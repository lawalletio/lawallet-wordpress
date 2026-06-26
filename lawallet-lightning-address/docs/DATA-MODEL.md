# Data model

Every persisted value this plugin reads or writes. The plugin creates **no custom
database tables** — it stores everything in WooCommerce **order meta** and
WordPress **options/transients**.

Settlement modes referenced below (the `settlement_method` setting):

- **`lightning_address`** — settle directly on the merchant's Lightning Address,
  verified via LUD-21 (`lud21`) or NIP-57 zap receipts (`nip57`).
- **`nwc_proxy`** — receive on a managed NWC wallet, then sweep the balance to the
  merchant's Lightning Address.
- **`nwc`** — receive on the merchant's own (terminal) NWC wallet and keep it.

## Order meta (`_wcll_*`)

Written when the invoice is issued (`WCLL_Gateway::issue_invoice_for_order()`) and
updated as the payment settles.

| Key | Purpose | Modes |
| --- | --- | --- |
| `_wcll_status` | Lifecycle: `pending` → `paid` / `expired`. | all |
| `_wcll_invoice` | BOLT11 invoice shown to the customer. | all |
| `_wcll_verify_url` | LUD-21 verify URL (empty otherwise). | `lightning_address` |
| `_wcll_amount_msat` | Invoice amount, millisatoshis. | all |
| `_wcll_rate` | Rate snapshot from `WCLL_Rates` (source, fiat/sat, buffer…). | all |
| `_wcll_expires_at` | Invoice expiry, Unix seconds. | all |
| `_wcll_settlement_method` | Resolved method: `lud21` / `nip57` / `nwc`. | all |
| `_wcll_lightning_address` | Destination address (forward target, or final receiver). | all |
| `_wcll_lnurl_pay_url` | LNURL-pay callback URL. | `lightning_address` |
| `_wcll_zap_request` | JSON NIP-57 zap request event. | `lightning_address` (nip57) |
| `_wcll_nostr_pubkey` | Zap-receipt author pubkey the frontend watches. | `lightning_address` (nip57) |
| `_wcll_nostr_relays` | Relay URLs to watch for the zap receipt. | `lightning_address` (nip57) |
| `_wcll_payment_hash` | Invoice payment hash (for `lookup_invoice`). | `nwc_proxy`, `nwc` |
| `_wcll_nwc_wallet_pubkey` | Wallet the order was invoiced on. | `nwc_proxy`, `nwc` |
| `_wcll_nwc_client_pubkey` | Client pubkey of that connection (public). | `nwc_proxy`, `nwc` |
| `_wcll_nwc_relays` | Relay URLs of that connection. | `nwc_proxy`, `nwc` |
| `_wcll_nwc_forward` | `yes` = sweep to the address, `no` = keep (terminal). | `nwc_proxy`, `nwc` |
| `_wcll_preimage` | Settlement proof, set once paid. | all |

### Legacy order meta (read-only)

Written by the pre-sweep, per-order forwarding code. No longer written; still read
so historical orders display correctly in the admin ledger.

| Key | Purpose |
| --- | --- |
| `_wcll_nwc_forwarded` | Old per-order forward result (`yes` / `failed`). |
| `_wcll_nwc_forward_fees` | Old per-order routing fee, msat. |

## Options & transients

| Key | Type | Autoload | Purpose |
| --- | --- | --- | --- |
| `woocommerce_wcll_gateway_settings` | option | yes | All gateway settings (the WooCommerce settings form). |
| `wcll_nwc_active_connection` | option | no | Current disposable proxy wallet (URI **with secret**, pubkey, relays). |
| `wcll_nwc_connection_archive` | option | no | Retired disposable wallets, keyed by pubkey (max 20) for settlement lookup. |
| `wcll_nwc_permanent_connection` | option | no | Permanent proxy NWC connection (URI **with secret**). |
| `wcll_nwc_receiver_connection` | option | no | Terminal-mode merchant wallet (URI **with secret**). |
| `wcll_nwc_mint_window` | option | no | Disposable-mint rate window (start + count); caps mints/hour. |
| `wcll_nwc_sweep_inflight` | option | no | Per-wallet in-flight sweep invoice (idempotency). |
| `wcll_nwc_sweeps` | option | no | Rolling payout log (≤50): wallet, address, amount, fee, preimage, time. |
| `wcll_nwc_admin_notices` | option | no | Queued one-shot admin notices. |
| `wcll_nwc_mint_lock` | transient | — | Lock during disposable-wallet provisioning (~1 min). |
| `wcll_nwc_balance_cache` | transient | — | Cached proxy wallet balance (120 s). |
| `wcll_nwc_receiver_balance_cache` | transient | — | Cached terminal wallet balance (120 s). |
| `wcll_nwc_sweep_<pubkey>` | transient | — | Per-wallet sweep lock (~2 min). |
| `wcll_nwc_fwd_<order_id>` | transient | — | (legacy) per-order forward lock. |
| `wcll_activation_redirect` | transient | — | One-shot onboarding redirect flag (~1 min). |
| `lawallet_discovery_enabled` | option | yes | Lightning-Address / NIP-05 discovery on/off. |
| `lawallet_gateway_endpoint` | option | yes | LaWallet discovery gateway endpoint. |
| `lawallet_gateway_server_settings` | option | yes | Server details returned by the discovery endpoint. |
| `lawallet_gateway_verified_at` | option | yes | Last successful discovery check (ISO 8601). |
| `lawallet_gateway_last_error` | option | yes | Last discovery error message. |

> **Security:** the `*_connection` options hold `nostr+walletconnect://…` URIs that
> include the wallet **secret** (full spend access). They are stored with
> `autoload = no`, are never written to order meta, and are never sent to the
> browser — only the public wallet/client pubkeys and relay URLs are.

## Cron

A single recurring event, `wcll_check_pending_payments` (every minute), polls
pending orders for settlement, runs the proxy balance sweep, and keeps a live
disposable wallet ready.
