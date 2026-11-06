<?php
/**
 * ZSCH_My_Counts — "how many appointments do I have today?" Published as the
 * read verb `schedule.my_counts` on the platform's capability registry
 * (ZDZ_Capabilities), for a home-screen count tile.
 *
 * schedule.lookup answers an event LIST (titles, bodies, the team calendar
 * too); a home tile needs a count and must never carry a title or a place. The
 * Scheduler owns the calendar, so the Scheduler answers.
 *
 * WHAT COUNTS — "yours"
 * ---------------------
 * The appointments that are this person's for the day, read through
 * ZSCH_Appointments::query() (scope 'all': what they may see, series expanded)
 * and kept when they are its owner or a participant (mine()):
 *   - their personal appointments;
 *   - appointments on the team calendar that they own (a job booked onto the
 *     team calendar with the assignee as its owner and no participant row);
 *   - any appointment, on either calendar, they were put on as a participant.
 * A repeating appointment counts once per occurrence in the day. The team
 * calendar's events that are someone else's and that they are not on are not
 * counted. Days are the Scheduler's own zone (ZSCH_Settings::default_tz(), the
 * zone schedule.lookup reads days in).
 *
 *   today        appointments that overlap the asked day
 *   ahead        of those, the ones not over yet (end after now); an all-day one counts until the day ends
 *   all_day      of today's, the all-day ones
 *   tomorrow     appointments that overlap the next day
 *   next_at      the start (unix) of the next timed appointment today that has not started, or null
 *
 * WHO ASKS, WHAT THEY GET
 * -----------------------
 *   anyone who holds the Scheduler (tier viewer: a tech and up), their OWN
 *   calendar only — an admin's too (the registry stamps who asks; no owner_id
 *   is honoured here)
 *   the kiosk (a shared device, nobody's calendar), signed out: refused
 *   anyone: counts and one time only — never a title, a body, a place, a person
 *   or a customer
 *
 * A failed read is an error (success false), never 0. `read_at` is when the
 * calendar was read.
 *
 * @since 1.10.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZSCH_My_Counts {

	const VERB = 'schedule.my_counts';

	/** Injected in tests: "now" (unix) and the reader (callable( $uid, $start_utc, $end_utc ) => rows|null). */
	public static $now  = null;
	public static $read = null;

	public static function descriptor() {
		return array(
			'verb'          => self::VERB,
			'provider'      => 'zsch',
			'app'           => defined( 'ZSCH_APP_ID' ) ? ZSCH_APP_ID : 'scheduler',
			'tier'          => 'tech',                // a tech and up: each person's own calendar
			'needs_app'     => true,
			'callback'      => array( __CLASS__, 'from_capability' ),
			'kiosk'         => false,
			'kiosk_bounded' => false,                 // a shared device has no calendar of its own
			'side_effect'   => false,
			'summary'       => 'How many appointments are on your own calendar today (and how many are still ahead, all-day, tomorrow), and when the next one starts. Counts and one time only.',
		);
	}

	public static function register( $caps ) {
		if ( ! is_array( $caps ) ) {
			$caps = array();
		}
		$caps[ self::VERB ] = self::descriptor();
		return $caps;
	}

	/** A 'Y-m-d', or null. PURE. */
	public static function day( $v ) {
		$v = is_scalar( $v ) ? trim( (string) $v ) : '';
		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m ) || ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
			return null;
		}
		return $v;
	}

	private static function zone() {
		$tz = class_exists( 'ZSCH_Settings' ) && method_exists( 'ZSCH_Settings', 'default_tz' ) ? (string) ZSCH_Settings::default_tz() : '';
		try {
			return new DateTimeZone( '' !== $tz ? $tz : ( function_exists( 'wp_timezone_string' ) ? wp_timezone_string() : 'UTC' ) );
		} catch ( Exception $e ) {
			return new DateTimeZone( 'UTC' );
		}
	}

	private static function now() {
		return null !== self::$now ? (int) self::$now : time();
	}

	/** A stored time ('Y-m-d H:i:s' UTC, or ISO with a zone) as unix; null when unreadable. PURE. */
	public static function ts( $v ) {
		$v = trim( (string) $v );
		if ( '' === $v || 0 === strpos( $v, '0000-00-00' ) ) {
			return null;
		}
		try {
			return ( new DateTime( $v, new DateTimeZone( 'UTC' ) ) )->getTimestamp();
		} catch ( Exception $e ) {
			return null;
		}
	}

	/** One local day as [ start_utc, end_utc ) 'Y-m-d H:i:s' strings and unix bounds. */
	private static function bounds( $ymd, DateTimeZone $zone ) {
		$a = new DateTime( $ymd . ' 00:00:00', $zone );
		$b = ( clone $a )->modify( '+1 day' );
		$u = new DateTimeZone( 'UTC' );
		$au = ( clone $a )->setTimezone( $u );
		$bu = ( clone $b )->setTimezone( $u );
		return array( $au->format( 'Y-m-d H:i:s' ), $bu->format( 'Y-m-d H:i:s' ), $a->getTimestamp(), $b->getTimestamp() );
	}

	/** The calendar for one day as this person may see it, as query() shapes it; null on a database error. */
	private static function read( $uid, $start_utc, $end_utc ) {
		if ( is_callable( self::$read ) ) {
			return call_user_func( self::$read, $uid, $start_utc, $end_utc );
		}
		if ( ! class_exists( 'ZSCH_Appointments' ) ) {
			return null;
		}
		global $wpdb;
		$rows = ZSCH_Appointments::query( $uid, $start_utc, $end_utc, array( 'scope' => 'all' ) );
		if ( isset( $wpdb ) && is_object( $wpdb ) && ! empty( $wpdb->last_error ) ) {
			return null;
		}
		return is_array( $rows ) ? $rows : null;
	}

	/** The rows that are this person's: they own it (either calendar) or are on it. PURE. */
	public static function mine( array $rows, $uid ) {
		$uid = (int) $uid;
		return array_values( array_filter( $rows, function ( $r ) use ( $uid ) {
			return is_array( $r ) && ( (int) ( $r['owner_user_id'] ?? 0 ) === $uid
				|| in_array( $uid, array_map( 'intval', (array) ( $r['participant_ids'] ?? array() ) ), true ) );
		} ) );
	}

	/**
	 * Count one day's rows. PURE: $rows as query() shapes them, $a / $b the day's unix bounds, $now unix.
	 * An occurrence counts when it overlaps [ $a, $b ); identical occurrences (same id and start) once.
	 */
	public static function tally( array $rows, $a, $b, $now ) {
		$seen = array();
		$out  = array( 'n' => 0, 'ahead' => 0, 'all_day' => 0, 'next_at' => null );
		foreach ( $rows as $r ) {
			if ( ! is_array( $r ) ) {
				continue;
			}
			$s = self::ts( $r['start_utc'] ?? '' );
			$e = self::ts( $r['end_utc'] ?? '' );
			if ( null === $s ) {
				continue;
			}
			if ( null === $e || $e < $s ) {
				$e = $s;
			}
			$overlaps = $s < $b && ( $e > $a || ( $e === $s && $s >= $a ) );
			if ( ! $overlaps ) {
				continue;
			}
			$key = (string) ( $r['id'] ?? '' ) . '|' . $s;
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$out['n']++;
			$all = ! empty( $r['is_all_day'] );
			if ( $all ) {
				$out['all_day']++;
			}
			if ( $all ? $now < $b : ( $e > $now || ( $e === $s && $s >= $now ) ) ) {
				$out['ahead']++;
			}
			if ( ! $all && $s > $now && $s < $b && ( null === $out['next_at'] || $s < $out['next_at'] ) ) {
				$out['next_at'] = $s;
			}
		}
		return $out;
	}

	public static function definitions() {
		return array(
			'today'    => 'Your appointments that day: the ones you own, on your calendar or the team\'s (a job booked for you), and any you were put on. A repeating one counts once per visit.',
			'ahead'    => 'Of those, the ones not over yet.',
			'tomorrow' => 'The same, for the next day.',
		);
	}

	/**
	 * The verb. $args: day ('Y-m-d', default today in the Scheduler's zone). Identity is the registry's stamp only.
	 */
	public static function from_capability( array $args ) {
		$uid   = (int) ( $args['requesting_user_id'] ?? ( $args['user_id'] ?? 0 ) );
		$kiosk = ! empty( $args['is_kiosk'] ) || 'kiosk' === (string) ( $args['tier'] ?? '' )
			|| ( $uid > 0 && function_exists( 'zsch_user_is_read_only' ) && zsch_user_is_read_only( $uid ) );
		if ( $uid <= 0 ) {
			return array( 'success' => false, 'denied' => true, 'reason' => 'signed_out', 'verb' => self::VERB );
		}
		if ( $kiosk ) {
			return array( 'success' => false, 'denied' => true, 'reason' => 'kiosk', 'verb' => self::VERB );
		}
		if ( function_exists( 'zsch_user_has_access' ) && ! zsch_user_has_access( $uid ) ) {
			return array( 'success' => false, 'denied' => true, 'reason' => 'app', 'verb' => self::VERB );
		}
		$zone  = self::zone();
		$now   = self::now();
		$today = ( new DateTime( '@' . $now ) )->setTimezone( $zone )->format( 'Y-m-d' );
		$day   = self::day( $args['day'] ?? '' );
		$day   = null === $day ? $today : $day;
		$next  = ( new DateTime( $day . ' 12:00:00', $zone ) )->modify( '+1 day' )->format( 'Y-m-d' );
		try {
			list( $s1, $e1, $a1, $b1 ) = self::bounds( $day, $zone );
			list( $s2, $e2, $a2, $b2 ) = self::bounds( $next, $zone );
			$rows1 = self::read( $uid, $s1, $e1 );
			$rows2 = null === $rows1 ? null : self::read( $uid, $s2, $e2 );
		} catch ( \Throwable $e ) {
			error_log( 'Zorderz Scheduler ' . self::VERB . ': ' . $e->getMessage() );
			$rows1 = null;
			$rows2 = null;
		}
		if ( ! is_array( $rows1 ) || ! is_array( $rows2 ) ) {
			return array( 'success' => false, 'error' => 'Your calendar could not be read just now.', 'verb' => self::VERB );
		}
		$t1 = self::tally( self::mine( $rows1, $uid ), $a1, $b1, $now );
		$t2 = self::tally( self::mine( $rows2, $uid ), $a2, $b2, $now );
		return array(
			'success'     => true,
			'verb'        => self::VERB,
			'day'         => $day,
			'tz'          => $zone->getName(),
			'counts'      => array( 'today' => $t1['n'], 'ahead' => $t1['ahead'], 'all_day' => $t1['all_day'], 'tomorrow' => $t2['n'] ),
			'next_at'     => $t1['next_at'],
			'definitions' => self::definitions(),
			'read_at'     => $now,
		);
	}
}
