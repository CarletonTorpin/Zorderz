<?php
/**
 * Stand-ins and a request builder for the Privacy View gate tests.
 *
 * The two WordPress functions the gate asks "what kind of request is this?" are defined here the way WordPress itself
 * defines them, so a test steers them through WordPress's own switches and never through a private global:
 *   wp_doing_ajax()  the `wp_doing_ajax` filter (set $GLOBALS['zdz_test_filters']['wp_doing_ajax']);
 *   is_admin()       $GLOBALS['current_screen']->in_admin().
 * ZDZ_Test_Kpv_Request::hit() hands the gate one request as PHP would: $_GET, $_POST, $_FILES, $_REQUEST, the address,
 * the method, the body's type, length and text, and $pagenow.
 *
 * @package Zorderz\Tests
 */

if ( ! function_exists( 'get_user_meta' ) ) {
	function get_user_meta( $uid, $key, $single = false ) { return $GLOBALS['zdz_test_user_meta'][ (int) $uid ][ $key ] ?? ''; }
}
if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id() { return (int) ( $GLOBALS['zdz_test_current_user'] ?? 0 ); }
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( $k, $v, $autoload = null ) { $GLOBALS['zdz_test_options'][ $k ] = $v; return true; }
}
if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( $k ) { unset( $GLOBALS['zdz_test_options'][ $k ] ); return true; }
}
if ( ! function_exists( 'wp_doing_ajax' ) ) {
	function wp_doing_ajax() { return (bool) apply_filters( 'wp_doing_ajax', defined( 'DOING_AJAX' ) && DOING_AJAX ); }
}
if ( ! function_exists( 'is_admin' ) ) {
	function is_admin() {
		if ( isset( $GLOBALS['current_screen'] ) && is_object( $GLOBALS['current_screen'] ) && method_exists( $GLOBALS['current_screen'], 'in_admin' ) ) {
			return (bool) $GLOBALS['current_screen']->in_admin();
		}
		return defined( 'WP_ADMIN' ) && WP_ADMIN;
	}
}

require_once dirname( __DIR__, 3 ) . '/zorderz/inc/class-zdz-privacy-view.php';

/** A screen that says whether the request is in wp-admin (what is_admin() asks). */
final class ZDZ_Test_Kpv_Screen {
	private $admin;
	public function __construct( $admin ) { $this->admin = (bool) $admin; }
	public function in_admin( $which = null ) { return $this->admin; }
}

/** A stand-in for the coder: knows two customer names (coded as ⟦c:id⟧) and seals a phone number (⟦e:…⟧). */
final class ZDZ_Test_Kpv_Coder {
	public $names  = array( 1 => 'Alex Riley', 2 => 'Example Hardware' );
	public $sealed = array();
	private function sub( $t ) {
		foreach ( $this->names as $id => $n ) {
			$t = preg_replace( '/(?<![\p{L}])' . preg_quote( $n, '/' ) . '(?![\p{L}])/iu', '⟦c:' . $id . '⟧', $t );
		}
		return $t;
	}
	/** As the coder does: a phone number in any usual layout is sealed, then the names it knows. */
	public function encode( $t ) {
		$t = (string) preg_replace_callback( '/(?<![\d:])(?:\+?1[\s.\-]?)?\(?\d{3}\)?[\s.\-]?\d{3}[\s.\-]\d{4}(?!\d)|(?<![\d:])\d{10}(?!\d)/u', function ( $m ) {
			$this->sealed[] = $m[0];
			return '⟦e:s' . ( count( $this->sealed ) - 1 ) . '⟧';
		}, (string) $t );
		return $this->sub( $t );
	}
	public function names_only( $t ) { return $this->sub( (string) $t ); }
	public function open( $c ) {
		$i = (int) substr( (string) $c, 1 );
		return ( 's' === substr( (string) $c, 0, 1 ) && isset( $this->sealed[ $i ] ) ) ? $this->sealed[ $i ] : null;
	}
	public function ledger_name( $id ) { return $this->names[ (int) $id ] ?? null; }
	public function index() {
		$l = array();
		foreach ( $this->names as $id => $n ) {
			$l[ ZDZ_Privacy_View::norm( $n ) ] = $id;
		}
		return array( 'ledger' => $l, 'other' => array() );
	}
}

