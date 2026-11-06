<?php
/**
 * ZHR_Clock -- the running clock. One running entry per person at most (a MySQL lock around every change), started
 * by Clock in or by a door-hanger walk, stopped by Clock out or by the end of the walk that started it.
 *
 * The app never invents a clock-out time. A Clock out (or Meal break) on a clock past 16 hours is refused with "set the
 * time you finished", and a walk finished that late leaves its clock open for a person; finish_locked() is the person's
 * "Clock out as of …", kept as a correction with who set it; a Clock in waits while an earlier day's shift is still open;
 * ask_office_locked() leaves a shift with the office.
 *
 * A walk's clock is found by the walk it belongs to (source_ref 'zdh:<drop>'), so finishing or discarding one walk never
 * stops another, and finishing someone's walk from another account stops the walker's clock, not the presser's. A clock
 * started back in time (a phone's queued start) begins after anything already logged, never on top of it. Minutes come
 * from the two moments themselves, so the night the clocks go back counts its real minutes.
 *
 * @package Zorderz\Hours
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZHR_Clock {

	/** A coordinate pair from a request, or nulls (accuracy in metres, at most 50 km). */
	public static function place( $lat, $lng, $acc ) {
		if ( ! is_numeric( $lat ) || ! is_numeric( $lng ) ) {
			return array( null, null, null );
		}
		$lat = (float) $lat;
		$lng = (float) $lng;
		if ( $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180 || ( 0.0 === $lat && 0.0 === $lng ) ) {
			return array( null, null, null );
		}
		$acc = is_numeric( $acc ) ? max( 0, min( 50000, (int) round( (float) $acc ) ) ) : null;
		return array( round( $lat, 7 ), round( $lng, 7 ), $acc );
	}

	/**
	 * Start the clock at $at_ts (now, or a walk's own start a little earlier). Returns ['entry', 'started'] or WP_Error.
	 * Already running -> that entry, started false. Anything logged between $at_ts and now moves the start to its end
	 * (a backdated start never double counts); a stretch logged past now refuses.
	 */
	public static function start( $uid, $at_ts, $source, $ref = '', $place = array( null, null, null ), $note = '', $actor = 0 ) {
		$uid = (int) $uid;
		if ( ! ZHR_DB::lock( $uid ) ) {
			return new WP_Error( 'busy', __( 'Hours is busy with your clock. Try again in a moment.', 'zorderz' ), array( 'status' => 409 ) );
		}
		try {
			$run = ZHR_DB::running( $uid );
			if ( $run && 'timer' === $source && self::asks_finish( $run, time() ) ) {
				// yesterday's shift (or one past 16 hours) is answered first; the new clock-in waits for it
				return new WP_Error( 'open_shift', self::open_shift_words( $run ), array( 'status' => 409, 'entry' => (int) $run['id'] ) );
			}
			if ( $run ) {
				return array( 'entry' => $run, 'started' => false );
			}
			$now   = time();
			$at_ts = min( (int) $at_ts, $now );
			$hits  = ZHR_DB::touching( $uid, ZHR_Time::local( $at_ts ), ZHR_Time::local( $now + 60 ) );
			foreach ( $hits as $h ) {
				$he = empty( $h['end_at'] ) ? null : ZHR_Time::ts( $h['end_at'] );
				if ( null === $he || $he > $now ) {
					/* translators: %s: a stretch of time already logged */
					return new WP_Error( 'overlap', sprintf( __( 'You already have %s logged. Clock in after it ends, or change that entry.', 'zorderz' ), ZHR_Time::label_span( $h['start_at'], $h['end_at'] ) ), array( 'status' => 409 ) );
				}
				$at_ts = max( $at_ts, $he );
			}
			$start_at = ZHR_Time::local( $at_ts );
			$id       = ZHR_DB::insert( array(
				'user_id'    => $uid,
				'work_date'  => substr( $start_at, 0, 10 ),
				'start_at'   => $start_at,
				'end_at'     => null,
				'minutes'    => 0,
				'source'     => $source,
				'source_ref' => $ref,
				'note'       => $note,
				'start_lat'  => $place[0],
				'start_lng'  => $place[1],
				'start_acc'  => $place[2],
				'created_by' => $actor ? (int) $actor : $uid,
			) );
			if ( ! $id ) {
				return new WP_Error( 'db', __( 'Hours could not save the clock. Try again.', 'zorderz' ), array( 'status' => 500 ) );
			}
			$entry = ZHR_DB::get( $id );
			delete_user_meta( $uid, 'zhr_on_meal' ); // any clock started ends a meal break (a walk's too)
			ZHR_DB::add_activity( array( array( 'user_id' => $uid, 'at' => $start_at, 'kind' => 'clock', 'app_id' => ZHR_APP_ID, 'lat' => $place[0], 'lng' => $place[1], 'acc' => $place[2], 'detail' => 'clock in' . ( 'doorhangers' === $source ? ' (route)' : '' ), 'ref' => 'hours:in:' . $id ) ) );
			return array( 'entry' => $entry, 'started' => true );
		} finally {
			ZHR_DB::unlock( $uid );
		}
	}

	/**
	 * Stop $uid's running clock at $at_ts. $only_ref: stop it only when it belongs to that walk ('' = any clock).
	 * Never past 16 hours, and never cut to 16 either (that would invent a finish): a person's Clock out or Meal break is
	 * refused (needs_time: "set the time you finished"); a walk finished that late leaves the clock open (null).
	 * Returns the finished entry, null when nothing (matching) was running, or WP_Error.
	 */
	public static function stop( $uid, $at_ts, $place = array( null, null, null ), $why = 'clock_out', $only_ref = '', $actor = 0 ) {
		$uid = (int) $uid;
		if ( ! ZHR_DB::lock( $uid ) ) {
			return new WP_Error( 'busy', __( 'Hours is busy with your clock. Try again in a moment.', 'zorderz' ), array( 'status' => 409 ) );
		}
		try {
			$run = ZHR_DB::running( $uid );
			if ( ! $run || ( '' !== $only_ref && $only_ref !== $run['source_ref'] ) ) {
				return null;
			}
			$s = (int) ZHR_Time::ts( $run['start_at'] );
			if ( (int) $at_ts - $s < 60 ) {
				// in and out within a minute is a mis-tap, not work: the entry goes (kept, with who removed it). An end
				// pushed to a full minute would lie in the future and block the next clock-in.
				ZHR_DB::soft_delete( $run['id'], $actor ? (int) $actor : $uid );
				return ZHR_DB::get( $run['id'] );
			}
			$end_ts = (int) $at_ts;
			if ( $end_ts - $s > ZHR_Time::MAX_ENTRY_MIN * 60 ) {
				if ( in_array( $why, array( 'clock_out', 'meal_break' ), true ) ) {
					/* translators: %s: when the clock started, such as "7:12 am on Fri Mar 6" */
					return new WP_Error( 'needs_time', sprintf( __( 'Your clock has been running since %s, more than 16 hours, so Hours will not clock you out now: that would make up a finish time. Set the time you finished.', 'zorderz' ), ZHR_Time::time_words( $s, current_time( 'Y-m-d' ) ) ), array( 'status' => 409, 'entry' => (int) $run['id'] ) );
				}
				error_log( 'Hours: a walk finished more than 16 hours after its clock started; the clock is left open for a person to set (entry ' . (int) $run['id'] . ')' ); // phpcs:ignore -- an id only
				return null;
			}
			$end_at = ZHR_Time::local( $end_ts );
			$fields = array(
				'end_at'  => $end_at,
				'minutes' => (int) floor( ( $end_ts - $s ) / 60 ),
				'end_lat' => $place[0],
				'end_lng' => $place[1],
				'end_acc' => $place[2],
			);
			$ok = ZHR_DB::update( $run['id'], $fields, $actor ? (int) $actor : $uid, $why );
			if ( ! $ok ) {
				return new WP_Error( 'changed', __( 'Your clock was changed somewhere else a moment ago. Reload Hours.', 'zorderz' ), array( 'status' => 409 ) );
			}
			ZHR_DB::add_activity( array( array( 'user_id' => $uid, 'at' => $end_at, 'kind' => 'clock', 'app_id' => ZHR_APP_ID, 'lat' => $place[0], 'lng' => $place[1], 'acc' => $place[2], 'detail' => 'clock out' . ( 'route_finished' === $why ? ' (route finished)' : '' ), 'ref' => 'hours:out:' . $run['id'] ) ) );
			return ZHR_DB::get( $run['id'] );
		} finally {
			ZHR_DB::unlock( $uid );
		}
	}

	/* ------------------------------------------------------------------ the forgotten clock-out */

	/**
	 * Is this open shift one to ask about first? Past 16 hours; or from an earlier day once its reminder time has passed
	 * (a night shift still running after midnight is just running until then). Never one already with the office. With
	 * reminders off, the reminder moment is 14 hours in (the "running long" mark).
	 */
	public static function asks_finish( array $e, $now_ts ) {
		if ( ! empty( $e['end_at'] ) || '' !== (string) ( $e['end_review'] ?? '' ) ) {
			return false;
		}
		if ( 'stale' === ZHR_Time::open_state( $e, $now_ts ) ) {
			return true;
		}
		if ( (string) $e['work_date'] >= current_time( 'Y-m-d' ) ) {
			return false;
		}
		$due = ZHR_Time::remind_ts( $e, zhr_get_settings() );
		if ( null === $due ) {
			$due = (int) ZHR_Time::ts( $e['start_at'] ) + ZHR_Time::LONG_ENTRY_MIN * 60;
		}
		return (int) $now_ts >= $due;
	}

	/** "Yesterday's shift is still open. When did you finish?" (or the day's name for an older one). */
	public static function open_shift_words( array $e ) {
		$today = current_time( 'Y-m-d' );
		$d     = (string) $e['work_date'];
		if ( ZHR_Time::add_days( $today, -1 ) === $d ) {
			return __( "Yesterday's shift is still open. When did you finish?", 'zorderz' );
		}
		if ( $today === $d ) {
			return __( "Today's shift is still open. When did you finish?", 'zorderz' );
		}
		/* translators: %s: a day such as "Fri Mar 6" */
		return sprintf( __( 'Your shift from %s is still open. When did you finish?', 'zorderz' ), ( new DateTimeImmutable( $d . ' 12:00:00', ZHR_Time::tz() ) )->format( 'D M j' ) );
	}

	/**
	 * Set the finish of an open shift ("Clock out as of …"). The caller has checked that $actor may change it
	 * (ZHR_REST::may_change) and holds the person's lock. $hm: 'HH:MM', resolved by ZHR_Time::finish_at(). The clock-in
	 * is kept; the finish is a correction in the history ('set_end' by the person, 'office_set_end' by anyone else), with
	 * who and when, and clears an ask to the office. Returns the entry or WP_Error.
	 */
	public static function finish_locked( $id, $hm, $actor ) {
		$e = ZHR_DB::get( (int) $id );
		if ( ! $e || $e['deleted_at'] ) {
			return new WP_Error( 'not_found', __( 'That entry was not found.', 'zorderz' ), array( 'status' => 404 ) );
		}
		if ( ! empty( $e['end_at'] ) ) {
			return new WP_Error( 'finish_closed', __( 'That shift already has a finish time. Use Change to fix it.', 'zorderz' ), array( 'status' => 409 ) );
		}
		$f = ZHR_Time::finish_at( $e['start_at'], (string) $hm, time() );
		if ( ! $f['ok'] ) {
			return new WP_Error( $f['code'], $f['sentence'], array( 'status' => 400 ) );
		}
		foreach ( ZHR_DB::touching( (int) $e['user_id'], $e['start_at'], $f['end_at'], (int) $e['id'] ) as $o ) {
			/* translators: %s: a stretch of time already logged */
			return new WP_Error( 'overlap', sprintf( __( 'That overlaps %s, already logged. Give an earlier finish, or change that entry.', 'zorderz' ), ZHR_Time::label_span( $o['start_at'], $o['end_at'] ) ), array( 'status' => 409 ) );
		}
		$why = (int) $actor === (int) $e['user_id'] ? 'set_end' : 'office_set_end';
		$ok  = ZHR_DB::update( (int) $e['id'], array( 'end_at' => $f['end_at'], 'minutes' => $f['minutes'], 'end_review' => '' ), (int) $actor, $why );
		if ( ! $ok ) {
			return new WP_Error( 'changed', __( 'That shift was changed somewhere else a moment ago. Reload Hours.', 'zorderz' ), array( 'status' => 409 ) );
		}
		ZHR_DB::add_activity( array( array( 'user_id' => (int) $e['user_id'], 'at' => $f['end_at'], 'kind' => 'clock', 'app_id' => ZHR_APP_ID, 'detail' => 'clock out (set later)', 'ref' => 'hours:out:' . (int) $e['id'] ) ) );
		return ZHR_DB::get( (int) $e['id'] );
	}

	/** "I forgot: ask the office". The person's own open shift only; it stays open with no finish. */
	public static function ask_office_locked( $id, $actor ) {
		$e = ZHR_DB::get( (int) $id );
		if ( ! $e || $e['deleted_at'] || (int) $e['user_id'] !== (int) $actor ) {
			return new WP_Error( 'not_found', __( 'That entry was not found.', 'zorderz' ), array( 'status' => 404 ) ); // never say whose
		}
		if ( ! empty( $e['end_at'] ) ) {
			return new WP_Error( 'finish_closed', __( 'That shift already has a finish time.', 'zorderz' ), array( 'status' => 409 ) );
		}
		if ( '' !== (string) $e['end_review'] ) {
			return new WP_Error( 'already_asked', __( 'The office already has this one. They will set the time you finished.', 'zorderz' ), array( 'status' => 409 ) );
		}
		if ( ! self::asks_finish( $e, time() ) ) {
			// only a forgotten shift goes to the office; a clock running today is clocked out or given its time
			return new WP_Error( 'still_running', __( 'This clock is still running. Clock out, or set the time you finished.', 'zorderz' ), array( 'status' => 409 ) );
		}
		if ( ! ZHR_DB::update( (int) $e['id'], array( 'end_review' => 'asked' ), (int) $actor, 'ask_office' ) ) {
			return new WP_Error( 'changed', __( 'That shift was changed somewhere else a moment ago. Reload Hours.', 'zorderz' ), array( 'status' => 409 ) );
		}
		return ZHR_DB::get( (int) $e['id'] );
	}

	/* ------------------------------------------------------------------ door-hanger walks */

	public static function walk_ref( $drop_id ) {
		return 'zdh:' . (int) $drop_id;
	}

	public static function start_for_walk( $uid, $drop_id, $at_ts ) {
		return self::start( $uid, $at_ts, 'doorhangers', self::walk_ref( $drop_id ), array( null, null, null ), __( 'Door hanging', 'zorderz' ) );
	}

	/**
	 * A walk ended: stop the clock that walk started, whoever's it is (finishing a walker's route from the office stops
	 * the walker's clock). $drop_ids: the walk's ids (a Quick Start walk is saved under a new id; its live id is the one
	 * the clock knows). Returns the finished entry or null.
	 */
	public static function stop_for_walk( array $drop_ids, $at_ts, $why = 'route_finished' ) {
		foreach ( array_unique( array_filter( array_map( 'intval', $drop_ids ) ) ) as $id ) {
			$run = ZHR_DB::running_by_ref( self::walk_ref( $id ) );
			if ( $run ) {
				return self::stop( (int) $run['user_id'], $at_ts, array( null, null, null ), $why, self::walk_ref( $id ) );
			}
		}
		return null;
	}

	/** A walk reset, discarded or deleted: its clock goes if under $mistake_s old, else it stops now. */
	public static function cancel_for_walk( array $drop_ids, $now_ts, $mistake_s ) {
		foreach ( array_unique( array_filter( array_map( 'intval', $drop_ids ) ) ) as $id ) {
			$run = ZHR_DB::running_by_ref( self::walk_ref( $id ) );
			if ( ! $run ) {
				continue;
			}
			if ( $now_ts - (int) ZHR_Time::ts( $run['start_at'] ) < $mistake_s ) {
				return ZHR_DB::soft_delete( $run['id'], (int) $run['user_id'] );
			}
			return self::stop( (int) $run['user_id'], $now_ts, array( null, null, null ), 'route_reset', self::walk_ref( $id ) );
		}
		return null;
	}
}
