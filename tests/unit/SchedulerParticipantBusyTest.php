<?php
/**
 * Scheduler — a participant on a repeating appointment is busy at every occurrence.
 *
 *   - busy_for() reads the participant's rows with the same window as the calendar read
 *     (a series that began before the window still counts) and expands them.
 *   - A weekly series counts at each occurrence in the window, not only its first.
 *   - An override counts at its moved time; a cancelled occurrence does not count; an
 *     occurrence the person was taken off (an override without them) does not count.
 *   - Appointments the person is not on never count.
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}

$zsch_wb_inc = dirname( __DIR__, 2 ) . '/zorderz-apps/apps/scheduler/includes/';
require_once $zsch_wb_inc . 'class-zsch-rrule.php';
require_once $zsch_wb_inc . 'class-zsch-recurrence.php';
require_once $zsch_wb_inc . 'class-zsch-duration.php';
require_once $zsch_wb_inc . 'class-zsch-series.php';
require_once $zsch_wb_inc . 'class-zsch-appointments.php';
require_once $zsch_wb_inc . 'class-zsch-participants.php';

/**
 * A small in-memory stand-in for the two tables busy_for() reads. It answers the
 * participant-joined window read and the overrides-by-series read the way MySQL would.
 */
final class ZDZ_Test_Wireb_Sched_DB {
	public $prefix = 'wp_';
	/** @var array<int,array> appointment rows keyed by id */
	public $appts = array();
	/** @var array<int,int[]> appointment id => active participant user ids */
	public $parts = array();

	public function prepare( $sql, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		return array( 'sql' => (string) $sql, 'args' => $args );
	}

	public function get_results( $q, $out = null ) {
		$sql  = is_array( $q ) ? $q['sql'] : (string) $q;
		$args = is_array( $q ) ? $q['args'] : array();
		if ( false !== strpos( $sql, 'zsch_participants' ) ) {
			list( $uid, $end, $start ) = array( (int) $args[0], (string) $args[1], (string) $args[2] );
			$series = false !== strpos( $sql, 'series_until_utc' );
			$rows   = array();
			foreach ( $this->appts as $id => $a ) {
				if ( ! in_array( $uid, $this->parts[ $id ] ?? array(), true ) || ! empty( $a['deleted_at'] ) ) {
					continue;
				}
				$plain  = strcmp( $a['start_utc'], $end ) < 0 && strcmp( $a['end_utc'], $start ) > 0;
				$master = $series && '' !== $a['rrule'] && strcmp( $a['start_utc'], $end ) < 0;
				if ( $plain || $master ) {
					$rows[] = $a;
				}
			}
			return $rows;
		}
		if ( false !== strpos( $sql, 'series_uid IN' ) ) {
			$rows = array();
			foreach ( $this->appts as $a ) {
				if ( empty( $a['deleted_at'] ) && in_array( (string) $a['series_uid'], array_map( 'strval', $args ), true ) ) {
					$rows[] = $a;
				}
			}
			return $rows;
		}
		return array();
	}
}

final class SchedulerParticipantBusyTest extends TestCase {

	private $saved_db;
	private ZDZ_Test_Wireb_Sched_DB $db;

	protected function setUp(): void {
		$this->saved_db  = $GLOBALS['wpdb'] ?? null;
		$this->db        = new ZDZ_Test_Wireb_Sched_DB();
		$GLOBALS['wpdb'] = $this->db;
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb'] = $this->saved_db;
	}

	private function row( int $id, array $over = array() ): array {
		return array_merge( array(
			'id'               => $id,
			'uid'              => 'appt-' . $id . '@example.com',
			'owner_user_id'    => 1,
			'rrule'            => '',
			'start_utc'        => '2026-02-03 15:00:00',
			'end_utc'          => '2026-02-03 16:00:00',
			'time_zone'        => 'America/New_York',
			'exdate'           => '',
			'rdate'            => '',
			'is_all_day'       => 0,
			'series_uid'       => '',
			'recurrence_id'    => '',
			'series_until_utc' => null,
			'status'           => '',
			'deleted_at'       => null,
		), $over );
	}

	/** Every Monday 09:00 New York from 5 January; Riley (user 7) is on it. */
	private function weekly_series(): void {
		$this->db->appts[1] = $this->row( 1, array(
			'uid'       => 'series-1@example.com',
			'rrule'     => 'FREQ=WEEKLY;BYDAY=MO',
			'start_utc' => '2026-01-05 14:00:00',
			'end_utc'   => '2026-01-05 15:00:00',
		) );
		$this->db->parts[1] = array( 7 );
	}

	private function starts( array $busy ): array {
		return array_column( $busy, 'start_utc' );
	}

	public function test_a_weekly_series_is_busy_at_every_occurrence_in_the_window(): void {
		$this->weekly_series();
		$busy = ZSCH_Participants::busy_for( 7, '2026-02-01 00:00:00', '2026-03-01 00:00:00' );
		$this->assertSame(
			array( '2026-02-02 14:00:00', '2026-02-09 14:00:00', '2026-02-16 14:00:00', '2026-02-23 14:00:00' ),
			$this->starts( $busy ),
			'a series that began in January is busy every Monday of February'
		);
		$this->assertSame( '2026-02-02 15:00:00', $busy[0]['end_utc'] );
	}

	public function test_the_wall_clock_holds_across_a_daylight_saving_change(): void {
		$this->weekly_series();
		$busy = ZSCH_Participants::busy_for( 7, '2026-03-01 00:00:00', '2026-03-17 00:00:00' );
		$this->assertSame( array( '2026-03-02 14:00:00', '2026-03-09 13:00:00', '2026-03-16 13:00:00' ), $this->starts( $busy ) );
	}

