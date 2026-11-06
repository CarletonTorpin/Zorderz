<?php
/**
 * ZDZ_Geocoder: the shared address cache key never moves, the permanent cache's backoff, the
 * common provider response shapes, and "was this photo taken at this address".
 *
 * The expected keys below are the keys the platform has always computed; every app that caches
 * an address (jobs, estimates, media) depends on them staying byte-identical.
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/notify-media-stubs.php';
require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-geocoder.php';

final class NotifyGeocoderTest extends TestCase {

	protected function tearDown(): void {
		zdz_notify_test_reset();
		ZDZ_Geocoder::flush();
	}

	public function test_the_cache_key_is_stable(): void {
		$k = ZDZ_Geocoder::address_cache_key( array( 'street' => '123 Example St', 'city' => 'Sample City', 'state' => 'ST', 'zip' => '00000' ) );
		$this->assertSame( '123 example st, sample city, st 00000', $k );
		$this->assertSame( 'zdz_geo_addr_0f2d33ff9a5deb4d6d57b64647775489', ZDZ_Geocoder::cache_option_name( $k ) );
		$this->assertSame( $k, ZDZ_Geocoder::normalize_string( '  123 Example St ,Sample City,  ST 00000 ' ), 'free-form text, same key' );
		$this->assertSame( $k, ZDZ_Geocoder::normalize_string( '123 EXAMPLE ST, SAMPLE CITY, ST 00000' ) );
		$this->assertSame( '456 sample avenue, example town, ex 12345, exampleland', ZDZ_Geocoder::address_cache_key(
			array( 'address1' => '  456  Sample  Avenue ', 'town' => 'Example Town', 'region' => 'EX', 'postcode' => '12345', 'country' => 'Exampleland' )
		), 'aliases and the country' );
		$this->assertSame( '789 placeholder rd', ZDZ_Geocoder::address_cache_key( array( 'street' => '789 Placeholder Rd' ) ) );
		$this->assertSame( sha1( $k ), ZDZ_Geocoder::hash( '123 Example St, Sample City, ST 00000' ) );
		$this->assertSame( ZDZ_Geocoder::hash( $k ), ZDZ_Geocoder::hash( array( 'street' => '123 Example St', 'city' => 'Sample City', 'state' => 'ST', 'zip' => '00000' ) ) );
	}

	public function test_abbreviations_are_off_unless_a_business_turns_them_on(): void {
		$long = ZDZ_Geocoder::normalize_string( '12 Example Street, Sample City' );
		$this->assertSame( '12 example street, sample city', $long, 'Core folds nothing' );
		$GLOBALS['zdz_test_filters']['zdz_geocoder_address_abbreviations'] = function () { return array( 'street' => 'st', 'Avenue' => 'ave', 'bad<' => 'x' ); };
		$this->assertSame( '12 example st, sample city', ZDZ_Geocoder::normalize_string( '12 Example Street, Sample City' ) );
		$this->assertSame( ZDZ_Geocoder::normalize_string( '12 Example St, Sample City' ), ZDZ_Geocoder::normalize_string( '12 Example Street, Sample City' ), 'both spellings, one key' );
		$this->assertSame( '12 streeter ave', ZDZ_Geocoder::normalize_string( '12 Streeter Avenue' ), 'whole words only' );
	}

	public function test_nothing_configured_means_no_call_and_no_failure(): void {
		$calls = 0;
		$r = ZDZ_Geocoder::resolve_address( '1 Example Way', array( 'transport' => function () use ( &$calls ) { $calls++; return '{}'; } ) );
		$this->assertNull( $r );
		$this->assertSame( 0, $calls, 'networked lookup is off by default' );
		$this->assertNull( ZDZ_Geocoder::lookup( '1 Example Way' ) );
	}

	public function test_a_configured_provider_and_the_common_shapes(): void {
		$GLOBALS['zdz_test_filters']['zdz_media_networked_geocode_enabled'] = function () { return true; };
		$GLOBALS['zdz_test_filters']['zdz_media_geocode_endpoint']          = function () { return 'https://geo.example.com/find?q={query}'; };
		$seen = '';
		$r = ZDZ_Geocoder::resolve_address( '2 Example Way, Sample City', array( 'transport' => function ( $url ) use ( &$seen ) {
			$seen = $url;
			return json_encode( array( 'result' => array( 'addressMatches' => array( array( 'coordinates' => array( 'x' => -100.5, 'y' => 40.25 ), 'matchedAddress' => '2 EXAMPLE WAY' ) ) ) ) );
		} ) );
		$this->assertSame( 'https://geo.example.com/find?q=2%20example%20way%2C%20sample%20city', $seen );
		$this->assertSame( 40.25, $r['lat'] );
		$this->assertSame( -100.5, $r['lng'] );
		$this->assertSame( 'interpolated', $r['precision'] );
		$this->assertSame( $r, ZDZ_Geocoder::lookup( '2 Example Way, Sample City' ), 'resolved once, then from the cache' );

		$this->assertSame( array( 'lat' => 1.5, 'lng' => 2.5 ), ZDZ_Geocoder::parse_common( '{"features":[{"geometry":{"coordinates":[2.5,1.5]}}]}' ) );
		$this->assertSame( array( 'lat' => 1.5, 'lng' => 2.5, 'matched' => 'Somewhere' ), ZDZ_Geocoder::parse_common( '[{"lat":"1.5","lon":"2.5","display_name":"Somewhere"}]' ) );
		$this->assertNull( ZDZ_Geocoder::parse_common( '{"nothing":true}' ) );
		$this->assertNull( ZDZ_Geocoder::parse_common( 'not json' ) );
	}

	public function test_an_implausible_point_is_never_kept(): void {
		$GLOBALS['zdz_test_filters']['zdz_media_forward_geocode'] = function () { return array( 'lat' => 0, 'lng' => 0 ); };
		$this->assertNull( ZDZ_Geocoder::resolve_address( '3 Example Way' ), 'null island is a parse bug' );
		$GLOBALS['zdz_test_filters']['zdz_media_forward_geocode'] = function () { return array( 'lat' => 95, 'lng' => 10 ); };
		$this->assertNull( ZDZ_Geocoder::resolve_address( '4 Example Way' ) );
	}

	public function test_backoff_after_failures(): void {
		$now = 1000000;
		$this->assertTrue( ZDZ_Geocoder::retry_allowed( 'retry', 0, 0, $now ) );
		$this->assertFalse( ZDZ_Geocoder::retry_allowed( 'retry', 1, $now - 60, $now ), 'an hour after the first failure' );
		$this->assertTrue( ZDZ_Geocoder::retry_allowed( 'retry', 1, $now - 3601, $now ) );
		$this->assertFalse( ZDZ_Geocoder::retry_allowed( 'retry', 2, $now - 3601, $now ), 'six hours after the second' );
		$this->assertTrue( ZDZ_Geocoder::retry_allowed( 'retry', 3, $now - 86401, $now ) );
		$this->assertFalse( ZDZ_Geocoder::retry_allowed( 'retry', 4, 0, $now ), 'given up after four' );
		$this->assertFalse( ZDZ_Geocoder::retry_allowed( 'unresolvable', 1, 0, $now ) );
	}

	public function test_same_property(): void {
		$lat = 40.0;
		$lng = -100.0;
		$this->assertSame( 'match', ZDZ_Geocoder::same_property( $lat, $lng, $lat + 0.0005, $lng )['verdict'], 'about 56 m' );
		$this->assertSame( 'nearby', ZDZ_Geocoder::same_property( $lat, $lng, $lat + 0.002, $lng )['verdict'], 'about 222 m' );
		$this->assertSame( 'elsewhere', ZDZ_Geocoder::same_property( $lat, $lng, $lat + 0.01, $lng )['verdict'] );
		$this->assertSame( 'too_vague', ZDZ_Geocoder::same_property( $lat, $lng, $lat, $lng, 500.0 )['verdict'], 'a vague fix is never a match' );
		$this->assertNear( 111195.0, ZDZ_Geocoder::distance_m( 0.0, 0.0, 1.0, 0.0 ), 5.0 );
		$GLOBALS['zdz_test_filters']['zdz_geocoder_same_property_m'] = function () { return 300.0; };
		$this->assertSame( 'match', ZDZ_Geocoder::same_property( $lat, $lng, $lat + 0.002, $lng )['verdict'], 'a business may widen its radius' );
		$GLOBALS['zdz_test_filters']['zdz_geocoder_same_property_m'] = function () { return 5000.0; };
		$this->assertSame( 120.0, ZDZ_Geocoder::same_property_m(), 'within 10 to 1000 m' );
	}

	private function assertNear( float $e, float $a, float $d ): void {
		$this->assertTrue( abs( $e - $a ) <= $d, "expected $e ± $d, got $a" );
	}
}
