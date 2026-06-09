# LaWallet Discovery for WordPress

WordPress plugin that routes LNURL and NIP-05 `/.well-known/*` discovery from a WordPress site to a LaWallet API gateway.

## What It Does

- Adds a WordPress admin setup page under `Settings -> LaWallet Discovery`.
- Asks for the LaWallet API gateway endpoint, for example `https://lawallet.example.com`.
- Verifies the endpoint by checking `/.well-known/lawallet.json?probe=<unique-id>`.
- Generates a WordPress rewrite rule for `/.well-known/*`.
- Redirects discovery requests to the configured LaWallet gateway with HTTP `307`.

This lets a root domain keep running WordPress while wallet discovery is served by a separate LaWallet instance.

## Installation

1. Copy `lawallet-wordpress.php` into `wp-content/plugins/lawallet-wordpress/lawallet-wordpress.php`.
2. Activate **LaWallet Discovery** in WordPress.
3. Go to `Settings -> LaWallet Discovery`.
4. Enter the LaWallet API gateway endpoint.
5. Click **Save endpoint**, then **Verify LaWallet**.

## Generated Rewrite

Requests like:

```text
https://example.com/.well-known/lnurlp/alice
https://example.com/.well-known/nostr.json?name=alice
```

are redirected to:

```text
https://lawallet.example.com/.well-known/lnurlp/alice
https://lawallet.example.com/.well-known/nostr.json?name=alice
```

## Development

The plugin is intentionally dependency-free and uses WordPress core APIs only.
