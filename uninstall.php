<?php
/**
 * Uninstall script.
 *
 * @package  soderlind\plugin\WPLoupe
 */

// If uninstall.php is not called by WordPress, abort.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Include the base filesystem class from WordPress core.
require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';

// Include the direct filesystem class from WordPress core.
require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';

// Create a new instance of the direct filesystem class.
$file_system_direct = new \WP_Filesystem_Direct( false );

// Apply filter to get cache path, default is 'WP_CONTENT_DIR/loupe-search-db'.
$cache_path = apply_filters( 'loupe_search_db_path', WP_CONTENT_DIR . '/loupe-search-db' );
$cache_path = apply_filters_deprecated( 'wp_loupe_db_path', array( $cache_path ), '1.1.0', 'loupe_search_db_path' );

// If the cache directory exists, remove it and its contents.
if ( $file_system_direct->is_dir( $cache_path ) ) {
	$file_system_direct->rmdir( $cache_path, true );
}

// Also remove the legacy database folder if it exists (renamed to loupe-search-db in 1.1.0).
$legacy_cache_path = WP_CONTENT_DIR . '/wp-loupe-db';
if ( $legacy_cache_path !== $cache_path && $file_system_direct->is_dir( $legacy_cache_path ) ) {
	$file_system_direct->rmdir( $legacy_cache_path, true );
}

/**
 * Delete the plugin's options and cached search results for the current site.
 *
 * @return void
 */
function wp_loupe_delete_site_data() {
	global $wpdb;

	delete_option( 'wp_loupe_custom_post_types' );
	delete_option( 'wp_loupe_fields' );
	delete_option( 'wp_loupe_advanced' );

	// Cached search results. Removed directly because the transient names are hashed.
	$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- uninstall routine, no caching layer available.
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_wp_loupe_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_wp_loupe_' ) . '%'
		)
	);
}

if ( is_multisite() ) {
	foreach ( get_sites( [ 'fields' => 'ids', 'number' => 0 ] ) as $blog_id ) {
		switch_to_blog( (int) $blog_id );
		wp_loupe_delete_site_data();
		restore_current_blog();
	}
} else {
	wp_loupe_delete_site_data();
}
