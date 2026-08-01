<?php
/**
 * ZCC_Split — deterministic commission splitting for shared jobs.
 *
 * When an invoice lists more than one salesperson code, the job is shared. The
 * mechanism is Core; the per-rep policies come from ZDZ_Compensation:
 *
 *   1. The job produces ONE commission pool (COGS/discount already removed).
 *   2. The pool RATE belongs to the job. With reps of differing rates we pick
 *      one job rate by ZDZ_Compensation::split_settings()['pool_rate_policy']
 *      (default 'max' — nobody is shorted).
 *   3. pool = net_commissionable × (job_rate / 100).
 *   4. Each rep's SHARE is decided by their own plan's split policy
 *      (equal / weight / own_share / full). Shares are normalised so the sum
 *      never exceeds the pool; largest-remainder cent allocation.
 *
 * Pure: no DB writes, no network, no LLM. All money math is PHP.
 *
 * @package Zorderz\Commission
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZCC_Split {

	public static function is_shared( array $codes ): bool {
		return count( self::normalize_codes( $codes ) ) > 1;
	}

	/** Upper-case, trim, drop blanks, de-dupe preserving order. */
	public static function normalize_codes( array $codes ): array {
		$out = [];
		foreach ( $codes as $c ) {
			$c = strtoupper( trim( (string) $c ) );
			if ( $c !== '' && ! in_array( $c, $out, true ) ) {
				$out[] = $c;
			}
		}
		return $out;
	}

	private static function pool_rate_policy(): string {
		if ( class_exists( 'ZDZ_Compensation' ) ) {
			$s = ZDZ_Compensation::split_settings();
			return (string) ( $s['pool_rate_policy'] ?? 'max' );
		}
		return 'max';
	}

	/**
	 * Resolve the full split for a shared job.
	 *
	 * @param float    $net_commissionable Dollars to commission on (COGS already out).
	 * @param string[] $codes              Salesperson codes present on the invoice.
	 * @param int|null $target_party_id    If set, adds a 'target' convenience block.
	 * @return array pool_rate, pool_amount, shares[], unresolved[], warnings[], target
	 */
	public static function resolve( float $net_commissionable, array $codes, ?int $target_party_id = null ): array {
		$codes  = self::normalize_codes( $codes );
		$result = [
			'pool_rate'   => 0.0,
			'pool_amount' => 0.0,
			'shares'      => [],
			'unresolved'  => [],
			'warnings'    => [],
			'target'      => null,
		];

		if ( empty( $codes ) ) {
			$result['warnings'][] = 'No salesperson codes supplied; nothing to split.';
			return $result;
		}

		// 1. Resolve each code to a configured plan (via ZDZ_Compensation).
		$reps = [];
		foreach ( $codes as $code ) {
			$plan = class_exists( 'ZDZ_Compensation' ) ? ZDZ_Compensation::plan_by_code( $code ) : null;
			if ( $plan === null ) {
				$result['unresolved'][] = $code;
				continue;
			}
			$reps[] = [ 'code' => $code, 'plan' => $plan ];
		}
		if ( ! empty( $result['unresolved'] ) ) {
			$result['warnings'][] = 'No commission plan matches code(s): ' . implode( ', ', $result['unresolved'] ) . '. Excluded from the split.';
		}
		if ( empty( $reps ) ) {
			$result['warnings'][] = 'None of the invoice codes matched a configured plan; pool not distributed.';
			return $result;
		}

		// 2. One job rate.
		$rates = [];
		foreach ( $reps as $r ) {
			$rates[] = (float) ( $r['plan']['rate_percent'] ?? 0 );
		}
		$pool_rate = self::choose_pool_rate( $rates );
		$result['pool_rate'] = $pool_rate;

		// 3. Pool dollars.
		$pool = round( $net_commissionable * ( $pool_rate / 100 ), 2 );
		$result['pool_amount'] = $pool;

		// 4. Raw share per rep.
		$equal_fraction = 1.0 / count( $reps );
		$raw = [];
		foreach ( $reps as $i => $r ) {
			$raw[ $i ] = self::raw_share( $r['plan'], $equal_fraction );
		}

		// 5. Normalise (own_share literal vs relative weights).
		$all_own = true;
		foreach ( $reps as $r ) {
			if ( ( $r['plan']['split_policy'] ?? 'full' ) !== 'own_share' ) {
				$all_own = false;
				break;
			}
		}
		$fractions = [];
		if ( $all_own ) {
			$sum_pct = array_sum( $raw );
			if ( $sum_pct > 100.0 + 1e-9 ) {
				$result['warnings'][] = sprintf( 'Own-share percentages total %.2f%% (over 100%%); scaled down to fit the pool.', $sum_pct );
				foreach ( $raw as $i => $pct ) {
					$fractions[ $i ] = $sum_pct > 0 ? ( $pct / $sum_pct ) : 0.0;
				}
			} else {
				if ( $sum_pct < 100.0 - 1e-9 ) {
					$result['warnings'][] = sprintf( 'Own-share percentages total %.2f%%; remaining %.2f%% is unallocated (house).', $sum_pct, 100.0 - $sum_pct );
				}
				foreach ( $raw as $i => $pct ) {
					$fractions[ $i ] = $pct / 100.0;
				}
			}
		} else {
			$sum_raw = array_sum( $raw );
			if ( $sum_raw <= 0 ) {
				$result['warnings'][] = 'Split weights summed to zero; fell back to an equal split.';
				foreach ( $reps as $i => $r ) {
					$fractions[ $i ] = $equal_fraction;
				}
			} else {
				foreach ( $raw as $i => $w ) {
					$fractions[ $i ] = $w / $sum_raw;
				}
			}
		}

		// 6. Dollars, largest-remainder cent allocation.
		$amounts = self::allocate_cents( $pool, $fractions );
		foreach ( $reps as $i => $r ) {
			$policy = $r['plan']['split_policy'] ?? 'full';
			$row = [
				'code'              => $r['code'],
				'party_id'          => (int) ( $r['plan']['party_id'] ?? 0 ),
				'display_name'      => (string) ( $r['plan']['display_name'] ?? $r['code'] ),
				'policy'            => $policy,
				'share_fraction'    => round( $fractions[ $i ], 6 ),
				'commission_amount' => $amounts[ $i ],
			];
			$result['shares'][] = $row;
			if ( $target_party_id !== null && $row['party_id'] === (int) $target_party_id ) {
				$result['target'] = $row;
			}
		}
		if ( $target_party_id !== null && $result['target'] === null ) {
			$result['warnings'][] = 'Requested rep is not listed on this shared invoice; their share is $0.00.';
		}
		return $result;
	}

	private static function raw_share( array $plan, float $equal_fraction ): float {
		switch ( $plan['split_policy'] ?? 'full' ) {
			case 'weight':
				return max( 0.0, (float) ( $plan['split_weight'] ?? 1 ) );
			case 'own_share':
				return max( 0.0, min( 100.0, (float) ( $plan['split_own_share'] ?? 0 ) ) );
			case 'full':    // a full-rate rep on a shared job is pooled equally (documented resolution)
			case 'equal':
			default:
				return $equal_fraction;
		}
	}

	private static function choose_pool_rate( array $rates ): float {
		$rates = array_map( 'floatval', $rates );
		if ( empty( $rates ) ) {
			return 0.0;
		}
		switch ( self::pool_rate_policy() ) {
			case 'min':     return (float) min( $rates );
			case 'average': return (float) ( array_sum( $rates ) / count( $rates ) );
			case 'first':   return (float) reset( $rates );
			case 'max':
			default:        return (float) max( $rates );
		}
	}

	/** Largest-remainder allocation so per-rep cents sum back to the pool. */
	private static function allocate_cents( float $pool, array $fractions ): array {
		$pool_cents   = (int) round( $pool * 100 );
		$allocated    = array_sum( $fractions );
		$target_cents = (int) round( $pool_cents * $allocated );
		$floors       = [];
		$remainders   = [];
		$running      = 0;
		foreach ( $fractions as $i => $f ) {
			$exact          = $pool_cents * $f;
			$floor          = (int) floor( $exact );
			$floors[ $i ]     = $floor;
			$remainders[ $i ] = $exact - $floor;
			$running         += $floor;
		}
		$leftover = $target_cents - $running;
		if ( $leftover > 0 ) {
			arsort( $remainders );
			foreach ( array_keys( $remainders ) as $i ) {
				if ( $leftover <= 0 ) {
					break;
				}
				$floors[ $i ] += 1;
				$leftover     -= 1;
			}
		}
		$out = [];
		foreach ( $floors as $i => $cents ) {
			$out[ $i ] = round( $cents / 100, 2 );
		}
		return $out;
	}
}
