<?php
/**
 * Tools → Zorderz Integration Health on a fresh install reads true:
 *   - the billing cascade is not a failure while the billing app is not connected at all,
 *     and is one once a client id is entered but no copy holds a token;
 *   - app discovery counts what registered, with no fixed expectation;
 *   - Messages' document cards pass with the Estimates document reader alone (the
 *     freshbooks-preview route is only the fallback), and fail with neither.
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'has_filter' ) ) {
	function has_filter( $hook, $cb = false ) { return isset( $GLOBALS['zdz_test_filters'][ $hook ] ); }
}
if ( ! function_exists( 'rest_get_server' ) ) {
	function rest_get_server() {
		return new class() {
			public function get_routes() { return (array) ( $GLOBALS['zdz_test_rest_routes'] ?? array() ); }
		};
	}
}

require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-integration-tests.php';

final class IntegrationHealthChecksTest extends TestCase {

	private $saved = array();

	protected function setUp(): void {
		foreach ( array( 'zdz_test_options', 'zdz_test_filters', 'zdz_test_rest_routes' ) as $k ) {
			$this->saved[ $k ] = $GLOBALS[ $k ] ?? null;
		}
		$GLOBALS['zdz_test_options']     = array();
		$GLOBALS['zdz_test_filters']     = array();
		$GLOBALS['zdz_test_rest_routes'] = array();
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

	private function check( string $method ): array {
		$m = new ReflectionMethod( 'ZDZ_Integration_Tests', $method );
		$m->setAccessible( true );
		return $m->invoke( new ZDZ_Integration_Tests() );
	}

	public function test_billing_cascade_passes_while_billing_is_not_connected(): void {
		$r = $this->check( 'check_credential_cascade' );
		$this->assertTrue( $r['pass'] );
		$this->assertStringContainsString( 'not connected yet', $r['detail'] );
	}

	public function test_billing_cascade_fails_when_connected_without_a_token(): void {
		$GLOBALS['zdz_test_options']['zdz_core_fb_client_id'] = 'client-id-for-test';
		$r = $this->check( 'check_credential_cascade' );
		$this->assertFalse( $r['pass'] );
		$this->assertSame( '0/3 prefixes have access tokens', $r['detail'] );
	}

	public function test_billing_cascade_passes_with_a_token(): void {
		$GLOBALS['zdz_test_options']['zdz_core_fb_client_id']   = 'client-id-for-test';
		$GLOBALS['zdz_test_options']['zdz_core_fb_access_token'] = 'token-for-test';
		$r = $this->check( 'check_credential_cascade' );
		$this->assertTrue( $r['pass'] );
		$this->assertSame( '1/3 prefixes have access tokens', $r['detail'] );
	}

	public function test_app_discovery_reports_the_count_without_a_fixed_expectation(): void {
		$GLOBALS['zdz_test_filters']['zdz_register_apps'] = static function () {
			return array( 'a' => 1, 'b' => 2, 'c' => 3 );
		};
		$r = $this->check( 'check_plugin_discovery' );
		$this->assertTrue( $r['pass'] );
		$this->assertSame( '3 apps registered', $r['detail'] );

		$GLOBALS['zdz_test_filters']['zdz_register_apps'] = static function () {
			return array();
		};
		$this->assertFalse( $this->check( 'check_plugin_discovery' )['pass'] );
	}

	public function test_document_cards_pass_with_the_estimates_reader_alone(): void {
		$GLOBALS['zdz_test_filters']['zdz_fb_document_for_viewer'] = static function ( $v ) {
			return $v;
		};
		$r = $this->check( 'check_messaging_preview' );
		$this->assertTrue( $r['pass'] );
		$this->assertSame( 'Estimates document reader registered', $r['detail'] );
	}

	public function test_document_cards_pass_with_the_fallback_route(): void {
		$GLOBALS['zdz_test_rest_routes'] = array( '/zorderz/v1/freshbooks-preview/(?P<id>\d+)' => array() );
		$this->assertTrue( $this->check( 'check_messaging_preview' )['pass'] );
	}

	public function test_document_cards_fail_with_neither(): void {
		$r = $this->check( 'check_messaging_preview' );
		$this->assertFalse( $r['pass'] );
		$this->assertStringContainsString( 'number only', $r['detail'] );
	}
}
