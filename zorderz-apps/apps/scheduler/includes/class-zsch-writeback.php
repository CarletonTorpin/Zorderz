<?php
/**
 * ZSCH_Writeback — connected-calendar write-back (Phase 2). SHIPS OFF.
 *
 * The first OUTBOUND sync: a copy of a work event written into each owner-or-
 * participant's OWN connected Google/Microsoft calendar. This is the highest-PII
 * path in the subsystem — work-event notes/addresses would land in personal
 * calendars that leave the tenant — so it ships behind a flag that DEFAULTS OFF
 * (`zsch_writeback_enabled` = 'no'): every entry point is a guarded no-op until a
 * tenant enables it and a per-user write scope exists. Nothing here books, writes,
 * or calls a provider while the flag is down.
 *
 * WHAT IS BUILT NOW (so Phase 2 is a wiring change, not a redesign):
 *   - is_enabled()       the single OFF-by-default gate (option + filter).
 *   - targets_for()      the ONE place "who gets a copy" is decided: owner ∪ active
 *                        participants, DEDUPED by user. (No copy is emitted now.)
 *   - is_own_writeback() the INV-Loop guard (belt: the writeback_map; braces: our
 *                        stamp — an Outlook category / a Google `zdz_appt_id`
 *                        extended property). Lives in the INBOUND path so it holds
 *                        even with the engine OFF: the owner's own calendar is also
 *                        their conflict feed, so an unguarded copy makes its owner
 *                        look busy against itself within 5 minutes.
 *   - category_label()   the Microsoft-category brand stamp — sourced from
 *                        ZDZ_Business_Profile, NEVER a literal.
 *   - copy_payload()     the shape of a copy: title/location/time ONLY. NEVER a
 *                        money figure; NEVER an attendees[] on a delegated write (it
 *                        would make that person's own mailbox invite everyone).
 *
 * HARD RULES (asserted by the harness):
 *   - flag OFF by default; the lifecycle hooks are no-ops when off.
 *   - NO money on a copy (copy_payload carries none).
 *   - NO attendees[] on a delegated write.
 *   - writeback_detail default 'title_time' (not 'full') — a personal calendar may
 *     be shared with a spouse / synced to a car. [IDENTITY→profile] default.
 *
 * @since 1.9.0 (Phase 2 scaffold; engine OFF)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZSCH_Writeback {

	/** The feature flag option — DEFAULT 'no'. Write-back ships OFF. */
	const FLAG_OPTION = 'zsch_writeback_enabled';

	/** The per-copy state machine. */
	const STATE_OFF    = 'off';
	const STATE_PENDING = 'pending';
	const STATE_SYNCED = 'synced';
	const STATE_ERROR  = 'error';
	const STATE_ORPHAN = 'orphan';

	/** Google private extended-property KEY that stamps our own copies ([CORE], renamed per token rules). */
	const STAMP_KEY = 'zdz_appt_id';

	/**
	 * The single gate. Write-back is OFF unless a tenant opts in AND the filter
	 * agrees. Default 'no' — a guarded no-op everywhere.
	 *
	 * @return bool
	 */
	public static function is_enabled(): bool {
		$on = function_exists( 'get_option' ) ? ( 'yes' === (string) get_option( self::FLAG_OPTION, 'no' ) ) : false;
		if ( function_exists( 'apply_filters' ) ) {
			$on = (bool) apply_filters( 'zsch_writeback_enabled', $on );
		}
		return (bool) $on;
	}

	/**
	 * Detail level for a copy — how much of the event rides into a personal
	 * calendar. [IDENTITY→profile] default 'title_time' (a personal calendar may be
	 * shared / synced elsewhere); 'full' is an explicit opt-in.
	 *
	 * @return string 'title_time' | 'full'
	 */
	public static function detail_level(): string {
		$d = function_exists( 'apply_filters' ) ? (string) apply_filters( 'zsch_writeback_detail', 'title_time' ) : 'title_time';
		return in_array( $d, array( 'title_time', 'full' ), true ) ? $d : 'title_time';
	}

	/* ===================================================================
	 * LIFECYCLE — subscribed to the appointment actions. No-op while OFF.
	 * =================================================================== */

	/** A copy is (would be) created. No-op while OFF. */
	public static function on_created( $payload ): void {
		if ( ! self::is_enabled() ) {
			return; // OFF — no network, no rows.
		}
		// Phase 2: enqueue copies for targets_for(); immediate push bounded, rest
		// drained by cron with backoff. Intentionally unimplemented while OFF.
	}

	/** A copy is (would be) patched. No-op while OFF. */
	public static function on_updated( $id, $payload = array() ): void {
		if ( ! self::is_enabled() ) {
			return;
		}
		// Phase 2: content_hash skips a no-op PATCH.
	}

	/** A copy is (would be) removed. No-op while OFF. */
	public static function on_deleted( $id, $payload = array() ): void {
		if ( ! self::is_enabled() ) {
			return;
		}
		// Phase 2: INV-Orphan — purge each target's copy BEFORE any grant is revoked.
	}

	/* ===================================================================
	 * targets_for — the ONE place "who gets a copy" is decided.
	 * =================================================================== */

	/**
	 * Owner ∪ active participants, DEDUPED by user. This is the ONLY authority for
	 * "who gets a copy". (Returns the set even while OFF — it is pure and testable;
	 * no copy is emitted until Phase 2 is enabled.)
	 *
	 * @param int $appt_id
	 * @return int[] user ids (owner first), deduped.
	 */
	public static function targets_for( int $appt_id ): array {
		$appt_id = (int) $appt_id;
		if ( $appt_id <= 0 || ! class_exists( 'ZSCH_Appointments' ) || ! is_callable( array( 'ZSCH_Appointments', 'get_raw' ) ) ) {
			return array();
		}
		$raw = ZSCH_Appointments::get_raw( $appt_id );
		if ( ! is_array( $raw ) || ! empty( $raw['deleted_at'] ) ) {
			return array();
		}
		$targets = array();
		$owner   = (int) ( $raw['owner_user_id'] ?? 0 );
		if ( $owner > 0 ) {
			$targets[] = $owner;
		}
		if ( class_exists( 'ZSCH_Participants' ) && is_callable( array( 'ZSCH_Participants', 'participant_ids' ) ) ) {
			foreach ( ZSCH_Participants::participant_ids( $appt_id ) as $uid ) {
				$targets[] = (int) $uid;
			}
		}
		// Dedupe by user, preserve owner-first order.
		return array_values( array_unique( array_filter( $targets ) ) );
	}

	/* ===================================================================
	 * INV-Loop — the inbound puller refuses to re-ingest our own writes.
	 * =================================================================== */

	/**
	 * Is this INBOUND external event one of OUR OWN write-back copies? Belt AND
	 * braces, so a single mechanism failing does not reopen the loop:
	 *   BELT   — its external_event_id is a row in wp_zsch_writeback_map.
	 *   BRACES — it carries our stamp (a Google `zdz_appt_id` extended property, or
	 *            our Microsoft category label).
	 * Called from the inbound reconcile so it holds EVEN WITH THE ENGINE OFF. While
	 * OFF the map is empty and nothing is stamped, so it never matches — the guard
	 * is simply pre-placed for when Phase 2 lands.
	 *
	 * @param array $external_event a normalized/raw inbound event.
	 * @return bool
	 */
	public static function is_own_writeback( array $external_event ): bool {
		// BRACES: our own stamp on the event.
		$props = $external_event['extendedProperties']['private'] ?? array();
		if ( is_array( $props ) && ! empty( $props[ self::STAMP_KEY ] ) ) {
			return true;
		}
		$cats = $external_event['categories'] ?? array();
		if ( is_array( $cats ) ) {
			$label = self::category_label();
			if ( '' !== $label && in_array( $label, $cats, true ) ) {
				return true;
			}
		}
		// BELT: the provider event id is a row in the write-back map.
		$ext_id = (string) ( $external_event['external_event_id'] ?? ( $external_event['id'] ?? '' ) );
		if ( '' === $ext_id ) {
			return false;
		}
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return false;
		}
		$table = $wpdb->prefix . 'zsch_writeback_map';
		$hit   = $wpdb->get_var(
			$wpdb->prepare( "SELECT 1 FROM {$table} WHERE external_event_id = %s LIMIT 1", $ext_id )
		); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return ! empty( $hit );
	}

	/* ===================================================================
	 * The copy shape + the brand stamp.
	 * =================================================================== */

	/**
	 * The payload of a copy — title/location/time ONLY at 'title_time' detail; a
	 * neutral title when detail is minimal. NEVER a money figure. NEVER an
	 * attendees[] on a delegated write (structurally absent from this shape). Used
	 * by Phase 2; exposed now so the money-off / no-attendees rules are testable.
	 *
	 * @param array $appt a raw appointment row.
	 * @return array{subject:string,location:string,start_utc:string,end_utc:string,categories:array}
	 */
	public static function copy_payload( array $appt ): array {
		$full  = ( 'full' === self::detail_level() );
		$title = (string) ( $appt['title'] ?? '' );
		$subject = $full && '' !== $title ? $title : self::neutral_subject();
		$out = array(
			'subject'    => $subject,
			'location'   => $full ? (string) ( $appt['location'] ?? '' ) : '',
			'start_utc'  => (string) ( $appt['start_utc'] ?? '' ),
			'end_utc'    => (string) ( $appt['end_utc'] ?? '' ),
			'categories' => array_values( array_filter( array( self::category_label() ) ) ),
			// NOTE: no 'attendees' key — a delegated write must never carry one
			// (it would make the target's own mailbox invite everyone, from them).
			// NOTE: no money key — a copy never carries a figure.
		);
		return $out;
	}

	/**
	 * The brand short-name stamped as the Microsoft category on a copy. Sourced
	 * from ZDZ_Business_Profile, NEVER a literal; a neutral default (the site name)
	 * when the profile is empty.
	 *
	 * @return string
	 */
	public static function category_label(): string {
		if ( class_exists( 'ZDZ_Business_Profile' ) ) {
			foreach ( array( 'identity.short_name', 'identity.short_code' ) as $path ) {
				$val = (string) ZDZ_Business_Profile::get( $path, '' );
				if ( '' !== trim( $val ) ) {
					return trim( $val );
				}
			}
			if ( is_callable( array( 'ZDZ_Business_Profile', 'name' ) ) ) {
				$n = (string) ZDZ_Business_Profile::name();
				if ( '' !== trim( $n ) ) {
					return trim( $n );
				}
			}
		}
		return function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'name' ) : '';
	}

	/** A neutral subject for a minimal-detail copy (no company/product name). */
	private static function neutral_subject(): string {
		$s = function_exists( 'apply_filters' ) ? (string) apply_filters( 'zsch_writeback_neutral_subject', 'Busy' ) : 'Busy';
		return '' !== trim( $s ) ? trim( $s ) : 'Busy';
	}
}
