<?php
/**
 * Zorderz Stock — Item Engine binding.
 *
 * This module owns NO product taxonomy. Everything about "what the business stocks" — the item
 * list, SKUs, unit nouns, subtype/category labels, par/reorder defaults and, critically, the
 * Bill-of-Materials (each item's `consumes[]`) — is read from ZDZ_Item_Engine. The old seed SKU
 * catalog and the hardcoded BOM keyword→SKU map are gone.
 *
 * Every method degrades to a NEUTRAL value on an empty catalog (or a missing engine), so nothing
 * here crashes before an admin has defined any items.
 *
 * Prefers the Item Engine static API; falls back to the mirrored `zdz_item_*` filters when class
 * load order makes the class unavailable — so binding is load-order-safe.
 *
 * @package Zorderz\Stock
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZSTOCK_Catalog {

	// ── Load-order-safe engine accessors ──────────────────────────────

	/** Match free text → one Item (full array) or null. */
	private static function match( $text ) {
		if ( is_callable( array( 'ZDZ_Item_Engine', 'match' ) ) ) {
			return ZDZ_Item_Engine::match( $text );
		}
		$pre = apply_filters( 'zdz_item_match', null, $text, array() );
		return is_array( $pre ) ? $pre : null;
	}

	/** Fetch one Item by id (full array) or null. */
	public static function get_item( $item_id ) {
		if ( is_callable( array( 'ZDZ_Item_Engine', 'get' ) ) ) {
			return ZDZ_Item_Engine::get( $item_id );
		}
		$pre = apply_filters( 'zdz_item_get', null, $item_id );
		return is_array( $pre ) ? $pre : null;
	}

	/** All items (optionally filtered), keyed by id. */
	private static function all( array $filter = array() ) {
		if ( is_callable( array( 'ZDZ_Item_Engine', 'all' ) ) ) {
			return ZDZ_Item_Engine::all( $filter );
		}
		return array();
	}

	/** subtype slug => label, for display grouping. */
	private static function subtype_labels() {
		$out = array();
		if ( is_callable( array( 'ZDZ_Item_Engine', 'subtypes' ) ) ) {
			foreach ( (array) ZDZ_Item_Engine::subtypes() as $s ) {
				if ( isset( $s['slug'] ) ) {
					$out[ $s['slug'] ] = (string) ( $s['label'] ?? $s['slug'] );
				}
			}
		}
		return $out;
	}

	/** Is the catalog empty (or absent)? Used to degrade the UI to a neutral empty state. */
	public static function is_empty() {
		if ( is_callable( array( 'ZDZ_Item_Engine', 'is_empty' ) ) ) {
			return (bool) ZDZ_Item_Engine::is_empty();
		}
		return empty( self::all() );
	}

	// ── Stock-tracked items ───────────────────────────────────────────

	/**
	 * The set of catalog items this module tracks stock for: every tangible product in the
	 * catalog (a service is not stocked), UNIONed with any item id that already has stock state
	 * (so a manually-adjusted item still appears even if its type later changes). Overridable via
	 * the `zstock_stock_item_ids` filter.
	 *
	 * @return array<int,string> item ids
	 */
	public static function stock_item_ids() {
		$ids = array_keys( self::all( array( 'type' => 'product' ) ) );

		global $wpdb;
		$tbl = ZSTOCK_DB::stock_table();
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tbl ) ) === $tbl ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- identifier from constant.
			$state_ids = $wpdb->get_col( "SELECT item_id FROM `{$tbl}`" );
			$ids       = array_merge( $ids, array_map( 'strval', (array) $state_ids ) );
		}
		$ids = array_values( array_unique( array_filter( $ids ) ) );

		$ids = apply_filters( 'zstock_stock_item_ids', $ids );
		return is_array( $ids ) ? array_values( array_unique( array_map( 'strval', $ids ) ) ) : array();
	}

	/**
	 * A display-shaped view of one stock item, merging catalog metadata with any per-item policy
	 * override. Costs are NOT baked in — `unit_cost` reads an admin-entered attribute only
	 * (default 0), so nothing supplier-priced ships in code.
	 *
	 * @param string $item_id
	 * @param array  $override  Row from wp_zstock_stock (current_stock, par_level, reorder_point).
	 * @return array|null
	 */
	public static function view( $item_id, array $override = array() ) {
		$item = self::get_item( $item_id );
		$labels = self::subtype_labels();

		if ( ! $item ) {
			// Item id present in stock state but absent from the catalog (e.g. a legacy id awaiting
			// remap). Surface it neutrally rather than dropping it silently.
			if ( empty( $override ) ) {
				return null;
			}
			return array(
				'id'            => (string) $item_id,
				'name'          => (string) $item_id,
				'sku'           => '',
				'category'      => '',
				'unit'          => 'each',
				'current_stock' => (float) ( $override['current_stock'] ?? 0 ),
				'par_level'     => (float) ( $override['par_level'] ?? 0 ),
				'reorder_point' => (float) ( $override['reorder_point'] ?? 0 ),
				'unit_cost'     => 0.0,
				'unresolved'    => true,
			);
		}

		$attrs = is_array( $item['attributes'] ?? null ) ? $item['attributes'] : array();
		$stock = is_array( $attrs['stock'] ?? null ) ? $attrs['stock'] : array();

		$par = isset( $override['par_level'] ) && null !== $override['par_level']
			? (float) $override['par_level']
			: (float) ( $stock['par'] ?? 0 );
		$reorder = isset( $override['reorder_point'] ) && null !== $override['reorder_point']
			? (float) $override['reorder_point']
			: (float) ( $stock['reorder_point'] ?? 0 );

		return array(
			'id'            => (string) $item['id'],
			'name'          => (string) ( $item['display_name'] ?? $item['id'] ),
			'sku'           => (string) ( $attrs['sku'] ?? '' ),
			'category'      => (string) ( $labels[ $item['subtype'] ] ?? $item['subtype'] ),
			'unit'          => (string) ( $item['unit_noun_singular'] ?: 'each' ),
			'current_stock' => (float) ( $override['current_stock'] ?? 0 ),
			'par_level'     => $par,
			'reorder_point' => $reorder,
			'lead_time_days' => (int) ( $stock['lead_time_days'] ?? 0 ),
			'unit_cost'     => (float) ( $attrs['unit_cost'] ?? 0 ),
		);
	}

	// ── Supplier-invoice line matching ────────────────────────────────

	/**
	 * Resolve a parsed supplier-invoice line to a catalog item id.
	 * SKU (attributes.sku) exact match first, then the engine's longest-alias resolver over the
	 * description. Returns '' when nothing matches (a logged disposition upstream), never a guess.
	 *
	 * @param array $parsed  { sku, description, ... }
	 * @return string item id, or ''.
	 */
	public static function match_supplier_line( array $parsed ) {
		$sku = strtolower( trim( (string) ( $parsed['sku'] ?? '' ) ) );
		if ( '' !== $sku ) {
			foreach ( self::all( array( 'type' => 'product' ) ) as $id => $item ) {
				$item_sku = strtolower( trim( (string) ( $item['attributes']['sku'] ?? '' ) ) );
				if ( '' !== $item_sku && $item_sku === $sku ) {
					return (string) $id;
				}
			}
		}
		$desc = (string) ( $parsed['description'] ?? '' );
		if ( '' !== trim( $desc ) ) {
			$item = self::match( $desc );
			if ( $item && ! empty( $item['id'] ) ) {
				return (string) $item['id'];
			}
		}
		return '';
	}

	// ── Bill of Materials (an item's `consumes[]`) ────────────────────

	/**
	 * Materials consumed when `$qty` of a billed line named `$line_name` is fulfilled.
	 *
	 * The BOM is the matched Item's `consumes[]` in the Item Engine — a list of
	 * { item_id, qty_per_unit, uom }. This replaces the old local BOM keyword→SKU table entirely.
	 * A non-numeric `qty_per_unit` (e.g. a summary line whose quantity is computed at runtime) or a
	 * subtype-target (`subtype:*`) is skipped with a logged disposition — never guessed. An empty catalog / no
	 * match ⇒ [] (neutral), so a business without a BOM simply records no consumption.
	 *
	 * @param string $line_name  Billed product line text.
	 * @param float  $qty        Units sold on that line.
	 * @return array<int,array>  [ { item_id, quantity, uom, source_line, qty_per_unit } ]
	 */
	public static function resolve_consumption( $line_name, $qty ) {
		$qty = (float) $qty;
		if ( $qty <= 0 || '' === trim( (string) $line_name ) ) {
			return array();
		}
		$item = self::match( $line_name );
		if ( ! $item || empty( $item['id'] ) ) {
			zstock_log( "consumption: no catalog match for '{$line_name}' (skipped)" );
			return array();
		}
		$consumes = is_array( $item['consumes'] ?? null ) ? $item['consumes'] : array();
		if ( empty( $consumes ) ) {
			zstock_log( "consumption: '{$item['id']}' declares no BOM (consumes[]) — nothing deducted" );
			return array();
		}
		$out = array();
		foreach ( $consumes as $c ) {
			if ( ! is_array( $c ) ) {
				continue;
			}
			$target = (string) ( $c['item_id'] ?? '' );
			$per    = $c['qty_per_unit'] ?? null;
			if ( '' === $target || 0 === strpos( $target, 'subtype:' ) || ! is_numeric( $per ) ) {
				zstock_log( "consumption: '{$item['id']}' → non-resolvable BOM target '{$target}' (skipped)" );
				continue;
			}
			$out[] = array(
				'item_id'      => $target,
				'quantity'     => $qty * (float) $per,
				'uom'          => (string) ( $c['uom'] ?? 'each' ),
				'source_line'  => (string) $line_name,
				'qty_per_unit' => (float) $per,
			);
		}
		return $out;
	}
}
