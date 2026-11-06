<?php
/**
 * Zorderz Scheduler schema migration — v1.10.0.
 *
 * Idempotent and ADDITIVE ONLY: every new column is nullable or carries a
 * default, so existing rows need no data change and the migration is a pure
 * widening of the schema. Safe to run on every load (dbDelta diffs against the
 * live table).
 *
 * wp_zsch_graph_map.pulled_body_hash
 *   A fingerprint of the body exactly as a Graph pull stored it. A pull stores
 *   Graph's `bodyPreview` — a truncated, plain-text preview — so pushing that
 *   column straight back would replace the event's real rich text with a
 *   preview of itself. An unchanged hash means no person edited the body here,
 *   and the push leaves `body` out of the PATCH (ZSCH_Appointments::body_is_ours()).
 *
 * wp_zsch_appointments
 *   client_request_id + UNIQUE (owner_user_id, client_request_id)
 *     The idempotency key of a create. A retried POST (a double tap, a phone
 *     resuming from the background, a proxy retry) resolves to the one row the
 *     first attempt made. The UNIQUE KEY is the guarantee; the application
 *     read in create() is only the fast path. NULL is deliberate: MySQL allows
 *     unlimited NULLs in a unique index, so callers that send no key are
 *     unaffected.
 *   uid, sequence, last_modified, updated_by
 *     RFC 5545 identity. `uid` is minted once and never regenerated; `sequence`
 *     advances on a significant revision only (ZSCH_Identity).
 *   duration
 *     The span as an RFC 5545 duration string (ZSCH_Duration). `end_utc` stays
 *     the indexed column every range query reads and is derived from it.
 *   job_id
 *     Groups the linked events of one multi-day job. A multi-day job is not a
 *     recurrence (ZSCH_Recurrence::guard_multiday()).
 *   rrule, rdate, exdate, recurrence_id, series_uid, related_to, series_until_utc
 *     Recurrence. A series is ONE row carrying a rule, expanded on read
 *     (ZSCH_Series). An override row carries the master's uid in `series_uid`
 *     and the occurrence's ORIGINAL local start in `recurrence_id`.
 *     `series_until_utc` is the start of the last occurrence, or NULL when the
 *     rule is unbounded or its end cannot be established — NULL is always read
 *     as "may still be running", never as "finished".
 *   override_key + UNIQUE
 *     md5(series_uid | recurrence_id), separator escaped
 *     (ZSCH_Series::override_key()). One occurrence, at most one override row.
 *     The natural key is the (uid, recurrence_id) pair; InnoDB's 767-byte limit
 *     applies per indexed column, and the whole-index limit is 3072 bytes on
 *     every row format, so the composite would also be declarable. The 32-byte
 *     key is used because it is far narrower to store, write and cache. NULL for
 *     every ordinary appointment, which the unique index tolerates.
 *
 * wp_zsch_change_log (new)
 *   Append-only. One row per changed field per write, with the actor, the
 *   actor type and the SOURCE of the write (ui, api, graph-sync, …), so "did a
 *   person here do this, or did the sync?" has an answer.
 *
 * Registered in zsch_activate(), zsch_maybe_upgrade() and
 * ZSCH_Appointments::migrate_once(); its columns are listed in
 * zsch_tables_exist() so a folder-overwrite update still self-heals.
 *
 * @since 1.10.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZSCH_Migrate_1_10_0 {

	public static function run() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$p       = $wpdb->prefix;

		dbDelta( "CREATE TABLE {$p}zsch_graph_map (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			appointment_id bigint(20) unsigned NOT NULL,
			graph_user varchar(255) NOT NULL DEFAULT '',
			graph_event_id varchar(512) NOT NULL DEFAULT '',
			graph_ical_uid varchar(512) NOT NULL DEFAULT '',
			etag varchar(255) NOT NULL DEFAULT '',
			pulled_body_hash varchar(40) NOT NULL DEFAULT '',
			last_synced_at datetime DEFAULT NULL,
			direction varchar(16) NOT NULL DEFAULT 'push',
			sync_error varchar(255) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			UNIQUE KEY idx_graph_event (graph_event_id),
			KEY idx_appointment (appointment_id),
			KEY idx_graph_user (graph_user)
		) {$charset};" );

		/* Byte-compatible with migrate-1.0.0's statement apart from the added
		 * columns and keys. Keep it that way: dbDelta diffs this against the live
		 * table, and a cosmetic difference makes it "fix" a correct column on
		 * every run. */
		dbDelta( "CREATE TABLE {$p}zsch_appointments (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			owner_user_id bigint(20) unsigned NOT NULL,
			calendar_scope varchar(16) NOT NULL DEFAULT 'personal',
			title varchar(255) NOT NULL DEFAULT '',
			body text NULL,
			location varchar(255) NOT NULL DEFAULT '',
			start_utc datetime NOT NULL,
			end_utc datetime NOT NULL,
			duration varchar(32) NOT NULL DEFAULT '',
			is_all_day tinyint(1) NOT NULL DEFAULT 0,
			time_zone varchar(64) NOT NULL DEFAULT 'UTC',
			status varchar(16) NOT NULL DEFAULT 'confirmed',
			busy_status varchar(16) NOT NULL DEFAULT 'busy',
			color varchar(16) NOT NULL DEFAULT '',
			attendees text NULL,
			client_request_id varchar(64) DEFAULT NULL,
			uid varchar(191) DEFAULT NULL,
			sequence int(10) unsigned NOT NULL DEFAULT 0,
			job_id varchar(64) DEFAULT NULL,
			rrule varchar(512) NOT NULL DEFAULT '',
			rdate text NULL,
			exdate text NULL,
			recurrence_id varchar(32) NOT NULL DEFAULT '',
			series_uid varchar(191) DEFAULT NULL,
			related_to varchar(191) DEFAULT NULL,
			series_until_utc datetime DEFAULT NULL,
			override_key char(32) DEFAULT NULL,
			created_by bigint(20) unsigned NOT NULL DEFAULT 0,
			updated_by bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			last_modified datetime DEFAULT NULL,
			deleted_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY idx_zsch_idem (owner_user_id, client_request_id),
			UNIQUE KEY idx_zsch_uid (uid),
			UNIQUE KEY idx_zsch_override (override_key),
			KEY idx_owner_start (owner_user_id, start_utc),
			KEY idx_scope_start (calendar_scope, start_utc),
			KEY idx_zsch_job (job_id),
			KEY idx_zsch_series (series_uid),
			KEY idx_zsch_live_series (rrule(8), series_until_utc),
			KEY idx_deleted (deleted_at)
		) {$charset};" );

		dbDelta( "CREATE TABLE {$p}zsch_change_log (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			appointment_id bigint(20) unsigned NOT NULL DEFAULT 0,
			uid varchar(191) NOT NULL DEFAULT '',
			actor_id bigint(20) unsigned NOT NULL DEFAULT 0,
			actor_type varchar(16) NOT NULL DEFAULT 'user',
			source varchar(24) NOT NULL DEFAULT 'ui',
			field varchar(64) NOT NULL DEFAULT '',
			old_value text NULL,
			new_value text NULL,
			sequence_before int(10) unsigned NOT NULL DEFAULT 0,
			sequence_after int(10) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY idx_cl_appt (appointment_id, created_at),
			KEY idx_cl_uid (uid),
			KEY idx_cl_source (source, created_at)
		) {$charset};" );

		/* Key any override row that predates the column. Re-runnable: a row that
		 * already has a key is not touched. md5(CONCAT(...)) here must equal
		 * ZSCH_Series::override_key() — including the escaping of '\' and '|',
		 * which is why the REPLACEs are spelled out. A duplicate pair fails the
		 * update rather than being merged: two rows for one instant is a question
		 * for a person, not for a migration. */
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $p is $wpdb->prefix; the table name is fixed and the statement takes no values.
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			"UPDATE {$p}zsch_appointments
			    SET override_key = MD5( CONCAT(
			          REPLACE( REPLACE( series_uid, '\\\\', '\\\\\\\\' ), '|', '\\\\|' ),
			          '|',
			          REPLACE( REPLACE( recurrence_id, '\\\\', '\\\\\\\\' ), '|', '\\\\|' ) ) )
			  WHERE override_key IS NULL
			    AND series_uid IS NOT NULL AND series_uid <> ''
			    AND recurrence_id IS NOT NULL AND recurrence_id <> ''"
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}
