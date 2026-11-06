<?php
/**
 * Receipts: the words come from the platform's one Ai router, and the page around them is built here (ZRCPT_Writer).
 *
 *   - one call through ZDZ_Core_LLM::ask as the caller 'receipts' (its CALLERS row reads documents, never on the shared
 *     device), with this app's own instructions and an answer shape that names no model and carries no customer detail;
 *   - the invoice goes as text (a looked-up job), a picture or a PDF (read_document); HEIC unconverted is refused in words;
 *   - no photo, link or price goes to the model; the page places the checked photos in the generator's order;
 *   - the model's words are decoded, stripped of markup, bounded and escaped; an empty street falls back to the
 *     looked-up address, else the receipt is refused;
 *   - the page's gallery keeps the shape photo remove/reorder rewrites, and survives the receipt sanitizer;
 *   - the generator no longer posts to a gateway itself, and the house check comes before the call.
 *
 * Neutral data only.
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/llm-test-doubles.php'; // the platform's router, as the router tests load it
require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-private.php';

if ( ! function_exists( '_n' ) ) {
	function _n( $one, $many, $n, $d = null ) { return 1 === (int) $n ? $one : $many; }
}
if ( ! function_exists( 'sanitize_title' ) ) {
	function sanitize_title( $s ) { return trim( preg_replace( '/[^a-z0-9]+/', '-', strtolower( (string) $s ) ), '-' ); }
}
if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( $s ) { return trim( strip_tags( (string) $s ) ); }
}
if ( ! defined( 'ZRCPT_COUNT_MARKER' ) ) {
	define( 'ZRCPT_COUNT_MARKER', 'ZDZ_RCPT_COUNT' );
}

$zrcpt_wr_dir = dirname( __DIR__, 2 ) . '/zorderz-apps/apps/receipts/includes/';
require_once $zrcpt_wr_dir . 'class-zrcpt-sanitize.php';
require_once $zrcpt_wr_dir . 'class-zrcpt-writer.php';
unset( $zrcpt_wr_dir );

/** A router that answers from a script and remembers what it was asked. */
class ZRCPT_Test_Writer_Router extends ZDZ_Core_LLM {
	public $calls  = array();
	public $answer = null;
	public function handle( $role, array $request, array $ctx ) {
		$this->calls[] = array( 'role' => $role, 'request' => $request, 'ctx' => $ctx );
		return $this->answer;
	}
}

final class ReceiptsWriterTest extends TestCase {

	protected function tearDown(): void {
		ZDZ_Core_LLM::set_instance( null );
	}

	private static function words(): array {
		return array(
			'address_short' => '12 Example Lane',
			'headline'      => 'We completed 12 units at your home.',
			'unit_summary'  => '12 units',
			'sections'      => array( array( 'heading' => 'What was done', 'items' => array( 'Fitted every unit', 'Checked each fixing' ) ) ),
		);
	}

	private static function src( string $rel ): string {
		return (string) file_get_contents( dirname( __DIR__, 2 ) . '/zorderz-apps/apps/receipts/' . $rel );
	}

	/* ── the request ──────────────────────────────────────────────────────── */

	public function test_receipts_is_a_registered_document_caller_never_on_the_shared_device(): void {
		$this->assertSame( 'receipts', ZRCPT_Writer::CALLER );
		$this->assertTrue( ZDZ_Core_LLM::CALLERS['receipts']['documents'] );
		$this->assertFalse( ZDZ_Core_LLM::CALLERS['receipts']['kiosk_safe'] );
	}

	public function test_the_invoice_part_by_kind(): void {
		$t = ZRCPT_Writer::invoice_part( base64_encode( "Document: Invoice #1001\nLine one" ), 'text/plain' );
		$this->assertSame( 'text', $t['type'] );
		$this->assertStringContainsString( 'Line one', $t['text'] );
		$this->assertSame( array( 'type' => 'pdf', 'data' => 'QUJD' ), ZRCPT_Writer::invoice_part( "QU JD\n", 'application/pdf' ) );
		$this->assertSame( 'image/jpeg', ZRCPT_Writer::invoice_part( 'QUJD', 'image/jpg' )['media_type'] );
		$this->assertSame( 'image', ZRCPT_Writer::invoice_part( 'QUJD', 'image/webp' )['type'] );
		$this->assertInstanceOf( WP_Error::class, ZRCPT_Writer::invoice_part( 'QUJD', 'image/heic' ), 'an unconverted HEIC is said, never sent' );
		$this->assertInstanceOf( WP_Error::class, ZRCPT_Writer::invoice_part( 'QUJD', 'application/zip' ) );
		$this->assertNull( ZRCPT_Writer::invoice_part( null, 'application/pdf' ) );
	}

