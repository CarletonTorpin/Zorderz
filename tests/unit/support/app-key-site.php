<?php
/**
 * One moment in a site's life, for the app key's tests (AppKey*Test.php start it; it is not a test by itself).
 *
 * The app's key comes from constants in wp-config.php, and a constant can be defined once per PHP process. So every
 * moment is a process of its own: its own key lines, its own salts, and an options store kept in a file, so that "the
 * next request" is simply the next process. A test names a case; the case looks at the real classes and answers with
 * what it saw, as JSON: { checks: [ [ ok, what ], ... ], logged: [...], writes: n, data: {...} }.
 *
 * What a test hands over (environment):
 *   ZDZK_CASE     the case to run (see the switch below)
 *   ZDZK_STORE    the options store (a JSON file; written only when something is written)
 *   ZDZK_ELOG     the PHP error log of this process
 *   ZDZK_SHOWN    a file that gets everything this process rendered for a browser (pages, notices, Site Health)
 *   ZDZK_INC      the folder holding the three classes (the theme's inc/ unless a test points elsewhere)
 *   ZDZK_KEY, ZDZK_PREV, ZDZK_SITE, ZDZK_LOAD   the wp-config.php lines: ZDZ_APP_KEY, ZDZ_APP_KEY_PREVIOUS,
 *                 ZDZ_APP_KEY_SITE, ZDZ_SECRETS_LOAD (each defined only when given)
 *   ZDZK_WPCONFIG a file to load in place of those lines, as wp-config.php would be
 *   ZDZK_HOME     the site's address (https://app.example.com unless given)
 *   ZDZK_SALTS    which set of WordPress salts this process has ("one" unless given)
 *   ZDZK_K1..K3   other printed keys a case needs (another site's, an earlier one)
 *   ZDZK_WPSALTS  "placeholder" for a wp-config.php whose eight salt lines were never filled in (real-looking otherwise)
 *   ZDZK_SEALED_KEY  the gate codes' own key line (ZDZ_SEALED_KEY), when given
 *   ZDZK_HANDOVER a file for what one moment hands the next without printing it (the gate codes' recovery code)
 *
 * It loads the real role, tier, Demo Mode, View As and Privacy View classes, so "who may manage the key" is answered
 * by the same code a site runs. Made-up keys only, drawn by the tests on every run; nothing here prints one.
 *
 * @package Zorderz\Tests
 */

if ( 'cli' !== PHP_SAPI ) {
	http_response_code( 404 );
	exit;
}
error_reporting( E_ALL );
ini_set( 'display_errors', 'stderr' );

function zdzk_env( $name ) {
	$v = getenv( 'ZDZK_' . $name );
	return false === $v ? '' : (string) $v;
}

$GLOBALS['zdzk_case']       = zdzk_env( 'CASE' );
$GLOBALS['zdzk_store_file'] = zdzk_env( 'STORE' );
$GLOBALS['zdzk_salts']      = '' !== zdzk_env( 'SALTS' ) ? zdzk_env( 'SALTS' ) : 'one';
$GLOBALS['zdzk_checks']     = array();
$GLOBALS['zdzk_logged']     = array();
$GLOBALS['zdzk_data']       = array();
$GLOBALS['zdzk_hooks']      = array();
$GLOBALS['zdzk_writes']     = 0;
$GLOBALS['zdzk_lock']       = '1';     // what GET_LOCK answers: '1' taken, '0' another run holds it
$GLOBALS['zdzk_jobs']       = array();
$GLOBALS['zdzk_nonce_bad']  = false;

$zdzk_saved                  = is_file( $GLOBALS['zdzk_store_file'] ) ? json_decode( (string) file_get_contents( $GLOBALS['zdzk_store_file'] ), true ) : array();
$GLOBALS['zdz_test_options'] = (array) ( $zdzk_saved['options'] ?? array() );
$GLOBALS['zdzk_tables']      = (array) ( $zdzk_saved['tables'] ?? array() );   // what an app keeps outside the options table
unset( $zdzk_saved );

/** Keep what a browser would have been sent, byte for byte, so the test can look through all of it. */
function zdzk_shown( $text ) {
	if ( '' !== zdzk_env( 'SHOWN' ) ) {
		file_put_contents( zdzk_env( 'SHOWN' ), (string) $text . "\n", FILE_APPEND );
	}
	return $text;
}

function zdzk_persist() {
	file_put_contents( $GLOBALS['zdzk_store_file'], json_encode( array( 'options' => $GLOBALS['zdz_test_options'], 'tables' => $GLOBALS['zdzk_tables'] ) ) );
}

/* ---------------------------------------------------------------------- WordPress, as far as these classes ask */

// Before the shared stand-ins: the few this file must answer differently (salts by set, writes that are kept and counted).
function wp_salt( $scheme = 'auth' ) {
	return 'zdzk-unit-salt-' . $GLOBALS['zdzk_salts'] . '-' . $scheme . '-0123456789abcdef0123456789abcdef';
}
function update_option( $k, $v, $autoload = null ) {
	$GLOBALS['zdz_test_options'][ $k ] = $v;
	++$GLOBALS['zdzk_writes'];
	zdzk_persist();
	return true;
}
function delete_option( $k ) {
	unset( $GLOBALS['zdz_test_options'][ $k ] );
	++$GLOBALS['zdzk_writes'];
	zdzk_persist();
	return true;
}
function add_filter( $hook, $cb, $prio = 10, $n = 1 ) {
	$GLOBALS['zdzk_hooks'][] = array( (string) $hook, $cb, (int) $prio );
	return true;
}
function add_action( $hook, $cb, $prio = 10, $n = 1 ) {
	return add_filter( $hook, $cb, $prio, $n );
}
function get_current_user_id() {
	return (int) ( $GLOBALS['zdz_test_current_user'] ?? 0 );
}
function get_user_meta( $uid, $key, $single = false ) {
	return $GLOBALS['zdz_test_user_meta'][ (int) $uid ][ $key ] ?? '';
}

