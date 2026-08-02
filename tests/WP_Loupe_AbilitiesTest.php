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

	public function test_registers_primary_and_deprecated_alias_abilities() {
		$GLOBALS[ '__wp_loupe_registered_abilities' ]           = [];
		$GLOBALS[ '__wp_loupe_registered_ability_categories' ] = [];

		WP_Loupe_Abilities::register_category();
		WP_Loupe_Abilities::register_abilities();

		$abilities  = $GLOBALS[ '__wp_loupe_registered_abilities' ];
		$categories = $GLOBALS[ '__wp_loupe_registered_ability_categories' ];

		// Primary names are registered.
		$this->assertArrayHasKey( 'loupe-search/search', $abilities );
		$this->assertArrayHasKey( 'loupe-search/get-post', $abilities );
		// Deprecated aliases remain registered (non-breaking).
		$this->assertArrayHasKey( 'wp-loupe/search', $abilities );
		$this->assertArrayHasKey( 'wp-loupe/get-post', $abilities );

		// Categories match the ability namespaces.
		$this->assertSame( 'loupe-search', $abilities[ 'loupe-search/search' ][ 'category' ] );
		$this->assertSame( 'wp-loupe', $abilities[ 'wp-loupe/search' ][ 'category' ] );
		$this->assertArrayHasKey( 'loupe-search', $categories );
		$this->assertArrayHasKey( 'wp-loupe', $categories );

		// Alias delegates to the same execute callback as the primary ability.
		$this->assertSame(
			$abilities[ 'loupe-search/search' ][ 'execute_callback' ],
			$abilities[ 'wp-loupe/search' ][ 'execute_callback' ]
		);
		$this->assertSame(
			$abilities[ 'loupe-search/get-post' ][ 'execute_callback' ],
			$abilities[ 'wp-loupe/get-post' ][ 'execute_callback' ]
		);
	}

	/**
	 * Both abilities are documented as publicly accessible and REST-exposed.
	 *
	 * @see docs/architecture.md, docs/migration-mcp-to-abilities.md, README.md
	 */
	public function test_abilities_are_public_and_rest_exposed() {
		$GLOBALS[ '__wp_loupe_registered_abilities' ]          = [];
		$GLOBALS[ '__wp_loupe_registered_ability_categories' ] = [];

		WP_Loupe_Abilities::register_category();
		WP_Loupe_Abilities::register_abilities();

		$abilities = $GLOBALS[ '__wp_loupe_registered_abilities' ];

		foreach ( [ 'loupe-search/search', 'loupe-search/get-post', 'wp-loupe/search', 'wp-loupe/get-post' ] as $name ) {
			$this->assertArrayHasKey( 'permission_callback', $abilities[ $name ], "{$name} must declare a permission callback" );
			$this->assertSame( '__return_true', $abilities[ $name ][ 'permission_callback' ], "{$name} is documented as unauthenticated" );

			$this->assertArrayHasKey( 'meta', $abilities[ $name ], "{$name} must declare meta" );
			$this->assertSame( [ 'show_in_rest' => true ], $abilities[ $name ][ 'meta' ], "{$name} is documented as exposed via REST" );
		}
	}
}
