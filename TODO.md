## TO DO

This is a list of features, both implemented and planned. Checked items are completed, while unchecked items are under development or consideration.

- [x] Automatic update of search index upon creation or modification of a post or page.
- [x] Tolerant to typos (based on the State Set Index Algorithm and Levenshtein)
- [x] Supports phrase search using `"` quotation marks
- [x] Supports stemming
- [x] Utilizes stop words from the WordPress translation, e.g., [Norwegian bokmål](https://translate.wordpress.org/projects/wp/dev/nb/default/?filters%5Bstatus%5D=either&filters%5Boriginal_id%5D=70980&filters%5Btranslation_id%5D=2917948).
- [x] Auto-detects languages
- [x] Option to reindex all posts and pages from the admin interface (Settings > WP Loupe).
- [x] Compatible with the theme's search.php template. Tested with [Twenty Twenty-Four](https://wordpress.org/themes/twentytwentyfour/) and [Twenty Twenty-Five](https://wordpress.org/themes/twentytwentyfive/).
- [x] Custom post types.
- [x] Adds processing time, as a comment, to the footer.
- [x] Supports translation. .pot file is included in the `languages` folder.
- [x] Delete posts and pages from the search index when they are deleted.
- [x] Pagination.
- [x] Developer-first REST Search API (GET legacy + POST advanced JSON).
- [x] Filter search results (AND, OR, IN, NOT IN, etc.) via POST `/wp-json/wp-loupe/v1/search` JSON filter AST.
- [x] Facets (terms) via POST `/wp-json/wp-loupe/v1/search`.
- [x] Geo radius + geo sorting via POST `/wp-json/wp-loupe/v1/search`.
- [x] Removed bundled UI integration (block/shortcode/search-form override). Build your own UI via the API.
- [ ] Categories, tags, and custom taxonomies (indexing + allowlisting for filtering/faceting).
- [ ] 2.0.0: Custom fields (indexing + allowlisting for filtering/faceting).
- [ ] Multisite support, including the option to index all sites in a network.
- [ ] Multisite support. Select which sites to index.
- [ ] Multisite support. Select which site to do search from.
- [ ] Expose/filter/sort on any allowlisted attribute (within schema constraints).
- [x] 1.1.0: Rename developer hooks `wp_loupe_*` → `loupe_search_*` non-breakingly. Fire the new hook names and keep the old ones via `apply_filters_deprecated()`/`do_action_deprecated()` (deprecated since 1.1.0) so existing integrations keep working.
- [x] 1.1.0: Rename WP CLI commands `wp loupe` → `wp loupe-search` non-breakingly. Keep the old command names via `WP_CLI::add_command_deprecated()` (deprecated since 1.1.0) so existing integrations keep working.
- [x] 1.1.0: Rename REST API namespace `wp-loupe` → `loupe-search` non-breakingly. Keep the old namespace via `register_rest_route_deprecated()` (deprecated since 1.1.0) so existing integrations keep working.
- [x] 1.1.0: Rename wp-loupe-db folder → loupe-search-db non-breakingly. Keep the old folder name for backwards compatibility (deprecated since 1.1.0) so existing integrations keep working. Investigate if needed. Databases are created if missing, so it should be safe to rename the folder. The plugin will create the new folder and populate it with a new index on first run. The old folder can be deleted after the plugin has been updated.
- [ ] When updating the `loupe/loupe` library, re-check `.distignore` for the release zip. We exclude unused `nitotm/efficient-language-detector` n-gram databases (`extralarge.php`, `medium.php`, `small.php`, `blob/`) and keep only `large.php`, because Loupe hardcodes `EldDataFile::LARGE` with `MODE_ARRAY` (see `vendor/loupe/loupe/src/Internal/LanguageDetection/NitotmLanguageDetector.php`). If a Loupe update changes the n-gram size or database mode, the shipped plugin would break language detection. Also re-verify the compressed zip stays under wp.org's 10 MB limit (currently ~9.4 MB).

