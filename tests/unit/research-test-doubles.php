<?php
/**
 * Test doubles for the deep research suites: the classes a run touches, one in-memory database for the run's tables
 * and the Ai ledger's, and a fixture that sets up a site where research can run (the feature on, an Ai budget, a
 * model, a workspace, an administrator who may run it) with a scripted Ai and a clock the test moves.
 *
 * Neutral data only. Not a test file itself (no *Test.php suffix).
 *
 * @package Zorderz\Tests
 */

require_once __DIR__ . '/support/privacy-stubs.php';
require_once __DIR__ . '/llm-test-doubles.php';
require_once __DIR__ . '/step-runner-test-doubles.php';

foreach ( array( 'ZANA_APP_ID' => 'sales-analytics', 'ZANA_NONCE' => 'zana_nonce', 'ZDZ_REST_NS' => 'zorderz/v1' ) as $zrs_k => $zrs_v ) {
	if ( ! defined( $zrs_k ) ) {
		define( $zrs_k, $zrs_v );
	}
}
unset( $zrs_k, $zrs_v );
if ( ! function_exists( 'update_user_meta' ) ) {
	function update_user_meta( $uid, $key, $value ) { $GLOBALS['zdz_test_user_meta'][ (int) $uid ][ $key ] = $value; return true; }
}
if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( $k ) { unset( $GLOBALS['zdz_test_transients'][ $k ] ); return true; }
}
if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( $url, $component = -1 ) { return -1 === $component ? parse_url( (string) $url ) : parse_url( (string) $url, $component ); }
}
if ( ! function_exists( 'has_filter' ) ) {
	function has_filter( $hook ) { return isset( $GLOBALS['zdz_test_filters'][ $hook ] ); }
}
if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( $u ) { return (string) $u; }
}
if ( ! function_exists( 'sanitize_email' ) ) {
	function sanitize_email( $e ) { return filter_var( (string) $e, FILTER_VALIDATE_EMAIL ) ? (string) $e : ''; }
}

$zrs_root = dirname( __DIR__, 2 );
// The platform's data permissions are NOT loaded: other suites in this process expect that class to be absent. The
// gate's seam stands in for it (Zdz_Research_Fixture::permission()).
foreach ( array( 'capabilities', 'ask-context', 'privacy-view', 'staging-guard', 'page-fetch', 'step-runner' ) as $zrs_f ) {
	require_once $zrs_root . '/zorderz/inc/class-zdz-' . $zrs_f . '.php';
}
foreach ( array( 'markers', 'allowance', 'llm-client', 'pseudonym-route', 'db', 'mail-compose', 'email-turn', 'company-facts', 'prompt-builder',
	'web-vetting', 'research-settings', 'research-store', 'research-activity', 'research-gate', 'research-tools', 'research', 'research-rest' ) as $zrs_f ) {
	require_once $zrs_root . '/zorderz-apps/apps/analytics/includes/class-zana-' . $zrs_f . '.php';
}
unset( $zrs_root, $zrs_f );

