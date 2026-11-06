<?php
/**
 * ZCC_Review — the money nobody is paid on until a person looks.
 *
 * An invoice whose sale or job has no attribution, or people its records disagree on, is a
 * review item (ZCC_Attribution). So are units held back because an installer is not marked
 * able to install an item. Each is kept here until an administrator settles it (an override
 * row, a recorded installer, a Team Roles mark) or dismisses it.
 *
 * One option (`zcc_review_items`, not autoloaded): key "role:invoice_id[:item]" => { the
 * review row (ids, invoice number, reason, people ids, codes), first_seen, last_seen }. No
 * customer name, address or amount is kept. Writes happen only when something is new, so a
 * calculation that finds nothing new writes nothing.
 *
 * @package Zorderz\Commission
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZCC_Review {

	const OPTION = 'zcc_review_items';
	const MAX    = 500;

	public static function key( array $row ): string {
		$k = (string) ( $row['role'] ?? 'sale' ) . ':' . (int) ( $row['invoice_id'] ?? 0 );
		if ( ! empty( $row['item_id'] ) ) {
			$k .= ':' . sanitize_key( (string) $row['item_id'] );
		}
		return $k;
	}

	public static function open(): array {
		$v = function_exists( 'get_option' ) ? get_option( self::OPTION, [] ) : [];
		return is_array( $v ) ? $v : [];
	}

	/**
	 * Note review rows. Only new keys (or a changed reason) are written.
	 *
	 * @return int How many rows were new.
	 */
	public static function note( array $rows ): int {
		$all  = self::open();
		$new  = 0;
		$now  = time();
		$dirt = false;
		foreach ( $rows as $r ) {
			if ( ! is_array( $r ) || (int) ( $r['invoice_id'] ?? 0 ) <= 0 ) {
				continue;
			}
			$k = self::key( $r );
			if ( isset( $all[ $k ] ) && ( $all[ $k ]['reason'] ?? '' ) === ( $r['reason'] ?? '' ) ) {
				continue;
			}
			if ( ! isset( $all[ $k ] ) ) {
				$new++;
			}
			$all[ $k ] = [
				'invoice_id'     => (int) $r['invoice_id'],
				'invoice_number' => substr( (string) ( $r['invoice_number'] ?? '' ), 0, 40 ),
				'role'           => (string) ( $r['role'] ?? 'sale' ),
				'reason'         => sanitize_key( (string) ( $r['reason'] ?? '' ) ),
				'people'         => array_map( 'intval', (array) ( $r['people'] ?? [] ) ),
				'codes'          => array_map( 'strval', (array) ( $r['codes'] ?? [] ) ),
				'item_id'        => sanitize_key( (string) ( $r['item_id'] ?? '' ) ),
				'units'          => (int) ( $r['units'] ?? 0 ),
				'first_seen'     => (int) ( $all[ $k ]['first_seen'] ?? $now ),
				'last_seen'      => $now,
			];
			$dirt = true;
		}
		if ( $dirt ) {
			if ( count( $all ) > self::MAX ) {
				uasort( $all, static function ( $a, $b ) {
					return (int) $b['last_seen'] - (int) $a['last_seen'];
				} );
				$all = array_slice( $all, 0, self::MAX, true );
			}
			update_option( self::OPTION, $all, false );
		}
		return $new;
	}

	/** An administrator settles or dismisses one item. */
	public static function dismiss( string $key ): bool {
		$all = self::open();
		if ( ! isset( $all[ $key ] ) ) {
			return false;
		}
		unset( $all[ $key ] );
		update_option( self::OPTION, $all, false );
		return true;
	}
}
