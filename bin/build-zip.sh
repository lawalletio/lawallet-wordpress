#!/usr/bin/env bash
#
# Build the plugin distributables.
#
#   lawallet-lightning-address.zip        Full build (GitHub / self-hosted).
#                                         Includes the GitHub self-updater.
#   lawallet-lightning-address-wporg.zip  WordPress.org upload build.
#                                         Excludes the self-updater (the .org
#                                         directory handles updates natively and
#                                         disallows bundled self-updaters).
#
set -euo pipefail
cd "$(dirname "$0")/.."

PLUGIN=lawallet-lightning-address
find "$PLUGIN" -name '.DS_Store' -delete

rm -f "$PLUGIN.zip" "$PLUGIN-wporg.zip"

# Full build (with updater)
zip -rq "$PLUGIN.zip" "$PLUGIN" -x '*.DS_Store'

# WordPress.org build (no updater)
zip -rq "$PLUGIN-wporg.zip" "$PLUGIN" -x '*.DS_Store' '*class-wcll-updater.php'

echo "Built:"
echo "  $PLUGIN.zip        ($(unzip -l "$PLUGIN.zip" | awk 'END{print $1}') files) — GitHub / self-hosted, with updater"
echo "  $PLUGIN-wporg.zip  ($(unzip -l "$PLUGIN-wporg.zip" | awk 'END{print $1}') files) — WordPress.org upload, no self-updater"
