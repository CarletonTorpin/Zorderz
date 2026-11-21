<?php
/**
 * Surveys: a customer already e-mailed is not e-mailed again unless a person asks for it.
 *
 * The real send path (ZSV_Dashboard::send_one) on stand-ins: an in-memory survey table with MySQL
 * named locks owned by a worker, a mail hand-off that records what left, and a CRM that keeps its
 * notes. Neutral data only; nothing is sent anywhere.
 *
 * NOT PROVEN HERE: two real database connections racing (the lock is modelled: held by the other
 * worker means not granted), and a real mail service.
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( $k, $v, $autoload = null ) { $GLOBALS['zdz_test_options'][ $k ] = $v; return true; }
}
if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( $k ) { unset( $GLOBALS['zdz_test_options'][ $k ] ); return true; }
}
if ( ! function_exists( 'wp_cache_delete' ) ) {
	function wp_cache_delete( ...$a ) { return true; }
}
if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id() { return (int) ( $GLOBALS['zdz_test_current_user'] ?? 0 ); }
}
if ( ! function_exists( 'maybe_unserialize' ) ) {
	function maybe_unserialize( $v ) {
		if ( is_string( $v ) ) {
			$u = @unserialize( $v, array( 'allowed_classes' => false ) );   // sentinel:allow the test stand-in for WordPress's maybe_unserialize(); no objects
			if ( false !== $u || 'b:0;' === $v ) {
				return $u;
			}
		}
		return $v;
	}
}
if ( ! function_exists( 'wp_date' ) ) {
	function wp_date( $f, $ts = null ) { return gmdate( $f, null === $ts ? time() : (int) $ts ); }
}
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
}
if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( $s, $d = null ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
}
if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $s ) { return (string) $s; }
}
if ( ! function_exists( 'zsv_usage' ) ) {
	function zsv_usage( string $key, $ref = 0, array $opts = array() ): void { $GLOBALS['zsv210_usage'][] = array( $key, (int) $ref ); }
}

$zsv210 = dirname( __DIR__, 2 ) . '/zorderz-apps/apps/surveys/includes/';
require_once $zsv210 . 'class-zsv-db.php';
require_once $zsv210 . 'class-zsv-settings.php';
require_once $zsv210 . 'class-zsv-budget.php';
require_once $zsv210 . 'class-zsv-owed.php';
require_once $zsv210 . 'class-zsv-note-reader.php';
require_once $zsv210 . 'class-zsv-send-once.php';
require_once $zsv210 . 'class-zsv-survey-manager.php';
require_once $zsv210 . 'class-zsv-dashboard.php';

/**
 * The survey table in memory, with MySQL named locks. A lock belongs to a worker ($proc); one held
 * by another worker is not granted (no waiting is modelled). Options are "the database" behind
 * $GLOBALS['zdz_test_options'].
 */
