<?php
/**
 * Prep: a queue card shows its own last plan (one customer, two jobs never share a preview),
 * the colour the shop cut in is recorded, and `prep.queue_counts` counts the queue without
 * naming anyone and refuses the shared device. Neutral data only.
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

final class PrepPlanPreviewQueueCountsTest extends TestCase {

	public static function setUpBeforeClass(): void {
		$dir = dirname( __DIR__, 2 ) . '/zorderz-apps/apps/prep/includes/';
		require_once $dir . 'class-zprep-plan-preview.php';
		require_once $dir . 'class-zprep-queue-counts.php';
	}

	protected function setUp(): void {
		ZPREP_Queue_Counts::$now    = null;
		ZPREP_Queue_Counts::$access = null;
		ZPREP_Queue_Counts::$mode   = null;
		ZPREP_Queue_Counts::$build  = null;
	}

	private static function same(): callable {
		return static function ( $a, $ca, $b, $cb ) {
			if ( '' !== $ca && '' !== $cb ) {
				return $ca === $cb;
			}
			return strtolower( trim( $a ) ) === strtolower( trim( $b ) );
		};
	}

	private function map(): array {
		$m = array();
		$m = ZPREP_Plan_Preview::put( $m, 'Alex Rivera', '7', array( 'pieces' => 25, 'area' => array( 'dark' => 60.0 ) ), '1001', '', 0, 1000, 'dark' );
		$m = ZPREP_Plan_Preview::put( $m, 'Alex Rivera', '7', array( 'pieces' => 9, 'area' => array( 'light' => 12.5 ) ), '1002', '', 0, 2000, 'light' );
		return $m;
	}

	public function test_each_card_gets_its_own_plan(): void {
		$m = $this->map();
		$this->assertCount( 2, $m, 'two jobs for one customer are two rows' );
		$this->assertSame( 25, ZPREP_Plan_Preview::pick_row( $m, 'Alex Rivera', '7', '1001', 0, 2, self::same() )['summary']['pieces'] );
		$this->assertSame( 9, ZPREP_Plan_Preview::pick_row( $m, 'Alex Rivera', '7', '1002', 0, 2, self::same() )['summary']['pieces'] );
	}

	public function test_a_sibling_card_never_shows_another_jobs_plan(): void {
		$this->assertNull( ZPREP_Plan_Preview::pick_row( $this->map(), 'Alex Rivera', '7', '1003', 0, 2, self::same() ) );
	}

	public function test_a_lone_job_falls_back_to_the_latest_plan(): void {
		$row = ZPREP_Plan_Preview::pick_row( $this->map(), 'Alex Rivera', '7', '', 0, 1, self::same() );
		$this->assertSame( 9, $row['summary']['pieces'] );
		$this->assertNull( ZPREP_Plan_Preview::pick_row( $this->map(), 'Jordan Lee', '8', '', 0, 1, self::same() ) );
	}

	public function test_summary_and_single_colour(): void {
		$plan = array(
			'summary' => array( 'total_pieces' => 4 ),
			'pages'   => array(
				array( 'color' => 'Dark', 'roll_width_in' => 36, 'sheet_length' => 48 ),
				array( 'color' => 'dark', 'roll_width_in' => 36, 'sheet_length' => 24 ),
			),
		);
		$s = ZPREP_Plan_Preview::summarize( $plan );
		$this->assertSame( 4, $s['pieces'] );
		$this->assertSame( array( 'dark' => 18.0 ), $s['area'] );
		$this->assertSame( 'dark', ZPREP_Plan_Preview::single_color( $s ) );
		$this->assertSame( '', ZPREP_Plan_Preview::single_color( array( 'area' => array( 'dark' => 1, 'light' => 2 ) ) ), 'mixed colours record none' );
	}

	public function test_old_rows_are_pruned(): void {
		$m = ZPREP_Plan_Preview::put( array(), 'Sam Lee', '', array( 'pieces' => 1 ), 'A', '', 0, 1000 );
		$m = ZPREP_Plan_Preview::put( $m, 'Casey Lee', '', array( 'pieces' => 1 ), 'B', '', 0, 1000 + ZPREP_Plan_Preview::TTL + 10 );
		$this->assertCount( 1, $m );
	}

	public function test_sibling_count_uses_the_same_customer_rule(): void {
		$jobs = array(
			array( 'customer' => 'Alex Rivera', 'customer_id' => '7' ),
			array( 'customer' => 'alex rivera', 'customer_id' => '' ),
			array( 'customer' => 'Alex Rivera', 'customer_id' => '7', 'fb_possible' => true ),
			array( 'customer' => 'Alex Rivera', 'kind' => 'remake' ),
		);
		$this->assertSame( 2, ZPREP_Plan_Preview::count_customer_jobs( $jobs, 'Alex Rivera', '7', self::same() ) );
	}

	public function test_queue_counts(): void {
		$now  = 1000000;
		$jobs = array(
			array( 'install_ts' => $now + 3600 ),
			array( 'install_ts' => $now + 30 * 86400 ),
			array( 'install_ts' => 0 ),
			array( 'fb_possible' => true, 'install_ts' => $now ),
			array( 'kind' => 'remake' ),
		);
		$this->assertSame( array( 'to_cut' => 3, 'this_week' => 1, 'possible' => 1 ), ZPREP_Queue_Counts::counts_from( array( 'configured' => true, 'jobs' => $jobs ), $now ) );
		$this->assertNull( ZPREP_Queue_Counts::counts_from( array( 'configured' => false, 'jobs' => array() ), $now ) );
	}

	public function test_queue_counts_verb_refusals_and_descriptor(): void {
		$d = ZPREP_Queue_Counts::descriptor();
		$this->assertFalse( $d['kiosk'] );
		$this->assertFalse( $d['side_effect'] );
		$this->assertTrue( $d['needs_app'] );
		$this->assertSame( 'denied', ZPREP_Queue_Counts::from_capability( array( 'user_id' => 0 ) )['denied'] ? 'denied' : '' );
		$this->assertSame( 'kiosk', ZPREP_Queue_Counts::from_capability( array( 'user_id' => 5, 'is_kiosk' => true ) )['reason'] );
		ZPREP_Queue_Counts::$access = static function () { return false; };
		$this->assertSame( 'no_prep', ZPREP_Queue_Counts::from_capability( array( 'user_id' => 5 ) )['reason'] );
		ZPREP_Queue_Counts::$access = static function () { return true; };
		ZPREP_Queue_Counts::$mode   = static function () { return 'assignee'; };
		$this->assertSame( 'assignee', ZPREP_Queue_Counts::from_capability( array( 'user_id' => 5 ) )['reason'] );
	}
}
