<?php
/**
 * ZSCH_REST — the scheduler's REST API.
 *
 * Namespace: zorderz/v1/scheduler. These power the calendar widget (it uses the cookie-
 * bound X-WP-Nonce that the theme already provides) and give other ecosystem
 * plugins a clean read surface. Every route is cap-gated; write routes also
 * enforce zsch_user_can_write() so the shared kiosk can never mutate.
 *
 * ROUTES
 *   GET    /events?start&end&scope            list visible events
 *   POST   /events                            create appointment
 *   PATCH  /events/{id}                        update appointment
 *   DELETE /events/{id}                        delete appointment
 *   GET    /availability?start&end&user_ids   availability blocks
 *   GET    /availability/team?start&end       team free/busy grid
 *   POST   /availability                       create a block
 *   DELETE /availability/{id}                  delete a block
 *   POST   /availability/dictate               natural-language availability
 *   GET    /team                               team roster (id, name, color)
 *   GET    /sync/status                        is Graph sync active?
 *   POST   /sync/now                           pull my Outlook events now
 *
 * v1.6.0 — Connected Calendars (all owner-scoped, kiosk can never reach them
 * because every gate includes zsch_user_can_write; responses carry
 * Cache-Control: no-store):
 *   GET    /connections                        my connected accounts + feeds
 *   DELETE /connections/{id}                   disconnect an account
 *   GET    /connections/{id}/calendars         live calendar list (picker)
 *   POST   /connections/{id}/feeds             toggle a conflict calendar
 *   POST   /connections/sync                   pull my connected calendars now (v1.7.0)
 *
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZSCH_REST {

	/**
	 * G3 (v1.5.1) — best-effort per-user write-rate gate (fixed window,
	 * transient counter; fail-open on object-cache eviction). Blast-radius
	 * control for runaway clients — not a lock.
	 *
	 * @return int 0 = allowed, else seconds to wait.
	 */
	private static function rate_gate( string $key, int $limit, int $window ): int {
		$uid = get_current_user_id();
		$k   = 'zsch_rl_' . $key . '_' . $uid . '_' . (int) floor( time() / $window );
		$n   = (int) get_transient( $k );
		if ( $n >= $limit ) {
			return max( 1, $window - ( time() % $window ) );
		}
		set_transient( $k, $n + 1, $window + 5 );
		return 0;
	}

	/** Shared 429 shape. */
	private static function rate_limited( int $retry ) {
		return new WP_Error( 'zsch_rate_limited', 'Too many requests — try again in ' . $retry . 's.', array( 'status' => 429, 'retry_after' => $retry ) );
	}

	/**
	 * Route base WITHIN the theme's single REST namespace.
	 *
	 * Every route lives under ZDZ_REST_NS (= 'zorderz/v1'), the one constant the
	 * theme owns — the namespace literal is never typed here (the v1.0.1 404 was
	 * four call sites hand-typing a stale namespace). Routes resolve as
	 * `zorderz/v1/scheduler/…` so they never collide with sibling apps.
	 */
	const ROUTE_BASE = '/scheduler';

	/**
	 * Full REST base URL the widget/connections JS prefixes onto route paths
	 * (e.g. base_url() . '/events'). Empty when the theme (which defines
	 * ZDZ_REST_NS) is absent — callers degrade rather than build a bad URL.
	 */
	public static function base_url(): string {
		if ( ! defined( 'ZDZ_REST_NS' ) ) {
			return '';
		}
		return esc_url_raw( rest_url( ZDZ_REST_NS . self::ROUTE_BASE ) );
	}

	public static function register_routes() {
		// Decline cleanly if the theme (owner of ZDZ_REST_NS) isn't present.
		if ( ! defined( 'ZDZ_REST_NS' ) ) {
			return;
		}
		$read  = WP_REST_Server::READABLE;
		$write = WP_REST_Server::CREATABLE;

		register_rest_route( ZDZ_REST_NS . self::ROUTE_BASE, '/events', array(
			array(
				'methods'             => $read,
				'callback'            => array( __CLASS__, 'list_events' ),
				'permission_callback' => array( __CLASS__, 'can_read' ),
			),
			array(
				'methods'             => $write,
				'callback'            => array( __CLASS__, 'create_event' ),
				'permission_callback' => array( __CLASS__, 'can_write' ),
			),
		) );

		register_rest_route( ZDZ_REST_NS . self::ROUTE_BASE, '/events/(?P<id>\d+)', array(
			array(
				'methods'             => 'PATCH',
				'callback'            => array( __CLASS__, 'update_event' ),
				'permission_callback' => array( __CLASS__, 'can_write' ),
			),
			array(
				'methods'             => 'DELETE',
				'callback'            => array( __CLASS__, 'delete_event' ),
				'permission_callback' => array( __CLASS__, 'can_write' ),
			),
		) );

		register_rest_route( ZDZ_REST_NS . self::ROUTE_BASE, '/availability', array(
			array(
				'methods'             => $read,
				'callback'            => array( __CLASS__, 'list_availability' ),
				'permission_callback' => array( __CLASS__, 'can_read' ),
			),
			array(
				'methods'             => $write,
				'callback'            => array( __CLASS__, 'create_availability' ),
				'permission_callback' => array( __CLASS__, 'can_write' ),
			),
		) );

		register_rest_route( ZDZ_REST_NS . self::ROUTE_BASE, '/availability/team', array(
			'methods'             => $read,
			'callback'            => array( __CLASS__, 'team_grid' ),
			'permission_callback' => array( __CLASS__, 'can_read' ),
		) );

		register_rest_route( ZDZ_REST_NS . self::ROUTE_BASE, '/availability/(?P<id>\d+)', array(
			'methods'             => 'DELETE',
			'callback'            => array( __CLASS__, 'delete_availability' ),
			'permission_callback' => array( __CLASS__, 'can_write' ),
		) );

		register_rest_route( ZDZ_REST_NS . self::ROUTE_BASE, '/availability/dictate', array(
			'methods'             => $write,
			'callback'            => array( __CLASS__, 'dictate_availability' ),
			'permission_callback' => array( __CLASS__, 'can_write' ),
		) );

		register_rest_route( ZDZ_REST_NS . self::ROUTE_BASE, '/team', array(
			'methods'             => $read,
			'callback'            => array( __CLASS__, 'team_roster' ),
			'permission_callback' => array( __CLASS__, 'can_read' ),
		) );

		register_rest_route( ZDZ_REST_NS . self::ROUTE_BASE, '/sync/status', array(
			'methods'             => $read,
			'callback'            => array( __CLASS__, 'sync_status' ),
			'permission_callback' => array( __CLASS__, 'can_read' ),
		) );

		register_rest_route( ZDZ_REST_NS . self::ROUTE_BASE, '/sync/now', array(
			'methods'             => $write,
			'callback'            => array( __CLASS__, 'sync_now' ),
			'permission_callback' => array( __CLASS__, 'can_write' ),
		) );

		// ── v1.6.0 Connected Calendars ─────────────────────────────
		register_rest_route( ZDZ_REST_NS . self::ROUTE_BASE, '/connections', array(
			'methods'             => $read,
			'callback'            => array( __CLASS__, 'list_connections' ),
			'permission_callback' => array( __CLASS__, 'can_connect' ),
		) );

		register_rest_route( ZDZ_REST_NS . self::ROUTE_BASE, '/connections/(?P<id>\d+)', array(
			'methods'             => 'DELETE',
			'callback'            => array( __CLASS__, 'delete_connection' ),
			'permission_callback' => array( __CLASS__, 'can_connect' ),
		) );

		register_rest_route( ZDZ_REST_NS . self::ROUTE_BASE, '/connections/(?P<id>\d+)/calendars', array(
			'methods'             => $read,
			'callback'            => array( __CLASS__, 'connection_calendars' ),
			'permission_callback' => array( __CLASS__, 'can_connect' ),
		) );

		register_rest_route( ZDZ_REST_NS . self::ROUTE_BASE, '/connections/(?P<id>\d+)/feeds', array(
			'methods'             => $write,
			'callback'            => array( __CLASS__, 'toggle_feed' ),
			'permission_callback' => array( __CLASS__, 'can_connect' ),
		) );

		// v1.7.0 — pull MY connected calendars into the busy mirror now.
		register_rest_route( ZDZ_REST_NS . self::ROUTE_BASE, '/connections/sync', array(
			'methods'             => $write,
			'callback'            => array( __CLASS__, 'sync_connections_now' ),
			'permission_callback' => array( __CLASS__, 'can_connect' ),
		) );
	}

	// ── permission gates ───────────────────────────────────────────

	public static function can_read() {
		if ( ! is_user_logged_in() || ! zsch_user_has_access() ) {
			return false;
		}
		// Customer-facing hide → 404 (handled in the callbacks; gate stays true
		// so we control the status code, like TSIM).
		return true;
	}

	public static function can_write() {
		return is_user_logged_in() && zsch_user_has_access() && zsch_user_can_write();
	}

	/**
	 * v1.6.0 — Connected Calendars gate: a write-capable user (kiosk is
	 * read-only → denied at this line, INV-Kiosk) AND the feature flag +
	 * provider config present. With the flag down these routes 404 like they
	 * don't exist.
	 */
	public static function can_connect() {
		return self::can_write()
			&& class_exists( 'ZSCH_OAuth' )
			&& ZSCH_OAuth::feature_enabled();
	}

	private static function hidden() {
		return ! class_exists( 'ZSCH_Widget' ) ? false : ! ZSCH_Widget::should_render();
	}

	private static function not_available() {
		return new WP_Error( 'zsch_unavailable', 'Not available.', array( 'status' => 404 ) );
	}

	// ── events ─────────────────────────────────────────────────────

	public static function list_events( WP_REST_Request $req ) {
		if ( self::hidden() ) { return self::not_available(); }
		list( $start_utc, $end_utc ) = self::window( $req );
		$scope = $req->get_param( 'scope' ) ?: 'all';
		$args  = array( 'scope' => in_array( $scope, array( 'all', 'personal', 'shared' ), true ) ? $scope : 'all' );
		$owner = (int) $req->get_param( 'owner_id' );
		if ( $owner > 0 ) { $args['owner_id'] = $owner; }

		$rows = ZSCH_Appointments::query( get_current_user_id(), $start_utc, $end_utc, $args );
		return rest_ensure_response( array( 'events' => $rows ) );
	}

	public static function create_event( WP_REST_Request $req ) {
		$rl = self::rate_gate( 'ev', 60, 3600 ); // G3 (v1.5.1)
		if ( $rl > 0 ) {
			return self::rate_limited( $rl );
		}
		if ( self::hidden() ) { return self::not_available(); }
		$res = ZSCH_Appointments::create( get_current_user_id(), self::event_input( $req ) );
		return self::respond( $res );
	}

	public static function update_event( WP_REST_Request $req ) {
		if ( self::hidden() ) { return self::not_available(); }
		$res = ZSCH_Appointments::update( get_current_user_id(), (int) $req['id'], self::event_input( $req ) );
		return self::respond( $res );
	}

	public static function delete_event( WP_REST_Request $req ) {
		if ( self::hidden() ) { return self::not_available(); }
		$res = ZSCH_Appointments::delete( get_current_user_id(), (int) $req['id'] );
		return self::respond( $res );
	}

	// ── availability ───────────────────────────────────────────────

	public static function list_availability( WP_REST_Request $req ) {
		if ( self::hidden() ) { return self::not_available(); }
		list( $start_utc, $end_utc ) = self::window( $req );
		$ids = $req->get_param( 'user_ids' );
		$ids = is_array( $ids ) ? $ids : ( $ids ? explode( ',', (string) $ids ) : array() );
		$blocks = ZSCH_Availability::query( $ids, $start_utc, $end_utc );
		return rest_ensure_response( array( 'availability' => $blocks ) );
	}

	public static function team_grid( WP_REST_Request $req ) {
		if ( self::hidden() ) { return self::not_available(); }
		list( $start_utc, $end_utc ) = self::window( $req );
		return rest_ensure_response( ZSCH_Availability::team_grid( $start_utc, $end_utc ) );
	}

	public static function create_availability( WP_REST_Request $req ) {
		if ( self::hidden() ) { return self::not_available(); }
		$res = ZSCH_Availability::create( get_current_user_id(), array(
			'kind'        => $req->get_param( 'kind' ),
			'start_local' => $req->get_param( 'start_local' ),
			'end_local'   => $req->get_param( 'end_local' ),
			'time_zone'   => $req->get_param( 'time_zone' ),
			'is_all_day'  => $req->get_param( 'is_all_day' ),
			'note'        => $req->get_param( 'note' ),
			'owner_id'    => $req->get_param( 'owner_id' ),
			'source'      => $req->get_param( 'source' ),
		) );
		return self::respond( $res );
	}

	public static function delete_availability( WP_REST_Request $req ) {
		if ( self::hidden() ) { return self::not_available(); }
		$res = ZSCH_Availability::delete( get_current_user_id(), (int) $req['id'] );
		return self::respond( $res );
	}

	/**
	 * Natural-language availability. The client passes already-parsed segments
	 * (the widget's JS parser handles "open Mon–Wed"); we also accept a raw
	 * `text` for server-side parsing of simple date phrases as a fallback.
	 */
	public static function dictate_availability( WP_REST_Request $req ) {
		$rl = self::rate_gate( 'dict', 30, 3600 ); // G3 (v1.5.1)
		if ( $rl > 0 ) {
			return self::rate_limited( $rl );
		}
		if ( self::hidden() ) { return self::not_available(); }

		$segments = $req->get_param( 'segments' );
		$tz       = $req->get_param( 'time_zone' ) ?: ZSCH_Settings::default_tz();

		if ( ! is_array( $segments ) || empty( $segments ) ) {
			// Fallback: parse a raw phrase server-side (best-effort, simple cases).
			$text = (string) $req->get_param( 'text' );
			$segments = self::parse_phrase( $text, $tz );
			if ( empty( $segments ) ) {
				return rest_ensure_response( array(
					'success' => false,
					'error'   => "I couldn't understand those dates. Try e.g. \"open June 16 to June 18\".",
				) );
			}
		}

		// Sanitize incoming segments.
		$clean = array();
		foreach ( $segments as $seg ) {
			if ( empty( $seg['start_local'] ) || empty( $seg['end_local'] ) ) {
				continue;
			}
			$clean[] = array(
				'kind'        => ( ( $seg['kind'] ?? 'open' ) === 'busy' ) ? 'busy' : 'open',
				'start_local' => sanitize_text_field( $seg['start_local'] ),
				'end_local'   => sanitize_text_field( $seg['end_local'] ),
			);
		}

		$res = ZSCH_Availability::bulk_set( get_current_user_id(), $clean, array(
			'time_zone' => $tz,
			'source'    => 'voice',
			'note'      => sanitize_text_field( $req->get_param( 'note' ) ?? '' ),
		) );
		return rest_ensure_response( $res );
	}

	// ── team + sync ────────────────────────────────────────────────

	public static function team_roster( WP_REST_Request $req ) {
		if ( self::hidden() ) { return self::not_available(); }
		$members = ZSCH_Availability::team_members();
		// Stable colour per user (deterministic hue from id).
		foreach ( $members as &$m ) {
			$m['color'] = self::color_for_user( $m['user_id'] );
		}
		return rest_ensure_response( array(
			'members' => $members,
			'me'      => get_current_user_id(),
			'is_admin'=> ZSCH_Appointments::viewer_is_admin( get_current_user_id() ),
			'can_write' => zsch_user_can_write(),
		) );
	}

	public static function sync_status( WP_REST_Request $req ) {
		return rest_ensure_response( array(
			'sync_active' => class_exists( 'ZSCH_Graph' ) && ZSCH_Graph::is_available(),
			'mailbox'     => class_exists( 'ZSCH_Graph' ) ? ZSCH_Graph::mailbox_for_user( get_current_user_id() ) : '',
		) );
	}

	public static function sync_now( WP_REST_Request $req ) {
		if ( ! class_exists( 'ZSCH_Graph' ) || ! ZSCH_Graph::is_available() ) {
			return rest_ensure_response( array( 'success' => false, 'error' => 'Sync is not configured.' ) );
		}
		$uid     = get_current_user_id();
		$mailbox = ZSCH_Graph::mailbox_for_user( $uid );
		if ( '' === $mailbox ) {
			return rest_ensure_response( array( 'success' => false, 'error' => 'No Microsoft mailbox on file for your account.' ) );
		}
		$start = gmdate( 'Y-m-d\TH:i:s\Z', strtotime( '-1 day' ) );
		$end   = gmdate( 'Y-m-d\TH:i:s\Z', strtotime( '+60 days' ) );
		$res   = ZSCH_Graph::calendar_view( $mailbox, $start, $end );
		if ( empty( $res['success'] ) ) {
			return rest_ensure_response( array( 'success' => false, 'error' => $res['error'] ?? 'Sync failed.' ) );
		}
		ZSCH_Appointments::reconcile_from_graph( $uid, $mailbox, $res['events'] );
		return rest_ensure_response( array( 'success' => true, 'pulled' => count( $res['events'] ) ) );
	}

	// ── Connected Calendars (v1.6.0) ───────────────────────────────

	/** Wrap a connections payload with no-store (these are personal). */
	private static function no_store( $payload ) {
		$resp = rest_ensure_response( $payload );
		if ( $resp instanceof WP_REST_Response ) {
			$resp->header( 'Cache-Control', 'private, no-store, max-age=0' );
		}
		return $resp;
	}

	/** My connected accounts + their conflict feeds (never anyone else's). */
	public static function list_connections( WP_REST_Request $req ) {
		if ( self::hidden() ) { return self::not_available(); }
		return self::no_store( array(
			'accounts'  => ZSCH_Connections::list_for_user( get_current_user_id() ),
			'providers' => ZSCH_OAuth::providers_available(),
		) );
	}

	/** Disconnect one of MY accounts (revoke best-effort + delete + purge). */
	public static function delete_connection( WP_REST_Request $req ) {
		$rl = self::rate_gate( 'conn_del', 10, 3600 );
		if ( $rl > 0 ) {
			return self::rate_limited( $rl );
		}
		if ( self::hidden() ) { return self::not_available(); }
		$ok = ZSCH_OAuth::disconnect( get_current_user_id(), (int) $req['id'] );
		if ( ! $ok ) {
			return self::not_available(); // Not yours / gone — same 404 either way.
		}
		return self::no_store( array( 'success' => true ) );
	}

	/** Live calendar list for the picker — owner-scoped via the account row. */
	public static function connection_calendars( WP_REST_Request $req ) {
		$rl = self::rate_gate( 'conn_cals', 30, 3600 );
		if ( $rl > 0 ) {
			return self::rate_limited( $rl );
		}
		if ( self::hidden() ) { return self::not_available(); }
		$account = ZSCH_Connections::get_owned_account( get_current_user_id(), (int) $req['id'] );
		if ( ! $account ) {
			return self::not_available();
		}
		$cals = ( 'microsoft' === $account->provider )
			? ZSCH_Graph_Delegated::calendar_list( (int) $account->id )
			: ZSCH_Google::calendar_list( (int) $account->id );
		if ( is_wp_error( $cals ) ) {
			return self::no_store( array(
				'success' => false,
				'error'   => ( 'zsch_reauth' === $cals->get_error_code() )
					? 'This account needs to be reconnected.'
					: 'Could not load the calendar list.',
				'reauth'  => ( 'zsch_reauth' === $cals->get_error_code() ),
			) );
		}
		return self::no_store( array( 'success' => true, 'calendars' => $cals ) );
	}

	/**
	 * Toggle a conflict calendar. Body: {external_cal_id, name, color, on}.
	 * on=true inserts the feed (idempotent); on=false deletes it + its mirror.
	 */
	public static function toggle_feed( WP_REST_Request $req ) {
		$rl = self::rate_gate( 'conn_feed', 60, 3600 );
		if ( $rl > 0 ) {
			return self::rate_limited( $rl );
		}
		if ( self::hidden() ) { return self::not_available(); }
		$uid        = get_current_user_id();
		$account_id = (int) $req['id'];
		$on         = filter_var( $req->get_param( 'on' ), FILTER_VALIDATE_BOOLEAN );

		if ( $on ) {
			$res = ZSCH_Connections::enable_feed(
				$uid,
				$account_id,
				(string) $req->get_param( 'external_cal_id' ),
				(string) ( $req->get_param( 'name' ) ?? '' ),
				(string) ( $req->get_param( 'color' ) ?? '' )
			);
			if ( is_wp_error( $res ) ) {
				return self::no_store( array( 'success' => false, 'error' => $res->get_error_message() ) );
			}
			return self::no_store( array( 'success' => true, 'feed_id' => (int) $res ) );
		}

		$feed_id = (int) $req->get_param( 'feed_id' );
		$ok      = $feed_id > 0 && ZSCH_Connections::disable_feed( $uid, $feed_id );
		return self::no_store( array( 'success' => (bool) $ok ) );
	}

	/**
	 * v1.7.0 — pull the caller's OWN connected calendars into the busy mirror
	 * immediately (owner-scoped inside ZSCH_Sync::sync_user). Rate-gated; safe
	 * no-op when the feature is off. Lets the connect flow feel instant instead
	 * of waiting up to 5 minutes for the cron.
	 */
	public static function sync_connections_now( WP_REST_Request $req ) {
		$rl = self::rate_gate( 'conn_sync', 12, 3600 );
		if ( $rl > 0 ) {
			return self::rate_limited( $rl );
		}
		if ( self::hidden() ) { return self::not_available(); }
		if ( ! class_exists( 'ZSCH_Sync' ) ) {
			return self::no_store( array( 'success' => false, 'error' => 'Sync engine unavailable.' ) );
		}
		return self::no_store( ZSCH_Sync::sync_user( get_current_user_id() ) );
	}

	// ── helpers ────────────────────────────────────────────────────

	private static function event_input( WP_REST_Request $req ) {
		return array(
			'title'          => $req->get_param( 'title' ),
			'body'           => $req->get_param( 'body' ),
			'location'       => $req->get_param( 'location' ),
			'start_local'    => $req->get_param( 'start_local' ),
			'end_local'      => $req->get_param( 'end_local' ),
			'time_zone'      => $req->get_param( 'time_zone' ),
			'is_all_day'     => $req->get_param( 'is_all_day' ),
			'calendar_scope' => $req->get_param( 'calendar_scope' ),
			'busy_status'    => $req->get_param( 'busy_status' ),
			'color'          => $req->get_param( 'color' ),
			'owner_id'       => $req->get_param( 'owner_id' ),
			'attendees'      => $req->get_param( 'attendees' ),
		);
	}

	/**
	 * Window from start/end params (ISO or Y-m-d). Defaults to the current
	 * month ± a week of padding. Returns UTC 'Y-m-d H:i:s'.
	 */
	private static function window( WP_REST_Request $req ) {
		$tz = ZSCH_Settings::default_tz();
		try { $zone = new DateTimeZone( $tz ); } catch ( Exception $e ) { $zone = new DateTimeZone( 'UTC' ); }

		$s = (string) $req->get_param( 'start' );
		$e = (string) $req->get_param( 'end' );
		try {
			$start = $s ? new DateTime( $s, $zone ) : new DateTime( 'first day of this month', $zone );
			$end   = $e ? new DateTime( $e, $zone ) : new DateTime( 'last day of this month', $zone );
			$start->setTime( 0, 0, 0 );
			$end->setTime( 23, 59, 59 );
		} catch ( Exception $ex ) {
			$start = new DateTime( 'first day of this month', $zone );
			$end   = new DateTime( 'last day of this month', $zone );
		}
		$start->setTimezone( new DateTimeZone( 'UTC' ) );
		$end->setTimezone( new DateTimeZone( 'UTC' ) );
		return array( $start->format( 'Y-m-d H:i:s' ), $end->format( 'Y-m-d H:i:s' ) );
	}

	/**
	 * Best-effort server-side parse of simple availability phrases. The widget
	 * does the heavy lifting client-side; this just rescues a few patterns:
	 *   "open June 16 to June 18", "busy 6/20", "open next monday".
	 */
	private static function parse_phrase( $text, $tz ) {
		$text = strtolower( trim( (string) $text ) );
		if ( '' === $text ) {
			return array();
		}
		$kind = ( false !== strpos( $text, 'busy' ) || false !== strpos( $text, 'book' ) || false !== strpos( $text, 'block' ) ) ? 'busy' : 'open';

		// "<date> to <date>" or "<date> - <date>"
		if ( preg_match( '/(.+?)\s*(?:to|through|thru|until|-|–)\s*(.+)/', $text, $m ) ) {
			$start = self::soft_date( $m[1], $tz );
			$end   = self::soft_date( $m[2], $tz );
			if ( $start && $end ) {
				return array( array( 'kind' => $kind, 'start_local' => $start, 'end_local' => $end ) );
			}
		}
		// single day
		$d = self::soft_date( $text, $tz );
		if ( $d ) {
			return array( array( 'kind' => $kind, 'start_local' => $d, 'end_local' => $d ) );
		}
		return array();
	}

	private static function soft_date( $frag, $tz ) {
		$frag = preg_replace( '/\b(open|busy|book(ed)?|block(ed)?|mark me|i am|im|i\'m|available|free)\b/', '', (string) $frag );
		$frag = trim( $frag );
		if ( '' === $frag ) {
			return '';
		}
		try {
			$zone      = new DateTimeZone( $tz );
			$now       = new DateTime( 'now', $zone );
			$this_year = (int) $now->format( 'Y' );

			$dt = new DateTime( $frag, $zone );
			$y  = (int) $dt->format( 'Y' );

			// Guard against an implausible parsed year (mirrors the JS fix): keep
			// only this year ± 1; otherwise pin the parsed month/day to this year.
			if ( $y < $this_year - 1 || $y > $this_year + 1 ) {
				$dt = new DateTime( $this_year . '-' . $dt->format( 'm-d' ), $zone );
			}
			// A bare month/day clearly in the past → assume next year.
			$age_days = ( $now->getTimestamp() - $dt->getTimestamp() ) / 86400;
			if ( $age_days > 32 ) {
				$dt->modify( '+1 year' );
			}
			return $dt->format( 'Y-m-d' );
		} catch ( Exception $e ) {
			return '';
		}
	}

	private static function color_for_user( $uid ) {
		$hue = ( (int) $uid * 47 ) % 360;
		return sprintf( 'hsl(%d 70%% 50%%)', $hue );
	}

	private static function respond( $res ) {
		if ( is_array( $res ) && empty( $res['success'] ) ) {
			return rest_ensure_response( $res ); // 200 with success:false (UI shows the message)
		}
		return rest_ensure_response( $res );
	}
}
