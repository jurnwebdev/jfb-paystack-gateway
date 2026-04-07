=== JFB Paystack Gateway ===
Contributors: @johnero24↗
Tags: paystack, jetformbuilder, payment, gateway, nigeria
Requires at least: 6.0
Tested up to: 6.7
Stable tag: 1.0.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Adds a native Paystack Inline Checkout action to JetFormBuilder with server-side verification and post-payment action support.

== Description ==

**JFB Paystack Gateway** integrates [Paystack](https://paystack.com) payments directly into [JetFormBuilder](https://jetformbuilder.com) forms using Paystack's Inline Checkout popup — no page redirects required.

= Features =

* Paystack Inline Checkout popup triggered on form submission
* Server-side transaction verification before any actions execute
* Sandbox / live mode auto-detected from your API keys
* Post-payment JFB actions (Redirect, Send Email, Update CCT, etc.) run via JFB's native event system
* Paystack transaction data injected as form field values for use in macros
* Deduplication prevents double-processing of the same transaction
* Clean admin settings page under **Settings → JFB Paystack**

= Available Field Macros (post-payment) =

After a successful payment, the following field names are available in any JFB action that supports field macros:

* `paystack_reference` — Transaction reference
* `paystack_status` — Transaction status
* `paystack_amount` — Amount in local currency (e.g. NGN)
* `paystack_currency` — Currency code (e.g. NGN)
* `paystack_channel` — Payment channel (card, bank, etc.)
* `paystack_paid_at` — Timestamp of payment
* `paystack_auth_code` — Paystack authorization code
* `paystack_card_type` — Card type (Visa, Mastercard, etc.)
* `paystack_bank` — Issuing bank name
* `paystack_last4` — Last 4 digits of card
* `paystack_customer_email` — Customer email from Paystack

= Requirements =

* WordPress 6.0+
* PHP 7.4+
* [JetFormBuilder](https://jetformbuilder.com) plugin (free or pro)
* A Paystack account with API keys

== Installation ==

1. Upload the `jfb-paystack-gateway` folder to `/wp-content/plugins/`.
2. Activate the plugin via **Plugins → Installed Plugins**.
3. Go to **Settings → JFB Paystack** and enter your Paystack API keys.
4. Edit a JetFormBuilder form and add the **"Paystack Checkout Popup"** action.
5. In the action settings, map your form's email and amount fields.
6. Add any follow-up actions (e.g. Redirect to Page, Send Email) and set their condition to **"Paystack: On Payment Success"**.

== Frequently Asked Questions ==

= Does this work with Paystack test keys? =

Yes. If your public key starts with `pk_test_`, the plugin automatically operates in sandbox mode and a "Sandbox Mode" badge is shown on the settings page.

= Which currencies are supported? =

All currencies supported by your Paystack account (NGN, GHS, ZAR, etc.). Amounts are transmitted in their minor unit (kobo, pesewa, etc.) and converted automatically.

= Can I run other JFB actions after payment (email, redirect, CCT update)? =

Yes — this is the primary feature. Add any JFB action and set its condition to **"Paystack: On Payment Success"** or **"Paystack: On Payment Failed"**. All original form field values plus the Paystack transaction fields listed above will be available.

= Is double-processing prevented? =

Yes. The plugin stores a transient after the first successful verification and rejects duplicate AJAX calls for the same transaction reference.

= What happens if the user closes the popup without paying? =

The plugin fires the **"Paystack: On Payment Failed"** JFB event, allowing you to handle cancellations with a custom message or action.

== Screenshots ==

1. Settings page — enter your Paystack API keys
2. JFB action editor — add the "Paystack Checkout Popup" action
3. Frontend — the Paystack inline popup
4. Post-payment — JFB success message shown after verification

== Changelog ==

= 1.0.0 =
* Initial release.
* Paystack Inline Checkout popup action for JetFormBuilder.
* Server-side transaction verification.
* Custom JFB events: PAYSTACK.SUCCESS, PAYSTACK.FAILED.
* Injected transaction fields for downstream JFB action macros.
* Standalone admin settings page.

== Upgrade Notice ==

= 1.0.0 =
First release.
