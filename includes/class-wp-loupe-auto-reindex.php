<?php
namespace Soderlind\Plugin\LoupeSearch;

/**
 * Rebuilds out-of-date indexes in the background after a plugin update.
 *
 * A bundled Loupe update (engine version) or a changed language mapping makes an
 * existing index report needsReindex(). Once per plugin version, the stale post
 * types are queued and rebuilt by WP-Cron using the batched reindex, so sites do
 * not depend on an admin clicking "Reindex". Missing indexes (fresh installs) are
 * left to the admin, as before.
 *
 * @since 1.5.3
 */
class WP_Loupe_Auto_Reindex {
	public const HOOK           = 'loupe_search_auto_reindex';
	public const VERSION_OPTION = 'loupe_search_version';
	public const STATE_OPTION   = 'loupe_search_auto_reindex_state';

	private const TIME_BUDGET = 20;
	private const BATCH_SIZE  = 200;

	private WP_Loupe_Search_Engine $engine;
	/** @var array<int,string> */
	private array $post_types;
	private ?string $plugin_version;
	/** @var callable(): WP_Loupe_Indexer */
	private $indexer_factory;

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
	 */
	public function run(): void {
		$state = get_option( self::STATE_OPTION );
		if ( ! is_array( $state ) || empty( $state[ 'post_types' ] ) ) {
			return;
		}

		try {
			$indexer = ( $this->indexer_factory )();

			if ( ! isset( $state[ 'v' ] ) ) {
				// Skip post types an admin or WP-CLI already rebuilt since queueing.
				$post_types = $this->get_stale_post_types( (array) $state[ 'post_types' ] );
				if ( empty( $post_types ) ) {
					$this->finish();
					return;
				}
				$state = $indexer->reindex_batch_init( $post_types );
			}

			$deadline = microtime( true ) + self::TIME_BUDGET;
			while ( empty( $state[ 'done' ] ) && microtime( true ) < $deadline ) {
				$state = $indexer->reindex_batch_step( $state, self::BATCH_SIZE );
			}
		} catch (\Throwable $e) {
			// Give up; the admin notice still asks for a manual reindex.
			WP_Loupe_Utils::debug_log( 'Background reindex failed: ' . $e->getMessage(), 'WP Loupe' );
			$this->finish();
			return;
		}

		if ( ! empty( $state[ 'done' ] ) ) {
			$this->finish();
			return;
		}

		update_option( self::STATE_OPTION, $state, false );
		$this->schedule();
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
