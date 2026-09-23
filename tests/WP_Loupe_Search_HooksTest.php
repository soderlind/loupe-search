<?php
namespace Soderlind\Plugin\LoupeSearch\Tests;

use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Soderlind\Plugin\LoupeSearch\WP_Loupe_Search_Engine;
use Soderlind\Plugin\LoupeSearch\WP_Loupe_Search_Hooks;

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

		Functions\expect( 'add_filter' )
			->once()
			->with( 'the_title', [ $hooks, 'highlight_title' ], 10, 2 );

		Functions\expect( 'add_filter' )
			->once()
			->with( 'get_the_excerpt', [ $hooks, 'highlight_excerpt' ], 10, 2 );

		Functions\expect( 'add_filter' )
			->once()
			->with( 'render_block_core/post-excerpt', [ $hooks, 'highlight_excerpt_block' ], 10, 3 );

		Functions\expect( 'add_filter' )
			->once()
			->with( 'render_block_core/post-content', [ $hooks, 'highlight_content_block' ], 10, 3 );

		Functions\expect( 'add_action' )
			->once()
			->with( 'wp_footer', [ $hooks, 'action_wp_footer' ], 999 );

		$hooks->register();
		$this->assertTrue( true );
	}

	public function test_highlight_content_block_replaces_wrapper_body(): void {
		// is_admin() defaults to false and wp_kses() are provided by the bootstrap shims;
		// only the search context needs forcing on.
		Functions\when( 'is_search' )->justReturn( true );

		$engine = $this->getMockBuilder( WP_Loupe_Search_Engine::class )
			->disableOriginalConstructor()
			->getMock();
		$engine->method( 'get_post_types' )->willReturn( [ 'post' ] );

		$hooks = new WP_Loupe_Search_Hooks( $engine );

		// Seed the id-keyed formatted map the render filters read.
		$prop = new \ReflectionProperty( $hooks, 'formatted_by_id' );
		$prop->setAccessible( true );
		$prop->setValue( $hooks, [ 42 => [ 'post_content' => 'hello <mark>world</mark>' ] ] );

		$block          = new \WP_Block();
		$block->context = [ 'postId' => 42 ];

		$content = '<div class="entry-content wp-block-post-content"><p>plain <a href="#">x</a></p><div>nested</div></div>';
		$out     = $hooks->highlight_content_block( $content, [], $block );

		$this->assertSame(
			'<div class="entry-content wp-block-post-content">hello <mark>world</mark></div>',
			$out
		);
	}

	public function test_highlight_content_block_noop_without_formatted(): void {
		Functions\when( 'is_search' )->justReturn( true );

		$engine = $this->getMockBuilder( WP_Loupe_Search_Engine::class )
			->disableOriginalConstructor()
			->getMock();
		$engine->method( 'get_post_types' )->willReturn( [ 'post' ] );

		$hooks = new WP_Loupe_Search_Hooks( $engine );

		$block          = new \WP_Block();
		$block->context = [ 'postId' => 7 ];

		$content = '<div class="wp-block-post-content"><p>unchanged</p></div>';
		$this->assertSame( $content, $hooks->highlight_content_block( $content, [], $block ) );
	}
}
