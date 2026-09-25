<?php
namespace Soderlind\Plugin\LoupeSearch;

/**
 * Front-end only hook integration for WP Loupe.
 *
 * Owns WordPress query interception + footer timing output.
 */
class WP_Loupe_Search_Hooks {
	/** @var WP_Loupe_Search_Engine */
	private $engine;

	/** @var array */
	private $post_types;

	/** @var int */
	private $total_found_posts = 0;

	/** @var int */
	private $max_num_pages = 0;

	/** @var array<int,array<string,string>> Loupe `_formatted` fields keyed by post ID. */
	private $formatted_by_id = [];

	/** @var string Highlight opening tag resolved from options; used to parse Loupe marks and wrap in-place. */
	private $highlight_start = '<mark>';

	/** @var string Highlight closing tag resolved from options. */
	private $highlight_end = '</mark>';

	/**
	 * Constructor.
	 *
	 * @param WP_Loupe_Search_Engine $engine Search engine instance.
	 */
	public function __construct( WP_Loupe_Search_Engine $engine ) {
		$this->engine     = $engine;
		$this->post_types = $engine->get_post_types();
	}

	/**
	 * Register WordPress hooks.
	 */
	public function register(): void {
		add_filter( 'posts_pre_query', [ $this, 'posts_pre_query' ], 10, 2 );
		add_filter( 'the_title', [ $this, 'highlight_title' ], 10, 2 );
		add_filter( 'get_the_excerpt', [ $this, 'highlight_excerpt' ], 10, 2 );
		// Block themes render the excerpt via core/post-excerpt, which strips tags with
		// wp_trim_words(); re-inject the highlighted snippet into the block output.
		add_filter( 'render_block_core/post-excerpt', [ $this, 'highlight_excerpt_block' ], 10, 3 );
		// Some block themes (Twenty Twenty-Five) render search results via
		// core/post-content instead of the excerpt; highlight that block too (issue #50).
		add_filter( 'render_block_core/post-content', [ $this, 'highlight_content_block' ], 10, 3 );
		add_action( 'wp_footer', [ $this, 'action_wp_footer' ], 999 );
	}

	/**
	 * Filter posts before the main query runs.
	 *
	 * Intercepts search queries and returns WP Loupe results instead.
	 *
	 * @param array|null $posts Null to allow WP to run its query, or array of posts to short-circuit.
	 * @param \WP_Query  $query The WP_Query instance.
	 * @return array|null Array of WP_Post objects on success, null to fall back to default query.
	 */
	public function posts_pre_query( $posts, \WP_Query $query ) {
		if ( ! $this->should_intercept_query( $query ) ) {
			return null;
		}

		$query->set( 'post_type', $this->post_types );
		$search_term = $this->prepare_search_term( $query->query_vars[ 'search_terms' ] ?? [] );
		$hits        = $this->engine->search( $search_term, $this->get_highlight_options() );
		$all_posts   = $this->create_post_objects( $hits );

		$this->total_found_posts = count( $all_posts );
		$posts_per_page          = apply_filters( 'loupe_search_posts_per_page', $query->get( 'posts_per_page' ) ?: get_option( 'posts_per_page' ) );
		$posts_per_page          = apply_filters_deprecated( 'wp_loupe_posts_per_page', array( $posts_per_page ), '1.1.0', 'loupe_search_posts_per_page' );
		$paged                   = $query->get( 'paged' ) ? $query->get( 'paged' ) : 1;
		$offset                  = ( $paged - 1 ) * $posts_per_page;
		$this->max_num_pages     = (int) ceil( $this->total_found_posts / $posts_per_page );
		$paged_posts             = array_slice( $all_posts, $offset, $posts_per_page );

		$query->found_posts   = $this->total_found_posts;
		$query->max_num_pages = $this->max_num_pages;
		$query->is_paged      = $paged > 1;
		return $paged_posts;
	}

	/**
	 * Determine if WP Loupe should intercept the given query.
	 *
	 * Checks for front-end search queries with indexed post types.
	 *
	 * @param \WP_Query $query The WP_Query instance.
	 * @return bool True if WP Loupe should handle this query.
	 */
	private function should_intercept_query( \WP_Query $query ): bool {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return false;
		}
		if ( ! $query->is_search() || ( ! $query->is_main_query() && ! wp_doing_ajax() ) ) {
			return false;
		}

		// When a post_type is explicitly requested, only intercept if every
		// requested type is indexed by WP Loupe; otherwise let WordPress handle
		// the query so unindexed types are not silently dropped.
		$queried_type = $query->get( 'post_type' );
		if ( ! empty( $queried_type ) && 'any' !== $queried_type ) {
			$queried_types = (array) $queried_type;
			if ( array_diff( $queried_types, $this->post_types ) ) {
				return false;
			}
		}

