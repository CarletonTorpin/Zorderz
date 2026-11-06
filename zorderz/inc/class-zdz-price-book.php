<?php
/**
 * ZDZ_Price_Book -- the priced catalog the estimate, the commission calculator, the chat and Privacy View read.
 *
 * It is not a second catalog. A price book family is an Item Engine item (its attributes carry
 * `price_book_family`), and the family's variants -- every orderable size, option and part, each with a SKU -- live
 * in that item's pricing scheme, of the Item Engine method `grid`. What a thing IS stays in the catalog; what it COSTS
 * the customer is the scheme; what it costs the BUSINESS (supplier costs) is kept apart, admin-only, in the option
 * zdz_price_book_costs, because the Item Engine's catalog is readable by every signed-in user.
 *
 * ── CONTRACT ─────────────────────────────────────────────────────────────────────────────────
 *  - Ships empty. Core holds no product, size, price, cost or supplier: a business's book arrives in its Identity
 *    Pack (catalog, pricing and costs files, the `price_book` block of each) and goes live only through the pack's
 *    preview -> confirm -> snapshot flow, which revert undoes. Readers of an empty book get null / [] / '' and keep
 *    their own neutral behaviour.
 *  - Numbers are never written by a model. A line is priced in code (match_line), from the words of its name and
 *    sub-line and its size, with the family's matching vocabulary from the pack; a line the book cannot price says
 *    why instead of guessing. A line's notes are never read.
 *  - COSTS NEVER REACH A CUSTOMER, and never a person below the admin rung. published(), item(), items(), family()
 *    and prompt_block() carry prices only. cost() is the one server-side read for cost-of-goods arithmetic
 *    (commissions, material costs): its result is never sent to a browser as it is. What a person may see goes
 *    through cost_visible() / item_for(), which answer only for an administrator (ZDZ_Capabilities' admin rung) who
 *    is not in Privacy View and not the shared device. Deny by default: an unknown role sees no cost.
 *  - One-use business rules (a free service earned by an earlier purchase) are kept as entitlements: reserved before
 *    the document is written (two documents at once cannot both get it), confirmed after, released if the write
 *    fails or an administrator voids it.
 *
 * Inches are the size unit; parse_wh() reads inches, feet, millimetres and centimetres.
 *
 * @package Zorderz
 * @since   2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZDZ_Price_Book {

	const ENT_TABLE       = 'zdz_price_book_entitlements';
	const SCHEMA_VERSION  = 1;
	const SCHEMA_OPT      = 'zdz_price_book_schema';
	const COSTS_OPT       = 'zdz_price_book_costs';     // array( 'families' => [key => source], 'items' => [sku => [cost, source]] )
	const VERSION_OPT     = 'zdz_price_book_version';   // array( 'label', 'pack', 'applied_at', 'by' )
	const SNAPSHOT_OPT    = 'zdz_price_book_snapshot';  // what a pack apply replaced (Identity Pack revert restores it)
	const FAMILY_ATTR     = 'price_book_family';
	const SCHEME_PREFIX   = 'price-book-';
	const CAP             = 'manage_options';
	const MAX_ITEMS       = 6000;
	const MAX_MONEY       = 100000;
	const RESERVATION_TTL = 900;   // seconds an unconfirmed entitlement reservation is held

	/** Per-request cache of the assembled book (false: no book). */
	private static $pub = null;

	public static function boot() {
		if ( function_exists( 'add_filter' ) ) {
			add_filter( 'zdz_identity_pack_consumers', array( __CLASS__, 'pack_consumer' ) );
		}
	}

	/** Forget the per-request cache (after a write, and in tests). */
	public static function flush() {
		self::$pub = null;
	}

	/* =========================================================================================
	 * READ SIDE -- the live book, from the Item Engine
	 * ========================================================================================= */

	/**
	 * The live book: array( 'version', 'families' => [key => family], 'items' => [sku => item] ), prices only. Null when
	 * no family is in the book. A family: key, item_id, label, brand, price_rules, rules, prompt, match, fit.
	 * An item: sku, family, kind, brand, model, label, line_item, dims, attributes, uom, price, double_price,
	 * price_basis, warning.
	 */
	public static function published() {
		if ( null !== self::$pub ) {
			return self::$pub ?: null;
		}
		self::$pub = false;
		if ( ! class_exists( 'ZDZ_Item_Engine' ) ) {
			return null;
		}
		$families = array();
		$items    = array();
		try {
			foreach ( (array) ZDZ_Item_Engine::all() as $id => $it ) {
				$key = (string) ( $it['attributes'][ self::FAMILY_ATTR ] ?? '' );
				if ( '' === $key || isset( $families[ $key ] ) ) {
					continue;
				}
				$scheme = '' !== (string) $it['pricing_scheme_id'] ? ZDZ_Item_Engine::pricing_scheme( $it['pricing_scheme_id'] ) : null;
				if ( ! is_array( $scheme ) || 'grid' !== ( $scheme['method'] ?? '' ) ) {
					continue;
				}
				$p    = (array) $scheme['params'];
				$attr = (array) $it['attributes'];
				$families[ $key ] = array(
					'key'         => $key,
					'item_id'     => (string) $id,
					'label'       => (string) ( '' !== (string) $it['display_name'] ? $it['display_name'] : $key ),
					'brand'       => (string) ( $attr['brand'] ?? '' ),
					'aliases'     => array_values( array_map( function ( $a ) { return is_array( $a ) ? (string) ( $a['value'] ?? '' ) : (string) $a; }, (array) $it['aliases'] ) ),
					'price_rules' => (array) ( $p['price_rules'] ?? array() ),
					'rules'       => array_values( (array) ( $p['rules'] ?? array() ) ),
					'prompt'      => (string) ( $attr['price_book_prompt'] ?? '' ),
					'match'       => (array) ( $attr['price_book_match'] ?? array() ),
				);
				foreach ( (array) ( $p['variants'] ?? array() ) as $v ) {
					if ( is_array( $v ) && isset( $v['sku'] ) && ! isset( $items[ (string) $v['sku'] ] ) ) {
						$v['family'] = $key;
						unset( $v['cost'], $v['source'] );   // never in the catalog; and never read from it
						$items[ (string) $v['sku'] ] = $v;
					}
				}
			}
		} catch ( \Throwable $e ) {
			return null;   // no catalog: no book, never a guess
		}
		if ( ! $families ) {
			return null;
		}
		$ver       = function_exists( 'get_option' ) ? get_option( self::VERSION_OPT, array() ) : array();
		self::$pub = array(
			'version'  => is_array( $ver ) && '' !== (string) ( $ver['label'] ?? '' ) ? (string) $ver['label'] : 'catalog-' . (int) ZDZ_Item_Engine::version(),
			'families' => $families,
			'items'    => $items,
		);
		return self::$pub;
	}

	/** Live version label, '' when there is no book. */
	public static function version() {
		$b = self::published();
		return $b ? (string) $b['version'] : '';
	}

	/** One live item, prices only (no cost). */
	public static function item( $sku ) {
		$b = self::published();
		return ( $b && isset( $b['items'][ (string) $sku ] ) ) ? $b['items'][ (string) $sku ] : null;
	}

	/** Customer price for a SKU; null = not in the book, or "quote by hand". */
	public static function price( $sku ) {
		$it = self::item( $sku );
		return ( $it && null !== ( $it['price'] ?? null ) ) ? (float) $it['price'] : null;
	}

	/**
	 * Supplier cost for a SKU of the live book, for server-side cost-of-goods arithmetic only. Never send this value to
	 * a browser, a document, a prompt or a log: what a person may see goes through cost_visible().
	 */
	public static function cost( $sku ) {
		if ( null === self::item( $sku ) ) {
			return null;
		}
		$c = function_exists( 'get_option' ) ? get_option( self::COSTS_OPT, array() ) : array();
		$v = is_array( $c ) ? ( $c['items'][ (string) $sku ]['cost'] ?? null ) : null;
		return is_numeric( $v ) ? (float) $v : null;
	}

	/** May this person see supplier costs? The admin rung only, never in Privacy View, never the shared device. */
	public static function may_see_costs( $uid ) {
		$uid = (int) $uid;
		if ( $uid <= 0 ) {
			return false;
		}
		if ( class_exists( 'ZDZ_Privacy_View' ) && ZDZ_Privacy_View::is_on( $uid ) ) {
			return false;
		}
		if ( class_exists( 'ZDZ_User_Roles' ) && method_exists( 'ZDZ_User_Roles', 'is_shared_device' ) && ZDZ_User_Roles::is_shared_device( $uid ) ) {
			return false;
		}
		if ( class_exists( 'ZDZ_Capabilities' ) && method_exists( 'ZDZ_Capabilities', 'tier_of' ) ) {
			return 'admin' === ZDZ_Capabilities::tier_of( $uid );
		}
		return function_exists( 'user_can' ) && user_can( $uid, self::CAP );
	}

	/** A cost a person may see: the cost for an administrator, null for anyone else. */
	public static function cost_visible( $sku, $uid ) {
		return self::may_see_costs( $uid ) ? self::cost( $sku ) : null;
	}

	/** One item as this person may see it: with its cost for an administrator, without for anyone else. */
	public static function item_for( $sku, $uid ) {
		$it = self::item( $sku );
		if ( null === $it ) {
			return null;
		}
		if ( self::may_see_costs( $uid ) ) {
			$it['cost'] = self::cost( $sku );
		}
		return $it;
	}

	/** A live family (label, brand, price_rules, rules, prompt, match), or null. */
	public static function family( $key ) {
		$b = self::published();
		return ( $b && isset( $b['families'][ (string) $key ] ) ) ? $b['families'][ (string) $key ] : null;
	}

	/** Items of one family, optionally of some kinds (prices only). */
	public static function items( $family, array $kinds = array() ) {
		$b = self::published();
		if ( ! $b ) {
			return array();
		}
		$out = array();
		foreach ( $b['items'] as $sku => $it ) {
			if ( ( $it['family'] ?? '' ) !== (string) $family ) {
				continue;
			}
			if ( $kinds && ! in_array( $it['kind'] ?? '', $kinds, true ) ) {
				continue;
			}
			$out[ $sku ] = $it;
		}
		return $out;
	}

	/** A family's price rules as written (what the business says its prices are), [] when none. */
	public static function price_rules( $family ) {
		$f = self::family( $family ); // phpcs:ignore PHPCompatibility.Extensions.RemovedExtensions.famRemoved -- self::family() is this class's own method, not the removed fam extension
		return $f ? (array) $f['price_rules'] : array();
	}

	/** A family's business rule (e.g. a one-use entitlement), or null. */
	public static function rule( $family, $rule_id ) {
		$f = self::family( $family ); // phpcs:ignore PHPCompatibility.Extensions.RemovedExtensions.famRemoved -- self::family() is this class's own method, not the removed fam extension
		foreach ( (array) ( $f['rules'] ?? array() ) as $r ) {
			if ( ( $r['id'] ?? '' ) === (string) $rule_id ) {
				return (array) $r;
			}
		}
		return null;
	}

	/**
	 * The family's prices as a prompt block for a model that writes estimate lines. PRICES ONLY, never a cost. The model
	 * is told the server sets the exact price: it only has to describe the line well. The family's own guidance on
	 * how to write a line comes from its pack.
	 */
	public static function prompt_block( $family ) {
		$f = self::family( $family ); // phpcs:ignore PHPCompatibility.Extensions.RemovedExtensions.famRemoved -- self::family() is this class's own method, not the removed fam extension
		if ( ! $f ) {
			return '';
		}
		$who = class_exists( 'ZDZ_Business_Profile' ) ? strtoupper( (string) ZDZ_Business_Profile::name() ) : '';
		$out = "\n\n## OFFICIAL " . ( '' !== $who ? self::clean_text( $who, 80 ) . ' ' : '' ) . 'PRICE BOOK — ' . strtoupper( self::clean_text( (string) $f['label'], 120 ) ) . ' (price book ' . self::version() . ")\n";
		$out .= "These are the official prices. They OVERRIDE every other price above for these products.\n";
		foreach ( (array) $f['price_rules'] as $what => $price ) {
			$out .= '- ' . self::clean_text( (string) $what ) . ': ' . self::clean_text( (string) $price ) . "\n";
		}
		foreach ( (array) $f['rules'] as $r ) {
			if ( ! empty( $r['text'] ) ) {
				$out .= '- Rule: ' . self::clean_text( (string) $r['text'] ) . " (the system applies this when the estimate is created; do not discount it yourself)\n";
			}
		}
		if ( '' !== trim( (string) $f['prompt'] ) ) {
			$out .= rtrim( (string) $f['prompt'] ) . "\n";
		}
		return $out;
	}

	/* =========================================================================================
	 * MATCHING -- which SKU does an estimate line mean? (deterministic; same answer every time)
	 * ========================================================================================= */

	/**
	 * A family's matching vocabulary (catalog.yml family `match`), every list compiled to one pattern or ''. A list
	 * entry is a word or phrase (whole words, any spacing or hyphen between its words) or, written "re:...", a PCRE
	 * fragment; a fragment that does not compile is left out.
	 */
	private static function pattern( $list ) {
		$alts = array();
		foreach ( (array) $list as $w ) {
			$w = trim( (string) $w );
			if ( '' === $w ) {
				continue;
			}
			if ( 0 === strpos( $w, 're:' ) ) {
				$frag = substr( $w, 3 );
				if ( '' !== $frag && false !== @preg_match( '/' . str_replace( '/', '\/', $frag ) . '/u', '' ) ) {   // phpcs:ignore -- a pack fragment that does not compile is skipped
					$alts[] = '(?:' . str_replace( '/', '\/', $frag ) . ')';
				}
				continue;
			}
			$parts  = preg_split( '/[\s\-]+/u', mb_strtolower( $w ), -1, PREG_SPLIT_NO_EMPTY );
			$alts[] = '\b' . implode( '[\s\-]*', array_map( function ( $p ) { return preg_quote( $p, '/' ); }, $parts ) ) . '\b';
		}
		return $alts ? '/' . implode( '|', $alts ) . '/u' : '';
	}

	private static function hits( $pattern, $text, &$m = null ) {
		return '' !== $pattern && 1 === preg_match( $pattern, (string) $text, $m );
	}

	/** The family's detection words: its `match.names`, else its brand and its catalog aliases. */
	private static function names_pattern( array $f ) {
		$names = (array) ( $f['match']['names'] ?? array() );
		if ( ! $names ) {
			$names = array_filter( array_merge( array( (string) $f['brand'] ), (array) $f['aliases'] ) );
		}
		return self::pattern( $names );
	}

	/**
	 * Is this line one of the family's products? Decided from the line's NAME (description), or from the name plus the
	 * family's name in the sub-line when the name is one of the family's generic names. Another brand in the name
	 * means it is that brand's. Notes are never read.
	 */
	public static function is_family_line( $family, $desc, $sub ) {
		$f = self::family( $family ); // phpcs:ignore PHPCompatibility.Extensions.RemovedExtensions.famRemoved -- self::family() is this class's own method, not the removed fam extension
		if ( ! $f ) {
			return false;
		}
		$d     = mb_strtolower( (string) $desc );
		$s     = mb_strtolower( (string) $sub );
		$names = self::names_pattern( $f );
		$other = self::pattern( $f['match']['other_brands'] ?? array() );
		if ( '' === $names || self::hits( $other, $d ) ) {
			return false;
		}
		if ( self::hits( $names, $d ) ) {
			return true;   // named: a brand in the sub-line is only mentioned
		}
		$generic = self::pattern( $f['match']['generic_names'] ?? array() );
		$in_sub  = isset( $f['match']['sub_names'] ) ? self::pattern( $f['match']['sub_names'] ) : $names;
		return self::hits( $generic, $d ) && self::hits( $in_sub, $s ) && ! self::hits( $other, $s );
	}

	/**
	 * Parse an opening size to [w, h] inches ([null, null] when absent). Accepts 36" x 80", 36x80, 35 1/2 x 81 3/4,
	 * 36-1/2" x 80", 36 by 96, 3' x 6'8", 914mm x 2032mm, 36" W x 80" H, and 80" H x 36" W (labels win over order).
	 * Smart quotes and × are normalised.
	 */
	public static function parse_wh( $s ) {
		$s = str_replace( array( "\u{201C}", "\u{201D}", "\u{2033}", "\u{2019}", "\u{2032}", "\u{00D7}", "''" ), array( '"', '"', '"', "'", "'", 'x', '"' ), (string) $s );
		$s = preg_replace( '/(\d),(\d{3})(?!\d)/', '$1$2', $s );                 // 1,200mm
		$s = preg_replace( '/(\d)\s*(?:ft|feet|foot)\b\.?/i', "$1'", $s );        // 12 ft -> 12'
		$dim = "(?<![\\d\\/.])(?:(\\d{1,2})'\\s*-?\\s*(\\d{1,2}(?:[\\s-]+\\d{1,2}\\/\\d{1,2})?)?\\s*\"?|(\\d{1,4}(?:\\.\\d+)?(?:[\\s-]+\\d{1,2}\\/\\d{1,2})?)\\s*(mm|cm|in(?:ches|ch)?\\b|\")?)\\s*(?:(w(?:ide|idth)?|h(?:igh|eight)?|tall)\\b\\.?)?";
		if ( ! preg_match( '/' . $dim . '\s*(?:x|by)\s*' . $dim . '/i', $s, $m ) ) {
			return array( null, null );
		}
		$a  = self::dim_value( $m[1] ?? '', $m[2] ?? '', $m[3] ?? '', $m[4] ?? '' );
		$b  = self::dim_value( $m[6] ?? '', $m[7] ?? '', $m[8] ?? '', $m[9] ?? '' );
		$la = strtolower( (string) ( $m[5] ?? '' ) );
		$lb = strtolower( (string) ( $m[10] ?? '' ) );
		if ( ! $a || ! $b || $a <= 0 || $b <= 0 ) {
			return array( null, null );   // a zero side is a missing size
		}
		if ( ( '' !== $la && 'w' !== $la[0] ) || ( '' !== $lb && 'w' === $lb[0] ) ) {
			return array( $b, $a );
		}
		return array( $a, $b );
	}

	private static function dim_value( $ft, $ftin, $num, $unit ) {
		if ( '' !== $ft ) {
			return 12.0 * (float) $ft + ( '' !== $ftin ? self::inches( $ftin ) : 0.0 );
		}
		if ( '' === $num ) {
			return null;
		}
		$v = self::inches( $num );
		$u = strtolower( $unit );
		if ( 'mm' === $u ) {
			return round( $v / 25.4, 3 );
		}
		if ( 'cm' === $u ) {
			return round( $v / 2.54, 3 );
		}
		return $v;
	}

	private static function inches( $s ) {
		$s = trim( (string) $s );
		if ( preg_match( '/^(\d+(?:\.\d+)?)[\s-]+(\d+)\/(\d+)$/', $s, $m ) ) {
			return (float) $m[1] + ( (float) $m[2] / max( 1, (float) $m[3] ) );
		}
		return (float) $s;
	}

	/** A size for a note: 36, 36.5 (never 36.50). */
	public static function fmt_in( $v ) {
		if ( null === $v ) {
			return '?';
		}
		$v = (float) $v;
		return ( abs( $v - round( $v ) ) < 0.01 ) ? (string) (int) round( $v ) : rtrim( rtrim( number_format( $v, 2, '.', '' ), '0' ), '.' );
	}

	/** Core's reasons for "no price", each replaceable by the family's `match.reasons`. */
	const REASONS = array(
		'other'        => 'Work that is not a unit this book prices (service, parts, removal, a credit or a fee) - not priced from the book.',
		'not_in_book'  => 'This product is not in the price book yet - price by hand.',
		'size_needed'  => 'This item is priced by its size - add the opening (W x H) to price it.',
		'too_wide'     => 'A {w}" opening is wider than this product is offered - price by hand.',
		'no_fit'       => 'Nothing in the price book covers {w}" wide x {h}" tall - price by hand.',
		'no_price'     => 'The price book has no list price for this item - price by hand.',
		'choice_unpriced' => 'Both choices were named and one of them could not be priced - write each as its own line.',
	);

	/**
	 * Resolve one estimate line to a live SKU of a family.
	 *
	 * The family's `match` block (catalog.yml) drives it:
	 *   names, generic_names, sub_names, other_brands   which lines are the family's (is_family_line; sub_names,
	 *                                        the words that name the family in a sub-line, default to names)
	 *   skip     [words]                     a sub-line with one of these is not priced at all (a line the system wrote)
	 *   flags    {name: [words]}             facts for notes ("upcharge": a finish the price does not include)
	 *   vetoes   [ {field: name|sub|text, words: [...], strip: [words], unless: {fact: value}, unless_sized: bool,
	 *              reason} ]                 work that is not a unit (service, parts, a fee): kind 'other', no price;
	 *                                        `strip` words are taken out of the field before it is read
	 *   not_in_book  [words]                 the family's products the book does not price yet
	 *   kinds    [ {when: {fact: value}, kind} ]   the line's kind before a variant is chosen
	 *   selectors [ {key, options: {value: [words]}, default, fallback: {value: other value}, applies_when: {fact: value},
	 *               choice: bool} ]          a variant attribute chosen by the line's words; a value no variant has
	 *                                        falls back along `fallback`; two `choice` values named split the line in two
	 *   pair      {words, doubled_when: {fact: value}}   a pair: each unit half the width; doubled price when it applies
	 *   single    {max_width, max_pair_width, applies_when}   wider than one unit: priced as a pair; wider than a pair:
	 *                                        no price
	 *   swap      {below_height, applies_when}   a size wider than tall, under that height, is read turned
	 *   size      {default_width, default_height, applies_when, required_when}
	 *   fit       {order: [width|height, ...], by: {fact-value: order}}   which covering variant is the smallest
	 *   notes     [ {when: {fact: value}, text} ]   what the matcher assumed, for the person writing the estimate
	 *   labels    {key: {value: words}}      how a chosen value is written in a reason ({key})
	 *   reasons   {other, not_in_book, size_needed, too_wide, no_fit, no_price, choice_unpriced}; no_fit:<kind> and
	 *             no_price:<kind> word it per kind, no_price_warning:<kind> folds the item's warning in ({warning});
	 *             a reason may name any selector's chosen value ({key})
	 * Facts a condition may name: every selector key (its chosen value), named:<key> (true when the line named it),
	 * from:<key> (the value before a fallback), every flag, pair, forced_pair, swapped, size_assumed, sized,
	 * other_brand, priced (a listed price was found), and choice (on a split line). A note's text may use {w}, {h}, {leaf} and {other}.
	 *
	 * @param array $line description, sub_description, dimensions, quantity (notes are never read).
	 * @return array matched, sku, price (a pair's whole price when doubled), kind, double, assumed[], reason, label,
	 *               warning, and for a split line both_choices, choice_key + options[] (each: the choice's value, sku,
	 *               price, double, label, line_item, assumed, reason).
	 */
	public static function match_line( $family, array $line ) {
		$none = array( 'matched' => false, 'sku' => null, 'price' => null, 'kind' => '', 'double' => false, 'assumed' => array(), 'reason' => '', 'label' => '', 'warning' => '' );
		$f    = self::family( $family ); // phpcs:ignore PHPCompatibility.Extensions.RemovedExtensions.famRemoved -- self::family() is this class's own method, not the removed fam extension
		if ( ! $f || ! self::is_family_line( $family, (string) ( $line['description'] ?? '' ), (string) ( $line['sub_description'] ?? '' ) ) ) {
			$r = $none;
		} else {
			$r = self::match_in( $f, $line, $none );
		}
		if ( function_exists( 'apply_filters' ) ) {
			$x = apply_filters( 'zdz_price_book_match_line', $r, (string) $family, $line );
			$r = is_array( $x ) ? $x + $none : $r;
		}
		return $r;
	}

	private static function reason( array $f, $key, array $vars = array() ) {
		$t = (string) ( $f['match']['reasons'][ $key ] ?? self::REASONS[ $key ] ?? '' );
		return strtr( $t, $vars );
	}

	/** Does a condition hold for these facts? Every key must equal (or be one of a list). An empty condition holds. */
	private static function holds( $when, array $facts ) {
		foreach ( (array) $when as $k => $v ) {
			$have = $facts[ (string) $k ] ?? null;
			if ( is_array( $v ) ? ! in_array( $have, $v, true ) : $have !== $v ) {
				return false;
			}
		}
		return true;
	}

	private static function match_in( array $f, array $line, array $none ) {
		$m     = (array) $f['match'];
		$desc  = (string) ( $line['description'] ?? '' );
		$sub   = (string) ( $line['sub_description'] ?? '' );
		$dimf  = (string) ( $line['dimensions'] ?? '' );
		$text  = mb_strtolower( $desc . ' ' . $sub );   // NEVER notes
		if ( self::hits( self::pattern( $m['skip'] ?? array() ), mb_strtolower( $sub ) ) ) {
			return $none;   // a line the system wrote itself is never re-priced
		}
		$r     = $none;
		$r['matched'] = true;

		// the selectors' words, read once
		$facts = array();
		$named = array();
		foreach ( (array) ( $m['selectors'] ?? array() ) as $sel ) {
			$key = (string) ( $sel['key'] ?? '' );
			if ( '' === $key ) {
				continue;
			}
			$named[ $key ] = array();
			foreach ( (array) ( $sel['options'] ?? array() ) as $value => $words ) {
				if ( self::hits( self::pattern( $words ), $text ) ) {
					$named[ $key ][] = (string) $value;
				}
			}
			$facts[ $key ]            = $named[ $key ] ? $named[ $key ][0] : (string) ( $sel['default'] ?? '' );
			$facts[ 'named:' . $key ] = (bool) $named[ $key ];
		}
		list( $w, $h ) = self::parse_wh( $dimf );
		if ( null === $w ) {
			list( $w, $h ) = self::parse_wh( $desc . ' ' . $sub );
		}
		$facts['sized']       = null !== $w;
		$facts['pair']        = self::hits( self::pattern( $m['pair']['words'] ?? array() ), $text );
		$facts['forced_pair'] = false;
		$facts['swapped']     = false;
		$facts['size_assumed'] = false;
		$other = self::pattern( $m['other_brands'] ?? array() );
		$facts['other_brand'] = self::hits( $other, mb_strtolower( $sub ), $om );
		$vars  = array( '{other}' => isset( $om[0] ) ? ucwords( $om[0] ) : '' );
		foreach ( (array) ( $m['flags'] ?? array() ) as $flag => $words ) {
			if ( preg_match( '/^[a-z][a-z0-9_]{0,31}$/', (string) $flag ) && ! isset( $facts[ $flag ] ) ) {
				$facts[ $flag ] = self::hits( self::pattern( $words ), $text );
			}
		}

		// work that is not a unit: kept with its own price and a reason
		foreach ( (array) ( $m['vetoes'] ?? array() ) as $v ) {
			$field = (string) ( $v['field'] ?? 'name' );
			$hay   = 'sub' === $field ? mb_strtolower( $sub ) : ( 'text' === $field ? $text : mb_strtolower( $desc ) );
			if ( ! empty( $v['unless'] ) && self::holds( $v['unless'], $facts ) ) {
				continue;
			}
			if ( ! empty( $v['unless_sized'] ) && $facts['sized'] ) {
				continue;
			}
			if ( ! empty( $v['strip'] ) && '' !== self::pattern( $v['strip'] ) ) {
				$hay = (string) preg_replace( self::pattern( $v['strip'] ), ' ', $hay );
			}
			if ( self::hits( self::pattern( $v['words'] ?? array() ), $hay ) ) {
				$r['kind']   = 'other';
				$r['reason'] = '' !== (string) ( $v['reason'] ?? '' ) ? (string) $v['reason'] : self::reason( $f, 'other' );
				return $r;
			}
		}
		if ( self::hits( self::pattern( $m['not_in_book'] ?? array() ), $text ) ) {
			$r['reason'] = self::reason( $f, 'not_in_book' );
			return $r;
		}
		foreach ( (array) ( $m['kinds'] ?? array() ) as $k ) {
			if ( isset( $k['kind'] ) && self::holds( $k['when'] ?? array(), $facts ) ) {
				$r['kind'] = (string) $k['kind'];
				break;
			}
		}

		// two choices named on one line: priced as each, for the estimate to offer as options
		foreach ( (array) ( $m['selectors'] ?? array() ) as $sel ) {
			$key = (string) ( $sel['key'] ?? '' );
			if ( empty( $sel['choice'] ) || count( $named[ $key ] ?? array() ) < 2 || ( ! empty( $sel['applies_when'] ) && ! self::holds( $sel['applies_when'], $facts ) ) ) {
				continue;
			}
			$opts  = array();
			$first = null;
			foreach ( array_slice( $named[ $key ], 0, 2 ) as $value ) {
				$x = self::price_variant( $f, array( $key => $value, 'named:' . $key => true ) + $facts, $w, $h, $vars, array( 'matched' => true ) + $r );
				$li = null !== $x['sku'] ? (string) ( self::item( $x['sku'] )['line_item'] ?? '' ) : '';
				$opts[] = array( 'value' => $value, 'sku' => $x['sku'], 'price' => $x['price'], 'double' => $x['double'], 'label' => $x['label'], 'line_item' => $li, 'assumed' => $x['assumed'], 'reason' => $x['reason'] );
				$first  = $first ?? $x;
			}
			$lead = array();
			foreach ( (array) ( $m['notes'] ?? array() ) as $n ) {
				if ( isset( $n['text'], $n['when']['choice'] ) && self::holds( $n['when'], array( 'choice' => true ) + $facts ) ) {
					$lead[] = strtr( (string) $n['text'], $vars );
				}
			}
			$r                 = $first;
			$r['both_choices'] = true;
			$r['choice_key']   = $key;
			$r['options']      = $opts;
			$r['assumed']      = array_merge( $lead, (array) $first['assumed'] );
			if ( null === $opts[0]['price'] || null === $opts[1]['price'] ) {
				$r['reason'] = self::reason( $f, 'choice_unpriced' );
			}
			return $r;
		}
		return self::price_variant( $f, $facts, $w, $h, $vars, $r );
	}

	/** The selected variant for these facts and this size, priced, with the notes that apply. */
	private static function price_variant( array $f, array $facts, $w, $h, array $vars, array $r ) {
		$m        = (array) $f['match'];
		$variants = self::items( $f['key'] );
		// a selector that does not apply to this line is not a constraint
		$sels = array();
		foreach ( (array) ( $m['selectors'] ?? array() ) as $sel ) {
			$key = (string) ( $sel['key'] ?? '' );
			if ( '' !== $key && ( empty( $sel['applies_when'] ) || self::holds( $sel['applies_when'], $facts ) ) ) {
				$sels[ $key ] = $sel;
			}
		}
		// a value no variant offers (with the other selected values) falls back along its chain
		foreach ( $sels as $key => $sel ) {
			$seen = array();
			while ( ! self::any_variant( $variants, $sels, $facts ) && isset( $sel['fallback'][ $facts[ $key ] ] ) && ! isset( $seen[ $facts[ $key ] ] ) ) {
				$seen[ $facts[ $key ] ] = true;
				$facts[ 'from:' . $key ] = $facts[ 'from:' . $key ] ?? $facts[ $key ];
				$facts[ $key ]           = (string) $sel['fallback'][ $facts[ $key ] ];
			}
		}
		// the size
		$swap = (array) ( $m['swap'] ?? array() );
		if ( null !== $w && null !== $h && $w > $h && isset( $swap['below_height'] ) && $h <= (float) $swap['below_height']
			&& ( empty( $swap['applies_when'] ) || self::holds( $swap['applies_when'], $facts ) ) ) {
			list( $w, $h )    = array( $h, $w );
			$facts['swapped'] = true;
		}
		$size = (array) ( $m['size'] ?? array() );
		if ( ( null === $w || null === $h ) && ! empty( $size['required_when'] ) && self::holds( $size['required_when'], $facts ) ) {
			$r['reason'] = self::reason( $f, 'size_needed' );
			return $r;
		}
		$single = (array) ( $m['single'] ?? array() );
		$sing_on = isset( $single['max_width'] ) && ( empty( $single['applies_when'] ) || self::holds( $single['applies_when'], $facts ) );
		if ( $sing_on && ! $facts['pair'] && null !== $w && $w > (float) $single['max_width'] + 1e-6
			&& ( ! isset( $single['max_pair_width'] ) || $w <= (float) $single['max_pair_width'] + 1e-6 ) ) {
			$facts['pair']        = true;
			$facts['forced_pair'] = true;
		}
		$leaf = ( null !== $w && $facts['pair'] ) ? $w / 2 : $w;
		if ( $sing_on && null !== $leaf && $leaf > (float) $single['max_width'] + 1e-6 ) {
			$vars['{w}']    = self::fmt_in( $w );
			$vars['{h}']    = self::fmt_in( $h );
			$vars['{leaf}'] = self::fmt_in( $leaf );
			$r['assumed']   = array_merge( $r['assumed'], self::notes_for( $m, $facts, $vars ) );
			$r['reason']    = self::reason( $f, 'too_wide', $vars + self::fact_vars( $facts, (array) ( $m['labels'] ?? array() ) ) );
			return $r;
		}
		if ( null === $leaf || null === $h ) {
			if ( isset( $size['default_width'], $size['default_height'] ) && ( empty( $size['applies_when'] ) || self::holds( $size['applies_when'], $facts ) ) ) {
				$leaf                  = null === $leaf ? (float) $size['default_width'] : $leaf;
				$h                     = null === $h ? (float) $size['default_height'] : $h;
				$facts['size_assumed'] = true;
			}
		}
		$vars['{w}']    = self::fmt_in( $w );
		$vars['{h}']    = self::fmt_in( $h );
		$vars['{leaf}'] = self::fmt_in( $leaf );
		$best             = self::fit( $variants, $sels, $facts, $leaf, $h, (array) ( $m['fit'] ?? array() ) );
		$facts['priced']  = $best && null !== ( $best[1]['price'] ?? null );
		$r['assumed']     = array_merge( $r['assumed'], self::notes_for( $m, $facts, $vars ) );
		$kind             = (string) $r['kind'];
		if ( ! $best ) {
			$r['reason'] = self::reason( $f, isset( $m['reasons'][ 'no_fit:' . $kind ] ) ? 'no_fit:' . $kind : 'no_fit', $vars + self::fact_vars( $facts, (array) ( $m['labels'] ?? array() ) ) );
			return $r;
		}
		list( $sku, $it ) = $best;
		$r['sku']     = $sku;
		$r['kind']    = (string) ( $it['kind'] ?? '' );
		$r['label']   = (string) ( $it['label'] ?? '' );
		$r['warning'] = (string) ( $it['warning'] ?? '' );
		if ( null === ( $it['price'] ?? null ) ) {
			// no list price: the family may word it per kind, and may fold the item's warning into it
			$k = (string) $r['kind'];
			if ( '' !== $r['warning'] && isset( $m['reasons'][ 'no_price_warning:' . $k ] ) ) {
				$r['reason']  = strtr( (string) $m['reasons'][ 'no_price_warning:' . $k ], array( '{warning}' => $r['warning'] ) );
				$r['warning'] = '';
			} else {
				$r['reason'] = self::reason( $f, isset( $m['reasons'][ 'no_price:' . $k ] ) ? 'no_price:' . $k : 'no_price' );
			}
			return $r;
		}
		$r['double'] = (bool) $facts['pair'];
		$doubled     = $facts['pair'] && self::holds( $m['pair']['doubled_when'] ?? array(), $facts ) && ! empty( $m['pair']['doubled_when'] );
		$r['price']  = $doubled ? (float) ( $it['double_price'] ?? 2 * (float) $it['price'] ) : (float) $it['price'];
		if ( $facts['forced_pair'] ) {
			$r['forced_pair'] = true;
		}
		return $r;
	}

	/** The notes whose condition holds (a split line's own notes are added where it is split). */
	private static function notes_for( array $m, array $facts, array $vars ) {
		$out = array();
		foreach ( (array) ( $m['notes'] ?? array() ) as $n ) {
			if ( isset( $n['text'] ) && ! isset( $n['when']['choice'] ) && self::holds( $n['when'] ?? array(), $facts ) ) {
				$out[] = strtr( (string) $n['text'], $vars );
			}
		}
		return $out;
	}

	/** Each selector's chosen value as a {key} placeholder for a reason's text, in the family's words for it
	 *  (`match.labels`: {key: {value: words}}) where it has them. */
	private static function fact_vars( array $facts, array $labels = array() ) {
		$out = array();
		foreach ( $facts as $k => $v ) {
			if ( is_string( $v ) && preg_match( '/^[a-z][a-z0-9_]*$/', (string) $k ) ) {
				$out[ '{' . $k . '}' ] = (string) ( $labels[ $k ][ $v ] ?? $v );
			}
		}
		return $out;
	}

	/** Does a variant fit these selected values? A variant without an attribute is not constrained by it. */
	private static function variant_fits( array $it, array $sels, array $facts ) {
		$a = (array) ( $it['attributes'] ?? array() );
		foreach ( $sels as $key => $sel ) {
			if ( array_key_exists( $key, $a ) && (string) $a[ $key ] !== (string) ( $facts[ $key ] ?? '' ) ) {
				return false;
			}
		}
		return null !== ( $it['dims']['max_width_in'] ?? null ) || null !== ( $it['dims']['max_height_in'] ?? null );
	}

	private static function any_variant( array $variants, array $sels, array $facts ) {
		foreach ( $variants as $it ) {
			if ( self::variant_fits( $it, $sels, $facts ) ) {
				return true;
			}
		}
		return false;
	}

	/** The smallest variant that covers the size, by the family's fit order: [sku, item] or null. */
	private static function fit( array $variants, array $sels, array $facts, $w, $h, array $fit ) {
		$order = (array) ( $fit['order'] ?? array( 'width', 'height' ) );
		foreach ( (array) ( $fit['by'] ?? array() ) as $rule ) {
			if ( isset( $rule['order'] ) && self::holds( $rule['when'] ?? array(), $facts ) ) {
				$order = (array) $rule['order'];
				break;
			}
		}
		$best = null;
		foreach ( $variants as $sku => $it ) {
			if ( ! self::variant_fits( $it, $sels, $facts ) ) {
				continue;
			}
			$mw = isset( $it['dims']['max_width_in'] ) ? (float) $it['dims']['max_width_in'] : INF;
			$mh = isset( $it['dims']['max_height_in'] ) ? (float) $it['dims']['max_height_in'] : INF;
			if ( ( null !== $w && $mw + 1e-6 < $w ) || ( null !== $h && $mh + 1e-6 < $h ) ) {
				continue;
			}
			$key = array();
			foreach ( $order as $o ) {
				$key[] = 'height' === $o ? $mh : $mw;
			}
			$key[] = (string) $sku;
			if ( null === $best || $key < $best[0] ) {
				$best = array( $key, $sku, $it );
			}
		}
		return $best ? array( $best[1], $best[2] ) : null;
	}

	/* =========================================================================================
	 * ENTITLEMENTS -- a business rule used once per customer (a free service earned by a purchase)
	 * ========================================================================================= */

	/**
	 * Create the entitlements table on first use; the option is stamped only after a clean run, so a half-applied
	 * migration retries. One use per qualifying purchase: UNIQUE (rule, client, qualifying_doc, live), and a voided row
	 * sets live = NULL, which never collides, so a voided use frees the purchase again.
	 */
	public static function ensure_tables() {
		static $ok = false;
		if ( $ok ) {
			return true;
		}
		if ( (int) get_option( self::SCHEMA_OPT, 0 ) >= self::SCHEMA_VERSION ) {
			$ok = true;
			return true;
		}
		global $wpdb;
		if ( ! $wpdb ) {
			return false;
		}
		$charset = $wpdb->get_charset_collate();
		$te      = $wpdb->prefix . self::ENT_TABLE;
		$wpdb->query(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$te} is $wpdb->prefix plus the class constant ENT_TABLE
			"CREATE TABLE IF NOT EXISTS `{$te}` (
				`id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				`rule_id` varchar(64) NOT NULL DEFAULT '',
				`client_id` varchar(32) NOT NULL DEFAULT '',
				`qualifying_doc` varchar(40) NOT NULL DEFAULT '',
				`qualifying_date` date NULL,
				`used_doc` varchar(40) NOT NULL DEFAULT '',
				`used_by` bigint(20) unsigned NOT NULL DEFAULT 0,
				`used_at` datetime NOT NULL,
				`book_version` varchar(32) NOT NULL DEFAULT '',
				`live` tinyint(1) NULL DEFAULT 1,
				`voided_at` datetime NULL,
				`voided_by` bigint(20) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY (`id`),
				UNIQUE KEY `uniq_live_use` (`rule_id`,`client_id`,`qualifying_doc`,`live`),
				KEY `idx_used_doc` (`used_doc`)
			) {$charset};" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$charset} is $wpdb->get_charset_collate()
		);
		if ( $wpdb->last_error ) {
			error_log( 'ZDZ_Price_Book::ensure_tables failed, schema not stamped: ' . $wpdb->last_error );
			return false;
		}
		update_option( self::SCHEMA_OPT, self::SCHEMA_VERSION, true );
		$ok = true;
		return true;
	}

	private static function ent_table() {
		global $wpdb;
		return $wpdb->prefix . self::ENT_TABLE;
	}

	/** The live use of $rule for $client earned by purchase $qualifying_doc, or null. */
	public static function entitlement_use( $rule_id, $client_id, $qualifying_doc ) {
		if ( '' === (string) $client_id || '' === (string) $qualifying_doc || ! self::ensure_tables() ) {
			return null;
		}
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare(
			'SELECT * FROM ' . self::ent_table() . ' WHERE rule_id = %s AND client_id = %s AND qualifying_doc = %s AND live = 1 ORDER BY id ASC LIMIT 1', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- self::ent_table() is $wpdb->prefix plus the class constant ENT_TABLE
			(string) $rule_id, (string) $client_id, (string) $qualifying_doc
		), ARRAY_A );
		// a reservation never confirmed (the request died before the write) is given back after RESERVATION_TTL, so it
		// cannot block that purchase's entitlement for good
		if ( $row && '' === (string) $row['used_doc'] && strtotime( (string) $row['used_at'] . ' UTC' ) < time() - self::RESERVATION_TTL ) {
			self::release_entitlement( (int) $row['id'] );
			return null;
		}
		return $row ?: null;
	}

	/** Reserve a use (used_doc still empty). 0 when it is already taken. */
	public static function reserve_entitlement( $rule_id, $client_id, $qualifying_doc, $qualifying_date, $uid ) {
		if ( '' === (string) $client_id || '' === (string) $qualifying_doc || ! self::ensure_tables() ) {
			return 0;
		}
		if ( self::entitlement_use( $rule_id, $client_id, $qualifying_doc ) ) {
			return 0;
		}
		global $wpdb;
		$ok = $wpdb->insert( self::ent_table(), array(
			'rule_id'         => substr( (string) $rule_id, 0, 64 ),
			'client_id'       => substr( (string) $client_id, 0, 32 ),
			'qualifying_doc'  => substr( (string) $qualifying_doc, 0, 40 ),
			'qualifying_date' => preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $qualifying_date ) ? (string) $qualifying_date : null,
			'used_doc'        => '',
			'used_by'         => (int) $uid,
			'used_at'         => current_time( 'mysql', true ),
			'book_version'    => substr( self::version(), 0, 32 ),
			'live'            => 1,
		) );
		return $ok ? (int) $wpdb->insert_id : 0;   // a duplicate-key failure means someone else holds it
	}

	/** Attach the document to a reserved use. */
	public static function confirm_entitlement( $id, $used_doc ) {
		$id = (int) $id;
		if ( $id <= 0 || '' === (string) $used_doc || ! self::ensure_tables() ) {
			return false;
		}
		global $wpdb;
		$n   = $wpdb->update( self::ent_table(), array( 'used_doc' => substr( (string) $used_doc, 0, 40 ) ), array( 'id' => $id, 'live' => 1 ) );
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::ent_table() . ' WHERE id = %d', $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- self::ent_table() is $wpdb->prefix plus the class constant ENT_TABLE
		if ( $row ) {
			self::audit( 'price_book_entitlement', "{$row['rule_id']} used for client {$row['client_id']} on {$used_doc} (qualifying document {$row['qualifying_doc']} {$row['qualifying_date']})" );
		}
		return (bool) $n;
	}

	/** Give a use back (the write failed, or an administrator voided it). */
	public static function release_entitlement( $id, $uid = 0, $why = 'released' ) {
		$id = (int) $id;
		if ( $id <= 0 || ! self::ensure_tables() ) {
			return false;
		}
		global $wpdb;
		$n = $wpdb->update( self::ent_table(), array( 'live' => null, 'voided_at' => current_time( 'mysql', true ), 'voided_by' => (int) $uid ), array( 'id' => $id, 'live' => 1 ) );
		if ( $n && 'released' !== $why ) {
			self::audit( 'price_book_entitlement_void', "Use #{$id} voided: {$why}" );
		}
		return (bool) $n;
	}

	/** Recent uses, newest first (admin screen). */
	public static function entitlement_uses( $limit = 50 ) {
		if ( ! self::ensure_tables() ) {
			return array();
		}
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::ent_table() . ' ORDER BY id DESC LIMIT %d', max( 1, min( 500, (int) $limit ) ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- self::ent_table() is $wpdb->prefix plus the class constant ENT_TABLE
		return is_array( $rows ) ? $rows : array();
	}

	/* =========================================================================================
	 * VALIDATION AND COMPARISON
	 * ========================================================================================= */

	/** Below this margin a priced item is flagged for review. A business may move it; never below zero. */
	public static function thin_margin() {
		$t = function_exists( 'apply_filters' ) ? apply_filters( 'zdz_price_book_thin_margin', 0.25 ) : 0.25;
		return max( 0.0, min( 0.95, (float) $t ) );
	}

	/**
	 * Validate one family payload: array( 'family' => [...], 'variants' => [...] ) with each variant's price and cost
	 * already joined (pack_book() does this). Returns [errors[], warnings[], clean payload|null]. Every rule is enforced
	 * here, server-side, whoever built the payload.
	 */
	public static function validate_family( $payload ) {
		$errors   = array();
		$warnings = array();
		if ( ! is_array( $payload ) ) {
			return array( array( 'The family is not a price-book family (expected an object).' ), array(), null );
		}
		$fam = (array) ( $payload['family'] ?? array() );
		$key = (string) ( $fam['key'] ?? '' );
		if ( ! preg_match( '/^[a-z][a-z0-9_]{1,31}$/', $key ) ) {
			$errors[] = 'The family key must be 2-32 lowercase letters, digits or underscores.';
		}
		$items = $payload['variants'] ?? null;
		if ( ! is_array( $items ) || ! $items ) {
			$errors[] = "Family '{$key}' has no items.";
			$items    = array();
		}
		if ( count( $items ) > self::MAX_ITEMS ) {
			$errors[] = 'Too many items (' . count( $items ) . ' > ' . self::MAX_ITEMS . ').';
		}
		$seen  = array();
		$clean = array();
		foreach ( $items as $i => $it ) {
			$n = '#' . ( (int) $i + 1 );
			if ( ! is_array( $it ) ) {
				$errors[] = "Item {$n} is not an object.";
				continue;
			}
			$sku = (string) ( $it['sku'] ?? '' );
			if ( ! preg_match( '/^[A-Z0-9][A-Z0-9\-]{1,63}$/', $sku ) ) {
				$errors[] = "Item {$n}: bad SKU '" . self::clean_text( $sku, 70 ) . "'.";
				continue;
			}
			if ( isset( $seen[ $sku ] ) ) {
				$errors[] = "Duplicate SKU {$sku}.";
				continue;
			}
			$seen[ $sku ] = true;
			$kind = (string) ( $it['kind'] ?? '' );
			if ( ! preg_match( '/^[a-z][a-z0-9_]{0,23}$/', $kind ) ) {
				$errors[] = "{$sku}: kind must be a short lower-case word.";
				continue;
			}
			$cost = $it['cost'] ?? null;
			if ( null !== $cost && ( ! is_numeric( $cost ) || (float) $cost < 0 || (float) $cost > self::MAX_MONEY ) ) {
				$errors[] = "{$sku}: cost must be empty or a number from 0 to " . self::MAX_MONEY . '.';
				continue;
			}
			$price = $it['price'] ?? null;
			if ( null !== $price && ( ! is_numeric( $price ) || (float) $price < 0 || (float) $price > self::MAX_MONEY ) ) {
				$errors[] = "{$sku}: price must be empty or a number from 0 to " . self::MAX_MONEY . '.';
				continue;
			}
			$dbl = $it['double_price'] ?? null;
			if ( null !== $dbl && ( ! is_numeric( $dbl ) || (float) $dbl < 0 || (float) $dbl > self::MAX_MONEY ) ) {
				$errors[] = "{$sku}: double price must be empty or a number.";
				continue;
			}
			$dims = array();
			foreach ( (array) ( $it['dims'] ?? array() ) as $dk => $dv ) {
				if ( ! preg_match( '/^[a-z_]{1,32}$/', (string) $dk ) ) {
					continue;
				}
				if ( is_int( $dv ) || is_float( $dv ) ) {
					$dims[ $dk ] = $dv;
				} elseif ( is_string( $dv ) ) {
					$dims[ $dk ] = self::clean_text( $dv, 120 );
				} elseif ( is_bool( $dv ) ) {
					$dims[ $dk ] = $dv;
				}
			}
			foreach ( array( 'max_width_in', 'max_height_in' ) as $dk ) {
				if ( isset( $dims[ $dk ] ) && ( ! is_numeric( $dims[ $dk ] ) || (float) $dims[ $dk ] <= 0 ) ) {
					$errors[] = "{$sku}: {$dk} must be a positive number.";
					continue 2;
				}
			}
			$attrs = array();
			foreach ( (array) ( $it['attributes'] ?? array() ) as $ak => $av ) {
				if ( preg_match( '/^[a-z_]{1,32}$/', (string) $ak ) && is_scalar( $av ) ) {
					$attrs[ $ak ] = self::clean_text( (string) $av, 60 );
				}
			}
			$src = array();
			foreach ( (array) ( $it['source'] ?? array() ) as $sk => $sv ) {
				if ( preg_match( '/^[a-z_]{1,32}$/', (string) $sk ) && is_scalar( $sv ) ) {
					$src[ $sk ] = self::clean_text( (string) $sv, 120 );
				}
			}
			$row = array(
				'sku'          => $sku,
				'kind'         => $kind,
				'brand'        => self::clean_text( (string) ( $it['brand'] ?? '' ), 60 ),
				'model'        => self::clean_text( (string) ( $it['model'] ?? '' ), 60 ),
				'label'        => self::clean_text( (string) ( $it['label'] ?? '' ), 300 ),
				'line_item'    => self::clean_text( (string) ( $it['line_item'] ?? '' ), 120 ),
				'dims'         => $dims,
				'attributes'   => $attrs,
				'uom'          => self::clean_text( (string) ( $it['uom'] ?? 'each' ), 20 ),
				'price'        => null === $price ? null : round( (float) $price, 2 ),
				'double_price' => null === $dbl ? null : round( (float) $dbl, 2 ),
				'price_basis'  => self::clean_text( (string) ( $it['price_basis'] ?? '' ), 120 ),
				'warning'      => self::clean_text( (string) ( $it['warning'] ?? '' ), 300 ),
				'cost'         => null === $cost ? null : round( (float) $cost, 2 ),
				'source'       => $src,
			);
			if ( null !== $row['price'] && $row['price'] <= 0 ) {
				$warnings[] = "{$sku}: customer price is 0.";
			}
			if ( null !== $row['price'] && $row['price'] > 0 && null !== $row['cost'] ) {
				if ( $row['price'] < $row['cost'] ) {
					$warnings[] = "{$sku}: price is below cost.";
				} elseif ( ( $row['price'] - $row['cost'] ) / $row['price'] < self::thin_margin() ) {
					$warnings[] = "{$sku}: thin margin.";
				}
			}
			$clean[] = $row;
		}
		usort( $clean, function ( $a, $b ) { return strcmp( $a['sku'], $b['sku'] ); } );
		$cfam = array(
			'key'         => $key,
			'label'       => self::clean_text( (string) ( $fam['label'] ?? $key ), 120 ),
			'brand'       => self::clean_text( (string) ( $fam['brand'] ?? '' ), 60 ),
			'aliases'     => array_values( array_filter( array_map( function ( $a ) { return self::clean_text( (string) $a, 80 ); }, (array) ( $fam['aliases'] ?? array() ) ) ) ),
			'decided_by'  => self::clean_text( (string) ( $fam['decided_by'] ?? '' ), 120 ),
			'prompt'      => self::clean_block( (string) ( $fam['prompt'] ?? '' ), 6000 ),
			'match'       => is_array( $fam['match'] ?? null ) ? self::clean_tree( $fam['match'] ) : array(),
			'source'      => array_map( function ( $v ) { return is_scalar( $v ) ? self::clean_text( (string) $v, 200 ) : ''; }, (array) ( $fam['source'] ?? array() ) ),
			'price_rules' => array(),
			'rules'       => array(),
		);
		foreach ( (array) ( $fam['price_rules'] ?? array() ) as $k => $v ) {
			if ( is_scalar( $v ) ) {
				$cfam['price_rules'][ self::clean_text( (string) $k, 120 ) ] = self::clean_text( (string) $v, 200 );
			}
		}
		foreach ( (array) ( $fam['rules'] ?? array() ) as $r ) {
			if ( ! is_array( $r ) || ! preg_match( '/^[a-z0-9\-]{3,64}$/', (string) ( $r['id'] ?? '' ) ) ) {
				continue;
			}
			$cfam['rules'][] = array(
				'id'              => (string) $r['id'],
				'kind'            => self::clean_text( (string) ( $r['kind'] ?? '' ), 30 ),
				'applies_to_kind' => self::clean_text( (string) ( $r['applies_to_kind'] ?? '' ), 30 ),
				'text'            => self::clean_text( (string) ( $r['text'] ?? '' ), 300 ),
				'price'           => is_numeric( $r['price'] ?? null ) ? (float) $r['price'] : null,
				'window_days'     => max( 0, min( 3650, (int) ( $r['window_days'] ?? 0 ) ) ),
				'max_uses'        => max( 0, min( 100, (int) ( $r['max_uses'] ?? 0 ) ) ),
				'qualifying'      => self::clean_text( (string) ( $r['qualifying'] ?? '' ), 200 ),
				// what an earlier purchase must come to (and of which kinds) to earn the rule, the words its line carries,
				// and the item priced when the rule's own line can't be
				'qualifying_min'   => is_numeric( $r['qualifying_min'] ?? null ) ? max( 0.0, (float) $r['qualifying_min'] ) : null,
				'qualifying_kinds' => array_values( array_filter( array_map( static function ( $k ) {
					return is_scalar( $k ) ? self::clean_text( (string) $k, 30 ) : '';
				}, (array) ( $r['qualifying_kinds'] ?? array() ) ), 'strlen' ) ),
				'line_text'        => self::clean_text( (string) ( $r['line_text'] ?? '' ), 120 ),
				'fallback_sku'     => preg_match( '/^[A-Z0-9][A-Z0-9\-]{1,63}$/', (string) ( $r['fallback_sku'] ?? '' ) ) ? (string) $r['fallback_sku'] : '',
			);
		}
		return array( $errors, $warnings, $errors ? null : array( 'family' => $cfam, 'variants' => $clean ) );
	}

	/**
	 * What would change from book $from to book $to (each: array( 'items' => [sku => item with cost] )).
	 * @return array added[], removed[], price[], cost[], counts{}
	 */
	public static function diff( $from, array $to ) {
		$a   = (array) ( $from['items'] ?? array() );
		$b   = (array) ( $to['items'] ?? array() );
		$out = array( 'added' => array(), 'removed' => array(), 'price' => array(), 'cost' => array() );
		foreach ( $b as $sku => $it ) {
			if ( ! isset( $a[ $sku ] ) ) {
				$out['added'][] = array( 'sku' => $sku, 'label' => $it['label'] ?? '', 'new_price' => $it['price'] ?? null );
				continue;
			}
			$o = $a[ $sku ];
			if ( self::money_or_null( $o['price'] ?? null ) !== self::money_or_null( $it['price'] ?? null ) || self::money_or_null( $o['double_price'] ?? null ) !== self::money_or_null( $it['double_price'] ?? null ) ) {
				$out['price'][] = array( 'sku' => $sku, 'label' => $it['label'] ?? '', 'old' => $o['price'] ?? null, 'new' => $it['price'] ?? null );
			}
			if ( self::money_or_null( $o['cost'] ?? null ) !== self::money_or_null( $it['cost'] ?? null ) ) {
				$out['cost'][] = array( 'sku' => $sku, 'label' => $it['label'] ?? '' );   // which, never the amounts
			}
		}
		foreach ( $a as $sku => $it ) {
			if ( ! isset( $b[ $sku ] ) ) {
				$out['removed'][] = array( 'sku' => $sku, 'label' => $it['label'] ?? '', 'old_price' => $it['price'] ?? null );
			}
		}
		$out['counts'] = array_map( 'count', $out );
		return $out;
	}

	/* =========================================================================================
	 * THE IDENTITY PACK'S PART -- catalog, pricing and costs files, `price_book` block of each
	 * ========================================================================================= */

	/** Registers the price book with the Identity Pack (preview, apply, snapshot, restore). */
	public static function pack_consumer( $consumers ) {
		$consumers = is_array( $consumers ) ? $consumers : array();
		$consumers['price-book'] = array(
			'label'    => __( 'Price book', 'zorderz' ),
			'files'    => array( 'catalog', 'pricing', 'costs' ),
			'paths'    => array( 'catalog' => array( 'price_book' ), 'pricing' => array( 'price_book' ), 'costs' => array( 'price_book' ) ),
			'preview'  => array( __CLASS__, 'pack_preview' ),
			'apply'    => array( __CLASS__, 'pack_apply' ),
			'snapshot' => array( __CLASS__, 'pack_snapshot' ),
			'restore'  => array( __CLASS__, 'pack_restore' ),
		);
		return $consumers;
	}

	/**
	 * The book a pack carries, joined from its three files:
	 *   catalog.yml  price_book: { families: [ {key, label, brand, aliases, match, prompt, decided_by} ],
	 *                              variants: [ {sku, family, kind, brand, model, label, line_item, dims, attributes,
	 *                                           uom, warning} ] }
	 *   pricing.yml  price_book: { version, families: [ {key, price_rules, rules} ],
	 *                              prices: [ {sku, price, double_price, basis} ] }
	 *   costs.yml    price_book: { families: [ {key, source} ], costs: [ {sku, cost, source} ] }
	 * Returns array( errors[], warnings[], families => [key => clean payload], version ), or null when no file has a
	 * price_book block. A variant with no price row is "quote by hand"; a price or cost row naming no variant is an
	 * error, so a typing slip never prices the wrong thing.
	 */
	public static function pack_book( array $files ) {
		$cat = (array) ( $files['catalog']['price_book'] ?? array() );
		$pri = (array) ( $files['pricing']['price_book'] ?? array() );
		$cos = (array) ( $files['costs']['price_book'] ?? array() );
		if ( ! $cat && ! $pri && ! $cos ) {
			return null;
		}
		$errors   = array();
		$warnings = array();
		$fams     = array();
		foreach ( (array) ( $cat['families'] ?? array() ) as $f ) {
			if ( is_array( $f ) && isset( $f['key'] ) ) {
				$fams[ (string) $f['key'] ] = array( 'family' => $f, 'variants' => array() );
			}
		}
		foreach ( (array) ( $pri['families'] ?? array() ) as $f ) {
			$k = (string) ( $f['key'] ?? '' );
			if ( ! isset( $fams[ $k ] ) ) {
				$errors[] = "pricing: family '{$k}' is not in the catalog.";
				continue;
			}
			$fams[ $k ]['family']['price_rules'] = $f['price_rules'] ?? array();
			$fams[ $k ]['family']['rules']       = $f['rules'] ?? array();
		}
		foreach ( (array) ( $cos['families'] ?? array() ) as $f ) {
			$k = (string) ( $f['key'] ?? '' );
			if ( isset( $fams[ $k ] ) ) {
				$fams[ $k ]['family']['source'] = (array) ( $f['source'] ?? array() );
			}
		}
		$where = array();
		foreach ( (array) ( $cat['variants'] ?? array() ) as $v ) {
			$k = (string) ( $v['family'] ?? '' );
			if ( ! isset( $fams[ $k ] ) ) {
				$errors[] = "catalog: variant '" . self::clean_text( (string) ( $v['sku'] ?? '' ), 70 ) . "' names no family in the catalog.";
				continue;
			}
			$where[ (string) ( $v['sku'] ?? '' ) ] = $k;
			$fams[ $k ]['variants'][ (string) ( $v['sku'] ?? '' ) ] = (array) $v;
		}
		foreach ( (array) ( $pri['prices'] ?? array() ) as $p ) {
			$sku = (string) ( $p['sku'] ?? '' );
			if ( ! isset( $where[ $sku ] ) ) {
				$errors[] = "pricing: price for '" . self::clean_text( $sku, 70 ) . "', which is not in the catalog.";
				continue;
			}
			$fams[ $where[ $sku ] ]['variants'][ $sku ]['price']        = $p['price'] ?? null;
			$fams[ $where[ $sku ] ]['variants'][ $sku ]['double_price'] = $p['double_price'] ?? null;
			$fams[ $where[ $sku ] ]['variants'][ $sku ]['price_basis']  = $p['basis'] ?? '';
		}
		foreach ( (array) ( $cos['costs'] ?? array() ) as $c ) {
			$sku = (string) ( $c['sku'] ?? '' );
			if ( ! isset( $where[ $sku ] ) ) {
				$errors[] = "costs: cost for '" . self::clean_text( $sku, 70 ) . "', which is not in the catalog.";
				continue;
			}
			$fams[ $where[ $sku ] ]['variants'][ $sku ]['cost']   = $c['cost'] ?? null;
			$fams[ $where[ $sku ] ]['variants'][ $sku ]['source'] = (array) ( $c['source'] ?? array() );
		}
		$out = array();
		foreach ( $fams as $k => $payload ) {
			$payload['variants'] = array_values( $payload['variants'] );
			list( $e, $w, $clean ) = self::validate_family( $payload );
			$errors   = array_merge( $errors, $e );
			$warnings = array_merge( $warnings, $w );
			if ( $clean ) {
				$out[ $k ] = $clean;
			}
		}
		return array(
			'errors'   => $errors,
			'warnings' => $warnings,
			'families' => $out,
			'version'  => self::clean_text( (string) ( $pri['version'] ?? '' ), 32 ),
		);
	}

	/** The live book with costs, for comparison (admin side only; never sent anywhere). */
	private static function live_with_costs() {
		$b = self::published();
		if ( ! $b ) {
			return null;
		}
		$c     = get_option( self::COSTS_OPT, array() );
		$items = array();
		foreach ( $b['items'] as $sku => $it ) {
			$it['cost']    = is_array( $c ) ? ( $c['items'][ $sku ]['cost'] ?? null ) : null;
			$items[ $sku ] = $it;
		}
		return array( 'items' => $items );
	}

	public static function pack_preview( array $files ) {
		$book = self::pack_book( $files );
		if ( null === $book ) {
			return array();
		}
		$rows = array();
		foreach ( array_slice( $book['errors'], 0, 10 ) as $e ) {
			$rows[] = array( 'file' => 'pricing', 'path' => 'price_book.error', 'from' => '', 'to' => 'REFUSED: ' . $e );
		}
		if ( $book['errors'] ) {
			return $rows;
		}
		$to = array( 'items' => array() );
		foreach ( $book['families'] as $k => $p ) {
			foreach ( $p['variants'] as $v ) {
				$to['items'][ $v['sku'] ] = $v;
			}
		}
		$from = self::live_with_costs();
		$d    = self::diff( $from, $to );
		$rows[] = array( 'file' => 'catalog', 'path' => 'price_book.families', 'from' => $from ? implode( ', ', array_keys( (array) self::published()['families'] ) ) : '(no price book)', 'to' => implode( ', ', array_keys( $book['families'] ) ) );
		$rows[] = array( 'file' => 'catalog', 'path' => 'price_book.items', 'from' => (string) count( (array) ( $from['items'] ?? array() ) ), 'to' => count( $to['items'] ) . ' (' . $d['counts']['added'] . ' new, ' . $d['counts']['removed'] . ' removed)' );
		$rows[] = array( 'file' => 'pricing', 'path' => 'price_book.prices', 'from' => '', 'to' => $d['counts']['price'] . ' price change(s)' );
		$rows[] = array( 'file' => 'costs', 'path' => 'price_book.costs', 'from' => '', 'to' => $d['counts']['cost'] . ' cost change(s) (amounts on Zorderz -> Price Book)' );
		if ( $book['warnings'] ) {
			$rows[] = array( 'file' => 'pricing', 'path' => 'price_book.warnings', 'from' => '', 'to' => count( $book['warnings'] ) . ' to review: ' . implode( '; ', array_slice( $book['warnings'], 0, 5 ) ) );
		}
		return $rows;
	}

	public static function pack_apply( array $files, $slug = '' ) {
		$book = self::pack_book( $files );
		if ( null === $book ) {
			return array( 'price book: nothing in the pack, left as it was.' );
		}
		if ( $book['errors'] ) {
			return array( 'price book NOT applied: ' . implode( ' ', array_slice( $book['errors'], 0, 5 ) ) );
		}
		$costs = get_option( self::COSTS_OPT, array() );
		$costs = is_array( $costs ) ? $costs : array();
		$costs += array( 'families' => array(), 'items' => array() );
		$done  = 0;
		foreach ( $book['families'] as $key => $payload ) {
			$err = self::write_family( $payload );
			if ( '' !== $err ) {
				return array( "price book family '{$key}' NOT applied: {$err}" );
			}
			// this family's costs replace its old ones; other families' stay
			foreach ( $costs['items'] as $sku => $c ) {
				if ( ( $c['family'] ?? '' ) === $key ) {
					unset( $costs['items'][ $sku ] );
				}
			}
			foreach ( $payload['variants'] as $v ) {
				if ( null !== $v['cost'] ) {
					$costs['items'][ $v['sku'] ] = array( 'cost' => $v['cost'], 'family' => $key, 'source' => $v['source'] );
				}
			}
			$costs['families'][ $key ] = $payload['family']['source'];
			$done                     += count( $payload['variants'] );
		}
		update_option( self::COSTS_OPT, $costs, false );
		update_option( self::VERSION_OPT, array(
			'label'      => '' !== $book['version'] ? $book['version'] : gmdate( 'Y-m-d' ),
			'pack'       => (string) $slug,
			'applied_at' => gmdate( 'c' ),
			'by'         => function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0,
		), false );
		self::flush();
		self::audit( 'price_book_pack', 'Price book applied from pack ' . $slug . ': ' . implode( ', ', array_keys( $book['families'] ) ) . " ({$done} items)" );
		$msg = array( sprintf( 'Price book applied: %d famil%s, %d items.', count( $book['families'] ), 1 === count( $book['families'] ) ? 'y' : 'ies', $done ) );
		if ( $book['warnings'] ) {
			$msg[] = count( $book['warnings'] ) . ' price-book item(s) to review on Zorderz -> Price Book.';
		}
		return $msg;
	}

	/** Write one family into the Item Engine: its item and its grid scheme. '' or the error. */
	private static function write_family( array $payload ) {
		if ( ! class_exists( 'ZDZ_Item_Engine' ) ) {
			return 'the Item Engine is not loaded';
		}
		$fam      = $payload['family'];
		$key      = $fam['key'];
		$scheme   = self::SCHEME_PREFIX . str_replace( '_', '-', $key );
		$variants = array();
		foreach ( $payload['variants'] as $v ) {
			unset( $v['cost'], $v['source'] );   // costs are never written into the catalog
			$variants[] = $v;
		}
		$r = ZDZ_Item_Engine::save_scheme( array(
			'id'     => $scheme,
			'name'   => $fam['label'],
			'method' => 'grid',
			'scope'  => 'item',
			'params' => array( 'family' => $key, 'price_rules' => $fam['price_rules'], 'rules' => $fam['rules'], 'variants' => $variants ),
		) );
		if ( is_wp_error( $r ) ) {
			return $r->get_error_message();
		}
		$r = ZDZ_Item_Engine::save_item( array(
			'id'                => self::item_id( $key ),
			'type'              => 'product',
			'display_name'      => $fam['label'],
			'aliases'           => $fam['aliases'],
			'sellable'          => 'standalone',
			'pricing_scheme_id' => $scheme,
			'attributes'        => array(
				self::FAMILY_ATTR   => $key,
				'brand'             => $fam['brand'],
				'price_book_match'  => $fam['match'],
				'price_book_prompt' => $fam['prompt'],
				'decided_by'        => $fam['decided_by'],
			),
		) );
		return is_wp_error( $r ) ? $r->get_error_message() : '';
	}

	/** The Item Engine id of a family's item. */
	public static function item_id( $key ) {
		return str_replace( '_', '-', (string) $key );
	}

	/** Before a pack is applied: the families it would replace (item and scheme), the costs and the version. */
	public static function pack_snapshot() {
		$fam = array();
		if ( class_exists( 'ZDZ_Item_Engine' ) ) {
			foreach ( (array) ZDZ_Item_Engine::all( array( 'active' => true ) ) + (array) ZDZ_Item_Engine::all( array( 'active' => false ) ) as $id => $it ) {
				$key = (string) ( $it['attributes'][ self::FAMILY_ATTR ] ?? '' );
				if ( '' !== $key ) {
					$fam[ $key ] = array( 'item' => $it, 'scheme' => ZDZ_Item_Engine::pricing_scheme( $it['pricing_scheme_id'] ) );
				}
			}
		}
		$snap = array(
			'families' => $fam,
			'costs'    => get_option( self::COSTS_OPT, null ),
			'version'  => get_option( self::VERSION_OPT, null ),
		);
		update_option( self::SNAPSHOT_OPT, $snap, false );   // kept out of the pack's own snapshot (it can be large)
		return array( 'kept' => self::SNAPSHOT_OPT, 'families' => array_keys( $fam ) );
	}

	/** Revert: families the pack added are removed, families it replaced come back, costs and version as they were. */
	public static function pack_restore( $kept ) {
		$snap = get_option( self::SNAPSHOT_OPT, null );
		if ( ! is_array( $snap ) || ! class_exists( 'ZDZ_Item_Engine' ) ) {
			return;
		}
		$before = (array) ( $snap['families'] ?? array() );
		foreach ( (array) ZDZ_Item_Engine::all( array( 'active' => true ) ) + (array) ZDZ_Item_Engine::all( array( 'active' => false ) ) as $id => $it ) {
			$key = (string) ( $it['attributes'][ self::FAMILY_ATTR ] ?? '' );
			if ( '' !== $key && ! isset( $before[ $key ] ) ) {
				ZDZ_Item_Engine::delete_item( $id );
				if ( '' !== (string) $it['pricing_scheme_id'] ) {
					ZDZ_Item_Engine::delete_scheme( $it['pricing_scheme_id'] );
				}
			}
		}
		foreach ( $before as $key => $b ) {
			if ( is_array( $b['scheme'] ?? null ) ) {
				ZDZ_Item_Engine::save_scheme( $b['scheme'] );
			}
			if ( is_array( $b['item'] ?? null ) ) {
				ZDZ_Item_Engine::save_item( $b['item'] );
			}
		}
		foreach ( array( self::COSTS_OPT => 'costs', self::VERSION_OPT => 'version' ) as $opt => $k ) {
			if ( is_array( $snap[ $k ] ?? null ) ) {
				update_option( $opt, $snap[ $k ], false );
			} else {
				delete_option( $opt );
			}
		}
		delete_option( self::SNAPSHOT_OPT );
		self::flush();
	}

	/* =========================================================================================
	 * HELPERS
	 * ========================================================================================= */

	/** Money compared to the cent, so 900, 900.0 and "900.00" are the same price. */
	private static function money_or_null( $v ) {
		return ( null === $v || '' === $v ) ? null : number_format( (float) $v, 2, '.', '' );
	}

	public static function clean_text( $s, $max = 500 ) {
		$s = trim( (string) ( preg_replace( '/[\x00-\x1F\x7F]+/u', ' ', (string) $s ) ?? '' ) );
		return function_exists( 'mb_substr' ) ? mb_substr( $s, 0, (int) $max ) : substr( $s, 0, (int) $max );
	}

	/** Text that keeps its line breaks (a prompt block). */
	private static function clean_block( $s, $max ) {
		$s = (string) preg_replace( '/[\x00-\x09\x0B-\x1F\x7F]+/u', ' ', str_replace( "\r", '', (string) $s ) );
		return function_exists( 'mb_substr' ) ? mb_substr( trim( $s ), 0, (int) $max ) : substr( trim( $s ), 0, (int) $max );
	}

	/** A matching block: keys and strings cleaned, depth and size bounded. */
	private static function clean_tree( $v, $depth = 0 ) {
		if ( $depth > 6 ) {
			return array();
		}
		if ( is_array( $v ) ) {
			$out = array();
			foreach ( array_slice( $v, 0, 400, true ) as $k => $x ) {
				$out[ is_int( $k ) ? $k : self::clean_text( (string) $k, 60 ) ] = self::clean_tree( $x, $depth + 1 );
			}
			return $out;
		}
		if ( is_string( $v ) ) {
			return self::clean_text( $v, 600 );
		}
		return is_scalar( $v ) || null === $v ? $v : '';
	}

	private static function audit( $type, $detail ) {
		if ( class_exists( 'ZDZ_Admin_Dashboard' ) && method_exists( 'ZDZ_Admin_Dashboard', 'log_action' ) && function_exists( 'get_current_user_id' ) ) {
			try {
				ZDZ_Admin_Dashboard::log_action( (int) get_current_user_id(), $type, $detail, 'price-book' );
			} catch ( \Throwable $e ) {
				unset( $e );   // logging never blocks a price change
			}
		}
	}
}
