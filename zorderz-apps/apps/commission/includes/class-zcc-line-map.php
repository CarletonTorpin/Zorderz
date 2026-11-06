<?php
/**
 * ZCC_Line_Map — line texts a person has confirmed as a catalog item.
 *
 * Some billing lines are written in words no catalog alias covers. An administrator can say
 * once "this line is item X" (often after reading an Ai proposal, ZCC_Ai) and from then on the
 * classifier reads that exact line text as item X. Only a person writes here; nothing in the
 * pay path ever asks a model.
 *
 * Stored in one option (`zcc_line_map`, not autoloaded): md5 of the normalised line text =>
 * { item_id, by, at }. The text itself is not kept, so a customer name that appears in a line
 * is never stored by this map. A version number rises with every change so the classifier's
 * cache lets go of the old answer.
 *
 * @package Zorderz\Commission
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZCC_Line_Map {

	const OPTION     = 'zcc_line_map';
	const VER_OPTION = 'zcc_line_map_version';
	const MAX        = 5000;

	/** The key a line text is stored under: md5 of its lower-case, single-spaced form. */
	public static function key( string $text ): string {
		$t = strtolower( trim( (string) preg_replace( '/\s+/u', ' ', $text ) ) );
		return '' === $t ? '' : md5( $t );
	}

	public static function all(): array {
		$m = function_exists( 'get_option' ) ? get_option( self::OPTION, [] ) : [];
		return is_array( $m ) ? $m : [];
	}

	public static function version(): int {
		return function_exists( 'get_option' ) ? (int) get_option( self::VER_OPTION, 0 ) : 0;
	}

	/** The confirmed item id for this line text, '' when none. */
	public static function get( string $text ): string {
		$k = self::key( $text );
		if ( '' === $k ) {
			return '';
		}
		$row = self::all()[ $k ] ?? null;
		return is_array( $row ) ? sanitize_key( (string) ( $row['item_id'] ?? '' ) ) : '';
	}

	/** The confirmed item id stored under a key, '' when none. */
	public static function get_by_key( string $key ): string {
		$row = self::all()[ $key ] ?? null;
		return is_array( $row ) ? sanitize_key( (string) ( $row['item_id'] ?? '' ) ) : '';
	}

	/**
	 * A person confirms a line (by its key) as an item. The item must exist in the catalog.
	 *
	 * @return array{ok:bool,error?:string}
	 */
	public static function confirm( string $key, string $item_id, int $by ): array {
		$item_id = sanitize_key( $item_id );
		if ( ! preg_match( '/^[a-f0-9]{32}$/', $key ) ) {
			return [ 'ok' => false, 'error' => 'bad_key' ];
		}
		if ( $by <= 0 ) {
			return [ 'ok' => false, 'error' => 'no_person' ];
		}
		if ( '' === $item_id || ! is_array( zcc_item_get( $item_id ) ) ) {
			return [ 'ok' => false, 'error' => 'unknown_item' ];
		}
		$all = self::all();
		if ( ! isset( $all[ $key ] ) && count( $all ) >= self::MAX ) {
			return [ 'ok' => false, 'error' => 'full' ];
		}
		$all[ $key ] = [ 'item_id' => $item_id, 'by' => $by, 'at' => time() ];
		update_option( self::OPTION, $all, false );
		update_option( self::VER_OPTION, self::version() + 1, false );
		if ( class_exists( 'ZCC_Classifier' ) ) {
			ZCC_Classifier::flush();
		}
		return [ 'ok' => true ];
	}

	/** Forget a confirmed mapping. */
	public static function forget( string $key ): array {
		$all = self::all();
		if ( ! isset( $all[ $key ] ) ) {
			return [ 'ok' => false, 'error' => 'not_found' ];
		}
		unset( $all[ $key ] );
		update_option( self::OPTION, $all, false );
		update_option( self::VER_OPTION, self::version() + 1, false );
		if ( class_exists( 'ZCC_Classifier' ) ) {
			ZCC_Classifier::flush();
		}
		return [ 'ok' => true ];
	}
}
