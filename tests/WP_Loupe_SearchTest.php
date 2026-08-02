<?php
namespace Soderlind\Plugin\WPLoupe; // Match plugin namespace for class references.

use PHPUnit\Framework\TestCase;

/**
 * Tests for the deprecated WP_Loupe_Search facade.
 *
 * The class exists only to keep pre-0.6.0 callers working, so what matters is that it
 * announces its deprecation and forwards every call to the engine/hooks it replaced.
 *
 * @see includes/class-wp-loupe-search.php
 */
class WP_Loupe_SearchTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS[ 'wp_loupe_test_deprecated' ]  = [];
		$GLOBALS[ 'wp_loupe_test_is_admin' ]    = false;
		$GLOBALS[ 'wp_loupe_test_doing_ajax' ]  = false;
		$GLOBALS[ 'wp_loupe_test_filters' ]     = [];
	}

	protected function tearDown(): void {
		$GLOBALS[ 'wp_loupe_test_filters' ]    = [];
		$GLOBALS[ 'wp_loupe_test_is_admin' ]   = false;
		$GLOBALS[ 'wp_loupe_test_doing_ajax' ] = false;
		parent::tearDown();
	}

	private function read( WP_Loupe_Search $search, string $property ) {
		return ( new \ReflectionProperty( WP_Loupe_Search::class, $property ) )->getValue( $search );
	}

	private function write( WP_Loupe_Search $search, string $property, $value ): void {
		( new \ReflectionProperty( WP_Loupe_Search::class, $property ) )->setValue( $search, $value );
	}

	/** Records what it was asked to do so delegation can be asserted exactly. */
	private function spy_hooks(): object {
		return new class {
			public array $pre_query_args = [];
			public int $footer_calls     = 0;
			public function posts_pre_query( $posts, $query ) {
				$this->pre_query_args = [ $posts, $query ];
				return [ 'from-hooks' ];
			}
			public function action_wp_footer(): void {
				$this->footer_calls++;
			}
		};
	}

	public function test_it_announces_its_deprecation_in_favour_of_the_search_engine() {
		new WP_Loupe_Search( [] );

		$this->assertSame(
			[ WP_Loupe_Search::class, '0.6.0', WP_Loupe_Search_Engine::class ],
			$GLOBALS[ 'wp_loupe_test_deprecated' ][ 0 ] ?? null
		);
	}

	public function test_it_still_intercepts_front_end_queries_when_instantiated() {
		$GLOBALS[ 'wp_loupe_test_hooks' ] = [];

		$search = new WP_Loupe_Search( [] );

		$hooks = $this->read( $search, 'hooks' );
		$this->assertInstanceOf( WP_Loupe_Search_Hooks::class, $hooks );
		$this->assertSame(
			[ [ $hooks, 'posts_pre_query' ], 10 ],
			$GLOBALS[ 'wp_loupe_test_hooks' ][ 'posts_pre_query' ][ 0 ] ?? null,
			'the facade must register the hooks it creates, not just build them'
		);
	}

	public function test_it_does_not_intercept_queries_in_the_admin() {
		$GLOBALS[ 'wp_loupe_test_is_admin' ] = true;

		$search = new WP_Loupe_Search( [] );

		$this->assertNull( $this->read( $search, 'hooks' ) );
	}

	public function test_search_returns_the_engine_hits_and_captures_the_engine_log() {
		$search = new WP_Loupe_Search( [] );
		$this->write( $search, 'engine', new class {
			public function search( $query ) {
				return [ [ 'id' => 1, 'query' => $query ] ];
			}
			public function get_log() {
				return 'engine log';
			}
		} );

		$this->assertSame( [ [ 'id' => 1, 'query' => 'dune' ] ], $search->search( 'dune' ) );
		$this->assertSame( 'engine log', $search->get_log() );
	}

	public function test_get_log_is_null_until_a_search_has_run() {
		$this->assertNull( ( new WP_Loupe_Search( [] ) )->get_log() );
	}

	public function test_posts_pre_query_hands_off_to_the_hooks() {
		$search = new WP_Loupe_Search( [] );
		$spy    = $this->spy_hooks();
		$this->write( $search, 'hooks', $spy );
		$query = new \WP_Query();

		$this->assertSame( [ 'from-hooks' ], $search->posts_pre_query( null, $query ) );
		$this->assertSame( [ null, $query ], $spy->pre_query_args );
	}

	/**
	 * Returning null leaves the query untouched, which is what must happen where the
	 * facade never registered hooks (admin, REST, WP-CLI).
	 */
	public function test_posts_pre_query_leaves_the_query_alone_without_hooks() {
		$GLOBALS[ 'wp_loupe_test_is_admin' ] = true;
		$search                              = new WP_Loupe_Search( [] );

		$this->assertNull( $search->posts_pre_query( [ 'untouched' ], new \WP_Query() ) );
	}

	public function test_action_wp_footer_hands_off_to_the_hooks() {
		$search = new WP_Loupe_Search( [] );
		$spy    = $this->spy_hooks();
		$this->write( $search, 'hooks', $spy );

		$search->action_wp_footer();

		$this->assertSame( 1, $spy->footer_calls );
	}

	public function test_action_wp_footer_is_a_no_op_without_hooks() {
		$GLOBALS[ 'wp_loupe_test_is_admin' ] = true;
		$search                              = new WP_Loupe_Search( [] );

		$search->action_wp_footer();

		$this->assertNull( $this->read( $search, 'hooks' ) );
	}
}
