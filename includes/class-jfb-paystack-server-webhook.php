<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Server-to-server Paystack webhook handler.
 *
 * Paystack calls this endpoint directly after a payment event, independently
 * of the user's browser. This is the fallback that ensures JFB downstream
 * actions (emails, database updates, redirects) always fire even when:
 *   - The user's browser loses connectivity after paying.
 *   - The browser AJAX call times out or silently fails.
 *   - The user closes the tab immediately after completing payment.
 *
 * Endpoint: POST /wp-json/jfb-paystack/v1/webhook
 *
 * Authentication: Paystack signs every request with HMAC-SHA512 of the raw
 * request body using your secret key. We verify this signature before doing
 * anything — no WordPress nonce is needed here because Paystack is the caller.
 *
 * Deduplication: shares the same 'jfb_paystack_processed_*' transient flag
 * with the AJAX handler, so whichever path fires first wins and the other
 * silently acknowledges without double-processing.
 */
class JFB_Paystack_Server_Webhook {

    use JFB_Paystack_Event_Executor;

    public function __construct() {
        add_action( 'rest_api_init', [ $this, 'register_route' ] );
    }

    public function register_route(): void {
        register_rest_route(
            'jfb-paystack/v1',
            '/webhook',
            [
                'methods'             => 'POST',
                'callback'            => [ $this, 'handle' ],
                // Authentication is via HMAC signature, not WordPress capability.
                'permission_callback' => '__return_true',
            ]
        );
    }

