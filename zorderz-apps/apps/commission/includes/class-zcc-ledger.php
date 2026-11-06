<?php
/**
 * ZCC_Ledger — the commission ledger (record of record).
 *
 * Prevents duplicate payment: each (party × invoice) exists once (DB UNIQUE).
 * Entries start 'draft'; the person who pays commission LOCKS a person's month
 * after paying it (ZCC_Month_Lock → finalize_invoices), and a locked
 * ('finalized') entry is never overwritten. Nothing locks on a timer: a lock
 * certifies a payment, so only the payer can say it. The app has no unlock.
 * The same invoice may pay several parties.
 *
 * Period keys are calendar months, 'YYYY-MM', in the site's time zone.
 *
 * Tests switch the ledger to an in-memory store with memory().
 *
 * @package Zorderz\Commission
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZCC_Ledger {

	/** In-memory rows for tests: "user:invoice" => row. Null: the database. */
	private static $mem = null;

	/** Use an in-memory store (tests). Pass false to go back to the database. */
	public static function memory( bool $on = true ): void {
		self::$mem = $on ? [] : null;
	}

	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'zcc_commission_ledger';
	}

	/**
	 * SELECT-then-branch upsert: finalized ⇒ 'locked' (never overwritten);
	 * draft ⇒ UPDATE; else INSERT. A UNIQUE(user_id, invoice_id) prevents a
	 * double-pay if two writers race; a losing INSERT fails safely to 'skipped'.
	 *
	 * @return string 'inserted' | 'updated' | 'locked' | 'skipped'
	 */
	public static function upsert_entry( int $party_id, string $period_key, array $invoice_result ): string {
		$invoice_id = (int) ( $invoice_result['invoice_id'] ?? 0 );
		if ( ! $invoice_id ) {
			return 'skipped';
		}

		$data = [
			'user_id'            => $party_id,
			'period_key'         => $period_key,
			'invoice_id'         => $invoice_id,
			'invoice_number'     => sanitize_text_field( $invoice_result['invoice_number'] ?? '' ),
			'customer_name'      => sanitize_text_field( $invoice_result['customer_name'] ?? '' ),
			'date_completed'     => sanitize_text_field( $invoice_result['date_completed'] ?? '' ),
			'gross_billed'       => round( (float) ( $invoice_result['gross_billed'] ?? 0 ), 2 ),
			'total_cogs'         => round( (float) ( $invoice_result['total_cogs'] ?? 0 ), 2 ),
			'net_commissionable' => round( (float) ( $invoice_result['net_commissionable'] ?? 0 ), 2 ),
			'net_attributed'     => round( (float) ( $invoice_result['net_attributed'] ?? $invoice_result['net_commissionable'] ?? 0 ), 2 ),
			'commission_amount'  => round( (float) ( $invoice_result['commission_amount'] ?? 0 ), 2 ),
			'status'             => 'draft',
			'detail_json'        => wp_json_encode( $invoice_result['lines'] ?? [] ),
		];

		if ( null !== self::$mem ) {
			$k = $party_id . ':' . $invoice_id;
			if ( isset( self::$mem[ $k ] ) && 'finalized' === self::$mem[ $k ]['status'] ) {
				return 'locked';
			}
			$was             = isset( self::$mem[ $k ] );
			self::$mem[ $k ] = $data + [ 'id' => count( self::$mem ) + 1, 'finalized_at' => null, 'finalized_by' => null ];
			return $was ? 'updated' : 'inserted';
		}

		global $wpdb;
		$table = self::table_name();
		$existing_status = $wpdb->get_var( $wpdb->prepare(
			"SELECT status FROM {$table} WHERE user_id = %d AND invoice_id = %d",
			$party_id, $invoice_id
		) );
		if ( $existing_status === 'finalized' ) {
			return 'locked';
		}

		if ( $existing_status === 'draft' ) {
			unset( $data['user_id'], $data['invoice_id'] );
			// The WHERE repeats status = 'draft': a lock that lands between the SELECT and this
			// UPDATE is never overwritten.
			$ok = $wpdb->update( $table, $data, [ 'user_id' => $party_id, 'invoice_id' => $invoice_id, 'status' => 'draft' ] );
			if ( false === $ok ) {
				error_log( 'ZCC_Ledger: draft update failed (party ' . $party_id . ', invoice ' . $invoice_id . ')' );
				return 'skipped';
			}
			if ( 0 === (int) $ok ) {
				// Nothing changed, or a lock landed in between: read which.
				$now = $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$table} WHERE user_id = %d AND invoice_id = %d", $party_id, $invoice_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$table} is self::table_name(): $wpdb->prefix plus a fixed table name
				return 'finalized' === $now ? 'locked' : 'updated';
			}
			return 'updated';
		}

		$ok = $wpdb->insert( $table, $data );
		if ( false === $ok ) {
			error_log( 'ZCC_Ledger: insert failed (party ' . $party_id . ', invoice ' . $invoice_id . ')' );
			return 'skipped';
		}
		return 'inserted';
	}

	public static function get_period( int $party_id, string $period_key ): array {
		if ( null !== self::$mem ) {
			return array_values( array_filter( self::$mem, static function ( $r ) use ( $party_id, $period_key ) {
				return (int) $r['user_id'] === $party_id && $r['period_key'] === $period_key;
			} ) );
		}
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM " . self::table_name() . " WHERE user_id = %d AND period_key = %s ORDER BY date_completed ASC, invoice_number ASC",
			$party_id, $period_key
		), ARRAY_A );
		return $rows ?: [];
	}

	public static function get_finalized_invoice_ids( int $party_id, string $period_key ): array {
		if ( null !== self::$mem ) {
			$out = [];
			foreach ( self::get_period( $party_id, $period_key ) as $r ) {
				if ( 'finalized' === $r['status'] ) {
					$out[] = (int) $r['invoice_id'];
				}
			}
			return $out;
		}
		global $wpdb;
		$ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT invoice_id FROM " . self::table_name() . " WHERE user_id = %d AND period_key = %s AND status = 'finalized'",
			$party_id, $period_key
		) );
		return array_map( 'intval', $ids ?: [] );
	}

	/**
	 * Each invoice's row for one person, WHATEVER month it is filed under (UNIQUE user +
	 * invoice): invoice_id => [ status, period_key ]. A missing invoice has no row.
	 */
	public static function rows_for_invoices( int $user_id, array $invoice_ids ): array {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $invoice_ids ), static function ( $i ) {
			return $i > 0;
		} ) ) );
		if ( $user_id <= 0 || ! $ids ) {
			return [];
		}
		$out = [];
		if ( null !== self::$mem ) {
			foreach ( $ids as $id ) {
				$r = self::$mem[ $user_id . ':' . $id ] ?? null;
				if ( $r ) {
					$out[ $id ] = [ 'status' => (string) $r['status'], 'period_key' => (string) $r['period_key'] ];
				}
			}
			return $out;
		}
		global $wpdb;
		$in   = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT invoice_id, status, period_key FROM " . self::table_name() . " WHERE user_id = %d AND invoice_id IN ($in)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- self::table_name() is $wpdb->prefix plus a fixed table name; $in is one %d placeholder per invoice id
			array_merge( [ $user_id ], $ids )
		), ARRAY_A );
		foreach ( (array) $rows as $r ) {
			$out[ (int) $r['invoice_id'] ] = [ 'status' => (string) $r['status'], 'period_key' => (string) $r['period_key'] ];
		}
		return $out;
	}

	/**
	 * A person's row for an invoice NUMBER (leading zeros ignored), or null. The in-app
	 * invoice view opens an invoice only for someone whose Commission already lists it.
	 */
	public static function get_entry_by_number( int $user_id, string $invoice_number ): ?array {
		$bare = ltrim( trim( $invoice_number ), '0' );
		if ( '' === $bare || $user_id <= 0 ) {
			return null;
		}
		if ( null !== self::$mem ) {
			foreach ( array_reverse( self::$mem ) as $r ) {
				if ( (int) $r['user_id'] === $user_id && ltrim( (string) $r['invoice_number'], '0' ) === $bare ) {
					return $r;
				}
			}
			return null;
		}
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM " . self::table_name() . " WHERE user_id = %d AND TRIM(LEADING '0' FROM invoice_number) = %s ORDER BY id DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- self::table_name() is $wpdb->prefix plus a fixed table name
			$user_id, $bare
		), ARRAY_A );
		return $row ?: null;
	}

	public static function get_period_summary( int $party_id, string $period_key ): array {
		global $wpdb;
		$table = self::table_name();
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT COUNT(*) AS invoice_count, SUM(gross_billed) AS gross_billed, SUM(total_cogs) AS total_cogs,
				SUM(net_commissionable) AS net_commissionable, SUM(commission_amount) AS total_commission,
				SUM(CASE WHEN status='finalized' THEN 1 ELSE 0 END) AS finalized_count,
				SUM(CASE WHEN status='draft' THEN 1 ELSE 0 END) AS draft_count
			 FROM {$table} WHERE user_id = %d AND period_key = %s",
			$party_id, $period_key
		), ARRAY_A );
		return $row ?: [];
	}

	/**
	 * Lock exactly these invoices for one person (the month the payer just paid), and nothing
	 * else: a draft another date range filed under the same month stays live.
	 *
	 * @param int[] $invoice_ids Invoice ids from the fresh full-month calculation.
	 * @return int Rows locked.
	 */
	public static function finalize_invoices( int $user_id, array $invoice_ids, int $finalized_by ): int {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $invoice_ids ), static function ( $i ) {
			return $i > 0;
		} ) ) );
		if ( $user_id <= 0 || ! $ids ) {
			return 0;
		}
		if ( null !== self::$mem ) {
			$n = 0;
			foreach ( $ids as $id ) {
				$k = $user_id . ':' . $id;
				if ( isset( self::$mem[ $k ] ) && 'draft' === self::$mem[ $k ]['status'] ) {
					self::$mem[ $k ]['status']       = 'finalized';
					self::$mem[ $k ]['finalized_by'] = $finalized_by;
					self::$mem[ $k ]['finalized_at'] = gmdate( 'Y-m-d H:i:s' );
					$n++;
				}
			}
			return $n;
		}
		global $wpdb;
		$in = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		return (int) $wpdb->query( $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- self::table_name() is $wpdb->prefix plus a fixed table name
			"UPDATE " . self::table_name() . "
			 SET status = 'finalized', finalized_at = NOW(), finalized_by = %d
			 WHERE user_id = %d AND status = 'draft' AND invoice_id IN ($in)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $in is one %d placeholder per invoice id
			array_merge( [ $finalized_by, $user_id ], $ids )
		) );
	}

	/**
	 * Who locked which person's month, when, and at what figure: one option per person and
	 * month (`zcc_month_lock_<user>_<YYYY_MM>`, not autoloaded), so two payers locking two
	 * people at once cannot lose one.
	 */
	public static function month_lock_key( int $user_id, string $month ): string {
		return 'zcc_month_lock_' . $user_id . '_' . str_replace( '-', '_', $month );
	}

	/** The lock record for one person's month ("YYYY-MM"), or null. */
	public static function get_month_lock( int $user_id, string $month ): ?array {
		$r = function_exists( 'get_option' ) ? get_option( self::month_lock_key( $user_id, $month ), null ) : null;
		return is_array( $r ) ? $r : null;
	}

	/** Remember a month lock (pressing it again for invoices paid later updates the record). */
	public static function record_month_lock( int $user_id, string $month, array $rec ): void {
		if ( function_exists( 'update_option' ) ) {
			update_option( self::month_lock_key( $user_id, $month ), $rec, false );
		}
	}

	/** Clear the retired automatic-lock event, if an older install scheduled it. */
	public static function unschedule_cron(): void {
		if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
			wp_clear_scheduled_hook( 'zcc_monthly_finalize' );
		}
	}

	/** Current month's period key (YYYY-MM) in the WP-configured timezone. */
	public static function current_period(): string {
		return current_time( 'Y-m' );
	}
}