if ( ! class_exists( 'ZSV210_Leads_DB' ) ) {
	class ZSV210_Leads_DB {
		public $prefix     = 'wp_';
		public $options    = 'wp_options';
		public $last_error = '';
		public $rows       = array();
		public $locks      = array();   // lock name => worker
		public $proc       = 'A';
		public $lock_null  = false;     // the database does not answer GET_LOCK
		public $lock_asks  = array();   // every lock name asked for
		public $fail_writes = 0;        // how many of the next row writes fail
		public $row_gone   = false;     // the fresh read under the lock finds nothing
		public $meanwhile  = null;      // runs once, at the first row read of a request
		public $at_write   = null;      // runs at each row write: ( id )

		public function prepare( $q, ...$a ) { return array( 'q' => $q, 'a' => $a ); }

		public function get_var( $p ) {
			$q = is_array( $p ) ? $p['q'] : (string) $p;
			$a = is_array( $p ) ? $p['a'] : array();
			if ( false !== strpos( $q, 'GET_LOCK(' ) ) {
				$name              = (string) $a[0];
				$this->lock_asks[] = $name;
				if ( $this->lock_null && 0 === strpos( $name, 'zsv_send_' ) ) {
					return null;
				}
				$owner = $this->locks[ $name ] ?? null;
				if ( null !== $owner && $owner !== $this->proc ) {
					return '0';
				}
				$this->locks[ $name ] = $this->proc;
				return '1';
			}
			if ( false !== strpos( $q, 'RELEASE_LOCK(' ) ) {
				return (string) $this->release( (string) $a[0] );
			}
			if ( false !== strpos( $q, 'SELECT option_value FROM' ) ) {
				$n = (string) $a[0];
				return array_key_exists( $n, (array) ( $GLOBALS['zdz_test_options'] ?? array() ) ) ? serialize( $GLOBALS['zdz_test_options'][ $n ] ) : null;
			}
			if ( false !== strpos( $q, 'SELECT crm_status FROM' ) ) {
				return $this->rows[ (int) $a[0] ]['crm_status'] ?? null;
			}
			return null;
		}

		private function release( string $name ): int {
			if ( ( $this->locks[ $name ] ?? null ) === $this->proc ) {
				unset( $this->locks[ $name ] );
				return 1;
			}
			return 0;
		}

		public function get_row( $p, $out = null ) {
			$q  = is_array( $p ) ? $p['q'] : (string) $p;
			$id = (int) ( is_array( $p ) ? ( $p['a'][0] ?? 0 ) : 0 );
			if ( false !== strpos( $q, 'SELECT email_sent_at, email_send_log' ) ) {
				if ( $this->row_gone || ! isset( $this->rows[ $id ] ) ) {
					return null;
				}
				return array( 'email_sent_at' => $this->rows[ $id ]['email_sent_at'], 'email_send_log' => $this->rows[ $id ]['email_send_log'] );
			}
			$row = $this->rows[ $id ] ?? null;   // the copy this request works from…
			if ( $this->meanwhile ) {
				$f               = $this->meanwhile;
				$this->meanwhile = null;
				$f();                             // …and what another request does right after it was read
			}
			return $row;
		}

		public function get_results( $p, $out = null ) { return array(); }

		public function query( $p ) {
			$q = is_array( $p ) ? $p['q'] : (string) $p;
			if ( false !== strpos( $q, 'RELEASE_LOCK(' ) ) {
				return $this->release( (string) $p['a'][0] );
			}
			return 1;
		}

		public function update( $table, $data, $where, $f = null, $wf = null ) {
			$id = (int) ( $where['id'] ?? 0 );
			if ( isset( $data['email_sent_at'] ) ) {
				if ( $this->at_write ) {
					call_user_func( $this->at_write, $id );
				}
				if ( $this->fail_writes > 0 ) {
					$this->fail_writes--;
					return false;
				}
			}
			if ( ! isset( $this->rows[ $id ] ) ) {
				return 0;
			}
			$this->rows[ $id ] = array_merge( $this->rows[ $id ], $data );
			return 1;
		}

		/** The send locks held right now, by any worker. */
		public function send_locks(): array {
			return array_values( array_filter( array_keys( $this->locks ), function ( $k ) { return 0 === strpos( (string) $k, 'zsv_send_' ); } ) );
		}
	}
}

/** A CRM that closes what it is asked to close and keeps the notes it is given, per lead. */
if ( ! class_exists( 'ZSV210_CRM' ) ) {
	class ZSV210_CRM {
		public $notes  = array();   // crm lead id => [ body, … ]
		public $closed = array();
		public function resolve_won_outcome() { return array( 'id' => 5, 'name' => 'Won' ); }
		public function close_lead_by_outcome( $lead_id, $outcome_id, $note = '' ) {
			$this->closed[ (int) $lead_id ] = true;
			return array( 'closed' => true, 'verified' => true, 'status' => 1 );
		}
		public function add_note( $a ) {
			$this->notes[ (int) $a['entity']['id'] ][] = (string) $a['note']['body'];
			return array( 'id' => 1 );
		}
		public function get_activities( $q ) { return array(); }
		public function get_lead( $id, $rev = '0' ) { return array( 'status' => empty( $this->closed[ (int) $id ] ) ? 0 : 1 ); }
	}
}

