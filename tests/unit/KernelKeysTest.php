<?php
/**
 * Kernel key hygiene:
 *   - ZDZ_Salt_Watch records fingerprints (never salts) on its first run and names a changed salt after;
 *   - ZDZ_Cred_Expiry keeps dates only, accepts only real dates, and lists what is due.
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'update_option' ) ) {
	function update_option( $k, $v, $autoload = null ) { $GLOBALS['zdz_test_options'][ $k ] = $v; return true; }
}
if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id() { return (int) ( $GLOBALS['zdz_test_current_user'] ?? 0 ); }
}
if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $k ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $k ) ); }
}

require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-salt-watch.php';
require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-cred-expiry.php';

final class KernelKeysTest extends TestCase {

	protected function tearDown(): void {
		unset( $GLOBALS['zdz_test_options'], $GLOBALS['zdz_test_filters'] );
	}

	public function test_salt_watch_keeps_fingerprints_never_salts_and_names_a_change(): void {
		$this->assertSame( array(), ZDZ_Salt_Watch::changed(), 'the first run records and reports nothing' );
		$stored = $GLOBALS['zdz_test_options'][ ZDZ_Salt_Watch::OPT ];
		foreach ( $stored['fp'] as $scheme => $fp ) {
			$this->assertSame( 12, strlen( $fp ) );
			$this->assertStringNotContainsString( 'salt', $fp );
		}
		$GLOBALS['zdz_test_options'][ ZDZ_Salt_Watch::OPT ]['fp']['auth'] = 'aaaaaaaaaaaa';
		$this->assertSame( array( 'auth' ), ZDZ_Salt_Watch::changed() );
		$lines = implode( ' ', ZDZ_Salt_Watch::breaks( array( 'auth' ) ) );
		$this->assertStringContainsString( 'Stored credentials', $lines );
	}

	public function test_expiry_keeps_real_dates_only_and_lists_what_is_due(): void {
		$this->assertTrue( ZDZ_Cred_Expiry::valid( '2027-02-28' ) );
		$this->assertFalse( ZDZ_Cred_Expiry::valid( '2027-02-30' ) );
		$this->assertFalse( ZDZ_Cred_Expiry::valid( 'sk-live-not-a-date' ) );
		$soon = gmdate( 'Y-m-d', strtotime( '+10 days' ) );
		$far  = gmdate( 'Y-m-d', strtotime( '+200 days' ) );
		$GLOBALS['zdz_test_options'][ ZDZ_Cred_Expiry::OPT ] = array( 'poe' => $soon, 'nutshell' => $far, 'freshbooks' => 'nonsense' );
		$due = ZDZ_Cred_Expiry::due();
		$this->assertCount( 1, $due );
		$this->assertSame( 'poe', $due[0][0] );
		$GLOBALS['zdz_test_filters']['zdz_cred_expiry_rows'] = function () { return array( 'Bad Row!' => 'An app key' ); };
		$this->assertArrayHasKey( 'badrow', ZDZ_Cred_Expiry::rows() );
	}
}
