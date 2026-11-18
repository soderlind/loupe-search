=== Loupe Search ===
Contributors: PerS
Tags: search, full-text search, typo-tolerant, bbpress, SQLite
Requires at least: 7.0
Tested up to: 7.1
Requires PHP: 8.3
Stable tag: 1.7.3
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html
Donate link: https://paypal.me/PerSoderlind

A search enhancement plugin for WordPress that delivers fast, accurate, and typo-tolerant results.

== Description ==

Loupe Search improves WordPress core search by maintaining its own index for fast lookups, supporting typo tolerance, phrase matching, basic exclusion operators, and per–post-type customization.

= Core Features =

* 🚀 Index-backed search engine replacing the WordPress default
* ⚡ Results served from a dedicated index, not the posts table
* 🔄 Real-time index synchronization
* 🌐 Support for multiple languages
* 📦 Full custom post type integration
* 📈 Integrated search performance metrics
* ✅ Works with the WordPress default themes

= Search Capabilities =

* 🔍 Typo-tolerant searching - find results even with misspellings
* 💬 Phrase matching with quotation marks: `"exact phrase"`
* ➖ Exclusion operator support (e.g., `term -excluded`)
* 🔀 Multi-word search: `term1 term2` finds content with both terms, or with either term when nothing contains both
* 📖 Pagination support
* Stemming support
* Stop words recognition
* Highlighting and cropped snippets on the default search results (opt-in) and via the REST API

= Administration =

* Simple settings interface
* Post type selection
* Field configuration options
* One-click reindexing
* Exclude individual posts from search (post list column, Quick Edit, bulk actions, editor sidebar)
* Opt-in Search insights: top searches and searches without results, stored as privacy-friendly daily totals (no IP, user or browser data). Report under Dashboard → Search insights with fixed or custom date ranges, a Dashboard widget and CSV export
* Processing time monitoring

= Developer Features =

* 🛠️ Extensive filter system for customization
* 📊 Performance monitoring and diagnostics
* 🔧 Customizable indexing
* Field weighting control

