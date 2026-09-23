<?php
namespace Soderlind\Plugin\LoupeSearch\Tests;

use PHPUnit\Framework\TestCase;
use Soderlind\Plugin\LoupeSearch\WP_Loupe_Factory;

class WP_Loupe_Factory_WeightOrderTest extends TestCase {

	/**
	 * @return array Attributes extracted from the given field config.
	 */
	private function extract( array $fields ): array {
		$method = new \ReflectionMethod( WP_Loupe_Factory::class, 'extract_attributes_from_fields' );
		$method->setAccessible( true );
		return $method->invoke( null, $fields );
	}

	public function test_searchable_attributes_are_ordered_by_weight_desc(): void {
		$attrs = $this->extract( [
			'post_content'      => [ 'indexable' => true, 'weight' => 1.0 ],
			'post_title'        => [ 'indexable' => true, 'weight' => 2.0 ],
			'taxonomy_category' => [ 'indexable' => true, 'weight' => 1.5 ],
		] );

		$this->assertSame(
			[ 'post_title', 'taxonomy_category', 'post_content' ],
			$attrs[ 'indexable' ]
		);
	}

	public function test_equal_weights_keep_configuration_order(): void {
		// post_content and no_weight both resolve to weight 1.0; the stable sort must
		// preserve their original order (post_content declared first).
		$attrs = $this->extract( [
			'post_content' => [ 'indexable' => true, 'weight' => 1.0 ],
			'no_weight'    => [ 'indexable' => true ],
		] );

		$this->assertSame( [ 'post_content', 'no_weight' ], $attrs[ 'indexable' ] );
	}

	public function test_filterable_and_sortable_are_unaffected_by_weight_sort(): void {
		$attrs = $this->extract( [
			'post_title'        => [ 'indexable' => true, 'weight' => 2.0 ],
			'taxonomy_category' => [ 'indexable' => true, 'weight' => 1.5, 'filterable' => true ],
			'published'         => [ 'sortable' => true ],
		] );

		$this->assertSame( [ 'taxonomy_category' ], $attrs[ 'filterable' ] );
		$this->assertSame( [ 'published' ], $attrs[ 'sortable' ] );
	}
}
