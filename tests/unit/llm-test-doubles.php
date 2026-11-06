<?php
/**
 * Test doubles for the Ai router suites: the few WordPress functions the router touches, an in-memory $wpdb that
 * understands exactly the ledger's statements, a stand-in customer-detail coder, and a router whose clock, provider and
 * coder a test controls. Neutral data only. Not a test file itself (no *Test.php suffix).
 *
 * @package Zorderz\Tests
 */

if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}
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
	function is_wp_error( $x ) { return $x instanceof WP_Error; }
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( $k, $v, $autoload = null ) { $GLOBALS['zdz_test_options'][ $k ] = $v; return true; }
}
if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( $k ) { unset( $GLOBALS['zdz_test_options'][ $k ] ); return true; }
}
if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id() { return (int) ( $GLOBALS['zdz_test_current_user'] ?? 0 ); }
}
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
}
if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
}
if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( $s, $d = null ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
}

require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-model-registry.php';
require_once dirname( __DIR__, 2 ) . '/zorderz/inc/llm/class-zdz-llm-provider.php';
require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-llm-guard.php';
require_once dirname( __DIR__, 2 ) . '/zorderz/inc/llm/class-zdz-llm-anthropic.php';
require_once dirname( __DIR__, 2 ) . '/zorderz/inc/llm/class-zdz-llm-openai-compatible.php';
require_once dirname( __DIR__, 2 ) . '/zorderz/inc/llm/class-zdz-llm-poe.php';
require_once dirname( __DIR__, 2 ) . '/zorderz/inc/llm/class-zdz-llm-fake.php';
require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-llm-ledger.php';
require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-core-llm.php';
if ( file_exists( dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-llm-admin.php' ) ) {
	require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-llm-admin.php';
}

if ( ! class_exists( 'Zdz_Test_Wpdb' ) ) {
	/**
	 * An in-memory $wpdb for the ledger: insert / update / delete by id, prepare(), get_var() for the SUM statements,
	 * get_results() for rows_since(), query() for DDL (kept) and the prune DELETEs.
	 */
	class Zdz_Test_Wpdb {
		public $prefix     = 'wp_';
		public $last_error = '';
		public $insert_id  = 0;
		public $tables     = array();
		public $ddl        = array();
		public $fail_reads = false;
		public $fail_insert = false;
		/** Called with ( $table, $row ) before a row is stored in the holds table; set to null after one use if wanted. */
		public $on_hold_insert = null;
		private $next_id = 1;

		public function get_charset_collate() { return ''; }

		public function prepare( $q, ...$args ) {
			if ( 1 === count( $args ) && is_array( $args[0] ) ) {
				$args = $args[0];
			}
			$i = 0;
			return preg_replace_callback( '/%[sd]/', function ( $m ) use ( &$i, $args ) {
				$v = $args[ $i++ ] ?? '';
				return '%d' === $m[0] ? (string) (int) $v : "'" . str_replace( "'", "''", (string) $v ) . "'";
			}, $q );
		}

		public function insert( $table, array $row, $formats = null ) {
			if ( $this->fail_insert ) {
				return false;
			}
			if ( $this->on_hold_insert && substr( $table, -strlen( 'zdz_llm_holds' ) ) === 'zdz_llm_holds' ) {
				$cb = $this->on_hold_insert;
				$this->on_hold_insert = null;
				call_user_func( $cb, $table, $row );
			}
			$id               = $this->next_id++;
			$row['id']        = $id;
			$this->tables[ $table ][ $id ] = $row;
			$this->insert_id  = $id;
			return 1;
		}

		public function update( $table, array $data, array $where, $f1 = null, $f2 = null ) {
			$id = (int) ( $where['id'] ?? 0 );
			if ( ! isset( $this->tables[ $table ][ $id ] ) ) {
				return 0;
			}
			$this->tables[ $table ][ $id ] = array_merge( $this->tables[ $table ][ $id ], $data );
			return 1;
		}

		public function delete( $table, array $where, $f = null ) {
			$id = (int) ( $where['id'] ?? 0 );
			if ( ! isset( $this->tables[ $table ][ $id ] ) ) {
				return 0;
			}
			unset( $this->tables[ $table ][ $id ] );
			return 1;
		}

		public function rows( $suffix ) {
			return array_values( $this->tables[ $this->prefix . $suffix ] ?? array() );
		}

		private static function conds( $where ) {
			$out = array();
			foreach ( preg_split( '/\s+AND\s+/', trim( $where ) ) as $c ) {
				if ( preg_match( "/^(\w+)\s*(>=|>|<|=)\s*'?([^']*)'?$/", trim( $c ), $m ) ) {
					$out[] = array( $m[1], $m[2], $m[3] );
				} elseif ( preg_match( '/^(\w+)\s+NOT IN\s*\((.*)\)$/', trim( $c ), $m ) ) {
					preg_match_all( "/'([^']*)'/", $m[2], $vals );
					$out[] = array( $m[1], 'NOT IN', $vals[1] );
				}
			}
			return $out;
		}

		private function matching( $table, $where ) {
			$rows = array();
			foreach ( $this->tables[ $table ] ?? array() as $r ) {
				$ok = true;
				foreach ( self::conds( $where ) as list( $col, $op, $val ) ) {
					$v = (string) ( $r[ $col ] ?? '' );
					switch ( $op ) {
						case '>=': $ok = $ok && strcmp( $v, $val ) >= 0; break;
						case '>':  $ok = $ok && strcmp( $v, $val ) > 0; break;
						case '<':  $ok = $ok && strcmp( $v, $val ) < 0; break;
						case '=':  $ok = $ok && $v === (string) $val; break;
						case 'NOT IN': $ok = $ok && ! in_array( $v, $val, true ); break;
					}
				}
				if ( $ok ) {
					$rows[] = $r;
				}
			}
			return $rows;
		}

		public function get_var( $q ) {
			if ( $this->fail_reads ) {
				return null;
			}
			if ( preg_match( '/SELECT COALESCE\(SUM\(usd_milli\),0\) FROM (\S+) WHERE (.*)$/s', $q, $m ) ) {
				$sum = 0;
				foreach ( $this->matching( $m[1], $m[2] ) as $r ) {
					$sum += (int) $r['usd_milli'];
				}
				return (string) $sum;
			}
			return null;
		}

		public function get_results( $q, $out = null ) {
			if ( preg_match( '/FROM (\S+) WHERE (.*?) ORDER BY/s', $q, $m ) ) {
				$rows = $this->matching( $m[1], $m[2] );
				usort( $rows, function ( $a, $b ) {
					return strcmp( $a['ts'], $b['ts'] );
				} );
				return $rows;
			}
			return array();
		}

		public function query( $q ) {
			if ( preg_match( '/^\s*CREATE TABLE/', $q ) ) {
				$this->ddl[] = $q;
				return true;
			}
			if ( preg_match( '/^DELETE FROM (\S+) WHERE (.*)$/s', $q, $m ) ) {
				$n = 0;
				foreach ( $this->matching( $m[1], $m[2] ) as $r ) {
					unset( $this->tables[ $m[1] ][ $r['id'] ] );
					$n++;
				}
				return $n;
			}
			return true;
		}
	}
}

if ( ! class_exists( 'Zdz_Test_Coder' ) ) {
	/**
	 * A stand-in for ZDZ_Private::for( 'llm' ) with the same instance methods: it codes e-mails, 555 phone numbers and
	 * the made-up full names it is told about as ⟦e:<72 characters>⟧, and (outside business text) the one-word ids it is
	 * told about. Codes made within one request are stable; new_request() draws a new salt.
	 */
	class Zdz_Test_Coder {
		public $names    = array( 'Riley Example', 'Jordan Sample' );
		public $one_word = array( 'widget-std' );
		public $sealed   = array();
		private $salt;

		public function __construct() { $this->salt = random_bytes( 16 ); }
		public function new_request() { $this->salt = random_bytes( 16 ); }
		public function fixed_salt( $seed ) {
			$prev       = $this->salt;
			$this->salt = hash( 'sha256', 'fixed|' . $seed, true );
			return $prev;
		}
		public function restore_salt( $prev ) { $this->salt = is_string( $prev ) ? $prev : random_bytes( 16 ); }

		private function seal( $plain ) {
			$h = rtrim( strtr( base64_encode( hash_hmac( 'sha256', $plain, $this->salt, true ) . hash_hmac( 'sha256', 'b|' . $plain, $this->salt, true ) . hash_hmac( 'sha256', 'c|' . $plain, $this->salt, true ) ), '+/', '-_' ), '=' );
			$code = substr( $h, 0, 72 );
			$this->sealed[ $code ] = $plain;
			return '⟦e:' . $code . '⟧';
		}

		public function encode( $text, $idx = null, $keep_codes = false, $scope = null, array $opts = array() ) {
			$text = (string) $text;
			if ( ! $keep_codes ) {
				$text = str_replace( array( '⟦', '⟧' ), array( '[', ']' ), $text );
			}
			$parts = preg_split( '/(⟦[ce]:[A-Za-z0-9_\-]*⟧)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE );
			foreach ( $parts as $i => $p ) {
				if ( 1 === $i % 2 ) {
					continue;
				}
				$p = preg_replace_callback( '/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[a-z]{2,}|\b555-\d{4}\b/', function ( $m ) {
					return $this->seal( $m[0] );
				}, $p );
				foreach ( $this->names as $n ) {
					$p = str_replace( $n, $this->seal( $n ), $p );
				}
				if ( empty( $opts['company'] ) ) {
					foreach ( $this->one_word as $w ) {
						$p = preg_replace_callback( '/(?<![\w\-])' . preg_quote( $w, '/' ) . '(?![\w\-])/', function ( $m ) {
							return $this->seal( $m[0] );
						}, $p );
					}
				}
				$parts[ $i ] = $p;
			}
			return implode( '', $parts );
		}

		public function from_reader( $text, array $allowed_c, $idx = null, array $allowed_e = null ) {
			$text = preg_replace_callback( '/⟦([ce]):([A-Za-z0-9_\-]*)⟧/u', function ( $m ) use ( $allowed_c, $allowed_e ) {
				if ( 'c' === $m[1] ) {
					return in_array( (int) $m[2], $allowed_c, true ) ? $m[0] : 'a customer';
				}
				if ( null !== $allowed_e && ! in_array( $m[2], $allowed_e, true ) ) {
					return '[private]';
				}
				return isset( $this->sealed[ $m[2] ] ) ? $m[0] : 'a customer';
			}, (string) $text );
			return $this->encode( $text, $idx, true );
		}

		public function decode( $text, $show ) {
			return preg_replace_callback( '/⟦e:([A-Za-z0-9_\-]*)⟧/u', function ( $m ) use ( $show ) {
				return $show && isset( $this->sealed[ $m[1] ] ) ? $this->sealed[ $m[1] ] : '[private]';
			}, (string) $text );
		}
	}
}

if ( ! class_exists( 'Zdz_Test_Router' ) ) {
	/** The router with a test's clock, provider and coder. */
	class Zdz_Test_Router extends ZDZ_Core_LLM {
		public $clock = 1793000000.0;
		public $fake;
		public $test_coder;
		public $use_real_coder = false;
		public $providers_made = array();

		public function now() { return $this->clock; }
		protected function pause( $seconds ) { $this->clock += $seconds; }
		protected function provider( array $entry ) {
			$this->providers_made[] = $entry;
			return $this->fake;
		}
		protected function coder() {
			return $this->use_real_coder ? parent::coder() : $this->test_coder;
		}
	}
}
