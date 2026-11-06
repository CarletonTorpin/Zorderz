<?php
/**
 * ZJOB_My_Counts — "how many installs are mine this week?", published as the read verb `jobs.my_next_counts` for the
 * field tech's home tile.
 *
 * WHAT COUNTS. Exactly what My next shows this person (ZJOB_My_Next::for_viewer, lean: no walks, no money steps), so
 * the tile and the list never disagree. Of those steps:
 *   install   an install visit on the calendar for a sold Project not yet finished, where this person is on the visit
 *             or is its confirmed installer. Its day is the visit's start in the business time zone:
 *               today      the day is today;
 *               this_week  the day is today or later, up to the end of the asked week (today's included);
 *               passed     the day is before today and the work is still not finished;
 *               later      after the asked week (not counted; next_install_day names the first such day, '' when none).
 *   other     every other step of this person's in My next (order materials, shop prep, schedule the install).
 *
 * WHO ASKS. Anyone who holds the Jobs app (viewer tier and up): their OWN steps only. The shared device and nobody
 * signed in are refused. Counts and a day only: never a customer, an address, a Project number or money. A failed read
 * is an error (success false), never 0. `read_at` is when Projects was read.
 *
 * @package Zorderz\Jobs
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZJOB_My_Counts {

	const VERB     = 'jobs.my_next_counts';
	const MAX_DAYS = 14; // the asked window ("this week") is never longer than this

	/** Injected in tests: "now" (unix) and the steps reader (callable( $uid ) => My next's answer). */
	public static $now  = null;
	public static $read = null;

	public static function init(): void {
		add_filter( 'zdz_register_capabilities', array( __CLASS__, 'register' ) );
	}

	public static function register( $caps ) {
		$caps                = is_array( $caps ) ? $caps : array();
		$caps[ self::VERB ]  = self::descriptor();
		return $caps;
	}

	public static function descriptor(): array {
		return array(
			'verb'          => self::VERB,
			'provider'      => 'zjob',
			'app'           => defined( 'ZJOB_APP_ID' ) ? ZJOB_APP_ID : 'jobs',
			'tier'          => 'viewer', // a tech and up: each person's own steps
			'needs_app'     => true,     // My next lives in the Jobs app
			'callback'      => array( __CLASS__, 'from_capability' ),
			'kiosk'         => false,
			'kiosk_bounded' => false,
			'side_effect'   => false,
			'summary'       => 'Your own install visits (today, the rest of the week, passed and not finished) and your other next steps, as My next shows them. Counts and a day only.',
		);
	}

	/** A 'Y-m-d' that is a real day, or null. PURE. */
	public static function day( $v ): ?string {
		$v = is_scalar( $v ) ? trim( (string) $v ) : '';
		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m ) || ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
			return null;
		}
		return $v;
	}

	/** The business time zone: the profile's, else the site's, else UTC. */
	public static function tz(): DateTimeZone {
		$name = '';
		if ( class_exists( 'ZDZ_Business_Profile' ) && method_exists( 'ZDZ_Business_Profile', 'get' ) ) {
			$name = (string) ZDZ_Business_Profile::get( 'locale.timezone' );
		}
		if ( '' === $name && function_exists( 'wp_timezone_string' ) ) {
			$name = (string) wp_timezone_string();
		}
		try {
			return new DateTimeZone( '' !== $name ? $name : 'UTC' );
		} catch ( \Throwable $e ) {
			return new DateTimeZone( 'UTC' );
		}
	}

	/** A UTC 'Y-m-d H:i:s' (or ISO) as a local day in $tz; '' when it cannot be read. PURE. */
	public static function local_day( string $utc, DateTimeZone $tz ): string {
		$utc = trim( $utc );
		if ( '' === $utc || 0 === strpos( $utc, '0000-00-00' ) ) {
			return '';
		}
		try {
			$d = new DateTime( $utc, new DateTimeZone( 'UTC' ) );
			$d->setTimezone( $tz );
			return $d->format( 'Y-m-d' );
		} catch ( \Throwable $e ) {
			return '';
		}
	}

	private static function now(): int {
		return null !== self::$now ? (int) self::$now : time();
	}

	/**
	 * Count My next's steps. PURE: $present is My next's `present` list; $today, $from and $to are local days in $tz.
	 *
	 * @return array{counts:array{today:int,this_week:int,passed:int,other:int},next_install_day:string}
	 */
	public static function tally( array $present, string $today, string $from, string $to, DateTimeZone $tz ): array {
		$c    = array( 'today' => 0, 'this_week' => 0, 'passed' => 0, 'other' => 0 );
		$next = '';
		foreach ( $present as $it ) {
			$step = (string) ( $it['step'] ?? '' );
			if ( 'install' !== $step ) {
				if ( '' !== $step && 'walk' !== $step && empty( $it['money'] ) && ! in_array( $step, array( 'invoice', 'payment' ), true ) ) {
					$c['other']++;
				}
				continue;
			}
			$d = self::local_day( (string) ( $it['when'] ?? '' ), $tz );
			if ( '' === $d ) {
				$c['other']++; // an install step with no readable day is still this person's to do; never dropped
				continue;
			}
			if ( $d < $today ) {
				$c['passed']++;
				continue;
			}
			if ( $d === $today ) {
				$c['today']++;
			}
			if ( $d >= $from && $d <= $to ) {
				$c['this_week']++;
			} elseif ( $d > $to && ( '' === $next || $d < $next ) ) {
				$next = $d;
			}
		}
		return array( 'counts' => $c, 'next_install_day' => $next );
	}

	/** The words sent with every answer, so the tile can say exactly what it counts. */
	public static function definitions(): array {
		return array(
			'this_week' => 'Install visits on your calendar from today to the end of the week, on work not yet finished, where you are on the visit or are its confirmed installer: what My next lists.',
			'today'     => 'Those whose visit is today.',
			'passed'    => 'Visits whose day has passed and the work is still not finished.',
			'other'     => 'Your other next steps in My next: order materials, shop prep, schedule an install.',
		);
	}

	/**
	 * The verb. $args: from, to ('Y-m-d', the week the tile shows; default today and six days on) and today ('Y-m-d',
	 * default today in the business time zone). Identity is the registry's stamp only.
	 */
	public static function from_capability( array $args ): array {
		$uid   = (int) ( $args['user_id'] ?? 0 );
		$kiosk = ! empty( $args['is_kiosk'] ) || 'kiosk' === (string) ( $args['tier'] ?? '' )
			|| ( $uid > 0 && class_exists( 'ZJOB_My_Next' ) && ! ZJOB_My_Next::is_person( $uid ) );
		if ( $uid <= 0 ) {
			return array( 'success' => false, 'denied' => true, 'reason' => 'signed_out', 'verb' => self::VERB );
		}
		if ( $kiosk ) {
			return array( 'success' => false, 'denied' => true, 'reason' => 'kiosk', 'verb' => self::VERB );
		}
		$tz    = self::tz();
		$now   = self::now();
		$today = self::day( $args['today'] ?? '' ) ?? ( new DateTimeImmutable( '@' . $now ) )->setTimezone( $tz )->format( 'Y-m-d' );
		$from  = self::day( $args['from'] ?? '' ) ?? $today;
		$to    = self::day( $args['to'] ?? '' ) ?? ( new DateTimeImmutable( $today, $tz ) )->modify( '+6 days' )->format( 'Y-m-d' );
		if ( $to < $from ) {
			return array( 'success' => false, 'error' => 'The week must end on or after its first day.', 'verb' => self::VERB );
		}
		$span = (int) ( new DateTimeImmutable( $from ) )->diff( new DateTimeImmutable( $to ) )->days;
		if ( $span >= self::MAX_DAYS ) {
			return array( 'success' => false, 'error' => 'The week is at most ' . self::MAX_DAYS . ' days.', 'verb' => self::VERB );
		}
		try {
			if ( is_callable( self::$read ) ) {
				$mine = call_user_func( self::$read, $uid );
			} elseif ( class_exists( 'ZJOB_My_Next' ) ) {
				$mine = ZJOB_My_Next::for_viewer( $uid, true );
			} else {
				$mine = null;
			}
		} catch ( \Throwable $e ) {
			error_log( 'Zorderz Jobs ' . self::VERB . ': ' . $e->getMessage() );
			$mine = null;
		}
		global $wpdb;
		if ( ! is_array( $mine ) || ! isset( $mine['present'] ) || ! is_array( $mine['present'] )
			|| ( isset( $wpdb ) && is_object( $wpdb ) && ! empty( $wpdb->last_error ) ) ) {
			return array( 'success' => false, 'error' => 'Your jobs could not be read just now.', 'verb' => self::VERB );
		}
		$t = self::tally( $mine['present'], $today, $from, $to, $tz );
		return array(
			'success'          => true,
			'verb'             => self::VERB,
			'counts'           => $t['counts'],
			'next_install_day' => $t['next_install_day'],
			'window'           => array( 'from' => $from, 'to' => $to, 'today' => $today ),
			'definitions'      => self::definitions(),
			'read_at'          => $now,
		);
	}
}
