#!/usr/bin/env bash
#
# PreToolUse(Bash) release guard for this plugin repo.
#
# Blocks `gh release create vX.Y.Z ...` unless the plugin version AND the
# WordPress readme (changelog + Stable tag) have already been updated to match
# the tag X.Y.Z. Any other Bash command is allowed through untouched.
#
# Reads the hook payload (JSON) on stdin and, when it denies, prints a
# PreToolUse permissionDecision=deny object on stdout.

input="$(cat)"
cmd="$(printf '%s' "$input" | jq -r '.tool_input.command // ""')"

# Only act on `gh release create`; allow everything else.
case "$cmd" in
  *"gh release create"*) : ;;
  *) exit 0 ;;
esac

# Pull the tag (first vX.Y.Z / X.Y.Z after `gh release create`), strip leading v.
ver="$(printf '%s' "$cmd" \
  | grep -oE 'gh release create[[:space:]]+v?[0-9]+\.[0-9]+\.[0-9]+' \
  | head -n1 \
  | grep -oE '[0-9]+\.[0-9]+\.[0-9]+' \
  | head -n1)"

# No identifiable semver tag -> don't block (unusual invocation).
[ -z "$ver" ] && exit 0

root="$(git rev-parse --show-toplevel 2>/dev/null || pwd)"
php="$root/lawallet-lightning-address/lawallet-lightning-address.php"
readme="$root/lawallet-lightning-address/readme.txt"
ver_re="$(printf '%s' "$ver" | sed 's/\./\\./g')"

missing=""
add_missing() { missing="${missing}"$'\n'"  - $1"; }

grep -Eq "Version:[[:space:]]*${ver_re}([^0-9]|$)" "$php" 2>/dev/null \
  || add_missing "plugin header \"Version: ${ver}\"  (lawallet-lightning-address/lawallet-lightning-address.php)"

grep -Eq "WCLL_VERSION'[[:space:]]*,[[:space:]]*'${ver_re}'" "$php" 2>/dev/null \
  || add_missing "WCLL_VERSION '${ver}'  (lawallet-lightning-address/lawallet-lightning-address.php)"

grep -Eq "Stable tag:[[:space:]]*${ver_re}([^0-9]|$)" "$readme" 2>/dev/null \
  || add_missing "\"Stable tag: ${ver}\"  (lawallet-lightning-address/readme.txt)"

grep -Eq "^=[[:space:]]*${ver_re}[[:space:]]*=" "$readme" 2>/dev/null \
  || add_missing "changelog heading \"= ${ver} =\"  (lawallet-lightning-address/readme.txt)"

# All good -> allow.
[ -z "$missing" ] && exit 0

reason="Release blocked (.claude/hooks/check-release-version.sh): v${ver} is missing required updates before \`gh release create\`:${missing}

Bump the plugin version and update the readme changelog + Stable tag to ${ver}, then retry."

jq -n --arg r "$reason" '{
  hookSpecificOutput: {
    hookEventName: "PreToolUse",
    permissionDecision: "deny",
    permissionDecisionReason: $r
  }
}'
exit 0
