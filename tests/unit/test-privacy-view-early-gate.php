<?php
/**
 * The Privacy View gate in front of EVERY plugin, through WordPress's own loading order.
 *
 * The theme's gate runs when the theme loads (after_setup_theme). A plugin's file, its plugins_loaded handlers and its
 * setup_theme handlers run before that, so no theme can be in front of them. The optional must-use file
 * (zorderz/mu-plugin/0-zdz-privacy-view-gate.php, installed into wp-content/mu-plugins/ by an administrator) runs
 * before any plugin and hands a request whose sign-in cookie names an account in Privacy View to
 * ZDZ_Privacy_View::early_gate().
 *
 * This script builds a small WordPress stand-in that loads things in wp-settings.php's order: must-use plugins in
 * file-name order (a host's own beside ours), muplugins_loaded, the cookie constants, vars.php's $pagenow, a probe
 * plugin (its file, plugins_loaded handlers at -1000, 10 and 20, setup_theme, init, AJAX handlers), the sign-in
 * functions (only now can anyone ask who is signed in, exactly as in WordPress; calling them earlier is a fatal error
 * here too), plugins_loaded, $_REQUEST rebuilt, setup_theme, the theme's class and boot(), after_setup_theme, init,
 * admin_init, the AJAX action. It serves that with PHP's own web server, so cookies, the address and the body are
 * parsed by PHP itself, and each request reports which of those steps ran.
 *
 * Skipped, with a line saying so, where a local server cannot be started. Neutral data only.
 *
 * Run: php tests/unit/test-privacy-view-early-gate.php
 */
if ( 'cli' !== PHP_SAPI ) {
	exit;
}
error_reporting( E_ALL & ~E_DEPRECATED );
require __DIR__ . '/support/privacy-view-served.php';

$REPO  = dirname( __DIR__, 2 );
$THEME = $REPO . '/zorderz';
$MU    = $THEME . '/mu-plugin/0-zdz-privacy-view-gate.php';
$pass  = 0;
$fail  = 0;
function ok( $c, $w, $d = null ) {
	global $pass, $fail;
	if ( $c ) {
		$pass++;
		echo "  ok    $w\n";
	} else {
		$fail++;
		echo "  FAIL  $w" . ( null !== $d ? ' -- ' . substr( (string) json_encode( $d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ), 0, 900 ) : '' ) . "\n";
	}
}
function section( $s ) {
	echo "\n== $s ==\n";
}
function done() {
	global $pass, $fail;
	echo $fail ? "\n$pass passed, $fail failed\n" : "\nAll {$pass} early-gate assertions passed.\n";
	exit( $fail ? 1 : 0 );
}

section( 'the pieces are there' );
ok( is_file( $MU ), 'the theme ships the must-use file (mu-plugin/0-zdz-privacy-view-gate.php)' );
$class = is_file( $THEME . '/inc/class-zdz-privacy-view.php' ) ? (string) file_get_contents( $THEME . '/inc/class-zdz-privacy-view.php' ) : '';
ok( false !== strpos( $class, 'public static function early_gate(' ), 'the theme\'s class has early_gate()' );
if ( ! is_file( $MU ) || false === strpos( $class, 'public static function early_gate(' ) ) {
	done();
}
$musrc = (string) file_get_contents( $MU );
ok( 1 === preg_match( '/^\s*\*\s*Plugin Name:/m', $musrc ) && 1 === preg_match( '/^\s*\*\s*Version:\s*([0-9.]+)\s*$/m', $musrc, $hv )
	&& 1 === preg_match( "/define\( 'ZDZ_PV_GATE_VERSION', '([0-9.]+)' \)/", $musrc, $cv ) && $hv[1] === $cv[1], 'it has a plugin header, and its header and its constant state the same version' );
$VERSION = $cv[1] ?? '';
$fn      = (string) file_get_contents( $THEME . '/functions.php' );
ok( false !== strpos( $fn, "'zdzpvg' => 'ZDZ_PV_GATE_VERSION'" ), 'the version beacon reports it' );
ok( 0 === preg_match( '/^\s*(?:require|include)[^\n]*0-zdz-privacy-view-gate\.php/m', $fn ), 'the theme itself never loads it (it works only from the must-use folder)' );
ok( strcmp( basename( $MU ), '00' ) < 0 && strcmp( basename( $MU ), 'a' ) < 0 && strcmp( basename( $MU ), 'A' ) < 0 && strcmp( basename( $MU ), '1' ) < 0,
	'its name sorts before other must-use plugins (WordPress loads them in file-name order)' );
ok( false === strpos( $musrc, 'error_log( $' ) && 0 === preg_match( '/error_log\([^;]*\$_(?:COOKIE|SERVER|GET|POST|REQUEST)/', $musrc ), 'it logs nothing the request wrote' );

