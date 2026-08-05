=== Loupe Search ===
Contributors: PerS
Tags: search, full-text search, typo-tolerant, fast search, SQLite
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 8.3
Stable tag: 1.2.5
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
* 🔀 OR search: `term1 term2` finds content with either term
* 📖 Pagination support
* Stemming support
* Stop words recognition
* Highlighting and cropped snippets via the REST API

= Administration =

* Simple settings interface
* Post type selection
* Field configuration options
* One-click reindexing
* Processing time monitoring

= Developer Features =

* 🛠️ Extensive filter system for customization
* 📊 Performance monitoring and diagnostics
* 🔧 Customizable indexing
* Field weighting control

See the [Search API documentation](https://github.com/soderlind/loupe-search/blob/main/docs/search-api.md) for REST endpoint details.

= AI Agent Integration (WordPress Abilities API) =

Loupe Search registers two abilities via the WordPress Abilities API (WordPress 6.9+) so AI agents and automation tools can discover and use search functionality natively:

* `loupe-search/search` — Typo-tolerant full-text search across indexed post types. Supports phrase matching, exclusion, and OR operators. Publicly accessible.
* `loupe-search/get-post` — Retrieve a single published post by ID. Publicly accessible.

These abilities are discoverable through the standard WordPress Abilities registry and require no additional configuration. As of 1.2.0 the ability namespace is `loupe-search`; the legacy `wp-loupe/search` and `wp-loupe/get-post` abilities still work but are deprecated aliases.

[Upgrading from the experimental MCP server?](https://github.com/soderlind/loupe-search/blob/main/docs/migration-mcp-to-abilities.md) The MCP server, token service, and WP-CLI token commands were removed in 0.8.5. Use the Abilities API endpoints described above instead; no configuration migration is required.

= Filters =

These filters allow developers to customize Loupe Search's behavior. As of 1.1.0 they use the `loupe_search_*` prefix; the old `wp_loupe_*` names still work but are deprecated and will be removed in a future major release.

`loupe_search_db_path`

Controls where the search index is stored.
Default: WP_CONTENT_DIR . '/loupe-search-db'

`loupe_search_post_types`

Modifies which post types are included in search.
Default: the post types selected on the settings screen, falling back to ['post', 'page']

`loupe_search_posts_per_page`

Controls search results per page.
Default: WordPress "Blog pages show at most" setting

`loupe_search_index_protected`

Controls indexing of password-protected posts. Receives false for protected posts and true for everything else; return true to index them anyway.

`loupe_search_field_{$field_name}`

Modifies a core post field before indexing. Does not fire for custom fields.
Example: The plugin uses `loupe_search_field_post_content` to strip HTML tags from content

`loupe_search_schema_{$post_type}`

Customizes the schema for a post type: which fields are indexed, their weight, and whether they are filterable or sortable.

`loupe_search_is_safely_sortable_{$post_type}`

Overrides whether a core post field may be used for sorting.

`loupe_search_is_safely_sortable_meta_{$post_type}`

Overrides whether a custom field may be used for sorting.

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
4. Contextual help tabs explaining field configuration options and search behavior settings.

== Frequently Asked Questions ==

= How does it handle updates to posts? =

The search index automatically updates when content is created, modified, or deleted.

= Will it slow down my site? =

No. Loupe Search uses a separate, optimized search index and doesn't impact your main database performance.

= Can I customize what content is searchable? =

Yes, using filters you can control exactly what content gets indexed and how it's searched.

= Does it work with custom post types? =

Yes, you can select which post types to include in the Settings page or via filters.

= How do I reindex on large sites? =

Use Settings > Loupe Search > Reindex (batched), or run via WP-CLI:

* `wp loupe-search reindex`
* `wp loupe-search reindex --post-types=post,page --batch-size=1000`

= How do I use advanced search operators? =

* `Hello World` will search for posts containing `Hello` **or** `World`.
* `"Hello World"` will search for posts containing the exact phrase `Hello World`.
* `Hello -World` will search for posts containing `Hello` but not `World`.

= What are the technical requirements? =

* PHP 8.3 or higher
* SQLite 3.35+ (required by Loupe)
* PHP extensions: pdo_sqlite, intl, mbstring
* WordPress 6.9+


== Changelog ==

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