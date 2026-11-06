<?php
/**
 * The stored-data ledger's sync: cursors that resume where a pass stopped, the history walk's end, the what-changed
 * cycle, the CRM's leads and notes, the staging guard, the lock, and that every provider call is a read. Neutral data.
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/ledger-test-doubles.php';

final class LedgerSyncTest extends TestCase {

	protected function setUp(): void {
		zdz_test_ldg_reset();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['zdz_test_ldg_ticks'] );
	}

	private function inv( int $id, string $date, array $more = array() ): array {
		return array_merge( array(
			'invoiceid' => $id, 'invoice_number' => sprintf( '%07d', $id ), 'customerid' => 100 + $id, 'organization' => 'Example Co ' . $id,
			'create_date' => $date, 'amount' => array( 'amount' => '100.00', 'code' => 'USD' ), 'outstanding' => array( 'amount' => '0.00' ),
			'v3_status' => 'paid', 'status' => 4, 'vis_state' => 0, 'updated' => $date . ' 10:00:00', 'lines' => array(),
		), $more );
	}

	private function fb( array $docs ): ZDZ_Test_Ldg_FB {
		$fb       = new ZDZ_Test_Ldg_FB();
		$fb->docs = array_merge( $fb->docs, $docs );
		ZANA_Ledger::$fb = $fb;
		return $fb;
	}

	public function test_history_walks_newest_year_first_reads_archived_and_stops_after_two_empty_years(): void {
		$fb = $this->fb( array( 'invoices' => array(
			$this->inv( 1, '2026-02-01' ), $this->inv( 2, '2026-03-01' ), $this->inv( 3, '2026-04-01' ),
			$this->inv( 4, '2025-05-01', array( 'vis_state' => 2 ) ), // archived: only the archived list returns it
			$this->inv( 5, '2024-07-01' ),
		) ) );
		$st = ZANA_Ledger::sync( 25 );
		$this->assertTrue( $st['ok'] );
		$s = ZANA_Ledger::state();
		$this->assertTrue( $s['invoices']['done'], 'two empty years (2023, 2022) end the walk' );
		$this->assertSame( 2022, $s['invoices']['loaded_from'] );
		$this->assertSame( array( 1, 2, 3, 4, 5 ), array_keys( ZANA_Ledger::$store->rows['invoices'] ) );
		$this->assertSame( '2026-06-14', $s['invoices']['updated_since'], 'the what-changed cursor starts a day before the walk' );
		// the walk read each year's default list, then its archived one
		$years = array();
		foreach ( $fb->calls as $c ) {
			if ( 'invoices' === $c[1] && isset( $c[2]['date_min'] ) && 1 === $c[3] ) {
				$years[] = substr( $c[2]['date_min'], 0, 4 ) . ':' . ( $c[2]['vis_state'] ?? 0 );
			}
		}
		$this->assertSame( array( '2026:0', '2026:2', '2025:0', '2025:2', '2024:0', '2024:2', '2023:0', '2023:2', '2022:0', '2022:2' ), $years );
	}

	public function test_a_pass_out_of_time_resumes_at_the_exact_page(): void {
		$fb = $this->fb( array( 'invoices' => array( $this->inv( 1, '2026-01-02' ), $this->inv( 2, '2026-01-03' ), $this->inv( 3, '2026-01-04' ), $this->inv( 4, '2026-01-05' ), $this->inv( 5, '2026-01-06' ) ) ) );
		$GLOBALS['zdz_test_ldg_ticks'] = 3; // room for two pages only
		ZANA_Ledger::sync( 25 );
		$s = ZANA_Ledger::state();
		$this->assertFalse( $s['invoices']['done'] );
		$this->assertSame( 2026, $s['invoices']['year'] );
		$this->assertSame( 3, $s['invoices']['page'], 'two pages read: the next pass starts at page 3' );
		$this->assertCount( 4, ZANA_Ledger::$store->rows['invoices'] );
		$GLOBALS['zdz_test_ldg_ticks'] = 100000;
		$fb->calls                     = array();
		ZANA_Ledger::sync( 25 );
		$walk = array_values( array_filter( $fb->calls, function ( $c ) { return 'invoices' === $c[1] && isset( $c[2]['date_min'] ); } ) );
		$this->assertSame( array( 'invoices', array( 'date_min' => '2026-01-01', 'date_max' => '2026-12-31' ), 3 ), array_slice( $walk[0], 1 ), 'the history walk resumed at page 3 of 2026' );
		$this->assertCount( 5, ZANA_Ledger::$store->rows['invoices'] );
	}

	public function test_the_rules_first_year_ends_the_walk(): void {
		ZANA_Ledger_Rules::$override = array( 'history_from_year' => 2025 );
		$this->fb( array( 'invoices' => array( $this->inv( 1, '2026-02-01' ), $this->inv( 2, '2023-02-01' ) ) ) );
		ZANA_Ledger::sync( 25 );
		$s = ZANA_Ledger::state();
		$this->assertTrue( $s['invoices']['done'] );
		$this->assertSame( 2025, $s['invoices']['loaded_from'] );
		$this->assertArrayNotHasKey( 2, ZANA_Ledger::$store->rows['invoices'], 'nothing before the first year is read' );
	}

	public function test_what_changed_reads_the_default_archived_and_deleted_lists_then_moves_the_cursor(): void {
		$fb = $this->fb( array( 'invoices' => array() ) );
		ZANA_Ledger::sync( 25 );
		$fb->docs['invoices'] = array(
			$this->inv( 9, '2026-06-14', array( 'updated' => '2026-06-15 09:00:00' ) ),
			$this->inv( 8, '2026-06-01', array( 'vis_state' => 1, 'updated' => '2026-06-15 09:00:00' ) ), // deleted since
		);
		$fb->calls = array();
		ZANA_Ledger::$now = gmmktime( 16, 0, 0, 6, 16, 2026 );
		ZANA_Ledger::sync( 25 );
		$inc = array_values( array_filter( $fb->calls, function ( $c ) { return 'invoices' === $c[1] && isset( $c[2]['updated_min'] ); } ) );
		$this->assertSame( array( 0, 2, 1 ), array_map( function ( $c ) { return (int) ( $c[2]['vis_state'] ?? 0 ); }, $inc ) );
		$this->assertSame( 1, (int) ZANA_Ledger::$store->rows['invoices'][8]['vis_state'], 'a deleted document is held as deleted, so it stops counting' );
		$s = ZANA_Ledger::state();
		$this->assertSame( '2026-06-15', $s['invoices']['updated_since'], 'next time from a day before this cycle began' );
		$this->assertNotNull( $s['invoices']['caught_up_at'] );
	}

	public function test_invoice_rows_derive_status_dates_and_drafts(): void {
		$draft = ZANA_Ledger::invoice_row( $this->inv( 1, '2026-01-10', array( 'v3_status' => 'draft', 'status' => 1 ) ) );
		$this->assertSame( 0, $draft['status'], 'a draft is status 0, never a sale' );
		$this->assertNull( $draft['paid_date'] );
		$disputed = ZANA_Ledger::invoice_row( $this->inv( 2, '2026-01-10', array( 'v3_status' => 'disputed', 'status' => 0 ) ) );
		$this->assertSame( 90, $disputed['status'], 'the billing system\'s 0 (disputed) is never read as a draft' );
		$terms = ZANA_Ledger::invoice_row( $this->inv( 3, '2026-01-10', array( 'due_offset_days' => 30, 'date_paid' => '2026-02-01' ) ) );
		$this->assertSame( '2026-02-09', $terms['due_date'] );
		$this->assertSame( '2026-02-01', $terms['paid_date'] );
		$owing = ZANA_Ledger::invoice_row( $this->inv( 4, '2026-01-10', array( 'outstanding' => array( 'amount' => '40.00' ), 'date_paid' => '2026-02-01' ) ) );
		$this->assertNull( $owing['paid_date'], 'an invoice still owing has no paid date' );
		$this->assertSame( 60.0, $owing['paid'] );
		$this->assertSame( 'Example Co 4', $owing['customer_name'] );
	}

	public function test_payments_are_read_and_a_daily_reread_removes_a_deleted_payment(): void {
		$fb = $this->fb( array( 'payments' => array(
			array( 'paymentid' => 1, 'invoiceid' => 5, 'date' => '2026-03-02', 'amount' => array( 'amount' => '50.00' ), 'type' => 'Check', 'updated' => '2026-03-02 10:00:00' ),
			array( 'paymentid' => 2, 'invoiceid' => 6, 'date' => '2026-04-02', 'amount' => array( 'amount' => '25.00' ), 'type' => 'Credit', 'updated' => '2026-04-02 10:00:00' ),
		) ) );
		ZANA_Ledger::sync( 25 );
		$this->assertCount( 2, ZANA_Ledger::$store->rows['payments'] );
		$this->assertTrue( ZANA_Ledger::state()['payments']['done'] );
		// a payment deleted in the billing system (it never shows as changed)
		$fb->docs['payments'] = array_slice( $fb->docs['payments'], 0, 1 );
		ZANA_Ledger::$now = gmmktime( 16, 0, 0, 6, 16, 2026 );
		ZANA_Ledger::sync( 25 );
		$this->assertSame( array( 1 ), array_keys( ZANA_Ledger::$store->rows['payments'] ), 'the next day\'s re-read of the year removed it' );
	}

	public function test_leads_backfill_then_newest_first_then_open_refresh_marks_closed_leads(): void {
		$ns = new ZDZ_Test_Ldg_NS();
		for ( $i = 1; $i <= 230; $i++ ) {
			$ns->leads[] = array( 'id' => $i, 'status' => 0, 'createdTime' => '2026-05-01T10:00:00+00:00', 'sources' => array( array( 'name' => 'Example source' ) ) );
		}
		ZANA_Ledger::$ns = $ns;
		ZANA_Ledger::$store->notes_ready = false;
		ZANA_Ledger::sync( 25 );
		$s = ZANA_Ledger::state();
		$this->assertTrue( $s['leads']['done'] );
		$this->assertSame( 230, $s['leads']['max_id'] );
		$this->assertSame( 'Example source', ZANA_Ledger::$store->rows['leads'][7]['source'] );
		// a new lead, and one closed in the CRM since (the open read no longer returns it)
		$ns->leads[]       = array( 'id' => 231, 'status' => 0, 'createdTime' => '2026-06-15T10:00:00+00:00' );
		$ns->leads[4]['status'] = 10;
		$ns->calls          = array();
		ZANA_Ledger::$now  = gmmktime( 15, 0, 0, 6, 15, 2026 ) + 4 * 3600;
		ZANA_Ledger::sync( 25 );
		$this->assertSame( array( 'find_leads_page', array( 'filter' => 2 ), 1, 'DESC' ), $ns->calls[0], 'new leads are read newest first' );
		$this->assertArrayHasKey( 231, ZANA_Ledger::$store->rows['leads'] );
		$this->assertContains( array( 'find_leads_page', array( 'status' => 0, 'filter' => 2 ), 1, 'ASC' ), $ns->calls, 'open leads are read again every few hours' );
		$this->assertSame( 10, ZANA_Ledger::$store->rows['leads'][5]['status'], 'the closed lead holds its status from the CRM' );
		$this->assertSame( gmdate( 'Y-m-d H:i:s', ZANA_Ledger::$now ), end( ZANA_Ledger::$store->vanished ), 'after a complete open read, open rows it did not touch are marked' );
	}

	public function test_lead_notes_come_from_the_full_objects_with_access_codes_cut(): void {
		$ns = new ZDZ_Test_Ldg_NS();
		$ns->leads = array(
			array( 'id' => 1, 'status' => 0, 'notes' => array( array( 'id' => 'n1', 'body' => 'Called Alex, left a message.', 'createdTime' => '2026-05-02T10:00:00+00:00', 'user' => array( 'name' => 'Jordan' ) ) ) ),
			array( 'id' => 2, 'status' => 0, 'notes' => array() ),
		);
		ZANA_Ledger::$ns = $ns;
		ZANA_Ledger::sync( 25 );
		$this->assertSame( 'Called Alex, left a message.', ZANA_Ledger::$store->notes[1]['n1']['body'] );
		$this->assertSame( 'Jordan', ZANA_Ledger::$store->notes[1]['n1']['author'] );
		$this->assertSame( 'find', ZANA_Ledger::state()['leads']['notes_via'] );
		$this->assertArrayHasKey( 2, ZANA_Ledger::$store->reads, 'a lead with no notes is still stamped as read' );
		$this->assertNull( ZANA_Ledger::note_row( array( 'id' => 'x', 'body' => '   ' ) ), 'a note with no text is not kept' );
	}

	public function test_a_staging_copy_reads_nothing(): void {
		$fb = $this->fb( array( 'invoices' => array( $this->inv( 1, '2026-02-01' ) ) ) );
		ZANA_Ledger::$staging = true;
		$st = ZANA_Ledger::sync( 25 );
		$this->assertFalse( $st['ok'] );
		$this->assertFalse( $st['more'] );
		$this->assertSame( array(), $fb->calls );
		$this->assertSame( array(), ZANA_Ledger::$store->rows['invoices'] );
	}

	public function test_a_running_pass_holds_the_lock(): void {
		$fb = $this->fb( array( 'invoices' => array( $this->inv( 1, '2026-02-01' ) ) ) );
		ZANA_Ledger::$store->locked = true;
		$st = ZANA_Ledger::sync( 25 );
		$this->assertFalse( $st['ok'] );
		$this->assertSame( array(), $fb->calls );
	}

	public function test_a_failing_provider_does_not_chain_and_records_the_error(): void {
		$fb       = $this->fb( array( 'invoices' => array( $this->inv( 1, '2026-02-01' ) ) ) );
		$fb->fail = true;
		$st       = ZANA_Ledger::sync( 25 );
		$this->assertTrue( $st['ok'] );
		$this->assertFalse( $st['more'], 'a failing provider waits for the hourly run' );
		$this->assertStringContainsString( 'HTTP 503', ZANA_Ledger::state()['invoices']['last_error'] );
		$this->assertTrue( ZANA_Ledger::coverage()['invoices']['last_error'], 'coverage says a part failed, never its text' );
	}

	public function test_every_provider_call_is_a_read(): void {
		$fb = $this->fb( array( 'invoices' => array( $this->inv( 1, '2026-02-01' ) ), 'estimates' => array(), 'payments' => array() ) );
		$ns = new ZDZ_Test_Ldg_NS();
		$ns->leads = array( array( 'id' => 1, 'status' => 0 ) );
		ZANA_Ledger::$ns = $ns;
		ZANA_Ledger::sync( 25 );
		$this->assertSame( array( 'list_page' ), array_values( array_unique( array_column( $fb->calls, 0 ) ) ) );
		$this->assertEmpty( array_diff( array_unique( array_column( $ns->calls, 0 ) ), array( 'find_leads_page', 'read_lead' ) ) );
		// the CRM connector's strict road refuses anything but a read
		$crm = ( new ReflectionClass( 'ZDZ_Core_Nutshell' ) )->newInstanceWithoutConstructor();
		try {
			$crm->rpc_strict( 'newNote', array() );
			$this->fail( 'a write must be refused' );
		} catch ( RuntimeException $e ) {
			$this->assertStringContainsString( 'Only CRM reads', $e->getMessage() );
		}
	}

	public function test_a_rules_change_re_derives_only_the_derived_columns(): void {
		$fb = $this->fb( array( 'invoices' => array( $this->inv( 1, '2026-02-01', array( 'po_number' => '10001-XY' ) ) ) ) );
		ZANA_Ledger::sync( 25 );
		$row = ZANA_Ledger::$store->rows['invoices'][1];
		$this->assertSame( '', $row['lead_source'], 'no rule names XY yet' );
		$this->assertSame( '10001', $row['zip'] );
		$this->assertFalse( ZANA_Ledger::relabel_pending( ZANA_Ledger::state() ) );
		// the pack now says what XY means
		ZANA_Ledger_Rules::$override = array( 'lead_sources' => array( 'codes' => array( 'XY' => 'Example channel' ) ) );
		$this->assertTrue( ZANA_Ledger::relabel_pending( ZANA_Ledger::state() ) );
		ZANA_Ledger::$store->rows['invoices'][1]['amount'] = 999.0; // the walk must never touch a figure
		ZANA_Ledger::sync( 25 );
		$row = ZANA_Ledger::$store->rows['invoices'][1];
		$this->assertSame( 'Example channel', $row['lead_source'] );
		$this->assertSame( 999.0, $row['amount'] );
		$this->assertFalse( ZANA_Ledger::relabel_pending( ZANA_Ledger::state() ) );
	}

	public function test_the_jobs_app_gets_the_invoice_number_for_a_billing_id(): void {
		ZANA_Ledger::$store->rows['invoices'][4321] = array( 'invoice_id' => 4321, 'invoice_number' => '0000123', 'vis_state' => 0 );
		ZANA_Ledger::$store->rows['invoices'][4322] = array( 'invoice_id' => 4322, 'invoice_number' => '0000124', 'vis_state' => 1 );
		$this->assertSame( '0000123', ZANA_Ledger::job_invoice_number( '4321', array() ) );
		$this->assertSame( '4322', ZANA_Ledger::job_invoice_number( '4322', array() ), 'a deleted invoice is not answered' );
		$this->assertSame( '9999', ZANA_Ledger::job_invoice_number( '9999', array() ), 'an id the copy does not hold is left as it was' );
		$this->assertSame( 'INV-7', ZANA_Ledger::job_invoice_number( 'INV-7', array() ) );
	}

	public function test_billing_connector_query_strings_keep_bracketed_keys(): void {
		$this->assertSame( 'page=2&search[date_min]=2026-01-01&include[]=lines', ZDZ_Core_FreshBooks::query_string( array( 'page' => 2, 'search[date_min]' => '2026-01-01', 'include[]' => 'lines', 'bad key' => 'x' ) ) );
		$p = ZDZ_Core_FreshBooks::page_of( array( 'response' => array( 'result' => array( 'invoices' => array( array( 'invoiceid' => 1 ) ), 'pages' => 3, 'total' => 201 ) ) ), 'invoices' );
		$this->assertSame( array( 'pages' => 3, 'total' => 201 ), array( 'pages' => $p['pages'], 'total' => $p['total'] ) );
		$this->assertSame( array( 'items' => array(), 'pages' => 1, 'total' => 0 ), ZDZ_Core_FreshBooks::page_of( null, 'invoices' ) );
	}
}
