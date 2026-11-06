<?php
/**
 * Hours: the numbers. A new install's rules are neutral (no standard hours, no meal, overtime or pay-period rules, no
 * reminder, no location asked for), and each rule a business sets is counted exactly: meal periods (never deducted),
 * overtime by day and by week (never counted twice), pay periods, and a length-only reading that never gets a made-up
 * start. The Scheduler link gives nothing, quietly, when the Scheduler is not there.
 *
 * Rule values in these tests are made up for the tests; none is any business's.
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

if ( ! function_exists( '_n' ) ) {
	function _n( $one, $many, $n, $d = null ) { return 1 === (int) $n ? $one : $many; }
}

require_once dirname( __DIR__, 2 ) . '/zorderz-apps/apps/hours/includes/class-zhr-rules.php';
require_once dirname( __DIR__, 2 ) . '/zorderz-apps/apps/hours/includes/class-zhr-time.php';
require_once dirname( __DIR__, 2 ) . '/zorderz-apps/apps/hours/includes/class-zhr-schedule.php';
require_once dirname( __DIR__, 2 ) . '/zorderz-apps/apps/hours/includes/class-zhr-pack.php';

/** The Scheduler's query, as the Hours app reads it. */
class ZHR_Test_Hr_Scheduler {
	public static $asked = array();
	public static function query( $viewer, $a, $b, array $args = array() ) {
		self::$asked[] = array( $viewer, $a, $b, $args );
		return array(
			array( 'owner_user_id' => 21, 'participant_ids' => array(), 'title' => 'Site visit', 'start_utc' => '2026-03-02T15:00:00Z', 'end_utc' => '2026-03-02T16:00:00Z', 'is_all_day' => false ),
			array( 'owner_user_id' => 30, 'participant_ids' => array( 21 ), 'title' => 'Team meeting', 'start_utc' => '2026-03-02T09:00:00Z', 'end_utc' => '2026-03-02T09:30:00Z', 'is_all_day' => false ),
			array( 'owner_user_id' => 30, 'participant_ids' => array(), 'title' => 'Someone else', 'start_utc' => '2026-03-02T10:00:00Z', 'end_utc' => '2026-03-02T11:00:00Z', 'is_all_day' => false ),
		);
	}
}

final class HoursRulesTest extends TestCase {

	/** A made-up meal rule for the tests: one 20-minute meal by the end of the 4th hour; a second by the 8th. */
	private static function meals( $premium = 45 ) {
		return array(
			'periods'         => array(
				array( 'after' => 240, 'length' => 20, 'waive_up_to' => 300 ),
				array( 'after' => 480, 'length' => 20, 'waive_up_to' => null, 'waive_if_previous_not_waived' => true ),
			),
			'premium_minutes' => $premium,
		);
	}

	/** Spans for a day from 'HH:MM' pairs on 2026-03-02 (UTC in the tests). */
	private static function spans( array $pairs ) {
		$out = array();
		foreach ( $pairs as $p ) {
			$a     = ZHR_Time::ts( '2026-03-02 ' . $p[0] . ':00' );
			$b     = ZHR_Time::ts( '2026-03-02 ' . $p[1] . ':00' );
			$out[] = array( $a, $b, (int) ( ( $b - $a ) / 60 ) );
		}
		return $out;
	}

	/* ── neutral defaults ─────────────────────────────────────────────────── */

	public function test_a_new_install_has_no_business_rules(): void {
		$s = ZHR_Time::default_settings();
		foreach ( $s['days'] as $d ) {
			$this->assertFalse( $d['on'] );
			$this->assertSame( '', $d['start'] );
		}
		$this->assertSame( 1, $s['edit_weeks'], 'the shortest edit window' );
		$this->assertFalse( $s['ask_location'] );
		$this->assertFalse( $s['note_location'] );
		$this->assertFalse( $s['everyone'], 'Hours reaches only the people the platform already grants it to' );
		$this->assertFalse( $s['crew_leads'] );
		$this->assertSame( '', $s['remind_at'] );
		$this->assertSame( array(), $s['meals']['periods'] );
		$this->assertSame( 0, $s['meals']['premium_minutes'] );
		$this->assertNull( $s['overtime']['daily_after'] );
		$this->assertNull( $s['overtime']['weekly_after'] );
		$this->assertSame( '', $s['pay_period']['kind'] );
		$this->assertSame( 7, ZHR_Time::default_settings( 7 )['week_start'], 'the site\'s own first day of the week' );
		$this->assertFalse( ZHR_Time::has_standard( $s ) );
	}

