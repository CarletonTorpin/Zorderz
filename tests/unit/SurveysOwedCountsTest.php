<?php
/**
 * Surveys: the time budget, owed CRM notes and Won marks, survey.counts, review dates, the
 * voicemail rules and the one-line send log. Neutral data only.
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
if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( $s ) { return trim( strip_tags( (string) $s ) ); }
}

/** A $wpdb stand-in: named locks always granted; nothing else is read here. */
if ( ! class_exists( 'ZDZ_Test_Surveys_DB' ) ) {
	class ZDZ_Test_Surveys_DB {
		public $prefix     = 'wp_';
		public $last_error = '';
		public $locks      = 0;
		public function prepare( $q, ...$a ) { return $q . ' ' . json_encode( $a ); }
		public function get_var( $q ) {
			if ( false !== strpos( $q, 'GET_LOCK' ) ) { $this->locks++; return '1'; }
			return '1';
		}
		public function get_row( $q, $o = null ) { return null; }
		public function get_results( $q, $o = null ) { return array(); }
		public function query( $q ) { return 1; }
	}
}

/** A CRM stand-in: notes it was given, what it answers for activities and a lead's status. */
if ( ! class_exists( 'ZDZ_Test_Surveys_CRM' ) ) {
	class ZDZ_Test_Surveys_CRM {
		public $notes      = array();
		public $note_ok    = true;
		public $activities = array();
		public $status     = 0;
		public function add_note( $a ) {
			if ( ! $this->note_ok ) { return null; }
			$this->notes[] = $a['note']['body'];
			return array( 'id' => count( $this->notes ) );
		}
		public function get_activities( $q ) { return $this->activities; }
		public function get_lead( $id, $rev = '0' ) { return null === $this->status ? null : array( 'status' => $this->status ); }
	}
}

$zsv = dirname( __DIR__, 2 ) . '/zorderz-apps/apps/surveys/includes/';
require_once $zsv . 'class-zsv-db.php';
require_once $zsv . 'class-zsv-settings.php';
require_once $zsv . 'class-zsv-budget.php';
require_once $zsv . 'class-zsv-owed.php';
require_once $zsv . 'class-zsv-counts.php';
require_once $zsv . 'class-zsv-survey-manager.php';
require_once $zsv . 'class-zsv-review-checker.php';
require_once $zsv . 'class-zsv-dashboard.php';

final class SurveysOwedCountsTest extends TestCase {

	private $prev_db;

	protected function setUp(): void {
		$this->prev_db    = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb']  = new ZDZ_Test_Surveys_DB();
		$GLOBALS['zdz_test_options'] = array();
		ZSV_Owed::$now   = 1800000000;
		ZSV_Counts::$now = null;
		ZSV_Budget::close();
	}

	protected function tearDown(): void {
		ZSV_Budget::close();
		ZSV_Owed::$now   = null;
		ZSV_Counts::$now = null;
		$GLOBALS['wpdb'] = $this->prev_db;
		unset( $GLOBALS['zdz_test_options'], $GLOBALS['zdz_test_user_roles'] );
	}

	private function manager( ZDZ_Test_Surveys_CRM $crm ): ZSV_Survey_Manager {
		return new ZSV_Survey_Manager( new stdClass(), $crm );
	}

	/* ── the budget ────────────────────────────────────────────────────────────────── */

	public function test_typical_cost_is_the_median_not_the_slowest_call(): void {
		$this->assertSame( ZSV_Budget::full_call(), ZSV_Budget::typical( array() ), 'no evidence: a full call' );
		$this->assertSame( 1.0, ZSV_Budget::typical( array( 1.0, 1.0, 6.0 ) ), 'one slow call does not price every later item' );
		$this->assertSame( ZSV_Budget::full_call(), ZSV_Budget::typical( array( 30.0, 30.0, 30.0 ) ), 'never above a full call' );
		$this->assertSame( 2 * 1.0 + ZSV_Budget::full_call(), ZSV_Budget::item_reserve( 3, array( 1.0, 1.0, 6.0 ) ), 'the last call is reserved in full' );
	}

