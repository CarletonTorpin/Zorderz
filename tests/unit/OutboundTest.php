<?php
/**
 * Addresses an administrator configured (ZDZ_Outbound): sent through the safe call, redirects capped, and a private
 * address only where the service sits there by design.
 *
 *   - private_host(): loopback, the private ranges, 100.64.0.0/10 (overlay networks), link-local, IPv6 loopback,
 *     unique-local and link-local, "localhost", and a name that resolves only to such addresses; nothing public.
 *   - send(): a public host always goes the safe way; a private host goes direct only when the caller says the service
 *     lives there (the Charts hub, a local model server); redirects never exceed the caller's cap.
 *   - Callers: a map layer goes the safe way with at most two redirects; the Charts hub on loopback goes direct and
 *     on a public tunnel address the safe way, never with a redirect.
 *
 * Documentation addresses and example.com only.
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
	function wp_remote_retrieve_body( $r ) { return is_array( $r ) ? (string) ( $r['body'] ?? '' ) : ''; }
}
if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( $url, $component = -1 ) { return -1 === $component ? parse_url( (string) $url ) : parse_url( (string) $url, $component ); }
}
foreach ( array( 'ZCH_WEB_PARTY' => 'zorderz-site', 'ZCH_HUB_PARTY' => 'charts-hub' ) as $zdz_ob_k => $zdz_ob_v ) {
	if ( ! defined( $zdz_ob_k ) ) {
		define( $zdz_ob_k, $zdz_ob_v );
	}
}
require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-outbound.php';
require_once dirname( __DIR__, 2 ) . '/zorderz-apps/apps/doorhangers/includes/class-zdh-geo.php';
foreach ( array( 'envelope', 'config', 'hub-client' ) as $zdz_ob_f ) {
	require_once dirname( __DIR__, 2 ) . '/zorderz-apps/apps/charts/includes/class-zch-' . $zdz_ob_f . '.php';
}

final class OutboundTest extends TestCase {

	/** @var array[] [ how, method, url, args ] */
	private $calls = array();

	protected function setUp(): void {
		$this->calls = array();
		$calls       = &$this->calls;
		ZDZ_Outbound::$transport = function ( $how, $method, $url, $args ) use ( &$calls ) {
			$calls[] = array( $how, $method, $url, $args );
			return array( 'response' => array( 'code' => 200 ), 'body' => '{"features":[]}' );
		};
		ZDZ_Outbound::$resolve = function ( $host ) {
			$map = array(
				'hub.example.com'     => array( '203.0.113.20' ),
				'hub.overlay.example' => array( '100.101.102.103' ),
				'mixed.example.com'   => array( '10.0.0.5', '203.0.113.9' ),
				'gis.example.com'     => array( '198.51.100.30' ),
			);
			return $map[ $host ] ?? array();
		};
	}

	protected function tearDown(): void {
		ZDZ_Outbound::$transport = null;
		ZDZ_Outbound::$resolve   = null;
	}

	public function test_private_addresses_are_recognised_and_public_ones_are_not(): void {
		foreach ( array( '127.0.0.1', '10.1.2.3', '172.16.0.1', '172.31.255.255', '192.168.1.1', '100.64.0.1', '100.127.255.254',
			'169.254.169.254', '0.0.0.0', '::1', 'fd00::1', 'fc12::3', 'fe80::1', '::ffff:127.0.0.1' ) as $ip ) {
			$this->assertTrue( ZDZ_Outbound::is_private_ip( $ip ), $ip );
		}
		foreach ( array( '8.8.8.8', '203.0.113.5', '100.128.0.1', '172.32.0.1', '2001:db8::1', '::ffff:198.51.100.1', 'not-an-ip' ) as $ip ) {
			$this->assertFalse( ZDZ_Outbound::is_private_ip( $ip ), $ip );
		}
	}

	public function test_a_host_is_private_by_its_address_or_by_every_address_it_resolves_to(): void {
		$this->assertTrue( ZDZ_Outbound::private_host( 'https://127.0.0.1:8765/v1' ) );
		$this->assertTrue( ZDZ_Outbound::private_host( 'http://[::1]:8765/' ) );
		$this->assertTrue( ZDZ_Outbound::private_host( 'http://localhost:1234/v1' ) );
		$this->assertTrue( ZDZ_Outbound::private_host( 'https://hub.localhost/' ) );
		$this->assertTrue( ZDZ_Outbound::private_host( 'https://hub.overlay.example/' ), 'an overlay-network name' );
		$this->assertFalse( ZDZ_Outbound::private_host( 'https://hub.example.com/' ) );
		$this->assertFalse( ZDZ_Outbound::private_host( 'https://mixed.example.com/' ), 'one public address: not private' );
		$this->assertFalse( ZDZ_Outbound::private_host( 'https://unknown.example.com/' ), 'unresolvable: not private' );
		$this->assertFalse( ZDZ_Outbound::private_host( 'not a url' ) );
	}

	public function test_send_goes_direct_only_to_a_private_host_that_is_private_by_design(): void {
		ZDZ_Outbound::send( 'POST', 'https://127.0.0.1:8765/x', array(), true, 0 );
		ZDZ_Outbound::send( 'POST', 'https://hub.example.com/x', array(), true, 0 );
		ZDZ_Outbound::send( 'GET', 'https://127.0.0.1/x', array(), false, 2 );
		$this->assertSame( array( 'direct', 'safe', 'safe' ), array_column( $this->calls, 0 ) );
		$this->assertSame( 'GET', $this->calls[2][1] );
	}

	public function test_redirects_never_exceed_the_callers_cap(): void {
		ZDZ_Outbound::send( 'GET', 'https://gis.example.com/x', array( 'redirection' => 10 ), false, 2 );
		ZDZ_Outbound::send( 'GET', 'https://gis.example.com/x', array(), false, 2 );
		ZDZ_Outbound::send( 'POST', 'https://gis.example.com/x', array( 'redirection' => 5 ), true, 0 );
		$this->assertSame( array( 2, 2, 0 ), array_map( function ( $c ) { return $c[3]['redirection']; }, $this->calls ) );
	}

	public function test_a_map_layer_goes_the_safe_way(): void {
		$r = ZDH_Geo::arcgis_query( 'https://gis.example.com:6443/arcgis/rest/services/Hazard/MapServer/0/query', array( 'where' => '1=1' ), 5 );
		$this->assertIsArray( $r );
		$this->assertCount( 1, $this->calls );
		$this->assertSame( 'safe', $this->calls[0][0] );
		$this->assertSame( 2, $this->calls[0][3]['redirection'] );
	}

	public function test_the_charts_hub_on_loopback_goes_direct_and_a_public_tunnel_the_safe_way(): void {
		list( $site_sk ) = ZCH_Envelope::keygen();
		list( , $hub_pk ) = ZCH_Envelope::keygen();
		$post = new ReflectionMethod( 'ZCH_Hub_Client', 'post' );
		$post->setAccessible( true );
		foreach ( array( 'http://127.0.0.1:8765/rpc' => 'direct', 'https://hub.overlay.example/rpc' => 'direct', 'https://hub.example.com/rpc' => 'safe' ) as $url => $how ) {
			$this->calls = array();
			$client      = new ZCH_Hub_Client( $url, $site_sk, $hub_pk );
			$post->invoke( $client, '{}', 5 );
			$this->assertSame( $how, $this->calls[0][0], $url );
			$this->assertSame( 0, $this->calls[0][3]['redirection'], 'never a redirect' );
		}
	}
}
