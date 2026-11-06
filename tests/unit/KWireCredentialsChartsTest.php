<?php
/**
 * Services an app adds on Zorderz → Credentials, and the Charts hub as one of them:
 *
 *   - zdz_core_credentials_services only ADDS: an entry cannot take a Core service id, name a row another service
 *     stores, keep its canonical row outside Core's prefix, or store a secret plain; Core's rows are unchanged;
 *   - a field's own check replaces the one-token rule, still bounded (no control characters, at most 2,000);
 *   - the Charts hub (address, hub public key, site secret, extra headers): the secret and the headers are sealed,
 *     Charts reads the credentials layer first and its own settings as the fallback, and wp-config.php still wins;
 *   - the Charts tile for each person Charts allows, through the ADD-only per-person grant.
 *
 * Neutral data only; the keys are made-up base64url strings.
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

if ( ! defined( 'ZCH_WEB_PARTY' ) ) {
	define( 'ZCH_WEB_PARTY', 'zorderz-site' );
}
if ( ! defined( 'ZCH_HUB_PARTY' ) ) {
	define( 'ZCH_HUB_PARTY', 'charts-hub' );
}
if ( ! defined( 'ZCH_APP_ID' ) ) {
	define( 'ZCH_APP_ID', 'charts' );
}
if ( ! function_exists( 'get_user_meta' ) ) {
	function get_user_meta( $uid, $key, $single = false ) { return $GLOBALS['zdz_test_user_meta'][ (int) $uid ][ $key ] ?? ''; }
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( $k, $v, $autoload = null ) { $GLOBALS['zdz_test_options'][ $k ] = $v; return true; }
}
if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( $url, $component = -1 ) { return -1 === $component ? parse_url( (string) $url ) : parse_url( (string) $url, $component ); }
}
if ( ! function_exists( 'wp_cache_delete' ) ) {
	function wp_cache_delete( $k, $g = '' ) { return true; }
}

/** The options table as rows: what the credentials layer reads and writes through $wpdb. */
if ( ! class_exists( 'ZDZ_Test_Kwa_Options_DB' ) ) {
	class ZDZ_Test_Kwa_Options_DB {
		public $options = 'wp_options';
		public static function to_row( $v ) {
			return is_array( $v ) ? serialize( $v ) : (string) $v;
		}
		public static function from_row( string $s ) {
			if ( 0 === strpos( $s, 'a:' ) ) {
				$a = @unserialize( $s, array( 'allowed_classes' => false ) ); // sentinel:allow test fixture: the pattern is test data or a stand-in, never run against input
				if ( is_array( $a ) ) {
					return $a;
				}
			}
			return $s;
		}
		public function prepare( $q, ...$args ) {
			return array( 'q' => $q, 'args' => $args );
		}
		public function get_var( $p ) {
			$name = (string) ( $p['args'][0] ?? '' );
			return array_key_exists( $name, $GLOBALS['zdz_test_options'] ?? array() ) ? self::to_row( $GLOBALS['zdz_test_options'][ $name ] ) : null;
		}
		public function insert( $t, $row ) {
			$GLOBALS['zdz_test_options'][ $row['option_name'] ] = self::from_row( (string) $row['option_value'] );
			return 1;
		}
		public function update( $t, $data, $where ) {
			$GLOBALS['zdz_test_options'][ $where['option_name'] ] = self::from_row( (string) $data['option_value'] );
			return 1;
		}
		public function delete( $t, $where ) {
			unset( $GLOBALS['zdz_test_options'][ $where['option_name'] ] );
			return 1;
		}
	}
}

$zdz_kwa_root = dirname( __DIR__, 2 );
require_once $zdz_kwa_root . '/zorderz/inc/class-zdz-core-crypto.php';
require_once $zdz_kwa_root . '/zorderz/inc/class-zdz-fb-credentials.php';
require_once $zdz_kwa_root . '/zorderz/inc/class-zdz-core-credentials.php';
require_once $zdz_kwa_root . '/zorderz-apps/apps/charts/includes/class-zch-envelope.php';
require_once $zdz_kwa_root . '/zorderz-apps/apps/charts/includes/class-zch-config.php';
require_once $zdz_kwa_root . '/zorderz-apps/apps/charts/includes/class-zch-access.php';

final class KWireCredentialsChartsTest extends TestCase {

	const PUB    = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaA';
	const SECRET = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbB';

	/** Sealed at rest, as the platform seals a secret (fixed-IV). */
	private static function seal( string $plain ): string {
		return ZDZ_Core_Crypto::encode( $plain, ZDZ_Core_Crypto::FIXED );
	}

