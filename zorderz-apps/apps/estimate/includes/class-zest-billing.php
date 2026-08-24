<?php
/**
 * ZEST_Billing — the three-state estimate→invoice billing resolver.
 *
 * "Did this estimate become an invoice?" is answered by an INTERNAL JOIN against Zorderz's
 * own invoice-document table (the native store the estimate app writes on convert, keyed by
 * the estimate row's converted_invoice_id) — NEVER a provider probe. Zorderz owns both
 * documents, so the link is a local join, not an outbound billing-provider call.
 *
 * THREE STATES — and the reason the middle one needs its own column:
 *   - invoiced      converted_invoice_id resolves to a LIVE invoice row (it exists and is
 *                   not voided).
 *   - not_invoiced  we have CHECKED (invoice_checked_at IS NOT NULL) and found no live invoice.
 *   - unchecked     invoice_checked_at IS NULL — nobody has asked yet. This is a DIFFERENT
 *                   fact from "not invoiced": a blank billing panel must never read as "never
 *                   invoiced", which would be the exact wrong answer.
 *
 * `unchecked` is deliberately SILENT to the Ai (ai_state() returns null): it is a fact about
 * our own plumbing — the internal join has not been run for this estimate — not a fact about
 * the customer's job. resolve() is READ-ONLY; the deliberate act of checking is the only thing
 * that stamps invoice_checked_at, and it is recorded by mark_checked() alone. No money moves
 * here — this class reports linkage, never a figure.
 *
 * @package Zorderz\Estimate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZEST_Billing {

	/** The three billing states. Constants so the values are never typed twice. */
	const STATE_INVOICED     = 'invoiced';
	const STATE_NOT_INVOICED = 'not_invoiced';
	const STATE_UNCHECKED    = 'unchecked';

	/**
	 * Resolve the billing state of one estimate.
	 *
	 * @param int $estimate_id
	 * @return array{state:string,invoice_id:int,checked:bool,checked_at:?string}
	 */
	public static function resolve( int $estimate_id ): array {
		if ( $estimate_id <= 0 ) {
			return self::unknown_state();
		}
		$many = self::resolve_many( array( $estimate_id ) );
		return $many[ $estimate_id ] ?? self::unknown_state();
	}

	/**
	 * Batch resolver — one query for many estimates (no N+1 for a list panel). Returns a map
	 * keyed by estimate id; an id with no estimate row resolves to `unchecked` (the safe
	 * default — never a false "not invoiced").
	 *
	 * @param int[] $estimate_ids
	 * @return array<int,array{state:string,invoice_id:int,checked:bool,checked_at:?string}>
	 */
	public static function resolve_many( array $estimate_ids ): array {
		global $wpdb;
		$out = array();
		$ids = array_values( array_unique( array_filter(
			array_map( 'intval', $estimate_ids ),
			static function ( $i ) { return $i > 0; }
		) ) );
		if ( empty( $ids ) ) {
			return $out;
		}

		$et = ZEST_DB::estimates_table();
		$it = ZEST_DB::invoices_table();

		// Estimates table absent (partial/failed install): every id is unchecked. We can only
		// resolve `invoiced`/`not_invoiced` from real rows; without them we stay silent.
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $et ) ) !== $et ) {
			foreach ( $ids as $id ) {
				$out[ $id ] = self::unknown_state();
			}
			return $out;
		}

		$ph      = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$has_inv = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $it ) ) === $it );

		if ( $has_inv ) {
			// The internal join: estimate → its own converted invoice document.
			$sql = "SELECT e.id AS est_id, e.converted_invoice_id AS civ, e.invoice_checked_at AS chk,
			               i.id AS inv_id, i.status AS inv_status
			          FROM {$et} e
			          LEFT JOIN {$it} i ON i.id = e.converted_invoice_id
			         WHERE e.id IN ({$ph})";
		} else {
			// No invoice table yet: no live invoice can exist, so the join collapses to null.
			$sql = "SELECT e.id AS est_id, e.converted_invoice_id AS civ, e.invoice_checked_at AS chk,
			               NULL AS inv_id, NULL AS inv_status
			          FROM {$et} e
			         WHERE e.id IN ({$ph})";
		}

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$ids ), ARRAY_A );
		$seen = array();
		foreach ( (array) $rows as $r ) {
			$eid          = (int) $r['est_id'];
			$seen[ $eid ] = true;
			$out[ $eid ]  = self::classify( $r['civ'], $r['chk'], $r['inv_id'], $r['inv_status'] );
		}
		// Any requested id without an estimate row → unchecked (never a false "not invoiced").
		foreach ( $ids as $id ) {
			if ( empty( $seen[ $id ] ) ) {
				$out[ $id ] = self::unknown_state();
			}
		}
		return $out;
	}

	/**
	 * The Ai-facing projection. Returns the billing state for the Ai to speak, EXCEPT when the
	 * state is `unchecked` — that one is deliberately silent (null): the customer's job has no
	 * "unchecked" fact, only our plumbing does. `invoiced` / `not_invoiced` are honest,
	 * customer-relevant facts the Ai may use.
	 *
	 * @param int $estimate_id
	 * @return string|null  'invoiced' | 'not_invoiced', or null to say nothing.
	 */
	public static function ai_state( int $estimate_id ): ?string {
		$state = self::resolve( $estimate_id )['state'];
		return ( self::STATE_UNCHECKED === $state ) ? null : $state;
	}

	/**
	 * Record the deliberate act of checking an estimate's billing state. This is the ONLY
	 * writer of invoice_checked_at; stamping it moves a row out of `unchecked`. It never
	 * touches money or the invoice link — only the "we looked" marker.
	 *
	 * @param int $estimate_id
	 * @return bool  true when the marker was written.
	 */
	public static function mark_checked( int $estimate_id ): bool {
		global $wpdb;
		if ( $estimate_id <= 0 ) {
			return false;
		}
		$et = ZEST_DB::estimates_table();
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $et ) ) !== $et ) {
			return false;
		}
		$updated = $wpdb->update(
			$et,
			array( 'invoice_checked_at' => current_time( 'mysql' ) ),
			array( 'id' => $estimate_id )
		);
		return false !== $updated;
	}

	/* ─────────────────────────────── internals ──────────────────────────── */

	/**
	 * The three-state decision from the joined columns. Priority: a live internal invoice link
	 * is authoritative (`invoiced`) regardless of the marker; otherwise the marker separates
	 * `not_invoiced` (checked) from `unchecked` (never asked — the NULL that must survive).
	 *
	 * @param mixed $civ        converted_invoice_id
	 * @param mixed $chk        invoice_checked_at
	 * @param mixed $inv_id     joined invoice id (null when none / not live)
	 * @param mixed $inv_status joined invoice status
	 * @return array{state:string,invoice_id:int,checked:bool,checked_at:?string}
	 */
	private static function classify( $civ, $chk, $inv_id, $inv_status ): array {
		$inv_id  = (int) $inv_id;
		$live    = ( $inv_id > 0 ) && self::is_live_status( (string) $inv_status );
		$checked = self::is_checked( $chk );

		if ( $live ) {
			$state = self::STATE_INVOICED;
		} elseif ( $checked ) {
			$state = self::STATE_NOT_INVOICED;
		} else {
			$state = self::STATE_UNCHECKED;
		}

		return array(
			'state'      => $state,
			'invoice_id' => $live ? $inv_id : 0,
			'checked'    => $checked,
			'checked_at' => $checked ? (string) $chk : null,
		);
	}

	/**
	 * Is invoice_checked_at a real timestamp? NULL, empty, and the MySQL zero-date all mean
	 * "nobody has asked" — the `unchecked` fact this whole class is built to preserve.
	 *
	 * @param mixed $chk
	 * @return bool
	 */
	private static function is_checked( $chk ): bool {
		if ( null === $chk ) {
			return false;
		}
		$chk = trim( (string) $chk );
		return ( '' !== $chk && '0000-00-00 00:00:00' !== $chk );
	}

	/**
	 * A linked invoice row is "live" (a real billing document) unless it has been voided. This
	 * is only consulted once the row is known to exist, so an empty status still counts as
	 * live — existence is the signal, `void` is the single terminal exception.
	 *
	 * @param string $status
	 * @return bool
	 */
	private static function is_live_status( string $status ): bool {
		return 'void' !== strtolower( trim( $status ) );
	}

	/**
	 * The SAFE default when a row cannot be read: `unchecked` (silent to the Ai), NEVER
	 * `not_invoiced`. A blank or unreadable billing panel must not read as "never invoiced".
	 *
	 * @return array{state:string,invoice_id:int,checked:bool,checked_at:?string}
	 */
	private static function unknown_state(): array {
		return array(
			'state'      => self::STATE_UNCHECKED,
			'invoice_id' => 0,
			'checked'    => false,
			'checked_at' => null,
		);
	}
}
