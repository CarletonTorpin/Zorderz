<?php
/**
 * ZSCH_Participants — an event carries a REAL party, not a typed email.
 *
 * Before this, a calendar event's "attendees" were a JSON string of email
 * addresses that had never produced an invitation (the app-level Graph mailbox
 * app was never configured, so `attendees[]` went nowhere). A participant is now
 * a real staff account (a `ZDZ_Party`), stored in wp_zsch_participants.
 *
 * WHAT A PARTICIPANT IS (three consequences, all realized here or by consumers):
 *   1. SEES the event — including an owner's personal (private) event. That is a
 *      DISCLOSURE, so adding a participant is gated as an EDIT (can_modify), and
 *      the disclosure is BOUNDED to the added party: ZSCH_Appointments::query()
 *      folds in events the viewer participates in (participant-scoped, never a
 *      blanket "make it public to the whole team").
 *   2. COUNTS AS BUSY — in Zsch_Suggest::open_slots() (and, as consumers adopt it,
 *      the team grid + chat availability lookup).
 *   3. Is a real PARTY — the picker is ZDZ_Party::selectable_people() (active,
 *      emailable, NON-kiosk, non-inactive). A device is NEVER a party
 *      (may_be_added() refuses the kiosk); the owner is never a participant row
 *      (you cannot remove yourself).
 *
 * TWO ACTIONS, DELIBERATELY LEFT UNWIRED: zsch_participant_added / _removed fire
 * with zero subscribers. "Being on an appointment is not being assigned to a job."
 *
 * IDOR: every mutating call RE-GATES per object (re-reads the appointment, re-runs
 * can_modify) — a client-supplied appointment id or user id is never trusted.
 *
 * NO MONEY: a participant row carries an id and a timestamp — never a figure.
 *
 * Ships EMPTY: names no company/person/product/place/provider; seeds nothing. The
 * display vocabulary ("People", "Just you so far.") is [IDENTITY→voice] and lives
 * in the client, not here.
 *
 * @since 1.9.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZSCH_Participants {

	private static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'zsch_participants';
	}

	/* ===================================================================
	 * ELIGIBILITY — a device is never a party.
	 * =================================================================== */

	/**
	 * May this user be ADDED as a participant? The kiosk (a shared device) is
	 * refused, and the candidate must be in the authoritative selectable roster
	 * (ZDZ_Party — active, emailable, non-kiosk, non-inactive). Fail-closed.
	 *
	 * @param int $user_id
	 * @return bool
	 */
	public static function may_be_added( int $user_id ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}
		// A device is never a party (INV-Kiosk) — even before the roster check.
		if ( function_exists( 'zsch_user_is_read_only' ) && zsch_user_is_read_only( $user_id ) ) {
			return false;
		}
		// Must be a selectable Party (the roster already excludes kiosk + inactive).
		if ( class_exists( 'ZDZ_Party' ) && is_callable( array( 'ZDZ_Party', 'selectable_people' ) ) ) {
			foreach ( ZDZ_Party::selectable_people( array( 'include_self' => true ) ) as $p ) {
				if ( (int) ( $p['id'] ?? 0 ) === $user_id ) {
					return true;
				}
			}
			return false; // a real user who is not a selectable party (no email / inactive) — refuse.
		}
		// Party model unavailable — fall back to "a real, existing account".
		return function_exists( 'get_userdata' ) ? (bool) get_userdata( $user_id ) : false;
	}

	/* ===================================================================
	 * WRITE — add / remove. Gated as an EDIT (IDOR re-gate per object).
	 * =================================================================== */

	/**
	 * Add one or more participants to an appointment.
	 *
	 * Gated as an EDIT: the actor must be able to MODIFY the appointment
	 * (can_modify) — because adding a participant DISCLOSES the event to them.
	 * Each candidate is re-checked with may_be_added(). The owner is silently
	 * skipped (already implicitly on it). Adding a participant to a personal
	 * event PROMOTES its disclosure to the added party (participant-scoped
	 * visibility) — never a blanket public flip.
	 *
	 * @param int   $actor    the acting user.
	 * @param int   $appt_id  the appointment.
	 * @param int[] $user_ids candidate party ids.
	 * @return array{ok:bool,error?:string,added?:int[],skipped?:int[]}
	 */
	public static function add( int $actor, int $appt_id, array $user_ids ): array {
		global $wpdb;
		$appt_id = (int) $appt_id;
		if ( $actor <= 0 || $appt_id <= 0 ) {
			return array( 'ok' => false, 'error' => 'bad_args' );
		}
		// IDOR RE-GATE per object: re-read the appointment and re-run the edit gate.
		$appt = self::appointment_raw( $appt_id );
		if ( null === $appt ) {
			return array( 'ok' => false, 'error' => 'no_appointment' );
		}
		if ( ! self::can_modify( $actor, $appt ) ) {
			return array( 'ok' => false, 'error' => 'forbidden' );
		}

		$owner_id = (int) ( $appt['owner_user_id'] ?? 0 );
		$added    = array();
		$skipped  = array();
		foreach ( array_values( array_unique( array_map( 'intval', $user_ids ) ) ) as $uid ) {
			if ( $uid <= 0 || $uid === $owner_id ) {
				$skipped[] = $uid; // the owner is never a row (implicitly on it).
				continue;
			}
			if ( ! self::may_be_added( $uid ) ) {
				$skipped[] = $uid; // kiosk / not a selectable party.
				continue;
			}
			// Idempotent revive-or-insert on UNIQUE(appointment, user).
			$existing = $wpdb->get_row(
				$wpdb->prepare( 'SELECT id, removed_at FROM ' . self::table() . ' WHERE appointment_id = %d AND user_id = %d', $appt_id, $uid ),
				ARRAY_A
			); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			if ( is_array( $existing ) ) {
				if ( null !== ( $existing['removed_at'] ?? null ) ) {
					$wpdb->update(
						self::table(),
						array( 'removed_at' => null, 'added_by' => $actor, 'added_at' => self::now() ),
						array( 'id' => (int) $existing['id'] ),
						array( '%s', '%d', '%s' ),
						array( '%d' )
					); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
					$added[] = $uid;
				}
				// already active → no-op (not counted as newly added).
				continue;
			}
			$ok = $wpdb->insert(
				self::table(),
				array(
					'appointment_id' => $appt_id,
					'user_id'        => $uid,
					'added_by'       => $actor,
					'added_at'       => self::now(),
					'removed_at'     => null,
				),
				array( '%d', '%d', '%d', '%s', '%s' )
			); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			if ( $ok ) {
				$added[] = $uid;
			}
		}

		foreach ( $added as $uid ) {
			// Zero-subscriber by design ("on an appointment" != "assigned to a job").
			if ( function_exists( 'do_action' ) ) {
				do_action( 'zsch_participant_added', $appt_id, $uid, $actor );
			}
		}

		return array( 'ok' => true, 'added' => $added, 'skipped' => array_values( array_filter( $skipped ) ) );
	}

	/**
	 * Remove one participant (soft — keeps the row so a re-add is a revive and a
	 * future write-back can purge the party's copy first — INV-Orphan). Gated as
	 * an edit (IDOR re-gate). The owner is not a row, so there is nothing to
	 * remove for them.
	 *
	 * @param int $actor
	 * @param int $appt_id
	 * @param int $user_id
	 * @return array{ok:bool,error?:string}
	 */
	public static function remove( int $actor, int $appt_id, int $user_id ): array {
		global $wpdb;
		$appt_id = (int) $appt_id;
		$user_id = (int) $user_id;
		if ( $actor <= 0 || $appt_id <= 0 || $user_id <= 0 ) {
			return array( 'ok' => false, 'error' => 'bad_args' );
		}
		$appt = self::appointment_raw( $appt_id );
		if ( null === $appt ) {
			return array( 'ok' => false, 'error' => 'no_appointment' );
		}
		if ( ! self::can_modify( $actor, $appt ) ) {
			return array( 'ok' => false, 'error' => 'forbidden' );
		}
		$wpdb->update(
			self::table(),
			array( 'removed_at' => self::now() ),
			array( 'appointment_id' => $appt_id, 'user_id' => $user_id, 'removed_at' => null ),
			array( '%s' ),
			array( '%d', '%d', '%s' )
		); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( function_exists( 'do_action' ) ) {
			do_action( 'zsch_participant_removed', $appt_id, $user_id, $actor );
		}
		return array( 'ok' => true );
	}

	/* ===================================================================
	 * READ
	 * =================================================================== */

	/**
	 * Active participant user ids for an appointment (owner excluded — it is never
	 * a row). Ints from the DB.
	 *
	 * @param int $appt_id
	 * @return int[]
	 */
	public static function participant_ids( int $appt_id ): array {
		global $wpdb;
		$appt_id = (int) $appt_id;
		if ( $appt_id <= 0 ) {
			return array();
		}
		$ids = $wpdb->get_col(
			$wpdb->prepare( 'SELECT user_id FROM ' . self::table() . ' WHERE appointment_id = %d AND removed_at IS NULL', $appt_id )
		); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return array_values( array_filter( array_map( 'intval', (array) $ids ) ) );
	}

	/**
	 * The appointment ids a user is an active participant of (for the query()
	 * visibility fold-in and the busy overlay). Optionally window-bounded via a
	 * caller join; here we return the id set (bounded by LIMIT for safety).
	 *
	 * @param int $user_id
	 * @return int[]
	 */
	public static function appointment_ids_for_participant( int $user_id ): array {
		global $wpdb;
		$user_id = (int) $user_id;
		if ( $user_id <= 0 ) {
			return array();
		}
		$ids = $wpdb->get_col(
			$wpdb->prepare( 'SELECT appointment_id FROM ' . self::table() . ' WHERE user_id = %d AND removed_at IS NULL LIMIT 2000', $user_id )
		); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return array_values( array_filter( array_map( 'intval', (array) $ids ) ) );
	}

	/** Is this user an active participant of the appointment? */
	public static function is_participant( int $appt_id, int $user_id ): bool {
		return in_array( (int) $user_id, self::participant_ids( (int) $appt_id ), true );
	}

	/**
	 * Present the current participants for the UI: id / name / initials only
	 * (email stays server-side, matching ZDZ_Party's privacy posture). The owner
	 * is not included (it is shown by the UI separately as "you"/owner).
	 *
	 * @param int $appt_id
	 * @return array<int,array{user_id:int,name:string,initials:string}>
	 */
	public static function list_for( int $appt_id ): array {
		$out = array();
		foreach ( self::participant_ids( (int) $appt_id ) as $uid ) {
			$name = '';
			if ( function_exists( 'get_userdata' ) ) {
				$u = get_userdata( $uid );
				$name = $u ? (string) $u->display_name : '';
			}
			$out[] = array(
				'user_id'  => $uid,
				'name'     => $name,
				'initials' => self::initials( $name ),
			);
		}
		return $out;
	}

	/**
	 * Busy intervals contributed by a user's participations in a UTC window — an
	 * event you are ON counts against your free time. Reads the LOCAL appointment
	 * rows (no network). Returns [ {start_utc,end_utc} ] in 'Y-m-d H:i:s'.
	 *
	 * @param int    $user_id
	 * @param string $start_utc 'Y-m-d H:i:s'
	 * @param string $end_utc
	 * @return array<int,array{start_utc:string,end_utc:string}>
	 */
	public static function busy_for( int $user_id, string $start_utc, string $end_utc ): array {
		global $wpdb;
		$user_id = (int) $user_id;
		if ( $user_id <= 0 ) {
			return array();
		}
		$appt = $wpdb->prefix . 'zsch_appointments';
		$part = self::table();
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT a.start_utc, a.end_utc
				 FROM {$part} p
				 INNER JOIN {$appt} a ON a.id = p.appointment_id
				 WHERE p.user_id = %d AND p.removed_at IS NULL
				   AND a.deleted_at IS NULL
				   AND a.start_utc < %s AND a.end_utc > %s
				 ORDER BY a.start_utc ASC LIMIT 1000",
				$user_id,
				$end_utc,
				$start_utc
			),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$out = array();
		foreach ( (array) $rows as $r ) {
			$out[] = array(
				'start_utc' => (string) ( $r['start_utc'] ?? '' ),
				'end_utc'   => (string) ( $r['end_utc'] ?? '' ),
			);
		}
		return $out;
	}

	/* ===================================================================
	 * INTERNAL
	 * =================================================================== */

	/**
	 * The edit gate. Uses ZSCH_Appointments::can_modify() through is_callable so
	 * this holds even if the scheduler model is older — fail-closed. This is the
	 * same gate attach() double-checks (a shared calendar disclosure).
	 */
	private static function can_modify( int $actor, array $appt ): bool {
		if ( ! is_callable( array( 'ZSCH_Appointments', 'can_modify' ) ) ) {
			return false;
		}
		return (bool) ZSCH_Appointments::can_modify( $actor, $appt );
	}

	/** The raw appointment row, or null (guarded). */
	private static function appointment_raw( int $appt_id ) {
		if ( ! class_exists( 'ZSCH_Appointments' ) || ! is_callable( array( 'ZSCH_Appointments', 'get_raw' ) ) ) {
			return null;
		}
		$raw = ZSCH_Appointments::get_raw( $appt_id );
		if ( ! is_array( $raw ) ) {
			return null;
		}
		// A deleted event is not modifiable.
		if ( ! empty( $raw['deleted_at'] ) ) {
			return null;
		}
		return $raw;
	}

	private static function initials( string $name ): string {
		$parts = preg_split( '/\s+/', trim( $name ) );
		$ini   = '';
		foreach ( (array) $parts as $p ) {
			if ( '' === $p ) {
				continue;
			}
			$ini .= strtoupper( mb_substr( $p, 0, 1 ) );
			if ( strlen( $ini ) >= 2 ) {
				break;
			}
		}
		return '' !== $ini ? $ini : '?';
	}

	private static function now(): string {
		return function_exists( 'current_time' ) ? (string) current_time( 'mysql', true ) : gmdate( 'Y-m-d H:i:s' );
	}
}
