<?php
/**
 * Stand-ins for the unit tests of the chat's style rules and of its replies in a Messages thread
 * (AssistantStyleMemoryTest, AssistantMessageRepliesTest). Not a test file itself.
 *
 *   - the few WordPress functions those two touch that no shared stand-in has yet (each guarded; each reads or writes
 *     the globals every suite shares). WordPress's scheduler is deliberately NOT among them: other classes take its
 *     absence to mean "not WordPress", so those two classes have their own seams for it;
 *   - ZDZ_Test_Asst_DB: an in-memory $wpdb that keeps the chat's sessions and messages, Messages' conversations and
 *     messages, and the option rows a lock or a claim adds, and understands exactly the statements those classes make;
 *   - zdz_test_asst_router(): the Ai router with a fake provider and a stand-in coder.
 *
 * Neutral data only.
 *
 * @package Zorderz\Tests
 */

require_once __DIR__ . '/privacy-stubs.php';
require_once dirname( __DIR__ ) . '/llm-test-doubles.php';

foreach ( array( 'ZANA_APP_ID' => 'sales-analytics', 'ZANA_NONCE' => 'zana_nonce', 'ZDZ_REST_NS' => 'zorderz/v1', 'ZANA_URL' => 'https://app.example.com/wp-content/plugins/zorderz-apps/apps/analytics/' ) as $zdz_asst_k => $zdz_asst_v ) {
	if ( ! defined( $zdz_asst_k ) ) {
		define( $zdz_asst_k, $zdz_asst_v );
	}
}

if ( ! function_exists( 'update_user_meta' ) ) {
	function update_user_meta( $uid, $key, $value ) { $GLOBALS['zdz_test_user_meta'][ (int) $uid ][ $key ] = $value; return true; }
}
if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( $k ) { unset( $GLOBALS['zdz_test_transients'][ $k ] ); return true; }
}
if ( ! function_exists( 'has_filter' ) ) {
	function has_filter( $hook ) { return isset( $GLOBALS['zdz_test_filters'][ $hook ] ); }
}
if ( ! function_exists( 'wp_hash' ) ) {
	function wp_hash( $s ) { return md5( 'unit|' . $s ); }
}
if ( ! function_exists( 'wp_set_current_user' ) ) {
	function wp_set_current_user( $uid ) { $GLOBALS['zdz_test_current_user'] = (int) $uid; }
}
if ( ! function_exists( 'wp_doing_cron' ) ) {
	function wp_doing_cron() { return ! empty( $GLOBALS['zdz_test_doing_cron'] ); }
}
if ( ! function_exists( 'wp_cache_delete' ) ) {
	function wp_cache_delete( $k, $g = '' ) { return true; }
}
if ( ! function_exists( 'wp_date' ) ) {
	function wp_date( $format, $ts = null ) { return gmdate( (string) $format, null === $ts ? time() : (int) $ts ); }
}
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
}
if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
}
if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( $s, $d = null ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
}

