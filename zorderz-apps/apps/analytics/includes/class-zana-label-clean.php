<?php
/**
 * ZANA_Label_Clean — a lead-source or city LABEL is a name to group by, nothing more.
 *
 * Both come from free text on a document (a reference or notes line, a location line), and free text carries more than
 * a name: "Friend. Gate code # 1234", "lockbox number 5678", "Neighbor house at 12 Example Ave", "Okay to charge CC",
 * "Springfield - (XY) Scheduled for AB to measure 6-10-26 …". Everything after an access word (gate / lockbox / code /
 * key pad / alarm), a street number, a second line or a scheduling note is not part of the name, so it is cut off.
 * The document's own notes are left alone: this only shapes the GROUPING label, so "Friend" and "Friend. Gate code
 * #1234" land in one row. Pure; a data app that groups by source or city reads it (the ledger's reports).
 *
 * @package Zorderz\Analytics
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZANA_Label_Clean {

	const MAX = 40;

	/**
	 * Access instructions; the label ends before them. "<gate|door|alarm|access|entry|lockbox> code"
	 * always; a number / no. / # / combo / lockbox / keypad only when a code follows (so "Door Example Co",
	 * "Access Example Co", "Code 9 Example Pools", "Lock Box Example Storage" keep their names).
	 */
	const ACCESS = '\b(?:gate|door|alarm|access|entry|lock\s*-?\s*box|key\s*-?\s*pad)\s*code\b'
		. '|\b(?:gate|door|alarm|access|entry)\s*(?:number|no\.?|#|combo)\s*[:#]?\s*#?\d'
		. '|\b(?:lock\s*-?\s*box|key\s*-?\s*pad|combo)\s*(?:number|no\.?|#|code)?\s*[:#]?\s*#?\d'
		. '|\bcode\s*[:#]\s*#?\d|\bcode\s+\d{3,}'
		// a code word followed by 2+ digits ("Gate 123", "Friend code 12", "buzz 112", "Lockbox: L1234"),
		// and unit / apt / suite numbers
		. '|\b(?:gate|code|buzz|buzzer|lockbox|lock\s*box|keypad|key\s*pad)\s*[:#]?\s*#?[A-Za-z]?\d{2,}'
		. '|\b(?:unit|apt|apartment|suite|ste)\.?\s*#?\s*[A-Za-z]?\d';

	/** Always a note, never part of a name. */
	const NOTE_STRONG = '\b(?:ok(?:ay)?\s+to\s+(?:charge|bill|run|text|call|email|leave)|call\s+(?:first|before|ahead)|text\s+(?:first|before|ahead)|met\s+w(?:ith\b|\/)|no\s+answer|left\s+(?:vm|voicemail|message))';

	/** A note only when it is not the first word ("Measure Right Example Co" is a name). */
	const NOTE_WEAK = '\b(?:scheduled|schedule|sched|appt|appointment|install(?:ed|ing)?\s+on|tenative|tentative|measured?|completed|pending)\b';

	/** A date, weekday or clock time: with a leading weak note word, the whole label is a note. */
	const WHEN = '/\b(?:\d{1,2}[\/\-]\d{1,2}(?:[\/\-]\d{2,4})?|mon(?:day)?|tue(?:s(?:day)?)?|wed(?:nesday)?|thu(?:rs(?:day)?)?|fri(?:day)?|sat(?:urday)?|sun(?:day)?|tomorrow|today|\d{1,2}\s*(?:am|pm))\b/i';

	/**
	 * Words that, after a number, are part of a business's name rather than a street ("Code 9 Example Pools", "2 Example Builders",
	 * "7 Oaks"): Core's generic words, plus the catalog's trade words (ZDZ_Ask_Phrases::trade_words()), each with a
	 * plural s.
	 */
	public static function name_words(): string {
		$w = array( 'glass', 'turf', 'pools?', 'builders', 'construction', 'painting', 'landscaping', 'roofing', 'design', 'doors?', 'windows?', 'oaks', 'co', 'inc', 'llc', 'items?', 'units?', 'jobs?', 'x' );
		if ( class_exists( 'ZDZ_Ask_Phrases' ) && method_exists( 'ZDZ_Ask_Phrases', 'trade_words' ) ) {
			foreach ( (array) ZDZ_Ask_Phrases::trade_words() as $t ) {
				$t = strtolower( trim( (string) $t ) );
				if ( preg_match( '/^[a-z][a-z \-]{1,30}$/', $t ) ) {
					$w[] = preg_quote( rtrim( $t, 's' ), '/' ) . 's?';
				}
			}
		}
		return implode( '|', array_unique( $w ) );
	}

	/**
	 * A lead-source grouping label.
	 *
	 * @param string $s raw source label.
	 * @return string cleaned label ('' when nothing name-like is left).
	 */
	public static function source( $s ) {
		return self::clean( (string) $s, false );
	}

	/** A city grouping label: the place name only. */
	public static function city( $s ) {
		return self::clean( (string) $s, true );
	}

	private static function clean( $s, $city ) {
		$s = (string) $s;
		if ( function_exists( 'mb_check_encoding' ) && ! mb_check_encoding( $s, 'UTF-8' ) ) {
			$s = mb_convert_encoding( $s, 'UTF-8', 'UTF-8' ); // invalid bytes would make every /u regex return null
		}
		// One line only: a second line is always a note.
		$s = preg_split( '/\r\n|\r|\n/', $s )[0];
		$s = trim( (string) preg_replace( '/\s+/u', ' ', $s ) );
		if ( '' === $s ) {
			return '';
		}
		if ( $city ) {
			// "Location - Springfield (XYZ) - (AB) 6/4 9 am install" -> "Springfield"
			$s = (string) preg_replace( '/^location\s*[-:–]\s*/i', '', $s );
			// A city that starts with a house number is a street address ("1250 Example Road,
			// Springfield"): no city label rather than a street name. "7 Oaks" is a place.
			if ( preg_match( '/^\d{3,6}\s+\S/', $s ) ) {
				return '';
			}
			$s = (string) preg_split( '/\s*\(|\s+[-–]\s+|\s*,\s*(?=[A-Z]{2}\b|\d)/u', $s )[0];
		}
		// A leading date or invoice-style number is dropped and the rest kept ("5/7/26 Example Co").
		$s = trim( (string) preg_replace( '/^(?:\d{1,2}[\/\-]\d{1,2}(?:[\/\-]\d{2,4})?|#?\d{4,})[\s.,:;\-–]*/', '', $s ) );
		// A label that opens with a note word and names a date, weekday or time is a note.
		if ( preg_match( '/^' . self::NOTE_WEAK . '/i', $s )
			&& ( preg_match( self::WHEN, $s ) || preg_match( '/\((?:[A-Z]{2,3})\)|\bmeasure|\bfor\s+[A-Z]{2,3}\b/', $s ) ) ) {
			return '';
		}
		$cuts = array(
			'/' . self::ACCESS . '/i',
			'/' . self::NOTE_STRONG . '/i',
			'/(?<=\S)\s+' . self::NOTE_WEAK . '/i',           // not as the first word
			'/\S+@\S+/',
			'/\(?\d{3}\)?[\s.\-]\d{3}[\s.\-]\d{4}/',             // a phone number
			'/\b\d{1,2}[\/\-]\d{1,2}(?:[\/\-]\d{2,4})?\b/',      // a date: 5/7/26, 6-10-26
			$city   // a street address: number + a capitalised word (a city label needs a street suffix too)
				? '/\b\d{1,6}\s+[A-Z][a-z]+\s+(?:St|Rd|Dr|Ave|Ln|Ct|Way|Blvd|Pl|Cir|Ter|Trl|Pkwy|Hwy|Road|Street|Drive|Lane|Court)\b/'
				: '/\b\d{1,6}\s+(?!(?:' . self::name_words() . ')\b)[A-Za-z][a-z]+/i',
			'/#\s*\d+|\b\d{4,}\b/',                               // a code or an invoice / lockbox number
		);
		foreach ( $cuts as $re ) {
			if ( preg_match( $re, $s, $m, PREG_OFFSET_CAPTURE ) ) {
				$s = substr( $s, 0, (int) $m[0][1] );
			}
		}
		// Trailing connectors and punctuation left by a cut: "Neighbor house at" -> "Neighbor house".
		do {
			$before = $s;
			$s      = (string) preg_replace( '/[\s.,:;#\-–\/&+]+$/u', '', $s );
			$s      = (string) preg_replace( '/\s+(?:at|on|in|of|for|by|the|to|and|w|with|number|no)$/i', '', $s );
		} while ( $s !== $before );
		$s = trim( $s );
		if ( function_exists( 'mb_substr' ) && mb_strlen( $s, 'UTF-8' ) > self::MAX ) {
			$s = rtrim( mb_substr( $s, 0, self::MAX, 'UTF-8' ) );
		} elseif ( strlen( $s ) > self::MAX ) {
			$s = rtrim( substr( $s, 0, self::MAX ) );
		}
		// Nothing letter-like left (e.g. "Gate code XXX", "#5678") is not a label.
		return preg_match( '/\p{L}{2,}/u', $s ) ? $s : '';
	}
}
