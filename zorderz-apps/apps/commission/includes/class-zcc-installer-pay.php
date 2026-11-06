<?php
/**
 * ZCC_Installer_Pay — piece-rate payroll for shop/bench work.
 *
 * A self-contained payroll add-on that pays a fixed price per unit produced. It
 * NEVER touches commissions: it reads invoices and reports what a piece worker
 * is owed. Installer labour is intentionally not deducted from invoice net.
 *
 * ── THE PARITY CONTRACT ──
 * Counts and rates BOTH key on the SAME Item Engine item id:
 *   - COUNTS come from ZCC_Classifier (Item Engine classification) → item id.
 *   - RATES come from ZDZ_Compensation::piece_rates() → item id.
 * compute_pay() joins them on item id. If a counted item has no configured rate
 * (or the two drift apart), pay is NOT silently $0 — a LOUD disposition is
 * raised (ZCC_Audit::log) and the miss is returned in `missing_rates`. This is
 * the bug the "?? 0" fallback used to hide: a kind present in counts but absent
 * from rates would zero a paycheck in silence. ZCC_Self_Test replays a synthetic
 * ledger to prove both halves stay consistent.
 *
 * Counts are also emitted in the Item Engine's item_keyed_v2 shape for analytics.
 *
 * ── WHO IS PAID ──
 * A paycheck counts only the jobs attributed to that person (ZCC_Attribution, role
 * 'install'): the job's recorded installer (the Core `per_job` mode), or — when a business
 * sets the company-wide piece-rate mode — the one configured piece worker. A job with no
 * installer, or more than one without saying whose items are whose, is a review item and pays
 * nobody. When Team Roles is in use (at least one mark exists), units of an item the installer
 * is not marked "Can" install are held for review too; with no marks at all the check is
 * skipped and the answer says so (`team_roles`, `team_roles_note`). The company-wide tally
 * (count_units with 'ALL') is a count for analytics, never a paycheck.
 *
 * @package Zorderz\Commission
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZCC_Installer_Pay {

	/**
	 * The set of item ids that earn a piece rate. The rate table is authoritative
	 * ("what earns a piece rate"); when the Item Engine also flags items
	 * `attributes.bench_payable`, those are included too. Empty out of the box.
	 *
	 * @return string[] item ids
	 */
	public static function payable_item_ids(): array {
		$ids = [];
		if ( class_exists( 'ZDZ_Compensation' ) ) {
			$ids = array_keys( ZDZ_Compensation::piece_rates() );
		}
		global $wpdb;
		if ( is_object( $wpdb ) && class_exists( 'ZDZ_Item_Engine' ) && method_exists( 'ZDZ_Item_Engine', 'all' ) ) {
			foreach ( (array) ZDZ_Item_Engine::all( [ 'attributes' => [ 'bench_payable' => true ] ] ) as $item ) {
				$id = (string) ( $item['id'] ?? '' );
				if ( $id !== '' && ! in_array( $id, $ids, true ) ) {
					$ids[] = $id;
				}
			}
		}
		return $ids;
	}

	/**
	 * Count payable shop units in a date window.
	 *
	 * @param string $code       Attribution code to filter by, or '' / 'ALL' / '*' for company-wide.
	 * @param string $date_start YYYY-MM-DD
	 * @param string $date_end   YYYY-MM-DD
	 * @param array|string|null $status
	 * @return array {
	 *   counts: { item_id => int }, counts_v2: item_keyed_v2 envelope,
	 *   units_total:int, unclassified_lines:int, unclassified_amount:float,
	 *   invoice_count:int, invoices:array, errors:array
	 * }
	 */
	public static function count_units( string $code, string $date_start, string $date_end, $status = null ): array {
		$code         = strtoupper( trim( $code ) );
		$company_wide = ( $code === '' || $code === 'ALL' || $code === '*' );
		$payable      = self::payable_item_ids();

		$out = [
			'code'                => $company_wide ? 'ALL' : $code,
			'company_wide'        => $company_wide,
			'date_start'          => $date_start,
			'date_end'            => $date_end,
			'counts'              => [],
			'units_total'         => 0,
			'unclassified_lines'  => 0,
			'unclassified_amount' => 0.0,
			'invoice_count'       => 0,
			'invoices'            => [],
			'errors'              => [],
		];

		if ( ! class_exists( 'ZCC_FreshBooks' ) || ! ZCC_FreshBooks::is_connected() ) {
			$out['errors'][] = 'FreshBooks is not connected.';
			return $out;
		}

		try {
			$invoices = ZCC_FreshBooks::get_invoices( $date_start, $date_end, $status );
		} catch ( \Throwable $e ) {
			$out['errors'][] = 'Invoice fetch failed: ' . $e->getMessage();
			return $out;
		}

		$counts = [];
		foreach ( $invoices as $inv ) {
			if ( ! $company_wide ) {
				$codes = array_map( 'strtoupper', (array) ( $inv['salesperson_codes'] ?? [] ) );
				if ( ! in_array( $code, $codes, true ) ) {
					continue;
				}
			}
			$inv_counts       = [];
			$inv_unclassified = 0;
			$inv_unclass_amt  = 0.0;
			foreach ( (array) ( $inv['lines'] ?? [] ) as $line ) {
				$cls     = ZCC_Classifier::classify_line( $line );
				$item_id = (string) ( $cls['item_id'] ?? '' );
				$qty     = max( 0, (int) ( $cls['quantity'] ?? 0 ) );
				if ( $item_id !== '' && in_array( $item_id, $payable, true ) && $qty > 0 ) {
					$counts[ $item_id ]     = ( $counts[ $item_id ] ?? 0 ) + $qty;
					$inv_counts[ $item_id ] = ( $inv_counts[ $item_id ] ?? 0 ) + $qty;
				} elseif ( $item_id === '' && abs( (float) ( $line['amount'] ?? 0 ) ) > 0.005 ) {
					$inv_unclassified++;
					$inv_unclass_amt += (float) ( $line['amount'] ?? 0 );
				}
			}
			$out['unclassified_lines']  += $inv_unclassified;
			$out['unclassified_amount'] += $inv_unclass_amt;
			$out['invoice_count']++;
			if ( count( $out['invoices'] ) < 500 ) {
				$out['invoices'][] = [
					'invoice_number' => $inv['invoice_number'] ?? '',
					'customer_name'  => $inv['customer_name'] ?? '',
					'date'           => $inv['date_completed'] ?? '',
					'counts'         => $inv_counts,
					'units'          => array_sum( $inv_counts ),
					'unclassified'   => $inv_unclassified,
				];
			}
		}

		$out['counts']              = $counts;
		$out['units_total']         = array_sum( $counts );
		$out['unclassified_amount'] = round( $out['unclassified_amount'], 2 );
		$out['counts_v2']           = self::to_item_keyed_v2( $counts );
		return $out;
	}

	/**
	 * Build the Item Engine item_keyed_v2 envelope from a flat item_id=>int map,
	 * so analytics branches on the shape discriminator, never by probing keys.
	 */
	public static function to_item_keyed_v2( array $counts ): array {
		global $wpdb;
		if ( is_object( $wpdb ) && class_exists( 'ZDZ_Item_Engine' ) && method_exists( 'ZDZ_Item_Engine', 'new_counts' ) ) {
			$env = ZDZ_Item_Engine::new_counts( array_keys( $counts ) );
			foreach ( $counts as $item_id => $n ) {
				$env = ZDZ_Item_Engine::add_count( $env, $item_id, (int) $n );
			}
			return $env;
		}
		// Neutral fallback when the engine is absent — still carries the discriminator.
		return [
			'shape'              => 'item_keyed_v2',
			'counts'             => array_map( 'intval', $counts ),
			'counts_meta'        => [],
			'requested_item_ids' => array_keys( $counts ),
		];
	}

	/**
	 * Apply configured piece rates to counts and return a paycheck breakdown.
	 *
	 * THE JOIN. Iterates the UNION of count keys and rate keys, joining on item
	 * id. A counted item with no configured rate is a LOUD disposition, never a
	 * silent $0. `missing_rates` carries the offending item ids for the caller.
	 *
	 * @param array      $counts item_id => int
	 * @param array|null $rates  Optional override: item_id => { rate, unit }.
	 * @return array { lines, units_total, total_pay, missing_rates, dispositions }
	 */
	public static function compute_pay( array $counts, ?array $rates = null ): array {
		$rates = is_array( $rates ) ? $rates : ( class_exists( 'ZDZ_Compensation' ) ? ZDZ_Compensation::piece_rates() : [] );

		$lines         = [];
		$total         = 0.0;
		$units         = 0;
		$missing_rates = [];
		$dispositions  = [];

		// The union: every item that was counted OR has a rate. This is what keeps
		// counts and rates from drifting silently — a key in one but not the other
		// is surfaced, not swallowed.
		$item_ids = array_values( array_unique( array_merge( array_keys( $counts ), array_keys( $rates ) ) ) );

		foreach ( $item_ids as $item_id ) {
			$qty      = max( 0, (int) ( $counts[ $item_id ] ?? 0 ) );
			$has_rate = isset( $rates[ $item_id ] );
			$rate     = $has_rate ? max( 0.0, (float) ( $rates[ $item_id ]['rate'] ?? 0 ) ) : null;

			if ( $qty > 0 && ! $has_rate ) {
				// COUNTED but UNPAYABLE — the exact silent-$0 bug. Fail loudly.
				$missing_rates[] = $item_id;
				$disp = [
					'item_id' => $item_id,
					'qty'     => $qty,
					'reason'  => sprintf( 'Counted %d unit(s) of "%s" but NO piece rate is configured — pay is UNRESOLVED, not $0. Configure a rate or exclude the item.', $qty, $item_id ),
				];
				$dispositions[] = $disp;
				if ( class_exists( 'ZCC_Audit' ) ) {
					ZCC_Audit::log( 'piece_rate_missing', $disp );
				}
				$lines[ $item_id ] = [ 'item_id' => $item_id, 'label' => self::item_label( $item_id ), 'qty' => $qty, 'rate' => null, 'pay' => null, 'unresolved' => true ];
				$units += $qty;
				continue;
			}

			$pay               = round( $qty * (float) $rate, 2 );
			$lines[ $item_id ] = [ 'item_id' => $item_id, 'label' => self::item_label( $item_id ), 'qty' => $qty, 'rate' => round( (float) $rate, 2 ), 'pay' => $pay, 'unresolved' => false ];
			$total            += $pay;
			$units            += $qty;
		}

		return [
			'lines'         => $lines,
			'units_total'   => $units,
			'total_pay'     => round( $total, 2 ),
			'rates'         => $rates,
			'missing_rates' => $missing_rates,
			'dispositions'  => $dispositions,
			// A caller can assert on this: any unresolved line means the paycheck
			// is NOT trustworthy and must be fixed before payout.
			'resolved'      => empty( $missing_rates ),
		];
	}

	/** Count a window AND compute pay in one call (company-wide or by code: a tally, see count_units). */
	public static function run_paycheck( string $code, string $date_start, string $date_end, $status = null ): array {
		$result             = self::count_units( $code, $date_start, $date_end, $status );
		$result['paycheck'] = self::compute_pay( $result['counts'] );
		$ucode              = strtoupper( trim( $code ) );
		$result['installer_label'] = ( $ucode === '' || $ucode === 'ALL' || $ucode === '*' ) ? __( 'Shop total (all fabrication)', 'zorderz' ) : $ucode;
		return $result;
	}

	/**
	 * One person's piece-rate units in a window: only the jobs attributed to them.
	 *
	 * @return array { party_id, counts, counts_v2, units_total, invoice_count, invoices,
	 *   review (rows for ZCC_Review), withheld (item_id => units held for review), errors }
	 */
	public static function count_units_for_party( int $party_id, string $date_start, string $date_end, $status = null ): array {
		$out = [
			'party_id'      => $party_id,
			'date_start'    => $date_start,
			'date_end'      => $date_end,
			'counts'        => [],
			'units_total'   => 0,
			'invoice_count' => 0,
			'invoices'      => [],
			'review'        => [],
			'withheld'      => [],
			'errors'        => [],
		];
		if ( $party_id <= 0 ) {
			$out['errors'][] = 'no_person';
			return $out;
		}
		if ( ! class_exists( 'ZCC_FreshBooks' ) || ! ZCC_FreshBooks::is_connected() ) {
			$out['errors'][] = 'FreshBooks is not connected.';
			return $out;
		}
		$invoices = ZCC_FreshBooks::get_invoices( $date_start, $date_end, $status );
		if ( ! $invoices && '' !== ZCC_FreshBooks::last_error() ) {
			$out['errors'][] = 'Invoices could not be loaded just now.';
			return $out;
		}
		return self::tally_for_party( $party_id, $invoices, $out );
	}

	/** The pure part of count_units_for_party(): attribution, Team Roles and counting over given invoices. */
	public static function tally_for_party( int $party_id, array $invoices, array $out = [] ): array {
		$out += [ 'party_id' => $party_id, 'counts' => [], 'units_total' => 0, 'invoice_count' => 0, 'invoices' => [], 'review' => [], 'withheld' => [], 'errors' => [] ];
		$payable = self::payable_item_ids();
		$counts  = [];
		$roles   = self::team_roles_check();
		$out['team_roles'] = $roles;
		if ( 'unmarked' === $roles ) {
			$out['team_roles_note'] = __( 'Nobody is marked in Team Roles yet, so this pay is not checked against who can install each item.', 'zorderz' );
		}
		foreach ( $invoices as $inv ) {
			if ( ! is_array( $inv ) ) {
				continue;
			}
			$res = ZCC_Attribution::resolve( $inv, 'install' );
			if ( in_array( $res['status'], [ 'none', 'ambiguous' ], true ) ) {
				if ( self::has_payable_units( $inv, $payable ) ) {
					$out['review'][] = ZCC_Attribution::review_row( $inv, $res );
				}
				continue;
			}
			if ( ! ZCC_Attribution::pays( $res, $party_id ) ) {
				continue;
			}
			$mine       = $res['items'][ $party_id ] ?? null; // null: every item on the job
			$inv_counts = [];
			foreach ( (array) ( $inv['lines'] ?? [] ) as $line ) {
				$cls     = ZCC_Classifier::classify_line( $line );
				$item_id = (string) ( $cls['item_id'] ?? '' );
				$qty     = max( 0, (int) ( $cls['quantity'] ?? 0 ) );
				if ( $item_id === '' || $qty <= 0 || ! in_array( $item_id, $payable, true ) ) {
					continue;
				}
				if ( is_array( $mine ) && ! in_array( $item_id, $mine, true ) ) {
					continue;
				}
				if ( 'unmarked' !== $roles && ! self::marked_can_install( $party_id, $item_id ) ) {
					$out['withheld'][ $item_id ] = ( $out['withheld'][ $item_id ] ?? 0 ) + $qty;
					$out['review'][] = [
						'invoice_id'     => (int) ( $inv['invoice_id'] ?? 0 ),
						'invoice_number' => (string) ( $inv['invoice_number'] ?? '' ),
						'role'           => 'install',
						'status'         => 'held',
						'reason'         => 'not_marked',
						'people'         => [ $party_id ],
						'codes'          => [],
						'item_id'        => $item_id,
						'units'          => $qty,
					];
					continue;
				}
				$counts[ $item_id ]     = ( $counts[ $item_id ] ?? 0 ) + $qty;
				$inv_counts[ $item_id ] = ( $inv_counts[ $item_id ] ?? 0 ) + $qty;
			}
			if ( ! $inv_counts ) {
				continue;
			}
			$out['invoice_count']++;
			if ( count( $out['invoices'] ) < 500 ) {
				$out['invoices'][] = [
					'invoice_number' => (string) ( $inv['invoice_number'] ?? '' ),
					'date'           => (string) ( $inv['date_completed'] ?? '' ),
					'counts'         => $inv_counts,
					'units'          => array_sum( $inv_counts ),
				];
			}
		}
		$out['counts']      = $counts;
		$out['units_total'] = array_sum( $counts );
		$out['counts_v2']   = self::to_item_keyed_v2( $counts );
		return $out;
	}

	/** One person's paycheck for a window: their attributed units × the configured rates. */
	public static function run_paycheck_for_party( int $party_id, string $date_start, string $date_end, $status = null ): array {
		$result             = self::count_units_for_party( $party_id, $date_start, $date_end, $status );
		$result['paycheck'] = self::compute_pay( $result['counts'] );
		if ( ! empty( $result['review'] ) && class_exists( 'ZCC_Review' ) ) {
			ZCC_Review::note( $result['review'] );
		}
		return $result;
	}

	/**
	 * Does Team Roles decide piece pay here?
	 *
	 *   'on'       Team Roles is in use: at least one mark exists. Every unit is checked.
	 *   'unmarked' Team Roles is active and nobody has been marked yet. The check is skipped
	 *              (it would withhold every unit) and the pay screen says so.
	 *   'off'      Team Roles does not say (not installed). Units are still asked of
	 *              `zdz_person_can_do`, which passes when nothing answers.
	 *
	 * Team Roles answers `zdz_team_roles_in_use` with true or false.
	 */
	public static function team_roles_check(): string {
		$in_use = apply_filters( 'zdz_team_roles_in_use', null );
		if ( null === $in_use ) {
			return 'off';
		}
		return $in_use ? 'on' : 'unmarked';
	}

	/**
	 * Is this person marked able to install this item? Asked of Team Roles when it is active
	 * (`zdz_person_can_do`); with no Team Roles answer there is nothing to check.
	 */
	public static function marked_can_install( int $party_id, string $item_id ): bool {
		$ans = apply_filters( 'zdz_person_can_do', null, $party_id, $item_id, 'install' );
		if ( null === $ans ) {
			return true;
		}
		if ( is_array( $ans ) ) {
			return ! empty( $ans['can'] );
		}
		return (bool) $ans;
	}

	private static function has_payable_units( array $inv, array $payable ): bool {
		foreach ( (array) ( $inv['lines'] ?? [] ) as $line ) {
			$cls = ZCC_Classifier::classify_line( $line );
			if ( in_array( (string) ( $cls['item_id'] ?? '' ), $payable, true ) ) {
				return true;
			}
		}
		return false;
	}

	/** Friendly label for an item id (from the Item Engine; humanized fallback). */
	private static function item_label( string $item_id ): string {
		$item = zcc_item_get( $item_id );
		if ( is_array( $item ) && ! empty( $item['name'] ) ) {
			return (string) $item['name'];
		}
		return ucwords( str_replace( [ '-', '_' ], ' ', $item_id ) );
	}
}
