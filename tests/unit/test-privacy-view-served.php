<?php
/**
 * The Privacy View gate in front of REAL requests, parsed by PHP itself.
 *
 * PrivacyViewWholeRequestTest hands the gate its superglobals and the body's text. That cannot show two things the
 * gate depends on: how PHP really turns an address and a body into $_GET, $_POST and $_FILES, and what php://input
 * really holds (the gate reads it: with that read broken, every ordinary form the account sends would be refused,
 * and no other test would say so). This script starts PHP's own web server on this machine (127.0.0.1 only, a free
 * port, stopped at the end), sends it real requests, and asks the real class for its verdict on each, as the account
 * in Privacy View.
 *
 * Skipped, with a line saying so, where a local server cannot be started. Neutral data only.
 *
 * Run: php tests/unit/test-privacy-view-served.php
 */
if ( 'cli' !== PHP_SAPI ) {
	exit;
}
error_reporting( E_ALL & ~E_DEPRECATED );
require __DIR__ . '/support/privacy-view-served.php';

$pass = 0;
$fail = 0;
function ok( $c, $w, $d = null ) {
	global $pass, $fail;
	if ( $c ) {
		$pass++;
		echo "  ok    $w\n";
	} else {
		$fail++;
		echo "  FAIL  $w" . ( null !== $d ? ' -- ' . substr( (string) json_encode( $d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ), 0, 400 ) : '' ) . "\n";
	}
}
function section( $s ) {
	echo "\n== $s ==\n";
}

/*
 * The page the server runs for every request: WordPress as it stands when the theme's gate runs (the request PHP
 * parsed, which script it is, who is signed in), the real class, and its verdict.
 */
$root   = sys_get_temp_dir() . '/zdzkpvserved' . bin2hex( random_bytes( 4 ) );
$router = $root . '/index.php';
mkdir( $root, 0700, true );
file_put_contents( $root . '/state.json', '{}' );
file_put_contents( $router, strtr( <<<'ROUTER'
<?php
error_reporting( -1 );
$repo  = __REPO__;
$log   = ini_get( 'error_log' );
$state = (array) json_decode( (string) file_get_contents( __DIR__ . '/state.json' ), true );
$path  = (string) substr( (string) $_SERVER['REQUEST_URI'], 0, strcspn( (string) $_SERVER['REQUEST_URI'], '?#' ) );
// which script WordPress would be running, as its own files say it (wp-admin/admin-ajax.php, wp-admin/admin.php)
if ( '/wp-admin/admin-ajax.php' === $path ) {
	define( 'DOING_AJAX', true );
	define( 'WP_ADMIN', true );
} elseif ( 0 === strpos( $path, '/wp-admin/' ) ) {
	define( 'WP_ADMIN', true );
}
require $repo . '/tests/bootstrap-unit.php';
ini_set( 'error_log', $log );   // this run reads what the gate logs
require_once $repo . '/tests/unit/support/privacy-view-gate-doubles.php';

final class ZDZ_Test_Kpv_Stop extends Exception {
	public $data;
	public $status;
	public function __construct( $data, $status ) {
		parent::__construct( 'stop' );
		$this->data   = $data;
		$this->status = $status;
	}
}
function wp_send_json_error( $data = null, $status = null ) {
	throw new ZDZ_Test_Kpv_Stop( $data, (int) $status );
}
function wp_die( $message = '', $title = '', $args = array() ) {
	throw new ZDZ_Test_Kpv_Stop( null, (int) ( $args['response'] ?? 500 ) );
}
function esc_html__( $s, $d = null ) {
	return $s;
}
/** A messaging app that narrows each direct-message action to the account's one thread. */
final class ZIM_Privacy_View {
	public static function action_ok( $action ) { return true; }
	public static function conversation_ok( $id ) { return true; }
}

// wp-includes/vars.php: $pagenow, from PHP_SELF
$self = (string) $_SERVER['PHP_SELF'];
if ( is_admin() ) {
	preg_match( '#/wp-admin/?(.*?)$#i', $self, $m );
	$pagenow = preg_replace( '#\?.*?$#', '', trim( $m[1], '/' ) );
	if ( '' === $pagenow || 'index' === $pagenow || 'index.php' === $pagenow ) {
		$pagenow = 'index.php';
	} else {
		preg_match( '#(.*?)(/|$)#', $pagenow, $m );
		$pagenow = strtolower( $m[1] );
		if ( '.php' !== substr( $pagenow, -4 ) ) {
			$pagenow .= '.php';
		}
	}
} else {
	$pagenow = preg_match( '#([^/]+\.php)([?/].*?)?$#i', $self, $m ) ? strtolower( $m[1] ) : 'index.php';
}
$_REQUEST = array_merge( $_GET, $_POST );   // as WordPress rebuilds it (wp_magic_quotes)

ZDZ_Test_Kpv_Request::people();
$GLOBALS['zdz_test_current_user'] = (int) ( $state['user'] ?? ZDZ_Test_Kpv_Request::ACCOUNT );
$GLOBALS['zdz_test_options']      = array( ZDZ_Privacy_View::DM_OPTION => ZDZ_Test_Kpv_Request::ADMIN );
if ( ! empty( $state['host_login_key'] ) ) {   // a host that marks its wp-login.php links declares its marker
	$GLOBALS['zdz_test_filters']['zdz_privacy_view_login_keys'] = function ( $keys ) {
		$keys[] = 'example-host-login';
		return $keys;
	};
}
ZDZ_Test_Kpv_Request::set( 'started', true );   // no masking buffer here

$verdict = ZDZ_Privacy_View::verdict();
$gate    = 'passed';
try {
	ZDZ_Privacy_View::gate();
} catch ( ZDZ_Test_Kpv_Stop $e ) {
	$gate = is_array( $e->data ) ? 'refused ' . $e->status . ' ' . ( $e->data['why'] ?? '' ) . ' ' . ( $e->data['code'] ?? '' ) : 'refused ' . $e->status . ' (page)';
}
header( 'Content-Type: application/json' );
header( 'X-Example-Gate: ' . ( 'passed' === $gate ? 'passed' : 'refused' ) );   // for a HEAD, which has no page to read
echo json_encode( array(
	'verdict' => $verdict,
	'gate'    => $gate,
	'kind'    => ZDZ_Privacy_View::request_kind(),
	'pagenow' => $pagenow,
	'get'     => array_keys( $_GET ),
	'post'    => array_keys( $_POST ),
	'files'   => array_keys( $_FILES ),
	'left'    => strlen( (string) file_get_contents( 'php://input' ) ),
) );
ROUTER
	, array( '__REPO__' => var_export( dirname( __DIR__, 2 ), true ) ) ) );

