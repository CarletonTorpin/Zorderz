<?php
/**
 * TS Scheduler schema migration — v1.0.0
 *
 * Idempotent. Safe to run repeatedly; dbDelta handles schema diffs.
 *
 * TABLE INVENTORY:
 *   wp_zsch_appointments   events (personal + shared). Local source of truth.
 *   wp_zsch_availability    free/busy blocks a user paints (own dates).
 *   wp_zsch_graph_map       local appointment id ↔ Microsoft Graph event id.
 *
 * DESIGN NOTES:
 *   - `calendar_scope` on appointments: 'personal' (only the owner + admins,
 *     and the owner's Outlook) vs 'shared' (whole team, colour-coded). This is
 *     the single column that drives all three product modes off ONE table.
 *   - Times are stored as UTC datetimes (start_utc / end_utc). The local IANA
 *     time zone string is kept alongside so the UI can render the wall-clock
 *     the user typed, and so Graph round-trips preserve it (Graph wants
 *     start/end with an explicit timeZone).
 *   - graph_map is a SEPARATE table (not columns on appointments) because not
 *     every appointment is synced (kiosk-created shared notes never sync), and
 *     because the etag/last_synced bookkeeping is sync-engine private — keeping
 *     it out of the hot appointment row keeps reads lean.
 *
 * INDEX DISCIPLINE:
 *   - Hot query "events for user U between A and B" → idx_owner_start.
 *   - Hot query "shared events between A and B"     → idx_scope_start.
 *   - Hot query "availability for user U in range"  → idx_avail_owner_start.
 *   - Sync lookup "find local row for a Graph id"   → UNIQUE idx_graph_event.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZSCH_Migrate_1_0_0 {

	public static function run() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$p       = $wpdb->prefix;

		// ── appointments ───────────────────────────────────────────
		// calendar_scope = 'personal' | 'shared'
		// status         = 'confirmed' | 'tentative' | 'cancelled'
		// busy_status    = 'busy' | 'free' | 'tentative' | 'oof'  (Outlook showAs)
		// owner_user_id  = the WP user the event belongs to / was created by.
		// sync_state     = 'local' (never synced) | 'synced' | 'pending' | 'error'
		dbDelta( "CREATE TABLE {$p}zsch_appointments (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			owner_user_id bigint(20) unsigned NOT NULL,
			calendar_scope varchar(16) NOT NULL DEFAULT 'personal',
			title varchar(255) NOT NULL DEFAULT '',
			body text NULL,
			location varchar(255) NOT NULL DEFAULT '',
			start_utc datetime NOT NULL,
			end_utc datetime NOT NULL,
			is_all_day tinyint(1) NOT NULL DEFAULT 0,
			time_zone varchar(64) NOT NULL DEFAULT 'UTC',
			status varchar(16) NOT NULL DEFAULT 'confirmed',
			busy_status varchar(16) NOT NULL DEFAULT 'busy',
			color varchar(16) NOT NULL DEFAULT '',
			attendees text NULL,
			created_by bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			deleted_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY idx_owner_start (owner_user_id, start_utc),
			KEY idx_scope_start (calendar_scope, start_utc),
			KEY idx_deleted (deleted_at)
		) {$charset};" );

		// ── availability ───────────────────────────────────────────
		// A painted free/busy block. kind='open' means "I'm available";
		// kind='busy' means "I'm blocked". Distinct from appointments: this is
		// the lightweight "who's around" layer used for team coordination and
		// the dictation flow ("mark me open Mon–Wed").
		dbDelta( "CREATE TABLE {$p}zsch_availability (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			owner_user_id bigint(20) unsigned NOT NULL,
			kind varchar(16) NOT NULL DEFAULT 'open',
			start_utc datetime NOT NULL,
			end_utc datetime NOT NULL,
			is_all_day tinyint(1) NOT NULL DEFAULT 1,
			time_zone varchar(64) NOT NULL DEFAULT 'UTC',
			note varchar(255) NOT NULL DEFAULT '',
			source varchar(16) NOT NULL DEFAULT 'manual',
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			deleted_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY idx_avail_owner_start (owner_user_id, start_utc),
			KEY idx_avail_kind (kind),
			KEY idx_avail_deleted (deleted_at)
		) {$charset};" );

		// ── graph_map ──────────────────────────────────────────────
		// One row per appointment that exists in (or was pushed to) Microsoft
		// Graph. graph_user is the mailbox (userPrincipalName / email) the
		// event lives in. graph_event_id is Graph's id; etag powers
		// conflict-safe updates (If-Match). direction records who wrote last so
		// the puller can skip echoes of its own pushes.
		dbDelta( "CREATE TABLE {$p}zsch_graph_map (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			appointment_id bigint(20) unsigned NOT NULL,
			graph_user varchar(255) NOT NULL DEFAULT '',
			graph_event_id varchar(512) NOT NULL DEFAULT '',
			graph_ical_uid varchar(512) NOT NULL DEFAULT '',
			etag varchar(255) NOT NULL DEFAULT '',
			last_synced_at datetime DEFAULT NULL,
			direction varchar(16) NOT NULL DEFAULT 'push',
			sync_error varchar(255) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			UNIQUE KEY idx_graph_event (graph_event_id),
			KEY idx_appointment (appointment_id),
			KEY idx_graph_user (graph_user)
		) {$charset};" );
	}
}
