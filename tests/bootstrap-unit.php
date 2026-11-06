<?php
/**
 * Bootstrap for Zorderz UNIT tests: security invariants that need no WordPress and no DB.
 *
 * It stubs the handful of WordPress functions the pure logic touches, then loads the REAL
 * shipping class so the tests pin the actual code, not a copy. Keep this dependency-light on
 * purpose: these tests must run in CI in seconds with no database, so a regression in a
 * security-critical helper turns the build red immediately.
 *
 * @package Zorderz\Tests
 */

define( 'ABSPATH', __DIR__ . '/' );

// Keep intentional error_log() diagnostics from the code under test out of the test output.
ini_set( 'error_log', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null' );

/*
 * Filters: pass-through by default, so tests exercise the unfiltered behaviour. A test that
 * needs a filter (e.g. to prove a tenant cannot loosen a safety floor) registers a callable
 * in $GLOBALS['zdz_test_filters'][ $hook ] and removes it afterwards.
 */
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook, $value = null, ...$args ) {
		if ( isset( $GLOBALS['zdz_test_filters'][ $hook ] ) && is_callable( $GLOBALS['zdz_test_filters'][ $hook ] ) ) {
			return call_user_func( $GLOBALS['zdz_test_filters'][ $hook ], $value, ...$args );
		}
		return $value;
	}
}
// One shared WP_Error stand-in for every test file, so whichever test loads first cannot
// leave a thinner one behind for the rest of the run.
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private $code;
		private $message;
		private $data;
		public function __construct( $code = '', $message = '', $data = '' ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}
		public function get_error_code() { return $this->code; }
		public function get_error_message() { return $this->message; }
		public function get_error_data() { return $this->data; }
	}
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ) { return $thing instanceof WP_Error; }
}
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( ...$a ) { return true; }
}
if ( ! function_exists( 'add_action' ) ) {
	function add_action( ...$a ) { return true; }
}
if ( ! function_exists( 'do_action' ) ) {
	function do_action( ...$a ) { return null; }
}
if ( ! function_exists( 'get_option' ) ) {
	function get_option( $k, $d = false ) { return $GLOBALS['zdz_test_options'][ $k ] ?? $d; }
}
if ( ! function_exists( '__' ) ) {
	function __( $s, $d = null ) { return $s; }
}
if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $k ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $k ) ); }
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $s ) { return is_string( $s ) ? trim( $s ) : ''; }
}
/*
 * The site's name and addresses, read the way WordPress reads them, so every test file sees the same site whichever
 * file the runner loads first. The name and tagline are the blogname and blogdescription options: empty unless a test
 * sets them, as a fresh profile with no site name would be. The home address is $GLOBALS['zdz_test_home'] (default
 * https://app.example.com); the WordPress address is the siteurl option, else the home address.
 */
if ( ! function_exists( 'home_url' ) ) {
	function home_url( $path = '' ) {
		$url = rtrim( (string) ( $GLOBALS['zdz_test_home'] ?? 'https://app.example.com' ), '/' );
		return ( $path && is_string( $path ) ) ? $url . '/' . ltrim( $path, '/' ) : $url;
	}
}
if ( ! function_exists( 'site_url' ) ) {
	function site_url( $path = '' ) {
		$base = rtrim( (string) get_option( 'siteurl', '' ), '/' );
		if ( '' === $base ) {
			return home_url( $path );
		}
		return ( $path && is_string( $path ) ) ? $base . '/' . ltrim( $path, '/' ) : $base;
	}
}
if ( ! function_exists( 'get_bloginfo' ) ) {
	function get_bloginfo( $show = '', $filter = 'raw' ) {
		switch ( (string) $show ) {
			case 'url':
				return home_url();
			case 'wpurl':
				return site_url();
			case 'description':
				return (string) get_option( 'blogdescription', '' );
			case 'admin_email':
				return (string) get_option( 'admin_email', '' );
			case 'charset':
				return 'UTF-8';
			case 'language':
				return 'en-US';
			case 'version':
				return '6.4';
			default:
				return (string) get_option( 'blogname', '' );
		}
	}
}

/*
 * A plugin file's folder and its address. The apps bundle is installed as wp-content/plugins/zorderz-apps, so a file
 * under this repository is served from the home address + /wp-content/plugins/ + its folder (zorderz-apps/apps/<app>/).
 */
