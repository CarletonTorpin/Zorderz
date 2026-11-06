<?php
/**
 * Shared stand-ins for the Estimates app's unit tests: the WordPress functions its pure decisions touch (each guarded,
 * each reading the suite's shared globals: zdz_test_options, zdz_test_user_meta, zdz_test_user_roles,
 * zdz_test_current_user, zdz_test_transients, zdz_test_filters), a small in-memory $wpdb for the estimates table
 * (ZDZ_Test_Est_DB), and the app's classes.
 *
 * @package Zorderz\Tests
 */

require_once __DIR__ . '/privacy-stubs.php';

if ( ! function_exists( 'add_user_meta' ) ) {
	function add_user_meta( $uid, $key, $value, $unique = false ) {
		if ( $unique && isset( $GLOBALS['zdz_test_user_meta'][ (int) $uid ][ $key ] ) ) {
			return false;
		}
		$GLOBALS['zdz_test_user_meta'][ (int) $uid ][ $key ] = $value;
		return true;
	}
}
if ( ! function_exists( 'update_user_meta' ) ) {
	function update_user_meta( $uid, $key, $value ) {
		$GLOBALS['zdz_test_user_meta'][ (int) $uid ][ $key ] = $value;
		return true;
	}
}
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
}
if ( ! function_exists( 'wp_date' ) ) {
	function wp_date( $f, $ts = null ) { return gmdate( $f, null === $ts ? time() : (int) $ts ); }
}

if ( ! class_exists( 'ZDZ_Test_Est_DB' ) ) {
	/** Just the estimates table, in memory: get_row by id, by hold, by document number or billing id; update by id. */
	final class ZDZ_Test_Est_DB {
		public $prefix     = 'wp_';
		public $insert_id  = 0;
		public $last_error = '';
		public $rows       = array();
		public function prepare( $sql, ...$args ) {
			$args = ( 1 === count( $args ) && is_array( $args[0] ) ) ? $args[0] : $args;
			$i    = 0;
			return preg_replace_callback( '/%[dsf]/', function ( $m ) use ( &$i, $args ) {
				$v = $args[ $i++ ] ?? '';
				return '%d' === $m[0] ? (string) (int) $v : "'" . addslashes( (string) $v ) . "'";
			}, $sql );
		}
		public function get_row( $q, $o = null ) {
			if ( preg_match( '/WHERE id = (\d+)/', $q, $m ) ) {
				return $this->rows[ (int) $m[1] ] ?? null;
			}
			if ( preg_match( "/WHERE TRIM\(LEADING '0' FROM billing_doc_num\) = '([^']*)'/", $q, $m ) || preg_match( "/WHERE billing_doc_num = '([^']*)'/", $q, $m ) ) {
				$trim = false !== strpos( $q, 'TRIM(' );
				foreach ( array_reverse( $this->rows, true ) as $r ) {
					$n = (string) ( $r['billing_doc_num'] ?? '' );
					if ( ( $trim ? ltrim( $n, '0' ) : $n ) === $m[1] ) {
						return $r;
					}
				}
				return null;
			}
			if ( preg_match( "/WHERE billing_doc_id = '([^']*)'/", $q, $m ) ) {
				foreach ( array_reverse( $this->rows, true ) as $r ) {
					if ( (string) ( $r['billing_doc_id'] ?? '' ) === $m[1] ) {
						return $r;
					}
				}
				return null;
			}
			if ( preg_match( "/WHERE seal_hold = '([^']*)'/", $q, $m ) ) {
				foreach ( array_reverse( $this->rows, true ) as $r ) {
					if ( (string) ( $r['seal_hold'] ?? '' ) === $m[1] ) {
						return $r;
					}
				}
			}
			return null;
		}
		public function get_results( $q, $o = null ) { return array(); }
		public function get_var( $q ) { return null; }
		public function get_col( $q ) { return array(); }
		public function query( $q ) { return 0; }
		public function esc_like( $s ) { return $s; }
		public function insert( $t, $row ) {
			$this->insert_id = count( $this->rows ) + 1;
			$this->rows[ $this->insert_id ] = array( 'id' => $this->insert_id ) + $row;
			return 1;
		}
		public function update( $t, $data, $where ) {
			$id = (int) ( $where['id'] ?? 0 );
			if ( ! isset( $this->rows[ $id ] ) ) {
				return 0;
			}
			$this->rows[ $id ] = array_merge( $this->rows[ $id ], $data );
			return 1;
		}
	}
}

if ( ! defined( 'ZEST_APP_ID' ) ) {
	define( 'ZEST_APP_ID', 'estimate-creator' );
}
if ( ! defined( 'ZEST_NONCE' ) ) {
	define( 'ZEST_NONCE', 'zest_nonce' );
}

$zest_inc = dirname( __DIR__, 3 ) . '/zorderz-apps/apps/estimate/includes/';
require_once dirname( __DIR__, 3 ) . '/zorderz/inc/class-zdz-name-match.php';
require_once dirname( __DIR__, 3 ) . '/zorderz/inc/class-zdz-sealed.php';
foreach ( array( 'db', 'freshbooks', 'deposit-limit', 'deposit-floor', 'deposit-rule', 'access', 'salesperson', 'access-requests', 'billing-doc',
	'contract-address', 'payment-plan', 'terms-note', 'deposit-check', 'price-book-lines', 'warranty-note', 'sale-form',
	'paper-signing', 'approve', 'sealed', 'edit-token', 'line-edits', 'client-index', 'territories', 'accept-watch',
	'line-options', 'pairs', 'door-handle', 'added-measurements', 'billing-view', 'reassign', 'zana-bridge' ) as $zest_f ) {
	require_once $zest_inc . 'class-zest-' . $zest_f . '.php';
}
unset( $zest_inc, $zest_f );