$server = ZDZ_Test_Kpv_Server::start( $router, array( 'post_max_size' => '64K' ) );
if ( null === $server ) {
	ZDZ_Test_Kpv_Server::remove_tree( $root );
	echo "  SKIPPED: PHP's own web server could not be started on 127.0.0.1 here, so nothing was served.\n\n0 passed, 0 failed (skipped)\n";
	exit( 0 );
}

/** One real request; the page's answer (for a HEAD, read from its header), or null. */
function send( $method, $uri, $body = null, array $headers = array(), array $state = array() ) {
	global $server, $root;
	file_put_contents( $root . '/state.json', json_encode( (object) $state ) );
	$r = $server->send( $method, $uri, $body, $headers );
	if ( ! is_array( $r ) ) {
		return null;
	}
	if ( is_array( $r['json'] ) ) {
		return $r['json'];
	}
	if ( 'HEAD' === $method && preg_match( '/^X-Example-Gate: (passed|refused)/mi', $r['head'], $m ) ) {
		return array( 'verdict' => 'passed' === $m[1] ? '' : 'refused', 'gate' => 'passed' === $m[1] ? 'passed' : 'refused 403' );
	}
	return null;
}

$AJAX = '/wp-admin/admin-ajax.php';
$BELL = '/wp-json/zorderz/v1/notifications/unread-count';
$FORM = array( 'Content-Type' => 'application/x-www-form-urlencoded;charset=UTF-8' );
$JSON = '{"example_setting_add":"x"}';
$B    = '----exampleBoundary7MA4YWxk';
$part = function ( $name, $value, $file = '' ) use ( $B ) {
	return "--$B\r\nContent-Disposition: form-data; name=\"$name\"" . ( '' !== $file ? "; filename=\"$file\"\r\nContent-Type: image/png" : '' ) . "\r\n\r\n$value\r\n";
};
$MP  = array( 'Content-Type' => "multipart/form-data; boundary=$B" );
$yes = function ( $r ) {
	return is_array( $r ) && '' === $r['verdict'] && 'passed' === $r['gate'];
};
$no = function ( $r, $word = null ) {
	return is_array( $r ) && '' !== $r['verdict'] && 0 === strpos( (string) $r['gate'], 'refused' ) && ( null === $word || "refused 403 $word zdz_privacy_view" === $r['gate'] );
};

