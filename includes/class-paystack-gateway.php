<?php
/**
 * Paystack Gateway Controller
 *
 * Registers Paystack as a native JetFormBuilder payment gateway, exactly as
 * PayPal and Stripe are registered. Extends Base_Gateway directly (not
 * Base_Scenario_Gateway) because we implement our own single-scenario flow
 * without needing JFB's Scenarios_Manager infrastructure.
 *
 * Flow overview:
 *   1. before_actions()  — fires before DEFAULT.PROCESS actions; sets form meta.
 *   2. after_actions()   — fires after DEFAULT.PROCESS actions; calls Paystack
 *                          /transaction/initialize, stores context, redirects user.
 *   3. try_run_on_catch()— fires on page load when ?jet_form_gateway=paystack&trxref=xxx
 *                          is in the URL (user returning from Paystack). Verifies the
 *                          transaction server-side, restores form context, fires
 *                          GATEWAY.SUCCESS or GATEWAY.FAILED events.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use JFB_Modules\Gateways\Base_Gateway;
use JFB_Modules\Gateways\Module as Gateways_Module;
use Jet_Form_Builder\Actions\Action_Handler;
use Jet_Form_Builder\Admin\Tabs_Handlers\Tab_Handler_Manager;
use Jet_Form_Builder\Exceptions\Gateway_Exception;
use Jet_Form_Builder\Db_Queries\Exceptions\Skip_Exception;

class JFB_Paystack_Gateway extends Base_Gateway {

	const ID               = 'paystack';
	const TRANSIENT_PREFIX = 'jfb_paystack_';

	/**
	 * Paystack transaction fields for the current request.
	 * Set during _finalize() and read by the resolved-request filter so that
	 * actions (CCT insert, Send Email, etc.) can access the values whether they
	 * use resolve_request(), get_request(), or macro strings.
	 *
	 * @var array<string,mixed>
	 */
	private static array $paystack_data = [];

	public function __construct() {
		// Register the Tab Handler so JFB's Tab_Handler_Manager knows about
		// Paystack. This is required for the gateway to appear in both the
		// JFB form editor gateway selector and Module::get_global_settings().
		Tab_Handler_Manager::instance()->install( new JFB_Paystack_Tab_Handler() );
	}

	/**
	 * Called by Gateways_Editor_Data::gateways_global_valid() to determine
	 * whether to show a "credentials missing" warning in the form editor.
	 * Must be a static method named get_credentials().
	 */
	public static function get_credentials(): array {
		$keys = get_option( 'jfb_paystack_keys', [] );
		return [
			'public' => $keys['public'] ?? '',
			// Return a placeholder so the check doesn't expose the encrypted value.
			// The actual secret is decrypted only when needed during payment.
			'secret' => ! empty( $keys['secret'] ) ? '***' : '',
		];
	}

	/**
	 * Tells Gateways_Editor_Data which credential fields must be non-empty
	 * for the gateway to be considered valid in the form editor.
	 */
	public function required_credentials_fields(): array {
		return [ 'public', 'secret' ];
	}

	/**
	 * The URL query param Paystack appends to the callback URL.
	 * We also use this as our token_query_name so Base_Gateway::set_payment_token()
	 * reads it automatically.
	 *
	 * @var string
	 */
	protected $token_query_name = 'trxref';

	// ─── Identity ────────────────────────────────────────────────────────────

	public function get_id(): string {
		return self::ID;
	}

	public function get_name(): string {
		return __( 'Paystack', 'jfb-paystack-gateway' );
	}

	// ─── Gateway meta ────────────────────────────────────────────────────────

	/**
	 * Required by Base_Gateway. Returns the gateway config for this form from
	 * JFB's stored gateways meta.
	 */
	protected function retrieve_gateway_meta() {
		return Gateways_Module::instance()->with_global_settings(
			jet_form_builder()->post_type->get_gateways( jet_fb_handler()->form_id ),
			self::ID
		);
	}

	/**
	 * Options exposed in the JFB form editor's gateway panel.
	 * 'price_field' is the standard JFB convention for selecting which form
	 * field holds the payment amount — Base_Gateway reads this automatically.
	 */
	protected function options_list(): array {
		return [
			'price_field' => [
				'label'    => __( 'Price/amount field', 'jfb-paystack-gateway' ),
				'required' => true,
			],
			'currency'    => [
				'label'    => __( 'Currency', 'jfb-paystack-gateway' ),
				'required' => false,
				'default'  => 'NGN',
			],
		];
	}

	// ─── Phase 1: Before DEFAULT.PROCESS actions ─────────────────────────────

	/**
	 * Called before the DEFAULT.PROCESS action chain runs.
	 * Sets form meta so JFB knows which gateway is active.
	 */
	public function before_actions() {
		$this->set_form_meta( Gateways_Module::instance()->gateways() );
	}

	// ─── Phase 2: After DEFAULT.PROCESS actions ───────────────────────────────

	/**
	 * Called after DEFAULT.PROCESS actions (e.g. Save Record) have run.
	 * Initializes the Paystack transaction and redirects the user to the
	 * Paystack hosted checkout page.
	 *
	 * @param Action_Handler $handler
	 * @throws Gateway_Exception
	 */
	public function after_actions( Action_Handler $handler ) {

		// 1. Resolve API keys.
		$keys   = get_option( 'jfb_paystack_keys', [] );
		$secret = jfb_paystack_decrypt_key( $keys['secret'] ?? '' );
		$public = $keys['public'] ?? '';

		if ( empty( $secret ) || empty( $public ) ) {
			throw new Gateway_Exception( 'Paystack API keys are not configured.' );
		}

		if ( substr( $secret, 0, 4 ) === 'enc:' ) {
			throw new Gateway_Exception( 'Paystack secret key decryption failed. Re-save your API keys.' );
		}

		// 2. Resolve amount directly from saved settings + form request data.
		//    We read the option directly here (bypassing Base_Gateway's set_price_field()
		//    machinery, which relies on per-form gateway meta that may not be configured)
		//    so the amount field name from Settings → JFB Paystack is always honoured.
		$price_field_name = trim( $keys['price_field'] ?? '' );

		if ( empty( $price_field_name ) ) {
			throw new Gateway_Exception(
				'Paystack: Amount Field Name is not configured. ' .
				'Go to Settings → JFB Paystack and fill in the "Amount Field Name" field.'
			);
		}

		// request_data is a Legacy_Request_Data object (iterable), not a plain array.
		// Flatten it to a plain array once so we can use array functions on it.
		$request = [];
		foreach ( jet_fb_action_handler()->request_data as $k => $v ) {
			$request[ $k ] = $v;
		}

		// Log submitted field names so mismatches are easy to spot in debug.log.
		error_log( '[JFB Paystack] Submitted fields: ' . implode( ', ', array_keys( $request ) ) );
		error_log( '[JFB Paystack] Looking for price field: ' . $price_field_name );

		$amount_major = isset( $request[ $price_field_name ] )
			? (float) $request[ $price_field_name ]
			: 0.0;
		$amount_kobo  = (int) round( $amount_major * 100 );

		if ( $amount_kobo <= 0 ) {
			throw new Gateway_Exception(
				sprintf(
					'Paystack: the "%s" field value is empty or zero. ' .
					'Make sure the field name matches exactly.',
					$price_field_name
				)
			);
		}

		// 3. Auto-detect customer email by scanning all submitted form fields
		//    for the first value that is a valid email address.
		//    Falls back to the logged-in user's email if none found in the form.
		$email = $this->detect_email_from_request( $request );

		if ( empty( $email ) ) {
			$email = sanitize_email( wp_get_current_user()->user_email ?? '' );
		}

		if ( empty( $email ) ) {
			throw new Gateway_Exception(
				'Paystack: no valid email address found in the form submission. ' .
				'Make sure your form includes an email field.'
			);
		}

		// 4. Currency — from saved settings, default NGN.
		$currency = strtoupper( ! empty( $keys['currency'] ) ? $keys['currency'] : 'NGN' );

		// 5. Generate a unique reference tied to this form submission.
		$reference = self::TRANSIENT_PREFIX . wp_generate_password( 12, false, false ) . '_' . time();

		// 6. Build the callback URL — same page the form is on, with our
		//    gateway params appended. Paystack redirects back here after payment.
		$callback_url = add_query_arg(
			[
				Gateways_Module::PAYMENT_TYPE_PARAM => self::ID,
				'trxref'                            => $reference,
			],
			jet_fb_handler()->refer
		);

		// 7. Call Paystack /transaction/initialize.
		$res = wp_remote_post( 'https://api.paystack.co/transaction/initialize', [
			'headers'   => [
				'Authorization' => 'Bearer ' . $secret,
				'Content-Type'  => 'application/json',
			],
			'body'      => wp_json_encode( [
				'email'        => $email,
				'amount'       => $amount_kobo,
				'reference'    => $reference,
				'currency'     => $currency,
				'callback_url' => $callback_url,
				'metadata'     => [
					'form_id'   => $handler->form_id,
					'reference' => $reference,
				],
			] ),
			'timeout'   => 15,
			'sslverify' => true,
		] );

		if ( is_wp_error( $res ) ) {
			error_log( '[JFB Paystack] Could not reach Paystack API: ' . $res->get_error_message() );
			throw new Gateway_Exception( 'Could not connect to Paystack. Please try again.' );
		}

		$body = json_decode( wp_remote_retrieve_body( $res ), true );

		if ( empty( $body['status'] ) || empty( $body['data']['authorization_url'] ) ) {
			$msg = $body['message'] ?? 'Unknown error';
			error_log( '[JFB Paystack] Initialization failed: ' . $msg );
			throw new Gateway_Exception( 'Paystack initialization failed: ' . esc_html( $msg ) );
		}

		// 8. Store the form context in a transient keyed by reference.
		//    This is restored in try_run_on_catch() when the user returns.
		set_transient( self::TRANSIENT_PREFIX . $reference, [
			'form_id'     => $handler->form_id,
			'request'     => $request,  // plain array — already flattened above
			'amount_kobo' => $amount_kobo,
			'currency'    => $currency,
			'email'       => $email,
			'public_key'  => $public,
		], 12 * HOUR_IN_SECONDS );

		// 9. Tell JFB to redirect the user to Paystack's hosted checkout page.
		//    JFB reads response_data['redirect'] in send_response() and does
		//    wp_redirect() — same mechanism used by the PayPal gateway.
		jet_fb_action_handler()->add_response( [
			'redirect' => $body['data']['authorization_url'],
		] );
	}

	// ─── Phase 3: Return from Paystack ───────────────────────────────────────

	/**
	 * Called on every page load where ?jet_form_gateway=paystack is present.
	 * This is the user returning from Paystack's hosted checkout page.
	 *
	 * Mirrors Base_Scenario_Gateway::try_run_on_catch() but without the
	 * Scenarios_Manager dependency, so it works on free and pro versions alike.
	 */
	public function try_run_on_catch() {

		// 1. Read and validate the reference from the URL.
		try {
			$this->set_payment_token(); // reads $_GET['trxref'] → $this->payment_token
		} catch ( Skip_Exception $e ) {
			return;
		}

		$reference = sanitize_text_field( $this->payment_token );

		if ( empty( $reference ) ) {
			return;
		}

		// 2. Load stored context — proof that this transaction was initiated here.
		$ctx = get_transient( self::TRANSIENT_PREFIX . $reference );

		if ( ! is_array( $ctx ) ) {
			error_log( '[JFB Paystack] Unknown or expired reference on return: ' . $reference );
			return;
		}

		$form_id     = absint( $ctx['form_id'] ?? 0 );
		$amount_kobo = (int) ( $ctx['amount_kobo'] ?? 0 );

		if ( ! $form_id ) {
			error_log( '[JFB Paystack] No form_id in transient for reference: ' . $reference );
			return;
		}

		// 3. Deduplication — if already processed (e.g. server webhook fired first).
		if ( get_transient( self::TRANSIENT_PREFIX . 'processed_' . $reference ) ) {
			error_log( '[JFB Paystack] Already processed on return: ' . $reference );
			$this->_send_already_processed( $form_id );
			return;
		}

		// 4. Verify transaction server-side with Paystack API.
		$keys   = get_option( 'jfb_paystack_keys', [] );
		$secret = jfb_paystack_decrypt_key( $keys['secret'] ?? '' );

		$verify = wp_remote_get(
			'https://api.paystack.co/transaction/verify/' . rawurlencode( $reference ),
			[
				'headers'   => [ 'Authorization' => 'Bearer ' . $secret ],
				'timeout'   => 15,
				'sslverify' => true,
			]
		);

		if ( is_wp_error( $verify ) ) {
			error_log( '[JFB Paystack] Verify API error: ' . $verify->get_error_message() );
			$this->_finalize( $form_id, $ctx, [], 'failed', $reference );
			return;
		}

		$vbody  = json_decode( wp_remote_retrieve_body( $verify ), true );
		$status = 'failed';

		if (
			! empty( $vbody['status'] ) &&
			! empty( $vbody['data']['status'] ) &&
			'success' === $vbody['data']['status']
		) {
			// 5. Amount integrity check.
			$verified_kobo = (int) ( $vbody['data']['amount'] ?? 0 );
			if ( $amount_kobo > 0 && $verified_kobo !== $amount_kobo ) {
				error_log( sprintf(
					'[JFB Paystack] Amount mismatch for %s — expected %d kobo, got %d kobo.',
					$reference, $amount_kobo, $verified_kobo
				) );
				$this->_finalize( $form_id, $ctx, [], 'failed', $reference );
				return;
			}

			$status = 'success';
		}

		// 6. Mark processed before executing to block duplicate server-webhook.
		set_transient(
			self::TRANSIENT_PREFIX . 'processed_' . $reference,
			'browser',
			24 * HOUR_IN_SECONDS
		);

		$transaction = ( 'success' === $status ) ? ( $vbody['data'] ?? [] ) : [];

		$this->_finalize( $form_id, $ctx, $transaction, $status, $reference );
	}

	// ─── Internal helpers ─────────────────────────────────────────────────────

	/**
	 * Restore form context, inject Paystack fields as macros, fire the JFB
	 * gateway event, and send the response (redirect or message).
	 *
	 * @param int    $form_id
	 * @param array  $ctx         Stored transient context.
	 * @param array  $transaction Paystack transaction data (success only).
	 * @param string $status      'success' | 'failed'
	 * @param string $reference   Transaction reference.
	 */
	private function _finalize(
		int    $form_id,
		array  $ctx,
		array  $transaction,
		string $status,
		string $reference
	): void {

		// Restore form submission context into JFB's action handler.
		jet_fb_action_handler()->set_form_id( $form_id );

		$saved_request = $ctx['request'] ?? [];

		// Build Paystack transaction fields for macro resolution.
		$auth            = $transaction['authorization'] ?? [];
		$paystack_fields = [
			'paystack_reference'      => sanitize_text_field( $transaction['reference']          ?? $reference ),
			'paystack_status'         => sanitize_text_field( $transaction['status']             ?? $status ),
			'paystack_amount'         => isset( $transaction['amount'] ) ? floatval( $transaction['amount'] / 100 ) : floatval( $ctx['amount_kobo'] / 100 ),
			'paystack_currency'       => sanitize_text_field( $transaction['currency']           ?? ( $ctx['currency'] ?? 'NGN' ) ),
			'paystack_channel'        => sanitize_text_field( $transaction['channel']            ?? '' ),
			'paystack_paid_at'        => sanitize_text_field( $transaction['paid_at']            ?? '' ),
			'paystack_auth_code'      => sanitize_text_field( $auth['authorization_code']       ?? '' ),
			'paystack_card_type'      => sanitize_text_field( $auth['card_type']                ?? '' ),
			'paystack_bank'           => sanitize_text_field( $auth['bank']                     ?? '' ),
			'paystack_last4'          => sanitize_text_field( $auth['last4']                    ?? '' ),
			'paystack_customer_email' => sanitize_email( $transaction['customer']['email']      ?? ( $ctx['email'] ?? '' ) ),
		];

		// Merge everything into one flat array.
		$full_request = array_merge( $saved_request, $paystack_fields );

		// ── Store in static cache ────────────────────────────────────────────────
		// Allows filters registered below to access data without closure complexity.
		self::$paystack_data = $paystack_fields;

		// Populate $this->data so process_status() → add_request($this->data['form_data'])
		// injects the full set including paystack fields.
		if ( property_exists( $this, 'data' ) ) {
			$this->data = array_merge( (array) $this->data, [
				'form_id'   => $form_id,
				'form_data' => $full_request,
			] );
		}

		// Set gateways_meta so send_response() / get_meta_message() work.
		$this->set_form_gateways_meta();

		// ── Multi-layer data injection strategy ──────────────────────────────────
		//
		// On the return page load there is no live form parse, so paystack_* fields
		// have no registered parsers in jet_fb_context(). We inject the data at
		// every plausible hook point so the values are available regardless of
		// which access path each downstream action (CCT insert, email, redirect) uses:
		//
		//  Layer 1 — raw_request via set_request():
		//    jet_fb_context()->get_request('paystack_reference') checks raw_request
		//    FIRST (before parsers), so this satisfies any action that calls get_request().
		//
		//  Layer 2 — resolved request filter:
		//    JFB passes resolve_request() to each action's do_action(). If a filter
		//    'jet-form-builder/request/context-fields' (or similar) exists, we merge
		//    our fields in so they appear in the $request array actions receive directly.
		//    This makes them selectable in the FIELDS MAP dropdown at runtime AND
		//    available as $request keys for any action that reads from $request directly.
		//
		//  Layer 3 — just-in-time re-injection via on-payment-{type}:
		//    Gateway_Base_Executor fires this hook right before execute_actions(),
		//    after any internal resets. We re-run layers 1 & 2 here as a safety net.

		// Layer 2 filter — inject into every resolved-request call while our actions run.
		$merge_filter = static function ( $resolved ) {
			return array_merge( (array) $resolved, self::$paystack_data );
		};

		// Register on all known/plausible filter names for the resolved request.
		// Unused filter names are harmless no-ops.
		$request_filters = [
			'jet-form-builder/request/context-fields',
			'jet-form-builder/request/resolved',
			'jet-form-builder/request/data',
			'jet-form-builder/request',
		];
		foreach ( $request_filters as $filter ) {
			add_filter( $filter, $merge_filter, PHP_INT_MAX );
		}

		// Layer 3 — on-payment hook: re-run layers 1 & 2 just before execute_actions().
		$inject = static function() use ( $full_request, $merge_filter, $request_filters ) {
			// Layer 1: raw_request.
			if ( function_exists( 'jet_fb_context' ) ) {
				jet_fb_context()->set_request( $full_request );
				// Also attempt add_request so parsers are created if resolve_path supports it.
				if ( function_exists( 'jet_fb_action_handler' ) ) {
					jet_fb_action_handler()->add_request( self::$paystack_data );
				}
			}
			// Layer 2: ensure filters are still registered (no-op if already added).
			foreach ( $request_filters as $filter ) {
				if ( ! has_filter( $filter, $merge_filter ) ) {
					add_filter( $filter, $merge_filter, PHP_INT_MAX );
				}
			}
		};
		add_action( 'jet-form-builder/gateways/on-payment-success', $inject );
		add_action( 'jet-form-builder/gateways/on-payment-failed',  $inject );

		// Fire GATEWAY.SUCCESS or GATEWAY.FAILED — triggers downstream actions.
		$action_error = false;
		try {
			$this->process_status( $status );
		} catch ( \Jet_Form_Builder\Exceptions\Action_Exception $e ) {
			$action_error = $e->getMessage();
		}

		// Clean up all filters and hooks.
		foreach ( $request_filters as $filter ) {
			remove_filter( $filter, $merge_filter, PHP_INT_MAX );
		}
		remove_action( 'jet-form-builder/gateways/on-payment-success', $inject );
		remove_action( 'jet-form-builder/gateways/on-payment-failed',  $inject );
		self::$paystack_data = [];

		// NOTE: do NOT fire 'jet-form-builder/gateways/before-send' here.
		// JFB's Form_Record\Module hooks that action and type-hints Scenario_Logic_Base
		// as the third argument — passing $this (a Base_Gateway) causes a fatal TypeError.

		// send_response() checks response_data['redirect'] set by a
		// Redirect-to-Page action and does wp_redirect(); otherwise it reloads.
		$this->send_response( [
			'status' => $action_error
				? $action_error
				: $this->get_result_message( $status ),
		] );
	}

	/**
	 * When a transaction was already processed (dedup), just reload the refer
	 * page cleanly — no double-firing of actions.
	 */
	private function _send_already_processed( int $form_id ): void {
		jet_fb_action_handler()->set_form_id( $form_id );
		$this->set_form_gateways_meta();
		$this->send_response( [ 'status' => '' ] );
	}

	/**
	 * Override get_result_message to use JFB's built-in message manager.
	 * Returns the form's configured success/failed message from post meta.
	 */
	public function get_result_message( $status ): string {
		$type = in_array( $status, [ 'success' ], true ) ? 'success' : 'failed';

		return \Jet_Form_Builder\Form_Messages\Manager::dynamic_success(
			$this->get_meta_message( $type )
		);
	}

	/**
	 * Scan all submitted form values and return the first one that is a valid
	 * email address. Prioritises fields whose name contains 'email', then falls
	 * back to any field with a valid email value.
	 *
	 * @param array $request The full form submission data.
	 * @return string Sanitized email, or empty string if none found.
	 */
	private function detect_email_from_request( array $request ): string {
		// Pass 1 — prefer fields whose name suggests they hold an email.
		foreach ( $request as $key => $value ) {
			if ( ! is_string( $value ) ) {
				continue;
			}
			if ( false !== strpos( strtolower( $key ), 'email' ) && is_email( $value ) ) {
				return sanitize_email( $value );
			}
		}

		// Pass 2 — accept any field with a syntactically valid email value.
		foreach ( $request as $value ) {
			if ( is_string( $value ) && is_email( $value ) ) {
				return sanitize_email( $value );
			}
		}

		return '';
	}

	/**
	 * No-op: we don't use the legacy set_payment() flow.
	 */
	protected function set_payment() {}

}
