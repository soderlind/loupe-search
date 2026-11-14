<?php
namespace Soderlind\Plugin\LoupeSearch\Tests;

use PHPUnit\Framework\TestCase;
use Soderlind\Plugin\LoupeSearch\Vendor\Loupe\Loupe\SearchParameters;
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

	/**
	 * Query terms are stemmed like document terms (patches/loupe/loupe/stem-query-terms.patch),
	 * so inflected forms find each other and the exact form ranks first.
	 */
	public function test_query_terms_are_stemmed(): void {
		$path  = sys_get_temp_dir() . '/loupe-search-query-stemming-' . uniqid();
		$loupe = WP_Loupe_Factory::create_loupe_instance_at( 'post', 'no', $path );
		$loupe->addDocuments( [
			[ 'id' => 1, 'post_title' => 'Plagene kom tilbake' ],
			[ 'id' => 2, 'post_title' => 'Plager og smerter' ],
		] );

		$ids = static fn( string $q ): array => array_map(
			'intval',
			array_column( $loupe->search( SearchParameters::create()->withQuery( $q ) )->toArray()[ 'hits' ], 'id' )
		);

		try {
			$this->assertSame( [ 2, 1 ], $ids( 'plager' ) );
			$this->assertSame( [ 1, 2 ], $ids( 'plagene' ) );
		} finally {
			unset( $loupe );
			exec( 'rm -rf ' . escapeshellarg( $path ) );
		}
	}
}
