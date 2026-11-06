<?php
/**
 * ZAGR_Business_Days - business-day arithmetic for one legal pack's definition. [CORE]
 *
 * A pack's rules.json describes what a "business day" is under its law:
 *
 *   excluded_weekdays  ISO weekday numbers that never count (7 = Sunday)
 *   holidays           the named holidays, each one of:
 *                        { month, day }            a fixed date (Independence Day: 7, 4)
 *                        { month, weekday, nth }   the nth weekday of the month (Thanksgiving: 11, 4, 4);
 *                                                  nth -1 is the last one (Memorial Day: 5, 1, -1)
 *                      with optional from_year / until_year for a holiday that started or ended in a given year
 *   observed           "also" (default): a fixed-date holiday that falls on a Saturday also takes the Friday before
 *                      out of the count, and one on a Sunday the Monday after. "none": only the date itself.
 *
 * Counting both the holiday and the weekday it is observed on never gives an earlier deadline than the law's own
 * definition, so a cancel-by date from here is the later, safer one when a definition is silent about observed days.
 *
 * Pure: no WordPress, no clock, no time zone. Dates in and out are 'Y-m-d' strings; the caller decides which local
 * day "today" is.
 *
 * @package Zorderz\Agreements
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZAGR_Business_Days {

	/** The longest window any rule may ask for. Anything longer is a broken pack, refused. */
	const MAX_DAYS = 30;

	/** @var array cache: definition hash. year => holiday dates */
	private static $cache = array();

	/** A real calendar date written Y-m-d, between 2000 and 2100. */
	public static function valid( string $ymd ): bool {
		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $ymd, $m ) ) {
			return false;
		}
		return checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) && (int) $m[1] >= 2000 && (int) $m[1] <= 2100;
	}

	/** The date a holiday rule gives in year $y, or '' when the rule doesn't apply that year (or is malformed). */
	public static function rule_date( array $h, int $y ): string {
		if ( isset( $h['from_year'] ) && $y < (int) $h['from_year'] ) {
			return '';
		}
		if ( isset( $h['until_year'] ) && $y > (int) $h['until_year'] ) {
			return '';
		}
		$month = (int) ( $h['month'] ?? 0 );
		if ( $month < 1 || $month > 12 ) {
			return '';
		}
		if ( isset( $h['day'] ) ) {
			$day = (int) $h['day'];
			return checkdate( $month, $day, $y ) ? sprintf( '%04d-%02d-%02d', $y, $month, $day ) : '';
		}
		$wd  = (int) ( $h['weekday'] ?? 0 );   // ISO: 1 Monday … 7 Sunday
		$nth = (int) ( $h['nth'] ?? 0 );
		if ( $wd < 1 || $wd > 7 || 0 === $nth || $nth > 5 || $nth < -1 ) {
			return '';
		}
		$utc = new DateTimeZone( 'UTC' );
		if ( $nth > 0 ) {
			$first = new DateTimeImmutable( sprintf( '%04d-%02d-01', $y, $month ), $utc );
			$shift = ( $wd - (int) $first->format( 'N' ) + 7 ) % 7;
			$d     = $first->modify( '+' . ( $shift + 7 * ( $nth - 1 ) ) . ' day' );
		} else {
			$last  = ( new DateTimeImmutable( sprintf( '%04d-%02d-01', $y, $month ), $utc ) )->modify( 'last day of this month' );
			$shift = ( (int) $last->format( 'N' ) - $wd + 7 ) % 7;
			$d     = $last->modify( '-' . $shift . ' day' );
		}
		return (int) $d->format( 'n' ) === $month ? $d->format( 'Y-m-d' ) : '';
	}

	/** Every date year $y takes out of the count under $def: the holidays, and their observed weekdays. */
	public static function holidays( array $def, int $y ): array {
		$key = md5( (string) json_encode( array( $def['holidays'] ?? array(), $def['observed'] ?? 'also' ) ) ) . '|' . $y;
		if ( isset( self::$cache[ $key ] ) ) {
			return self::$cache[ $key ];
		}
		$also = 'none' !== ( $def['observed'] ?? 'also' );
		$out  = array();
		$utc  = new DateTimeZone( 'UTC' );
		foreach ( (array) ( $def['holidays'] ?? array() ) as $h ) {
			if ( ! is_array( $h ) ) {
				continue;
			}
			$d = self::rule_date( $h, $y );
			if ( '' === $d ) {
				continue;
			}
			$out[] = $d;
			if ( $also && isset( $h['day'] ) ) {
				$dt = new DateTimeImmutable( $d, $utc );
				$n  = (int) $dt->format( 'N' );
				if ( 6 === $n ) {
					$out[] = $dt->modify( '-1 day' )->format( 'Y-m-d' );   // Saturday: the Friday before (31 Dec for 1 Jan)
				} elseif ( 7 === $n ) {
					$out[] = $dt->modify( '+1 day' )->format( 'Y-m-d' );   // Sunday: the Monday after
				}
			}
		}
		$out = array_values( array_unique( $out ) );
		sort( $out );
		return self::$cache[ $key ] = $out;
	}

	/** Does $ymd count as a business day under $def? */
	public static function is_business_day( array $def, string $ymd ): bool {
		if ( ! self::valid( $ymd ) ) {
			return false;
		}
		$d    = new DateTimeImmutable( $ymd, new DateTimeZone( 'UTC' ) );
		$days = array_map( 'intval', (array) ( $def['excluded_weekdays'] ?? array( 7 ) ) );
		if ( in_array( (int) $d->format( 'N' ), $days, true ) ) {
			return false;
		}
		$y = (int) $d->format( 'Y' );
		// a 1 January on a Saturday is observed on 31 December of the year before: look at next year's list too
		return ! in_array( $ymd, self::holidays( $def, $y ), true ) && ! in_array( $ymd, self::holidays( $def, $y + 1 ), true );
	}

	/**
	 * The $n-th business day after $from_ymd (the day itself never counts). '' for a bad date, or $n outside 1 to
	 * MAX_DAYS.
	 */
	public static function add( array $def, string $from_ymd, int $n ): string {
		if ( ! self::valid( $from_ymd ) || $n < 1 || $n > self::MAX_DAYS ) {
			return '';
		}
		$d     = new DateTimeImmutable( $from_ymd, new DateTimeZone( 'UTC' ) );
		$count = 0;
		$guard = 0;
		while ( $count < $n && $guard < 400 ) {
			$d = $d->modify( '+1 day' );
			$guard++;
			if ( self::is_business_day( $def, $d->format( 'Y-m-d' ) ) ) {
				$count++;
			}
		}
		return $count === $n ? $d->format( 'Y-m-d' ) : '';
	}

	/** Forget cached holiday lists (the suites load more than one definition). */
	public static function forget(): void {
		self::$cache = array();
	}
}
