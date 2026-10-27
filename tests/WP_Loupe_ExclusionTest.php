<?php
namespace {
	if ( ! function_exists( 'wp_verify_nonce' ) ) {
		function wp_verify_nonce( $nonce, $action = -1 ) {
			return 'valid-' . $action === $nonce ? 1 : false;
		}
	}
	if ( ! function_exists( 'add_query_arg' ) ) {
		function add_query_arg( $args, $url ) {
			return $url . ( str_contains( $url, '?' ) ? '&' : '?' ) . http_build_query( $args );
		}
	}
	if ( ! function_exists( 'remove_query_arg' ) ) {
		function remove_query_arg( $keys, $url ) {
			return $url;
		}
	}
}

namespace Soderlind\Plugin\LoupeSearch {

	use PHPUnit\Framework\TestCase;

	/**
	 * Excluded posts: kept out of the index, and re-evaluated whenever the flag changes.
	 *
	 * @see includes/class-wp-loupe-exclusion.php
	 */
	class WP_Loupe_ExclusionTest extends TestCase {

		private $original_wpdb;

		protected function setUp(): void {
			parent::setUp();
			$GLOBALS[ 'wp_loupe_test_post_meta' ] = [];
			$GLOBALS[ 'wp_loupe_test_hooks' ]     = [];
			$GLOBALS[ 'wp_loupe_test_is_admin' ]  = false;
			$this->original_wpdb                  = $GLOBALS[ 'wpdb' ] ?? null;
			$_POST                                = [];
		}

		protected function tearDown(): void {
			$GLOBALS[ 'wpdb' ]                    = $this->original_wpdb;
			$GLOBALS[ 'wp_loupe_test_post_meta' ] = [];
			$GLOBALS[ 'wp_loupe_test_is_admin' ]  = false;
			$_POST                                = [];
			parent::tearDown();
		}

		private function recording_indexer() {
			return new class( [], false ) extends WP_Loupe_Indexer {
				public array $reindexed = [];
				public function reindex_object_ids( array $object_ids ): void {
					$this->reindexed = array_merge( $this->reindexed, $object_ids );
				}
			};
		}

		private function exclusion( $indexer = null ): WP_Loupe_Exclusion {
			return new WP_Loupe_Exclusion( $indexer ?? $this->recording_indexer(), [ 'post', 'page' ] );
		}

		private function exclude( int $post_id ): void {
			$GLOBALS[ 'wp_loupe_test_post_meta' ][ $post_id ][ WP_Loupe_Exclusion::META_KEY ] = '1';
		}

		public function test_excluded_posts_are_vetoed_and_others_left_alone() {
			$this->exclude( 5 );
			$exclusion = $this->exclusion();

			$this->assertFalse( $exclusion->filter_is_indexable( true, new \WP_Post( [ 'ID' => 5 ] ) ) );
			$this->assertTrue( $exclusion->filter_is_indexable( true, new \WP_Post( [ 'ID' => 6 ] ) ) );
		}

		public function test_an_earlier_rejection_is_never_overturned() {
			$this->assertFalse( $this->exclusion()->filter_is_indexable( false, new \WP_Post( [ 'ID' => 6 ] ) ) );
		}

		public function test_set_excluded_stores_and_clears_the_flag() {
			WP_Loupe_Exclusion::set_excluded( 7, true );
			$this->assertTrue( WP_Loupe_Exclusion::is_excluded( 7 ) );

			WP_Loupe_Exclusion::set_excluded( 7, false );
			$this->assertFalse( WP_Loupe_Exclusion::is_excluded( 7 ) );
		}

		public function test_flag_changes_reevaluate_the_post() {
			$indexer   = $this->recording_indexer();
			$exclusion = $this->exclusion( $indexer );

			$exclusion->on_meta_change( 1, 8, WP_Loupe_Exclusion::META_KEY );
			$exclusion->on_meta_change( 2, 9, '_edit_lock' );

			$this->assertSame( [ 8 ], $indexer->reindexed );
		}

		public function test_meta_removed_during_deletion_does_not_restore_the_post() {
			$indexer   = $this->recording_indexer();
			$exclusion = $this->exclusion( $indexer );

			$exclusion->mark_deleting( 8 );
			$exclusion->on_meta_change( [ 1 ], 8, WP_Loupe_Exclusion::META_KEY );

			$this->assertSame( [], $indexer->reindexed );
		}

