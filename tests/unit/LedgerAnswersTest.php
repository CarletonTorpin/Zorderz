<?php
/**
 * What reads the stored-data ledger: who may (deny by default), the home figures' sums, the ask bar's routing, accepted
 * estimates never invoiced, lead sources grouped by the business's own rules, the enricher, periods, the shadow check,
 * payment states and the invoice view's gate. Neutral data only.
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/ledger-test-doubles.php';

final class LedgerAnswersTest extends TestCase {

	protected function setUp(): void {
		zdz_test_ldg_reset();
		ZANA_Ledger_Rules::$override = array(
			'lead_sources'  => array(
				'codes'         => array( 'XY' => 'Example channel', 'RF' => 'Referral' ),
				'suffix_codes'  => array( 'XY' ),
				'note_keywords' => array( 'example flyer' => 'Flyers' ),
				'note_tags'     => array( array( 'label' => 'Example channel', 'tags' => array( 'examplead', 'example ad' ), 'except' => array( 'exampleorg' ) ) ),
				'aliases'       => array( 'flyer' => 'Flyers' ),
			),
			'reference'     => array( 'self_codes' => array( 'EXC' ), 'product_codes' => array( 'GT' => 'Gate' ) ),
			'brands'        => array( 'Northwind' => array( 'northwind' ) ),
			'source_groups' => array(
				array( 'key' => 'channel', 'label' => 'Example channel', 'words' => array( 'example channel', 'channel ads' ), 'crm' => array( 'example + paid', '=example channel' ), 'billing' => array( '=example channel' ), 'aside' => array( '=example' ), 'aside_label' => 'Plain "example", not marked as paid' ),
				array( 'key' => 'referral', 'label' => 'Referrals', 'words' => array( 'referral', 'referrals' ), 'crm' => array( 'referral' ), 'billing' => array( 'referral' ) ),
			),
		);
	}

	protected function tearDown(): void {
		unset( $GLOBALS['zdz_test_filters'], $GLOBALS['zdz_test_options'] );
	}

	/* ─────────────────────────── fixtures ─────────────────────────── */

	/** Invoices (the ledger's row shape), estimates, payments for June 2026 and earlier. */
	private function ledger_rows(): void {
		$inv = array(
			array( 'id' => 1, 'invoice_number' => '1001', 'customer_id' => 11, 'customer_name' => 'Example Co', 'city' => 'Riverton', 'zip' => '10001', 'd' => '2026-06-02', 'due_date' => '2026-06-02', 'paid_date' => '2026-06-05', 'amount' => 1000.0, 'paid' => 1000.0, 'outstanding' => 0.0, 'vis_state' => 0, 'status' => 4, 'lead_source' => 'Example channel', 'rep_code' => 'AB', 'mix' => '{"Gates":800,"Other":200}', 'brands' => '{"Northwind":800}' ),
			array( 'id' => 2, 'invoice_number' => '1002', 'customer_id' => 12, 'customer_name' => 'Sam Rivera', 'city' => 'Midvale', 'zip' => '', 'd' => '2026-06-10', 'due_date' => '2026-06-12', 'paid_date' => null, 'amount' => 500.0, 'paid' => 200.0, 'outstanding' => 300.0, 'vis_state' => 2, 'status' => 2, 'lead_source' => 'Referral', 'rep_code' => 'CD', 'mix' => '{"Fencing":500}', 'brands' => '{}' ),
			array( 'id' => 3, 'invoice_number' => '1003', 'customer_id' => 13, 'customer_name' => 'Robin Lee', 'city' => 'Riverton', 'zip' => '', 'd' => '2026-06-11', 'due_date' => null, 'paid_date' => null, 'amount' => 700.0, 'paid' => 0.0, 'outstanding' => 700.0, 'vis_state' => 0, 'status' => 0, 'lead_source' => '', 'rep_code' => 'AB', 'mix' => '{}', 'brands' => '{}' ), // a draft
			array( 'id' => 4, 'invoice_number' => '1004', 'customer_id' => 14, 'customer_name' => 'Quinn Park', 'city' => 'Riverton', 'zip' => '', 'd' => '2026-06-12', 'due_date' => null, 'paid_date' => null, 'amount' => 900.0, 'paid' => 0.0, 'outstanding' => 900.0, 'vis_state' => 1, 'status' => 2, 'lead_source' => '', 'rep_code' => 'AB', 'mix' => '{}', 'brands' => '{}' ), // deleted
			array( 'id' => 5, 'invoice_number' => '1005', 'customer_id' => 11, 'customer_name' => 'Example Co', 'city' => 'Riverton', 'zip' => '', 'd' => '2026-02-01', 'due_date' => '2026-03-03', 'paid_date' => null, 'amount' => 400.0, 'paid' => 0.0, 'outstanding' => 400.0, 'vis_state' => 0, 'status' => 2, 'lead_source' => '', 'rep_code' => 'AB/CD', 'mix' => '{}', 'brands' => '{}' ),
			array( 'id' => 6, 'invoice_number' => '1006', 'customer_id' => 15, 'customer_name' => 'Zero Ltd', 'city' => '', 'zip' => '', 'd' => '2026-06-03', 'due_date' => null, 'paid_date' => null, 'amount' => 0.0, 'paid' => 0.0, 'outstanding' => 0.0, 'vis_state' => 0, 'status' => 2, 'lead_source' => '', 'rep_code' => '', 'mix' => '{}', 'brands' => '{}' ),
		);
		$est = array(
			array( 'id' => 21, 'estimate_number' => '21', 'customer_id' => 11, 'customer_name' => 'Example Co', 'city' => 'Riverton', 'zip' => '', 'd' => '2026-06-01', 'amount' => 1000.0, 'accepted' => 1, 'invoiced' => 1, 'status_label' => 'invoiced', 'vis_state' => 0, 'lead_source' => 'Example channel', 'rep_code' => 'AB', 'mix' => '{}', 'brands' => '{}' ),
			array( 'id' => 22, 'estimate_number' => '22', 'customer_id' => 16, 'customer_name' => 'Morgan Hill', 'city' => 'Midvale', 'zip' => '', 'd' => '2026-06-04', 'amount' => 300.0, 'accepted' => 1, 'invoiced' => 0, 'status_label' => 'accepted', 'vis_state' => 0, 'lead_source' => '', 'rep_code' => 'CD', 'mix' => '{}', 'brands' => '{}' ),
			array( 'id' => 23, 'estimate_number' => '23', 'customer_id' => 17, 'customer_name' => 'Devin Ash', 'city' => 'Midvale', 'zip' => '', 'd' => '2026-06-05', 'amount' => 250.0, 'accepted' => 0, 'invoiced' => 0, 'status_label' => 'sent', 'vis_state' => 0, 'lead_source' => '', 'rep_code' => 'AB', 'mix' => '{}', 'brands' => '{}' ),
			array( 'id' => 24, 'estimate_number' => '24', 'customer_id' => 18, 'customer_name' => 'Blake Fir', 'city' => '', 'zip' => '', 'd' => '2026-06-06', 'amount' => 50.0, 'accepted' => 0, 'invoiced' => 0, 'status_label' => 'draft', 'vis_state' => 0, 'lead_source' => '', 'rep_code' => 'AB', 'mix' => '{}', 'brands' => '{}' ),
		);
		$pay = array(
			array( 'id' => 31, 'invoice_id' => 1, 'd' => '2026-06-05', 'amount' => 1000.0, 'pay_type' => 'Check', 'vis_state' => 0 ),
			array( 'id' => 32, 'invoice_id' => 2, 'd' => '2026-06-11', 'amount' => 200.0, 'pay_type' => 'Credit', 'vis_state' => 0 ),
			array( 'id' => 33, 'invoice_id' => 9, 'd' => '2026-01-20', 'amount' => 150.0, 'pay_type' => 'Check', 'vis_state' => 0 ),
			array( 'id' => 34, 'invoice_id' => 9, 'd' => '2026-06-20', 'amount' => 99.0, 'pay_type' => 'Check', 'vis_state' => 1 ), // deleted
		);
		$all = array( 'invoices' => $inv, 'estimates' => $est, 'payments' => $pay );
		ZANA_Ledger_Figures::$rows = function ( $kind ) use ( $all ) { return $all[ $kind ] ?? array(); };
		ZANA_Ledger_Figures::$lead_rows = function () {
			return array(
				array( 'id' => 1, 'created_at' => '2026-06-02 10:00:00', 'closed_at' => '2026-06-09 10:00:00', 'status' => 10, 'source' => 'Example paid search' ),
				array( 'id' => 2, 'created_at' => '2026-06-03 10:00:00', 'closed_at' => '2026-06-10 10:00:00', 'status' => 11, 'source' => 'Referral from Alex' ),
				array( 'id' => 3, 'created_at' => '2026-06-04 10:00:00', 'closed_at' => null, 'status' => 0, 'source' => 'Example' ),
				array( 'id' => 4, 'created_at' => '2026-05-20 10:00:00', 'closed_at' => '2026-06-01 10:00:00', 'status' => 10, 'source' => '' ),
				array( 'id' => 5, 'created_at' => '2026-05-21 10:00:00', 'closed_at' => null, 'status' => 1, 'source' => 'Example channel' ),
			);
		};
		ZANA_Home_Figures::$coverage = array(
			'invoices'  => array( 'complete' => true, 'last_ok' => '2026-06-15 14:30:00', 'rows' => 6 ),
			'estimates' => array( 'complete' => true, 'last_ok' => '2026-06-15 14:30:00', 'rows' => 4 ),
			'payments'  => array( 'complete' => true, 'last_ok' => '2026-06-15 14:30:00', 'caught_up_at' => '2026-06-15 14:30:00', 'loaded_from' => 2024 ),
			'leads'     => array( 'complete' => true, 'last_ok' => '2026-06-15 10:00:00' ),
			'notes'     => array( 'complete' => true, 'last_ok' => '2026-06-15 10:00:00', 'ready' => true ),
		);
		ZANA_Home_Figures::$notes = function () {
			return array(
				'leads' => array(
					array( 'lead_id' => 1, 'created_at' => '2026-06-02 10:00:00', 'read_at' => '2026-06-15 09:00:00', 'via' => 'get' ),
					array( 'lead_id' => 2, 'created_at' => '2026-06-03 10:00:00', 'read_at' => '2026-06-15 09:00:00', 'via' => 'get' ),
					array( 'lead_id' => 3, 'created_at' => '2026-06-04 10:00:00', 'read_at' => null, 'via' => null ),
				),
				'notes' => array(
					array( 'lead_id' => 1, 'created_at' => '2026-06-02 10:03:00', 'author' => 'Jordan', 'body' => 'Intake: wants a quote.' ),
					array( 'lead_id' => 1, 'created_at' => '2026-06-03 09:00:00', 'author' => 'Jordan', 'body' => 'Called back, booked a visit.' ),
					array( 'lead_id' => 2, 'created_at' => '2026-06-05 09:00:00', 'author' => 'Jordan', 'body' => ZDZ_Contacts::note_opening() . 'Called (Riley, Jun 5)' ),
				),
			);
		};
	}

	/* ─────────────────────────── who may ─────────────────────────── */

	public function test_the_gate_denies_by_default_and_never_lifts_the_kiosk_floor(): void {
		$w = function ( $who, $what ) { return ZANA_Ledger_Access::why_not( zdz_test_ldg_ctx( $who ), $what ); };
		$this->assertSame( 'signed_out', $w( 'nobody', 'leads' ) );
		$this->assertSame( 'kiosk', $w( 'kiosk', 'company' ), 'a shared device is refused even with every right set' );
		$this->assertSame( 'demo', $w( 'demo', 'company' ) );
		$this->assertSame( 'tier', $w( 'tech', 'leads' ), 'a viewer reads nothing from the ledger' );
		$this->assertSame( 'rights', $w( 'sales', 'company' ) );
		$this->assertSame( '', $w( 'sales', 'mine' ) );
		$this->assertSame( '', $w( 'sales', 'list_own' ) );
		$this->assertSame( 'rights', $w( 'sales_nocode', 'list_own' ) );
		$this->assertSame( '', $w( 'owner', 'list' ) );
		$this->assertSame( '', $w( 'pv_owner', 'company' ), 'a Privacy View account keeps its aggregates' );
		$this->assertSame( 'privacy_view', $w( 'pv_owner', 'list' ), '…but never a list that names customers' );
		$this->assertSame( 'privacy_view', $w( 'pv_owner', 'doc_all' ) );
		$this->assertSame( 'rights', $w( 'owner', 'unknown thing' ) );
		$k = ZANA_Ledger_Access::normalize( zdz_test_ldg_ctx( 'kiosk' ) );
		$this->assertFalse( $k['revenue'] );
		$this->assertSame( array(), $k['codes'] );
	}

	/* ─────────────────────────── home figures ─────────────────────────── */

	public function test_home_figures_for_the_company_count_what_the_definitions_say(): void {
		$this->ledger_rows();
		zdz_test_ldg_as( 'owner' );
		$r = ZANA_Home_Figures::from_capability( array( 'user_id' => 7, 'month' => '2026-06' ) );
		$this->assertTrue( $r['success'] );
		$this->assertSame( 'company', $r['shaped_for'] );
		$m = $r['company']['revenue_mtd'];
		// June: #1 (1000, paid) + #2 (500, archived, 300 owed); the draft, the deleted and the $0 invoice are left out
		$this->assertSame( 1500.0, $m['value'] );
		$this->assertSame( 2, $m['invoices'] );
		$this->assertSame( 1200.0, $m['collected'] );
		$this->assertSame( 300.0, $m['owed'] );
		$this->assertSame( 1900.0, $r['company']['revenue_ytd']['value'], 'the year adds February\'s 400' );
		// received by the day it arrived, on any invoice; credits apart; a deleted payment never counts
		$this->assertSame( array( 'value' => 1200.0, 'payments' => 2, 'credits' => array( 'count' => 1, 'amount' => 200.0 ) ), $m['received'] );
		$this->assertSame( 1350.0, $r['company']['revenue_ytd']['received']['value'] );
		$this->assertSame( 3, $r['company']['estimates'], 'drafts are not counted' );
		$this->assertSame( 2, $r['company']['estimates_accepted'], 'accepted or invoiced' );
		$this->assertSame( 1, $r['company']['estimates_drafts'] );
		$this->assertSame( 3, $r['company']['leads_new'] );
		$this->assertSame( 2, $r['company']['leads_won'], 'won by the day closed, whenever created' );
		$this->assertSame( round( 2 / 3, 4 ), $r['company']['win_rate'] );
		$this->assertSame( 2, $r['company']['leads_open'], 'open and pending' );
		$c = $r['company']['leads_contacted'];
		$this->assertSame( 1, $c['contacted'], 'the intake and the contact log\'s own note are not a person\'s follow-up' );
		$this->assertSame( 3, $c['of'] );
		$this->assertSame( 1, $c['unread'] );
		$this->assertFalse( $r['sources']['invoices']['stale'] );
		$this->assertTrue( $r['sources']['leads']['stale'], 'five hours old' );
		$this->assertArrayHasKey( 'revenue_mtd', $r['definitions'] );
	}

	public function test_a_salesperson_gets_their_own_counts_and_no_money(): void {
		$this->ledger_rows();
		zdz_test_ldg_as( 'sales' );
		$r = ZANA_Home_Figures::from_capability( array( 'user_id' => 7, 'month' => '2026-06' ) );
		$this->assertSame( 'mine', $r['shaped_for'] );
		$this->assertArrayNotHasKey( 'company', $r );
		$this->assertSame( 1, $r['mine']['invoices_mtd'], 'June: #1 only (the draft and the deleted one are not counted)' );
		$this->assertSame( 2, $r['mine']['invoices_ytd'], 'with February\'s shared job' );
		$this->assertSame( 2, $r['mine']['estimates'] );
		$this->assertSame( 1, $r['mine']['estimates_accepted'] );
		$this->assertArrayNotHasKey( 'payments', $r['sources'] );
		$json = (string) json_encode( $r );
		$this->assertStringNotContainsString( 'Example Co', $json );
		$this->assertStringNotContainsString( '1001', $json, 'never a document number' );
		zdz_test_ldg_as( 'sales_nocode' );
		$r = ZANA_Home_Figures::from_capability( array( 'user_id' => 7, 'month' => '2026-06' ) );
		$this->assertTrue( $r['mine']['no_rep_code'], 'no code: no figures of their own, never the company\'s' );
	}

	public function test_home_figures_refuse_the_shared_device_a_viewer_and_bad_months(): void {
		$this->ledger_rows();
		zdz_test_ldg_as( 'owner' );
		$this->assertTrue( ZANA_Home_Figures::from_capability( array( 'user_id' => 7, 'is_kiosk' => true ) )['denied'] );
		zdz_test_ldg_as( 'tech' );
		$this->assertSame( 'tier', ZANA_Home_Figures::from_capability( array( 'user_id' => 7 ) )['reason'] );
		zdz_test_ldg_as( 'owner' );
		$this->assertArrayHasKey( 'error', ZANA_Home_Figures::from_capability( array( 'user_id' => 7, 'month' => '2026-07' ) ) );
		$this->assertArrayHasKey( 'error', ZANA_Home_Figures::from_capability( array( 'user_id' => 7, 'month' => 'June' ) ) );
	}

	public function test_a_failed_copy_is_an_error_never_zeros(): void {
		$this->ledger_rows();
		zdz_test_ldg_as( 'owner' );
		ZANA_Ledger_Figures::$lead_rows = function () { throw new RuntimeException( 'db' ); };
		$r = ZANA_Home_Figures::from_capability( array( 'user_id' => 7, 'month' => '2026-06' ) );
		$this->assertTrue( $r['success'] );
		$this->assertArrayHasKey( 'leads', $r['errors'] );
		$this->assertArrayNotHasKey( 'leads_new', $r['company'] );
		ZANA_Ledger_Figures::$rows = function () { throw new RuntimeException( 'db' ); };
		ZANA_Home_Figures::$coverage['notes']['ready'] = false;
		$r = ZANA_Home_Figures::from_capability( array( 'user_id' => 7, 'month' => '2026-06' ) );
		$this->assertFalse( $r['success'], 'every copy failed' );
	}

	public function test_an_app_declares_its_own_note_opening(): void {
		$p = array( 'contacts' => '/^' . preg_quote( ZDZ_Contacts::note_opening(), '/' ) . '/iu' );
		$this->assertSame( 'app:contacts', ZANA_Home_Figures::classify( '2026-06-01 10:00:00', array( 'author' => 'Jordan', 'created_at' => '2026-06-02 10:00:00', 'body' => ZDZ_Contacts::note_opening() . 'Texted' ), $p, 10 ) );
		$GLOBALS['zdz_test_filters']['zana_crm_app_note_patterns'] = function () { return array( 'example' => '/^\[Example app\] /', 'bad' => '/(unclosed' ); };
		$all = ZANA_Home_Figures::app_note_patterns();
		$this->assertArrayHasKey( 'example', $all );
		$this->assertArrayNotHasKey( 'bad', $all, 'a pattern that does not compile is skipped' );
		$this->assertSame( 'intake', ZANA_Home_Figures::classify( '2026-06-01 10:00:00', array( 'author' => 'Jordan', 'created_at' => '2026-06-01 10:05:00', 'body' => 'x' ), array(), 10 ) );
		$this->assertSame( 'intake', ZANA_Home_Figures::classify( '2026-06-01 10:00:00', array( 'author' => 'Jordan', 'created_at' => '', 'body' => 'x' ), array(), 10 ), 'an undated note is never a follow-up' );
		$this->assertSame( 'no_author', ZANA_Home_Figures::classify( '2026-06-01 10:00:00', array( 'author' => '', 'created_at' => '2026-06-03 10:00:00', 'body' => 'x' ), array(), 10 ) );
		$this->assertSame( 'contact', ZANA_Home_Figures::classify( '2026-06-01 10:00:00', array( 'author' => '', 'created_at' => '2026-06-01 10:02:00', 'body' => 'Contacted by phone on June 1, 2026 at 10:02 AM by a salesperson' ), $p, 10 ), 'an app\'s record of a contact counts, even inside the intake window' );
		$GLOBALS['zdz_test_filters']['zana_crm_contact_note_patterns'] = function ( $p ) { return $p + array( 'example' => '/^Reached by Example app/' ); };
		$this->assertSame( 'contact', ZANA_Home_Figures::classify( '2026-06-01 10:00:00', array( 'author' => 'Jordan', 'created_at' => '2026-06-04 10:00:00', 'body' => 'Reached by Example app' ), $p, 10 ) );
		unset( $GLOBALS['zdz_test_filters']['zana_crm_contact_note_patterns'], $GLOBALS['zdz_test_filters']['zana_crm_app_note_patterns'] );
	}

	/* ─────────────────────────── the ask bar ─────────────────────────── */

	public function test_quick_answers_route_to_the_ledger_lead_sources_and_billing_gaps(): void {
		$this->ledger_rows();
		zdz_test_ldg_as( 'owner' );
		ZANA_Home_Figures::$coverage = null;
		ZANA_Ledger::$store->rows['invoices'] = array();
		$state = ZANA_Ledger::state();
		foreach ( array( 'invoices', 'estimates' ) as $k ) {
			$state[ $k ]['done']    = true;
			$state[ $k ]['last_ok'] = gmdate( 'Y-m-d H:i:s', time() - 60 );
		}
		update_option( ZANA_Ledger::STATE_OPTION, $state );

		$a = ZANA_Quick_Answers::invoke( array( 'question' => 'how much did we invoice last month', 'user_id' => 7 ) );
		$this->assertTrue( $a['answered'] );
		$this->assertSame( 'ledger', $a['kind'] );
		$this->assertStringContainsString( 'May 2026', $a['markdown'] );

		$a = ZANA_Quick_Answers::invoke( array( 'question' => 'sales in Riverton this month', 'user_id' => 7 ) );
		$this->assertSame( 'ledger', $a['kind'] );
		$this->assertStringContainsString( '$1,000.00', $a['markdown'], 'Riverton: invoice #1 only' );

		$a = ZANA_Quick_Answers::invoke( array( 'question' => 'how many example channel leads this year', 'user_id' => 7 ) );
		$this->assertSame( 'lead_sources', $a['kind'] );
		$this->assertStringContainsString( '2 leads', $a['markdown'], 'the paid one and the exact label; the plain one is aside' );

		ZANA_Billing_Gaps::$estimates     = function () { return array( array( 'customer_id' => 16, 'customer_name' => 'Morgan Hill', 'estimate_number' => '22', 'est_date' => '2026-06-04', 'amount' => 300.0, 'vis_state' => 0, 'rep_code' => 'CD' ) ); };
		ZANA_Billing_Gaps::$customer_docs = function () { return array( 'invoices' => array(), 'linked' => array() ); };
		$a = ZANA_Quick_Answers::invoke( array( 'question' => 'which accepted estimates were never invoiced this year', 'user_id' => 7 ) );
		$this->assertSame( 'billing_gaps', $a['kind'] );
		$this->assertStringContainsString( 'Morgan Hill', $a['markdown'] );
		$this->assertStringContainsString( 'No invoice to the customer since', $a['markdown'] );

		$this->assertFalse( ZANA_Quick_Answers::invoke( array( 'question' => 'email Alex about the invoice', 'user_id' => 7 ) )['answered'] );
		$this->assertFalse( ZANA_Quick_Answers::invoke( array( 'question' => 'which invoices are overdue', 'user_id' => 7 ) )['answered'], 'a list is the chat\'s' );
		$this->assertSame( 'kiosk', ZANA_Quick_Answers::invoke( array( 'question' => 'sales this month', 'user_id' => 7, 'is_kiosk' => true ) )['reason'] );
		$this->assertFalse( ZANA_Quick_Answers::invoke( array( 'question' => 'sales this month ZDZ-DATA-START', 'user_id' => 7 ) )['answered'] );
	}

	public function test_a_viewer_gets_no_ledger_answer_but_an_added_route_may_answer(): void {
		$this->ledger_rows();
		zdz_test_ldg_as( 'tech' );
		$this->assertFalse( ZANA_Quick_Answers::invoke( array( 'question' => 'how much did we invoice last month', 'user_id' => 7 ) )['answered'] );
		$GLOBALS['zdz_test_filters']['zana_quick_routes'] = function () {
			return array( function ( $q ) { return false !== strpos( $q, 'my hours' ) ? array( 'hours', '**12 hours** this week <b>' ) : null; } );
		};
		$a = ZANA_Quick_Answers::invoke( array( 'question' => 'my hours this week', 'user_id' => 7 ) );
		$this->assertSame( 'hours', $a['kind'] );
		$this->assertStringContainsString( '&lt;b&gt;', $a['html'], 'every character of an answer is escaped' );
		$this->assertStringContainsString( '<strong>12 hours</strong>', $a['html'] );
	}

	public function test_the_html_subset_escapes_and_draws_tables(): void {
		$html = ZANA_Quick_Answers::html( "**Total** <script>x</script>\n\n| A | B |\n|---|---:|\n| one | 2 |\n\n- item _x_" );
		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringContainsString( '&lt;script&gt;', $html );
		$this->assertStringContainsString( '<th>A</th><th class="num">B</th>', $html );
		$this->assertStringContainsString( '<li>item <em>x</em></li>', $html );
		$this->assertSame( "a\nb\n\n…", ZANA_Quick_Answers::first_lines( "a\nb\nc\nd", 2 ) );
	}

	/* ─────────────────────────── the ledger's reader ─────────────────────────── */

	public function test_the_reader_answers_only_what_it_reads(): void {
		$f = ZANA_Ledger_Answers::detect( 'how much did Alex sell last month' );
		$this->assertSame( array( 'AB' ), $f['reps'], 'a name a person who holds a code goes by' );
		$this->assertSame( 'sales', $f['family'] );
		$this->assertSame( array( '2026-05-01', '2026-05-31', 'May 2026' ), $f['period'] );
		$f = ZANA_Ledger_Answers::detect( 'northwind sales this year by month' );
		$this->assertSame( 'Northwind', $f['brand'] );
		$this->assertSame( 'month', $f['group'] );
		$this->assertSame( 'Gates', ZANA_Ledger_Answers::detect( 'gate sales this year' )['category'] );
		$this->assertSame( 'owed', ZANA_Ledger_Answers::detect( 'how much is overdue' )['family'] );
		$this->assertSame( 'paid', ZANA_Ledger_Answers::detect( 'how long do customers take to pay' )['family'] );
		$this->assertNull( ZANA_Ledger_Answers::detect( 'how fast do we pay our suppliers' ), 'we are the payer: not this' );
		$this->assertNull( ZANA_Ledger_Answers::detect( 'sales in Springfield this year' ), 'a town the ledger does not hold is a word it does not read' );
		$this->assertNull( ZANA_Ledger_Answers::detect( 'sales by rep by month' ), 'two groupings' );
		$this->assertNull( ZANA_Ledger_Answers::detect( 'what is our profit this year' ) );
		$this->assertNull( ZANA_Ledger_Answers::detect( 'sales before june 2' ), 'a date it cannot place' );
		$this->assertSame( 'estimates', ZANA_Ledger_Answers::detect( 'how many estimates did we send in 2025' )['family'] );
		$this->assertSame( 'year', ZANA_Ledger_Answers::detect( 'sales this year vs last year' )['compare']['kind'] );
		$this->assertTrue( ZANA_Ledger_Answers::detect( 'which invoices are overdue' )['list']['overdue'] );
	}

	public function test_sales_by_salesperson_split_a_shared_job_and_resolve_my_codes(): void {
		$this->ledger_rows();
		$s = ZANA_Ledger_Figures::sales( ZANA_Ledger_Figures::filters( array( 'group' => 'rep' ) ), array( '2026-01-01', '2026-06-30', '' ) );
		$this->assertSame( 1200.0, $s['groups']['Alex Example']['value'], '1000 + half of the shared 400' );
		$this->assertSame( 700.0, $s['groups']['Casey Doe']['value'], '500 + half of the shared 400' );
		zdz_test_ldg_as( 'owner' );
		ZANA_Ledger_Access::$context = function () { return array_merge( zdz_test_ldg_ctx( 'owner' ), array( 'codes' => array( 'CD' ) ) ); };
		$f = ZANA_Ledger_Answers::resolve( 'my sales this year', 7 );
		$this->assertSame( array( 'CD' ), $f['reps'] );
		zdz_test_ldg_as( 'sales' );
		$this->assertNull( ZANA_Ledger_Answers::resolve( 'my sales this year', 7 ), 'company figures need company rights' );
		zdz_test_ldg_as( 'pv_owner' );
		$this->assertNotNull( ZANA_Ledger_Answers::resolve( 'sales this year', 7 ) );
		$this->assertNull( ZANA_Ledger_Answers::resolve( 'which invoices are overdue', 7 ), 'a list names customers' );
		zdz_test_ldg_as( 'owner' );
		ZANA_Ledger_Answers::$relabelling = true;
		$this->assertNull( ZANA_Ledger_Answers::resolve( 'northwind sales this year', 7 ), 'brands wait while labels are re-derived' );
		$this->assertNotNull( ZANA_Ledger_Answers::resolve( 'sales this year', 7 ) );
	}

	public function test_money_owed_ages_by_due_date_and_leaves_archived_apart(): void {
		$this->ledger_rows();
		$o = ZANA_Ledger_Figures::owed( ZANA_Ledger_Figures::filters(), null );
		$this->assertSame( 1, $o['n'], 'the draft and the deleted invoice are left out; the archived one is apart' );
		$this->assertSame( 400.0, $o['owed'] );
		$this->assertSame( 1, $o['arch_n'] );
		$this->assertSame( 300.0, $o['arch_owed'] );
		$this->assertSame( 400.0, $o['over_owed'] );
		$this->assertSame( 1, $o['ages'][4]['n'], 'due 3 March: over 90 days late on 15 June' );
		$p = ZANA_Ledger_Figures::paid( ZANA_Ledger_Figures::filters(), array( '2026-06-01', '2026-06-30', '' ) );
		$this->assertSame( 1, $p['n'] );
		$this->assertSame( array( 3 ), $p['days'] );
		$c = ZANA_Ledger_Figures::customers( ZANA_Ledger_Figures::filters(), array( '2026-06-01', '2026-06-30', '' ) );
		$this->assertSame( array( 2, 1, 1 ), array( $c['customers'], $c['new'], $c['returning'] ), 'customer 11 was billed in February' );
	}

	/* ─────────────────────────── the chat's DATA LEDGER block ─────────────────────────── */

	public function test_the_chat_block_is_company_only_and_privacy_view_gets_no_names(): void {
		$this->ledger_rows();
		$state = ZANA_Ledger::state();
		$state['invoices']    = array_merge( $state['invoices'], array( 'done' => true, 'rows' => 6, 'loaded_from' => 2025, 'last_ok' => '2026-06-15 14:00:00' ) );
		$state['estimates']   = array_merge( $state['estimates'], array( 'done' => true, 'rows' => 4, 'loaded_from' => 2025, 'last_ok' => '2026-06-15 14:00:00' ) );
		$state['leads']       = array_merge( $state['leads'], array( 'done' => true, 'rows' => 5, 'last_ok' => '2026-06-15 14:00:00' ) );
		update_option( ZANA_Ledger::STATE_OPTION, $state );
		$this->assertSame( array( 'customers' ), ZANA_Ledger_Reports::wants( 'how many unique customers did we have each year' ) );
		$this->assertSame( array(), ZANA_Ledger_Reports::wants( 'email Robin about tomorrow' ) );
		zdz_test_ldg_as( 'owner' );
		$d = ZANA_Ledger_Reports::data_context( array( 'text' => '' ), 'who are our top customers', 7 );
		$this->assertStringContainsString( 'COVERAGE: invoices: complete history from 2025', $d['text'] );
		$this->assertStringContainsString( 'Example Co', $d['text'] );
		zdz_test_ldg_as( 'pv_owner' );
		$d = ZANA_Ledger_Reports::data_context( array( 'text' => '' ), 'who are our top customers', 7 );
		$this->assertStringContainsString( 'CUSTOMERS BY YEAR', $d['text'] );
		$this->assertStringNotContainsString( 'Example Co', $d['text'], 'no list that names customers' );
		zdz_test_ldg_as( 'sales' );
		$this->assertSame( array( 'text' => '' ), ZANA_Ledger_Reports::data_context( array( 'text' => '' ), 'who are our top customers', 7 ) );
		zdz_test_ldg_as( 'owner' );
		$d = ZANA_Ledger_Reports::data_context( array( 'text' => '' ), 'how much is still owed', 7 );
		$this->assertStringContainsString( '1 invoices, $400.00 still owed', $d['text'] );
		$this->assertStringContainsString( 'ARCHIVED invoices still show $300.00', $d['text'] );
	}

	/* ─────────────────────────── accepted, never invoiced ─────────────────────────── */

	public function test_billing_gap_detection(): void {
		$this->assertSame( 'accepted', ZANA_Billing_Gaps::detect( 'Which accepted estimates were never invoiced?' ) );
		$this->assertSame( 'accepted', ZANA_Billing_Gaps::detect( 'accepted quotes awaiting billing' ) );
		$this->assertSame( 'jobs', ZANA_Billing_Gaps::detect( 'which jobs are done but not invoiced' ) );
		$this->assertSame( 'deleted', ZANA_Billing_Gaps::detect( 'which completed jobs had their invoice deleted' ) );
		$this->assertSame( '', ZANA_Billing_Gaps::detect( 'please invoice the accepted estimates' ), 'an action, not this report' );
		$this->assertSame( '', ZANA_Billing_Gaps::detect( 'which jobs are not done yet' ) );
		$w = ZANA_Billing_Gaps::window_from( 'accepted estimates never invoiced since March 2026' );
		$this->assertSame( array( '2026-03-01', '2026-06-15' ), array( $w[0], $w[1] ) );
		$this->assertFalse( ZANA_Billing_Gaps::window_from( 'accepted estimates never invoiced' )[3] );
	}

	public function test_billing_gaps_split_by_what_happened_to_the_customer_since(): void {
		$rows = array(
			array( 'customer_id' => 1, 'customer_name' => 'Alex Example', 'estimate_number' => '31', 'est_date' => '2026-05-01', 'amount' => 1000.0, 'vis_state' => 0 ),
			array( 'customer_id' => 2, 'customer_name' => 'Sam Rivera', 'estimate_number' => '32', 'est_date' => '2026-05-02', 'amount' => 500.0, 'vis_state' => 2 ),
			array( 'customer_id' => 3, 'customer_name' => 'Robin Lee', 'estimate_number' => '33', 'est_date' => '2026-05-03', 'amount' => 800.0, 'vis_state' => 0 ),
		);
		$docs = array(
			'invoices' => array(
				1 => array( array( '2026-01-01', 200.0 ) ),                 // only before: no invoice since
				2 => array( array( '2026-05-20', 600.0 ) ),                 // invoiced afterwards for at least as much
				3 => array( array( '2026-05-25', 100.0 ) ),                 // invoiced afterwards, for less
			),
			'linked'   => array(),
		);
		$s = ZANA_Billing_Gaps::split( $rows, $docs, true );
		$this->assertSame( array( 'Alex Example' ), array_column( $s['open'], 'customer' ) );
		$this->assertSame( 1000.0, $s['open_total'] );
		$this->assertSame( array( 'Robin Lee' ), array_column( $s['short'], 'customer' ) );
		$this->assertSame( 700.0, $s['short'][0]['gap'] );
		$this->assertSame( array( 'Sam Rivera' ), array_column( $s['later'], 'customer' ) );
		$s = ZANA_Billing_Gaps::split( $rows, $docs, false );
		$this->assertSame( array(), $s['short'], 'a salesperson is never shown a customer\'s invoice totals' );
		$this->assertCount( 2, $s['later'] );
	}

	public function test_billing_gaps_are_refused_without_the_right_and_to_privacy_view(): void {
		zdz_test_ldg_as( 'tech' );
		$this->assertStringContainsString( 'shown to the company view', ZANA_Billing_Gaps::accepted_answer( 7, 'which accepted estimates were never invoiced' ) );
		zdz_test_ldg_as( 'pv_owner' );
		$this->assertStringContainsString( 'shown to the company view', ZANA_Billing_Gaps::accepted_answer( 7, 'which accepted estimates were never invoiced' ) );
	}

	/* ─────────────────────────── lead sources from the business's rules ─────────────────────────── */

	public function test_lead_source_groups_come_from_the_rules(): void {
		$this->assertSame( 'channel', ZANA_Lead_Sources::group_of( 'Example paid search' ), 'example + paid' );
		$this->assertSame( 'channel', ZANA_Lead_Sources::group_of( 'example channel' ), 'the exact label' );
		$this->assertSame( '', ZANA_Lead_Sources::group_of( 'Example' ), 'the plain one is aside, not in the group' );
		$this->assertSame( 'referral', ZANA_Lead_Sources::group_of( 'Referral from Alex' ) );
		$this->assertSame( 'none', ZANA_Lead_Sources::group_of( '' ), 'Core\'s own group for an empty source' );
		$this->assertSame( 'Example channel', ZANA_Lead_Sources::billing_label( 'Example channel' ) );
		$this->assertSame( 'Something else', ZANA_Lead_Sources::billing_label( 'Something else' ) );
		$f = ZANA_Lead_Sources::detect( 'how many referral leads this year' );
		$this->assertSame( array( 'referral' ), $f['groups'] );
		$this->assertNull( ZANA_Lead_Sources::detect( 'how many leads from the spring fair this year' ), 'a source nobody listed' );
		$this->assertNull( ZANA_Lead_Sources::detect( 'how many leads does Riley have' ), 'a person' );
		$this->assertNull( ZANA_Lead_Sources::detect( 'how many referral leads in Riverton' ), 'a word it does not read' );
		$this->assertTrue( ZANA_Lead_Sources::detect( 'leads by source last month' )['breakdown'] );
		ZANA_Ledger_Rules::$override = array();
		$this->assertSame( array( 'none' ), array_keys( ZANA_Lead_Sources::groups() ), 'with no pack, only Core\'s group' );
	}

	public function test_the_matching_terms_are_data_never_patterns(): void {
		$this->assertTrue( ZANA_Ledger_Rules::matches( 'Example Paid', array( 'example + paid' ) ) );
		$this->assertFalse( ZANA_Ledger_Rules::matches( 'Examples Paid', array( 'example + paid' ) ), 'whole words' );
		$this->assertTrue( ZANA_Ledger_Rules::matches( '', array( '=' ) ) );
		$this->assertFalse( ZANA_Ledger_Rules::matches( 'x', array( '=' ) ) );
		$this->assertFalse( ZANA_Ledger_Rules::matches( 'example org', array( 'example' ), array( 'example org' ) ) );
		$clean = ZANA_Ledger_Rules::clean( array( 'lead_sources' => array( 'codes' => array( 'x/(.*)' => 'Bad', 'ok' => 'Fine' ) ), 'brands' => array( 'B' => array( '(.*)+' ) ) ) );
		$this->assertSame( array( 'OK' => 'Fine' ), $clean['lead_sources']['codes'] );
		$this->assertSame( array(), $clean['brands'], 'a pattern is never a brand word' );
	}

	public function test_an_identity_pack_sets_the_rules_and_revert_puts_them_back(): void {
		ZANA_Ledger_Rules::$override = null;
		ZANA_Ledger_Rules::flush();
		$GLOBALS['zdz_test_options'][ ZANA_Ledger_Rules::OPTION ] = array( 'intake_minutes' => 5, 'lead_sources' => array( 'codes' => array( 'OLD' => 'Old channel' ) ) );
		$files = array(
			'mappings' => array( 'lead_sources' => array(
				'codes'  => array( 'XY' => 'Example channel', 'x/(.*)' => 'Bad' ),
				'groups' => array( array( 'key' => 'channel', 'label' => 'Example channel', 'words' => array( 'example channel' ), 'crm' => array( 'example' ) ) ),
			) ),
			'catalog'  => array( 'ledger' => array( 'brands' => array( 'Northwind' => array( '(.*)+' ) ) ) ),
			'rules'    => array( 'ledger' => array( 'history_from_year' => 2020 ) ),
		);
		$c = ZANA_Ledger_Rules::pack_consumer( array() )['sales-ledger'];
		$this->assertSame( array( 'mappings', 'catalog', 'document-conventions', 'rules' ), $c['files'] );
		$this->assertContains( 'lead_sources.groups', $c['paths']['mappings'] );

		$rows = ZANA_Ledger_Rules::pack_preview( $files );
		$by   = array();
		foreach ( $rows as $r ) {
			$by[ $r['path'] ] = $r;
		}
		$this->assertSame( array( '1 item(s)', '1 item(s)' ), array( $by['lead_sources.codes']['from'], $by['lead_sources.codes']['to'] ), 'only the clean code counts' );
		$this->assertSame( 'refused', $by['ledger.brands']['kind'], 'a pattern is refused, not applied' );
		$this->assertSame( array( '0', '2020' ), array( $by['ledger.history_from_year']['from'], $by['ledger.history_from_year']['to'] ) );
		$this->assertSame( 'note', $by['lead_sources']['kind'], 'a change to derived columns says the history is re-derived' );

		$kept = ZANA_Ledger_Rules::pack_snapshot( $files, 'example' );
		$this->assertSame( array( 'sales ledger: 4 rule(s) set' ), ZANA_Ledger_Rules::pack_apply( $files, 'example' ) );
		$r = ZANA_Ledger_Rules::get();
		$this->assertSame( array( 'XY' => 'Example channel' ), $r['lead_sources']['codes'], 'a list the pack carries replaces the stored one' );
		$this->assertSame( 'channel', $r['source_groups'][0]['key'] );
		$this->assertSame( array( 2020, 5 ), array( $r['history_from_year'], $r['intake_minutes'] ), 'what the pack does not carry stays' );

		ZANA_Ledger_Rules::pack_restore( $kept );
		$r = ZANA_Ledger_Rules::get();
		$this->assertSame( array( 'OLD' => 'Old channel' ), $r['lead_sources']['codes'] );
		$this->assertSame( 0, $r['history_from_year'] );
		unset( $GLOBALS['zdz_test_options'][ ZANA_Ledger_Rules::OPTION ] );
		ZANA_Ledger_Rules::flush();
	}

	/* ─────────────────────────── the enricher ─────────────────────────── */

	public function test_the_reference_notes_and_location_line_derive_the_columns(): void {
		$rules = ZANA_Ledger_Rules::get();
		$r     = ZANA_Ledger_Enrich::parse_reference( '10001-EXC/GT/XY', $rules, array( 'AB' ) );
		$this->assertSame( array( '10001', 'Gate', 'XY', 'Example channel' ), array( $r['zip'], $r['product'], $r['source_code'], $r['source_label'] ) );
		$r = ZANA_Ledger_Enrich::parse_reference( '10001XY', $rules );
		$this->assertSame( array( '10001', 'XY' ), array( $r['zip'], $r['source_code'] ), 'a suffix code the rules allow' );
		$r = ZANA_Ledger_Enrich::parse_reference( '10001-AB', $rules, array( 'AB' ) );
		$this->assertSame( '', $r['source_code'], 'a salesperson code is never a source' );
		$doc = array(
			'po_number' => '10001',
			'notes'     => 'Saw the example flyer',
			'lines'     => array( array( 'name' => 'Location', 'description' => "Riverton - (AB & CD)\nsecond line" ), array( 'name' => 'Northwind gate', 'amount' => array( 'amount' => '250.00' ) ) ),
		);
		$e = ZANA_Ledger_Enrich::document( $doc, $rules, array( 'rep_codes' => array( 'AB', 'CD' ) ) );
		$this->assertSame( 'Flyers', $e['lead_source'], 'a note keyword the rules list' );
		$this->assertSame( 'AB/CD', $e['rep'], 'both codes of a shared job' );
		$this->assertSame( 'Riverton', $e['city'] );
		$this->assertFalse( $e['rep_inferred'], 'never inferred' );
		$e = ZANA_Ledger_Enrich::document( array( 'po_number' => '10001-RF', 'notes' => 'ExampleAd' ), $rules );
		$this->assertSame( 'Example channel', $e['lead_source'], 'a note tag wins over the reference code' );
		$e = ZANA_Ledger_Enrich::document( array( 'po_number' => '10001-RF', 'notes' => 'ExampleAd ExampleOrg' ), $rules );
		$this->assertSame( 'Referral', $e['lead_source'], 'an exception word cancels the tag' );
		$this->assertSame( array( 'Northwind' => 250.0 ), ZANA_Ledger_Enrich::brands( $doc['lines'], $rules ) );
		$this->assertSame( array( 'Other' => 250.0 ), ZANA_Ledger_Enrich::mix( $doc['lines'], $rules ), 'the location line is never a product' );
		$e = ZANA_Ledger_Enrich::document( array( 'po_number' => '10001', 'lines' => array() ), ZANA_Ledger_Rules::clean( array() ) );
		$this->assertSame( array( '', '' ), array( $e['lead_source'], $e['rep'] ), 'Core derives nothing a business did not say' );
	}

	public function test_grouping_labels_lose_codes_phones_and_notes(): void {
		$this->assertSame( 'Friend', ZANA_Ledger_Enrich::label( 'Friend.  Gate code # 4321', false ) );
		$this->assertSame( 'Neighbor', ZANA_Ledger_Enrich::label( 'Neighbor 555-010-0142', false ) );
		$this->assertSame( '', ZANA_Ledger_Enrich::label( 'Scheduled 6/10 for AB', false ) );
		$this->assertSame( 'Riverton', ZANA_Ledger_Enrich::label( 'Location - Riverton (AB) 6/4 install', true ) );
		$this->assertSame( '', ZANA_Ledger_Enrich::label( '1250 Example Way, Riverton', true ), 'a street address is not a town' );
		$this->assertSame( 'Riverton', ZANA_Ledger_Figures::city_label( 'riverton, XY 10001' ) );
	}

	/* ─────────────────────────── periods, shadow, state, doc ─────────────────────────── */

	public function test_periods_are_placed_exactly_or_refused(): void {
		$p = function ( $m ) { return ZANA_Ledger_Periods::period_in( $m ); };
		$this->assertSame( array( '2026-01-01', '2026-06-15', '2026 so far' ), $p( 'this year' ) );
		$this->assertSame( array( '2026-04-01', '2026-06-15', 'Q2 2026 so far' ), $p( 'q2 2026' ) );
		$this->assertSame( array( '2026-03-16', '2026-06-15', 'the last 3 months' ), $p( 'the last 3 months' ) );
		$this->assertSame( array( '2025-07-01', '2025-07-31', 'July 2025' ), $p( 'july' ), 'a month without a year is the latest one' );
		$this->assertNull( $p( 'sales' ) );
		$this->assertFalse( $p( 'next month' ) );
		$this->assertFalse( $p( '2024 and 2025' ) );
		$this->assertFalse( $p( 'the first half of 2026' ) );
		$this->assertFalse( $p( 'q4 2026' ), 'a period that has not started' );
		$w = ZANA_Ledger_Periods::month_window( '2026-02' );
		$this->assertSame( array( '2026-02-01', '2026-02-28', '2026-01-01' ), array( $w['from'], $w['to'], $w['year_from'] ) );
		$this->assertArrayHasKey( 'error', ZANA_Ledger_Periods::month_window( '2022-01' ), 'too far back' );
	}

	public function test_the_shadow_check(): void {
		$now = gmmktime( 15, 0, 0, 6, 15, 2026 );
		$cov = array( 'invoices' => array( 'complete' => true, 'last_ok' => '2026-06-15 14:00:00' ) );
		$win = array( 'date_min' => '2026-06-01', 'date_max' => '2026-06-30' );
		$live = array( array( 'invoiceid' => 1, 'create_date' => '2026-06-02', 'amount' => array( 'amount' => '100.00' ), 'updated' => '2026-06-02 10:00:00' ) );
		$row  = array( array( 'id' => 1, 'amount' => '100.00', 'd' => '2026-06-02', 'vis_state' => 0, 'u' => '2026-06-02 10:00:00' ) );
		$c    = function ( $l, $r, $w = null, $s = array(), $cv = null ) use ( $now, $cov, $win ) { return ZANA_Ledger_Shadow::compare( 'invoices', $w ?? $win, $l, $r, $cv ?? $cov, $s, $now ); };
		$this->assertSame( 'same', $c( $live, $row )['verdict'] );
		$this->assertSame( 'ledger lacks', $c( $live, array() )['why'] );
		$this->assertSame( 'amounts', $c( $live, array( array( 'amount' => '90.00' ) + $row[0] ) )['why'] );
		$this->assertSame( 'ledger extra', $c( array(), $row )['why'] );
		$recent = array( array( 'updated' => '2026-06-15 13:00:00' ) + $live[0] );
		$this->assertSame( 'ledger behind', $c( $recent, array() )['why'] );
		$this->assertSame( 'filtered', $c( $live, $row, $win + array( 'status' => 'paid' ) )['why'] );
		$this->assertSame( 'truncated', $c( $live, $row, null, array( 'truncated' => true ) )['why'] );
		$this->assertSame( 'not fresh', $c( $live, $row, null, array(), array( 'invoices' => array( 'complete' => true, 'last_ok' => '2026-06-14 01:00:00' ) ) )['why'] );
		$this->assertSame( 'shadow: ledger differs (invoices, amounts)', ZANA_Ledger_Shadow::label( 'invoices', array( 'verdict' => 'differs', 'why' => 'amounts' ) ) );
		$this->assertSame( array( 'verdict' => 'differs', 'why' => 'amounts' ), ZANA_Ledger_Shadow::turn_verdict( 'shadow: ledger same (invoices); shadow: ledger differs (estimates, amounts)' ) );
	}

	public function test_payment_states(): void {
		$s = function ( $d ) { return ZANA_Invoice_State::payment_state( $d, '2026-06-15' ); };
		$this->assertSame( 'paid', $s( array( 'amount' => 100, 'outstanding' => 0 ) ) );
		$this->assertSame( 'partial', $s( array( 'amount' => 100, 'paid' => 40, 'outstanding' => 60 ) ) );
		$this->assertSame( 'overdue', $s( array( 'amount' => 100, 'outstanding' => 100, 'due_date' => '2026-06-01' ) ) );
		$this->assertSame( 'outstanding', $s( array( 'amount' => 100, 'outstanding' => 100, 'due_date' => '2026-07-01' ) ) );
		$this->assertSame( 'draft', $s( array( 'v3_status' => 'draft' ) ) );
		$this->assertTrue( ZANA_Invoice_State::matches( array( 'amount' => 100, 'outstanding' => 100, 'due_date' => '2026-06-01' ), 'unpaid', '2026-06-15' ) );
	}

	public function test_the_invoice_view_gate(): void {
		ZANA_Invoice_Doc::$ledger = array( 'invoice' => array( '1001' => array( array( 'id' => 1, 'rep' => 'AB' ) ), '1002' => array( array( 'id' => 2, 'rep' => 'CD' ) ) ) );
		$this->assertSame( 'all', ZANA_Invoice_Doc::may_open( zdz_test_ldg_ctx( 'owner' ), 'invoice', '1002' )['scope'] );
		$own = ZANA_Invoice_Doc::may_open( zdz_test_ldg_ctx( 'sales' ), 'invoice', '1001' );
		$this->assertTrue( $own['ok'] );
		$this->assertFalse( $own['money'], 'no amounts without company rights' );
		$this->assertFalse( ZANA_Invoice_Doc::may_open( zdz_test_ldg_ctx( 'sales' ), 'invoice', '1002' )['ok'], 'someone else\'s' );
		$this->assertFalse( ZANA_Invoice_Doc::may_open( zdz_test_ldg_ctx( 'pv_owner' ), 'invoice', '1001' )['ok'] );
		$this->assertFalse( ZANA_Invoice_Doc::may_open( zdz_test_ldg_ctx( 'kiosk' ), 'invoice', '1001' )['ok'] );
		$doc = array( 'invoiceid' => 2, 'lines' => array( array( 'name' => 'Location', 'description' => 'Riverton - (CD)' ) ) );
		$this->assertFalse( ZANA_Invoice_Doc::still_theirs( $doc, 'invoice', array( 'id' => 1, 'codes' => array( 'AB' ) ) ), 'not the document the ledger approved' );
		$shape = ZANA_Invoice_Doc::shape( array( 'invoice_number' => '1001', 'amount' => array( 'amount' => '100.00' ), 'lines' => array( array( 'name' => 'Gate', 'amount' => array( 'amount' => '100.00' ), 'unit_cost' => array( 'amount' => '100.00' ) ) ) ), 'invoice', false );
		$this->assertNull( $shape['totals'] );
		$this->assertNull( $shape['lines'][0]['amount'] );
		$this->assertTrue( ZANA_Invoice_Doc::is_draft( array() ), 'no status at all: treated as a draft' );
		$this->assertSame( '', ZANA_Invoice_Doc::share_link_from( array( 'share_link' => 'https://evil.example.com/#/link/abc' ) ) );
		ZANA_Invoice_Doc::$breadth = function () { return 'none'; };
		$this->assertFalse( ZANA_Invoice_Doc::may_open( zdz_test_ldg_ctx( 'owner' ), 'invoice', '1002' )['ok'], 'the Charts setting narrows it' );
		ZANA_Invoice_Doc::$breadth = function () { return 'own'; };
		$this->assertSame( 'own', ZANA_Invoice_Doc::may_open( array_merge( zdz_test_ldg_ctx( 'owner' ), array( 'codes' => array( 'CD' ) ) ), 'invoice', '1002' )['scope'] );
	}
}
