<?php
namespace Soderlind\Plugin\LoupeSearch;

use PHPUnit\Framework\TestCase;

/**
 * Index partitions: one Loupe index per partition (e.g. per language), routed by filters.
 */
class WP_Loupe_PartitionsTest extends TestCase {

	private string $dir;

	protected function setUp(): void {
		parent::setUp();
		$this->dir = sys_get_temp_dir() . '/loupe-partitions-test-' . uniqid();
		$GLOBALS[ 'wp_loupe_test_filters' ]    = [
			'loupe_search_db_path' => fn() => $this->dir,
		];
		$GLOBALS[ 'wp_loupe_test_transients' ] = [];
		$GLOBALS[ 'wp_loupe_test_post_types' ] = [];
		update_option( 'loupe_search_fields', [] );
		WP_Loupe_Factory::clear_instance_cache();
	}

	protected function tearDown(): void {
		WP_Loupe_Factory::clear_instance_cache();
		$GLOBALS[ 'wp_loupe_test_filters' ] = [];
		$this->rmdir( $this->dir );
		parent::tearDown();
	}

	private function rmdir( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::CHILD_FIRST ) as $file ) {
			$file->isDir() ? rmdir( $file->getPathname() ) : unlink( $file->getPathname() );
		}
		rmdir( $dir );
	}

	/**
	 * Partition post types into "en" and "nb"; posts are routed by $language[ID].
	 *
	 * @param array<int,string> $language Post ID => partition.
	 */
	private function partition_by( array &$language ): void {
		$GLOBALS[ 'wp_loupe_test_filters' ][ 'loupe_search_partitions' ]     = static fn() => [ 'en', 'nb' ];
		$GLOBALS[ 'wp_loupe_test_filters' ][ 'loupe_search_post_partition' ] = static function ( $partition, $post ) use ( &$language ) {
			return $language[ $post->ID ] ?? $partition;
		};
		// English and German build a large decompounding dictionary on first use; keep tests light.
		$GLOBALS[ 'wp_loupe_test_filters' ][ 'loupe_search_partition_language' ] = static fn( $language, $partition ) => 'nb' === $partition ? 'no' : 'fr';
	}

	private function make_indexer(): WP_Loupe_Indexer {
		$indexer = new WP_Loupe_Indexer( [], false );
		( new \ReflectionProperty( WP_Loupe_Indexer::class, 'post_types' ) )->setValue( $indexer, [ 'post' ] );
		return $indexer;
	}

	private function post( int $id ): \WP_Post {
		$GLOBALS[ 'wp_loupe_test_post_types' ][ $id ] = 'post';
		return new \WP_Post( [ 'ID' => $id, 'post_type' => 'post', 'post_title' => "Post {$id}" ] );
	}

	public function test_unpartitioned_post_types_keep_a_single_index_in_the_post_type_directory(): void {
		$this->assertSame( [ '' ], WP_Loupe_Partitions::all( 'post' ) );
		$this->assertFalse( WP_Loupe_Partitions::is_partitioned( 'post' ) );
		$this->assertSame( '', WP_Loupe_Partitions::for_post( $this->post( 1 ) ) );
		$this->assertSame( $this->dir . '/post', WP_Loupe_DB::get_instance()->get_db_path( 'post' ) );
	}

	public function test_named_partitions_are_sanitized_subdirectories(): void {
		$GLOBALS[ 'wp_loupe_test_filters' ][ 'loupe_search_partitions' ] = static fn() => [ 'EN', '../nb', 'en', '' ];

		$this->assertSame( [ 'en', 'nb' ], WP_Loupe_Partitions::all( 'post' ) );
		$this->assertSame( $this->dir . '/post/nb', WP_Loupe_DB::get_instance()->get_db_path( 'post', '../nb' ) );
	}

	public function test_posts_with_an_unknown_partition_go_to_the_first_one(): void {
		$language = [ 1 => 'nb', 2 => 'de' ];
		$this->partition_by( $language );

		$this->assertSame( 'nb', WP_Loupe_Partitions::for_post( $this->post( 1 ) ) );
		$this->assertSame( 'en', WP_Loupe_Partitions::for_post( $this->post( 2 ) ) );
	}

	public function test_query_partitions_ignore_unknown_names(): void {
		$GLOBALS[ 'wp_loupe_test_filters' ][ 'loupe_search_partitions' ]       = static fn() => [ 'en', 'nb' ];
		$GLOBALS[ 'wp_loupe_test_filters' ][ 'loupe_search_query_partitions' ] = static fn() => [ 'NB', 'de' ];

		$this->assertSame( [ 'nb' ], WP_Loupe_Partitions::for_query( 'post' ) );
	}

	public function test_a_post_lives_in_exactly_one_partition_and_moves_when_its_partition_changes(): void {
		$language = [ 1 => 'en', 2 => 'nb' ];
		$this->partition_by( $language );
		$indexer = $this->make_indexer();
		$engine  = new WP_Loupe_Search_Engine( [ 'post' ], WP_Loupe_DB::get_instance() );

		$indexer->add( 1, $this->post( 1 ), false );
		$indexer->add( 2, $this->post( 2 ), false );
		$this->assertSame( 1, $engine->count_documents( 'post', 'en' ) );
		$this->assertSame( 1, $engine->count_documents( 'post', 'nb' ) );
		$this->assertSame( 2, $engine->count_documents( 'post' ) );
		$this->assertFileExists( $this->dir . '/post/en/loupe.db' );
		$this->assertFileDoesNotExist( $this->dir . '/post/loupe.db' );

		$language[ 1 ] = 'nb';
		$indexer->add( 1, $this->post( 1 ), true );
		$this->assertSame( 0, $engine->count_documents( 'post', 'en' ) );
		$this->assertSame( 2, $engine->count_documents( 'post', 'nb' ) );

		$indexer->delete_post( 1 );
		$this->assertSame( 1, $engine->count_documents( 'post' ) );
	}

	public function test_clearing_an_unpartitioned_index_removes_former_partitions(): void {
		$language = [ 1 => 'nb' ];
		$this->partition_by( $language );
		$this->make_indexer()->add( 1, $this->post( 1 ), false );
		$this->assertFileExists( $this->dir . '/post/nb/loupe.db' );

		unset( $GLOBALS[ 'wp_loupe_test_filters' ][ 'loupe_search_partitions' ] );
		$GLOBALS[ 'wp_loupe_test_filters' ][ 'loupe_search_language' ] = static fn() => 'fr';
		$indexer = $this->make_indexer();
		( new \ReflectionMethod( WP_Loupe_Indexer::class, 'clear_post_type_index' ) )->invoke( $indexer, 'post' );

		$this->assertDirectoryDoesNotExist( $this->dir . '/post/nb' );
		$this->assertFileExists( $this->dir . '/post/loupe.db' );
	}

	public function test_each_partition_uses_its_own_language(): void {
		$GLOBALS[ 'wp_loupe_test_filters' ][ 'loupe_search_partitions' ]         = static fn() => [ 'en', 'nb' ];
		$GLOBALS[ 'wp_loupe_test_filters' ][ 'loupe_search_partition_language' ] = static fn( $language, $partition ) => 'nb' === $partition ? 'no' : 'en';

		$this->assertSame( 'no', WP_Loupe_Partitions::language( 'nb', 'post' ) );
		$this->assertSame( WP_Loupe_Factory::get_site_language(), WP_Loupe_Partitions::language( '', 'post' ) );
		$this->assertNotSame(
			WP_Loupe_Factory::create_partition_instance( 'post', 'en', WP_Loupe_DB::get_instance() ),
			WP_Loupe_Factory::create_partition_instance( 'post', 'nb', WP_Loupe_DB::get_instance() )
		);
	}

	/**
	 * Loupe double returning one hit, recording that it was searched.
	 */
	private function fake_loupe( int $id ) {
		return new class( $id ) {
			public int $searched = 0;
			public function __construct( private int $id ) {
			}
			public function search( $params ) {
				++$this->searched;
				$hits = [ [ 'id' => $this->id, '_rankingScore' => 0.5 ] ];
				return new class( $hits ) {
					public function __construct( private array $hits ) {
					}
					public function toArray(): array {
						return [ 'hits' => $this->hits, 'totalHits' => count( $this->hits ), 'processingTimeMs' => 1 ];
					}
				};
			}
		};
	}

	public function test_search_covers_only_the_query_partitions_and_caches_per_partition_set(): void {
		$GLOBALS[ 'wp_loupe_test_filters' ][ 'loupe_search_partitions' ] = static fn() => [ 'en', 'nb' ];
		$engine = new WP_Loupe_Search_Engine( [ 'post' ], WP_Loupe_DB::get_instance() );
		$loupes = [ 'post/en' => $this->fake_loupe( 1 ), 'post/nb' => $this->fake_loupe( 2 ) ];
		foreach ( [ 'saved_fields' => [ 'post' => [ 'post_title' => [ 'indexable' => true, 'weight' => 1.0 ] ] ], 'loupe' => $loupes ] as $prop => $value ) {
			( new \ReflectionProperty( WP_Loupe_Search_Engine::class, $prop ) )->setValue( $engine, $value );
		}

		$GLOBALS[ 'wp_loupe_test_filters' ][ 'loupe_search_query_partitions' ] = static fn() => [ 'nb' ];
		$this->assertSame( [ 2 ], array_column( $engine->search( 'post' ), 'id' ) );
		$this->assertSame( 0, $loupes[ 'post/en' ]->searched );

		$GLOBALS[ 'wp_loupe_test_filters' ][ 'loupe_search_query_partitions' ] = static fn() => [ 'en' ];
		$this->assertSame( [ 1 ], array_column( $engine->search( 'post' ), 'id' ), 'another partition set must not hit the cache' );

		unset( $GLOBALS[ 'wp_loupe_test_filters' ][ 'loupe_search_query_partitions' ] );
		$ids = array_column( $engine->search( 'post' ), 'id' );
		sort( $ids );
		$this->assertSame( [ 1, 2 ], $ids );
	}

	public function test_index_is_ready_only_when_every_partition_exists(): void {
		$GLOBALS[ 'wp_loupe_test_filters' ][ 'loupe_search_partitions' ] = static fn() => [ 'en', 'nb' ];
		WP_Loupe_Factory::create_partition_instance( 'post', 'en', WP_Loupe_DB::get_instance() )->deleteAllDocuments();
		$engine = new WP_Loupe_Search_Engine( [ 'post' ], WP_Loupe_DB::get_instance() );

		$this->assertTrue( $engine->is_index_ready( 'post', 'en' )[ 'ready' ] );
		$this->assertFalse( $engine->is_index_ready( 'post', 'nb' )[ 'ready' ] );
		$this->assertFalse( $engine->is_index_ready( 'post' )[ 'ready' ] );
	}
}
