<?php
/**
 * Zorderz Jobs — the Job Dossier: narrative history (wp_zdz_job_events).
 *
 * An append-only timeline of what happened to a job — created, assigned, scheduled,
 * worked, completed, closed, a note or photo added. It is the substrate the Record
 * panel later reads to tell a job's story.
 *
 * TWO DELIBERATE PROPERTIES:
 *   1. Append-only. record() only ever INSERTs. History is never rewritten; a
 *      correction is a NEW event, not an edit of an old one.
 *   2. Summaries are composed AT WRITE TIME. We render the one-line human sentence
 *      now and store it, rather than deferring the render to read time over a payload
 *      that may grow. A reader (including the Ai, behind the Record panel's counts-only
 *      allow-list) gets a safe, frozen string — not a live projection of whatever the
 *      source row has since accumulated.
 *
 * Every event carries a `visibility` tier on its envelope (internal | staff | customer),
 * the Flow-shaped structural guard against internal narrative leaking onto a
 * customer-facing surface (a receipt renderer asks for the `customer` ceiling and
 * physically cannot receive a staff/internal row — see for_jobs()'s max_visibility).
 *
 * `sp_code` is a minimal salesperson-attribution shim on the event (the union-of-legacy
 * meta-keys mapping is [IDENTITY->mappings]); the full ZDZ_Party sp_code facet is
 * deferred — estimate-minted Projects attribute via created_by instead.
 *
 * SCHEMA SELF-BOOT: this class owns ensure_schema() for BOTH dossier tables
 * (wp_zdz_job_events and wp_zdz_job_notes), version-gated by the option
 * `zjob_dossier_db_version`, idempotent (dbDelta), and it NEVER seeds a row. It is
 * self-registered on after_setup_theme + init at the bottom of this file, so the
 * tables exist regardless of who wired the loader.
 *
 * @package Zorderz\Jobs
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZJOB_Events {

	/** Bumps when the events/notes DDL changes. Independent of ZJOB_DB's version. */
	const DB_VERSION        = '1.0.0';
	const DB_VERSION_OPTION = 'zjob_dossier_db_version';

	/** Visibility tiers (the Flow event envelope). internal is the most restricted. */
	const VIS_INTERNAL = 'internal';
	const VIS_STAFF    = 'staff';
	const VIS_CUSTOMER = 'customer';

	/** Actor kinds. */
	const ACTOR_USER   = 'user';
	const ACTOR_SYSTEM = 'system';

	/** Per-request short-circuit so ensure_schema() is cheap when called repeatedly. */
	private static bool $ensured = false;

	/* =======================================================================
	 * TABLE NAMES
	 * ======================================================================= */

	/** wp_zdz_job_events */
	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'zdz_job_events';
	}

	/** wp_zdz_job_notes (owned here for the single-migrator boot; read by ZJOB_Notes). */
	public static function notes_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'zdz_job_notes';
	}

	/* =======================================================================
	 * SCHEMA SELF-BOOT (idempotent; version-gated; never seeds data)
	 * ======================================================================= */

	/**
	 * Create/upgrade the two dossier tables. Runs dbDelta only when the stored version
	 * is behind OR a table is physically missing (covers zip-replace upgrades that skip
	 * activation and folder-copy first installs), exactly like ZJOB_DB::maybe_upgrade().
	 */
	public static function ensure_schema(): void {
		if ( self::$ensured ) {
			return;
		}
		global $wpdb;

		$events = self::table();
		$notes  = self::notes_table();

		$events_present = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $events ) ) === $events );
		$notes_present  = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $notes ) ) === $notes );
		$version_ok     = ( get_option( self::DB_VERSION_OPTION ) === self::DB_VERSION );

		if ( $events_present && $notes_present && $version_ok ) {
			self::$ensured = true;
			return;
		}

		$charset_collate = $wpdb->get_charset_collate();

		// Narrative history. `summary` is the pre-composed one-liner; `context_json`
		// keeps the structured detail without ever being required to render the line.
		$sql_events = "CREATE TABLE {$events} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			job_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			event_type VARCHAR(64) NOT NULL DEFAULT '',
			summary VARCHAR(255) NOT NULL DEFAULT '',
			visibility VARCHAR(16) NOT NULL DEFAULT 'staff',
			actor_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			actor_kind VARCHAR(16) NOT NULL DEFAULT 'user',
			sp_code VARCHAR(32) NOT NULL DEFAULT '',
			context_json LONGTEXT NULL,
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY job_id (job_id),
			KEY job_created (job_id, created_at),
			KEY event_type (event_type),
			KEY visibility (visibility)
		) {$charset_collate};";

		// Threaded notes. parent_id 0 = a top-level thread root; else the root's id.
		// visibility defaults to 'internal' — the safest tier for the bluntest PII.
		$sql_notes = "CREATE TABLE {$notes} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			job_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			parent_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			author_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			visibility VARCHAR(16) NOT NULL DEFAULT 'internal',
			body TEXT NULL,
			edited_by BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			updated_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			deleted_at DATETIME NULL DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY job_id (job_id),
			KEY job_thread (job_id, parent_id),
			KEY job_visibility (job_id, visibility)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql_events );
		dbDelta( $sql_notes );

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
		self::$ensured = true;
	}

	/* =======================================================================
	 * WRITE (append-only)
	 * ======================================================================= */

	/**
	 * Append one event to a job's history. Best-effort: a failure to record a
	 * narrative line never throws (writing the story is bookkeeping and bookkeeping
	 * does not get to break the work).
	 *
	 * @param int    $job_id     The job this event belongs to.
	 * @param string $event_type A stable slug — e.g. 'created', 'status_changed',
	 *                           'scheduled', 'worker_done', 'note_added', 'photo_added'.
	 * @param array  $args {
	 *     @type string $summary    Explicit one-liner. Omit to compose one from
	 *                              $event_type + $context (the default, recommended).
	 *     @type string $visibility internal|staff|customer. Default 'staff'. An
	 *                              unknown value is clamped to 'staff'.
	 *     @type int    $actor_id   Who acted (0 => a system actor).
	 *     @type string $actor_kind user|system. Inferred from actor_id when omitted.
	 *     @type string $sp_code    Salesperson short-code shim (optional).
	 *     @type array  $context    Structured detail (stored as JSON; whitelisted keys
	 *                              feed the composed summary).
	 * }
	 * @return int The new event id, or 0 on failure.
	 */
	public static function record( int $job_id, string $event_type, array $args = [] ): int {
		if ( $job_id <= 0 ) {
			return 0;
		}
		self::ensure_schema();
		global $wpdb;

		$event_type = sanitize_key( $event_type );
		if ( '' === $event_type ) {
			$event_type = 'event';
		}

		$context = isset( $args['context'] ) && is_array( $args['context'] ) ? $args['context'] : [];

		$summary = isset( $args['summary'] ) && '' !== (string) $args['summary']
			? sanitize_text_field( (string) $args['summary'] )
			: self::compose_summary( $event_type, $context );
		// Hard cap to the column width (multibyte-safe).
		if ( function_exists( 'mb_substr' ) ) {
			$summary = mb_substr( $summary, 0, 255 );
		} else {
			$summary = substr( $summary, 0, 255 );
		}

		$visibility = self::clamp_visibility( (string) ( $args['visibility'] ?? self::VIS_STAFF ) );

		$actor_id   = max( 0, (int) ( $args['actor_id'] ?? 0 ) );
		$actor_kind = isset( $args['actor_kind'] )
			? sanitize_key( (string) $args['actor_kind'] )
			: ( $actor_id > 0 ? self::ACTOR_USER : self::ACTOR_SYSTEM );
		if ( ! in_array( $actor_kind, [ self::ACTOR_USER, self::ACTOR_SYSTEM ], true ) ) {
			$actor_kind = $actor_id > 0 ? self::ACTOR_USER : self::ACTOR_SYSTEM;
		}

		$sp_code = isset( $args['sp_code'] ) ? substr( sanitize_text_field( (string) $args['sp_code'] ), 0, 32 ) : '';

		$now = current_time( 'mysql' );

		$ok = $wpdb->insert(
			self::table(),
			[
				'job_id'       => $job_id,
				'event_type'   => $event_type,
				'summary'      => $summary,
				'visibility'   => $visibility,
				'actor_id'     => $actor_id,
				'actor_kind'   => $actor_kind,
				'sp_code'      => $sp_code,
				'context_json' => empty( $context ) ? null : wp_json_encode( $context ),
				'created_at'   => $now,
			],
			[ '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s' ]
		);

		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/* =======================================================================
	 * READ
	 * ======================================================================= */

	/**
	 * The narrative for a set of jobs, newest first by default.
	 *
	 * VISIBILITY CEILING (the customer-safe door): pass $opts['max_visibility'] to cap
	 * how sensitive a row may be. The ceiling is enforced IN SQL, not filtered after:
	 *   - 'customer' -> ONLY customer-visible events (what a receipt renderer asks for)
	 *   - 'staff'    -> customer + staff (no internal)
	 *   - 'internal' -> every tier (the default; the staff Record panel, already gated
	 *                   upstream by per-component openability)
	 * There is intentionally no boolean toggle — a caller chooses the ceiling by name,
	 * and a customer surface simply never names anything above 'customer'.
	 *
	 * @param int[] $job_ids
	 * @param array $opts { limit:int=200, order:'DESC'|'ASC'='DESC',
	 *                       event_type:string='', max_visibility:string='internal' }
	 * @return array<int,array> event rows (context decoded into 'context')
	 */
	public static function for_jobs( array $job_ids, array $opts = [] ): array {
		$job_ids = self::clean_ids( $job_ids );
		if ( empty( $job_ids ) ) {
			return [];
		}
		self::ensure_schema();
		global $wpdb;

		$where  = [];
		$params = [];

		$ph = implode( ',', array_fill( 0, count( $job_ids ), '%d' ) );
		$where[] = "job_id IN ({$ph})";
		$params  = array_merge( $params, $job_ids );

		// Visibility ceiling -> the exact tiers allowed, enforced in SQL.
		$allowed = self::tiers_up_to( (string) ( $opts['max_visibility'] ?? self::VIS_INTERNAL ) );
		if ( ! empty( $allowed ) ) {
			$vph     = implode( ',', array_fill( 0, count( $allowed ), '%s' ) );
			$where[] = "visibility IN ({$vph})";
			$params  = array_merge( $params, $allowed );
		} else {
			// An unknown ceiling is fail-closed: show nothing rather than everything.
			return [];
		}

		if ( ! empty( $opts['event_type'] ) ) {
			$where[]  = 'event_type = %s';
			$params[] = sanitize_key( (string) $opts['event_type'] );
		}

		$order = ( strtoupper( (string) ( $opts['order'] ?? 'DESC' ) ) === 'ASC' ) ? 'ASC' : 'DESC';
		$limit = (int) ( $opts['limit'] ?? 200 );
		$limit = max( 1, min( 1000, $limit ) );

		$table = self::table();
		$sql   = "SELECT * FROM {$table} WHERE " . implode( ' AND ', $where )
			. " ORDER BY created_at {$order}, id {$order} LIMIT {$limit}";

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
		if ( ! $rows ) {
			return [];
		}

		foreach ( $rows as &$r ) {
			$r['context'] = ! empty( $r['context_json'] ) ? (array) json_decode( (string) $r['context_json'], true ) : [];
		}
		unset( $r );

		return $rows;
	}

	/** Convenience: the narrative for a single job. */
	public static function for_job( int $job_id, array $opts = [] ): array {
		return self::for_jobs( [ $job_id ], $opts );
	}

	/* =======================================================================
	 * INTERNAL
	 * ======================================================================= */

	/**
	 * Compose the one-line human summary at write time. NEUTRAL by construction — it
	 * reads only a small whitelist of structured context keys (statuses, counts, a
	 * pre-formatted 'when'), never a customer name/address, so a narrative line can
	 * never smuggle raw PII into a place a count-only reader trusts.
	 */
	private static function compose_summary( string $event_type, array $ctx ): string {
		switch ( $event_type ) {
			case 'created':
				return __( 'Job created', 'zorderz' );
			case 'assigned':
				return __( 'Job assigned to a worker', 'zorderz' );
			case 'unassigned':
				return __( 'Job assignment cleared', 'zorderz' );
			case 'status_changed':
				$from = isset( $ctx['from'] ) ? sanitize_key( (string) $ctx['from'] ) : '';
				$to   = isset( $ctx['to'] ) ? sanitize_key( (string) $ctx['to'] ) : '';
				if ( '' !== $from && '' !== $to ) {
					/* translators: 1: previous status slug, 2: new status slug. */
					return sprintf( __( 'Status changed from %1$s to %2$s', 'zorderz' ), $from, $to );
				}
				if ( '' !== $to ) {
					/* translators: %s: new status slug. */
					return sprintf( __( 'Status set to %s', 'zorderz' ), $to );
				}
				return __( 'Status changed', 'zorderz' );
			case 'scheduled':
				$when = isset( $ctx['when'] ) ? sanitize_text_field( (string) $ctx['when'] ) : '';
				/* translators: %s: a pre-formatted date/time. */
				return '' !== $when ? sprintf( __( 'Scheduled for %s', 'zorderz' ), $when ) : __( 'Scheduled', 'zorderz' );
			case 'rescheduled':
				return __( 'Appointment time changed', 'zorderz' );
			case 'unscheduled':
				return __( 'Appointment cleared', 'zorderz' );
			case 'worker_started':
				return __( 'Worker started', 'zorderz' );
			case 'worker_done':
				return __( 'Worker marked their part complete', 'zorderz' );
			case 'completed':
				return __( 'Job completed', 'zorderz' );
			case 'closed':
				return __( 'Job closed', 'zorderz' );
			case 'cancelled':
				return __( 'Job cancelled', 'zorderz' );
			case 'note_added':
				return __( 'Note added', 'zorderz' );
			case 'photo_added':
				$n = isset( $ctx['count'] ) ? max( 1, (int) $ctx['count'] ) : 1;
				/* translators: %d: number of photos. */
				return sprintf( _n( '%d photo added', '%d photos added', $n, 'zorderz' ), $n );
			default:
				$label = ucwords( str_replace( [ '_', '-' ], ' ', sanitize_key( $event_type ) ) );
				return '' !== $label ? $label : __( 'Event', 'zorderz' );
		}
	}

	/** Clamp a visibility to a known tier; unknown -> the staff default. */
	private static function clamp_visibility( string $v ): string {
		$v = sanitize_key( $v );
		return in_array( $v, [ self::VIS_INTERNAL, self::VIS_STAFF, self::VIS_CUSTOMER ], true ) ? $v : self::VIS_STAFF;
	}

	/** Sensitivity rank: customer (least) < staff < internal (most). */
	private static function rank( string $v ): int {
		$map = [ self::VIS_CUSTOMER => 0, self::VIS_STAFF => 1, self::VIS_INTERNAL => 2 ];
		return $map[ $v ] ?? -1;
	}

	/**
	 * The tiers at or below a ceiling. An unknown ceiling returns [] (the caller then
	 * fails closed). 'internal' ceiling returns all three tiers.
	 *
	 * @return string[]
	 */
	private static function tiers_up_to( string $ceiling ): array {
		$ceiling = sanitize_key( $ceiling );
		$rank    = self::rank( $ceiling );
		if ( $rank < 0 ) {
			return [];
		}
		$out = [];
		foreach ( [ self::VIS_CUSTOMER, self::VIS_STAFF, self::VIS_INTERNAL ] as $tier ) {
			if ( self::rank( $tier ) <= $rank ) {
				$out[] = $tier;
			}
		}
		return $out;
	}

	/** Normalise a mixed id list to clean positive ints. */
	private static function clean_ids( array $ids ): array {
		$out = [];
		foreach ( $ids as $id ) {
			$id = (int) $id;
			if ( $id > 0 ) {
				$out[ $id ] = $id;
			}
		}
		return array_values( $out );
	}
}

/*
 * Self-boot the dossier schema. Registered at file-load so the tables exist no matter
 * how the loader is wired. Version-gated + a per-request short-circuit make the repeat
 * on `init` a cheap no-op. Never seeds a row.
 */
add_action( 'after_setup_theme', [ 'ZJOB_Events', 'ensure_schema' ], 9 );
add_action( 'init', [ 'ZJOB_Events', 'ensure_schema' ], 9 );
