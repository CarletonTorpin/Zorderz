<?php
/**
 * Estimates: the deposit limit and the deposit reader.
 *
 *   - The limit is the legal packs' (a state pack's deposit_cap: the lesser of a fixed amount and a percent of the
 *     price before tax, in whole dollars), folded with the business's own stricter one; a pack with no cap (the
 *     federal default) is no limit at all, and then nothing is found and nothing is refused.
 *   - A business may narrow the limit, never widen it.
 *   - The reader: sure asks (refuse Approve and Send), possible ones (a warning), and lawful wordings that are never
 *     asks; the app's own sentence; Fix rewrites only what it understands.
 *   - The payment schedule's deposit is the business's rule, never over the limit.
 *
 * Neutral data only.
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/support/estimate-stubs.php';

final class EstimateDepositTest extends TestCase {

	/** The shape a state pack's rules.json carries (a home-improvement down-payment cap). */
	const STATE_CAP = array( 'fixed_cents' => 100000, 'percent' => 10, 'base' => 'contract_before_tax', 'whole_dollars' => true );

	protected function setUp(): void {
		$GLOBALS['zdz_test_options'] = array();
		$GLOBALS['zdz_test_filters'] = array();
		ZEST_Deposit_Limit::reset();
		ZEST_Payment_Plan::set_config( null );
	}

	protected function tearDown(): void {
		ZEST_Deposit_Limit::reset();
		ZEST_Payment_Plan::set_config( null );
		unset( $GLOBALS['zdz_test_options'], $GLOBALS['zdz_test_filters'] );
	}

	private function state_limit(): void {
		ZEST_Deposit_Limit::set( ZEST_Deposit_Limit::fold( array( self::STATE_CAP ) ) );
	}

	/* ── the limit ───────────────────────────────────────────────────── */

	public function test_the_state_pack_cap_is_the_lesser_of_the_fixed_amount_and_the_percent(): void {
		$this->state_limit();
		$this->assertTrue( ZEST_Deposit_Limit::active() );
		$this->assertSame( 50000, ZEST_Deposit_Limit::cap_cents( 500000 ), '10% of $5,000 is under $1,000' );
		$this->assertSame( 100000, ZEST_Deposit_Limit::cap_cents( 2500000 ), '10% of $25,000 is over $1,000: the fixed amount' );
		$this->assertSame( 50000, ZEST_Deposit_Limit::cap_cents( 500099 ), 'whole dollars, rounded down' );
		$this->assertSame( '$1,000 or 10% of the price, whichever is less', ZEST_Deposit_Limit::describe() );
	}

	public function test_no_pack_cap_means_no_limit_and_nothing_is_found(): void {
		ZEST_Deposit_Limit::set( ZEST_Deposit_Limit::fold( array() ) );
		$this->assertFalse( ZEST_Deposit_Limit::active() );
		$this->assertSame( array(), ZEST_Deposit_Rule::find( '50% deposit required to start.', 500000 ) );
		$this->assertSame( array(), ZEST_Deposit_Rule::doc_findings( array( 'notes' => '50% deposit required to start.', 'lines' => array() ) ) );
	}

	/**
	 * Run in its own process, where no other test file has loaded the agreements app (a runner that cannot do that skips it).
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_without_the_agreements_app_there_is_no_legal_limit(): void {
		if ( class_exists( 'ZAGR_Pack' ) ) {
			$this->markTestSkipped( 'the agreements app is loaded in this run' );
		}
		$this->assertSame( array(), ZEST_Deposit_Limit::law_rules( 'ZZ' ) );
		$this->assertFalse( ZEST_Deposit_Limit::active() );
	}

	public function test_the_cap_comes_from_the_agreements_packs_when_they_are_there(): void {
		if ( ! class_exists( 'ZAGR_Pack' ) || ! method_exists( 'ZAGR_Pack', 'deposit_cap' ) ) {
			$this->markTestSkipped( 'the agreements app is not loaded in this run' );
		}
		$rules = ZEST_Deposit_Limit::law_rules( '' );
		foreach ( $rules as $r ) {
			$this->assertIsArray( $r );
		}
		// whatever the packs say, the reader's limit and the agreements app's own cap agree on a price
		$stack = ZAGR_Pack::stack_for( '' );
		$cap   = ZAGR_Pack::deposit_cap( $stack, 500000, 0 );
		ZEST_Deposit_Limit::set( ZEST_Deposit_Limit::fold( array_map( static function ( $p ) { return $p['rules']['deposit_cap'] ?? null; }, (array) ZAGR_Pack::packs( $stack ) ) ) );
		$this->assertSame( $cap, ZEST_Deposit_Limit::limit_cents( 500000 ) );
	}

	public function test_a_business_may_narrow_the_limit_but_never_widen_it(): void {
		$GLOBALS['zdz_test_options']['zest_deposit_house_limit'] = array( 'fixed_cents' => 50000 );
		$narrow = ZEST_Deposit_Limit::fold( array( self::STATE_CAP ), ZEST_Deposit_Limit::house() );
		$this->assertSame( 50000, $narrow['cap_cents'] );
		$this->assertSame( 1000, $narrow['rate_bp'] );
		$GLOBALS['zdz_test_options']['zest_deposit_house_limit'] = array( 'percent' => 50, 'fixed_cents' => 900000 );
		$wide = ZEST_Deposit_Limit::fold( array( self::STATE_CAP ), ZEST_Deposit_Limit::house() );
		$this->assertSame( 100000, $wide['cap_cents'], 'a larger house figure never raises the legal cap' );
		$this->assertSame( 1000, $wide['rate_bp'] );
	}

	public function test_over_reads_percents_and_dollars_against_the_price(): void {
		$this->state_limit();
		$this->assertTrue( ZEST_Deposit_Limit::over( 50.0, 0, 0 )[0], 'a percent over the percent limit is over at any price' );
		$this->assertFalse( ZEST_Deposit_Limit::over( 10.0, 0, 500000 )[0] );
		$this->assertTrue( ZEST_Deposit_Limit::over( 10.0, 0, 2500000 )[0], '10% of $25,000 is over the $1,000 limit' );
		$this->assertTrue( ZEST_Deposit_Limit::over( 0.0, 60000, 500000 )[0], '$600 is over $500 on $5,000' );
		$this->assertFalse( ZEST_Deposit_Limit::over( 0.0, 50000, 500000 )[0], 'exactly the limit is within it' );
		$this->assertTrue( ZEST_Deposit_Limit::over( 0.0, 150000, 0 )[0], 'price unknown: over the fixed amount is over' );
	}

	/* ── the reader ──────────────────────────────────────────────────── */

	public function test_sure_asks_refuse(): void {
		$this->state_limit();
		foreach ( array( '50% deposit required to start.', 'Deposit required: 50%', 'Terms: 50/50', 'Half when your order ships.', 'Deposit when you sign: $1,500', '$2,500 down' ) as $t ) {
			$f = ZEST_Deposit_Rule::find( $t, 500000 );
			$this->assertNotEmpty( $f, $t );
			$this->assertTrue( $f[0]['sure'], $t );
			$this->assertNotEmpty( ZEST_Deposit_Rule::blocking( $f ), $t );
		}
	}

	public function test_lawful_wordings_are_never_asks(): void {
		$this->state_limit();
		foreach ( array( 'No deposit required, 100% due at install.', 'Save 20% when you order.', 'Deposit of $1,000 received 10/1.', '4% processing fee on cards.', '100% due upon completion.', 'Deposit: $500 when you sign.' ) as $t ) {
			$this->assertSame( array(), ZEST_Deposit_Rule::blocking( ZEST_Deposit_Rule::find( $t, 500000 ) ), $t );
		}
	}

	public function test_a_possible_ask_warns_and_blocks_nothing(): void {
		$this->state_limit();
		$f = ZEST_Deposit_Rule::find( 'Payment of 50% is needed for us to start', 500000 );
		$this->assertNotEmpty( $f );
		$this->assertFalse( $f[0]['sure'] );
		$this->assertSame( array(), ZEST_Deposit_Rule::blocking( $f ) );
	}

	public function test_fix_replaces_a_clause_that_is_only_payment_talk_with_the_apps_sentence(): void {
		$this->state_limit();
		list( $text, $removed, $wrote ) = ZEST_Deposit_Rule::fix( 'Thanks! 50% deposit due at signing.', 500000 );
		$this->assertSame( array( '50% deposit due at signing.' ), $removed );
		$this->assertSame( 'Deposit: $500, due when you approve this estimate. Balance due upon completion.', $wrote );
		$this->assertStringContainsString( $wrote, $text );
		$this->assertSame( array(), ZEST_Deposit_Rule::blocking( ZEST_Deposit_Rule::find( $text, 500000 ) ), 'the app\'s own sentence is within the limit' );
		list( $again ) = ZEST_Deposit_Rule::fix( $text, 500000 );
		$this->assertSame( $text, $again, 'fixing twice changes nothing' );
	}

	public function test_the_apps_sentence_over_the_limit_after_a_price_change_is_sure(): void {
		$this->state_limit();
		$f = ZEST_Deposit_Rule::find( 'Deposit: $500, due when you approve this estimate.', 300000 );
		$this->assertNotEmpty( $f );
		$this->assertTrue( $f[0]['own'] && $f[0]['sure'] );
	}

	public function test_the_business_product_words_join_the_goods_readings(): void {
		$this->state_limit();
		$this->assertSame( array(), ZEST_Deposit_Rule::blocking( ZEST_Deposit_Rule::find( 'Half when your widgets arrive.', 500000 ) ) );
		$GLOBALS['zdz_test_options']['zest_deposit_product_words'] = array( 'widget' );
		ZEST_Deposit_Limit::reset();
		$this->state_limit();
		$this->assertNotEmpty( ZEST_Deposit_Rule::blocking( ZEST_Deposit_Rule::find( 'Half when your widgets arrive.', 500000 ) ) );
	}

	public function test_refusal_words_name_the_limit_not_a_place(): void {
		$this->state_limit();
		$found = ZEST_Deposit_Rule::doc_findings( array( 'notes' => '50% deposit required to start.', 'lines' => array( array( 'name' => 'Widget', 'description' => '', 'qty' => 1, 'unit_cost' => array( 'amount' => '5000.00' ) ) ) ) );
		$msg   = ZEST_Deposit_Rule::refusal_text( $found, 'estimate', '1001', 'approved' );
		$this->assertStringContainsString( '$1,000 or 10% of the price, whichever is less', $msg );
		$this->assertStringContainsString( 'so it was not approved', $msg );
		$plain = ZEST_Deposit_Rule::refusal_plain( 'estimate', '1001', 'sent' );
		$this->assertStringNotContainsString( '50%', $plain, 'the plain words carry no customer figure' );
	}

	/* ── the payment schedule ────────────────────────────────────────── */

	public function test_the_schedule_deposit_is_the_business_rule_within_the_limit(): void {
		$this->state_limit();
		ZEST_Payment_Plan::set_config( array( 'groups' => array( array( 'id' => 'widgets', 'names' => array( 'widget' ) ) ), 'deposit' => array( 'at_limit' => true ) ) );
		$doc  = array( 'lines' => array( array( 'description' => 'Widget, large', 'sub_description' => '', 'quantity' => 1, 'unit_price' => 5000 ) ) );
		$plan = ZEST_Payment_Plan::build( $doc );
		$this->assertTrue( $plan['ok'], implode( '; ', $plan['why'] ) );
		$this->assertSame( array( 'widgets' ), $plan['families'] );
		$this->assertSame( 50000, $plan['deposit_cents'] );
		$this->assertSame( 450000, $plan['balance_cents'] );

		ZEST_Payment_Plan::set_config( array( 'groups' => array( array( 'id' => 'widgets', 'names' => array( 'widget' ) ) ), 'deposit' => array( 'percent' => 50 ) ) );
		$this->assertSame( 50000, ZEST_Payment_Plan::build( $doc )['deposit_cents'], 'a 50% house rule is cut to the limit' );
	}

	public function test_no_group_no_schedule_and_no_rule_no_schedule(): void {
		$this->state_limit();
		$doc = array( 'lines' => array( array( 'description' => 'Widget', 'quantity' => 1, 'unit_price' => 100 ) ) );
		$this->assertFalse( ZEST_Payment_Plan::build( $doc )['in_scope'], 'Core ships no groups' );
		ZEST_Payment_Plan::set_config( array( 'groups' => array( array( 'id' => 'widgets', 'names' => array( 'widget' ) ) ) ) );
		$plan = ZEST_Payment_Plan::build( $doc );
		$this->assertTrue( $plan['in_scope'] );
		$this->assertFalse( $plan['ok'] );
		$this->assertContains( 'no deposit rule is set for this business', $plan['why'] );
		$this->assertSame( '', ZEST_Payment_Plan::family( 'Non-widget part', '' ), '"non-<word>" never counts' );
	}

	public function test_the_schedule_block_reads_back_as_the_apps_own(): void {
		$this->state_limit();
		$plan  = array( 'deposit_cents' => 50000, 'balance_cents' => 450000, 'total_cents' => 500000 );
		$block = ZEST_Terms_Note::render( $plan, 'Questions about your payments? Call 555-0100.' );
		$notes = "Thank you.\n\n" . $block;
		$this->assertTrue( ZEST_Terms_Note::has_block( $notes ) );
		$this->assertFalse( ZEST_Terms_Note::lookalike( $notes ) );
		$this->assertSame( 'Thank you.', ZEST_Terms_Note::take_out( $notes ) );
		$typed = "PAYMENT SCHEDULE\nHalf now, half later.";
		$this->assertTrue( ZEST_Terms_Note::lookalike( $typed ), 'a schedule typed by hand is the customer\'s words' );
		$this->assertSame( $typed, ZEST_Terms_Note::put( $typed, $block ), 'never a second schedule beside it' );
	}
}
