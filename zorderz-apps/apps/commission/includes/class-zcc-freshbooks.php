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
			// Tier-2 SHADOW annotation — a second attribution opinion computed in
			// parallel for A/B comparison. IN-MEMORY ONLY: never persisted, never
			// merged into salesperson_codes, never surfaced off the admin screen,
			// and never paid while zcc_t2_live is 'no' (the shipped default). It is
			// a guarded no-op — a bare-initials, roster-guarded read that touches no
			// pay figure — so the money engine and the ledger are byte-for-byte
			// unchanged whether it finds a candidate or not.
			't2_shadow'         => self::t2_shadow_for( $ref . ' ' . $notes . ' ' . implode( ' ', array_column( $lines, 'description' ) ) ),
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

	/* ==================================================================
	 * TIER-2 BARE-INITIALS ATTRIBUTION — SHADOW (ships OFF)
	 *
	 * Tier-1 is the parenthesised document code harvested above. Tier-2 is a
	 * roster-guarded second opinion on BARE initials in the free text
	 * ("… <initials> - Scheduled"). It ships in SHADOW: it records what it WOULD
	 * attribute and never pays it. The single, reversible, nonce-guarded toggle
	 * `zcc_t2_live` (default 'no') is the only thing that could ever let it feed a
	 * pay path, and the plugin NEVER flips itself — going live is a human,
	 * pay-affecting decision. While OFF the shadow performs ZERO writes: the scan
	 * is pure, the annotation is in-memory only, and salesperson_codes is untouched.
	 * ================================================================== */

	/** Is Tier-2 attribution LIVE (allowed to feed pay)? Ships OFF; Zorderz never flips it. */
	public static function is_t2_live(): bool {
		return function_exists( 'get_option' ) && get_option( 'zcc_t2_live', 'no' ) === 'yes';
	}

	/**
	 * The roster allow-list: uppercased `initials` from ZDZ_Party — the ONLY
	 * tokens a shadow match may hit. Memoised per request; returns [] when the
	 * roster service is absent or empty (a fresh install ⇒ the shadow is inert).
	 * This is the only I/O the shadow touches, and it is a READ.
	 *
	 * @return string[]
	 */
	public static function t2_roster(): array {
		if ( isset( self::$memo['t2_roster'] ) ) {
			return self::$memo['t2_roster'];
		}
		$initials = [];
		if ( class_exists( 'ZDZ_Party' ) && method_exists( 'ZDZ_Party', 'selectable_people' ) ) {
			try {
				foreach ( (array) ZDZ_Party::selectable_people() as $p ) {
					$i = strtoupper( trim( (string) ( $p['initials'] ?? '' ) ) );
					if ( $i !== '' && ! in_array( $i, $initials, true ) ) {
						$initials[] = $i;
					}
				}
			} catch ( \Throwable $e ) {
				$initials = [];
			}
		}
		self::$memo['t2_roster'] = $initials;
		return $initials;
	}

	/**
	 * Assemble the Tier-2 shadow annotation for one invoice's free text, reading
	 * the live roster + the Compensation attribution contract (reserved tokens +
	 * code format). A thin wrapper over the PURE t2_shadow_scan(); performs NO
	 * writes and returns in-memory data only.
	 *
	 * @return array See t2_shadow_scan().
	 */
	public static function t2_shadow_for( string $text ): array {
		$contract = class_exists( 'ZDZ_Compensation' ) ? ZDZ_Compensation::attribution() : [];
		$reserved = is_array( $contract['reserved_tokens'] ?? null ) ? (array) $contract['reserved_tokens'] : [];
		$format   = (string) ( $contract['code_format'] ?? '/^[A-Z]{2,4}$/' );
		return self::t2_shadow_scan( $text, self::t2_roster(), $reserved, $format );
	}

	/**
	 * PURE Tier-2 bare-initials shadow scan. No I/O, no globals, no writes, no side
	 * effects — same inputs ⇒ same output. It records what Tier-2 WOULD attribute;
	 * nothing it returns ever reaches a pay path on its own.
	 *
	 * Rules (Core mechanism; roster + tokens are Identity, injected):
	 *  - Parenthesised codes are Tier-1's — strip them; Tier-2 sees BARE text only.
	 *  - A candidate token must match the attribution `code_format` AND be a roster
	 *    initial. A token that is NOT on the roster is NEVER a candidate — so an
	 *    unconfigured / hallucinated code can never match.
	 *  - A reserved / stopword token (a caps-prose word like "AS", or a place /
	 *    source code) is rejected, never guessed.
	 *  - Two or more DISTINCT roster hits ⇒ AMBIGUOUS ⇒ no guess (a review flag).
	 *
	 * @param string   $text        Invoice free text.
	 * @param string[] $roster      Allow-list of initials (Identity; injected).
	 * @param string[] $reserved    Reserved/stopword tokens (Identity; injected).
	 * @param string   $code_format Anchored regex a token must match to be a code.
	 * @return array{ scanned:bool, candidate:string, matched:bool, ambiguous:bool, rejected:string[], reason:string }
	 */
	public static function t2_shadow_scan( string $text, array $roster, array $reserved, string $code_format = '/^[A-Z]{2,4}$/' ): array {
		$roster_u = [];
		foreach ( $roster as $r ) {
			$ru = strtoupper( trim( (string) $r ) );
			if ( $ru !== '' ) {
				$roster_u[ $ru ] = true;
			}
		}
		if ( empty( $roster_u ) ) {
			// No roster configured ⇒ the shadow is a total no-op.
			return [ 'scanned' => true, 'candidate' => '', 'matched' => false, 'ambiguous' => false, 'rejected' => [], 'reason' => 'no roster configured — shadow inert' ];
		}

		$reserved_u = [];
		foreach ( $reserved as $r ) {
			$ru = strtoupper( trim( (string) $r ) );
			if ( $ru !== '' ) {
				$reserved_u[ $ru ] = true;
			}
		}

		// Strip parenthesised groups — those belong to Tier-1 harvest_codes().
		$bare = preg_replace( '/\([^)]*\)/', ' ', $text );
		if ( ! is_string( $bare ) ) {
			$bare = $text;
		}

		$hits     = [];
		$rejected = [];
		foreach ( (array) preg_split( '/[^A-Za-z]+/', $bare, -1, PREG_SPLIT_NO_EMPTY ) as $tok ) {
			$t = strtoupper( (string) $tok );
			if ( @preg_match( $code_format, $t ) !== 1 ) {
				continue; // not a code shape
			}
			if ( isset( $reserved_u[ $t ] ) ) {
				if ( ! in_array( $t, $rejected, true ) ) {
					$rejected[] = $t; // reserved place/source/stopword — never a person
				}
				continue;
			}
			if ( isset( $roster_u[ $t ] ) && ! in_array( $t, $hits, true ) ) {
				$hits[] = $t; // roster-guarded: only configured initials count
			}
		}

		if ( count( $hits ) === 1 ) {
			return [ 'scanned' => true, 'candidate' => $hits[0], 'matched' => true, 'ambiguous' => false, 'rejected' => $rejected, 'reason' => 'single roster match — shadow only, not paid' ];
		}
		if ( count( $hits ) >= 2 ) {
			return [ 'scanned' => true, 'candidate' => '', 'matched' => false, 'ambiguous' => true, 'rejected' => $rejected, 'reason' => 'ambiguous: ' . count( $hits ) . ' roster matches — no guess' ];
		}
		return [ 'scanned' => true, 'candidate' => '', 'matched' => false, 'ambiguous' => false, 'rejected' => $rejected, 'reason' => $rejected ? 'only reserved/stopword tokens — rejected' : 'no roster match' ];
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
