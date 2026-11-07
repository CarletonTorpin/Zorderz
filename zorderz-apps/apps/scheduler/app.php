<?php
/**
 * Module: Zorderz - Scheduler
 * Description: Calendar scheduler for the Zorderz dashboard. Personal appointments,
 *   team availability, and a shared team calendar. Each user connects their OWN
 *   Google or Microsoft (Exchange) calendar (Connected Calendars) so the team
 *   scheduler knows when they are busy; an optional org-wide Microsoft 365 app
 *   ("Mode A") can additionally two-way sync every mailbox. Dashboard tile +
 *   dictation-friendly availability ("mark me open these dates").
 * Version:     1.9.0
 * Author:      Zorderz
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: zorderz
 * Requires PHP: 8.0
 *
 * This is a bundled app module (loaded by zorderz-apps.php), not a standalone
 * plugin. It registers with the theme through the `zdz_register_apps` filter on
 * after_setup_theme and declines cleanly when the theme is absent.
 *
 * WHAT SHIPS (current behaviour, in brief):
 *   - The Graph sweep is BOUNDED: a per-tick budget plus a per-mailbox ceiling, at most
 *     MAX_USERS_PER_TICK mailboxes, oldest-swept first; every Graph pull holds one GET_LOCK.
 *   - A create is IDEMPOTENT on `client_request_id`; every appointment has an RFC 5545
 *     `uid` and `sequence`, a `duration`, and an append-only change log of each write's SOURCE.
 *   - REPEATING APPOINTMENTS: one row carries an RRULE (ZSCH_RRule, ZSCH_Recurrence),
 *     expanded on read (ZSCH_Series); one occurrence, this and following, or every one can
 *     be edited or deleted (ZSCH_Series_Write).
 *   - A WORK calendar may show WHAT a colleague is busy with; a personal one shows only
 *     WHEN (ZSCH_Settings::is_work_email(), fail-closed).
 *   - HANDOFF: any surface adds `data-zdz-schedule data-ns data-ref`; one delegated listener
 *     POSTs {ns,ref_id} to zorderz/v1/scheduler/intake. The origin is kept SERVER-SIDE under
 *     a single-user, single-use, 2h-TTL token and never rides the URL/body.
 *   - PARTICIPANTS: an event carries real ZDZ_Party people (the shared device is never a
 *     party); a participant SEES the event and COUNTS AS BUSY.
 *   - CONNECTED CALENDARS: each person connects their own Google or Microsoft calendar;
 *     their chosen calendars' busy times fold into availability (the busy overlay).
 *   - WRITE-BACK of appointments to connected calendars SHIPS OFF
 *     (`zsch_writeback_enabled` default 'no'): its map table and guards exist; money never
 *     rides a copy and a delegated write never carries attendees.
 *   - Availability: one tap per day, a long-press Day Editor and an overwrite guard (no
 *     accidental multi-day painting); a cascade month calendar; per-user REST write limits.
 *
 * ============================================================================
 * PROGRAMMER NOTES
 * ============================================================================
 * ROLE: Bootstrap. Constants, schema activation, class loading, theme hook,
 *       dock-tile registration, full-page route.
 *
 * WHAT IT DOES:
 * A calendar surface on the Zorderz dashboard. It works entirely on its own WP
 * tables (LOCAL-FIRST) and lets people:
 *   1. PERSONAL  — add / edit their own appointments.
 *   2. AVAILABILITY — paint the dates/times they're open or booked, so the
 *      team can see who's free for a job ("mark me open Mon–Wed").
 *   3. SHARED — a single team calendar everyone can see, colour-coded by
 *      person, for jobs and shared resources.
 *
 * TWO CALENDAR-SYNC MODES (both OPTIONAL; nothing ships configured):
 *
 *   • CONNECTED CALENDARS (the universal, primary mode). Each user connects
 *     their OWN Google and/or Microsoft (Exchange) account via per-user
 *     delegated OAuth and picks which of their calendars count as "busy". This
 *     is provider-agnostic and needs no org-wide admin — it is the mode a fresh
 *     tenant should use. Delegated (user) consent, encrypted per-account token
 *     vault; feature-flagged via option `zsch_connected_cals` (default 'no'),
 *     enabled once an admin pastes a Google and/or Microsoft delegated client
 *     id/secret. See includes/class-zsch-{vault,connections,oauth,google,
 *     graph-delegated}.php and README.md → "Connect your calendar".
 *
 *   • MODE A — org-wide Microsoft 365 app (optional, single-tenant only). Where
 *     an entire team is in ONE Microsoft 365 tenant, an admin may register ONE
 *     Azure AD app with the *application* permission `Calendars.ReadWrite`
 *     (admin-consented once) so the platform can two-way sync every mailbox by
 *     its address WITHOUT each user connecting. This is a convenience for that
 *     specific topology, NOT an assumption the platform makes. The tenant id,
 *     client id, client secret and per-user mailbox all ship EMPTY and are read
 *     from settings / a Connections binding — never hardcoded. When a second app
 *     needs Graph, promote class-zsch-graph.php to a theme ZDZ_Core_Graph behind
 *     the same signatures. See README.md → "Mode A (org-wide Azure setup)".
 *
 * Until either mode is configured the Graph/OAuth surfaces are safe no-ops and
 * the calendar runs local-first — it never errors because sync is absent.
 *
 * INTEROP (orchestrator):
 * Ships ZSCH_ZANA_Bridge with
 * read verbs (availability.lookup, schedule.lookup) + an action verb
 * (appointment.create, preview-and-confirm) so the operator bot can answer
 * "is a teammate free Thursday?" and "book me 2pm Tuesday". Tier/kiosk enforced
 * in the bridge, never in the model. Reads contacts via the theme's shared
 * clients where needed; owns its own WP tables for schedule data.
 *
 * APPOINTMENT STATES: the appointment/availability state values
 * ('confirmed'/'tentative'/'cancelled', 'open'/'busy') are the app's OWN for
 * now. When the Core Flow service lands, these bind to a tenant-declared state
 * machine via a documented Flow hook; until then they stay in-app (no competing
 * taxonomy is invented).
 *
 * DB TABLES (see db/migrate-1.0.0.php and db/migrate-1.6.0.php):
 *   wp_zsch_appointments        — events (personal + shared), local source of truth
 *   wp_zsch_availability         — free/busy blocks a user paints
 *   wp_zsch_graph_map            — local appointment id ↔ Graph event id + etag
 *   wp_zsch_settings             — per-install Graph config + sync cursors (option-backed helper)
 *   wp_zsch_calendar_accounts    — per-user connected accounts (encrypted token vault)
 *   wp_zsch_calendar_feeds       — chosen conflict calendars per account
 *   wp_zsch_external_events      — normalized external busy mirror (the sync engine fills it)
 *
 * SELF-CONTAINED:
 * The Microsoft Graph client is the plugin's own (there is no shared
 * ZDZ_Core_Graph yet). It is the FIRST Graph consumer; when a second app needs
 * Graph, promote class-zsch-graph.php to a theme ZDZ_Core_Graph and migrate
 * behind these method signatures. Likewise the token vault is deliberately
 * scheduler-scoped; factor it up into a theme service only when a second
 * per-user-OAuth consumer appears.
 *
 * CUSTOMER-FACING MODE:
 * If the analytics app's customer-facing mode is active for the current user,
 * the tile and widget hide entirely (never a visible refusal) — same contract
 * as the other apps in the bundle.
 * ============================================================================
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ── Constants ──────────────────────────────────────────────────────
define( 'ZSCH_VERSION', '1.10.0' );
define( 'ZSCH_PLUGIN_FILE', __FILE__ );
define( 'ZSCH_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'ZSCH_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'ZSCH_NONCE', 'zsch_nonce' );

// The dock-tile / app id used by the theme's springboard + role grants.
define( 'ZSCH_APP_ID', 'scheduler' );

/**
 * Roles that may use the scheduler.
 *
 * Everyone with a real seat gets it (they all have an Outlook calendar). The
 * shared kiosk `zdz_general` is given READ-ONLY access (it can see the shared
 * team calendar and who's available, but cannot create/edit/delete or sync —
 * every write path refuses it, server-side, via zsch_user_can_write()).
 *
 * Filterable so adding a role later needs no code edit.
 *
 * @return string[] lowercase role slugs
 */
