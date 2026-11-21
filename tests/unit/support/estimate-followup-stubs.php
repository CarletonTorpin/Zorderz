<?php
/**
 * Shared stand-ins for the Estimates follow-up and Privacy View tests: the app's classes those tests need on top of
 * estimate-stubs.php, the platform's Privacy View and document conventions as they ship, a $wpdb stand-in over an
 * in-memory SQLite database (ZDZ_Test_EstFu_DB) holding the estimates, follow-ups and change-history tables, a
 * billing transport, a CRM client and a stand-in for the platform's coder. Every double carries the ZDZ_Test_EstFu_
 * prefix, because the whole suite runs in one PHP process.
 *
 * @package Zorderz\Tests
 */

require_once __DIR__ . '/estimate-stubs.php';

if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( $url, $protocols = null ) {
		$url = trim( (string) $url );
		$ok  = is_array( $protocols ) && $protocols ? $protocols : array( 'http', 'https' );
		return preg_match( '#^(' . implode( '|', array_map( 'preg_quote', $ok ) ) . ')://[^\s<>"\']+$#i', $url ) ? $url : '';
	}
}

$zdz_estfu_root = dirname( __DIR__, 3 );
require_once $zdz_estfu_root . '/zorderz/inc/class-zdz-doc-conventions.php';
require_once $zdz_estfu_root . '/zorderz/inc/class-zdz-privacy-view.php';
// Not the dashboard class: other suites read "is the estimate app's dashboard here?" to decide who sees prices.
foreach ( array( 'nutshell', 'followup', 'followup-ajax', 'privacy-view', 'settings', 'pack', 'lists' ) as $zdz_estfu_f ) {
	require_once $zdz_estfu_root . '/zorderz-apps/apps/estimate/includes/class-zest-' . $zdz_estfu_f . '.php';
}
unset( $zdz_estfu_root, $zdz_estfu_f );

