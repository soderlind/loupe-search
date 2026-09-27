<?php
namespace Soderlind\Plugin\LoupeSearch;

use Soderlind\Plugin\LoupeSearch\Vendor\Loupe\Loupe\SearchParameters;

/**
 * Side-effect free search engine for WP Loupe.
 *
 * Performs Loupe searches and returns raw hit arrays.
 */
class WP_Loupe_Search_Engine {
	private $post_types;
	private $loupe = [];
	private $log;
	private $db;
	private $saved_fields;
	private const CACHE_TTL                 = 3600; // 1 hour
	private const MAX_CACHEABLE_QUERY_LENGTH = 128;
	private const LOUPE_DB_FILENAME         = 'loupe.db';
	private const LOUPE_ATTRIBUTE_NAME_RGXP = '/^[a-zA-Z\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*$/';

	/**
	 * @param array $post_types
	 * @param WP_Loupe_DB|null $db
	 */
	public function __construct( $post_types, $db = null ) {
		$this->post_types   = (array) $post_types;
		$this->db           = $db ?: WP_Loupe_DB::get_instance();
		$this->saved_fields = get_option( 'loupe_search_fields', [] );

		$iso6391_lang = ( '' === get_locale() ) ? 'en' : strtolower( substr( get_locale(), 0, 2 ) );
		foreach ( $this->post_types as $post_type ) {
			$this->loupe[ $post_type ] = WP_Loupe_Factory::create_loupe_instance( $post_type, $iso6391_lang, $this->db );
		}
	}

	/**
	 * @return array
	 */
	public function get_post_types() {
		return $this->post_types;
	}

	/**
	 * Whether a query is short enough to be worth caching.
	 *
	 * Searches are reachable by unauthenticated visitors, so caching every distinct query
	 * would add transient rows to the options table without bound. Long queries are almost
	 * always bot noise with no cache hit rate, so they are executed without being stored.
	 *
	 * @param string $query Search query.
	 * @return bool
	 */
	private function is_cacheable_query( $query ) {
		/**
		 * Filters the maximum query length that is written to the search result cache.
		 *
		 * @since 1.2.4
		 * @param int $max_length Maximum number of bytes. Default 128.
		 */
		$max_length = (int) apply_filters( 'loupe_search_max_cacheable_query_length', self::MAX_CACHEABLE_QUERY_LENGTH );

		return $max_length > 0 && strlen( (string) $query ) <= $max_length;
	}

