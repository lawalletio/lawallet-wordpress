#!/usr/bin/env bash
#
# Build the installable plugin ZIP.
#
#   lawallet-lightning-address.zip   Installable plugin (GitHub Release / manual
#                                    upload). Identical to the WordPress.org SVN
#                                    trunk; the .org directory updates natively.
#
set -euo pipefail
cd "$(dirname "$0")/.."

PLUGIN=lawallet-lightning-address
find "$PLUGIN" -name '.DS_Store' -delete

rm -f "$PLUGIN.zip"
zip -rq "$PLUGIN.zip" "$PLUGIN" -x '*.DS_Store'

echo "Built:"
echo "  $PLUGIN.zip  ($(unzip -l "$PLUGIN.zip" | awk 'END{print $1}') files)"
