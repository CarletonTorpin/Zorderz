<?php
/**
 * ZIB_Supplier_Feed — supplier order mail → Stock's Incoming list.
 *
 * Each person connects their own mailbox in Email; only mail from senders on Stock's supplier list reaches
 * Stock; an order counts as received only when someone taps Received in Stock. This class only tells Stock
 * that an order exists.
 *
 * WHAT CROSSES THE SEAM, AND WHAT DOES NOT. For a message FROM a listed supplier, Stock is given:
 *   - the mailbox owner's user id, a one-way message key (sha256), the sender's address, the received time,
 *   - the order / PO / invoice numbers, each of a strict SHAPE: digits (at least 4), optionally after one of a few
 *     known prefixes (INV, SO, PO, ORD, WO, ACK); never a phone number or a year -- so a job or customer name, or
 *     the next ordinary word ("order OF 120"), can never ride along as a "number",
 *   - order lines read one text line at a time: a quantity (1-999), a unit, and a description made ONLY of
 *     allow-listed product and colour words, plus a size like 36"x50'. The product words are the business's own
 *     catalog words, supplied by Stock through the `zib_supplier_product_words` filter; the Core list holds only
 *     colour words, so with no catalog a line keeps no label and the order says its lines are in the email.
 * Never the body, never the subject, never the sender's display name, never other mail.
 *
 * WHICH MAIL. Inbound; from a listed sender (never the business's own domains, never a whole free-mail domain --
 * this class enforces that itself and does not trust the list); not in Junk or Deleted Items; received in the last
 * 30 days (so the backfill when a mailbox first connects, a reconnect or an index rebuild does not pour a year of
 * long-delivered orders into Incoming); not internal mail.
 *
 * TWO DOORS, BOTH OWNER-SCOPED (the mailbox owner, never another person), BOTH AUDITED:
 *   1. At ingest (store_message) -- new mail, for its own owner (path supplier_feed_ingest).
 *   2. "Look in my email" in Stock -- ZIB_Gatekeeper::owner_supplier_scan(): the signed-in person's own indexed
 *      mail, last N days (max 60) (path owner_supplier_scan).
 * Stock decides what to keep (an order number is required) and de-duplicates.
 *
 * Off unless Stock is present: the list comes only from the `zib_supplier_senders` filter (Stock adds it), and
 * nothing is sent unless something answers the `zib_supplier_message` filter. An empty list sends nothing.
 * Mail from suppliers is EXTERNAL: a mailbox indexed "internal" never sees it (the scan says so).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZIB_Supplier_Feed {

	const MAX_SENDERS = 200;
	const MAX_LINES   = 30;
	const MAX_QTY     = 999;
	const MAX_REFS    = 5;
	const SCAN_DAYS   = 30;
	const SCAN_MAX    = 200;
	const INGEST_DAYS = 30;

	/** Colour words a line's description may keep (whole words). Product words come from the catalog. */
	const COLOUR_WORDS = array( 'black', 'white', 'gray', 'grey', 'tan', 'beige', 'brown', 'bronze', 'charcoal', 'clear', 'silver' );
	/** Unit nouns that are never a label on their own (the unit says it). Catalog unit nouns are added by filter. */
	const UNIT_WORDS = array( 'roll', 'rolls', 'kit', 'kits', 'box', 'boxes', 'case', 'cases', 'piece', 'pieces', 'unit', 'units' );
	/** Measures that follow a quantity, never an order number. */
	const MEASURES   = 'square\s+f(?:ee|oo)t|sq\.?\s*f(?:ee|oo)t|sq\.?\s*ft|sf|lf|ln\.?\s*ft|lbs?|pounds?|gal|gallons?|kg|meters?|inches';
	/** Core unit words a quantity may be followed by; a business's own unit nouns are added (unit_pattern()). */
	const UNITS      = 'rolls?|units?|yards?|yds?|feet|foot|ft|boxes|box|cases?|pieces?|pcs?|ea|each|sheets?|bundles?|pallets?|kits?|bags?|packs?|pairs?|sets?';

	/** The only letters an order number may start with (so "OF120" or "SAMPLE-4410" is never a number). */
	const REF_PREFIXES = 'INV|SO|PO|ORD|WO|ACK';
	/** Folders that are never a source, by Graph wellKnownName (display names are localised). */
	const SKIP_WELLKNOWN = array( 'junkemail', 'deleteditems', 'recoverableitemsdeletions', 'recoverableitemsroot', 'recoverableitemspurges', 'conflicts', 'outbox' );
	/** Free-mail families on any country domain, as the registrable name only ("yahoo.co.uk", not "mail.supplier.com"). */
	const FREE_FAMILY = array( 'yahoo', 'ymail', 'rocketmail', 'hotmail', 'outlook', 'live', 'msn', 'aol', 'gmx', 'web', 'mail', 'yandex', 'proton', 'protonmail', 'zoho', 'icloud' );

	/** Whole domains that are never a supplier (exact addresses at them are fine); their subdomains neither. */
	const FREE_MAIL = array( 'rr.com', 'pacbell.net', 'frontier.com', 'frontiernet.net', 'fastmail.com', 'fastmail.fm', 'hey.com', 'duck.com', 'btinternet.com', 'sky.com', 'optonline.net', 'windstream.net', 'centurylink.net', 'q.com', 'bellsouth.net', 'roadrunner.com', 'twc.com', 'spectrum.net', 'suddenlink.net', 'mediacombb.net', 'juno.com', 'netzero.net', 'tutanota.com', 'tuta.io', 'pm.me', 'hushmail.com', 'mailinator.com', 'gmail.com', 'googlemail.com', 'yahoo.com', 'outlook.com', 'hotmail.com', 'live.com', 'msn.com', 'icloud.com', 'me.com', 'mac.com', 'aol.com', 'proton.me', 'protonmail.com', 'comcast.net', 'att.net', 'sbcglobal.net', 'cox.net', 'verizon.net', 'charter.net', 'earthlink.net', 'ymail.com', 'gmx.com', 'mail.com', 'zoho.com' );

	public static function boot(): void {
		if ( class_exists( 'ZIB_Settings' ) && ! ZIB_Settings::feature_enabled() ) { return; }
		add_filter( 'zib_supplier_scan', array( __CLASS__, 'scan_filter' ), 10, 3 );
		// So Stock can refuse the business's own domains when an admin types them (this class refuses them anyway).
		add_filter( 'zib_internal_domains', array( __CLASS__, 'internal_domains_filter' ) );
	}

	public static function internal_domains_filter( $prev ) {
		return class_exists( 'ZIB_Settings' ) ? ZIB_Settings::internal_domains() : (array) $prev;
	}

	/* ------------------------------------------------------------------ PURE: the list */

	/** Is this domain (or a parent of it) one of $domains? PURE. */
	public static function in_domains( string $domain, array $domains ): bool {
		$domain = strtolower( trim( $domain ) );
		foreach ( $domains as $d ) {
			$d = strtolower( trim( (string) $d ) );
			if ( '' !== $d && ( $domain === $d || substr( $domain, -strlen( $d ) - 1 ) === '.' . $d ) ) {
				return true;
			}
		}
		return false;
	}

	/** Second labels that are part of a country-code suffix ("co.uk", "com.au"); a real list, not a length test. */
	const CC_SLD = array( 'co', 'com', 'net', 'org', 'gov', 'edu', 'ac', 'ltd', 'plc', 'ne', 'or', 'go', 'mil', 'nhs', 'sch', 'gob', 'gv', 'nom', 'biz', 'info', 'k12' );

	/** How many trailing labels are the public suffix: "com" 1, "co.uk" / "ca.us" 2, "k12.ca.us" 3. PURE. */
	public static function suffix_len( array $labels ): int {
		$n   = count( $labels );
		$tld = $labels[ $n - 1 ];
		$sld = $n >= 2 ? $labels[ $n - 2 ] : '';
		$len = 1;
		if ( 2 === strlen( $tld ) && $n >= 2 && ( in_array( $sld, self::CC_SLD, true ) || ( 'us' === $tld && 2 === strlen( $sld ) ) ) ) {
			$len = 2; // "co.uk", "ca.us" (a US state)
		}
		if ( 'us' === $tld && 2 === $len && $n >= 3 && in_array( $labels[ $n - 3 ], array( 'k12', 'lib', 'cc', 'tec', 'gen', 'state', 'ci', 'co', 'town', 'cog', 'mus', 'dst' ), true ) ) {
			$len = 3; // "k12.ca.us"
		}
		return $len;
	}

	/**
	 * A domain too wide to stand for one supplier: a public-suffix shape ("co.uk", "ca.us", "k12.ca.us"), a free-mail
	 * provider or ISP (any of its subdomains too: "san.rr.com"), or a free-mail family on any country domain. PURE.
	 */
	public static function too_wide( string $domain ): bool {
		$labels = explode( '.', $domain );
		$sfx    = self::suffix_len( $labels );
		if ( count( $labels ) <= $sfx ) {
			return true;
		}
		foreach ( self::FREE_MAIL as $f ) {
			if ( $domain === $f || substr( $domain, -strlen( $f ) - 1 ) === '.' . $f ) {
				return true;
			}
		}
		// "yahoo.co.uk", "outlook.fr" (and their subdomains) -- the family name must be the registrable label.
		return in_array( $labels[ count( $labels ) - $sfx - 1 ], self::FREE_FAMILY, true );
	}

	/**
	 * The supplier list from Stock, cleaned: lowercase exact addresses ("orders@example-supplier.test") or whole domains ("@example-supplier.test";
	 * "example-supplier.test" is read as "@example-supplier.test"). Refused: anything at the business's own domains, a whole
	 * free-mail domain or a public suffix -- such an entry would pour colleagues' or personal mail in. PURE.
	 *
	 * @return string[]
	 */
	public static function normalize_senders( $list, array $internal = array() ): array {
		$out   = array();
		$label = '[a-z0-9]([a-z0-9\-]*[a-z0-9])?';
		foreach ( (array) $list as $s ) {
			$s = strtolower( trim( (string) $s ) );
			if ( '' === $s ) {
				continue;
			}
			if ( false === strpos( $s, '@' ) ) {
				$s = '@' . $s;
			}
			$is_domain = (bool) preg_match( '/^@' . $label . '(\.' . $label . ')+$/', $s );
			$is_addr   = ! $is_domain && (bool) preg_match( '/^[a-z0-9._%+\-]+@' . $label . '(\.' . $label . ')+$/', $s );
			if ( ! $is_domain && ! $is_addr ) {
				continue;
			}
			$domain = substr( $s, strpos( $s, '@' ) + 1 );
			if ( self::in_domains( $domain, $internal ) ) {
				continue; // never the company's own mail
			}
			if ( $is_domain && self::too_wide( $domain ) ) {
				continue; // never everyone at gmail.com
			}
			$out[ $s ] = true;
			if ( count( $out ) >= self::MAX_SENDERS ) {
				break;
			}
		}
		return array_keys( $out );
	}

	/** Is this sender on the list? An exact address, or a listed domain (and its subdomains). PURE. */
	public static function sender_matches( string $addr, array $senders ): bool {
		$addr = strtolower( trim( $addr ) );
		if ( '' === $addr || false === strpos( $addr, '@' ) ) {
			return false;
		}
		$domain = substr( $addr, strpos( $addr, '@' ) + 1 );
		foreach ( $senders as $s ) {
			if ( '@' === $s[0] ) {
				if ( self::in_domains( $domain, array( substr( $s, 1 ) ) ) ) {
					return true;
				}
			} elseif ( $addr === $s ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Junk, Deleted Items: never a source (a spoofed "supplier" lands there). By the folder's wellKnownName when there
	 * is one (a localised "Courrier indésirable" has no English name to match; a user folder
	 * called "Bulk Orders" is not junk); else by the coarse label; else by an English name. PURE.
	 */
	public static function skip_folder( string $folder, string $folder_name, string $well_known = '' ): bool {
		$wk = strtolower( trim( $well_known ) );
		if ( '' !== $wk ) {
			return in_array( $wk, self::SKIP_WELLKNOWN, true );
		}
		if ( in_array( strtolower( trim( $folder ) ), self::SKIP_WELLKNOWN, true ) ) {
			return true;
		}
		return (bool) preg_match( '/^(junk|junk e-?mail|spam|deleted items|trash|quarantine)$/', strtolower( trim( $folder_name ) ) );
	}

	/* ------------------------------------------------------------------ PURE: the reader */

	/**
	 * Order / PO / sales-order / invoice numbers of a strict shape. Kind 'order' (order, PO, SO, confirmation) ranks
	 * above 'invoice'. Each has the number as written ("SO-10422") and its digits ("10422"), which is what Stock
	 * matches on, so "SO-10422", "10422" and "Order #10422" are one order. Read line by line, so a subject ending in
	 * "order" cannot swallow the body's number. PURE.
	 *
	 * @return array<int,array{kind:string,ref:string,core:string}>
	 */
	public static function refs( string $text ): array {
		$out  = array();
		$seen = array();
		// Words are case-blind; the short keywords only in capitals ("So 100 rolls are left" is not a sales order).
		$kw   = '(purchase\s+order|sales\s+order|order|confirmation|invoice|(?-i:PO|P\.O\.|SO|S\.O\.|INV\.?|Inv\.?))';
		$tok  = '((?:(?:' . self::REF_PREFIXES . ')[\-#]?\s?)?[0-9][0-9\-]{3,18})';
		$pats = array(
			// Never a number followed by a unit ("Order 1000 rolls", "2500 sq ft": a quantity).
			'/\b' . $kw . '\s*(?:number|num|no\.?|#)?\s*[:#]?\s*' . $tok . '\b(?!\s*(?:' . self::unit_pattern() . '|' . self::MEASURES . ')\b)/i',
			'/\b(' . self::REF_PREFIXES . ')[\-#]\s?([0-9][0-9\-]{3,16})\b/', // "INV-7781", "SO#10422" standing alone
		);
		foreach ( preg_split( '/\n+/', $text ) as $line ) {
			foreach ( $pats as $i => $re ) {
				if ( ! preg_match_all( $re, $line, $mm, PREG_SET_ORDER ) ) {
					continue;
				}
				foreach ( $mm as $m ) {
					$word = strtolower( preg_replace( '/[^a-z]/i', '', $m[1] ) );
					$raw  = 0 === $i ? $m[2] : ( $m[1] . '-' . $m[2] );
					$ref  = strtoupper( preg_replace( '/\s+/', '', str_replace( '#', '-', $raw ) ) );
					$ref  = trim( preg_replace( '/-{2,}/', '-', $ref ), '-' );
					if ( ! self::ref_shape_ok( $ref ) ) {
						continue;
					}
					$core = self::core_of( $ref );
					$kind = in_array( $word, array( 'invoice', 'inv' ), true ) ? 'invoice' : 'order';
					if ( isset( $seen[ $core ] ) ) {
						continue;
					}
					$seen[ $core ] = true;
					$out[]         = array( 'kind' => $kind, 'ref' => $ref, 'core' => $core );
				}
			}
		}
		usort( $out, function ( $a, $b ) {
			return ( 'order' === $a['kind'] ? 0 : 1 ) <=> ( 'order' === $b['kind'] ? 0 : 1 );
		} );
		return array_slice( $out, 0, self::MAX_REFS );
	}

	/**
	 * The digits an order is matched on: one run -> that run ("INV00012345" -> 12345); a long number
	 * (5+ digits) with only short suffixes -> the number ("10422-01" -> 10422, a split shipment); anything else -> all
	 * the digits ("1001-55" and "1001-56", an account number first, stay two orders; "SO-2026-10422" -> 202610422 and
	 * "PO 2026-1234" -> 20261234, so a supplier whose numbers restart each year never collides with last year's).
	 * Leading zeros dropped. When unsure, two orders rather than one: a spare order is seen, a swallowed one is not.
	 * PURE.
	 */
	public static function core_of( string $ref ): string {
		preg_match_all( '/[0-9]+/', $ref, $m );
		$all  = $m[0];
		$runs = $all;
		$core = '';
		if ( 1 === count( $runs ) ) {
			$core = ltrim( $runs[0], '0' );
		} elseif ( $runs && strlen( ltrim( $runs[0], '0' ) ) >= 5 && max( array_map( 'strlen', array_slice( $runs, 1 ) ) ) <= 2 ) {
			$core = ltrim( $runs[0], '0' );
		} else {
			$core = ltrim( implode( '', $runs ), '0' );
		}
		return strlen( $core ) >= 4 ? $core : ltrim( implode( '', $all ), '0' );
	}

	/**
	 * Is this the shape of an order number? At most 20 characters, at most two
	 * hyphens, digits (at least 4) after nothing or one known prefix (INV, SO, PO, ORD, WO, ACK); never a phone number, a bare year
	 * or a "3-5" (business days). PURE.
	 */
	public static function ref_shape_ok( string $ref ): bool {
		if ( strlen( $ref ) > 20 || substr_count( $ref, '-' ) > 2 ) {
			return false;
		}
		if ( ! preg_match( '/^(?:(?:' . self::REF_PREFIXES . ')-?)?[0-9][0-9\-]*$/', $ref ) ) {
			return false; // "31-SAMPLEJOB", "SAMPLE-4410", "OF120" are not numbers
		}
		if ( strlen( preg_replace( '/[^0-9]/', '', $ref ) ) < 4 ) {
			return false; // "order 120 rolls": a quantity, not an order
		}
		$bare = preg_replace( '/^[A-Z]+-?/', '', $ref );
		if ( preg_match( '/^[0-9]{3}-?[0-9]{3}-?[0-9]{4}$|^[0-9]{3}-[0-9]{4}$|^[0-9]{2,3}-[0-9]{2,3}-[0-9]{4}$/', $bare ) ) {
			return false; // a phone number (10 or 7 digits), an SSN-like shape
		}
		if ( preg_match( '/^(19|20)[0-9]{2}$/', $bare ) || preg_match( '/^([0-9]{4}-[0-9]{1,2}-[0-9]{1,2}|[0-9]{1,2}-[0-9]{1,2}-[0-9]{2,4})$/', $bare ) ) {
			return false; // a year, a date
		}
		if ( preg_match( '/^[0-9]{1,2}-[0-9]{1,2}$/', $bare ) ) {
			return false; // "3-5 business days"
		}
		return true;
	}

	/**
	 * The words a line's label may keep: Core colour words plus the business's own product words (Stock adds its
	 * catalog's item-name and alias words through `zib_supplier_product_words`). Lowercase letters only, 2+ long.
	 *
	 * @return string[]
	 */
	public static function product_words(): array {
		$extra = function_exists( 'apply_filters' ) ? apply_filters( 'zib_supplier_product_words', array() ) : array();
		return self::clean_words( array_merge( self::COLOUR_WORDS, (array) $extra ) );
	}

	/** Unit nouns that are never a label on their own: Core's plus the business's (`zib_supplier_unit_words`). */
	public static function unit_words(): array {
		$extra = function_exists( 'apply_filters' ) ? apply_filters( 'zib_supplier_unit_words', array() ) : array();
		return self::clean_words( array_merge( self::UNIT_WORDS, (array) $extra ) );
	}

	/** The unit alternation used by the line and number readers: Core's units plus the business's unit nouns. */
	public static function unit_pattern( ?array $units = null ): string {
		$extra = array();
		foreach ( null === $units ? self::unit_words() : self::clean_words( $units ) as $w ) {
			$extra[] = preg_quote( $w, '/' );
		}
		return self::UNITS . ( $extra ? '|' . implode( '|', $extra ) : '' );
	}

	/** Lowercase whole words of letters, 2+ long, each once. PURE. */
	public static function clean_words( array $words ): array {
		$out = array();
		foreach ( $words as $w ) {
			$w = strtolower( trim( (string) $w ) );
			if ( preg_match( '/^[a-z]{2,40}$/', $w ) ) {
				$out[ $w ] = true;
			}
		}
		return array_keys( $out );
	}

	/**
	 * Keep only allow-listed product and colour words of a phrase (split on anything not a letter: a hyphenated
	 * "samplejob-cloth" is split, and only an allow-listed word stays). Bare numbers never stay (a quantity must not
	 * leak into a label). A phrase that is then only a unit noun is dropped (the unit says it). PURE given the lists.
	 */
	public static function product_words_only( string $phrase, ?array $allow = null, ?array $units = null ): string {
		$allow = null === $allow ? self::product_words() : $allow;
		$units = null === $units ? self::unit_words() : $units;
		$keep  = array();
		foreach ( preg_split( '/[^a-z]+/', strtolower( $phrase ) ) as $w ) {
			if ( '' !== $w && in_array( $w, $allow, true ) && ! in_array( $w, $keep, true ) ) {
				$keep[] = $w;
			}
		}
		if ( ! $keep || ( 1 === count( $keep ) && in_array( $keep[0], $units, true ) ) ) {
			return '';
		}
		return implode( ' ', $keep );
	}

	/** A size written like 36" x 50' or 36x50, normalised to 36"x50'. PURE. */
	public static function size_of( string $s ): string {
		if ( preg_match( '/\b([0-9]{1,3})\s*(?:"|\x{2033}|\x{201D}|in(?:ch(?:es)?)?)?\s*[x\x{00D7}]\s*([0-9]{1,3})\s*(?:\'|\x{2032}|\x{2019}|ft|feet|foot)?(?=[^0-9]|$)/iu', $s, $m ) ) {
			return $m[1] . '"x' . $m[2] . "'";
		}
		return '';
	}

	/**
	 * Order lines, one text line at a time (a look-back that crosses lines would label one line with the words of
	 * the line above). A line is read when it is one of:
	 *   "<description> x 3" / "<description> × 3" (the quantity last),
	 *   "3 x <description>" (the quantity first),
	 *   "3 rolls of <description>" / "3 rolls <description>".
	 * Quantities 1-999; a size is kept; the description keeps only allow-listed words, else becomes its unit, else
	 * the line is not read. Anything else (tables of columns, prices) is not guessed at: the order then says its
	 * lines are in the email, and the person adds them on Received. PURE.
	 *
	 * @return array<int,array{description:string,qty:float,unit:string}>
	 */
	public static function lines( string $text, ?array $allow = null, ?array $units = null ): array {
		$out   = array();
		$allow = null === $allow ? self::product_words() : self::clean_words( $allow );
		$units = null === $units ? self::unit_words() : self::clean_words( $units );
		$u     = self::unit_pattern( $units );
		foreach ( preg_split( '/\n+/', $text ) as $line ) {
			$line = trim( preg_replace( '/[ \t]+/', ' ', $line ) );
			if ( '' === $line || strlen( $line ) > 200 ) {
				continue;
			}
			$qty = 0; $desc = ''; $unit = '';
			if ( preg_match( '/^(.{3,120}?)\s+[x\x{00D7}]\s*([0-9]{1,3})\s*$/iu', $line, $m ) ) {
				$desc = $m[1]; $qty = (int) $m[2];
			} elseif ( preg_match( '/^([0-9]{1,3})\s*[x\x{00D7}]\s+(.{3,120})$/iu', $line, $m ) ) {
				$qty = (int) $m[1]; $desc = $m[2];
			} elseif ( preg_match( '/(?:^|\s)([0-9]{1,3})\s+(' . $u . ')\b(?:\s+of)?\s+(.{3,120})$/i', $line, $m ) ) {
				$qty = (int) $m[1]; $unit = strtolower( $m[2] ); $desc = $m[3];
			} else {
				continue;
			}
			if ( $qty < 1 || $qty > self::MAX_QTY ) {
				continue;
			}
			$size  = self::size_of( $desc );
			$words = self::product_words_only( preg_replace( '/[0-9]+/', ' ', $desc ), $allow, $units );
			if ( '' === $unit && preg_match_all( '/\b(' . $u . ')\b/i', $desc, $um ) ) {
				$unit = strtolower( end( $um[1] ) ); // the last one: "Fine Cloth Roll x 2" is in rolls
			}
			if ( '' === $words ) {
				$words = $unit; // "3 rolls of <nothing we can name>" -> "rolls"
			}
			if ( '' === $words && '' === $size ) {
				continue; // nothing we can name: not a line we read
			}
			$out[] = array( 'description' => trim( $words . ( '' !== $size ? ' ' . $size : '' ) ), 'qty' => (float) $qty, 'unit' => $unit );
			if ( count( $out ) >= self::MAX_LINES ) {
				break;
			}
		}
		return $out;
	}

	/** What Stock may know of one message: its numbers and its lines. PURE. */
	public static function order_facts( string $clean, string $subject ): array {
		$refs = self::refs( $subject . "\n" . $clean );
		return array( 'refs' => $refs, 'ref' => $refs ? $refs[0]['ref'] : '', 'lines' => self::lines( $clean ) );
	}

	/**
	 * A one-way key for one message. The RFC Message-ID when there is one (the same for every copy: two folders,
	 * two mailboxes), else this mailbox's id. PURE.
	 */
	public static function message_key( int $account_id, string $ms_message_hash, string $internet_message_id = '' ): string {
		$mid = strtolower( trim( $internet_message_id ) );
		return '' !== $mid ? hash( 'sha256', 'zib-supplier|mid|' . $mid ) : hash( 'sha256', 'zib-supplier|' . $account_id . '|' . $ms_message_hash );
	}

	/** Received within the last $days (stored times are UTC)? Unknown times are not. */
	public static function recent( string $received_at, int $days ): bool {
		$ts = '' === trim( $received_at ) ? false : strtotime( $received_at . ' UTC' );
		return $ts && $ts >= time() - $days * 86400 && $ts <= time() + 86400;
	}

	/* ------------------------------------------------------------------ WP seam */

	/** The supplier list Stock gives us, minus anything at the company's own domains or too wide. */
	public static function senders(): array {
		$internal = class_exists( 'ZIB_Settings' ) ? ZIB_Settings::internal_domains() : array();
		$out      = array();
		foreach ( self::normalize_senders( apply_filters( 'zib_supplier_senders', array() ), $internal ) as $s ) {
			// An exact address that is a colleague's (a registered person's personal free-mail address, say)
			// is not a supplier either.
			if ( '@' !== $s[0] && is_callable( array( 'ZIB_Classifier', 'wp_is_registered_internal' ) ) && ZIB_Classifier::wp_is_registered_internal( $s ) ) {
				continue;
			}
			$out[] = $s;
		}
		return $out;
	}

	/** The folder's wellKnownName, from the folder table ('' when unknown). */
	public static function well_known( int $account_id, string $folder_hash ): string {
		global $wpdb;
		if ( '' === $folder_hash || ! isset( $wpdb ) ) {
			return '';
		}
		return (string) $wpdb->get_var( $wpdb->prepare(
			'SELECT well_known FROM ' . $wpdb->prefix . 'zib_folders WHERE account_id = %d AND folder_hash = %s', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$account_id,
			$folder_hash
		) );
	}

	/** Anyone listening? (Stock adds the filter.) */
	public static function listening(): bool {
		return function_exists( 'has_filter' ) && (bool) has_filter( 'zib_supplier_message' );
	}

	/**
	 * Door 1 -- at ingest, for one stored message. Fail-soft: a supplier message that cannot be read for Stock never
	 * breaks indexing.
	 */
	public static function on_stored( int $owner, int $account_id, array $norm, string $body_content, string $body_format, string $class = '' ): void {
		try {
			if ( 'in' !== ( $norm['direction'] ?? '' ) || $owner <= 0 || ! self::listening() ) {
				return;
			}
			if ( 'internal' === $class || self::skip_folder( (string) ( $norm['folder'] ?? '' ), (string) ( $norm['folder_name'] ?? '' ) ) ) {
				return;
			}
			if ( ! self::recent( (string) ( $norm['received_at'] ?? '' ), self::INGEST_DAYS ) ) {
				return; // the backfill of a newly connected mailbox, a rebuild: old orders stay out of Incoming
			}
			$senders = self::senders();
			if ( ! $senders || ! self::sender_matches( (string) ( $norm['from_addr'] ?? '' ), $senders ) ) {
				return;
			}
			if ( self::skip_folder( (string) ( $norm['folder'] ?? '' ), (string) ( $norm['folder_name'] ?? '' ), self::well_known( $account_id, (string) ( $norm['folder_hash'] ?? '' ) ) ) ) {
				return; // by wellKnownName (one query, only for a supplier's mail)
			}
			$clean = class_exists( 'ZIB_Enrich' ) ? ZIB_Enrich::clean_body( $body_content, $body_format ) : '';
			$key   = self::message_key( $account_id, md5( (string) ( $norm['ms_message_id'] ?? '' ) ), (string) ( $norm['ms_internet_message_id'] ?? '' ) );
			$res   = self::emit( $owner, $key, $norm, $clean, 'ingest' );
			if ( class_exists( 'ZIB_Gatekeeper' ) ) {
				ZIB_Gatekeeper::audit_supplier_feed( $owner, is_array( $res ) ? (string) ( $res['result'] ?? '' ) : 'none' );
			}
		} catch ( \Throwable $e ) {
			error_log( 'ZIB supplier feed: ' . $e->getMessage() );
		}
	}

	/** Build the payload (no body, no subject, no display name) and hand it to Stock. Returns Stock's answer. */
	public static function emit( int $owner, string $key, array $m, string $clean, string $via ) {
		$facts   = self::order_facts( $clean, (string) ( $m['subject'] ?? '' ) );
		$payload = array(
			'owner_user_id' => $owner,
			'message_key'   => $key,
			'from_addr'     => strtolower( trim( (string) ( $m['from_addr'] ?? '' ) ) ),
			'received_at'   => (string) ( $m['received_at'] ?? '' ),
			'order_ref'     => $facts['ref'],
			'refs'          => $facts['refs'],
			'lines'         => $facts['lines'],
			'via'           => $via,
		);
		// One filter, so Stock both takes the order and says what it did (a scan counts the answers).
		return apply_filters( 'zib_supplier_message', null, $payload );
	}

	/** Door 2 -- Stock's "Look in my email": apply_filters( 'zib_supplier_scan', null, $uid, $days ). */
	public static function scan_filter( $prev, $uid = 0, $days = self::SCAN_DAYS ) {
		$actor = (int) get_current_user_id();
		if ( $actor <= 0 || (int) $uid !== $actor ) {
			return array( 'ok' => false, 'reason' => 'not-you' ); // only your own mailbox, only for yourself
		}
		return ZIB_Gatekeeper::owner_supplier_scan( $actor, self::senders(), (int) $days );
	}
}