	/**
	 * Execute a search.
	 *
	 * @param string $query
	 * @param array{fields?:array<string>,start_tag?:string,end_tag?:string,crop_fields?:array<string>,crop_length?:int,crop_marker?:string} $highlight Optional opt-in highlight/crop options. Empty for no formatting.
	 * @param array<int,string|array{field:string,direction?:string}> $sort Optional explicit sort. Each entry is "field" or "field:dir", validated against the configured sortable fields. Empty keeps relevance ordering (issue #64).
	 * @return array Raw hit arrays with at least id, _score, post_type.
	 */
	public function search( $query, array $highlight = [], array $sort = [] ) {
		$requested_sort = $this->parse_requested_sort( $sort );
		$cacheable     = $this->is_cacheable_query( $query );
		$cache_key     = md5( (string) $query . serialize( $this->post_types ) . serialize( $highlight ) . serialize( $requested_sort ) );
		$transient_key = "loupe_search_cache_{$cache_key}";
		$cached_result = $cacheable ? get_transient( $transient_key ) : false;
		if ( false !== $cached_result ) {
			$this->log = sprintf( 'WP Loupe cache hit: %s ms', 0 );
			return $cached_result;
		}

		$hits                = [];
		$processing_time_sum = 0;

		// Resolve the requested sort against the sortable fields configured across all
		// post types, carrying each field's effective direction (explicit, else its
		// configured default). Only fields that survive validation drive ordering; if
		// none do, results stay relevance-ordered (issue #64).
		$sortable_union = [];
		foreach ( $this->post_types as $pt ) {
			foreach ( (array) ( $this->saved_fields[ $pt ] ?? [] ) as $field_name => $settings ) {
				if ( ! empty( $settings[ 'sortable' ] ) && ! isset( $sortable_union[ $field_name ] ) ) {
					$sortable_union[ $field_name ] = $settings[ 'sort_direction' ] ?? 'desc';
				}
			}
		}
		$effective_sort = [];
		foreach ( $requested_sort as $rs ) {
			if ( ! isset( $sortable_union[ $rs[ 'field' ] ] ) ) {
				continue;
			}
			$effective_sort[] = [
				'field'     => $rs[ 'field' ],
				'direction' => '' !== $rs[ 'direction' ] ? $rs[ 'direction' ] : $sortable_union[ $rs[ 'field' ] ],
			];
		}

		foreach ( $this->post_types as $post_type ) {
			$post_type_fields = $this->saved_fields[ $post_type ] ?? [];
			if ( empty( $post_type_fields ) ) {
				continue;
			}

			try {
				$indexable_fields   = [];
				$filterable_fields  = [];
				$sortable_allowlist = [];
				$retrievable_fields = [ 'id' ];

				foreach ( $post_type_fields as $field_name => $settings ) {
					if ( ! empty( $settings[ 'indexable' ] ) ) {
						$indexable_fields[] = [
							'field'  => $field_name,
							'weight' => $settings[ 'weight' ] ?? 1.0,
						];
					}

					if ( ! empty( $settings[ 'filterable' ] ) ) {
						$filterable_fields[] = $field_name;
					}

					// sortable only marks a field as eligible for sorting; it is not applied
					// unless the caller explicitly requests it (issue #64).
					if ( ! empty( $settings[ 'sortable' ] ) ) {
						$sortable_allowlist[ $field_name ] = $settings[ 'sort_direction' ] ?? 'desc';
					}
				}

				// Apply the validated sort, keeping only fields this type can sort by.
				$type_sort = [];
				foreach ( $effective_sort as $rs ) {
					if ( ! isset( $sortable_allowlist[ $rs[ 'field' ] ] ) ) {
						continue;
					}
					$type_sort[]         = "{$rs[ 'field' ]}:{$rs[ 'direction' ]}";
					$filterable_fields[] = $rs[ 'field' ]; // ensure the value is retrieved for the cross-type merge.
				}

				$retrievable_fields = array_unique( array_merge(
					$retrievable_fields,
					array_map( function ( $field ) {
						return $field[ 'field' ];
					}, $indexable_fields ),
					$filterable_fields
				) );

				$search_params = SearchParameters::create()
					->withQuery( (string) $query )
					->withAttributesToRetrieve( $retrievable_fields )
					->withShowRankingScore( true )
					->withLimit( 1000 );

				if ( ! empty( $type_sort ) ) {
					try {
						$search_params = $search_params->withSort( $type_sort );
					} catch (\Throwable $e) {
						WP_Loupe_Utils::debug_log( "Sort error for {$post_type}: " . $e->getMessage(), 'WP Loupe' );
					}
				}

				// Opt-in highlighting/cropping for the front-end search path. Requested
				// fields are intersected with this post type's indexable fields so an
				// unknown field never makes Loupe throw and drop the whole type.
				if ( ! empty( $highlight[ 'fields' ] ) || ! empty( $highlight[ 'crop_fields' ] ) ) {
					$indexable_names = array_column( $indexable_fields, 'field' );
					$type_highlight  = ! empty( $highlight[ 'fields' ] ) ? array_values( array_intersect( $highlight[ 'fields' ], $indexable_names ) ) : [];
					$type_crop       = ! empty( $highlight[ 'crop_fields' ] ) ? array_values( array_intersect( $highlight[ 'crop_fields' ], $indexable_names ) ) : [];
					if ( ! empty( $type_highlight ) ) {
						$search_params = $search_params->withAttributesToHighlight(
							$type_highlight,
							isset( $highlight[ 'start_tag' ] ) ? (string) $highlight[ 'start_tag' ] : '<em>',
							isset( $highlight[ 'end_tag' ] ) ? (string) $highlight[ 'end_tag' ] : '</em>'
						);
					}
					if ( ! empty( $type_crop ) ) {
						$search_params = $search_params->withAttributesToCrop(
							$type_crop,
							isset( $highlight[ 'crop_length' ] ) ? (int) $highlight[ 'crop_length' ] : 50,
							isset( $highlight[ 'crop_marker' ] ) ? (string) $highlight[ 'crop_marker' ] : '…'
						);
					}
				}

				$result = $this->loupe[ $post_type ]->search( $search_params );
				$arr    = $result->toArray();

				if ( isset( $arr[ 'processingTimeMs' ] ) ) {
					$processing_time_sum += (int) $arr[ 'processingTimeMs' ];
				}

				$tmp_hits = isset( $arr[ 'hits' ] ) && is_array( $arr[ 'hits' ] ) ? $arr[ 'hits' ] : [];
				foreach ( $tmp_hits as $hit ) {
					if ( ! is_array( $hit ) ) {
						continue;
					}
					// Normalize Loupe's ranking score into the plugin's historic `_score` field.
					if ( isset( $hit[ '_rankingScore' ] ) && ! isset( $hit[ '_score' ] ) ) {
						$hit[ '_score' ] = $hit[ '_rankingScore' ];
					}
					$hit[ 'post_type' ] = $post_type;
					$hits[]             = $hit;
				}
			} catch (\Throwable $e) {
				WP_Loupe_Utils::debug_log( "Search error for {$post_type}: " . $e->getMessage(), 'WP Loupe' );
				continue;
			}
		}

		// Each post type has its own index, so hits arrive grouped by type (all posts,
		// then all pages). Merge them into one relevance-ordered list so score/weight
		// interleaves types instead of always ranking one type above another (issue #51).
		// Cross-index scores are only approximately comparable; usort is stable in PHP 8+,
		// so equal scores keep their original per-type order. When the caller requested an
		// explicit sort, order by those fields instead of relevance (issue #64).
		if ( ! empty( $effective_sort ) ) {
			$this->sort_hits_by_fields( $hits, $effective_sort );
		} else {
			usort( $hits, static fn( array $a, array $b ): int => ( $b[ '_score' ] ?? 0 ) <=> ( $a[ '_score' ] ?? 0 ) );
		}

		/**
		 * Reorder or regroup the merged, cross-post-type result set.
		 *
		 * Fires after hits from every post-type index are merged and sorted by relevance
		 * score (descending). Return the hits reordered to apply a custom policy, e.g.
		 * grouping by post type or boosting a type. Each hit carries at least `id`,
		 * `_score`, and `post_type`.
		 *
		 * @since 1.3.5
		 * @param array  $hits  Hits sorted by relevance score (descending).
		 * @param string $query The search query.
		 */
		$hits = apply_filters( 'loupe_search_order_results', $hits, (string) $query );

		$this->log = sprintf( 'WP Loupe processing time: %s ms', (string) $processing_time_sum );
		if ( $cacheable ) {
			set_transient( $transient_key, $hits, self::CACHE_TTL );
		}
		return $hits;
	}

