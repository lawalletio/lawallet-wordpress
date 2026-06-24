=== Accept Bitcoin with your Lightning Address ===
Contributors: magollo
Tags: lightning, bitcoin, payments, lnurl, nostr
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 0.6.6
License: GPL-3.0-or-later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Connect payments with most popular Lightning wallets without registration or credentials.

== Description ==

**Accept Bitcoin with your Lightning Address** combines two Lightning features in one plugin:

1. **WooCommerce Lightning payments.** A checkout gateway with three receiver modes you choose
   between: pay directly to your merchant Lightning Address (LNURL-pay, verified server-side with
   LUD-21 or NIP-57 zap receipts); pay through a managed NWC proxy wallet that forwards to your
   Lightning Address (for addresses that confirm neither LUD-21 nor NIP-57); or receive straight into
   your own Nostr Wallet Connect (NWC, NIP-47) wallet. It optionally detects payment faster in the
   browser via NIP-57 zap receipts and WebLN, and converts fiat order totals to sats using Yadio
   exchange rates.
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

= NWC wallet (optional) =

If you choose an NWC (Nostr Wallet Connect, NIP-47) receiver mode, the plugin connects to the Nostr
relays in the wallet connection string to invoice that wallet and confirm the payment. In "NWC" mode
the payment stays in your own wallet (you paste its connection string in the Receiver section). In
"Lightning Address via NWC Proxy" mode a managed proxy wallet receives the payment and the plugin
forwards it to your Lightning Address minus a small routing reserve. Encrypted requests/responses
(make/lookup/pay invoice) are exchanged with the wallet through those relays. The relays are third
parties carried in the connection string; the wallet connection secret is stored on your server and is
never sent to the browser.

