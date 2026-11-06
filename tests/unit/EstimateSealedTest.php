<?php
/**
 * Estimates: a gate, lockbox or alarm code never leaves in plain text.
 *
 *   - what is typed for a parse is cut before a model reads it, sealed under this request's hold, and the hold rides
 *     with the preview; a hold is adopted only by the person who made it;
 *   - every write of the stored row is cut (notes, lines, the customer's street) and the row keeps the hold;
 *   - every write to the billing system is cut (notes, terms, lines) and sealed under that billing document;
 *   - before the billing system e-mails an estimate its copy is checked: a code there is taken out, and when the copy
 *     can't be read nothing is sent;
 *   - every estimate verb's answer is cut before it reaches the chat;
 *   - opening a code on an estimate is denied by default: only its owner, and only on a day an app says is a visit.
 *
 * Neutral data only.
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/support/estimate-stubs.php';

if ( ! class_exists( 'ZDZ_Test_Est_Seal_DB' ) ) {
	/** The vault's tables plus the estimates table (rows by id and by hold). */
	class ZDZ_Test_Est_Seal_DB extends ZDZ_Test_Vault_DB {
		public $estimates = array();
		public function get_row( $sql, $out = null ) {
			if ( false !== strpos( $sql, 'zest_estimates' ) ) {
				if ( preg_match( '/WHERE id = (\d+)/', $sql, $m ) ) {
					return $this->estimates[ (int) $m[1] ] ?? null;
				}
				if ( preg_match( "/WHERE seal_hold = '([^']*)'/", $sql, $m ) ) {
					foreach ( $this->estimates as $r ) {
						if ( (string) ( $r['seal_hold'] ?? '' ) === $m[1] ) {
							return $r;
						}
					}
				}
				return null;
			}
			return parent::get_row( $sql, $out );
		}
	}
}

final class EstimateSealedTest extends TestCase {

	const ALEX   = 71; // sales
	const JORDAN = 72; // sales
	const RILEY  = 74; // the shared device
	const CODE   = '4821';
	const TYPED  = 'Back yard, gate code is 4821, two screens on the patio';

