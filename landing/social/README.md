# Social content

Tooling and data for the per-wallet social campaign (Twitter/X + Nostr): a
WooCommerce fact paired with a Lightning wallet, promoting "Accept Bitcoin via
your Lightning Address" with the LaWallet WordPress plugin.

Driven by the **`wallet-social`** skill — see `.claude/skills/wallet-social/SKILL.md`
and `AGENTS.md`.

## Files
- `wallets.json` — wallet registry: `slug, name, tagline, logo, accent, twitter, nostr`.
  Verify Twitter handles and fill the Nostr npubs before publishing.
- `woocommerce-facts.json` — 20 WooCommerce stats to rotate through (one per post).
- `published.json` — log of generated/published posts (which fact → which wallet, when).
- `wallet-card.template.html` — the 1200×630 card (`{{NAME}}`, `{{LOGO}}`, `{{ACCENT}}`).
- `make-card.sh` — renders a card from the registry: `./make-card.sh <slug>`.

## Generate a card
```bash
./landing/social/make-card.sh blink     # -> landing/social/wallet-blink.png
```

## Caption shape
> {WooCommerce fact}. Now those merchants can accept Bitcoin ⚡
> Get paid to your @{handle} Lightning Address — no registration, non-custodial.
> WooCommerce meets {Name} → https://wordpress.lawallet.io
> #Bitcoin #Lightning #WooCommerce

Then append the post to `published.json`.

## Before publishing
Verify the wallet handle/npub and source-check the WooCommerce stat. The plugin is
free, open-source, and non-custodial — payments go to the merchant's own address.
