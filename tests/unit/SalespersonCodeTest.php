<?php
/**
 * One person's salesperson code, read the same way everywhere (ZDZ_Salesperson_Code).
 *
 *   - the profile's code first, then the party code; either screen's code is seen;
 *   - an upgraded install's older key is read only while the current key is empty;
 *   - saving the profile field removes the older copy, so a cleared code never comes back;
 *   - the theme's readers (ZDZ_Core_Settings, the contact bridge) ask this class.
 *
 * Neutral data only.
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'get_user_meta' ) ) {
	function get_user_meta( $uid, $key, $single = false ) { return $GLOBALS['zdz_test_user_meta'][ (int) $uid ][ $key ] ?? ''; }
}
if ( ! function_exists( 'update_user_meta' ) ) {
	function update_user_meta( $uid, $key, $v ) { $GLOBALS['zdz_test_user_meta'][ (int) $uid ][ $key ] = $v; return true; }
}

require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-salesperson-code.php';

final class SalespersonCodeTest extends TestCase {

	private const UID = 4101;

	protected function setUp(): void {
		unset( $GLOBALS['zdz_test_user_meta'][ self::UID ] );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['zdz_test_user_meta'][ self::UID ] );
	}

	private function meta( array $m ): void {
		$GLOBALS['zdz_test_user_meta'][ self::UID ] = $m;
	}

	public function test_the_profile_code_then_the_party_code(): void {
		$this->meta( array( ZDZ_Salesperson_Code::PROFILE_KEY => 'AB', ZDZ_Salesperson_Code::PARTY_KEY => 'CD' ) );
		$this->assertSame( 'AB', ZDZ_Salesperson_Code::get( self::UID ) );
		$this->assertSame( 'CD', ZDZ_Salesperson_Code::party( self::UID ), 'the party code reads its own key first' );
		$this->meta( array( ZDZ_Salesperson_Code::PARTY_KEY => 'CD' ) );
		$this->assertSame( 'CD', ZDZ_Salesperson_Code::get( self::UID ), 'a code set only by the commission plan is still the person\'s code' );
	}

	public function test_a_migrated_install_reads_the_current_key(): void {
		// After the rename migration copies the code and the older key is cleaned up, only the current key is left.
		$this->meta( array( ZDZ_Salesperson_Code::PROFILE_KEY => 'AB' ) );
		$this->assertSame( 'AB', ZDZ_Salesperson_Code::get( self::UID ) );
	}

	public function test_the_older_key_only_while_the_current_one_is_empty(): void {
		$this->meta( array( ZDZ_Salesperson_Code::OLD_PROFILE_KEY => 'AB' ) );
		$this->assertSame( 'AB', ZDZ_Salesperson_Code::get( self::UID ), 'not migrated yet: the older key is read' );
		$this->meta( array( ZDZ_Salesperson_Code::OLD_PROFILE_KEY => 'AB', ZDZ_Salesperson_Code::PROFILE_KEY => 'EF' ) );
		$this->assertSame( 'EF', ZDZ_Salesperson_Code::get( self::UID ), 'the current key wins over an older copy' );
		$this->meta( array( ZDZ_Salesperson_Code::OLD_PARTY_KEY => 'GH' ) );
		$this->assertSame( 'GH', ZDZ_Salesperson_Code::party( self::UID ) );
		$this->assertSame( 'GH', ZDZ_Salesperson_Code::get( self::UID ) );
	}

	public function test_saving_the_profile_removes_the_older_copy(): void {
		$this->meta( array( ZDZ_Salesperson_Code::OLD_PROFILE_KEY => 'AB' ) );
		ZDZ_Salesperson_Code::save_profile( self::UID, '' );
		$this->assertSame( '', ZDZ_Salesperson_Code::get( self::UID ), 'a cleared code does not come back from the older key' );
		ZDZ_Salesperson_Code::save_profile( self::UID, '  JK-LONGER-THAN-TEN  ' );
		$this->assertSame( 'JK-LONGER-', ZDZ_Salesperson_Code::profile( self::UID ), 'kept as typed, at most ten characters' );
		$this->assertArrayNotHasKey( ZDZ_Salesperson_Code::OLD_PROFILE_KEY, $GLOBALS['zdz_test_user_meta'][ self::UID ] );
	}

	public function test_only_short_text_is_a_code(): void {
		$this->assertSame( '', ZDZ_Salesperson_Code::clean( array( 'AB' ) ) );
		$this->assertSame( '', ZDZ_Salesperson_Code::clean( "A\nB" ) );
		$this->assertSame( '', ZDZ_Salesperson_Code::clean( str_repeat( 'A', 65 ) ) );
		$this->assertSame( 'AB', ZDZ_Salesperson_Code::clean( ' AB ' ) );
		$this->assertSame( '', ZDZ_Salesperson_Code::get( 0 ), 'nobody signed in has no code' );
	}

	public function test_the_theme_readers_ask_this_class(): void {
		$root     = dirname( __DIR__, 2 ) . '/zorderz/';
		$settings = (string) file_get_contents( $root . 'inc/class-zdz-core-settings.php' );
		$bridge   = (string) file_get_contents( $root . 'inc/class-zdz-contact-bridge.php' );
		$ui       = (string) file_get_contents( $root . 'inc/class-zdz-admin-ui.php' );
		$this->assertStringContainsString( 'ZDZ_Salesperson_Code::get( $user_id )', $settings );
		$this->assertStringContainsString( 'ZDZ_Salesperson_Code::party( $uid )', $bridge );
		$this->assertStringContainsString( 'ZDZ_Salesperson_Code::save_profile(', $ui );
		foreach ( array( $settings, $bridge, $ui ) as $src ) {
			$this->assertStringNotContainsString( "'" . ZDZ_Salesperson_Code::OLD_PROFILE_KEY . "'", $src, 'no reader names an older key itself' );
			$this->assertStringNotContainsString( "'" . ZDZ_Salesperson_Code::OLD_PARTY_KEY . "'", $src );
		}
		$this->assertStringContainsString( 'class-zdz-salesperson-code.php', (string) file_get_contents( $root . 'functions.php' ) );
	}
}
