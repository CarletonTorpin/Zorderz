<?php
/**
 * ZCC_Self_Test — the counts × rates PARITY self-test.
 *
 * The most dangerous failure mode in this app is the counts/rates join drifting
 * apart: a kind present in the count table but absent from the rate table would,
 * under the old "?? 0" fallback, zero a paycheck IN SILENCE. This test replays a
 * small SYNTHETIC ledger (no DB, no FreshBooks, no real data) and asserts:
 *
 *   1. When BOTH counts and rates are configured, pay is NON-ZERO and CORRECT.
 *   2. When a counted kind has NO rate, the result is a LOUD disposition — the
 *      line is `unresolved`, `resolved` is false, and `missing_rates` names it —
 *      NEVER a silent $0. The rated kinds on the same run still pay correctly.
 *   3. A rate with no count is harmless (pays $0 for that item, run stays
 *      resolved) — so adding a rate never breaks a run.
 *
 * Runnable on demand: ZCC_Self_Test::run(), the admin "Run self-test" action, or
 * GET {ZDZ_REST_NS}/commission/self-test (admin only).
 *
 * @package Zorderz\Commission
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZCC_Self_Test {

	/**
	 * Run the parity self-test.
	 *
	 * @return array { passed:bool, results:array<int,array{name:string,ok:bool,detail:string}> }
	 */
	public static function run(): array {
		$results = [];

		// Synthetic catalog identities + rates (never touches the real Item Engine
		// or Compensation store — this proves the JOIN in isolation).
		$rates_full = [
			'kind-a' => [ 'rate' => 10.00, 'unit' => 'per_item' ],
			'kind-b' => [ 'rate' => 5.00,  'unit' => 'per_item' ],
		];

		// ── Test 1: both configured ⇒ non-zero, exact pay ──
		$pay = ZCC_Installer_Pay::compute_pay( [ 'kind-a' => 3, 'kind-b' => 2 ], $rates_full );
		$expected = round( 3 * 10.00 + 2 * 5.00, 2 ); // 40.00
		$ok1 = ( abs( $pay['total_pay'] - $expected ) < 0.005 )
			&& $pay['total_pay'] > 0
			&& ! empty( $pay['resolved'] )
			&& empty( $pay['missing_rates'] );
		$results[] = [
			'name'   => 'both configured ⇒ correct non-zero pay',
			'ok'     => $ok1,
			'detail' => sprintf( 'total_pay=%.2f (expected %.2f), resolved=%s, missing=%d', $pay['total_pay'], $expected, ! empty( $pay['resolved'] ) ? 'true' : 'false', count( $pay['missing_rates'] ) ),
		];

		// ── Test 2: a counted kind with NO rate ⇒ LOUD, never silent $0 ──
		$rates_partial = [ 'kind-a' => [ 'rate' => 10.00, 'unit' => 'per_item' ] ]; // kind-b rate missing
		$pay2 = ZCC_Installer_Pay::compute_pay( [ 'kind-a' => 3, 'kind-b' => 2 ], $rates_partial );
		$kind_a_line = $pay2['lines']['kind-a'] ?? [];
		$kind_b_line = $pay2['lines']['kind-b'] ?? [];
		$ok2 = in_array( 'kind-b', $pay2['missing_rates'], true )          // named
			&& empty( $pay2['resolved'] )                                  // run flagged not-trustworthy
			&& ! empty( $pay2['dispositions'] )                            // disposition raised
			&& ! empty( $kind_b_line['unresolved'] )                       // the line is unresolved
			&& array_key_exists( 'pay', $kind_b_line )                     // the line exists…
			&& $kind_b_line['pay'] === null                                // …with pay NULL, NOT a silent $0
			&& abs( ( $kind_a_line['pay'] ?? 0 ) - 30.00 ) < 0.005;        // the rated kind still pays
		$results[] = [
			'name'   => 'missing rate ⇒ loud disposition, never silent $0',
			'ok'     => $ok2,
			'detail' => sprintf( 'missing=%s, resolved=%s, dispositions=%d, kind-b.pay=%s, kind-a.pay=%.2f',
				implode( ',', $pay2['missing_rates'] ), ! empty( $pay2['resolved'] ) ? 'true' : 'false',
				count( $pay2['dispositions'] ), var_export( $kind_b_line['pay'] ?? null, true ), $kind_a_line['pay'] ?? 0 ),
		];

		// ── Test 3: a rate with no count ⇒ harmless (0 for it, run stays resolved) ──
		$pay3 = ZCC_Installer_Pay::compute_pay( [ 'kind-a' => 1 ], $rates_full );
		$kb3  = $pay3['lines']['kind-b'] ?? [];
		$ok3  = ! empty( $pay3['resolved'] )
			&& empty( $pay3['missing_rates'] )
			&& abs( ( $kb3['pay'] ?? -1 ) - 0.00 ) < 0.005
			&& abs( $pay3['total_pay'] - 10.00 ) < 0.005;
		$results[] = [
			'name'   => 'rate with no count ⇒ harmless zero, run resolved',
			'ok'     => $ok3,
			'detail' => sprintf( 'total_pay=%.2f, resolved=%s, missing=%d', $pay3['total_pay'], ! empty( $pay3['resolved'] ) ? 'true' : 'false', count( $pay3['missing_rates'] ) ),
		];

		// ── Test 4: the item_keyed_v2 envelope carries the shape discriminator ──
		$env = ZCC_Installer_Pay::to_item_keyed_v2( [ 'kind-a' => 3 ] );
		$ok4 = ( ( $env['shape'] ?? '' ) === 'item_keyed_v2' )
			&& is_array( $env['counts'] ?? null )
			&& ( (int) ( $env['counts']['kind-a'] ?? 0 ) === 3 );
		$results[] = [
			'name'   => 'counts payload is item_keyed_v2 (scalar-only)',
			'ok'     => $ok4,
			'detail' => 'shape=' . ( $env['shape'] ?? '(none)' ),
		];

		$passed = true;
		foreach ( $results as $r ) {
			$passed = $passed && $r['ok'];
		}
		if ( ! $passed ) {
			error_log( 'ZCC_Self_Test: PARITY SELF-TEST FAILED — ' . wp_json_encode( $results ) );
		}
		return [ 'passed' => $passed, 'results' => $results ];
	}
}
