<?php
/**
 * The ask bar's rules and each person's home apps:
 *
 *   - ZDZ_Ask_Phrases: launch rows open the right app (a verb where one is required), a named person's commission is a
 *     card while an aggregate goes to chat, a stock frame names its item and refuses a person or a time; an app's bad
 *     row is dropped, not fatal; the trade's own words only ever add to a rule;
 *   - ZDZ_Ask_Actions: what speech adds is taken off, a question or a report is never an action, the first matching row
 *     for an app the person can open wins, its name and estimate number go into the launch options, and the business's
 *     own name in a spoken greeting is taken off like "hey";
 *   - ZDZ_For_You: hours are cleaned, work and off are decided across midnight, the hour bands cover the day once, the
 *     ranking keeps the role's list until opens outweigh it, opens before a reset or in the future never count, and the
 *     shared device gets nothing.
 *
 * Neutral data only (Alex, Jordan, Example Co).
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/support/privacy-stubs.php';
if ( ! function_exists( 'wp_timezone_string' ) ) {
	function wp_timezone_string() { return 'UTC'; }
}

require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-capabilities.php';
require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-ask-phrases.php';
require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-ask-actions.php';
require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-for-you.php';

final class AssistantAskBarTest extends TestCase {

	protected function setUp(): void {
		// Another file may have loaded the profile and kept a resolved copy: start from no profile.
		if ( class_exists( 'ZDZ_Business_Profile' ) ) {
			ZDZ_Business_Profile::flush();
		}
	}

	protected function tearDown(): void {
		unset( $GLOBALS['zdz_test_filters'], $GLOBALS['zdz_test_options'], $GLOBALS['zdz_test_user_roles'],
			$GLOBALS['zdz_test_user_meta'], $GLOBALS['zdz_test_current_user'], $GLOBALS['zdz_test_transients'] );
	}

	/* ── ZDZ_Ask_Phrases ───────────────────────────────────────────────────────────────────────────── */

	public function test_every_shipped_pattern_runs_on_both_sides(): void {
		foreach ( ZDZ_Ask_Phrases::launch_rows() as $r ) {
			$this->assertTrue( ZDZ_Ask_Phrases::valid( $r['re'] ), $r['re'] );
		}
		$c = ZDZ_Ask_Phrases::commission();
		foreach ( array_merge( $c['earn'], $c['self'], array( $c['person_specific'], $c['company_wide'], $c['period'] ), array_values( $c['take'] ) ) as $p ) {
			$this->assertTrue( ZDZ_Ask_Phrases::valid( $p ), $p );
		}
		foreach ( $c['subject'] as $s ) {
			$this->assertTrue( ZDZ_Ask_Phrases::valid( $s['re'] ), $s['re'] );
		}
		$this->assertFalse( ZDZ_Ask_Phrases::valid( '(?<=x)y' ), 'lookbehind is refused (the page cannot run it everywhere)' );
		$this->assertFalse( ZDZ_Ask_Phrases::valid( 'a++' ), 'possessive quantifiers are refused' );
	}

	public function test_launch_rows_need_a_verb_where_required(): void {
		$this->assertSame( 'estimate-creator', ZDZ_Ask_Phrases::match_launch( 'new estimate for Jordan Example' )['id'] );
		$this->assertSame( 'Jordan Example', ZDZ_Ask_Phrases::match_launch( 'new estimate for Jordan Example' )['value'] );
		$this->assertNull( ZDZ_Ask_Phrases::match_launch( 'estimate for Jordan' ), 'estimates need a launch verb' );
		$this->assertSame( 'scheduler', ZDZ_Ask_Phrases::match_launch( 'schedule tomorrow' )['id'], 'the schedule may start the ask' );
		$this->assertSame( 'tomorrow', ZDZ_Ask_Phrases::match_launch( 'schedule tomorrow' )['value'] );
		$this->assertNull( ZDZ_Ask_Phrases::match_launch( 'open commission by salesperson' ), 'an aggregate never opens Commission' );
		$this->assertNull( ZDZ_Ask_Phrases::match_launch( 'open leads', array( 'scheduler' ) ), 'only an app the person can open' );
	}

	public function test_an_apps_bad_row_is_dropped_not_fatal(): void {
		$GLOBALS['zdz_test_filters']['zdz_ask_launch_rows'] = function ( $rows ) {
			$rows[] = array( 'ids' => array( 'thing' ), 're' => '(?<=a)b' );
			$rows[] = array( 'ids' => array( 'other' ), 're' => '\b(widget board)\b', 'cc' => 'red;background:url(x)' );
			return $rows;
		};
		$ids = array_map( function ( $r ) { return $r['ids'][0]; }, ZDZ_Ask_Phrases::launch_rows() );
		$this->assertNotContains( 'thing', $ids );
		$this->assertContains( 'other', $ids );
		$rows = ZDZ_Ask_Phrases::launch_rows();
		$this->assertSame( '#64748B', end( $rows )['cc'], 'a colour that is not a colour falls back' );
	}

	public function test_commission_names_a_person_or_goes_to_chat(): void {
		$m = ZDZ_Ask_Phrases::match_commission( "Alex's May commission" );
		$this->assertSame( 'Alex', $m['subject'] );
		$this->assertSame( 'May', $m['period'] );
		$this->assertSame( 'products', ZDZ_Ask_Phrases::match_commission( "Jordan's commission by product" )['focus'] );
		$this->assertSame( 'basis', ZDZ_Ask_Phrases::match_commission( "how is Jordan's commission calculated" )['focus'] );
		$this->assertSame( 'Jordan', ZDZ_Ask_Phrases::match_commission( 'how much did Jordan earn last month' )['subject'] );
		$this->assertSame( '', ZDZ_Ask_Phrases::match_commission( 'my commission this month' )['subject'], 'self' );
		$this->assertNull( ZDZ_Ask_Phrases::match_commission( 'commission by salesperson' ) );
		$this->assertNull( ZDZ_Ask_Phrases::match_commission( 'average commission this year' ) );
		$this->assertNull( ZDZ_Ask_Phrases::match_commission( 'how much did the Example job make' ), 'a job is not a person' );
		$this->assertNull( ZDZ_Ask_Phrases::match_commission( 'luanch commission' ), 'a slipped verb is not a person' );
		$two = ZDZ_Ask_Phrases::match_commission( 'Alex and Jordan commission' );
		$this->assertSame( 'Alex', $two['subject'] );
		$this->assertSame( array( 'Jordan' ), $two['others'] );
	}

	public function test_trade_words_mark_things_never_people(): void {
		$this->assertSame( 'Gate', ZDZ_Ask_Phrases::match_commission( 'how much did Gate earn' )['subject'] ?? null );
		$GLOBALS['zdz_test_options']['zdz_ask_trade_words'] = array( 'gate', 'Awning', 'bad|word', '  ' );
		$this->assertSame( array( 'gate', 'awning' ), ZDZ_Ask_Phrases::trade_words(), 'anything that could change a pattern is dropped' );
		$this->assertNull( ZDZ_Ask_Phrases::match_commission( 'how much did the Example gate make' ) );
		$this->assertSame( 'gate(?:s|es)?|awning(?:s|es)?', ZDZ_Ask_Phrases::trade_alt() );
	}

	public function test_stock_frames_name_an_item_and_refuse_people_and_times(): void {
		$this->assertSame( 'tape', ZDZ_Ask_Phrases::match_stock( 'how much tape do we have' ) );
		$this->assertSame( 'hinges', ZDZ_Ask_Phrases::match_stock( 'do we have any hinges in stock?' ) );
		$this->assertSame( '', ZDZ_Ask_Phrases::match_stock( 'how many leads do we have' ) );
		$this->assertSame( '', ZDZ_Ask_Phrases::match_stock( "what's available tomorrow" ) );
		$this->assertSame( '', ZDZ_Ask_Phrases::match_stock( 'how many are left on the Example job right now please tell me' ) );
	}

	public function test_stock_rule_reaches_the_page_only_with_the_verb(): void {
		$js = ZDZ_Ask_Phrases::for_js( 0 );
		$this->assertNull( $js['stock'] );
		$this->assertIsArray( $js['actions'] );
		$this->assertSame( ZDZ_Ask_Actions::V, $js['actions']['v'] );
	}

	/* ── ZDZ_Ask_Actions ───────────────────────────────────────────────────────────────────────────── */

	public function test_what_speech_adds_is_taken_off(): void {
		$this->assertSame( 'send the estimate to jordan', ZDZ_Ask_Actions::normalize( 'Um, okay so can you please send the estimate to Jordan, thanks' ) );
		$this->assertSame( 'send estimate 6523', ZDZ_Ask_Actions::normalize( 'send estimate six five two three' ) );
		$this->assertSame( 'call alex', ZDZ_Ask_Actions::normalize( "I'm gonna call Alex" ), 'contractions open, then the lead-in comes off' );
		$this->assertSame( 'zoe', ZDZ_Ask_Actions::normalize( 'Zoé' ), 'an accent is folded, not a space' );
		$this->assertLessThanOrEqual( 300, strlen( ZDZ_Ask_Actions::normalize( str_repeat( 'send ', 400 ) ) ) );
	}

	public function test_the_business_name_in_a_greeting_is_taken_off(): void {
		$alt = ZDZ_Ask_Actions::wake_alt( array( 'Example Co', 'EC', 'X' ) );
		$this->assertSame( 'example ?co|ec', $alt, 'longest first; one letter is not a name' );
		$this->assertSame( '', ZDZ_Ask_Actions::wake_alt( array() ) );
		$this->assertSame( '', ZDZ_Ask_Actions::wake_words(), 'no profile, no wake words' );
	}

	public function test_a_question_or_a_report_is_never_an_action(): void {
		$this->assertNull( ZDZ_Ask_Actions::match( 'how many estimates did I send' ) );
		$this->assertNull( ZDZ_Ask_Actions::match( 'did Jordan start the job' ) );
		$this->assertNull( ZDZ_Ask_Actions::match( 'estimates sent this month' ) );
		$this->assertNull( ZDZ_Ask_Actions::match( 'send the estimate to Jordan?' ), 'a question mark makes it a question' );
		$this->assertSame( 'survey_emails_sent', ZDZ_Ask_Actions::match( 'send the surveys from last week' )['id'], 'a verb of doing still acts' );
	}

	public function test_rows_open_the_app_with_the_context(): void {
		$a = ZDZ_Ask_Actions::match( 'send the estimate to Jordan' );
		$this->assertSame( 'estimate_sent', $a['id'] );
		$this->assertSame( 'estimate-creator', $a['app'] );
		$this->assertSame( 'Jordan', $a['options']['customer'] );
		$this->assertSame( 'ask', $a['options']['source'] );
		$n = ZDZ_Ask_Actions::match( 'resend estimate #5123' );
		$this->assertSame( 'estimate_resent', $n['id'] );
		$this->assertSame( '5123', $n['options']['estimate_number'] );
		$this->assertSame( 'document_shared', ZDZ_Ask_Actions::match( 'share the estimate in the team chat' )['id'], 'a share is a message, not a send' );
		$this->assertSame( 'job_started', ZDZ_Ask_Actions::match( 'on my way to the Example job' )['id'] );
		$this->assertSame( 'jobs', ZDZ_Ask_Actions::match( 'on my way to the Example job' )['app'] );
		$m = ZDZ_Ask_Actions::match( 'tell Alex the Example job moved' );
		$this->assertSame( 'message_sent', $m['id'] );
		$this->assertSame( 'Alex', $m['options']['to'], 'a message names its person first' );
	}

	public function test_only_an_app_the_person_can_open(): void {
		$this->assertNull( ZDZ_Ask_Actions::match( 'send the estimate to Jordan', array( 'jobs' ) ) );
		$this->assertSame( 'leads', ZDZ_Ask_Actions::match( 'assign leads to Alex', array( 'leads' ) )['app'] );
	}

	public function test_trade_words_join_the_rows(): void {
		$this->assertNull( ZDZ_Ask_Actions::match( 'order more awnings' ) );
		$GLOBALS['zdz_test_options']['zdz_ask_trade_words'] = array( 'awning' );
		$this->assertSame( 'order_started', ZDZ_Ask_Actions::match( 'order more awnings' )['id'] );
		$this->assertSame( '', ZDZ_Ask_Actions::name_in( 'for awnings' ), 'a trade word is never a name' );
	}

	public function test_every_row_runs_on_both_sides_and_a_bad_row_is_dropped(): void {
		$GLOBALS['zdz_test_filters']['zdz_ask_actions'] = function ( $rows ) {
			$rows[] = array( 'id' => 'bad_one', 'apps' => array( 'x' ), 'label' => 'Bad', 'phrases' => array( '(?<=a)b' ) );
			$rows[] = array( 'id' => 'half', 'apps' => array( 'x' ), 'label' => 'Half', 'verbs' => array( 'do' ) );
			return $rows;
		};
		$ids = array_column( ZDZ_Ask_Actions::rows(), 'id' );
		$this->assertNotContains( 'bad_one', $ids );
		$this->assertNotContains( 'half', $ids, 'verbs without objects cannot run' );
		$this->assertContains( 'estimate_sent', $ids );
	}

	/* ── ZDZ_For_You ───────────────────────────────────────────────────────────────────────────────── */

	public function test_hours_are_cleaned(): void {
		$this->assertSame( array( 'days' => array( 1, 3 ), 'start' => '07:05', 'end' => '16:00' ), ZDZ_For_You::sanitize_hours( array( 'days' => array( 3, 1, 9, 'x', 3 ), 'start' => '7:05', 'end' => '16:00' ) ) );
		$this->assertNull( ZDZ_For_You::sanitize_hours( array( 'days' => array( 1 ), 'start' => '08:00', 'end' => '08:00' ) ) );
		$this->assertNull( ZDZ_For_You::sanitize_hours( array( 'days' => array( 1 ), 'start' => '25:00', 'end' => '08:00' ) ) );
		$this->assertNull( ZDZ_For_You::sanitize_hours( 'nine to five' ) );
	}

	public function test_usual_hours_come_from_the_business_then_core(): void {
		$this->assertSame( ZDZ_For_You::CORE_HOURS, ZDZ_For_You::default_hours() );
		$GLOBALS['zdz_test_options']['zdz_work_hours_default'] = array( 'days' => array( 2, 3 ), 'start' => '06:00', 'end' => '14:00' );
		$this->assertSame( '06:00', ZDZ_For_You::default_hours()['start'] );
		$GLOBALS['zdz_test_options']['zdz_work_hours_default'] = 'garbage';
		$this->assertSame( ZDZ_For_You::CORE_HOURS, ZDZ_For_You::default_hours() );
		$GLOBALS['zdz_test_user_meta'][7]['zdz_work_hours'] = array( 'days' => array( 6 ), 'start' => '10:00', 'end' => '12:00' );
		$h = ZDZ_For_You::hours_for( 7 );
		$this->assertTrue( $h['set'] );
		$this->assertFalse( ZDZ_For_You::hours_for( 8 )['set'] );
	}

	public function test_work_and_off_across_midnight(): void {
		$day = array( 'days' => array( 1, 2, 3, 4, 5 ), 'start' => '09:00', 'end' => '17:00' );
		$this->assertSame( 'work', ZDZ_For_You::mode_at( $day, 1, 9 * 60 ) );
		$this->assertSame( 'off', ZDZ_For_You::mode_at( $day, 1, 17 * 60 ) );
		$this->assertSame( 'off', ZDZ_For_You::mode_at( $day, 6, 10 * 60 ) );
		$night = array( 'days' => array( 5 ), 'start' => '22:00', 'end' => '06:00' );
		$this->assertSame( 'work', ZDZ_For_You::mode_at( $night, 5, 23 * 60 ) );
		$this->assertSame( 'work', ZDZ_For_You::mode_at( $night, 6, 2 * 60 ), 'Friday night runs into Saturday' );
		$this->assertSame( 'off', ZDZ_For_You::mode_at( $night, 1, 2 * 60 ), 'Sunday night is not a work night' );
		$this->assertSame( 'work', ZDZ_For_You::mode_at( array( 'days' => array( 7 ), 'start' => '22:00', 'end' => '06:00' ), 1, 60 ), 'Sunday is the day before Monday' );
	}

	public function test_bands_cover_the_day_once(): void {
		$this->assertSame( 'night', ZDZ_For_You::band_of( 2 ) );
		$this->assertSame( 'early', ZDZ_For_You::band_of( 5 ) );
		$this->assertSame( 'evening', ZDZ_For_You::band_of( 20 ) );
		$GLOBALS['zdz_test_filters']['zdz_for_you_bands'] = function () { return array( array( 'day', 6, 18 ), array( 'late', 17, 6 ) ); };
		$this->assertSame( ZDZ_For_You::BANDS, ZDZ_For_You::bands(), 'overlapping bands fall back' );
		$GLOBALS['zdz_test_filters']['zdz_for_you_bands'] = function () { return array( array( 'day', 6, 18 ), array( 'late', 18, 6 ) ); };
		$this->assertSame( 'late', ZDZ_For_You::band_of( 23 ) );
		$this->assertSame( 'workday', ZDZ_For_You::day_type( ZDZ_For_You::CORE_HOURS, 3 ) );
		$this->assertSame( 'dayoff', ZDZ_For_You::day_type( ZDZ_For_You::CORE_HOURS, 7 ) );
	}

	public function test_opens_outweigh_the_role_list_only_with_use(): void {
		$prior = array( 'jobs' => 3.0, 'scheduler' => 2.5, 'inbox' => 2.0 );
		$this->assertSame( array( 'jobs', 'scheduler', 'inbox' ), ZDZ_For_You::rank( $prior, array() ) );
		$this->assertSame( array( 'jobs', 'scheduler', 'inbox' ), ZDZ_For_You::rank( $prior, array( 'inbox' => 0.2 ) ), 'a rare open barely moves' );
		$this->assertSame( 'inbox', ZDZ_For_You::rank( $prior, array( 'inbox' => 3 ) )[0] );
		$this->assertSame( array( 'jobs', 'scheduler', 'inbox', 'game' ), ZDZ_For_You::rank( $prior, array( 'game' => 0.1 ) ), 'an app outside the list joins at the end' );
		$cell = ZDZ_For_You::rank_cell( $prior, array( 'scheduler' => 1 ), array() );
		$this->assertSame( 'scheduler', $cell[0], 'the cell weighs most' );
	}

	public function test_weighing_skips_resets_and_the_future(): void {
		$tz    = new DateTimeZone( 'UTC' );
		$now   = gmmktime( 12, 0, 0, 3, 4, 2026 );   // a Wednesday, 12:00
		$hours = ZDZ_For_You::CORE_HOURS;
		$rows  = array(
			array( 'app_id' => 'jobs', 'ts' => $now - 3600 ),             // work, midday
			array( 'app_id' => 'game', 'ts' => $now - 15 * 3600 ),        // the night before: off
			array( 'app_id' => 'inbox', 'ts' => $now - 30 * 86400 ),      // before the reset
			array( 'app_id' => 'future', 'ts' => $now + 86400 ),
			array( 'app_id' => 'Bad Id!', 'ts' => $now - 60 ),
		);
		$w = ZDZ_For_You::weigh( $hours, $rows, $now, $now - 10 * 86400, $tz );
		$this->assertArrayHasKey( 'jobs', $w['work'] );
		$this->assertArrayHasKey( 'game', $w['off'] );
		$this->assertArrayNotHasKey( 'inbox', $w['work'] + $w['off'] );
		$this->assertArrayNotHasKey( 'future', $w['work'] + $w['off'] );
		$this->assertArrayHasKey( 'work|workday|midday', $w['cells'] );
		$this->assertLessThan( 0.01, abs( 1.0 - $w['work']['jobs'] ) );
	}

	public function test_cells_are_sent_only_when_they_differ(): void {
		$usage = array( 'work' => array(), 'off' => array(), 'cells' => array( 'work|workday|morning' => array( 'game' => 10 ), 'odd|x|y' => array( 'game' => 9 ) ) );
		$r     = ZDZ_For_You::ranking( 'zdz_sales', $usage );
		$this->assertArrayHasKey( 'work|workday|morning', $r['cells'] );
		$this->assertSame( 'game', $r['cells']['work|workday|morning'][0] );
		$this->assertArrayNotHasKey( 'odd|x|y', $r['cells'] );
		$this->assertSame( array(), ZDZ_For_You::ranking( 'zdz_sales', array( 'work' => array(), 'off' => array(), 'cells' => array() ) )['cells'] );
	}

	public function test_the_shared_device_gets_nothing(): void {
		$this->assertNull( ZDZ_For_You::for_js( 5, 'zdz_general' ) );
		$this->assertNull( ZDZ_For_You::for_js( 0, 'zdz_sales' ) );
		$this->assertTrue( ZDZ_For_You::is_shared( 0 ) );
		$this->assertFalse( ZDZ_For_You::is_shared( 5, 'zdz_sales' ) );
	}

	public function test_role_lists_use_platform_app_ids_and_a_business_may_replace_them(): void {
		foreach ( ZDZ_For_You::role_defaults() as $mode => $roles ) {
			foreach ( $roles as $role => $list ) {
				$this->assertTrue( '_default' === $role || 'administrator' === $role || 0 === strpos( $role, 'zdz_' ), $role );
				$this->assertLessThanOrEqual( 8, count( $list ) );
			}
		}
		$GLOBALS['zdz_test_filters']['zdz_for_you_defaults'] = function () { return array( 'work' => array( '_default' => array( 'game', 'BAD ID' ) ) ); };
		$this->assertSame( array( 'game' => 3.0 ), ZDZ_For_You::prior_for( 'zdz_tech', 'work' ) );
		$this->assertSame( array(), ZDZ_For_You::prior_for( 'zdz_tech', 'off' ) );
	}
}