final class ZDZ_Test_Kpv_Request {

	/** The account in Privacy View, an administrator it may message, and a team member. */
	const ACCOUNT = 9;
	const ADMIN   = 7;
	const MEMBER  = 3;

	/** The request headers and server values a test may set; cleared between requests. */
	const SERVER_KEYS = array( 'REQUEST_METHOD', 'REQUEST_URI', 'PHP_SELF', 'CONTENT_TYPE', 'HTTP_CONTENT_TYPE', 'CONTENT_LENGTH', 'HTTP_TRANSFER_ENCODING',
		'HTTP_X_HTTP_METHOD_OVERRIDE', 'HTTP_COOKIE' );

	public static function prop( $name ) {
		$p = new ReflectionProperty( 'ZDZ_Privacy_View', $name );
		$p->setAccessible( true );
		return $p;
	}

	public static function set( $name, $value ) {
		self::prop( $name )->setValue( null, $value );
	}

	public static function get( $name ) {
		return self::prop( $name )->getValue();
	}

	/** The people every gate test starts from: the account (an administrator's rights, Privacy View on), signed in. */
	public static function people() {
		$GLOBALS['zdz_test_user_meta']    = array( self::ACCOUNT => array( 'zdz_privacy_view' => '1' ) );
		$GLOBALS['zdz_test_user_roles']   = array( self::ACCOUNT => array( 'administrator' ), self::ADMIN => array( 'administrator' ), self::MEMBER => array( 'zdz_sales' ) );
		$GLOBALS['zdz_test_user_caps']    = array( self::ACCOUNT => array( 'manage_options' ), self::ADMIN => array( 'manage_options' ) );
		$GLOBALS['zdz_test_current_user'] = self::ACCOUNT;
	}

	/**
	 * One request, as PHP would hand it over. $kind: ajax, rest, login, admin, front. $get / $post / $files by name.
	 * $o: uri, bare (no query written), method, type (null: none), length, te (a transfer encoding), input (the raw
	 * body; else what a browser would send for $post), request (what $_REQUEST holds), page ($pagenow), http_type (the
	 * type under PHP's other name for it), self (PHP_SELF), server (more $_SERVER values).
	 */
	public static function build( $kind, array $get = array(), array $post = array(), array $o = array(), array $files = array() ) {
		foreach ( self::SERVER_KEYS as $k ) {
			if ( 'HTTP_COOKIE' !== $k ) {
				unset( $_SERVER[ $k ] );
			}
		}
		$method                    = $o['method'] ?? ( $post || $files ? 'POST' : 'GET' );
		$_SERVER['REQUEST_METHOD'] = $method;
		$uri                       = $o['uri'] ?? ( 'ajax' === $kind ? '/wp-admin/admin-ajax.php' : ( 'login' === $kind ? '/wp-login.php'
			: ( 'rest' === $kind ? '/wp-json/zorderz/v1/notifications/unread-count' : ( 'admin' === $kind ? '/wp-admin/profile.php' : '/' ) ) ) );
		if ( $get && false === strpos( $uri, '?' ) && empty( $o['bare'] ) ) {   // the address as a browser would write it for these fields
			$uri .= '?' . implode( '&', array_map( function ( $k, $v ) { return $k . '=' . ( is_array( $v ) ? '' : $v ); }, array_keys( $get ), $get ) );
		}
		$_SERVER['REQUEST_URI'] = $uri;
		$_SERVER['PHP_SELF']    = $o['self'] ?? ( 'ajax' === $kind ? '/wp-admin/admin-ajax.php' : ( 'login' === $kind ? '/wp-login.php'
			: ( 'admin' === $kind ? '/wp-admin/' . ( $o['page'] ?? 'profile.php' ) : '/index.php' ) ) );
		$type = array_key_exists( 'type', $o ) ? $o['type'] : ( $files ? 'multipart/form-data; boundary=----example' : ( 'POST' === $method ? 'application/x-www-form-urlencoded; charset=UTF-8' : null ) );
		if ( null !== $type ) {
			$_SERVER[ empty( $o['http_type'] ) ? 'CONTENT_TYPE' : 'HTTP_CONTENT_TYPE' ] = $type;
		}
		$form = null !== $type && 0 === stripos( ltrim( $type ), 'application/x-www-form-urlencoded' );
		$raw  = array_key_exists( 'input', $o ) ? (string) $o['input'] : ( $form && 'POST' === $method ? http_build_query( $post ) : '' );
		if ( isset( $o['length'] ) ) {
			$_SERVER['CONTENT_LENGTH'] = (string) $o['length'];
		} elseif ( '' !== $raw || $post || $files ) {
			$_SERVER['CONTENT_LENGTH'] = (string) max( 1, strlen( $raw ) );
		}
		if ( isset( $o['te'] ) ) {
			$_SERVER['HTTP_TRANSFER_ENCODING'] = $o['te'];
		}
		foreach ( $o['server'] ?? array() as $k => $v ) {
			$_SERVER[ $k ] = $v;
		}
		self::set( 'input', $raw );
		$_GET     = $get;
		$_POST    = $post;
		$_FILES   = $files;
		$_REQUEST = $o['request'] ?? ( $post + $get );
		if ( 'ajax' === $kind ) {
			$GLOBALS['zdz_test_filters']['wp_doing_ajax'] = function () { return true; };
		} else {
			unset( $GLOBALS['zdz_test_filters']['wp_doing_ajax'] );
		}
		$GLOBALS['current_screen'] = new ZDZ_Test_Kpv_Screen( 'ajax' === $kind || 'admin' === $kind );
		$GLOBALS['pagenow']        = $o['page'] ?? ( 'ajax' === $kind ? 'admin-ajax.php' : ( 'login' === $kind ? 'wp-login.php' : ( 'admin' === $kind ? 'profile.php' : 'index.php' ) ) );
	}

