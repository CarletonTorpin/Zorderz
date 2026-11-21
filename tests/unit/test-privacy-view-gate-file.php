<?php
/**
 * ZDZ_Privacy_View_Gate_File: the optional must-use file that runs the Privacy View gate before any plugin loads.
 *
 * A theme never installs a must-use plugin by itself. This script pins what the class does instead:
 *   - it says where things stand: not installed, installed and the one this theme ships, older, or different;
 *   - only an administrator who could install a plugin by hand may install, update or remove the file, and never an
 *     account in Privacy View, the shared device or a View As preview;
 *   - a press goes through a nonce, writes through WordPress's file system layer, reads back what was written, and is
 *     recorded (the audit log, the site's log, the last press for the page);
 *   - where the folder cannot be written from here it says so and names the one file to copy by hand;
 *   - the note the early gate reads of the apps' declarations is written before the file and removed with it;
 *   - Site Health says whether anything needs doing.
 *
 * It runs in its own process: it sets the must-use folder (a temporary one) and the theme folder for the whole run.
 *
 * Run: php tests/unit/test-privacy-view-gate-file.php
 */
if ( 'cli' !== PHP_SAPI ) {
	exit;
}
error_reporting( E_ALL & ~E_DEPRECATED );

$pass = 0;
$fail = 0;
function ok( $c, $w, $d = null ) {
	global $pass, $fail;
	if ( $c ) {
		$pass++;
		echo "  ok    $w\n";
	} else {
		$fail++;
		echo "  FAIL  $w" . ( null !== $d ? ' -- ' . substr( (string) json_encode( $d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ), 0, 700 ) : '' ) . "\n";
	}
}
function section( $s ) {
	echo "\n== $s ==\n";
}

$REPO = dirname( __DIR__, 2 );
$TMP  = sys_get_temp_dir() . '/zdzkpvfile' . bin2hex( random_bytes( 4 ) );
mkdir( $TMP . '/content', 0700, true );
mkdir( $TMP . '/empty-theme', 0700, true );
mkdir( $TMP . '/newer-theme/mu-plugin', 0700, true );
define( 'WPMU_PLUGIN_DIR', $TMP . '/content/mu-plugins' );   // not there yet: the first install makes it

