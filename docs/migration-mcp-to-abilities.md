# Migration Guide: MCP Server → WordPress Abilities API

**Applies to:** Loupe Search 0.8.5 or later. (The plugin was named *WP Loupe*
when this change shipped; see [Renamed from WP Loupe](renamed-from-wp-loupe.md).)

In 0.8.5 the experimental **MCP (Model Context Protocol) server**, its
**token service**, and the **WP-CLI token commands** were removed in favor of
the native [WordPress Abilities API](https://developer.wordpress.org/apis/abilities-api/)
(WordPress 6.9+).

This is a **breaking change** for any integration that talked to the MCP
endpoints or issued MCP tokens. This guide explains what was removed and how to
move your integration to the Abilities API.

**What you will do:** pick a replacement path (abilities or plain REST), swap
your call sites, delete token plumbing, then verify. Most integrations only
touch a handful of lines — the hard part is deciding which path you need.

## TL;DR

- The MCP server, OAuth token endpoint, `/.well-known/*` discovery, and the
  `wp-loupe-mcp/v1/*` REST namespace no longer exist.
- The `wp wp-loupe mcp issue-token` WP-CLI command was removed.
- Search and single-post retrieval are now exposed as two abilities:
  `loupe-search/search` and `loupe-search/get-post` (legacy `wp-loupe/*`
  aliases still work).
- No tokens, scopes, or rate-limit configuration are required anymore.
- If you only need a plain HTTP/JSON API, use the existing
  [REST search API](search-api.md) instead.

## Which path do I need?

Pick one before you change any code:

| Your integration | Use | Why |
|------------------|-----|-----|
| Runs **inside WordPress** (plugin, theme, WP-CLI) | Abilities via `wp_get_ability()` | No HTTP hop, no auth to configure |
| Is an **AI agent / automation tool** that discovers capabilities | Abilities registry | Agents enumerate and call abilities natively |
| Is an **external app** that just wants search results over HTTP | [REST search API](search-api.md) | Richer: filters, facets, geo, highlighting |

The abilities and the REST API read the same index, so mixing them is fine.

## Migration checklist

- [ ] Site is on WordPress 6.9+ and Loupe Search 0.8.5+
- [ ] Every `wp-loupe-mcp/v1/*` URL removed from your code
- [ ] `/.well-known/mcp.json` and OAuth discovery calls removed
- [ ] `client_credentials` requests and `Authorization: Bearer` headers removed
- [ ] Stored MCP tokens/secrets deleted from config and secret stores
- [ ] `wp wp-loupe mcp issue-token` removed from scripts and cron
- [ ] Call sites swapped to abilities or the REST search API
- [ ] Verified end-to-end (see [Verify the migration](#verify-the-migration))

## What was removed

| Removed in 0.8.5 | Replacement |
|------------------|-------------|
| MCP server (enable toggle in Settings → WP Loupe → MCP) | WordPress Abilities API (always available on WP 6.9+) |
| Discovery: `/.well-known/mcp.json`, `/.well-known/oauth-protected-resource` | Abilities registry (`wp_get_abilities()`) + REST |
| REST namespace `/wp-json/wp-loupe-mcp/v1/*` | Ability REST routes (`show_in_rest`) and [`/wp-json/loupe-search/v1/search`](search-api.md) |
| OAuth token endpoint `POST /wp-json/wp-loupe-mcp/v1/oauth/token` | Not needed — abilities run under standard WordPress auth/capabilities |
| Token management UI, scopes (`search.read`, `post.read`, `schema.read`, `health.read`, `commands.read`), TTLs, revoke-all | Removed — no tokens to manage |
| Commands: `searchPosts`, `getPost`, `getSchema`, `listCommands`, `healthCheck` | `loupe-search/search`, `loupe-search/get-post` (see below) |
| WP-CLI: `wp wp-loupe mcp issue-token` | Removed |
| MCP rate-limiting settings | Removed |

## The replacement abilities

Loupe Search registers two abilities under the `loupe-search` category. Both are
publicly accessible (`permission_callback` returns true) and exposed via REST
(`show_in_rest`).

> As of 1.2.0 the primary names are `loupe-search/*` (category `loupe-search`).
> The legacy `wp-loupe/search` and `wp-loupe/get-post` abilities (category
> `wp-loupe`) remain registered as deprecated aliases and will be removed in a
> future major release.

### `loupe-search/search`

Typo-tolerant full-text search across indexed post types. Supports phrase
matching (`"..."`), exclusion (`-term`), and OR operators.

Input:

| Field | Type | Notes |
|-------|------|-------|
| `query` | string | **Required.** The search query. |
| `post_types` | string[] | Optional. Defaults to all indexed post types. |
| `per_page` | integer | Optional. Default `10`, min `1`, max `100`. |
| `page` | integer | Optional. Default `1`. |

Output: `{ hits[], total_hits, page, total_pages }`, where each hit contains
`id`, `title`, `url`, `excerpt`, `post_type`, and `post_date`.

### `loupe-search/get-post`

Retrieve a single published post by ID.

Input: `{ id }` (integer, required, min `1`).

Output: `{ id, title, content, excerpt, url, post_type, post_date, author }`.

## How to migrate

### 1. Command mapping

| Old MCP command | New approach |
|-----------------|--------------|
| `searchPosts` | `loupe-search/search` ability, or `POST /wp-json/loupe-search/v1/search` |
| `getPost` | `loupe-search/get-post` ability |
| `getSchema` | No direct equivalent; field configuration lives in Settings → Loupe Search |
| `listCommands` | Discover via the Abilities registry (`wp_get_abilities()`) |
| `healthCheck` | Removed |

### 2. Drop tokens and OAuth

Remove any `client_credentials` token requests and `Authorization: Bearer`
headers pointed at the MCP endpoints. Abilities run under standard WordPress
authentication and capabilities — there are no MCP tokens to issue, rotate, or
revoke.

A typical before/after, for PHP that used to fetch a token then search:

```php
// BEFORE — two HTTP calls plus token bookkeeping.
$token = json_decode( wp_remote_retrieve_body( wp_remote_post(
	rest_url( 'wp-loupe-mcp/v1/oauth/token' ),
	[ 'body' => [ 'grant_type' => 'client_credentials', 'scope' => 'search.read' ] ]
) ), true )['access_token'];

$hits = json_decode( wp_remote_retrieve_body( wp_remote_post(
	rest_url( 'wp-loupe-mcp/v1/commands/searchPosts' ),
	[
		'headers' => [ 'Authorization' => "Bearer {$token}" ],
		'body'    => wp_json_encode( [ 'query' => 'hello world' ] ),
	]
) ), true );
```

```php
// AFTER — one in-process call, no tokens.
$hits = wp_get_ability( 'loupe-search/search' )->execute( [
	'query' => 'hello world',
] );
```

Delete any stored client IDs/secrets from your config and secret store as well —
they no longer authenticate anything.

### 3. Call an ability in PHP

```php
$result = wp_get_ability( 'loupe-search/search' )->execute( [
	'query'    => 'hello world',
	'per_page' => 10,
] );
```

### 4. Or keep using plain HTTP/JSON

If your integration just needs an HTTP search endpoint (no AI agent), the REST
search API is unchanged and remains the simplest option:

```bash
curl -s -X POST \
  -H 'Content-Type: application/json' \
  -d '{"q":"hello world","page":{"number":1,"size":10}}' \
  https://example.com/wp-json/loupe-search/v1/search
```

> **Watch the parameter names.** The abilities take `query` / `per_page` / `page`,
> but the REST endpoint takes `q` and a `page` **object** (`{ number, size }`).
> Sending `query` to REST returns `400 wp_loupe_missing_query`.

See **[docs/search-api.md](search-api.md)** for the full request/response
schema, filters, facets, geo search, and a Gutenberg block example.

### 5. Remove WP-CLI token scripts

Any automation that called `wp wp-loupe mcp issue-token` should be deleted; the
command no longer exists and tokens are not required.

## Verify the migration

**1. The abilities are registered.** From WP-CLI:

```bash
wp eval 'print_r( array_keys( wp_get_abilities() ) );'
```

You should see `loupe-search/search` and `loupe-search/get-post` (plus the
deprecated `wp-loupe/*` aliases).

**2. A search returns hits.**

```bash
wp eval '$r = wp_get_ability( "loupe-search/search" )->execute( [ "query" => "the" ] ); echo $r["total_hits"], PHP_EOL;'
```

A `total_hits` of `0` usually means the index is empty — run
`wp loupe-search reindex`.

**3. Nothing still points at MCP.** Grep your integration for leftovers:

```bash
grep -rn 'wp-loupe-mcp\|issue-token\|well-known/mcp' .
```

This should return nothing.

## Troubleshooting

| Symptom | Likely cause | Fix |
|---------|--------------|-----|
| `wp_get_ability()` is undefined | WordPress older than 6.9, or Abilities API unavailable | Upgrade to WordPress 6.9+ |
| `wp_get_ability()` returns `null` | Ability name typo, or called before `abilities_api_init` | Use the exact name; call after the registration hook |
| `404` on `/wp-json/wp-loupe-mcp/v1/*` | Expected — the namespace was removed | Switch to abilities or the REST search API |
| `400 wp_loupe_missing_query` from REST | Sent `query` instead of `q` | REST uses `q`; abilities use `query` |
| `400 wp_loupe_no_indexed_post_types` | No post type has a ready index | Run `wp loupe-search reindex` |
| Deprecation notice naming `wp-loupe/search` | Still calling the legacy alias | Switch to `loupe-search/search` |

## Notes

- The Abilities API requires **WordPress 6.9+**, which is also the plugin's new
  minimum. Ensure your site is on 6.9 or later before upgrading.
- Removing MCP reduces the plugin's attack surface: no public OAuth endpoint,
  no long-lived tokens, and no custom `.well-known` routes.
