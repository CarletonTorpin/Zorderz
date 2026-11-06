<?php
/**
 * Receipts: model-written HTML is sanitized at the write, the public page carries a strict
 * Content-Security-Policy, and a receipt link works for the public only once approved.
 *
 * Loads the real ZRCPT_Sanitize and ZRCPT_Finalize, and the pure deciders of ZRCPT_Receipt
 * (the class file is loaded without booting it). Neutral data only.
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $u ) { return (string) $u; }
}

final class ReceiptsSanitizeGateTest extends TestCase {

	public static function setUpBeforeClass(): void {
		$dir = dirname( __DIR__, 2 ) . '/zorderz-apps/apps/receipts/includes/';
		require_once $dir . 'class-zrcpt-sanitize.php';
		require_once $dir . 'class-zrcpt-finalize.php';
		if ( ! class_exists( 'ZRCPT_Receipt' ) ) {
			require_once $dir . 'class-zrcpt-receipt.php';
		}
	}

	protected function setUp(): void {
		unset( $GLOBALS['zdz_test_filters']['zrcpt_receipt_font_hosts'], $GLOBALS['zdz_test_filters']['zrcpt_public_view_allowed'] );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['zdz_test_filters'] );
	}

	private function page( string $body ): string {
		return "<!DOCTYPE html>\n<html><head><title>Receipt for Example Co</title><style>.a > .b{color:#123}</style></head><body>" . $body . '</body></html>';
	}

	public function test_script_handlers_and_script_urls_are_removed(): void {
		$dirty = $this->page(
			'<p onclick="alert(1)">Hi Alex</p><script>alert(2)</script>'
			. '<a href="javascript:alert(3)">x</a><a href=" JaVaScRiPt:alert(4)">y</a>'
			. '<img src="x" onerror="alert(5)"><iframe src="https://example.com"></iframe>'
			. '<form action="https://example.com"><input name="q"></form>'
			. '<svg><animate attributeName="href" to="javascript:alert(6)"/></svg>'
			. '<div style="background:url(javascript:alert(7))">z</div>'
			. '<meta http-equiv="refresh" content="0;url=https://example.com">'
		);
		$clean = ZRCPT_Sanitize::clean( $dirty );
		$this->assertStringNotContainsString( '<script', strtolower( $clean ) );
		$this->assertStringNotContainsString( 'onclick', strtolower( $clean ) );
		$this->assertStringNotContainsString( 'onerror', strtolower( $clean ) );
		$this->assertStringNotContainsString( 'javascript:', strtolower( $clean ) );
		$this->assertStringNotContainsString( '<iframe', strtolower( $clean ) );
		$this->assertStringNotContainsString( '<form', strtolower( $clean ) );
		$this->assertStringNotContainsString( '<input', strtolower( $clean ) );
		$this->assertStringNotContainsString( '<animate', strtolower( $clean ) );
		$this->assertStringNotContainsString( 'http-equiv', strtolower( $clean ) );
		$this->assertStringNotContainsString( 'alert(2)', $clean, 'script source never shows up as page text' );
		$this->assertStringContainsString( 'Hi Alex', $clean );
	}

	public function test_clean_is_idempotent_and_keeps_layout(): void {
		$page  = $this->page( '<div class="gallery"><div class="gallery-item"><img src="https://example.com/a.jpg" alt="1"></div></div><p>Thank you, Sam.</p>' );
		$once  = ZRCPT_Sanitize::clean( $page );
		$twice = ZRCPT_Sanitize::clean( $once );
		$this->assertSame( $once, $twice );
		$this->assertStringContainsString( '.a > .b{color:#123}', $once, 'style blocks keep their selectors' );
		$this->assertStringContainsString( '<img src="https://example.com/a.jpg" alt="1">', $once );
		$this->assertSame( 0, strpos( $once, '<!DOCTYPE html>' ) );
	}

	public function test_finalize_sanitize_keeps_its_section_sentinels(): void {
		$html = $this->page( ZRCPT_Finalize::FS_GALLERY_START . '<div>g</div>' . ZRCPT_Finalize::FS_GALLERY_END . '<b onmouseover="x()">p</b>' );
		$out  = ZRCPT_Finalize::sanitize( $html );
		$this->assertStringContainsString( ZRCPT_Finalize::FS_GALLERY_START, $out );
		$this->assertStringContainsString( ZRCPT_Finalize::FS_GALLERY_END, $out );
		$this->assertStringNotContainsString( 'onmouseover', $out );
		$this->assertSame( $out, ZRCPT_Finalize::sanitize( $out ) );
	}

	public function test_font_stylesheets_need_an_allowed_host(): void {
		$page = '<!DOCTYPE html><html><head><link rel="stylesheet" href="https://fonts.example.com/css?family=A"></head><body>t</body></html>';
		$this->assertStringNotContainsString( 'fonts.example.com', ZRCPT_Sanitize::clean( $page ), 'no font host by default' );
		$GLOBALS['zdz_test_filters']['zrcpt_receipt_font_hosts'] = static function () { return array( 'fonts.example.com', 'bad host!' ); };
		$this->assertSame( array( 'fonts.example.com' ), ZRCPT_Sanitize::font_hosts() );
		$this->assertStringContainsString( '<link rel="stylesheet" href="https://fonts.example.com/css?family=A">', ZRCPT_Sanitize::clean( $page ) );
	}

	public function test_url_and_style_checks(): void {
		$this->assertTrue( ZRCPT_Sanitize::url_ok( 'https://example.com/x' ) );
		$this->assertTrue( ZRCPT_Sanitize::url_ok( '/relative' ) );
		$this->assertTrue( ZRCPT_Sanitize::url_ok( 'data:image/png;base64,AAAA' ) );
		$this->assertFalse( ZRCPT_Sanitize::url_ok( "java\tscript:alert(1)" ) );
		$this->assertFalse( ZRCPT_Sanitize::url_ok( 'data:text/html,<b>x</b>' ) );
		$this->assertFalse( ZRCPT_Sanitize::style_ok( 'width:expression(alert(1))' ) );
		$this->assertFalse( ZRCPT_Sanitize::style_ok( 'background:url("\\6a avascript:x")' ) );
		$this->assertTrue( ZRCPT_Sanitize::style_ok( 'color:rgba(0,0,0,.5);margin:0 auto' ) );
	}

	public function test_csp_allows_only_the_receipt_script_folder(): void {
		$csp = ZRCPT_Receipt::build_csp( 'https://example.com/wp-content/apps/receipts/assets/js/', array() );
		$this->assertStringContainsString( "default-src 'none'", $csp );
		$this->assertStringContainsString( 'script-src https://example.com/wp-content/apps/receipts/assets/js/;', $csp );
		$this->assertStringNotContainsString( "'unsafe-inline' https", $csp );
		$this->assertStringNotContainsString( "script-src 'self'", $csp );
		$this->assertStringContainsString( "object-src 'none'", $csp );
		$this->assertStringContainsString( "base-uri 'none'", $csp );
		$this->assertStringContainsString( "form-action 'none'", $csp );
		$this->assertStringContainsString( "connect-src 'none'", $csp );
		$this->assertStringContainsString( "script-src 'none'", ZRCPT_Receipt::build_csp( '', array() ) );
		$with = ZRCPT_Receipt::build_csp( 'https://example.com/js/', array( 'fonts.example.com' ) );
		$this->assertStringContainsString( "style-src 'self' 'unsafe-inline' https://fonts.example.com;", $with );
		$this->assertStringContainsString( "font-src 'self' data: https://fonts.example.com;", $with );
	}

	public function test_public_view_gate_only_narrows(): void {
		$this->assertFalse( ZRCPT_Receipt::narrow_only( false, true ), 'a filter cannot open an unapproved receipt' );
		$this->assertFalse( ZRCPT_Receipt::narrow_only( true, false ), 'a filter may close one' );
		$this->assertTrue( ZRCPT_Receipt::narrow_only( true, true ) );
	}

	public function test_approval_cutoff_is_strict(): void {
		$cut = '2026-01-01 00:00:00';
		$this->assertTrue( ZRCPT_Receipt::before_cutoff( '2025-12-01 10:00:00', '2025-12-02 10:00:00', $cut ) );
		$this->assertFalse( ZRCPT_Receipt::before_cutoff( '2025-12-01 10:00:00', '2026-02-01 10:00:00', $cut ), 'a redo after the cutoff needs approval' );
		$this->assertFalse( ZRCPT_Receipt::before_cutoff( '2026-01-01 00:00:00', '', $cut ) );
		$this->assertFalse( ZRCPT_Receipt::before_cutoff( '', '', $cut ) );
		$this->assertFalse( ZRCPT_Receipt::before_cutoff( '2025-12-01 10:00:00', '', '' ), 'no cutoff: nothing predates the flow' );
	}
}
