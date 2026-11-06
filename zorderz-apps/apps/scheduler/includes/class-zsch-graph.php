<?php
/**
 * ZSCH_Graph — Microsoft Graph (Microsoft 365 / Exchange Online) client.
 *
 * THE "EXCHANGE" INTEGRATION. Every team member's mailbox lives in the SAME
 * Microsoft 365 tenant, so we authenticate ONCE as the application (client
 * credentials grant) using an Azure AD app registration that has been granted
 * the *application* permission `Calendars.ReadWrite` with org admin consent.
 * That single app-only token can read/write ANY user's calendar addressed by
 * their mailbox (userPrincipalName / primary email) — no per-user OAuth.
 *
 * Endpoints used (Graph v1.0):
 *   POST /users/{mailbox}/events                    create
 *   PATCH /users/{mailbox}/events/{id}              update (If-Match: etag)
 *   DELETE /users/{mailbox}/events/{id}             delete
 *   GET  /users/{mailbox}/calendarView?startDateTime&endDateTime   pull range
 *
 * SELF-CONTAINED (contract section 2.1.2): reconstructs its own credentials from
 * ZSCH_Settings; the orchestrator/caller never passes them. is_available()
 * guards "configured + switched on". When a SECOND app needs Graph, promote
 * this to a theme ZDZ_Core_Graph and migrate behind these signatures.
 *
 * GRACEFUL DEGRADATION: if sync isn't configured, every method is a safe
 * no-op that returns a structured "skipped" result — the rest of the plugin
 * runs local-first and never errors because Graph is absent.
 *
 * NB: All HTTP goes through wp_remote_* (WordPress HTTP API) — never raw cURL.
 *
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZSCH_Graph {

	const TOKEN_URL_TMPL = 'https://login.microsoftonline.com/%s/oauth2/v2.0/token';
	const GRAPH_BASE     = 'https://graph.microsoft.com/v1.0';
	const SCOPE          = 'https://graph.microsoft.com/.default';

	/* ── Cron sweep bounds ─────────────────────────────────────────
	 * WP-Cron runs INSIDE a web request, which hosts commonly terminate at about
	 * 60 seconds. Loading every user and giving each an unbounded calendar_view()
	 * (up to 20 pages at a 25s timeout apiece) can blow that wall in aggregate;
	 * the request is killed mid-loop, and if every tick restarted from the same
	 * user order, the people at the END of that list would never sync — silently,
	 * every tick.
	 *
	 * Two bounds, because they are different properties: a throughput budget that
	 * stops STARTING new work once the tick has been productive, and a hard
	 * ceiling that refuses to start any mailbox we cannot fund at its full cap.
	 * Plus a per-tick cap and an oldest-first cursor, so the sweep ROTATES and
	 * nobody can starve. */
	const SWEEP_BUDGET_S     = 30;
	const PER_USER_CEILING_S = 12;
	const MAX_USERS_PER_TICK = 12;
	const SYNC_CURSOR_META   = 'zsch_graph_last_sweep';

	/**
	 * Shared theme client, when present AND configured.
	 *
	 * Once the theme ships a shared
	 * `ZDZ_Core_Graph` we read/write Microsoft 365 through it (one token cache,
	 * one client on the org's single Azure app registration) rather than a
	 * private duplicate. We keep this plugin's own client as the fallback so the
	 * plugin still works if it's installed on a theme that predates ZDZ_Core_Graph.
	 *
	 * Returns the shared client instance if it exists and is configured, else null.
	 *
	 * @return \ZDZ_Core_Graph|null
	 */
	private static function shared() {
		static $checked = false, $client = null;
		if ( ! $checked ) {
			$checked = true;
			if ( class_exists( 'ZDZ_Core_Graph' ) ) {
				$c = new ZDZ_Core_Graph();
				if ( $c->is_configured() ) {
					$client = $c;
				}
			}
		}
		return $client;
	}

	/**
	 * Is two-way sync configured and enabled?
	 *
	 * True if EITHER the theme's shared client is configured, OR the plugin's
	 * own settings have sync active (standalone fallback).
	 *
	 * @return bool
	 */
	public static function is_available() {
		if ( self::shared() ) {
			return true;
		}
		return class_exists( 'ZSCH_Settings' ) && ZSCH_Settings::sync_active();
	}

	/**
	 * Acquire (or reuse a cached) app-only bearer token.
	 *
	 * @return string|WP_Error  access token, or WP_Error on failure.
	 */
	public static function get_token() {
		$cached = ZSCH_Settings::get_cached_token();
		if ( $cached && $cached['expires_at'] > time() ) {
			return $cached['access_token'];
		}

		$cfg = ZSCH_Settings::get_config();
		$secret = ZSCH_Settings::get_secret();
		if ( empty( $cfg['tenant_id'] ) || empty( $cfg['client_id'] ) || '' === $secret ) {
			return new WP_Error( 'zsch_graph_unconfigured', 'Microsoft 365 connection is not configured.' );
		}

		$url  = sprintf( self::TOKEN_URL_TMPL, rawurlencode( $cfg['tenant_id'] ) );
		$resp = wp_remote_post( $url, array(
			'timeout' => 20,
			'headers' => array( 'Content-Type' => 'application/x-www-form-urlencoded' ),
			'body'    => array(
				'client_id'     => $cfg['client_id'],
				'client_secret' => $secret,
				'grant_type'    => 'client_credentials',
				'scope'         => self::SCOPE,
			),
		) );

		if ( is_wp_error( $resp ) ) {
			return $resp;
		}
		$code = (int) wp_remote_retrieve_response_code( $resp );
		$json = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( $code < 200 || $code >= 300 || empty( $json['access_token'] ) ) {
			$desc = is_array( $json ) ? ( $json['error_description'] ?? $json['error'] ?? 'unknown' ) : 'unknown';
			self::log( "token error HTTP {$code}: " . ( is_string( $desc ) ? $desc : wp_json_encode( $desc ) ) );
			return new WP_Error( 'zsch_graph_token', 'Could not authenticate with Microsoft 365.', array( 'status' => $code ) );
		}

		ZSCH_Settings::set_cached_token( $json['access_token'], (int) ( $json['expires_in'] ?? 3600 ) );
		return $json['access_token'];
	}

	/**
	 * Resolve a WP user to their Microsoft mailbox address.
	 *
	 * Convention: the WP account email IS the Microsoft 365 UPN (the whole team
	 * is provisioned from Microsoft). A `zsch_mailbox` user-meta override is
	 * honoured first for the rare case where they differ.
	 *
	 * @param int $user_id
	 * @return string  mailbox (email/UPN) or '' if none.
	 */
	public static function mailbox_for_user( $user_id ) {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 ) {
			return '';
		}
		$override = get_user_meta( $user_id, 'zsch_mailbox', true );
		if ( is_string( $override ) && is_email( $override ) ) {
			return $override;
		}
		$u = get_userdata( $user_id );
		return ( $u && is_email( $u->user_email ) ) ? $u->user_email : '';
	}

	// ── Write operations ───────────────────────────────────────────

	/**
	 * Create an event in a user's Outlook calendar.
	 *
	 * @param int   $owner_user_id  whose mailbox to write to.
	 * @param array $appt           a normalised appointment row (see ZSCH_Appointments).
	 * @return array { success, skipped, error, graph_event_id, etag, ical_uid }
	 */
	public static function create_event( $owner_user_id, array $appt ) {
		if ( ! self::is_available() ) {
			return self::skipped();
		}
		$mailbox = self::mailbox_for_user( $owner_user_id );
		if ( '' === $mailbox ) {
			return self::fail( 'no_mailbox', 'No Microsoft mailbox for this user.' );
		}

		// Prefer the theme's shared client.
		$shared = self::shared();
		if ( $shared ) {
			return self::normalize_shared( $shared->create_event( $mailbox, self::appt_to_graph( $appt ) ) );
		}

		$token = self::get_token();
		if ( is_wp_error( $token ) ) {
			return self::fail( 'auth', $token->get_error_message() );
		}

		$body = self::appt_to_graph( $appt );
		$resp = wp_remote_post( self::GRAPH_BASE . '/users/' . rawurlencode( $mailbox ) . '/events', array(
			'timeout' => 25,
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Content-Type'  => 'application/json',
			),
			'body'    => wp_json_encode( $body ),
		) );

		return self::handle_event_write_response( $resp, 'create' );
	}

	/**
	 * Map a ZDZ_Core_Graph write result ({ id, iCalUId, etag }) into this class's
	 * shape ({ graph_event_id, ical_uid, etag }) so callers are unchanged.
	 *
	 * @param array $r
	 * @return array
	 */
	private static function normalize_shared( array $r ) {
		if ( empty( $r['success'] ) ) {
			return $r; // already { success:false, error, … } or { skipped:true }
		}
		return array(
			'success'        => true,
			'skipped'        => ! empty( $r['skipped'] ),
			'error'          => '',
			'graph_event_id' => $r['id'] ?? '',
			'ical_uid'       => $r['iCalUId'] ?? '',
			'etag'           => $r['etag'] ?? '',
		);
	}

	/**
	 * Update an existing event (conflict-safe via If-Match etag).
	 *
	 * @param string $mailbox
	 * @param string $graph_event_id
	 * @param array  $appt
	 * @param string $etag  optional; '' to skip If-Match.
	 * @return array
	 */
	public static function update_event( $mailbox, $graph_event_id, array $appt, $etag = '', array $omit = array() ) {
		if ( ! self::is_available() ) {
			return self::skipped();
		}
		if ( '' === $mailbox || '' === $graph_event_id ) {
			return self::fail( 'bad_args', 'Missing mailbox or event id.' );
		}

		$shared = self::shared();
		if ( $shared ) {
			return self::normalize_shared( $shared->update_event( $mailbox, $graph_event_id, self::appt_to_graph( $appt, $omit ), $etag ) );
		}

		$token = self::get_token();
		if ( is_wp_error( $token ) ) {
			return self::fail( 'auth', $token->get_error_message() );
		}

		$headers = array(
			'Authorization' => 'Bearer ' . $token,
			'Content-Type'  => 'application/json',
		);
		if ( '' !== $etag ) {
			$headers['If-Match'] = $etag;
		}

		$resp = wp_remote_request( self::GRAPH_BASE . '/users/' . rawurlencode( $mailbox ) . '/events/' . rawurlencode( $graph_event_id ), array(
			'method'  => 'PATCH',
			'timeout' => 25,
			'headers' => $headers,
			'body'    => wp_json_encode( self::appt_to_graph( $appt, $omit ) ),
		) );

		return self::handle_event_write_response( $resp, 'update' );
	}

	/**
	 * Delete an event.
	 *
	 * @param string $mailbox
	 * @param string $graph_event_id
	 * @return array { success, skipped, error }
	 */
	public static function delete_event( $mailbox, $graph_event_id ) {
		if ( ! self::is_available() ) {
			return self::skipped();
		}
		if ( '' === $mailbox || '' === $graph_event_id ) {
			return self::fail( 'bad_args', 'Missing mailbox or event id.' );
		}

		$shared = self::shared();
		if ( $shared ) {
			return $shared->delete_event( $mailbox, $graph_event_id );
		}

		$token = self::get_token();
		if ( is_wp_error( $token ) ) {
			return self::fail( 'auth', $token->get_error_message() );
		}

		$resp = wp_remote_request( self::GRAPH_BASE . '/users/' . rawurlencode( $mailbox ) . '/events/' . rawurlencode( $graph_event_id ), array(
			'method'  => 'DELETE',
			'timeout' => 20,
			'headers' => array( 'Authorization' => 'Bearer ' . $token ),
		) );

		if ( is_wp_error( $resp ) ) {
			return self::fail( 'http', $resp->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $resp );
		// 204 = deleted; 404 = already gone (treat as success — idempotent).
		if ( 204 === $code || 404 === $code ) {
			return array( 'success' => true, 'skipped' => false, 'error' => '' );
		}
		return self::fail( 'http', "Graph delete failed (HTTP {$code})." );
	}

	// ── Read / pull ────────────────────────────────────────────────

	/**
	 * Pull a user's calendar view (expanded recurrences) for a UTC window.
	 *
	 * @param string $mailbox
	 * @param string $start_utc  ISO 8601 (e.g. 2026-06-01T00:00:00Z)
	 * @param string $end_utc
	 * @return array { success, skipped, error, events:array }  events are raw Graph objects.
	 */
	public static function calendar_view( $mailbox, $start_utc, $end_utc, $budget_s = 0 ) {
		if ( ! self::is_available() ) {
			return self::skipped( array( 'events' => array() ) );
		}
		if ( '' === $mailbox ) {
			return self::fail( 'no_mailbox', 'No Microsoft mailbox for this user.', array( 'events' => array() ) );
		}

		$shared = self::shared();
		if ( $shared ) {
			return $shared->calendar_view( $mailbox, $start_utc, $end_utc );
		}

		$token = self::get_token();
		if ( is_wp_error( $token ) ) {
			return self::fail( 'auth', $token->get_error_message(), array( 'events' => array() ) );
		}

		$events = array();
		$url = add_query_arg( array(
			'startDateTime' => $start_utc,
			'endDateTime'   => $end_utc,
			/* `type` and `seriesMasterId`: /calendarView EXPANDS a recurring
			 * event, and every occurrence it returns carries the SAME iCalUId.
			 * These two fields are how the reconciler tells an occurrence from a
			 * standalone event. */
			'$select'       => 'id,iCalUId,subject,bodyPreview,location,start,end,isAllDay,showAs,isCancelled,lastModifiedDateTime,organizer,type,seriesMasterId',
			'$top'          => 100,
			'$orderby'      => 'start/dateTime',
		), self::GRAPH_BASE . '/users/' . rawurlencode( $mailbox ) . '/calendarView' );

		/* Follow @odata.nextLink pagination (cap at 20 pages defensively).
		 *
		 * When the caller passes a $budget_s, the page loop is bounded by WALL
		 * TIME as well as page count: 20 pages x a 25s timeout is a 500-second
		 * worst case for ONE mailbox, enough for a single slow account to eat a
		 * whole cron tick. We stop paging when the budget is spent and return what
		 * we have with `partial => true`. The window is ordered by start time, so a
		 * partial pull is the NEAREST events — the ones that matter today — never a
		 * random slice. */
		$t_page  = microtime( true );
		$partial = false;
		$guard = 0;
		while ( $url && $guard < 20 ) {
			$guard++;
			$req_timeout = 25;
			if ( $budget_s > 0 ) {
				$left = (float) $budget_s - ( microtime( true ) - $t_page );
				if ( $left < 3 ) {          // cannot fund even a minimal call
					$partial = true;
					break;
				}
				$req_timeout = (int) max( 3, min( 25, floor( $left ) ) );
			}
			$resp = wp_remote_get( $url, array(
				'timeout' => $req_timeout,
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					// Ask Graph to return times in UTC for deterministic parsing.
					'Prefer'        => 'outlook.timezone="UTC"',
				),
			) );
			if ( is_wp_error( $resp ) ) {
				return self::fail( 'http', $resp->get_error_message(), array( 'events' => $events ) );
			}
			$code = (int) wp_remote_retrieve_response_code( $resp );
			$json = json_decode( wp_remote_retrieve_body( $resp ), true );
			if ( $code < 200 || $code >= 300 ) {
				return self::fail( 'http', "Graph calendarView failed (HTTP {$code}).", array( 'events' => $events ) );
			}
			if ( ! empty( $json['value'] ) && is_array( $json['value'] ) ) {
				foreach ( $json['value'] as $ev ) {
					$events[] = $ev;
				}
			}
			$url = $json['@odata.nextLink'] ?? '';
		}

		return array( 'success' => true, 'skipped' => false, 'error' => '', 'events' => $events, 'partial' => $partial );
	}

	/**
	 * Cron entry — pull recent changes for every connected user and reconcile
	 * them into wp_zsch_appointments. Delegates the reconcile to
	 * ZSCH_Appointments so the table logic stays in one place.
	 *
	 * Windowed pull: yesterday → +60 days. Keeps the per-tick cost bounded;
	 * far-future and deep-past edits are rare and caught on the next user view.
	 */
	/**
	 * ONE lock for every Graph pull.
	 *
	 * Two concurrent pulls of one mailbox (the cron sweep and a "Sync now" click, or
	 * two sweeps) would each reconcile the same events: both find no map row, both
	 * INSERT, and the loser's appointment has no map row at all, so
	 * appt_id_for_graph_event() can never find it again — a duplicate nobody can
	 * clean up from the app. A transient cannot prevent that (check-then-set).
	 *
	 * GET_LOCK is atomic, exclusive between connections, and released by MySQL itself
	 * when the request that holds it ends — a crashed sweep cannot leave the calendar
	 * locked. Timeout 0: a click must not hang behind a sweep; it is told a sync is
	 * running. GET_LOCK is server-wide, so the name carries the install (prefix + DB
	 * name), as ZSCH_Appointments::migrate_once() does; 16 + 32 characters, under
	 * MySQL's 64.
	 */
	public static function sync_lock_name() {
		global $wpdb;
		return 'zsch_graph_sync_' . md5( (string) $wpdb->prefix . ( defined( 'DB_NAME' ) ? DB_NAME : '' ) );
	}

	public static function sync_lock_acquire() {
		global $wpdb;
		$got = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', self::sync_lock_name(), 0 ) );
		if ( null === $got ) {
			// A database error, not a sync in progress: say so, or a broken DB reads as "busy" forever.
			self::log( 'sync_lock_acquire: GET_LOCK returned no row (database error) -- pull skipped' );
		}
		return '1' === (string) $got;
	}

	public static function sync_lock_release() {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::sync_lock_name() ) );
	}

	public static function cron_sync_all() {
		if ( ! self::is_available() || ! class_exists( 'ZSCH_Appointments' ) ) {
			return;
		}

		/* Overlap guard: a tick that ran long must not overlap the next and double
		 * the load it was already failing to carry. One GET_LOCK, shared with the
		 * "Sync now" route. */
		if ( ! self::sync_lock_acquire() ) {
			return;
		}

		$t0      = microtime( true );
		$start   = gmdate( 'Y-m-d\TH:i:s\Z', strtotime( '-1 day' ) );
		$end     = gmdate( 'Y-m-d\TH:i:s\Z', strtotime( '+60 days' ) );
		$done    = 0;
		$partial = 0;
		$seen    = 0;

		try {
			foreach ( self::sweep_candidates() as $uid ) {
				if ( ! self::can_start_user( microtime( true ) - $t0, self::SWEEP_BUDGET_S, self::PER_USER_CEILING_S ) ) {
					break;
				}

				$seen++;
				$mailbox = self::mailbox_for_user( $uid );

				if ( '' !== $mailbox ) {
					$res = self::calendar_view( $mailbox, $start, $end, self::PER_USER_CEILING_S );
					if ( ! empty( $res['success'] ) ) {
						ZSCH_Appointments::reconcile_from_graph( $uid, $mailbox, $res['events'] );
						$done++;
						if ( ! empty( $res['partial'] ) ) {
							$partial++;
						}
					}
				}

				/* Stamp on EVERY attempt — success, failure, or no mailbox at all.
				 * The cursor exists to make the sweep ROTATE. Advancing only on success
				 * would leave one permanently slow or broken mailbox sitting at the head
				 * of the queue, starving everybody behind it. */
				self::stamp_swept( $uid );
			}
		} catch ( \Throwable $e ) {
			self::log( 'cron_sync_all fatal: ' . $e->getMessage() );
		} finally {
			self::sync_lock_release();
		}

		if ( $seen ) {
			self::log( sprintf(
				'cron_sync_all: %d attempted, %d reconciled, %d partial, %.1fs',
				$seen, $done, $partial, microtime( true ) - $t0
			) );
		}
	}

	/**
	 * Users eligible for the Graph sweep, OLDEST-SWEPT FIRST.
	 *
	 * A never-swept user sorts ahead of everyone (absent meta reads as 0), so a
	 * new account is picked up on the very next tick instead of whenever the
	 * default user order happens to reach it. Eligible: has the app, is not the
	 * read-only shared device.
	 *
	 * The full user list is still read, because a user with no cursor meta yet
	 * cannot be found by a meta-ordered query and must not be invisible. That
	 * read is cheap; the HTTP calls were always the expensive part.
	 *
	 * @return int[] user ids, at most MAX_USERS_PER_TICK.
	 */
	private static function sweep_candidates() {
		$ids = get_users( array(
			'fields' => 'ID',
			'number' => -1,
		) );

		$eligible = array();
		foreach ( (array) $ids as $id ) {
			$uid = (int) $id;
			if ( $uid <= 0 || ! zsch_user_has_access( $uid ) || zsch_user_is_read_only( $uid ) ) {
				continue;
			}
			$eligible[ $uid ] = (int) get_user_meta( $uid, self::SYNC_CURSOR_META, true );
		}

		return self::order_sweep( $eligible, self::MAX_USERS_PER_TICK );
	}

	/**
	 * May this tick START another mailbox?
	 *
	 * Pure, so the two bounds can be tested without a network or a clock.
	 *
	 * TWO bounds, because they are different properties:
	 *
	 *   (a) THROUGHPUT BUDGET — the tick has been productive; stop starting work
	 *       and let the next tick have the rest.
	 *   (b) HARD CEILING — never START a unit of work we cannot fund at its FULL
	 *       cap. This is the one people leave out, and leaving it out is why a
	 *       budget alone does not hold: `budget - elapsed` LOOSENS as calls get
	 *       faster, so a run of quick mailboxes lets the sweep start one more
	 *       right at the edge and that one blows the wall. A short-changed call
	 *       fails and is then wrongly charged to the work.
	 *
	 * @param float $elapsed seconds spent so far this tick.
	 * @param float $budget  SWEEP_BUDGET_S.
	 * @param float $ceiling PER_USER_CEILING_S.
	 * @return bool
	 */
	public static function can_start_user( $elapsed, $budget, $ceiling ) {
		$elapsed = (float) $elapsed;
		$budget  = (float) $budget;
		$ceiling = (float) $ceiling;
		if ( $elapsed >= $budget ) {
			return false;                       // (a)
		}
		return ( $budget - $elapsed ) >= $ceiling; // (b)
	}

	/**
	 * Order the eligible mailboxes for this tick: OLDEST SWEPT FIRST, capped.
	 *
	 * Pure. This ordering is what prevents starvation: a sweep that restarted
	 * from the same user order every tick would cut off the same people every
	 * time the request died partway. Sorting by the cursor guarantees rotation:
	 * whoever went longest without a sweep goes first, so a user can be delayed
	 * but never starved.
	 *
	 * A never-swept user has no meta and reads as 0, which sorts ahead of every
	 * real timestamp — so a brand-new account is picked up on the next tick
	 * rather than whenever the default user order happens to reach it.
	 *
	 * asort() preserves keys and is stable in PHP 8, so equal cursors keep their
	 * relative order instead of shuffling between ticks.
	 *
	 * @param array<int,int> $uid_to_cursor  user id => last sweep unix ts (0 = never).
	 * @param int            $max            per-tick cap.
	 * @return int[] user ids, oldest first, at most $max.
	 */
	public static function order_sweep( array $uid_to_cursor, $max ) {
		$max = (int) $max;
		if ( $max <= 0 || empty( $uid_to_cursor ) ) {
			return array();
		}
		asort( $uid_to_cursor, SORT_NUMERIC );
		return array_slice( array_map( 'intval', array_keys( $uid_to_cursor ) ), 0, $max );
	}

	/**
	 * Record that this user's mailbox was attempted on this tick.
	 *
	 * Deliberately a bare timestamp and not a success flag — see the note in
	 * cron_sync_all() about why the cursor must advance on failure too.
	 */
	private static function stamp_swept( $user_id ) {
		update_user_meta( (int) $user_id, self::SYNC_CURSOR_META, time() );
	}

	// ── Mapping helpers ────────────────────────────────────────────

	/**
	 * Convert a normalised appointment row into a Graph event body.
	 *
	 * @param array $appt
	 * @return array
	 */
	public static function appt_to_graph( array $appt, array $omit = array() ) {
		$tz = $appt['time_zone'] ?? ZSCH_Settings::default_tz();

		$body = array(
			'subject' => (string) ( $appt['title'] ?? '' ),
			'body'    => array(
				'contentType' => 'text',
				'content'     => (string) ( $appt['body'] ?? '' ),
			),
			'start'   => array(
				'dateTime' => self::utc_to_graph_local( $appt['start_utc'] ?? '', $tz ),
				'timeZone' => $tz,
			),
			'end'     => array(
				'dateTime' => self::utc_to_graph_local( $appt['end_utc'] ?? '', $tz ),
				'timeZone' => $tz,
			),
			'isAllDay' => ! empty( $appt['is_all_day'] ),
			'showAs'   => self::map_show_as( $appt['busy_status'] ?? 'busy' ),
		);

		/* Drop any field the caller says it does not own.
		 *
		 * A PATCH sends only what we mean to change; anything we omit keeps
		 * whatever the person set on it in Outlook ("PATCH, not PUT — send only
		 * the fields we own"). The live case is `body`: for an event we PULLED,
		 * our copy is Graph's truncated
		 * `bodyPreview`, and sending it back replaces real rich text with a
		 * preview of itself. See ZSCH_Appointments::push_update(). */
		foreach ( $omit as $field ) {
			unset( $body[ (string) $field ] );
		}

		if ( ! empty( $appt['location'] ) ) {
			$body['location'] = array( 'displayName' => (string) $appt['location'] );
		}

		// Attendees: stored as a JSON array of emails.
		if ( ! empty( $appt['attendees'] ) ) {
			$emails = is_array( $appt['attendees'] ) ? $appt['attendees'] : json_decode( (string) $appt['attendees'], true );
			if ( is_array( $emails ) ) {
				$att = array();
				foreach ( $emails as $email ) {
					if ( is_email( $email ) ) {
						$att[] = array(
							'emailAddress' => array( 'address' => $email ),
							'type'         => 'required',
						);
					}
				}
				if ( $att ) {
					$body['attendees'] = $att;
				}
			}
		}

		return $body;
	}

	/**
	 * Map our busy_status to Graph showAs.
	 */
	private static function map_show_as( $busy ) {
		switch ( $busy ) {
			case 'free':      return 'free';
			case 'tentative': return 'tentative';
			case 'oof':       return 'oof';
			default:          return 'busy';
		}
	}

	/**
	 * Convert a stored UTC datetime ('Y-m-d H:i:s') to the wall-clock string in
	 * $tz that Graph expects alongside an explicit timeZone.
	 *
	 * @param string $utc
	 * @param string $tz IANA
	 * @return string  e.g. 2026-06-14T14:00:00
	 */
	public static function utc_to_graph_local( $utc, $tz ) {
		if ( '' === $utc ) {
			return '';
		}
		try {
			$dt = new DateTime( $utc, new DateTimeZone( 'UTC' ) );
			$dt->setTimezone( new DateTimeZone( $tz ) );
			return $dt->format( 'Y-m-d\TH:i:s' );
		} catch ( Exception $e ) {
			return '';
		}
	}

	/**
	 * Convert a Graph dateTimeTimeZone (we requested UTC) to our stored UTC
	 * 'Y-m-d H:i:s'.
	 *
	 * @param array $graph_dt  { dateTime, timeZone }
	 * @return string
	 */
	public static function graph_to_utc( $graph_dt ) {
		if ( empty( $graph_dt['dateTime'] ) ) {
			return '';
		}
		$tz = $graph_dt['timeZone'] ?? 'UTC';
		try {
			$dt = new DateTime( $graph_dt['dateTime'], new DateTimeZone( self::normalise_tz( $tz ) ) );
			$dt->setTimezone( new DateTimeZone( 'UTC' ) );
			return $dt->format( 'Y-m-d H:i:s' );
		} catch ( Exception $e ) {
			return '';
		}
	}

	/**
	 * Graph sometimes returns Windows time-zone names ("Pacific Standard
	 * Time"). We requested UTC, so the common case is fine; map the few we
	 * might still see, else fall back to UTC.
	 */
	private static function normalise_tz( $tz ) {
		$map = array(
			'UTC'                   => 'UTC',
			'Pacific Standard Time' => 'America/Los_Angeles',
			'Mountain Standard Time'=> 'America/Denver',
			'Central Standard Time' => 'America/Chicago',
			'Eastern Standard Time' => 'America/New_York',
		);
		if ( isset( $map[ $tz ] ) ) {
			return $map[ $tz ];
		}
		// If it's already a valid IANA id, use it; otherwise UTC.
		try {
			new DateTimeZone( $tz );
			return $tz;
		} catch ( Exception $e ) {
			return 'UTC';
		}
	}

	// ── Response + result shaping ──────────────────────────────────

	private static function handle_event_write_response( $resp, $op ) {
		if ( is_wp_error( $resp ) ) {
			return self::fail( 'http', $resp->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $resp );
		$json = json_decode( wp_remote_retrieve_body( $resp ), true );

		if ( $code < 200 || $code >= 300 ) {
			$msg = is_array( $json ) && isset( $json['error']['message'] ) ? $json['error']['message'] : "HTTP {$code}";
			self::log( "{$op} error: {$msg}" );
			return self::fail( 'http', "Graph {$op} failed: {$msg}", array( 'status' => $code ) );
		}

		// PATCH may return 200 with body; create returns 201 with body.
		$etag = '';
		if ( is_array( $json ) ) {
			$etag = $json['@odata.etag'] ?? '';
		}
		return array(
			'success'        => true,
			'skipped'        => false,
			'error'          => '',
			'graph_event_id' => is_array( $json ) ? ( $json['id'] ?? '' ) : '',
			'ical_uid'       => is_array( $json ) ? ( $json['iCalUId'] ?? '' ) : '',
			'etag'           => $etag,
		);
	}

	private static function skipped( array $extra = array() ) {
		return array_merge( array( 'success' => true, 'skipped' => true, 'error' => '' ), $extra );
	}

	private static function fail( $code, $message, array $extra = array() ) {
		return array_merge( array( 'success' => false, 'skipped' => false, 'error' => $message, 'error_code' => $code ), $extra );
	}

	private static function log( $msg ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( 'ZSCH_Graph: ' . $msg );
		}
	}
}
