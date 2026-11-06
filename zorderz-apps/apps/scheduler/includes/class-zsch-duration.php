<?php
/**
 * ZSCH_Duration — ISO 8601 durations, and the one place an end instant is derived.
 *
 * ── WHY A DURATION AND NOT AN END INSTANT ─────────────────────────────────────
 *
 * Storing a start instant AND an end instant cannot express the difference between
 * "three days" and "seventy-two hours". Across a DST transition those are not the
 * same span, and which one you meant is exactly the information an end instant
 * throws away. In a zone that springs forward on a Sunday:
 *
 *     Fri 08:00 (winter time) + P3D   (nominal) →  Mon 08:00 (summer time)  ✓
 *     Fri 08:00 (winter time) + PT72H (exact)   →  Mon 09:00 (summer time)  ✗
 *
 * ── THE RULE THAT MAKES IT CORRECT ────────────────────────────────────────────
 *
 * Arithmetic happens in the event's own zone, never in UTC:
 *
 *     local Sat 01:30 + P1D, added in LOCAL  →  Sun 01:30  ✓
 *     local Sat 01:30 + P1D, added in UTC    →  Sun 02:30  ✗
 *
 * An exact duration gives the same answer either way, so there is no branch and
 * no decision to get wrong at the call site: one path, correct for both kinds.
 *
 * ── WHY end_utc SURVIVES ──────────────────────────────────────────────────────
 *
 * `duration` is the source of truth; `end_utc` is DERIVED and kept, because it is
 * the indexed column every range query reads and because every existing reader
 * (the Graph push, the Connected Calendars overlay, the assistant) already uses it.
 *
 * KEEPING BOTH IS ONLY SAFE WITH ONE DISCIPLINE: `end_utc` is never authored on
 * its own — it is only ever an instant the duration reproduces. The moment
 * something writes an end instant independently, the two columns can disagree,
 * and a pair of columns that can disagree eventually will.
 *
 * @since 1.10.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZSCH_Duration {

	/** A year. Sanity bound — nothing in this app legitimately spans longer. */
	const MAX_SECONDS = 31536000;

	/**
	 * ISO 8601 duration grammar, restricted to the parts a calendar uses.
	 *
	 * Deliberately excludes years and months. They are nominal in a way that is
	 * genuinely ambiguous (how long is a month from 31 January?), nothing in this
	 * app needs them, and accepting input you cannot resolve unambiguously is how
	 * you end up with an appointment on a date nobody chose. Weeks and days are
	 * nominal but unambiguous; hours, minutes and seconds are exact.
	 *
	 * Note `M` means MONTHS before the `T` and MINUTES after it. That is a real
	 * source of bugs, which is the other reason months are refused outright.
	 */
	const PATTERN = '/^P(?!$)(?:(\d+)W)?(?:(\d+)D)?(?:T(?!$)(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?)?$/';

	/**
	 * Is this a well-formed duration we accept?
	 *
	 * @param mixed $iso
	 * @return bool
	 */
	public static function is_valid( $iso ) {
		if ( ! is_string( $iso ) || '' === $iso ) {
			return false;
		}
		return 1 === preg_match( self::PATTERN, $iso );
	}

	/**
	 * Does this duration carry a NOMINAL component (weeks or days)?
	 *
	 * Nominal means "the same wall-clock time, N days later" — which may be 23,
	 * 24 or 25 hours of elapsed time depending on what the zone did in between.
	 * Exact means elapsed time. See the class header for why the difference is
	 * load-bearing rather than pedantic.
	 *
	 * @param string $iso
	 * @return bool
	 */
	public static function is_nominal( $iso ) {
		if ( ! self::is_valid( $iso ) ) {
			return false;
		}
		preg_match( self::PATTERN, $iso, $m );
		return ( '' !== ( $m[1] ?? '' ) ) || ( '' !== ( $m[2] ?? '' ) );
	}

	/**
	 * Break a duration into its parts.
	 *
	 * @param string $iso
	 * @return array|null { weeks, days, hours, minutes, seconds } or null if invalid.
	 */
	public static function parts( $iso ) {
		if ( ! self::is_valid( $iso ) ) {
			return null;
		}
		preg_match( self::PATTERN, $iso, $m );
		return array(
			'weeks'   => (int) ( $m[1] ?? 0 ),
			'days'    => (int) ( $m[2] ?? 0 ),
			'hours'   => (int) ( $m[3] ?? 0 ),
			'minutes' => (int) ( $m[4] ?? 0 ),
			'seconds' => (int) ( $m[5] ?? 0 ),
		);
	}

	/**
	 * An EXACT duration of N seconds, in the most compact legible form.
	 *
	 * Always time-only (`PT…`), never days, because seconds are an exact quantity
	 * and rendering 86400 seconds as `P1D` would silently convert it into a
	 * nominal one. That conversion is the bug this class exists to prevent, so it
	 * is not available even by accident.
	 *
	 * @param int $seconds
	 * @return string '' when out of range or not positive.
	 */
	public static function from_seconds( $seconds ) {
		$seconds = (int) $seconds;
		if ( $seconds <= 0 || $seconds > self::MAX_SECONDS ) {
			return '';
		}
		$h = intdiv( $seconds, 3600 );
		$m = intdiv( $seconds % 3600, 60 );
		$s = $seconds % 60;

		$out = 'PT';
		if ( $h ) { $out .= $h . 'H'; }
		if ( $m ) { $out .= $m . 'M'; }
		if ( $s ) { $out .= $s . 'S'; }
		return ( 'PT' === $out ) ? '' : $out;
	}

	/**
	 * A NOMINAL duration of N days — the multi-day-job form.
	 *
	 * @param int $days
	 * @return string '' when out of range or not positive.
	 */
	public static function from_days( $days ) {
		$days = (int) $days;
		if ( $days <= 0 || ( $days * 86400 ) > self::MAX_SECONDS ) {
			return '';
		}
		return 'P' . $days . 'D';
	}

	/**
	 * Re-emit a duration in canonical form, or '' if it is not one we accept.
	 *
	 * Canonicalising on the way in means two spellings of the same span compare
	 * equal downstream, so a content hash does not churn and a change log does not
	 * record `PT60M → PT1H` as if somebody had rescheduled something.
	 *
	 * Weeks normalise to days; days stay days (nominal is preserved, never
	 * flattened into hours).
	 *
	 * @param string $iso
	 * @return string
	 */
	public static function normalize( $iso ) {
		$p = self::parts( $iso );
		if ( null === $p ) {
			return '';
		}
		$days = $p['weeks'] * 7 + $p['days'];
		$secs = $p['hours'] * 3600 + $p['minutes'] * 60 + $p['seconds'];

		if ( $days <= 0 && $secs <= 0 ) {
			return '';
		}
		if ( ( $days * 86400 + $secs ) > self::MAX_SECONDS ) {
			return '';
		}

		$out = 'P';
		if ( $days ) {
			$out .= $days . 'D';
		}
		if ( $secs ) {
			$h = intdiv( $secs, 3600 );
			$m = intdiv( $secs % 3600, 60 );
			$s = $secs % 60;
			$out .= 'T';
			if ( $h ) { $out .= $h . 'H'; }
			if ( $m ) { $out .= $m . 'M'; }
			if ( $s ) { $out .= $s . 'S'; }
		}
		return $out;
	}

	/**
	 * THE DERIVATION. Given a start instant, a duration and a zone, the end instant.
	 *
	 * This is the only function permitted to produce an `end_utc`. Everything else
	 * reads the column it writes.
	 *
	 * @param string $start_utc 'Y-m-d H:i:s' in UTC.
	 * @param string $iso       ISO 8601 duration.
	 * @param string $tz        IANA zone id the event was scheduled in.
	 * @return string 'Y-m-d H:i:s' in UTC, or '' if any argument is unusable.
	 */
	public static function derive_end_utc( $start_utc, $iso, $tz ) {
		$iso = self::normalize( $iso );
		if ( '' === $iso || ! is_string( $start_utc ) || '' === $start_utc ) {
			return '';
		}
		try {
			$zone  = new DateTimeZone( (string) $tz );
			$start = new DateTime( $start_utc, new DateTimeZone( 'UTC' ) );

			// Into the event's own zone, add there, come back. The order is the rule.
			$start->setTimezone( $zone );
			$start->add( new DateInterval( $iso ) );
			$start->setTimezone( new DateTimeZone( 'UTC' ) );

			return $start->format( 'Y-m-d H:i:s' );
		} catch ( Exception $e ) {
			return '';
		}
	}

	/**
	 * The NOMINAL duration between two UTC instants, read on the wall clock of $tz.
	 *
	 * ── WHY THIS EXISTS ──────────────────────────
	 *
	 * Every stored `duration` is EXACT: create(), update() and the
	 * backfill all write between_exact(), because only an exact span is sure to
	 * reproduce a stored end byte for byte. That is right for the row's OWN end.
	 * It is wrong the moment the span is re-applied to a DIFFERENT start — which
	 * is what a repeating appointment does to every occurrence after the first.
	 * The expander and the series writers did exactly that, as seconds on a UTC
	 * instant, and an all-day daily job drew 00:00 → 01:00 the next day on the
	 * spring-forward and 00:00 → 23:00 on the fall-back. Everything downstream —
	 * conflicts, availability, free-slot search, the provider push — inherited
	 * the wrong block.
	 */
	public static function nominal_between( $start_utc, $end_utc, $tz ) {
		try {
			$zone = new DateTimeZone( (string) $tz );
			$a    = new DateTime( (string) $start_utc, new DateTimeZone( 'UTC' ) );
			$b    = new DateTime( (string) $end_utc, new DateTimeZone( 'UTC' ) );
		} catch ( Exception $e ) {
			return '';
		}
		if ( $b->getTimestamp() <= $a->getTimestamp() ) {
			return '';
		}
		$a->setTimezone( $zone );
		$b->setTimezone( $zone );

		/* Whole days, counted on the wall clock: the largest N for which the start
		 * plus N local days has not passed the end. Counted by stepping rather than
		 * by dividing seconds by 86400, because dividing is the exact arithmetic
		 * this function exists to stop. Bounded by MAX_SECONDS, i.e. 366 steps. */
		$days = 0;
		$cur  = clone $a;
		while ( $days < 366 ) {
			$next = ( clone $cur )->modify( '+1 day' );
			if ( $next->getTimestamp() > $b->getTimestamp() ) {
				break;
			}
			$cur = $next;
			$days++;
		}
		/*
		 * EARLIER on the wall than the start it follows. */
		$wall = strtotime( $b->format( 'Y-m-d H:i:s' ) . ' UTC' ) - strtotime( $cur->format( 'Y-m-d H:i:s' ) . ' UTC' );
		$rest = ( $wall > 0 ) ? $wall : ( $b->getTimestamp() - $cur->getTimestamp() );

		$out = 'P';
		if ( $days > 0 ) {
			$out .= $days . 'D';
		}
		if ( $rest > 0 ) {
			$out .= substr( self::from_seconds( $rest ), 1 );   // 'PT…' → 'T…'
		}
		return ( 'P' === $out ) ? '' : self::normalize( $out );
	}

	/**
	 * THE end of one OCCURRENCE: a template's span, re-applied to a new start.
	 *
	 * The single function every end computation for a repeating appointment goes
	 * through — the expander, move_instance()'s default end
	 * and split_following()'s new master. Before this each of them added the
	 * template's length in SECONDS to a UTC instant.
	 *
	 * Two readings, and which one applies is a property of the appointment, not a
	 * judgement at the call site:
	 *
	 *   ALL-DAY    Wholly wall-clock. An all-day job is "the whole of that day",
	 *              which is 23, 24 or 25 hours depending on the date. The end is
	 *              the template's end WALL TIME on the same number of local days
	 *              after the occurrence's own date. This is the only reading that
	 *              handles the stored all-day shape (00:00:00 → 23:59:59, see
	 *              ZSCH_Appointments::local_to_utc()): its 86,399-second exact span
	 *              and its PT23H59M59S nominal span are BOTH an hour wrong on a
	 *              transition day, because the missing second is not a day.
	 *
	 *   TIMED      nominal_between() of the template, then derive_end_utc() on the
	 *              occurrence's start — whole days on the wall clock, the rest in
	 *              elapsed time. A two-hour appointment is two hours; a 09:00 →
	 *              09:00-tomorrow booking is still 09:00 tomorrow after a change.
	 *
	 * Falls back to the template's exact length only when every derivation fails
	 * (an unknown zone), because an occurrence with SOME end beats one with none.
	 */
	public static function derive_instance_end_utc( $instance_start_utc, $template_start_utc, $template_end_utc, $tz, $all_day = false ) {
		if ( ! is_string( $instance_start_utc ) || '' === $instance_start_utc ) {
			return '';
		}
		/* The template's OWN occurrence ends where the row says it does. Since
		 * stored end; the stored end is the record, so it is returned as-is. */
		if ( (string) $instance_start_utc === (string) $template_start_utc
			&& is_string( $template_end_utc ) && strcmp( $template_end_utc, $template_start_utc ) > 0 ) {
			return (string) $template_end_utc;
		}
		if ( $all_day ) {
			try {
				$zone = new DateTimeZone( (string) $tz );
				$utc  = new DateTimeZone( 'UTC' );
				$ts   = ( new DateTime( (string) $template_start_utc, $utc ) )->setTimezone( $zone );
				$te   = ( new DateTime( (string) $template_end_utc, $utc ) )->setTimezone( $zone );
				$is   = ( new DateTime( $instance_start_utc, $utc ) )->setTimezone( $zone );

				// Calendar days between the template's local dates — dates, not seconds.
				$d0   = new DateTime( $ts->format( 'Y-m-d' ), $utc );
				$d1   = new DateTime( $te->format( 'Y-m-d' ), $utc );
				$days = (int) round( ( $d1->getTimestamp() - $d0->getTimestamp() ) / 86400 );

				$date = ( new DateTime( $is->format( 'Y-m-d' ), $utc ) )->modify( ( $days >= 0 ? '+' : '' ) . $days . ' days' );
				$end_local = $date->format( 'Y-m-d' ) . ' ' . $te->format( 'H:i:s' );
				$end       = class_exists( 'ZSCH_Tzdata' ) ? ZSCH_Tzdata::resolve( $end_local, $zone ) : new DateTime( $end_local, $zone );
				if ( ! $end ) {
					throw new Exception( 'unreadable end' );
				}
				$end->setTimezone( $utc );
				$out = $end->format( 'Y-m-d H:i:s' );
				if ( strcmp( $out, $instance_start_utc ) > 0 ) {
					return $out;
				}
			} catch ( Exception $e ) {
				// fall through to the timed reading
			}
		}
		$iso = self::nominal_between( $template_start_utc, $template_end_utc, $tz );
		if ( '' !== $iso ) {
			$out = self::derive_end_utc( $instance_start_utc, $iso, $tz );
			if ( '' !== $out ) {
				return $out;
			}
		}
		$s = strtotime( (string) $template_start_utc . ' UTC' );
		$e = strtotime( (string) $template_end_utc . ' UTC' );
		$i = strtotime( $instance_start_utc . ' UTC' );
		if ( ! $i ) {
			return '';
		}
		$len = ( $s && $e && $e > $s ) ? ( $e - $s ) : 3600;
		return gmdate( 'Y-m-d H:i:s', $i + $len );
	}

	/**
	 * The EXACT duration between two UTC instants.
	 *
	 * Used by the backfill, and exact on purpose: it is the only form that can
	 * reproduce an already-stored `end_utc` byte for byte, whatever convention
	 * that row was written under. A backfill that "improves" a stored value is a
	 * backfill that changed data, which this one must never do.
	 *
	 * @param string $start_utc
	 * @param string $end_utc
	 * @return string '' when the pair is unusable or not positive.
	 */
	public static function between_exact( $start_utc, $end_utc ) {
		try {
			$a = new DateTime( (string) $start_utc, new DateTimeZone( 'UTC' ) );
			$b = new DateTime( (string) $end_utc, new DateTimeZone( 'UTC' ) );
		} catch ( Exception $e ) {
			return '';
		}
		return self::from_seconds( $b->getTimestamp() - $a->getTimestamp() );
	}

	/**
	 * Does this duration reproduce this stored end instant exactly?
	 *
	 * The backfill's safety gate, and the invariant a test asserts for every row:
	 * whatever we record as a duration must derive back to the end that is already
	 * on the row. If it does not, the row is left alone and reported rather than
	 * quietly rewritten.
	 *
	 * @param string $start_utc
	 * @param string $end_utc
	 * @param string $iso
	 * @param string $tz
	 * @return bool
	 */
	public static function round_trips( $start_utc, $end_utc, $iso, $tz ) {
		$derived = self::derive_end_utc( $start_utc, $iso, $tz );
		return ( '' !== $derived ) && ( $derived === (string) $end_utc );
	}
}