/* ---- what WordPress would answer (each steered by a global a test sets) ---- */
$GLOBALS['kpv_theme'] = $REPO . '/zorderz';
$GLOBALS['kpv_calls'] = array();   // what was done, in order
function get_template_directory() {
	return $GLOBALS['kpv_theme'];
}
function get_user_meta( $uid, $key, $single = false ) {
	return $GLOBALS['zdz_test_user_meta'][ (int) $uid ][ $key ] ?? '';
}
function get_current_user_id() {
	return (int) ( $GLOBALS['zdz_test_current_user'] ?? 0 );
}
function update_option( $k, $v, $autoload = null ) {
	$GLOBALS['kpv_calls'][]           = array( 'update_option', $k, $autoload );
	$GLOBALS['zdz_test_options'][ $k ] = $v;
	return true;
}
function delete_option( $k ) {
	$GLOBALS['kpv_calls'][] = array( 'delete_option', $k );
	unset( $GLOBALS['zdz_test_options'][ $k ] );
	return true;
}
function get_users( $args = array() ) {   // the one question asked here: is anyone's flag set?
	$out = array();
	foreach ( (array) ( $GLOBALS['zdz_test_user_meta'] ?? array() ) as $id => $meta ) {
		if ( isset( $args['meta_key'] ) && (string) ( $meta[ $args['meta_key'] ] ?? '' ) === (string) ( $args['meta_value'] ?? '' ) ) {
			$out[] = (int) $id;
		}
	}
	return $out;
}
function wp_is_file_mod_allowed( $context ) {
	return (bool) ( $GLOBALS['kpv_file_mods'] ?? true );
}
function wp_is_writable( $path ) {
	return ( $GLOBALS['kpv_writable'] ?? true ) && is_writable( $path );
}
function WP_Filesystem() {   // false where the host would ask for credentials
	if ( empty( $GLOBALS['kpv_fs'] ) ) {
		return false;
	}
	$GLOBALS['wp_filesystem'] = $GLOBALS['kpv_fs'];
	return true;
}
function wp_opcache_invalidate( $file, $force = false ) {
	$GLOBALS['kpv_calls'][] = array( 'wp_opcache_invalidate', $file );
	return true;
}
function esc_html( $s ) {
	return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' );
}
function esc_html__( $s, $d = null ) {
	return esc_html( $s );
}
function esc_attr( $s ) {
	return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' );
}
function esc_url( $s ) {
	return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' );
}
function admin_url( $p = '' ) {
	return 'https://app.example.com/wp-admin/' . ltrim( (string) $p, '/' );
}
function wp_nonce_field( $action = -1, $name = '_wpnonce', $referer = true, $echo = true ) {
	$h = '<input type="hidden" name="' . $name . '" value="nonce-for-' . $action . '">';
	if ( $echo ) {
		echo $h;
	}
	return $h;
}
/** WordPress ends the request inside these three; the stand-ins end the handler the same way. */
final class ZDZ_Test_Kpv_End extends Exception {
	public $what;
	public $detail;
	public function __construct( $what, $detail ) {
		parent::__construct( (string) $what );
		$this->what   = $what;
		$this->detail = $detail;
	}
}
function check_admin_referer( $action = -1, $arg = '_wpnonce' ) {
	if ( ( $_REQUEST[ $arg ] ?? '' ) !== 'nonce-for-' . $action ) {
		throw new ZDZ_Test_Kpv_End( 'bad-nonce', $action );
	}
	return 1;
}
function wp_die( $message = '', $title = '', $args = array() ) {
	throw new ZDZ_Test_Kpv_End( 'die', array( $message, (int) ( $args['response'] ?? 500 ) ) );
}
function wp_safe_redirect( $url, $status = 302 ) {
	throw new ZDZ_Test_Kpv_End( 'redirect', $url );
}
final class ZDZ_Admin_Dashboard {
	public static $rows = array();
	public static function log_action( $user_id, $action_type, $detail = '', $app_id = '', $meta = array(), $usage = array() ) {
		self::$rows[] = array( $user_id, $action_type, $detail, $app_id );
	}
}
final class ZDZ_User_Roles {
	public static function is_shared_device( int $uid ): bool {
		return in_array( $uid, (array) ( $GLOBALS['kpv_shared'] ?? array() ), true );
	}
}
final class ZDZ_View_As {
	public static function is_emulating(): bool {
		return ! empty( $GLOBALS['kpv_view_as'] );
	}
}
/** WordPress's file system layer, writing to this machine's disk; told to fail in one way at a time. */
final class ZDZ_Test_Kpv_FS {
	public $fail = '';
	public function find_folder( $dir ) {
		return 'find' === $this->fail ? false : rtrim( (string) $dir, '/' ) . '/';
	}
	public function is_dir( $p ) {
		return is_dir( $p );
	}
	public function mkdir( $p ) {
		return 'mkdir' === $this->fail ? false : @mkdir( $p, 0700 );
	}
	public function exists( $p ) {
		return file_exists( $p );
	}
	public function put_contents( $p, $text, $mode = false ) {
		$GLOBALS['kpv_calls'][] = array( 'put_contents', $p, $mode );
		if ( 'put' === $this->fail ) {
			return false;
		}
		return false !== file_put_contents( $p, 'change' === $this->fail ? $text . "\n// changed on the way\n" : $text );
	}
	public function get_contents( $p ) {
		return is_file( $p ) ? file_get_contents( $p ) : false;
	}
	public function delete( $p ) {
		$GLOBALS['kpv_calls'][] = array( 'delete', $p );
		return 'delete' === $this->fail ? false : @unlink( $p );
	}
}

require $REPO . '/tests/bootstrap-unit.php';
ini_set( 'error_log', $TMP . '/php.log' );   // this run reads what the class logs
require_once $REPO . '/zorderz/inc/class-zdz-privacy-view.php';
require_once $REPO . '/zorderz/inc/class-zdz-privacy-view-gate-file.php';

const ADMIN   = 7;   // manages the site and may install plugins
const LIMITED = 8;   // manages the site; WordPress refuses it plugin installs (file changes are off for it)
const MEMBER  = 3;
const ACCOUNT = 9;   // an administrator's rights, and Privacy View
$GLOBALS['zdz_test_user_roles'] = array( ADMIN => array( 'administrator' ), LIMITED => array( 'administrator' ), MEMBER => array( 'zdz_sales' ), ACCOUNT => array( 'administrator' ) );
$GLOBALS['zdz_test_user_caps']  = array( ADMIN => array( 'manage_options', 'install_plugins' ), LIMITED => array( 'manage_options' ), MEMBER => array( 'install_plugins' ), ACCOUNT => array( 'manage_options', 'install_plugins' ) );
$GLOBALS['zdz_test_user_names'] = array( ADMIN => 'Robin Devin' );
$GLOBALS['zdz_test_user_meta']  = array();
$GLOBALS['zdz_test_options']    = array();
$as = function ( $uid ) {
	$GLOBALS['zdz_test_current_user'] = (int) $uid;
};
$as( ADMIN );