	public function test_settings_are_cleaned_and_bounded(): void {
		$s = ZHR_Time::clean_settings( array(
			'week_start' => 9, 'edit_weeks' => 40, 'remind_at' => '25:00', 'surprise' => 'x',
			'days'       => array( 1 => array( 'on' => true, 'start' => '09:00', 'end' => '17:00' ), 2 => array( 'on' => true, 'start' => 'nine', 'end' => '' ) ),
			'meals'      => array( 'periods' => array( array( 'after' => 'x', 'length' => 20 ) ), 'premium_minutes' => 60 ),
			'pay_period' => array( 'kind' => 'biweekly' ),
		) );
		$this->assertSame( 7, $s['week_start'] );
		$this->assertSame( 8, $s['edit_weeks'] );
		$this->assertSame( '', $s['remind_at'], 'a time that cannot be read keeps the one it had' );
		$this->assertArrayNotHasKey( 'surprise', $s );
		$this->assertTrue( $s['days'][1]['on'] );
		$this->assertFalse( $s['days'][2]['on'], 'a day that never had good times stays a day off' );
		$this->assertSame( array(), $s['meals']['periods'], 'an unreadable rule is no rule' );
		$this->assertSame( 0, $s['meals']['premium_minutes'], 'no premium without a rule' );
		$this->assertSame( '', $s['pay_period']['kind'], 'a two-week period with no first day is no period' );
	}

	/* ── meal periods ─────────────────────────────────────────────────────── */

	public function test_with_no_meal_rules_no_meal_is_required_asked_or_owed(): void {
		$m = ZHR_Time::meal_check( self::spans( array( array( '06:00', '18:00' ) ) ) );
		$this->assertSame( array(), $m['meals'] );
		$this->assertSame( 0, $m['premium'] );
		$this->assertFalse( $m['needs_answer'] );
		$this->assertNull( $m['progress'] );
		$t = ZHR_Time::day_totals( ZHR_Time::default_settings(), '2026-03-02', array( array( 'start_at' => '2026-03-02 06:00:00', 'end_at' => '2026-03-02 18:00:00', 'minutes' => 720 ) ), ZHR_Time::ts( '2026-03-03 12:00:00' ) );
		$this->assertSame( 720, $t['worked'], 'nothing is ever deducted' );
		$this->assertFalse( $t['off_day'], 'with no standard hours no day is "a day off"' );
	}

