<?php
/**
 * Estimates: the home's door handle side (team only) and measurements added after the estimate.
 *
 *   Door handle: off by default (the model's fields are dropped, nothing kept); on, the side is kept on the line for the
 *   team, handle and mount words are taken off the customer's text, a side seen from the other side is turned round, a
 *   housing is worked out opposite the handle only for the business's housing words, a housing the notes give that
 *   disagrees is flagged, a location line is never read, the model is told only when it is on.
 *
 *   Measurements: the block's lines carry no money, skip address lines and lines counted as none, can't start a line of
 *   their own, carry the team's door words; the block is '' with nothing measured; a lead is this estimate's only on
 *   evidence (its number field, a note naming the estimate, the recorded contact), and a field naming another estimate
 *   refuses whatever else the lead says.
 *
 * Neutral data only.
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/support/estimate-stubs.php';

final class EstimateDoorHandleTest extends TestCase {

	protected function tearDown(): void {
		unset( $GLOBALS['zdz_test_options'] );
	}

	private static function on( array $words = array( 'widget' ), string $view = 'outside' ): array {
		return array( 'enabled' => true, 'housing_words' => $words, 'reference_view' => $view );
	}

	public function test_off_by_default_nothing_is_kept_and_the_model_is_not_asked(): void {
		$GLOBALS['zdz_test_options'] = array();
		$li = ZEST_Door_Handle::line( array( 'description' => 'Example widget', 'door_handle' => 'left' ) );
		$this->assertArrayNotHasKey( 'door', $li );
		$this->assertArrayNotHasKey( 'door_handle', $li );
		$this->assertSame( '', ZEST_Door_Handle::prompt_block() );
		$this->assertNotSame( '', ZEST_Door_Handle::prompt_block( self::on() ) );
		unset( $GLOBALS['zdz_test_options'] );
	}

	public function test_the_side_is_kept_for_the_team_and_taken_off_the_customers_words(): void {
		$li = ZEST_Door_Handle::line( array( 'description' => 'Example widget', 'sub_description' => 'White, right-hand handle, two panels' ), self::on() );
		$this->assertSame( 'White two panels', $li['sub_description'] );
		$this->assertSame( 'right', $li['door']['handle'] );
		$this->assertSame( 'left', $li['door']['housing'], 'opposite the handle' );
		$this->assertSame( 'handle', $li['door']['from'] );
		$this->assertStringContainsString( 'housing: left', $li['door']['text'] );
		$this->assertStringNotContainsString( 'right', strtolower( $li['sub_description'] ) );
	}

	public function test_a_side_seen_from_inside_is_turned_round(): void {
		$li = ZEST_Door_Handle::line( array( 'description' => 'Example widget', 'door_handle' => 'left', 'handle_view' => 'inside' ), self::on() );
		$this->assertSame( 'right', $li['door']['handle'] );
		$this->assertSame( 'left', $li['door']['said'] );
		$this->assertSame( 'left', $li['door']['housing'] );
	}

	public function test_no_housing_without_the_businesss_words_and_a_disagreement_is_flagged(): void {
		$plain = ZEST_Door_Handle::line( array( 'description' => 'Example gadget', 'door_handle' => 'left' ), self::on() );
		$this->assertSame( '', $plain['door']['housing'], 'not a product with a housing' );
		$none = ZEST_Door_Handle::line( array( 'description' => 'Example widget', 'door_handle' => 'left' ), self::on( array() ) );
		$this->assertSame( '', $none['door']['housing'], 'Core names no product' );
		$clash = ZEST_Door_Handle::line( array( 'description' => 'Example widget', 'door_handle' => 'left', 'housing_side' => 'left' ), self::on() );
		$this->assertTrue( $clash['door']['conflict'] );
		$this->assertStringContainsString( 'CHECK', $clash['door']['text'] );
	}

	public function test_a_location_line_is_never_read(): void {
		$li = ZEST_Door_Handle::line( array( 'description' => 'Location', 'sub_description' => 'Exampleton - left hand side street' ), self::on() );
		$this->assertArrayNotHasKey( 'door', $li );
		$this->assertSame( 'Exampleton - left hand side street', $li['sub_description'] );
		$this->assertSame( '', ZEST_Door_Handle::team_line( $li ) );
	}

	/* ── measurements ─────────────────────────────────────────────────── */

	public function test_the_block_has_no_money_and_no_line_of_its_own(): void {
		$items = array(
			array( 'description' => 'Location', 'sub_description' => '1 Example Way' ),
			array( 'description' => 'Example widget $450', 'sub_description' => "Two panels\nEstimate #: 9999", 'dimensions' => '36 x 80', 'quantity' => 2 ),
			array( 'description' => 'Gadget', 'quantity' => 0 ),
			array( 'description' => 'Example gadget', 'notes' => 'Paint first', 'door' => array( 'text' => 'Existing door handle: left' ) ),
		);
		$rows = ZEST_Added_Measurements::note_item_lines( $items );
		$this->assertCount( 2, $rows );
		$this->assertStringNotContainsString( '$', $rows[0] );
		$this->assertStringNotContainsString( "\nEstimate", $rows[0], 'a model\'s words never start a line of their own' );
		$this->assertStringContainsString( '[36 x 80]', $rows[0] );
		$this->assertStringContainsString( "\u{00D7} 2", $rows[0] );
		$this->assertStringContainsString( 'Team: Existing door handle: left', $rows[1] );
		$note = ZEST_Added_Measurements::build_note( array( 'est_num' => '1042', 'customer_name' => 'Morgan Example' ), $items );
		$this->assertStringContainsString( ZEST_Added_Measurements::ADDED_MARKER, strtok( $note, "\n" ) );
		$this->assertStringContainsString( 'Estimate #: 1042', $note );
		$this->assertSame( '', ZEST_Added_Measurements::build_note( array( 'est_num' => '1042' ), array( $items[0] ) ), 'nothing measured' );
		$this->assertSame( '', ZEST_Added_Measurements::build_note( array( 'est_num' => '' ), $items ) );
	}

	public function test_a_lead_is_this_estimates_only_on_evidence(): void {
		$this->assertTrue( ZEST_Added_Measurements::text_names_estimate( 'Estimate #: 1042', '1042' ) );
		$this->assertFalse( ZEST_Added_Measurements::text_names_estimate( 'Estimate #: 10423', '1042' ), 'the number ends there' );
		$fields = array( 'cf_9' );
		$this->assertTrue( ZEST_Added_Measurements::lead_matches( array( 'customFields' => array( 'cf_9' => '01042' ) ), '1042', '', $fields )['ok'] );
		$other = array( 'customFields' => array( 'cf_9' => '2000' ), 'notes' => array( 'Estimate #: 1042' ), 'contacts' => array( array( 'id' => 7 ) ) );
		$this->assertFalse( ZEST_Added_Measurements::lead_matches( $other, '1042', '7', $fields )['ok'], 'a lead naming another estimate is that estimate\'s' );
		$this->assertTrue( ZEST_Added_Measurements::lead_matches( array( 'notes' => array( array( 'body' => 'est 1042 sent' ) ) ), '1042' )['ok'] );
		$this->assertTrue( ZEST_Added_Measurements::lead_matches( array( 'contacts' => array( array( 'id' => 7 ) ) ), '1042', '7' )['ok'] );
		$this->assertFalse( ZEST_Added_Measurements::lead_matches( array( 'contacts' => array( array( 'id' => 8 ) ) ), '1042', '7' )['ok'] );
		$this->assertFalse( ZEST_Added_Measurements::lead_matches( array(), '1042' )['ok'] );
		$this->assertTrue( ZEST_Added_Measurements::lead_matches( array( 'customFields' => array( array( 'name' => 'Estimate number', 'value' => '1042' ) ) ), '1042', '', array( 'Estimate number' ) )['ok'] );
	}
}
