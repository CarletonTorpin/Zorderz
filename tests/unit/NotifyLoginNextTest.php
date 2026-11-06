<?php
/**
 * The sign-in return path: only a plain same-site path under an allowed prefix is ever followed,
 * and every open-redirect shape is refused.
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/notify-media-stubs.php';
require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-login-next.php';

final class NotifyLoginNextTest extends TestCase {

	protected function tearDown(): void {
		zdz_notify_test_reset();
	}

	/* ── Sign-in return path ──────────────────────────────────────────────── */

	public function test_login_next_accepts_allow_listed_same_site_paths(): void {
		$this->assertSame( '/vault/example-doc', ZDZ_Login_Next::clean( '/vault/example-doc' ) );
		$this->assertSame( '/vault/example-doc', ZDZ_Login_Next::clean( 'https://app.example.com/vault/example-doc' ) );
		$this->assertSame( '/vault/excerpt/12', ZDZ_Login_Next::clean( '%2Fvault%2Fexcerpt%2F12' ), 'one level of encoding' );
	}

	/** @dataProvider refusals */
	public function test_login_next_refuses_open_redirects( $candidate ): void {
		$this->assertSame( '', ZDZ_Login_Next::clean( $candidate ) );
	}

	public function refusals(): array {
		return array(
			'other host'          => array( 'https://evil.example.net/vault/x' ),
			'protocol-relative'   => array( '//evil.example.net/vault/x' ),
			'encoded slashes'     => array( '%2F%2Fevil.example.net%2Fvault%2Fx' ),
			'double encoding'     => array( '%252Fvault%252Fx' ),
			'backslash'           => array( '/\\evil.example.net/vault/x' ),
			'dot-dot'             => array( '/vault/../wp-admin/' ),
			'query'               => array( '/vault/x?next=https://evil.example.net' ),
			'fragment'            => array( '/vault/x#y' ),
			'scheme'              => array( 'javascript:alert(1)' ),
			'the sign-in page'    => array( '/login/' ),
			'wp-admin'            => array( '/wp-admin/options.php' ),
			'home'                => array( '/' ),
			'not allow-listed'    => array( '/some/other/page' ),
			'the prefix alone'    => array( '/vault/' ),
			'not a string'        => array( array( '/vault/x' ) ),
			'too long'            => array( '/vault/' . str_repeat( 'a', 400 ) ),
			'spaces'              => array( '/vault/a b' ),
			'host with userinfo'  => array( 'https://app.example.com@evil.example.net/vault/x' ),
		);
	}

	public function test_a_filter_can_add_a_prefix_but_never_open_the_site(): void {
		$GLOBALS['zdz_test_filters']['zdz_login_next_prefixes'] = function ( $p ) { return array_merge( $p, array( '/', '//', '/jobs/', 'no-slash/', '/a/../' ) ); };
		$this->assertSame( array( '/vault/', '/jobs/' ), ZDZ_Login_Next::allowed_prefixes() );
		$this->assertSame( '/jobs/41', ZDZ_Login_Next::clean( '/jobs/41' ) );
		$this->assertSame( '', ZDZ_Login_Next::clean( '/anything' ) );
	}

	public function test_login_next_reads_its_argument_out_of_a_wrapped_url(): void {
		$this->assertSame( '/vault/doc', ZDZ_Login_Next::from_url( 'https://app.example.com/?zdz_next=%2Fvault%2Fdoc&x=1' ) );
		$this->assertSame( '/vault/doc', ZDZ_Login_Next::from_url( rawurlencode( 'https://app.example.com/?zdz_next=%2Fvault%2Fdoc' ) ) );
		$this->assertSame( '', ZDZ_Login_Next::from_url( 'https://app.example.com/?zdz_next=https%3A%2F%2Fevil.example.net%2Fvault%2Fx' ) );
	}
}
