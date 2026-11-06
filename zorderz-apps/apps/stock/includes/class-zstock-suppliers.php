<?php
/**
 * ZSTOCK_Suppliers — the supplier list, and supplier order mail → Incoming.
 *
 * Mail never moves stock: it can only put an order under Incoming. Only mail from listed suppliers reaches Stock,
 * and an order counts as received only when someone taps Received.
 *
 * THE LIST. Option `zstock_suppliers`: [ { name, match } ], `match` an exact address ("orders@example.com") or a whole
 * domain ("@example.com", which also covers its subdomains). Only an admin edits it. The Email app reads the matches
 * through the `zib_supplier_senders` filter, so an empty list means nothing is read.
 *
 * Never the business's own domains, never a whole free-mail domain or a public suffix (an exact address there is
 * fine): either would pour colleagues' or personal mail in. Every change is audited (added, removed).
 *
 * WHAT ARRIVES (from the Email app, ZIB_Supplier_Feed): the mailbox owner's id, a one-way message key, the sender, the
 * received time, the order/invoice numbers (each with its digits, its "core") and the lines (quantity, unit, a label
 * made only of product words). Never the body, the subject or the sender's name. The product words are this
 * business's own catalog words, which this class hands the Email app (`zib_supplier_product_words`).
 *
 * WHAT BECOMES AN ORDER:
 *   - It must carry an order number: marketing, a quote or a delivery notice with lines but no number is not an
 *     order. Three or more invoice numbers is a statement, not an order.
 *   - ONE ORDER, HOWEVER MANY EMAILS: the confirmation says SO-10422, the invoice INV-7781 "for order 10422", the
 *     shipping notice "Order #10422". Orders are matched on the supplier-list entry plus the DIGITS of any number,
 *     and an open order learns each new number it is seen with, so the invoice alone later still finds it.
 *   - NOT OURS STICKS: an order marked Not ours is matched like any other, so the next email about it does not bring
 *     it back.
 *   - At most DAY_CAP new orders a day from one supplier (filter `zstock_supplier_day_cap`): a flood, or a spoofed
 *     sender, cannot bury Incoming.
 *   - A line's stock item is pre-picked only on a clear match: every word of the line in one item's name, its colour
 *     included, and no other item fitting as well. Otherwise the person picks on Received.
 *   - When the lines could not be read (a table, a PDF), the order says "Lines are in the email"; on Received the
 *     person adds what came (ZSTOCK_Orders::receive $extra).
 *
 * @package Zorderz\Stock
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZSTOCK_Suppliers {

	const OPTION    = 'zstock_suppliers';
	const MAX       = 100;
	const DAY_CAP   = 20;
	const LINE_QTY  = 999;
	/** Filler and unit words that never decide which item a line is. The catalog's own unit nouns are added. */
	const UNIT_WORD = array( 'roll', 'box', 'case', 'sheet', 'bag', 'pack', 'pair', 'set', 'kit', 'unit', 'piece', 'pc', 'ea', 'each', 'item', 'of', 'the', 'and', 'for', 'with', 'in', 'yard', 'yd', 'ft', 'foot', 'feet' );
	/** Colour words: an item of a colour the line does not say is not that line. Filter: `zstock_colour_words`. */
	const COLOURS   = array( 'black', 'white', 'gray', 'grey', 'tan', 'beige', 'brown', 'bronze', 'charcoal', 'clear', 'silver' );
	/** Free-mail families on any country domain, as the registrable name only ("yahoo.co.uk", not "mail.supplier.com"). */
	const FREE_FAMILY = array( 'yahoo', 'ymail', 'rocketmail', 'hotmail', 'outlook', 'live', 'msn', 'aol', 'gmx', 'web', 'mail', 'yandex', 'proton', 'protonmail', 'zoho', 'icloud' );
	const REF_PREFIXES = 'INV|SO|PO|ORD|WO|ACK';
	/** Domains many businesses send from (shops, invoicing, bulk mail): two suppliers there are two suppliers. */
	const SHARED_HOSTS = array( 'myshopify.com', 'shopifyemail.com', 'shopify.com', 'squarespace.com', 'squarespace-mail.com', 'wix.com', 'wixsite.com', 'bigcommerce.com', 'etsy.com', 'ebay.com', 'amazon.com', 'square.site', 'squareup.com', 'intuit.com', 'quickbooks.com', 'freshbooks.com', 'xero.com', 'bill.com', 'paypal.com', 'stripe.com', 'zoho.com', 'sendgrid.net', 'amazonses.com', 'mailchimp.com', 'mcsv.net', 'mailgun.org', 'constantcontact.com', 'hubspot.com', 'salesforce.com', 'netsuite.com', 'sage.com', 'invoicely.com', 'waveapps.com', 'faire.com' );
	const FREE_MAIL = array( 'rr.com', 'pacbell.net', 'frontier.com', 'frontiernet.net', 'fastmail.com', 'fastmail.fm', 'hey.com', 'duck.com', 'btinternet.com', 'sky.com', 'optonline.net', 'windstream.net', 'centurylink.net', 'q.com', 'bellsouth.net', 'roadrunner.com', 'twc.com', 'spectrum.net', 'suddenlink.net', 'mediacombb.net', 'juno.com', 'netzero.net', 'tutanota.com', 'tuta.io', 'pm.me', 'hushmail.com', 'mailinator.com', 'gmail.com', 'googlemail.com', 'yahoo.com', 'outlook.com', 'hotmail.com', 'live.com', 'msn.com', 'icloud.com', 'me.com', 'mac.com', 'aol.com', 'proton.me', 'protonmail.com', 'comcast.net', 'att.net', 'sbcglobal.net', 'cox.net', 'verizon.net', 'charter.net', 'earthlink.net', 'ymail.com', 'gmx.com', 'mail.com', 'zoho.com' );

	public static function register(): void {
		add_filter( 'zib_supplier_senders', array( __CLASS__, 'senders_filter' ) );
		add_filter( 'zib_supplier_message', array( __CLASS__, 'message_filter' ), 10, 2 );
		add_filter( 'zib_supplier_product_words', array( __CLASS__, 'product_words_filter' ) );
		add_filter( 'zib_supplier_unit_words', array( __CLASS__, 'unit_words_filter' ) );
	}

	/* ------------------------------------------------------------------ catalog words */

	/**
	 * The words of every stock item's name and aliases, singular and plural, for the Email app's allow-list: only
	 * these and colour words may label a line read from supplier mail.
	 */
	public static function product_words_filter( $prev ) {
		$out = is_array( $prev ) ? $prev : array();
		foreach ( self::catalog_names() as $name ) {
			foreach ( preg_split( '/[^a-z]+/', strtolower( $name ) ) as $w ) {
				if ( strlen( $w ) >= 2 ) {
					$out[] = $w;
					$out[] = self::singular( $w );
				}
			}
		}
		return array_values( array_unique( $out ) );
	}

	/** The catalog's unit nouns (each, roll, sheet …), so a line "3 sheets of …" reads its unit. */
	public static function unit_words_filter( $prev ) {
		$out = is_array( $prev ) ? $prev : array();
		return array_values( array_unique( array_merge( $out, self::catalog_units() ) ) );
	}

	/** Names and aliases of the stock items (Item Engine display names; aliases where the engine has them). */
	private static function catalog_names(): array {
		$names = array();
		if ( class_exists( 'ZSTOCK_Engine' ) ) {
			foreach ( ZSTOCK_Engine::items() as $it ) {
				$names[] = (string) $it['name'];
				$item    = class_exists( 'ZSTOCK_Catalog' ) ? ZSTOCK_Catalog::get_item( (string) $it['id'] ) : null;
				foreach ( (array) ( $item['aliases'] ?? array() ) as $a ) {
					$names[] = (string) $a;
				}
			}
		}
		return $names;
	}

	/** Unit nouns of the stock items, singular and plural. */
	private static function catalog_units(): array {
		$out = array();
		if ( class_exists( 'ZSTOCK_Catalog' ) && class_exists( 'ZSTOCK_Engine' ) ) {
			foreach ( ZSTOCK_Engine::items() as $it ) {
				$item = ZSTOCK_Catalog::get_item( (string) $it['id'] );
				foreach ( array( $item['unit_noun_singular'] ?? '', $item['unit_noun_plural'] ?? '', $it['unit'] ?? '' ) as $u ) {
					$u = strtolower( trim( (string) $u ) );
					if ( preg_match( '/^[a-z]{2,30}$/', $u ) ) {
						$out[] = $u;
					}
				}
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Single-word aliases and what they stand for: an item alias "abc" on an item named "Alpha Beta Coil" expands a
	 * line's "abc" into those words. Filter: `zstock_word_aliases` (word => [words]).
	 *
	 * @return array<string,string[]>
	 */
	public static function aliases(): array {
		$map = array();
		if ( class_exists( 'ZSTOCK_Engine' ) && class_exists( 'ZSTOCK_Catalog' ) ) {
			foreach ( ZSTOCK_Engine::items() as $it ) {
				$item = ZSTOCK_Catalog::get_item( (string) $it['id'] );
				foreach ( (array) ( $item['aliases'] ?? array() ) as $a ) {
					$a = strtolower( trim( (string) $a ) );
					if ( preg_match( '/^[a-z]{2,20}$/', $a ) ) {
						$map[ $a ] = self::words( (string) $it['name'], array() );
					}
				}
			}
		}
		$f = function_exists( 'apply_filters' ) ? apply_filters( 'zstock_word_aliases', $map ) : $map;
		return is_array( $f ) ? $f : $map;
	}

	/** The colour words. Core's list, which a business may extend with its own finishes. */
	public static function colours(): array {
		$f = function_exists( 'apply_filters' ) ? apply_filters( 'zstock_colour_words', self::COLOURS ) : self::COLOURS;
		return is_array( $f ) ? array_values( array_map( 'strtolower', $f ) ) : self::COLOURS;
	}

	/* ------------------------------------------------------------------ the list */

	/** The business's own domains, from the Email app when it is here. */
	public static function internal_domains(): array {
		return array_values( array_filter( array_map( function ( $d ) {
			return strtolower( trim( (string) $d ) );
		}, (array) apply_filters( 'zib_internal_domains', array() ) ) ) );
	}

	/** Is $domain $d or under it? PURE. */
	private static function under( string $domain, string $d ): bool {
		return '' !== $d && ( $domain === $d || substr( $domain, -strlen( $d ) - 1 ) === '.' . $d );
	}

	/**
	 * "Orders@Example.com" stays an address; "example.com" or "@Example.com" becomes "@example.com"; else ''.
	 * Refused (''): anything at the business's own domains; a whole free-mail domain; a public suffix ("co.uk").
	 * PURE given $internal.
	 */
	public static function normalize_match( string $m, ?array $internal = null ): string {
		$m = strtolower( trim( $m ) );
		if ( '' === $m ) {
			return '';
		}
		if ( false === strpos( $m, '@' ) ) {
			$m = '@' . $m;
		}
		$domain_re = '([a-z0-9]([a-z0-9\-]*[a-z0-9])?\.)+[a-z]{2,}';
		$is_domain = (bool) preg_match( '/^@' . $domain_re . '$/', $m );
		if ( ! $is_domain && ! preg_match( '/^[a-z0-9._%+\-]+@' . $domain_re . '$/', $m ) ) {
			return '';
		}
		$domain = substr( $m, strpos( $m, '@' ) + 1 );
		foreach ( ( null === $internal ? self::internal_domains() : $internal ) as $d ) {
			if ( self::under( $domain, (string) $d ) ) {
				return ''; // the business's own mail is never a supplier
			}
		}
		if ( ! $is_domain && is_callable( array( 'ZIB_Classifier', 'wp_is_registered_internal' ) ) && ZIB_Classifier::wp_is_registered_internal( $m ) ) {
			return ''; // a colleague's own address: the Email app would never pass its mail anyway
		}
		if ( $is_domain && self::too_wide( $domain ) ) {
			return ''; // everyone at a free-mail domain is not a supplier
		}
		return $m;
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
	 * Too wide to stand for one supplier: a public suffix, a free-mail provider or ISP (and its subdomains), a
	 * free-mail family on any country domain ("yahoo.co.uk"). PURE.
	 */
	public static function too_wide( string $domain ): bool {
		$labels = explode( '.', $domain );
		$sfx    = self::suffix_len( $labels );
		if ( count( $labels ) <= $sfx ) {
			return true;
		}
		foreach ( self::FREE_MAIL as $f ) {
			if ( self::under( $domain, $f ) ) {
				return true;
			}
		}
		return in_array( $labels[ count( $labels ) - $sfx - 1 ], self::FREE_FAMILY, true );
	}

	/** "billing.example-supplier.com" -> "example-supplier.com"; "x.example-supplier.co.uk" -> "example-supplier.co.uk". PURE. */
	public static function registrable( string $match ): string {
		$domain = substr( $match, (int) strpos( $match, '@' ) + 1 );
		$labels = explode( '.', $domain );
		$keep   = self::suffix_len( $labels ) + 1;
		return implode( '.', array_slice( $labels, -min( $keep, count( $labels ) ) ) );
	}

	/** The cleaned list. PURE given $internal. */
	public static function clean( $rows, ?array $internal = null ): array {
		$internal = null === $internal ? self::internal_domains() : $internal;
		$out      = array();
		$seen     = array();
		foreach ( (array) $rows as $r ) {
			if ( ! is_array( $r ) ) {
				continue;
			}
			$name  = trim( sanitize_text_field( (string) ( $r['name'] ?? '' ) ) );
			$match = self::normalize_match( (string) ( $r['match'] ?? '' ), $internal );
			if ( '' === $name || '' === $match || isset( $seen[ $match ] ) ) {
				continue;
			}
			$seen[ $match ] = true;
			$out[]          = array( 'name' => ZSTOCK_Orders::cut( $name, 120 ), 'match' => $match );
			if ( count( $out ) >= self::MAX ) {
				break;
			}
		}
		return $out;
	}

	public static function all(): array {
		return self::clean( get_option( self::OPTION, array() ) );
	}

	/** @return array{0:array,1:string[],2:string[]} the saved list, the matches added, the matches removed. */
	public static function save( array $rows ): array {
		$before = array_column( self::all(), 'match' );
		$clean  = self::clean( $rows );
		update_option( self::OPTION, $clean, false );
		$after = array_column( $clean, 'match' );
		return array( $clean, array_values( array_diff( $after, $before ) ), array_values( array_diff( $before, $after ) ) );
	}

	/** The supplier a sender belongs to: an exact address first, then the longest matching domain. PURE on $list. */
	public static function supplier_for( string $addr, ?array $list = null ): ?array {
		$addr = strtolower( trim( $addr ) );
		$at   = strpos( $addr, '@' );
		if ( false === $at ) {
			return null;
		}
		$domain = substr( $addr, $at + 1 );
		$best   = null;
		$len    = -1;
		foreach ( ( null === $list ? self::all() : $list ) as $r ) {
			$m = $r['match'];
			if ( '@' !== $m[0] ) {
				if ( $m === $addr ) {
					return $r;
				}
				continue;
			}
			$d = substr( $m, 1 );
			if ( self::under( $domain, $d ) && strlen( $d ) > $len ) {
				$best = $r;
				$len  = strlen( $d );
			}
		}
		return $best;
	}

	public static function senders_filter( $prev ) {
		$out = is_array( $prev ) ? $prev : array();
		foreach ( self::all() as $r ) {
			$out[] = $r['match'];
		}
		return $out;
	}

	/* ------------------------------------------------------------------ mail → Incoming */

	/** apply_filters( 'zib_supplier_message', null, $payload ) → { result: created|duplicate|skipped, order_id?, why? } */
	public static function message_filter( $prev, $p = array() ) {
		if ( ! is_array( $p ) ) {
			return $prev;
		}
		return self::take( $p );
	}

	/**
	 * The digits an order is matched on: one run -> that run ("INV00012345" -> 12345); a long number (5+ digits) with
	 * only short suffixes -> the number ("10422-01" -> 10422, a split shipment); anything else -> all the digits
	 * ("1001-55" and "1001-56", an account number first, stay two orders; "SO-2026-10422" -> 202610422, so a supplier
	 * whose numbers restart each year never collides with last year's). Leading zeros dropped. When unsure, two
	 * orders rather than one: a spare order is seen, a swallowed one is not. PURE.
	 */
	public static function core_of( string $ref ): string {
		preg_match_all( '/[0-9]+/', $ref, $m );
		$runs = $m[0];
		if ( 1 === count( $runs ) ) {
			$core = ltrim( $runs[0], '0' );
		} elseif ( $runs && strlen( ltrim( $runs[0], '0' ) ) >= 5 && max( array_map( 'strlen', array_slice( $runs, 1 ) ) ) <= 2 ) {
			$core = ltrim( $runs[0], '0' );
		} else {
			$core = ltrim( implode( '', $runs ), '0' );
		}
		return strlen( $core ) >= 4 ? $core : ltrim( implode( '', $runs ), '0' );
	}

	/** The numbers in a payload, checked again here: [ { kind, ref, core } ], orders first. PURE. */
	public static function refs_of( array $p ): array {
		$raw  = isset( $p['refs'] ) && is_array( $p['refs'] ) ? $p['refs'] : array( array( 'kind' => 'order', 'ref' => (string) ( $p['order_ref'] ?? '' ) ) );
		$out  = array();
		$seen = array();
		foreach ( array_slice( $raw, 0, 5 ) as $r ) {
			if ( ! is_array( $r ) ) {
				continue;
			}
			$ref  = strtoupper( (string) ( $r['ref'] ?? '' ) );
			$core = self::core_of( $ref );
			// The same shape the Email app insists on: a known prefix or none, at least 4 digits, not a date.
			if ( ! preg_match( '/^(?:(?:' . self::REF_PREFIXES . ')-?)?[0-9][0-9\-]{3,19}$/', $ref ) || strlen( $ref ) > 20 || strlen( preg_replace( '/[^0-9]/', '', $ref ) ) < 4 || '' === $core || isset( $seen[ $core ] )
				|| preg_match( '/^[0-9]{4}-[0-9]{1,2}-[0-9]{1,2}$|^[0-9]{1,2}-[0-9]{1,2}-[0-9]{2,4}$/', $ref )
				|| preg_match( '/^(19|20)[0-9]{2}$|^[0-9]{3}-?[0-9]{3}-?[0-9]{4}$|^[0-9]{3}-[0-9]{4}$|^[0-9]{2,3}-[0-9]{2,3}-[0-9]{4}$/', preg_replace( '/^[A-Z]+-?/', '', $ref ) ) ) { // a year, a phone, an SSN shape
				continue;
			}
			$seen[ $core ] = true;
			$out[]         = array( 'kind' => 'invoice' === ( $r['kind'] ?? '' ) ? 'invoice' : 'order', 'ref' => $ref, 'core' => $core );
		}
		usort( $out, function ( $a, $b ) {
			return ( 'order' === $a['kind'] ? 0 : 1 ) <=> ( 'order' === $b['kind'] ? 0 : 1 );
		} );
		return $out;
	}

	/** At most this many new email orders a day from one supplier. */
	public static function day_cap(): int {
		return max( 1, (int) ( function_exists( 'apply_filters' ) ? apply_filters( 'zstock_supplier_day_cap', self::DAY_CAP ) : self::DAY_CAP ) );
	}

	public static function take( array $p ): array {
		global $wpdb;
		$owner = (int) ( $p['owner_user_id'] ?? 0 );
		if ( $owner <= 0 || ! get_userdata( $owner ) || ZSTOCK_Orders::is_kiosk( $owner ) ) {
			return array( 'result' => 'skipped', 'why' => 'no-owner' );
		}
		$sup = self::supplier_for( (string) ( $p['from_addr'] ?? '' ) );
		if ( ! $sup ) {
			return array( 'result' => 'skipped', 'why' => 'not-a-listed-supplier' ); // the Email app checks too; Stock decides
		}
		$refs = self::refs_of( $p );
		if ( ! $refs ) {
			return array( 'result' => 'skipped', 'why' => 'no-order-number' ); // lines alone are an advert or a quote
		}
		if ( count( array_filter( $refs, function ( $r ) { return 'invoice' === $r['kind']; } ) ) >= 3 ) {
			return array( 'result' => 'skipped', 'why' => 'statement' ); // a statement of account lists many invoices
		}
		$cores = array_column( $refs, 'core' );
		$lines = array();
		foreach ( (array) ( $p['lines'] ?? array() ) as $l ) {
			if ( ! is_array( $l ) ) {
				continue;
			}
			$qty  = (float) ( $l['qty'] ?? 0 );
			$desc = ZSTOCK_Orders::cut( trim( sanitize_text_field( (string) ( $l['description'] ?? '' ) ) ), 120 );
			if ( ! is_finite( $qty ) || $qty < 1 || $qty > self::LINE_QTY || '' === $desc ) {
				continue;
			}
			$lines[] = array(
				'description' => $desc,
				'qty'         => $qty,
				'unit'        => ZSTOCK_Orders::cut( sanitize_text_field( (string) ( $l['unit'] ?? '' ) ), 30 ),
				'item_id'     => self::guess_item( $desc ),
			);
			if ( count( $lines ) >= ZSTOCK_Orders::MAX_LINES ) {
				break;
			}
		}
		$ref = $refs[0]['ref'];
		if ( ! $lines ) {
			// An order number but no lines Stock could read: the person adds what came on Received.
			/* translators: %s: the order number */
			$lines[] = array( 'description' => sprintf( __( 'Lines are in the email (order %s)', 'zorderz' ), $ref ), 'qty' => 1, 'unit' => '', 'item_id' => '' );
		}

		// One supplier at a time: ingest and a scan of the same message must not both make the order.
		$lock = 'supmail_' . substr( sha1( $sup['match'] ), 0, 20 );
		if ( ! ZSTOCK_Orders::lock( $lock ) ) {
			return array( 'result' => 'skipped', 'why' => 'busy' );
		}
		try {
			$found = self::find_order( $sup, $cores );
			if ( $found ) {
				// An open email order learns the new numbers, so the invoice alone later still finds it.
				if ( 'email' === $found['source'] && in_array( $found['status'], ZSTOCK_Orders::OPEN_STATUSES, true ) ) {
					$merged = ZSTOCK_Orders::cores_field( array_merge( array_filter( explode( '|', (string) $found['ref_cores'] ) ), $cores ) );
					if ( $merged !== (string) $found['ref_cores'] ) {
						$orders = ZSTOCK_DB::orders_table();
						// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is $wpdb->prefix plus a fixed name; values are placeholders.
						$wpdb->query( $wpdb->prepare( "UPDATE `{$orders}` SET ref_cores = %s WHERE id = %d", $merged, (int) $found['id'] ) );
					}
				}
				return array( 'result' => 'duplicate', 'order_id' => (int) $found['id'], 'why' => 'rejected' === $found['status'] ? 'not-ours' : 'same-order' );
			}
			$since  = substr( (string) current_time( 'mysql' ), 0, 10 ) . ' 00:00:00';
			$orders = ZSTOCK_DB::orders_table();
			$today  = (int) $wpdb->get_var( $wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is $wpdb->prefix plus a fixed name; values are placeholders.
				"SELECT COUNT(*) FROM `{$orders}` WHERE source = 'email' AND supplier_match = %s AND created_at >= %s",
				$sup['match'],
				$since
			) );
			if ( $today >= self::day_cap() ) {
				return array( 'result' => 'skipped', 'why' => 'daily-cap' );
			}
			$key  = strtolower( (string) ( $p['message_key'] ?? '' ) );
			$date = substr( (string) ( $p['received_at'] ?? '' ), 0, 10 );
			$r    = ZSTOCK_Orders::create_incoming( array(
				'supplier'       => $sup['name'],
				'ref'            => $ref,
				'date'           => preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ? $date : '',
				'source'         => 'email',
				'source_key'     => preg_match( '/^[a-f0-9]{40,64}$/', $key ) ? $key : '',
				'supplier_match' => $sup['match'],
				'ref_cores'      => $cores,
				'lines'          => $lines,
			), $owner );
		} finally {
			ZSTOCK_Orders::unlock( $lock );
		}
		if ( ! empty( $r['ok'] ) ) {
			return array( 'result' => 'created', 'order_id' => (int) $r['order_id'] );
		}
		if ( ! empty( $r['duplicate'] ) ) {
			return array( 'result' => 'duplicate', 'order_id' => (int) $r['order_id'], 'why' => 'same-order' );
		}
		return array( 'result' => 'skipped', 'why' => (string) ( $r['message'] ?? 'not saved' ) );
	}

	/**
	 * An order this supplier already has with any of these numbers, in any state (Not ours included): an email order
	 * under the same list entry (or the same supplier name, if the entry was edited since) carrying one of the cores,
	 * or a typed-in order for the supplier whose number has the same digits.
	 */
	public static function find_order( array $sup, array $cores ): ?array {
		global $wpdb;
		if ( ! $cores ) {
			return null;
		}
		$t    = ZSTOCK_DB::orders_table();
		$like = array();
		// The same supplier under another list row of its own domain ("orders@example-supplier.test" and
		// "@billing.example-supplier.test"). Never across a free-mail or ISP domain, or a host many businesses send
		// from: two senders at the same free-mail domain, or two shops on one shop platform, are two suppliers.
		$base  = self::registrable( $sup['match'] );
		$wide  = self::too_wide( $base ) || in_array( $base, self::SHARED_HOSTS, true );
		$exact = $wpdb->esc_like( $sup['match'] ); // a LIKE with no wildcard: an escaped "_" must not match any letter
		$args  = array( $sup['match'], $sup['name'], $wide ? $exact : '%@' . $wpdb->esc_like( $base ), $wide ? $exact : '%.' . $wpdb->esc_like( $base ) );
		foreach ( $cores as $c ) {
			$like[] = 'ref_cores LIKE %s';
			$args[] = '%|' . $wpdb->esc_like( $c ) . '|%';
		}
		$row = $wpdb->get_row( $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- table name is $wpdb->prefix plus a fixed name; the OR list repeats a fixed fragment and every value is a placeholder.
			"SELECT id, status, source, ref_cores FROM `{$t}` WHERE source = 'email' AND ( supplier_match = %s OR supplier_name = %s OR supplier_match LIKE %s OR supplier_match LIKE %s ) AND ( " . implode( ' OR ', $like ) . ' ) ORDER BY id ASC LIMIT 1',
			$args
		), ARRAY_A );
		if ( $row ) {
			return $row;
		}
		$typed = (array) $wpdb->get_results( $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is $wpdb->prefix plus a fixed name; the name is a placeholder.
			"SELECT id, status, source, ref_cores, invoice_number FROM `{$t}` WHERE source <> 'email' AND supplier_name = %s AND invoice_number <> '' ORDER BY id DESC LIMIT 200",
			$sup['name']
		), ARRAY_A );
		foreach ( $typed as $o ) {
			if ( in_array( self::core_of( (string) $o['invoice_number'] ), $cores, true ) ) { // the same rule as email
				return $o;
			}
		}
		return null;
	}

	/** "36\"x100'", "36 x 100", "36in x 100ft" -> "36x100"; '' when there is no size. PURE. */
	public static function size_of( string $s ): string {
		return preg_match( '/\b([0-9]{1,3})\s*(?:"|in(?:ch(?:es)?)?|\x{2033}|\x{201D})?\s*[x\x{00D7}]\s*([0-9]{1,3})(?![0-9])/iu', $s, $m ) ? $m[1] . 'x' . $m[2] : '';
	}

	/** rolls -> roll; glass stays glass. PURE. */
	public static function singular( string $w ): string {
		return ( strlen( $w ) > 3 && 's' === substr( $w, -1 ) && 'ss' !== substr( $w, -2 ) ) ? substr( $w, 0, -1 ) : $w;
	}

	/** A line's or a name's words: lowercase, singular, aliases expanded, no numbers. PURE given $aliases. */
	public static function words( string $s, ?array $aliases = null ): array {
		$aliases = null === $aliases ? self::aliases() : $aliases;
		$out     = array();
		foreach ( preg_split( '/[^a-z]+/', strtolower( $s ) ) as $w ) {
			$w = self::singular( $w );
			if ( '' === $w ) {
				continue;
			}
			foreach ( (array) ( $aliases[ $w ] ?? array( $w ) ) as $x ) {
				$out[ (string) $x ] = true;
			}
		}
		return array_keys( $out );
	}

	/**
	 * The stock item for a line, pre-picked for the person to check on Received — only on a clear match: every word
	 * of the line (beyond units) is in the item's name; the item's colour, if it has one, is in the line; a size on
	 * both must agree; and exactly one item fits (or exactly one fits with nothing else in its name). '' otherwise.
	 * PURE given $items (each { id, name }), $aliases, $units and $colours.
	 */
	public static function guess_item( string $desc, ?array $items = null, ?array $aliases = null, ?array $units = null, ?array $colours = null ): string {
		$aliases = null === $aliases ? self::aliases() : $aliases;
		$units   = null === $units ? array_merge( self::UNIT_WORD, array_map( array( __CLASS__, 'singular' ), self::catalog_units() ) ) : $units;
		$colours = null === $colours ? self::colours() : $colours;
		$want    = array_values( array_diff( self::words( $desc, $aliases ), $units ) );
		if ( ! $want ) {
			return '';
		}
		$size  = self::size_of( $desc );
		$items = null === $items ? ( class_exists( 'ZSTOCK_Engine' ) ? ZSTOCK_Engine::items() : array() ) : $items;
		$fit   = array();
		$exact = array();
		foreach ( $items as $it ) {
			$has = array_values( array_diff( self::words( (string) $it['name'], $aliases ), $units ) );
			if ( array_diff( $want, $has ) ) {
				continue; // a word of the line is not in this item
			}
			if ( array_diff( array_intersect( $has, $colours ), $want ) ) {
				continue; // the item is a colour the line does not say
			}
			$isz = self::size_of( (string) $it['name'] );
			if ( '' !== $size && '' !== $isz && $size !== $isz ) {
				continue; // a 36x100 roll is not the 60x50 roll
			}
			$fit[] = (string) $it['id'];
			if ( ! array_diff( $has, $want ) ) {
				$exact[] = (string) $it['id'];
			}
		}
		if ( 1 === count( $fit ) ) {
			return $fit[0];
		}
		return 1 === count( $exact ) ? $exact[0] : '';
	}
}
