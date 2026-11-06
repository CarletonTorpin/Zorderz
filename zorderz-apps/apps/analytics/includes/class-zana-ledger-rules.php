<?php
/**
 * ZANA_Ledger_Rules — how this business writes its billing paperwork and names its lead sources, as data.
 *
 * The stored-data ledger (ZANA_Ledger) keeps a copy of the billing system's invoices, estimates and payments and the
 * CRM's leads. Most of what it stores is the provider's own fields; a few columns are DERIVED from free text the
 * business types: the lead source (from a document's reference field and notes), the salesperson and the town (from the
 * location line), the brands a line names. How a business writes those is its own, so none of it is in code. It is
 * read here from one option, `zana_ledger_rules` (an Identity Pack writes it through the "sales-ledger" consumer, see
 * PACK; the `zana_ledger_rules` filter may adjust it), merged over neutral Core defaults that derive nothing a business
 * did not say:
 *
 *   lead_sources.codes               CODE => label: a reference token that names a lead source ("XY" => "Example channel")
 *   lead_sources.suffix_codes        codes that also count glued to the end of a reference ("12345XY")
 *   lead_sources.unknown_short_code_is_source
 *                                    a short capitalised token nobody listed is a source code labelled as itself
 *   lead_sources.note_keywords       keyword => label, found anywhere in a document's notes
 *   lead_sources.note_tags           [ {label, tags[], except[]} ]: a tag in the notes that sets the label even over a
 *                                    reference code. A tag without a space counts as a whole word anywhere in the notes;
 *                                    a tag with a space only when it is the whole note. An `except` word cancels it.
 *   lead_sources.aliases             cleaned label (lower case) => the label it is counted under
 *   lead_sources.short_note_is_source a short note that matched nothing is itself the source label
 *   reference.self_codes             tokens that name the business itself in a reference (skipped)
 *   reference.product_codes          CODE => product: a reference token that is a product, not a source
 *   location_line_names              line names that carry the location line ("location"): the town and salesperson
 *   brands                           label => [words]: the brands a line names (empty = the catalog's brand attributes)
 *   source_groups                    [ {key, label, words[], crm[], billing[], aside[], aside_label} ]: how lead-source
 *                                    questions group the CRM's and the billing system's source names (see matches())
 *   history_from_year                the first year the history walk reads (0 = until two empty years in a row)
 *   intake_minutes                   a CRM note written this soon after its lead is the intake, not a follow-up
 *   label_number_words               more words that may follow a number inside a business's name in a label
 *
 * Matching terms (crm, billing, aside): a plain phrase matches as whole words, case-insensitively; "=text" matches the
 * whole value exactly (trimmed; "=" alone is an empty value); "a + b" needs every part. Never a regular expression: a
 * pack is data, and data never becomes a pattern the code runs.
 *
 * signature() changes whenever anything that derives a stored column changes, so the ledger re-derives its history
 * once (ZANA_Ledger::relabel_pending()).
 *
 * @package Zorderz\Analytics
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZANA_Ledger_Rules {

	const OPTION = 'zana_ledger_rules';

	/** Bump when Core's own derivation of a stored column changes (the ledger re-derives its history once). */
	const CORE_RULES = '2';

	/** Injected in tests: the merged rules, used as they are. */
	public static $override = null;

	/** @var array|null this request's rules */
	private static $memo = null;

	public static function flush(): void {
		self::$memo = null;
	}

	/** Core's neutral defaults: nothing is derived that the business did not say. */
	public static function defaults(): array {
		return array(
			'lead_sources'        => array(
				'codes'                       => array(),
				'suffix_codes'                => array(),
				'unknown_short_code_is_source' => false,
				'note_keywords'               => array(),
				'note_tags'                   => array(),
				'aliases'                     => array(),
				'short_note_is_source'        => false,
			),
			'reference'           => array(
				'self_codes'    => array(),
				'product_codes' => array(),
			),
			'location_line_names' => array( 'location' ),
			'brands'              => array(),
			'source_groups'       => array(),
			'history_from_year'   => 0,
			'intake_minutes'      => 10,
			'label_number_words'  => array(),
		);
	}

	/** The rules in force: defaults, then the stored option, then the filter; every value cleaned. */
	public static function get(): array {
		if ( is_array( self::$override ) ) {
			return self::clean( array_replace_recursive( self::defaults(), self::$override ) );
		}
		if ( null !== self::$memo ) {
			return self::$memo;
		}
		$stored = function_exists( 'get_option' ) ? get_option( self::OPTION, array() ) : array();
		$rules  = array_replace_recursive( self::defaults(), is_array( $stored ) ? $stored : array() );
		if ( function_exists( 'apply_filters' ) ) {
			$f     = apply_filters( 'zana_ledger_rules', $rules );
			$rules = is_array( $f ) ? $f : $rules;
		}
		self::$memo = self::clean( $rules );
		return self::$memo;
	}

	/** A short fingerprint of everything that derives a stored column (lead source, brands, town, salesperson). */
	public static function signature(): string {
		$r = self::get();
		$p = array( $r['lead_sources'], $r['reference'], $r['location_line_names'], $r['brands'], $r['label_number_words'] );
		return self::CORE_RULES . '.' . substr( md5( (string) json_encode( $p ) ), 0, 10 );
	}

	/* ─────────────────────────── cleaning (pure) ─────────────────────────── */

	/** A code: capital letters and digits, 1-12. */
	public static function code( $v ): string {
		$v = strtoupper( trim( (string) $v ) );
		return preg_match( '/^[A-Z0-9]{1,12}$/', $v ) ? $v : '';
	}

	/** A label: one line of plain text, at most 80 characters. */
	public static function label( $v ): string {
		$v = is_scalar( $v ) ? (string) $v : '';
		$v = trim( (string) preg_replace( '/[\x00-\x1F\x7F<>]+/u', ' ', $v ) );
		$v = trim( (string) preg_replace( '/\s+/u', ' ', $v ) );
		return function_exists( 'mb_substr' ) ? mb_substr( $v, 0, 80 ) : substr( $v, 0, 80 );
	}

	/** A word or phrase: lower case letters, digits, spaces, hyphens, apostrophes, "&", "/" and "+"; at most 60. */
	public static function phrase( $v ): string {
		$v = strtolower( self::label( $v ) );
		if ( '' === $v || strlen( $v ) > 60 ) {
			return '';
		}
		return preg_match( "/^[\\p{L}\\p{N} '&\\/+=.-]*$/u", $v ) ? $v : '';
	}

	/** A list of phrases. */
	public static function phrases( $list ): array {
		$out = array();
		foreach ( is_array( $list ) ? $list : array() as $p ) {
			$p = is_string( $p ) && '=' === $p ? '=' : self::phrase( $p );
			if ( '' !== $p ) {
				$out[ $p ] = true;
			}
		}
		return array_keys( $out );
	}

	public static function clean( array $r ): array {
		$d   = self::defaults();
		$ls  = is_array( $r['lead_sources'] ?? null ) ? $r['lead_sources'] : array();
		$out = $d;

		foreach ( (array) ( $ls['codes'] ?? array() ) as $c => $l ) {
			$c = self::code( $c );
			$l = self::label( $l );
			if ( '' !== $c && '' !== $l ) {
				$out['lead_sources']['codes'][ $c ] = $l;
			}
		}
		foreach ( (array) ( $ls['suffix_codes'] ?? array() ) as $c ) {
			$c = self::code( $c );
			if ( '' !== $c && strlen( $c ) >= 2 ) {
				$out['lead_sources']['suffix_codes'][] = $c;
			}
		}
		$out['lead_sources']['suffix_codes']                 = array_values( array_unique( $out['lead_sources']['suffix_codes'] ) );
		$out['lead_sources']['unknown_short_code_is_source'] = ! empty( $ls['unknown_short_code_is_source'] );
		$out['lead_sources']['short_note_is_source']         = ! empty( $ls['short_note_is_source'] );
		foreach ( (array) ( $ls['note_keywords'] ?? array() ) as $k => $l ) {
			$k = self::phrase( $k );
			$l = self::label( $l );
			if ( '' !== $k && '' !== $l && strlen( $k ) >= 3 ) {
				$out['lead_sources']['note_keywords'][ $k ] = $l;
			}
		}
		foreach ( (array) ( $ls['note_tags'] ?? array() ) as $t ) {
			$l    = self::label( is_array( $t ) ? ( $t['label'] ?? '' ) : '' );
			$tags = self::phrases( is_array( $t ) ? ( $t['tags'] ?? array() ) : array() );
			if ( '' !== $l && $tags ) {
				$out['lead_sources']['note_tags'][] = array( 'label' => $l, 'tags' => $tags, 'except' => self::phrases( $t['except'] ?? array() ) );
			}
		}
		foreach ( (array) ( $ls['aliases'] ?? array() ) as $k => $l ) {
			$k = self::phrase( $k );
			$l = self::label( $l );
			if ( '' !== $k && '' !== $l ) {
				$out['lead_sources']['aliases'][ $k ] = $l;
			}
		}

		$ref = is_array( $r['reference'] ?? null ) ? $r['reference'] : array();
		foreach ( (array) ( $ref['self_codes'] ?? array() ) as $c ) {
			$c = self::code( $c );
			if ( '' !== $c ) {
				$out['reference']['self_codes'][] = $c;
			}
		}
		$out['reference']['self_codes'] = array_values( array_unique( $out['reference']['self_codes'] ) );
		foreach ( (array) ( $ref['product_codes'] ?? array() ) as $c => $l ) {
			$c = self::code( $c );
			$l = self::label( $l );
			if ( '' !== $c && '' !== $l ) {
				$out['reference']['product_codes'][ $c ] = $l;
			}
		}

		$names = self::phrases( $r['location_line_names'] ?? $d['location_line_names'] );
		$out['location_line_names'] = $names ? $names : $d['location_line_names'];

		foreach ( (array) ( $r['brands'] ?? array() ) as $label => $words ) {
			$label = self::label( $label );
			$words = self::phrases( $words );
			if ( '' !== $label && $words ) {
				$out['brands'][ $label ] = $words;
			}
		}

		$seen = array();
		foreach ( (array) ( $r['source_groups'] ?? array() ) as $g ) {
			if ( ! is_array( $g ) ) {
				continue;
			}
			$key   = preg_replace( '/[^a-z0-9_]/', '', strtolower( (string) ( $g['key'] ?? '' ) ) );
			$label = self::label( $g['label'] ?? '' );
			$words = self::phrases( $g['words'] ?? array() );
			if ( '' === $key || '' === $label || ! $words || isset( $seen[ $key ] ) || 'none' === $key ) {
				continue;
			}
			$seen[ $key ]           = true;
			$out['source_groups'][] = array(
				'key'         => $key,
				'label'       => $label,
				'words'       => $words,
				'crm'         => self::phrases( $g['crm'] ?? array() ),
				'billing'     => self::phrases( $g['billing'] ?? array() ),
				'except'      => self::phrases( $g['except'] ?? array() ),
				'aside'       => self::phrases( $g['aside'] ?? array() ),
				'aside_label' => self::label( $g['aside_label'] ?? '' ),
			);
		}

		$y = (int) ( $r['history_from_year'] ?? 0 );
		$out['history_from_year'] = ( $y >= 1990 && $y <= 2100 ) ? $y : 0;
		$m = (int) ( $r['intake_minutes'] ?? $d['intake_minutes'] );
		$out['intake_minutes'] = ( $m >= 0 && $m <= 1440 ) ? $m : $d['intake_minutes'];
		$out['label_number_words'] = self::phrases( $r['label_number_words'] ?? array() );
		return $out;
	}

	/* ─────────────────────────── matching (pure) ─────────────────────────── */

	/** Lower case, one space, the apostrophes made plain. */
	public static function fold( $s ): string {
		$s = strtolower( str_replace( array( "\u{2019}", "\u{2018}", '`' ), "'", (string) $s ) );
		return trim( (string) preg_replace( '/\s+/u', ' ', $s ) );
	}

	/** Is $phrase in $text as whole words? */
	public static function has_phrase( string $text, string $phrase ): bool {
		$phrase = trim( $phrase );
		if ( '' === $phrase ) {
			return false;
		}
		$rx = '/(?<![\p{L}\p{N}])' . str_replace( ' ', '\s+', preg_quote( $phrase, '/' ) ) . '(?![\p{L}\p{N}])/u';
		return (bool) preg_match( $rx, $text );
	}

	/**
	 * Does a value match any of the terms (and none of the exceptions)? A plain phrase: whole words; "=text": the whole
	 * value; "=" an empty value; "a + b": every part.
	 */
	public static function matches( $value, array $terms, array $except = array() ): bool {
		$v = self::fold( $value );
		foreach ( $except as $x ) {
			if ( '' !== $v && self::has_phrase( $v, (string) $x ) ) {
				return false;
			}
		}
		foreach ( $terms as $t ) {
			$t = (string) $t;
			if ( '' === $t ) {
				continue;
			}
			if ( '=' === $t[0] ) {
				if ( $v === self::fold( substr( $t, 1 ) ) ) {
					return true;
				}
				continue;
			}
			if ( '' === $v ) {
				continue;
			}
			$parts = array_values( array_filter( array_map( 'trim', explode( '+', $t ) ), 'strlen' ) );
			$all   = (bool) $parts;
			foreach ( $parts as $p ) {
				if ( ! self::has_phrase( $v, $p ) ) {
					$all = false;
					break;
				}
			}
			if ( $all ) {
				return true;
			}
		}
		return false;
	}

	/* ─────────────────────────── the Identity Pack ─────────────────────────── */

	/** Where each rule lives in an Identity Pack: pack file => [ path in that file => the rules key it sets ]. */
	const PACK = array(
		'mappings'             => array(
			'lead_sources.codes'                        => 'lead_sources.codes',
			'lead_sources.suffix_codes'                 => 'lead_sources.suffix_codes',
			'lead_sources.unknown_short_code_is_source' => 'lead_sources.unknown_short_code_is_source',
			'lead_sources.note_keywords'                => 'lead_sources.note_keywords',
			'lead_sources.note_tags'                    => 'lead_sources.note_tags',
			'lead_sources.aliases'                      => 'lead_sources.aliases',
			'lead_sources.short_note_is_source'         => 'lead_sources.short_note_is_source',
			'lead_sources.groups'                       => 'source_groups',
		),
		'catalog'              => array(
			'ledger.product_codes' => 'reference.product_codes',
			'ledger.brands'        => 'brands',
		),
		'document-conventions' => array(
			'ledger.self_codes'          => 'reference.self_codes',
			'ledger.location_line_names' => 'location_line_names',
			'ledger.label_number_words'  => 'label_number_words',
		),
		'rules'                => array(
			'ledger.history_from_year' => 'history_from_year',
			'ledger.intake_minutes'    => 'intake_minutes',
		),
	);

	/** Registers the ledger's rules with the Identity Pack (preview, apply, revert). */
	public static function pack_consumer( $consumers ) {
		$consumers = is_array( $consumers ) ? $consumers : array();
		$paths     = array();
		foreach ( self::PACK as $file => $map ) {
			$paths[ $file ] = array_keys( $map );
		}
		$consumers['sales-ledger'] = array(
			'label'    => function_exists( '__' ) ? __( 'Sales ledger', 'zorderz' ) : 'Sales ledger',
			'files'    => array_keys( self::PACK ),
			'paths'    => $paths,
			'preview'  => array( __CLASS__, 'pack_preview' ),
			'apply'    => array( __CLASS__, 'pack_apply' ),
			'snapshot' => array( __CLASS__, 'pack_snapshot' ),
			'restore'  => array( __CLASS__, 'pack_restore' ),
		);
		return $consumers;
	}

	/** What a pack carries for the ledger: rules key => [ file, path, value ]. Pure. */
	public static function pack_read( array $files ): array {
		$out = array();
		foreach ( self::PACK as $file => $map ) {
			$data = is_array( $files[ $file ] ?? null ) ? $files[ $file ] : array();
			foreach ( $map as $path => $key ) {
				$v = self::dig( $data, $path );
				if ( null !== $v ) {
					$out[ $key ] = array( $file, $path, $v );
				}
			}
		}
		return $out;
	}

	/** The stored rules with a pack's values laid over them; a list or map the pack carries replaces the stored one whole. Pure. */
	public static function pack_merge( array $stored, array $read ): array {
		foreach ( $read as $key => $row ) {
			$stored = self::put( $stored, (string) $key, $row[2] );
		}
		return $stored;
	}

	/** Preview rows: what each path the pack carries changes, checked the way the ledger reads it. */
	public static function pack_preview( array $files ): array {
		$stored = self::stored();
		$read   = self::pack_read( $files );
		$before = self::clean( array_replace_recursive( self::defaults(), $stored ) );
		$after  = self::clean( array_replace_recursive( self::defaults(), self::pack_merge( $stored, $read ) ) );
		$rows   = array();
		foreach ( $read as $key => $row ) {
			$was = self::dig( $before, (string) $key );
			$now = self::dig( $after, (string) $key );
			if ( self::blank( $now ) && ! self::blank( $row[2] ) ) {
				$rows[] = array( 'file' => $row[0], 'path' => $row[1], 'kind' => 'refused', 'why' => 'nothing in it passed the checks (codes are capital letters and digits; words are plain text)' );
				continue;
			}
			$rows[] = array( 'file' => $row[0], 'path' => $row[1], 'from' => self::summary( $was ), 'to' => self::summary( $now ) );
		}
		if ( $read && self::signature_of( $before ) !== self::signature_of( $after ) ) {
			$rows[] = array( 'file' => 'mappings', 'path' => 'lead_sources', 'kind' => 'note', 'note' => 'The ledger re-derives the lead source, town, salesperson and brands of its stored history on its next passes.' );
		}
		return $rows;
	}

	public static function pack_apply( array $files, $slug = '' ): array {
		$read = self::pack_read( $files );
		if ( ! $read ) {
			return array();
		}
		update_option( self::OPTION, self::pack_merge( self::stored(), $read ), false );
		self::flush();
		return array( sprintf( 'sales ledger: %d rule(s) set', count( $read ) ) );
	}

	/** What to keep before a pack writes: the stored option as it is (null when there is none). */
	public static function pack_snapshot( array $files = array(), $slug = '' ) {
		$v = function_exists( 'get_option' ) ? get_option( self::OPTION, null ) : null;
		return array( 'rules' => is_array( $v ) ? $v : null );
	}

	public static function pack_restore( $kept ): array {
		$v = is_array( $kept ) ? ( $kept['rules'] ?? null ) : null;
		if ( is_array( $v ) ) {
			update_option( self::OPTION, $v, false );
		} elseif ( function_exists( 'delete_option' ) ) {
			delete_option( self::OPTION );
		}
		self::flush();
		return array();
	}

	private static function stored(): array {
		$v = function_exists( 'get_option' ) ? get_option( self::OPTION, array() ) : array();
		return is_array( $v ) ? $v : array();
	}

	private static function signature_of( array $r ): string {
		return md5( (string) json_encode( array( $r['lead_sources'], $r['reference'], $r['location_line_names'], $r['brands'], $r['label_number_words'] ) ) );
	}

	private static function blank( $v ): bool {
		return null === $v || '' === $v || array() === $v || false === $v;
	}

	private static function summary( $v ): string {
		if ( is_bool( $v ) ) {
			return $v ? 'yes' : 'no';
		}
		if ( is_array( $v ) ) {
			return sprintf( '%d item(s)', count( $v ) );
		}
		return (string) $v;
	}

	/** A dotted path in an array, or null. */
	private static function dig( $a, string $path ) {
		foreach ( explode( '.', $path ) as $k ) {
			if ( ! is_array( $a ) || ! array_key_exists( $k, $a ) ) {
				return null;
			}
			$a = $a[ $k ];
		}
		return $a;
	}

	private static function put( array $a, string $path, $v ): array {
		$keys = explode( '.', $path );
		$last = array_pop( $keys );
		$ref  = &$a;
		foreach ( $keys as $k ) {
			if ( ! isset( $ref[ $k ] ) || ! is_array( $ref[ $k ] ) ) {
				$ref[ $k ] = array();
			}
			$ref = &$ref[ $k ];
		}
		$ref[ $last ] = $v;
		unset( $ref );
		return $a;
	}
}
