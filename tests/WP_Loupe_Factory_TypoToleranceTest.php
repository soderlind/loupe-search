<?php
namespace Soderlind\Plugin\LoupeSearch\Tests;

use PHPUnit\Framework\TestCase;
use Soderlind\Plugin\LoupeSearch\WP_Loupe_Factory;

class WP_Loupe_Factory_TypoToleranceTest extends TestCase {
	/**
	 * @param array<string,mixed> $settings
	 */
	private function is_disabled( array $settings ): bool {
		$method = new \ReflectionMethod( WP_Loupe_Factory::class, 'configure_typo_tolerance' );
		$method->setAccessible( true );
		return $method->invoke( null, $settings )->isDisabled();
	}

	public function test_unsaved_settings_default_to_enabled(): void {
		$this->assertFalse( $this->is_disabled( [] ) );
	}

	public function test_saved_unchecked_disables_typo_tolerance(): void {
		$this->assertTrue( $this->is_disabled( [ 'typo_enabled' => false ] ) );
	}

	public function test_saved_checked_enables_typo_tolerance(): void {
		$this->assertFalse( $this->is_disabled( [ 'typo_enabled' => true ] ) );
	}
}
