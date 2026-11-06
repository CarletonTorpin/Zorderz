<?php
/**
 * ZSCH_Series — turn stored master rows into the instances a calendar shows.
 *
 * ── WHY EXPAND ON READ RATHER THAN MATERIALISE ON WRITE ───────────────────────
 *
 * The tempting alternative is to write N rows when somebody creates a series.
 * It is wrong for two reasons that do not go away:
 *
 * So ONE row carries the rule, and instances are generated for whatever window
 * is being looked at. That is what every mature calendar does, and it is why
 * `recurrence_id` rather than a row id is the handle for a single occurrence.
 *
 * ── THE THREE KINDS OF ROW ────────────────────────────────────────────────────
 *
 *   MASTER     `rrule <> ''`. Carries the rule, the exdates, and the wall time.
 *              Its own `start_utc` is the FIRST instance.
 *   OVERRIDE   `series_uid <> ''` and `recurrence_id <> ''`. A real row that
 *              REPLACES one generated instance — this is how "move just this
 *              one" works without touching the series. Its `recurrence_id` is
 *              the instance's ORIGINAL start and never changes.
 *   PLAIN      Neither. An ordinary appointment, passed through untouched.
 *
 * ── THE BUDGET, AND WHY IT IS REPORTED ────────────────────────────────────────
 *
 * A year view over ten daily series is 3,650 instances. Generating them is cheap;
 * shipping them to a phone is not. There is a hard per-query budget, and when it
 * is hit the result SAYS SO rather than quietly returning a short list — a
 * calendar that silently stops drawing appointments after some invisible limit is
 * worse than one that refuses, because the gap looks like a free afternoon.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZSCH_Series {

	/** Instances one query may generate across ALL series before it stops. */
	const QUERY_BUDGET = 1500;

	/** Instances any ONE series may contribute to a single window. */
	const PER_SERIES_CAP = 400;

	/**
	 * Is this row a series master?
	 *
	 * @param array $row
	 * @return bool
	 */
	public static function is_master( array $row ) {
		return '' !== trim( (string) ( $row['rrule'] ?? '' ) );
	}

	/**
	 * Is this row an override for one instance of a series?
	 *
	 * @param array $row
	 * @return bool
	 */
	public static function is_override( array $row ) {
		return '' !== trim( (string) ( $row['series_uid'] ?? '' ) )
			&& '' !== trim( (string) ( $row['recurrence_id'] ?? '' ) );
	}

	/**
	 * Split a raw row set into the three kinds.
	 *
	 * PURE.
	 *
	 * @param array $rows
	 * @return array{masters:array,overrides:array,plain:array}
	 */
	public static function sort_rows( array $rows ) {
		$masters = array(); $overrides = array(); $plain = array();
		foreach ( $rows as $r ) {
			if ( ! is_array( $r ) ) { continue; }
			if ( self::is_override( $r ) ) {
				/* Keyed by series + original start, which is exactly the pair that
				  */
				$overrides[ $r['series_uid'] . '|' . $r['recurrence_id'] ] = $r;
			} elseif ( self::is_master( $r ) ) {
				$masters[] = $r;
			} else {
				$plain[] = $r;
			}
		}
		return array( 'masters' => $masters, 'overrides' => $overrides, 'plain' => $plain );
	}

	/**
	 * Expand one master into the instances that fall inside a UTC window.
	 *
	 * PURE — overrides are passed in rather than queried, so this is testable
	 * without a database and cannot surprise a caller with a second round trip.
	 *
	 * @param array  $master
	 * @param string $win_start_utc
	 * @param string $win_end_utc
	 * @param array  $overrides   keyed 'series_uid|recurrence_id'.
	 * @param int    $budget      instances still available to this query.
	 * @return array{instances:array,used:int,truncated:bool}
	 */
	public static function expand_master( array $master, $win_start_utc, $win_end_utc, array $overrides = array(), $budget = self::QUERY_BUDGET ) {
		$empty = array( 'instances' => array(), 'used' => 0, 'truncated' => false );

		$rule = trim( (string) ( $master['rrule'] ?? '' ) );
		if ( '' === $rule || ! class_exists( 'ZSCH_Recurrence' ) ) {
			return $empty;
		}
		$tz = (string) ( $master['time_zone'] ?? 'UTC' );

		/* DTSTART is the master's own start, expressed as wall clock in the event's
		 * zone. Converting it here — rather than storing a local column — keeps a
		 * single source of truth for when the series begins. */
		$dtstart_local = self::utc_to_local( (string) $master['start_utc'], $tz );
		if ( '' === $dtstart_local ) {
			return $empty;
		}

		/* The window is UTC; the engine works in local dates. Widen by a day on
		 * each side before converting so an instance sitting within a zone offset
		 * of the boundary is not lost, then filter exactly on UTC afterwards. */
		$horizon_local = self::utc_to_local( self::shift_days( $win_end_utc, 1 ), $tz );

		/* The floor, not just the ceiling. PER_SERIES_CAP is a cap on what this
		 * window may SHOW; counted from DTSTART instead, a series older than the
		 * cap would produce instances that are all filtered out afterwards and a
		 * blank calendar. Telling the engine where the window starts is what makes
		 * the cap mean "there is more you are not seeing". */
		$from_local = self::utc_to_local( self::shift_days( $win_start_utc, -1 ), $tz );

		$opts = array(
			'exdate_local'  => self::split_list( $master['exdate'] ?? '' ),
			'rdate_local'   => self::split_list( $master['rdate'] ?? '' ),
			'horizon_local' => $horizon_local,
			'from_local'    => $from_local,
			'limit'         => max( 1, min( self::PER_SERIES_CAP, (int) $budget ) ),
		);

		$res = ZSCH_Recurrence::expand( $dtstart_local, $tz, $rule, $opts );
		if ( empty( $res['ok'] ) ) {
			/* A master carrying a rule this engine refuses is a data problem, not a
			 * reason to drop the appointment on the floor. Show the master itself so
			  */
			return array(
				'instances' => array( self::as_instance( $master, $master['start_utc'], $master['end_utc'], '', true ) ),
				'used'      => 1,
				'truncated' => false,
			);
		}

		$out      = array();
		$uid      = (string) ( $master['uid'] ?? '' );

		foreach ( $res['instances'] as $inst ) {
			$rid = $inst['recurrence_id'];

			/* An override REPLACES the generated instance entirely — its own times,
			 * title and status win, because somebody edited that one on purpose. */
			$key = $uid . '|' . $rid;
			if ( isset( $overrides[ $key ] ) ) {
				$o = $overrides[ $key ];
				/* A cancelled override is a removed instance. Storing the
				 * cancellation as a row rather than only as an EXDATE keeps the
				 * reason and the actor in the change log. */
				if ( 'cancelled' === ( $o['status'] ?? '' ) || ! empty( $o['deleted_at'] ) ) {
					continue;
				}
				$out[] = self::as_instance( $o, $o['start_utc'], $o['end_utc'], $rid, false, true );
				continue;
			}

			$start = $inst['utc'];
			/* The end is DERIVED per occurrence (ZSCH_Duration::
			 * derive_instance_end_utc()), never the master's length in seconds on
			 * a UTC instant: that would draw an all-day daily job 00:00 → 01:00 the
			 * next day across a spring-forward, and hand that block to every
			 * conflict check, availability grid and provider push downstream. */
			$end   = self::instance_end_utc( $master, $start );

			/* Exact window filter. The engine was given a widened horizon so nothing
			 * near a boundary was lost; this is where the real bounds are applied. */
			if ( strcmp( $start, $win_end_utc ) >= 0 ) { continue; }
			if ( strcmp( $end, $win_start_utc ) <= 0 ) { continue; }

			$out[] = self::as_instance( $master, $start, $end, $rid );
			if ( count( $out ) >= $opts['limit'] ) {
				break;
			}
		}

		/* TRUNCATION MEANS "there is more you are not seeing", and only two things
		 * qualify: the per-series cap, or the query budget. The engine's own
		 * `truncated` flag does NOT — it means "I stopped at the horizon", and the
		 * horizon is the WINDOW, which this method set deliberately. Propagating it
		 * marked every ordinary month view as truncated simply because the series
		 * continues into next month, which is not a warning, it is a calendar. */
		return array(
			'instances' => $out,
			'used'      => count( $out ),
			'truncated' => count( $out ) >= $opts['limit'],
		);
	}

	/**
	 * The whole read: rows in, instances out, window-filtered and ordered.
	 *
	 * PURE. Everything it needs — masters, overrides, plain rows — is already in
	 * the row set the caller fetched, so this adds no queries of its own.
	 *
	 * @param array  $rows
	 * @param string $win_start_utc
	 * @param string $win_end_utc
	 * @return array{rows:array,truncated:bool,series_count:int}
	 */
	public static function materialize( array $rows, $win_start_utc, $win_end_utc ) {
		$sorted = self::sort_rows( $rows );
		$out    = $sorted['plain'];
		$budget = self::QUERY_BUDGET - count( $out );
		$trunc  = false;

		foreach ( $sorted['masters'] as $m ) {
			if ( $budget <= 0 ) { $trunc = true; break; }
			$r      = self::expand_master( $m, $win_start_utc, $win_end_utc, $sorted['overrides'], $budget );
			$out    = array_merge( $out, $r['instances'] );
			$budget -= $r['used'];
			if ( $r['truncated'] ) { $trunc = true; }
		}

		/* An override whose master is NOT in this row set still belongs in the
		 * window — it is a real appointment somebody moved, and dropping it because
		 * its parent fell outside the query is how a moved instance disappears. */
		foreach ( $sorted['overrides'] as $o ) {
			if ( 'cancelled' === ( $o['status'] ?? '' ) ) { continue; }
			$seen = false;
			foreach ( $out as $x ) {
				if ( (int) ( $x['id'] ?? 0 ) === (int) $o['id'] ) { $seen = true; break; }
			}
			if ( $seen ) { continue; }
			if ( strcmp( (string) $o['start_utc'], $win_end_utc ) >= 0 ) { continue; }
			if ( strcmp( (string) $o['end_utc'], $win_start_utc ) <= 0 ) { continue; }
			$out[] = self::as_instance( $o, $o['start_utc'], $o['end_utc'], (string) $o['recurrence_id'], false, true );
		}

		usort( $out, static function ( $a, $b ) {
			$c = strcmp( (string) $a['start_utc'], (string) $b['start_utc'] );
			return ( 0 !== $c ) ? $c : ( (int) $a['id'] <=> (int) $b['id'] );
		} );

		return array(
			'rows'         => $out,
			'truncated'    => $trunc,
			'series_count' => count( $sorted['masters'] ),
		);
	}

	/**
	 * Build one instance row from the row it came from.
	 *
	 * The instance keeps the SOURCE ROW'S id, because that is what permissions,
	 * participants and job links hang off. What identifies the occurrence is the
	 * pair (uid, recurrence_id), surfaced as `instance_key` — an instance has no
	 * id of its own precisely because it has no row of its own.
	 *
	 * @param array  $src
	 * @param string $start_utc
	 * @param string $end_utc
	 * @param string $recurrence_id
	 * @param bool   $broken   the rule could not be parsed.
	 * @param bool   $is_override
	 * @return array
	 */
	private static function as_instance( array $src, $start_utc, $end_utc, $recurrence_id, $broken = false, $is_override = false ) {
		$row                  = $src;
		$row['start_utc']     = $start_utc;
		$row['end_utc']       = $end_utc;
		$row['recurrence_id'] = (string) $recurrence_id;
		$row['_is_instance']  = ( '' !== (string) $recurrence_id );
		$row['_is_override']  = (bool) $is_override;
		$row['_rule_broken']  = (bool) $broken;
		$row['_series_uid']   = $is_override
			? (string) ( $src['series_uid'] ?? '' )
			: (string) ( $src['uid'] ?? '' );
		$row['_instance_key'] = ( '' !== (string) $recurrence_id )
			? $row['_series_uid'] . '|' . $recurrence_id
			: '';
		return $row;
	}

	/* ══ helpers ═══════════════════════════════════════════════════════ */

	/**
	 * How long is this row, in seconds?
	 *
	 * @param array $row
	 * @return int
	 */
	public static function row_duration_seconds( array $row ) {
		$s = strtotime( (string) $row['start_utc'] . ' UTC' );
		$e = strtotime( (string) $row['end_utc'] . ' UTC' );
		if ( $s && $e && $e > $s ) {
			return $e - $s;
		}
		return 3600;   // a defensible hour rather than a zero-length appointment
	}

	/**
	 * The end of the occurrence of $master that starts at $start_utc.
	 *
	 * ONE call for every place that needs it — the expander here, and
	 * ZSCH_Series_Write's move_instance() default end and split_following() new
	 * master — so the three cannot drift apart the way three copies of
	 * `start + seconds` did. The arithmetic itself lives in
	 * ZSCH_Duration::derive_instance_end_utc(), which is the class that owns
	 * "how long is this, and in which clock".
	 */
	public static function instance_end_utc( array $master, $start_utc ) {
		$tz  = (string) ( $master['time_zone'] ?? 'UTC' );
		$end = '';
		if ( class_exists( 'ZSCH_Duration' ) && method_exists( 'ZSCH_Duration', 'derive_instance_end_utc' ) ) {
			$end = ZSCH_Duration::derive_instance_end_utc(
				(string) $start_utc,
				(string) ( $master['start_utc'] ?? '' ),
				(string) ( $master['end_utc'] ?? '' ),
				$tz,
				self::is_all_day_shape( $master )
			);
		}
		return ( '' !== $end ) ? $end : self::add_seconds( (string) $start_utc, self::row_duration_seconds( $master ) );
	}

	/**
	 * Is this row an all-day appointment, by flag OR by shape?
	 *
	 * The flag alone is not enough. ZSCH_Appointments::local_to_utc() turns a
	 * date-only start/end into 00:00:00 → 23:59:59 local KEYED ON THE STRING'S
	 * SHAPE, deliberately not on the flag — so a row can be a whole local day
	 * without saying so. A row that starts at local midnight and ends at the last
	 * second of a local day (or at a later local midnight) is a whole-day block
	 * whatever the flag says, and re-applying it to another date must give that
	 * date's whole day, however long the zone made it.
	 */
	public static function is_all_day_shape( array $row ) {
		if ( ! empty( $row['is_all_day'] ) ) {
			return true;
		}
		$tz = (string) ( $row['time_zone'] ?? 'UTC' );
		$s  = self::utc_to_local( (string) ( $row['start_utc'] ?? '' ), $tz );
		$e  = self::utc_to_local( (string) ( $row['end_utc'] ?? '' ), $tz );
		if ( '' === $s || '' === $e || strcmp( $e, $s ) <= 0 ) {
			return false;
		}
		return '00:00:00' === substr( $s, 11 )
			&& in_array( substr( $e, 11 ), array( '23:59:59', '00:00:00' ), true );
	}

	/** @return string 'Y-m-d H:i:s' local wall clock, or '' */
	public static function utc_to_local( $utc, $tz ) {
		if ( ! is_string( $utc ) || '' === $utc ) { return ''; }
		try {
			$d = new DateTime( $utc, new DateTimeZone( 'UTC' ) );
			$d->setTimezone( new DateTimeZone( (string) $tz ) );
			return $d->format( 'Y-m-d H:i:s' );
		} catch ( Exception $e ) {
			return '';
		}
	}

	/** @return string 'Y-m-d H:i:s' UTC */
	public static function local_to_utc( $local, $tz ) {
		if ( ! is_string( $local ) || '' === $local ) { return ''; }
		if ( class_exists( 'ZSCH_Tzdata' ) ) {
			return ZSCH_Tzdata::local_to_utc( $local, $tz );
		}
		try {
			$d = new DateTime( $local, new DateTimeZone( (string) $tz ) );
			$d->setTimezone( new DateTimeZone( 'UTC' ) );
			return $d->format( 'Y-m-d H:i:s' );
		} catch ( Exception $e ) {
			return '';
		}
	}

	/** @return string */
	private static function add_seconds( $utc, $secs ) {
		return gmdate( 'Y-m-d H:i:s', strtotime( $utc . ' UTC' ) + (int) $secs );
	}

	/** @return string */
	private static function shift_days( $utc, $n ) {
		return gmdate( 'Y-m-d H:i:s', strtotime( $utc . ' UTC' ) + ( (int) $n * 86400 ) );
	}

	/**
	 * A stored EXDATE/RDATE list -> array of local wall-clock strings.
	 *
	 * Newline or comma separated, because both are what a human or an importer
	 * will produce and neither is worth refusing.
	 *
	 * @param mixed $raw
	 * @return string[]
	 */
	public static function split_list( $raw ) {
		$raw = trim( (string) $raw );
		if ( '' === $raw ) { return array(); }
		$parts = preg_split( '/[\r\n,]+/', $raw );
		$out   = array();
		foreach ( (array) $parts as $p ) {
			$p = trim( $p );
			if ( '' !== $p ) { $out[] = $p; }
		}
		return $out;
	}

	/**
	 * Add one value to a stored list, without duplicating it.
	 *
	 * @param mixed  $raw
	 * @param string $value
	 * @return string
	 */
	public static function add_to_list( $raw, $value ) {
		$list  = self::split_list( $raw );
		$value = trim( (string) $value );
		if ( '' === $value ) { return implode( "\n", $list ); }
		if ( ! in_array( $value, $list, true ) ) { $list[] = $value; }
		sort( $list );
		return implode( "\n", $list );
	}

	/**
	 * Remove one value from a stored list.
	 *
	 * EXDATE was added by cancel() and by delete() and removed by NOTHING. There
	 * was no operation in the plugin that could take an entry off the list, so
	 * reinstating a cancelled occurrence left the EXDATE in place: the row was
	 * live locally, and every push told Google and Outlook that occurrence was
	 * cancelled. The local calendar only looked right because materialize() has
	 * a fallback loop — expand_master() alone reports the occurrence missing.
	 */
	public static function remove_from_list( $raw, $value ) {
		$list  = self::split_list( $raw );
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return implode( "\n", $list );
		}
		$list = array_values( array_filter( $list, static function ( $v ) use ( $value ) {
			return $v !== $value;
		} ) );
		sort( $list );
		return implode( "\n", $list );
	}

	/**
	 * Partition a stored list at a local wall-clock boundary.
	 *
	 * ── WHAT THIS IS FOR ──────────────────────────────────────────────────────
	 *
	 * Comparison is strcmp on 'Y-m-d H:i:s' local wall clock, which is the form
	 * these lists hold and the form recurrence_id holds; both sort lexically the
	 * same way they sort chronologically. A UTC instant must NOT be passed here.
	 */
	public static function split_list_at( $raw, $at_local ) {
		$at     = trim( (string) $at_local );
		$before = array();
		$after  = array();
		foreach ( self::split_list( $raw ) as $v ) {
			if ( '' !== $at && strcmp( $v, $at ) < 0 ) {
				$before[] = $v;
			} else {
				$after[] = $v;
			}
		}
		sort( $before );
		sort( $after );
		return array(
			'before' => implode( "\n", $before ),
			'after'  => implode( "\n", $after ),
		);
	}

	/**
	 * When does this series stop?
	 *
	 * Stored on the master as `series_until_utc` so the read query can EXCLUDE a
	 * finished series in SQL instead of loading and expanding every series ever
	 * created on every calendar read. NULL means unbounded.
	 *
	 * @param string $dtstart_local
	 * @param string $tz
	 * @param string $rule
	 * @return string|null 'Y-m-d H:i:s' UTC, or null when the series never ends.
	 */
	public static function compute_until( $dtstart_local, $tz, $rule ) {
		if ( ! class_exists( 'ZSCH_RRule' ) ) { return null; }
		$p = ZSCH_RRule::parse( $rule, true );
		if ( empty( $p['ok'] ) ) { return null; }

		/* Unbounded rules have no end, and saying so explicitly is what keeps them
		 * visible forever instead of vanishing at a horizon somebody guessed. */
		if ( 0 === (int) $p['count'] && '' === (string) $p['until_utc'] ) {
			return null;
		}
		if ( '' !== (string) $p['until_utc'] ) {
			return (string) $p['until_utc'];
		}
		/* REACH THE END, OR SAY THERE ISN'T ONE.
		 * THE TWO FAILURES ARE NOT SYMMETRIC, and that is what decides the else
		 * branch. A bound that is too LONG costs one row read per calendar page,
		 * because the expansion at draw time still honours COUNT. A bound that is
		 * too SHORT deletes contracted work from the calendar silently. So when
		 * the end cannot be established, NULL — unbounded — is the answer, and
		 * never a shorter guess. */
		$r = ZSCH_Recurrence::expand_all( $dtstart_local, $tz, $rule );
		if ( empty( $r['ok'] ) || empty( $r['complete'] ) || empty( $r['instances'] ) ) {
			return null;
		}
		$last = $r['instances'][ count( $r['instances'] ) - 1 ];
		return (string) $last['utc'];
	}
	/**
	 * The RFC's (UID, RECURRENCE-ID) pair, as something an index can hold.
	 *
	 * The separator is escaped rather than trusted: without it ('a|b', 'c') and
	 * ('a', 'b|c') are one key, and a crafted UID could collide with a real
	 * occurrence of another series.
	 *
	 * @param string $series_uid
	 * @param string $recurrence_id
	 * @return string|null 32 hex characters, or NULL when this is not an override.
	 */
	public static function override_key( $series_uid, $recurrence_id ) {
		$series_uid    = trim( (string) $series_uid );
		$recurrence_id = trim( (string) $recurrence_id );
		if ( '' === $series_uid || '' === $recurrence_id ) {
			return null;
		}
		$esc = static function ( $v ) {
			return str_replace( array( '\\', '|' ), array( '\\\\', '\\|' ), $v );
		};
		return md5( $esc( $series_uid ) . '|' . $esc( $recurrence_id ) );
	}

}
