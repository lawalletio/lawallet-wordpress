---
name: wallet-social
description: Create a co-marketing social post (Twitter/X + Nostr) that pairs a WooCommerce fact with a Lightning wallet, promoting "Accept Bitcoin via your Lightning Address" with the LaWallet WordPress plugin. Generates the card image and both captions, tracks each wallet's accounts, and avoids repeating a fact for the same wallet. Use when asked to make/draft a social post for a wallet (Wallet of Satoshi, Primal, Strike, Alby, Blink, LNbits, LaWallet), a campaign batch, or "the next wallet post".
---

# wallet-social

Produce one ready-to-publish social post for a Lightning wallet, co-branded with
WooCommerce, for the **LaWallet – Lightning Address** WordPress plugin
(https://wordpress.lawallet.io). Output = a 1200×630 image + a Twitter/X caption
+ a Nostr caption. It does **not** auto-publish — it prepares content for the
human (or a connected posting tool) to send.

## Data (all under `landing/social/`)
- `wallets.json` — registry: `slug, name, tagline, logo, accent, twitter, nostr`.
- `woocommerce-facts.json` — 20 WooCommerce stats (`id, title, text`).
- `published.json` — `{ "posts": [...] }` log; one entry per generated post.
- `wallet-card.template.html` + `make-card.sh` — the card generator.

## Steps

1. **Pick the wallet.** Use the one the user names. If they say "next", read
   `published.json` and choose the wallet with the oldest (or no) post.

2. **Pick a fresh fact.** From `woocommerce-facts.json`, choose a fact whose `id`
   is **not** already paired with this wallet in `published.json`. Prefer facts
   that fit a merchant/store angle (e.g. market share, GMV, store count).

3. **Optional — find a timely angle.** If web tools are available, check the
   wallet's recent X/Nostr activity (search `@<twitter>` / the wallet name) to
   tailor the hook or timing. Never invent quotes; only use it to choose the angle.

4. **Generate the image:**
   ```bash
   ./landing/social/make-card.sh <slug>      # -> landing/social/wallet-<slug>.png
   ```
   Then view the PNG to confirm it rendered (logo, accent, no clipped text).

5. **Write the captions.** Keep them honest and non-custodial-accurate.
   - **Twitter/X (≤ 280 chars):** lead with the WooCommerce fact, then the value
     prop, tag the wallet's `@handle`, link, 1–2 hashtags.
   - **Nostr (no limit):** same message, can tag the wallet's `nostr` npub if set;
     attach the image.
   Template:
   > {WooCommerce fact}. Now those merchants can accept Bitcoin ⚡
   >
   > Get paid to your @{handle} Lightning Address — no registration, non-custodial.
   >
   > WooCommerce meets {Name} → https://wordpress.lawallet.io
   >
   > #Bitcoin #Lightning #WooCommerce

6. **Record it.** Append to `published.json` `posts`:
   `{ "wallet": "<slug>", "fact_id": <id>, "date": "<YYYY-MM-DD>", "twitter": "...", "nostr": "...", "image": "landing/social/wallet-<slug>.png" }`
   (Pass the date in; do not call Date.now()/`date` assumptions blindly — use the
   real current date from context.)

7. **Deliver.** Show the image path + both captions. If a posting MCP tool is
   connected and the user approves, publish; otherwise hand off for manual posting.

## Rules
- Verify the `twitter` handle and fill `nostr` in `wallets.json` before publishing
  (handles change; some are marked to verify).
- WooCommerce stats are marketing figures — keep them as written and let the user
  source-check before posting.
- One fact per wallet per post; rotate facts so the campaign stays fresh.
- Stay accurate: the plugin is free, open-source, non-custodial; payments go to
  the merchant's own Lightning Address.

See `AGENTS.md` and `landing/SOCIAL-CARDS.md` for the design system.
