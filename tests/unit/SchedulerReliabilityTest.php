<?php
/**
 * Scheduler — reliability decisions that need no database.
 *
 *   - The Graph sweep is bounded by a budget AND a per-mailbox ceiling, and rotates
 *     oldest-swept first so nobody can be starved.
 *   - The version beacon is throttled per VERSION (a deploy is always visible); the
 *     zone-database beacon never calls a first look a change.
 *   - A create's idempotency key is accepted only in a shape that cannot collide, and a
 *     duplicate-key error is recognised narrowly.
 *   - A pulled Outlook body is never pushed back as the body; a sweep never applies a
 *     snapshot older than a person's edit.
 *   - Small normalisers: job grouping keys, EXDATE/RDATE lists, the split-off notice.
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'register_activation_hook' ) ) {
	function register_activation_hook( ...$a ) { return true; }
}
if ( ! function_exists( 'register_deactivation_hook' ) ) {
	function register_deactivation_hook( ...$a ) { return true; }
}

$zsch_dir = dirname( __DIR__, 2 ) . '/zorderz-apps/apps/scheduler/';
if ( ! function_exists( 'zsch_beacon_decide' ) ) {
	require_once $zsch_dir . 'app.php';
}
require_once $zsch_dir . 'includes/class-zsch-settings.php';
require_once $zsch_dir . 'includes/class-zsch-graph.php';
require_once $zsch_dir . 'includes/class-zsch-appointments.php';
require_once $zsch_dir . 'includes/class-zsch-backfill.php';

final class SchedulerReliabilityTest extends TestCase {

	/* ── The bounded sweep ─────────────────────────────────────────────────── */

	public function test_a_tick_stops_starting_work_once_the_budget_is_spent(): void {
		$this->assertTrue( ZSCH_Graph::can_start_user( 0, 30, 12 ) );
		$this->assertFalse( ZSCH_Graph::can_start_user( 30, 30, 12 ), 'budget spent' );
		$this->assertFalse( ZSCH_Graph::can_start_user( 45, 30, 12 ) );
	}

	public function test_a_mailbox_is_never_started_without_its_full_ceiling(): void {
		$this->assertTrue( ZSCH_Graph::can_start_user( 18, 30, 12 ), 'exactly the ceiling left' );
		$this->assertFalse( ZSCH_Graph::can_start_user( 18.5, 30, 12 ), 'inside the budget but short of the ceiling' );
	}

	public function test_sweep_order_is_oldest_first_never_swept_first_and_capped(): void {
		$order = ZSCH_Graph::order_sweep( array( 11 => 300, 12 => 0, 13 => 100, 14 => 200 ), 3 );
		$this->assertSame( array( 12, 13, 14 ), $order );
		$this->assertSame( array(), ZSCH_Graph::order_sweep( array( 1 => 0 ), 0 ) );
		$this->assertSame( array(), ZSCH_Graph::order_sweep( array(), 5 ) );
	}

	public function test_equal_cursors_keep_their_order_between_ticks(): void {
		$this->assertSame( array( 7, 3, 9 ), ZSCH_Graph::order_sweep( array( 7 => 50, 3 => 50, 9 => 50 ), 12 ) );
	}

	public function test_sweep_bounds_are_declared(): void {
		$this->assertGreaterThan( 0, ZSCH_Graph::SWEEP_BUDGET_S );
		$this->assertLessThanOrEqual( ZSCH_Graph::SWEEP_BUDGET_S, ZSCH_Graph::PER_USER_CEILING_S );
		$this->assertGreaterThan( 0, ZSCH_Graph::MAX_USERS_PER_TICK );
		$this->assertSame( 'zsch_graph_last_sweep', ZSCH_Graph::SYNC_CURSOR_META );
	}

	/* ── Beacons ──────────────────────────────────────────────────────────── */

	public function test_version_beacon_fires_on_any_change_and_names_the_old_version(): void {
		$this->assertSame( array( 'fire' => false, 'from' => '' ), zsch_beacon_decide( '1.10.0', '1.10.0' ) );
		$this->assertSame( array( 'fire' => true, 'from' => '1.9.0' ), zsch_beacon_decide( '1.9.0', '1.10.0' ) );
		$this->assertSame( array( 'fire' => true, 'from' => '' ), zsch_beacon_decide( false, '1.10.0' ), 'expired: fire, no from' );
		$this->assertSame( array( 'fire' => true, 'from' => '' ), zsch_beacon_decide( 1, '1.10.0' ), 'a legacy bare 1 is not a version' );
	}

	public function test_zone_beacon_speaks_only_about_real_changes(): void {
		$this->assertFalse( zsch_tz_beacon_decide( 'abc', '' )['fire'], 'nothing read, nothing to say' );
		$this->assertFalse( zsch_tz_beacon_decide( 'abc', 'abc' )['fire'] );
		$this->assertSame( array( 'fire' => true, 'from' => '' ), zsch_tz_beacon_decide( '', 'abc' ), 'a first look is not a change' );
		$this->assertSame( array( 'fire' => true, 'from' => 'abc' ), zsch_tz_beacon_decide( 'abc', 'def' ) );
	}

	public function test_beacons_log_locally_and_name_no_outside_endpoint(): void {
		$src = (string) file_get_contents( dirname( __DIR__, 2 ) . '/zorderz-apps/apps/scheduler/app.php' );
		$this->assertStringContainsString( "error_log(\n\t\t\t'Zorderz Scheduler ACTIVE — version '", $src );
		$this->assertDoesNotMatchRegularExpression( '/wp_remote_(post|get)\([^)]*beacon/i', $src, 'the beacon never reports off the server' );
	}

	/* ── Idempotent create ────────────────────────────────────────────────── */

	public function test_request_id_shapes(): void {
		$this->assertSame( '123e4567-e89b-12d3-a456-426614174000', ZSCH_Appointments::sanitize_request_id( '123e4567-e89b-12d3-a456-426614174000' ) );
		$this->assertSame( 'r1a2b3c4d5', ZSCH_Appointments::sanitize_request_id( ' r1a2b3c4d5 ' ) );
		$this->assertSame( '', ZSCH_Appointments::sanitize_request_id( 'short' ), 'too short to be a key' );
		$this->assertSame( '', ZSCH_Appointments::sanitize_request_id( str_repeat( 'a', 65 ) ), 'too long is refused, never truncated' );
		$this->assertSame( '', ZSCH_Appointments::sanitize_request_id( 'has space inside' ) );
		$this->assertSame( '', ZSCH_Appointments::sanitize_request_id( '<script>xx' ) );
		$this->assertSame( '', ZSCH_Appointments::sanitize_request_id( array( 'abcdefgh' ) ) );
	}

	public function test_only_a_unique_violation_counts_as_already_created(): void {
		$this->assertTrue( ZSCH_Appointments::is_duplicate_key_error( "Duplicate entry '7-abc' for key 'idx_zsch_idem'" ) );
		$this->assertTrue( ZSCH_Appointments::is_duplicate_key_error( 'Error 1062: something' ) );
		$this->assertFalse( ZSCH_Appointments::is_duplicate_key_error( "Unknown column 'uid' in 'field list'" ) );
		$this->assertFalse( ZSCH_Appointments::is_duplicate_key_error( '' ) );
	}

	/* ── The pulled body, and stale snapshots ─────────────────────────────── */

	public function test_a_pulled_preview_is_never_sent_back_as_the_body(): void {
		$preview = 'Bring the ladder';
		$hash    = ZSCH_Appointments::body_fingerprint( $preview );
		$this->assertFalse( ZSCH_Appointments::body_is_ours( $preview, $hash ), 'unchanged since the pull: Outlook keeps its body' );
		$this->assertTrue( ZSCH_Appointments::body_is_ours( 'Bring the long ladder', $hash ), 'a person edited it here: their edit wins' );
		$this->assertTrue( ZSCH_Appointments::body_is_ours( 'anything', '' ), 'never pulled: the body is ours' );
		$this->assertSame( '', ZSCH_Appointments::body_fingerprint( '' ) );
	}

	public function test_a_sweep_never_undoes_a_newer_edit_by_a_person(): void {
		$person_row = array( 'updated_by' => 5, 'last_modified' => '2026-05-01 10:00:00' );
		$sync_row   = array( 'updated_by' => 0, 'last_modified' => '2026-05-01 10:00:00' );
		$older      = array( 'lastModifiedDateTime' => '2026-05-01T09:59:00Z' );
		$newer      = array( 'lastModifiedDateTime' => '2026-05-01T10:01:00Z' );
		$this->assertTrue( ZSCH_Appointments::graph_is_stale( $person_row, $older ) );
		$this->assertFalse( ZSCH_Appointments::graph_is_stale( $person_row, $newer ) );
		$this->assertFalse( ZSCH_Appointments::graph_is_stale( $sync_row, $older ), 'a sync-written row is always correctable' );
		$this->assertFalse( ZSCH_Appointments::graph_is_stale( $person_row, array() ), 'cannot compare: no verdict' );
		$this->assertFalse( ZSCH_Appointments::graph_is_stale( null, $older ) );
	}

	public function test_pending_map_ids_are_never_real_graph_ids(): void {
		$this->assertTrue( ZSCH_Appointments::is_pending_graph_id( 'pending:12:abc' ) );
		$this->assertFalse( ZSCH_Appointments::is_pending_graph_id( 'AAMkAGI2TAAA=' ) );
		$this->assertFalse( ZSCH_Appointments::is_pending_graph_id( '' ) );
	}

	/* ── Normalisers ──────────────────────────────────────────────────────── */

	public function test_job_ids(): void {
		$this->assertSame( 'JOB-42.a:1', ZSCH_Appointments::sanitize_job_id( ' JOB-42.a:1 ' ) );
		$this->assertNull( ZSCH_Appointments::sanitize_job_id( '' ) );
		$this->assertNull( ZSCH_Appointments::sanitize_job_id( 'has space' ) );
		$this->assertNull( ZSCH_Appointments::sanitize_job_id( str_repeat( 'x', 65 ) ) );
		$this->assertNull( ZSCH_Appointments::sanitize_job_id( 42 ) );
	}

	public function test_date_lists_drop_what_could_not_match_an_instance(): void {
		$this->assertSame(
			"2026-01-05 09:00:00\n2026-01-12 09:00:00",
			ZSCH_Appointments::sanitize_date_list( "2026-01-12 09:00:00,2026-01-05 09:00:00\n2026-01-05 09:00:00\n2026-01-19\nnot a date" )
		);
		$this->assertNull( ZSCH_Appointments::sanitize_date_list( '' ) );
		$this->assertSame( '2026-02-02 09:00:00', ZSCH_Appointments::sanitize_date_list( array( '2026-02-02 09:00:00' ) ) );
	}

	public function test_split_off_notice(): void {
		$this->assertSame( '', ZSCH_Appointments::split_off_notice( 0 ) );
		$this->assertSame( '1 split-off appointment from this series was kept.', ZSCH_Appointments::split_off_notice( 1 ) );
		$this->assertSame( '3 split-off appointments from this series were kept.', ZSCH_Appointments::split_off_notice( 3 ) );
	}

	/* ── Backfill ─────────────────────────────────────────────────────────── */

	public function test_backfill_log_lines(): void {
		$this->assertSame( array(), ZSCH_Backfill::report_lines( array( 'processed' => 0 ) ), 'nothing happened, nothing said' );
		$running = ZSCH_Backfill::report_lines( array( 'processed' => 200, 'uid_set' => 200, 'duration_set' => 198, 'skipped_no_roundtrip' => 2, 'remaining' => 40, 'done' => false ) );
		$this->assertCount( 1, $running );
		$this->assertStringContainsString( 'skipped 2 (no round-trip), 40 remaining', $running[0] );
		$done = ZSCH_Backfill::report_lines( array( 'processed' => 40, 'uid_set' => 40, 'duration_set' => 40, 'skipped_no_roundtrip' => 0, 'remaining' => 0, 'done' => true ), 3 );
		$this->assertCount( 2, $done );
		$this->assertStringContainsString( 'COMPLETE', $done[0] );
		$this->assertStringContainsString( '3 appointment(s) still carrying no duration', $done[1], 'the closing line states the ground truth' );
		$this->assertStringContainsString( 'every appointment now has a uid and a duration', ZSCH_Backfill::report_lines( array( 'processed' => 1, 'done' => true ), 0 )[1] );
	}

	/* ── Single-flight schema upgrade ─────────────────────────────────────── */

	public function test_the_migration_is_single_flight_and_registered_everywhere(): void {
		$dir = dirname( __DIR__, 2 ) . '/zorderz-apps/apps/scheduler/';
		$app = (string) file_get_contents( $dir . 'app.php' );
		$this->assertSame( 2, substr_count( $app, 'ZSCH_Migrate_1_10_0::run();' ), 'activation and the self-heal both run it' );
		$this->assertStringContainsString( "SELECT GET_LOCK(%s, %d)', \$lock, 10", $app );
		$this->assertStringContainsString( "SELECT RELEASE_LOCK(%s)', \$lock", $app );
		$appt = (string) file_get_contents( $dir . 'includes/class-zsch-appointments.php' );
		$this->assertStringContainsString( "array( '1.0.0', '1.6.0', '1.9.0', '1.10.0' )", $appt, 'the insert self-heal knows every migration' );
		$this->assertFileExists( $dir . 'db/migrate-1.10.0.php' );
	}
}
