<?php
/**
 * Plugin Name:       JFB Paystack Gateway
 * Plugin URI:        https://github.com/johnero27/jfb-paystack-gateway
 * Description:       Adds Paystack as a native JetFormBuilder payment gateway with server-side verification, full macro support, and post-payment action support.
 * Version:           2.1.7
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Tobi John
 * Author URI:        https://tobijohn.com
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       jfb-paystack-gateway
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ── Plugin constants ──────────────────────────────────────────────────────────
define( 'JFB_PAYSTACK_VERSION', '2.0.0' );
define( 'JFB_PAYSTACK_FILE',    __FILE__ );
define( 'JFB_PAYSTACK_DIR',     plugin_dir_path( __FILE__ ) );
define( 'JFB_PAYSTACK_URL',     plugin_dir_url( __FILE__ ) );

// ── API key encryption helpers ────────────────────────────────────────────────

/**
 * Encrypt a Paystack API key for storage using AES-256-CBC.
 * Uses AUTH_KEY from wp-config.php. Encrypted values are prefixed with 'enc:'.
 * Falls back to plain-text if openssl is unavailable.
 */
function jfb_paystack_encrypt_key( string $value ): string {
	if ( empty( $value ) || ! function_exists( 'openssl_encrypt' ) || ! defined( 'AUTH_KEY' ) ) {
		return $value;
	}
	$key    = hash( 'sha256', AUTH_KEY, true );
	$iv     = openssl_random_pseudo_bytes( 16 );
	$cipher = openssl_encrypt( $value, 'AES-256-CBC', $key, 0, $iv );
	return 'enc:' . base64_encode( $iv . $cipher );
}

/**
 * Decrypt a Paystack API key from storage.
 * Values not prefixed with 'enc:' are legacy plain-text and returned as-is.
 */
function jfb_paystack_decrypt_key( string $value ): string {
	if ( empty( $value ) || substr( $value, 0, 4 ) !== 'enc:' ) {
		return $value;
	}
	if ( ! function_exists( 'openssl_decrypt' ) || ! defined( 'AUTH_KEY' ) ) {
		return $value;
	}
	$decoded = base64_decode( substr( $value, 4 ), true );
	if ( false === $decoded || strlen( $decoded ) < 17 ) {
		return '';
	}
	$key    = hash( 'sha256', AUTH_KEY, true );
	$iv     = substr( $decoded, 0, 16 );
	$cipher = substr( $decoded, 16 );
	$plain  = openssl_decrypt( $cipher, 'AES-256-CBC', $key, 0, $iv );
	return false !== $plain ? $plain : '';
}

// ── Activation check ──────────────────────────────────────────────────────────
register_activation_hook( __FILE__, 'jfb_paystack_check_dependencies' );

function jfb_paystack_check_dependencies() {
	// class_exists() is unreliable here — JetFormBuilder registers its main class
	// inside plugins_loaded, so it's not in memory when our activation hook fires.
	// Read the active plugins list from the database directly instead.
	if ( ! function_exists( 'is_plugin_active' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	$active = array_merge(
		(array) get_option( 'active_plugins', [] ),
		is_multisite() ? array_keys( (array) get_site_option( 'active_sitewide_plugins', [] ) ) : []
	);

	foreach ( $active as $plugin_file ) {
		if ( false !== strpos( $plugin_file, 'jet-form-builder' ) ) {
			return; // Found — allow activation.
		}
	}

	deactivate_plugins( plugin_basename( __FILE__ ) );
	wp_die(
		esc_html__( 'JFB Paystack Gateway requires JetFormBuilder to be installed and activated.', 'jfb-paystack-gateway' ),
		esc_html__( 'Plugin Activation Error', 'jfb-paystack-gateway' ),
		[ 'back_link' => true ]
	);
}

// ── Soft dependency notice ────────────────────────────────────────────────────
add_action( 'admin_notices', 'jfb_paystack_dependency_notice' );
function jfb_paystack_dependency_notice() {
	if ( function_exists( 'jet_form_builder' ) ) {
		return;
	}
	echo '<div class="notice notice-error"><p>';
	echo '<strong>JFB Paystack Gateway:</strong> ';
	esc_html_e( 'JetFormBuilder is not active. This plugin requires JetFormBuilder to function.', 'jfb-paystack-gateway' );
	echo '</p></div>';
}

// ── Bootstrap ─────────────────────────────────────────────────────────────────
// Priority 20 — runs after JetFormBuilder (priority 10) has initialised.
add_action( 'plugins_loaded', 'jfb_paystack_init', 20 );

function jfb_paystack_init() {
	if ( ! function_exists( 'jet_form_builder' ) ) {
		return;
	}

	require_once JFB_PAYSTACK_DIR . 'includes/class-jfb-paystack-admin.php';
	require_once JFB_PAYSTACK_DIR . 'includes/class-paystack-tab-handler.php';
	require_once JFB_PAYSTACK_DIR . 'includes/class-paystack-gateway.php';

	// Admin settings page.
	if ( is_admin() ) {
		new JFB_Paystack_Admin();
	}

	// Register Paystack as a native JFB gateway — same hook PayPal uses.
	// Fires on 'init' inside JFB's Gateways\Module::register_gateways().
	add_action( 'jet-form-builder/gateways/register', 'jfb_paystack_register_gateway' );
}

function jfb_paystack_register_gateway( $gateways_module ) {
	$gateways_module->register_gateway( new JFB_Paystack_Gateway() );
}
