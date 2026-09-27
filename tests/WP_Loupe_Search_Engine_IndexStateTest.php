<?php
namespace Soderlind\Plugin\LoupeSearch\Tests;

use PHPUnit\Framework\TestCase;
use Soderlind\Plugin\LoupeSearch\WP_Loupe_Search_Engine;

/**
 * index_needs_rebuild() must treat an empty-but-valid index as needing a rebuild
 * when the site has published content (issue: reindex-needed notice missing after
 * the multisite path change recreated a fresh empty index).
 */
class WP_Loupe_Search_Engine_IndexStateTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS[ 'wp_loupe_test_published_counts' ] = [];
	}

	private function engine( bool $ready, int $doc_count ): WP_Loupe_Search_Engine {
		$engine = $this->getMockBuilder( WP_Loupe_Search_Engine::class )
			->disableOriginalConstructor()
			->onlyMethods( [ 'is_index_ready' ] )
			->getMock();
		$engine->method( 'is_index_ready' )->willReturn(
			$ready ? [ 'ready' => true ] : [ 'ready' => false, 'reason' => 'index_missing' ]
		);

		$loupe = new class( $doc_count ) {
			private int $count;
			public function __construct( int $count ) {
				$this->count = $count;
			}
			public function countDocuments(): int {
				return $this->count;
			}
		};
		$ref = new \ReflectionProperty( WP_Loupe_Search_Engine::class, 'loupe' );
		$ref->setAccessible( true );
		$ref->setValue( $engine, [ 'post' => $loupe ] );

		return $engine;
	}

	public function test_missing_index_needs_rebuild(): void {
		$this->assertTrue( $this->engine( false, 0 )->index_needs_rebuild( 'post' ) );
	}

	public function test_populated_index_does_not_need_rebuild(): void {
		$this->assertFalse( $this->engine( true, 5 )->index_needs_rebuild( 'post' ) );
	}

	public function test_empty_index_with_published_posts_needs_rebuild(): void {
		$GLOBALS[ 'wp_loupe_test_published_counts' ][ 'post' ] = 3;
		$this->assertTrue( $this->engine( true, 0 )->index_needs_rebuild( 'post' ) );
	}

	public function test_empty_index_without_content_does_not_need_rebuild(): void {
		$GLOBALS[ 'wp_loupe_test_published_counts' ][ 'post' ] = 0;
		$this->assertFalse( $this->engine( true, 0 )->index_needs_rebuild( 'post' ) );
	}
}
