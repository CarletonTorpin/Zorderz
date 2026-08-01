<?php
/**
 * ZDZ_Doc_Conventions — the tenant "house style" for customer-facing documents,
 * applied ON OUTPUT (BID-9).
 *
 * A business writes its own conventions onto every estimate/invoice it produces:
 * which metadata lines lead the document, which closing line trails it, how a
 * job location and a rep's initials are formatted, how prices and dimensions are
 * rounded, the shape of a reference code, casing rules, phone format. Another
 * business's copy of every one of those is different — so none of them belong in
 * code. They live here as CONFIGURABLE strings/flags and are applied by the
 * document producer (the Estimate app) as a final overlay on its output.
 *
 * The old estimate creator baked these as a "mode": the location-line-first rule,
 * the "{City} - ({Initials})" sub-description, the "Submitted by: {initials}"
 * provenance line, the closing "$0 Tax and Installation Included" line and the
 * round-up-to-nearest-ten were compiled into the prompt and the engine. That made
 * one tenant's paperwork the platform's behaviour, and it put a company's roster,
 * places and brand into AI prompt text. This service replaces the mode.
 *
 * SHIP NEUTRAL. With nothing configured every overlay is a no-op: no forced
 * leading/trailing line, no provenance line, no rounding, no forced casing. The
 * pure formatters (location-line template, reference-code template, initials
 * delimiters) carry neutral CORE default TEMPLATES — placeholders only, naming
 * nothing — so a producer that asks for a location line gets a sensible shape
 * without the platform ever asserting a place, a person or a price.
 *
 * The initials short code is the party's code published by ZDZ_Party under the
 * key `initials` and matched case-insensitively (§13); reserved tokens that must
 * never be read as a rep code are DERIVED from the pack (territory place aliases +
 * mapping codes) via a filter, on top of a neutral accounting/English core set.
 *
 * One parser. parse_location_line() is the single reader every consumer
 * (attribution, analytics, commissions) shares, so the two-parsers-disagree
 * defect (a multi-rep "(GT & DM)" line splitting for pay but reading as one junk
 * token in analytics) cannot recur.
 *
 * Exposure:
 *   - PHP  : ZDZ_Doc_Conventions::apply_on_output( $estimate, $ctx )  (the overlay)
 *            plus the discrete formatters/parsers below.
 *   - Filter: zdz_document_conventions (merge/override the config)
 *   - REST : GET /wp-json/zorderz/v1/doc-conventions   (publishes to JS)
 *
 * Crosswalk: 03-geo-connections.md · Document Conventions D1–D24.
 *
 * @since   1.1.0
 * @package Zorderz
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZDZ_Doc_Conventions {

	/** Option holding the tenant's convention overlay (an array; empty = neutral). */
	const OPTION = 'zdz_document_conventions';

	/** Per-request memo of the resolved config. */
	private static $memo = null;

	/**
	 * Neutral accounting / English tokens that are never a person's initials.
	 * These name no company, person, product or place, so they are a CORE default;
	 * identity-bearing reserved tokens (a neighbourhood alias like a region code)
	 * arrive through the zdz_doc_reserved_tokens filter from the pack.
	 */
	const CORE_RESERVED_TOKENS = array(
		'NEW', 'VOID', 'PAID', 'DUE', 'NET', 'TBD', 'NA', 'NONE', 'COD', 'PO',
		'INV', 'EST', 'REF', 'QTY', 'EACH', 'EA', 'SET', 'KIT', 'USD', 'TAX',
		'SUB', 'WO', 'JOB', 'ADU', 'HOA',
	);

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/* ============================================================== *
	 *  CONFIG
	 * ============================================================== */

	/**
	 * The neutral default overlay. Every value here is either an empty
	 * behaviour-off default or a placeholder-only template — nothing names a
	 * company, person, product, place or price.
	 */
	public static function defaults(): array {
		return array(

			// D18 — casing. Default 'none' = passthrough (standard behaviour).
			'casing' => array(
				'mode'            => 'none',              // none | title | sentence
				'sentence_fields' => array( 'notes', 'sub_description' ),
				'preserve_tokens' => array(),             // derived; merged with codes at read time
			),

			// D17 — phone. Off by default (format => '' = passthrough).
			'phone' => array(
				'format' => '',                           // e.g. "{area}-{prefix}-{line}"
				'strip'  => array( ' ', '(', ')', '.', '+1' ),
			),

			// D13/D14 — rounding. increment 0 / unit '' = no rounding.
			'rounding' => array(
				'price' => array(
					'mode'        => 'ceil',              // ceil | round | floor
					'increment'   => 0,                   // 0 = off
					'applies_to'  => 'positive_only',     // positive_only | all
				),
				'dimensions' => array(
					'unit' => '',                         // '' = off; 'foot' rounds inches->feet
					'mode' => 'ceil',
				),
			),

			// D3/D4 — initials parenthetical. Delimiters are neutral punctuation.
			'initials' => array(
				'open'                  => '(',
				'close'                 => ')',
				'case'                  => 'upper',       // upper | as_is
				'multi_separator'       => ' & ',         // writer form
				'omit_when_empty'       => true,          // never "( )"
				'stored_with_delimiters' => false,        // pack stores GT, not "(GT)"
				'token_len'             => array( 'min' => 2, 'max' => 4 ),
				'parse'                 => array(
					'accept_separators'  => array( '/', ',', '&', '+', 'and' ),
					'case_insensitive'   => true,
					'strip_non_letters'  => true,
					'reserved_tokens'    => array(),      // merged with CORE_RESERVED_TOKENS + filter
				),
			),

			// D1/D2/D5/D6/D8/D10 — estimate line + location conventions.
			'estimate' => array(
				// D1 — leading metadata lines (default none). Each: id, description,
				// description_synonyms[], price, quantity, required, position.
				'leading'  => array(),
				// D10 — trailing metadata lines (default none). Each: id, text, price,
				// quantity, only_if (has_priced_line|always), normalize_variants[].
				'trailing' => array(),
				// D2 — location line. Placeholder-only template; a producer opts in by
				// declaring a leading line whose builder is 'location_line'.
				'location_line' => array(
					'format'   => '{locality} - ({initials}){note_sep}{note}',
					'note_sep' => ' ',
				),
				// D8 — provenance ("Submitted by: …"). Disabled by default.
				'provenance_line' => array(
					'enabled'         => false,
					'template'        => 'Submitted by: {initials}{ parenthetical}',
					'target_field'    => 'customer_notes',
					'omit_when_unset' => true,
					'dedupe'          => 'first_wins',
				),
				// D22 — line policy.
				'line_policy' => array(
					'merge'            => 'never',
					'drop'             => 'never',
					'allow_duplicates' => true,
				),
			),

			// D7/D21 — the ONE definition of a $0 non-product metadata line and the
			// phrase allowlist derived from it. Ships with generic ids only.
			'metadata_lines' => array(
				'price'            => 0,
				'quantity'         => 1,
				'ids'              => array( 'location', 'closing_note' ),
				'phrase_allowlist' => array(
					'location', 'see notes', 'no charge', 'n/c', 'included', 'incl.',
				),
			),

			// D11/D12 — reference code. Placeholder-only templates.
			'reference_code' => array(
				'write_template'             => '{postal}-{category}',
				'write_template_with_source' => '{postal}-{category}-{source}',
				'separator'                  => '-',
				'target_field'               => 'reference',
				'forbid_document_number'     => true,     // never use the doc number as a ref
				'split_on'                   => array( '-', '/' ),
				'accept_patterns'            => array(
					'{postal}-{category}',
					'{postal}-{category}-{source}',
					'{postal}-{source}',
				),
			),

			// D9 — customer-facing notes discipline (enforced deterministically).
			'notes' => array(
				'forbidden_phrases' => array(),           // pack supplies cost/markup words
				'revision_stamp'    => array(
					'template'      => "\nRev: {reason} ({initials}, {date})",
					'date_format'   => 'n/j/Y',
					'unknown_actor' => 'N/A',
				),
			),

			// D15 — per-party measurement notation. Default empty; overrides on Party.
			'measurement_notation' => array(
				'default' => '',
			),
		);
	}

	/**
	 * Resolved config: defaults ⟵ stored option ⟵ filter. Deep-merged so a tenant
	 * overriding one leaf never has to restate the tree.
	 */
	public static function config(): array {
		if ( null !== self::$memo ) {
			return self::$memo;
		}
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		$config = self::deep_merge( self::defaults(), $stored );

		/**
		 * Filter the resolved document-convention overlay. The Identity Pack loader
		 * (document-conventions.yml) writes the option; this filter lets a pack that
		 * generates derived lists (reserved tokens, casing preserve tokens) inject
		 * them without a stored write.
		 *
		 * @param array $config The resolved conventions.
		 */
		self::$memo = (array) apply_filters( 'zdz_document_conventions', $config );
		return self::$memo;
	}

	/** Dot-path getter over the resolved config. */
	public static function get( string $path, $default = null ) {
		$node = self::config();
		foreach ( explode( '.', $path ) as $seg ) {
			if ( is_array( $node ) && array_key_exists( $seg, $node ) ) {
				$node = $node[ $seg ];
			} else {
				return $default;
			}
		}
		return $node;
	}

	/** Persist an overlay (admin / pack import). Flushes the memo. */
	public static function save( array $overlay ): void {
		update_option( self::OPTION, $overlay );
		self::$memo = null;
	}

	public static function flush(): void {
		self::$memo = null;
	}

	/* ============================================================== *
	 *  THE OUTPUT OVERLAY  (the single entry point on output)
	 * ============================================================== */

	/**
	 * Apply every configured convention to a document about to leave the platform.
	 * The Estimate app calls this once, on output only — never during parsing and
	 * never as a mode compiled into a prompt. With an empty overlay this returns
	 * the estimate unchanged.
	 *
	 * @param array $estimate { line_items:array[], customer_notes:string, ... }
	 * @param array $ctx      { initials:string, parenthetical:string, user_id:int }
	 * @return array The estimate with conventions applied.
	 */
	public static function apply_on_output( array $estimate, array $ctx = array() ): array {
		$lines = isset( $estimate['line_items'] ) && is_array( $estimate['line_items'] )
			? $estimate['line_items'] : array();

		// 1) Price rounding (positive lines only; discounts exempt).
		foreach ( $lines as &$li ) {
			if ( isset( $li['unit_price'] ) && empty( $li['is_discount'] ) ) {
				$li['unit_price'] = self::round_price( (float) $li['unit_price'] );
			}
		}
		unset( $li );

		// 2) Leading metadata lines (e.g. a location line first).
		$lines = self::apply_leading_lines( $lines, $ctx );

		// 3) Trailing metadata lines (e.g. the closing $0 tax/installation line),
		//    only when at least one priced line exists.
		$lines = self::apply_trailing_lines( $lines, $ctx );

		$estimate['line_items'] = array_values( $lines );

		// 4) Provenance line into the customer-facing notes, deduped.
		$field = (string) self::get( 'estimate.provenance_line.target_field', 'customer_notes' );
		$prov  = self::provenance_line( (string) ( $ctx['initials'] ?? '' ), (string) ( $ctx['parenthetical'] ?? '' ) );
		if ( '' !== $prov ) {
			$existing = (string) ( $estimate[ $field ] ?? '' );
			$estimate[ $field ] = self::merge_provenance( $existing, $prov );
		}

		// 5) Casing normalisation across text fields (passthrough unless configured).
		if ( 'none' !== self::get( 'casing.mode', 'none' ) ) {
			$estimate = self::apply_casing( $estimate );
		}

		return $estimate;
	}

	/* ============================================================== *
	 *  LEADING / TRAILING LINES  (D1, D10)
	 * ============================================================== */

	/** Ensure every configured leading line is present, in order, at the front. */
	public static function apply_leading_lines( array $lines, array $ctx = array() ): array {
		$leading = (array) self::get( 'estimate.leading', array() );
		if ( empty( $leading ) ) {
			return $lines;
		}
		$prepend = array();
		foreach ( $leading as $spec ) {
			$desc     = (string) ( $spec['description'] ?? '' );
			$synonyms = array_map( 'strval', (array) ( $spec['description_synonyms'] ?? array() ) );
			// Already present? (match description or a synonym, case-insensitive)
			$found = false;
			foreach ( $lines as $li ) {
				$d = strtolower( trim( (string) ( $li['description'] ?? '' ) ) );
				if ( $d === strtolower( $desc ) || in_array( $d, array_map( 'strtolower', $synonyms ), true ) ) {
					$found = true;
					break;
				}
			}
			if ( $found ) {
				continue;
			}
			$prepend[] = array(
				'description'     => $desc,
				'sub_description' => (string) ( $spec['sub_description'] ?? '' ),
				'unit_price'      => (float) ( $spec['price'] ?? 0 ),
				'quantity'        => (int) ( $spec['quantity'] ?? 1 ),
				'is_metadata'     => true,
			);
		}
		return array_merge( $prepend, $lines );
	}

	/**
	 * Ensure every configured trailing line is present, last, once. A line whose
	 * only_if is has_priced_line is appended only when a positive-priced line
	 * exists; any prior variant matching normalize_variants is removed first, so
	 * the canonical line is never duplicated, reordered or priced.
	 */
	public static function apply_trailing_lines( array $lines, array $ctx = array() ): array {
		$trailing = (array) self::get( 'estimate.trailing', array() );
		if ( empty( $trailing ) ) {
			return $lines;
		}
		$has_priced = false;
		foreach ( $lines as $li ) {
			if ( (float) ( $li['unit_price'] ?? 0 ) > 0 ) {
				$has_priced = true;
				break;
			}
		}
		foreach ( $trailing as $spec ) {
			$only_if = (string) ( $spec['only_if'] ?? 'always' );
			if ( 'has_priced_line' === $only_if && ! $has_priced ) {
				continue;
			}
			$text     = (string) ( $spec['text'] ?? '' );
			$variants = array_map( 'strval', (array) ( $spec['normalize_variants'] ?? array() ) );

			// Remove any existing canonical line or variant.
			$lines = array_values( array_filter( $lines, function ( $li ) use ( $text, $variants ) {
				$d = (string) ( $li['description'] ?? '' );
				if ( strcasecmp( trim( $d ), trim( $text ) ) === 0 ) {
					return false;
				}
				foreach ( $variants as $rx ) {
					if ( '' !== $rx && @preg_match( '/' . str_replace( '/', '\/', $rx ) . '/i', $d ) ) {
						return false;
					}
				}
				return true;
			} ) );

			// Re-append canonically, last.
			$lines[] = array(
				'description'     => $text,
				'sub_description' => (string) ( $spec['sub_description'] ?? '' ),
				'unit_price'      => (float) ( $spec['price'] ?? 0 ),
				'quantity'        => (int) ( $spec['quantity'] ?? 1 ),
				'is_metadata'     => true,
			);
		}
		return $lines;
	}

	/* ============================================================== *
	 *  LOCATION LINE  (D2, D5, D6 — one parser)
	 * ============================================================== */

	/** Build a location sub-description: "{locality} - ({initials}) {note}". */
	public static function build_location_line( string $locality, array $codes = array(), string $note = '' ): string {
		$fmt      = (string) self::get( 'estimate.location_line.format', '{locality} - ({initials}){note_sep}{note}' );
		$note_sep = (string) self::get( 'estimate.location_line.note_sep', ' ' );
		$paren    = self::format_initials( $codes ); // "(GT & DM)" or ''

		// The template writes "({initials})"; format_initials already includes the
		// delimiters, so collapse the template's literal parens around it.
		$initials_inner = $paren;
		if ( '' !== $paren ) {
			$open  = preg_quote( (string) self::get( 'initials.open', '(' ), '/' );
			$close = preg_quote( (string) self::get( 'initials.close', ')' ), '/' );
			// strip one layer of delimiters so the template's own parens wrap it once
			$initials_inner = preg_replace( '/^' . $open . '(.*)' . $close . '$/', '$1', $paren );
		}

		$out = strtr( $fmt, array(
			'{locality}' => $locality,
			'{initials}' => $initials_inner,
			'{note_sep}' => ( '' !== $note ? $note_sep : '' ),
			'{note}'     => $note,
		) );

		// If there were no codes and omit_when_empty is set, remove an empty "()".
		if ( '' === $initials_inner && self::get( 'initials.omit_when_empty', true ) ) {
			$open  = preg_quote( (string) self::get( 'initials.open', '(' ), '/' );
			$close = preg_quote( (string) self::get( 'initials.close', ')' ), '/' );
			$out   = preg_replace( '/\s*-?\s*' . $open . '\s*' . $close . '/', '', $out );
		}
		return trim( $out );
	}

	/**
	 * THE shared location-line parser. Returns the locality, the resolved rep
	 * codes, and any trailing note. Every consumer (attribution, analytics,
	 * commissions) reads through this, so a multi-rep "(GT & DM)" line can never
	 * split one way for pay and another for analytics.
	 *
	 * @return array{locality:string,party_codes:array,note:string}
	 */
	public static function parse_location_line( string $text ): array {
		$text  = trim( $text );
		$open  = (string) self::get( 'initials.open', '(' );
		$close = (string) self::get( 'initials.close', ')' );
		$o     = preg_quote( $open, '/' );
		$c     = preg_quote( $close, '/' );

		$codes = array();
		$note  = '';
		if ( preg_match( '/' . $o . '([^' . $c . ']*)' . $c . '/', $text, $m ) ) {
			$codes = self::parse_initials( $m[1] );
			// note = anything after the closing delimiter
			$after = substr( $text, (int) strpos( $text, $m[0] ) + strlen( $m[0] ) );
			$note  = trim( ltrim( trim( (string) $after ), '-: ' ) );
			$text  = trim( substr( $text, 0, (int) strpos( $text, $m[0] ) ) );
		}
		// locality = leading text, minus a trailing separator dash.
		$locality = trim( rtrim( $text, " -" ) );
		return array(
			'locality'    => $locality,
			'party_codes' => $codes,
			'note'        => $note,
		);
	}

	/* ============================================================== *
	 *  INITIALS  (D3, D4, A16)
	 * ============================================================== */

	/** Format a list of rep codes as the writer form, e.g. "(GT & DM)" or ''. */
	public static function format_initials( array $codes ): string {
		$codes = array_values( array_filter( array_map( 'trim', $codes ), 'strlen' ) );
		if ( 'upper' === self::get( 'initials.case', 'upper' ) ) {
			$codes = array_map( 'strtoupper', $codes );
		}
		if ( empty( $codes ) ) {
			return self::get( 'initials.omit_when_empty', true ) ? '' : self::get( 'initials.open', '(' ) . self::get( 'initials.close', ')' );
		}
		$sep = (string) self::get( 'initials.multi_separator', ' & ' );
		return self::get( 'initials.open', '(' ) . implode( $sep, $codes ) . self::get( 'initials.close', ')' );
	}

	/**
	 * Parse a code group into normalised rep codes. Accepts the looser reader
	 * separators (/ , & + and), uppercases, strips non-letters, keeps tokens of
	 * the configured length, and drops reserved tokens (a place alias or a mapping
	 * code is never a rep).
	 *
	 * @return string[] Uppercased codes.
	 */
	public static function parse_initials( string $group ): array {
		$group = trim( $group );
		if ( '' === $group ) {
			return array();
		}
		$seps = (array) self::get( 'initials.parse.accept_separators', array( '/', ',', '&', '+', 'and' ) );
		// Normalise the word 'and' to a symbol separator first.
		$group = preg_replace( '/\band\b/i', '/', $group );
		$class = '';
		foreach ( $seps as $s ) {
			if ( 'and' === $s ) {
				continue;
			}
			$class .= preg_quote( $s, '/' );
		}
		$parts = preg_split( '/[' . $class . '\s]+/', $group );

		$min      = (int) self::get( 'initials.token_len.min', 2 );
		$max      = (int) self::get( 'initials.token_len.max', 4 );
		$reserved = array_map( 'strtoupper', self::reserved_tokens() );

		$out = array();
		foreach ( (array) $parts as $p ) {
			if ( self::get( 'initials.parse.strip_non_letters', true ) ) {
				$p = preg_replace( '/[^A-Za-z]/', '', $p );
			}
			$p = strtoupper( trim( (string) $p ) );
			if ( '' === $p || strlen( $p ) < $min || strlen( $p ) > $max ) {
				continue;
			}
			if ( in_array( $p, $reserved, true ) ) {
				continue; // a reserved token is never a rep code
			}
			$out[ $p ] = true;
		}
		return array_keys( $out );
	}

	/**
	 * Tokens that must never be read as a rep code. The neutral CORE set (generic
	 * accounting/English words) plus whatever the pack derives from territory
	 * place aliases and mapping codes.
	 */
	public static function reserved_tokens(): array {
		$configured = (array) self::get( 'initials.parse.reserved_tokens', array() );
		$merged     = array_merge( self::CORE_RESERVED_TOKENS, $configured );
		/**
		 * Filter the reserved-token set. The pack loader adds place aliases and
		 * source/product codes here so adding a neighbourhood cannot silently
		 * create a spurious rep (crosswalk A16).
		 *
		 * @param string[] $merged
		 */
		$merged = (array) apply_filters( 'zdz_doc_reserved_tokens', $merged );
		return array_values( array_unique( array_map( 'strtoupper', array_map( 'strval', $merged ) ) ) );
	}

	/* ============================================================== *
	 *  PROVENANCE LINE  (D8)
	 * ============================================================== */

	/**
	 * "Submitted by: {initials} {(XX)}" or '' when disabled or unset. Omits the
	 * parenthetical when absent; never emits an empty form.
	 */
	public static function provenance_line( string $initials, string $parenthetical = '' ): string {
		if ( ! self::get( 'estimate.provenance_line.enabled', false ) ) {
			return '';
		}
		$initials = trim( $initials );
		if ( '' === $initials && self::get( 'estimate.provenance_line.omit_when_unset', true ) ) {
			return '';
		}
		$tmpl = (string) self::get( 'estimate.provenance_line.template', 'Submitted by: {initials}{ parenthetical}' );
		$paren = trim( $parenthetical );
		$line  = strtr( $tmpl, array(
			'{initials}'      => $initials,
			'{ parenthetical}' => ( '' !== $paren ? ' ' . $paren : '' ),
			'{parenthetical}' => $paren,
		) );
		return trim( $line );
	}

	/** True if a line of text is a provenance line (for dedupe / read-back). */
	public static function is_provenance_line( string $line ): bool {
		$tmpl   = (string) self::get( 'estimate.provenance_line.template', 'Submitted by: {initials}' );
		$prefix = trim( (string) strstr( $tmpl, '{', true ) ); // text before first placeholder
		if ( '' === $prefix ) {
			return false;
		}
		return stripos( trim( $line ), $prefix ) === 0;
	}

	/**
	 * Merge a provenance line into existing notes, deduped first-wins: an existing
	 * provenance line is kept and the new one dropped; otherwise the new line is
	 * appended on its own line.
	 */
	public static function merge_provenance( string $existing, string $provenance ): string {
		$existing = (string) $existing;
		$lines    = preg_split( '/\r\n|\r|\n/', $existing );
		foreach ( (array) $lines as $l ) {
			if ( self::is_provenance_line( (string) $l ) ) {
				return $existing; // first wins — keep what is already there
			}
		}
		$existing = rtrim( $existing );
		return '' === $existing ? $provenance : $existing . "\n" . $provenance;
	}

	/* ============================================================== *
	 *  ROUNDING  (D13, D14)
	 * ============================================================== */

	/** Round a price per config. increment 0 = passthrough. Discounts pass raw. */
	public static function round_price( float $raw ): float {
		$inc = (float) self::get( 'rounding.price.increment', 0 );
		if ( $inc <= 0 ) {
			return $raw;
		}
		$applies = (string) self::get( 'rounding.price.applies_to', 'positive_only' );
		if ( 'positive_only' === $applies && $raw <= 0 ) {
			return $raw;
		}
		$mode = (string) self::get( 'rounding.price.mode', 'ceil' );
		return self::round_to( $raw, $inc, $mode );
	}

	/** Round a dimension in inches to whole feet per config. unit '' = passthrough. */
	public static function round_dimension_feet( float $inches ): float {
		if ( 'foot' !== self::get( 'rounding.dimensions.unit', '' ) ) {
			return $inches;
		}
		$mode = (string) self::get( 'rounding.dimensions.mode', 'ceil' );
		$feet = $inches / 12.0;
		return self::round_to( $feet, 1, $mode );
	}

	private static function round_to( float $v, float $inc, string $mode ): float {
		if ( $inc <= 0 ) {
			return $v;
		}
		$n = $v / $inc;
		switch ( $mode ) {
			case 'floor':
				$n = floor( $n );
				break;
			case 'round':
				$n = round( $n );
				break;
			case 'ceil':
			default:
				$n = ceil( $n );
				break;
		}
		return (float) ( $n * $inc );
	}

	/* ============================================================== *
	 *  REFERENCE CODE  (D11, D12 — one writer, many readers)
	 * ============================================================== */

	/**
	 * Build a reference code from tokens. Uses the with-source template when a
	 * source token is present, else the base template.
	 *
	 * @param array $tokens { postal:string, category:string, source:string }
	 */
	public static function format_reference( array $tokens ): string {
		$postal   = trim( (string) ( $tokens['postal'] ?? '' ) );
		$category = trim( (string) ( $tokens['category'] ?? '' ) );
		$source   = trim( (string) ( $tokens['source'] ?? '' ) );
		$tmpl     = ( '' !== $source )
			? (string) self::get( 'reference_code.write_template_with_source', '{postal}-{category}-{source}' )
			: (string) self::get( 'reference_code.write_template', '{postal}-{category}' );
		$out = strtr( $tmpl, array(
			'{postal}'   => $postal,
			'{category}' => $category,
			'{source}'   => $source,
		) );
		$sep = (string) self::get( 'reference_code.separator', '-' );
		return trim( preg_replace( '/' . preg_quote( $sep, '/' ) . '+/', $sep, trim( $out, $sep . ' ' ) ) );
	}

	/**
	 * Parse a reference code into its tokens by splitting on the configured
	 * separators. Positional: [postal, category, source]. Unrecognised codes
	 * return the raw string under 'raw'.
	 *
	 * @return array{postal:string,category:string,source:string,raw:string}
	 */
	public static function parse_reference( string $code ): array {
		$code  = trim( $code );
		$split = (array) self::get( 'reference_code.split_on', array( '-', '/' ) );
		$class = '';
		foreach ( $split as $s ) {
			$class .= preg_quote( $s, '/' );
		}
		$parts = preg_split( '/[' . $class . ']+/', $code );
		$parts = array_values( array_filter( array_map( 'trim', (array) $parts ), 'strlen' ) );

		$out = array( 'postal' => '', 'category' => '', 'source' => '', 'raw' => $code );
		if ( empty( $parts ) ) {
			return $out;
		}
		// A leading all-digits token is the postal.
		if ( preg_match( '/^\d{3,}$/', $parts[0] ) ) {
			$out['postal'] = array_shift( $parts );
		}
		if ( isset( $parts[0] ) ) {
			$out['category'] = $parts[0];
		}
		if ( isset( $parts[1] ) ) {
			$out['source'] = $parts[1];
		}
		return $out;
	}

	/* ============================================================== *
	 *  METADATA LINES  (D7, D21)
	 * ============================================================== */

	/** The configured ids of $0 non-product metadata lines. */
	public static function metadata_line_ids(): array {
		return array_map( 'strval', (array) self::get( 'metadata_lines.ids', array() ) );
	}

	/**
	 * Is this a $0 metadata line rather than a product/service? A $0 line whose
	 * description matches the derived phrase allowlist, or a marked leading/trailing
	 * line, is metadata. Consumers read this instead of re-deriving "is this a
	 * product?" from text (crosswalk D7/D21).
	 */
	public static function is_metadata_line( string $description, $unit_price = 0 ): bool {
		if ( (float) $unit_price > 0 ) {
			return false; // a priced line is a product/service by definition
		}
		$desc = strtolower( trim( $description ) );
		if ( '' === $desc ) {
			return false;
		}
		foreach ( self::phrase_allowlist() as $phrase ) {
			if ( '' !== $phrase && false !== strpos( $desc, strtolower( $phrase ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The $0-line phrase allowlist, DERIVED from metadata_lines + the configured
	 * leading/trailing line texts, so adding a metadata line automatically stops
	 * it being flagged as an un-priced product.
	 */
	public static function phrase_allowlist(): array {
		$phrases = (array) self::get( 'metadata_lines.phrase_allowlist', array() );
		foreach ( (array) self::get( 'estimate.leading', array() ) as $l ) {
			if ( ! empty( $l['description'] ) ) {
				$phrases[] = strtolower( (string) $l['description'] );
			}
			foreach ( (array) ( $l['description_synonyms'] ?? array() ) as $syn ) {
				$phrases[] = strtolower( (string) $syn );
			}
		}
		foreach ( (array) self::get( 'estimate.trailing', array() ) as $l ) {
			if ( ! empty( $l['text'] ) ) {
				$phrases[] = strtolower( (string) $l['text'] );
			}
		}
		return array_values( array_unique( array_map( 'strval', $phrases ) ) );
	}

	/* ============================================================== *
	 *  CASING  (D18)
	 * ============================================================== */

	/** Title-case a string while preserving the reserved/brand/code tokens. */
	public static function to_title( string $s ): string {
		if ( '' === trim( $s ) ) {
			return $s;
		}
		$preserve = array_map( 'strtoupper', self::preserve_tokens() );
		$words    = preg_split( '/(\s+)/', $s, -1, PREG_SPLIT_DELIM_CAPTURE );
		$out      = '';
		foreach ( (array) $words as $w ) {
			if ( preg_match( '/^\s+$/', $w ) || '' === $w ) {
				$out .= $w;
				continue;
			}
			$bare = preg_replace( '/[^A-Za-z]/', '', $w );
			if ( '' !== $bare && in_array( strtoupper( $bare ), $preserve, true ) ) {
				$out .= strtoupper( $w );
			} else {
				$out .= function_exists( 'mb_convert_case' )
					? mb_convert_case( strtolower( $w ), MB_CASE_TITLE, 'UTF-8' )
					: ucwords( strtolower( $w ) );
			}
		}
		return $out;
	}

	/** Tokens preserved verbatim under casing: config + reserved + party initials. */
	public static function preserve_tokens(): array {
		$tokens = (array) self::get( 'casing.preserve_tokens', array() );
		$tokens = array_merge( $tokens, self::reserved_tokens() );
		if ( class_exists( 'ZDZ_Party' ) && method_exists( 'ZDZ_Party', 'selectable_people' ) ) {
			foreach ( (array) ZDZ_Party::selectable_people() as $p ) {
				if ( ! empty( $p['initials'] ) ) {
					$tokens[] = (string) $p['initials'];
				}
			}
		}
		/** @param string[] $tokens */
		$tokens = (array) apply_filters( 'zdz_doc_preserve_tokens', $tokens );
		return array_values( array_unique( array_map( 'strval', $tokens ) ) );
	}

	/** Apply casing across an estimate's text fields. */
	private static function apply_casing( array $estimate ): array {
		$mode = (string) self::get( 'casing.mode', 'none' );
		if ( 'none' === $mode ) {
			return $estimate;
		}
		$conv = function ( $v ) use ( $mode ) {
			$v = (string) $v;
			if ( 'title' === $mode ) {
				return self::to_title( $v );
			}
			if ( 'sentence' === $mode && '' !== trim( $v ) ) {
				return function_exists( 'mb_strtoupper' )
					? mb_strtoupper( mb_substr( $v, 0, 1 ) ) . mb_substr( $v, 1 )
					: ucfirst( $v );
			}
			return $v;
		};
		foreach ( array( 'customer_name', 'customer_street', 'customer_city' ) as $f ) {
			if ( isset( $estimate[ $f ] ) ) {
				$estimate[ $f ] = $conv( $estimate[ $f ] );
			}
		}
		if ( ! empty( $estimate['line_items'] ) && is_array( $estimate['line_items'] ) ) {
			foreach ( $estimate['line_items'] as &$li ) {
				if ( isset( $li['description'] ) ) {
					$li['description'] = $conv( $li['description'] );
				}
			}
			unset( $li );
		}
		return $estimate;
	}

	/* ============================================================== *
	 *  PHONE  (D17)
	 * ============================================================== */

	/** Normalise a phone number per config. Empty format = passthrough. */
	public static function format_phone( string $raw ): string {
		$fmt = (string) self::get( 'phone.format', '' );
		if ( '' === $fmt ) {
			return trim( $raw );
		}
		$digits = preg_replace( '/\D+/', '', $raw );
		if ( 11 === strlen( $digits ) && '1' === $digits[0] ) {
			$digits = substr( $digits, 1 );
		}
		if ( 10 !== strlen( $digits ) ) {
			return trim( $raw ); // unknown shape — leave as-is
		}
		return strtr( $fmt, array(
			'{area}'   => substr( $digits, 0, 3 ),
			'{prefix}' => substr( $digits, 3, 3 ),
			'{line}'   => substr( $digits, 6, 4 ),
		) );
	}

	/* ============================================================== *
	 *  MEASUREMENT NOTATION  (D15 — per-party)
	 * ============================================================== */

	/**
	 * The measurement-notation text for a user: the platform default overlaid with
	 * the party's own override. The override lives on the party (user meta, renamed
	 * from the legacy key, read-time aliased).
	 */
	public static function measurement_notation( int $user_id = 0 ): string {
		$default = (string) self::get( 'measurement_notation.default', '' );
		if ( $user_id <= 0 ) {
			return $default;
		}
		$override = (string) get_user_meta( $user_id, 'zdz_notation_profile', true );
		if ( '' === $override ) {
			$override = (string) get_user_meta( $user_id, 'tsec_notation_profile', true ); // legacy read-time alias
		}
		return '' !== $override ? $override : $default;
	}

	/* ============================================================== *
	 *  REST — publish the conventions to JS
	 * ============================================================== */

	public static function register_routes(): void {
		register_rest_route(
			ZDZ_REST_NS,
			'/doc-conventions',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'rest_conventions' ),
				'permission_callback' => function () {
					return is_user_logged_in();
				},
			)
		);
	}

	public static function rest_conventions( WP_REST_Request $request ) {
		$c = self::config();
		// Publish the read-only view a widget needs; secrets/none here anyway.
		return rest_ensure_response( array(
			'conventions'     => $c,
			'reserved_tokens' => self::reserved_tokens(),
			'preserve_tokens' => self::preserve_tokens(),
		) );
	}

	/* ============================================================== *
	 *  Internals
	 * ============================================================== */

	/** Recursive array merge where scalar/leaf values from $over win. */
	private static function deep_merge( array $base, array $over ): array {
		foreach ( $over as $k => $v ) {
			if ( is_array( $v ) && isset( $base[ $k ] ) && is_array( $base[ $k ] ) && self::is_assoc( $base[ $k ] ) ) {
				$base[ $k ] = self::deep_merge( $base[ $k ], $v );
			} else {
				$base[ $k ] = $v;
			}
		}
		return $base;
	}

	private static function is_assoc( array $a ): bool {
		if ( array() === $a ) {
			return true;
		}
		return array_keys( $a ) !== range( 0, count( $a ) - 1 );
	}
}

ZDZ_Doc_Conventions::init();