/** The manager, with the mail hand-off recorded instead of made. */
if ( ! class_exists( 'ZSV210_Manager' ) ) {
	class ZSV210_Manager extends ZSV_Survey_Manager {
		public $mails   = array();
		public $mail_ok = true;
		public $during  = null;   // what another request does while this e-mail is on its way
		protected function deliver( string $to, string $subject, string $body, array $headers ): bool {
			if ( ! $this->mail_ok ) {
				return false;
			}
			if ( $this->during ) {
				$f            = $this->during;
				$this->during = null;
				$f();
			}
			$this->mails[] = $to;
			return true;
		}
	}
}

final class SurveysSendOnceTest extends TestCase {

	private $prev_db;
	private $prev_log;
	/** @var ZSV210_Leads_DB */
	private $db;
	/** @var ZSV210_CRM */
	private $crm;
	/** @var ZSV210_Manager */
	private $mgr;

	protected function setUp(): void {
		$this->prev_db   = $GLOBALS['wpdb'] ?? null;
		$this->db        = new ZSV210_Leads_DB();
		$GLOBALS['wpdb'] = $this->db;
		$GLOBALS['zdz_test_options'] = array(
			'zsv_review_url_custom' => 'https://example.com/review',
			'zsv_review_routing'    => 'custom_only',
			'admin_email'           => 'office@example.com',
		);
		$GLOBALS['zsv210_usage'] = array();
		if ( class_exists( 'ZDZ_Business_Profile' ) && method_exists( 'ZDZ_Business_Profile', 'flush' ) ) {
			ZDZ_Business_Profile::flush();
		}
		ZSV_Budget::close();
		ZSV_Owed::$now = null;
		$this->crm = new ZSV210_CRM();
		$this->mgr = new ZSV210_Manager( new stdClass(), $this->crm );
		$this->prev_log = null;
	}

	protected function tearDown(): void {
		ZSV_Budget::close();
		$GLOBALS['wpdb'] = $this->prev_db;
		unset( $GLOBALS['zdz_test_options'], $GLOBALS['zdz_test_user_roles'], $GLOBALS['zdz_test_user_caps'], $GLOBALS['zdz_test_current_user'], $GLOBALS['zsv210_usage'] );
		if ( class_exists( 'ZDZ_Business_Profile' ) && method_exists( 'ZDZ_Business_Profile', 'flush' ) ) {
			ZDZ_Business_Profile::flush();
		}
	}

	private function row( int $id, array $more = array() ): array {
		return array_merge(
			array(
				'id'              => $id,
				'first_name'      => 'Riley',
				'last_name'       => 'Example ' . $id,
				'email'           => 'riley' . $id . '@example.com',
				'operator_status' => 'satisfied',
				'crm_lead_id'     => (string) ( 5100 + $id ),
				'crm_status'      => 'Open',
				'email_sent_at'   => null,
				'email_send_log'  => null,
				'review_left'     => 0,
			),
			$more
		);
	}

	private function seed( array ...$rows ): void {
		foreach ( $rows as $r ) {
			$this->db->rows[ $r['id'] ] = $r;
		}
	}

	private function send( int $id, bool $again = false ): array {
		return ZSV_Dashboard::send_one( $this->mgr, $id, $again );
	}

	private function mails(): int {
		return count( $this->mgr->mails );
	}

	private function log_of( int $id ): array {
		return ZSV_Send_Once::send_log( $this->db->rows[ $id ] );
	}

	/** Run $fn and return what it wrote to the error log. */
	private function logged( callable $fn ): string {
		$tmp  = tempnam( sys_get_temp_dir(), 'zsv210-' );
		$prev = ini_set( 'error_log', $tmp );
		try {
			$fn();
		} finally {
			ini_set( 'error_log', false === $prev ? '/dev/null' : $prev );
		}
		$txt = (string) file_get_contents( $tmp );
		unlink( $tmp );
		return $txt;
	}

	/* ── asked for twice by number ─────────────────────────────────────────────────── */

