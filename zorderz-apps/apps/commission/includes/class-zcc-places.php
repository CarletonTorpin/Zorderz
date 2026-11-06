<?php
/**
 * ZCC_Places — the places a business works in, so a place name is never read as a product.
 *
 * A billing line often names the job's town ("Exampleton - (AB) repair") and some product names
 * are also town names. Read naively, the town becomes the product and the job carries that
 * product's cost. So before a line is classified, place names are taken out of its text:
 *
 *   - a place of two or more words is always removed (its words are a place, whatever they are);
 *   - a one-word place is removed where a place sits — right after a location label
 *     ("Location —", "Location:"), or right before an attribution code ("- (AB)", "(AB)") — or
 *     when nothing else on the line says it is a product: no size ("36 x 80") and none of the
 *     business's product-context words;
 *   - the billing ITEM name is never changed: an item named after a place-like product is that
 *     product.
 *
 * The list is the business's service area: option `zcc_places` (a list of names), the
 * multi-word places ZDZ_Private already knows, and the `zcc_places` filter. Product-context
 * words come from option `zcc_product_context_words` and the filter of the same name. Core
 * ships both empty, so this is a no-op until a business fills them (an Identity Pack's
 * territories or rules file, or the settings screen).
 *
 * Pure string work: no I/O beyond reading the two lists, no model.
 *
 * @package Zorderz\Commission
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZCC_Places {

	const OPTION         = 'zcc_places';
	const CONTEXT_OPTION = 'zcc_product_context_words';

	/** Request cache: [ places (longest first), context words ]. */
	private static $lists = null;

	/** Forget the cached lists (after a settings save; between tests). */
	public static function flush(): void {
		self::$lists = null;
	}

	/** The places, lower case, longest first. Empty unless the business sets them. */
	public static function places(): array {
		return self::lists()[0];
	}

	/** The business's product-context words, lower case. */
	public static function context_words(): array {
		return self::lists()[1];
	}

	private static function lists(): array {
		if ( null !== self::$lists ) {
			return self::$lists;
		}
		$raw = function_exists( 'get_option' ) ? get_option( self::OPTION, [] ) : [];
		$raw = is_array( $raw ) ? $raw : [];
		if ( class_exists( 'ZDZ_Private' ) && method_exists( 'ZDZ_Private', 'places' ) ) {
			$raw = array_merge( $raw, (array) ZDZ_Private::places() );
		}
		$raw    = (array) apply_filters( 'zcc_places', $raw );
		$places = [];
		foreach ( $raw as $p ) {
			$p = self::norm( is_scalar( $p ) ? (string) $p : '' );
			if ( '' !== $p ) {
				$places[ $p ] = true;
			}
		}
		$places = array_keys( $places );
		usort( $places, static function ( $a, $b ) {
			return strlen( $b ) - strlen( $a );
		} );

		$ctx = function_exists( 'get_option' ) ? get_option( self::CONTEXT_OPTION, [] ) : [];
		$ctx = (array) apply_filters( 'zcc_product_context_words', is_array( $ctx ) ? $ctx : [] );
		$words = [];
		foreach ( $ctx as $w ) {
			$w = self::norm( is_scalar( $w ) ? (string) $w : '' );
			if ( '' !== $w ) {
				$words[ $w ] = true;
			}
		}
		return self::$lists = [ $places, array_keys( $words ) ];
	}

	/** Lower case, single spaces, hyphens as spaces. */
	public static function norm( string $s ): string {
		$s = strtolower( trim( $s ) );
		$s = str_replace( [ '-', "\t" ], ' ', $s );
		return trim( (string) preg_replace( '/\s+/', ' ', $s ) );
	}

	/** Does this text name any of the business's places? */
	public static function mentions_place( string $text ): bool {
		$lower = strtolower( $text );
		foreach ( self::places() as $p ) {
			if ( preg_match( self::word_re( $p ), $lower ) ) {
				return true;
			}
		}
		return false;
	}

	/** Does the line say it is a product: a size, or one of the business's context words? */
	public static function has_product_context( string $lower ): bool {
		if ( preg_match( '/\d\s*(?:"|in\b|inch\w*|\')?\s*(?:x|×|by)\s*\d/u', $lower ) ) {
			return true;
		}
		foreach ( self::context_words() as $w ) {
			if ( preg_match( self::word_re( $w ), $lower ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The text with place names taken out by the rules above. Case is kept for everything
	 * else (a later coder finds names and addresses by their capitals).
	 */
	public static function neutralize( string $text ): string {
		$places = self::places();
		if ( ! $places || '' === trim( $text ) ) {
			return $text;
		}
		$lower   = strtolower( $text );
		$context = self::has_product_context( $lower );
		foreach ( $places as $p ) {
			$re = self::word_re( $p );
			if ( false !== strpos( $p, ' ' ) ) {
				$text = (string) preg_replace( $re, ' ', $text );
				continue;
			}
			if ( ! $context ) {
				$text = (string) preg_replace( $re, ' ', $text );
				continue;
			}
			// Where a place sits: after a location label, or just before an attribution code.
			$q    = preg_quote( $p, '/' );
			$text = (string) preg_replace( '/(\blocation\b\s*[:\x{2014}\x{2013}-]?\s*)' . $q . '\b/iu', '$1 ', $text );
			$text = (string) preg_replace( '/\b' . $q . '(\s*[-\x{2013}\x{2014}]?\s*\([A-Za-z]{2,4}\))/iu', ' $1', $text );
		}
		return trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
	}

	/**
	 * The text a line is classified on: its billing item name as written, then its sub-line
	 * with places taken out. A line with no item name is neutralised whole.
	 */
	public static function line_text( array $line ): string {
		$item = trim( (string) ( $line['item_name'] ?? $line['desc_head'] ?? '' ) );
		$sub  = (string) ( $line['desc_sub'] ?? '' );
		if ( '' === $item && '' === $sub ) {
			return self::neutralize( (string) ( $line['description'] ?? '' ) );
		}
		$sub = self::neutralize( $sub );
		return trim( $item . ( '' !== $sub ? ' ' . $sub : '' ) );
	}

	/** Whole-word, hyphen-or-space tolerant pattern for a normalised phrase. */
	private static function word_re( string $phrase ): string {
		$parts = array_map( static function ( $w ) {
			return preg_quote( $w, '/' );
		}, explode( ' ', $phrase ) );
		return '/\b' . implode( '[\s\-]+', $parts ) . '\b/iu';
	}
}
