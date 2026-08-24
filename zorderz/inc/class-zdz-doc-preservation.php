<?php
/**
 * Zdz_Doc_Preservation — the shared document preservation lock (§61).
 *
 * A priced document's sold lines are not silently rewritten. When the operator's WORDS
 * ask to leave a document as-is (e.g. "just add the photo measurements to the lead"),
 * the lock restores the original line items after the model's parse, keeps the internal
 * fields the model LEARNED (dimensions / measurements / sub_description), and hands the
 * caller a correction disclosure to prepend — while a compound "edit AND preserve"
 * request is let through untouched.
 *
 * Shared by the estimate update path (Plan 02 E1/E6) and the invoice line-edit path.
 * Every step is a named method so a test can exercise it in isolation — the §61 lesson.
 *
 * TWO SHIPPED-DEFECT LESSONS, encoded here:
 *   A1 — the provider WIRE shape carries `unit_cost` as an ARRAY ({amount:"85.00"}); a
 *        `(float)` cast of the whole array silently becomes 1.0 and zeroes every line.
 *        normalize_provider_items() reads unit_cost['amount'] as a nested member, never
 *        the array itself.
 *   A2 — an armed lock with a DIFFERING signature RESTORES; it never overwrites. The
 *        destructive branch is never the default.
 *
 * The lock NAMES NOTHING. Its arm / stand-down vocabularies come from typed
 * Zdz_Rule_Governance phrase rules (Core ships a neutral English default; a tenant adds
 * trade vocabulary via `zdz_rules`); its disclosure wording comes through a voice filter
 * with a neutral Core default. No company / person / product / place literal appears here.
 *
 * @since   1.2.0
 * @package Zorderz
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Zdz_Doc_Preservation {

	/**
	 * Fields that are SOLD — restored verbatim from the original document so a priced
	 * line is byte-identical after a preserve-only modify (the parity floor).
	 */
	const SOLD_FIELDS = array( 'description', 'quantity', 'unit_price', 'line_total', 'kind', 'is_lot', 'item_id', 'attribution' );

	/**
	 * Fields the model may have LEARNED on this turn — overlaid from the model output so a
	 * measurements update populates while pricing is restored. These are exactly the
	 * fields line_items_signature() ignores.
	 */
	const LEARNED_FIELDS = array( 'dimensions', 'measurements', 'sub_description', 'notes' );

	/* ============================================================== *
	 *  NORMALIZE  (provider WIRE shape → model shape, once)
	 * ============================================================== */

	/**
	 * Map the billing-provider WIRE shape (name / description / qty / unit_cost['amount'])
	 * to the model shape (description / sub_description / quantity / unit_price + kind),
	 * ONCE. Accepts an already-model-shaped item and passes it through field-by-field.
	 *
	 * A1 guard: unit_cost is read as a nested array member; the whole array is never cast.
	 *
	 * @param array $provider_items
	 * @return array<int,array> model-shape line items
	 */
	public static function normalize_provider_items( array $provider_items ): array {
		$out = array();
		foreach ( $provider_items as $pi ) {
			if ( ! is_array( $pi ) ) {
				continue;
			}

			// --- unit_price (A1) -------------------------------------------------
			$unit_price = 0.0;
			if ( array_key_exists( 'unit_price', $pi ) && ! is_array( $pi['unit_price'] ) ) {
				$unit_price = (float) $pi['unit_price'];
			} elseif ( array_key_exists( 'unit_cost', $pi ) ) {
				$uc = $pi['unit_cost'];
				if ( is_array( $uc ) ) {
					// The WIRE shape. Read the nested amount — NEVER (float) the array.
					$unit_price = isset( $uc['amount'] ) ? (float) $uc['amount'] : 0.0;
				} else {
					$unit_price = (float) $uc; // a provider that flattens unit_cost to a scalar
				}
			}

			// --- quantity --------------------------------------------------------
			$qty = 1;
			if ( array_key_exists( 'quantity', $pi ) ) {
				$qty = $pi['quantity'];
			} elseif ( array_key_exists( 'qty', $pi ) ) {
				$qty = $pi['qty'];
			}
			$qty = is_numeric( $qty ) ? ( 0 + $qty ) : $qty;

			// --- description / sub_description (name is the WIRE primary) ---------
			if ( array_key_exists( 'name', $pi ) && '' !== (string) $pi['name'] ) {
				$description     = (string) $pi['name'];
				$sub_description = (string) ( $pi['description'] ?? '' ); // wire secondary line
			} else {
				$description     = (string) ( $pi['description'] ?? '' );
				$sub_description = (string) ( $pi['sub_description'] ?? '' );
			}

			// --- kind ------------------------------------------------------------
			$kind = strtolower( trim( (string) ( $pi['kind'] ?? '' ) ) );
			if ( '' === $kind ) {
				$is_meta = class_exists( 'ZDZ_Doc_Conventions' )
					&& ZDZ_Doc_Conventions::is_metadata_line( $description, $unit_price );
				$kind = $is_meta ? 'context' : 'item';
			}

			$row = array(
				'kind'            => $kind,
				'description'     => $description,
				'sub_description' => $sub_description,
				'quantity'        => $qty,
				'unit_price'      => $unit_price,
			);
			// Carry through anything else the source already has (dimensions, item_id,
			// measurements, line_total, id, attribution, is_lot …) without inventing it.
			foreach ( array( 'line_total', 'item_id', 'is_lot', 'attribution', 'dimensions', 'measurements', 'id', 'notes' ) as $k ) {
				if ( array_key_exists( $k, $pi ) ) {
					$row[ $k ] = $pi[ $k ];
				}
			}
			$out[] = $row;
		}
		return $out;
	}

	/* ============================================================== *
	 *  ARM  (from the operator's WORDS, never the model's output — A2)
	 * ============================================================== */

	/** True when the request words ask to preserve the priced document. */
	public static function arm_from_request( string $request_words ): bool {
		return self::any_phrase_present( $request_words, self::rule_phrases( 'doc_preserve' ) );
	}

	/** True when the request words name an explicit line edit — stands the lock DOWN. */
	public static function stand_down_for_line_edit( string $request_words ): bool {
		return self::any_phrase_present( $request_words, self::rule_phrases( 'doc_line_edit' ) );
	}

	/** Phrase vocabulary for an intent, from Rule Governance; [] when unavailable. */
	private static function rule_phrases( string $intent ): array {
		if ( class_exists( 'ZDZ_Rule_Governance' ) && method_exists( 'ZDZ_Rule_Governance', 'phrases' ) ) {
			return (array) ZDZ_Rule_Governance::phrases( $intent );
		}
		return array();
	}

	/**
	 * Membership test: is any phrase present in the request? Both sides are normalized to
	 * a space-delimited lowercase token stream and compared by substring — a word/phrase
	 * membership test, NOT a regex over free text.
	 */
	private static function any_phrase_present( string $request, array $phrases ): bool {
		if ( empty( $phrases ) ) {
			return false;
		}
		$hay = self::normalize_stream( $request );
		if ( ' ' === $hay ) {
			return false;
		}
		foreach ( $phrases as $phrase ) {
			$needle = self::normalize_stream( (string) $phrase );
			if ( ' ' === $needle ) {
				continue;
			}
			if ( false !== strpos( $hay, $needle ) ) {
				return true;
			}
		}
		return false;
	}

	/** Lowercase; collapse every non-alphanumeric run to one space; pad with spaces. */
	private static function normalize_stream( string $s ): string {
		$s = strtolower( $s );
		$s = preg_replace( '/[^a-z0-9]+/u', ' ', $s ); // normalizer, not a matcher
		$s = trim( (string) preg_replace( '/\s+/', ' ', (string) $s ) );
		return ' ' . $s . ' ';
	}

	/* ============================================================== *
	 *  SIGNATURE  (the narrow fingerprint — the parity floor)
	 * ============================================================== */

	/**
	 * The narrow line-items fingerprint: unit_price as integer cents, quantity
	 * string-normalized ("20" === 20 === "20.0"), kind + description casefolded —
	 * IGNORING dimensions, sub_description, measurements and every internal-only field
	 * (exactly what a measurements update fills in). A legitimate $0 context / metadata
	 * line contributes its kind + description but no price delta.
	 */
	public static function line_items_signature( array $items ): string {
		$parts = array();
		foreach ( $items as $li ) {
			if ( ! is_array( $li ) ) {
				continue;
			}
			$cents = (int) round( ( (float) ( $li['unit_price'] ?? 0 ) ) * 100 );
			$qty   = array_key_exists( 'quantity', $li ) ? $li['quantity'] : ( $li['qty'] ?? '' );
			$qty   = is_numeric( $qty ) ? (string) ( 0 + $qty ) : trim( (string) $qty );
			$kind  = strtolower( trim( (string) ( $li['kind'] ?? '' ) ) );
			$desc  = strtolower( trim( (string) ( $li['description'] ?? '' ) ) );
			$parts[] = $kind . '|' . $desc . '|' . $cents . '|' . $qty;
		}
		return implode( '||', $parts );
	}

	/* ============================================================== *
	 *  RESTORE  (sold fields from original; learned fields from model)
	 * ============================================================== */

	/**
	 * Restore what is SOLD from the original document while keeping what the model
	 * LEARNED. Positional: the model is instructed to keep order, so line i of the output
	 * inherits its internal fields from model line i. Priced lines come back byte-identical
	 * to the original (the parity floor); measurements / dimensions / sub_description
	 * populate from the model where present.
	 */
	public static function restore_preserving_internal_fields( array $original_model, array $model_out ): array {
		$out = array();
		$i   = 0;
		foreach ( $original_model as $orig ) {
			if ( ! is_array( $orig ) ) {
				$i++;
				continue;
			}
			$line     = $orig; // start from the sold truth
			$overlay  = ( isset( $model_out[ $i ] ) && is_array( $model_out[ $i ] ) ) ? $model_out[ $i ] : array();
			foreach ( self::LEARNED_FIELDS as $f ) {
				// Overlay a learned field only when the model actually filled it in.
				if ( array_key_exists( $f, $overlay ) && '' !== $overlay[ $f ] && array() !== $overlay[ $f ] ) {
					$line[ $f ] = $overlay[ $f ];
				}
			}
			$out[] = $line;
			$i++;
		}
		return $out;
	}

	/* ============================================================== *
	 *  DISCLOSURE  (PREPENDED — never appended; §61 truncation lesson)
	 * ============================================================== */

	/**
	 * Prepend a correction disclosure to a document's notes. A trailing note was once
	 * swallowed by a 77-byte truncation, so the disclosure goes at the FRONT.
	 *
	 * @param array  $doc   a document with a notes-carrying field.
	 * @param string $note  the disclosure to prepend.
	 * @param string $field which field carries the note (default 'notes').
	 */
	public static function prepend_disclosure( array $doc, string $note, string $field = 'notes' ): array {
		$note = trim( $note );
		if ( '' === $note ) {
			return $doc;
		}
		$existing = (string) ( $doc[ $field ] ?? '' );
		$doc[ $field ] = ( '' !== trim( $existing ) ) ? ( $note . "\n" . $existing ) : $note;
		return $doc;
	}

	/** The disclosure wording — [IDENTITY→voice] with a neutral Core default. */
	public static function disclosure_text( array $ctx = array() ): string {
		$default = 'Pricing was left unchanged as requested; the added details were recorded without altering the priced lines.';
		return (string) apply_filters( 'zdz_doc_preservation_disclosure', $default, $ctx );
	}

	/* ============================================================== *
	 *  APPLY  (orchestration — restore is never the destructive default)
	 * ============================================================== */

	/**
	 * The orchestration. If the request arms the lock and does NOT name a line edit, and
	 * the model's output changed the sold signature, restore the original priced lines
	 * (keeping learned internal fields) and return a disclosure to prepend. In every other
	 * case the model output stands.
	 *
	 * @param array  $original_model_items the prior document (model shape).
	 * @param array  $model_items          the model's proposed items.
	 * @param string $request_words        the operator's instruction.
	 * @param array  $ctx                  passed to the disclosure voice filter.
	 * @return array{ line_items:array, preserved:bool, disclosure:string }
	 */
	public static function apply( array $original_model_items, array $model_items, string $request_words, array $ctx = array() ): array {
		$result = array( 'line_items' => $model_items, 'preserved' => false, 'disclosure' => '' );

		$armed = self::arm_from_request( $request_words );
		if ( ! $armed || self::stand_down_for_line_edit( $request_words ) ) {
			// No preserve intent, or an explicit line edit was named — the model stands.
			return $result;
		}

		if ( self::line_items_signature( $original_model_items ) === self::line_items_signature( $model_items ) ) {
			// The model already preserved the sold lines — nothing to restore.
			return $result;
		}

		// A2: armed + a differing signature → RESTORE. Never the destructive branch.
		$restored             = self::restore_preserving_internal_fields( $original_model_items, $model_items );
		$result['line_items'] = $restored;
		$result['preserved']  = true;
		$result['disclosure'] = self::disclosure_text( $ctx );

		error_log( sprintf(
			'Zorderz Doc Preservation: armed lock restored %d priced line(s) after a signature mismatch (learned fields kept).',
			count( $restored )
		) );

		return $result;
	}
}
