<?php
namespace Soderlind\Plugin\LoupeSearch\Tests;

use PHPUnit\Framework\TestCase;
use Soderlind\Plugin\LoupeSearch\WP_Loupe_Search_Engine;

/**
 * Cross-post-type result ordering (issue #51b).
 *
 * Each post type is a separate index, so the engine queries them in turn. These tests
 * assert that the combined hits are merged by relevance score instead of staying grouped
 * by post type, and that the loupe_search_order_results filter can override that policy.
 */
class WP_Loupe_Search_Engine_OrderTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		global $wp_loupe_test_transients, $wp_loupe_test_filters;
		$wp_loupe_test_transients = [];
		$wp_loupe_test_filters    = [];
	}

	protected function tearDown(): void {
		global $wp_loupe_test_filters;
		$wp_loupe_test_filters = [];
		parent::tearDown();
	}

	/**
	 * Build an engine with fake per-post-type Loupe instances injected.
	 *
	 * @param array<string,array<int,array<string,mixed>>> $hits_by_type post_type => list of raw hit arrays.
	 */
	private function engine_returning( array $hits_by_type ): WP_Loupe_Search_Engine {
		// Empty post-types skips real Loupe creation in the constructor; a dummy db is
		// never touched on the search-merge path.
		$engine = new WP_Loupe_Search_Engine( [], new \stdClass() );

		$post_types   = array_keys( $hits_by_type );
		$saved_fields = [];
		$loupe        = [];
		foreach ( $hits_by_type as $post_type => $hits ) {
			$saved_fields[ $post_type ] = [ 'post_title' => [ 'indexable' => true, 'weight' => 1.0 ] ];
			$loupe[ $post_type ]        = new class( $hits ) {
				private array $hits;
				public function __construct( array $hits ) {
					$this->hits = $hits;
				}
				public function search( $params ) {
					return new class( $this->hits ) {
						private array $hits;
						public function __construct( array $hits ) {
							$this->hits = $hits;
						}
						public function toArray(): array {
							return [ 'hits' => $this->hits, 'processingTimeMs' => 1 ];
						}
					};
				}
			};
		}

		$this->set_private( $engine, 'post_types', $post_types );
		$this->set_private( $engine, 'saved_fields', $saved_fields );
		$this->set_private( $engine, 'loupe', $loupe );

		return $engine;
	}

	private function set_private( object $obj, string $prop, $value ): void {
		$ref = new \ReflectionProperty( WP_Loupe_Search_Engine::class, $prop );
		$ref->setAccessible( true );
		$ref->setValue( $obj, $value );
	}

	public function test_hits_are_merged_across_post_types_by_score(): void {
		$engine = $this->engine_returning( [
			'post' => [ [ 'id' => 1, '_rankingScore' => 0.30 ] ],
			'page' => [ [ 'id' => 2, '_rankingScore' => 0.80 ] ],
		] );

		$hits = $engine->search( 'alpha' );

		// The page scores higher, so it must precede the post despite being indexed later.
		$this->assertSame( [ 2, 1 ], array_column( $hits, 'id' ) );
	}

	public function test_equal_scores_keep_post_type_order(): void {
		$engine = $this->engine_returning( [
			'post' => [ [ 'id' => 1, '_rankingScore' => 0.50 ] ],
			'page' => [ [ 'id' => 2, '_rankingScore' => 0.50 ] ],
		] );

		$hits = $engine->search( 'bravo' );

		$this->assertSame( [ 1, 2 ], array_column( $hits, 'id' ) );
	}

	public function test_order_results_filter_can_override_the_merge(): void {
		global $wp_loupe_test_filters;
		// Reverse the relevance order to prove the filter is applied last.
		$wp_loupe_test_filters[ 'loupe_search_order_results' ] = static fn( array $hits ): array => array_reverse( $hits );

		$engine = $this->engine_returning( [
			'post' => [ [ 'id' => 1, '_rankingScore' => 0.30 ] ],
			'page' => [ [ 'id' => 2, '_rankingScore' => 0.80 ] ],
		] );

		$hits = $engine->search( 'charlie' );

		$this->assertSame( [ 1, 2 ], array_column( $hits, 'id' ) );
	}
}
