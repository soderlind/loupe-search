<?php
namespace {
	// Minimal bbPress API surface used by WP_Loupe_BBPress, driven by $GLOBALS['wp_loupe_test_bbp'].
	if ( ! function_exists( 'bbp_get_forum_post_type' ) ) {
		function bbp_get_forum_post_type() {
			return 'forum';
		}
		function bbp_get_topic_post_type() {
			return 'topic';
		}
		function bbp_get_reply_post_type() {
			return 'reply';
		}
		function bbp_is_forum_public( $forum_id = 0, $check_ancestors = true ) {
			return ! empty( $GLOBALS[ 'wp_loupe_test_bbp' ][ 'public' ][ (int) $forum_id ] );
		}
		function bbp_get_topic_forum_id( $topic_id = 0 ) {
			return $GLOBALS[ 'wp_loupe_test_bbp' ][ 'topic_forum' ][ (int) $topic_id ] ?? 0;
		}
		function bbp_get_reply_forum_id( $reply_id = 0 ) {
			return $GLOBALS[ 'wp_loupe_test_bbp' ][ 'reply_forum' ][ (int) $reply_id ] ?? 0;
		}
	}
}

namespace Soderlind\Plugin\LoupeSearch {

	use PHPUnit\Framework\TestCase;

	/**
	 * bbPress integration: posts inside private/hidden forums must never be indexed,
	 * and visibility changes must re-evaluate the whole forum tree.
	 *
	 * @see includes/class-wp-loupe-bbpress.php
	 */
	class WP_Loupe_BBPressTest extends TestCase {

		private $original_wpdb;

		protected function setUp(): void {
			parent::setUp();
			$GLOBALS[ 'wp_loupe_test_bbp' ] = [
				'public'      => [ 1 => true, 2 => false ],
				'topic_forum' => [ 10 => 1, 20 => 2 ],
				'reply_forum' => [ 11 => 1, 21 => 2 ],
			];
			$this->original_wpdb = $GLOBALS[ 'wpdb' ] ?? null;
		}

		protected function tearDown(): void {
			$GLOBALS[ 'wpdb' ]              = $this->original_wpdb;
			$GLOBALS[ 'wp_loupe_test_bbp' ] = [];
			parent::tearDown();
		}

		/**
		 * Indexer double that records which IDs were re-evaluated.
		 */
		private function recording_indexer() {
			return new class( [], false ) extends WP_Loupe_Indexer {
				public array $reindexed = [];
				public function reindex_object_ids( array $object_ids ): void {
					$this->reindexed = array_merge( $this->reindexed, $object_ids );
				}
			};
		}

		private function integration( $indexer = null ): WP_Loupe_BBPress {
			return new WP_Loupe_BBPress( $indexer ?? $this->recording_indexer() );
		}

		public function test_topics_and_replies_in_a_public_forum_stay_indexable() {
			$bbp = $this->integration();

			$this->assertTrue( $bbp->filter_is_indexable( true, new \WP_Post( [ 'ID' => 10, 'post_type' => 'topic' ] ) ) );
			$this->assertTrue( $bbp->filter_is_indexable( true, new \WP_Post( [ 'ID' => 11, 'post_type' => 'reply' ] ) ) );
			$this->assertTrue( $bbp->filter_is_indexable( true, new \WP_Post( [ 'ID' => 1, 'post_type' => 'forum' ] ) ) );
		}

		public function test_published_topics_and_replies_in_a_private_forum_are_vetoed() {
			$bbp = $this->integration();

			$this->assertFalse( $bbp->filter_is_indexable( true, new \WP_Post( [ 'ID' => 20, 'post_type' => 'topic' ] ) ) );
			$this->assertFalse( $bbp->filter_is_indexable( true, new \WP_Post( [ 'ID' => 21, 'post_type' => 'reply' ] ) ) );
			$this->assertFalse( $bbp->filter_is_indexable( true, new \WP_Post( [ 'ID' => 2, 'post_type' => 'forum' ] ) ), 'a published forum whose ancestor tree is not public' );
		}

		public function test_non_bbpress_posts_and_forumless_topics_are_left_alone() {
			$bbp = $this->integration();

			$this->assertTrue( $bbp->filter_is_indexable( true, new \WP_Post( [ 'ID' => 2, 'post_type' => 'post' ] ) ), 'post ID 2 is not the private forum' );
			$this->assertTrue( $bbp->filter_is_indexable( true, new \WP_Post( [ 'ID' => 99, 'post_type' => 'topic' ] ) ) );
		}

		public function test_an_earlier_rejection_is_never_overturned() {
			$this->assertFalse( $this->integration()->filter_is_indexable( false, new \WP_Post( [ 'ID' => 10, 'post_type' => 'topic' ] ) ) );
		}

		public function test_visibility_change_reevaluates_the_forum_subforums_and_their_contents() {
			// Forum 2 → sub-forum 3 → sub-forum 4; contents keyed by _bbp_forum_id.
			$GLOBALS[ 'wpdb' ] = new class {
				public $posts    = 'wp_posts';
				public $postmeta = 'wp_postmeta';
				private array $children = [ 2 => [ 3 ], 3 => [ 4 ] ];
				private array $content  = [ 2 => [ 20, 21 ], 3 => [ 30 ], 4 => [ 40 ], 1 => [ 10 ] ];
				public function prepare( $query, ...$args ) {
					return [ $query, is_array( $args[ 0 ] ?? null ) ? $args[ 0 ] : $args ];
				}
				public function get_col( $prepared ) {
					[ $query, $args ] = $prepared;
					$out = [];
					if ( str_contains( $query, 'post_parent IN' ) ) {
						foreach ( array_slice( $args, 1 ) as $parent ) {
							$out = array_merge( $out, $this->children[ $parent ] ?? [] );
						}
					} elseif ( str_contains( $query, '_bbp_forum_id' ) ) {
						foreach ( $args as $forum ) {
							$out = array_merge( $out, $this->content[ $forum ] ?? [] );
						}
					}
					return array_map( 'strval', $out );
				}
			};

			$indexer = $this->recording_indexer();
			$this->integration( $indexer )->reindex_forum_contents( 2 );

			sort( $indexer->reindexed );
			$this->assertSame( [ 2, 3, 4, 20, 21, 30, 40 ], $indexer->reindexed );
		}

		public function test_regular_forum_save_only_reindexes_when_the_status_changed() {
			$indexer = $this->recording_indexer();
			$bbp     = $this->getMockBuilder( WP_Loupe_BBPress::class )
				->setConstructorArgs( [ $indexer ] )
				->onlyMethods( [ 'reindex_forum_contents' ] )
				->getMock();
			$bbp->expects( $this->once() )->method( 'reindex_forum_contents' )->with( 2 );

			$public  = new \WP_Post( [ 'ID' => 2, 'post_type' => 'forum', 'post_status' => 'publish' ] );
			$private = new \WP_Post( [ 'ID' => 2, 'post_type' => 'forum', 'post_status' => 'private' ] );
			$topic   = new \WP_Post( [ 'ID' => 20, 'post_type' => 'topic', 'post_status' => 'private' ] );

			$bbp->maybe_reindex_forum_contents( 2, $public, true, $public );   // unchanged
			$bbp->maybe_reindex_forum_contents( 2, $private, false, null );    // new forum
			$bbp->maybe_reindex_forum_contents( 20, $topic, true, $public );   // not a forum
			$bbp->maybe_reindex_forum_contents( 2, $private, true, $public );  // publish → private
		}
	}
}
