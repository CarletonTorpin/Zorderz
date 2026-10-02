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

// Keep intentional error_log() diagnostics from the code under test out of the test output.
ini_set( 'error_log', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null' );

/*
 * Filters: pass-through by default, so tests exercise the unfiltered behaviour. A test that
 * needs a filter (e.g. to prove a tenant cannot loosen a safety floor) registers a callable
 * in $GLOBALS['zdz_test_filters'][ $hook ] and removes it afterwards.
 */
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook, $value = null, ...$args ) {
		if ( isset( $GLOBALS['zdz_test_filters'][ $hook ] ) && is_callable( $GLOBALS['zdz_test_filters'][ $hook ] ) ) {
			return call_user_func( $GLOBALS['zdz_test_filters'][ $hook ], $value, ...$args );
		}
		return $value;
	}
}
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( ...$a ) { return true; }
}
if ( ! function_exists( 'add_action' ) ) {
	function add_action( ...$a ) { return true; }
}
if ( ! function_exists( 'do_action' ) ) {
	function do_action( ...$a ) { return null; }
}
if ( ! function_exists( 'get_option' ) ) {
	function get_option( $k, $d = false ) { return $GLOBALS['zdz_test_options'][ $k ] ?? $d; }
}
if ( ! function_exists( '__' ) ) {
	function __( $s, $d = null ) { return $s; }
}
if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $k ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $k ) ); }
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
require_once dirname( __DIR__ ) . '/zorderz/inc/class-zdz-answer-authority.php'; // threshold floor clamp
require_once dirname( __DIR__ ) . '/zorderz/inc/class-zdz-rule-governance.php';  // safety-floor merge
require_once dirname( __DIR__ ) . '/zorderz/inc/class-zdz-name-match.php';       // shared name matcher
require_once dirname( __DIR__ ) . '/zorderz/inc/class-zdz-contact-bridge.php';   // §133 disclosure gate
