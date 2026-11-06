<?php
/**
 * Stock's pure decisions for supplier orders and the ask-bar lookup:
 *
 *   - the supplier list refuses the business's own domains, whole free-mail domains and public suffixes;
 *   - order numbers are matched on their digits, so the confirmation, the invoice and the shipping notice of one
 *     order are one order, and a year-prefixed number never collides with last year's;
 *   - a line's stock item is pre-picked only on a clear match (every word, its colour, a matching size, one item);
 *   - the lookup answers with item names first, quantities only, and "not counted yet" instead of a 0 nobody counted;
 *   - an item nobody has counted is never called low.
 *
 * Neutral catalog: made-up items, example.test suppliers.
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

if ( ! defined( 'ZSTOCK_APP_ID' ) ) {
	define( 'ZSTOCK_APP_ID', 'stock' );
}

require_once dirname( __DIR__, 2 ) . '/zorderz-apps/apps/stock/includes/class-zstock-orders.php';
require_once dirname( __DIR__, 2 ) . '/zorderz-apps/apps/stock/includes/class-zstock-suppliers.php';
require_once dirname( __DIR__, 2 ) . '/zorderz-apps/apps/stock/includes/class-zstock-engine.php';
require_once dirname( __DIR__, 2 ) . '/zorderz-apps/apps/stock/includes/class-zstock-lookup.php';
require_once dirname( __DIR__, 2 ) . '/zorderz-apps/apps/stock/includes/class-zstock-admin.php';

final class StockIncomingTest extends TestCase {

	protected function tearDown(): void {
		unset( $GLOBALS['zdz_test_user_roles'] );
	}

	private static function catalog(): array {
		return array(
			array( 'id' => 'fine-cloth-black', 'name' => 'Fine Cloth Roll Black 36x100', 'category' => 'Cloth', 'sku' => 'FM-36', 'unit' => 'roll', 'current_stock' => 4, 'par_level' => 6, 'reorder_point' => 2, 'counted' => true ),
			array( 'id' => 'fine-cloth-gray', 'name' => 'Fine Cloth Roll Gray 60x50', 'category' => 'Cloth', 'sku' => 'FM-60', 'unit' => 'roll', 'current_stock' => 0, 'par_level' => 2, 'reorder_point' => 1, 'counted' => false ),
			array( 'id' => 'edge-strip', 'name' => 'Edge Strip', 'category' => 'Cloth', 'sku' => 'ES-1', 'unit' => 'roll', 'current_stock' => 3, 'par_level' => 1, 'reorder_point' => 0, 'counted' => true ),
			array( 'id' => 'widget', 'name' => 'Widget', 'category' => 'Hardware', 'sku' => 'HW-WIDGETS', 'unit' => 'each', 'current_stock' => 12, 'par_level' => 10, 'reorder_point' => 5, 'counted' => true ),
		);
	}

	/* ── the supplier list ────────────────────────────────────────────── */

	public function test_supplier_list_refuses_own_and_wide_domains(): void {
		$rows = ZSTOCK_Suppliers::clean( array(
			array( 'name' => 'Example Supply', 'match' => 'Orders@Supplier.Example' ),
			array( 'name' => 'Example Hardware', 'match' => 'hardware.example' ),
			array( 'name' => 'Us', 'match' => '@example.com' ),
			array( 'name' => 'Free mail', 'match' => 'gmail.com' ),
			array( 'name' => 'A person at free mail', 'match' => 'casey.sample@gmail.com' ),
			array( 'name' => 'Suffix', 'match' => 'co.uk' ),
			array( 'name' => '', 'match' => 'orders@nameless.example' ),
			array( 'name' => 'Dup', 'match' => 'orders@supplier.example' ),
		), array( 'example.com' ) );
		$this->assertSame( array( 'orders@supplier.example', '@hardware.example', 'casey.sample@gmail.com' ), array_column( $rows, 'match' ) );
	}

	public function test_supplier_for_prefers_exact_then_longest_domain(): void {
		$list = array(
			array( 'name' => 'Wide', 'match' => '@supplier.example' ),
			array( 'name' => 'Billing', 'match' => '@billing.supplier.example' ),
			array( 'name' => 'Exact', 'match' => 'orders@supplier.example' ),
		);
		$this->assertSame( 'Exact', ZSTOCK_Suppliers::supplier_for( 'orders@supplier.example', $list )['name'] );
		$this->assertSame( 'Billing', ZSTOCK_Suppliers::supplier_for( 'ar@billing.supplier.example', $list )['name'] );
		$this->assertSame( 'Wide', ZSTOCK_Suppliers::supplier_for( 'sam@supplier.example', $list )['name'] );
		$this->assertNull( ZSTOCK_Suppliers::supplier_for( 'sam@other.example', $list ) );
	}

	public function test_registrable_domain(): void {
		$this->assertSame( 'supplier.example', ZSTOCK_Suppliers::registrable( '@billing.supplier.example' ) );
		$this->assertSame( 'supplier.co.uk', ZSTOCK_Suppliers::registrable( 'x@mail.supplier.co.uk' ) );
	}

	public function test_admin_supplier_lines_parse(): void {
		$rows = ZSTOCK_Admin::parse_supplier_lines( "Example Supply | orders@supplier.example\nno bar here\n | @x.example\r\nHardware Co|@hardware.example" );
		$this->assertSame( array(
			array( 'name' => 'Example Supply', 'match' => 'orders@supplier.example' ),
			array( 'name' => 'Hardware Co', 'match' => '@hardware.example' ),
		), $rows );
	}

	/* ── order numbers ────────────────────────────────────────────────── */

	public function test_one_order_however_many_emails(): void {
		foreach ( array( 'SO-10422', '10422', 'ORD10422', '10422-01' ) as $ref ) {
			$this->assertSame( '10422', ZSTOCK_Suppliers::core_of( $ref ), $ref );
		}
		$this->assertSame( '202610422', ZSTOCK_Suppliers::core_of( 'SO-2026-10422' ), 'a year-prefixed number keeps its year' );
		$this->assertNotSame( ZSTOCK_Suppliers::core_of( '1001-55' ), ZSTOCK_Suppliers::core_of( '1001-56' ), 'an account number first: two orders' );
	}

	public function test_refs_are_checked_again_on_the_stock_side(): void {
		$r = ZSTOCK_Suppliers::refs_of( array( 'refs' => array(
			array( 'kind' => 'invoice', 'ref' => 'INV-7781' ),
			array( 'kind' => 'order', 'ref' => 'SO-10422' ),
			array( 'kind' => 'order', 'ref' => '555-555-0100' ),
			array( 'kind' => 'order', 'ref' => '2026' ),
			array( 'kind' => 'order', 'ref' => 'SAMPLE-4410' ),
			array( 'kind' => 'order', 'ref' => '10422' ),
		) ) );
		$this->assertSame( array( 'SO-10422', 'INV-7781' ), array_column( $r, 'ref' ), 'orders first; a phone, a year, a word and a repeat are dropped' );
	}

	public function test_cores_field(): void {
		$this->assertSame( '|10422|7781|', ZSTOCK_Orders::cores_field( array( '10422', '7781', '10422', 'x12', '12' ) ) );
		$this->assertSame( '', ZSTOCK_Orders::cores_field( array() ) );
	}

	public function test_order_key_is_case_and_space_insensitive(): void {
		$this->assertSame( ZSTOCK_Orders::order_key( 'Example Supply', 'SO 10422' ), ZSTOCK_Orders::order_key( ' example  supply', 'so 10422 ' ) );
		$this->assertSame( '', ZSTOCK_Orders::order_key( 'Example Supply', '  ' ), 'no number: no key' );
	}

	/* ── which stock item a line is ───────────────────────────────────── */

	public function test_a_line_is_pre_picked_only_on_a_clear_match(): void {
		$items   = self::catalog();
		$units   = ZSTOCK_Suppliers::UNIT_WORD;
		$colours = ZSTOCK_Suppliers::COLOURS;
		$this->assertSame( 'fine-cloth-black', ZSTOCK_Suppliers::guess_item( 'fine cloth black 36"x100\'', $items, array(), $units, $colours ) );
		$this->assertSame( '', ZSTOCK_Suppliers::guess_item( 'fine cloth', $items, array(), $units, $colours ), 'two colours fit and the line says neither' );
		$this->assertSame( '', ZSTOCK_Suppliers::guess_item( 'fine cloth black 60x50', $items, array(), $units, $colours ), 'the size disagrees' );
		$this->assertSame( 'widget', ZSTOCK_Suppliers::guess_item( 'widgets', $items, array(), $units, $colours ) );
		$this->assertSame( '', ZSTOCK_Suppliers::guess_item( 'rolls', $items, array(), $units, $colours ), 'a unit alone names nothing' );
	}

	public function test_an_alias_expands_into_the_item_words(): void {
		$items = self::catalog();
		$this->assertSame( 'edge-strip', ZSTOCK_Suppliers::guess_item( 'es strip', $items, array( 'es' => array( 'edge' ) ), ZSTOCK_Suppliers::UNIT_WORD, ZSTOCK_Suppliers::COLOURS ) );
	}

	/* ── the lookup ───────────────────────────────────────────────────── */

	public function test_lookup_words(): void {
		$this->assertSame( array( 'fine', 'cloth', 'roll' ), ZSTOCK_Lookup::words( 'How many fine cloth rolls do we have available?' ) );
		$this->assertSame( array(), ZSTOCK_Lookup::words( 'is it on?' ) );
	}

	public function test_names_first_then_category(): void {
		list( $named, $also ) = ZSTOCK_Lookup::rank( self::catalog(), ZSTOCK_Lookup::words( 'cloth' ) );
		$this->assertSame( array( 'fine-cloth-black', 'fine-cloth-gray' ), array_column( $named, 'id' ) );
		$this->assertSame( array( 'edge-strip' ), array_column( $also, 'id' ), 'found only through its category: listed after the names' );
		list( $named2, $also2 ) = ZSTOCK_Lookup::rank( self::catalog(), ZSTOCK_Lookup::words( 'hardware' ) );
		$this->assertSame( array(), $named2 );
		$this->assertSame( array( 'widget' ), array_column( $also2, 'id' ), 'no name fits: the category answers' );
	}

	public function test_whole_words_only(): void {
		$this->assertFalse( ZSTOCK_Lookup::matches( array( 'name' => 'Bottom Rail' ), array( 'tom' ) ) );
		$this->assertTrue( ZSTOCK_Lookup::matches( array( 'name' => 'Widget', 'sku' => 'HW-WIDGETS' ), array( 'hw-widget' ) ), 'a SKU counts whole, singular' );
	}

	public function test_hit_is_quantities_only_and_says_not_counted_yet(): void {
		$cat  = self::catalog();
		$gray = ZSTOCK_Lookup::hit( $cat[1] );
		$this->assertSame( 'not counted yet', $gray['on_hand'] );
		$this->assertSame( 'uncounted', $gray['status'] );
		$this->assertSame( '', $gray['unit'] );
		$black = ZSTOCK_Lookup::hit( $cat[0] );
		$this->assertSame( 4, $black['on_hand'] );
		$this->assertSame( 'low', $black['status'] );
		$this->assertSame( array( 'name', 'category', 'unit', 'on_hand', 'status', 'counted' ), array_keys( $black ), 'never a cost, a value, a supplier or an order' );
	}

	public function test_lookup_descriptor_is_a_read(): void {
		$d = ZSTOCK_Lookup::get_capability_descriptor();
		$this->assertSame( 'stock.lookup', $d['verb'] );
		$this->assertFalse( $d['side_effect'] );
		$this->assertFalse( $d['kiosk'] );
		$this->assertSame( 'stock', $d['app'] );
		$this->assertFalse( $d['needs_app'] );
	}

	/* ── not counted yet ──────────────────────────────────────────────── */

	public function test_an_uncounted_item_is_never_low(): void {
		$this->assertSame( 'uncounted', ZSTOCK_Engine::status_of( array( 'current_stock' => 0, 'reorder_point' => 1, 'par_level' => 2, 'counted' => false ) ) );
		$this->assertSame( 'out', ZSTOCK_Engine::status_of( array( 'current_stock' => 0, 'reorder_point' => 1, 'par_level' => 2, 'counted' => true ) ), 'a counted zero is out' );
		$this->assertSame( 'good', ZSTOCK_Engine::status_of( array( 'current_stock' => 9, 'reorder_point' => 1, 'par_level' => 2, 'counted' => true ) ) );
	}

	public function test_public_order_carries_no_money_and_names_the_mailbox_owner_only(): void {
		$GLOBALS['zdz_test_user_roles'][7] = array( 'zdz_operator' );
		$o = @ZSTOCK_Orders::public_order( array(
			'id' => 3, 'supplier_name' => 'Example Supply', 'invoice_number' => 'SO-10422', 'invoice_date' => '2026-01-02',
			'status' => 'draft', 'receipts' => 0, 'source' => 'email', 'created_by' => 7, 'created_at' => '2026-01-02 08:00:00',
			'total_amount' => 999.0, 'file_url' => 'https://example.test/x.pdf', 'ai_raw_json' => '{}',
			'lines' => array( array( 'id' => 9, 'supplier_description' => 'fine cloth', 'quantity' => 5, 'received_qty' => 3, 'unit' => 'roll', 'item_id' => 'fine-cloth-black', 'item_name' => 'Fine Cloth' ) ),
		) );
		unset( $GLOBALS['zdz_test_user_roles'] );
		$this->assertSame( 'incoming', $o['status'], 'a legacy draft reads as incoming' );
		$this->assertIsString( $o['from'] );
		$this->assertStringNotContainsString( '@', $o['from'], 'a colleague\'s name at most, never an address' );
		$this->assertSame( 2.0, $o['lines'][0]['remaining'] );
		$this->assertArrayNotHasKey( 'total_amount', $o );
		$this->assertArrayNotHasKey( 'file_url', $o );
	}
}
