<?php
/**
 * The plugin bootstrap file
 *
 * @link              https://github.com/soderlind/loupe-search
 * @since             0.0.1
 * @package           WP_Loupe
 *
 * @wordpress-plugin
 * Plugin Name:       Loupe Search
 * Plugin URI:        https://github.com/soderlind/loupe-search
 * Description:       Fast, index-backed WordPress search with typo tolerance, phrase matching, exclusions, custom post types, real-time indexing, and a developer-friendly REST API.
 * Version:           1.3.5
 * Author:            Per Soderlind
 * Author URI:        https://soderlind.no
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       loupe-search
 * Domain Path:       /languages
 * Requires at least: 6.9
 * Requires PHP:      8.3
 */

declare(strict_types=1);
namespace Soderlind\Plugin\LoupeSearch;

use Soderlind\Plugin\LoupeSearch\WP_Loupe_Utils;

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

// Stand down if the predecessor "WP Loupe" plugin is still active. It ships the
// same components under the old Soderlind\Plugin\WPLoupe namespace, so running
// both would duplicate search interception and REST routes.
if (
	in_array( 'wp-loupe/wp-loupe.php', (array) get_option( 'active_plugins', array() ), true )
	|| isset( ( (array) get_site_option( 'active_sitewide_plugins', array() ) )[ 'wp-loupe/wp-loupe.php' ] )
	|| class_exists( 'Soderlind\\Plugin\\WPLoupe\\WP_Loupe_Loader', false )
) {
	add_action( 'admin_notices', function () {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		printf(
			'<div class="notice notice-error"><p><strong>%1$s</strong> %2$s</p></div>',
			esc_html__( 'Loupe Search is inactive.', 'loupe-search' ),
			esc_html__( 'The older “WP Loupe” plugin is still active and supersedes it. Deactivate and delete WP Loupe to use Loupe Search.', 'loupe-search' )
		);
	} );
	return;
}

define( 'LOUPE_SEARCH_FILE', __FILE__ );
define( 'LOUPE_SEARCH_NAME', plugin_basename( LOUPE_SEARCH_FILE ) );
define( 'LOUPE_SEARCH_PATH', plugin_dir_path( LOUPE_SEARCH_FILE ) );
define( 'LOUPE_SEARCH_URL', plugin_dir_url( LOUPE_SEARCH_FILE ) );

require_once LOUPE_SEARCH_PATH . 'includes/class-wp-loupe-loader.php';
// Load CLI commands if in WP-CLI context
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once LOUPE_SEARCH_PATH . 'includes/class-wp-loupe-cli.php';
}

/**
 * Initialize plugin
 */
function init() {
	// Don't run on autosave or Heartbeat requests. Cron must run: scheduled-post
	// publishing and imports/syncs fire wp_after_insert_post during WP-Cron, and the
	// indexer only hears it when the loader is wired up (see issue #52).
	if (
		( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) ||
		( defined( 'DOING_AJAX' ) && DOING_AJAX && isset( $_REQUEST[ 'action' ] ) && 'heartbeat' === $_REQUEST[ 'action' ] )
	) {
		return;
	}

	WP_Loupe_Loader::get_instance();
	if ( ! WP_Loupe_Utils::has_sqlite() ) {
		return;
	}

	// new WP_Loupe_Updater( LOUPE_SEARCH_FILE );
}
add_action( 'plugins_loaded', __NAMESPACE__ . '\\init' );

/**
 * Activation hook: flush rewrite rules to register .well-known endpoints once they are added.
 */
function activate( $network_wide = false ) {
	// When network activated, iterate over all sites to ensure rewrite rules include .well-known endpoints.
	if ( is_multisite() && $network_wide ) {
		global $wpdb;
		$blog_ids = $wpdb->get_col( "SELECT blog_id FROM {$wpdb->blogs} WHERE public = 1" );
		foreach ( $blog_ids as $blog_id ) {
			switch_to_blog( (int) $blog_id );
			flush_rewrite_rules();
			restore_current_blog();
		}
	} else {
		flush_rewrite_rules();
	}
}

/**
 * Deactivation hook: flush rewrite rules to remove custom endpoints.
 */
function deactivate() {
	flush_rewrite_rules();
}

register_activation_hook( LOUPE_SEARCH_FILE, __NAMESPACE__ . '\\activate' );

// Ensure new subsites get rewrite rules for .well-known endpoints.
function on_new_blog( $blog_id ) {
	if ( ! is_multisite() ) {
		return;
	}
	switch_to_blog( (int) $blog_id );
	// Trigger init hooks that add rewrite rules.
	do_action( 'init' );
	flush_rewrite_rules();
	restore_current_blog();
}
add_action( 'wpmu_new_blog', __NAMESPACE__ . '\\on_new_blog', 20 );
register_deactivation_hook( LOUPE_SEARCH_FILE, __NAMESPACE__ . '\\deactivate' );