	public function test_the_same_customer_asked_for_twice_is_emailed_once(): void {
		$this->seed( $this->row( 1 ) );
		$a = $this->send( 1 );
		$this->assertSame( 'sent', $a['result'] );
		$this->assertSame( 1, $a['send_no'] );
		$this->assertSame( 1, $this->mails() );

		$b = $this->send( 1 );
		$this->assertSame( 1, $this->mails(), 'the second request sends nothing' );
		$this->assertSame( 'skipped', $b['result'] );
		$this->assertSame( 'already_invited', $b['reason'] );
		$this->assertTrue( $b['already_emailed'], 'marked for the page' );
		$this->assertSame( $this->db->rows[1]['email_sent_at'], $b['emailed_at'], 'with the time' );
		$this->assertCount( 1, $this->log_of( 1 ), 'the send log still holds one time' );
		$this->assertCount( 1, $this->crm->notes[5101], 'the CRM got one note, not two' );
		$this->assertSame( array( array( 'survey_emails_sent', 1 ) ), $GLOBALS['zsv210_usage'], 'one staff action counted' );
	}

	public function test_the_refusal_is_logged_by_number_only(): void {
		$this->seed( $this->row( 1 ) );
		$a    = $this->send( 1 );
		$b    = $this->send( 1 );
		$line = ZSV_Dashboard::log_press( array( $a, $b ) );
		$this->assertStringContainsString( 'sent 1, skipped 1 (#1 already_invited)', $line );
		$this->assertStringNotContainsString( '@', $line );
		$this->assertStringNotContainsString( 'Riley', $line );
	}

	/* ── a person says "send again" ────────────────────────────────────────────────── */

	public function test_send_again_sends_a_second_email_and_says_which_send_it_was(): void {
		$this->seed( $this->row( 3 ) );
		$this->send( 3 );
		$b   = null;
		$log = $this->logged( function () use ( &$b ) { $b = $this->send( 3, true ); } );
		$this->assertSame( 'sent', $b['result'] );
		$this->assertSame( 2, $this->mails(), 'the second e-mail goes' );
		$this->assertCount( 2, $this->log_of( 3 ), 'the send log holds both times' );
		$this->assertSame( 2, $b['send_no'], 'the result says it was send #2' );
		$this->assertCount( 2, $this->crm->notes[5103] );
		$this->assertStringContainsString( 'send #2', $this->crm->notes[5103][1], 'the second CRM note says which send it was' );
		$this->assertStringNotContainsString( 'send #2', $this->crm->notes[5103][0] );
		$this->assertStringContainsString( '#3 e-mailed AGAIN because a person asked (send #2)', $log );
		$this->assertStringNotContainsString( '@example.com', $log );
	}

	public function test_send_again_counts_only_for_the_customers_it_names(): void {
		$this->seed( $this->row( 4 ), $this->row( 5 ), $this->row( 6 ) );
		$this->send( 4 );
		$this->send( 5 );
		// One request for both, a person asked again for #5 only.
		$again = ZSV_Send_Once::named_again( '5', array( 4, 5 ) );
		$r4    = $this->send( 4, in_array( 4, $again, true ) );
		$r5    = $this->send( 5, in_array( 5, $again, true ) );
		$this->assertSame( 'skipped', $r4['result'] );
		$this->assertSame( 'sent', $r5['result'] );
		$this->assertSame( 3, $this->mails() );
		$this->assertCount( 1, $this->log_of( 4 ) );
		$this->assertCount( 2, $this->log_of( 5 ) );

		// Naming a customer never e-mailed is an ordinary first send.
		$r6  = null;
		$log = $this->logged( function () use ( &$r6 ) { $r6 = $this->send( 6, true ); } );
		$this->assertSame( 'sent', $r6['result'] );
		$this->assertSame( 1, $r6['send_no'] );
		$this->assertStringNotContainsString( 'AGAIN', $log );
	}

	public function test_which_customers_a_request_may_send_again(): void {
		$this->assertSame( array(), ZSV_Send_Once::named_again( '17', array( 16 ) ), 'a customer who is not in the request means nothing' );
		$this->assertSame( array( 16 ), ZSV_Send_Once::named_again( '16', array( 16 ) ) );
		$this->assertSame( array( 17 ), ZSV_Send_Once::named_again( 'x,0,-17, 17abc', array( 17 ) ), 'whole numbers only; x and 0 are dropped' );
		$this->assertSame( array(), ZSV_Send_Once::named_again( '16,17', array() ), 'a request that names no customer never sends again' );
		$this->assertSame( array( 16 ), ZSV_Send_Once::named_again( array( '16', array( 3 ), null ), array( 16, 3 ) ), 'a list is read the same way' );
		$this->assertSame( array(), ZSV_Send_Once::named_again( null, array( 16 ) ) );
	}

