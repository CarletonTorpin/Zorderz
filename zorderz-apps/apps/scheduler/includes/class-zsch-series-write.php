<?php
/**
 * ZSCH_Series_Write — the three things you can do to one occurrence.
 *
 * Every calendar with recurrence asks the same question when you change a
 * repeating appointment: **this one, this and following, or all of them?**
 * Those are three genuinely different writes, and conflating any two of them is
 * how a series quietly loses days.
 *
 *   THIS ONE           An OVERRIDE row keyed on the instance's ORIGINAL start.
 *                      The series is untouched (RFC 5545 section 3.8.4.4).
 *   THIS AND FOLLOWING A SPLIT: the original is truncated with UNTIL and a NEW
 *                      series with a NEW UID carries the remainder, related back.
 *                      Not RANGE=THISANDFUTURE, which other clients ignore.
 *   ALL                An ordinary update of the master row
 *                      (ZSCH_Appointments::update()).
 *
 * Every public write answers in ONE envelope (`success` and `ok` merged onto the
 * payload; a WP_Error passed straight through), so a caller never has to know
 * which scope produced the answer.
 *
 * Every read-modify-write of a series' shared columns (rrule, exdate, rdate,
 * sequence) holds the series lock (acquire()/release()), re-reads the master
 * INSIDE the lock, and advances SEQUENCE from that re-read.
 *
 * ── WHY CANCELLING ONE INSTANCE WRITES A ROW, NOT JUST AN EXDATE ──────────────
 *
 * An EXDATE alone makes the instance disappear, and it is what goes on the wire
 * to Google and Outlook. But an EXDATE is a bare timestamp: it does not record
 * WHO cancelled it, WHEN, or from where. Writing a cancelled override row as well
 * gives the change log an actor and a source. So both are written: the EXDATE
 * for correctness on the wire, the row for accountability. The expander honours
 * either.
 *
 * @since 1.10.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZSCH_Series_Write {

	/** The three scopes a caller may ask for. */
	const SCOPES = array( 'instance', 'following', 'series' );

	/** @return string */
	private static function table() {
		global $wpdb;
		return $wpdb->prefix . 'zsch_appointments';
	}

	/**
	 * Normalise a caller's scope word. Anything unrecognised becomes 'instance',
	 * because it is the SMALLEST of the three — a caller who was unclear should
	 * not accidentally rewrite a whole series.
	 *
	 * PURE.
	 *
	 * @param mixed $scope
	 * @return string
	 */
	public static function normalize_scope( $scope ) {
		$scope = is_string( $scope ) ? strtolower( trim( $scope ) ) : '';
		if ( in_array( $scope, self::SCOPES, true ) ) {
			return $scope;
		}
		/* Aliases people and other calendars actually use. */
		if ( in_array( $scope, array( 'this', 'single', 'occurrence' ), true ) )   { return 'instance'; }
		if ( in_array( $scope, array( 'thisandfuture', 'this_and_following', 'future' ), true ) ) { return 'following'; }
		if ( in_array( $scope, array( 'all', 'master', 'whole' ), true ) )         { return 'series'; }
		return 'instance';
	}

	/**
	 * Load a master row by id, refusing anything that is not a series.
	 *
	 * @param int $id
	 * @return array|WP_Error
	 */
	public static function load_master( $id, $actor_id = null ) {
		global $wpdb;
		$t   = self::table();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d AND deleted_at IS NULL", (int) $id ), ARRAY_A ); // phpcs:ignore
		if ( ! $row ) {
			return new WP_Error( 'zsch_not_found', 'That appointment no longer exists.', array( 'status' => 404 ) );
		}
		/* Permission lives HERE rather than in the REST handler, because every one
		 * of these operations already has to load the master and there must not be
		 * two places that decide who may change a series. `can_modify` is the same
		 * predicate the ordinary edit path uses — deliberately not a second,
		 * looser "may edit an occurrence" rule that could drift from it. */
		if ( null !== $actor_id && class_exists( 'ZSCH_Appointments' )
			&& ! ZSCH_Appointments::can_modify( (int) $actor_id, $row ) ) {
			return new WP_Error( 'zsch_forbidden', 'You cannot change that appointment.', array( 'status' => 403 ) );
		}
		if ( '' === trim( (string) ( $row['rrule'] ?? '' ) ) ) {
			return new WP_Error( 'zsch_not_a_series', 'That appointment does not repeat, so there is no occurrence to change.', array( 'status' => 400 ) );
		}
		return $row;
	}

	/**
	 * Is $recurrence_id genuinely an instance of this master?
	 *
	 * Checked before every write. An override keyed on a time the rule never
	 * produces is invisible forever — it would sit in the table looking like a
	 * real appointment and never render, which is worse than an error.
	 *
	 * @param array  $master
	 * @param string $recurrence_id local wall clock.
	 * @return bool
	 */
	public static function is_instance_of( array $master, $recurrence_id ) {
		if ( ! class_exists( 'ZSCH_Recurrence' ) ) { return false; }
		$tz    = (string) $master['time_zone'];
		$local = ZSCH_Series::utc_to_local( (string) $master['start_utc'], $tz );
		if ( '' === $local || '' === (string) $recurrence_id ) { return false; }

		/* Expanded only as far as the candidate, so a long series does not cost a
		 * full expansion to answer a yes/no about one date. */
		$r = ZSCH_Recurrence::expand( $local, $tz, (string) $master['rrule'], array(
			'horizon_local' => (string) $recurrence_id,
			'limit'         => ZSCH_Series::PER_SERIES_CAP,
		) );
		if ( empty( $r['ok'] ) ) { return false; }
		foreach ( $r['instances'] as $i ) {
			if ( $i['recurrence_id'] === (string) $recurrence_id ) { return true; }
		}
		return false;
	}

	/**
	 * Move ONE occurrence, leaving the series alone.
	 *
	 * Writes an override row whose `recurrence_id` is the instance's ORIGINAL
	 * start and stays that way forever. Re-keying it to the new time is the
	 * single most common recurrence bug there is: it detaches the override, so the
	 * generated instance reappears at the old slot AND the override shows at the
	 * new one — a ghost and a duplicate from one drag.
	 *
	 * @param int    $actor_id
	 * @param int    $master_id
	 * @param string $recurrence_id local wall clock of the ORIGINAL start.
	 * @param array  $changes       start_utc/end_utc/title/… to apply to this one.
	 * @return array|WP_Error the override row's shaped form.
	 */
	public static function move_instance( $actor_id, $master_id, $recurrence_id, array $changes ) {
		global $wpdb;
		$master = self::load_master( $master_id, $actor_id );
		if ( is_wp_error( $master ) ) { return $master; }

		$recurrence_id = trim( (string) $recurrence_id );
		if ( ! self::is_instance_of( $master, $recurrence_id ) ) {
			return new WP_Error( 'zsch_not_an_instance',
				'That is not one of this appointment\'s occurrences, so there is nothing to move.',
				array( 'status' => 400 ) );
		}

		/* Local wall clock in, UTC stored. Converting HERE means the caller never
		 * has to know the series' zone, and the zone used is always the master's
		 * own rather than whatever a client guessed. */
		$tzm = (string) $master['time_zone'];
		if ( isset( $changes['start_local'] ) && '' !== $changes['start_local'] ) {
			$changes['start_utc'] = ZSCH_Series::local_to_utc( (string) $changes['start_local'], $tzm );
		}
		if ( isset( $changes['end_local'] ) && '' !== $changes['end_local'] ) {
			$changes['end_utc'] = ZSCH_Series::local_to_utc( (string) $changes['end_local'], $tzm );
		}

		$tz = (string) $master['time_zone'];

		/* Default to the generated instant, so a caller changing only the title
		 * does not have to restate the times it is not touching. */
		$start = (string) ( $changes['start_utc'] ?? ZSCH_Series::local_to_utc( $recurrence_id, $tz ) );
		$end   = (string) ( $changes['end_utc'] ?? '' );
		if ( '' === $end ) {
			/*
			 * expander derives it, through ONE function, never as the master's
			 * length in seconds on a UTC instant. A title-only edit of an all-day
			  */
			$end = ZSCH_Series::instance_end_utc( $master, $start );
		}

		$fields = array(
			'start_utc'     => $start,
			'end_utc'       => $end,
			'title'         => isset( $changes['title'] ) ? sanitize_text_field( $changes['title'] ) : $master['title'],
			'body'          => isset( $changes['body'] ) ? sanitize_textarea_field( $changes['body'] ) : $master['body'],
			'location'      => isset( $changes['location'] ) ? sanitize_text_field( $changes['location'] ) : $master['location'],
			'status'        => 'confirmed',
			'last_modified' => current_time( 'mysql', true ),
			'updated_by'    => (int) $actor_id,
			'updated_at'    => current_time( 'mysql', true ),
		);
		if ( class_exists( 'ZSCH_Duration' ) ) {
			$fields['duration'] = ZSCH_Duration::between_exact( $start, $end );
		}

		/*
		 * ────────────────────────────────────────────────────────────────── */
		$lock = self::series_lock( $master );
		$got  = self::acquire( $lock );
		if ( is_wp_error( $got ) ) { return $got; }

		try {
			/* RE-READ inside the lock. The snapshot above was taken before we
			 * had exclusion and may already be stale — which is the entire
			 * reason for locking rather than just serialising. */
			$cur = self::read_raw( (int) $master['id'] ) ?: $master;

			/* ALIVE OR BURIED. find_override() filters `deleted_at IS NULL`,
			 * which is right for "what is on the calendar" and wrong for "what
			 * is holding this override_key" — the unique index holds a
			 * soft-deleted row's key exactly as firmly as a live one. Asking the
			 * narrow question here is what made a deleted occurrence impossible
			 * to schedule again: a permanent 500 on every retry, forever. */
			$existing = self::find_override_any( (string) $master['uid'], $recurrence_id );

			if ( $existing ) {
				/* REVIVED, not replaced. One occurrence is one row: its id, its
				 * change history and its participants all survive, and the
				 * unique key never has two rows to choose between. */
				$revive = $fields;
				if ( null !== ( $existing['deleted_at'] ?? null ) ) {
					$revive['deleted_at'] = null;
				}
				$wpdb->update( self::table(), $revive, array( 'id' => (int) $existing['id'] ) ); // phpcs:ignore
				self::unsuppress( (array) $cur, $recurrence_id, $actor_id );
				self::log_change( $existing, $fields, $actor_id, 'move-instance' );
				return self::envelope( self::read( (int) $existing['id'] ) );
			}

			/* A NEW row, with its own uid. The RFC shape is same-UID-plus-
			 * back at the sync boundary. */
			$row = array_merge( $fields, array(
				'owner_user_id'  => (int) $master['owner_user_id'],
				'calendar_scope' => $master['calendar_scope'],
				'is_all_day'     => (int) $master['is_all_day'],
				'time_zone'      => $tz,
				'busy_status'    => $master['busy_status'],
				'color'          => $master['color'],
				'job_id'         => $master['job_id'],
				'uid'            => class_exists( 'ZSCH_Identity' ) ? ZSCH_Identity::mint_uid( ZSCH_Identity::uid_host() ) : null,
				'sequence'       => 0,
				'rrule'          => '',           // an override is not itself a series
				'series_uid'     => (string) $master['uid'],
				'recurrence_id'  => $recurrence_id,
				/* the RFC's (UID, RECURRENCE-ID) in a form an index can
				 * hold. The SELECT above is now an optimisation; THIS is what
				 * makes two people dragging the same Tuesday impossible to get
				 * wrong. */
				'override_key'   => ZSCH_Series::override_key( (string) $master['uid'], $recurrence_id ),
				'created_by'     => (int) $actor_id,
				'created_at'     => current_time( 'mysql', true ),
			) );
			$ok = $wpdb->insert( self::table(), $row ); // phpcs:ignore
			if ( ! $ok ) {
				/* The unique key did its job. With the series lock held this is
				 * no longer reachable by a concurrent writer of this series, so
				 * what remains is a row the lock does not cover — most of all a
				 * SOFT-DELETED override, whose key the index holds exactly as
				 * firmly as a live one. find_override_ANY, therefore: the narrow
				 * version filtered out the very row blocking the insert, which
				 * is what made this a permanent 500 rather than a recovery. */
				$raced = self::find_override_any( (string) $master['uid'], $recurrence_id );
				if ( $raced ) {
					$revive = $fields;
					if ( null !== ( $raced['deleted_at'] ?? null ) ) {
						$revive['deleted_at'] = null;
					}
					$wpdb->update( self::table(), $revive, array( 'id' => (int) $raced['id'] ) ); // phpcs:ignore
					self::unsuppress( (array) $cur, $recurrence_id, $actor_id );
					self::log_change( $raced, $fields, $actor_id, 'move-instance' );
					return self::envelope( self::read( (int) $raced['id'] ) );
				}
				return new WP_Error( 'zsch_override_failed', 'Could not save that change.', array( 'status' => 500 ) );
			}
			$new_id = (int) $wpdb->insert_id;

			/* THE CREW COMES TOO. Participants live in their own table, so a row
			 * that copies every column of the master still copies nobody — and
			 * everybody assigned to that occurrence would show as free while
			 * working it. Copied AFTER the insert because they hang off the new
			 * row's id. */
			if ( class_exists( 'ZSCH_Participants' ) ) {
				$crew = ZSCH_Participants::user_ids_for( (int) $master['id'] );
				if ( ! empty( $crew ) ) {
					ZSCH_Participants::add( (int) $actor_id, $new_id, $crew );
				}
			}

			/* This occurrence exists again, so any EXDATE naming it must go —
			 * otherwise the row is live here and cancelled on the wire. */
			self::unsuppress( (array) $cur, $recurrence_id, $actor_id );

			self::log_change( array( 'id' => $new_id, 'uid' => $row['uid'] ), $fields, $actor_id, 'move-instance' );
			return self::envelope( self::read( $new_id ) );
		} finally {
			self::release( $lock );
		}
	}

	/**
	 * Cancel ONE occurrence.
	 *
	 * Writes the EXDATE (what goes on the wire, and what actually suppresses the
	 * instance) AND marks any override row cancelled (what keeps the actor and the
	 * reason in the change log). Either alone would work; only both together
	 * answer "who cancelled this, and when".
	 *
	 * @param int    $actor_id
	 * @param int    $master_id
	 * @param string $recurrence_id
	 * @return array|WP_Error
	 */
	public static function cancel_instance( $actor_id, $master_id, $recurrence_id ) {
		global $wpdb;
		$master = self::load_master( $master_id, $actor_id );
		if ( is_wp_error( $master ) ) { return $master; }

		$recurrence_id = trim( (string) $recurrence_id );
		if ( ! self::is_instance_of( $master, $recurrence_id ) ) {
			return new WP_Error( 'zsch_not_an_instance',
				'That is not one of this appointment\'s occurrences.', array( 'status' => 400 ) );
		}

		/* SINGLE-FLIGHT, because this is a read-modify-write.
		 */
		/* the name comes from series_lock() and the wait from
		 * acquire(), because five other writers now take this same lock and a
		 * second spelling of the name would be a second lock. md5('') for a
		 * uid-less row — one global lock shared across the server — is handled
		 * there too. */
		$lock = self::series_lock( $master );
		$got  = self::acquire( $lock );
		if ( is_wp_error( $got ) ) { return $got; }
		try {
			/* read_raw(), NOT read().
			 * processes on the first attempt. */
			$fresh  = self::read_raw( (int) $master['id'] );
			$cur    = is_array( $fresh ) ? $fresh : $master;
			$exdate = ZSCH_Series::add_to_list( $cur['exdate'] ?? '', $recurrence_id );
			$before = array( 'exdate' => (string) ( $cur['exdate'] ?? '' ) );
			$after  = array( 'exdate' => $exdate );

			/*
			 * this bumps SEQUENCE — without it Google and Outlook drop the update as
			 * a stale duplicate and the occurrence stays on everybody's calendar. */
			$seq_before = (int) ( $cur['sequence'] ?? $master['sequence'] );
			$seq        = $seq_before + 1;

			$wpdb->update( self::table(), array( // phpcs:ignore
				'exdate'        => $exdate,
				'sequence'      => $seq,
				'last_modified' => current_time( 'mysql', true ),
				'updated_by'    => (int) $actor_id,
				'updated_at'    => current_time( 'mysql', true ),
			), array( 'id' => (int) $master['id'] ) );
		} finally {
			self::release( $lock );
		}

		$existing = self::find_override( (string) $master['uid'], $recurrence_id );
		if ( $existing ) {
			$wpdb->update( self::table(), array( 'status' => 'cancelled', 'updated_by' => (int) $actor_id ), array( 'id' => (int) $existing['id'] ) ); // phpcs:ignore
		}

		if ( class_exists( 'ZSCH_Identity' ) ) {
			ZSCH_Identity::log( (int) $master['id'], (string) $master['uid'],
				ZSCH_Identity::diff( $before, $after, array( 'exdate' ) ),
				array( 'actor_id' => (int) $actor_id, 'actor_type' => 'user', 'source' => 'ui',
					'sequence_before' => $seq_before, 'sequence_after' => $seq ) );
		}
		return self::envelope( array( 'cancelled' => $recurrence_id, 'sequence' => $seq ) );
	}

	/**
	 * "This and all following" — a SPLIT, not a RANGE.
	 *
	 * The original is truncated with an inclusive UTC UNTIL at its last kept
	 * instance; a NEW row with a NEW uid carries the remainder and points back via
	 * `related_to`. RANGE=THISANDFUTURE is correct per the RFC and quietly ignored
	 * by enough clients that the split is what every mature product actually does.
	 *
	 * @param int    $actor_id
	 * @param int    $master_id
	 * @param string $recurrence_id the first occurrence that belongs to the NEW series.
	 * @param array  $changes       applied to the new series.
	 * @return array|WP_Error
	 */
	public static function split_following( $actor_id, $master_id, $recurrence_id, array $changes = array() ) {
		global $wpdb;
		$master = self::load_master( $master_id, $actor_id );
		if ( is_wp_error( $master ) ) { return $master; }

		$tz    = (string) $master['time_zone'];
		$local = ZSCH_Series::utc_to_local( (string) $master['start_utc'], $tz );
		$new_start_local = isset( $changes['start_local'] ) && '' !== $changes['start_local']
			? (string) $changes['start_local']
			: '';

		$sp = ZSCH_Recurrence::split( $local, $tz, (string) $master['rrule'], (string) $recurrence_id, $new_start_local );
		if ( empty( $sp['ok'] ) ) {
			return new WP_Error( 'zsch_split_failed', $sp['error'], array( 'status' => 400, 'code' => $sp['code'] ) );
		}

		/* UNDER THE SERIES LOCK. This rewrites the master's rrule,
		 * cancellation of the same series could each lose the other's work. */
		$lock = self::series_lock( $master );
		$got  = self::acquire( $lock );
		if ( is_wp_error( $got ) ) { return $got; }
		try {
		/* Re-read under the lock: the snapshot above may already be stale. */
		$fresh  = self::read_raw( (int) $master['id'] );
		$master = is_array( $fresh ) ? $fresh : $master;

		/* ── ORDER MATTERS ─────────────────────────────────────────────────
		 * CREATE the replacement, and only once it exists TRUNCATE the original.
		 * If anything stops in between, the intermediate state is a visible
		 * duplicate, which somebody can see and fix — never silent data loss.
		 * (The whole split also runs in one transaction where the engine
		 * supports it; the order is what still holds where it does not.)
		 * ──────────────────────────────────────────────────────────────── */

		// ── the new series ───────────────────────────────────────────────
		$ns_local = $sp['new']['dtstart_local'];

		/* Split the stored lists at the CUT, not at the new series' DTSTART.
		 * one line further down. */
		$split_ex = ZSCH_Series::split_list_at( $master['exdate'] ?? '', (string) $recurrence_id );
		$split_rd = ZSCH_Series::split_list_at( $master['rdate'] ?? '', (string) $recurrence_id );
		$ns_utc   = ZSCH_Series::local_to_utc( $ns_local, $tz );
		/*
		 * that 25-hour "day" as its template. */
		$ne_utc   = ZSCH_Series::instance_end_utc( $master, $ns_utc );

		/*
		 * The overrides and EXDATEs past the cut are keyed in the ORIGINAL
		 * series' frame. When the caller also changed the time, the new series
		 * generates every occurrence at a different recurrence_id, so a carried
		 * override matched nothing (the occurrence showed twice) and a carried
		 * EXDATE cancelled nothing (the cancelled job came back). Planned HERE,
		 * before anything is written, so a date that cannot be checked refuses
		 * the whole split rather than half-doing it. */
		$carry_rows = self::override_rows( (string) $master['uid'], (string) $recurrence_id, true );
		$rk_plan    = self::plan_rekey( $carry_rows, $split_ex['after'], array(
			'dtstart_local' => $ns_local,
			'tz'            => $tz,
			'rule'          => $sp['new']['rule'],
			'rdate'         => $split_rd['after'],
		), $master, array(
			'start_utc'  => $ns_utc,
			'end_utc'    => $ne_utc,
			'time_zone'  => $tz,
			'is_all_day' => (int) $master['is_all_day'],
		) );
		if ( is_wp_error( $rk_plan ) ) {
			return $rk_plan;
		}

		$row = array(
			'owner_user_id'  => (int) $master['owner_user_id'],
			'calendar_scope' => $master['calendar_scope'],
			'title'          => isset( $changes['title'] ) ? sanitize_text_field( $changes['title'] ) : $master['title'],
			'body'           => isset( $changes['body'] ) ? sanitize_textarea_field( $changes['body'] ) : $master['body'],
			'location'       => isset( $changes['location'] ) ? sanitize_text_field( $changes['location'] ) : $master['location'],
			'start_utc'      => $ns_utc,
			'end_utc'        => $ne_utc,
			'is_all_day'     => (int) $master['is_all_day'],
			'time_zone'      => $tz,
			'status'         => 'confirmed',
			'busy_status'    => $master['busy_status'],
			'color'          => $master['color'],
			'job_id'         => $master['job_id'],
			/* A NEW uid is not optional. Without it the two halves are the same
			 * event to every downstream system and the split is invisible. */
			'uid'            => class_exists( 'ZSCH_Identity' ) ? ZSCH_Identity::mint_uid( ZSCH_Identity::uid_host() ) : null,
			'sequence'       => 0,
			'rrule'          => $sp['new']['rule'],
			/* THE CANCELLATIONS COME TOO.
			 * PARTITIONED, not copied: entries before the cut belong to the
			 * original, and handing the new series EXDATEs for occurrences its
			 * rule cannot generate would push cancellations of events that do
			 * not exist. */
			'exdate'         => '' !== $rk_plan['exdate'] ? $rk_plan['exdate'] : null,
			'rdate'          => '' !== $split_rd['after'] ? $split_rd['after'] : null,
			'recurrence_id'  => '',
			'series_uid'     => null,
			'related_to'     => (string) $master['uid'],
			'duration'       => class_exists( 'ZSCH_Duration' ) ? ZSCH_Duration::between_exact( $ns_utc, $ne_utc ) : '',
			'created_by'     => (int) $actor_id,
			'updated_by'     => (int) $actor_id,
			'created_at'     => current_time( 'mysql', true ),
			'updated_at'     => current_time( 'mysql', true ),
			'last_modified'  => current_time( 'mysql', true ),
		);
		$row['series_until_utc'] = ZSCH_Series::compute_until( $ns_local, $tz, $sp['new']['rule'] );

		/*
		 * last re-key. The ordering below (create, then truncate) is kept for a
		 * non-transactional engine; on InnoDB a refused carry or re-key now
		 * undoes the whole split instead of stranding a row with no override
		 * key beside a visible duplicate. */
		$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore
		/*
		 * answers, instead of leaving the transaction open behind a released
		 * lock. Body not re-indented. */
		try {
		if ( ! $wpdb->insert( self::table(), $row ) ) { // phpcs:ignore
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore
			return new WP_Error( 'zsch_split_insert_failed', 'Could not create the new series.', array( 'status' => 500 ) );
		}
		$new_id  = (int) $wpdb->insert_id;
		$new_uid = (string) $row['uid'];

		// ── now, and only now, truncate the original ─────────────────────
		$orig_until = ZSCH_Series::compute_until( $local, $tz, $sp['original']['rule'] );
		$seq        = (int) $master['sequence'] + 1;
		$trunc_n = $wpdb->update( self::table(), array( // phpcs:ignore
			'rrule'            => $sp['original']['rule'],
			'series_until_utc' => $orig_until,
			'sequence'         => $seq,
			/* The other half of the partition. Left alone, the original would
			 * keep EXDATEs naming dates its truncated rule no longer generates —
			 * harmless on this calendar, and pushed to Google as cancellations of
			 * occurrences that do not exist. */
			'exdate'           => '' !== $split_ex['before'] ? $split_ex['before'] : null,
			'rdate'            => '' !== $split_rd['before'] ? $split_rd['before'] : null,
			'last_modified'    => current_time( 'mysql', true ),
			'updated_by'       => (int) $actor_id,
			'updated_at'       => current_time( 'mysql', true ),
		), array( 'id' => (int) $master['id'] ) );

		/*
		 * untouched original, every date after the cut drawn twice, success:true. */
		if ( false === $trunc_n || (int) $trunc_n < 1 ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore
			$undone = ! self::read_raw( $new_id );
			return new WP_Error( $undone ? 'zsch_split_rolled_back' : 'zsch_split_partial',
				$undone
					? 'The series was NOT split: the original series could not be shortened, so nothing was saved. Try again in a moment.'
					: 'The series could not be shortened, but the new series may already exist and every occurrence after the split may appear twice. Reload the calendar and check.',
				array( 'status' => 500, 'rolled_back' => $undone, 'truncate_refused' => true ) );
		}

		/* the overrides go with the half they belong to.
		 * An occurrence somebody had already moved is a row carrying the ORIGINAL
		 * series' uid and its own recurrence_id. After the split, the ones at or
		 * after the split point belong to a series that no longer generates them:
		 * they stopped being overrides and became free-standing appointments,
		 * still on the calendar, attached to nothing. Re-pointing them is what
		 * keeps "this and all following" from leaving ghosts behind. */
		/* The overrides are carried from the CUT (a recurrence_id, i.e. an
		 * ORIGINAL start), never from the possibly-MOVED new start: comparing a
		 * new start against original starts would leave an override on the cut
		 * day behind on the truncated series, beside the new series' own
		 * occurrence. */
		$moved_ids = array();
		$carried   = self::carry_overrides( (string) $master['uid'], $new_uid, (string) $recurrence_id, $moved_ids );
		/*
		 * their original attribution (no backfill). */
		$rk        = self::apply_rekey_plan( $rk_plan, $new_uid, $new_uid, $actor_id, (int) $master['id'] );
		$not_moved = array_values( array_diff( array_map( 'intval', array_column( $carry_rows, 'id' ) ), $moved_ids ) );
		$missed    = count( $not_moved );
		/* Distinct occurrences, not a sum of two tallies: one refused row used
		 * to be counted once as "not moved" and again as "re-key failed". */
		$affected  = count( array_unique( array_merge( $not_moved, array_map( 'intval', $rk['failed_ids'] ) ) ) );

		if ( class_exists( 'ZSCH_Identity' ) ) {
			ZSCH_Identity::log( (int) $master['id'], (string) $master['uid'],
				array( 'rrule' => array( (string) $master['rrule'], $sp['original']['rule'] ) ),
				array( 'actor_id' => (int) $actor_id, 'actor_type' => 'user', 'source' => 'ui',
					'sequence_before' => (int) $master['sequence'], 'sequence_after' => $seq ) );
		}

		$payload = array(
			'original' => array( 'id' => (int) $master['id'], 'rule' => $sp['original']['rule'], 'kept' => $sp['original']['kept'] ),
			'new'      => array( 'id' => $new_id, 'rule' => $sp['new']['rule'], 'carried' => $sp['new']['carried'] ),
			'overrides_moved'    => $carried,
			'overrides_rekeyed'  => $rk['overrides_rekeyed'],
			'overrides_detached' => $rk['overrides_detached'],
			'overrides_retired'  => $rk['overrides_retired'],
			'overrides_retimed'  => $rk['overrides_retimed'],
			'exdates_rekeyed'    => $rk_plan['exdates_rekeyed'],
			'exdates_dropped'    => $rk_plan['exdates_dropped'],
		);

		/* HONEST OUTPUT FOR A PARTIAL SPLIT. The split itself has
		 * the facts, so the editor shows it and a person can look. */
		if ( $affected > 0 ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore
			$still  = self::read_raw( (int) $master['id'] );
			$undone = $still && (string) $still['rrule'] === (string) $master['rrule'] && ! self::read_raw( $new_id );
			if ( $undone ) {
				$payload['overrides_moved']    = 0;
				$payload['overrides_rekeyed']  = 0;
				$payload['overrides_detached'] = 0;
				$payload['overrides_retired']  = 0;
				$payload['overrides_retimed']  = 0;
				$payload['new']['id']          = 0;
			}
			return new WP_Error( $undone ? 'zsch_split_rolled_back' : 'zsch_split_partial',
				$undone
					? sprintf( 'The series was NOT split: %d changed occurrence(s) could not be moved onto the new series, so nothing was saved. Try again; if it keeps happening, change those occurrences one at a time.', $affected )
					: sprintf( 'The series was split, but %d changed occurrence(s) could not be moved onto the new series and may now appear twice. Reload the calendar and check the dates after the split.', $affected ),
				array_merge( array( 'status' => 500, 'rolled_back' => $undone, 'occurrences_affected' => $affected,
					'overrides_not_moved' => $missed, 'rekey_failed' => (int) $rk['failed'] ), $payload ) );
		}
		$wpdb->query( 'COMMIT' ); // phpcs:ignore
		} catch ( \Throwable $e ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore
			error_log( 'Zorderz Scheduler split_following(): rolled back after an exception: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
			$still  = self::read_raw( (int) $master['id'] );
			$undone = $still && (string) $still['rrule'] === (string) $master['rrule']
				&& ( empty( $new_id ) || ! self::read_raw( (int) $new_id ) );
			return new WP_Error( $undone ? 'zsch_split_rolled_back' : 'zsch_split_partial',
				$undone
					? 'The series was NOT split: something went wrong while saving it, and everything was undone. Try again.'
					: 'Something went wrong while splitting the series, and it may be partly changed. Reload the calendar and check it.',
				array( 'status' => 500, 'rolled_back' => $undone ) );
		}
		return self::envelope( $payload );
		} finally {
			self::release( $lock );
		}
	}

	/**
	 * "Delete this and all following" — truncate the series, create nothing.
	 *
	 * This is `split_following` without the second half, and it exists because a
	 * delete offering only "just this one" and "every one" makes people choose
	 * "every one" and lose a year of work. Reuses the same engine split so the
	 * truncated rule is computed one way, not two.
	 *
	 * @param int    $actor_id
	 * @param int    $master_id
	 * @param string $recurrence_id  first occurrence to remove.
	 * @return array|WP_Error
	 */
	public static function truncate_following( $actor_id, $master_id, $recurrence_id ) {
		global $wpdb;
		$master = self::load_master( $master_id, $actor_id );
		if ( is_wp_error( $master ) ) { return $master; }

		$tz            = (string) $master['time_zone'];
		$local         = ZSCH_Series::utc_to_local( (string) $master['start_utc'], $tz );
		$recurrence_id = trim( (string) $recurrence_id );

		$sp = ZSCH_Recurrence::split( $local, $tz, (string) $master['rrule'], $recurrence_id );

		/*
		 * occurrence deleted nothing and answered with an error about SPLITTING.
		 * The `kept === 0` branch below was written for exactly this and was
		 * unreachable, because split() refuses `split_at_start` before it can
		 * report kept = 0. The user's intent is unambiguous — every occurrence
		 * from the first one on, which is the whole series — so it goes through
		 * the ordinary delete path, the one "delete all" already uses: same lock,
		 * same cascade to overrides, same Graph removal, same hook.
		 *
		 * Only when the cut IS the first occurrence (DTSTART). A recurrence_id
		 * before it is not an occurrence at all, and deleting a series because
		 * somebody sent a date it never had would be a destructive guess. */
		if ( empty( $sp['ok'] ) && 'split_at_start' === (string) ( $sp['code'] ?? '' ) && $recurrence_id === $local ) {
			$whole = self::delete_whole_series( $actor_id, (int) $master['id'] );
			if ( is_wp_error( $whole ) ) { return $whole; }
			return self::envelope( $whole );
		}
		if ( empty( $sp['ok'] ) ) {
			return new WP_Error( 'zsch_split_failed', $sp['error'], array( 'status' => 400, 'code' => $sp['code'] ) );
		}

		/* UNDER THE SERIES LOCK, for the same reason as the split. */
		$lock = self::series_lock( $master );
		$got  = self::acquire( $lock );
		if ( is_wp_error( $got ) ) { return $got; }
		try {
		$fresh  = self::read_raw( (int) $master['id'] );
		$master = is_array( $fresh ) ? $fresh : $master;

		/* Cutting at the FIRST occurrence removes the series entirely. Say so by
		 * deleting it, rather than storing a rule that generates nothing — a row
		 * whose rule matches no date is the kind of state that reads as a bug
		 * forever afterwards. */
		if ( 0 === (int) $sp['original']['kept'] ) {
			return ZSCH_Appointments::delete( $actor_id, (int) $master['id'] );
		}

		/*
		 * unsuppresses), so it counts as visible and keeps the series. */
		$keep = ZSCH_Recurrence::expand_all( $local, $tz, $sp['original']['rule'] );
		if ( ! empty( $keep['ok'] ) && ! empty( $keep['complete'] ) ) {
			$ex      = array_flip( ZSCH_Series::split_list( $master['exdate'] ?? '' ) );
			$visible = 0;
			foreach ( (array) $keep['instances'] as $i ) {
				if ( ! isset( $ex[ $i['recurrence_id'] ] ) ) { $visible++; }
			}
			$rd = ZSCH_Series::split_list_at( $master['rdate'] ?? '', $recurrence_id );
			foreach ( ZSCH_Series::split_list( $rd['before'] ) as $v ) {
				if ( ! isset( $ex[ $v ] ) ) { $visible++; }
			}
			if ( 0 === $visible ) {
				$whole = self::delete_whole_series( $actor_id, (int) $master['id'], array(
					'cancelled_before_cut' => (int) $sp['original']['kept'],
				) );
				if ( is_wp_error( $whole ) ) { return $whole; }
				return self::envelope( $whole );
			}
		}

		$until = ZSCH_Series::compute_until( $local, $tz, $sp['original']['rule'] );
		$seq   = (int) $master['sequence'] + 1;
		$wpdb->update( self::table(), array( // phpcs:ignore
			'rrule'            => $sp['original']['rule'],
			'series_until_utc' => $until,
			'sequence'         => $seq,
			'last_modified'    => current_time( 'mysql', true ),
			'updated_by'       => (int) $actor_id,
			'updated_at'       => current_time( 'mysql', true ),
		), array( 'id' => (int) $master['id'] ) );

		$gone = $wpdb->query( $wpdb->prepare( // phpcs:ignore
			'UPDATE ' . self::table() . ' SET deleted_at = %s' // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- self::table() is $wpdb->prefix plus a fixed name; values are placeholders.
			. ' WHERE series_uid = %s AND recurrence_id >= %s AND deleted_at IS NULL',
			current_time( 'mysql', true ), (string) $master['uid'], $recurrence_id
		) );

		if ( class_exists( 'ZSCH_Identity' ) ) {
			ZSCH_Identity::log( (int) $master['id'], (string) $master['uid'],
				array( 'rrule' => array( (string) $master['rrule'], $sp['original']['rule'] ) ),
				array( 'actor_id' => (int) $actor_id, 'actor_type' => 'user', 'source' => 'ui',
					'sequence_before' => (int) $master['sequence'], 'sequence_after' => $seq ) );
		}

		/*
		 * the cut on; a visit that a "for all" edit split off this series and
		 * that now sits at or after the cut is NOT removed (it has no
		 * series_uid, so the UPDATE above cannot reach it). The person asked for
		 * everything from here on to go, so they are told what stayed, by the
		 * same rule and in the same words as a whole-series delete. Visits split
		 * off BEFORE the cut are not mentioned: nothing about them was asked. */
		$cut_utc    = ZSCH_Series::local_to_utc( $recurrence_id, $tz );
		$kept_split = ( '' !== (string) $cut_utc && method_exists( 'ZSCH_Appointments', 'split_off_kept' ) )
			? ZSCH_Appointments::split_off_kept( (int) $actor_id, (string) $master['uid'], $cut_utc )
			: 0;   // no cut instant: say nothing rather than count the whole series
		$tr = array(
			'id'                => (int) $master['id'],
			'rule'              => $sp['original']['rule'],
			'kept'              => $sp['original']['kept'],
			'overrides_removed' => (int) $gone,
			'kept_split_off'    => (int) $kept_split,
		);
		if ( $kept_split > 0 ) {
			$tr['notice'] = ZSCH_Appointments::split_off_notice( $kept_split );
		}
		return self::envelope( $tr );
		} finally {
			self::release( $lock );
		}
	}

	/**
	 * "Delete this and all following" that turns out to mean the whole series.
	 *
	 * Through ZSCH_Appointments::delete(), the path "delete all" uses — same
	 * lock (re-entrant when the caller already holds it), same cascade, same
	 * Graph removal, same hook — and answered in this class's envelope.
	 */
	private static function delete_whole_series( $actor_id, $master_id, array $extra = array() ) {
		$del = ZSCH_Appointments::delete( $actor_id, (int) $master_id );
		if ( empty( $del['success'] ) ) {
			$code = (string) ( $del['code'] ?? 'zsch_delete_failed' );
			return new WP_Error( $code, (string) ( $del['error'] ?? 'Could not delete the series.' ),
				array( 'status' => (int) ( $del['status'] ?? ( 'zsch_series_busy' === $code ? 409 : 400 ) ) ) );
		}
		$out = array(
			'id'                => (int) $master_id,
			'rule'              => '',
			'kept'              => 0,
			'deleted_series'    => true,
			'overrides_removed' => null,   // cascaded by delete(), which does not count them
			/*
			 * this answer is rebuilt from scratch, so carry the count across. */
			'kept_split_off'    => (int) ( $del['kept_split_off'] ?? 0 ),
		);
		if ( ! empty( $del['notice'] ) ) {
			$out['notice'] = (string) $del['notice'];
		}
		return self::envelope( array_merge( $out, $extra ) );
	}

	/**
	 * Move the overrides at or after $from_local onto the new series.
	 *
	 * Their RECURRENCE-ID does not change — it never can, it is the occurrence's
	 * original start forever — only which series it is an occurrence
	 * OF. The key is recomputed because it is derived from the pair.
	 *
	 * Deliberately NOT done in one UPDATE: override_key has to be computed per
	 * row, and computing it in SQL would be a second implementation of
	 * ZSCH_Series::override_key() that could drift from the first.
	 *
	 * @param string $old_uid
	 * @param string $new_uid
	 * @param string $from_local  the new series' DTSTART, local wall clock.
	 * @return int how many rows moved.
	 */
	private static function carry_overrides( $old_uid, $new_uid, $from_local, &$moved_ids = array() ) {
		global $wpdb;
		if ( '' === trim( (string) $old_uid ) || '' === trim( (string) $new_uid ) ) {
			return 0;
		}
		$rows = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore
			'SELECT id, recurrence_id FROM ' . self::table() // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- self::table() is $wpdb->prefix plus a fixed name; values are placeholders.
			. ' WHERE series_uid = %s AND recurrence_id >= %s AND deleted_at IS NULL',
			$old_uid, $from_local
		), ARRAY_A );
		$moved = 0;
		foreach ( (array) $rows as $r ) {
			/*
			 * discarded and `$moved++` was unconditional, so a refused UPDATE was
			 * reported to the client as carried — and an override left behind on
			 * the truncated series is exactly the duplicate this function exists
			 * to prevent. `false` is a refusal; 0 rows is "nothing matched". */
			$n = $wpdb->update( self::table(), array( // phpcs:ignore
				'series_uid'   => $new_uid,
				'override_key' => ZSCH_Series::override_key( $new_uid, (string) $r['recurrence_id'] ),
				'updated_at'   => current_time( 'mysql', true ),
			), array( 'id' => (int) $r['id'] ) );
			if ( false !== $n && (int) $n > 0 ) {
				$moved++;
				$moved_ids[] = (int) $r['id'];
			}
		}
		return $moved;
	}


	/**
	 * The override rows of a series, alive AND buried, for a re-key.
	 *
	 * Buried (soft-deleted) rows are included on purpose: UNIQUE
	 * idx_zsch_override holds a buried row's key exactly as firmly as a live
	 * one, so a re-key that ignored them could collide with one.
	 */
	public static function override_rows( $series_uid, $from_rid = '', $live_only = false ) {
		global $wpdb;
		$series_uid = trim( (string) $series_uid );
		if ( '' === $series_uid ) {
			return array();
		}
		$sql = 'SELECT id, uid, recurrence_id, status, deleted_at, series_uid, start_utc, end_utc FROM ' . self::table()
			. ' WHERE series_uid = %s AND recurrence_id <> \'\'';
		$args = array( $series_uid );
		if ( '' !== (string) $from_rid ) {
			$sql   .= ' AND recurrence_id >= %s';
			$args[] = (string) $from_rid;
		}
		if ( $live_only ) {
			$sql .= ' AND deleted_at IS NULL';
		}
		$sql .= ' ORDER BY id ASC';
		return (array) $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A ); // phpcs:ignore
	}

	/**
	 * The generated occurrences of a series definition on one LOCAL date.
	 *
	 * PURE apart from the engine. Answers "which recurrence_ids does this rule
	 * produce on $date?" — which is the whole of what a re-key needs to know,
	 * and cheap to ask one date at a time because the engine is told where the
	 * caller's interest begins.
	 */
	public static function instances_on_date( array $def, $date ) {
		$rule = trim( (string) ( $def['rule'] ?? '' ) );
		if ( '' === $rule ) {
			return array( 'rule' => array(), 'all' => array() );   // the row no longer repeats
		}
		$dt0 = (string) ( $def['dtstart_local'] ?? '' );
		if ( '' === $dt0 ) {
			return new WP_Error( 'zsch_series_edit_unmappable', 'The series start could not be read, so the edit was not applied.', array( 'status' => 400 ) );
		}
		/* A recurrence_id that is not even a date was never matched by anything:
		 * it is already a ghost. "Not an instance" is the true answer, and
		 * refusing instead would make every future "for all" edit of this series
		  */
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $date ) ) {
			return array( 'rule' => array(), 'all' => array() );
		}
		/* REACH. The engine scans at most MAX_SCAN dates from DTSTART — about 54
		 * years at the densest cadence this app accepts. Past that an absent date
		 * would be "not reached", not "not an instance", and treating the two the
		 * same is how a re-key would detach an override the rule still generates,
		 * leaving a duplicate. Refuse instead; nothing real is that far out. */
		if ( (int) substr( $date, 0, 4 ) - (int) substr( $dt0, 0, 4 ) > 50 ) {
			return new WP_Error( 'zsch_series_edit_unmappable',
				'One of the changed occurrences is too far from the start of the series to check safely, so the edit was not applied.',
				array( 'status' => 400 ) );
		}
		/*
		 * (rule + RDATE) are reported separately. A re-key may only land on an
		 * occurrence the RULE generates: an RDATE is an explicit extra
		 * occurrence somebody added, not the one an override was made for, and
		 * treating "rule + RDATE on one date" as "no instance" is how a
		 * cancelled occurrence came back and a moved one showed twice. */
		$out = array( 'rule' => array(), 'all' => array() );
		foreach ( array( 'rule' => array(), 'all' => ZSCH_Series::split_list( $def['rdate'] ?? '' ) ) as $k => $rdates ) {
			$r = ZSCH_Recurrence::expand( $dt0, (string) $def['tz'], $rule, array(
				'from_local'    => $date . ' 00:00:00',
				'horizon_local' => $date . ' 23:59:59',
				'rdate_local'   => $rdates,
				'limit'         => ZSCH_Recurrence::MAX_INSTANCES,
			) );
			if ( empty( $r['ok'] ) ) {
				return new WP_Error( 'zsch_series_edit_unmappable',
					'The new repeat rule could not be expanded (' . (string) ( $r['error'] ?? 'unknown' ) . '), so the edit was not applied.',
					array( 'status' => 400 ) );
			}
			foreach ( (array) $r['instances'] as $i ) {
				if ( 0 === strpos( (string) $i['recurrence_id'], $date . ' ' ) ) {
					$out[ $k ][] = (string) $i['recurrence_id'];
				}
			}
			$out[ $k ] = array_values( array_unique( $out[ $k ] ) );
		}
		return $out;
	}

	/**
	 * Plan where every override and EXDATE of a series lands under a NEW definition.
	 *
	 * ── THE PROBLEM ──────────────────────────────
	 *
	 * An override's `recurrence_id` is the ORIGINAL local start of the occurrence
	 * it replaces, and the expander matches it by exact string. Edit the series
	 * "for all" — a new time, a new zone, a new BYDAY — and every generated
	 * recurrence_id changes. Left alone, each moved occurrence would show twice
	 * (the regenerated instance AND the orphaned override) and each cancelled one
	 * would come back, because its EXDATE names a time the rule no longer
	 * produces. A split with a time change has the same problem for everything it
	 * carries.
	 *
	 * ── THE PRINCIPLE, AND THE RULE THAT SATISFIES IT ─────────────────────────
	 *
	 * After a series edit no occurrence appears twice, and no moved or cancelled
	 * occurrence silently reverts. So, per override and per EXDATE entry:
	 *
	 * The rule this file and ZSCH_Recurrence both state — never re-key an
	 * override — is about MOVING ONE occurrence: re-keying it to where it was
	 * dragged TO detaches it. This is the other case: the SERIES changed, the
	 * occurrence's original slot no longer exists, and the override is re-keyed to
	 * the slot the series NOW generates for the same occurrence. Leaving it keyed
	 * to a time nothing produces is the detachment, not the re-key.
	 *
	 * Refuses (WP_Error, nothing written) rather than guess whenever a date cannot
	 * be checked, because a wrong "not an instance" leaves a duplicate.
	 */
	public static function plan_rekey( array $rows, $exdate, array $def, $old_master = null, $new_master = null ) {
		$plan = array(
			'rekey'           => array(),   // live id   => new recurrence_id
			'detach'          => array(),   // live moved rows → standalone
			'retire'          => array(),   // live cancelled rows with no occurrence left
			'buried_rekey'    => array(),   // buried id => new recurrence_id
			'buried_clear'    => array(),   // buried rows whose key must be released
			'rows'            => array(),   // id => row, for the log
			'retime'          => array(),   // id => [start_utc, end_utc] for re-keyed rows that were never MOVED
			'exdate'          => (string) $exdate,
			'exdate_changed'  => false,
			'exdates_rekeyed' => 0,
			'exdates_dropped' => 0,
		);
		$cache = array();
		$on    = static function ( $date ) use ( $def, &$cache ) {
			if ( ! array_key_exists( $date, $cache ) ) {
				$cache[ $date ] = self::instances_on_date( $def, $date );
			}
			return $cache[ $date ];
		};
		/* Where one old recurrence_id lands: itself, one new id, or nowhere (''). */
		$map = static function ( $rid ) use ( $on ) {
			$rid  = trim( (string) $rid );
			$date = substr( $rid, 0, 10 );
			$c    = $on( $date );
			if ( is_wp_error( $c ) ) { return $c; }
			if ( in_array( $rid, $c['all'], true ) ) { return $rid; }
			if ( 1 === count( $c['rule'] ) ) { return $c['rule'][0]; }
			if ( count( $c['rule'] ) > 1 ) {
				/* Unreachable with today's grammar (no BYHOUR: a rule yields at
				 * most one occurrence per date), and refused rather than guessed
				 * if that ever changes — a wrong pick is a duplicate. */
				return new WP_Error( 'zsch_series_edit_ambiguous',
					sprintf( 'The new repeat rule has more than one occurrence on %s, so the changed occurrence on that day cannot be matched safely. Nothing was saved.', $date ),
					array( 'status' => 409 ) );
			}
			return '';
		};

		/* Live rows first, and among them the ones whose key does not change, so
		 * a row that is ALREADY an occurrence's override keeps it and a row that
		 * would re-key onto the same slot loses the claim rather than displacing it. */
		$live = array(); $buried = array();
		foreach ( $rows as $r ) {
			if ( null === ( $r['deleted_at'] ?? null ) ) { $live[] = $r; } else { $buried[] = $r; }
		}
		$claimed = array();
		$targets = array();
		foreach ( array_merge( $live, $buried ) as $r ) {
			$t = $map( $r['recurrence_id'] );
			if ( is_wp_error( $t ) ) { return $t; }
			$targets[ (int) $r['id'] ] = $t;
			$plan['rows'][ (int) $r['id'] ] = $r;
		}
		usort( $live, static function ( $a, $b ) use ( $targets ) {
			$sa = ( $targets[ (int) $a['id'] ] === (string) $a['recurrence_id'] ) ? 0 : 1;
			$sb = ( $targets[ (int) $b['id'] ] === (string) $b['recurrence_id'] ) ? 0 : 1;
			return ( $sa <=> $sb ) ?: ( (int) $a['id'] <=> (int) $b['id'] );
		} );
		foreach ( $live as $r ) {
			$id = (int) $r['id'];
			$t  = $targets[ $id ];
			if ( '' !== $t && ! isset( $claimed[ $t ] ) ) {
				$claimed[ $t ] = $id;
				if ( $t !== (string) $r['recurrence_id'] ) {
					$plan['rekey'][ $id ] = $t;
					/*
					 * never MOVED (a title, a note) still sits exactly on its old
					 * generated slot. It follows the series to the new one; only
					 * an occurrence somebody actually moved keeps its own times. */
					$rt = self::unmoved_retime( $r, $t, $old_master, $new_master );
					if ( null !== $rt ) {
						$plan['retime'][ $id ] = $rt;
					}
				}
				continue;
			}
			if ( 'cancelled' === (string) ( $r['status'] ?? '' ) ) {
				$plan['retire'][] = $id;
			} else {
				$plan['detach'][] = $id;
			}
		}
		foreach ( $buried as $r ) {
			$id = (int) $r['id'];
			$t  = $targets[ $id ];
			if ( '' !== $t && ! isset( $claimed[ $t ] ) ) {
				$claimed[ $t ] = $id;
				if ( $t !== (string) $r['recurrence_id'] ) {
					$plan['buried_rekey'][ $id ] = $t;
				}
				continue;
			}
			$plan['buried_clear'][] = $id;
		}

		/* The EXDATE list, mapped by the same rule. */
		$old = ZSCH_Series::split_list( $exdate );
		$new = array();
		foreach ( $old as $e ) {
			$t = $map( $e );
			if ( is_wp_error( $t ) ) { return $t; }
			if ( '' === $t ) {
				$plan['exdates_dropped']++;
				continue;
			}
			if ( $t !== $e ) {
				$plan['exdates_rekeyed']++;
			}
			$new[] = $t;
		}
		$new = array_values( array_unique( $new ) );
		sort( $new );
		$plan['exdate']         = implode( "\n", $new );
		$sorted_old             = $old;
		sort( $sorted_old );
		$plan['exdate_changed'] = ( $new !== $sorted_old );
		return $plan;
	}

	/**
	 * New times for an override that sits exactly on its old generated slot.
	 *
	 * "Never moved" means its stored start is the old occurrence's start AND its
	 * stored end is the old occurrence's derived end. Anything else — a new time,
	 * a longer or shorter visit — was a deliberate move and keeps its times.
	 */
	private static function unmoved_retime( array $row, $new_rid, $old_master, $new_master ) {
		if ( ! is_array( $old_master ) || ! is_array( $new_master ) || ! isset( $row['start_utc'], $row['end_utc'] ) ) {
			return null;
		}
		$old_start = ZSCH_Series::local_to_utc( (string) $row['recurrence_id'], (string) $old_master['time_zone'] );
		if ( '' === $old_start || $old_start !== (string) $row['start_utc'] ) {
			return null;
		}
		if ( ZSCH_Series::instance_end_utc( $old_master, $old_start ) !== (string) $row['end_utc'] ) {
			return null;
		}
		$new_start = ZSCH_Series::local_to_utc( (string) $new_rid, (string) $new_master['time_zone'] );
		if ( '' === $new_start ) {
			return null;
		}
		return array( $new_start, ZSCH_Series::instance_end_utc( $new_master, $new_start ) );
	}

	/** Does this plan write anything to an override row? */
	public static function plan_has_row_work( array $plan ) {
		return ! empty( $plan['rekey'] ) || ! empty( $plan['detach'] ) || ! empty( $plan['retire'] )
			|| ! empty( $plan['buried_rekey'] ) || ! empty( $plan['buried_clear'] ) || ! empty( $plan['retime'] );
	}

	/**
	 * The plan for a "for all" edit of a master, BEFORE it is written.
	 *
	 * Called by ZSCH_Appointments::update() with the series lock held and the
	 * master re-read inside it. Returns null when the edit cannot change any
	 * recurrence_id (a title, a colour, a note), so an ordinary save costs no
	 * expansion at all.
	 */
	public static function plan_series_edit( array $before, array $after ) {
		if ( '' === trim( (string) ( $before['rrule'] ?? '' ) ) || '' === trim( (string) ( $before['uid'] ?? '' ) ) ) {
			return null;   // was not a series: nothing can be keyed to it
		}
		$tz0 = (string) ( $before['time_zone'] ?? 'UTC' );
		$tz1 = (string) ( $after['time_zone'] ?? $tz0 );
		$d0  = ZSCH_Series::utc_to_local( (string) $before['start_utc'], $tz0 );
		$d1  = ZSCH_Series::utc_to_local( (string) $after['start_utc'], $tz1 );
		$same = $d0 === $d1 && $tz0 === $tz1
			&& trim( (string) $before['rrule'] ) === trim( (string) ( $after['rrule'] ?? '' ) )
			&& (string) ( $before['rdate'] ?? '' ) === (string) ( $after['rdate'] ?? '' );
		if ( $same ) {
			/*
			 * occurrences that were edited and never moved take the new length. */
			if ( (string) ( $before['end_utc'] ?? '' ) === (string) ( $after['end_utc'] ?? '' ) ) {
				return null;
			}
			$plan = array(
				'rekey' => array(), 'detach' => array(), 'retire' => array(), 'buried_rekey' => array(),
				'buried_clear' => array(), 'rows' => array(), 'retime' => array(),
				'exdate' => (string) ( $after['exdate'] ?? '' ), 'exdate_changed' => false,
				'exdates_rekeyed' => 0, 'exdates_dropped' => 0,
			);
			$new_master = array_merge( $after, array( 'time_zone' => $tz1 ) );
			foreach ( self::override_rows( (string) $before['uid'], '', true ) as $r ) {
				$rt = self::unmoved_retime( $r, (string) $r['recurrence_id'], $before, $new_master );
				if ( null !== $rt && ( $rt[0] !== (string) $r['start_utc'] || $rt[1] !== (string) $r['end_utc'] ) ) {
					$plan['retime'][ (int) $r['id'] ] = $rt;
					$plan['rows'][ (int) $r['id'] ]   = $r;
				}
			}
			return empty( $plan['retime'] ) ? null : $plan;
		}
		$def = array(
			'dtstart_local' => $d1,
			'tz'            => $tz1,
			'rule'          => (string) ( $after['rrule'] ?? '' ),
			'rdate'         => $after['rdate'] ?? '',
		);
		return self::plan_rekey(
			self::override_rows( (string) $before['uid'] ),
			$after['exdate'] ?? ( $before['exdate'] ?? '' ),
			$def,
			$before,
			array_merge( $after, array( 'time_zone' => $tz1 ) )
		);
	}

	/**
	 * Write a plan's override changes. MUST run under the series lock.
	 *
	 * Two phases, because the unique key is over the DERIVED override_key: moving
	 * row A onto the slot row B is about to vacate would collide with B for the
	 * length of one statement. Every key being changed is released (NULL, which
	 * UNIQUE admits many of) before any new key is written. Both callers run this
	 * inside a transaction, so a failure after phase 1 does not strand a row with
	 * no key.
	 */
	public static function apply_rekey_plan( array $plan, $target_uid, $related_uid, $actor_id, $crew_from_id = 0 ) {
		global $wpdb;
		$t   = self::table();
		$now = current_time( 'mysql', true );
		$out = array( 'overrides_rekeyed' => 0, 'overrides_detached' => 0, 'overrides_retired' => 0,
			'overrides_retimed' => 0, 'buried_rekeyed' => 0, 'failed' => 0, 'failed_ids' => array() );
		$fail = static function ( $id ) use ( &$out ) {
			$out['failed_ids'][ (int) $id ] = true;
			$out['failed'] = count( $out['failed_ids'] );
		};

		$ids = array_merge( array_keys( $plan['rekey'] ), $plan['detach'], $plan['retire'],
			array_keys( $plan['buried_rekey'] ), $plan['buried_clear'] );
		$retime_only = array_diff_key( (array) ( $plan['retime'] ?? array() ), $plan['rekey'] );
		if ( empty( $ids ) && empty( $retime_only ) ) {
			$out['failed_ids'] = array();
			return $out;
		}
		foreach ( $ids as $id ) {
			if ( false === $wpdb->update( $t, array( 'override_key' => null ), array( 'id' => (int) $id ) ) ) { // phpcs:ignore
				$fail( $id );
			}
		}
		if ( $out['failed'] > 0 ) {
			$out['failed_ids'] = array_keys( $out['failed_ids'] );
			return $out;
		}

		$log = static function ( $id, array $changes ) use ( $plan, $actor_id ) {
			if ( ! class_exists( 'ZSCH_Identity' ) || empty( $changes ) ) { return; }
			$row = $plan['rows'][ (int) $id ] ?? array();
			ZSCH_Identity::log( (int) $id, (string) ( $row['uid'] ?? '' ), $changes,
				array( 'actor_id' => (int) $actor_id, 'actor_type' => (int) $actor_id > 0 ? 'user' : 'system', 'source' => 'ui' ) );
		};
		$rekey = static function ( $id, $rid, $counter ) use ( $wpdb, $t, $now, $target_uid, $plan, $log, $fail, &$out ) {
			$fields = array(
				'series_uid'    => (string) $target_uid,
				'recurrence_id' => (string) $rid,
				'override_key'  => ZSCH_Series::override_key( (string) $target_uid, (string) $rid ),
				'updated_at'    => $now,
			);
			$changes = array( 'recurrence_id' => array( (string) ( $plan['rows'][ (int) $id ]['recurrence_id'] ?? '' ), (string) $rid ) );
			if ( isset( $plan['retime'][ (int) $id ] ) ) {
				list( $ns, $ne ) = $plan['retime'][ (int) $id ];
				$fields['start_utc'] = $ns;
				$fields['end_utc']   = $ne;
				if ( class_exists( 'ZSCH_Duration' ) ) {
					$fields['duration'] = ZSCH_Duration::between_exact( $ns, $ne );
				}
				$changes['start_utc'] = array( (string) ( $plan['rows'][ (int) $id ]['start_utc'] ?? '' ), $ns );
				$changes['end_utc']   = array( (string) ( $plan['rows'][ (int) $id ]['end_utc'] ?? '' ), $ne );
			}
			$n = $wpdb->update( $t, $fields, array( 'id' => (int) $id ) ); // phpcs:ignore
			if ( false === $n || (int) $n < 1 ) { $fail( $id ); return; }
			$out[ $counter ]++;
			if ( isset( $plan['retime'][ (int) $id ] ) ) { $out['overrides_retimed']++; }
			$log( $id, $changes );
		};
		foreach ( $plan['rekey'] as $id => $rid ) {
			$rekey( $id, $rid, 'overrides_rekeyed' );
		}
		foreach ( $plan['buried_rekey'] as $id => $rid ) {
			$rekey( $id, $rid, 'buried_rekeyed' );
		}
		/*
		 * no occurrence id moves, but an edited-never-moved occurrence still
		 * follows the new length. Times only; its key is untouched. */
		foreach ( $retime_only as $id => $times ) {
			list( $ns, $ne ) = $times;
			$f = array( 'start_utc' => $ns, 'end_utc' => $ne, 'updated_at' => $now );
			if ( class_exists( 'ZSCH_Duration' ) ) { $f['duration'] = ZSCH_Duration::between_exact( $ns, $ne ); }
			$n = $wpdb->update( $t, $f, array( 'id' => (int) $id ) ); // phpcs:ignore
			if ( false === $n || (int) $n < 1 ) { $fail( $id ); continue; }
			$out['overrides_retimed']++;
			$log( $id, array(
				'start_utc' => array( (string) ( $plan['rows'][ (int) $id ]['start_utc'] ?? '' ), $ns ),
				'end_utc'   => array( (string) ( $plan['rows'][ (int) $id ]['end_utc'] ?? '' ), $ne ),
			) );
		}

		/*
		 * Before detachment attach_overrides() pulled it in through a visible
		 * master, so the master's CURRENT people saw it even if they were added
		 * after the move (the move copies the crew only once). A detached row is
		 * seen through its OWN participants only, so they are merged onto it:
		 * never fewer people than could see it before, and nobody who could not
		 * see the series (only the master's owner and participants are added). */
		$crew = array();
		if ( (int) $crew_from_id > 0 && class_exists( 'ZSCH_Participants' ) && ! empty( $plan['detach'] ) ) {
			$crew = ZSCH_Participants::user_ids_for( (int) $crew_from_id );
			$m    = ZSCH_Appointments::get_raw( (int) $crew_from_id );
			if ( $m ) { $crew[] = (int) $m['owner_user_id']; }
			$crew = array_values( array_unique( array_map( 'intval', $crew ) ) );
		}

		/* DETACHED: the row stays exactly where somebody put it — same id, same
		  */
		foreach ( array( 'detach' => 'overrides_detached', 'retire' => 'overrides_retired' ) as $k => $counter ) {
			foreach ( $plan[ $k ] as $id ) {
				$row    = $plan['rows'][ (int) $id ] ?? array();
				$fields = array(
					'series_uid'    => null,
					'recurrence_id' => '',
					'override_key'  => null,
					'related_to'    => (string) $related_uid,
					'updated_by'    => (int) $actor_id,
					'updated_at'    => $now,
					'last_modified' => $now,
				);
				if ( 'retire' === $k ) {
					$fields['deleted_at'] = $now;   // a cancellation of an occurrence that no longer exists
				}
				$n = $wpdb->update( $t, $fields, array( 'id' => (int) $id ) ); // phpcs:ignore
				if ( false === $n || (int) $n < 1 ) { $fail( $id ); continue; }
				if ( 'detach' === $k && ! empty( $crew ) ) {
					/*
					 * occurrence — including one soft-removed on purpose — is left
					 * as they are. user_ids_for() hides removed rows and add()
					 * REVIVES them, so merging against it put back somebody taken
					 * off this one day, and queued them an invite. The removal is a
					 * recorded decision; a series edit must not overturn it. */
					$have = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( // phpcs:ignore
						'SELECT user_id FROM ' . $wpdb->prefix . 'zsch_participants WHERE appointment_id = %d', (int) $id ) ) );
					$want = array_values( array_diff( $crew, $have ) );
					if ( ! empty( $want ) ) {
						$r = ZSCH_Participants::add( (int) $actor_id, (int) $id, $want );
						if ( empty( $r['success'] ) && empty( $r['ok'] ) ) {
							/* The actor may edit the series but not this row (a row
							 * moved by somebody else); the row's owner always may. */
							$r = ZSCH_Participants::add( (int) ( $m['owner_user_id'] ?? 0 ), (int) $id, $want );
						}
						$now_on = ZSCH_Participants::user_ids_for( (int) $id );
						$owner  = (int) ( ZSCH_Appointments::get_raw( (int) $id )['owner_user_id'] ?? 0 );
						foreach ( $want as $u ) {
							if ( $u !== $owner && ! in_array( $u, $now_on, true ) && ZSCH_Participants::may_be_added( $u ) ) {
								$fail( $id );   // somebody who could see it would lose it
								break;
							}
						}
					}
				}
				$out[ $counter ]++;
				$log( $id, array(
					'series_uid'    => array( (string) ( $row['series_uid'] ?? '' ), '' ),
					'recurrence_id' => array( (string) ( $row['recurrence_id'] ?? '' ), '' ),
					'related_to'    => array( '', (string) $related_uid ),
				) );
			}
		}
		foreach ( $plan['buried_clear'] as $id ) {
			$n = $wpdb->update( $t, array( 'series_uid' => null, 'recurrence_id' => '', 'updated_at' => $now ), array( 'id' => (int) $id ) ); // phpcs:ignore
			if ( false === $n ) { $fail( $id ); }
		}
		$out['failed_ids'] = array_keys( $out['failed_ids'] );
		return $out;
	}

	/* ══ small helpers ═════════════════════════════════════════════════ */

	/**
	 * @param string $series_uid
	 * @param string $recurrence_id
	 * @return array|null
	 */
	public static function find_override( $series_uid, $recurrence_id ) {
		global $wpdb;
		$t = self::table();
		$r = $wpdb->get_row( $wpdb->prepare( // phpcs:ignore
			"SELECT * FROM {$t} WHERE series_uid = %s AND recurrence_id = %s AND deleted_at IS NULL LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $t is $wpdb->prefix plus a fixed table name; values are placeholders.
			(string) $series_uid, (string) $recurrence_id
		), ARRAY_A );
		return $r ?: null;
	}

	/**
	 * The name of the single-flight lock for one series.
	 *
	 * ── ONE PLACE, BECAUSE THE NAME IS THE LOCK ───────────────────────────────
	 *
	 * PUBLIC because ZSCH_Appointments::update() and ::delete() write the same
	 * master columns and must take the same lock. A second implementation of the
	 * NAME would be a second lock, which is not a lock at all.
	 */
	public static function series_lock( array $master ) {
		$uid = trim( (string) ( $master['uid'] ?? '' ) );
		if ( '' !== $uid ) {
			return 'zsch_series_' . md5( $uid );
		}
		/* No uid, so no series identity to lock on. The row id is distinct and
		 * stable, which is all a lock needs — and far better than every uid-less
		 * series on the server queueing behind one another. */
		return 'zsch_series_row_' . (int) ( $master['id'] ?? 0 );
	}

	/**
	 * Take the series lock, or say who is busy.
	 *
	 * Returns true when the lock is HELD and the caller must release it, or a
	 * WP_Error the caller should return as-is. Callers pair this with a
	 * `finally` that releases — every one of them, without exception, because a
	 * lock leaked on an error path is a series nobody can edit until the
	 * connection closes.
	 */
	public static function acquire( $lock ) {
		global $wpdb;
		$got = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $lock, 5 ) );
		if ( '1' !== (string) $got ) {
			return new WP_Error( 'zsch_series_busy',
				'Somebody else is changing this appointment right now. Try again in a moment.',
				array( 'status' => 409 ) );
		}
		return true;
	}

	/** Release a lock taken by acquire(). */
	public static function release( $lock ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
	}

	/**
	 * The master row as the DATABASE holds it, for a read-modify-write.
	 */
	private static function read_raw( $id ) {
		global $wpdb;
		$t = self::table();
		$r = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", (int) $id ), ARRAY_A ); // phpcs:ignore
		return $r ?: null;
	}

	/**
	 * The override for this occurrence, ALIVE OR BURIED.
	 *
	 * ── WHY BOTH FUNCTIONS EXIST ──────────────────────────────────────────────
	 *
	 * find_override() filters `deleted_at IS NULL`, which is right for every
	 * question about what is on the calendar. It is wrong for one question:
	 * "what is holding this override_key?" — because UNIQUE idx_zsch_override
	 * holds the key of a SOFT-DELETED row exactly as firmly as a live one.
	 */
	public static function find_override_any( $series_uid, $recurrence_id ) {
		global $wpdb;
		$t = self::table();
		$r = $wpdb->get_row( $wpdb->prepare( // phpcs:ignore
			"SELECT * FROM {$t} WHERE series_uid = %s AND recurrence_id = %s ORDER BY deleted_at IS NULL DESC, id ASC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $t is $wpdb->prefix plus a fixed table name; values are placeholders.
			(string) $series_uid, (string) $recurrence_id
		), ARRAY_A );
		return $r ?: null;
	}

	/**
	 * This occurrence exists again: take its EXDATE off the master.
	 *
	 * ── WHY THIS IS PART OF A MOVE ────────────────────────────────────────────
	 */
	private static function unsuppress( array $master, $recurrence_id, $actor_id ) {
		global $wpdb;
		$had = ZSCH_Series::split_list( $master['exdate'] ?? '' );
		if ( ! in_array( trim( (string) $recurrence_id ), $had, true ) ) {
			return false;
		}
		$exdate = ZSCH_Series::remove_from_list( $master['exdate'] ?? '', $recurrence_id );
		$wpdb->update( self::table(), array( // phpcs:ignore
			'exdate'        => '' !== $exdate ? $exdate : null,
			'sequence'      => (int) $master['sequence'] + 1,
			'last_modified' => current_time( 'mysql', true ),
			'updated_by'    => (int) $actor_id,
			'updated_at'    => current_time( 'mysql', true ),
		), array( 'id' => (int) $master['id'] ) );
		return true;
	}

	/** @return array|WP_Error */
	/**
	 * ONE shape for every scoped write, so the editor can have ONE opinion.
	 *
	 * ── WHY THIS EXISTS ───────────────────────────────────────────────────
	 *
	 * MERGES rather than replaces — callers read appointment fields, `graph`
	 * and `warnings` off these returns, and an envelope that swallowed the
	 * payload would be a different bug with the same symptom.
	 *
	 * A WP_Error passes straight through: an error already has a shape, and
	 * wrapping one would announce `success => true` over a failure, which is
	 * the exact class of lie this whole change is about.
	 */
	private static function envelope( $payload ) {
		if ( is_wp_error( $payload ) ) {
			return $payload;
		}
		return array_merge( array( 'success' => true, 'ok' => true ), (array) $payload );
	}

	private static function read( $id ) {
		global $wpdb;
		$t = self::table();
		$r = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", (int) $id ), ARRAY_A ); // phpcs:ignore
		if ( ! $r ) {
			return new WP_Error( 'zsch_not_found', 'Saved, but could not be read back.', array( 'status' => 500 ) );
		}
		return ZSCH_Appointments::shape_row( $r );
	}

	/**
	 * Record what changed. Never blocks the write it describes.
	 *
	 * @param array $row
	 * @param array $fields
	 * @param int   $actor_id
	 * @param string $source_note
	 * @return void
	 */
	private static function log_change( array $row, array $fields, $actor_id, $source_note ) {
		if ( ! class_exists( 'ZSCH_Identity' ) ) { return; }
		$changes = array();
		foreach ( array( 'start_utc', 'end_utc', 'title', 'location' ) as $f ) {
			if ( isset( $fields[ $f ] ) ) {
				$changes[ $f ] = array( (string) ( $row[ $f ] ?? '' ), (string) $fields[ $f ] );
			}
		}
		ZSCH_Identity::log( (int) ( $row['id'] ?? 0 ), (string) ( $row['uid'] ?? '' ), $changes,
			array( 'actor_id' => (int) $actor_id, 'actor_type' => 'user', 'source' => 'ui' ) );
	}
}