$F       = 'ZDZ_Privacy_View_Gate_File';
$SHIPPED = $REPO . '/zorderz/mu-plugin/' . ZDZ_Privacy_View_Gate_File::FILE;
$TARGET  = WPMU_PLUGIN_DIR . '/' . ZDZ_Privacy_View_Gate_File::FILE;
$NOTE    = ZDZ_Privacy_View::DECLARED_OPT;
$TEXT    = (string) file_get_contents( $SHIPPED );
$V       = ZDZ_Privacy_View_Gate_File::version_of( $TEXT );
$fs      = new ZDZ_Test_Kpv_FS();
$did     = function ( $name ) {
	return array_values( array_filter( $GLOBALS['kpv_calls'], function ( $c ) use ( $name ) {
		return $c[0] === $name;
	} ) );
};
$fresh = function () {
	$GLOBALS['kpv_calls']       = array();
	ZDZ_Admin_Dashboard::$rows = array();
};
$last = function () {
	return $GLOBALS['zdz_test_options'][ ZDZ_Privacy_View_Gate_File::LAST ] ?? null;
};

section( 'what a copy of the file says of itself' );
ok( 1 === preg_match( '/\A[0-9]+\.[0-9]+\.[0-9]+\z/', $V ), 'the theme\'s copy states a version', $V );
ok( 1 === preg_match( "/define\( 'ZDZ_PV_GATE_VERSION', '" . preg_quote( $V, '/' ) . "' \)/", $TEXT ), '... the one it reports when WordPress loads it' );
ok( '2.3.4' === ZDZ_Privacy_View_Gate_File::version_of( "<?php\n/**\n * Plugin Name: Example\n * Version:     2.3.4\n */" ) && '1.0' === ZDZ_Privacy_View_Gate_File::version_of( "<?php\n// Version: 1.0\n" )
	&& '3.0.0-beta' === ZDZ_Privacy_View_Gate_File::version_of( "<?php /*\nversion: 3.0.0-beta\n*/" ), 'a header\'s "Version:" is read, however the comment is written' );
ok( '' === ZDZ_Privacy_View_Gate_File::version_of( '<?php // no header' ) && '' === ZDZ_Privacy_View_Gate_File::version_of( "<?php\n * Version: latest\n" )
	&& '' === ZDZ_Privacy_View_Gate_File::version_of( "<?php\n\$x = 'Version: 9.9.9 and more';\n" ) && '' === ZDZ_Privacy_View_Gate_File::version_of( '<?php ' . str_repeat( ' ', 9000 ) . "\n * Version: 1.2.3\n" ),
	'no header, a word for a version, the words inside a line of code, or a header far down the file: none' );

section( 'where things stand' );
$s = ZDZ_Privacy_View_Gate_File::status();
ok( array( 'state' => 'absent', 'installed' => '', 'shipped' => $V, 'running' => '', 'writable' => true ) === $s, 'nothing installed: absent, and it could be written (the folder above the must-use folder can)', $s );
ok( 'Not installed.' === ZDZ_Privacy_View_Gate_File::status_line( $s ), '... in words' );
ok( WPMU_PLUGIN_DIR === ZDZ_Privacy_View_Gate_File::target_dir() && $TARGET === ZDZ_Privacy_View_Gate_File::target_path() && $SHIPPED === ZDZ_Privacy_View_Gate_File::shipped_path(), 'from the theme\'s mu-plugin/ folder to WordPress\'s must-use folder' );
$GLOBALS['kpv_file_mods'] = false;
ok( false === ZDZ_Privacy_View_Gate_File::status()['writable'], 'a site that forbids file changes: not writable from here' );
$GLOBALS['kpv_file_mods'] = true;
$GLOBALS['kpv_writable']  = false;
ok( false === ZDZ_Privacy_View_Gate_File::status()['writable'], 'a folder this process cannot write: not writable from here' );
$GLOBALS['kpv_writable'] = true;

