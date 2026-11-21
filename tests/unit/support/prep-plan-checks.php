<?php
/**
 * Checks on a Prep cut plan, shared by the planner and stock-sheet tests: how many pieces its bench sheets hold, and
 * whether every piece lies on its stock with no two overlapping. And made-up jobs to run them on.
 *
 * @package Zorderz\Tests
 */

if ( ! function_exists( 'zprepio_plan_placed' ) ) {
	/** Pieces on the bench sheets of a plan (continuous pulls are not counted). */
	function zprepio_plan_placed( array $plan ): int {
		$n = 0;
		foreach ( (array) ( $plan['pages'] ?? array() ) as $pg ) {
			if ( empty( $pg['compressed'] ) ) {
				$n += count( (array) ( $pg['pieces'] ?? array() ) );
			}
		}
		return $n;
	}
}

if ( ! function_exists( 'zprepio_plan_off_stock' ) ) {
	/** '' when every piece lies inside its stock (x across the width, y along the length) and no two overlap. */
	function zprepio_plan_off_stock( array $plan ): string {
		foreach ( (array) ( $plan['pages'] ?? array() ) as $i => $pg ) {
			if ( ! empty( $pg['compressed'] ) ) {
				continue;
			}
			$across = $pg['roll_width_in'] - $pg['grungy_margin'];
			$along  = $pg['sheet_length'];
			$ps     = $pg['pieces'];
			foreach ( $ps as $a => $p ) {
				if ( $p['x'] < -1e-6 || $p['y'] < -1e-6 || $p['x'] + $p['w'] > $across + 1e-6 || $p['y'] + $p['h'] > $along + 1e-6 ) {
					return "page {$i}: a piece runs off the stock";
				}
				for ( $b = $a + 1, $n = count( $ps ); $b < $n; $b++ ) {
					$o = $ps[ $b ];
					if ( $p['x'] < $o['x'] + $o['w'] - 1e-6 && $o['x'] < $p['x'] + $p['w'] - 1e-6 && $p['y'] < $o['y'] + $o['h'] - 1e-6 && $o['y'] < $p['y'] + $p['h'] - 1e-6 ) {
						return "page {$i}: two pieces overlap";
					}
				}
			}
		}
		return '';
	}
}

if ( ! function_exists( 'zprepio_made_up_jobs' ) ) {
	/**
	 * $count made-up jobs, each one to three lines of rectangles, the same on every run. The numbers come from a
	 * small generator kept here (the planner seeds PHP's own for its trials, so that one cannot be shared).
	 * $takes( $w, $h ) says which sizes belong in a job.
	 *
	 * @return array<int,array{0:array,1:int}> each job's measurements and how many pieces it asks for.
	 */
	function zprepio_made_up_jobs( int $count, callable $takes, string $color = 'grey' ): array {
		$x    = 88172645;
		$next = static function ( int $lo, int $hi ) use ( &$x ): int {
			$x ^= ( $x << 13 ) & 0xFFFFFFFF;
			$x ^= $x >> 17;
			$x ^= ( $x << 5 ) & 0xFFFFFFFF;
			return $lo + ( $x % ( $hi - $lo + 1 ) );
		};
		$jobs = array();
		for ( $tries = 0; count( $jobs ) < $count && $tries < $count * 20; $tries++ ) {
			$m     = array();
			$asked = 0;
			for ( $lines = $next( 1, 3 ); $lines > 0; $lines-- ) {
				$qty = $next( 1, 18 );
				$w   = $next( 6, 52 ) / 2; // 3 to 26 inches, by the half inch
				$h   = $next( 6, 92 ) / 2; // 3 to 46 inches
				if ( ! $takes( $w, $h ) ) {
					continue;
				}
				$m[]    = array( 'kind' => 'custom', 'qty' => $qty, 'width_in' => $w, 'height_in' => $h, 'shape' => 'rectangle', 'color' => $color );
				$asked += $qty;
			}
			if ( $m ) {
				$jobs[] = array( $m, $asked );
			}
		}
		return $jobs;
	}
}