	public function test_only_an_administrator_can_say_send_again(): void {
		$GLOBALS['zdz_test_user_roles'] = array( 7 => array( 'administrator' ), 8 => array( 'zdz_operator' ) );
		$this->assertSame( array( 16 ), ZSV_Dashboard::asked_again( array( 16 ), '16', 7 ) );
		$this->assertSame( array(), ZSV_Dashboard::asked_again( array( 16 ), '16', 8 ), 'the operator\'s request is read as an ordinary send' );
		$this->assertSame( array(), ZSV_Dashboard::asked_again( array( 16 ), '16', 0 ) );
		$this->assertSame( array(), ZSV_Dashboard::asked_again( array( 16 ), '', 7 ) );
		$this->assertSame( array(), ZSV_Dashboard::asked_again( array( 16 ), '99', 7 ) );
	}

	/* ── what counts as e-mailed ───────────────────────────────────────────────────── */

	public function test_when_a_customer_was_emailed(): void {
		$this->assertSame( '', ZSV_Send_Once::emailed_at( array() ) );
		$this->assertSame( '2026-03-02 10:00:00', ZSV_Send_Once::emailed_at( array( 'email_sent_at' => '2026-03-02 10:00:00' ) ) );
		$this->assertSame( '2026-03-03 08:00:00', ZSV_Send_Once::emailed_at( array( 'email_sent_at' => null, 'email_send_log' => '["2026-03-02 13:13:00","2026-03-03 08:00:00"]' ) ), 'the newest time in the log' );
		$this->assertSame( '', ZSV_Send_Once::emailed_at( array( 'email_sent_at' => '0000-00-00 00:00:00' ) ), 'the empty date is not a send' );
		$this->assertSame( '2026-03-02 13:13:00', ZSV_Send_Once::emailed_at( array( 'email_sent_at' => '0000-00-00 00:00:00', 'email_send_log' => '["2026-03-02 13:13:00"]' ) ) );
		$this->assertSame( '', ZSV_Send_Once::emailed_at( array( 'email_send_log' => 'not a log' ) ) );
		$this->assertSame( '', ZSV_Send_Once::emailed_at( array( 'email_send_log' => '[""]' ) ) );
	}

	public function test_a_row_whose_time_was_lost_but_whose_log_has_one_counts_as_emailed(): void {
		$this->seed( $this->row( 7, array( 'email_send_log' => '["2026-03-02 13:13:00"]' ) ) );
		$b = $this->send( 7 );
		$this->assertSame( 0, $this->mails() );
		$this->assertSame( 'already_invited', $b['reason'] );
		$this->assertSame( '2026-03-02 13:13:00', $b['emailed_at'], 'the reason gives the time from the log' );
	}

	public function test_the_empty_date_is_not_a_send(): void {
		$this->seed( $this->row( 27, array( 'email_sent_at' => '0000-00-00 00:00:00' ) ) );
		$this->assertSame( 'sent', $this->send( 27 )['result'] );
		$this->assertSame( 1, $this->mails() );
	}

	public function test_a_send_made_before_send_logs_were_kept_is_send_one_of_two(): void {
		$this->seed( $this->row( 28, array( 'email_sent_at' => '2026-02-01 09:00:00', 'crm_status' => 'Won' ) ) );
		$this->assertSame( 'skipped', $this->send( 28 )['result'] );
		$b = $this->send( 28, true );
		$this->assertSame( 2, $b['send_no'] );
		$this->assertSame( '2026-02-01 09:00:00', $this->log_of( 28 )[0], 'the earlier time is kept in the log' );
		$this->assertCount( 2, $this->log_of( 28 ) );
	}

	/* ── the fresh read under the lock ─────────────────────────────────────────────── */