/* ------------------------------------------------------------------ the stand-in site */
$ROOT = sys_get_temp_dir() . '/zdzkpvboot' . bin2hex( random_bytes( 4 ) );
mkdir( $ROOT . '/wp-content/mu-plugins', 0700, true );
mkdir( $ROOT . '/wp-content/plugins/example-probe', 0700, true );
mkdir( $ROOT . '/wp-content/themes/example-old-theme/inc', 0700, true );
copy( $MU, $ROOT . '/wp-content/mu-plugins/' . basename( $MU ) );
if ( ! @symlink( $THEME, $ROOT . '/wp-content/themes/zorderz' ) ) {
	ZDZ_Test_Kpv_Server::remove_tree( $ROOT );
	echo "  SKIPPED: this machine cannot link the theme into a stand-in site, so nothing was served.\n";
	done();
}
// a theme with no early gate: the class without early_gate()
file_put_contents( $ROOT . '/wp-content/themes/example-old-theme/inc/class-zdz-privacy-view.php', "<?php\nclass ZDZ_Privacy_View {\n\tpublic static function gate() {}\n\tpublic static function boot() {}\n}\n" );
// a host's own must-use plugin, standing in: it records that it ran and which cookies it saw
file_put_contents( $ROOT . '/wp-content/mu-plugins/host-example.php', "<?php\nexample_event( 'mu:host', array( 'cookies' => array_keys( \$_COOKIE ) ) );\n" );
// a messaging app's class that narrows each direct-message action to the account's one thread
file_put_contents( $ROOT . '/wp-content/plugins/example-probe/class-zim-privacy-view.php', "<?php\nclass ZIM_Privacy_View {\n\tpublic static function action_ok( \$a ) { return true; }\n\tpublic static function conversation_ok( \$c ) { return true; }\n}\n" );
file_put_contents( $ROOT . '/wp-content/plugins/example-probe/example-probe.php', <<<'PROBE'
<?php
example_event( 'plugin:file', array(
	'signin_fn' => function_exists( 'wp_get_current_user' ),
	'cookies'   => array_keys( $_COOKIE ),
	'header'    => (string) ( $_SERVER['HTTP_COOKIE'] ?? '' ),
	'request'   => array_keys( $_REQUEST ),
	'pv_class'  => class_exists( 'ZDZ_Privacy_View', false ),
	'app_pw'    => array(
		apply_filters( 'wp_is_application_passwords_available_for_user', true, (object) array( 'ID' => 9 ) ),
		apply_filters( 'wp_is_application_passwords_available_for_user', true, (object) array( 'ID' => 3 ) ),
	),
) );
if ( class_exists( 'ZDZ_Privacy_View', false ) && method_exists( 'ZDZ_Privacy_View', 'is_on' ) ) {
	example_event( 'plugin:file:is_on', ZDZ_Privacy_View::is_on() );   // asked before WordPress can say who it is
}
add_action( 'plugins_loaded', function () { example_event( 'plugin:plugins_loaded:-1000' ); }, -1000 );
add_action( 'plugins_loaded', function () { example_event( 'plugin:plugins_loaded' ); }, 10 );
add_action( 'setup_theme', function () { example_event( 'plugin:setup_theme' ); } );
add_action( 'init', function () { example_event( 'plugin:init' ); }, 1 );
foreach ( array( 'zana_list_sessions', 'zim_sidebar', 'zim_post', 'example_read', 'example_signin_form' ) as $a ) {
	add_action( 'wp_ajax_' . $a, function () use ( $a ) { example_event( 'ajax:' . $a ); } );
	add_action( 'wp_ajax_nopriv_' . $a, function () use ( $a ) { example_event( 'ajax-nopriv:' . $a ); } );
}
// as the real apps do: the messaging app loads its Privacy View class on plugins_loaded, and an app declares the read
// it masks itself on the filters there
add_action( 'plugins_loaded', function () {
	if ( ! empty( $GLOBALS['S']['zim'] ) ) {
		require_once __DIR__ . '/class-zim-privacy-view.php';
	}
}, 10 );
add_action( 'plugins_loaded', function () {
	if ( empty( $GLOBALS['S']['no_app'] ) ) {
		add_filter( 'zdz_privacy_view_allowed_ajax', function ( $list ) { $list[] = 'example_read'; return $list; } );
		add_filter( 'zdz_privacy_view_ajax_keys', function ( $rows ) { $rows['example_read'] = array( 'example_id' ); return $rows; } );
	}
}, 20 );
PROBE
);
file_put_contents( $ROOT . '/index.php', <<<'ROUTER'
<?php
error_reporting( -1 );
$ROOT = __DIR__;
$S    = (array) json_decode( (string) file_get_contents( $ROOT . '/state.json' ), true );
define( 'ABSPATH', $ROOT . '/' );
define( 'WP_CONTENT_DIR', $ROOT . '/wp-content' );
$path = (string) substr( (string) $_SERVER['REQUEST_URI'], 0, strcspn( (string) $_SERVER['REQUEST_URI'], '?#' ) );
if ( '/wp-admin/admin-ajax.php' === $path ) {
	define( 'DOING_AJAX', true );
	define( 'WP_ADMIN', true );
} elseif ( 0 === strpos( $path, '/wp-admin/' ) ) {
	define( 'WP_ADMIN', true );
}
function example_event( $e, $x = null ) {
	file_put_contents( $GLOBALS['ROOT'] . '/events.jsonl', json_encode( null === $x ? array( $e ) : array( $e, $x ) ) . "\n", FILE_APPEND );
}

// ---- WordPress's own functions that exist before any plugin loads
$HOOKS = array();
function add_action( $tag, $cb, $priority = 10, $args = 1 ) {
	$GLOBALS['HOOKS'][ $tag ][ $priority ][] = array( $cb, $args );
	return true;
}
function add_filter( $tag, $cb, $priority = 10, $args = 1 ) {
	return add_action( $tag, $cb, $priority, $args );
}
function do_action( $tag, ...$a ) {
	if ( empty( $GLOBALS['HOOKS'][ $tag ] ) ) {
		return;
	}
	ksort( $GLOBALS['HOOKS'][ $tag ] );
	foreach ( $GLOBALS['HOOKS'][ $tag ] as $cbs ) {   // (as in WordPress, a handler added to the priority being run is not run in this pass)
		foreach ( $cbs as $c ) {
			call_user_func_array( $c[0], array_slice( $a, 0, $c[1] ) );
		}
	}
}
function apply_filters( $tag, $value, ...$a ) {
	if ( empty( $GLOBALS['HOOKS'][ $tag ] ) ) {
		return $value;
	}
	ksort( $GLOBALS['HOOKS'][ $tag ] );
	foreach ( $GLOBALS['HOOKS'][ $tag ] as $cbs ) {
		foreach ( $cbs as $c ) {
			$value = call_user_func_array( $c[0], array_slice( array_merge( array( $value ), $a ), 0, max( 1, $c[1] ) ) );
		}
	}
	return $value;
}
function example_kept_options() {
	$file = $GLOBALS['ROOT'] . '/options.json';
	return is_file( $file ) ? (array) json_decode( (string) file_get_contents( $file ), true ) : array();
}
function get_option( $k, $d = false ) {
	$S = $GLOBALS['S'];
	// the site's address (home) is not the address WordPress names its cookies from (siteurl)
	$o = example_kept_options() + array( 'template' => $S['template'] ?? 'zorderz', 'home' => 'http://localhost', 'siteurl' => 'http://127.0.0.1', 'zdz_privacy_view_dm_to' => empty( $S['no_partner'] ) ? 7 : 0 );
	return array_key_exists( $k, $o ) ? $o[ $k ] : $d;
}
function update_option( $k, $v, $autoload = null ) {
	example_event( 'option:written', $k );
	file_put_contents( $GLOBALS['ROOT'] . '/options.json', json_encode( array( $k => $v ) + example_kept_options() ) );
	return true;
}
function delete_option( $k ) {
	example_event( 'option:deleted', $k );
	$o = example_kept_options();
	unset( $o[ $k ] );
	file_put_contents( $GLOBALS['ROOT'] . '/options.json', json_encode( (object) $o ) );
	return true;
}
function get_template_directory() {
	return WP_CONTENT_DIR . '/themes/' . get_option( 'template' );
}
function home_url( $p = '' ) {
	return 'http://localhost' . $p;
}
function is_admin() {
	return defined( 'WP_ADMIN' ) && WP_ADMIN;
}
function wp_doing_ajax() {
	return defined( 'DOING_AJAX' ) && DOING_AJAX;
}
function wp_unslash( $s ) {
	return $s;
}
function __( $s, $d = null ) {
	return $s;
}
function esc_html__( $s, $d = null ) {
	return $s;
}
$USERS = array( 9 => 'privacy-account', 3 => 'jordan', 7 => 'site-admin' );
$META  = array( 9 => array( 'zdz_privacy_view' => '1' ) );
function get_user_meta( $u, $k, $single = false ) {
	return $GLOBALS['META'][ (int) $u ][ $k ] ?? '';
}
// these two lean on WordPress's sign-in functions, which exist only after the plugins have loaded
function user_can( $u, $c ) {
	$user = get_userdata( (int) $u );
	return $user && 'manage_options' === $c && in_array( (int) $user->ID, array( 7, 9 ), true );   // the account holds an administrator's rights too
}
function get_current_user_id() {
	return (int) wp_get_current_user()->ID;
}
function current_user_can( $c ) {
	return user_can( get_current_user_id(), $c );
}
class WP_Error {
	public function __construct( ...$a ) {}
}
class Example_DB {
	public $users    = 'wp_users';
	public $usermeta = 'wp_usermeta';
	public function prepare( $q, ...$a ) {
		foreach ( $a as $x ) {
			$q = preg_replace( '/%s/', "'" . addslashes( (string) $x ) . "'", $q, 1 );
		}
		return $q;
	}
	public function get_col( $q ) {
		example_event( 'db' );
		if ( false === strpos( $q, 'wp_users' ) || false === strpos( $q, 'wp_usermeta' ) || false === strpos( $q, "meta_key = 'zdz_privacy_view'" ) || false === strpos( $q, "meta_value = '1'" ) ) {
			return array();
		}
		$out = array();
		foreach ( $GLOBALS['META'] as $id => $m ) {
			if ( '1' === ( $m['zdz_privacy_view'] ?? '' ) ) {
				$out[] = $GLOBALS['USERS'][ $id ];
			}
		}
		return $out;
	}
}
$wpdb = new Example_DB();
function wp_send_json_error( $d = null, $code = null ) {
	example_event( 'theme:refused', array( $code, $d['why'] ?? '', $d['code'] ?? '' ) );
	http_response_code( (int) $code );
	echo json_encode( array( 'theme_refused' => true ) );
	exit;
}
function wp_die( $m = '', $t = '', $a = array() ) {
	example_event( 'theme:refused', array( (int) ( $a['response'] ?? 500 ), '', '' ) );
	http_response_code( (int) ( $a['response'] ?? 500 ) );
	echo json_encode( array( 'theme_refused' => true ) );
	exit;
}

// ---- wp-settings.php: must-use plugins, in file-name order
$mu = (array) glob( WP_CONTENT_DIR . '/mu-plugins/*.php' );
sort( $mu );
foreach ( $mu as $f ) {
	include_once $f;
}
do_action( 'muplugins_loaded' );
// the cookie constants, settled only now (late_hash: as if something changed the site address after the must-use plugins)
define( 'COOKIEHASH', md5( empty( $S['late_hash'] ) ? 'http://127.0.0.1' : 'http://late.example' ) );
define( 'LOGGED_IN_COOKIE', 'wordpress_logged_in_' . COOKIEHASH );
define( 'AUTH_COOKIE', 'wordpress_' . COOKIEHASH );
define( 'SECURE_AUTH_COOKIE', 'wordpress_sec_' . COOKIEHASH );
// wp-includes/vars.php: $pagenow
$self = (string) $_SERVER['PHP_SELF'];
if ( is_admin() ) {
	preg_match( '#/wp-admin/?(.*?)$#i', $self, $sm );
	$pagenow = preg_replace( '#\?.*?$#', '', trim( $sm[1], '/' ) );
	if ( '' === $pagenow || 'index' === $pagenow || 'index.php' === $pagenow ) {
		$pagenow = 'index.php';
	} else {
		preg_match( '#(.*?)(/|$)#', $pagenow, $sm );
		$pagenow = strtolower( $sm[1] );
		if ( '.php' !== substr( $pagenow, -4 ) ) {
			$pagenow .= '.php';
		}
	}
} else {
	$pagenow = preg_match( '#([^/]+\.php)([?/].*?)?$#i', $self, $sm ) ? strtolower( $sm[1] ) : 'index.php';
}
// regular plugins
include_once WP_CONTENT_DIR . '/plugins/example-probe/example-probe.php';
// wp-includes/pluggable.php: the sign-in functions exist only from here on
$CURRENT_USER = null;
if ( ! function_exists( 'wp_get_current_user' ) ) {   // declared HERE, as pluggable.php does (a top-level function would exist from the start of the file)
	function wp_validate_auth_cookie( $cookie = '', $scheme = '' ) {   // the cookie by its scheme; settles nobody
		$name = 'logged_in' === $scheme ? LOGGED_IN_COOKIE : AUTH_COOKIE;
		$c    = '' !== $cookie ? $cookie : ( $_COOKIE[ $name ] ?? '' );
		$p    = is_string( $c ) ? explode( '|', $c ) : array();
		if ( 4 === count( $p ) && (int) $p[1] > time() && hash_equals( hash_hmac( 'sha256', $p[0] . '|' . $p[1] . '|' . $p[2], 'example-test-key' ), $p[3] ) ) {
			foreach ( $GLOBALS['USERS'] as $id => $login ) {
				if ( $login === $p[0] ) {
					return $id;
				}
			}
		}
		return false;
	}
	function wp_get_current_user() {
		if ( null === $GLOBALS['CURRENT_USER'] ) {
			// WordPress: the determine_current_user filter, with its own two cookie checks first (and whatever else is hooked)
			$GLOBALS['CURRENT_USER'] = (int) apply_filters( 'determine_current_user', (int) ( wp_validate_auth_cookie( '', '' ) ?: wp_validate_auth_cookie( '', 'logged_in' ) ) );
			example_event( 'who', $GLOBALS['CURRENT_USER'] );
		}
		return (object) array( 'ID' => $GLOBALS['CURRENT_USER'] );
	}
	function get_userdata( $id ) {
		return isset( $GLOBALS['USERS'][ (int) $id ] ) ? (object) array( 'ID' => (int) $id, 'user_login' => $GLOBALS['USERS'][ (int) $id ] ) : false;
	}
}
example_event( 'pluggable' );
do_action( 'plugins_loaded' );
example_event( 'after_plugins_loaded' );
$_REQUEST = array_merge( $_GET, $_POST );   // wp_magic_quotes()
do_action( 'setup_theme' );
// the theme's functions.php, as far as Privacy View goes
require_once get_template_directory() . '/inc/class-zdz-privacy-view.php';
if ( property_exists( 'ZDZ_Privacy_View', 'started' ) ) {   // no masking buffer in a test
	$started = new ReflectionProperty( 'ZDZ_Privacy_View', 'started' );
	$started->setAccessible( true );
	$started->setValue( null, true );
}
add_filter( 'determine_current_user', function ( $u ) {   // as the theme's Demo Mode does
	example_event( 'theme:determine_current_user' );
	return $u;
}, 100 );
ZDZ_Privacy_View::boot();
do_action( 'after_setup_theme' );
do_action( 'init' );
if ( is_admin() ) {
	do_action( 'admin_init' );
}
if ( wp_doing_ajax() ) {
	do_action( ( get_current_user_id() ? 'wp_ajax_' : 'wp_ajax_nopriv_' ) . ( $_REQUEST['action'] ?? '' ) );
}
example_event( 'end', array( 'gate' => defined( 'ZDZ_PV_GATE_VERSION' ) ? ZDZ_PV_GATE_VERSION : null ) );
header( 'Content-Type: application/json' );
echo json_encode( array( 'ok' => true ) );
ROUTER
);
file_put_contents( $ROOT . '/state.json', '{}' );

