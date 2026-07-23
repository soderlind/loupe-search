<?php
namespace Soderlind\Plugin\WPLoupe; // Match plugin namespace for class references.

use PHPUnit\Framework\TestCase;

/**
 * Test the WordPress Abilities API integration (WP_Loupe_Abilities).
 *
 * Focuses on the execute_get_post() callback logic in isolation using shims:
 *  - Missing/invalid ID returns a WP_Error.
 *  - A valid published post returns the documented output shape.
 */
class WP_Loupe_AbilitiesTest extends TestCase {

	public function test_execute_get_post_missing_id_returns_error() {
		$result = WP_Loupe_Abilities::execute_get_post( [] );
		$this->assertInstanceOf( '\WP_Error', $result );
		$this->assertSame( 'invalid_id', $result->get_error_code() );
	}

	public function test_execute_get_post_zero_id_returns_error() {
		$result = WP_Loupe_Abilities::execute_get_post( [ 'id' => 0 ] );
		$this->assertInstanceOf( '\WP_Error', $result );
		$this->assertSame( 'invalid_id', $result->get_error_code() );
	}

	public function test_execute_get_post_valid_returns_expected_shape() {
		$result = WP_Loupe_Abilities::execute_get_post( [ 'id' => 42 ] );

		$this->assertIsArray( $result );
		foreach ( [ 'id', 'title', 'content', 'excerpt', 'url', 'post_type', 'post_date', 'author' ] as $key ) {
			$this->assertArrayHasKey( $key, $result );
		}
		$this->assertSame( 42, $result['id'] );
		$this->assertSame( 'Title 42', $result['title'] );
		$this->assertStringContainsString( '/post/42', $result['url'] );
	}
}