section( 'who may install, update or remove it' );
ok( true === ZDZ_Privacy_View_Gate_File::may_manage( ADMIN ), 'an administrator who may install plugins' );
ok( false === ZDZ_Privacy_View_Gate_File::may_manage( LIMITED ), 'not one WordPress refuses plugin installs' );
ok( false === ZDZ_Privacy_View_Gate_File::may_manage( MEMBER ), 'not a team member, whatever else they hold' );
ok( false === ZDZ_Privacy_View_Gate_File::may_manage( 0 ) && false === ZDZ_Privacy_View_Gate_File::may_manage( 404 ), 'nobody signed in; nobody at all' );
$GLOBALS['zdz_test_user_meta'][ ACCOUNT ] = array( 'zdz_privacy_view' => '1' );
ok( false === ZDZ_Privacy_View_Gate_File::may_manage( ACCOUNT ), 'never an account in Privacy View, with an administrator\'s rights or not' );
$GLOBALS['kpv_shared'] = array( ADMIN );
ok( false === ZDZ_Privacy_View_Gate_File::may_manage( ADMIN ), 'never the shared device' );
$GLOBALS['kpv_shared']  = array();
$GLOBALS['kpv_view_as'] = true;
ok( false === ZDZ_Privacy_View_Gate_File::may_manage( ADMIN ), 'never during a View As preview' );
$GLOBALS['kpv_view_as'] = false;

section( 'installing it' );
$fresh();
foreach ( array( ACCOUNT, MEMBER, LIMITED, 0 ) as $uid ) {
	$as( $uid );
	ok( 'not-allowed' === ZDZ_Privacy_View_Gate_File::install( $fs, $uid ) && 'not-allowed' === ZDZ_Privacy_View_Gate_File::remove( $fs, $uid ), "user $uid: not allowed" );
}
ok( ! file_exists( $TARGET ) && array() === $GLOBALS['kpv_calls'] && ! isset( $GLOBALS['zdz_test_options'][ $NOTE ] ), '... and nothing was touched' );
$as( ADMIN );
ok( 'not-writable' === ZDZ_Privacy_View_Gate_File::install( null, ADMIN ) && ! file_exists( $TARGET ), 'no file system layer (the host would ask for credentials): "not writable", nothing written' );
ok( is_array( $last() ) && false === $last()['ok'] && 'not-writable' === $last()['why'] && 'install' === $last()['act'] && ADMIN === $last()['by'], '... and the failed press is kept for the page', $last() );
ok( 1 === count( ZDZ_Admin_Dashboard::$rows ) && array( ADMIN, 'privacy_view_gate_file', "the early gate file: install failed (not-writable), version $V", 'privacy-view' ) === ZDZ_Admin_Dashboard::$rows[0], '... and recorded in the audit log', ZDZ_Admin_Dashboard::$rows );
$fs->fail = 'find';
ok( 'not-writable' === ZDZ_Privacy_View_Gate_File::install( $fs, ADMIN ), 'a file system layer that cannot find the folder: "not writable"' );
$fs->fail              = '';
$GLOBALS['kpv_theme'] = $TMP . '/empty-theme';
ok( 'no-copy' === ZDZ_Privacy_View_Gate_File::install( $fs, ADMIN ) && ! file_exists( $TARGET ), 'a theme with no copy of the file: "no copy"' );
ok( '' === ZDZ_Privacy_View_Gate_File::status()['shipped'], '... and the status says the theme ships none' );
$GLOBALS['kpv_theme'] = $REPO . '/zorderz';
$fs->fail              = 'put';
$fresh();
ok( 'write-failed' === ZDZ_Privacy_View_Gate_File::install( $fs, ADMIN ) && ! file_exists( $TARGET ), 'the write fails: "write failed", no file' );
ok( isset( $GLOBALS['zdz_test_options'][ $NOTE ] ), '(the note of what the apps declare was written first)' );
ok( false === ZDZ_Privacy_View::remember_declared() && ! isset( $GLOBALS['zdz_test_options'][ $NOTE ] ), '... and, the file not being there after all, an administrator\'s next wp-admin request takes the note away' );
$fs->fail = '';
$fresh();
$GLOBALS['zdz_test_filters']['zdz_privacy_view_allowed_ajax'] = function ( $list ) {
	$list[] = 'example_read';
	return $list;
};
$GLOBALS['zdz_test_filters']['zdz_privacy_view_ajax_keys'] = function ( $rows ) {
	$rows['example_read'] = array( 'example_id' );
	return $rows;
};
$r = ZDZ_Privacy_View_Gate_File::install( $fs, ADMIN );
ok( 'installed' === $r && is_file( $TARGET ) && hash( 'sha256', (string) file_get_contents( $TARGET ) ) === hash( 'sha256', $TEXT ), 'an administrator\'s press: installed, byte for byte the theme\'s copy (the folder was made)', $r );
$order = array_column( $GLOBALS['kpv_calls'], 0 );
ok( array( 'update_option', $NOTE, true ) === ( $did( 'update_option' )[0] ?? null ) && array_search( 'update_option', $order, true ) < array_search( 'put_contents', $order, true ),
	'the note of what the apps declare is written BEFORE the file (the file never runs without it), where WordPress loads it with every request', $GLOBALS['kpv_calls'] );
