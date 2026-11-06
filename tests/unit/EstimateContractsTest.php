<?php
/**
 * Estimates: what other apps are promised.
 *
 *   - the agreements app: the request built at Approve passes that app's own check for a job in a state whose pack caps
 *     the down payment (deposit at the cap, whole dollars), with the language answer, the buyer's state, the
 *     salesperson's registration, the lead-time groups and the staff's disaster-repair answer; the schedule's deposit is
 *     never over the pack's cap;
 *   - the messaging app: zdz_fb_document_for_viewer hands a document only to someone who may open that estimate, never
 *     to the shared device or a stranger; an invoice to the admin tier, or to the salesperson whose own it is (made from
 *     their estimate, or its location line names exactly them), nobody else; amounts only for a person who may see
 *     prices; codes cut from every word;
 *   - the jobs app: an acceptance carries the salespeople it names and says when it is old news.
 *
 * Neutral data only.
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/support/agreements-stubs.php';
require_once __DIR__ . '/support/estimate-stubs.php';
require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-doc-conventions.php';
require_once dirname( __DIR__, 2 ) . '/zorderz-apps/apps/estimate/includes/class-zest-doc-reader.php';

final class EstimateContractsTest extends TestCase {

	const ALEX   = 51; // sales, AA
	const JORDAN = 52; // sales, JJ
	const RILEY  = 54; // the shared device
	const CASEY  = 55; // owner

	protected function setUp(): void {
		$GLOBALS['zdz_test_options']      = array(
			ZAGR_Settings::OPT_LEAD => array( array( 'id' => 'widgets', 'label' => 'Widgets', 'min' => 5, 'max' => 10, 'install' => 2 ) ),
		);
		$GLOBALS['zdz_test_transients']   = array();
		$GLOBALS['zdz_test_filters']      = array(
			'zagr_agreement_pack'           => static function () { return 'us-federal'; },
			'zest_salesperson_registration' => static function ( $r, $uid ) { return self::ALEX === (int) $uid ? 'REG-0001' : ''; },
		);
		$GLOBALS['zdz_test_user_roles']   = array( self::ALEX => array( 'zdz_sales' ), self::JORDAN => array( 'zdz_sales' ), self::RILEY => array( 'zdz_general' ), self::CASEY => array( 'zdz_owner' ) );
		$GLOBALS['zdz_test_user_meta']    = array( self::ALEX => array( 'zdz_user_initials' => 'AA' ), self::JORDAN => array( 'zdz_user_initials' => 'JJ' ) );
		$GLOBALS['zdz_test_users']        = array();
		foreach ( $GLOBALS['zdz_test_user_roles'] as $id => $r ) {
			$GLOBALS['zdz_test_users'][] = (object) array( 'ID' => $id, 'roles' => $r, 'display_name' => 'Person ' . $id );
		}
		$GLOBALS['zdz_test_current_user'] = self::ALEX;
		$GLOBALS['wpdb']                  = new ZDZ_Test_Est_DB();
		ZAGR_Pack::forget();
		ZEST_Deposit_Limit::reset();
		ZEST_Salesperson::flush();
		ZEST_Payment_Plan::set_config( array(
			'groups'  => array( array( 'id' => 'widgets', 'label' => 'Widgets', 'names' => array( 'widget' ) ) ),
			'deposit' => array( 'at_limit' => true ),
		) );
		ZEST_Billing_Doc::$account   = 'acct1';
		ZEST_Billing_Doc::$transport = null;
	}

	protected function tearDown(): void {
		ZAGR_Pack::forget();
		ZEST_Deposit_Limit::reset();
		ZEST_Salesperson::flush();
		ZEST_Payment_Plan::set_config( null );
		ZEST_Billing_Doc::$account   = null;
		ZEST_Billing_Doc::$transport = null;
		unset( $GLOBALS['zdz_test_options'], $GLOBALS['zdz_test_transients'], $GLOBALS['zdz_test_filters'], $GLOBALS['zdz_test_user_roles'],
			$GLOBALS['zdz_test_user_meta'], $GLOBALS['zdz_test_users'], $GLOBALS['zdz_test_current_user'], $GLOBALS['wpdb'] );
	}

	private static function row( array $over = array() ): array {
		return $over + array(
			'id' => 7, 'created_by' => self::ALEX, 'salesperson' => 'AA', 'notes' => '', 'billing_doc_num' => '0001042', 'billing_doc_id' => '555',
			'billing_cust_id' => '901', 'customer_name' => 'Morgan Example', 'customer_email' => 'morgan@example.com',
			'customer_street' => '100 Example Way', 'customer_city' => 'Exampleton', 'customer_state' => 'CA', 'customer_zip' => '00000',
			'customer_phone' => '555-555-0100', 'items_json' => '[]', 'seal_hold' => '', 'created_at' => '2026-01-02 10:00:00',
		);
	}

	private static function doc( array $over = array() ): array {
		return $over + array(
			'id' => '555', 'estimate_number' => '0001042', 'create_date' => '2026-01-02', 'notes' => 'Gate code 4821', 'terms' => '',
			'fname' => 'Morgan', 'lname' => 'Example', 'organization' => '', 'street' => '100 Example Way', 'city' => 'Exampleton', 'province' => 'CA', 'code' => '00000',
			'amount' => array( 'amount' => '1500.00' ),
			'lines' => array(
				array( 'type' => 0, 'name' => 'Example widget', 'description' => 'Plain, two', 'qty' => '2', 'unit_cost' => array( 'amount' => '750.00' ) ),
			),
		);
	}

	/* ── the agreements app ──────────────────────────────────────────── */

	public function test_the_approve_request_passes_the_agreements_apps_own_check(): void {
		ZEST_Deposit_Limit::set( ZEST_Deposit_Limit::for_region( 'CA' ) );
		$plan = ZEST_Payment_Plan::build( self::doc() );
		$this->assertTrue( $plan['ok'], implode( '; ', $plan['why'] ) );
		$cap = ZAGR_Pack::deposit_cap( ZAGR_Pack::stack_for( 'CA' ), $plan['total_cents'], $plan['tax_cents'] );
		$this->assertSame( $cap, $plan['deposit_cents'], 'the deposit sits at the pack\'s cap, read from the pack' );
		$this->assertSame( 0, $plan['deposit_cents'] % 100 );

		$req = ZEST_Terms_Note::request( self::row(), self::doc(), $plan, true, array( 'disaster_repair' => false ) );
		$this->assertSame( 'CA', $req['buyer']['region'] );
		$this->assertSame( 'REG-0001', $req['salesperson']['registration'] );
		$this->assertSame( array( array( 'family' => 'widgets', 'units' => 2 ) ), $req['families'] );
		$this->assertTrue( $req['language_ok'] );
		$this->assertFalse( $req['disaster_repair'] );
		$this->assertSame( 901, $req['client_id'] );
		$chk = ZAGR_Document::check( $req );
		$this->assertTrue( $chk['ok'], (string) ( $chk['message'] ?? '' ) );

		$null = ZEST_Terms_Note::request( self::row(), self::doc(), $plan, null, array( 'disaster_repair' => null ) );
		$this->assertArrayHasKey( 'disaster_repair', $null, 'not answered is sent as not answered, never assumed' );
		$this->assertNull( $null['disaster_repair'] );
		$this->assertNull( $null['language_ok'] );
	}

	public function test_the_staff_questions_come_from_the_packs(): void {
		$this->assertSame( array( 'disaster' => 'disaster_repair' ), ZEST_Terms_Note::staff_questions( 'CA' ) );
		$this->assertSame( array(), ZEST_Terms_Note::staff_questions( 'TX' ) );
	}

	public function test_a_deposit_over_the_cap_is_never_offered(): void {
		ZEST_Deposit_Limit::set( ZEST_Deposit_Limit::for_region( 'CA' ) );
		ZEST_Payment_Plan::set_config( array(
			'groups'  => array( array( 'id' => 'widgets', 'names' => array( 'widget' ) ) ),
			'deposit' => array( 'percent' => 50 ),
		) );
		foreach ( array( 50000, 500000, 2500000 ) as $pretax ) {
			$cap = ZAGR_Pack::deposit_cap( ZAGR_Pack::stack_for( 'CA' ), $pretax, 0 );
			$this->assertLessThanOrEqual( $cap, ZEST_Payment_Plan::deposit_for( $pretax ), (string) $pretax );
		}
	}

	public function test_the_agreements_app_is_told_who_sold_it(): void {
		$GLOBALS['wpdb']->rows[7] = self::row();
		$this->assertSame( array( self::ALEX ), ZEST_Salesperson::agreement_salespeople( array(), 7 ) );
	}

	/* ── the messaging app ───────────────────────────────────────────── */

	private function billing_answers(): void {
		$doc = self::doc();
		ZEST_Billing_Doc::$transport = static function ( $method, $path, $body ) use ( $doc ) {
			return false !== strpos( $path, '/estimates/estimates/555' ) ? array( 'response' => array( 'result' => array( 'estimate' => $doc ) ) ) : null;
		};
	}

	public function test_a_document_goes_only_to_someone_who_may_open_that_estimate(): void {
		$this->billing_answers();
		$GLOBALS['wpdb']->rows[7] = self::row();

		$mine = ZEST_Doc_Reader::for_viewer( null, '#1042', array( 'as_user' => self::ALEX ) );
		$this->assertTrue( $mine['ok'] );
		$this->assertSame( 'estimate', $mine['kind'] );
		$this->assertStringNotContainsString( '4821', json_encode( $mine ), 'codes cut from every word' );
		$this->assertNull( $mine['doc']['totals'], 'amounts only for a person who may see prices' );
		$this->assertFalse( $mine['doc']['money'] );

		$this->assertSame( 'not_yours', ZEST_Doc_Reader::for_viewer( null, '1042', array( 'as_user' => self::JORDAN ) )['why'] );
		$this->assertSame( 'shared_device', ZEST_Doc_Reader::for_viewer( null, '1042', array( 'as_user' => self::RILEY ) )['why'] );
		$this->assertSame( 'no_access', ZEST_Doc_Reader::for_viewer( null, '1042', array() )['why'], 'no rights-holder named: nothing' );
		$this->assertSame( 'no_access', ZEST_Doc_Reader::for_viewer( null, '1042', array( 'as_user' => 999 ) )['why'] );
		$this->assertSame( 'bad_number', ZEST_Doc_Reader::for_viewer( null, 'abc', array( 'as_user' => self::ALEX ) )['why'] );
		$this->assertSame( 'not_found', ZEST_Doc_Reader::for_viewer( null, '7777', array( 'as_user' => self::ALEX, 'kind' => 'estimate' ) )['why'] );
		$this->assertTrue( ZEST_Doc_Reader::for_viewer( null, '1042', array( 'as_user' => self::CASEY ) )['ok'], 'the admin tier' );

		$other = array( 'ok' => true, 'kind' => 'estimate', 'doc' => array(), 'also' => '' );
		$this->assertSame( $other, ZEST_Doc_Reader::for_viewer( $other, '1042', array( 'as_user' => self::JORDAN ) ), 'another provider answered first' );
	}

	/** The billing system answers an invoice search for 2001 (made from estimate 555) and 2002 (no estimate, JJ's line). */
	private function invoice_answers(): void {
		$inv = array(
			'2001' => array( 'id' => '9001', 'invoice_number' => '2001', 'estimateid' => '555', 'notes' => '', 'fname' => 'Morgan', 'lname' => 'Example',
				'lines' => array( array( 'name' => 'Example widget', 'description' => 'Plain', 'qty' => '1', 'unit_cost' => array( 'amount' => '750.00' ) ) ) ),
			'2002' => array( 'id' => '9002', 'invoice_number' => '2002', 'estimateid' => '0', 'notes' => '', 'fname' => 'Quinn', 'lname' => 'Example',
				'lines' => array(
					array( 'name' => 'Location', 'description' => 'Exampleton - (JJ)', 'qty' => '1', 'unit_cost' => array( 'amount' => '0' ) ),
					array( 'name' => 'Example widget', 'description' => 'Plain', 'qty' => '1', 'unit_cost' => array( 'amount' => '750.00' ) ),
				) ),
		);
		ZEST_Billing_Doc::$transport = static function ( $method, $path, $body ) use ( $inv ) {
			if ( preg_match( '/invoices\?.*search\[invoice_number\]=(\d+)/', $path, $m ) ) {
				return array( 'response' => array( 'result' => array( 'invoices' => isset( $inv[ $m[1] ] ) ? array( $inv[ $m[1] ] ) : array() ) ) );
			}
			return null;
		};
	}

	public function test_an_invoice_goes_to_the_admin_tier_or_the_salesperson_whose_own_it_is(): void {
		$GLOBALS['wpdb']->rows[7] = self::row();
		$this->invoice_answers();
		$own = ZEST_Doc_Reader::for_viewer( null, '2001', array( 'as_user' => self::ALEX, 'kind' => 'invoice' ) );
		$this->assertTrue( $own['ok'], 'made from an estimate that is theirs' );
		$this->assertSame( 'invoice', $own['kind'] );
		$this->assertSame( 'not_yours', ZEST_Doc_Reader::for_viewer( null, '2001', array( 'as_user' => self::JORDAN, 'kind' => 'invoice' ) )['why'] );
		$this->assertTrue( ZEST_Doc_Reader::for_viewer( null, '2001', array( 'as_user' => self::CASEY, 'kind' => 'invoice' ) )['ok'], 'the admin tier' );
		$this->assertSame( 'shared_device', ZEST_Doc_Reader::for_viewer( null, '2001', array( 'as_user' => self::RILEY, 'kind' => 'invoice' ) )['why'] );
		$this->assertSame( 'not_found', ZEST_Doc_Reader::for_viewer( null, '2003', array( 'as_user' => self::ALEX, 'kind' => 'invoice' ) )['why'] );
		$this->assertTrue( ZEST_Doc_Reader::for_viewer( null, '2002', array( 'as_user' => self::JORDAN, 'kind' => 'invoice' ) )['ok'], 'its location line names exactly them' );
		$this->assertSame( 'not_yours', ZEST_Doc_Reader::for_viewer( null, '2002', array( 'as_user' => self::ALEX, 'kind' => 'invoice' ) )['why'] );
	}

	public function test_an_invoice_is_never_handed_over_when_the_billing_system_cannot_be_read(): void {
		$this->assertSame( 'unavailable', ZEST_Doc_Reader::for_viewer( null, '2001', array( 'as_user' => self::ALEX, 'kind' => 'invoice' ) )['why'] );
	}

	/* ── the jobs app ────────────────────────────────────────────────── */

	public function test_an_acceptance_names_its_salespeople_and_says_when_it_is_old_news(): void {
		$routed = self::row( array( 'created_by' => 0, 'salesperson' => 'JJ' ) );
		$d      = ZEST_Accept_Watch::detail( $routed, 'watch', 0, false, false );
		$this->assertSame( array( self::JORDAN ), $d['salesperson_uids'] );
		$this->assertSame( 'accepted', $d['billing_state'] );
		$this->assertSame( 'unknown', $d['who'], 'never claims the customer accepted' );
		$this->assertFalse( $d['quiet'] );
		$old = ZEST_Accept_Watch::detail( $routed, 'watch', 0, true, false );
		$this->assertTrue( $old['backlog'] && $old['quiet'] );
		$by = ZEST_Accept_Watch::detail( self::row(), 'manual', self::ALEX, false, false );
		$this->assertSame( 'manual', $by['billing_state'] );
		$this->assertSame( 'staff', $by['who'] );
		$this->assertFalse( ZEST_Accept_Watch::stamp( self::row(), 'manual', self::ALEX ), 'already accepted (no row changed): no second event' );
	}
}
