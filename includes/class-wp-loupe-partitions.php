<?php
namespace Soderlind\Plugin\LoupeSearch;

/**
 * Index partitions: split one post type's index into several Loupe indexes.
 *
 * Core does not partition by itself. Every post type has a single, unnamed
 * partition ('') stored in the post type's index directory. Add-ons (for example
 * a multilingual add-on) use the filters below to give each post type several
 * named partitions, each stored in its own subdirectory with its own language,
 * so stemming and stop words match the content.
 *
 * @since 1.6.0
 */
final class WP_Loupe_Partitions {

	/**
	 * Partitions of a post type's index. Never empty.
	 *
	 * @param string $post_type Post type.
	 * @return string[] Sanitized, unique partition names; [''] when not partitioned.
	 */
	public static function all( string $post_type ): array {
		/**
		 * Filters the partitions a post type's index is split into.
		 *
		 * Each partition is a separate Loupe index stored in a subdirectory of the
		 * post type's index directory. Names are sanitized to lowercase a-z, 0-9,
		 * "-" and "_". Return [''] (the default) for a single, unpartitioned index.
		 * Reindex after changing the list.
		 *
		 * @since 1.6.0
		 * @param string[] $partitions Partition names. Default [''].
		 * @param string   $post_type  Post type.
		 */
		$partitions = apply_filters( 'loupe_search_partitions', [ '' ], $post_type );

		$clean = [];
		foreach ( (array) $partitions as $partition ) {
			$partition = self::sanitize( $partition );
			if ( '' !== $partition && ! in_array( $partition, $clean, true ) ) {
				$clean[] = $partition;
			}
		}
		return empty( $clean ) ? [ '' ] : $clean;
	}

	/**
	 * Whether a post type's index is split into named partitions.
	 *
	 * @param string $post_type Post type.
	 * @return bool
	 */
	public static function is_partitioned( string $post_type ): bool {
		return [ '' ] !== self::all( $post_type );
	}

	/**
	 * The partition a post is indexed in.
	 *
	 * @param \WP_Post $post Post.
	 * @return string One of all( $post->post_type ); the first one when the filter returns an unknown name.
	 */
	public static function for_post( \WP_Post $post ): string {
		$partitions = self::all( (string) $post->post_type );
		if ( [ '' ] === $partitions ) {
			return '';
		}

		/**
		 * Filters the partition a post is indexed in.
		 *
		 * Return one of the names from `loupe_search_partitions`. Any other value
		 * puts the post in the first partition.
		 *
		 * @since 1.6.0
		 * @param string   $partition Partition name. Default: the first partition.
		 * @param \WP_Post $post      Post being indexed.
		 */
		$partition = self::sanitize( apply_filters( 'loupe_search_post_partition', $partitions[ 0 ], $post ) );

		return in_array( $partition, $partitions, true ) ? $partition : $partitions[ 0 ];
	}

	/**
	 * Loupe language (stemming, stop words) for a partition.
	 *
	 * @param string $partition Partition name.
	 * @param string $post_type Post type.
	 * @return string Language code, see WP_Loupe_Factory::resolve_language().
	 */
	public static function language( string $partition, string $post_type ): string {
		$default = WP_Loupe_Factory::get_site_language();
		if ( '' === $partition ) {
			return $default;
		}

		/**
		 * Filters the Loupe language used for a partition's stemming and stop words.
		 *
		 * The `languages` key of the `loupe_search_advanced` option, when set,
		 * overrides this for every partition.
		 *
		 * @since 1.6.0
		 * @param string $language  Language code. Default: the site language.
		 * @param string $partition Partition name.
		 * @param string $post_type Post type.
		 */
		$language = apply_filters( 'loupe_search_partition_language', $default, $partition, $post_type );

		return is_string( $language ) && '' !== $language ? $language : $default;
	}

	/**
	 * Human-readable partition name for the Dashboard.
	 *
	 * @param string $partition Partition name.
	 * @param string $post_type Post type.
	 * @return string
	 */
	public static function label( string $partition, string $post_type ): string {
		/**
		 * Filters the label shown for a partition on the Dashboard.
		 *
		 * @since 1.6.0
		 * @param string $label     Label. Default: the partition name.
		 * @param string $partition Partition name.
		 * @param string $post_type Post type.
		 */
		$label = apply_filters( 'loupe_search_partition_label', $partition, $partition, $post_type );

		return is_scalar( $label ) && '' !== (string) $label ? (string) $label : $partition;
	}

	/**
	 * Partitions a search covers.
	 *
	 * @param string $post_type Post type being searched.
	 * @return string[] Subset of all( $post_type ), possibly empty.
	 */
	public static function for_query( string $post_type ): array {
		$partitions = self::all( $post_type );

		/**
		 * Filters the partitions a search covers, e.g. only the current language.
		 *
		 * Unknown names are ignored. Return an empty array to skip the post type.
		 * An unpartitioned post type has the single partition '' (empty string).
		 *
		 * @since 1.6.0
		 * @param string[] $partitions All partitions of the post type.
		 * @param string   $post_type  Post type being searched.
		 */
		$requested = apply_filters( 'loupe_search_query_partitions', $partitions, $post_type );

		$requested = array_map( [ self::class, 'sanitize' ], (array) $requested );
		return array_values( array_intersect( $partitions, $requested ) );
	}

	/**
	 * Sanitize a partition name to a safe directory name.
	 *
	 * @param mixed $partition Raw name.
	 * @return string
	 */
	public static function sanitize( $partition ): string {
		if ( ! is_scalar( $partition ) ) {
			return '';
		}
		return (string) preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $partition ) );
	}
}
