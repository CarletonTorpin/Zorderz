<?php
/**
 * The credentials layer (Zorderz → Credentials):
 *   - every copy's at-rest format is read exactly, and the inventory never carries a value;
 *   - apply() writes each copy in its declared format, and each app's OWN reader gets the key back;
 *   - Undo restores every row byte for byte (one field of an array option on its own);
 *   - the write guard refuses another screen's change, and keeps a same-key re-save silently;
 *   - the kill switch turns the guards off;
 *   - retire deletes only copies whose app falls back to Core;
 *   - the billing secret is served to each reader in the format it decodes.
 *
 * Runs against the real ZDZ_Core_Crypto and the real app readers (Leads' decrypt_shared, the
 * Knowledge Vault's decrypt_key) over an in-memory options table.
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'update_option' ) ) {
	function update_option( $k, $v, $autoload = null ) { $GLOBALS['zdz_test_options'][ $k ] = $v; return true; }
}
if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( $k ) { unset( $GLOBALS['zdz_test_options'][ $k ] ); return true; }
}
if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id() { return (int) ( $GLOBALS['zdz_test_current_user'] ?? 0 ); }
}
if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( $k ) { return $GLOBALS['zdz_test_transients'][ $k ] ?? false; }
}
if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( $k, $v, $ttl = 0 ) { $GLOBALS['zdz_test_transients'][ $k ] = $v; return true; }
}
if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( $k ) { unset( $GLOBALS['zdz_test_transients'][ $k ] ); return true; }
}
if ( ! function_exists( 'wp_cache_delete' ) ) {
	function wp_cache_delete( $k, $g = '' ) { return true; }
}
if ( ! function_exists( 'admin_url' ) ) {
	function admin_url( $p = '' ) { return 'https://example.com/wp-admin/' . ltrim( (string) $p, '/' ); }
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $x ) { return $x instanceof WP_Error; }
}
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private $m;
		public function __construct( $c = '', $m = '' ) { $this->m = $m; }
		public function get_error_message() { return $this->m; }
	}
}
if ( ! function_exists( 'wp_remote_retrieve_header' ) ) {
	// Header names are case-insensitive, as in WordPress's response object.
	function wp_remote_retrieve_header( $r, $h ) {
		if ( ! is_array( $r ) || ! is_array( $r['headers'] ?? null ) ) {
			return '';
		}
		foreach ( $r['headers'] as $k => $v ) {
			if ( strtolower( (string) $k ) === strtolower( $h ) ) {
				return (string) $v;
			}
		}
		return '';
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
}
if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
}
if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
}
if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( $s, $d = null ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
}
if ( ! function_exists( 'esc_attr__' ) ) {
	function esc_attr__( $s, $d = null ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
}
if ( ! function_exists( 'wp_nonce_field' ) ) {
	function wp_nonce_field( $a = -1, $n = '_wpnonce', $r = true, $echo = true ) { $h = '<input type="hidden" name="_wpnonce" value="nonce">'; if ( $echo ) { echo $h; } return $h; }
}
if ( ! function_exists( 'wp_create_nonce' ) ) {
	function wp_create_nonce( $a = -1 ) { return 'nonce'; }
}

/**
 * The options table as WordPress keeps it: each row a string (arrays serialized), read and
 * written by the credentials layer through $wpdb. The same rows back get_option().
 */
if ( ! class_exists( 'ZDZ_Test_Options_DB' ) ) {
	class ZDZ_Test_Options_DB {
		public $options = 'wp_options';
		/** @var string[] rows whose writes silently do nothing (a refused or lost write) */
		public $deaf = array();
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
			if ( ! array_key_exists( $name, $GLOBALS['zdz_test_options'] ?? array() ) ) {
				return null;
			}
			return self::to_row( $GLOBALS['zdz_test_options'][ $name ] );
		}
		public function insert( $t, $row ) {
			if ( in_array( $row['option_name'], $this->deaf, true ) ) {
				return 1;
			}
			$GLOBALS['zdz_test_options'][ $row['option_name'] ] = self::from_row( (string) $row['option_value'] );
			return 1;
		}
		public function update( $t, $data, $where ) {
			if ( in_array( $where['option_name'], $this->deaf, true ) ) {
				return 1;
			}
			$GLOBALS['zdz_test_options'][ $where['option_name'] ] = self::from_row( (string) $data['option_value'] );
			return 1;
		}
		public function delete( $t, $where ) {
			unset( $GLOBALS['zdz_test_options'][ $where['option_name'] ] );
			return 1;
		}
	}
}