	public function test_first_item_always_starts_then_the_reserve_must_fit(): void {
		ZSV_Budget::open( 'test', 1.0 ); // raised to the floor of two full calls
		$this->assertTrue( ZSV_Budget::can_start( 50 ), 'no evidence yet: the first item starts (progress is guaranteed)' );
		ZSV_Budget::observe( 0.5 );
		$this->assertFalse( ZSV_Budget::can_start( 50 ), 'with evidence, a reserve beyond the budget does not start' );
		$this->assertTrue( ZSV_Budget::can_start( 1 ) );
		ZSV_Budget::close();
		$this->assertTrue( ZSV_Budget::can_start( 500 ), 'no budget open: interactive work is not limited here' );
	}

	/* ── owed notes ───────────────────────────────────────────────────────────────── */

	public function test_a_posted_note_leaves_the_store_and_carries_its_ref(): void {
		$crm = new ZDZ_Test_Surveys_CRM();
		$st  = $this->manager( $crm )->post_note_durably( 'reviewed', 41, 7, 'A neutral note' );
		$this->assertSame( 'posted', $st );
		$this->assertSame( 0, ZSV_Owed::count() );
		$this->assertMatchesRegularExpression( '/^A neutral note\nRef: zsv-[0-9a-f]{12}$/', $crm->notes[0] );
	}

	public function test_a_failed_note_is_kept_and_never_posted_blind_a_second_time(): void {
		$crm          = new ZDZ_Test_Surveys_CRM();
		$crm->note_ok = false;
		$m            = $this->manager( $crm );
		$this->assertSame( 'maybe', $m->post_note_durably( 'emailed', 41, 7, 'A neutral note' ) );
		$this->assertSame( 1, ZSV_Owed::count() );

		// Within the settle window: left alone.
		$crm->note_ok = true;
		$r = $m->drain_owed_notes();
		$this->assertSame( 1, $r['waiting'] );
		$this->assertSame( array(), $crm->notes );

		// Later, and the lead cannot be read: still never a blind second post.
		ZSV_Owed::$now += ZSV_Owed::MAYBE_SETTLE + 1;
		$crm->activities = null;
		$r = $m->drain_owed_notes();
		$this->assertSame( 1, $r['waiting'] );
		$this->assertSame( array(), $crm->notes );

		// The lead reads, and the Ref is already there (the earlier post had landed): forget it.
		$ref = array_key_first( ZSV_Owed::notes() );
		$crm->activities = array( array( 'note' => 'A neutral note Ref: ' . $ref ) );
		$r = $m->drain_owed_notes();
		$this->assertSame( 1, $r['already'] );
		$this->assertSame( 0, ZSV_Owed::count() );
		$this->assertSame( array(), $crm->notes, 'not posted twice' );
	}

	public function test_a_note_not_on_the_lead_is_posted_by_the_next_sweep(): void {
		$crm          = new ZDZ_Test_Surveys_CRM();
		$crm->note_ok = false;
		$m            = $this->manager( $crm );
		$m->post_note_durably( 'auto_close', 41, 7, 'A neutral close note' );
		ZSV_Owed::$now  += ZSV_Owed::MAYBE_SETTLE + 1;
		$crm->note_ok    = true;
		$crm->activities = array( array( 'note' => 'something else' ) );
		$r = $m->drain_owed_notes();
		$this->assertSame( 1, $r['posted'] );
		$this->assertSame( 0, ZSV_Owed::count() );
		$this->assertCount( 1, $crm->notes );
	}

