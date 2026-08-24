<?php
/**
 * ZCC_Coverage — commission attribution-coverage TELEMETRY (observability only).
 *
 * This is the caller/assembly layer for the PURE ZCC_Calc_Engine::build_coverage()
 * counter. It answers ONE question — "is every fetched invoice attributed to a
 * rep, and is anything sitting past the payment-lookback floor where it could
 * vanish silently?" — reporting COUNTS plus the single unassigned-revenue total
 * (company-wide revenue with no rep code). It is deliberately incapable of
 * exposing a per-person pay figure: it never reads a rate, a tier, a piece rate,
 * a minimum, or a COGS value. It is a read-only step AFTER the money is computed,
 * so it can never change a payout (parity gate).
 *
 * Surface rule: `unattributed_rev` is a coverage figure, but it IS a revenue
 * number, so it stays ADMIN-ONLY — never a chat / email / digest / kiosk surface.
 *
 * @package Zorderz\Commission
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZCC_Coverage {

	/**
	 * Core default for the payment-lookback floor, in days. Commission fetches by
	 * payment date via an issue-date over-fetch of this many days; an invoice paid
	 * more than this after issue is never fetched — so never flagged. Tenant-tunable
	 * via the `zcc_payment_lookback_days` filter; it is a [CORE] default, not a
	 * tenant value.
	 */
	const LOOKBACK_DEFAULT = 75;

	/** The tenant-tunable payment-lookback floor (whole days). Never below 1. */
	public static function lookback_days(): int {
		$d = (int) apply_filters( 'zcc_payment_lookback_days', self::LOOKBACK_DEFAULT );
		return $d > 0 ? $d : self::LOOKBACK_DEFAULT;
	}

	/**
	 * Build a coverage snapshot from a set of NORMALISED invoices (the shape
	 * ZCC_FreshBooks::get_invoices() returns). Reads only: the presence of
	 * attribution codes (attributed vs not), one coverage revenue figure per
	 * invoice (billed total — summed ONLY into the unassigned bucket), the two
	 * dates (for the lookback gauge), and the in-memory Tier-2 shadow candidate
	 * (for the shadow-ready count). It reads NO rate and NO cost, and it resolves
	 * NO code to a person — so it cannot surface an individual's pay. The counting
	 * is delegated to the pure ZCC_Calc_Engine::build_coverage().
	 *
	 * @param array  $invoices     Normalised invoices.
	 * @param int    $target_party Optional rep this run is "for" (0 = aggregate).
	 * @param string $as_of        Optional 'YYYY-MM-DD' reference date (default: today, WP tz).
	 * @return array The coverage snapshot (see ZCC_Calc_Engine::build_coverage()).
	 */
	public static function snapshot( array $invoices, int $target_party = 0, string $as_of = '' ): array {
		$rows = [];
		foreach ( $invoices as $inv ) {
			if ( ! is_array( $inv ) ) {
				continue;
			}
			$codes  = isset( $inv['salesperson_codes'] ) && is_array( $inv['salesperson_codes'] ) ? $inv['salesperson_codes'] : [];
			$shadow = isset( $inv['t2_shadow']['candidate'] ) ? (string) $inv['t2_shadow']['candidate'] : '';
			$rows[] = [
				'codes'            => $codes,
				// Coverage revenue = the invoice's billed total. A company-wide
				// revenue number, summed ONLY across the unassigned bucket. NOT a
				// commission and NOT a COGS. Never per-person.
				'revenue'          => (float) ( $inv['total_amount'] ?? $inv['gross_billed'] ?? 0 ),
				// Left unresolved on purpose: resolving a code to a party would read
				// the compensation plan (rates). Coverage never does. other_reps
				// therefore reports 0 unless a caller supplies resolved ids.
				'attributed_party' => (int) ( $inv['attributed_party'] ?? 0 ),
				'date_completed'   => (string) ( $inv['date_completed'] ?? '' ),
				'date_paid'        => (string) ( $inv['date_paid'] ?? '' ),
				'unknown_lines'    => (int) ( $inv['unknown_lines'] ?? 0 ),
				'shadow_candidate' => $shadow,
			];
		}

		$ctx = [
			'invoices'      => $rows,
			'lookback_days' => self::lookback_days(),
			'as_of'         => $as_of !== '' ? $as_of : self::today(),
			'target_party'  => $target_party,
		];

		return ZCC_Calc_Engine::build_coverage( $ctx );
	}

	/** Today's date in the WP timezone (falls back to UTC outside WordPress). */
	private static function today(): string {
		return function_exists( 'current_time' ) ? current_time( 'Y-m-d' ) : gmdate( 'Y-m-d' );
	}

	/**
	 * Emit the coverage debug line — ONLY under WP_DEBUG, and only to the error
	 * log (a developer surface, never a user one). No-op otherwise. The line
	 * carries counts + the unassigned-revenue total and NO per-person figure.
	 */
	public static function maybe_log( array $coverage ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG && function_exists( 'error_log' ) ) {
			error_log( ZCC_Calc_Engine::coverage_log_line( $coverage ) );
		}
	}
}
