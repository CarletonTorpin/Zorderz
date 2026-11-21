<?php
/**
 * Estimates follow-ups: the record (ZEST_Followup's rows).
 *
 *   - one estimate has one open ask: a new ask replaces the waiting one; the estimate's follow-up is always its newest
 *     row that still stands;
 *   - an answer goes onto the waiting row (a try keeps each try's own words and stays waiting; a reach finishes it and
 *     may move the estimate to Declined), or becomes a row of its own when nobody asked;
 *   - the token a screen sends back changes with every step, also after a take-back returns a row to an earlier state;
 *   - a take-back undoes the LAST thing only: an answer (the row waits again), a try, or the ask itself (the ask it
 *     had replaced waits again); what was taken back is kept on the row;
 *   - a row that moved since it was read is never written;
 *   - the notes for the CRM wait on the row: each answer adds its own, a run takes them all at once, the ones that did
 *     not go are put back in front, and one that has not gone can be dropped;
 *   - the lists read only the columns they need: never the typed words.
 *
 * Neutral data only.
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/support/estimate-followup-stubs.php';

final class EstimateFollowupRecordTest extends TestCase {

	const ALEX  = 61; // sales, AA
	const CASEY = 65; // owner, CC
	const SAM   = 63; // operator, SS

	/** @var ZDZ_Test_EstFu_DB */
	private $db;

	protected function setUp(): void {
		$this->db = zdz_test_estfu_people( array( self::ALEX => array( 'zdz_sales', 'AA' ), self::CASEY => array( 'zdz_owner', 'CC' ), self::SAM => array( 'zdz_operator', 'SS' ) ) );
	}

	protected function tearDown(): void {
		zdz_test_estfu_teardown();
	}

	private function ask( int $est, int $to = self::ALEX, int $by = self::CASEY, string $note = '' ): int {
		return ZEST_Followup::ask( array( 'estimate_id' => $est, 'billing_doc_id' => '501', 'billing_doc_num' => '1001', 'is_test' => false, 'sent_by' => $by, 'sent_to' => $to, 'ask_note' => $note ) );
	}

	private function answer( ?array $row, string $answer, string $outcome = '', string $channel = 'call', string $note = '', int $by = self::ALEX, int $est = 0 ): int {
		return ZEST_Followup::answer( $row, array( 'estimate_id' => $est, 'answer' => $answer, 'outcome' => $outcome, 'channel' => $channel, 'note' => $note, 'by' => $by ) );
	}

	public function test_the_test_table_has_every_column_the_schema_names(): void {
		preg_match_all( '/^\s+([a-z_]+)\s+(?:BIGINT|VARCHAR|TINYINT|DATETIME|TEXT|INT|LONGTEXT)/m', ZEST_Followup::schema( 'wp_', '' ), $m );
		$this->assertGreaterThan( 25, count( $m[1] ) );
		$this->assertSame( array(), array_values( array_diff( $m[1], $this->db->columns( 'wp_zest_followups' ) ) ) );
		$this->assertStringContainsString( 'CREATE TABLE wp_zest_followups', ZEST_Followup::schema( 'wp_', '' ) );
	}

	public function test_a_new_ask_replaces_the_one_still_waiting(): void {
		$first = $this->ask( 7 );
		$this->assertGreaterThan( 0, $first );
		$this->assertSame( 'open', ZEST_Followup::latest( 7 )['state'] );
		$this->assertTrue( ZEST_Followup::is_waiting( ZEST_Followup::waiting( 7 ) ) );
		$second = $this->ask( 7, self::ALEX, self::SAM );
		$this->assertSame( 'superseded', ZEST_Followup::get( $first )['state'] );
		$this->assertSame( $second, (int) ZEST_Followup::latest( 7 )['id'], 'the follow-up is the newest row that stands' );
		$this->assertSame( 0, ZEST_Followup::ask( array( 'estimate_id' => 7, 'sent_to' => 0 ) ), 'an ask to nobody is not recorded' );
		$this->assertSame( 0, ZEST_Followup::ask( array( 'estimate_id' => 0, 'sent_to' => self::ALEX ) ) );
		$this->assertNull( ZEST_Followup::latest( 8 ) );
		$this->assertSame( '0', ZEST_Followup::token( null ) );
	}

	public function test_a_try_stays_waiting_and_a_reach_finishes_it(): void {
		$id  = $this->ask( 7 );
		$t0  = ZEST_Followup::token( ZEST_Followup::get( $id ) );
		$this->assertSame( $id, $this->answer( ZEST_Followup::get( $id ), 'tried', '', 'voicemail', 'left a message' ) );
		$row = ZEST_Followup::get( $id );
		$this->assertSame( 'tried', $row['state'] );
		$this->assertSame( 1, (int) $row['tries'] );
		$this->assertTrue( ZEST_Followup::is_waiting( $row ) );
		$t1 = ZEST_Followup::token( $row );
		$this->assertNotSame( $t0, $t1 );
		$this->assertSame( $id, $this->answer( $row, 'tried', '', 'call', 'no pick-up' ) );
		$row = ZEST_Followup::get( $id );
		$this->assertSame( array( 'left a message', 'no pick-up' ), array_column( ZEST_Followup::tries_of( $row ), 'note' ), 'each try keeps its own words' );
		$this->assertSame( $id, $this->answer( $row, 'reached', 'other_provider', 'call', 'went with someone else' ) );
		$row = ZEST_Followup::get( $id );
		$this->assertSame( 'done', $row['state'] );
		$this->assertSame( 1, (int) $row['declined'] );
		$this->assertSame( 'went with someone else', $row['answer_note'] );
		$this->assertSame( 2, (int) $row['tries'], 'the tries stay counted' );
		$this->assertFalse( ZEST_Followup::is_waiting( $row ) );
		$this->assertSame( -1, $this->answer( $row, 'reached', 'deciding' ), 'a finished row is not answered again in place' );
	}

	public function test_an_answer_is_refused_when_it_is_not_one(): void {
		$id = $this->ask( 7 );
		$this->assertSame( 0, $this->answer( ZEST_Followup::get( $id ), 'reached', '' ), 'no outcome' );
		$this->assertSame( 0, $this->answer( ZEST_Followup::get( $id ), 'reached', 'deciding', 'voicemail' ), 'a voicemail is a try' );
		$this->assertSame( 0, $this->answer( ZEST_Followup::get( $id ), 'reached', 'deciding', 'call', '', 0 ), 'nobody answering' );
		$this->assertSame( 'open', ZEST_Followup::get( $id )['state'] );
	}

	public function test_a_row_that_moved_since_it_was_read_is_not_written(): void {
		$id    = $this->ask( 7 );
		$stale = ZEST_Followup::get( $id );
		$this->answer( $stale, 'tried' );
		$this->assertSame( -1, $this->answer( $stale, 'reached', 'deciding' ), 'the row was open when read; it is tried now' );
		$this->assertSame( 'tried', ZEST_Followup::get( $id )['state'] );
		$this->assertNull( ZEST_Followup::undo( $stale, self::CASEY ), 'a take-back names the state it read too' );
	}

	public function test_nobody_asked_the_answer_is_a_row_of_its_own(): void {
		$id  = $this->answer( null, 'reached', 'deciding', 'text', 'thinking about it', self::ALEX, 9 );
		$row = ZEST_Followup::get( $id );
		$this->assertSame( 'done', $row['state'] );
		$this->assertSame( 0, (int) $row['sent_by'] );
		$this->assertSame( self::ALEX, (int) $row['sent_to'] );
		$this->assertSame( 0, $this->answer( null, 'reached', 'deciding' ), 'no estimate: nothing recorded' );
	}

	public function test_taking_back_an_answer_makes_an_asked_row_wait_again(): void {
		$id = $this->ask( 7 );
		$this->answer( ZEST_Followup::get( $id ), 'reached', 'not_needed', 'call', 'wrong customer' );
		$done = ZEST_Followup::get( $id );
		ZEST_Followup::mark( $id, array( 'doc_line' => 'Follow-up 6/1/2026 (AA): Customer no longer needs the work.', 'doc_state' => 'written', 'doc_on' => 'x', 'doc_why' => 'A sentence, not a code' ) );
		$this->assertSame( '', ZEST_Followup::get( $id )['doc_why'], 'only a code is ever stored as the reason' );
		$this->assertSame( 'answer', ZEST_Followup::undo_kind( $done ) );
		$r = ZEST_Followup::undo( ZEST_Followup::get( $id ), self::ALEX );
		$this->assertSame( 'answer', $r['kind'] );
		$this->assertSame( 'open', $r['state'] );
		$row = ZEST_Followup::get( $id );
		$this->assertSame( 'open', $row['state'] );
		$this->assertSame( 0, (int) $row['declined'] );
		$this->assertSame( '', (string) $row['answer_note'] );
		$this->assertSame( '', $row['doc_line'] );
		$this->assertSame( '', $row['doc_state'] );
		$kept = ZEST_Followup::undone_of( $row );
		$this->assertCount( 1, $kept );
		$this->assertSame( 'wrong customer', $kept[0]['note'], 'what was taken back is kept on the row' );
		$this->assertSame( 'not_needed', $kept[0]['outcome'] );
		$this->assertSame( self::ALEX, $kept[0]['by'] );
		$this->assertSame( ':1', substr( ZEST_Followup::token( $row ), -strlen( ':1' ) ), 'the count of take-backs rides on the token' );
		$this->assertNotSame( ZEST_Followup::token( array( 'id' => $id, 'state' => 'open', 'tries' => 0 ) ), ZEST_Followup::token( $row ), 'open again is not the open a screen saw before' );
	}

	public function test_taking_back_an_answer_nobody_asked_for_withdraws_it(): void {
		$id = $this->answer( null, 'handled', 'going_ahead', 'email', '', self::ALEX, 9 );
		$r  = ZEST_Followup::undo( ZEST_Followup::get( $id ), self::ALEX );
		$this->assertSame( 'withdrawn', $r['state'] );
		$this->assertNull( ZEST_Followup::latest( 9 ), 'a withdrawn row is no longer the estimate\'s follow-up' );
		$this->assertSame( array( 9 ), ZEST_Followup::have_rows( array( 9, 10 ) ), 'but there is still a history to show' );
		$this->assertSame( '', ZEST_Followup::undo_kind( ZEST_Followup::get( $id ) ), 'nothing more to take back' );
		$this->assertNull( ZEST_Followup::undo( ZEST_Followup::get( $id ), self::ALEX ) );
	}

	public function test_taking_back_a_try_leaves_the_earlier_tries(): void {
		$id = $this->ask( 7 );
		$this->answer( ZEST_Followup::get( $id ), 'tried', '', 'call', 'first' );
		$this->answer( ZEST_Followup::get( $id ), 'tried', '', 'text', 'second' );
		$r = ZEST_Followup::undo( ZEST_Followup::get( $id ), self::ALEX );
		$this->assertSame( 'try', $r['kind'] );
		$this->assertSame( 'second', $r['entry']['note'] );
		$row = ZEST_Followup::get( $id );
		$this->assertSame( 'tried', $row['state'] );
		$this->assertSame( 1, (int) $row['tries'] );
		$this->assertSame( 'call', $row['channel'], 'the try before is its answer again' );
		$r = ZEST_Followup::undo( $row, self::ALEX );
		$this->assertSame( 'open', $r['state'], 'no try left: the ask waits as it did' );
		$this->assertSame( 2, (int) ZEST_Followup::get( $id )['undone'] );
	}

	public function test_taking_back_an_ask_brings_back_the_one_it_replaced(): void {
		$first = $this->ask( 7, self::ALEX, self::CASEY );
		$this->answer( ZEST_Followup::get( $first ), 'tried' );
		$second = $this->ask( 7, self::ALEX, self::SAM );
		$r      = ZEST_Followup::undo( ZEST_Followup::get( $second ), self::SAM );
		$this->assertSame( 'ask', $r['kind'] );
		$this->assertSame( 'withdrawn', ZEST_Followup::get( $second )['state'] );
		$this->assertSame( $first, (int) $r['back']['id'] );
		$this->assertSame( 'tried', ZEST_Followup::get( $first )['state'], 'it waits again, with its try' );
		$this->assertSame( $first, (int) ZEST_Followup::latest( 7 )['id'] );
		$this->assertNull( ZEST_Followup::undo( ZEST_Followup::get( $first ), 0 ), 'nobody takes nothing back' );
	}

	public function test_a_declined_estimate_reopens_once(): void {
		$id = $this->ask( 7 );
		$this->answer( ZEST_Followup::get( $id ), 'reached', 'other_provider' );
		$before = ZEST_Followup::token( ZEST_Followup::get( $id ) );
		$this->assertTrue( ZEST_Followup::reopen( ZEST_Followup::get( $id ), self::ALEX ) );
		$row = ZEST_Followup::get( $id );
		$this->assertSame( 0, (int) $row['declined'] );
		$this->assertSame( self::ALEX, (int) $row['reopened_by'] );
		$this->assertNotSame( $before, ZEST_Followup::token( $row ) );
		$this->assertFalse( ZEST_Followup::reopen( $row, self::ALEX ), 'not declined any more' );
	}

	public function test_the_crm_notes_wait_on_the_row_and_are_taken_once(): void {
		$id = $this->ask( 7 );
		$this->assertSame( array(), ZEST_Followup::take_notes( $id ) );
		$this->assertTrue( ZEST_Followup::queue_note( $id, "HEAD one\nfirst" ) );
		$this->assertTrue( ZEST_Followup::queue_note( $id, "HEAD two\nsecond" ) );
		$this->assertFalse( ZEST_Followup::queue_note( $id, '  ' ) );
		$this->assertSame( 'queued', ZEST_Followup::get( $id )['crm_state'] );
		$this->assertSame( array( "HEAD one\nfirst", "HEAD two\nsecond" ), ZEST_Followup::take_notes( $id ) );
		$this->assertSame( 'posting', ZEST_Followup::get( $id )['crm_state'] );
		$this->assertSame( array(), ZEST_Followup::take_notes( $id ), 'a second run finds nothing to post' );

		ZEST_Followup::queue_note( $id, "HEAD three\nthird" );   // an answer recorded while the run was posting
		ZEST_Followup::put_back( $id, array( "HEAD two\nsecond" ), 'queued', 1 );
		$row = ZEST_Followup::get( $id );
		$this->assertSame( array( "HEAD two\nsecond", "HEAD three\nthird" ), ZEST_Followup::queue_of( $row ), 'what did not go is in front' );
		$this->assertSame( 1, (int) $row['crm_tries'] );

		$this->assertTrue( ZEST_Followup::drop_note( $id, 'HEAD two' ) );
		$this->assertFalse( ZEST_Followup::drop_note( $id, 'HEAD two' ), 'it is gone' );
		$this->assertFalse( ZEST_Followup::drop_note( $id, '' ) );
		$this->assertSame( array( "HEAD three\nthird" ), ZEST_Followup::queue_of( ZEST_Followup::get( $id ) ) );

		ZEST_Followup::take_notes( $id );
		ZEST_Followup::settle_notes( $id, 'posted' );
		$this->assertSame( 'posted', ZEST_Followup::get( $id )['crm_state'] );
		ZEST_Followup::queue_note( $id, 'later' );
		ZEST_Followup::settle_notes( $id, 'posted' );
		$this->assertSame( 'queued', ZEST_Followup::get( $id )['crm_state'], 'a note queued meanwhile is not called posted' );
	}

	public function test_the_lists_read_the_newest_standing_row_and_never_the_typed_words(): void {
		$a = $this->ask( 7, self::ALEX, self::CASEY, 'please call today' );
		$this->answer( ZEST_Followup::get( $a ), 'reached', 'deciding', 'call', 'private words' );
		$b = $this->ask( 8, self::SAM );
		$c = $this->ask( 8, self::ALEX );           // replaces b
		$d = $this->ask( 9, self::ALEX );
		ZEST_Followup::undo( ZEST_Followup::get( $d ), self::CASEY );   // taken back

		$map = ZEST_Followup::latest_map( array( 7, 8, 9, 10, 'x', -1 ) );
		$this->assertSame( array( 7, 8 ), array_keys( $map ) );
		$this->assertSame( $c, (int) $map[8]['id'] );
		foreach ( $map as $r ) {
			foreach ( array( 'ask_note', 'answer_note', 'try_notes', 'crm_queue', 'undo_log' ) as $typed ) {
				$this->assertFalse( array_key_exists( $typed, $r ), $typed );
			}
		}
		$any = ZEST_Followup::have_rows( array( 7, 8, 9, 10 ) );
		sort( $any );
		$this->assertSame( array( 7, 8, 9 ), $any );
		$mine = ZEST_Followup::sent_to( self::ALEX );
		sort( $mine );
		$this->assertSame( array( 7, 8 ), $mine, 'answered ones stay; a withdrawn one does not count' );
		$this->assertSame( array(), ZEST_Followup::sent_to( self::SAM ), 'an ask a newer one replaced does not count' );
		$with = ZEST_Followup::with_followup();
		sort( $with );
		$this->assertSame( array( 7, 8 ), $with );
		$this->assertCount( 2, ZEST_Followup::history( 8 ) );
		$this->assertSame( $c, (int) ZEST_Followup::history( 8 )[0]['id'], 'newest first' );
		$this->assertSame( array(), ZEST_Followup::history( 0 ) );
	}

	public function test_a_missing_table_reads_as_nothing(): void {
		$this->db->missing = array( 'wp_zest_followups' );
		$this->assertFalse( ZEST_Followup::table_exists() );
		$this->assertSame( array(), ZEST_Followup::quiet( static function () {
			return ZEST_Followup::latest_map( array( 7 ) );
		}, array() ) );
		$this->assertSame( 'none', ZEST_Followup::quiet( static function () {
			throw new RuntimeException( 'broken' );
		}, 'none' ) );
		$this->assertNull( ZEST_Followup::quiet( static function () {
			return ZEST_Followup::waiting( 7 );
		}, null ) );
	}
}
