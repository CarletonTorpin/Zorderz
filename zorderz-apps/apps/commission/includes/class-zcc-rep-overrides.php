<?php
/**
 * ZCC_Rep_Overrides — historical back-assignment of a rep to un-coded invoices.
 *
 * The shared source of truth for manual attribution. The Commission app writes
 * (admin action / REST); analytics reads it (via get_code(), guarded by
 * class_exists) so its answers reflect a real assignment, not an inference.
 *
 * Precedence the whole system honours (ZDZ_Compensation::attribution()):
 *   real document code  >  override row here  >  inference.
 * An override is consulted ONLY when the invoice has no real code, and the code
 * MUST resolve to a configured plan — an override can never introduce a rep the
 * pay engine cannot pay. LOCAL ONLY; fully reversible.
 *
 * @package Zorderz\Commission
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZCC_Rep_Overrides {

	/** In-request map: invoice_id(int) => rep_code(string). */
	private static $cache = null;

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'zcc_rep_overrides';
	}

	public static function table_ready(): bool {
		static $ready = null;
		if ( $ready !== null ) {
			return $ready;
		}
		global $wpdb;
		$t     = self::table();
		$ready = ( (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) === $t );
		return $ready;
	}

	public static function get_code( $invoice_id ): string {
		$id = (int) $invoice_id;
		if ( $id <= 0 ) {
			return '';
		}
		return self::all()[ $id ] ?? '';
	}

	/** @return array<int,string> */
	public static function all(): array {
		if ( self::$cache !== null ) {
			return self::$cache;
		}
		self::$cache = [];
		if ( ! self::table_ready() ) {
			return self::$cache;
		}
		global $wpdb;
		$rows = $wpdb->get_results( "SELECT invoice_id, rep_code FROM " . self::table(), ARRAY_A );
		if ( is_array( $rows ) ) {
			foreach ( $rows as $r ) {
				$code = strtoupper( trim( (string) ( $r['rep_code'] ?? '' ) ) );
				if ( $code !== '' ) {
					self::$cache[ (int) $r['invoice_id'] ] = $code;
				}
			}
		}
		return self::$cache;
	}

	/**
	 * Assign (or re-assign) a rep to one invoice. Validates the code against a
	 * configured compensation plan so an override can never introduce an unpayable
	 * rep. Per-invoice + explicit (no bulk auto-apply).
	 *
	 * @return array { ok:bool, error?:string }
	 */
	public static function assign( $invoice_id, string $rep_code, int $by_user = 0, string $note = '' ): array {
		$id   = (int) $invoice_id;
		$code = strtoupper( trim( $rep_code ) );

		if ( $id <= 0 ) {
			return [ 'ok' => false, 'error' => 'Invalid invoice id.' ];
		}
		$fmt = class_exists( 'ZDZ_Compensation' ) ? ( ZDZ_Compensation::attribution()['code_format'] ?? '/^[A-Z]{2,4}$/' ) : '/^[A-Z]{2,4}$/';
		if ( ! preg_match( $fmt, $code ) ) {
			return [ 'ok' => false, 'error' => 'Rep code must be 2–4 letters.' ];
		}
		if ( class_exists( 'ZDZ_Compensation' ) && ZDZ_Compensation::plan_by_code( $code ) === null ) {
			return [ 'ok' => false, 'error' => "No compensation plan is configured for rep code '{$code}'." ];
		}
		if ( ! self::table_ready() ) {
			return [ 'ok' => false, 'error' => 'Override table not installed — run the DB upgrade.' ];
		}

		global $wpdb;
		$t        = self::table();
		$existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t} WHERE invoice_id = %d", $id ) );
		if ( $existing ) {
			$wpdb->update( $t, [ 'rep_code' => $code, 'note' => substr( $note, 0, 255 ), 'assigned_by' => $by_user ?: null ], [ 'invoice_id' => $id ], [ '%s', '%s', '%d' ], [ '%d' ] );
		} else {
			$wpdb->insert( $t, [ 'invoice_id' => $id, 'rep_code' => $code, 'note' => substr( $note, 0, 255 ), 'assigned_by' => $by_user ?: null ], [ '%d', '%s', '%s', '%d' ] );
		}
		self::$cache = null;
		if ( class_exists( 'ZCC_Audit' ) ) {
			ZCC_Audit::log( 'rep_override_assign', [ 'invoice_id' => $id, 'rep_code' => $code, 'by' => $by_user, 'note' => $note ] );
		}
		return [ 'ok' => true ];
	}

	public static function remove( $invoice_id ): array {
		$id = (int) $invoice_id;
		if ( $id <= 0 ) {
			return [ 'ok' => false, 'error' => 'Invalid invoice id.' ];
		}
		if ( ! self::table_ready() ) {
			return [ 'ok' => false, 'error' => 'Override table not installed.' ];
		}
		global $wpdb;
		$wpdb->delete( self::table(), [ 'invoice_id' => $id ], [ '%d' ] );
		self::$cache = null;
		return [ 'ok' => true ];
	}
}
