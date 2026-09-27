<?php
namespace Soderlind\Plugin\LoupeSearch\Tests;

use PHPUnit\Framework\TestCase;
use Soderlind\Plugin\LoupeSearch\WP_Loupe_Search_Engine;

/**
 * Explicit-vs-implicit sorting (issue #64): sortable fields configure eligibility
 * only; a normal search must stay relevance-ordered and never call withSort().
 */
class WP_Loupe_Search_Engine_SortTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		global $wp_loupe_test_transients, $wp_loupe_test_filters;
		$wp_loupe_test_transients = [];
		$wp_loupe_test_filters    = [];
	}

	/**
	 * @param array<int,array<string,mixed>> $hits
	 */
	private function make_engine( array $hits ): array {
		$engine = new WP_Loupe_Search_Engine( [], new \stdClass() );

		$loupe = new class( $hits ) {
			/** @var array<int,object> */
			public array $captured = [];
			private array $hits;
			public function __construct( array $hits ) {
				$this->hits = $hits;
			}
			public function search( $params ) {
				$this->captured[] = $params;
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

		$saved_fields = [
			'post' => [
				'post_title' => [ 'indexable' => true, 'weight' => 1.0, 'sortable' => true, 'sort_direction' => 'desc' ],
				'post_date'  => [ 'indexable' => true, 'weight' => 1.0, 'sortable' => true, 'sort_direction' => 'desc' ],
			],
		];

		$this->set_private( $engine, 'post_types', [ 'post' ] );
		$this->set_private( $engine, 'saved_fields', $saved_fields );
		$this->set_private( $engine, 'loupe', [ 'post' => $loupe ] );

		return [ $engine, $loupe ];
	}

	private function set_private( object $obj, string $prop, $value ): void {
		$ref = new \ReflectionProperty( WP_Loupe_Search_Engine::class, $prop );
		$ref->setAccessible( true );
		$ref->setValue( $obj, $value );
	}

	public function test_regular_search_does_not_apply_sort(): void {
		[ $engine, $loupe ] = $this->make_engine( [
			[ 'id' => 1, '_rankingScore' => 0.30, 'post_date' => '2020-01-01' ],
			[ 'id' => 2, '_rankingScore' => 0.90, 'post_date' => '2019-01-01' ],
		] );

		$hits = $engine->search( 'alpha' );

		// Loupe's default is relevance; the sortable fields must not be injected.
		$this->assertSame( [ '_relevance:desc' ], $loupe->captured[ 0 ]->getSort(), 'sortable fields must not be auto-applied' );
		// Relevance order: higher score first.
		$this->assertSame( [ 2, 1 ], array_column( $hits, 'id' ) );
	}

	public function test_explicit_sort_applies_and_orders_by_field(): void {
		[ $engine, $loupe ] = $this->make_engine( [
			[ 'id' => 1, '_rankingScore' => 0.90, 'post_date' => '2020-01-01' ],
			[ 'id' => 2, '_rankingScore' => 0.10, 'post_date' => '2019-01-01' ],
		] );

		$hits = $engine->search( 'alpha', [], [ 'post_date:asc' ] );

		$this->assertSame( [ 'post_date:asc' ], $loupe->captured[ 0 ]->getSort() );
		// Ascending by date: 2019 (id 2) before 2020 (id 1), despite lower score.
		$this->assertSame( [ 2, 1 ], array_column( $hits, 'id' ) );
	}

	public function test_non_sortable_field_is_ignored(): void {
		[ $engine, $loupe ] = $this->make_engine( [
			[ 'id' => 1, '_rankingScore' => 0.30 ],
		] );

		$engine->search( 'alpha', [], [ 'post_content:asc' ] );

		$this->assertSame( [ '_relevance:desc' ], $loupe->captured[ 0 ]->getSort(), 'a non-sortable field must not be applied' );
	}
}