function zsch_roles() {
	return (array) apply_filters( 'zsch_roles', array(
		'zdz_owner', 'zdz_admin', 'zdz_sales', 'zdz_operator', 'zdz_mfg', 'zdz_tech', 'zdz_general',
	) );
}

/**
 * Roles that get *read-only* access (the shared kiosk).
 *
 * @return string[]
 */
function zsch_read_only_roles() {
	return (array) apply_filters( 'zsch_read_only_roles', array( 'zdz_general' ) );
}

/**
 * Is this user one of the read-only roles (e.g. the shared kiosk)?
 *
 * @param int|null $user_id Defaults to current user.
 * @return bool
 */
function zsch_user_is_read_only( $user_id = null ) {
	$user_id = ( $user_id && (int) $user_id > 0 ) ? (int) $user_id : get_current_user_id();
	if ( $user_id <= 0 ) {
		return false;
	}
	if ( class_exists( 'ZDZ_User_Roles' ) && method_exists( 'ZDZ_User_Roles', 'is_shared_device' ) && ZDZ_User_Roles::is_shared_device( $user_id ) ) {
		return true;   // the shared device, by the platform's trait, whichever role carries it (no filter lifts this)
	}
	$u = get_userdata( $user_id );
	if ( ! $u ) {
		return false;
	}
	$ro = array_map( 'strtolower', zsch_read_only_roles() );
	foreach ( (array) $u->roles as $role ) {
		if ( in_array( strtolower( (string) $role ), $ro, true ) ) {
			return true;
		}
	}
	return false;
}

/**
 * May this user perform write actions (create/edit/delete/sync)?
 * Inverse of zsch_user_is_read_only().
 *
 * @param int|null $user_id
 * @return bool
 */
function zsch_user_can_write( $user_id = null ) {
	return ! zsch_user_is_read_only( $user_id );
}

/**
 * Does the user have access to the scheduler?
 *
 * Dual capability check (matches Messaging/Analytics): WordPress admin (manage_options)
 * OR Zorderz custom (zdz_access_app). Read-only roles are admitted here
 * too (write paths are blocked separately).
 *
 * @param int|null $user_id
 * @return bool
 */
function zsch_user_has_access( $user_id = null ) {
	if ( zsch_user_is_read_only( $user_id ) ) {
		return true;
	}
	if ( $user_id && (int) $user_id > 0 ) {
		return user_can( (int) $user_id, 'manage_options' )
		    || user_can( (int) $user_id, 'zdz_access_app' );
	}
	return current_user_can( 'manage_options' )
	    || current_user_can( 'zdz_access_app' );
}

// ── Activation ─────────────────────────────────────────────────────
register_activation_hook( __FILE__, 'zsch_activate' );