// request_order: PHP may put cookies into $_REQUEST (its default where php.ini names no order)
$server = ZDZ_Test_Kpv_Server::start( $ROOT . '/index.php', array( 'request_order' => 'CGP' ), $ROOT );
if ( null === $server ) {
	ZDZ_Test_Kpv_Server::remove_tree( $ROOT );
	echo "  SKIPPED: PHP's own web server could not be started on 127.0.0.1 here, so nothing was served.\n";
	done();
}

/** A sign-in cookie for $login, as WordPress writes it (the value %-encoded). $how: valid, forged (a bad check), expired, old. */
function signin( $login, $how = 'valid', $site = 'http://127.0.0.1', $prefix = 'wordpress_logged_in_' ) {
	$exp = 'expired' === $how ? time() - 60 : ( 'old' === $how ? time() - 7200 : time() + 3600 );   // expired: inside the hour WordPress allows a form; old: past it
	$tok = 'exampleTok' . substr( md5( $login ), 0, 10 );
	$mac = 'forged' === $how ? str_repeat( '0', 64 ) : hash_hmac( 'sha256', "$login|$exp|$tok", 'example-test-key' );
	return $prefix . md5( $site ) . '=' . rawurlencode( "$login|$exp|$tok|$mac" );
}
/** One real request. Returns [ status, body (decoded JSON or text), ev: [ name => data ], names (in order), raw ]. */
function send( $method, $uri, $body = null, array $headers = array(), array $state = array() ) {
	global $server, $ROOT;
	file_put_contents( $ROOT . '/state.json', json_encode( (object) $state ) );
	@unlink( $ROOT . '/events.jsonl' );
	$r = $server->send( $method, $uri, $body, $headers );
	if ( ! is_array( $r ) ) {
		return null;
	}
	$names = array();
	$ev    = array();
	foreach ( is_file( $ROOT . '/events.jsonl' ) ? (array) file( $ROOT . '/events.jsonl', FILE_IGNORE_NEW_LINES ) : array() as $l ) {
		$e = json_decode( (string) $l, true );
		if ( is_array( $e ) ) {
			$names[]     = $e[0];
			$ev[ $e[0] ] = $e[1] ?? true;
		}
	}
	return array( 'status' => $r['status'], 'body' => is_array( $r['json'] ) ? $r['json'] : $r['text'], 'ev' => $ev, 'names' => $names, 'raw' => $r['head'] . "\r\n\r\n" . $r['text'] );
}
function note() {
	global $ROOT;
	$o = is_file( $ROOT . '/options.json' ) ? (array) json_decode( (string) file_get_contents( $ROOT . '/options.json' ), true ) : array();
	return $o['zdz_privacy_view_declared'] ?? null;
}