if ( ! class_exists( 'Zdz_Research_Test_Db' ) ) {
	/** The runs', jobs' and chat's tables in the general in-memory database; the Ai ledger's in the ledger's own double. */
	class Zdz_Research_Test_Db extends Zdz_Steps_Test_Db {
		public $usermeta = '';
		public $options  = 'wp_options';
		/** @var Zdz_Test_Wpdb */
		public $llm;

		public function __construct() {
			$this->llm = new Zdz_Test_Wpdb();
		}
		private static function is_llm( $s ) {
			return false !== strpos( (string) $s, 'zdz_llm_' );
		}
		public function insert( $table, array $row, $formats = null ) {
			if ( self::is_llm( $table ) ) {
				$r               = $this->llm->insert( $table, $row, $formats );
				$this->insert_id = $this->llm->insert_id;
				return $r;
			}
			return parent::insert( $table, $row, $formats );
		}
		public function update( $table, array $data, array $where, $f1 = null, $f2 = null ) {
			return self::is_llm( $table ) ? $this->llm->update( $table, $data, $where, $f1, $f2 ) : parent::update( $table, $data, $where, $f1, $f2 );
		}
		public function delete( $table, array $where, $f = null ) {
			return self::is_llm( $table ) ? $this->llm->delete( $table, $where, $f ) : parent::delete( $table, $where, $f );
		}
		public function query( $sql ) {
			return self::is_llm( $sql ) ? $this->llm->query( $sql ) : parent::query( $sql );
		}
		public function get_var( $sql ) {
			return self::is_llm( $sql ) ? $this->llm->get_var( $sql ) : parent::get_var( $sql );
		}
		public function get_results( $sql, $output = null ) {
			return self::is_llm( $sql ) ? $this->llm->get_results( $sql, $output ) : parent::get_results( $sql, $output );
		}
		public function get_row( $sql, $output = null ) {
			return self::is_llm( $sql ) ? null : parent::get_row( $sql, $output );
		}
	}
}

if ( ! class_exists( 'Zdz_Research_Records_Stub' ) ) {
	/** A stand-in for the records tool: its state, the requests in an answer, and what a lookup hands back. */
	class Zdz_Research_Records_Stub {
		public $state   = 'on';
		public $lookups = array();
		public $block   = "RECORDS\n- invoice 1001, line 2: replaced part";
		public function state( $uid ) { return $this->state; }
		public function prompt_block( $vocab ) { return 'RECORDS: ask with [ZANA_RECORDS]{lookup}[/ZANA_RECORDS].'; }
		public function requests_in( $text ) {
			return preg_match_all( '/\[ZANA_RECORDS\](.*?)\[\/ZANA_RECORDS\]/s', (string) $text, $m ) ? array_map( function ( $j ) {
				return json_decode( $j, true );
			}, $m[1] ) : array();
		}
		public function lookup( $uid, $lookups ) {
			$this->lookups[] = array( (int) $uid, $lookups );
			return array( 'ok' => true, 'state' => 'on', 'message' => '', 'block' => $this->block, 'fenced' => $this->block, 'results' => array(), 'ran' => 1, 'refused' => 0, 'verified' => array() );
		}
		public function strip_requests( $text, $offered = false ) {
			return trim( (string) preg_replace( '/\[ZANA_RECORDS\].*?\[\/ZANA_RECORDS\]/s', '', (string) $text ) );
		}
	}
}

