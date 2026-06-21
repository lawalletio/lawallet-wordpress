=== Accept Bitcoin with your Lightning Address ===
Contributors: magollo
Tags: lightning, bitcoin, payments, lnurl, nostr
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 0.4.0
License: GPL-3.0-or-later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Connect payments with most popular Lightning wallets without registration or credentials.

== Description ==

**Accept Bitcoin with your Lightning Address** combines two Lightning features in one plugin:

1. **WooCommerce Lightning payments.** A checkout gateway that pays to your merchant Lightning
   Address using LNURL-pay, verifies settlement server-side with LUD-21 or NIP-57 zap receipts,
   optionally detects payment faster in the browser via NIP-57 zap receipts and WebLN, and converts
   fiat order totals to sats using Yadio exchange rates. For addresses that support neither LUD-21
   nor NIP-57, an optional NWC (Nostr Wallet Connect, NIP-47) proxy wallet can take the payment and
   forward it to your Lightning Address.
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

For stores **not** priced in BTC, the plugin converts the order total to satoshis using a Bitcoin
exchange rate from [Yadio](https://yadio.io), a free public rate API. It sends a single server-side
GET request to `https://api.yadio.io/rate/{CURRENCY}/BTC` whenever a fresh rate is needed, where
`{CURRENCY}` is your WooCommerce store currency code (for example `USD` or `ARS`). Results are
cached for 5 minutes. Only that three-letter currency code is sent - no customer, order, account,
or site data ever leaves your server.

This request is optional. Stores priced in BTC/SAT need no conversion, and any store can avoid
Yadio entirely by setting a fixed "manual sats-per-unit" rate in the gateway settings.

Yadio is a free, public, no-account API. Because the request is made from your server, Yadio receives
your store server's IP address (which, per its privacy policy, it logs to monitor for abuse) and the
three-letter currency code - nothing else. This service is provided by Yadio; see the Yadio
[terms of service](https://yadio.io/terms.html) and [privacy policy](https://yadio.io/privacy.html).
Yadio's role as the rate provider for the LaWallet ecosystem is also disclosed in the
[LaWallet privacy policy](https://lawallet.io/privacy).

= Nostr relays (optional) =

If your wallet provider supports NIP-57 zap receipts, the customer's browser subscribes to the
Nostr relays advertised by the wallet to detect payment faster. Only the public invoice/zap
request data is exchanged. Relays are operated by third parties chosen by your wallet provider.

= NWC proxy wallet (optional) =

If you enable the NWC (Nostr Wallet Connect, NIP-47) proxy wallet, the plugin connects to the Nostr
relays in the wallet connection string to invoice that wallet, confirm the payment, and forward the
funds to your Lightning Address. Encrypted requests/responses (make/lookup/pay invoice) are exchanged
with the wallet through those relays. The relays are third parties carried in the connection string;
the wallet connection secret is stored on your server and is never sent to the browser. This feature
is off by default and only used for Lightning Addresses that support neither LUD-21 nor NIP-57.

In Disposable mode the plugin obtains a throwaway wallet connection from an lncurl service (a single
HTTP request returns a connection string; default https://lncurl.lol, configurable to any lncurl
instance you trust) and requests a replacement when a wallet dies. Only the request to mint a wallet
is sent to that service; the wallet you receive is custodial at whichever provider the lncurl service
is backed by, so use it only for funds in transit. In Permanent mode no provisioning service is
contacted — you supply the connection string yourself.

== Installation ==

1. Upload the plugin ZIP from **Plugins -> Add New -> Upload Plugin**, or unzip it into
   `wp-content/plugins/`.
2. Activate **Accept Bitcoin with your Lightning Address**.
3. For payments: go to **WooCommerce -> Settings -> Payments -> Lightning (LaWallet)** and set
   your merchant Lightning Address. Saving creates a test invoice and requires a LUD-21 verify
   URL, so you know settlement verification works before going live.
4. For discovery: go to **Settings -> LaWallet**, enter your LaWallet gateway endpoint and
   connect. The plugin verifies the endpoint before enabling the redirects.

== Frequently Asked Questions ==

= Do I need WooCommerce? =

Only for payments. Lightning Address / NIP-05 discovery works without WooCommerce.

= Does the plugin custody funds? =

No. With LUD-21 or NIP-57 the payment goes directly to your own Lightning Address; the plugin only
requests invoices and verifies settlement. If you enable the optional NWC proxy wallet, the payment
lands in a disposable wallet you control and is then forwarded to your Lightning Address — the
plugin never holds your keys or funds.

= My wallet does not support LUD-21, can I still use this? =

Yes. If your Lightning Address supports NIP-57 zap receipts, settlement is confirmed from the signed
receipt instead. If it supports neither LUD-21 nor NIP-57, you can enable the optional NWC (Nostr
Wallet Connect) proxy wallet: the plugin invoices an NWC wallet, confirms the payment over that
connection, and forwards the funds to your Lightning Address (keeping a small reserve for routing
fees). The proxy wallet can be a fixed connection you provide (Permanent mode) or one the plugin
creates on demand from an lncurl service and replaces when it dies (Disposable mode). Orders are
only ever marked paid when a payment is actually confirmed.

= Which currencies are supported? =

Any WooCommerce currency supported by Yadio rates, plus a manual sats-per-unit rate as a
fallback. BTC-denominated stores need no conversion.

== Changelog ==

= 0.4.0 =
* NWC proxy wallets can now be managed automatically. In the new Disposable mode the plugin creates a throwaway NWC wallet on demand from an lncurl service (default https://lncurl.lol), reuses it across orders, and provisions a replacement when it dies (optional, behind a toggle).
* Permanent mode keeps the previous behaviour: a fixed connection string you provide that is never auto-replaced. Existing setups upgrade to Permanent mode automatically.
* Show the proxy wallet balance and mode on the payment gateway settings page.
* Per-order settlement and forwarding always target the exact wallet an order was invoiced on, even after the active disposable wallet has rotated.
* Connection secrets are kept server-side in dedicated storage and are never written to the settings form, order data, or the browser.
* Update the bundled Spanish (es_AR, es_ES) translations for the new strings.

= 0.3.0 =
* Add an optional NWC (Nostr Wallet Connect, NIP-47) proxy wallet as a third settlement method for Lightning Addresses that support neither LUD-21 verification nor NIP-57 zap receipts.
* When enabled, the plugin invoices a disposable NWC wallet, confirms the payment over the wallet connection (and via the cron re-check), marks the order paid, then forwards the funds to your merchant Lightning Address keeping a routing reserve of 1% or 10 sats, whichever is larger.
* The browser subscribes to NIP-47 wallet notifications to detect payment faster; the connection secret is kept on the server and never exposed to customers.
* Forwarding is idempotent and retried by cron, with an attempt cap and an admin note when manual intervention is needed, so a settled order is never left un-paid.
* Hide the payment gateway when a configured Lightning Address has no usable settlement method (no LUD-21, no NIP-57, and no NWC proxy).
* Update the bundled Spanish (es_AR, es_ES) translations for the new strings.

= 0.2.2 =
* Update the bundled Spanish (es_AR, es_ES) translations to cover the new gateway, discovery, and NIP-57 strings.

= 0.2.1 =
* Document the Yadio exchange-rate service with links to its Terms of Service and Privacy Policy.

= 0.2.0 =
* Add live LUD-16, LUD-21 and NIP-57 checks beside the merchant Lightning Address field, each with a status icon.
* Accept Lightning Addresses that do not support LUD-21: settlement is confirmed from signed NIP-57 zap receipts (at checkout and via the cron re-check), with BIP-340 signature verification so only genuine receipts can settle an order.
* Show the connected gateway name and domain on the discovery settings page, and warn when the gateway domain does not match your site.
* Refresh the discovery settings copy and rename the Settings menu entry to "Lightning by LaWallet".

= 0.1.7 =
* Refresh the plugin directory icon (new lightning mark with a subtle Bitcoin watermark).

= 0.1.6 =
* Rename the plugin to "Accept Bitcoin with your Lightning Address" and update the short description.

= 0.1.5 =
* Set the Contributors field to the plugin owner's WordPress.org account (magollo).
* Expand the Yadio external-service disclosure: clarify the optional, currency-code-only request, the manual-rate opt-out, and the available Yadio documentation.

= 0.1.4 =
* Add a GitHub-based self-updater so installs distributed outside the WordPress.org directory can update from the project's GitHub Releases. Self-disables when the plugin is hosted on WordPress.org.

= 0.1.3 =
* Point the Plugin URI to the project site (wordpress.lawallet.io).

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
