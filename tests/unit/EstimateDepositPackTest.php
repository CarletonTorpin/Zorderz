<?php
/**
 * Estimates: the deposit limit read from the agreements app's real legal packs (not a figure in the estimate app).
 *
 *   - a job in the state whose pack caps the down payment gets that cap; a job in a state with no state pack gets the
 *     national pack's rule, which sets none (no limit, nothing found);
 *   - a job whose state is not known gets the strictest cap any state pack layered on the chosen pack sets;
 *   - "no agreements" (pack none) means no legal limit;
 *   - the reader's limit and the agreements app's own deposit_cap() agree on every price.
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/support/agreements-stubs.php';
require_once __DIR__ . '/support/estimate-stubs.php';

final class EstimateDepositPackTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['zdz_test_options']    = array();
		$GLOBALS['zdz_test_transients'] = array();
		$GLOBALS['zdz_test_filters']    = array( 'zagr_agreement_pack' => static function () { return 'us-federal'; } );
		ZAGR_Pack::forget();
		ZEST_Deposit_Limit::reset();
	}

	protected function tearDown(): void {
		ZAGR_Pack::forget();
		ZEST_Deposit_Limit::reset();
		unset( $GLOBALS['zdz_test_options'], $GLOBALS['zdz_test_filters'], $GLOBALS['zdz_test_transients'] );
	}

	public function test_a_state_pack_caps_the_deposit_and_the_national_pack_does_not(): void {
		$ca = ZEST_Deposit_Limit::for_region( 'CA' );
		$this->assertSame( 1000, $ca['rate_bp'] );
		$this->assertSame( 100000, $ca['cap_cents'] );
		$this->assertTrue( $ca['whole'] );
		$tx = ZEST_Deposit_Limit::for_region( 'TX' );
		$this->assertNull( $tx['rate_bp'] );
		$this->assertNull( $tx['cap_cents'] );
		ZEST_Deposit_Limit::set( $tx );
		$this->assertSame( array(), ZEST_Deposit_Rule::find( '50% deposit required to start.', 500000 ), 'no cap: nothing is over a limit' );
	}

	public function test_an_unknown_state_gets_the_strictest_cap_that_could_apply(): void {
		$any = ZEST_Deposit_Limit::for_region( '' );
		$this->assertSame( 100000, $any['cap_cents'] );
		ZEST_Deposit_Limit::set( $any );
		$this->assertNotEmpty( ZEST_Deposit_Rule::blocking( ZEST_Deposit_Rule::find( '50% deposit required to start.', 500000 ) ) );
	}

	public function test_no_agreements_means_no_legal_limit(): void {
		$GLOBALS['zdz_test_filters']['zagr_agreement_pack'] = static function () { return 'none'; };
		ZAGR_Pack::forget();
		$none = ZEST_Deposit_Limit::for_region( 'CA' );
		$this->assertNull( $none['cap_cents'] );
		$this->assertNull( $none['rate_bp'] );
	}

	public function test_the_reader_and_the_agreements_app_agree_on_the_cap(): void {
		ZEST_Deposit_Limit::set( ZEST_Deposit_Limit::for_region( 'CA' ) );
		$stack = ZAGR_Pack::stack_for( 'CA' );
		foreach ( array( 99900, 500000, 999999, 2500000 ) as $price ) {
			$this->assertSame( ZAGR_Pack::deposit_cap( $stack, $price, 0 ), ZEST_Deposit_Limit::limit_cents( $price ), (string) $price );
		}
		$this->assertTrue( ZAGR_Pack::whole_dollars( $stack ) );
	}
}
