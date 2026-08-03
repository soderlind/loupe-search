<?php
namespace Soderlind\Plugin\WPLoupe;

/**
 * WordPress Abilities API integration for Loupe Search.
 *
 * Registers two abilities so AI agents and automation tools can discover
 * and execute Loupe Search functionality via the standard Abilities API
 * (WordPress 6.9+).
 *
 * As of 1.2.0 the primary ability namespace is `loupe-search/*` (category
 * `loupe-search`). The legacy `wp-loupe/*` abilities (category `wp-loupe`)
 * are still registered as deprecated aliases for backward compatibility and
 * will be removed in a future major release.
 *
 * @package Soderlind\Plugin\WPLoupe
 * @since   0.9.0
 */
class WP_Loupe_Abilities {

	public static function init(): void {
		add_action( 'wp_abilities_api_categories_init', [ __CLASS__, 'register_category' ] );
		add_action( 'wp_abilities_api_init', [ __CLASS__, 'register_abilities' ] );
	}

	/**
	 * Register the ability categories.
	 *
	 * `loupe-search` is the primary category since 1.2.0. `wp-loupe` is kept as a
	 * deprecated alias so the legacy abilities remain discoverable.
	 */
	public static function register_category(): void {
		wp_register_ability_category(
			'loupe-search',
			[
				'label'       => __( 'Loupe Search', 'loupe-search' ),
				'description' => __( 'Search abilities provided by the Loupe Search plugin.', 'loupe-search' ),
			]
		);

		// Legacy alias (deprecated since 1.2.0).
		wp_register_ability_category(
			'wp-loupe',
			[
				'label'       => __( 'Loupe Search (deprecated)', 'loupe-search' ),
				'description' => __( 'Deprecated alias of the "loupe-search" category. Use "loupe-search" instead.', 'loupe-search' ),
			]
		);
	}

	/**
	 * Register all Loupe Search abilities.
	 *
	 * Each ability is registered under its primary `loupe-search/*` name and again
	 * under its deprecated `wp-loupe/*` alias so existing consumers keep working.
	 */
	public static function register_abilities(): void {
		// Primary abilities (since 1.2.0).
		wp_register_ability( 'loupe-search/search', self::get_search_ability_args( 'loupe-search' ) );
		wp_register_ability( 'loupe-search/get-post', self::get_get_post_ability_args( 'loupe-search' ) );

		// Deprecated aliases (kept for backward compatibility).
		wp_register_ability( 'wp-loupe/search', self::get_search_ability_args( 'wp-loupe', true ) );
		wp_register_ability( 'wp-loupe/get-post', self::get_get_post_ability_args( 'wp-loupe', true ) );
	}

