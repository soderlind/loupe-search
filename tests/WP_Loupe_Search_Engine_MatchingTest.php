<?php
namespace Soderlind\Plugin\LoupeSearch\Tests;

use PHPUnit\Framework\TestCase;
use Soderlind\Plugin\LoupeSearch\WP_Loupe_Search_Engine;

/**
 * Multi-word queries require every word, and widen to any word only when that
 * finds nothing in any post type.
 */
class WP_Loupe_Search_Engine_MatchingTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		global $wp_loupe_test_transients, $wp_loupe_test_filters;
		$wp_loupe_test_transients = [];
		$wp_loupe_test_filters    = [];
	}

	/**
	 * @param array<string,array<string,array<int,array<string,mixed>>>> $hits_by_type Hits per post type, keyed by matching strategy.
	 * @return array{0:WP_Loupe_Search_Engine,1:array<string,object>}
	 */
	private function make_engine( array $hits_by_type ): array {
		$engine = new WP_Loupe_Search_Engine( [], new \stdClass() );
		$loupes = [];
		$fields = [];
		foreach ( $hits_by_type as $post_type => $hits_by_strategy ) {
			$loupes[ $post_type ] = new class( $hits_by_strategy ) {
				/** @var string[] */
				public array $strategies = [];
				public function __construct( private array $hits_by_strategy ) {
				}
				public function search( $params ) {
					$strategy           = $params->getMatchingStrategy();
					$this->strategies[] = $strategy;
					$hits               = $this->hits_by_strategy[ $strategy ] ?? [];
					return new class( $hits ) {
						public function __construct( private array $hits ) {
						}
						public function toArray(): array {
							return [ 'hits' => $this->hits, 'totalHits' => count( $this->hits ), 'processingTimeMs' => 1 ];
						}
					};
				}
			};
			$fields[ $post_type ] = [ 'post_title' => [ 'indexable' => true, 'weight' => 1.0 ] ];
		}

		foreach ( [ 'post_types' => array_keys( $hits_by_type ), 'saved_fields' => $fields, 'loupe' => $loupes ] as $prop => $value ) {
			$ref = new \ReflectionProperty( WP_Loupe_Search_Engine::class, $prop );
			$ref->setAccessible( true );
			$ref->setValue( $engine, $value );
		}

		return [ $engine, $loupes ];
	}

	public function test_all_words_match_skips_fallback(): void {
		[ $engine, $loupes ] = $this->make_engine( [
			'post' => [ 'all' => [ [ 'id' => 1, '_rankingScore' => 0.9 ] ], 'any' => [ [ 'id' => 2, '_rankingScore' => 0.5 ] ] ],
			'page' => [ 'any' => [ [ 'id' => 3, '_rankingScore' => 0.4 ] ] ],
		] );

		$hits = $engine->search( 'pc-en er treg' );

		$this->assertSame( [ 1 ], array_column( $hits, 'id' ) );
		$this->assertSame( [ 'all' ], $loupes[ 'post' ]->strategies );
		$this->assertSame( [ 'all' ], $loupes[ 'page' ]->strategies );
	}

	public function test_falls_back_to_any_word_when_nothing_matches_all(): void {
		[ $engine, $loupes ] = $this->make_engine( [
			'post' => [ 'any' => [ [ 'id' => 2, '_rankingScore' => 0.5 ] ] ],
		] );

		$hits = $engine->search( 'treg xyzzy' );

		$this->assertSame( [ 2 ], array_column( $hits, 'id' ) );
		$this->assertSame( [ 'all', 'any' ], $loupes[ 'post' ]->strategies );
	}

	public function test_advanced_search_falls_back_only_without_hits(): void {
		[ $engine, $loupes ] = $this->make_engine( [
			'post' => [ 'all' => [ [ 'id' => 1 ] ], 'any' => [ [ 'id' => 1 ], [ 'id' => 2 ] ] ],
		] );
		$this->assertSame( 1, $engine->search_advanced( 'pc er treg', [] )[ 'totalHits' ] );
		$this->assertSame( [ 'all' ], $loupes[ 'post' ]->strategies );

		[ $engine, $loupes ] = $this->make_engine( [
			'post' => [ 'any' => [ [ 'id' => 1 ], [ 'id' => 2 ] ] ],
		] );
		$this->assertSame( 2, $engine->search_advanced( 'treg xyzzy', [] )[ 'totalHits' ] );
		$this->assertSame( [ 'all', 'any' ], $loupes[ 'post' ]->strategies );
	}
}