	public function test_a_business_meal_rule_is_checked_and_never_deducted(): void {
		$r = self::meals();
		// 5 h straight: the meal was missed
		$m = ZHR_Time::meal_check( self::spans( array( array( '08:00', '13:00' ) ) ), array(), array(), false, $r );
		$this->assertSame( 'missed', $m['meals'][0]['status'] );
		$this->assertSame( 'not_required', $m['meals'][1]['status'] );
		$this->assertSame( 1, $m['premium'] );
		$this->assertSame( 45, $m['premium_minutes'] );
		$this->assertTrue( $m['needs_answer'] );
		$this->assertSame( 300, $m['worked'] );
		// a 20-minute break after 3 h: on time
		$ok = ZHR_Time::meal_check( self::spans( array( array( '08:00', '11:00' ), array( '11:20', '13:20' ) ) ), array(), array(), false, $r );
		$this->assertSame( 'ok', $ok['meals'][0]['status'] );
		$this->assertSame( 0, $ok['premium'] );
		// the break after 4.5 h: late; answered as the person's own choice: nothing owed
		$late = ZHR_Time::meal_check( self::spans( array( array( '08:00', '12:30' ), array( '12:50', '14:00' ) ) ), array(), array( 1 => 'voluntary' ), false, $r );
		$this->assertSame( 'late', $late['meals'][0]['status'] );
		$this->assertSame( 0, $late['premium'] );
		$this->assertFalse( $late['needs_answer'] );
		// a signed waiver covers a day under its limit, never one over it
		$w = ZHR_Time::meal_check( self::spans( array( array( '08:00', '12:50' ) ) ), array( 1 => true ), array(), false, $r );
		$this->assertSame( 'waived', $w['meals'][0]['status'] );
		$w2 = ZHR_Time::meal_check( self::spans( array( array( '08:00', '13:30' ) ) ), array( 1 => true ), array(), false, $r );
		$this->assertSame( 'missed', $w2['meals'][0]['status'] );
		// with no premium set, a missed meal is still recorded and asked about, but owes nothing
		$np = ZHR_Time::meal_check( self::spans( array( array( '08:00', '13:00' ) ) ), array(), array(), false, self::meals( 0 ) );
		$this->assertSame( 0, $np['premium'] );
		$this->assertTrue( $np['needs_answer'] );
	}

	public function test_a_standard_day_holds_the_meal_only_when_there_is_a_rule(): void {
		$s = ZHR_Time::clean_settings( array( 'days' => array( 1 => array( 'on' => true, 'start' => '08:00', 'end' => '16:30' ) ) ) );
		$this->assertSame( 510, ZHR_Time::standard_day( $s, '2026-03-02' )['minutes'] );
		$s['meals'] = ZHR_Rules::clean_meals( self::meals() );
		$this->assertSame( 490, ZHR_Time::standard_day( $s, '2026-03-02' )['minutes'] );
		$this->assertTrue( ZHR_Time::standard_day( $s, '2026-03-03' )['off'] );
	}

	/* ── overtime ─────────────────────────────────────────────────────────── */

	private static function week( array $minutes, $from = '2026-03-02' ) {
		$out = array();
		foreach ( $minutes as $i => $m ) {
			$out[] = array( 'date' => ( new DateTimeImmutable( $from . ' 12:00:00' ) )->modify( '+' . $i . ' days' )->format( 'Y-m-d' ), 'worked' => $m );
		}
		return $out;
	}

	public function test_with_neutral_rules_nothing_is_overtime(): void {
		$o = ZHR_Rules::overtime( self::week( array( 900, 900, 900, 900, 900, 900, 900 ) ), array(), 1 );
		$this->assertSame( 0, $o['overtime'] );
		$this->assertSame( 6300, $o['regular'] );
	}

	public function test_daily_overtime_line(): void {
		$o = ZHR_Rules::overtime( self::week( array( 500, 420 ) ), array( 'daily_after' => 450 ), 1 );
		$this->assertSame( array( 'regular' => 450, 'overtime' => 50 ), $o['days']['2026-03-02'] );
		$this->assertSame( array( 'regular' => 420, 'overtime' => 0 ), $o['days']['2026-03-03'] );
	}