	public function test_overrides_move_cancel_and_remove_single_occurrences(): void {
		$this->weekly_series();
		// 9 Feb moved to Tuesday 10 Feb 11:00; the crew came with it.
		$this->db->appts[2] = $this->row( 2, array(
			'start_utc'     => '2026-02-10 16:00:00',
			'end_utc'       => '2026-02-10 17:00:00',
			'series_uid'    => 'series-1@example.com',
			'recurrence_id' => '2026-02-09 09:00:00',
		) );
		$this->db->parts[2] = array( 7 );
		// 16 Feb cancelled.
		$this->db->appts[3] = $this->row( 3, array(
			'start_utc'     => '2026-02-16 14:00:00',
			'end_utc'       => '2026-02-16 15:00:00',
			'series_uid'    => 'series-1@example.com',
			'recurrence_id' => '2026-02-16 09:00:00',
			'status'        => 'cancelled',
		) );
		$this->db->parts[3] = array( 7 );
		// 23 Feb kept, but Riley was taken off that one occurrence.
		$this->db->appts[4] = $this->row( 4, array(
			'start_utc'     => '2026-02-23 14:00:00',
			'end_utc'       => '2026-02-23 15:00:00',
			'series_uid'    => 'series-1@example.com',
			'recurrence_id' => '2026-02-23 09:00:00',
		) );
		$this->db->parts[4] = array( 8 );

		$busy = ZSCH_Participants::busy_for( 7, '2026-02-01 00:00:00', '2026-03-01 00:00:00' );
		$this->assertSame( array( '2026-02-02 14:00:00', '2026-02-10 16:00:00' ), $this->starts( $busy ) );

		// The person still on the 23rd is busy then, and only then.
		$this->assertSame( array( '2026-02-23 14:00:00' ), $this->starts( ZSCH_Participants::busy_for( 8, '2026-02-01 00:00:00', '2026-03-01 00:00:00' ) ) );
	}

	public function test_an_occurrence_moved_out_of_the_window_frees_its_old_slot(): void {
		$this->weekly_series();
		$this->db->appts[2] = $this->row( 2, array(
			'start_utc'     => '2026-03-03 16:00:00',
			'end_utc'       => '2026-03-03 17:00:00',
			'series_uid'    => 'series-1@example.com',
			'recurrence_id' => '2026-02-23 09:00:00',
		) );
		$this->db->parts[2] = array( 7 );
		$busy = ZSCH_Participants::busy_for( 7, '2026-02-20 00:00:00', '2026-03-01 00:00:00' );
		$this->assertSame( array(), $busy, 'the 23rd moved to March: nothing in this window' );
	}

	public function test_plain_appointments_still_count_and_others_never_do(): void {
		$this->db->appts[5] = $this->row( 5 );
		$this->db->parts[5] = array( 7 );
		$this->db->appts[6] = $this->row( 6, array( 'start_utc' => '2026-02-04 15:00:00', 'end_utc' => '2026-02-04 16:00:00' ) );
		$this->db->parts[6] = array( 9 );
		$this->db->appts[9] = $this->row( 9, array( 'rrule' => 'FREQ=DAILY', 'uid' => 'series-9@example.com', 'start_utc' => '2026-01-05 14:00:00', 'end_utc' => '2026-01-05 15:00:00' ) );
		$this->db->parts[9] = array( 9 );

		$busy = ZSCH_Participants::busy_for( 7, '2026-02-01 00:00:00', '2026-03-01 00:00:00' );
		$this->assertSame( array( '2026-02-03 15:00:00' ), $this->starts( $busy ) );
		$this->assertSame( array(), ZSCH_Participants::busy_for( 0, '2026-02-01 00:00:00', '2026-03-01 00:00:00' ) );
	}

	public function test_busy_from_rows_keeps_only_this_persons_overlapping_live_rows(): void {
		$rows = array(
			array( 'id' => 1, 'start_utc' => '2026-02-02 14:00:00', 'end_utc' => '2026-02-02 15:00:00' ),
			array( 'id' => 1, 'start_utc' => '2026-02-02 14:00:00', 'end_utc' => '2026-02-02 15:00:00' ),
			array( 'id' => 2, 'start_utc' => '2026-02-03 14:00:00', 'end_utc' => '2026-02-03 15:00:00' ),
			array( 'id' => 3, 'start_utc' => '2026-02-04 14:00:00', 'end_utc' => '2026-02-04 15:00:00', 'status' => 'cancelled' ),
			array( 'id' => 1, 'start_utc' => '2026-03-02 14:00:00', 'end_utc' => '2026-03-02 15:00:00' ),
		);
		$busy = ZSCH_Participants::busy_from_rows( $rows, array( 1, 3 ), '2026-02-01 00:00:00', '2026-03-01 00:00:00' );
		$this->assertSame( array( '2026-02-02 14:00:00' ), $this->starts( $busy ) );
	}

	public function test_the_calendar_read_and_the_busy_read_share_one_window(): void {
		$this->assertSame( 4, substr_count( ZSCH_Appointments::window_where(), '%s' ) );
		$this->assertStringContainsString( 'a.series_until_utc IS NULL', ZSCH_Appointments::window_where( 'a.' ) );
		$this->assertStringNotContainsString( ';', ZSCH_Appointments::window_where( 'a.; DROP' ) );
		$src = (string) file_get_contents( dirname( __DIR__, 2 ) . '/zorderz-apps/apps/scheduler/includes/class-zsch-appointments.php' );
		$this->assertStringContainsString( '$where[]  = self::window_where();', $src );
		$this->assertStringContainsString( '$rows = self::expand_window( $rows, $start_utc, $end_utc );', $src );
	}
}
