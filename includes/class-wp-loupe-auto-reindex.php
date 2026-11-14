<?php
namespace Soderlind\Plugin\LoupeSearch;

/**
 * Rebuilds out-of-date indexes in the background after a plugin update.
 *
 * A bundled Loupe update (engine version) or a changed language mapping makes an
 * existing index report needsReindex(). Once per plugin version, the stale post
 * types are queued and rebuilt by WP-Cron, so sites do not depend on an admin
 * clicking "Reindex". Missing indexes (fresh installs) are left to the admin.
 *
 * Each post type is built into a shadow directory next to the live index and
 * swapped in when complete, so searches keep using the old index until then.
 * Post saves/deletes during the build are mirrored into the shadow. If the build
 * fails the shadow is discarded and the stale index (and its admin notice) stays.
 *
 * @since 1.5.3
 */
class WP_Loupe_Auto_Reindex {
	public const HOOK           = 'loupe_search_auto_reindex';
	public const VERSION_OPTION = 'loupe_search_version';
	public const STATE_OPTION   = 'loupe_search_auto_reindex_state';

	private const TIME_BUDGET   = 20;
	private const BATCH_SIZE    = 200;
	private const SHADOW_SUFFIX = '.rebuild';

	private WP_Loupe_Search_Engine $engine;
	/** @var array<int,string> */
	private array $post_types;
	private ?string $plugin_version;
	/** @var callable(): WP_Loupe_Indexer */
	private $indexer_factory;
	private ?WP_Loupe_Indexer $indexer = null;
	/** @var array<string,object> Shadow Loupe instances opened in this request. */
	private array $shadows = [];

	/**
	 * @param WP_Loupe_Search_Engine $engine          Engine used to check index readiness.
	 * @param array                  $post_types      Indexed post types.
	 * @param string|null            $plugin_version  Current plugin version; read from the plugin header when null.
	 * @param callable|null          $indexer_factory Returns the indexer used for the rebuild.
	 */
	public function __construct( WP_Loupe_Search_Engine $engine, array $post_types, ?string $plugin_version = null, ?callable $indexer_factory = null ) {
		$this->engine          = $engine;
		$this->post_types      = array_values( array_map( 'strval', $post_types ) );
		$this->plugin_version  = $plugin_version;
		$this->indexer_factory = $indexer_factory ?? static fn() => new WP_Loupe_Indexer( null, false );
	}

	public function register(): void {
		add_action( 'admin_init', [ $this, 'maybe_schedule' ] );
		add_action( self::HOOK, [ $this, 'run' ] );
		add_action( 'loupe_search_internal_document_added', [ $this, 'mirror_add' ], 10, 2 );
		add_action( 'loupe_search_internal_documents_deleted', [ $this, 'mirror_delete' ], 10, 2 );
	}

	/**
	 * Once per plugin version, queue a background rebuild of stale indexes.
	 */
	public function maybe_schedule(): void {
		$version = $this->get_plugin_version();
		if ( '' === $version || get_option( self::VERSION_OPTION ) === $version ) {
			return;
		}
		update_option( self::VERSION_OPTION, $version );

		$stale = $this->get_stale_post_types( $this->post_types );
		if ( empty( $stale ) ) {
			return;
		}

		update_option( self::STATE_OPTION, [ 'post_types' => $stale ], false );
		$this->schedule();
	}

	/**
	 * Cron callback: advance the rebuild within a time budget, then reschedule.
	 *
	 * State: queue (post types left), post_type (being built), after (last post ID).
	 * At most one post type is completed per request: Loupe keeps each opened
	 * index (and its tokenizer cache) in memory until the request ends.
	 */
	public function run(): void {
		$state = get_option( self::STATE_OPTION );
		if ( ! is_array( $state ) || empty( $state[ 'post_types' ] ) ) {
			return;
		}

		if ( ! isset( $state[ 'queue' ] ) ) {
			// Skip post types an admin or WP-CLI already rebuilt since queueing.
			$state[ 'queue' ]     = $this->get_stale_post_types( (array) $state[ 'post_types' ] );
			$state[ 'post_type' ] = null;
			$state[ 'after' ]     = 0;
		}

		try {
			$deadline = microtime( true ) + self::TIME_BUDGET;
			while ( microtime( true ) < $deadline ) {
				if ( null === $state[ 'post_type' ] ) {
					if ( empty( $state[ 'queue' ] ) ) {
						$this->finish();
						return;
					}
					$state[ 'post_type' ] = (string) array_shift( $state[ 'queue' ] );
					$state[ 'after' ]     = 0;
					$this->delete_directory( $this->get_shadow_path( $state[ 'post_type' ] ) );
					// Mirror edits from now on; the batches pick up everything else.
					update_option( self::STATE_OPTION, $state, false );
				}

				$post_type = $state[ 'post_type' ];
				$last_id   = $this->get_indexer()->index_batch_into( $this->open_shadow( $post_type ), $post_type, (int) $state[ 'after' ], self::BATCH_SIZE );

				if ( $last_id > 0 ) {
					$state[ 'after' ] = $last_id;
					continue;
				}

				$this->swap( $post_type );
				$state[ 'post_type' ] = null;
				if ( empty( $state[ 'queue' ] ) ) {
					$this->finish();
					return;
				}
				break;
			}
		} catch (\Throwable $e) {
			// Keep the stale live index (still searchable, still flagged by the admin notice).
			WP_Loupe_Utils::debug_log( 'Background reindex failed: ' . $e->getMessage(), 'WP Loupe' );
			if ( ! empty( $state[ 'post_type' ] ) ) {
				unset( $this->shadows[ (string) $state[ 'post_type' ] ] );
				$this->delete_directory( $this->get_shadow_path( (string) $state[ 'post_type' ] ) );
			}
			$this->finish();
			return;
		}

		update_option( self::STATE_OPTION, $state, false );
		$this->schedule();
	}