	public function test_backoff_after_repeated_failures_and_old_entries_are_given_up_loudly(): void {
		$crm          = new ZDZ_Test_Surveys_CRM();
		$crm->note_ok = false;
		$m            = $this->manager( $crm );
		$m->post_note_durably( 'reviewed', 41, 7, 'note' );
		$ref = array_key_first( ZSV_Owed::notes() );
		for ( $i = 0; $i < ZSV_Owed::BACKOFF_AFTER; $i++ ) {
			ZSV_Owed::$now  += ZSV_Owed::MAYBE_SETTLE + 1;
			$crm->activities = array();
			$m->drain_owed_notes();
		}
		$e = ZSV_Owed::notes()[ $ref ];
		$this->assertGreaterThanOrEqual( ZSV_Owed::BACKOFF_AFTER, (int) $e['tries'] );
		$this->assertGreaterThan( ZSV_Owed::$now, (int) $e['next'], 'retried once a day from now on' );

		ZSV_Owed::$now += ( ZSV_Owed::MAX_AGE_DAYS + 1 ) * 86400;
		ZSV_Owed::owe( 'reviewed', 42, 8, 'another', ZSV_Owed::new_ref() ); // a write runs the cap
		$this->assertArrayNotHasKey( $ref, ZSV_Owed::notes() );
		$this->assertArrayHasKey( $ref, $GLOBALS['zdz_test_options'][ ZSV_Owed::GAVE_UP_OPTION ] );
		$this->assertArrayNotHasKey( 'body', $GLOBALS['zdz_test_options'][ ZSV_Owed::GAVE_UP_OPTION ][ $ref ], 'the given-up record keeps no note text' );
	}

	/* ── owed Won marks ───────────────────────────────────────────────────────────── */

	public function test_owed_won_mark_decisions(): void {
		$e = array( 'crm' => 41, 'since' => ZSV_Owed::$now );
		$this->assertSame( 'close', ZSV_Owed::won_decision( $e, 0 ), 'still open in the CRM: make the close' );
		$this->assertSame( 'settled', ZSV_Owed::won_decision( $e, 2 ), 'closed since (any outcome): never re-closed' );
		$this->assertSame( 'keep', ZSV_Owed::won_decision( $e, null ), 'unreadable: keep for the next sweep' );
		$old = array( 'crm' => 41, 'since' => ZSV_Owed::$now - ( ZSV_Owed::EMAILED_LOOKBACK_DAYS + 1 ) * 86400 );
		$this->assertSame( 'expired', ZSV_Owed::won_decision( $old, 0 ) );
	}

	public function test_owed_won_list(): void {
		ZSV_Owed::won_owed_add( 7, 41 );
		ZSV_Owed::won_owed_add( 0, 41 ); // never without a row
		$this->assertSame( array( 7 ), array_keys( ZSV_Owed::won_owed() ) );
		ZSV_Owed::won_owed_forget( 7 );
		$this->assertSame( array(), ZSV_Owed::won_owed() );
	}

	public function test_a_lead_closed_by_hand_since_is_settled_not_reclosed(): void {
		$crm         = new ZDZ_Test_Surveys_CRM();
		$crm->status = 3;
		ZSV_Owed::won_owed_add( 7, 41 );
		$r = $this->manager( $crm )->settle_owed( false );
		$this->assertSame( 1, $r['won']['settled'] );
		$this->assertSame( array(), ZSV_Owed::won_owed() );
	}

	/* ── survey.counts ────────────────────────────────────────────────────────────── */

	public function test_counts_refuse_signed_out_kiosk_and_unknown_tiers(): void {
		$this->assertSame( 'signed_out', ZSV_Counts::from_capability( array() )['reason'] );
		$this->assertSame( 'kiosk', ZSV_Counts::from_capability( array( 'user_id' => 5, 'is_kiosk' => true ) )['reason'] );
		$GLOBALS['zdz_test_user_roles'][6] = array( 'subscriber' );
		$r = ZSV_Counts::from_capability( array( 'user_id' => 6 ) );
		$this->assertFalse( $r['success'] );
		$this->assertSame( 'tier', $r['reason'] );
		$d = ZSV_Counts::descriptor();
		$this->assertFalse( $d['kiosk'] );
		$this->assertFalse( $d['side_effect'] );
	}

	public function test_month_window(): void {
		ZSV_Counts::$now = gmmktime( 12, 0, 0, 5, 15, 2026 );
		$w = ZSV_Counts::month_window( '' );
		$this->assertSame( '2026-05', $w['month'] );
		$this->assertSame( '2026-05-01 00:00:00', $w['from_local'] );
		$this->assertSame( '2026-06-01 00:00:00', $w['to_local'] );
		$this->assertSame( '2026-05-31', $w['to'] );
		$this->assertArrayHasKey( 'error', ZSV_Counts::month_window( '2026-13' ) );
		$this->assertArrayHasKey( 'error', ZSV_Counts::month_window( '2026-06' ), 'a month that has not started' );
		$this->assertArrayHasKey( 'error', ZSV_Counts::month_window( '2023-01' ), 'too far back' );
		$this->assertSame( '2024-02-29', ZSV_Counts::month_window( '2024-02' )['to'] );
	}

