<?php
namespace Soderlind\Plugin\WPLoupe;

/**
 * WordPress Abilities API integration for WP Loupe.
 *
 * Registers two abilities so AI agents and automation tools can discover
 * and execute WP Loupe search functionality via the standard Abilities API
 * (WordPress 6.9+).
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
	 * Register the wp-loupe ability category.
	 */
	public static function register_category(): void {
		wp_register_ability_category(
			'wp-loupe',
			[
				'label'       => __( 'Loupe Search', 'loupe-search' ),
				'description' => __( 'Search abilities provided by the Loupe Search plugin.', 'loupe-search' ),
			]
		);
	}

	/**
	 * Register all WP Loupe abilities.
	 */
	public static function register_abilities(): void {
		self::register_search_ability();
		self::register_get_post_ability();
	}

	/**
	 * Register the wp-loupe/search ability.
	 */
	private static function register_search_ability(): void {
		wp_register_ability(
			'wp-loupe/search',
			[
				'label'               => __( 'Search Posts', 'loupe-search' ),
				'description'         => __( 'Search WordPress content using Loupe Search\'s typo-tolerant full-text search engine. Supports phrase matching with quotes, exclusion with -, and OR searches.', 'loupe-search' ),
				'category'            => 'wp-loupe',
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
			]
		);
	}

	/**
	 * Register the wp-loupe/get-post ability.
	 */
	private static function register_get_post_ability(): void {
		wp_register_ability(
			'wp-loupe/get-post',
			[
				'label'               => __( 'Get Post', 'loupe-search' ),
				'description'         => __( 'Retrieve a single published post by its ID, including title, content, excerpt, URL, author, and publication date.', 'loupe-search' ),
				'category'            => 'wp-loupe',
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
			]
		);
	}

	/**
	 * Execute the wp-loupe/search ability.
	 *
	 * @param array $input Validated input from the Abilities API.
	 * @return array Search results.
	 */
	public static function execute_search( array $input ): array {
		$query    = sanitize_text_field( $input['query'] ?? '' );
		$per_page = min( 100, max( 1, (int) ( $input['per_page'] ?? 10 ) ) );
		$page     = max( 1, (int) ( $input['page'] ?? 1 ) );

		$options          = get_option( 'wp_loupe_custom_post_types', [] );
		$configured_types = ! empty( $options['wp_loupe_post_type_field'] )
			? (array) $options['wp_loupe_post_type_field']
			: [ 'post', 'page' ];

		$post_types = ! empty( $input['post_types'] )
			? array_intersect( array_map( 'sanitize_key', (array) $input['post_types'] ), $configured_types )
			: $configured_types;

		if ( empty( $post_types ) ) {
			$post_types = $configured_types;
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
	 * Execute the wp-loupe/get-post ability.
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
