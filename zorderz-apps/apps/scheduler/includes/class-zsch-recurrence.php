<?php
/**
 * ZSCH_Recurrence — expand a recurrence rule into instances.
 *
 * PURE. No WordPress, no database, no clock. Everything it needs is passed in.
 *
 * ── THE ONE ARCHITECTURAL RULE ────────────────────────────────────────────────
 *
 * **Iterate CALENDAR DATES. Never iterate instants.**
 *
 * A recurring 09:00 job is at 09:00 all year. Its UTC instant is 17:00Z in winter
 * and 16:00Z in summer, and it is the UTC instant that moves, not the local time.
 * An engine that expands by adding 86400 seconds to a UTC instant gets this
 * exactly backwards and shifts every appointment by an hour for half the year —
 * on screen it looks fine, because the UI converts back.
 *
 * So this engine generates a list of DATES (Y-m-d), pairs each with DTSTART's
 * wall-clock TIME, and only then constructs a zoned DateTime. All date arithmetic
 * happens in UTC where no transition exists; the single conversion at the end is
 * where the zone is applied.
 *
 * The single conversion goes through ZSCH_Tzdata::resolve(), which gives the
 * two DST edge cases the meaning RFC 5545 gives them in every zone: a time that
 * occurs twice is its FIRST occurrence, and a time that does not exist is read
 * with the offset in force before the gap.
 *
 * ── WHAT A MULTI-DAY JOB IS NOT ───────────────────────────────────────────────
 *
 * A four-day job is ONE job and up to four linked rows sharing a `job_id`. It
 * is NOT an event that recurs four times. Recurrence instances are generated from
 * a rule and share one identity, so moving "day three" means writing an override
 * against the series; linked rows are independent and can be moved, crewed and
 * completed separately, which is what actually happens on a job.
 *
 * `guard_multiday()` refuses the combination rather than trusting the convention
 * to hold. See its own note for the cost of that choice, which is real.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZSCH_Recurrence {

	/** Hard ceiling on returned instances, whatever the rule asks for. */
	const MAX_INSTANCES = 750;

	/** Candidate periods examined before giving up. */
	const MAX_PERIODS = 20000;

	/**
	 * How far the generator may SCAN when the caller only wants a far window.
	 */
	const MAX_SCAN = 20000;

	/** Default horizon for a rule with no COUNT and no UNTIL. */
	const DEFAULT_HORIZON_YEARS = 5;

	/**
	 * How many times expand_all() may double its horizon guess.
	 *
	 * Six, because the guess is derived from the rule's own cadence and is wrong
	 * only when the rule SKIPS most of its periods — BYMONTHDAY=31 selects seven
	 * months in twelve, 29 February selects one year in four. Six doublings is
	 * 64x the cadence estimate, past any real-world sparsity, and each attempt is
	 * still bounded by MAX_PERIODS, so a pathological rule costs arithmetic and
	 * not time.
	 */
	const MAX_HORIZON_DOUBLINGS = 6;

	/**
	 * Expand a rule.
	 *
	 * @param string $dtstart_local 'Y-m-d H:i:s' wall clock in $tz.
	 * @param string $tz            IANA zone id.
	 * @param string $rule          RRULE string.
	 * @param array  $opts {
	 *     @type string[] $exdate_local  local wall-clock values to remove.
	 *     @type string[] $rdate_local   local wall-clock values to add.
	 *     @type array    $overrides     recurrence_id_local => new_start_local.
	 *     @type string   $horizon_local hard stop; defaults to +5 years.
	 *     @type int      $limit         cap on returned instances.
	 * }
	 * @return array {ok:bool, instances?:array, truncated?:bool, code?:string, error?:string}
	 */
	public static function expand( $dtstart_local, $tz, $rule, array $opts = array() ) {
		$parsed = ZSCH_RRule::parse( $rule, true );
		if ( empty( $parsed['ok'] ) ) {
			return $parsed;   // the parser already said why
		}
		try {
			$zone = new DateTimeZone( (string) $tz );
		} catch ( Exception $e ) {
			return self::err( 'bad_timezone', sprintf( '"%s" is not a time zone this server knows.', $tz ) );
		}
		if ( ! is_string( $dtstart_local ) || ! preg_match( '/^(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2}):(\d{2})$/', $dtstart_local, $m ) ) {
			return self::err( 'bad_dtstart', 'DTSTART must be "Y-m-d H:i:s" wall-clock local time.' );
		}
		if ( ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
			return self::err( 'bad_dtstart', sprintf( 'DTSTART "%s" is not a real date.', $dtstart_local ) );
		}

		$start_date = sprintf( '%s-%s-%s', $m[1], $m[2], $m[3] );
		$wall_time  = sprintf( '%s:%s:%s', $m[4], $m[5], $m[6] );
		$limit      = isset( $opts['limit'] ) ? max( 1, min( self::MAX_INSTANCES, (int) $opts['limit'] ) ) : self::MAX_INSTANCES;

		/* WHERE THE CALLER'S INTEREST BEGINS.
		 * A rule is anchored at DTSTART and cannot be re-anchored without changing
		 * which dates it selects, so a far window still has to be REACHED by
		 * generating forward. What it must not do is spend the caller's instance
		 * budget getting there. Everything before `from_local` is counted (COUNT
		 * is an ordinal and must stay one) and then dropped, and only the dates
		 * from there on are built into zoned instants.
		 *
		 * One day of slack, because an instance sitting within a zone offset of
		 * the boundary belongs to the window even when its local DATE does not. */
		$from_local = isset( $opts['from_local'] ) ? (string) $opts['from_local'] : '';
		$scan_from  = '';
		if ( '' !== $from_local && preg_match( '/^\d{4}-\d{2}-\d{2}/', $from_local ) ) {
			$scan_from = self::add_days( substr( $from_local, 0, 10 ), -1 );
		}
		$gen_limit = ( '' !== $scan_from ) ? self::MAX_SCAN : $limit;

		/* A rule with neither COUNT nor UNTIL is infinite. Give it a horizon so an
		 * accident cannot walk to the heat death of the universe, and REPORT the
		 * truncation rather than quietly returning a short list. */
		$horizon = isset( $opts['horizon_local'] ) && '' !== $opts['horizon_local']
			? (string) $opts['horizon_local']
			: self::add_years( $start_date, self::DEFAULT_HORIZON_YEARS ) . ' 23:59:59';

		$dates = self::dates( $parsed, $start_date, $horizon, $gen_limit );
		if ( isset( $dates['ok'] ) && false === $dates['ok'] ) {
			return $dates;
		}
		$truncated = $dates['truncated'];
		$dates     = $dates['dates'];

		// ── dates + wall time -> zoned instants ─────────────────────────
		$utc       = new DateTimeZone( 'UTC' );
		$until_utc = (string) $parsed['until_utc'];
		$instances = array();
		$count_cap = (int) $parsed['count'];

		$ordinal = 0;   // position in the series, which is what COUNT counts
		foreach ( $dates as $d ) {
			$ordinal++;

			/* Before the caller's window: counted, never built. Skipping the
			 * DateTime construction is what keeps an eight-year-old daily series
			 * cheap, and it is safe because a date before the scan floor is
			 * necessarily before UNTIL too — the floor already carries a day of
			 * slack in the other direction. */
			if ( '' !== $scan_from && strcmp( $d, $scan_from ) < 0 ) {
				if ( $count_cap > 0 && $ordinal >= $count_cap ) { $truncated = false; break; }
				continue;
			}

			$local = $d . ' ' . $wall_time;
			/* THE conversion: a nonexistent local time takes the pre-gap offset
			 * and an ambiguous one the first occurrence, as the spec (as
			 * corrected) requires — through ZSCH_Tzdata::resolve(), because PHP's
			 * own parser does not do this consistently in every zone. */
			$dt = self::to_instant( $local, $zone );
			if ( null === $dt ) {
				continue;
			}
			$inst_utc = ( clone $dt )->setTimezone( $utc )->format( 'Y-m-d H:i:s' );

			/* UNTIL is a UTC instant and is INCLUSIVE. */
			if ( '' !== $until_utc && strcmp( $inst_utc, $until_utc ) > 0 ) {
				$truncated = false;   // we reached the declared end; that is not truncation
				break;
			}

			$instances[] = array(
				'local'         => $dt->format( 'Y-m-d H:i:s' ),
				'utc'           => $inst_utc,
				'recurrence_id' => $dt->format( 'Y-m-d H:i:s' ),
				'overridden'    => false,
			);

			if ( $count_cap > 0 && $ordinal >= $count_cap ) {
				$truncated = false;   // COUNT satisfied — an ORDINAL, not a tally
				break;
			}
			if ( count( $instances ) >= $limit ) {
				$truncated = true;
				break;
			}
		}

		// ── RDATE: extra instants the rule does not generate ────────────
		if ( ! empty( $opts['rdate_local'] ) ) {
			foreach ( (array) $opts['rdate_local'] as $r ) {
				$r  = (string) $r;
				$dt = self::to_instant( $r, $zone );
				if ( null === $dt ) {
					continue;
				}
				$local = $dt->format( 'Y-m-d H:i:s' );
				$dup   = false;
				foreach ( $instances as $i ) {
					if ( $i['recurrence_id'] === $local ) { $dup = true; break; }
				}
				if ( ! $dup ) {
					$instances[] = array(
						'local'         => $local,
						'utc'           => ( clone $dt )->setTimezone( $utc )->format( 'Y-m-d H:i:s' ),
						'recurrence_id' => $local,
						'overridden'    => false,
					);
				}
			}
		}

		// ── EXDATE: removal, and the match must be EXACT ────────────────
		/*
		 * the match must be exact — an EXDATE written in UTC does not cancel an
		 * instance generated as local-with-TZID, and the cancellation silently does
		 * nothing. So this compares LOCAL WALL CLOCK against LOCAL WALL CLOCK and
		 * nothing else. A caller holding a UTC exdate must convert it first, on
		 * purpose, where the conversion is visible. */
		if ( ! empty( $opts['exdate_local'] ) ) {
			$ex = array();
			foreach ( (array) $opts['exdate_local'] as $e ) {
				$ex[ trim( (string) $e ) ] = true;
			}
			$instances = array_values( array_filter(
				$instances,
				static function ( $i ) use ( $ex ) {
					return ! isset( $ex[ $i['recurrence_id'] ] );
				}
			) );
		}

		// ── overrides: RECURRENCE-ID is the ORIGINAL start ──────────────
		/*
		 * is where the instance ORIGINALLY was, never where it was moved to.
		 * Re-keying it to the new start after a drag detaches the override, leaving
		 * a ghost at the old slot AND a duplicate at the new one. So the map is
		 * looked up by `recurrence_id`, and `recurrence_id` is never rewritten. */
		if ( ! empty( $opts['overrides'] ) ) {
			foreach ( $instances as $idx => $i ) {
				$key = $i['recurrence_id'];
				if ( ! isset( $opts['overrides'][ $key ] ) ) {
					continue;
				}
				$new = (string) $opts['overrides'][ $key ];
				if ( '' === $new ) {
					continue;
				}
				$dt = self::to_instant( $new, $zone );
				if ( null === $dt ) {
					continue;
				}
				$instances[ $idx ]['local']      = $dt->format( 'Y-m-d H:i:s' );
				$instances[ $idx ]['utc']        = ( clone $dt )->setTimezone( $utc )->format( 'Y-m-d H:i:s' );
				$instances[ $idx ]['overridden'] = true;
				// recurrence_id deliberately untouched.
			}
		}

		/* Sort by the EFFECTIVE instant, because that is the order a calendar shows
		 * and a moved instance really does belong at its new position. */
		usort( $instances, static function ( $a, $b ) {
			return strcmp( $a['utc'], $b['utc'] );
		} );

		return array( 'ok' => true, 'instances' => $instances, 'truncated' => (bool) $truncated );
	}

	/**
	 * Convenience: just the local wall-clock strings.
	 *
	 * @param string $dtstart_local
	 * @param string $tz
	 * @param string $rule
	 * @param array  $opts
	 * @return array {ok:bool, local?:string[], ...}
	 */
	/**
	 * Expand a BOUNDED rule to its real end, whatever horizon that takes.
	 *
	 * ── WHY THIS EXISTS ───────────────────────────────────────────────────────
	 *
	 * A horizon is the wrong instrument for a question with an exact answer.
	 * COUNT and UNTIL both DECLARE an end; this reaches it.
	 *
	 * ── HOW ───────────────────────────────────────────────────────────────────
	 *
	 * UNTIL is a date we already know, so its horizon is set from it and one pass
	 * is enough. COUNT is an ordinal, and how long N occurrences take depends on
	 * how many of the rule's own periods actually select a date — BYMONTHDAY=31
	 * selects seven months in twelve, 29 February one year in four. So the
	 * horizon is GUESSED from the rule's cadence and DOUBLED until the expansion
	 * reports it reached the end. Guessing high is free: `dates()` stops at
	 * `limit` regardless of how far the horizon sits.
	 *
	 * ── THE CONTRACT, AND WHY `complete` IS THE POINT ─────────────────────────
	 *
	 * @param string $dtstart_local 'Y-m-d H:i:s' wall clock in $tz.
	 * @param string $tz            IANA zone id.
	 * @param string $rule          RRULE string.
	 * @return array {ok:bool, instances:array, truncated:bool, complete:bool, unbounded:bool}
	 */
	public static function expand_all( $dtstart_local, $tz, $rule ) {
		$p = ZSCH_RRule::parse( $rule, true );
		if ( empty( $p['ok'] ) ) {
			return $p;   // the parser already said why
		}

		$count = (int) $p['count'];
		$until = (string) $p['until_utc'];
		$none  = array( 'ok' => true, 'instances' => array(), 'truncated' => true, 'complete' => false, 'unbounded' => false );

		/* No COUNT and no UNTIL: there is no end to reach, and saying so is the
		 * answer. A caller that wants instances from an unbounded rule has to
		 * name its own window, because only it knows which one it means. */
		if ( 0 === $count && '' === $until ) {
			$none['unbounded'] = true;
			return $none;
		}

		/* UNTIL: the end is DECLARED, so the horizon is read off it rather than
		 * guessed. A year of slack because UNTIL is a UTC instant and the horizon
		 * is a local date, and no zone offset is anywhere near a year. */
		if ( '' !== $until ) {
			$h = (int) substr( $until, 0, 4 ) + 1;
			$r = self::expand( $dtstart_local, $tz, $rule, array(
				'limit'         => self::MAX_INSTANCES,
				'horizon_local' => $h . substr( $until, 4, 6 ) . ' 23:59:59',
			) );
			if ( empty( $r['ok'] ) ) { return $r; }
			$r['complete']  = empty( $r['truncated'] );
			$r['unbounded'] = false;
			return $r;
		}

		/* COUNT past what one expansion may RETURN. There is no number to give,
		 * so give none — see the callers, which all treat that as "unbounded"
		 * rather than inventing a shorter answer. */
		if ( $count > self::MAX_INSTANCES ) {
			return $none;
		}

		$start_date = substr( (string) $dtstart_local, 0, 10 );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $start_date ) ) {
			return self::err( 'bad_dtstart', 'DTSTART must be "Y-m-d H:i:s" wall-clock local time.' );
		}
		$years = self::years_for( $p, $count );

		$r = $none;
		for ( $try = 0; $try < self::MAX_HORIZON_DOUBLINGS; $try++ ) {
			$horizon = ( (int) substr( $start_date, 0, 4 ) + $years ) . substr( $start_date, 4 ) . ' 23:59:59';
			$r       = self::expand( $dtstart_local, $tz, $rule, array(
				'limit'         => $count,
				'horizon_local' => $horizon,
			) );
			if ( empty( $r['ok'] ) ) { return $r; }

			/* BOTH conditions. `truncated` false alone would also be true of a
			 * rule that ran out of dates early, and a short list read as a
			  */
			if ( empty( $r['truncated'] ) && count( $r['instances'] ) >= $count ) {
				$r['complete']  = true;
				$r['unbounded'] = false;
				return $r;
			}
			$years *= 2;
		}

		$r['complete']  = false;
		$r['unbounded'] = false;
		return $r;
	}

	/**
	 * A first guess at how many YEARS $count occurrences of $p might span.
	 *
	 * Deliberately crude and deliberately HIGH. Guessing high costs nothing —
	 * `dates()` stops once it has `limit` dates however far away the horizon is —
	 * while guessing low costs a doubling. The estimate ignores BYDAY/BYMONTHDAY
	 * entirely: those can only make a period yield MORE dates (shortening the
	 * span, which is safe) or FEWER (which the doubling in expand_all() is for).
	 */
	private static function years_for( array $p, $count ) {
		$n = max( 1, (int) $count ) * max( 1, (int) $p['interval'] );
		switch ( $p['freq'] ) {
			case 'DAILY':   $y = (int) ceil( $n / 365 ); break;
			case 'WEEKLY':  $y = (int) ceil( $n / 52 );  break;
			case 'MONTHLY': $y = (int) ceil( $n / 12 );  break;
			default:        $y = $n;                     break;   // YEARLY
		}
		return max( 1, $y ) + 1;
	}

	public static function expand_local( $dtstart_local, $tz, $rule, array $opts = array() ) {
		$r = self::expand( $dtstart_local, $tz, $rule, $opts );
		if ( empty( $r['ok'] ) ) {
			return $r;
		}
		$out = array();
		foreach ( $r['instances'] as $i ) {
			$out[] = $i['local'];
		}
		return array( 'ok' => true, 'local' => $out, 'truncated' => $r['truncated'] );
	}

	/* ══ DATE GENERATION — pure calendar arithmetic, no zones ═══════════ */

	/**
	 * Generate candidate dates for a parsed rule.
	 *
	 * @param array  $p
	 * @param string $start_date  'Y-m-d'
	 * @param string $horizon     'Y-m-d H:i:s'
	 * @param int    $limit
	 * @return array {dates:string[], truncated:bool} or an error array.
	 */
	private static function dates( array $p, $start_date, $horizon, $limit ) {
		$horizon_date = substr( (string) $horizon, 0, 10 );
		$out          = array();
		$seen         = array();
		$periods      = 0;
		$truncated    = false;

		/* DTSTART always counts as the first occurrence. Emitted first
		 * and unconditionally — the off-by-one where COUNT=3 yields DTSTART plus
		 * three more is endemic and produces one extra appointment per series
		 * forever. */
		$out[ $start_date ] = true;
		$seen[ $start_date ] = true;

		$interval = max( 1, (int) $p['interval'] );

		switch ( $p['freq'] ) {

			case 'DAILY':
				$d = $start_date;
				while ( count( $out ) < $limit && $periods++ < self::MAX_PERIODS ) {
					$d = self::add_days( $d, $interval );
					if ( strcmp( $d, $horizon_date ) > 0 ) { $truncated = true; break; }
					if ( self::date_matches( $p, $d ) && ! isset( $seen[ $d ] ) ) {
						$out[ $d ] = true; $seen[ $d ] = true;
					}
				}
				break;

			case 'WEEKLY':
				$wkst_idx  = (int) array_search( $p['wkst'], ZSCH_RRule::DAYS, true );
				$start_dow = (int) self::dow( $start_date );
				$diff      = ( $start_dow - $wkst_idx + 7 ) % 7;
				$week      = self::add_days( $start_date, -$diff );   // start of DTSTART's week

				/* With no BYDAY the rule repeats on DTSTART's own weekday. */
				$days = empty( $p['byday'] )
					? array( ZSCH_RRule::DAYS[ $start_dow ] )
					: array_column( $p['byday'], 'day' );

				while ( count( $out ) < $limit && $periods++ < self::MAX_PERIODS ) {
					foreach ( $days as $dayTok ) {
						$idx = (int) array_search( $dayTok, ZSCH_RRule::DAYS, true );
						$off = ( $idx - $wkst_idx + 7 ) % 7;
						$d   = self::add_days( $week, $off );
						if ( strcmp( $d, $start_date ) < 0 ) { continue; }   // before DTSTART
						if ( strcmp( $d, $horizon_date ) > 0 ) { $truncated = true; break 2; }
						if ( isset( $seen[ $d ] ) ) { continue; }
						if ( ! self::date_matches( $p, $d ) ) { continue; }
						$out[ $d ] = true; $seen[ $d ] = true;
						if ( count( $out ) >= $limit ) { break; }
					}
					$week = self::add_days( $week, 7 * $interval );
					if ( strcmp( $week, $horizon_date ) > 0 ) { $truncated = true; break; }
				}
				break;

			case 'MONTHLY':
			case 'YEARLY':
				$y = (int) substr( $start_date, 0, 4 );
				$mo = (int) substr( $start_date, 5, 2 );
				$dd = (int) substr( $start_date, 8, 2 );

				$step_months = ( 'MONTHLY' === $p['freq'] ) ? $interval : 12 * $interval;
				$cur_y = $y; $cur_m = $mo;

				while ( count( $out ) < $limit && $periods++ < self::MAX_PERIODS ) {
					// advance one period
					$total = ( $cur_y * 12 + ( $cur_m - 1 ) ) + $step_months;
					$cur_y = intdiv( $total, 12 );
					$cur_m = ( $total % 12 ) + 1;

					if ( $cur_y > (int) substr( $horizon_date, 0, 4 ) + 1 ) { $truncated = true; break; }

					/* YEARLY honours BYMONTH; MONTHLY's month is the period itself. */
					$months = ( 'YEARLY' === $p['freq'] && ! empty( $p['bymonth'] ) )
						? $p['bymonth']
						: array( $cur_m );
					if ( 'YEARLY' === $p['freq'] && empty( $p['bymonth'] ) ) {
						$months = array( $mo );   // same month as DTSTART
					}

					foreach ( $months as $mm ) {
						foreach ( self::days_in_period( $p, $cur_y, (int) $mm, $dd ) as $day ) {
							if ( ! checkdate( (int) $mm, $day, $cur_y ) ) {
								continue;
							}
							$d = sprintf( '%04d-%02d-%02d', $cur_y, $mm, $day );
							if ( strcmp( $d, $start_date ) < 0 ) { continue; }
							if ( strcmp( $d, $horizon_date ) > 0 ) { $truncated = true; break 3; }
							if ( isset( $seen[ $d ] ) ) { continue; }
							$out[ $d ] = true; $seen[ $d ] = true;
							if ( count( $out ) >= $limit ) { break 2; }
						}
					}
				}
				break;
		}

		$list = array_keys( $out );
		sort( $list );
		return array( 'dates' => $list, 'truncated' => $truncated );
	}

	/**
	 * Which days of $year-$month does the rule select?
	 *
	 * @param array $p
	 * @param int   $year
	 * @param int   $month
	 * @param int   $fallback_day  DTSTART's day-of-month, used when nothing selects.
	 * @return int[] day numbers, possibly invalid for the month (caller skips those).
	 */
	private static function days_in_period( array $p, $year, $month, $fallback_day ) {
		$dim = (int) gmdate( 't', gmmktime( 0, 0, 0, $month, 1, $year ) );

		if ( ! empty( $p['byday'] ) ) {
			$days = array();
			foreach ( $p['byday'] as $bd ) {
				$idx = (int) array_search( $bd['day'], ZSCH_RRule::DAYS, true );
				if ( null === $bd['ord'] ) {
					// every such weekday in the month
					for ( $d = 1; $d <= $dim; $d++ ) {
						if ( (int) gmdate( 'w', gmmktime( 0, 0, 0, $month, $d, $year ) ) === $idx ) {
							$days[] = $d;
						}
					}
				} else {
					$d = self::nth_weekday( $year, $month, (int) $bd['ord'], $idx );
					if ( $d > 0 ) { $days[] = $d; }
				}
			}
			/* BYMONTHDAY alongside BYDAY is an intersection. */
			if ( ! empty( $p['bymonthday'] ) ) {
				$md = self::resolve_monthdays( $p['bymonthday'], $dim );
				$days = array_values( array_intersect( $days, $md ) );
			}
			$days = array_unique( $days );
			sort( $days );
			return $days;
		}

		if ( ! empty( $p['bymonthday'] ) ) {
			$days = self::resolve_monthdays( $p['bymonthday'], $dim );
			sort( $days );
			return $days;
		}

		return array( (int) $fallback_day );
	}

	/**
	 * Turn BYMONTHDAY values into day numbers. Negative counts back from the end,
	 * so -1 is the last day of THIS month — which is what people mean by "the last
	 * of the month", as distinct from BYMONTHDAY=31, which skips the months that
	 * have no 31st.
	 *
	 * @param int[] $list
	 * @param int   $dim days in month
	 * @return int[]
	 */
	private static function resolve_monthdays( array $list, $dim ) {
		$out = array();
		foreach ( $list as $n ) {
			$n = (int) $n;
			$d = ( $n > 0 ) ? $n : ( $dim + $n + 1 );
			if ( $d >= 1 && $d <= $dim ) {
				$out[] = $d;
			}
			/* A positive n beyond the month's length is simply not a date in this
			 * month and is dropped here; the caller's checkdate() would drop it too.
			 * Either way it is SKIPPED, never clamped to the 28th. */
		}
		return array_unique( $out );
	}

	/**
	 * Day-of-month of the nth given weekday, or 0 if the month has no such day.
	 *
	 * @param int $year
	 * @param int $month
	 * @param int $ord   1 = first, -1 = last.
	 * @param int $dow   0=Sunday .. 6=Saturday
	 * @return int
	 */
	public static function nth_weekday( $year, $month, $ord, $dow ) {
		$dim   = (int) gmdate( 't', gmmktime( 0, 0, 0, $month, 1, $year ) );
		$first = (int) gmdate( 'w', gmmktime( 0, 0, 0, $month, 1, $year ) );

		if ( $ord > 0 ) {
			$day = 1 + ( ( $dow - $first + 7 ) % 7 ) + ( $ord - 1 ) * 7;
		} else {
			$last     = (int) gmdate( 'w', gmmktime( 0, 0, 0, $month, $dim, $year ) );
			$day      = $dim - ( ( $last - $dow + 7 ) % 7 ) + ( $ord + 1 ) * 7;
		}
		return ( $day >= 1 && $day <= $dim ) ? $day : 0;
	}

	/**
	 * Does a generated date survive the rule's filtering BY* parts?
	 *
	 * Used by DAILY and WEEKLY, where the BY* parts narrow rather than generate.
	 *
	 * @param array  $p
	 * @param string $date 'Y-m-d'
	 * @return bool
	 */
	private static function date_matches( array $p, $date ) {
		$y = (int) substr( $date, 0, 4 );
		$m = (int) substr( $date, 5, 2 );
		$d = (int) substr( $date, 8, 2 );

		if ( ! empty( $p['bymonth'] ) && ! in_array( $m, array_map( 'intval', $p['bymonth'] ), true ) ) {
			return false;
		}
		if ( ! empty( $p['bymonthday'] ) ) {
			$dim = (int) gmdate( 't', gmmktime( 0, 0, 0, $m, 1, $y ) );
			if ( ! in_array( $d, self::resolve_monthdays( $p['bymonthday'], $dim ), true ) ) {
				return false;
			}
		}
		if ( ! empty( $p['byday'] ) ) {
			$dow  = ZSCH_RRule::DAYS[ (int) self::dow( $date ) ];
			$toks = array_column( $p['byday'], 'day' );
			if ( ! in_array( $dow, $toks, true ) ) {
				return false;
			}
		}
		return true;
	}

	/* ══ THE MULTI-DAY JOB GUARD ═══════════════════════════════════════ */

	/**
	 * Refuse a recurring event that also spans more than a day.
	 *
	 * A four-day install is one job and up to four linked rows sharing a `job_id`;
	 * it is not an event that recurs. Modelling it as recurrence is how you end up
	 * with four appointments that drift apart the first time somebody reschedules
	 * one, because a recurrence instance cannot be moved without writing an
	 * override against the whole series.
	 *
	 * PURE.
	 *
	 * @param string $duration_iso ISO 8601 duration on the event, or ''.
	 * @param string $rule         the RRULE, or ''.
	 * @return array {ok:bool, code?:string, error?:string}
	 */
	public static function guard_multiday( $duration_iso, $rule ) {
		$duration_iso = (string) $duration_iso;
		$rule         = trim( (string) $rule );

		if ( '' === $rule || '' === $duration_iso ) {
			return array( 'ok' => true );   // not both, nothing to judge
		}
		if ( ! class_exists( 'ZSCH_Duration' ) ) {
			return array( 'ok' => true );   // cannot judge; do not invent a verdict
		}
		$parts = ZSCH_Duration::parts( $duration_iso );
		if ( null === $parts ) {
			return array( 'ok' => true );   // unparseable; not this guard's argument to make
		}

		/* An APPROXIMATE magnitude, and deliberately computed here rather than added
		 * to ZSCH_Duration as a `to_seconds()`. That class exists to keep nominal
		 * and exact durations distinct — P3D is not PT72H across a DST change — and
		 * a general seconds accessor on it would be the obvious thing to reach for
		 * next time somebody wants to "just compare two durations", quietly undoing
		 * the distinction. This guard only needs to know whether the span is longer
		 * than a day, a question where the nominal/exact difference cannot change
		 * the answer. */
		$secs = $parts['weeks'] * 604800
			+ $parts['days'] * 86400
			+ $parts['hours'] * 3600
			+ $parts['minutes'] * 60
			+ $parts['seconds'];

		/* Exactly one day is a full-day event, which is ordinary and recurs fine.
		 * More than a day is the shape of a job. */
		if ( $secs <= 86400 ) {
			return array( 'ok' => true );
		}
		return array(
			'ok'    => false,
			'code'  => 'multiday_is_a_job',
			'error' => 'This event both recurs and runs longer than a day. A multi-day job is ONE job made of linked appointments sharing a job_id, not an event that repeats — linked rows can be moved, crewed and completed separately, which is what actually happens on a job, whereas moving one recurrence instance means writing an override against the whole series. Create the days as linked appointments instead.',
		);
	}

	/* ══ "THIS AND FOLLOWING" — a split, not a RANGE ════════════════════ */

	/**
	 * Split a series at an instance: truncate the original, start a new one.
	 *
	 * @param string $dtstart_local
	 * @param string $tz
	 * @param string $rule
	 * @param string $split_at_local  the first instance that belongs to the NEW series.
	 * @param string $new_start_local optional new wall time for the new series.
	 * @return array {ok:bool, original?:array, new?:array, code?:string, error?:string}
	 */
	public static function split( $dtstart_local, $tz, $rule, $split_at_local, $new_start_local = '' ) {
		$split_at_local = (string) $split_at_local;

		$parsed = ZSCH_RRule::parse( $rule, true );
		if ( empty( $parsed['ok'] ) ) {
			return $parsed;
		}
		$bounded = ( (int) $parsed['count'] > 0 || '' !== (string) $parsed['until_utc'] );

		/* REACH THE SPLIT POINT.
		 * A bounded rule is expanded to its real end, because `carried` is only
		 * an honest number when the whole remainder has been seen. An unbounded
		 * rule has no end to reach, so its horizon comes from the SPLIT POINT,
		 * which is the one date this function is always given. */
		if ( $bounded ) {
			$all = self::expand_all( $dtstart_local, $tz, $rule );
			if ( empty( $all['ok'] ) ) {
				return $all;
			}
			if ( empty( $all['complete'] ) ) {
				/* Refusing is the honest answer and the safe one. The alternative
				 * — partitioning a list known to be short — is how a split wrote
				 * COUNT=37 onto the remainder of a 120-visit contract and deleted
				 * 59 visits with no error anywhere. */
				return self::err( 'split_series_too_long',
					'This series is longer than the scheduler can work through in one pass, so it cannot be split safely. Shorten it first, or split it into a separate appointment by hand.' );
			}
		} else {
			$through = preg_match( '/^(\d{4})-\d{2}-\d{2}/', $split_at_local, $m )
				? ( (int) $m[1] + 1 ) . substr( $split_at_local, 4, 6 ) . ' 23:59:59'
				: '';
			$all = self::expand( $dtstart_local, $tz, $rule, array(
				'limit'         => self::MAX_INSTANCES,
				'horizon_local' => $through,
			) );
			if ( empty( $all['ok'] ) ) {
				return $all;
			}
		}

		$before = array();
		$after  = array();
		foreach ( $all['instances'] as $i ) {
			if ( strcmp( $i['recurrence_id'], $split_at_local ) < 0 ) {
				$before[] = $i;
			} else {
				$after[] = $i;
			}
		}
		if ( empty( $after ) ) {
			return self::err( 'split_after_end', 'The split point is at or after the end of the series; there is nothing to split off.' );
		}
		if ( empty( $before ) ) {
			return self::err( 'split_at_start', 'The split point is the first instance, so there is no original series left. Edit the series instead of splitting it.' );
		}

		/* The original ends at its last kept instance. UNTIL is INCLUSIVE and must
		 * be UTC, so this is that instance's own UTC instant — which is also why it
		 * is taken from the expansion rather than computed by subtracting a day from
		 * the split point, an arithmetic that breaks across a DST transition. */
		$last_kept          = $before[ count( $before ) - 1 ];
		$trunc              = $parsed;
		$trunc['count']     = 0;
		$trunc['until_utc'] = $last_kept['utc'];

		/* The new series inherits what remains. An UNTIL carries across unchanged.
		 * A COUNT IS PARTITIONED, NOT RE-TALLIED.
		 *
		 * COUNT is an ORDINAL over the whole series, so the remainder
		 * is arithmetic: what was declared, less what the original keeps. That
		 * holds whatever the expansion reached, which is the point — the number
		 * no longer depends on how far anything looked. `$before` is complete
		 * whenever `$after` is non-empty, because truncation only ever cuts the
		 * tail and everything in `$before` precedes the split point. */
		$new              = $parsed;
		$new['until_utc'] = (int) $parsed['count'] > 0 ? '' : $parsed['until_utc'];
		$new['count']     = (int) $parsed['count'] > 0
			? max( 1, (int) $parsed['count'] - count( $before ) )
			: 0;

		$new_start = ( '' !== (string) $new_start_local ) ? (string) $new_start_local : $after[0]['local'];

		return array(
			'ok'       => true,
			'original' => array(
				'rule'          => ZSCH_RRule::to_string( $trunc ),
				'dtstart_local' => $dtstart_local,
				'kept'          => count( $before ),
			),
			'new'      => array(
				'rule'          => ZSCH_RRule::to_string( $new ),
				'dtstart_local' => $new_start,
				/* What the customer is actually owed. For a COUNT rule that is
				 * the partition above; for an UNTIL rule the expansion reached
				 * the declared end, so the tally is the truth; for an unbounded
				 * rule there is no number and NULL says so rather than reporting
				 * however far the horizon happened to look. */
				'carried'       => (int) $parsed['count'] > 0
					? (int) $new['count']
					: ( '' !== (string) $parsed['until_utc'] ? count( $after ) : null ),
				/* A NEW UID is not optional. Without it the two halves are the same
				 * event to every downstream system and the split is invisible; with
				 * it, RELATED-TO is what keeps them associated. */
				'needs_new_uid' => true,
				'related_to'    => 'original',
			),
		);
	}

	/* ══ small date helpers — UTC only, so no transition can reach them ═ */

	/** @return string 'Y-m-d' */
	/**
	 * A wall-clock time in $zone as an instant (ZSCH_Tzdata::resolve()), or null.
	 *
	 * @param string       $local
	 * @param DateTimeZone $zone
	 * @return DateTime|null
	 */
	private static function to_instant( $local, DateTimeZone $zone ) {
		if ( class_exists( 'ZSCH_Tzdata' ) ) {
			return ZSCH_Tzdata::resolve( $local, $zone );
		}
		try {
			return new DateTime( (string) $local, $zone );
		} catch ( Exception $e ) {
			return null;
		}
	}

	private static function add_days( $date, $n ) {
		$t = strtotime( $date . ' 00:00:00 UTC' ) + ( (int) $n * 86400 );
		return gmdate( 'Y-m-d', $t );
	}

	/** @return string 'Y-m-d' */
	private static function add_years( $date, $n ) {
		$y = (int) substr( $date, 0, 4 ) + (int) $n;
		return $y . substr( $date, 4 );
	}

	/** @return int 0=Sunday .. 6=Saturday */
	private static function dow( $date ) {
		return (int) gmdate( 'w', strtotime( $date . ' 00:00:00 UTC' ) );
	}

	/** @return array */
	private static function err( $code, $message ) {
		return array( 'ok' => false, 'code' => $code, 'error' => $message );
	}
}
