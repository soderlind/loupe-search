# Loupe Search documentation

Developer documentation for [Loupe Search](../README.md). For installation and
settings, start with the [main README](../README.md).

## Build a search UI

- **[Search API](search-api.md)** — The REST API behind your own search UI:
  quick start, pagination, filtering, sorting, facets, highlighting, and geo
  search, plus a full endpoint and error reference.

## Customize indexing and results

- **[Filters](filters.md)** — All eight `loupe_search_*` filters: what gets
  indexed, field weights, schema, sortability, and results per page. Includes
  recipes and when a reindex is needed.

## Migrating

- **[Renamed from WP Loupe](renamed-from-wp-loupe.md)** — What changed when the
  plugin was renamed, which old names still work, and what was deliberately left
  alone.
- **[MCP Server → Abilities API](migration-mcp-to-abilities.md)** — Moving off
  the experimental MCP server removed in 0.8.5.

## Decisions

Architecture decision records live in [`adr/`](adr/):

- **[0001 — Defer plugin auto-updates to WordPress core](adr/0001-defer-auto-updates-to-core.md)**

## Elsewhere

- [Changelog](../CHANGELOG.md)
- [Plugin readme (WordPress.org)](../readme.txt)