ok( array( 'example_read' => array( 'example_id' ) ) === ( $GLOBALS['zdz_test_options'][ $NOTE ]['ajax'] ?? null ), '... holding what the apps declare now' );
ok( array( 'put_contents', $TARGET, 0644 ) === ( $did( 'put_contents' )[0] ?? null ), 'written through the file system layer, readable and not writable by others', $did( 'put_contents' ) );
ok( array( array( 'wp_opcache_invalidate', $TARGET ) ) === $did( 'wp_opcache_invalidate' ), 'PHP\'s cache of the file is dropped, so the next request runs the new one' );
ok( array( array( ADMIN, 'privacy_view_gate_file', "the early gate file: install done, version $V", 'privacy-view' ) ) === ZDZ_Admin_Dashboard::$rows, 'recorded in the audit log: who, what, which version', ZDZ_Admin_Dashboard::$rows );
ok( is_array( $last() ) && true === $last()['ok'] && 'installed' === $last()['why'] && $V === $last()['version'] && ADMIN === $last()['by'] && 1 === preg_match( '/\A\d{4}-\d\d-\d\d \d\d:\d\d:\d\d\z/', (string) $last()['at'] ), '... and kept for the page', $last() );
$s = ZDZ_Privacy_View_Gate_File::status();
ok( 'current' === $s['state'] && $V === $s['installed'] && "Installed, version $V." === ZDZ_Privacy_View_Gate_File::status_line( $s ), 'the status: installed, this theme\'s version', $s );
ok( '' === $s['running'], '(WordPress has not loaded it in this test process, and the status says so)' );
ok( 'updated' === ZDZ_Privacy_View_Gate_File::install( $fs, ADMIN ), 'pressed again: written again, as an update' );

section( 'a newer theme, an older file' );
file_put_contents( $TMP . '/newer-theme/mu-plugin/' . ZDZ_Privacy_View_Gate_File::FILE, str_replace( array( "Version:     $V", "'ZDZ_PV_GATE_VERSION', '$V'" ), array( 'Version:     9.9.9', "'ZDZ_PV_GATE_VERSION', '9.9.9'" ), $TEXT ) );
$GLOBALS['kpv_theme'] = $TMP . '/newer-theme';
$s                    = ZDZ_Privacy_View_Gate_File::status();
ok( 'older' === $s['state'] && $V === $s['installed'] && '9.9.9' === $s['shipped'] && "Installed, version $V. This theme ships version 9.9.9: update it." === ZDZ_Privacy_View_Gate_File::status_line( $s ), 'after a theme update the installed file is older, and the status says to update it', $s );
$fresh();
ok( 'updated' === ZDZ_Privacy_View_Gate_File::install( $fs, ADMIN ) && 'current' === ZDZ_Privacy_View_Gate_File::status()['state'] && '9.9.9' === ZDZ_Privacy_View_Gate_File::status()['installed'], 'the press updates it' );
ok( array( array( ADMIN, 'privacy_view_gate_file', 'the early gate file: update done, version 9.9.9', 'privacy-view' ) ) === ZDZ_Admin_Dashboard::$rows, '... recorded as an update', ZDZ_Admin_Dashboard::$rows );
$GLOBALS['kpv_theme'] = $REPO . '/zorderz';
$s                    = ZDZ_Privacy_View_Gate_File::status();
ok( 'different' === $s['state'] && "Installed (version 9.9.9), but it is not the file this theme ships (version $V). Replace it to be sure of what runs." === ZDZ_Privacy_View_Gate_File::status_line( $s ),
	'a file newer than the theme\'s (the theme was rolled back) is "different", never "older"', $s );
