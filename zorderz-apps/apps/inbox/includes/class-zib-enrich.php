<?php
/**
 * ZIB_Enrich — ingest-time enrichment: the per-message "index card" + SUM-able extracts.
 *
 * WHY (P6): the P1 index stored subject / 255-char snippet / parties + an ENCRYPTED,
 * unsearchable body — so body content past the preview was unfindable and there was
 * nothing to COUNT. This class reads each in-scope body ONCE, at ingest (the safest
 * place — no user query, no other mailbox in context), and derives a bounded, sanitized
 * card so the live chat model can query structured fields and NEVER re-read a raw body.
 *
 * P6a (this drop) is the DETERMINISTIC half — a pure body cleaner + regex extractors +
 * seed-taxonomy tagging. P6b adds the quarantined LLM synthesizer for gist / entities /
 * commitments and open-vocabulary tags; ENRICH_VER bumps then and the backfill re-runs.
 *
 * The parse methods (clean_body, extract_facts, deterministic_tags, is_automated_sender)
 * are PURE (no WP / DB / Graph), so they are unit-tested directly (zib-enrich-test.php).
 * enrich_message() / backfill_batch() are the WP/DB seam; both fail SOFT — a bad parse or
 * DB hiccup logs and returns, never breaking ingestion.
 *
 * PRODUCT TAXONOMY is Identity, not Core (v1.8 generalization). The mechanism —
 * canonicalise a supplier's many phrasings for one product to a single family label so
 * counts aggregate — lives here; the specific families a business sells are supplied via
 * the `zib_product_families` / `zib_product_hint_terms` filters (an Identity Pack fills
 * them from its catalog). Core ships NONE, so an unconfigured install simply falls back to
 * the generic last-words label and names no product.
 *
 * @since 0.6.0 (P6a — enrichment foundation)
 * @package Zorderz
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZIB_Enrich {

	/** Enrichment version. Bumped when the derivation changes so backfill re-runs.
	 *  v2 (P6c.1): "Roll × N" line-item extraction + canonical product family + variant (color/size)
	 *  + nearest order-ref for de-dup. Re-enriches existing mail so item counts become computable. */
	const ENRICH_VER      = 2;

	const MAX_CLEAN_CH     = 20000; // stored cleaned-body cap (retrieval + storage sanity)
	const MAX_EXTRACTS     = 40;    // per-message cap ("within data constraints")
	const GIST_CH          = 200;
	const BACKFILL_PER_TICK = 40;   // bounded re-enrich batch per cron tick

	// ── tables ──────────────────────────────────────────────────────
	private static function t_msg(): string { global $wpdb; return $wpdb->prefix . 'zib_messages'; }
	private static function t_ext(): string { global $wpdb; return $wpdb->prefix . 'zib_extracts'; }

	// ════════════════════════════════════════════════════════════════
	//  PURE parse layer (no WP / DB — unit-tested directly)
	// ════════════════════════════════════════════════════════════════

	/** HTML → plain text: drop script/style/comments, block tags → newlines, decode entities. PURE. */
	public static function html_to_text( string $html ): string {
		$s = preg_replace( '#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $html );
		$s = preg_replace( '#<!--.*?-->#s', ' ', (string) $s );
		$s = preg_replace( '#<br\s*/?>#i', "\n", (string) $s );
		$s = preg_replace( '#</(p|div|tr|li|h[1-6]|table|blockquote|ul|ol)>#i', "\n", (string) $s );
		$s = strip_tags( (string) $s );
		$s = html_entity_decode( (string) $s, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		return (string) $s;
	}

	/**
	 * Cut quoted reply history so the cleaned text (and any excerpt) is the NEW content,
	 * not an old copy that would out-rank the real message. Truncates at the earliest of
	 * the common quote markers. PURE.
	 */
	public static function strip_quoted( string $text ): string {
		$markers = array(
			'/^\s*On .{1,180}\bwrote:\s*$/mi',            // Gmail/Apple "On <date>, X wrote:"
			'/^\s*-{2,}\s*Original Message\s*-{2,}/mi',    // Outlook
			'/^\s*-{2,}\s*Forwarded message\s*-{2,}/mi',   // Gmail forward
			'/^\s*_{5,}\s*$/m',                            // Outlook divider line
			'/^\s*From:\s?.{1,200}$(?=\s*^\s*(Sent|Date|To):)/mi', // Outlook header block
			'/^\s*Sent from my \w+/mi',                    // mobile sig-ish preface
		);
		$cut = strlen( $text );
		foreach ( $markers as $re ) {
			if ( preg_match( $re, $text, $m, PREG_OFFSET_CAPTURE ) ) {
				$cut = min( $cut, (int) $m[0][1] );
			}
		}
		// A run of >-quoted lines: cut from the first one if it starts a block.
		if ( preg_match( '/^\s*>.*(?:\n\s*>.*){1,}/m', $text, $m, PREG_OFFSET_CAPTURE ) ) {
			$cut = min( $cut, (int) $m[0][1] );
		}
		return ( $cut < strlen( $text ) ) ? substr( $text, 0, $cut ) : $text;
	}

	/** Drop a trailing signature block after the standard "-- " delimiter. Conservative. PURE. */
	public static function strip_signature( string $text ): string {
		if ( preg_match( '/^\s*--\s*$/m', $text, $m, PREG_OFFSET_CAPTURE ) ) {
			return substr( $text, 0, (int) $m[0][1] );
		}
		return $text;
	}

	/** Full deterministic clean: html→text, de-quote, de-sign, collapse whitespace, cap. PURE. */
	public static function clean_body( string $raw, string $format ): string {
		$s = ( 'html' === strtolower( $format ) ) ? self::html_to_text( $raw ) : $raw;
		$s = self::strip_quoted( $s );
		$s = self::strip_signature( $s );
		$s = str_replace( array( "\r\n", "\r" ), "\n", $s );
		$s = preg_replace( '/[ \t]+/', ' ', $s );          // horizontal runs
		$s = preg_replace( '/\n{3,}/', "\n\n", (string) $s ); // vertical runs
		$s = trim( (string) $s );
		if ( strlen( $s ) > self::MAX_CLEAN_CH ) {
			$s = substr( $s, 0, self::MAX_CLEAN_CH );
		}
		return $s;
	}

	/** One-line deterministic gist (P6a): first meaningful line, else the subject. PURE. */
	public static function first_line_gist( string $clean, string $subject ): string {
		foreach ( preg_split( '/\n+/', $clean ) as $line ) {
			$line = trim( $line );
			if ( strlen( $line ) >= 12 && preg_match( '/[a-z]/i', $line ) ) {
				return self::clip( $line, self::GIST_CH );
			}
		}
		return self::clip( trim( $subject ), self::GIST_CH );
	}

	/** Automated (machine) sender? Mirrors the gatekeeper's is_nonhuman spirit, kept local & pure. PURE. */
	public static function is_automated_sender( string $addr, string $from_name ): bool {
		$addr = strtolower( trim( $addr ) );
		if ( '' === $addr || false === strpos( $addr, '@' ) ) {
			return false;
		}
		list( $local, $domain ) = array_pad( explode( '@', $addr, 2 ), 2, '' );
		$tokens = '(?:no-?reply|do-?not-?reply|donotreply|noreply|notifications?|notify|mailer(?:-daemon)?|bounce[sd]?|postmaster|newsletter|updates?|alerts?|automated|system|mailer|marketing|news|info)';
		// whole-component match anywhere in the local part (so "analytics-noreply" is caught,
		// but "noreplyman" is NOT — the review's earlier false-positive guard).
		if ( preg_match( '/(?:^|[._+-])' . $tokens . '(?:$|[._+-])/', $local ) ) {
			return true;
		}
		// automated subdomain label (mail./email./billing./notifications./bounce./send.)
		if ( preg_match( '/^(?:mail|email|billing|notifications?|mailer|bounce|send|reply|em|e)\./', $domain ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Deterministic structured extraction over the cleaned body + subject. Returns rows
	 * shaped for wp_zib_extracts (no ids). Bounded to MAX_EXTRACTS. PURE.
	 *
	 * @return array<int,array{kind:string,label:string,qty:?float,unit:string,amount:?float,currency:string,ref:string,raw_span:string,confidence:float}>
	 */
	public static function extract_facts( string $clean, string $subject ): array {
		$hay = $subject . "\n" . $clean;
		$out = array();

		// (1) money — $1,234.56 / $75
		if ( preg_match_all( '/\$\s?([0-9][0-9,]*(?:\.[0-9]{1,2})?)/', $hay, $mm, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $mm[1] as $i => $cap ) {
				$amt = (float) str_replace( ',', '', $cap[0] );
				$out[] = self::row( 'money', '', null, '', $amt, 'USD', '', self::span( $hay, (int) $mm[0][ $i ][1] ), 0.90 );
			}
		}

		// (2) order / PO / invoice / confirmation reference
		if ( preg_match_all( '/\b(?:P\.?\s?O\.?|purchase order|order|invoice|inv|confirmation|conf|ref(?:erence)?)\s*(?:number|no\.?|#)?\s*[:#]?\s*([A-Z0-9][A-Z0-9\-]{2,})\b/i', $hay, $rm, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $rm[1] as $i => $cap ) {
				$ref = trim( $cap[0] );
				if ( preg_match( '/[0-9]/', $ref ) ) { // require a digit so plain words don't match
					$out[] = self::row( 'order_ref', '', null, '', null, '', strtoupper( $ref ), self::span( $hay, (int) $rm[0][ $i ][1] ), 0.70 );
				}
			}
		}

		// (3) product + quantity — "3 rolls (of material)", "16 screens", "5 yards of fabric"
		$units = 'rolls?|units?|yards?|yds?|feet|foot|ft|boxes?|cases?|pieces?|pcs?|screens?|doors?|sheets?|bundles?|pallets?|sq\.?\s?ft|sqft';
		if ( preg_match_all( '/\b([0-9]+(?:\.[0-9]+)?)\s+(' . $units . ')\b(?:\s+of\s+([a-z][a-z0-9\-\' ]{2,40}?))?(?=[\s.,;:!?)]|$)/i', $hay, $pm, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $pm[1] as $i => $cap ) {
				$qty   = (float) $cap[0];
				$unit  = self::canon_unit( $pm[2][ $i ][0] );
				$label = isset( $pm[3][ $i ] ) ? self::norm_label( (string) $pm[3][ $i ][0] ) : '';
				if ( '' === $label ) {
					$label = self::product_hint( $hay, (int) $pm[0][ $i ][1] );
				}
				$out[] = self::row( 'product', $label, $qty, $unit, null, '', '', self::span( $hay, (int) $pm[0][ $i ][1] ), '' === $label ? 0.55 : 0.75 );
			}
		}

		// (4) e-commerce line-item quantity — "<Product> Roll in Black × 1", "White Roll × 2".
		// Suppliers write the qty AFTER the noun ("Roll × N"), which pattern (3) ("N rolls") misses.
		// Anchored on a unit noun + "× N": canonicalise the product FAMILY from the text before the
		// noun (so a business's several phrasings for one product fold to one label the aggregate can
		// match — the family map is Identity-supplied; see canon_product), capture color + size as a
		// variant descriptor (stored in raw_span), and attach the message's NEAREST order # as ref so
		// the redundant confirmation / shipment / delivery emails for the SAME order are de-duplicated
		// at aggregation time (owner_aggregate groups by ref). ×  = U+00D7; plain "x"/"X" also accepted.
		$order_refs = array();
		if ( preg_match_all( '/\border\s*#?\s*([0-9]{3,})\b/i', $hay, $om, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $om[1] as $oc ) {
				$order_refs[] = array( 'ref' => (string) $oc[0], 'off' => (int) $oc[1] );
			}
		}
		if ( preg_match_all( '/\b(rolls?|panels?|sheets?|screens?|vents?)\b\s*(?:in\s+([a-z]+)\b\s*)?[\x{00D7}xX]\s*([0-9]{1,4})\b/iu', $hay, $em, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $em[3] as $i => $qcap ) {
				$noun_off = (int) $em[1][ $i ][1];
				$before   = substr( $hay, max( 0, $noun_off - 64 ), min( 64, $noun_off ) );
				$after    = substr( $hay, $noun_off, 120 );
				$qty      = (float) $qcap[0];
				$unit     = self::canon_unit( (string) $em[1][ $i ][0] );
				$label    = self::canon_product( $before . ' ' . (string) $em[1][ $i ][0] );
				$color    = ( isset( $em[2][ $i ] ) && '' !== (string) $em[2][ $i ][0] )
					? self::norm_color( (string) $em[2][ $i ][0] )
					: self::near_color( $before . ' ' . $after );
				$size     = self::near_size( $after );
				$ref      = self::nearest_ref( $order_refs, $noun_off );
				$variant  = trim( ( '' !== $color ? ucfirst( $color ) : '' ) . ( '' !== $size ? ( ( '' !== $color ? ' ' : '' ) . $size ) : '' ) );
				$out[]    = self::row( 'product', $label, $qty, $unit, null, '', $ref, ( '' !== $variant ? $variant : self::span( $hay, $noun_off ) ), '' !== $label ? 0.82 : 0.6 );
			}
		}

		if ( count( $out ) > self::MAX_EXTRACTS ) {
			$out = array_slice( $out, 0, self::MAX_EXTRACTS );
		}
		return $out;
	}

	/**
	 * Deterministic seed-taxonomy tags. Returns tag_keys (subset of the global seed set).
	 * PURE. (P6b adds open-vocabulary tags via the quarantined synthesizer.)
	 *
	 * @return string[]
	 */
	public static function deterministic_tags( string $clean, string $subject, string $from_addr, string $from_name, string $direction, string $class, bool $has_attachments, array $facts ): array {
		$h    = strtolower( $subject . "\n" . $clean );
		$tags = array();
		$auto = self::is_automated_sender( $from_addr, $from_name );

		if ( $auto ) { $tags[] = 'automated'; }
		if ( preg_match( '/\b(invoice|billed|amount due|balance due)\b/', $h ) ) { $tags[] = 'invoice'; }
		if ( preg_match( '/\b(receipt|payment received|thanks? for your payment|paid in full)\b/', $h ) ) { $tags[] = 'receipt'; }
		if ( preg_match( '/\b(order confirmation|your order|order #|order number|purchase order|\bp\.?o\.?\b)\b/', $h ) ) { $tags[] = 'order'; }
		if ( preg_match( '/\b(quote|estimate|pricing|proposal)\b/', $h ) ) { $tags[] = 'quote'; }
		if ( preg_match( '/\b(shipped|shipment|tracking|out for delivery|delivered|in transit|carrier)\b/', $h ) ) { $tags[] = 'shipping'; }
		if ( preg_match( '/\b(appointment|install date|installation|schedule|reschedule|calendar|book(ed|ing)?)\b/', $h ) ) { $tags[] = 'scheduling'; }
		if ( preg_match( '/\b(complaint|unhappy|disappointed|refund|wrong|damaged|defective|not satisfied|issue with)\b/', $h ) ) { $tags[] = 'complaint'; }
		if ( preg_match( '/\b(new form submission|new lead|new inquiry|contact request|quote request)\b/', $h ) ) { $tags[] = 'lead'; }
		if ( preg_match( '/\b(unsubscribe|newsletter|% off|sale ends|limited time|shop now)\b/', $h ) ) { $tags[] = 'marketing'; }
		if ( preg_match( '/\b(follow(?:ing)?[ -]up|checking in|circling back|any update|still waiting|just wanted to)\b/', $h ) ) { $tags[] = 'follow-up'; }
		if ( preg_match( '/\b(support|help|ticket|case #|troubleshoot)\b/', $h ) ) { $tags[] = 'support'; }

		// transaction if any commercial fact was extracted
		foreach ( $facts as $f ) {
			if ( in_array( $f['kind'], array( 'money', 'order_ref', 'product' ), true ) ) { $tags[] = 'transaction'; break; }
		}
		// vendor: an external sender in a commercial thread that isn't a marketing blast
		if ( 'external' === $class && in_array( 'transaction', $tags, true ) && ! in_array( 'marketing', $tags, true ) ) {
			$tags[] = 'vendor';
		}
		// conversation: a real human exchange (not automated, has a real counterparty)
		if ( ! $auto && '' !== trim( $from_addr ) && ! in_array( 'marketing', $tags, true ) ) {
			$tags[] = 'conversation';
		}

		return array_values( array_unique( $tags ) );
	}

	// ── pure helpers ────────────────────────────────────────────────

	private static function row( string $kind, string $label, ?float $qty, string $unit, ?float $amount, string $currency, string $ref, string $raw_span, float $confidence ): array {
		return array(
			'kind' => $kind, 'label' => $label, 'qty' => $qty, 'unit' => $unit,
			'amount' => $amount, 'currency' => $currency, 'ref' => $ref,
			'raw_span' => $raw_span, 'confidence' => $confidence,
		);
	}

	public static function canon_unit( string $u ): string {
		$u = strtolower( trim( $u ) );
		$map = array(
			'roll' => 'rolls', 'unit' => 'units', 'yard' => 'yards', 'yd' => 'yards', 'yds' => 'yards',
			'foot' => 'feet', 'ft' => 'feet', 'box' => 'boxes', 'case' => 'cases', 'piece' => 'pieces',
			'pc' => 'pieces', 'pcs' => 'pieces', 'screen' => 'screens', 'door' => 'doors',
			'sheet' => 'sheets', 'bundle' => 'bundles', 'pallet' => 'pallets', 'sqft' => 'sqft', 'sq ft' => 'sqft', 'sq.ft' => 'sqft',
		);
		$u = preg_replace( '/\s+/', ' ', $u );
		if ( isset( $map[ $u ] ) ) { return $map[ $u ]; }
		$sing = rtrim( $u, 's' );
		return isset( $map[ $sing ] ) ? $map[ $sing ] : $u;
	}

	private static function norm_label( string $s ): string {
		$s = strtolower( trim( $s ) );
		$s = preg_replace( '/\b(the|a|an|our|your|my|some|these|those)\b\s*/', '', $s );
		$s = preg_replace( '/[^a-z0-9\- ]/', '', (string) $s );
		$s = trim( preg_replace( '/\s+/', ' ', (string) $s ) );
		return self::clip( $s, 120 );
	}

	/**
	 * Map a product phrase to a canonical FAMILY label so a supplier's many phrasings for
	 * one product aggregate as one. The families are IDENTITY, not Core: a business's own
	 * catalog supplies them via the `zib_product_families` filter as
	 *   [ [ 'pattern' => <regex, no delimiters>, 'label' => <family> ], ... ]
	 * (an Identity Pack fills this from its catalog). Core ships NONE — so an unconfigured
	 * install names no product and just uses the generic fallback: the last few words before
	 * the unit noun, normalised. PURE.
	 */
	public static function canon_product( string $s ): string {
		$t = strtolower( $s );
		$families = apply_filters( 'zib_product_families', array() );
		foreach ( (array) $families as $fam ) {
			$pat = is_array( $fam ) && isset( $fam['pattern'] ) ? (string) $fam['pattern'] : '';
			$lab = is_array( $fam ) && isset( $fam['label'] ) ? (string) $fam['label'] : '';
			// Identity-authored patterns are trusted config; a malformed one is skipped
			// (@preg_match returns false), never fatal.
			if ( '' !== $pat && '' !== $lab && @preg_match( '/' . $pat . '/', $t ) === 1 ) {
				return $lab;
			}
		}
		$t = preg_replace( '/\b(order|summary|items?|shipment|in|the|your|a|an|of|x)\b/', ' ', $t );
		$w = array_values( array_filter( preg_split( '/\s+/', trim( (string) $t ) ) ) );
		return self::norm_label( implode( ' ', array_slice( $w, -4 ) ) );
	}

	private static function norm_color( string $c ): string {
		$c = strtolower( trim( $c ) );
		return in_array( $c, array( 'black', 'white', 'gray', 'grey', 'tan', 'beige', 'bronze', 'charcoal', 'almond', 'clear', 'mill' ), true ) ? $c : '';
	}

	private static function near_color( string $win ): string {
		return preg_match( '/\b(black|white|gray|grey|tan|beige|bronze|charcoal|almond|clear)\b/i', $win, $m ) ? strtolower( $m[1] ) : '';
	}

	/** A dimension near the item — 36" x 50' → 36"×50'. PURE. */
	private static function near_size( string $win ): string {
		if ( preg_match( '/\b([0-9]{1,3})\s*(?:\x{2033}|\x{201D}|"|in|inch(?:es)?)?\s*[\x{00D7}xX]\s*([0-9]{1,3})\s*(?:\x{2032}|\x{2019}|\'|ft|feet|foot|"|\x{2033})?/u', $win, $m ) ) {
			return $m[1] . '"' . "\u{00D7}" . $m[2] . "'";
		}
		return '';
	}

	/** The order # nearest (by offset) to a product span, for de-dup at aggregation time. PURE. */
	private static function nearest_ref( array $order_refs, int $off ): string {
		$best = ''; $bestd = PHP_INT_MAX;
		foreach ( $order_refs as $o ) {
			$d = abs( (int) $o['off'] - $off );
			if ( $d < $bestd ) { $bestd = $d; $best = (string) $o['ref']; }
		}
		return $best;
	}

	/**
	 * Best-effort product hint near a qty span when no explicit "of <product>". The hint
	 * terms are IDENTITY (a business's catalog keywords), supplied via the
	 * `zib_product_hint_terms` filter as a flat list of strings. Core ships NONE, so an
	 * unconfigured install returns '' (no product named). PURE.
	 */
	private static function product_hint( string $hay, int $offset ): string {
		$terms = apply_filters( 'zib_product_hint_terms', array() );
		if ( empty( $terms ) || ! is_array( $terms ) ) {
			return '';
		}
		$parts = array();
		foreach ( $terms as $term ) {
			$term = trim( (string) $term );
			if ( '' !== $term ) {
				$parts[] = preg_quote( $term, '/' );
			}
		}
		if ( empty( $parts ) ) {
			return '';
		}
		$win = strtolower( substr( $hay, max( 0, $offset - 40 ), 120 ) );
		if ( preg_match( '/\b(' . implode( '|', $parts ) . ')\b/', $win, $m ) ) {
			return self::norm_label( $m[1] );
		}
		return '';
	}

	private static function span( string $hay, int $offset ): string {
		return self::clip( trim( substr( $hay, max( 0, $offset - 8 ), 96 ) ), 160 );
	}

	private static function clip( string $s, int $len ): string {
		return ( strlen( $s ) > $len ) ? rtrim( substr( $s, 0, $len ) ) : $s;
	}

	// ════════════════════════════════════════════════════════════════
	//  WP / DB seam (fail-soft)
	// ════════════════════════════════════════════════════════════════

	/**
	 * Enrich ONE message and persist its card / extracts / tags. Fail-soft.
	 *
	 * @param int    $message_id  the wp_zib_messages row.
	 * @param array  $ctx         { owner_user_id, account_id, subject, from_addr, from_name,
	 *                             direction, class, has_attachments, received_at }
	 * @param string $body_content raw body (plaintext for new ingest; decrypted for backfill).
	 * @param string $body_format 'text' | 'html'
	 */
	public static function enrich_message( int $message_id, array $ctx, string $body_content, string $body_format ): void {
		global $wpdb;
		try {
			$owner   = (int) ( $ctx['owner_user_id'] ?? 0 );
			$account = (int) ( $ctx['account_id'] ?? 0 );
			$subject = (string) ( $ctx['subject'] ?? '' );
			if ( $message_id <= 0 || $owner <= 0 ) {
				return;
			}

			$clean = self::clean_body( $body_content, $body_format );
			$gist  = self::first_line_gist( $clean, $subject );
			$facts = self::extract_facts( $clean, $subject );
			$tags  = self::deterministic_tags(
				$clean, $subject,
				(string) ( $ctx['from_addr'] ?? '' ), (string) ( $ctx['from_name'] ?? '' ),
				(string) ( $ctx['direction'] ?? 'in' ), (string) ( $ctx['class'] ?? 'external' ),
				! empty( $ctx['has_attachments'] ), $facts
			);

			// card_enc: the structured card JSON, ENCRYPTED at rest (P6a: gist + fact summary;
			// P6b's synthesizer adds people/companies/commitments prose).
			$card = wp_json_encode( array(
				'v'         => self::ENRICH_VER,
				'gist'      => $gist,
				'products'  => self::cardit( $facts, 'product' ),
				'money'     => self::cardit( $facts, 'money' ),
				'orders'    => self::cardit( $facts, 'order_ref' ),
				'tags'      => $tags,
			) );

			$wpdb->update(
				self::t_msg(),
				array(
					'body_clean_text' => $clean,
					'gist'            => self::clip( $gist, 512 ),
					'card_enc'        => ZIB_Crypto::encrypt( (string) $card ),
					'enrich_ver'      => self::ENRICH_VER,
					'enriched_at'     => gmdate( 'Y-m-d H:i:s' ),
				),
				array( 'id' => $message_id ),
				array( '%s', '%s', '%s', '%d', '%s' ),
				array( '%d' )
			);

			self::persist_extracts( $message_id, $owner, $account, (string) ( $ctx['received_at'] ?? null ), $facts );

			if ( class_exists( 'ZIB_Tags' ) ) {
				ZIB_Tags::assign_seed( $message_id, $owner, $tags, 'deterministic' );
			}
		} catch ( \Throwable $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'ZIB Enrich: message ' . $message_id . ' failed: ' . $e->getMessage() );
			}
		}
	}

	/** Re-enrich already-indexed mail in bounded batches (cron). Returns rows processed. */
	public static function backfill_batch( int $limit = self::BACKFILL_PER_TICK ): int {
		global $wpdb;
		$rows = (array) $wpdb->get_results( $wpdb->prepare(
			'SELECT id, owner_user_id, account_id, subject, from_addr, from_name, direction, class,
			        has_attachments, received_at, body_enc, body_format
			 FROM ' . self::t_msg() . '
			 WHERE enrich_ver < %d AND body_enc IS NOT NULL AND body_enc <> \'\'
			 ORDER BY received_at DESC
			 LIMIT %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			self::ENRICH_VER, max( 1, $limit )
		) );
		$n = 0;
		foreach ( $rows as $r ) {
			$body = ZIB_Crypto::decrypt( (string) $r->body_enc );
			self::enrich_message( (int) $r->id, array(
				'owner_user_id'   => (int) $r->owner_user_id,
				'account_id'      => (int) $r->account_id,
				'subject'         => (string) $r->subject,
				'from_addr'       => (string) $r->from_addr,
				'from_name'       => (string) $r->from_name,
				'direction'       => (string) $r->direction,
				'class'           => (string) $r->class,
				'has_attachments' => (int) $r->has_attachments,
				'received_at'     => (string) $r->received_at,
			), $body, (string) $r->body_format );
			$n++;
		}
		if ( $n > 0 && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( 'ZIB Enrich: backfilled ' . $n . ' message(s) to enrich_ver ' . self::ENRICH_VER );
		}
		return $n;
	}

	// ── persistence helpers ─────────────────────────────────────────

	private static function persist_extracts( int $message_id, int $owner, int $account, ?string $received_at, array $facts ): void {
		global $wpdb;
		// Idempotent re-enrich: clear this message's prior extracts first.
		$wpdb->delete( self::t_ext(), array( 'message_id' => $message_id ), array( '%d' ) );
		foreach ( $facts as $f ) {
			$wpdb->insert( self::t_ext(), array(
				'message_id'    => $message_id,
				'owner_user_id' => $owner,
				'account_id'    => $account,
				'kind'          => (string) $f['kind'],
				'label'         => (string) $f['label'],
				'qty'           => isset( $f['qty'] ) ? $f['qty'] : null,
				'unit'          => (string) $f['unit'],
				'amount'        => isset( $f['amount'] ) ? $f['amount'] : null,
				'currency'      => (string) $f['currency'],
				'ref'           => (string) $f['ref'],
				'raw_span'      => (string) $f['raw_span'],
				'confidence'    => (float) $f['confidence'],
				'source'        => 'deterministic',
				'received_at'   => ( $received_at !== '' ) ? $received_at : null,
			) );
		}
	}

	/** Compact a fact kind into a card-friendly list. */
	private static function cardit( array $facts, string $kind ): array {
		$out = array();
		foreach ( $facts as $f ) {
			if ( $f['kind'] !== $kind ) { continue; }
			if ( 'product' === $kind ) {
				$out[] = array( 'label' => $f['label'], 'qty' => $f['qty'], 'unit' => $f['unit'] );
			} elseif ( 'money' === $kind ) {
				$out[] = array( 'amount' => $f['amount'], 'currency' => $f['currency'] );
			} else {
				$out[] = array( 'ref' => $f['ref'] );
			}
		}
		return $out;
	}
}
