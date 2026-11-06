<?php
/**
 * My next and the home tiles' counts read from it:
 *
 *   - ZJOB_My_Next::steps(): which steps are open on a Project and whose each is (confirmed people first, the owners'
 *     defaults otherwise, the office when nobody sold it); no step on a fact the records do not hold; money steps only
 *     with a billing answer.
 *   - is_mine(), sort_present() (visits by day, then prep, orders, scheduling, walks, money), pick_visit() (an upcoming
 *     visit always wins over a passed one), is_installed()/is_sold(), combine_cut(), city_of() (never a street).
 *   - ZJOB_My_Counts::tally() (today / this week / passed / other / next day) and the verb's refusals.
 *   - ZJOB_Installed_Counts: whose a Project is, when its work finished, the month window.
 *
 * Neutral data only.
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/zorderz-apps/apps/jobs/includes/class-zjob-my-next.php';
require_once dirname( __DIR__, 2 ) . '/zorderz-apps/apps/jobs/includes/class-zjob-my-counts.php';
require_once dirname( __DIR__, 2 ) . '/zorderz-apps/apps/jobs/includes/class-zjob-installed-counts.php';

class JobsMyNextTest extends TestCase {

	private $owners = array( 'cut' => array( 30 ), 'order' => array( 40 ), 'office' => array( 50 ) );

	private function project( array $over = array() ): array {
		return array_merge( array(
			'id'           => '01HPROJECT00000000000000AA',
			'kinds'        => array(),
			'installed'    => false,
			'seller_ids'   => array( 10 ),
			'seller_codes' => array(),
			'orders'       => array( 'n' => 0, 'ordered' => 0 ),
			'cut'          => 'unknown',
			'visit'        => null,
			'visit_past'   => false,
			'money'        => null,
			'confirmed'    => array(),
		), $over );
	}

	private function step_names( array $steps ): array {
		return array_column( $steps, 'step' );
	}

	public function test_a_sold_project_with_nothing_booked_asks_its_seller_to_schedule() {
		$s = ZJOB_My_Next::steps( $this->project(), $this->owners );
		$this->assertSame( array( 'schedule' ), $this->step_names( $s ) );
		$this->assertSame( array( 10 ), $s[0]['owners'] );
	}

	public function test_nobody_sold_it_so_the_office_schedules() {
		$s = ZJOB_My_Next::steps( $this->project( array( 'seller_ids' => array() ) ), $this->owners );
		$this->assertSame( array( 50 ), $s[0]['owners'] );
		$this->assertSame( array(), $s[0]['codes'] );
	}

	public function test_a_confirmed_seller_takes_the_schedule_step_over_the_code() {
		$s = ZJOB_My_Next::steps( $this->project( array( 'seller_codes' => array( 'AB' ), 'confirmed' => array( 'seller' => 11 ) ) ), $this->owners );
		$this->assertSame( array( 11 ), $s[0]['owners'] );
		$this->assertSame( array(), $s[0]['codes'] );
	}

	public function test_no_order_step_without_an_order_or_a_rule_naming_the_kind() {
		$s = ZJOB_My_Next::steps( $this->project( array( 'kinds' => array( 'install' ) ) ), $this->owners );
		$this->assertNotContains( 'order', $this->step_names( $s ) );
		$rules = array( 'order' => array( 'install' ), 'cut' => array() );
		$s     = ZJOB_My_Next::steps( $this->project( array( 'kinds' => array( 'install' ) ) ), $this->owners, $rules );
		$this->assertContains( 'order', $this->step_names( $s ) );
	}

	public function test_an_order_not_placed_goes_to_the_confirmed_orderer_else_the_order_owners() {
		$p = $this->project( array( 'orders' => array( 'n' => 2, 'ordered' => 1 ) ) );
		$s = ZJOB_My_Next::steps( $p, $this->owners );
		$this->assertSame( 'order', $s[0]['step'] );
		$this->assertSame( array( 40 ), $s[0]['owners'] );
		$s = ZJOB_My_Next::steps( array_merge( $p, array( 'confirmed' => array( 'orderer' => 41 ) ) ), $this->owners );
		$this->assertSame( array( 41 ), $s[0]['owners'] );
		$all = ZJOB_My_Next::steps( $this->project( array( 'orders' => array( 'n' => 2, 'ordered' => 2 ) ) ), $this->owners );
		$this->assertNotContains( 'order', $this->step_names( $all ) );
	}

	public function test_shop_prep_opens_only_on_the_ledger_or_a_rule() {
		$this->assertNotContains( 'cut', $this->step_names( ZJOB_My_Next::steps( $this->project(), $this->owners ) ) );
		$s = ZJOB_My_Next::steps( $this->project( array( 'cut' => 'partial' ) ), $this->owners );
		$this->assertSame( 'cut', $s[0]['step'] );
		$this->assertSame( array( 30 ), $s[0]['owners'] );
		$this->assertNotContains( 'cut', $this->step_names( ZJOB_My_Next::steps( $this->project( array( 'cut' => 'complete' ) ), $this->owners ) ) );
		$s = ZJOB_My_Next::steps( $this->project( array( 'cut' => 'uncut', 'confirmed' => array( 'preparer' => 31 ) ) ), $this->owners );
		$this->assertSame( array( 31 ), $s[0]['owners'] );
		// A passed visit: the prep step is history.
		$past = $this->project( array( 'cut' => 'uncut', 'visit' => array( 'start_utc' => '2026-01-01 16:00:00', 'people' => array( 20 ) ), 'visit_past' => true ) );
		$this->assertNotContains( 'cut', $this->step_names( ZJOB_My_Next::steps( $past, $this->owners ) ) );
	}

	public function test_a_booked_visit_belongs_to_its_people_and_the_confirmed_installers() {
		$p = $this->project( array( 'visit' => array( 'start_utc' => '2026-03-02 16:00:00', 'people' => array( 20 ) ), 'confirmed' => array( 'installer' => 21, 'installer2' => 22 ) ) );
		$s = ZJOB_My_Next::steps( $p, $this->owners );
		$this->assertSame( array( 'install' ), $this->step_names( $s ) );
		$this->assertSame( array( 20, 21, 22 ), $s[0]['owners'] );
		$this->assertSame( '2026-03-02 16:00:00', $s[0]['when'] );
	}

	public function test_money_steps_need_a_billing_answer() {
		$this->assertSame( array(), ZJOB_My_Next::steps( $this->project( array( 'installed' => true ) ), $this->owners ) );
		$s = ZJOB_My_Next::steps( $this->project( array( 'installed' => true, 'money' => array( 'invoiced' => false, 'due' => null ) ) ), $this->owners );
		$this->assertSame( 'invoice', $s[0]['step'] );
		$this->assertTrue( $s[0]['money'] );
		$s = ZJOB_My_Next::steps( $this->project( array( 'installed' => true, 'money' => array( 'invoiced' => true, 'due' => 12.5 ) ) ), $this->owners );
		$this->assertSame( 'payment', $s[0]['step'] );
		$s = ZJOB_My_Next::steps( $this->project( array( 'installed' => true, 'money' => array( 'invoiced' => true, 'due' => 0.0 ) ) ), $this->owners );
		$this->assertSame( array(), $s );
	}

	public function test_is_mine_by_owner_or_by_the_persons_code_on_a_schedule_step() {
		$this->assertTrue( ZJOB_My_Next::is_mine( array( 'step' => 'install', 'owners' => array( 7 ) ), 7, array() ) );
		$this->assertFalse( ZJOB_My_Next::is_mine( array( 'step' => 'install', 'owners' => array( 7 ) ), 8, array() ) );
		$this->assertTrue( ZJOB_My_Next::is_mine( array( 'step' => 'schedule', 'owners' => array(), 'codes' => array( 'ab' ) ), 8, array( 'AB' ) ) );
		$this->assertFalse( ZJOB_My_Next::is_mine( array( 'step' => 'order', 'owners' => array(), 'codes' => array( 'AB' ) ), 8, array( 'AB' ) ) );
		$this->assertFalse( ZJOB_My_Next::is_mine( array( 'step' => 'install', 'owners' => array( 0 ) ), 0, array() ) );
	}

	public function test_sort_puts_visits_by_day_then_prep_order_schedule_walk_money() {
		$mk = function ( $step, $id, $when = '' ) {
			return array( 'step' => $step, 'when' => $when, 'project' => array( 'id' => $id ) );
		};
		$items = array(
			$mk( 'payment', 'P1' ),
			$mk( 'schedule', 'P2' ),
			$mk( 'install', 'P3', '2026-03-05 16:00:00' ),
			$mk( 'order', 'P4' ),
			$mk( 'install', 'P5', '2026-03-02 16:00:00' ),
			$mk( 'walk', '', '2026-03-03 23:59:00' ),
			$mk( 'cut', 'P6' ),
			$mk( 'schedule', 'P9' ),
			$mk( 'install', 'P7', '' ),
		);
		$out = ZJOB_My_Next::sort_present( $items );
		$this->assertSame(
			array( 'install:P5', 'install:P3', 'install:P7', 'cut:P6', 'order:P4', 'schedule:P9', 'schedule:P2', 'walk:', 'payment:P1' ),
			array_map( function ( $i ) { return $i['step'] . ':' . $i['project']['id']; }, $out )
		);
	}

	public function test_pick_visit_prefers_the_next_one_coming_and_keeps_a_passed_one_as_past() {
		$now   = '2026-03-10 12:00:00';
		$floor = '2025-11-10 12:00:00';
		$v     = ZJOB_My_Next::pick_visit( array(
			array( 'start_utc' => '2026-03-01 16:00:00', 'people' => array( 1 ), 'appt' => 0 ),
			array( 'start_utc' => '2026-03-20 16:00:00', 'people' => array( 2 ), 'appt' => 0 ),
			array( 'start_utc' => '2026-03-12 16:00:00', 'people' => array( 3 ), 'appt' => 0 ),
			array( 'start_utc' => '2026-03-12 16:00:00', 'people' => array( 4, 3 ), 'appt' => 9 ),
		), $now, $floor );
		$this->assertSame( '2026-03-12 16:00:00', $v['start_utc'] );
		$this->assertFalse( $v['past'] );
		$this->assertSame( array( 3, 4 ), $v['people'] );
		$p = ZJOB_My_Next::pick_visit( array(
			array( 'start_utc' => '2026-02-01 16:00:00', 'people' => array( 1 ), 'appt' => 0 ),
			array( 'start_utc' => '2026-03-01 16:00:00', 'people' => array( 2 ), 'appt' => 0 ),
			array( 'start_utc' => '2025-01-01 16:00:00', 'people' => array( 5 ), 'appt' => 0 ),
		), $now, $floor );
		$this->assertTrue( $p['past'] );
		$this->assertSame( '2026-03-01 16:00:00', $p['start_utc'] );
		$this->assertNull( ZJOB_My_Next::pick_visit( array( array( 'start_utc' => '0000-00-00 00:00:00' ) ), $now, $floor ) );
	}

	public function test_installed_and_sold() {
		$this->assertTrue( ZJOB_My_Next::is_installed( 'completed', array() ) );
		$this->assertFalse( ZJOB_My_Next::is_installed( 'active', array( array( 'status' => 'done' ), array( 'status' => 'open' ) ) ) );
		$this->assertTrue( ZJOB_My_Next::is_installed( 'active', array( array( 'status' => 'done' ), array( 'status' => 'cancelled' ) ) ) );
		$this->assertFalse( ZJOB_My_Next::is_installed( 'open', array( array( 'status' => 'cancelled' ) ) ) );
		$this->assertFalse( ZJOB_My_Next::is_sold( array() ) );
		$this->assertFalse( ZJOB_My_Next::is_sold( array( array( 'status' => 'cancelled' ) ) ) );
		$this->assertTrue( ZJOB_My_Next::is_sold( array( array( 'status' => 'open' ) ) ) );
	}

	public function test_prep_records_combine_least_across_leads_most_with_the_estimate() {
		$this->assertSame( 'uncut', ZJOB_My_Next::combine_cut( array( 'complete', 'uncut' ), 'unknown' ) );
		$this->assertSame( 'complete', ZJOB_My_Next::combine_cut( array( 'partial' ), 'complete' ) );
		$this->assertSame( 'partial', ZJOB_My_Next::combine_cut( array( 'partial' ), 'uncut' ) );
		$this->assertSame( 'uncut', ZJOB_My_Next::combine_cut( array( 'unknown' ), 'uncut' ) );
		$this->assertSame( 'unknown', ZJOB_My_Next::combine_cut( array(), 'nonsense' ) );
	}

	public function test_city_is_never_the_street_nor_a_region_or_postcode() {
		$this->assertSame( 'Springfield', ZJOB_My_Next::city_of( '12 Example St, Springfield, AB 12345' ) );
		$this->assertSame( 'Springfield', ZJOB_My_Next::city_of( '12 Example St, Springfield AB 12345' ) );
		$this->assertSame( '', ZJOB_My_Next::city_of( '12 Example St, AB 12345' ) );
		$this->assertSame( '', ZJOB_My_Next::city_of( '12 Example St' ) );
		$this->assertSame( 'Riverton', ZJOB_My_Next::city_of( '4 Sample Rd, Riverton (RT), AB, USA' ) );
	}

	public function test_present_item_starts_prep_only_with_a_lead_or_an_estimate_number() {
		$p    = array( 'id' => 'P1', 'code' => 'PRJ-1', 'customer' => 'Alex Example', 'city' => 'Springfield' );
		$open = ZJOB_My_Next::present_item( 'cut', $p, array( 'why' => 'x' ) );
		$this->assertSame( 'projects', $open['start']['app'] );
		$prep = ZJOB_My_Next::present_item( 'cut', $p + array( 'cut_lead' => 5 ), array() );
		$this->assertSame( 5, $prep['start']['options']['lead_id'] );
		$this->assertNotSame( 'projects', $prep['start']['app'] );
	}

	/* ── jobs.my_next_counts ─────────────────────────────────────────── */

	public function test_tally_counts_today_week_passed_other_and_the_next_day() {
		$tz      = new DateTimeZone( 'UTC' );
		$present = array(
			array( 'step' => 'install', 'when' => '2026-03-10 09:00:00' ), // today
			array( 'step' => 'install', 'when' => '2026-03-12 09:00:00' ), // this week
			array( 'step' => 'install', 'when' => '2026-03-08 09:00:00' ), // passed
			array( 'step' => 'install', 'when' => '2026-03-25 09:00:00' ), // later
			array( 'step' => 'install', 'when' => '2026-03-20 09:00:00' ), // later, sooner
			array( 'step' => 'install', 'when' => '' ),                    // no day: other
			array( 'step' => 'order' ),
			array( 'step' => 'walk' ),
			array( 'step' => 'invoice', 'money' => true ),
		);
		$t = ZJOB_My_Counts::tally( $present, '2026-03-10', '2026-03-10', '2026-03-16', $tz );
		$this->assertSame( array( 'today' => 1, 'this_week' => 2, 'passed' => 1, 'other' => 2 ), $t['counts'] );
		$this->assertSame( '2026-03-20', $t['next_install_day'] );
	}

	public function test_tally_reads_the_day_in_the_business_zone() {
		$t = ZJOB_My_Counts::tally( array( array( 'step' => 'install', 'when' => '2026-03-11 02:00:00' ) ), '2026-03-10', '2026-03-10', '2026-03-16', new DateTimeZone( 'America/Denver' ) );
		$this->assertSame( 1, $t['counts']['today'] );
	}

	public function test_the_count_verb_refuses_nobody_and_the_shared_device_and_never_returns_zero_on_failure() {
		$this->assertTrue( ZJOB_My_Counts::from_capability( array( 'user_id' => 0 ) )['denied'] );
		$this->assertSame( 'kiosk', ZJOB_My_Counts::from_capability( array( 'user_id' => 5, 'is_kiosk' => true ) )['reason'] );
		ZJOB_My_Counts::$read = function () { return null; };
		$r = ZJOB_My_Counts::from_capability( array( 'user_id' => 5, 'today' => '2026-03-10' ) );
		$this->assertFalse( $r['success'] );
		$this->assertArrayNotHasKey( 'counts', $r );
		ZJOB_My_Counts::$read = function () { return array( 'present' => array( array( 'step' => 'install', 'when' => '2026-03-10 09:00:00' ) ) ); };
		$r = ZJOB_My_Counts::from_capability( array( 'user_id' => 5, 'today' => '2026-03-10', 'from' => '2026-03-10', 'to' => '2026-03-16' ) );
		$this->assertTrue( $r['success'] );
		$this->assertSame( 1, $r['counts']['this_week'] );
		$bad = ZJOB_My_Counts::from_capability( array( 'user_id' => 5, 'today' => '2026-03-10', 'from' => '2026-03-01', 'to' => '2026-03-30' ) );
		$this->assertFalse( $bad['success'] );
		ZJOB_My_Counts::$read = null;
		$d = ZJOB_My_Counts::descriptor();
		$this->assertFalse( $d['side_effect'] );
		$this->assertFalse( $d['kiosk'] );
		$this->assertSame( 'jobs.my_next_counts', $d['verb'] );
	}

	/* ── jobs.installed_counts ───────────────────────────────────────── */

	public function test_a_confirmed_seller_owns_it_else_the_maker_or_a_code() {
		$this->assertTrue( ZJOB_Installed_Counts::is_yours( 5, 5, 9, array(), array() ) );
		$this->assertFalse( ZJOB_Installed_Counts::is_yours( 5, 6, 5, array( 'AB' ), array( 'AB' ) ) ); // someone else is confirmed
		$this->assertTrue( ZJOB_Installed_Counts::is_yours( 5, 0, 5, array(), array() ) );
		$this->assertTrue( ZJOB_Installed_Counts::is_yours( 5, 0, 0, array( ' ab ' ), array( 'AB' ) ) );
		$this->assertFalse( ZJOB_Installed_Counts::is_yours( 5, 0, 7, array( 'CD' ), array( 'AB' ) ) );
		// No "installed by hand" mark is kept, so none is described or sent (the tile never shows "marked 0").
		$this->assertSame( array( 'installed', 'by_jobs' ), array_keys( ZJOB_Installed_Counts::definitions() ) );
	}

	public function test_finished_at_is_the_last_done_job_and_nothing_while_one_is_live() {
		$jobs = array(
			array( 'id' => 1, 'status' => 'done', 'worker_done_at' => '2026-03-03 10:00:00', 'closed_at' => '2026-03-04 10:00:00' ),
			array( 'id' => 2, 'status' => 'done', 'worker_done_at' => null, 'closed_at' => '0000-00-00 00:00:00', 'updated_at' => '2026-03-05 10:00:00' ),
			array( 'id' => 3, 'status' => 'cancelled' ),
		);
		$this->assertSame( '2026-03-05 10:00:00', ZJOB_Installed_Counts::finished_at( $jobs ) );
		$this->assertSame( '', ZJOB_Installed_Counts::finished_at( array_merge( $jobs, array( array( 'id' => 4, 'status' => 'pending_close' ) ) ) ) );
		$this->assertSame( '', ZJOB_Installed_Counts::finished_at( array( array( 'id' => 3, 'status' => 'cancelled' ) ) ) );
		// Cleared test jobs are left out.
		$this->assertSame( '2026-03-04 10:00:00', ZJOB_Installed_Counts::finished_at( $jobs, array( 2 ) ) );
	}

	public function test_month_window_in_the_business_zone_and_its_limits() {
		$tz  = new DateTimeZone( 'America/Denver' );
		$now = strtotime( '2026-03-10 12:00:00 UTC' );
		$w   = ZJOB_Installed_Counts::month_window( '', $tz, $now );
		$this->assertSame( '2026-03', $w['month'] );
		$this->assertSame( '2026-03-31', $w['to'] );
		$this->assertSame( '2026-03-01 07:00:00', $w['from_utc'] );
		$this->assertArrayHasKey( 'error', ZJOB_Installed_Counts::month_window( '2026-04', $tz, $now ) );
		$this->assertArrayHasKey( 'error', ZJOB_Installed_Counts::month_window( '2026-13', $tz, $now ) );
		$this->assertArrayHasKey( 'error', ZJOB_Installed_Counts::month_window( '2020-01', $tz, $now ) );
		$d = ZJOB_Installed_Counts::descriptor();
		$this->assertFalse( $d['side_effect'] );
		$this->assertSame( 'staff', $d['tier'] );
	}
}