	/**
	 * Normalise a caller-supplied sort request into a list of field/direction pairs.
	 *
	 * Accepts "field", "field:asc"/"field:desc" strings, or ['field'=>..,'direction'=>..]
	 * arrays. Validity against the sortable allowlist is enforced later, per post type.
	 *
	 * @param array<int,mixed> $sort
	 * @return array<int,array{field:string,direction:string}>
	 */
	private function parse_requested_sort( array $sort ): array {
		$out = [];
		foreach ( $sort as $entry ) {
			if ( is_string( $entry ) ) {
				[ $field, $dir ] = array_pad( explode( ':', $entry, 2 ), 2, '' );
			} elseif ( is_array( $entry ) && isset( $entry[ 'field' ] ) ) {
				$field = $entry[ 'field' ];
				$dir   = $entry[ 'direction' ] ?? '';
			} else {
				continue;
			}
			$field = trim( (string) $field );
			if ( '' === $field ) {
				continue;
			}
			$dir     = strtolower( trim( (string) $dir ) );
			$out[]   = [ 'field' => $field, 'direction' => ( 'asc' === $dir || 'desc' === $dir ) ? $dir : '' ];
		}
		return $out;
	}

	/**
	 * Sort merged cross-type hits by the requested fields, in order, honouring
	 * direction. Missing values compare equal so partially-shared fields are safe.
	 *
	 * @param array<int,array<string,mixed>>                 $hits
	 * @param array<int,array{field:string,direction:string}> $requested_sort
	 */
	private function sort_hits_by_fields( array &$hits, array $requested_sort ): void {
		usort( $hits, static function ( array $a, array $b ) use ( $requested_sort ): int {
			foreach ( $requested_sort as $rs ) {
				$field = $rs[ 'field' ];
				$dir   = '' !== $rs[ 'direction' ] ? $rs[ 'direction' ] : 'desc';
				$cmp   = ( $a[ $field ] ?? null ) <=> ( $b[ $field ] ?? null );
				if ( 0 !== $cmp ) {
					return 'asc' === $dir ? $cmp : -$cmp;
				}
			}
			return 0;
		} );
	}

