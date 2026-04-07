<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class JFB_Paystack_Webhook {

    use JFB_Paystack_Event_Executor;

    public function __construct() {
        add_action( 'wp_ajax_jfb_paystack_trigger_event',        [ $this, 'handle_ajax' ] );
        add_action( 'wp_ajax_nopriv_jfb_paystack_trigger_event', [ $this, 'handle_ajax' ] );
    }

    public function handle_ajax() {

        // ── Rate limiting — max 15 attempts per IP per minute ────────────────────────
        // Prevents brute-force replay or enumeration of transaction references.
        $ip     = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
        $rl_key = 'jfb_paystack_rl_' . md5( $ip );
        $rl_hits = (int) get_transient( $rl_key );
        if ( $rl_hits >= 15 ) {
            error_log( '[JFB Paystack] Rate limit exceeded for IP: ' . $ip );
            wp_send_json_error( [ 'message' => 'Too many requests. Please wait a moment and try again.' ], 429 );
        }
        set_transient( $rl_key, $rl_hits + 1, MINUTE_IN_SECONDS );

        // ── Fix 1: CSRF — verify the nonce that was baked into the JS payload ──────
        // The nonce was generated server-side in do_action() and returned only through
        // JFB's own AJAX response, so external sites cannot forge it.
        if ( ! check_ajax_referer( 'jfb_paystack_verify', 'nonce', false ) ) {
            error_log( '[JFB Paystack] CSRF nonce verification failed for IP: ' . $ip );
            wp_send_json_error( [ 'message' => 'Security check failed.' ], 403 );
        }

        $reference = isset( $_POST['reference'] ) ? sanitize_text_field( wp_unslash( $_POST['reference'] ) ) : '';
        $form_id   = isset( $_POST['form_id'] )   ? absint( $_POST['form_id'] )                             : 0;
        $status    = isset( $_POST['status'] )     ? sanitize_text_field( wp_unslash( $_POST['status'] ) )  : '';

        if ( ! $reference || ! $form_id ) {
            error_log( '[JFB Paystack] Invalid request — missing reference or form_id from IP: ' . $ip );
            wp_send_json_error( [ 'message' => 'Invalid request.' ], 400 );
        }

        // ── Fix 2: Proof-of-origin — confirm this reference was initiated on this server ──
        // Reads the transient that was set in do_action(). If it doesn't exist, this
        // reference was never created by us, so reject immediately.
        $transient_data = get_transient( 'jfb_paystack_' . $reference );

        if ( ! is_array( $transient_data ) ) {
            // Unknown reference — not initiated by this site, or already expired.
            error_log( '[JFB Paystack] Unknown or expired reference "' . $reference . '" from IP: ' . $ip );
            wp_send_json_error( [ 'message' => 'Invalid or expired transaction reference.' ], 400 );
        }

        // ── Fix 3: Form-ID ownership — prevent an attacker submitting a different form_id ──
        // The authoritative form_id was stored server-side when the transaction was created.
        $expected_form_id = absint( $transient_data['form_id'] ?? 0 );
        if ( $form_id !== $expected_form_id ) {
            error_log( sprintf(
                '[JFB Paystack] Form ID mismatch for reference "%s" — expected %d, got %d. IP: %s',
                $reference, $expected_form_id, $form_id, $ip
            ) );
            wp_send_json_error( [ 'message' => 'Form mismatch.' ], 403 );
        }

        // === FAILED / CANCELLED ===
        // We allow failed without a Paystack API call, but only after the proof-of-origin
        // check above confirms the reference was legitimately created by this server.
        if ( 'failed' === $status ) {
            $result = $this->execute_gateway_event( 'failed', $reference, $form_id, $transient_data );
            wp_send_json_success( $result );
        }

        // === SUCCESS — verify with Paystack server-side before doing anything ===
        $keys   = get_option( 'jfb_paystack_keys', [] );
        // Secret key is AES-encrypted at rest; decrypt before use.
        $secret = jfb_paystack_decrypt_key( $keys['secret'] ?? '' );

        $res = wp_remote_get(
            'https://api.paystack.co/transaction/verify/' . rawurlencode( $reference ),
            [
                'headers'   => [ 'Authorization' => 'Bearer ' . wp_strip_all_tags( $secret ) ],
                'timeout'   => 15,
                'sslverify' => true,
            ]
        );

        if ( is_wp_error( $res ) ) {
            // ── Fix 4: Don't leak internal errors to the client ──────────────────
            error_log( '[JFB Paystack] Could not reach Paystack API: ' . $res->get_error_message() );
            wp_send_json_error( [ 'message' => 'Payment verification is temporarily unavailable. Please contact support.' ], 503 );
        }

        $http_code = wp_remote_retrieve_response_code( $res );
        if ( 200 !== $http_code ) {
            error_log( '[JFB Paystack] Paystack API returned HTTP ' . $http_code . ' for reference ' . $reference );
            wp_send_json_error( [ 'message' => 'Payment verification failed. Please contact support.' ], 502 );
        }

        $body = json_decode( wp_remote_retrieve_body( $res ), true );

        if (
            ! empty( $body['status'] ) &&
            ! empty( $body['data']['status'] ) &&
            'success' === $body['data']['status']
        ) {
            // ── Amount integrity check ───────────────────────────────────────────────
            // Compare the amount we initialised (stored in transient) against the amount
            // Paystack actually charged. A mismatch means something tampered with the
            // flow or the wrong transaction was submitted.
            $expected_kobo = isset( $transient_data['amount_kobo'] ) ? (int) $transient_data['amount_kobo'] : null;
            $verified_kobo = isset( $body['data']['amount'] )        ? (int) $body['data']['amount']        : null;

            if ( null !== $expected_kobo && null !== $verified_kobo && $expected_kobo !== $verified_kobo ) {
                error_log( sprintf(
                    '[JFB Paystack] Amount mismatch for reference "%s" — expected %d kobo, Paystack returned %d kobo. IP: %s',
                    $reference, $expected_kobo, $verified_kobo, $ip
                ) );
                wp_send_json_error( [ 'message' => 'Payment amount mismatch. Please contact support.' ], 400 );
            }

            // Deduplicate — prevent double-firing for the same reference
            if ( get_transient( 'jfb_paystack_processed_' . $reference ) ) {
                wp_send_json_success( [ 'message' => 'Already processed.' ] );
            }
            set_transient( 'jfb_paystack_processed_' . $reference, true, 24 * HOUR_IN_SECONDS );

            $transaction = $body['data'];
            $result      = $this->execute_gateway_event( 'success', $reference, $form_id, $transient_data, $transaction );
            wp_send_json_success( $result );
        }

        // The Paystack transaction came back but was not 'success'
        wp_send_json_error( [ 'message' => 'Transaction was not successful.' ], 400 );
    }

}
