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
	 * Stop words come from the bundled list for each index language and can be filtered.
	 */
	public function test_stop_words_for_languages(): void {
		global $wp_loupe_test_filters;
		$words = WP_Loupe_Factory::get_stop_words( [ 'no', 'en' ] );
		$this->assertContains( 'er', $words );
		$this->assertContains( 'the', $words );
		$this->assertContains( 'på', $words );
		$this->assertContains( 'pa', $words );
		$this->assertSame( [], WP_Loupe_Factory::get_stop_words( [ 'xx', '../en' ] ) );

		$wp_loupe_test_filters[ 'loupe_search_stop_words' ] = static fn( array $w ): array => [ ' Treg ', 'treg', '' ];
		try {
			$this->assertSame( [ 'treg' ], WP_Loupe_Factory::get_stop_words( [ 'no' ] ) );
		} finally {
			unset( $wp_loupe_test_filters[ 'loupe_search_stop_words' ] );
		}
	}

	/**
	 * Stop words are dropped from queries, so common words no longer match on their own.
	 */
	public function test_stop_words_are_ignored_in_queries(): void {
		$path  = sys_get_temp_dir() . '/loupe-search-stop-words-' . uniqid();
		$loupe = WP_Loupe_Factory::create_loupe_instance_at( 'post', 'no', $path );
		$loupe->addDocuments( [
			[ 'id' => 1, 'post_title' => 'Treg pc' ],
			[ 'id' => 2, 'post_title' => 'Det er en fin dag' ],
			[ 'id' => 3, 'post_title' => 'Ut på tur' ],
		] );

		$ids = static fn( string $q, string $strategy ): array => array_map(
			'intval',
			array_column( $loupe->search( SearchParameters::create()->withQuery( $q )->withMatchingStrategy( $strategy ) )->toArray()[ 'hits' ], 'id' )
		);

		try {
			$this->assertSame( [ 1 ], $ids( 'pc-en er treg', 'any' ) );
			$this->assertSame( [ 1 ], $ids( 'pc-en er treg', 'all' ) );
			// Only stop words: searched as typed.
			$this->assertSame( [ 2 ], $ids( 'er en', 'all' ) );
			// Stop words with diacritics are matched after Loupe folds them to ASCII.
			$this->assertSame( [ 1 ], $ids( 'treg på pc', 'all' ) );
		} finally {
			unset( $loupe );
			exec( 'rm -rf ' . escapeshellarg( $path ) );
		}
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