$AJAX  = '/wp-admin/admin-ajax.php';
$NS    = '/wp-json/zorderz/v1';
$FORM  = array( 'Content-Type' => 'application/x-www-form-urlencoded; charset=UTF-8' );
$JSONT = array( 'Content-Type' => 'application/json' );
$PV    = signin( 'privacy-account' );
$TEAM  = signin( 'jordan' );
$ADMIN = signin( 'site-admin' );
$HASH  = md5( 'http://127.0.0.1' );
$JUNK  = 'example_cached_page=1; _ga=GA1.1.example; zdz_view_as=3; example-account-override=1; EXAMPLE_Authorization=example.token';
$DOCK  = '/?zim_page=1&zdz_embed=dock&zim_compact=1&c=12';
$ran   = function ( $r, $name ) {
	return is_array( $r ) && in_array( $name, $r['names'], true );
};
// refused before any plugin's file, the host's own must-use plugin, and anyone asking who is signed in
$before_all = function ( $r ) use ( $ran ) {
	return is_array( $r ) && 403 === $r['status'] && ! $ran( $r, 'plugin:file' ) && ! $ran( $r, 'mu:host' ) && ! $ran( $r, 'who' );
};
// let by before the plugins, refused at plugins_loaded before any plugin's handler there
$at_loaded = function ( $r ) use ( $ran ) {
	return is_array( $r ) && 403 === $r['status'] && $ran( $r, 'plugin:file' ) && ! $ran( $r, 'plugin:plugins_loaded:-1000' ) && ! $ran( $r, 'plugin:plugins_loaded' ) && ! $ran( $r, 'plugin:setup_theme' ) && ! $ran( $r, 'theme:refused' );
};
$all_ran = function ( $r, $handler = null ) use ( $ran ) {
	return is_array( $r ) && 200 === $r['status'] && $ran( $r, 'plugin:file' ) && $ran( $r, 'plugin:plugins_loaded:-1000' ) && $ran( $r, 'plugin:setup_theme' ) && $ran( $r, 'plugin:init' ) && $ran( $r, 'end' ) && ( null === $handler || $ran( $r, $handler ) );
};
// refused by the theme's gate (where an app's readiness can be asked), before init and any handler
$at_theme = function ( $r ) use ( $ran ) {
	return is_array( $r ) && 403 === $r['status'] && $ran( $r, 'theme:refused' ) && ! $ran( $r, 'plugin:init' ) && ! preg_grep( '/^ajax/', $r['names'] );
};
$word = function ( $r ) {
	return is_array( $r ) && is_array( $r['body'] ) ? ( $r['body']['data']['why'] ?? null ) : null;
};

section( 'the account, doing what it does every day: everything runs, in order' );
$r = send( 'POST', $AJAX, 'action=zana_list_sessions&nonce=a1b2c3d4e5', $FORM + array( 'Cookie' => $PV ) );
ok( $all_ran( $r, 'ajax:zana_list_sessions' ) && 9 === ( $r['ev']['who'] ?? null ), 'the chat\'s list of past chats: every plugin, every hook, then its handler, as the account', $r['names'] ?? $r );
ok( false === ( $r['ev']['plugin:file']['signin_fn'] ?? null ), '(as in WordPress, a plugin\'s file loads before the sign-in functions exist)', $r['ev']['plugin:file'] ?? null );
ok( $ran( $r, 'theme:determine_current_user' ) && (int) array_search( 'who', $r['names'], true ) > (int) array_search( 'plugin:setup_theme', $r['names'], true ),
	'who is signed in is settled only after the theme loads, so the theme\'s own determine_current_user filters (Demo Mode) still count', $r['names'] );