function zsch_activate() {
	require_once ZSCH_PLUGIN_DIR . 'db/migrate-1.0.0.php';
	ZSCH_Migrate_1_0_0::run();
	// v1.6.0 — Connected Calendars tables (additive, idempotent).
	require_once ZSCH_PLUGIN_DIR . 'db/migrate-1.6.0.php';
	ZSCH_Migrate_1_6_0::run();
	// v1.9.0 — Participants + Write-back map (additive, idempotent).
	require_once ZSCH_PLUGIN_DIR . 'db/migrate-1.9.0.php';
	ZSCH_Migrate_1_9_0::run();
	// v1.10.0 — identity, duration, idempotent create, recurrence, change log (additive, idempotent).
	require_once ZSCH_PLUGIN_DIR . 'db/migrate-1.10.0.php';
	ZSCH_Migrate_1_10_0::run();

	// Make the tile visible to every eligible user on first install. The theme
	// gates tile visibility behind per-user `zdz_allowed_apps` meta for
	// non-admin roles; without this grant, sales/operator/mfg/tech/general
	// users could activate the plugin and never see the "Schedule" tile.
	zsch_grant_tile_to_all_eligible_users();

	// Sync cron — pulls Graph changes for connected mailboxes every 5 minutes.
	if ( ! wp_next_scheduled( 'zsch_cron_sync' ) ) {
		wp_schedule_event( time() + 120, 'zsch_every_five_minutes', 'zsch_cron_sync' );
	}

	update_option( 'zsch_db_version', ZSCH_VERSION );
}

/**
 * Grant the scheduler tile to every user with `zdz_access_app` (unless
 * explicitly denied). Idempotent. Admin-class roles bypass the allowed-apps
 * meta in the theme, so we skip them.
 */
function zsch_grant_tile_to_all_eligible_users() {
	$users = get_users( array(
		'fields'     => array( 'ID' ),
		'capability' => 'zdz_access_app',
		'number'     => -1,
	) );
	foreach ( $users as $u ) {
		zsch_grant_tile_to_user( (int) $u->ID );
	}
}

/**
 * Grant to one user. Hooked on wp_login so users created/promoted after the
 * scheduler was activated also get the tile.
 */
function zsch_grant_tile_to_user( $user_id ) {
	$user_id = (int) $user_id;
	if ( $user_id <= 0 ) {
		return;
	}
	if ( ! zsch_user_has_access( $user_id ) ) {
		return;
	}

	$denied = get_user_meta( $user_id, 'zdz_denied_apps', true );
	if ( is_array( $denied ) && in_array( ZSCH_APP_ID, $denied, true ) ) {
		return; // Admin explicitly denied — respect it.
	}
	$allowed = get_user_meta( $user_id, 'zdz_allowed_apps', true );
	if ( ! is_array( $allowed ) ) {
		$allowed = array();
	}
	if ( ! in_array( ZSCH_APP_ID, $allowed, true ) ) {
		$allowed[] = ZSCH_APP_ID;
		update_user_meta( $user_id, 'zdz_allowed_apps', $allowed );
	}
}

// On login, top up the grant for any user who doesn't yet have it.
add_action( 'wp_login', function ( $user_login, $user ) {
	if ( $user instanceof WP_User ) {
		zsch_grant_tile_to_user( $user->ID );
	}
}, 10, 2 );

// Custom 5-minute cadence for the Graph sync puller.
add_filter( 'cron_schedules', function ( $schedules ) {
	if ( empty( $schedules['zsch_every_five_minutes'] ) ) {
		$schedules['zsch_every_five_minutes'] = array(
			'interval' => 5 * MINUTE_IN_SECONDS,
			// Plain string, NOT __(): the cron_schedules filter fires before
			// `init`, so a translation call here trips WP 6.7+'s
			// "_load_textdomain_just_in_time" notice (which can corrupt the
			// Analytics chat's AJAX JSON). Admin-only label; no early i18n needed.
			'display'  => 'Every 5 minutes (Zorderz Scheduler)',
		);
	}
	return $schedules;
} );

// ── Deactivation ───────────────────────────────────────────────────
register_deactivation_hook( __FILE__, 'zsch_deactivate' );

function zsch_deactivate() {
	// Deactivating preserves data — do NOT drop tables.
	wp_clear_scheduled_hook( 'zsch_cron_sync' );
}

// ── DB upgrade on version bump + SELF-HEALING table check ──────────
add_action( 'plugins_loaded', 'zsch_maybe_upgrade', 5 );

/**
 * Ensure the plugin's tables exist and are current.
 *
 * Runs the migration when EITHER the stored db version is behind, OR the tables
 * are physically missing. The second condition is the important one: when the
 * plugin is updated by OVERWRITING the folder (drag-and-drop, SFTP, or some
 * "upload zip / replace" flows), WordPress does NOT fire register_activation_hook
 * — so a first-time install done that way would never create the tables, and
 * every insert would fail ("Database insert failed."). Checking for the tables
 * on load makes the schema self-heal no matter how the files arrived.
 *
 * dbDelta (inside the migrations) is idempotent, so re-running is safe.
 */
