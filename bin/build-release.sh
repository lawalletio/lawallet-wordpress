#!/usr/bin/env bash
#
# Build the two release artifacts for a plugin release.
#
#   dist/lawallet-lightning-address.zip   Installable plugin ZIP. Upload via
#                                         WordPress Admin -> Plugins -> Add New ->
#                                         Upload Plugin, or attach to a GitHub
#                                         Release.
#
#   dist/svn/                             WordPress.org SVN payload for the
#                                         *current* release only:
#                                           assets/         directory graphics
#                                           trunk/          current stable code
#                                           tags/<version>/ snapshot of trunk
#
# Version is read from the plugin header (the single source of truth) and must
# match the readme "Stable tag". See docs/RELEASE-WORDPRESS-ORG.md for the full
# publish flow.
set -euo pipefail
cd "$(dirname "$0")/.."

PLUGIN=lawallet-lightning-address
SRC=$PLUGIN
DIST=dist
ASSETS_SRC=.wordpress-org

# --- Version (single source of truth: the plugin header) ---------------------
VERSION=$(grep -iE '^[[:space:]]*\*[[:space:]]*Version:' "$SRC/$PLUGIN.php" \
  | head -1 | sed -E 's/.*Version:[[:space:]]*//' | tr -d '[:space:]')
[ -n "$VERSION" ] || { echo "ERROR: could not read Version from $SRC/$PLUGIN.php" >&2; exit 1; }

# readme "Stable tag" must point at this version, or .org serves the wrong tag.
STABLE=$(grep -iE '^Stable tag:' "$SRC/readme.txt" | sed -E 's/.*:[[:space:]]*//' | tr -d '[:space:]')
if [ "$STABLE" != "$VERSION" ]; then
  echo "ERROR: readme Stable tag ($STABLE) != plugin Version ($VERSION)." >&2
  echo "       Sync both before building a release." >&2
  exit 1
fi

echo "Building release: $PLUGIN $VERSION"
find "$SRC" -name '.DS_Store' -delete
rm -rf "$DIST"
mkdir -p "$DIST"

# --- 1) Installable plugin ZIP -----------------------------------------------
zip -rq "$DIST/$PLUGIN.zip" "$PLUGIN" -x '*.DS_Store'

# --- 2) WordPress.org SVN payload --------------------------------------------
SVN="$DIST/svn"
mkdir -p "$SVN/trunk" "$SVN/tags/$VERSION" "$SVN/assets"

# trunk = the plugin code (identical to the installable ZIP).
cp -R "$SRC/." "$SVN/trunk/"
find "$SVN/trunk" -name '.DS_Store' -delete

# tags/<version> = exact snapshot of trunk.
cp -R "$SVN/trunk/." "$SVN/tags/$VERSION/"

# assets = directory graphics only (NOT .wordpress-org/README.md).
for f in banner-1544x500.png banner-772x250.png icon-128x128.png icon-256x256.png icon.svg; do
  [ -f "$ASSETS_SRC/$f" ] && cp "$ASSETS_SRC/$f" "$SVN/assets/"
done
for s in "$ASSETS_SRC"/screenshot-*.png; do
  [ -e "$s" ] && cp "$s" "$SVN/assets/"
done

# --- Summary -----------------------------------------------------------------
echo
echo "Built into $DIST/ :"
echo "  $PLUGIN.zip                    installable plugin ($(find "$SRC" -type f | wc -l | tr -d ' ') files)"
echo "  svn/trunk/                     current stable code ($(find "$SVN/trunk" -type f | wc -l | tr -d ' ') files)"
echo "  svn/tags/$VERSION/             snapshot of trunk"
echo "  svn/assets/                    $(find "$SVN/assets" -type f | wc -l | tr -d ' ') directory graphics"
echo
echo "Next: copy svn/{assets,trunk,tags} into your SVN working copy and commit."
echo "See docs/RELEASE-WORDPRESS-ORG.md"
