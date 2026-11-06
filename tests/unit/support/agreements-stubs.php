<?php
/**
 * Shared stand-ins for the agreements app's unit tests. Every WordPress stub reads the same globals the other suites
 * use (zdz_test_options, zdz_test_transients, zdz_test_user_caps, zdz_test_user_roles) and behaves like WordPress;
 * every test double carries the agreements prefix (ZDZ_Test_Agr_*), because the whole suite runs in one PHP process.
 *
 * @package Zorderz\Tests
 */

if ( ! defined( 'ZAGR_DIR' ) ) {
	define( 'ZAGR_DIR', dirname( __DIR__, 3 ) . '/zorderz-apps/apps/agreements/' );
	define( 'ZAGR_URL', 'https://app.example.com/wp-content/plugins/zorderz-apps/apps/agreements/' );
	define( 'ZAGR_VERSION', '1.0.0' );
}
if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( $k, $v, $autoload = null ) { $GLOBALS['zdz_test_options'][ $k ] = $v; return true; }
}
if ( ! function_exists( 'add_option' ) ) {
	function add_option( $k, $v = '', $d = '', $autoload = null ) {
		if ( isset( $GLOBALS['zdz_test_options'][ $k ] ) ) {
			return false;
		}
		$GLOBALS['zdz_test_options'][ $k ] = $v;
		return true;
	}
}
if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( $k ) { unset( $GLOBALS['zdz_test_options'][ $k ] ); return true; }
}
if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( $k ) { return $GLOBALS['zdz_test_transients'][ $k ] ?? false; }
}
if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( $k, $v, $ttl = 0 ) { $GLOBALS['zdz_test_transients'][ $k ] = $v; return true; }
}
if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( $k ) { unset( $GLOBALS['zdz_test_transients'][ $k ] ); return true; }
}
if ( ! function_exists( 'wp_timezone_string' ) ) {
	function wp_timezone_string() { return 'UTC'; }
}

/**
 * A $wpdb stand-in over an in-memory SQLite database: prepare() with %d / %s / %F, get_var, get_row, get_results,
 * query (rows changed), insert (false on a unique-key clash, as MySQL), update, delete, suppress_errors.
 */
class ZDZ_Test_Agr_DB {
	public $prefix     = 'wp_';
	public $insert_id  = 0;
	public $last_error = '';
	private $pdo;

	public function __construct() {
		$this->pdo = new PDO( 'sqlite::memory:' );
		$this->pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
		$this->pdo->exec( 'CREATE TABLE wp_zagr_agreements ( id INTEGER PRIMARY KEY AUTOINCREMENT, estimate_id INTEGER NOT NULL UNIQUE, client_id INTEGER DEFAULT 0, is_test INTEGER DEFAULT 0, link_hash TEXT NOT NULL UNIQUE, link_sealed TEXT DEFAULT \'\', state TEXT DEFAULT \'draft\', language_ok INTEGER, doc_hash TEXT DEFAULT \'\', document_sealed TEXT DEFAULT \'\', schedule_json TEXT DEFAULT \'\', template_version TEXT DEFAULT \'\', plan_version TEXT DEFAULT \'\', email_sealed TEXT DEFAULT \'\', contractor_user_id INTEGER DEFAULT 0, contractor_signed_at TEXT, render_hash TEXT DEFAULT \'\', age_sealed TEXT, signed_at TEXT, copy_received_at TEXT, copy_via TEXT DEFAULT \'\', cancel_by TEXT, pdf_cancel_by TEXT, window_closed_at TEXT, cancelled_at TEXT, cancel_how TEXT DEFAULT \'\', pdf_sha256 TEXT DEFAULT \'\', pdf_file TEXT DEFAULT \'\', vault_doc_id INTEGER DEFAULT 0, head_hash TEXT DEFAULT \'\', event_count INTEGER DEFAULT 0, created_at TEXT, updated_at TEXT )' ); // sentinel:allow test fixture: the pattern is test data or a stand-in, never run against input
		$this->pdo->exec( 'CREATE TABLE wp_zagr_events ( id INTEGER PRIMARY KEY AUTOINCREMENT, agreement_id INTEGER NOT NULL, seq INTEGER NOT NULL, type TEXT NOT NULL, at_utc TEXT NOT NULL, actor TEXT DEFAULT \'\', ip_sealed TEXT NOT NULL, ua_sealed TEXT NOT NULL, detail TEXT NOT NULL, prev_hash TEXT NOT NULL, hash TEXT NOT NULL, UNIQUE ( agreement_id, seq ) )' ); // sentinel:allow test fixture: the pattern is test data or a stand-in, never run against input
		$this->pdo->exec( 'CREATE TABLE wp_zagr_codes ( id INTEGER PRIMARY KEY AUTOINCREMENT, agreement_id INTEGER NOT NULL, session_id INTEGER NOT NULL, purpose TEXT NOT NULL, bound_for TEXT DEFAULT \'\', code_mac TEXT NOT NULL, created_at TEXT NOT NULL, expires_at TEXT NOT NULL, tries INTEGER DEFAULT 0, used_at TEXT, ip_tag TEXT DEFAULT \'\' )' ); // sentinel:allow test fixture: the pattern is test data or a stand-in, never run against input
		$this->pdo->exec( 'CREATE TABLE wp_zagr_locks ( id INTEGER PRIMARY KEY AUTOINCREMENT, agreement_id INTEGER NOT NULL, purpose TEXT NOT NULL, source TEXT DEFAULT \'\', wrong INTEGER DEFAULT 0, locked_until TEXT, updated_at TEXT NOT NULL, UNIQUE ( agreement_id, purpose, source ) )' ); // sentinel:allow test fixture: the pattern is test data or a stand-in, never run against input
	}

