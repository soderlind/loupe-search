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
	public const WIDGET_LIMIT      = 5;
	public const PERIODS           = [ '7', '30', '90', 'this_month', 'last_week', 'last_month', 'last_quarter', 'custom' ];
	public const DEFAULT_PERIOD    = '30';
	public const PAGE_SLUG         = 'loupe-search-insights';
	public const WIDGET_ID         = 'loupe_search_insights';

	private const SAVE_ACTION   = 'loupe_search_insights_save';
	private const CLEAR_ACTION  = 'loupe_search_insights_clear';
	private const EXPORT_ACTION = 'loupe_search_insights_export';

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
			add_action( 'admin_post_' . self::EXPORT_ACTION, [ $this, 'handle_export' ] );
			add_action( 'admin_menu', [ $this, 'add_report_page' ] );
			add_action( 'wp_dashboard_setup', [ $this, 'add_dashboard_widget' ] );
			add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		}
	}

	/**
	 * Load the report stylesheet on the report page and the Dashboard (widget),
	 * and the date picker script on the report page.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue_assets( $hook ): void {
		if ( ! in_array( $hook, [ 'index.php', 'dashboard_page_' . self::PAGE_SLUG ], true ) || ! self::settings()[ 'enabled' ] ) {
			return;
		}
		wp_enqueue_style( 'loupe-search-insights', LOUPE_SEARCH_URL . 'lib/css/insights.css', [], WP_Loupe_Utils::get_version_number() );
		if ( 'dashboard_page_' . self::PAGE_SLUG === $hook ) {
			// Core localizes jquery-ui-datepicker with the date format from Settings → General.
			wp_enqueue_script( 'loupe-search-insights', LOUPE_SEARCH_URL . 'lib/js/insights.js', [ 'jquery-ui-datepicker' ], WP_Loupe_Utils::get_version_number(), true );
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
		return self::report_range( self::day_offset( max( 1, $days ) - 1 ), self::today() );
	}

	/**
	 * Aggregate report for an inclusive date range.
	 *
	 * The two lists do not overlap: top searches lists queries that returned
	 * results at least once in the range, searches without results lists
	 * queries that never returned anything in the range.
	 * The zero_results total counts the searches for those never-found queries,
	 * so it matches the searches without results list.
	 *
	 * @param string $from  First day (Y-m-d).
	 * @param string $to    Last day (Y-m-d).
	 * @param int    $limit Rows per list.
	 * @return array{searches:int,zero_results:int,average_results:float,top:array,zero:array}
	 */
	public static function report_range( string $from, string $to, int $limit = self::REPORT_LIMIT ): array {
		global $wpdb;
		$empty = [ 'searches' => 0, 'zero_results' => 0, 'average_results' => 0.0, 'top' => [], 'zero' => [] ];
		if ( ! self::is_installed() ) {
			return $empty;
		}
		$table = self::table_name();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table; name is not user input.
		$totals = $wpdb->get_row( $wpdb->prepare(
			"SELECT SUM(q.searches) AS searches, SUM(CASE WHEN q.zero_results = q.searches THEN q.searches ELSE 0 END) AS zero_results, SUM(q.results_sum) AS results_sum
			FROM ( SELECT SUM(searches) AS searches, SUM(zero_results) AS zero_results, SUM(results_sum) AS results_sum FROM {$table} WHERE day >= %s AND day <= %s GROUP BY search_query ) AS q",
			$from,
			$to
		), ARRAY_A );
		$top    = $wpdb->get_results( $wpdb->prepare(
			"SELECT search_query, SUM(searches) AS searches, SUM(zero_results) AS zero_results, SUM(results_sum) AS results_sum
			FROM {$table} WHERE day >= %s AND day <= %s GROUP BY search_query HAVING SUM(zero_results) < SUM(searches) ORDER BY searches DESC, search_query ASC LIMIT %d",
			$from,
			$to,
			$limit
		), ARRAY_A );
		$zero   = $wpdb->get_results( $wpdb->prepare(
			"SELECT search_query, SUM(zero_results) AS zero_results
			FROM {$table} WHERE day >= %s AND day <= %s GROUP BY search_query HAVING SUM(zero_results) = SUM(searches) ORDER BY zero_results DESC, search_query ASC LIMIT %d",
			$from,
			$to,
			$limit
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
	 * Daily rows for an inclusive date range, for export.
	 *
	 * @param string $from First day (Y-m-d).
	 * @param string $to   Last day (Y-m-d).
	 * @return array<int,array<string,mixed>>
	 */
	public static function export_rows( string $from, string $to ): array {
		global $wpdb;
		if ( ! self::is_installed() ) {
			return [];
		}
		$table = self::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table; name is not user input.
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT day, search_query, searches, zero_results, results_sum FROM {$table} WHERE day >= %s AND day <= %s ORDER BY day ASC, search_query ASC",
			$from,
			$to
		), ARRAY_A );
		return is_array( $rows ) ? $rows : [];
	}

	/**
	 * Neutralize a CSV cell that a spreadsheet would run as a formula.
	 *
	 * Search queries are visitor input, so "=HYPERLINK(...)" must stay text.
	 *
	 * @param mixed $value Cell value.
	 * @return string
	 */
	public static function csv_cell( $value ): string {
		$value = (string) $value;
		if ( '' !== $value && false !== strpos( "=+-@\t\r", $value[ 0 ] ) ) {
			return "'" . $value;
		}
		return $value;
	}

	/**
	 * Turn a period key (and custom dates) into an inclusive date range.
	 *
	 * Calendar periods are complete: "last month" is the whole previous month.
	 * Weeks start on the site's "Week Starts On" day.
	 *
	 * @param string $period Period key, see PERIODS.
	 * @param string $from   Custom first day (Y-m-d).
	 * @param string $to     Custom last day (Y-m-d).
	 * @return array{period:string,from:string,to:string}
	 */
	public static function resolve_period( string $period, string $from = '', string $to = '' ): array {
		$today = new \DateTimeImmutable( self::today(), new \DateTimeZone( 'UTC' ) );
		$fmt   = static fn( \DateTimeImmutable $d ): string => $d->format( 'Y-m-d' );

		switch ( $period ) {
			case '7':
			case '30':
			case '90':
				return [ 'period' => $period, 'from' => $fmt( $today->modify( '-' . ( (int) $period - 1 ) . ' days' ) ), 'to' => $fmt( $today ) ];

			case 'this_month':
				return [ 'period' => $period, 'from' => $fmt( $today->modify( 'first day of this month' ) ), 'to' => $fmt( $today ) ];

			case 'last_week':
				$start_of_week = ( (int) get_option( 'start_of_week', 1 ) ) % 7;
				$into_week     = ( (int) $today->format( 'w' ) - $start_of_week + 7 ) % 7;
				$this_week     = $today->modify( '-' . $into_week . ' days' );
				return [ 'period' => $period, 'from' => $fmt( $this_week->modify( '-7 days' ) ), 'to' => $fmt( $this_week->modify( '-1 day' ) ) ];

			case 'last_month':
				return [ 'period' => $period, 'from' => $fmt( $today->modify( 'first day of last month' ) ), 'to' => $fmt( $today->modify( 'last day of last month' ) ) ];

			case 'last_quarter':
				$quarter_start = $today->setDate( (int) $today->format( 'Y' ), intdiv( (int) $today->format( 'n' ) - 1, 3 ) * 3 + 1, 1 );
				return [ 'period' => $period, 'from' => $fmt( $quarter_start->modify( '-3 months' ) ), 'to' => $fmt( $quarter_start->modify( '-1 day' ) ) ];

			case 'custom':
				$start = self::parse_day( $from );
				$end   = self::parse_day( $to );
				if ( null !== $start && null !== $end ) {
					if ( $start > $end ) {
						[ $start, $end ] = [ $end, $start ];
					}
					$end   = min( $end, $fmt( $today ) );
					$start = min( $start, $end );
					return [ 'period' => $period, 'from' => $start, 'to' => $end ];
				}
				break;
		}

		return self::resolve_period( self::DEFAULT_PERIOD );
	}

	/**
	 * Validate a Y-m-d date string.
	 *
	 * @param string $day Raw value.
	 * @return string|null Normalized day, or null when invalid.
	 */
	private static function parse_day( string $day ): ?string {
		$date = \DateTimeImmutable::createFromFormat( '!Y-m-d', $day, new \DateTimeZone( 'UTC' ) );
		return ( $date && $date->format( 'Y-m-d' ) === $day ) ? $day : null;
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
		if ( empty( $_POST[ 'confirm_clear' ] ) ) {
			$this->redirect_back( 'not_confirmed' );
		}
		self::clear();
		$this->redirect_back( 'cleared' );
	}

	/**
	 * Stream the selected period as CSV.
	 *
	 * @return void
	 */
	public function handle_export(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage options for this site.', 'loupe-search' ), 403 );
		}
		check_admin_referer( self::EXPORT_ACTION );

		$range = self::period_from_request();
		$rows  = self::export_rows( $range[ 'from' ], $range[ 'to' ] );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( sprintf( 'Content-Disposition: attachment; filename="loupe-search-insights-%s-%s.csv"', $range[ 'from' ], $range[ 'to' ] ) );

		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" ); // UTF-8 BOM so spreadsheet apps detect the encoding.
		fputcsv( $out, [ 'day', 'search_query', 'searches', 'zero_results', 'results_sum' ], ',', '"', '' );
		foreach ( $rows as $row ) {
			fputcsv(
				$out,
				[
					(string) $row[ 'day' ],
					self::csv_cell( $row[ 'search_query' ] ),
					(int) $row[ 'searches' ],
					(int) $row[ 'zero_results' ],
					(int) $row[ 'results_sum' ],
				],
				',',
				'"',
				''
			);
		}
		fclose( $out );
		exit;
	}

	/**
	 * Add Dashboard → Search insights while collection is on.
	 *
	 * @return void
	 */
	public function add_report_page(): void {
		if ( ! self::settings()[ 'enabled' ] ) {
			return;
		}
		add_dashboard_page(
			__( 'Search insights', 'loupe-search' ),
			__( 'Search insights', 'loupe-search' ),
			'manage_options',
			self::PAGE_SLUG,
			[ self::class, 'render_page' ]
		);
	}

	/**
	 * Add the dashboard widget while collection is on.
	 *
	 * @return void
	 */
	public function add_dashboard_widget(): void {
		if ( ! self::settings()[ 'enabled' ] || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		wp_add_dashboard_widget( self::WIDGET_ID, __( 'Search insights', 'loupe-search' ), [ self::class, 'render_widget' ] );
	}

	/**
	 * URL of the report page.
	 *
	 * @param array<string,string> $args Extra query args.
	 * @return string
	 */
	public static function report_url( array $args = [] ): string {
		return add_query_arg( array_merge( [ 'page' => self::PAGE_SLUG ], $args ), admin_url( 'index.php' ) );
	}

	/**
	 * URL of the settings tab.
	 *
	 * @param array<string,string> $args Extra query args.
	 * @return string
	 */
	private static function settings_url( array $args = [] ): string {
		return add_query_arg( array_merge( [ 'page' => 'loupe-search', 'tab' => 'insights' ], $args ), admin_url( 'options-general.php' ) );
	}

	/**
	 * Redirect to the Search insights tab with a notice flag.
	 *
	 * @param string $notice Notice key.
	 * @return void
	 */
	private function redirect_back( string $notice ): void {
		wp_safe_redirect( self::settings_url( [ 'insights_notice' => $notice ] ) );
		exit;
	}

	/**
	 * Period selected in the current request.
	 *
	 * @return array{period:string,from:string,to:string}
	 */
	private static function period_from_request(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only view state; export checks its own nonce.
		$period = isset( $_GET[ 'period' ] ) ? sanitize_key( wp_unslash( $_GET[ 'period' ] ) ) : self::DEFAULT_PERIOD;
		$from   = isset( $_GET[ 'from' ] ) ? sanitize_text_field( wp_unslash( $_GET[ 'from' ] ) ) : '';
		$to     = isset( $_GET[ 'to' ] ) ? sanitize_text_field( wp_unslash( $_GET[ 'to' ] ) ) : '';
		// phpcs:enable
		return self::resolve_period( $period, $from, $to );
	}

	/**
	 * Human label for a period key.
	 *
	 * @param string $period Period key.
	 * @return string
	 */
	private static function period_label( string $period ): string {
		switch ( $period ) {
			case '7':
			case '30':
			case '90':
				/* translators: %d: number of days. */
				return sprintf( _n( 'Last %d day', 'Last %d days', (int) $period, 'loupe-search' ), (int) $period );
			case 'this_month':
				return __( 'This month', 'loupe-search' );
			case 'last_week':
				return __( 'Last week', 'loupe-search' );
			case 'last_month':
				return __( 'Last month', 'loupe-search' );
			case 'last_quarter':
				return __( 'Last quarter', 'loupe-search' );
			default:
				return __( 'Custom', 'loupe-search' );
		}
	}

	/**
	 * Render the Search insights settings tab.
	 *
	 * @return void
	 */
	public static function render_tab(): void {
		$settings = self::settings();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view state.
		$notice = isset( $_GET[ 'insights_notice' ] ) ? sanitize_key( $_GET[ 'insights_notice' ] ) : '';
		if ( 'saved' === $notice ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Search insights settings saved.', 'loupe-search' ) . '</p></div>';
		} elseif ( 'cleared' === $notice ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'All search insights data was deleted.', 'loupe-search' ) . '</p></div>';
		} elseif ( 'not_confirmed' === $notice ) {
			echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__( 'Nothing was deleted. To delete all search insights data, tick the confirmation box first.', 'loupe-search' ) . '</p></div>';
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
			<?php submit_button( __( 'Save Changes', 'loupe-search' ) ); ?>
		</form>
		<?php
		if ( $settings[ 'enabled' ] ) {
			?>
			<hr />
			<p>
				<a class="button" href="<?php echo esc_url( self::report_url() ); ?>"><?php esc_html_e( 'View report', 'loupe-search' ); ?></a>
				<span class="description"><?php esc_html_e( 'The report is under Dashboard → Search insights, with a summary on the Dashboard.', 'loupe-search' ); ?></span>
			</p>
			<?php
		}
		if ( self::has_data() ) {
			self::render_clear_form();
		}
	}

	/**
	 * Render the Dashboard → Search insights page.
	 *
	 * @return void
	 */
	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage options for this site.', 'loupe-search' ), 403 );
		}
		$range = self::period_from_request();
		?>
		<div class="wrap loupe-search-insights">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Search insights', 'loupe-search' ); ?></h1>
			<a class="page-title-action" href="<?php echo esc_url( self::settings_url() ); ?>"><?php esc_html_e( 'Settings', 'loupe-search' ); ?></a>
			<hr class="wp-header-end" />
			<?php self::render_report( $range ); ?>
		</div>
		<?php
	}

	/**
	 * Render the dashboard widget: last 7 days at a glance.
	 *
	 * @return void
	 */
	public static function render_widget(): void {
		$range  = self::resolve_period( '7' );
		$report = self::report_range( $range[ 'from' ], $range[ 'to' ], self::WIDGET_LIMIT );
		?>
		<div class="loupe-search-insights loupe-search-insights__widget">
			<p class="loupe-search-insights__period"><strong><?php echo esc_html( self::period_label( '7' ) ); ?></strong></p>
			<?php self::render_summary( $report ); ?>
			<h3><?php esc_html_e( 'Top searches', 'loupe-search' ); ?></h3>
			<?php self::render_table( $report[ 'top' ], [ 'search_query' => __( 'Search', 'loupe-search' ), 'searches' => __( 'Searches', 'loupe-search' ) ] ); ?>
			<h3><?php esc_html_e( 'Searches without results', 'loupe-search' ); ?></h3>
			<?php self::render_table( $report[ 'zero' ], [ 'search_query' => __( 'Search', 'loupe-search' ), 'zero_results' => __( 'Searches', 'loupe-search' ) ] ); ?>
			<p><a href="<?php echo esc_url( self::report_url() ); ?>"><?php esc_html_e( 'View full report', 'loupe-search' ); ?></a></p>
		</div>
		<?php
	}

	/**
	 * Render the totals sentence.
	 *
	 * @param array{searches:int,zero_results:int,average_results:float} $report Report.
	 * @return void
	 */
	private static function render_summary( array $report ): void {
		?>
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
		<?php
	}

	/**
	 * Render the period picker, export link and reports.
	 *
	 * @param array{period:string,from:string,to:string} $range Selected period.
	 * @return void
	 */
	private static function render_report( array $range ): void {
		$report      = self::report_range( $range[ 'from' ], $range[ 'to' ] );
		$date_format = (string) get_option( 'date_format', 'Y-m-d' );
		$export_url  = wp_nonce_url(
			add_query_arg(
				[ 'action' => self::EXPORT_ACTION, 'period' => $range[ 'period' ], 'from' => $range[ 'from' ], 'to' => $range[ 'to' ] ],
				admin_url( 'admin-post.php' )
			),
			self::EXPORT_ACTION
		);
		?>
		<h2 class="screen-reader-text"><?php esc_html_e( 'Choose a period', 'loupe-search' ); ?></h2>
		<ul class="subsubsub">
			<?php
			$links = [];
			foreach ( array_diff( self::PERIODS, [ 'custom' ] ) as $period ) {
				$links[] = sprintf(
					'<li><a href="%s"%s>%s</a></li>',
					esc_url( self::report_url( [ 'period' => $period ] ) ),
					$period === $range[ 'period' ] ? ' class="current" aria-current="page"' : '',
					esc_html( self::period_label( $period ) )
				);
			}
			if ( 'custom' === $range[ 'period' ] ) {
				$links[] = '<li><span class="current" aria-current="page">' . esc_html( self::period_label( 'custom' ) ) . '</span></li>';
			}
			echo implode( ' | ', $links ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
			?>
		</ul>
		<form method="get" action="<?php echo esc_url( admin_url( 'index.php' ) ); ?>" class="loupe-search-insights__range">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>" />
			<input type="hidden" name="period" value="custom" />
			<label for="loupe-search-insights-from"><?php esc_html_e( 'From', 'loupe-search' ); ?></label>
			<input type="date" id="loupe-search-insights-from" name="from" value="<?php echo esc_attr( $range[ 'from' ] ); ?>" max="<?php echo esc_attr( self::today() ); ?>" required />
			<label for="loupe-search-insights-to"><?php esc_html_e( 'To', 'loupe-search' ); ?></label>
			<input type="date" id="loupe-search-insights-to" name="to" value="<?php echo esc_attr( $range[ 'to' ] ); ?>" max="<?php echo esc_attr( self::today() ); ?>" required />
			<button type="submit" class="button"><?php esc_html_e( 'Apply', 'loupe-search' ); ?></button>
		</form>
		<div class="loupe-search-insights__heading">
			<h2>
				<?php
				echo esc_html( sprintf(
					/* translators: 1: period name, 2: first day, 3: last day. */
					__( '%1$s: %2$s – %3$s', 'loupe-search' ),
					self::period_label( $range[ 'period' ] ),
					date_i18n( $date_format, strtotime( $range[ 'from' ] . ' 12:00:00' ) ),
					date_i18n( $date_format, strtotime( $range[ 'to' ] . ' 12:00:00' ) )
				) );
				?>
			</h2>
			<a class="button" href="<?php echo esc_url( $export_url ); ?>"><?php esc_html_e( 'Export this period (CSV)', 'loupe-search' ); ?></a>
		</div>
		<?php self::render_summary( $report ); ?>
		<div class="loupe-search-insights__columns">
			<div class="loupe-search-insights__column">
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
			<div class="loupe-search-insights__column">
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
					<?php foreach ( $columns as $key => $heading ) : ?>
						<th scope="col"<?php echo 'search_query' === $key ? '' : ' class="num"'; ?>><?php echo esc_html( $heading ); ?></th>
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
							<td<?php echo 'search_query' === $key ? '' : ' class="num"'; ?>>
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
	 * Render the Delete all data form.
	 *
	 * @return void
	 */
	private static function render_clear_form(): void {
		?>
		<hr />
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return window.confirm('<?php echo esc_js( __( 'Delete all search insights data? This cannot be undone.', 'loupe-search' ) ); ?>');">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::CLEAR_ACTION ); ?>" />
			<?php wp_nonce_field( self::CLEAR_ACTION ); ?>
			<p>
				<label>
					<input type="checkbox" name="confirm_clear" value="1" required />
					<?php esc_html_e( 'I understand that this permanently deletes all search insights data and cannot be undone.', 'loupe-search' ); ?>
				</label>
			</p>
			<?php submit_button( __( 'Delete all data permanently', 'loupe-search' ), 'secondary loupe-search-insights-delete', 'submit', false ); ?>
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
