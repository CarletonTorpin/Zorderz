<?php
/**
 * Shared stand-ins for the privacy kernel's unit tests: the few WordPress functions the coder, the vault, the usage
 * records and "Ask chat about this" touch, and a small in-memory $wpdb that keeps rows in arrays. Every function is
 * guarded (the bootstrap's WordPress stand-ins, users, capabilities and REST classes among them, come first); every
 * value is read from the same globals the other suites use ($GLOBALS['zdz_test_options'], zdz_test_filters,
 * zdz_test_user_roles, zdz_test_current_user).
 *
 * @package Zorderz\Tests
 */

foreach ( array( 'HOUR_IN_SECONDS' => 3600, 'DAY_IN_SECONDS' => 86400, 'MINUTE_IN_SECONDS' => 60, 'ARRAY_A' => 'ARRAY_A' ) as $c => $v ) {
	if ( ! defined( $c ) ) {
		define( $c, $v );
	}
}
// Real-looking salts, so the vault's "fail closed without real salts" rule lets the suite seal. Never real values.
foreach ( array( 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT' ) as $c ) {
	if ( ! defined( $c ) ) {
		define( $c, 'zorderz-unit-test-' . strtolower( $c ) . '-0123456789abcdef' );
	}
}

if ( ! function_exists( 'update_option' ) ) {
	function update_option( $k, $v, $autoload = null ) { $GLOBALS['zdz_test_options'][ $k ] = $v; return true; }
}
if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( $k ) { unset( $GLOBALS['zdz_test_options'][ $k ] ); return true; }
}
if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( $k ) { return $GLOBALS['zdz_test_transients'][ $k ] ?? false; }
}
if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( $k, $v, $ttl = 0 ) { $GLOBALS['zdz_test_transients'][ $k ] = $v; return true; }
}
if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id() { return (int) ( $GLOBALS['zdz_test_current_user'] ?? 0 ); }
}
if ( ! function_exists( 'get_user_meta' ) ) {
	function get_user_meta( $uid, $key, $single = false ) { return $GLOBALS['zdz_test_user_meta'][ (int) $uid ][ $key ] ?? ''; }
}
if ( ! function_exists( 'is_user_logged_in' ) ) {
	function is_user_logged_in() { return (int) ( $GLOBALS['zdz_test_current_user'] ?? 0 ) > 0; }
}
if ( ! function_exists( 'absint' ) ) {
	function absint( $v ) { return abs( (int) $v ); }
}
if ( ! function_exists( 'sanitize_textarea_field' ) ) {
	function sanitize_textarea_field( $s ) { return is_string( $s ) ? trim( strip_tags( $s ) ) : ''; }
}
if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( $s ) { return trim( strip_tags( (string) $s ) ); }
}
if ( ! function_exists( 'wp_timezone' ) ) {
	function wp_timezone() { return new DateTimeZone( 'UTC' ); }
}
if ( ! function_exists( '_n' ) ) {
	function _n( $one, $many, $n, $d = null ) { return 1 === (int) $n ? $one : $many; }
}

if ( ! class_exists( 'ZDZ_Test_Vault_DB' ) ) {
	/**
	 * An in-memory $wpdb: tables are arrays of rows. Understands the handful of statements the privacy kernel makes;
	 * anything else answers null (as a failed read would).
	 */
	class ZDZ_Test_Vault_DB {
		public $prefix     = 'wp_';
		public $insert_id  = 0;
		public $last_error = '';
		public $tables     = array();
		public $fail_insert = false;

		public function prepare( $sql, ...$args ) {
			if ( 1 === count( $args ) && is_array( $args[0] ) ) {
				$args = $args[0];
			}
			$i = 0;
			return preg_replace_callback( '/%[dsf]/', function ( $m ) use ( &$i, $args ) {
				$v = $args[ $i++ ] ?? '';
				return '%d' === $m[0] ? (string) (int) $v : "'" . addslashes( (string) $v ) . "'";
			}, $sql );
		}
		public function esc_like( $s ) { return $s; }
		public function get_charset_collate() { return ''; }
		public function insert( $table, $row, $fmt = null ) {
			if ( $this->fail_insert ) {
				return false;
			}
			$this->tables[ $table ] = $this->tables[ $table ] ?? array();
			$this->insert_id        = count( $this->tables[ $table ] ) + 1;
			$this->tables[ $table ][ $this->insert_id ] = array( 'id' => $this->insert_id ) + $row;
			return 1;
		}
		public function query( $sql ) {
			if ( preg_match( '/^CREATE TABLE IF NOT EXISTS `([^`]+)`/', $sql, $m ) ) {
				$this->tables[ $m[1] ] = $this->tables[ $m[1] ] ?? array();
				return true;
			}
			return 0;
		}
		public function get_var( $sql ) {
			if ( preg_match( '/^SELECT customer_name FROM (\S+) WHERE customer_id = (\d+)/', $sql, $m ) ) {
				foreach ( $this->tables[ $m[1] ] ?? array() as $r ) {
					if ( (int) ( $r['customer_id'] ?? 0 ) === (int) $m[2] ) {
						return $r['customer_name'];
					}
				}
				return null;
			}
			if ( preg_match( '/^SHOW TABLES LIKE \'([^\']+)\'/', $sql, $m ) ) {
				return isset( $this->tables[ $m[1] ] ) ? $m[1] : null;
			}
			if ( preg_match( '/^SELECT MAX\(id\) FROM `([^`]+)`/', $sql, $m ) ) {
				$ids = array_keys( $this->tables[ $m[1] ] ?? array() );
				return $ids ? max( $ids ) : null;
			}
			return null;
		}
		public function get_row( $sql, $out = null ) {
			if ( preg_match( '/FROM `([^`]+)` WHERE id = (\d+)/', $sql, $m ) ) {
				return $this->tables[ $m[1] ][ (int) $m[2] ] ?? null;
			}
			return null;
		}
		public function get_results( $sql, $out = null ) { return array(); }
		public function get_col( $sql ) { return array(); }
		public function rows( $table ) { return array_values( $this->tables[ $this->prefix . $table ] ?? array() ); }
	}
}
