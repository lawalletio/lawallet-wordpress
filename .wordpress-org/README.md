# WordPress.org directory assets

These images are for the **WordPress.org plugin directory**, not the plugin ZIP.
They live in the plugin's SVN repo under `/assets/` (a sibling of `/trunk/` and
`/tags/`), e.g.:

```
svn co https://plugins.svn.wordpress.org/lawallet-lightning-address
# copy these files into  lawallet-lightning-address/assets/
svn add assets/* ; svn ci -m "Add icon + banner"
```

(If you use the `10up/action-wordpress-plugin-deploy` GitHub Action, it reads
this `.wordpress-org/` folder automatically.)

## Files

| File | Purpose |
|---|---|
| `icon-256x256.png` | Plugin icon (retina) |
| `icon-128x128.png` | Plugin icon |
| `banner-1544x500.png` | Header banner (retina) |
| `banner-772x250.png` | Header banner |

Screenshots (optional) go here too as `screenshot-1.png`, `screenshot-2.png`, …
matching the `== Screenshots ==` list in `readme.txt`.

## Regenerate

Source templates live in `landing/` and reuse `landing/assets/` for branding,
so the look matches the social cards (see `landing/SOCIAL-CARDS.md`).

```bash
cd landing
CHROME="/Applications/Google Chrome.app/Contents/MacOS/Google Chrome"
# icon (256, then downscale to 128)
"$CHROME" --headless --disable-gpu --hide-scrollbars --allow-file-access-from-files \
  --window-size=256,256 --screenshot="../.wordpress-org/icon-256x256.png" "file://$PWD/wporg-icon.html"
# banner (1544x500, then downscale to 772x250)
"$CHROME" --headless --disable-gpu --hide-scrollbars --allow-file-access-from-files \
  --window-size=1544,500 --screenshot="../.wordpress-org/banner-1544x500.png" "file://$PWD/wporg-banner.html"
cd ..
magick .wordpress-org/icon-256x256.png    -resize 128x128 .wordpress-org/icon-128x128.png
magick .wordpress-org/banner-1544x500.png -resize 772x250 .wordpress-org/banner-772x250.png
```
