<?php
/**
 * Shared stand-ins for the sign-in routes unit tests (SigninCodesTest, SigninRoutesTest, SigninPageTest,
 * SigninBridgeTest, SigninProfileTest, SigninPrivacyViewTest).
 *
 * Loads the REAL classes (ZDZ_Signin_Routes, ZDZ_Magic_Link_Bridge and the kernel classes they ask: the hierarchy,
 * Privacy View, View As, the notice router, the audit log) on top of guarded WordPress stand-ins that read the
 * globals the other unit tests use ($GLOBALS['zdz_test_options'], zdz_test_user_meta, zdz_test_user_roles,
 * zdz_test_user_caps, zdz_test_user_names, zdz_test_users, zdz_test_transients, zdz_test_current_user).
 *
 * The role and Demo Mode classes are NOT loaded here: the in-process unit tests run without them. What needs them
 * (Demo Mode, the shared-device trait on another role, the wp-config.php line) is in test-signin-routes-standalone.php,
 * which runs in its own PHP process. A test here asserts about Demo Mode only when zdz_test_signin_demo() says the
 * class is loaded.
 *
 * The database is ZDZ_Test_Signin_DB: MySQL's named locks as far as ZDZ_Signin_Routes::lock() cares, and a record of
 * every row written (a notice in the bell, an audit row), so nothing here needs MySQL.
 *
 * A stand-in defined here is used only when no earlier test file defined that function. The few whose exact behaviour
 * a test depends on are named in ZDZ_Test_Signin_Doubles::$own, and such a test skips itself when another file's
 * stand-in is the one in force.
 *
 * Neutral data only (example.com, example.net, example.org).
 *
 * @package Zorderz\Tests
 */

require_once __DIR__ . '/support/privacy-stubs.php';

/** Which of the stand-ins below are the ones in force (see the file comment). */
final class ZDZ_Test_Signin_Doubles {
	public static $own = array();
}

if ( ! class_exists( 'WP_User' ) ) {
	class WP_User {
		public $ID           = 0;
		public $roles        = array();
		public $user_email   = '';
		public $display_name = '';
		public $user_login   = '';
	}
}

