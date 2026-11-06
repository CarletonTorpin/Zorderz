<?php
/**
 * ZAGR_Words - the six-word link, /agreement/<six words>/. [CORE]
 *
 * Six words from a curated list of 1,038 (about 60 bits), drawn from the operating system's random source. A link is
 * looked up by the SHA-256 of its words and the words themselves are kept only sealed under the agreements key, so a
 * copy of the database alone opens no agreement.
 *
 * @package Zorderz\Agreements
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZAGR_Words {

	const COUNT = 6;
	const FLOOR = 1000; // a shorter list is refused: it would make links easier to guess

	private static $list = null;

	/** @return string[] the curated list (malformed entries dropped, so a bad file can't shrink the space silently) */
	public static function wordlist(): array {
		if ( null === self::$list ) {
			$file = ZAGR_DIR . 'data/share-wordlist.php';
			$list = is_readable( $file ) ? ( require $file ) : array();
			self::$list = array_values( array_unique( array_filter( (array) $list, static function ( $w ) {
				return is_string( $w ) && preg_match( '/^[a-z]{3,6}$/', $w );
			} ) ) );
		}
		return self::$list;
	}

	/** Six words, hyphen-joined, from the OS CSPRNG; '' when the list is under the floor (refuse then). */
	public static function generate(): string {
		$list = self::wordlist();
		$n    = count( $list );
		if ( $n < self::FLOOR ) {
			return '';
		}
		$picked = array();
		for ( $i = 0; $i < self::COUNT; $i++ ) {
			$picked[] = $list[ random_int( 0, $n - 1 ) ];
		}
		return implode( '-', $picked );
	}

	/** Lowercase, spaces or underscores to hyphens, letters and hyphens only (forgiving of copy and paste). */
	public static function normalize( string $t ): string {
		$t = strtolower( trim( $t ) );
		$t = (string) preg_replace( '/[\s_+]+/', '-', $t );
		$t = (string) preg_replace( '/[^a-z-]/', '', $t );
		$t = (string) preg_replace( '/-+/', '-', $t );
		return trim( $t, '-' );
	}

	/** Exactly six list-shaped words? (A cheap shape check before any database read.) */
	public static function well_formed( string $normalized ): bool {
		return (bool) preg_match( '/^[a-z]{3,6}(?:-[a-z]{3,6}){5}$/', $normalized );
	}

	/** The lookup hash of a link's words. */
	public static function hash( string $normalized ): string {
		return hash( 'sha256', 'zagr-link-v1|' . $normalized );
	}

	/** The public address for a link's words. */
	public static function url( string $words ): string {
		return home_url( '/agreement/' . $words . '/' );
	}
}
