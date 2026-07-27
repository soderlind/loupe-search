# Renamed from WP Loupe

Loupe Search is the successor to the **WP Loupe** plugin. Existing installs
upgrade seamlessly — nothing breaks on update. The old names below still work
but are deprecated and will be removed in a future major release.

## Summary of renames

| Area | Legacy (deprecated) | Current | Deprecated since |
| --- | --- | --- | --- |
| Index folder | `wp-content/wp-loupe-db` | `wp-content/loupe-search-db` | 1.1.0 |
| Developer filters | `wp_loupe_*` | `loupe_search_*` | 1.1.0 |
| REST namespace | `wp-loupe/v1` | `loupe-search/v1` | 1.1.0 |
| WP-CLI command | `wp wp-loupe` | `wp loupe-search` | 1.1.0 |
| Abilities | `wp-loupe/search`, `wp-loupe/get-post` | `loupe-search/search`, `loupe-search/get-post` | 1.2.0 |
| Ability category | `wp-loupe` | `loupe-search` | 1.2.0 |

## Details

### Index folder

The legacy `wp-content/wp-loupe-db` index folder is reused automatically if it
is present, so no reindex is required after upgrading. New installs create
`wp-content/loupe-search-db`.

### Developer filters

Filters use the `loupe_search_*` prefix (e.g. `loupe_search_db_path`,
`loupe_search_schema_post`). The old `wp_loupe_*` names still fire via
`apply_filters_deprecated()`. Update your `add_filter()` calls to the new names.

### REST namespace

The REST namespace is `loupe-search/v1` (e.g.
`/wp-json/loupe-search/v1/search`). The old `wp-loupe/v1` namespace is still
registered as a deprecated alias.

### WP-CLI command

The command is `wp loupe-search` (e.g. `wp loupe-search reindex`). The old
`wp wp-loupe` command still works as a deprecated alias.

### Abilities (WordPress Abilities API)

Abilities are registered as `loupe-search/search` and `loupe-search/get-post`
under the `loupe-search` category. The legacy `wp-loupe/search` and
`wp-loupe/get-post` abilities (category `wp-loupe`) remain registered as
deprecated aliases sharing the same callbacks.

See the [Changelog](../CHANGELOG.md) for the full history.