ok( true === ( $r['ev']['plugin:file']['pv_class'] ?? null ) && true === ( $r['ev']['plugin:file:is_on'] ?? null ), 'a plugin asking "is this an account in Privacy View?" as its file loads is told yes (never a quiet no, never a fatal error)', $r['ev']['plugin:file'] ?? null );
ok( array( false, true ) === ( $r['ev']['plugin:file']['app_pw'] ?? null ), 'application passwords are off for the account before the theme loads, and on for anyone else', $r['ev']['plugin:file'] ?? null );
ok( $VERSION === ( $r['ev']['end']['gate'] ?? null ), 'the file reports its version' );
$r = send( 'GET', $AJAX . '?action=zim_sidebar&nonce=a1b2c3d4e5', null, array( 'Cookie' => $PV ), array( 'zim' => 1 ) );
ok( $all_ran( $r, 'ajax:zim_sidebar' ), 'the messaging list by GET (a messaging app that narrows it is there)', $r['names'] ?? $r );
ok( $all_ran( send( 'GET', '/', null, array( 'Cookie' => $PV ) ) ) && $all_ran( send( 'GET', '/?cb=2.1.0', null, array( 'Cookie' => $PV ) ) ), 'the app shell' );
ok( $all_ran( send( 'GET', $NS . '/notifications/unread-count', null, array( 'Cookie' => $PV, 'X-WP-Nonce' => 'a1b2c3d4e5' ) ) ), 'the bell\'s count, with its nonce header' );
ok( $all_ran( send( 'GET', '/wp-login.php?action=logout&_wpnonce=a1b2c3d4e5', null, array( 'Cookie' => $PV ) ) ), 'signing out' );
$r = send( 'GET', '/wp-admin/profile.php', null, array( 'Cookie' => $PV ) );
ok( $all_ran( $r ) && ! $ran( $r, 'option:written' ), 'its own profile (and its request writes no note)', $r['names'] ?? $r );

section( 'one thing more: refused before ANY plugin has loaded (not even the host\'s own must-use plugin)' );
$r = send( 'POST', $AJAX, 'action=zana_list_sessions&nonce=n&option_page=example_settings&example_setting_add=x', $FORM + array( 'Cookie' => $PV ) );
ok( $before_all( $r ) && 'field' === $word( $r ) && 'zdz_privacy_view' === ( $r['body']['data']['code'] ?? '' ) && false === ( $r['body']['success'] ?? null ) && false !== stripos( $r['raw'], 'X-Zorderz-Privacy-Gate: before-plugins' ),
	'a settings save riding on a read: 403 before any plugin, with the gate\'s code and word', $r );
ok( false !== stripos( (string) ( $r['raw'] ?? '' ), 'Cache-Control: no-store' ), '... never cached' );
ok( $before_all( send( 'POST', '/', 'example_heartbeat=1', $FORM + array( 'Cookie' => $PV ) ) ), 'a POST to the home address (which a page cache may act on as its file loads)' );
ok( $before_all( send( 'GET', '/?example_preview=1', null, array( 'Cookie' => $PV ) ) ), 'the home address with a key of some plugin\'s' );
ok( $before_all( send( 'GET', '/wp-admin/options.php', null, array( 'Cookie' => $PV ) ) ) && $before_all( send( 'GET', '/wp-admin/options.php?rest_route=/zorderz/v1/notifications/unread-count', null, array( 'Cookie' => $PV ) ) ),
	'a wp-admin screen it may not open, also when addressed as the bell' );
ok( $before_all( send( 'POST', $NS . '/consent', '{}', $JSONT + array( 'Cookie' => $PV ) ) ), 'a REST write' );
ok( $before_all( send( 'POST', $AJAX, 'action=example_save_settings&nonce=n', $FORM + array( 'Cookie' => $PV ) ) ), 'an action that is not on its list' );
$r = send( 'POST', $AJAX, 'action=example_read&nonce=n&example_id=5', $FORM + array( 'Cookie' => $PV ) );
ok( $before_all( $r ) && 'not listed' === $word( $r ), 'an app\'s own read, while nothing is noted of what the apps declare', $r );
$r = send( 'POST', $AJAX, 'action=zana_list_sessions&nonce=n', $FORM + array( 'Cookie' => $PV, 'X-Original-URL' => '/wp-admin/options.php' ) );
ok( $before_all( $r ) && 'header' === $word( $r ), 'a header that rewrites the address (X-Original-URL): refused, why "header"', $r );
ok( $before_all( send( 'POST', $AJAX, 'action=zana_list_sessions&nonce=n', $FORM + array( 'Cookie' => $PV, 'X-Rewrite-URL' => '/wp-admin/options.php' ) ) )
	&& $before_all( send( 'POST', $AJAX, 'action=zana_list_sessions&nonce=n', $FORM + array( 'Cookie' => $PV, 'X-HTTP-Method-Override' => 'DELETE' ) ) ), '... X-Rewrite-URL, a method override' );
$r = send( 'GET', '/?example=1', null, array( 'Cookie' => $PV ) );
ok( $before_all( $r ) && is_string( $r['body'] ) && false !== strpos( $r['body'], 'not available in Privacy View' ) && false !== stripos( $r['raw'], 'Cache-Control: no-store' ), 'a page: a plain 403 page saying so, never cached', is_array( $r ) ? substr( $r['raw'], 0, 300 ) : $r );
ok( $before_all( send( 'GET', '/wp-admin/options.php?rest_route=/zorderz/v1/magic-link-init', null, array( 'Cookie' => $PV ) ) ) && $before_all( send( 'GET', '/?rest_route=/zorderz/v1/consent&example=wp-login.php', null, array( 'Cookie' => $PV ) ) )
	&& $before_all( send( 'GET', '/wp-login-example/', null, array( 'Cookie' => $PV ) ) ), 'a wp-admin screen addressed as a sign-in route; "wp-login" in a value, or in a path that is not wp-login.php' );

section( 'cookies: the account keeps only its sign-in cookies, before anything reads them' );
$r    = send( 'POST', $AJAX, 'action=zana_list_sessions&nonce=n', $FORM + array( 'Cookie' => $JUNK . '; ' . $PV . '; wordpress_test_cookie=WP%20Cookie%20check' ) );
$seen = $r['ev']['plugin:file']['cookies'] ?? null;
ok( $all_ran( $r ) && is_array( $seen ) && array( 'wordpress_logged_in_' . $HASH, 'wordpress_test_cookie' ) === array_values( $seen ), 'a plugin\'s file sees only the sign-in cookie and the cookie test (no page cache\'s, analytics\', View As or proxy cookie)', $seen );
$hdr = (string) ( $r['ev']['plugin:file']['header'] ?? '' );
ok( '' !== $hdr && false === strpos( $hdr, 'example_cached_page' ) && false === strpos( $hdr, '_ga' ) && false === strpos( $hdr, 'zdz_view_as' ) && false !== strpos( $hdr, 'wordpress_logged_in_' ), '... and so does anything reading the Cookie header itself', $hdr );
ok( array( 'action', 'nonce' ) === array_values( (array) ( $r['ev']['plugin:file']['request'] ?? null ) ), '... and $_REQUEST holds the request\'s own fields, no cookie (PHP may put cookies in it)', $r['ev']['plugin:file']['request'] ?? null );
ok( ! in_array( 'example_cached_page', (array) ( $r['ev']['mu:host']['cookies'] ?? array( 'example_cached_page' ) ), true ), '... and the host\'s own must-use plugin, which loads after ours', $r['ev']['mu:host'] ?? null );
ok( 9 === ( $r['ev']['who'] ?? null ), '... and WordPress still knows who it is' );
$r = send( 'POST', $AJAX, 'action=zana_list_sessions&nonce=n', $FORM + array( 'Cookie' => $JUNK . '; ' . $TEAM ) );
ok( $all_ran( $r ) && in_array( 'example_cached_page', (array) ( $r['ev']['plugin:file']['cookies'] ?? array() ), true ) && in_array( '_ga', (array) ( $r['ev']['plugin:file']['cookies'] ?? array() ), true )
	&& in_array( 'zdz_view_as', (array) ( $r['ev']['plugin:file']['cookies'] ?? array() ), true ), 'someone else keeps every cookie they sent', $r['ev']['plugin:file'] ?? $r );

