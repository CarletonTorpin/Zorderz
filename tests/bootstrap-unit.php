<?php
/**
 * Bootstrap for Zorderz UNIT tests: security invariants that need no WordPress and no DB.
 *
 * It stubs the handful of WordPress functions the pure logic touches, then loads the REAL
 * shipping class so the tests pin the actual code, not a copy. Keep this dependency-light on
 * purpose: these tests must run in CI in seconds with no database, so a regression in a
 * security-critical helper turns the build red immediately.
 *
 * @package Zorderz\Tests
 */

define( 'ABSPATH', __DIR__ . '/' );

if ( ! function_exists( 'apply_filters' ) ) {
	// Pass-through: unit tests exercise the default (unfiltered) behavior.
	function apply_filters( $hook, $value = null ) { return $value; }
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $s ) { return is_string( $s ) ? trim( $s ) : ''; }
}
if ( ! function_exists( 'wp_salt' ) ) {
	// Deterministic per-scheme secret so the HMAC share-link tests are stable; distinct per
	// scheme so domain-separation assertions are meaningful. Never the real salt.
	function wp_salt( $scheme = 'auth' ) { return 'zorderz-unit-test-salt::' . $scheme; }
}

/**
 * Minimal stand-in for the core settings class so ZDZ_Data_Portability::secret_option_names()
 * can consume the authoritative secret-field list without booting WordPress. The real list is
 * separately pinned by CoreSettingsCouplingTest against the shipping file.
 */
if ( ! class_exists( 'ZDZ_Core_Settings' ) ) {
	class ZDZ_Core_Settings {
		public static function secret_fields(): array {
			return array( 'poe_api_key', 'fb_client_secret', 'fb_access_token', 'fb_refresh_token', 'ns_api_key', 'review_bridge_key' );
		}
	}
}

require_once dirname( __DIR__ ) . '/zorderz/inc/class-zdz-data-portability.php';
require_once dirname( __DIR__ ) . '/zorderz/inc/class-zdz-kpi-metrics.php'; // for is_financial_metric()
require_once dirname( __DIR__ ) . '/zorderz/inc/class-zdz-share-link.php';  // HMAC share-link primitive
