# LaWallet for WordPress — landing page

A self-contained marketing page for the **LaWallet – Lightning Address** WordPress plugin.
Static HTML/CSS with a little vanilla JS — no build step, no dependencies.

## Structure

```
landing/
  index.html                  # the whole page (CSS inlined)
  assets/
    lawallet-wordmark.svg      # official LaWallet wordmark (from lawallet.io)
    lawallet-icon.svg          # LaWallet symbol mark (square)
    wordpress.svg              # W-in-circle mark
    bitcoin.svg                # Bitcoin ₿ mark
    wallets/                   # one square icon per supported wallet
      wallet-of-satoshi.svg primal.svg strike.svg alby.svg
      coincorner.svg spark.svg lnbits.svg
```

## Design

Mirrors the lawallet.io look and feel: dark `#0A0A0F` canvas, `#00836d` teal-green
primary, coral `#e95052`, brand yellow `#fdc800`, Inter + JetBrains Mono, animated
gradient blobs, grid + noise overlays, and glassy cards.

## Preview locally

```bash
python3 -m http.server 8099 --directory landing
# open http://localhost:8099
```

## Deploy

It's plain static files — drop the `landing/` contents on any static host
(GitHub Pages, Netlify, Vercel, Cloudflare Pages, or a `/` route on lawallet.io).

## Note on wallet icons

The wallet icons under `assets/wallets/` are clean, brand-colored **stylized
representations** (monogram chips), not the wallets' official logos. They indicate
Lightning Address compatibility. Swap in official brand assets if/when you have
permission to use them.