	/**
	 * Mirror a document saved to the live index into the shadow being built.
	 *
	 * @param string $post_type
	 * @param array  $document
	 */
	public function mirror_add( $post_type, $document ): void {
		$shadow = $this->get_active_shadow( (string) $post_type );
		if ( null === $shadow ) {
			return;
		}
		try {
			$shadow->addDocument( (array) $document );
		} catch (\Throwable $e) {
			WP_Loupe_Utils::debug_log( 'Background reindex mirror failed: ' . $e->getMessage(), 'WP Loupe' );
		}
	}

	/**
	 * Mirror documents deleted from the live index into the shadow being built.
	 *
	 * @param string $post_type
	 * @param array  $ids
	 */
	public function mirror_delete( $post_type, $ids ): void {
		$shadow = $this->get_active_shadow( (string) $post_type );
		if ( null === $shadow ) {
			return;
		}
		try {
			$shadow->deleteDocuments( array_values( (array) $ids ) );
		} catch (\Throwable $e) {
			WP_Loupe_Utils::debug_log( 'Background reindex mirror failed: ' . $e->getMessage(), 'WP Loupe' );
		}
	}

	/**
	 * Remove scheduled work and stored state (deactivation/uninstall).
	 */
	public static function clear(): void {
		wp_clear_scheduled_hook( self::HOOK );
		delete_option( self::STATE_OPTION );
	}

	/**
	 * @param array $post_types
	 * @return array<int,string>
	 */
	private function get_stale_post_types( array $post_types ): array {
		$stale = [];
		foreach ( $post_types as $post_type ) {
			$status = $this->engine->is_index_ready( (string) $post_type );
			if ( 'index_needs_reindex' === ( $status[ 'reason' ] ?? null ) ) {
				$stale[] = (string) $post_type;
			}
		}
		return $stale;
	}

	private function schedule(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_single_event( time(), self::HOOK );
		}
	}

	/**
	 * @return \Soderlind\Plugin\LoupeSearch\Vendor\Loupe\Loupe\Loupe|null
	 */
	private function get_active_shadow( string $post_type ) {
		$state = get_option( self::STATE_OPTION );
		if ( ! is_array( $state ) || ( $state[ 'post_type' ] ?? null ) !== $post_type ) {
			return null;
		}
		if ( ! is_dir( $this->get_shadow_path( $post_type ) ) ) {
			return null;
		}
		return $this->open_shadow( $post_type );
	}

	/**
	 * @return \Soderlind\Plugin\LoupeSearch\Vendor\Loupe\Loupe\Loupe
	 */
	private function open_shadow( string $post_type ) {
		if ( ! isset( $this->shadows[ $post_type ] ) ) {
			$this->shadows[ $post_type ] = WP_Loupe_Factory::create_loupe_instance_at( $post_type, WP_Loupe_Factory::get_site_language(), $this->get_shadow_path( $post_type ) );
		}
		return $this->shadows[ $post_type ];
	}

	/**
	 * Replace the live index with the completed shadow.
	 */
	private function swap( string $post_type ): void {
		$live   = $this->get_live_path( $post_type );
		$shadow = $this->get_shadow_path( $post_type );
		$old    = $live . '.old';

		unset( $this->shadows[ $post_type ] );
		$this->delete_directory( $old );
		if ( is_dir( $live ) && ! rename( $live, $old ) ) {
			throw new \RuntimeException( "Could not move {$live} aside." );
		}
		if ( ! rename( $shadow, $live ) ) {
			if ( is_dir( $old ) ) {
				rename( $old, $live );
			}
			throw new \RuntimeException( "Could not move {$shadow} into place." );
		}
		$this->delete_directory( $old );
		WP_Loupe_Factory::clear_instance_cache( $post_type );
	}

	private function get_live_path( string $post_type ): string {
		return rtrim( WP_Loupe_DB::get_instance()->get_base_path(), '/' ) . '/' . $post_type;
	}

	private function get_shadow_path( string $post_type ): string {
		return $this->get_live_path( $post_type ) . self::SHADOW_SUFFIX;
	}

	private function delete_directory( string $path ): void {
		if ( ! is_dir( $path ) ) {
			return;
		}
		$items = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $path, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $items as $item ) {
			$item->isDir() && ! $item->isLink() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		rmdir( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}

	private function get_indexer(): WP_Loupe_Indexer {
		if ( null === $this->indexer ) {
			$this->indexer = ( $this->indexer_factory )();
		}
		return $this->indexer;
	}

	private function finish(): void {
		delete_option( self::STATE_OPTION );
		WP_Loupe_Index_Notice::flush();
	}

	private function get_plugin_version(): string {
		if ( null === $this->plugin_version ) {
			$data                 = get_file_data( LOUPE_SEARCH_FILE, [ 'Version' => 'Version' ] );
			$this->plugin_version = (string) ( $data[ 'Version' ] ?? '' );
		}
		return $this->plugin_version;
	}
}
