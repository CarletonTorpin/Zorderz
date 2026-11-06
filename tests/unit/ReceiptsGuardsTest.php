<?php
/**
 * Receipts: one customer's receipt stays on that customer's paperwork.
 *
 *   - the same-customer link-write check (a link is written only onto a document of the
 *     receipt's own, known customer; blanking is always allowed);
 *   - the invoice partition (anchor customer, other/unknown customers dropped fail-closed,
 *     the item-tag filter failing open);
 *   - the two-job page reuse guard (a second job at the same address gets its own page);
 *   - the invoice-reading progress caption names no model or provider.
 *
 * Neutral data only.
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

final class ReceiptsGuardsTest extends TestCase {

	public static function setUpBeforeClass(): void {
		require_once dirname( __DIR__, 2 ) . '/zorderz-apps/apps/receipts/includes/class-zrcpt-guards.php';
	}

	public function test_link_write_needs_the_same_known_customer(): void {
		$url = 'https://example.com/receipt/a-b-c-d/';
		$this->assertTrue( ZRCPT_Guards::link_write_allowed( $url, '101', '101' ) );
		$this->assertFalse( ZRCPT_Guards::link_write_allowed( $url, '101', '202' ), 'another customer' );
		$this->assertFalse( ZRCPT_Guards::link_write_allowed( $url, '', '101' ), 'receipt customer unresolved' );
		$this->assertFalse( ZRCPT_Guards::link_write_allowed( $url, '101', '' ), 'document customer unknown' );
		$this->assertFalse( ZRCPT_Guards::link_write_allowed( $url, '', '' ) );
		$this->assertTrue( ZRCPT_Guards::link_write_allowed( '', '', '202' ), 'blanking a link is always allowed' );
	}

	public function test_partition_anchors_on_the_first_kept_invoice(): void {
		$docs = array(
			'1001' => array( 'customerid' => '7' ),
			'1002' => array( 'customerid' => '7' ),
			'1003' => array( 'customerid' => '9' ),
			'1004' => array( 'customerid' => '' ),
		);
		$p = ZRCPT_Guards::partition_invoices( $docs, false, static function () { return true; } );
		$this->assertSame( array( '1001', '1002' ), $p['kept'] );
		$this->assertSame( '7', $p['anchor'] );
		$this->assertSame( array( '1003', '1004' ), $p['dropped_customer'], 'other and unknown customers fail closed' );
		$this->assertFalse( $p['fail_open'] );
	}

	public function test_tag_filter_drops_untagged_and_fails_open_only_when_all_go(): void {
		$docs = array(
			'2001' => array( 'customerid' => '5', 'tagged' => false ),
			'2002' => array( 'customerid' => '5', 'tagged' => true ),
			'2003' => array( 'customerid' => '6', 'tagged' => true ),
		);
		$has = static function ( array $d ) { return ! empty( $d['tagged'] ); };
		$p   = ZRCPT_Guards::partition_invoices( $docs, true, $has );
		$this->assertSame( array( '2002' ), $p['kept'] );
		$this->assertSame( array( '2001' ), $p['dropped_tag'] );
		$this->assertSame( array( '2003' ), $p['dropped_customer'] );

		$none = array(
			'3001' => array( 'customerid' => '5' ),
			'3002' => array( 'customerid' => '8' ),
		);
		$q = ZRCPT_Guards::partition_invoices( $none, true, static function () { return false; } );
		$this->assertTrue( $q['fail_open'] );
		$this->assertSame( array( '3001' ), $q['kept'], 'fail-open still keeps one customer' );
		$this->assertSame( array( '3002' ), $q['dropped_customer'] );
		$this->assertSame( array(), $q['dropped_tag'] );
	}

	public function test_partition_skips_unresolved_numbers(): void {
		$p = ZRCPT_Guards::partition_invoices( array( '4001' => array(), '4002' => array( 'customerid' => '3' ) ), false, static function () { return true; } );
		$this->assertSame( array( '4002' ), $p['kept'] );
		$this->assertSame( '3', $p['anchor'] );
	}

	public function test_second_job_at_the_same_address_gets_its_own_page(): void {
		$this->assertTrue( ZRCPT_Guards::reuse_allowed( true, array( '5001' ), array( '5002' ) ), 'an invoice match is the same job' );
		$this->assertFalse( ZRCPT_Guards::reuse_allowed( false, array( '5001' ), array( '5002' ) ), 'address match bound to another invoice' );
		$this->assertTrue( ZRCPT_Guards::reuse_allowed( false, array( 'INV-5001' ), array( '5001', '5003' ) ), 'overlap means the same job' );
		$this->assertTrue( ZRCPT_Guards::reuse_allowed( false, array(), array( '5002' ) ), 'an unbound page may be reused' );
	}

	public function test_invoice_reading_caption_names_no_model_or_provider(): void {
		$root = dirname( __DIR__, 2 ) . '/zorderz-apps/apps/receipts/';
		$php  = (string) file_get_contents( $root . 'includes/class-zrcpt-receipt.php' );
		$this->assertSame( 1, preg_match( "/\\['Sending invoice to AI[^\\]]*\\]/u", $php, $m ), 'the generator step list is present' );
		$this->assertDoesNotMatchRegularExpression( '/gemini|claude|opus|sonnet|gpt|llama|mistral|poe\b/i', $m[0] );
		$js = (string) file_get_contents( $root . 'assets/js/widget.js' );
		$this->assertSame( 1, preg_match( '/[^\n]*is reading the invoice[^\n]*/', $js, $j ) );
		$this->assertDoesNotMatchRegularExpression( '/gemini|claude|opus|sonnet|gpt|llama|mistral/i', $j[0] );
	}
}