ok( 'updated' === ZDZ_Privacy_View_Gate_File::install( $fs, ADMIN ) && 'current' === ZDZ_Privacy_View_Gate_File::status()['state'], '... and the press puts this theme\'s copy back' );
file_put_contents( $TARGET, $TEXT . "\n// a line added by hand\n" );
ok( 'different' === ZDZ_Privacy_View_Gate_File::status()['state'], 'the same version, changed by hand: different' );
file_put_contents( $TARGET, "<?php\n// no header at all\n" );
$s = ZDZ_Privacy_View_Gate_File::status();
ok( 'different' === $s['state'] && '' === $s['installed'] && false !== strpos( ZDZ_Privacy_View_Gate_File::status_line( $s ), 'version unknown' ), 'another file under that name: different, version unknown', $s );
$fs->fail = 'change';
$fresh();
ok( 'differs' === ZDZ_Privacy_View_Gate_File::install( $fs, ADMIN ) && 'different' === ZDZ_Privacy_View_Gate_File::status()['state'], 'what is on the server after the write is not what was written: "differs" (it is read back, never assumed)' );
ok( false === $last()['ok'] && 'differs' === $last()['why'] && array() === $did( 'wp_opcache_invalidate' ), '... a failed press' );
$fs->fail = '';
ok( 'updated' === ZDZ_Privacy_View_Gate_File::install( $fs, ADMIN ) && 'current' === ZDZ_Privacy_View_Gate_File::status()['state'], '(put right again)' );

section( 'removing it' );
$fs->fail = 'delete';
$fresh();
ok( 'remove-failed' === ZDZ_Privacy_View_Gate_File::remove( $fs, ADMIN ) && is_file( $TARGET ) && isset( $GLOBALS['zdz_test_options'][ $NOTE ] ), 'the delete fails: "remove failed"; the file and its note stay' );
$fs->fail = '';
$fresh();
ok( 'removed' === ZDZ_Privacy_View_Gate_File::remove( $fs, ADMIN ) && ! file_exists( $TARGET ), 'an administrator\'s press: removed' );
ok( ! isset( $GLOBALS['zdz_test_options'][ $NOTE ] ) && array( array( 'delete_option', $NOTE ) ) === $did( 'delete_option' ), '... and the note it read goes with it' );
ok( array( array( ADMIN, 'privacy_view_gate_file', 'the early gate file: remove done', 'privacy-view' ) ) === ZDZ_Admin_Dashboard::$rows && 'removed' === $last()['why'], '... recorded', ZDZ_Admin_Dashboard::$rows );
ok( 'not-there' === ZDZ_Privacy_View_Gate_File::remove( $fs, ADMIN ) && 'absent' === ZDZ_Privacy_View_Gate_File::status()['state'], 'pressed again: there was nothing to remove' );
ok( 'not-writable' === ZDZ_Privacy_View_Gate_File::remove( null, ADMIN ), 'no file system layer: "not writable"' );

section( 'the press itself (admin-post)' );
$GLOBALS['kpv_fs'] = $fs;
$press             = function ( $uid, array $post ) use ( $as ) {
	$as( $uid );
	$_POST    = $post;
	$_REQUEST = $post;
	try {
		ZDZ_Privacy_View_Gate_File::handle();
	} catch ( ZDZ_Test_Kpv_End $e ) {
		return array( $e->what, $e->detail );
	}
	return array( 'returned', null );
};
$good = array( 'action' => ZDZ_Privacy_View_Gate_File::ACTION, '_wpnonce' => 'nonce-for-' . ZDZ_Privacy_View_Gate_File::ACTION, 'do' => 'install' );
foreach ( array( ACCOUNT => 'the account in Privacy View', MEMBER => 'a team member', LIMITED => 'an administrator who may not install plugins', 0 => 'nobody signed in' ) as $uid => $who ) {
	$r = $press( $uid, $good );
	ok( array( 'die', array( 'Not allowed.', 403 ) ) === $r && ! file_exists( $TARGET ), "$who, with a good nonce: 403, nothing done", $r );
}
$r = $press( ADMIN, array( '_wpnonce' => 'another' ) + $good );
ok( 'bad-nonce' === $r[0] && ! file_exists( $TARGET ), 'an administrator without the page\'s nonce: stopped at the nonce, nothing done', $r );
$r = $press( ADMIN, array( 'do' => 'something-else' ) + $good );
ok( array( 'redirect', 'https://app.example.com/wp-admin/admin.php?page=zdz-privacy-view&gate=not-allowed' ) === $r && ! file_exists( $TARGET ), 'a press that is neither install nor remove does nothing', $r );
$r = $press( ADMIN, array( 'do' => '"><script>alert(1)</script>' ) + $good );
ok( array( 'redirect', 'https://app.example.com/wp-admin/admin.php?page=zdz-privacy-view&gate=not-allowed' ) === $r, '... and nothing the request wrote reaches the address it is sent back to', $r );
$r = $press( ADMIN, $good );
ok( array( 'redirect', 'https://app.example.com/wp-admin/admin.php?page=zdz-privacy-view&gate=installed' ) === $r && is_file( $TARGET ), 'an administrator\'s press: installed, and back to the page with one word for what happened', $r );
$GLOBALS['kpv_file_mods'] = false;
$r                        = $press( ADMIN, array( 'do' => 'remove' ) + $good );
ok( array( 'redirect', 'https://app.example.com/wp-admin/admin.php?page=zdz-privacy-view&gate=not-writable' ) === $r && is_file( $TARGET ), 'on a site that forbids file changes the press writes nothing and says so', $r );
$GLOBALS['kpv_file_mods'] = true;
unset( $GLOBALS['kpv_fs'] );
$r = $press( ADMIN, array( 'do' => 'remove' ) + $good );
ok( array( 'redirect', 'https://app.example.com/wp-admin/admin.php?page=zdz-privacy-view&gate=not-writable' ) === $r && is_file( $TARGET ), '... and where the host would ask for credentials', $r );
$GLOBALS['kpv_fs'] = $fs;
$r                 = $press( ADMIN, array( 'do' => 'remove' ) + $good );
ok( array( 'redirect', 'https://app.example.com/wp-admin/admin.php?page=zdz-privacy-view&gate=removed' ) === $r && ! file_exists( $TARGET ), 'remove: removed', $r );
$src = (string) file_get_contents( $REPO . '/zorderz/inc/class-zdz-privacy-view-gate-file.php' );
ok( false !== strpos( $src, "add_action( 'admin_post_' . self::ACTION" ) && false === strpos( $src, 'admin_post_nopriv' ), 'the press is for a signed-in person only' );
ok( 0 === preg_match( '/add_action\(\s*\'(?:init|admin_init|after_setup_theme|after_switch_theme|upgrader_process_complete)\'/', $src ), 'nothing installs, updates or removes the file by itself (no hook but the press and Site Health)' );

