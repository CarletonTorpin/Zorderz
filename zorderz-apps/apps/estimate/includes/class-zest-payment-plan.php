<?php
/**
 * ZEST_Payment_Plan - the payment schedule of an estimate, worked out by fixed rules.
 *
 * Which estimates get a schedule is the business's: option zest_payment_schedule (rules.yml
 * `estimates.payment_schedule`), Core default empty = no estimate gets one.
 *
 *   groups     [ { id, label?, names?: [words], price_book_family?: key } ] - a line belongs to the first group whose
 *              words are in the line's NAME (whole words; "not <word>" / "non-<word>" never counts), or that is a line
 *              of the price book family (ZDZ_Price_Book::is_family_line: the name, or the sub-line where the family
 *              says so; notes are never read). The ids are what the customer-agreements app knows as lead-time groups.
 *   deposit    { at_limit: true } the most the deposit limit allows (ZEST_Deposit_Limit), or { percent?, fixed_cents? }
 *              the business's own rule (the smaller of the two), never over the limit. No rule: no schedule.
 *
 * An estimate with any line in a group is ONE job: a deposit when the customer signs the agreement, the rest when the
 * work is done; the deposit is worked out on the whole price.
 *
 *   contract    the billing system's saved total (amount). Tax is worked out the way the billing system does it: a line's
 *               tax is the line x (1 - the document discount) x its rate, summed per tax and rounded once; a negative line
 *               takes nothing off another line's tax. A tax typed as a priced line is tax too.
 *   deposit     on the deposit reader's pre-tax price (ZEST_Deposit_Rule::base), in whole dollars where the limit says so.
 *   balance     the contract amount less the deposit, due when the work is done (the cents land here).
 *   the check   the price is above $0; the lines, the discount and the tax add up to the billing system's total to the
 *               cent; the deposit is within the limit on the price worked out here too; no line charges a deposit or
 *               credits money already paid (the schedule could not say what is owed). A failure means no schedule.
 *
 * Pure but for reading its two settings. Whole cents throughout; the same estimate always gives the same schedule.
 * When the document has no saved total yet (a write about to happen), its own sum is the total ('predicted').
 *
 * @package Zorderz\Estimate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ZEST_Payment_Plan {

	/** The rules' version, sent to the agreements app with each schedule (schedule.plan_version). */
	const VERSION = '1';
	const OPT     = 'zest_payment_schedule';

	/** @var array|null the configuration in force (tests set it). */
	private static $config = null;

	public static function set_config( ?array $c ): void {
		self::$config = $c;
	}

	public static function config(): array {
		if ( null !== self::$config ) {
			return self::$config;
		}
		$c = function_exists( 'get_option' ) ? get_option( self::OPT, array() ) : array();
		if ( function_exists( 'apply_filters' ) ) {
			$c = apply_filters( 'zest_payment_schedule', $c );
		}
		return is_array( $c ) ? $c : array();
	}

	/** The groups, cleaned: [ { id, label, names: [regex], family } ]. */
	public static function groups(): array {
		$out = array();
		foreach ( (array) ( self::config()['groups'] ?? array() ) as $g ) {
			$id = is_array( $g ) ? strtolower( trim( (string) ( $g['id'] ?? '' ) ) ) : '';
			if ( ! preg_match( '/^[a-z0-9][a-z0-9_\-]{0,39}$/', $id ) ) {
				continue;
			}
			$words = array();
			foreach ( (array) ( $g['names'] ?? array() ) as $w ) {
				$w = strtolower( trim( (string) $w ) );
				if ( preg_match( '/^[a-z0-9][a-z0-9 \-]{0,39}$/', $w ) ) {
					$words[] = str_replace( array( ' ', '\\-' ), array( '[\s\-]*', '[\s\-]*' ), preg_quote( $w, '/' ) );
				}
			}
			$out[] = array(
				'id'     => $id,
				'label'  => '' !== trim( (string) ( $g['label'] ?? '' ) ) ? (string) $g['label'] : $id,
				'names'  => $words,
				'family' => is_string( $g['price_book_family'] ?? null ) ? (string) $g['price_book_family'] : '',
			);
		}
		return $out;
	}

	/* ------------------------------------------------------------------ lines */

	private static function cents( $v ): int {
		if ( is_array( $v ) ) {
			$v = $v['amount'] ?? 0;
		}
		return is_numeric( $v ) ? (int) round( (float) $v * 100 ) : 0;
	}

	/** "1" for 1, "2.5" for 2.5, as the billing system and the sale form print a quantity. */
	public static function qty( float $q ): string {
		$s = rtrim( rtrim( number_format( $q, 4, '.', '' ), '0' ), '.' );
		return '' === $s || '-0' === $s ? '0' : $s;
	}

	/**
	 * One line, in any shape the app passes around (a billing line {name, description, qty, unit_cost}; an engine item
	 * {description, sub_description, quantity, unit_price}), as [ name, sub-line, qty, cents, taxes [ [name, basis
	 * points] ], the line in billing shape ]. null for junk.
	 */
	public static function line( $l ): ?array {
		if ( ! is_array( $l ) ) {
			return null;
		}
		if ( array_key_exists( 'name', $l ) ) {
			$name = (string) $l['name'];
			$sub  = (string) ( $l['full_description'] ?? $l['description'] ?? '' );
		} else {
			$name = (string) ( $l['description'] ?? '' );
			$sub  = (string) ( $l['sub_description'] ?? '' );
		}
		$q    = $l['qty'] ?? ( $l['quantity'] ?? 1 );
		$q    = is_numeric( $q ) ? (float) $q : 1.0;
		$unit = $l['unit_cost'] ?? ( $l['rate'] ?? ( $l['unit_price'] ?? 0 ) );
		$rate = self::cents( $unit );
		$tax  = array();
		foreach ( array( 1, 2 ) as $k ) {
			$tn = trim( (string) ( $l[ 'taxName' . $k ] ?? '' ) );
			$tr = $l[ 'taxAmount' . $k ] ?? null;
			if ( '' !== $tn && is_numeric( $tr ) && (float) $tr > 0 ) {
				$tax[] = array( $tn, (int) round( (float) $tr * 100 ) );   // "7.75" (percent) -> 775 basis points
			}
		}
		$bl = array_key_exists( 'unit_cost', $l ) && array_key_exists( 'name', $l ) ? $l
			: array( 'name' => $name, 'description' => $sub, 'qty' => self::qty( $q ), 'unit_cost' => array( 'amount' => is_array( $unit ) ? ( $unit['amount'] ?? 0 ) : $unit ) );
		return array( $name, $sub, $q, (int) round( $q * $rate ), $tax, $bl );
	}

	/* ------------------------------------------------------------------ groups */

	/** The group of one line by its own words: a group id, or ''. */
	public static function family( string $name, string $sub ): string {
		$n = strtolower( $name );
		foreach ( self::groups() as $g ) {
			foreach ( $g['names'] as $w ) {
				$clean = (string) preg_replace( '/\b(?:non|not)[\s\-]*(?:' . $w . ')/i', ' ', $n );
				if ( preg_match( '/\b(?:' . $w . ')\b/i', $clean ) ) {
					return $g['id'];
				}
			}
			if ( '' !== $g['family'] && class_exists( 'ZDZ_Price_Book' ) && method_exists( 'ZDZ_Price_Book', 'is_family_line' )
				&& ZDZ_Price_Book::is_family_line( $g['family'], $name, $sub ) ) {
				return $g['id'];
			}
		}
		return '';
	}

	/** The groups on an estimate, in the configured order. */
	public static function families( array $lines ): array {
		$seen = array();
		foreach ( $lines as $l ) {
			$p = self::line( $l );
			if ( null === $p ) {
				continue;
			}
			$f = self::family( $p[0], $p[1] ); // phpcs:ignore PHPCompatibility.Extensions.RemovedExtensions.famRemoved -- self::family() is a method of this class, not the removed fam extension
			if ( '' !== $f ) {
				$seen[ $f ] = true;
			}
		}
		$out = array();
		foreach ( self::groups() as $g ) {
			if ( isset( $seen[ $g['id'] ] ) ) {
				$out[] = $g['id'];
			}
		}
		return $out;
	}

	public static function in_scope( array $lines ): bool {
		return (bool) self::families( $lines ); // phpcs:ignore PHPCompatibility.Extensions.RemovedExtensions.famRemoved -- self::families() is a method of this class, not the removed fam extension
	}

	/** [ [ family, units ] ]: units = the summed quantity of the group's priced lines (for the agreement's dates). */
	public static function family_units( array $lines ): array {
		$fams  = self::families( $lines ); // phpcs:ignore PHPCompatibility.Extensions.RemovedExtensions.famRemoved -- self::families() is a method of this class, not the removed fam extension
		$units = array_fill_keys( $fams, 0.0 );
		foreach ( $lines as $l ) {
			$p = self::line( $l );
			if ( null === $p || $p[3] <= 0 ) {
				continue;
			}
			$f = self::family( $p[0], $p[1] ); // phpcs:ignore PHPCompatibility.Extensions.RemovedExtensions.famRemoved -- self::family() is a method of this class, not the removed fam extension
			if ( '' !== $f && isset( $units[ $f ] ) ) {
				$units[ $f ] += $p[2];
			}
		}
		$out = array();
		foreach ( $fams as $f ) {
			$out[] = array( 'family' => $f, 'units' => (int) round( $units[ $f ] ) );
		}
		return $out;
	}

	/* ------------------------------------------------------------------ money */

	/** 50000 -> "$500", 123450 -> "$1,234.50" (whole cents, no float). */
	public static function money( int $c ): string {
		$neg = $c < 0;
		$c   = abs( $c );
		return ( $neg ? '-' : '' ) . '$' . number_format( intdiv( $c, 100 ) ) . ( $c % 100 ? '.' . str_pad( (string) ( $c % 100 ), 2, '0', STR_PAD_LEFT ) : '' );
	}

	private static function div_round( int $num, int $den ): int {
		return $den > 0 ? intdiv( $num + intdiv( $den, 2 ), $den ) : 0;
	}

	/**
	 * The deposit on this pre-tax price by the business's rule, never over the limit; null when no rule says one.
	 */
	public static function deposit_for( int $pretax ): ?int {
		$rule = (array) ( self::config()['deposit'] ?? array() );
		$lim  = ZEST_Deposit_Limit::active() ? ZEST_Deposit_Limit::cap_cents( $pretax ) : null;
		if ( ! empty( $rule['at_limit'] ) ) {
			return $lim;
		}
		$own = null;
		if ( isset( $rule['percent'] ) && is_numeric( $rule['percent'] ) && (float) $rule['percent'] >= 0 ) {
			$own = intdiv( max( 0, $pretax ) * (int) round( (float) $rule['percent'] * 100 ), 10000 );
		}
		if ( isset( $rule['fixed_cents'] ) && is_numeric( $rule['fixed_cents'] ) && (int) $rule['fixed_cents'] >= 0 ) {
			$own = null === $own ? (int) $rule['fixed_cents'] : min( $own, (int) $rule['fixed_cents'] );
		}
		if ( null === $own ) {
			return null;
		}
		if ( ! empty( $rule['whole_dollars'] ) || ( ZEST_Deposit_Limit::active() && ZEST_Deposit_Limit::get()['whole'] ) ) {
			$own = intdiv( $own, 100 ) * 100;
		}
		return null === $lim ? $own : min( $own, $lim );
	}

	/* ------------------------------------------------------------------ the plan */

	/**
	 * The schedule of an estimate (or invoice) as it is saved, or as it is about to be written (no 'amount'):
	 * [ in_scope, families, ok, why[] (with figures), why_plain[] (no figures), predicted, lines_cents, discount_cents,
	 *   pretax_cents, tax_cents, total_cents (the contract amount), deposit_cents, balance_cents, version ].
	 */
	public static function build( array $doc ): array {
		$raw  = array_values( (array) ( $doc['lines'] ?? array() ) );
		$fams = self::families( $raw ); // phpcs:ignore PHPCompatibility.Extensions.RemovedExtensions.famRemoved -- self::families() is a method of this class, not the removed fam extension
		$out  = array(
			'in_scope' => (bool) $fams, 'families' => $fams, 'ok' => false, 'why' => array(), 'why_plain' => array(),
			'predicted' => ! array_key_exists( 'amount', $doc ), 'lines_cents' => 0, 'discount_cents' => 0, 'pretax_cents' => 0,
			'tax_cents' => 0, 'total_cents' => 0, 'deposit_cents' => 0, 'balance_cents' => 0, 'version' => self::VERSION,
		);
		if ( ! $fams ) {
			$out['why'][] = $out['why_plain'][] = 'none of its lines is in a group that has a payment schedule';
			return $out;
		}

		$sub    = 0;        // every line, as the billing system's subtotal
		$job    = 0;        // the work: every line but tax typed as a line
		$taxl   = 0;        // tax typed as a priced line
		$locked = false;    // a deposit charge or a credit for money already paid
		$taxed  = array();  // [ cents, taxes ] of each positive line with a tax
		$bl     = array();  // billing-shape lines, for the deposit reader
		foreach ( $raw as $l ) {
			$p = self::line( $l );
			if ( null === $p ) {
				continue;
			}
			$c    = $p[3];
			$bl[] = $p[5];
			$sub += $c;
			if ( ( $c > 0 && ZEST_Deposit_Rule::deposit_line( $p[5] ) ) || ( $c < 0 && ZEST_Deposit_Rule::credit_line( $p[5] ) ) ) {
				$locked = true;
				continue;
			}
			if ( $c > 0 && ZEST_Deposit_Rule::tax_line( $p[5] ) ) {
				$taxl += $c;
			} else {
				$job += $c;
			}
			if ( $c > 0 && $p[4] ) {
				$taxed[] = array( $c, $p[4] );
			}
		}

		// the whole-document discount (discount_value is a percent; discount_total its money, negative)
		$dv_bp = is_numeric( $doc['discount_value'] ?? null ) ? (int) round( (float) $doc['discount_value'] * 100 ) : 0;
		$dt    = abs( self::cents( $doc['discount_total'] ?? 0 ) );
		if ( ! $out['predicted'] && $dt > 0 ) {
			$discount = $dt;
		} elseif ( $dv_bp > 0 && $sub > 0 ) {
			$discount = self::div_round( $sub * $dv_bp, 10000 );
		} else {
			$discount = $dt;
		}

		// line taxes: line x (1 - discount) x rate, summed per tax, rounded once
		$acc = array();
		foreach ( $taxed as $t ) {
			foreach ( $t[1] as $tx ) {
				$key = strtolower( $tx[0] ) . '|' . $tx[1];
				if ( $dv_bp > 0 ) {
					$acc[ $key ] = ( $acc[ $key ] ?? 0 ) + $t[0] * $tx[1] * ( 10000 - $dv_bp );   // cents x 1e8
				} else {
					$share       = ( $discount > 0 && $sub > 0 ) ? ( $sub - $discount ) / $sub : 1.0;
					$acc[ $key ] = ( $acc[ $key ] ?? 0 ) + (int) round( $t[0] * $tx[1] * 10000 * $share );
				}
			}
		}
		$tax_l = 0;
		foreach ( $acc as $n ) {
			$tax_l += self::div_round( (int) $n, 100000000 );
		}

		$pretax = $job - $discount;
		$tax    = $taxl + $tax_l;
		$ours   = $pretax + $tax;
		$total  = $out['predicted'] ? $ours : self::cents( $doc['amount'] ?? 0 );

		// the deposit reader's own price, never worked out again
		$bdoc          = $doc;
		$bdoc['lines'] = $bl;
		if ( $out['predicted'] ) {
			$bdoc['amount']         = number_format( $total / 100, 2, '.', '' );
			$bdoc['discount_total'] = number_format( -$discount / 100, 2, '.', '' );
		}
		$base    = ZEST_Deposit_Rule::base( $bdoc );
		$dep     = self::deposit_for( $base );
		$deposit = null === $dep ? 0 : $dep;

		$out = array_merge( $out, array(
			'lines_cents' => $sub, 'discount_cents' => $discount, 'pretax_cents' => $pretax, 'tax_cents' => $tax,
			'total_cents' => $total, 'deposit_cents' => $deposit, 'balance_cents' => $total - $deposit,
		) );

		$why = array();
		if ( null === $dep ) {
			$why[] = array( 'no deposit rule is set for this business', 'no deposit rule is set for this business' );
		}
		if ( $locked ) {
			$why[] = array( 'it has a line for a deposit charge or for money already paid', 'it has a line for a deposit charge or for money already paid' );
		}
		if ( $total <= 0 ) {
			$why[] = array( 'its price is $0 or less', 'it has no price yet' );
		} elseif ( $pretax <= 0 ) {
			$why[] = array( 'its price before tax can\'t be worked out', 'its price before tax can\'t be worked out' );
		}
		if ( ! $locked && $total > 0 && $ours !== $total ) {
			$why[] = array( 'its lines, discount, and tax add up to ' . self::money( $ours ) . ', but the billing system\'s total is ' . self::money( $total ),
				'its lines, discount, and tax don\'t add up to the billing system\'s total' );
		}
		if ( $deposit < 0 || ( ZEST_Deposit_Limit::active() && null !== ZEST_Deposit_Limit::limit_cents( max( 0, $pretax ) ) && $deposit > ZEST_Deposit_Limit::cap_cents( max( 0, $pretax ) ) ) ) {
			$why[] = array( 'its deposit would be over the deposit limit', 'its deposit would be over the deposit limit' );
		}
		foreach ( $why as $w ) {
			$out['why'][]       = $w[0];
			$out['why_plain'][] = $w[1];
		}
		$out['ok'] = ! $why;
		if ( ! $out['ok'] ) {
			$out['deposit_cents'] = 0;
			$out['balance_cents'] = 0;
		}
		return $out;
	}
}