	protected function setUp(): void {
		$GLOBALS['wpdb']                  = new ZDZ_Test_Est_Seal_DB();
		$GLOBALS['zdz_test_user_roles']   = array( self::ALEX => array( 'zdz_sales' ), self::JORDAN => array( 'zdz_sales' ), self::RILEY => array( 'zdz_general' ) );
		$GLOBALS['zdz_test_user_meta']    = array( self::ALEX => array( 'zdz_user_initials' => 'AA' ), self::JORDAN => array( 'zdz_user_initials' => 'JJ' ) );
		$GLOBALS['zdz_test_users']        = array();
		$GLOBALS['zdz_test_options']      = array();
		$GLOBALS['zdz_test_filters']      = array();
		$GLOBALS['zdz_test_transients']   = array();
		$GLOBALS['zdz_test_current_user'] = self::ALEX;
		ZDZ_Sealed::reset_state();
		ZEST_Sealed::reset();
		ZEST_Salesperson::flush();
		ZEST_Billing_Doc::$transport = null;
		ZEST_Billing_Doc::$account   = 'acct1';
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'], $GLOBALS['zdz_test_user_roles'], $GLOBALS['zdz_test_user_meta'], $GLOBALS['zdz_test_users'],
			$GLOBALS['zdz_test_options'], $GLOBALS['zdz_test_filters'], $GLOBALS['zdz_test_transients'], $GLOBALS['zdz_test_current_user'] );
		ZDZ_Sealed::reset_state();
		ZEST_Sealed::reset();
		ZEST_Salesperson::flush();
		ZEST_Billing_Doc::$transport = null;
		ZEST_Billing_Doc::$account   = null;
	}

	private function sealed_rows(): array {
		return $GLOBALS['wpdb']->rows( 'zdz_sealed' );
	}

	private function assertNoCode( $v, string $msg = '' ): void {
		$this->assertStringNotContainsString( self::CODE, is_string( $v ) ? $v : (string) json_encode( $v ), $msg );
	}

	public function test_the_vault_is_ready_here(): void {
		$this->assertTrue( ZEST_Sealed::on() );
		$this->assertTrue( ZDZ_Sealed::can_keep() );
	}

	public function test_what_is_typed_is_cut_before_a_model_reads_it_and_the_hold_rides_with_the_preview(): void {
		$in = ZEST_Sealed::cut_in( self::TYPED );
		$this->assertNoCode( $in );
		$this->assertStringContainsString( '[gate code sealed]', $in );
		$hold = ZEST_Sealed::current_hold();
		$this->assertMatchesRegularExpression( '/^h[0-9a-f]{16}$/', $hold );
		$rows = $this->sealed_rows();
		$this->assertCount( 1, $rows );
		$this->assertSame( array( 'estimate', $hold ), array( $rows[0]['scope_type'], $rows[0]['scope_id'] ) );
		$this->assertNoCode( $rows[0]['sealed'], 'the vault never keeps it in plain text' );

		// the model repeats the code in a line: cut again, and the preview carries the hold
		$res = ZEST_Sealed::result_in( array( 'estimate' => array( 'items' => array( array( 'description' => 'Screen', 'sub_description' => 'Gate code 4821' ) ) ) ) );
		$this->assertNoCode( $res );
		$this->assertSame( $hold, $res['estimate']['seal_hold'] );

		$this->assertSame( 'two screens on the patio', ZEST_Sealed::cut_in( 'two screens on the patio' ), 'words without a code are left alone' );
	}

	public function test_a_hold_is_adopted_only_by_the_person_who_made_it(): void {
		ZEST_Sealed::cut_in( self::TYPED );
		$hold = ZEST_Sealed::current_hold();
		ZEST_Sealed::reset();
		$GLOBALS['zdz_test_current_user'] = self::JORDAN;
		$this->assertSame( '', ZEST_Sealed::adopt( $hold ) );
		$this->assertSame( '', ZEST_Sealed::adopt( 'not-a-hold' ) );
		$GLOBALS['zdz_test_current_user'] = self::ALEX;
		$this->assertSame( $hold, ZEST_Sealed::adopt( $hold ) );
	}

	public function test_every_write_of_the_stored_row_is_cut_and_keeps_the_hold(): void {
		$data = array(
			'notes'           => self::TYPED,
			'customer_street' => '100 Example Way, gate 4821',
			'items_json'      => json_encode( array( array( 'description' => 'Screen', 'sub_description' => 'Lockbox code 4821' ) ) ),
			'seal_hold'       => '',
		);
		$out = ZEST_Sealed::row_data( $data, self::ALEX );
		$this->assertNoCode( $out );
		$this->assertMatchesRegularExpression( '/^h[0-9a-f]{16}$/', $out['seal_hold'] );
		$this->assertStringContainsString( '[gate code sealed]', $out['notes'] );
		foreach ( $this->sealed_rows() as $r ) {
			$this->assertSame( $out['seal_hold'], $r['scope_id'], 'sealed under the row\'s hold' );
		}

		// a later write of the same row seals under the row's own hold
		$hold = $out['seal_hold'];
		ZEST_Sealed::reset();
		$again = ZEST_Sealed::row_data( array( 'notes' => 'Alarm code 4821#', 'seal_hold' => $hold ), self::ALEX );
		$this->assertNoCode( $again );
		$this->assertSame( $hold, $again['seal_hold'] );
		$last = $this->sealed_rows();
		$this->assertSame( $hold, end( $last )['scope_id'] );

		// nothing to seal: nothing is made
		ZEST_Sealed::reset();
		$plain = ZEST_Sealed::row_data( array( 'notes' => 'Two screens', 'seal_hold' => '' ), self::ALEX );
		$this->assertSame( '', $plain['seal_hold'] );
		$this->assertSame( '', ZEST_Sealed::current_hold() );
	}

	public function test_every_write_to_the_billing_system_is_cut_and_sealed_under_that_document(): void {
		$out = ZEST_Sealed::outgoing( array(
			'notes' => self::TYPED,
			'terms' => 'Call first. Gate 4821',
			'lines' => array( array( 'name' => 'Screen', 'description' => 'Keypad code 4821' ) ),
		), 'estimate', '555' );
		$this->assertNoCode( $out );
		foreach ( $this->sealed_rows() as $r ) {
			$this->assertSame( 'b555', $r['scope_id'] );
		}
		$inv = ZEST_Sealed::outgoing( array( 'notes' => 'Gate code 4821' ), 'invoice', '9' );
		$this->assertNoCode( $inv );
		$rows = $this->sealed_rows();
		$this->assertSame( 'i9', end( $rows )['scope_id'] );

		// a new estimate's payload: the preview's hold is used, and the hold itself never goes to billing
		ZEST_Sealed::cut_in( self::TYPED );
		$hold = ZEST_Sealed::current_hold();
		ZEST_Sealed::reset();
		$est = ZEST_Sealed::outgoing_estimate( array( 'notes' => 'Gate code 4821', 'seal_hold' => $hold ) );
		$this->assertNoCode( $est );
		$this->assertArrayNotHasKey( 'seal_hold', $est );
		$rows = $this->sealed_rows();
		$this->assertSame( $hold, end( $rows )['scope_id'] );
	}

	public function test_billing_put_cuts_through_the_outgoing_filter(): void {
		$GLOBALS['zdz_test_filters']['zest_billing_outgoing'] = array( 'ZEST_Sealed', 'outgoing' );
		$sent = array();
		ZEST_Billing_Doc::$transport = function ( $method, $path, $body ) use ( &$sent ) {
			$sent[] = array( $method, $path, $body );
			return array( 'response' => array( 'result' => array( 'estimate' => array( 'id' => '555' ) ) ) );
		};
		$res = ZEST_Billing_Doc::put( 'estimate', '555', array( 'notes' => self::TYPED, 'customer_secret' => 'x' ) );
		$this->assertTrue( $res['ok'] );
		$this->assertNoCode( $sent );
		$this->assertArrayNotHasKey( 'customer_secret', $sent[0][2]['estimate'], 'only the fields this app writes' );
	}

	public function test_before_the_billing_system_emails_an_estimate_a_code_there_is_taken_out(): void {
		$GLOBALS['zdz_test_filters']['zest_billing_outgoing'] = array( 'ZEST_Sealed', 'outgoing' );
		$doc  = array( 'id' => '555', 'notes' => 'Gate code 4821', 'terms' => '', 'create_date' => '2026-01-02',
			'lines' => array( array( 'name' => 'Screen', 'description' => 'Two', 'qty' => '1', 'unit_cost' => array( 'amount' => '100.00' ), 'type' => 0 ) ) );
		$puts = array();
		ZEST_Billing_Doc::$transport = function ( $method, $path, $body ) use ( &$doc, &$puts ) {
			if ( 'PUT' === $method ) {
				$puts[] = $body;
				if ( isset( $body['estimate']['notes'] ) ) {
					$doc['notes'] = $body['estimate']['notes'];
				}
			}
			return array( 'response' => array( 'result' => array( 'estimate' => $doc ) ) );
		};
		$res = ZEST_Billing_Doc::email( '555', 'morgan@example.com' );
		$this->assertTrue( $res['ok'], (string) $res['error'] );
		$this->assertNoCode( $puts );
		$this->assertNoCode( $doc['notes'] );
		$this->assertTrue( ! empty( end( $puts )['estimate']['action_email'] ), 'sent only after the copy was clean' );

		// the copy can't be read: nothing is sent
		$puts = array();
		ZEST_Billing_Doc::$transport = function ( $method, $path, $body ) use ( &$puts ) {
			if ( 'PUT' === $method ) {
				$puts[] = $body;
			}
			return null;
		};
		$this->assertFalse( ZEST_Billing_Doc::email( '555', 'morgan@example.com' )['ok'] );
		$this->assertSame( array(), $puts );
	}

	public function test_every_estimate_verb_answer_is_cut_before_the_chat(): void {
		$caps = ZEST_Sealed::guard_verbs( array(
			'estimate.contact_for' => array( 'callable' => function () { return array( 'notes' => 'Gate code 4821' ); } ),
			'jobs.something'       => array( 'callable' => function () { return array( 'notes' => 'Gate code 4821' ); } ),
		) );
		$this->assertNoCode( call_user_func( $caps['estimate.contact_for']['callable'], array() ) );
		$this->assertStringContainsString( self::CODE, json_encode( call_user_func( $caps['jobs.something']['callable'], array() ) ), 'another app\'s verb is that app\'s to guard' );
	}

	public function test_opening_an_estimate_code_is_denied_by_default(): void {
		$GLOBALS['wpdb']->estimates[7] = array( 'id' => 7, 'created_by' => self::ALEX, 'salesperson' => 'AA', 'notes' => '', 'items_json' => '[]', 'seal_hold' => '', 'billing_doc_id' => '' );
		$vault_row = array( 'scope_type' => 'estimate', 'scope_id' => '7' );
		$this->assertFalse( ZEST_Sealed::may_open( false, $vault_row, self::ALEX ), 'no visit today' );
		$GLOBALS['zdz_test_filters']['zest_sealed_estimate_visit'] = function ( $ok, $id, $uid ) { return 7 === $id; };
		$this->assertSame( 'estimate visit today', ZEST_Sealed::may_open( false, $vault_row, self::ALEX ) );
		$this->assertFalse( ZEST_Sealed::may_open( false, $vault_row, self::JORDAN ), 'not theirs' );
		$this->assertFalse( ZEST_Sealed::may_open( false, $vault_row, self::RILEY ), 'never the shared device' );
		$this->assertFalse( ZEST_Sealed::may_open( false, array( 'scope_type' => 'estimate', 'scope_id' => '99' ), self::ALEX ), 'no such estimate' );
		$this->assertFalse( ZEST_Sealed::may_open( false, array( 'scope_type' => 'job', 'scope_id' => '7' ), self::ALEX ), 'another app\'s code' );
	}
}