	public function test_a_copy_read_before_another_request_sent_is_not_trusted(): void {
		$this->seed( $this->row( 8 ) );
		// The copy read at the top of this request says "never e-mailed"; another request's write lands right after it.
		$this->db->meanwhile = function () {
			$this->db->rows[8]['email_sent_at']  = '2026-03-16 09:00:00';
			$this->db->rows[8]['email_send_log'] = '["2026-03-16 09:00:00"]';
		};
		$b = $this->send( 8 );
		$this->assertSame( 0, $this->mails(), 'the row is read again before the e-mail' );
		$this->assertTrue( $b['already_emailed'] );
		$this->assertSame( array( '2026-03-16 09:00:00' ), $this->log_of( 8 ), 'the other request\'s time is still the only one' );
	}

	public function test_a_row_that_cannot_be_read_back_is_not_sent_on_a_guess(): void {
		$this->seed( $this->row( 26 ) );
		$this->db->row_gone = true;
		$b = $this->send( 26 );
		$this->assertSame( 'skipped', $b['result'] );
		$this->assertSame( 'row_unreadable', $b['reason'] );
		$this->assertSame( 0, $this->mails() );
		$this->assertSame( array(), $this->db->send_locks(), 'and its lock is let go' );
		$this->db->row_gone = false;
		$this->assertSame( 'sent', $this->send( 26 )['result'] );
	}

	/* ── the lock ──────────────────────────────────────────────────────────────────── */

	public function test_two_requests_for_one_customer_at_the_same_moment(): void {
		$this->seed( $this->row( 9 ) );
		$name                     = ZSV_Send_Once::lock_name( 9 );
		$this->db->locks[ $name ] = 'B';   // the other worker is in the middle of its send
		$b = $this->send( 9 );
		$this->assertSame( 0, $this->mails(), 'this request sends nothing' );
		$this->assertSame( 'send_in_progress', $b['reason'] );
		$this->assertTrue( $b['in_progress'], 'marked for the page' );
		$this->assertSame( 'B', $this->db->locks[ $name ], 'the other worker\'s lock is untouched' );
		unset( $this->db->locks[ $name ] );
		$this->assertSame( 'sent', $this->send( 9 )['result'], 'once the other request is done and wrote nothing, the customer can be sent' );
	}

	public function test_a_request_that_arrives_while_the_email_is_on_its_way_sends_nothing(): void {
		$this->seed( $this->row( 24 ) );
		$other             = null;
		$second            = new ZSV210_Manager( new stdClass(), $this->crm );
		$this->mgr->during = function () use ( &$other, $second ) {
			$this->db->proc = 'B';
			$other          = ZSV_Dashboard::send_one( $second, 24 );
			$this->db->proc = 'A';
		};
		$a = $this->send( 24 );
		$this->assertSame( 'sent', $a['result'] );
		$this->assertSame( 1, $this->mails() );
		$this->assertSame( array(), $second->mails, 'the request that arrived during it sends nothing' );
		$this->assertTrue( $other['in_progress'] );
		$this->assertCount( 1, $this->log_of( 24 ) );
		$this->assertCount( 1, $this->crm->notes[5124] );
	}

	public function test_the_lock_is_still_held_when_the_time_is_written(): void {
		$this->seed( $this->row( 25 ) );
		$held               = 'never written';
		$this->db->at_write = function ( $id ) use ( &$held ) { $held = $this->db->locks[ ZSV_Send_Once::lock_name( $id ) ] ?? null; };
		$this->send( 25 );
		$this->assertSame( 'A', $held );
		$this->assertSame( array(), $this->db->send_locks(), 'and let go before the CRM work is over' );
	}

	public function test_when_the_database_does_not_answer_the_lock_nothing_is_sent(): void {
		$this->seed( $this->row( 19 ) );
		$this->db->lock_null = true;
		$b   = null;
		$log = $this->logged( function () use ( &$b ) { $b = $this->send( 19 ); } );
		$this->assertSame( 0, $this->mails(), 'only-once cannot be promised without the lock' );
		$this->assertSame( 'lock_unavailable', $b['reason'] );
		$this->assertArrayNotHasKey( 'in_progress', $b, 'it is not called "running"' );
		$this->assertStringContainsString( '#19 NOT sent: the database did not answer the send lock', $log );
		$this->assertStringNotContainsString( '@example.com', $log );
		$this->db->lock_null = false;
		$this->assertSame( 'sent', $this->send( 19 )['result'], 'once the database answers again, the customer is sent' );
	}

