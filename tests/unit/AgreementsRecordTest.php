<?php
/**
 * The agreements app's record and its codes, against an in-memory database (neutral data only):
 *
 *   - the append-only record: a keyed hash chain whose edits, cuts and anchor changes are found, and that never says
 *     "intact" without the key;
 *   - the emailed code: issued only with the key, bound to its session, purpose and version; right, wrong, five tries,
 *     expiry, one use; the per-session budget; twenty wrong answers pause that source only;
 *   - the paper signing's date rules: never a day to come, never more than 30 days back, the answer never assumed;
 *     and when a paper signing counts as signed.
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/support/agreements-stubs.php';

if ( ! defined( 'ZDZ_AGREEMENTS_KEY' ) ) {
	// a throwaway key for this process only; never a real one
	define( 'ZDZ_AGREEMENTS_KEY', base64_encode( random_bytes( 32 ) ) );
}

final class AgreementsRecordTest extends TestCase {

	/** @var ZDZ_Test_Agr_DB */
	private $db;

	protected function setUp(): void {
		$this->db                       = new ZDZ_Test_Agr_DB();
		$GLOBALS['wpdb']                = $this->db;
		$GLOBALS['zdz_test_options']    = array();
		$GLOBALS['zdz_test_transients'] = array();
		$GLOBALS['zdz_test_filters']    = array(
			'zagr_timezone' => static function () { return 'America/Chicago'; },
		);
		ZAGR_Key::forget();
		ZAGR_Calendar::forget();
		ZAGR_Clock::$fixed = strtotime( '2026-11-04 18:00:00 UTC' );
	}

	protected function tearDown(): void {
		ZAGR_Clock::$fixed = null;
		ZAGR_Key::forget();
		ZAGR_Calendar::forget();
		unset( $GLOBALS['wpdb'], $GLOBALS['zdz_test_filters'], $GLOBALS['zdz_test_options'], $GLOBALS['zdz_test_transients'] );
	}

	private function agreement(): int {
		$this->db->insert( 'wp_zagr_agreements', array( 'estimate_id' => 101, 'client_id' => 5, 'link_hash' => str_repeat( 'a', 64 ), 'state' => 'ready', 'created_at' => ZAGR_Clock::utc(), 'updated_at' => ZAGR_Clock::utc() ) );
		return (int) $this->db->insert_id;
	}

	/* ── the record ────────────────────────────────────────────────────────── */

	public function test_the_key_is_usable_and_seals_by_field(): void {
		$this->assertTrue( ZAGR_Key::usable() );
		$s = ZAGR_Key::seal( 'Sam Example', 'sig1|1' );
		$this->assertNotSame( 'Sam Example', $s );
		$this->assertSame( 'Sam Example', ZAGR_Key::open( $s, 'sig1|1' ) );
		$this->assertNull( ZAGR_Key::open( $s, 'sig2|1' ), 'a value moved to another field does not open' );
	}

	public function test_appended_events_chain_and_verify(): void {
		$id = $this->agreement();
		foreach ( array( 'created', 'contractor_signed', 'viewed', 'consent_given' ) as $type ) {
			$this->assertNotNull( ZAGR_Events::append( $id, $type, array( 'n' => 1 ), 'system' ) );
		}
		$v = ZAGR_Events::verify( $id );
		$this->assertTrue( $v['ok'], (string) $v['reason'] );
		$this->assertSame( 4, $v['count'] );
		$rows = ZAGR_Events::all( $id );
		$this->assertSame( ZAGR_Events::GENESIS, $rows[0]['prev_hash'] );
		$this->assertSame( $rows[0]['hash'], $rows[1]['prev_hash'] );
		$a = $this->db->get_row( 'SELECT head_hash, event_count FROM wp_zagr_agreements WHERE id = ' . $id );
		$this->assertSame( 4, (int) $a['event_count'] );
		$this->assertSame( $rows[3]['hash'], $a['head_hash'] );
	}

	public function test_an_unknown_event_type_is_refused(): void {
		$this->expectException( InvalidArgumentException::class );
		ZAGR_Events::append( $this->agreement(), 'made_up', array() );
	}

	public function test_an_edited_event_is_found(): void {
		$id = $this->agreement();
		foreach ( array( 'created', 'viewed', 'consent_given' ) as $type ) {
			ZAGR_Events::append( $id, $type, array( 'n' => 1 ) );
		}
		$this->db->query( "UPDATE wp_zagr_events SET detail = '{\"n\":2}' WHERE agreement_id = " . $id . ' AND seq = 2' );
		$v = ZAGR_Events::verify( $id );
		$this->assertFalse( $v['ok'] );
		$this->assertSame( 2, $v['bad_seq'] );
		$this->assertSame( 'An event was changed after it was written.', $v['reason'] );
	}

	public function test_a_cut_off_tail_and_a_moved_anchor_are_found(): void {
		$id = $this->agreement();
		foreach ( array( 'created', 'viewed', 'consent_given' ) as $type ) {
			ZAGR_Events::append( $id, $type, array() );
		}
		$this->db->query( 'DELETE FROM wp_zagr_events WHERE agreement_id = ' . $id . ' AND seq = 3' );
		$this->assertSame( 'The last events are missing.', ZAGR_Events::verify( $id )['reason'] );
		// the row set back to match the cut record: the keyed anchor still knows step 3
		$h = $this->db->get_var( 'SELECT hash FROM wp_zagr_events WHERE agreement_id = ' . $id . ' AND seq = 2' );
		$this->db->query( "UPDATE wp_zagr_agreements SET event_count = 2, head_hash = '" . $h . "' WHERE id = " . $id );
		$this->assertSame( 'The record no longer reaches its anchored step.', ZAGR_Events::verify( $id )['reason'] );
		// an anchor rewritten without the key
		$GLOBALS['zdz_test_options'][ ZAGR_Events::ANCHOR_OPT . $id ] = array( 'n' => 2, 'head' => $h, 'mac' => str_repeat( '0', 64 ) );
		$this->assertSame( 'The record\'s anchor was changed.', ZAGR_Events::verify( $id )['reason'] );
		unset( $GLOBALS['zdz_test_options'][ ZAGR_Events::ANCHOR_OPT . $id ] );
		$this->assertSame( 'The record\'s anchor is missing.', ZAGR_Events::verify( $id )['reason'] );
	}

	public function test_a_record_is_never_intact_without_the_key(): void {
		$id = $this->agreement();
		ZAGR_Events::append( $id, 'created', array() );
		$GLOBALS['zdz_test_options'][ ZAGR_Key::OPT ] = array( 'check' => str_repeat( 'f', 64 ) );   // a different key's check value
		ZAGR_Key::forget();
		$this->assertFalse( ZAGR_Key::usable(), 'a key whose check differs is refused' );
		$v = ZAGR_Events::verify( $id );
		$this->assertFalse( $v['ok'] );
		$this->assertTrue( $v['unchecked'] );
		$this->assertNull( ZAGR_Events::append( $id, 'viewed', array() ), 'nothing is appended without a usable key' );
	}

	/* ── the code ──────────────────────────────────────────────────────────── */

	public function test_a_code_is_right_once_and_bound_to_its_version(): void {
		$id = $this->agreement();
		$r  = ZAGR_Codes::issue( $id, 11, 'sign', 'agr:v1' );
		$this->assertTrue( $r['ok'] );
		$this->assertMatchesRegularExpression( '/^\d{6}$/', $r['plain'] );
		$this->assertNotContains( $r['plain'], array_column( $this->db->get_results( 'SELECT code_mac FROM wp_zagr_codes' ), 'code_mac' ), 'only a MAC is kept' );
		$this->assertSame( 'wrong', ZAGR_Codes::check( $id, 11, 'sign', 'agr:v2', $r['plain'] ), 'a code sent for one version never signs another' );
		$this->assertSame( 'none', ZAGR_Codes::check( $id, 12, 'sign', 'agr:v1', $r['plain'] ), 'another session has no code' );
		$this->assertSame( 'ok', ZAGR_Codes::check( $id, 11, 'sign', 'agr:v1', $r['plain'] ) );
		$this->assertSame( 'none', ZAGR_Codes::check( $id, 11, 'sign', 'agr:v1', $r['plain'] ), 'used up' );
	}

	public function test_five_tries_and_expiry(): void {
		$id = $this->agreement();
		$r  = ZAGR_Codes::issue( $id, 21, 'sign', 'agr:v1' );
		$bad = '000000' === $r['plain'] ? '111111' : '000000';
		for ( $i = 1; $i <= 4; $i++ ) {
			$this->assertSame( 'wrong', ZAGR_Codes::check( $id, 21, 'sign', 'agr:v1', $bad ) );
		}
		$this->assertSame( 'tries', ZAGR_Codes::check( $id, 21, 'sign', 'agr:v1', $bad ) );
		$this->assertSame( 'tries', ZAGR_Codes::check( $id, 21, 'sign', 'agr:v1', $r['plain'] ), 'no sixth comparison, even of the right code' );
		ZAGR_Clock::$fixed += 61;
		$r2 = ZAGR_Codes::issue( $id, 21, 'sign', 'agr:v1' );
		ZAGR_Clock::$fixed += ZAGR_Codes::TTL + 1;
		$this->assertSame( 'expired', ZAGR_Codes::check( $id, 21, 'sign', 'agr:v1', $r2['plain'] ) );
	}

	public function test_resend_wait_and_the_session_budget(): void {
		$id = $this->agreement();
		$this->assertTrue( ZAGR_Codes::issue( $id, 31, 'sign', 'agr:v1' )['ok'] );
		$this->assertSame( 'wait', ZAGR_Codes::issue( $id, 31, 'sign', 'agr:v1' )['why'] );
		ZAGR_Clock::$fixed += 61;
		$this->assertTrue( ZAGR_Codes::issue( $id, 31, 'sign', 'agr:v1' )['ok'] );
		ZAGR_Clock::$fixed += 61;
		$this->assertTrue( ZAGR_Codes::issue( $id, 31, 'sign', 'agr:v1' )['ok'] );
		ZAGR_Clock::$fixed += 61;
		$this->assertSame( 'limit', ZAGR_Codes::issue( $id, 31, 'sign', 'agr:v1' )['why'], 'three an hour for one session' );
		ZAGR_Clock::$fixed += HOUR_IN_SECONDS;
		$this->assertTrue( ZAGR_Codes::issue( $id, 31, 'sign', 'agr:v1' )['ok'] );
	}

	public function test_twenty_wrong_answers_pause_only_that_source(): void {
		$id = $this->agreement();
		$n  = 0;
		while ( $n < ZAGR_Codes::LOCK_AFTER ) {
			$r = ZAGR_Codes::issue( $id, 41, 'sign', 'agr:v1' );
			$this->assertTrue( $r['ok'], 'code ' . $n );
			$bad = '000000' === $r['plain'] ? '111111' : '000000';
			for ( $i = 0; $i < ZAGR_Codes::TRIES && $n < ZAGR_Codes::LOCK_AFTER; $i++, $n++ ) {
				$last = ZAGR_Codes::check( $id, 41, 'sign', 'agr:v1', $bad );
			}
			ZAGR_Clock::$fixed += HOUR_IN_SECONDS + 1;
		}
		$this->assertSame( 'locked', $last );
		$this->assertTrue( ZAGR_Codes::is_locked( $id, 'sign', 's41' ) );
		$this->assertSame( 'locked', ZAGR_Codes::issue( $id, 41, 'sign', 'agr:v1' )['why'] );
		$this->assertFalse( ZAGR_Codes::is_locked( $id, 'sign', 's42' ), 'another source is not paused' );
		$this->assertFalse( ZAGR_Codes::is_locked( $id, 'manage', 's41' ), 'nor another purpose' );
		$this->assertTrue( ZAGR_Events::has( $id, 'codes_locked' ) );
		ZAGR_Clock::$fixed += ZAGR_Codes::LOCK_FOR + 1;
		$this->assertFalse( ZAGR_Codes::is_locked( $id, 'sign', 's41' ), 'the pause ends after 24 hours' );
	}

	public function test_no_code_without_the_key(): void {
		$GLOBALS['zdz_test_options'][ ZAGR_Key::OPT ] = array( 'check' => str_repeat( 'f', 64 ) );
		ZAGR_Key::forget();
		$this->assertSame( 'no_key', ZAGR_Codes::issue( $this->agreement(), 51, 'sign', 'agr:v1' )['why'] );
	}

	/* ── paper signing dates ───────────────────────────────────────────────── */

	public function test_paper_dates_and_the_age_answer(): void {
		// now: 2026-11-04, local (America/Chicago) noon
		$this->assertSame( 'date', ZAGR_Paper::answers( '2026-11-05', 'no' )[0], 'never a day to come' );
		$this->assertSame( 'date', ZAGR_Paper::answers( '2026-10-04', 'no' )[0], 'more than 30 days back' );
		$this->assertSame( 'date', ZAGR_Paper::answers( '2026-02-30', 'no' )[0], 'not a date' );
		$this->assertNull( ZAGR_Paper::answers( '2026-10-05', 'no' ), 'thirty days back is fine' );
		$this->assertSame( 'older', ZAGR_Paper::answers( '2026-11-04', '' )[0], 'the age answer is required, never assumed' );
		$this->assertSame( 'older', ZAGR_Paper::answers( '2026-11-04', 'maybe' )[0] );
		$this->assertNull( ZAGR_Paper::answers( '2026-11-04', 'yes' ) );
		$this->assertSame( '2026-10-05', ZAGR_Paper::first_day( '2026-11-04' ) );
	}

	public function test_a_paper_signing_counts_at_local_noon_never_in_the_future(): void {
		// recorded the same day before noon: now, so its local day is still the paper date
		ZAGR_Clock::$fixed = strtotime( '2026-11-04 15:00:00 UTC' );   // 9 am in America/Chicago
		$this->assertSame( '2026-11-04 15:00:00', ZAGR_Paper::signed_at_for( '2026-11-04' ) );
		// an earlier day: noon local time on it
		$this->assertSame( '2026-11-02 18:00:00', ZAGR_Paper::signed_at_for( '2026-11-02' ) );
		$this->assertSame( '2026-11-02', ZAGR_Clock::local_day( ZAGR_Clock::ts( ZAGR_Paper::signed_at_for( '2026-11-02' ) ) ) );
	}

	public function test_a_late_recording_is_counted_by_local_days(): void {
		$p = array( 'date' => '2026-10-20', 'cancel_by' => '2026-10-23', 'at' => '2026-10-30 18:00:00.000000' );
		$this->assertSame( 10, ZAGR_Paper::backdated_days( $p ) );
		$this->assertGreaterThan( 0, ZAGR_Paper::late_days( $p ), 'recorded after its cancellation period had ended' );
		$this->assertTrue( ZAGR_Paper::step_ok( $p ) );
		$on_time = array( 'date' => '2026-10-20', 'cancel_by' => '2026-10-23', 'at' => '2026-10-21 18:00:00.000000' );
		$this->assertSame( 0, ZAGR_Paper::late_days( $on_time ) );
		$this->assertFalse( ZAGR_Paper::step_ok( array( 'date' => 'x', 'cancel_by' => '2026-10-23', 'at' => '' ) ), 'what can\'t be read is never confirmed' );
	}
}
