jQuery(document).ready(function ($) {

    /**
     * JFB AJAX response flow:
     *   1. onSuccess() is called
     *   2. form.toggle() — unlocks the form
     *   3. Fires "jet-form-builder/ajax/processing-error" (our hook)  ← we run here
     *   4. insertMessage(t.message) — JFB appends message AFTER our handler returns
     *
     * Fix: setTimeout(0) removes that message after step 4 completes.
     */
    $(document).on('jet-form-builder/ajax/processing-error', function (event, response, $form) {

        if (!response || response.status !== 'paystack_auth_required') {
            return;
        }

        event.stopImmediatePropagation();

        var formNode = ($form && $form[0]) ? $form[0] : $form;

        // Remove the raw status message JFB inserts AFTER our handler returns
        setTimeout(function () {
            $(formNode).find('.jet-form-builder-messages-wrap').remove();
        }, 0);

        var data = response.paystack;

        if (!data || !data.public_key) {
            console.error('[JFB Paystack] Payload missing from AJAX response.', response);
            return;
        }

        // ─── Paystack Inline Popup ──────────────────────────────────────────
        var handler = PaystackPop.setup({
            key:         data.public_key,
            email:       data.email,
            amount:      data.amount,
            ref:         data.reference,
            access_code: data.access_code,

            onClose: function () {
                // User closed without paying → trigger GATEWAY.FAILED
                $.post(JfbPaystackConfig.ajax_url, {
                    action:    'jfb_paystack_trigger_event',
                    nonce:     data.nonce,
                    status:    'failed',
                    reference: data.reference,
                    form_id:   data.form_id
                }, function (res) {
                    if (res && res.success && res.data && res.data.message) {
                        insertFormMessage(formNode, res.data.message, 'error');
                    }
                });
            },

            callback: function (paymentResponse) {
                var $btn    = $(formNode).find('[type="submit"]');
                var oldText = $btn.text();
                $btn.text('Verifying payment…').prop('disabled', true);

                // Verify server-side → triggers GATEWAY.SUCCESS + downstream actions
                $.ajax({
                    url:      JfbPaystackConfig.ajax_url,
                    method:   'POST',
                    dataType: 'json',
                    data: {
                        action:    'jfb_paystack_trigger_event',
                        nonce:     data.nonce,
                        status:    'success',
                        reference: paymentResponse.reference,
                        form_id:   data.form_id
                    },

                    success: function (res) {
                        $btn.text(oldText).prop('disabled', false);

                        if (!res.success) {
                            var errMsg = (res.data && res.data.message) || 'Payment verification failed.';
                            insertFormMessage(formNode, errMsg, 'error');
                            return;
                        }

                        var redirectUrl = res.data && res.data.redirect;
                        var openNewTab  = res.data && res.data.open_in_new_tab;
                        var formMessage = (res.data && res.data.message) || '';

                        if (redirectUrl) {
                            // A Redirect-to-Page action fired — navigate the browser
                            if (formMessage) {
                                insertFormMessage(formNode, formMessage, 'success');
                            }
                            setTimeout(function () {
                                if (openNewTab) {
                                    window.open(redirectUrl, '_blank');
                                } else {
                                    window.location.href = redirectUrl;
                                }
                            }, formMessage ? 1000 : 0);
                            return;
                        }

                        // No redirect — show the form's configured success message
                        $(formNode)[0].reset();
                        insertFormMessage(formNode, formMessage || 'Payment successful!', 'success');
                    },

                    error: function () {
                        $btn.text(oldText).prop('disabled', false);
                        insertFormMessage(formNode, 'Network error while verifying payment. Please contact support.', 'error');
                    }
                });
            }
        });

        handler.openIframe();
    });

    /**
     * Insert a message into JFB's standard message container.
     * Matches the HTML structure JFB uses so existing form styles apply.
     *
     * @param {Element} formNode  The <form> or wrapper element
     * @param {string}  message   Message text / HTML
     * @param {string}  type      'success' | 'error'
     */
    function insertFormMessage(formNode, message, type) {
        if (!message) { return; }

        var cssClass = (type === 'success')
            ? 'jet-form-builder__success'
            : 'jet-form-builder__error';

        var $wrap = $(formNode).find('.jet-form-builder-messages-wrap');
        if (!$wrap.length) {
            $wrap = $('<div class="jet-form-builder-messages-wrap"></div>');
            $(formNode).append($wrap);
        }

        // Build element safely — use .text() to prevent XSS from server-supplied messages.
        var $msg = $('<div></div>').addClass(cssClass).text(message);
        $wrap.empty().append($msg);

        // Auto-scroll to the message
        $wrap[0].scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }
});
