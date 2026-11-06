<?php
/**
 * The address a record keeps (ZDZ_Client_IP): the connection's address (REMOTE_ADDR), which the person on the other end
 * cannot type, or a trusted proxy's value on zdz_client_ip. A forwarded-for header is the client's own claim: it never
 * becomes the address.
 *
 *   - get(): REMOTE_ADDR; X-Forwarded-For, X-Real-IP and CF-Connecting-IP are ignored; the filter can supply a proxy's
 *     value, which must still be a valid IP address.
 *   - forwarded_chain(): valid addresses only, at most five.
 *   - The audit log's accountability rows keep the connection's address in ip_address and a forwarded list only as
 *     meta "forwarded_for"; usage rows still keep no address.
 *   - Agreements and receipt approvals record the same address.
 *
 * Documentation addresses only (192.0.2.0/24, 198.51.100.0/24, 203.0.113.0/24, 2001:db8::/32).
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/support/privacy-stubs.php';
require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-client-ip.php';
require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-consent.php';
require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-admin-dashboard.php';
require_once __DIR__ . '/support/agreements-stubs.php';

final class ClientIpRecordTest extends TestCase {

	const HEADERS = array( 'REMOTE_ADDR', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_CF_CONNECTING_IP', 'HTTP_USER_AGENT' );

	protected function setUp(): void {
		foreach ( self::HEADERS as $h ) {
			unset( $_SERVER[ $h ] );
		}
		$_SERVER['REMOTE_ADDR']           = '192.0.2.10';
		$_SERVER['HTTP_X_FORWARDED_FOR']  = '203.0.113.99, not-an-ip, 198.51.100.4';
		$_SERVER['HTTP_X_REAL_IP']        = '203.0.113.98';
		$_SERVER['HTTP_CF_CONNECTING_IP'] = '203.0.113.97';
	}

	protected function tearDown(): void {
		foreach ( self::HEADERS as $h ) {
			unset( $_SERVER[ $h ] );
		}
		unset( $GLOBALS['zdz_test_filters'], $GLOBALS['zdz_test_options'], $GLOBALS['wpdb'], $GLOBALS['zdz_test_user_roles'], $GLOBALS['zdz_test_current_user'] );
	}

	public function test_the_address_is_the_connections_never_a_header(): void {
		$this->assertSame( '192.0.2.10', ZDZ_Client_IP::get() );
		$_SERVER['REMOTE_ADDR'] = '2001:db8::5';
		$this->assertSame( '2001:db8::5', ZDZ_Client_IP::get() );
		$_SERVER['REMOTE_ADDR'] = 'garbage';
		$this->assertSame( '', ZDZ_Client_IP::get(), 'never a header in its place' );
		unset( $_SERVER['REMOTE_ADDR'] );
		$this->assertSame( '', ZDZ_Client_IP::get() );
	}

	public function test_a_trusted_proxy_value_comes_only_through_the_filter_and_must_be_an_address(): void {
		$GLOBALS['zdz_test_filters']['zdz_client_ip'] = function ( $ip ) { return '198.51.100.7'; };
		$this->assertSame( '198.51.100.7', ZDZ_Client_IP::get() );
		$GLOBALS['zdz_test_filters']['zdz_client_ip'] = function ( $ip ) { return '198.51.100.7, 192.0.2.1'; };
		$this->assertSame( '', ZDZ_Client_IP::get() );
		$GLOBALS['zdz_test_filters']['zdz_client_ip'] = function ( $ip ) { return array( '198.51.100.7' ); };
		$this->assertSame( '', ZDZ_Client_IP::get() );
	}

	public function test_the_forwarded_chain_is_valid_addresses_only_and_short(): void {
		$this->assertSame( array( '203.0.113.99', '198.51.100.4' ), ZDZ_Client_IP::forwarded_chain() );
		$_SERVER['HTTP_X_FORWARDED_FOR'] = implode( ',', array_fill( 0, 9, '203.0.113.1' ) );
		$this->assertCount( ZDZ_Client_IP::MAX_FORWARDED, ZDZ_Client_IP::forwarded_chain() );
		unset( $_SERVER['HTTP_X_FORWARDED_FOR'] );
		$this->assertSame( array(), ZDZ_Client_IP::forwarded_chain() );
	}

	public function test_the_audit_log_keeps_the_connections_address_and_labels_the_forwarded_list(): void {
		$GLOBALS['wpdb']                  = new ZDZ_Test_Vault_DB();
		$GLOBALS['zdz_test_user_roles']   = array( 7 => array( 'zdz_sales' ) );
		$GLOBALS['zdz_test_options']['zdz_audit_log_version'] = ZDZ_Admin_Dashboard::DB_VERSION;
		$this->assertTrue( ZDZ_Admin_Dashboard::log_action( 7, 'login', 'signed in' ) );
		$this->assertTrue( ZDZ_Admin_Dashboard::log_action( 7, 'app_open', 'Jobs', 'jobs' ) );
		$rows = $GLOBALS['wpdb']->rows( 'zdz_audit_log' );
		$this->assertSame( '192.0.2.10', $rows[0]['ip_address'], 'what the server saw, not what the client wrote' );
		$meta = json_decode( (string) $rows[0]['meta_json'], true );
		$this->assertSame( '203.0.113.99, 198.51.100.4', $meta['forwarded_for'], 'kept, under its own label' );
		$this->assertSame( '', $rows[1]['ip_address'], 'a usage row keeps no address' );
		$this->assertStringNotContainsString( '203.0.113', (string) $rows[1]['meta_json'] );
		$this->assertSame( '192.0.2.10', ZDZ_Admin_Dashboard::client_ip() );
	}

	public function test_agreements_and_receipt_approvals_record_the_same_address(): void {
		$this->assertSame( '192.0.2.10', ZAGR_People::client_ip() );
		$this->assertSame( '192.0.2.10 (forwarded for 203.0.113.99)', ZAGR_People::request_ip() );
		$GLOBALS['zdz_test_filters']['zdz_client_ip'] = function ( $ip ) { return '198.51.100.7'; };
		$this->assertSame( '198.51.100.7', ZAGR_People::client_ip(), 'one trusted-proxy setting for every record' );
		unset( $GLOBALS['zdz_test_filters']['zdz_client_ip'] );

		$dir = dirname( __DIR__, 2 ) . '/zorderz-apps/apps/receipts/includes/';
		require_once $dir . 'class-zrcpt-sanitize.php';
		require_once $dir . 'class-zrcpt-finalize.php';
		if ( ! class_exists( 'ZRCPT_Receipt' ) ) {
			require_once $dir . 'class-zrcpt-receipt.php';
		}
		$r = ( new ReflectionClass( 'ZRCPT_Receipt' ) )->newInstanceWithoutConstructor();
		$m = new ReflectionMethod( 'ZRCPT_Receipt', 'client_ip' );
		$m->setAccessible( true );
		$this->assertSame( '192.0.2.10', $m->invoke( $r ), 'an approval records the connection, not a header' );
	}
}
