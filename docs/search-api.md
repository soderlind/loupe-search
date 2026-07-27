# Loupe Search API (Developer Guide)

Loupe Search exposes a REST API that lets you build your own search UI (theme template, JS widget, Gutenberg block, React app, etc.) on top of the same index Loupe Search uses internally.

This guide is written to be read top-to-bottom the first time: start with **Quick start**, then add one capability at a time in **Building up a search**. Use **Reference** once you know what you need.

## Contents

1. [Quick start](#quick-start) — first working request in a few minutes
2. [Core concepts](#core-concepts) — endpoints, permissions, index readiness, allowlisting
3. [Building up a search](#building-up-a-search) — pagination, filters, sorting, facets, highlighting, geo
4. [Worked examples](#worked-examples) — Gutenberg block and server-side PHP
5. [Preparing fields](#preparing-fields-schema-hooks) — schema hooks for filter/sort/facet/geo fields
6. [Reference](#reference) — full request/response schema, filter AST, errors

## Quick start

### Step 1 — Prerequisites

- WordPress 6.9+ and Loupe Search activated.
- At least one post type selected in **Settings → Loupe Search**.
- A built index. If you have never indexed, run:

```sh
wp loupe-search reindex
```

### Step 2 — Confirm the index is ready

Search fails with HTTP 400 if nothing is indexed, so check first. This endpoint requires an administrator (`manage_options`), so run it logged in:

```sh
curl -s https://example.test/wp-json/loupe-search/v1/index-status \
  --user 'admin:application-password'
```

```json
{
  "items": [
    { "postType": "post", "label": "Posts", "ready": true, "reason": null, "published": 42 },
    { "postType": "page", "label": "Pages", "ready": false, "reason": "missing", "published": 7 }
  ]
}
```

Every post type you intend to search should have `"ready": true`. If not, reindex.

### Step 3 — Your first search

The smallest possible request is a `q` and nothing else. Searching is public — no authentication needed:

```sh
curl -s https://example.test/wp-json/loupe-search/v1/search \
  -H 'Content-Type: application/json' \
  -d '{ "q": "wordpress" }'
```

### Step 4 — Read the response

```json
{
  "hits": [
    {
      "id": 123,
      "post_type": "post",
      "post_type_label": "Post",
      "title": "Getting started with WordPress",
      "excerpt": "…",
      "url": "https://example.test/getting-started",
      "_score": 12.345
    }
  ],
  "pagination": { "total": 42, "per_page": 10, "current_page": 1, "total_pages": 5 },
  "tookMs": 8
}
```

That is enough to render a results list: loop `hits`, link `url`, print `title`. Everything that follows is additive — you opt into filters, facets, and highlighting only when you need them.

## Core concepts

### Endpoints

| Method | Route | Access | Purpose |
| --- | --- | --- | --- |
| POST | `/wp-json/loupe-search/v1/search` | Public | **Recommended.** JSON filters, facets, geo, sorting, highlighting |
| GET | `/wp-json/loupe-search/v1/search?q=…` | Public | Legacy. Query only, no filters/facets/geo |
| GET | `/wp-json/loupe-search/v1/index-status` | `manage_options` | Per-post-type index health |

### Authentication & permissions

- **Search is public.** Both search endpoints use `__return_true` as their permission callback, so anonymous visitors can query them. They only ever return **published** content.
- **Admin endpoints** (`/index-status`, `/post-type-fields/…`, `/create-database`, `/delete-database`) require the `manage_options` capability.
- **From the browser**, use `@wordpress/api-fetch`, which attaches the REST nonce automatically. With plain `fetch()` you do not need a nonce for search, but send `X-WP-Nonce` if you want the request to run as the logged-in user.
- **From outside WordPress**, use an [application password](https://developer.wordpress.org/rest-api/reference/application-passwords/) for the admin endpoints.

### Index readiness

Search requires a ready index per post type.

- When `postTypes: "all"` is used, Loupe Search only searches post types that have a ready index.
- If **no** configured post type has a ready index, the API returns **HTTP 400** (`wp_loupe_no_indexed_post_types`).
- If you name a post type explicitly and its index is missing or stale, you get `wp_loupe_index_missing` or `wp_loupe_index_needs_reindex`.

### Allowlisted fields

Filtering, sorting, facets, and geo operations are restricted to fields that are explicitly enabled in **Settings → Loupe Search**.

- Filter fields must be enabled as **Filterable**
- Sort fields must be enabled as **Sortable**
- Facet fields must be enabled as **Filterable** (terms facet)
- Geo requires a dedicated geo-point field:
  - Geo radius filtering requires the field to be **Filterable**
  - Geo distance sorting (`geo.sort`) requires the field to be **Sortable**

If you request an operation on a non-allowlisted field, the API returns **HTTP 400** (`wp_loupe_unallowlisted_field`) with the offending field in `data.details.field`.

> Need to enable a field that is not in the UI, or set this up in code? See [Preparing fields](#preparing-fields-schema-hooks).

## Building up a search

Each step below adds one capability to the request from Quick start. All of them are optional and can be combined.

### Add pagination

```json
{ "q": "wordpress", "page": { "number": 2, "size": 20 } }
```

- `size` must be 1–100 (default 10); `number` is 1-based (default 1).
- Results are capped at **1000** total scanned documents: `(number - 1) × size + size` must stay ≤ 1000. Deeper requests return `wp_loupe_pagination_limit`.
- Use `pagination.total_pages` from the response to render a pager.

### Restrict to post types

```json
{ "q": "wordpress", "postTypes": [ "post", "product" ] }
```

Omit `postTypes` (or use `"all"`) to search every post type that has a ready index.

### Filter results

Filters use a small JSON tree (see [Filter AST](#filter-ast-json) for the full grammar). Start with one predicate:

```json
{
  "q": "wordpress",
  "filter": { "type": "pred", "field": "category", "op": "eq", "value": "news" }
}
```

Combine predicates with `and` / `or` / `not`:

```json
{
  "q": "wordpress",
  "filter": {
    "type": "and",
    "items": [
      { "type": "pred", "field": "category", "op": "in", "value": [ "news", "events" ] },
      { "type": "pred", "field": "post_date", "op": "gte", "value": "2025-01-01" }
    ]
  }
}
```

> **Gotcha:** every `field` here must be allowlisted as **Filterable**, or the request fails with `wp_loupe_unallowlisted_field`.

### Sort results

```json
{
  "q": "wordpress",
  "sort": [ { "by": "_score", "order": "desc" }, { "by": "post_date", "order": "desc" } ]
}
```

- Sorting is applied in order, so the example sorts by relevance and breaks ties by date.
- `_score` is always available. Any other field must be allowlisted as **Sortable**.
- Omitting `sort` sorts by relevance.

### Add facets

Facets return counts per value so you can build filter UI. Only **terms** facets are supported:

```json
{
  "q": "wordpress",
  "facets": [ { "type": "terms", "field": "category", "size": 10, "minCount": 1 } ]
}
```

The response gains a `facets` object:

```json
{
  "facets": {
    "category": {
      "type": "terms",
      "buckets": [ { "value": "news", "count": 12 }, { "value": "events", "count": 4 } ]
    }
  }
}
```

A typical UI renders each bucket as a checkbox, then sends the checked values back as an `in` filter on the same field.

### Highlight matches and build snippets

Highlighting is **opt-in**: ask for it and each hit gains a `_formatted` object.

```json
{
  "q": "wordpress",
  "attributesToHighlight": [ "post_title", "post_content" ],
  "highlightStartTag": "<mark>",
  "highlightEndTag": "</mark>",
  "attributesToCrop": [ "post_content" ],
  "cropLength": 30
}
```

```json
{
  "_formatted": {
    "post_title": "Getting started with <mark>WordPress</mark>",
    "post_content": "…install <mark>WordPress</mark> and pick a theme…"
  }
}
```

> **Gotcha:** `_formatted` keys are the underlying Loupe field names (`post_title`, `post_content`), **not** the friendly top-level keys (`title`, `excerpt`). Read `hit._formatted.post_title` and fall back to `hit.title`.

Details:

- Use `["*"]` to cover every indexed field. Unknown field names are dropped silently.
- Start/end tags are sanitized to an allowlist of inline tags (`mark`, `em`, `strong`, `span`, `b`, `i`, each allowing `class`), so the API never emits script-capable markup. Indexed content is stored as plain text, so `_formatted` contains only those tags plus matched text — safe to render.
- `cropLength` defaults to 50 and is clamped to 10–500. `cropMarker` defaults to `…`.

### Search by location

```json
{
  "q": "cafe",
  "geo": {
    "field": "location",
    "near": { "lat": 59.9139, "lon": 10.7522 },
    "radiusMeters": 5000,
    "sort": { "order": "asc" },
    "includeDistance": true
  }
}
```

- `field` must be a geo-point field; `near` is required (`lng` is accepted as an alias for `lon`).
- `radiusMeters` is optional — omit it to sort by distance without filtering.
- `includeDistance: true` adds `_distanceMeters` to each hit.
- Radius filtering needs the field **Filterable**; `geo.sort` needs it **Sortable**.

## Worked examples

### Gutenberg block (client-side search)

A minimal block that searches as you type. Note the **debounce** — without it every keystroke fires a request.

If you build blocks with `@wordpress/scripts` you already have `@wordpress/api-fetch`, `@wordpress/element`, and `@wordpress/components`. Using `apiFetch` also means the REST nonce is attached for you.

```js
import apiFetch from '@wordpress/api-fetch';
import { useEffect, useState } from '@wordpress/element';
import { TextControl, Spinner } from '@wordpress/components';

export default function Edit() {
	const [ query, setQuery ] = useState( '' );
	const [ isLoading, setIsLoading ] = useState( false );
	const [ hits, setHits ] = useState( [] );

	useEffect( () => {
		if ( ! query.trim() ) {
			setHits( [] );
			return;
		}

		const controller = new AbortController();

		// Wait 300ms after the last keystroke before querying.
		const timer = setTimeout( () => {
			setIsLoading( true );

			apiFetch( {
				path: '/loupe-search/v1/search',
				method: 'POST',
				signal: controller.signal,
				data: {
					q: query,
					postTypes: 'all',
					page: { number: 1, size: 10 },
					attributesToHighlight: [ 'post_title' ],
					highlightStartTag: '<mark>',
					highlightEndTag: '</mark>',
				},
			} )
				.then( ( res ) => setHits( res?.hits || [] ) )
				.catch( ( error ) => {
					if ( 'AbortError' !== error?.name ) {
						setHits( [] );
					}
				} )
				.finally( () => setIsLoading( false ) );
		}, 300 );

		// Cancel the pending timer and request when the query changes.
		return () => {
			clearTimeout( timer );
			controller.abort();
		};
	}, [ query ] );

	return (
		<div className="loupe-search-block">
			<TextControl
				label="Search"
				value={ query }
				onChange={ setQuery }
				placeholder="Type to search…"
			/>
			{ isLoading ? <Spinner /> : null }
			<ul>
				{ hits.map( ( hit ) => (
					<li key={ hit.id }>
						<a href={ hit.url }>{ hit.title }</a>
					</li>
				) ) }
			</ul>
		</div>
	);
}
```

> Rendering `_formatted` values requires `dangerouslySetInnerHTML`. That is safe here because the API sanitizes highlight tags and indexes plain text, but only do it with values that came from `_formatted`.

### PHP (server-side call)

```php
$response = wp_remote_post(
	rest_url( 'loupe-search/v1/search' ),
	[
		'headers' => [ 'Content-Type' => 'application/json' ],
		'body'    => wp_json_encode(
			[
				'q'         => 'wordpress',
				'postTypes' => 'all',
				'page'      => [ 'number' => 1, 'size' => 10 ],
			]
		),
	]
);

if ( is_wp_error( $response ) ) {
	return;
}

$body = json_decode( wp_remote_retrieve_body( $response ), true );

foreach ( $body['hits'] ?? [] as $hit ) {
	printf( '<a href="%s">%s</a>', esc_url( $hit['url'] ), esc_html( $hit['title'] ) );
}
```

## Preparing fields (schema hooks)

Loupe Search’s REST API only lets clients **filter/sort/facet/geo** on fields that are enabled in the schema.

Most sites will configure this in **Settings → Loupe Search → Field Settings**.
If you’re building an integration or need to enforce fields programmatically, use the schema hooks below.

### Fields for facets (terms)

Terms facets require the field to be:

- indexed (`indexable: true`)
- allowlisted as filterable (`filterable: true`)
- stored as a string or an array of strings

Example: add a facet field backed by post meta.

```php
// 1) Allowlist the field in the schema.
add_filter( 'loupe_search_schema_post', function ( array $schema ): array {
  $schema['audience'] = [
    'weight'         => 1.0,
    'indexable'      => true,
    'filterable'     => true,  // enables filtering + terms facets
    'sortable'       => false,
    'sort_direction' => 'desc',
  ];
  return $schema;
} );

// 2) Store the value in post meta as string or array of strings.
//    (Arrays become multi-valued facets.)
add_action( 'save_post', function ( int $post_id ) {
  // Example: multi-valued facet.
  update_post_meta( $post_id, 'audience', [ 'beginner', 'developer' ] );
} );
```

### Fields for geo (radius filtering + distance sorting)

Geo requires a dedicated geo-point field stored as an array:

```php
// Stored as post meta:
// [ 'lat' => 59.9139, 'lng' => 10.7522 ]
// (or use 'lon' instead of 'lng')
```

To enable geo features:

- Geo **radius filtering** requires the field to be **Filterable**.
- Geo **distance sorting** (`geo.sort`) requires the field to be **Sortable**.

Example:

```php
// 1) Allowlist the geo field.
add_filter( 'loupe_search_schema_post', function ( array $schema ): array {
  $schema['location'] = [
    'weight'         => 1.0,
    'indexable'      => true,
    'filterable'     => true, // required for geo radius filtering
    'sortable'       => true, // required for geo.sort distance ordering
    'sort_direction' => 'asc',
  ];
  return $schema;
} );

// 2) Store the geo-point in post meta.
add_action( 'save_post', function ( int $post_id ) {
  update_post_meta( $post_id, 'location', [
    'lat' => 59.9139,
    'lng' => 10.7522,
  ] );
} );
```

If your geo field is stored as post meta and you need to override “meta sortability” decisions, you can use:

```php
add_filter( 'loupe_search_is_safely_sortable_meta_post', function ( bool $is_sortable, string $field_name ): bool {
  if ( 'location' === $field_name ) {
    return true;
  }
  return $is_sortable;
}, 10, 2 );
```

### Fields for sorting (non-geo)

Sorting requires the field to be:

- indexed (`indexable: true`)
- allowlisted as sortable (`sortable: true`)
- stored as a scalar (string/number) for post meta fields

Example:

```php
add_filter( 'loupe_search_schema_post', function ( array $schema ): array {
  $schema['rating'] = [
    'weight'         => 1.0,
    'indexable'      => true,
    'filterable'     => false,
    'sortable'       => true,
    'sort_direction' => 'desc',
  ];
  return $schema;
} );

add_action( 'save_post', function ( int $post_id ) {
  update_post_meta( $post_id, 'rating', 4.7 );
} );
```

Note: if you already have data in meta, you typically only need the schema hook + a reindex (Settings → Loupe Search → Reindex, or `wp loupe-search reindex`).

## Reference

### POST /search request body

```json
{
  "q": "search text",
  "postTypes": "all",
  "page": { "number": 1, "size": 10 },
  "filter": {
    "type": "and",
    "items": [
      { "type": "pred", "field": "category", "op": "eq", "value": "news" },
      { "type": "pred", "field": "post_author", "op": "eq", "value": 123 }
    ]
  },
  "sort": [
    { "by": "_score", "order": "desc" },
    { "by": "post_date", "order": "desc" }
  ],
  "facets": [
    { "type": "terms", "field": "category", "size": 10, "minCount": 1 }
  ],
  "geo": {
    "field": "location",
    "near": { "lat": 59.9139, "lon": 10.7522 },
    "radiusMeters": 5000,
    "sort": { "order": "asc" },
    "includeDistance": true
  },
  "attributesToHighlight": [ "post_title", "post_content" ],
  "highlightStartTag": "<mark>",
  "highlightEndTag": "</mark>",
  "attributesToCrop": [ "post_content" ],
  "cropLength": 50,
  "cropMarker": "…"
}
```

#### Top-level properties

- `q` (string, required): the search query.
- `postTypes` (`"all"` | string[], optional, default `"all"`): which post types to search.
  - `"all"` resolves to the subset of configured post types that have a ready index.
- `page.number` (int, optional, default 1): 1-based page.
- `page.size` (int, optional, default 10): page size (1–100).
- `filter` (object, optional): JSON filter AST (see below).
- `sort` (array, optional): sorting instructions.
- `facets` (array, optional): terms facets.
- `geo` (object, optional): geo radius + geo sorting.
- `attributesToHighlight` (string[], optional): field names to wrap matched terms in. Use `["*"]` for every indexed field.
- `highlightStartTag` / `highlightEndTag` (string, optional, default `<em>` / `</em>`): markup wrapped around matches.
- `attributesToCrop` (string[], optional): field names to return as a short snippet around the match. Use `["*"]` for every indexed field.
- `cropLength` (int, optional, default 50, clamped 10–500): approximate snippet length in words.
- `cropMarker` (string, optional, default `…`): marker inserted where text is cropped.

Notes:

- Fields used in `filter`, `sort`, `facets`, and `geo` must be allowlisted in Settings (see **Allowlisted fields** above).
- For geo, `geo.near.lon` is supported; `geo.near.lng` is also accepted for convenience.

#### Highlighting & cropping

See [Highlight matches and build snippets](#highlight-matches-and-build-snippets) for a walkthrough. In short: `_formatted` appears only when `attributesToHighlight` and/or `attributesToCrop` are supplied, unknown field names are dropped, `["*"]` expands to every indexed field, tags are sanitized to an inline allowlist, and `_formatted` keys are Loupe field names (`post_title`) rather than the friendly keys (`title`).

### Response

```json
{
  "hits": [
    {
      "id": 123,
      "post_type": "post",
      "post_type_label": "Post",
      "title": "Example title",
      "excerpt": "…",
      "url": "https://example.test/example",
      "_score": 12.345,
      "_distanceMeters": 3210,
      "_formatted": {
        "post_title": "Example <mark>title</mark>",
        "post_content": "…matched <mark>title</mark> in context…"
      }
    }
  ],
  "facets": {
    "category": {
      "type": "terms",
      "buckets": [
        { "value": "news", "count": 12 },
        { "value": "events", "count": 4 }
      ]
    }
  },
  "pagination": {
    "total": 42,
    "per_page": 10,
    "current_page": 1,
    "total_pages": 5
  },
  "tookMs": 8
}
```

Notes:

- `_score` is always included.
- `_distanceMeters` is included only when `geo.includeDistance` is `true`.
- `_formatted` is included only when `attributesToHighlight` and/or `attributesToCrop` are supplied.

### Filter AST (JSON)

Loupe Search accepts a structured JSON filter. The server translates it into the underlying Loupe filter syntax.

#### Groups

- `{ "type": "and", "items": [ <expr>, <expr>, ... ] }`
- `{ "type": "or",  "items": [ <expr>, <expr>, ... ] }`
- `{ "type": "not", "item": <expr> }`

#### Predicates

Predicates use a single shape:

```json
{ "type": "pred", "field": "fieldName", "op": "eq", "value": "example" }
```

Supported `op` values:

- `eq`, `ne`
- `in`, `nin` (value must be a non-empty array)
- `lt`, `lte`, `gt`, `gte`
- `between` (value must be `[min, max]` or `{ "min": ..., "max": ... }`)
- `exists` (value must be boolean)

#### Literal values

The API accepts:

- strings
- numbers
- booleans
- `null`
- dates as either:
  - date-only `YYYY-MM-DD`
  - ISO-8601 timestamp (e.g. `2025-12-18T10:11:12Z`)

### GET /search (legacy)

Kept for backward compatibility. It supports a query and pagination only — no filters, facets, geo, sorting, or highlighting. Prefer POST for anything else.

```sh
curl -s 'https://example.test/wp-json/loupe-search/v1/search?q=wordpress&post_type=post&per_page=10&page=1'
```

| Parameter | Type | Default | Description |
| --- | --- | --- | --- |
| `q` | string | — | **Required.** Search query. |
| `post_type` | string | `all` | A single post type slug, or `all`. |
| `per_page` | int | 10 | Results per page. |
| `page` | int | 1 | 1-based page number. |

The response contains `hits` and `pagination` with the same shape as POST, but never `facets`, `tookMs`, `_distanceMeters`, or `_formatted`.

### GET /index-status

Requires the `manage_options` capability. Returns one entry per configured post type:

```json
{
  "items": [
    { "postType": "post", "label": "Posts", "ready": true, "reason": null, "published": 42 }
  ]
}
```

- `ready` — whether the post type can be searched.
- `reason` — why it is not ready (e.g. `missing`, or a schema-drift reason); `null` when ready.
- `published` — number of published posts of that type.

### Errors

Errors are returned as standard WordPress REST errors. Validation problems use **HTTP 400**; unknown post types on admin routes use **404**; indexing failures use **500**.

```json
{
  "code": "wp_loupe_missing_query",
  "message": "Missing or empty query parameter \"q\".",
  "data": { "status": 400 }
}
```

Some errors add a `data.details` object naming the offending value — for example `details.field` for allowlist failures and `details.postType` for index problems.

| Code | Cause | Fix |
| --- | --- | --- |
| `wp_loupe_invalid_payload` | Body was not valid JSON | Send valid JSON and `Content-Type: application/json` |
| `wp_loupe_missing_query` | `q` missing or empty | Always send a non-empty `q` |
| `wp_loupe_invalid_page_size` | `page.size` outside 1–100 | Clamp page size to 1–100 |
| `wp_loupe_pagination_limit` | Page too deep (offset + size > 1000) | Lower `page.number`/`page.size`, or narrow the query |
| `wp_loupe_no_indexed_post_types` | No post type has a ready index | Run `wp loupe-search reindex` |
| `wp_loupe_index_missing` | Named post type has no index | Index that post type; see `details.postType` |
| `wp_loupe_index_needs_reindex` | Index schema is stale after a settings change | Reindex the post type |
| `wp_loupe_invalid_post_types` | `postTypes` not `"all"` or a non-empty array | Send `"all"` or an array of slugs |
| `wp_loupe_invalid_post_type` | Unknown slug in `postTypes` | Check `details.unknown` |
| `wp_loupe_unallowlisted_field` | Field not enabled as Filterable/Sortable | Enable it in Settings or via schema hooks; see `details.field` |
| `wp_loupe_invalid_filter` | Malformed filter node, operator, or value | Check the [Filter AST](#filter-ast-json) grammar |
| `wp_loupe_invalid_sort` | Malformed `sort` entry or bad order | Use `{ "by": …, "order": "asc"\|"desc" }` |
| `wp_loupe_invalid_facets` | `facets` not an array, or non-terms type | Only `{ "type": "terms", … }` is supported |
| `wp_loupe_invalid_facet_field` | Facet field name is not a valid attribute name | Use a valid indexed field name |
| `wp_loupe_invalid_geo` | Bad `geo` object, `near`, radius, or sort order | See [Search by location](#search-by-location) |
| `wp_loupe_invalid_geo_field` | Geo field name is not a valid attribute name | Use a valid geo-point field |
| `wp_loupe_invalid_highlight` | `attributesToHighlight` not an array | Send an array of field names or `["*"]` |
| `wp_loupe_invalid_crop` | `attributesToCrop` not an array | Send an array of field names or `["*"]` |
| `wp_loupe_reindex_failed` | Indexing threw an error (HTTP 500) | Check the message and PHP error log |

> These error codes keep the `wp_loupe_` prefix for backward compatibility, even though the REST namespace is now `loupe-search/v1`.
