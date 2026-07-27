# Loupe Search - Enhanced WordPress Search

A search enhancement plugin for WordPress that builds a fast, typo-tolerant index and exposes a [developer-friendly API](https://github.com/soderlind/loupe-search/blob/main/docs/search-api.md) so you can build your own search UI. **Loupe Search works out of the box with WordPress’s standard search.**

> **Renamed from WP Loupe.** Loupe Search is the successor to the **WP Loupe** plugin. Existing installs upgrade seamlessly: the legacy `wp-content/wp-loupe-db` index folder is reused automatically, and the deprecated `wp_loupe_*` filters, `wp-loupe/v1` REST namespace, and `wp wp-loupe` CLI command still work (with deprecation warnings) until a future major release. See the [Changelog](CHANGELOG.md) for the full list of renamed hooks and endpoints.

## Quick Links

[Installation](#installation) | [REST API](#rest-api) | [AI Agent Integration](#ai-agent-integration-wordpress-abilities-api) | [Building Your Own Search UI](#building-your-own-search-ui) | [Settings](#settings) | [Filters](#filters) | [Changelog](CHANGELOG.md)


## Overview

Loupe Search transforms WordPress's search functionality by:

- Creating a dedicated search index for lightning-fast results
- Supporting typo-tolerant searches
- Automatically maintaining the search index
- Providing a stable REST API for custom search experiences

> Integrating with AI agents or automation? Loupe Search registers native abilities via the [WordPress Abilities API](#ai-agent-integration-wordpress-abilities-api).

## REST API

Loupe Search exposes search via REST endpoints:

- **POST** `/wp-json/loupe-search/v1/search` (recommended; supports JSON filters, facets, geo, explicit sorting, and search-result highlighting/snippets)
- **GET** `/wp-json/loupe-search/v1/search?q=...` (legacy; kept for backward compatibility)

> **Note:** As of 1.1.0 the REST namespace is `loupe-search/v1`. The old `wp-loupe/v1` namespace still works but is deprecated and will be removed in a future major release.

Developer documentation (schema + examples + Gutenberg block example): **[docs/search-api.md](docs/search-api.md)**

## AI Agent Integration (WordPress Abilities API)

Loupe Search registers two abilities via the [WordPress Abilities API](https://developer.wordpress.org/) (WordPress 6.9+) so AI agents and automation tools can discover and use search functionality natively — no extra configuration required:

- `loupe-search/search` — Typo-tolerant full-text search across indexed post types. Supports phrase matching, exclusion, and OR operators. Publicly accessible.
- `loupe-search/get-post` — Retrieve a single published post by ID. Publicly accessible.

Both abilities are discoverable through the standard WordPress Abilities registry and exposed via REST (`show_in_rest`).

> **Note:** As of 1.2.0 the ability namespace and category are `loupe-search`. The legacy `wp-loupe/search` and `wp-loupe/get-post` abilities (category `wp-loupe`) still work but are deprecated aliases and will be removed in a future major release.

> **Upgrading from the MCP server?** The experimental MCP server, token service, and WP-CLI token commands were removed in 0.8.5. See the **[MCP → Abilities API migration guide](docs/migration-mcp-to-abilities.md)**.

## Features

- Fast index-backed search for configured post types
- Typo-tolerance (Loupe)
- Per-field weighting, filterable fields, sortable fields (configured in Settings)
- Developer-facing REST API for building custom UIs
- Native AI agent integration via the WordPress Abilities API

## Technical Requirements

- PHP 8.1 or higher
- SQLite 3.35+ (required by Loupe 0.13.x)
- PHP extensions: `pdo_sqlite`, `intl`, `mbstring`
- WordPress 6.9+

> **About the PHP extensions:** `pdo_sqlite` provides the SQLite driver Loupe uses to store and query the search index; `intl` powers locale-aware tokenizing, collation, and typo tolerance; and `mbstring` ensures multibyte (UTF-8) text is handled correctly during indexing and search. All three are required, but **they are enabled by default on most modern PHP installations and shared hosts**, so no action is usually needed. If your host is missing one, contact them or enable it in your PHP configuration.



## Installation

1. **Install from WordPress.org**

   - In your WordPress admin, go to Plugins > Add New
   - Search for "Loupe Search"
   - Click "Install Now", then "Activate"

2. **Quick Install**

   - Download [`loupe-search.zip`](https://github.com/soderlind/loupe-search/releases/latest/download/loupe-search.zip)
   - Upload via WordPress Plugins > Add New > Upload Plugin

3. **Composer Install**

   ```bash
   composer require soderlind/loupe-search
   ```

4. **Post-Installation**
   - Activate the plugin
   - Go to Settings > Loupe Search
	- Click "Reindex" to build the initial search index (runs in batches; safe for large sites)



## Building Your Own Search UI

**Loupe Search works out of the box with WordPress’s standard search.**
If your theme uses the normal search flow (e.g. a search form that routes to the built-in search results page), Loupe Search will power the results automatically — no custom UI required.

Loupe Search intentionally does **not** ship a front-end search block/shortcode UI.
If you want a custom search experience (autocomplete, filters/facets, geo, custom sorting, etc.), build the UI you want and query Loupe Search via the REST API.

Start here: **[docs/search-api.md](docs/search-api.md)**

## Settings

You can configure Loupe Search's search behavior and performance via the WordPress admin: Settings > Loupe Search.


### General Settings

#### Post Types
Select which post types to include in the search index.

#### Field Weight
Weight determines how important a field is in search results:

- Higher weight (e.g., 2.0) makes matches in this field more important in results ranking.
- Default weight is 1.0.
- Lower weight (e.g., 0.5) makes matches less important but still searchable.

#### Filterable Fields
Filterable fields can be used to refine search results:

- Enable this option to allow filtering search results by this field's values.
- Best for fields with consistent, categorized values like taxonomies, status fields, or controlled metadata.
- Examples: categories, tags, post type, author, or custom taxonomies.

Note: Fields with highly variable or unique values (like content) make poor filters as each post would have its own filter value.


#### Sortable Fields
Sortable fields can be used to order search results:

- Enable this option to allow sorting search results by this field's values
- Works best with numerical fields, dates, or short text values
- Examples: date, price, rating, title

### Advanced Settings

Loupe Search provides advanced configuration options to fine-tune your search experience:

#### Prefix Search

- Configure prefix search behavior. Prefix search allows finding terms by typing only the beginning (e.g., "huck" finds "huckleberry").
- Prefix search is only performed on the last word in a search query. Prior words must be typed out fully to get accurate results. E.g. `my friend huck` would find documents containing huckleberry - `huck is my friend`, however, would not.

#### Typo Tolerance

- **Enable Typo Tolerance**: When enabled, searches will match terms with minor spelling errors.
- **First Character Double Counting**: When enabled, typos in the first character of a word will count as two errors instead of one.
- **Typo Tolerance for Prefix Search**: Allows typo tolerance in partial word searches.
- **Alphabet Size**: Define the size of the alphabet for typo calculations.
- **Index Length**: Configure the maximum length of indexed terms.
- **Typo Thresholds**: Set the minimum word length required for allowing different numbers of typos.

#### Query Parameters

- **Maximum Query Tokens**: Limits the number of words processed in a search query (default: 12).
- **Minimum Prefix Length**: Sets the minimum character length before prefix search activates (default: 3).

#### Languages

- Configure which languages the search index should optimize for. Default is English ('en').

These advanced settings can be accessed in the WordPress admin under Settings > Loupe Search > Advanced tab.

## Reindexing

Reindexing rebuilds the index for your configured post types.

- **Admin UI:** Settings → Loupe Search → click **Reindex** (runs in batches to avoid timeouts)
- **WP-CLI (recommended for large sites):**

	```bash
	wp loupe-search reindex
	```

	Optional flags:

	```bash
	wp loupe-search reindex --post-types=post,page --batch-size=1000
	```

## Testing

- PHPUnit:

	```bash
	composer test
	```

- Pest (runs using the PHPUnit config):

	```bash
	composer test:pest
	```



## FAQ

### How does it handle updates to posts?

The search index automatically updates when content is created, modified, or deleted.

### Will it slow down my site?

No. Loupe Search uses a separate, optimized search index and doesn't impact your main database performance.

### Can I customize what content is searchable?

Yes, using filters you can control exactly what content gets indexed and how it's searched.

### Does it work with custom post types?

Yes, you can select which post types to include in the Settings page or via filters.

## Filters

> **Note:** As of 1.1.0 the developer filters use the `loupe_search_*` prefix. The old `wp_loupe_*` names still work but are deprecated and will be removed in a future major release. Update your `add_filter()` calls to the new names.

### `loupe_search_db_path`

This filter allows you to change the path where the Loupe database files are stored. By default, it's in the `WP_CONTENT_DIR .'/loupe-search-db'` directory.

```php
add_filter( 'loupe_search_db_path', function ( $path ) {
	return WP_CONTENT_DIR . '/my-path';
} );
```

### `loupe_search_post_types`

This filter allows you to modify the array of post types that the Loupe Search plugin works with. By default, it includes 'post' and 'page'.

```php
add_filter( 'loupe_search_post_types', [ 'post', 'page', 'book' ] );
```

### `loupe_search_posts_per_page`

This filter allows you to modify the number of search results per page. By default it's 10, set in `WPAdmin->Settings->Reading->"Blog pages show at most"`.

```php
add_filter( 'loupe_search_posts_per_page', 20 );
```

### `loupe_search_index_protected`

This filter allows you to index posts and pages that are protected by a password. By default, it's set to `false`.

```php
add_filter( 'loupe_search_index_protected','__return_true' );
```

### `loupe_search_field_{$field_name}`

This filter allows you to change the field content before it is indexed.

By default, the following is used to remove HTML tags and comments from `post_content`. Among others, it removes the WordPress block comments.

```php
add_filter( 'loupe_search_field_post_content', 'wp_strip_all_tags' );
```

### `loupe_search_schema_{$post_type}`

Modify the search schema for a specific post type. The filter name is dynamically generated based on the post type.

```php
// Customize the schema for 'book' post type
add_filter( 'loupe_search_schema_book', function( $schema ) {
	$schema['book_isbn'] = [		// Add a new field
		'weight'     => 2.0,		// Higher weight means higher relevance in search results
		'filterable' => true,		// Allow filtering by this field
		'sortable'   => [			// Allow sorting by this field
			'direction' => 'asc'	// Default sort direction
		],
	];

	// Modify existing field settings
	$schema['post_title']['weight'] = 3.0; // Increase title weight for books

	// Remove a field
	unset( $schema['post_excerpt'] );


	$schema['book_author'] = [
		'weight'     => 1.5,
		'filterable' => true,
		'sortable'   => [ 'direction' => 'asc' ],
	];

	return $schema;
});
```

The schema configuration supports the following options for each field:

- `weight` (float): The relevance weight in search results. Default: 1.0
- `filterable` (bool): Whether the field can be used for filtering. Default: false
- `sortable` (array): Sorting configuration with `direction` key ('asc' or 'desc'). Default: null

Default schema fields:

```php
[
	'post_title'   => [
		'weight'     => 2,
		'filterable' => true,
		'sortable'   => [ 'direction' => 'asc' ],
	],
	'post_content' => [ 'weight' => 1.0],
	'post_excerpt' => [ 'weight' => 1.5 ],
	'post_date'    => [
		'weight'     => 1.0,
		'filterable' => true,
		'sortable'   => [ 'direction' => 'desc' ],
	],
	'post_author'  => [
		'weight'     => 1.0,
		'filterable' => true,
		'sortable'   => [ 'direction' => 'asc' ],
	],
	'permalink'    => [ 'weight' => 1.0 ],
]
```


## Acknowledgements

- Loupe Search is built upon [Loupe](https://github.com/loupe-php/loupe/). Loupe is licensed under the MIT license.

## Copyright and License

Loupe Search is copyright © 2024 [Per Søderlind](http://github.com/soderlind).

Loupe Search is open-source software; you can redistribute it and/or modify it under the terms of the GNU General Public License, version 2, as published by the Free Software Foundation.

Loupe Search is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See [LICENSE](LICENSE) for more information.

<!--
TOC MAINTENANCE
The Table of Contents near the top of this file is maintained manually (no automated script in build pipeline).
Update procedure when headings change:
1. Identify new/renamed/removed headings at levels ## and important ### subsections.
2. Derive anchors (GitHub algorithm: lowercase, spaces -> dashes, remove most punctuation).
3. Insert/update list items inside the <!-- TOC BEGIN --> / <!-- TOC END --> block.
4. Keep indentation with tabs (current style) or convert uniformly if you restyle the list.
5. Avoid adding very small, single-sentence subsections to keep TOC scannable.
-->
