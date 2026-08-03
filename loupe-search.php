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
 * Description:       Enhance the search functionality of your WordPress site with Loupe Search.
 * Version:           1.2.4
 * Author:            Per Soderlind
 * Author URI:        https://soderlind.no
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       loupe-search
 * Domain Path:       /languages
 */

declare(strict_types=1);
namespace Soderlind\Plugin\WPLoupe;

use Soderlind\Plugin\WPLoupe\WP_Loupe_Utils;

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
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
	// Don't run on autosave, Heartbeat or cron requests.
	if (
		( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) ||
		( defined( 'DOING_AJAX' ) && DOING_AJAX && isset( $_REQUEST[ 'action' ] ) && 'heartbeat' === $_REQUEST[ 'action' ] ) ||
		( defined( 'DOING_CRON' ) && DOING_CRON )
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
