<?php
/**
 * ZANA_Ledger_Periods — the periods a question names, in the site's time zone. Pure (a clock and a zone are injected).
 *
 * period_in() reads "this year", "last month", "Q3 2025", "the last 90 days", "since 2024", "March", "2025" and the
 * like into array( from Y-m-d, to Y-m-d, label ). It answers null when the question names no period, and false when it
 * names one this reader cannot place exactly ("before June 2", "the first half of 2026", "next month", two periods):
 * such a question goes to the chat's planner instead of being answered for a different period. A period still running
 * ends today and says "so far".
 *
 * month_window() is the home figures' month: its dates (for the ledger's date columns) and its edges in UTC (for the
 * CRM's UTC times).
 *
 * @package Zorderz\Analytics
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZANA_Ledger_Periods {

	/** Injected in tests: unix "now" and the zone. */
	public static $now = null;
	public static $tz  = null;

	/** How far back a home-figures month may be asked for. */
	const MAX_MONTHS_BACK = 36;

	const MONTHS = array( 'january' => 1, 'february' => 2, 'march' => 3, 'april' => 4, 'may' => 5, 'june' => 6, 'july' => 7,
		'august' => 8, 'september' => 9, 'october' => 10, 'november' => 11, 'december' => 12, 'jan' => 1, 'feb' => 2, 'mar' => 3,
		'apr' => 4, 'jun' => 6, 'jul' => 7, 'aug' => 8, 'sep' => 9, 'sept' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12 );

	const NUMBER_WORDS = array( 'one' => 1, 'two' => 2, 'three' => 3, 'four' => 4, 'five' => 5, 'six' => 6, 'seven' => 7, 'eight' => 8,
		'nine' => 9, 'ten' => 10, 'twelve' => 12, 'eighteen' => 18, 'thirty' => 30, 'ninety' => 90 );

	public static function tz(): DateTimeZone {
		if ( self::$tz instanceof DateTimeZone ) {
			return self::$tz;
		}
		return function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );
	}

	public static function now(): int {
		return null !== self::$now ? (int) self::$now : time();
	}

	/** A local calendar day, Y-m-d, $offset days from today. */
	public static function day( int $offset = 0 ): string {
		$d = new DateTime( '@' . self::now() );
		$d->setTimezone( self::tz() );
		if ( $offset ) {
			$d->modify( ( $offset > 0 ? '+' : '' ) . $offset . ' days' );
		}
		return $d->format( 'Y-m-d' );
	}

	public static function today(): string {
		return self::day();
	}

	/** The first day of a rolling window of $months months ending on $today (Aug 31 back 6 -> Mar 1). */
	public static function back( string $today, int $months ): string {
		$y  = (int) substr( $today, 0, 4 );
		$mo = (int) substr( $today, 5, 2 ) - $months;
		$d  = (int) substr( $today, 8, 2 );
		while ( $mo < 1 ) {
			$mo += 12;
			$y--;
		}
		$last = (int) gmdate( 't', gmmktime( 0, 0, 0, $mo, 1, $y ) );
		return gmdate( 'Y-m-d', gmmktime( 0, 0, 0, $mo, min( $d, $last ) + 1, $y ) );
	}

	/** A Y-m-d moved by whole months, the day clamped to the month's last. */
	public static function shift_months( string $ymd, int $months ): string {
		$y  = (int) substr( $ymd, 0, 4 );
		$mo = (int) substr( $ymd, 5, 2 ) + $months;
		while ( $mo < 1 ) {
			$mo += 12;
			$y--;
		}
		while ( $mo > 12 ) {
			$mo -= 12;
			$y++;
		}
		$d = min( (int) substr( $ymd, 8, 2 ), (int) gmdate( 't', gmmktime( 0, 0, 0, $mo, 1, $y ) ) );
		return sprintf( '%04d-%02d-%02d', $y, $mo, $d );
	}

	/** A Y-m-d moved by whole years (Feb 29 -> Feb 28). */
	public static function shift_year( string $ymd, int $years ): string {
		$y = (int) substr( $ymd, 0, 4 ) + $years;
		$m = (int) substr( $ymd, 5, 2 );
		$d = min( (int) substr( $ymd, 8, 2 ), (int) gmdate( 't', gmmktime( 0, 0, 0, $m, 1, $y ) ) );
		return sprintf( '%04d-%02d-%02d', $y, $m, $d );
	}

	/** The last day of a Y-m-d's month. */
	public static function month_end( string $ymd ): string {
		return gmdate( 'Y-m-t', strtotime( substr( $ymd, 0, 7 ) . '-01 00:00:00 UTC' ) );
	}

	public static function month_name( string $ymd ): string {
		return gmdate( 'F Y', strtotime( substr( $ymd, 0, 7 ) . '-01 00:00:00 UTC' ) );
	}

	/**
	 * The period $m (lower case) names: array( from, to, label ), null for none, false for one this reader can't place.
	 *
	 * @return array|null|false
	 */
	public static function period_in( string $m ) {
		$today = self::day();
		$y     = (int) substr( $today, 0, 4 );
		$mo    = (int) substr( $today, 5, 2 );
		$cut   = function ( $from, $to, $label ) use ( $today ) {
			if ( $from > $today ) {
				return false; // a period that has not started
			}
			$open = $to >= $today;
			return array( $from, $open ? $today : $to, $label . ( $open ? ' so far' : '' ) );
		};
		$num   = function ( $s ) { return is_numeric( $s ) ? (int) $s : (int) ( self::NUMBER_WORDS[ $s ] ?? 0 ); };
		$mrx   = implode( '|', array_keys( self::MONTHS ) );
		$found = array();
		if ( preg_match( '/\b(?:between|before|until|till|prior to)\b|\bafter\s+(?!20\d\d\b)[a-z0-9]/', $m ) ) {
			return false;
		}
		// a part of a period, a day, an hour, a coming period: never widened, never read as all time
		if ( preg_match( '/(?<!first )(?<!second )(?<!third )(?<!fourth )\bquarters?\b|\bquarterly\b|\bhalf\b|\bh[12]\b|\b(?:early|late|mid|middle|beginning|start|end)\b|\bhours?\b|\bminutes?\b|\btonight\b|\bmorning\b|\bafternoon\b|\bevening\b|\b(?:mon|tues|wednes|thurs|fri|satur|sun)days?\b|\bnext\b(?!\s?door)|\bupcoming\b|\bfiscal\b|\bfy\b|\b\d{1,2}(?:st|nd|rd|th)\b|\b(?:' . $mrx . ')\.?\s+\d{1,2}(?!\d)/', $m ) ) {
			return false;
		}
		if ( preg_match( '/\b(?:this year|ytd|year to date|so far this year)\b/', $m ) ) {
			$found[] = $cut( "$y-01-01", "$y-12-31", (string) $y );
		}
		if ( preg_match( '/\blast year\b|\bprevious year\b/', $m ) ) {
			$found[] = $cut( ( $y - 1 ) . '-01-01', ( $y - 1 ) . '-12-31', (string) ( $y - 1 ) );
		}
		if ( preg_match( '/\bthis month\b|\bmonth to date\b|\bmtd\b/', $m ) ) {
			$found[] = $cut( sprintf( '%04d-%02d-01', $y, $mo ), $today, self::month_name( $today ) );
		}
		if ( preg_match( '/\blast month\b|\bprevious month\b/', $m ) ) {
			$f       = self::shift_months( sprintf( '%04d-%02d-01', $y, $mo ), -1 );
			$found[] = $cut( $f, self::month_end( $f ), self::month_name( $f ) );
		}
		if ( preg_match( '/\btoday\b/', $m ) ) {
			$found[] = array( $today, $today, 'today' );
		}
		if ( preg_match( '/\byesterday\b/', $m ) ) {
			$d       = self::day( -1 );
			$found[] = array( $d, $d, 'yesterday' );
		}
		if ( preg_match( '/\bthis week\b/', $m ) ) {
			$dow     = (int) gmdate( 'N', strtotime( $today . ' 12:00:00 UTC' ) );
			$found[] = array( self::day( 1 - $dow ), $today, 'this week (from Monday)' );
		}
		if ( preg_match( '/\b(?:in the |over the )?(?:last|past|previous)\s+(\d{1,3}|one|two|three|four|five|six|seven|eight|nine|ten|twelve|eighteen|thirty|ninety)\s+(days?|weeks?|months?|years?)\b/', $m, $r ) ) {
			$n    = max( 1, $num( $r[1] ) );
			$unit = rtrim( $r[2], 's' );
			$days = 'day' === $unit ? $n : ( 'week' === $unit ? 7 * $n : 0 );
			$lab  = 'the last ' . $n . ' ' . $unit . ( 1 === $n ? '' : 's' );
			$found[] = $days ? array( self::day( 1 - $days ), $today, $lab ) : array( self::back( $today, 'month' === $unit ? $n : 12 * $n ), $today, $lab );
		} elseif ( preg_match( '/\blast week\b|\bprevious week\b/', $m ) ) {
			$dow     = (int) gmdate( 'N', strtotime( $today . ' 12:00:00 UTC' ) );
			$found[] = array( self::day( 1 - $dow - 7 ), self::day( -$dow ), 'last week (Monday to Sunday)' );
		} elseif ( preg_match( '/\bpast (week|month|year)\b/', $m, $r ) ) {
			$f       = 'week' === $r[1] ? self::day( -6 ) : self::back( $today, 'month' === $r[1] ? 1 : 12 );
			$found[] = array( $f, $today, 'the past ' . $r[1] );
		}
		if ( preg_match( '/\b(?:q([1-4])|(first|second|third|fourth) quarter)(?:\s+(?:of\s+)?(20\d\d))?\b/', $m, $r ) ) {
			$q       = '' !== $r[1] ? (int) $r[1] : (int) array_search( $r[2], array( 1 => 'first', 2 => 'second', 3 => 'third', 4 => 'fourth' ), true );
			$qy      = ! empty( $r[3] ) ? (int) $r[3] : $y;
			$f       = sprintf( '%04d-%02d-01', $qy, 3 * $q - 2 );
			$found[] = $cut( $f, self::month_end( sprintf( '%04d-%02d-01', $qy, 3 * $q ) ), 'Q' . $q . ' ' . $qy );
			$m       = str_replace( $r[0], ' ', $m );
		}
		if ( preg_match_all( '/\b(' . $mrx . ')\b(?:\s+(?:of\s+)?(20\d\d))?/', $m, $mm, PREG_SET_ORDER ) ) {
			if ( count( $mm ) > 1 ) {
				return false; // a range of months is the planner's
			}
			$r = $mm[0];
			$n = self::MONTHS[ $r[1] ];
			if ( 'may' === $r[1] && empty( $r[2] ) && ! preg_match( '/\b(?:in|during|for|of|since|from|last|this)\s+may\b/', $m ) ) {
				if ( ! preg_match( '/\bmay\s+(?:i|we|you)\b/', $m ) ) {
					return false; // a bare "may" that could be either
				}
			} else {
				$my = ! empty( $r[2] ) ? (int) $r[2] : ( $n <= $mo ? $y : $y - 1 ); // a month without a year: the latest one
				$f  = sprintf( '%04d-%02d-01', $my, $n );
				if ( preg_match( '/\bsince\s+' . preg_quote( $r[1], '/' ) . '\b/', $m ) ) {
					$found[] = $cut( $f, $today, 'since ' . self::month_name( $f ) );
				} else {
					$found[] = $cut( $f, self::month_end( $f ), self::month_name( $f ) );
				}
				$m = str_replace( $r[0], ' ', $m );
			}
		}
		if ( preg_match( '/\b(20\d\d)\s*(?:-|–|to|through|thru)\s*(20\d\d)\b/', $m, $r ) ) {
			$a       = min( (int) $r[1], (int) $r[2] );
			$b       = max( (int) $r[1], (int) $r[2] );
			$found[] = $cut( "{$a}-01-01", "{$b}-12-31", "{$a}–{$b}" );
		} elseif ( preg_match( '/\bsince\s+(20\d\d)\b|\bfrom\s+(20\d\d)\s+(?:on|onwards?|forward|to (?:now|today|date))\b/', $m, $r ) ) {
			$a       = (int) ( '' !== $r[1] ? $r[1] : $r[2] );
			$found[] = $cut( $a . '-01-01', $today, 'since ' . $a );
		} elseif ( preg_match( '/\bafter\s+(20\d\d)\b/', $m, $r ) ) {
			$a       = (int) $r[1] + 1;
			$found[] = $cut( $a . '-01-01', $today, 'since ' . $a );
		} elseif ( preg_match_all( '/\b(20\d\d)\b/', $m, $r ) ) {
			$ys = array_unique( array_map( 'intval', $r[1] ) );
			if ( count( $ys ) > 1 ) {
				return false; // two years: a comparison
			}
			$a       = (int) reset( $ys );
			$found[] = $cut( "$a-01-01", "$a-12-31", (string) $a );
		}
		$found = array_values( array_filter( $found, function ( $p ) { return null !== $p; } ) );
		if ( in_array( false, $found, true ) || count( $found ) > 1 ) {
			return false;
		}
		if ( $found ) {
			return $found[0];
		}
		if ( preg_match( '/\b\d{1,2}\/\d{1,2}\b|\b(?:spring|summer|fall|autumn|winter|holidays?|weekend|between|before|until|till)\b|\bsince\b/', $m ) ) {
			return false;
		}
		return null;
	}

	/** "September 2026 (Sep 1 – Sep 30, 2026)"; with $dates_only just the dates. */
	public static function label( array $p, bool $dates_only = false ): string {
		$fmt = function ( $d, $f ) { return gmdate( $f, strtotime( $d . ' 12:00:00 UTC' ) ); };
		$a   = $fmt( $p[0], 'M j, Y' );
		$b   = $fmt( $p[1], 'M j, Y' );
		$d   = $p[0] === $p[1] ? $a : ( substr( $p[0], 0, 4 ) === substr( $p[1], 0, 4 ) ? $fmt( $p[0], 'M j' ) . ' – ' . $b : $a . ' – ' . $b );
		return $dates_only ? $d : $p[2] . ' (' . $d . ')';
	}

	/** "2026 so far", "September 2026", "last 30 days (Sep 21 – Oct 20)". */
	public static function short( $p ): string {
		if ( null === $p ) {
			return 'all time';
		}
		if ( preg_match( '/^(?:20\d\d(?: so far)?|[A-Z][a-z]+ 20\d\d(?: so far)?|Q[1-4] 20\d\d(?: so far)?|today|yesterday)$/', (string) $p[2] ) ) {
			return (string) $p[2];
		}
		return (string) preg_replace( '/^the /', '', (string) $p[2] ) . ' (' . self::label( $p, true ) . ')';
	}

	/** A local day's edge as the UTC time the ledger stores for CRM times. */
	public static function utc_edge( string $ymd, bool $end = false ): string {
		$d = new DateTime( $ymd . ( $end ? ' 23:59:59' : ' 00:00:00' ), self::tz() );
		$d->setTimezone( new DateTimeZone( 'UTC' ) );
		return $d->format( 'Y-m-d H:i:s' );
	}

	/**
	 * A calendar month in the site's time zone: { month, from, to, year_from, tz, from_utc, to_utc }, or { error }.
	 * '' = this month. A malformed month, a future month and one more than MAX_MONTHS_BACK back are refused.
	 */
	public static function month_window( string $month ): array {
		$tz  = self::tz();
		$utc = new DateTimeZone( 'UTC' );
		$now = ( new DateTimeImmutable( '@' . self::now() ) )->setTimezone( $tz );
		$cur = $now->format( 'Y-m' );
		if ( '' === $month ) {
			$month = $cur;
		}
		if ( ! preg_match( '/^(\d{4})-(0[1-9]|1[0-2])$/D', $month, $m ) ) {
			return array( 'error' => 'month must be YYYY-MM.' );
		}
		if ( $month > $cur ) {
			return array( 'error' => 'That month has not started yet.' );
		}
		$back = ( (int) substr( $cur, 0, 4 ) - (int) $m[1] ) * 12 + ( (int) substr( $cur, 5, 2 ) - (int) $m[2] );
		if ( $back > self::MAX_MONTHS_BACK ) {
			return array( 'error' => 'That month is too far back.' );
		}
		$start = new DateTimeImmutable( $month . '-01 00:00:00', $tz );
		$next  = $start->modify( 'first day of next month' );
		return array(
			'month'     => $month,
			'from'      => $start->format( 'Y-m-d' ),
			'to'        => $next->modify( '-1 day' )->format( 'Y-m-d' ),
			'year_from' => $start->format( 'Y' ) . '-01-01',
			'tz'        => $tz->getName(),
			'from_utc'  => $start->setTimezone( $utc )->format( 'Y-m-d H:i:s' ),
			'to_utc'    => $next->setTimezone( $utc )->format( 'Y-m-d H:i:s' ),
		);
	}
}
