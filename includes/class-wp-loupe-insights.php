<?php
namespace Soderlind\Plugin\LoupeSearch;

/**
 * Search insights: opt-in, privacy-friendly daily totals per normalized query.
 *
 * Stores only (day, query, searches, zero-result searches, summed result count)
 * in a per-site table. No IP address, user, user agent or time of day is kept.
 * See docs/adr/0003-search-insights-daily-totals-only.md.
 *
 * @since 1.4.0
 */
class WP_Loupe_Insights {
	public const OPTION            = 'loupe_search_insights';
	public const DB_VERSION_OPTION = 'loupe_search_insights_db_version';
	public const DB_VERSION        = '2';
	public const TABLE             = 'loupe_search_insights';
	public const CRON_HOOK         = 'loupe_search_insights_purge';
	public const MAX_QUERY_LENGTH  = 100;
	public const DEFAULT_RETENTION = 90;
	public const MAX_RETENTION     = 365;
	public const REPORT_LIMIT      = 20;
	public const PERIODS           = [ 7, 30, 90 ];

	private const SAVE_ACTION  = 'loupe_search_insights_save';
	private const CLEAR_ACTION = 'loupe_search_insights_clear';

	/**
	 * User-agent fragments of crawlers, monitors and HTTP libraries.
	 */
	private const BOT_PATTERN = '/bot|crawl|spider|slurp|archiver|facebookexternalhit|embedly|preview|headless|lighthouse|pingdom|uptime|monitor|curl|wget|python|java\/|go-http|httpclient|okhttp|axios|node-fetch|scrapy/i';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'loupe_search_searched', [ $this, 'on_searched' ], 10, 3 );
		add_action( self::CRON_HOOK, [ self::class, 'purge' ] );

		if ( is_admin() ) {
			add_action( 'admin_init', [ $this, 'admin_init' ] );
			add_action( 'admin_post_' . self::SAVE_ACTION, [ $this, 'handle_save' ] );
			add_action( 'admin_post_' . self::CLEAR_ACTION, [ $this, 'handle_clear' ] );
		}
	}

	/**
	 * Current settings with defaults applied.
	 *
	 * @return array{enabled:bool,retention_days:int}
	 */
	public static function settings(): array {
		$raw = get_option( self::OPTION, [] );
		$raw = is_array( $raw ) ? $raw : [];
		return [
			'enabled'        => ! empty( $raw[ 'enabled' ] ),
			'retention_days' => self::sanitize_retention( $raw[ 'retention_days' ] ?? self::DEFAULT_RETENTION ),
		];
	}

	/**
	 * Clamp a retention period to 1..MAX_RETENTION days.
	 *
	 * @param mixed $days Raw value.
	 * @return int
	 */
	public static function sanitize_retention( $days ): int {
		$days = (int) $days;
		if ( $days < 1 ) {
			return self::DEFAULT_RETENTION;
		}
		return min( $days, self::MAX_RETENTION );
	}

	/**
	 * Normalize a query for counting, or return '' when it must not be stored.
	 *
	 * Lowercases, trims and collapses whitespace. Queries that are too long or
	 * look like an email address or phone number are discarded, as they are
	 * likely to contain personal data.
	 *
	 * @param string $query Raw query.
	 * @return string Normalized query, or '' to skip.
	 */
	public static function normalize( string $query ): string {
		$query = trim( (string) preg_replace( '/\s+/u', ' ', $query ) );
		$query = function_exists( 'mb_strtolower' ) ? mb_strtolower( $query, 'UTF-8' ) : strtolower( $query );
		if ( '' === $query ) {
			return '';
		}
		$length = function_exists( 'mb_strlen' ) ? mb_strlen( $query, 'UTF-8' ) : strlen( $query );
		if ( $length > self::MAX_QUERY_LENGTH ) {
			return '';
		}
		if ( preg_match( '/[^\s@]+@[^\s@]+\.[^\s@]+/u', $query ) ) {
			return '';
		}
		// Seven or more digits, optionally separated by spaces, dots, dashes, slashes or brackets.
		if ( preg_match( '/\+?\d(?:[\s().\/-]*\d){6,}/', $query ) ) {
			return '';
		}
		return $query;
	}

	/**
	 * Whether a user agent looks like an automated client. Empty counts as a bot.
	 *
	 * @param string $user_agent User agent header.
	 * @return bool
	 */
	public static function is_bot( string $user_agent ): bool {
		return '' === trim( $user_agent ) || 1 === preg_match( self::BOT_PATTERN, $user_agent );
	}

	/**
	 * Whether the current request is a front-end visitor search we may count.
	 *
	 * @param string $source 'main' or 'rest'.
	 * @return bool
	 */
	public static function should_count( string $source ): bool {
		$countable = true;
		if ( ( defined( 'WP_CLI' ) && WP_CLI ) || ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) ) {
			$countable = false;
		} elseif ( is_admin() ) {
			$countable = false;
		} elseif ( current_user_can( 'manage_options' ) ) {
			$countable = false;
		} else {
			$user_agent = isset( $_SERVER[ 'HTTP_USER_AGENT' ] ) ? (string) wp_unslash( $_SERVER[ 'HTTP_USER_AGENT' ] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- only pattern-matched, never stored.
			$countable  = ! self::is_bot( $user_agent );
		}

		/**
		 * Filters whether a visitor search is counted by Search insights.
		 *
		 * @since 1.4.0
		 * @param bool   $countable Whether to count the search.
		 * @param string $source    'main' or 'rest'.
		 */
		return (bool) apply_filters( 'loupe_search_insights_should_count', $countable, $source );
	}

	/**
	 * Handle the loupe_search_searched action.
	 *
	 * @param string $query      Query as typed.
	 * @param int    $total_hits Matching documents.
	 * @param string $source     'main' or 'rest'.
	 * @return void
	 */
	public function on_searched( $query, $total_hits = 0, $source = 'main' ): void {
		if ( ! self::settings()[ 'enabled' ] || ! self::should_count( (string) $source ) ) {
			return;
		}
		$normalized = self::normalize( (string) $query );
		if ( '' === $normalized ) {
			return;
		}
		self::maybe_install();
		self::record( $normalized, max( 0, (int) $total_hits ) );
	}

	/**
	 * Add one search to today's totals for a normalized query.
	 *
	 * @param string $normalized Normalized query.
	 * @param int    $total_hits Matching documents.
	 * @return void
	 */
	public static function record( string $normalized, int $total_hits ): void {
		global $wpdb;
		$table = self::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table; name is not user input.
		$wpdb->query( $wpdb->prepare(
			"INSERT INTO {$table} (day, search_query, searches, zero_results, results_sum) VALUES (%s, %s, 1, %d, %d)
			ON DUPLICATE KEY UPDATE searches = searches + 1, zero_results = zero_results + VALUES(zero_results), results_sum = results_sum + VALUES(results_sum)",
			self::today(),
			$normalized,
			0 === $total_hits ? 1 : 0,
			$total_hits
		) );
	}

	/**
	 * Aggregate report for the last $days days (today included).
	 *
	 * @param int $days Period length.
	 * @return array{searches:int,zero_results:int,average_results:float,top:array,zero:array}
	 */
	public static function report( int $days ): array {
		global $wpdb;
		$empty = [ 'searches' => 0, 'zero_results' => 0, 'average_results' => 0.0, 'top' => [], 'zero' => [] ];
		if ( ! self::is_installed() ) {
			return $empty;
		}
		$table = self::table_name();
		$since = self::day_offset( max( 1, $days ) - 1 );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table; name is not user input.
		$totals = $wpdb->get_row( $wpdb->prepare(
			"SELECT SUM(searches) AS searches, SUM(zero_results) AS zero_results, SUM(results_sum) AS results_sum FROM {$table} WHERE day >= %s",
			$since
		), ARRAY_A );
		$top    = $wpdb->get_results( $wpdb->prepare(
			"SELECT search_query, SUM(searches) AS searches, SUM(zero_results) AS zero_results, SUM(results_sum) AS results_sum
			FROM {$table} WHERE day >= %s GROUP BY search_query ORDER BY searches DESC, search_query ASC LIMIT %d",
			$since,
			self::REPORT_LIMIT
		), ARRAY_A );
		$zero   = $wpdb->get_results( $wpdb->prepare(
			"SELECT search_query, SUM(zero_results) AS zero_results
			FROM {$table} WHERE day >= %s GROUP BY search_query HAVING SUM(zero_results) > 0 ORDER BY zero_results DESC, search_query ASC LIMIT %d",
			$since,
			self::REPORT_LIMIT
		), ARRAY_A );
		// phpcs:enable

		$searches = (int) ( $totals[ 'searches' ] ?? 0 );
		return [
			'searches'        => $searches,
			'zero_results'    => (int) ( $totals[ 'zero_results' ] ?? 0 ),
			'average_results' => $searches > 0 ? round( (int) ( $totals[ 'results_sum' ] ?? 0 ) / $searches, 1 ) : 0.0,
			'top'             => is_array( $top ) ? $top : [],
			'zero'            => is_array( $zero ) ? $zero : [],
		];
	}

	/**
	 * Delete rows older than the retention period (daily cron).
	 *
	 * @return void
	 */
	public static function purge(): void {
		global $wpdb;
		if ( ! self::is_installed() ) {
			return;
		}
		$table  = self::table_name();
		$cutoff = self::day_offset( self::settings()[ 'retention_days' ] );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table; name is not user input.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE day < %s", $cutoff ) );
	}

	/**
	 * Delete all collected data.
	 *
	 * @return void
	 */
	public static function clear(): void {
		global $wpdb;
		if ( ! self::is_installed() ) {
			return;
		}
		$table = self::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table; name is not user input.
		$wpdb->query( "DELETE FROM {$table}" );
	}

	/**
	 * Whether any data is stored.
	 *
	 * @return bool
	 */
	public static function has_data(): bool {
		global $wpdb;
		if ( ! self::is_installed() ) {
			return false;
		}
		$table = self::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table; name is not user input.
		return null !== $wpdb->get_var( "SELECT 1 FROM {$table} LIMIT 1" );
	}

	/**
	 * Full table name for the current site.
	 *
	 * @return string
	 */
	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	/**
	 * Whether the table exists at the current schema version.
	 *
	 * @return bool
	 */
	public static function is_installed(): bool {
		return self::DB_VERSION === get_option( self::DB_VERSION_OPTION );
	}

	/**
	 * Create or upgrade the table when needed, and schedule the purge.
	 *
	 * @return void
	 */
	public static function maybe_install(): void {
		if ( ! self::is_installed() ) {
			self::install();
		}
		if ( function_exists( 'wp_next_scheduled' ) && ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Create or upgrade the table.
	 *
	 * @return void
	 */
	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table_name();
		$charset = $wpdb->get_charset_collate();
		// Binary so accent and case variants (cafe/café) are distinct keys; 400 bytes fits MAX_QUERY_LENGTH UTF-8 chars.
		dbDelta( "CREATE TABLE {$table} (
  day date NOT NULL,
  search_query varbinary(400) NOT NULL,
  searches int(10) unsigned NOT NULL DEFAULT 0,
  zero_results int(10) unsigned NOT NULL DEFAULT 0,
  results_sum bigint(20) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (day,search_query),
  KEY search_query (search_query)
) {$charset};" );
		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );
	}

	/**
	 * Drop the table, settings and cron event for the current site (uninstall).
	 *
	 * @return void
	 */
	public static function uninstall(): void {
		global $wpdb;
		$table = self::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table; name is not user input.
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
		delete_option( self::OPTION );
		delete_option( self::DB_VERSION_OPTION );
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/**
	 * Admin init: keep the purge scheduled and add privacy policy text.
	 *
	 * @return void
	 */
	public function admin_init(): void {
		if ( self::is_installed() && ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
		if ( function_exists( 'wp_add_privacy_policy_content' ) ) {
			wp_add_privacy_policy_content( 'Loupe Search', wp_kses_post( wpautop( self::privacy_text(), false ) ) );
		}
	}

	/**
	 * Suggested privacy policy text.
	 *
	 * @return string
	 */
	public static function privacy_text(): string {
		return __( 'When Search insights is turned on, this site counts the words visitors search for so we can improve our content. We store only the search words (lowercased) and, per day, how many times they were searched and how many results they returned. We do not store your IP address, user account, browser details or the time of your search. Searches that look like an email address or phone number are not stored. The daily totals are deleted automatically after the retention period set by the site administrator.', 'loupe-search' );
	}

	/**
	 * Save the enable toggle and retention period.
	 *
	 * @return void
	 */
	public function handle_save(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage options for this site.', 'loupe-search' ), 403 );
		}
		check_admin_referer( self::SAVE_ACTION );

		$current = self::settings();
		$enabled = ! empty( $_POST[ 'enabled' ] );
		$days    = isset( $_POST[ 'retention_days' ] ) ? self::sanitize_retention( absint( wp_unslash( $_POST[ 'retention_days' ] ) ) ) : $current[ 'retention_days' ];
		update_option( self::OPTION, [ 'enabled' => $enabled, 'retention_days' => $days ], false );

		if ( $enabled ) {
			self::maybe_install();
			if ( $days !== $current[ 'retention_days' ] ) {
				self::purge();
			}
		}
		$this->redirect_back( 'saved' );
	}

	/**
	 * Clear all collected data.
	 *
	 * @return void
	 */
	public function handle_clear(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage options for this site.', 'loupe-search' ), 403 );
		}
		check_admin_referer( self::CLEAR_ACTION );
		self::clear();
		$this->redirect_back( 'cleared' );
	}

	/**
	 * Redirect to the Search insights tab with a notice flag.
	 *
	 * @param string $notice Notice key.
	 * @return void
	 */
	private function redirect_back( string $notice ): void {
		wp_safe_redirect( add_query_arg( [ 'page' => 'loupe-search', 'tab' => 'insights', 'insights_notice' => $notice ], admin_url( 'options-general.php' ) ) );
		exit;
	}

	/**
	 * Render the Search insights settings tab.
	 *
	 * @return void
	 */
	public static function render_tab(): void {
		$settings = self::settings();
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only view state.
		$notice = isset( $_GET[ 'insights_notice' ] ) ? sanitize_key( $_GET[ 'insights_notice' ] ) : '';
		$period = isset( $_GET[ 'period' ] ) ? absint( $_GET[ 'period' ] ) : 30;
		// phpcs:enable
		if ( ! in_array( $period, self::PERIODS, true ) ) {
			$period = 30;
		}

		if ( 'saved' === $notice ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Search insights settings saved.', 'loupe-search' ) . '</p></div>';
		} elseif ( 'cleared' === $notice ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'All search insights data was deleted.', 'loupe-search' ) . '</p></div>';
		}
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::SAVE_ACTION ); ?>" />
			<?php wp_nonce_field( self::SAVE_ACTION ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Search insights', 'loupe-search' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="enabled" value="1" <?php checked( $settings[ 'enabled' ] ); ?> />
							<?php esc_html_e( 'Count what visitors search for', 'loupe-search' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'Stores only daily totals per search query: how often it was searched and how many results it returned. No IP address, user, browser or time of day is stored. Searches by administrators, bots, WP-CLI and the admin are not counted. Queries that look like an email address or phone number are discarded.', 'loupe-search' ); ?></p>
					</td>
				</tr>
				<?php if ( $settings[ 'enabled' ] ) : ?>
					<tr>
						<th scope="row"><label for="loupe-search-insights-retention"><?php esc_html_e( 'Keep data for', 'loupe-search' ); ?></label></th>
						<td>
							<input type="number" id="loupe-search-insights-retention" name="retention_days" min="1" max="<?php echo esc_attr( (string) self::MAX_RETENTION ); ?>" value="<?php echo esc_attr( (string) $settings[ 'retention_days' ] ); ?>" class="small-text" />
							<?php esc_html_e( 'days', 'loupe-search' ); ?>
							<p class="description"><?php esc_html_e( 'Older daily totals are deleted automatically once a day.', 'loupe-search' ); ?></p>
						</td>
					</tr>
				<?php endif; ?>
			</table>
			<?php submit_button( __( 'Save Settings', 'loupe-search' ) ); ?>
		</form>
		<?php
		if ( $settings[ 'enabled' ] ) {
			self::render_report( $period );
		}
		if ( self::has_data() ) {
			self::render_clear_form();
		}
	}

	/**
	 * Render the period picker and reports.
	 *
	 * @param int $period Days.
	 * @return void
	 */
	private static function render_report( int $period ): void {
		$report = self::report( $period );
		?>
		<hr />
		<h2><?php esc_html_e( 'Report', 'loupe-search' ); ?></h2>
		<ul class="subsubsub" style="float:none;margin-bottom:1em;">
			<?php
			$links = [];
			foreach ( self::PERIODS as $days ) {
				$url     = add_query_arg( [ 'page' => 'loupe-search', 'tab' => 'insights', 'period' => $days ], admin_url( 'options-general.php' ) );
				/* translators: %d: number of days. */
				$label   = sprintf( _n( 'Last %d day', 'Last %d days', $days, 'loupe-search' ), $days );
				$links[] = sprintf(
					'<li><a href="%s"%s>%s</a></li>',
					esc_url( $url ),
					$days === $period ? ' class="current" aria-current="page"' : '',
					esc_html( $label )
				);
			}
			echo implode( ' | ', $links ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
			?>
		</ul>
		<p>
			<?php
			printf(
				/* translators: 1: number of searches, 2: number of searches without results, 3: average number of results. */
				esc_html__( '%1$s searches, %2$s without results, %3$s results on average.', 'loupe-search' ),
				'<strong>' . esc_html( number_format_i18n( $report[ 'searches' ] ) ) . '</strong>',
				'<strong>' . esc_html( number_format_i18n( $report[ 'zero_results' ] ) ) . '</strong>',
				'<strong>' . esc_html( number_format_i18n( $report[ 'average_results' ], 1 ) ) . '</strong>'
			);
			?>
		</p>
		<div style="display:flex;flex-wrap:wrap;gap:2em;">
			<div style="flex:1 1 320px;">
				<h3><?php esc_html_e( 'Top searches', 'loupe-search' ); ?></h3>
				<?php
				self::render_table(
					$report[ 'top' ],
					[
						'search_query' => __( 'Search', 'loupe-search' ),
						'searches'     => __( 'Searches', 'loupe-search' ),
						'average'      => __( 'Avg. results', 'loupe-search' ),
					]
				);
				?>
			</div>
			<div style="flex:1 1 320px;">
				<h3><?php esc_html_e( 'Searches without results', 'loupe-search' ); ?></h3>
				<?php
				self::render_table(
					$report[ 'zero' ],
					[
						'search_query' => __( 'Search', 'loupe-search' ),
						'zero_results' => __( 'Searches', 'loupe-search' ),
					]
				);
				?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render a report table.
	 *
	 * @param array<int,array<string,mixed>> $rows    Rows.
	 * @param array<string,string>           $columns Column key => heading.
	 * @return void
	 */
	private static function render_table( array $rows, array $columns ): void {
		?>
		<table class="widefat striped">
			<thead>
				<tr>
					<?php foreach ( $columns as $heading ) : ?>
						<th scope="col"><?php echo esc_html( $heading ); ?></th>
					<?php endforeach; ?>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $rows ) ) : ?>
					<tr><td colspan="<?php echo esc_attr( (string) count( $columns ) ); ?>"><?php esc_html_e( 'No searches yet.', 'loupe-search' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $rows as $row ) : ?>
					<tr>
						<?php foreach ( array_keys( $columns ) as $key ) : ?>
							<td>
								<?php
								if ( 'search_query' === $key ) {
									echo esc_html( (string) $row[ 'search_query' ] );
								} elseif ( 'average' === $key ) {
									$searches = (int) $row[ 'searches' ];
									echo esc_html( number_format_i18n( $searches > 0 ? (int) $row[ 'results_sum' ] / $searches : 0, 1 ) );
								} else {
									echo esc_html( number_format_i18n( (int) $row[ $key ] ) );
								}
								?>
							</td>
						<?php endforeach; ?>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Render the Clear all data form.
	 *
	 * @return void
	 */
	private static function render_clear_form(): void {
		?>
		<hr />
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return window.confirm('<?php echo esc_js( __( 'Delete all search insights data? This cannot be undone.', 'loupe-search' ) ); ?>');">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::CLEAR_ACTION ); ?>" />
			<?php wp_nonce_field( self::CLEAR_ACTION ); ?>
			<?php submit_button( __( 'Clear all data', 'loupe-search' ), 'delete', 'submit', false ); ?>
		</form>
		<?php
	}

	/**
	 * Today's date in the site timezone (Y-m-d).
	 *
	 * @return string
	 */
	private static function today(): string {
		return self::day_offset( 0 );
	}

	/**
	 * Date $days days before today in the site timezone (Y-m-d).
	 *
	 * @param int $days Days back.
	 * @return string
	 */
	private static function day_offset( int $days ): string {
		$now = (int) current_time( 'timestamp' ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested -- local calendar day.
		return gmdate( 'Y-m-d', $now - $days * DAY_IN_SECONDS );
	}
}
