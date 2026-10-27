<?php
namespace {
	if ( ! function_exists( 'current_time' ) ) {
		function current_time( $type ) {
			return $GLOBALS[ 'wp_loupe_test_now' ] ?? time();
		}
	}
	if ( ! function_exists( 'wp_clear_scheduled_hook' ) ) {
		function wp_clear_scheduled_hook( $hook ) {
			$GLOBALS[ 'wp_loupe_test_cleared_hooks' ][] = $hook;
		}
	}
	if ( ! defined( 'DAY_IN_SECONDS' ) ) {
		define( 'DAY_IN_SECONDS', 86400 );
	}
	if ( ! defined( 'ARRAY_A' ) ) {
		define( 'ARRAY_A', 'ARRAY_A' );
	}
}

namespace Soderlind\Plugin\LoupeSearch {

	use PHPUnit\Framework\TestCase;

	/**
	 * Search insights: privacy-friendly daily totals of settled visitor searches.
	 *
	 * @see includes/class-wp-loupe-insights.php
	 */
	class WP_Loupe_InsightsTest extends TestCase {

		private $original_wpdb;

		/** @var object Recording wpdb double. */
		private $db;

		protected function setUp(): void {
			parent::setUp();
			$GLOBALS[ 'wp_loupe_test_hooks' ]       = [];
			$GLOBALS[ 'wp_loupe_test_is_admin' ]    = false;
			$GLOBALS[ 'wp_loupe_test_user_can' ]    = false;
			$GLOBALS[ 'wp_loupe_test_now' ]         = gmmktime( 12, 0, 0, 3, 15, 2026 );
			$GLOBALS[ 'wp_loupe_test_options' ][ WP_Loupe_Insights::DB_VERSION_OPTION ] = WP_Loupe_Insights::DB_VERSION;
			$GLOBALS[ 'wp_loupe_test_options' ][ WP_Loupe_Insights::OPTION ]            = [ 'enabled' => true, 'retention_days' => 90 ];
			$_SERVER[ 'HTTP_USER_AGENT' ]           = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_0) AppleWebKit/605.1.15 Safari/605.1.15';

			$this->original_wpdb = $GLOBALS[ 'wpdb' ];
			$this->db            = new class () {
				public $prefix  = 'wp_';
				public $queries = [];
				public $row     = null;
				public $results = [];
				public function prepare( $query, ...$args ) {
					foreach ( $args as $arg ) {
						$replacement = is_int( $arg ) ? (string) $arg : "'" . addslashes( (string) $arg ) . "'";
						$query       = preg_replace( '/%[sd]/', $replacement, $query, 1 );
					}
					return $query;
				}
				public function query( $sql ) {
					$this->queries[] = $sql;
					return 1;
				}
				public function get_row( $sql, $output = null ) {
					$this->queries[] = $sql;
					return $this->row;
				}
				public function get_results( $sql, $output = null ) {
					$this->queries[] = $sql;
					return array_shift( $this->results ) ?? [];
				}
				public function get_var( $sql ) {
					$this->queries[] = $sql;
					return null;
				}
			};
			$GLOBALS[ 'wpdb' ] = $this->db;
		}

		protected function tearDown(): void {
			$GLOBALS[ 'wpdb' ] = $this->original_wpdb;
			unset( $GLOBALS[ 'wp_loupe_test_user_can' ], $GLOBALS[ 'wp_loupe_test_now' ], $_SERVER[ 'HTTP_USER_AGENT' ] );
			unset( $GLOBALS[ 'wp_loupe_test_options' ][ WP_Loupe_Insights::OPTION ], $GLOBALS[ 'wp_loupe_test_options' ][ WP_Loupe_Insights::DB_VERSION_OPTION ] );
			$GLOBALS[ 'wp_loupe_test_filters' ] = [];
			parent::tearDown();
		}

		public function test_normalize_lowercases_trims_and_collapses_whitespace(): void {
			$this->assertSame( 'hello wide world', WP_Loupe_Insights::normalize( "  Hello \t WIDE\n world " ) );
			$this->assertSame( 'smørbrød', WP_Loupe_Insights::normalize( 'SMØRBRØD' ) );
		}

