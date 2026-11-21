<?php
/**
 * Stand-ins for the tests of Prep's internal orders.
 *
 *   ZPrepIo_Sqlite_DB   a $wpdb whose SQL really runs, on an in-memory SQLite database, so a test reads back what the
 *                       class under test wrote (a remake row, an assignment, the cut ledger) through the class's own
 *                       queries. Values come back as strings, as they do from $wpdb.
 *                       Its delta() does dbDelta()'s work: it makes a table from the app's own CREATE TABLE text,
 *                       and widens one that is already there (new columns, new indexes), as an update does. No
 *                       dbDelta() is defined here: other tests count on there being none.
 *   ZPrepIo_Stop        what wp_die() and the JSON replies throw here, so a refusal can be caught and read.
 *
 * And the few WordPress functions these classes call that the shared bootstrap does not define. Each keeps to the
 * suite's globals ($GLOBALS['zdz_test_options'], ['zdz_test_current_user'], ['zdz_test_user_meta'], ...), and each is
 * defined only when no other test file has defined it.
 *
 * @package Zorderz\Tests
 */

defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );
defined( 'OBJECT' ) || define( 'OBJECT', 'OBJECT' );
defined( 'MINUTE_IN_SECONDS' ) || define( 'MINUTE_IN_SECONDS', 60 );
defined( 'HOUR_IN_SECONDS' ) || define( 'HOUR_IN_SECONDS', 3600 );
defined( 'DAY_IN_SECONDS' ) || define( 'DAY_IN_SECONDS', 86400 );
defined( 'ZPREP_APP_ID' ) || define( 'ZPREP_APP_ID', 'prep' );
defined( 'ZPREP_NONCE' ) || define( 'ZPREP_NONCE', 'zprep_nonce' );
defined( 'ZPREP_VERSION' ) || define( 'ZPREP_VERSION', '0.0.0-test' );

