<?php
/**
 * The inbox's supplier-order reader and its compose helpers — every decision that is pure:
 *
 *   - which senders may stand for a supplier (never the business's own domain, a whole free-mail domain or a
 *     public suffix), and which folders are never a source;
 *   - what counts as an order number (a strict shape: never a phone, a year, a date or a quantity) and the digits
 *     an order is matched on;
 *   - what a line keeps: only allow-listed product and colour words, so a customer or job name never rides along;
 *     with no catalog words supplied, a line keeps no product label at all;
 *   - the Vault documents an email may carry, the sent-mail topic words, and the person/topic split.
 *
 * Neutral data only: example.com / example.test addresses, made-up numbers.
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'has_filter' ) ) {
	function has_filter( $hook ) { return isset( $GLOBALS['zdz_test_filters'][ $hook ] ); }
}

require_once dirname( __DIR__, 2 ) . '/zorderz-apps/apps/inbox/includes/class-zib-supplier-feed.php';
require_once dirname( __DIR__, 2 ) . '/zorderz-apps/apps/inbox/includes/class-zib-compose.php';
require_once dirname( __DIR__, 2 ) . '/zorderz-apps/apps/inbox/includes/class-zib-gatekeeper.php';
require_once dirname( __DIR__, 2 ) . '/zorderz-apps/apps/inbox/includes/class-zib-zana-bridge.php';

final class InboxSupplierFeedTest extends TestCase {

	protected function tearDown(): void {
		unset( $GLOBALS['zdz_test_filters'] );
	}

	/* ── the supplier list ────────────────────────────────────────────── */

	public function test_senders_are_cleaned_and_own_or_wide_domains_refused(): void {
		$s = ZIB_Supplier_Feed::normalize_senders( array( 'Orders@Example-Supplier.test', 'example-hardware.test', 'not an address', 'x@', '' ) );
		$this->assertSame( array( 'orders@example-supplier.test', '@example-hardware.test' ), $s );

		$r = ZIB_Supplier_Feed::normalize_senders(
			array( '@example.com', 'sales@example.com', 'x@mail.example.com', 'gmail.com', '@outlook.com', 'co.uk', 'casey.sample@gmail.com', '@example-supplier.co.uk' ),
			array( 'example.com' )
		);
		$this->assertSame( array( 'casey.sample@gmail.com', '@example-supplier.co.uk' ), $r, 'own domain, whole free-mail domains and public suffixes are refused' );
	}

	public function test_sender_matching_is_exact_or_by_domain(): void {
		$s = array( 'orders@example-supplier.test', '@example-hardware.test' );
		$this->assertTrue( ZIB_Supplier_Feed::sender_matches( 'orders@example-supplier.test', $s ) );
		$this->assertFalse( ZIB_Supplier_Feed::sender_matches( 'sales@example-supplier.test', $s ) );
		$this->assertTrue( ZIB_Supplier_Feed::sender_matches( 'noreply@mail.example-hardware.test', $s ) );
		$this->assertFalse( ZIB_Supplier_Feed::sender_matches( 'x@notexample-hardware.test', $s ) );
		$this->assertFalse( ZIB_Supplier_Feed::sender_matches( 'x@example-hardware.test.evil.test', $s ) );
		$this->assertFalse( ZIB_Supplier_Feed::sender_matches( '', $s ) );
	}

	public function test_junk_and_deleted_are_never_a_source(): void {
		$this->assertTrue( ZIB_Supplier_Feed::skip_folder( 'junkemail', '' ) );
		$this->assertTrue( ZIB_Supplier_Feed::skip_folder( '', 'Deleted Items' ) );
		$this->assertFalse( ZIB_Supplier_Feed::skip_folder( 'inbox', 'Inbox' ) );
		$this->assertTrue( ZIB_Supplier_Feed::skip_folder( 'other', 'Courrier indésirable', 'junkemail' ), 'by wellKnownName first' );
		$this->assertFalse( ZIB_Supplier_Feed::skip_folder( 'other', 'Bulk Orders', '' ) );
	}

	public function test_too_wide_domains(): void {
		foreach ( array( 'gmail.com', 'san.rr.com', 'yahoo.co.uk', 'co.uk', 'k12.ca.us' ) as $d ) {
			$this->assertTrue( ZIB_Supplier_Feed::too_wide( $d ), $d );
		}
		foreach ( array( 'example.de', 'mail.supplier.example', 'example-supplier.co.uk' ) as $d ) {
			$this->assertFalse( ZIB_Supplier_Feed::too_wide( $d ), $d );
		}
	}

	/* ── order numbers ────────────────────────────────────────────────── */

	public function test_order_numbers_have_a_strict_shape(): void {
		$this->assertTrue( ZIB_Supplier_Feed::ref_shape_ok( 'SO-10422' ) );
		$this->assertTrue( ZIB_Supplier_Feed::ref_shape_ok( '10422' ) );
		$this->assertTrue( ZIB_Supplier_Feed::ref_shape_ok( 'INV00012345' ) );
		$this->assertFalse( ZIB_Supplier_Feed::ref_shape_ok( 'SAMPLE-4410' ), 'a word is not a number' );
		$this->assertFalse( ZIB_Supplier_Feed::ref_shape_ok( '555-555-0100' ), 'a phone number' );
		$this->assertFalse( ZIB_Supplier_Feed::ref_shape_ok( '2026' ), 'a year' );
		$this->assertFalse( ZIB_Supplier_Feed::ref_shape_ok( '2026-10-16' ), 'a date' );
		$this->assertFalse( ZIB_Supplier_Feed::ref_shape_ok( '120' ), 'too few digits: a quantity' );
	}

	public function test_refs_read_numbers_and_rank_orders_first(): void {
		$r = ZIB_Supplier_Feed::refs( "Invoice INV-7781 for your order\nOrder #10422 has shipped\nCall 555-555-0100" );
		$this->assertSame( array( 'order', 'invoice' ), array_column( $r, 'kind' ) );
		$this->assertSame( array( '10422', '7781' ), array_column( $r, 'core' ) );
		$this->assertSame( array(), ZIB_Supplier_Feed::refs( 'Order 1000 rolls today' ), 'a number followed by a unit is a quantity' );
		$this->assertSame( array(), ZIB_Supplier_Feed::refs( 'So 1000 are left' ), 'short keywords count only in capitals' );
	}

	public function test_core_of_matches_the_same_order_however_written(): void {
		$this->assertSame( '10422', ZIB_Supplier_Feed::core_of( 'SO-10422' ) );
		$this->assertSame( '10422', ZIB_Supplier_Feed::core_of( '10422-01' ), 'a split shipment' );
		$this->assertSame( '12345', ZIB_Supplier_Feed::core_of( 'INV00012345' ) );
		$this->assertSame( '100155', ZIB_Supplier_Feed::core_of( '1001-55' ) );
		$this->assertSame( '202610422', ZIB_Supplier_Feed::core_of( 'SO-2026-10422' ), 'the year stays in' );
	}

	/* ── lines: product words come from the catalog ───────────────────── */

	public function test_a_line_keeps_only_allow_listed_words(): void {
		$allow = array( 'cloth', 'fine', 'roll', 'gray' );
		$this->assertSame( 'fine cloth gray', ZIB_Supplier_Feed::product_words_only( 'Fine-Cloth for Jordan-Residence, gray', $allow, array( 'roll' ) ) );
		$this->assertSame( '', ZIB_Supplier_Feed::product_words_only( 'roll', $allow, array( 'roll' ) ), 'a unit noun alone is not a label' );
	}

	public function test_lines_in_three_shapes(): void {
		$allow = array( 'cloth', 'fine', 'widget', 'black' );
		$l     = ZIB_Supplier_Feed::lines( "Fine Cloth Roll x 3\n2 x Black Widget\n4 rolls of fine cloth 36\" x 50'\nTotal due 120.00", $allow, array() );
		$this->assertCount( 3, $l );
		$this->assertSame( array( 'fine cloth', 3.0, 'roll' ), array( $l[0]['description'], $l[0]['qty'], $l[0]['unit'] ) );
		$this->assertSame( array( 'black widget', 2.0 ), array( $l[1]['description'], $l[1]['qty'] ) );
		$this->assertSame( "fine cloth 36\"x50'", $l[2]['description'] );
	}

	public function test_without_catalog_words_a_line_keeps_no_product_label(): void {
		$l = ZIB_Supplier_Feed::lines( "Sample Product for Riley Example x 3\n2 x Black Gizmo", null, null );
		$this->assertSame( array( 'black' ), array( $l[0]['description'] ?? '' ), 'only the colour survives; names never ride along' );
	}

	public function test_catalog_words_arrive_by_filter(): void {
		$GLOBALS['zdz_test_filters']['zib_supplier_product_words'] = function ( $w ) { return array_merge( (array) $w, array( 'Gizmo', 'not a word!' ) ); };
		$words = ZIB_Supplier_Feed::product_words();
		$this->assertContains( 'gizmo', $words );
		$this->assertContains( 'black', $words, 'Core colour words stay' );
		$this->assertNotContains( 'not a word!', $words );
	}

	public function test_message_key_is_one_way_and_stable(): void {
		$a = ZIB_Supplier_Feed::message_key( 3, md5( 'x' ), '<abc@example.test>' );
		$b = ZIB_Supplier_Feed::message_key( 4, md5( 'y' ), '<ABC@example.test>' );
		$this->assertSame( $a, $b, 'the RFC Message-ID makes two copies one message' );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $a );
	}

	/* ── compose + sent-mail topics ───────────────────────────────────── */

	public function test_vault_documents_on_an_email_are_normalized(): void {
		$d = ZIB_Compose::normalize_docs( '[{"id":4,"how":"link","days":30},{"id":4},{"id":0},{"id":9,"how":"zip","days":7}]' );
		$this->assertSame( array(
			array( 'id' => 4, 'how' => 'link', 'days' => 30 ),
			array( 'id' => 9, 'how' => 'attach', 'days' => 14 ),
		), $d );
		$many = array();
		for ( $i = 1; $i <= 9; $i++ ) {
			$many[] = array( 'id' => $i );
		}
		$this->assertCount( ZIB_Compose::MAX_DOCS, ZIB_Compose::normalize_docs( $many ) );
	}

	public function test_a_private_document_is_named_only_by_its_kind(): void {
		$this->assertSame( 'PDF, 1.5 MB', ZIB_Compose::kind_and_size( 'application/pdf', 1572864 ) );
		$this->assertSame( 'PDF, 922 KB', ZIB_Compose::kind_and_size( 'application/pdf', 943718 ) );
		$this->assertSame( 'image', ZIB_Compose::kind( 'image/x-unknown' ) );
	}

	public function test_topic_terms_and_boolean(): void {
		$this->assertSame( array( 'sam', 'invoice' ), ZIB_Gatekeeper::topic_terms( "Sam's invoices" ) );
		$this->assertSame( array( 'morgan' ), ZIB_Gatekeeper::topic_terms( "Morgan's" ) );
		$this->assertSame( '+sam* +invoice*', ZIB_Gatekeeper::topic_boolean( array( 'sam', 'invoice', 'x' ) ) );
		$this->assertSame( '', ZIB_Gatekeeper::topic_boolean( array() ) );
	}

	public function test_person_and_topic_are_split_only_on_a_known_person(): void {
		$known = function ( $name ) { return 'jordan blake' === $name || 'jordan' === $name; };
		$this->assertSame( array( 'jordan blake', 'patio photos' ), ZIB_Zana_Bridge::split_person_topic( 'jordan blake patio photos', $known ) );
		$this->assertSame( array(), ZIB_Zana_Bridge::split_person_topic( 'casey patio', $known ), 'nobody known: no split' );
		$this->assertSame( array(), ZIB_Zana_Bridge::split_person_topic( 'jordan', $known ), 'a single word is never split' );
	}
}
