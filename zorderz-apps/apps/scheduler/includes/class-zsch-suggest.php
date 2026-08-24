<?php
/**
 * Zsch_Suggest — genuinely-free time, from LOCAL reads, ZERO network.
 *
 * open_slots() offers a handful of free start times for the intake editor. It is
 * ADVISORY: a human picks and confirms; nothing here books. It walks a business-
 * hours grid over a short horizon and drops any slot that overlaps a busy interval
 * gathered from THREE LOCAL READS (no provider call on this interactive path):
 *
 *   1. the owner's own local appointments      (ZSCH_Appointments::query)
 *   2. the owner's painted BUSY availability    (ZSCH_Availability::query)
 *   3. events the owner is a PARTICIPANT of      (ZSCH_Participants::busy_for)
 *
 * (Connected-calendar external busy is also folded in when present — but it too is
 * a local mirror read (ZSCH_Sync::read_busy), never a live provider call.)
 *
 * Business hours + duration are [IDENTITY→profile]: business hours via the
 * `zsch_business_hours` filter (Core default Mon–Fri 08:00–17:00) and the duration
 * via the EXISTING ZJOB_Scheduler::default_duration_min() mechanism (never a second
 * hardcode of the already-generalized 2-hour default). tz via ZSCH_Settings.
 *
 * @since 1.9.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Zsch_Suggest {

	/** How far ahead to look for free time. */
	const HORIZON_DAYS = 14;

	/** How many slots to offer. */
	const MAX_SLOTS = 6;

	/** Grid granularity, minutes (the :00 / :30 grid). */
	const GRID_MIN = 30;

	/**
	 * Core-default duration fallback (minutes) used ONLY when neither the intake
	 * bundle nor the ZJOB_Scheduler mechanism yields one. Mirrors the platform's
	 * already-generalized 2-hour default; the authoritative, tunable value is the
	 * `zdz_job_appt_duration_min` filter read via ZJOB_Scheduler.
	 */
	const DURATION_FALLBACK_MIN = 120;

	/**
	 * Offer free start times for the actor (or a named owner).
	 *
	 * @param int   $actor
	 * @param array $args { duration_min?:int, owner_id?:int, horizon_days?:int }
	 * @return array<int,array{start_local:string,end_local:string,start_utc:string,end_utc:string}>
	 */
	public static function open_slots( int $actor, array $args = array() ): array {
		if ( $actor <= 0 ) {
			return array();
		}
		$owner    = (int) ( $args['owner_id'] ?? 0 );
		$owner    = $owner > 0 ? $owner : $actor;
		$duration = (int) ( $args['duration_min'] ?? 0 );
		$duration = $duration > 0 ? $duration : self::default_duration_min();
		$duration = max( self::GRID_MIN, $duration );
		$horizon  = (int) ( $args['horizon_days'] ?? self::HORIZON_DAYS );
		$horizon  = max( 1, min( 60, $horizon ) );

		$tz = self::tz();
		try {
			$zone = new DateTimeZone( $tz );
			$utc  = new DateTimeZone( 'UTC' );
		} catch ( \Exception $e ) {
			return array();
		}

		// The UTC window we gather busy intervals over.
		$now         = new DateTime( 'now', $utc );
		$window_end  = ( clone $now )->modify( '+' . $horizon . ' days' );
		$win_start_s = $now->format( 'Y-m-d H:i:s' );
		$win_end_s   = $window_end->format( 'Y-m-d H:i:s' );

		$busy = self::gather_busy( $actor, $owner, $win_start_s, $win_end_s ); // array of [start_ts,end_ts]

		$hours = self::business_hours();
		$slots = array();

		// Walk each day in the horizon; within business hours, step the grid.
		$day = new DateTime( 'now', $zone );
		$day->setTime( 0, 0, 0 );
		for ( $d = 0; $d <= $horizon && count( $slots ) < self::MAX_SLOTS; $d++ ) {
			$cursor = ( clone $day )->modify( '+' . $d . ' days' );
			$dow    = (int) $cursor->format( 'N' ); // 1 (Mon) … 7 (Sun)
			if ( ! in_array( $dow, $hours['days'], true ) ) {
				continue;
			}
			list( $bh_start, $bh_end ) = self::day_bounds( $cursor, $hours, $zone );
			$slot = clone $bh_start;
			while ( $slot->getTimestamp() + ( $duration * 60 ) <= $bh_end->getTimestamp() ) {
				if ( count( $slots ) >= self::MAX_SLOTS ) {
					break;
				}
				$slot_end = ( clone $slot )->modify( '+' . $duration . ' minutes' );
				// Never suggest a slot in the past.
				$slot_utc = ( clone $slot )->setTimezone( $utc );
				if ( $slot_utc->getTimestamp() >= $now->getTimestamp() && ! self::overlaps_busy( $slot_utc, $duration, $busy ) ) {
					$end_utc = ( clone $slot_end )->setTimezone( $utc );
					$slots[] = array(
						'start_local' => $slot->format( 'Y-m-d H:i' ),
						'end_local'   => $slot_end->format( 'Y-m-d H:i' ),
						'start_utc'   => $slot_utc->format( 'Y-m-d\TH:i:s\Z' ),
						'end_utc'     => $end_utc->format( 'Y-m-d\TH:i:s\Z' ),
					);
				}
				$slot->modify( '+' . self::GRID_MIN . ' minutes' );
			}
		}
		return $slots;
	}

	/* ===================================================================
	 * INTERNAL
	 * =================================================================== */

	/** Gather busy intervals [ [start_ts,end_ts], … ] from the three local reads. */
	private static function gather_busy( int $actor, int $owner, string $win_start_s, string $win_end_s ): array {
		$intervals = array();

		// 1) The owner's own appointments (local).
		if ( class_exists( 'ZSCH_Appointments' ) && is_callable( array( 'ZSCH_Appointments', 'query' ) ) ) {
			$rows = ZSCH_Appointments::query( $actor, $win_start_s, $win_end_s, array( 'scope' => 'all', 'owner_id' => $owner ) );
			foreach ( (array) $rows as $r ) {
				$s = self::iso_to_ts( (string) ( $r['start_utc'] ?? '' ) );
				$e = self::iso_to_ts( (string) ( $r['end_utc'] ?? '' ) );
				if ( $s && $e && 'free' !== (string) ( $r['busy_status'] ?? 'busy' ) ) {
					$intervals[] = array( $s, $e );
				}
			}
		}

		// 2) The owner's painted BUSY availability (local).
		if ( class_exists( 'ZSCH_Availability' ) && is_callable( array( 'ZSCH_Availability', 'query' ) ) ) {
			$blocks = ZSCH_Availability::query( array( $owner ), $win_start_s, $win_end_s );
			foreach ( (array) $blocks as $b ) {
				if ( 'busy' !== (string) ( $b['kind'] ?? '' ) ) {
					continue;
				}
				$s = self::iso_to_ts( (string) ( $b['start_utc'] ?? '' ) );
				$e = self::iso_to_ts( (string) ( $b['end_utc'] ?? '' ) );
				if ( $s && $e ) {
					$intervals[] = array( $s, $e );
				}
			}
		}

		// 3) Events the owner is a PARTICIPANT of (local).
		if ( class_exists( 'ZSCH_Participants' ) && is_callable( array( 'ZSCH_Participants', 'busy_for' ) ) ) {
			foreach ( ZSCH_Participants::busy_for( $owner, $win_start_s, $win_end_s ) as $p ) {
				$s = self::mysql_to_ts( (string) ( $p['start_utc'] ?? '' ) );
				$e = self::mysql_to_ts( (string) ( $p['end_utc'] ?? '' ) );
				if ( $s && $e ) {
					$intervals[] = array( $s, $e );
				}
			}
		}

		// (also) external connected-calendar busy — a LOCAL mirror read, no network.
		if ( class_exists( 'ZSCH_Sync' ) && is_callable( array( 'ZSCH_Sync', 'conflicts_for' ) ) ) {
			foreach ( ZSCH_Sync::conflicts_for( $owner, $win_start_s, $win_end_s ) as $c ) {
				$s = self::iso_to_ts( (string) ( $c['start_utc'] ?? '' ) );
				$e = self::iso_to_ts( (string) ( $c['end_utc'] ?? '' ) );
				if ( $s && $e ) {
					$intervals[] = array( $s, $e );
				}
			}
		}

		return $intervals;
	}

	/** Does [slot_utc, +duration] overlap any busy interval? */
	private static function overlaps_busy( DateTime $slot_utc, int $duration_min, array $busy ): bool {
		$s = $slot_utc->getTimestamp();
		$e = $s + ( $duration_min * 60 );
		foreach ( $busy as $iv ) {
			// Overlap iff slot starts before the interval ends AND ends after it starts.
			if ( $s < $iv[1] && $e > $iv[0] ) {
				return true;
			}
		}
		return false;
	}

	/** Business-hours start/end DateTimes for a given day (in the business tz). */
	private static function day_bounds( DateTime $day, array $hours, DateTimeZone $zone ): array {
		list( $sh, $sm ) = self::hm( $hours['start'], 8, 0 );
		list( $eh, $em ) = self::hm( $hours['end'], 17, 0 );
		$start = clone $day;
		$start->setTime( $sh, $sm, 0 );
		$end = clone $day;
		$end->setTime( $eh, $em, 0 );
		return array( $start, $end );
	}

	/**
	 * Business hours — [IDENTITY→profile] via `zsch_business_hours`. Core default
	 * Mon–Fri 08:00–17:00. Validated so a bad filter value cannot break the walk.
	 *
	 * @return array{days:int[],start:string,end:string}
	 */
	public static function business_hours(): array {
		$default = array( 'days' => array( 1, 2, 3, 4, 5 ), 'start' => '08:00', 'end' => '17:00' );
		$h = function_exists( 'apply_filters' ) ? apply_filters( 'zsch_business_hours', $default ) : $default;
		if ( ! is_array( $h ) ) {
			return $default;
		}
		$days = array();
		foreach ( (array) ( $h['days'] ?? $default['days'] ) as $d ) {
			$d = (int) $d;
			if ( $d >= 1 && $d <= 7 ) {
				$days[] = $d;
			}
		}
		if ( empty( $days ) ) {
			$days = $default['days'];
		}
		$start = self::valid_hm( (string) ( $h['start'] ?? '' ) ) ? (string) $h['start'] : $default['start'];
		$end   = self::valid_hm( (string) ( $h['end'] ?? '' ) ) ? (string) $h['end'] : $default['end'];
		return array( 'days' => $days, 'start' => $start, 'end' => $end );
	}

	/** Duration via the existing ZJOB_Scheduler mechanism (no new hardcode). */
	private static function default_duration_min(): int {
		if ( class_exists( 'ZJOB_Scheduler' ) && is_callable( array( 'ZJOB_Scheduler', 'default_duration_min' ) ) ) {
			$d = (int) ZJOB_Scheduler::default_duration_min();
			if ( $d > 0 ) {
				return $d;
			}
		}
		return self::DURATION_FALLBACK_MIN;
	}

	private static function tz(): string {
		if ( class_exists( 'ZSCH_Settings' ) && is_callable( array( 'ZSCH_Settings', 'default_tz' ) ) ) {
			$tz = (string) ZSCH_Settings::default_tz();
			if ( '' !== $tz ) {
				return $tz;
			}
		}
		return 'UTC';
	}

	/** "HH:MM" → [h,m] with fallbacks. */
	private static function hm( $val, int $dh, int $dm ): array {
		if ( self::valid_hm( (string) $val ) ) {
			$p = explode( ':', (string) $val );
			return array( (int) $p[0], (int) $p[1] );
		}
		return array( $dh, $dm );
	}

	private static function valid_hm( string $val ): bool {
		return (bool) preg_match( '/^([01]?\d|2[0-3]):[0-5]\d$/', trim( $val ) );
	}

	/** ISO 'Y-m-dTH:i:sZ' → unix ts (0 on failure). */
	private static function iso_to_ts( string $iso ): int {
		$iso = trim( $iso );
		if ( '' === $iso ) {
			return 0;
		}
		$ts = strtotime( $iso );
		return $ts ? (int) $ts : 0;
	}

	/** MySQL UTC 'Y-m-d H:i:s' → unix ts (0 on failure). */
	private static function mysql_to_ts( string $s ): int {
		$s = trim( $s );
		if ( '' === $s || '0000-00-00 00:00:00' === $s ) {
			return 0;
		}
		$ts = strtotime( $s . ' UTC' );
		return $ts ? (int) $ts : 0;
	}
}