	public function test_a_failed_read_is_an_error_never_zeros(): void {
		ZSV_Counts::$now = gmmktime( 12, 0, 0, 5, 15, 2026 );
		$r = ZSV_Counts::month_counts( ZSV_Counts::month_window( '2026-04' ) );
		$this->assertFalse( $r['success'] );
		$this->assertArrayNotHasKey( 'counts', $r );
	}

	/* ── review dates and the recheck window, in the site's time ──────────────────── */

	public function test_review_date_is_written_in_the_site_time(): void {
		$tz = new DateTimeZone( 'America/New_York' );
		$this->assertSame( '2026-03-31 22:30:00', ZSV_Review_Checker::review_date_from_bridge( '2026-04-01T02:30:00+00:00', 'fallback', $tz ), 'a review late on the 31st stays in March' );
		$this->assertSame( '2026-04-01 00:00:00', ZSV_Review_Checker::review_date_from_bridge( '2026-04-01', 'fallback', $tz ), 'no zone: kept as given' );
		$this->assertSame( 'fallback', ZSV_Review_Checker::review_date_from_bridge( '', 'fallback', $tz ) );
		$this->assertSame( 'fallback', ZSV_Review_Checker::review_date_from_bridge( 'not a date', 'fallback', $tz ) );
		$this->assertSame( '2026-01-01 07:00:00', ZSV_Review_Checker::recheck_cutoff( gmmktime( 18, 0, 0, 1, 1, 2026 ), $tz ), 'six hours ago, in the site frame' );
	}

	/* ── call notes: a full voice mailbox, and a newer voicemail ──────────────────── */

	public function test_voicemail_full_is_not_reached_unless_the_note_says_they_were(): void {
		$m = $this->manager( new ZDZ_Test_Surveys_CRM() );
		$this->assertSame( 'left_vm', $m->parse_single_status( 'Called, VM full' ) );
		$this->assertSame( 'left_vm', $m->parse_single_status( 'mailbox is full, will call back' ), 'not an issue with the work' );
		$this->assertSame( 'left_vm', $m->parse_single_status( 'Voice mail full' ) );
		$this->assertSame( 'satisfied', $m->parse_single_status( 'VM full yesterday; reached today, happy with everything' ) );
	}

	public function test_a_newer_voicemail_outranks_an_older_unclear_note(): void {
		$m = $this->manager( new ZDZ_Test_Surveys_CRM() );
		$this->assertSame( 'left_vm', $m->parse_status_multi( array( '3/1/26 talked briefly, unclear', '3/5/26 left vm' ) )['status'] );
		$this->assertSame( 'needs_attention', $m->parse_status_multi( array( '3/1/26 left vm', '3/5/26 talked briefly, unclear' ) )['status'] );
		$this->assertSame( 'satisfied', $m->parse_status_multi( array( '3/1/26 happy, all good', '3/5/26 left vm' ) )['status'], 'a customer reached earlier stays reached' );
	}

	/* ── one log line per press: numbers and reason codes, never a name ───────────── */

	public function test_send_press_log_line(): void {
		$line = ZSV_Dashboard::log_press( array(
			array( 'id' => 11, 'result' => 'sent', 'crm' => 'won' ),
			array( 'id' => 12, 'result' => 'sent', 'crm' => 'owed' ),
			array( 'id' => 13, 'result' => 'skipped', 'reason' => 'status_holds_left_vm' ),
			array( 'id' => 14, 'result' => 'failed', 'reason' => 'send_failed' ),
			array( 'id' => 15, 'result' => 'not_reached', 'reason' => 'time' ),
			array( 'id' => 16, 'result' => 'skipped', 'reason' => 'Riley <riley@example.com>' ),
		) );
		$this->assertStringContainsString( 'sent 2, skipped 2 (#13 status_holds_left_vm, #16 other), failed 1, not reached 1; 1 Won mark(s) owed', $line );
		$this->assertStringNotContainsString( '@', $line );
		$this->assertStringNotContainsString( 'Riley', $line );
	}
}