See the [Search API documentation](https://github.com/soderlind/loupe-search/blob/main/docs/search-api.md) for REST endpoint details.

= Add-ons =

Install, activate and update Loupe Search Block, Loupe Semantic Search and Loupe Search Multilingual from one screen with [Loupe Search Add-ons](https://github.com/soderlind/loupe-search-addons). Their updates then show up in Dashboard → Updates like any other plugin.

* 🔍 [Loupe Search Block](https://github.com/soderlind/loupe-search-block) — A search block with live results as you type, a post type filter, category and tag facets, sorting and highlighted snippets. Falls back to a plain search form without JavaScript.
* 🌐 [Loupe Cross-Site Search](https://github.com/soderlind/loupe-cross-site-search) — Search across every site in a WordPress multisite network from a single hub. Mirrors each site's public content into one combined index and ships a ready-to-use Cross-Site Search block with site and post-type facets, sorting, and highlighted snippets. Requires WordPress multisite 7.0+ and Loupe Search active on the network.
* 🧠 [Loupe Semantic Search](https://github.com/soderlind/loupe-semantic-search) — Adds semantic retrieval to Loupe Search. Hybrid (keyword + semantic) search finds posts by meaning as well as by words, in the front-end search, the REST API and Loupe Search Block. Abilities return RAG passages with sources for AI agents. Embeddings come from a provider you connect through WordPress 7 AI Connectors. Requires Loupe Search 1.5.0+ and the AI plugin 1.5.0+.
* 🗣️ [Loupe Search Multilingual](https://github.com/soderlind/loupe-search-multilingual) — One index per language for sites translated with Polylang or WPML. Each language gets its own stemming and stop words, and visitors only find posts in the current language. Requires Loupe Search 1.6.0+.
* 📁 [VMFA Search](https://github.com/soderlind/vmfa-search) — Supercharges the native Media Library search field with fast, typo-tolerant search across title, filename, alt text, caption, and description. Requires the [Virtual Media Folders](https://wordpress.org/plugins/virtual-media-folders/) plugin.

= AI Agent Integration (WordPress Abilities API) =

Loupe Search registers two abilities via the WordPress Abilities API (WordPress 6.9+) so AI agents and automation tools can discover and use search functionality natively:

* `loupe-search/search` — Typo-tolerant full-text search across indexed post types. Supports phrase matching, exclusion, and OR operators. Publicly accessible.
* `loupe-search/get-post` — Retrieve a single published post by ID. Publicly accessible.

These abilities are discoverable through the standard WordPress Abilities registry and require no additional Loupe Search configuration. As of 1.2.0 the ability namespace is `loupe-search`; the legacy `wp-loupe/search` and `wp-loupe/get-post` abilities still work but are deprecated aliases.

For an AI agent to use these abilities, install and activate the [MCP Adapter](https://wordpress.org/plugins/mcp-adapter/) plugin. It exposes registered abilities to agents over the Model Context Protocol (MCP).

[Upgrading from the experimental MCP server?](https://github.com/soderlind/loupe-search/blob/main/docs/migration-mcp-to-abilities.md) The MCP server, token service, and WP-CLI token commands were removed in 0.8.5. Use the Abilities API endpoints described above instead; no configuration migration is required.

= Filters =

These filters allow developers to customize Loupe Search's behavior. As of 1.1.0 they use the `loupe_search_*` prefix; the old `wp_loupe_*` names still work but are deprecated and will be removed in a future major release.

* `loupe_search_post_types`: Choose which post types are indexed.
* `loupe_search_index_protected`: Index password-protected posts.
* `loupe_search_indexable_post_statuses`: Index extra publicly viewable statuses.
* `loupe_search_is_indexable`: Keep individual posts out of the index.
* `loupe_search_db_path`: Move the index directory.
* `loupe_search_language`: Override the stemming language.
* `loupe_search_stop_words`: Change the common words ignored in searches.
* `loupe_search_partitions`, `loupe_search_post_partition`, `loupe_search_partition_language`, `loupe_search_query_partitions`, `loupe_search_partition_label`: Split a post type's index into partitions, for example one per language.
* `loupe_search_field_{$field_name}`: Clean a core post field before indexing.
* `loupe_search_schema_{$post_type}`: Add/remove fields, set weights and sorting.
* `loupe_search_is_safely_sortable_{$post_type}`: Force a core field to be sortable.
* `loupe_search_is_safely_sortable_meta_{$post_type}`: Correct meta-field sortability detection.
* `loupe_search_posts_per_page`: Change results per page.
* `loupe_search_max_cacheable_query_length`: Change which queries are written to the result cache.
* `loupe_search_order_results`: Reorder or regroup results merged across post types.
* `loupe_search_advanced_hits`: Rerank or add hits in REST `POST /search` before pagination.
* `loupe_search_highlight`: Turn match highlighting on for the default search.
* `loupe_search_highlight_fields`: Choose which fields are highlighted.
* `loupe_search_highlight_start_tag` / `loupe_search_highlight_end_tag`: Change the tags wrapped around matches.
* `loupe_search_highlight_crop_fields`: Choose which fields become cropped snippets.
* `loupe_search_highlight_crop_length`: Change snippet length (in words).
* `loupe_search_highlight_crop_marker`: Change the ellipsis on cropped snippets.
* `loupe_search_insights_should_count`: Decide whether a visitor search is counted by Search insights.
* `loupe_search_settings_tabs`: Add a tab to Settings → Loupe Search.

Action: `loupe_search_searched` fires after a visitor search (first results page, or REST with `countSearch: true`), with the query, total hits and source. It fires before the Search insights checks, so searches rejected by `loupe_search_insights_should_count` still trigger it.

For signatures, defaults, and usage examples, see the [filter documentation](https://github.com/soderlind/loupe-search/blob/main/docs/filters.md).


== Installation ==

1. **Quick Install**
   * Upload the plugin files to the `/wp-content/plugins/loupe-search` directory, or install the plugin through the WordPress plugins screen directly
   * Activate through the 'Plugins' menu in WordPress

2. **Post-Installation**
   * Visit Settings > Loupe Search to configure
   * Click 'Reindex' to build the initial search index (runs in batches to avoid timeouts)

3. **Updates**
   * Plugin updates are handled automatically by WordPress.org.

== Screenshots ==

1. Dashboard tab showing index health per post type, batched reindexing, and the system status for the required PHP extensions.
2. Fields tab for selecting which post types are indexed and configuring each field as indexable, filterable or sortable, with weight and sort direction.
3. Search Behavior tab with tokenization, prefix search and typo tolerance settings.
4. Search insights settings tab for turning on privacy-friendly search counting, choosing how long data is kept, opening the report and deleting all data.
5. Contextual help tabs explaining field configuration options and search behavior settings.
6. Search insights report under Dashboard → Search insights, with preset and custom periods, CSV export, top searches and searches without results.

== Frequently Asked Questions ==

= How does it handle updates to posts? =

The search index automatically updates when content is created, modified, or deleted.

= Will it slow down my site? =

No. Loupe Search uses a separate, optimized search index and doesn't impact your main database performance.

= Can I customize what content is searchable? =

Yes, using filters you can control exactly what content gets indexed and how it's searched. To hide a single post, mark it as excluded from the post list or the editor sidebar. Excluding a post only removes it from search results; it stays public at its URL.

= Does it work with custom post types? =

Yes, you can select which post types to include in the Settings page or via filters.

= How do I reindex on large sites? =

Use Settings > Loupe Search > Reindex (batched), or run via WP-CLI:

* `wp loupe-search reindex`
* `wp loupe-search reindex --post-types=post,page --batch-size=1000`

= How do I use advanced search operators? =

* `Hello World` will search for posts containing both `Hello` and `World`. If no post contains both, posts containing either word are returned.
* `"Hello World"` will search for posts containing the exact phrase `Hello World`.
* `Hello -World` will search for posts containing `Hello` but not `World`.

= What are the technical requirements? =

* PHP 8.3 or higher
* SQLite 3.35+ (required by Loupe)
* PHP extensions: pdo_sqlite, intl, mbstring
* WordPress 6.9+


== Changelog ==

= 1.7.3 =
* Fixed: The From and To dates on the Search insights report use the date format from Settings → General instead of the browser's locale, with a date picker that follows the site's language and start of week. Typed dates are checked before the report is applied, so an invalid or future date shows a message instead of silently using the previous date.
* Changed: Updated WordPress.org screenshot 4 (Search insights settings tab) and added screenshot 6 (Search insights report).

= 1.7.2 =
* Changed: Search insights "Searches without results" lists only queries that found nothing in the whole period. A query that found results some of the time is listed only under Top searches, so the two lists no longer overlap. Applies to the report page and the Dashboard widget.
* Changed: The "without results" total in the Search insights summary counts only searches for those never-found queries, so it matches the list.

= 1.7.1 =
* Changed: Search insights follows WordPress admin design conventions: right-aligned number columns, "Export this period (CSV)" next to the period heading, a screen-reader heading for the period links, "Custom" shown as the current period, a stylesheet instead of inline styles, and more spacing in the Dashboard widget.
* Changed: The settings save button is now labelled "Save Changes".
* Fixed: "Delete all data permanently" now requires a confirmation checkbox, which is also checked on the server. The button is styled as a destructive action.

= 1.7.0 =
* Added: The Search insights report moved to Dashboard → Search insights, with a Dashboard widget for the last 7 days. The settings tab links to it.
* Added: Search insights periods: this month, last week, last month, last quarter and a custom date range, next to the last 7, 30 and 90 days.
* Added: Search insights Export CSV: daily totals (day, query, searches, zero results, results sum) for the selected period. Queries that a spreadsheet would read as a formula are exported as text.
* Changed: Top searches no longer list queries that never returned a result; they appear only under searches without results.
* Changed: Docs list the Loupe Search Multilingual add-on and the Loupe Search Add-ons manager.
* Fixed: The Search column in post lists has a fixed width instead of taking up spare space.

= 1.6.0 =
* Added: Index partitions. Add-ons can split a post type's index into several indexes, for example one per language, each with its own stemming and stop words. New filters `loupe_search_partitions`, `loupe_search_post_partition`, `loupe_search_partition_language`, `loupe_search_query_partitions` and `loupe_search_partition_label`. Without them nothing changes.
* Added: The Dashboard's index health table shows a row per partition.
* Changed: The internal `loupe_search_internal_document_added` action passes the partition as a third argument.

= 1.5.6 =
* Fixed: Field weights on the Fields tab no longer show as `1.0` (or `1,0` in some browser locales) next to saved weights shown as `1`, and a saved weight of `0` is no longer replaced by the default.
* Fixed: The settings Help overview now lists the Search insights tab.
* Changed: readme.txt lists every public filter and the `loupe_search_searched` action; the full reference is in docs/filters.md.
* Changed: The FAQ now explains that multi-word searches match all words, falling back to any word.
* Changed: Updated WordPress.org screenshots, including the Search insights tab.

= 1.5.5 =
* Added: Common words (stop words) such as "the", "er" or "på" are ignored in searches, so they no longer make most posts match. Lists for 12 languages are bundled; change them with the `loupe_search_stop_words` filter. Indexes are rebuilt automatically after updating.
* Changed: Multi-word searches now return posts that contain every word. If no post contains every word, posts that contain any of them are returned.

= 1.5.4 =
* Fixed: Search terms are now stemmed like indexed content, so inflected forms find each other (for example "plager" also finds "plagene"). Exact matches still rank first.

= 1.5.3 =
* Fixed: REST search and the search block no longer fail with "Rebuild required" after updating to 1.5.2; the old index is used until it has been rebuilt.
* Added: Out-of-date indexes are rebuilt automatically in the background after a plugin update.

= 1.5.2 =
* Changed: Updated the bundled Loupe search library to 1.1.7.

= 1.5.1 =
* Fixed: Stemming now works for Norwegian (nb_NO, nn_NO) and other locales whose code the stemmer did not recognise, and three-letter locales no longer get the wrong language (e.g. Filipino was stemmed as Finnish). Reindex after updating.
* Added: `loupe_search_language` filter to override the stemming language.
* Changed: Docs list the Loupe Semantic Search add-on.

= 1.5.0 =
* Changed: Requires WordPress 7.0 or later.
* Added: `loupe_search_advanced_hits` filter, so add-ons can rerank or add hits in REST `POST /search` before pagination. Its context includes a `matches_filter` callback that keeps added hits within the request's filters.
* Added: `loupe_search_order_results` now also receives the searched post types and the applied field sort.
* Added: `loupe_search_settings_tabs` filter, so add-ons can add a tab to Settings → Loupe Search.
* Added: `loupe_search_is_post_indexable()` function, telling add-ons whether Loupe Search indexes a post.
* Fixed: REST search skipped post types registered on `init`, such as bbPress forums, topics and replies and custom post types from CPT UI or a theme.

= 1.4.0 =
* Added: Exclude individual posts from search, from the post list (Search column, Quick Edit, bulk actions) or the editor sidebar. The index updates immediately. This hides posts from search results only; it is not access control. Fixes #69.
* Added: The Dashboard shows the number of excluded posts per post type, and the index-status REST response includes `excluded`.
* Added: Search insights (opt-in, off by default): top searches, top searches without results and the average result count for the last 7, 30 or 90 days. Stores daily totals per query only — no IP address, user, user agent or time of day. 90-day retention by default, purged daily. Fixes #70.
* Added: REST `countSearch` parameter on `GET` and `POST /search`.
* Added: Public REST `GET /search-fields` route listing the searchable post types and the fields each one can filter, facet and sort on.
* Added: `loupe_search_searched` action and `loupe_search_insights_should_count` filter.
* Fixed: REST `POST /search` ignored `attributesToHighlight` and `attributesToCrop`, so `_formatted` was never returned.
* Fixed: REST search `_formatted` values are filtered to inline highlight tags, so stored markup can't reach the page as HTML.
* Fixed: The "reindex needed" admin notice now ignores excluded posts.

= 1.3.8 =
* Added: bbPress support. Forums, topics and replies are indexed only when their forum and all parent forums are public; changing a forum's visibility or moving a forum or topic re-checks the affected sub-forums, topics and replies.
* Added: `loupe_search_is_indexable` filter to keep individual posts out of the index.
* Added: `loupe_search_indexable_post_statuses` filter to index extra publicly viewable statuses per post type.
* Fixed: WP-CLI crashed on sites where Loupe Search is active because `wp loupe-search` was registered under the old namespace.
* Fixed: A full reindex now applies the same rules as saving a post, so password-protected posts are no longer indexed by a reindex.
* Changed: Updated npm dependencies.

= 1.3.7 =
* Added: Admin notice prompting a reindex when an index is missing or out of date (e.g. after upgrading or on multisite), with a link to the reindex dashboard.
* Added: The Dashboard “Index health” now shows the indexed document count and flags an empty index as needing a reindex.
* Security: The index directory is now protected from direct web access with .htaccess/web.config/index.php files, so the SQLite index (which can contain private post data) is no longer downloadable. Nginx users must deny the directory in their server config. Fixes #62.
* Fixed: Search now uses the site locale for stemming and stopwords instead of always English. Fixes #57.
* Fixed: Sortable fields are no longer forced as sort criteria on every search; regular searches keep relevance ordering. Fixes #64.
* Fixed: Typo tolerance now defaults to enabled when settings were never saved, matching the settings UI. Fixes #58.
* Fixed: Permanently deleting a post (including via wp_delete_post()) now removes it from the index. Fixes #59.
* Fixed: Multisite installs now use a per-site index directory so networked sites no longer overwrite each other's index. Fixes #56.
* Fixed: The SQLite availability check now runs before the plugin wires up its SQLite-backed components. Fixes #63.

= 1.3.6 =
* Fixed: Match highlighting no longer strips paragraph/layout markup in block themes like Twenty Twenty-Five; matches are highlighted in place within the rendered content. Fixes #54.
* Fixed: A custom highlight tag that reuses a common element (e.g. `<span class="hit">`) no longer skips ordinary elements of the same name.

= 1.3.5 =
* Added: New `loupe_search_order_results` filter to customize how results are ordered or grouped across post types.
* Fixed: The indexer now runs during WP-Cron, so scheduled posts and cron-driven imports are indexed automatically. Fixes #52.
* Fixed: Search ordering now respects field weights, and results are merged across post types by relevance instead of grouping one type above another. Fixes #51.
* Fixed: Match highlighting now works in block themes that render full content via core/post-content, such as Twenty Twenty-Five. Fixes #50.

= 1.3.4 =
* Fixed: Loupe Search is now fully self-contained — its bundled Composer libraries are namespace-scoped so they can no longer clash with different versions of the same libraries loaded by the active theme or another plugin. This resolves fatal errors that appeared with several themes whose `functions.php` loads its own bundled libraries; library-free themes such as Twenty Twenty-Five were never affected.

= 1.3.3 =
* Fixed: Categories and tags are now indexed when a post is created or updated — the indexer now runs on `wp_after_insert_post`, which fires after a post's terms are saved. Previously you had to reindex manually. Fixes #41. Props @Zodiac1978.
* Fixed: Renaming or deleting a category/tag now reindexes the posts attached to that term, so search finds them under the new name immediately. Fixes #43. Props @Zodiac1978.
* Fixed: Plugin URI header now points to the GitHub repository instead of a WordPress.org URL. Fixes #38. Props @Zodiac1978.
* Fixed: Several i18n string issues — clearer deprecated-alias descriptions, a removed trailing space and a needless translatable string, and a missing closing quote in the Prefix Search help text. Fixes #36. Props @Zodiac1978.

= 1.3.2 =
* Added: Opt-in match highlighting on the default WordPress search results. Toggle it with the new "Highlight Matches" checkbox under Settings → Loupe Search → Search Behavior (off by default), or override in code with `add_filter( 'loupe_search_highlight', '__return_true' )`. Titles get matched terms wrapped in `<mark>` and excerpts become highlighted, cropped snippets. Tune it with the `loupe_search_highlight_fields`, `loupe_search_highlight_start_tag`/`_end_tag`, `loupe_search_highlight_crop_fields`, `loupe_search_highlight_crop_length`, and `loupe_search_highlight_crop_marker` filters. Highlight tags are sanitized to a safe inline allowlist. Works in classic and block themes (block-theme excerpts are re-highlighted after the core/post-excerpt block strips tags). See the Filters documentation for details.
* Fixed: Fatal error when the old "WP Loupe" plugin was active at the same time as Loupe Search — both declared the same classes, so activating one crashed with "Cannot declare class ... already in use". Loupe Search now uses its own PHP namespace and, when WP Loupe is still active, shows a notice asking you to deactivate it instead of crashing.

= 1.3.1 =
* Fixed: Typo tolerance settings (alphabet size, index length, first-character double counting, prefix search, and thresholds) were silently ignored because Loupe's immutable TypoTolerance builder methods return a new instance that was discarded. Props @Zodiac1978.
* Fixed: Admin JavaScript strings were not translatable because wp_set_script_translations() was never called for the loupe-search-admin script. Props @Zodiac1978.
* Fixed: Corrected the broken link to the "Efficient Similarity Search in Very Large String Sets" research paper in the typo tolerance settings description. Props @Zodiac1978.

= 1.3.0 =
* Security: Hardened the Abilities API integration. The loupe-search/get-post ability no longer returns the content of password-protected posts, and password-protected posts can no longer surface through the loupe-search/search ability or the public REST search.
* Changed: The loupe-search/search and loupe-search/get-post abilities and the REST search endpoints now share one public-visibility gate (published, public post type, not password protected).
* Changed: The loupe-search/get-post ability now returns proper HTTP status codes (400 for an invalid id, 404 when not viewable).
* Fixed: A post's index document is now purged when it becomes unpublished or password protected, instead of leaving a stale copy.
* Added: A search benchmark harness.

= 1.2.6 =
* Fixed: Reindexing failed with a "Cannot open for reading gzip" error because the release package omitted loupe/matcher's dictionary.gz, which the decompounder rebuilds its search index from.

= 1.2.5 =
* Changed: The plugin description now summarises what the search does, and Plugin URI points at the WordPress.org listing.
* Changed: Tested up to WordPress 7.1.
* Fixed: The settings help panel still called the plugin "WP Loupe".

= 1.2.4 =
* Changed: The minimum PHP version is now 8.3. The plugin's dependencies already required PHP 8.2/8.3, so the previously advertised 8.1 requirement was not accurate.
* Security: The public search REST route and search ability now scope the query itself to public post types, so hit totals and facet counts can no longer reveal indexed non-public content.
* Changed: The plugin's options moved off the `wp_` prefix, which is reserved for WordPress core. Existing settings are migrated automatically on first load.
* Changed: The settings screen moved to `options-general.php?page=loupe-search`.
* Removed: The 12-hour cache in front of the public search route. Caching every query from an unauthenticated route could grow the options table without bound.
* Removed: Third-party command line entry points and build config from the release package.
* Removed: The unused language-detection n-gram databases from the release package, which drops it from 197 MB to 25 MB.
* Fixed: The indexer stripped tags using a deprecated filter name.
* Added: The `loupe_search_max_cacheable_query_length` filter (default 128) bounds which queries are written to the result cache.

= 1.2.3 =
* Changed: Updated the plugin screenshots to reflect the current settings UI.
* Changed: Corrected the screenshot descriptions to match the current Search Behavior tab.

= 1.2.2 =
* Security: Public REST search endpoints and the search abilities now only return results from public post types.
* Security: Field settings now sanitize the nested post-type and field-name keys.
* Changed: Updated the bundled Loupe search engine to 1.0.1 (compound-word decomposition, improved excerpt cropping).
* Removed: The bundled plugin update checker library; updates are served by WordPress.org.
* Fixed: Admin help-tab styles are now enqueued instead of printed in an inline style tag.
* Fixed: Removed a broken documentation link and excluded development/test folders from the release package.

= 1.2.1 =
* Fixed: The `loupe_search_post_types` filter now applies everywhere. It was only applied when resolving the front-end search scope, so the indexer, the REST API and the WordPress Abilities kept using the raw settings value. The settings screen still shows the stored option.
* Fixed: Uninstalling now deletes the plugin's options and cached search results, on every site of a multisite network. Previously only the index directories were removed.
* Fixed: Removed a bootstrap short-circuit that skipped plugin initialization on REST requests whose path did not start with `/wp-json/wp-loupe`.
* Fixed: The Composer package is now `soderlind/loupe-search`, and its PSR-4 prefix is no longer over-escaped.
* I18n: Regenerated `loupe-search.pot`. It now includes the ability and highlighting strings added in 1.2.0, and the plugin URI no longer points at the old repository.
* Removed: The unused `WP_Loupe_Migration` class and `WP_Loupe_Utils::is_post_indexable()`.
* Documentation: Added an architecture guide and a docs index, and corrected the filter, rename and migration guides.

= 1.2.0 =
* Added: REST search now supports opt-in highlighting and cropping. Send `attributesToHighlight` and/or `attributesToCrop` (field names or `["*"]`) to the POST `/search` endpoint; each hit then includes a `_formatted` object with matched terms wrapped in tags and/or cropped snippets. Tag/marker and length are configurable via `highlightStartTag`, `highlightEndTag`, `cropLength`, and `cropMarker`. Highlight tags are sanitized to a safe allowlist.
* Changed: WordPress Abilities are now registered as `loupe-search/search` and `loupe-search/get-post` under the `loupe-search` category. The legacy `wp-loupe/search` and `wp-loupe/get-post` abilities remain registered as deprecated aliases and will be removed in a future major release.

= 1.1.1 =
* Fixed: Front-end search now works when a public, searchable post type (e.g. attachments) is not indexed. Previously the plugin bailed out and fell back to WordPress's default search, returning no Loupe results.

= 1.1.0 =
* Changed: Developer filters now use the `loupe_search_*` prefix.
* Changed: REST namespace is now `loupe-search/v1`; WP-CLI command is now `wp loupe-search`; the index folder is now `wp-content/loupe-search-db` (existing installs keep using `wp-loupe-db` if present).
* Deprecated: The `wp_loupe_*` filters, the `wp-loupe/v1` REST namespace, the `wp wp-loupe` CLI command, and the `wp-loupe-db` folder name are deprecated but still work for backward compatibility; they will be removed in a future major release.

= 1.0.0 =
* Added: Redesigned settings screen with Dashboard, Fields, and Search Behavior tabs.
* Added: Dashboard showing per-post-type index health, a reindex panel with a progress bar and Cancel button, and a system status check for required PHP extensions.
* Added: REST endpoint `GET /wp-json/wp-loupe/v1/index-status` returning index health for configured post types.
* Changed: Replaced the Select2 post-type dropdown with an accessible native checkbox picker.
* Changed: Field configuration for each post type is now shown in collapsible accordions.
* Changed: Updated the contextual help tabs to match the redesigned settings screen and removed references to controls that no longer exist.
* Removed: Select2 dependency and its bundled assets.
* Removed: Auto-update settings toggle; per-plugin auto-updates are now handled by WordPress core.

= 0.8.5 =
* Added: WordPress Abilities API integration (WordPress 6.9+) registering `wp-loupe/search` and `wp-loupe/get-post` abilities for native AI agent and automation discovery.
* Changed: Lowered minimum PHP requirement to 8.1; raised minimum WordPress to 6.9.
* Changed: Prepared for the WordPress.org plugin directory; updates are now handled by WordPress.org.
* Removed: Experimental MCP (Model Context Protocol) server, token service, and WP-CLI token commands (replaced by the WordPress Abilities API).
* Removed: GitHub-based plugin updater.

= 0.8.4 =
* Changed: Updated PHP dependencies to `loupe/loupe` 0.13.12, `phpunit/phpunit` 12.5, and `pestphp/pest` 4.5.
* Changed: Updated JavaScript tooling dependencies and lockfile to address open dependency advisories.
* Changed: PHPUnit configuration and legacy test method names were updated for PHPUnit 12 compatibility.

= 0.8.3 =
* Fixed: `should_intercept_query()` validates all public searchable post types are indexed before intercepting generic searches.
* Changed: Added DocBlocks for methods in search hooks, token service, and indexer classes.

= 0.8.2 =
* Changed: Enhanced README with WP Loupe compatibility note.
* Changed: Updated dev dependencies (`basic-ftp` 5.0.5 → 5.2.0).

= 0.8.1 =
* Fixed: Search hooks now activate for AJAX requests (e.g., live search), resolving missing results in AJAX-powered search forms.
* Fixed: Pagination respects the query `posts_per_page` variable before falling back to the global option.
* Fixed: `should_intercept_query()` intercepts AJAX search requests, not only main queries.
* Changed: Search engine retrieves up to 1 000 hits per post type before client-side paging to prevent truncated result sets.
* Changed: Updated PHP and JavaScript dependencies.

= 0.8.0 =
* Added: Batched reindexing to avoid admin request timeouts on large sites.
* Added: Admin-only maintenance REST endpoint: `POST /wp-json/wp-loupe/v1/reindex-batch`.
* Added: WP-CLI command for batched reindexing: `wp wp-loupe reindex`.
* Added: Pest test runner (dev) alongside PHPUnit.
* Changed: Reindexing is triggered via a separate admin UI button (not tied to saving settings).

= 0.7.0 =
* Added: Search API guide with hook-based field preparation examples (facets, geo, sorting).
* Added: Filter `wp_loupe_is_safely_sortable_meta_{$post_type}` to override meta sortability decisions.
* Fixed: Geo-point meta fields are treated as sortable-safe for distance sorting.

= 0.6.0 =
* Added: Split search engine (side-effect free) from front-end hooks to avoid REST/MCP side effects.
* Added: Advanced REST search API via POST `/wp-json/wp-loupe/v1/search` (JSON filters, facets, geo, sorting).
* Removed: Bundled UI integration (block/shortcode/search form override). Build your own UI using the REST API.
* Changed: Upgraded `loupe/loupe` to 0.13.4 and tightened runtime requirements checks.
* Fixed: Reindexing now safely rebuilds/migrates indexes across Loupe schema upgrades.
* Fixed: Guarded against empty `wp_loupe_db_path` filter values.

= 0.5.7 =
* Added: Always expose core WordPress fields (`post_title`, `post_content`, `post_excerpt`, `post_date`, `post_modified`, `post_author`, `permalink`) in REST field discovery and settings UI even if unchecked for indexing.
* Changed: Field discovery flow now starts with mandatory core fields then merges schema & meta keys for stable UI state.
* Fixed: Previous changelog typo ("dependecies" -> "dependencies").

= 0.5.6 =
* Changed: Updated dependencies to latest versions

= 0.5.5 =
* Added: Settings toggle to enable or disable automatic plugin updates (defaults enabled).
* Added: Schema manager unit test validating baseline `post_date` only.
* Added: Updated translation template with new settings strings.
* Changed: Simplified baseline schema to only include mandatory `post_date`; per-post-type field settings now applied cleanly.
* Changed: Readme wording trimmed to reduce promotional language.
* Fixed: Structural mismatch in default schema logic preventing accurate field inheritance.

= 0.5.4 =
* Added: Automatic plugin update infrastructure (filter-based) with constant opt-out.
* Added: Migration ensuring mandatory `post_date` field exists after Loupe upgrade; conditional reindex strategy (immediate for small sites, scheduled for large).
* Fixed: Publishing/indexing error caused by missing SQLite `post_date` column.
* Internal: Post-date migration triggers safe reindex path based on site size.

= 0.5.3 =
* Added: Copy buttons (with accessible live feedback) for MCP manifest and protected resource endpoints.
* Added: Aria-live region and translatable status messages for copy success/failure.
* Changed: Removed inline JavaScript for endpoint copying; logic centralized in `admin.js`.
* Fixed: Clipboard fallback for browsers without `navigator.clipboard` support.
* Changed: Minor wording clarity improvements in endpoint descriptions.
* Note: Small UX iteration paving the way for richer manifest metadata.

= 0.5.2 =
* Added: Settings surfacing discovery endpoints (manifest & protected resource) for MCP clients.
* Added: Accessibility improvements groundwork (live region placeholder) before 0.5.3 enhancements.
* Changed: Removed POST `/commands` from visible endpoint list (method clarity).
* Fixed: Ensured reliable JSON output for `/.well-known/mcp.json` (rewrite + fallback path).
* Fixed: Clipboard copy resilience improvements (initial implementation) preparing for 0.5.3.
* I18n: Regenerated `wp-loupe.pot` with new MCP strings and translator comments.
* Note: Internal rate-limit option polish and manifest stability adjustments.

= 0.5.1 =
* UI: Wrapped MCP token table in panel and standardized max-width (840px)
* UI: Reordered headings and moved Save button for consistency
* MCP: Token management interface (scopes, TTL presets, revoke all, last-used tracking, copy-once)
* MCP: Hybrid anonymous/authenticated search access with scoped tokens
* MCP: Secure HMAC-signed pagination cursors for `searchPosts`
* MCP: WP-CLI token issuance mirrored in admin interface

= 0.5.0 =
* Initial MCP integration (preview): discovery manifest, commands, rate limiting, scoped tokens, pagination security
* Requires PHP 8.3+ and Loupe 0.12.13

= 0.4.3 =
* Fixed: Inline JavaScript using `wp_print_inline_script_tag`.
* Plugin updates are handled automatically via GitHub. No need to manually download and install updates.

= 0.4.2 =
* Customizer settings not being saved

= 0.4.1 =
* Update settings documentation in README.md
* Update translations for new strings

= 0.4.0 =
* Added improved caching mechanisms for better performance
* Enhanced field configuration management and organization
* Refactored code structure for better maintainability
* Optimized sortable field checking with static caching
* Improved attribute extraction and configuration building
* More efficient handling of typo tolerance configuration

= 0.3.2 =
* Fixed: In readme.txt, update the `Tested up to` value to 6.7

= 0.3.1 =
* Bug fix: Non-scalar fields no longer get selected by default for sorting when adding a new post type
* Improved field configuration UI to properly handle non-sortable fields
* Updated translations for new strings

= 0.3.0 =
* Added support for custom post types
* Added field configuration interface for indexing, filtering, and sorting
* Improved search algorithm
* Performance optimizations

= 0.2.3 =
* Enhanced field indexing to strictly respect settings configuration
* Improved schema manager to only include explicitly selected fields
* Refined factory class to ensure proper field filtering from settings
* Added filter `wp_loupe_field_{$field_name}` to allow field modification.

= 0.2.2 =
* Changed: Modified field indexing to only include explicitly selected fields in settings
* Changed: Updated schema manager to respect indexable field settings
* Changed: Improved field selection behavior in admin interface

= 0.2.1 =
* Added translation support for admin interface
* Updated translation files with new strings

= 0.2.0 =
* Added new field settings management interface in the settings page
	* Added ability to configure Weight, Filterable, and Sortable options per field
* Added help tabs to explain field configuration options
	* Added detailed explanations for Weight, Filterable, and Sortable fields
	* Added help sidebar with documentation link

= 0.1.7 =
* Refactored code: Replaced WP_Loupe_Shared trait with WP_Loupe_Factory class
* Improved code organization and maintainability
* Enhanced code structure for better testability

= 0.1.6 =
* Housekeeping

= 0.1.5 =
* Fixed: GitHub API authentication errors in updater class
* Fixed: Added proper token-based authentication for GitHub API requests
* Fixed: Resolved 403 errors when checking for plugin updates

= 0.1.4 =
* Fixed issue with plugin update notification not showing in some cases
* Fixed GitHub release asset detection for automatic updates

= 0.1.3 =
* Security: Improved GitHub integration with proper API token handling
* Security: Updated GitHub actions workflow for better release asset management
* Added: Plugin update success notification
* Added: Improved GitHub release asset detection with regex pattern
* Added: Updated installation instructions for automatic updates
* Changed: Enhanced updater with better error handling
* Changed: Updated dependencies to latest versions

= 0.1.2 =
* Added "Behind the scenes" documentation section explaining plugin's internal dataflow
* Added detailed step-by-step documentation on indexing and search processes
* Implemented automatic GitHub updates using YahnisElsts/plugin-update-checker library
* Added acknowledgement for third-party libraries used
* Improved README documentation with more thorough explanations of architecture
* Enhanced code organization and comments for better developer understanding
* Simplified plugin update process with direct GitHub integration

= 0.1.1 =
* Clear search results cache when reindexing, saving, updating or deleting posts

= 0.1.0 =
* Added new WP_Loupe_Schema_Manager class for schema configurations
* Added methods for indexable, filterable, and sortable fields
* Added prepare_document method in WP_Loupe_Indexer
* Enhanced reindex_all method with prepare_document
* Improved search method in WP_Loupe_Search with schema-based fields
* Added search results caching for better performance
* Updated create_post_objects method for efficient post fetching
* Added schema customization documentation in README.md

= 0.0.31 =
* Update readme.txt

= 0.0.30 =
* Added post type selection in settings page
* Added support for all public post types in search
* Added default selection of 'post' and 'page' for new installations
* Improved settings UI with Select2 dropdown
* Updated post type handling in search index

= 0.0.20 =
* Fix Typo in class-wp-loupe-loader.php

= 0.0.19 =
* Changed: Update dependencies

= 0.0.18 =
* Fixed return value in posts_pre_query to return null instead of posts for better WP Core integration

= 0.0.17 =
* Added wp_loupe_posts_per_page filter hook for customizing posts per page
* Added PHPDoc blocks for all class properties in search class
* Improved code documentation for all method parameters
* Enhanced error handling in database operations

= 0.0.16 =
* Added proper documentation to WP_Loupe_Search class
* Added missing PHPDoc blocks for class properties
* Fixed PHPCS warnings related to comment formatting
* Fixed inline documentation for better code readability

= 0.0.15 =
* Added pagination support for search results
* Added total found posts and max pages calculation
* Added proper handling of posts per page setting
* Improved search query interception logic
* Enhanced performance for large result sets

= 0.0.14 =
* Fixed problem with reindexing all posts and pages from the admin interface

= 0.0.13 =

* Improved search results handling for custom post types
* Enhanced post object creation with proper post type support

= 0.0.12 =
* Added comprehensive documentation to all classes and methods
* Added proper DocBlocks following WordPress coding standards
* Improved code documentation across all files

= 0.0.11 =
* Added trait for sharing Loupe instance creation between classes
* Updated field names to match WordPress post field names
* Fixed search results handling and post object creation

= 0.0.10 =
* Performance: Reduced search attributes to only retrieve essential fields
* Performance: Removed content from sortable attributes
* Performance: Removed highlighting feature
* Fixed: Typo in search query variable
* Fixed: Code style improvements for better maintainability

= 0.0.1 - 0.0.5 =
Development version, do not use in production.