if ( ! class_exists( 'Zdz_Research_Fixture' ) ) {
	class Zdz_Research_Fixture {

		const PRIMARY = 'example-large-1';
		const OWNER   = 11;   // an administrator-level account: may run research and use the web
		const SALES   = 12;   // a staff account
		const KIOSK   = 13;   // the shared device
		const ADMIN   = 14;   // another administrator
		const SESSION = 5;

		/** @var int the clock every research class and the step runner read */
		public static $now = 1793000000;
		/** @var array<int,array{0:int,1:string}> start requests "sent" and not yet delivered */
		public static $kicks = array();
		/** @var Zdz_Test_Router */
		public static $router;

		/**
		 * The data permissions this fixture's people hold, as the platform's role defaults give them (administrators:
		 * both; sales: the web only; the shared device and an unknown role: neither), with a person's own override in
		 * user meta `zdz_data_permissions` winning.
		 */
		public static function permission( int $uid, string $key ): bool {
			$own = $GLOBALS['zdz_test_user_meta'][ $uid ]['zdz_data_permissions'][ $key ] ?? 'default';
			if ( in_array( $own, array( 'allow', 'deny' ), true ) ) {
				return 'allow' === $own;
			}
			$roles = (array) ( $GLOBALS['zdz_test_user_roles'][ $uid ] ?? array() );
			if ( in_array( 'zdz_general', $roles, true ) ) {
				return false;
			}
			if ( in_array( 'administrator', $roles, true ) ) {
				return true;
			}
			return in_array( 'zdz_sales', $roles, true ) && 'access_web_research' === $key;
		}

		/** A site where research can run, with a scripted Ai. */
		public static function up( array $answers = array() ) {
			unset( $GLOBALS['zdz_test_user_caps'], $GLOBALS['zdz_test_users'], $GLOBALS['zdz_test_transients'] );
			self::$now   = 1793000000;
			self::$kicks = array();
			$GLOBALS['wpdb']             = new Zdz_Research_Test_Db();
			$GLOBALS['zdz_test_options'] = array(
				'zdz_model_slot_llm_primary'    => self::PRIMARY,
				'zdz_llm_model_prices'          => array( self::PRIMARY => array( 3, 3.75, 6, 0.3, 15 ) ),
				'zdz_llm_budgets'               => array( 'app' => 100, 'all' => 120 ),
				'zdz_llm_ledger_db'             => ZDZ_LLM_Ledger::DB_VERSION,
				ZANA_Research_Settings::OPTION  => array( 'enabled' => true ),
			);
			$GLOBALS['zdz_test_filters']      = array(
				'zdz_anthropic_workspace_id' => function () {
					return 'wrkspc_example';
				},
			);
			$GLOBALS['zdz_test_user_meta']    = array();
			$GLOBALS['zdz_test_user_roles']   = array(
				self::OWNER => array( 'administrator' ),
				self::SALES => array( 'zdz_sales' ),
				self::KIOSK => array( 'zdz_general' ),
				self::ADMIN => array( 'administrator' ),
			);
			$GLOBALS['zdz_test_current_user'] = self::OWNER;

			$r             = new Zdz_Test_Router();
			$r->test_coder = new Zdz_Test_Coder();
			$r->fake       = new ZDZ_LLM_Fake( $answers, array(), function ( $s ) use ( $r ) {
				$r->clock += $s;
			} );
			ZDZ_LLM_Ledger::$now = function () use ( $r ) {
				return (int) $r->clock;
			};
			ZDZ_LLM_Ledger::forget();
			ZDZ_Core_LLM::set_instance( $r );
			$coder                    = $r->test_coder;
			ZANA_LLM_Client::$decoder = function ( $t ) use ( $coder ) {
				return $coder->decode( $t, true );
			};
			ZANA_Allowance::$today    = function () {
				return '2026-10-26';
			};
			self::$router             = $r;

			$clock = function () {
				return Zdz_Research_Fixture::$now;
			};
			ZANA_Research_Store::$now        = $clock;
			ZDZ_Step_Runner::reset();
			ZDZ_Step_Runner::$now            = $clock;
			ZDZ_Step_Runner::$origin         = null;
			ZDZ_Step_Runner::$sapi           = null;
			ZDZ_Step_Runner::$armer          = function () {
			};
			ZDZ_Step_Runner::$loopback       = function ( $id, $token ) {
				Zdz_Research_Fixture::$kicks[] = array( (int) $id, (string) $token );
			};
			ZANA_Research_Gate::$permission    = array( __CLASS__, 'permission' );
			ZANA_Research_Gate::$messages_turn = null;
			ZANA_Research_Gate::$demo          = null;
			ZANA_Research::$answer_gate        = null;
			ZANA_Research_Tools::$page_reader  = null;
			ZANA_Research_Tools::$quick        = function () {
				return null;
			};
			ZANA_Research_Tools::$own          = null;
			ZANA_Research_Tools::$records_tool = false;
			ZANA_Company_Facts::$rows          = array();
			ZANA_Email_Turn::$company_names    = array();
			if ( class_exists( 'ZANA_Charts_Tool' ) ) {
				ZANA_Charts_Tool::$vocab_for = array();
			}
			self::flush();
			ZANA_Research::boot();
			return $r;
		}

		public static function down(): void {
			ZDZ_Step_Runner::reset();
			ZDZ_Step_Runner::$now = ZDZ_Step_Runner::$origin = ZDZ_Step_Runner::$sapi = ZDZ_Step_Runner::$armer = ZDZ_Step_Runner::$loopback = null;
			ZANA_Research_Store::$now          = null;
			ZANA_Research_Gate::$permission    = null;
			ZANA_Research_Gate::$messages_turn = null;
			ZANA_Research_Gate::$demo          = null;
			ZANA_Research::$answer_gate        = null;
			ZANA_Research_Tools::$page_reader  = null;
			ZANA_Research_Tools::$quick        = null;
			ZANA_Research_Tools::$own          = null;
			ZANA_Research_Tools::$records_tool = null;
			ZANA_Company_Facts::$rows          = null;
			ZANA_Email_Turn::$company_names    = null;
			ZANA_LLM_Client::$decoder          = null;
			ZANA_Allowance::$today             = null;
			if ( class_exists( 'ZANA_Charts_Tool' ) ) {
				ZANA_Charts_Tool::$vocab_for = null;
			}
			ZDZ_Core_LLM::set_instance( null );
			ZDZ_LLM_Ledger::$now = null;
			ZDZ_LLM_Ledger::forget();
			self::flush();
			unset( $GLOBALS['wpdb'], $GLOBALS['zdz_test_options'], $GLOBALS['zdz_test_filters'], $GLOBALS['zdz_test_user_roles'],
				$GLOBALS['zdz_test_user_meta'], $GLOBALS['zdz_test_current_user'], $GLOBALS['zdz_test_user_caps'] );
		}

		/** What the platform remembers about people within one request is forgotten. */
		public static function flush(): void {
			ZDZ_Capabilities::flush();
			if ( class_exists( 'ZDZ_Business_Profile' ) ) {
				ZDZ_Business_Profile::flush();
			}
		}

		/** Deliver every start request that is waiting, until none is (or $max steps ran). Returns the steps' outcomes. */
		public static function pump( int $max = 60 ): array {
			$out = array();
			while ( self::$kicks && count( $out ) < $max ) {
				list( $id, $token ) = array_shift( self::$kicks );
				if ( ZDZ_Step_Runner::consume_kick( $id, $token ) ) {
					$out[] = ZDZ_Step_Runner::run( $id );
				}
			}
			return $out;
		}

		/** Deliver one waiting start request. */
		public static function step(): string {
			while ( self::$kicks ) {
				list( $id, $token ) = array_shift( self::$kicks );
				if ( ZDZ_Step_Runner::consume_kick( $id, $token ) ) {
					return ZDZ_Step_Runner::run( $id );
				}
			}
			return 'none';
		}

		/** The run's message in the chat, as the chat stores it when a run answers the turn. */
		public static function message( int $run_id, int $uid = self::OWNER, int $session = self::SESSION ): void {
			$GLOBALS['wpdb']->insert( 'wp_zana_messages', array( 'session_id' => $session, 'user_id' => $uid, 'role' => 'assistant', 'body' => ZANA_Research::token( $run_id ) ) );
		}

		public static function message_body( int $run_id ): string {
			foreach ( $GLOBALS['wpdb']->rows( 'zana_messages' ) as $m ) {
				if ( false !== strpos( (string) $m['body'], ZANA_Research::token( $run_id ) ) ) {
					return (string) $m['body'];
				}
			}
			return '';
		}

		/** A plan as the Ai answers it. */
		public static function plan( array $questions, string $objective = 'Find out what was asked.' ) {
			return ZDZ_LLM_Fake::answer( (string) json_encode( array( 'objective' => $objective, 'questions' => $questions, 'done_when' => 'The question is answered.' ) ) );
		}

		/** A next action as the Ai answers it. */
		public static function action( array $a ) {
			return ZDZ_LLM_Fake::answer( (string) json_encode( $a ) );
		}

		/** Everything the provider was sent, as one string (what left the site). */
		public static function sent(): string {
			return (string) json_encode( self::$router->fake->requests, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		}
	}
}
