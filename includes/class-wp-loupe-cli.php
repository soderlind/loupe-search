<?php
namespace Soderlind\Plugin\WPLoupe;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( '\\WP_CLI' ) ) {
	/**
	 * WP-CLI commands for WP Loupe.
	 */
	class WP_Loupe_CLI_Command {
		/**
		 * Reindex all configured post types in batches.
		 *
		 * ## OPTIONS
		 *
		 * [--post-types=<slugs>]
		 * : Comma or space separated list of post types. Defaults to the plugin setting.
		 *
		 * [--batch-size=<n>]
		 * : Number of posts per batch. Default: 500. Range: 10..2000
		 *
		 * ## EXAMPLES
		 *
		 *   # Reindex all configured post types
		 *   wp loupe-search reindex
		 *
		 *   # Reindex only posts in bigger batches
		 *   wp loupe-search reindex --post-types=post --batch-size=1000
		 */
		public function reindex( $args, $assoc_args ) {
			$batch_size = isset( $assoc_args[ 'batch-size' ] ) ? (int) $assoc_args[ 'batch-size' ] : 500;
			if ( $batch_size < 10 || $batch_size > 2000 ) {
				$batch_size = 500;
			}

			$post_types = null;
			if ( isset( $assoc_args[ 'post-types' ] ) && is_string( $assoc_args[ 'post-types' ] ) && trim( $assoc_args[ 'post-types' ] ) !== '' ) {
				$post_types = preg_split( '/[\s,]+/', trim( (string) $assoc_args[ 'post-types' ] ) );
				$post_types = array_values( array_unique( array_filter( array_map( function ( $v ) {
					return is_string( $v ) ? sanitize_key( $v ) : '';
				}, $post_types ) ) ) );
				if ( empty( $post_types ) ) {
					$post_types = null;
				}
			}

			$indexer = new WP_Loupe_Indexer( null, false );
			$state   = $indexer->reindex_batch_init( $post_types );

			$totals = [];
			$total  = 0;
			if ( isset( $state[ 'post_types' ] ) && is_array( $state[ 'post_types' ] ) ) {
				foreach ( $state[ 'post_types' ] as $pt ) {
					$counts                  = function_exists( 'wp_count_posts' ) ? wp_count_posts( (string) $pt ) : null;
					$publish                 = ( is_object( $counts ) && isset( $counts->publish ) ) ? (int) $counts->publish : 0;
					$totals[ (string) $pt ]  = $publish;
					$total                  += $publish;
				}
			}

			\WP_CLI::log( 'WP Loupe: starting batched reindex…' );
			if ( $total > 0 ) {
				\WP_CLI::log( 'Total published posts: ' . $total );
			}

			$last_logged = -1;
			while ( empty( $state[ 'done' ] ) ) {
				$state            = $indexer->reindex_batch_step( $state, $batch_size );
				$processed        = isset( $state[ 'processed' ] ) ? (int) $state[ 'processed' ] : 0;
				$idx              = isset( $state[ 'idx' ] ) ? (int) $state[ 'idx' ] : 0;
				$post_types_state = isset( $state[ 'post_types' ] ) && is_array( $state[ 'post_types' ] ) ? $state[ 'post_types' ] : [];
				$current_pt       = ( $idx < count( $post_types_state ) ) ? (string) $post_types_state[ $idx ] : null;
				$pt_processed     = isset( $state[ 'processed_pt' ] ) ? (int) $state[ 'processed_pt' ] : 0;

				if ( $processed !== $last_logged ) {
					$last_logged = $processed;
					if ( $total > 0 ) {
						$pct  = min( 100, (int) round( ( $processed / $total ) * 100 ) );
						$line = sprintf( 'Progress: %d%% (%d/%d)', $pct, $processed, $total );
					} else {
						$line = sprintf( 'Progress: %d', $processed );
					}
					if ( $current_pt ) {
						$pt_total = isset( $totals[ $current_pt ] ) ? (int) $totals[ $current_pt ] : 0;
						if ( $pt_total > 0 ) {
							$line .= sprintf( ' — %s: %d/%d', $current_pt, $pt_processed, $pt_total );
						} else {
							$line .= sprintf( ' — %s: %d', $current_pt, $pt_processed );
						}
					}
					\WP_CLI::log( $line );
				}
			}

			\WP_CLI::success( 'Reindex completed.' );
		}
	}

	\WP_CLI::add_command( 'loupe-search', '\\Soderlind\\Plugin\\WPLoupe\\WP_Loupe_CLI_Command' );

	// Deprecated alias, kept for backward compatibility. Deprecated since 1.1.0; use `wp loupe-search`.
	\WP_CLI::add_command(
		'wp-loupe',
		'\\Soderlind\\Plugin\\WPLoupe\\WP_Loupe_CLI_Command',
		[
			'before_invoke' => function () {
				\WP_CLI::warning( 'The `wp wp-loupe` command is deprecated since 1.1.0 and will be removed in a future major release. Use `wp loupe-search` instead.' );
			},
		]
	);
}
