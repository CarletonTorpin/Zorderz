<?php
/**
 * ZCC_Calc_Engine — deterministic commission calculation.
 *
 * The math core. Every dollar flows through here. ZERO LLM. ZERO randomness.
 * Same inputs always produce the same outputs, to the penny.
 *
 * Everything it needs is read from services, not hardcoded:
 *   - the plan (structure, rate, tiers, split policy, card-fee handling) from
 *     ZDZ_Compensation::get_plan();
 *   - line classification from ZCC_Classifier (Item Engine + ledger kinds);
 *   - COGS from ZCC_Cost_Book (Item Engine);
 *   - product-minimum rules from ZDZ_Compensation::product_minimums();
 *   - the payability gate + attribution contract from ZDZ_Compensation.
 *
 * SAFETY: an UNCONFIGURED plan is never paid at a default rate — it computes $0
 * and raises a loud disposition (Playbook §7). A ledger-kind line (refund, fee,
 * …) is booked non-commissionable per its flags. An unresolvable share on a
 * shared job pays zero-and-flags.
 *
 * @package Zorderz\Commission
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZCC_Calc_Engine {

	/**
	 * Calculate commissions for a set of invoices for one party.
	 *
	 * @return array { invoices, summary, plan, flags, ledger_stats, period_key }
	 */
	public static function calculate( array $invoices, int $party_id, string $period_key = '' ): array {
		$plan  = class_exists( 'ZDZ_Compensation' ) ? ZDZ_Compensation::get_plan( $party_id ) : [ 'configured' => false ];
		$flags = [];

		if ( empty( $plan['configured'] ) ) {
			// Nothing silent: surface the missing plan, pay nothing.
			ZCC_Audit::log( 'unconfigured_plan', [ 'party_id' => $party_id ] );
			$flags[] = [ 'reason' => 'No compensation plan is configured for this party — $0 computed. Configure a plan in Compensation settings.', 'severity' => 'warning' ];
		}

		if ( $period_key === '' ) {
			$period_key = current_time( 'Y-m' ); // WP timezone
		}

		usort( $invoices, function ( $a, $b ) {
			return strcmp( $a['date_completed'] ?? '', $b['date_completed'] ?? '' );
		} );

		$finalized_ids = class_exists( 'ZCC_Ledger' ) ? ZCC_Ledger::get_finalized_invoice_ids( $party_id, $period_key ) : [];
		$processed     = [];
		$running_total = 0.0;
		$ledger_stats  = [ 'inserted' => 0, 'updated' => 0, 'locked' => 0, 'skipped' => 0 ];

		// Finalized rows contribute to the tiered running total at their locked value.
		if ( class_exists( 'ZCC_Ledger' ) && $finalized_ids ) {
			foreach ( ZCC_Ledger::get_period( $party_id, $period_key ) as $le ) {
				if ( ( $le['status'] ?? '' ) !== 'finalized' ) {
					continue;
				}
				$processed[] = [
					'invoice_number'    => $le['invoice_number'],
					'invoice_id'        => (int) $le['invoice_id'],
					'customer_name'     => $le['customer_name'],
					'date_completed'    => $le['date_completed'],
					'lines'             => json_decode( $le['detail_json'] ?? '[]', true ) ?: [],
					'gross_billed'      => (float) $le['gross_billed'],
					'total_cogs'        => (float) $le['total_cogs'],
					'net_commissionable'=> (float) $le['net_commissionable'],
					'commission_amount' => (float) $le['commission_amount'],
					'ledger_status'     => 'finalized',
				];
				$running_total += ( isset( $le['net_attributed'] ) && $le['net_attributed'] !== null && $le['net_attributed'] !== '' )
					? (float) $le['net_attributed'] : (float) $le['net_commissionable'];
			}
		}

		foreach ( $invoices as $inv ) {
			$inv_id = (int) ( $inv['invoice_id'] ?? 0 );
			if ( in_array( $inv_id, $finalized_ids, true ) ) {
				$ledger_stats['locked']++;
				continue;
			}
			// Payability re-checked at fetch; belt-and-suspenders here too.
			if ( ! self::is_payable_status( $inv ) ) {
				continue;
			}
			$result                  = self::process_invoice( $inv, $plan, $running_total, $party_id );
			$result['ledger_status'] = 'draft';
			$processed[]             = $result;
			$running_total          += $result['net_attributed'] ?? $result['net_commissionable'];
			if ( ! empty( $result['flags'] ) ) {
				$flags = array_merge( $flags, $result['flags'] );
			}
			if ( class_exists( 'ZCC_Ledger' ) && $inv_id ) {
				$action = ZCC_Ledger::upsert_entry( $party_id, $period_key, $result );
				if ( isset( $ledger_stats[ $action ] ) ) {
					$ledger_stats[ $action ]++;
				}
			}
		}

		usort( $processed, function ( $a, $b ) {
			return strcmp( $a['date_completed'] ?? '', $b['date_completed'] ?? '' );
		} );

		return [
			'invoices'     => $processed,
			'summary'      => self::build_summary( $processed, $plan ),
			'plan'         => $plan,
			'flags'        => $flags,
			'ledger_stats' => $ledger_stats,
			'period_key'   => $period_key,
		];
	}

	/** Process one invoice through classify → COGS → net → commission → minimum. */
	private static function process_invoice( array $inv, array $plan, float $running_total, int $target_party_id = 0 ): array {
		$lines           = $inv['lines'] ?? [];
		$discount_amount = (float) ( $inv['discount_amount'] ?? 0 );
		$cc_fee          = 0.0;
		$flags           = [];

		$classifications = ZCC_Classifier::classify_lines( $lines );

		$processed_lines = [];
		$gross_billed    = 0.0;
		$total_cogs      = 0.0;
		// Per-subtype net accumulators, for the generalized product-minimum rule.
		$subtype_gross = [];
		$subtype_net   = [];

		foreach ( $lines as $i => $line ) {
			$cls         = $classifications[ $i ] ?? [ 'category' => 'unknown', 'item_id' => '', 'quantity' => 1, 'confidence' => 0 ];
			$line_amount = (float) ( $line['amount'] ?? 0 );
			$line_qty    = max( 1, (int) ( $cls['quantity'] ?? $line['qty'] ?? 1 ) );

			// Ledger kind: money-but-not-a-sale. Booked per its flags, never as product.
			if ( ! empty( $cls['ledger_kind'] ) ) {
				if ( $cls['ledger_kind'] === 'card_fee' ) {
					$cc_fee += abs( $line_amount );
				}
				$counts_rev = ! empty( $cls['counts_toward_revenue'] );
				if ( $counts_rev ) {
					$gross_billed += $line_amount; // e.g. a discount reduces the base
				}
				$processed_lines[] = [
					'description'    => $line['description'] ?? '',
					'amount'         => round( $line_amount, 2 ),
					'cogs'           => 0.0,
					'cogs_source'    => 'Ledger entry (' . $cls['ledger_kind'] . ') — non-product',
					'commissionable' => false,
					'category'       => $cls['category'],
				];
				continue;
			}

			// Non-commissionable product line (attribute-flagged).
			if ( ! empty( $cls['non_commissionable'] ) ) {
				$gross_billed     += $line_amount;
				$processed_lines[] = [
					'description'    => $line['description'] ?? '',
					'amount'         => round( $line_amount, 2 ),
					'cogs'           => 0.0,
					'cogs_source'    => 'Non-commissionable',
					'commissionable' => false,
					'category'       => $cls['category'],
				];
				continue;
			}

			// COGS via the Item Engine cost book (empty catalog ⇒ clean, reported $0).
			$item_id  = (string) ( $cls['item_id'] ?? '' );
			$dims     = is_array( $cls['dimensions'] ?? null ) ? $cls['dimensions'] : [];
			$res      = ZCC_Cost_Book::resolve_cost_detailed( $item_id, $line_qty, $line_amount, $dims );
			$line_cogs = (float) $res['cost'];
			$cogs_source = $item_id === '' ? 'No catalog match — $0 COGS' : ( 'COGS: ' . $item_id );

			if ( ! empty( $res['missing'] ) && ! self::is_noise_zero_line( $line_amount, $line['description'] ?? '' ) ) {
				$flags[] = [ 'invoice' => $inv['invoice_number'] ?? '', 'description' => $line['description'] ?? '', 'amount' => $line_amount, 'reason' => "Classified to item '{$item_id}', which is not in the catalog — $0 COGS applied, flagged.", 'severity' => 'warning' ];
			} elseif ( $item_id === '' && ( $cls['confidence'] ?? 0 ) < 0.6 && ! self::is_noise_zero_line( $line_amount, $line['description'] ?? '' ) ) {
				$flags[] = [ 'invoice' => $inv['invoice_number'] ?? '', 'description' => $line['description'] ?? '', 'amount' => $line_amount, 'reason' => $cls['notes'] ?? 'Low-confidence classification.' ];
			}

			// COGS sanity cap: cost > revenue is always a wrong match — cap + flag.
			$cap = ZCC_Cost_Book::apply_sanity_cap( $line_cogs, $line_amount );
			if ( $cap['capped'] ) {
				$flags[] = [ 'invoice' => $inv['invoice_number'] ?? '', 'description' => $line['description'] ?? '', 'amount' => $line_amount, 'reason' => sprintf( 'COGS ($%s) exceeded the line price ($%s) — capped; verify the item.', number_format( $line_cogs, 2 ), number_format( $line_amount, 2 ) ), 'severity' => 'warning' ];
				$line_cogs   = $cap['cost'];
				$cogs_source .= ' — capped at line price';
			}

			$gross_billed += $line_amount;
			$total_cogs   += $line_cogs;

			$subtype = (string) ( $cls['subtype'] ?? '' );
			if ( $subtype !== '' ) {
				$subtype_gross[ $subtype ] = ( $subtype_gross[ $subtype ] ?? 0 ) + $line_amount;
				$subtype_net[ $subtype ]   = ( $subtype_net[ $subtype ] ?? 0 ) + ( $line_amount - $line_cogs );
			}

			$processed_lines[] = [
				'description'    => $line['description'] ?? '',
				'amount'         => round( $line_amount, 2 ),
				'cogs'           => round( $line_cogs, 2 ),
				'cogs_source'    => $cogs_source,
				'commissionable' => true,
				'net'            => round( $line_amount - $line_cogs, 2 ),
				'category'       => $cls['category'],
				'subtype'        => $subtype,
			];
		}

		// Invoice-level totals.
		$net_billed         = round( $gross_billed - $discount_amount, 2 );
		$net_commissionable = round( $net_billed - $total_cogs, 2 );

		// Attribution codes, filtered to resolvable plans + reserved (place/source) tokens.
		$sp_codes  = isset( $inv['salesperson_codes'] ) && is_array( $inv['salesperson_codes'] ) ? $inv['salesperson_codes'] : [];
		$sp_codes  = self::filter_resolvable_codes( $sp_codes );
		$is_shared = class_exists( 'ZCC_Split' ) && ZCC_Split::is_shared( $sp_codes );

		$split_meta     = null;
		$net_attributed = $net_commissionable;

		if ( $is_shared ) {
			$split      = ZCC_Split::resolve( $net_commissionable, $sp_codes, $target_party_id );
			$split_meta = $split;
			if ( $split['target'] !== null ) {
				$commission     = $split['target']['commission_amount'];
				$net_attributed = round( $net_commissionable * (float) $split['target']['share_fraction'], 2 );
			} else {
				// Named on the code but no resolvable share — pay $0 and flag.
				$commission     = 0.0;
				$net_attributed = 0.0;
			}
			foreach ( (array) ( $split['warnings'] ?? [] ) as $w ) {
				$flags[] = [ 'invoice' => $inv['invoice_number'] ?? '', 'description' => 'Split', 'amount' => $net_commissionable, 'reason' => $w ];
			}
		} else {
			$commission = self::apply_commission_structure( $net_commissionable, $plan, $running_total );
		}

		// Product-scoped minimum-commission floor (generalized "product minimum").
		$floor_meta = self::apply_product_minimums( $commission, $plan, $is_shared, $net_commissionable, $net_attributed, $subtype_gross, $subtype_net, $flags, $inv );
		if ( $floor_meta !== null ) {
			$commission = $floor_meta['new_commission'];
		}

		return [
			'invoice_number'    => $inv['invoice_number'] ?? '',
			'invoice_id'        => (int) ( $inv['invoice_id'] ?? 0 ),
			'customer_name'     => $inv['customer_name'] ?? '',
			'date_completed'    => $inv['date_completed'] ?? '',
			'date_paid'         => $inv['date_paid'] ?? '',
			'fb_url'            => $inv['fb_url'] ?? '',
			'payment_status'    => self::resolve_pay_status( $inv ),
			'lines'             => $processed_lines,
			'gross_billed'      => round( $gross_billed, 2 ),
			'total_cogs'        => round( $total_cogs, 2 ),
			'discount_amount'   => round( $discount_amount, 2 ),
			'cc_fee'            => round( $cc_fee, 2 ),
			'net_billed'        => $net_billed,
			'net_commissionable'=> $net_commissionable,
			'net_attributed'    => round( $net_attributed, 2 ),
			'commission_amount' => round( $commission, 2 ),
			'is_shared'         => $is_shared,
			'salesperson_codes' => ZCC_Split::normalize_codes( $sp_codes ),
			'split'             => $split_meta,
			'product_minimum'   => $floor_meta,
			'flags'             => $flags,
		];
	}

	/** Apply the plan's commission structure to a solo invoice's net. */
	private static function apply_commission_structure( float $net, array $plan, float $running_total ): float {
		if ( empty( $plan['configured'] ) ) {
			return 0.0; // unconfigured never pays a default rate
		}
		switch ( (string) ( $plan['structure'] ?? '' ) ) {
			case 'flat_rate':
			case 'formula':
				$rate = (float) ( $plan['rate_percent'] ?? 0 );
				return round( $net * ( $rate / 100 ), 2 );

			case 'tiered':
				return self::apply_tiered( $net, $plan, $running_total );

			case 'per_job_bonus':
				return round( (float) ( $plan['bonus_per_job'] ?? 0 ), 2 ); // per invoice, not per dollar

			case 'salary_only':
			case 'piece_rate':
			default:
				return 0.0;
		}
	}

	/** Marginal tiered commission, accumulated across the period (incl. finalized rows). */
	private static function apply_tiered( float $net, array $plan, float $running_total ): float {
		$tiers = is_array( $plan['tiers'] ?? null ) ? $plan['tiers'] : [];
		if ( empty( $tiers ) || $net <= 0 ) {
			return 0.0;
		}
		$start      = max( 0.0, $running_total );
		$end        = $start + $net;
		$commission = 0.0;
		$prev_ceil  = 0.0;
		foreach ( $tiers as $tier ) {
			$ceiling = ( isset( $tier['ceiling'] ) && $tier['ceiling'] !== null && $tier['ceiling'] !== '' ) ? (float) $tier['ceiling'] : INF;
			$rate    = (float) ( $tier['rate'] ?? 0 );
			$band_lo = max( $start, $prev_ceil );
			$band_hi = min( $end, $ceiling );
			if ( $band_hi > $band_lo ) {
				$commission += ( $band_hi - $band_lo ) * ( $rate / 100 );
			}
			$prev_ceil = $ceiling;
			if ( $ceiling >= $end ) {
				break;
			}
		}
		return round( $commission, 2 );
	}

	/**
	 * Product-scoped minimum-commission floor. Generalizes the "product minimum":
	 * for each configured rule whose qualifying subtype hit its min job gross, if
	 * the party is covered and on a percentage structure, the subtype's share of
	 * commission is floored at the rule's minimum. Ships with NO rules ⇒ no-op.
	 *
	 * @return array|null meta when a floor fired, else null.
	 */
	private static function apply_product_minimums( float $commission, array $plan, bool $is_shared, float $net_commissionable, float $net_attributed, array $subtype_gross, array $subtype_net, array &$flags, array $inv ): ?array {
		if ( empty( $plan['configured'] ) || empty( $plan['minimum_party'] ) ) {
			return null;
		}
		if ( ! in_array( (string) ( $plan['structure'] ?? '' ), [ 'flat_rate', 'tiered' ], true ) ) {
			return null;
		}
		if ( ! class_exists( 'ZDZ_Compensation' ) ) {
			return null;
		}
		$party_id = (int) ( $plan['party_id'] ?? 0 );
		foreach ( ZDZ_Compensation::product_minimums() as $rule ) {
			$sub = $rule['item_subtype'];
			if ( ! isset( $subtype_gross[ $sub ] ) ) {
				continue;
			}
			$parties = (array) ( $rule['applies_to_parties'] ?? [] );
			if ( ! empty( $parties ) && ! in_array( $party_id, $parties, true ) ) {
				continue;
			}
			if ( (float) $subtype_gross[ $sub ] < (float) $rule['min_job_gross'] ) {
				continue;
			}
			$basis_net = $is_shared ? max( 0.0001, $net_attributed ) : max( 0.0001, $net_commissionable );
			$sub_net   = (float) ( $subtype_net[ $sub ] ?? 0 );
			$fraction  = $is_shared
				? min( 1.0, $net_commissionable > 0 ? ( $sub_net / $net_commissionable ) : 0.0 )
				: min( 1.0, $sub_net / $basis_net );
			$sub_comm     = round( $commission * $fraction, 2 );
			$other_comm   = round( $commission - $sub_comm, 2 );
			$min_comm     = (float) $rule['min_commission'];
			if ( $sub_comm < $min_comm ) {
				$new = round( $other_comm + $min_comm, 2 );
				$flags[] = [ 'invoice' => $inv['invoice_number'] ?? '', 'description' => 'Product minimum applied', 'amount' => $subtype_gross[ $sub ], 'reason' => sprintf( 'Commission on subtype "%s" raised to the $%s minimum (job ≥ $%s).', $sub, number_format( $min_comm, 2 ), number_format( (float) $rule['min_job_gross'], 2 ) ), 'severity' => 'info' ];
				return [ 'applied' => true, 'rule' => $rule['id'], 'subtype' => $sub, 'computed' => $sub_comm, 'floored_to' => $min_comm, 'new_commission' => $new ];
			}
		}
		return null;
	}

	/** Keep only codes that resolve to a plan (when at least one does) and are not reserved place/source tokens. */
	private static function filter_resolvable_codes( array $codes ): array {
		if ( count( $codes ) < 2 || ! class_exists( 'ZDZ_Compensation' ) ) {
			return $codes;
		}
		$reserved = array_map( 'strtoupper', (array) ( ZDZ_Compensation::attribution()['reserved_tokens'] ?? [] ) );
		$resolvable = [];
		foreach ( $codes as $c ) {
			$cu = strtoupper( trim( (string) $c ) );
			if ( in_array( $cu, $reserved, true ) ) {
				continue;
			}
			if ( ZDZ_Compensation::plan_by_code( $cu ) !== null ) {
				$resolvable[] = $cu;
			}
		}
		return ! empty( $resolvable ) ? $resolvable : $codes;
	}

	private static function build_summary( array $invoices, array $plan ): array {
		$gross = 0.0; $cogs = 0.0; $net = 0.0; $commission = 0.0; $cc = 0.0; $disc = 0.0;
		foreach ( $invoices as $inv ) {
			$gross      += (float) ( $inv['gross_billed'] ?? 0 );
			$cogs       += (float) ( $inv['total_cogs'] ?? 0 );
			$net        += (float) ( $inv['net_commissionable'] ?? 0 );
			$commission += (float) ( $inv['commission_amount'] ?? 0 );
			$cc         += (float) ( $inv['cc_fee'] ?? 0 );
			$disc       += (float) ( $inv['discount_amount'] ?? 0 );
		}
		$base = (float) ( $plan['base_pay'] ?? 0 );
		return [
			'invoice_count'      => count( $invoices ),
			'gross_billed'       => round( $gross, 2 ),
			'total_cogs'         => round( $cogs, 2 ),
			'total_cc_fees'      => round( $cc, 2 ),
			'total_discounts'    => round( $disc, 2 ),
			'net_commissionable' => round( $net, 2 ),
			'commission_rate'    => self::rate_label( $plan ),
			'total_commission'   => round( $commission, 2 ),
			'base_pay'           => round( $base, 2 ),
			'total_pay'          => round( $base + $commission, 2 ),
		];
	}

	private static function rate_label( array $plan ): string {
		if ( empty( $plan['configured'] ) ) {
			return 'unconfigured';
		}
		switch ( (string) ( $plan['structure'] ?? '' ) ) {
			case 'flat_rate':
			case 'formula':
				return rtrim( rtrim( number_format( (float) ( $plan['rate_percent'] ?? 0 ), 2 ), '0' ), '.' ) . '%';
			case 'tiered':
				return 'Tiered';
			case 'per_job_bonus':
				return '$' . number_format( (float) ( $plan['bonus_per_job'] ?? 0 ), 2 ) . '/job';
			case 'salary_only':
				return 'Salary only';
			case 'piece_rate':
				return 'Piece rate';
			default:
				return (string) ( $plan['structure'] ?? '' );
		}
	}

	public static function is_payable_status( array $inv ): bool {
		$s = self::resolve_pay_status( $inv );
		$gate = class_exists( 'ZDZ_Compensation' ) ? ZDZ_Compensation::payability() : [ 'statuses' => [ 'paid', 'partial' ] ];
		return in_array( $s, (array) $gate['statuses'], true );
	}

	private static function resolve_pay_status( array $inv ): string {
		foreach ( [ $inv['v3_status'] ?? '', $inv['status'] ?? '' ] as $cand ) {
			$c = strtolower( trim( (string) $cand ) );
			if ( $c !== '' && ! ctype_digit( $c ) ) {
				return $c;
			}
		}
		$tol         = class_exists( 'ZDZ_Compensation' ) ? (float) ( ZDZ_Compensation::payability()['money_tolerance'] ?? 0.005 ) : 0.005;
		$outstanding = (float) ( $inv['outstanding'] ?? 0 );
		$total       = (float) ( $inv['total_amount'] ?? $inv['gross_billed'] ?? 0 );
		if ( $total > 0 && $outstanding <= $tol ) {
			return 'paid';
		}
		if ( $outstanding > $tol && $outstanding < $total ) {
			return 'partial';
		}
		return '';
	}

	/** A $0 non-product line (location marker, "tax & install incl.", receipt link) is expected, not review noise. */
	private static function is_noise_zero_line( float $amount, string $description ): bool {
		if ( abs( $amount ) > 0.005 ) {
			return false;
		}
		$d = strtolower( $description );
		foreach ( [ 'tax and install', 'tax & install', 'install incl', 'installation included', 'location', 'see notes', 'no charge', 'n/c', 'included', 'incl.', 'receipt', 'link' ] as $needle ) {
			if ( strpos( $d, $needle ) !== false ) {
				return true;
			}
		}
		return false;
	}
}