    /**
     * Handle an inbound Paystack webhook call.
     *
     * Flow:
     *   1. Verify HMAC-SHA512 signature — rejects spoofed/tampered requests.
     *   2. Parse JSON body and confirm event type is charge.success.
     *   3. Check deduplication flag — if AJAX path already processed this, acknowledge and exit.
     *   4. Confirm proof-of-origin — reference must exist in our transients.
     *   5. Amount integrity check — verified amount must match what we initialized.
     *   6. Set deduplication flag atomically before executing to block the AJAX path.
     *   7. Execute JFB gateway event (same as AJAX path via shared trait).
     *
     * Always returns HTTP 200 to Paystack (even on non-fatal rejections) so that
     * Paystack does not keep retrying events that we intentionally don't process.
     * Only returns non-200 on signature failure or server misconfiguration.
     */
    public function handle( WP_REST_Request $request ): WP_REST_Response {

        // ── Step 1: HMAC-SHA512 signature verification ───────────────────────────
        // Paystack computes: HMAC-SHA512( raw_request_body, secret_key )
        // and sends the hex digest in the x-paystack-signature header.
        // We must compute the same hash and compare with hash_equals() (timing-safe).
        $raw_body  = $request->get_body();
        $signature = (string) $request->get_header( 'x-paystack-signature' );

        $keys   = get_option( 'jfb_paystack_keys', [] );
        $secret = jfb_paystack_decrypt_key( $keys['secret'] ?? '' );

        if ( empty( $secret ) ) {
            error_log( '[JFB Paystack Server Webhook] No secret key configured — cannot verify signature.' );
            return new WP_REST_Response( [ 'message' => 'Gateway not configured.' ], 500 );
        }

        if ( empty( $signature ) ) {
            error_log( '[JFB Paystack Server Webhook] Request arrived with no x-paystack-signature header.' );
            return new WP_REST_Response( [ 'message' => 'Missing signature.' ], 401 );
        }

        $expected = hash_hmac( 'sha512', $raw_body, $secret );

        if ( ! hash_equals( $expected, $signature ) ) {
            // Could be a spoofed request, a misconfigured secret, or a corrupted payload.
            error_log( '[JFB Paystack Server Webhook] Signature mismatch — request rejected.' );
            return new WP_REST_Response( [ 'message' => 'Invalid signature.' ], 401 );
        }

        // ── Step 2: Parse body and check event type ───────────────────────────────
        $event = json_decode( $raw_body, true );

        if ( ! is_array( $event ) ) {
            error_log( '[JFB Paystack Server Webhook] Could not parse JSON body.' );
            return new WP_REST_Response( [ 'message' => 'Invalid payload.' ], 400 );
        }

        $event_type = $event['event'] ?? '';

        // Only act on successful charge events. Acknowledge all others with 200
        // so Paystack doesn't retry them.
        if ( 'charge.success' !== $event_type ) {
            return new WP_REST_Response( [ 'message' => 'Event type not handled: ' . sanitize_text_field( $event_type ) ], 200 );
        }

        $data      = $event['data'] ?? [];
        $reference = sanitize_text_field( $data['reference'] ?? '' );

        if ( empty( $reference ) ) {
            error_log( '[JFB Paystack Server Webhook] charge.success event arrived with no reference.' );
            return new WP_REST_Response( [ 'message' => 'Missing reference.' ], 200 );
        }

        // ── Step 3: Deduplication — check if browser AJAX already handled this ────
        // The AJAX handler and this webhook share the same transient flag so that
        // whichever path completes first prevents the other from double-firing.
        if ( get_transient( 'jfb_paystack_processed_' . $reference ) ) {
            error_log( '[JFB Paystack Server Webhook] Reference already processed (AJAX path won): ' . $reference );
            return new WP_REST_Response( [ 'message' => 'Already processed.' ], 200 );
        }

        // ── Step 4: Proof-of-origin — reference must have been initialized here ───
        // If the transient doesn't exist, this reference was either created on a
        // different site sharing the same Paystack account, or it has expired.
        // Return 200 so Paystack stops retrying — we just don't process it.
        $transient_data = get_transient( 'jfb_paystack_' . $reference );

        if ( ! is_array( $transient_data ) ) {
            error_log( '[JFB Paystack Server Webhook] No transient found for reference: ' . $reference );
            return new WP_REST_Response( [ 'message' => 'Reference not recognized on this site.' ], 200 );
        }

        $form_id = absint( $transient_data['form_id'] ?? 0 );

        if ( ! $form_id ) {
            error_log( '[JFB Paystack Server Webhook] Invalid form_id in transient for reference: ' . $reference );
            return new WP_REST_Response( [ 'message' => 'Invalid transient data.' ], 200 );
        }

        // ── Step 5: Amount integrity check ───────────────────────────────────────
        // The amount we initialized is stored in the transient. Cross-check against
        // the amount Paystack reports in the webhook payload.
        $expected_kobo = isset( $transient_data['amount_kobo'] ) ? (int) $transient_data['amount_kobo'] : null;
        $verified_kobo = isset( $data['amount'] )                ? (int) $data['amount']                : null;

        if ( null !== $expected_kobo && null !== $verified_kobo && $expected_kobo !== $verified_kobo ) {
            error_log( sprintf(
                '[JFB Paystack Server Webhook] Amount mismatch for reference "%s" — expected %d kobo, webhook reported %d kobo.',
                $reference, $expected_kobo, $verified_kobo
            ) );
            // Return 200 — stop retries, but do not process the mismatched payment.
            return new WP_REST_Response( [ 'message' => 'Amount mismatch.' ], 200 );
        }

        // ── Step 6: Mark as processed before executing ────────────────────────────
        // Setting the flag here (before execute_gateway_event) minimises the race
        // window where a near-simultaneous AJAX call could also pass the dedup check.
        set_transient( 'jfb_paystack_processed_' . $reference, 'webhook', 24 * HOUR_IN_SECONDS );

        // ── Step 7: Execute JFB gateway event ────────────────────────────────────
        // execute_gateway_event() is provided by the JFB_Paystack_Event_Executor
        // trait — identical logic to the AJAX handler's success path.
        $this->execute_gateway_event( 'success', $reference, $form_id, $transient_data, $data );

        error_log( '[JFB Paystack Server Webhook] Successfully processed reference via server webhook: ' . $reference );

        return new WP_REST_Response( [ 'message' => 'OK' ], 200 );
    }
}