	public function test_the_request_carries_facts_and_this_apps_instructions_only(): void {
		$text = ZRCPT_Writer::request( 'Customer: Alex Example', ZRCPT_Writer::invoice_part( base64_encode( 'Line one' ), 'text/plain' ), 3 );
		$this->assertSame( 'write', $text['role'], 'a looked-up invoice is text' );
		$this->assertTrue( $text['request']['system_company'], 'the instructions are the app\'s own words' );
		$parts = $text['request']['messages'][0]['content'];
		$this->assertCount( 2, $parts );
		$this->assertArrayNotHasKey( 'company', $parts[0], 'the facts are never marked as business text: they carry the customer' );
		$this->assertStringContainsString( 'The page shows 3 photos', $parts[0]['text'] );

		$pdf = ZRCPT_Writer::request( 'Facts', ZRCPT_Writer::invoice_part( 'QUJD', 'application/pdf' ), 0 );
		$this->assertSame( 'read_document', $pdf['role'], 'a PDF or picture is read as a document' );

		$sys = ZRCPT_Writer::system_prompt();
		$this->assertStringContainsString( 'ZDZ_RCPT_COUNT: N', $sys, 'the authoritative count line is named' );
		$this->assertStringContainsString( 'Never write a price, an amount, a payment state', $sys );
		$this->assertStringContainsString( 'Copy a code exactly', $sys );
		$this->assertStringNotContainsString( 'http', $sys );

		$json = json_encode( ZRCPT_Writer::schema() );
		foreach ( array( 'model', 'claude', 'gpt', 'gemini', 'poe' ) as $w ) {
			$this->assertStringNotContainsString( '"' . $w, strtolower( $json ), 'no model is named' );
		}
		$this->assertStringNotContainsString( 'description', $json, 'the shape carries field names only (a provider may keep a schema)' );
	}

	/* ── the answer ───────────────────────────────────────────────────────── */

	public function test_words_are_decoded_stripped_and_bounded(): void {
		$decode = static function ( $s ) {
			return str_replace( '⟦e:abcd1⟧', '12 Example Lane', (string) $s );
		};
		$json = array(
			'address_short' => '⟦e:abcd1⟧',
			'headline'      => 'Done <b>well</b>',
			'unit_summary'  => '12 units',
			'sections'      => array_fill( 0, 9, array( 'heading' => 'Part', 'items' => array_fill( 0, 14, 'Item' ) ) ),
		);
		$w = ZRCPT_Writer::words( $json, $decode );
		$this->assertSame( '12 Example Lane', $w['address_short'], 'the customer\'s street comes back on their own receipt' );
		$this->assertSame( 'Done well', $w['headline'], 'markup is dropped, never shown' );
		$this->assertCount( ZRCPT_Writer::MAX_SECTIONS, $w['sections'] );
		$this->assertCount( ZRCPT_Writer::MAX_ITEMS, $w['sections'][0]['items'] );
	}

	public function test_a_missing_street_falls_back_or_refuses(): void {
		$id   = static function ( $s ) { return (string) $s; };
		$json = array( 'address_short' => '', 'headline' => 'Done', 'unit_summary' => '', 'sections' => array() );
		$this->assertSame( '34 Sample Road', ZRCPT_Writer::words( $json, $id, "34 Sample Road\nSpringfield" )['address_short'] );
		$this->assertInstanceOf( WP_Error::class, ZRCPT_Writer::words( $json, $id ) );
		$this->assertInstanceOf( WP_Error::class, ZRCPT_Writer::words( array( 'address_short' => 'x', 'headline' => '', 'unit_summary' => '', 'sections' => array() ), $id ), 'no words, no receipt' );
		$this->assertInstanceOf( WP_Error::class, ZRCPT_Writer::words( 'not json', $id ) );
	}

	/* ── the page ─────────────────────────────────────────────────────────── */

	public function test_the_page_escapes_the_words_and_places_the_checked_photos(): void {
		$w             = self::words();
		$w['headline'] = '<script>alert(1)</script> Done';
		$html          = ZRCPT_Writer::page( $w, array(
			'heading'       => 'Completed Work',
			'install_date'  => 'March 3rd, 2026',
			'photo_urls'    => array( 'https://example.com/a.jpg', 'javascript:alert(2)', 'https://example.com/b.jpg' ),
			'invoice_links' => array( '1001' => 'https://example.com/i/1#/link/x' ),
			'closing_line'  => 'Thank you',
		) );
		$this->assertStringNotContainsString( '<script>alert(1)', $html );
		$this->assertStringContainsString( '&lt;script&gt;', $html );
		$this->assertSame( 2, substr_count( $html, 'class="gallery-item"' ), 'only web addresses become photos' );
		$this->assertLessThan( strpos( $html, 'b.jpg' ), strpos( $html, 'a.jpg' ), 'in the generator\'s order' );
		$this->assertStringNotContainsString( 'View invoice #1001', $html, 'a single invoice link is the invoice panel\'s' );
		$this->assertStringContainsString( 'Thank you', $html );
		$this->assertStringContainsString( 'Work completed: March 3rd, 2026', $html );
		// photo remove/reorder rewrites the gallery with this pattern
		$this->assertMatchesRegularExpression( '/<div class="gallery">.*?<\/div>(\s*<div class="gallery-note">)/s', $html );

		$two = ZRCPT_Writer::page( $w, array( 'invoice_links' => array( '1001' => 'https://example.com/1', '1002' => 'https://example.com/2' ) ) );
		$this->assertStringContainsString( 'View invoice #1002', $two, 'a receipt covering several invoices lists each' );
	}

