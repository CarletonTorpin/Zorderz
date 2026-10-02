<?php
/**
 * Every admin-ajax action a Zorderz script posts must be registered in PHP.
 *
 * 1.10.1 shipped Sketch Pad, Camera and Media dead: their PHP still registered the
 * pre-rename action names while the JavaScript (and the theme service worker) posted the
 * renamed ones, so every call answered admin-ajax's "0". This scans each app's scripts for
 * the action names they post and requires the app's PHP, or the theme's, to register them.
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

final class AjaxActionContractTest extends TestCase {

	private const ACTION_RE = '/(?:append\(\s*[\'"]action[\'"]\s*,\s*|(?<![.\w])action\s*:\s*|ajaxPost\(\s*|ajax\(\s*|post\(\s*)[\'"]([a-z][a-z0-9_]{3,})[\'"]/';

	private static function files( string $dir, string $ext ): array {
		$out = array();
		$it  = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $f ) {
			$p = $f->getPathname();
			if ( substr( $p, -strlen( $ext ) ) === $ext && false === strpos( $p, '/vendor/' ) ) {
				$out[] = $p;
			}
		}
		sort( $out );
		return $out;
	}

	private static function concat( array $paths ): string {
		return implode( "\n", array_map( 'file_get_contents', $paths ) );
	}

	/**
	 * True when $php registers $action for logged-in users: a literal `wp_ajax_<action>` hook,
	 * or a key of a dispatch table ('<action>' => handler) in code that registers
	 * 'wp_ajax_' . $key dynamically. A bare mention of the name is not enough: in 1.10.1 Media
	 * named its actions in the nopriv denial loop while the logged-in hooks used old names.
	 */
	private static function registered( string $action, string $php ): bool {
		if ( false !== strpos( $php, "'wp_ajax_" . $action . "'" ) || false !== strpos( $php, '"wp_ajax_' . $action . '"' ) ) {
			return true;
		}
		$dynamic = (bool) preg_match( "/add_action\(\s*'wp_ajax_'\s*\./", $php );
		return $dynamic && (bool) preg_match( "/['\"]" . preg_quote( $action, '/' ) . "['\"]\s*=>/", $php );
	}

	public function test_every_posted_action_has_a_hook(): void {
		$root      = dirname( __DIR__, 2 );
		$theme_php = self::concat( self::files( $root . '/zorderz', '.php' ) );
		$missing   = array();
		$checked   = 0;
		foreach ( glob( $root . '/zorderz-apps/apps/*', GLOB_ONLYDIR ) as $app ) {
			preg_match_all( self::ACTION_RE, self::concat( self::files( $app, '.js' ) ), $m );
			$php = self::concat( self::files( $app, '.php' ) ) . "\n" . $theme_php;
			foreach ( array_unique( $m[1] ) as $action ) {
				$checked++;
				if ( ! self::registered( $action, $php ) ) {
					$missing[] = basename( $app ) . ': ' . $action;
				}
			}
		}
		$this->assertGreaterThan( 50, $checked, 'the scan found too few actions; the pattern has drifted' );
		$this->assertSame( array(), $missing, 'actions posted by JS with no PHP hook' );
	}

	/** The theme service worker drains the Camera upload queue; its action must exist too. */
	public function test_service_worker_actions_are_registered(): void {
		$root = dirname( __DIR__, 2 );
		preg_match_all( self::ACTION_RE, (string) file_get_contents( $root . '/zorderz/sw.js' ), $m );
		$php = self::concat( self::files( $root . '/zorderz-apps', '.php' ) ) . self::concat( self::files( $root . '/zorderz', '.php' ) );
		$this->assertNotEmpty( $m[1] );
		foreach ( array_unique( $m[1] ) as $action ) {
			$this->assertTrue( false !== strpos( $php, 'wp_ajax_' . $action ), 'service worker posts ' . $action . ' but no hook is registered' );
		}
	}
}