		public function test_bulk_exclude_and_include_toggle_the_flag_and_report_counts() {
			$exclusion = $this->exclusion();

			$url = $exclusion->handle_bulk_action( 'edit.php', 'loupe_search_exclude', [ 1, 2 ] );
			$this->assertTrue( WP_Loupe_Exclusion::is_excluded( 1 ) );
			$this->assertTrue( WP_Loupe_Exclusion::is_excluded( 2 ) );
			$this->assertStringContainsString( 'loupe_search_bulk=excluded', $url );
			$this->assertStringContainsString( 'loupe_search_changed=2', $url );

			$url = $exclusion->handle_bulk_action( 'edit.php', 'loupe_search_include', [ 2 ] );
			$this->assertTrue( WP_Loupe_Exclusion::is_excluded( 1 ) );
			$this->assertFalse( WP_Loupe_Exclusion::is_excluded( 2 ) );
			$this->assertStringContainsString( 'loupe_search_bulk=included', $url );
		}

		public function test_unrelated_bulk_actions_pass_through() {
			$this->assertSame( 'edit.php', $this->exclusion()->handle_bulk_action( 'edit.php', 'trash', [ 1 ] ) );
			$this->assertFalse( WP_Loupe_Exclusion::is_excluded( 1 ) );
		}

		public function test_form_save_needs_a_valid_nonce() {
			$exclusion = $this->exclusion();
			$post      = new \WP_Post( [ 'ID' => 3, 'post_type' => 'post' ] );

			$_POST = [ 'loupe_search_exclude' => '1', 'loupe_search_exclude_nonce' => 'forged' ];
			$exclusion->save_from_form( 3, $post );
			$this->assertFalse( WP_Loupe_Exclusion::is_excluded( 3 ) );

			$_POST[ 'loupe_search_exclude_nonce' ] = 'valid-loupe_search_exclude';
			$exclusion->save_from_form( 3, $post );
			$this->assertTrue( WP_Loupe_Exclusion::is_excluded( 3 ) );

			unset( $_POST[ 'loupe_search_exclude' ] );
			$exclusion->save_from_form( 3, $post );
			$this->assertFalse( WP_Loupe_Exclusion::is_excluded( 3 ), 'an unticked box clears the flag' );
		}

		public function test_form_save_ignores_unindexed_post_types() {
			$_POST = [ 'loupe_search_exclude' => '1', 'loupe_search_exclude_nonce' => 'valid-loupe_search_exclude' ];
			$this->exclusion()->save_from_form( 4, new \WP_Post( [ 'ID' => 4, 'post_type' => 'attachment' ] ) );

			$this->assertFalse( WP_Loupe_Exclusion::is_excluded( 4 ) );
		}

		public function test_post_list_hooks_are_registered_per_indexed_post_type_in_admin_only() {
			$this->exclusion()->register();
			$this->assertArrayHasKey( 'loupe_search_is_indexable', $GLOBALS[ 'wp_loupe_test_hooks' ] );
			$this->assertArrayNotHasKey( 'manage_post_posts_columns', $GLOBALS[ 'wp_loupe_test_hooks' ] );

			$GLOBALS[ 'wp_loupe_test_hooks' ]    = [];
			$GLOBALS[ 'wp_loupe_test_is_admin' ] = true;
			$this->exclusion()->register();
			foreach ( [ 'post', 'page' ] as $type ) {
				$this->assertArrayHasKey( "manage_{$type}_posts_columns", $GLOBALS[ 'wp_loupe_test_hooks' ] );
				$this->assertArrayHasKey( "bulk_actions-edit-{$type}", $GLOBALS[ 'wp_loupe_test_hooks' ] );
				$this->assertArrayHasKey( "handle_bulk_actions-edit-{$type}", $GLOBALS[ 'wp_loupe_test_hooks' ] );
			}
		}

		public function test_bulk_actions_and_column_are_added() {
			$exclusion = $this->exclusion();

			$this->assertArrayHasKey( 'loupe_search_exclude', $exclusion->add_bulk_actions( [] ) );
			$this->assertArrayHasKey( 'loupe_search_include', $exclusion->add_bulk_actions( [] ) );
			$this->assertArrayHasKey( WP_Loupe_Exclusion::COLUMN, $exclusion->add_column( [ 'title' => 'Title' ] ) );
		}

		public function test_count_excluded_only_counts_published_posts_of_the_type() {
			$GLOBALS[ 'wpdb' ] = new class {
				public $posts    = 'wp_posts';
				public $postmeta = 'wp_postmeta';
				public array $args = [];
				public string $query = '';
				public function prepare( $query, ...$args ) {
					$this->query = $query;
					$this->args  = $args;
					return $query;
				}
				public function get_var( $query ) {
					return '3';
				}
			};

			$this->assertSame( 3, WP_Loupe_Exclusion::count_excluded( 'page' ) );
			$this->assertSame( [ 'page', WP_Loupe_Exclusion::META_KEY ], $GLOBALS[ 'wpdb' ]->args );
			$this->assertStringContainsString( "post_status = 'publish'", $GLOBALS[ 'wpdb' ]->query );
		}
	}
}
