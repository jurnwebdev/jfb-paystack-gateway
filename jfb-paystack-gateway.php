<?php
/**
 * Plugin Name:       JFB Paystack Gateway
 * Plugin URI:        https://github.com/your-username/jfb-paystack-gateway
 * Description:       Adds a native Paystack Inline Checkout action to JetFormBuilder, with server-side verification and post-payment action support (redirects, emails, CCT updates).
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Your Name
 * Author URI:        https://yourwebsite.com
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       jfb-paystack-gateway
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

// ── Plugin constants ─────────────────────────────────────────────────────────
define( 'JFB_PAYSTACK_VERSION', '1.0.0' );
define( 'JFB_PAYSTACK_FILE',    __FILE__ );
define( 'JFB_PAYSTACK_DIR',     plugin_dir_path( __FILE__ ) );
define( 'JFB_PAYSTACK_URL',     plugin_dir_url( __FILE__ ) );

// ── API key encryption helpers ────────────────────────────────────────────────

/**
 * Encrypt a Paystack API key for storage using AES-256-CBC.
 *
 * Uses AUTH_KEY from wp-config.php as the passphrase so the encrypted blob is
 * worthless without access to the WordPress installation's configuration.
 * Encrypted values are prefixed with 'enc:' to allow safe migration of any
 * existing plain-text keys already in the database.
 *
 * Falls back to returning the plain-text value if openssl is unavailable.
 *
 * @param string $value Plain-text key.
 * @return string Encrypted (prefixed) value, or original value if unavailable.
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
 *
 * Values not prefixed with 'enc:' are treated as legacy plain-text keys and
 * returned unchanged, so existing installations keep working after upgrade.
 *
 * @param string $value Possibly encrypted (prefixed) value.
 * @return string Plain-text key.
 */
