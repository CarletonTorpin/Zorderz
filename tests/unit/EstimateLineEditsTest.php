<?php
/**
 * Estimates: changing the lines of a billing document.
 *
 *   - what a person may change follows their field permissions: wording, quantities, prices (never while prices are
 *     blind), adding and removing only with both; an archived document is read only; an invoice with a payment takes
 *     price and quantity changes from an administrator only;
 *   - a change outside those rights is refused whole, nothing applied; bad figures and empty names are refused;
 *   - figures not touched go back exactly as they were read;
 *   - the "Edited by" line is added once per person per day, and not for the person the provenance line names;
 *   - a save needs the edit token this screen was given for this estimate.
 *
 * Neutral data only.
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/support/estimate-stubs.php';
require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-doc-conventions.php';

final class EstimateLineEditsTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['zdz_test_user_meta'] = array();
		$GLOBALS['zdz_test_options']   = array();
		$GLOBALS['zdz_test_filters']   = array();
		ZDZ_Doc_Conventions::flush();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['zdz_test_user_meta'], $GLOBALS['zdz_test_options'], $GLOBALS['zdz_test_filters'] );
		ZDZ_Doc_Conventions::flush();
	}

	private static function doc( array $over = array() ): array {
		return $over + array(
			'vis_state' => 0,
			'paid'      => array( 'amount' => '0.00' ),
			'notes'     => '',
			'lines'     => array(
				array( 'type' => 0, 'name' => 'Widget', 'description' => 'Blue', 'qty' => '2', 'unit_cost' => array( 'amount' => '100.00' ), 'taxName1' => 'Sales', 'taxAmount1' => '8' ),
				array( 'type' => 0, 'name' => 'Gadget', 'description' => '', 'qty' => '1', 'unit_cost' => array( 'amount' => '50.5' ) ),
			),
		);
	}

	private static function all(): array {
		return array( 'edit_wording' => true, 'edit_quantity' => true, 'edit_pricing' => true );
	}

	public function test_rights_follow_the_field_permissions(): void {
		$r = ZEST_Line_Edits::rights( self::doc(), 'estimate', self::all(), false );
		$this->assertSame( array( true, true, true, true ), array( $r['text'], $r['qty'], $r['price'], $r['add_remove'] ) );

		$r = ZEST_Line_Edits::rights( self::doc(), 'estimate', array( 'edit_wording' => true ), false );
		$this->assertSame( array( true, false, false, false ), array( $r['text'], $r['qty'], $r['price'], $r['add_remove'] ) );

		$r = ZEST_Line_Edits::rights( self::doc(), 'estimate', array(), false );
		$this->assertSame( array( false, false, false, false ), array( $r['text'], $r['qty'], $r['price'], $r['add_remove'] ), 'nothing granted, nothing allowed' );

		$r = ZEST_Line_Edits::rights( self::doc(), 'estimate', self::all(), false, true );
		$this->assertFalse( $r['price'], 'blind prices are never edited' );
		$this->assertFalse( $r['add_remove'] );
		$this->assertTrue( $r['qty'] );
	}

	public function test_an_archived_document_is_read_only(): void {
		$r = ZEST_Line_Edits::rights( self::doc( array( 'vis_state' => 1 ) ), 'estimate', self::all(), true );
		$this->assertSame( array( false, false, false, false ), array( $r['text'], $r['qty'], $r['price'], $r['add_remove'] ) );
		$this->assertNotSame( '', $r['note'] );
	}

	public function test_a_paid_invoice_takes_price_and_quantity_changes_from_an_administrator_only(): void {
		$paid = self::doc( array( 'paid' => array( 'amount' => '25.00' ) ) );
		$r    = ZEST_Line_Edits::rights( $paid, 'invoice', self::all(), false );
		$this->assertTrue( $r['text'] );
		$this->assertFalse( $r['qty'] );
		$this->assertFalse( $r['price'] );
		$this->assertFalse( $r['add_remove'] );
		$a = ZEST_Line_Edits::rights( $paid, 'invoice', self::all(), true );
		$this->assertTrue( $a['price'] && $a['qty'] && $a['add_remove'] );
		$this->assertTrue( ZEST_Line_Edits::rights( $paid, 'estimate', self::all(), false )['price'], 'an estimate has no payment rule' );
	}

	public function test_a_change_outside_the_rights_is_refused_whole(): void {
		$lines  = ZEST_Line_Edits::lines_from_doc( self::doc() );
		$wording = array( 'text' => true, 'qty' => false, 'price' => false, 'add_remove' => false );
		$res    = ZEST_Line_Edits::apply( $lines, array(
			array( 'type' => 'text', 'line' => 0, 'field' => 'name', 'to' => 'Widget, large' ),
			array( 'type' => 'rate', 'line' => 0, 'to' => '1' ),
		), $wording );
		$this->assertFalse( $res['ok'] );
		$this->assertArrayNotHasKey( 'lines', $res );
		foreach ( array(
			array( 'type' => 'qty', 'line' => 0, 'to' => '3' ),
			array( 'type' => 'remove', 'line' => 1 ),
			array( 'type' => 'add', 'name' => 'Extra', 'qty' => 1, 'rate' => 5 ),
			array( 'type' => 'option', 'line' => 0 ),
		) as $c ) {
			$this->assertFalse( ZEST_Line_Edits::apply( $lines, array( $c ), $wording )['ok'], (string) $c['type'] );
		}
		$this->assertFalse( ZEST_Line_Edits::apply( $lines, array( array( 'type' => 'text', 'line' => 9, 'field' => 'name', 'to' => 'x' ) ), $wording )['ok'], 'no such line' );
		$this->assertFalse( ZEST_Line_Edits::apply( $lines, array(), $wording )['ok'] );
	}

	public function test_bad_figures_and_empty_names_are_refused(): void {
		$lines = ZEST_Line_Edits::lines_from_doc( self::doc() );
		$all   = array( 'text' => true, 'qty' => true, 'price' => true, 'add_remove' => true );
		foreach ( array(
			array( 'type' => 'qty', 'line' => 0, 'to' => '0' ),
			array( 'type' => 'qty', 'line' => 0, 'to' => 'lots' ),
			array( 'type' => 'qty', 'line' => 0, 'to' => (string) ( ZEST_Line_Edits::MAX_QTY + 1 ) ),
			array( 'type' => 'rate', 'line' => 0, 'to' => 'free' ),
			array( 'type' => 'text', 'line' => 0, 'field' => 'name', 'to' => '   ' ),
			array( 'type' => 'text', 'line' => 0, 'field' => 'colour', 'to' => 'x' ),
			array( 'type' => 'add', 'name' => '', 'qty' => 1, 'rate' => 5 ),
		) as $c ) {
			$this->assertFalse( ZEST_Line_Edits::apply( $lines, array( $c ), $all )['ok'], json_encode( $c ) );
		}
		$both = ZEST_Line_Edits::apply( $lines, array( array( 'type' => 'remove', 'line' => 0 ), array( 'type' => 'remove', 'line' => 1 ) ), $all );
		$this->assertFalse( $both['ok'], 'a document keeps at least one line' );
	}

	public function test_an_allowed_save_changes_only_what_was_asked_and_untouched_figures_go_back_as_read(): void {
		$lines = ZEST_Line_Edits::lines_from_doc( self::doc() );
		$res   = ZEST_Line_Edits::apply( $lines, array(
			array( 'type' => 'qty', 'line' => 0, 'to' => '3' ),
			array( 'type' => 'add', 'name' => 'Extra', 'description' => 'One more', 'qty' => '1', 'rate' => '12.5' ),
		), array( 'text' => true, 'qty' => true, 'price' => true, 'add_remove' => true ) );
		$this->assertTrue( $res['ok'] );
		$this->assertCount( 2, $res['log'] );
		$wire = ZEST_Line_Edits::to_wire_lines( $res['lines'] );
		$this->assertSame( '3', $wire[0]['qty'] );
		$this->assertSame( '100.00', $wire[0]['unit_cost']['amount'] );
		$this->assertSame( 'Sales', $wire[0]['taxName1'], 'fields the editor does not touch ride along' );
		$this->assertSame( '50.5', $wire[1]['unit_cost']['amount'], 'an untouched price goes back exactly as read' );
		$this->assertSame( 'Extra', $wire[2]['name'] );
		$this->assertSame( '', ZEST_Line_Edits::verify( $res['lines'], array( 'lines' => $wire ) ) );
		$this->assertSame( '1', ZEST_Line_Edits::verify( $res['lines'], array( 'lines' => array_replace( $wire, array( 0 => array( 'qty' => '9' ) + $wire[0] ) ) ) ) );
		$this->assertSame( 'count', ZEST_Line_Edits::verify( $res['lines'], array( 'lines' => array( $wire[0] ) ) ) );
	}

	public function test_nothing_changed_saves_nothing(): void {
		$lines = ZEST_Line_Edits::lines_from_doc( self::doc() );
		$res   = ZEST_Line_Edits::apply( $lines, array( array( 'type' => 'qty', 'line' => 0, 'to' => '2' ) ), array( 'text' => true, 'qty' => true, 'price' => true, 'add_remove' => true ) );
		$this->assertFalse( $res['ok'] );
	}

	public function test_the_edited_by_line_is_added_once_and_not_for_the_named_salesperson(): void {
		$once = ZEST_Line_Edits::edited_by( 'Call first.', 'jj', '2026-01-02' );
		$this->assertSame( "Call first.\nEdited by: JJ - 2026-01-02", $once );
		$this->assertSame( $once, ZEST_Line_Edits::edited_by( $once, 'JJ', '2026-01-02' ) );
		$this->assertSame( 'Call first.', ZEST_Line_Edits::edited_by( 'Call first.', '', '2026-01-02' ) );
		$named = "Call first.\nSubmitted by: JJ (AA)";
		$this->assertSame( $named, ZEST_Line_Edits::edited_by( $named, 'JJ', '2026-01-02' ), 'the person the provenance line names' );
		$this->assertStringContainsString( 'Edited by: AA', ZEST_Line_Edits::edited_by( $named, 'AA', '2026-01-02' ), 'a parenthetical is not a name' );
	}

	public function test_a_save_needs_this_screens_token_for_this_estimate(): void {
		$t = ZEST_Edit_Token::issue( 81, 7 );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{32}$/', $t );
		$this->assertSame( '', ZEST_Edit_Token::check( 81, 7, $t ) );
		$this->assertSame( ZEST_Edit_Token::says_other(), ZEST_Edit_Token::check( 81, 8, $t ), 'another estimate' );
		$this->assertSame( ZEST_Edit_Token::says_none(), ZEST_Edit_Token::check( 82, 7, $t ), 'another person' );
		$this->assertSame( ZEST_Edit_Token::says_none(), ZEST_Edit_Token::check( 81, 7, 'not-a-token' ) );
		$this->assertSame( ZEST_Edit_Token::says_none(), ZEST_Edit_Token::check( 81, 7, str_repeat( 'a', 32 ) ) );
		foreach ( (array) $GLOBALS['zdz_test_user_meta'][81] as $k => $v ) {
			$this->assertStringNotContainsString( $t, (string) $k . (string) $v, 'only a hash of the token is kept' );
		}
		$this->assertTrue( ZEST_Edit_Token::drop( 81, $t ) );
		$this->assertSame( ZEST_Edit_Token::says_none(), ZEST_Edit_Token::check( 81, 7, $t ) );
		$this->assertSame( '', ZEST_Edit_Token::issue( 0, 7 ), 'nobody signed in, no token' );
	}
}
