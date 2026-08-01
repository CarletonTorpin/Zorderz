<?php
/**
 * ZSCH_TSA_Bridge — orchestrator capability bridge for the scheduler.
 *
 * Per ORCHESTRATOR-INTEROP-CONTRACT-v1 §2.1, this is the static class the
 * operator bot (Brain Bot / TSA) calls server-side. Every method returns a
 * structured array (never throws, never null), is self-contained, accepts the
 * caller's tier, and reads through this plugin's own models.
 *
 * VERBS:
 *   availability.lookup  (read)   — "is a teammate free Thursday?" → their open/busy.
 *   schedule.lookup      (read)   — "what's on my calendar this week?"
 *   appointment.create   (action) — "book me 2pm Tuesday" → preview+confirm.
 *
 * KIOSK STANCE (most-restrictive-wins):
 *   - Schedule/availability data is OPERATIONAL coordination data, not PII or
 *     financials. The shared kiosk (`zdz_general`) MAY read the TEAM-LEVEL view
 *     in a BOUNDED form: names + free/busy + event TITLES of SHARED events
 *     only. Personal-event details and bodies/attendees are scrubbed
 *     server-side on kiosk. (You can see "A teammate: booked 9–11 — Job #4821"
 *     if that's a shared job, but not the contents of that teammate's private
 *     personal appointment.)
 *   - appointment.create is SIDE-EFFECTING → FORBIDDEN on kiosk. The bridge
 *     refuses; the engine should also strip the [ZSCH_BOOK] marker on kiosk as
 *     a backstop.
 *
 * REGISTRY-READY (§2.3): get_capability_descriptor() shapes the verbs as
 * future zdz_register_capabilities callbacks with side_effect declared.
 *
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZSCH_TSA_Bridge {

	const SOURCE = 'zsch_tsa_bridge';

	/**
	 * Installed + ready. (Always true once the plugin is active; Graph sync is
	 * NOT required — the local schedule answers most questions.)
	 *
	 * @return bool
	 */
	public static function is_available() {
		return class_exists( 'ZSCH_Appointments' ) && class_exists( 'ZSCH_Availability' );
	}

	/**
	 * v1.5.4 (security): resolve the TRUE acting user + kiosk. True-session-wins -- the live
	 * session decides who is acting and whether this is the shared kiosk; the payload's
	 * requesting_user_id / is_kiosk are advisory and used ONLY on the session-less async seam.
	 * Stops a forged requesting_user_id from reading/creating another user's schedule (or
	 * claiming admin), and a forged is_kiosk=false from lifting the kiosk bound in a real
	 * shared-device session.
	 * @return array [ int uid, bool is_kiosk ]
	 */
	private static function resolve_actor( array $payload ) {
		$uid = (int) get_current_user_id();
		if ( $uid <= 0 ) {
			$uid = (int) ( $payload['requesting_user_id'] ?? 0 );
		}
		$is_kiosk = ! empty( $payload['is_kiosk'] );
		if ( $uid > 0 ) {
			$u = get_userdata( $uid );
			if ( $u && is_array( $u->roles ) && in_array( 'zdz_general', $u->roles, true ) ) {
				$is_kiosk = true;
			}
		}
		return array( $uid, $is_kiosk );
	}

	/**
	 * READ — availability for a named person (or the whole team) in a window.
	 *
	 * @param array $payload {
	 *   query|user_id : who (display-name/login to resolve, or explicit id; omit for team),
	 *   start, end    : 'Y-m-d' or ISO; defaults to the next 7 days,
	 *   tier, is_kiosk, requesting_user_id
	 * }
	 * @return array structured result
	 */
	public static function availability_lookup( array $payload ) {
		if ( ! self::is_available() ) {
			return self::fail( 'unavailable', 'Scheduler is not available.' );
		}
		list( $uid, $is_kiosk ) = self::resolve_actor( $payload );
		list( $start_utc, $end_utc, $label ) = self::resolve_window( $payload );

		// Resolve target user (optional).
		$target_id = self::resolve_user( $payload );
		if ( isset( $payload['query'] ) && '' !== trim( (string) $payload['query'] ) && ! $target_id ) {
			return self::clarify( "I couldn't find a team member matching \"" . sanitize_text_field( $payload['query'] ) . "\". Who did you mean?" );
		}

		$ids = $target_id ? array( $target_id ) : wp_list_pluck( ZSCH_Availability::team_members(), 'user_id' );
		$grid_blocks = ZSCH_Availability::query( $ids, $start_utc, $end_utc );

		// Also surface busy times from actual appointments (so "free?" accounts
		// for booked events, not just painted availability).
		$appts = array();
		foreach ( $ids as $uid ) {
			// On kiosk we only consider SHARED appointments as busy-makers and
			// only expose their titles; personal details are withheld.
			$rows = ZSCH_Appointments::query(
				$is_kiosk ? 0 : $uid,
				$start_utc,
				$end_utc,
				$is_kiosk ? array( 'scope' => 'shared' ) : array( 'scope' => 'all', 'owner_id' => $uid )
			);
			foreach ( $rows as $r ) {
				if ( $target_id && (int) $r['owner_user_id'] !== (int) $target_id && 'shared' !== $r['scope'] ) {
					continue;
				}
				$appts[] = self::scrub_event( $r, $is_kiosk );
			}
		}

		// v1.7.0 (Connected Calendars Phase 1) — also count EXTERNAL conflict
		// calendars (Google/Microsoft) as busy-makers so "is a teammate free?"
		// reflects their outside commitments. Busy-only (no titles); personal data → never
		// surfaced on the shared kiosk.
		if ( ! $is_kiosk && class_exists( 'ZSCH_Sync' ) ) {
			$ext = ZSCH_Sync::read_busy( $ids, $start_utc, $end_utc );
			foreach ( $ext as $ext_uid => $ext_blocks ) {
				$owner = get_userdata( (int) $ext_uid );
				foreach ( $ext_blocks as $eb ) {
					$appts[] = array(
						'id'          => 0,
						'scope'       => 'external',
						'owner_name'  => $owner ? $owner->display_name : '',
						'title'       => 'Busy',
						'start_utc'   => $eb['start_utc'],
						'end_utc'     => $eb['end_utc'],
						'is_all_day'  => $eb['is_all_day'],
						'busy_status' => $eb['busy_status'],
						'source'      => 'external',
					);
				}
			}
		}

		return array(
			'success'      => true,
			'denied'       => false,
			'needs_clarify'=> false,
			'message'      => '',
			'error'        => '',
			'window'       => array( 'label' => $label, 'start' => $start_utc, 'end' => $end_utc ),
			'target'       => $target_id ? self::user_brief( $target_id ) : null,
			'availability' => array_map( array( __CLASS__, 'block_brief' ), $grid_blocks ),
			'busy_events'  => $appts,
			'kiosk'        => $is_kiosk,
			'source'       => self::SOURCE,
		);
	}

	/**
	 * READ — the requesting user's own schedule (or, for admins, a named
	 * person's) in a window.
	 *
	 * @param array $payload  { query|user_id, start, end, tier, is_kiosk, requesting_user_id }
	 * @return array
	 */
	public static function schedule_lookup( array $payload ) {
		if ( ! self::is_available() ) {
			return self::fail( 'unavailable', 'Scheduler is not available.' );
		}
		list( $viewer_id, $is_kiosk ) = self::resolve_actor( $payload );
		list( $start_utc, $end_utc, $label ) = self::resolve_window( $payload );

		// On kiosk, "my schedule" is meaningless (shared account) → show the
		// shared team calendar only.
		$args = array( 'scope' => $is_kiosk ? 'shared' : 'all' );
		$target_id = self::resolve_user( $payload );
		if ( $target_id && ! $is_kiosk && ZSCH_Appointments::viewer_is_admin( $viewer_id ) ) {
			$args['owner_id'] = $target_id;
		}

		$rows = ZSCH_Appointments::query( $is_kiosk ? 0 : $viewer_id, $start_utc, $end_utc, $args );
		$events = array();
		foreach ( $rows as $r ) {
			$events[] = self::scrub_event( $r, $is_kiosk );
		}

		return array(
			'success'      => true,
			'denied'       => false,
			'needs_clarify'=> false,
			'message'      => '',
			'error'        => '',
			'window'       => array( 'label' => $label, 'start' => $start_utc, 'end' => $end_utc ),
			'events'       => $events,
			'kiosk'        => $is_kiosk,
			'source'       => self::SOURCE,
		);
	}

	/**
	 * ACTION — create an appointment. Side-effecting → preview-and-confirm in
	 * the bot; FORBIDDEN on kiosk.
	 *
	 * @param array $payload {
	 *   title, start_local, end_local, time_zone, location, body,
	 *   calendar_scope ('personal'|'shared'), busy_status, attendees[],
	 *   is_kiosk, requesting_user_id, confirmed (bool)
	 * }
	 * @return array
	 */
	public static function appointment_create( array $payload ) {
		if ( ! self::is_available() ) {
			return self::fail( 'unavailable', 'Scheduler is not available.' );
		}
		list( $actor_id, $is_kiosk ) = self::resolve_actor( $payload );
		if ( $is_kiosk ) {
			return self::deny( 'Creating appointments is disabled on the shared device.' );
		}
		if ( $actor_id <= 0 || ! zsch_user_can_write( $actor_id ) ) {
			return self::deny( 'You do not have permission to create appointments.' );
		}

		// Preview step: if not confirmed, echo back a normalized summary for the
		// bot to render as a confirm card (do NOT write yet).
		if ( empty( $payload['confirmed'] ) ) {
			return array(
				'success'       => true,
				'needs_confirm' => true,
				'denied'        => false,
				'message'       => 'Confirm to create this appointment.',
				'preview'       => array(
					'title'          => sanitize_text_field( $payload['title'] ?? '' ),
					'start_local'    => sanitize_text_field( $payload['start_local'] ?? '' ),
					'end_local'      => sanitize_text_field( $payload['end_local'] ?? '' ),
					'location'       => sanitize_text_field( $payload['location'] ?? '' ),
					'calendar_scope' => ( ( $payload['calendar_scope'] ?? 'personal' ) === 'shared' ) ? 'shared' : 'personal',
				),
				'source'        => self::SOURCE,
			);
		}

		$res = ZSCH_Appointments::create( $actor_id, $payload );
		if ( empty( $res['success'] ) ) {
			return self::fail( 'create_failed', $res['error'] ?? 'Could not create the appointment.' );
		}

		return array(
			'success'      => true,
			'denied'       => false,
			'created'      => true,
			'appointment'  => $res['appointment'],
			'synced'       => ! empty( $res['graph']['success'] ) && empty( $res['graph']['skipped'] ),
			'message'      => 'Appointment created.',
			'source'       => self::SOURCE,
		);
	}

	// ── L4-ready descriptor ────────────────────────────────────────

	/**
	 * Capability descriptors, shaped for the future zdz_register_capabilities
	 * registry (contract §2.3).
	 *
	 * @return array
	 */
	public static function get_capability_descriptor() {
		return array(
			'availability.lookup' => array(
				'provider'      => 'zsch',
				'tier'          => 'tech',                // anyone with the app
				'callback'      => array( __CLASS__, 'availability_lookup' ),
				'kiosk'         => false,
				'kiosk_bounded' => true,                  // team free/busy + shared titles only
				'side_effect'   => false,
			),
			'schedule.lookup' => array(
				'provider'      => 'zsch',
				'tier'          => 'tech',
				'callback'      => array( __CLASS__, 'schedule_lookup' ),
				'kiosk'         => false,
				'kiosk_bounded' => true,                  // shared calendar only on kiosk
				'side_effect'   => false,
			),
			'appointment.create' => array(
				'provider'      => 'zsch',
				'tier'          => 'tech',
				'callback'      => array( __CLASS__, 'appointment_create' ),
				'kiosk'         => false,
				'kiosk_bounded' => false,                 // no safe kiosk mode → fully forbidden
				'side_effect'   => true,                  // preview-and-confirm
			),
		);
	}

	/**
	 * Format spec hint for the renderer (mirrors the contacts bridge pattern).
	 */
	public static function get_format_spec() {
		return array(
			'availability.lookup' => 'render as a free/busy summary per person',
			'schedule.lookup'     => 'render as a chronological event list',
			'appointment.create'  => 'render preview as a confirm card, result as a single event card',
		);
	}

	// ── internal helpers ───────────────────────────────────────────

	/**
	 * Scrub an event for output, applying kiosk bounding server-side.
	 */
	private static function scrub_event( array $r, $is_kiosk ) {
		$out = array(
			'id'          => $r['id'],
			'scope'       => $r['scope'],
			'owner_name'  => $r['owner_name'],
			'title'       => $r['title'],
			'start_utc'   => $r['start_utc'],
			'end_utc'     => $r['end_utc'],
			'is_all_day'  => $r['is_all_day'],
			'busy_status' => $r['busy_status'],
		);
		if ( $is_kiosk ) {
			// Bounded: shared events keep their title; personal events become an
			// opaque "Busy" with no body/location/attendees.
			if ( 'shared' !== $r['scope'] ) {
				$out['title'] = 'Busy';
			}
			return $out; // no body/location/attendees on kiosk
		}
		// Full (non-kiosk).
		$out['body']      = $r['body'];
		$out['location']  = $r['location'];
		$out['attendees'] = $r['attendees'];
		return $out;
	}

	private static function resolve_user( array $payload ) {
		if ( ! empty( $payload['user_id'] ) ) {
			$uid = (int) $payload['user_id'];
			return ( $uid > 0 && zsch_user_has_access( $uid ) ) ? $uid : 0;
		}
		$q = trim( (string) ( $payload['query'] ?? '' ) );
		if ( '' === $q ) {
			return 0;
		}
		// Try login, then display-name (case-insensitive, confidence-gated:
		// only resolve on a single unambiguous match).
		$by_login = get_user_by( 'login', $q );
		if ( $by_login && zsch_user_has_access( $by_login->ID ) ) {
			return (int) $by_login->ID;
		}
		$matches = get_users( array(
			'search'         => '*' . $q . '*',
			'search_columns' => array( 'display_name', 'user_nicename', 'user_login' ),
			'number'         => 3,
			'fields'         => array( 'ID' ),
		) );
		$eligible = array();
		foreach ( $matches as $m ) {
			if ( zsch_user_has_access( (int) $m->ID ) && ! zsch_user_is_read_only( (int) $m->ID ) ) {
				$eligible[] = (int) $m->ID;
			}
		}
		return ( count( $eligible ) === 1 ) ? $eligible[0] : 0; // never guess between two
	}

	/**
	 * Resolve a {start,end} window from the payload (defaults to next 7 days).
	 * Returns [ start_utc, end_utc, human_label ] in 'Y-m-d H:i:s'.
	 */
	private static function resolve_window( array $payload ) {
		$tz = ZSCH_Settings::default_tz();
		try {
			$zone = new DateTimeZone( $tz );
		} catch ( Exception $e ) {
			$zone = new DateTimeZone( 'UTC' );
		}

		$start_raw = trim( (string) ( $payload['start'] ?? '' ) );
		$end_raw   = trim( (string) ( $payload['end'] ?? '' ) );

		try {
			$start = $start_raw ? new DateTime( $start_raw, $zone ) : new DateTime( 'today', $zone );
			$end   = $end_raw ? new DateTime( $end_raw, $zone ) : ( clone $start )->modify( '+7 days' );
			$start->setTime( 0, 0, 0 );
			$end->setTime( 23, 59, 59 );
		} catch ( Exception $e ) {
			$start = new DateTime( 'today', $zone );
			$end   = ( clone $start )->modify( '+7 days' );
		}

		$label = $start->format( 'M j' ) . ' – ' . $end->format( 'M j' );
		$start->setTimezone( new DateTimeZone( 'UTC' ) );
		$end->setTimezone( new DateTimeZone( 'UTC' ) );
		return array( $start->format( 'Y-m-d H:i:s' ), $end->format( 'Y-m-d H:i:s' ), $label );
	}

	private static function user_brief( $uid ) {
		$u = get_userdata( (int) $uid );
		return $u ? array( 'user_id' => (int) $uid, 'name' => $u->display_name ) : null;
	}

	private static function block_brief( array $b ) {
		return array(
			'owner_name' => $b['owner_name'],
			'kind'       => $b['kind'],
			'start_utc'  => $b['start_utc'],
			'end_utc'    => $b['end_utc'],
			'note'       => $b['note'],
		);
	}

	// ── structured-return shims ────────────────────────────────────

	private static function fail( $code, $message ) {
		return array( 'success' => false, 'denied' => false, 'needs_clarify' => false, 'error' => $message, 'error_code' => $code, 'message' => $message, 'source' => self::SOURCE );
	}

	private static function deny( $message ) {
		return array( 'success' => false, 'denied' => true, 'needs_clarify' => false, 'error' => '', 'message' => $message, 'source' => self::SOURCE );
	}

	private static function clarify( $message ) {
		return array( 'success' => true, 'denied' => false, 'needs_clarify' => true, 'error' => '', 'message' => $message, 'source' => self::SOURCE );
	}
}
