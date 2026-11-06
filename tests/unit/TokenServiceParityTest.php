<?php
/**
 * The token service's one-app and reconnect rules:
 *   - a caller naming a different app registration is refused before any network call;
 *   - a family that names another app (or a separate family that names none) is not ours;
 *   - a refresh token written into a family by a sign-in is adopted, a rotated-away one never
 *     is, and a sealed value is read decoded, never adopted as a token.
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'update_option' ) ) {
	function update_option( $k, $v, $autoload = null ) { $GLOBALS['zdz_test_options'][ $k ] = $v; return true; }
}
if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( $k ) { unset( $GLOBALS['zdz_test_options'][ $k ] ); return true; }
}
if ( ! function_exists( 'wp_cache_delete' ) ) {
	function wp_cache_delete( $k, $g = '' ) { return true; }
}
if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( $k ) { return $GLOBALS['zdz_test_transients'][ $k ] ?? false; }
}
if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( $k, $v, $ttl = 0 ) { $GLOBALS['zdz_test_transients'][ $k ] = $v; return true; }
}
if ( ! function_exists( 'wp_remote_post' ) ) {
	function wp_remote_post( $url, $args = array() ) {
		$GLOBALS['zdz_test_http'][] = $url;
		return array( 'response' => array( 'code' => 500 ), 'body' => '' );
	}
}
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}

require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-core-crypto.php';
require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-token-service.php';

final class TokenServiceParityTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['zdz_test_options']    = array();
		$GLOBALS['zdz_test_transients'] = array();
		$GLOBALS['zdz_test_http']       = array();
		$GLOBALS['zdz_test_filters']['zdz_token_providers'] = function () {
			return array(
				'demo' => array(
					'id'                 => 'demo',
					'label'              => 'Demo billing',
					'default'            => true,
					'store_key'          => 'demo',
					'token_url'          => 'https://billing.example.com/oauth/token',
					'plaintext_secret_max' => 100,
					'option_families'    => array( 'zdz_core_demo_', 'zx_demo_', 'zy_demo_' ),
					'separate_families'  => array( 'zy_demo_' ),
					'reconnect_families' => array( 'zdz_core_demo_' ),
					'credentials'        => array( 'client_id' => 'client-alpha', 'client_secret' => 'secret-alpha', 'refresh_token' => '' ),
				),
			);
		};
		ZDZ_Token_Service::reset_memo();
	}

	protected function tearDown(): void {
		ZDZ_Token_Service::reset_memo();
		unset( $GLOBALS['zdz_test_options'], $GLOBALS['zdz_test_filters'], $GLOBALS['zdz_test_transients'], $GLOBALS['zdz_test_http'] );
	}

	private static function provider(): array {
		$m = new ReflectionMethod( 'ZDZ_Token_Service', 'resolve_provider' );
		$m->setAccessible( true );
		return $m->invoke( null, 'demo' );
	}

	public function test_a_caller_on_another_app_is_refused_without_a_network_call(): void {
		update_option( 'zdz_tok_demo_refresh_token', 'rt-live-0001' );
		$this->assertSame( '', ZDZ_Token_Service::refresh( array( 'provider' => 'demo', 'client_id' => 'client-beta', 'client_secret' => 'secret-beta' ) ) );
		$this->assertSame( array(), $GLOBALS['zdz_test_http'] );
		$this->assertSame( 'rt-live-0001', get_option( 'zdz_tok_demo_refresh_token' ), 'the shared store is left alone' );
		$this->assertSame( 'client-alpha', ZDZ_Token_Service::canonical_pair( 'demo' )['client_id'] );
	}

	public function test_which_families_are_ours(): void {
		$p = self::provider();
		$this->assertTrue( ZDZ_Token_Service::family_is_ours( $p, 'zx_demo_' ), 'no client id: inherits' );
		update_option( 'zx_demo_client_id', 'client-beta' );
		$this->assertFalse( ZDZ_Token_Service::family_is_ours( $p, 'zx_demo_' ), 'names another app' );
		update_option( 'zx_demo_client_id', ZDZ_Core_Crypto::encode( 'client-alpha', ZDZ_Core_Crypto::FIXED ) );
		$this->assertTrue( ZDZ_Token_Service::family_is_ours( $p, 'zx_demo_' ), 'a sealed id is read decoded' );
		$this->assertFalse( ZDZ_Token_Service::family_is_ours( $p, 'zy_demo_' ), 'a separate family must name the app itself' );
		update_option( 'zy_demo_client_id', 'client-alpha' );
		$this->assertTrue( ZDZ_Token_Service::family_is_ours( $p, 'zy_demo_' ) );
	}

	public function test_a_reconnect_is_adopted_and_a_rotated_away_token_never_is(): void {
		update_option( 'zdz_tok_demo_refresh_token', 'rt-live-0001' );
		update_option( 'zdz_tok_demo_reauth_needed', 'yes' );
		update_option( 'zx_demo_access_token', 'at-new-0002' );
		$this->assertSame( 'adopted', ZDZ_Token_Service::adopt_external( 'demo', 'zx_demo_', 'rt-new-0002' ) );
		$this->assertSame( 'rt-new-0002', get_option( 'zdz_tok_demo_refresh_token' ) );
		$this->assertSame( 'at-new-0002', get_option( 'zdz_tok_demo_access_token' ) );
		$this->assertNull( get_option( 'zdz_tok_demo_reauth_needed', null ), 'the re-auth flag clears' );
		$retired = get_option( 'zdz_tok_demo_retired_rt' );
		$this->assertCount( 1, $retired );
		$this->assertStringNotContainsString( 'rt-live', implode( '', $retired ), 'fingerprints only, never the token' );

		$this->assertSame( 'stale', ZDZ_Token_Service::adopt_external( 'demo', 'zx_demo_', 'rt-live-0001' ) );
		$this->assertSame( 'rt-new-0002', get_option( 'zdz_tok_demo_refresh_token' ) );
		$this->assertSame( 'rt-new-0002', get_option( 'zx_demo_refresh_token' ), 'the live token is written back to that family' );
		$this->assertSame( 'same', ZDZ_Token_Service::adopt_external( 'demo', 'zx_demo_', 'rt-new-0002' ) );
	}

	public function test_another_apps_family_and_non_tokens_are_never_adopted(): void {
		update_option( 'zdz_tok_demo_refresh_token', 'rt-live-0001' );
		$this->assertSame( 'foreign', ZDZ_Token_Service::adopt_external( 'demo', 'zy_demo_', 'rt-other-app-0003' ) );
		$this->assertSame( 'skip', ZDZ_Token_Service::adopt_external( 'demo', 'zx_demo_', 'not a token' ) );
		$this->assertSame( 'skip', ZDZ_Token_Service::adopt_external( 'demo', 'zx_demo_', str_repeat( 'x', 201 ) ) );
		$this->assertSame( 'rt-live-0001', get_option( 'zdz_tok_demo_refresh_token' ) );
		$sealed = ZDZ_Core_Crypto::encode( 'rt-sealed-0004', ZDZ_Core_Crypto::FIXED );
		$this->assertSame( 'adopted', ZDZ_Token_Service::adopt_external( 'demo', 'zdz_core_demo_', $sealed ) );
		$this->assertSame( 'rt-sealed-0004', get_option( 'zdz_tok_demo_refresh_token' ), 'a sealed family value is adopted decoded' );
	}

	public function test_the_retired_list_is_kept_short(): void {
		update_option( 'zdz_tok_demo_refresh_token', 'rt-0' );
		for ( $i = 1; $i <= 12; $i++ ) {
			ZDZ_Token_Service::adopt_external( 'demo', 'zx_demo_', 'rt-' . $i );
		}
		$this->assertCount( ZDZ_Token_Service::RETIRED_KEEP, get_option( 'zdz_tok_demo_retired_rt' ) );
	}
}