section( 'the page section' );
$page = function ( $uid, array $get = array() ) use ( $as ) {
	$as( $uid );
	$_GET = $get;
	ob_start();
	ZDZ_Privacy_View_Gate_File::render();
	return (string) ob_get_clean();
};
ok( '' === $page( ACCOUNT ) && '' === $page( MEMBER ) && '' === $page( 0 ), 'nothing is drawn for the account, a team member or nobody' );
$h = $page( ADMIN );
ok( false !== strpos( $h, '<h2>Early gate</h2>' ) && false !== strpos( $h, '<strong>Not installed.</strong>' ), 'an administrator sees where things stand', $h );
ok( 1 === substr_count( $h, '<form method="post" action="https://app.example.com/wp-admin/admin-post.php"' ) && false !== strpos( $h, 'name="action" value="zdz_privacy_view_gate_file"' ) && false !== strpos( $h, 'name="do" value="install"' )
	&& false !== strpos( $h, 'value="nonce-for-zdz_privacy_view_gate_file"' ) && false !== strpos( $h, 'Install the early gate' ) && false === strpos( $h, 'Remove it' ), '... and one button: install (a form with the nonce)', $h );
ok( false !== strpos( $h, 'nothing is installed unless you press the button' ), '... with what pressing it does' );
ok( false !== strpos( $h, 'Last change: remove, done, ' ) && false !== strpos( $h, ' UTC, by Robin Devin.' ), '... and the last change: what, when, who', $h );
$h = $page( ADMIN, array( 'gate' => 'installed' ) );
ok( false !== strpos( $h, 'notice-success' ) && false !== strpos( $h, 'The early gate is installed. It runs from the next request on.' ), 'back from a press: its result in a sentence', $h );
$h = $page( ADMIN, array( 'gate' => '<script>alert(1)</script>' ) );
ok( false === strpos( $h, 'notice' ) && false === strpos( $h, 'alert' ), 'a result word that is not one of its own draws nothing', $h );
$h = $page( LIMITED );
ok( false === strpos( $h, '<form' ) && false !== strpos( $h, 'Installing it takes an administrator who may install plugins.' ) && false !== strpos( $h, esc_html( $SHIPPED ) ) && false !== strpos( $h, esc_html( WPMU_PLUGIN_DIR . '/' ) ),
	'an administrator who may not install plugins gets no button: the one file to copy by hand, from where to where', $h );
$GLOBALS['kpv_file_mods'] = false;
$h                        = $page( ADMIN );
ok( false === strpos( $h, '<form' ) && false !== strpos( $h, 'The folder cannot be written from here' ) && false !== strpos( $h, 'Copy this one file by hand:' ) && false !== strpos( $h, esc_html( $SHIPPED ) ),
	'where the folder cannot be written from here: no button, the file to copy by hand', $h );
