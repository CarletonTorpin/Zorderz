<?php
/**
 * Shared stand-ins for the "answer ONE email" suites: the few WordPress functions the inbox app touches, the inbox
 * app's two access rules as its app file writes them, and an in-memory store that holds one person's mail (and the
 * chat's own sessions and messages) and answers exactly the statements sent for them. Every function is guarded; every
 * value is read from the globals the other suites use. Neutral data only. Not a test file itself.
 *
 * @package Zorderz\Tests
 */

if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( $k ) { return $GLOBALS['zdz_test_transients'][ $k ] ?? false; }
}
if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( $k, $v, $ttl = 0 ) { $GLOBALS['zdz_test_transients'][ $k ] = $v; return true; }
}
if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( $k ) { unset( $GLOBALS['zdz_test_transients'][ $k ] ); return true; }
}
if ( ! function_exists( 'wp_parse_args' ) ) {
	function wp_parse_args( $args, $defaults = array() ) { return array_merge( (array) $defaults, (array) $args ); }
}
if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id() { return (int) ( $GLOBALS['zdz_test_current_user'] ?? 0 ); }
}
if ( ! function_exists( 'has_filter' ) ) {
	function has_filter( $hook ) { return isset( $GLOBALS['zdz_test_filters'][ $hook ] ); }
}
if ( ! function_exists( 'zib_user_is_read_only' ) ) {
	/** The inbox app's own rule, as its app file writes it: the shared device's role is a device, not a person. */
	function zib_user_is_read_only( $user_id = null ) {
		$u = get_userdata( (int) $user_id );
		return $u && in_array( 'zdz_general', (array) $u->roles, true );
	}
}
if ( ! function_exists( 'zib_user_has_access' ) ) {
	/** The inbox app's own rule: never the shared device; otherwise an administrator or the app capability. */
	function zib_user_has_access( $user_id = null ) {
		if ( zib_user_is_read_only( $user_id ) ) {
			return false;
		}
		return user_can( (int) $user_id, 'manage_options' ) || user_can( (int) $user_id, 'zdz_access_app' );
	}
}

foreach ( array( 'settings', 'crypto', 'supplier-feed', 'compose', 'gatekeeper', 'zana-bridge' ) as $zrs_f ) {
	require_once dirname( __DIR__, 3 ) . '/zorderz-apps/apps/inbox/includes/class-zib-' . $zrs_f . '.php';
}
unset( $zrs_f );