	public function test_weekly_overtime_line_counts_each_minute_once(): void {
		// a made-up 30-hour week line, a 9-hour day line: Mon..Thu 9 h each
		$o = ZHR_Rules::overtime( self::week( array( 540, 540, 540, 540 ) ), array( 'daily_after' => 540, 'weekly_after' => 1800 ), 1 );
		$this->assertSame( 0, $o['days']['2026-03-02']['overtime'] );
		$this->assertSame( 0, $o['days']['2026-03-03']['overtime'] );
		$this->assertSame( array( 'regular' => 540, 'overtime' => 0 ), $o['days']['2026-03-04'] );
		$this->assertSame( array( 'regular' => 180, 'overtime' => 360 ), $o['days']['2026-03-05'], 'past the week line' );
		$this->assertSame( 2160, $o['regular'] + $o['overtime'], 'nothing gained or lost' );
		// a day already over the day line does not count its overtime toward the week line again
		$o2 = ZHR_Rules::overtime( self::week( array( 600, 600, 600 ) ), array( 'daily_after' => 480, 'weekly_after' => 1440 ), 1 );
		$this->assertSame( 360, $o2['overtime'] );
		$this->assertSame( 1440, $o2['regular'] );
		// a new week starts its line again (week starting Monday; 2026-03-09 is the next Monday)
		$o3 = ZHR_Rules::overtime( array( array( 'date' => '2026-03-08', 'worked' => 600 ), array( 'date' => '2026-03-09', 'worked' => 600 ) ), array( 'weekly_after' => 600 ), 1 );
		$this->assertSame( 0, $o3['overtime'] );
		$o4 = ZHR_Rules::overtime( array( array( 'date' => '2026-03-08', 'worked' => 600 ), array( 'date' => '2026-03-09', 'worked' => 600 ) ), array( 'weekly_after' => 600 ), 7 );
		$this->assertSame( 600, $o4['overtime'], 'weeks starting Sunday: the 8th and the 9th are one week' );
		$o5 = ZHR_Rules::overtime( array( array( 'date' => '2026-03-07', 'worked' => 600 ), array( 'date' => '2026-03-08', 'worked' => 600 ) ), array( 'weekly_after' => 600 ), 1 );
		$this->assertSame( 600, $o5['overtime'], 'Saturday and Sunday are one Monday-start week' );
	}

	/* ── periods ──────────────────────────────────────────────────────────── */

	public function test_pay_periods(): void {
		$this->assertNull( ZHR_Rules::pay_period( array(), '2026-03-04' ), 'no pay period set: none' );
		$this->assertSame( array( '2026-03-02', '2026-03-08' ), ZHR_Rules::pay_period( array( 'kind' => 'weekly' ), '2026-03-04', 1 ) );
		$this->assertSame( array( '2026-03-01', '2026-03-07' ), ZHR_Rules::pay_period( array( 'kind' => 'weekly' ), '2026-03-04', 7 ) );
		$bi = array( 'kind' => 'biweekly', 'anchor' => '2026-02-23' );
		$this->assertSame( array( '2026-02-23', '2026-03-08' ), ZHR_Rules::pay_period( $bi, '2026-03-04' ) );
		$this->assertSame( array( '2026-03-09', '2026-03-22' ), ZHR_Rules::pay_period( $bi, '2026-03-09' ) );
		$this->assertSame( array( '2026-02-09', '2026-02-22' ), ZHR_Rules::pay_period( $bi, '2026-03-04', 1, 1 ), 'the period before' );
		$this->assertSame( array( '2026-02-09', '2026-02-22' ), ZHR_Rules::pay_period( $bi, '2026-02-22' ), 'before the first day, periods count back from it' );
		$this->assertNull( ZHR_Rules::pay_period( array( 'kind' => 'biweekly' ), '2026-03-04' ) );
		$this->assertSame( array( '2026-03-01', '2026-03-15' ), ZHR_Rules::pay_period( array( 'kind' => 'semimonthly' ), '2026-03-04' ) );
		$this->assertSame( array( '2026-02-16', '2026-02-28' ), ZHR_Rules::pay_period( array( 'kind' => 'semimonthly' ), '2026-03-04', 1, 1 ) );
		$this->assertSame( array( '2026-01-16', '2026-01-31' ), ZHR_Rules::pay_period( array( 'kind' => 'semimonthly' ), '2026-02-10', 1, 1 ) );
		$this->assertSame( array( '2025-12-16', '2025-12-31' ), ZHR_Rules::pay_period( array( 'kind' => 'semimonthly' ), '2026-01-10', 1, 1 ), 'across the year' );
		$this->assertSame( array( '2026-02-01', '2026-02-28' ), ZHR_Rules::pay_period( array( 'kind' => 'monthly' ), '2026-03-04', 1, 1 ) );
	}

