<?php
/**
 * ZCC_Ledger — the commission ledger (record of record).
 *
 * Prevents duplicate payment: each (party × invoice) exists once (DB UNIQUE).
 * Entries start 'draft' and become 'finalized' on period close; once finalized
 * an entry is locked and never overwritten. The same invoice may pay several
 * parties. Core mechanism — carries no taxonomy; the pay calendar comes from
 * ZDZ_Compensation.
 *
 * @package Zorderz\Commission
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZCC_Ledger {

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
		global $wpdb;
		$table = self::table_name();

		$invoice_id = (int) ( $invoice_result['invoice_id'] ?? 0 );
		if ( ! $invoice_id ) {
			return 'skipped';
		}

		$existing_status = $wpdb->get_var( $wpdb->prepare(
			"SELECT status FROM {$table} WHERE user_id = %d AND invoice_id = %d",
			$party_id, $invoice_id
		) );
		if ( $existing_status === 'finalized' ) {
			return 'locked';
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

		if ( $existing_status === 'draft' ) {
			unset( $data['user_id'], $data['invoice_id'] );
			$ok = $wpdb->update( $table, $data, [ 'user_id' => $party_id, 'invoice_id' => $invoice_id ] );
			if ( false === $ok ) {
				error_log( 'ZCC_Ledger: draft update failed (party ' . $party_id . ', invoice ' . $invoice_id . ') — ' . $wpdb->last_error );
				return 'skipped';
			}
			return 'updated';
		}

		$ok = $wpdb->insert( $table, $data );
		if ( false === $ok ) {
			error_log( 'ZCC_Ledger: insert failed (party ' . $party_id . ', invoice ' . $invoice_id . ') — ' . $wpdb->last_error );
			return 'skipped';
		}
		return 'inserted';
	}

	public static function get_period( int $party_id, string $period_key ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM " . self::table_name() . " WHERE user_id = %d AND period_key = %s ORDER BY date_completed ASC, invoice_number ASC",
			$party_id, $period_key
		), ARRAY_A );
		return $rows ?: [];
	}

	public static function get_finalized_invoice_ids( int $party_id, string $period_key ): array {
		global $wpdb;
		$ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT invoice_id FROM " . self::table_name() . " WHERE user_id = %d AND period_key = %s AND status = 'finalized'",
			$party_id, $period_key
		) );
		return array_map( 'intval', $ids ?: [] );
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

	public static function finalize_all_for_period( string $period_key ): array {
		global $wpdb;
		$table    = self::table_name();
		$affected = (int) $wpdb->query( $wpdb->prepare(
			"UPDATE {$table} SET status='finalized', finalized_at=NOW(), finalized_by=0 WHERE period_key = %s AND status='draft'",
			$period_key
		) );
		$users = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(DISTINCT user_id) FROM {$table} WHERE period_key = %s AND status='finalized' AND finalized_by=0 AND finalized_at >= DATE_SUB(NOW(), INTERVAL 1 MINUTE)",
			$period_key
		) );
		return [ 'users_affected' => $users, 'entries_finalized' => $affected ];
	}

	public static function finalize_period( int $party_id, string $period_key, int $finalized_by = 0 ): int {
		global $wpdb;
		return (int) $wpdb->query( $wpdb->prepare(
			"UPDATE " . self::table_name() . " SET status='finalized', finalized_at=NOW(), finalized_by=%d WHERE user_id=%d AND period_key=%s AND status='draft'",
			$finalized_by ?: get_current_user_id(), $party_id, $period_key
		) );
	}

	/**
	 * Cron: finalise the PRIOR calendar month. The pay calendar (period_type,
	 * finalize_target) comes from ZDZ_Compensation; the key is computed in the
	 * WP-configured timezone, not server-local.
	 */
	public static function cron_finalize_prior_month(): void {
		$period_key = gmdate( 'Y-m', strtotime( 'first day of last month', current_time( 'timestamp' ) ) );
		$result     = self::finalize_all_for_period( $period_key );
		error_log( sprintf( 'ZCC_Ledger: auto-finalised %s — %d entries across %d parties.', $period_key, $result['entries_finalized'], $result['users_affected'] ) );
	}

	public static function schedule_cron(): void {
		if ( ! wp_next_scheduled( 'zcc_monthly_finalize' ) ) {
			wp_schedule_event( strtotime( 'first day of next month midnight' ), 'zcc_monthly', 'zcc_monthly_finalize' );
		}
	}

	public static function unschedule_cron(): void {
		$ts = wp_next_scheduled( 'zcc_monthly_finalize' );
		if ( $ts ) {
			wp_unschedule_event( $ts, 'zcc_monthly_finalize' );
		}
	}

	/** Current month's period key (YYYY-MM) in the WP-configured timezone. */
	public static function current_period(): string {
		return current_time( 'Y-m' );
	}
}
