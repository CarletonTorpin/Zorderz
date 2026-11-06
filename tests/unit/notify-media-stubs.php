<?php
/**
 * Shared WordPress stand-ins for the notification, contact, sign-in and media tests.
 *
 * Each stub is guarded, and reads the same globals the other unit tests use
 * ($GLOBALS['zdz_test_options'], zdz_test_filters, zdz_test_user_meta, zdz_test_user_roles,
 * zdz_test_current_user), so whichever file defines a function first, all of them agree.
 * Extra globals used here: zdz_test_user_caps (uid => [cap, ...]), zdz_test_home (site URL).
 *
 * @package Zorderz\Tests
 */

if ( ! function_exists( 'get_user_meta' ) ) {
	function get_user_meta( $uid, $key, $single = false ) { return $GLOBALS['zdz_test_user_meta'][ (int) $uid ][ $key ] ?? ''; }
}
if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id() { return (int) ( $GLOBALS['zdz_test_current_user'] ?? 0 ); }
}
if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( $s ) { return trim( strip_tags( (string) $s ) ); }
}
if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( $url, $component = -1 ) { return -1 === $component ? parse_url( (string) $url ) : parse_url( (string) $url, $component ); }
}
if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( $u ) { return (string) $u; }
}
if ( ! function_exists( 'wp_parse_args' ) ) {
	function wp_parse_args( $a, $d = array() ) { return array_merge( (array) $d, (array) $a ); }
}
if ( ! function_exists( 'sanitize_textarea_field' ) ) {
	function sanitize_textarea_field( $s ) { return trim( strip_tags( (string) $s ) ); }
}
if ( ! function_exists( 'wp_timezone' ) ) {
	function wp_timezone() { return new DateTimeZone( 'UTC' ); }
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $x ) { return $x instanceof WP_Error; }
}
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private $code; private $message; private $data;
		public function __construct( $code = '', $message = '', $data = '' ) { $this->code = $code; $this->message = $message; $this->data = $data; }
		public function get_error_code() { return $this->code; }
		public function get_error_message() { return $this->message; }
		public function get_error_data() { return $this->data; }
	}
}

require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-hierarchy.php';
require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-capabilities.php';
require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-consent.php';

/** Reset every global these tests touch. */
function zdz_notify_test_reset() {
	unset( $GLOBALS['zdz_test_filters'], $GLOBALS['zdz_test_options'], $GLOBALS['zdz_test_user_roles'],
		$GLOBALS['zdz_test_user_meta'], $GLOBALS['zdz_test_current_user'], $GLOBALS['zdz_test_user_caps'], $GLOBALS['zdz_test_home'] );
	if ( class_exists( 'ZDZ_Capabilities' ) ) {
		ZDZ_Capabilities::flush();
	}
}
