<?php
/**
 * Demo Mode switches one device, and every way out is guarded.
 *
 * Standalone (its own PHP process: it stands in for WordPress's login sessions).
 * Run: php tests/unit/test-kiosk-demo.php
 *
 *   - The record lives in the device's own sign-in: another device of the same administrator is never switched.
 *   - A device in Demo Mode is found by its auth cookie too, so deleting the logged-in cookie never makes it the
 *     administrator again.
 *   - Entering is refused on a request already running as the shared account (no PIN can be overwritten).
 *   - Wrong PINs lock exit (5 → 15 minutes, 10 → an hour, 15 on → a day); a right PIN while locked still waits.
 *   - The stored target must still be the shared General account; with none, the request runs signed out.
 *   - An administrator who lost manage_options is not switched, and the stale record goes.
 *   - An earlier account-wide record moves to the one sign-in it was bound to, or to every sign-in when unbound.
 *   - The administrator's reset ends Demo Mode or signs the device out; an unknown device is "gone".
 */

error_reporting( E_ALL & ~E_DEPRECATED );
if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', __DIR__ . '/' );
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) define( 'MINUTE_IN_SECONDS', 60 );
if ( ! defined( 'DAY_IN_SECONDS' ) ) define( 'DAY_IN_SECONDS', 86400 );
define( 'ZDZ_REST_NS', 'zorderz/v1' );

$GLOBALS['meta']  = array();   // uid => key => value
$GLOBALS['opts']  = array();
$GLOBALS['roles'] = array( 1 => array( 'administrator' ), 2 => array( 'zdz_general' ), 3 => array( 'zdz_sales' ), 4 => array( 'administrator' ) );
$GLOBALS['caps']  = array( 1 => array( 'manage_options' ), 4 => array( 'manage_options' ) );
$GLOBALS['logged_in_token'] = '';
$GLOBALS['auth_token']      = '';
$GLOBALS['current']         = 0;

class WP_Error {
	private $c; private $m; private $d;
	public function __construct( $c = '', $m = '', $d = '' ) { $this->c = $c; $this->m = $m; $this->d = $d; }
	public function get_error_code() { return $this->c; }
	public function get_error_message() { return $this->m; }
	public function get_error_data() { return $this->d; }
}
class WP_REST_Request {
	private $p;
	public function __construct( array $p = array() ) { $this->p = $p; }
	public function get_param( $k ) { return $this->p[ $k ] ?? null; }
}
class WP_REST_Server { const CREATABLE = 'POST'; const READABLE = 'GET'; }
/** WordPress's user-meta session store, as far as this class uses it. */
class WP_Session_Tokens {
	private $uid;
	public static function get_instance( $uid ) { $o = new self(); $o->uid = (int) $uid; return $o; }
	private function all() { return (array) ( $GLOBALS['meta'][ $this->uid ]['session_tokens'] ?? array() ); }
	private function put( array $s ) { $GLOBALS['meta'][ $this->uid ]['session_tokens'] = $s; }
	public function get( $token ) { $a = $this->all(); return $a[ hash( 'sha256', $token ) ] ?? null; }
	public function update( $token, $session ) { $a = $this->all(); $a[ hash( 'sha256', $token ) ] = $session; $this->put( $a ); }
	public function destroy( $token ) { $a = $this->all(); unset( $a[ hash( 'sha256', $token ) ] ); $this->put( $a ); }
	public function verify( $token ) { return null !== $this->get( $token ); }
}
function add_action( ...$a ) { return true; }
function add_filter( ...$a ) { return true; }
function apply_filters( $h, $v, ...$a ) { return $v; }
function get_option( $k, $d = false ) { return $GLOBALS['opts'][ $k ] ?? $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['opts'][ $k ] = $v; return true; }
function get_user_meta( $u, $k, $s = false ) { return $GLOBALS['meta'][ (int) $u ][ $k ] ?? ''; }
function update_user_meta( $u, $k, $v ) { $GLOBALS['meta'][ (int) $u ][ $k ] = $v; return true; }
function delete_user_meta( $u, $k ) { unset( $GLOBALS['meta'][ (int) $u ][ $k ] ); return true; }
function get_userdata( $u ) { return isset( $GLOBALS['roles'][ (int) $u ] ) ? (object) array( 'ID' => (int) $u, 'roles' => $GLOBALS['roles'][ (int) $u ], 'display_name' => 'Person ' . $u ) : false; }
function user_can( $u, $c ) { return in_array( $c, (array) ( $GLOBALS['caps'][ (int) $u ] ?? array() ), true ); }
function get_users( $a = array() ) {
	$out = array();
	foreach ( $GLOBALS['roles'] as $id => $r ) { if ( in_array( $a['role'] ?? '', $r, true ) ) { $out[] = $id; } }
	sort( $out );
	return $out;
}
function wp_get_session_token() { return (string) $GLOBALS['logged_in_token']; }
function wp_parse_auth_cookie( $c = '', $scheme = '' ) { return '' !== $GLOBALS['auth_token'] ? array( 'token' => $GLOBALS['auth_token'] ) : false; }
function wp_validate_auth_cookie( $c = '', $s = '' ) { return 0; }
function wp_hash_password( $p ) { return password_hash( $p, PASSWORD_DEFAULT ); }
function wp_check_password( $p, $h ) { return '' !== $h && password_verify( $p, $h ); }
function current_time( $t ) { return gmdate( 'Y-m-d H:i:s' ); }
function home_url( $p = '' ) { return 'https://app.example.com/' . ltrim( $p, '/' ); }
function rest_ensure_response( $r ) { return $r; }
function wp_set_current_user( $u ) { $GLOBALS['current'] = (int) $u; }
function get_current_user_id() { return (int) $GLOBALS['current']; }
function wp_create_nonce( $a ) { return 'nonce-' . $GLOBALS['current']; }
function wp_cache_delete( ...$a ) { return true; }
function is_user_logged_in() { return true; }
function wp_unslash( $v ) { return $v; }

