<?php
/**
 * ZEST_Contract_Address - the job's address as a contract prints it, for both the online agreement's buyer
 * (ZEST_Terms_Note::buyer) and the paper sale form (ZEST_Sale_Form::facts). One helper, so the two can't drift apart.
 *
 *  - The street. A billing system's second street line often holds the client's phone; joining street and street2 for
 *    a contract would put the phone in the job's address. A line that is only phone numbers (with phone words and short
 *    labels: "(cell)", "C:", "Phone #:") is left out; a real second line ("Apt 4", "Unit B") stays; a trailing phone
 *    that is the buyer's own comes off either line, with its label only when the label is punctuated ("Cell:") or after
 *    a comma, and never from inside a number ("Apt 1 555-0177" keeps "Apt 1"; "#201" stays "#201").
 *  - The city. Where the place name the app stores for its location line is a neighbourhood, the contract prints the
 *    postal city instead: the business's map ZIP => [ stored name, postal city ] (option zest_postal_cities, from
 *    territories.yml `postal_cities`; filter of the same name). A city stored any other way prints as stored.
 *
 * Pure but for reading that one map. US-style phone numbers (10 digits, an optional +1).
 *
 * @package Zorderz\Estimate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ZEST_Contract_Address {

	const POSTAL_OPT = 'zest_postal_cities';

	/** A phone number: 10 digits as a person writes them, an optional +1 and extension. */
	const PHONE = '(?<![\d+])(?:\+1[\s.-]*)?(?:\(\d{3}\)|\d{3})[\s.-]*\d{3}[\s.-]*\d{4}(?!\d)(?:\s*(?:x|ext\.?)\s*\d{1,6})?';

	/** Words that may stand beside a number on a phone-only line. */
	const WORDS = 'cell|cellphone|mobile|mob|home|work|office|tel|telephone|phone|fax|main|alt|primary|contact|number';

	/** Short labels: a label only when punctuated ("C:"), and before a trailing phone only right after a comma. */
	const SHORT = 'c|h|m|w|p|ph|hm|wk|cl|cel|no';

	const LABEL_PUNCT       = '(?:\b(?:%s)\s*(?:#\s*)?[:#.]\s*)';
	const LABEL_SHORT_COMMA = '(?:,\s*(?:%s)\s*(?:#\s*)?[:#.]\s*)';
	const AFTER             = '(?:\s*\(\s*(?:%s)\s*\)|\s+(?:%s))?\s*';

	/** @var array|null tests set the map directly */
	public static $postal = null;

	private static function one( $v ): string {
		return trim( (string) preg_replace( '/\s+/u', ' ', (string) $v ) );
	}

	/** The 10 digits of a phone ("+1 (555) 010-0177" -> "5550100177"), or '' when it is not one. */
	public static function digits( string $s ): string {
		$d = (string) preg_replace( '/\D/', '', (string) preg_replace( '/\s*(?:x|ext\.?)\s*\d{1,6}\s*$/i', '', $s ) );
		if ( 11 === strlen( $d ) && '1' === $d[0] ) {
			$d = substr( $d, 1 );
		}
		return 10 === strlen( $d ) ? $d : '';
	}

	/** Is this line nothing but phone numbers (and phone words, short punctuated labels, punctuation)? */
	public static function is_phone( string $s ): bool {
		$s = self::one( $s );
		if ( '' === $s ) {
			return false;
		}
		$s    = (string) preg_replace( '/^\+?1[\s.-]+(?=\(?\d{3}\)?[\s.-]*\d{3}[\s.-]*\d{4}(?!\d))/', '', $s );
		$n    = 0;
		$rest = (string) preg_replace( '/' . self::PHONE . '/i', ' ', $s, -1, $n );
		if ( $n < 1 ) {
			return false;
		}
		$rest = (string) preg_replace( '/\b(?:' . self::WORDS . ')\b/i', ' ', $rest );
		$rest = (string) preg_replace( '/\b(?:' . self::SHORT . ')\s*(?:#\s*)?[:#.]/i', ' ', $rest );
		return '' === (string) preg_replace( '/[\s\p{P}\p{S}]+/u', '', $rest );
	}

	/** $line without a trailing phone that is one of the buyer's own ($own: digits => true). */
	private static function strip_own( string $line, array $own ): string {
		if ( ! $own ) {
			return $line;
		}
		$after = sprintf( self::AFTER, self::WORDS, self::WORDS );
		if ( ! preg_match( '/(' . self::PHONE . ')' . $after . '$/i', $line, $m, PREG_OFFSET_CAPTURE ) || ! isset( $own[ self::digits( $m[1][0] ) ] ) ) {
			return $line;
		}
		$head = substr( $line, 0, (int) $m[0][1] );
		$head = (string) preg_replace( '/' . sprintf( self::LABEL_PUNCT, self::WORDS ) . '$/i', '', $head );
		$head = (string) preg_replace( '/' . sprintf( self::LABEL_SHORT_COMMA, self::SHORT ) . '$/i', '', $head );
		$head = (string) preg_replace( '/,\s*(?:' . self::WORDS . ')\s*$/i', '', $head );
		$head = rtrim( $head, " \t,;:/-" );
		return '' === $head ? $line : $head;
	}

	/** The street as a contract prints it (see the header). $phones: the buyer's own numbers. */
	public static function street( string $street, string $street2 = '', array $phones = array() ): string {
		$own = array();
		foreach ( $phones as $p ) {
			$d = self::digits( (string) $p );
			if ( '' !== $d ) {
				$own[ $d ] = true;
			}
		}
		$s1 = self::one( $street );
		$s1 = self::is_phone( $s1 ) ? '' : self::strip_own( $s1, $own );
		$s2 = self::one( $street2 );
		$s2 = self::is_phone( $s2 ) ? '' : self::strip_own( $s2, $own );
		return trim( $s1 . ' ' . $s2 );
	}

	/** The business's map ZIP => [ stored name, postal city ]. */
	public static function postal_map(): array {
		if ( is_array( self::$postal ) ) {
			return self::$postal;
		}
		$m = function_exists( 'get_option' ) ? get_option( self::POSTAL_OPT, array() ) : array();
		if ( function_exists( 'apply_filters' ) ) {
			$m = apply_filters( self::POSTAL_OPT, $m );
		}
		$out = array();
		foreach ( is_array( $m ) ? $m : array() as $zip => $pair ) {
			$zip = substr( (string) preg_replace( '/\D/', '', (string) $zip ), 0, 5 );
			if ( 5 === strlen( $zip ) && is_array( $pair ) && 2 === count( $pair ) ) {
				$out[ $zip ] = array( (string) array_values( $pair )[0], (string) array_values( $pair )[1] );
			}
		}
		return $out;
	}

	/** The city as a contract prints it: the postal city where the stored name is the business's neighbourhood name. */
	public static function city( string $city, string $zip ): string {
		$c   = self::one( $city );
		$z   = substr( (string) preg_replace( '/\D/', '', $zip ), 0, 5 );
		$map = self::postal_map();
		if ( isset( $map[ $z ] ) && 0 === strcasecmp( $c, $map[ $z ][0] ) ) {
			return $map[ $z ][1];
		}
		return $c;
	}
}