if ( ! class_exists( 'ZDZ_Test_EstFu_DB' ) ) {
	/**
	 * A $wpdb stand-in over an in-memory SQLite database: prepare() with %d / %s / %f, get_var, get_row, get_results,
	 * get_col, query (rows changed), insert, update, suppress_errors. Named locks are kept in an array (`busy` names
	 * a lock somebody else holds); "SHOW TABLES LIKE" answers from `missing`.
	 */
	final class ZDZ_Test_EstFu_DB {
		public $prefix     = 'wp_';
		public $insert_id  = 0;
		public $last_error = '';
		/** @var string[] lock names held by "someone else" */
		public $busy = array();
		/** @var string[] table names that are "not there yet" */
		public $missing = array();
		/** @var string[] every statement run, in order */
		public $log = array();
		private $pdo;

		public function __construct() {
			$this->pdo = new PDO( 'sqlite::memory:' );
			$this->pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
			$this->pdo->sqliteCreateFunction( 'CONCAT', static function ( ...$parts ) {
				return implode( '', array_map( 'strval', $parts ) );
			} );
			$this->make( 'CREATE TABLE wp_zest_estimates ( id INTEGER PRIMARY KEY AUTOINCREMENT, customer_name TEXT DEFAULT \'\', customer_org TEXT DEFAULT \'\', customer_email TEXT DEFAULT \'\','
				. ' customer_phone TEXT DEFAULT \'\', customer_street TEXT DEFAULT \'\', customer_city TEXT DEFAULT \'\', customer_state TEXT DEFAULT \'\', customer_zip TEXT DEFAULT \'\','
				. ' salesperson TEXT DEFAULT \'\', item_count INTEGER DEFAULT 0, rejected_count INTEGER DEFAULT 0, billing_doc_id TEXT DEFAULT \'\', billing_doc_num TEXT DEFAULT \'\','
				. ' billing_cust_id TEXT DEFAULT \'\', crm_lead_id TEXT DEFAULT \'\', crm_contact_id TEXT DEFAULT \'\', items_json TEXT DEFAULT \'\', rejected_json TEXT DEFAULT \'\', notes TEXT DEFAULT \'\','
				. ' status TEXT DEFAULT \'created\', doc_type TEXT DEFAULT \'estimate\', created_by INTEGER DEFAULT 0, created_at TEXT DEFAULT \'2026-01-05 10:00:00\', sent_at TEXT, accepted_at TEXT,'
				. ' approved_at TEXT, approved_by INTEGER DEFAULT 0, approved_fp TEXT DEFAULT \'\', seal_hold TEXT DEFAULT \'\' )' );
			$this->make( 'CREATE TABLE wp_zest_followups ( id INTEGER PRIMARY KEY AUTOINCREMENT, estimate_id INTEGER DEFAULT 0, billing_doc_id TEXT DEFAULT \'\', billing_doc_num TEXT DEFAULT \'\','
				. ' is_test INTEGER DEFAULT 0, state TEXT DEFAULT \'open\', sent_by INTEGER DEFAULT 0, sent_to INTEGER DEFAULT 0, sent_at TEXT DEFAULT \'\', ask_note TEXT, tries INTEGER DEFAULT 0,'
				. ' answer TEXT DEFAULT \'\', outcome TEXT DEFAULT \'\', channel TEXT DEFAULT \'\', answer_note TEXT, try_notes TEXT, declined INTEGER DEFAULT 0, answered_by INTEGER DEFAULT 0,'
				. ' answered_at TEXT, reopened_by INTEGER DEFAULT 0, reopened_at TEXT, doc_line TEXT DEFAULT \'\', doc_state TEXT DEFAULT \'\', doc_why TEXT DEFAULT \'\', doc_prev TEXT DEFAULT \'\','
				. ' doc_on TEXT, undo_log TEXT, undone INTEGER DEFAULT 0, crm_state TEXT DEFAULT \'\', crm_queue TEXT, crm_tries INTEGER DEFAULT 0, contact_id INTEGER DEFAULT 0 )' );
			$this->make( 'CREATE TABLE wp_zest_line_changes ( id INTEGER PRIMARY KEY AUTOINCREMENT, batch TEXT, created_at TEXT, user_id INTEGER, initials TEXT, doc_kind TEXT, billing_id TEXT,'
				. ' doc_number TEXT, line_no INTEGER, change_type TEXT, field TEXT, slot TEXT, option_id TEXT, before_text TEXT, after_text TEXT, before_amount REAL, after_amount REAL, reason TEXT,'
				. ' verified INTEGER DEFAULT 0, state TEXT DEFAULT \'pending\' )' );
		}

		private function make( string $sql ): void {
			$this->pdo->query( $sql );
		}

		/** The columns of one of the tables above. */
		public function columns( string $table ): array {
			$out = array();
			foreach ( $this->pdo->query( 'PRAGMA table_info(' . preg_replace( '/[^a-z_]/', '', $table ) . ')' )->fetchAll( PDO::FETCH_ASSOC ) as $c ) {
				$out[] = (string) $c['name'];
			}
			return $out;
		}

		public function suppress_errors( $on = true ) {
			return false;
		}

		public function get_charset_collate() {
			return '';
		}

		public function esc_like( $s ) {
			return addcslashes( (string) $s, '_%\\' );
		}

		public function prepare( $sql, ...$args ) {
			if ( 1 === count( $args ) && is_array( $args[0] ) ) {
				$args = $args[0];
			}
			$pdo = $this->pdo;
			$i   = 0;
			return preg_replace_callback( '/%[dsfF]/', static function ( $m ) use ( &$i, $args, $pdo ) {
				$v = $args[ $i++ ] ?? null;
				if ( '%d' === $m[0] ) {
					return (string) (int) $v;
				}
				if ( '%s' !== $m[0] ) {
					return (string) (float) $v;
				}
				return $pdo->quote( (string) $v );
			}, $sql );
		}

		/** A statement the database itself does not know: a named lock, or "is this table there". */
		private function special( string $sql ) {
			if ( preg_match( "/^SELECT GET_LOCK\('([^']*)'/", $sql, $m ) ) {
				return in_array( $m[1], $this->busy, true ) ? '0' : '1';
			}
			if ( preg_match( "/^SELECT RELEASE_LOCK\('/", $sql ) ) {
				return '1';
			}
			if ( preg_match( "/^SHOW TABLES LIKE '([^']*)'/", $sql, $m ) ) {
				return in_array( $m[1], $this->missing, true ) ? null : $m[1];
			}
			return false;
		}

		private function run( string $sql ) {
			$this->log[]      = $sql;
			$this->last_error = '';
			foreach ( $this->missing as $t ) {
				if ( false !== strpos( $sql, $t ) ) {
					$this->last_error = 'no such table';
					return null;
				}
			}
			try {
				return $this->pdo->query( $sql );
			} catch ( \PDOException $e ) {
				$this->last_error = $e->getMessage();
				return null;
			}
		}

		public function get_var( $sql ) {
			$s = $this->special( (string) $sql );
			if ( false !== $s ) {
				return $s;
			}
			$st = $this->run( (string) $sql );
			$v  = $st ? $st->fetchColumn() : false;
			return false === $v ? null : $v;
		}

		public function get_row( $sql, $out = null ) {
			$st = $this->run( (string) $sql );
			$r  = $st ? $st->fetch( PDO::FETCH_ASSOC ) : false;
			return false === $r ? null : $r;
		}

		public function get_results( $sql, $out = null ) {
			$st = $this->run( (string) $sql );
			return $st ? $st->fetchAll( PDO::FETCH_ASSOC ) : array();
		}

		public function get_col( $sql ) {
			$st = $this->run( (string) $sql );
			return $st ? $st->fetchAll( PDO::FETCH_COLUMN, 0 ) : array();
		}

		public function query( $sql ) {
			if ( false !== $this->special( (string) $sql ) ) {
				return 1;
			}
			$st = $this->run( (string) $sql );
			return $st ? $st->rowCount() : false;
		}

		private function value( $v ): string {
			return null === $v ? 'NULL' : ( is_int( $v ) || is_bool( $v ) ? (string) (int) $v : $this->pdo->quote( (string) $v ) );
		}

		public function insert( $table, $data, $fmt = null ) {
			$st = $this->run( 'INSERT INTO ' . $table . ' (' . implode( ',', array_keys( $data ) ) . ') VALUES (' . implode( ',', array_map( array( $this, 'value' ), array_values( $data ) ) ) . ')' );
			if ( ! $st ) {
				return false;
			}
			$this->insert_id = (int) $this->pdo->lastInsertId();
			return 1;
		}

		public function update( $table, $data, $where, $fmt = null, $wfmt = null ) {
			$set = array();
			foreach ( $data as $k => $v ) {
				$set[] = $k . ' = ' . $this->value( $v );
			}
			$cond = array();
			foreach ( $where as $k => $v ) {
				$cond[] = $k . ' = ' . $this->value( $v );
			}
			$st = $this->run( 'UPDATE ' . $table . ' SET ' . implode( ', ', $set ) . ' WHERE ' . implode( ' AND ', $cond ) );
			return $st ? $st->rowCount() : false;
		}

		/** One estimate row for a test: neutral values, anything given laid over them. Returns its id. */
		public function estimate( array $over = array() ): int {
			$this->insert( 'wp_zest_estimates', $over + array(
				'customer_name' => 'Morgan Example', 'salesperson' => 'AA', 'billing_doc_num' => '1001', 'billing_doc_id' => '501', 'billing_cust_id' => '77',
				'items_json'    => '[{"description":"Widget","sub_description":"","unit_price":100,"quantity":2}]', 'item_count' => 1, 'rejected_json' => '[]', 'notes' => '',
				'status'        => 'sent', 'doc_type' => 'estimate', 'created_by' => 0,
			) );
			return $this->insert_id;
		}

		public function row( string $table, int $id ): ?array {
			return $this->get_row( 'SELECT * FROM ' . $table . ' WHERE id = ' . $id );
		}
	}
}

