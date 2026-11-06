<?php
/**
 * Google Business Profile (ZDZ_Core_Google_Business): the business's own reviews, read-only.
 *
 *   - The Credentials entry is added on zdz_core_credentials_services, append-only: a filter can add a service but
 *     never replace one, never reuse another service's rows, and a half-formed entry is ignored.
 *   - The sign-in state is signed, bound to one person, expires, and is spent by its first use (a replay is refused).
 *   - The sign-in is sealed at rest. The stored row, the access-token cache, the status, the words shown and the
 *     Credentials block never carry the refresh token, the access token, the sign-in code, the client secret or the
 *     client ID.
 *   - A project Google has not approved: every answer says "not approved", the site asks again at most once an hour,
 *     and the home tile shows the typed number saying why.
 *   - The cache window: a figure younger than an hour is used with no call; older, it is asked once.
 *   - A copy of the site (ZDZ_Staging_Guard on) calls nothing: no sign-in, no refresh, no read, no revoke.
 *
 * Neutral stand-in values only. The network is a scripted transport.
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
if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id() { return (int) ( $GLOBALS['zdz_test_current_user'] ?? 0 ); }
}
if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( $k ) { return $GLOBALS['zdz_test_transients'][ $k ] ?? false; }
}
if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( $k, $v, $ttl = 0 ) { $GLOBALS['zdz_test_transients'][ $k ] = $v; return true; }
}
if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( $k ) { unset( $GLOBALS['zdz_test_transients'][ $k ] ); return true; }
}
if ( ! function_exists( 'wp_cache_delete' ) ) {
	function wp_cache_delete( $k, $g = '' ) { return true; }
}
if ( ! function_exists( 'admin_url' ) ) {
	function admin_url( $p = '' ) { return 'https://example.com/wp-admin/' . ltrim( (string) $p, '/' ); }
}
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
}
if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
}
if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
}
if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( $s, $d = null ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
}
if ( ! function_exists( 'wp_nonce_field' ) ) {
	function wp_nonce_field( $a = -1, $n = '_wpnonce', $r = true, $echo = true ) { $h = '<input type="hidden" name="_wpnonce" value="nonce">'; if ( $echo ) { echo $h; } return $h; }
}

/** The options table as rows of strings, read by the credentials layer through $wpdb (the same rows back get_option()). */
if ( ! class_exists( 'ZDZ_Test_Gbp_DB' ) ) {
	class ZDZ_Test_Gbp_DB {
		public $options = 'wp_options';
		public function prepare( $q, ...$args ) {
			return array( 'q' => $q, 'args' => $args );
		}
		public function get_var( $p ) {
			$name = (string) ( $p['args'][0] ?? '' );
			if ( ! array_key_exists( $name, $GLOBALS['zdz_test_options'] ?? array() ) ) {
				return null;
			}
			$v = $GLOBALS['zdz_test_options'][ $name ];
			return is_array( $v ) ? serialize( $v ) : (string) $v;
		}
	}
}

require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-core-crypto.php';
require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-core-credentials.php';
require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-staging-guard.php';
require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-core-google-business.php';

final class GoogleBusinessProfileTest extends TestCase {

	/** Neutral stand-ins, never real values. */
	const CLIENT_ID     = 'example-client-id-0001';
	const CLIENT_SECRET = 'example-client-secret-0002';
	const REFRESH       = '1//example-refresh-token-0003';
	const ACCESS        = 'ya29.example-access-token-0004';
	const ACCESS_2      = 'ya29.example-access-token-0005';
	const CODE          = '4/example-sign-in-code-0006';
	const NOW           = 1780000000;