		// For default searches (no explicit post_type), intercept and restrict
		// results to the indexed post types via posts_pre_query().
		return true;
	}

	/**
	 * Prepare search terms for Loupe query.
	 *
	 * Wraps multi-word terms in quotes for phrase matching.
	 *
	 * @param array|mixed $search_terms Array of search terms from WP_Query.
	 * @return string Prepared search string.
	 */
	private function prepare_search_term( $search_terms ): string {
		$search_terms = is_array( $search_terms ) ? $search_terms : [];
		return implode( ' ', array_map( function ( $term ) {
			$term = (string) $term;
			return strpos( $term, ' ' ) !== false ? '"' . $term . '"' : $term;
		}, $search_terms ) );
	}

	/**
	 * Convert Loupe search hits to WP_Post objects.
	 *
	 * Fetches full post data and attaches filterable/sortable meta fields.
	 *
	 * @param array $hits Array of Loupe search result hits.
	 * @return array Array of WP_Post objects ordered by relevance.
	 */
	private function create_post_objects( $hits ): array {
		if ( empty( $hits ) || ! is_array( $hits ) ) {
			return [];
		}

		$saved_fields = get_option( 'loupe_search_fields', [] );
		$hits_by_type = [];
		foreach ( $hits as $hit ) {
			if ( ! is_array( $hit ) || empty( $hit[ 'post_type' ] ) ) {
				continue;
			}
			$hits_by_type[ $hit[ 'post_type' ] ][] = $hit;
		}

		$all_posts = [];
		foreach ( $hits_by_type as $post_type => $type_hits ) {
			$type_posts = get_posts( [
				'post_type'        => $post_type,
				'post__in'         => array_column( $type_hits, 'id' ),
				'posts_per_page'   => -1,
				'orderby'          => 'post__in',
				'suppress_filters' => true, // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.SuppressFilters_suppress_filters -- required for accurate search-result ordering by post__in.
			] );

			$post_type_fields = is_array( $saved_fields ) ? ( $saved_fields[ $post_type ] ?? [] ) : [];
			$fields_to_load   = [];
			foreach ( (array) $post_type_fields as $field_name => $settings ) {
				if ( ! empty( $settings[ 'filterable' ] ) || ! empty( $settings[ 'sortable' ] ) ) {
					$fields_to_load[] = $field_name;
				}
			}

			foreach ( $type_posts as $post ) {
				foreach ( $fields_to_load as $field ) {
					if ( strpos( $field, 'taxonomy_' ) === 0 ) {
						$taxonomy = substr( $field, 9 );
						$terms    = wp_get_post_terms( $post->ID, $taxonomy );
						if ( ! is_wp_error( $terms ) ) {
							$post->{$field} = $terms;
						}
					} elseif ( ! property_exists( $post, $field ) ) {
						$post->{$field} = get_post_meta( $post->ID, $field, true );
					}
				}
			}

			$all_posts = array_merge( $all_posts, $type_posts );
		}

		$posts_lookup = array_column( $all_posts, null, 'ID' );
		return array_values( array_filter( array_map( function ( $hit ) use ( $posts_lookup ) {
			if ( ! isset( $hit[ 'id' ], $posts_lookup[ $hit[ 'id' ] ] ) ) {
				return null;
			}
			// Keep highlighted/cropped fields in an ID-keyed map so the render filters
			// find them regardless of which WP_Post instance the theme renders (block
			// themes re-run the query and use different instances).
			if ( ! empty( $hit[ '_formatted' ] ) && is_array( $hit[ '_formatted' ] ) ) {
				$this->formatted_by_id[ (int) $hit[ 'id' ] ] = $hit[ '_formatted' ];
			}
			return $posts_lookup[ $hit[ 'id' ] ];
		}, $hits ) ) );
	}

	/**
	 * Build opt-in highlight/crop options for the front-end search.
	 *
	 * Highlighting is OFF by default. Enable it by returning true from the
	 * `loupe_search_highlight` filter; the remaining filters tune fields, tags and crop.
	 *
	 * @return array
	 */
	private function get_highlight_options(): array {
		/**
		 * Enable match highlighting on the default WordPress search results.
		 *
		 * Defaults to the "Highlight Matches" setting (Search Behavior tab); the
		 * filter overrides it either way.
		 *
		 * @since 1.3.2
		 * @param bool $enabled
		 */
		$enabled = ! empty( get_option( 'loupe_search_advanced', [] )[ 'highlight_enabled' ] );
		if ( ! apply_filters( 'loupe_search_highlight', $enabled ) ) {
			return [];
		}

		$fields      = (array) apply_filters( 'loupe_search_highlight_fields', [ 'post_title', 'post_content' ] );
		$crop_fields = (array) apply_filters( 'loupe_search_highlight_crop_fields', [ 'post_content' ] );
		$start       = $this->sanitize_highlight_tag( (string) apply_filters( 'loupe_search_highlight_start_tag', '<mark>' ) );
		$end         = $this->sanitize_highlight_tag( (string) apply_filters( 'loupe_search_highlight_end_tag', '</mark>' ) );
		$crop_length = (int) apply_filters( 'loupe_search_highlight_crop_length', 55 );

		$this->highlight_start = '' !== $start ? $start : '<mark>';
		$this->highlight_end   = '' !== $end ? $end : '</mark>';

		return [
			'fields'      => array_values( array_filter( array_map( 'strval', $fields ) ) ),
			'start_tag'   => '' !== $start ? $start : '<mark>',
			'end_tag'     => '' !== $end ? $end : '</mark>',
			'crop_fields' => array_values( array_filter( array_map( 'strval', $crop_fields ) ) ),
			'crop_length' => $crop_length > 0 ? $crop_length : 55,
			'crop_marker' => (string) apply_filters( 'loupe_search_highlight_crop_marker', '…' ),
		];
	}

	/**
	 * Replace a search-result title with its highlighted version.
	 *
	 * @param string $title
	 * @param int    $post_id
	 * @return string
	 */
	public function highlight_title( $title, $post_id = 0 ) {
		if ( ! $this->is_highlightable_context() ) {
			return $title;
		}
		$formatted = $this->formatted_by_id[ (int) $post_id ][ 'post_title' ] ?? '';
		return '' !== $formatted ? wp_kses( (string) $formatted, $this->highlight_allowed_tags() ) : $title;
	}

	/**
	 * Replace a search-result excerpt with a highlighted, cropped snippet.
	 *
	 * @param string        $excerpt
	 * @param \WP_Post|null $post
	 * @return string
	 */
	public function highlight_excerpt( $excerpt, $post = null ) {
		if ( ! $this->is_highlightable_context() ) {
			return $excerpt;
		}
		$post_id   = $post instanceof \WP_Post ? $post->ID : (int) get_the_ID();
		$formatted = $this->formatted_by_id[ $post_id ][ 'post_content' ] ?? '';
		return '' !== $formatted ? wp_kses( (string) $formatted, $this->highlight_allowed_tags() ) : $excerpt;
	}

	/**
	 * Re-inject the highlighted snippet into the core/post-excerpt block output.
	 *
	 * The block strips tags via wp_trim_words(), so `get_the_excerpt` highlighting is
	 * lost in block themes; replace the rendered excerpt paragraph with the safe,
	 * highlighted, cropped snippet from Loupe.
	 *
	 * @param string        $block_content
	 * @param array         $block
	 * @param \WP_Block|null $instance
	 * @return string
	 */
	public function highlight_excerpt_block( $block_content, $block, $instance = null ) {
		if ( ! $this->is_highlightable_context() ) {
			return $block_content;
		}
		$post_id   = ( $instance instanceof \WP_Block ) ? (int) ( $instance->context[ 'postId' ] ?? 0 ) : 0;
		$formatted = $this->formatted_by_id[ $post_id ][ 'post_content' ] ?? '';
		if ( '' === $formatted ) {
			return $block_content;
		}
		$safe = wp_kses( (string) $formatted, $this->highlight_allowed_tags() );
		return preg_replace_callback(
			'#(<p class="wp-block-post-excerpt__excerpt">).*?(</p>)#s',
			static function ( $m ) use ( $safe ) {
				return $m[ 1 ] . $safe . $m[ 2 ];
			},
			$block_content,
			1
		);
	}

	/**
	 * Highlight search terms inside the rendered core/post-content block.
	 *
	 * Themes that render full content (e.g. Twenty Twenty-Five's search results) use
	 * core/post-content. Rather than swapping the body for Loupe's plain-text snippet
	 * (which drops the theme's block markup — issue #54), keep the rendered HTML and
	 * only wrap the matched terms in the highlight tag, decorating text nodes in place.
	 * Terms come from the words Loupe actually marked in `_formatted`, so highlighting
	 * still reflects Loupe's matching (typo/stem tolerance).
	 *
	 * @param string         $block_content
	 * @param array          $block
	 * @param \WP_Block|null $instance
	 * @return string
	 */
	public function highlight_content_block( $block_content, $block, $instance = null ) {
		if ( ! $this->is_highlightable_context() ) {
			return $block_content;
		}
		$post_id   = ( $instance instanceof \WP_Block ) ? (int) ( $instance->context[ 'postId' ] ?? 0 ) : 0;
		$formatted = $this->formatted_by_id[ $post_id ][ 'post_content' ] ?? '';
		if ( '' === $formatted ) {
			return $block_content;
		}
		$terms = $this->extract_marked_terms( (string) $formatted );
		if ( empty( $terms ) ) {
			return $block_content;
		}
		return $this->mark_terms_in_html( (string) $block_content, $terms );
	}

	/**
	 * Pull the distinct matched terms out of a Loupe `_formatted` value.
	 *
	 * @param string $formatted
	 * @return array<int,string>
	 */
	private function extract_marked_terms( string $formatted ): array {
		$start = preg_quote( '' !== $this->highlight_start ? $this->highlight_start : '<mark>', '#' );
		$end   = preg_quote( '' !== $this->highlight_end ? $this->highlight_end : '</mark>', '#' );
		if ( ! preg_match_all( '#' . $start . '(.+?)' . $end . '#su', $formatted, $matches ) ) {
			return [];
		}
		$terms = [];
		foreach ( $matches[ 1 ] as $term ) {
			$term = trim( wp_strip_all_tags( (string) $term ) );
			if ( '' !== $term ) {
				$terms[ function_exists( 'mb_strtolower' ) ? mb_strtolower( $term ) : strtolower( $term ) ] = $term;
			}
		}
		return array_values( $terms );
	}

	/**
	 * Wrap the given terms in the highlight tag within an HTML fragment's text nodes.
	 *
	 * Splits the fragment into tags and text so markup is preserved untouched, and
	 * skips script/style bodies and already-highlighted spans to avoid nested marks.
	 *
	 * @param string            $html
	 * @param array<int,string> $terms
	 * @return string
	 */
	private function mark_terms_in_html( string $html, array $terms ): string {
		$start   = '' !== $this->highlight_start ? $this->highlight_start : '<mark>';
		$end     = '' !== $this->highlight_end ? $this->highlight_end : '</mark>';
		$hl_tag  = preg_match( '#^<\s*([a-z0-9]+)#i', $start, $tm ) ? strtolower( $tm[ 1 ] ) : 'mark';
		$skip_re = '#^<\s*/?\s*(?:script|style|' . preg_quote( $hl_tag, '#' ) . ')\b#i';

		// Longest terms first so overlapping matches wrap the fuller word.
		usort( $terms, static fn( $a, $b ) => strlen( (string) $b ) <=> strlen( (string) $a ) );
		$pattern = '#(' . implode( '|', array_map( static fn( $t ) => preg_quote( (string) $t, '#' ), $terms ) ) . ')#iu';

		$parts = preg_split( '#(<[^>]*>)#', $html, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY );
		if ( ! is_array( $parts ) ) {
			return $html;
		}
		$skip = 0;
		$out  = '';
		foreach ( $parts as $part ) {
			if ( '' === $part ) {
				continue;
			}
			if ( '<' === $part[ 0 ] ) {
				if ( preg_match( $skip_re, $part ) ) {
					$skip += preg_match( '#^<\s*/#', $part ) ? -1 : 1;
					$skip  = max( 0, $skip );
				}
				$out .= $part;
				continue;
			}
			if ( $skip > 0 ) {
				$out .= $part;
				continue;
			}
			$out .= preg_replace_callback(
				$pattern,
				static fn( $m ) => $start . $m[ 0 ] . $end,
				$part
			);
		}
		return $out;
	}

	/**
	 * Whether the current request is a front-end search where highlighting applies.
	 *
	 * Intentionally does not require the main loop: block themes (Twenty Twenty-Five
	 * etc.) render results via the Query Loop block, outside `in_the_loop()`. The
	 * render callbacks additionally require the post to carry `loupe_formatted`, so
	 * non-result titles (menus, widgets) are never touched.
	 *
	 * @return bool
	 */
	private function is_highlightable_context(): bool {
		return ! is_admin() && is_search();
	}

	/**
	 * Inline-tag allowlist shared by the tag sanitizer and the render escaping.
	 *
	 * @return array<string,array<string,bool>>
	 */
	private function highlight_allowed_tags(): array {
		return [
			'mark'   => [ 'class' => true ],
			'em'     => [ 'class' => true ],
			'strong' => [ 'class' => true ],
			'span'   => [ 'class' => true ],
			'b'      => [ 'class' => true ],
			'i'      => [ 'class' => true ],
		];
	}

	/**
	 * Restrict a highlight tag to the safe inline allowlist (matches the REST path).
	 *
	 * @param string $tag
	 * @return string
	 */
	private function sanitize_highlight_tag( string $tag ): string {
		return wp_kses( $tag, $this->highlight_allowed_tags() );
	}

	/**
	 * Output search timing info as HTML comment in footer.
	 *
	 * @return void
	 */
	public function action_wp_footer(): void {
		if ( is_admin() ) {
			return;
		}
		$log = $this->engine->get_log();
		if ( $log ) {
			echo "\n<!-- " . esc_html( $log ) . " -->\n";
		}
	}
}
