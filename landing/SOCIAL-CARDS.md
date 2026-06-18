# Social cards & banners — design system

How to make share images, OG cards, and banners for **LaWallet × WordPress** that
match the landing's first-section look (light purple, watermarked, gradient
headline). Everything is plain HTML rendered to PNG with headless Chrome — no
build step, no design tool.

The live Open Graph / Twitter card is set in `index.html`
(`og:image` / `twitter:image`). Point it at whichever PNG you want.

---

## The vibe (design tokens)

| Token | Value |
|---|---|
| Background | `radial-gradient(120% 105% at 50% -12%, #ffffff 0%, #f4eefc 50%, #ece4f7 100%)` |
| Watermark | `assets/wp-woo-pattern.svg`, `background-size: 156px`, `opacity: .4–.5` |
| Vignette (keeps center legible) | `radial-gradient(56% 60% at 50% 46%, rgba(255,255,255,.92), transparent)` |
| Accent gradient (headline) | `linear-gradient(100deg, #3858E9, #873EFF 52%, #00a98c)` |
| Ink / heading | `#0b1020` · secondary ink `#1c1a17` |
| Muted text | `#6b6577` |
| WooCommerce purple | `#7F54B3` · deep `#6d28d9` · pill bg `#f4ecfd` / border `#e1cffb` |
| Bitcoin orange | `#f7931a` |
| Font | `'Inter', 'Helvetica Neue', Arial, sans-serif` |
| Canvas | `1200 × 630` (OG/Twitter ratio 1.91:1) |

**Gradient text gotcha:** `-webkit-background-clip: text` clips descenders
(g, y, p) when line-height is tight. Always give gradient headings
`line-height: ~1.25` and a little `padding-bottom` (~6–8px).

---

## Reusable pieces

- **Logo lockup** — WordPress + WooCommerce in a white rounded card:
  `assets/wordpress.png` (height ~36–52) + 1px divider + `assets/woocommerce-2015.svg` (height ~30–44).
- **Wallet chips** — white rounded tiles (so dark app icons read on light):
  `width/height ~80–96; border-radius 20; background:#fff; padding 14–16;`
  `img { object-fit: contain; max-width/height: 100% }`. Sources in `assets/wallets/`
  (`wallet-of-satoshi.png`, `primal.svg`, `strike.png`, `alby.svg`, `coincorner.svg`,
  `blink.png`, `lnbits.svg`) + `assets/lawallet-icon.svg`.
- **WooCommerce pill** — `#f4ecfd` bg, `#e1cffb` border, `#6d28d9` text, label "WooCommerce Plugin".
- **Bitcoin accent** — `assets/bitcoin.svg` (~60–70px) in a corner.
- **LaWallet logo** — `assets/lawallet-logo.svg` (dark wordmark, trimmed), bottom-left, height ~38.
- **Open-source badge** — inline GitHub mark (`fill:#1c1a17`) + "100% open source", bottom-right.

Standard footer: **LaWallet logo bottom-left, "100% open source" bottom-right.**

---

## The variations (kept in the repo)

| Source | Image | What it is |
|---|---|---|
| `og.html`   | `og-image.png`   | A — centered logo lockup, "Accept Bitcoin via Lightning Address" |
| `og-b.html` | `og-image-b.png` | B — text left, WP+Woo card right |
| `og-c.html` | `og-image-c.png` | C — bold "No registration required" statement |
| `og-d.html` | `og-image-d.png` | D — all wallet logos grouped in a row |
| `og-e.html` | `og-image-e.png` | **E (live)** — wallet logos scattered around the message + "100% open source" |

---

## Generate / regenerate

Each `og-*.html` is self-contained and references `assets/` relatively. Render
to PNG with headless Chrome (macOS path shown):

```bash
cd landing
CHROME="/Applications/Google Chrome.app/Contents/MacOS/Google Chrome"
"$CHROME" --headless --disable-gpu --hide-scrollbars --allow-file-access-from-files \
  --force-device-scale-factor=1 --window-size=1200,630 \
  --default-background-color=00000000 \
  --screenshot="$PWD/og-image-e.png" "file://$PWD/og-e.html"
```

- Swap `og-e.html` / `og-image-e.png` for the file you're rendering.
- `--force-device-scale-factor=2` → a crisp 2× (2400×1260) export.
- Verify before shipping: open the PNG, check no gradient descenders are clipped
  and nothing overlaps the bottom-left logo / bottom-right badge.

### Make a new card
1. Copy the closest `og-*.html` to `og-<name>.html`.
2. Keep `.wm` + `.vig` + the token colors; change the copy/layout.
3. Keep the footer (LaWallet bottom-left, "100% open source" bottom-right).
4. Render to `og-image-<name>.png` with the command above.

### Banners / other sizes
Same recipe, change `--window-size` **and** the `html,body` width/height to match:

| Use | Size |
|---|---|
| OG / Twitter card | 1200 × 630 |
| GitHub social preview | 1280 × 640 |
| X / Twitter header | 1500 × 500 |
| Wide hero banner | 1600 × 500 |

For short banners, drop to a single row: logo lockup · headline · wallet chips ·
footer. Keep the watermark + gradient so it still reads as the same family.

---

## Set the live card

In `index.html`, the `og:image`, `og:image:secure_url`, and `twitter:image`
point to the live PNG (absolute `https://wordpress.lawallet.io/...`). Change the
filename to switch. After deploy, refresh caches via the Facebook Sharing
Debugger and the X Card Validator.
