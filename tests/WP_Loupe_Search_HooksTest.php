<?php
namespace Soderlind\Plugin\WPLoupe\Tests;

use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Soderlind\Plugin\WPLoupe\WP_Loupe_Search_Engine;
use Soderlind\Plugin\WPLoupe\WP_Loupe_Search_Hooks;

class WP_Loupe_Search_HooksTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		\Brain\Monkey\setUp();
	}

	protected function tearDown(): void {
		\Brain\Monkey\tearDown();
		parent::tearDown();
	}

	public function test_register_adds_expected_hooks(): void {
		$engine = $this->getMockBuilder( WP_Loupe_Search_Engine::class )
			->disableOriginalConstructor()
			->getMock();
		$engine->method( 'get_post_types' )->willReturn( [ 'post', 'page' ] );

		$hooks = new WP_Loupe_Search_Hooks( $engine );

		Functions\expect( 'add_filter' )
			->once()
			->with( 'posts_pre_query', [ $hooks, 'posts_pre_query' ], 10, 2 );

		Functions\expect( 'add_action' )
			->once()
			->with( 'wp_footer', [ $hooks, 'action_wp_footer' ], 999 );

		$hooks->register();
		$this->assertTrue( true );
	}
}