	/**
	 * Determine whether a post type index is ready to be queried.
	 *
	 * Definition (per docs): ready = DB file exists + schema OK.
	 * Schema OK is derived from Loupe's needsReindex() signal.
	 *
	 * @param string $post_type
	 * @return array{ready:bool, reason?:string}
	 */
	public function is_index_ready( string $post_type ): array {
		$post_type = sanitize_key( $post_type );
		if ( empty( $post_type ) ) {
			return [ 'ready' => false, 'reason' => 'invalid_post_type' ];
		}
		if ( ! isset( $this->loupe[ $post_type ] ) ) {
			return [ 'ready' => false, 'reason' => 'unknown_post_type' ];
		}

		try {
			$db_dir  = $this->db->get_db_path( $post_type );
			$db_file = trailingslashit( $db_dir ) . self::LOUPE_DB_FILENAME;
			if ( ! file_exists( $db_file ) ) {
				return [ 'ready' => false, 'reason' => 'index_missing' ];
			}
		} catch (\Throwable $e) {
			return [ 'ready' => false, 'reason' => 'index_unreadable' ];
		}

		try {
			if ( method_exists( $this->loupe[ $post_type ], 'needsReindex' ) ) {
				$needs_reindex = (bool) $this->loupe[ $post_type ]->needsReindex();
				if ( $needs_reindex ) {
					return [ 'ready' => false, 'reason' => 'index_needs_reindex' ];
				}
			}
		} catch (\Throwable $e) {
			return [ 'ready' => false, 'reason' => 'index_unreadable' ];
		}

		return [ 'ready' => true ];
	}

	/**
	 * Whether a post type's index should be (re)built before it can serve results.
	 *
	 * True when the index is missing/unreadable/needs-reindex, or when it exists with
	 * a valid schema but holds zero documents while the site actually has published
	 * posts of that type — the exact state left behind when a fresh empty index is
	 * created at a new location (e.g. the multisite per-site path change, issue #56).
	 *
	 * @param string $post_type
	 * @return bool
	 */
	public function index_needs_rebuild( string $post_type ): bool {
		$status = $this->is_index_ready( $post_type );
		if ( empty( $status[ 'ready' ] ) ) {
			return true;
		}

		$count = $this->count_documents( $post_type );
		if ( $count < 0 ) {
			return false; // Can't determine reliably; don't nag.
		}
		if ( $count > 0 ) {
			return false;
		}

		return $this->post_type_has_published( $post_type );
	}

	/**
	 * Number of documents indexed for a post type, or -1 when it cannot be read.
	 *
	 * @param string $post_type
	 * @return int
	 */
	public function count_documents( string $post_type ): int {
		if ( ! isset( $this->loupe[ $post_type ] ) || ! method_exists( $this->loupe[ $post_type ], 'countDocuments' ) ) {
			return -1;
		}
		try {
			return (int) $this->loupe[ $post_type ]->countDocuments();
		} catch (\Throwable $e) {
			return -1;
		}
	}