function zsch_maybe_upgrade() {
	global $wpdb;

	$db_ver       = get_option( 'zsch_db_version', '0' );
	$needs_by_ver = version_compare( $db_ver, ZSCH_VERSION, '<' );
	$needs_tables = ! zsch_tables_exist();

	if ( ! $needs_by_ver && ! $needs_tables ) {
		return;                       // the common case: two cheap reads, done.
	}

	/* SINGLE-FLIGHT. Several requests can arrive in the same second after an
	 * update (an admin-ajax call, a REST fetch, a page load) and all of them
	 * would otherwise run the same ALTERs: the losers log "Duplicate column"
	 * errors, and — the real problem — a request can read a HALF-migrated schema
	 * and INSERT into a column that is not there yet. MySQL GET_LOCK is atomic
	 * and released when the connection dies. A waiter BLOCKS rather than racing,
	 * and once it gets the lock it RE-CHECKS, because the request it waited on
	 * has almost certainly just done the work. */
	// The same lock name ZSCH_Appointments::migrate_once() takes: one schema, one lock.
	$lock = 'zsch_migrate_' . md5( (string) $wpdb->prefix . ( defined( 'DB_NAME' ) ? DB_NAME : '' ) );
	$got  = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $lock, 10 ) );

	if ( '1' !== (string) $got ) {
		/* Another request is migrating and taking longer than expected. Do NOT
		 * migrate anyway — that is the race this lock exists to stop. The next
		 * page load retries. */
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( 'Zorderz Scheduler: migration lock busy — another request is upgrading the schema; skipping this pass.' );
		}
		return;
	}

	try {
		// Holder re-reads: the request we queued behind may have just finished.
		$db_ver       = get_option( 'zsch_db_version', '0' );
		$needs_by_ver = version_compare( $db_ver, ZSCH_VERSION, '<' );
		$needs_tables = ! zsch_tables_exist();
		if ( ! $needs_by_ver && ! $needs_tables ) {
			return;
		}

		require_once ZSCH_PLUGIN_DIR . 'db/migrate-1.0.0.php';
		ZSCH_Migrate_1_0_0::run();
		// v1.6.0 — Connected Calendars tables ride the same self-heal.
		require_once ZSCH_PLUGIN_DIR . 'db/migrate-1.6.0.php';
		ZSCH_Migrate_1_6_0::run();
		// v1.9.0 — Participants + Write-back map ride the same self-heal.
		require_once ZSCH_PLUGIN_DIR . 'db/migrate-1.9.0.php';
		ZSCH_Migrate_1_9_0::run();
		// v1.10.0 — identity, duration, idempotency, recurrence, change log.
		require_once ZSCH_PLUGIN_DIR . 'db/migrate-1.10.0.php';
		ZSCH_Migrate_1_10_0::run();
		update_option( 'zsch_db_version', ZSCH_VERSION );

		if ( $needs_tables && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( 'Zorderz Scheduler: tables were missing — ran schema migration on load (likely a file-overwrite update).' );
		}
	} finally {
		// Always, even if a migration threw.
		$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
	}
}

/**
 * Do all scheduler tables exist?
 *
 * @return bool
 */
function zsch_tables_exist() {
	global $wpdb;
	$need = array(
		$wpdb->prefix . 'zsch_appointments',
		$wpdb->prefix . 'zsch_availability',
		$wpdb->prefix . 'zsch_graph_map',
		// v1.6.0 — Connected Calendars.
		$wpdb->prefix . 'zsch_calendar_accounts',
		$wpdb->prefix . 'zsch_calendar_feeds',
		$wpdb->prefix . 'zsch_external_events',
		// v1.9.0 — Participants + Write-back map.
		$wpdb->prefix . 'zsch_participants',
		$wpdb->prefix . 'zsch_writeback_map',
		// v1.10.0 — the append-only change log.
		$wpdb->prefix . 'zsch_change_log',
	);
	foreach ( $need as $t ) {
		// $wpdb->get_var with SHOW TABLES LIKE returns the table name if present.
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) );
		if ( $found !== $t ) {
			return false;
		}
	}

	/* Columns added to PRE-EXISTING tables, which the table check above cannot
	 * see. Without this a folder-overwrite update ships code whose every create()
	 * and every Graph pull writes to a column that is not there. The value is a
	 * LIST per table, so two migrations adding columns to one table cannot
	 * silently replace each other's entry. Cheap: one SHOW COLUMNS per entry, and
	 * only when every table is present. */
	$columns = array(
		$wpdb->prefix . 'zsch_graph_map'    => array( 'pulled_body_hash' ),
		$wpdb->prefix . 'zsch_appointments' => array( 'client_request_id', 'duration', 'uid', 'sequence', 'rrule', 'recurrence_id', 'series_uid', 'related_to', 'series_until_utc', 'override_key' ),
	);
	foreach ( $columns as $table => $cols ) {
		foreach ( (array) $cols as $column ) {
			$found = $wpdb->get_var( $wpdb->prepare(
				'SHOW COLUMNS FROM `' . str_replace( '`', '', $table ) . '` LIKE %s', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$column
			) );
			if ( $found !== $column ) {
				return false;
			}
		}
	}
	return true;
}

/**
 * Should the version beacon fire, and which version is it changing FROM? PURE.
 *
 * The beacon is throttled per VERSION, not per hour: a stored value that is
 * not the running version fires on the very next load whatever the remaining
 * TTL, so a deploy is always visible in the log; an unchanged version still
 * beacons at most hourly.
 *
 * @param mixed  $stored   the transient: a version string, the legacy bare 1, or false.
 * @param string $current  ZSCH_VERSION.
 * @return array{fire:bool,from:string}
 */
function zsch_beacon_decide( $stored, $current ) {
	if ( (string) $current === (string) $stored ) {
		return array( 'fire' => false, 'from' => '' );
	}
	// Only report a FROM version when the stored value looks like one (not the legacy bare 1).
	$from = ( is_string( $stored ) && preg_match( '/^\d+\.\d+/', $stored ) ) ? $stored : '';
	return array( 'fire' => true, 'from' => $from );
}

/**
 * Should the ZONE-DATABASE beacon speak? PURE.
 *
 * Same shape as zsch_beacon_decide(), applied to a value that is not a version
 * number. What is throttled is the COST, not the SIGNAL: computing a
 * fingerprint walks years of transitions for every declared zone, so a
 * transient decides how often we LOOK; the comparison is against a recorded
 * option, never the transient, so a change that happened is always reported
 * when we do look.
 *
 * @param string $stored  the fingerprint last recorded ('' = never looked).
 * @param string $current the fingerprint computed now.
 * @return array{fire:bool,from:string}
 */