section( 'signing in and out: let by before the plugins, judged as soon as WordPress says who it is' );
$r = send( 'POST', $NS . '/magic-link-code-claim', '{"email":"alex@example.com","code":"12345678"}', $JSONT + array( 'Cookie' => $PV ) );
ok( $at_loaded( $r ), 'the account, signed in, asking to claim a sign-in code: let by before the plugins, refused at plugins_loaded before any handler there (it gets no sign-in route)', $r['names'] ?? $r );
ok( $at_loaded( send( 'POST', $NS . '/magic-link-send-code', '{"email":"alex@example.com"}', $JSONT + array( 'Cookie' => $PV ) ) ), '... asking for a code to be sent' );
ok( $at_loaded( send( 'GET', '/wp-login.php?token=x&user_id=3', null, array( 'Cookie' => $PV ) ) ), '... following someone else\'s sign-in link' );
ok( $at_loaded( send( 'POST', '/wp-login.php', 'log=x&pwd=x', $FORM + array( 'Cookie' => $PV ) ) ), '... posting the sign-in form' );
foreach ( array( 'expired' => 'an expired', 'forged' => 'a stale or made-up' ) as $how => $what ) {
	$OLD = signin( 'privacy-account', $how );
	$r   = send( 'POST', $NS . '/magic-link-send-code', '{"email":"alex@example.com"}', $JSONT + array( 'Cookie' => $OLD ) );
	ok( $all_ran( $r ) && 0 === ( $r['ev']['who'] ?? null ) && ! $ran( $r, 'theme:refused' ), "a browser holding $what cookie of the account (signed out): asking for a sign-in code goes through, as for anyone signed out", $r['names'] ?? $r );
	ok( $all_ran( send( 'POST', $NS . '/magic-link-code-claim', '{"email":"alex@example.com","code":"123456"}', $JSONT + array( 'Cookie' => $OLD ) ) )
		&& $all_ran( send( 'GET', $NS . '/magic-link-status?request_id=x1', null, array( 'Cookie' => $OLD ) ) )
		&& $all_ran( send( 'POST', $NS . '/magic-link-init', '{}', $JSONT + array( 'Cookie' => $OLD ) ) )
		&& $all_ran( send( 'POST', $NS . '/magic-link-claim', '{}', $JSONT + array( 'Cookie' => $OLD ) ) ), '... claiming the code; the phone page\'s three routes' );
	ok( $all_ran( send( 'GET', '/wp-login.php', null, array( 'Cookie' => $OLD ) ) ) && $all_ran( send( 'GET', '/', null, array( 'Cookie' => $OLD ) ) ), '... wp-login.php and the app shell' );
	ok( $all_ran( send( 'GET', '/login/', null, array( 'Cookie' => $OLD ) ) ) && $all_ran( send( 'GET', '/login/?zdz_next=%252Fprojects', null, array( 'Cookie' => $OLD ) ) )
		&& $all_ran( send( 'GET', '/login/?magic-login-bridge=1&request_id=x1&bridge_token=x2&bridge_code=123456&zdz_next=%252Fprojects', null, array( 'Cookie' => $OLD ) ) )
		&& $all_ran( send( 'GET', '/register/', null, array( 'Cookie' => $OLD ) ) ), '... the app\'s own sign-in page (where a signed-out person is sent), with the bridge\'s keys, and the register page' );
}
$r = send( 'GET', '/login/', null, array( 'Cookie' => signin( 'privacy-account', 'old' ) ) );
ok( $all_ran( $r ) && ! $ran( $r, 'db' ) && ! $ran( $r, 'plugin:file:is_on' ), 'a cookie of the account that expired over an hour ago is not even looked up: that browser is simply signed out', $r['names'] ?? $r );
$OLD = signin( 'privacy-account', 'forged' );
ok( $before_all( send( 'GET', '/login/?example=1', null, array( 'Cookie' => $OLD ) ) ) && $before_all( send( 'POST', '/login/', 'example=1', $FORM + array( 'Cookie' => $OLD ) ) ) && $before_all( send( 'GET', '/projects/', null, array( 'Cookie' => $OLD ) ) ),
	'... but nothing else of the front end: the sign-in page with another key, by POST, another page' );
ok( $at_loaded( send( 'GET', '/login/', null, array( 'Cookie' => $PV ) ) ), 'the account itself, signed in: the sign-in page is refused at plugins_loaded' );
$r = send( 'POST', $NS . '/magic-link-send-code', '{"email":"alex@example.com"}', $JSONT );
ok( $all_ran( $r ) && ! $ran( $r, 'db' ), 'a signed-out browser with no cookie at all: untouched, and nothing is looked up', $r['names'] ?? $r );
$r = send( 'POST', $AJAX, 'action=example_signin_form&email=alex@example.com', $FORM + array( 'Cookie' => signin( 'privacy-account', 'expired' ) ) );
ok( $before_all( $r ), 'deliberate: a sign-in plugin\'s own AJAX form from a browser still holding the account\'s cookie is refused before the plugins (the app\'s sign-in page does not use one)', $r['names'] ?? $r );

