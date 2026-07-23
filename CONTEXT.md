# WP Loupe

WP Loupe is a WordPress plugin that maintains a dedicated, typo-tolerant search
index (backed by Loupe/SQLite) and powers WordPress search, a REST API, and
WordPress Abilities. This context covers the plugin's **admin UI**.

## Language

### Admin UI

**Settings screen**:
The single admin page under Settings → WP Loupe, organized into three tabs —
Dashboard, Fields, and Search Behavior. Hosts both the Dashboard and the
configuration tabs.
_Avoid_: options page, admin page

**Search Behavior**:
The configuration tab that tunes how matching works: tokenization, prefix
search, and typo tolerance.
_Avoid_: Advanced (the old tab name), Search Engine

**Dashboard**:
The first tab of the Settings screen. A read-mostly surface for observing index
health and running a reindex. Contains the index health table, the reindex
panel, and the system status strip. It is not a top-level admin menu.
_Avoid_: overview, home, landing page

**Index health**:
The current queryability of a post type's index, expressed as a status:
_Ready_, _Needs reindex_, or _Not indexed_. Derived from `is_index_ready()`.
_Avoid_: index state, index status (as separate terms)

**Reindex panel**:
The Dashboard control that starts a batched reindex and shows live progress
(current post type + counts) by polling the reindex-batch REST endpoint.
_Avoid_: rebuild, sync

**System status**:
The Dashboard strip reporting environment readiness (SQLite version, required
PHP extensions, database path) from the utilities requirements check.
_Avoid_: diagnostics, health check (reserved: those name other things)

**Field settings**:
The configuration tab where each post type's indexed fields are given a weight
and marked filterable and/or sortable.
_Avoid_: field config, schema editor

**Published count**:
The number of published posts of a post type (`wp_count_posts()->publish`),
shown on the Dashboard as the reindex denominator. It is a target, not a
precise count of documents currently in the index.
_Avoid_: doc count, indexed count