if ( ! function_exists( 'get_user_by' ) ) {
	ZDZ_Test_Signin_Doubles::$own['get_user_by'] = true;
	// As WordPress: one of the accounts get_users() searches, by id, e-mail (any letter case) or login; false when none.
	function get_user_by( $field, $value ) {
		foreach ( (array) ( $GLOBALS['zdz_test_users'] ?? array() ) as $u ) {
			if ( ! is_object( $u ) ) {
				continue;
			}
			if ( ( 'id' === strtolower( (string) $field ) && (int) $u->ID === (int) $value )
				|| ( 'email' === $field && '' !== (string) $value && strtolower( (string) ( $u->user_email ?? '' ) ) === strtolower( trim( (string) $value ) ) )
				|| ( 'login' === $field && (string) ( $u->user_login ?? '' ) === (string) $value ) ) {
				return $u;
			}
		}
		return false;
	}
}
if ( ! function_exists( 'update_user_meta' ) ) {
	function update_user_meta( $uid, $key, $value ) { $GLOBALS['zdz_test_user_meta'][ (int) $uid ][ $key ] = $value; return true; }
}
if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( $k ) { unset( $GLOBALS['zdz_test_transients'][ $k ] ); return true; }
}
if ( ! function_exists( 'wp_set_current_user' ) ) {
	function wp_set_current_user( $uid ) { $GLOBALS['zdz_test_current_user'] = (int) $uid; }
}
if ( ! function_exists( 'wp_clear_auth_cookie' ) ) {
	function wp_clear_auth_cookie() {}
}
if ( ! function_exists( 'wp_set_auth_cookie' ) ) {
	ZDZ_Test_Signin_Doubles::$own['wp_set_auth_cookie'] = true;
	function wp_set_auth_cookie( $uid, $remember = false, $secure = '' ) { $GLOBALS['zdz_test_signin_cookie'] = (int) $uid; }
}
if ( ! function_exists( 'is_ssl' ) ) {
	function is_ssl() { return true; }
}
if ( ! function_exists( 'wp_mail' ) ) {
	ZDZ_Test_Signin_Doubles::$own['wp_mail'] = true;
	// Keeps what the site "sent"; answers false when a test says the mailer refuses.
	function wp_mail( $to, $subject, $message, $headers = '', $attachments = array() ) {
		$GLOBALS['zdz_test_signin_mail'][] = array( 'to' => $to, 'subject' => (string) $subject, 'message' => (string) $message );
		return false !== ( $GLOBALS['zdz_test_signin_mail_ok'] ?? true );
	}
}
if ( ! function_exists( 'sanitize_email' ) ) {
	function sanitize_email( $e ) { return filter_var( trim( (string) $e ), FILTER_VALIDATE_EMAIL ) ? trim( (string) $e ) : ''; }
}
if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( $url, $component = -1 ) { return -1 === $component ? parse_url( (string) $url ) : parse_url( (string) $url, $component ); }
}
if ( ! function_exists( 'wp_parse_args' ) ) {
	function wp_parse_args( $a, $d = array() ) { return array_merge( (array) $d, (array) $a ); }
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
if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( $s, $d = null ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
}
if ( ! function_exists( 'esc_attr__' ) ) {
	function esc_attr__( $s, $d = null ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
}
if ( ! function_exists( 'esc_html_e' ) ) {
	function esc_html_e( $s, $d = null ) { echo htmlspecialchars( (string) $s, ENT_QUOTES ); }
}
if ( ! function_exists( 'esc_attr_e' ) ) {
	function esc_attr_e( $s, $d = null ) { echo htmlspecialchars( (string) $s, ENT_QUOTES ); }
}
if ( ! function_exists( 'wp_kses' ) ) {
	// Keeps the named tags (no attributes are allowed by any caller here) and drops every other tag.
	function wp_kses( $s, $allowed = array(), $protocols = array() ) {
		return strip_tags( (string) $s, '<' . implode( '><', array_keys( (array) $allowed ) ) . '>' );
	}
}
if ( ! function_exists( 'wp_nonce_field' ) ) {
	function wp_nonce_field( $a = -1, $n = '_wpnonce', $r = true, $echo = true ) {
		$h = '<input type="hidden" name="' . $n . '" value="nonce">';
		if ( $echo ) {
			echo $h;
		}
		return $h;
	}
}
if ( ! function_exists( 'check_admin_referer' ) ) {
	ZDZ_Test_Signin_Doubles::$own['check_admin_referer'] = true;
	// WordPress dies here; a post with no nonce, or another form's, goes no further. A test posts
	// zdz_test_signin_nonce( $action ) as _wpnonce.
	function check_admin_referer( $action = -1, $query_arg = '_wpnonce' ) {
		if ( ( $_POST[ $query_arg ] ?? '' ) !== zdz_test_signin_nonce( $action ) ) {
			throw new RuntimeException( 'nonce' );
		}
		return 1;
	}
}
if ( ! function_exists( 'wp_verify_nonce' ) ) {
	ZDZ_Test_Signin_Doubles::$own['wp_verify_nonce'] = true;
	function wp_verify_nonce( $nonce, $action = -1 ) { return zdz_test_signin_nonce( $action ) === $nonce ? 1 : false; }
}
if ( ! function_exists( 'add_submenu_page' ) ) {
	ZDZ_Test_Signin_Doubles::$own['add_submenu_page'] = true;
	function add_submenu_page( $parent, $title, $menu, $cap, $slug, $cb = null ) {
		$GLOBALS['zdz_test_signin_pages'][] = array( 'parent' => $parent, 'cap' => $cap, 'slug' => $slug );
		return 'admin_page_' . $slug;
	}
}
if ( ! function_exists( 'register_rest_route' ) ) {
	ZDZ_Test_Signin_Doubles::$own['register_rest_route'] = true;
	function register_rest_route( $ns, $route, $args = array() ) { $GLOBALS['zdz_test_signin_rest'][ $route ] = $args; return true; }
}
if ( ! function_exists( 'wp_cache_delete' ) ) {
	ZDZ_Test_Signin_Doubles::$own['wp_cache_delete'] = true;
	function wp_cache_delete( $k, $g = '' ) { $GLOBALS['zdz_test_signin_cache_drops'][] = $g . ':' . $k; return true; }
}
if ( ! function_exists( 'disabled' ) ) {
	function disabled( $a, $b = true, $echo = true ) {
		$h = $a === $b ? " disabled='disabled'" : '';
		if ( $echo ) {
			echo $h;
		}
		return $h;
	}
}
if ( ! function_exists( 'checked' ) ) {
	function checked( $a, $b = true, $echo = true ) {
		$h = $a === $b ? " checked='checked'" : '';
		if ( $echo ) {
			echo $h;
		}
		return $h;
	}
}
if ( ! function_exists( 'selected' ) ) {
	function selected( $a, $b = true, $echo = true ) {
		$h = $a === $b ? " selected='selected'" : '';
		if ( $echo ) {
			echo $h;
		}
		return $h;
	}
}

/** The nonce a form for $action carries in these tests. */
function zdz_test_signin_nonce( $action ) {
	return 'zdz-test-nonce:' . $action;
}

if ( ! class_exists( 'ZDZ_Test_Signin_DB' ) ) {
	/**
	 * The database, as far as the sign-in classes care. MySQL's named locks: $held are names another connection holds
	 * (GET_LOCK then answers 0); $allow limits how many more locks are given (-1: no limit); $taken / $released /
	 * $waits are what this "connection" did, in order. Every insert is kept in $written (the bell's rows, audit rows).
	 */
	class ZDZ_Test_Signin_DB extends ZDZ_Test_Empty_DB {
		public $prefix      = 'wp_2_';   // one site of a network: a lock's name must not carry it
		public $base_prefix = 'wp_';
		public $held        = array();
		public $allow       = -1;
		public $taken       = array();
		public $released    = array();
		public $waits       = array();
		public $no_get_lock = false;     // a database with no named locks (SQLite, for one): GET_LOCK answers nothing
		public $rows        = array();   // the options-row locks: option_name => when it was made
		public $rows_taken  = 0;
		private $mine       = array();

		public function get_var( $sql ) {
			if ( preg_match( "/SELECT option_value FROM \S+ WHERE option_name = '([^']+)'/", (string) $sql, $m ) ) {
				return $this->rows[ $m[1] ] ?? null;
			}
			if ( preg_match( "/GET_LOCK\('([^']+)', (\d+)\)/", (string) $sql, $m ) ) {
				$this->waits[] = (int) $m[2];
				if ( $this->no_get_lock ) {
					return null;
				}
				if ( in_array( $m[1], $this->held, true ) || 0 === $this->allow ) {
					return '0';
				}
				if ( $this->allow > 0 ) {
					$this->allow--;
				}
				$this->taken[]       = $m[1];
				$this->mine[ $m[1] ] = ( $this->mine[ $m[1] ] ?? 0 ) + 1;
				return '1';
			}
			if ( preg_match( "/RELEASE_LOCK\('([^']+)'\)/", (string) $sql, $m ) ) {
				$this->released[]    = $m[1];
				$this->mine[ $m[1] ] = ( $this->mine[ $m[1] ] ?? 0 ) - 1;
				return '1';
			}
			return null;
		}

		/** The options-row lock: INSERT IGNORE makes a row only when there is none; DELETE removes it (when it still matches). */
		public function query( $sql ) {
			if ( preg_match( "/^INSERT IGNORE INTO \S+ \(option_name, option_value, autoload\) VALUES \('([^']+)', '([^']*)', 'no'\)$/", (string) $sql, $m ) ) {
				if ( isset( $this->rows[ $m[1] ] ) ) {
					return 0;
				}
				$this->rows[ $m[1] ] = $m[2];
				$this->rows_taken++;
				return 1;
			}
			if ( preg_match( "/^DELETE FROM \S+ WHERE option_name = '([^']+)'(?: AND option_value = '([^']*)')?$/", (string) $sql, $m ) ) {
				if ( ! isset( $this->rows[ $m[1] ] ) || ( isset( $m[2] ) && $this->rows[ $m[1] ] !== $m[2] ) ) {
					return 0;
				}
				unset( $this->rows[ $m[1] ] );
				return 1;
			}
			return parent::query( $sql );
		}

		/** Locks taken and not given back: named locks, and options rows still there. */
		public function open_locks() {
			$open = array_filter( $this->mine );
			foreach ( array_keys( $this->rows ) as $row ) {
				$open[ 'row:' . $row ] = 1;
			}
			return $open;
		}

		/** The notices put in the bell: [ to, type, title, message ] each. */
		public function notices() {
			$out = array();
			foreach ( $this->written as $w ) {
				if ( 'insert' === $w[0] && 'zdz_notifications' === substr( (string) $w[1], -17 ) ) {
					$out[] = array(
						'to'      => (int) $w[2]['user_id'],
						'type'    => (string) $w[2]['alert_type'],
						'title'   => (string) $w[2]['title'],
						'message' => (string) $w[2]['message'],
					);
				}
			}
			return $out;
		}
	}
}

$zdz_test_signin_root = dirname( __DIR__, 2 );
foreach ( array( 'hierarchy', 'privacy-view', 'view-as', 'admin-dashboard', 'alert-router', 'magic-link-bridge', 'signin-routes' ) as $zdz_test_signin_f ) {
	require_once $zdz_test_signin_root . '/zorderz/inc/class-zdz-' . $zdz_test_signin_f . '.php';
}

/** The name of the lock ZDZ_Signin_Routes takes for one account, with the stand-in database. */
function zdz_test_signin_lock( $uid ) {
	return 'zdz_signin_' . substr( md5( ( defined( 'DB_NAME' ) ? (string) DB_NAME : '' ) . '|wp_' ), 0, 12 ) . '_' . (int) $uid;
}

/** A made-up account, known to every stand-in that reads people. An administrator role holds the admin capabilities. */
function zdz_test_signin_user( $id, $email, $name, array $roles, array $caps = array() ) {
	$u               = new WP_User();
	$u->ID           = (int) $id;
	$u->user_email   = (string) $email;
	$u->display_name = (string) $name;
	$u->user_login   = strtolower( str_replace( ' ', '.', (string) $name ) );
	$u->roles        = $roles;
	if ( array_intersect( array( 'administrator', 'zdz_owner', 'zdz_admin' ), $roles ) ) {
		$caps = array_merge( $caps, array( 'manage_options', 'edit_users' ) );
	}
	$GLOBALS['zdz_test_users'][ 'u' . (int) $id ] = $u;
	$GLOBALS['zdz_test_user_roles'][ (int) $id ]  = $roles;
	$GLOBALS['zdz_test_user_names'][ (int) $id ]  = (string) $name;
	$GLOBALS['zdz_test_user_caps'][ (int) $id ]   = array_values( array_unique( $caps ) );
	return $u;
}

/** Sets one of a class's private statics (View As, Demo Mode, the bridge's typed address). */
function zdz_test_signin_static( $class, $prop, $value ) {
	$r = new ReflectionProperty( $class, $prop );
	$r->setAccessible( true );
	$r->setValue( null, $value );
}

/** Who the request is: this account, as themselves (no View As, no Demo Mode). */
function zdz_test_signin_as( $uid ) {
	$GLOBALS['zdz_test_current_user'] = (int) $uid;
	zdz_test_signin_static( 'ZDZ_View_As', 'emulated_role', null );
	zdz_test_signin_static( 'ZDZ_View_As', 'emulated_user_id', null );
	zdz_test_signin_demo( false );
}

/** View As a role (or, with $as_user, one person and no role). */
function zdz_test_signin_view_as( $role, $as_user = null ) {
	zdz_test_signin_static( 'ZDZ_View_As', 'emulated_role', $role );
	zdz_test_signin_static( 'ZDZ_View_As', 'emulated_user_id', $as_user );
}

/** Demo Mode on or off for this request. False when the Demo Mode class is not loaded (there is then nothing to set). */
function zdz_test_signin_demo( $on ) {
	if ( ! class_exists( 'ZDZ_Kiosk_Demo', false ) ) {
		return false;
	}
	zdz_test_signin_static( 'ZDZ_Kiosk_Demo', 'switched', (bool) $on );
	return true;
}

/** The usual people: an owner who is the first administrator, an office administrator, an administrator account in
 *  Privacy View, the shared device, and four people who are neither. */
function zdz_test_signin_people() {
	zdz_test_signin_user( 1, 'morgan@example.com', 'Morgan Example', array( 'administrator' ) );
	zdz_test_signin_user( 2, 'robin@example.com', 'Robin Sample', array( 'zdz_admin' ) );
	zdz_test_signin_user( 3, 'devin@example.com', 'Devin Example', array( 'zdz_owner' ) );
	$GLOBALS['zdz_test_user_meta'][3]['zdz_privacy_view'] = '1';
	zdz_test_signin_user( 9, 'device@example.com', 'Shared Device', array( 'zdz_general' ) );
	zdz_test_signin_user( 11, 'alex@example.com', 'Alex Example', array( 'zdz_sales' ) );
	zdz_test_signin_user( 12, 'jordan@example.com', 'Jordan Sample', array( 'zdz_tech' ) );
	zdz_test_signin_user( 13, 'riley@example.com', 'Riley Example', array( 'zdz_sales' ) );
	zdz_test_signin_user( 14, 'casey@example.com', 'Casey Sample', array( 'zdz_tech' ) );
}

/** Fresh state for one test: no people, options, meta, transients, mail, pages or filters; a new database; the clock fixed. */
function zdz_test_signin_reset() {
	foreach ( array( 'zdz_test_users', 'zdz_test_user_roles', 'zdz_test_user_names', 'zdz_test_user_caps', 'zdz_test_user_meta',
		'zdz_test_options', 'zdz_test_transients', 'zdz_test_filters', 'zdz_test_signin_mail', 'zdz_test_signin_pages',
		'zdz_test_signin_cache_drops', 'zdz_test_signin_rest' ) as $g ) {
		$GLOBALS[ $g ] = array();
	}
	unset( $GLOBALS['zdz_test_signin_mail_ok'], $GLOBALS['zdz_test_signin_cookie'] );
	$GLOBALS['wpdb'] = new ZDZ_Test_Signin_DB();
	zdz_test_signin_as( 0 );
	zdz_test_signin_static( 'ZDZ_Magic_Link_Bridge', 'typed_alias', '' );
	ZDZ_Signin_Routes::$now = 1794550000;
	$_POST                  = array();
	$_GET                   = array();
	$_SERVER['REMOTE_ADDR'] = '203.0.113.10';
	unset( $_SERVER['REQUEST_METHOD'], $_SERVER['HTTP_USER_AGENT'] );
}

/** After a test: nothing of it is left for the next file (the whole suite runs in one process). */
function zdz_test_signin_teardown() {
	zdz_test_signin_as( 0 );
	zdz_test_signin_static( 'ZDZ_Magic_Link_Bridge', 'typed_alias', '' );
	ZDZ_Signin_Routes::$now = 0;
	$_POST                  = array();
	$_GET                   = array();
	foreach ( array( 'zdz_test_users', 'zdz_test_user_roles', 'zdz_test_user_names', 'zdz_test_user_caps', 'zdz_test_user_meta',
		'zdz_test_options', 'zdz_test_transients', 'zdz_test_filters', 'zdz_test_current_user', 'zdz_test_signin_mail',
		'zdz_test_signin_mail_ok', 'zdz_test_signin_cookie', 'zdz_test_signin_pages', 'zdz_test_signin_cache_drops',
		'zdz_test_signin_rest', 'wpdb' ) as $g ) {
		unset( $GLOBALS[ $g ] );
	}
	unset( $_SERVER['REMOTE_ADDR'], $_SERVER['REQUEST_METHOD'], $_SERVER['HTTP_USER_AGENT'] );
}

/** Do the stand-ins a test depends on answer as this file's do? (Another file's may have been defined first.) */
function zdz_test_signin_has( ...$names ) {
	foreach ( $names as $n ) {
		if ( empty( ZDZ_Test_Signin_Doubles::$own[ $n ] ) ) {
			return false;
		}
	}
	return true;
}

/** The code a person may try as a wrong one: the same length, never the right code. */
function zdz_test_signin_wrong( $code ) {
	$lo = 8 === strlen( (string) $code ) ? 10000000 : 100000;
	return (string) ( $lo + ( ( (int) $code - $lo + 1 ) % ( 9 * $lo ) ) );
}
