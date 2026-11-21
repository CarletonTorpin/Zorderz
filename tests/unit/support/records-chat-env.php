<?php
/**
 * What the chat's records tests load: the WordPress stand-ins the chat needs beyond the shared bootstrap (each guarded),
 * the kernel classes, every class of the chat and the Charts classes the records tool reads through. Used by
 * ChatRecordsTest and ChatDataFitTest.
 *
 * @package Zorderz\Tests
 */

require_once __DIR__ . '/privacy-stubs.php';
require_once dirname( __DIR__ ) . '/llm-test-doubles.php';

foreach ( array(
	'ZANA_APP_ID'     => 'sales-analytics',
	'ZANA_NONCE'      => 'zana_nonce',
	'ZANA_URL'        => 'https://app.example.com/wp-content/plugins/zorderz-apps/apps/analytics/',
	'DAY_IN_SECONDS'  => 86400,
	'ZCH_WEB_PARTY'   => 'zorderz-site',
	'ZCH_HUB_PARTY'   => 'charts-hub',
	'ZDZ_REST_NS'     => 'zorderz/v1',
	'ZCH_APP_ID'      => 'charts',
	'ZCH_OPTION'      => 'zch_settings',
	'ZCH_TABLE_AUDIT' => 'zch_audit',
) as $zdz_test_rcd_k => $zdz_test_rcd_v ) {
	if ( ! defined( $zdz_test_rcd_k ) ) {
		define( $zdz_test_rcd_k, $zdz_test_rcd_v );
	}
}
if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
}
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
}
if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( $s, $d = null ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
}
if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( $url, $component = -1 ) { return -1 === $component ? parse_url( (string) $url ) : parse_url( (string) $url, $component ); }
}
if ( ! function_exists( 'wp_rand' ) ) {
	function wp_rand( $a = 0, $b = 0 ) { return mt_rand( (int) $a, (int) $b ); }
}
if ( ! function_exists( 'sanitize_email' ) ) {
	function sanitize_email( $e ) { return filter_var( (string) $e, FILTER_VALIDATE_EMAIL ) ? (string) $e : ''; }
}
if ( ! function_exists( 'sanitize_title' ) ) {
	function sanitize_title( $s ) { return strtolower( (string) preg_replace( '/[^a-z0-9]+/i', '-', (string) $s ) ); }
}
if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( $k ) { unset( $GLOBALS['zdz_test_transients'][ $k ] ); return true; }
}
if ( ! function_exists( 'update_user_meta' ) ) {
	function update_user_meta( $uid, $key, $value ) { $GLOBALS['zdz_test_user_meta'][ (int) $uid ][ $key ] = $value; return true; }
}
if ( ! function_exists( 'has_filter' ) ) {
	function has_filter( $hook ) { return isset( $GLOBALS['zdz_test_filters'][ $hook ] ); }
}
if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( $u ) { return (string) $u; }
}
if ( ! function_exists( 'wp_create_nonce' ) ) {
	function wp_create_nonce( $a = '' ) { return 'abc123nonce'; }
}
if ( ! function_exists( 'wp_date' ) ) {
	function wp_date( $format, $timestamp = null ) { return gmdate( (string) $format, null === $timestamp ? time() : (int) $timestamp ); }
}

$zdz_test_rcd_root = dirname( __DIR__, 3 );
foreach ( array( 'capabilities', 'hierarchy', 'ask-context', 'privacy-view', 'push-setup', 'sealed' ) as $zdz_test_rcd_f ) {
	require_once $zdz_test_rcd_root . '/zorderz/inc/class-zdz-' . $zdz_test_rcd_f . '.php';
}
foreach ( array( 'markers', 'allowance', 'llm-client', 'plain-math', 'actions', 'pseudonym-route', 'prompt-builder', 'db', 'mail-compose', 'mail-relevance',
	'mail-recall', 'mail-followup', 'mail-crossuser', 'mail-continuation', 'mail-guard', 'email-turn', 'email-cooldown', 'mail-route', 'compose',
	'user-memory', 'turn-timings', 'push', 'charts-tool', 'hours-route', 'plan-routes', 'page-nav', 'vault-links', 'label-clean', 'sealed', 'app-reads',
	'dm-card', 'company-facts', 'starters', 'web-note', 'routes', 'name-variants', 'records', 'data-fit', 'chat' ) as $zdz_test_rcd_f ) {
	require_once $zdz_test_rcd_root . '/zorderz-apps/apps/analytics/includes/class-zana-' . $zdz_test_rcd_f . '.php';
}
foreach ( array( 'envelope', 'config', 'access', 'hub-client', 'store', 'prospect', 'chat-bridge', 'prospect-verb', 'records-verb' ) as $zdz_test_rcd_f ) {
	require_once $zdz_test_rcd_root . '/zorderz-apps/apps/charts/includes/class-zch-' . $zdz_test_rcd_f . '.php';
}