section( 'what the account\'s own pages send, as a browser sends it' );
$body = 'nonce=a1b2c3d4e5&project_id=5&action=zdz_jobs_project_detail';
$r    = send( 'POST', $AJAX, $body, $FORM );
ok( $yes( $r ) && array( 'nonce', 'project_id', 'action' ) === $r['post'] && strlen( $body ) === $r['left'] && 'ajax' === $r['kind'],
	'an ordinary form (one project): allowed; the gate read the body and it is still there for a later reader', $r );
ok( $yes( send( 'POST', $AJAX, 'action=zana_refresh_nonce', $FORM ) ), 'the chat\'s keep-alive ("action" alone)' );
ok( $yes( send( 'POST', $AJAX, 'action=zdz_fresh_nonce', $FORM ) ), 'the app\'s keep-alive ("action" alone)' );
ok( $no( send( 'POST', $AJAX, 'action=zdz_fresh_nonce&example=1', $FORM ), 'field' ), '... one field more is refused' );
ok( $yes( send( 'POST', $AJAX, 'nonce=n&view=&action=zdz_jobs_projects_list', $FORM ) ), 'the Projects list (an empty value among its fields)' );
$r = send( 'POST', $AJAX, $part( 'action', 'zana_send_message' ) . $part( 'nonce', 'a1b2c3d4e5' ) . $part( 'session_id', '9' ) . $part( 'message', "A question?\r\nline two & more = 100% {\"a\":1}" ) . "--$B--\r\n", $MP );
ok( $yes( $r ) && 0 === $r['left'], 'the chat\'s send as a form with parts: allowed; PHP has read all of it', $r );
ok( $yes( send( 'POST', $AJAX, 'action=zim_post&nonce=n&conversation_id=4&body=Hello+there&attachment_ids%5B%5D=3&attachment_ids%5B%5D=5&share_refs%5B0%5D%5Btype%5D=p', $FORM ) ),
	'a direct message with its lists (names with brackets, one more than once)' );
ok( $yes( send( 'GET', $AJAX . '?action=zim_poll&nonce=a1b2c3d4e5&conversation_id=4&since=120' ) ) && $yes( send( 'GET', $AJAX . '?action=zim_sidebar&nonce=a1b2c3d4e5' ) ), 'the messaging reads by GET' );
ok( $yes( send( 'GET', '/' ) ) && $yes( send( 'GET', '/?cb=2.1.0' ) ) && $yes( send( 'HEAD', '/' ) ), 'the app shell (GET, HEAD, a cache-buster)' );
ok( $yes( send( 'GET', '/?zdz_media=77&t=0123456789abcdef0123456789abcdef&s=thumb&v=1731300000' ) ), 'a photo' );
$r = send( 'GET', $BELL, null, array( 'X-WP-Nonce' => 'a1b2c3d4e5' ) );
ok( $yes( $r ) && 'rest' === $r['kind'], 'the bell\'s count, with its nonce header', $r );
ok( $yes( send( 'GET', $BELL . '/' ) ) && $yes( send( 'GET', $BELL, null, array( 'Content-Type' => 'application/json' ) ) ), '... with a closing slash; from a script that names a type and sends nothing' );
$r = send( 'GET', '/wp-login.php?action=logout&_wpnonce=a1b2c3d4e5' );
ok( $yes( $r ) && 'login' === $r['kind'], 'signing out', $r );
ok( $yes( send( 'GET', '/wp-login.php?action=logout&redirect_to=https%3A%2F%2Fapp.example.com%2F&_wpnonce=a1b2c3d4e5' ) ), '... with where to go next' );
ok( $no( send( 'GET', '/wp-login.php?action=logout&_wpnonce=a1b2c3d4e5&example-host-login=true' ) ), '... a key more is refused' );
ok( $yes( send( 'GET', '/wp-login.php?action=logout&_wpnonce=a1b2c3d4e5&example-host-login=true', null, array(), array( 'host_login_key' => 1 ) ) ),
	'... unless the site declares it (a host that marks its own sign-in links)' );
