<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Registers a standalone Settings → JFB Paystack admin page
 * for storing Paystack API keys.
 */
class JFB_Paystack_Admin {

    public function __construct() {
        add_action( 'admin_menu',  [ $this, 'register_menu' ] );
        add_action( 'admin_init',  [ $this, 'register_settings' ] );
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
        $public     = isset( $input['public'] ) ? sanitize_text_field( $input['public'] ) : '';
        $raw_secret = isset( $input['secret'] ) ? sanitize_text_field( $input['secret'] ) : '';

        if ( '' !== $raw_secret ) {
            // Encrypt the incoming plain-text secret key before persisting.
            $secret = jfb_paystack_encrypt_key( $raw_secret );
        } else {
            // Blank submission — preserve whichever encrypted value is already stored.
            $existing = get_option( 'jfb_paystack_keys', [] );
            $secret   = $existing['secret'] ?? '';
        }

        return [
            'public' => $public,
            'secret' => $secret,
        ];
    }

    public function render_settings_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $keys   = get_option( 'jfb_paystack_keys', [] );
        $public = $keys['public'] ?? '';
        // Decrypt for display — the <input type="password"> keeps it masked in the browser.
        $secret = jfb_paystack_decrypt_key( $keys['secret'] ?? '' );

        $is_test_mode = ! empty( $public ) && str_starts_with( $public, 'pk_test_' );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'JFB Paystack Gateway', 'jfb-paystack-gateway' ); ?></h1>
            <p class="description">
                <?php esc_html_e( 'Connect your Paystack account to JetFormBuilder. Use test keys for sandbox mode, or live keys for production.', 'jfb-paystack-gateway' ); ?>
            </p>

            <?php if ( ! empty( $public ) ) : ?>
            <div class="notice notice-<?php echo $is_test_mode ? 'warning' : 'success'; ?> inline" style="margin: 10px 0;">
                <p>
                    <?php if ( $is_test_mode ) : ?>
                        <strong><?php esc_html_e( 'Sandbox Mode', 'jfb-paystack-gateway' ); ?></strong> —
                        <?php esc_html_e( 'Test keys detected. No real payments will be processed.', 'jfb-paystack-gateway' ); ?>
                    <?php else : ?>
                        <strong><?php esc_html_e( 'Live Mode', 'jfb-paystack-gateway' ); ?></strong> —
                        <?php esc_html_e( 'Live keys are active. Real payments will be processed.', 'jfb-paystack-gateway' ); ?>
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
                            <p class="description"><?php esc_html_e( 'Your Paystack public key. Starts with pk_test_ or pk_live_.', 'jfb-paystack-gateway' ); ?></p>
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
                            <p class="description"><?php esc_html_e( 'Your Paystack secret key. Never share this publicly. Used for server-side verification.', 'jfb-paystack-gateway' ); ?></p>
                        </td>
                    </tr>
                </table>

                <h2><?php esc_html_e( 'Server Webhook (Fallback)', 'jfb-paystack-gateway' ); ?></h2>
                <p class="description" style="max-width: 600px;">
                    <?php esc_html_e( 'Register this URL in your Paystack Dashboard → Settings → API Keys & Webhooks. It ensures payment actions (emails, redirects, database updates) always fire — even if the buyer\'s browser loses connection after paying.', 'jfb-paystack-gateway' ); ?>
                </p>
                <table class="form-table" role="presentation" style="max-width: 600px;">
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Webhook URL', 'jfb-paystack-gateway' ); ?></th>
                        <td>
                            <code style="display:inline-block; padding: 6px 10px; background:#f0f0f1; border:1px solid #c3c4c7; border-radius:3px; user-select:all; word-break:break-all;">
                                <?php echo esc_html( rest_url( 'jfb-paystack/v1/webhook' ) ); ?>
                            </code>
                            <p class="description" style="margin-top:6px;">
                                <?php esc_html_e( 'Paystack will POST signed charge.success events to this URL. The plugin verifies the HMAC-SHA512 signature before processing anything.', 'jfb-paystack-gateway' ); ?>
                            </p>
                        </td>
                    </tr>
                </table>

                <h2><?php esc_html_e( 'How to Use', 'jfb-paystack-gateway' ); ?></h2>
                <ol style="max-width: 600px;">
                    <li><?php esc_html_e( 'Save your Paystack API keys above.', 'jfb-paystack-gateway' ); ?></li>
                    <li><?php esc_html_e( 'Copy the Webhook URL above into your Paystack Dashboard → Settings → API Keys & Webhooks.', 'jfb-paystack-gateway' ); ?></li>
                    <li><?php esc_html_e( 'Edit a JetFormBuilder form and add the "Paystack Checkout Popup" action.', 'jfb-paystack-gateway' ); ?></li>
                    <li><?php esc_html_e( 'Map your form\'s email and amount fields in the action settings.', 'jfb-paystack-gateway' ); ?></li>
                    <li><?php esc_html_e( 'Add follow-up actions (e.g. Redirect to Page, Send Email) — set their condition to "Paystack: On Payment Success".', 'jfb-paystack-gateway' ); ?></li>
                </ol>

                <h2><?php esc_html_e( 'Available Field Macros (after payment)', 'jfb-paystack-gateway' ); ?></h2>
                <p class="description"><?php esc_html_e( 'These field names are injected after a successful payment and can be used in follow-up actions:', 'jfb-paystack-gateway' ); ?></p>
                <table class="widefat striped" style="max-width: 600px; margin-bottom: 20px;">
                    <thead><tr><th><?php esc_html_e( 'Field Name', 'jfb-paystack-gateway' ); ?></th><th><?php esc_html_e( 'Description', 'jfb-paystack-gateway' ); ?></th></tr></thead>
                    <tbody>
                        <tr><td><code>paystack_reference</code></td><td><?php esc_html_e( 'Transaction reference', 'jfb-paystack-gateway' ); ?></td></tr>
                        <tr><td><code>paystack_status</code></td><td><?php esc_html_e( 'Transaction status (success)', 'jfb-paystack-gateway' ); ?></td></tr>
                        <tr><td><code>paystack_amount</code></td><td><?php esc_html_e( 'Amount charged (in major units e.g. NGN)', 'jfb-paystack-gateway' ); ?></td></tr>
                        <tr><td><code>paystack_currency</code></td><td><?php esc_html_e( 'Currency code (e.g. NGN)', 'jfb-paystack-gateway' ); ?></td></tr>
                        <tr><td><code>paystack_channel</code></td><td><?php esc_html_e( 'Payment channel (card, bank, etc.)', 'jfb-paystack-gateway' ); ?></td></tr>
                        <tr><td><code>paystack_paid_at</code></td><td><?php esc_html_e( 'Timestamp of payment', 'jfb-paystack-gateway' ); ?></td></tr>
                        <tr><td><code>paystack_auth_code</code></td><td><?php esc_html_e( 'Authorization code', 'jfb-paystack-gateway' ); ?></td></tr>
                        <tr><td><code>paystack_card_type</code></td><td><?php esc_html_e( 'Card type (Visa, Mastercard, etc.)', 'jfb-paystack-gateway' ); ?></td></tr>
                        <tr><td><code>paystack_bank</code></td><td><?php esc_html_e( 'Issuing bank', 'jfb-paystack-gateway' ); ?></td></tr>
                        <tr><td><code>paystack_last4</code></td><td><?php esc_html_e( 'Last 4 digits of card', 'jfb-paystack-gateway' ); ?></td></tr>
                        <tr><td><code>paystack_customer_email</code></td><td><?php esc_html_e( 'Customer email from Paystack', 'jfb-paystack-gateway' ); ?></td></tr>
                    </tbody>
                </table>

                <?php submit_button( __( 'Save API Keys', 'jfb-paystack-gateway' ) ); ?>
            </form>
        </div>
        <?php
    }
}
