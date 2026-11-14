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
	use Soderlind\Plugin\LoupeSearch\Vendor\Loupe\Loupe\SearchParameters;
	use Soderlind\Plugin\LoupeSearch\WP_Loupe_Auto_Reindex;
	use Soderlind\Plugin\LoupeSearch\WP_Loupe_DB;
	use Soderlind\Plugin\LoupeSearch\WP_Loupe_Factory;
	use Soderlind\Plugin\LoupeSearch\WP_Loupe_Indexer;
	use Soderlind\Plugin\LoupeSearch\WP_Loupe_Search_Engine;

	class WP_Loupe_Auto_ReindexTest extends TestCase {

		protected function setUp(): void {
			parent::setUp();
			$GLOBALS[ 'wp_loupe_test_scheduled' ] = [];
			delete_option( WP_Loupe_Auto_Reindex::VERSION_OPTION );
			delete_option( WP_Loupe_Auto_Reindex::STATE_OPTION );
			// English/German build a large per-index tokenizer cache; keep the tests light.
			$GLOBALS[ 'wp_loupe_test_filters' ][ 'loupe_search_language' ] = static fn() => 'fr';
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

		private const PT = 'auto_reindex_test';

		protected function tearDown(): void {
			unset( $GLOBALS[ 'wp_loupe_test_filters' ][ 'loupe_search_language' ] );
			WP_Loupe_Factory::clear_instance_cache();
			$dirs = [];
			foreach ( [ $this->livePath(), $this->livePath() . '2' ] as $live ) {
				array_push( $dirs, $live, $live . '.rebuild', $live . '.old' );
			}
			foreach ( $dirs as $dir ) {
				if ( is_dir( $dir ) ) {
					$items = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::CHILD_FIRST );
					foreach ( $items as $item ) {
						$item->isDir() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() );
					}
					rmdir( $dir );
				}
			}
			parent::tearDown();
		}

		private function livePath(): string {
			return WP_Loupe_DB::get_instance()->get_base_path() . '/' . self::PT;
		}

		private function open( string $path, string $lang = '' ) {
			return WP_Loupe_Factory::create_loupe_instance_at( self::PT, '' === $lang ? WP_Loupe_Factory::get_site_language() : $lang, $path );
		}

		/** A live index built with another language, so it reports needsReindex(). */
		private function seedStaleLive(): void {
			$this->open( $this->livePath(), 'es' )->addDocuments( [ [ 'id' => 1, 'post_title' => 'Old title' ] ] );
		}

		private function ids( $loupe ): array {
			$ids = array_map( 'intval', array_column( $loupe->search( SearchParameters::create()->withQuery( '' )->withHitsPerPage( 100 ) )->toArray()[ 'hits' ], 'id' ) );
			sort( $ids );
			return $ids;
		}

		/**
		 * @param callable $on_batch fn( $shadow, int $after ): int, return last ID or 0.
		 */
		private function indexer( callable $on_batch ): WP_Loupe_Indexer {
			$indexer = $this->createStub( WP_Loupe_Indexer::class );
			$indexer->method( 'index_batch_into' )->willReturnCallback(
				static fn( $shadow, string $pt, int $after ): int => $on_batch( $shadow, $after )
			);
			return $indexer;
		}

		private function auto( WP_Loupe_Indexer $indexer, ?string $reason = 'index_needs_reindex' ): WP_Loupe_Auto_Reindex {
			return new WP_Loupe_Auto_Reindex( $this->engine( [ self::PT => $reason ] ), [ self::PT ], '1.5.3', static fn() => $indexer );
		}

		public function test_run_builds_a_shadow_and_swaps_it_in_when_complete(): void {
			$this->seedStaleLive();
			update_option( WP_Loupe_Auto_Reindex::STATE_OPTION, [ 'post_types' => [ self::PT ] ] );

			$live_during_build = null;
			$indexer           = $this->indexer( function ( $shadow, int $after ) use ( &$live_during_build ): int {
				if ( 0 === $after ) {
					$shadow->addDocuments( [ [ 'id' => 2, 'post_title' => 'New title' ] ] );
					$live_during_build = $this->ids( $this->open( $this->livePath(), 'es' ) );
					return 2;
				}
				return 0;
			} );

			$this->auto( $indexer )->run();

			$this->assertSame( [ 1 ], $live_during_build, 'Live index stays intact while the shadow is built.' );
			$this->assertSame( [ 2 ], $this->ids( $this->open( $this->livePath() ) ) );
			$this->assertDirectoryDoesNotExist( $this->livePath() . '.rebuild' );
			$this->assertDirectoryDoesNotExist( $this->livePath() . '.old' );
			$this->assertFalse( get_option( WP_Loupe_Auto_Reindex::STATE_OPTION ) );
			$this->assertFalse( wp_next_scheduled( WP_Loupe_Auto_Reindex::HOOK ) );
		}

		public function test_run_completes_one_post_type_per_request_and_reschedules(): void {
			$second = self::PT . '2';
			update_option( WP_Loupe_Auto_Reindex::STATE_OPTION, [ 'post_types' => [ self::PT, $second ] ] );

			$built   = [];
			$indexer = $this->createStub( WP_Loupe_Indexer::class );
			$indexer->method( 'index_batch_into' )->willReturnCallback(
				static function ( $shadow, string $pt ) use ( &$built ): int {
					$built[] = $pt;
					return 0;
				}
			);
			$engine = $this->engine( [ self::PT => 'index_needs_reindex', $second => 'index_needs_reindex' ] );
			$auto   = new WP_Loupe_Auto_Reindex( $engine, [ self::PT, $second ], '1.5.3', static fn() => $indexer );

			$auto->run();
			$this->assertSame( [ self::PT ], $built );
			$this->assertSame( [ $second ], get_option( WP_Loupe_Auto_Reindex::STATE_OPTION )[ 'queue' ] );
			$this->assertNotFalse( wp_next_scheduled( WP_Loupe_Auto_Reindex::HOOK ) );

			$GLOBALS[ 'wp_loupe_test_scheduled' ] = [];
			$auto->run();
			$this->assertSame( [ self::PT, $second ], $built );
			$this->assertFalse( get_option( WP_Loupe_Auto_Reindex::STATE_OPTION ) );
			$this->assertFalse( wp_next_scheduled( WP_Loupe_Auto_Reindex::HOOK ) );
		}

		public function test_run_mirrors_edits_made_during_the_build_into_the_shadow(): void {
			$this->seedStaleLive();
			update_option( WP_Loupe_Auto_Reindex::STATE_OPTION, [ 'post_types' => [ self::PT ] ] );

			$auto    = null;
			$indexer = $this->indexer( function ( $shadow, int $after ) use ( &$auto ): int {
				if ( 0 === $after ) {
					$shadow->addDocuments( [ [ 'id' => 2, 'post_title' => 'Two' ], [ 'id' => 3, 'post_title' => 'Three' ] ] );
					// A post is saved and another deleted while the build is in progress.
					$auto->mirror_add( self::PT, [ 'id' => 4, 'post_title' => 'Four' ] );
					$auto->mirror_delete( self::PT, [ 3 ] );
					return 3;
				}
				return 0;
			} );
			$auto = $this->auto( $indexer );

			$auto->run();

			$this->assertSame( [ 2, 4 ], $this->ids( $this->open( $this->livePath() ) ) );
		}

		public function test_run_skips_post_types_already_rebuilt(): void {
			update_option( WP_Loupe_Auto_Reindex::STATE_OPTION, [ 'post_types' => [ self::PT ] ] );

			$indexer = $this->createMock( WP_Loupe_Indexer::class );
			$indexer->expects( $this->never() )->method( 'index_batch_into' );

			$this->auto( $indexer, null )->run();

			$this->assertFalse( get_option( WP_Loupe_Auto_Reindex::STATE_OPTION ) );
		}

		public function test_failed_rebuild_discards_the_shadow_and_keeps_the_live_index(): void {
			$this->seedStaleLive();
			update_option( WP_Loupe_Auto_Reindex::STATE_OPTION, [ 'post_types' => [ self::PT ] ] );

			$indexer = $this->indexer( static function ( $shadow, int $after ): int {
				if ( 0 === $after ) {
					$shadow->addDocuments( [ [ 'id' => 2, 'post_title' => 'Partial' ] ] );
					return 2;
				}
				throw new \RuntimeException( 'disk full' );
			} );

			$this->auto( $indexer )->run();

			$this->assertSame( [ 1 ], $this->ids( $this->open( $this->livePath(), 'es' ) ) );
			$this->assertTrue( $this->open( $this->livePath() )->needsReindex(), 'Still stale, so the admin notice keeps asking for a reindex.' );
			$this->assertDirectoryDoesNotExist( $this->livePath() . '.rebuild' );
			$this->assertFalse( get_option( WP_Loupe_Auto_Reindex::STATE_OPTION ) );
			$this->assertFalse( wp_next_scheduled( WP_Loupe_Auto_Reindex::HOOK ) );
		}
	}
}