	/**
	 * Whether the post type has at least one published post worth indexing.
	 *
	 * @param string $post_type
	 * @return bool
	 */
	private function post_type_has_published( string $post_type ): bool {
		if ( ! function_exists( 'wp_count_posts' ) ) {
			return false;
		}
		$counts = wp_count_posts( $post_type );
		return is_object( $counts ) && ! empty( $counts->publish ) && (int) $counts->publish > 0;
	}

	/**
	 * Execute an advanced search for REST / API usage.
	 *
	 * This method is intentionally low-level: it accepts an already validated set of
	 * Loupe-compatible parameters (filter string, sort strings, facet fields, etc.).
	 *
	 * @param string $query
	 * @param array{
	 *   filter?: string,
	 *   sort?: array<string>,
	 *   facets?: array<string>,
	 *   limit?: int,
	 *   attributesToRetrieve?: array<string>,
	 *   attributesToHighlight?: array<string>,
	 *   highlightStartTag?: string,
	 *   highlightEndTag?: string,
	 *   attributesToCrop?: array<string>,
	 *   cropLength?: int,
	 *   cropMarker?: string,
	 * } $options
	 * @return array{hits:array<int,array<string,mixed>>, totalHits:int, processingTimeMs:int, facetDistribution:array<string,array<string,int>>}
	 */
	public function search_advanced( string $query, array $options ): array {
		$query  = (string) $query;
		$filter = isset( $options[ 'filter' ] ) ? (string) $options[ 'filter' ] : '';
		$sort   = isset( $options[ 'sort' ] ) && is_array( $options[ 'sort' ] ) ? array_values( $options[ 'sort' ] ) : [];
		$facets = isset( $options[ 'facets' ] ) && is_array( $options[ 'facets' ] ) ? array_values( $options[ 'facets' ] ) : [];
		$limit  = isset( $options[ 'limit' ] ) ? (int) $options[ 'limit' ] : 0;
		$attrs  = isset( $options[ 'attributesToRetrieve' ] ) && is_array( $options[ 'attributesToRetrieve' ] ) ? array_values( $options[ 'attributesToRetrieve' ] ) : [];

		// Highlighting / cropping. The caller (REST) is responsible for validating field
		// names against the indexed set and sanitizing the tag/marker strings.
		$highlight        = isset( $options[ 'attributesToHighlight' ] ) && is_array( $options[ 'attributesToHighlight' ] ) ? array_values( array_unique( $options[ 'attributesToHighlight' ] ) ) : [];
		$crop             = isset( $options[ 'attributesToCrop' ] ) && is_array( $options[ 'attributesToCrop' ] ) ? array_values( array_unique( $options[ 'attributesToCrop' ] ) ) : [];
		$highlight_start  = isset( $options[ 'highlightStartTag' ] ) ? (string) $options[ 'highlightStartTag' ] : '<em>';
		$highlight_end    = isset( $options[ 'highlightEndTag' ] ) ? (string) $options[ 'highlightEndTag' ] : '</em>';
		$crop_length      = isset( $options[ 'cropLength' ] ) ? (int) $options[ 'cropLength' ] : 50;
		$crop_marker      = isset( $options[ 'cropMarker' ] ) ? (string) $options[ 'cropMarker' ] : '…';

		// Loupe only formats attributes that are present in the returned hit (i.e. those
		// listed in attributesToRetrieve). Ensure every highlighted/cropped field is
		// retrieved so it appears in the hit's `_formatted` payload.
		if ( ! empty( $attrs ) && ( ! empty( $highlight ) || ! empty( $crop ) ) ) {
			$attrs = array_values( array_unique( array_merge( $attrs, $highlight, $crop ) ) );
		}

		$cache_key     = md5( wp_json_encode( [
			'q'          => $query,
			'post_types' => $this->post_types,
			'filter'     => $filter,
			'sort'       => $sort,
			'facets'     => $facets,
			'limit'      => $limit,
			'attrs'      => $attrs,
			'highlight'  => $highlight,
			'crop'       => $crop,
			'hl_start'   => $highlight_start,
			'hl_end'     => $highlight_end,
			'crop_len'   => $crop_length,
			'crop_mark'  => $crop_marker,
		] ) );
		$transient_key = "loupe_search_cache_adv_{$cache_key}";
		$cacheable     = $this->is_cacheable_query( $query );
		$cached        = $cacheable ? get_transient( $transient_key ) : false;
		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}