	public function test_named_periods_follow_the_week_start_and_the_pay_period(): void {
		$this->assertSame( array( '2026-03-02', '2026-03-08' ), array_slice( ZHR_Time::period( 'this_week', '2026-03-04', 1 ), 0, 2 ) );
		$this->assertSame( array( '2026-03-01', '2026-03-07' ), array_slice( ZHR_Time::period( 'this_week', '2026-03-04', 7 ), 0, 2 ) );
		$this->assertSame( array( '2026-02-23', '2026-03-01' ), array_slice( ZHR_Time::period( 'last_week', '2026-03-04', 1 ), 0, 2 ) );
		$this->assertSame( array( '2026-03-02', '2026-03-08' ), array_slice( ZHR_Time::period( 'this_pay_period', '2026-03-04', 1 ), 0, 2 ), 'no pay period: this week' );
		$this->assertSame( array( '2026-03-01', '2026-03-15' ), array_slice( ZHR_Time::period( 'this_pay_period', '2026-03-04', 1, '', '', array( 'kind' => 'semimonthly' ) ), 0, 2 ) );
		$this->assertNotContains( 'this_pay_period', ZHR_Time::period_keys() );
		$this->assertContains( 'this_pay_period', ZHR_Time::period_keys( array( 'kind' => 'monthly' ) ) );
		$this->assertSame( '2026-03-02', ZHR_Time::edit_from( '2026-03-04', ZHR_Time::default_settings() ), 'this week only' );
	}

	/* ── what a reading may become ────────────────────────────────────────── */

	public function test_a_length_alone_never_gets_a_made_up_start(): void {
		$read = array( 'entries' => array( array( 'date' => '2026-03-02', 'start' => '', 'end' => '', 'minutes' => 480, 'note' => '', 'name' => '' ) ) );
		$p    = ZHR_Time::proposals_from_model( $read, ZHR_Time::default_settings(), '2026-03-04' );
		$this->assertTrue( $p[0]['needs_start'] );
		$this->assertSame( '', $p[0]['start'] );
		$s = ZHR_Time::clean_settings( array( 'days' => array( 1 => array( 'on' => true, 'start' => '09:00', 'end' => '17:00' ) ) ) );
		$q = ZHR_Time::proposals_from_model( $read, $s, '2026-03-04' );
		$this->assertSame( '09:00', $q[0]['start'], 'the business\'s own standard start, marked as assumed' );
		$this->assertSame( '17:00', $q[0]['end'] );
		$this->assertTrue( $q[0]['assumed'] );
		$none = ZHR_Time::proposals_from_model( array( 'entries' => array( array( 'date' => '2026-03-02', 'start' => '08:00', 'end' => '', 'minutes' => 0 ) ) ), $s, '2026-03-04' );
		$this->assertSame( array(), $none, 'a start with no end and no length is dropped, never filled in' );
	}

	public function test_a_finish_is_never_in_the_future_or_past_sixteen_hours(): void {
		$now = ZHR_Time::ts( '2026-03-02 18:00:00' );
		$ok  = ZHR_Time::finish_at( '2026-03-02 08:00:00', '16:30', $now );
		$this->assertTrue( $ok['ok'] );
		$this->assertSame( 510, $ok['minutes'] );
		$this->assertSame( 'finish_future', ZHR_Time::finish_at( '2026-03-02 08:00:00', '18:30', $now )['code'] );
		$this->assertFalse( ZHR_Time::finish_at( '2026-03-02 08:00:00', '07:00', ZHR_Time::ts( '2026-03-04 12:00:00' ) )['ok'] );
	}

	/* ── an Identity Pack ─────────────────────────────────────────────────── */

