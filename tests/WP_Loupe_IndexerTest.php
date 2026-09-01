<?php
namespace Soderlind\Plugin\WPLoupe; // Match plugin namespace for class references.

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the indexer's indexability rules and document building.
 *
 * The indexer is always constructed with no post types so that no Loupe/SQLite
 * instances are created on disk; the post types the logic needs are injected after.
 */
class WP_Loupe_IndexerTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS[ 'wp_loupe_test_revisions' ]  = [];
		$GLOBALS[ 'wp_loupe_test_autosaves' ]  = [];
		$GLOBALS[ 'wp_loupe_test_terms' ]      = [];
		$GLOBALS[ 'wp_loupe_test_taxonomies' ] = [];
		$GLOBALS[ 'wp_loupe_test_post_meta' ]  = [];
		$GLOBALS[ 'wp_loupe_test_filters' ]    = [];
		$GLOBALS[ 'wp_loupe_test_post_types' ] = [];
		update_option( 'loupe_search_fields', [] );
	}

	protected function tearDown(): void {
		$GLOBALS[ 'wp_loupe_test_filters' ]    = [];
		$GLOBALS[ 'wp_loupe_test_taxonomies' ] = [];
		parent::tearDown();
	}

	private function make_indexer( array $post_types = [ 'post' ] ): WP_Loupe_Indexer {
		$indexer = new WP_Loupe_Indexer( [], false );
		( new \ReflectionProperty( WP_Loupe_Indexer::class, 'post_types' ) )->setValue( $indexer, $post_types );
		return $indexer;
	}

	private function invoke( WP_Loupe_Indexer $indexer, string $method, ...$args ) {
		return ( new \ReflectionMethod( WP_Loupe_Indexer::class, $method ) )->invokeArgs( $indexer, $args );
	}

	// ---------------------------------------------------------------- is_indexable

	public function test_is_indexable_accepts_a_published_post_of_an_indexed_type() {
		$indexer = $this->make_indexer();
		$post    = new \WP_Post( [ 'ID' => 1 ] );

		$this->assertTrue( $this->invoke( $indexer, 'is_indexable', 1, $post ) );
	}

	public function test_is_indexable_rejects_revisions_and_autosaves() {
		$indexer = $this->make_indexer();

		$GLOBALS[ 'wp_loupe_test_revisions' ][ 7 ] = true;
		$this->assertFalse( $this->invoke( $indexer, 'is_indexable', 7, new \WP_Post( [ 'ID' => 7 ] ) ), 'revisions must not be indexed' );

		$GLOBALS[ 'wp_loupe_test_autosaves' ][ 8 ] = true;
		$this->assertFalse( $this->invoke( $indexer, 'is_indexable', 8, new \WP_Post( [ 'ID' => 8 ] ) ), 'autosaves must not be indexed' );
	}

	public function test_is_indexable_rejects_a_post_type_that_is_not_configured() {
		$indexer = $this->make_indexer( [ 'post' ] );
		$post    = new \WP_Post( [ 'ID' => 2, 'post_type' => 'page' ] );

		$this->assertFalse( $this->invoke( $indexer, 'is_indexable', 2, $post ) );
	}

	public function test_is_indexable_rejects_every_status_except_publish() {
		$indexer = $this->make_indexer();

		foreach ( [ 'draft', 'pending', 'private', 'future', 'trash', 'auto-draft', 'inherit' ] as $status ) {
			$post = new \WP_Post( [ 'ID' => 3, 'post_status' => $status ] );
			$this->assertFalse( $this->invoke( $indexer, 'is_indexable', 3, $post ), "{$status} must not be indexed" );
		}
	}

	public function test_is_indexable_rejects_password_protected_posts_by_default() {
		$indexer = $this->make_indexer();
		$post    = new \WP_Post( [ 'ID' => 4, 'post_password' => 'secret' ] );

		$this->assertFalse( $this->invoke( $indexer, 'is_indexable', 4, $post ) );
	}

	/**
	 * @see docs/filters.md — loupe_search_index_protected opts password-protected posts back in.
	 */
	public function test_is_indexable_allows_protected_posts_when_the_filter_opts_in() {
		$GLOBALS[ 'wp_loupe_test_filters' ][ 'loupe_search_index_protected' ] = function () {
			return true;
		};

		$indexer = $this->make_indexer();
		$post    = new \WP_Post( [ 'ID' => 5, 'post_password' => 'secret' ] );

		$this->assertTrue( $this->invoke( $indexer, 'is_indexable', 5, $post ) );
	}

	// ------------------------------------------------------------------------- add

	/**
	 * Minimal Loupe double recording add/delete calls.
	 */
	private function fake_loupe() {
		return new class {
			public array $added   = [];
			public array $deleted = [];
			public function addDocument( $doc ) {
				$this->added[] = $doc;
			}
			public function deleteDocument( $id ) {
				$this->deleted[] = $id;
			}
		};
	}

	private function inject_loupe( WP_Loupe_Indexer $indexer, string $post_type, $loupe ): void {
		( new \ReflectionProperty( WP_Loupe_Indexer::class, 'loupe' ) )->setValue( $indexer, [ $post_type => $loupe ] );
	}

	public function test_add_indexes_a_publishable_post() {
		$indexer = $this->make_indexer( [ 'post' ] );
		$loupe   = $this->fake_loupe();
		$this->inject_loupe( $indexer, 'post', $loupe );

		$indexer->add( 23, new \WP_Post( [ 'ID' => 23, 'post_type' => 'post' ] ), true );

		$this->assertCount( 1, $loupe->added );
		$this->assertSame( 23, $loupe->added[ 0 ][ 'id' ] );
		$this->assertSame( [], $loupe->deleted, 'an indexable post is never purged' );
	}

	public function test_add_purges_a_stale_document_when_a_post_becomes_non_indexable() {
		$indexer = $this->make_indexer( [ 'post' ] );
		$loupe   = $this->fake_loupe();
		$this->inject_loupe( $indexer, 'post', $loupe );

		$GLOBALS[ 'wp_loupe_test_post_types' ][ 21 ] = 'post';
		// Published post that has since gained a password — no longer indexable.
		$post = new \WP_Post( [ 'ID' => 21, 'post_type' => 'post', 'post_password' => 'secret' ] );

		$indexer->add( 21, $post, true );

		$this->assertSame( [], $loupe->added, 'a protected post is not (re)indexed' );
		$this->assertSame( [ 21 ], $loupe->deleted, 'a now-protected post must be purged from the index' );
	}

	public function test_add_leaves_the_index_untouched_for_an_unindexed_post_type() {
		$indexer = $this->make_indexer( [ 'post' ] );
		$loupe   = $this->fake_loupe();
		$this->inject_loupe( $indexer, 'post', $loupe );

		$post = new \WP_Post( [ 'ID' => 22, 'post_type' => 'page', 'post_password' => 'secret' ] );

		$indexer->add( 22, $post, true );

		$this->assertSame( [], $loupe->added );
		$this->assertSame( [], $loupe->deleted, 'posts of an unindexed type are never touched' );
	}

	// ------------------------------------------------------------ prepare_document

	public function test_prepare_document_always_carries_id_and_post_type() {
		$indexer = $this->make_indexer();
		$document = $indexer->prepare_document( new \WP_Post( [ 'ID' => 10 ] ) );

		$this->assertSame( 10, $document[ 'id' ] );
		$this->assertSame( 'post', $document[ 'post_type' ] );
	}

	public function test_prepare_document_collects_core_meta_and_taxonomy_fields() {
		update_option( 'loupe_search_fields', [
			'post' => [
				'post_title'     => [ 'indexable' => true ],
				'rating'         => [ 'indexable' => true ],
				'taxonomy_genre' => [ 'indexable' => true ],
			],
		] );
		$GLOBALS[ 'wp_loupe_test_post_meta' ][ 11 ][ 'rating' ] = '5';
		$GLOBALS[ 'wp_loupe_test_terms' ][ 11 ][ 'genre' ]      = [ 'Drama', 'Sci-Fi' ];
		$GLOBALS[ 'wp_loupe_test_taxonomies' ]                  = [ 'genre' ];

		$indexer  = $this->make_indexer();
		$document = $indexer->prepare_document( new \WP_Post( [
			'ID'         => 11,
			'post_title' => '  Dune  ',
			'post_date'  => '2026-01-02 03:04:05',
		] ) );

		$this->assertSame( '2026-01-02 03:04:05', $document[ 'post_date' ], 'post_date is indexable by default' );
		$this->assertSame( 'Dune', $document[ 'post_title' ], 'post properties are read from the post and trimmed' );
		$this->assertSame( '5', $document[ 'rating' ], 'unknown field names fall back to post meta' );
		$this->assertSame( [ 'Drama', 'Sci-Fi' ], $document[ 'taxonomy_genre' ], 'taxonomy_ fields resolve to term names' );
	}

	public function test_prepare_document_skips_fields_configured_as_not_indexable() {
		update_option( 'loupe_search_fields', [ 'post' => [ 'post_date' => [ 'indexable' => false ] ] ] );

		$indexer  = $this->make_indexer();
		$document = $indexer->prepare_document( new \WP_Post( [ 'ID' => 12 ] ) );

		$this->assertArrayNotHasKey( 'post_date', $document );
	}

	/**
	 * A sortable field must exist on every document, otherwise Loupe cannot sort by it.
	 */
	public function test_prepare_document_adds_an_empty_placeholder_for_a_valueless_sortable_field() {
		update_option( 'loupe_search_fields', [
			'post' => [ 'shelf_order' => [ 'indexable' => false, 'sortable' => true ] ],
		] );

		$indexer  = $this->make_indexer();
		$document = $indexer->prepare_document( new \WP_Post( [ 'ID' => 13 ] ) );

		$this->assertArrayHasKey( 'shelf_order', $document );
		$this->assertSame( '', $document[ 'shelf_order' ] );
	}

	public function test_prepare_document_uses_the_meta_value_of_a_sortable_field_when_present() {
		update_option( 'loupe_search_fields', [
			'post' => [ 'shelf_order' => [ 'indexable' => false, 'sortable' => true ] ],
		] );
		$GLOBALS[ 'wp_loupe_test_post_meta' ][ 14 ][ 'shelf_order' ] = '  A7  ';

		$indexer  = $this->make_indexer();
		$document = $indexer->prepare_document( new \WP_Post( [ 'ID' => 14 ] ) );

		$this->assertSame( 'A7', $document[ 'shelf_order' ] );
	}

	// ------------------------------------------------------- sanitize_field_value

	public function test_sanitize_field_value_maps_empty_values_to_null() {
		$indexer = $this->make_indexer();

		foreach ( [ null, '', [], false, '   ' ] as $empty ) {
			$this->assertNull( $this->invoke( $indexer, 'sanitize_field_value', $empty ) );
		}
	}

	public function test_sanitize_field_value_trims_strings_and_passes_numbers_through() {
		$indexer = $this->make_indexer();

		$this->assertSame( 'hello', $this->invoke( $indexer, 'sanitize_field_value', '  hello  ' ) );
		$this->assertSame( 42, $this->invoke( $indexer, 'sanitize_field_value', 42 ) );
		$this->assertSame( '3.14', $this->invoke( $indexer, 'sanitize_field_value', '3.14' ) );
	}

	public function test_sanitize_field_value_reduces_arrays_to_non_empty_strings() {
		$indexer = $this->make_indexer();

		$this->assertSame(
			[ 'a', 'b' ],
			$this->invoke( $indexer, 'sanitize_field_value', [ ' a ', 'b', '', 3, null ] ),
			'non-strings and empty strings are dropped'
		);
		$this->assertNull( $this->invoke( $indexer, 'sanitize_field_value', [ 3, null ] ) );
	}

	public function test_sanitize_field_value_normalises_geo_points_to_lat_lng_floats() {
		$indexer = $this->make_indexer();

		$this->assertSame(
			[ 'lat' => 1.5, 'lng' => 2.5 ],
			$this->invoke( $indexer, 'sanitize_field_value', [ 'lat' => '1.5', 'lon' => '2.5' ] ),
			'lon is accepted as input and normalised to lng'
		);
		$this->assertSame(
			[ 'lat' => 1.0, 'lng' => 2.0 ],
			$this->invoke( $indexer, 'sanitize_field_value', [ 'lat' => 1, 'lng' => 2 ] )
		);
		$this->assertNull(
			$this->invoke( $indexer, 'sanitize_field_value', [ 'lat' => 'north', 'lng' => 2 ] ),
			'a non-numeric coordinate is not a usable geo point'
		);
	}

	public function test_sanitize_field_value_converts_stringable_objects() {
		$indexer    = $this->make_indexer();
		$stringable = new class {
			public function __toString(): string {
				return 'Loupe';
			}
		};

		$this->assertSame( 'Loupe', $this->invoke( $indexer, 'sanitize_field_value', $stringable ) );
	}
}