function zsch_tz_beacon_decide( $stored, $current ) {
	$current = (string) $current;
	$stored  = (string) $stored;

	if ( '' === $current ) {
		return array( 'fire' => false, 'from' => '' );   // nothing was read; nothing to say
	}
	if ( $current === $stored ) {
		return array( 'fire' => false, 'from' => '' );
	}
	// A first-ever look is worth one line, but it is not a CHANGE.
	return array( 'fire' => true, 'from' => ( '' === $stored ) ? '' : $stored );
}

// ── Load all class files ───────────────────────────────────────────
add_action( 'plugins_loaded', 'zsch_load_includes' );

function zsch_load_includes() {
	$dir = ZSCH_PLUGIN_DIR . 'includes/';
	foreach ( glob( $dir . '*.php' ) as $file ) {
		require_once $file;
	}

	/* VERSION BEACON. Log the running version to this site's own PHP error log
	 * (nothing leaves the server), so the live build can be confirmed from the
	 * log — critical when a stale cache or install leaves an old version
	 * running after an update. Throttled per VERSION (zsch_beacon_decide()): a
	 * version change is reported on the very next load, an unchanged version at
	 * most hourly. A deploy history is one grep:
	 *
	 *     grep "Zorderz Scheduler ACTIVE" debug.log
	 */
	$zsch_beacon = zsch_beacon_decide( get_transient( 'zsch_version_beacon' ), ZSCH_VERSION );
	if ( $zsch_beacon['fire'] ) {
		set_transient( 'zsch_version_beacon', ZSCH_VERSION, HOUR_IN_SECONDS );
		error_log(
			'Zorderz Scheduler ACTIVE — version ' . ZSCH_VERSION
			. ( '' !== $zsch_beacon['from'] ? ' (VERSION CHANGED from ' . $zsch_beacon['from'] . ')' : '' )
			. ' (file: ' . __FILE__ . ')'
		);
	}

	/* ZONE-DATABASE BEACON. The recurrence engine relies on PHP resolving a
	 * spring-forward gap with the pre-gap offset and a fall-back overlap to the
	 * first instant. Both come from the zone database, so the data is watched
	 * rather than a version string: the fingerprint hashes the real transition
	 * table of the declared zones (ZSCH_Tzdata), and the version is recorded too.
	 *   fingerprint changed                 → something we DEPEND ON moved (the alarm)
	 *   version changed, fingerprint same   → updated, nothing we depend on moved (the receipt)
	 * The transient throttles how often the fingerprint is COMPUTED (twice a
	 * day); the options are what it is compared against. Local log only. */
	if ( class_exists( 'ZSCH_Tzdata' ) && false === get_transient( 'zsch_tzdata_look' ) ) {
		set_transient( 'zsch_tzdata_look', 1, 12 * HOUR_IN_SECONDS );

		$zsch_tz_fp         = ZSCH_Tzdata::fingerprint( ZSCH_Tzdata::zones() );
		$zsch_tz_stored     = (string) get_option( 'zsch_tzdata_fingerprint', '' );
		$zsch_tz_decide     = zsch_tz_beacon_decide( $zsch_tz_stored, $zsch_tz_fp );
		$zsch_tz_ver        = ZSCH_Tzdata::version();
		$zsch_tz_ver_stored = (string) get_option( 'zsch_tzdata_version', '' );
		$zsch_tz_ver_decide = zsch_tz_beacon_decide( $zsch_tz_ver_stored, $zsch_tz_ver );

		if ( $zsch_tz_decide['fire'] ) {
			update_option( 'zsch_tzdata_fingerprint', $zsch_tz_fp, false );
			update_option( 'zsch_tzdata_version', $zsch_tz_ver, false );
			error_log(
				'Zorderz Scheduler TZDATA — zone rules ' . $zsch_tz_fp
				. ' (php reports ' . $zsch_tz_ver . ')'
				. ( '' !== $zsch_tz_decide['from']
					? ' (TZDATA CHANGED from ' . $zsch_tz_decide['from'] . ')'
					: ' (first record)' )
			);
		} elseif ( $zsch_tz_ver_decide['fire'] ) {
			// Only reachable when the fingerprint held steady: updated, nothing we watch moved.
			update_option( 'zsch_tzdata_version', $zsch_tz_ver, false );
			error_log(
				'Zorderz Scheduler TZDATA — zone database now ' . $zsch_tz_ver
				. ( '' !== $zsch_tz_ver_decide['from']
					? ' (TZDATA VERSION CHANGED from ' . $zsch_tz_ver_decide['from'] . ')'
					: ' (first record)' )
				. ' — zone rules ' . $zsch_tz_fp . ' unmoved, nothing we depend on changed'
			);
		}

		// A broken assumption is not throttled by sameness: logged every time we look.
		$zsch_tz_checks = ZSCH_Tzdata::selftest();
		if ( 'fail' === ZSCH_Tzdata::selftest_status( $zsch_tz_checks ) ) {
			foreach ( $zsch_tz_checks as $zsch_tz_check ) {
				if ( 'fail' === $zsch_tz_check['status'] ) {
					error_log(
						'Zorderz Scheduler TZDATA SELFTEST FAILED — ' . $zsch_tz_check['name']
						. ' — ' . $zsch_tz_check['detail']
					);
				}
			}
		}
	}

	// ── Register capabilities with the orchestrator registry. ──
	// The bridge shapes its verbs in ZSCH_ZANA_Bridge::get_capability_descriptor()
	// (availability.lookup, schedule.lookup, appointment.create) with honest
	// kiosk/side_effect flags; this publishes them via the `zdz_register_capabilities`
	// filter, and the central resolver (ZDZ_Capabilities::invoke) enforces them. We
	// pull straight from the descriptor so the registration can never drift from the
	// bridge's own declared posture (the same pattern as Leads).
	//
	// The [ZSCH_AVAIL]/[ZSCH_SCHED]/[ZSCH_BOOK] marker handlers and the assistant's
	// kiosk rule belong to the Analytics app; this app ships everything that is its own.
	add_filter( 'zdz_register_capabilities', function ( $caps ) {
		if ( class_exists( 'ZSCH_ZANA_Bridge' )
			&& method_exists( 'ZSCH_ZANA_Bridge', 'get_capability_descriptor' ) ) {
			foreach ( ZSCH_ZANA_Bridge::get_capability_descriptor() as $verb => $descriptor ) {
				$caps[ $verb ] = $descriptor;
			}
		}
		// schedule.my_counts — counts for a home tile; its own class and its own
		// descriptor (app, needs_app, side_effect: false declared there).
		if ( class_exists( 'ZSCH_My_Counts' ) ) {
			$caps = ZSCH_My_Counts::register( $caps );
		}
		return $caps;
	} );

	// Wire the sync cron callback once classes are loaded.
	add_action( 'zsch_cron_sync', array( 'ZSCH_Graph', 'cron_sync_all' ) );

	/* Backfill `uid` and `duration` onto rows created before those columns
	 * existed. On the shared 5-minute tick, one bounded batch per call, and
	 * self-marking as done — it completes over a few ticks and then costs one
	 * option read. Never on a page load, and it never writes a start or an end. */
	if ( class_exists( 'ZSCH_Backfill' ) ) {
		add_action( 'zsch_cron_sync', array( 'ZSCH_Backfill', 'cron_tick' ) );
	}

	// v1.9.0 — Write-back subscribes the appointment lifecycle so it is
	// WIRED but OFF: every handler is a guarded no-op until `zsch_writeback_enabled`
	// flips to 'yes' (default 'no'). `created` reads the ARRAY payload (arg 1);
	// `updated`/`deleted` read the INT id (arg 1) + the payload (arg 2) — matching
	// the two fired shapes exactly.
	if ( class_exists( 'ZSCH_Writeback' ) ) {
		add_action( 'zsch_appointment_created', array( 'ZSCH_Writeback', 'on_created' ), 20, 1 );
		add_action( 'zsch_appointment_updated', array( 'ZSCH_Writeback', 'on_updated' ), 20, 2 );
		add_action( 'zsch_appointment_deleted', array( 'ZSCH_Writeback', 'on_deleted' ), 20, 2 );
	}

	// v1.9.0 — the Schedule-Job launch listener. ONE document-level delegated
	// listener powers the `data-zdz-schedule` attribute anywhere on the front end
	// for a write-capable user (kiosk excluded). It carries context, never
	// authority; the server re-derives the gate.
	add_action( 'wp_enqueue_scripts', 'zsch_enqueue_schedule_button' );

	// v1.7.0 — Connected Calendars sync: pull each user's external conflict
	// feeds into the busy mirror on the SAME 5-minute tick. Self-gated by
	// ZSCH_OAuth::feature_enabled() (no-op with the flag down) and self-locking,
	// so it can share the hook with the app-level Graph sync above safely.
	if ( class_exists( 'ZSCH_Sync' ) ) {
		add_action( 'zsch_cron_sync', array( 'ZSCH_Sync', 'cron_all' ) );
	}

	// v1.7.1 — SC1.1: register a per-user "Connected Calendars" card in the
	// theme's Settings → App Authorizations section. The theme runs its
	// app-authorizations payload through the `zdz_app_authorizations` filter;
	// without it the filter never fires (clean no-op). Gated
	// exactly like the widget card — feature ON and a write-capable (non-kiosk)
	// user — so the read-only kiosk never gets an entry (INV-Kiosk).
	add_filter( 'zdz_app_authorizations', function ( $data, $user_id ) {
		if ( ! is_array( $data ) ) {
			$data = array();
		}
		if ( ! class_exists( 'ZSCH_OAuth' ) || ! ZSCH_OAuth::feature_enabled() ) {
			return $data;
		}
		if ( function_exists( 'zsch_user_is_read_only' ) && zsch_user_is_read_only( (int) $user_id ) ) {
			return $data;
		}
		$accounts = class_exists( 'ZSCH_Connections' )
			? ZSCH_Connections::list_for_user( (int) $user_id )
			: array();
		$data['calendars'] = array(
			'connected' => ! empty( $accounts ),
			'count'     => count( (array) $accounts ),
		);
		return $data;
	}, 10, 2 );

	// Boot the AJAX router + admin page.
	if ( class_exists( 'ZSCH_Dashboard' ) ) {
		new ZSCH_Dashboard();
	}
	if ( is_admin() && class_exists( 'ZSCH_Admin' ) ) {
		new ZSCH_Admin();
	}

	// v1.6.0 — Connected Calendars OAuth routes (start/callback). The class
	// no-ops on every request unless the feature flag + provider config are
	// live; registering the hook is free. Runtime-guarded (no theme symbols
	// referenced at load time — the ZDZ_Share_Link dependency is checked inside
	// the handlers).
	if ( class_exists( 'ZSCH_OAuth' ) ) {
		ZSCH_OAuth::register();
	}

	// Full-page route — ?zsch_page=1 renders the calendar full-viewport. Kept
	// so the inline widget's "open full screen" affordance and any deep-link
	// still work, but it is NOT advertised anywhere in the nav.
	add_action( 'template_redirect', 'zsch_maybe_render_full_page' );

	// NOTE (v1.0.3): The bottom-nav tab injector is intentionally DISABLED.
	// The Scheduler is a dashboard-WIDGET-only app — it must not add a tab to the
	// bottom nav (nor a dock/sidebar tile; see springboard:false in the tile
	// config). The injector function remains below but is no longer hooked.
	// add_action( 'wp_enqueue_scripts', 'zsch_enqueue_nav_inject' );

	// v1.0.5: keep page optimizers from deferring/combining our widget script.
	add_filter( 'script_loader_tag', 'zsch_mark_no_optimize', 10, 2 );
}