	public function test_the_lock_is_let_go_on_every_way_out(): void {
		$this->seed( $this->row( 10 ), $this->row( 11, array( 'crm_status' => 'Lost' ) ), $this->row( 12 ), $this->row( 13, array( 'operator_status' => 'left_vm' ) ) );
		$this->send( 10 );
		$this->assertSame( array(), $this->db->send_locks(), 'after a send' );
		$this->send( 10 );
		$this->assertSame( array(), $this->db->send_locks(), 'after an "already e-mailed" skip' );

		$b = $this->send( 11 );
		$this->assertSame( 'crm_closed', $b['reason'], 'a lead closed as lost in the CRM is not e-mailed' );
		$this->assertSame( array(), $this->db->send_locks() );

		$this->mgr->mail_ok = false;
		$b = $this->send( 12 );
		$this->assertSame( 'failed', $b['result'], 'the mail service refuses' );
		$this->assertSame( array(), $this->db->send_locks() );
		$this->assertNull( $this->db->rows[12]['email_sent_at'], 'the row is not marked e-mailed' );
		$this->mgr->mail_ok = true;
		$this->assertSame( 'sent', $this->send( 12 )['result'], 'and that customer can be sent afterwards' );

		$this->db->lock_asks = array();
		$b = $this->send( 13 );
		$this->assertSame( 'status_holds_left_vm', $b['reason'] );
		$this->assertSame( array(), $this->db->lock_asks, 'a call-outcome skip never takes the lock' );
		$this->assertSame( 2, $this->mails() );
	}

	public function test_different_customers_do_not_wait_on_each_other(): void {
		$this->seed( $this->row( 14 ), $this->row( 15 ) );
		$this->db->locks[ ZSV_Send_Once::lock_name( 14 ) ] = 'B';
		$this->assertSame( 'sent', $this->send( 15 )['result'] );
		$this->assertNotSame( ZSV_Send_Once::lock_name( 14 ), ZSV_Send_Once::lock_name( 15 ) );
		$this->assertLessThanOrEqual( 64, strlen( ZSV_Send_Once::lock_name( PHP_INT_MAX ) ), 'inside MySQL\'s 64 characters' );
	}

	/* ── the e-mail went but the row would not take its time ───────────────────────── */

	public function test_a_failed_write_is_tried_once_more(): void {
		$this->seed( $this->row( 20 ) );
		$this->db->fail_writes = 1;
		$a = $this->send( 20 );
		$this->assertSame( 'sent', $a['result'] );
		$this->assertNotEmpty( $this->db->rows[20]['email_sent_at'], 'one failure costs nothing' );
		$this->assertSame( array(), ZSV_Send_Once::unrecorded() );
		$this->assertArrayNotHasKey( 'unrecorded', $a );
	}

	public function test_an_email_whose_time_the_row_would_not_take_is_remembered_apart(): void {
		$this->seed( $this->row( 21 ), $this->row( 23 ) );
		$this->db->fail_writes = 2;
		$a   = null;
		$log = $this->logged( function () use ( &$a ) { $a = $this->send( 21 ); } );
		$this->assertSame( 'sent', $a['result'] );
		$this->assertSame( 1, $this->mails() );
		$this->assertNull( $this->db->rows[21]['email_sent_at'], 'both tries failed: the row has no time' );
		$this->assertArrayHasKey( 21, ZSV_Send_Once::unrecorded(), 'the customer is remembered as e-mailed, apart from the row' );
		$this->assertTrue( $a['unrecorded'], 'the result marks it for the page' );
		$this->assertStringContainsString( '#21 WAS E-MAILED but the time could not be written to its row', $log );
		$this->assertStringNotContainsString( '@example.com', $log );
		$this->assertSame( array(), $this->db->send_locks(), 'the lock is let go' );

		$b = $this->send( 21 );
		$this->assertSame( 1, $this->mails(), 'the NEXT press is refused: still one e-mail' );
		$this->assertTrue( $b['already_emailed'] );
		$this->assertSame( ZSV_Send_Once::unrecorded()[21], $b['emailed_at'] );
		$this->assertTrue( ZSV_Dashboard::recent_row( $this->db->rows[21], ZSV_Send_Once::unrecorded() )['invited'], 'and the list does not offer Send for them' );

		$c = $this->send( 21, true );
		$this->assertSame( 'sent', $c['result'], 'a person can still say "send again"' );
		$this->assertSame( 2, $c['send_no'], 'and it goes as send #2' );
		$this->assertCount( 2, $this->log_of( 21 ), 'that write worked, so the row carries both times' );
		$this->assertArrayNotHasKey( 21, ZSV_Send_Once::unrecorded(), 'and the customer is no longer remembered apart' );

		$this->assertSame( 'sent', $this->send( 23 )['result'], 'another customer is not held back by it' );
	}

