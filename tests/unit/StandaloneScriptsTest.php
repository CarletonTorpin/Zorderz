<?php
/**
 * Runs the repository's standalone test scripts under PHPUnit, so CI executes them.
 *
 * Several security-relevant tests are plain PHP scripts with their own small harness
 * (the contact-card disclosure gate, the shared name matcher, the Prep de-dup gates, the
 * Ai core services, the Dot Plot report sources, the Jobs geo worklist). PHPUnit only
 * discovers *Test.php classes, so before 1.10.2 none of them ran in CI. Each script runs in
 * its own PHP process (they define global stubs) and must exit 0.
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

final class StandaloneScriptsTest extends TestCase {

	public function scripts(): array {
		$root  = dirname( __DIR__, 2 );
		$paths = array_merge(
			glob( $root . '/tests/unit/test-*.php' ),
			glob( $root . '/zorderz-apps/apps/*/tests/*.php' )
		);
		sort( $paths );
		$out = array();
		foreach ( $paths as $p ) {
			$out[ substr( $p, strlen( $root ) + 1 ) ] = array( $p );
		}
		return $out;
	}

	/**
	 * @dataProvider scripts
	 */
	public function test_script_passes( string $path ): void {
		$cmd = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $path ) . ' 2>&1';
		$out = array();
		$rc  = 0;
		exec( $cmd, $out, $rc ); // sentinel:allow every argument is escapeshellarg()'d; runs repo test scripts only
		$this->assertSame( 0, $rc, basename( $path ) . " failed:\n" . implode( "\n", array_slice( $out, -25 ) ) );
	}

	public function test_scripts_are_found(): void {
		$this->assertGreaterThanOrEqual( 7, count( $this->scripts() ) );
	}
}