if ( ! function_exists( 'plugin_dir_path' ) ) {
	function plugin_dir_path( $file ) { return rtrim( dirname( (string) $file ), '/' ) . '/'; }
}
if ( ! function_exists( 'plugin_dir_url' ) ) {
	function plugin_dir_url( $file ) {
		$dir  = rtrim( str_replace( '\\', '/', dirname( (string) $file ) ), '/' );
		$root = rtrim( str_replace( '\\', '/', dirname( __DIR__ ) ), '/' ) . '/';
		$rel  = 0 === strpos( $dir . '/', $root ) ? substr( $dir, strlen( $root ) ) : basename( $dir );
		return home_url( '/wp-content/plugins/' . trim( $rel, '/' ) . '/' );
	}
}

/*
 * People and what they may do, read the way WordPress answers it, from the globals every test file shares:
 *   $GLOBALS['zdz_test_current_user']  the signed-in user id (0: nobody);
 *   $GLOBALS['zdz_test_user_roles']    [ id => [ role, ... ] ]  (an id not listed is no user at all);
 *   $GLOBALS['zdz_test_user_caps']     [ id => [ cap, ... ] ] or [ id => [ cap => true ] ];
 *   $GLOBALS['zdz_test_user_names']    [ id => display name ]  (default "User <id>");
 *   $GLOBALS['zdz_test_users']         the list get_users() searches (objects with ID and roles).
 * An administrator holds manage_options, as in WordPress; any other capability is held only when granted.
 * current_user_can() asks the same question for the signed-in user, so nobody signed in can do nothing.
 */
if ( ! function_exists( 'get_userdata' ) ) {
	function get_userdata( $uid ) {
		$uid   = (int) $uid;
		$roles = $GLOBALS['zdz_test_user_roles'][ $uid ] ?? null;
		if ( null === $roles ) {
			return false;
		}
		return (object) array(
			'ID'           => $uid,
			'roles'        => (array) $roles,
			'display_name' => (string) ( $GLOBALS['zdz_test_user_names'][ $uid ] ?? 'User ' . $uid ),
			'user_login'   => 'user' . $uid,
		);
	}
}
if ( ! function_exists( 'user_can' ) ) {
	function user_can( $user, $cap ) {
		$uid  = is_object( $user ) ? (int) ( $user->ID ?? 0 ) : (int) $user;
		$caps = (array) ( $GLOBALS['zdz_test_user_caps'][ $uid ] ?? array() );
		if ( in_array( $cap, $caps, true ) || ! empty( $caps[ $cap ] ) ) {
			return true;
		}
		return 'manage_options' === $cap && in_array( 'administrator', (array) ( $GLOBALS['zdz_test_user_roles'][ $uid ] ?? array() ), true );
	}
}
if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( $cap, ...$args ) {
		return user_can( (int) ( $GLOBALS['zdz_test_current_user'] ?? 0 ), $cap );
	}
}
if ( ! function_exists( 'get_users' ) ) {
	function get_users( $args = array() ) {
		$args = (array) $args;
		$out  = array();
		foreach ( array_values( (array) ( $GLOBALS['zdz_test_users'] ?? array() ) ) as $u ) {
			$roles = is_object( $u ) ? (array) ( $u->roles ?? array() ) : array();
			if ( ! empty( $args['role__in'] ) && is_object( $u ) && isset( $u->roles ) && ! array_intersect( $roles, (array) $args['role__in'] ) ) {
				continue;
			}
			if ( ! empty( $args['role'] ) && is_object( $u ) && isset( $u->roles ) && ! in_array( $args['role'], $roles, true ) ) {
				continue;
			}
			$out[] = ( 'ID' === ( $args['fields'] ?? '' ) && is_object( $u ) ) ? (int) $u->ID : $u;
		}
		return $out;
	}
}
if ( ! function_exists( 'delete_user_meta' ) ) {
	function delete_user_meta( $uid, $key, $value = '' ) {
		$had = isset( $GLOBALS['zdz_test_user_meta'][ (int) $uid ][ $key ] );
		unset( $GLOBALS['zdz_test_user_meta'][ (int) $uid ][ $key ] );
		return $had;
	}
}

