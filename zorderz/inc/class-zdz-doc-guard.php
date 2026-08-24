<?php
/**
 * Zdz_Doc_Guard — the shared value-floor / no-silent-unpricing guard (§62).
 *
 * A priced document must not silently become unpriced. A write that would take a
 * document CURRENTLY carrying money down to $0.00 is refused, quoting BOTH figures.
 * Creating a deliberate $0 stub (no prior money) is untouched — that is the $0-stub
 * allowance matrix's job, not this guard's.
 *
 * DESIGN INVARIANTS, ported verbatim (§62):
 *   - IDENTITY-BLIND. The guard forms no opinion about who the user is — no $uid, no
 *     role, no capability — so ONE call sits on a POST, an admin save and a chat marker
 *     without three rule copies.
 *   - FAIL-OPEN. An unreadable prior total ($prior === null) is NOT evidence of a hazard
 *     (INV-12 read in the forgotten direction): the write proceeds.
 *   - FILTER ESCAPE, NEVER A ROLE. The escape hatch is `zdz_allow_zero_regression`,
 *     a filter defaulting false.
 *   - INVOICES ARE EXEMPT. They append a revision, never replace lines — callers simply
 *     do not wire this guard on the invoice write.
 *
 * Shared by the estimate create + update + chat paths.
 *
 * @since   1.2.0
 * @package Zorderz
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Zdz_Doc_Guard {

	/**
	 * Refuse a priced → $0 regression. Identity-blind; fail-open on an unknown prior.
	 *
	 * @param float|null $prior_total the document's total BEFORE the write, or null when
	 *                                it could not be read (drives fail-open).
	 * @param float      $new_total   the recomputed total the write would land on.
	 * @param array      $ctx         opaque context passed to the escape filter.
	 * @return array{refuse:bool,message:string}
	 */
	public static function zero_regression_refusal( ?float $prior_total, float $new_total, array $ctx = array() ): array {
		$out = array( 'refuse' => false, 'message' => '' );

		// Fail-open: an unreadable prior is not evidence of a hazard.
		if ( null === $prior_total ) {
			return $out;
		}
		// Only a document that currently carries money can regress to nothing.
		if ( $prior_total <= 0 || $new_total > 0 ) {
			return $out;
		}

		// Escape hatch: a FILTER defaulting false — never a capability / role.
		if ( (bool) apply_filters( 'zdz_allow_zero_regression', false, $ctx ) ) {
			error_log( sprintf(
				'Zorderz Doc Guard: priced→$0 regression allowed by zdz_allow_zero_regression filter (prior %.2f).',
				$prior_total
			) );
			return $out;
		}

		$out['refuse']  = true;
		$out['message'] = self::refusal_message( $prior_total, $new_total, $ctx );
		error_log( sprintf(
			'Zorderz Doc Guard: refused priced→$0 regression (from %.2f to %.2f). Nothing written.',
			$prior_total, $new_total
		) );
		return $out;
	}

	/**
	 * Total a provider payload in EITHER wire shape:
	 *   - a top-level amount/total (an object-level figure the provider reports), OR
	 *   - the sum over line items of unit_cost['amount'] × qty (the WIRE line shape).
	 * Returns null when nothing readable is present — which drives the guard fail-open.
	 *
	 * A1 discipline: unit_cost is read as a nested member, never cast as an array.
	 *
	 * @param array $provider_payload
	 * @return float|null
	 */
	public static function total_from_provider( array $provider_payload ): ?float {
		// Unwrap a common provider envelope { response:{ result:{ estimate:{…} } } }.
		$est = $provider_payload;
		if ( isset( $provider_payload['response']['result']['estimate'] ) && is_array( $provider_payload['response']['result']['estimate'] ) ) {
			$est = $provider_payload['response']['result']['estimate'];
		} elseif ( isset( $provider_payload['estimate'] ) && is_array( $provider_payload['estimate'] ) ) {
			$est = $provider_payload['estimate'];
		}

		// A top-level figure wins when present (the provider's own computed total).
		foreach ( array( 'amount', 'total' ) as $k ) {
			if ( isset( $est[ $k ] ) ) {
				$v = $est[ $k ];
				if ( is_array( $v ) && isset( $v['amount'] ) ) {
					return (float) $v['amount'];
				}
				if ( is_numeric( $v ) ) {
					return (float) $v;
				}
			}
		}

		// Else sum the lines in the wire (or model) shape.
		$lines = null;
		foreach ( array( 'lines', 'line_items', 'items' ) as $k ) {
			if ( isset( $est[ $k ] ) && is_array( $est[ $k ] ) ) {
				$lines = $est[ $k ];
				break;
			}
		}
		if ( ! is_array( $lines ) ) {
			return null; // nothing readable → fail-open
		}

		$sum = 0.0;
		foreach ( $lines as $li ) {
			if ( ! is_array( $li ) ) {
				continue;
			}
			$unit = 0.0;
			if ( isset( $li['unit_cost'] ) && is_array( $li['unit_cost'] ) ) {
				$unit = isset( $li['unit_cost']['amount'] ) ? (float) $li['unit_cost']['amount'] : 0.0;
			} elseif ( isset( $li['unit_cost'] ) && is_numeric( $li['unit_cost'] ) ) {
				$unit = (float) $li['unit_cost'];
			} elseif ( isset( $li['unit_price'] ) && is_numeric( $li['unit_price'] ) ) {
				$unit = (float) $li['unit_price'];
			}
			$qty = 1.0;
			if ( isset( $li['qty'] ) && is_numeric( $li['qty'] ) ) {
				$qty = (float) $li['qty'];
			} elseif ( isset( $li['quantity'] ) && is_numeric( $li['quantity'] ) ) {
				$qty = (float) $li['quantity'];
			}
			$sum += $unit * $qty;
		}
		return $sum;
	}

	/* ---- wording ([IDENTITY→voice] with a neutral Core default) ---- */

	/** The refusal message, quoting both figures. Overridable via the voice filter. */
	private static function refusal_message( float $prior_total, float $new_total, array $ctx ): string {
		$default = sprintf(
			'This would take the document from %s to %s. Nothing was written. Add pricing, or create a $0 stub deliberately if that is intended.',
			self::money( $prior_total ),
			self::money( $new_total )
		);
		return (string) apply_filters( 'zdz_doc_guard_refusal_message', $default, $prior_total, $new_total, $ctx );
	}

	/** Format a money figure with the tenant currency sigil (defaults to "$0.00"). */
	private static function money( float $v ): string {
		$sigil = '$';
		$dec   = '.';
		$thou  = ',';
		if ( class_exists( 'ZDZ_Answer_Authority' ) && method_exists( 'ZDZ_Answer_Authority', 'currency' ) ) {
			$c     = (array) ZDZ_Answer_Authority::currency();
			$sigil = (string) ( $c['sigil'] ?? $sigil );
			$dec   = (string) ( $c['decimal'] ?? $dec );
			$thou  = (string) ( $c['thousands'] ?? $thou );
		}
		return $sigil . number_format( $v, 2, $dec, $thou );
	}
}
