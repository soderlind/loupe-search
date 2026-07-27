# Migration Guide: MCP Server → WordPress Abilities API

**Applies to:** upgrading to WP Loupe 0.8.5 or later.

In 0.8.5 the experimental **MCP (Model Context Protocol) server**, its
**token service**, and the **WP-CLI token commands** were removed in favor of
the native [WordPress Abilities API](https://developer.wordpress.org/)
(WordPress 6.9+).

This is a **breaking change** for any integration that talked to the MCP
endpoints or issued MCP tokens. This guide explains what was removed and how to
move your integration to the Abilities API.

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

## What was removed

| Removed in 0.8.5 | Replacement |
|------------------|-------------|
| MCP server (enable toggle in Settings → WP Loupe → MCP) | WordPress Abilities API (always available on WP 6.9+) |
| Discovery: `/.well-known/mcp.json`, `/.well-known/oauth-protected-resource` | Abilities registry (`wp_get_abilities()`) + REST |
| REST namespace `/wp-json/wp-loupe-mcp/v1/*` | Ability REST routes (`show_in_rest`) and [`/wp-json/wp-loupe/v1/search`](search-api.md) |
| OAuth token endpoint `POST /wp-json/wp-loupe-mcp/v1/oauth/token` | Not needed — abilities run under standard WordPress auth/capabilities |
| Token management UI, scopes (`search.read`, `post.read`, `schema.read`, `health.read`, `commands.read`), TTLs, revoke-all | Removed — no tokens to manage |
| Commands: `searchPosts`, `getPost`, `getSchema`, `listCommands`, `healthCheck` | `wp-loupe/search`, `wp-loupe/get-post` (see below) |
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
| `getSchema` | No direct equivalent; field configuration lives in Settings → WP Loupe |
| `listCommands` | Discover via the Abilities registry (`wp_get_abilities()`) |
| `healthCheck` | Removed |

### 2. Drop tokens and OAuth

Remove any `client_credentials` token requests and `Authorization: Bearer`
headers pointed at the MCP endpoints. Abilities run under standard WordPress
authentication and capabilities — there are no MCP tokens to issue, rotate, or
revoke.

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
  -d '{"query":"hello world","per_page":10}' \
  https://example.com/wp-json/loupe-search/v1/search
```

See **[docs/search-api.md](search-api.md)** for the full request/response
schema, filters, facets, geo search, and a Gutenberg block example.

### 5. Remove WP-CLI token scripts

Any automation that called `wp wp-loupe mcp issue-token` should be deleted; the
command no longer exists and tokens are not required.

## Notes

- The Abilities API requires **WordPress 6.9+**, which is also WP Loupe's new
  minimum. Ensure your site is on 6.9 or later before upgrading.
- Removing MCP reduces the plugin's attack surface: no public OAuth endpoint,
  no long-lived tokens, and no custom `.well-known` routes.