/* Dates, JSON, e-mail and URLs, as WordPress does them (the site's timezone is UTC here). */
if ( ! function_exists( 'current_time' ) ) {
	function current_time( $type, $gmt = 0 ) {
		if ( 'mysql' === $type ) {
			return gmdate( 'Y-m-d H:i:s' );
		}
		if ( 'timestamp' === $type || 'U' === $type ) {
			return time();
		}
		return gmdate( (string) $type );
	}
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, $options = 0, $depth = 512 ) {
		return json_encode( $data, (int) $options, max( 1, (int) $depth ) );
	}
}
if ( ! function_exists( 'is_email' ) ) {
	// WordPress's own rules: the address back when it passes, false when it does not.
	function is_email( $email, $deprecated = false ) {
		if ( ! is_string( $email ) || strlen( $email ) < 6 || false === strpos( $email, '@', 1 ) ) {
			return false;
		}
		list( $local, $domain ) = explode( '@', $email, 2 );
		if ( ! preg_match( '/^[a-zA-Z0-9!#$%&\'*+\/=?^_`{|}~\.-]+$/', $local ) || preg_match( '/\.{2,}/', $domain )
			|| trim( $domain, " \t\n\r\0\x0B." ) !== $domain ) {
			return false;
		}
		$subs = explode( '.', $domain );
		if ( count( $subs ) < 2 ) {
			return false;
		}
		foreach ( $subs as $sub ) {
			if ( trim( $sub, " \t\n\r\0\x0B-" ) !== $sub || ! preg_match( '/^[a-z0-9-]+$/i', $sub ) ) {
				return false;
			}
		}
		return $email;
	}
}
if ( ! function_exists( 'add_query_arg' ) ) {
	/*
	 * Both of WordPress's forms: add_query_arg( [ key => value ], $url ) and add_query_arg( key, value, $url ). As in
	 * WordPress, the new values are not encoded (the caller encodes what needs it), a value of false removes its key,
	 * an existing query is kept and a #fragment stays at the end.
	 */
	function add_query_arg( ...$args ) {
		if ( is_array( $args[0] ) ) {
			$new = $args[0];
			$uri = ( isset( $args[1] ) && false !== $args[1] ) ? (string) $args[1] : (string) ( $_SERVER['REQUEST_URI'] ?? '' );
		} else {
			$new = array( $args[0] => $args[1] ?? '' );
			$uri = ( isset( $args[2] ) && false !== $args[2] ) ? (string) $args[2] : (string) ( $_SERVER['REQUEST_URI'] ?? '' );
		}
		$frag = '';
		$hash = strpos( $uri, '#' );
		if ( false !== $hash ) {
			$frag = substr( $uri, $hash );
			$uri  = substr( $uri, 0, $hash );
		}
		if ( false !== strpos( $uri, '?' ) ) {
			list( $base, $query ) = explode( '?', $uri, 2 );
			$base .= '?';
		} elseif ( preg_match( '#^[a-z][a-z0-9+.\-]*://#i', $uri ) || false === strpos( $uri, '=' ) ) {
			$base  = $uri . '?';
			$query = '';
		} else {
			$base  = '';
			$query = $uri;
		}
		parse_str( $query, $qs );
		array_walk_recursive( $qs, function ( &$v ) { $v = urlencode( (string) $v ); } );
		foreach ( $new as $k => $v ) {
			$qs[ $k ] = $v;
		}
		$pairs = array();
		$build = function ( $data, $prefix ) use ( &$build, &$pairs ) {
			foreach ( (array) $data as $k => $v ) {
				$key = '' === $prefix ? (string) $k : $prefix . '%5B' . $k . '%5D';
				if ( null === $v || ( '' === $prefix && false === $v ) ) {
					continue;
				}
				if ( is_array( $v ) || is_object( $v ) ) {
					$build( $v, $key );
				} else {
					$pairs[] = $key . '=' . ( false === $v ? '0' : ( true === $v ? '1' : (string) $v ) );
				}
			}
		};
		$build( $qs, '' );
		$ret = preg_replace( '#=(&|$)#', '$1', trim( implode( '&', $pairs ), '?' ) );
		return str_replace( '?#', '#', rtrim( $base . $ret . $frag, '?' ) );
	}
}
if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	function wp_remote_retrieve_response_code( $response ) {
		if ( is_wp_error( $response ) || ! is_array( $response ) || ! isset( $response['response'] ) || ! is_array( $response['response'] ) ) {
			return '';
		}
		return $response['response']['code'] ?? '';
	}
}
if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $value ) {
		if ( is_array( $value ) ) {
			return array_map( 'wp_unslash', $value );
		}
		return is_string( $value ) ? stripslashes( $value ) : $value;
	}
}
/* Just enough of the REST API: a request with named params, a JSON body and $req['id']; a response with its data. */
if ( ! class_exists( 'WP_REST_Request' ) ) {
	class WP_REST_Request implements ArrayAccess {
		private $p;
		private $json;
		public function __construct( array $params = array(), $json = null ) { $this->p = $params; $this->json = $json; }
		public function get_param( $k ) { return $this->p[ $k ] ?? null; }
		public function get_json_params() { return $this->json; }
		public function offsetExists( $k ): bool { return isset( $this->p[ $k ] ); }
		#[\ReturnTypeWillChange]
		public function offsetGet( $k ) { return $this->p[ $k ] ?? null; }
		public function offsetSet( $k, $v ): void { $this->p[ $k ] = $v; }
		public function offsetUnset( $k ): void { unset( $this->p[ $k ] ); }
	}
}
if ( ! class_exists( 'WP_REST_Response' ) ) {
	class WP_REST_Response {
		public $data;
		public $status;
		public function __construct( $data = null, $status = 200 ) { $this->data = $data; $this->status = $status; }
		public function get_data() { return $this->data; }
		public function get_status() { return $this->status; }
		public function header( $k, $v ) {}
	}
}
if ( ! function_exists( 'rest_ensure_response' ) ) {
	// As in WordPress: an error and a response come back as they are; anything else becomes a response.
	function rest_ensure_response( $response ) {
		if ( is_wp_error( $response ) || $response instanceof WP_REST_Response ) {
			return $response;
		}
		return new WP_REST_Response( $response );
	}
}
if ( ! function_exists( 'wp_salt' ) ) {
	// Deterministic per-scheme secret so the HMAC share-link tests are stable; distinct per
	// scheme so domain-separation assertions are meaningful. Never the real salt.
	function wp_salt( $scheme = 'auth' ) { return 'zorderz-unit-test-salt::' . $scheme; }
}

