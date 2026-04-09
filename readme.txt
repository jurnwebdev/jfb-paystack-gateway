=== JFB Paystack Gateway ===
Contributors: tobijohn
Tags: paystack, jetformbuilder, payment, gateway, nigeria
Requires at least: 6.0
Tested up to: 6.7
Stable tag: 2.1.1
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Adds Paystack as a native JetFormBuilder payment gateway with server-side verification, full transaction data capture, and post-payment action support.

== Description ==

**JFB Paystack Gateway** integrates [Paystack](https://paystack.com) payments directly into [JetFormBuilder](https://jetformbuilder.com) forms as a native payment gateway — exactly like PayPal is built into JFB.

When a user submits a form, they are redirected to the Paystack hosted checkout page. After payment, Paystack sends them back to your site where the plugin verifies the transaction server-side and fires JFB's native GATEWAY.SUCCESS or GATEWAY.FAILED events, triggering any downstream actions you have configured (Insert CCT, Send Email, Redirect to Page, Show Popup, etc.).

= Key Features =

* Native JFB gateway — appears alongside PayPal in the form editor Payment Gateways panel
* Paystack hosted checkout redirect flow (no popups, works on any device)
* Server-side transaction verification via Paystack API before any actions run
* Amount integrity check — verifies the amount paid matches what was expected
* Auto-detection of customer email from form fields (no manual mapping required)
* Transaction data injected as hidden form fields for use in CCT inserts, emails, and redirects
* Deduplication — prevents double-processing if user hits back or refreshes
* API key encryption at rest using AES-256-CBC (requires OpenSSL)
* Sandbox / live mode auto-detected from your API keys
* Clean admin settings page at Settings → JFB Paystack
* Works with JetFormBuilder free and pro

= Available Transaction Fields (Hidden Field Names) =

After a successful payment, the plugin populates the following hidden form fields with real data from Paystack. Add Hidden Field blocks with these exact names to your JetFormBuilder form to capture the values in your database records or emails.

**Always populated:**

* `paystack_reference` — Unique transaction reference (e.g. jfb_paystack_abc123_1234567890)
* `paystack_status` — Transaction status ("success")
* `paystack_amount` — Amount charged in major currency units (e.g. 10000.00 for ₦10,000)
* `paystack_currency` — ISO currency code (e.g. NGN, GHS, USD)
* `paystack_channel` — Payment channel: "card", "bank", "ussd", "mobile_money", "qr"
* `paystack_paid_at` — ISO 8601 payment timestamp (e.g. 2024-04-09T12:34:56.000Z)
* `paystack_customer_email` — Customer email as recorded by Paystack

**Populated for card payments only:**

* `paystack_auth_code` — Authorization code (for future charges / tokenisation)
* `paystack_card_type` — Card scheme: "visa", "mastercard", "verve"
* `paystack_bank` — Issuing bank name (e.g. "Guaranty Trust Bank")
* `paystack_last4` — Last 4 digits of the card used

= Requirements =

* WordPress 6.0+
* PHP 7.4+
* [JetFormBuilder](https://jetformbuilder.com) plugin (free or pro)
* A [Paystack](https://paystack.com) account with API keys

== Installation ==

1. Upload the `jfb-paystack-gateway` folder to `/wp-content/plugins/` and activate it, **or** install via Plugins → Add New → Upload Plugin.
2. JetFormBuilder must be installed and active — the plugin will not activate without it.
3. Go to **Settings → JFB Paystack** and follow the setup guide on that page.

== Setup Guide ==

**Step 1 — API Keys & Amount Field**

1. Get your API keys from the Paystack Dashboard → Settings → API Keys & Webhooks.
2. In WordPress, go to Settings → JFB Paystack.
3. Enter your Public Key (starts with pk_test_ or pk_live_).
4. Enter your Secret Key (starts with sk_test_ or sk_live_). It is stored encrypted on your server.
5. Enter the Amount Field Name — the exact name of the field in your JetFormBuilder form that holds the payment amount (e.g. "amount", "price", "total").
6. Enter the Currency code (e.g. NGN, GHS, USD). Defaults to NGN.
7. Click Save Settings.

**Step 2 — Enable Gateways in JetFormBuilder**

1. Go to JetFormBuilder → Settings → Payment Gateways.
2. Toggle "Enable Gateways" on and save.

**Step 3 — Configure Your Form**

1. Edit your JetFormBuilder form.
2. Open the Payment Gateways panel on the right and select Paystack.
3. Make sure your form has a numeric field for the amount whose name matches the Amount Field Name you configured.
4. Make sure your form has an email field — the plugin auto-detects any field with a valid email address.

**Step 4 — Add Hidden Fields for Transaction Data**

To store Paystack transaction details in your database or include them in emails:

1. In your form editor, add a Hidden Field block.
2. Set its field name to one of the field names listed in the "Available Transaction Fields" section above (e.g. `paystack_reference`).
3. Repeat for each field you want to capture.
4. In your Insert/Update CCT action → Fields Map, select each hidden field from the dropdown and map it to the corresponding column in your database table.

The plugin automatically fills these hidden fields with real values after every successful payment. You do not need to type anything into them.

**Step 5 — Add Post-Payment Actions**

1. In the form editor, open the Actions panel.
2. Add actions: Insert/Update CCT Item, Send Email, Redirect to Page, Show Popup, etc.
3. Set the condition on each action to **GATEWAY.SUCCESS**. These fire after a confirmed Paystack payment.
4. Optionally add actions with condition **GATEWAY.FAILED** to handle declined or cancelled payments.

**Step 6 — Test**

1. Use Paystack test keys (pk_test_ / sk_test_).
2. Test card: 4084 0840 8408 4081, any future expiry, CVV 408, OTP 123456.
3. Submit your form — you will be redirected to the Paystack hosted checkout page.
4. Complete the test payment — Paystack redirects back to your site automatically.
5. Check that your CCT record was created, email was sent, and redirect worked.
6. When satisfied, replace test keys with live keys in Settings → JFB Paystack.

== Frequently Asked Questions ==

= Does this work with the free version of JetFormBuilder? =

Yes. The plugin works with both the free and pro versions of JetFormBuilder.

= Do I need to configure a webhook URL in my Paystack dashboard? =

No. The plugin uses a redirect-based flow — Paystack sends the user back to your page with the transaction reference in the URL, and the plugin verifies the transaction server-side on that page load. No webhook configuration is required.

= What if the user closes the browser after paying but before being redirected back? =

The payment is still complete on Paystack's side. The user can return to the same page URL (with the transaction reference in the URL) and the plugin will process the return correctly, as long as it has not already been processed. Consider also setting up Paystack webhooks as an additional fallback for critical use cases.

= Can the same transaction be processed twice? =

No. The plugin stores a deduplication transient after the first successful verification. Any subsequent attempt to process the same reference is rejected.

= Which currencies are supported? =

Any currency enabled on your Paystack account — NGN, GHS, ZAR, USD, KES, and more. Enter the ISO 4217 code in the Currency field on the settings page.

= Are my API keys stored securely? =

Your Secret Key is encrypted at rest using AES-256-CBC with your WordPress AUTH_KEY as the encryption key. Your Public Key is stored as plain text (it is not secret — it is used in the browser).

= What happens if the amount field is not found in the form submission? =

The plugin throws a descriptive error and does not initiate a Paystack transaction. Check that the Amount Field Name in Settings → JFB Paystack exactly matches the field name in your form.

= Can I use this to capture recurring / subscription payments? =

Not directly — this plugin handles one-time payments. The `paystack_auth_code` field can be used with Paystack's Charge API for subsequent charges, but that requires custom development.

== Changelog ==

= 2.1.1 =
* Fixed: amount field now read directly from saved settings + form request, bypassing unreliable JFB gateway meta merge.
* Fixed: Legacy_Request_Data object iteration (was causing fatal TypeError on form submit).
* Fixed: Removed do_action('jet-form-builder/gateways/before-send') call which caused fatal TypeError with JFB Form_Record module.
* Improved: Transaction data injection strategy for CCT and email macro resolution.

= 2.1.0 =
* Added: "Payment Settings" section in admin page for global Amount Field Name and Currency configuration.
* Added: JFB_Paystack_Tab_Handler extended to return price_field and currency for global settings merge.
* Added: Admin settings now collect and store price_field and currency alongside API keys.

= 2.0.8 =
* Changed: Author updated to Tobi John.
* Improved: Version bump for release.

= 2.0.0 =
* Complete architectural rewrite as a native JFB Base_Gateway extension.
* Replaced inline popup flow with Paystack hosted checkout redirect flow.
* Removed all legacy action/webhook/event classes.
* Added: Email auto-detection from form submission (no manual email field mapping).
* Added: AES-256-CBC encryption for Secret Key storage.
* Added: Deduplication via WordPress transients.
* Added: Amount integrity check against Paystack API verified amount.
* Added: Full Paystack transaction fields injected as form request data for macro resolution.
* Added: JFB Tab_Handler registration for gateway global settings.
* Fixed: Activation hook timing (class_exists unreliable — switched to active_plugins database check).
* Fixed: Admin notice false positive (switched to function_exists('jet_form_builder')).

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 2.0.0 =
Complete rewrite. Remove any old webhook configurations from your Paystack dashboard — they are no longer needed. Re-save your API keys after upgrading. Add a numeric amount field to your forms and configure its name in Settings → JFB Paystack.