require __DIR__ . '/../../zorderz/inc/class-zdz-kiosk-demo.php';

$pass = 0; $fail = 0;
function ok( bool $c, string $m ): void {
	global $pass, $fail;
	if ( $c ) { $pass++; echo "  ok   $m\n"; } else { $fail++; echo "  FAIL $m\n"; }
}
/** A fresh request: the static per-request state of the class is cleared. */
function new_request( string $logged_in, string $auth = '' ): void {
	$GLOBALS['logged_in_token'] = $logged_in;
	$GLOBALS['auth_token']      = '' !== $auth ? $auth : $logged_in;
	$r = new ReflectionClass( 'ZDZ_Kiosk_Demo' );
	foreach ( array( 'target_uid_cache' => null, 'real_uid' => null, 'switched' => false, 'migrated' => array(), 'current_target' => 0, 'held' => array() ) as $k => $v ) {
		$p = $r->getProperty( $k );
		$p->setAccessible( true );
		$p->setValue( null, $v );
	}
}
function signed_in( int $uid, string $token ): void {
	$s = (array) ( $GLOBALS['meta'][ $uid ]['session_tokens'] ?? array() );
	$s[ hash( 'sha256', $token ) ] = array( 'expiration' => time() + 3600, 'login' => time() - 60 );
	$GLOBALS['meta'][ $uid ]['session_tokens'] = $s;
}
function err( $r ): string { return $r instanceof WP_Error ? $r->get_error_code() : ''; }

signed_in( 1, 'phone-token' );
signed_in( 1, 'laptop-token' );

// ── Lock steps ──────────────────────────────────────────────────────────────
ok( 0 === ZDZ_Kiosk_Demo::lock_minutes( 4 ), 'four wrong PINs: no wait' );
ok( 15 === ZDZ_Kiosk_Demo::lock_minutes( 5 ), 'five: 15 minutes' );
ok( 0 === ZDZ_Kiosk_Demo::lock_minutes( 7 ), 'after a wait, more tries before the next' );
ok( 60 === ZDZ_Kiosk_Demo::lock_minutes( 10 ), 'ten: an hour' );
ok( 1440 === ZDZ_Kiosk_Demo::lock_minutes( 15 ) && 1440 === ZDZ_Kiosk_Demo::lock_minutes( 22 ), 'fifteen on: a day each' );

// ── Who the shared account may be ───────────────────────────────────────────
ok( ZDZ_Kiosk_Demo::is_kiosk_account( 2, 1 ), 'the General account is a target' );
ok( ! ZDZ_Kiosk_Demo::is_kiosk_account( 3, 1 ), 'a salesperson never is' );
ok( ! ZDZ_Kiosk_Demo::is_kiosk_account( 1, 1 ), 'the administrator never is' );

