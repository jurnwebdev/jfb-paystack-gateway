<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Shared logic for executing a JFB gateway event after a Paystack payment.
 *
 * Used by both the browser-side AJAX handler (JFB_Paystack_Webhook) and the
 * server-to-server REST webhook (JFB_Paystack_Server_Webhook) so that both
 * paths run the same downstream actions (emails, redirects, CCT updates, etc.).
 */
trait JFB_Paystack_Event_Executor {

    /**
     * Resurrect saved form data, inject verified Paystack transaction fields,
     * and fire JFB's GATEWAY.SUCCESS or GATEWAY.FAILED event so all downstream
     * actions configured by the site owner run.
     *
     * Available field macros after payment:
     *   paystack_reference, paystack_status, paystack_amount,
     *   paystack_currency, paystack_channel, paystack_paid_at,
     *   paystack_auth_code, paystack_card_type, paystack_bank,
     *   paystack_last4, paystack_customer_email
     *
     * @param string $type           'success' | 'failed'
     * @param string $reference      Paystack transaction reference.
     * @param int    $form_id        JetFormBuilder form ID.
     * @param array  $transient_data Stored transient (keys: request, form_id, amount_kobo).
     * @param array  $transaction    Full Paystack transaction object (success path only).
     * @return array Result containing redirect, open_in_new_tab, message.
     */
    protected function execute_gateway_event(
        string $type,
        string $reference,
        int    $form_id,
        array  $transient_data,
        array  $transaction = []
    ): array {

        // 1. Restore the original form submission data into the JFB request context.
        $saved_request = $transient_data['request'] ?? [];
        if ( is_array( $saved_request ) && ! empty( $saved_request ) ) {
            jet_fb_action_handler()->add_request( $saved_request );
        }

        // 2. Inject verified Paystack transaction fields for downstream actions.
        if ( ! empty( $transaction ) ) {
            $auth = $transaction['authorization'] ?? [];
            jet_fb_action_handler()->add_request( [
                'paystack_reference'      => sanitize_text_field( $transaction['reference']       ?? $reference ),
                'paystack_status'         => sanitize_text_field( $transaction['status']          ?? $type ),
                'paystack_amount'         => isset( $transaction['amount'] ) ? floatval( $transaction['amount'] / 100 ) : 0,
                'paystack_currency'       => sanitize_text_field( $transaction['currency']        ?? 'NGN' ),
                'paystack_channel'        => sanitize_text_field( $transaction['channel']         ?? '' ),
                'paystack_paid_at'        => sanitize_text_field( $transaction['paid_at']         ?? '' ),
                'paystack_auth_code'      => sanitize_text_field( $auth['authorization_code']    ?? '' ),
                'paystack_card_type'      => sanitize_text_field( $auth['card_type']             ?? '' ),
                'paystack_bank'           => sanitize_text_field( $auth['bank']                  ?? '' ),
                'paystack_last4'          => sanitize_text_field( $auth['last4']                 ?? '' ),
                'paystack_customer_email' => sanitize_email( $transaction['customer']['email']   ?? '' ),
            ] );
        }

        // 3. Load the form's actions into the action handler.
        jet_fb_action_handler()->set_form_id( $form_id );

        $result = [
            'redirect'        => null,
            'open_in_new_tab' => false,
            'message'         => $this->get_form_message( $form_id, $type ),
        ];

        // 4. Fire the JFB gateway event so configured actions (emails, redirects, etc.) run.
        try {
            if ( 'success' === $type ) {
                jet_fb_events()->execute(
                    \Jet_Form_Builder\Actions\Events\Gateway_Success\Gateway_Success_Event::class,
                    $form_id
                );
            } else {
                jet_fb_events()->execute(
                    \Jet_Form_Builder\Actions\Events\Gateway_Failed\Gateway_Failed_Event::class,
                    $form_id
                );
            }
        } catch ( \Throwable $e ) {
            // Swallow — non-fatal; redirect/message may still apply.
            error_log( '[JFB Paystack] Event execution error for reference ' . $reference . ': ' . $e->getMessage() );
        }

        // 5. Read any redirect URL set by a Redirect-to-Page action.
        $response_data = jet_fb_action_handler()->response_data;
        if ( ! empty( $response_data['redirect'] ) ) {
            // Validate redirect — wp_validate_redirect() rejects external domains.
            $safe_redirect             = wp_validate_redirect( $response_data['redirect'], home_url( '/' ) );
            $result['redirect']        = $safe_redirect;
            $result['open_in_new_tab'] = ! empty( $response_data['open_in_new_tab'] );
        }

        return $result;
    }

    /**
     * Read the form's configured success/failed message from post meta.
     * These are set in JetFormBuilder → Form Settings → Messages tab.
     */
    protected function get_form_message( int $form_id, string $type ): string {
        $messages = get_post_meta( $form_id, '_jf_messages', true );

        if ( is_string( $messages ) ) {
            $messages = json_decode( $messages, true );
        }

        if ( 'success' === $type ) {
            return $messages['success'] ?? __( 'Thank you! Your payment was successful.', 'jfb-paystack-gateway' );
        }

        return $messages['failed'] ?? __( 'Payment was cancelled.', 'jfb-paystack-gateway' );
    }
}