section( 'what the apps declare: noted from an administrator\'s wp-admin request, read before the apps load' );
ok( null === note(), 'nothing is noted yet' );
$r = send( 'GET', '/wp-admin/index.php', null, array( 'Cookie' => $TEAM ) );
ok( $all_ran( $r ) && null === note(), 'a team member\'s wp-admin request notes nothing', $r['names'] ?? $r );
$r = send( 'GET', '/wp-admin/index.php', null, array( 'Cookie' => $ADMIN ) );
ok( $all_ran( $r ) && 'zdz_privacy_view_declared' === ( $r['ev']['option:written'] ?? null ), 'an administrator\'s does (the must-use file is installed, so there is a reader)', $r['names'] ?? $r );
ok( array( 'ajax' => array( 'example_read' => array( 'example_id' ) ), 'rest' => array(), 'signin' => array(), 'headers' => array(), 'login' => array() ) === note(), 'the note: the app\'s read with its one field; nothing of Core\'s own lists', note() );
$r = send( 'GET', '/wp-admin/index.php', null, array( 'Cookie' => $ADMIN ) );
ok( $all_ran( $r ) && ! $ran( $r, 'option:written' ), 'nothing changed: not written again', $r['names'] ?? $r );
$r = send( 'POST', $AJAX, 'action=example_read&nonce=n&example_id=5', $FORM + array( 'Cookie' => $PV ) );
ok( $all_ran( $r, 'ajax:example_read' ), 'now the account\'s read of that app passes every look and reaches its handler', $r['names'] ?? $r );
$r = send( 'POST', $AJAX, 'action=example_read&nonce=n&example_id=5&option_page=example_settings', $FORM + array( 'Cookie' => $PV ) );
ok( $before_all( $r ) && 'field' === $word( $r ), '... with a field more: refused before any plugin', $r );
$r = send( 'POST', $AJAX, 'action=example_read&nonce=n&example_id=5', $FORM + array( 'Cookie' => $PV ), array( 'no_app' => 1 ) );
ok( $at_theme( $r ) && 'not listed' === ( $r['ev']['theme:refused'][1] ?? null ), '... with the app gone but its note still there: refused by the theme\'s gate, which asks the apps themselves (a stale note opens nothing)', $r['names'] ?? $r );
$r = send( 'GET', '/wp-admin/profile.php', null, array( 'Cookie' => $PV ), array( 'no_app' => 1 ) );
ok( $all_ran( $r ) && ! $ran( $r, 'option:written' ) && null !== note(), 'the account\'s own wp-admin request never rewrites the note', $r['names'] ?? $r );
$r = send( 'GET', '/wp-admin/index.php', null, array( 'Cookie' => $ADMIN ), array( 'no_app' => 1 ) );
ok( $all_ran( $r ) && array() === ( note()['ajax'] ?? null ), 'an administrator\'s next wp-admin request brings the note up to date', note() );
ok( $before_all( send( 'POST', $AJAX, 'action=example_read&nonce=n&example_id=5', $FORM + array( 'Cookie' => $PV ), array( 'no_app' => 1 ) ) ), '... and the read is refused before any plugin again' );
send( 'GET', '/wp-admin/index.php', null, array( 'Cookie' => $ADMIN ) );
ok( array( 'example_read' => array( 'example_id' ) ) === ( note()['ajax'] ?? null ), '(the app back, and noted again)' );

section( 'at the theme\'s gate: the parts only an app can answer' );
$r = send( 'GET', $AJAX . '?action=zim_sidebar&nonce=a1b2c3d4e5', null, array( 'Cookie' => $PV ) );
ok( $at_theme( $r ), 'the messaging list with no messaging app that narrows it: held to its fields at both early looks, refused by the theme\'s gate, before init and any handler', $r['names'] ?? $r );
$r = send( 'GET', $DOCK, null, array( 'Cookie' => $PV ) );
ok( $at_theme( $r ), 'the docked direct-message thread with no messaging app that narrows it: refused by the theme\'s gate, before init', $r['names'] ?? $r );
ok( $all_ran( send( 'GET', $DOCK, null, array( 'Cookie' => $PV ), array( 'zim' => 1 ) ) ), '... with one, it goes through' );

section( 'the wp-admin cookie alone, and cookie names WordPress settles on later' );
$PVA = signin( 'privacy-account', 'valid', 'http://127.0.0.1', 'wordpress_' );
ok( $before_all( send( 'GET', '/wp-admin/options.php', null, array( 'Cookie' => $PVA ) ) ) && $all_ran( send( 'GET', '/wp-admin/profile.php', null, array( 'Cookie' => $PVA ) ) ),
	'the account with only its wp-admin cookie: a screen it may not open is refused before the plugins; its profile opens' );
ok( $at_loaded( send( 'POST', $NS . '/magic-link-code-claim', '{}', $JSONT + array( 'Cookie' => $PVA ) ) ), '... and a sign-in route is refused at plugins_loaded (the wp-admin cookie is checked there too)' );
$LATE = signin( 'privacy-account', 'valid', 'http://late.example' );
$r    = send( 'POST', $AJAX, 'action=zana_list_sessions&nonce=n&option_page=example', $FORM + array( 'Cookie' => $LATE ), array( 'late_hash' => 1 ) );
ok( $at_loaded( $r ) && 'field' === $word( $r ), 'cookie names WordPress settled on only after the must-use plugins (the site address changed in between): the account is found again at plugins_loaded and refused there', $r['names'] ?? $r );
ok( $at_loaded( send( 'POST', $NS . '/magic-link-code-claim', '{}', $JSONT + array( 'Cookie' => $LATE ), array( 'late_hash' => 1 ) ) ), '... and judged in full there too (a sign-in route: refused)' );
ok( $all_ran( send( 'POST', $AJAX, 'action=zana_list_sessions&nonce=n', $FORM + array( 'Cookie' => $LATE ), array( 'late_hash' => 1 ) ) )
	&& $all_ran( send( 'POST', $AJAX, 'action=zana_list_sessions&nonce=n&option_page=example', $FORM + array( 'Cookie' => signin( 'jordan', 'valid', 'http://late.example' ) ), array( 'late_hash' => 1 ) ) ),
	'... its allowed requests still run, and a team member is untouched' );

section( 'requests whose answer waits for the theme\'s gate are still held to their shape early' );
$r = send( 'GET', $AJAX . '?action=zim_sidebar&nonce=a1b2c3d4e5&option_page=example', null, array( 'Cookie' => $PV ), array( 'zim' => 1 ) );
ok( $before_all( $r ) && 'field' === $word( $r ), 'the messaging list with a field more: refused before any plugin', $r['names'] ?? $r );
ok( $before_all( send( 'GET', $DOCK . '&example=1', null, array( 'Cookie' => $PV ), array( 'zim' => 1 ) ) ), 'the docked direct-message page with a key more: refused before any plugin' );
$r = send( 'POST', $AJAX, 'action=example_read&nonce=n&example_id=5&option_page=example', $FORM + array( 'Cookie' => $LATE ), array( 'late_hash' => 1 ) );
ok( $at_loaded( $r ) && 'field' === $word( $r ), 'found only at plugins_loaded (cookie names settled late): an app\'s read with a field more is refused there', $r['names'] ?? $r );
ok( $at_theme( send( 'POST', $AJAX, 'action=example_read&nonce=n&example_id=5', $FORM + array( 'Cookie' => $LATE ), array( 'late_hash' => 1, 'no_app' => 1 ) ) )
	&& $all_ran( send( 'POST', $AJAX, 'action=example_read&nonce=n&example_id=5', $FORM + array( 'Cookie' => $LATE ), array( 'late_hash' => 1 ) ) ), '... with no app ready, refused by the theme\'s gate; with one, it goes through' );
