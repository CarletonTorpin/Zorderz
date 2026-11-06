<?php
/**
 * ZSCH_Appointments — the appointment model (wp_zsch_appointments).
 *
 * Local source of truth for events. Every write also pushes to Microsoft Graph
 * (when sync is active) and records the mapping in wp_zsch_graph_map. The
 * cron puller calls reconcile_from_graph() to fold Outlook-side changes back in.
 *
 * SCOPES:
 *   'personal' — belongs to one user; visible to that user + admins; synced to
 *                that user's Outlook calendar.
 *   'shared'   — the team calendar; visible to all who can see the scheduler;
 *                synced to the OWNER's Outlook (whoever created it) so it shows
 *                on a real mailbox, and colour-coded by owner in the UI.
 *
 * All times stored UTC. The caller hands wall-clock + tz; we convert.
 *
 * IDENTITY: every row carries an RFC 5545 `uid` minted once and never
 * regenerated, and a `sequence` that advances on a significant revision only
 * (ZSCH_Identity). Every write appends to wp_zsch_change_log with its source.
 *
 * RECURRENCE: a series is ONE row carrying a rule (`rrule`, `rdate`, `exdate`),
 * expanded on read (ZSCH_Series). Moving or cancelling one occurrence writes an
 * OVERRIDE row keyed by (series_uid, recurrence_id); the scoped writes live in
 * ZSCH_Series_Write. Every read-modify-write of a series' shared columns holds
 * the series lock (ZSCH_Series_Write::acquire()).
 *
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZSCH_Appointments {

	private static function table() {
		global $wpdb;
		return $wpdb->prefix . 'zsch_appointments';
	}

	private static function map_table() {
		global $wpdb;
		return $wpdb->prefix . 'zsch_graph_map';
	}

	/**
	 * Validate the recurrence fields on a write, or refuse the write.
	 *
	 * REFUSES rather than sanitises. A rule that cannot be expanded is an
	 * appointment that silently does not happen, and the failure would not
	 * surface until somebody opened a month that should have had it.
	 *
	 * @param array  $data
	 * @param string $start_utc
	 * @param string $end_utc
	 * @param string $tz
	 * @return array|WP_Error {rrule,rdate,exdate,series_until_utc}
	 */
	public static function recurrence_fields( array $data, $start_utc, $end_utc, $tz ) {
		$blank = array( 'rrule' => '', 'rdate' => null, 'exdate' => null, 'series_until_utc' => null );

		$rule = trim( (string) ( $data['rrule'] ?? '' ) );
		if ( '' === $rule ) {
			return $blank;
		}
		if ( ! class_exists( 'ZSCH_RRule' ) || ! class_exists( 'ZSCH_Series' ) ) {
			return new WP_Error( 'zsch_no_engine', 'Recurrence is not available on this install.', array( 'status' => 500 ) );
		}

		$p = ZSCH_RRule::parse( $rule, true );
		if ( empty( $p['ok'] ) ) {
			return new WP_Error( 'zsch_bad_rrule', $p['error'], array( 'status' => 400, 'code' => $p['code'] ) );
		}

		/* A span of more than a day that also repeats is a multi-day JOB modelled
		 * as recurrence, and the two drift apart the first time somebody
		 * reschedules one day. Refused; a multi-day job is linked events sharing
		 * a job_id. */
		$dur = class_exists( 'ZSCH_Duration' ) ? ZSCH_Duration::between_exact( $start_utc, $end_utc ) : '';
		if ( class_exists( 'ZSCH_Recurrence' ) ) {
			$g = ZSCH_Recurrence::guard_multiday( $dur, $rule );
			if ( empty( $g['ok'] ) ) {
				return new WP_Error( 'zsch_multiday_is_a_job', $g['error'], array( 'status' => 400, 'code' => $g['code'] ) );
			}
		}

		// Canonicalise through the parser so the stored value is one this engine accepts.
		$canon = ZSCH_RRule::to_string( $p );
		$local = ZSCH_Series::utc_to_local( $start_utc, $tz );
		$until = ZSCH_Series::compute_until( $local, $tz, $canon );

		return array(
			'rrule'            => $canon,
			'rdate'            => self::sanitize_date_list( $data['rdate'] ?? '' ),
			'exdate'           => self::sanitize_date_list( $data['exdate'] ?? '' ),
			'series_until_utc' => $until,
		);
	}

	/**
	 * Normalise an EXDATE/RDATE list to newline-separated local wall clock.
	 *
	 * Values that are not 'Y-m-d H:i:s' are DROPPED, not coerced. An EXDATE that
	 * does not match an instance exactly cancels nothing (RFC 5545 section 3.8.5.1), so
	 * a coerced value would look like a cancellation and not be one.
	 *
	 * @param mixed $raw
	 * @return string|null
	 */
	public static function sanitize_date_list( $raw ) {
		if ( is_array( $raw ) ) {
			$raw = implode( "\n", $raw );
		}
		$out = array();
		foreach ( preg_split( '/[\r\n,]+/', (string) $raw ) as $v ) {
			$v = trim( $v );
			if ( 1 === preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $v ) ) {
				$out[] = $v;
			}
		}
		$out = array_values( array_unique( $out ) );
		sort( $out );
		return empty( $out ) ? null : implode( "\n", $out );
	}

	/**
	 * Load the override rows belonging to any series master in $rows.
	 *
	 * Keyed on the SERIES, not on the window. An override that was moved outside
	 * the window still has to be known about, because its existence is what
	 * SUPPRESSES the generated instance at its original slot. Fetching by window
	 * instead would leave a ghost appointment at a time the job is not happening.
	 * Costs nothing when the window holds no series: the query is skipped.
	 *
	 * @param array $rows
	 * @return array $rows plus any overrides not already in it.
	 */
	private static function attach_overrides( array $rows ) {
		global $wpdb;
		$uids = array();
		$have = array();
		foreach ( $rows as $r ) {
			$have[ (int) ( $r['id'] ?? 0 ) ] = true;
			if ( '' !== trim( (string) ( $r['rrule'] ?? '' ) ) && ! empty( $r['uid'] ) ) {
				$uids[] = (string) $r['uid'];
			}
		}
		$uids = array_values( array_unique( $uids ) );
		if ( empty( $uids ) ) {
			return $rows;
		}
		$t  = self::table();
		$ph = implode( ',', array_fill( 0, count( $uids ), '%s' ) );
		$ov = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore
			"SELECT * FROM {$t} WHERE deleted_at IS NULL AND series_uid IN ({$ph}) LIMIT 2000", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $t is $wpdb->prefix plus a fixed name; $ph is a list of %s placeholders.
			$uids
		), ARRAY_A );
		foreach ( (array) $ov as $o ) {
			if ( empty( $have[ (int) $o['id'] ] ) ) {
				$rows[] = $o;
			}
		}
		return $rows;
	}

	/**
	 * The window condition every windowed read uses, with four placeholders in
	 * this order: window end, window start, window end, window start.
	 *
	 * Two arms. An ordinary appointment overlaps the window when it starts
	 * before the end and ends after the start. A series is one row carrying a
	 * rule, so it is loaded when it MIGHT reach the window (it began before the
	 * window ends and has not already finished) and expansion decides what
	 * actually lands inside. See query() for the bound on `series_until_utc`.
	 *
	 * @param string $alias table alias with its dot (e.g. 'a.'), or '' for none.
	 * @return string SQL fragment.
	 */
	public static function window_where( $alias = '' ) {
		$a = preg_replace( '/[^a-z_.]/i', '', (string) $alias );
		return "( ( {$a}start_utc < %s AND {$a}end_utc > %s )"
			. " OR ( {$a}rrule <> '' AND {$a}start_utc < %s"
			. " AND ( {$a}series_until_utc IS NULL"
			. " OR DATE_ADD( {$a}series_until_utc, INTERVAL ( GREATEST( TIMESTAMPDIFF( SECOND, {$a}start_utc, {$a}end_utc ), 0 ) + 86400 ) SECOND ) > %s ) ) )";
	}

	/**
	 * Turn a windowed row set into the occurrences that fall in the window.
	 *
	 * Overrides are fetched by series (attach_overrides()), then every master is
	 * expanded across the window with its overrides replacing the occurrences
	 * they stand for. Plain rows pass through. Every occurrence keeps the id of
	 * the row it came from (the master's, or the override's own).
	 *
	 * @param array  $rows      rows read with window_where().
	 * @param string $start_utc 'Y-m-d H:i:s'
	 * @param string $end_utc
	 * @return array[] occurrence rows, ordered by start.
	 */
	public static function expand_window( array $rows, $start_utc, $end_utc ) {
		$rows = self::attach_overrides( $rows );
		$mat  = class_exists( 'ZSCH_Series' )
			? ZSCH_Series::materialize( $rows, $start_utc, $end_utc )
			: array( 'rows' => $rows, 'truncated' => false );
		return $mat['rows'];
	}

	/**
	 * Fetch events visible to $viewer within a UTC window.
	 *
	 * Visibility:
	 *   - shared events: everyone.
	 *   - personal events: only the owner (admins may pass $all_personal=true via
	 *     a scope arg, but the default viewer call shows just their own).
	 *   - events the viewer is a participant of.
	 *
	 * Series are expanded into the occurrences that fall inside the window.
	 *
	 * @param int    $viewer_id
	 * @param string $start_utc 'Y-m-d H:i:s'
	 * @param string $end_utc
	 * @param array  $args { scope: 'all'|'personal'|'shared', owner_id?:int }
	 * @return array[] normalised rows
	 */
	public static function query( $viewer_id, $start_utc, $end_utc, array $args = array() ) {
		global $wpdb;
		$viewer_id = (int) $viewer_id;
		$scope     = $args['scope'] ?? 'all';
		$t         = self::table();

		$where  = array( 'deleted_at IS NULL' );
		$params = array();

		/* WINDOW, in two arms.
		 *
		 * An ordinary appointment overlaps the window when it starts before the
		 * end and ends after the start. A SERIES cannot be filtered that way: it
		 * is one row carrying a rule, and a weekly job created in January is
		 * relevant to a window in August while its row says January. So the
		 * second arm loads a series that MIGHT reach the window — it began before
		 * the window ends and has not already finished — and expansion decides
		 * what actually lands inside. `series_until_utc` is NULL for an unbounded
		 * rule, which is what keeps "every Monday, forever" visible.
		 *
		 * `series_until_utc` holds the START of the last occurrence, so the bound
		 * is widened by the row's own span plus a day (the longest an occurrence
		 * can run, stretched by any zone transition); otherwise the last
		 * occurrence would be dropped from a window that begins inside it.
		 * Over-inclusion costs one row read; expansion filters each occurrence on
		 * its real end. */
		$where[]  = self::window_where();
		$params[] = $end_utc;
		$params[] = $start_utc;
		$params[] = $end_utc;
		$params[] = $start_utc;

		$is_admin = self::viewer_is_admin( $viewer_id );

		// PARTICIPANT VISIBILITY. A participant SEES the event they are on — the
		// disclosure the add() gate authorizes, bounded to that party (never a
		// blanket public flip). Ids come straight from the DB (ints), so they are
		// embedded directly and safely — no user input touches this fragment.
		$part_or = '';
		if ( ! $is_admin && class_exists( 'ZSCH_Participants' ) && is_callable( array( 'ZSCH_Participants', 'appointment_ids_for_participant' ) ) ) {
			$part_ids = array_values( array_filter( array_map( 'intval', ZSCH_Participants::appointment_ids_for_participant( $viewer_id ) ) ) );
			if ( ! empty( $part_ids ) ) {
				$part_or = ' OR id IN (' . implode( ',', $part_ids ) . ')';
			}
		}

		if ( 'shared' === $scope ) {
			$where[] = "calendar_scope = 'shared'";
		} elseif ( 'personal' === $scope ) {
			$where[]  = "( ( calendar_scope = 'personal' AND owner_user_id = %d )$part_or )";
			$params[] = $viewer_id;
		} else {
			// 'all' — shared (everyone) + personal (own, or anyone if admin) +
			// events the viewer participates in.
			if ( $is_admin && ! empty( $args['owner_id'] ) ) {
				$where[]  = "( calendar_scope = 'shared' OR ( calendar_scope = 'personal' AND owner_user_id = %d ) )";
				$params[] = (int) $args['owner_id'];
			} elseif ( $is_admin ) {
				// admins see all personal + shared
				$where[] = "( calendar_scope = 'shared' OR calendar_scope = 'personal' )";
			} else {
				$where[]  = "( calendar_scope = 'shared' OR ( calendar_scope = 'personal' AND owner_user_id = %d )$part_or )";
				$params[] = $viewer_id;
			}
		}

		$sql  = "SELECT * FROM {$t} WHERE " . implode( ' AND ', $where ) . ' ORDER BY start_utc ASC LIMIT 1000';
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ); // phpcs:ignore
		$rows = $rows ?: array();

		/* SERIES EXPANSION. Overrides are fetched by series (attach_overrides()),
		 * then every master is expanded across the window with its overrides
		 * replacing the occurrences they stand for. */
		$rows = self::expand_window( $rows, $start_utc, $end_utc );

		/* One batched participant read for the whole page, not one per row: this
		 * returns up to 1000 rows and is the hottest read in the app. */
		$ids      = array_values( array_unique( array_map( 'intval', wp_list_pluck( $rows, 'id' ) ) ) );
		$part_map = ( class_exists( 'ZSCH_Participants' ) && method_exists( 'ZSCH_Participants', 'map_for' ) )
			? ZSCH_Participants::map_for( $ids )
			: array();

		$out = array();
		foreach ( $rows as $r ) {
			$out[] = self::shape_row( $r, $part_map[ (int) $r['id'] ] ?? array() );
		}
		return $out;
	}

	/**
	 * Create an appointment (and push to Graph if active).
	 *
	 * @param int   $actor_id  the user creating it.
	 * @param array $data      raw input: title, body, location, start_local,
	 *                         end_local, time_zone, is_all_day, calendar_scope,
	 *                         busy_status, owner_id?, attendees?[], rrule?,
	 *                         rdate?, exdate?, job_id?, client_request_id?
	 * @param array $origin    the handoff origin {ns, ref_id} — read from the
	 *                         signed intake TOKEN by the REST layer, NEVER from the
	 *                         request body. Empty for a plain create. It
	 *                         rides ONLY the fired zsch_appointment_created payload
	 *                         (so a contributor born-links the appointment); this
	 *                         model never resolves or trusts it itself.
	 * @return array|WP_Error { success, id?, appointment?, error?, graph? }
	 */
	public static function create( $actor_id, array $data, array $origin = array() ) {
		global $wpdb;
		$actor_id = (int) $actor_id;

		if ( ! zsch_user_can_write( $actor_id ) ) {
			return array( 'success' => false, 'error' => 'Read-only account cannot create events.' );
		}

		/* IDEMPOTENT CREATE. A caller opts in by sending `client_request_id` (a
		 * key it mints once, before the first attempt, and reuses on every
		 * retry), so a double tap, a phone resuming from the background or a
		 * proxy retry resolves to one appointment — and one event on the owner's
		 * real calendar — instead of two. Callers that send nothing are
		 * unaffected. This read is the FAST path for an obvious replay; the
		 * UNIQUE KEY (owner_user_id, client_request_id) is the guarantee for two
		 * requests arriving at once. */
		$request_id = self::sanitize_request_id( $data['client_request_id'] ?? '' );
		if ( '' !== $request_id ) {
			$prior = self::find_by_request_id( $actor_id, $request_id );
			if ( $prior ) {
				return self::replay_result( $prior );
			}
		}

		$scope = ( ( $data['calendar_scope'] ?? 'personal' ) === 'shared' ) ? 'shared' : 'personal';
		// Owner: personal events belong to the actor; shared events can name an
		// owner (defaults to actor). Non-admins can only own their own.
		$owner_id = $actor_id;
		if ( ! empty( $data['owner_id'] ) && self::viewer_is_admin( $actor_id ) ) {
			$owner_id = (int) $data['owner_id'];
		}

		$tz        = self::sanitize_tz( $data['time_zone'] ?? ZSCH_Settings::default_tz() );
		$all_day   = ! empty( $data['is_all_day'] );
		$start_utc = self::local_to_utc( $data['start_local'] ?? '', $tz, $all_day, false );
		$end_utc   = self::local_to_utc( $data['end_local'] ?? '', $tz, $all_day, true );

		// Say WHICH field is wrong, so the editor can tell the person.
		if ( '' === $start_utc ) {
			return array( 'success' => false, 'error' => 'That start date/time could not be read. Pick it again from the field.' );
		}
		if ( '' === $end_utc ) {
			return array( 'success' => false, 'error' => 'That end date/time could not be read. Pick it again from the field.' );
		}
		if ( strtotime( $end_utc ) <= strtotime( $start_utc ) ) {
			return array(
				'success' => false,
				'error'   => sprintf(
					'The end (%s) is not after the start (%s). Fix the Ends field and save again.',
					self::pretty_local( $end_utc, $tz, $all_day ),
					self::pretty_local( $start_utc, $tz, $all_day )
				),
			);
		}

		// Conflict check against the owner's EXTERNAL calendars. Policy 'warn'
		// (default): book anyway and return warnings for the UI/bot to surface.
		// Policy 'block': refuse. No-op (empty) when the feature is off or the
		// owner has no feeds.
		$conflicts = class_exists( 'ZSCH_Sync' ) ? ZSCH_Sync::conflicts_for( (int) $owner_id, $start_utc, $end_utc ) : array();
		if ( ! empty( $conflicts ) && 'block' === ZSCH_Settings::conncal_config()['conflict_policy'] ) {
			return array(
				'success'   => false,
				'error'     => 'That time conflicts with a connected calendar.',
				'conflicts' => self::shape_conflicts( $conflicts ),
			);
		}

		/* The unique key is (owner_user_id, client_request_id) and the owner is only
		 * resolved above, so re-check against the RESOLVED owner — an admin
		 * creating on somebody else's behalf keys to that owner. */
		if ( '' !== $request_id && $owner_id !== $actor_id ) {
			$prior = self::find_by_request_id( $owner_id, $request_id );
			if ( $prior ) {
				return self::replay_result( $prior );
			}
		}

		// Validate the recurrence fields BEFORE building the row, so a refusal is
		// returned instead of a half-written series.
		$recur = self::recurrence_fields( $data, $start_utc, $end_utc, $tz );
		if ( is_wp_error( $recur ) ) {
			return $recur;
		}

		$now = current_time( 'mysql', true );
		$row = array(
			'owner_user_id'     => $owner_id,
			'calendar_scope'    => $scope,
			'title'             => sanitize_text_field( $data['title'] ?? '' ),
			'body'              => sanitize_textarea_field( $data['body'] ?? '' ),
			'location'          => sanitize_text_field( $data['location'] ?? '' ),
			'start_utc'         => $start_utc,
			'end_utc'           => $end_utc,
			'is_all_day'        => $all_day ? 1 : 0,
			'time_zone'         => $tz,
			'status'            => 'confirmed',
			'busy_status'       => self::sanitize_busy( $data['busy_status'] ?? 'busy' ),
			'color'             => sanitize_text_field( $data['color'] ?? '' ),
			'attendees'         => self::sanitize_attendees( $data['attendees'] ?? array() ),
			'client_request_id' => ( '' !== $request_id ) ? $request_id : null,
			/* Identity, minted ONCE and never regenerated: the UID must survive
			 * every edit, reschedule, cancellation, export and sync round-trip
			 * (RFC 5545 section 3.8.4.7). */
			'uid'               => class_exists( 'ZSCH_Identity' )
				? ZSCH_Identity::mint_uid( ZSCH_Identity::uid_host() )
				: null,
			'sequence'          => 0,
			/* The span, as a duration. `end_utc` stays the indexed column every
			 * range query reads, DERIVED from this (ZSCH_Duration). */
			'duration'          => class_exists( 'ZSCH_Duration' )
				? ZSCH_Duration::between_exact( $start_utc, $end_utc )
				: '',
			'job_id'            => self::sanitize_job_id( $data['job_id'] ?? '' ),
			'rrule'             => $recur['rrule'],
			'rdate'             => $recur['rdate'],
			'exdate'            => $recur['exdate'],
			'series_until_utc'  => $recur['series_until_utc'],
			'recurrence_id'     => '',
			'series_uid'        => null,
			'last_modified'     => $now,
			'updated_by'        => $actor_id,
			'created_by'        => $actor_id,
			'created_at'        => $now,
			'updated_at'        => $now,
		);

		$ok = $wpdb->insert( self::table(), $row ); // phpcs:ignore
		if ( ! $ok ) {
			/* THE RACE, RESOLVED BY THE DATABASE. Two concurrent creates carrying
			 * the same key both got past the read above; one inserted, this one
			 * holds a duplicate-key error. The caller asked for one appointment
			 * and there is exactly one: return it. */
			if ( '' !== $request_id && self::is_duplicate_key_error( $wpdb->last_error ) ) {
				$prior = self::find_by_request_id( $owner_id, $request_id );
				if ( $prior ) {
					return self::replay_result( $prior );
				}
			}

			// Self-heal a missing table or column (file-overwrite update), then retry once.
			if ( function_exists( 'zsch_tables_exist' ) && ! zsch_tables_exist() ) {
				self::migrate_once();
				$ok = $wpdb->insert( self::table(), $row ); // phpcs:ignore
			}
			if ( ! $ok ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log( 'ZSCH_Appointments insert failed: ' . $wpdb->last_error );
				}
				return array( 'success' => false, 'error' => 'Could not save appointment. ' . ( $wpdb->last_error ? $wpdb->last_error : 'Please try again.' ) );
			}
		}
		$id = (int) $wpdb->insert_id;

		// Push to Graph (owner's mailbox). Non-fatal on failure — local row stands.
		$graph_result = self::push_create( $owner_id, array_merge( $row, array( 'id' => $id ) ) );

		// The change log's first row for this event. `source` answers "did a
		// person here do this, or did a sync?".
		if ( class_exists( 'ZSCH_Identity' ) ) {
			ZSCH_Identity::log( $id, (string) ( $row['uid'] ?? '' ), array(
				'created' => array( '', (string) $row['start_utc'] ),
			), array(
				'actor_id'        => $actor_id,
				'actor_type'      => $actor_id > 0 ? 'user' : 'system',
				'source'          => self::source_of( $data ),
				'sequence_before' => 0,
				'sequence_after'  => 0,
			) );
		}

		// LIFECYCLE: announce the created appointment so a contributor can
		// born-link it to its origin (a Project). The origin comes from the signed
		// intake token via the REST layer — NEVER the request body.
		self::fire_lifecycle( 'created', $id, $actor_id, $origin );

		return array(
			'success'     => true,
			'id'          => $id,
			'appointment' => self::shape_row( self::get_raw( $id ) ),
			'graph'       => $graph_result,
			// Non-empty when the booked time overlaps the owner's external
			// calendars (policy 'warn'): booked anyway, surfaced to the UI.
			'warnings'    => ! empty( $conflicts ) ? self::shape_conflicts( $conflicts ) : array(),
		);
	}

	/**
	 * Run the schema self-heal ONCE, whoever asks.
	 *
	 * dbDelta() is idempotent but its diff and its ALTER are not atomic: two
	 * requests arriving together each read the pre-migration table and both try
	 * to add the same column. Worse, a request can read a HALF-migrated schema.
	 * A MySQL GET_LOCK makes it single-flight, and RE-CHECKING inside the lock
	 * means the waiters find the table already correct and do nothing. The lock
	 * name carries the table prefix and database name because GET_LOCK is
	 * server-wide: two installs in one MySQL must not block each other.
	 *
	 * @return bool whether this call ran the migrations.
	 */
	private static function migrate_once() {
		global $wpdb;
		$lock = 'zsch_migrate_' . md5( (string) $wpdb->prefix . ( defined( 'DB_NAME' ) ? DB_NAME : '' ) );
		$got  = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $lock, 10 ) );
		if ( '1' !== (string) $got ) {
			return false;   // somebody else is doing this work; the caller retries against their result
		}
		try {
			if ( function_exists( 'zsch_tables_exist' ) && zsch_tables_exist() ) {
				return false;   // a waiter: the winner already fixed it
			}
			// EVERY migration that owns a column this class writes belongs here.
			foreach ( array( '1.0.0', '1.6.0', '1.9.0', '1.10.0' ) as $v ) {
				$file = ZSCH_PLUGIN_DIR . 'db/migrate-' . $v . '.php';
				if ( ! file_exists( $file ) ) {
					continue;
				}
				require_once $file;
				$class = 'ZSCH_Migrate_' . str_replace( '.', '_', $v );
				if ( class_exists( $class ) ) {
					call_user_func( array( $class, 'run' ) );
				}
			}
			return true;
		} finally {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
	}

	/**
	 * Normalise a job grouping key. PURE.
	 *
	 * This is what a MULTI-DAY JOB is modelled with — a four-day job is one job
	 * and up to four linked events sharing this key, NOT a recurring event that
	 * happens four times. Linked events are independent rows that can be moved,
	 * crewed and completed separately. Borrowed from RFC 9253's REFID.
	 *
	 * @param mixed $raw
	 * @return string|null null when absent, so the column stays NULL.
	 */
	public static function sanitize_job_id( $raw ) {
		if ( ! is_string( $raw ) ) {
			return null;
		}
		$raw = trim( $raw );
		if ( '' === $raw || strlen( $raw ) > 64 ) {
			return null;
		}
		return preg_match( '/^[A-Za-z0-9._:\-]+$/', $raw ) ? $raw : null;
	}

	/**
	 * Where did this write come from? The change log's `source` column. A caller
	 * may declare it; anything undeclared and actor-less is a system write (the
	 * Graph puller, cron), and anything undeclared with a real actor came
	 * through the UI.
	 *
	 * @param array $data
	 * @return string
	 */
	private static function source_of( array $data ) {
		if ( ! empty( $data['_source'] ) ) {
			return (string) $data['_source'];
		}
		return ( get_current_user_id() > 0 ) ? 'ui' : 'system';
	}

	/**
	 * Normalise a caller-supplied idempotency key. PURE.
	 *
	 * Accepts a UUID or any opaque token of 8–64 safe characters. Longer keys are
	 * REJECTED rather than truncated, because a truncated key can collide with a
	 * DIFFERENT request — the one failure an idempotency layer must never have.
	 *
	 * @param mixed $raw
	 * @return string '' when absent or unusable.
	 */
	public static function sanitize_request_id( $raw ) {
		if ( ! is_string( $raw ) ) {
			return '';
		}
		$raw = trim( $raw );
		if ( '' === $raw || strlen( $raw ) > 64 || strlen( $raw ) < 8 ) {
			return '';
		}
		return preg_match( '/^[A-Za-z0-9._:-]+$/', $raw ) ? $raw : '';
	}

	/**
	 * Is this wpdb error a unique-constraint violation? PURE.
	 *
	 * MySQL 1062 / SQLSTATE 23000, matched on the text because wpdb does not
	 * surface the driver code. Deliberately narrow: any other error is a real
	 * failure and must not be mistaken for "already created".
	 *
	 * @param string $error $wpdb->last_error
	 * @return bool
	 */
	public static function is_duplicate_key_error( $error ) {
		$error = (string) $error;
		if ( '' === $error ) {
			return false;
		}
		return ( false !== stripos( $error, 'duplicate entry' ) )
			|| ( false !== stripos( $error, '1062' ) );
	}

	/**
	 * The appointment a previous attempt with this key already created.
	 *
	 * Soft-deleted rows are excluded: if the person deleted it, a retry must not
	 * resurrect it. The unique index still holds the key, so such a retry fails
	 * closed rather than creating a ghost.
	 *
	 * @param int    $owner_id
	 * @param string $request_id
	 * @return array|null raw row
	 */
	private static function find_by_request_id( $owner_id, $request_id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare(
			'SELECT * FROM ' . self::table() . ' WHERE owner_user_id = %d AND client_request_id = %s AND deleted_at IS NULL LIMIT 1', // phpcs:ignore
			(int) $owner_id,
			(string) $request_id
		), ARRAY_A );
		return $row ?: null;
	}

	/**
	 * The result a replayed create returns: the SAME shape a first-time create
	 * returns, so a client that retried needs no special path. `replayed` is
	 * additive and exists for logs and tests, not for branching.
	 *
	 * @param array $row raw appointment row
	 * @return array
	 */
	private static function replay_result( array $row ) {
		$id = (int) $row['id'];
		return array(
			'success'     => true,
			'id'          => $id,
			'appointment' => self::shape_row( $row ),
			'graph'       => array( 'skipped' => true ),
			'warnings'    => array(),
			'replayed'    => true,
		);
	}

	/**
	 * Update an appointment (and patch Graph).
	 *
	 * A SERIES row is updated under its series lock: update_locked() reads
	 * `rrule`, `exdate`, `rdate` and `sequence` and writes them back, and a
	 * concurrent "cancel this occurrence" must not be overwritten by it. An
	 * ordinary appointment shares nothing and takes no lock, so no save on the
	 * calendar waits for a contention that cannot happen.
	 *
	 * @param int   $actor_id
	 * @param int   $id
	 * @param array $data  same shape as create (partial allowed).
	 * @return array
	 */
	public static function update( $actor_id, $id, array $data ) {
		$actor_id = (int) $actor_id;
		$id       = (int) $id;

		$existing = self::get_raw( $id );
		if ( ! $existing ) {
			return array( 'success' => false, 'error' => 'Not found.' );
		}
		if ( ! self::can_modify( $actor_id, $existing ) ) {
			return array( 'success' => false, 'error' => 'Not allowed.' );
		}

		$lock = self::series_lock_for( $existing );
		if ( '' === $lock ) {
			return self::update_locked( $actor_id, $id, $data, $existing );
		}
		$got = ZSCH_Series_Write::acquire( $lock );
		if ( is_wp_error( $got ) ) {
			return self::busy_response( $got );
		}
		try {
			// RE-READ inside the lock: acting on the snapshot read before it is the defect, not the fix.
			$fresh = self::get_raw( $id );
			return self::update_locked( $actor_id, $id, $data, $fresh ?: $existing );
		} finally {
			ZSCH_Series_Write::release( $lock );
		}
	}

	/**
	 * Which single-flight lock covers writes to this row, if any.
	 *
	 * An OVERRIDE modifies its master's `exdate` and `sequence`, so it locks the
	 * MASTER. A series MASTER locks itself. Anything else takes none.
	 *
	 * @param array $row raw appointment row.
	 * @return string '' when this row needs no lock.
	 */
	private static function series_lock_for( array $row ) {
		if ( ! class_exists( 'ZSCH_Series_Write' ) ) {
			return '';
		}
		$series_uid = trim( (string) ( $row['series_uid'] ?? '' ) );
		if ( '' !== $series_uid ) {
			return ZSCH_Series_Write::series_lock( array( 'uid' => $series_uid, 'id' => 0 ) );
		}
		if ( '' !== trim( (string) ( $row['rrule'] ?? '' ) ) ) {
			return ZSCH_Series_Write::series_lock( $row );
		}
		return '';
	}

	/**
	 * The refusal for a busy series lock, carrying `code` as well as the
	 * sentence: the REST layer maps codes to statuses, and a caller matching on
	 * prose breaks when the wording improves.
	 *
	 * @param WP_Error $err
	 * @return array
	 */
	private static function busy_response( $err ) {
		return array(
			'success' => false,
			'code'    => $err->get_error_code(),
			'error'   => $err->get_error_message(),
			'status'  => 409,
		);
	}

	/**
	 * The body of update(), running with the series lock held when one applies.
	 * $existing was read INSIDE the lock and must not be re-read behind it.
	 *
	 * @param int   $actor_id
	 * @param int   $id
	 * @param array $data
	 * @param array $existing raw row, read under the lock.
	 * @return array
	 */
	private static function update_locked( $actor_id, $id, array $data, array $existing ) {
		global $wpdb;

		$tz = self::sanitize_tz( $data['time_zone'] ?? $existing['time_zone'] );

		$update = array( 'updated_at' => current_time( 'mysql', true ) );
		if ( isset( $data['title'] ) )       { $update['title']    = sanitize_text_field( $data['title'] ); }
		if ( isset( $data['body'] ) )        { $update['body']     = sanitize_textarea_field( $data['body'] ); }
		if ( isset( $data['location'] ) )    { $update['location'] = sanitize_text_field( $data['location'] ); }
		if ( isset( $data['busy_status'] ) ) { $update['busy_status'] = self::sanitize_busy( $data['busy_status'] ); }
		if ( isset( $data['color'] ) )       { $update['color']    = sanitize_text_field( $data['color'] ); }
		if ( isset( $data['time_zone'] ) )   { $update['time_zone'] = $tz; }
		/* isset(), NOT array_key_exists(): ZSCH_REST::event_input() always emits
		 * this key, null when the request carried none. A PATCH that simply omits
		 * `attendees` must not wipe the stored list. */
		if ( isset( $data['attendees'] ) )   { $update['attendees'] = self::sanitize_attendees( $data['attendees'] ); }
		if ( isset( $data['is_all_day'] ) )  { $update['is_all_day'] = ! empty( $data['is_all_day'] ) ? 1 : 0; }

		// All-day is whatever the patch says, else whatever it already was.
		$all_day = isset( $data['is_all_day'] ) ? ! empty( $data['is_all_day'] ) : ! empty( $existing['is_all_day'] );

		if ( isset( $data['start_local'] ) ) {
			$s = self::local_to_utc( $data['start_local'], $tz, $all_day, false );
			if ( '' === $s ) { return array( 'success' => false, 'error' => 'That start date/time could not be read. Pick it again from the field.' ); }
			$update['start_utc'] = $s;
		}
		if ( isset( $data['end_local'] ) ) {
			$e = self::local_to_utc( $data['end_local'], $tz, $all_day, true );
			if ( '' === $e ) { return array( 'success' => false, 'error' => 'That end date/time could not be read. Pick it again from the field.' ); }
			$update['end_utc'] = $e;
		}

		$start_check = $update['start_utc'] ?? $existing['start_utc'];
		$end_check   = $update['end_utc'] ?? $existing['end_utc'];
		if ( strtotime( $end_check ) <= strtotime( $start_check ) ) {
			return array(
				'success' => false,
				'error'   => sprintf(
					'The end (%s) is not after the start (%s). Fix the Ends field and save again.',
					self::pretty_local( $end_check, $tz, $all_day ),
					self::pretty_local( $start_check, $tz, $all_day )
				),
			);
		}

		// Keep `duration` in step with the bounds this write produces, so the two
		// columns cannot disagree.
		if ( class_exists( 'ZSCH_Duration' )
			&& ( isset( $update['start_utc'] ) || isset( $update['end_utc'] ) ) ) {
			$update['duration'] = ZSCH_Duration::between_exact( $start_check, $end_check );
		}
		if ( isset( $data['job_id'] ) ) {
			$update['job_id'] = self::sanitize_job_id( $data['job_id'] );
		}

		/* RECOMPUTE THE SERIES' END whenever anything it derives from moves: the
		 * rule, the start it counts from, or the zone the count happens in.
		 * `series_until_utc` is a hard SQL exclusion in query(), so a stale value
		 * makes a live series vanish from every view. Through recurrence_fields(),
		 * so there is one implementation of "what does this rule mean". */
		$recur_touched = isset( $data['rrule'] ) || isset( $data['rdate'] ) || isset( $data['exdate'] );
		$anchor_moved  = isset( $update['start_utc'] ) || isset( $update['time_zone'] );
		if ( $recur_touched || ( $anchor_moved && '' !== trim( (string) ( $existing['rrule'] ?? '' ) ) ) ) {
			$recur = self::recurrence_fields(
				array(
					'rrule'  => $data['rrule']  ?? ( $existing['rrule']  ?? '' ),
					'rdate'  => $data['rdate']  ?? ( $existing['rdate']  ?? '' ),
					'exdate' => $data['exdate'] ?? ( $existing['exdate'] ?? '' ),
				),
				$start_check,
				$end_check,
				$tz
			);
			if ( is_wp_error( $recur ) ) {
				return array( 'success' => false, 'error' => $recur->get_error_message() );
			}
			// Only the rule fields the caller sent are written back; the derived end always is.
			if ( $recur_touched ) {
				$update['rrule']  = $recur['rrule'];
				$update['rdate']  = $recur['rdate'];
				$update['exdate'] = $recur['exdate'];
			}
			$update['series_until_utc'] = $recur['series_until_utc'];
		}

		/* A "FOR ALL" EDIT KEEPS ITS OVERRIDES. Moving the series' time, changing
		 * its zone or its BYDAY changes every generated recurrence_id; the override
		 * rows and the EXDATE list keyed to the old ones are re-keyed, detached or
		 * retired. Planned BEFORE the write, under the series lock, so an edit whose
		 * occurrences cannot be checked is refused with nothing written. The EXDATE
		 * half goes into the master's own UPDATE; the override rows are written
		 * after it, in one transaction with it (ZSCH_Series_Write::plan_rekey()). */
		$series_plan = null;
		if ( class_exists( 'ZSCH_Series_Write' ) && method_exists( 'ZSCH_Series_Write', 'plan_series_edit' )
			&& '' !== trim( (string) ( $existing['rrule'] ?? '' ) ) ) {
			$series_plan = ZSCH_Series_Write::plan_series_edit( $existing, array_merge( $existing, $update ) );
			if ( is_wp_error( $series_plan ) ) {
				return array(
					'success' => false,
					'code'    => $series_plan->get_error_code(),
					'error'   => $series_plan->get_error_message(),
					'status'  => (int) ( ( (array) $series_plan->get_error_data() )['status'] ?? 400 ),
				);
			}
			if ( is_array( $series_plan ) && ! empty( $series_plan['exdate_changed'] ) ) {
				$update['exdate'] = '' !== $series_plan['exdate'] ? $series_plan['exdate'] : null;
			}
		}

		/* SEQUENCE (RFC 5546 section 2.1.4): bumped ONLY on a significant revision
		 * (start, end, duration, recurrence, status). Bumping it for a typo in the
		 * notes resets participation in some clients; never bumping it makes
		 * Google and Outlook discard the update as a stale duplicate. */
		$seq_before = (int) ( $existing['sequence'] ?? 0 );
		$seq_after  = $seq_before;
		if ( class_exists( 'ZSCH_Identity' ) && ZSCH_Identity::needs_sequence_bump( $existing, $update ) ) {
			$seq_after               = $seq_before + 1;
			$update['sequence']      = $seq_after;
			$update['last_modified'] = current_time( 'mysql', true );
		}
		$update['updated_by'] = $actor_id;

		// Capture the diff BEFORE the write — afterwards there is nothing to compare.
		$changes = array();
		if ( class_exists( 'ZSCH_Identity' ) ) {
			$changes = ZSCH_Identity::diff(
				$existing,
				$update,
				array_merge( ZSCH_Identity::SIGNIFICANT, ZSCH_Identity::TRACKED )
			);
		}

		/* ONE TRANSACTION for the master and the rows keyed to it, only when there
		 * are such rows to move: an ordinary save is a single UPDATE as before.
		 * Atomic on InnoDB; on a non-transactional engine the statements still run
		 * in this order and a failure is still reported, never swallowed. An
		 * exception inside the transaction rolls it back and answers, and the
		 * caller's finally still releases the lock. */
		$rows_work = is_array( $series_plan ) && ZSCH_Series_Write::plan_has_row_work( $series_plan );
		if ( $rows_work ) {
			$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore
		}

		try {
			$wrote = $wpdb->update( self::table(), $update, array( 'id' => $id ) ); // phpcs:ignore

			$rekey = array( 'overrides_rekeyed' => 0, 'overrides_detached' => 0, 'overrides_retired' => 0, 'overrides_retimed' => 0, 'buried_rekeyed' => 0, 'failed' => 0 );
			if ( $rows_work ) {
				$rekey = ( false === $wrote )
					? array_merge( $rekey, array( 'failed' => 1 ) )
					: ZSCH_Series_Write::apply_rekey_plan( $series_plan, (string) $existing['uid'], (string) $existing['uid'], $actor_id, $id );
				if ( $rekey['failed'] > 0 ) {
					$wpdb->query( 'ROLLBACK' ); // phpcs:ignore
					$still  = self::get_raw( $id );
					$undone = $still && (string) $still['start_utc'] === (string) $existing['start_utc']
						&& (string) $still['end_utc'] === (string) $existing['end_utc']
						&& (string) $still['rrule'] === (string) $existing['rrule']
						&& (string) $still['time_zone'] === (string) $existing['time_zone'];
					return array(
						'success'          => false,
						'code'             => 'zsch_series_edit_partial',
						'error'            => $undone
							? 'The series was NOT changed: its moved or cancelled occurrences could not be carried over to the new times, so nothing was saved. Try again; if it keeps happening, change the occurrences one at a time.'
							: 'The series was changed, but some of its moved or cancelled occurrences could not be carried over and may now appear twice. Reload the calendar and check them.',
						'overrides_failed' => (int) $rekey['failed'],
					);
				}
			}

			if ( ! empty( $changes ) && class_exists( 'ZSCH_Identity' ) ) {
				ZSCH_Identity::log( $id, (string) ( $existing['uid'] ?? '' ), $changes, array(
					'actor_id'        => $actor_id,
					'actor_type'      => $actor_id > 0 ? 'user' : 'system',
					'source'          => self::source_of( $data ),
					'sequence_before' => $seq_before,
					'sequence_after'  => $seq_after,
				) );
			}
			if ( $rows_work ) {
				$wpdb->query( 'COMMIT' ); // phpcs:ignore
			}
		} catch ( \Throwable $e ) {
			if ( ! $rows_work ) {
				throw $e;   // no transaction of ours is open
			}
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore
			error_log( 'Zorderz Scheduler update(): rolled back a series edit after an exception: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
			$still  = self::get_raw( $id );
			$undone = $still && (string) $still['start_utc'] === (string) $existing['start_utc']
				&& (string) $still['end_utc'] === (string) $existing['end_utc']
				&& (string) $still['rrule'] === (string) $existing['rrule']
				&& (string) $still['time_zone'] === (string) $existing['time_zone'];
			return array(
				'success' => false,
				'code'    => 'zsch_series_edit_failed',
				'error'   => $undone
					? 'The series was NOT changed: something went wrong while saving it, and everything was undone. Try again.'
					: 'Something went wrong while saving the series, and it may be partly changed. Reload the calendar and check it.',
				'status'  => 500,
			);
		}

		$merged       = array_merge( $existing, $update );
		$graph_result = self::push_update( (int) $existing['owner_user_id'], $merged );

		// LIFECYCLE (drift): the resolver re-reads get_raw() and refreshes any
		// cached job date against this edit — so the calendar and the container
		// never drift. int id is the first arg (the drift subscriber's signature).
		self::fire_lifecycle( 'updated', $id, $actor_id );

		$res = array(
			'success'     => true,
			'id'          => $id,
			'appointment' => self::shape_row( self::get_raw( $id ) ),
			'graph'       => $graph_result,
		);
		/* What happened to the occurrences, said on every series save, zeros
		 * included: "nothing needed moving" and "nobody checked" must not look the
		 * same to a caller. */
		if ( '' !== trim( (string) ( $existing['rrule'] ?? '' ) ) ) {
			$res['overrides_rekeyed']  = (int) $rekey['overrides_rekeyed'];
			$res['overrides_detached'] = (int) $rekey['overrides_detached'];
			$res['overrides_retired']  = (int) $rekey['overrides_retired'];
			$res['overrides_retimed']  = (int) $rekey['overrides_retimed'];
			$res['exdates_rekeyed']    = is_array( $series_plan ) ? (int) $series_plan['exdates_rekeyed'] : 0;
			$res['exdates_dropped']    = is_array( $series_plan ) ? (int) $series_plan['exdates_dropped'] : 0;
		}
		return $res;
	}

	/**
	 * Soft-delete an appointment (and delete from Graph).
	 *
	 * A series row is deleted under its series lock: deleting an OVERRIDE
	 * appends to the master's EXDATE and bumps its SEQUENCE, and deleting a
	 * MASTER cascades to its overrides. Both are read-modify-writes of state
	 * the scoped writes also change.
	 *
	 * @param int $actor_id
	 * @param int $id
	 * @return array
	 */
	public static function delete( $actor_id, $id ) {
		$id       = (int) $id;
		$existing = self::get_raw( $id );
		if ( ! $existing ) {
			return array( 'success' => false, 'error' => 'Not found.' );
		}
		if ( ! self::can_modify( (int) $actor_id, $existing ) ) {
			return array( 'success' => false, 'error' => 'Not allowed.' );
		}

		$lock = self::series_lock_for( $existing );
		if ( '' === $lock ) {
			return self::delete_locked( $actor_id, $id, $existing );
		}
		$got = ZSCH_Series_Write::acquire( $lock );
		if ( is_wp_error( $got ) ) {
			return self::busy_response( $got );
		}
		try {
			$fresh = self::get_raw( $id );
			return self::delete_locked( $actor_id, $id, $fresh ?: $existing );
		} finally {
			ZSCH_Series_Write::release( $lock );
		}
	}

	/**
	 * The body of delete(), running with the series lock held when one applies.
	 *
	 * @param int   $actor_id
	 * @param int   $id
	 * @param array $existing raw row, read under the lock.
	 * @return array
	 */
	private static function delete_locked( $actor_id, $id, array $existing ) {
		global $wpdb;

		$now = current_time( 'mysql', true );
		$wpdb->update( self::table(), array( 'deleted_at' => $now ), array( 'id' => $id ) ); // phpcs:ignore

		/* A ROW IN A SERIES IS NOT A ROW ON ITS OWN.
		 *
		 * Deleting an OVERRIDE must leave an EXDATE on the master, or the rule
		 * regenerates the occurrence at its ORIGINAL slot on the next read — an
		 * EXDATE is what suppresses an instance (RFC 5545 section 3.8.5.1).
		 *
		 * Deleting a MASTER takes its overrides with it; they are separate rows
		 * with their own start_utc and would otherwise stay on the calendar as
		 * free-standing appointments of a series that no longer exists. */
		$series_uid = trim( (string) ( $existing['series_uid'] ?? '' ) );
		$rid        = trim( (string) ( $existing['recurrence_id'] ?? '' ) );
		if ( '' !== $series_uid && '' !== $rid && class_exists( 'ZSCH_Series' ) ) {
			$master = $wpdb->get_row( $wpdb->prepare( // phpcs:ignore
				'SELECT id, exdate, sequence FROM ' . self::table() . ' WHERE uid = %s AND deleted_at IS NULL', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- self::table() is $wpdb->prefix plus a fixed name; values are placeholders.
				$series_uid
			), ARRAY_A );
			if ( $master ) {
				$wpdb->update( self::table(), array( // phpcs:ignore
					'exdate'        => ZSCH_Series::add_to_list( $master['exdate'] ?? '', $rid ),
					'sequence'      => (int) $master['sequence'] + 1,
					'last_modified' => $now,
					'updated_by'    => (int) $actor_id,
					'updated_at'    => $now,
				), array( 'id' => (int) $master['id'] ) );
			}
		}
		$own_uid = trim( (string) ( $existing['uid'] ?? '' ) );
		if ( '' !== $own_uid && '' !== trim( (string) ( $existing['rrule'] ?? '' ) ) ) {
			$wpdb->query( $wpdb->prepare( // phpcs:ignore
				'UPDATE ' . self::table() . ' SET deleted_at = %s WHERE series_uid = %s AND deleted_at IS NULL', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- self::table() is $wpdb->prefix plus a fixed name; values are placeholders.
				$now,
				$own_uid
			) );
		}

		// Remove from Graph + drop the map row.
		$map = self::get_map( $id );
		if ( $map && '' !== $map['graph_event_id'] ) {
			// A pending id is a push in flight, not an Outlook event: nothing to delete yet.
			if ( ! self::is_pending_graph_id( $map['graph_event_id'] ) ) {
				$mailbox = ZSCH_Graph::mailbox_for_user( (int) $existing['owner_user_id'] );
				ZSCH_Graph::delete_event( $mailbox, $map['graph_event_id'] );
			}
			$wpdb->delete( self::map_table(), array( 'appointment_id' => $id ) ); // phpcs:ignore
		}

		// LIFECYCLE (drift): the resolver clears any cached job date and
		// forgets the Project's appointment ref (else is_scheduled() answers true
		// forever for a deleted appointment, and UNIQUE blocks re-use of the id).
		self::fire_lifecycle( 'deleted', $id, (int) $actor_id );

		$res = array( 'success' => true, 'id' => $id );

		/* KEEP SPLIT-OFF VISITS, AND SAY SO. A "for all" edit can detach a moved
		 * occurrence into a standalone appointment (ZSCH_Series_Write::
		 * apply_rekey_plan()). The cascade above is by series_uid, which a
		 * detached row no longer has, so it survives the series on purpose —
		 * somebody moved that visit and it is its own appointment now. Every
		 * series-master delete reports `kept_split_off` (zero included) and a
		 * `notice` when it is > 0, counting only rows the deleting user can see. */
		if ( '' !== $own_uid && '' !== trim( (string) ( $existing['rrule'] ?? '' ) ) ) {
			$kept                  = self::split_off_kept( (int) $actor_id, $own_uid );
			$res['kept_split_off'] = $kept;
			if ( $kept > 0 ) {
				$res['notice'] = self::split_off_notice( $kept );
			}
		}
		return $res;
	}

	/**
	 * How many appointments SPLIT OFF this series are still live and visible to
	 * $viewer_id.
	 *
	 * "Split off" means exactly what a detach records, and nothing looser:
	 *   - `related_to` = the series' uid and `series_uid` empty;
	 *   - AND a change-log row on that appointment recording `related_to` going
	 *     to this uid — the marker the detach writes. An import, a hand edit or
	 *     the NEW master of a "this and following" split carries the column with
	 *     no such log row and is not counted;
	 *   - live (deleted_at IS NULL): a retired override is detached AND
	 *     soft-deleted, so it is never counted.
	 * A split-off visit later given its own rule still counts.
	 *
	 * Each candidate goes through can_view() for the DELETING user. An
	 * appointment they cannot see is not counted and not hinted at, because the
	 * existence of a row you cannot see is itself what the visibility rule
	 * protects.
	 *
	 * @param int         $viewer_id
	 * @param string      $series_uid
	 * @param string|null $from_utc   only rows starting at or after this UTC instant; null = all.
	 * @return int
	 */
	public static function split_off_kept( $viewer_id, $series_uid, $from_utc = null ) {
		global $wpdb;
		$series_uid = trim( (string) $series_uid );
		if ( '' === $series_uid || (int) $viewer_id <= 0 ) {
			return 0;
		}
		$t    = self::table();
		$log  = $wpdb->prefix . 'zsch_change_log';
		$sql  = "SELECT a.* FROM {$t} a"
			. ' WHERE a.related_to = %s AND a.deleted_at IS NULL'
			. " AND ( a.series_uid IS NULL OR a.series_uid = '' )"
			. " AND EXISTS ( SELECT 1 FROM {$log} l WHERE l.appointment_id = a.id"
			. " AND l.field = 'related_to' AND l.new_value = %s )";
		$args = array( $series_uid, $series_uid );
		if ( null !== $from_utc && '' !== trim( (string) $from_utc ) ) {
			$sql   .= ' AND a.start_utc >= %s';
			$args[] = (string) $from_utc;
		}
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A ); // phpcs:ignore
		$n    = 0;
		foreach ( (array) $rows as $r ) {
			if ( self::can_view( (int) $viewer_id, $r ) ) {
				$n++;
			}
		}
		return $n;
	}

	/**
	 * The sentence for split_off_kept(). Plain text — the editor puts it in
	 * with textContent, and any other caller must escape it as text too.
	 *
	 * @param int $n
	 * @return string '' when there is nothing to say.
	 */
	public static function split_off_notice( $n ) {
		$n = (int) $n;
		if ( $n <= 0 ) {
			return '';
		}
		return 1 === $n
			? '1 split-off appointment from this series was kept.'
			: $n . ' split-off appointments from this series were kept.';
	}

	// ── Graph push helpers ─────────────────────────────────────────

	/** How long a pending map row keeps the pull away from its mailbox. */
	const PUSH_PENDING_TTL_S = 120;

	/** The prefix that marks a map row as a push still in flight. */
	const PUSH_PENDING_PREFIX = 'pending:';

	/**
	 * Push a new appointment to Outlook, and map it, with no window a pull can
	 * land in.
	 *
	 * A map row for this appointment is written BEFORE the remote create, marked
	 * pending, and the pull skips any UNMAPPED event of that mailbox while a
	 * pending row is fresh. That closes the create-then-map window by causality
	 * rather than by timing: the event cannot appear in a pull's listing before
	 * the POST that creates it, and the pending row is committed before the POST
	 * is sent. No lock and no wait — the pull's sync lock is held for a whole
	 * sweep, and a person's save must never hang behind it. The cost is that a
	 * genuinely new Outlook event created in the same instant is picked up one
	 * sweep later. A push that dies mid-flight leaves a stale pending row, which
	 * stops shielding after PUSH_PENDING_TTL_S.
	 */
	private static function push_create( $owner_id, array $row ) {
		if ( ! ZSCH_Graph::is_available() ) {
			return array( 'skipped' => true );
		}
		$mailbox = ZSCH_Graph::mailbox_for_user( $owner_id );
		$pending = self::claim_pending_map( (int) $row['id'], $mailbox );

		$res = ZSCH_Graph::create_event( $owner_id, $row );
		if ( ! empty( $res['success'] ) && empty( $res['skipped'] ) && ! empty( $res['graph_event_id'] ) ) {
			self::upsert_map( (int) $row['id'], $mailbox, $res );
		} else {
			self::release_pending_map( $pending );
		}
		return $res;
	}

	/**
	 * Is this graph_event_id a push still in flight rather than a real Graph id?
	 *
	 * @param string $graph_event_id
	 * @return bool
	 */
	public static function is_pending_graph_id( $graph_event_id ) {
		return 0 === strpos( (string) $graph_event_id, self::PUSH_PENDING_PREFIX );
	}

	/**
	 * Write (or take over) this appointment's map row as PENDING, before the POST.
	 * The pending id is unique per row because graph_event_id carries a UNIQUE key.
	 *
	 * @param int    $appointment_id
	 * @param string $mailbox
	 * @return array { id:int, placeholder:string, restore:string|null } — what to undo.
	 */
	private static function claim_pending_map( $appointment_id, $mailbox ) {
		global $wpdb;
		$placeholder = self::PUSH_PENDING_PREFIX . (int) $appointment_id . ':' . md5( uniqid( '', true ) );
		$data        = array(
			'graph_user'     => (string) $mailbox,
			'graph_event_id' => $placeholder,
			'last_synced_at' => current_time( 'mysql', true ),
			'direction'      => 'pending',
			'sync_error'     => '',
		);
		$existing = self::get_map( $appointment_id );
		if ( $existing ) {
			// Only an UNMAPPED row can be taken over. A real Graph id is never overwritten.
			if ( '' !== (string) $existing['graph_event_id'] && ! self::is_pending_graph_id( $existing['graph_event_id'] ) ) {
				return array( 'id' => 0, 'placeholder' => '', 'restore' => null );
			}
			$wpdb->update( self::map_table(), $data, array( 'id' => (int) $existing['id'] ) ); // phpcs:ignore
			return array( 'id' => (int) $existing['id'], 'placeholder' => $placeholder, 'restore' => (string) $existing['graph_event_id'] );
		}
		$ok = $wpdb->insert( self::map_table(), array_merge( $data, array( 'appointment_id' => (int) $appointment_id ) ) ); // phpcs:ignore
		return array( 'id' => $ok ? (int) $wpdb->insert_id : 0, 'placeholder' => $placeholder, 'restore' => null );
	}

	/** Undo claim_pending_map() after a push that did not create anything. */
	private static function release_pending_map( array $pending ) {
		global $wpdb;
		if ( empty( $pending['id'] ) ) {
			return;
		}
		$where = array( 'id' => (int) $pending['id'], 'graph_event_id' => (string) $pending['placeholder'] );
		if ( null === $pending['restore'] ) {
			$wpdb->delete( self::map_table(), $where ); // phpcs:ignore
		} else {
			// Back to what it was. A stale pending id is not restored: it would only shield the mailbox for no reason.
			$was = self::is_pending_graph_id( $pending['restore'] ) ? '' : (string) $pending['restore'];
			$wpdb->update( self::map_table(), array( 'graph_event_id' => $was, 'direction' => 'push' ), $where ); // phpcs:ignore
		}
	}

	/**
	 * Is a push to this mailbox in flight right now?
	 *
	 * @param string $mailbox
	 * @return bool
	 */
	private static function push_in_flight( $mailbox ) {
		global $wpdb;
		$since = gmdate( 'Y-m-d H:i:s', time() - self::PUSH_PENDING_TTL_S );
		$n     = $wpdb->get_var( $wpdb->prepare( // phpcs:ignore
			'SELECT COUNT(*) FROM ' . self::map_table() // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- self::map_table() is $wpdb->prefix plus a fixed name; values are placeholders.
			. ' WHERE graph_user = %s AND direction = %s AND graph_event_id LIKE %s AND last_synced_at >= %s',
			(string) $mailbox,
			'pending',
			$wpdb->esc_like( self::PUSH_PENDING_PREFIX ) . '%',
			$since
		) );
		return (int) $n > 0;
	}

	private static function push_update( $owner_id, array $row ) {
		if ( ! ZSCH_Graph::is_available() ) {
			return array( 'skipped' => true );
		}
		$map     = self::get_map( (int) $row['id'] );
		$mailbox = ZSCH_Graph::mailbox_for_user( $owner_id );
		if ( $map && '' !== $map['graph_event_id'] && ! self::is_pending_graph_id( $map['graph_event_id'] ) ) {
			/* Do not hand Outlook back a preview of its own description. If the
			 * local body still hashes to what a pull wrote, no person has edited
			 * it here and it is not ours to send. */
			$omit = array();
			if ( ! self::body_is_ours( $row['body'] ?? '', $map['pulled_body_hash'] ?? '' ) ) {
				$omit[] = 'body';
			}
			$res = ZSCH_Graph::update_event( $mailbox, $map['graph_event_id'], $row, $map['etag'], $omit );
			if ( ! empty( $res['success'] ) && empty( $res['skipped'] ) ) {
				self::upsert_map( (int) $row['id'], $mailbox, $res );
			}
			return $res;
		}
		// No map yet (created while sync was off) — create it now.
		return self::push_create( $owner_id, $row );
	}

	/**
	 * Reconcile a batch of Graph events for a user into our table.
	 * Called by ZSCH_Graph::cron_sync_all() and the "Sync now" route. Match on
	 * graph_event_id; insert new, update changed, soft-delete cancelled.
	 *
	 * THE PULL GUARDS ITSELF: two concurrent pulls of one mailbox would both find
	 * no map row and both insert, leaving a duplicate nobody can reconcile. A
	 * caller that already holds the sync lock passes straight through
	 * (IS_USED_LOCK answers for THIS connection); a caller that does not is given
	 * it with timeout 0, or told the pull did not run.
	 *
	 * @param int    $owner_id
	 * @param string $mailbox
	 * @param array  $events  raw Graph event objects.
	 * @return array { success, seen?, deferred?, code? }
	 */
	public static function reconcile_from_graph( $owner_id, $mailbox, array $events ) {
		global $wpdb;
		$took = false;
		if ( class_exists( 'ZSCH_Graph' ) && method_exists( 'ZSCH_Graph', 'sync_lock_name' ) ) {
			$mine = $wpdb->get_var( $wpdb->prepare( 'SELECT IS_USED_LOCK(%s) = CONNECTION_ID()', ZSCH_Graph::sync_lock_name() ) );
			if ( '1' !== (string) $mine ) {
				if ( ! ZSCH_Graph::sync_lock_acquire() ) {
					return array( 'success' => false, 'code' => 'zsch_sync_busy', 'reconciled' => 0 );
				}
				$took = true;
			}
		}
		try {
			return self::reconcile_locked( (int) $owner_id, (string) $mailbox, $events );
		} finally {
			if ( $took ) {
				ZSCH_Graph::sync_lock_release();
			}
		}
	}

	/**
	 * The body of reconcile_from_graph(), with the sync lock held.
	 *
	 * @param int    $owner_id
	 * @param string $mailbox
	 * @param array  $events
	 * @return array
	 */
	private static function reconcile_locked( $owner_id, $mailbox, array $events ) {
		global $wpdb;
		$seen     = 0;
		$deferred = 0;
		/* Asked ONCE, BEFORE any map read. The causal argument only holds in this
		 * order: pending row committed before the POST, POST before the listing,
		 * THIS read before every map read. A push that finishes afterwards is then
		 * either still pending here (deferred) or already mapped when its event is
		 * looked up. */
		$pushing = self::push_in_flight( $mailbox );

		foreach ( $events as $ev ) {
			$graph_id = $ev['id'] ?? '';
			if ( '' === $graph_id ) {
				continue;
			}
			// INV-Loop: never re-ingest our OWN write-back copy. The owner's own
			// calendar is also their conflict feed, so an unguarded copy makes its
			// owner look busy against itself within 5 minutes. Belt (the write-back
			// map) + braces (our stamp) live in ZSCH_Writeback, so the guard holds
			// even while the write-back engine is OFF (it simply never matches yet).
			if ( class_exists( 'ZSCH_Writeback' ) && ZSCH_Writeback::is_own_writeback( $ev ) ) {
				continue;
			}
			$ev_all_day = ! empty( $ev['isAllDay'] );

			if ( $ev_all_day ) {
				/* Graph represents an all-day event as midnight → next midnight (end
				 * EXCLUSIVE) and, because we ask for outlook.timezone="UTC", labels
				 * the calendar dates UTC rather than offsetting them. Read the bare
				 * dates, drop the exclusive end back to the real last day, and store
				 * the same inclusive local bounds create() uses (00:00:00 → 23:59:59
				 * in the row's zone), so a pull right after a push is a no-op. */
				$row_tz    = self::sanitize_tz( ZSCH_Settings::default_tz() );
				$start_day = substr( (string) ( $ev['start']['dateTime'] ?? '' ), 0, 10 );
				$end_day   = substr( (string) ( $ev['end']['dateTime'] ?? '' ), 0, 10 );
				if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $start_day ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $end_day ) ) {
					continue;
				}
				try {
					$last = new DateTime( $end_day );
					$last->modify( '-1 day' );
					// A zero-length all-day event ends on its start day.
					$end_day = ( $last->format( 'Y-m-d' ) < $start_day ) ? $start_day : $last->format( 'Y-m-d' );
				} catch ( Exception $e ) {
					$end_day = $start_day;
				}
				$start_utc = self::local_to_utc( $start_day, $row_tz, true, false );
				$end_utc   = self::local_to_utc( $end_day, $row_tz, true, true );
			} else {
				$start_utc = ZSCH_Graph::graph_to_utc( $ev['start'] ?? array() );
				$end_utc   = ZSCH_Graph::graph_to_utc( $ev['end'] ?? array() );
			}

			if ( '' === $start_utc || '' === $end_utc ) {
				continue;
			}
			$cancelled = ! empty( $ev['isCancelled'] );

			$existing_id = self::appt_id_for_graph_event( $graph_id );
			$seen++;

			/* A push to this mailbox is between its POST and its map row. An
			 * unmapped event now may be that push's own event; leave it for the
			 * next sweep, when the map row will say whose it is. */
			if ( ! $existing_id && ! $cancelled && $pushing ) {
				$deferred++;
				continue;
			}

			if ( $cancelled ) {
				if ( $existing_id ) {
					$wpdb->update( self::table(), array( 'deleted_at' => current_time( 'mysql', true ) ), array( 'id' => $existing_id ) ); // phpcs:ignore
					// Drift: an Outlook-side cancel must clear any cached job date.
					self::fire_lifecycle( 'deleted', $existing_id, 0 );
				}
				continue;
			}

			/* `bodyPreview` is a TRUNCATED PLAIN-TEXT PREVIEW, not the body. Storing
			 * it is fine (it is what the card shows); writing it BACK would be data
			 * loss. Hash exactly what is stored so push_update() can tell "nobody
			 * has touched this since the pull" from "a person really edited it". */
			$pulled_body = sanitize_textarea_field( $ev['bodyPreview'] ?? '' );

			/* ADOPT GRAPH'S OWN UID RATHER THAN MINTING ONE. `iCalUId` IS the RFC
			 * 5545 UID every other client already uses for this event; minting our
			 * own would give one real-world appointment two identities (RFC 5546
			 * section 2.1.5). */
			$ev_uid = (string) ( $ev['iCalUId'] ?? '' );
			if ( ! class_exists( 'ZSCH_Identity' ) || ! ZSCH_Identity::is_valid_uid( $ev_uid ) ) {
				$ev_uid = '';
			}

			$fields = array(
				'owner_user_id'  => (int) $owner_id,
				'calendar_scope' => 'personal', // pulled-in Outlook events are personal
				'title'          => sanitize_text_field( $ev['subject'] ?? '(no subject)' ),
				'body'           => $pulled_body,
				'location'       => sanitize_text_field( $ev['location']['displayName'] ?? '' ),
				'start_utc'      => $start_utc,
				'end_utc'        => $end_utc,
				'is_all_day'     => $ev_all_day ? 1 : 0,
				'busy_status'    => self::busy_from_show_as( $ev['showAs'] ?? 'busy' ),
				'status'         => 'confirmed',
				'updated_at'     => current_time( 'mysql', true ),
			);

			if ( $existing_id ) {
				$before = self::get_raw( $existing_id );

				/* DO NOT APPLY A SNAPSHOT OLDER THAN THE ROW. A sweep that started
				 * before somebody dragged an appointment here and finished after
				 * would write the pre-drag state back over their change. */
				if ( self::graph_is_stale( $before, $ev ) ) {
					self::touch_map_synced( $existing_id, $ev['@odata.etag'] ?? '', self::body_fingerprint( $pulled_body ) );
					continue;
				}
				// Attribute it: the change log's `source` says this was the sync.
				if ( class_exists( 'ZSCH_Identity' ) && $before ) {
					$sync_changes = ZSCH_Identity::diff(
						$before,
						$fields,
						array_merge( ZSCH_Identity::SIGNIFICANT, ZSCH_Identity::TRACKED )
					);
					if ( ! empty( $sync_changes ) ) {
						ZSCH_Identity::log( $existing_id, (string) ( $before['uid'] ?? $ev_uid ), $sync_changes, array(
							'actor_id'   => 0,
							'actor_type' => 'sync',
							'source'     => 'graph-sync',
						) );
					}
				}

				$wpdb->update( self::table(), $fields, array( 'id' => $existing_id ) ); // phpcs:ignore
				self::touch_map_synced( $existing_id, $ev['@odata.etag'] ?? '', self::body_fingerprint( $pulled_body ) );

				/* Drift: an Outlook-side move refreshes the cached job date. Only
				 * when the window actually moved — this runs every five minutes for
				 * every mailbox, and a hook fired on an unchanged row would turn
				 * every subscriber into a five-minute write. */
				$moved = ! $before
					|| (string) $before['start_utc'] !== (string) $start_utc
					|| (string) $before['end_utc'] !== (string) $end_utc;
				if ( $moved ) {
					self::fire_lifecycle( 'updated', $existing_id, 0 );
				}
			} else {
				$fields['created_by'] = 0; // system/pull
				$fields['created_at'] = current_time( 'mysql', true );

				/* ONE iCalUId, MANY OCCURRENCES. /calendarView expands a recurring
				 * event and every occurrence it returns carries the SAME iCalUId, so
				 * against UNIQUE idx_zsch_uid only the first would insert. An
				 * occurrence gets its OWN uid and carries the shared one as
				 * `series_uid`; the provider's iCalUId is still recorded on the map
				 * row, so identity across systems survives. */
				$ev_type       = strtolower( (string) ( $ev['type'] ?? '' ) );
				$is_occurrence = in_array( $ev_type, array( 'occurrence', 'exception' ), true )
					|| '' !== trim( (string) ( $ev['seriesMasterId'] ?? '' ) );
				$mint          = class_exists( 'ZSCH_Identity' ) ? ZSCH_Identity::mint_uid( ZSCH_Identity::uid_host() ) : null;

				if ( $is_occurrence && '' !== $ev_uid ) {
					$fields['uid']        = $mint;
					$fields['series_uid'] = $ev_uid;
				} else {
					$fields['uid'] = ( '' !== $ev_uid ) ? $ev_uid : $mint;
				}
				$fields['sequence']      = 0;
				$fields['last_modified'] = current_time( 'mysql', true );
				if ( class_exists( 'ZSCH_Duration' ) ) {
					$fields['duration'] = ZSCH_Duration::between_exact( $start_utc, $end_utc );
				}

				$inserted = $wpdb->insert( self::table(), $fields ); // phpcs:ignore
				if ( ! $inserted && self::is_duplicate_key_error( $wpdb->last_error ) && null !== $mint ) {
					/* The UID is already held by a row this pull did not find by
					 * graph id — a second route to the same event. Keep the
					 * appointment; the map row carries the provider's identity. */
					$fields['uid']        = $mint;
					$fields['series_uid'] = ( '' !== $ev_uid ) ? $ev_uid : null;
					$inserted             = $wpdb->insert( self::table(), $fields ); // phpcs:ignore
				}
				$new_id = $inserted ? (int) $wpdb->insert_id : 0;

				// No row, no map row: a map row at appointment_id 0 could never be reconciled.
				if ( $new_id > 0 ) {
					self::upsert_map( $new_id, $mailbox, array(
						'graph_event_id'   => $graph_id,
						'ical_uid'         => $ev['iCalUId'] ?? '',
						'etag'             => $ev['@odata.etag'] ?? '',
						'pulled_body_hash' => self::body_fingerprint( $pulled_body ),
					), 'pull' );
				} elseif ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log( sprintf( // phpcs:ignore WordPress.PHP.DevelopmentFunctions
						'Zorderz Scheduler sync: could not store pulled event %s (%s). Nothing was written.',
						$graph_id,
						$wpdb->last_error ? $wpdb->last_error : 'unknown'
					) );
				}
			}
		}
		return array( 'success' => true, 'seen' => $seen, 'deferred' => $deferred );
	}

	// ── map-table helpers ──────────────────────────────────────────

	/**
	 * Is this Graph snapshot older than the row it is about to overwrite? PURE.
	 *
	 * Only a change made by a PERSON counts: a sweep must never undo somebody's
	 * edit, but it must always be able to correct a row the previous sweep
	 * wrote, or a clock skew of a few seconds would freeze the mailbox.
	 * `updated_by` is 0 for every sync write and non-zero for every user write.
	 *
	 * @param array|null $before the local row, as stored.
	 * @param array      $ev     the Graph event.
	 * @return bool true when the local row wins and the snapshot must be dropped.
	 */
	public static function graph_is_stale( $before, array $ev ) {
		if ( empty( $before ) || ! is_array( $before ) ) {
			return false;
		}
		if ( (int) ( $before['updated_by'] ?? 0 ) <= 0 ) {
			return false;   // last touched by sync; a newer sweep is authoritative
		}
		$remote = trim( (string) ( $ev['lastModifiedDateTime'] ?? '' ) );
		$local  = trim( (string) ( $before['last_modified'] ?? '' ) );
		if ( '' === $remote || '' === $local ) {
			return false;   // cannot compare; do not invent a verdict
		}
		$r = strtotime( $remote );
		$l = strtotime( $local . ' UTC' );
		if ( ! $r || ! $l ) {
			return false;
		}
		return $r < $l;
	}

	private static function upsert_map( $appointment_id, $mailbox, array $res, $direction = 'push' ) {
		global $wpdb;
		$appointment_id = (int) $appointment_id;
		$existing       = self::get_map( $appointment_id );
		$data           = array(
			'appointment_id' => $appointment_id,
			'graph_user'     => (string) $mailbox,
			'graph_event_id' => (string) ( $res['graph_event_id'] ?? ( $existing['graph_event_id'] ?? '' ) ),
			'graph_ical_uid' => (string) ( $res['ical_uid'] ?? ( $existing['graph_ical_uid'] ?? '' ) ),
			'etag'           => (string) ( $res['etag'] ?? ( $existing['etag'] ?? '' ) ),
			'last_synced_at' => current_time( 'mysql', true ),
			'direction'      => $direction,
			'sync_error'     => '',
		);
		/* Only ever write the fingerprint when the caller supplies one (a pull).
		 * A push must NOT clear it: the event's body still belongs to Outlook. */
		if ( isset( $res['pulled_body_hash'] ) ) {
			$data['pulled_body_hash'] = (string) $res['pulled_body_hash'];
		}
		if ( $existing ) {
			$wpdb->update( self::map_table(), $data, array( 'appointment_id' => $appointment_id ) ); // phpcs:ignore
		} else {
			$wpdb->insert( self::map_table(), $data ); // phpcs:ignore
		}
	}

	private static function touch_map_synced( $appointment_id, $etag, $pulled_body_hash = null ) {
		global $wpdb;
		$data = array(
			'etag'           => (string) $etag,
			'last_synced_at' => current_time( 'mysql', true ),
			'direction'      => 'pull',
		);
		if ( null !== $pulled_body_hash ) {
			$data['pulled_body_hash'] = (string) $pulled_body_hash;
		}
		$wpdb->update( self::map_table(), $data, array( 'appointment_id' => (int) $appointment_id ) ); // phpcs:ignore
	}

	/**
	 * Fingerprint of a body as WE stored it. PURE. One method so the pull side
	 * and the push side can never disagree about normalisation.
	 *
	 * @param string $body
	 * @return string 40-char sha1, or '' for an empty body.
	 */
	public static function body_fingerprint( $body ) {
		$body = (string) $body;
		return ( '' === $body ) ? '' : sha1( $body );
	}

	/**
	 * Should this update send `body` to Graph, or leave Outlook's alone? PURE.
	 *
	 * True when the local body is a person's edit and should win; false when it
	 * is still the preview a pull wrote, and sending it would destroy the real
	 * rich text on the other end. An empty stored hash means "no body was ever
	 * pulled for this event" — one created here — and then the body IS ours.
	 *
	 * @param string $local_body        the body on our row right now.
	 * @param string $pulled_body_hash  fingerprint recorded at pull time.
	 * @return bool
	 */
	public static function body_is_ours( $local_body, $pulled_body_hash ) {
		$pulled_body_hash = (string) $pulled_body_hash;
		if ( '' === $pulled_body_hash ) {
			return true;
		}
		return self::body_fingerprint( $local_body ) !== $pulled_body_hash;
	}

	private static function get_map( $appointment_id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::map_table() . ' WHERE appointment_id = %d', (int) $appointment_id ), ARRAY_A ); // phpcs:ignore
		return $row ?: null;
	}

	private static function appt_id_for_graph_event( $graph_event_id ) {
		global $wpdb;
		$id = $wpdb->get_var( $wpdb->prepare( 'SELECT appointment_id FROM ' . self::map_table() . ' WHERE graph_event_id = %s', $graph_event_id ) ); // phpcs:ignore
		return $id ? (int) $id : 0;
	}

	// ── small utilities ────────────────────────────────────────────

	public static function get_raw( $id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', (int) $id ), ARRAY_A ); // phpcs:ignore
		return $row ?: null;
	}

	/**
	 * Compact external-conflict list for booking warnings (busy-only; no titles).
	 *
	 * @param array $conflicts rows from ZSCH_Sync::conflicts_for()
	 * @return array
	 */
	private static function shape_conflicts( array $conflicts ) {
		$out = array();
		foreach ( $conflicts as $c ) {
			$out[] = array(
				'start_utc'   => (string) ( $c['start_utc'] ?? '' ),
				'end_utc'     => (string) ( $c['end_utc'] ?? '' ),
				'is_all_day'  => ! empty( $c['is_all_day'] ),
				'busy_status' => (string) ( $c['busy_status'] ?? 'busy' ),
				'source'      => 'external',
			);
		}
		return $out;
	}

	/**
	 * May this viewer SEE this appointment?
	 *
	 * The row-level twin of query()'s visibility clause: shared to everybody,
	 * personal to its owner (or an admin), and "…or I am on it" — which the
	 * kiosk never gets (INV-Kiosk: a shared device is not a person). Seeing is
	 * not editing, so this is deliberately NOT can_modify().
	 *
	 * @param int   $viewer_id
	 * @param array $row
	 * @return bool
	 */
	public static function can_view( $viewer_id, array $row ) {
		$viewer_id = (int) $viewer_id;
		if ( $viewer_id <= 0 ) {
			return false;
		}
		if ( ! empty( $row['deleted_at'] ) ) {
			return false;
		}
		if ( 'shared' === (string) ( $row['calendar_scope'] ?? '' ) ) {
			return true;
		}
		if ( (int) ( $row['owner_user_id'] ?? 0 ) === $viewer_id ) {
			return true;
		}
		if ( self::viewer_is_admin( $viewer_id ) ) {
			return true;
		}
		if ( function_exists( 'zsch_user_is_read_only' ) && zsch_user_is_read_only( $viewer_id ) ) {
			return false;
		}
		if ( class_exists( 'ZSCH_Participants' ) ) {
			return ZSCH_Participants::is_participant( (int) ( $row['id'] ?? 0 ), $viewer_id );
		}
		return false;
	}

	/**
	 * May this actor MODIFY this appointment? Public so Zjob_Appointment_Link
	 * and ZSCH_Participants can double-gate through it via is_callable — the
	 * estimate # / address land on a SHARED calendar, so a link or a
	 * participant-add (a disclosure) must clear the same edit gate as an edit.
	 * Read-only (kiosk) never passes; admins always; else owner or creator.
	 *
	 * @param int   $actor_id
	 * @param array $row  a raw appointment row (from get_raw()).
	 * @return bool
	 */
	public static function can_modify( $actor_id, array $row ) {
		if ( ! zsch_user_can_write( $actor_id ) ) {
			return false;
		}
		if ( self::viewer_is_admin( $actor_id ) ) {
			return true;
		}
		// Owner can modify their own; shared events can be edited by their creator.
		return (int) ( $row['owner_user_id'] ?? 0 ) === (int) $actor_id
			|| (int) ( $row['created_by'] ?? 0 ) === (int) $actor_id;
	}

	/**
	 * Fire an appointment lifecycle action. The subscribers have TWO different
	 * first-arg shapes, honored exactly here:
	 *   - created  → do_action('zsch_appointment_created', $payload)  [array first:
	 *     Zjob_Schedule_Context::on_created reads $payload['origin'|'actor'|'appt_id']]
	 *   - updated  → do_action('zsch_appointment_updated', $id, $payload)  [int first:
	 *     Zjob_Install_Date::on_appointment_updated re-reads get_raw($id); the
	 *     payload rides as a 2nd arg for any richer subscriber]
	 *   - deleted  → do_action('zsch_appointment_deleted', $id, $payload)  [int first]
	 *
	 * `origin` carries the handoff pair {ns, ref_id} from the signed intake token
	 * (empty for a plain create/update/delete or a Graph-pull reconcile — the drift
	 * subscribers never read it, they re-read get_raw). `actor` is the acting USER
	 * ID (int) — on_created casts it and passes it to attach() as the viewer, so it
	 * must be the real id, never a structure. NO money ever rides a payload.
	 *
	 * @param string $event  'created' | 'updated' | 'deleted'
	 * @param int    $id      appointment id.
	 * @param int    $actor   acting user id.
	 * @param array  $origin  {ns, ref_id} from the token; empty otherwise.
	 */
	private static function fire_lifecycle( $event, $id, $actor, array $origin = array() ) {
		$id = (int) $id;
		if ( $id <= 0 || ! function_exists( 'do_action' ) ) {
			return;
		}
		$payload = array(
			'appt_id' => $id,
			'id'      => $id,
			'actor'   => (int) $actor,
			'origin'  => array(
				'ns'     => isset( $origin['ns'] ) ? (string) $origin['ns'] : '',
				'ref_id' => isset( $origin['ref_id'] ) ? (string) $origin['ref_id'] : '',
			),
		);
		if ( 'created' === $event ) {
			do_action( 'zsch_appointment_created', $payload );
		} elseif ( 'updated' === $event ) {
			do_action( 'zsch_appointment_updated', $id, $payload );
		} elseif ( 'deleted' === $event ) {
			do_action( 'zsch_appointment_deleted', $id, $payload );
		}
	}

	public static function viewer_is_admin( $user_id ) {
		return user_can( (int) $user_id, 'manage_options' )
			|| user_can( (int) $user_id, 'zdz_owner' )
			|| user_can( (int) $user_id, 'zdz_admin' );
	}

	/**
	 * Normalise a DB row into the shape the frontend + bridge consume.
	 *
	 * @param array      $row
	 * @param int[]|null $participant_ids pass the batched ids when the caller has
	 *                                    them (query() does); null looks them up,
	 *                                    which is right for a single row.
	 * @return array|null
	 */
	public static function shape_row( $row, $participant_ids = null ) {
		if ( ! is_array( $row ) ) {
			return null;
		}
		$owner = get_userdata( (int) $row['owner_user_id'] );

		if ( null === $participant_ids ) {
			$participant_ids = class_exists( 'ZSCH_Participants' )
				? ZSCH_Participants::participant_ids( (int) $row['id'] )
				: array();
		}

		return array(
			'id'              => (int) $row['id'],
			'owner_user_id'   => (int) $row['owner_user_id'],
			'owner_name'      => $owner ? $owner->display_name : '',
			'scope'           => $row['calendar_scope'],
			'title'           => $row['title'],
			'body'            => $row['body'],
			'location'        => $row['location'],
			'start_utc'       => self::iso( $row['start_utc'] ),
			'end_utc'         => self::iso( $row['end_utc'] ),
			'is_all_day'      => (bool) $row['is_all_day'],
			'time_zone'       => $row['time_zone'],
			'busy_status'     => $row['busy_status'],
			'status'          => $row['status'],
			'color'           => $row['color'],
			'attendees'       => json_decode( (string) ( $row['attendees'] ?? '' ), true ) ?: array(),
			// Real accounts on the event (owner excluded — never a participant row).
			'participant_ids' => array_values( array_map( 'intval', (array) $participant_ids ) ),
			/* RECURRENCE.
			 *
			 * `recurrence_id` identifies ONE occurrence: its ORIGINAL local start,
			 * never where it was moved to. A client editing one occurrence sends it
			 * back unchanged (RFC 5545 section 3.8.4.4).
			 *
			 * `id` stays the SOURCE ROW's id, because permissions, participants and
			 * job links hang off it. A generated occurrence has no row of its own:
			 * (series_uid, recurrence_id) is its handle, surfaced as `instance_key`.
			 *
			 * `sequence` is part of the event: a client reconciling against a
			 * provider needs it, and an in-lock re-read must see it. */
			'rrule'           => (string) ( $row['rrule'] ?? '' ),
			'sequence'        => (int) ( $row['sequence'] ?? 0 ),
			'exdate'          => (string) ( $row['exdate'] ?? '' ),
			'rdate'           => (string) ( $row['rdate'] ?? '' ),
			'recurrence_id'   => (string) ( $row['recurrence_id'] ?? '' ),
			'series_uid'      => (string) ( $row['_series_uid'] ?? $row['series_uid'] ?? '' ),
			'instance_key'    => (string) ( $row['_instance_key'] ?? '' ),
			'is_instance'     => ! empty( $row['_is_instance'] ),
			'is_override'     => ! empty( $row['_is_override'] ),
			'repeats'         => '' !== trim( (string) ( $row['rrule'] ?? '' ) ),
			// A master whose rule cannot be expanded is shown as itself, and says so.
			'rule_broken'     => ! empty( $row['_rule_broken'] ),
		);
	}

	private static function iso( $mysql_utc ) {
		if ( empty( $mysql_utc ) ) {
			return '';
		}
		return str_replace( ' ', 'T', $mysql_utc ) . 'Z';
	}

	/**
	 * Wall-clock local → UTC 'Y-m-d H:i:s'.
	 *
	 * Also accepts a DATE-ONLY string ("2026-08-18"), normalised to day bounds
	 * the same way ZSCH_Availability does it: start 00:00:00, end 23:59:59.
	 * Keyed on the STRING SHAPE, not on $all_day: a caller that sends a real
	 * clock time gets that clock honoured even when it also sets is_all_day.
	 *
	 * @param string $local  'Y-m-d', 'Y-m-d H:i[:s]' or 'Y-m-dTH:i'.
	 * @param string $tz     IANA zone the wall clock is in.
	 * @param bool   $all_day
	 * @param bool   $is_end true for the end of the range.
	 * @return string '' when unparseable.
	 */
	private static function local_to_utc( $local, $tz, $all_day = false, $is_end = false ) {
		$local = trim( (string) $local );
		if ( '' === $local ) {
			return '';
		}
		try {
			// Accept "2026-06-14T14:00" or "2026-06-14 14:00[:00]".
			$local = str_replace( 'T', ' ', $local );
			if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $local ) ) {
				$local .= $is_end ? ' 23:59:59' : ' 00:00:00';
			} elseif ( preg_match( '/^(\d{4}-\d{2}-\d{2}):00$/', $local, $m ) ) {
				// A date with a stray ':00' appended (a datetime helper applied to a date field).
				$local = $m[1] . ( $is_end ? ' 23:59:59' : ' 00:00:00' );
			}
			if ( class_exists( 'ZSCH_Tzdata' ) ) {
				// The repeated hour resolves to its first occurrence, a skipped one to the pre-gap offset.
				return ZSCH_Tzdata::local_to_utc( $local, $tz );
			}
			$dt = new DateTime( $local, new DateTimeZone( $tz ) );
			$dt->setTimezone( new DateTimeZone( 'UTC' ) );
			return $dt->format( 'Y-m-d H:i:s' );
		} catch ( Exception $e ) {
			return '';
		}
	}

	/**
	 * A UTC timestamp rendered back in the person's own zone, for error messages
	 * ("the end (Fri Aug 7, 3:00 PM) is not after the start…").
	 */
	private static function pretty_local( $utc, $tz, $all_day = false ) {
		try {
			$dt = new DateTime( $utc, new DateTimeZone( 'UTC' ) );
			$dt->setTimezone( new DateTimeZone( $tz ) );
			return $dt->format( $all_day ? 'D M j' : 'D M j, g:i A' );
		} catch ( Exception $e ) {
			return (string) $utc;
		}
	}

	private static function sanitize_tz( $tz ) {
		$tz = (string) $tz;
		try {
			new DateTimeZone( $tz );
			return $tz;
		} catch ( Exception $e ) {
			return ZSCH_Settings::default_tz();
		}
	}

	private static function sanitize_busy( $b ) {
		$b = strtolower( (string) $b );
		return in_array( $b, array( 'busy', 'free', 'tentative', 'oof' ), true ) ? $b : 'busy';
	}

	private static function busy_from_show_as( $show ) {
		$show = strtolower( (string) $show );
		if ( 'free' === $show ) { return 'free'; }
		if ( 'tentative' === $show ) { return 'tentative'; }
		if ( 'oof' === $show || 'workingelsewhere' === $show ) { return 'oof'; }
		return 'busy';
	}

	private static function sanitize_attendees( $att ) {
		if ( is_string( $att ) ) {
			$att = json_decode( $att, true );
		}
		$out = array();
		if ( is_array( $att ) ) {
			foreach ( $att as $email ) {
				if ( is_email( $email ) ) {
					$out[] = sanitize_email( $email );
				}
			}
		}
		return wp_json_encode( array_values( array_unique( $out ) ) );
	}
}