function jfb_paystack_decrypt_key( string $value ): string {
    if ( empty( $value ) || substr( $value, 0, 4 ) !== 'enc:' ) {
        return $value; // Legacy plain-text — return as-is.
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

// ── Activation check ─────────────────────────────────────────────────────────
register_activation_hook( __FILE__, 'jfb_paystack_check_dependencies' );

function jfb_paystack_check_dependencies() {
    // class_exists() is unreliable here — JetFormBuilder registers its main class
    // inside a plugins_loaded callback, so it is not yet in memory when our
    // activation hook fires. Instead, read the active plugins list from the database
    // directly, which is always available regardless of load order.
    if ( ! function_exists( 'is_plugin_active' ) ) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }

    $active = array_merge(
        (array) get_option( 'active_plugins', [] ),
        is_multisite() ? array_keys( (array) get_site_option( 'active_sitewide_plugins', [] ) ) : []
    );

    $jfb_active = false;
    foreach ( $active as $plugin_file ) {
        if ( false !== strpos( $plugin_file, 'jet-form-builder' ) ) {
            $jfb_active = true;
            break;
        }
    }

    if ( ! $jfb_active ) {
        deactivate_plugins( plugin_basename( __FILE__ ) );
        wp_die(
            esc_html__( 'JFB Paystack Gateway requires JetFormBuilder to be installed and activated.', 'jfb-paystack-gateway' ),
            esc_html__( 'Plugin Activation Error', 'jfb-paystack-gateway' ),
            [ 'back_link' => true ]
        );
    }
}

// ── Soft dependency notice (if JFB is deactivated after our plugin is active) ─
add_action( 'admin_notices', 'jfb_paystack_dependency_notice' );
function jfb_paystack_dependency_notice() {
    // JetFormBuilder's public API is the jet_form_builder() function.
    // Its main class is namespaced (\Jet_Form_Builder\Plugin), so
    // class_exists('Jet_Form_Builder') is always false and cannot be used.
    if ( function_exists( 'jet_form_builder' ) ) {
        return;
    }
    echo '<div class="notice notice-error"><p>';
    echo '<strong>JFB Paystack Gateway:</strong> ';
    esc_html_e( 'JetFormBuilder is not active. This plugin requires JetFormBuilder to function.', 'jfb-paystack-gateway' );
    echo '</p></div>';
}

// ── Bootstrap ─────────────────────────────────────────────────────────────────
// Priority 20 ensures we run after JetFormBuilder (priority 10) has finished
// registering its classes and the jet_form_builder() function is available.
add_action( 'plugins_loaded', 'jfb_paystack_init', 20 );

function jfb_paystack_init() {
    // Bail silently if JFB hasn't initialised yet.
    if ( ! function_exists( 'jet_form_builder' ) ) {
        return;
    }

    // Core class files
    require_once JFB_PAYSTACK_DIR . 'includes/trait-jfb-paystack-event-executor.php';
    require_once JFB_PAYSTACK_DIR . 'includes/class-jfb-paystack-admin.php';
    require_once JFB_PAYSTACK_DIR . 'includes/class-jfb-paystack-events.php';
    require_once JFB_PAYSTACK_DIR . 'includes/class-jfb-paystack-action.php';
    require_once JFB_PAYSTACK_DIR . 'includes/class-jfb-paystack-webhook.php';
    require_once JFB_PAYSTACK_DIR . 'includes/class-jfb-paystack-server-webhook.php';

    // Boot components
    if ( is_admin() ) {
        new JFB_Paystack_Admin();
    }

    new JFB_Paystack_Events();
    new JFB_Paystack_Webhook();
    new JFB_Paystack_Server_Webhook();

    // Register the JFB action type
    add_action( 'jet-form-builder/actions/register', function( $manager ) {
        $manager->register_action_type( new JFB_Paystack_Action() );
    } );

    // Inject block editor JS (action config UI)
    add_action( 'enqueue_block_editor_assets', 'jfb_paystack_inject_editor_js', 100 );

    // Enqueue frontend scripts
    add_action( 'wp_enqueue_scripts', 'jfb_paystack_enqueue_frontend_scripts' );
}

// ── Editor JS ────────────────────────────────────────────────────────────────
function jfb_paystack_inject_editor_js() {
    $js = <<<JS
    wp.domReady(function() {
        if (!window.jfb || !window.jfb.actions) return;
        var e = window.React;
        var i = window.jfb.actions;

        i.registerAction({
            type: "paystack_checkout_overlay",
            label: "Paystack Checkout Popup",
            edit: function(props) {
                var settings = props.settings || {};
                var onChange = props.onChangeSettingObj;
                return e.createElement( 'div', { style: { padding: '10px', background: '#f8f9f9', border: '1px solid #ddd' } },
                    e.createElement( 'div', { style: { marginBottom: '10px' } },
                        e.createElement( 'label', { style: { display: 'block', fontWeight: 'bold', marginBottom: '5px' } }, "Email Field Name" ),
                        e.createElement( 'input', {
                            type: 'text',
                            placeholder: 'e.g. user_email',
                            value: settings.email_field || '',
                            onChange: function(evt) { onChange({ email_field: evt.target.value }); },
                            style: { width: '100%', padding: '5px' }
                        })
                    ),
                    e.createElement( 'div', { style: { marginBottom: '10px' } },
                        e.createElement( 'label', { style: { display: 'block', fontWeight: 'bold', marginBottom: '5px' } }, "Amount Field Name" ),
                        e.createElement( 'input', {
                            type: 'text',
                            placeholder: 'e.g. total_amount',
                            value: settings.amount_field || '',
                            onChange: function(evt) { onChange({ amount_field: evt.target.value }); },
                            style: { width: '100%', padding: '5px' }
                        })
                    ),
                    e.createElement( 'p', { style:{fontSize:'12px', color:'#666'} }, "Enter the names of the form fields to map to Paystack." )
                );
            }
        });
    });
JS;
    wp_add_inline_script( 'jet-fb-components', $js );
}

// ── Frontend Scripts ──────────────────────────────────────────────────────────
function jfb_paystack_enqueue_frontend_scripts() {
    wp_enqueue_script(
        'paystack-inline',
        'https://js.paystack.co/v1/inline.js',
        [],
        null,
        true
    );
    wp_enqueue_script(
        'jfb-paystack-checkout',
        JFB_PAYSTACK_URL . 'assets/js/paystack-checkout.js',
        [ 'jquery', 'paystack-inline' ],
        JFB_PAYSTACK_VERSION,
        true
    );
    wp_localize_script( 'jfb-paystack-checkout', 'JfbPaystackConfig', [
        'ajax_url' => admin_url( 'admin-ajax.php' ),
        'nonce'    => wp_create_nonce( 'jfb_paystack_verify' ),
    ] );
}
