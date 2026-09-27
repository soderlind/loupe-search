<?php
namespace Soderlind\Plugin\LoupeSearch;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin notice prompting a reindex when a configured post type's index is
 * missing, unreadable, or out of date — e.g. after the multisite per-site index
 * path change (issue #56) orphaned an existing single-site index, or after an
 * upgrade that changes the schema.
 */
class WP_Loupe_Index_Notice {

	/** Cached list of not-ready post types. */
	private const TRANSIENT = 'loupe_search_index_status';

	/** @var WP_Loupe_Search_Engine */
	private $engine;

	/** @var array<int,string> */
	private $post_types;

	/**
	 * @param WP_Loupe_Search_Engine $engine
	 * @param array<int,string>      $post_types
	 */
	public function __construct( WP_Loupe_Search_Engine $engine, array $post_types ) {
		$this->engine     = $engine;
		$this->post_types = $post_types;
	}

	/**
	 * Register the admin notice.
	 */
	public function register(): void {
		add_action( 'admin_notices', [ $this, 'maybe_render' ] );
	}

	/**
	 * Drop the cached status so the next admin page re-evaluates index readiness.
	 * Call after a reindex completes or the indexed post types change.
	 */
	public static function flush(): void {
		if ( function_exists( 'delete_transient' ) ) {
			delete_transient( self::TRANSIENT );
		}
	}

	/**
	 * Render the reindex prompt when at least one index is not ready.
	 */
	public function maybe_render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// The plugin's own dashboard already offers the reindex UI.
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && isset( $screen->id ) && 'settings_page_loupe-search' === $screen->id ) {
			return;
		}

		if ( empty( $this->get_unready_post_types() ) ) {
			return;
		}

		$url = admin_url( 'options-general.php?page=loupe-search&tab=dashboard' );
		printf(
			'<div class="notice notice-warning"><p><strong>%1$s</strong> %2$s</p><p><a class="button button-primary" href="%3$s">%4$s</a></p></div>',
			esc_html__( 'Loupe Search:', 'loupe-search' ),
			esc_html__( 'The search index needs to be rebuilt before results appear. This can happen after upgrading, changing indexed post types, or on multisite where each site keeps its own index.', 'loupe-search' ),
			esc_url( $url ),
			esc_html__( 'Reindex now', 'loupe-search' )
		);
	}

	/**
	 * Post types whose index is missing, unreadable, or out of date. Cached briefly
	 * so readiness (which can open each index) is not recomputed on every admin page.
	 *
	 * @return array<int,string>
	 */
	private function get_unready_post_types(): array {
		$cached = get_transient( self::TRANSIENT );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$unready = [];
		foreach ( $this->post_types as $post_type ) {
			$status = $this->engine->is_index_ready( (string) $post_type );
			if ( empty( $status[ 'ready' ] ) ) {
				$unready[] = (string) $post_type;
			}
		}

		set_transient( self::TRANSIENT, $unready, 15 * MINUTE_IN_SECONDS );
		return $unready;
	}
}
