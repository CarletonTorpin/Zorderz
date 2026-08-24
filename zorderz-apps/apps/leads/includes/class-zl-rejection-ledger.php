<?php
/**
 * Zorderz Leads — Rejection accounting + "no matches is a result" (D-04 / S6-05)
 *
 * Legibility over the SAME numbers. This adds no counting authority and moves no total;
 * it only makes an existing outcome legible. Two disciplines:
 *
 *   1. THE FUNNEL BALANCES. Every candidate that entered a run either became a lead or
 *      was dropped at exactly one named gate. So `scanned == matched + Σ rejections`
 *      always holds; {@see balances()} is the check, and a run that cannot balance is a
 *      bug in the caller's accounting, surfaced rather than hidden. "Nothing silent."
 *
 *   2. "NO MATCHES" IS A RESULT. An empty run is a definite answer — a neutral outcome at
 *      100% that NAMES the gate that emptied it — not a blank, a 0%, or red error text.
 *      Red text that isn't an error trains an operator to ignore red text, so an empty
 *      result gets its own outcome object, never `failed`.
 *
 * Territory (and any place) names in the human message come from CONFIG passed by the
 * caller — never a literal in this file. With no names configured it falls back to the
 * bare codes; it never invents a place name.
 *
 * Pure PHP: no WordPress, no I/O. Proven in a no-WP harness.
 *
 * @package Zorderz\Leads
 * @since   2.8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZL_Rejection_Ledger {

	/**
	 * Canonical gate order + human labels. A gate not listed here is still accepted
	 * (its slug is humanized), so the mechanism is future-proof as more gates are
	 * instrumented — but these are the funnel's known drop points.
	 *
	 * @var array<string,string>
	 */
	const GATE_LABELS = array(
		'cooldown'       => 'recently contacted (cooldown)',
		'product'        => 'product filter',
		'enrich'         => 'no usable contact data',
		'pre_candidate'  => 'earlier filters',
		'territory'      => 'territory / service area',
		'over_limit'     => 'beyond the batch limit',
		'demographic'    => 'demographic filter',
		'duplicate'      => 'already a lead (duplicate)',
		'ai_validation'  => 'AI strict review',
	);

	/**
	 * Build a ledger from a scanned total, per-gate rejection counts, and the matched
	 * count. Counts are coerced to non-negative integers. The ledger records whether the
	 * funnel balances; it never alters the numbers it was handed.
	 *
	 * @param int   $scanned     Items that entered the funnel.
	 * @param array $rejections  Map gate-slug => count.
	 * @param int   $matched     Items that became leads.
	 * @return array
	 */
	public static function make( int $scanned, array $rejections, int $matched ): array {
		$scanned = max( 0, $scanned );
		$matched = max( 0, $matched );

		$clean = array();
		$sum   = 0;
		foreach ( $rejections as $gate => $count ) {
			$gate  = (string) $gate;
			$count = max( 0, (int) $count );
			if ( $gate === '' ) {
				continue;
			}
			// Merge duplicate slugs additively rather than clobbering.
			$clean[ $gate ] = ( $clean[ $gate ] ?? 0 ) + $count;
			$sum           += $count;
		}

		return array(
			'scanned'        => $scanned,
			'matched'        => $matched,
			'rejections'     => $clean,
			'total_rejected' => $sum,
			'balanced'       => ( $matched + $sum ) === $scanned,
		);
	}

	/**
	 * Does the funnel balance? scanned == matched + Σ rejections.
	 *
	 * @param array $ledger
	 * @return bool
	 */
	public static function balances( array $ledger ): bool {
		return isset( $ledger['balanced'] ) && $ledger['balanced'] === true;
	}

	/**
	 * The single gate that dropped the most, or '' when nothing was dropped. Ties break
	 * by canonical gate order (earlier wins) for a stable message.
	 *
	 * @param array $ledger
	 * @return string
	 */
	public static function dominant_gate( array $ledger ): string {
		$rej = isset( $ledger['rejections'] ) && is_array( $ledger['rejections'] ) ? $ledger['rejections'] : array();
		if ( empty( $rej ) ) {
			return '';
		}
		$order = array_keys( self::GATE_LABELS );
		$best  = '';
		$best_n = 0;
		$best_rank = PHP_INT_MAX;
		foreach ( $rej as $gate => $n ) {
			$n = (int) $n;
			if ( $n <= 0 ) {
				continue;
			}
			$rank = array_search( $gate, $order, true );
			$rank = ( $rank === false ) ? PHP_INT_MAX - 1 : $rank;
			if ( $n > $best_n || ( $n === $best_n && $rank < $best_rank ) ) {
				$best      = (string) $gate;
				$best_n    = $n;
				$best_rank = $rank;
			}
		}
		return $best;
	}

	/**
	 * Human label for a gate slug. Unknown slugs are humanized (underscores → spaces).
	 *
	 * @param string $gate
	 * @return string
	 */
	public static function gate_label( string $gate ): string {
		if ( isset( self::GATE_LABELS[ $gate ] ) ) {
			return self::GATE_LABELS[ $gate ];
		}
		return trim( str_replace( '_', ' ', $gate ) );
	}

	/**
	 * Resolve a territory CODE to its configured NAME. The name map is supplied by the
	 * caller (from `zl_zip_territories()` / a filter). With no name configured the bare
	 * code is used — a name is NEVER invented here.
	 *
	 * @param string $code
	 * @param array  $names  Map code => display name (config-provided).
	 * @return string
	 */
	public static function territory_label( string $code, array $names = array() ): string {
		$code = trim( $code );
		if ( $code === '' ) {
			return '';
		}
		if ( isset( $names[ $code ] ) && trim( (string) $names[ $code ] ) !== '' ) {
			return (string) $names[ $code ];
		}
		// Case-insensitive fallback (codes are often upper-cased downstream).
		foreach ( $names as $k => $v ) {
			if ( strcasecmp( (string) $k, $code ) === 0 && trim( (string) $v ) !== '' ) {
				return (string) $v;
			}
		}
		return $code; // bare code, never a literal place name
	}

	/**
	 * A legible, one-paragraph account of where the candidates went. Names every gate
	 * that dropped anything (in canonical order), calls out the dominant gate, and —
	 * when territory is dominant and `territory_seen` is supplied — names the territories
	 * the candidates were actually in, using CONFIG names. When the AI review is the gate
	 * that emptied the run, it says so in the operator's terms.
	 *
	 * @param array $ledger
	 * @param array $territory_names  Map territory-code => display name (config).
	 * @param array $opts             { territory_seen: map code=>count, filters: list<string> }
	 * @return string
	 */
	public static function describe( array $ledger, array $territory_names = array(), array $opts = array() ): string {
		$scanned  = (int) ( $ledger['scanned'] ?? 0 );
		$matched  = (int) ( $ledger['matched'] ?? 0 );
		$rej      = isset( $ledger['rejections'] ) && is_array( $ledger['rejections'] ) ? $ledger['rejections'] : array();
		$dominant = self::dominant_gate( $ledger );

		$parts = array();
		$parts[] = sprintf(
			'Scanned %d; %d matched.',
			$scanned,
			$matched
		);

		// Which filters were applied (named, from the caller) — so an empty result names
		// the whole gate set, not just the one that fired.
		$filters = isset( $opts['filters'] ) && is_array( $opts['filters'] ) ? array_filter( array_map( 'strval', $opts['filters'] ) ) : array();
		if ( ! empty( $filters ) ) {
			$parts[] = 'Filters applied: ' . implode( ', ', $filters ) . '.';
		}

		// Per-gate breakdown in canonical order.
		if ( ! empty( $rej ) ) {
			$ordered = array();
			foreach ( array_keys( self::GATE_LABELS ) as $gate ) {
				if ( ! empty( $rej[ $gate ] ) ) {
					$ordered[ $gate ] = (int) $rej[ $gate ];
				}
			}
			// Any non-canonical gates after the known ones.
			foreach ( $rej as $gate => $n ) {
				if ( ! isset( $ordered[ $gate ] ) && (int) $n > 0 ) {
					$ordered[ $gate ] = (int) $n;
				}
			}
			$bits = array();
			foreach ( $ordered as $gate => $n ) {
				$bits[] = sprintf( '%d at %s', $n, self::gate_label( $gate ) );
			}
			if ( ! empty( $bits ) ) {
				$parts[] = 'Dropped: ' . implode( '; ', $bits ) . '.';
			}
		}

		// Name the dominant gate.
		if ( $dominant !== '' ) {
			$dn = (int) ( $rej[ $dominant ] ?? 0 );

			if ( $dominant === 'ai_validation' ) {
				// The AI review gate speaks in the operator's terms.
				$passed = $matched + $dn;
				$parts[] = sprintf(
					'%d passed every filter, then the AI review dropped %s — not a territory or product miss.',
					$passed,
					$dn === $passed ? 'them all' : ( $dn . ' of them' )
				);
			} elseif ( $dominant === 'territory' ) {
				$msg = sprintf( 'The biggest drop was %s (%d).', self::gate_label( $dominant ), $dn );
				$seen = isset( $opts['territory_seen'] ) && is_array( $opts['territory_seen'] ) ? $opts['territory_seen'] : array();
				arsort( $seen );
				$named = array();
				foreach ( $seen as $code => $n ) {
					$label = self::territory_label( (string) $code, $territory_names );
					if ( $label !== '' ) {
						$named[] = sprintf( '%s (%d)', $label, (int) $n );
					}
				}
				if ( ! empty( $named ) ) {
					$msg .= ' Candidates were in ' . implode( ', ', $named ) . ' — a territory mismatch, not a product one. Try All Salespeople.';
				} else {
					$msg .= ' Try widening the service area or All Salespeople.';
				}
				$parts[] = $msg;
			} else {
				$parts[] = sprintf( 'The biggest drop was %s (%d).', self::gate_label( $dominant ), $dn );
			}
		}

		return implode( ' ', $parts );
	}

	/**
	 * "No matches" as a definite result object — never null, never an error. A neutral
	 * terminal outcome at 100% that carries the balanced funnel and a legible message.
	 * Distinct from `failed`/`complete` so the poller and badges can render it in its own
	 * (neutral) colour.
	 *
	 * @param array $ledger
	 * @param array $territory_names  Config code => name map.
	 * @param array $opts             { territory_seen, filters }  (passed to describe)
	 * @return array
	 */
	public static function no_matches_result( array $ledger, array $territory_names = array(), array $opts = array() ): array {
		return array(
			'outcome'        => 'no_matches',
			'is_result'      => true,   // a definite answer …
			'is_error'       => false,  // … not an error
			'status'         => 'no_matches',
			'pct'            => 100,     // it ran to completion; 0% reads as "died early"
			'scanned'        => (int) ( $ledger['scanned'] ?? 0 ),
			'matched'        => (int) ( $ledger['matched'] ?? 0 ),
			'rejections'     => isset( $ledger['rejections'] ) && is_array( $ledger['rejections'] ) ? $ledger['rejections'] : array(),
			'total_rejected' => (int) ( $ledger['total_rejected'] ?? 0 ),
			'balanced'       => self::balances( $ledger ),
			'dominant'       => self::dominant_gate( $ledger ),
			'message'        => self::describe( $ledger, $territory_names, $opts ),
		);
	}
}
