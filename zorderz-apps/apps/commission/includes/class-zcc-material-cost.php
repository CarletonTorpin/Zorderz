<?php
/**
 * ZCC_Material_Cost — what the business really pays for materials.
 *
 * Two rules, applied together, from one start date the business sets:
 *
 *  1. PRICE BOOK COSTS FOR A FAMILY. A line of a price-book family the business names here
 *     (ZDZ_Price_Book::is_family_line) whose cost book has no cost takes the supplier cost of the
 *     SKU the book matches it to (match_line → cost), times the units. A line the book prices
 *     at under half its price for that SKU is a fee or an upcharge, not the product: no cost.
 *     A family line the book cannot price is flagged, never a silent $0.
 *  2. PURCHASE TAX ON MATERIALS. Every material cost carries the tax rate of the place it is
 *     bought: each supplier group has a tax area, each area a rate. Costs that are not a
 *     purchase price (a percent-of-billed estimate), $0 lines and service work are not taxed.
 *
 * WHY A START DATE, AND NOTHING BEFORE IT. An earned commission may be a wage, and how it is
 * worked out may have to be in a signed written plan. So the rules change nothing until the
 * business enters the day they apply from, and then only invoices PAID on or after that day (or
 * issued, when not yet paid). A day before today needs its own confirmation: it re-figures
 * commission not yet locked for invoices already paid. Locked rows are never recalculated.
 * Until then the Material cost tab shows, per person and month, what the rules would change,
 * without writing anything (report_row).
 *
 * Every value is the business's: the families, the supplier groups, the tax areas and their
 * rates (option `zcc_material_cost_rules`, or the Identity Pack's costs file). Core ships none,
 * so with nothing configured the rules do nothing.
 *
 * @package Zorderz\Commission
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZCC_Material_Cost {

	const OPTION = 'zcc_material_cost_rules';

	/** Report only: null = by the start date; 'on' / 'off' forces the rules for a what-if run. */
	public static $force = null;

	/* ── settings ─────────────────────────────────────────────── */

	public static function defaults(): array {
		return [
			'start_date' => '',
			'families'   => [],                    // price-book family key => supplier group key
			'groups'     => [ 'other' => __( 'Everything else', 'zorderz' ) ],
			'areas'      => [],                    // tax area name => rate (percent)
			'tax_areas'  => [],                    // supplier group key => tax area name
			'updated_at' => '',
			'updated_by' => 0,
		];
	}

	public static function get(): array {
		$saved = function_exists( 'get_option' ) ? get_option( self::OPTION, [] ) : [];
		$cfg   = array_merge( self::defaults(), is_array( $saved ) ? $saved : [] );
		foreach ( [ 'families', 'groups', 'areas', 'tax_areas' ] as $k ) {
			$cfg[ $k ] = is_array( $cfg[ $k ] ) ? $cfg[ $k ] : [];
		}
		if ( ! isset( $cfg['groups']['other'] ) ) {
			$cfg['groups']['other'] = __( 'Everything else', 'zorderz' );
		}
		return $cfg;
	}

	public static function valid_date( string $d ): bool {
		return (bool) preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m ) && checkdate( (int) $m[2], (int) $m[3], (int) $m[1] );
	}

	/**
	 * Whitelisted save. A date that is not a real Y-m-d is refused, never guessed; a day before
	 * today needs `confirm_past`; a rate must be a number from 0 to under 100; a group's area
	 * must be one of the areas.
	 *
	 * @param array  $in    start_date, confirm_past, families, groups, areas, tax_areas.
	 * @param int    $uid   Who saves.
	 * @param string $today Y-m-d (tests); '' = the site's today.
	 */
	public static function save( array $in, int $uid, string $today = '' ): array {
		$cfg  = self::get();
		$date = trim( (string) ( $in['start_date'] ?? $cfg['start_date'] ) );
		if ( '' !== $date && ! self::valid_date( $date ) ) {
			return [ 'ok' => false, 'error' => __( 'The start date must be a real date (YYYY-MM-DD), or empty.', 'zorderz' ) ];
		}
		$today = '' !== $today ? $today : substr( (string) current_time( 'mysql' ), 0, 10 );
		if ( '' !== $date && $date !== $cfg['start_date'] && strcmp( $date, $today ) < 0 && empty( $in['confirm_past'] ) ) {
			return [ 'ok' => false, 'error' => __( 'That day is before today. Commission not yet locked for invoices paid since then would be figured again with the new costs. If the signed plan covers those invoices, tick the confirmation and save again.', 'zorderz' ) ];
		}
		$groups = [ 'other' => (string) ( $cfg['groups']['other'] ?? __( 'Everything else', 'zorderz' ) ) ];
		foreach ( (array) ( $in['groups'] ?? $cfg['groups'] ) as $k => $label ) {
			$k = sanitize_key( (string) $k );
			if ( '' !== $k ) {
				$groups[ $k ] = sanitize_text_field( (string) $label );
			}
		}
		$areas = [];
		foreach ( (array) ( $in['areas'] ?? $cfg['areas'] ) as $name => $rate ) {
			$name = sanitize_text_field( (string) $name );
			if ( '' === $name ) {
				continue;
			}
			if ( ! is_numeric( $rate ) || (float) $rate < 0 || (float) $rate >= 100 ) {
				/* translators: %s: a tax area's name. */
				return [ 'ok' => false, 'error' => sprintf( __( 'The rate for %s must be a percent from 0 to under 100.', 'zorderz' ), $name ) ];
			}
			$areas[ $name ] = round( (float) $rate, 4 );
		}
		$tax = [];
		foreach ( (array) ( $in['tax_areas'] ?? $cfg['tax_areas'] ) as $g => $a ) {
			$g = sanitize_key( (string) $g );
			$a = (string) $a;
			if ( '' === $g || '' === $a ) {
				continue;
			}
			if ( ! isset( $groups[ $g ] ) || ! isset( $areas[ $a ] ) ) {
				/* translators: %s: a tax area's name. */
				return [ 'ok' => false, 'error' => sprintf( __( 'Unknown tax area or supplier group: %s', 'zorderz' ), $a ) ];
			}
			$tax[ $g ] = $a;
		}
		$fam = [];
		foreach ( (array) ( $in['families'] ?? $cfg['families'] ) as $f => $g ) {
			$f = sanitize_key( (string) $f );
			$g = sanitize_key( (string) $g );
			if ( '' !== $f ) {
				$fam[ $f ] = isset( $groups[ $g ] ) ? $g : 'other';
			}
		}
		$new = [
			'start_date' => $date,
			'families'   => $fam,
			'groups'     => $groups,
			'areas'      => $areas,
			'tax_areas'  => $tax,
			'updated_at' => function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' ),
			'updated_by' => $uid,
		];
		update_option( self::OPTION, $new, false );
		if ( class_exists( 'ZCC_Audit' ) ) {
			ZCC_Audit::log( 'material_cost_rules_saved', [ 'by' => $uid, 'start' => $date, 'families' => count( $fam ), 'areas' => count( $areas ) ] );
		}
		return [ 'ok' => true, 'config' => $new ];
	}

	/** A short fingerprint of the rules (and the price book version), for caches that hold figures. */
	public static function version(): string {
		$c  = self::get();
		$pb = ( class_exists( 'ZDZ_Price_Book' ) && method_exists( 'ZDZ_Price_Book', 'version' ) ) ? (string) ZDZ_Price_Book::version() : '';
		return substr( md5( (string) wp_json_encode( [ $c['start_date'], $c['families'], $c['areas'], $c['tax_areas'], $pb ] ) ), 0, 8 );
	}

	/** The rate for a supplier group: [ area, rate ]. A group with no area uses "other"'s. */
	public static function rate_for( string $group ): array {
		$cfg  = self::get();
		$area = (string) ( $cfg['tax_areas'][ $group ] ?? $cfg['tax_areas']['other'] ?? '' );
		return [ 'area' => $area, 'rate' => (float) ( $cfg['areas'][ $area ] ?? 0 ) ];
	}

	/* ── when ─────────────────────────────────────────────────── */

	/** The day that decides: paid, else issued (as the period filter does). */
	public static function invoice_day( array $inv ): string {
		$d = (string) ( $inv['date_paid'] ?? '' );
		if ( '' === $d ) {
			$d = (string) ( $inv['date_completed'] ?? '' );
		}
		return substr( $d, 0, 10 );
	}

	public static function applies( array $inv ): bool {
		if ( 'on' === self::$force ) {
			return true;
		}
		if ( 'off' === self::$force ) {
			return false;
		}
		$start = (string) self::get()['start_date'];
		if ( '' === $start ) {
			return false;
		}
		$day = self::invoice_day( $inv );
		return '' !== $day && strcmp( $day, $start ) >= 0;
	}

	/* ── what ─────────────────────────────────────────────────── */

	/** The billing item name and its sub-line, apart (the price book wants them apart). */
	public static function split_line( array $line ): array {
		if ( isset( $line['item_name'] ) || isset( $line['desc_sub'] ) ) {
			return [ trim( (string) ( $line['item_name'] ?? '' ) ), trim( (string) ( $line['desc_sub'] ?? '' ) ) ];
		}
		$parts = explode( ' — ', (string) ( $line['description'] ?? '' ), 2 );
		return [ trim( $parts[0] ), trim( $parts[1] ?? '' ) ];
	}

	/** The configured family this line belongs to, '' when none. */
	public static function family_of( array $line ): string {
		if ( ! class_exists( 'ZDZ_Price_Book' ) ) {
			return '';
		}
		list( $name, $sub ) = self::split_line( $line );
		foreach ( array_keys( self::get()['families'] ) as $fam ) {
			if ( ZDZ_Price_Book::is_family_line( (string) $fam, $name, $sub ) ) {
				return (string) $fam;
			}
		}
		return '';
	}

	/**
	 * The supplier cost for a family line, from the live price book. $units counts items (a
	 * "double" match counts two; the larger of the two is used, never both multiplied).
	 */
	public static function family_cost( string $family, array $line, int $units ): array {
		$none = [ 'cost' => 0.0, 'sku' => null, 'kind' => '', 'assumed' => [], 'why' => '', 'fee' => false ];
		if ( ! class_exists( 'ZDZ_Price_Book' ) ) {
			return array_merge( $none, [ 'why' => 'no price book' ] );
		}
		list( $name, $sub ) = self::split_line( $line );
		$m = ZDZ_Price_Book::match_line( $family, [ 'description' => $name, 'sub_description' => $sub, 'dimensions' => '' ] );
		if ( empty( $m['matched'] ) || empty( $m['sku'] ) ) {
			return array_merge( $none, [ 'why' => (string) ( $m['reason'] ?? 'not matched' ), 'kind' => (string) ( $m['kind'] ?? '' ) ] );
		}
		$billed = (float) ( $line['unit_cost'] ?? 0 );
		$book   = (float) ( $m['price'] ?? 0 );
		if ( $billed > 0 && $book > 0 && $billed < 0.5 * $book ) {
			return array_merge( $none, [ 'fee' => true, 'why' => 'billed under half the book price: a fee or upcharge' ] );
		}
		$each = ZDZ_Price_Book::cost( (string) $m['sku'] );
		if ( null === $each || $each <= 0 ) {
			return array_merge( $none, [ 'why' => 'no cost in the price book' ] );
		}
		$n = max( 1, $units, ! empty( $m['double'] ) ? 2 : 1 );
		return [
			'cost'    => round( $each * $n, 2 ),
			'each'    => round( (float) $each, 2 ),
			'units'   => $n,
			'sku'     => (string) $m['sku'],
			'kind'    => (string) ( $m['kind'] ?? '' ),
			'assumed' => (array) ( $m['assumed'] ?? [] ),
			'why'     => '',
			'fee'     => false,
		];
	}

	/**
	 * Called by ZCC_Calc_Engine for each priced commissionable line, after its usual cost.
	 *
	 * @return array{applied:bool,cogs:float,source:string,steps:string[],material:array,flag?:string}
	 */
	public static function adjust_line( array $inv, array $line, array $cls, float $cogs, int $qty ): array {
		$out = [ 'applied' => false, 'cogs' => $cogs, 'source' => '', 'steps' => [], 'material' => [] ];
		if ( ! self::applies( $inv ) ) {
			return $out;
		}
		$cfg      = self::get();
		$family   = self::family_of( $line ); // phpcs:ignore PHPCompatibility.Extensions.RemovedExtensions.famRemoved -- self::family_of() is this class's own method, not the removed fam extension
		$steps    = [];
		$material = [];
		$new      = $cogs;

		if ( '' !== $family && $new <= 0.0001 ) {
			$fc = self::family_cost( $family, $line, $qty ); // phpcs:ignore PHPCompatibility.Extensions.RemovedExtensions.famRemoved -- self::family_cost() is this class's own method, not the removed fam extension
			if ( $fc['cost'] > 0 ) {
				$new     = $fc['cost'];
				$steps[] = sprintf( 'Supplier cost $%s%s, from the price book', number_format( $fc['cost'], 2 ), $fc['units'] > 1 ? ' (' . $fc['units'] . ' × $' . number_format( $fc['each'], 2 ) . ')' : '' );
				if ( $fc['assumed'] ) {
					$steps[] = 'Assumed: ' . implode( '; ', array_map( 'strval', $fc['assumed'] ) );
				}
				$material['dealer'] = $fc['cost'];
				$material['sku']    = $fc['sku'];
			} elseif ( empty( $fc['fee'] ) ) {
				$out['flag'] = sprintf( 'A %s line with no price book cost (%s), so $0 material cost was used. Check the size or the product on the invoice.', $family, '' !== $fc['why'] ? $fc['why'] : 'not matched' );
			}
		}

		$item_id   = (string) ( $cls['item_id'] ?? '' );
		$item      = '' !== $item_id ? zcc_item_get( $item_id ) : null;
		$not_price = is_array( $item ) && ( 'service' === ( $item['type'] ?? '' ) || 'percent_of_billed' === (string) ( ZCC_Cost_Book::cost_attrs( $item_id, $item )['cost_type'] ?? '' ) );
		if ( $new > 0.0001 && ! $not_price ) {
			$group = '' !== $family ? (string) ( $cfg['families'][ $family ] ?? 'other' ) : 'other';
			$r     = self::rate_for( $group );
			if ( $r['rate'] > 0 ) {
				$tax = round( $new * $r['rate'] / 100, 2 );
				if ( ! isset( $material['dealer'] ) ) {
					$steps[] = sprintf( 'Material cost $%s', number_format( $new, 2 ) );
				}
				$steps[]   = sprintf( '+ %s%% purchase tax (%s) $%s', rtrim( rtrim( number_format( $r['rate'], 3 ), '0' ), '.' ), $r['area'], number_format( $tax, 2 ) );
				$new      += $tax;
				$material += [ 'tax' => $tax, 'rate' => $r['rate'], 'area' => $r['area'], 'group' => $group ];
			}
		}

		$new = round( $new, 2 );
		if ( abs( $new - $cogs ) < 0.005 ) {
			return $out;
		}
		$out['applied']  = true;
		$out['cogs']     = $new;
		$out['steps']    = $steps;
		$out['material'] = $material + [ 'before' => round( $cogs, 2 ) ];
		$out['source']   = ' + material cost rules (' . implode( '; ', $steps ) . ')';
		return $out;
	}

	/* ── the what-if report ───────────────────────────────────── */

	/**
	 * One person, one month: commission and material cost by today's rules and by the new
	 * ones. Nothing is written: no ledger rows (draft or locked), no audit of a calculation.
	 * $invoices: tests pass them; otherwise the month is read from the billing system.
	 */
	public static function report_row( int $uid, string $ym, ?array $invoices = null ): array {
		if ( ! preg_match( '/^(\d{4})-(\d{2})$/', $ym, $m ) || ! checkdate( (int) $m[2], 1, (int) $m[1] ) ) {
			return [ 'ok' => false, 'error' => 'Bad month.' ];
		}
		if ( null === $invoices ) {
			if ( ! ZCC_FreshBooks::is_connected() ) {
				return [ 'ok' => false, 'error' => 'The billing system is not connected.' ];
			}
			$start    = $ym . '-01';
			$invoices = ZCC_FreshBooks::get_invoices( $start, gmdate( 'Y-m-t', strtotime( $start ) ), [ 'paid', 'partial' ] );
		}
		$mine = ZCC_Attribution::partition( $invoices, $uid )['matched'];

		$prev_force = self::$force;
		$prev_dry   = ZCC_Calc_Engine::$no_ledger;
		ZCC_Calc_Engine::$no_ledger = true;
		try {
			self::$force = 'off';
			$before      = ZCC_Calc_Engine::calculate( $mine, $uid, $ym );
			self::$force = 'on';
			$after       = ZCC_Calc_Engine::calculate( $mine, $uid, $ym );
		} finally {
			self::$force                = $prev_force;
			ZCC_Calc_Engine::$no_ledger = $prev_dry;
		}
		$pick = static function ( array $r ): array {
			$s = $r['summary'] ?? [];
			return [
				'commission' => round( (float) ( $s['total_commission'] ?? 0 ), 2 ),
				'cogs'       => round( (float) ( $s['total_cogs'] ?? 0 ), 2 ),
				'invoices'   => count( (array) ( $r['invoices'] ?? [] ) ),
			];
		};
		$u = function_exists( 'get_userdata' ) ? get_userdata( $uid ) : null;
		return [
			'ok'     => true,
			'uid'    => $uid,
			'name'   => ( $u && ! empty( $u->display_name ) ) ? $u->display_name : ( '#' . $uid ),
			'month'  => $ym,
			'before' => $pick( $before ),
			'after'  => $pick( $after ),
		];
	}
}
