<?php
/**
 * ZEST_Price_Book_Lines - an estimate's lines priced from the business's price book (ZDZ_Price_Book).
 *
 *   price()        every line of a price book family (ZDZ_Price_Book::is_family_line / match_line: the line's name and
 *                  sub-line and its size, never its notes) gets its unit price from the published book, replacing the
 *                  model's. What the book assumed, could not price, or changed from the price in the notes rides on the
 *                  line as `pb` (internal) and comes back as a review flag, never on the customer's words. A line the book
 *                  cannot price keeps its price. Metadata lines, discounts, credits and the tax line are never priced.
 *                  An opening the book priced as a pair gets the family's pair word in front (match.pair.words, so
 *                  nobody orders one); a pair the book prices one at a time becomes a quantity of two.
 *   options        a line naming two choices of a split selector (match_line both_choices) becomes two optional lines,
 *                  each with the choice's price and the option wording (document conventions estimate.option_tail, a
 *                  neutral default): the total counts both until one is removed; removing one takes the wording off the
 *                  other (clean_lone_option, used by the line edits and the preview), and Approve refuses while any line
 *                  still carries it (has_options).
 *   entitlements   a family rule of kind "entitlement" (a free or reduced service earned by an earlier purchase:
 *                  { id, kind: entitlement, applies_to_kind, price, window_days, qualifying_min, qualifying_kinds?,
 *                  line_text?, fallback_sku? }, from the pack): when the customer's billing client is known, an invoice
 *                  to that client in the rule's window whose family lines of a qualifying kind come to qualifying_min or
 *                  more (net of credits) earns ONE line of the rule's kind at the rule's price, reserved in the price
 *                  book before the billing system is written (two estimates at once cannot both get it), confirmed after,
 *                  released if the write fails. A free line already on an estimate is kept only when the book holds its
 *                  use for THIS estimate; otherwise it goes back to the book price, or the rule's fallback_sku price, or
 *                  stays unpriced with a flag (a $0 line among priced ones is then refused at create, never sent).
 *                  Anything uncertain leaves the normal price on.
 *   prompt_block() each family's prices for the parse model (prices only, never a cost).
 *
 * No product, SKU, rule id or price in this file: every one comes from the published book. Without a book, nothing here
 * changes a line.
 *
 * @package Zorderz\Estimate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZEST_Price_Book_Lines {

	const OPTION_TAIL = 'OPTION %1$d of 2 (%2$s). Choose one: the total counts both options, and the one not chosen comes off.';
	const FREE_TEXT   = 'Included at no charge';

	public static function book(): bool {
		return class_exists( 'ZDZ_Price_Book' ) && method_exists( 'ZDZ_Price_Book', 'published' ) && null !== ZDZ_Price_Book::published();
	}

	/** The families this app prices from: every family in the published book (filter zest_price_book_families narrows). */
	public static function families(): array {
		if ( ! self::book() ) {
			return array();
		}
		$keys = array_keys( (array) ( ZDZ_Price_Book::published()['families'] ?? array() ) );
		$keys = (array) apply_filters( 'zest_price_book_families', $keys );
		return array_values( array_filter( array_map( 'strval', $keys ), 'strlen' ) );
	}

	/** The family a line belongs to, or ''. */
	public static function family_of( array $li ): string {
		foreach ( self::families() as $f ) { // phpcs:ignore PHPCompatibility.Extensions.RemovedExtensions.famRemoved -- self::families() is a method of this class, not the removed fam extension
			if ( ZDZ_Price_Book::is_family_line( $f, (string) ( $li['description'] ?? '' ), (string) ( $li['sub_description'] ?? '' ) ) ) {
				return $f;
			}
		}
		return '';
	}

	/* ------------------------------------------------------------------ the option wording */

	public static function tail_template(): string {
		$t = class_exists( 'ZDZ_Doc_Conventions' ) ? (string) ZDZ_Doc_Conventions::get( 'estimate.option_tail', '' ) : '';
		return ( false !== strpos( $t, '%1$d' ) && false !== strpos( $t, '%2$s' ) ) ? $t : self::OPTION_TAIL;
	}

	public static function tail( int $n, string $what ): string {
		return sprintf( self::tail_template(), $n, $what );
	}

	/** The option wording as a pattern (any number and choice). */
	public static function tail_re(): string {
		$t = preg_quote( self::tail_template(), '/' );
		$t = str_replace( array( preg_quote( '%1$d', '/' ), preg_quote( '%2$s', '/' ) ), array( '[12]', '[^)]*' ), $t );
		return '/\s*-?\s*' . $t . '/';
	}

	public static function has_option( string $t ): bool {
		return (bool) preg_match( self::tail_re(), $t );
	}

	public static function strip_option( string $t ): string {
		return trim( (string) preg_replace( self::tail_re(), '', $t ), " \t-" );
	}

	/** Does any line still carry the option wording? (billing-shape or engine-shape lines) */
	public static function has_options( array $lines ): bool {
		foreach ( $lines as $l ) {
			if ( is_array( $l ) && ( self::has_option( (string) ( $l['description'] ?? '' ) ) || self::has_option( (string) ( $l['sub_description'] ?? '' ) ) ) ) {
				return true;
			}
		}
		return false;
	}

	/** The option lines grouped by the line they offer: [ words => [ keys ] ]. */
	public static function option_groups( array $items, string $field ): array {
		$g = array();
		foreach ( $items as $k => $li ) {
			$t = is_array( $li ) ? (string) ( $li[ $field ] ?? '' ) : '';
			if ( self::has_option( $t ) ) {
				$g[ strtolower( self::strip_option( $t ) ) ][] = $k;
			}
		}
		return $g;
	}

	/** A line still marked as one of two options, its partner gone, loses the option wording. */
	public static function clean_lone_option( array $items, string $field ): array {
		foreach ( self::option_groups( $items, $field ) as $keys ) {
			if ( 1 === count( $keys ) ) {
				$items[ $keys[0] ][ $field ] = self::strip_option( (string) $items[ $keys[0] ][ $field ] );
			}
		}
		return $items;
	}

	/* ------------------------------------------------------------------ pricing */

	/** Lines the book never prices: metadata, discounts, credits, the tax line. */
	public static function skip_line( array $li ): bool {
		$d = (string) ( $li['description'] ?? '' );
		if ( (float) ( $li['unit_price'] ?? 0 ) < 0 ) {
			return true;
		}
		if ( class_exists( 'ZDZ_Doc_Conventions' ) && ZDZ_Doc_Conventions::is_metadata_line( $d, (float) ( $li['unit_price'] ?? 0 ) ) ) {
			return true;
		}
		return (bool) preg_match( '/^\s*(?:property\s+)?location\b|\b(discount|credit|price\s*adjust|reduction|coupon)\b|^\s*tax\b/i', $d );
	}

	public static function free_text( array $rule ): string {
		$t = trim( (string) ( $rule['line_text'] ?? '' ) );
		return '' !== $t ? $t : self::FREE_TEXT;
	}

	/** Is this an entitlement's line (a free line the app wrote)? */
	public static function is_free_line( array $li ): bool {
		return ! empty( $li['pb']['entitlement'] );
	}

	/** A line naming two choices -> two option lines (each priced); any other line as it is. */
	public static function expand_options( array $items ): array {
		$out = array();
		foreach ( $items as $li ) {
			$f = is_array( $li ) && ! self::skip_line( $li ) && ! self::is_free_line( $li ) ? self::family_of( $li ) : ''; // phpcs:ignore PHPCompatibility.Extensions.RemovedExtensions.famRemoved -- self::family_of() is a method of this class, not the removed fam extension
			if ( '' === $f ) {
				$out[] = $li;
				continue;
			}
			$m    = ZDZ_Price_Book::match_line( $f, $li );
			$opts = (array) ( $m['options'] ?? array() );
			if ( empty( $m['both_choices'] ) || 2 !== count( $opts ) || null === ( $opts[0]['price'] ?? null ) || null === ( $opts[1]['price'] ?? null ) ) {
				$out[] = $li;
				continue;
			}
			$note = (string) ( $m['assumed'][0] ?? '' );
			foreach ( $opts as $n => $o ) {
				$new  = $li;
				$sub  = trim( (string) ( $li['sub_description'] ?? '' ), " \t-" );
				$tail = self::tail( $n + 1, (string) ( '' !== (string) ( $o['label'] ?? '' ) ? $o['label'] : ( $o['value'] ?? '' ) ) );
				if ( '' !== trim( (string) ( $o['line_item'] ?? '' ) ) ) {
					$new['description'] = (string) $o['line_item'];
				}
				$new['sub_description'] = '' !== $sub ? $sub . ' - ' . $tail : $tail;
				$new['unit_price']      = (float) $o['price'];
				$new['_pb_note']        = '' !== $note ? array( $note ) : array();
				// the line still names both choices: it is priced as this option, never matched again
				$new['_pb_choice']      = array( 'sku' => $o['sku'] ?? null, 'price' => (float) $o['price'], 'kind' => (string) ( $m['kind'] ?? '' ) );
				$out[] = $new;
			}
		}
		return $out;
	}

	/**
	 * Price every family line from the published book. Returns { items, flags } (flags: one plain sentence per line the
	 * book changed or could not price, for the review).
	 */
	public static function price( array $items ): array {
		if ( ! self::families() ) { // phpcs:ignore PHPCompatibility.Extensions.RemovedExtensions.famRemoved -- self::families() is a method of this class, not the removed fam extension
			return array( 'items' => $items, 'flags' => array() );
		}
		$ver   = (string) ZDZ_Price_Book::version();
		$items = self::clean_lone_option( self::expand_options( $items ), 'sub_description' );
		$flags = array();
		foreach ( $items as &$li ) {
			if ( ! is_array( $li ) ) {
				continue;
			}
			$pre    = (array) ( $li['_pb_note'] ?? array() );
			$choice = is_array( $li['_pb_choice'] ?? null ) ? $li['_pb_choice'] : null;
			unset( $li['_pb_note'], $li['_pb_choice'] );
			if ( self::is_free_line( $li ) && (float) ( $li['unit_price'] ?? 0 ) <= 0 ) {
				continue;
			}
			unset( $li['pb'] );
			$f = self::skip_line( $li ) ? '' : self::family_of( $li ); // phpcs:ignore PHPCompatibility.Extensions.RemovedExtensions.famRemoved -- self::family_of() is a method of this class, not the removed fam extension
			if ( '' === $f ) {
				continue;
			}
			$m = null !== $choice
				? array( 'matched' => true, 'sku' => $choice['sku'], 'price' => $choice['price'], 'kind' => $choice['kind'], 'assumed' => array() )
				: ZDZ_Price_Book::match_line( $f, $li );
			if ( empty( $m['matched'] ) ) {
				continue;
			}
			// an opening priced as a pair says so on the line, so nobody orders one
			if ( ! empty( $m['forced_pair'] ) ) {
				$li['description'] = self::pair_named( $f, (string) ( $li['description'] ?? '' ) );
			}
			$lf = array_merge( $pre, (array) ( $m['assumed'] ?? array() ) );
			if ( ! empty( $m['warning'] ) ) {
				$lf[] = (string) $m['warning'];
			}
			if ( null !== ( $m['price'] ?? null ) ) {
				$old = (float) ( $li['unit_price'] ?? 0 );
				$new = round( (float) $m['price'], 2 );
				if ( $old > 0 && abs( $old - $new ) >= 1.0 ) {
					/* translators: 1: the book's price, 2: the line's price */
					$lf[] = sprintf( __( 'Priced $%1$s from the price book (the line had $%2$s). Use a discount line for a negotiated price.', 'zorderz' ), number_format( $new, 2 ), number_format( $old, 2 ) );
				}
				$li['unit_price'] = $new;
				if ( self::pair_of_singles( $m ) && (float) ( $li['quantity'] ?? 1 ) <= 1 ) {
					$li['quantity'] = 2;   // a pair priced one at a time is two of them
				}
			} else {
				$lf[] = '' !== (string) ( $m['reason'] ?? '' ) ? (string) $m['reason'] : __( 'The price book has no price for this line - price it by hand.', 'zorderz' );
			}
			$li['pb'] = array(
				'version' => $ver,
				'family'  => $f,
				'sku'     => $m['sku'] ?? null,
				'kind'    => (string) ( $m['kind'] ?? '' ),
				'priced'  => null !== ( $m['price'] ?? null ),
				'flags'   => array_values( array_unique( array_filter( array_map( 'strval', $lf ) ) ) ),
			);
			foreach ( $li['pb']['flags'] as $x ) {
				$flags[] = trim( (string) ( $li['description'] ?? '' ) ) . ': ' . $x;
			}
		}
		unset( $li );
		return array( 'items' => $items, 'flags' => $flags );
	}

	/** The line's name with the family's pair word in front (its first match.pair.words entry), unless it says one. */
	public static function pair_named( string $family, string $desc ): string {
		$fam   = ZDZ_Price_Book::family( $family ); // phpcs:ignore PHPCompatibility.Extensions.RemovedExtensions.famRemoved -- ZDZ_Price_Book::family() is a method, not the removed fam extension
		$words = array_values( array_filter( array_map( 'strval', (array) ( $fam['match']['pair']['words'] ?? array() ) ), 'strlen' ) );
		if ( ! $words ) {
			return $desc;
		}
		foreach ( $words as $w ) {
			if ( preg_match( '/\b' . preg_quote( $w, '/' ) . '\b/i', $desc ) ) {
				return $desc;
			}
		}
		return ucfirst( $words[0] ) . ' ' . ltrim( $desc );
	}

	/** A pair the book priced at one unit's price (the family does not price this kind as a pair): two units. */
	public static function pair_of_singles( array $m ): bool {
		if ( empty( $m['double'] ) || null === ( $m['price'] ?? null ) || empty( $m['sku'] ) ) {
			return false;
		}
		$item = ZDZ_Price_Book::item( (string) $m['sku'] );
		return is_array( $item ) && is_numeric( $item['price'] ?? null ) && abs( (float) $item['price'] - (float) $m['price'] ) < 0.01;
	}

	/** Each family's prices, for the parse model (prices only). */
	public static function prompt_block(): string {
		$out = '';
		foreach ( self::families() as $f ) { // phpcs:ignore PHPCompatibility.Extensions.RemovedExtensions.famRemoved -- self::families() is a method of this class, not the removed fam extension
			$out .= (string) ZDZ_Price_Book::prompt_block( $f );
		}
		return $out;
	}

	/* ------------------------------------------------------------------ entitlements */

	/** The entitlement rules of every family: [ [ family, rule ] ]. */
	public static function entitlement_rules(): array {
		$out = array();
		foreach ( self::families() as $f ) { // phpcs:ignore PHPCompatibility.Extensions.RemovedExtensions.famRemoved -- self::families() is a method of this class, not the removed fam extension
			$fam = ZDZ_Price_Book::family( $f ); // phpcs:ignore PHPCompatibility.Extensions.RemovedExtensions.famRemoved -- ZDZ_Price_Book::family() is a method, not the removed fam extension
			foreach ( (array) ( $fam['rules'] ?? array() ) as $r ) {
				if ( is_array( $r ) && 'entitlement' === ( $r['kind'] ?? '' ) && '' !== (string) ( $r['id'] ?? '' ) && '' !== (string) ( $r['applies_to_kind'] ?? '' ) ) {
					$out[] = array( $f, $r );
				}
			}
		}
		return $out;
	}

	/**
	 * Does this invoice earn the rule? Its family lines of a qualifying kind, net of every credit, reach qualifying_min.
	 * A rule without a qualifying_min earns nothing.
	 */
	public static function invoice_qualifies( array $inv, string $family, array $rule ): bool {
		if ( ! is_numeric( $rule['qualifying_min'] ?? null ) ) {
			return false;   // a rule that does not say what earns it gives nothing away
		}
		$min   = (float) $rule['qualifying_min'];
		$kinds = array_map( 'strval', (array) ( $rule['qualifying_kinds'] ?? array() ) );
		$units = 0.0;
		$less  = 0.0;
		foreach ( (array) ( $inv['lines'] ?? array() ) as $ln ) {
			if ( ! is_array( $ln ) ) {
				continue;
			}
			$amt  = isset( $ln['amount']['amount'] ) ? (float) $ln['amount']['amount'] : (float) ( $ln['qty'] ?? 1 ) * (float) ( $ln['unit_cost']['amount'] ?? 0 );
			$name = (string) ( $ln['name'] ?? '' );
			$desc = (string) ( $ln['description'] ?? '' );
			if ( $amt < 0 || preg_match( '/credit|\breturn|refund|discount|reduction|coupon|price\s*adjust/i', $name . ' ' . $desc ) ) {
				$less += abs( $amt );
				continue;
			}
			$m = ZDZ_Price_Book::match_line( $family, array( 'description' => $name, 'sub_description' => $desc ) );
			if ( empty( $m['matched'] ) ) {
				continue;
			}
			$kind = (string) ( $m['kind'] ?? '' );
			if ( $kind === (string) $rule['applies_to_kind'] || ( $kinds && ! in_array( $kind, $kinds, true ) ) ) {
				continue;
			}
			$units += $amt;
		}
		return ( $units - $less ) + 0.005 >= $min && $units > 0;
	}

	/**
	 * Give each entitlement the customer has earned to one line, reserving it. $items by reference; $client_id: the
	 * billing client; $doc_key: 'est:<billing id>' when updating, '' for a new estimate; $invoices: callable( client_id,
	 * days ) => invoices with lines. Returns the grants [ rule, client_id, invoice, date, reservation, existing ].
	 */
	public static function apply_entitlements( array &$items, string $client_id, string $doc_key, callable $invoices ): array {
		if ( '' === $client_id || ! method_exists( 'ZDZ_Price_Book', 'reserve_entitlement' ) ) {
			return array();
		}
		$grants = array();
		foreach ( self::entitlement_rules() as $pair ) {
			list( $family, $rule ) = $pair;
			$rid   = (string) $rule['id'];
			$price = is_numeric( $rule['price'] ?? null ) ? max( 0.0, (float) $rule['price'] ) : 0.0;
			$text  = self::free_text( $rule );
			$mark  = '/\s*-?\s*' . preg_quote( $text, '/' ) . '\b[^\n]*/i';
			$kept  = null;
			foreach ( $items as &$li ) {
				if ( ! is_array( $li ) || $rid !== (string) ( $li['pb']['entitlement'] ?? '' ) ) {
					continue;
				}
				$inv = (string) ( $li['pb']['qualifying_invoice'] ?? '' );
				$use = ( null === $kept && '' !== $doc_key && '' !== $inv ) ? ZDZ_Price_Book::entitlement_use( $rid, $client_id, $inv ) : null;
				if ( $use && (string) ( $use['used_doc'] ?? '' ) === $doc_key && (float) ( $li['unit_price'] ?? 0 ) <= $price ) {
					$kept = array( 'rule' => $rid, 'client_id' => $client_id, 'invoice' => $inv, 'date' => (string) ( $use['qualifying_date'] ?? '' ), 'reservation' => (int) $use['id'], 'existing' => true );
					continue;
				}
				// not confirmable: no "included" words on a line nobody is giving away
				$li['sub_description'] = trim( (string) preg_replace( $mark, '', (string) ( $li['sub_description'] ?? '' ) ), " -\t" );
				unset( $li['pb'] );
				$m = ZDZ_Price_Book::match_line( $family, $li );
				if ( null !== ( $m['price'] ?? null ) ) {
					$li['unit_price'] = round( (float) $m['price'], 2 );
				} elseif ( '' !== (string) ( $rule['fallback_sku'] ?? '' ) && null !== ZDZ_Price_Book::price( (string) $rule['fallback_sku'] ) ) {
					$li['unit_price'] = (float) ZDZ_Price_Book::price( (string) $rule['fallback_sku'] );
				} else {
					$li['pb'] = array( 'family' => $family, 'priced' => false, 'flags' => array( __( 'The price book could not price this line - price it by hand.', 'zorderz' ) ) );
				}
			}
			unset( $li );
			if ( $kept ) {
				$grants[] = $kept;
				continue;
			}
			// a priced line of the rule's kind to give
			$idx = null;
			foreach ( $items as $k => $li ) {
				if ( ! is_array( $li ) || self::skip_line( $li ) || (float) ( $li['unit_price'] ?? 0 ) <= 0 ) {
					continue;
				}
				$m = ZDZ_Price_Book::match_line( $family, $li );
				if ( ! empty( $m['matched'] ) && (string) $rule['applies_to_kind'] === (string) ( $m['kind'] ?? '' ) && null !== ( $m['price'] ?? null ) ) {
					$idx = $k;
					break;
				}
			}
			if ( null === $idx ) {
				continue;
			}
			$window = max( 1, (int) ( $rule['window_days'] ?? 365 ) );
			$found  = null;
			foreach ( (array) call_user_func( $invoices, $client_id, $window ) as $inv ) {
				if ( ! is_array( $inv ) || ! self::invoice_qualifies( $inv, $family, $rule ) ) {
					continue;
				}
				$inv_no = (string) ( $inv['invoice_number'] ?? ( $inv['id'] ?? '' ) );
				if ( '' === $inv_no ) {
					continue;
				}
				$date = substr( (string) ( $inv['create_date'] ?? '' ), 0, 10 );
				$use  = ZDZ_Price_Book::entitlement_use( $rid, $client_id, $inv_no );
				if ( $use ) {
					if ( '' !== $doc_key && (string) ( $use['used_doc'] ?? '' ) === $doc_key ) {
						$found = array( 'invoice' => $inv_no, 'date' => $date, 'reservation' => (int) $use['id'], 'existing' => true );
						break;
					}
					continue;   // this purchase's entitlement went on another estimate
				}
				$res = ZDZ_Price_Book::reserve_entitlement( $rid, $client_id, $inv_no, $date, (int) get_current_user_id() );
				if ( ! $res ) {
					continue;   // someone else just took it
				}
				$found = array( 'invoice' => $inv_no, 'date' => $date, 'reservation' => (int) $res, 'existing' => false );
				break;
			}
			if ( ! $found ) {
				continue;
			}
			$li   = $items[ $idx ];
			$qty  = max( 1, (int) round( (float) ( $li['quantity'] ?? 1 ) ) );
			$when = '' !== $found['date'] ? gmdate( 'M Y', (int) strtotime( $found['date'] . ' 12:00:00 UTC' ) ) : '';
			/* translators: 1: the rule's words, 2: month and year, 3: invoice number */
			$why  = sprintf( __( '%1$s - earlier purchase (%2$sinvoice #%3$s)', 'zorderz' ), $text, '' !== $when ? $when . ', ' : '', $found['invoice'] );
			$base = trim( (string) preg_replace( $mark, '', (string) ( $li['sub_description'] ?? '' ) ), " -\t" );
			$free = $li;
			$free['quantity']        = 1;
			$free['unit_price']      = $price;
			$free['sub_description'] = '' !== $base ? $base . ' - ' . $why : $why;
			$free['pb']              = array_merge( (array) ( $li['pb'] ?? array() ), array( 'entitlement' => $rid, 'qualifying_invoice' => $found['invoice'] ) );
			$replacement             = array( $free );
			if ( $qty > 1 ) {
				$rest             = $li;
				$rest['quantity'] = $qty - 1;
				$replacement[]    = $rest;
			}
			array_splice( $items, (int) array_search( $idx, array_keys( $items ), true ), 1, $replacement );
			$items    = array_values( $items );
			$grants[] = array( 'rule' => $rid, 'client_id' => $client_id, 'invoice' => $found['invoice'], 'date' => $found['date'], 'reservation' => $found['reservation'], 'existing' => $found['existing'] );
		}
		return $grants;
	}

	/** The billing system has the estimate: attach it to each reserved use. */
	public static function confirm( array $grants, string $doc_key ): void {
		if ( ! $grants || '' === $doc_key || ! method_exists( 'ZDZ_Price_Book', 'confirm_entitlement' ) ) {
			return;
		}
		foreach ( $grants as $g ) {
			if ( empty( $g['existing'] ) && ! empty( $g['reservation'] ) ) {
				ZDZ_Price_Book::confirm_entitlement( (int) $g['reservation'], $doc_key );
			}
		}
	}

	/** The write failed: give each new reservation back. */
	public static function release( array $grants ): void {
		if ( ! $grants || ! method_exists( 'ZDZ_Price_Book', 'release_entitlement' ) ) {
			return;
		}
		foreach ( $grants as $g ) {
			if ( empty( $g['existing'] ) && ! empty( $g['reservation'] ) ) {
				ZDZ_Price_Book::release_entitlement( (int) $g['reservation'] );
			}
		}
	}
}
