<?php
/**
 * Estimates follow-ups: who may, and what each system is told.
 *
 *   - re-send: the admin tier, or an operator for an estimate they may open; only to a person the estimate names;
 *     never the shared device; a business narrows or widens it on a filter, but never for the shared device;
 *   - answer: whoever may re-send, the person asked (until an administrator removes their access), or the estimate's
 *     owner; every write carries the token of what the screen showed and is refused when that changed;
 *   - a notice names the estimate by number and never the customer;
 *   - the billing system gets ONE fixed line, only for a reach, only where the switch wants it, only onto the open
 *     estimate of the same customer, history first, read back; any doubt writes nothing and says why;
 *   - a take-back takes the line off the billing estimate FIRST (putting back the line it had replaced); when that
 *     cannot be done, nothing is taken back; a CRM note that has not gone is dropped, one that has is corrected;
 *   - the CRM note goes only to a lead that is shown to be this estimate's, and is tried again when it did not go;
 *   - what a person typed is shown in the history, but a note taken back only to those it concerns.
 *
 * Neutral data only.
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/support/estimate-followup-stubs.php';

final class EstimateFollowupFlowTest extends TestCase {

	const ALEX   = 61; // sales, AA: the estimate's salesperson
	const JORDAN = 62; // sales, JJ: someone else
	const SAM    = 63; // operator, SS: typed the estimate in
	const RILEY  = 64; // the shared device
	const CASEY  = 65; // owner, CC

	/** @var ZDZ_Test_EstFu_DB */
	private $db;
	/** @var ZDZ_Test_EstFu_Billing */
	private $billing;
	/** @var array[] every notice sent: [type, to, title, message] */
	private $notices = array();
	/** @var int */
	private $est;

	protected function setUp(): void {
		$this->db = zdz_test_estfu_people( array(
			self::ALEX => array( 'zdz_sales', 'AA' ), self::JORDAN => array( 'zdz_sales', 'JJ' ), self::SAM => array( 'zdz_operator', 'SS' ),
			self::RILEY => array( 'zdz_general', 'RR' ), self::CASEY => array( 'zdz_owner', 'CC' ),
		) );
		$this->billing               = new ZDZ_Test_EstFu_Billing();
		$this->billing->docs['501']  = ZDZ_Test_EstFu_Billing::doc();
		ZEST_Billing_Doc::$transport = $this->billing;
		ZEST_Billing_Doc::$account   = 'acct1';
		$this->notices               = array();
		ZEST_Followup_Ajax::$notifier = function ( $type, $to, $title, $msg, $opts ) {
			$this->notices[] = array( $type, $to, $title, $msg );
			return array( 'in_app' );
		};
		$this->est = $this->db->estimate( array( 'created_by' => self::SAM ) );
	}

	protected function tearDown(): void {
		zdz_test_estfu_teardown();
	}

	private function row( ?int $id = null ): array {
		return (array) ZEST_Access::row( null === $id ? $this->est : $id );
	}

	private function token(): string {
		return ZEST_Followup::token( ZEST_Followup::latest( $this->est ) );
	}

	private function mode( string $m ): void {
		$GLOBALS['zdz_test_options']['zest_followup_line_mode'] = $m;
	}

	/** Casey asks Alex; returns the follow-up's id. */
	private function asked(): int {
		$r = ZEST_Followup_Ajax::send( $this->row(), self::CASEY, array( 'to' => self::ALEX, 'note' => '', 'expect' => $this->token() ) );
		$this->assertTrue( $r['ok'], (string) ( $r['message'] ?? '' ) );
		return (int) $r['followup']['id'];
	}

	private function reach( string $outcome = 'deciding', string $note = 'they will think it over', int $by = self::ALEX ): array {
		return ZEST_Followup_Ajax::answer( $this->row(), $by, array( 'answer' => 'reached', 'outcome' => $outcome, 'channel' => 'call', 'note' => $note, 'expect' => $this->token() ) );
	}

	/* ── who may ─────────────────────────────────────────────────────────── */

	public function test_who_may_resend(): void {
		$this->assertTrue( ZEST_Followup_Ajax::can_send( self::CASEY ) );
		$this->assertTrue( ZEST_Followup_Ajax::can_send( self::SAM ), 'an operator' );
		$this->assertFalse( ZEST_Followup_Ajax::can_send( self::ALEX ), 'a salesperson does not re-send' );
		$this->assertFalse( ZEST_Followup_Ajax::can_send( self::RILEY ) );
		$this->assertFalse( ZEST_Followup_Ajax::can_send( 0 ) );

		$row = $this->row();
		$this->assertTrue( ZEST_Followup_Ajax::may_send( self::CASEY, $row ) );
		$this->assertTrue( ZEST_Followup_Ajax::may_send( self::SAM, $row ), 'the operator who typed it in may open it' );
		$other = $this->row( $this->db->estimate( array( 'created_by' => self::JORDAN, 'salesperson' => 'JJ', 'billing_doc_num' => '1002' ) ) );
		$this->assertFalse( ZEST_Followup_Ajax::may_send( self::SAM, $other ), 'an operator only re-sends estimates they may open' );
		$this->assertTrue( ZEST_Followup_Ajax::may_send( self::CASEY, $other ) );
	}

	public function test_a_business_changes_who_may_resend_but_never_for_the_shared_device(): void {
		$GLOBALS['zdz_test_filters']['zest_followup_can_send'] = static function ( $can, $uid ) {
			return self::ALEX === $uid || self::RILEY === $uid;   // only Alex, and (asked for in vain) the shared device
		};
		$this->assertTrue( ZEST_Followup_Ajax::can_send( self::ALEX ) );
		$this->assertFalse( ZEST_Followup_Ajax::can_send( self::CASEY ), 'narrowed' );
		$this->assertFalse( ZEST_Followup_Ajax::can_send( self::RILEY ), 'the shared device never, whatever the filter says' );
		$GLOBALS['zdz_test_filters']['zest_followup_can_send'] = static function () {
			return 'yes';
		};
		$this->assertFalse( ZEST_Followup_Ajax::can_send( self::CASEY ), 'only an exact true counts' );
	}

	public function test_who_may_answer_and_take_back(): void {
		$id  = $this->asked();
		$row = $this->row();
		$fu  = ZEST_Followup::get( $id );
		$this->assertTrue( ZEST_Followup_Ajax::may_answer( self::ALEX, $row, $fu ), 'the person asked' );
		$this->assertTrue( ZEST_Followup_Ajax::may_answer( self::CASEY, $row, $fu ) );
		$this->assertFalse( ZEST_Followup_Ajax::may_answer( self::JORDAN, $row, $fu ) );
		$this->assertFalse( ZEST_Followup_Ajax::may_answer( self::RILEY, $row, $fu ) );

		$this->assertTrue( ZEST_Followup_Ajax::may_undo( self::CASEY, $row, $fu ), 'whoever sent the ask' );
		$this->assertFalse( ZEST_Followup_Ajax::may_undo( self::ALEX, $row, $fu ), 'never the person asked: they answer it' );
		$this->assertFalse( ZEST_Followup_Ajax::may_undo( self::JORDAN, $row, $fu ) );

		// an administrator removes Alex's access to this estimate: the ask is no way round it
		$GLOBALS['zdz_test_user_meta'][ self::ALEX ][ ZEST_Access_Requests::REVOKED ] = array( 'row:' . $this->est => 1 );
		$this->assertFalse( ZEST_Followup_Ajax::may_answer( self::ALEX, $row, $fu ) );
		$this->assertFalse( ZEST_Followup_Ajax::may_undo( self::ALEX, $row, array( 'state' => 'done', 'answered_by' => self::ALEX, 'sent_to' => self::ALEX, 'sent_by' => 0 ) ) );
	}

	/* ── re-send ─────────────────────────────────────────────────────────── */

	public function test_a_resend_is_refused_when_it_should_be(): void {
		$row = $this->row();
		$r   = ZEST_Followup_Ajax::send( $row, self::ALEX, array( 'to' => self::ALEX, 'expect' => '0' ) );
		$this->assertFalse( $r['ok'] );
		$this->assertSame( 403, $r['status'] );
		$r = ZEST_Followup_Ajax::send( $row, self::CASEY, array( 'to' => self::JORDAN, 'expect' => '0' ) );
		$this->assertFalse( $r['ok'], 'only to a person the estimate names' );
		$r = ZEST_Followup_Ajax::send( $row, self::CASEY, array( 'to' => self::ALEX, 'expect' => '5:open:0:0:0' ) );
		$this->assertSame( 'changed', $r['code'], 'the screen showed something that is not there' );
		$accepted = $this->row( $this->db->estimate( array( 'status' => 'accepted', 'accepted_at' => '2026-01-06 09:00:00', 'billing_doc_num' => '1003' ) ) );
		$this->assertFalse( ZEST_Followup_Ajax::send( $accepted, self::CASEY, array( 'to' => self::ALEX, 'expect' => '0' ) )['ok'], 'an accepted estimate is not followed up' );
		$nobody = $this->row( $this->db->estimate( array( 'salesperson' => 'ZZ', 'billing_doc_num' => '1004' ) ) );
		$r      = ZEST_Followup_Ajax::send( $nobody, self::CASEY, array( 'to' => self::ALEX, 'expect' => '0' ) );
		$this->assertFalse( $r['ok'] );
		$this->assertStringContainsString( 'nobody to send it to', $r['message'] );
		$this->assertSame( array(), $this->notices, 'nobody was told anything' );
		$this->assertNull( ZEST_Followup::latest( $this->est ) );
	}

	public function test_a_resend_records_the_ask_and_tells_the_salesperson_without_naming_the_customer(): void {
		$r = ZEST_Followup_Ajax::send( $this->row(), self::CASEY, array( 'to' => self::ALEX, 'note' => 'they asked about colors', 'expect' => '0' ) );
		$this->assertTrue( $r['ok'] );
		$this->assertSame( array( 'in_app' ), $r['told'] );
		$this->assertSame( 'AA', $r['to']['initials'] );
		$this->assertSame( 'open', $r['followup']['state'] );
		$this->assertSame( 'CC asked AA to follow up', $r['followup']['words'] );
		$this->assertCount( 1, $this->notices );
		list( $type, $to, $title, $msg ) = $this->notices[0];
		$this->assertSame( ZEST_Followup_Ajax::ALERT_ASKED, $type );
		$this->assertSame( self::ALEX, $to );
		$this->assertStringContainsString( '#1001', $msg );
		$this->assertStringContainsString( 'they asked about colors', $msg );
		foreach ( array( $title, $msg ) as $text ) {
			$this->assertStringNotContainsString( 'Morgan', $text, 'a notice never names the customer' );
		}
		$this->assertSame( array(), $this->billing->calls, 'nothing is written to (or read from) the billing system for an ask' );

		// the same ask pressed again within the minute is the same ask
		$again = ZEST_Followup_Ajax::send( $this->row(), self::CASEY, array( 'to' => self::ALEX, 'expect' => '0' ) );
		$this->assertTrue( $again['ok'] );
		$this->assertTrue( $again['again'] );
		$this->assertCount( 1, $this->notices );
		$this->assertCount( 1, ZEST_Followup::history( $this->est ) );
	}

	public function test_a_test_customers_notice_says_so(): void {
		$est = $this->db->estimate( array( 'customer_name' => 'Test Customer One', 'billing_doc_num' => '1005' ) );
		ZEST_Followup_Ajax::send( $this->row( $est ), self::CASEY, array( 'to' => self::ALEX, 'expect' => '0' ) );
		$this->assertStringContainsString( 'TEST:', $this->notices[0][2] );
		$this->assertSame( 1, (int) ZEST_Followup::latest( $est )['is_test'] );
	}

	/* ── the answer ──────────────────────────────────────────────────────── */

	public function test_an_answer_is_refused_when_it_should_be(): void {
		$this->asked();
		$row = $this->row();
		$in  = array( 'answer' => 'reached', 'outcome' => 'deciding', 'channel' => 'call', 'note' => '', 'expect' => $this->token() );
		$this->assertSame( 403, ZEST_Followup_Ajax::answer( $row, self::JORDAN, $in )['status'], 'not theirs' );
		$this->assertFalse( ZEST_Followup_Ajax::answer( $row, self::ALEX, array( 'answer' => 'sold' ) + $in )['ok'] );
		$this->assertFalse( ZEST_Followup_Ajax::answer( $row, self::ALEX, array( 'outcome' => '' ) + $in )['ok'] );
		$this->assertFalse( ZEST_Followup_Ajax::answer( $row, self::ALEX, array( 'channel' => 'voicemail' ) + $in )['ok'] );
		$this->assertFalse( ZEST_Followup_Ajax::answer( $row, self::ALEX, array( 'outcome' => 'other' ) + $in )['ok'], '"Something else" needs the note' );
		$this->assertSame( 'changed', ZEST_Followup_Ajax::answer( $row, self::ALEX, array( 'expect' => '0' ) + $in )['code'], 'a screen that did not show the ask' );
		$this->assertSame( 'open', ZEST_Followup::latest( $this->est )['state'] );
		$this->assertSame( array(), $this->billing->calls );
	}

	public function test_a_try_writes_nothing_to_the_billing_system_and_tells_the_asker(): void {
		$id = $this->asked();
		$this->mode( 'on' );
		$r = ZEST_Followup_Ajax::answer( $this->row(), self::ALEX, array( 'answer' => 'tried', 'channel' => 'voicemail', 'note' => 'left a message', 'expect' => $this->token() ) );
		$this->assertTrue( $r['ok'] );
		$this->assertSame( $id, $r['id'] );
		$this->assertSame( '', $r['doc']['state'] );
		$this->assertSame( array(), $this->billing->calls );
		$this->assertSame( 'AA tried, no answer yet', $r['followup']['words'] );
		$this->assertSame( 'CC', $r['asker']['who'] );
		$this->assertSame( array( 'in_app' ), $r['asker']['told'] );
		$last = end( $this->notices );
		$this->assertSame( self::CASEY, $last[1] );
		$this->assertStringContainsString( 'left a message', $last[3] );
		$this->assertStringNotContainsString( 'Morgan', $last[2] . $last[3] );
		$queued = ZEST_Followup::queue_of( ZEST_Followup::get( $id ) );
		$this->assertCount( 1, $queued, 'the true wording waits for the CRM lead' );
		$this->assertStringContainsString( 'left a voicemail', $queued[0] );
		$this->assertStringContainsString( 'left a message', $queued[0] );
	}

	public function test_the_switch_decides_whether_a_reach_writes_its_line(): void {
		$this->asked();
		$r = $this->reach();
		$this->assertSame( 'skipped', $r['doc']['state'], 'test customers only by default, and this is not one' );
		$this->assertSame( 'mode_test', $r['doc']['why_code'] );
		$this->assertSame( array(), $this->billing->calls, 'the billing system was not even read' );
		$this->assertSame( 'skipped', $r['followup']['doc_state'] );
		$this->assertSame( 'mode_test', $r['followup']['doc_why_code'] );

		$this->mode( 'off' );
		$est = $this->db->estimate( array( 'customer_name' => 'Test Customer One', 'billing_doc_num' => '1006', 'billing_doc_id' => '502' ) );
		$r   = ZEST_Followup_Ajax::answer( $this->row( $est ), self::CASEY, array( 'answer' => 'reached', 'outcome' => 'deciding', 'channel' => 'call', 'expect' => '0' ) );
		$this->assertSame( 'mode_off', $r['doc']['why_code'], 'off is off for test customers too' );
	}

	public function test_a_reach_writes_one_fixed_line_and_keeps_the_typed_words_out_of_it(): void {
		$this->mode( 'on' );
		$this->billing->docs['501']['notes'] = 'Gate is on the left.';
		$id = $this->asked();
		$r  = $this->reach( 'other_provider', 'went with someone cheaper, 555-0100' );
		$this->assertTrue( $r['ok'] );
		$this->assertSame( 'written', $r['doc']['state'] );
		$line = ZEST_Followup::get( $id )['doc_line'];
		$this->assertMatchesRegularExpression( '#\AFollow-up \d{1,2}/\d{1,2}/\d{4} \(AA\): Customer chose another provider\.\z#', $line );
		$this->assertSame( "Gate is on the left.\n" . $line, $this->billing->docs['501']['notes'] );
		$this->assertStringNotContainsString( 'cheaper', $this->billing->docs['501']['notes'], 'never what was typed' );
		$sent = $this->billing->docs['501']['lines'];
		$this->assertCount( 1, $sent, 'the lines ride along unchanged' );
		$this->assertSame( array( 'Widget', '', '2', '100.00' ), array( $sent[0]['name'], $sent[0]['description'], (string) $sent[0]['qty'], (string) $sent[0]['unit_cost']['amount'] ) );
		$this->assertTrue( $r['followup']['declined'] );
		$this->assertSame( $line, ZEST_Followup::get( $id )['doc_on'] );
		$log = $this->db->get_row( "SELECT * FROM wp_zest_line_changes WHERE billing_id = '501'" );
		$this->assertSame( 'saved', $log['state'], 'the change history was written first and marked when the billing system answered' );
		$this->assertSame( 1, (int) $log['verified'] );
		$this->assertSame( $line, $log['after_text'] );
		$this->assertSame( $line, substr( $this->row()['notes'], -strlen( $line ) ), 'this app\'s own copy of the notes got the same line' );
		$this->assertStringContainsString( 'went with someone cheaper', ZEST_Followup::queue_of( ZEST_Followup::get( $id ) )[0], 'the typed words go to the CRM lead' );

		$this->assertSame( 'This estimate is among the declined ones. Reopen it first.', $this->reach()['message'] );
	}

	public function test_a_later_line_replaces_the_earlier_one(): void {
		$this->mode( 'on' );
		$this->reach( 'deciding', '', self::ALEX );
		$first = $this->billing->docs['501']['notes'];
		$r     = ZEST_Followup_Ajax::answer( $this->row(), self::ALEX, array( 'answer' => 'reached', 'outcome' => 'going_ahead', 'channel' => 'text', 'expect' => $this->token() ) );
		$this->assertSame( 'written', $r['doc']['state'] );
		$notes = $this->billing->docs['501']['notes'];
		$this->assertStringNotContainsString( 'still deciding', $notes, 'the customer reads the latest word, not a list' );
		$this->assertStringContainsString( 'plans to go ahead', $notes );
		$this->assertSame( $first, ZEST_Followup::latest( $this->est )['doc_prev'], 'the line it replaced is remembered for a take-back' );
	}

	public function test_any_doubt_about_the_estimate_writes_nothing_and_says_why(): void {
		$this->mode( 'on' );
		$line = ZEST_Followup::line( 'deciding', 'AA', '6/1/2026' );
		$try  = function ( array $doc_over = array(), array $row_over = array() ) use ( $line ) {
			$est = $this->db->estimate( $row_over + array( 'billing_doc_num' => '1001' ) );
			$id  = ZEST_Followup::answer( null, array( 'estimate_id' => $est, 'answer' => 'reached', 'outcome' => 'deciding', 'channel' => 'call', 'by' => self::ALEX ) );
			$this->billing->docs['501'] = ZDZ_Test_EstFu_Billing::doc( $doc_over );
			return ZEST_Followup_Ajax::write_line( (array) ZEST_Followup::get( $id ), $this->row( $est ), $line, self::ALEX );
		};
		$r = $try( array(), array( 'billing_doc_id' => '' ) );
		$this->assertSame( array( 'skipped', 'local' ), array( $r['state'], $r['why_code'] ), 'an estimate that lives in this app only' );
		$this->assertSame( 'customer', $try( array( 'customerid' => 78 ) )['why_code'], 'another customer\'s estimate answered' );
		$this->assertSame( 'archived', $try( array( 'vis_state' => 1 ) )['why_code'] );
		$this->assertSame( 'no_lines', $try( array( 'lines' => array() ) )['why_code'] );
		$GLOBALS['zdz_test_filters']['zdz_billing_estimate_status'] = static function () {
			return array( 4 => array( 'label' => 'Accepted', 'signal' => 'customer_accepted' ) );
		};
		$this->assertSame( 'accepted', $try( array( 'status' => 4 ) )['why_code'] );
		unset( $GLOBALS['zdz_test_filters']['zdz_billing_estimate_status'] );

		$r = $try( array( 'id' => 999 ) );
		$this->assertSame( array( 'failed', 'unread' ), array( $r['state'], $r['why_code'] ), 'a different document answered: never written' );
		$this->db->busy = array( 'zest_bv_estimate_501' );
		$this->assertSame( 'busy', $try()['why_code'], 'someone is saving this estimate right now' );
		$this->db->busy = array();
		$this->billing->refuse_put = true;
		$r = $try();
		$this->assertSame( array( 'failed', 'refused' ), array( $r['state'], $r['why_code'] ) );
		$this->assertSame( 'failed', $this->db->get_var( 'SELECT state FROM wp_zest_line_changes ORDER BY id DESC LIMIT 1' ) );
		$this->billing->refuse_put = false;
		$this->billing->down       = true;
		$this->assertSame( 'unread', $try()['why_code'] );
		$this->billing->down = false;
		$this->assertSame( 'not_connected', $try( array(), array( 'billing_doc_id' => 'abc-1' ) )['why_code'], 'a billing id this connection cannot address' );
		foreach ( $this->billing->docs as $d ) {
			$this->assertSame( '', (string) $d['notes'], 'nothing reached the billing notes in any of these' );
		}
	}

	public function test_only_the_apps_own_line_is_ever_written(): void {
		$this->mode( 'on' );
		$id = ZEST_Followup::answer( null, array( 'estimate_id' => $this->est, 'answer' => 'reached', 'outcome' => 'deciding', 'channel' => 'call', 'by' => self::ALEX ) );
		foreach ( array( '', 'Customer said call 555-0100', 'Follow-up 6/1/2026 (AA): something typed' ) as $typed ) {
			$r = ZEST_Followup_Ajax::write_line( (array) ZEST_Followup::get( $id ), $this->row(), $typed, self::ALEX );
			$this->assertSame( array( 'failed', 'no_line' ), array( $r['state'], $r['why_code'] ) );
		}
		$this->assertSame( array(), $this->billing->calls );
	}

	public function test_an_estimate_with_no_billing_customer_on_record_is_checked_by_name(): void {
		$this->mode( 'on' );
		$this->billing->clients['77'] = array( 'id' => 77, 'fname' => 'MORGAN', 'lname' => 'example', 'organization' => '' );
		$est = $this->db->estimate( array( 'billing_cust_id' => '', 'billing_doc_num' => '1001' ) );
		$r   = ZEST_Followup_Ajax::answer( $this->row( $est ), self::CASEY, array( 'answer' => 'reached', 'outcome' => 'deciding', 'channel' => 'call', 'expect' => '0' ) );
		$this->assertSame( 'written', $r['doc']['state'], 'the estimate\'s own client carries the name this app holds' );
		$this->assertSame( '77', $this->row( $est )['billing_cust_id'], 'and its id is kept on the row' );

		$this->billing->docs['501']   = ZDZ_Test_EstFu_Billing::doc();
		$this->billing->clients['77'] = array( 'id' => 77, 'fname' => 'Jordan', 'lname' => 'Blake', 'organization' => '' );
		$est = $this->db->estimate( array( 'billing_cust_id' => '', 'billing_doc_num' => '1001' ) );
		$r   = ZEST_Followup_Ajax::answer( $this->row( $est ), self::CASEY, array( 'answer' => 'reached', 'outcome' => 'deciding', 'channel' => 'call', 'expect' => '0' ) );
		$this->assertSame( array( 'skipped', 'customer_old' ), array( $r['doc']['state'], $r['doc']['why_code'] ), 'another name: nothing is written' );
		$this->assertSame( '', $this->row( $est )['billing_cust_id'] );
		$this->assertSame( '', $this->billing->docs['501']['notes'] );

		unset( $this->billing->clients['77'] );
		$est = $this->db->estimate( array( 'billing_cust_id' => '', 'billing_doc_num' => '1001' ) );
		$r   = ZEST_Followup_Ajax::answer( $this->row( $est ), self::CASEY, array( 'answer' => 'reached', 'outcome' => 'deciding', 'channel' => 'call', 'expect' => '0' ) );
		$this->assertSame( 'customer_old', $r['doc']['why_code'], 'no such client: not confirmed' );
	}

	public function test_a_line_on_an_approved_estimate_means_it_must_be_approved_again(): void {
		$this->mode( 'on' );
		$this->db->update( 'wp_zest_estimates', array( 'approved_fp' => ZEST_Approve::approval_fp( $this->billing->docs['501'] ) ), array( 'id' => $this->est ) );
		$this->assertSame( 'void', $this->reach()['doc']['approval'] );
	}

	/* ── reopen, retry ───────────────────────────────────────────────────── */

	public function test_a_declined_estimate_is_reopened_by_whoever_may_answer(): void {
		$this->mode( 'on' );
		$this->asked();
		$this->reach( 'not_needed' );
		$this->assertSame( 403, ZEST_Followup_Ajax::reopen( $this->row(), self::JORDAN, $this->token() )['status'] );
		$this->assertSame( 'changed', ZEST_Followup_Ajax::reopen( $this->row(), self::ALEX, '0' )['code'] );
		$r = ZEST_Followup_Ajax::reopen( $this->row(), self::ALEX, $this->token() );
		$this->assertTrue( $r['ok'] );
		$this->assertFalse( $r['followup']['declined'] );
		$this->assertTrue( $r['followup']['reopened'] );
		$this->assertStringContainsString( 'reopened by AA', $r['followup']['words'] );
		$this->assertSame( 'written', $r['doc']['state'] );
		$this->assertSame( 'Estimate reopened.', substr( $this->billing->docs['501']['notes'], -strlen( 'Estimate reopened.' ) ) );
		$this->assertStringNotContainsString( 'no longer needs', $this->billing->docs['501']['notes'] );
		$this->assertSame( 'changed', ZEST_Followup_Ajax::reopen( $this->row(), self::ALEX, $this->token() )['code'], 'it is not among the declined ones any more' );
	}

	public function test_a_line_that_did_not_get_through_can_be_tried_again(): void {
		$this->mode( 'on' );
		$id = $this->asked();
		$this->billing->down = true;
		$r                   = $this->reach();
		$this->assertTrue( $r['ok'], 'the answer is saved whatever the billing system does' );
		$this->assertSame( 'failed', $r['doc']['state'] );
		$this->assertSame( 403, ZEST_Followup_Ajax::line_retry( $id, self::JORDAN )['status'] );
		$this->assertSame( 403, ZEST_Followup_Ajax::line_retry( 999, self::ALEX )['status'], 'an id that is not there says nothing more than "not yours"' );
		$this->billing->down = false;
		$r                   = ZEST_Followup_Ajax::line_retry( $id, self::ALEX );
		$this->assertSame( 'written', $r['doc']['state'] );
		$this->assertStringContainsString( 'still deciding', $this->billing->docs['501']['notes'] );
		$this->assertFalse( ZEST_Followup_Ajax::line_retry( $id, self::ALEX )['ok'], 'nothing left to retry' );
	}

	/* ── taken back ──────────────────────────────────────────────────────── */

	public function test_taking_back_an_answer_takes_its_line_off_first_and_puts_the_earlier_one_back(): void {
		$this->mode( 'on' );
		$earlier = 'Follow-up 5/1/2026 (CC): Customer is still deciding.';
		$this->billing->docs['501']['notes'] = "Gate is on the left.\n" . $earlier;
		$id = $this->asked();
		$this->reach( 'other_provider', 'typed on the wrong estimate' );
		$this->assertStringContainsString( 'another provider', $this->billing->docs['501']['notes'] );
		$this->notices = array();

		$this->assertSame( 403, ZEST_Followup_Ajax::undo( $this->row(), self::JORDAN, $this->token() )['status'] );
		$this->assertSame( 'changed', ZEST_Followup_Ajax::undo( $this->row(), self::ALEX, '0' )['code'] );
		$r = ZEST_Followup_Ajax::undo( $this->row(), self::ALEX, $this->token() );
		$this->assertTrue( $r['ok'], (string) ( $r['message'] ?? '' ) );
		$this->assertSame( 'answer', $r['kind'] );
		$this->assertSame( 'restored', $r['doc']['state'] );
		$this->assertSame( "Gate is on the left.\n" . $earlier, $this->billing->docs['501']['notes'] );
		$this->assertSame( 'dropped', $r['crm'], 'its CRM note had not gone: the words never reach the lead' );
		$this->assertSame( array(), ZEST_Followup::queue_of( ZEST_Followup::get( $id ) ) );
		$this->assertSame( 'open', $r['followup']['state'], 'the estimate waits on the salesperson again' );
		$this->assertSame( 'CC', $r['told'][0]['who'], 'whoever asked is told' );
		$this->assertStringContainsString( 'took back', $this->notices[0][3] );
		$this->assertStringNotContainsString( 'wrong estimate', $this->notices[0][3], 'a notice never repeats the words taken back' );
		$this->assertStringNotContainsString( 'Morgan', $this->notices[0][2] . $this->notices[0][3] );
		$this->assertSame( $earlier, substr( $this->row()['notes'], -strlen( $earlier ) ) );
	}

	public function test_nothing_is_taken_back_when_the_billing_line_cannot_come_off(): void {
		$this->mode( 'on' );
		$id = $this->asked();
		$this->reach();
		$this->billing->down = true;
		$r                   = ZEST_Followup_Ajax::undo( $this->row(), self::ALEX, $this->token() );
		$this->assertFalse( $r['ok'] );
		$this->assertStringContainsString( 'Nothing was taken back', $r['message'] );
		$this->assertSame( 'done', ZEST_Followup::get( $id )['state'], 'the record still stands' );
		$this->assertSame( 0, (int) ZEST_Followup::get( $id )['undone'] );
	}

	public function test_a_note_that_already_went_to_the_crm_is_corrected_not_removed(): void {
		$id = $this->asked();
		$this->reach( 'deciding', 'words meant for another customer' );
		ZEST_Followup::take_notes( $id );
		ZEST_Followup::settle_notes( $id, 'posted' );
		$r = ZEST_Followup_Ajax::undo( $this->row(), self::CASEY, $this->token() );
		$this->assertTrue( $r['ok'] );
		$this->assertSame( 'corrected', $r['crm'] );
		$this->assertSame( '', $r['doc']['state'], 'nothing had been written to the billing system, so it is not touched' );
		$fix = ZEST_Followup::queue_of( ZEST_Followup::get( $id ) );
		$this->assertCount( 1, $fix );
		$this->assertStringContainsString( 'FOLLOW-UP TAKEN BACK', $fix[0] );
		$this->assertStringContainsString( 'Disregard it', $fix[0] );
		$this->assertStringNotContainsString( 'another customer', $fix[0], 'the correction is fixed words: it does not repeat the note' );
	}

	public function test_taking_back_an_ask_tells_the_person_asked(): void {
		$id            = $this->asked();
		$this->notices = array();
		$this->assertSame( 403, ZEST_Followup_Ajax::undo( $this->row(), self::ALEX, $this->token() )['status'], 'the person asked answers it; they do not take it back' );
		$r = ZEST_Followup_Ajax::undo( $this->row(), self::CASEY, $this->token() );
		$this->assertSame( 'ask', $r['kind'] );
		$this->assertSame( '', $r['crm'], 'an ask was never noted in the CRM' );
		$this->assertNull( $r['followup'] );
		$this->assertSame( 'withdrawn', ZEST_Followup::get( $id )['state'] );
		$this->assertSame( self::ALEX, $this->notices[0][1] );
		$this->assertStringContainsString( 'nothing for you to do', $this->notices[0][3] );
		$this->assertSame( array(), $this->billing->calls );
	}

	/* ── the CRM note ────────────────────────────────────────────────────── */

	public function test_the_crm_note_goes_only_to_a_lead_shown_to_be_this_estimates(): void {
		$crm                        = new ZDZ_Test_EstFu_Crm();
		ZEST_Nutshell::$test_client = $crm;
		$this->db->update( 'wp_zest_estimates', array( 'crm_lead_id' => '900' ), array( 'id' => $this->est ) );
		$id = $this->asked();
		$this->reach( 'deciding', 'thinking it over' );

		$crm->leads[900] = array( 'id' => 900, 'notes' => array( array( 'note' => 'Estimate #2222 sent.' ) ) );
		$this->assertSame( 0, ZEST_Nutshell::estimate_lead_id( $this->row() ), 'the lead names no estimate of ours: not this estimate\'s' );
		ZEST_Followup_Ajax::cron_crm_note( $id );
		$this->assertSame( array(), $crm->notes );
		$this->assertSame( 'none', ZEST_Followup::get( $id )['crm_state'], 'the words stay in the app, and nothing is retried' );

		ZEST_Followup::queue_note( $id, 'second note' );
		$crm->leads[900] = array( 'id' => 900, 'notes' => array( array( 'note' => 'Estimate #1001 sent.' ) ) );
		$this->assertSame( 900, ZEST_Nutshell::estimate_lead_id( $this->row() ) );
		ZEST_Followup_Ajax::cron_crm_note( $id );
		$this->assertSame( array( array( 900, 'second note' ) ), $crm->notes );
		$this->assertSame( 'posted', ZEST_Followup::get( $id )['crm_state'] );
		ZEST_Followup_Ajax::cron_crm_note( $id );
		$this->assertCount( 1, $crm->notes, 'a second run posts nothing twice' );
	}

	public function test_a_crm_note_that_did_not_go_is_kept_and_tried_again(): void {
		$crm                        = new ZDZ_Test_EstFu_Crm();
		ZEST_Nutshell::$test_client = $crm;
		$this->db->update( 'wp_zest_estimates', array( 'crm_lead_id' => '900' ), array( 'id' => $this->est ) );
		$id = $this->asked();
		ZEST_Followup::queue_note( $id, 'one' );
		ZEST_Followup::queue_note( $id, 'two' );

		$this->assertSame( -1, ZEST_Nutshell::estimate_lead_id( $this->row() ), 'the lead could not be read: nothing is known either way' );
		ZEST_Followup_Ajax::cron_crm_note( $id );
		$row = ZEST_Followup::get( $id );
		$this->assertSame( array( 'one', 'two' ), ZEST_Followup::queue_of( $row ) );
		$this->assertSame( array( 'queued', 1 ), array( $row['crm_state'], (int) $row['crm_tries'] ) );

		$crm->leads[900] = array( 'id' => 900, 'notes' => array( array( 'note' => 'Estimate #1001' ) ) );
		$crm->fail       = 0;
		ZEST_Followup_Ajax::cron_crm_note( $id );
		$this->assertSame( array( array( 900, 'one' ), array( 900, 'two' ) ), $crm->notes, 'in order' );

		ZEST_Followup::queue_note( $id, 'three' );
		$crm->fail = 99;
		ZEST_Followup_Ajax::cron_crm_note( $id );
		ZEST_Followup_Ajax::cron_crm_note( $id );
		$this->assertSame( 'queued', ZEST_Followup::get( $id )['crm_state'] );
		ZEST_Followup_Ajax::cron_crm_note( $id );
		$row = ZEST_Followup::get( $id );
		$this->assertSame( 'failed', $row['crm_state'], 'three tries, then it waits for the next answer' );
		$this->assertSame( array( 'three' ), ZEST_Followup::queue_of( $row ), 'and is never lost' );
		$this->assertSame( 0, ZEST_Nutshell::estimate_lead_id( array( 'crm_lead_id' => '900', 'billing_doc_num' => '' ) ), 'an estimate with no number names no lead' );
		$this->assertFalse( ZEST_Nutshell::add_lead_note( 0, 'x' ) );
		$this->assertFalse( ZEST_Nutshell::add_lead_note( 900, '  ' ) );
	}

	/* ── the history, and the lists ──────────────────────────────────────── */

	public function test_the_history_carries_the_typed_words_for_those_who_may_see_the_estimate(): void {
		$r = ZEST_Followup_Ajax::send( $this->row(), self::CASEY, array( 'to' => self::ALEX, 'note' => 'ask about the gate', 'expect' => '0' ) );
		ZEST_Followup_Ajax::answer( $this->row(), self::ALEX, array( 'answer' => 'tried', 'channel' => 'call', 'note' => 'no pick-up', 'expect' => $this->token() ) );
		$this->reach( 'deciding', 'will decide Friday' );

		$this->assertSame( 403, ZEST_Followup_Ajax::history( $this->est, self::JORDAN )['status'] );
		$this->assertFalse( ZEST_Followup_Ajax::history( 999, self::CASEY )['ok'] );
		$h = ZEST_Followup_Ajax::history( $this->est, self::ALEX );
		$this->assertTrue( $h['ok'] );
		$this->assertSame( '1001', $h['number'] );
		$this->assertCount( 1, $h['rows'] );
		$this->assertSame( 'ask about the gate', $h['rows'][0]['ask_note'] );
		$this->assertSame( 'will decide Friday', $h['rows'][0]['note'] );
		$this->assertSame( 'no pick-up', $h['rows'][0]['tried'][0]['note'] );
		$this->assertSame( 'Call', $h['rows'][0]['channel'] );

		// the answer is taken back by Casey: its words are for whoever typed them, took it back, or may re-send
		ZEST_Followup_Ajax::undo( $this->row(), self::CASEY, $this->token() );
		$this->assertSame( 'will decide Friday', ZEST_Followup_Ajax::history( $this->est, self::ALEX )['rows'][0]['undone'][0]['note'], 'the person who typed it' );
		$this->assertSame( 'will decide Friday', ZEST_Followup_Ajax::history( $this->est, self::CASEY )['rows'][0]['undone'][0]['note'] );
		$GLOBALS['zdz_test_user_meta'][ self::JORDAN ][ ZEST_Access_Requests::META ] = array( 'row:' . $this->est => 1 );   // an administrator grants Jordan the estimate
		$seen = ZEST_Followup_Ajax::history( $this->est, self::JORDAN );
		$this->assertTrue( $seen['ok'] );
		$this->assertSame( '', $seen['rows'][0]['undone'][0]['note'], 'someone it does not concern sees that it happened, not what it said' );
		$this->assertSame( 'AA reached the customer: Still deciding', $seen['rows'][0]['undone'][0]['words'] );
	}

	public function test_a_list_gets_each_estimates_follow_up_and_what_this_person_may_do(): void {
		$id   = $this->asked();
		$none = $this->db->estimate( array( 'billing_doc_num' => '1010' ) );
		$l    = ZEST_Followup_Ajax::for_list( array( $this->est, $none ), self::ALEX );
		$this->assertSame( array( $this->est ), array_keys( $l['map'] ) );
		$v = $l['map'][ $this->est ];
		$this->assertSame( $id, $v['id'] );
		$this->assertTrue( $v['mine'], 'asked of this person' );
		$this->assertFalse( $v['by_me'] );
		$this->assertSame( $this->token(), $v['token'] );
		$this->assertFalse( $l['meta']['can_send'] );
		$this->assertSame( array_keys( ZEST_Followup::OUTCOMES ), array_column( $l['meta']['outcomes'], 'code' ) );
		$this->assertSame( array( false, false, false, false, true, true, false ), array_column( $l['meta']['outcomes'], 'declined' ) );
		$this->assertSame( ZEST_Followup::CHANNELS, array_column( $l['meta']['channels'], 'code' ) );
		$this->assertSame( array( $this->est => true ), $l['any'] );
		$this->assertTrue( ZEST_Followup_Ajax::for_list( array( $this->est ), self::CASEY )['map'][ $this->est ]['by_me'] );

		$this->db->missing = array( 'wp_zest_followups' );
		$l                 = ZEST_Followup_Ajax::for_list( array( $this->est ), self::ALEX );
		$this->assertSame( array(), $l['map'], 'no table yet: no follow-ups, and the list still loads' );
	}

	public function test_what_is_not_an_open_estimate_is_not_followed_up(): void {
		$this->assertSame( '', ZEST_Followup_Ajax::not_open( array( 'doc_type' => 'estimate', 'status' => 'sent' ) ) );
		$this->assertSame( '', ZEST_Followup_Ajax::not_open( array( 'status' => 'created' ) ) );
		$this->assertNotSame( '', ZEST_Followup_Ajax::not_open( array( 'doc_type' => 'invoice', 'status' => 'sent' ) ) );
		$this->assertNotSame( '', ZEST_Followup_Ajax::not_open( array( 'status' => 'sent', 'accepted_at' => '2026-01-06 09:00:00' ) ) );
		$this->assertNotSame( '', ZEST_Followup_Ajax::not_open( array( 'status' => 'converted' ) ) );
		$this->assertNotSame( '', ZEST_Followup_Ajax::not_open( array( 'status' => 'imported' ) ) );
	}
}
