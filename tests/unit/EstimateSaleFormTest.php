<?php
/**
 * Estimates: the sale form.
 *
 *   - facts(): one item per priced line with its $0 parts listed under the priced line above it; the app's location
 *     line is never a part; a deposit charge or a credit for money already paid leaves the price and the payments for
 *     the pen; the price column adds up to the contract price.
 *   - check_print(): the signing day must be close to today; the downpayment is the form's own figure or the pen, never
 *     another figure; the sheet's hash must match the estimate as it is now; the age question is required only where
 *     the job's legal packs ask it.
 *
 * Neutral data only.
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/support/estimate-stubs.php';

final class EstimateSaleFormTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['zdz_test_options'] = array();
		$GLOBALS['zdz_test_filters'] = array();
		ZEST_Deposit_Limit::reset();
		ZEST_Deposit_Limit::set( ZEST_Deposit_Limit::fold( array( array( 'fixed_cents' => 100000, 'percent' => 10, 'whole_dollars' => true ) ) ) );
		ZEST_Payment_Plan::set_config( array( 'deposit' => array( 'at_limit' => true ) ) );
	}

	protected function tearDown(): void {
		ZEST_Deposit_Limit::reset();
		ZEST_Payment_Plan::set_config( null );
		unset( $GLOBALS['zdz_test_options'], $GLOBALS['zdz_test_filters'] );
	}

	private static function doc( array $extra_lines = array() ): array {
		return array(
			'id'              => '501',
			'estimate_number' => '1001',
			'fname'           => 'Riley',
			'lname'           => 'Example',
			'street'          => '12 Example St',
			'street2'         => '555-555-0100',
			'city'            => 'Exampleton',
			'province'        => 'ZZ',
			'code'            => '00000',
			'amount'          => '5000.00',
			'notes'           => '',
			'lines'           => array_merge( array(
				array( 'name' => 'Location', 'description' => 'Exampleton - (AA)', 'qty' => '1', 'unit_cost' => array( 'amount' => '0' ) ),
				array( 'name' => 'Widget, large', 'description' => 'White', 'qty' => '2', 'unit_cost' => array( 'amount' => '2400.00' ) ),
				array( 'name' => 'Mounting kit', 'description' => '', 'qty' => '2', 'unit_cost' => array( 'amount' => '0' ) ),
				array( 'name' => 'Delivery', 'description' => '', 'qty' => '1', 'unit_cost' => array( 'amount' => '200.00' ) ),
			), $extra_lines ),
		);
	}

	public function test_facts_list_items_with_their_parts_and_add_up(): void {
		$f = ZEST_Sale_Form::facts( self::doc(), array( 'email' => 'riley@example.com', 'phone' => '555-555-0100', 'salesperson' => 'Alex', 'signing' => '2026-01-05', 'down' => 50000 ) );
		$this->assertCount( 2, $f['items'] );
		$this->assertSame( 'Widget, large', $f['items'][0]['what'] );
		$this->assertStringContainsString( 'Includes: Mounting kit (2).', $f['items'][0]['notes'] );
		$this->assertSame( '5,000.00', $f['contract'] );
		$this->assertSame( '500.00', $f['down'] );
		$this->assertSame( '4,500.00', $f['final'] );
		$this->assertSame( 0, $f['adjust_cents'] );
		$this->assertSame( 'Riley Example', $f['prepared'] );
		$this->assertSame( '12 Example St', $f['street'], 'a second street line that is only a phone is left out' );
	}

	public function test_a_deposit_charge_leaves_the_price_for_the_pen(): void {
		$doc = self::doc( array( array( 'name' => 'Deposit', 'description' => '', 'qty' => '1', 'unit_cost' => array( 'amount' => '500.00' ) ) ) );
		$dp  = ZEST_Sale_Form::downpayment( $doc );
		$this->assertTrue( $dp['locked'] );
		$f = ZEST_Sale_Form::facts( $doc, array( 'down' => 50000, 'signing' => '2026-01-05' ) );
		$this->assertSame( '', $f['contract'] );
		$this->assertSame( '', $f['down'] );
	}

	public function test_the_form_figure_is_the_deposit_within_the_limit(): void {
		$dp = ZEST_Sale_Form::downpayment( self::doc() );
		$this->assertSame( 50000, $dp['cap'] );
		$this->assertSame( 500000, $dp['contract'] );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{16}$/', $dp['seen'] );
	}

	public function test_check_print_takes_only_the_form_figure_or_the_pen(): void {
		$dp    = ZEST_Sale_Form::downpayment( self::doc() );
		$today = '2026-01-05';
		$ok    = ZEST_Sale_Form::check_print( $dp, array( 'signing' => $today, 'down' => '500', 'seen' => $dp['seen'] ), $today, false );
		$this->assertTrue( $ok['ok'] );
		$this->assertSame( 50000, $ok['extra']['down'] );
		$pen = ZEST_Sale_Form::check_print( $dp, array( 'signing' => $today, 'down' => '', 'seen' => $dp['seen'] ), $today, false );
		$this->assertTrue( $pen['ok'] );
		$this->assertNull( $pen['extra']['down'] );
		$this->assertSame( 'down', ZEST_Sale_Form::check_print( $dp, array( 'signing' => $today, 'down' => '2500', 'seen' => $dp['seen'] ), $today, false )['code'], 'half the price is refused' );
		$this->assertSame( 'down', ZEST_Sale_Form::check_print( $dp, array( 'signing' => $today, 'down' => 'abc', 'seen' => $dp['seen'] ), $today, false )['code'] );
		$this->assertSame( 'down', ZEST_Sale_Form::check_print( $dp, array( 'signing' => $today, 'seen' => $dp['seen'] ), $today, false )['code'], 'the figure must be sent, even empty' );
	}

	public function test_check_print_refuses_a_changed_estimate_and_a_far_signing_day(): void {
		$dp    = ZEST_Sale_Form::downpayment( self::doc() );
		$today = '2026-01-05';
		$this->assertSame( 'changed', ZEST_Sale_Form::check_print( $dp, array( 'signing' => $today, 'down' => '', 'seen' => 'deadbeefdeadbeef' ), $today, false )['code'] );
		$this->assertSame( 'signing', ZEST_Sale_Form::check_print( $dp, array( 'signing' => '2026-02-20', 'down' => '', 'seen' => $dp['seen'] ), $today, false )['code'] );
		$this->assertSame( 'signing', ZEST_Sale_Form::check_print( $dp, array( 'signing' => '2026-02-30', 'down' => '', 'seen' => $dp['seen'] ), $today, false )['code'] );
		$this->assertTrue( ZEST_Sale_Form::check_print( $dp, array( 'signing' => '2026-01-02', 'down' => '', 'seen' => $dp['seen'] ), $today, false )['ok'], 'three days back is fine' );
	}

	public function test_the_age_question_is_required_only_where_the_packs_ask_it(): void {
		$dp    = ZEST_Sale_Form::downpayment( self::doc() );
		$today = '2026-01-05';
		$in    = array( 'signing' => $today, 'down' => '', 'seen' => $dp['seen'] );
		$this->assertSame( 'older', ZEST_Sale_Form::check_print( $dp, $in, $today, true )['code'] );
		$this->assertTrue( ZEST_Sale_Form::check_print( $dp, $in + array( 'older' => 'no' ), $today, true )['ok'] );
		$this->assertTrue( ZEST_Sale_Form::check_print( $dp, $in, $today, false )['ok'] );
	}

	public function test_fill_puts_facts_in_the_business_form_fields(): void {
		$f    = ZEST_Sale_Form::facts( self::doc(), array( 'signing' => '2026-01-05', 'down' => null ) );
		$form = array( 'fields' => array( 'BuyerName' => 'prepared', 'Item1' => 'items.0.what', 'Price' => 'contract', 'Nothing' => 'no_such' ) );
		$out  = ZEST_Sale_Form::fill( $f, $form );
		$this->assertSame( 'Riley Example', $out['BuyerName'] );
		$this->assertSame( 'Widget, large', $out['Item1'] );
		$this->assertSame( '5,000.00', $out['Price'] );
		$this->assertArrayNotHasKey( 'Nothing', $out );
	}
}