// ── Entering on the phone ───────────────────────────────────────────────────
new_request( 'phone-token' );
ok( 1 === ZDZ_Kiosk_Demo::filter_current_user( 1 ), 'before Demo Mode the phone runs as the administrator' );
ok( ZDZ_Kiosk_Demo::can_enter(), 'the administrator may enter' );
ok( 'bad_pin' === err( ZDZ_Kiosk_Demo::rest_enter( new WP_REST_Request( array( 'pin' => '12' ) ) ) ), 'a PIN of two digits is refused' );
$r = ZDZ_Kiosk_Demo::rest_enter( new WP_REST_Request( array( 'pin' => '4321' ) ) );
ok( is_array( $r ) && ! empty( $r['active'] ), 'Demo Mode on for this device' );
$sess = $GLOBALS['meta'][1]['session_tokens'];
ok( ! empty( $sess[ hash( 'sha256', 'phone-token' ) ]['zdz_kiosk_demo']['active'] ), 'the record is in the phone\'s sign-in' );
ok( empty( $sess[ hash( 'sha256', 'laptop-token' ) ]['zdz_kiosk_demo'] ), 'and not in the laptop\'s' );
ok( false === strpos( json_encode( $sess ), '4321' ), 'the PIN is kept only as a hash' );

new_request( 'phone-token' );
ok( 2 === ZDZ_Kiosk_Demo::filter_current_user( 1 ), 'the phone now runs as the General account' );
ok( ! ZDZ_Kiosk_Demo::can_enter(), 'a request in Demo Mode cannot enter again' );
ok( 'forbidden' === err( ZDZ_Kiosk_Demo::rest_enter( new WP_REST_Request( array( 'pin' => '9999' ) ) ) ), 'nor set a new PIN' );

new_request( 'laptop-token' );
ok( 1 === ZDZ_Kiosk_Demo::filter_current_user( 1 ), 'the laptop stays the administrator' );

new_request( '', 'phone-token' );
ok( 2 === ZDZ_Kiosk_Demo::filter_current_user( 1 ), 'the logged-in cookie deleted: the auth cookie still finds Demo Mode' );

// ── Leaving: wrong PINs lock ────────────────────────────────────────────────
for ( $i = 1; $i <= 4; $i++ ) {
	new_request( 'phone-token' );
	ZDZ_Kiosk_Demo::filter_current_user( 1 );
	ok( 'bad_pin' === err( ZDZ_Kiosk_Demo::rest_exit( new WP_REST_Request( array( 'pin' => '0000' ) ) ) ), "wrong PIN $i" );
}
new_request( 'phone-token' );
ZDZ_Kiosk_Demo::filter_current_user( 1 );
ok( 'locked' === err( ZDZ_Kiosk_Demo::rest_exit( new WP_REST_Request( array( 'pin' => '0000' ) ) ) ), 'the fifth locks exit' );
new_request( 'phone-token' );
ZDZ_Kiosk_Demo::filter_current_user( 1 );
ok( 'locked' === err( ZDZ_Kiosk_Demo::rest_exit( new WP_REST_Request( array( 'pin' => '4321' ) ) ) ), 'even the right PIN waits while locked' );
new_request( 'phone-token' );
ok( 2 === ZDZ_Kiosk_Demo::filter_current_user( 1 ), 'and the phone is still in Demo Mode' );

// The wait passes.
$k = hash( 'sha256', 'phone-token' );
$GLOBALS['meta'][1]['session_tokens'][ $k ]['zdz_kiosk_demo']['locked_until'] = time() - 1;
new_request( 'phone-token' );
ZDZ_Kiosk_Demo::filter_current_user( 1 );
$r = ZDZ_Kiosk_Demo::rest_exit( new WP_REST_Request( array( 'pin' => '4321' ) ) );
ok( is_array( $r ) && empty( $r['active'] ), 'the right PIN after the wait leaves Demo Mode' );
new_request( 'phone-token' );
ok( 1 === ZDZ_Kiosk_Demo::filter_current_user( 1 ), 'the phone is the administrator again' );