For the proxy wallet, in Disposable mode the plugin obtains a throwaway wallet connection from an lncurl service (a single
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

No. In Lightning Address mode the payment goes directly to your own address (confirmed via LUD-21 or
NIP-57); the plugin only requests invoices and verifies settlement. In NWC mode the payment lands in
your own wallet and stays there. In NWC Proxy mode it lands in a managed wallet and is forwarded on to
your Lightning Address. Either way the plugin never holds your keys or funds.

= My wallet does not support LUD-21, can I still use this? =

Yes. If your Lightning Address supports NIP-57 zap receipts, settlement is confirmed from the signed
receipt instead. If it supports neither LUD-21 nor NIP-57, choose the "Lightning Address via NWC
Proxy" receiver mode: the plugin invoices a managed NWC wallet, confirms the payment over that
connection, and forwards it on to your Lightning Address (keeping a small reserve for routing fees).
The proxy wallet can be a fixed connection you provide (Permanent mode) or one the plugin creates on
demand from an lncurl service and replaces when it dies (Disposable mode). Alternatively, choose "NWC"
mode to receive straight into your own NWC wallet. Orders are only ever marked paid when a payment is
actually confirmed.

= Which currencies are supported? =

Any WooCommerce currency supported by Yadio rates, plus a manual sats-per-unit rate as a
fallback. BTC-denominated stores need no conversion.

== Changelog ==

= 0.6.6 =
* Moved the "Create disposable wallet" button to sit directly below the "Disposable wallet service (lncurl)" field.

= 0.6.5 =
* Reset the NWC Wallet Receive/Withdraw section whenever you toggle it, so reopening always starts fresh (no stale invoice or half-filled form).

= 0.6.4 =
* The NWC Wallet "Receive" flow now detects the incoming payment (via wallet notifications, with a polling fallback), shows a "Payment received" message, refreshes the balance, and closes the invoice section automatically.

= 0.6.3 =
* Moved "Show connection string" to a smaller button in the top-right of the NWC wallet panel; the secret warning now appears with the revealed string.

= 0.6.2 =
* Add a "Create disposable wallet" button to the NWC Wallet tab to provision a fresh proxy wallet on demand (archiving the current one).
* Show a live countdown of when a disposable proxy wallet will run dry, based on its balance and the ~1 sat/hour lncurl upkeep.

= 0.6.1 =
* Removed the "Auto-replace dead wallet" setting. Disposable proxy wallets are now always replaced automatically when they die — that is the point of disposable mode.

= 0.6.0 =
* New Receiver section with a single mode selector: **Lightning Address**, **Lightning Address via NWC Proxy**, or **NWC**. The page adapts to the chosen mode.
* NWC mode lets you receive into your own NWC wallet by pasting a connection string, with a live balance shown in the Receiver section.
* Lightning Address via NWC Proxy keeps the managed proxy wallet (Disposable or Permanent) on its own NWC Wallet tab, and forwards each payment to your Lightning Address.
* When a Lightning Address can confirm neither LUD-21 nor NIP-57, an alert prompts you to switch to NWC Proxy mode.
* Existing installs migrate automatically (the old proxy becomes NWC Proxy mode).

= 0.5.0 =
* Choose your settlement method explicitly: **Lightning Address** (paid directly, confirmed via LUD-21 or NIP-57) or **NWC wallet** (paid into a Nostr Wallet Connect wallet you control).
* NWC wallet payments now stay in the wallet by default. The previous "proxy" behaviour is kept as an optional **Forward to Lightning Address** toggle (with the same routing reserve and idempotent forwarding).
* Removed the implicit, automatic LA→NWC fallback and the duplicate "Use NWC Proxy" checkbox — the method is now a single, clear choice.
* Existing installs are migrated automatically: the old NWC proxy maps to NWC mode with forwarding enabled; everything else maps to Lightning Address mode.

= 0.4.13 =
* Add a "Show connection string" button to the proxy wallet panel to reveal the current NWC connection string (disposable or permanent) for importing the wallet into another app. The string is fetched on demand for the administrator only, never rendered into the page, and carries a "keep it private" warning.

= 0.4.12 =
* Fix proxy forwards that settled but were left stuck on "pending" (and could be sent a second time on retry) when the wallet's pay response was lost in transit. The forward invoice is now stored before paying and reconciled via lookup_invoice, making forwarding idempotent.
* Show the routing fee paid alongside the reserve kept in the forward order note.

= 0.4.11 =
* Add a "Show" button to each proxy transaction that opens a detail view with the received (proxy) and sent (forward) invoice details: amounts, payment hash, preimages, and invoices.

= 0.4.10 =
* Attach a "Proxied from Woocommerce #<order>" comment (LUD-12) to forwarded NWC proxy payments, when the destination Lightning Address accepts comments.

= 0.4.9 =
* When the NWC proxy wallet is enabled, route every payment through it — even when the Lightning Address supports LUD-21 or NIP-57. Turn it off to settle directly through the address again.

= 0.4.8 =
* Add a proxy transactions list on the NWC tab: the latest 5 payments received through the proxy with their forwarding status to your Lightning Address and a payment proof (preimage), plus a "Show all" button that opens a paginated modal.

= 0.4.7 =
* Add a "Regenerate NWC connection" button on the NWC proxy wallet tab that appears when you change the disposable wallet service (lncurl) URL, provisioning a fresh wallet from the new service.

= 0.4.6 =
* Move the NIP-57 relay URLs setting to the Advanced tab.
* Add a "Use NWC Proxy" toggle on the Lightning Address tab that controls the same setting as the NWC proxy wallet tab.

= 0.4.5 =
* Fix the LUD-21 status check beside the Lightning Address field: it now confirms the generated invoice actually returns a `verify` URL, instead of reporting LUD-21 as supported for any address that can create an invoice.

= 0.4.4 =
* Show the compatible-wallets list directly on the Lightning Address tab (above the connection status) instead of behind a button and modal.

= 0.4.3 =
* Link each wallet in the compatible-wallets picker to its website, and add a link to lightningaddress.com for the full list of Lightning Address wallets and services.

= 0.4.2 =
* Add a "compatible wallets" picker on the Lightning Address tab: a modal listing popular wallets that give customers a Lightning Address, with logos.
* Add a proxy wallet panel on the NWC tab: a live balance (updated from NWC notifications), a Receive control that generates an invoice, and a Withdraw control that pays a Lightning Address or BOLT11 invoice. All wallet operations run server-side; the connection secret is never exposed to the browser.

= 0.4.1 =
* Reorganise the payment gateway settings into tabs (Checkout, Lightning Address, NWC proxy wallet, Pricing & rates, Advanced) for a clearer setup, with the NWC fields revealing based on the selected mode.

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