if ( ! function_exists( 'update_option' ) ) {
	function update_option( $k, $v, $autoload = null ) { $GLOBALS['zdz_test_options'][ $k ] = $v; return true; }
}
if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( $k ) { unset( $GLOBALS['zdz_test_options'][ $k ] ); return true; }
}
if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id() { return (int) ( $GLOBALS['zdz_test_current_user'] ?? 0 ); }
}
if ( ! function_exists( 'get_user_meta' ) ) {
	function get_user_meta( $uid, $key, $single = false ) { return $GLOBALS['zdz_test_user_meta'][ (int) $uid ][ $key ] ?? ''; }
}
if ( ! function_exists( '_n' ) ) {
	function _n( $one, $many, $n, $d = null ) { return 1 === (int) $n ? $one : $many; }
}
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
}
if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
}
if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
}
if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( $s, $d = null ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
}
if ( ! function_exists( 'esc_html_e' ) ) {
	function esc_html_e( $s, $d = null ) { echo htmlspecialchars( (string) $s, ENT_QUOTES ); }
}
if ( ! function_exists( 'esc_attr_e' ) ) {
	function esc_attr_e( $s, $d = null ) { echo htmlspecialchars( (string) $s, ENT_QUOTES ); }
}
if ( ! function_exists( 'esc_textarea' ) ) {
	function esc_textarea( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
}
if ( ! function_exists( 'selected' ) ) {
	function selected( $a, $b = true, $echo = true ) {
		$out = ( (string) $a === (string) $b ) ? ' selected="selected"' : '';
		if ( $echo ) {
			echo $out;
		}
		return $out;
	}
}
if ( ! function_exists( 'admin_url' ) ) {
	function admin_url( $p = '' ) { return 'https://example.com/wp-admin/' . ltrim( (string) $p, '/' ); }
}
if ( ! function_exists( 'wp_nonce_url' ) ) {
	function wp_nonce_url( $url, $action = -1, $name = '_wpnonce' ) { return $url . ( false === strpos( (string) $url, '?' ) ? '?' : '&' ) . $name . '=test-nonce'; }
}
if ( ! function_exists( 'sanitize_textarea_field' ) ) {
	function sanitize_textarea_field( $s ) { return is_string( $s ) ? trim( strip_tags( $s ) ) : ''; }
}
if ( ! function_exists( 'sanitize_title' ) ) {
	function sanitize_title( $s ) { return trim( preg_replace( '/[^a-z0-9]+/', '-', strtolower( (string) $s ) ), '-' ); }
}
if ( ! function_exists( 'taxonomy_exists' ) ) {
	function taxonomy_exists( $taxonomy ) { return false; }
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
if ( ! function_exists( 'zprep_usage' ) ) {
	/** Prep's usage recorder, kept as a list a test can read. */
	function zprep_usage( string $key, int $ref = 0, array $opts = array() ): void { $GLOBALS['zdz_test_prep_usage'][] = array( $key, $ref ); }
}

if ( ! class_exists( 'ZPrepIo_Stop' ) ) {
	/** A request that ended: wp_die(), or a JSON reply. */
	class ZPrepIo_Stop extends Exception {
		/** @var bool|null true for a success reply, false for an error reply, null for wp_die(). */
		public $ok;
		public $data;
		public $status;
		public function __construct( $ok, $data = null, int $status = 0 ) {
			parent::__construct( is_array( $data ) ? (string) ( $data['message'] ?? '' ) : (string) $data );
			$this->ok     = $ok;
			$this->data   = $data;
			$this->status = $status;
		}
	}
}
if ( ! function_exists( 'wp_die' ) ) {
	function wp_die( $message = '', $title = '', $args = array() ) { throw new ZPrepIo_Stop( null, (string) $message, is_int( $title ) ? $title : 0 ); }
}
if ( ! function_exists( 'wp_send_json_error' ) ) {
	function wp_send_json_error( $data = null, $status = null ) { throw new ZPrepIo_Stop( false, $data, (int) $status ); }
}
if ( ! function_exists( 'wp_send_json_success' ) ) {
	function wp_send_json_success( $data = null, $status = null ) { throw new ZPrepIo_Stop( true, $data, (int) $status ); }
}
if ( ! function_exists( 'check_ajax_referer' ) ) {
	function check_ajax_referer( $action = -1, $arg = false, $die = true ) {
		if ( 'good' !== ( $_POST[ $arg ] ?? '' ) ) {
			throw new ZPrepIo_Stop( false, array( 'message' => 'bad nonce' ), 403 );
		}
		return 1;
	}
}

if ( ! function_exists( 'check_admin_referer' ) ) {
	function check_admin_referer( $action = -1, $arg = '_wpnonce' ) {
		if ( 'test-nonce' !== ( $_REQUEST[ $arg ] ?? '' ) ) {
			throw new ZPrepIo_Stop( null, 'bad nonce', 403 );
		}
		return 1;
	}
}

if ( ! function_exists( 'zprepio_doubles_are_live' ) ) {
	/** Are the request-ending functions above the ones in force? (Another test file may have defined its own first.) */
	function zprepio_doubles_are_live( string ...$functions ): bool {
		foreach ( $functions as $fn ) {
			if ( ! function_exists( $fn ) || ( new ReflectionFunction( $fn ) )->getFileName() !== __FILE__ ) {
				return false;
			}
		}
		return true;
	}
}

if ( ! class_exists( 'ZPrepIo_Sqlite_DB' ) ) {
	class ZPrepIo_Sqlite_DB {
		public $prefix     = 'wp_';
		public $options    = 'wp_options';
		public $usermeta   = 'wp_usermeta';
		public $last_error = '';
		public $insert_id  = 0;
		/** @var string[] every statement run, in order. */
		public $log = array();
		/** @var int how many times delta() was asked to make or widen a table. */
		public $deltas = 0;
		/** @var PDO */
		private $pdo;

		public function __construct() {
			$this->pdo = new PDO( 'sqlite::memory:' );
			$this->pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
			$this->pdo->setAttribute( PDO::ATTR_STRINGIFY_FETCHES, true );
		}

		public function prepare( $sql, ...$args ) {
			if ( 1 === count( $args ) && is_array( $args[0] ) ) {
				$args = $args[0];
			}
			$i = 0;
			return preg_replace_callback( '/%[dsf]/', function ( $m ) use ( &$i, $args ) {
				$v = $args[ $i++ ] ?? '';
				if ( '%s' === $m[0] ) {
					return $this->pdo->quote( (string) $v );
				}
				return '%d' === $m[0] ? (string) (int) $v : (string) (float) $v;
			}, $sql );
		}
		public function esc_like( $s ) { return addcslashes( (string) $s, '_%\\' ); }
		public function get_charset_collate() { return ''; }

		/** @return PDOStatement|null null when the statement failed (last_error says why), as $wpdb reports it. */
		private function run( string $sql ) {
			$this->log[]      = $sql;
			$this->last_error = '';
			// The one MySQL-only statement the classes under test use: "is this table there?".
			if ( preg_match( "/^\s*SHOW TABLES LIKE\s+('(?:[^']|'')*')\s*$/i", $sql, $m ) ) {
				$sql = "SELECT name FROM sqlite_master WHERE type = 'table' AND name = {$m[1]}";
			}
			try {
				return $this->pdo->query( $sql );
			} catch ( PDOException $e ) {
				$this->last_error = $e->getMessage();
				return null;
			}
		}
		private static function shaped( array $row, $output ) {
			return ARRAY_A === $output ? $row : (object) $row;
		}

		public function get_var( $sql ) {
			$st  = $this->run( (string) $sql );
			$row = $st ? $st->fetch( PDO::FETCH_NUM ) : false;
			return $row ? $row[0] : null;
		}
		public function get_col( $sql ) {
			$st = $this->run( (string) $sql );
			return $st ? $st->fetchAll( PDO::FETCH_COLUMN, 0 ) : array();
		}
		public function get_row( $sql, $output = OBJECT ) {
			$st  = $this->run( (string) $sql );
			$row = $st ? $st->fetch( PDO::FETCH_ASSOC ) : false;
			return $row ? self::shaped( $row, $output ) : null;
		}
		public function get_results( $sql, $output = OBJECT ) {
			$st  = $this->run( (string) $sql );
			$out = array();
			foreach ( $st ? $st->fetchAll( PDO::FETCH_ASSOC ) : array() as $row ) {
				$out[] = self::shaped( $row, $output );
			}
			return $out;
		}
		public function query( $sql ) {
			$st = $this->run( (string) $sql );
			return $st ? $st->rowCount() : false;
		}

		private function lit( $v ): string {
			if ( null === $v ) {
				return 'NULL';
			}
			if ( is_bool( $v ) ) {
				return $v ? '1' : '0';
			}
			return ( is_int( $v ) || is_float( $v ) ) ? (string) $v : $this->pdo->quote( (string) $v );
		}
		private function pairs( array $data, string $glue ): string {
			$out = array();
			foreach ( $data as $k => $v ) {
				$out[] = '`' . $k . '` ' . ( ( null === $v && ' AND ' === $glue ) ? 'IS NULL' : '= ' . $this->lit( $v ) );
			}
			return implode( $glue, $out );
		}

		public function insert( $table, $data, $format = null ) {
			$cols = implode( ', ', array_map( static function ( $c ) { return '`' . $c . '`'; }, array_keys( $data ) ) );
			$st   = $this->run( "INSERT INTO `{$table}` ({$cols}) VALUES (" . implode( ', ', array_map( array( $this, 'lit' ), array_values( $data ) ) ) . ')' );
			if ( ! $st ) {
				return false;
			}
			$this->insert_id = (int) $this->pdo->lastInsertId();
			return 1;
		}
		public function update( $table, $data, $where, $format = null, $where_format = null ) {
			$st = $this->run( "UPDATE `{$table}` SET " . $this->pairs( $data, ', ' ) . ' WHERE ' . $this->pairs( $where, ' AND ' ) );
			return $st ? $st->rowCount() : false;
		}
		public function delete( $table, $where, $where_format = null ) {
			$st = $this->run( "DELETE FROM `{$table}` WHERE " . $this->pairs( $where, ' AND ' ) );
			return $st ? $st->rowCount() : false;
		}

		/** Run a statement of the test's own (a table as it was before an update, a row with a chosen date). */
		public function statement( string $sql ): void {
			$this->pdo->query( $sql );
		}

		/** The columns a table has now, in order. */
		public function columns( string $table ): array {
			return array_column( $this->pdo->query( "PRAGMA table_info(`{$table}`)" )->fetchAll( PDO::FETCH_ASSOC ), 'name' );
		}
		/** The names of a table's indexes, as the app named them. */
		public function indexes( string $table ): array {
			$out = array();
			foreach ( $this->pdo->query( "PRAGMA index_list(`{$table}`)" )->fetchAll( PDO::FETCH_ASSOC ) as $ix ) {
				if ( 0 === strpos( (string) $ix['name'], $table . '__' ) ) {
					$out[] = substr( (string) $ix['name'], strlen( $table ) + 2 );
				}
			}
			sort( $out );
			return $out;
		}

		/**
		 * dbDelta()'s work for one CREATE TABLE statement: make the table, or add what an existing one lacks.
		 * Every column keeps its DEFAULT, so a row that was there before a column was added reads the default.
		 */
		public function delta( string $sql ): void {
			++$this->deltas;
			if ( ! preg_match( '/CREATE TABLE\s+(\S+)\s*\((.*)\)[^)]*$/s', $sql, $m ) ) {
				return;
			}
			$table   = $m[1];
			$columns = array();
			$indexes = array();
			foreach ( preg_split( '/,\s*\n/', trim( $m[2] ) ) as $line ) {
				$line = trim( $line );
				if ( '' === $line || 0 === stripos( $line, 'PRIMARY KEY' ) ) {
					continue;
				}
				if ( preg_match( '/^(UNIQUE\s+)?KEY\s+(\w+)\s*\(([^)]*)\)/i', $line, $k ) ) {
					$indexes[ $k[2] ] = array( '' !== $k[1], preg_replace( '/\(\d+\)/', '', $k[3] ) );
					continue;
				}
				if ( ! preg_match( '/^(\w+)\s+(.*)$/s', $line, $c ) ) {
					continue;
				}
				$spec = $c[2];
				if ( false !== stripos( $spec, 'AUTO_INCREMENT' ) ) {
					$columns[ $c[1] ] = 'INTEGER PRIMARY KEY AUTOINCREMENT';
					continue;
				}
				$type = preg_match( '/INT/i', $spec ) ? 'INTEGER' : ( preg_match( '/DECIMAL|FLOAT|DOUBLE/i', $spec ) ? 'REAL' : 'TEXT' );
				$def  = preg_match( "/DEFAULT\s+('(?:[^']|'')*'|-?[0-9.]+|NULL)/i", $spec, $d ) ? ' DEFAULT ' . $d[1] : '';
				$columns[ $c[1] ] = $type . $def;
			}
			$have = $this->columns( $table );
			if ( ! $have ) {
				$defs = array();
				foreach ( $columns as $name => $spec ) {
					$defs[] = "`{$name}` {$spec}";
				}
				$this->pdo->query( "CREATE TABLE `{$table}` (" . implode( ', ', $defs ) . ')' );
			} else {
				foreach ( $columns as $name => $spec ) {
					if ( ! in_array( $name, $have, true ) ) {
						$this->pdo->query( "ALTER TABLE `{$table}` ADD COLUMN `{$name}` {$spec}" );
					}
				}
			}
			foreach ( $indexes as $name => $ix ) {
				$this->pdo->query( 'CREATE ' . ( $ix[0] ? 'UNIQUE ' : '' ) . "INDEX IF NOT EXISTS `{$table}__{$name}` ON `{$table}` ({$ix[1]})" );
			}
		}
	}
}

$zprepio_root = dirname( __DIR__, 3 );
require_once $zprepio_root . '/zorderz/inc/class-zdz-user-roles.php';
require_once $zprepio_root . '/zorderz/inc/class-zdz-privacy-view.php';
require_once $zprepio_root . '/zorderz/inc/class-zdz-plugin-api.php';
require_once $zprepio_root . '/zorderz/inc/class-zdz-item-engine.php';
require_once $zprepio_root . '/zorderz/inc/interface-zdz-app.php';
foreach ( array( 'settings', 'nesting', 'engine', 'leftovers', 'cut-state', 'assignments', 'remakes', 'crm', 'dashboard', 'queue-counts', 'upcoming', 'pack', 'admin', 'internal-report', 'app' ) as $zprepio_file ) {
	require_once $zprepio_root . '/zorderz-apps/apps/prep/includes/class-zprep-' . $zprepio_file . '.php';
}
unset( $zprepio_root, $zprepio_file );

if ( ! class_exists( 'ZPrepIo_Case' ) ) {
	/**
	 * A shop with its tables made and six accounts, for the tests of internal orders:
	 *
	 *   1 Alex    an administrator                      (a manager)
	 *   2 Jordan  has Prep                              (a team member)
	 *   3 Sam     has Prep                              (a team member)
	 *   4 Riley   does not have Prep                    (sees Prep only while a job is assigned to them)
	 *   5         the shared device, with Prep allowed  (never a person who is given work)
	 *   6 Casey   the platform's administrator role, without the WordPress capability  (a manager by role)
	 *
	 * Rolls: grey in two widths, sand in one. Nothing else is configured: one purpose, no sheet size, no cost.
	 */
	abstract class ZPrepIo_Case extends PHPUnit\Framework\TestCase {

		const ALEX   = 1;
		const JORDAN = 2;
		const SAM    = 3;
		const RILEY  = 4;
		const DEVICE = 5;
		const CASEY  = 6;

		/** @var ZPrepIo_Sqlite_DB */
		protected $db;
		/** @var array what the globals held before this test. */
		private $kept = array();

		const GLOBALS_USED = array( 'wpdb', 'zdz_test_options', 'zdz_test_user_roles', 'zdz_test_user_names', 'zdz_test_user_caps', 'zdz_test_user_meta', 'zdz_test_current_user', 'zdz_test_filters', 'zdz_test_scheduled', 'zdz_test_prep_usage', 'zdz_test_transients' );

		protected function setUp(): void {
			foreach ( self::GLOBALS_USED as $g ) {
				$this->kept[ $g ] = array_key_exists( $g, $GLOBALS ) ? $GLOBALS[ $g ] : null;
			}
			$this->db        = new ZPrepIo_Sqlite_DB();
			$GLOBALS['wpdb'] = $this->db;

			$GLOBALS['zdz_test_options'] = array(
				'zprep_settings' => array(
					'rolls' => array(
						array( 'width_in' => 36, 'color' => 'grey', 'available' => true ),
						array( 'width_in' => 60, 'color' => 'grey', 'available' => true ),
						array( 'width_in' => 36, 'color' => 'sand', 'available' => true ),
					),
				),
			);
			$GLOBALS['zdz_test_user_roles'] = array(
				self::ALEX   => array( 'administrator' ),
				self::JORDAN => array( 'zdz_tech' ),
				self::SAM    => array( 'zdz_tech' ),
				self::RILEY  => array( 'zdz_tech' ),
				self::DEVICE => array( ZDZ_User_Roles::SHARED_DEVICE_ROLE ),
				self::CASEY  => array( 'zdz_admin' ),
			);
			$GLOBALS['zdz_test_user_names'] = array(
				self::ALEX   => 'Alex',
				self::JORDAN => 'Jordan',
				self::SAM    => 'Sam',
				self::RILEY  => 'Riley',
				self::DEVICE => 'Shop tablet',
				self::CASEY  => 'Casey',
			);
			$GLOBALS['zdz_test_user_caps'] = array();
			$GLOBALS['zdz_test_user_meta'] = array(
				self::JORDAN => array( 'zdz_allowed_apps' => array( ZPREP_APP_ID ) ),
				self::SAM    => array( 'zdz_allowed_apps' => array( ZPREP_APP_ID ) ),
				self::RILEY  => array( 'zdz_allowed_apps' => array() ),
				self::DEVICE => array( 'zdz_allowed_apps' => array( ZPREP_APP_ID ) ),
			);
			$GLOBALS['zdz_test_current_user'] = 0;
			// A person holding a live assignment is let into Prep by this filter, as on a real site.
			$GLOBALS['zdz_test_filters']    = array( 'zdz_extra_allowed_apps' => array( 'ZPREP_Assignments', 'filter_extra_allowed_apps' ) );
			$GLOBALS['zdz_test_scheduled']  = array();
			$GLOBALS['zdz_test_prep_usage'] = array();
			$GLOBALS['zdz_test_transients'] = array();
			$_POST                          = array();
			$_GET                           = array();
			$_REQUEST                       = array();

			ZPREP_Assignments::flush_memo();
			ZDZ_Privacy_View::flush();
			ZDZ_Item_Engine::flush();

			// The tables, from each class's own definition. Prep's upgrade runs through the database above.
			ZPREP_Remakes::$delta        = array( $this->db, 'delta' );
			ZPREP_Queue_Counts::$schedule = static function ( int $uid ): void {
				$GLOBALS['zdz_test_scheduled'][] = $uid;
			};
			ZPREP_Remakes::migrate();
			$this->db->delta( ZPREP_Assignments::schema_sql() );
			$this->db->delta( ZPREP_Cut_State::schema_sql() );
		}

		protected function tearDown(): void {
			foreach ( $this->kept as $g => $was ) {
				if ( null === $was ) {
					unset( $GLOBALS[ $g ] );
				} else {
					$GLOBALS[ $g ] = $was;
				}
			}
			$_POST    = array();
			$_GET     = array();
			$_REQUEST = array();
			ZPREP_Remakes::$delta         = null;
			ZPREP_Queue_Counts::$schedule = null;
			ZPREP_Assignments::flush_memo();
			ZDZ_Privacy_View::flush();
			ZDZ_Item_Engine::flush();
		}

		/** One row of an order's form. */
		protected static function piece( int $qty, float $w, float $h, $sell = '', string $kind = 'custom' ): array {
			return array( 'kind' => $kind, 'qty' => $qty, 'width' => $w, 'height' => $h, 'color' => '', 'notes' => '', 'sell_each' => $sell );
		}

		/** What the form posts for an internal order cut from 24 x 32 sheets, with anything in $over put over it. */
		protected static function order( array $over = array() ): array {
			return array_merge( array(
				'kind'           => 'internal',
				'customer_label' => 'Jig covers',
				'purpose_code'   => 'general',
				'for_whom'       => 'the bench crew',
				'reason_text'    => 'to keep dust off the jigs',
				'color'          => 'grey',
				'stock'          => 'sheet',
				'sheet_w'        => '24',
				'sheet_l'        => '32',
				'force_roll'     => 0,
				'pieces'         => array( self::piece( 6, 20, 15 ) ),
			), $over );
		}

		/** Save an order as Alex and hand back its row; the test fails here when it is refused. */
		protected function saved( array $over = array() ): array {
			$row = ZPREP_Remakes::create( self::order( $over ), self::ALEX );
			$this->assertIsArray( $row, is_wp_error( $row ) ? $row->get_error_message() : 'not a row' );
			return $row;
		}

		/** Three purposes of the business's own, in place of the one Core ships. */
		protected function three_purposes(): void {
			$GLOBALS['zdz_test_options'][ ZPREP_Settings::INTERNAL_OPT ] = array(
				'purposes' => array(
					array( 'key' => 'fixtures', 'label' => 'Shop fixtures', 'code' => 'FIX' ),
					array( 'key' => 'testing', 'label' => 'Material testing', 'code' => 'TST' ),
					array( 'key' => 'donated', 'label' => 'Donated work', 'code' => 'DON' ),
				),
			);
		}

		/**
		 * Put items in the catalog (the Item Engine's own table, read by the Item Engine's own code).
		 *
		 * @param array<string,array{0:string,1:string,2:array,3?:int}> $items id => [ subtype, display name, attributes, active ].
		 */
		protected function catalog( array $items ): void {
			$table = $this->db->prefix . ZDZ_Item_Engine::ITEMS_TABLE;
			$this->db->statement( "CREATE TABLE `{$table}` ( id TEXT, type TEXT, subtype TEXT, display_name TEXT, aliases TEXT, negative_aliases TEXT, attributes TEXT, sellable TEXT, consumes TEXT, pricing_scheme_id TEXT, rules TEXT, external_refs TEXT, unit_noun_singular TEXT, unit_noun_plural TEXT, parent_item_id TEXT, match_priority INTEGER, countable INTEGER, active INTEGER, sort_order INTEGER )" );
			$n = 0;
			foreach ( $items as $id => $it ) {
				$this->db->insert( $table, array(
					'id' => $id, 'type' => 'product', 'subtype' => $it[0], 'display_name' => $it[1], 'aliases' => '[]', 'negative_aliases' => '[]',
					'attributes' => json_encode( (object) $it[2] ), 'sellable' => 'standalone', 'consumes' => '{}', 'pricing_scheme_id' => '', 'rules' => '[]',
					'external_refs' => '{}', 'unit_noun_singular' => '', 'unit_noun_plural' => '', 'parent_item_id' => '', 'match_priority' => 0,
					'countable' => 1, 'active' => $it[3] ?? 1, 'sort_order' => ++$n,
				) );
			}
			ZDZ_Item_Engine::flush();
		}

		protected function assign( int $assignee, array $row ): void {
			$a = ZPREP_Assignments::create( self::ALEX, $assignee, ZPREP_Remakes::job_key( (int) $row['id'] ), (string) $row['customer_label'], 'internal' );
			$this->assertIsArray( $a, is_wp_error( $a ) ? $a->get_error_message() : 'not an assignment' );
		}
	}
}
