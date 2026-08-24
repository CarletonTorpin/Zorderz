<?php
/**
 * Zorderz Scheduler schema migration — v1.9.0 (Participants + Write-back map).
 *
 * Idempotent, additive only. dbDelta handles schema diffs; the v1.0.0 / v1.6.0
 * tables are untouched. Ships the write-back bookkeeping table NOW even though the
 * write-back engine ships OFF (`zsch_writeback_enabled` default 'no'), so Phase 2
 * needs no further migration — the same "ship the schema now" discipline as the
 * v1.6.0 Phase-1 sync columns.
 *
 * TABLE INVENTORY (new):
 *   wp_zsch_participants    an appointment carries REAL party accounts, not a JSON
 *                           string of typed emails. One row per (appointment, user);
 *                           soft `removed_at`. The OWNER is never a row (you cannot
 *                           remove yourself — the owner is included by every reader
 *                           implicitly). UNIQUE(appointment, user) makes re-adding a
 *                           removed party a revive, never a duplicate.
 *   wp_zsch_writeback_map   per-copy bookkeeping for the reverse-sync (Phase 2, OFF):
 *                           appointment × feed, the provider event id + etag, a
 *                           content_hash (skip no-op PATCHes), and the state machine
 *                           off→pending→synced|error|orphan. It is ALSO the INV-Loop
 *                           "belt": the inbound puller looks a pulled event up here to
 *                           refuse re-ingesting our own write (the owner's calendar is
 *                           their own conflict feed). Empty until Phase 2 is enabled.
 *
 * @since 1.9.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZSCH_Migrate_1_9_0 {

	public static function run() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$p       = $wpdb->prefix;

		// ── participants ───────────────────────────────────────────────
		// A calendar event carries real staff accounts. Soft `removed_at`
		// (removing a party keeps the row so a re-add is a revive, and so a
		// future write-back can PURGE the party's copy — INV-Orphan). The owner
		// is NEVER stored here; every reader folds the owner in implicitly.
		dbDelta( "CREATE TABLE {$p}zsch_participants (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			appointment_id bigint(20) unsigned NOT NULL,
			user_id bigint(20) unsigned NOT NULL,
			added_by bigint(20) unsigned NOT NULL DEFAULT 0,
			added_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			removed_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY idx_part_identity (appointment_id, user_id),
			KEY idx_part_appt (appointment_id),
			KEY idx_part_user (user_id, removed_at)
		) {$charset};" );

		// ── write-back map (Phase 2 — engine ships OFF) ────────────────
		// state = off | pending | synced | error | orphan. One row per copy
		// (appointment × the target's single two_way feed). content_hash lets a
		// re-push skip a no-op PATCH. external_event_id is the INV-Loop belt: a
		// pulled event whose id matches a row here is OUR OWN write and must not
		// be re-ingested (unguarded, every event makes its owner look busy against
		// itself within 5 minutes).
		dbDelta( "CREATE TABLE {$p}zsch_writeback_map (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			appointment_id bigint(20) unsigned NOT NULL,
			feed_id bigint(20) unsigned NOT NULL,
			target_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			external_event_id varchar(191) NOT NULL DEFAULT '',
			etag varchar(191) NOT NULL DEFAULT '',
			content_hash char(40) NOT NULL DEFAULT '',
			state varchar(16) NOT NULL DEFAULT 'off',
			last_error varchar(255) NOT NULL DEFAULT '',
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY idx_wb_copy (appointment_id, feed_id),
			KEY idx_wb_extid (external_event_id),
			KEY idx_wb_state (state)
		) {$charset};" );
	}
}