	protected function setUp(): void {
		$GLOBALS['zdz_test_options']                                    = array();
		$GLOBALS['wpdb']                                                = new ZDZ_Test_Kwa_Options_DB();
		$GLOBALS['zdz_test_filters']['zdz_core_credentials_services'] = array( 'ZCH_Config', 'credentials_service' );
		ZCH_Config::$stored                                             = null;
		ZCH_Config::flush_creds();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['zdz_test_filters']['zdz_core_credentials_services'], $GLOBALS['wpdb'], $GLOBALS['zdz_test_user_roles'], $GLOBALS['zdz_test_user_meta'] );
		$GLOBALS['zdz_test_options'] = array();
		ZCH_Config::$stored          = null;
		ZCH_Config::flush_creds();
	}

	/* ── the filter only adds ────────────────────────────────────────────── */

	public function test_the_charts_hub_is_an_added_app_credential_with_its_secrets_sealed(): void {
		$s = ZDZ_Core_Credentials::services();
		$this->assertArrayHasKey( 'charts_hub', $s );
		$h = $s['charts_hub'];
		$this->assertSame( 'app', $h['kind'] );
		$this->assertTrue( ZDZ_Core_Credentials::is_app( 'charts_hub' ) );
		$this->assertSame( array( 'url', 'hub_public', 'site_secret' ), $h['required'] );
		foreach ( $h['canonical'] as $f => $row ) {
			$this->assertSame( 0, strpos( $row, 'zdz_core_charts_' ), $row );
			$this->assertArrayHasKey( $row, $h['copies'][ $f ], 'Core\'s row is listed first' );
		}
		foreach ( array( 'site_secret', 'headers' ) as $secret ) {
			$this->assertTrue( $h['fields'][ $secret ][1], "$secret is never shown" );
			foreach ( $h['copies'][ $secret ] as $row => $meta ) {
				$this->assertSame( ZDZ_Core_Crypto::FIXED, $meta[0], "$secret is sealed in $row" );
			}
		}
		$this->assertSame( 'site_secret', $h['copies']['site_secret']['zch_hub']['field'], 'Charts\' own row is a copy, so "Manage here" adopts it' );
		$this->assertArrayNotHasKey( 'test', $h );
		$this->assertSame( ZDZ_Core_Crypto::PLAIN, $h['copies']['url']['zdz_core_charts_hub_url'][0], 'the address is not a secret' );
	}

	public function test_a_filter_can_never_change_or_take_a_core_row(): void {
		$GLOBALS['zdz_test_filters']['zdz_core_credentials_services'] = null;
		$core = ZDZ_Core_Credentials::services();
		$GLOBALS['zdz_test_filters']['zdz_core_credentials_services'] = function ( $s ) {
			$P = ZDZ_Core_Crypto::PLAIN;
			$F = ZDZ_Core_Crypto::FIXED;
			$entry = function ( $canon, $copy ) use ( $F ) {
				return array( 'kind' => 'app', 'label' => 'Example', 'group' => 'Example', 'fields' => array( 'k' => array( 'Key', true ) ),
					'canonical' => array( 'k' => $canon ), 'copies' => array( 'k' => array( $copy => array( $F, 'Example app' ) ) ) );
			};
			$s['poe']['label'] = 'Mine now';                                                        // change a Core entry
			unset( $s['nutshell'] );                                                                 // remove one
			$s['steal']   = $entry( 'zdz_core_poe_api_key', 'example_steal_k' );                    // a Core row
			$s['copycat'] = $entry( 'zdz_core_example_c', 'zl_poe_api_key' );                       // another service's copy
			$s['half']    = array( 'kind' => 'app', 'label' => 'Half', 'group' => 'x', 'fields' => array( 'k' => array( 'Key', true ) ) );   // no rows
			$s['fine']    = $entry( 'zdz_core_example_feed_k', 'example_feed_k' );
			return $s;
		};
		$s = ZDZ_Core_Credentials::services();
		foreach ( $core as $id => $def ) {
			$this->assertSame( $def, $s[ $id ], "$id is exactly as Core lists it" );
		}
		foreach ( array( 'steal', 'copycat', 'half' ) as $bad ) {
			$this->assertArrayNotHasKey( $bad, $s, $bad );
		}
		$this->assertArrayHasKey( 'fine', $s );
	}

	public function test_a_fields_own_check_replaces_the_one_token_rule_but_stays_bounded(): void {
		$f = ZDZ_Core_Credentials::services()['charts_hub']['fields'];
		$this->assertTrue( ZDZ_Core_Credentials::field_value_ok( $f['headers'], '{"X-Example-Access": "Bearer abc 123"}' ), 'spaces inside JSON are fine here' );
		$this->assertFalse( ZDZ_Core_Credentials::field_value_ok( $f['headers'], '["a"]' ), 'a list is not a header map' );
		$this->assertFalse( ZDZ_Core_Credentials::field_value_ok( $f['headers'], "{\"X\": \"a\x07b\"}" ), 'never a control character' );
		$this->assertTrue( ZDZ_Core_Credentials::field_value_ok( $f['url'], 'https://hub.example.com/v1/rpc' ) );
		$this->assertFalse( ZDZ_Core_Credentials::field_value_ok( $f['url'], 'javascript:alert(1)' ) );
		$this->assertTrue( ZDZ_Core_Credentials::field_value_ok( $f['hub_public'], self::PUB . ',' . self::SECRET ) );
		$this->assertFalse( ZDZ_Core_Credentials::field_value_ok( $f['site_secret'], 'short' ) );
		$this->assertFalse( ZDZ_Core_Credentials::field_value_ok( array( 'Key', true ), 'has space' ), 'without its own check: one token' );
	}

	/* ── Charts reads the credentials layer first ───────────────────────── */

	public function test_charts_reads_the_credentials_layer_then_its_own_settings(): void {
		$GLOBALS['zdz_test_options']['zch_hub'] = array( 'url' => 'https://old.example.com/v1/rpc', 'hub_public' => self::PUB, 'site_secret' => self::seal( self::SECRET ) );
		$this->assertSame( 'https://old.example.com/v1/rpc', ZCH_Config::hub_url(), 'nothing on Credentials yet: the old settings' );
		$this->assertSame( self::SECRET, ZCH_Config::site_secret() );
		ZCH_Config::flush_creds();

		$GLOBALS['zdz_test_options']['zdz_core_charts_hub_url']     = 'https://hub.example.com/v1/rpc';
		$GLOBALS['zdz_test_options']['zdz_core_charts_site_secret'] = self::seal( strrev( self::SECRET ) );
		$GLOBALS['zdz_test_options']['zdz_core_charts_hub_headers'] = self::seal( '{"X-Example-Access":"abc"}' );
		ZCH_Config::flush_creds();
		$this->assertSame( 'https://hub.example.com/v1/rpc', ZCH_Config::hub_url() );
		$this->assertSame( strrev( self::SECRET ), ZCH_Config::site_secret(), 'opened from its sealed row' );
		$this->assertSame( self::PUB, ZCH_Config::hub_public(), 'a field Credentials does not hold yet: the old settings' );
		$this->assertSame( array( 'X-Example-Access' => 'abc' ), ZCH_Config::headers() );
		$this->assertStringNotContainsString( strrev( self::SECRET ), (string) $GLOBALS['zdz_test_options']['zdz_core_charts_site_secret'], 'never stored plain' );
	}

	public function test_managing_the_hub_adopts_charts_own_values_into_sealed_rows(): void {
		$GLOBALS['zdz_test_options']['zch_hub'] = array( 'url' => 'https://hub.example.com/v1/rpc', 'hub_public' => self::PUB, 'site_secret' => self::seal( self::SECRET ), 'label' => 'the back room' );
		$vals                                   = ZDZ_Core_Credentials::current_values( 'charts_hub' );
		$this->assertSame( self::SECRET, $vals['site_secret'], 'what Charts holds now' );
		$this->assertSame( '', ZDZ_Core_Credentials::apply( 'charts_hub', $vals, 1 ) );
		$this->assertTrue( ZDZ_Core_Credentials::engaged( 'charts_hub' ) );
		$this->assertTrue( ZCH_Config::managed() );
		$row = (string) $GLOBALS['zdz_test_options']['zdz_core_charts_site_secret'];
		$this->assertStringNotContainsString( self::SECRET, $row, 'sealed at rest' );
		$this->assertSame( ZDZ_Core_Crypto::FIXED, ZDZ_Core_Crypto::decode( $row )[0] );
		$this->assertSame( 'the back room', $GLOBALS['zdz_test_options']['zch_hub']['label'], 'Charts\' other settings are its own' );
		ZCH_Config::flush_creds();
		$this->assertSame( self::SECRET, ZCH_Config::site_secret() );
	}

	/* ── the tile ───────────────────────────────────────────────────────── */

	public function test_the_charts_tile_is_added_for_people_charts_allows_only(): void {
		$GLOBALS['zdz_test_user_roles'] = array( 4 => array( 'zdz_sales' ), 9 => array( 'zdz_general' ) );
		$allowed                        = ZCH_Access::allowed( 4 );
		$apps                           = ZCH_Access::extra_allowed( array( 'leads' ), 4 );
		$this->assertSame( $allowed, in_array( 'charts', $apps, true ), 'the tile follows Charts\' own rule' );
		$this->assertSame( array( 'leads' ), ZCH_Access::extra_allowed( array( 'leads' ), 9 ), 'never the shared device' );
		$this->assertSame( array( 'leads' ), ZCH_Access::extra_allowed( array( 'leads' ), 0 ) );
		$this->assertSame( array( 'charts' ), ZCH_Access::extra_allowed( array( 'charts' ), 4 ), 'added once' );
	}
}
