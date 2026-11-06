<?php
/**
 * ZCC_Cost_Book — COGS resolution, delegated to the Item Engine.
 *
 * Cost lives on the Item in the Item Engine (`attributes.cost` / a cost
 * scheme), and this class is a thin, deterministic resolver over it. There is
 * no cost table of its own and nothing is seeded;
 * an empty catalog makes every lookup a clean, reported zero — never a silent
 * one.
 *
 * The critical distinction: resolve_cost_detailed() reports whether an id actually EXISTS, so a
 * classification pointing at a missing item becomes a flag, not a hidden $0.
 *
 * Cost types mirror the Pricing Scheme method set and resolve through the Item
 * Engine's own safe formula evaluator — one arithmetic engine for price and
 * cost, no eval().
 *
 * WHERE COSTS LIVE. The Item Engine's catalog is readable by every signed-in
 * person, so a cost written into an item's attributes is not private. Costs
 * belong in this app's own cost book: option `zcc_cost_book` (not autoloaded,
 * administrators only), item id => { cost_type, cost, cost_percent,
 * cost_formula, by, at }. An entry there wins; a cost still sitting in an item's
 * attributes is read as before (so no figure changes on an existing install)
 * and the settings screen offers to move it into the cost book.
 *
 * The cost form (save_entry) writes cost-each entries only; an edit keeps the
 * fields the form does not show; an item whose lines are booked without a cost
 * (non-commissionable) is refused.
 *
 * @package Zorderz\Commission
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZCC_Cost_Book {

	const OPTION    = 'zcc_cost_book';
	const MAX_MONEY = 100000;

	/** The attribute keys that carry a cost on an Item Engine item. */
	const COST_ATTRS = [ 'cost', 'cost_type', 'cost_percent', 'cost_formula' ];

	/** The private cost book: item id => entry. */
	public static function entries(): array {
		$v = function_exists( 'get_option' ) ? get_option( self::OPTION, [] ) : [];
		return is_array( $v ) ? $v : [];
	}

	/** The cost attributes for an item: the cost book's entry, else what its catalog attributes carry. */
	public static function cost_attrs( string $item_id, array $item ): array {
		$e = self::entries()[ $item_id ] ?? null;
		if ( is_array( $e ) ) {
			return $e;
		}
		$attrs = is_array( $item['attributes'] ?? null ) ? $item['attributes'] : [];
		return array_intersect_key( $attrs, array_flip( self::COST_ATTRS ) );
	}

	/**
	 * Add or change one cost-each entry. Administrators only (the caller checks the
	 * capability and the nonce); the item must exist and be one whose lines carry a cost.
	 *
	 * @return array{ok:bool,error?:string}
	 */
	public static function save_entry( string $item_id, $cost, int $by ): array {
		$item_id = sanitize_key( $item_id );
		$item    = '' !== $item_id ? zcc_item_get( $item_id ) : null;
		if ( ! is_array( $item ) ) {
			return [ 'ok' => false, 'error' => __( 'There is no catalog item with that id.', 'zorderz' ) ];
		}
		if ( ! empty( $item['attributes']['non_commissionable'] ) ) {
			return [ 'ok' => false, 'error' => __( 'Lines of this item are booked without a cost, so it takes no cost here.', 'zorderz' ) ];
		}
		if ( ! is_numeric( $cost ) || (float) $cost < 0 || (float) $cost > self::MAX_MONEY ) {
			return [ 'ok' => false, 'error' => __( 'The cost must be a number from 0 up.', 'zorderz' ) ];
		}
		$all = self::entries();
		$old = is_array( $all[ $item_id ] ?? null ) ? $all[ $item_id ] : [];
		$type = (string) ( $old['cost_type'] ?? 'per_unit' );
		if ( ! in_array( $type, [ 'per_unit', 'fixed' ], true ) ) {
			return [ 'ok' => false, 'error' => __( 'This entry is not a cost-each entry; change it in the Identity Pack’s costs file.', 'zorderz' ) ];
		}
		$all[ $item_id ] = array_merge( $old, [
			'cost_type' => 'per_unit',
			'cost'      => round( (float) $cost, 2 ),
			'by'        => $by,
			'at'        => time(),
		] );
		update_option( self::OPTION, $all, false );
		return [ 'ok' => true ];
	}

	/** Remove one entry from the cost book. */
	public static function delete_entry( string $item_id ): bool {
		$all = self::entries();
		if ( ! isset( $all[ $item_id ] ) ) {
			return false;
		}
		unset( $all[ $item_id ] );
		update_option( self::OPTION, $all, false );
		return true;
	}

	/** Item ids whose catalog attributes still carry a cost (visible to every signed-in person). */
	public static function catalog_costs(): array {
		if ( ! class_exists( 'ZDZ_Item_Engine' ) || ! method_exists( 'ZDZ_Item_Engine', 'all' ) ) {
			return [];
		}
		$out = [];
		foreach ( (array) ZDZ_Item_Engine::all() as $item ) {
			$attrs = is_array( $item['attributes'] ?? null ) ? $item['attributes'] : [];
			if ( array_intersect_key( $attrs, array_flip( self::COST_ATTRS ) ) && '' !== (string) ( $item['id'] ?? '' ) ) {
				$out[] = (string) $item['id'];
			}
		}
		return $out;
	}

	/**
	 * Move every cost out of the public catalog into the cost book: the entry is written here
	 * first (unless one exists), then the cost keys are taken off the item, every other field
	 * kept. Returns how many items moved.
	 */
	public static function move_catalog_costs( int $by ): int {
		if ( ! class_exists( 'ZDZ_Item_Engine' ) || ! method_exists( 'ZDZ_Item_Engine', 'save_item' ) ) {
			return 0;
		}
		$all   = self::entries();
		$moved = 0;
		foreach ( self::catalog_costs() as $id ) {
			$item = ZDZ_Item_Engine::get( $id );
			if ( ! is_array( $item ) ) {
				continue;
			}
			$attrs = (array) ( $item['attributes'] ?? [] );
			if ( ! isset( $all[ $id ] ) ) {
				$all[ $id ] = array_intersect_key( $attrs, array_flip( self::COST_ATTRS ) ) + [ 'by' => $by, 'at' => time() ];
				update_option( self::OPTION, $all, false );
			}
			$item['attributes'] = array_diff_key( $attrs, array_flip( self::COST_ATTRS ) );
			$ok = ZDZ_Item_Engine::save_item( $item );
			if ( true === $ok ) {
				$moved++;
			}
		}
		return $moved;
	}

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

		$attrs     = self::cost_attrs( $item_id, $item );
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
