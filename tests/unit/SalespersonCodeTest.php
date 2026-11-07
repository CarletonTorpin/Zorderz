<?php
/**
 * One person's salesperson code, read the same way everywhere (ZDZ_Salesperson_Code).
 *
 *   - the profile's code first, then the party code; either screen's code is seen;
 *   - saving the profile field keeps the code as typed, at most ten characters, and an empty value clears it;
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

	public function test_saving_the_profile(): void {
		$this->meta( array( ZDZ_Salesperson_Code::PROFILE_KEY => 'AB' ) );
		ZDZ_Salesperson_Code::save_profile( self::UID, '' );
		$this->assertSame( '', ZDZ_Salesperson_Code::get( self::UID ), 'an empty value clears the code' );
		ZDZ_Salesperson_Code::save_profile( self::UID, '  JK-LONGER-THAN-TEN  ' );
		$this->assertSame( 'JK-LONGER-', ZDZ_Salesperson_Code::profile( self::UID ), 'kept as typed, at most ten characters' );
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
		$this->assertStringContainsString( 'class-zdz-salesperson-code.php', (string) file_get_contents( $root . 'functions.php' ) );
	}
}
