# Filters

Loupe Search exposes nine filters for changing what gets indexed, how it is
weighted, and how results are returned.

> Filters use the `loupe_search_*` prefix. The old `wp_loupe_*` names still work
> as deprecated aliases — see [Renamed from WP Loupe](renamed-from-wp-loupe.md).

## Contents

1. [Where to put filter code](#where-to-put-filter-code)
2. [Quick reference](#quick-reference)
3. [Indexing scope](#indexing-scope)
   - [`loupe_search_post_types`](#loupe_search_post_types)
   - [`loupe_search_index_protected`](#loupe_search_index_protected)
   - [`loupe_search_db_path`](#loupe_search_db_path)
4. [Field content](#field-content)
   - [`loupe_search_field_{$field_name}`](#loupe_search_field_field_name)
5. [Schema](#schema)
   - [`loupe_search_schema_{$post_type}`](#loupe_search_schema_post_type)
6. [Sortability](#sortability)
   - [`loupe_search_is_safely_sortable_{$post_type}`](#loupe_search_is_safely_sortable_post_type)
   - [`loupe_search_is_safely_sortable_meta_{$post_type}`](#loupe_search_is_safely_sortable_meta_post_type)
7. [Results](#results)
   - [`loupe_search_posts_per_page`](#loupe_search_posts_per_page)
   - [`loupe_search_max_cacheable_query_length`](#loupe_search_max_cacheable_query_length)
8. [Recipes](#recipes)
9. [After changing a filter](#after-changing-a-filter)

## Where to put filter code

Put these in a small **mu-plugin** (`wp-content/mu-plugins/loupe-search-tweaks.php`)
rather than a theme's `functions.php`. Indexing also runs from WP-CLI and from
`wp-cron`, where the active theme may not be loaded — a filter that only exists
in `functions.php` can therefore apply on the front end but silently not apply
during a CLI reindex, producing an index that disagrees with your settings.

Filters that affect indexing must be registered before indexing runs, so
register them at load time (not inside an `init` callback that runs late).

## Quick reference

| Filter | Passed | Use it to |
| --- | --- | --- |
| `loupe_search_post_types` | `array $post_types` | Choose which post types are indexed |
| `loupe_search_index_protected` | `bool $should_index` | Index password-protected posts |
| `loupe_search_db_path` | `string $path` | Move the index directory |
| `loupe_search_field_{$field_name}` | `mixed $value` | Clean a core post field before indexing |
| `loupe_search_schema_{$post_type}` | `array $schema` | Add/remove fields, set weights and sorting |
| `loupe_search_is_safely_sortable_{$post_type}` | `bool $sortable`, `string $field_name` | Force a core field to be sortable |
| `loupe_search_is_safely_sortable_meta_{$post_type}` | `bool $sortable`, `string $field_name` | Correct meta-field sortability detection |
| `loupe_search_posts_per_page` | `int $per_page` | Change results per page |
| `loupe_search_max_cacheable_query_length` | `int $max_length` | Change which queries are written to the result cache |

## Indexing scope

### `loupe_search_post_types`

Which post types are indexed. Defaults to the post types selected on the
settings screen, falling back to `[ 'post', 'page' ]`.

```php
add_filter( 'loupe_search_post_types', function ( array $post_types ): array {
	$post_types[] = 'book';
	return $post_types;
} );
```

Append rather than replace unless you intend to override the settings screen —
returning a hard-coded array makes the admin checkboxes have no effect.

### `loupe_search_index_protected`

Whether the current post should be indexed. The value passed in is
`empty( $post->post_password )`, so password-protected posts arrive as `false`
and everything else as `true`.

```php
// Index password-protected posts too.
add_filter( 'loupe_search_index_protected', '__return_true' );
```

The filter receives no post object, so it cannot make a per-post decision —
it is an on/off switch. Note that indexed protected posts become findable by
their content, which is usually not what a password is for.

### `loupe_search_db_path`

Where the Loupe index files live. Defaults to `WP_CONTENT_DIR . '/loupe-search-db'`.

```php
add_filter( 'loupe_search_db_path', function ( string $path ): string {
	return WP_CONTENT_DIR . '/uploads/loupe-search-db';
} );
```

Use an absolute path that PHP can write to, and exclude it from version control.
Changing this after indexing points the plugin at an empty directory — reindex
afterwards.

## Field content

### `loupe_search_field_{$field_name}`

Transforms a field's value just before it is indexed. `{$field_name}` is the
field name, e.g. `loupe_search_field_post_content`.

```php
// Strip HTML (including block comments) from post_content before indexing.
add_filter( 'loupe_search_field_post_content', 'wp_strip_all_tags' );
```

**This only fires for properties that exist on the `WP_Post` object** —
`post_content`, `post_title`, `post_excerpt`, and so on. It does *not* fire for
custom fields or taxonomy values, so `loupe_search_field_my_meta_key` will never
run. To change how meta is indexed, use the schema filter.

Return a string or other scalar; returning an array will not index usefully.

## Schema

### `loupe_search_schema_{$post_type}`

The schema controls which fields are indexed and how they behave. The array you
receive is the baseline schema merged with whatever is configured on the
settings screen, so read before you write.

Each field accepts:

| Key | Type | Default | Meaning |
| --- | --- | --- | --- |
| `weight` | float | `1.0` | Relevance multiplier in search results |
| `indexable` | bool | `false` | Include the field in the index |
| `filterable` | bool | `false` | Allow filtering and terms facets on the field |
| `sortable` | bool | `false` | Allow sorting by the field |
| `sort_direction` | string | `'desc'` | Default direction, `'asc'` or `'desc'` |

`sortable` is also gated by the sortability checks below — setting it to `true`
on a field that holds arrays will be overridden.

```php
add_filter( 'loupe_search_schema_book', function ( array $schema ): array {
	// Add a custom field.
	$schema['book_isbn'] = [
		'weight'     => 2.0,
		'indexable'  => true,
		'filterable' => true,
	];

	// Make books sort by publication date, newest first.
	$schema['book_published'] = [
		'weight'         => 1.0,
		'indexable'      => true,
		'sortable'       => true,
		'sort_direction' => 'desc',
	];

	// Boost titles for this post type.
	$schema['post_title']['weight'] = 3.0;

	// Stop indexing excerpts.
	unset( $schema['post_excerpt'] );

	return $schema;
} );
```

Only `post_date` is present before settings are applied:

```php
[
	'post_date' => [
		'weight'         => 1.0,
		'indexable'      => true,
		'filterable'     => true,
		'sortable'       => true,
		'sort_direction' => 'desc',
	],
]
```

Every other field — `post_title`, `post_content`, `post_excerpt`, `post_author`,
`permalink`, and any custom fields — is added from the settings screen. Do not
assume a field exists in `$schema`; check first when modifying:

```php
add_filter( 'loupe_search_schema_post', function ( array $schema ): array {
	if ( isset( $schema['post_title'] ) ) {
		$schema['post_title']['weight'] = 4.0;
	}
	return $schema;
} );
```

## Sortability

Before a field is registered as sortable, the plugin checks that its values are
scalar — Loupe cannot sort on arrays. These two filters let you override that
verdict.

### `loupe_search_is_safely_sortable_{$post_type}`

Runs for **core post fields** that are not already classified as sortable or
non-sortable. Receives `false` by default plus the field name.

```php
add_filter( 'loupe_search_is_safely_sortable_book', function ( bool $sortable, string $field_name ): bool {
	return 'menu_order' === $field_name ? true : $sortable;
}, 10, 2 );
```

### `loupe_search_is_safely_sortable_meta_{$post_type}`

Runs for **meta fields**. The incoming value comes from sampling the meta value
of up to five posts: `true` if all sampled values were scalar (or geo-points).

That sample can be wrong. If only the sixth post stores an array, the field is
wrongly marked sortable and sorting misbehaves; if the first five posts have no
value, a perfectly sortable field is marked unsortable. Use this filter to state
the answer explicitly:

```php
add_filter( 'loupe_search_is_safely_sortable_meta_book', function ( bool $sortable, string $field_name ): bool {
	// This one is always a single number, regardless of what sampling found.
	if ( 'book_price' === $field_name ) {
		return true;
	}

	// This one sometimes holds an array — never sort on it.
	if ( 'book_contributors' === $field_name ) {
		return false;
	}

	return $sortable;
}, 10, 2 );
```

## Results

### `loupe_search_posts_per_page`

Number of results per page for front-end searches. Defaults to the query's
`posts_per_page`, falling back to **Settings → Reading → "Blog pages show at
most"**.

```php
add_filter( 'loupe_search_posts_per_page', function ( int $per_page ): int {
	return 20;
} );
```

This affects the WordPress search loop only. The REST API takes its page size
from the request — see [Search API](search-api.md).

### `loupe_search_max_cacheable_query_length`

Maximum query length, in bytes, that is written to the result cache. Defaults
to **128**. Longer queries still run, they are just not cached.

Search is reachable without authentication, so every distinct query would
otherwise be able to create a transient. The bound keeps a visitor from filling
the options table with long throwaway queries.

```php
add_filter( 'loupe_search_max_cacheable_query_length', function ( int $max_length ): int {
	return 256;
} );
```

Return `0` to disable result caching entirely.

## Recipes

### Index a custom post type with its custom fields

```php
add_filter( 'loupe_search_post_types', function ( array $post_types ): array {
	$post_types[] = 'recipe';
	return $post_types;
} );

add_filter( 'loupe_search_schema_recipe', function ( array $schema ): array {
	$schema['recipe_ingredients'] = [
		'weight'     => 2.0,
		'indexable'  => true,
		'filterable' => true,
	];
	$schema['recipe_time'] = [
		'weight'         => 1.0,
		'indexable'      => true,
		'filterable'     => true,
		'sortable'       => true,
		'sort_direction' => 'asc',
	];
	return $schema;
} );

add_filter( 'loupe_search_is_safely_sortable_meta_recipe', function ( bool $sortable, string $field_name ): bool {
	return 'recipe_time' === $field_name ? true : $sortable;
}, 10, 2 );
```

### Strip shortcodes as well as HTML from indexed content

```php
add_filter( 'loupe_search_field_post_content', function ( $value ) {
	return wp_strip_all_tags( strip_shortcodes( (string) $value ) );
} );
```

### Keep a post type out of the index temporarily

```php
add_filter( 'loupe_search_post_types', function ( array $post_types ): array {
	return array_values( array_diff( $post_types, [ 'page' ] ) );
} );
```

## After changing a filter

Filters that affect **what** or **how** content is indexed — post types, schema,
field content, sortability, protected posts — only take effect for content
indexed afterwards. Reindex so the existing index matches:

```bash
wp loupe-search reindex
```

Filters that only affect reading, such as `loupe_search_posts_per_page`, take
effect immediately.
