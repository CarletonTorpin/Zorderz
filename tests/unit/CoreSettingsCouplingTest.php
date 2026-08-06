<?php
/**
 * Pins the coupling the export secret exclusion depends on: ZDZ_Core_Settings::secret_fields()
 * is the single source of truth for which core options are credentials, and the exporter reads
 * it. This test guards two things a refactor could quietly break:
 *
 *   1. secret_fields() stays PUBLIC (the exporter calls it from another class);
 *   2. review_bridge_key stays IN the list (removing it silently re-opens the export leak).
 *
 * It inspects the shipping source file rather than executing it, so it needs no WordPress.
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

final class CoreSettingsCouplingTest extends TestCase {

	private function source(): string {
		$path = dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-core-settings.php';
		$this->assertFileExists( $path );
		return (string) file_get_contents( $path );
	}

	public function test_secret_fields_is_public(): void {
		$src = $this->source();
		$this->assertMatchesRegularExpression(
			'/public\s+static\s+function\s+secret_fields\s*\(/',
			$src,
			'ZDZ_Core_Settings::secret_fields() must be public so the exporter can use it as the source of truth.'
		);
	}

	public function test_review_bridge_key_is_declared_secret(): void {
		$src = $this->source();
		$this->assertStringContainsString(
			'review_bridge_key',
			$src,
			'review_bridge_key must remain declared as a secret field (its omission was the Aug 2026 export leak).'
		);
	}
}