	/** The gate's verdict on one request ('' allowed, else why not). */
	public static function hit( $kind, array $get = array(), array $post = array(), array $o = array(), array $files = array() ) {
		self::build( $kind, $get, $post, $o, $files );
		return ZDZ_Privacy_View::verdict();
	}

	/** The verdict before any plugin has loaded: $pagenow is not set yet, and $_REQUEST is PHP's own. */
	public static function early( $kind, array $get = array(), array $post = array(), array $o = array(), array $files = array() ) {
		self::build( $kind, $get, $post, $o, $files );
		$GLOBALS['pagenow'] = 'not-set-yet.php';
		$_REQUEST           = $o['php_request'] ?? array();
		return ZDZ_Privacy_View::early_verdict();
	}

	/** An allowed action with exactly its own fields (each given a made-up value). */
	public static function own( $action, array $extra = array() ) {
		$f = array( 'action' => $action, 'nonce' => 'n0nce' );
		foreach ( array_diff( ZDZ_Privacy_View::ajax_keys( $action ), ZDZ_Privacy_View::AJAX_BASE_KEYS ) as $k ) {
			$f[ $k ] = '1';
		}
		return $extra + $f;
	}

	/** Every action Core lists: its own, and the direct-message ones. */
	public static function all() {
		return array_merge( ZDZ_Privacy_View::ALLOWED_AJAX, ZDZ_Privacy_View::DM_AJAX );
	}

	/** Put everything a test may have set back as it found it. */
	public static function reset() {
		foreach ( self::SERVER_KEYS as $k ) {
			unset( $_SERVER[ $k ] );
		}
		$_GET     = array();
		$_POST    = array();
		$_FILES   = array();
		$_REQUEST = array();
		$_COOKIE  = array();
		unset( $GLOBALS['current_screen'], $GLOBALS['pagenow'], $GLOBALS['zdz_test_filters'], $GLOBALS['zdz_test_options'], $GLOBALS['zdz_test_user_meta'],
			$GLOBALS['zdz_test_current_user'], $GLOBALS['zdz_test_users'], $GLOBALS['zdz_test_user_caps'], $GLOBALS['zdz_test_user_roles'], $GLOBALS['zdz_test_home'] );
		foreach ( array( 'input' => null, 'early' => false, 'early_login' => '', 'early_done' => false, 'defer_ready' => false, 'stripped' => array() ) as $name => $value ) {
			self::set( $name, $value );
		}
		ZDZ_Privacy_View::$halt = null;
		ZDZ_Privacy_View::flush();
	}
}
