# Social content data

Source data for the per-wallet social media campaign (image + caption).

## Files
- `woocommerce-facts.json` — 20 WooCommerce stats. Pair one fact per post with a
  wallet co-marketing card.

## How it fits together
- Card template: `landing/wallet-<wallet>.html` → rendered PNG (1200×630), e.g.
  "WooCommerce meets Blink". See `landing/SOCIAL-CARDS.md` for the design system
  and the headless-Chrome render command.
- A fact from `woocommerce-facts.json` becomes the post caption (or an on-image
  line), so each wallet post leads with a different WooCommerce proof point.

## Before publishing
The figures are marketing stats — verify and cite a source for each before
posting publicly (some are approximate or vary by source).