$r = send( 'POST', $AJAX, 'action=zim_post&nonce=n&conversation_id=12&body=Hello', $FORM + array( 'Cookie' => $PV ), array( 'no_partner' => 1, 'zim' => 1 ) );
ok( $at_loaded( $r ), 'with nobody chosen to message, a direct message is refused at plugins_loaded (the partner is known by then; only the apps\' classes are not)', $r['names'] ?? $r );
ok( $at_loaded( send( 'GET', $DOCK, null, array( 'Cookie' => $PV ), array( 'no_partner' => 1, 'zim' => 1 ) ) ), '... and the docked direct-message page' );
ok( $at_theme( send( 'POST', $AJAX, 'action=zim_post&nonce=n&conversation_id=12&body=Hello', $FORM + array( 'Cookie' => $PV ) ) ), 'with a partner chosen and no messaging app ready: refused by the theme\'s gate, before init' );
ok( $all_ran( send( 'POST', $AJAX, 'action=zim_post&nonce=n&conversation_id=12&body=Hello', $FORM + array( 'Cookie' => $PV ), array( 'zim' => 1 ) ), 'ajax:zim_post' ), '... with both, it reaches its handler' );

section( 'nobody else' );
$r = send( 'POST', $AJAX, 'action=zana_list_sessions&nonce=n', $FORM + array( 'Cookie' => 'wordpress_logged_in_' . $HASH . '[x]=' . rawurlencode( 'privacy-account|' . ( time() + 99 ) . '|2|3' ) . '; ' . $TEAM ) );
ok( $all_ran( $r ), 'a cookie written as a list never counts as a sign-in cookie (and raises nothing)', $r['names'] ?? $r );
$r = send( 'POST', $AJAX . '?page=example-callback&code=x', 'action=zana_list_sessions&nonce=n&option_page=example', $FORM + array( 'Cookie' => $TEAM, 'X-Original-URL' => '/x' ) );
ok( $all_ran( $r ) && 3 === ( $r['ev']['who'] ?? null ), 'a team member\'s request, with all of that in it, runs as before', $r['names'] ?? $r );
ok( 1 === count( array_keys( $r['names'] ?? array(), 'db', true ) ), '... at the cost of one small query (their cookie looks like a sign-in cookie)' );
ok( false === ( $r['ev']['plugin:file']['pv_class'] ?? null ), '... and the theme\'s class is not loaded any earlier for them' );
$r = send( 'GET', '/?example=1&example_preview=1' );
ok( $all_ran( $r ) && ! $ran( $r, 'db' ) && ! in_array( 'who', array_slice( $r['names'], 0, (int) array_search( 'after_plugins_loaded', $r['names'], true ) ), true ),
	'someone signed out: no query, and nobody asks who is signed in any earlier than before', $r['names'] ?? $r );
$r = send( 'POST', $AJAX, 'action=zana_list_sessions&nonce=n&option_page=example', $FORM + array( 'Cookie' => $TEAM . '; examplefake=' . rawurlencode( 'privacy-account|' . ( time() + 99 ) . '|2|3' )
	. '; wordpress_logged_in_0123456789abcdef0123456789abcdef=' . rawurlencode( 'privacy-account|' . ( time() + 99 ) . '|2|3' ) ) );
ok( $all_ran( $r ) && 3 === ( $r['ev']['who'] ?? null ) && in_array( 'examplefake', (array) ( $r['ev']['plugin:file']['cookies'] ?? array() ), true ),
	'a cookie naming the account under any other name (planted from a sub-domain, or an old site hash) is not read: the team member is untouched', $r['names'] ?? $r );

section( 'with a theme that has no early gate, the must-use file fails closed' );
$OLDT = array( 'template' => 'example-old-theme' );
$r    = send( 'POST', $AJAX, 'action=zana_list_sessions&nonce=n', $FORM + array( 'Cookie' => $PV ), $OLDT );
ok( is_array( $r ) && 403 === $r['status'] && ! $ran( $r, 'plugin:file' ) && is_string( $r['body'] ) && false !== strpos( $r['body'], 'the active theme cannot check this request' ), 'the account\'s request is refused, saying why', $r );
ok( 403 === ( send( 'GET', $NS . '/consent', null, array( 'Cookie' => $PV ), $OLDT )['status'] ?? 0 )
	&& 403 === ( send( 'POST', $NS . '/magic-link-example', '{}', $JSONT + array( 'Cookie' => $PV ), $OLDT )['status'] ?? 0 )
	&& 403 === ( send( 'POST', '/', 'example=1', $FORM + array( 'Cookie' => $PV ), $OLDT )['status'] ?? 0 ), '... another REST route, a route that only looks like a sign-in route, a POST to the home page: refused' );
ok( 403 === ( send( 'GET', '/?example_preview=1', null, array( 'Cookie' => $PV ), $OLDT )['status'] ?? 0 ) && 403 === ( send( 'GET', '/login/?example=1', null, array( 'Cookie' => $PV ), $OLDT )['status'] ?? 0 ),
	'... the home page or the sign-in page with another key: refused' );
ok( 200 === ( send( 'GET', '/login/', null, array( 'Cookie' => $PV ), $OLDT )['status'] ?? 0 ) && 200 === ( send( 'GET', '/', null, array( 'Cookie' => $PV ), $OLDT )['status'] ?? 0 ), '... the sign-in page and the home page open (to sign in again)' );
ok( 200 === ( send( 'GET', '/wp-login.php?action=logout&_wpnonce=n', null, array( 'Cookie' => $PV ), $OLDT )['status'] ?? 0 )
	&& 200 === ( send( 'POST', $NS . '/magic-link-send-code', '{}', $JSONT + array( 'Cookie' => $PV ), $OLDT )['status'] ?? 0 ), '... and wp-login.php and the sign-in routes still open (to sign out, or in)' );
$r = send( 'POST', $AJAX, 'action=zana_list_sessions&nonce=n', $FORM + array( 'Cookie' => $TEAM ), $OLDT );
ok( is_array( $r ) && 200 === $r['status'], '... and nobody else notices', $r['names'] ?? $r );

$lines = $server->log_lines();
$server->stop();
ZDZ_Test_Kpv_Server::remove_tree( $ROOT );
$errs = array_values( array_filter( $lines, function ( $l ) {
	return false === strpos( $l, 'Zorderz Privacy View: refused' );
} ) );
ok( array() === $errs, 'through all of it PHP raised nothing (no fatal error, warning or notice)', array_slice( $errs, 0, 4 ) );
$refusals = array_values( array_filter( $lines, function ( $l ) {
	return false !== strpos( $l, 'Zorderz Privacy View: refused' );
} ) );
ok( count( $refusals ) > 5 && ! array_filter( $refusals, function ( $l ) {
	return false !== strpos( $l, 'example-test-key' ) || false !== strpos( $l, 'exampleTok' ) || false !== strpos( $l, 'privacy-account|' ) || false !== strpos( $l, 'alex@' );
} ), 'each refusal is one log line, with no cookie value in it', array_slice( $refusals, 0, 3 ) );
ok( count( array_filter( $refusals, function ( $l ) {
	return false !== strpos( $l, '(before plugins)' );
} ) ) > 5 && count( array_filter( $refusals, function ( $l ) {
	return false === strpos( $l, '(before plugins)' ) && false === strpos( $l, 'must-use gate' );
} ) ) > 2, '... and the log tells an early refusal from the theme\'s' );

done();