$r = send( 'GET', '/wp-admin/profile.php' );
ok( $yes( $r ) && 'admin' === $r['kind'] && 'profile.php' === $r['pagenow'], 'its own profile screen', $r );
$chunk = function ( $t ) {
	return dechex( strlen( $t ) ) . "\r\n" . $t . "\r\n";
};
$r = send( 'POST', $AJAX, $chunk( 'actio' ) . $chunk( 'n=zana_refresh_nonce' ) . "0\r\n\r\n", $FORM + array( 'Transfer-Encoding' => 'chunked' ) );
ok( $yes( $r ), 'an ordinary form sent in chunks, with no length given', $r );

section( 'one thing more, and it is refused' );
ok( $no( send( 'POST', $AJAX, 'action=zana_list_sessions&nonce=n&option_page=example_settings&example_setting_add=x', $FORM ), 'field' ), 'a chat read with a settings save\'s fields in its body' );
ok( $no( send( 'POST', $AJAX . '?page=example-callback&code=x', 'action=zana_list_sessions&nonce=n', $FORM ) ), '... with ?page=…&code= in its address' );
ok( $no( send( 'GET', $AJAX . '?action=zim_sidebar&nonce=n&=code=x' ), 'address' ), 'a pair with no name in the address (PHP drops it; the text still says "code=")' );
ok( $no( send( 'GET', $AJAX . '?action=zim_sidebar&nonce=n&nonce=n' ), 'address' ) && $no( send( 'GET', $AJAX . '?action=zim_sidebar&nonce=n%26x' ), 'address' ), 'a name twice; a value written as %xx' );
ok( $no( send( 'POST', $AJAX . '?action=zana_list_sessions&nonce=n', $JSON, array( 'Content-Type' => 'application/json' ) ) ), 'a JSON body beside an action in the address' );
ok( $no( send( 'POST', $AJAX, 'action=zana_list_sessions&nonce=n&[' . $JSON . ']', $FORM ), 'body' ), 'a pair PHP drops (its name begins with "[") after the listed fields' );
ok( $no( send( 'POST', $AJAX, 'action=example_save_settings&action=zana_list_sessions&nonce=n', $FORM ), 'body' ), 'the action twice in a form' );
ok( $no( send( 'POST', $AJAX, 'action=zana_list_sessions&nonce=n&a.b=1', $FORM ) ) && $no( send( 'POST', $AJAX, 'action=zana_list_sessions&nonce=n&', $FORM ), 'body' ), 'a name PHP rewrites; a trailing "&"' );
ok( $no( send( 'POST', $AJAX, $JSON, array( 'Content-Type' => 'application/x-www-form-urlencodedx' ) ) ) && $no( send( 'POST', $AJAX, $JSON, array( 'Content-Type' => 'multipart/form-data' ) ) ),
	'a type that only begins like a form\'s; a form with parts and no boundary' );
ok( $no( send( 'GET', $AJAX . '?action=zim_sidebar&nonce=n', $JSON, $FORM ), 'body' ) && $no( send( 'GET', '/?cb=1', $JSON, $FORM ) ) && $no( send( 'GET', $BELL, $JSON, array( 'Content-Type' => 'application/json' ) ) ),
	'a GET with a body (an AJAX read, the shell, the bell)' );
ok( $no( send( 'POST', $AJAX, 'action=zana_list_sessions&nonce=n&x=' . str_repeat( 'y', 70000 ), $FORM ) ), 'a form over PHP\'s size limit (here 64K)' );
$r = send( 'POST', $AJAX, $part( 'action', 'zana_send_message' ) . $part( 'nonce', 'n' ) . $part( 'session_id', '9' ) . $part( 'message', 'Hello' ) . $part( 'example_files[]', "\x89PNG example", 'example.png' ) . "--$B--\r\n", $MP );
ok( $no( $r, 'file' ) && array( 'example_files' ) === $r['files'], 'a file: the account uploads nothing', $r );
ok( $no( send( 'POST', $BELL, '{}', array( 'Content-Type' => 'application/json' ) ) ) && $no( send( 'POST', '/wp-json/zorderz/v1/consent', '{}', array( 'Content-Type' => 'application/json' ) ) ), 'a REST write' );
foreach ( array( 'users.php', 'options.php', 'post-new.php', 'plugins.php', 'index.php' ) as $screen ) {
	$r = send( 'GET', "/wp-admin/$screen?rest_route=/zorderz/v1/notifications/unread-count" );
	ok( $no( $r ) && 'admin' === $r['kind'], "wp-admin/$screen with ?rest_route=<the bell's count> is a wp-admin screen, refused", $r );
}
ok( $no( send( 'GET', '/wp-admin/options.php' . $BELL ) ) && $no( send( 'GET', '/projects' . $BELL ) ) && $no( send( 'GET', '/wp-cron.php?rest_route=/zorderz/v1/notifications/unread-count' ) ),
	'... written as a path after the screen; "/wp-json/" further along an address; another script' );