/**
 * Add no-defer / no-optimize attributes to the scheduler widget <script> tags.
 *
 * NitroPack, Autoptimize, WP Rocket, SG Optimizer and Cloudflare Rocket Loader
 * all transform or defer third-party scripts by default. When the dashboard
 * shell is served from a stale cache, a deferred/combined widget.js is a common
 * reason the calendar JS doesn't run (the grid then sits empty). These flags ask
 * every common optimizer to leave these scripts untouched.
 *
 * @param string $tag    The full <script> HTML.
 * @param string $handle The script handle.
 * @return string
 */
function zsch_mark_no_optimize( $tag, $handle ) {
	// v1.6.0: the Connected Calendars bundle gets the same protection.
	if ( ! in_array( $handle, array( 'zsch-widget-js', 'zsch-connections-js' ), true ) ) {
		return $tag;
	}
	// Insert the opt-out attributes right after "<script ".
	$attrs = 'data-no-optimize="1" data-no-defer="1" data-cfasync="false" data-nitro-exclude ';
	if ( false !== strpos( $tag, '<script ' ) && false === strpos( $tag, 'data-no-optimize' ) ) {
		$tag = preg_replace( '/<script\s+/', '<script ' . $attrs, $tag, 1 );
	}
	return $tag;
}

/**
 * Enqueue the Schedule-Job launch listener (v1.9.0). Loaded for a logged-in,
 * write-capable user who has scheduler access (the kiosk is read-only → excluded,
 * INV-Kiosk), so any surface can host a `data-zdz-schedule` button. Localized with
 * the intake base URL + a REST nonce; degrades cleanly when the theme (owner of
 * ZDZ_REST_NS) is absent (empty base → the listener no-ops).
 */
