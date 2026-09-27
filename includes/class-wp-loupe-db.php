<?php
namespace Soderlind\Plugin\LoupeSearch;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Database management class for WP Loupe
 *
 * @package Soderlind\Plugin\LoupeSearch
 * @since 0.0.1
 */
class WP_Loupe_DB {
	/**
	 * Instance of this class
	 *
	 * @since 0.0.1
	 * @var WP_Loupe_DB
	 */
	private static $instance = null;

	/**
	 * Get instance of this class
	 *
	 * @since 0.0.1
	 * @return WP_Loupe_DB Instance of this class
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Delete the search index
	 *
	 * @since 0.0.1
	 * @return bool True if index was deleted, false otherwise
	 */
	public function delete_index() {
		// Only load the filesystem classes if needed
		if (!class_exists('WP_Filesystem_Direct')) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
			require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
		}

		$file_system = new \WP_Filesystem_Direct(false);
		$cache_path = $this->get_base_path();

		if ($file_system->is_dir($cache_path)) {
			return $file_system->rmdir($cache_path, true);
		}
		
		return true;
	}

	/**
	 * Get database path for a post type
	 *
	 * @since 0.0.1
	 * @param string $post_type Post type.
	 * @return string Path to database file
	 */
	public function get_db_path($post_type) {
		$base_path = $this->get_base_path();
		$path      = rtrim( $base_path, '/' ) . '/' . ltrim( (string) $post_type, '/' );
		$this->ensure_directory_exists( $path );
		return $path;
	}
	
	/**
	 * Get base path for all Loupe databases
	 *
	 * @since 0.0.1
	 * @return string Base path
	 */
	public function get_base_path() {
		$default = defined( 'WP_CONTENT_DIR' ) ? ( WP_CONTENT_DIR . '/loupe-search-db' ) : '';

		// On multisite each site needs its own index directory; otherwise every site
		// in the network shares one path and overwrites the others (issue #56).
		$is_multisite = function_exists( 'is_multisite' ) && is_multisite();
		if ( '' !== $default && $is_multisite ) {
			$default .= '/site-' . ( function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 1 );
		}

		// Backward compatibility (deprecated since 1.1.0): if the legacy `wp-loupe-db`
		// folder exists and the new `loupe-search-db` folder does not, keep using the
		// legacy path so existing indexes are not orphaned. Single-site only: the legacy
		// layout never namespaced per site, so it must not be reused on a network.
		if ( '' !== $default && ! $is_multisite ) {
			$legacy = WP_CONTENT_DIR . '/wp-loupe-db';
			if ( is_dir( $legacy ) && ! is_dir( $default ) ) {
				$default = $legacy;
			}
		}

		$path    = apply_filters( 'loupe_search_db_path', $default );
		$path    = apply_filters_deprecated( 'wp_loupe_db_path', array( $path ), '1.1.0', 'loupe_search_db_path' );
		$path    = is_string( $path ) ? trim( $path ) : '';

		// Guard against misbehaving filters returning empty/false.
		if ( '' === $path ) {
			$path = $default;
		}

		$path = is_string( $path ) ? rtrim( $path, '/' ) : '';
		$this->ensure_directory_exists( $path );
		$this->maybe_protect_directory( $path );
		// Also protect the top-level index root so pre-1.3.7 shared files (from before
		// the multisite per-site split) are not left web-readable after an upgrade.
		if ( defined( 'WP_CONTENT_DIR' ) ) {
			$this->maybe_protect_directory( WP_CONTENT_DIR . '/loupe-search-db' );
		}
		return $path;
	}

	/**
	 * Best-effort ensure that the directory exists.
	 *
	 * @param string $path
	 */
	private function ensure_directory_exists( string $path ): void {
		if ( '' === $path ) {
			return;
		}

		if ( function_exists( 'wp_mkdir_p' ) ) {
			wp_mkdir_p( $path );
			return;
		}

		// Fallback for very early contexts.
		if ( ! is_dir( $path ) ) {
			@mkdir( $path, 0755, true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- fallback for very early contexts before wp_mkdir_p() is available.
		}
	}

	/**
	 * Drop server-level protection files into the index directory so the SQLite
	 * files (which can contain private post data) are not web-accessible. Written
	 * once and skipped on later calls. Apache and IIS are covered directly; nginx
	 * users must deny access to the directory in their server config.
	 *
	 * @param string $path Base index directory.
	 */
	private function maybe_protect_directory( string $path ): void {
		if ( '' === $path || ! is_dir( $path ) ) {
			return;
		}

		$files = [
			'.htaccess'  => "# Loupe Search: deny direct access to index files.\n<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tOrder allow,deny\n\tDeny from all\n</IfModule>\n",
			'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration>\n\t<system.webServer>\n\t\t<authorization>\n\t\t\t<deny users=\"*\" />\n\t\t</authorization>\n\t</system.webServer>\n</configuration>\n",
			'index.php'  => "<?php\n// Silence is golden.\n",
		];

		foreach ( $files as $name => $contents ) {
			$file = $path . '/' . $name;
			if ( ! file_exists( $file ) ) {
				@file_put_contents( $file, $contents ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- best-effort protection file, may run before WP_Filesystem is available.
			}
		}
	}
}
