<?php
/**
 * ZHR_Time -- the rules of Hours, as plain functions (no database, no WordPress calls except the site's time zone).
 *
 * Everything that decides a number a person or the accountant reads is here, so it can be tested on its own:
 *   - minutes between two moments (to the minute; a daylight-saving night counts the real minutes),
 *   - week / month / pay-period bounds (the week starts on the day the business chose),
 *   - the standard day (each weekday its own start and end, or a day off; none until the business sets them),
 *   - meal periods, when the business has any (ZHR_Rules): nothing is ever deducted. A meal is time off the clock,
 *     recorded by clocking out for it. Each day is checked against the business's rules; a missed, late or short meal
 *     is the person's to answer (their own choice, or not provided), and an unanswered or "not provided" one owes the
 *     business's premium for the day. Meal punches are never rounded. See meal_check(),
 *   - what an entry may be (no overlap, no future, no older than the edit window for anyone outside the admin tier),
 *   - how much of an entry the app's own activity backs up (a signal for a person to read, never a verdict),
 *   - the CSV the accountant opens.
 *
 * Times are the site's local wall clock ('Y-m-d H:i:s'), the way the platform's tables store them
 * (current_time('mysql')). A moment is turned into seconds only through the site's time zone.
 *
 * @package Zorderz\Hours
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZHR_Time {

	const MAX_ENTRY_MIN   = 960;   // 16 h: one entry longer than this is not a shift, it is a forgotten clock-out
	                               // (a clock past it is never stopped by the app; a person sets its finish)
	const LONG_ENTRY_MIN  = 840;   // 14 h: flagged "long" (still allowed)
	const FUTURE_SLACK_S  = 300;   // a phone clock a few minutes ahead is not "the future"
	const NOTE_MAX        = 500;
	const MEAL_SPLIT_MAX  = 120;   // a meal break recorded after the fact is at most 2 hours

	/** How long each kind of app activity counts as "here and working", in minutes before and after the moment. */
	const WINDOWS = array(
		'in_app'    => array( 2, 6 ),   // an app opened, a search, a tap the platform logs
		'heartbeat' => array( 1, 5 ),   // the platform's "still here" beat while the app is open
		'login'     => array( 0, 5 ),
		'photo'     => array( 10, 10 ), // a photo taken in the app
		'upload'    => array( 2, 6 ),
		'walk'      => array( 5, 5 ),   // a GPS point from a door-hanger walk
		'job'       => array( 10, 10 ), // a Jobs step (On my way, Start, a note ...)
		'ping'      => array( 1, 4 ),   // Hours' own "the phone is here" note while clocked in (the phone's word only)
	);

	/**
	 * Activity kinds that cover a stretch from `at` to `until` (the phone traced it): a door-hanger walk on foot, and a
	 * stretch of the same walk driven between blocks. Driving is work time, so it backs the clock like walking does; it
	 * is just not door hanging.
	 */
	const SPAN_KINDS = array( 'walk_span', 'drive_span' );

	/* ------------------------------------------------------------------ the clock */

	public static function tz() {
		if ( function_exists( 'wp_timezone' ) ) {
			return wp_timezone();
		}
		return new DateTimeZone( 'UTC' );
	}

	/** 'Y-m-d H:i[:s]' (site time) -> unix seconds, or null. */
	public static function ts( $local ) {
		$local = trim( (string) $local );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(:\d{2})?$/', $local ) ) {
			return null;
		}
		try {
			$d = new DateTimeImmutable( $local, self::tz() );
		} catch ( Exception $e ) {
			return null;
		}
		return $d->getTimestamp();
	}

	/** unix seconds -> 'Y-m-d H:i:s' site time. */
	public static function local( $ts ) {
		return ( new DateTimeImmutable( '@' . (int) $ts ) )->setTimezone( self::tz() )->format( 'Y-m-d H:i:s' );
	}

	/** Whole minutes from $start to $end (local strings); never negative. */
	public static function minutes( $start, $end ) {
		$a = self::ts( $start );
		$b = self::ts( $end );
		if ( null === $a || null === $b || $b <= $a ) {
			return 0;
		}
		return (int) floor( ( $b - $a ) / 60 );
	}

	public static function is_date( $d ) {
		return ZHR_Rules::is_date( $d );
	}

	/** 'H:MM' / 'HH:MM' (24-hour) -> minutes after midnight, or null. */
	public static function hm_to_min( $s ) {
		$s = trim( (string) $s );
		if ( ! preg_match( '/^([01]?\d|2[0-3]):([0-5]\d)$/', $s, $m ) ) {
			return null;
		}
		return (int) $m[1] * 60 + (int) $m[2];
	}

	public static function min_to_hm( $min ) {
		$min = ( (int) $min % 1440 + 1440 ) % 1440;
		return sprintf( '%02d:%02d', intdiv( $min, 60 ), $min % 60 );
	}

	/** 1 (Monday) .. 7 (Sunday). */
	public static function iso_day( $date ) {
		return (int) ( new DateTimeImmutable( $date . ' 12:00:00', self::tz() ) )->format( 'N' );
	}

	public static function add_days( $date, $n ) {
		return ( new DateTimeImmutable( $date . ' 12:00:00', self::tz() ) )->modify( ( $n >= 0 ? '+' : '' ) . (int) $n . ' days' )->format( 'Y-m-d' );
	}

	/** Every date from $from to $to inclusive (at most 400). */
	public static function dates( $from, $to ) {
		$out = array();
		$d   = $from;
		for ( $i = 0; $i < 400 && $d <= $to; $i++ ) {
			$out[] = $d;
			$d     = self::add_days( $d, 1 );
		}
		return $out;
	}

	/** The first day of the week holding $date, for a week that starts on ISO day $week_start (1 = Monday). */
	public static function week_start( $date, $week_start = 1 ) {
		$ws   = max( 1, min( 7, (int) $week_start ) );
		$back = ( self::iso_day( $date ) - $ws + 7 ) % 7;
		return self::add_days( $date, -$back );
	}

	/** The period keys Hours understands; the pay-period ones only when the business has a pay period. */
	public static function period_keys( array $pay = array() ) {
		$keys = array( 'today', 'yesterday', 'this_week', 'last_week', 'this_month', 'last_month', 'this_year', 'custom' );
		if ( '' !== ZHR_Rules::clean_pay_period( $pay )['kind'] ) {
			array_splice( $keys, 4, 0, array( 'this_pay_period', 'last_pay_period' ) );
		}
		return $keys;
	}

	/**
	 * A named period -> [from, to, label]. $key: today, yesterday, this_week, last_week, this_pay_period, last_pay_period,
	 * this_month, last_month, this_year, custom (with $from/$to). Unknown keys, and a pay period the business has not set,
	 * read as this_week. $pay: the business's pay period (ZHR_Rules::clean_pay_period()).
	 */
	public static function period( $key, $today, $week_start = 1, $from = '', $to = '', array $pay = array() ) {
		switch ( $key ) {
			case 'today':
				return array( $today, $today, __( 'Today', 'zorderz' ) );
			case 'yesterday':
				$y = self::add_days( $today, -1 );
				return array( $y, $y, __( 'Yesterday', 'zorderz' ) );
			case 'last_week':
				$s = self::add_days( self::week_start( $today, $week_start ), -7 );
				return array( $s, self::add_days( $s, 6 ), __( 'Last week', 'zorderz' ) );
			case 'this_pay_period':
			case 'last_pay_period':
				$pp = ZHR_Rules::pay_period( $pay, $today, $week_start, 'last_pay_period' === $key ? 1 : 0 );
				if ( $pp ) {
					return array( $pp[0], $pp[1], 'last_pay_period' === $key ? __( 'Last pay period', 'zorderz' ) : __( 'This pay period', 'zorderz' ) );
				}
				break;
			case 'this_month':
				return array( substr( $today, 0, 8 ) . '01', ( new DateTimeImmutable( $today ) )->format( 'Y-m-t' ), __( 'This month', 'zorderz' ) );
			case 'last_month':
				$f = ( new DateTimeImmutable( substr( $today, 0, 8 ) . '01' ) )->modify( '-1 month' );
				return array( $f->format( 'Y-m-01' ), $f->format( 'Y-m-t' ), __( 'Last month', 'zorderz' ) );
			case 'this_year':
				return array( substr( $today, 0, 4 ) . '-01-01', substr( $today, 0, 4 ) . '-12-31', __( 'This year', 'zorderz' ) );
			case 'custom':
				if ( self::is_date( $from ) && self::is_date( $to ) ) {
					if ( $to < $from ) {
						list( $from, $to ) = array( $to, $from );
					}
					if ( count( self::dates( $from, $to ) ) >= 400 ) { // at most 400 days at a time
						$to = self::add_days( $from, 399 );
					}
					return array( $from, $to, $from === $to ? $from : $from . ' – ' . $to );
				}
				break; // a custom period without two dates is this week
		}
		$s = self::week_start( $today, $week_start );
		return array( $s, self::add_days( $s, 6 ), __( 'This week', 'zorderz' ) );
	}

	/* ------------------------------------------------------------------ settings */

	/**
	 * The settings a new install starts with. Nothing here is a business's own choice: no standard hours, no meal, overtime
	 * or pay-period rules, no reminder, no location asked for, the shortest edit window, and Hours held only by the people
	 * the platform already grants it to. $week_start: the site's own first day of the week (ISO 1..7).
	 */
	public static function default_settings( $week_start = 1 ) {
		$days = array();
		for ( $d = 1; $d <= 7; $d++ ) {
			$days[ $d ] = array( 'on' => false, 'start' => '', 'end' => '' );
		}
		return array(
			'week_start'    => max( 1, min( 7, (int) $week_start ) ),
			'days'          => $days,  // standard hours per weekday; a day without them is a day off
			'edit_weeks'    => 1,      // people change their own hours this week; older needs the admin tier
			'ask_location'  => false,  // clock in / out asks the phone where it is (the person can still say no)
			'note_location' => false,  // while clocked in, note where the phone is when the app is used (only if already allowed)
			'everyone'      => false,  // every staff role holds Hours (a per-person deny in User Management still wins)
			'crew_leads'    => false,  // a crew lead may read their crew's hours (as the person sees them; never change them)
			'remind_at'     => '',     // a clock still running at this time (site time) gets one notice; '' = no reminder
			'meals'         => ZHR_Rules::clean_meals( array() ),
			'overtime'      => ZHR_Rules::clean_overtime( array() ),
			'pay_period'    => ZHR_Rules::clean_pay_period( array() ),
		);
	}

	private static function truthy( $v ) {
		return ! ( empty( $v ) || '0' === (string) $v || 'false' === (string) $v );
	}

	/** A clean settings array from anything; unknown keys dropped, every value bounded. */
	public static function clean_settings( $in, $base = null ) {
		$out = is_array( $base ) ? $base : self::default_settings();
		$in  = is_array( $in ) ? $in : array();
		if ( isset( $in['week_start'] ) ) {
			$out['week_start'] = max( 1, min( 7, (int) $in['week_start'] ) );
		}
		if ( isset( $in['days'] ) && is_array( $in['days'] ) ) {
			for ( $d = 1; $d <= 7; $d++ ) {
				$row = $in['days'][ $d ] ?? ( $in['days'][ (string) $d ] ?? null );
				if ( ! is_array( $row ) ) {
					continue;
				}
				$cur = $out['days'][ $d ] ?? array( 'on' => false, 'start' => '', 'end' => '' );
				$s   = self::hm_to_min( $row['start'] ?? '' );
				$e   = self::hm_to_min( $row['end'] ?? '' );
				$on  = self::truthy( $row['on'] ?? false );
				if ( null !== $s && null !== $e && $e > $s ) {
					$cur['start'] = self::min_to_hm( $s );
					$cur['end']   = self::min_to_hm( $e );
				} elseif ( $on ) {
					// a bad time keeps the day as it was; a day that never had good times stays a day off
					$on = ! empty( $cur['on'] ) && null !== self::hm_to_min( $cur['start'] ) && null !== self::hm_to_min( $cur['end'] );
				}
				$cur['on']         = $on;
				$out['days'][ $d ] = $cur;
			}
		}
		foreach ( array( 'ask_location', 'note_location', 'everyone', 'crew_leads' ) as $k ) {
			if ( array_key_exists( $k, $in ) ) {
				$out[ $k ] = self::truthy( $in[ $k ] );
			}
		}
		if ( isset( $in['edit_weeks'] ) ) {
			$out['edit_weeks'] = max( 1, min( 8, (int) $in['edit_weeks'] ) );
		}
		if ( array_key_exists( 'remind_at', $in ) ) {
			$r = trim( (string) $in['remind_at'] );
			if ( '' === $r ) {
				$out['remind_at'] = '';
			} elseif ( null !== self::hm_to_min( $r ) ) {
				$out['remind_at'] = self::min_to_hm( self::hm_to_min( $r ) );
			} // anything else keeps the time it had
		}
		if ( array_key_exists( 'meals', $in ) ) {
			$out['meals'] = ZHR_Rules::clean_meals( $in['meals'] );
		}
		if ( array_key_exists( 'overtime', $in ) ) {
			$out['overtime'] = ZHR_Rules::clean_overtime( $in['overtime'] );
		}
		if ( array_key_exists( 'pay_period', $in ) ) {
			$out['pay_period'] = ZHR_Rules::clean_pay_period( $in['pay_period'] );
		}
		// keep exactly the known keys, days 1..7, every rule set in its clean shape
		$known = self::default_settings( $out['week_start'] ?? 1 );
		foreach ( array_keys( $out ) as $k ) {
			if ( ! array_key_exists( $k, $known ) ) {
				unset( $out[ $k ] );
			}
		}
		foreach ( $known as $k => $v ) {
			if ( ! array_key_exists( $k, $out ) ) {
				$out[ $k ] = $v;
			}
		}
		for ( $d = 1; $d <= 7; $d++ ) {
			if ( ! isset( $out['days'][ $d ] ) ) {
				$out['days'][ $d ] = $known['days'][ $d ];
			}
		}
		ksort( $out['days'] );
		$out['meals']      = ZHR_Rules::clean_meals( $out['meals'] );
		$out['overtime']   = ZHR_Rules::clean_overtime( $out['overtime'] );
		$out['pay_period'] = ZHR_Rules::clean_pay_period( $out['pay_period'] );
		return $out;
	}

	/** The business has set standard hours for at least one day. */
	public static function has_standard( $settings ) {
		foreach ( (array) ( $settings['days'] ?? array() ) as $row ) {
			if ( ! empty( $row['on'] ) ) {
				return true;
			}
		}
		return false;
	}

	/** The standard day for $date: ['off' => bool, 'start' => 'HH:MM', 'end' => 'HH:MM', 'minutes' => paid minutes]. */
	public static function standard_day( $settings, $date ) {
		$d   = self::iso_day( $date );
		$row = $settings['days'][ $d ] ?? null;
		$s   = is_array( $row ) ? self::hm_to_min( $row['start'] ?? '' ) : null;
		$e   = is_array( $row ) ? self::hm_to_min( $row['end'] ?? '' ) : null;
		if ( ! is_array( $row ) || empty( $row['on'] ) || null === $s || null === $e ) {
			return array( 'off' => true, 'start' => '', 'end' => '', 'minutes' => 0 );
		}
		$span = max( 0, $e - $s );
		$paid = $span;
		// with a meal rule, a standard day long enough to need the first meal and hold it has the meal inside it, off the
		// clock (a day exactly as long as the rule's hours plus the meal needs no meal; a shorter one has no room for it)
		$first = $settings['meals']['periods'][0] ?? null;
		if ( is_array( $first ) && $span > (int) $first['after'] + (int) $first['length'] ) {
			$paid = max( 0, $span - (int) $first['length'] );
		}
		return array( 'off' => false, 'start' => $row['start'], 'end' => $row['end'], 'minutes' => $paid );
	}

	/* ------------------------------------------------------------------ a day's total */

	/**
	 * One day's numbers from its entries (each ['start_at','end_at' or null,'minutes']; a running entry counts to $now_ts).
	 * Nothing is deducted: worked = the minutes on the clock. $meal: ['waiver1' => bool|periods, 'waiver2' => …,
	 * 'answers' => [1 => 'voluntary'|'not_provided'|['answer','about'], 2 => …]] for the meal check (see meal_check()).
	 */
	public static function day_totals( $settings, $date, array $entries, $now_ts, array $meal = array() ) {
		$raw     = 0;
		$running = false;
		$spans   = array();
		$open    = 0; // shifts with no finish a person has set (past 16 hours, or left with the office): counted as nothing
		foreach ( $entries as $e ) {
			$s = self::ts( $e['start_at'] ?? '' );
			if ( null === $s ) {
				continue;
			}
			if ( empty( $e['end_at'] ) && 'live' !== self::open_state( $e, $now_ts ) ) {
				$open++;
				continue;
			}
			if ( empty( $e['end_at'] ) ) {
				$running = true;
				$end     = max( $s, (int) $now_ts );
			} else {
				$end = self::ts( $e['end_at'] );
				if ( null === $end ) {
					continue;
				}
			}
			// a closed entry counts its stored minutes: they were taken from the real moments (the night the clocks go
			// back, 01:30 to 01:10 the second time round is 40 minutes though the wall clock went backwards)
			$m       = ( ! empty( $e['end_at'] ) && isset( $e['minutes'] ) && (int) $e['minutes'] > 0 ) ? (int) $e['minutes'] : (int) floor( max( 0, $end - $s ) / 60 );
			$end     = max( $end, $s + $m * 60 );
			$raw    += $m;
			$spans[] = array( $s, $end, $m );
		}
		usort( $spans, function ( $a, $b ) { return $a[0] <=> $b[0]; } );
		$std     = self::standard_day( $settings, $date );
		$waivers = array(
			1 => self::waiver_on( $meal['waiver1'] ?? false, $date ),
			2 => self::waiver_on( $meal['waiver2'] ?? false, $date ),
		);
		return array(
			'date'       => $date,
			'raw'        => $raw,
			'worked'     => $raw,
			'standard'   => $std['minutes'],
			'off_day'    => $std['off'] && self::has_standard( $settings ), // with no standard hours set, no day is "a day off"
			'std_start'  => $std['start'],
			'std_end'    => $std['end'],
			'running'    => $running,
			'entries'    => count( $spans ),
			'unfinished' => $open,
			// a day whose end is not known yet is not judged (as a day still on the clock is not)
			'meals'      => self::meal_check( $spans, $waivers, (array) ( $meal['answers'] ?? array() ), $running || $open > 0, (array) ( $settings['meals'] ?? array() ) ),
		);
	}

	/**
	 * Was a waiver in force on $date? $w: true/false, or the periods it was on file: [[from, to|null], …] (from = the day
	 * it was signed; to = the day it was revoked, not covered). A waiver signed today never reaches back.
	 */
	public static function waiver_on( $w, $date ) {
		if ( ! is_array( $w ) ) {
			return (bool) $w;
		}
		foreach ( $w as $p ) {
			$from = (string) ( $p[0] ?? '' );
			$to   = (string) ( $p[1] ?? '' );
			if ( self::is_date( $from ) && $date >= $from && ( '' === $to || $date < $to ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The meal periods of one day, against the business's rules ($rules: ZHR_Rules::clean_meals() shape; no periods = no
	 * meal is required). $spans: [start_ts, end_ts, minutes] sorted, the stretches on the clock; the gaps between them are
	 * the breaks (off the clock, so unpaid and off duty). $waivers: [1 => bool, 2 => bool], in force that day. Returns:
	 *   ['meals' => [ ['no','required','after','length','status','start_ts','minutes','answer','about','violation'] … ],
	 *    'premium' => 0|1, 'premium_minutes' => n, 'needs_answer' => bool, 'worked' => minutes, 'progress' => …|null]
	 * status: not_required | ok | waived | missed | late | short | pending (the clock is running and the deadline is not
	 * past yet). A violation (missed, late, short) answered 'voluntary' (the person chose to skip, delay or shorten a meal
	 * they were given) owes nothing; answered 'not_provided', or not answered yet, owes the business's premium for the
	 * day -- once a day however many meals. This is the record; payroll decides the pay.
	 */
	public static function meal_check( array $spans, array $waivers = array(), array $answers = array(), $running = false, array $rules = array() ) {
		$rules   = ZHR_Rules::clean_meals( $rules );
		$periods = $rules['periods'];
		$breaks  = array();
		$cum     = 0;
		foreach ( $spans as $i => $sp ) {
			if ( $i > 0 ) {
				$gap = (int) floor( max( 0, $sp[0] - $spans[ $i - 1 ][1] ) / 60 );
				if ( $gap > 0 ) {
					$breaks[] = array( 'start' => (int) $spans[ $i - 1 ][1], 'len' => $gap, 'at' => $cum, 'i' => count( $breaks ) );
				}
			}
			$cum += (int) $sp[2];
		}
		$worked = $cum;
		$meals  = array();
		$used   = -1;
		foreach ( $periods as $k => $rule ) {
			$no    = $k + 1;
			$after = (int) $rule['after'];
			$len   = (int) $rule['length'];
			$m     = array( 'no' => $no, 'required' => $worked > $after, 'after' => $after, 'length' => $len, 'status' => 'not_required', 'start_ts' => null, 'minutes' => null, 'answer' => null, 'about' => '', 'violation' => false );
			if ( $m['required'] ) {
				$waived = ! empty( $waivers[ $no ] )
					&& null !== $rule['waive_up_to'] && $worked <= (int) $rule['waive_up_to']
					&& ! $running
					&& ( 0 === $k || empty( $rule['waive_if_previous_not_waived'] ) || 'waived' !== ( $meals[ $k - 1 ]['status'] ?? '' ) );
				if ( $waived ) {
					$m['status'] = 'waived';
				} else {
					$ok = null; $late = null; $short = null;
					foreach ( $breaks as $b ) {
						if ( $b['i'] <= $used ) {
							continue; // a later meal comes after the one before it
						}
						if ( $b['len'] >= $len ) {
							if ( $b['at'] <= $after ) { $ok = $b; break; }
							if ( null === $late ) { $late = $b; }
						} elseif ( $b['at'] <= $after && ( null === $short || $b['len'] > $short['len'] ) ) {
							$short = $b;
						}
					}
					$pick = $ok ? $ok : ( $late ? $late : $short );
					$m['status'] = $ok ? 'ok' : ( $late ? 'late' : ( $short ? 'short' : 'missed' ) );
					if ( $pick ) {
						$m['start_ts'] = $pick['start'];
						$m['minutes']  = $pick['len'];
						$used = $pick['i']; // a break counted for one meal, even a short one, is never the next one's
					}
					if ( $running && 'missed' === $m['status'] && ! $ok ) {
						$m['status'] = 'pending'; // still on the clock: the day is not over (a late meal may still come)
					}
					$m['violation'] = in_array( $m['status'], array( 'missed', 'late', 'short' ), true );
				}
			}
			// an answer counts only for the meal it was given about: the same status and break. A plain string is an
			// answer given without that (the rules tests).
			$m['about'] = $m['status'] . '|' . ( null === $m['start_ts'] ? '' : (int) $m['start_ts'] );
			$a          = $answers[ $no ] ?? null;
			if ( is_array( $a ) ) {
				$a = ( (string) ( $a['about'] ?? '' ) === $m['about'] ) ? ( $a['answer'] ?? null ) : null;
			}
			$m['answer'] = in_array( $a, array( 'voluntary', 'not_provided' ), true ) ? $a : null;
			$meals[] = $m;
		}
		$owed = false;
		$open = false;
		foreach ( $meals as $m ) {
			if ( $m['violation'] ) {
				if ( 'voluntary' !== $m['answer'] ) {
					$owed = true;
				}
				if ( null === $m['answer'] ) {
					$open = true;
				}
			}
		}
		$premium = ( $owed && $rules['premium_minutes'] > 0 ) ? 1 : 0;
		// for the card while the clock runs: which meals were taken (a break as long as the rule asks, in order), and how
		// many worked minutes are left before each one is due (negative = past due)
		$progress = null;
		if ( $periods ) {
			$progress = array( 'meals' => array() );
			foreach ( $periods as $k => $rule ) {
				$long = array_values( array_filter( $breaks, function ( $b ) use ( $rule ) { return $b['len'] >= (int) $rule['length']; } ) );
				$progress['meals'][] = array(
					'no'          => $k + 1,
					'after'       => (int) $rule['after'],
					'length'      => (int) $rule['length'],
					'waive_up_to' => $rule['waive_up_to'],
					'done'        => isset( $long[ $k ] ),
					'late'        => isset( $long[ $k ] ) && $long[ $k ]['at'] > (int) $rule['after'],
					'left'        => (int) $rule['after'] - $worked,
					'waiver'      => ! empty( $waivers[ $k + 1 ] ),
				);
			}
		}
		return array( 'meals' => $meals, 'premium' => $premium, 'premium_minutes' => $premium ? (int) $rules['premium_minutes'] : 0, 'needs_answer' => $open, 'worked' => $worked, 'progress' => $progress );
	}

	/**
	 * Totals over many day_totals() rows. With $today, 'standard_to_date' counts only the days up to and including
	 * today, and over / under compare the hours worked with that (a Wednesday is not 24 hours "under" for the week).
	 * meal_premiums: days the meal record says owe the premium; meal_open: days with a meal question unanswered.
	 */
	public static function range_totals( array $days, $today = null ) {
		$t = array( 'worked' => 0, 'raw' => 0, 'standard' => 0, 'standard_to_date' => 0, 'days_worked' => 0, 'off_days_worked' => 0, 'over' => 0, 'under' => 0, 'meal_premiums' => 0, 'meal_premium_minutes' => 0, 'meal_open' => 0, 'unfinished' => 0 );
		foreach ( $days as $d ) {
			$t['worked']   += (int) $d['worked'];
			$t['raw']      += (int) $d['raw'];
			$t['standard'] += (int) $d['standard'];
			if ( null === $today || (string) $d['date'] <= (string) $today ) {
				$t['standard_to_date'] += (int) $d['standard'];
			}
			if ( (int) $d['raw'] > 0 ) {
				$t['days_worked']++;
				if ( ! empty( $d['off_day'] ) ) {
					$t['off_days_worked']++;
				}
			}
			$t['unfinished'] += (int) ( $d['unfinished'] ?? 0 );
			if ( ! empty( $d['meals'] ) ) {
				$t['meal_premiums']        += (int) $d['meals']['premium'];
				$t['meal_premium_minutes'] += (int) ( $d['meals']['premium_minutes'] ?? 0 );
				$t['meal_open']            += ! empty( $d['meals']['needs_answer'] ) ? 1 : 0;
			}
		}
		$diff       = $t['worked'] - $t['standard_to_date'];
		$t['over']  = max( 0, $diff );
		$t['under'] = max( 0, -$diff );
		return $t;
	}

	/** A meal's state in words, for the day view and the accountant's file. */
	public static function meal_words( array $m ) {
		$at  = $m['start_ts'] ? ( new DateTimeImmutable( '@' . (int) $m['start_ts'] ) )->setTimezone( self::tz() )->format( 'g:i A' ) : '';
		$no  = (int) $m['no'];
		$aft = ZHR_Rules::after_words( (int) ( $m['after'] ?? 0 ) );
		switch ( $m['status'] ) {
			/* translators: 1: meal number, 2: time, 3: minutes */
			case 'ok':      return sprintf( __( 'Meal %1$d: %2$s, %3$d min', 'zorderz' ), $no, $at, (int) $m['minutes'] );
			/* translators: %d: meal number */
			case 'waived':  return sprintf( __( 'Meal %d: waived (signed waiver)', 'zorderz' ), $no );
			/* translators: 1: meal number, 2: time, 3: an hour such as "the 5th hour" */
			case 'late':    return sprintf( __( 'Meal %1$d: late (%2$s, after %3$s)', 'zorderz' ), $no, $at, $aft );
			/* translators: 1: meal number, 2: minutes, 3: time */
			case 'short':   return sprintf( __( 'Meal %1$d: short (%2$d min at %3$s)', 'zorderz' ), $no, (int) $m['minutes'], $at );
			/* translators: %d: meal number */
			case 'missed':  return sprintf( __( 'Meal %d: none taken', 'zorderz' ), $no );
			/* translators: %d: meal number */
			case 'pending': return sprintf( __( 'Meal %d: not taken yet', 'zorderz' ), $no );
		}
		return '';
	}

	/** 515 -> '8 h 35 min'; 480 -> '8 h'; 35 -> '35 min'. */
	public static function fmt( $min ) {
		$min = max( 0, (int) $min );
		$h   = intdiv( $min, 60 );
		$m   = $min % 60;
		if ( 0 === $h ) {
			return $m . ' min';
		}
		return $h . ' h' . ( $m ? ' ' . $m . ' min' : '' );
	}

	/** Decimal hours for the accountant: 515 -> '8.58'. */
	public static function dec( $min ) {
		return number_format( max( 0, (int) $min ) / 60, 2, '.', '' );
	}

	/* ------------------------------------------------------------------ what an entry may be */

	/** The earliest work date anyone outside the admin tier may still add or change: the start of the week (edit_weeks - 1) weeks back. */
	public static function edit_from( $today, $settings ) {
		$weeks = max( 1, (int) ( $settings['edit_weeks'] ?? 1 ) );
		return self::add_days( self::week_start( $today, (int) ( $settings['week_start'] ?? 1 ) ), -7 * ( $weeks - 1 ) );
	}

	/** The refusal for a day before the edit window. */
	public static function too_old_words() {
		return __( 'That is before the hours you can change yourself. Ask an admin to add or change it.', 'zorderz' );
	}

	/**
	 * Check one proposed entry. $in: ['date','start','end','note'] (end may be '' only when $ctx['allow_open']).
	 * $ctx: now_ts, is_admin, edit_from, others (this person's other live entries: id, start_at, end_at), allow_open.
	 * Returns ['ok' => bool, 'entry' => clean | null, 'problems' => [ [code, sentence], ... ]].
	 * An end at or before the start runs past midnight into the next day (a night shift), up to 16 hours.
	 */
	public static function check_entry( array $in, array $ctx ) {
		$problems = array();
		$date     = trim( (string) ( $in['date'] ?? '' ) );
		$s        = self::hm_to_min( $in['start'] ?? '' );
		$end_raw  = trim( (string) ( $in['end'] ?? '' ) );
		$e        = '' === $end_raw ? null : self::hm_to_min( $end_raw );
		$note     = self::clean_note( $in['note'] ?? '' );
		$now      = (int) $ctx['now_ts'];

		if ( ! self::is_date( $date ) ) {
			return self::bad( 'date', __( 'The date could not be read.', 'zorderz' ) );
		}
		if ( null === $s ) {
			return self::bad( 'start', __( 'The start time could not be read.', 'zorderz' ) );
		}
		if ( '' !== $end_raw && null === $e ) {
			return self::bad( 'end', __( 'The end time could not be read.', 'zorderz' ) );
		}
		if ( null === $e && empty( $ctx['allow_open'] ) ) {
			return self::bad( 'end', __( 'An end time is needed.', 'zorderz' ) );
		}
		$start_at = $date . ' ' . self::min_to_hm( $s ) . ':00';
		$end_at   = null;
		$minutes  = 0;
		if ( null !== $e ) {
			$end_date = $e > $s ? $date : self::add_days( $date, 1 );
			$end_at   = $end_date . ' ' . self::min_to_hm( $e ) . ':00';
			$minutes  = self::minutes( $start_at, $end_at );
			if ( $minutes < 1 ) {
				return self::bad( 'span', __( 'The end has to be after the start.', 'zorderz' ) );
			}
			if ( $minutes > self::MAX_ENTRY_MIN ) {
				return self::bad( 'span', __( 'That is more than 16 hours in one go. Split it into the real shifts.', 'zorderz' ) );
			}
		}
		$sts = self::ts( $start_at );
		$ets = null === $end_at ? $sts : self::ts( $end_at );
		if ( null === $end_at && $now - $sts > self::MAX_ENTRY_MIN * 60 ) {
			return self::bad( 'span', __( 'A clock cannot have been running more than 16 hours. Give it an end time.', 'zorderz' ) );
		}
		if ( $ets > $now + self::FUTURE_SLACK_S || $sts > $now + self::FUTURE_SLACK_S ) {
			$problems[] = array( 'future', __( 'That time has not happened yet.', 'zorderz' ) );
		}
		if ( empty( $ctx['is_admin'] ) && $date < (string) $ctx['edit_from'] ) {
			$problems[] = array( 'too_old', self::too_old_words() );
		}
		foreach ( (array) ( $ctx['others'] ?? array() ) as $o ) {
			$os = self::ts( $o['start_at'] ?? '' );
			$oe = empty( $o['end_at'] ) ? $now : self::ts( $o['end_at'] );
			if ( null === $os || null === $oe ) {
				continue;
			}
			if ( empty( $o['end_at'] ) && '' !== (string) ( $o['end_review'] ?? '' ) ) {
				$oe = $os + 60; // a shift left with the office has no known end; only its clock-in minute is taken
			}
			$my_end = null === $end_at ? $now : $ets;
			if ( $sts < $oe && $my_end > $os ) {
				/* translators: %s: a stretch of time such as "7:05 AM–3:30 PM, Tue Mar 3" */
				$problems[] = array( 'overlap', sprintf( __( 'That overlaps %s, already logged.', 'zorderz' ), self::label_span( $o['start_at'], $o['end_at'] ?? null ) ) );
				break;
			}
		}
		return array(
			'ok'       => ! $problems,
			'entry'    => array( 'work_date' => $date, 'start_at' => $start_at, 'end_at' => $end_at, 'minutes' => $minutes, 'note' => $note ),
			'problems' => $problems,
		);
	}

	private static function bad( $code, $sentence ) {
		return array( 'ok' => false, 'entry' => null, 'problems' => array( array( $code, $sentence ) ) );
	}

	/** At most $n characters (never half a character: a curly apostrophe cut in two made the database refuse the row). */
	public static function cut( $s, $n ) {
		$s = (string) $s;
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $s, 0, (int) $n, 'UTF-8' );
		}
		return substr( $s, 0, (int) $n );
	}

	public static function clean_note( $s ) {
		$s = preg_replace( '/[\x00-\x1F\x7F]+/u', ' ', (string) $s );
		$s = trim( preg_replace( '/\s+/u', ' ', strip_tags( (string) $s ) ) );
		return self::cut( $s, self::NOTE_MAX );
	}

	/** '7:05 AM–3:30 PM, Tue Mar 3' (or 'the clock running since 7:05 AM, Tue Mar 3' for a running one). */
	public static function label_span( $start_at, $end_at = null ) {
		$s = self::ts( $start_at );
		if ( null === $s ) {
			return '';
		}
		$tz  = self::tz();
		$sd  = ( new DateTimeImmutable( '@' . $s ) )->setTimezone( $tz );
		$day = $sd->format( 'D M j' );
		if ( empty( $end_at ) ) {
			/* translators: 1: time, 2: day */
			return sprintf( __( 'the clock running since %1$s, %2$s', 'zorderz' ), $sd->format( 'g:i A' ), $day );
		}
		$e = self::ts( $end_at );
		if ( null === $e ) {
			return $sd->format( 'g:i A' ) . ', ' . $day;
		}
		$ed = ( new DateTimeImmutable( '@' . $e ) )->setTimezone( $tz );
		return $sd->format( 'g:i A' ) . '–' . $ed->format( 'g:i A' ) . ', ' . $day;
	}

	/**
	 * Flags shown beside an entry (information for the office and the accountant, never a refusal):
	 * long (over 14 h), running_long (the clock still running after 14 h -- a forgotten clock-out), no_finish (past 16
	 * hours or with the office), off_day (on a day off, when the business has standard hours), overlap (with another entry;
	 * only a database written before the overlap check can hold one), edited.
	 */
	public static function flags( array $entry, array $others, $settings, $now_ts ) {
		$out = array();
		$s   = self::ts( $entry['start_at'] ?? '' );
		if ( null === $s ) {
			return $out;
		}
		if ( empty( $entry['end_at'] ) ) {
			if ( 'live' !== self::open_state( $entry, $now_ts ) ) {
				$out[] = 'no_finish';
				$e     = $s + 60;
			} else {
				if ( (int) $now_ts - $s > self::LONG_ENTRY_MIN * 60 ) {
					$out[] = 'running_long';
				}
				$e = (int) $now_ts;
			}
		} else {
			$e = (int) self::ts( $entry['end_at'] );
			if ( ( $e - $s ) / 60 > self::LONG_ENTRY_MIN ) {
				$out[] = 'long';
			}
		}
		if ( self::has_standard( $settings ) && self::standard_day( $settings, substr( (string) $entry['start_at'], 0, 10 ) )['off'] ) {
			$out[] = 'off_day';
		}
		foreach ( $others as $o ) {
			if ( (int) ( $o['id'] ?? 0 ) === (int) ( $entry['id'] ?? -1 ) ) {
				continue;
			}
			$os = self::ts( $o['start_at'] ?? '' );
			$oe = empty( $o['end_at'] ) ? ( 'live' === self::open_state( $o, $now_ts ) ? (int) $now_ts : $os + 60 ) : self::ts( $o['end_at'] );
			if ( null !== $os && null !== $oe && $s < $oe && $e > $os ) {
				$out[] = 'overlap';
				break;
			}
		}
		if ( ! empty( $entry['history_json'] ) && '[]' !== $entry['history_json'] ) {
			$out[] = 'edited';
		}
		return $out;
	}

	/* ------------------------------------------------------------------ backed up by the app? */

	/**
	 * How much of [$start_ts, $end_ts] the app's own record of this person covers. $events: rows with
	 * ['ts' => unix, 'kind' => one of WINDOWS' keys or a SPAN_KINDS kind, 'until' => unix (spans only)].
	 * A door-hanger walk's start-to-finish covers its whole span (the phone traced it); every other kind covers its
	 * window. Returns ['pct' => 0..100, 'covered' => minutes, 'level' => strong|some|little|none, 'kinds' => counts].
	 * This is evidence for a person to read, never a decision.
	 */
	public static function evidence( $start_ts, $end_ts, array $events ) {
		$start_ts = (int) $start_ts;
		$end_ts   = (int) $end_ts;
		$len      = max( 0, $end_ts - $start_ts );
		$kinds    = array();
		if ( $len < 60 ) {
			return array( 'pct' => 0, 'covered' => 0, 'level' => 'none', 'kinds' => $kinds );
		}
		$iv = array();
		foreach ( $events as $ev ) {
			$k = (string) ( $ev['kind'] ?? '' );
			$t = (int) ( $ev['ts'] ?? 0 );
			if ( $t <= 0 ) {
				continue;
			}
			if ( in_array( $k, self::SPAN_KINDS, true ) ) {
				$u = (int) ( $ev['until'] ?? 0 );
				if ( $u <= $t ) {
					continue;
				}
				$a = $t;
				$b = $u;
			} elseif ( isset( self::WINDOWS[ $k ] ) ) {
				$a = $t - self::WINDOWS[ $k ][0] * 60;
				$b = $t + self::WINDOWS[ $k ][1] * 60;
			} else {
				continue;
			}
			$a = max( $a, $start_ts );
			$b = min( $b, $end_ts );
			if ( $b > $a ) {
				$iv[]        = array( $a, $b );
				$kinds[ $k ] = ( $kinds[ $k ] ?? 0 ) + 1;
			}
		}
		usort( $iv, function ( $x, $y ) { return $x[0] <=> $y[0]; } );
		$covered = 0;
		$cur     = null;
		foreach ( $iv as $i ) {
			if ( null === $cur ) {
				$cur = $i;
			} elseif ( $i[0] <= $cur[1] ) {
				$cur[1] = max( $cur[1], $i[1] );
			} else {
				$covered += $cur[1] - $cur[0];
				$cur      = $i;
			}
		}
		if ( null !== $cur ) {
			$covered += $cur[1] - $cur[0];
		}
		$pct   = (int) round( 100 * $covered / $len );
		$level = $pct >= 60 ? 'strong' : ( $pct >= 20 ? 'some' : ( $pct > 0 ? 'little' : 'none' ) );
		return array( 'pct' => $pct, 'covered' => (int) floor( $covered / 60 ), 'level' => $level, 'kinds' => $kinds );
	}

	/**
	 * Whole minutes of [$a, $b] covered by events of one span kind ('walk_span' or 'drive_span'), overlaps counted once.
	 * $events: as evidence() takes them.
	 */
	public static function span_minutes( array $events, $kind, $a, $b ) {
		$a  = (int) $a;
		$b  = (int) $b;
		$iv = array();
		foreach ( $events as $ev ) {
			if ( $kind !== (string) ( $ev['kind'] ?? '' ) ) {
				continue;
			}
			$x = max( $a, (int) ( $ev['ts'] ?? 0 ) );
			$y = min( $b, (int) ( $ev['until'] ?? 0 ) );
			if ( $y > $x ) {
				$iv[] = array( $x, $y );
			}
		}
		usort( $iv, function ( $p, $q ) { return $p[0] <=> $q[0]; } );
		$sum = 0;
		$cur = null;
		foreach ( $iv as $i ) {
			if ( null !== $cur && $i[0] <= $cur[1] ) {
				$cur[1] = max( $cur[1], $i[1] );
				continue;
			}
			if ( null !== $cur ) {
				$sum += $cur[1] - $cur[0];
			}
			$cur = $i;
		}
		if ( null !== $cur ) {
			$sum += $cur[1] - $cur[0];
		}
		return (int) round( $sum / 60 );
	}

	/**
	 * The events that can touch [$a, $b] from a list sorted by time (an export of a year runs evidence() thousands of
	 * times). A walk span is kept when it began within a day before.
	 */
	public static function events_near( array $events, $a, $b ) {
		$lo = (int) $a - 86400;
		$hi = (int) $b + 3600;
		$n  = count( $events );
		$l  = 0;
		$r  = $n;
		while ( $l < $r ) { // first event at or after $lo
			$m = ( $l + $r ) >> 1;
			if ( (int) $events[ $m ]['ts'] < $lo ) {
				$l = $m + 1;
			} else {
				$r = $m;
			}
		}
		$out = array();
		for ( $i = $l; $i < $n && (int) $events[ $i ]['ts'] <= $hi; $i++ ) {
			$out[] = $events[ $i ];
		}
		return $out;
	}

	/* ------------------------------------------------------------------ what the model read */

	/**
	 * The model's JSON -> proposed entries ['date','start','end','note','name','assumed'] (not yet checked, not yet
	 * attributed: ZHR_Attribution decides whose each one is). `name` is the name the model read beside the stretch,
	 * exactly as written or said ('' when none). An entry given only as a length ("8 hours yesterday") starts at that
	 * day's standard start and is marked assumed so the person sees the times were filled in; with no standard start it
	 * keeps no start at all (needs_start) and cannot be added until a person gives one. A length is never invented:
	 * no length and no end -> dropped. Only an end ("I clocked out at 4:30") is a finish for an open shift (finish).
	 */
	public static function proposals_from_model( $parsed, $settings, $today ) {
		$out = array();
		if ( ! is_array( $parsed ) ) {
			return $out;
		}
		foreach ( array_slice( (array) ( $parsed['entries'] ?? array() ), 0, 31 ) as $e ) {
			if ( ! is_array( $e ) ) {
				continue;
			}
			$date = trim( (string) ( $e['date'] ?? '' ) );
			if ( ! self::is_date( $date ) ) {
				continue;
			}
			$name = self::cut( trim( preg_replace( '/\s+/u', ' ', (string) ( $e['name'] ?? '' ) ) ), 80 );
			$note = self::clean_note( $e['note'] ?? '' );
			$s    = self::hm_to_min( $e['start'] ?? '' );
			$en   = self::hm_to_min( $e['end'] ?? '' );
			$len  = isset( $e['minutes'] ) && is_numeric( $e['minutes'] ) ? (int) round( (float) $e['minutes'] ) : null;
			if ( null !== $len && ( $len < 1 || $len > self::MAX_ENTRY_MIN ) ) {
				$len = null;
			}
			$assumed = false;
			if ( null === $s && null === $en ) {
				if ( null === $len ) {
					continue;
				}
				$std = self::standard_day( $settings, $date );
				if ( $std['off'] ) {
					// no standard start that day: never a made-up start time; a person gives it
					$out[] = array( 'date' => $date, 'start' => '', 'end' => '', 'minutes_given' => $len, 'note' => $note, 'name' => $name, 'assumed' => false, 'needs_start' => true );
					continue;
				}
				$s       = (int) self::hm_to_min( $std['start'] );
				$en      = ( $s + $len ) % 1440;
				$assumed = true;
			} elseif ( null === $en ) {
				if ( null === $len ) {
					continue;
				}
				$en = ( $s + $len ) % 1440;
			} elseif ( null === $s ) {
				if ( null === $len ) {
					$out[] = array( 'date' => $date, 'start' => '', 'end' => self::min_to_hm( $en ), 'note' => $note, 'name' => $name, 'assumed' => false, 'finish' => true );
					continue;
				}
				$s = ( ( $en - $len ) % 1440 + 1440 ) % 1440;
			}
			$out[] = array(
				'date'    => $date,
				'start'   => self::min_to_hm( $s ),
				'end'     => self::min_to_hm( $en ),
				'note'    => $note,
				'name'    => $name,
				'assumed' => $assumed,
			);
		}
		return $out;
	}

	/* ------------------------------------------------------------------ the forgotten clock-out */

	/**
	 * What an entry with no end is: 'live' (the clock is running, at most 16 hours), 'stale' (running more than 16 hours:
	 * a forgotten clock-out) or 'parked' (the person asked the office to set the finish). '' for a finished entry.
	 * Only a live clock counts minutes; the others count nothing until a person sets the finish (the app never invents
	 * a clock-out time).
	 */
	public static function open_state( array $e, $now_ts ) {
		if ( ! empty( $e['end_at'] ) ) {
			return '';
		}
		if ( '' !== (string) ( $e['end_review'] ?? '' ) ) {
			return 'parked';
		}
		$s = self::ts( $e['start_at'] ?? '' );
		return ( null !== $s && (int) $now_ts - $s > self::MAX_ENTRY_MIN * 60 ) ? 'stale' : 'live';
	}

	/** The End cell of the accountant's file for an entry with no end. */
	public static function open_words( array $e ) {
		$st = self::open_state( $e, time() );
		return 'parked' === $st ? __( 'no finish time (asked the office)', 'zorderz' ) : ( 'stale' === $st ? __( 'no finish time', 'zorderz' ) : __( 'running', 'zorderz' ) );
	}

	/**
	 * The finish a person gives for an open shift: 'HH:MM' -> the first such moment after the clock-in (the same day, or
	 * the next for a night shift), at most 16 hours after it and not later than now.
	 * Returns ['ok' => true, 'end_at', 'minutes'] or ['ok' => false, 'code', 'sentence'].
	 */
	public static function finish_at( $start_at, $hm, $now_ts ) {
		$m = self::hm_to_min( $hm );
		$s = self::ts( $start_at );
		if ( null === $m || null === $s ) {
			return array( 'ok' => false, 'code' => 'finish_time', 'sentence' => __( 'That time could not be read. Give it like 4:30 PM.', 'zorderz' ) );
		}
		$day  = substr( (string) $start_at, 0, 10 );
		$same = self::ts( $day . ' ' . self::min_to_hm( $m ) . ':00' );
		$when = self::time_words( $s );
		if ( $same > $s ) {
			$end_at = $day . ' ' . self::min_to_hm( $m ) . ':00';
		} else {
			$end_at = self::add_days( $day, 1 ) . ' ' . self::min_to_hm( $m ) . ':00';
			if ( self::ts( $end_at ) - $s > self::MAX_ENTRY_MIN * 60 ) {
				// earlier than the clock-in that day, and more than 16 hours on the next: either way not a finish
				/* translators: %s: a time such as "7:12 am" */
				return array( 'ok' => false, 'code' => 'finish_before', 'sentence' => sprintf( __( 'That is before the clock-in at %s, or more than 16 hours after it. Give the time you finished, or ask the office.', 'zorderz' ), $when ) );
			}
		}
		$e = self::ts( $end_at );
		if ( $e - $s > self::MAX_ENTRY_MIN * 60 ) {
			/* translators: %s: a time such as "7:12 am" */
			return array( 'ok' => false, 'code' => 'finish_long', 'sentence' => sprintf( __( 'That is more than 16 hours after the clock-in at %s. Ask the office.', 'zorderz' ), $when ) );
		}
		if ( $e > (int) $now_ts ) { // never in the future, not even the phone-clock slack (a typed minute is never ahead)
			return array( 'ok' => false, 'code' => 'finish_future', 'sentence' => __( 'That time has not come yet. Give the time you finished, or Clock out now.', 'zorderz' ) );
		}
		$min = (int) floor( ( $e - $s ) / 60 );
		if ( $min < 1 ) {
			/* translators: %s: a time such as "7:12 am" */
			return array( 'ok' => false, 'code' => 'finish_before', 'sentence' => sprintf( __( 'That is the minute of the clock-in at %s. Give the time you finished.', 'zorderz' ), $when ) );
		}
		return array( 'ok' => true, 'end_at' => $end_at, 'minutes' => $min );
	}

	/** '7:12 am', or '7:12 am on Fri Mar 6' when that is not $today. */
	public static function time_words( $ts, $today = null ) {
		$d = ( new DateTimeImmutable( '@' . (int) $ts ) )->setTimezone( self::tz() );
		return $d->format( 'g:i a' ) . ( null !== $today && $d->format( 'Y-m-d' ) !== $today ? ' ' . __( 'on', 'zorderz' ) . ' ' . $d->format( 'D M j' ) : '' );
	}

	/**
	 * When an open shift gets its one notice: the reminder time on the shift's own day; a shift that began at or after
	 * that time (a night job), 14 hours in. null when reminders are off.
	 */
	public static function remind_ts( array $e, $settings ) {
		$at = (string) ( $settings['remind_at'] ?? '' );
		$s  = self::ts( $e['start_at'] ?? '' );
		if ( '' === $at || null === self::hm_to_min( $at ) || null === $s ) {
			return null;
		}
		$t = self::ts( substr( (string) $e['start_at'], 0, 10 ) . ' ' . $at . ':00' );
		return ( null === $t || $s >= $t ) ? $s + self::LONG_ENTRY_MIN * 60 : $t;
	}

	/* ------------------------------------------------------------------ the accountant's file */

	public static function csv_header() {
		return array(
			__( 'Person', 'zorderz' ), __( 'Date', 'zorderz' ), __( 'Weekday', 'zorderz' ), __( 'Start', 'zorderz' ), __( 'End', 'zorderz' ),
			__( 'Minutes', 'zorderz' ), __( 'Hours', 'zorderz' ), __( 'Source', 'zorderz' ), __( 'Note', 'zorderz' ),
			__( 'Backed by app activity (%)', 'zorderz' ), __( 'Start location', 'zorderz' ), __( 'End location', 'zorderz' ),
			__( 'Changed', 'zorderz' ), __( 'Logged', 'zorderz' ),
		);
	}

	/** One CSV row for one entry. $person: display name. Location: "lat,lng" (a person reads it in a map). */
	public static function csv_row( array $e, $person, $evidence_pct = '' ) {
		$loc = function ( $lat, $lng ) {
			return ( null !== $lat && '' !== $lat && null !== $lng && '' !== $lng ) ? round( (float) $lat, 5 ) . ',' . round( (float) $lng, 5 ) : '';
		};
		$hist = json_decode( (string) ( $e['history_json'] ?? '' ), true );
		$set  = ! empty( $e['finish_set_by'] )
			/* translators: %s: initials */
			? sprintf( __( 'finish set later by %s', 'zorderz' ), $e['finish_set_by'] ) . ( ! empty( $e['finish_set_office'] ) ? ' ' . __( '(the office)', 'zorderz' ) : '' )
			: '';
		return array(
			(string) $person,
			(string) $e['work_date'],
			( new DateTimeImmutable( $e['work_date'] . ' 12:00:00', self::tz() ) )->format( 'D' ),
			substr( (string) $e['start_at'], 11, 5 ),
			empty( $e['end_at'] ) ? self::open_words( $e ) : substr( (string) $e['end_at'], 11, 5 ) . ( substr( (string) $e['end_at'], 0, 10 ) !== substr( (string) $e['start_at'], 0, 10 ) ? ' ' . __( '(+1 day)', 'zorderz' ) : '' ),
			(string) (int) $e['minutes'],
			self::dec( (int) $e['minutes'] ),
			(string) $e['source'],
			(string) $e['note'],
			(string) $evidence_pct,
			$loc( $e['start_lat'] ?? null, $e['start_lng'] ?? null ),
			$loc( $e['end_lat'] ?? null, $e['end_lng'] ?? null ),
			/* translators: 1: number of changes, 2: when the last one was made */
			( is_array( $hist ) && $hist ? sprintf( __( '%1$d change(s), last %2$s', 'zorderz' ), count( $hist ), (string) ( end( $hist )['at'] ?? '' ) ) : '' )
				. ( '' !== $set ? ( $hist ? '; ' : '' ) . $set : '' ),
			(string) ( $e['created_at'] ?? '' ),
		);
	}

	/** RFC 4180 with a guard against spreadsheet formulas (a cell starting = + - @ is prefixed with '). */
	public static function csv_line( array $cells ) {
		$out = array();
		foreach ( $cells as $c ) {
			$c = (string) $c;
			if ( '' !== $c && preg_match( '/^[=+\-@\t\r]/', $c ) && ! preg_match( '/^-?\d+(\.\d+)?(,-?\d+(\.\d+)?)?$/', $c ) ) {
				$c = "'" . $c;
			}
			$out[] = '"' . str_replace( '"', '""', $c ) . '"';
		}
		return implode( ',', $out ) . "\r\n";
	}
}