if ( ! class_exists( 'ZDZ_Test_EstFu_Billing' ) ) {
	/**
	 * The billing system, for ZEST_Billing_Doc::$transport: one estimate by id with its lines and notes, its customer,
	 * a search by number and the share-link request. Every call is kept in `calls`.
	 */
	final class ZDZ_Test_EstFu_Billing {
		public $docs    = array();   // id => estimate
		public $clients = array();   // id => client
		public $share   = array();   // id => what the share-link request answers (a string, or null for "no answer")
		public $calls   = array();
		public $down    = false;     // every call answers nothing
		public $refuse_put = false;

		public function __invoke( $method, $path, $body = array() ) {
			$this->calls[] = $method . ' ' . $path;
			if ( $this->down ) {
				return null;
			}
			if ( preg_match( '#/users/clients/(\d+)$#', $path, $m ) ) {
				return isset( $this->clients[ $m[1] ] ) ? array( 'response' => array( 'result' => array( 'client' => $this->clients[ $m[1] ] ) ) ) : array( 'response' => array( 'result' => array() ) );
			}
			if ( preg_match( '#/estimates/estimates/(\d+)/share_link#', $path, $m ) ) {
				return array_key_exists( $m[1], $this->share ) && null !== $this->share[ $m[1] ] ? array( 'response' => array( 'result' => array( 'share_link' => array( 'share_link' => $this->share[ $m[1] ] ) ) ) ) : null;
			}
			if ( preg_match( '#/estimates/estimates\?.*search\[estimate_number\]=(\d+)#', $path, $m ) ) {
				$found = array();
				foreach ( $this->docs as $d ) {
					if ( ltrim( (string) $d['estimate_number'], '0' ) === $m[1] ) {
						$found[] = $d;
					}
				}
				return array( 'response' => array( 'result' => array( 'estimates' => $found ) ) );
			}
			if ( preg_match( '#/estimates/estimates/(\d+)#', $path, $m ) ) {
				if ( ! isset( $this->docs[ $m[1] ] ) ) {
					return array( 'response' => array( 'result' => array() ) );
				}
				if ( 'PUT' === $method ) {
					if ( $this->refuse_put ) {
						return null;
					}
					foreach ( (array) ( $body['estimate'] ?? array() ) as $k => $v ) {
						$this->docs[ $m[1] ][ $k ] = $v;
					}
				}
				return array( 'response' => array( 'result' => array( 'estimate' => $this->docs[ $m[1] ] ) ) );
			}
			return null;
		}

		/** One open estimate of customer 77 with a line and no notes. */
		public static function doc( array $over = array() ): array {
			return $over + array(
				'id' => 501, 'estimate_number' => '1001', 'customerid' => 77, 'vis_state' => 0, 'status' => 1, 'create_date' => '2026-01-05', 'notes' => '',
				'fname' => 'Morgan', 'lname' => 'Example', 'organization' => '',
				'lines' => array( array( 'name' => 'Widget', 'description' => '', 'qty' => '2', 'unit_cost' => array( 'amount' => '100.00', 'code' => 'USD' ), 'type' => 0 ) ),
			);
		}
	}
}

