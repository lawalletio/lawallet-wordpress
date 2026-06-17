=== LaWallet - Lightning Address ===
Contributors: agustinkassis
Tags: lightning, bitcoin, payments, lnurl, nostr
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 0.1.2
License: GPL-3.0-or-later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Accept Bitcoin Lightning payments in WooCommerce and route Lightning Address/NIP-05 discovery from your domain to a LaWallet gateway.

== Description ==

**LaWallet - Lightning Address** combines two Lightning features in one plugin:

1. **WooCommerce Lightning payments.** A checkout gateway that pays to your merchant Lightning
   Address using LNURL-pay, verifies settlement server-side with LUD-21, optionally detects payment
   faster in the browser via NIP-57 zap receipts and WebLN, and converts fiat order totals to sats
   using Yadio exchange rates.
2. **Lightning Address / NIP-05 discovery.** Keeps your root domain on WordPress while a separate
   [LaWallet](https://lawallet.io) instance serves wallet discovery: the plugin registers rewrite
   rules for the relevant `/.well-known/` routes and redirects them (HTTP 307) to the LaWallet
   gateway you configure.

= Payments: how an order is processed =

* At checkout the plugin resolves your merchant Lightning Address (LNURL-pay) and requests an
  invoice for the order total.
* The customer pays by scanning a QR code, opening their wallet via a `lightning:` link, or using
  WebLN.
* Your server confirms settlement with the LUD-21 `verify` URL before the order is marked paid.
  Zap receipts and WebLN only speed up detection in the browser - they never settle an order by
  themselves.
* Pending Lightning orders are re-checked by WordPress cron; expired unpaid invoices cancel the
  order automatically.
* For non-BTC store currencies, order totals are converted using Yadio BTC exchange rates, or a
  manual sats-per-unit rate you set yourself.

= Discovery: which requests are redirected =

Only the wallet discovery routes under `/.well-known/` are touched (for example
`/.well-known/lnurlp/...` and `/.well-known/nostr.json`). They are redirected with HTTP 307
(method-preserving) to the LaWallet gateway endpoint you configure under
**Settings -> LaWallet**. The endpoint is verified before discovery is enabled.

== External services ==

This plugin talks to the following external services. No data is sent anywhere until you
configure the related feature.

= Your merchant Lightning Address endpoint (LNURL-pay) =

When a customer checks out, the plugin contacts the LNURL-pay endpoint of the Lightning Address
you configured (the domain after the `@` of your own address) to request an invoice for the order
amount, and later calls its LUD-21 `verify` URL to confirm settlement. Order amounts and invoice
identifiers are sent. The terms of that service are the ones of your own wallet provider.

= LaWallet gateway =

If you enable discovery, requests to your site's `/.well-known/` wallet discovery routes are
redirected to the LaWallet gateway endpoint you configure, and the plugin probes
`/.well-known/lawallet.json` on that endpoint to verify it. See the
[LaWallet terms](https://lawallet.io/terms) and [privacy policy](https://lawallet.io/privacy).

= Yadio exchange rates =

For stores not priced in BTC, the plugin fetches BTC exchange rates from
[Yadio](https://yadio.io) by requesting `https://api.yadio.io/rate/{CURRENCY}/BTC` server-side
whenever a fresh rate is needed (results are cached for 5 minutes). Only the store currency code
is sent - no customer or order data. Yadio is a free public rate API that does not publish a
formal terms of service or privacy policy; see the [Yadio website](https://yadio.io) and its
[API documentation](https://yadio.io/api.html).

= Nostr relays (optional) =

If your wallet provider supports NIP-57 zap receipts, the customer's browser subscribes to the
Nostr relays advertised by the wallet to detect payment faster. Only the public invoice/zap
request data is exchanged. Relays are operated by third parties chosen by your wallet provider.

== Installation ==

1. Upload the plugin ZIP from **Plugins -> Add New -> Upload Plugin**, or unzip it into
   `wp-content/plugins/`.
2. Activate **LaWallet - Lightning Address**.
3. For payments: go to **WooCommerce -> Settings -> Payments -> Lightning (LaWallet)** and set
   your merchant Lightning Address. Saving creates a test invoice and requires a LUD-21 verify
   URL, so you know settlement verification works before going live.
4. For discovery: go to **Settings -> LaWallet**, enter your LaWallet gateway endpoint and
   connect. The plugin verifies the endpoint before enabling the redirects.

== Frequently Asked Questions ==

= Do I need WooCommerce? =

Only for payments. Lightning Address / NIP-05 discovery works without WooCommerce.

= Does the plugin custody funds? =

No. Payments go directly to your own Lightning Address. The plugin only requests invoices and
verifies settlement via LUD-21.

= My wallet does not support LUD-21, can I still use this? =

No. Server-side settlement verification (LUD-21 `verify`) is required so orders are only marked
paid when the invoice is actually settled.

= Which currencies are supported? =

Any WooCommerce currency supported by Yadio rates, plus a manual sats-per-unit rate as a
fallback. BTC-denominated stores need no conversion.

== Changelog ==

= 0.1.2 =
* Enqueue the settings-page CSS and JavaScript instead of printing them inline.
* Expand the Yadio external service documentation and load translations on the init hook.
* Set the plugin Contributors to agustinkassis.

= 0.1.1 =
* Improve Lightning payment page rate display with locked Yadio conversion snapshots and BTC/SAT display options.
* Polish checkout/payment controls, including WebLN loading states, paid-state disabling, and fallback wallet visibility.
* Add a Bitcoin icon to the WooCommerce LaWallet payment method option.

= 0.1.0 =
* Initial release: WooCommerce Lightning gateway (LNURL-pay + LUD-21 verification, WebLN and
  NIP-57 zap receipt detection, Yadio fiat conversion) and Lightning Address / NIP-05 discovery
  redirects to a LaWallet gateway.