		public function test_normalize_discards_empty_overlong_and_personal_looking_queries(): void {
			$this->assertSame( '', WP_Loupe_Insights::normalize( '   ' ) );
			$this->assertSame( '', WP_Loupe_Insights::normalize( str_repeat( 'a', 101 ) ) );
			$this->assertSame( str_repeat( 'a', 100 ), WP_Loupe_Insights::normalize( str_repeat( 'a', 100 ) ) );
			$this->assertSame( '', WP_Loupe_Insights::normalize( 'contact per@example.com' ) );
			$this->assertSame( '', WP_Loupe_Insights::normalize( '+47 912 34 567' ) );
			$this->assertSame( '', WP_Loupe_Insights::normalize( '(555) 123-4567' ) );
			$this->assertSame( 'wordpress 6.9 release', WP_Loupe_Insights::normalize( 'WordPress 6.9 release' ) );
			$this->assertSame( 'iso 27001', WP_Loupe_Insights::normalize( 'ISO 27001' ) );
		}

		public function test_bots_and_missing_user_agents_are_detected(): void {
			$this->assertTrue( WP_Loupe_Insights::is_bot( '' ) );
			$this->assertTrue( WP_Loupe_Insights::is_bot( 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)' ) );
			$this->assertTrue( WP_Loupe_Insights::is_bot( 'curl/8.4.0' ) );
			$this->assertTrue( WP_Loupe_Insights::is_bot( 'Mozilla/5.0 HeadlessChrome/120.0' ) );
			$this->assertFalse( WP_Loupe_Insights::is_bot( $_SERVER[ 'HTTP_USER_AGENT' ] ) );
		}

		public function test_only_front_end_visitors_are_counted(): void {
			$this->assertTrue( WP_Loupe_Insights::should_count( 'main' ) );

			$GLOBALS[ 'wp_loupe_test_user_can' ] = true;
			$this->assertFalse( WP_Loupe_Insights::should_count( 'main' ), 'Administrators are not counted.' );
			$GLOBALS[ 'wp_loupe_test_user_can' ] = false;

			$GLOBALS[ 'wp_loupe_test_is_admin' ] = true;
			$this->assertFalse( WP_Loupe_Insights::should_count( 'rest' ), 'Admin requests are not counted.' );
			$GLOBALS[ 'wp_loupe_test_is_admin' ] = false;

			$_SERVER[ 'HTTP_USER_AGENT' ] = 'Bingbot/2.0';
			$this->assertFalse( WP_Loupe_Insights::should_count( 'rest' ), 'Bots are not counted.' );
		}

		public function test_should_count_is_filterable(): void {
			$GLOBALS[ 'wp_loupe_test_filters' ][ 'loupe_search_insights_should_count' ] = static fn() => false;
			$this->assertFalse( WP_Loupe_Insights::should_count( 'main' ) );
		}

		public function test_a_counted_search_adds_to_todays_totals(): void {
			( new WP_Loupe_Insights() )->on_searched( '  Opening HOURS ', 0, 'rest' );

			$this->assertCount( 1, $this->db->queries );
			$sql = $this->db->queries[ 0 ];
			$this->assertStringContainsString( 'INSERT INTO wp_loupe_search_insights', $sql );
			$this->assertStringContainsString( "VALUES ('2026-03-15', 'opening hours', 1, 1, 0)", $sql );
			$this->assertStringContainsString( 'ON DUPLICATE KEY UPDATE searches = searches + 1', $sql );
		}

		public function test_a_search_with_results_is_not_a_zero_result_search(): void {
			( new WP_Loupe_Insights() )->on_searched( 'news', 12, 'main' );
			$this->assertStringContainsString( "VALUES ('2026-03-15', 'news', 1, 0, 12)", $this->db->queries[ 0 ] );
		}

		public function test_nothing_is_stored_when_disabled_skipped_or_discarded(): void {
			$insights = new WP_Loupe_Insights();

			$GLOBALS[ 'wp_loupe_test_options' ][ WP_Loupe_Insights::OPTION ] = [ 'enabled' => false ];
			$insights->on_searched( 'news', 3, 'main' );

			$GLOBALS[ 'wp_loupe_test_options' ][ WP_Loupe_Insights::OPTION ] = [ 'enabled' => true ];
			$insights->on_searched( 'me@example.com', 0, 'rest' );

			$GLOBALS[ 'wp_loupe_test_user_can' ] = true;
			$insights->on_searched( 'news', 3, 'main' );

			$this->assertSame( [], $this->db->queries );
		}

		public function test_report_sums_the_period_and_computes_the_average(): void {
			$this->db->row     = [ 'searches' => '8', 'zero_results' => '2', 'results_sum' => '30' ];
			$this->db->results = [
				[ [ 'search_query' => 'news', 'searches' => '5', 'zero_results' => '0', 'results_sum' => '30' ] ],
				[ [ 'search_query' => 'xyzzy', 'zero_results' => '2' ] ],
			];

			$report = WP_Loupe_Insights::report( 7 );

			$this->assertSame( 8, $report[ 'searches' ] );
			$this->assertSame( 2, $report[ 'zero_results' ] );
			$this->assertSame( 3.8, $report[ 'average_results' ] );
			$this->assertSame( 'news', $report[ 'top' ][ 0 ][ 'search_query' ] );
			$this->assertSame( 'xyzzy', $report[ 'zero' ][ 0 ][ 'search_query' ] );
			$this->assertStringContainsString( "day >= '2026-03-09'", $this->db->queries[ 0 ], 'Seven days including today.' );
			$this->assertStringContainsString( 'LIMIT 20', $this->db->queries[ 1 ] );
		}

		public function test_report_is_empty_before_the_table_exists(): void {
			unset( $GLOBALS[ 'wp_loupe_test_options' ][ WP_Loupe_Insights::DB_VERSION_OPTION ] );
			$this->assertSame( 0, WP_Loupe_Insights::report( 30 )[ 'searches' ] );
			$this->assertSame( [], $this->db->queries );
		}

		public function test_purge_deletes_days_older_than_the_retention_period(): void {
			$GLOBALS[ 'wp_loupe_test_options' ][ WP_Loupe_Insights::OPTION ] = [ 'enabled' => false, 'retention_days' => 30 ];
			WP_Loupe_Insights::purge();
			$this->assertSame( [ "DELETE FROM wp_loupe_search_insights WHERE day < '2026-02-13'" ], $this->db->queries );
		}

		public function test_retention_is_clamped(): void {
			$this->assertSame( 90, WP_Loupe_Insights::sanitize_retention( 0 ) );
			$this->assertSame( 90, WP_Loupe_Insights::sanitize_retention( 'abc' ) );
			$this->assertSame( 14, WP_Loupe_Insights::sanitize_retention( '14' ) );
			$this->assertSame( 365, WP_Loupe_Insights::sanitize_retention( 5000 ) );
		}

		public function test_settings_default_to_off_with_90_day_retention(): void {
			unset( $GLOBALS[ 'wp_loupe_test_options' ][ WP_Loupe_Insights::OPTION ] );
			$this->assertSame( [ 'enabled' => false, 'retention_days' => 90 ], WP_Loupe_Insights::settings() );
		}

		public function test_uninstall_drops_table_settings_and_cron(): void {
			$GLOBALS[ 'wp_loupe_test_cleared_hooks' ] = [];
			WP_Loupe_Insights::uninstall();
			$this->assertSame( [ 'DROP TABLE IF EXISTS wp_loupe_search_insights' ], $this->db->queries );
			$this->assertFalse( get_option( WP_Loupe_Insights::OPTION ) );
			$this->assertFalse( get_option( WP_Loupe_Insights::DB_VERSION_OPTION ) );
			$this->assertSame( [ WP_Loupe_Insights::CRON_HOOK ], $GLOBALS[ 'wp_loupe_test_cleared_hooks' ] );
		}

		public function test_register_listens_for_searches_and_the_purge_event(): void {
			( new WP_Loupe_Insights() )->register();
			$this->assertArrayHasKey( 'loupe_search_searched', $GLOBALS[ 'wp_loupe_test_hooks' ] );
			$this->assertArrayHasKey( WP_Loupe_Insights::CRON_HOOK, $GLOBALS[ 'wp_loupe_test_hooks' ] );
			$this->assertArrayNotHasKey( 'admin_post_loupe_search_insights_clear', $GLOBALS[ 'wp_loupe_test_hooks' ], 'Admin handlers only in admin.' );
		}
	}
}