if ( ! class_exists( 'ZDZ_Test_Asst_DB' ) ) {
	/**
	 * The in-memory $wpdb. Rows live in arrays by table; a statement it does not know reads as nothing found and
	 * writes nothing, and every statement run is kept in $log.
	 */
	class ZDZ_Test_Asst_DB {
		public $prefix     = 'wp_';
		public $options    = 'wp_options';
		public $users      = 'wp_users';
		public $usermeta   = '';          // no usermeta table: a day's spend is kept through update_user_meta()
		public $insert_id  = 0;
		public $last_error = '';
		public $opt        = array();     // option_name => option_value: the rows a lock or a claim adds
		public $sessions   = array();     // the chat's sessions, by id
		public $chat       = array();     // the chat's messages, by id
		public $convs      = array();     // Messages' conversations, by id
		public $msgs       = array();     // Messages' messages, by id
		public $other      = array();     // inserts into any other table: [ table, row ]
		public $log        = array();

		public function prepare( $sql, ...$args ) {
			if ( 1 === count( $args ) && is_array( $args[0] ) ) {
				$args = $args[0];
			}
			$i = 0;
			return (string) preg_replace_callback( '/%[dsf]/', function ( $m ) use ( &$i, $args ) {
				$v = $args[ $i++ ] ?? '';
				return '%d' === $m[0] ? (string) (int) $v : "'" . addslashes( (string) $v ) . "'";
			}, (string) $sql );
		}
		public function esc_like( $s ) { return $s; }
		public function get_charset_collate() { return ''; }

		private function said( $sql ) {
			$sql         = trim( (string) preg_replace( '/\s+/', ' ', (string) $sql ) );
			$this->log[] = $sql;
			return $sql;
		}

		public function insert( $table, $row, $fmt = null ) {
			$map = array( 'wp_zana_sessions' => 'sessions', 'wp_zana_messages' => 'chat', 'wp_zim_conversations' => 'convs', 'wp_zim_messages' => 'msgs' );
			if ( ! isset( $map[ $table ] ) ) {
				$this->other[]   = array( (string) $table, (array) $row );
				$this->insert_id = count( $this->other );
				return 1;
			}
			$store           = $map[ $table ];
			$id              = $this->{$store} ? max( array_keys( $this->{$store} ) ) + 1 : 1;
			$this->{$store}[ $id ] = array( 'id' => $id ) + (array) $row;
			$this->insert_id = $id;
			return 1;
		}
		public function update( $table, $set, $where, $f = null, $wf = null ) {
			if ( 'wp_zana_messages' === $table && isset( $where['id'], $this->chat[ (int) $where['id'] ] ) ) {
				$this->chat[ (int) $where['id'] ] = array_merge( $this->chat[ (int) $where['id'] ], (array) $set );
			}
			return 1;
		}
		public function delete( ...$a ) { return 1; }

		public function query( $sql ) {
			$sql = $this->said( $sql );
			if ( preg_match( "/^INSERT IGNORE INTO wp_options \(option_name, option_value, autoload\) VALUES \('([^']+)', '([^']*)', 'no'\)$/", $sql, $m ) ) {
				if ( isset( $this->opt[ $m[1] ] ) ) {
					return 0;
				}
				$this->opt[ $m[1] ] = $m[2];
				return 1;
			}
			if ( preg_match( "/^UPDATE wp_options SET option_value = '([^']*)' WHERE option_name = '([^']+)' AND CAST\(option_value AS UNSIGNED\) < (\d+)$/", $sql, $m ) ) {
				if ( isset( $this->opt[ $m[2] ] ) && (int) $this->opt[ $m[2] ] < (int) $m[3] ) {
					$this->opt[ $m[2] ] = $m[1];
					return 1;
				}
				return 0;
			}
			if ( preg_match( "/^DELETE FROM wp_options WHERE option_name = '([^']+)'$/", $sql, $m ) ) {
				$had = isset( $this->opt[ $m[1] ] ) ? 1 : 0;
				unset( $this->opt[ $m[1] ] );
				return $had;
			}
			return 0;
		}

		public function get_var( $sql ) {
			$sql = $this->said( $sql );
			if ( preg_match( '/^SELECT user_id FROM wp_zana_sessions WHERE id = (\d+)$/', $sql, $m ) ) {
				return isset( $this->sessions[ (int) $m[1] ] ) ? (string) $this->sessions[ (int) $m[1] ]['user_id'] : null;
			}
			if ( preg_match( "/^SELECT id FROM wp_zim_conversations WHERE kind = 'dm' AND user_a = (\d+) AND user_b = (\d+)/", $sql, $m ) ) {
				foreach ( $this->convs as $c ) {
					if ( 'dm' === $c['kind'] && (int) $c['user_a'] === (int) $m[1] && (int) $c['user_b'] === (int) $m[2] ) {
						return (string) $c['id'];
					}
				}
				return null;
			}
			if ( preg_match( '/^SELECT COALESCE\(MAX\(id\), 0\) FROM wp_zim_messages WHERE conversation_id = (\d+) AND id < (\d+)$/', $sql, $m ) ) {
				$max = 0;
				foreach ( $this->msgs as $r ) {
					if ( (int) $r['conversation_id'] === (int) $m[1] && (int) $r['id'] < (int) $m[2] ) {
						$max = max( $max, (int) $r['id'] );
					}
				}
				return (string) $max;
			}
			return null;
		}

		public function get_row( $sql, $out = 'OBJECT' ) {
			$sql = $this->said( $sql );
			if ( preg_match( "/^SELECT user_a, user_b FROM wp_zim_conversations WHERE id = (\d+) AND kind = 'dm' LIMIT 1$/", $sql, $m ) ) {
				$c = $this->convs[ (int) $m[1] ] ?? null;
				return ( $c && 'dm' === $c['kind'] ) ? (object) array( 'user_a' => $c['user_a'], 'user_b' => $c['user_b'] ) : null;
			}
			if ( preg_match( '/^SELECT kind, is_announcements FROM wp_zim_conversations WHERE id = (\d+)$/', $sql, $m ) ) {
				$c = $this->convs[ (int) $m[1] ] ?? null;
				return $c ? (object) array( 'kind' => $c['kind'], 'is_announcements' => $c['is_announcements'] ?? 0 ) : null;
			}
			return null;
		}

		public function get_results( $sql, $out = 'OBJECT' ) {
			$sql = $this->said( $sql );
			if ( preg_match( '/FROM wp_zim_messages WHERE conversation_id = (\d+) AND id > (\d+) ORDER BY id ASC LIMIT (\d+)$/', $sql, $m ) ) {
				return array_slice( $this->thread( (int) $m[1], function ( $id ) use ( $m ) { return $id > (int) $m[2]; } ), 0, (int) $m[3] );
			}
			if ( preg_match( '/FROM wp_zim_messages WHERE conversation_id = (\d+) (?:AND id < (\d+) )?ORDER BY id DESC LIMIT (\d+)$/', $sql, $m ) ) {
				$before = '' !== (string) $m[2] ? (int) $m[2] : PHP_INT_MAX;
				return array_slice( array_reverse( $this->thread( (int) $m[1], function ( $id ) use ( $before ) { return $id < $before; } ) ), 0, (int) $m[3] );
			}
			if ( preg_match( '/^SELECT id, role, body, tier, verdict, created_at FROM wp_zana_messages WHERE session_id = (\d+) AND user_id = (\d+) ORDER BY id ASC$/', $sql, $m ) ) {
				return array_values( array_filter( $this->chat, function ( $r ) use ( $m ) {
					return (int) $r['session_id'] === (int) $m[1] && (int) $r['user_id'] === (int) $m[2];
				} ) );
			}
			if ( preg_match( "/^SELECT id, body FROM wp_zana_messages WHERE session_id = (\d+) AND user_id = (\d+) AND role = 'assistant' AND body LIKE '%(.+)%'$/", $sql, $m ) ) {
				return array_values( array_filter( $this->chat, function ( $r ) use ( $m ) {
					return (int) $r['session_id'] === (int) $m[1] && (int) $r['user_id'] === (int) $m[2] && 'assistant' === $r['role'] && false !== strpos( (string) $r['body'], stripslashes( $m[3] ) );
				} ) );
			}
			if ( preg_match( '/^SELECT id, title, updated_at FROM wp_zana_sessions WHERE user_id = (\d+) ORDER BY updated_at DESC LIMIT (\d+)$/', $sql, $m ) ) {
				$rows = array_values( array_filter( $this->sessions, function ( $r ) use ( $m ) { return (int) $r['user_id'] === (int) $m[1]; } ) );
				return array_slice( array_reverse( $rows ), 0, (int) $m[2] );
			}
			return array();
		}

		public function get_col( $sql ) { $this->said( $sql ); return array(); }

		/** A conversation's messages, oldest first, as Messages selects them. */
		private function thread( int $conv, callable $keep ): array {
			$rows = array();
			foreach ( $this->msgs as $r ) {
				if ( (int) $r['conversation_id'] === $conv && $keep( (int) $r['id'] ) ) {
					$rows[] = $r + array( 'created_at' => '2026-03-04 10:00:00', 'edited_at' => null, 'deleted_at' => null, 'deleted_by_user_id' => null );
				}
			}
			return $rows;
		}

		/* What a test puts there. */
		public function conversation( int $id, int $a, int $b, string $kind = 'dm' ): void {
			$this->convs[ $id ] = array( 'id' => $id, 'kind' => $kind, 'user_a' => min( $a, $b ), 'user_b' => max( $a, $b ), 'is_announcements' => 0 );
		}
		public function message( int $conv, int $author, string $body, bool $deleted = false ): int {
			$id                = $this->msgs ? max( array_keys( $this->msgs ) ) + 1 : 1;
			$this->msgs[ $id ] = array( 'id' => $id, 'conversation_id' => $conv, 'author_user_id' => $author, 'body' => $body, 'created_at' => '2026-03-04 10:00:00', 'edited_at' => null, 'deleted_at' => $deleted ? '2026-03-04 10:05:00' : null, 'deleted_by_user_id' => $deleted ? $author : null );
			return $id;
		}
		public function session( int $uid, string $title ): int {
			$this->insert( 'wp_zana_sessions', array( 'user_id' => $uid, 'title' => $title, 'created_at' => '2026-03-04 09:00:00', 'updated_at' => '2026-03-04 09:00:00' ) );
			return $this->insert_id;
		}
		public function said_in_chat( int $session, int $uid, string $role, string $body ): int {
			$this->insert( 'wp_zana_messages', array( 'session_id' => $session, 'user_id' => $uid, 'role' => $role, 'body' => $body, 'tier' => 'unknown', 'verdict' => 'ok', 'created_at' => '2026-03-04 09:00:00' ) );
			return $this->insert_id;
		}
	}
}