if ( ! class_exists( 'ZDZ_Test_EstFu_Crm' ) ) {
	/** The CRM client, for ZEST_Nutshell::$test_client: one lead by id, and the notes added to it. */
	final class ZDZ_Test_EstFu_Crm {
		public $leads = array();   // id => lead (null = cannot be read)
		public $notes = array();   // [ lead id, text ]
		public $fail  = 0;         // this many add_note calls fail first
		public function is_configured() { return true; }
		public function read_lead( $id ) {
			if ( ! array_key_exists( (int) $id, $this->leads ) ) {
				return null;
			}
			return $this->leads[ (int) $id ];
		}
		public function add_note( $args ) {
			if ( $this->fail > 0 ) {
				$this->fail--;
				return false;
			}
			$this->notes[] = array( (int) ( $args['entity']['id'] ?? 0 ), (string) ( $args['note'] ?? '' ) );
			return array( 'id' => count( $this->notes ) );
		}
	}
}

if ( ! class_exists( 'ZDZ_Test_EstFu_Coder' ) ) {
	/** A stand-in for the platform's coder: it knows one customer's name. */
	final class ZDZ_Test_EstFu_Coder {
		public $names = array( 1 => 'Morgan Example' );
		private function sub( $t ) {
			foreach ( $this->names as $id => $n ) {
				$t = preg_replace( '/(?<![\p{L}])' . preg_quote( $n, '/' ) . '(?![\p{L}])/iu', '⟦c:' . $id . '⟧', $t );
			}
			return $t;
		}
		public function encode( $t ) { return $this->sub( (string) $t ); }
		public function names_only( $t ) { return $this->sub( (string) $t ); }
		public function open( $c ) { return null; }
		public function ledger_name( $id ) { return $this->names[ (int) $id ] ?? null; }
		public function index() {
			$l = array();
			foreach ( $this->names as $id => $n ) {
				$l[ ZDZ_Privacy_View::norm( $n ) ] = $id;
			}
			return array( 'ledger' => $l, 'other' => array() );
		}
	}
}