	/**
	 * Build the args for the search ability.
	 *
	 * @param string $category   Ability category slug.
	 * @param bool   $deprecated Whether this is the deprecated alias.
	 * @return array<string,mixed>
	 */
	private static function get_search_ability_args( string $category, bool $deprecated = false ): array {
		$description = __( 'Search WordPress content using Loupe Search\'s typo-tolerant full-text search engine. Supports phrase matching with quotes, exclusion with -, and OR searches.', 'loupe-search' );
		if ( $deprecated ) {
			$description = __( 'Deprecated alias of "loupe-search/search". Use "loupe-search/search" instead. ', 'loupe-search' ) . $description;
		}

		return [
			'label'               => __( 'Search Posts', 'loupe-search' ),
			'description'         => $description,
			'category'            => $category,
			'input_schema'        => [
				'type'       => 'object',
				'required'   => [ 'query' ],
				'properties' => [
					'query'      => [
						'type'        => 'string',
						'description' => __( 'The search query. Supports phrases ("hello world"), exclusions (-term), and OR searches.', 'loupe-search' ),
					],
					'post_types' => [
						'type'        => 'array',
						'items'       => [ 'type' => 'string' ],
						'description' => __( 'Post types to search. Defaults to all indexed post types.', 'loupe-search' ),
					],
					'per_page'   => [
						'type'        => 'integer',
						'description' => __( 'Number of results to return. Default: 10. Max: 100.', 'loupe-search' ),
						'default'     => 10,
						'minimum'     => 1,
						'maximum'     => 100,
					],
					'page'       => [
						'type'        => 'integer',
						'description' => __( 'Page of results to return. Default: 1.', 'loupe-search' ),
						'default'     => 1,
						'minimum'     => 1,
					],
				],
			],
			'output_schema'       => [
				'type'       => 'object',
				'properties' => [
					'hits'       => [
						'type'        => 'array',
						'description' => __( 'Array of matching posts.', 'loupe-search' ),
						'items'       => [
							'type'       => 'object',
							'properties' => [
								'id'         => [ 'type' => 'integer', 'description' => __( 'Post ID.', 'loupe-search' ) ],
								'title'      => [ 'type' => 'string', 'description' => __( 'Post title.', 'loupe-search' ) ],
								'url'        => [ 'type' => 'string', 'description' => __( 'Post permalink.', 'loupe-search' ) ],
								'excerpt'    => [ 'type' => 'string', 'description' => __( 'Post excerpt.', 'loupe-search' ) ],
								'post_type'  => [ 'type' => 'string', 'description' => __( 'Post type slug.', 'loupe-search' ) ],
								'post_date'  => [ 'type' => 'string', 'description' => __( 'Publication date (ISO 8601).', 'loupe-search' ) ],
							],
						],
					],
					'total_hits' => [
						'type'        => 'integer',
						'description' => __( 'Total number of matching posts.', 'loupe-search' ),
					],
					'page'       => [
						'type'        => 'integer',
						'description' => __( 'Current page number.', 'loupe-search' ),
					],
					'total_pages' => [
						'type'        => 'integer',
						'description' => __( 'Total number of pages.', 'loupe-search' ),
					],
				],
			],
			'execute_callback'    => [ __CLASS__, 'execute_search' ],
			'permission_callback' => '__return_true',
			'meta'                => [ 'show_in_rest' => true ],
		];
	}

	/**
	 * Build the args for the get-post ability.
	 *
	 * @param string $category   Ability category slug.
	 * @param bool   $deprecated Whether this is the deprecated alias.
	 * @return array<string,mixed>
	 */
	private static function get_get_post_ability_args( string $category, bool $deprecated = false ): array {
		$description = __( 'Retrieve a single published post by its ID, including title, content, excerpt, URL, author, and publication date.', 'loupe-search' );
		if ( $deprecated ) {
			$description = __( 'Deprecated alias of "loupe-search/get-post". Use "loupe-search/get-post" instead. ', 'loupe-search' ) . $description;
		}

		return [
			'label'               => __( 'Get Post', 'loupe-search' ),
			'description'         => $description,
			'category'            => $category,
			'input_schema'        => [
				'type'       => 'object',
				'required'   => [ 'id' ],
				'properties' => [
					'id' => [
						'type'        => 'integer',
						'description' => __( 'The post ID to retrieve.', 'loupe-search' ),
						'minimum'     => 1,
					],
				],
			],
			'output_schema'       => [
				'type'       => 'object',
				'properties' => [
					'id'         => [ 'type' => 'integer', 'description' => __( 'Post ID.', 'loupe-search' ) ],
					'title'      => [ 'type' => 'string', 'description' => __( 'Post title.', 'loupe-search' ) ],
					'content'    => [ 'type' => 'string', 'description' => __( 'Post content (HTML stripped).', 'loupe-search' ) ],
					'excerpt'    => [ 'type' => 'string', 'description' => __( 'Post excerpt.', 'loupe-search' ) ],
					'url'        => [ 'type' => 'string', 'description' => __( 'Post permalink.', 'loupe-search' ) ],
					'post_type'  => [ 'type' => 'string', 'description' => __( 'Post type slug.', 'loupe-search' ) ],
					'post_date'  => [ 'type' => 'string', 'description' => __( 'Publication date (ISO 8601).', 'loupe-search' ) ],
					'author'     => [ 'type' => 'string', 'description' => __( 'Display name of the post author.', 'loupe-search' ) ],
				],
			],
			'execute_callback'    => [ __CLASS__, 'execute_get_post' ],
			'permission_callback' => '__return_true',
			'meta'                => [ 'show_in_rest' => true ],
		];
	}

