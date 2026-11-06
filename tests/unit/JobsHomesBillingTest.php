<?php
/**
 * Homes (one card per client and street) and the billing history of a Project, with neutral data:
 *
 *   - client_of(): the billing connector's client first, else the CRM contact on the jobs; never a name;
 *   - home_key(): the same client and the same street (however it is written) is one home; no client or no street
 *     stays on its own; group() rides the other estimates on the newest card;
 *   - kind_of() / when(): what a log entry says happened and when (a settings change is nothing);
 *   - shape() / summary() / money_state(): deleted payments are never paid, a draft is not an invoice, an answer with
 *     no log is "not known"; My next's money steps come from the kept history through zjob_project_money.
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

$zjob_homes_inc = dirname( __DIR__, 2 ) . '/zorderz-apps/apps/jobs/includes/';
require_once $zjob_homes_inc . 'class-zjob-repair-log.php';
require_once $zjob_homes_inc . 'class-zjob-project-homes.php';

class JobsHomesBillingTest extends TestCase {

	protected function tearDown(): void {
		unset( $GLOBALS['zdz_test_filters']['zjob_project_client'], $GLOBALS['zdz_test_filters']['zjob_project_invoice_history'] );
	}

	public function test_the_client_is_a_fact_never_a_name() {
		$this->assertSame( 'crm:41', ZJOB_Project_Homes::client_of( 'P1', array( array( 'crm_contact_id' => 0, 'customer_name' => 'Alex Example' ), array( 'crm_contact_id' => 41 ) ) ) );
		$this->assertSame( '', ZJOB_Project_Homes::client_of( 'P1', array( array( 'customer_name' => 'Alex Example' ) ) ) );
		$GLOBALS['zdz_test_filters']['zjob_project_client'] = function ( $v, $pid ) { return 'P1' === $pid ? 'C-77' : $v; };
		$this->assertSame( 'bill:C-77', ZJOB_Project_Homes::client_of( 'P1', array( array( 'crm_contact_id' => 41 ) ) ) );
	}

	public function test_one_home_per_client_and_street() {
		$a = ZJOB_Project_Homes::home_key( 'crm:41', '12 Example Street, Springfield' );
		$this->assertNotSame( '', $a );
		$this->assertSame( $a, ZJOB_Project_Homes::home_key( 'crm:41', '12 example st., Springfield, AB 11111' ) );
		$this->assertNotSame( $a, ZJOB_Project_Homes::home_key( 'crm:41', '14 Example Street, Springfield' ) ); // another property
		$this->assertNotSame( $a, ZJOB_Project_Homes::home_key( 'crm:42', '12 Example Street, Springfield' ) ); // another client
		$this->assertSame( '', ZJOB_Project_Homes::home_key( '', '12 Example Street' ) );
		$this->assertSame( '', ZJOB_Project_Homes::home_key( 'crm:41', 'Springfield' ) );
		$g = ZJOB_Project_Homes::group( array(
			array( 'id' => 'P3', 'home' => 'h1', 'code' => 'PRJ-3', 'status' => 'open', 'mine' => false ),
			array( 'id' => 'P2', 'home' => '', 'code' => 'PRJ-2', 'status' => 'open' ),
			array( 'id' => 'P1', 'home' => 'h1', 'code' => 'PRJ-1', 'status' => 'completed', 'mine' => true ),
		) );
		$this->assertSame( array( 'P3', 'P2' ), array_column( $g, 'id' ) );
		$this->assertSame( 'P1', $g[0]['parts'][0]['id'] );
		$this->assertTrue( $g[0]['mine'] );
	}

	public function test_what_a_log_entry_says_and_when() {
		$this->assertSame( 'sent', ZJOB_Project_Homes::kind_of( array( 'event' => 'invoice_sent' ) ) );
		$this->assertSame( 'reminder', ZJOB_Project_Homes::kind_of( array( 'description' => 'Payment reminder emailed' ) ) );
		$this->assertSame( 'viewed', ZJOB_Project_Homes::kind_of( array( 'type' => 'invoice', 'message' => 'Client viewed the invoice' ) ) );
		$this->assertSame( '', ZJOB_Project_Homes::kind_of( array( 'event' => 'payment_settings_updated' ) ) );
		$this->assertSame( 'paid', ZJOB_Project_Homes::kind_of( array( 'action' => 'payment' ) ) );
		$this->assertSame( '2026-03-02 10:15:00', ZJOB_Project_Homes::when( array( 'created_at' => '2026-03-02T10:15' ) ) );
		$this->assertSame( '2026-03-02', ZJOB_Project_Homes::when( array( 'date' => '2026-03-02' ) ) );
		$this->assertSame( gmdate( 'Y-m-d H:i:s', 1772446500 ), ZJOB_Project_Homes::when( array( 'timestamp' => 1772446500000 ) ) );
		$this->assertSame( '', ZJOB_Project_Homes::when( array( 'by' => 'Alex' ) ) );
	}

	public function test_shape_keeps_what_and_when_and_never_a_deleted_payment() {
		$h = ZJOB_Project_Homes::shape( array(
			'invoice_number' => '1001', 'v3_status' => 'Partial', 'amount' => array( 'amount' => '500.00' ), 'outstanding' => '200', 'paid' => '300',
			'audit_logs' => array(
				array( 'event' => 'sent', 'created_at' => '2026-03-02 09:00:00', 'by' => 'Alex Example' ),
				array( 'event' => 'sent', 'created_at' => '2026-03-02 09:00:00' ),
				array( 'event' => 'viewed', 'created_at' => '2026-03-03 12:00:00' ),
				array( 'event' => 'settings_changed', 'created_at' => '2026-03-03 12:00:00' ),
			),
		), array( array( 'date' => '2026-03-05', 'amount' => '300' ), array( 'date' => '2026-03-06', 'amount' => '50', 'vis_state' => 1 ) ) );
		$this->assertSame( 'partial', $h['status'] );
		$this->assertSame( 500.0, $h['amount'] );
		$this->assertSame( 200.0, $h['due'] );
		$this->assertCount( 2, $h['events'] );
		$this->assertCount( 1, $h['payments'] );
		$this->assertTrue( $h['log'] );
		$this->assertStringNotContainsString( 'Alex', json_encode( $h ) );
		$s = ZJOB_Project_Homes::summary( $h );
		$this->assertSame( 1, $s['sent_count'] );
		$this->assertSame( '2026-03-03 12:00:00', $s['last_viewed'] );
		$this->assertSame( '2026-03-05', $s['last_payment'] );
		$this->assertFalse( ZJOB_Project_Homes::shape( array( 'status' => 'sent' ), null )['log'] );
	}

	public function test_money_state_and_my_next_reads_it() {
		$this->assertSame( array( 'invoiced' => false, 'due' => null ), ZJOB_Project_Homes::money_state( array( array( 'draft' => true, 'due' => 5.0 ) ) ) );
		$this->assertSame( array( 'invoiced' => true, 'due' => 12.5 ), ZJOB_Project_Homes::money_state( array( array( 'due' => 10.0 ), array( 'due' => 2.5 ) ) ) );
		$this->assertSame( array( 'invoiced' => true, 'due' => null ), ZJOB_Project_Homes::money_state( array( array( 'due' => null ) ) ) );
		$this->assertNull( ZJOB_Project_Homes::money_from_history( null, 'P1' ) ); // nobody keeps a history: no claim
		$GLOBALS['zdz_test_filters']['zjob_project_invoice_history'] = function ( $v, $pid ) {
			return array( array( 'invoice' => array( 'status' => 'sent', 'outstanding' => '40.00' ), 'payments' => array() ) );
		};
		$this->assertSame( array( 'invoiced' => true, 'due' => 40.0 ), ZJOB_Project_Homes::money_from_history( null, 'P1' ) );
		$this->assertSame( array( 'invoiced' => false ), ZJOB_Project_Homes::money_from_history( array( 'invoiced' => false ), 'P1' ) );
	}
}
