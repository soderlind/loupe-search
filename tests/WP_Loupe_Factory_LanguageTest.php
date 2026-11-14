<?php
namespace Soderlind\Plugin\LoupeSearch\Tests;

use PHPUnit\Framework\TestCase;
use Soderlind\Plugin\LoupeSearch\WP_Loupe_Factory;

class WP_Loupe_Factory_LanguageTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		update_option( 'loupe_search_advanced', [] );
	}

	/**
	 * @return array<int,string>
	 */
	private function languages_for( string $lang ): array {
		$attributes = [ 'indexable' => [ 'post_title' ], 'filterable' => [], 'sortable' => [] ];
		$method     = new \ReflectionMethod( WP_Loupe_Factory::class, 'build_configuration' );
		$method->setAccessible( true );
		return $method->invoke( null, $attributes, $lang )->getLanguages();
	}

	public function test_locale_language_is_applied_to_configuration(): void {
		$this->assertSame( [ 'de' ], $this->languages_for( 'de' ) );
	}

	public function test_defaults_to_english(): void {
		$this->assertSame( [ 'en' ], $this->languages_for( '' ) );
	}

	public function test_explicit_languages_setting_wins(): void {
		update_option( 'loupe_search_advanced', [ 'languages' => [ 'fr' ] ] );
		$this->assertSame( [ 'fr' ], $this->languages_for( 'de' ) );
	}

	public function test_explicit_languages_setting_is_resolved(): void {
		update_option( 'loupe_search_advanced', [ 'languages' => [ 'nb', 'nn', 'en' ] ] );
		$this->assertSame( [ 'en', 'no' ], $this->languages_for( 'de' ) );
	}

	/**
	 * @return array<string,array{string,string}>
	 */
	public static function locale_provider(): array {
		return [
			'Norwegian Bokmål'   => [ 'nb_NO', 'no' ],
			'Norwegian Nynorsk'  => [ 'nn_NO', 'no' ],
			'regional variant'   => [ 'pt_BR', 'pt' ],
			'formal variant'     => [ 'de_CH_informal', 'de' ],
			'no stemmer, 3 char' => [ 'fil', 'fil' ],
			'no stemmer, region' => [ 'zh_CN', 'zh' ],
			'empty'              => [ '', 'en' ],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'locale_provider' )]
	public function test_resolve_language( string $locale, string $expected ): void {
		$this->assertSame( $expected, WP_Loupe_Factory::resolve_language( $locale ) );
	}
}
