<?php
/**
 * ZSTOCK_Orders — supplier orders as Incoming → Received, and every write to the stock ledger.
 *
 * ONE CLASS WRITES. Every ledger row goes through ledger_add() (ZSTOCK_Engine::add_ledger_entry delegates here),
 * and an item's current_stock is set from SUM(quantity_change) after every write, never incremented. Rows are only
 * ever added: the ledger is the truth.
 *
 * AN ORDER COUNTS AS RECEIVED ONLY WHEN A PERSON SAYS SO. An order arrives as `incoming` (typed in by hand, from an
 * uploaded invoice, or from supplier order mail). Someone taps Received when the box is here, picks which stock item
 * each line is and how many came, and only then do ledger rows go in, with who and when. Legacy `draft` rows (an
 * uploaded invoice waiting for approval) are treated as incoming.
 *
 * PART OF AN ORDER. 3 of 5 rolls come: the person says "some of it is still coming", the order stays open as
 * `partial`, each line remembers how many have come so far (`received_qty`, cumulative), and the rest is received
 * later or closed with "Nothing more coming". "Not ours" closes an order nothing came for, without touching stock.
 *
 * ONE RECEIPT AT A TIME. Received claims the order with a conditional UPDATE that also checks how many receipts the
 * person's screen saw (`receipts`), inside a transaction: a second tap, on another phone, finds the order already
 * moved on and writes nothing. (Transactions need InnoDB, MySQL's default.) A count takes a per-item named lock, so
 * two counts cannot both write a difference from the same starting number.
 *
 * NOT COUNTED YET. An item has a first count when someone counts it (`counted_at` on the stock row; a count of 0 is a
 * count). Until then Stock does not call it low or out — with nothing counted, every item would read as low.
 *
 * Item ids are Item Engine ids (strings); an item is "in the stock list" when the catalog binding can show it.
 *
 * @package Zorderz\Stock
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZSTOCK_Orders {

	const MAX_LINES     = 30;
	const MAX_QTY       = 100000;
	const MAX_EXTRA     = 10;
	const OPEN_STATUSES = array( 'incoming', 'draft', 'partial' );
	const SOURCES       = array( 'manual', 'email', 'upload' );

	/* ------------------------------------------------------------------ people, locks */

	/** The shared device never writes stock and never sees email orders. The theme's own rule first. */
	public static function is_kiosk( int $uid ): bool {
		if ( $uid <= 0 ) {
			return true;
		}
		if ( is_callable( array( 'ZDZ_User_Roles', 'is_shared_device' ) ) && ZDZ_User_Roles::is_shared_device( $uid ) ) {
			return true;
		}
		if ( is_callable( array( 'ZDZ_Hierarchy', 'is_kiosk' ) ) && ZDZ_Hierarchy::is_kiosk( $uid ) ) {
			return true;
		}
		$u = function_exists( 'get_userdata' ) ? get_userdata( $uid ) : null;
		return ! $u || in_array( 'zdz_general', (array) $u->roles, true );
	}

	/**
	 * A named MySQL lock (GET_LOCK), so two requests do not interleave on one item or one order. A database without
	 * named locks answers nothing: go on without it.
	 */
	public static function lock( string $name, int $wait = 5 ): bool {
		global $wpdb;
		$r = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', self::lock_name( $name ), $wait ) );
		return null === $r || '1' === (string) $r;
	}

	public static function unlock( string $name ): void {
		global $wpdb;
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::lock_name( $name ) ) );
	}

	private static function lock_name( string $name ): string {
		$prefix = isset( $GLOBALS['wpdb']->prefix ) ? (string) $GLOBALS['wpdb']->prefix : 'wp_';
		return substr( $prefix . 'zstock_' . $name, 0, 64 );
	}

	/* ------------------------------------------------------------------ the ledger */

	/** Is this item in the stock list (the catalog, or an item that already has stock state)? */
	public static function item_exists( string $item_id ): bool {
		$item_id = trim( $item_id );
		return '' !== $item_id && class_exists( 'ZSTOCK_Catalog' ) && is_array( ZSTOCK_Catalog::view( $item_id ) );
	}

	/** An item's display name, or its id when the catalog does not know it. */
	public static function item_name( string $item_id ): string {
		$v = class_exists( 'ZSTOCK_Catalog' ) ? ZSTOCK_Catalog::view( $item_id ) : null;
		return is_array( $v ) ? (string) $v['name'] : $item_id;
	}

	/**
	 * Add one ledger row and (unless $recompute is false) set the item's stock from the ledger. Positive = in,
	 * negative = out.
	 *
	 * @return int|false the ledger row id.
	 */
	public static function ledger_add( string $item_id, float $qty, string $type, string $ref_type = '', string $ref_id = '', string $label = '', string $notes = '', int $uid = 0, bool $recompute = true ) {
		global $wpdb;
		$item_id = trim( $item_id );
		if ( '' === $item_id || '' === $type || ! is_finite( $qty ) ) {
			return false;
		}
		$ok = $wpdb->insert(
			ZSTOCK_DB::ledger_table(),
			array(
				'item_id'          => self::cut( $item_id, 80 ),
				'transaction_type' => substr( $type, 0, 50 ),
				'quantity_change'  => round( $qty, 4 ),
				'reference_type'   => substr( $ref_type, 0, 50 ),
				'reference_id'     => substr( $ref_id, 0, 191 ),
				'reference_label'  => self::cut( $label, 255 ),
				'user_id'          => $uid > 0 ? $uid : (int) get_current_user_id(),
				'notes'            => $notes,
				'created_at'       => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%f', '%s', '%s', '%s', '%d', '%s', '%s' )
		);
		if ( ! $ok ) {
			return false;
		}
		$id = (int) $wpdb->insert_id;
		if ( $recompute ) {
			self::recompute( $item_id );
		}
		return $id;
	}

	/** current_stock = SUM of the item's ledger rows, set in one statement. */
	public static function recompute( string $item_id ): float {
		return (float) ZSTOCK_Engine::recalculate_stock( $item_id );
	}

	/**
	 * A count: the number on the shelf. Writes the difference as a CYCLE_COUNT row (zero is a real count) and marks
	 * the item counted. Holds the item's lock, so two phones counting at once cannot both write a difference from the
	 * same starting number.
	 *
	 * @return array{ok:bool,message?:string,ledger_id?:int,new_stock?:float,delta?:float}
	 */
	public static function count_item( string $item_id, float $actual, int $uid, string $notes = '' ): array {
		global $wpdb;
		if ( ! self::item_exists( $item_id ) ) {
			return array( 'ok' => false, 'message' => __( 'That item is not in the stock list.', 'zorderz' ) );
		}
		if ( ! is_finite( $actual ) || $actual < 0 || $actual > self::MAX_QTY ) {
			return array( 'ok' => false, 'message' => __( 'Enter how many are there: 0 or more.', 'zorderz' ) );
		}
		if ( ! self::lock( 'item_' . md5( $item_id ) ) ) {
			return array( 'ok' => false, 'message' => __( 'Someone else is changing this item. Try again in a moment.', 'zorderz' ) );
		}
		$actual  = round( $actual, 2 );
		$current = self::recompute( $item_id );
		$delta   = round( $actual - $current, 4 );
		$id      = self::ledger_add( $item_id, $delta, 'CYCLE_COUNT', 'count', '', 'Counted: ' . self::num( $actual ), trim( 'Counted ' . self::num( $actual ) . ', was ' . self::num( $current ) . '. ' . $notes ), $uid );
		if ( $id ) {
			$tbl = ZSTOCK_DB::stock_table();
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is $wpdb->prefix plus a fixed name; values are placeholders.
			$wpdb->query( $wpdb->prepare( "UPDATE `{$tbl}` SET counted_at = %s WHERE item_id = %s", current_time( 'mysql' ), $item_id ) );
		}
		self::unlock( 'item_' . md5( $item_id ) );
		if ( ! $id ) {
			return array( 'ok' => false, 'message' => __( 'The count could not be saved. Nothing changed.', 'zorderz' ) );
		}
		return array( 'ok' => true, 'ledger_id' => (int) $id, 'new_stock' => $actual, 'delta' => $delta );
	}

	/* ------------------------------------------------------------------ orders */

	/** Supplier + reference, case- and space-insensitive: the same order twice is one order. PURE. */
	public static function order_key( string $supplier, string $ref ): string {
		$norm = function ( $s ) {
			return trim( preg_replace( '/\s+/', ' ', strtolower( (string) $s ) ) );
		};
		return '' === $norm( $ref ) ? '' : sha1( $norm( $supplier ) . '|' . $norm( $ref ) );
	}

	/**
	 * Make an Incoming order. Nothing reaches stock until someone taps Received.
	 *
	 * $o = { supplier, ref?, date? (Y-m-d), source (manual|email|upload), notes?,
	 *        source_key? (a sha1/sha256 hex from the source, e.g. the email; used when there is no order number),
	 *        form_key?   (the New order form's one-time key: a retry of the same form is the same order),
	 *        supplier_match?, ref_cores? (email orders: the supplier-list entry it came under and the digits of every
	 *                    number it carried — ZSTOCK_Suppliers matches later mail on these),
	 *        lines: [ { description, qty, unit?, sku?, item_id? } ] }
	 *
	 * @return array{ok:bool,order_id?:int,duplicate?:bool,message?:string}
	 */
	public static function create_incoming( array $o, int $uid ): array {
		global $wpdb;
		$supplier = trim( sanitize_text_field( (string) ( $o['supplier'] ?? '' ) ) );
		$ref      = trim( sanitize_text_field( (string) ( $o['ref'] ?? '' ) ) );
		$date     = (string) ( $o['date'] ?? '' );
		$source   = (string) ( $o['source'] ?? 'manual' );
		$raw      = (array) ( $o['lines'] ?? array() );
		if ( '' === $supplier || self::len( $supplier ) > 120 ) {
			return array( 'ok' => false, 'message' => __( 'Say which supplier the order is from.', 'zorderz' ) );
		}
		if ( self::len( $ref ) > 100 ) {
			return array( 'ok' => false, 'message' => __( 'The order number is too long.', 'zorderz' ) );
		}
		if ( ! in_array( $source, self::SOURCES, true ) ) {
			return array( 'ok' => false, 'message' => __( 'Unknown order source.', 'zorderz' ) );
		}
		if ( count( $raw ) > self::MAX_LINES ) {
			// Before the loop: a flood of posted lines must not cost a query each before the refusal.
			/* translators: %d: the most lines one order may have */
			return array( 'ok' => false, 'message' => sprintf( __( 'An order can have up to %d lines.', 'zorderz' ), self::MAX_LINES ) );
		}
		if ( '' !== $date && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			$date = '';
		}
		$lines = array();
		foreach ( $raw as $l ) {
			if ( ! is_array( $l ) ) {
				continue;
			}
			$desc = trim( sanitize_text_field( (string) ( $l['description'] ?? '' ) ) );
			$qty  = (float) ( $l['qty'] ?? 0 );
			$item = trim( (string) ( $l['item_id'] ?? '' ) );
			if ( '' !== $item && ! self::item_exists( $item ) ) {
				$item = '';
			}
			if ( '' === $desc && '' !== $item ) {
				$desc = self::item_name( $item );
			}
			if ( '' === $desc ) {
				continue;
			}
			if ( ! is_finite( $qty ) || $qty <= 0 || $qty > self::MAX_QTY ) {
				return array( 'ok' => false, 'message' => __( 'Each line needs how many were ordered.', 'zorderz' ) );
			}
			$lines[] = array(
				'description' => self::cut( $desc, 500 ),
				'qty'         => round( $qty, 2 ),
				'unit'        => self::cut( trim( sanitize_text_field( (string) ( $l['unit'] ?? '' ) ) ), 50 ),
				'sku'         => self::cut( trim( sanitize_text_field( (string) ( $l['sku'] ?? '' ) ) ), 80 ),
				'item_id'     => $item,
			);
		}
		if ( ! $lines ) {
			return array( 'ok' => false, 'message' => __( 'Add at least one line: what was ordered and how many.', 'zorderz' ) );
		}

		// The key that makes a second copy the same order: supplier + number; else the source's own hash (an email);
		// else the form's one-time key (a retry after "no answer").
		$key = self::order_key( $supplier, $ref );
		if ( '' === $key ) {
			$sk = strtolower( (string) ( $o['source_key'] ?? '' ) );
			$fk = strtolower( (string) ( $o['form_key'] ?? '' ) );
			if ( preg_match( '/^[a-f0-9]{40,64}$/', $sk ) ) {
				$key = $sk;
			} elseif ( preg_match( '/^[a-f0-9]{16,64}$/', $fk ) ) {
				$key = sha1( 'form|' . $fk );
			}
		}
		$locked = '' !== $key;
		if ( $locked && ! self::lock( 'order_' . substr( $key, 0, 40 ) ) ) {
			return array( 'ok' => false, 'message' => __( 'That order is being saved already. Check Incoming in a moment.', 'zorderz' ) );
		}
		$done = function ( array $r ) use ( $locked, $key ) {
			if ( $locked ) {
				self::unlock( 'order_' . substr( $key, 0, 40 ) );
			}
			return $r;
		};
		$orders = ZSTOCK_DB::orders_table();
		if ( '' !== $key ) {
			$dup = (int) $wpdb->get_var( $wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is $wpdb->prefix plus a fixed name; the key is a placeholder.
				"SELECT id FROM `{$orders}` WHERE source_key = %s AND status <> 'rejected' ORDER BY id ASC LIMIT 1",
				$key
			) );
			if ( $dup ) {
				/* translators: %d: order number in Stock */
				return $done( array( 'ok' => false, 'duplicate' => true, 'order_id' => $dup, 'message' => sprintf( __( 'That order is already in Stock (order %d).', 'zorderz' ), $dup ) ) );
			}
		}

		// The order and its lines together, or not at all.
		$now = current_time( 'mysql' );
		$wpdb->query( 'START TRANSACTION' );
		$ok = $wpdb->insert(
			$orders,
			array(
				'supplier_name'  => $supplier,
				'invoice_number' => $ref,
				'invoice_date'   => '' === $date ? null : $date,
				'total_amount'   => 0,
				'status'         => 'incoming',
				'source'         => $source,
				'source_key'     => $key,
				'created_by'     => $uid,
				'supplier_match' => self::cut( strtolower( trim( (string) ( $o['supplier_match'] ?? '' ) ) ), 120 ),
				'ref_cores'      => self::cores_field( (array) ( $o['ref_cores'] ?? array() ) ),
				'notes'          => sanitize_text_field( (string) ( $o['notes'] ?? '' ) ),
				'created_at'     => $now,
			)
		);
		$order_id = $ok ? (int) $wpdb->insert_id : 0;
		foreach ( $lines as $l ) {
			if ( ! $order_id ) {
				break;
			}
			$ok = $wpdb->insert(
				ZSTOCK_DB::order_items_table(),
				array(
					'order_id'             => $order_id,
					'item_id'              => $l['item_id'],
					'sku'                  => $l['sku'],
					'supplier_description' => $l['description'],
					'quantity'             => $l['qty'],
					'unit'                 => '' !== $l['unit'] ? $l['unit'] : 'each',
					'confidence'           => '' !== $l['item_id'] ? 'HIGH' : 'LOW',
					'matched'              => '' !== $l['item_id'] ? 1 : 0,
					'created_at'           => $now,
				)
			);
			if ( ! $ok ) {
				$order_id = 0;
			}
		}
		if ( ! $order_id ) {
			$wpdb->query( 'ROLLBACK' );
			return $done( array( 'ok' => false, 'message' => __( 'The order could not be saved. Nothing was added.', 'zorderz' ) ) );
		}
		$wpdb->query( 'COMMIT' );
		return $done( array( 'ok' => true, 'order_id' => $order_id ) );
	}

	/**
	 * Received: the box is here. $picks = { line_id => { item_id, qty } } for this order's lines; a line with no item
	 * or qty 0 is skipped (not ours, or did not come). $more_coming keeps the order open as `partial`. $seen is how
	 * many receipts the person's screen showed: if another receipt happened since, nothing is written. $extra =
	 * [ { item_id, qty } ] are items that came but are not a line of the order (an email whose lines could not be
	 * read): each becomes a new line, received in the same transaction. One SUPPLIER_ORDER row per line.
	 *
	 * @return array{ok:bool,message?:string,lines?:int,already?:bool,status?:string}
	 */
	public static function receive( int $order_id, array $picks, int $uid, bool $more_coming = false, ?int $seen = null, array $extra = array() ): array {
		global $wpdb;
		$order = self::get( $order_id );
		if ( ! $order ) {
			return array( 'ok' => false, 'message' => __( 'That order is not in Stock.', 'zorderz' ) );
		}
		if ( ! in_array( $order['status'], self::OPEN_STATUSES, true ) ) {
			return self::already( $order );
		}
		$receipts = (int) ( $order['receipts'] ?? 0 );
		if ( null !== $seen && $seen !== $receipts ) {
			return self::already( $order, true );
		}
		$by_line = array();
		foreach ( $order['lines'] as $l ) {
			$by_line[ (int) $l['id'] ] = $l;
		}
		$use = array();
		foreach ( $picks as $line_id => $p ) {
			$line_id = (int) $line_id;
			if ( ! isset( $by_line[ $line_id ] ) || ! is_array( $p ) ) {
				return array( 'ok' => false, 'message' => __( 'A line does not belong to this order. Nothing was added.', 'zorderz' ) );
			}
			$item = trim( (string) ( $p['item_id'] ?? '' ) );
			$qty  = (float) ( $p['qty'] ?? 0 );
			if ( ! is_finite( $qty ) || $qty < 0 || $qty > self::MAX_QTY ) {
				return array( 'ok' => false, 'message' => __( 'How many came must be 0 or more.', 'zorderz' ) );
			}
			if ( '' === $item || $qty <= 0 ) {
				continue;
			}
			if ( ! self::item_exists( $item ) ) {
				return array( 'ok' => false, 'message' => __( 'A line points at an item that is not in the stock list. Nothing was added.', 'zorderz' ) );
			}
			$use[ $line_id ] = array( 'item_id' => $item, 'qty' => round( $qty, 2 ) );
		}
		if ( count( $extra ) > self::MAX_EXTRA ) {
			/* translators: %d: the most extra items at a time */
			return array( 'ok' => false, 'message' => sprintf( __( 'Add up to %d more items at a time.', 'zorderz' ), self::MAX_EXTRA ) );
		}
		$add = array();
		foreach ( $extra as $x ) {
			if ( ! is_array( $x ) ) {
				continue;
			}
			$item = trim( (string) ( $x['item_id'] ?? '' ) );
			$qty  = (float) ( $x['qty'] ?? 0 );
			if ( '' === $item && $qty <= 0 ) {
				continue; // an empty row
			}
			if ( ! is_finite( $qty ) || $qty <= 0 || $qty > self::MAX_QTY || '' === $item ) {
				return array( 'ok' => false, 'message' => __( 'Each added item needs the stock item and how many came.', 'zorderz' ) );
			}
			if ( ! self::item_exists( $item ) ) {
				return array( 'ok' => false, 'message' => __( 'A line points at an item that is not in the stock list. Nothing was added.', 'zorderz' ) );
			}
			$add[] = array( 'item_id' => $item, 'qty' => round( $qty, 2 ) );
		}
		if ( ! $use && ! $add ) {
			return array( 'ok' => false, 'message' => __( 'Pick the stock item for at least one line and how many came.', 'zorderz' ) );
		}

		$status  = $more_coming ? 'partial' : 'received';
		$orders  = ZSTOCK_DB::orders_table();
		$lines_t = ZSTOCK_DB::order_items_table();
		$wpdb->query( 'START TRANSACTION' );
		$now     = current_time( 'mysql' );
		$claimed = $wpdb->query( $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is $wpdb->prefix plus a fixed name; values are placeholders.
			"UPDATE `{$orders}` SET status = %s, receipts = receipts + 1, received_by = %d, received_at = %s WHERE id = %d AND status IN ('incoming','draft','partial') AND receipts = %d",
			$status,
			$uid,
			$now,
			$order_id,
			$receipts
		) );
		if ( false === $claimed ) {
			// A database error is not "someone else got there first".
			$err = (string) $wpdb->last_error; // before ROLLBACK, which clears it
			$wpdb->query( 'ROLLBACK' );
			zstock_log( 'receive claim failed for order ' . $order_id . ': ' . $err );
			return array( 'ok' => false, 'message' => __( 'Stock could not be updated. Nothing was added; try again.', 'zorderz' ) );
		}
		if ( 1 !== (int) $claimed ) {
			$wpdb->query( 'ROLLBACK' );
			return self::already( self::get( $order_id ) ?: $order, true );
		}
		$label = self::label( $order );
		$items = array();
		foreach ( $use as $line_id => $u ) {
			$id = self::ledger_add( $u['item_id'], $u['qty'], 'SUPPLIER_ORDER', 'supplier_order', (string) $order_id, $label, 'Line: ' . $by_line[ $line_id ]['supplier_description'], $uid, false );
			$up = $id ? $wpdb->query( $wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is $wpdb->prefix plus a fixed name; values are placeholders.
				"UPDATE `{$lines_t}` SET item_id = %s, matched = 1, received_qty = COALESCE(received_qty, 0) + %f WHERE id = %d",
				$u['item_id'],
				$u['qty'],
				$line_id
			) ) : false;
			if ( ! $id || false === $up ) {
				$wpdb->query( 'ROLLBACK' );
				return array( 'ok' => false, 'message' => __( 'Stock could not be updated. Nothing was added; try again.', 'zorderz' ) );
			}
			$items[ $u['item_id'] ] = true;
		}
		foreach ( $add as $u ) {
			$view = class_exists( 'ZSTOCK_Catalog' ) ? ZSTOCK_Catalog::view( $u['item_id'] ) : null;
			$ok   = $wpdb->insert( $lines_t, array(
				'order_id'             => $order_id,
				'item_id'              => $u['item_id'],
				'supplier_description' => self::cut( (string) ( $view['name'] ?? 'Item' ), 500 ),
				'quantity'             => $u['qty'],
				'received_qty'         => $u['qty'],
				'unit'                 => self::cut( (string) ( $view['unit'] ?? 'each' ), 50 ),
				'sku'                  => '',
				'confidence'           => 'HIGH',
				'matched'              => 1,
				'created_at'           => $now,
			) );
			$id = $ok ? self::ledger_add( $u['item_id'], $u['qty'], 'SUPPLIER_ORDER', 'supplier_order', (string) $order_id, $label, 'Added on Received', $uid, false ) : false;
			if ( ! $ok || ! $id ) {
				$wpdb->query( 'ROLLBACK' );
				return array( 'ok' => false, 'message' => __( 'Stock could not be updated. Nothing was added; try again.', 'zorderz' ) );
			}
			$items[ $u['item_id'] ] = true;
		}
		$wpdb->query( 'COMMIT' );
		// After COMMIT, so the rows just written are what the sum sees.
		foreach ( array_keys( $items ) as $iid ) {
			self::recompute( (string) $iid );
		}
		return array( 'ok' => true, 'lines' => count( $use ) + count( $add ), 'status' => $status );
	}

	/** Part came, and nothing more is coming: the open order closes as received. Stock does not change. */
	public static function close_rest( int $order_id, int $uid, ?int $seen = null ): array {
		global $wpdb;
		$order = self::get( $order_id );
		if ( ! $order ) {
			return array( 'ok' => false, 'message' => __( 'That order is not in Stock.', 'zorderz' ) );
		}
		if ( in_array( $order['status'], array( 'incoming', 'draft' ), true ) ) {
			return array( 'ok' => false, 'message' => __( 'Nothing has come for this order yet. If it is not coming, use Not ours.', 'zorderz' ) );
		}
		if ( 'partial' !== $order['status'] ) {
			return self::already( $order );
		}
		$receipts = (int) ( $order['receipts'] ?? 0 );
		if ( null !== $seen && $seen !== $receipts ) {
			return self::already( $order, true );
		}
		$orders = ZSTOCK_DB::orders_table();
		// Who closed it, in its own columns: received_by stays the person who last took delivery.
		$n = $wpdb->query( $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is $wpdb->prefix plus a fixed name; values are placeholders.
			"UPDATE `{$orders}` SET status = 'received', closed_by = %d, closed_at = %s WHERE id = %d AND status = 'partial' AND receipts = %d",
			$uid,
			current_time( 'mysql' ),
			$order_id,
			$receipts
		) );
		if ( 1 !== (int) $n ) {
			return self::already( self::get( $order_id ) ?: $order, true );
		}
		return array( 'ok' => true );
	}

	/**
	 * It came, but nothing in it is kept in stock (freight, a part for one job). Closes an order nothing has been
	 * received for as received, with who and when, without touching stock. (Not ours is for an order that is not
	 * ours or is not coming.)
	 */
	public static function close_nothing( int $order_id, int $uid ): array {
		global $wpdb;
		$order = self::get( $order_id );
		if ( ! $order ) {
			return array( 'ok' => false, 'message' => __( 'That order is not in Stock.', 'zorderz' ) );
		}
		if ( ! in_array( $order['status'], array( 'incoming', 'draft' ), true ) ) {
			return self::already( $order );
		}
		$orders = ZSTOCK_DB::orders_table();
		$n      = $wpdb->query( $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is $wpdb->prefix plus a fixed name; values are placeholders.
			"UPDATE `{$orders}` SET status = 'received', closed_by = %d, closed_at = %s, notes = %s WHERE id = %d AND status IN ('incoming','draft') AND receipts = 0",
			$uid,
			current_time( 'mysql' ),
			self::cut( trim( (string) $order['notes'] . ' Arrived; nothing in it goes into stock.' ), 1000 ),
			$order_id
		) );
		if ( 1 !== (int) $n ) {
			return self::already( self::get( $order_id ) ?: $order, true );
		}
		return array( 'ok' => true );
	}

	/** Not ours / not coming: an order nothing has come for closes without touching stock. */
	public static function reject( int $order_id, string $reason, int $uid ): array {
		global $wpdb;
		$order = self::get( $order_id );
		if ( ! $order ) {
			return array( 'ok' => false, 'message' => __( 'That order is not in Stock.', 'zorderz' ) );
		}
		if ( 'partial' === $order['status'] ) {
			return array( 'ok' => false, 'already' => true, 'message' => __( 'Part of this order already came. Use Nothing more coming to close it.', 'zorderz' ) );
		}
		$orders = ZSTOCK_DB::orders_table();
		$n      = $wpdb->query( $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is $wpdb->prefix plus a fixed name; values are placeholders.
			"UPDATE `{$orders}` SET status = 'rejected', rejected_by = %d, rejected_at = %s, reject_reason = %s WHERE id = %d AND status IN ('incoming','draft')",
			$uid,
			current_time( 'mysql' ),
			self::cut( sanitize_text_field( $reason ), 255 ),
			$order_id
		) );
		if ( 1 !== (int) $n ) {
			return self::already( self::get( $order_id ) ?: $order );
		}
		return array( 'ok' => true );
	}

	/** One order with its lines (and each line's stock item name). */
	public static function get( int $order_id ): ?array {
		global $wpdb;
		if ( $order_id <= 0 ) {
			return null;
		}
		$orders = ZSTOCK_DB::orders_table();
		$lines  = ZSTOCK_DB::order_items_table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is $wpdb->prefix plus a fixed name; the id is a placeholder.
		$o = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$orders}` WHERE id = %d", $order_id ), ARRAY_A );
		if ( ! $o ) {
			return null;
		}
		$rows = (array) $wpdb->get_results( $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is $wpdb->prefix plus a fixed name; the id is a placeholder.
			"SELECT id, supplier_description, item_id, quantity, unit, sku, received_qty FROM `{$lines}` WHERE order_id = %d ORDER BY id ASC",
			$order_id
		), ARRAY_A );
		foreach ( $rows as &$r ) {
			$iid             = (string) ( $r['item_id'] ?? '' );
			$view            = ( '' !== $iid && class_exists( 'ZSTOCK_Catalog' ) ) ? ZSTOCK_Catalog::view( $iid ) : null;
			$r['item_name']  = is_array( $view ) ? (string) $view['name'] : '';
			$r['item_unit']  = is_array( $view ) ? (string) $view['unit'] : '';
		}
		unset( $r );
		$o['lines'] = $rows;
		return $o;
	}

	/** Orders still waiting for someone to say they came (all or the rest), newest first, with their lines. */
	public static function incoming( int $limit = 10, bool $with_email = true ): array {
		global $wpdb;
		$orders = ZSTOCK_DB::orders_table();
		$ids    = (array) $wpdb->get_col( $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- table name is $wpdb->prefix plus a fixed name; the optional clause is a literal; the limit is a placeholder.
			"SELECT id FROM `{$orders}` WHERE status IN ('incoming','draft','partial')" . ( $with_email ? '' : " AND source <> 'email'" ) . ' ORDER BY created_at DESC, id DESC LIMIT %d',
			max( 1, min( 50, $limit ) )
		) );
		$out = array();
		foreach ( $ids as $id ) {
			$o = self::get( (int) $id );
			if ( $o ) {
				$out[] = self::public_order( $o );
			}
		}
		return $out;
	}

	/** How many orders are waiting (all or the rest). */
	public static function open_count( bool $with_email = true ): int {
		global $wpdb;
		$orders = ZSTOCK_DB::orders_table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- table name is $wpdb->prefix plus a fixed name; the optional clause is a literal; no input.
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$orders}` WHERE status IN ('incoming','draft','partial')" . ( $with_email ? '' : " AND source <> 'email'" ) );
	}

	/** What the widget needs; nothing it does not (no raw Ai text, no file URL, no money). PURE apart from the name lookup. */
	public static function public_order( array $o ): array {
		$lines = array();
		foreach ( (array) ( $o['lines'] ?? array() ) as $l ) {
			$got     = (float) ( $l['received_qty'] ?? 0 );
			$lines[] = array(
				'id'          => (int) $l['id'],
				'description' => (string) $l['supplier_description'],
				'qty'         => (float) $l['quantity'],
				'received'    => $got,
				'remaining'   => max( 0, round( (float) $l['quantity'] - $got, 2 ) ),
				'unit'        => (string) ( $l['unit'] ?? '' ),
				'item_id'     => (string) ( $l['item_id'] ?? '' ),
				'item_name'   => (string) ( $l['item_name'] ?? '' ),
			);
		}
		$from = '';
		if ( 'email' === ( $o['source'] ?? '' ) && ! empty( $o['created_by'] ) && function_exists( 'get_userdata' ) ) {
			// Whose mailbox it came from: a colleague's name, never the email itself.
			$u    = get_userdata( (int) $o['created_by'] );
			$from = $u ? (string) $u->display_name : '';
		}
		return array(
			'id'         => (int) $o['id'],
			'supplier'   => (string) $o['supplier_name'],
			'ref'        => (string) $o['invoice_number'],
			'date'       => (string) ( $o['invoice_date'] ?? '' ),
			'status'     => 'draft' === $o['status'] ? 'incoming' : (string) $o['status'],
			'receipts'   => (int) ( $o['receipts'] ?? 0 ),
			'source'     => (string) ( $o['source'] ?? 'upload' ),
			'from'       => $from,
			'created_at' => (string) $o['created_at'],
			'lines'      => $lines,
		);
	}

	/* ------------------------------------------------------------------ helpers */

	/** "|10422|7781|" from [ '10422', '7781' ]: digit strings of 3-20, each once, the field at most 255. PURE. */
	public static function cores_field( array $cores ): string {
		$keep = array();
		foreach ( $cores as $c ) {
			$c = (string) $c;
			if ( preg_match( '/^[0-9]{3,20}$/', $c ) && ! in_array( $c, $keep, true ) && strlen( '|' . implode( '|', array_merge( $keep, array( $c ) ) ) . '|' ) <= 255 ) {
				$keep[] = $c;
			}
		}
		return $keep ? '|' . implode( '|', $keep ) . '|' : '';
	}

	private static function already( array $order, bool $moved = false ): array {
		if ( in_array( $order['status'], array( 'received', 'approved' ), true ) ) {
			$who = ( ! empty( $order['received_by'] ) && function_exists( 'get_userdata' ) ) ? get_userdata( (int) $order['received_by'] ) : null;
			return array(
				'ok'      => false,
				'already' => true,
				'message' => $who
					/* translators: %s: who received the order */
					? sprintf( __( 'Already received by %s. Nothing was added again.', 'zorderz' ), $who->display_name )
					: __( 'Already received. Nothing was added again.', 'zorderz' ),
			);
		}
		if ( 'rejected' === $order['status'] ) {
			return array( 'ok' => false, 'already' => true, 'message' => __( 'That order was marked Not ours. Nothing was added.', 'zorderz' ) );
		}
		if ( $moved ) {
			return array( 'ok' => false, 'already' => true, 'message' => __( 'Someone just received part of this order. Check what is still to come, then try again. Nothing was added.', 'zorderz' ) );
		}
		return array( 'ok' => false, 'already' => true, 'message' => __( 'That order is no longer waiting.', 'zorderz' ) );
	}

	public static function label( array $order ): string {
		$ref = '' !== (string) $order['invoice_number'] ? ' #' . $order['invoice_number'] : '';
		return self::cut( 'Order ' . (int) $order['id'] . ' · ' . $order['supplier_name'] . $ref, 255 );
	}

	public static function num( float $n ): string {
		return ( floor( $n ) == $n ) ? (string) (int) $n : rtrim( rtrim( number_format( $n, 2, '.', '' ), '0' ), '.' ); // phpcs:ignore Universal.Operators.StrictComparisons
	}

	/** Cut to $n characters, never through a character (a byte cut can make an insert fail). */
	public static function cut( string $s, int $n ): string {
		return function_exists( 'mb_substr' ) ? mb_substr( $s, 0, $n, 'UTF-8' ) : substr( $s, 0, $n );
	}

	private static function len( string $s ): int {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $s, 'UTF-8' ) : strlen( $s );
	}
}
