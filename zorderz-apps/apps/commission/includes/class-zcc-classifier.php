<?php
/**
 * ZCC_Classifier — invoice line classifier.
 *
 * The old plugin carried a 1,400-line keyword cascade full of product brands,
 * model numbers and real transaction examples. Per the crosswalk (IC-02, CN-08,
 * CP-13, CP-14) that entire taxonomy moves to the Item Engine and the real
 * examples are deleted. What remains here is Core mechanism only:
 *
 *   1. LEDGER KINDS first. A line that is money-but-not-a-sale (discount, card
 *      fee, refund, gratuity, pass-through) is a Ledger Entry, classified by
 *      ZDZ_Compensation::classify_ledger_kind() — a generic, name-free matcher
 *      with the safety-floor flags baked in (a refund is never commissionable).
 *
 *   2. PRODUCT lines are classified through the Item Engine
 *      (zdz_item_classify / zdz_item_match). The result is an ITEM ID, never a
 *      hardcoded category. An empty catalog returns '' ⇒ 'unknown', which the
 *      calc engine books at $0 COGS and (for a priced line) flags for review.
 *
 *   3. QUANTITY comes from the line text ("(4) …") when the invoice qty field is
 *      1 — a Core count-extraction step with a unit-word rejection list so
 *      "(14x6)" / "(50%)" / "($600)" never read as a count.
 *
 * Deterministic: same description ⇒ same classification, cached by
 * description + Item Engine version so editing the catalog self-invalidates.
 * ZERO LLM in this path.
 *
 * @package Zorderz\Commission
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZCC_Classifier {

	/** Request-level classification cache: "desc|iev" => result. */
	private static $cache = [];

	/** Tokens inside "(…)" that mean the parenthetical is NOT a unit count. */
	const UNIT_WORD_REJECTS = [ 'in', 'inch', 'inches', 'ft', 'foot', 'feet', 'cm', 'mm', 'by', 'gauge', 'x' ];

	/**
	 * Classify an array of lines. Returns classifications indexed by position.
	 *
	 * @param array $lines [ { description, qty, amount }, ... ]
	 * @return array [ { item_id, subtype, category, quantity, confidence,
	 *                  non_commissionable, counts_toward_revenue, ledger_kind,
	 *                  unit_noun_singular, unit_noun_plural, notes }, ... ]
	 */
	public static function classify_lines( array $lines ): array {
		$out = [];
		foreach ( $lines as $i => $line ) {
			$out[ $i ] = self::classify_line( $line );
		}
		ksort( $out );
		return $out;
	}

	/** Classify one line. */
	public static function classify_line( array $line ): array {
		$desc = trim( (string) ( $line['description'] ?? '' ) );
		$qty  = max( 1, (int) ( $line['qty'] ?? 1 ) );

		$key = md5( strtolower( $desc ) ) . '|' . zcc_item_engine_version();
		if ( isset( self::$cache[ $key ] ) ) {
			$c = self::$cache[ $key ];
			$c['quantity'] = self::effective_quantity( $desc, $qty );
			return $c;
		}

		// ── 1. Ledger kind (non-sale money) ──
		$ledger = class_exists( 'ZDZ_Compensation' ) ? ZDZ_Compensation::classify_ledger_kind( $desc ) : null;
		if ( $ledger !== null ) {
			$def    = $ledger['def'];
			$result = [
				'item_id'                  => '',
				'subtype'                  => '',
				'category'                 => 'ledger_' . $ledger['kind'],
				'ledger_kind'              => $ledger['kind'],
				'quantity'                 => 1,
				'confidence'               => 1.0,
				'non_commissionable'       => empty( $def['counts_toward_commission'] ),
				'counts_toward_revenue'    => ! empty( $def['counts_toward_revenue'] ),
				'sign'                     => (string) ( $def['sign'] ?? 'positive' ),
				'unit_noun_singular'       => '',
				'unit_noun_plural'         => '',
				'notes'                    => (string) ( $def['label'] ?? 'Ledger entry' ) . ' (non-product).',
				'classified_by'            => 'ledger_kind',
			];
			self::$cache[ $key ] = $result;
			return $result;
		}

		// ── 2. Product line via the Item Engine ──
		$item_id = zcc_item_classify( $desc );
		$item    = $item_id !== '' ? zcc_item_get( $item_id ) : zcc_item_match( $desc );
		if ( is_array( $item ) && $item_id === '' ) {
			$item_id = (string) ( $item['id'] ?? '' );
		}

		if ( $item_id !== '' && is_array( $item ) ) {
			$attrs  = is_array( $item['attributes'] ?? null ) ? $item['attributes'] : [];
			$result = [
				'item_id'                  => $item_id,
				'subtype'                  => (string) ( $item['subtype'] ?? '' ),
				'category'                 => (string) ( $item['subtype'] ?? $item['type'] ?? 'product' ),
				'ledger_kind'              => '',
				'quantity'                 => $qty,
				'confidence'               => 0.9,
				'non_commissionable'       => ! empty( $attrs['non_commissionable'] ),
				'counts_toward_revenue'    => true,
				'sign'                     => 'positive',
				'unit_noun_singular'       => (string) ( $item['unit_noun_singular'] ?? '' ),
				'unit_noun_plural'         => (string) ( $item['unit_noun_plural'] ?? '' ),
				'notes'                    => '',
				'classified_by'            => 'item_engine',
			];
			self::$cache[ $key ] = $result;
			$result['quantity'] = self::effective_quantity( $desc, $qty );
			return $result;
		}

		// ── 3. Unknown — neutral degrade (empty catalog or no match) ──
		$result = [
			'item_id'                  => '',
			'subtype'                  => '',
			'category'                 => 'unknown',
			'ledger_kind'              => '',
			'quantity'                 => $qty,
			'confidence'               => 0.0,
			'non_commissionable'       => false,
			'counts_toward_revenue'    => true,
			'sign'                     => 'positive',
			'unit_noun_singular'       => '',
			'unit_noun_plural'         => '',
			'notes'                    => 'No catalog match — flagged for review.',
			'classified_by'            => 'fallback',
			'needs_review'             => true,
		];
		// Do not cache the "unknown" result: adding the item to the catalog should
		// make the very next classification succeed without a manual cache clear.
		$result['quantity'] = self::effective_quantity( $desc, $qty );
		return $result;
	}

	/**
	 * Effective unit count = the "(N)" stated in the description × the invoice qty
	 * when qty > 1. The QTY-FIELD TRAP: a job billed as one "(4) …" line has a
	 * FreshBooks qty of 1 but four units. A rejection list keeps "(14x6)", "(50%)"
	 * and "($600)" from ever reading as a count.
	 */
	public static function effective_quantity( string $desc, int $billing_qty ): int {
		$billing_qty = max( 1, $billing_qty );
		$text_count  = self::text_count( $desc );
		if ( $text_count > 0 ) {
			return $billing_qty > 1 ? $text_count * $billing_qty : $text_count;
		}
		return $billing_qty;
	}

	/** Parse a leading/parenthetical "(N)" unit count, honouring the reject list. Returns 0 when none. */
	private static function text_count( string $desc ): int {
		if ( preg_match_all( '/\(\s*(\d{1,3})\s*([a-z%$"\']*)\s*\)/i', $desc, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $hit ) {
				$n    = (int) $hit[1];
				$tail = strtolower( trim( $hit[2] ) );
				if ( $n < 1 || $n > 500 ) {
					continue;
				}
				if ( $tail !== '' && in_array( $tail, self::UNIT_WORD_REJECTS, true ) ) {
					continue;
				}
				// A bare "(N)" with no trailing unit word is a count.
				if ( $tail === '' ) {
					return $n;
				}
			}
		}
		return 0;
	}
}