	/**
	 * Execute the loupe-search/search ability (and its wp-loupe/search alias).
	 *
	 * @param array $input Validated input from the Abilities API.
	 * @return array Search results.
	 */
	public static function execute_search( array $input ): array {
		$query    = sanitize_text_field( $input['query'] ?? '' );
		$per_page = min( 100, max( 1, (int) ( $input['per_page'] ?? 10 ) ) );
		$page     = max( 1, (int) ( $input['page'] ?? 1 ) );

		// This ability is unauthenticated, so only public post types are ever queried.
		// Scoping the engine (rather than filtering its output) keeps total_hits from
		// revealing how much non-public content is indexed.
		$configured_types = WP_Loupe_Utils::get_public_indexed_post_types();

		$post_types = ! empty( $input['post_types'] )
			? array_intersect( array_map( 'sanitize_key', (array) $input['post_types'] ), $configured_types )
			: $configured_types;

		if ( empty( $post_types ) ) {
			$post_types = $configured_types;
		}

		if ( empty( $post_types ) ) {
			return [
				'hits'        => [],
				'total_hits'  => 0,
				'page'        => $page,
				'total_pages' => 0,
			];
		}

		$db     = WP_Loupe_DB::get_instance();
		$engine = new WP_Loupe_Search_Engine( array_values( $post_types ), $db );
		$raw    = $engine->search( $query );

		$offset = ( $page - 1 ) * $per_page;
		$paged  = array_slice( $raw, $offset, $per_page );

		$hits = [];
		foreach ( $paged as $hit ) {
			$post_id = isset( $hit['id'] ) ? (int) $hit['id'] : 0;
			if ( ! $post_id ) {
				continue;
			}
			$post = get_post( $post_id );
			if ( ! $post || 'publish' !== $post->post_status ) {
				continue;
			}
			$post_type_obj = get_post_type_object( $post->post_type );
			if ( ! $post_type_obj || ! $post_type_obj->public ) {
				continue; // Public ability: never expose non-public post types.
			}
			$hits[] = [
				'id'        => $post_id,
				'title'     => get_the_title( $post ),
				'url'       => get_permalink( $post ),
				'excerpt'   => wp_strip_all_tags( get_the_excerpt( $post ) ),
				'post_type' => $post->post_type,
				'post_date' => get_the_date( 'c', $post ),
			];
		}

		$total_hits  = count( $raw );
		$total_pages = $per_page > 0 ? (int) ceil( $total_hits / $per_page ) : 1;

		return [
			'hits'        => $hits,
			'total_hits'  => $total_hits,
			'page'        => $page,
			'total_pages' => $total_pages,
		];
	}

	/**
	 * Execute the loupe-search/get-post ability (and its wp-loupe/get-post alias).
	 *
	 * @param array $input Validated input from the Abilities API.
	 * @return array|WP_Error Post data or error.
	 */
	public static function execute_get_post( array $input ) {
		$post_id = (int) ( $input['id'] ?? 0 );
		if ( ! $post_id ) {
			return new \WP_Error( 'invalid_id', __( 'A valid post ID is required.', 'loupe-search' ) );
		}

		$post = get_post( $post_id );
		if ( ! $post || 'publish' !== $post->post_status ) {
			return new \WP_Error( 'post_not_found', __( 'Post not found or not publicly accessible.', 'loupe-search' ) );
		}

		$post_type_obj = get_post_type_object( $post->post_type );
		if ( ! $post_type_obj || ! $post_type_obj->public ) {
			return new \WP_Error( 'post_not_found', __( 'Post not found or not publicly accessible.', 'loupe-search' ) );
		}

		return [
			'id'        => $post_id,
			'title'     => get_the_title( $post ),
			'content'   => wp_strip_all_tags( apply_filters( 'the_content', $post->post_content ) ),
			'excerpt'   => wp_strip_all_tags( get_the_excerpt( $post ) ),
			'url'       => get_permalink( $post ),
			'post_type' => $post->post_type,
			'post_date' => get_the_date( 'c', $post ),
			'author'    => get_the_author_meta( 'display_name', (int) $post->post_author ),
		];
	}
}
