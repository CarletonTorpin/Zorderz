<?php
/**
 * The app's keep-alive (admin-ajax zdz_fresh_nonce), as its handler is written in functions.php.
 *
 * An account in Privacy View has every reply masked, and a value under most keys could come back rewritten: a
 * nonce that happens to read like a phone number would reach the page as a code, and the page's next call would
 * fail. The masks always leave the key "nonce" whole, so the handler answers the same nonce under "nonce" (read
 * first by the page) and under "rest" (read by pages and widgets that already did).
 *
 * This script runs the handler itself with stand-ins for the four WordPress functions it calls. It pins:
 *   - signed in: one nonce for the REST API, the same value under "nonce" and "rest", and "demo";
 *   - signed out: "not_logged_in" with status 401, and no nonce made;
 *   - the action is for a signed-in user only (no nopriv hook).
 *
 * Run: php tests/unit/test-privacy-view-keepalive.php
 */
error_reporting( E_ALL & ~E_DEPRECATED );

$pass = 0;
$fail = 0;
function ok( bool $c, string $m ): void {
	global $pass, $fail;
	if ( $c ) {
		$pass++;
		echo "  ok    $m\n";
	} else {
		$fail++;
		echo "  FAIL  $m\n";
	}
}

/** WordPress stops the request inside wp_send_json_error(); the stand-in stops the handler the same way. */
final class ZDZ_Test_Keepalive_Stop extends Exception {}

$signed_in = true;
$sent      = array();
$made      = array();
function is_user_logged_in() {
	global $signed_in;
	return $signed_in;
}
function wp_create_nonce( $action = -1 ) {
	global $made;
	$made[] = $action;
	return 'wp_rest' === $action ? 'c0ffee1234' : 'another0000';
}
function wp_send_json_success( $data = null, $status = null ) {
	global $sent;
	$sent[] = array( 'ok', $data, $status );
}
function wp_send_json_error( $data = null, $status = null ) {
	global $sent;
	$sent[] = array( 'error', $data, $status );
	throw new ZDZ_Test_Keepalive_Stop();
}

$fn    = (string) file_get_contents( __DIR__ . '/../../zorderz/functions.php' );
$open  = '$zdz_fresh_nonce = function () {';
$at    = strpos( $fn, $open );
$end   = false === $at ? false : strpos( $fn, "\n};", $at );
$found = false !== $at && false !== $end;
ok( $found, 'functions.php has the keep-alive handler' );
ok( 1 === substr_count( $fn, "add_action( 'wp_ajax_zdz_fresh_nonce', \$zdz_fresh_nonce );" ), 'it answers the admin-ajax action zdz_fresh_nonce' );
ok( false === strpos( $fn, 'wp_ajax_nopriv_zdz_fresh_nonce' ), 'for a signed-in user only' );

if ( $found ) {
	$text    = substr( $fn, $at + strlen( '$zdz_fresh_nonce = ' ), $end + 2 - $at - strlen( '$zdz_fresh_nonce = ' ) );
	$handler = eval( 'return ' . $text . ';' ); // sentinel:allow the handler's own text from this repository's functions.php, run in a test process with stand-ins
	ok( $handler instanceof Closure, 'the handler runs as written' );

	if ( $handler instanceof Closure ) {
		$handler();
		$reply = $sent[0][1] ?? null;
		ok( 1 === count( $sent ) && 'ok' === $sent[0][0] && is_array( $reply ), 'signed in: one reply' );
		ok( array( 'wp_rest' ) === $made, 'one nonce made, for the REST API' );
		ok( is_array( $reply ) && 'c0ffee1234' === ( $reply['nonce'] ?? null ), 'the nonce is under "nonce"' );
		ok( is_array( $reply ) && 'c0ffee1234' === ( $reply['rest'] ?? null ), '... and the same value under "rest"' );
		ok( is_array( $reply ) && array_key_exists( 'demo', $reply ) && false === $reply['demo'], '... with "demo" (false here: Demo Mode is not loaded)' );
		ok( is_array( $reply ) && array( 'demo', 'nonce', 'rest' ) === ( function ( $k ) { sort( $k ); return $k; } )( array_keys( $reply ) ), '... and nothing else' );

		$signed_in = false;
		$sent      = array();
		$made      = array();
		$stopped   = false;
		try {
			$handler();
		} catch ( ZDZ_Test_Keepalive_Stop $e ) {
			$stopped = true;
		}
		ok( $stopped && array( array( 'error', 'not_logged_in', 401 ) ) === $sent, 'signed out: "not_logged_in", status 401' );
		ok( array() === $made, '... and no nonce is made' );
	}
}

echo $fail ? "\n$pass passed, $fail failed\n" : "\nAll {$pass} keep-alive assertions passed.\n";
exit( $fail ? 1 : 0 );
