<?php
/**
 * Public helper functions for add-ons.
 *
 * @package WP_Loupe
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'loupe_search_is_post_indexable' ) ) {
	/**
	 * Whether Loupe Search indexes the given post.
	 *
	 * Applies the same checks as the indexer: indexed post type, indexable status,
	 * password protection and the `loupe_search_is_indexable` filter (which also
	 * covers posts excluded from search). Returns false until Loupe Search has loaded.
	 *
	 * @since 1.5.0
	 * @param \WP_Post|int $post Post object or ID.
	 * @return bool
	 */
	function loupe_search_is_post_indexable( $post ): bool {
		$post = get_post( $post );
		if ( ! $post instanceof \WP_Post || ! class_exists( \Soderlind\Plugin\LoupeSearch\WP_Loupe_Indexer::class, false ) ) {
			return false;
		}
		return \Soderlind\Plugin\LoupeSearch\WP_Loupe_Indexer::is_post_indexable( $post );
	}
}