$GLOBALS['kpv_file_mods'] = true;
ZDZ_Privacy_View_Gate_File::install( $fs, ADMIN );
$h = $page( ADMIN );
ok( false !== strpos( $h, "<strong>Installed, version $V.</strong>" ) && false !== strpos( $h, 'WordPress did not load it on this request.' ) && false !== strpos( $h, 'name="do" value="remove"' ) && false === strpos( $h, 'name="do" value="install"' ),
	'installed and current: remove is the only button (and a file WordPress did not load is said to be so)', $h );
file_put_contents( $TARGET, str_replace( "Version:     $V", 'Version:     0.0.1', $TEXT ) );
$h = $page( ADMIN );
ok( false !== strpos( $h, 'name="do" value="install"' ) && false !== strpos( $h, 'Update it' ) && false !== strpos( $h, 'name="do" value="remove"' ), 'installed and older: update, or remove', $h );
$GLOBALS['kpv_theme'] = $TMP . '/empty-theme';
$h                    = $page( ADMIN );
ok( false === strpos( $h, '<form' ) && false !== strpos( $h, 'This theme has no copy of the file.' ), 'a theme with no copy: nothing to press' );
$GLOBALS['kpv_theme'] = $REPO . '/zorderz';

section( 'Site Health' );
$tests = ZDZ_Privacy_View_Gate_File::site_health( array( 'direct' => array( 'other' => array() ) ) );
ok( isset( $tests['direct']['other'], $tests['direct']['zdz_privacy_view_gate_file']['label'] ) && is_callable( $tests['direct']['zdz_privacy_view_gate_file']['test'] ), 'one test of its own, beside the others' );
$run = function () use ( $tests ) {
	return call_user_func( $tests['direct']['zdz_privacy_view_gate_file']['test'] );
};
$r = $run();
ok( 'recommended' === $r['status'] && false !== strpos( $r['description'], 'Installed, version 0.0.1. This theme ships version ' . $V ) && 'zdz_privacy_view_gate_file' === $r['test'], 'an older file: recommended, saying which versions', $r );
ok( false !== strpos( $r['actions'], 'href="https://app.example.com/wp-admin/admin.php?page=zdz-privacy-view"' ), '... with the way to the page' );
ZDZ_Privacy_View_Gate_File::install( $fs, ADMIN );
$r = $run();
ok( 'good' === $r['status'] && 'The Privacy View early gate is installed and up to date' === $r['label'], 'the theme\'s file: good', $r );
ZDZ_Privacy_View_Gate_File::remove( $fs, ADMIN );
$r = $run();
ok( 'recommended' === $r['status'] && 'An account is in Privacy View and the early gate is not installed' === $r['label'], 'not installed while an account is in Privacy View: recommended', $r );
$GLOBALS['zdz_test_user_meta'] = array();
$r                             = $run();
ok( 'good' === $r['status'] && 'No account is in Privacy View, so the early gate has nothing to do' === $r['label'], 'not installed and no account in Privacy View: nothing needs doing', $r );

section( 'the site\'s log' );
$log = is_file( $TMP . '/php.log' ) ? (string) file_get_contents( $TMP . '/php.log' ) : '';
ok( false !== strpos( $log, "Zorderz Privacy View: the early gate file: install done, version $V by user " . ADMIN ) && false !== strpos( $log, 'Zorderz Privacy View: the early gate file: remove done by user ' . ADMIN )
	&& false !== strpos( $log, 'the early gate file: install failed (write-failed)' ), 'every press is one line there: what, the version, who' );
ok( false === strpos( $log, 'Warning' ) && false === strpos( $log, 'Notice' ) && false === strpos( $log, 'Deprecated' ) && false === strpos( $log, 'Fatal' ), 'and PHP raised nothing through all of it', substr( $log, 0, 300 ) );

// tidy
$rm = function ( $d ) use ( &$rm ) {
	foreach ( (array) @scandir( $d ) as $f ) {
		if ( '.' === $f || '..' === $f || ! is_string( $f ) ) {
			continue;
		}
		is_dir( "$d/$f" ) && ! is_link( "$d/$f" ) ? $rm( "$d/$f" ) : @unlink( "$d/$f" );
	}
	@rmdir( $d );
};
$rm( $TMP );

echo $fail ? "\n$pass passed, $fail failed\n" : "\nAll {$pass} gate-file assertions passed.\n";
exit( $fail ? 1 : 0 );
