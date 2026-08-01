<?php
/**
 * ZL Database Migration — v2.0.0
 *
 * Creates:
 *   1. wp_zl_lead_forwards — Forward-to-team tracking (modeled after surveys plugin)
 *
 * Modifies:
 *   1. wp_zl_batches — Adds updated_at column for stale batch detection
 *   2. wp_zl_leads   — Adds status_updated_by, callback_date columns
 *
 * Runs once via the version-gated migration in zl_maybe_upgrade().
 *
 * @since 2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function zl_migrate_200() {
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$charset = $wpdb->get_charset_collate();

	// ── 1. Lead Forwards table ──────────────────────────────────
	$t_forwards = $wpdb->prefix . 'zl_lead_forwards';
	dbDelta( "CREATE TABLE {$t_forwards} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		lead_id bigint(20) unsigned NOT NULL,
		batch_id bigint(20) unsigned NOT NULL DEFAULT 0,
		sender_id bigint(20) unsigned NOT NULL,
		recipient_id bigint(20) unsigned NOT NULL,
		note_text text NOT NULL,
		is_task tinyint(1) NOT NULL DEFAULT 0,
		status varchar(20) NOT NULL DEFAULT 'pending',
		completed_at datetime DEFAULT NULL,
		created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
		PRIMARY KEY  (id),
		KEY idx_lead_id (lead_id),
		KEY idx_recipient_id (recipient_id),
		KEY idx_status (status)
	) {$charset};" );

	// ── 2. Add updated_at to batches for stale detection ────────
	$col_exists = $wpdb->get_var(
		"SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
		 WHERE TABLE_SCHEMA = DATABASE()
		 AND TABLE_NAME = '{$wpdb->prefix}zl_batches'
		 AND COLUMN_NAME = 'updated_at'"
	);
	if ( ! $col_exists ) {
		$wpdb->query(
			"ALTER TABLE {$wpdb->prefix}zl_batches
			 ADD COLUMN updated_at datetime DEFAULT NULL AFTER created_at"
		);
		// Backfill: set updated_at = created_at for existing rows
		$wpdb->query(
			"UPDATE {$wpdb->prefix}zl_batches
			 SET updated_at = created_at WHERE updated_at IS NULL"
		);
	}

	// ── 3. Add status_updated_by + callback_date to leads ───────
	$col_exists = $wpdb->get_var(
		"SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
		 WHERE TABLE_SCHEMA = DATABASE()
		 AND TABLE_NAME = '{$wpdb->prefix}zl_leads'
		 AND COLUMN_NAME = 'status_updated_by'"
	);
	if ( ! $col_exists ) {
		$wpdb->query(
			"ALTER TABLE {$wpdb->prefix}zl_leads
			 ADD COLUMN status_updated_by bigint(20) unsigned DEFAULT NULL AFTER contacted_at,
			 ADD COLUMN callback_date datetime DEFAULT NULL AFTER status_updated_by"
		);
	}

	// ── 4. Add error_message to batches for failed batch context ─
	$col_exists = $wpdb->get_var(
		"SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
		 WHERE TABLE_SCHEMA = DATABASE()
		 AND TABLE_NAME = '{$wpdb->prefix}zl_batches'
		 AND COLUMN_NAME = 'error_message'"
	);
	if ( ! $col_exists ) {
		$wpdb->query(
			"ALTER TABLE {$wpdb->prefix}zl_batches
			 ADD COLUMN error_message text DEFAULT NULL AFTER ai_summary"
		);
	}

	error_log( 'ZL v2.0.0 migration: Created zl_lead_forwards table, added updated_at/error_message to batches, status_updated_by/callback_date to leads.' );
}
