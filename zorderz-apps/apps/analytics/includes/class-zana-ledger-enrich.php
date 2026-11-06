<?php
/**
 * ZANA_Ledger_Enrich — the columns the ledger derives from a billing document's free text. Pure.
 *
 * What a document's fields mean is the business's own (ZANA_Ledger_Rules); how they are read is Core:
 *
 *   reference   split by the document conventions' separators (ZDZ_Doc_Conventions, the platform's one reader). A
 *               leading all-digits token is the postal code; every other token is a code the rules list (a lead
 *               source, a product, the business itself), a salesperson code (never a source), or the product.
 *   notes       a note tag the rules list sets the source even over a reference code; else, with no reference code,
 *               a keyword the rules list; else (when the rules say so) a short note is itself the source.
 *   location    the line the rules name ("location") holds "<town> - (<codes>)": read with
 *               ZDZ_Doc_Conventions::parse_location_line(), so pay and analytics never read it two ways.
 *   salesperson the location line's code; else a person's recorded back-assignment (the commission app's rep
 *               overrides, invoices only). Never inferred: an uncoded document has no salesperson.
 *   labels      a source or a town is a GROUPING label, so access codes, phone numbers, street numbers, dates and
 *               scheduling notes are cut off ("Friend. Gate code 1234" groups with "Friend"); the notes are kept.
 *   mix         line amounts by catalog category (ZDZ_Item_Engine: the matched item's subtype, else its name; nothing
 *               matched: "Other"), administrative and $0 lines left out.
 *   brands      line amounts by the brands a line names (the rules' brand words, else the catalog items' brand).
 *
 * @package Zorderz\Analytics
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZANA_Ledger_Enrich {

	/** The longest grouping label. */
	const LABEL_MAX = 40;

	/** Injected in tests: callable( text ) => category label; callable( text ) => brand names. Else the catalog. */
	public static $category_fn = null;
	public static $brands_fn   = null;

	/** Every column derived from one document. $ctx: rep_codes (the codes people hold), override (callable id => code). */
	public static function document( array $doc, array $rules, array $ctx = array() ): array {
		$reference = (string) ( $doc['po_number'] ?? ( $doc['reference'] ?? '' ) );
		$notes     = (string) ( $doc['notes'] ?? '' );
		$ref       = self::parse_reference( $reference, $rules, (array) ( $ctx['rep_codes'] ?? array() ) );
		$source    = $ref['source_label'];
		$from_note = self::notes_source( $notes, $rules );
		if ( '' === $source && '' !== $from_note ) {
			$source = $from_note;
		}
		$tagged = self::note_tag( $notes, $rules );
		if ( '' !== $tagged ) {
			$source = $tagged;
		}
		$raw    = $source;
		$source = self::label( $source, false, $rules );
		$alias  = $rules['lead_sources']['aliases'][ strtolower( $source ) ] ?? '';
		if ( '' !== $alias ) {
			$source = $alias;
		}

		$loc = self::location( (array) ( $doc['lines'] ?? array() ), $rules );
		$rep = $loc['rep'];
		if ( '' === $rep && isset( $ctx['override'] ) && is_callable( $ctx['override'] ) && isset( $doc['invoiceid'] ) ) {
			$rep = self::codes( array( (string) call_user_func( $ctx['override'], (int) $doc['invoiceid'] ) ) );
		}
		return array(
			'lead_source'     => $source,
			'lead_source_raw' => $raw,
			'rep'             => $rep,
			'rep_inferred'    => false,
			'city'            => $loc['city'],
			'zip'             => $ref['zip'],
			'product'         => $ref['product'],
		);
	}

	/** The separators the platform's reference reader splits on. */
	protected static function separators(): array {
		$s = class_exists( 'ZDZ_Doc_Conventions' ) ? (array) ZDZ_Doc_Conventions::get( 'reference_code.split_on', array( '-', '/' ) ) : array( '-', '/' );
		$s = array_values( array_filter( array_map( 'strval', $s ), 'strlen' ) );
		return $s ? $s : array( '-', '/' );
	}

	/**
	 * A reference: { zip, product, source_code, source_label, raw }.
	 *
	 * @param string[] $rep_codes salesperson codes (never read as a source)
	 */
	public static function parse_reference( string $reference, array $rules, array $rep_codes = array() ): array {
		$out = array( 'zip' => '', 'product' => '', 'source_code' => '', 'source_label' => '', 'raw' => trim( $reference ) );
		$reference = trim( $reference );
		if ( '' === $reference ) {
			return $out;
		}
		$class = '';
		foreach ( self::separators() as $sep ) {
			$class .= preg_quote( $sep, '/' );
		}
		$parts = array_values( array_filter( array_map( 'trim', (array) preg_split( '/[' . $class . ']+/', $reference ) ), 'strlen' ) );
		if ( ! $parts ) {
			return $out;
		}
		if ( preg_match( '/^\d{3,10}$/', $parts[0] ) ) {
			$out['zip'] = array_shift( $parts );
		}
		$codes   = (array) $rules['lead_sources']['codes'];
		$self    = (array) $rules['reference']['self_codes'];
		$product = (array) $rules['reference']['product_codes'];
		$reps    = array_map( 'strtoupper', $rep_codes );
		foreach ( $parts as $part ) {
			$up = strtoupper( $part );
			if ( in_array( $up, $self, true ) ) {
				continue;
			}
			if ( isset( $codes[ $up ] ) ) {
				if ( '' === $out['source_code'] ) {
					$out['source_code']  = $up;
					$out['source_label'] = $codes[ $up ];
				}
				continue;
			}
			if ( isset( $product[ $up ] ) ) {
				$out['product'] = $product[ $up ];
				continue;
			}
			if ( ! empty( $rules['lead_sources']['unknown_short_code_is_source'] ) && '' === $out['source_code']
				&& strlen( $up ) <= 3 && preg_match( '/^[A-Z0-9]+$/', $part ) && ! in_array( $up, $reps, true ) ) {
				$out['source_code']  = $up;
				$out['source_label'] = $up;
				continue;
			}
			if ( '' === $out['product'] ) {
				$out['product'] = $part;
			}
		}
		// A code the rules allow at the very end, glued on ("12345XY", "Product XY"): not inside a word.
		if ( '' === $out['source_code'] ) {
			foreach ( (array) $rules['lead_sources']['suffix_codes'] as $sc ) {
				if ( ! isset( $codes[ $sc ] ) || ! preg_match( '/(?:^|[^A-Za-z])' . preg_quote( $sc, '/' ) . '\s*$/i', $reference ) ) {
					continue;
				}
				$out['source_code']  = $sc;
				$out['source_label'] = $codes[ $sc ];
				if ( '' !== $out['product'] && preg_match( '/^(.*?)(?:\s+|(?<=\d))' . preg_quote( $sc, '/' ) . '\s*$/i', $out['product'], $pm ) ) {
					$out['product'] = trim( $pm[1] );
					if ( '' === $out['zip'] && preg_match( '/^\d{3,10}$/', $out['product'] ) ) {
						$out['zip']     = $out['product'];
						$out['product'] = '';
					}
				}
				break;
			}
		}
		return $out;
	}

	/** A source named in the notes by a keyword the rules list, or (when allowed) a short note itself; else ''. */
	public static function notes_source( string $notes, array $rules ): string {
		$n = ZANA_Ledger_Rules::fold( $notes );
		if ( '' === $n ) {
			return '';
		}
		foreach ( (array) $rules['lead_sources']['note_keywords'] as $kw => $label ) {
			if ( false !== strpos( $n, (string) $kw ) ) {
				return (string) $label;
			}
		}
		if ( ! empty( $rules['lead_sources']['short_note_is_source'] ) && strlen( trim( $notes ) ) < 30 && ! preg_match( '/\s{3,}/', $notes ) ) {
			return trim( $notes );
		}
		return '';
	}

	/** A note tag that sets the source over everything else (see ZANA_Ledger_Rules), or ''. */
	public static function note_tag( string $notes, array $rules ): string {
		$n = ZANA_Ledger_Rules::fold( $notes );
		if ( '' === $n ) {
			return '';
		}
		foreach ( (array) $rules['lead_sources']['note_tags'] as $t ) {
			$hit = false;
			foreach ( (array) $t['tags'] as $tag ) {
				$tag = ZANA_Ledger_Rules::fold( $tag );
				if ( '' === $tag ) {
					continue;
				}
				if ( false === strpos( $tag, ' ' ) ? ZANA_Ledger_Rules::has_phrase( $n, $tag ) : $n === $tag ) {
					$hit = true;
					break;
				}
			}
			if ( ! $hit ) {
				continue;
			}
			foreach ( (array) $t['except'] as $x ) {
				if ( ZANA_Ledger_Rules::has_phrase( $n, (string) $x ) || false !== strpos( str_replace( ' ', '', $n ), str_replace( ' ', '', (string) $x ) ) ) {
					continue 2;
				}
			}
			return (string) $t['label'];
		}
		return '';
	}

	/** The location line: { city, rep } (rep: the codes joined with "/", '' when none). */
	public static function location( array $lines, array $rules ): array {
		$out = array( 'city' => '', 'rep' => '' );
		foreach ( $lines as $line ) {
			if ( ! is_array( $line ) ) {
				continue;
			}
			$name = strtolower( trim( (string) ( $line['name'] ?? '' ) ) );
			$hit  = false;
			foreach ( (array) $rules['location_line_names'] as $ln ) {
				if ( '' !== $ln && 0 === strpos( $name, (string) $ln ) ) {
					$hit = true;
					break;
				}
			}
			if ( ! $hit ) {
				continue;
			}
			$desc = trim( (string) ( $line['description'] ?? '' ) );
			if ( class_exists( 'ZDZ_Doc_Conventions' ) ) {
				$p     = ZDZ_Doc_Conventions::parse_location_line( (string) preg_split( '/\r\n|\r|\n/', $desc )[0] );
				$codes = (array) $p['party_codes'];
				$city  = (string) $p['locality'];
			} else {
				$codes = preg_match( '/\(([A-Za-z0-9\/ &,+]+)\)/', $desc, $m ) ? preg_split( '/[\/ &,+]+/', $m[1] ) : array();
				$city  = (string) preg_replace( '/\s*[-–]?\s*\(.*$/s', '', $desc );
			}
			$out['rep']  = self::codes( $codes );
			$out['city'] = self::label( $city, true, $rules );
			return $out;
		}
		return $out;
	}

	/** Salesperson codes as one stored value: 2-4 capital letters each, joined with "/", at most 20 characters. */
	public static function codes( array $codes ): string {
		$keep = array();
		foreach ( $codes as $c ) {
			$c = strtoupper( trim( (string) $c ) );
			if ( preg_match( '/^[A-Z]{2,4}$/', $c ) ) {
				$keep[ $c ] = true;
			}
		}
		return substr( implode( '/', array_keys( $keep ) ), 0, 20 );
	}

	/* ─────────────────────────── grouping labels ─────────────────────────── */

	/** Access instructions: the label ends before them. */
	const ACCESS = '\b(?:gate|door|alarm|access|entry|lock\s*-?\s*box|key\s*-?\s*pad)\s*code\b'
		. '|\b(?:gate|door|alarm|access|entry)\s*(?:number|no\.?|#|combo)\s*[:#]?\s*#?\d'
		. '|\b(?:lock\s*-?\s*box|key\s*-?\s*pad|combo)\s*(?:number|no\.?|#|code)?\s*[:#]?\s*#?\d'
		. '|\bcode\s*[:#]\s*#?\d|\bcode\s+\d{3,}'
		. '|\b(?:gate|code|buzz|buzzer|lockbox|lock\s*box|keypad|key\s*pad)\s*[:#]?\s*#?[A-Za-z]?\d{2,}'
		. '|\b(?:unit|apt|apartment|suite|ste)\.?\s*#?\s*[A-Za-z]?\d';

	/** Always a note, never part of a name. */
	const NOTE_STRONG = '\b(?:ok(?:ay)?\s+to\s+(?:charge|bill|run|text|call|email|leave)|call\s+(?:first|before|ahead)|text\s+(?:first|before|ahead)|met\s+w(?:ith\b|\/)|no\s+answer|left\s+(?:vm|voicemail|message))';

	/** A note only when it is not the first word (a business's name may start with one of these words). */
	const NOTE_WEAK = '\b(?:scheduled|schedule|sched|appt|appointment|install(?:ed|ing)?\s+on|tenative|tentative|measured?|completed|pending)\b';

	/** A date, weekday or clock time. */
	const WHEN = '/\b(?:\d{1,2}[\/\-]\d{1,2}(?:[\/\-]\d{2,4})?|mon(?:day)?|tue(?:s(?:day)?)?|wed(?:nesday)?|thu(?:rs(?:day)?)?|fri(?:day)?|sat(?:urday)?|sun(?:day)?|tomorrow|today|\d{1,2}\s*(?:am|pm))\b/i';

	/** Words that may follow a number inside a business's name or a count, so the label keeps them. */
	const NUMBER_WORDS = array( 'builders', 'construction', 'painting', 'landscaping', 'roofing', 'design', 'co', 'inc', 'llc', 'items', 'item', 'units', 'unit', 'jobs', 'job', 'x' );

	/** A grouping label from free text: a source ($city false) or a town ($city true). '' when nothing name-like is left. */
	public static function label( $s, bool $city, array $rules = array() ): string {
		$s = (string) $s;
		if ( function_exists( 'mb_check_encoding' ) && ! mb_check_encoding( $s, 'UTF-8' ) ) {
			$s = mb_convert_encoding( $s, 'UTF-8', 'UTF-8' );
		}
		$s = (string) preg_split( '/\r\n|\r|\n/', $s )[0];
		$s = trim( (string) preg_replace( '/\s+/u', ' ', $s ) );
		if ( '' === $s ) {
			return '';
		}
		if ( $city ) {
			$s = (string) preg_replace( '/^location\s*[-:–]\s*/i', '', $s );
			if ( preg_match( '/^\d{3,6}\s+\S/', $s ) ) {
				return ''; // a street address, not a town
			}
			$s = (string) preg_split( '/\s*\(|\s+[-–]\s+|\s*,\s*(?=[A-Z]{2}\b|\d)/u', $s )[0];
		}
		$s = trim( (string) preg_replace( '/^(?:\d{1,2}[\/\-]\d{1,2}(?:[\/\-]\d{2,4})?|#?\d{4,})[\s.,:;\-–]*/', '', $s ) );
		if ( preg_match( '/^' . self::NOTE_WEAK . '/i', $s )
			&& ( preg_match( self::WHEN, $s ) || preg_match( '/\((?:[A-Z]{2,3})\)|\bmeasure|\bfor\s+[A-Z]{2,3}\b/', $s ) ) ) {
			return '';
		}
		$words = array_merge( self::NUMBER_WORDS, (array) ( $rules['label_number_words'] ?? array() ) );
		$words = implode( '|', array_map( function ( $w ) { return preg_quote( (string) $w, '/' ); }, $words ) );
		$cuts  = array(
			'/' . self::ACCESS . '/i',
			'/' . self::NOTE_STRONG . '/i',
			'/(?<=\S)\s+' . self::NOTE_WEAK . '/i',
			'/\S+@\S+/',
			'/\(?\d{3}\)?[\s.\-]\d{3}[\s.\-]\d{4}/',
			'/\b\d{1,2}[\/\-]\d{1,2}(?:[\/\-]\d{2,4})?\b/',
			$city
				? '/\b\d{1,6}\s+[A-Z][a-z]+\s+(?:St|Rd|Dr|Ave|Ln|Ct|Way|Blvd|Pl|Cir|Ter|Trl|Pkwy|Hwy|Road|Street|Drive|Lane|Court)\b/'
				: '/\b\d{1,6}\s+(?!(?:' . $words . ')\b)[A-Za-z][a-z]+/i',
			'/#\s*\d+|\b\d{4,}\b/',
		);
		foreach ( $cuts as $re ) {
			if ( preg_match( $re, $s, $m, PREG_OFFSET_CAPTURE ) ) {
				$s = substr( $s, 0, (int) $m[0][1] );
			}
		}
		do {
			$before = $s;
			$s      = (string) preg_replace( '/[\s.,:;#\-–\/&+]+$/u', '', $s );
			$s      = (string) preg_replace( '/\s+(?:at|on|in|of|for|by|the|to|and|w|with|number|no)$/i', '', $s );
		} while ( $s !== $before );
		$s = trim( $s );
		if ( function_exists( 'mb_substr' ) ) {
			$s = rtrim( mb_substr( $s, 0, self::LABEL_MAX, 'UTF-8' ) );
		} else {
			$s = rtrim( substr( $s, 0, self::LABEL_MAX ) );
		}
		if ( class_exists( 'ZDZ_Sealed' ) && method_exists( 'ZDZ_Sealed', 'cut' ) ) {
			$s = trim( (string) ZDZ_Sealed::cut( $s ) );
		}
		return preg_match( '/\p{L}{2,}/u', $s ) ? $s : '';
	}

	/* ─────────────────────────── lines ─────────────────────────── */

	/** Line amount, rounded to cents (the billing system sends {amount, code} or a number). */
	public static function money( $v ): float {
		if ( is_array( $v ) ) {
			$v = $v['amount'] ?? 0;
		}
		return round( (float) $v, 2 );
	}

	/** An administrative line (the location line, a $0 metadata line): never a product. */
	public static function is_admin_line( string $name, string $desc, float $amount, array $rules ): bool {
		$n = strtolower( trim( $name ) );
		foreach ( (array) $rules['location_line_names'] as $ln ) {
			if ( '' !== $ln && 0 === strpos( $n, (string) $ln ) ) {
				return true;
			}
		}
		if ( preg_match( '/^(?:memo|note|gate\s*code)\b/', $n ) ) {
			return true;
		}
		if ( class_exists( 'ZDZ_Doc_Conventions' ) && method_exists( 'ZDZ_Doc_Conventions', 'is_metadata_line' )
			&& ZDZ_Doc_Conventions::is_metadata_line( '' !== trim( $desc ) ? $desc : $name, $amount ) ) {
			return true;
		}
		return false;
	}

	/** Line amounts by catalog category; administrative and $0 lines left out. $category: callable( text ) => label. */
	public static function mix( $lines, array $rules, $category = null ): array {
		$mix = array();
		foreach ( (array) $lines as $line ) {
			if ( ! is_array( $line ) ) {
				continue;
			}
			$name = trim( (string) ( $line['name'] ?? '' ) );
			$desc = trim( (string) ( $line['description'] ?? '' ) );
			$head = '' !== $name ? $name : $desc;
			if ( strlen( $head ) < 3 ) {
				continue;
			}
			$amt = self::money( $line['amount'] ?? 0 );
			if ( abs( $amt ) < 0.01 || self::is_admin_line( $name, $desc, $amt, $rules ) ) {
				continue;
			}
			$cat         = is_callable( $category ) ? (string) call_user_func( $category, $head . ' ' . $desc ) : self::category( $head . ' ' . $desc );
			$cat         = '' !== $cat ? $cat : 'Other';
			$mix[ $cat ] = round( ( $mix[ $cat ] ?? 0 ) + $amt, 2 );
		}
		return $mix;
	}

	/** A line's catalog category: the matched item's subtype label, else its name; '' when nothing matches. */
	public static function category( string $text ): string {
		if ( is_callable( self::$category_fn ) ) {
			return (string) call_user_func( self::$category_fn, $text );
		}
		if ( ! class_exists( 'ZDZ_Item_Engine' ) || ZDZ_Item_Engine::is_empty() ) {
			return '';
		}
		static $labels = null;
		if ( null === $labels ) {
			$labels = array();
			foreach ( (array) ZDZ_Item_Engine::subtypes() as $s ) {
				if ( is_array( $s ) && isset( $s['slug'], $s['label'] ) ) {
					$labels[ (string) $s['slug'] ] = (string) $s['label'];
				}
			}
		}
		$item = ZDZ_Item_Engine::match( $text );
		if ( ! is_array( $item ) ) {
			return '';
		}
		$sub = (string) ( $item['subtype'] ?? '' );
		if ( '' !== $sub ) {
			return $labels[ $sub ] ?? ucwords( str_replace( array( '-', '_' ), ' ', $sub ) );
		}
		return ZANA_Ledger_Rules::label( $item['name'] ?? '' );
	}

	/**
	 * Line amounts by the brands each line names: the rules' brand words, else the brand attribute of the catalog items
	 * the line names. A line naming two brands counts for both. Administrative and $0 lines left out.
	 * $catalog_brands: callable( text ) => string[] (tests), else the Item Engine.
	 */
	public static function brands( $lines, array $rules, $catalog_brands = null ): array {
		$out   = array();
		$words = (array) $rules['brands'];
		foreach ( (array) $lines as $line ) {
			if ( ! is_array( $line ) ) {
				continue;
			}
			$name = trim( (string) ( $line['name'] ?? '' ) );
			$desc = trim( (string) ( $line['description'] ?? '' ) );
			$head = '' !== $name ? $name : $desc;
			if ( strlen( $head ) < 3 ) {
				continue;
			}
			$amt = self::money( $line['amount'] ?? 0 );
			if ( abs( $amt ) < 0.01 || self::is_admin_line( $name, $desc, $amt, $rules ) ) {
				continue;
			}
			$text  = ZANA_Ledger_Rules::fold( $name . ' ' . $desc );
			$named = array();
			if ( $words ) {
				foreach ( $words as $label => $ws ) {
					foreach ( (array) $ws as $w ) {
						if ( ZANA_Ledger_Rules::has_phrase( $text, (string) $w ) ) {
							$named[ $label ] = true;
							break;
						}
					}
				}
			} else {
				$list = is_callable( $catalog_brands ) ? (array) call_user_func( $catalog_brands, $name . ' ' . $desc ) : self::catalog_brands( $name . ' ' . $desc );
				foreach ( $list as $b ) {
					$b = ZANA_Ledger_Rules::label( $b );
					if ( '' !== $b ) {
						$named[ $b ] = true;
					}
				}
			}
			foreach ( array_keys( $named ) as $b ) {
				$out[ $b ] = round( ( $out[ $b ] ?? 0 ) + $amt, 2 );
			}
		}
		return $out;
	}

	/** The brand attribute of every catalog item a line names. */
	protected static function catalog_brands( string $text ): array {
		if ( is_callable( self::$brands_fn ) ) {
			return (array) call_user_func( self::$brands_fn, $text );
		}
		if ( ! class_exists( 'ZDZ_Item_Engine' ) || ZDZ_Item_Engine::is_empty() ) {
			return array();
		}
		$out = array();
		foreach ( (array) ZDZ_Item_Engine::match_all( $text ) as $item ) {
			$b = $item['attributes']['brand'] ?? '';
			if ( is_string( $b ) && '' !== trim( $b ) ) {
				$out[] = $b;
			}
		}
		return $out;
	}

	/** The customer's name on a document: the organisation, else first and last name. */
	public static function customer_name( array $doc ): string {
		foreach ( array( 'current_organization', 'organization' ) as $k ) {
			if ( ! empty( $doc[ $k ] ) && is_string( $doc[ $k ] ) ) {
				return trim( $doc[ $k ] );
			}
		}
		return trim( (string) ( $doc['fname'] ?? '' ) . ' ' . (string) ( $doc['lname'] ?? '' ) );
	}
}
