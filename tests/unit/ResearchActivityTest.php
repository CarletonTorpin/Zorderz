<?php
/**
 * A research run's activity log: every action is one event, a step that never reported back is shown as lost, the
 * totals stay true when the list drops old events, only the run's owner sees it, and the trail kept with the answer
 * has no excerpts of the business's data.
 *
 * Neutral data only.
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/research-test-doubles.php';

final class ResearchActivityTest extends TestCase {

	private $run = 0;

	protected function setUp(): void {
		Zdz_Research_Fixture::up();
		$this->run = ZANA_Research_Store::create( array( 'user_id' => Zdz_Research_Fixture::OWNER, 'phase' => 'researching' ) );
	}

	protected function tearDown(): void {
		Zdz_Research_Fixture::down();
	}

	private function row(): array {
		return (array) ZANA_Research_Store::get( $this->run );
	}

	public function test_an_event_is_kept_with_its_kind_words_and_time(): void {
		$A = 'ZANA_Research_Activity';
		$i = $A::add( $this->run, 'web', 'Searching the web', 'average franchise revenue', 'run' );
		$this->assertSame( 0, $i );
		$e = $this->row()['activity'][0];
		$this->assertSame( 'web', $e['k'] );
		$this->assertSame( 'run', $e['st'] );
		$this->assertSame( 'Searching the web', $e['l'] );
		$this->assertSame( 'average franchise revenue', $e['d'] );
		$this->assertSame( Zdz_Research_Fixture::$now, $e['at'] );
		$this->assertNull( $e['dur'] );
	}

	public function test_an_unknown_kind_or_status_is_kept_as_information(): void {
		$A = 'ZANA_Research_Activity';
		$A::add( $this->run, 'exploit', 'x', '', 'pwned' );
		$e = $this->row()['activity'][0];
		$this->assertSame( 'info', $e['k'] );
		$this->assertSame( 'info', $e['st'] );
	}

	public function test_closing_an_event_gives_its_outcome_and_how_long_it_took(): void {
		$A = 'ZANA_Research_Activity';
		$i = $A::add( $this->run, 'web', 'Searching the web', 'q', 'run' );
		Zdz_Research_Fixture::$now += 17;
		$A::done( $this->run, $i, 'ok', 'q', array( 'sources' => 3, 'sites' => array( 'tradeweekly.example', 'tradeweekly.example', '' ) ), 'Searched the web: 3 sources' );
		$e = $this->row()['activity'][0];
		$this->assertSame( 'ok', $e['st'] );
		$this->assertSame( 17, $e['dur'] );
		$this->assertSame( 'Searched the web: 3 sources', $e['l'] );
		$this->assertSame( 3, $e['m']['sources'] );
		$this->assertSame( array( 'tradeweekly.example' ), $e['m']['sites'] );
		$this->assertSame( 1, $this->row()['counts']['web'] );
		$A::done( $this->run, 99, 'ok' );   // no such event: nothing changes
		$A::done( $this->run, -1, 'ok' );
		$this->assertCount( 1, $this->row()['activity'] );
	}

	public function test_a_step_that_never_reported_back_is_shown_as_lost(): void {
		$A = 'ZANA_Research_Activity';
		$A::add( $this->run, 'data', 'Checking the business\'s own data', 'q', 'run' );
		Zdz_Research_Fixture::$now += 200;
		$A::add( $this->run, 'decide', 'Deciding the next step', '', 'run' );
		$r = $this->row();
		$this->assertSame( 'lost', $r['activity'][0]['st'] );
		$this->assertSame( 200, $r['activity'][0]['dur'] );
		$this->assertSame( 'run', $r['activity'][1]['st'] );
		$this->assertSame( 1, $r['counts']['lost'] );
		$this->assertArrayNotHasKey( 'data', $r['counts'], 'a lost step is not a data answer' );
	}

	public function test_only_finished_work_is_counted(): void {
		$A = 'ZANA_Research_Activity';
		$c = array();
		$c = $A::tally( $c, array( 'k' => 'web', 'st' => 'ok' ) );
		$c = $A::tally( $c, array( 'k' => 'web', 'st' => 'fail' ) );
		$c = $A::tally( $c, array( 'k' => 'web', 'st' => 'run' ) );
		$c = $A::tally( $c, array( 'k' => 'data', 'st' => 'ok' ) );
		$c = $A::tally( $c, array( 'k' => 'note', 'st' => 'ok' ) );
		$c = $A::tally( $c, array( 'k' => 'vet', 'st' => 'ok' ) );
		$c = $A::tally( $c, array( 'k' => 'vet', 'st' => 'ok', 'm' => array( 'verdict' => 'verified' ) ) );
		$c = $A::tally( $c, array( 'k' => 'pause', 'st' => 'wait' ) );
		$c = $A::tally( $c, array( 'k' => 'decide', 'st' => 'ok' ) );
		$this->assertSame( array( 'web' => 1, 'data' => 1, 'note' => 1, 'vet' => 1, 'pause' => 1 ), $c, 'listing the facts is not a check; a search that failed is not a search' );
	}

	public function test_the_list_is_capped_and_the_totals_stay_true(): void {
		$A      = 'ZANA_Research_Activity';
		$events = array();
		$counts = array();
		for ( $i = 0; $i < $A::MAX_EVENTS + 25; $i++ ) {
			list( $events, $counts ) = $A::with_event( $events, $counts, 0 === $i ? 'start' : 'note', 'Event ' . $i, '', 'ok', array(), 1000 + $i );
		}
		$this->assertCount( $A::MAX_EVENTS, $events );
		$this->assertSame( 'Event 0', $events[0]['l'], 'the start is kept' );
		$this->assertSame( 'Event ' . ( $A::MAX_EVENTS + 24 ), $events[ $A::MAX_EVENTS - 1 ]['l'], 'and the newest' );
		$this->assertSame( 25, $counts['dropped'] );
		$this->assertSame( $A::MAX_EVENTS + 24, $counts['note'], 'every note is counted, kept in the list or not' );
	}

	public function test_long_text_is_cut_and_kept_as_one_plain_line(): void {
		$A = 'ZANA_Research_Activity';
		$A::add( $this->run, 'note', str_repeat( 'L', 500 ), "two\n\nlines   of <b>text</b> under \$500", 'ok', array( 'excerpt' => str_repeat( 'x', 900 ), 'items' => array( str_repeat( 'i', 900 ), '', 'kept' ), 'verdict' => 'Verified!<script>', 'other' => 'dropped' ) );
		$e = $this->row()['activity'][0];
		$this->assertSame( $A::LABEL_MAX, mb_strlen( $e['l'] ) );
		$this->assertSame( 'two lines of <b>text</b> under $500', $e['d'], 'nothing is stripped: the page writes it as text' );
		$this->assertSame( $A::EXCERPT_MAX, mb_strlen( $e['m']['excerpt'] ) );
		$this->assertCount( 2, $e['m']['items'] );
		$this->assertSame( 220, mb_strlen( $e['m']['items'][0] ) );
		$this->assertSame( 'verifiedscript', $e['m']['verdict'] );
		$this->assertArrayNotHasKey( 'other', $e['m'] );
	}

	public function test_an_event_left_open_by_an_earlier_request_is_closed_as_lost(): void {
		$A = 'ZANA_Research_Activity';
		$A::add( $this->run, 'data', 'Checking', 'q', 'run' );
		$at = Zdz_Research_Fixture::$now;
		Zdz_Research_Fixture::$now += 120;
		$A::close_running( $this->run, 'fail', 'the Ai did not answer', $at + 60 );
		$r = $this->row();
		$this->assertSame( 'lost', $r['activity'][0]['st'], 'opened before this request began: its worker is gone' );
		$this->assertSame( 'q', $r['activity'][0]['d'] );
		$this->assertSame( 1, $r['counts']['lost'] );

		$A::add( $this->run, 'web', 'Searching', 'q2', 'run' );
		$A::close_running( $this->run, 'fail', 'no answer in time', Zdz_Research_Fixture::$now - 5 );
		$r = $this->row();
		$this->assertSame( 'fail', $r['activity'][1]['st'] );
		$this->assertSame( 'no answer in time', $r['activity'][1]['d'] );
		$A::close_running( $this->run, 'ok' );   // nothing open: nothing changes
		$this->assertSame( 1, $this->row()['counts']['lost'] );
	}

	public function test_a_long_step_says_where_it_is_and_only_the_newest_notes_are_kept(): void {
		$A = 'ZANA_Research_Activity';
		$A::mark_running( $this->run, 'nothing is open' );
		$A::add( $this->run, 'data', 'Checking', 'q', 'run' );
		for ( $i = 1; $i <= $A::MARKS_MAX + 3; $i++ ) {
			$A::mark_running( $this->run, 'note ' . $i );
		}
		$A::mark_running( $this->run, '   ' );
		$items = $this->row()['activity'][0]['m']['items'];
		$this->assertCount( $A::MARKS_MAX, $items );
		$this->assertSame( 'note 4', $items[0] );
		$this->assertSame( 'note ' . ( $A::MARKS_MAX + 3 ), $items[ $A::MARKS_MAX - 1 ] );
	}

	public function test_only_the_person_who_started_the_run_sees_what_it_is_doing(): void {
		$A = 'ZANA_Research_Activity';
		$F = 'Zdz_Research_Fixture';
		$i = $A::add( $this->run, 'data', 'Checking the business\'s own data', 'What were sales last year?', 'run' );
		$A::done( $this->run, $i, 'ok', null, array( 'excerpt' => 'Sales were $120,000.' ) );
		$mine = $A::view( $this->row(), $F::OWNER );
		$this->assertCount( 1, $mine['events'] );
		$this->assertSame( 'Sales were $120,000.', $mine['events'][0]['x'], 'the owner sees a short excerpt while the run is live' );
		foreach ( array( $F::ADMIN, $F::SALES, 0 ) as $other ) {
			$this->assertSame( array( 'events' => array(), 'now' => null ), $A::view( $this->row(), $other ), 'nobody else sees anything, an administrator included' );
		}
	}

	public function test_what_is_happening_now_is_said_in_words(): void {
		$A = 'ZANA_Research_Activity';
		$F = 'Zdz_Research_Fixture';
		$v = $A::view( $this->row(), $F::OWNER );
		$this->assertSame( 'Starting the next step', $v['now']['label'] );
		$F::$now += 30;
		$v = $A::view( $this->row(), $F::OWNER );
		$this->assertSame( 'Waiting for the server to start the next step', $v['now']['label'] );
		$this->assertFalse( $v['now']['quiet'] );
		$F::$now += 70;
		$this->assertTrue( $A::view( $this->row(), $F::OWNER )['now']['quiet'] );

		$A::add( $this->run, 'web', 'Searching the web', 'q', 'run' );
		$this->assertSame( 'Searching the web', $A::view( $this->row(), $F::OWNER )['now']['label'] );
		$F::$now += $A::SILENT_AFTER;
		$v = $A::view( $this->row(), $F::OWNER );
		$this->assertTrue( $v['now']['quiet'] );
		$this->assertStringContainsString( 'No word from this step for 2:30', $v['now']['label'] );
		$this->assertSame( $A::SILENT_AFTER, $v['events'][0]['dur'], 'an open event shows how long it has run' );
	}

	public function test_a_plan_waiting_for_start_says_so(): void {
		$id = ZANA_Research_Store::create( array( 'user_id' => Zdz_Research_Fixture::OWNER, 'phase' => 'awaiting' ) );
		$v  = ZANA_Research_Activity::view( (array) ZANA_Research_Store::get( $id ), Zdz_Research_Fixture::OWNER );
		$this->assertSame( 'Waiting for you to start it', $v['now']['label'] );
	}

	public function test_the_trail_kept_with_the_answer_has_no_excerpts(): void {
		$A = 'ZANA_Research_Activity';
		$i = $A::add( $this->run, 'data', 'Checking', 'What were sales last year?', 'run' );
		$A::done( $this->run, $i, 'ok', null, array( 'excerpt' => 'Sales were $120,000 for Riley Example.' ) );
		$i = $A::add( $this->run, 'web', 'Searching', 'q', 'run' );
		$A::done( $this->run, $i, 'ok', null, array( 'sources' => 2, 'sites' => array( 'tradeweekly.example' ) ) );
		Zdz_Research_Fixture::$now += 95;
		$t = $A::trail( $this->row() );
		$this->assertSame( 1, $t['v'] );
		$this->assertSame( 95, $t['total_s'] );
		$this->assertSame( 1, $t['counts']['data'] );
		$this->assertSame( 1, $t['counts']['web'] );
		$this->assertSame( 0, $t['counts']['vet'] );
		$this->assertCount( 2, $t['events'] );
		$this->assertArrayNotHasKey( 'x', $t['events'][0] );
		$this->assertStringNotContainsString( '120,000', json_encode( $t ), 'what the data answered is not kept in the trail' );
		$this->assertSame( 'What were sales last year?', $t['events'][0]['d'], 'the question the run asked is' );
		$this->assertSame( array( 'tradeweekly.example' ), $t['events'][1]['sites'] );
		$this->assertSame( array(), $A::trail( array( 'activity' => array() ) ) );
	}

	public function test_a_run_that_has_ended_takes_no_more_events(): void {
		$A = 'ZANA_Research_Activity';
		$A::add( $this->run, 'web', 'Searching', 'q', 'run' );
		ZANA_Research_Store::end( $this->run, 'stopped' );
		$this->assertSame( -1, $A::add( $this->run, 'note', 'A late worker writes', 'x' ) );
		$A::done( $this->run, 0, 'ok', 'late' );
		$A::mark_running( $this->run, 'late' );
		$A::close_running( $this->run, 'ok' );
		$r = $this->row();
		$this->assertCount( 1, $r['activity'] );
		$this->assertSame( 'run', $r['activity'][0]['st'] );
		$this->assertSame( -1, $A::add( 404, 'note', 'No such run', '' ) );
	}

	public function test_small_parts(): void {
		$A = 'ZANA_Research_Activity';
		$this->assertSame( '0:00', $A::mmss( 0 ) );
		$this->assertSame( '2:05', $A::mmss( 125 ) );
		$this->assertSame( '0:00', $A::mmss( -9 ) );
		$this->assertSame( array( 'tradeweekly.example', 'citygazette.example' ), $A::sites( array(
			'https://www.tradeweekly.example/a', array( 'url' => 'https://tradeweekly.example/b' ), array( 'url' => 'https://citygazette.example/' ), 'not a url', array( 'type' => 'internal' ),
		) ) );
		$this->assertSame( -1, $A::last_running( array() ) );
		$this->assertSame( 1, $A::last_running( array( array( 'st' => 'run' ), array( 'st' => 'run' ), array( 'st' => 'ok' ) ) ) );
	}
}