/**
 * The Ai router with a fake provider (it answers $answers in turn) and a stand-in coder, a month's budget set, and the
 * ledger in memory ($GLOBALS['wpdb'] becomes the ledger's own stand-in; options a test set beforehand are kept).
 */
function zdz_test_asst_router( array $answers ) {
	$GLOBALS['wpdb']             = new Zdz_Test_Wpdb();
	$GLOBALS['zdz_test_options'] = array(
		'zdz_model_slot_llm_primary' => 'example-large-1',
		'zdz_llm_model_prices'       => array( 'example-large-1' => array( 3, 3.75, 6, 0.3, 15 ) ),
		'zdz_llm_budgets'            => array( 'app' => 10, 'all' => 12 ),
		'zdz_llm_ledger_db'          => ZDZ_LLM_Ledger::DB_VERSION,
	) + (array) ( $GLOBALS['zdz_test_options'] ?? array() );
	$r             = new Zdz_Test_Router();
	$r->test_coder = new Zdz_Test_Coder();
	$r->fake       = new ZDZ_LLM_Fake( $answers, array(), function ( $s ) use ( $r ) { $r->clock += $s; } );
	ZDZ_LLM_Ledger::$now = function () use ( $r ) { return (int) $r->clock; };
	ZDZ_LLM_Ledger::forget();
	ZDZ_Core_LLM::set_instance( $r );
	return $r;
}

/** After a test that used the router. */
function zdz_test_asst_router_off(): void {
	ZDZ_Core_LLM::set_instance( null );
	ZDZ_LLM_Ledger::$now = null;
	ZDZ_LLM_Ledger::forget();
}