if ( ! class_exists( 'Zdz_Test_Reply_Mail_DB' ) ) {
	/**
	 * An in-memory mail store that answers exactly the statements the gatekeeper sends for one person's own mail:
	 * one message by id and owner, a message's parties, the addresses a name has used, mail received from a sender,
	 * and the person's own sent mail. Bodies are sealed with the inbox app's own crypto, as they are stored. It also
	 * keeps the chat's own sessions and messages, for a suite that runs a whole chat turn.
	 */
	class Zdz_Test_Reply_Mail_DB {
		public $prefix     = 'wp_';
		public $last_error = '';
		public $insert_id  = 0;
		public $msgs       = array();
		public $parties    = array();
		public $inserts    = array();
		/** The chat's own tables: chat id => its person, and the messages kept. */
		public $sessions   = array();
		public $chat       = array();

		public function esc_like( $s ) { return addcslashes( (string) $s, '_%\\' ); }
		public function get_charset_collate() { return ''; }
		public function prepare( $sql, ...$args ) {
			if ( 1 === count( $args ) && is_array( $args[0] ) ) {
				$args = $args[0];
			}
			return array( 'sql' => $sql, 'args' => $args );
		}
		public function insert( $table, $row, $fmt = null ) {
			if ( 'zana_sessions' === substr( (string) $table, -13 ) ) {
				$this->insert_id                    = count( $this->sessions ) + 1;
				$this->sessions[ $this->insert_id ] = (int) $row['user_id'];
				return 1;
			}
			if ( 'zana_messages' === substr( (string) $table, -13 ) ) {
				$this->chat[] = array( 'id' => count( $this->chat ) + 1 ) + $row;
				return 1;
			}
			$this->inserts[] = array( $table, $row );
			$this->insert_id++;
			return 1;
		}
		public function update( $table, $row, $where, $f = null, $wf = null ) { return 1; }
		public function query( $q ) { return 0; }
		public function get_var( $q ) {
			if ( is_array( $q ) && false !== strpos( $q['sql'], 'zana_sessions' ) && false !== strpos( $q['sql'], 'SELECT user_id' ) ) {
				return $this->sessions[ (int) $q['args'][0] ] ?? null;   // whose chat is this?
			}
			return null;
		}
		public function get_col( $q ) { return array(); }
		public function get_row( $q, $out = null ) {
			if ( is_array( $q ) && false !== strpos( $q['sql'], 'WHERE id = %d AND owner_user_id = %d' ) ) {
				list( $id, $owner ) = $q['args'];
				foreach ( $this->msgs as $m ) {
					if ( (int) $m->id === (int) $id && (int) $m->owner_user_id === (int) $owner ) {
						return $m;
					}
				}
			}
			return null;
		}
		public function get_results( $q, $out = null ) {
			if ( ! is_array( $q ) ) {
				return array();
			}
			$sql = $q['sql'];
			if ( false !== strpos( $sql, 'SELECT role, body FROM' ) ) {   // a chat's earlier turns, newest first
				list( $session, $uid, $limit ) = $q['args'];
				$rows = array_values( array_filter( $this->chat, function ( $r ) use ( $session, $uid ) {
					return (int) $r['session_id'] === (int) $session && (int) $r['user_id'] === (int) $uid;
				} ) );
				return array_slice( array_reverse( $rows ), 0, (int) $limit );
			}
			if ( false !== strpos( $sql, 'SELECT role, addr, name' ) ) {
				return array_values( array_filter( $this->parties, function ( $p ) use ( $q ) {
					return (int) $p->message_id === (int) $q['args'][0];
				} ) );
			}
			if ( false !== strpos( $sql, 'MAX(is_internal) AS internal' ) ) {   // the addresses a name has used
				list( $owner, , $like ) = $q['args'];
				$needle = strtolower( trim( stripslashes( $like ), '%' ) );
				$found  = array();
				foreach ( $this->parties as $p ) {
					if ( (int) $p->owner_user_id === (int) $owner && '' !== $p->addr && false !== strpos( strtolower( $p->name ), $needle ) ) {
						$found[ $p->addr ] = (object) array( 'addr' => $p->addr, 'internal' => (int) ( $p->is_internal ?? 0 ) );
					}
				}
				return array_values( $found );
			}
			if ( false !== strpos( $sql, 'SELECT id, subject, body_clean_text, received_at' ) ) {   // the person's own sent mail
				$args = $q['args'];
				if ( false !== strpos( $sql, 'AGAINST' ) ) {
					array_shift( $args );   // the topic (relevance is the database's own)
				}
				$owner = (int) array_shift( $args );
				$to    = ( false !== strpos( $sql, "role = 'to' AND LOWER(addr) = %s" ) ) ? array( (int) array_shift( $args ), (string) array_shift( $args ) ) : null;
				$rows  = array();
				foreach ( $this->msgs as $m ) {
					if ( (int) $m->owner_user_id !== $owner || 'out' !== $m->direction || 'external' !== $m->class ) {
						continue;
					}
					if ( null !== $to ) {
						$hit = false;
						foreach ( $this->parties as $p ) {
							if ( (int) $p->message_id === (int) $m->id && (int) $p->owner_user_id === $to[0] && 'to' === $p->role && strtolower( $p->addr ) === $to[1] ) {
								$hit = true;
							}
						}
						if ( ! $hit ) {
							continue;
						}
					}
					$rows[] = (object) array( 'id' => $m->id, 'subject' => $m->subject, 'body_clean_text' => $m->plain, 'received_at' => $m->received_at, 'score' => 1 );
				}
				return $rows;
			}
			if ( false !== strpos( $sql, 'ms_internet_message_id, has_attachments, body_enc, body_format' ) ) {   // mail received from a sender
				$args  = $q['args'];
				$owner = (int) array_shift( $args );
				$dir   = (string) array_shift( $args );
				$likes = array();
				while ( count( $args ) > 1 ) {
					$likes[] = strtolower( trim( stripslashes( (string) array_shift( $args ) ), '%' ) );
				}
				$rows = array();
				foreach ( $this->msgs as $m ) {
					if ( (int) $m->owner_user_id !== $owner || $m->direction !== $dir ) {
						continue;
					}
					$hit = empty( $likes );
					foreach ( $likes as $l ) {
						if ( false !== strpos( strtolower( $m->from_name ), $l ) || false !== strpos( strtolower( $m->from_addr ), $l ) ) {
							$hit = true;
						}
					}
					if ( $hit ) {
						$rows[] = $m;
					}
				}
				usort( $rows, function ( $a, $b ) { return strcmp( $b->received_at, $a->received_at ); } );
				return $rows;
			}
			return array();
		}

		/** One stored message (its body sealed as the inbox app stores it). */
		public function add( int $id, int $owner, string $dir, string $from_name, string $from_addr, string $subject, string $body, string $at, array $more = array() ): void {
			$this->msgs[] = (object) array_merge( array(
				'id' => $id, 'owner_user_id' => $owner, 'direction' => $dir, 'from_name' => $from_name, 'from_addr' => $from_addr, 'subject' => $subject,
				'body_enc' => ZIB_Crypto::encrypt( $body ), 'plain' => $body, 'body_format' => 'text', 'received_at' => $at, 'class' => 'external', 'folder' => 'inbox',
				'has_attachments' => 0, 'ms_internet_message_id' => '<m' . $id . '@example.com>', 'ms_conversation_id' => 'c' . $id,
			), $more );
		}

		public function party( int $mid, int $owner, string $role, string $name, string $addr, int $internal = 0 ): void {
			$this->parties[] = (object) array( 'message_id' => $mid, 'owner_user_id' => $owner, 'role' => $role, 'name' => $name, 'addr' => $addr, 'is_internal' => $internal );
		}

		public function drop( int $id ): void {
			$this->msgs = array_values( array_filter( $this->msgs, function ( $m ) use ( $id ) { return (int) $m->id !== $id; } ) );
		}
	}
}
