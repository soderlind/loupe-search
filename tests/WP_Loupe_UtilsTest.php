<?php
namespace Soderlind\Plugin\WPLoupe\Tests;

use PHPUnit\Framework\TestCase;
use Soderlind\Plugin\WPLoupe\WP_Loupe_Utils;

class WP_Loupe_UtilsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		delete_option( 'loupe_search_custom_post_types' );
		$GLOBALS[ 'wp_loupe_test_filters' ]                = [];
		$GLOBALS[ 'wp_loupe_test_non_public_post_types' ] = [];
	}

	protected function tearDown(): void {
		delete_option( 'loupe_search_custom_post_types' );
		$GLOBALS[ 'wp_loupe_test_filters' ]                = [];
		$GLOBALS[ 'wp_loupe_test_non_public_post_types' ] = [];
		parent::tearDown();
	}

	public function test_get_indexed_post_types_defaults_to_post_and_page(): void {
		$this->assertSame( [ 'post', 'page' ], WP_Loupe_Utils::get_indexed_post_types() );
	}

	public function test_get_indexed_post_types_reads_the_settings_option(): void {
		update_option( 'loupe_search_custom_post_types', [ 'loupe_search_post_type_field' => [ 'book', 'movie' ] ] );

		$this->assertSame( [ 'book', 'movie' ], WP_Loupe_Utils::get_indexed_post_types() );
	}

	public function test_get_indexed_post_types_applies_the_filter(): void {
		update_option( 'loupe_search_custom_post_types', [ 'loupe_search_post_type_field' => [ 'post' ] ] );

		$GLOBALS[ 'wp_loupe_test_filters' ][ 'loupe_search_post_types' ] = function ( $post_types ) {
			return array_merge( (array) $post_types, [ 'book' ] );
		};

		$this->assertSame( [ 'post', 'book' ], WP_Loupe_Utils::get_indexed_post_types() );
	}

	public function test_get_public_indexed_post_types_drops_non_public_types(): void {
		update_option( 'loupe_search_custom_post_types', [ 'loupe_search_post_type_field' => [ 'post', 'secret' ] ] );
		$GLOBALS[ 'wp_loupe_test_non_public_post_types' ] = [ 'secret' ];

		$this->assertSame(
			[ 'post' ],
			WP_Loupe_Utils::get_public_indexed_post_types(),
			'unauthenticated endpoints must never search a non-public post type'
		);
	}
}