// ── The stored target is checked every time ─────────────────────────────────
new_request( 'phone-token' );
ZDZ_Kiosk_Demo::filter_current_user( 1 );   // WordPress resolves the user first
ZDZ_Kiosk_Demo::rest_enter( new WP_REST_Request( array( 'pin' => '2468' ) ) );
$GLOBALS['meta'][1]['session_tokens'][ $k ]['zdz_kiosk_demo']['target_user_id'] = 3;   // a salesperson
new_request( 'phone-token' );
ok( 2 === ZDZ_Kiosk_Demo::filter_current_user( 1 ), 'a target that is not General falls back to the General account' );
$GLOBALS['roles'][2] = array( 'zdz_sales' );   // no General account left
new_request( 'phone-token' );
ok( 0 === ZDZ_Kiosk_Demo::filter_current_user( 1 ), 'with no General account the request runs signed out, never as the administrator' );
$GLOBALS['roles'][2] = array( 'zdz_general' );

$GLOBALS['caps'][1] = array();   // no longer an administrator
new_request( 'phone-token' );
ok( 1 === ZDZ_Kiosk_Demo::filter_current_user( 1 ), 'an account that lost manage_options is not switched' );
ok( empty( $GLOBALS['meta'][1]['session_tokens'][ $k ]['zdz_kiosk_demo'] ), 'and its stale record goes' );
$GLOBALS['caps'][1] = array( 'manage_options' );

// ── The administrator's reset ───────────────────────────────────────────────
new_request( 'phone-token' );
ZDZ_Kiosk_Demo::filter_current_user( 1 );
ZDZ_Kiosk_Demo::rest_enter( new WP_REST_Request( array( 'pin' => '1357' ) ) );
ok( 'gone' === ZDZ_Kiosk_Demo::reset_device( 1, str_repeat( 'a', 64 ), 'end' ), 'an unknown device is gone' );
ok( 'ended' === ZDZ_Kiosk_Demo::reset_device( 1, $k, 'end' ), 'End Demo Mode' );
new_request( 'phone-token' );
ok( 1 === ZDZ_Kiosk_Demo::filter_current_user( 1 ), 'the device is the administrator\'s again' );
new_request( 'phone-token' );
ZDZ_Kiosk_Demo::filter_current_user( 1 );
ZDZ_Kiosk_Demo::rest_enter( new WP_REST_Request( array( 'pin' => '1357' ) ) );
ok( 'signed_out' === ZDZ_Kiosk_Demo::reset_device( 1, $k, 'sign_out' ), 'Sign the device out' );
ok( ! isset( $GLOBALS['meta'][1]['session_tokens'][ $k ] ), 'its sign-in is gone' );

// ── An earlier account-wide record ──────────────────────────────────────────
signed_in( 4, 'tab-a' );
signed_in( 4, 'tab-b' );
$GLOBALS['meta'][4]['zdz_kiosk_demo'] = array( 'active' => true, 'pin_hash' => wp_hash_password( '8642' ), 'target_user_id' => 2, 'session' => 'tab-a' );
new_request( 'tab-b' );
ok( 4 === ZDZ_Kiosk_Demo::filter_current_user( 4 ), 'a record bound to one sign-in never switches another' );
new_request( 'tab-a' );
ok( 2 === ZDZ_Kiosk_Demo::filter_current_user( 4 ), 'and the sign-in it was bound to stays in Demo Mode' );
ok( ! isset( $GLOBALS['meta'][4]['zdz_kiosk_demo'] ), 'the account-wide record is deleted once moved' );

signed_in( 1, 'desk-token' );
$GLOBALS['meta'][1]['zdz_kiosk_demo'] = array( 'active' => true, 'pin_hash' => wp_hash_password( '8642' ), 'target_user_id' => 2 );
new_request( 'desk-token' );
ok( 2 === ZDZ_Kiosk_Demo::filter_current_user( 1 ), 'an unbound record keeps every sign-in in Demo Mode (fail closed)' );
new_request( 'laptop-token' );
ok( 2 === ZDZ_Kiosk_Demo::filter_current_user( 1 ), 'the laptop too' );

// ── The device label ────────────────────────────────────────────────────────
$ua = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1';
ok( 'iPhone · Safari' === ZDZ_Kiosk_Demo::device_label( $ua ), 'a short device label' );
ok( false === strpos( ZDZ_Kiosk_Demo::device_label( $ua ), 'Mozilla' ), 'never the full agent' );

echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
