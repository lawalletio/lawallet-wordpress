# Releasing to WordPress.org (SVN) + installable ZIP

This documents how we ship a plugin release. It produces **two artifacts**:

1. **`dist/lawallet-lightning-address.zip`** — the installable plugin ZIP. Drop it
   into WordPress (Admin → Plugins → Add New → Upload Plugin) or attach it to a
   GitHub Release. Includes the GitHub self-updater.
2. **`dist/svn/`** — the WordPress.org SVN payload for the **current release only**
   (`assets/`, `trunk/`, `tags/<version>/`). Excludes the self-updater, which the
   .org directory disallows.

Everything here lives in the repo, **not** inside the shipped `lawallet-lightning-address/`
plugin folder.

---

## Background: how WordPress.org SVN is laid out

WordPress.org hosts each plugin in a Subversion repo with three top-level folders
([readme.txt reference](https://developer.wordpress.org/plugins/wordpress-org/how-your-readme-txt-works/),
[SVN guide](https://developer.wordpress.org/plugins/wordpress-org/how-to-use-subversion/)):

| Folder | What it holds |
|---|---|
| `trunk/` | The current, in-development copy of the plugin. |
| `tags/<version>/` | An immutable snapshot per released version (e.g. `tags/0.2.2/`). |
| `assets/` | Directory-page graphics — banners, icons, screenshots. **Not** shipped in the plugin ZIP. |

What the directory actually serves to users is controlled by the **`Stable tag`** in
`trunk/readme.txt`. With `Stable tag: 0.2.2`, .org installs `tags/0.2.2/`. So the
release ritual is: update `trunk/`, copy it to `tags/<version>/`, point `Stable tag`
at it, and commit.

`assets/` filenames are fixed by convention: `banner-1544x500.png`,
`banner-772x250.png`, `icon-256x256.png`, `icon-128x128.png`, `icon.svg`, and
`screenshot-1.png`, `screenshot-2.png`, … matching the `== Screenshots ==` list in
`readme.txt`. Our source graphics live in `.wordpress-org/` (see that folder's
`README.md` for how they're regenerated).

### The self-updater exclusion

The plugin bundles a GitHub self-updater (`includes/class-wcll-updater.php`) for
self-hosted / GitHub installs. WordPress.org updates plugins natively and Plugin
Check flags bundled updaters, so the `.org` build **omits** that file. The main
plugin file loads it defensively:

```php
if ( file_exists( WCLL_PLUGIN_DIR . 'includes/class-wcll-updater.php' ) ) {
    require_once WCLL_PLUGIN_DIR . 'includes/class-wcll-updater.php';
}
```

…so the updater is present in the installable ZIP and absent from the SVN payload,
and both run cleanly.

---

## Step 1 — Bump the version

Keep these in sync (every release; enforced by `.claude/hooks/check-release-version.sh`):

- `lawallet-lightning-address/lawallet-lightning-address.php` — `Version:` header **and** `WCLL_VERSION`
- `lawallet-lightning-address/readme.txt` — `Stable tag` **and** a `== Changelog ==` entry

## Step 2 — Build the artifacts

```bash
./bin/build-release.sh
```

The script reads the version from the plugin header (refusing to build if
`readme.txt`'s `Stable tag` doesn't match), then writes:

```
dist/
├── lawallet-lightning-address.zip   # installable ZIP (with updater)
└── svn/
    ├── assets/                       # banners + icons (no README.md)
    ├── trunk/                        # current code (no updater)
    └── tags/<version>/               # snapshot of trunk
```

`dist/` is git-ignored and rebuilt from scratch each run.

> Pre-submit checks worth running first: `make php-lint`, and Plugin Check against
> the no-updater build — see `AGENTS.md`.

## Step 3 — Publish the installable ZIP

Attach `dist/lawallet-lightning-address.zip` to the GitHub Release for the tag, or
upload it directly in WordPress admin.

## Step 4 — Commit the SVN payload to WordPress.org

You need an `svn` client (`brew install svn`) and a checkout of the plugin repo:

```bash
# One-time checkout (somewhere outside this repo)
svn co https://plugins.svn.wordpress.org/lawallet-lightning-address
```

Copy the freshly built payload into the working copy, then add + commit:

```bash
WC=/path/to/lawallet-lightning-address        # your SVN working copy
DIST=/path/to/this-repo/dist/svn

cp -R "$DIST/assets/."          "$WC/assets/"
rm -rf "$WC/trunk"; cp -R "$DIST/trunk"  "$WC/trunk"
cp -R "$DIST/tags/."            "$WC/tags/"   # adds tags/<version>/ only

cd "$WC"
svn add --force assets trunk tags     # stage new files (A = added)
svn status                            # review; svn rm any intentionally deleted files
svn ci -m "Release <version>" --username magollo
```

Notes:
- The build emits **only the current tag** under `tags/`. Older tags already live
  in the remote SVN repo and must stay untouched — never delete them.
- If you removed files between releases, `svn status` shows them as missing (`!`);
  `svn rm` them so the commit reflects the deletion.
- First asset upload can take a few minutes to appear on the plugin page.

---

## Quick reference

| Concern | Where |
|---|---|
| Plugin version | `lawallet-lightning-address/lawallet-lightning-address.php` (`Version:` + `WCLL_VERSION`) |
| Stable tag + changelog | `lawallet-lightning-address/readme.txt` |
| Directory graphics (source) | `.wordpress-org/` |
| Build script | `bin/build-release.sh` → `dist/` |
| ZIP-only builds (full + wporg) | `bin/build-zip.sh` |
| SVN repo | `https://plugins.svn.wordpress.org/lawallet-lightning-address` |
| .org contributor | `magollo` |