function zsch_enqueue_schedule_button() {
	if ( is_admin() || ! is_user_logged_in() ) {
		return;
	}
	if ( ! function_exists( 'zsch_user_has_access' ) || ! zsch_user_has_access() ) {
		return;
	}
	if ( function_exists( 'zsch_user_is_read_only' ) && zsch_user_is_read_only() ) {
		return; // a device is never a scheduler — and never launches a handoff.
	}
	wp_enqueue_script(
		'zsch-schedule-button',
		ZSCH_PLUGIN_URL . 'assets/js/schedule-button.js',
		array(),
		ZSCH_VERSION,
		true
	);
	wp_localize_script( 'zsch-schedule-button', 'zschSchedule', array(
		'base'  => class_exists( 'ZSCH_REST' ) ? ZSCH_REST::base_url() : '',
		'nonce' => wp_create_nonce( 'wp_rest' ),
	) );
}

function zsch_enqueue_nav_inject() {
	if ( is_admin() || ! is_user_logged_in() ) {
		return;
	}
	if ( ! empty( $_GET['zsch_page'] ) ) {
		return; // don't inject into ourselves
	}
	if ( ! function_exists( 'zsch_user_has_access' ) || ! zsch_user_has_access() ) {
		return;
	}

	wp_enqueue_style(
		'zsch-nav-inject',
		ZSCH_PLUGIN_URL . 'assets/css/nav-inject.css',
		array(),
		ZSCH_VERSION
	);
	wp_enqueue_script(
		'zsch-nav-inject',
		ZSCH_PLUGIN_URL . 'assets/js/nav-inject.js',
		array(),
		ZSCH_VERSION,
		true
	);
	wp_localize_script( 'zsch-nav-inject', 'zschNavData', array(
		'pageUrl' => home_url( '/?zsch_page=1' ),
	) );
}

/**
 * Register REST routes (cross-plugin coordination + the widget's own data API).
 * Runs only on REST requests — zero cost on other page loads.
 */
add_action( 'rest_api_init', function () {
	if ( class_exists( 'ZSCH_REST' ) ) {
		ZSCH_REST::register_routes();
	}
} );

/**
 * Full-page view handler — /?zsch_page=1 opens the calendar full-viewport
 * (theme bridge iframes this). Applies the same customer-facing hide as the
 * inline widget.
 */
function zsch_maybe_render_full_page() {
	if ( empty( $_GET['zsch_page'] ) ) {
		return;
	}
	if ( ! is_user_logged_in() || ! zsch_user_has_access() ) {
		zsch_render_unavailable();
		exit;
	}
	if ( class_exists( 'ZSCH_Widget' ) && ! ZSCH_Widget::should_render() ) {
		zsch_render_unavailable();
		exit;
	}

	$widget = new ZSCH_Widget();
	status_header( 200 );
	nocache_headers();
	header( 'Content-Type: text/html; charset=utf-8' );

	$html = $widget->render_dashboard_widget( get_current_user_id(), 'fullpage' );
	if ( null === $html ) {
		zsch_render_unavailable();
		exit;
	}

	$is_embed = ! empty( $_GET['zdz_embed'] );

	echo '<!doctype html><html data-theme="system"><head><meta charset="utf-8">';
	echo '<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">';
	echo '<title>Schedule</title>';
	// Theme-sync before any CSS paint, no FOUC.
	echo '<script>(function(){try{var t=localStorage.getItem("zdz_theme");if(t){document.documentElement.setAttribute("data-theme",t);}}catch(e){}})();</script>';
	if ( $is_embed ) {
		echo '<style>';
		echo "html,body{margin:0;padding:0;height:100%;overflow:hidden;background:var(--sys-bg,transparent);";
		echo "font-family:'Inter',system-ui,-apple-system,sans-serif;-webkit-font-smoothing:antialiased;color:var(--sys-text,#e2e8f0);}";
		echo '.zsch-fullpage .zsch-w{height:100vh;max-height:100vh;border:0;border-radius:0;}';
		echo '</style>';
		$theme_css_url = get_stylesheet_uri();
		if ( $theme_css_url ) {
			echo '<link rel="stylesheet" href="' . esc_url( $theme_css_url ) . '">';
		}
		wp_print_styles( array( 'zsch-widget-css' ) );
		wp_print_scripts( array( 'zsch-widget-js' ) );
	} else {
		wp_head();
	}
	echo '</head><body class="zsch-fullpage' . ( $is_embed ? ' zsch-embed' : '' ) . '">';
	echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	if ( ! $is_embed ) {
		wp_footer();
	}
	echo '</body></html>';
	exit;
}

