<?php
/**
 * ZCC_Cost_Recheck — what today's cost rules would say about a locked row, without changing it.
 *
 * Cost rules change: an item gets a cost, a cost is corrected, a location line or a line billed
 * $0 stops carrying a cost. A DRAFT row follows by itself (every calculation rewrites its
 * drafts). A LOCKED row never changes — its month was paid — so when the rules would now cost
 * it differently, the row is listed for a person to look at, with the old and the new figure.
 *
 * The new figure is worked out only from what the row kept (each line's item id, quantity,
 * amount and category) with the deterministic rules: no model, no billing-system read, nothing
 * written. It is the cost book's cost before any material-cost rule, compared with the cost the
 * line had before those rules, so it is labelled "about". Rows kept before item ids were stored
 * are compared on the location and $0 rules only.
 *
 * @package Zorderz\Commission
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZCC_Cost_Recheck {

	/**
	 * Each stored line's cost under today's rules next to the cost it was given.
	 *
	 * @param array $lines Ledger detail lines.
	 * @return array{ old:float, new:float, changed:array }
	 */
	public static function recheck_lines( array $lines ): array {
		$old_total = 0.0;
		$new_total = 0.0;
		$changed   = [];
		foreach ( $lines as $l ) {
			if ( ! is_array( $l ) || ( isset( $l['commissionable'] ) && ! $l['commissionable'] && 'location' !== ( $l['category'] ?? '' ) ) ) {
				continue;
			}
			$old = round( (float) ( $l['material']['before'] ?? $l['cogs'] ?? 0 ), 2 );
			$why = '';
			$new = self::line_cost( $l, $old, $why );
			$old_total += $old;
			$new_total += $new;
			if ( abs( $new - $old ) > 0.005 ) {
				$changed[] = [ 'description' => (string) ( $l['description'] ?? '' ), 'old' => $old, 'new' => $new, 'why' => $why ];
			}
		}
		return [ 'old' => round( $old_total, 2 ), 'new' => round( $new_total, 2 ), 'changed' => $changed ];
	}

	/** One line's cost by today's rules; $why names what changed it. */
	private static function line_cost( array $l, float $old, string &$why ): float {
		$amt     = (float) ( $l['amount'] ?? 0 );
		$item_id = (string) ( $l['item_id'] ?? '' );
		if ( 'location' === ( $l['category'] ?? '' ) ) {
			$why = 'location';
			return 0.0;
		}
		$item    = '' !== $item_id ? zcc_item_get( $item_id ) : null;
		$zero_ok = is_array( $item ) && ! empty( $item['attributes']['cost_on_zero_lines'] );
		if ( $amt <= 0.005 && ! $zero_ok ) {
			$why = 'zero_line';
			return 0.0;
		}
		if ( '' === $item_id || ! array_key_exists( 'item_id', $l ) ) {
			return $old; // nothing kept to re-cost from
		}
		$qty = max( 1, (int) ( $l['quantity'] ?? 1 ) );
		$res = ZCC_Cost_Book::resolve_cost_detailed( $item_id, $qty, $amt );
		$new = (float) $res['cost'];
		$cap = ZCC_Cost_Book::apply_sanity_cap( $new, $amt );
		$new = round( (float) $cap['cost'], 2 );
		if ( abs( $new - $old ) > 0.005 ) {
			$why = ! empty( $res['missing'] ) ? 'item_gone' : 'cost_changed';
		}
		return $new;
	}

	/** A review flag for one locked ledger row whose cost today's rules would change, or null. */
	public static function flag_for_entry( array $le ): ?array {
		$lines = json_decode( (string) ( $le['detail_json'] ?? '[]' ), true );
		if ( ! is_array( $lines ) || ! $lines ) {
			return null;
		}
		$r = self::recheck_lines( $lines );
		if ( ! $r['changed'] ) {
			return null;
		}
		return [
			'invoice'      => (string) ( $le['invoice_number'] ?? '' ),
			'description'  => 'Cost recheck',
			'amount'       => 0,
			'reason'       => sprintf(
				/* translators: 1: old cost, 2: new cost, 3: lines changed. */
				__( 'Locked with a cost of $%1$s; today’s cost rules give about $%2$s (%3$d line(s)). The locked figure stands; a person decides whether anything is owed.', 'zorderz' ),
				number_format( $r['old'], 2 ),
				number_format( $r['new'], 2 ),
				count( $r['changed'] )
			),
			'severity'     => 'info',
			'cost_recheck' => $r,
		];
	}
}
