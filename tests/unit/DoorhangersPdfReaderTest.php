<?php
/**
 * Doorhangers reads scanned route maps with the apps bundle's one pdf.js copy (the Estimates app's vendor/pdfjs).
 *
 *   - Doorhangers ships no copy of its own; its config points at the shared library and worker (a worker always
 *     matches its library because both come from the same copy);
 *   - every call Doorhangers makes (getDocument, numPages, getPage, getViewport, render with a canvas context, the
 *     worker setting, destroy) is in the shared copy's API.
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

final class DoorhangersPdfReaderTest extends TestCase {

	private static function apps(): string {
		return dirname( __DIR__, 2 ) . '/zorderz-apps/apps/';
	}

	public function test_one_copy_and_doorhangers_points_at_it(): void {
		$this->assertFalse( is_dir( self::apps() . 'doorhangers/assets/vendor/pdfjs' ), 'Doorhangers ships no copy of its own' );
		$this->assertFileExists( self::apps() . 'estimate/assets/js/vendor/pdfjs/pdf.min.js' );
		$this->assertFileExists( self::apps() . 'estimate/assets/js/vendor/pdfjs/pdf.worker.min.js' );
		$app = (string) file_get_contents( self::apps() . 'doorhangers/app.php' );
		$this->assertStringContainsString( "'pdfjs'         => zdh_pdfjs_urls()", $app );
		$this->assertStringContainsString( "'estimate/assets/js/vendor/pdfjs/'", $app );
		$this->assertStringNotContainsString( '4.10.38', $app, 'no version of a copy that is gone' );
	}

	public function test_doorhangers_calls_are_in_the_shared_api(): void {
		$js  = (string) file_get_contents( self::apps() . 'doorhangers/assets/app.js' );
		$lib = (string) file_get_contents( self::apps() . 'estimate/assets/js/vendor/pdfjs/pdf.min.js' );
		foreach ( array( 'getDocument({ data: buf })', '.getPage(', 'getViewport({ scale:', "render({ canvasContext: ctx, viewport: vp })", 'GlobalWorkerOptions.workerSrc', 'pdf.destroy()', 'numPages' ) as $call ) {
			$this->assertStringContainsString( $call, $js, 'Doorhangers still makes this call: ' . $call );
		}
		$this->assertMatchesRegularExpression( '/render\(\{canvasContext:[a-z]+,canvas:[a-z]+=[a-z]+\.canvas,viewport:/', $lib, 'render() still takes a canvas context' );
		foreach ( array( 'getDocument', 'GlobalWorkerOptions', 'getViewport(', 'getPage(', 'numPages', 'destroy(' ) as $api ) {
			$this->assertStringContainsString( $api, $lib, 'the shared copy has ' . $api );
		}
		$this->assertMatchesRegularExpression( '/pdfjsVersion\s*=\s*"?[4-9]\./', $lib, 'a version past the fixed font bug (4.2.67 and later)' );
	}
}