	public function test_the_page_survives_the_receipt_sanitizer(): void {
		$html  = ZRCPT_Writer::page( self::words(), array( 'photo_urls' => array( 'https://example.com/a.jpg' ) ) );
		$clean = ZRCPT_Sanitize::clean( $html );
		$this->assertStringContainsString( 'class="gallery-item"', $clean );
		$this->assertStringContainsString( 'https://example.com/a.jpg', $clean );
		$this->assertStringContainsString( 'Fitted every unit', $clean );
		$this->assertStringContainsString( 'data-zrcpt-print', $clean );
	}

	public function test_the_receipt_record(): void {
		$r = ZRCPT_Writer::receipt( self::words(), array( 'install_date' => 'March 3rd, 2026' ) );
		$this->assertSame( '12-example-lane', $r['vanity_slug'] );
		$this->assertSame( '12 units', $r['vent_summary'] );
		$this->assertSame( 'March 3rd, 2026', $r['install_date'] );
		$this->assertStringContainsString( '12 Example Lane', base64_decode( $r['html_base64'] ) );
	}

	/* ── the call ─────────────────────────────────────────────────────────── */

	public function test_one_call_through_the_router_as_receipts(): void {
		$r         = new ZRCPT_Test_Writer_Router();
		$r->answer = array( 'text' => '', 'json' => self::words(), 'model' => 'm' );
		ZDZ_Core_LLM::set_instance( $r );
		$out = ZRCPT_Writer::write( 'Facts', null, array( 'photo_urls' => array( 'https://example.com/a.jpg' ), 'install_date' => 'March 3rd, 2026' ) );
		$this->assertIsArray( $out );
		$this->assertCount( 1, $r->calls );
		$this->assertSame( 'receipts', $r->calls[0]['ctx']['caller'] );
		$this->assertSame( 'write', $r->calls[0]['role'] );
		$this->assertGreaterThan( time(), $r->calls[0]['ctx']['deadline'] );
		$this->assertArrayNotHasKey( 'model', $r->calls[0]['request'], 'no model named by the app' );
		$this->assertStringNotContainsString( 'example.com/a.jpg', json_encode( $r->calls[0]['request'] ), 'no photo address goes to the model' );

		$r->answer = new WP_Error( 'zdz_llm_budget', 'No monthly Ai budget is set, so nothing was sent.' );
		$err       = ZRCPT_Writer::write( 'Facts', null, array() );
		$this->assertInstanceOf( WP_Error::class, $err );
		$this->assertSame( 'No monthly Ai budget is set, so nothing was sent.', $err->get_error_message(), 'a refusal is said as one' );

		$r->answer = array( 'text' => '', 'json' => null );
		$this->assertInstanceOf( WP_Error::class, ZRCPT_Writer::write( 'Facts', null, array() ), 'never an empty receipt' );
		$this->assertInstanceOf( WP_Error::class, ZRCPT_Writer::write( 'Facts', new WP_Error( 'zrcpt_invoice_format', 'x' ), array() ) );
		$this->assertCount( 3, $r->calls, 'an unreadable invoice is refused before any call' );
	}

	public function test_the_generator_uses_the_writer_after_the_house_check(): void {
		$gen = self::src( 'includes/class-zrcpt-receipt.php' );
		$this->assertStringNotContainsString( 'wp_remote_post( self::API_ENDPOINT', $gen );
		$this->assertStringNotContainsString( 'api.poe.com', $gen );
		$this->assertStringNotContainsString( 'call_poe_bot', $gen );
		$gate  = strpos( $gen, 'ZRCPT_Houses::gate(' );
		$write = strpos( $gen, 'ZRCPT_Writer::write(' );
		$this->assertTrue( false !== $gate && false !== $write );
		$this->assertLessThan( $write, $gate, 'every photo is checked against the house before the call' );
		$this->assertStringNotContainsString( '"\nPhotos:\n"', $gen, 'the photo list no longer goes to the model' );
		$this->assertStringNotContainsString( 'ZRCPT_INVOICE_URL', $gen, 'no link goes to the model' );
		$this->assertStringContainsString( "require_once ZRCPT_DIR . 'includes/class-zrcpt-writer.php';", self::src( 'app.php' ) );
	}
}
