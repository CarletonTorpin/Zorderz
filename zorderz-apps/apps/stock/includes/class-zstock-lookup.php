<?php
/**
 * ZSTOCK_Lookup — Stock's read verb for the home ask bar: "how many fine cloth rolls do we have".
 *
 * `stock.lookup` finds the items whose name, category or SKU hold every word asked, and says how many are on hand.
 *
 * NAMES FIRST. Items whose NAME holds every word lead the answer. With a shared category, matching name + category +
 * SKU together would list every item of the category for one word of it. Items found only through their category or
 * SKU are not dropped (one item named after its category must not hide the rest of the category): the ask bar counts
 * them in "and N more", and the widget lists them after the names under "Also in <category>". When no name fits,
 * they are the answer. Same rule in both places (assets/js/widget.js showMatches).
 *
 * QUANTITIES ONLY: anyone signed in may ask, so the answer never carries a cost, a value, a supplier or an order.
 * Not on the shared device (refused by the registry and here).
 *
 * NOT COUNTED YET: an item nobody has counted is not "out" — Stock does not know. Its status is `uncounted` and,
 * when nothing has come in either, on_hand reads "not counted yet" instead of a 0 that looks like a fact.
 *
 * @package Zorderz\Stock
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZSTOCK_Lookup {

	const MAX_ITEMS = 8;

	/** Words that carry no meaning in an item search. */
	const STOP = array( 'the', 'a', 'an', 'any', 'some', 'our', 'of', 'for', 'in', 'on', 'we', 'do', 'have', 'got', 'is', 'are', 'there', 'how', 'many', 'much', 'left', 'stock', 'available', 'hand', 'inventory' );

	/** The registry row: a read, quantities only, for anyone signed in who is not the shared device. */
	public static function get_capability_descriptor(): array {
		return array(
			'verb'          => 'stock.lookup',
			'provider'      => 'stock',
			'app'           => ZSTOCK_APP_ID,
			'tier'          => 'viewer',
			'needs_app'     => false,
			'callback'      => array( __CLASS__, 'lookup' ),
			'kiosk'         => false,
			'kiosk_bounded' => false,
			'side_effect'   => false,
			'outside'       => false,
			'summary'       => 'How many of an item are on hand (quantities only; never costs).',
		);
	}

	public static function register(): void {
		add_filter( 'zdz_register_capabilities', function ( $caps ) {
			$caps = is_array( $caps ) ? $caps : array();
			$d    = self::get_capability_descriptor();
			$caps[ $d['verb'] ] = $d;
			return $caps;
		} );
	}

	/** The words of an ask, singular, without filler; words under 3 letters cannot name an item on their own. PURE. */
	public static function words( string $q ): array {
		$q   = strtolower( preg_replace( '/[^A-Za-z0-9\s\-]/', ' ', $q ) );
		$out = array();
		foreach ( preg_split( '/\s+/', trim( $q ) ) as $w ) {
			if ( '' === $w || in_array( $w, self::STOP, true ) || strlen( $w ) < 3 ) {
				continue;
			}
			$out[] = self::singular( $w );
		}
		return array_values( array_unique( $out ) );
	}

	/** rolls -> roll; glass stays glass. PURE. */
	public static function singular( string $w ): string {
		return ( strlen( $w ) > 3 && 's' === substr( $w, -1 ) && 'ss' !== substr( $w, -2 ) ) ? substr( $w, 0, -1 ) : $w;
	}

	/**
	 * Does this item hold every word, as a WHOLE word of the given fields ("tom" must not find "Bottom rail", nor
	 * "ray" find "Gray cloth")? A SKU also counts whole, singular like the asked word. PURE.
	 */
	public static function matches( array $item, array $words, array $fields = array( 'name', 'category', 'sku' ) ): bool {
		if ( ! $words ) {
			return false;
		}
		$hay = '';
		foreach ( $fields as $f ) {
			$hay .= ' ' . strtolower( (string) ( $item[ $f ] ?? '' ) );
		}
		$toks = array();
		foreach ( preg_split( '/[^a-z0-9]+/', $hay ) as $t ) {
			if ( '' !== $t ) {
				$toks[ self::singular( $t ) ] = true;
			}
		}
		if ( in_array( 'sku', $fields, true ) && '' !== (string) ( $item['sku'] ?? '' ) ) {
			$toks[ self::singular( strtolower( (string) $item['sku'] ) ) ] = true;
		}
		foreach ( $words as $w ) {
			if ( ! isset( $toks[ $w ] ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Names first: [ named, also ]. `named` hold every word in their name; `also` only through category or SKU. PURE.
	 *
	 * @return array{0:array,1:array}
	 */
	public static function rank( array $items, array $words ): array {
		$named = array();
		$also  = array();
		foreach ( $items as $r ) {
			if ( self::matches( $r, $words, array( 'name' ) ) ) {
				$named[] = $r;
			} elseif ( self::matches( $r, $words ) ) {
				$also[] = $r;
			}
		}
		return array( $named, $also );
	}

	/** One item as the answer shows it: quantities only. PURE. */
	public static function hit( array $r ): array {
		$stock   = (float) ( $r['current_stock'] ?? 0 );
		$counted = ! empty( $r['counted'] );
		$unknown = ! $counted && $stock <= 0;
		return array(
			'name'     => (string) $r['name'],
			'category' => (string) ( $r['category'] ?? '' ),
			'unit'     => $unknown ? '' : (string) ( ( $r['unit'] ?? '' ) ?: 'each' ),
			'on_hand'  => $unknown ? 'not counted yet' : ( ( floor( $stock ) == $stock ) ? (int) $stock : round( $stock, 2 ) ), // phpcs:ignore Universal.Operators.StrictComparisons
			'status'   => class_exists( 'ZSTOCK_Engine' ) ? ZSTOCK_Engine::status_of( $r ) : ( $counted ? 'good' : 'uncounted' ),
			'counted'  => $counted,
		);
	}

	/** The verb. */
	public static function lookup( array $payload ): array {
		$res = array( 'success' => false, 'denied' => false, 'items' => array(), 'count' => 0, 'more' => 0, 'query' => '', 'message' => '', 'source' => 'stock' );
		$uid = (int) get_current_user_id();
		if ( $uid <= 0 || ZSTOCK_Orders::is_kiosk( $uid ) || ! empty( $payload['is_kiosk'] ) ) {
			$res['success'] = true;
			$res['denied']  = true;
			$res['message'] = __( 'Stock is not available on the shared device.', 'zorderz' );
			return $res;
		}
		$q            = trim( (string) ( $payload['query'] ?? '' ) );
		$res['query'] = $q;
		$words        = self::words( $q );
		if ( ! $words ) {
			$res['success'] = true;
			$res['message'] = __( 'Which item?', 'zorderz' );
			return $res;
		}
		list( $named, $also ) = self::rank( ZSTOCK_Engine::items(), $words );
		$found = $named ? $named : $also;
		$extra = $named ? count( $also ) : 0;
		$hits  = array_map( array( __CLASS__, 'hit' ), $found );

		$res['success'] = true;
		$res['count']   = count( $hits ) + $extra;
		$res['more']    = max( 0, count( $hits ) - self::MAX_ITEMS ) + $extra;
		$res['items']   = array_slice( $hits, 0, self::MAX_ITEMS );
		if ( ! $hits ) {
			/* translators: %s: what was asked */
			$res['message'] = sprintf( __( 'Nothing in the stock list matches "%s".', 'zorderz' ), $q );
		}
		return $res;
	}
}
