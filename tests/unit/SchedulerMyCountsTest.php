<?php
/**
 * Scheduler — the read verb `schedule.my_counts` (counts for a home tile).
 *
 *   - The descriptor declares itself a READ (side_effect false) of the scheduler app that
 *     needs the app, so the registry never runs it as an unconfirmed write.
 *   - Signed out and the shared device are refused; a person gets only their OWN calendar.
 *   - "Yours" is owner or participant; a repeating appointment counts once per visit; an
 *     all-day one is "ahead" until the day ends; the answer carries counts and one time,
 *     never a title, a place or a person.
 *   - A failed read is an error, never zero.
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'wp_timezone_string' ) ) {
	function wp_timezone_string() { return 'UTC'; }
}
if ( ! function_exists( 'wp_parse_args' ) ) {
	function wp_parse_args( $args, $defaults = array() ) { return array_merge( (array) $defaults, (array) $args ); }
}

$zsch_inc = dirname( __DIR__, 2 ) . '/zorderz-apps/apps/scheduler/includes/';
require_once $zsch_inc . 'class-zsch-settings.php';
require_once $zsch_inc . 'class-zsch-my-counts.php';
require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-capabilities.php';

final class SchedulerMyCountsTest extends TestCase {

	protected function tearDown(): void {
		ZSCH_My_Counts::$now  = null;
		ZSCH_My_Counts::$read = null;
		unset( $GLOBALS['zdz_test_options'], $GLOBALS['zdz_test_user_roles'], $GLOBALS['zdz_test_user_caps'] );
	}

	private function row( int $id, int $owner, string $s, string $e, bool $all_day = false, array $on = array() ): array {
		return array(
			'id'              => $id,
			'owner_user_id'   => $owner,
			'start_utc'       => $s,
			'end_utc'         => $e,
			'is_all_day'      => $all_day,
			'participant_ids' => $on,
			'title'           => 'Site visit',
			'location'        => '1 Example Street',
		);
	}

	public function test_descriptor_is_a_read_of_the_scheduler(): void {
		$d = ZSCH_My_Counts::descriptor();
		$this->assertSame( 'schedule.my_counts', $d['verb'] );
		$this->assertFalse( $d['side_effect'] );
		$this->assertTrue( $d['needs_app'] );
		$this->assertSame( 'zsch', $d['provider'] );
		$this->assertFalse( $d['kiosk'] );
		$norm = ZDZ_Capabilities::normalize( 'schedule.my_counts', $d, array() );
		$this->assertNotNull( $norm );
		$this->assertFalse( $norm['side_effect'], 'the registry reads it as a read' );
		$this->assertFalse( $norm['outside'] );
		$this->assertSame( 'viewer', $norm['tier'] );
	}

	public function test_signed_out_and_the_shared_device_are_refused(): void {
		$this->assertSame( 'signed_out', ZSCH_My_Counts::from_capability( array() )['reason'] );
		$this->assertSame( 'kiosk', ZSCH_My_Counts::from_capability( array( 'requesting_user_id' => 4, 'is_kiosk' => true ) )['reason'] );
	}

	public function test_mine_is_owner_or_participant(): void {
		$rows = array(
			$this->row( 1, 7, '2026-05-04 15:00:00', '2026-05-04 16:00:00' ),
			$this->row( 2, 8, '2026-05-04 17:00:00', '2026-05-04 18:00:00', false, array( 7 ) ),
			$this->row( 3, 8, '2026-05-04 19:00:00', '2026-05-04 20:00:00' ),
		);
		$this->assertSame( array( 1, 2 ), array_column( ZSCH_My_Counts::mine( $rows, 7 ), 'id' ) );
	}

	public function test_tally(): void {
		$a   = strtotime( '2026-05-04 00:00:00 UTC' );
		$b   = $a + 86400;
		$now = strtotime( '2026-05-04 12:00:00 UTC' );
		$rows = array(
			$this->row( 1, 7, '2026-05-04 09:00:00', '2026-05-04 10:00:00' ),            // over
			$this->row( 2, 7, '2026-05-04 14:00:00', '2026-05-04 15:00:00' ),            // ahead, next
			$this->row( 3, 7, '2026-05-04 16:00:00', '2026-05-04 17:00:00' ),            // ahead
			$this->row( 3, 7, '2026-05-04 16:00:00', '2026-05-04 17:00:00' ),            // the same visit twice
			$this->row( 4, 7, '2026-05-04 00:00:00', '2026-05-04 23:59:59', true ),      // all-day, ahead
			$this->row( 5, 7, '2026-05-05 09:00:00', '2026-05-05 10:00:00' ),            // tomorrow
		);
		$t = ZSCH_My_Counts::tally( $rows, $a, $b, $now );
		$this->assertSame( 4, $t['n'] );
		$this->assertSame( 3, $t['ahead'] );
		$this->assertSame( 1, $t['all_day'] );
		$this->assertSame( strtotime( '2026-05-04 14:00:00 UTC' ), $t['next_at'] );
	}

	public function test_the_answer_carries_counts_only_and_a_failed_read_is_an_error(): void {
		$GLOBALS['zdz_test_user_roles'][7] = array( 'zdz_tech' );
		$GLOBALS['zdz_test_user_caps'][7]  = array( 'zdz_access_app' );
		ZSCH_My_Counts::$now  = strtotime( '2026-05-04 12:00:00 UTC' );
		ZSCH_My_Counts::$read = function ( $uid, $s, $e ) {
			return array( $this->row( 1, (int) $uid, '2026-05-04 14:00:00', '2026-05-04 15:00:00' ) );
		};
		$out = ZSCH_My_Counts::from_capability( array( 'requesting_user_id' => 7, 'day' => '2026-05-04' ) );
		$this->assertTrue( $out['success'] );
		$this->assertSame( 1, $out['counts']['today'] );
		$this->assertSame( 'UTC', $out['tz'] );
		$json = (string) json_encode( $out );
		$this->assertStringNotContainsString( 'Site visit', $json, 'never a title' );
		$this->assertStringNotContainsString( 'Example Street', $json, 'never a place' );

		ZSCH_My_Counts::$read = function () { return null; };
		$err = ZSCH_My_Counts::from_capability( array( 'requesting_user_id' => 7 ) );
		$this->assertFalse( $err['success'] );
		$this->assertArrayNotHasKey( 'counts', $err, 'a failed read is not zero' );
	}

	public function test_day_parser(): void {
		$this->assertSame( '2026-02-28', ZSCH_My_Counts::day( '2026-02-28' ) );
		$this->assertNull( ZSCH_My_Counts::day( '2026-02-30' ) );
		$this->assertNull( ZSCH_My_Counts::day( 'tomorrow' ) );
	}
}
