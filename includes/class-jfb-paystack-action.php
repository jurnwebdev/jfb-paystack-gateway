<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class JFB_Paystack_Action extends \Jet_Form_Builder\Actions\Types\Base {

    public function get_id() {
        return 'paystack_checkout_overlay';
    }

    public function get_name() {
        return 'Paystack Checkout Popup';
    }

    public function action_attributes() {
        return [
            'email_field'  => [ 'type' => 'string' ],
            'amount_field' => [ 'type' => 'string' ],
        ];
    }

    public function do_action( array $request, \Jet_Form_Builder\Actions\Action_Handler $handler ) {

        // 1. Load Paystack API keys (secret is AES-encrypted at rest; decrypt before use)
        $keys   = get_option( 'jfb_paystack_keys', [] );
        $secret = jfb_paystack_decrypt_key( $keys['secret'] ?? '' );
        $public = $keys['public'] ?? '';

        if ( empty( $secret ) || empty( $public ) ) {
            throw new \Jet_Form_Builder\Exceptions\Action_Exception( 'failed' );
        }

        // 2. Read the field names mapped in the editor → look up submitted values
        $email_field_name  = $this->settings['email_field']  ?? '';
        $amount_field_name = $this->settings['amount_field'] ?? '';

        if ( empty( $email_field_name ) || empty( $amount_field_name ) ) {
            throw new \Jet_Form_Builder\Exceptions\Action_Exception( 'failed' );
        }

        $email      = isset( $request[ $email_field_name ] )  ? sanitize_email( $request[ $email_field_name ] )  : '';
        $amount_raw = isset( $request[ $amount_field_name ] ) ? (string) $request[ $amount_field_name ] : '0';

        // 3. Convert amount to kobo (Paystack uses minor units)
        $amount_clean = preg_replace( '/[^\d\.]/', '', $amount_raw );
        $amount_kobo  = (int) round( floatval( $amount_clean ) * 100 );

        if ( empty( $email ) || $amount_kobo <= 0 ) {
            throw new \Jet_Form_Builder\Exceptions\Action_Exception( 'failed' );
        }

        // 4. Generate a unique transaction reference
        $reference = 'jfb_' . wp_generate_password( 12, false, false ) . '_' . time();

        // 5. Call Paystack Initialize Transaction API
        $body = [
            'email'     => $email,
            'amount'    => $amount_kobo,
            'reference' => $reference,
            'metadata'  => [
                'form_id'   => $handler->form_id,
                'reference' => $reference,
            ],
            'channels' => [ 'card', 'bank', 'ussd', 'qr', 'mobile_money', 'bank_transfer' ],
        ];

        $res = wp_remote_post( 'https://api.paystack.co/transaction/initialize', [
            'headers' => [
                'Authorization' => 'Bearer ' . wp_strip_all_tags( $secret ),
                'Content-Type'  => 'application/json',
            ],
            'body'    => wp_json_encode( $body ),
            'timeout' => 15,
        ] );

        if ( is_wp_error( $res ) ) {
            throw new \Jet_Form_Builder\Exceptions\Action_Exception( 'failed' );
        }

        $response_body = json_decode( wp_remote_retrieve_body( $res ), true );

        if ( empty( $response_body['status'] ) || empty( $response_body['data']['access_code'] ) ) {
            throw new \Jet_Form_Builder\Exceptions\Action_Exception( 'failed' );
        }

        // 6. Store original form request + form_id in a transient.
        //    Storing form_id here and re-reading it in the webhook prevents an attacker
        //    from submitting a different form_id in the AJAX callback (form-ID spoofing).
        set_transient( 'jfb_paystack_' . $reference, [
            'request'     => $request,
            'form_id'     => $handler->form_id,
            'amount_kobo' => $amount_kobo, // Stored for server-side amount integrity check on verify.
        ], 12 * HOUR_IN_SECONDS );

        // 7. Build the payload the JS popup needs.
        //    Include a nonce so the AJAX verify callback can be CSRF-protected.
        $payload = [
            'access_code' => $response_body['data']['access_code'],
            'reference'   => $reference,
            'public_key'  => $public,
            'email'       => $email,
            'amount'      => $amount_kobo,
            'form_id'     => $handler->form_id,
            'nonce'       => wp_create_nonce( 'jfb_paystack_verify' ),
        ];

        // 8. Bolt our payload onto the JFB AJAX response via filter
        add_filter( 'jet-fb/response-handler/query-args', function( $args ) use ( $payload ) {
            if ( isset( $args['status'] ) && $args['status'] === 'paystack_auth_required' ) {
                $args['paystack'] = $payload;
                $args['message']  = ''; // Suppress the raw status string JFB would show
            }
            return $args;
        } );

        // 9. Stop the JFB action chain — JS picks this up via 'processing-error' event
        throw new \Jet_Form_Builder\Exceptions\Action_Exception( 'paystack_auth_required' );
    }
}