if ( ! function_exists( 'zdz_test_estfu_people' ) ) {
	/**
	 * The team for a test: id => [ role, code ]. Sets the suite's shared globals (roles, the short code, the user
	 * list), signs nobody in and gives the test a fresh database, which it returns.
	 */
	function zdz_test_estfu_people( array $people ): ZDZ_Test_EstFu_DB {
		$GLOBALS['zdz_test_user_roles'] = array();
		$GLOBALS['zdz_test_user_meta']  = array();
		$GLOBALS['zdz_test_users']      = array();
		foreach ( $people as $id => $p ) {
			$GLOBALS['zdz_test_user_roles'][ $id ] = array( $p[0] );
			$GLOBALS['zdz_test_user_meta'][ $id ]  = array( 'zdz_user_initials' => $p[1] );
			$GLOBALS['zdz_test_users'][]           = (object) array( 'ID' => $id, 'roles' => array( $p[0] ), 'display_name' => 'Person ' . $id );
		}
		$GLOBALS['zdz_test_options']      = array();
		$GLOBALS['zdz_test_filters']      = array();
		$GLOBALS['zdz_test_current_user'] = 0;
		$GLOBALS['wpdb']                  = new ZDZ_Test_EstFu_DB();
		zdz_test_estfu_flush();
		return $GLOBALS['wpdb'];
	}

	/** Every static cache these tests touch, forgotten. */
	function zdz_test_estfu_flush(): void {
		ZEST_Salesperson::flush();
		ZEST_Followup::reset();
		ZDZ_Doc_Conventions::flush();
		ZDZ_Privacy_View::flush();
		ZEST_Billing_Doc::$transport      = null;
		ZEST_Billing_Doc::$account        = null;
		ZEST_Nutshell::$test_client       = null;
		ZEST_Followup_Ajax::$notifier     = null;
	}

	function zdz_test_estfu_teardown(): void {
		unset( $GLOBALS['zdz_test_user_roles'], $GLOBALS['zdz_test_user_meta'], $GLOBALS['zdz_test_users'], $GLOBALS['zdz_test_options'],
			$GLOBALS['zdz_test_filters'], $GLOBALS['zdz_test_current_user'], $GLOBALS['zdz_test_user_caps'], $GLOBALS['wpdb'] );
		$_POST    = array();
		$_REQUEST = array();
		zdz_test_estfu_flush();
	}
}