	public function suppress_errors( $on = true ) {
		return false;
	}

	public function prepare( $sql, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		$pdo = $this->pdo;
		$i   = 0;
		return preg_replace_callback( '/%[dsF]/', function ( $m ) use ( &$i, $args, $pdo ) {
			$v = $args[ $i++ ] ?? null;
			if ( '%d' === $m[0] ) {
				return (string) (int) $v;
			}
			if ( '%F' === $m[0] ) {
				return (string) (float) $v;
			}
			return $pdo->quote( (string) $v );
		}, $sql );
	}

	private function run( $sql ) {
		$this->last_error = '';
		try {
			return $this->pdo->query( $sql );
		} catch ( \PDOException $e ) {
			$this->last_error = $e->getMessage();
			return null;
		}
	}

	public function get_var( $sql ) {
		$st = $this->run( $sql );
		$v  = $st ? $st->fetchColumn() : false;
		return false === $v ? null : $v;
	}

	public function get_row( $sql, $out = null ) {
		$st = $this->run( $sql );
		$r  = $st ? $st->fetch( PDO::FETCH_ASSOC ) : false;
		return false === $r ? null : $r;
	}

	public function get_results( $sql, $out = null ) {
		$st = $this->run( $sql );
		return $st ? $st->fetchAll( PDO::FETCH_ASSOC ) : array();
	}

	public function query( $sql ) {
		$this->last_error = '';
		try {
			return $this->pdo->exec( $sql ); // sentinel:allow test fixture: the pattern is test data or a stand-in, never run against input
		} catch ( \PDOException $e ) {
			$this->last_error = $e->getMessage();
			return false;
		}
	}

	public function insert( $table, $data ) {
		$cols = array_keys( $data );
		$vals = array_map( function ( $v ) {
			return null === $v ? 'NULL' : ( is_int( $v ) ? (string) $v : $this->pdo->quote( (string) $v ) );
		}, array_values( $data ) );
		$ok = $this->query( 'INSERT INTO ' . $table . ' (' . implode( ',', $cols ) . ') VALUES (' . implode( ',', $vals ) . ')' );
		if ( false === $ok ) {
			return false;
		}
		$this->insert_id = (int) $this->pdo->lastInsertId();
		return 1;
	}

	public function update( $table, $data, $where ) {
		$set = array();
		foreach ( $data as $k => $v ) {
			$set[] = $k . ' = ' . ( null === $v ? 'NULL' : $this->pdo->quote( (string) $v ) );
		}
		$w = array();
		foreach ( $where as $k => $v ) {
			$w[] = $k . ' = ' . $this->pdo->quote( (string) $v );
		}
		return $this->query( 'UPDATE ' . $table . ' SET ' . implode( ', ', $set ) . ' WHERE ' . implode( ' AND ', $w ) );
	}

	public function delete( $table, $where ) {
		$w = array();
		foreach ( $where as $k => $v ) {
			$w[] = $k . ' = ' . $this->pdo->quote( (string) $v );
		}
		return $this->query( 'DELETE FROM ' . $table . ' WHERE ' . implode( ' AND ', $w ) );
	}
}

$zagr_includes = ZAGR_DIR . 'includes/';
foreach ( array( 'clock', 'business-days', 'calendar', 'strings', 'words', 'key', 'template', 'pack', 'checklist', 'settings', 'install',
	'events', 'store', 'document', 'people', 'codes', 'mailer', 'alerts', 'paper', 'flow' ) as $zagr_part ) {
	require_once $zagr_includes . 'class-zagr-' . $zagr_part . '.php';
}
unset( $zagr_includes, $zagr_part );
