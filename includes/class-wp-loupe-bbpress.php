<?php
namespace Soderlind\Plugin\LoupeSearch;

/**
 * bbPress integration.
 *
 * A bbPress topic or reply keeps post_status 'publish' even when its forum is
 * private or hidden; bbPress hides it at query time. Loupe returns its own
 * results, so such posts must never reach the index.
 *
 * @package Soderlind\Plugin\LoupeSearch
 * @since 1.3.8
 */
class WP_Loupe_BBPress {

	/** @var WP_Loupe_Indexer */
	private $indexer;

	/**
	 * @param WP_Loupe_Indexer $indexer Indexer used to refresh affected posts.
	 */
	public function __construct( WP_Loupe_Indexer $indexer ) {
		$this->indexer = $indexer;
	}

	/**
	 * Whether bbPress is loaded.
	 *
	 * @return bool
	 */
	public static function is_active(): bool {
		return function_exists( 'bbp_is_forum_public' ) && function_exists( 'bbp_get_forum_post_type' );
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'loupe_search_is_indexable', [ $this, 'filter_is_indexable' ], 10, 2 );
		// bbPress changes visibility with a direct DB update; these fire after its cache flush.
		foreach ( [ 'bbp_publicized_forum', 'bbp_privatized_forum', 'bbp_hid_forum' ] as $hook ) {
			add_action( $hook, [ $this, 'reindex_forum_contents' ] );
		}
		// Re-check once bbPress has written its relationship meta, which can land after wp_after_insert_post.
		foreach ( [ 'bbp_insert_topic', 'bbp_insert_reply', 'bbp_new_topic', 'bbp_edit_topic', 'bbp_new_reply', 'bbp_edit_reply' ] as $hook ) {
			add_action( $hook, [ $this, 'reindex_post' ] );
		}
		add_action( 'wp_after_insert_post', [ $this, 'maybe_reindex_after_save' ], 20, 4 );
	}

	/**
	 * Veto forums, topics and replies that are not in a fully public forum tree.
	 *
	 * @param bool  $indexable Whether the post may be indexed.
	 * @param mixed $post      Post object.
	 * @return bool
	 */
	public function filter_is_indexable( $indexable, $post = null ): bool {
		if ( ! $indexable || ! $post instanceof \WP_Post ) {
			return (bool) $indexable;
		}

		$forum_id = $this->get_forum_id( $post );
		if ( $forum_id <= 0 ) {
			return true;
		}

		return (bool) bbp_is_forum_public( $forum_id, true );
	}

	/**
	 * Re-check posts whose visibility depends on a forum or topic that was moved or changed status.
	 *
	 * @param int           $post_id     Post ID.
	 * @param \WP_Post      $post        Post object.
	 * @param bool          $update      Whether this is an update.
	 * @param \WP_Post|null $post_before Post before the update.
	 * @return void
	 */
	public function maybe_reindex_after_save( int $post_id, $post, bool $update, $post_before = null ): void {
		if ( ! $update || ! $post instanceof \WP_Post || ! $post_before instanceof \WP_Post ) {
			return;
		}

		$parent_changed = (int) $post->post_parent !== (int) $post_before->post_parent;

		if ( bbp_get_forum_post_type() === $post->post_type ) {
			if ( $parent_changed || $post->post_status !== $post_before->post_status ) {
				$this->reindex_forum_contents( $post_id );
			}
			return;
		}

		if ( bbp_get_topic_post_type() === $post->post_type && $parent_changed ) {
			$this->reindex_topic_replies( $post_id );
		}
	}

	/**
	 * Re-check a single topic or reply.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public function reindex_post( $post_id ): void {
		$post_id = (int) $post_id;
		if ( $post_id > 0 ) {
			$this->indexer->reindex_object_ids( [ $post_id ] );
		}
	}

	/**
	 * Re-check all replies of a topic, whose forum is resolved through the topic.
	 *
	 * @param int $topic_id Topic ID.
	 * @return void
	 */
	public function reindex_topic_replies( $topic_id ): void {
		global $wpdb;

		$topic_id = (int) $topic_id;
		if ( $topic_id <= 0 ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_parent = %d",
				bbp_get_reply_post_type(),
				$topic_id
			)
		);

		$this->indexer->reindex_object_ids( array_map( 'intval', (array) $ids ) );
	}

	/**
	 * Re-evaluate a forum, its sub-forums and all their topics and replies.
	 *
	 * The indexer adds posts that became public and purges those that did not.
	 *
	 * @param int $forum_id Forum ID.
	 * @return void
	 */
	public function reindex_forum_contents( $forum_id ): void {
		$forum_id = (int) $forum_id;
		if ( $forum_id <= 0 ) {
			return;
		}

		$forum_ids = array_merge( [ $forum_id ], $this->get_descendant_forum_ids( $forum_id ) );
		$this->indexer->reindex_object_ids( array_merge( $forum_ids, $this->get_forum_content_ids( $forum_ids ) ) );
	}

	/**
	 * Forum that governs a post's visibility, or 0 when not a bbPress post.
	 *
	 * @param \WP_Post $post Post object.
	 * @return int
	 */
	private function get_forum_id( \WP_Post $post ): int {
		switch ( $post->post_type ) {
			case bbp_get_forum_post_type():
				return (int) $post->ID;
			case bbp_get_topic_post_type():
				return (int) bbp_get_topic_forum_id( $post->ID );
			case bbp_get_reply_post_type():
				return (int) bbp_get_reply_forum_id( $post->ID );
		}
		return 0;
	}

	/**
	 * IDs of all sub-forums below a forum.
	 *
	 * Uses SQL so bbPress's visibility filters on WP_Query cannot hide private forums.
	 *
	 * @param int $forum_id Forum ID.
	 * @return array<int,int>
	 */
	private function get_descendant_forum_ids( int $forum_id ): array {
		global $wpdb;

		$found   = [];
		$parents = [ $forum_id ];
		while ( ! empty( $parents ) ) {
			$placeholders = implode( ', ', array_fill( 0, count( $parents ), '%d' ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$children = $wpdb->get_col(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders are built from a fixed '%d' list.
					"SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_parent IN ( {$placeholders} )",
					array_merge( [ bbp_get_forum_post_type() ], $parents )
				)
			);
			$children = array_diff( array_map( 'intval', (array) $children ), $found, [ $forum_id ] );
			$found    = array_merge( $found, $children );
			$parents  = $children;
		}

		return array_values( $found );
	}

	/**
	 * IDs of all topics and replies that belong to the given forums.
	 *
	 * @param array<int,int> $forum_ids Forum IDs.
	 * @return array<int,int>
	 */
	private function get_forum_content_ids( array $forum_ids ): array {
		global $wpdb;

		$placeholders = implode( ', ', array_fill( 0, count( $forum_ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders are built from a fixed '%d' list.
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_bbp_forum_id' AND meta_value IN ( {$placeholders} )",
				$forum_ids
			)
		);

		return array_map( 'intval', (array) $ids );
	}
}