/**
 * Render the theme's "not available" fallback. 200, not 403 — never leak
 * existence (matches Messaging).
 */
function zsch_render_unavailable() {
	status_header( 200 );
	nocache_headers();
	header( 'Content-Type: text/html; charset=utf-8' );
	echo '<!doctype html><html><head><meta charset="utf-8"><title>Not available</title>';
	echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
	echo '<style>body{font-family:system-ui,sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;color:#64748b;background:#f8fafc}p{max-width:28ch;text-align:center}</style>';
	echo '</head><body><p>This feature isn\'t available right now.</p></body></html>';
}

/**
 * ────────────────────────────────────────────────────────
 * ZORDERZ THEME INTEGRATION — Tier 2 inline widget + Tier 1 iframe.
 * MUST run inside after_setup_theme (theme interfaces aren't defined earlier).
 * ────────────────────────────────────────────────────────
 */
add_action( 'after_setup_theme', function () {

	if ( ! class_exists( 'ZSCH_Widget' ) ) {
		return; // classes load on plugins_loaded(5), before this — defensive.
	}

	// Shared tile config used by both interface tiers.
	//
	// springboard => false : the Scheduler is a DASHBOARD-WIDGET-ONLY app. It must
	// NOT appear as its own dock tile, left-sidebar entry, recent chip, or search
	// result — only the inline widget on the dashboard. The theme's app-list
	// builders (getVisibleApps / dock / sticky bar / recents) all skip apps with
	// springboard:false via isSecondarySurface(), while renderWidgets() still
	// renders the inline widget (it keys only on bridge_type:'inline_widget' +
	// widget_html). Same pattern the Media library uses for its full-screen
	// surface. (Requires theme v2.21.0+; on older themes the key is simply
	// ignored and the tile shows as before — harmless.)
	$config = array(
		'id'          => ZSCH_APP_ID,
		'nm'          => 'Schedule',
		'icon'        => 'calendar',
		'cat'         => 'Team',
		'cc'          => '#0EA5E9',
		'desc'        => 'Appointments, team availability & shared calendar — synced with Outlook.',
		'roles'       => zsch_roles(),
		// v1.3.0: Scheduler now gets a real app ICON like every other app —
		// 'springboard' => true means it appears in the top quick-link dock, the
		// Apps grid, recents, and search. Because bridge_type is 'inline_widget'
		// (set per tier below), TAPPING the icon JUMPS to the Schedule widget on
		// the dashboard (it does NOT open a separate full-screen page) — exactly
		// how the other inline apps behave. Previously this was springboard:false
		// (widget-only, no icon), which the field found un-discoverable.
		'springboard' => true,
		'admin_url'   => home_url( '/?zsch_page=1' ),
	);

	// ── TIER 2 — inline dashboard widget (theme v2.0+) ──
	if ( interface_exists( '\Zorderz\Widget_App_Interface' ) ) {

		class ZSCH_App implements \Zorderz\Widget_App_Interface {

			private $cfg;

			public function __construct( array $cfg ) {
				$this->cfg = $cfg;
				$this->cfg['bridge_type'] = 'inline_widget';
			}

			public function get_config(): array {
				return $this->cfg;
			}

			public function render_mobile_view( int $user_id ): void {
				echo '<iframe src="' . esc_url( home_url( '/?zsch_page=1&zdz_mobile=1' ) ) . '" style="width:100%;height:100%;border:none;" title="Schedule"></iframe>';
			}

			public function render_dashboard_widget( int $user_id ): ?string {
				if ( ! ZSCH_Widget::should_render() ) {
					return null;
				}
				$widget = new ZSCH_Widget();
				return $widget->render_dashboard_widget( $user_id, 'inline' );
			}
		}

		add_filter( 'zdz_register_apps', function ( $apps ) use ( $config ) {
			$apps[ ZSCH_APP_ID ] = new ZSCH_App( $config );
			return $apps;
		} );

	// ── TIER 1 — standard iframe tile (theme v1.x) ──
	} elseif ( interface_exists( '\Zorderz\App_Interface' ) ) {

		class ZSCH_App implements \Zorderz\App_Interface {

			private $cfg;

			public function __construct( array $cfg ) {
				$this->cfg = $cfg;
				$this->cfg['bridge_type'] = 'iframe';
			}

			public function get_config(): array {
				return $this->cfg;
			}

			public function render_mobile_view( int $user_id ): void {
				echo '<iframe src="' . esc_url( home_url( '/?zsch_page=1&zdz_mobile=1' ) ) . '" style="width:100%;height:100%;border:none;" title="Schedule"></iframe>';
			}
		}

		add_filter( 'zdz_register_apps', function ( $apps ) use ( $config ) {
			$apps[ ZSCH_APP_ID ] = new ZSCH_App( $config );
			return $apps;
		} );
	}
} );