require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-core-crypto.php';
require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-fb-credentials.php';
require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-core-credentials.php';
require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-cred-expiry.php';
require_once dirname( __DIR__, 2 ) . '/zorderz-apps/apps/leads/includes/class-zl-admin.php';
require_once dirname( __DIR__, 2 ) . '/zorderz-apps/apps/knowledge/includes/class-zkv-dashboard.php';

final class CredentialsLayerTest extends TestCase {

	/** Neutral stand-in keys (never real). */
	const K_OLD = 'gw-old-0000000000000000000000000000aaaa';
	const K_ALT = 'gw-alt-1111111111111111111111111111bbbb';
	const K_NEW = 'gw-new-2222222222222222222222222222cccc';

	protected function setUp(): void {
		$GLOBALS['zdz_test_options']      = array();
		$GLOBALS['zdz_test_transients']   = array();
		$GLOBALS['zdz_test_current_user'] = 7;
		$GLOBALS['wpdb']                  = new ZDZ_Test_Options_DB();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['zdz_test_options'], $GLOBALS['zdz_test_filters'], $GLOBALS['zdz_test_transients'], $GLOBALS['zdz_test_current_user'],
			$GLOBALS['zdz_test_user_caps'], $GLOBALS['wpdb'] );
	}

	private static function row( string $name ): ?string {
		return array_key_exists( $name, $GLOBALS['zdz_test_options'] ) ? ZDZ_Test_Options_DB::to_row( $GLOBALS['zdz_test_options'][ $name ] ) : null;
	}

	/** Every row a service touches, as stored strings (null = absent). */
	private static function snapshot( string $svc ): array {
		$def  = ZDZ_Core_Credentials::services()[ $svc ];
		$rows = array_values( $def['canonical'] );
		foreach ( $def['copies'] as $copies ) {
			$rows = array_merge( $rows, array_keys( $copies ) );
		}
		$out = array();
		foreach ( array_unique( $rows ) as $r ) {
			$out[ $r ] = self::row( $r );
		}
		return $out;
	}

	public function test_every_copy_declares_a_format_the_decoder_knows(): void {
		$ok = array( ZDZ_Core_Crypto::PLAIN, ZDZ_Core_Crypto::FIXED, ZDZ_Core_Crypto::PAIR );
		foreach ( ZDZ_Core_Credentials::services() as $svc => $def ) {
			$this->assertNotEmpty( $def['label'], $svc );
			foreach ( $def['canonical'] as $field => $row ) {
				$this->assertSame( 0, strpos( $row, 'zdz_core_' ), "$svc canonical $row is Core's own row" );
			}
			foreach ( $def['copies'] as $field => $copies ) {
				foreach ( $copies as $name => $meta ) {
					$this->assertContains( $meta[0], $ok, "$svc $name format" );
					$this->assertMatchesRegularExpression( '/^[a-z0-9_]+$/', $name );
				}
			}
		}
	}

	public function test_inventory_reads_every_format_and_never_carries_a_value(): void {
		update_option( 'zdz_core_poe_api_key', ZDZ_Core_Crypto::encode( self::K_OLD, ZDZ_Core_Crypto::FIXED ) );
		update_option( 'zl_poe_api_key', ZDZ_Core_Crypto::encode( self::K_OLD, ZDZ_Core_Crypto::PAIR ) );
		update_option( 'zkv_poe_api_key', self::K_ALT ); // stored plain by hand
		$inv = ZDZ_Core_Credentials::inventory( 'poe' );
		$this->assertCount( 2, $inv );
		$fp_old = ZDZ_Core_Crypto::fingerprint( self::K_OLD );
		$this->assertSame( 'K1', $inv[ $fp_old ]['label'] );
		$this->assertSame( strlen( self::K_OLD ), $inv[ $fp_old ]['len'] );
		$this->assertSame( array( 'zdz_core_poe_api_key' => 'fixed-IV', 'zl_poe_api_key' => 'pair-IV' ), $inv[ $fp_old ]['rows'] );
		$this->assertSame( array( 'zkv_poe_api_key' => 'plain' ), $inv[ ZDZ_Core_Crypto::fingerprint( self::K_ALT ) ]['rows'] );

		$copies = ZDZ_Core_Credentials::copy_inventory( 'poe' );
		$this->assertCount( 3, $copies );
		$blob = serialize( array( $inv, $copies ) );
		foreach ( array( self::K_OLD, self::K_ALT ) as $k ) {
			$this->assertStringNotContainsString( $k, $blob, 'the inventory carries fingerprints and lengths, never a key' );
		}
		foreach ( $copies as $c ) {
			$this->assertSame( 8, strlen( $c['fp'] ) );
		}
	}

	public function test_a_twice_encrypted_copy_is_read_through_every_layer(): void {
		$twice = ZDZ_Core_Crypto::encode( ZDZ_Core_Crypto::encode( self::K_OLD, ZDZ_Core_Crypto::FIXED ), ZDZ_Core_Crypto::FIXED );
		$this->assertSame( self::K_OLD, ZDZ_Core_Credentials::deep_plain( $twice ) );
		$this->assertNotSame( self::K_OLD, ZDZ_Core_Credentials::one_layer( $twice, array( 'fixed-IV', 'x' ) ), 'one decryption is what the app itself would read' );
		$this->assertSame( 'k-in-array', ZDZ_Core_Credentials::stored_plain( serialize( array( 'f' => 'k-in-array', 'other' => 'x' ) ), array( 'plain', 'x', 'field' => 'f' ) ) );
	}

	public function test_apply_writes_each_copy_in_the_readers_format_and_every_app_reads_it(): void {
		update_option( 'zdz_core_poe_api_key', ZDZ_Core_Crypto::encode( self::K_OLD, ZDZ_Core_Crypto::FIXED ) );
		update_option( 'zl_poe_api_key', self::K_OLD );                     // plain: Leads could not read it
		update_option( 'zkv_poe_api_key', ZDZ_Core_Crypto::encode( self::K_ALT, ZDZ_Core_Crypto::PAIR ) );

		$this->assertSame( '', ZDZ_Core_Credentials::apply( 'poe', array( 'api_key' => self::K_NEW ), 7 ) );
		foreach ( ZDZ_Core_Credentials::services()['poe']['copies']['api_key'] as $name => $meta ) {
			list( $fmt, $plain ) = ZDZ_Core_Crypto::decode( (string) self::row( $name ) );
			$this->assertSame( $meta[0], $fmt, "$name is stored in its reader's format" );
			$this->assertSame( self::K_NEW, $plain, $name );
		}
		// The apps' own readers, unchanged.
		$this->assertSame( self::K_NEW, ZL_Admin::decrypt_shared( 'zl_poe_api_key', 'zl_absent_fallback' ) );
		$this->assertSame( self::K_NEW, ZKV_Dashboard::decrypt_key( get_option( 'zkv_poe_api_key' ) ) );
		$this->assertTrue( ZDZ_Core_Credentials::engaged( 'poe' ) );
		$this->assertSame( self::K_NEW, ZDZ_Core_Credentials::get( 'poe' ) );
	}

	public function test_apply_writes_array_fields_and_plain_app_copies_and_leaves_other_settings(): void {
		update_option( 'zsch_graph_config', array( 'tenant_id' => 'tenant-old', 'client_id' => 'client-old', 'default_tz' => 'UTC', 'sync_enabled' => true ) );
		update_option( 'zsch_graph_secret', 'secret-old-value' );
		$err = ZDZ_Core_Credentials::apply( 'ms_calendar_sync', array( 'tenant_id' => 'tenant-new', 'client_id' => 'client-new', 'client_secret' => 'secret-new-value' ), 7 );
		$this->assertSame( '', $err );
		$cfg = get_option( 'zsch_graph_config' );
		$this->assertSame( 'tenant-new', $cfg['tenant_id'] );
		$this->assertSame( 'client-new', $cfg['client_id'] );
		$this->assertSame( 'UTC', $cfg['default_tz'], 'the app\'s other settings are kept' );
		$this->assertTrue( $cfg['sync_enabled'] );
		$this->assertSame( 'secret-new-value', get_option( 'zsch_graph_secret' ), 'Scheduler reads its secret plain' );
		$this->assertSame( ZDZ_Core_Crypto::FIXED, ZDZ_Core_Crypto::decode( (string) self::row( 'zdz_core_ms_sync_client_secret' ) )[0], 'Core seals its own row' );

		// Stripe: an app service that may create the app's rows.
		$this->assertSame( '', ZDZ_Core_Credentials::apply( 'stripe', array( 'secret' => 'sk_test_example0001', 'webhook_secret' => 'whsec_example0001' ), 7 ) );
		$this->assertSame( 'sk_test_example0001', get_option( 'zic_stripe_secret' ) );
		$this->assertSame( 'whsec_example0001', get_option( 'zic_stripe_webhook_secret' ) );
		$this->assertNull( self::row( 'zic_stripe_publishable' ), 'an empty field is set nowhere' );
	}

	public function test_a_copy_whose_app_has_no_fallback_is_created_when_that_app_is_loaded(): void {
		if ( ! defined( 'ZL_VERSION' ) ) {
			define( 'ZL_VERSION', 'test' );
		}
		update_option( 'zdz_core_poe_api_key', ZDZ_Core_Crypto::encode( self::K_OLD, ZDZ_Core_Crypto::FIXED ) );
		$this->assertSame( '', ZDZ_Core_Credentials::apply( 'poe', array( 'api_key' => self::K_NEW ) ) );
		$this->assertSame( self::K_NEW, ZL_Admin::decrypt_shared( 'zl_poe_api_key', 'zl_absent_fallback' ), 'Leads reads only its own row, so it gets one' );
		$this->assertNull( self::row( 'zkv_poe_api_key' ), 'an app that falls back to Core gets no new row' );
		$this->assertSame( '', ZDZ_Core_Credentials::undo( 'poe' ) );
		$this->assertNull( self::row( 'zl_poe_api_key' ), 'Undo removes the row it created' );
	}

	public function test_undo_restores_every_row_byte_for_byte(): void {
		$GLOBALS['zdz_test_options']['zdz_core_ns_email']   = 'alex@example.com';
		$GLOBALS['zdz_test_options']['zdz_core_ns_api_key'] = ZDZ_Core_Crypto::encode( self::K_OLD, ZDZ_Core_Crypto::FIXED );
		$GLOBALS['zdz_test_options']['zl_ns_email']         = 'jordan@example.com';
		$GLOBALS['zdz_test_options']['zl_ns_api_key']       = ZDZ_Core_Crypto::encode( self::K_ALT, ZDZ_Core_Crypto::FIXED );
		$before = self::snapshot( 'nutshell' );

		$this->assertSame( '', ZDZ_Core_Credentials::apply( 'nutshell', array( 'email' => 'sam@example.com', 'api_key' => self::K_NEW ), 7 ) );
		$this->assertNotSame( $before, self::snapshot( 'nutshell' ) );
		$this->assertTrue( ZDZ_Core_Credentials::has_backup( 'nutshell' ) );

		// A second change keeps the FIRST backup: Undo goes back to before the first change.
		$this->assertSame( '', ZDZ_Core_Credentials::apply( 'nutshell', array( 'email' => 'sam@example.com', 'api_key' => self::K_ALT ), 7 ) );

		$this->assertSame( '', ZDZ_Core_Credentials::undo( 'nutshell' ) );
		$this->assertSame( $before, self::snapshot( 'nutshell' ), 'every row is back, byte for byte' );
		$this->assertFalse( ZDZ_Core_Credentials::has_backup( 'nutshell' ) );
		$this->assertFalse( ZDZ_Core_Credentials::engaged( 'nutshell' ) );
	}

	public function test_undo_restores_one_array_field_and_keeps_later_changes_to_the_rest(): void {
		update_option( 'zib_config', array( 'tenant_id' => 'tenant-a', 'client_id' => 'client-a' ) );
		$this->assertSame( '', ZDZ_Core_Credentials::apply( 'ms_inbox', array( 'tenant_id' => 'tenant-b', 'client_id' => 'client-b', 'client_secret' => 'secret-b-000' ) ) );
		$cfg            = get_option( 'zib_config' );
		$cfg['note']    = 'set by the app after the change';
		update_option( 'zib_config', $cfg );
		$this->assertSame( '', ZDZ_Core_Credentials::undo( 'ms_inbox' ) );
		$this->assertSame( array( 'tenant_id' => 'tenant-a', 'client_id' => 'client-a', 'note' => 'set by the app after the change' ), get_option( 'zib_config' ) );
		$this->assertNull( self::row( 'zib_ms_secret' ), 'a row the change created is gone again' );
		$this->assertNull( self::row( 'zdz_core_ms_inbox_client_secret' ) );
	}

	public function test_a_row_that_does_not_read_back_fails_the_change_and_undo_still_works(): void {
		update_option( 'zdz_core_poe_api_key', ZDZ_Core_Crypto::encode( self::K_OLD, ZDZ_Core_Crypto::FIXED ) );
		update_option( 'zkv_poe_api_key', ZDZ_Core_Crypto::encode( self::K_OLD, ZDZ_Core_Crypto::FIXED ) );
		$before                   = self::snapshot( 'poe' );
		$GLOBALS['wpdb']->deaf    = array( 'zkv_poe_api_key' );
		$err                      = ZDZ_Core_Credentials::apply( 'poe', array( 'api_key' => self::K_NEW ) );
		$this->assertStringContainsString( 'zkv_poe_api_key', $err );
		$this->assertFalse( ZDZ_Core_Credentials::engaged( 'poe' ), 'no state is recorded' );
		$this->assertStringContainsString( 'Undo', ZDZ_Core_Credentials::apply( 'poe', array( 'api_key' => self::K_ALT ) ), 'a backup left by an unfinished change must be undone first' );
		$GLOBALS['wpdb']->deaf = array();
		$this->assertSame( '', ZDZ_Core_Credentials::undo( 'poe' ) );
		$this->assertSame( $before, self::snapshot( 'poe' ) );
	}

	public function test_the_guard_refuses_another_screens_change_and_names_the_row(): void {
		update_option( 'zdz_core_poe_api_key', ZDZ_Core_Crypto::encode( self::K_OLD, ZDZ_Core_Crypto::FIXED ) );
		update_option( 'zkv_poe_api_key', ZDZ_Core_Crypto::encode( self::K_OLD, ZDZ_Core_Crypto::FIXED ) );
		$this->assertSame( '', ZDZ_Core_Credentials::apply( 'poe', array( 'api_key' => self::K_NEW ) ) );

		$plan = ZDZ_Core_Credentials::guard_plan();
		$this->assertContains( array( 'zkv_poe_api_key', '' ), $plan );
		$this->assertContains( array( 'zdz_core_poe_api_key', '' ), $plan );

		$stored  = get_option( 'zkv_poe_api_key' );
		$foreign = ZDZ_Core_Crypto::encode( self::K_ALT, ZDZ_Core_Crypto::FIXED );
		$this->assertSame( $stored, ZDZ_Core_Credentials::guard_value( 'zkv_poe_api_key', $foreign, $stored ), 'another key is refused' );
		$this->assertSame( array( 'zkv_poe_api_key' ), get_transient( ZDZ_Core_Credentials::REFUSED . '7' ) );

		$GLOBALS['zdz_test_transients'] = array();
		$same = ZDZ_Core_Crypto::encode( self::K_NEW, ZDZ_Core_Crypto::PAIR );
		$this->assertSame( $stored, ZDZ_Core_Credentials::guard_value( 'zkv_poe_api_key', $same, $stored ), 'the stored row is kept' );
		$this->assertFalse( get_transient( ZDZ_Core_Credentials::REFUSED . '7' ), 'the same key re-saved is not reported as refused' );

		// One field of an array option: pinned; the app's other settings still save.
		$old = array( 'google_client_id' => 'client-kept', 'ms_client_id' => '', 'conflict_policy' => 'warn' );
		$new = array( 'google_client_id' => 'client-other', 'ms_client_id' => '', 'conflict_policy' => 'block' );
		$out = ZDZ_Core_Credentials::guard_field( 'zsch_conncal_config', 'google_client_id', $new, $old );
		$this->assertSame( 'client-kept', $out['google_client_id'] );
		$this->assertSame( 'block', $out['conflict_policy'] );
	}

	public function test_the_kill_switch_turns_the_guards_off(): void {
		update_option( 'zdz_core_poe_api_key', ZDZ_Core_Crypto::encode( self::K_OLD, ZDZ_Core_Crypto::FIXED ) );
		$this->assertSame( '', ZDZ_Core_Credentials::apply( 'poe', array( 'api_key' => self::K_NEW ) ) );
		$this->assertNotEmpty( ZDZ_Core_Credentials::guard_plan() );
		$GLOBALS['zdz_test_filters']['zdz_single_credentials'] = function () { return false; };
		$this->assertFalse( ZDZ_Core_Credentials::active() );
		$this->assertSame( array(), ZDZ_Core_Credentials::guard_plan() );
	}

	public function test_nothing_is_guarded_before_a_service_is_managed(): void {
		update_option( 'zkv_poe_api_key', ZDZ_Core_Crypto::encode( self::K_OLD, ZDZ_Core_Crypto::FIXED ) );
		$this->assertSame( array(), ZDZ_Core_Credentials::guard_plan() );
	}

	public function test_retire_deletes_only_copies_whose_app_falls_back_to_core(): void {
		update_option( 'zdz_core_poe_api_key', ZDZ_Core_Crypto::encode( self::K_OLD, ZDZ_Core_Crypto::FIXED ) );
		update_option( 'zkv_poe_api_key', ZDZ_Core_Crypto::encode( self::K_OLD, ZDZ_Core_Crypto::FIXED ) );
		update_option( 'zl_poe_api_key', ZDZ_Core_Crypto::encode( self::K_OLD, ZDZ_Core_Crypto::FIXED ) );
		$this->assertSame( '', ZDZ_Core_Credentials::apply( 'poe', array( 'api_key' => self::K_NEW ) ) );
		$this->assertStringContainsString( 'backup', ZDZ_Core_Credentials::retire( 'poe' ), 'the old keys must be let go first' );
		$this->assertSame( '', ZDZ_Core_Credentials::forget_backup( 'poe' ) );
		$this->assertSame( '', ZDZ_Core_Credentials::retire( 'poe', 7 ) );
		$this->assertNull( self::row( 'zkv_poe_api_key' ) );
		$this->assertNotNull( self::row( 'zl_poe_api_key' ), 'Leads reads only its own copy: kept' );
		$this->assertSame( array( array( 'zkv_poe_api_key', '' ) ), ZDZ_Core_Credentials::retired_rows( 'poe' ) );
		$this->assertArrayHasKey( 'zl_poe_api_key', ZDZ_Core_Credentials::kept_copies( 'poe' ) );

		$this->assertSame( '', ZDZ_Core_Credentials::unretire( 'poe' ) );
		$this->assertSame( self::K_NEW, ZKV_Dashboard::decrypt_key( get_option( 'zkv_poe_api_key' ) ), 'brought back with the current key, in its format' );
		$this->assertFalse( ZDZ_Core_Credentials::is_retired( 'poe' ) );
	}

	public function test_differing_names_apps_never_values(): void {
		update_option( 'zsch_google_secret', 'google-secret-a' );
		update_option( 'zdz_core_google_cal_client_secret', ZDZ_Core_Crypto::encode( 'google-secret-b', ZDZ_Core_Crypto::FIXED ) );
		$diff = ZDZ_Core_Credentials::differing( 'google_calendar' );
		$this->assertSame( array( 'Scheduler' ), $diff );
		$this->assertSame( 'google-secret-b', ZDZ_Core_Credentials::current_values( 'google_calendar' )['client_secret'], 'Core\'s row wins' );
	}

	public function test_the_billing_secret_is_served_in_each_readers_format(): void {
		$master = ZDZ_Core_Crypto::encode( 'billing-secret-0001', ZDZ_Core_Crypto::FIXED );
		$this->assertSame( $master, ZDZ_Core_Credentials::serve_in_format( $master, ZDZ_Core_Crypto::FIXED ), 'already that format: unchanged' );
		$this->assertSame( 'billing-secret-0001', ZDZ_Core_Credentials::serve_in_format( $master, ZDZ_Core_Crypto::PLAIN ) );
		$pair = ZDZ_Core_Credentials::serve_in_format( $master, ZDZ_Core_Crypto::PAIR );
		$this->assertSame( array( ZDZ_Core_Crypto::PAIR, 'billing-secret-0001' ), ZDZ_Core_Crypto::decode( $pair ) );
		$this->assertFalse( ZDZ_Core_Credentials::serve_in_format( false, ZDZ_Core_Crypto::FIXED ), 'a view that is not engaged passes through' );

		$this->assertSame( array( 'zl_' => ZDZ_Core_Crypto::FIXED ), ZDZ_Core_FB_Credentials::views() );
		$GLOBALS['zdz_test_filters']['zdz_fb_credential_views'] = function ( $v ) { return $v + array( 'zdz_core_' => 'plain', 'Bad Prefix!' => 'plain', 'zx_' => 'rot13' ); };
		$this->assertSame( array( 'zl_' => ZDZ_Core_Crypto::FIXED, 'badprefix' => 'plain' ), ZDZ_Core_FB_Credentials::views(), 'Core\'s family and unknown formats are never views' );
	}

	public function test_the_billing_views_serve_tokens_decoded_and_the_secret_as_stored(): void {
		ZDZ_Core_FB_Credentials::reset_memo();
		$sealed_secret = ZDZ_Core_Crypto::encode( 'billing-secret-0002', ZDZ_Core_Crypto::FIXED );
		update_option( 'zdz_core_fb_client_secret', $sealed_secret );
		update_option( 'zdz_core_fb_refresh_token', ZDZ_Core_Crypto::encode( 'refresh-token-0002', ZDZ_Core_Crypto::FIXED ) );
		update_option( 'zdz_core_fb_client_id', 'client-0002' );
		update_option( 'zdz_core_fb_access_token', 'access-token-0002' );
		$this->assertSame( $sealed_secret, ZDZ_Core_FB_Credentials::canonical( 'fb_client_secret' ) );
		$this->assertSame( 'refresh-token-0002', ZDZ_Core_FB_Credentials::canonical( 'fb_refresh_token' ) );
		$this->assertTrue( ZDZ_Core_FB_Credentials::master_is_usable() );
		ZDZ_Core_FB_Credentials::reset_memo();
	}

	public function test_the_workspace_check_is_skipped_when_none_is_set(): void {
		$ok    = array( 'response' => array( 'code' => 200 ), 'headers' => array( 'anthropic-workspace-id' => 'wrkspc_example_a' ) );
		$other = array( 'response' => array( 'code' => 200 ), 'headers' => array( 'anthropic-workspace-id' => 'wrkspc_example_b' ) );
		$this->assertTrue( ZDZ_Core_Credentials::read_anthropic_answer( $ok, '' )['ok'], 'no workspace set: any working key' );
		$this->assertTrue( ZDZ_Core_Credentials::read_anthropic_answer( $ok, 'wrkspc_example_a' )['ok'] );
		$this->assertSame( 'other_workspace', ZDZ_Core_Credentials::read_anthropic_answer( $other, 'wrkspc_example_a' )['why'] );
		$this->assertSame( 'rejected', ZDZ_Core_Credentials::read_anthropic_answer( array( 'response' => array( 'code' => 401 ) ), '' )['why'] );
		$this->assertSame( 'unreachable', ZDZ_Core_Credentials::read_anthropic_answer( new WP_Error( 'x', 'down' ), '' )['why'] );
		$this->assertSame( '', ZDZ_Core_Credentials::anthropic_workspace(), 'Core names no workspace' );
	}

	public function test_backups_never_travel_in_a_data_export(): void {
		$names = ZDZ_Core_Credentials::export_secret_names( array() );
		$this->assertContains( 'zdz_core_cred_backup_poe', $names );
		$this->assertContains( 'zdz_core_cred_backup_stripe', $names );
		$pat = ZDZ_Core_Credentials::export_non_portable( array() );
		$this->assertSame( 1, preg_match( $pat[0], 'zdz_core_cred_state' ) );
	}

	public function test_expiry_rows_follow_the_services_table(): void {
		$rows = ZDZ_Cred_Expiry::rows();
		foreach ( array_keys( ZDZ_Core_Credentials::services() ) as $svc ) {
			$this->assertArrayHasKey( $svc, $rows );
		}
		$this->assertArrayHasKey( 'review_bridge', $rows, 'the Review Bridge key is dated too' );
		$this->assertSame( ZDZ_Core_Credentials::PAGE, ZDZ_Cred_Expiry::page(), 'one dates form, on Credentials' );
	}

	public function test_the_screen_never_prints_a_stored_value(): void {
		$GLOBALS['zdz_test_user_caps'] = array( 7 => array( 'manage_options' ) ); // the signed-in administrator
		$secrets = array(
			'zdz_core_poe_api_key'      => self::K_OLD,
			'zl_poe_api_key'            => self::K_ALT,
			'zdz_core_ns_api_key'       => 'crm-key-00000000000000000001',
			'zl_ns_api_key'             => 'crm-key-00000000000000000002',
			'zdz_core_fb_client_secret' => 'billing-secret-00000000000003',
			'zsch_google_secret'        => 'google-secret-000000000000004',
			'zsch_graph_secret'         => 'graph-secret-0000000000000005',
			'zib_ms_secret'             => 'inbox-secret-0000000000000006',
			'zkv_mail_secret'           => 'vault-secret-0000000000000007',
			'zic_stripe_secret'         => 'sk_test_example_000000000008',
			'zic_stripe_webhook_secret' => 'whsec_example_0000000000009',
		);
		foreach ( $secrets as $row => $v ) {
			$sealed = 0 === strpos( $row, 'zdz_core_' ) || 0 === strpos( $row, 'zl_' ); // stored as each reader keeps it
			update_option( $row, $sealed ? ZDZ_Core_Crypto::encode( $v, ZDZ_Core_Crypto::FIXED ) : $v );
		}
		update_option( 'zdz_core_ns_email', 'alex@example.com' );
		update_option( 'zl_ns_email', 'jordan@example.com' );
		update_option( 'zsch_graph_config', array( 'tenant_id' => 'tenant-0000000010', 'client_id' => 'client-0000000011' ) );
		$this->assertSame( '', ZDZ_Core_Credentials::apply( 'stripe', array( 'secret' => 'sk_test_example_000000000008' ) ) );
		ob_start();
		ZDZ_Core_Credentials::render();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( ZDZ_Core_Crypto::fingerprint( self::K_OLD ), $html );
		foreach ( $secrets as $row => $v ) {
			$this->assertStringNotContainsString( $v, $html, $row . ' must never reach the page' );
			$this->assertStringNotContainsString( ZDZ_Core_Crypto::encode( $v, ZDZ_Core_Crypto::FIXED ), $html, $row . ' ciphertext must never reach the page' );
		}
		foreach ( array( 'alex@example.com', 'jordan@example.com', 'tenant-0000000010', 'client-0000000011' ) as $v ) {
			$this->assertStringNotContainsString( $v, $html, 'logins and ids are shown masked' );
		}
		$this->assertStringContainsString( 'id="zdz-cred-expiry"', $html, 'the dates form is on this screen' );
	}

	public function test_typed_values_must_be_one_printable_token(): void {
		$this->assertTrue( ZDZ_Core_Credentials::valid_field_value( 'client-0003.example' ) );
		$this->assertFalse( ZDZ_Core_Credentials::valid_field_value( 'has a space' ) );
		$this->assertFalse( ZDZ_Core_Credentials::valid_field_value( str_repeat( 'x', 501 ) ) );
	}
}
