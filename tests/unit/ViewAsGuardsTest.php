<?php
/**
 * ZDZ_View_As guards: only an administrator previews, only as one of the platform's own role slugs, and never an
 * account in Privacy View (whose gate also refuses the switch's query keys).
 *
 * Neutral data only.
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'absint' ) ) {
	function absint( $v ) { return abs( (int) $v ); }
}
if ( ! function_exists( 'get_user_meta' ) ) {
	function get_user_meta( $uid, $key, $single = false ) { return $GLOBALS['zdz_test_user_meta'][ (int) $uid ][ $key ] ?? ''; }
}
if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id() { return (int) ( $GLOBALS['zdz_test_current_user'] ?? 0 ); }
}

require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-view-as.php';
require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-privacy-view.php';

final class ViewAsGuardsTest extends TestCase {

	private static function set( $prop, $value ) {
		$r = new ReflectionProperty( 'ZDZ_View_As', $prop );
		$r->setAccessible( true );
		$r->setValue( null, $value );
	}

	protected function setUp(): void {
		self::set( 'emulated_role', null );
		self::set( 'emulated_user_id', null );
		$_GET    = array();
		$_COOKIE = array();
		$GLOBALS['zdz_test_current_user'] = 3;
		$GLOBALS['zdz_test_user_caps']    = array( 3 => array( 'manage_options' ) );
		$GLOBALS['zdz_test_user_roles']   = array( 3 => array( 'administrator' ) );
		if ( ! current_user_can( 'manage_options' ) ) {
			$this->markTestSkipped( 'a capability stub loaded earlier does not grant this administrator' );
		}
	}

	protected function tearDown(): void {
		self::set( 'emulated_role', null );
		self::set( 'emulated_user_id', null );
		$_GET    = array();
		$_COOKIE = array();
		unset( $GLOBALS['zdz_test_current_user'], $GLOBALS['zdz_test_user_caps'], $GLOBALS['zdz_test_user_roles'], $GLOBALS['zdz_test_user_meta'] );
	}

	public function test_an_administrator_previews_a_platform_role(): void {
		$_COOKIE['zdz_view_as'] = 'zdz_sales';
		ZDZ_View_As::handle_query_param();
		$this->assertSame( 'zdz_sales', ZDZ_View_As::get_emulated_role() );
		$this->assertTrue( ZDZ_View_As::is_emulating() );
	}

	public function test_only_the_platform_role_slugs_can_be_previewed(): void {
		foreach ( array( 'administrator', 'editor', 'zdz_unknown', ' zdz_sales<b>', '' ) as $role ) {
			self::set( 'emulated_role', null );
			$_COOKIE['zdz_view_as'] = $role;
			ZDZ_View_As::handle_query_param();
			$this->assertNull( ZDZ_View_As::get_emulated_role(), 'refused: ' . $role );
			$this->assertFalse( ZDZ_View_As::is_emulating() );
		}
	}

	public function test_a_previewed_user_id_is_a_number(): void {
		$_COOKIE['zdz_view_as_user'] = '7abc';
		ZDZ_View_As::handle_query_param();
		$this->assertSame( 7, ZDZ_View_As::get_emulated_user_id() );
	}

	public function test_an_account_in_privacy_view_never_previews(): void {
		$GLOBALS['zdz_test_user_meta'] = array( 3 => array( 'zdz_privacy_view' => '1' ) );
		$_COOKIE['zdz_view_as']        = 'zdz_owner';
		$_COOKIE['zdz_view_as_user']   = '5';
		ZDZ_View_As::handle_query_param();
		$this->assertNull( ZDZ_View_As::get_emulated_role(), 'a cookie set earlier is ignored' );
		$this->assertNull( ZDZ_View_As::get_emulated_user_id() );
		self::set( 'emulated_role', 'zdz_owner' );
		$this->assertFalse( ZDZ_View_As::is_emulating(), 'and nothing reads as previewing' );
		$this->assertFalse( ZDZ_Privacy_View::front_allowed( '/', array( 'zdz_view_as' ) ), 'its gate refuses the switch' );
		$this->assertFalse( ZDZ_Privacy_View::front_allowed( '/', array( 'zdz_view_as_user' ) ) );
	}

	public function test_nobody_previews_without_the_administrator_capability(): void {
		$GLOBALS['zdz_test_user_caps'] = array( 3 => array() );
		$GLOBALS['zdz_test_user_roles'] = array( 3 => array( 'zdz_sales' ) );
		$this->assertFalse( current_user_can( 'manage_options' ), 'a salesperson is not an administrator' );
		$_COOKIE['zdz_view_as'] = 'zdz_owner';
		ZDZ_View_As::handle_query_param();
		$this->assertNull( ZDZ_View_As::get_emulated_role() );
		self::set( 'emulated_role', 'zdz_owner' );
		$this->assertFalse( ZDZ_View_As::is_emulating() );
	}
}
