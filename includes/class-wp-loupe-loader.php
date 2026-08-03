<?php
namespace Soderlind\Plugin\WPLoupe;

/**
 * Main plugin loader
 *
 * @package Soderlind\Plugin\WPLoupe
 * @since 0.0.11
 */
class WP_Loupe_Loader {
	private static $instance = null;
	private $search_engine;
	private $search_hooks;
	private $indexer;
	private $post_types;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->load_dependencies();
		$this->migrate_reserved_prefix_data();
		$this->setup_post_types();
		$this->init_components();
		$this->register_hooks();
	}

	/**
	 * Load dependencies
	 * 
	 * @return void
	 */
	private function load_dependencies() {
		require_once LOUPE_SEARCH_PATH . 'vendor/autoload.php';
		require_once LOUPE_SEARCH_PATH . 'includes/class-wp-loupe-schema-manager.php';
		require_once LOUPE_SEARCH_PATH . 'includes/class-wp-loupe-factory.php';
		require_once LOUPE_SEARCH_PATH . 'includes/class-wp-loupe-search.php';
		require_once LOUPE_SEARCH_PATH . 'includes/class-wp-loupe-search-engine.php';
		require_once LOUPE_SEARCH_PATH . 'includes/class-wp-loupe-search-hooks.php';
		require_once LOUPE_SEARCH_PATH . 'includes/class-wp-loupe-indexer.php';
		require_once LOUPE_SEARCH_PATH . 'includes/class-wp-loupe-db.php';
		require_once LOUPE_SEARCH_PATH . 'includes/class-wp-loupe-utils.php';
		require_once LOUPE_SEARCH_PATH . 'includes/class-wp-loupe-settings.php';
		require_once LOUPE_SEARCH_PATH . 'includes/class-wp-loupe-rest.php';
		require_once LOUPE_SEARCH_PATH . 'includes/class-wp-loupe-abilities.php';
	}

	/**
	 * Move stored data off the reserved `wp_` prefix.
	 *
	 * `wp_` is reserved for WordPress core, so the plugin's options moved to
	 * `loupe_search_*` in 1.2.4. Runs once per site and removes the old rows.
	 *
	 * @since 1.2.4
	 * @return void
	 */
	private function migrate_reserved_prefix_data() {
		if ( get_option( 'loupe_search_prefix_migrated' ) ) {
			return;
		}

		$renamed_options = [
			'wp_loupe_custom_post_types' => 'loupe_search_custom_post_types',
			'wp_loupe_fields'            => 'loupe_search_fields',
			'wp_loupe_advanced'          => 'loupe_search_advanced',
		];

		$migrated = false;

		foreach ( $renamed_options as $old_name => $new_name ) {
			$value = get_option( $old_name, null );
			if ( null === $value ) {
				continue;
			}

			if ( is_array( $value ) && isset( $value[ 'wp_loupe_post_type_field' ] ) ) {
				$value[ 'loupe_search_post_type_field' ] = $value[ 'wp_loupe_post_type_field' ];
				unset( $value[ 'wp_loupe_post_type_field' ] );
			}

			if ( false === get_option( $new_name, false ) ) {
				update_option( $new_name, $value );
			}

			delete_option( $old_name );
			$migrated = true;
		}

		if ( $migrated ) {
			// Result caches are keyed by a hash of the query, so they cannot be renamed.
			WP_Loupe_Utils::remove_transient( 'wp_loupe_search_' );
		}

		update_option( 'loupe_search_prefix_migrated', 1, false );
	}

	/**
	 * Setup post types
	 * 
	 * @return void
	 */
	private function setup_post_types() {
		$this->post_types = WP_Loupe_Utils::get_indexed_post_types();
	}

	/**
	 * Initialize components
	 * 
	 * @return void
	 */
	private function init_components() {
		new WPLoupe_Settings_Page();

		$this->search_engine = new WP_Loupe_Search_Engine( $this->post_types );
		// Front-end only: register query interception + footer timing.
		if ( ( ! is_admin() || wp_doing_ajax() ) && ! ( defined( 'REST_REQUEST' ) && REST_REQUEST ) && ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			$this->search_hooks = new WP_Loupe_Search_Hooks( $this->search_engine );
			$this->search_hooks->register();
		}
		$this->indexer = new WP_Loupe_Indexer( $this->post_types );

		// Initialize REST API handler
		new WP_Loupe_REST();

		// Register Abilities API (WordPress 6.9+)
		WP_Loupe_Abilities::init();
	}

	/**
	 * Register hooks
	 * 
	 * @return void
	 */
	private function register_hooks() {
		add_action( 'init', [ $this, 'load_textdomain' ] );
	}

	/**
	 * Load textdomain
	 * 
	 * @return void
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'loupe-search', false, dirname( plugin_basename( LOUPE_SEARCH_FILE ) ) . '/languages' );
	}
}
