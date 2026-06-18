#!/usr/bin/env bash
#
# Render a per-wallet co-marketing card (1200x630) from wallet-card.template.html
# using the wallet's entry in wallets.json.
#
#   ./make-card.sh <wallet-slug>      e.g. ./make-card.sh blink
#
# Output: wallet-<slug>.html + wallet-<slug>.png (in this folder).
#
set -euo pipefail
cd "$(dirname "$0")"   # landing/social/

slug="${1:?usage: make-card.sh <wallet-slug>}"

IFS=$'\t' read -r name logo accent <<EOF
$(python3 -c "import json; w=[x for x in json.load(open('wallets.json'))['wallets'] if x['slug']=='$slug']; print('\t'.join([w[0]['name'], w[0]['logo'], w[0]['accent']]) if w else '')")
EOF
[ -n "${name:-}" ] || { echo "Unknown wallet slug: $slug" >&2; exit 1; }

out_html="wallet-$slug.html"
out_png="wallet-$slug.png"
sed -e "s|{{NAME}}|$name|g" -e "s|{{LOGO}}|$logo|g" -e "s|{{ACCENT}}|$accent|g" \
  wallet-card.template.html > "$out_html"

CHROME="${CHROME:-/Applications/Google Chrome.app/Contents/MacOS/Google Chrome}"
"$CHROME" --headless --disable-gpu --hide-scrollbars --allow-file-access-from-files \
  --force-device-scale-factor=1 --window-size=1200,630 --default-background-color=00000000 \
  --screenshot="$PWD/$out_png" "file://$PWD/$out_html" >/dev/null 2>&1

echo "Wrote social/$out_html and social/$out_png"
