<?php
/**
 * Paystack Tab Handler
 *
 * Registers Paystack in JFB's Tab_Handler_Manager.
 *
 * Required for two things:
 *   1. Satisfies Module::get_global_settings('paystack') calls — without this,
 *      a fatal Repository_Exception is thrown when JFB tries to merge global
 *      settings for our gateway.
 *   2. Provides global defaults (price_field, currency) that Module::with_global_settings()
 *      merges into retrieve_gateway_meta() so set_price_field() / set_price_from_filed()
 *      work automatically for every form.
 *
 * API keys and field configuration are all stored in our own option (jfb_paystack_keys)
 * managed by Settings → JFB Paystack. on_get_request() is a no-op because saving
 * goes through our own settings page, not JFB's settings AJAX system.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Jet_Form_Builder\Admin\Tabs_Handlers\Base_Handler;

class JFB_Paystack_Tab_Handler extends Base_Handler {

	/**
	 * Must match JFB_Paystack_Gateway::ID ('paystack') so that
	 * Module::with_global_settings() can find and merge these settings.
	 */
	public function slug(): string {
		return 'paystack';
	}

	/**
	 * Returns the current Paystack settings in JFB's expected format.
	 *
	 * Called by:
	 *   - Module::get_global_settings('paystack')       — credentials check
	 *   - Module::with_global_settings()                — merges price_field/currency
	 *   - Gateways_Editor_Data::gateways_global_valid() — editor credential validation
	 */
	public function on_load(): array {
		$keys = get_option( 'jfb_paystack_keys', [] );
		return [
			// API credentials (used by Gateways_Editor_Data for the "keys missing" warning).
			'public' => $keys['public'] ?? '',
			// Non-empty placeholder so the editor validity check passes.
			'secret' => ! empty( $keys['secret'] ) ? '***' : '',
			// Global defaults — merged into each form's gateway meta by with_global_settings().
			// Users set these once in Settings → JFB Paystack.
			'price_field' => $keys['price_field'] ?? '',
			'currency'    => ! empty( $keys['currency'] ) ? $keys['currency'] : 'NGN',
		];
	}

	/**
	 * No-op — Paystack settings are managed by our own settings page (Settings → JFB Paystack),
	 * not JFB's settings AJAX tab system.
	 */
	public function on_get_request(): void {}

}