		$total_hits          = 0;
		$processing_time_sum = 0;
		$facet_distribution  = [];
		$hits                = [];

		foreach ( $this->post_types as $post_type ) {
			try {
				$search_params = SearchParameters::create()
					->withQuery( $query )
					->withShowRankingScore( true );

				if ( ! empty( $attrs ) ) {
					$search_params = $search_params->withAttributesToRetrieve( array_values( array_unique( $attrs ) ) );
				}
				if ( ! empty( $filter ) ) {
					$search_params = $search_params->withFilter( $filter );
				}
				if ( ! empty( $facets ) ) {
					$search_params = $search_params->withFacets( $facets );
				}
				if ( ! empty( $sort ) ) {
					$search_params = $search_params->withSort( $sort );
				}
				if ( $limit > 0 ) {
					$search_params = $search_params->withLimit( $limit );
				}

				if ( ! empty( $highlight ) ) {
					$search_params = $search_params->withAttributesToHighlight( $highlight, $highlight_start, $highlight_end );
				}
				if ( ! empty( $crop ) ) {
					$search_params = $search_params->withAttributesToCrop( $crop, $crop_length, $crop_marker );
				}

				$result = $this->loupe[ $post_type ]->search( $search_params );
				$arr    = $result->toArray();

				$total_hits          += isset( $arr[ 'totalHits' ] ) ? (int) $arr[ 'totalHits' ] : 0;
				$processing_time_sum += isset( $arr[ 'processingTimeMs' ] ) ? (int) $arr[ 'processingTimeMs' ] : 0;

				if ( isset( $arr[ 'facetDistribution' ] ) && is_array( $arr[ 'facetDistribution' ] ) ) {
					foreach ( $arr[ 'facetDistribution' ] as $facet_field => $dist ) {
						if ( ! is_array( $dist ) ) {
							continue;
						}
						if ( ! isset( $facet_distribution[ $facet_field ] ) ) {
							$facet_distribution[ $facet_field ] = [];
						}
						foreach ( $dist as $val => $cnt ) {
							$facet_distribution[ $facet_field ][ (string) $val ] = ( $facet_distribution[ $facet_field ][ (string) $val ] ?? 0 ) + (int) $cnt;
						}
					}
				}

				$tmp_hits = isset( $arr[ 'hits' ] ) && is_array( $arr[ 'hits' ] ) ? $arr[ 'hits' ] : [];
				foreach ( $tmp_hits as $hit ) {
					if ( ! is_array( $hit ) ) {
						continue;
					}
					if ( isset( $hit[ '_rankingScore' ] ) && ! isset( $hit[ '_score' ] ) ) {
						$hit[ '_score' ] = $hit[ '_rankingScore' ];
					}
					$hit[ 'post_type' ] = $post_type;
					$hits[]             = $hit;
				}
			} catch (\Throwable $e) {
				WP_Loupe_Utils::debug_log( "Advanced search error for {$post_type}: " . $e->getMessage(), 'WP Loupe' );
				continue;
			}
		}

		$out = [
			'hits'              => $hits,
			'totalHits'         => $total_hits,
			'processingTimeMs'  => $processing_time_sum,
			'facetDistribution' => $facet_distribution,
		];
		if ( $cacheable ) {
			set_transient( $transient_key, $out, self::CACHE_TTL );
		}
		return $out;
	}
	/**
	 * Helper: validate that a field name is a Loupe-compatible attribute name.
	 *
	 * @param mixed $field
	 */
	public static function is_valid_loupe_attribute_name( $field ): bool {
		return is_string( $field ) && preg_match( self::LOUPE_ATTRIBUTE_NAME_RGXP, $field ) === 1;
	}

	/**
	 * @return string|null
	 */
	public function get_log() {
		return $this->log;
	}
}
