<?php
/**
 * An account in Privacy View and sign-in routes: no page, no act, no route.
 *
 *   - Its gate lists what it may reach, and no sign-in address is on any list: the five sign-in REST routes, the
 *     Sign-in routes screen and its posts are refused there before WordPress routes the request.
 *   - The page, every act on it, the profile save and the login bridge's handlers each know the account themselves,
 *     so the refusal holds wherever a request is judged, and even if the account were written into the list of
 *     managers or named the second issuer.
 *   - It is never given a backup address or a one-time code. Signed out, it still signs in with a code emailed to
 *     its own address, like anyone.
 *
 * Neutral data only.
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/signin-test-doubles.php';

final class SigninPrivacyViewTest extends TestCase {

	const ROUTES = array( 'magic-link-init', 'magic-link-status', 'magic-link-claim', 'magic-link-code-claim', 'magic-link-send-code' );

	protected function setUp(): void {
		zdz_test_signin_reset();
		zdz_test_signin_people();
		if ( ! ( get_user_by( 'id', 3 ) instanceof WP_User ) ) {
			$this->markTestSkipped( 'a get_user_by() stand-in loaded earlier does not know these accounts' );
		}
		zdz_test_signin_as( 3 );   // Devin: an administrator role, in Privacy View
	}

	protected function tearDown(): void {
		unset( $_SERVER['REQUEST_URI'] );
		zdz_test_signin_teardown();
	}

	public function test_the_gates_lists_hold_no_sign_in_address(): void {
		$ns = '/' . ( defined( 'ZDZ_REST_NS' ) ? ZDZ_REST_NS : 'zorderz/v1' );
		$this->assertTrue( ZDZ_Privacy_View::is_on() );
		$this->assertTrue( ZDZ_Privacy_View::rest_allowed( $ns . '/notifications/unread-count', 'GET' ), 'control: a listed read is let through (the gate is answering)' );
		foreach ( self::ROUTES as $route ) {
			foreach ( array( 'GET', 'POST', 'HEAD' ) as $method ) {
				$this->assertFalse( ZDZ_Privacy_View::rest_allowed( $ns . '/' . $route, $method ), "$method $route" );
			}
		}
		$this->assertFalse( ZDZ_Privacy_View::screen_allowed( 'admin.php', 'zdz-signin-routes', array( 'page' ) ), 'the Sign-in routes screen' );
		$this->assertFalse( ZDZ_Privacy_View::screen_allowed( 'admin.php', 'zdz-signin-routes' ) );
		foreach ( array( 'zdz_signin_do', 'zdz_signin_issue', 'zdz_signin_routes', 'magic_link_code_claim' ) as $action ) {
			$this->assertFalse( ZDZ_Privacy_View::ajax_allowed( $action ) );
		}
	}

	public function test_its_gate_refuses_each_sign_in_route(): void {
		foreach ( self::ROUTES as $route ) {
			$_SERVER['REQUEST_URI']    = '/wp-json/zorderz/v1/' . $route;
			$_SERVER['REQUEST_METHOD'] = 'magic-link-status' === $route ? 'GET' : 'POST';
			$_GET                      = 'magic-link-status' === $route ? array( 'request_id' => 'example' ) : array();
			$_POST                     = 'magic-link-status' === $route ? array() : array( 'code' => '123456', 'email' => 'alex@example.com' );
			$this->assertNotSame( '', ZDZ_Privacy_View::verdict(), $_SERVER['REQUEST_METHOD'] . ' ' . $route . ' is refused' );
		}
		$_GET  = array();
		$_POST = array();
	}

	public function test_the_account_gets_no_route_whatever_is_written_about_it(): void {
		$r = 'ZDZ_Signin_Routes';
		$this->assertFalse( $r::gets_routes( 3 ) );
		$this->assertFalse( $r::acting_ok( 3 ), 'every act on the page asks this of the actor' );
		$this->assertFalse( $r::eligible_manager( 3 ) );
		$this->assertFalse( $r::may_claim( 3 ), 'it cannot take the page on a fresh install' );
		$this->assertFalse( $r::claim_management( 3 ) );
		$this->assertFalse( $r::ever_named() );

		// even written straight into what the page keeps
		$GLOBALS['zdz_test_options']['zdz_signin_managers'] = array( 3 );
		$GLOBALS['zdz_test_options']['zdz_signin_issuer']   = array( 'uid' => 3, 'scope' => 'anyone' );
		$this->assertSame( array(), $r::managers() );
		$this->assertNull( $r::issuer() );
		$this->assertFalse( $r::may_manage( 3 ) );
		$this->assertFalse( $r::is_issuer( 3 ) );
		$this->assertFalse( $r::may_open( 3 ) );
		$this->assertFalse( $r::may_edit_addresses( 3 ) );
		$this->assertTrue( is_wp_error( $r::issue( 3, 11 ) ) );
		$this->assertFalse( $r::cancel_issued( 3, 11 ) );
		$this->assertFalse( $r::add_manager( 3, 2 ) );
		$this->assertFalse( $r::set_issuer( 3, 11 ) );
		$this->assertNull( $r::save_addresses( 3, 3, 'devin.home@example.net' ) );
		$this->assertNull( $r::save_addresses( 3, 11, '' ) );

		$src = (string) file_get_contents( dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-signin-routes.php' );
		$this->assertMatchesRegularExpression( '/function may_open\(.{0,200}?may_manage\(.{0,80}?may_claim\(.{0,80}?is_issuer\(/s', $src, 'may_open() is those three and nothing else' );
		foreach ( array( 'may_manage', 'may_claim', 'is_issuer' ) as $fn ) {
			$this->assertMatchesRegularExpression( '/function ' . $fn . '\(.{0,200}?self::acting_ok\(/s', $src, $fn . '() asks acting_ok()' );
		}
	}

	public function test_nobody_gives_it_a_code_or_a_backup_address(): void {
		$r = 'ZDZ_Signin_Routes';
		zdz_test_signin_as( 1 );
		$r::claim_management( 1 );
		$this->assertFalse( $r::may_issue_for( 1, 3 ) );
		$this->assertTrue( is_wp_error( $r::issue( 1, 3 ) ) );
		$this->assertFalse( $r::put_code( 3, '12345678', 'issued', 600, array( 'by' => 1 ) ) );
		$this->assertNull( $r::save_addresses( 1, 3, 'devin.home@example.net' ) );
		$this->assertFalse( $r::set_issuer( 1, 3 ) );
		$this->assertFalse( $r::add_manager( 1, 3 ) );
		$this->assertSame( 'none', $r::cover( 3 ) );

		update_user_meta( 3, 'zdz_login_aliases', array( 'devin.old@example.net' ) );
		$this->assertSame( array(), $r::save_addresses( 1, 3, '' ), 'an address it had before it was put in Privacy View can be removed' );
		$this->assertSame( array(), $r::addresses( 3 ) );

		$this->assertTrue( $r::put_code( 3, '246810', 'mail', 300, array( 'to' => 'devin@example.com' ) ), 'a code emailed to its own address is kept like anyone\'s' );
		$c = $r::claim( 'devin@example.com', '246810' );
		$this->assertSame( 3, (int) $c['user']->ID );
	}
}
