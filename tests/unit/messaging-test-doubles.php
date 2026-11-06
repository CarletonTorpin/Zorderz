<?php
/**
 * Shared stand-ins for the messaging app's unit tests (MessagingNoticesTest, MessagingPrivacyViewTest,
 * MessagingDocSharesTest, MessagingEmailReplyTest, MessagingMembershipTest).
 *
 * Loads the REAL messaging app (app.php for its constants and access predicates, and the classes under test) on top of
 * guarded WordPress stubs that read the same globals the other unit tests use ($GLOBALS['zdz_test_options'],
 * zdz_test_filters, zdz_test_user_meta, zdz_test_user_roles, zdz_test_user_caps, zdz_test_current_user). The database
 * is ZDZ_Test_Msg_DB: it records writes and answers reads from per-test callables, so nothing here needs MySQL.
 *
 * Neutral data only.
 *
 * @package Zorderz\Tests
 */

require_once __DIR__ . '/notify-media-stubs.php';

foreach ( array( 'MINUTE_IN_SECONDS' => 60, 'HOUR_IN_SECONDS' => 3600, 'DAY_IN_SECONDS' => 86400, 'OBJECT' => 'OBJECT', 'ARRAY_A' => 'ARRAY_A' ) as $zdz_msg_c => $zdz_msg_v ) {
	if ( ! defined( $zdz_msg_c ) ) {
		define( $zdz_msg_c, $zdz_msg_v );
	}
}

if ( ! function_exists( 'register_activation_hook' ) ) {
	function register_activation_hook( ...$a ) { return true; }
}
if ( ! function_exists( 'register_deactivation_hook' ) ) {
	function register_deactivation_hook( ...$a ) { return true; }
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( $k, $v, $autoload = null ) { $GLOBALS['zdz_test_options'][ $k ] = $v; return true; }
}
if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( $k ) { unset( $GLOBALS['zdz_test_options'][ $k ] ); return true; }
}
if ( ! function_exists( 'update_user_meta' ) ) {
	function update_user_meta( $uid, $k, $v ) { $GLOBALS['zdz_test_user_meta'][ (int) $uid ][ $k ] = $v; return true; }
}
if ( ! function_exists( 'wp_kses_post' ) ) {
	function wp_kses_post( $s ) { return strip_tags( (string) $s, '<a><strong><em><ul><ol><li><br><p><code><pre>' ); }
}
if ( ! function_exists( 'absint' ) ) {
	function absint( $v ) { return abs( (int) $v ); }
}
if ( ! function_exists( 'has_filter' ) ) {
	function has_filter( $hook ) { return isset( $GLOBALS['zdz_test_filters'][ $hook ] ); }
}
if ( ! function_exists( 'wp_create_nonce' ) ) {
	function wp_create_nonce( $a = '' ) { return 'abc123'; }
}
if ( ! function_exists( 'sanitize_email' ) ) {
	function sanitize_email( $e ) { return filter_var( trim( (string) $e ), FILTER_VALIDATE_EMAIL ) ? trim( (string) $e ) : ''; }
}
if ( ! function_exists( 'wp_list_pluck' ) ) {
	function wp_list_pluck( $list, $field ) { return array_map( function ( $r ) use ( $field ) { return is_object( $r ) ? $r->$field : $r[ $field ]; }, (array) $list ); }
}
if ( ! function_exists( 'sanitize_title' ) ) {
	function sanitize_title( $s ) { return trim( (string) preg_replace( '/[^a-z0-9-]+/', '-', strtolower( (string) $s ) ), '-' ); }
}
if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( $k ) { return $GLOBALS['zdz_test_transients'][ $k ] ?? false; }
}
if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( $k, $v, $ttl = 0 ) { $GLOBALS['zdz_test_transients'][ $k ] = $v; return true; }
}

/** The database stand-in: writes are recorded; each read asks a per-test callable (sql => answer), else a default. */
class ZDZ_Test_Msg_DB {
	public $prefix     = 'wp_';
	public $last_error = '';
	public $insert_id  = 0;
	public $inserted   = array();   // [ table, row ]
	public $queries    = array();   // every SQL string run or read
	public $on_var     = null;
	public $on_results = null;
	public $on_row     = null;
	public $on_col     = null;

