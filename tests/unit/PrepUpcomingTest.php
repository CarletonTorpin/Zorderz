<?php
/**
 * Upcoming production, with neutral sheets and kinds:
 *
 *   - ZPREP_Upcoming::prep_by(): counted back over the shop's working days; an install on a day off is prepared by
 *     the working day before; an unbooked job has no day;
 *   - sheets_for(): a kind's sheets, narrowed by the words a line names, all of them when no line names any;
 *   - plan(): each job's earliest prep-by day, late when it is before today, unbooked last; per day and sheet the
 *     count, flagged over the sheet's capacity;
 *   - the Core sheets (one per kind of work) when the business names none; a sheet is checked value by value;
 *   - who sees it: an unknown audience reads as administrators only; hidden roles are cleaned;
 *   - ZJOB_Upcoming: the window rule and amounts taken out of what a prep sheet carries; the verb is a read that the
 *     shared device and nobody signed in are refused.
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/zorderz-apps/apps/prep/includes/class-zprep-upcoming.php';
require_once dirname( __DIR__, 2 ) . '/zorderz-apps/apps/prep/includes/class-zprep-pack.php';
require_once dirname( __DIR__, 2 ) . '/zorderz-apps/apps/jobs/includes/class-zjob-my-next.php';
require_once dirname( __DIR__, 2 ) . '/zorderz-apps/apps/jobs/includes/class-zjob-my-counts.php';
require_once dirname( __DIR__, 2 ) . '/zorderz-apps/apps/jobs/includes/class-zjob-upcoming.php';

class PrepUpcomingTest extends TestCase {

	private function sheets(): array {
		return array_map( array( 'ZPREP_Upcoming', 'clean_sheet' ), array(
			array( 'key' => 'frames', 'label' => 'Frames', 'kinds' => array( 'install' ), 'lead_days' => 2, 'capacity' => 1, 'steps' => array( 'Cut the frame', 'Check the corners' ) ),
			array( 'key' => 'doors', 'label' => 'Doors', 'kinds' => array( 'install' ), 'words' => array( 'door' ), 'lead_days' => 1 ),
			array( 'key' => 'repair', 'label' => 'Repairs', 'kinds' => array( 'service' ) ),
		) );
	}

	public function test_prep_by_counts_back_over_working_days() {
		$weekdays = array( 1, 2, 3, 4, 5 );
		$this->assertSame( '2026-03-11', ZPREP_Upcoming::prep_by( '2026-03-11', 0, $weekdays ) );      // a Wednesday
		$this->assertSame( '2026-03-09', ZPREP_Upcoming::prep_by( '2026-03-11', 2, $weekdays ) );      // two working days back
		$this->assertSame( '2026-03-05', ZPREP_Upcoming::prep_by( '2026-03-09', 2, $weekdays ) );      // over the weekend
		$this->assertSame( '2026-03-13', ZPREP_Upcoming::prep_by( '2026-03-15', 0, $weekdays ) );      // a Sunday install
		$this->assertSame( '2026-03-13', ZPREP_Upcoming::prep_by( '2026-03-15', 2, array( 1, 2, 3, 4, 5, 6, 7 ) ) );
		$this->assertSame( '', ZPREP_Upcoming::prep_by( '', 2, $weekdays ) );
	}

	public function test_sheets_for_narrows_by_words_and_falls_back_to_all() {
		$s = $this->sheets();
		$this->assertSame( array( 'frames', 'doors' ), ZPREP_Upcoming::sheets_for( array( 'install' ), array( array( 'name' => 'Front door', 'description' => '' ) ), $s ) );
		$this->assertSame( array( 'frames' ), ZPREP_Upcoming::sheets_for( array( 'install' ), array( array( 'name' => 'Window frame', 'description' => '' ) ), $s ) );
		$this->assertSame( array( 'frames' ), ZPREP_Upcoming::sheets_for( array( 'install' ), array( array( 'name' => 'Doorway trim' ) ), $s ) ); // a whole word only
		$this->assertSame( array( 'repair' ), ZPREP_Upcoming::sheets_for( array( 'service', 'other' ), array(), $s ) );
		$this->assertSame( array(), ZPREP_Upcoming::sheets_for( array( 'other' ), array(), $s ) );
	}

	public function test_plan_orders_by_prep_day_flags_late_and_over_capacity() {
		$items = array(
			array( 'key' => 'project:B', 'kinds' => array( 'install' ), 'install_day' => '2026-03-12', 'lines' => array() ),
			array( 'key' => 'project:A', 'kinds' => array( 'install' ), 'install_day' => '2026-03-12', 'lines' => array( array( 'name' => 'Back door' ) ) ),
			array( 'key' => 'project:C', 'kinds' => array( 'service' ), 'install_day' => '', 'lines' => array() ),
			array( 'key' => 'project:D', 'kinds' => array( 'service' ), 'install_day' => '2026-03-08', 'lines' => array() ),
		);
		$p = ZPREP_Upcoming::plan( $items, $this->sheets(), '2026-03-09', array( 1, 2, 3, 4, 5 ) );
		$this->assertSame( array( 'project:D', 'project:A', 'project:B', 'project:C' ), array_column( $p['items'], 'key' ) );
		$this->assertSame( '2026-03-06', $p['items'][0]['prep_by'] ); // a Sunday install, ready the Friday before
		$this->assertTrue( $p['items'][0]['late'] );
		$this->assertSame( '2026-03-10', $p['items'][1]['prep_by'] );
		$this->assertSame( '', $p['items'][3]['prep_by'] );
		$this->assertFalse( $p['items'][3]['late'] );
		$tue = array_values( array_filter( $p['days'], function ( $d ) { return '2026-03-10' === $d['day']; } ) )[0];
		$this->assertSame( 2, $tue['sheets']['frames']['count'] );
		$this->assertTrue( $tue['sheets']['frames']['over'] );
		$wed = array_values( array_filter( $p['days'], function ( $d ) { return '2026-03-11' === $d['day']; } ) )[0];
		$this->assertFalse( $wed['sheets']['doors']['over'] ); // no capacity named: never over
	}

	public function test_core_sheets_are_one_per_kind_and_a_sheet_is_checked() {
		unset( $GLOBALS['zdz_test_options'][ ZPREP_Upcoming::SHEETS_OPT ] );
		$s = ZPREP_Upcoming::sheets( array( 'install', 'service', 'install', '' ) );
		$this->assertSame( array( 'install', 'service' ), array_column( $s, 'key' ) );
		$this->assertSame( 0, $s[0]['lead_days'] );
		$this->assertNull( $s[0]['capacity'] );
		$this->assertNull( ZPREP_Upcoming::clean_sheet( array( 'key' => '', 'label' => 'x' ) ) );
		$c = ZPREP_Upcoming::clean_sheet( array( 'key' => 'A b!', 'label' => '<b>Bold</b>', 'lead_days' => 99, 'capacity' => 0 ) );
		$this->assertSame( 'ab', $c['key'] );
		$this->assertSame( 'Bold', $c['label'] );
		$this->assertSame( 30, $c['lead_days'] );
		$this->assertNull( $c['capacity'] );
		$this->assertSame( array( 1, 2, 3, 4, 5, 6, 7 ), ZPREP_Upcoming::working_days() );
		$plan = ZPREP_Pack::plan( array( 'rules' => array( 'prep' => array( 'sheets' => array( array( 'key' => 'k', 'label' => 'K' ), 'junk' ), 'working_days' => array( 1, 9, 2 ) ) ) ) );
		$this->assertCount( 1, $plan[ ZPREP_Upcoming::SHEETS_OPT ] );
		$this->assertSame( array( 1, 2 ), $plan[ ZPREP_Upcoming::DAYS_OPT ] );
	}

	public function test_who_sees_it_reads_narrow_when_unsure() {
		$this->assertSame( 'prep', ZPREP_Upcoming::audience( null ) );
		$this->assertSame( 'prep', ZPREP_Upcoming::audience( 'prep' ) );
		$this->assertSame( 'admin', ZPREP_Upcoming::audience( 'admin' ) );
		$this->assertSame( 'admin', ZPREP_Upcoming::audience( 'someone' ), 'an unknown value is the narrow side' );
		$this->assertSame( array( 'shop_role', 'other' ), ZPREP_Upcoming::hidden_roles( array( 'Shop_Role', '', 'other', 'shop_role' ) ) );
		$this->assertSame( array(), ZPREP_Upcoming::hidden_roles( 'shop_role' ) );
		$plan = ZPREP_Pack::plan( array( 'rules' => array( 'prep' => array( 'upcoming_audience' => 'everyone', 'upcoming_hidden_roles' => array( 'shop_role' ) ) ) ) );
		$this->assertSame( 'admin', $plan[ ZPREP_Upcoming::AUDIENCE_OPT ] );
		$this->assertSame( array( 'shop_role' ), $plan[ ZPREP_Upcoming::HIDDEN_OPT ] );
	}

	public function test_the_window_rule() {
		$p = array( 'install_day' => '2026-03-12', 'kinds' => array( 'install' ), 'created' => '2026-01-01' );
		$this->assertTrue( ZJOB_Upcoming::in_window( $p, '2026-03-10', '2026-03-20', array(), false, '2026-02-01' ) );
		$this->assertFalse( ZJOB_Upcoming::in_window( $p, '2026-03-13', '2026-03-20', array(), false, '2026-02-01' ) );
		$this->assertFalse( ZJOB_Upcoming::in_window( $p, '2026-03-10', '2026-03-20', array( 'service' ), false, '2026-02-01' ) );
		$u = array( 'install_day' => '', 'kinds' => array(), 'created' => '2026-02-15' );
		$this->assertFalse( ZJOB_Upcoming::in_window( $u, '2026-03-10', '2026-03-20', array(), false, '2026-02-01' ) );
		$this->assertTrue( ZJOB_Upcoming::in_window( $u, '2026-03-10', '2026-03-20', array(), true, '2026-02-01' ) );
		$this->assertFalse( ZJOB_Upcoming::in_window( array( 'install_day' => '', 'created' => '2026-01-15' ), '2026-03-10', '2026-03-20', array(), true, '2026-02-01' ) );
	}

	public function test_a_prep_sheet_never_carries_an_amount() {
		$this->assertSame( 'Panel 36x60', ZJOB_Upcoming::strip_amounts( 'Panel 36x60 $45.00/ea' ) );
		$this->assertSame( 'Labour', ZJOB_Upcoming::strip_amounts( 'Labour @ 85/hr' ) );
		$this->assertSame( 'Deposit', ZJOB_Upcoming::strip_amounts( 'Deposit 500.00' ) );
		$this->assertSame( 'Total 6 pieces', ZJOB_Upcoming::strip_amounts( 'Total 6 pieces' ) );
		$this->assertSame( 'Track 120.00 long', ZJOB_Upcoming::strip_amounts( 'Track 120.00 long' ) );
		$this->assertSame( 'Trim', ZJOB_Upcoming::strip_amounts( "Trim \u{20AC}12" ) );
		$l = ZJOB_Upcoming::strip_line( array( 'desc' => 'Frame $1,250', 'sub' => 'white', 'qty' => '2.0' ) );
		$this->assertSame( array( 'name' => 'Frame', 'description' => 'white', 'qty' => 2 ), $l );
		$this->assertSame( '12 Example St', ZJOB_Upcoming::street( '12 Example St, Springfield' ) );
		$this->assertSame( '', ZJOB_Upcoming::street( 'Springfield' ) );
	}

	public function test_the_verb_is_a_read_and_refuses_the_shared_device() {
		$d = ZJOB_Upcoming::descriptor();
		$this->assertFalse( $d['side_effect'] );
		$this->assertFalse( $d['kiosk'] );
		$this->assertSame( 'staff', $d['tier'] );
		$this->assertTrue( ZJOB_Upcoming::from_capability( array( 'user_id' => 0 ) )['denied'] );
		$this->assertSame( 'kiosk', ZJOB_Upcoming::from_capability( array( 'user_id' => 4, 'is_kiosk' => true ) )['reason'] );
		$bad = ZJOB_Upcoming::from_capability( array( 'user_id' => 4, 'from' => '2026-03-10', 'to' => '2026-05-10' ) );
		$this->assertFalse( $bad['success'] );
	}
}
