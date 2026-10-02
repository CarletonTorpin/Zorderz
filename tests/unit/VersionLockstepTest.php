<?php
/**
 * The theme and the apps bundle ship in lockstep, and four hand-maintained copies of the
 * version have drifted before (1.7.2 fixed the apps marker; the theme floor then lagged at
 * 1.10.0 through 1.10.1, re-creating the permanent "App update ready" toast). Pin them all.
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

final class VersionLockstepTest extends TestCase {

	private static function grab( string $file, string $re ): string {
		$src = (string) file_get_contents( dirname( __DIR__, 2 ) . '/' . $file );
		return preg_match( $re, $src, $m ) ? $m[1] : '';
	}

	public function test_all_version_copies_agree(): void {
		$theme  = self::grab( 'zorderz/style.css', '/^Version:\s*(\S+)/m' );
		$floor  = self::grab( 'zorderz/functions.php', "/define\\(\\s*'ZDZ_THEME_VER_FLOOR',\\s*'([^']+)'/" );
		$apps   = self::grab( 'zorderz-apps/zorderz-apps.php', '/^\s*\*\s*Version:\s*(\S+)/m' );
		$appsfb = self::grab( 'zorderz-apps/zorderz-apps.php', "/\\\$zdz_apps_ver\\s*=\\s*'([^']+)'/" );

		$this->assertMatchesRegularExpression( '/^\d+\.\d+\.\d+$/', $theme );
		$this->assertSame( $theme, $floor, 'ZDZ_THEME_VER_FLOOR must match style.css' );
		$this->assertSame( $theme, $apps, 'the apps bundle header must match the theme' );
		$this->assertSame( $theme, $appsfb, 'the apps version fallback literal must match the header' );
	}

	public function test_changelog_has_an_entry_for_this_version(): void {
		$theme = self::grab( 'zorderz/style.css', '/^Version:\s*(\S+)/m' );
		$log   = (string) file_get_contents( dirname( __DIR__, 2 ) . '/CHANGELOG.md' );
		$this->assertStringContainsString( '## [' . $theme . ']', $log );
	}
}
