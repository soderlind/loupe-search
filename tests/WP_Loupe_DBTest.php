<?php
namespace Soderlind\Plugin\LoupeSearch\Tests;

use PHPUnit\Framework\TestCase;
use Soderlind\Plugin\LoupeSearch\WP_Loupe_DB;

class WP_Loupe_DBTest extends TestCase {
	public function test_get_base_path_writes_protection_files(): void {
		global $wp_loupe_test_filters;
		$dir = sys_get_temp_dir() . '/loupe-db-test-' . uniqid();

		$wp_loupe_test_filters[ 'loupe_search_db_path' ] = static fn() => $dir;

		$base = WP_Loupe_DB::get_instance()->get_base_path();
		$this->assertSame( $dir, $base );

		foreach ( [ '.htaccess', 'web.config', 'index.php' ] as $file ) {
			$this->assertFileExists( $base . '/' . $file );
		}
		$this->assertStringContainsString( 'Require all denied', (string) file_get_contents( $base . '/.htaccess' ) );
		$this->assertStringContainsString( 'deny users="*"', (string) file_get_contents( $base . '/web.config' ) );

		foreach ( [ '.htaccess', 'web.config', 'index.php' ] as $file ) {
			@unlink( $base . '/' . $file );
		}
		@rmdir( $base );
		unset( $wp_loupe_test_filters[ 'loupe_search_db_path' ] );
	}
}
