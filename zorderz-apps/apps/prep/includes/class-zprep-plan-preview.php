<?php
/**
 * ZPREP_Plan_Preview — each queue card's own last plan, and the colour the shop cut it in.
 *
 * After a job is computed, Prep remembers a small summary of its plan — pieces and material
 * area per colour — keyed PER JOB (the card's own reference, else the estimate it was planned
 * from, else its CRM lead). A queue card shows the summary of its own plan:
 *
 *   1. a remembered plan for the same customer whose reference / estimate / lead equals this
 *      card's is this card's plan (the most recent on a tie);
 *   2. otherwise, when this customer has more than one job on the queue, nothing is shown —
 *      one job's plan never appears on a sibling's card;
 *   3. otherwise (one job for this customer) the customer's most recent plan is shown.
 *
 * "Same customer" is ZPREP_Dashboard::same_customer() for both the match and the sibling
 * count, so the two can never disagree about who a customer is.
 *
 * The record is display and provenance only: what is cut always comes from the job's own
 * measurements. The Receipts app reads the colour a job was cut in (cut_color_for()) as a
 * recorded fact, when every piece of the plan was one colour.
 *
 * Stored in one non-autoloaded option, pruned by age and size.
 *
 * @package Zorderz\Prep
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZPREP_Plan_Preview {

	const OPTION  = 'zprep_plan_preview';
	const TTL     = 180 * 86400; // seconds
	const MAX     = 400;

	/* ================================================================
	 * PURE
	 * ================================================================ */

	/** The summary of a plan: total pieces and material area (sq ft) per colour. */
	public static function summarize( array $plan ): array {
		$area = array();
		foreach ( (array) ( $plan['pages'] ?? array() ) as $pg ) {
			if ( ! is_array( $pg ) ) {
				continue;
			}
			$c = strtolower( trim( (string) ( $pg['color'] ?? '' ) ) );
			$w = (float) ( $pg['roll_width_in'] ?? 0 );
			$l = (float) ( $pg['sheet_length'] ?? 0 );
			if ( '' === $c || $w <= 0 || $l <= 0 ) {
				continue;
			}
			$area[ $c ] = ( $area[ $c ] ?? 0.0 ) + ( $w * $l / 144.0 );
		}
		foreach ( $area as $c => $v ) {
			$area[ $c ] = round( $v, 1 );
		}
		ksort( $area );
		return array(
			'pieces' => (int) ( $plan['summary']['total_pieces'] ?? 0 ),
			'area'   => $area,
		);
	}

	/** The single colour of a summary, or '' for none / several. */
	public static function single_color( ?array $summary ): string {
		$area = array_filter( (array) ( $summary['area'] ?? array() ), static function ( $v ) { return (float) $v > 0; } );
		return 1 === count( $area ) ? (string) array_key_first( $area ) : '';
	}

	/** Add one plan to the map (keyed per job), pruning old and excess rows. */
	public static function put( array $map, string $name, string $cid, array $summary, string $card_ref, string $source_job, int $lead_id, int $now, string $cut_color = '' ): array {
		$name = trim( $name );
		if ( '' === $name ) {
			return $map;
		}
		$disc = '';
		if ( '' !== trim( $card_ref ) ) {
			$disc = 'c:' . trim( $card_ref );
		} elseif ( '' !== trim( $source_job ) ) {
			$disc = 's:' . trim( $source_job );
		} elseif ( $lead_id > 0 ) {
			$disc = 'l:' . $lead_id;
		}
		$sig = class_exists( 'ZPREP_Dashboard' ) ? ZPREP_Dashboard::norm_name( $name ) : strtolower( $name );
		$key = ( '' !== $sig ? $sig : strtolower( $name ) ) . ( '' !== $disc ? '|' . $disc : '' );
		$map[ $key ] = array(
			'customer'    => $name,
			'customer_id' => trim( $cid ),
			'summary'     => array( 'pieces' => (int) ( $summary['pieces'] ?? 0 ), 'area' => (array) ( $summary['area'] ?? array() ) ),
			'card_ref'    => trim( $card_ref ),
			'source_job'  => trim( $source_job ),
			'lead_id'     => $lead_id,
			'cut_color'   => strtolower( trim( $cut_color ) ),
			'at'          => $now,
		);
		if ( $now > 0 ) {
			foreach ( $map as $k => $row ) {
				if ( (int) ( $row['at'] ?? 0 ) < $now - self::TTL ) {
					unset( $map[ $k ] );
				}
			}
		}
		if ( count( $map ) > self::MAX ) {
			uasort( $map, static function ( $a, $b ) { return (int) ( $b['at'] ?? 0 ) <=> (int) ( $a['at'] ?? 0 ); } );
			$map = array_slice( $map, 0, self::MAX, true );
		}
		return $map;
	}

	/**
	 * The remembered row for one card, by the precedence in the class docblock, or null.
	 *
	 * @param callable $same fn( name_a, cid_a, name_b, cid_b ): bool — the same-customer rule.
	 */
	public static function pick_row( array $map, string $name, string $cid, string $ref, int $lead_id, int $siblings, callable $same ): ?array {
		$ref     = trim( $ref );
		$matches = array();
		foreach ( $map as $row ) {
			if ( is_array( $row ) && $same( $name, $cid, (string) ( $row['customer'] ?? '' ), (string) ( $row['customer_id'] ?? '' ) ) ) {
				$matches[] = $row;
			}
		}
		if ( empty( $matches ) ) {
			return null;
		}
		$latest = static function ( array $rows ): ?array {
			$best = null;
			foreach ( $rows as $r ) {
				if ( null === $best || (int) ( $r['at'] ?? 0 ) >= (int) ( $best['at'] ?? 0 ) ) {
					$best = $r;
				}
			}
			return $best;
		};
		if ( '' !== $ref || $lead_id > 0 ) {
			$own = array_filter( $matches, static function ( $r ) use ( $ref, $lead_id ) {
				return ( '' !== $ref && ( (string) ( $r['card_ref'] ?? '' ) === $ref || (string) ( $r['source_job'] ?? '' ) === $ref ) )
					|| ( $lead_id > 0 && (int) ( $r['lead_id'] ?? 0 ) === $lead_id );
			} );
			if ( $own ) {
				return $latest( $own );
			}
		}
		if ( $siblings > 1 ) {
			return null;
		}
		return $latest( $matches );
	}

	/** How many actionable queue cards are this customer's (same rule as the match). */
	public static function count_customer_jobs( array $jobs, string $name, string $cid, callable $same ): int {
		$n = 0;
		foreach ( $jobs as $j ) {
			if ( is_array( $j ) && empty( $j['fb_possible'] ) && 'remake' !== (string) ( $j['kind'] ?? '' )
				&& $same( $name, $cid, (string) ( $j['customer'] ?? '' ), (string) ( $j['customer_id'] ?? '' ) ) ) {
				$n++;
			}
		}
		return $n;
	}

	/* ================================================================
	 * STORAGE + CONSUMERS
	 * ================================================================ */

	private static function same(): callable {
		return array( 'ZPREP_Dashboard', 'same_customer' );
	}

	private static function map(): array {
		$m = get_option( self::OPTION, array() );
		return is_array( $m ) ? $m : array();
	}

	/** Remember a computed plan for one job. */
	public static function remember( string $customer, string $cid, array $plan, string $card_ref, string $source_job, int $lead_id ): void {
		$summary = self::summarize( $plan );
		if ( $summary['pieces'] <= 0 && empty( $summary['area'] ) ) {
			return;
		}
		$map = self::put( self::map(), $customer, $cid, $summary, $card_ref, $source_job, $lead_id, time(), self::single_color( $summary ) );
		update_option( self::OPTION, $map, false );
	}

	/** Add `plan_preview` ({pieces, area}) to each queue card that has its own remembered plan. */
	public static function annotate_jobs( array $jobs ): array {
		$map = self::map();
		if ( empty( $map ) ) {
			return $jobs;
		}
		foreach ( $jobs as &$j ) {
			if ( ! is_array( $j ) ) {
				continue;
			}
			$name = (string) ( $j['customer'] ?? '' );
			$cid  = (string) ( $j['customer_id'] ?? '' );
			if ( '' === trim( $name ) ) {
				continue;
			}
			$row = self::pick_row( $map, $name, $cid, (string) ( $j['estimate_number'] ?? '' ), (int) ( $j['lead_id'] ?? 0 ), self::count_customer_jobs( $jobs, $name, $cid, self::same() ), self::same() );
			if ( $row ) {
				$j['plan_preview'] = $row['summary'];
			}
		}
		unset( $j );
		return $jobs;
	}

	/**
	 * The colour a job was cut in, as recorded by Prep (every piece of its plan one colour),
	 * or ''. Matched like a card (own reference first); a customer's latest plan otherwise.
	 */
	public static function cut_color_for( string $name, string $cid, string $ref = '', int $lead_id = 0 ): string {
		if ( '' === trim( $name ) ) {
			return '';
		}
		$row = self::pick_row( self::map(), $name, $cid, $ref, $lead_id, 1, self::same() );
		return $row ? (string) ( $row['cut_color'] ?? '' ) : '';
	}
}
