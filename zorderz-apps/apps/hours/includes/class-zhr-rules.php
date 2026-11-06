<?php
/**
 * ZHR_Rules -- the parts of Hours that depend on where and how a business works, as data.
 *
 * Meal periods, overtime and the pay period differ from one jurisdiction and one business to the next, so none of them
 * is built in. Each ships EMPTY and is set by the business (Hours → Settings, or a pack writing the option zhr_settings):
 *
 *   meals       ['periods' => [ up to two rules ], 'premium_minutes' => int]
 *               A rule: ['after' => minutes worked, 'length' => minutes, 'waive_up_to' => minutes | null,
 *               'waive_if_previous_not_waived' => bool]. "A day over `after` minutes worked needs a meal of at least
 *               `length` minutes starting by the end of minute `after`." A signed waiver covers the rule on a day of
 *               at most `waive_up_to` minutes (null: never waivable); the second rule's waiver may require that the first
 *               was not waived. premium_minutes: the extra pay time a day owes when a required meal was missed, late or
 *               short and the person did not say it was their own choice (at most once a day); 0 = none.
 *               No periods = no meal is ever required, asked about or owed. Nothing is ever deducted either way: a meal is
 *               time off the clock, recorded by clocking out for it.
 *   overtime    ['daily_after' => minutes | null, 'weekly_after' => minutes | null]. Minutes worked past either line are
 *               reported as overtime (the weekly line counts only time not already overtime that day). Null = no line.
 *               This is the record the office and the accountant read; payroll decides the pay.
 *   pay_period  ['kind' => '' | weekly | biweekly | semimonthly | monthly, 'anchor' => 'Y-m-d' (the first day of any one
 *               biweekly period)]. '' = no pay period: the period chips offer weeks and months only.
 *
 * Everything here is pure (no database, no WordPress), so each decision is tested on its own.
 *
 * @package Zorderz\Hours
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZHR_Rules {

	/** At most this many meal periods a day (the meal answers and waivers are kept per period number). */
	const MAX_MEALS = 2;

	/** The pay-period kinds a business may choose. '' = none. */
	const PAY_KINDS = array( 'weekly', 'biweekly', 'semimonthly', 'monthly' );

	/* ------------------------------------------------------------------ cleaning what is stored */

	/** Whole minutes from 1 to 24 hours, or null. */
	private static function minutes_or_null( $v ) {
		if ( null === $v || '' === $v || false === $v || ! is_numeric( $v ) ) {
			return null;
		}
		$n = (int) round( (float) $v );
		return ( $n >= 1 && $n <= 1440 ) ? $n : null;
	}

	/** Same, for a week (up to 168 hours). */
	private static function week_minutes_or_null( $v ) {
		if ( null === $v || '' === $v || false === $v || ! is_numeric( $v ) ) {
			return null;
		}
		$n = (int) round( (float) $v );
		return ( $n >= 1 && $n <= 10080 ) ? $n : null;
	}

	/** The meal rules, cleaned: rules in order of `after`, each sane; anything unreadable is dropped (no rule). */
	public static function clean_meals( $in ) {
		$in      = is_array( $in ) ? $in : array();
		$periods = array();
		foreach ( array_slice( is_array( $in['periods'] ?? null ) ? $in['periods'] : array(), 0, self::MAX_MEALS ) as $p ) {
			if ( ! is_array( $p ) ) {
				continue;
			}
			$after  = self::minutes_or_null( $p['after'] ?? null );
			$length = self::minutes_or_null( $p['length'] ?? null );
			if ( null === $after || null === $length || $length > 240 ) {
				continue;
			}
			$waive = self::minutes_or_null( $p['waive_up_to'] ?? null );
			if ( null !== $waive && $waive <= $after ) {
				$waive = null; // a waiver that could never apply (the meal is only needed past `after`) is no waiver
			}
			$periods[] = array(
				'after'                        => $after,
				'length'                       => $length,
				'waive_up_to'                  => $waive,
				'waive_if_previous_not_waived' => ! empty( $p['waive_if_previous_not_waived'] ),
			);
		}
		usort( $periods, function ( $a, $b ) { return $a['after'] <=> $b['after']; } );
		$prem = isset( $in['premium_minutes'] ) && is_numeric( $in['premium_minutes'] ) ? (int) round( (float) $in['premium_minutes'] ) : 0;
		return array(
			'periods'         => $periods,
			'premium_minutes' => $periods ? max( 0, min( 480, $prem ) ) : 0,
		);
	}

	public static function clean_overtime( $in ) {
		$in = is_array( $in ) ? $in : array();
		return array(
			'daily_after'  => self::minutes_or_null( $in['daily_after'] ?? null ),
			'weekly_after' => self::week_minutes_or_null( $in['weekly_after'] ?? null ),
		);
	}

	public static function clean_pay_period( $in ) {
		$in     = is_array( $in ) ? $in : array();
		$kind   = in_array( (string) ( $in['kind'] ?? '' ), self::PAY_KINDS, true ) ? (string) $in['kind'] : '';
		$anchor = (string) ( $in['anchor'] ?? '' );
		if ( ! self::is_date( $anchor ) ) {
			$anchor = '';
		}
		if ( 'biweekly' === $kind && '' === $anchor ) {
			$kind = ''; // a two-week period needs a first day to count from; without one there is no period
		}
		return array( 'kind' => $kind, 'anchor' => 'biweekly' === $kind ? $anchor : '' );
	}

	/** Any meal period configured? */
	public static function has_meals( array $settings ) {
		return ! empty( $settings['meals']['periods'] );
	}

	/** Any overtime line configured? */
	public static function has_overtime( array $settings ) {
		$o = $settings['overtime'] ?? array();
		return null !== ( $o['daily_after'] ?? null ) || null !== ( $o['weekly_after'] ?? null );
	}

	/** The shortest meal any rule asks for (the floor for a meal break recorded after the fact), or 0. */
	public static function meal_min_length( array $settings ) {
		$min = 0;
		foreach ( (array) ( $settings['meals']['periods'] ?? array() ) as $p ) {
			$min = 0 === $min ? (int) $p['length'] : min( $min, (int) $p['length'] );
		}
		return $min;
	}

	/* ------------------------------------------------------------------ dates (no time zone needed: dates only) */

	public static function is_date( $d ) {
		if ( ! is_string( $d ) || ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m ) ) {
			return false;
		}
		return checkdate( (int) $m[2], (int) $m[3], (int) $m[1] );
	}

	private static function day( $date ) {
		return new DateTimeImmutable( $date . ' 12:00:00', new DateTimeZone( 'UTC' ) );
	}

	private static function plus( $date, $n ) {
		return self::day( $date )->modify( ( $n >= 0 ? '+' : '' ) . (int) $n . ' days' )->format( 'Y-m-d' );
	}

	/** The first day of the week holding $date, for a week starting on ISO day $ws (1 = Monday … 7 = Sunday). */
	public static function week_of( $date, $ws ) {
		$ws   = max( 1, min( 7, (int) $ws ) );
		$back = ( (int) self::day( $date )->format( 'N' ) - $ws + 7 ) % 7;
		return self::plus( $date, -$back );
	}

	/* ------------------------------------------------------------------ the pay period */

	/**
	 * The pay period holding $today, or the one $back periods before it: [from, to], or null when the business has no pay
	 * period (or a biweekly one with no first day). $ws: the week's first ISO day, for weekly periods.
	 */
	public static function pay_period( array $pp, $today, $ws = 1, $back = 0 ) {
		$pp   = self::clean_pay_period( $pp );
		$back = max( 0, (int) $back );
		if ( '' === $pp['kind'] || ! self::is_date( $today ) ) {
			return null;
		}
		switch ( $pp['kind'] ) {
			case 'weekly':
				$s = self::plus( self::week_of( $today, $ws ), -7 * $back );
				return array( $s, self::plus( $s, 6 ) );
			case 'biweekly':
				$a    = self::day( $pp['anchor'] );
				$diff = (int) floor( ( self::day( $today )->getTimestamp() - $a->getTimestamp() ) / 86400 );
				$n    = (int) floor( $diff / 14 );   // negative before the anchor: periods count back from it too
				$s    = self::plus( $pp['anchor'], 14 * ( $n - $back ) );
				return array( $s, self::plus( $s, 13 ) );
			case 'semimonthly':
				$d    = self::day( $today );
				$half = (int) $d->format( 'j' ) <= 15 ? 0 : 1;
				$idx  = (int) $d->format( 'Y' ) * 24 + ( (int) $d->format( 'n' ) - 1 ) * 2 + $half - $back;
				$y    = intdiv( $idx, 24 );
				$m    = intdiv( $idx % 24, 2 ) + 1;
				$h    = $idx % 2;
				$first = sprintf( '%04d-%02d-01', $y, $m );
				$last  = self::day( $first )->format( 'Y-m-t' );
				return 0 === $h ? array( $first, sprintf( '%04d-%02d-15', $y, $m ) ) : array( sprintf( '%04d-%02d-16', $y, $m ), $last );
			case 'monthly':
				$f = self::day( substr( $today, 0, 8 ) . '01' )->modify( '-' . $back . ' months' );
				return array( $f->format( 'Y-m-01' ), $f->format( 'Y-m-t' ) );
		}
		return null;
	}

	/* ------------------------------------------------------------------ overtime */

	/**
	 * Overtime over a run of days. $days: [ ['date' => 'Y-m-d', 'worked' => minutes], … ] (any order; a day appears once).
	 * $ot: ['daily_after', 'weekly_after'] (null = no line). $ws: the week's first ISO day.
	 * Returns ['days' => [date => ['regular' => n, 'overtime' => n]], 'regular' => total, 'overtime' => total].
	 * The weekly line counts each week's regular minutes in date order; minutes already overtime that day never count
	 * twice. Give whole weeks for a weekly line: a week cut short at the start counts only the days it was given.
	 */
	public static function overtime( array $days, array $ot, $ws = 1 ) {
		$ot     = self::clean_overtime( $ot );
		$daily  = $ot['daily_after'];
		$weekly = $ot['weekly_after'];
		usort( $days, function ( $a, $b ) { return strcmp( (string) $a['date'], (string) $b['date'] ); } );
		$out  = array( 'days' => array(), 'regular' => 0, 'overtime' => 0 );
		$week = array();
		foreach ( $days as $d ) {
			$date = (string) ( $d['date'] ?? '' );
			if ( ! self::is_date( $date ) ) {
				continue;
			}
			$worked = max( 0, (int) ( $d['worked'] ?? 0 ) );
			$reg    = $worked;
			$over   = 0;
			if ( null !== $daily && $worked > $daily ) {
				$over = $worked - $daily;
				$reg  = $daily;
			}
			if ( null !== $weekly ) {
				$w    = self::week_of( $date, $ws );
				$room = max( 0, $weekly - ( $week[ $w ] ?? 0 ) );
				if ( $reg > $room ) {
					$over += $reg - $room;
					$reg   = $room;
				}
				$week[ $w ] = ( $week[ $w ] ?? 0 ) + $reg;
			}
			$out['days'][ $date ] = array( 'regular' => $reg, 'overtime' => $over );
			$out['regular']      += $reg;
			$out['overtime']     += $over;
		}
		return $out;
	}

	/* ------------------------------------------------------------------ the meal rules in words */

	/** 300 -> 'the 5th hour'; 330 -> '5 h 30 min'. */
	public static function after_words( $after ) {
		$after = (int) $after;
		if ( 0 === $after % 60 ) {
			$h   = intdiv( $after, 60 );
			$suf = ( $h % 100 >= 11 && $h % 100 <= 13 ) ? 'th' : ( array( 'th', 'st', 'nd', 'rd' )[ $h % 10 ] ?? 'th' );
			/* translators: %s: an ordinal such as "5th" */
			return sprintf( __( 'the %s hour', 'zorderz' ), $h . $suf );
		}
		/* translators: 1: hours, 2: minutes */
		return sprintf( __( '%1$d h %2$d min', 'zorderz' ), intdiv( $after, 60 ), $after % 60 );
	}

	/** 360 -> '6 hours'; 390 -> '6 h 30 min'; 30 -> '30 minutes'. */
	public static function length_words( $min ) {
		$min = (int) $min;
		if ( $min < 60 ) {
			/* translators: %d: a number of minutes */
			return sprintf( _n( '%d minute', '%d minutes', $min, 'zorderz' ), $min );
		}
		if ( 0 === $min % 60 ) {
			$h = intdiv( $min, 60 );
			/* translators: %d: a number of hours */
			return sprintf( _n( '%d hour', '%d hours', $h, 'zorderz' ), $h );
		}
		/* translators: 1: hours, 2: minutes */
		return sprintf( __( '%1$d h %2$d min', 'zorderz' ), intdiv( $min, 60 ), $min % 60 );
	}

	/** The business's meal rules as plain sentences, for the help sheet and the settings (empty: no rules). */
	public static function meal_words( array $meals ) {
		$m   = self::clean_meals( $meals );
		$out = array();
		foreach ( $m['periods'] as $i => $p ) {
			$line = sprintf(
				/* translators: 1: a length such as "5 hours", 2: minutes, 3: an hour such as "the 5th hour" */
				__( 'On a day over %1$s worked, a meal break of %2$d minutes or more starting by the end of %3$s.', 'zorderz' ),
				self::length_words( $p['after'] ),
				$p['length'],
				self::after_words( $p['after'] )
			);
			if ( null !== $p['waive_up_to'] ) {
				$line .= ' ' . sprintf(
					/* translators: %s: a length such as "6 hours" */
					__( 'A signed waiver can cover it on a day of %s or less', 'zorderz' ),
					self::length_words( $p['waive_up_to'] )
				) . ( $i > 0 && $p['waive_if_previous_not_waived'] ? ' ' . __( 'when the meal before it was not waived', 'zorderz' ) : '' ) . '.';
			}
			$out[] = $line;
		}
		if ( $m['periods'] && $m['premium_minutes'] > 0 ) {
			$out[] = sprintf(
				/* translators: %s: a length such as "1 hour" */
				__( 'A required meal break that was missed, late or short, unless the person chose that, owes %s of extra pay for the day (at most once a day).', 'zorderz' ),
				self::length_words( $m['premium_minutes'] )
			);
		}
		return $out;
	}
}