	public function test_a_pack_sets_the_rules_checked_and_refuses_what_it_cannot_read(): void {
		$this->assertNull( ZHR_Pack::plan( array( 'rules' => array( 'other' => 1 ) ) ), 'no hours block: nothing to do' );
		$p = ZHR_Pack::plan( array(
			'rules'        => array( 'hours' => array(
				'week_start'     => 'sunday',
				'standard_hours' => array( 'monday' => array( 'start' => '09:00', 'end' => '17:00' ), 'tuesday' => array( 'off' => true ) ),
				'edit_weeks'     => 3,
				'remind_at'      => '19:30',
				'crew_leads_see_crew' => 'yes',
				'meal_periods'   => array( array( 'after_minutes' => 240, 'length_minutes' => 20, 'waivable_up_to_minutes' => 300 ) ),
				'surprise'       => 1,
			) ),
			'compensation' => array( 'hourly' => array(
				'meal_premium_minutes' => 45,
				'overtime'             => array( 'daily_after_minutes' => 450, 'weekly_after_minutes' => null ),
				'pay_period'           => array( 'kind' => 'biweekly', 'anchor' => '2026-02-23' ),
			) ),
		) );
		$this->assertSame( 7, $p['patch']['week_start'] );
		$this->assertTrue( $p['patch']['days'][1]['on'] );
		$this->assertSame( '09:00', $p['patch']['days'][1]['start'] );
		$this->assertFalse( $p['patch']['days'][2]['on'] );
		$this->assertFalse( $p['patch']['days'][3]['on'], 'a weekday the pack does not list is a day off' );
		$this->assertSame( 3, $p['patch']['edit_weeks'] );
		$this->assertTrue( $p['patch']['crew_leads'] );
		$this->assertSame( 240, $p['patch']['meals']['periods'][0]['after'] );
		$this->assertSame( 45, $p['patch']['meals']['premium_minutes'] );
		$this->assertSame( array( 'daily_after' => 450, 'weekly_after' => null ), $p['patch']['overtime'] );
		$this->assertSame( 'biweekly', $p['patch']['pay_period']['kind'] );
		$this->assertSame( array( 'hours.surprise' ), $p['unconsumed'] );
		$this->assertSame( array(), $p['refused'] );

		$bad = ZHR_Pack::plan( array(
			'rules'        => array( 'hours' => array(
				'week_start'     => 'someday',
				'standard_hours' => array( 'monday' => array( 'start' => '17:00', 'end' => '09:00' ) ),
				'remind_at'      => 'evening',
				'meal_periods'   => array( array( 'after_minutes' => 240 ) ),
			) ),
			'compensation' => array( 'hourly' => array( 'pay_period' => array( 'kind' => 'biweekly' ), 'overtime' => array( 'daily_after_minutes' => -5 ) ) ),
		) );
		$this->assertSame( array(), $bad['patch'], 'nothing it cannot read is written, and half a week is never applied' );
		$this->assertCount( 6, $bad['refused'] );
	}

	/* ── the Scheduler link ───────────────────────────────────────────────── */

	public function test_no_scheduler_no_appointments_and_no_error(): void {
		$this->assertFalse( ZHR_Schedule::available( 'ZHR_Test_Hr_No_Such_Scheduler' ) );
		$this->assertSame( array(), ZHR_Schedule::for_person( 21, '2026-03-02', '2026-03-02', 21, 'ZHR_Test_Hr_No_Such_Scheduler' ) );
	}

	public function test_scheduler_rows_are_the_persons_own_read_with_the_viewers_rights(): void {
		ZHR_Test_Hr_Scheduler::$asked = array();
		$rows = ZHR_Schedule::for_person( 21, '2026-03-02', '2026-03-02', 40, 'ZHR_Test_Hr_Scheduler' );
		$this->assertSame( 40, ZHR_Test_Hr_Scheduler::$asked[0][0], 'asked as the viewer, never as the person' );
		$this->assertSame( 21, ZHR_Test_Hr_Scheduler::$asked[0][3]['owner_id'] );
		$this->assertCount( 2, $rows['2026-03-02'], 'own and taking part; never someone else\'s' );
		$this->assertSame( 'Team meeting', $rows['2026-03-02'][0]['title'], 'in time order' );
		$this->assertSame( '15:00', $rows['2026-03-02'][1]['start'] );
	}
}