	public function test_the_remembered_list_keeps_the_newest(): void {
		for ( $i = 1; $i <= ZSV_Send_Once::UNRECORDED_KEEP + 5; $i++ ) {
			ZSV_Send_Once::unrecorded_add( $i, '2026-03-01 10:00:00' );
		}
		$l = ZSV_Send_Once::unrecorded();
		$this->assertCount( ZSV_Send_Once::UNRECORDED_KEEP, $l );
		$this->assertArrayNotHasKey( 1, $l );
		$this->assertArrayHasKey( ZSV_Send_Once::UNRECORDED_KEEP + 5, $l );
		ZSV_Send_Once::unrecorded_forget( ZSV_Send_Once::UNRECORDED_KEEP + 5 );
		$this->assertArrayNotHasKey( ZSV_Send_Once::UNRECORDED_KEEP + 5, ZSV_Send_Once::unrecorded() );
	}

	/* ── what the list is told ─────────────────────────────────────────────────────── */

	public function test_the_list_row_says_who_has_been_handled(): void {
		$r = ZSV_Dashboard::recent_row( $this->row( 30, array( 'operator_notes' => " Happy, send the link \n", 'work_description' => 'Annual service', 'city' => 'Springfield' ) ) );
		$this->assertFalse( $r['invited'] );
		$this->assertFalse( $r['reviewed'] );
		$this->assertFalse( $r['closed'] );
		$this->assertSame( 'Happy, send the link', $r['notes'] );
		$this->assertSame( 'Annual service', $r['work'] );
		$this->assertSame( 'Riley Example 30', $r['name'] );

		$this->assertTrue( ZSV_Dashboard::recent_row( $this->row( 31, array( 'email_send_log' => '["2026-03-02 13:13:00"]' ) ) )['invited'], 'a time only in the send log counts' );
		$this->assertFalse( ZSV_Dashboard::recent_row( $this->row( 32, array( 'email_sent_at' => '0000-00-00 00:00:00' ) ) )['invited'] );
		$this->assertTrue( ZSV_Dashboard::recent_row( $this->row( 33, array( 'review_left' => 1 ) ) )['reviewed'] );
		foreach ( array( 'Won', 'lost', 'Cancelled', 'deleted' ) as $st ) {
			$this->assertTrue( ZSV_Dashboard::recent_row( $this->row( 34, array( 'crm_status' => $st ) ) )['closed'], $st );
		}
		$this->assertFalse( ZSV_Dashboard::recent_row( $this->row( 35, array( 'crm_status' => null ) ) )['closed'] );
	}

	public function test_who_may_see_survey_customers(): void {
		$GLOBALS['zdz_test_user_roles'] = array( 7 => array( 'administrator' ), 8 => array( 'subscriber' ) );
		$this->assertTrue( ZSV_Dashboard::can_see( 7 ) );
		$this->assertFalse( ZSV_Dashboard::can_see( 0 ), 'nobody signed in' );
		if ( ! class_exists( 'ZDZ_Data_Permissions' ) ) {
			$this->assertFalse( ZSV_Dashboard::can_see( 8 ), 'not an administrator, and no permission to see other people\'s customers' );
		}
	}

	/* ── nothing is left open ──────────────────────────────────────────────────────── */

	public function test_no_budget_and_no_lock_are_left_behind(): void {
		$this->seed( $this->row( 40 ) );
		$this->send( 40 );
		$this->send( 40 );
		$this->send( 40, true );
		$this->assertFalse( ZSV_Budget::is_open() );
		$this->assertSame( array(), $this->db->locks, 'no lock of any kind is held' );
	}
}
