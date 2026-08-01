<?php
/**
 * ZCC_FreshBooks — invoice source for the commission engine.
 *
 * A thin adapter over the theme's ZDZ_Core_FreshBooks client (which owns the
 * provider host, OAuth token, and single-flight refresh — none of that lives
 * here; the provider host is config, never a literal). This class does only the
 * commission-relevant work: fetch a window, normalise each invoice, harvest the
 * attribution codes from the free-text reference, extract discount / card-fee
 * ledger rows, and apply the PAYABILITY GATE.
 *
 * SAFETY FLOOR (ZDZ_Compensation::payability()): the provider's own status
 * filter is never trusted, so status is re-checked after the fetch. A string
 * status (v3_status/status) is authoritative; the legacy integer code is NEVER
 * hand-mapped; money is the last-resort fallback.
 *
 * @package Zorderz\Commission
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZCC_FreshBooks {

	/** Request-scoped memo so several verbs in one turn share ONE fetch. */
	private static $memo = [];

	/** Is a FreshBooks connection configured? */
	public static function is_connected(): bool {
		if ( ! class_exists( 'ZDZ_Core_FreshBooks' ) ) {
			return false;
		}
		try {
			$c = new ZDZ_Core_FreshBooks();
			return method_exists( $c, 'is_configured' ) ? (bool) $c->is_configured() : true;
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * Fetch + normalise paid/payable invoices in a date window.
	 *
	 * @param string $date_start YYYY-MM-DD
	 * @param string $date_end   YYYY-MM-DD
	 * @param array|string|null $status Allowed statuses (default from Compensation).
	 * @param string $date_basis 'paid' (period tracks collection) | 'issued'.
	 * @return array Normalised invoices (already gated to payable).
	 */
	public static function get_invoices( string $date_start, string $date_end, $status = null, string $date_basis = 'paid' ): array {
		if ( ! self::is_connected() ) {
			return [];
		}
		$gate     = class_exists( 'ZDZ_Compensation' ) ? ZDZ_Compensation::payability() : [ 'statuses' => [ 'paid', 'partial' ], 'money_tolerance' => 0.005 ];
		$statuses = is_array( $status ) ? array_map( 'strtolower', $status ) : ( is_string( $status ) && $status !== '' ? array_map( 'strtolower', explode( ',', $status ) ) : (array) $gate['statuses'] );

		$memo_key = md5( $date_start . '|' . $date_end . '|' . implode( ',', $statuses ) . '|' . $date_basis );
		if ( isset( self::$memo[ $memo_key ] ) ) {
			return self::$memo[ $memo_key ];
		}

		$raw_list = [];
		try {
			$client = new ZDZ_Core_FreshBooks();
			// The theme client owns paging + the host; ask for the window. We widen
			// the issue-date window backward for a payment-basis period and post-
			// filter on date_paid below.
			$params = [ 'date_start' => $date_start, 'date_end' => $date_end, 'date_basis' => $date_basis ];
			$fetched = $client->get_invoices( $params );
			if ( is_array( $fetched ) ) {
				$raw_list = $fetched;
			}
		} catch ( \Throwable $e ) {
			error_log( 'ZCC_FreshBooks: invoice fetch failed — ' . $e->getMessage() );
			return [];
		}

		$out = [];
		foreach ( $raw_list as $raw ) {
			if ( ! is_array( $raw ) ) {
				continue;
			}
			$inv = self::normalize_invoice( $raw );
			// PAYABILITY GATE — re-check after the fetch; never trust the filter.
			if ( ! self::is_payable_status( $raw, $inv, $statuses, (float) $gate['money_tolerance'] ) ) {
				continue;
			}
			// Payment-basis window: keep only invoices collected in range.
			if ( $date_basis === 'paid' && $inv['date_paid'] !== '' ) {
				if ( $inv['date_paid'] < $date_start || $inv['date_paid'] > $date_end ) {
					continue;
				}
			}
			$out[] = $inv;
		}

		self::$memo[ $memo_key ] = $out;
		return $out;
	}

	/** Drop the request memo (between tests / after a token change). */
	public static function flush_memo(): void {
		self::$memo = [];
	}

	/**
	 * Normalise a raw provider invoice into the shape the calc engine expects.
	 * Reads several possible date keys (issue date is `create_date`, not `date`).
	 */
	public static function normalize_invoice( array $raw ): array {
		$lines = [];
		foreach ( (array) ( $raw['lines'] ?? [] ) as $ln ) {
			if ( ! is_array( $ln ) ) {
				continue;
			}
			$amount = (float) ( $ln['amount']['amount'] ?? $ln['amount'] ?? 0 );
			$lines[] = [
				'description' => (string) ( $ln['name'] ?? '' ) . ( ! empty( $ln['description'] ) ? ' ' . $ln['description'] : '' ),
				'desc_head'   => (string) ( $ln['name'] ?? '' ),
				'qty'         => (int) round( (float) ( $ln['qty'] ?? 1 ) ),
				'amount'      => $amount,
			];
		}

		$issue = (string) ( $raw['create_date'] ?? $raw['generation_date'] ?? $raw['date'] ?? '' );
		$paid  = (string) ( $raw['date_paid'] ?? '' );
		$ref   = (string) ( $raw['po_number'] ?? $raw['reference'] ?? '' );
		$notes = (string) ( $raw['notes'] ?? '' );

		return [
			'invoice_id'      => (int) ( $raw['id'] ?? $raw['invoiceid'] ?? 0 ),
			'invoice_number'  => (string) ( $raw['invoice_number'] ?? '' ),
			'customer_name'   => trim( (string) ( $raw['organization'] ?? ( ( $raw['fname'] ?? '' ) . ' ' . ( $raw['lname'] ?? '' ) ) ) ),
			'date_completed'  => $issue,
			'date_paid'       => $paid,
			'fb_url'          => (string) ( $raw['fb_url'] ?? '' ),
			'location'        => '',
			'reference'       => $ref,
			'lines'           => $lines,
			'salesperson_codes' => self::harvest_codes( $ref . ' ' . $notes . ' ' . implode( ' ', array_column( $lines, 'description' ) ) ),
			'discount_amount' => round( (float) ( $raw['discount_total']['amount'] ?? $raw['discount_amount'] ?? 0 ), 2 ),
			'cc_fee'          => 0.0, // resolved from a line by the calc engine's ledger-kind pass
			'outstanding'     => (float) ( $raw['outstanding']['amount'] ?? $raw['outstanding'] ?? 0 ),
			'total_amount'    => (float) ( $raw['amount']['amount'] ?? $raw['total_amount'] ?? 0 ),
			'v3_status'       => (string) ( $raw['v3_status'] ?? '' ),
			'status'          => (string) ( $raw['status'] ?? '' ),
		];
	}

	/**
	 * Harvest salesperson attribution codes from invoice free text: "(AB)",
	 * "(CD/EF)", "(A & B)", "(A and B)". Uppercased 2–4 letter tokens. Region /
	 * source tokens are filtered downstream against configured plans + the
	 * attribution reserved-token set.
	 *
	 * @return string[]
	 */
	public static function harvest_codes( string $text ): array {
		$codes = [];
		if ( preg_match_all( '/\(([A-Za-z]{2,4}(?:\s*(?:\/|&|and)\s*[A-Za-z]{2,4})*)\)/', $text, $m ) ) {
			foreach ( $m[1] as $group ) {
				foreach ( preg_split( '/\s*(?:\/|&|and)\s*/i', $group ) as $c ) {
					$c = strtoupper( trim( $c ) );
					if ( preg_match( '/^[A-Z]{2,4}$/', $c ) && ! in_array( $c, $codes, true ) ) {
						$codes[] = $c;
					}
				}
			}
		}
		return $codes;
	}

	/**
	 * Payability gate. A string status (v3_status → status) is authoritative;
	 * otherwise money decides (outstanding ≈ 0 ⇒ paid; 0 < outstanding < total ⇒
	 * partial). The legacy integer code is never hand-mapped.
	 */
	public static function is_payable_status( array $raw, array $inv, array $statuses, float $tol ): bool {
		foreach ( [ $raw['v3_status'] ?? '', $inv['status'] ?? '', $raw['status'] ?? '' ] as $cand ) {
			$c = strtolower( trim( (string) $cand ) );
			if ( $c !== '' && ! ctype_digit( $c ) ) {
				return in_array( $c, $statuses, true );
			}
		}
		$outstanding = (float) ( $inv['outstanding'] ?? 0 );
		$total       = (float) ( $inv['total_amount'] ?? 0 );
		if ( $total > 0 && $outstanding <= $tol && in_array( 'paid', $statuses, true ) ) {
			return true;
		}
		if ( $outstanding > $tol && $outstanding < $total && in_array( 'partial', $statuses, true ) ) {
			return true;
		}
		return false;
	}
}
