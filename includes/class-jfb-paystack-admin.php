<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin settings page — Settings → JFB Paystack.
 * Stores Paystack API keys (secret encrypted at rest with AES-256-CBC).
 */
class JFB_Paystack_Admin {

	public function __construct() {
		add_action( 'admin_menu', [ $this, 'register_menu' ] );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
	}

	public function register_menu() {
		add_options_page(
			__( 'JFB Paystack Gateway', 'jfb-paystack-gateway' ),
			__( 'JFB Paystack', 'jfb-paystack-gateway' ),
			'manage_options',
			'jfb-paystack-gateway',
			[ $this, 'render_settings_page' ]
		);
	}

	public function register_settings() {
		register_setting(
			'jfb_paystack_settings_group',
			'jfb_paystack_keys',
			[
				'type'              => 'array',
				'sanitize_callback' => [ $this, 'sanitize_keys' ],
				'default'           => [ 'public' => '', 'secret' => '' ],
			]
		);
	}

	public function sanitize_keys( $input ) {
		$public      = isset( $input['public'] )      ? sanitize_text_field( trim( $input['public'] ) )      : '';
		$raw_secret  = isset( $input['secret'] )      ? sanitize_text_field( trim( $input['secret'] ) )      : '';
		$price_field = isset( $input['price_field'] ) ? sanitize_key( trim( $input['price_field'] ) )        : '';
		$currency    = isset( $input['currency'] )    ? strtoupper( sanitize_text_field( trim( $input['currency'] ) ) ) : 'NGN';

		// Validate public key format.
		if ( ! empty( $public ) && ! preg_match( '/^pk_(test|live)_/', $public ) ) {
			add_settings_error(
				'jfb_paystack_keys',
				'invalid_public_key',
				__( 'Invalid Public Key — must start with pk_test_ or pk_live_.', 'jfb-paystack-gateway' )
			);
			$public = '';
		}

		// Validate secret key format.
		if ( ! empty( $raw_secret ) && ! preg_match( '/^sk_(test|live)_/', $raw_secret ) ) {
			add_settings_error(
				'jfb_paystack_keys',
				'invalid_secret_key',
				__( 'Invalid Secret Key — must start with sk_test_ or sk_live_.', 'jfb-paystack-gateway' )
			);
			$raw_secret = '';
		}

		if ( '' !== $raw_secret ) {
			$secret = jfb_paystack_encrypt_key( $raw_secret );
		} else {
			// Blank submission — preserve the existing encrypted value.
			$existing = get_option( 'jfb_paystack_keys', [] );
			$secret   = $existing['secret'] ?? '';
		}

		return [
			'public'      => $public,
			'secret'      => $secret,
			'price_field' => $price_field,
			'currency'    => ! empty( $currency ) ? $currency : 'NGN',
		];
	}

	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$keys         = get_option( 'jfb_paystack_keys', [] );
		$public       = $keys['public'] ?? '';
		$secret       = jfb_paystack_decrypt_key( $keys['secret'] ?? '' );
		$price_field  = $keys['price_field'] ?? '';
		$currency     = ! empty( $keys['currency'] ) ? $keys['currency'] : 'NGN';
		$is_test_mode = ! empty( $public ) && str_starts_with( $public, 'pk_test_' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'JFB Paystack Gateway', 'jfb-paystack-gateway' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Connect your Paystack account to JetFormBuilder. Paystack appears as a native gateway in the JFB form editor alongside PayPal.', 'jfb-paystack-gateway' ); ?>
			</p>

			<?php if ( ! empty( $public ) ) : ?>
			<div class="notice notice-<?php echo $is_test_mode ? 'warning' : 'success'; ?> inline" style="margin: 10px 0;">
				<p>
					<?php if ( $is_test_mode ) : ?>
						<strong><?php esc_html_e( 'Test Mode', 'jfb-paystack-gateway' ); ?></strong> —
						<?php esc_html_e( 'Test keys active. No real payments will be processed.', 'jfb-paystack-gateway' ); ?>
					<?php else : ?>
						<strong><?php esc_html_e( 'Live Mode', 'jfb-paystack-gateway' ); ?></strong> —
						<?php esc_html_e( 'Live keys active. Real payments will be processed.', 'jfb-paystack-gateway' ); ?>
					<?php endif; ?>
				</p>
			</div>
			<?php endif; ?>

			<form method="post" action="options.php">
				<?php settings_fields( 'jfb_paystack_settings_group' ); ?>

				<h2><?php esc_html_e( 'API Keys', 'jfb-paystack-gateway' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="jfb_paystack_public"><?php esc_html_e( 'Public Key', 'jfb-paystack-gateway' ); ?></label>
						</th>
						<td>
							<input
								type="text"
								id="jfb_paystack_public"
								name="jfb_paystack_keys[public]"
								value="<?php echo esc_attr( $public ); ?>"
								class="regular-text"
								placeholder="pk_test_..."
								autocomplete="off"
							/>
							<p class="description"><?php esc_html_e( 'Starts with pk_test_ or pk_live_.', 'jfb-paystack-gateway' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="jfb_paystack_secret"><?php esc_html_e( 'Secret Key', 'jfb-paystack-gateway' ); ?></label>
						</th>
						<td>
							<input
								type="password"
								id="jfb_paystack_secret"
								name="jfb_paystack_keys[secret]"
								value="<?php echo esc_attr( $secret ); ?>"
								class="regular-text"
								placeholder="sk_test_..."
								autocomplete="off"
							/>
							<p class="description"><?php esc_html_e( 'Stored encrypted. Never exposed publicly.', 'jfb-paystack-gateway' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Payment Settings', 'jfb-paystack-gateway' ); ?></h2>
				<p class="description" style="margin-bottom:10px;">
					<?php esc_html_e( 'These settings apply to all forms using the Paystack gateway.', 'jfb-paystack-gateway' ); ?>
				</p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="jfb_paystack_price_field"><?php esc_html_e( 'Amount Field Name', 'jfb-paystack-gateway' ); ?></label>
						</th>
						<td>
							<input
								type="text"
								id="jfb_paystack_price_field"
								name="jfb_paystack_keys[price_field]"
								value="<?php echo esc_attr( $price_field ); ?>"
								class="regular-text"
								placeholder="amount"
							/>
							<p class="description">
								<?php esc_html_e( 'The exact name of the form field that holds the payment amount (e.g. "amount", "price", "total"). Must match the field name in your JetFormBuilder form.', 'jfb-paystack-gateway' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="jfb_paystack_currency"><?php esc_html_e( 'Currency', 'jfb-paystack-gateway' ); ?></label>
						</th>
						<td>
							<input
								type="text"
								id="jfb_paystack_currency"
								name="jfb_paystack_keys[currency]"
								value="<?php echo esc_attr( $currency ); ?>"
								class="small-text"
								placeholder="NGN"
								maxlength="3"
							/>
							<p class="description">
								<?php esc_html_e( 'ISO 4217 currency code, e.g. NGN, GHS, ZAR, USD, KES.', 'jfb-paystack-gateway' ); ?>
							</p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Setup Guide', 'jfb-paystack-gateway' ); ?></h2>
				<p class="description" style="margin-bottom: 10px;">
					<?php esc_html_e( 'Follow these steps once to connect Paystack to every JetFormBuilder payment form on your site.', 'jfb-paystack-gateway' ); ?>
				</p>

				<h3 style="margin-top: 16px;"><?php esc_html_e( 'Step 1 — Save API Keys &amp; Amount Field', 'jfb-paystack-gateway' ); ?></h3>
				<ol style="max-width: 700px; margin-left: 2em;">
					<li><?php esc_html_e( 'Enter your Paystack Public Key and Secret Key above (get them from your Paystack Dashboard → Settings → API Keys &amp; Webhooks).', 'jfb-paystack-gateway' ); ?></li>
					<li><?php esc_html_e( 'Enter the Amount Field Name — this must exactly match the JetFormBuilder field name in your form that holds the payment amount (e.g. "amount", "price", "total").', 'jfb-paystack-gateway' ); ?></li>
					<li><?php esc_html_e( 'Set the Currency code (e.g. NGN, GHS, USD, ZAR, KES). Defaults to NGN.', 'jfb-paystack-gateway' ); ?></li>
					<li><?php esc_html_e( 'Click "Save Settings".', 'jfb-paystack-gateway' ); ?></li>
				</ol>

				<h3 style="margin-top: 16px;"><?php esc_html_e( 'Step 2 — Enable Gateways in JetFormBuilder', 'jfb-paystack-gateway' ); ?></h3>
				<ol style="max-width: 700px; margin-left: 2em;">
					<li><?php esc_html_e( 'Go to JetFormBuilder → Settings → Payment Gateways.', 'jfb-paystack-gateway' ); ?></li>
					<li><?php esc_html_e( 'Toggle "Enable Gateways" on.', 'jfb-paystack-gateway' ); ?></li>
					<li><?php esc_html_e( 'Save.', 'jfb-paystack-gateway' ); ?></li>
				</ol>

				<h3 style="margin-top: 16px;"><?php esc_html_e( 'Step 3 — Configure Your Form', 'jfb-paystack-gateway' ); ?></h3>
				<ol style="max-width: 700px; margin-left: 2em;">
					<li><?php esc_html_e( 'Edit your JetFormBuilder form.', 'jfb-paystack-gateway' ); ?></li>
					<li><?php esc_html_e( 'Open the Payment Gateways panel on the right → select Paystack.', 'jfb-paystack-gateway' ); ?></li>
					<li><?php esc_html_e( 'Make sure your form has an amount field whose name matches the "Amount Field Name" you configured above.', 'jfb-paystack-gateway' ); ?></li>
					<li><?php esc_html_e( 'Make sure your form has an email field — the plugin auto-detects any field containing a valid email address to use as the Paystack customer email.', 'jfb-paystack-gateway' ); ?></li>
				</ol>

				<h3 style="margin-top: 16px;"><?php esc_html_e( 'Step 4 — Add Hidden Fields for Transaction Data (Recommended)', 'jfb-paystack-gateway' ); ?></h3>
				<p style="max-width: 700px;">
					<?php esc_html_e( 'To capture Paystack transaction data (reference, status, channel, etc.) into your database records or emails, add Hidden Fields to your form with the following exact field names. After a successful payment, the plugin automatically populates these fields with real values before your downstream actions run.', 'jfb-paystack-gateway' ); ?>
				</p>
				<table class="widefat striped" style="max-width: 760px; margin: 10px 0 20px;">
					<thead>
						<tr>
							<th style="width:200px;"><?php esc_html_e( 'Hidden Field Name', 'jfb-paystack-gateway' ); ?></th>
							<th><?php esc_html_e( 'What it contains', 'jfb-paystack-gateway' ); ?></th>
							<th style="width:80px;"><?php esc_html_e( 'Always set?', 'jfb-paystack-gateway' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<tr>
							<td><code>paystack_reference</code></td>
							<td><?php esc_html_e( 'Unique transaction reference string (e.g. jfb_paystack_abc123_1234567890)', 'jfb-paystack-gateway' ); ?></td>
							<td><?php esc_html_e( 'Yes', 'jfb-paystack-gateway' ); ?></td>
						</tr>
						<tr>
							<td><code>paystack_status</code></td>
							<td><?php esc_html_e( 'Transaction status returned by Paystack — "success" on successful payment', 'jfb-paystack-gateway' ); ?></td>
							<td><?php esc_html_e( 'Yes', 'jfb-paystack-gateway' ); ?></td>
						</tr>
						<tr>
							<td><code>paystack_amount</code></td>
							<td><?php esc_html_e( 'Amount charged in major currency units (e.g. 10000.00 for ₦10,000)', 'jfb-paystack-gateway' ); ?></td>
							<td><?php esc_html_e( 'Yes', 'jfb-paystack-gateway' ); ?></td>
						</tr>
						<tr>
							<td><code>paystack_currency</code></td>
							<td><?php esc_html_e( 'ISO currency code of the transaction (e.g. NGN, GHS, USD)', 'jfb-paystack-gateway' ); ?></td>
							<td><?php esc_html_e( 'Yes', 'jfb-paystack-gateway' ); ?></td>
						</tr>
						<tr>
							<td><code>paystack_channel</code></td>
							<td><?php esc_html_e( 'Payment channel used — "card", "bank", "ussd", "mobile_money", "qr", etc.', 'jfb-paystack-gateway' ); ?></td>
							<td><?php esc_html_e( 'Yes', 'jfb-paystack-gateway' ); ?></td>
						</tr>
						<tr>
							<td><code>paystack_paid_at</code></td>
							<td><?php esc_html_e( 'ISO 8601 timestamp of when the payment was completed (e.g. 2024-04-09T12:34:56.000Z)', 'jfb-paystack-gateway' ); ?></td>
							<td><?php esc_html_e( 'Yes', 'jfb-paystack-gateway' ); ?></td>
						</tr>
						<tr>
							<td><code>paystack_customer_email</code></td>
							<td><?php esc_html_e( 'Customer email address as recorded by Paystack', 'jfb-paystack-gateway' ); ?></td>
							<td><?php esc_html_e( 'Yes', 'jfb-paystack-gateway' ); ?></td>
						</tr>
						<tr>
							<td><code>paystack_auth_code</code></td>
							<td><?php esc_html_e( 'Paystack authorization code — can be used to charge the card again in future (tokenisation)', 'jfb-paystack-gateway' ); ?></td>
							<td><?php esc_html_e( 'Card only', 'jfb-paystack-gateway' ); ?></td>
						</tr>
						<tr>
							<td><code>paystack_card_type</code></td>
							<td><?php esc_html_e( 'Card scheme — "visa", "mastercard", "verve", etc.', 'jfb-paystack-gateway' ); ?></td>
							<td><?php esc_html_e( 'Card only', 'jfb-paystack-gateway' ); ?></td>
						</tr>
						<tr>
							<td><code>paystack_bank</code></td>
							<td><?php esc_html_e( 'Name of the issuing bank (e.g. "Guaranty Trust Bank")', 'jfb-paystack-gateway' ); ?></td>
							<td><?php esc_html_e( 'Card only', 'jfb-paystack-gateway' ); ?></td>
						</tr>
						<tr>
							<td><code>paystack_last4</code></td>
							<td><?php esc_html_e( 'Last 4 digits of the card used (e.g. 4081)', 'jfb-paystack-gateway' ); ?></td>
							<td><?php esc_html_e( 'Card only', 'jfb-paystack-gateway' ); ?></td>
						</tr>
					</tbody>
				</table>
				<p class="description" style="max-width: 700px; margin-bottom: 20px;">
					<strong><?php esc_html_e( 'How to use them:', 'jfb-paystack-gateway' ); ?></strong>
					<?php esc_html_e( 'In your JetFormBuilder form editor, add a Hidden Field block for each value you want to capture. Set the field name to exactly one of the names above (e.g. "paystack_reference"). Then in your Insert/Update CCT action → Fields Map, select that hidden field from the dropdown and map it to the matching column in your database. The plugin fills it in automatically after every successful payment — you never need to type anything into those fields.', 'jfb-paystack-gateway' ); ?>
				</p>

				<h3 style="margin-top: 16px;"><?php esc_html_e( 'Step 5 — Add Post-Payment Actions', 'jfb-paystack-gateway' ); ?></h3>
				<ol style="max-width: 700px; margin-left: 2em;">
					<li><?php esc_html_e( 'In the form editor, go to the Actions panel.', 'jfb-paystack-gateway' ); ?></li>
					<li><?php esc_html_e( 'Add your actions: "Insert/Update CCT Item", "Send Email", "Redirect to Page", "Show Popup", etc.', 'jfb-paystack-gateway' ); ?></li>
					<li><?php esc_html_e( 'Set the condition on each action to GATEWAY.SUCCESS. These actions will fire automatically after a successful Paystack payment.', 'jfb-paystack-gateway' ); ?></li>
					<li><?php esc_html_e( 'Optionally add actions with condition GATEWAY.FAILED to handle failed payments.', 'jfb-paystack-gateway' ); ?></li>
				</ol>

				<h3 style="margin-top: 16px;"><?php esc_html_e( 'Step 6 — Test the Flow', 'jfb-paystack-gateway' ); ?></h3>
				<ol style="max-width: 700px; margin-left: 2em;">
					<li><?php esc_html_e( 'Use your Paystack test keys (pk_test_... / sk_test_...) and a Paystack test card (4084084084084081, any future expiry, CVV 408).', 'jfb-paystack-gateway' ); ?></li>
					<li><?php esc_html_e( 'Submit your form — you will be redirected to the Paystack hosted checkout page.', 'jfb-paystack-gateway' ); ?></li>
					<li><?php esc_html_e( 'Complete the payment — Paystack redirects back to your page automatically.', 'jfb-paystack-gateway' ); ?></li>
					<li><?php esc_html_e( 'Verify that your CCT record was inserted, email was sent, and any redirect worked as expected.', 'jfb-paystack-gateway' ); ?></li>
					<li><?php esc_html_e( 'When ready for production, replace test keys with live keys above and save.', 'jfb-paystack-gateway' ); ?></li>
				</ol>

				<hr style="margin: 24px 0;" />

				<?php submit_button( __( 'Save Settings', 'jfb-paystack-gateway' ) ); ?>
			</form>
		</div>
		<?php
	}
}
