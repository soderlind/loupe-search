<?php
namespace Soderlind\Plugin\WPLoupe; // Match plugin namespace for class references.

use PHPUnit\Framework\TestCase;

/**
 * Tests for the plugin loader's wiring decisions.
 *
 * The loader owns two rules worth pinning: query interception is front-end only, and the
 * translations live in the plugin's own languages directory.
 *
 * @see includes/class-wp-loupe-loader.php
 */
class WP_Loupe_LoaderTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS[ 'wp_loupe_test_is_admin' ]    = false;
		$GLOBALS[ 'wp_loupe_test_doing_ajax' ]  = false;
		$GLOBALS[ 'wp_loupe_test_textdomains' ] = [];
		$this->reset_options();
		// No post types keeps the engine, indexer and REST handler from opening Loupe indexes.
		$GLOBALS[ 'wp_loupe_test_filters' ] = [
			'loupe_search_post_types' => function () {
				return [];
			},
		];
		( new \ReflectionProperty( WP_Loupe_Loader::class, 'instance' ) )->setValue( null, null );
	}

	protected function tearDown(): void {
		( new \ReflectionProperty( WP_Loupe_Loader::class, 'instance' ) )->setValue( null, null );
		$GLOBALS[ 'wp_loupe_test_filters' ]    = [];
		$GLOBALS[ 'wp_loupe_test_is_admin' ]   = false;
		$GLOBALS[ 'wp_loupe_test_doing_ajax' ] = false;
		$this->reset_options();
		parent::tearDown();
	}

	private function reset_options(): void {
		foreach ( [ 'loupe_search_prefix_migrated', 'wp_loupe_custom_post_types', 'wp_loupe_fields', 'wp_loupe_advanced', 'loupe_search_custom_post_types', 'loupe_search_fields', 'loupe_search_advanced' ] as $option ) {
			delete_option( $option );
		}
	}

	private function read( WP_Loupe_Loader $loader, string $property ) {
		return ( new \ReflectionProperty( WP_Loupe_Loader::class, $property ) )->getValue( $loader );
	}

	public function test_get_instance_returns_a_single_shared_loader() {
		$this->assertSame( WP_Loupe_Loader::get_instance(), WP_Loupe_Loader::get_instance() );
	}

	public function test_it_intercepts_queries_on_the_front_end() {
		$GLOBALS[ 'wp_loupe_test_hooks' ] = [];

		$loader = WP_Loupe_Loader::get_instance();

		$hooks = $this->read( $loader, 'search_hooks' );
		$this->assertInstanceOf( WP_Loupe_Search_Hooks::class, $hooks );
		$this->assertSame(
			[ [ $hooks, 'posts_pre_query' ], 10 ],
			$GLOBALS[ 'wp_loupe_test_hooks' ][ 'posts_pre_query' ][ 0 ] ?? null,
			'the loader must register the hooks it creates, not just build them'
		);
	}

	public function test_it_loads_translations_on_init() {
		$GLOBALS[ 'wp_loupe_test_hooks' ] = [];

		$loader = WP_Loupe_Loader::get_instance();

		$this->assertSame(
			[ [ $loader, 'load_textdomain' ], 10 ],
			$GLOBALS[ 'wp_loupe_test_hooks' ][ 'init' ][ 0 ] ?? null
		);
	}

	public function test_it_does_not_intercept_queries_in_the_admin() {
		$GLOBALS[ 'wp_loupe_test_is_admin' ] = true;

		$loader = WP_Loupe_Loader::get_instance();

		$this->assertNull( $this->read( $loader, 'search_hooks' ) );
	}

	/**
	 * Admin-ajax requests render front-end results, so they must keep the interception.
	 */
	public function test_it_intercepts_queries_during_admin_ajax() {
		$GLOBALS[ 'wp_loupe_test_is_admin' ]   = true;
		$GLOBALS[ 'wp_loupe_test_doing_ajax' ] = true;

		$loader = WP_Loupe_Loader::get_instance();

		$this->assertInstanceOf( WP_Loupe_Search_Hooks::class, $this->read( $loader, 'search_hooks' ) );
	}

	public function test_it_always_builds_a_search_engine_and_indexer() {
		$loader = WP_Loupe_Loader::get_instance();

		$this->assertInstanceOf( WP_Loupe_Search_Engine::class, $this->read( $loader, 'search_engine' ) );
		$this->assertInstanceOf( WP_Loupe_Indexer::class, $this->read( $loader, 'indexer' ) );
	}

	public function test_it_takes_its_post_types_from_the_plugin_settings() {
		$GLOBALS[ 'wp_loupe_test_filters' ][ 'loupe_search_post_types' ] = function () {
			return [];
		};

		$loader = WP_Loupe_Loader::get_instance();

		$this->assertSame( [], $this->read( $loader, 'post_types' ) );
		$this->assertSame( [], $this->read( $loader, 'search_engine' )->get_post_types() );
	}

	public function test_load_textdomain_points_at_the_plugin_languages_directory() {
		WP_Loupe_Loader::get_instance()->load_textdomain();

		$this->assertSame(
			[ 'loupe-search', false, 'loupe-search/languages' ],
			$GLOBALS[ 'wp_loupe_test_textdomains' ][ 0 ] ?? null
		);
	}

	public function test_it_moves_stored_settings_off_the_reserved_wp_prefix() {
		delete_option( 'loupe_search_prefix_migrated' );
		update_option( 'wp_loupe_custom_post_types', [ 'wp_loupe_post_type_field' => [ 'book' ] ] );
		update_option( 'wp_loupe_fields', [ 'book' => [ 'post_title' => [ 'indexable' => true ] ] ] );
		update_option( 'wp_loupe_advanced', [ 'loupe_search_typo_enabled' => true ] );

		WP_Loupe_Loader::get_instance();

		$this->assertSame(
			[ 'loupe_search_post_type_field' => [ 'book' ] ],
			get_option( 'loupe_search_custom_post_types' ),
			'the inner post type key must be renamed alongside the option'
		);
		$this->assertSame( [ 'book' => [ 'post_title' => [ 'indexable' => true ] ] ], get_option( 'loupe_search_fields' ) );
		$this->assertSame( [ 'loupe_search_typo_enabled' => true ], get_option( 'loupe_search_advanced' ) );

		$this->assertFalse( get_option( 'wp_loupe_custom_post_types', false ), 'the legacy option must not be left behind' );
		$this->assertFalse( get_option( 'wp_loupe_fields', false ) );
		$this->assertFalse( get_option( 'wp_loupe_advanced', false ) );
	}

	public function test_it_does_not_overwrite_settings_that_were_already_migrated() {
		delete_option( 'loupe_search_prefix_migrated' );
		update_option( 'wp_loupe_fields', [ 'stale' => true ] );
		update_option( 'loupe_search_fields', [ 'current' => true ] );

		WP_Loupe_Loader::get_instance();

		$this->assertSame( [ 'current' => true ], get_option( 'loupe_search_fields' ) );
		$this->assertFalse( get_option( 'wp_loupe_fields', false ) );
	}
}
