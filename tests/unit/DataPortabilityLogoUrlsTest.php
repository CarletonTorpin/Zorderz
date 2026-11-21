<?php
/**
 * Logo addresses travel with a Company Data bundle.
 *
 * A Business Profile logo is stored as a full address on the site that set it. On export an address inside the
 * site's own uploads becomes "{uploads}/…" (the file travels in the bundle); on import "{uploads}/" becomes the
 * restoring site's uploads address. Addresses elsewhere, other profile fields and empty slots are untouched. The
 * shipped sample's logos use the portable form, and every file they name is in the sample.
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

final class DataPortabilityLogoUrlsTest extends TestCase {

	private static function profile( array $logo ): array {
		return array(
			'identity' => array( 'trading_name' => 'Example Co' ),
			'brand'    => array( 'logo' => $logo, 'ramp' => array( '500' => '#5150D6' ) ),
		);
	}

	public function test_an_address_in_this_sites_uploads_leaves_as_the_portable_form(): void {
		$p   = self::profile( array(
			'wide'    => array( 'dark' => 'https://old.example/wp-content/uploads/2026/08/logo-dark.png', 'light' => 'http://old.example/wp-content/uploads/2026/08/logo-light.png' ),
			'square'  => array( 'dark' => '//old.example/wp-content/uploads/2026/08/mark.png', 'light' => '' ),
			'favicon' => 'https://cdn.example/fav.png',
		) );
		$out = ZDZ_Data_Portability::portable_logo_urls( $p, 'https://old.example/wp-content/uploads', 'out' );
		$this->assertSame( '{uploads}/2026/08/logo-dark.png', $out['brand']['logo']['wide']['dark'] );
		$this->assertSame( '{uploads}/2026/08/logo-light.png', $out['brand']['logo']['wide']['light'], 'either scheme is the same folder' );
		$this->assertSame( '{uploads}/2026/08/mark.png', $out['brand']['logo']['square']['dark'] );
		$this->assertSame( '', $out['brand']['logo']['square']['light'] );
		$this->assertSame( 'https://cdn.example/fav.png', $out['brand']['logo']['favicon'], 'an address elsewhere stays as it is' );
		$this->assertSame( $p['identity'], $out['identity'] );
		$this->assertSame( $p['brand']['ramp'], $out['brand']['ramp'] );
	}

	public function test_the_portable_form_lands_on_the_restoring_sites_uploads(): void {
		$p  = self::profile( array(
			'wide'    => array( 'dark' => '{uploads}/2026/08/logo-dark.png', 'light' => 'https://elsewhere.example/a.png' ),
			'square'  => array( 'dark' => '', 'light' => '{uploads}/2026/08/mark-light.png' ),
			'favicon' => '',
		) );
		$in = ZDZ_Data_Portability::portable_logo_urls( $p, 'https://new.example/blog/wp-content/uploads', 'in' );
		$this->assertSame( 'https://new.example/blog/wp-content/uploads/2026/08/logo-dark.png', $in['brand']['logo']['wide']['dark'] );
		$this->assertSame( 'https://elsewhere.example/a.png', $in['brand']['logo']['wide']['light'] );
		$this->assertSame( 'https://new.example/blog/wp-content/uploads/2026/08/mark-light.png', $in['brand']['logo']['square']['light'] );
		$this->assertSame( '', $in['brand']['logo']['square']['dark'] );
	}

	public function test_a_profile_without_logos_or_without_an_uploads_address_is_unchanged(): void {
		$p = array( 'identity' => array( 'trading_name' => 'Example Co' ) );
		$this->assertSame( $p, ZDZ_Data_Portability::portable_logo_urls( $p, 'https://a.example/wp-content/uploads', 'out' ) );
		$q = self::profile( array( 'wide' => array( 'dark' => '{uploads}/x.png' ) ) );
		$this->assertSame( $q, ZDZ_Data_Portability::portable_logo_urls( $q, '', 'in' ) );
	}

	public function test_the_sample_logos_are_portable_and_their_files_ship(): void {
		if ( ! class_exists( 'ZipArchive' ) ) {
			$this->markTestSkipped( 'ZipArchive unavailable' );
		}
		$zip = new ZipArchive();
		$this->assertTrue( true === $zip->open( dirname( __DIR__, 2 ) . '/zorderz/sample-data/testco.zip' ) );
		$bundle = json_decode( (string) $zip->getFromName( 'zorderz-data.json' ), true );
		$logo   = $bundle['options']['zdz_business_profile']['brand']['logo'];
		$named  = 0;
		foreach ( array( 'wide', 'square' ) as $shape ) {
			foreach ( array( 'dark', 'light' ) as $ink ) {
				$v = (string) $logo[ $shape ][ $ink ];
				$this->assertSame( 0, strpos( $v, '{uploads}/' ), "$shape $ink" );
				$this->assertTrue( false !== $zip->locateName( 'uploads/' . substr( $v, strlen( '{uploads}/' ) ) ), "$shape $ink file ships" );
				$named++;
			}
		}
		$this->assertSame( 4, $named );
		// every file an attachment record names (the original and each size) is in the sample
		foreach ( (array) $bundle['attachments'] as $att ) {
			$meta = $att['meta']['_wp_attachment_metadata'];
			$dir  = dirname( (string) $meta['file'] );
			$this->assertTrue( false !== $zip->locateName( 'uploads/' . $meta['file'] ), $meta['file'] );
			foreach ( (array) ( $meta['sizes'] ?? array() ) as $size ) {
				$this->assertTrue( false !== $zip->locateName( 'uploads/' . $dir . '/' . $size['file'] ), $size['file'] );
			}
		}
		$zip->close();
	}
}
