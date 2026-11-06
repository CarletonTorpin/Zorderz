<?php
/**
 * Small kernel rules, each with its own test:
 *
 *   - The Review Bridge key travels in a neutral header; a site may name another through a filter, but only a plain
 *     header name is ever taken (never a second header or a line break).
 *   - One fresh-nonce action: every widget posts the platform's own name, and no older alias is registered.
 *   - The usage-records notice states how long detailed records are kept; changing that number (by a pack or a
 *     filter) changes the version people agreed to, so they are asked again. The days the notice was written for
 *     keep the notice's own version.
 *   - The privacy coder's postcode pattern always matches the whole number, however a site wrote it, as the pack's
 *     check requires: a longer number that merely contains a postcode is never let off.
 *   - A pack's notice grants name a category no registered notice uses: the preview says so (a likely typo).
 *
 * Neutral data only.
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/support/privacy-stubs.php';
if ( ! function_exists( 'update_user_meta' ) ) {
	function update_user_meta( $uid, $key, $value ) { $GLOBALS['zdz_test_user_meta'][ (int) $uid ][ $key ] = $value; return true; }
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( $k, $v, $autoload = null ) { $GLOBALS['zdz_test_options'][ $k ] = $v; return true; }
}
require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-core-review-bridge.php';
require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-consent.php';
require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-admin-dashboard.php';
require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-identity-pack.php';
require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-sealed.php';
require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-alert-router.php';
if ( ! function_exists( 'wp_parse_args' ) ) {
	function wp_parse_args( $a, $d = array() ) { return array_merge( (array) $d, (array) $a ); }
}

final class KWireKernelTest extends TestCase {

	protected function tearDown(): void {
		unset( $GLOBALS['zdz_test_filters']['zdz_review_bridge_header'], $GLOBALS['zdz_test_filters']['zdz_audit_raw_days'] );
		unset( $GLOBALS['zdz_test_user_meta'], $GLOBALS['zdz_test_user_roles'] );
		$GLOBALS['zdz_test_options'] = array();
	}

	/* ── Review Bridge header ────────────────────────────────────────────── */

	private static function headers_with_key( string $key ): array {
		$rb = ( new ReflectionClass( 'ZDZ_Core_ReviewBridge' ) )->newInstanceWithoutConstructor();
		$p  = new ReflectionProperty( 'ZDZ_Core_ReviewBridge', 'api_key' );
		$p->setAccessible( true );
		$p->setValue( $rb, $key );
		$m = new ReflectionMethod( 'ZDZ_Core_ReviewBridge', 'auth_headers' );
		$m->setAccessible( true );
		return $m->invoke( $rb );
	}

	public function test_review_bridge_sends_a_neutral_header_by_default(): void {
		$this->assertSame( 'X-Review-Bridge-Key', ZDZ_Core_ReviewBridge::header_name() );
		$this->assertSame( array( 'X-Review-Bridge-Key' => 'k-123' ), self::headers_with_key( 'k-123' ) );
	}

	public function test_review_bridge_header_name_may_be_changed_by_a_filter_but_only_to_a_plain_name(): void {
		$GLOBALS['zdz_test_filters']['zdz_review_bridge_header'] = function () { return 'X-Example-Bridge-Key'; };
		$this->assertSame( array( 'X-Example-Bridge-Key' => 'k-123' ), self::headers_with_key( 'k-123' ) );

		foreach ( array( "X-Key\r\nX-Other: 1", 'X Key', '', '-X-Key', str_repeat( 'a', 65 ), array( 'X-Key' ), 'X-Key:' ) as $bad ) {
			$GLOBALS['zdz_test_filters']['zdz_review_bridge_header'] = function () use ( $bad ) { return $bad; };
			$this->assertSame( ZDZ_Core_ReviewBridge::KEY_HEADER, ZDZ_Core_ReviewBridge::header_name(), 'refused: ' . json_encode( $bad ) );
		}
	}

	/* ── One fresh-nonce action ──────────────────────────────────────────── */

	public function test_every_widget_asks_the_one_fresh_nonce_action(): void {
		$root = dirname( __DIR__, 2 );
		$fn   = (string) file_get_contents( $root . '/zorderz/functions.php' );
		$this->assertStringContainsString( "add_action( 'wp_ajax_zdz_fresh_nonce'", $fn );
		$this->assertSame( 1, preg_match_all( '/wp_ajax_[a-z]+_fresh_nonce/', $fn ), 'one action, no alias' );
		$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/zorderz-apps/apps', FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $f ) {
			if ( in_array( $f->getExtension(), array( 'js', 'php' ), true ) && preg_match( "/action['\"]?\s*[,:]\s*['\"][a-z]+_fresh_nonce/", (string) file_get_contents( $f->getPathname() ), $m ) ) {
				$this->assertStringContainsString( 'zdz_fresh_nonce', $m[0], $f->getPathname() . ' posts the platform\'s action' );
			}
		}
		$this->assertStringContainsString( "'zdz_fresh_nonce'", (string) file_get_contents( $root . '/zorderz-apps/apps/scheduler/assets/js/widget.js' ) );
	}

	/* ── Consent follows the retention days ─────────────────────────────── */

	public function test_the_retention_days_are_part_of_the_version_people_agree_to(): void {
		$this->assertSame( ZDZ_Consent::VERSION, ZDZ_Consent::with_retention( ZDZ_Consent::VERSION, ZDZ_Admin_Dashboard::RAW_DAYS ), 'the days the notice was written for' );
		$this->assertSame( 3, ZDZ_Consent::with_retention( 3, 0 ), 'a notice that states no days' );
		$this->assertSame( 200120, ZDZ_Consent::with_retention( 2, 120 ) );
		$this->assertNotSame( ZDZ_Consent::with_retention( 2, 120 ), ZDZ_Consent::with_retention( 2, 365 ) );
		$this->assertSame( ZDZ_Consent::VERSION, ZDZ_Consent::version() );
	}

	public function test_a_pack_changing_the_retention_days_asks_everyone_again(): void {
		$GLOBALS['zdz_test_options']    = array( ZDZ_Consent::OPT_ON => '1' );
		$GLOBALS['zdz_test_user_roles'] = array( 7 => array( 'zdz_sales' ) );
		$GLOBALS['zdz_test_user_meta'][7][ ZDZ_Consent::META ] = array( 'version' => ZDZ_Consent::version(), 'accepted_at' => '2026-01-01T00:00:00Z' );
		$this->assertTrue( ZDZ_Consent::has( 'usage_records', 7 ) );
		$this->assertFalse( ZDZ_Consent::should_ask( 7 ) );

		// what the pack's rules.yml usage_records.raw_days writes, read back through the filter the pack answers
		$plan = ZDZ_Identity_Pack::plan_raw_days( array( 'usage_records.raw_days' => 120 ) );
		$this->assertSame( array(), $plan['refused'] );
		$GLOBALS['zdz_test_options'][ ZDZ_Identity_Pack::SETTINGS_OPTION ] = array( 'usage_records.raw_days' => 120 );
		$GLOBALS['zdz_test_filters']['zdz_audit_raw_days'] = function ( $v ) { return ZDZ_Identity_Pack::filtered( 'usage_records.raw_days', $v ); };
		$this->assertStringContainsString( 'deleted after 120 days', ZDZ_Consent::wording() );
		$this->assertFalse( ZDZ_Consent::has( 'usage_records', 7 ), 'an agreement to another number of days is not this one' );
		$this->assertTrue( ZDZ_Consent::should_ask( 7 ), 'asked again' );

		$this->assertTrue( ZDZ_Consent::answer( 7, 'accept' ) );
		$this->assertTrue( ZDZ_Consent::has( 'usage_records', 7 ) );
		unset( $GLOBALS['zdz_test_filters']['zdz_audit_raw_days'] );
		$this->assertTrue( ZDZ_Consent::should_ask( 7 ), 'and again when it goes back' );
	}

	/* ── The postcode is matched whole ───────────────────────────────────── */

	public function test_the_postcode_pattern_matches_the_whole_number(): void {
		$this->assertSame( '^(?:\\d{5})$', ZDZ_Sealed::whole( '\\d{5}' ) );
		$GLOBALS['zdz_test_options'][ ZDZ_Sealed::POSTCODE_OPT ] = array( 'digits' => '\\d{5}', 'region' => 'ST' );   // written unanchored
		ZDZ_Sealed::flush_lists();
		$this->assertSame( '12345 - [gate code sealed]', ZDZ_Sealed::cut( '12345 - gate code 1066' ), 'the postcode itself is let off' );
		$this->assertStringNotContainsString( '123456', ZDZ_Sealed::cut( '123456 - gate code 1066' ), 'a longer number is not a postcode' );

		$GLOBALS['zdz_test_options'][ ZDZ_Sealed::POSTCODE_OPT ] = array( 'digits' => '^\\d{5}$', 'region' => 'ST' );   // as a pack writes it
		ZDZ_Sealed::flush_lists();
		$this->assertSame( '12345 - [gate code sealed]', ZDZ_Sealed::cut( '12345 - gate code 1066' ) );
		$this->assertStringNotContainsString( '123456', ZDZ_Sealed::cut( '123456 - gate code 1066' ) );
		unset( $GLOBALS['zdz_test_options'][ ZDZ_Sealed::POSTCODE_OPT ] );
		ZDZ_Sealed::flush_lists();
	}

	/* ── Notice grants name categories in use ───────────────────────────── */

	public function test_a_grant_for_a_category_no_notice_uses_is_named_at_preview(): void {
		$router = ZDZ_Alert_Router::get_instance();
		$types  = new ReflectionProperty( 'ZDZ_Alert_Router', 'types' );
		$types->setAccessible( true );
		$kept = $types->getValue( $router );
		$types->setValue( $router, array() );
		$raw = array( 'zdz_sales' => array( 'sales' => array( 'push' ), 'salse' => array( 'push' ) ) );

		$p = ZDZ_Identity_Pack::plan_alert_grants( array( 'alerts.role_category_grants' => $raw ) );
		$this->assertStringContainsString( 'could not be checked', implode( ' ', $p['notes'] ), 'no notice types loaded: said, not guessed' );

		$router->register_type( 'example_lead_forward', array( 'category' => 'sales' ) );
		$router->register_type( 'example_backup_failed', array( 'category' => 'system' ) );
		$this->assertSame( array( 'sales', 'system' ), ZDZ_Alert_Router::categories_in_use() );
		$p     = ZDZ_Identity_Pack::plan_alert_grants( array( 'alerts.role_category_grants' => $raw ) );
		$notes = implode( ' ', $p['notes'] );
		$this->assertStringContainsString( '"salse"', $notes );
		$this->assertStringNotContainsString( '"sales"', $notes );
		$this->assertSame( array(), $p['refused'], 'kept, not refused: an app installed later may use it' );
		$this->assertArrayHasKey( 'salse', $p['writes'][0]['value']['zdz_sales'] );

		$p = ZDZ_Identity_Pack::plan_alert_grants( array( 'alerts.role_category_grants' => array( 'zdz_sales' => array( 'sales' => array( 'push' ) ) ) ) );
		$this->assertSame( array(), $p['notes'] );
		$types->setValue( $router, $kept );
	}
}