ok( $no( send( 'POST', '/wp-login.php', 'log=x&pwd=x', $FORM ) ) && $no( send( 'GET', '/wp-login.php?action=lostpassword' ) ) && $no( send( 'GET', '/wp-login.php?=code=x' ) )
	&& $no( send( 'POST', '/wp-login.php', $JSON, array( 'Content-Type' => 'application/json' ) ) ), 'wp-login.php: the sign-in form, a password reset, a nameless pair, a JSON body' );
ok( $no( send( 'GET', '/wp-json/zorderz/v1/magic-link-status?request_id=x1' ) ) && $no( send( 'POST', '/wp-json/zorderz/v1/magic-link-send-code', '{"email":"alex@example.com"}', array( 'Content-Type' => 'application/json' ) ) )
	&& $no( send( 'GET', '/login/' ) ), 'a sign-in route or the sign-in page: the account, signed in, gets none' );
ok( $no( send( 'HEAD', '/?example_preview=1' ) ) && $no( send( 'HEAD', $AJAX . '?action=zim_sidebar&nonce=n' ) ), 'by HEAD: the shell with another key; an AJAX read' );
ok( $no( send( 'GET', '/', null, array( 'X-Original-URL' => '/wp-admin/options.php' ) ), null ) && $no( send( 'POST', $AJAX, 'action=zana_refresh_nonce', $FORM + array( 'X-HTTP-Method-Override' => 'DELETE' ) ) ),
	'a header that rewrites the address or the method' );

section( 'only for the account in Privacy View' );
$r = send( 'POST', $AJAX . '?page=example-callback&code=x', $JSON, array( 'Content-Type' => 'application/json' ), array( 'user' => 3 ) );
ok( is_array( $r ) && 'passed' === $r['gate'] && strlen( $JSON ) === $r['left'], 'someone else\'s request passes the gate untouched, its body unread', $r );
$r = send( 'POST', $AJAX . '?page=example-callback&code=x', $JSON, array( 'Content-Type' => 'application/json' ), array( 'user' => 0 ) );
ok( is_array( $r ) && 'passed' === $r['gate'], '... and a signed-out one', $r );

$lines = $server->log_lines();
$server->stop();
ZDZ_Test_Kpv_Server::remove_tree( $root );
$raised = array_values( array_filter( $lines, function ( $l ) {
	// PHP's own words about a request it would not parse (the oversized form, the form with no boundary) are expected
	return ( false !== strpos( $l, 'Warning' ) || false !== strpos( $l, 'Deprecated' ) || false !== strpos( $l, 'Fatal' ) || false !== strpos( $l, 'Notice' ) )
		&& false === strpos( $l, 'POST Content-Length' ) && false === strpos( $l, 'Missing boundary' );
} ) );
ok( array() === $raised, 'through all of it PHP raised nothing from the gate (no warning, no notice)', array_slice( $raised, 0, 3 ) );
$refusals = array_values( array_filter( $lines, function ( $l ) {
	return false !== strpos( $l, 'Zorderz Privacy View: refused' );
} ) );
ok( count( $refusals ) > 20 && ! array_filter( $refusals, function ( $l ) {
	return false !== strpos( $l, 'example_setting_add' ) || false !== strpos( $l, 'alex@' ) || strlen( $l ) > 400;
} ), 'each refusal is one short log line, with nothing of the request\'s values in it', array_slice( $refusals, 0, 2 ) );

echo $fail ? "\n$pass passed, $fail failed\n" : "\nAll {$pass} served-gate assertions passed.\n";
exit( $fail ? 1 : 0 );
