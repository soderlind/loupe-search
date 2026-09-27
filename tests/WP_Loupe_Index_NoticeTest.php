<?php
namespace Soderlind\Plugin\LoupeSearch\Tests;

use PHPUnit\Framework\TestCase;
use Soderlind\Plugin\LoupeSearch\WP_Loupe_Index_Notice;
use Soderlind\Plugin\LoupeSearch\WP_Loupe_Search_Engine;

class WP_Loupe_Index_NoticeTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		global $wp_loupe_test_transients;
		$wp_loupe_test_transients = [];
	}

	/**
	 * @param array<string,bool> $ready_by_type
	 */
	private function make_engine( array $ready_by_type ): WP_Loupe_Search_Engine {
		$engine = $this->getMockBuilder( WP_Loupe_Search_Engine::class )
			->disableOriginalConstructor()
			->onlyMethods( [ 'is_index_ready' ] )
			->getMock();
		$engine->method( 'is_index_ready' )->willReturnCallback(
			static fn( string $pt ): array => ! empty( $ready_by_type[ $pt ] ) ? [ 'ready' => true ] : [ 'ready' => false, 'reason' => 'index_missing' ]
		);
		return $engine;
	}

	private function render( WP_Loupe_Index_Notice $notice ): string {
		ob_start();
		$notice->maybe_render();
		return (string) ob_get_clean();
	}

	public function test_notice_is_shown_when_an_index_is_not_ready(): void {
		$notice = new WP_Loupe_Index_Notice( $this->make_engine( [ 'post' => false, 'page' => true ] ), [ 'post', 'page' ] );
		$out    = $this->render( $notice );
		$this->assertStringContainsString( 'notice-warning', $out );
		$this->assertStringContainsString( 'Reindex now', $out );
	}

	public function test_no_notice_when_all_indexes_ready(): void {
		$notice = new WP_Loupe_Index_Notice( $this->make_engine( [ 'post' => true, 'page' => true ] ), [ 'post', 'page' ] );
		$this->assertSame( '', $this->render( $notice ) );
	}

	public function test_flush_clears_cached_status(): void {
		global $wp_loupe_test_transients;
		set_transient( 'loupe_search_index_status', [ 'post' ], 900 );
		WP_Loupe_Index_Notice::flush();
		$this->assertArrayNotHasKey( 'loupe_search_index_status', $wp_loupe_test_transients );
	}
}
