<?php
/**
 * ZCC_Cost_Book — COGS resolution, delegated to the Item Engine.
 *
 * The old plugin owned a `wp_tscc_cogs_catalog` table seeded with ~49 real
 * supplier costs. Per the crosswalk (CO-02/CO-25) that catalog is subsumed by
 * the Item Engine: cost lives on the Item (`attributes.cost` / a cost scheme),
 * and this class is a thin, deterministic resolver over it. Nothing is seeded;
 * an empty catalog makes every lookup a clean, reported zero — never a silent
 * one.
 *
 * The critical distinction the old code added (v2.8.0) is preserved:
 * resolve_cost_detailed() reports whether an id actually EXISTS, so a
 * classification pointing at a missing item becomes a flag, not a hidden $0.
 *
 * Cost types mirror the Pricing Scheme method set and resolve through the Item
 * Engine's own safe formula evaluator — one arithmetic engine for price and
 * cost, no eval().
 *
 * @package Zorderz\Commission
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZCC_Cost_Book {

	/**
	 * Resolve the COGS for an item.
	 *
	 * @param string $item_id       Item Engine id (from the classifier).
	 * @param int    $quantity      Units.
	 * @param float  $billed_amount For a percent-of-billed cost.
	 * @param array  $dimensions    width_in / height_in for a formula cost.
	 * @return float Dollars, 0.0 when the item / cost is absent.
	 */
	public static function resolve_cost( string $item_id, int $quantity = 1, float $billed_amount = 0.0, array $dimensions = [] ): float {
		$res = self::resolve_cost_detailed( $item_id, $quantity, $billed_amount, $dimensions );
		return (float) $res['cost'];
	}

	/**
	 * Resolve a cost AND report whether the item id actually exists.
	 *
	 * @return array{cost:float,found:bool,missing:bool,cost_type:string}
	 *   - missing : true when a NON-EMPTY id was supplied but no item exists
	 *               (the actionable "unknown/fabricated id" case).
	 */
	public static function resolve_cost_detailed( string $item_id, int $quantity = 1, float $billed_amount = 0.0, array $dimensions = [] ): array {
		$item_id = trim( $item_id );
		if ( $item_id === '' ) {
			return [ 'cost' => 0.0, 'found' => false, 'missing' => false, 'cost_type' => '' ];
		}

		$item = zcc_item_get( $item_id );
		if ( ! is_array( $item ) ) {
			return [ 'cost' => 0.0, 'found' => false, 'missing' => true, 'cost_type' => '' ];
		}

		$attrs     = is_array( $item['attributes'] ?? null ) ? $item['attributes'] : [];
		$cost_type = (string) ( $attrs['cost_type'] ?? ( isset( $attrs['cost_formula'] ) && $attrs['cost_formula'] !== '' ? 'formula' : ( isset( $attrs['cost_percent'] ) ? 'percent_of_billed' : 'fixed' ) ) );
		$quantity  = max( 0, $quantity );

		switch ( $cost_type ) {
			case 'percent_of_billed':
				$pct  = (float) ( $attrs['cost_percent'] ?? $attrs['cost'] ?? 0 );
				$cost = ( $billed_amount <= 0 ) ? 0.0 : round( $billed_amount * ( $pct / 100.0 ), 2 );
				break;

			case 'formula':
				$expr = (string) ( $attrs['cost_formula'] ?? '' );
				$vars = [
					'width_in'  => (float) ( $dimensions['width_in'] ?? $dimensions['w'] ?? 0 ),
					'height_in' => (float) ( $dimensions['height_in'] ?? $dimensions['h'] ?? 0 ),
					'quantity'  => (float) $quantity,
					'cost'      => (float) ( $attrs['cost'] ?? 0 ),
				];
				$per  = class_exists( 'ZDZ_Item_Engine' ) ? (float) ZDZ_Item_Engine::eval_formula( $expr, $vars ) : 0.0;
				$cost = round( $per * max( 1, $quantity ), 2 );
				break;

			case 'per_unit':
			case 'fixed':
			default:
				$cost = round( (float) ( $attrs['cost'] ?? 0 ) * $quantity, 2 );
				break;
		}

		return [ 'cost' => $cost, 'found' => true, 'missing' => false, 'cost_type' => $cost_type ];
	}

	/**
	 * COGS sanity cap: a cost larger than the line's revenue is always a wrong
	 * match. Caller passes line cost + line amount; we return the capped cost and
	 * whether a cap fired, so the caller can raise a LOUD disposition. Tolerance
	 * is Core; a tenant can widen it via the filter.
	 *
	 * @return array{cost:float,capped:bool}
	 */
	public static function apply_sanity_cap( float $line_cogs, float $line_amount ): array {
		$tol = (float) apply_filters( 'zcc_cogs_sanity_tolerance', 0.005 );
		if ( $line_amount > $tol && $line_cogs > $line_amount + $tol ) {
			return [ 'cost' => $line_amount, 'capped' => true ];
		}
		return [ 'cost' => $line_cogs, 'capped' => false ];
	}
}
