<?php
namespace Soderlind\Plugin\WPLoupe; // Match plugin namespace for class references.

use PHPUnit\Framework\TestCase;

/**
 * Test the /wp-loupe/v1/post-type-fields/{post_type} endpoint logic in isolation using shims.
 * We focus on:
 *  - Valid post type returns array structure (labels present)
 *  - Invalid post type returns WP_Error
 *  - Custom CPT (hlz_movie) recognized via bootstrap extension
 */
class WP_Loupe_REST_PostTypeFieldsTest extends TestCase {

	private function make_request( $params ) {
		return new class ($params) {
			private $p;
			public function __construct( $p ) {
				$this->p = $p;
			}
			public function get_param( $k ) {
				return $this->p[ $k ] ?? null;
			}
		};
	}

	public function test_post_type_fields_valid_core_type() {
		// Ensure option empty so REST class sets defaults.
		update_option( 'wp_loupe_custom_post_types', [] );
		$rest     = new WP_Loupe_REST();
		$req      = $this->make_request( [ 'post_type' => 'post' ] );
		$response = $rest->handle_post_type_fields_request( $req );
		$this->assertIsArray( $response );
		// Schema may be empty under lightweight bootstrap; just assert array shape (labels if present).
		foreach ( $response as $field => $info ) {
			$this->assertIsArray( $info );
			$this->assertArrayHasKey( 'label', $info );
		}
	}

	public function test_post_type_fields_invalid_type() {
		$rest   = new WP_Loupe_REST();
		$req    = $this->make_request( [ 'post_type' => 'no_such_type' ] );
		$result = $rest->handle_post_type_fields_request( $req );
		$this->assertInstanceOf( '\WP_Error', $result );
		$this->assertSame( 'wp_loupe_invalid_post_type', $result->get_error_code() );
	}

	protected function tearDown(): void {
		$GLOBALS[ 'wpdb' ]->col_results = [];
		parent::tearDown();
	}

	/**
	 * Meta keys beginning with "_" are protected in WordPress and must never be offered
	 * as indexable fields, so the discovery query filters them out with a prefix LIKE.
	 */
	public function test_post_type_fields_excludes_protected_meta_keys() {
		global $wpdb;
		update_option( 'wp_loupe_custom_post_types', [] );
		$wpdb->col_results = [ 'rating', 'runtime' ];

		$rest     = new WP_Loupe_REST();
		$response = $rest->handle_post_type_fields_request( $this->make_request( [ 'post_type' => 'post' ] ) );

		$this->assertSame(
			'\\_%',
			$wpdb->last_prepare_args[ 1 ],
			'Protected meta must be excluded by an escaped leading underscore followed by a wildcard'
		);

		$this->assertArrayHasKey( 'rating', $response, 'Discovered meta keys must reach the response' );
		$this->assertArrayHasKey( 'runtime', $response );
	}

	public function test_post_type_fields_custom_cpt_hlz_movie() {
		// Simulate settings option including hlz_movie so REST picks it up.
		update_option( 'wp_loupe_custom_post_types', [ 'wp_loupe_post_type_field' => [ 'hlz_movie' ] ] );
		$rest     = new WP_Loupe_REST();
		$req      = $this->make_request( [ 'post_type' => 'hlz_movie' ] );
		$response = $rest->handle_post_type_fields_request( $req );
		// In shim environment taxonomy/meta discovery will be empty; still expect an array.
		$this->assertIsArray( $response );
		// Should at least contain schema-driven fields; verify label structure shape.
		foreach ( $response as $field => $info ) {
			$this->assertIsArray( $info );
			$this->assertArrayHasKey( 'label', $info );
		}
	}
}
