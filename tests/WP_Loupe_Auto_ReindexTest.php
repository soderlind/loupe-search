<?php
namespace {
	if ( ! function_exists( 'wp_next_scheduled' ) ) {
		function wp_next_scheduled( $hook ) {
			return $GLOBALS[ 'wp_loupe_test_scheduled' ][ $hook ] ?? false;
		}
	}
	if ( ! function_exists( 'wp_schedule_single_event' ) ) {
		function wp_schedule_single_event( $timestamp, $hook ) {
			$GLOBALS[ 'wp_loupe_test_scheduled' ][ $hook ] = $timestamp;
			return true;
		}
	}
	if ( ! function_exists( 'wp_schedule_event' ) ) {
		function wp_schedule_event( $timestamp, $recurrence, $hook ) {
			$GLOBALS[ 'wp_loupe_test_scheduled' ][ $hook ] = $timestamp;
			return true;
		}
	}
}

namespace Soderlind\Plugin\LoupeSearch\Tests {

	use PHPUnit\Framework\TestCase;
	use Soderlind\Plugin\LoupeSearch\WP_Loupe_Auto_Reindex;
	use Soderlind\Plugin\LoupeSearch\WP_Loupe_Indexer;
	use Soderlind\Plugin\LoupeSearch\WP_Loupe_Search_Engine;

	class WP_Loupe_Auto_ReindexTest extends TestCase {

		protected function setUp(): void {
			parent::setUp();
			$GLOBALS[ 'wp_loupe_test_scheduled' ] = [];
			delete_option( WP_Loupe_Auto_Reindex::VERSION_OPTION );
			delete_option( WP_Loupe_Auto_Reindex::STATE_OPTION );
		}

		/**
		 * @param array<string,string|null> $reasons Post type => not-ready reason (null = ready).
		 */
		private function engine( array $reasons ): WP_Loupe_Search_Engine {
			$engine = $this->createStub( WP_Loupe_Search_Engine::class );
			$engine->method( 'is_index_ready' )->willReturnCallback(
				static fn( string $pt ): array => null === ( $reasons[ $pt ] ?? null )
					? [ 'ready' => true ]
					: [ 'ready' => false, 'reason' => $reasons[ $pt ] ]
			);
			return $engine;
		}

		public function test_queues_only_stale_post_types_once_per_version(): void {
			$auto = new WP_Loupe_Auto_Reindex(
				$this->engine( [ 'post' => 'index_needs_reindex', 'page' => null, 'product' => 'index_missing' ] ),
				[ 'post', 'page', 'product' ],
				'1.5.3'
			);

			$auto->maybe_schedule();

			$this->assertSame( [ 'post_types' => [ 'post' ] ], get_option( WP_Loupe_Auto_Reindex::STATE_OPTION ) );
			$this->assertNotFalse( wp_next_scheduled( WP_Loupe_Auto_Reindex::HOOK ) );
			$this->assertSame( '1.5.3', get_option( WP_Loupe_Auto_Reindex::VERSION_OPTION ) );

			// Same version: no new check.
			delete_option( WP_Loupe_Auto_Reindex::STATE_OPTION );
			$auto->maybe_schedule();
			$this->assertFalse( get_option( WP_Loupe_Auto_Reindex::STATE_OPTION ) );
		}

		public function test_nothing_is_queued_when_no_index_is_stale(): void {
			$auto = new WP_Loupe_Auto_Reindex( $this->engine( [ 'post' => 'index_missing' ] ), [ 'post' ], '1.5.3' );
			$auto->maybe_schedule();

			$this->assertFalse( get_option( WP_Loupe_Auto_Reindex::STATE_OPTION ) );
			$this->assertFalse( wp_next_scheduled( WP_Loupe_Auto_Reindex::HOOK ) );
		}

		public function test_run_rebuilds_queued_post_types_and_clears_state(): void {
			update_option( WP_Loupe_Auto_Reindex::STATE_OPTION, [ 'post_types' => [ 'post' ] ] );

			$indexer = $this->getMockBuilder( WP_Loupe_Indexer::class )
				->disableOriginalConstructor()
				->onlyMethods( [ 'reindex_batch_init', 'reindex_batch_step' ] )
				->getMock();
			$indexer->expects( $this->once() )->method( 'reindex_batch_init' )->with( [ 'post' ] )
				->willReturn( [ 'v' => 1, 'post_types' => [ 'post' ] ] );
			$indexer->expects( $this->exactly( 2 ) )->method( 'reindex_batch_step' )
				->willReturnOnConsecutiveCalls( [ 'v' => 1, 'post_types' => [ 'post' ] ], [ 'v' => 1, 'done' => true ] );

			$auto = new WP_Loupe_Auto_Reindex( $this->engine( [ 'post' => 'index_needs_reindex' ] ), [ 'post' ], '1.5.3', static fn() => $indexer );
			$auto->run();

			$this->assertFalse( get_option( WP_Loupe_Auto_Reindex::STATE_OPTION ) );
			$this->assertFalse( wp_next_scheduled( WP_Loupe_Auto_Reindex::HOOK ) );
		}

		public function test_run_skips_post_types_already_rebuilt(): void {
			update_option( WP_Loupe_Auto_Reindex::STATE_OPTION, [ 'post_types' => [ 'post' ] ] );

			$indexer = $this->getMockBuilder( WP_Loupe_Indexer::class )
				->disableOriginalConstructor()
				->onlyMethods( [ 'reindex_batch_init', 'reindex_batch_step' ] )
				->getMock();
			$indexer->expects( $this->never() )->method( 'reindex_batch_init' );

			$auto = new WP_Loupe_Auto_Reindex( $this->engine( [ 'post' => null ] ), [ 'post' ], '1.5.3', static fn() => $indexer );
			$auto->run();

			$this->assertFalse( get_option( WP_Loupe_Auto_Reindex::STATE_OPTION ) );
		}

		public function test_run_gives_up_when_the_rebuild_throws(): void {
			update_option( WP_Loupe_Auto_Reindex::STATE_OPTION, [ 'post_types' => [ 'post' ] ] );

			$indexer = $this->createStub( WP_Loupe_Indexer::class );
			$indexer->method( 'reindex_batch_init' )->willThrowException( new \RuntimeException( 'disk full' ) );

			$auto = new WP_Loupe_Auto_Reindex( $this->engine( [ 'post' => 'index_needs_reindex' ] ), [ 'post' ], '1.5.3', static fn() => $indexer );
			$auto->run();

			$this->assertFalse( get_option( WP_Loupe_Auto_Reindex::STATE_OPTION ) );
			$this->assertFalse( wp_next_scheduled( WP_Loupe_Auto_Reindex::HOOK ) );
		}
	}
}