	public function prepare( $q, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		$i = 0;
		return (string) preg_replace_callback( '/%[dsf]/', function ( $m ) use ( &$i, $args ) {
			$v = $args[ $i++ ] ?? '';
			return '%d' === $m[0] ? (string) (int) $v : "'" . addslashes( (string) $v ) . "'";
		}, (string) $q );
	}
	private function ask( $h, $sql, $default ) {
		$this->queries[] = (string) $sql;
		return is_callable( $h ) ? call_user_func( $h, (string) $sql ) : $default;
	}
	public function get_var( $sql ) { return $this->ask( $this->on_var, $sql, null ); }
	public function get_results( $sql, $o = 'OBJECT' ) { return $this->ask( $this->on_results, $sql, array() ); }
	public function get_row( $sql, $o = 'OBJECT' ) { return $this->ask( $this->on_row, $sql, null ); }
	public function get_col( $sql ) { return $this->ask( $this->on_col, $sql, array() ); }
	public function insert( $t, $row, $f = null ) {
		$this->inserted[] = array( (string) $t, (array) $row );
		$this->insert_id  = count( $this->inserted );
		return 1;
	}
	public function query( $sql ) { $this->queries[] = (string) $sql; return 0; }
	public function update( ...$a ) { return 1; }
	public function delete( ...$a ) { return 1; }
}

$zdz_msg_root = dirname( __DIR__, 2 );
require_once $zdz_msg_root . '/zorderz/inc/class-zdz-quiet-hours.php';
require_once $zdz_msg_root . '/zorderz/inc/class-zdz-push-setup.php';
require_once $zdz_msg_root . '/zorderz/inc/class-zdz-privacy-view.php';
require_once $zdz_msg_root . '/zorderz-apps/apps/messaging/app.php';
foreach ( array( 'channels', 'messages', 'notifications', 'notices', 'push', 'privacy-view', 'dm-send', 'doc-shares',
	'preview-cards', 'email-reply', 'unread-counts', 'media-cards' ) as $zdz_msg_f ) {
	require_once $zdz_msg_root . '/zorderz-apps/apps/messaging/includes/class-zim-' . $zdz_msg_f . '.php';
}

/** After a test: nothing of it is left for the next file (the whole suite runs in one process). */
function zdz_test_msg_teardown() {
	zdz_test_msg_reset();
	unset( $GLOBALS['wpdb'] );
}

/** Fresh state for one test: no options, people, filters or rows; a new database stand-in. */
function zdz_test_msg_reset() {
	zdz_notify_test_reset();
	unset( $GLOBALS['zdz_test_transients'] );
	$GLOBALS['wpdb']             = new ZDZ_Test_Msg_DB();
	ZIM_Notices::$sender         = null;
	ZIM_Doc_Shares::$reader      = null;
	ZIM_Unread_Counts::$now      = null;
	if ( class_exists( 'ZDZ_Privacy_View' ) ) {
		ZDZ_Privacy_View::flush();
	}
	$r = new ReflectionProperty( 'ZIM_Push', 'has_session_col' );
	$r->setAccessible( true );
	$r->setValue( null, null );
}

/**
 * People for a test: uid => roles. 'admin' gets the administrator role and manage_options; 'staff' a field role with
 * Messages; 'shared' the shared device; 'none' a role without Messages.
 */
function zdz_test_msg_people( array $people ) {
	foreach ( $people as $uid => $kind ) {
		switch ( $kind ) {
			case 'admin':
				$GLOBALS['zdz_test_user_roles'][ $uid ] = array( 'administrator' );
				$GLOBALS['zdz_test_user_caps'][ $uid ]  = array( 'manage_options', 'zdz_access_app' );
				break;
			case 'staff':
				$GLOBALS['zdz_test_user_roles'][ $uid ] = array( 'zdz_tech' );
				$GLOBALS['zdz_test_user_caps'][ $uid ]  = array( 'zdz_access_app' );
				break;
			case 'shared':
				$GLOBALS['zdz_test_user_roles'][ $uid ] = array( 'zdz_general' );
				$GLOBALS['zdz_test_user_caps'][ $uid ]  = array();
				break;
			default:
				$GLOBALS['zdz_test_user_roles'][ $uid ] = array( 'subscriber' );
				$GLOBALS['zdz_test_user_caps'][ $uid ]  = array();
		}
	}
}
