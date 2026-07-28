# Renamed from WP Loupe

Loupe Search is the successor to the **WP Loupe** plugin. Existing installs
upgrade seamlessly — nothing breaks on update. The old names below still work
but are deprecated and will be removed in a future major release.

## Do I need to do anything?

**On upgrade: no.** Every old name is still wired up, and the existing index is
reused, so sites and integrations keep working untouched.

**Before the next major release: yes.** Update any code that uses the old
names. The quickest way to find it:

- Set `WP_DEBUG` to `true` and exercise your integration. Deprecated filters
  raise standard WordPress deprecation notices naming their replacement, and
  `wp wp-loupe` prints a CLI warning.
- Grep your own code for the legacy names:

  ```sh
  grep -rn "wp_loupe_\|wp-loupe/v1\|wp wp-loupe\|wp-loupe-db" .
  ```

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

New installs create `wp-content/loupe-search-db`.

On upgrade, the legacy `wp-content/wp-loupe-db` folder keeps being used **only
while the new folder does not exist** — so no reindex is required. If both
folders exist, `loupe-search-db` wins. To move over deliberately, delete or
rename the legacy folder and run `wp loupe-search reindex`.

The path is filterable via `loupe_search_db_path` (legacy: `wp_loupe_db_path`).

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

## What was *not* renamed

Some identifiers deliberately keep the `wp_loupe_` prefix. These are not
oversights, and there is nothing to migrate:

| Identifier | Why it stayed |
| --- | --- |
| Options `wp_loupe_custom_post_types`, `wp_loupe_fields`, `wp_loupe_advanced` | Renaming would orphan existing settings on every install |
| REST error codes, e.g. `wp_loupe_missing_query`, `wp_loupe_unallowlisted_field` | A REST error `code` is a single string; changing it would break clients that branch on it |
| PHP namespace `Soderlind\Plugin\WPLoupe` and `WP_Loupe_*` class names | Internal API, not part of the public contract |
