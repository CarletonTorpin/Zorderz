<?php
/**
 * Scheduler — the recurrence engine, series expansion and provider mapping.
 *
 *   - RRULE parsing refuses what it cannot expand, with a reason; canonical output round-trips.
 *   - Expansion iterates DATES, then places each on the wall clock: a weekly 09:00 stays
 *     09:00 local while the UTC instant moves across a DST change.
 *   - A bounded series is expanded to its REAL end (a ten-year COUNT is not cut at a default
 *     horizon) and an unbounded one says so; a "this and following" split keeps the true
 *     remainder of a COUNT.
 *   - A multi-day span that also repeats is refused (it is a job, not a recurrence).
 *   - Expansion on read: an override replaces the occurrence it stands for (even when moved
 *     out of its slot), an EXDATE suppresses one, and a rule that cannot be expanded is shown
 *     as itself and flagged.
 *   - The override key cannot collide across the separator; EXDATE lists split at the cut.
 *   - Provider mapping: Google takes the RRULE plus zoned EXDATEs; Microsoft's pattern is
 *     built, and a rule it cannot express is refused rather than approximated.
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

$zsch_inc = dirname( __DIR__, 2 ) . '/zorderz-apps/apps/scheduler/includes/';
require_once $zsch_inc . 'class-zsch-rrule.php';
require_once $zsch_inc . 'class-zsch-recurrence.php';
require_once $zsch_inc . 'class-zsch-duration.php';
require_once $zsch_inc . 'class-zsch-series.php';
require_once $zsch_inc . 'class-zsch-recurrence-map.php';

final class SchedulerRecurrenceTest extends TestCase {

	private function master( array $over = array() ): array {
		return array_merge( array(
			'id'            => 1,
			'uid'           => 'series-1@example.com',
			'rrule'         => 'FREQ=WEEKLY;BYDAY=MO',
			'start_utc'     => '2026-01-05 14:00:00',   // 09:00 in New York
			'end_utc'       => '2026-01-05 15:00:00',
			'time_zone'     => 'America/New_York',
			'exdate'        => '',
			'rdate'         => '',
			'is_all_day'    => 0,
			'series_uid'    => null,
			'recurrence_id' => '',
			'duration'      => 'PT1H',
		), $over );
	}

	/* ── Parsing ──────────────────────────────────────────────────────────── */

	public function test_bad_rules_are_refused_with_a_reason(): void {
		$p = ZSCH_RRule::parse( 'FREQ=WEEKLY;BYDAY=XX' );
		$this->assertFalse( $p['ok'] );
		$this->assertSame( 'byday_invalid', $p['code'] );
		$this->assertNotEmpty( $p['error'] );
		$this->assertFalse( ZSCH_RRule::parse( '' )['ok'] );
		$this->assertFalse( ZSCH_RRule::parse( 'FREQ=WEEKLY;COUNT=3;UNTIL=20260101T000000Z' )['ok'], 'COUNT and UNTIL together' );
	}

	public function test_canonical_form_round_trips(): void {
		$p = ZSCH_RRule::parse( 'byday=MO,WE;freq=weekly;interval=2' );
		$this->assertTrue( $p['ok'] );
		$canon = ZSCH_RRule::to_string( $p );
		$again = ZSCH_RRule::parse( $canon );
		$this->assertTrue( $again['ok'] );
		$this->assertSame( $canon, ZSCH_RRule::to_string( $again ) );
	}

	/* ── Expansion ────────────────────────────────────────────────────────── */

	public function test_weekly_holds_the_wall_clock_across_a_dst_change(): void {
		$r = ZSCH_Recurrence::expand( '2026-03-02 09:00:00', 'America/New_York', 'FREQ=WEEKLY;BYDAY=MO;COUNT=3' );
		$this->assertTrue( $r['ok'] );
		$this->assertSame( array( '2026-03-02 09:00:00', '2026-03-09 09:00:00', '2026-03-16 09:00:00' ), array_column( $r['instances'], 'local' ) );
		$this->assertSame( array( '2026-03-02 14:00:00', '2026-03-09 13:00:00', '2026-03-16 13:00:00' ), array_column( $r['instances'], 'utc' ) );
		$this->assertSame( array_column( $r['instances'], 'local' ), array_column( $r['instances'], 'recurrence_id' ), 'RECURRENCE-ID is the original local start' );
	}

	public function test_monthly_on_the_31st_skips_short_months_rather_than_inventing_dates(): void {
		$r = ZSCH_Recurrence::expand( '2026-01-31 09:00:00', 'UTC', 'FREQ=MONTHLY;COUNT=3' );
		$this->assertSame( array( '2026-01-31 09:00:00', '2026-03-31 09:00:00', '2026-05-31 09:00:00' ), array_column( $r['instances'], 'local' ) );
	}

	public function test_a_long_count_series_is_expanded_to_its_real_end(): void {
		$r = ZSCH_Recurrence::expand_all( '2026-01-31 09:00:00', 'UTC', 'FREQ=MONTHLY;COUNT=120' );
		$this->assertTrue( $r['complete'], 'a bounded rule must be expanded to the end, not to a default horizon' );
		$this->assertCount( 120, $r['instances'] );
		$this->assertSame( '2043-01-31 09:00:00', ZSCH_Series::compute_until( '2026-01-31 09:00:00', 'UTC', 'FREQ=MONTHLY;COUNT=120' ) );
	}

	public function test_an_unbounded_series_has_no_end(): void {
		$this->assertNull( ZSCH_Series::compute_until( '2026-01-05 09:00:00', 'UTC', 'FREQ=WEEKLY' ), 'NULL means "may still be running"' );
	}

	public function test_split_keeps_the_true_remainder_of_a_count(): void {
		$s = ZSCH_Recurrence::split( '2026-01-05 09:00:00', 'UTC', 'FREQ=WEEKLY;BYDAY=MO;COUNT=10', '2026-02-02 09:00:00' );
		$this->assertTrue( $s['ok'] );
		$this->assertSame( 4, $s['original']['kept'] );
		$this->assertStringContainsString( 'UNTIL=', $s['original']['rule'], 'the original series now ends before the cut' );
		$this->assertSame( 'FREQ=WEEKLY;BYDAY=MO;COUNT=6', $s['new']['rule'], '10 - 4 = 6 remain' );
		$this->assertTrue( $s['new']['needs_new_uid'] );
	}

	public function test_a_multi_day_span_that_repeats_is_a_job_not_a_recurrence(): void {
		$g = ZSCH_Recurrence::guard_multiday( 'P2D', 'FREQ=WEEKLY' );
		$this->assertFalse( $g['ok'] );
		$this->assertSame( 'multiday_is_a_job', $g['code'] );
		$this->assertTrue( ZSCH_Recurrence::guard_multiday( 'PT2H', 'FREQ=WEEKLY' )['ok'] );
		$this->assertTrue( ZSCH_Recurrence::guard_multiday( 'P2D', '' )['ok'], 'a multi-day job that does not repeat is fine' );
	}

	public function test_refusal_messages_name_no_business(): void {
		$p = ZSCH_Recurrence::expand( '2026-01-05 09:00:00', 'UTC', 'FREQ=MINUTELY' );
		$this->assertFalse( $p['ok'] );
		$this->assertDoesNotMatchRegularExpression( '/\bthis business\b|\bour\b/i', (string) $p['error'] );
	}

	/* ── Expansion on read ────────────────────────────────────────────────── */

	public function test_an_override_replaces_its_occurrence_and_an_exdate_suppresses_one(): void {
		$master   = $this->master( array( 'exdate' => '2026-01-19 09:00:00' ) );
		$override = array(
			'id'            => 2,
			'uid'           => 'occurrence-1@example.com',
			'rrule'         => '',
			'start_utc'     => '2026-01-13 16:00:00',   // moved from Monday to Tuesday 11:00
			'end_utc'       => '2026-01-13 17:00:00',
			'time_zone'     => 'America/New_York',
			'exdate'        => '',
			'rdate'         => '',
			'is_all_day'    => 0,
			'series_uid'    => 'series-1@example.com',
			'recurrence_id' => '2026-01-12 09:00:00',
		);
		$m    = ZSCH_Series::materialize( array( $master, $override ), '2026-01-01 00:00:00', '2026-02-01 00:00:00' );
		$rows = $m['rows'];
		$this->assertSame( array( '2026-01-05 14:00:00', '2026-01-13 16:00:00', '2026-01-26 14:00:00' ), array_column( $rows, 'start_utc' ) );
		$this->assertSame( array( 1, 2, 1 ), array_map( 'intval', array_column( $rows, 'id' ) ), 'the override row stands in for its occurrence' );
		$this->assertTrue( $rows[1]['_is_override'] );
		$this->assertSame( '2026-01-12 09:00:00', $rows[1]['recurrence_id'], 'keyed by where it WAS, not where it went' );
		$this->assertFalse( $m['truncated'], 'a series continuing past the window is not a truncation' );
	}

	public function test_a_rule_that_cannot_be_expanded_is_shown_and_flagged(): void {
		$m = ZSCH_Series::materialize( array( $this->master( array( 'rrule' => 'FREQ=NOPE' ) ) ), '2026-01-01 00:00:00', '2026-02-01 00:00:00' );
		$this->assertCount( 1, $m['rows'] );
		$this->assertTrue( $m['rows'][0]['_rule_broken'] );
	}

	/* ── Keys and lists ───────────────────────────────────────────────────── */

	public function test_override_key_cannot_collide_across_the_separator(): void {
		$this->assertNotSame( ZSCH_Series::override_key( 'a|b', 'c' ), ZSCH_Series::override_key( 'a', 'b|c' ) );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{32}$/', ZSCH_Series::override_key( 'series-1@example.com', '2026-01-12 09:00:00' ) );
	}

	public function test_exdate_lists_are_added_removed_and_split_at_the_cut(): void {
		$list = ZSCH_Series::add_to_list( '2026-01-05 09:00:00', '2026-01-12 09:00:00' );
		$this->assertSame( "2026-01-05 09:00:00\n2026-01-12 09:00:00", $list );
		$this->assertSame( $list, ZSCH_Series::add_to_list( $list, '2026-01-12 09:00:00' ), 'adding twice is once' );
		$this->assertSame( '2026-01-12 09:00:00', ZSCH_Series::remove_from_list( $list, '2026-01-05 09:00:00' ), 'a reinstated occurrence leaves the list' );
		$this->assertSame(
			array( 'before' => '2026-01-05 09:00:00', 'after' => '2026-02-09 09:00:00' ),
			ZSCH_Series::split_list_at( "2026-01-05 09:00:00\n2026-02-09 09:00:00", '2026-02-02 09:00:00' ),
			'a split carries its cancellations to the side they belong to'
		);
	}

	/* ── Provider mapping ─────────────────────────────────────────────────── */

	public function test_google_gets_the_rule_and_zoned_exdates(): void {
		$g = ZSCH_Recurrence_Map::to_google( 'FREQ=WEEKLY;BYDAY=MO', '2026-01-19 09:00:00', 'America/Chicago' );
		$this->assertTrue( $g['ok'] );
		$this->assertSame( array( 'RRULE:FREQ=WEEKLY;BYDAY=MO', 'EXDATE;TZID=America/Chicago:20260119T090000' ), $g['recurrence'] );
	}

	public function test_microsoft_gets_its_own_pattern_and_unsupported_rules_are_refused(): void {
		$m = ZSCH_Recurrence_Map::to_graph( 'FREQ=MONTHLY;BYDAY=2TU', '2026-01-13 09:00:00', 'UTC' );
		$this->assertTrue( $m['ok'] );
		$this->assertSame( 'relativeMonthly', $m['patternedRecurrence']['pattern']['type'] );
		$this->assertSame( 'second', $m['patternedRecurrence']['pattern']['index'] );
		$this->assertSame( array( 'tuesday' ), $m['patternedRecurrence']['pattern']['daysOfWeek'] );
		$this->assertSame( 'noEnd', $m['patternedRecurrence']['range']['type'] );
		$this->assertFalse( ZSCH_Recurrence_Map::to_graph( 'FREQ=MINUTELY', '2026-01-13 09:00:00', 'UTC' )['ok'] );
	}
}
