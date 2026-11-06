<?php
/**
 * The Email tile on a site where Email is not turned on opens a plain note (never nothing):
 *   - an administrator is told where the connection goes and where Email is turned on;
 *   - anyone else is told an administrator turns it on, with no admin link;
 *   - the note carries no connection data (no REST address, nonce or sign-in link).
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( $s, $d = null ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
}
if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
}
if ( ! function_exists( 'admin_url' ) ) {
	function admin_url( $p = '' ) { return 'https://example.com/wp-admin/' . ltrim( (string) $p, '/' ); }
}

require_once dirname( __DIR__, 2 ) . '/zorderz-apps/apps/inbox/includes/class-zib-widget.php';

final class InboxOffCardTest extends TestCase {

	private $saved = array();

	protected function setUp(): void {
		foreach ( array( 'zdz_test_current_user', 'zdz_test_user_roles', 'zdz_test_user_caps' ) as $k ) {
			$this->saved[ $k ] = $GLOBALS[ $k ] ?? null;
		}
	}

	protected function tearDown(): void {
		foreach ( $this->saved as $k => $v ) {
			if ( null === $v ) {
				unset( $GLOBALS[ $k ] );
			} else {
				$GLOBALS[ $k ] = $v;
			}
		}
	}

	private function as_user( int $uid, array $roles ): void {
		$GLOBALS['zdz_test_current_user']       = $uid;
		$GLOBALS['zdz_test_user_roles'][ $uid ] = $roles;
		$GLOBALS['zdz_test_user_caps'][ $uid ]  = array();
	}

	public function test_administrator_is_shown_where_to_turn_email_on(): void {
		$this->as_user( 41, array( 'administrator' ) );
		$html = ZIB_Widget::render_off_card();
		$this->assertStringContainsString( 'not turned on', $html );
		$this->assertStringContainsString( 'Zorderz → Credentials', $html );
		$this->assertStringContainsString( 'options-general.php?page=zib-settings', $html );
	}

	public function test_anyone_else_gets_no_admin_link(): void {
		$this->as_user( 42, array( 'zdz_tech' ) );
		$html = ZIB_Widget::render_off_card();
		$this->assertStringContainsString( 'An administrator turns it on.', $html );
		$this->assertStringNotContainsString( 'wp-admin', $html );
	}

	public function test_the_note_carries_no_connection_data(): void {
		$this->as_user( 41, array( 'administrator' ) );
		$html = ZIB_Widget::render_off_card();
		foreach ( array( 'data-zib-rest', 'data-zib-nonce', 'data-zib-start' ) as $attr ) {
			$this->assertStringNotContainsString( $attr, $html );
		}
	}
}
