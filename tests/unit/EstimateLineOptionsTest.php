<?php
/**
 * Estimates: the press-and-hold option map, and the pack keys that carry the business's choices.
 *
 *   - validate() takes a well-formed map and refuses markup, unknown slots, a wrong version and empty families;
 *   - a line's family is found by whole words; a word is named by an option's sayings; the case of the word is kept;
 *   - check_option() refuses a swap that is not a whole word, not the word that is there, not in the line's family's
 *     slots, not a known choice, or not written exactly as the choice;
 *   - ZEST_Line_Edits::apply() applies swaps (last first), logs each one, refuses overlap, refuses without a map, and
 *     refuses a typed rewording and a swap on the same field at once;
 *   - the map in use is the hub's copy when valid, else the pack's, else none (Core ships none);
 *   - the pack consumer accepts catalog.estimate.line_options, rules estimates.door_handle and estimates.reassign, and
 *     refuses what it cannot read.
 *
 * Neutral data only: an "Example" product family with made-up words.
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/support/estimate-stubs.php';
require_once dirname( __DIR__, 2 ) . '/zorderz-apps/apps/estimate/includes/class-zest-pack.php';

final class EstimateLineOptionsTest extends TestCase {

	public static function map(): array {
		return array(
			'map_version'   => 1,
			'built'         => '2026-01-01',
			'colors'        => array(
				array( 'id' => 'white', 'label' => 'White', 'say' => array( 'white', 'wht' ) ),
				array( 'id' => 'black', 'label' => 'Black', 'say' => array( 'black', 'blk' ) ),
			),
			'color_context' => array( 'frame_color' => array( 'frame' ) ),
			'slots'         => array(
				'frame_color' => array( 'label' => 'Frame color', 'kind' => 'color' ),
				'style'       => array( 'label' => 'Style', 'options' => array(
					array( 'id' => 'plain', 'label' => 'Plain', 'write' => 'plain', 'say' => array( 'plain' ) ),
					array( 'id' => 'fancy', 'label' => 'Fancy', 'write' => 'fancy', 'say' => array( 'fancy', 'deluxe' ) ),
				) ),
				'size'        => array( 'label' => 'Size', 'options' => array(
					array( 'id' => 'small', 'label' => 'Small', 'write' => 'small', 'say' => array( 'small' ) ),
				) ),
			),
			'families'      => array(
				array( 'id' => 'widgets', 'label' => 'Widgets', 'match' => array( 'widget' ), 'slots' => array( 'frame_color', 'style' ) ),
				array( 'id' => 'gadgets', 'label' => 'Gadgets', 'match' => array( 'gadget' ), 'slots' => array( 'size' ) ),
			),
		);
	}

	protected function setUp(): void {
		$GLOBALS['zdz_test_options'] = array();
		ZEST_Line_Options::flush();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['zdz_test_options'] );
		ZEST_Line_Options::flush();
	}

	public function test_a_well_formed_map_passes_and_a_bad_one_never_does(): void {
		$this->assertTrue( ZEST_Line_Options::validate( self::map() ) );
		$bad = self::map();
		$bad['colors'][0]['label'] = '<b>White</b>';
		$this->assertFalse( ZEST_Line_Options::validate( $bad ), 'no markup' );
		$bad = self::map();
		$bad['families'][0]['slots'][] = 'nope';
		$this->assertFalse( ZEST_Line_Options::validate( $bad ), 'a family names only known slots' );
		$bad = self::map();
		$bad['map_version'] = 2;
		$this->assertFalse( ZEST_Line_Options::validate( $bad ) );
		$bad = self::map();
		$bad['families'] = array();
		$this->assertFalse( ZEST_Line_Options::validate( $bad ), 'a map with no family is no map' );
		$bad = self::map();
		$bad['slots']['style']['options'][0]['id'] = 'Bad Id';
		$this->assertFalse( ZEST_Line_Options::validate( $bad ) );
		$this->assertFalse( ZEST_Line_Options::validate( null ) );
	}

	public function test_families_words_and_case(): void {
		$m = self::map();
		$this->assertSame( 'widgets', ZEST_Line_Options::family_of( 'Example Widget - white frame', $m )['id'] );
		$this->assertNull( ZEST_Line_Options::family_of( 'Widgetry kit', $m ), 'whole words only' );
		$this->assertSame( 'black', ZEST_Line_Options::option_named( $m, 'frame_color', 'BLK' ) );
		$this->assertSame( 'fancy', ZEST_Line_Options::option_named( $m, 'style', 'deluxe' ) );
		$this->assertSame( '', ZEST_Line_Options::option_named( $m, 'style', 'white' ) );
		$this->assertSame( 'BLACK', ZEST_Line_Options::apply_case( 'WHITE', 'black' ) );
		$this->assertSame( 'Black', ZEST_Line_Options::apply_case( 'White', 'black' ) );
		$this->assertSame( 'black', ZEST_Line_Options::apply_case( 'white', 'black' ) );
	}

	public function test_a_swap_is_checked_word_for_word(): void {
		$m    = self::map();
		$text = 'Plain widget, white frame';
		$ok   = ZEST_Line_Options::check_option( $text, array( 'start' => 14, 'end' => 19, 'from' => 'white', 'to' => 'black', 'slot' => 'frame_color', 'option' => 'black' ), $m, array( 'frame_color', 'style' ) );
		$this->assertIsArray( $ok );
		$this->assertSame( 'white', $ok['was'] );
		$this->assertIsString( ZEST_Line_Options::check_option( $text, array( 'start' => 15, 'end' => 19, 'from' => 'hite', 'to' => 'black', 'slot' => 'frame_color', 'option' => 'black' ), $m, null ), 'a whole word only' );
		$this->assertIsString( ZEST_Line_Options::check_option( $text, array( 'start' => 14, 'end' => 19, 'from' => 'black', 'to' => 'white', 'slot' => 'frame_color', 'option' => 'white' ), $m, null ), 'the word that is there' );
		$this->assertIsString( ZEST_Line_Options::check_option( $text, array( 'start' => 14, 'end' => 19, 'from' => 'white', 'to' => 'black', 'slot' => 'frame_color', 'option' => 'black' ), $m, array( 'size' ) ), 'the line\'s family\'s slots only' );
		$this->assertIsString( ZEST_Line_Options::check_option( $text, array( 'start' => 14, 'end' => 19, 'from' => 'white', 'to' => 'Black', 'slot' => 'frame_color', 'option' => 'black' ), $m, null ), 'written exactly as the choice, in the word\'s case' );
		$this->assertIsString( ZEST_Line_Options::check_option( $text, array( 'start' => 14, 'end' => 19, 'from' => 'white', 'to' => 'white', 'slot' => 'frame_color', 'option' => 'white' ), $m, null ), 'already the choice' );
		$this->assertIsString( ZEST_Line_Options::check_option( $text, array( 'start' => 14, 'end' => 19, 'from' => 'white', 'to' => 'red', 'slot' => 'frame_color', 'option' => 'red' ), $m, null ), 'not a known choice' );
	}

	private static function lines(): array {
		return ZEST_Line_Edits::lines_from_doc( array( 'lines' => array(
			array( 'name' => 'Plain widget', 'description' => 'White frame, plain', 'qty' => '1', 'unit_cost' => array( 'amount' => '100.00' ) ),
			array( 'name' => 'Gadget', 'description' => 'Small', 'qty' => '1', 'unit_cost' => array( 'amount' => '50.00' ) ),
		) ) );
	}

	public function test_the_line_editor_applies_swaps_and_refuses_what_it_cannot_check(): void {
		$rights = array( 'text' => true, 'qty' => true, 'price' => true, 'add_remove' => true );
		$swaps  = array(
			array( 'type' => 'option', 'line' => 0, 'field' => 'description', 'start' => 0, 'end' => 5, 'from' => 'White', 'to' => 'Black', 'slot' => 'frame_color', 'option' => 'black' ),
			array( 'type' => 'option', 'line' => 0, 'field' => 'description', 'start' => 13, 'end' => 18, 'from' => 'plain', 'to' => 'fancy', 'slot' => 'style', 'option' => 'fancy' ),
		);
		$res = ZEST_Line_Edits::apply( self::lines(), $swaps, $rights, self::map() );
		$this->assertTrue( $res['ok'], (string) ( $res['error'] ?? '' ) );
		$this->assertSame( 'Black frame, fancy', $res['lines'][0]['description'] );
		$this->assertCount( 2, $res['log'] );
		$this->assertSame( 'option', $res['log'][0]['type'] );
		$this->assertSame( 'frame_color', $res['log'][0]['slot'] );
		$this->assertStringContainsString( '"White" to "Black"', ZEST_Line_Edits::summary( $res['log'] )[0] );

		$this->assertFalse( ZEST_Line_Edits::apply( self::lines(), $swaps, $rights, null )['ok'], 'no map: nothing guessed' );
		$this->assertFalse( ZEST_Line_Edits::apply( self::lines(), $swaps, array( 'text' => false ) + $rights, self::map() )['ok'], 'no wording right' );
		$overlap = array( $swaps[0], array( 'start' => 2, 'end' => 7 ) + $swaps[1] );
		$this->assertFalse( ZEST_Line_Edits::apply( self::lines(), $overlap, $rights, self::map() )['ok'] );
		$both = array( $swaps[0], array( 'type' => 'text', 'line' => 0, 'field' => 'description', 'to' => 'Something else' ) );
		$this->assertFalse( ZEST_Line_Edits::apply( self::lines(), $both, $rights, self::map() )['ok'], 'a typed rewording and a swap on one field at once' );
		$wrong_family = array( array( 'type' => 'option', 'line' => 1, 'field' => 'description', 'start' => 0, 'end' => 5, 'from' => 'Small', 'to' => 'Black', 'slot' => 'frame_color', 'option' => 'black' ) );
		$this->assertFalse( ZEST_Line_Edits::apply( self::lines(), $wrong_family, $rights, self::map() )['ok'] );
	}

	public function test_the_map_in_use_is_the_hubs_then_the_packs_then_none(): void {
		$this->assertNull( ZEST_Line_Options::get(), 'Core ships no catalog' );
		$this->assertSame( 'none', ZEST_Line_Options::status()['from'] );
		$GLOBALS['zdz_test_options'][ ZEST_Line_Options::PACK_OPTION ] = self::map();
		ZEST_Line_Options::flush();
		$this->assertSame( 'pack', ZEST_Line_Options::status()['from'] );
		$hub = self::map();
		$hub['built'] = '2026-02-02';
		$GLOBALS['zdz_test_options'][ ZEST_Line_Options::OPTION ] = array( 'map' => $hub, 'fetched_at' => 1 );
		ZEST_Line_Options::flush();
		$this->assertSame( '2026-02-02', ZEST_Line_Options::get()['built'] );
		$GLOBALS['zdz_test_options'][ ZEST_Line_Options::OPTION ] = array( 'map' => array( 'map_version' => 1 ) );
		ZEST_Line_Options::flush();
		$this->assertSame( '2026-01-01', ZEST_Line_Options::get()['built'], 'a broken hub copy falls back to the pack\'s' );
		$fb = ZEST_Line_Options::for_browser( '' );
		$this->assertArrayHasKey( 'map', $fb );
		$this->assertArrayNotHasKey( 'map', ZEST_Line_Options::for_browser( $fb['map_fp'] ), 'the browser\'s copy is current: not sent again' );
	}

	public function test_the_pack_carries_the_option_map_the_handle_rule_and_the_hand_out_codes(): void {
		$p = ZEST_Pack::plan( array(
			'catalog' => array( 'estimate' => array( 'line_options' => self::map(), 'other' => 1 ) ),
			'rules'   => array( 'estimates' => array(
				'door_handle' => array( 'enabled' => true, 'housing_words' => array( 'widget' ), 'reference_view' => 'outside' ),
				'reassign'    => array( 'from_codes' => array( 'ab', 'CD' ) ),
			) ),
		) );
		$this->assertSame( self::map(), $p['writes']['zest_line_options_pack'] );
		$this->assertSame( array( 'enabled' => true, 'housing_words' => array( 'widget' ), 'reference_view' => 'outside' ), $p['writes']['zest_door_handle'] );
		$this->assertSame( array( 'AB', 'CD' ), $p['writes']['zest_reassign_from_codes'] );
		$this->assertContains( 'catalog.estimate.other', $p['unconsumed'] );

		$bad = ZEST_Pack::plan( array(
			'catalog' => array( 'estimate' => array( 'line_options' => array( 'map_version' => 1 ) ) ),
			'rules'   => array( 'estimates' => array( 'door_handle' => array( 'reference_view' => 'sideways' ), 'reassign' => array( 'from_codes' => array( 'TOOLONG' ) ) ) ),
		) );
		$this->assertSame( array(), $bad['writes'] );
		$this->assertCount( 3, $bad['refused'] );
		$this->assertContains( 'zest_line_options_pack', ZEST_Pack::OPTIONS, 'revert puts it back too' );
	}
}