function get_transient( $k ) {
	return $GLOBALS['zdzk_transients'][ $k ] ?? false;
}
function set_transient( $k, $v, $ttl = 0 ) {
	$GLOBALS['zdzk_transients'][ $k ] = $v;
	return true;
}
define( 'ARRAY_A', 'ARRAY_A' );
// The eight salt lines of wp-config.php: filled in (never real values), or left as the sample file has them.
foreach ( array( 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT' ) as $zdzk_c ) {
	define( $zdzk_c, 'placeholder' === zdzk_env( 'WPSALTS' ) ? 'put your unique phrase here' : 'zdzk-unit-' . strtolower( $zdzk_c ) . '-line-0123456789abcdef0123456789' );
}

if ( '' !== zdzk_env( 'HOME' ) ) {
	$GLOBALS['zdz_test_home'] = zdzk_env( 'HOME' );
}
require dirname( __DIR__, 2 ) . '/bootstrap-unit.php';
ini_set( 'error_log', zdzk_env( 'ELOG' ) );
ini_set( 'log_errors', '1' );

function esc_html( $s ) {
	return htmlspecialchars( (string) $s, ENT_QUOTES );
}
function esc_attr( $s ) {
	return htmlspecialchars( (string) $s, ENT_QUOTES );
}
function esc_url( $s ) {
	return (string) $s;
}
function esc_html__( $s, $d = null ) {
	return htmlspecialchars( (string) $s, ENT_QUOTES );
}
function esc_attr__( $s, $d = null ) {
	return htmlspecialchars( (string) $s, ENT_QUOTES );
}
function _n( $one, $many, $n, $d = null ) {
	return 1 === (int) $n ? $one : $many;
}
function admin_url( $p = '' ) {
	return home_url( '/wp-admin/' . ltrim( (string) $p, '/' ) );
}
function wp_parse_url( $u, $c = -1 ) {
	return parse_url( (string) $u, $c );
}
function add_submenu_page( ...$a ) {
	$GLOBALS['zdzk_data']['submenu'] = array( $a[0] ?? '', $a[3] ?? '', $a[4] ?? '' );
	return 'page';
}
function wp_nonce_field( $action ) {
	echo '<input type="hidden" name="_wpnonce" value="nonce-for-' . htmlspecialchars( (string) $action, ENT_QUOTES ) . '">';
}
/** As WordPress does: a press without the page's own nonce goes no further. */
final class ZDZK_Refused extends Exception {}
function check_admin_referer( $action ) {
	if ( ( $_POST['_wpnonce'] ?? '' ) !== 'nonce-for-' . $action ) {
		$GLOBALS['zdzk_nonce_bad'] = true;
		throw new ZDZK_Refused( 'nonce' );
	}
	return 1;
}

/** The audit log: what was written, so a test can read it (and look for a key in it). */
final class ZDZ_Admin_Dashboard {
	public static function log_action( $user_id, $action_type, $detail = '', $app_id = '', $meta = array(), $usage = array() ) {
		$GLOBALS['zdzk_logged'][] = array( (int) $user_id, (string) $action_type, (string) $detail, (string) $app_id );
		return true;
	}
}

/** The options table as $wpdb answers the scan, a named lock, and nothing else. */
final class ZDZK_Site_DB {
	public $prefix    = 'wp_';
	public $options   = 'wp_options';
	public $insert_id = 0;
	public $queries   = 0;
	public $held      = 0;   // named locks this connection holds now
	public $locked    = 0;   // how many times it took one

	public function esc_like( $s ) {
		return addcslashes( (string) $s, '_%\\' );
	}
	public function prepare( $sql, ...$args ) {
		$i = 0;
		return preg_replace_callback(
			'/%[ds]/',
			function ( $m ) use ( &$i, $args ) {
				$v = $args[ $i++ ] ?? '';
				return '%d' === $m[0] ? (string) (int) $v : "'" . addslashes( (string) $v ) . "'";
			},
			$sql
		);
	}
	/** SELECT option_id, option_value FROM wp_options WHERE option_id > N AND option_value LIKE '%needle%' ORDER BY option_id LIMIT M */
	public function get_results( $sql ) {
		++$this->queries;
		if ( ! preg_match( '/FROM wp_options WHERE option_id > (\d+) AND option_value LIKE \'%(.*)%\' ORDER BY option_id ASC LIMIT (\d+)$/', (string) $sql, $m ) ) {
			return array();
		}
		$needle = stripcslashes( $m[2] );
		$out    = array();
		$id     = 0;
		foreach ( $GLOBALS['zdz_test_options'] as $value ) {
			++$id;
			$value = is_array( $value ) ? serialize( $value ) : (string) $value;
			if ( $id > (int) $m[1] && false !== strpos( $value, $needle ) ) {
				$out[] = (object) array(
					'option_id'    => $id,
					'option_value' => $value,
				);
				if ( count( $out ) >= (int) $m[3] ) {
					break;
				}
			}
		}
		return $out;
	}
	/* What the gate-code vault asks of a database: its two tables, kept with the rest of what apps keep. */
	public function get_charset_collate() {
		return '';
	}
	public function insert( $table, $row, $fmt = null ) {
		$rows            = (array) ( $GLOBALS['zdzk_tables'][ $table ] ?? array() );
		$this->insert_id = count( $rows ) + 1;
		$GLOBALS['zdzk_tables'][ $table ][ (string) $this->insert_id ] = array( 'id' => $this->insert_id ) + $row;
		zdzk_persist();
		return 1;
	}
	public function get_row( $sql, $out = null ) {
		if ( preg_match( '/FROM `([^`]+)` WHERE id = (\d+)/', (string) $sql, $m ) ) {
			return $GLOBALS['zdzk_tables'][ $m[1] ][ $m[2] ] ?? null;
		}
		return null;
	}

	/** SELECT GET_LOCK( name, 0 ): '1' and the lock is held, or '0' when another run holds it. SELECT MAX(id) of a vault table. */
	public function get_var( $sql ) {
		if ( preg_match( '/^SELECT MAX\(id\) FROM `([^`]+)`/', (string) $sql, $m ) ) {
			$ids = array_map( 'intval', array_keys( (array) ( $GLOBALS['zdzk_tables'][ $m[1] ] ?? array() ) ) );
			return $ids ? max( $ids ) : null;
		}
		if ( false === strpos( (string) $sql, 'GET_LOCK' ) ) {
			return null;
		}
		if ( '1' === $GLOBALS['zdzk_lock'] ) {
			++$this->held;
			++$this->locked;
		}
		return $GLOBALS['zdzk_lock'];
	}
	/** SELECT RELEASE_LOCK( name ), and the vault's CREATE TABLE IF NOT EXISTS */
	public function query( $sql ) {
		if ( false !== strpos( (string) $sql, 'RELEASE_LOCK' ) && $this->held > 0 ) {
			--$this->held;
		}
		if ( preg_match( '/^CREATE TABLE IF NOT EXISTS `([^`]+)`/', (string) $sql, $m ) && ! isset( $GLOBALS['zdzk_tables'][ $m[1] ] ) ) {
			$GLOBALS['zdzk_tables'][ $m[1] ] = array();
		}
		return 1;
	}
}
$GLOBALS['wpdb'] = new ZDZK_Site_DB();

/* ---------------------------------------------------------------------- the site's wp-config.php lines */

if ( '' !== zdzk_env( 'WPCONFIG' ) ) {
	require zdzk_env( 'WPCONFIG' );
} else {
	foreach ( array( 'KEY' => 'ZDZ_APP_KEY', 'PREV' => 'ZDZ_APP_KEY_PREVIOUS', 'SITE' => 'ZDZ_APP_KEY_SITE' ) as $zdzk_e => $zdzk_c ) {
		if ( '' !== zdzk_env( $zdzk_e ) ) {
			define( $zdzk_c, zdzk_env( $zdzk_e ) );
		}
	}
	if ( '' !== zdzk_env( 'LOAD' ) ) {
		define( 'ZDZ_SECRETS_LOAD', true );
	}
}
if ( '' !== zdzk_env( 'SEALED_KEY' ) ) {
	define( 'ZDZ_SEALED_KEY', zdzk_env( 'SEALED_KEY' ) );
}

/* ---------------------------------------------------------------------- the real classes */

$zdzk_inc  = dirname( __DIR__, 3 ) . '/zorderz/inc';
$zdzk_keys = '' !== zdzk_env( 'INC' ) ? rtrim( zdzk_env( 'INC' ), '/' ) : $zdzk_inc;
foreach ( array( 'user-roles', 'hierarchy', 'capabilities', 'kiosk-demo', 'view-as', 'privacy-view', 'core-crypto', 'sealed', 'salt-watch' ) as $zdzk_f ) {
	require_once $zdzk_inc . '/class-zdz-' . $zdzk_f . '.php';
}
foreach ( array( 'keys', 'keys-migrate', 'keys-admin' ) as $zdzk_f ) {
	require_once $zdzk_keys . '/class-zdz-' . $zdzk_f . '.php';
}

// The people of this site. 5 and 6 carry the shared-device trait under another role's name.
const ZDZK_ADMIN = 1;   // an administrator
const ZDZK_SALES = 2;   // a salesperson
const ZDZK_SHARED = 3;  // the shared device
const ZDZK_ADMIN2 = 4;  // another administrator
const ZDZK_TRAIT = 5;   // a field role that carries the shared-device trait
const ZDZK_OWNER_TRAIT = 6; // an owner-level role that carries it
const ZDZK_PRIVACY = 7; // an administrator's role, in Privacy View
$GLOBALS['zdz_test_user_roles']   = array(
	ZDZK_ADMIN       => array( 'administrator' ),
	ZDZK_SALES       => array( 'zdz_sales' ),
	ZDZK_SHARED      => array( 'zdz_general' ),
	ZDZK_ADMIN2      => array( 'administrator' ),
	ZDZK_TRAIT       => array( 'zdz_tech' ),
	ZDZK_OWNER_TRAIT => array( 'zdz_owner' ),
	ZDZK_PRIVACY     => array( 'administrator' ),
);
$GLOBALS['zdz_test_user_caps']    = array(
	ZDZK_TRAIT       => array( 'zdz_is_shared_device' ),
	ZDZK_OWNER_TRAIT => array( 'zdz_is_shared_device', 'manage_options' ),
);
$GLOBALS['zdz_test_user_names']   = array(
	ZDZK_ADMIN  => 'Alex',
	ZDZK_SALES  => 'Jordan',
	ZDZK_ADMIN2 => 'Sam',
);
$GLOBALS['zdz_test_user_meta']    = array( ZDZK_PRIVACY => array( ZDZ_Privacy_View::META => '1' ) );
$GLOBALS['zdz_test_current_user'] = ZDZK_ADMIN;

/* ---------------------------------------------------------------------- helpers for the cases */

require_once __DIR__ . '/app-key-format.php';   // the stored format, from its description: zdzk_kid(), zdzk_seal_under(), ...

function zdzk_ok( $cond, $what ) {
	$GLOBALS['zdzk_checks'][] = array( true === $cond, (string) $what );
}
function zdzk_has( $hay, $needle ) {
	return false !== strpos( (string) $hay, (string) $needle );
}
function zdzk_opt( $name ) {
	return $GLOBALS['zdz_test_options'][ $name ] ?? null;
}
/** The page as an administrator's browser gets it (entities read back), after an optional press. */
function zdzk_page( $post = null, $with_nonce = true ) {
	if ( null !== $post ) {
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                     = $with_nonce ? $post + array( '_wpnonce' => 'nonce-for-' . ZDZ_Keys_Admin::NONCE ) : $post;
	} else {
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_POST                     = array();
	}
	ob_start();
	try {
		ZDZ_Keys_Admin::render();
	} catch ( ZDZK_Refused $e ) {
		echo '[refused: ' . $e->getMessage() . ']';
	}
	$_POST                     = array();
	$_SERVER['REQUEST_METHOD'] = 'GET';
	return html_entity_decode( (string) zdzk_shown( ob_get_clean() ), ENT_QUOTES );
}
function zdzk_notice() {
	ob_start();
	ZDZ_Keys_Admin::notice();
	return html_entity_decode( (string) zdzk_shown( ob_get_clean() ), ENT_QUOTES );
}
/** The Site Health item, as WordPress would show it. */
function zdzk_health() {
	$h = ZDZ_Keys_Admin::site_health_result();
	zdzk_shown( implode( ' ', array( $h['label'], $h['description'], $h['actions'] ) ) );
	return $h;
}
/** A new request: this process forgets what it read, as the next page load would. */
function zdzk_next_request() {
	ZDZ_Keys::reset();
}
/**
 * Register a job over a table an app keeps for itself (never in the options table). Values written as "legacy:<text>"
 * are what the app's reader of today understands; anything else it cannot read.
 */
function zdzk_job( $id, array $over = array() ) {
	$GLOBALS['zdzk_jobs'][ $id ] = $over + array(
		'label'   => 'Example rows (' . $id . ')',
		'purpose' => 'credentials',
		'items'   => function () use ( $id ) {
			return array_map( 'strval', array_keys( (array) ( $GLOBALS['zdzk_tables'][ $id ] ?? array() ) ) );
		},
		'stored'  => function ( $item ) use ( $id ) {
			return $GLOBALS['zdzk_tables'][ $id ][ $item ] ?? null;
		},
		'legacy'  => function ( $item, $raw ) {
			return 0 === strpos( (string) $raw, 'legacy:' ) ? substr( (string) $raw, 7 ) : null;
		},
		'write'   => function ( $item, $value ) use ( $id ) {
			$GLOBALS['zdzk_tables'][ $id ][ $item ] = $value;
			zdzk_persist();
			return true;
		},
	);
	$GLOBALS['zdz_test_filters']['zdz_keys_migrations'] = function ( $jobs ) {
		return array_merge( (array) $jobs, $GLOBALS['zdzk_jobs'] );
	};
}
function zdzk_rows( $id, array $rows ) {
	$GLOBALS['zdzk_tables'][ $id ] = $rows;
	zdzk_persist();
}
function zdzk_row( $id, $item ) {
	return $GLOBALS['zdzk_tables'][ $id ][ $item ] ?? null;
}
function zdzk_static( $class, $prop, $value ) {
	$r = new ReflectionProperty( $class, $prop );
	$r->setAccessible( true );
	$r->setValue( null, $value );
}
$K   = defined( 'ZDZ_APP_KEY' ) ? (string) constant( 'ZDZ_APP_KEY' ) : '';
$P   = defined( 'ZDZ_APP_KEY_PREVIOUS' ) ? (string) constant( 'ZDZ_APP_KEY_PREVIOUS' ) : '';
$K1  = zdzk_env( 'K1' );
$K2  = zdzk_env( 'K2' );
$K3  = zdzk_env( 'K3' );
$ME  = 'app.example.com';
$CRE = ZDZ_Keys::PURPOSES['credentials'];

/* ---------------------------------------------------------------------- the cases */

switch ( $GLOBALS['zdzk_case'] ) {

	/* ============================== nothing set up: the service does nothing at all */

	case 'dark':
		zdzk_ok( 'missing' === ZDZ_Keys::state() && ! ZDZ_Keys::set_up() && ! ZDZ_Keys::ready(), 'no key line and nothing recorded: state missing, not set up' );
		zdzk_ok( null === ZDZ_Keys_Admin::banner() && '' === zdzk_notice(), 'no notice on any admin page' );
		zdzk_ok( null === ZDZ_Keys::seal( 'credentials', 'value-EXAMPLE-0001', 'row' ) && null === ZDZ_Keys::mac( 'sign', 'message' ), 'nothing is sealed or signed' );
		zdzk_ok( false === ZDZ_Keys::verify_mac( 'sign', 'message', str_repeat( 'a', 64 ) ), 'no signature verifies' );
		$looks = 'zdzk1:0123abcd:' . str_repeat( 'A', 60 );
		zdzk_ok( null === ZDZ_Keys::open( 'credentials', $looks, 'row' ) && 'missing' === ZDZ_Keys::why( $looks ), 'a sealed value opens as null, never as an empty string, and says why' );
		zdzk_ok( '' === ZDZ_Keys::key_id() && ! ZDZ_Keys::rotating() && null === ZDZ_Keys::recordable() && false === ZDZ_Keys::record_key( ZDZK_ADMIN ), 'no key id, nothing to record' );
		$h = zdzk_health();
		zdzk_ok( 'good' === $h['status'] && zdzk_has( $h['label'], 'not set up' ) && 'zdz_app_key' === $h['test'], 'Site Health: good, and says it is not set up' );
		$p = zdzk_page();
		zdzk_ok( zdzk_has( $p, 'Not set up yet' ) && zdzk_has( $p, 'php bin/make-app-keys.php --site=app.example.com' ), 'the page shows the set-up steps, with the script and this site' );
		zdzk_ok( zdzk_has( $p, "define( 'ZDZ_SECRETS_LOAD', true );" ) && zdzk_has( $p, "if ( is_readable( __DIR__ . '/zdz-secrets.php' ) ) { require_once __DIR__ . '/zdz-secrets.php'; }" ), 'and the two wp-config.php lines, the require guarded' );
		zdzk_ok( ! zdzk_has( $p, 'Record this key' ) && ! zdzk_has( $p, 'Re-encrypt everything now' ) && zdzk_has( $p, 'Nothing is registered yet' ), 'nothing to record, nothing to run' );
		$p = zdzk_page( array( 'zdz_keys_do' => 'run_all' ) );
		zdzk_ok( zdzk_has( $p, 'Nothing was moved: the app\'s key is missing.' ), 'a press to re-encrypt says the key is missing' );
		$p = zdzk_page( array( 'zdz_keys_do' => 'record' ) );
		zdzk_ok( zdzk_has( $p, 'Nothing was recorded.' ), 'a press to record records nothing' );
		zdzk_ok( array() === ZDZ_Keys_Migrate::run_all( 200, ZDZK_ADMIN ) && array() === $GLOBALS['zdzk_logged'], 'nothing runs, nothing is logged' );
		zdzk_ok( 0 === $GLOBALS['zdzk_writes'] && null === zdzk_opt( 'zdz_keys_state' ) && null === zdzk_opt( 'zdz_keys_ever' ), 'no read and no press wrote anything' );
		break;

	case 'file_loaded_but_no_key':
		// wp-config.php has the ZDZ_SECRETS_LOAD line, but no key came from the file (missing, or unreadable)
		zdzk_ok( defined( 'ZDZ_SECRETS_LOAD' ) && ! ZDZ_Keys::set_up() && null === ZDZ_Keys_Admin::banner() && '' === zdzk_notice(), 'no key from the file: still not set up, no notice' );
		zdzk_ok( zdzk_has( zdzk_page(), 'no ZDZ_APP_KEY came from it' ), 'the page says the file was not read' );
		zdzk_ok( 0 === $GLOBALS['zdzk_writes'], 'nothing written' );
		break;

	/* ============================== first use, and the ready state */

	case 'first_use':
		zdzk_ok( 'ok' === ZDZ_Keys::state() && ZDZ_Keys::ready() && ZDZ_Keys::set_up(), 'a key, nothing ever recorded, nothing sealed anywhere: ready' );
		$st = (array) zdzk_opt( 'zdz_keys_state' );
		zdzk_ok( zdzk_kid( $K ) === ( $st['kid'] ?? '' ) && 'first' === ( $st['how'] ?? '' ) && 0 === ( $st['by'] ?? -1 ) && $ME === ( $st['site'] ?? '' ), 'the key id is recorded once, by the app, with this site' );
		zdzk_ok( 1 === zdzk_opt( 'zdz_keys_ever' ), 'and that a key was ever recorded here' );
		zdzk_ok( array( 'kid', 'prev_kid', 'site', 'at', 'by', 'how' ) === array_keys( $st ), 'the record holds a key id, a site, a time and who: nothing else' );
		zdzk_ok( zdzk_kid( $K ) === ZDZ_Keys::key_id() && 1 === preg_match( '/^[0-9a-f]{8}$/D', ZDZ_Keys::key_id() ), 'the key id is 8 hex, by the documented rule' );
		zdzk_ok( null === ZDZ_Keys_Admin::banner() && '' === zdzk_notice(), 'ready: no notice' );
		$h = zdzk_health();
		zdzk_ok( 'good' === $h['status'] && zdzk_has( $h['label'], ZDZ_Keys::key_id() ), 'Site Health: good, with the key id' );
		$GLOBALS['zdz_test_current_user'] = ZDZK_SALES;
		zdzk_ok( '' === zdzk_notice(), 'someone who is not an administrator never sees a notice' );
		$GLOBALS['zdz_test_current_user'] = ZDZK_ADMIN;
		$w = $GLOBALS['zdzk_writes'];
		zdzk_next_request();
		zdzk_ok( 'ok' === ZDZ_Keys::state() && $w === $GLOBALS['zdzk_writes'], 'the next request reads the record and writes nothing' );
		zdzk_ok( null === ZDZ_Keys::recordable() && false === ZDZ_Keys::record_key( ZDZK_ADMIN, $K ), 'nothing more to record while it is recorded and no key change is under way' );
		break;

	case 'site_line_any_spelling':
		zdzk_ok( 'ok' === ZDZ_Keys::state(), 'a site line written with a scheme, www., a port, a path and capitals names this host' );
		$i = ZDZ_Keys::info();
		zdzk_ok( $ME === $i['site_const'] && $ME === $i['host'], 'both are shown as the bare host' );
		break;

	/* ============================== sealing, opening, signing: the format */

	case 'format':
		$s = ZDZ_Keys::seal( 'credentials', 'provider-key-EXAMPLE-0001', 'zdz_example_row' );
		zdzk_ok( is_string( $s ) && 1 === preg_match( '/^zdzk1:[0-9a-f]{8}:[A-Za-z0-9_-]+$/D', $s ) && ZDZ_Keys::is_zdzk1( $s ), 'a sealed value is zdzk1:<key id>:<base64url>' );
		zdzk_ok( ZDZ_Keys::key_id() === ZDZ_Keys::header_key_id( $s ) && zdzk_kid( $K ) === substr( $s, 6, 8 ), 'its header names the key by id' );
		zdzk_ok( ! zdzk_has( $s, 'provider-key' ) && ! zdzk_has( $s, '=' ), 'never the plaintext, no padding' );
		zdzk_ok( 'provider-key-EXAMPLE-0001' === ZDZ_Keys::open( 'credentials', $s, 'zdz_example_row' ) && '' === ZDZ_Keys::why( $s, 'credentials', 'zdz_example_row' ), 'it opens for its purpose and its row' );
		zdzk_ok( 'provider-key-EXAMPLE-0001' === zdzk_open_under( $K, $CRE, 'credentials', $s, 'zdz_example_row' ), 'an independent reader of the documented format opens it (subkey id, context, bound data)' );
		$t = zdzk_seal_under( $K, $CRE, 'credentials', 'provider-key-EXAMPLE-0002', 'zdz_example_row' );
		zdzk_ok( 'provider-key-EXAMPLE-0002' === ZDZ_Keys::open( 'credentials', $t, 'zdz_example_row' ), 'and what an independent writer seals, the service opens' );
		zdzk_ok( null === ZDZ_Keys::open( 'credentials', $s, 'zdz_other_row' ) && 'tampered' === ZDZ_Keys::why( $s, 'credentials', 'zdz_other_row' ), 'moved to another row: it does not open' );
		zdzk_ok( null === ZDZ_Keys::open( 'inbox-tokens', $s, 'zdz_example_row' ) && 'tampered' === ZDZ_Keys::why( $s, 'inbox-tokens', 'zdz_example_row' ), 'read for another purpose: it does not open' );
		zdzk_ok( false === zdzk_open_under( $K, ZDZ_Keys::PURPOSES['inbox-tokens'], 'credentials', $s, 'zdz_example_row' ), 'another purpose has another subkey (the purpose is not only in the bound data)' );
		$other = substr( $s, 0, 6 ) . ( 'aaaaaaaa' === substr( $s, 6, 8 ) ? 'bbbbbbbb' : 'aaaaaaaa' ) . substr( $s, 14 );
		zdzk_ok( null === ZDZ_Keys::open( 'credentials', $other, 'zdz_example_row' ) && 'unknown_key' === ZDZ_Keys::why( $other, 'credentials', 'zdz_example_row' ), 'its key id edited: no key by that id, it does not open' );
		$s2 = ZDZ_Keys::seal( 'credentials', 'provider-key-EXAMPLE-0001', 'zdz_example_row' );
		zdzk_ok( $s !== $s2 && substr( $s, 15, 32 ) !== substr( $s2, 15, 32 ), 'sealing the same value twice never gives the same bytes (a new nonce each time)' );
		zdzk_ok( null === ZDZ_Keys::seal( 'no-such-purpose', 'x', 'row' ) && null === ZDZ_Keys::mac( 'no-such-purpose', 'x' ) && null === ZDZ_Keys::seal( 7, 'x', 'row' ), 'an unknown purpose is refused, never given a default' );
		zdzk_ok( null === ZDZ_Keys::open( 'no-such-purpose', $s, 'zdz_example_row' ) && 'unknown_purpose' === ZDZ_Keys::why( $s, 'no-such-purpose', 'zdz_example_row' ), 'and opens nothing' );
		zdzk_ok( '' === ZDZ_Keys::seal( 'credentials', '' ) && '' === ZDZ_Keys::open( 'credentials', '' ) && '' === ZDZ_Keys::seal( 'credentials', null ) && '' === ZDZ_Keys::open( 'credentials', false, 'zdz_example_row' ), 'empty in, empty out (an option that is not there reads as nothing stored)' );
		zdzk_ok( null === ZDZ_Keys::seal( 'credentials', array( 'provider-key-EXAMPLE-0001' ), 'zdz_example_row' ) && null === ZDZ_Keys::seal( 'credentials', 'provider-key-EXAMPLE-0001', array( 'zdz_example_row' ) ) && null === ZDZ_Keys::mac( 'sign', array( 'm' ) ), 'only text is sealed, bound or signed: an array is refused, never taken as a word' );
		zdzk_ok( null === ZDZ_Keys::open( 'credentials', array( $s ), 'zdz_example_row' ) && 'not_zdzk1' === ZDZ_Keys::why( array( $s ) ) && null === ZDZ_Keys::open( 'credentials', $s, array( 'zdz_example_row' ) ) && false === ZDZ_Keys::verify_mac( 'sign', array( 'm' ), str_repeat( 'a', 64 ) ), 'and nothing opens or verifies for one' );
		$word = zdzk_seal_under( $K, $CRE, 'credentials', 'value-EXAMPLE-0009', 'Array' );   // sealed to the very word PHP makes of an array
		zdzk_ok( 'value-EXAMPLE-0009' === ZDZ_Keys::open( 'credentials', $word, 'Array' ) && null === ZDZ_Keys::open( 'credentials', $word, array( 'zdz_example_row' ) ), 'not even a value bound to that very word opens for an array' );
		$num = ZDZ_Keys::seal( 'credentials', 4821, 7 );
		zdzk_ok( '4821' === ZDZ_Keys::open( 'credentials', $num, '7' ), 'a number is sealed as it is written, and bound as it is written' );
		// a damaged value: every way of cutting or changing it reads as null, never as text
		$bad = array(
			'cut short in the payload'    => substr( $s, 0, strlen( $s ) - 5 ),
			'cut to less than a nonce'    => substr( $s, 0, 30 ),
			'cut inside the header'       => substr( $s, 0, 10 ),
			'one payload character off'   => substr( $s, 0, 40 ) . ( 'A' === $s[40] ? 'B' : 'A' ) . substr( $s, 41 ),
			'a character outside base64'  => substr( $s, 0, 40 ) . '!' . substr( $s, 41 ),
			'a line break after it'       => $s . "\n",
			'the header in capitals'      => 'ZDZK1' . substr( $s, 5 ),
			'an older-looking header'     => 'zdzk0' . substr( $s, 5 ),
		);
		$all_null = true;
		$whys     = array();
		foreach ( $bad as $label => $v ) {
			$all_null       = $all_null && null === ZDZ_Keys::open( 'credentials', $v, 'zdz_example_row' );
			$whys[ $label ] = ZDZ_Keys::why( $v, 'credentials', 'zdz_example_row' );
		}
		zdzk_ok( $all_null, 'a cut, changed or padded value opens as null, never as text' );
		zdzk_ok(
			array(
				'cut short in the payload'   => 'tampered',
				'cut to less than a nonce'   => 'tampered',
				'cut inside the header'      => 'not_zdzk1',
				'one payload character off'  => 'tampered',
				'a character outside base64' => 'not_zdzk1',
				'a line break after it'      => 'not_zdzk1',
				'the header in capitals'     => 'not_zdzk1',
				'an older-looking header'    => 'not_zdzk1',
			) === $whys,
			'and each says why: tampered, or not this format'
		);
		zdzk_ok( ! ZDZ_Keys::is_zdzk1( 'plain text' ) && ! ZDZ_Keys::is_zdzk1( null ) && ! ZDZ_Keys::is_zdzk1( array( $s ) ) && '' === ZDZ_Keys::header_key_id( 'plain text' ), 'anything else is not a sealed value' );
		// signatures
		$mac = ZDZ_Keys::mac( 'sign', 'photo-grant|42|2026-01-31' );
		zdzk_ok( is_string( $mac ) && 1 === preg_match( '/^[0-9a-f]{64}$/D', $mac ) && ZDZ_Keys::verify_mac( 'sign', 'photo-grant|42|2026-01-31', $mac ), 'a signature is 64 hex and verifies' );
		zdzk_ok( ! ZDZ_Keys::verify_mac( 'sign', 'photo-grant|43|2026-01-31', $mac ) && ! ZDZ_Keys::verify_mac( 'links', 'photo-grant|42|2026-01-31', $mac ), 'not for another message, not for another purpose' );
		zdzk_ok( ! ZDZ_Keys::verify_mac( 'sign', 'photo-grant|42|2026-01-31', strtoupper( $mac ) ) && ! ZDZ_Keys::verify_mac( 'sign', 'photo-grant|42|2026-01-31', substr( $mac, 0, 63 ) ) && ! ZDZ_Keys::verify_mac( 'sign', 'photo-grant|42|2026-01-31', null ), 'and only in its exact form' );
		zdzk_ok( zdzk_mac_under( $K, ZDZ_Keys::PURPOSES['sign'], 'sign', 'photo-grant|42|2026-01-31' ) === $mac, 'it is HMAC-SHA256 under the purpose\'s subkey, as documented' );
		zdzk_ok( $s === ZDZ_Keys::reseal( 'credentials', $s, 'zdz_example_row' ), 'a value already under the current key is given back as it is' );
		zdzk_ok( null === ZDZ_Keys::reseal( 'credentials', 'fixed-iv-bytes', 'zdz_example_row' ) && 'not_zdzk1' === ZDZ_Keys::why( 'fixed-iv-bytes' ), 'a value in another format is not this service\'s to read' );
		$GLOBALS['zdzk_data']['sealed'] = $s;
		break;

	/* ============================== the key is gone, or is not a key */

	case 'missing_after_set_up':
		// the require line or the file went: the record and the sealed values are still in the database
		$i = ZDZ_Keys::info();
		zdzk_ok( 'missing' === ZDZ_Keys::state() && ZDZ_Keys::set_up() && '' === $i['kid'], 'set up once, no key line now: missing' );
		$b = ZDZ_Keys_Admin::banner();
		zdzk_ok( is_array( $b ) && 0 === strpos( $b[0], 'The app\'s key is missing' ) && zdzk_has( $b[1], 'Do not make a new key' ) && zdzk_has( $b[1], 'require line' ), 'the notice says what to put back, and not to make a new key' );
		zdzk_ok( zdzk_has( zdzk_notice(), 'The app\'s key is missing' ) && zdzk_has( zdzk_notice(), 'page=zdz-app-key' ), 'on every admin page, with the way to the page' );
		$GLOBALS['zdz_test_current_user'] = ZDZK_SALES;
		zdzk_ok( '' === zdzk_notice() && '' === zdzk_page(), 'but only for administrators: anyone else sees no notice and no page' );
		$GLOBALS['zdz_test_current_user'] = ZDZK_ADMIN;
		$h = zdzk_health();
		zdzk_ok( 'critical' === $h['status'] && zdzk_has( $h['label'], 'missing' ), 'Site Health: critical' );
		$v = (string) zdzk_opt( 'zdz_example_row' );
		zdzk_ok( ZDZ_Keys::is_zdzk1( $v ) && null === ZDZ_Keys::open( 'credentials', $v, 'zdz_example_row' ) && 'missing' === ZDZ_Keys::why( $v, 'credentials', 'zdz_example_row' ), 'a stored value opens as null, never as an empty string' );
		zdzk_ok( null === ZDZ_Keys::seal( 'credentials', 'value-EXAMPLE-0003', 'zdz_example_row' ) && null === ZDZ_Keys::mac( 'sign', 'm' ), 'nothing is sealed or signed' );
		zdzk_ok( null === ZDZ_Keys::recordable() && false === ZDZ_Keys::record_key( ZDZK_ADMIN ), 'nothing can be recorded' );
		zdzk_page();
		zdzk_page( array( 'zdz_keys_do' => 'run_all' ) );
		zdzk_page( array( 'zdz_keys_do' => 'record' ) );
		zdzk_ok( 0 === $GLOBALS['zdzk_writes'], 'nothing is written, whatever is pressed' );
		break;

	case 'not_a_printed_key':
		$b = ZDZ_Keys_Admin::banner();
		zdzk_ok( 'bad_constant' === ZDZ_Keys::state() && ZDZ_Keys::set_up() && is_array( $b ) && zdzk_has( $b[0], 'not a printed key' ), 'a key line that is not a printed key: its own notice' );
		zdzk_ok( null === ZDZ_Keys::seal( 'credentials', 'x', 'row' ) && null === ZDZ_Keys::recordable(), 'nothing sealed, nothing to record' );
		$out = zdzk_notice() . zdzk_page() . json_encode( ZDZ_Keys::info() );
		zdzk_ok( ! zdzk_has( $out, $K ) && zdzk_has( $out, '(not a printed key)' ), 'what the line holds is never shown' );
		zdzk_ok( 0 === $GLOBALS['zdzk_writes'], 'nothing written' );
		break;

	/* ============================== the wrong key: locked, and never healing itself */

	case 'changed_key':
		// the record and the stored value are another key's (K1); the file now holds this one
		$i = ZDZ_Keys::info();
		$b = ZDZ_Keys_Admin::banner();
		zdzk_ok( 'mismatch' === ZDZ_Keys::state() && ! $i['unrecorded'], 'a key that is not the recorded one: mismatch' );
		zdzk_ok( is_array( $b ) && zdzk_has( $b[0], zdzk_kid( $K ) ) && zdzk_has( $b[0], zdzk_kid( $K1 ) ) && zdzk_has( $b[1], 'Put back the right ZDZ_APP_KEY line' ) && zdzk_has( $b[1], 'Do not make a new key' ), 'the notice names both key ids and says to put the right line back' );
		$v = (string) zdzk_opt( 'zdz_example_row' );
		zdzk_ok( null === ZDZ_Keys::open( 'credentials', $v, 'zdz_example_row' ) && 'mismatch' === ZDZ_Keys::why( $v, 'credentials', 'zdz_example_row' ), 'the stored value is not read' );
		zdzk_ok( null === ZDZ_Keys::seal( 'credentials', 'value-EXAMPLE-0004', 'zdz_example_row' ) && null === ZDZ_Keys::mac( 'sign', 'm' ) && null === ZDZ_Keys::reseal( 'credentials', $v, 'zdz_example_row' ), 'nothing is sealed, signed or re-sealed' );
		zdzk_job( 'example' );
		zdzk_ok( array( 'error' => 'mismatch' ) === ZDZ_Keys_Migrate::run( 'example', 200, ZDZK_ADMIN ), 'no job runs' );
		zdzk_ok( null === ZDZ_Keys::recordable() && false === ZDZ_Keys::record_key( ZDZK_ADMIN ) && false === ZDZ_Keys::record_key( ZDZK_ADMIN, $K ) && false === ZDZ_Keys::record_key( ZDZK_ADMIN, $K1 ), 'an administrator cannot record it, whatever they type' );
		$p = zdzk_page( array( 'zdz_keys_do' => 'record', 'zdz_keys_code' => $K ) );
		zdzk_ok( zdzk_has( $p, 'Nothing was recorded.' ) && ! zdzk_has( $p, 'Record this key' ), 'the page offers no Record and a press records nothing' );
		$p = zdzk_page( array( 'zdz_keys_do' => 'run_all' ) );
		zdzk_ok( zdzk_has( $p, 'Nothing was moved: the app\'s key is not the recorded one.' ), 'a press to re-encrypt is refused' );
		zdzk_ok( zdzk_kid( $K1 ) === zdzk_opt( 'zdz_keys_state' )['kid'] && 0 === $GLOBALS['zdzk_writes'], 'the record still names the key the data was sealed with; nothing was written' );
		zdzk_next_request();
		zdzk_ok( 'mismatch' === ZDZ_Keys::state(), 'and it is still locked on the next look: a changed key never heals itself' );
		break;

	case 'right_key_back':
		$v = (string) zdzk_opt( 'zdz_example_row' );
		zdzk_ok( 'ok' === ZDZ_Keys::state() && 'value-EXAMPLE-0001' === ZDZ_Keys::open( 'credentials', $v, 'zdz_example_row' ), 'with the right line back the value opens: nothing was damaged while it was locked' );
		zdzk_ok( 0 === $GLOBALS['zdzk_writes'], 'and nothing needed writing' );
		break;

	case 'check_recorded_key_when_locked':
		// the file holds another key, but the data was sealed with K1: the paper K1 is "the recorded key"
		zdzk_ok( 'mismatch' === ZDZ_Keys::state() && 'recorded' === ZDZ_Keys::code_reads_as( $K1 ) && ! ZDZ_Keys::code_matches( $K1 ), 'locked: the paper code is recognised as the recorded key' );
		$p = zdzk_page( array( 'zdz_keys_do' => 'check', 'zdz_keys_code' => $K1 ) );
		zdzk_ok( zdzk_has( $p, 'should hold this code' ) && ! zdzk_has( $p, $K1 ), 'the page says the file should hold that code, without showing it' );
		break;

	/* ============================== the record was lost (a restore), or values are already there */

	case 'lost_record_same_key':
		// values under this very key are stored, and nothing says which key they are under
		$i = ZDZ_Keys::info();
		zdzk_ok( 'mismatch' === ZDZ_Keys::state() && $i['unrecorded'] && 0 === $GLOBALS['zdzk_writes'], 'no record, values under this key: locked until an administrator says so; nothing written' );
		$b = ZDZ_Keys_Admin::banner();
		zdzk_ok( is_array( $b ) && zdzk_has( $b[0], 'no record of which key' ) && zdzk_has( $b[0], zdzk_kid( $K ) ) && zdzk_has( $b[1], 'press Record' ), 'the notice asks for Record, not for a new key' );
		zdzk_ok( array( zdzk_kid( $K ), 'recorded' ) === ZDZ_Keys::recordable(), 'this key id may be recorded' );
		$p = zdzk_page();
		zdzk_ok( zdzk_has( $p, 'to record key id ' . zdzk_kid( $K ) ) && zdzk_has( $p, 'Key ids on stored values' ) && zdzk_has( $p, 'name="zdz_keys_code"' ) && 2 === substr_count( $p, 'aria-label="The printed key"' ), 'the page offers Record, asks for the printed key in a named box, and shows the key ids it found' );
		$p = zdzk_page( array( 'zdz_keys_do' => 'record' ) );
		zdzk_ok( null === zdzk_opt( 'zdz_keys_state' ) && zdzk_has( $p, 'type the printed key' ), 'Record without the printed key: refused' );
		$p = zdzk_page( array( 'zdz_keys_do' => 'record', 'zdz_keys_code' => $K2 ) );
		zdzk_ok( null === zdzk_opt( 'zdz_keys_state' ) && ! zdzk_has( $p, $K2 ) && ! zdzk_has( $p, str_replace( ' ', '', $K2 ) ), 'Record with another printed key: refused, and what was typed is never shown' );
		$p = zdzk_page( array( 'zdz_keys_do' => 'record', 'zdz_keys_code' => $K ), false );
		zdzk_ok( null === zdzk_opt( 'zdz_keys_state' ) && true === $GLOBALS['zdzk_nonce_bad'], 'Record with the right key but not from this page (no nonce): refused' );
		$p  = zdzk_page( array( 'zdz_keys_do' => 'record', 'zdz_keys_code' => strtolower( $K ) ) );
		$st = (array) zdzk_opt( 'zdz_keys_state' );
		zdzk_ok( zdzk_kid( $K ) === ( $st['kid'] ?? '' ) && 'recorded' === ( $st['how'] ?? '' ) && ZDZK_ADMIN === ( $st['by'] ?? 0 ), 'Record with the printed key typed: this key id, by that administrator' );
		zdzk_ok( zdzk_has( $p, 'Recorded: key id ' . zdzk_kid( $K ) ) && ! zdzk_has( $p, strtolower( $K ) ), 'the page says so, without the key' );
		zdzk_ok( array( array( ZDZK_ADMIN, 'keys_key_recorded', 'App key: key id ' . zdzk_kid( $K ) . ' recorded', 'zdz-theme' ) ) === $GLOBALS['zdzk_logged'], 'one audit row: who, what, the key id' );
		zdzk_ok( 'ok' === ZDZ_Keys::state() && 'value-EXAMPLE-0001' === ZDZ_Keys::open( 'credentials', (string) zdzk_opt( 'zdz_example_row' ), 'zdz_example_row' ), 'then it is ready, and the value opens' );
		break;

	case 'lost_record_other_key':
		// the stored value is under ANOTHER key (K2): this key must never be recorded over it
		$before = $GLOBALS['zdz_test_options'];
		zdzk_ok( 'mismatch' === ZDZ_Keys::state() && ZDZ_Keys::info()['unrecorded'], 'no record, values under another key: locked' );
		zdzk_ok( null === ZDZ_Keys::recordable() && false === ZDZ_Keys::record_key( ZDZK_ADMIN ) && false === ZDZ_Keys::record_key( ZDZK_ADMIN, $K ) && false === ZDZ_Keys::record_key( ZDZK_ADMIN, $K2 ), 'an administrator cannot record this key either, whatever they type' );
		$p = zdzk_page( array( 'zdz_keys_do' => 'record', 'zdz_keys_code' => $K ) );
		zdzk_ok( ! zdzk_has( $p, 'Record this key' ) && zdzk_has( $p, zdzk_kid( $K2 ) ), 'the page offers no Record, and shows the key id it found on the stored value' );
		zdzk_ok( $before === $GLOBALS['zdz_test_options'] && 0 === $GLOBALS['zdzk_writes'], 'nothing recorded, nothing written' );
		break;

	case 'foreign_id_inside_serialized_option':
		// the same, with the foreign value inside an array option (as a settings array would hold it)
		zdzk_ok( 'mismatch' === ZDZ_Keys::state() && array( zdzk_kid( $K2 ) ) === ZDZ_Keys::sealed_kids() && 0 === $GLOBALS['zdzk_writes'], 'a foreign key id inside a serialized option is seen: locked, nothing recorded' );
		zdzk_ok( null === ZDZ_Keys::recordable() && false === ZDZ_Keys::record_key( ZDZK_ADMIN, $K ), 'and an administrator cannot record over it' );
		break;

	case 'lost_record_nothing_found':
		// a key was recorded here before; the record is gone and the scan finds nothing: never recorded by itself
		$i = ZDZ_Keys::info();
		zdzk_ok( 'mismatch' === ZDZ_Keys::state() && $i['unrecorded'] && null === zdzk_opt( 'zdz_keys_state' ) && 0 === $GLOBALS['zdzk_writes'], 'recorded once before, nothing found now: it waits for an administrator' );
		zdzk_ok( ZDZ_Keys::set_up() && null !== ZDZ_Keys_Admin::banner() && zdzk_has( zdzk_notice(), 'no record of which key' ), 'it counts as set up, so the notice shows' );
		zdzk_ok( false === ZDZ_Keys::record_key( ZDZK_ADMIN ) && true === ZDZ_Keys::record_key( ZDZK_ADMIN, $K ) && 'ok' === ZDZ_Keys::state(), 'an administrator records it by typing the printed key' );
		break;

	case 'lost_record_mid_change':
		// the record was lost in the middle of a key change: the stored value is under the previous key
		zdzk_ok( 'mismatch' === ZDZ_Keys::state() && array( zdzk_kid( $P ), 'found' ) === ZDZ_Keys::recordable(), 'values under the previous key: the previous key id is the one to record' );
		zdzk_ok( false === ZDZ_Keys::record_key( ZDZK_ADMIN ) && false === ZDZ_Keys::record_key( ZDZK_ADMIN, $K ), 'not without typing, and not by typing the new key' );
		zdzk_ok( true === ZDZ_Keys::record_key( ZDZK_ADMIN, $P ), 'typing the key the data is under records it' );
		zdzk_ok( 'ok' === ZDZ_Keys::state() && ZDZ_Keys::rotating() && zdzk_kid( $P ) === zdzk_opt( 'zdz_keys_state' )['kid'], 'ready, a key change under way, the previous key id recorded (not the new one)' );
		zdzk_ok( 'value-EXAMPLE-0001' === ZDZ_Keys::open( 'credentials', (string) zdzk_opt( 'zdz_example_row' ), 'zdz_example_row' ), 'and the value opens with the previous key' );
		break;

	case 'job_too_large_to_scan':
		zdzk_job(
			'big',
			array(
				'purpose' => 'inbox-content',
				'items'   => function () {
					return range( 1, ZDZ_Keys::SCAN_LIMIT + 1 );
				},
				'stored'  => function ( $item ) {
					return null;
				},
			)
		);
		zdzk_ok( 'mismatch' === ZDZ_Keys::state() && ZDZ_Keys::info()['unrecorded'] && 0 === $GLOBALS['zdzk_writes'], 'a job too large to look through, with no key-id reader, and nothing recorded: locked, nothing written' );
		zdzk_ok( null === ZDZ_Keys::sealed_kids() && null === ZDZ_Keys::recordable() && false === ZDZ_Keys::record_key( ZDZK_ADMIN, $K ), 'the scan cannot tell, so nothing may be recorded' );
		zdzk_ok( zdzk_has( zdzk_page(), 'can\'t tell: a registered job is too large to scan' ), 'the page says why' );
		break;

	case 'large_job_with_a_key_id_reader':
		zdzk_job(
			'big',
			array(
				'purpose' => 'inbox-content',
				'items'   => function () {
					return range( 1, ZDZ_Keys::SCAN_LIMIT + 1 );
				},
				'stored'  => function ( $item ) {
					return null;
				},
				'kids'    => function () {
					return array();
				},
			)
		);
		zdzk_ok( 'ok' === ZDZ_Keys::state() && 'first' === zdzk_opt( 'zdz_keys_state' )['how'], 'the same job with a key-id reader that finds none: first use, recorded' );
		break;

	case 'many_option_rows':
		// more sealed option rows than one read of the scan, the last of them under another key
		zdzk_ok( count( $GLOBALS['zdz_test_options'] ) > 2 * ZDZ_Keys::SCAN_BATCH, 'the options table holds more sealed rows than two reads' );
		$kids = ZDZ_Keys::sealed_kids();
		$want = array( zdzk_kid( $K ), zdzk_kid( $K2 ) );
		sort( $want );
		zdzk_ok( $want === $kids && $GLOBALS['wpdb']->queries >= 3, 'the scan reads every row, a batch at a time: the key id on the last row is found' );
		zdzk_ok( 'mismatch' === ZDZ_Keys::state() && null === ZDZ_Keys::recordable() && false === ZDZ_Keys::record_key( ZDZK_ADMIN, $K ), 'so this key is not recorded over a value under another key' );
		break;

	/* ============================== another site's key, a copied database, a move */

	case 'site_line_other_host':
		$i = ZDZ_Keys::info();
		zdzk_ok( 'other_site' === ZDZ_Keys::state() && 'file' === $i['site_why'] && 0 === $GLOBALS['zdzk_writes'], 'a key file made for another host: other_site, nothing recorded' );
		$b = ZDZ_Keys_Admin::banner();
		zdzk_ok( is_array( $b ) && zdzk_has( $b[0], 'staging.example.com' ) && zdzk_has( $b[1], $ME ) && zdzk_has( $b[1], 'own zdz-secrets.php' ), 'the notice names both hosts and asks for this site\'s own file' );
		zdzk_ok( zdzk_has( zdzk_notice(), 'App key' ) && 'critical' === zdzk_health()['status'], 'on every admin page, and critical in Site Health' );
		zdzk_ok( null === ZDZ_Keys::seal( 'credentials', 'x', 'row' ) && null === ZDZ_Keys::mac( 'sign', 'm' ) && null === ZDZ_Keys::recordable() && false === ZDZ_Keys::record_key( ZDZK_ADMIN, $K ), 'app secrets are locked, and nothing can be recorded' );
		break;

	case 'copied_database':
		// the record names another host: this database was copied here (a staging copy made from the live site)
		$i = ZDZ_Keys::info();
		zdzk_ok( 'other_site' === ZDZ_Keys::state() && 'record' === $i['site_why'] && 0 === $GLOBALS['zdzk_writes'], 'a database recorded on another host: other_site, nothing written' );
		$b = ZDZ_Keys_Admin::banner();
		zdzk_ok( is_array( $b ) && zdzk_has( $b[0], 'copied from production.example.com' ) && zdzk_has( $b[1], 'Nothing is damaged' ) && zdzk_has( $b[1], 'Do not make a new key' ) && zdzk_has( $b[1], $ME ), 'the notice says it was copied, from where, and that nothing is damaged' );
		$v = (string) zdzk_opt( 'zdz_example_row' );
		zdzk_ok( null === ZDZ_Keys::open( 'credentials', $v, 'zdz_example_row' ) && 'other_site' === ZDZ_Keys::why( $v, 'credentials', 'zdz_example_row' ) && null === ZDZ_Keys::seal( 'credentials', 'x', 'row' ), 'the copied values are not read here, and nothing is sealed' );
		zdzk_ok( null === ZDZ_Keys::recordable() && false === ZDZ_Keys::record_key( ZDZK_ADMIN ) && false === ZDZ_Keys::record_key( ZDZK_ADMIN, $K ), 'nothing can be recorded: the copy stays locked for app secrets' );
		zdzk_ok( ! zdzk_has( zdzk_page(), 'Record this key' ), 'and the page offers no Record' );
		break;

	case 'copied_database_with_the_other_sites_file':
		// the copy brought the other site's key file along with its database
		$i = ZDZ_Keys::info();
		$b = ZDZ_Keys_Admin::banner();
		zdzk_ok( 'other_site' === ZDZ_Keys::state() && 'file' === $i['site_why'] && 0 === $GLOBALS['zdzk_writes'], 'the other site\'s file on a copy of its database: other_site, nothing written' );
		zdzk_ok( is_array( $b ) && zdzk_has( $b[0], 'the key for production.example.com' ) && zdzk_has( $b[1], 'own zdz-secrets.php' ), 'the notice asks for this site\'s own file' );
		zdzk_ok( null === ZDZ_Keys::open( 'credentials', (string) zdzk_opt( 'zdz_example_row' ), 'zdz_example_row' ) && null === ZDZ_Keys::recordable() && false === ZDZ_Keys::record_key( ZDZK_ADMIN, $K ), 'the copied values are not read with the other site\'s key, and nothing can be recorded' );
		break;

	case 'moved_site':
		// the record names the old address; the file names this one and holds the recorded key
		zdzk_ok( 'other_site' === ZDZ_Keys::state() && array( zdzk_kid( $K ), 'moved' ) === ZDZ_Keys::recordable(), 'the site moved to a new address: that may be recorded' );
		$p = zdzk_page();
		zdzk_ok( zdzk_has( $p, 'this looks like a move to a new address' ) && zdzk_has( $p, 'old.example.com' ) && zdzk_has( $p, 'as the app\'s key on ' . $ME ), 'the page says what it looks like, and asks' );
		$p  = zdzk_page( array( 'zdz_keys_do' => 'record' ) );
		$st = (array) zdzk_opt( 'zdz_keys_state' );
		zdzk_ok( $ME === ( $st['site'] ?? '' ) && 'moved' === ( $st['how'] ?? '' ) && zdzk_kid( $K ) === ( $st['kid'] ?? '' ) && 'ok' === ZDZ_Keys::state(), 'Record: the new address is recorded with the same key id, and it is ready' );
		zdzk_ok( 'value-EXAMPLE-0001' === ZDZ_Keys::open( 'credentials', (string) zdzk_opt( 'zdz_example_row' ), 'zdz_example_row' ), 'the values open again' );
		break;

	case 'moved_site_with_a_foreign_value':
		zdzk_ok( 'other_site' === ZDZ_Keys::state() && null === ZDZ_Keys::recordable() && false === ZDZ_Keys::record_key( ZDZK_ADMIN, $K ), 'a move is not recorded while a stored value is under another key' );
		break;

	/* ============================== who may manage the key */

	case 'who_may_manage':
		zdzk_ok( true === ZDZ_Keys::may_manage( ZDZK_ADMIN ), 'an administrator, signed in as themselves: yes' );
		zdzk_ok( false === ZDZ_Keys::may_manage( ZDZK_ADMIN2 ), 'another administrator\'s id, not the one signed in: no' );
		zdzk_ok( false === ZDZ_Keys::may_manage( 0 ) && false === ZDZ_Keys::may_manage( 99 ), 'nobody, or an id that is no user: no' );
		$GLOBALS['zdz_test_current_user'] = ZDZK_SALES;
		zdzk_ok( false === ZDZ_Keys::may_manage( ZDZK_SALES ) && false === ZDZ_Keys::record_key( ZDZK_SALES, $K ), 'someone who is not an administrator: no, and cannot record' );
		$GLOBALS['zdz_test_current_user'] = ZDZK_SHARED;
		zdzk_ok( false === ZDZ_Keys::may_manage( ZDZK_SHARED ), 'the shared device: no' );
		$GLOBALS['zdz_test_current_user'] = ZDZK_TRAIT;
		zdzk_ok( false === ZDZ_Keys::may_manage( ZDZK_TRAIT ), 'a field role that carries the shared-device trait: no' );
		$GLOBALS['zdz_test_current_user'] = ZDZK_OWNER_TRAIT;
		zdzk_ok( current_user_can( 'manage_options' ) && false === ZDZ_Keys::may_manage( ZDZK_OWNER_TRAIT ), 'an owner-level role that carries the shared-device trait: no, whatever else it may do' );
		zdzk_ok( zdzk_has( zdzk_page(), 'Only an administrator' ), 'and it sees only the sentence' );
		$GLOBALS['zdz_test_current_user'] = ZDZK_PRIVACY;
		zdzk_ok( false === ZDZ_Keys::may_manage( ZDZK_PRIVACY ) && false === ZDZ_Keys::record_key( ZDZK_PRIVACY, $K ), 'an account in Privacy View, though it holds an administrator\'s role: no' );
		$p = zdzk_page( array( 'zdz_keys_do' => 'record', 'zdz_keys_code' => $K ) );
		zdzk_ok( zdzk_has( $p, 'Only an administrator' ) && ! zdzk_has( $p, zdzk_kid( $K ) ) && ! zdzk_has( $p, 'name="zdz_keys_do"' ), 'it sees only the sentence: no key id, no form, and its press does nothing' );
		$GLOBALS['zdz_test_current_user'] = ZDZK_ADMIN;
		zdzk_static( 'ZDZ_Kiosk_Demo', 'switched', true );
		zdzk_ok( false === ZDZ_Keys::may_manage( ZDZK_ADMIN ), 'Demo Mode on this device: no, whoever started it' );
		zdzk_static( 'ZDZ_Kiosk_Demo', 'switched', false );
		zdzk_static( 'ZDZ_View_As', 'emulated_role', 'zdz_sales' );
		zdzk_ok( ZDZ_View_As::is_emulating() && false === ZDZ_Keys::may_manage( ZDZK_ADMIN ), 'View As another role: no' );
		zdzk_static( 'ZDZ_View_As', 'emulated_role', null );
		zdzk_static( 'ZDZ_View_As', 'emulated_user_id', ZDZK_SALES );
		zdzk_ok( false === ZDZ_Keys::may_manage( ZDZK_ADMIN ) && zdzk_has( zdzk_page(), 'Only an administrator' ), 'View As another person: no, and the page shows only the sentence' );
		zdzk_static( 'ZDZ_View_As', 'emulated_user_id', null );
		zdzk_ok( true === ZDZ_Keys::may_manage( ZDZK_ADMIN ) && null === zdzk_opt( 'zdz_keys_state' ), 'back as themselves: yes; and nothing was recorded by anyone refused' );
		zdzk_ok( true === ZDZ_Keys::record_key( ZDZK_ADMIN, $K ) && ZDZK_ADMIN === zdzk_opt( 'zdz_keys_state' )['by'], 'the administrator records it, typing the key' );
		zdzk_ok( false === ZDZ_Keys::record_key( ZDZK_ADMIN, $K ), 'and not again while it is recorded and no key change is under way' );
		break;

	/* ============================== Check a printed key */

	case 'check_a_printed_key':
		// the key in the file is this one, the previous one is K1 and is the recorded one (a key change); K3 is nobody's
		zdzk_ok( 'this' === ZDZ_Keys::code_reads_as( strtolower( $K ) ) && ZDZ_Keys::code_matches( str_replace( ' ', '-', $K ) ), 'the file\'s key, in lower case or with dashes: this key' );
		zdzk_ok( 'this' === ZDZ_Keys::code_reads_as( strtr( $K, array( 'O' => '0', 'I' => '1', 'B' => '8' ) ) ), 'read with the look-alikes of a hand copy (0 for O, 1 for I, 8 for B)' );
		zdzk_ok( 'recorded' === ZDZ_Keys::code_reads_as( $K1 ) && ! ZDZ_Keys::code_matches( $K1 ), 'the recorded key (also the previous one): recorded' );
		zdzk_ok( 'none' === ZDZ_Keys::code_reads_as( $K3 ) && 'not_a_code' === ZDZ_Keys::code_reads_as( 'ABCD' ) && 'not_a_code' === ZDZ_Keys::code_reads_as( substr( $K, 0, -1 ) ) && 'not_a_code' === ZDZ_Keys::code_reads_as( array() ), 'another key: none; too short, or not text: not a code' );
		$p = zdzk_page( array( 'zdz_keys_do' => 'check', 'zdz_keys_code' => $K ) );
		zdzk_ok( zdzk_has( $p, 'That code is the key in zdz-secrets.php.' ) && zdzk_has( $p, 'It read 52 characters.' ), 'the page says which key it is and how many characters it read' );
		$p = zdzk_page( array( 'zdz_keys_do' => 'check', 'zdz_keys_code' => $K1 ) );
		zdzk_ok( zdzk_has( $p, 'the key the app\'s data was sealed with' ), 'the recorded key is named as such' );
		$p = zdzk_page( array( 'zdz_keys_do' => 'check', 'zdz_keys_code' => $K3 ) );
		zdzk_ok( zdzk_has( $p, 'not this site\'s key, the recorded key, or the previous key' ), 'nobody\'s key is said to be nobody\'s' );
		$short = substr( $K3, 0, 24 ) . " \u{00A0}!?";
		$p     = zdzk_page( array( 'zdz_keys_do' => 'check', 'zdz_keys_code' => $short ) );
		zdzk_ok( zdzk_has( $p, 'That is not a printed key.' ) && zdzk_has( $p, 'It read 20 characters, and left out 2 other marks' ) && zdzk_has( $p, 'a printed key has 52' ), 'a short code with stray marks: said so, with what was read and what was left out' );
		zdzk_ok( ! zdzk_has( $p, substr( $K3, 0, 14 ) ), 'and never echoed' );
		zdzk_ok( array() === $GLOBALS['zdzk_logged'] && 0 === $GLOBALS['zdzk_writes'], 'checking writes nothing: no option, no audit row' );
		zdzk_ok( array( 52, 0 ) === ZDZ_Keys_Admin::code_reading( $K ) && array( 8, 1 ) === ZDZ_Keys_Admin::code_reading( "AAAA-BBBB\u{00A0}é" ), 'counts are of characters read and of other marks, never the code' );
		break;

	/* ============================== the page */

	case 'page_with_and_without_jobs':
		$p   = zdzk_page();
		$kid = ZDZ_Keys::key_id();
		zdzk_ok( 8 === strlen( $kid ) && zdzk_has( $p, $kid ) && zdzk_has( $p, 'is in place' ), 'the page shows the key id and that the key is in place' );
		zdzk_ok( ! zdzk_has( $p, 'To set it up' ) && zdzk_has( $p, 'Nothing is registered yet' ) && zdzk_has( $p, 'still depend on the WordPress salts' ) && ! zdzk_has( $p, 'Re-encrypt everything now' ), 'no job: it says so, says what still depends on the salts, and shows no button' );
		zdzk_ok( zdzk_has( $p, '(the file names no site: add ZDZ_APP_KEY_SITE)' ) && zdzk_has( $p, 'WP_SECRETS_KEY is not set' ) && zdzk_has( $p, 'by the app, on first use' ), 'a file that names no site is said; so are WordPress\'s own secrets key and who recorded the key' );
		zdzk_ok( ! zdzk_has( $p, 'confirm(' ) && ! zdzk_has( $p, 'type="password"' ), 'no browser confirm() and no password box (a browser would offer to save the key)' );
		zdzk_ok( zdzk_has( $p, 'If the host changes wp-config.php' ) && zdzk_has( $p, 'Never make a new key' ), 'the steps for a host-side change are there' );
		// with a job: the counts, Run, and Re-encrypt everything now (which runs it, and is logged)
		zdzk_rows( 'example', array( 'r1' => 'legacy:provider-key-EXAMPLE-0001', 'r2' => 'legacy:provider-key-EXAMPLE-0002', 'r3' => 'unreadable-bytes' ) );
		zdzk_job( 'example' );
		$p = zdzk_page();
		zdzk_ok( zdzk_has( $p, 'Example rows (example)' ) && zdzk_has( $p, 'not run yet' ) && zdzk_has( $p, 'Re-encrypt everything now</button>' ) && zdzk_has( $p, '>Run</button>' ), 'a job: listed, not run yet, both buttons shown' );
		zdzk_ok( ! zdzk_has( $p, 'confirm(' ) && ! zdzk_has( $p, 'onclick' ), 'and neither button stops to ask the browser' );
		$p = zdzk_page( array( 'zdz_keys_do' => 'run_all' ) );
		zdzk_ok( zdzk_has( $p, 'Ran every job once.' ) && zdzk_has( $p, '2 done · 1 couldn\'t read · 0 skipped' ), 'Re-encrypt everything now: the counts' );
		zdzk_ok( ZDZ_Keys::is_zdzk1( zdzk_row( 'example', 'r1' ) ) && 'provider-key-EXAMPLE-0001' === ZDZ_Keys::open( 'credentials', zdzk_row( 'example', 'r1' ), 'r1' ) && 'unreadable-bytes' === zdzk_row( 'example', 'r3' ), 'moved and readable; the unreadable one left exactly as it was' );
		zdzk_ok( zdzk_has( $p, '1 couldn\'t be read (by row; left as it was)' ) && zdzk_has( $p, '<p>r3</p>' ) && ! zdzk_has( $p, 'unreadable-bytes' ) && ! zdzk_has( $p, 'provider-key-EXAMPLE' ), 'the unreadable row is named by row, never by value' );
		zdzk_ok( 1 === count( $GLOBALS['zdzk_logged'] ) && 'keys_migration_run_all' === $GLOBALS['zdzk_logged'][0][1] && ZDZK_ADMIN === $GLOBALS['zdzk_logged'][0][0], 'the press is in the audit log, with who pressed' );
		$p = zdzk_page( array( 'zdz_keys_do' => 'run', 'zdz_keys_job' => 'example' ) );
		zdzk_ok( zdzk_has( $p, 'Ran it: 0 done · 1 couldn\'t read · 2 skipped. Finished.' ), 'Run again: nothing more done, 2 skipped: pressing twice changes nothing' );
		zdzk_ok( 2 === count( $GLOBALS['zdzk_logged'] ) && 'keys_migration_run' === $GLOBALS['zdzk_logged'][1][1] && zdzk_has( $GLOBALS['zdzk_logged'][1][2], 'example' ), 'that press is logged too' );
		$p = zdzk_page( array( 'zdz_keys_do' => 'run', 'zdz_keys_job' => 'no-such' ) );
		zdzk_ok( zdzk_has( $p, 'Nothing was moved: there is no such job.' ) && 2 === count( $GLOBALS['zdzk_logged'] ), 'a job that is not there: said, and nothing logged' );
		$p = zdzk_page( array( 'zdz_keys_do' => 'run_all' ), false );
		zdzk_ok( zdzk_has( $p, '[refused: nonce]' ) && 2 === count( $GLOBALS['zdzk_logged'] ), 'a press that did not come from this page (no nonce) goes no further' );
		break;

	case 'boot':
		ZDZ_Keys_Admin::boot();
		$hooks = array();
		foreach ( $GLOBALS['zdzk_hooks'] as $h ) {
			$hooks[ $h[0] ] = array( $h[1], $h[2] );
		}
		zdzk_ok( array( array( 'ZDZ_Keys_Admin', 'add_page' ), 22 ) === ( $hooks['admin_menu'] ?? null ), 'the page is added after the Zorderz menu and its other key pages' );
		zdzk_ok( array( 'ZDZ_Keys_Admin', 'notice' ) === ( $hooks['admin_notices'][0] ?? null ) && array( 'ZDZ_Keys_Admin', 'site_health' ) === ( $hooks['site_status_tests'][0] ?? null ), 'the notice and the Site Health item are hooked' );
		zdzk_ok( array( 'ZDZ_Keys', 'export_non_portable' ) === ( $hooks['zdz_export_non_portable_option_patterns'][0] ?? null ), 'and the key\'s own bookkeeping is kept out of a data export' );
		ZDZ_Keys_Admin::add_page();
		zdzk_ok( array( 'zdz-core-settings', 'manage_options', 'zdz-app-key' ) === $GLOBALS['zdzk_data']['submenu'], 'the page sits under Zorderz, for administrators' );
		$t = ZDZ_Keys_Admin::site_health( array( 'direct' => array( 'other' => array() ) ) );
		zdzk_ok( isset( $t['direct']['zdz_app_key'], $t['direct']['other'] ) && array( 'ZDZ_Keys_Admin', 'site_health_result' ) === $t['direct']['zdz_app_key']['test'], 'Site Health gains one test and keeps the others' );
		zdzk_ok( 0 === $GLOBALS['zdzk_writes'] && 'missing' === ZDZ_Keys::state(), 'booting reads no key and writes nothing' );
		break;

	/* ============================== a previous key */

	case 'previous_key_malformed':
		$i = ZDZ_Keys::info();
		zdzk_ok( 'ok' === ZDZ_Keys::state() && $i['prev_bad'] && '' === $i['prev_kid'] && ! ZDZ_Keys::rotating(), 'a previous-key line that is not a printed key: named, opens nothing, and the current key still works' );
		$p = zdzk_page();
		zdzk_ok( zdzk_has( $p, 'is set but is not a printed key' ) && ! zdzk_has( $p, $P ), 'the page says so, and never shows what the line holds' );
		break;

	case 'previous_key_same_as_current':
		zdzk_ok( 'ok' === ZDZ_Keys::state() && ! ZDZ_Keys::rotating() && '' === ZDZ_Keys::info()['prev_kid'], 'a previous key equal to the current one is ignored' );
		break;

	case 'previous_key_reads_old_values':
		// K1 is recorded and holds the stored values; the file now has this key, with K1 as the previous one
		$old = (string) zdzk_opt( 'zdz_example_row' );
		$mac = (string) zdzk_opt( 'zdz_example_mac' );
		zdzk_ok( 'ok' === ZDZ_Keys::state() && ZDZ_Keys::rotating() && zdzk_kid( $K ) === ZDZ_Keys::key_id(), 'a key change under way: ready, and the key id is the new one' );
		zdzk_ok( 'value-EXAMPLE-0001' === ZDZ_Keys::open( 'credentials', $old, 'zdz_example_row' ), 'a value under the previous key still opens' );
		zdzk_ok( ZDZ_Keys::verify_mac( 'sign', 'message-EXAMPLE', $mac ) && $mac !== ZDZ_Keys::mac( 'sign', 'message-EXAMPLE' ), 'a signature made with the previous key still verifies; new ones are made with the new key' );
		$new = ZDZ_Keys::seal( 'credentials', 'value-EXAMPLE-0002', 'zdz_other_row' );
		zdzk_ok( zdzk_kid( $K ) === ZDZ_Keys::header_key_id( $new ) && zdzk_kid( $P ) === ZDZ_Keys::header_key_id( $old ), 'what is written now is under the new key' );
		$moved = ZDZ_Keys::reseal( 'credentials', $old, 'zdz_example_row' );
		zdzk_ok( zdzk_kid( $K ) === ZDZ_Keys::header_key_id( $moved ) && 'value-EXAMPLE-0001' === ZDZ_Keys::open( 'credentials', $moved, 'zdz_example_row' ), 'reseal() moves the old value under the new key' );
		$as_new = 'zdzk1:' . zdzk_kid( $K ) . substr( $old, 14 );
		zdzk_ok( null === ZDZ_Keys::open( 'credentials', $as_new, 'zdz_example_row' ) && 'tampered' === ZDZ_Keys::why( $as_new, 'credentials', 'zdz_example_row' ), 'an old value relabelled with the new key id does not open' );
		$p = zdzk_page();
		zdzk_ok( zdzk_has( $p, 'A key change is under way' ) && zdzk_has( $p, zdzk_kid( $P ) . ' (a key change is under way)' ), 'the page says a key change is under way, with the previous key id' );
		zdzk_ok( null === ZDZ_Keys::recordable() && false === ZDZ_Keys::record_key( ZDZK_ADMIN ) && false === ZDZ_Keys::record_key( ZDZK_ADMIN, $K ), 'the new key is not recorded while a stored value is still under the previous one' );
		$GLOBALS['zdzk_data']['old'] = $old;
		break;

	case 'previous_key_gone_too_soon':
		// the previous-key line was taken out while a value is still under that key
		$old = (string) zdzk_opt( 'zdz_example_row' );
		zdzk_ok( 'mismatch' === ZDZ_Keys::state() && null === ZDZ_Keys::open( 'credentials', $old, 'zdz_example_row' ), 'without the previous-key line the recorded key is not there: locked, nothing read' );
		break;

	/* ============================== the jobs registry and the lock */

	case 'jobs_registry':
		$cb = function () {
			return array();
		};
		$ok = array( 'purpose' => 'credentials', 'items' => $cb, 'stored' => $cb, 'legacy' => $cb, 'write' => $cb );
		$GLOBALS['zdz_test_filters']['zdz_keys_migrations'] = function ( $jobs ) use ( $ok, $cb ) {
			$jobs['Bad Id!']          = $ok;
			$jobs['']                 = $ok;
			$jobs[ str_repeat( 'a', 41 ) ] = $ok;
			$jobs['no-purpose']       = array( 'purpose' => 'no-such' ) + $ok;
			$jobs['purpose-missing']  = array_diff_key( $ok, array( 'purpose' => 1 ) );
			$jobs['not-callable']     = array( 'stored' => 'no_such_function_example' ) + $ok;
			$jobs['no-write']         = array_diff_key( $ok, array( 'write' => 1 ) );
			$jobs['bad-kids']         = array( 'kids' => 'no_such_function_example' ) + $ok;
			$jobs['not-an-array']     = 'credentials';
			$jobs['good']             = array(
				'items'  => function () {
					return array( 'a' );
				},
				'stored' => function ( $item ) {
					return 'legacy:value-EXAMPLE-0001';
				},
				'legacy' => function ( $item, $raw ) {
					return substr( $raw, 7 );
				},
				'write'  => function ( $item, $value ) {
					$GLOBALS['zdzk_data']['wrote'] = true;
					return true;
				},
			) + $ok;
			return $jobs;
		};
		$jobs = ZDZ_Keys_Migrate::jobs();
		zdzk_ok( array( 'good' ) === array_keys( $jobs ) && 'good' === $jobs['good']['label'], 'a job with a bad id, an unknown purpose or a part that cannot be called is dropped; a job without a label is named by its id' );
		$GLOBALS['zdzk_lock'] = '0';
		$r                    = ZDZ_Keys_Migrate::run( 'good' );
		zdzk_ok( array( 'error' => 'busy' ) === $r && ! isset( $GLOBALS['zdzk_data']['wrote'] ) && null === zdzk_opt( 'zdz_keys_migrate_good' ), 'another run holds the lock: busy, nothing changed' );
		$GLOBALS['zdzk_lock'] = '1';
		zdzk_ok( array( 'error' => 'no_such_job' ) === ZDZ_Keys_Migrate::run( 'none' ) && array( 'error' => 'no_such_job' ) === ZDZ_Keys_Migrate::run( 'Bad Id!' ), 'a job that is not registered, or was dropped, does not run' );
		$GLOBALS['zdz_test_filters']['zdz_keys_migrations'] = function ( $jobs ) {
			return 'not a list';
		};
		zdzk_ok( array() === ZDZ_Keys_Migrate::jobs() && array() === ZDZ_Keys_Migrate::run_all(), 'a filter that answers nonsense registers nothing' );
		break;

	case 'lock_is_given_back':
		zdzk_rows( 'plain', array( 'a' => 'legacy:value-EXAMPLE-0001' ) );
		zdzk_job( 'plain' );
		ZDZ_Keys_Migrate::run( 'plain', 200, ZDZK_ADMIN );
		zdzk_ok( 1 === $GLOBALS['wpdb']->locked && 0 === $GLOBALS['wpdb']->held, 'a run takes the lock once and gives it back' );
		zdzk_rows( 'throws', array( 'a' => 'legacy:value-EXAMPLE-0001' ) );
		zdzk_job(
			'throws',
			array(
				'stored' => function ( $item ) {
					throw new RuntimeException( 'the table is not there' );
				},
				'kids'   => function () {
					return array();
				},
			)
		);
		$threw = false;
		try {
			ZDZ_Keys_Migrate::run( 'throws', 200, ZDZK_ADMIN );
		} catch ( RuntimeException $e ) {
			$threw = true;
		}
		zdzk_ok( $threw && 2 === $GLOBALS['wpdb']->locked && 0 === $GLOBALS['wpdb']->held, 'a run that fails part-way gives the lock back too' );
		zdzk_ok( null === zdzk_opt( 'zdz_keys_migrate_throws' ), 'and keeps no count of a press it could not finish' );
		break;

	/* ============================== moving stored values onto the key */

	case 'move_start':
		// six values the way stored credentials are kept today (under the WordPress salts), and one under other salts
		$rows = array();
		for ( $n = 1; $n <= 6; $n++ ) {
			$rows[ 'row' . $n ] = ZDZ_Core_Crypto::encode( 'provider-key-EXAMPLE-000' . $n, ZDZ_Core_Crypto::FIXED );
		}
		$other        = 'zdzk-unit-salt-other-auth-0123456789abcdef0123456789abcdef';   // another site's salt
		$rows['row7'] = openssl_encrypt( 'provider-key-EXAMPLE-0007', 'AES-256-CBC', $other, 0, substr( hash( 'sha256', $other ), 0, 16 ) );
		zdzk_rows( 'creds', $rows );
		$GLOBALS['zdzk_data']['before'] = $rows;
		// fall through: the first press
	case 'move_press':
		zdzk_job(
			'creds',
			array(
				'legacy' => function ( $item, $raw ) {
					$p = ZDZ_Core_Crypto::decode( (string) $raw );
					return in_array( $p[0], array( ZDZ_Core_Crypto::FIXED, ZDZ_Core_Crypto::PAIR ), true ) ? $p[1] : null;
				},
			)
		);
		$r = ZDZ_Keys_Migrate::run( 'creds', '' !== zdzk_env( 'ARG' ) ? (int) zdzk_env( 'ARG' ) : 3, ZDZK_ADMIN );
		$GLOBALS['zdzk_data']['run']  = array_intersect_key( $r, array_flip( array( 'cursor', 'done', 'skipped', 'unreadable', 'unreadable_n', 'damaged', 'finished', 'again', 'by' ) ) );
		$GLOBALS['zdzk_data']['rows'] = $GLOBALS['zdzk_tables']['creds'];
		$opens                        = array();
		foreach ( $GLOBALS['zdzk_tables']['creds'] as $item => $v ) {
			$opens[ $item ] = ZDZ_Keys::is_zdzk1( $v ) ? ZDZ_Keys::open( 'credentials', $v, $item ) : null;
		}
		$GLOBALS['zdzk_data']['opens']    = $opens;
		$GLOBALS['zdzk_data']['all_done'] = ZDZ_Keys_Migrate::all_done();
		zdzk_ok( true, 'pressed' );
		break;

	case 'move_read_back':
		// a store that says yes and keeps the wrong bytes, and one that half-writes and says no: both are caught
		zdzk_rows( 'odd', array( 'h1' => 'legacy:value-EXAMPLE-0001', 'h2' => 'legacy:value-EXAMPLE-0002', 'h3' => 'legacy:value-EXAMPLE-0003' ) );
		zdzk_job(
			'odd',
			array(
				'write' => function ( $item, $value ) {
					if ( 'h1' === $item && 0 === strpos( $value, 'zdzk1:' ) ) {
						$GLOBALS['zdzk_tables']['odd'][ $item ] = substr( $value, 0, 20 );   // half, and says no
						return false;
					}
					if ( 'h2' === $item && 0 === strpos( $value, 'zdzk1:' ) ) {
						$GLOBALS['zdzk_tables']['odd'][ $item ] = strrev( $value );           // the wrong bytes, and says yes
						return true;
					}
					$GLOBALS['zdzk_tables']['odd'][ $item ] = $value;
					return true;
				},
			)
		);
		$r = ZDZ_Keys_Migrate::run( 'odd', 200, ZDZK_ADMIN );
		zdzk_ok( 'legacy:value-EXAMPLE-0001' === zdzk_row( 'odd', 'h1' ) && 'legacy:value-EXAMPLE-0002' === zdzk_row( 'odd', 'h2' ), 'a half write and a wrong write are both read back, found out, and the old bytes put back' );
		zdzk_ok( array( 'h1', 'h2' ) === $r['unreadable'] && 2 === $r['unreadable_n'] && 1 === $r['done'] && array() === $r['damaged'], 'they are listed, not counted as done; nothing is damaged' );
		zdzk_ok( 'value-EXAMPLE-0003' === ZDZ_Keys::open( 'credentials', zdzk_row( 'odd', 'h3' ), 'h3' ) && true === $r['finished'], 'the third moved; the job finishes with the two listed' );
		break;

	case 'value_that_cannot_be_put_back':
		zdzk_rows( 'stuck', array( 'd1' => 'legacy:value-EXAMPLE-0001' ) );
		zdzk_job(
			'stuck',
			array(
				'write' => function ( $item, $value ) {
					$GLOBALS['zdzk_tables']['stuck'][ $item ] = 'stuck-' . substr( md5( $value ), 0, 6 );
					return false;
				},
			)
		);
		$r1 = ZDZ_Keys_Migrate::run( 'stuck', 200, ZDZK_ADMIN );
		$r2 = ZDZ_Keys_Migrate::run( 'stuck', 200, ZDZK_ADMIN );
		zdzk_ok( array( 'd1' ) === $r1['damaged'] && array( 'd1' ) === $r2['damaged'] && false === $r2['finished'] && false === ZDZ_Keys_Migrate::all_done(), 'a value whose old bytes could not be put back: listed once, the job stays open, the move is not done' );
		$p = zdzk_page();
		zdzk_ok( zdzk_has( $p, '1 could not be put back as it was after a failed write:' ) && zdzk_has( $p, 'restore them from a backup' ) && zdzk_has( $p, '(not finished)' ), 'the page says so loudly, and what to do' );
		break;

	case 'list_grows_between_presses':
		zdzk_rows( 'grow', array( 'b1' => 'legacy:1', 'b2' => 'legacy:2', 'b3' => 'legacy:3', 'b4' => 'legacy:4', 'b5' => 'legacy:5' ) );
		zdzk_job(
			'grow',
			array(
				'items' => function () {
					$k = array_map( 'strval', array_keys( $GLOBALS['zdzk_tables']['grow'] ) );
					sort( $k );
					return $k;
				},
			)
		);
		$r = ZDZ_Keys_Migrate::run( 'grow', 2 );
		zdzk_ok( 2 === $r['done'] && false === $r['finished'] && 1 === $r['cursor'], 'the first press moves two of five and keeps its place' );
		$GLOBALS['zdzk_tables']['grow']['a0'] = 'legacy:0';   // an item that sorts before the place it kept
		$r                                    = ZDZ_Keys_Migrate::run( 'grow', 100 );
		zdzk_ok( false === $r['finished'] && true === $r['again'] && ! ZDZ_Keys::is_zdzk1( zdzk_row( 'grow', 'a0' ) ), 'a list that grew behind it: not finished, a new pass asked for' );
		zdzk_ok( zdzk_has( zdzk_page(), 'More values appeared since this pass began' ), 'the page says so' );
		$r = ZDZ_Keys_Migrate::run( 'grow', 100 );
		zdzk_ok( true === $r['finished'] && false === $r['again'] && ZDZ_Keys::is_zdzk1( zdzk_row( 'grow', 'a0' ) ) && 6 === $r['done'] && 5 === $r['skipped'], 'the next pass moves it and finishes: six moved in all, five passed over in this pass' );
		break;

	case 'list_shifts_under_the_cursor':
		zdzk_rows( 'shift', array( 'm1' => 'unreadable-bytes', 'm2' => 'legacy:value-EXAMPLE-0002' ) );
		zdzk_job(
			'shift',
			array(
				'items' => function () {
					$k = array_map( 'strval', array_keys( $GLOBALS['zdzk_tables']['shift'] ) );
					sort( $k );
					return $k;
				},
			)
		);
		$r = ZDZ_Keys_Migrate::run( 'shift', 1 );
		zdzk_ok( 1 === $r['unreadable_n'] && array( 'm1' ) === $r['unreadable'] && 0 === $r['cursor'], 'the first press reaches one value, which it cannot read' );
		$GLOBALS['zdzk_tables']['shift']['a0'] = '';   // an empty row that sorts first: every place moves down by one
		$r                                     = ZDZ_Keys_Migrate::run( 'shift', 100 );
		zdzk_ok( 1 === $r['unreadable_n'] && array( 'm1' ) === $r['unreadable'] && 1 === $r['done'] && true === $r['finished'], 'a value met a second time because the list shifted is counted once' );
		break;

	case 'job_callback_asks_during_first_scan':
		$GLOBALS['zdzk_data']['seen'] = array();
		zdzk_rows( 'asks', array() );
		zdzk_job(
			'asks',
			array(
				'items' => function () {
					$GLOBALS['zdzk_data']['seen'][] = ZDZ_Keys::state();
					return array( 'x' );
				},
			)
		);
		zdzk_ok( 'ok' === ZDZ_Keys::state() && array( 'mismatch' ) === array_values( array_unique( $GLOBALS['zdzk_data']['seen'] ) ), 'a job that asks about the key while the first scan runs gets "locked", not an endless loop; then the key is ready' );
		break;

	case 'many_unreadable_values':
		$rows = array();
		for ( $n = 1; $n <= 250; $n++ ) {
			$rows[ sprintf( 'u%03d', $n ) ] = 'unreadable-bytes';
		}
		$rows['v001'] = 'legacy:value-EXAMPLE-0001';
		zdzk_rows( 'many', $rows );
		zdzk_job( 'many', array( 'purpose' => 'inbox-content' ) );
		$r = ZDZ_Keys_Migrate::run( 'many', 1000 );
		zdzk_ok( true === $r['finished'] && 1 === $r['done'] && 250 === $r['unreadable_n'] && ZDZ_Keys_Migrate::LIST_MAX === count( $r['unreadable'] ), 'finished in one pass: 1 done, 250 it could not read, 200 of them named' );
		$r = ZDZ_Keys_Migrate::run( 'many', 1000 );
		zdzk_ok( true === $r['finished'] && 250 === $r['unreadable_n'] && 1 === $r['skipped'] && 0 === $r['done'], 'pressed again: still 250, not more' );
		zdzk_ok( true === ZDZ_Keys_Migrate::all_done() && 'unreadable-bytes' === zdzk_row( 'many', 'u001' ), 'the move counts as done, and what it could not read is as it was' );
		$p = zdzk_page();
		zdzk_ok( zdzk_has( $p, '250 couldn\'t be read (by row; left as they were)' ) && zdzk_has( $p, 'u200 …' ) && ! zdzk_has( $p, 'u201' ), 'the page names the first 200 and says there are more' );
		break;

	/* ============================== a deliberate key change */

	case 'key_change_held_open_by_an_option':
		// K1 is recorded; an option is still sealed under K1; no job; the file now holds this key with K1 as previous
		zdzk_ok( ZDZ_Keys::rotating() && false === ZDZ_Keys_Migrate::all_done(), 'a value still under the previous key holds the key change open' );
		$r = ZDZ_Keys_Migrate::run_all( 200, ZDZK_ADMIN );
		zdzk_ok( empty( $r['_recorded'] ) && zdzk_kid( $P ) === zdzk_opt( 'zdz_keys_state' )['kid'], 'Re-encrypt everything now does not record the new key' );
		zdzk_ok( null === ZDZ_Keys::recordable() && false === ZDZ_Keys::record_key( ZDZK_ADMIN ), 'nor does Record' );
		break;

	case 'key_change_start':
		// five values under the recorded key K1, kept in an app's own table; the file now has this key, K1 as previous
		$rows = array();
		for ( $n = 1; $n <= 5; $n++ ) {
			$rows[ 'row' . $n ] = zdzk_seal_under( $P, $CRE, 'credentials', 'provider-key-EXAMPLE-000' . $n, 'row' . $n );
		}
		zdzk_rows( 'change', $rows );
		zdzk_job( 'change' );
		zdzk_ok( ZDZ_Keys::rotating() && array( zdzk_kid( $P ) ) === ZDZ_Keys::sealed_kids() && false === ZDZ_Keys_Migrate::all_done(), 'before: the scan finds the previous key id, through the job' );
		zdzk_ok( 'provider-key-EXAMPLE-0003' === ZDZ_Keys::open( 'credentials', zdzk_row( 'change', 'row3' ), 'row3' ), 'both keys read: a value under the previous key opens' );
		zdzk_ok( zdzk_has( zdzk_page(), 'A key change ends links and signatures made with the previous key.' ), 'the page says what a key change ends, before anything is pressed' );
		$r = ZDZ_Keys_Migrate::run( 'change', 2, ZDZK_ADMIN );
		zdzk_ok( 2 === $r['done'] && false === $r['finished'] && false === ZDZ_Keys_Migrate::all_done(), 'a first press moves two of five' );
		zdzk_ok( null === ZDZ_Keys::recordable() && false === ZDZ_Keys::record_key( ZDZK_ADMIN ) && zdzk_kid( $P ) === zdzk_opt( 'zdz_keys_state' )['kid'], 'Record is refused until the re-encryption has finished' );
		$r = ZDZ_Keys_Migrate::run_all( 200, ZDZK_ADMIN );
		zdzk_ok( true === ( $r['_recorded'] ?? null ) && 5 === $r['change']['done'] && true === $r['change']['finished'], 'Re-encrypt everything now moves the other three (five of five), sees that nothing is left under the previous key, and records the new key' );
		$st = (array) zdzk_opt( 'zdz_keys_state' );
		zdzk_ok( zdzk_kid( $K ) === $st['kid'] && zdzk_kid( $P ) === $st['prev_kid'] && 'rotated' === $st['how'] && ZDZK_ADMIN === $st['by'], 'the record names the new key id, the one it replaced, and who pressed' );
		zdzk_ok( 'ok' === ZDZ_Keys::state() && ! ZDZ_Keys::rotating() && array( zdzk_kid( $K ) ) === ZDZ_Keys::sealed_kids(), 'the key change is over: every stored value is under the new key' );
		$p = zdzk_page();
		zdzk_ok( zdzk_has( $p, zdzk_kid( $P ) . ' (can be removed once nothing is under it)' ), 'the page says the previous-key line can go' );
		break;

	case 'key_change_done':
		// the next request, the previous-key line taken out
		$all = true;
		for ( $n = 1; $n <= 5; $n++ ) {
			$all = $all && 'provider-key-EXAMPLE-000' . $n === ZDZ_Keys::open( 'credentials', zdzk_row( 'change', 'row' . $n ), 'row' . $n );
		}
		zdzk_job( 'change' );
		zdzk_ok( 'ok' === ZDZ_Keys::state() && ! ZDZ_Keys::rotating() && $all, 'with the previous-key line removed everything still reads' );
		$r = ZDZ_Keys_Migrate::run( 'change', 200, ZDZK_ADMIN );
		zdzk_ok( 0 === $r['done'] && 5 === $r['skipped'] && true === $r['finished'], 'and a press moves nothing more' );
		break;

	case 'digits_only_key_id':
		// both key ids are made of digits only, the kind PHP turns into a number when it is an array key
		zdzk_ok( 1 === preg_match( '/^\d{8}$/D', zdzk_kid( $K ) ) && 1 === preg_match( '/^\d{8}$/D', zdzk_kid( $P ) ), 'the test drew two keys whose ids are digits only' );
		zdzk_rows( 'digits', array( 'row1' => zdzk_seal_under( $P, $CRE, 'credentials', 'value-EXAMPLE-0001', 'row1' ) ) );
		zdzk_job( 'digits' );
		$kids = ZDZ_Keys::sealed_kids();
		zdzk_ok( array( zdzk_kid( $P ) ) === $kids && is_string( $kids[0] ), 'a key id read through a job is text, never a number' );
		zdzk_ok( array( zdzk_kid( $P ), 'found' ) === ZDZ_Keys::recordable() && true === ZDZ_Keys::record_key( ZDZK_ADMIN, $P ) && ZDZ_Keys::rotating(), 'a lost record with values under the previous key: the previous key id is recorded' );
		$r = ZDZ_Keys_Migrate::run_all( 200, ZDZK_ADMIN );
		zdzk_ok( true === ( $r['_recorded'] ?? null ) && zdzk_kid( $K ) === zdzk_opt( 'zdz_keys_state' )['kid'] && true === ZDZ_Keys_Migrate::all_done(), 'and the key change finishes and is recorded' );
		break;

	/* ============================== the WordPress salts change */

	case 'salts_seal':
		// salts "one": seal under the app's key, sign, and (for comparison) keep one credential the way it is kept today
		$GLOBALS['zdzk_data']['fp_auth'] = substr( hash( 'sha256', wp_salt( 'auth' ) ), 0, 12 );
		update_option( 'zdz_example_credentials', ZDZ_Keys::seal( 'credentials', 'provider-key-EXAMPLE-0001', 'zdz_example_credentials' ) );
		update_option( 'zdz_example_token', ZDZ_Keys::seal( 'inbox-tokens', 'sign-in-token-EXAMPLE-0002', 'zdz_example_token' ) );
		update_option( 'zdz_example_code', ZDZ_Keys::seal( 'sealed-codes', '4821', 'zdz_sealed:7:sealed' ) );
		update_option( 'zdz_example_mac', ZDZ_Keys::mac( 'links', 'share-link-EXAMPLE|42' ) );
		$long = 'provider-key-EXAMPLE-0003-' . str_repeat( 'x', 48 );
		update_option( 'zdz_example_salted', ZDZ_Core_Crypto::encode( $long, ZDZ_Core_Crypto::FIXED ) );
		zdzk_ok( $long === ZDZ_Core_Crypto::plain( (string) zdzk_opt( 'zdz_example_salted' ) ), 'before the change, the credential kept under the salts reads' );
		zdzk_ok( 'ok' === ZDZ_Keys::state(), 'and the app\'s key is ready' );
		break;

	case 'salts_changed':
		$GLOBALS['zdzk_data']['fp_auth'] = substr( hash( 'sha256', wp_salt( 'auth' ) ), 0, 12 );
		zdzk_ok( '' === ZDZ_Core_Crypto::plain( (string) zdzk_opt( 'zdz_example_salted' ) ) && 'undecodable' === ZDZ_Core_Crypto::decode( (string) zdzk_opt( 'zdz_example_salted' ) )[0], 'after the salts changed, the credential kept under the salts no longer reads (the change is real)' );
		zdzk_ok( 'ok' === ZDZ_Keys::state() && 0 === $GLOBALS['zdzk_writes'], 'the app\'s key is still ready, and nothing needed recording again' );
		zdzk_ok( 'provider-key-EXAMPLE-0001' === ZDZ_Keys::open( 'credentials', (string) zdzk_opt( 'zdz_example_credentials' ), 'zdz_example_credentials' ), 'a credential sealed under the app\'s key still opens' );
		zdzk_ok( 'sign-in-token-EXAMPLE-0002' === ZDZ_Keys::open( 'inbox-tokens', (string) zdzk_opt( 'zdz_example_token' ), 'zdz_example_token' ) && '4821' === ZDZ_Keys::open( 'sealed-codes', (string) zdzk_opt( 'zdz_example_code' ), 'zdz_sealed:7:sealed' ), 'so do a sign-in token and a code' );
		zdzk_ok( ZDZ_Keys::verify_mac( 'links', 'share-link-EXAMPLE|42', (string) zdzk_opt( 'zdz_example_mac' ) ) && (string) zdzk_opt( 'zdz_example_mac' ) === ZDZ_Keys::mac( 'links', 'share-link-EXAMPLE|42' ), 'and a signature made before the change still verifies' );
		break;

	/* ============================== beside the keys Zorderz already has: nothing of theirs changes */

	case 'older_keys_seal':
		// salts "one", the app's key ready: the gate-code vault, the salt watch and stored credentials do what they did
		zdzk_ok( 'ok' === ZDZ_Keys::state() && ZDZ_Sealed::can_keep() && ! defined( 'ZDZ_SEALED_KEY' ), 'the app\'s key is ready, and gate codes are kept under their own key, made from the salts' );
		$id  = ZDZ_Sealed::seal( 'gate', '4821', array( 'job', 12 ) );
		$row = $GLOBALS['zdzk_tables']['wp_zdz_sealed'][ (string) $id ] ?? array();
		zdzk_ok( $id > 0 && 0 === strpos( (string) ( $row['sealed'] ?? '' ), 'v1:' ) && ! zdzk_has( json_encode( $row ), '4821' ), 'a gate code is sealed in the vault\'s own format, as before' );
		zdzk_ok( '4821' === ZDZ_Sealed::open( $id, ZDZK_ADMIN ) && null === ZDZ_Sealed::open( $id, ZDZK_SHARED ), 'and opens for an administrator, never for the shared device' );
		zdzk_ok( array() === ZDZ_Salt_Watch::changed() && 4 === count( (array) ( zdzk_opt( 'zdz_salt_fingerprints' )['fp'] ?? array() ) ), 'the salt watch keeps its four fingerprints' );
		$long = 'provider-key-EXAMPLE-0003-' . str_repeat( 'x', 48 );
		update_option( 'zdz_example_salted', ZDZ_Core_Crypto::encode( $long, ZDZ_Core_Crypto::FIXED ) );
		zdzk_ok( ZDZ_Core_Crypto::FIXED === ZDZ_Core_Crypto::decode( (string) zdzk_opt( 'zdz_example_salted' ) )[0] && $long === ZDZ_Core_Crypto::plain( (string) zdzk_opt( 'zdz_example_salted' ) ), 'a stored credential is kept the way it was kept: under the salts' );
		ZDZ_Keys::forget_scan();
		zdzk_ok( array() === ZDZ_Keys::sealed_kids() && ! zdzk_has( json_encode( $GLOBALS['zdz_test_options'] ) . json_encode( $GLOBALS['zdzk_tables'] ), 'zdzk1:' ), 'nothing of theirs was put under the app\'s key' );
		file_put_contents( zdzk_env( 'HANDOVER' ), ZDZ_Sealed::recovery_code() );
		$GLOBALS['zdzk_data']['code_id'] = $id;
		$GLOBALS['zdzk_data']['check']   = (string) zdzk_opt( 'zdz_sealed_key_check' );
		break;

	case 'older_keys_after_salt_change':
		// salts "two", the same app key: what depended on the salts still breaks, exactly as it did
		$id = (int) zdzk_env( 'ARG' );
		zdzk_ok( 'ok' === ZDZ_Keys::state() && 0 === $GLOBALS['zdzk_writes'], 'the app\'s key is still ready' );
		zdzk_ok( ZDZ_Sealed::locked() && ! ZDZ_Sealed::can_keep() && null === ZDZ_Sealed::open( $id, ZDZK_ADMIN ) && 'locked' === ZDZ_Sealed::why_not( $id, ZDZK_ADMIN ) && 0 === ZDZ_Sealed::seal( 'gate', '1234', array( 'job', 12 ) ), 'gate codes are locked by the salt change: the app\'s key neither opens nor re-keys them' );
		zdzk_ok( zdzk_env( 'K1' ) === (string) zdzk_opt( 'zdz_sealed_key_check' ), 'and their check value was not re-stored' );
		$changed = ZDZ_Salt_Watch::changed();
		$lines   = implode( ' | ', ZDZ_Salt_Watch::breaks( $changed ) );
		zdzk_ok( array( 'auth', 'secure_auth', 'logged_in', 'nonce' ) === $changed, 'the salt watch names the salts that changed' );
		zdzk_ok( zdzk_has( $lines, 'Stored credentials can no longer be read' ) && zdzk_has( $lines, 'Gate codes are locked' ) && zdzk_has( $lines, 'Everyone is signed out' ) && ! zdzk_has( $lines, 'Nothing else is affected' ), 'and still says what a salt change breaks: its words are not softened while stored values depend on the salts' );
		zdzk_ok( 'undecodable' === ZDZ_Core_Crypto::decode( (string) zdzk_opt( 'zdz_example_salted' ) )[0], 'the stored credential cannot be read, as before' );
		break;

	case 'older_keys_recovery_code':
		// salts "two", and the gate codes' own printed code in ZDZ_SEALED_KEY: their own way back, as before
		$id = (int) zdzk_env( 'ARG' );
		zdzk_ok( defined( 'ZDZ_SEALED_KEY' ) && ZDZ_Sealed::can_keep() && '' === ZDZ_Sealed::not_ready_reason(), 'the gate codes\' own key line unlocks them' );
		zdzk_ok( '4821' === ZDZ_Sealed::open( $id, ZDZK_ADMIN ), 'and the code sealed before the salt change opens' );
		zdzk_ok( zdzk_has( implode( ' | ', ZDZ_Salt_Watch::breaks( array( 'secure_auth' ) ) ), 'Gate codes still open' ), 'the salt watch says so' );
		zdzk_ok( 'ok' === ZDZ_Keys::state() && ! ZDZ_Keys::code_matches( (string) constant( 'ZDZ_SEALED_KEY' ) ) && 'none' === ZDZ_Keys::code_reads_as( (string) constant( 'ZDZ_SEALED_KEY' ) ), 'the two keys are two keys: the gate codes\' code is not the app\'s key' );
		break;

	case 'older_keys_bad_recovery_code':
		$id = (int) zdzk_env( 'ARG' );
		zdzk_ok( 'bad_constant' === ZDZ_Sealed::not_ready_reason() && null === ZDZ_Sealed::open( $id, ZDZK_ADMIN ) && 0 === ZDZ_Sealed::seal( 'gate', '1234', array( 'job', 12 ) ), 'a gate-code key line that is not a recovery code opens nothing, whatever the app\'s key is' );
		zdzk_ok( 'ok' === ZDZ_Keys::state(), 'and the app\'s key is not affected by it' );
		break;

	case 'older_keys_without_real_salts':
		// the eight salt lines were never filled in: the vault refuses, and the app's key does not depend on them
		zdzk_ok( ! ZDZ_Sealed::salts_real() && 'no_salts' === ZDZ_Sealed::not_ready_reason() && 0 === ZDZ_Sealed::seal( 'gate', '4821', array( 'job', 12 ) ), 'without real salts the gate-code vault seals nothing, as before' );
		$s = ZDZ_Keys::seal( 'credentials', 'value-EXAMPLE-0001', 'zdz_example_row' );
		zdzk_ok( 'ok' === ZDZ_Keys::state() && 'value-EXAMPLE-0001' === ZDZ_Keys::open( 'credentials', (string) $s, 'zdz_example_row' ), 'the app\'s key seals and opens all the same: it is made from no salt' );
		break;

	/* ============================== no libsodium */

	case 'no_sodium':
		$b = ZDZ_Keys_Admin::banner();
		zdzk_ok( 'no_sodium' === ZDZ_Keys::state() && is_array( $b ) && zdzk_has( $b[0], 'no libsodium' ) && zdzk_has( $b[1], 'sodium extension' ), 'without libsodium: its own state and notice' );
		zdzk_ok( null === ZDZ_Keys::seal( 'credentials', 'x', 'row' ) && null === ZDZ_Keys::mac( 'sign', 'm' ) && null === ZDZ_Keys::recordable() && '' === ZDZ_Keys::info()['kid'], 'nothing sealed or signed, nothing to record, no key read' );
		$p = zdzk_page( array( 'zdz_keys_do' => 'check', 'zdz_keys_code' => $K ) );
		zdzk_ok( zdzk_has( $p, 'That code is not this site\'s key' ) && zdzk_has( $p, 'It read 52 characters.' ), 'the page still opens, and Check a printed key still answers' );
		zdzk_ok( 0 === $GLOBALS['zdzk_writes'], 'nothing written' );
		break;

	default:
		fwrite( STDERR, 'no such case: ' . $GLOBALS['zdzk_case'] . "\n" );
		exit( 2 );
}

echo json_encode(
	array(
		'case'   => $GLOBALS['zdzk_case'],
		'checks' => $GLOBALS['zdzk_checks'],
		'logged' => $GLOBALS['zdzk_logged'],
		'writes' => $GLOBALS['zdzk_writes'],
		'data'   => $GLOBALS['zdzk_data'],
	)
);
exit( 0 );
