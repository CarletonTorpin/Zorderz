<?php
/**
 * ZSCH_Tzdata — the zone database this scheduler's correctness rests on,
 * made observable, and the one place a wall-clock time becomes an instant.
 *
 * ── WHY THIS FILE EXISTS ────────────────────────────────────────────────
 *
 * The recurrence engine places every occurrence on the wall clock of its zone,
 * so its correctness depends on the zone database PHP has loaded. That database
 * cannot always be identified by version:
 *
 *   - `timezone_version_get()` returns the bundled `timezonedb` release only
 *     when the PECL extension is loaded. Without it PHP uses the operating
 *     system's tzdata and returns the literal string `0.system` — forever,
 *     across every tzdata update the OS ever applies.
 *   - So on many hosts the version string cannot change, cannot be compared,
 *     and cannot warn anybody, and pinning the extension is not always possible.
 *
 * WHEN YOU CANNOT PIN THE VERSION, PIN THE DATA, AND PIN THE BEHAVIOUR.
 *
 * FINGERPRINT (pin the data). A hash of the real transition table of the
 * declared zones over a fixed window. If a rule we depend on moves, the
 * fingerprint moves, whatever the version string says.
 *
 * SELFTEST (pin the behaviour). The resolution semantics the engine depends on
 * (resolve()) are asserted against the running zone database, at a transition
 * DISCOVERED IN THE DATA rather than a date written here. A zone with no
 * seasonal time simply has no transition to test, and the check reports
 * "nothing to assert" instead of failing — a check that cries wolf on a change
 * in the law would be switched off, and then it protects nothing.
 *
 * @since 1.10.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZSCH_Tzdata {

	/**
	 * The zones whose rules this install's correctness depends on.
	 *
	 * Core declares only UTC. The business's own zone is added at run time by
	 * zones() from the scheduler's default time zone (which reads the Business
	 * Profile), and any other component may add more on `zsch_tzdata_zones`.
	 */
	const DEFAULT_ZONES = array( 'UTC' );

	/**
	 * The fingerprint window. FIXED calendar bounds, never "now ± n years".
	 *
	 * A sliding window re-fingerprints itself as time passes, so it would raise a
	 * change every time it slid past a transition — a yearly false alarm, which is
	 * how a real one gets scrolled past. Fixed bounds make the fingerprint a pure
	 * function of the zone data. The window covers every instant an appointment in
	 * this system can plausibly fall on.
	 */
	const WINDOW_FROM = '2020-01-01T00:00:00Z';
	const WINDOW_TO   = '2035-01-01T00:00:00Z';

	/* ═══════════════════════════════════════════════════════════════════
	 * Wall clock → instant, with the semantics the engine relies on
	 * ═══════════════════════════════════════════════════════════════════ */

	/**
	 * A local wall-clock time in $zone, as the instant RFC 5545 means. PURE.
	 *
	 * PHP's own `new DateTime( $local, $zone )` does not do this consistently:
	 * for some zones it resolves the repeated hour to the second occurrence. The
	 * scheduler therefore resolves every wall-clock time through here, and the
	 * self-test asserts this function — the thing the engine depends on —
	 * against the zone database actually loaded.
	 *
	 * @param string       $local 'Y-m-d H:i[:s]' wall clock.
	 * @param DateTimeZone $zone
	 * @return DateTime|null the instant, expressed in $zone; null when unparseable.
	 */
	public static function resolve( $local, DateTimeZone $zone ) {
		try {
			$wall_dt = new DateTime( (string) $local, new DateTimeZone( 'UTC' ) );
		} catch ( Exception $e ) {
			return null;
		}
		$wall = $wall_dt->getTimestamp();   // the wall clock, counted as if it were UTC

		// Every offset the zone uses near this wall time is a candidate.
		$trs = $zone->getTransitions( $wall - 2 * 86400, $wall + 2 * 86400 );
		if ( ! is_array( $trs ) || empty( $trs ) ) {
			$off = $zone->getOffset( new DateTime( '@' . $wall ) );
			return ( new DateTime( '@' . ( $wall - $off ) ) )->setTimezone( $zone );
		}
		$offsets = array();
		foreach ( $trs as $t ) {
			$offsets[ (int) $t['offset'] ] = true;
		}
		$valid = array();
		foreach ( array_keys( $offsets ) as $off ) {
			$ts = $wall - $off;
			if ( (int) $zone->getOffset( new DateTime( '@' . $ts ) ) === $off ) {
				$valid[] = $ts;
			}
		}
		if ( ! empty( $valid ) ) {
			return ( new DateTime( '@' . min( $valid ) ) )->setTimezone( $zone );   // the first occurrence
		}

		// Inside a gap: use the offset in force before the transition that opened it.
		$prev = null;
		foreach ( $trs as $t ) {
			if ( null !== $prev && (int) $t['offset'] > (int) $prev['offset']
				&& $wall >= (int) $t['ts'] + (int) $prev['offset']
				&& $wall < (int) $t['ts'] + (int) $t['offset'] ) {
				return ( new DateTime( '@' . ( $wall - (int) $prev['offset'] ) ) )->setTimezone( $zone );
			}
			$prev = $t;
		}
		try {
			return new DateTime( (string) $local, $zone );   // not reachable on a sane database
		} catch ( Exception $e ) {
			return null;
		}
	}

	/**
	 * resolve(), as a UTC 'Y-m-d H:i:s' string. PURE.
	 *
	 * @param string $local
	 * @param string $tz
	 * @return string '' when the time or the zone is unusable.
	 */
	public static function local_to_utc( $local, $tz ) {
		try {
			$zone = new DateTimeZone( (string) $tz );
		} catch ( Exception $e ) {
			return '';
		}
		$dt = self::resolve( $local, $zone );
		return $dt ? $dt->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ) : '';
	}

	/* ═══════════════════════════════════════════════════════════════════
	 * What the runtime says about itself
	 * ═══════════════════════════════════════════════════════════════════ */

	/**
	 * The zone-database version PHP reports. PURE.
	 *
	 * '0.system' means the OS tzdata is in use and this string will never move,
	 * whatever the OS does. Treat it as "unknown", not as a version.
	 *
	 * @return string
	 */
	public static function version() {
		$v = function_exists( 'timezone_version_get' ) ? (string) timezone_version_get() : '';
		return ( '' === $v ) ? '0.system' : $v;
	}

	/**
	 * Is the zone database pinned to a version we can actually compare? PURE.
	 *
	 * @return bool
	 */
	public static function pinned() {
		return '0.system' !== self::version();
	}

	/* ═══════════════════════════════════════════════════════════════════
	 * Pin the data — a fingerprint of the transitions themselves
	 * ═══════════════════════════════════════════════════════════════════ */

	/**
	 * A stable short hash of the DST transition table for $zones. PURE.
	 *
	 * Hashes the triple (instant, offset, isdst) of every transition in the fixed
	 * window. Abbreviations are deliberately excluded: tzdata revises names like
	 * "+07"/"WIB" without changing a single instant anybody is scheduled at, and a
	 * fingerprint that moves on a cosmetic change is one nobody trusts.
	 *
	 * @param array|null  $zones Zone ids; defaults to DEFAULT_ZONES.
	 * @return string 16 hex chars, or '' if no zone could be read at all.
	 */
	public static function fingerprint( $zones = null ) {
		$zones = self::normalise_zone_list( $zones );
		$from  = strtotime( self::WINDOW_FROM );
		$to    = strtotime( self::WINDOW_TO );

		$parts = array();
		foreach ( $zones as $zone ) {
			try {
				$dtz = new DateTimeZone( $zone );
			} catch ( Exception $e ) {
				/* An unreadable zone is itself a fact worth fingerprinting: it means
				 * the database no longer carries a zone we depend on. */
				$parts[] = $zone . '=UNREADABLE';
				continue;
			}
			$sig = array();
			foreach ( $dtz->getTransitions( $from, $to ) as $t ) {
				$sig[] = $t['ts'] . ':' . $t['offset'] . ':' . ( empty( $t['isdst'] ) ? '0' : '1' );
			}
			$parts[] = $zone . '=' . implode( ',', $sig );
		}

		if ( empty( $parts ) ) {
			return '';
		}
		return substr( hash( 'sha256', implode( '|', $parts ) ), 0, 16 );
	}

	/**
	 * Normalise + de-duplicate a zone list, preserving a deterministic order. PURE.
	 *
	 * Sorted, because a fingerprint that depends on the order its zones were
	 * listed in is not a fingerprint of anything.
	 *
	 * @param array|null $zones
	 * @return array
	 */
	public static function normalise_zone_list( $zones = null ) {
		if ( ! is_array( $zones ) || empty( $zones ) ) {
			$zones = self::DEFAULT_ZONES;
		}
		$zones = array_values( array_unique( array_filter( array_map( 'strval', $zones ), 'strlen' ) ) );
		sort( $zones );
		return $zones;
	}

	/* ═══════════════════════════════════════════════════════════════════
	 * Pin the behaviour — the three semantics the engine rests on
	 * ═══════════════════════════════════════════════════════════════════ */

	/**
	 * Find the first offset-INCREASING transition (a spring-forward gap) in the
	 * window, reading it out of the zone data rather than assuming a date. PURE.
	 *
	 * @param string   $zone
	 * @param int|null $at Prefer the first such transition at or after this instant.
	 * @return array|null { ts, before, after } offsets in seconds, or null if the
	 *                    zone has no such transition (e.g. seasonal time abolished).
	 */
	public static function find_gap( $zone, $at = null ) {
		return self::find_shift( $zone, 1, $at );
	}

	/**
	 * Find an offset-DECREASING transition (an ambiguous fall-back). PURE.
	 *
	 * @param string   $zone
	 * @param int|null $at
	 * @return array|null { ts, before, after }
	 */
	public static function find_overlap( $zone, $at = null ) {
		return self::find_shift( $zone, -1, $at );
	}

	/**
	 * @param string   $zone
	 * @param int      $direction +1 for a gap, -1 for an overlap.
	 * @param int|null $at        Prefer the first match at or after this instant.
	 *
	 * Prefers the NEXT upcoming transition rather than the window's first, so the
	 * evidence the Health panel prints is about a date an operator recognises —
	 * the first transition in a fixed window is years old, and old evidence reads
	 * as stale even when the check it reports is live. Falls back to
	 * the last match in the window if every transition is already in the past, so a
	 * zone whose rules end still gets checked.
	 *
	 * @return array|null
	 */
	private static function find_shift( $zone, $direction, $at = null ) {
		try {
			$dtz = new DateTimeZone( $zone );
		} catch ( Exception $e ) {
			return null;
		}
		$trs = $dtz->getTransitions( strtotime( self::WINDOW_FROM ), strtotime( self::WINDOW_TO ) );
		if ( ! is_array( $trs ) || count( $trs ) < 2 ) {
			return null;
		}
		$at     = ( null === $at ) ? time() : (int) $at;
		$latest = null;

		for ( $i = 1, $n = count( $trs ); $i < $n; $i++ ) {
			$before = (int) $trs[ $i - 1 ]['offset'];
			$after  = (int) $trs[ $i ]['offset'];
			if ( 0 === $after - $before ) {
				continue;
			}
			if ( ( $after > $before ) !== ( $direction > 0 ) ) {
				continue;
			}
			$hit = array(
				'ts'     => (int) $trs[ $i ]['ts'],
				'before' => $before,
				'after'  => $after,
			);
			if ( $hit['ts'] >= $at ) {
				return $hit;            // the next one — what an operator expects to see
			}
			$latest = $hit;             // remember the most recent past one as a fallback
		}
		return $latest;
	}

	/**
	 * Assert the resolution semantics the recurrence engine depends on, against
	 * the zone database that is actually loaded right now. PURE.
	 *
	 * Each check is {name, status, detail}. status is 'pass', 'fail' or 'n/a'.
	 * 'n/a' is a first-class answer: a zone with no seasonal time has no gap to
	 * resolve, and reporting that honestly is the difference between a check that
	 * survives a change in the law and one that gets switched off.
	 *
	 * @param string|null $zone null = the primary declared zone (primary_zone()).
	 * @return array list of checks
	 */
	public static function selftest( $zone = null ) {
		if ( null === $zone || '' === (string) $zone ) {
			$zone = self::primary_zone();
		}
		$checks = array();

		try {
			$tz = new DateTimeZone( $zone );
		} catch ( Exception $e ) {
			return array( array(
				'name'   => 'zone readable',
				'status' => 'fail',
				'detail' => sprintf( '%s is not a zone this PHP can read at all.', $zone ),
			) );
		}
		$utc = new DateTimeZone( 'UTC' );

		/* ── 1. Spring-forward gap: the PRE-gap offset must be used ────────── */
		$gap = self::find_gap( $zone );
		if ( null === $gap ) {
			$checks[] = array(
				'name'   => 'spring-forward gap uses the pre-gap offset',
				'status' => 'n/a',
				'detail' => sprintf( '%s has no offset-increasing transition in the window — nothing to resolve.', $zone ),
			);
		} else {
			$half  = intdiv( $gap['after'] - $gap['before'], 2 );
			$wall  = $gap['ts'] + $gap['before'] + $half;          // a local time inside the gap
			$local = gmdate( 'Y-m-d H:i:s', $wall );
			$want  = $wall - $gap['before'];                        // resolved at the PRE-gap offset
			$res = self::resolve( $local, $tz );
			$got = $res ? $res->getTimestamp() : null;
			$checks[] = array(
				'name'   => 'spring-forward gap uses the pre-gap offset',
				'status' => ( $got === $want ) ? 'pass' : 'fail',
				'detail' => sprintf(
					'local %s (nonexistent) → %s UTC; RFC 5545 section 3.3.10 as corrected by Errata 4271 wants %s UTC.',
					$local,
					( null === $got ) ? 'unparseable' : gmdate( 'Y-m-d H:i:s', $got ),
					gmdate( 'Y-m-d H:i:s', $want )
				),
			);
		}

		/* ── 2. Fall-back overlap: the FIRST occurrence must win ───────────── */
		$ovl = self::find_overlap( $zone );
		if ( null === $ovl ) {
			$checks[] = array(
				'name'   => 'fall-back overlap resolves to the first occurrence',
				'status' => 'n/a',
				'detail' => sprintf( '%s has no offset-decreasing transition in the window — nothing is ambiguous.', $zone ),
			);
		} else {
			$half  = intdiv( $ovl['before'] - $ovl['after'], 2 );
			$wall  = $ovl['ts'] + $ovl['after'] + $half;            // a local time inside the overlap
			$local = gmdate( 'Y-m-d H:i:s', $wall );
			$want  = $wall - $ovl['before'];                        // the FIRST of the two instants
			$res = self::resolve( $local, $tz );
			$got = $res ? $res->getTimestamp() : null;
			$checks[] = array(
				'name'   => 'fall-back overlap resolves to the first occurrence',
				'status' => ( $got === $want ) ? 'pass' : 'fail',
				'detail' => sprintf(
					'local %s (occurs twice) → %s UTC; the earlier instant is %s UTC.',
					$local,
					( null === $got ) ? 'unparseable' : gmdate( 'Y-m-d H:i:s', $got ),
					gmdate( 'Y-m-d H:i:s', $want )
				),
			);
		}

		/* ── 3. A nominal day stays on its wall clock across a transition ──── */
		if ( null === $gap ) {
			$checks[] = array(
				'name'   => 'a nominal day keeps its wall-clock time across a shift',
				'status' => 'n/a',
				'detail' => 'no transition in the window to carry a day across.',
			);
		} else {
			/* 09:00 local on the day before the shift, advanced by one CALENDAR day.
			  */
			$day_before = gmdate( 'Y-m-d', $gap['ts'] + $gap['before'] - 86400 );
			try {
				$a       = new DateTime( $day_before . ' 09:00:00', $tz );
				$b       = ( clone $a )->modify( '+1 day' );
				$elapsed = $b->getTimestamp() - $a->getTimestamp();
				$kept    = ( '09:00' === $b->format( 'H:i' ) );
			} catch ( Exception $e ) {
				$kept    = false;
				$elapsed = 0;
			}
			$checks[] = array(
				'name'   => 'a nominal day keeps its wall-clock time across a shift',
				'status' => $kept ? 'pass' : 'fail',
				'detail' => sprintf(
					'%s 09:00 +1 day → %s (%d h elapsed). A nominal day is a calendar day, not 24 h.',
					$day_before,
					$kept ? '09:00 local' : 'NOT 09:00 local',
					(int) round( $elapsed / 3600 )
				),
			);
		}

		return $checks;
	}

	/**
	 * Roll a selftest up to one word. PURE.
	 *
	 * @param array $checks
	 * @return string 'pass' | 'fail' | 'n/a'
	 */
	public static function selftest_status( array $checks ) {
		$seen_pass = false;
		foreach ( $checks as $c ) {
			if ( 'fail' === $c['status'] ) {
				return 'fail';
			}
			if ( 'pass' === $c['status'] ) {
				$seen_pass = true;
			}
		}
		return $seen_pass ? 'pass' : 'n/a';
	}

	/* ═══════════════════════════════════════════════════════════════════
	 * WordPress-aware wrappers (the only impure code in this file)
	 * ═══════════════════════════════════════════════════════════════════ */

	/**
	 * The declared zone list, filterable so another component can add one.
	 *
	 * @return array
	 */
	public static function zones() {
		$zones = self::DEFAULT_ZONES;
		$own   = self::primary_zone();
		if ( 'UTC' !== $own ) {
			$zones[] = $own;
		}
		if ( function_exists( 'apply_filters' ) ) {
			$filtered = apply_filters( 'zsch_tzdata_zones', $zones );
			if ( is_array( $filtered ) && ! empty( $filtered ) ) {
				$zones = $filtered;
			}
		}
		return self::normalise_zone_list( $zones );
	}

	/**
	 * The zone this install books in: the scheduler's default time zone, which
	 * resolves the admin override, then the Business Profile, then WordPress.
	 * UTC when none of those can be read.
	 *
	 * @return string
	 */
	public static function primary_zone() {
		$zone = '';
		if ( class_exists( 'ZSCH_Settings' ) && method_exists( 'ZSCH_Settings', 'default_tz' ) ) {
			$zone = (string) ZSCH_Settings::default_tz();
		}
		try {
			new DateTimeZone( $zone );
		} catch ( Exception $e ) {
			$zone = '';
		}
		return '' !== $zone ? $zone : 'UTC';
	}

	/**
	 * Zones appearing on appointments that are NOT in the declared list.
	 *
	 * @return array
	 */
	public static function undeclared_zones() {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return array();
		}
		$table = $wpdb->prefix . 'zsch_appointments';
		/*
		 * it, so a rename fails the test instead of the server. */
		$rows  = $wpdb->get_col( "SELECT DISTINCT time_zone FROM {$table} WHERE time_zone IS NOT NULL AND time_zone <> ''" ); // phpcs:ignore
		if ( ! is_array( $rows ) ) {
			return array();
		}
		return array_values( array_diff( self::normalise_zone_list( $rows ), self::zones() ) );
	}
}