/**
 * A $wpdb with no tables, for a test whose code reaches the database only through a class another test file may have
 * loaded (the Item Engine's catalog, the customer-detail coder's name lists, the audit log). Every read finds nothing,
 * as on a site where those tables do not exist yet; a write is kept in $written and succeeds.
 */
if ( ! class_exists( 'ZDZ_Test_Empty_DB' ) ) {
	class ZDZ_Test_Empty_DB {
		public $prefix     = 'wp_';
		public $options    = 'wp_options';
		public $usermeta   = 'wp_usermeta';
		public $last_error = '';
		public $insert_id  = 0;
		public $written    = array();

		public function prepare( $sql, ...$args ) {
			if ( 1 === count( $args ) && is_array( $args[0] ) ) {
				$args = $args[0];
			}
			$i = 0;
			return preg_replace_callback( '/%[dsf]/', function ( $m ) use ( &$i, $args ) {
				$v = $args[ $i++ ] ?? '';
				return '%s' === $m[0] ? "'" . addslashes( (string) $v ) . "'" : (string) ( 0 + $v );
			}, $sql );
		}
		public function esc_like( $s ) { return addcslashes( (string) $s, '_%\\' ); }
		public function get_charset_collate() { return ''; }
		public function get_var( $sql ) { return null; }
		public function get_col( $sql ) { return array(); }
		public function get_row( $sql, $out = null ) { return null; }
		public function get_results( $sql, $out = null ) { return array(); }
		public function query( $sql ) { $this->written[] = array( 'query', $sql ); return 0; }
		public function insert( $table, $row, $fmt = null ) {
			$this->written[] = array( 'insert', $table, $row );
			$this->insert_id++;
			return 1;
		}
		public function replace( $table, $row, $fmt = null ) { $this->written[] = array( 'replace', $table, $row ); return 1; }
		public function update( $table, $row, $where, $fmt = null, $wfmt = null ) { $this->written[] = array( 'update', $table, $row, $where ); return 0; }
		public function delete( $table, $where, $wfmt = null ) { $this->written[] = array( 'delete', $table, $where ); return 0; }
	}
}

/**
 * Minimal stand-in for the core settings class so ZDZ_Data_Portability::secret_option_names()
 * can consume the authoritative secret-field list without booting WordPress. The real list is
 * separately pinned by CoreSettingsCouplingTest against the shipping file.
 */
if ( ! class_exists( 'ZDZ_Core_Settings' ) ) {
	class ZDZ_Core_Settings {
		public static function secret_fields(): array {
			return array( 'poe_api_key', 'fb_client_secret', 'fb_access_token', 'fb_refresh_token', 'ns_api_key', 'review_bridge_key' );
		}
	}
}

require_once dirname( __DIR__ ) . '/zorderz/inc/class-zdz-data-portability.php';
require_once dirname( __DIR__ ) . '/zorderz/inc/class-zdz-kpi-metrics.php'; // for is_financial_metric()
require_once dirname( __DIR__ ) . '/zorderz/inc/class-zdz-share-link.php';  // HMAC share-link primitive
require_once dirname( __DIR__ ) . '/zorderz/inc/class-zdz-answer-authority.php'; // threshold floor clamp
require_once dirname( __DIR__ ) . '/zorderz/inc/class-zdz-rule-governance.php';  // safety-floor merge
require_once dirname( __DIR__ ) . '/zorderz/inc/class-zdz-name-match.php';       // shared name matcher
require_once dirname( __DIR__ ) . '/zorderz/inc/class-zdz-contact-bridge.php';   // disclosure gate