	protected function setUp(): void {
		$GLOBALS['zdz_test_options']      = array();
		$GLOBALS['zdz_test_transients']   = array();
		$GLOBALS['zdz_test_current_user'] = 3;
		$GLOBALS['zdz_test_gbp_calls']    = array();
		$GLOBALS['zdz_test_gbp_answers']  = array();
		$GLOBALS['wpdb']                  = new ZDZ_Test_Gbp_DB();
		$GLOBALS['zdz_test_filters']['zdz_core_credentials_services'] = array( 'ZDZ_Core_Google_Business', 'add_service' );
		ZDZ_Core_Google_Business::$now       = self::NOW;
		ZDZ_Core_Google_Business::$transport = static function ( string $method, string $url, array $args ): array {
			$GLOBALS['zdz_test_gbp_calls'][] = array( $method, $url, $args );
			foreach ( $GLOBALS['zdz_test_gbp_answers'] as $i => $a ) {
				if ( 0 === strpos( $url, $a[0] ) ) {
					unset( $GLOBALS['zdz_test_gbp_answers'][ $i ] );
					return array( $a[1], $a[2] );
				}
			}
			return array( 0, '' );
		};
		ZDZ_KPI_Metrics::$now = self::NOW;
		self::staging( false );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['zdz_test_filters']['zdz_core_credentials_services'], $GLOBALS['zdz_test_gbp_calls'], $GLOBALS['zdz_test_gbp_answers'] );
		unset( $GLOBALS['zdz_test_options'], $GLOBALS['zdz_test_transients'], $GLOBALS['zdz_test_current_user'], $GLOBALS['wpdb'] );
		ZDZ_Core_Google_Business::$now       = null;
		ZDZ_Core_Google_Business::$transport = null;
		ZDZ_KPI_Metrics::$now                = null;
		( new ReflectionProperty( 'ZDZ_Staging_Guard', 'status' ) )->setValue( null, null );
		unset( $GLOBALS['zdz_test_user_caps'] );
	}

	/* ── helpers ─────────────────────────────────────────────────────────── */

	/** This request's staging-guard decision. */
	private static function staging( bool $on ): void {
		( new ReflectionProperty( 'ZDZ_Staging_Guard', 'status' ) )->setValue( null, $on ? array( 'active' => true, 'reason' => 'forced_on' ) : array( 'active' => false, 'reason' => 'live' ) );
	}

	private static function with_client(): void {
		$GLOBALS['zdz_test_options']['zdz_core_gbp_client_id']     = ZDZ_Core_Crypto::encode( self::CLIENT_ID, ZDZ_Core_Crypto::FIXED );
		$GLOBALS['zdz_test_options']['zdz_core_gbp_client_secret'] = ZDZ_Core_Crypto::encode( self::CLIENT_SECRET, ZDZ_Core_Crypto::FIXED );
	}

	private static function ready(): void {
		self::with_client();
		$GLOBALS['zdz_test_options']['zdz_core_gbp_token']    = array( 'refresh' => ZDZ_Core_Crypto::encode( self::REFRESH, ZDZ_Core_Crypto::FIXED ), 'at' => self::NOW - 86400, 'by' => 3 );
		$GLOBALS['zdz_test_options']['zdz_core_gbp_location'] = array( 'account' => 'accounts/1001', 'location' => 'locations/2002', 'title' => 'Example Co' );
	}

	/** The next answer to a request whose URL starts with $prefix. */
	private static function answer( string $prefix, int $http, $body ): void {
		$GLOBALS['zdz_test_gbp_answers'][] = array( $prefix, $http, is_string( $body ) ? $body : json_encode( $body ) );
	}

	private static function calls(): array {
		return $GLOBALS['zdz_test_gbp_calls'];
	}

	private static function reviews_prefix(): string {
		return sprintf( ZDZ_Core_Google_Business::REVIEWS_FMT, 'accounts/1001', 'locations/2002' );
	}

	/** Everything at rest and everything shown, as one string to search. */
	private static function everything(): string {
		return serialize( array( $GLOBALS['zdz_test_options'], $GLOBALS['zdz_test_transients'] ) );
	}

	private static function google_tile(): array {
		$m = new ReflectionMethod( 'ZDZ_KPI_Metrics', 'google_tile' );
		$m->setAccessible( true );
		return $m->invoke( null );
	}

	/* ── the Credentials entry ───────────────────────────────────────────── */

	public function test_the_credentials_entry_is_added_and_reads_the_sealed_client(): void {
		self::with_client();
		$all = ZDZ_Core_Credentials::services();
		$this->assertArrayHasKey( 'google_business', $all );
		$this->assertSame( 'app', $all['google_business']['kind'] );
		$this->assertTrue( $all['google_business']['fields']['client_secret'][1], 'the secret is a write-only field' );
		$this->assertSame( ZDZ_Core_Crypto::FIXED, $all['google_business']['copies']['client_secret']['zdz_core_gbp_client_secret'][0], 'the secret is sealed at rest' );
		$this->assertSame( array( self::CLIENT_ID, self::CLIENT_SECRET ), ZDZ_Core_Google_Business::client() );
		$this->assertTrue( ZDZ_Core_Google_Business::has_client() );
	}

	public function test_the_services_filter_is_append_only(): void {
		$poe_label = ZDZ_Core_Credentials::services()['poe']['label'];
		$GLOBALS['zdz_test_filters']['zdz_core_credentials_services'] = static function ( $s ) {
			$s = ZDZ_Core_Google_Business::add_service( $s );
			$s['poe']['label'] = 'replaced';                       // a change to a listed service
			unset( $s['nutshell'] );                               // a removal
			$grab = ZDZ_Core_Google_Business::service_def();       // another service's rows
			$grab['canonical']['client_secret'] = 'zdz_core_poe_api_key';
			$grab['copies']['client_secret']    = array( 'zdz_core_poe_api_key' => array( ZDZ_Core_Crypto::PLAIN, 'x' ) );
			$s['grabber'] = $grab;
			$half = ZDZ_Core_Google_Business::service_def();       // half-formed: a field with no copies
			unset( $half['copies']['client_id'] );
			$half['canonical'] = array( 'client_id' => 'zdz_core_half_id', 'client_secret' => 'zdz_core_half_secret' );
			$half['copies']['client_secret'] = array( 'zdz_core_half_secret' => array( ZDZ_Core_Crypto::FIXED, 'x' ) );
			$s['half'] = $half;
			$s['Bad Id'] = ZDZ_Core_Google_Business::service_def();
			return $s;
		};
		$all = ZDZ_Core_Credentials::services();
		$this->assertSame( $poe_label, $all['poe']['label'], 'a listed service cannot be changed' );
		$this->assertArrayHasKey( 'nutshell', $all, 'nor removed' );
		$this->assertArrayHasKey( 'google_business', $all, 'a whole new entry is added' );
		$this->assertArrayNotHasKey( 'grabber', $all, "an entry that stores in another service's row is refused" );
		$this->assertArrayNotHasKey( 'half', $all, 'a half-formed entry is refused' );
		$this->assertArrayNotHasKey( 'Bad Id', $all );

		$GLOBALS['zdz_test_filters']['zdz_core_credentials_services'] = static function () { return 'not a list'; };
		$this->assertArrayHasKey( 'poe', ZDZ_Core_Credentials::services(), 'a broken filter leaves the built-in list' );
		$this->assertArrayNotHasKey( 'google_business', ZDZ_Core_Credentials::services() );
	}

	/* ── the sign-in state ───────────────────────────────────────────────── */

	public function test_a_state_verifies_once_for_its_own_person(): void {
		$s = ZDZ_Core_Google_Business::issue_state( 3 );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{32}\.[0-9]+\.[a-f0-9]{32}$/', $s );
		$nonce = explode( '.', $s )[0];
		$this->assertStringNotContainsString( $nonce, self::everything(), 'only a hash of the nonce is kept' );
		$this->assertSame( '', ZDZ_Core_Google_Business::verify_state( 3, $s ) );
		$this->assertSame( 'unknown', ZDZ_Core_Google_Business::verify_state( 3, $s ), 'a replay is refused: the state was spent' );
	}

	public function test_a_state_is_refused_when_forged_foreign_old_or_replaced(): void {
		$s = ZDZ_Core_Google_Business::issue_state( 3 );
		$this->assertSame( 'forged', ZDZ_Core_Google_Business::verify_state( 4, $s ), "another person's state does not match their signature" );
		$this->assertSame( '', ZDZ_Core_Google_Business::verify_state( 3, $s ), "and it did not spend the owner's" );

		$s = ZDZ_Core_Google_Business::issue_state( 3 );
		list( $n, $exp, $sig ) = explode( '.', $s );
		$this->assertSame( 'forged', ZDZ_Core_Google_Business::verify_state( 3, $n . '.' . ( (int) $exp + 3600 ) . '.' . $sig ), 'a longer expiry breaks the signature' );
		$this->assertSame( 'unknown', ZDZ_Core_Google_Business::verify_state( 3, $s ), 'and any answer spends the pending state' );

		foreach ( array( '', 'abc', str_repeat( 'a', 32 ), $n . '.' . $exp, $n . '.' . $exp . '.' . strtoupper( $sig ) ) as $bad ) {
			ZDZ_Core_Google_Business::issue_state( 3 );
			$this->assertSame( 'malformed', ZDZ_Core_Google_Business::verify_state( 3, $bad ), 'malformed: ' . $bad );
		}
		$this->assertSame( 'malformed', ZDZ_Core_Google_Business::verify_state( 0, ZDZ_Core_Google_Business::issue_state( 0 ) ), 'never for no one' );

		$s = ZDZ_Core_Google_Business::issue_state( 3 );
		ZDZ_Core_Google_Business::$now = self::NOW + ZDZ_Core_Google_Business::STATE_TTL + 1;
		$this->assertSame( 'expired', ZDZ_Core_Google_Business::verify_state( 3, $s ), 'over ten minutes' );
		ZDZ_Core_Google_Business::$now = self::NOW;

		$first  = ZDZ_Core_Google_Business::issue_state( 3 );
		$second = ZDZ_Core_Google_Business::issue_state( 3 );
		$this->assertSame( 'unknown', ZDZ_Core_Google_Business::verify_state( 3, $first ), 'a newer Connect replaces the older state' );
		ZDZ_Core_Google_Business::issue_state( 3 );
		$this->assertSame( 'unknown', ZDZ_Core_Google_Business::verify_state( 3, $second ) );
	}

	public function test_the_sign_in_address_asks_for_one_scope_and_carries_no_secret(): void {
		$this->assertSame( '', ZDZ_Core_Google_Business::connect_url( 3 ), 'no client, no address' );
		self::with_client();
		$url = ZDZ_Core_Google_Business::connect_url( 3 );
		$this->assertSame( 0, strpos( $url, ZDZ_Core_Google_Business::AUTH_URL . '?' ) );
		parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $q );
		$this->assertSame( ZDZ_Core_Google_Business::SCOPE, $q['scope'], 'one scope' );
		$this->assertSame( 'offline', $q['access_type'] );
		$this->assertSame( 'consent', $q['prompt'] );
		$this->assertSame( 'https://example.com/wp-admin/admin.php?page=zdz-google-business-callback', $q['redirect_uri'] );
		$this->assertStringNotContainsString( self::CLIENT_SECRET, $url );
		$this->assertSame( '', ZDZ_Core_Google_Business::verify_state( 3, $q['state'] ), "the address carries the person's own state" );
		$this->assertSame( array(), self::calls(), 'building the address calls nothing' );
	}

	/* ── sealing, and nothing echoed ─────────────────────────────────────── */

	public function test_the_sign_in_is_sealed_and_never_echoed(): void {
		self::with_client();
		self::answer( ZDZ_Core_Google_Business::TOKEN_URL, 200, array( 'access_token' => self::ACCESS, 'refresh_token' => self::REFRESH, 'expires_in' => 3599, 'token_type' => 'Bearer' ) );
		$this->assertSame( '', ZDZ_Core_Google_Business::exchange_code( self::CODE, 3 ) );

		$this->assertCount( 1, self::calls() );
		list( $method, $url, $args ) = self::calls()[0];
		$this->assertSame( array( 'POST', ZDZ_Core_Google_Business::TOKEN_URL ), array( $method, $url ), 'the code goes to the token endpoint only' );
		$this->assertSame( self::CODE, $args['body']['code'] );

		$row = $GLOBALS['zdz_test_options']['zdz_core_gbp_token'];
		$this->assertSame( ZDZ_Core_Crypto::FIXED, ZDZ_Core_Crypto::decode( $row['refresh'] )[0], 'sealed' );
		$this->assertSame( self::REFRESH, ZDZ_Core_Crypto::plain( $row['refresh'] ) );
		$this->assertSame( 3, $row['by'] );
		foreach ( array( self::REFRESH, self::ACCESS, self::CODE, self::CLIENT_SECRET, self::CLIENT_ID ) as $plain ) {
			$this->assertStringNotContainsString( $plain, self::everything(), 'nothing in the clear at rest: ' . $plain );
		}
		$this->assertTrue( ZDZ_Core_Google_Business::is_connected() );
		$this->assertSame( 'ok', ZDZ_Core_Google_Business::status()['state'] );

		$this->assertSame( self::ACCESS, ZDZ_Core_Google_Business::access_token(), 'the kept access token is used' );
		$this->assertCount( 1, self::calls(), 'with no second call' );

		// A row holding a plain string is not trusted as a sign-in.
		$GLOBALS['zdz_test_options']['zdz_core_gbp_token']['refresh'] = self::REFRESH;
		$this->assertFalse( ZDZ_Core_Google_Business::is_connected() );
	}

	public function test_a_failed_exchange_stores_nothing_and_its_words_carry_no_secret(): void {
		self::with_client();
		self::answer( ZDZ_Core_Google_Business::TOKEN_URL, 400, array( 'error' => 'invalid_grant', 'error_description' => 'Bad code ' . self::CODE . ' for ' . self::CLIENT_SECRET ) );
		$why = ZDZ_Core_Google_Business::exchange_code( self::CODE, 3 );
		$this->assertSame( 'invalid_grant', $why );
		$this->assertArrayNotHasKey( 'zdz_core_gbp_token', $GLOBALS['zdz_test_options'] );
		$words = ZDZ_Core_Google_Business::words( $why );
		foreach ( array( self::CODE, self::CLIENT_SECRET, 'Bad code' ) as $s ) {
			$this->assertStringNotContainsString( $s, $words );
			$this->assertStringNotContainsString( $s, self::everything() );
		}

		self::answer( ZDZ_Core_Google_Business::TOKEN_URL, 400, array( 'error' => '<b>' . self::CODE . '</b>' ) );
		$this->assertDoesNotMatchRegularExpression( '#[<>/]#', ZDZ_Core_Google_Business::exchange_code( self::CODE, 3 ), "Google's error word is reduced to a key" );

		self::answer( ZDZ_Core_Google_Business::TOKEN_URL, 200, array( 'access_token' => self::ACCESS ) );
		$this->assertSame( 'no_refresh_token', ZDZ_Core_Google_Business::exchange_code( self::CODE, 3 ), 'no lasting sign-in, nothing kept' );
		$this->assertArrayNotHasKey( 'zdz_core_gbp_token', $GLOBALS['zdz_test_options'] );
		$this->assertSame( 'unreachable', ZDZ_Core_Google_Business::exchange_code( self::CODE, 3 ) );
	}

	public function test_an_answer_that_echoes_the_token_never_reaches_the_status(): void {
		self::ready();
		self::answer( ZDZ_Core_Google_Business::TOKEN_URL, 200, array( 'access_token' => self::ACCESS, 'expires_in' => 3599 ) );
		self::answer( self::reviews_prefix(), 500, array( 'error' => array( 'code' => 500, 'status' => 'INTERNAL', 'message' => 'Bearer ' . self::ACCESS . ' ' . self::REFRESH ) ) );
		$r = ZDZ_Core_Google_Business::reviews();
		$this->assertFalse( $r['ok'] );
		$this->assertSame( 'http_500', $r['why'] );
		$this->assertSame( 'error', ZDZ_Core_Google_Business::status()['state'] );
		foreach ( array( self::ACCESS, self::REFRESH, 'Bearer' ) as $s ) {
			$this->assertStringNotContainsString( $s, self::everything() );
			$this->assertStringNotContainsString( $s, ZDZ_Core_Google_Business::words( $r['why'] ) );
		}
	}

	public function test_a_401_refreshes_once_and_a_dead_sign_in_says_reconnect(): void {
		self::ready();
		self::answer( ZDZ_Core_Google_Business::TOKEN_URL, 200, array( 'access_token' => self::ACCESS, 'expires_in' => 3599 ) );
		self::answer( self::reviews_prefix(), 401, array( 'error' => array( 'code' => 401, 'status' => 'UNAUTHENTICATED' ) ) );
		self::answer( ZDZ_Core_Google_Business::TOKEN_URL, 200, array( 'access_token' => self::ACCESS_2, 'expires_in' => 3599 ) );
		self::answer( self::reviews_prefix(), 200, array( 'reviews' => array( array( 'reviewId' => 'r1', 'starRating' => 'FIVE', 'reviewer' => array( 'displayName' => 'Alex' ), 'comment' => 'Fine work.' ) ), 'totalReviewCount' => 1, 'averageRating' => 5 ) );
		$r = ZDZ_Core_Google_Business::reviews();
		$this->assertTrue( $r['ok'] );
		$this->assertSame( 5, $r['reviews'][0]['stars'] );
		$this->assertSame( 1, $r['total'] );
		$c = self::calls();
		$this->assertCount( 4, $c );
		$this->assertSame( 'Bearer ' . self::ACCESS, $c[1][2]['headers']['Authorization'] );
		$this->assertSame( 'Bearer ' . self::ACCESS_2, $c[3][2]['headers']['Authorization'], 'the retry used a fresh token' );
		foreach ( $c as $call ) {
			if ( 0 === strpos( $call[1], 'https://mybusiness' ) ) {
				$this->assertSame( 'GET', $call[0], 'the API is only ever read' );
			}
		}

		delete_transient( ZDZ_Core_Google_Business::T_ACCESS );
		self::answer( ZDZ_Core_Google_Business::TOKEN_URL, 400, array( 'error' => 'invalid_grant' ) );
		$this->assertSame( '', ZDZ_Core_Google_Business::access_token() );
		$this->assertSame( 'reconnect', ZDZ_Core_Google_Business::status()['state'] );
		$this->assertSame( 'no_token', ZDZ_Core_Google_Business::reviews()['why'] );
	}

	/* ── not approved yet ────────────────────────────────────────────────── */

	public function test_classify_reads_not_approved_plainly(): void {
		$this->assertSame( '', ZDZ_Core_Google_Business::classify( 200, array() ) );
		$this->assertSame( 'not_approved', ZDZ_Core_Google_Business::classify( 429, null ) );
		$this->assertSame( 'not_approved', ZDZ_Core_Google_Business::classify( 403, array( 'error' => array( 'status' => 'PERMISSION_DENIED', 'message' => 'My Business API has not been used in project 0 before or it is disabled.' ) ) ) );
		$this->assertSame( 'not_approved', ZDZ_Core_Google_Business::classify( 403, array( 'error' => array( 'status' => 'PERMISSION_DENIED', 'details' => array( array( 'reason' => 'SERVICE_DISABLED' ) ) ) ) ) );
		$this->assertSame( 'not_approved', ZDZ_Core_Google_Business::classify( 400, array( 'error' => array( 'status' => 'RESOURCE_EXHAUSTED' ) ) ) );
		$this->assertSame( 'denied', ZDZ_Core_Google_Business::classify( 403, array( 'error' => array( 'status' => 'PERMISSION_DENIED', 'message' => 'The caller does not have permission' ) ) ) );
		$this->assertSame( 'http_500', ZDZ_Core_Google_Business::classify( 500, array( 'error' => 'a string, not an object' ) ) );
		$this->assertStringContainsString( 'Not approved yet', ZDZ_Core_Google_Business::words( 'not_approved' ) );
	}

	public function test_not_approved_is_said_asked_hourly_and_the_tile_shows_the_typed_number(): void {
		self::ready();
		self::answer( ZDZ_Core_Google_Business::TOKEN_URL, 200, array( 'access_token' => self::ACCESS, 'expires_in' => 3599 ) );
		self::answer( self::reviews_prefix(), 429, array( 'error' => array( 'code' => 429, 'status' => 'RESOURCE_EXHAUSTED', 'message' => 'Quota exceeded' ) ) );
		$this->assertNull( ZDZ_Core_Google_Business::summary( true ) );
		$this->assertSame( 'not_approved', ZDZ_Core_Google_Business::status()['state'] );
		$this->assertSame( 'not_approved', ZDZ_Core_Google_Business::fallback_reason() );
		self::answer( self::reviews_prefix(), 403, array( 'error' => array( 'code' => 403, 'status' => 'PERMISSION_DENIED', 'message' => 'The API has not been used in this project before or it is disabled.' ) ) );
		$this->assertSame( 'not_approved', ZDZ_Core_Google_Business::reviews()['why'], 'every call says so' );
		$this->assertCount( 3, self::calls() );

		$tile = self::google_tile();
		$this->assertNotSame( 'error', $tile['state'], 'not an error: the typed number' );
		$this->assertSame( 'entered', $tile['as_of_kind'] );
		$this->assertStringContainsString( 'not approved yet', $tile['message'] . ' ' . $tile['detail'] );
		$this->assertStringContainsString( 'has not approved', $tile['definition'] );
		$this->assertCount( 3, self::calls(), 'the tile did not ask again inside the hour' );

		ZDZ_Core_Google_Business::$now = self::NOW + 3000;
		$this->assertNull( ZDZ_Core_Google_Business::summary( true ) );
		$this->assertCount( 3, self::calls(), 'still inside the hour: no call' );

		ZDZ_Core_Google_Business::$now = self::NOW + ZDZ_Core_Google_Business::NOT_APPROVED_TTL + 1;
		self::answer( ZDZ_Core_Google_Business::TOKEN_URL, 200, array( 'access_token' => self::ACCESS_2, 'expires_in' => 3599 ) );
		self::answer( self::reviews_prefix(), 200, array( 'totalReviewCount' => 7, 'averageRating' => 4.86 ) );
		$sum = ZDZ_Core_Google_Business::summary( true );
		$this->assertSame( 7, $sum['total'], 'an hour later it asks again, and approval shows at once' );
		$this->assertSame( 4.9, $sum['average'] );
		$this->assertSame( '', ZDZ_Core_Google_Business::fallback_reason() );
	}

	/* ── the cache window ────────────────────────────────────────────────── */

	public function test_a_figure_younger_than_an_hour_is_used_without_a_call(): void {
		self::ready();
		self::answer( ZDZ_Core_Google_Business::TOKEN_URL, 200, array( 'access_token' => self::ACCESS, 'expires_in' => 3599 ) );
		self::answer( self::reviews_prefix(), 200, array( 'totalReviewCount' => 12, 'averageRating' => 4.66 ) );
		$this->assertSame( array( 'total' => 12, 'average' => 4.7, 'asked_at' => self::NOW ), ZDZ_Core_Google_Business::summary( true ) );
		$this->assertCount( 2, self::calls() );
		$this->assertStringContainsString( 'pageSize=1', self::calls()[1][1], 'a one-review page' );

		ZDZ_Core_Google_Business::$now = self::NOW + ZDZ_Core_Google_Business::SUMMARY_TTL - 1;
		$this->assertSame( 12, ZDZ_Core_Google_Business::summary( true )['total'] );
		$this->assertCount( 2, self::calls(), 'inside the hour: no call' );
		$this->assertSame( 12, ZDZ_Core_Google_Business::summary( false )['total'], 'without leave to ask, the cached figure still reads' );

		ZDZ_Core_Google_Business::$now = self::NOW + ZDZ_Core_Google_Business::SUMMARY_TTL + 1;
		$this->assertNull( ZDZ_Core_Google_Business::summary( false ), 'past the hour, without leave to ask: nothing' );
		self::answer( ZDZ_Core_Google_Business::TOKEN_URL, 200, array( 'access_token' => self::ACCESS_2, 'expires_in' => 3599 ) );
		self::answer( self::reviews_prefix(), 200, array( 'totalReviewCount' => 13, 'averageRating' => 4.7 ) );
		$this->assertSame( 13, ZDZ_Core_Google_Business::summary( true )['total'], 'past the hour: asked once' );
		$this->assertCount( 4, self::calls(), 'the access token had expired too: one refresh, one read' );

		$tile = self::google_tile();
		$this->assertSame( 'ok', $tile['state'] );
		$this->assertSame( 13, $tile['value'] );
		$this->assertSame( 'synced', $tile['as_of_kind'] );
		$this->assertSame( self::NOW + ZDZ_Core_Google_Business::SUMMARY_TTL + 1, $tile['as_of'] );
	}

	public function test_after_a_failure_no_one_asks_for_five_minutes(): void {
		self::ready();
		self::answer( ZDZ_Core_Google_Business::TOKEN_URL, 200, array( 'access_token' => self::ACCESS, 'expires_in' => 3599 ) );
		self::answer( self::reviews_prefix(), 503, '' );
		$this->assertNull( ZDZ_Core_Google_Business::summary( true ) );
		$this->assertCount( 2, self::calls() );
		$this->assertSame( '', ZDZ_Core_Google_Business::fallback_reason(), 'a failure is not a reason to fall back' );
		$this->assertSame( 'error', self::google_tile()['state'], 'the tile says Google could not be asked' );
		$this->assertCount( 2, self::calls() );

		ZDZ_Core_Google_Business::$now = self::NOW + ZDZ_Core_Google_Business::FAIL_TTL + 1;
		self::answer( self::reviews_prefix(), 200, array( 'totalReviewCount' => 3 ) );
		$this->assertSame( 3, ZDZ_Core_Google_Business::summary( true )['total'] );
		$this->assertNull( ZDZ_Core_Google_Business::summary( true )['average'], 'no average from Google: none, never a guess' );
		$this->assertCount( 3, self::calls(), 'the kept access token was still good' );
	}

	/* ── a copy of the site ──────────────────────────────────────────────── */

	public function test_a_copy_of_the_site_calls_nothing(): void {
		self::ready();
		self::staging( true );
		self::answer( ZDZ_Core_Google_Business::TOKEN_URL, 200, array( 'access_token' => self::ACCESS, 'refresh_token' => self::REFRESH ) );
		self::answer( self::reviews_prefix(), 200, array( 'totalReviewCount' => 9 ) );

		$this->assertTrue( ZDZ_Core_Google_Business::is_ready(), 'set up, as the copied database says' );
		$this->assertSame( '', ZDZ_Core_Google_Business::connect_url( 3 ), 'no sign-in' );
		$this->assertSame( 'staging', ZDZ_Core_Google_Business::exchange_code( self::CODE, 3 ) );
		$this->assertSame( '', ZDZ_Core_Google_Business::access_token(), 'no refresh' );
		$this->assertSame( 'staging', ZDZ_Core_Google_Business::reviews()['why'], 'no read' );
		$this->assertSame( 'staging', ZDZ_Core_Google_Business::api_get( ZDZ_Core_Google_Business::ACCOUNTS_URL )[2] );
		$this->assertSame( array( array(), 'staging' ), ZDZ_Core_Google_Business::find_locations() );
		$this->assertNull( ZDZ_Core_Google_Business::summary( true ) );
		$this->assertSame( 'staging', ZDZ_Core_Google_Business::fallback_reason() );

		$tile = self::google_tile();
		$this->assertSame( 'entered', $tile['as_of_kind'], 'the tile shows the typed number' );
		$this->assertStringContainsString( 'copy of the site', $tile['message'] . ' ' . $tile['detail'] );

		$this->assertSame( array(), self::calls(), 'not one request left the copy' );
		$this->assertSame( self::REFRESH, ZDZ_Core_Crypto::plain( $GLOBALS['zdz_test_options']['zdz_core_gbp_token']['refresh'] ), "the live site's sign-in is untouched" );
	}

	/* ── the location ────────────────────────────────────────────────────── */

	public function test_only_a_location_named_as_google_names_it_is_read(): void {
		self::ready();
		$this->assertSame( 'locations/2002', ZDZ_Core_Google_Business::location()['location'] );
		foreach ( array( '../accounts/1001', 'accounts/1001/locations/2002', 'accounts/', 'https://example.com/x' ) as $bad ) {
			$GLOBALS['zdz_test_options']['zdz_core_gbp_location']['account'] = $bad;
			$this->assertSame( array(), ZDZ_Core_Google_Business::location(), 'refused: ' . $bad );
			$this->assertFalse( ZDZ_Core_Google_Business::is_ready() );
		}
		$this->assertSame( 'no_location', ZDZ_Core_Google_Business::reviews()['why'] );

		self::answer( ZDZ_Core_Google_Business::TOKEN_URL, 200, array( 'access_token' => self::ACCESS, 'expires_in' => 3599 ) );
		self::answer( ZDZ_Core_Google_Business::ACCOUNTS_URL, 200, array( 'accounts' => array( array( 'name' => 'accounts/1001' ), array( 'name' => 'accounts/../9' ), 'x' ) ) );
		self::answer( sprintf( ZDZ_Core_Google_Business::LOCATIONS_FMT, 'accounts/1001' ), 200, array( 'locations' => array(
			array( 'name' => 'locations/2002', 'title' => 'Example Co', 'storefrontAddress' => array( 'addressLines' => array( '1 Example Street' ), 'locality' => 'Exampleton' ) ),
			array( 'name' => 'locations/2002?x=1', 'title' => 'Refused' ),
		) ) );
		list( $found, $why ) = ZDZ_Core_Google_Business::find_locations();
		$this->assertSame( '', $why );
		$this->assertSame( array( array( 'account' => 'accounts/1001', 'location' => 'locations/2002', 'title' => 'Example Co', 'address' => '1 Example Street, Exampleton' ) ), $found );
		$this->assertCount( 3, self::calls(), 'the refused account was never asked' );
	}

	/* ── the Credentials block ───────────────────────────────────────────── */

	public function test_the_credentials_block_prints_no_secret(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			$GLOBALS['zdz_test_user_caps'][3] = array( 'manage_options' );
			if ( ! current_user_can( 'manage_options' ) ) {
				$this->markTestSkipped( 'another test file defined current_user_can() as always false' );
			}
		}
		self::ready();
		set_transient( ZDZ_Core_Google_Business::T_SUMMARY, array( 'total' => 12, 'average' => 4.7, 'asked_at' => self::NOW ), 3600 );
		set_transient( ZDZ_Core_Google_Business::T_ACCESS, array( 't' => ZDZ_Core_Crypto::encode( self::ACCESS, ZDZ_Core_Crypto::FIXED ), 'exp' => self::NOW + 600 ), 600 );
		update_option( ZDZ_Core_Google_Business::OPT_STATUS, array( 'state' => 'not_approved', 'at' => self::NOW, 'why' => 'quota 0 or API not enabled' ) );
		ob_start();
		ZDZ_Core_Google_Business::render_for_service( 'google_business' );
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'admin.php?page=zdz-google-business-callback', $html, 'the redirect address to paste' );
		$this->assertStringContainsString( 'Example Co', $html );
		$this->assertStringContainsString( 'Not approved yet', $html );
		$this->assertStringContainsString( 'zdz_gbp_disconnect', $html );
		$sealed = array(
			ZDZ_Core_Crypto::encode( self::CLIENT_ID, ZDZ_Core_Crypto::FIXED ),
			ZDZ_Core_Crypto::encode( self::CLIENT_SECRET, ZDZ_Core_Crypto::FIXED ),
			ZDZ_Core_Crypto::encode( self::REFRESH, ZDZ_Core_Crypto::FIXED ),
		);
		foreach ( array_merge( array( self::CLIENT_ID, self::CLIENT_SECRET, self::REFRESH, self::ACCESS ), $sealed ) as $s ) {
			$this->assertStringNotContainsString( $s, $html );
		}
		$this->assertSame( array(), self::calls(), 'drawing the block asks Google nothing' );

		ob_start();
		ZDZ_Core_Google_Business::render_for_service( 'stripe' );
		$this->assertSame( '', ob_get_clean(), 'drawn under its own entry only' );

		self::staging( true );
		ob_start();
		ZDZ_Core_Google_Business::render_block();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'copy of the live site', $html );
		$this->assertStringNotContainsString( 'zdz_gbp_connect', $html, 'no Connect on a copy' );
		$this->assertStringNotContainsString( 'zdz_gbp_test', $html );
	}

	/* ── housekeeping ────────────────────────────────────────────────────── */

	public function test_salt_watch_and_export_lines(): void {
		$this->assertSame( array(), ZDZ_Core_Google_Business::salt_breaks( array(), array( 'auth' ) ), 'not connected: nothing to say' );
		self::ready();
		$this->assertCount( 1, ZDZ_Core_Google_Business::salt_breaks( array(), array( 'auth' ) ) );
		$this->assertSame( array(), ZDZ_Core_Google_Business::salt_breaks( array(), array( 'logged_in' ) ) );
		$this->assertContains( 'zdz_core_gbp_token', ZDZ_Core_Google_Business::export_secret_names( array() ) );
		$re = ZDZ_Core_Google_Business::export_non_portable( array() )[0];
		$this->assertMatchesRegularExpression( $re, 'zdz_core_gbp_token' );
		$this->assertMatchesRegularExpression( $re, 'zdz_core_gbp_status' );
		$this->assertDoesNotMatchRegularExpression( $re, 'zdz_core_gbp_location', "the location is the business's choice and travels" );
	}
}
