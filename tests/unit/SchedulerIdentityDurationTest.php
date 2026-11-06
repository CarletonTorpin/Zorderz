<?php
/**
 * Scheduler — RFC 5545 identity and durations.
 *
 *   - A UID is minted once from random entropy and a sanitised host; with no host it falls
 *     back to a reserved, neutral one. Validation accepts any printable ASCII up to the
 *     column width, so a provider's own UID can be adopted.
 *   - SEQUENCE advances only on a significant revision; a notes typo does not bump it.
 *   - The "who wins" rule: higher SEQUENCE, then newer DTSTAMP; identical is not applied.
 *   - Change-log sources and actor types are closed lists.
 *   - A NOMINAL day is a calendar day (it keeps the wall clock across a DST change); an
 *     EXACT span is seconds. An all-day occurrence ends at the end of its own local day.
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/zorderz-apps/apps/scheduler/includes/class-zsch-identity.php';
require_once dirname( __DIR__, 2 ) . '/zorderz-apps/apps/scheduler/includes/class-zsch-duration.php';

final class SchedulerIdentityDurationTest extends TestCase {

	public function test_uid_is_entropy_at_a_sanitised_host(): void {
		$this->assertSame( 'abc@badhost', ZSCH_Identity::mint_uid( 'bad host!', 'abc' ) );
		$this->assertSame( 'abc@zorderz.invalid', ZSCH_Identity::mint_uid( '', 'abc' ), 'a reserved .invalid host, never a real domain' );
		$a = ZSCH_Identity::mint_uid( 'example.com' );
		$b = ZSCH_Identity::mint_uid( 'example.com' );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{32}@example\.com$/', $a );
		$this->assertNotSame( $a, $b, 'two mints never collide' );
	}

	public function test_uid_validation_accepts_provider_uids_and_refuses_junk(): void {
		$this->assertTrue( ZSCH_Identity::is_valid_uid( '040000008200E00074C5B7101A82E0080000000' ) );
		$this->assertTrue( ZSCH_Identity::is_valid_uid( 'abc@example.com' ) );
		$this->assertFalse( ZSCH_Identity::is_valid_uid( '' ) );
		$this->assertFalse( ZSCH_Identity::is_valid_uid( 'has space' ) );
		$this->assertFalse( ZSCH_Identity::is_valid_uid( str_repeat( 'a', 192 ) ), 'longer than the column' );
		$this->assertFalse( ZSCH_Identity::is_valid_uid( null ) );
	}

	public function test_sequence_bumps_only_on_a_significant_change(): void {
		$before = array( 'start_utc' => '2026-01-05 17:00:00', 'title' => 'Site visit', 'body' => '' );
		$this->assertFalse( ZSCH_Identity::needs_sequence_bump( $before, array( 'title' => 'Site visit (gate code)' ) ) );
		$this->assertFalse( ZSCH_Identity::needs_sequence_bump( $before, array( 'body' => 'typo fixed' ) ) );
		$this->assertTrue( ZSCH_Identity::needs_sequence_bump( $before, array( 'start_utc' => '2026-01-05 18:00:00' ) ) );
		$this->assertTrue( ZSCH_Identity::needs_sequence_bump( $before, array( 'rrule' => 'FREQ=WEEKLY' ) ) );
		$this->assertFalse( ZSCH_Identity::needs_sequence_bump( $before, array( 'start_utc' => '2026-01-05 17:00:00' ) ), 'same value is no change' );
	}

	public function test_diff_only_reports_fields_the_write_touched(): void {
		$d = ZSCH_Identity::diff( array( 'title' => 'A', 'location' => 'X' ), array( 'title' => 'B' ), array( 'title', 'location' ) );
		$this->assertSame( array( 'title' => array( 'A', 'B' ) ), $d );
	}

	public function test_who_wins(): void {
		$this->assertSame( 'higher_sequence', ZSCH_Identity::wins( 1, '', 2, '' )['reason'] );
		$this->assertSame( 'stale_sequence', ZSCH_Identity::wins( 2, '20260101T000000Z', 1, '20260102T000000Z' )['reason'] );
		$this->assertTrue( ZSCH_Identity::wins( 1, '20260101T000000Z', 1, '20260102T000000Z' )['apply'] );
		$this->assertFalse( ZSCH_Identity::wins( 1, '20260102T000000Z', 1, '20260101T000000Z' )['apply'] );
		$this->assertSame( 'identical_revision', ZSCH_Identity::wins( 1, '20260101T000000Z', 1, '20260101T000000Z' )['reason'] );
		$this->assertFalse( ZSCH_Identity::wins( 1, '20260101T000000Z', 1, '' )['apply'], 'no inbound stamp: do not apply' );
	}

	public function test_sources_and_actor_types_are_closed_lists(): void {
		$this->assertSame( 'graph-sync', ZSCH_Identity::normalize_source( ' Graph-Sync ' ) );
		$this->assertSame( 'unknown', ZSCH_Identity::normalize_source( 'something-new' ) );
		$this->assertSame( 'system', ZSCH_Identity::normalize_actor_type( 'robot' ) );
		$this->assertSame( 'sync', ZSCH_Identity::normalize_actor_type( 'SYNC' ) );
	}

	public function test_change_log_values_are_clipped(): void {
		$this->assertSame( 500, strlen( ZSCH_Identity::clip( str_repeat( 'x', 900 ) ) ) );
		$this->assertSame( 'short', ZSCH_Identity::clip( 'short' ) );
	}

	/* ── Durations ────────────────────────────────────────────────────────── */

	public function test_exact_span_is_seconds(): void {
		$this->assertSame( 'PT24H', ZSCH_Duration::between_exact( '2026-03-07 14:00:00', '2026-03-08 14:00:00' ) );
		$this->assertSame( 'PT1H30M', ZSCH_Duration::between_exact( '2026-01-05 17:00:00', '2026-01-05 18:30:00' ) );
	}

	public function test_a_nominal_day_keeps_the_wall_clock_across_spring_forward(): void {
		// 09:00 local on the Saturday before the US change, in a zone with DST.
		$this->assertSame( '2026-03-08 13:00:00', ZSCH_Duration::derive_end_utc( '2026-03-07 14:00:00', 'P1D', 'America/New_York' ), 'P1D: 09:00 the next day' );
		$this->assertSame( '2026-03-08 14:00:00', ZSCH_Duration::derive_end_utc( '2026-03-07 14:00:00', 'PT24H', 'America/New_York' ), 'PT24H: 24 elapsed hours' );
	}

	public function test_an_all_day_occurrence_ends_at_its_own_local_midnight(): void {
		// A one-day all-day template (00:00 → 23:59:59 local) repeated onto the DST-change day.
		$end = ZSCH_Duration::derive_instance_end_utc( '2026-03-08 05:00:00', '2026-03-01 05:00:00', '2026-03-02 04:59:59', 'America/New_York', true );
		$this->assertSame( '2026-03-09 03:59:59', $end, '23:59:59 local on the shorter day, not a fixed number of seconds' );
	}

	public function test_duration_validation(): void {
		$this->assertTrue( ZSCH_Duration::is_valid( 'P1D' ) );
		$this->assertTrue( ZSCH_Duration::is_valid( 'PT1H30M' ) );
		$this->assertFalse( ZSCH_Duration::is_valid( '' ) );
		$this->assertFalse( ZSCH_Duration::is_valid( '1 hour' ) );
		$this->assertTrue( ZSCH_Duration::is_nominal( 'P2D' ) );
		$this->assertFalse( ZSCH_Duration::is_nominal( 'PT48H' ) );
	}

	public function test_a_backfilled_duration_must_reproduce_the_stored_end(): void {
		$this->assertTrue( ZSCH_Duration::round_trips( '2026-01-05 17:00:00', '2026-01-05 18:00:00', 'PT1H', 'UTC' ) );
		$this->assertFalse( ZSCH_Duration::round_trips( '2026-01-05 17:00:00', '2026-01-05 18:00:00', 'PT2H', 'UTC' ) );
	}
}
