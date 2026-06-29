# AGENTS.md

Guide for AI agents working in this repo.

## What this is
- **`lawallet-lightning-address/`** — the WordPress plugin **"LaWallet – Lightning
  Address"**: a WooCommerce gateway that accepts Bitcoin via a merchant Lightning
  Address (LNURL-pay + LUD-21), plus Lightning Address / NIP-05 discovery that
  redirects `/.well-known/` to a LaWallet gateway. Non-custodial, GPL-3.0.
- **`landing/`** — the marketing site (static HTML) for https://wordpress.lawallet.io,
  deployed on Vercel. Also holds the social-card tooling.
- **`bin/`**, **`e2e/`**, **`mock-lnurl/`**, **`docker-compose.yml`** — local dev,
  tests, and a mock LNURL server.

## Data model
- No custom tables. State lives in WooCommerce **order meta** (`_wcll_*`) and
  WordPress **options/transients** (`wcll_nwc_*`, `lawallet_*`). Full reference:
  **`lawallet-lightning-address/docs/DATA-MODEL.md`** — keep it in sync when you
  add or rename a persisted key.

## Plugin: build, version, release
- Version lives in `lawallet-lightning-address/lawallet-lightning-address.php`
  (`Version:` header + `WCLL_VERSION`) and `readme.txt` (`Stable tag` + changelog).
  Keep all in sync; bump for every release.
- Build distributables: **`bin/build-zip.sh`** → `lawallet-lightning-address.zip`
  (installable plugin; identical to the WordPress.org SVN trunk — the .org
  directory updates natively).
- Full release build: **`bin/build-release.sh`** → `dist/` with two artifacts:
  the installable `lawallet-lightning-address.zip` and an SVN payload
  `dist/svn/{assets,trunk,tags/<version>}` (current tag only).
  WordPress.org SVN publish flow: **`docs/RELEASE-WORDPRESS-ORG.md`**.
- Releases are GitHub Releases tagged `vX.Y.Z` with the zip attached (`gh release create`).
- Plugin Check (must pass clean): bring up Docker, then
  `docker compose run --rm --no-deps wpcli wp plugin check lawallet-lightning-address`.
  PHP lint: `make php-lint` (or `php -l` via the `php:8.2-cli` Docker image).
- Commit/push only when asked. End commit messages with the Co-Authored-By trailer.

## Landing & social images
- Social/OG cards are plain HTML rendered to PNG with headless Chrome. Design
  system, tokens, and render command: **`landing/SOCIAL-CARDS.md`**.
- The live OG/Twitter card is set by `og:image` / `twitter:image` in
  `landing/index.html` (currently `og-image-e.png`).

## Skill: `wallet-social` (social posts for wallets)
Creates a co-marketing **Twitter/X + Nostr** post pairing a WooCommerce fact with
a Lightning wallet ("Accept Bitcoin via your Lightning Address"). Full spec:
**`.claude/skills/wallet-social/SKILL.md`**. Data lives in `landing/social/`:

- `wallets.json` — wallet registry (name, logo, accent, **twitter**, **nostr**).
  Verify handles and fill npubs before publishing.
- `woocommerce-facts.json` — 20 WooCommerce stats to rotate through.
- `published.json` — log of generated posts (tracks which fact went to which wallet,
  and which wallet is due next).
- `wallet-card.template.html` + `make-card.sh` — render a card:
  `./landing/social/make-card.sh <slug>` → `landing/social/wallet-<slug>.png`.

Workflow: pick wallet (named, or the least-recently-posted from `published.json`) →
pick an unused WooCommerce fact → `make-card.sh <slug>` and view the PNG → write
the Twitter (≤280) + Nostr captions tagging the wallet's handle/npub and linking
wordpress.lawallet.io → append the entry to `published.json` → hand off (or publish
via a connected tool only with approval). One fact per wallet per post; keep claims
honest (free, open-source, non-custodial; funds go to the merchant's own address).

Wallets: Wallet of Satoshi, Primal, Strike, Alby, Blink, LNbits, LaWallet.
