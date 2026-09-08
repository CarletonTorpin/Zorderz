<?php
/**
 * Inbox schema migration — v1.5.0 (Phase 0: all-folder sync — the folder model).
 *
 * wp_zib_folders — ONE row per mail folder per account, discovered by recursive enumeration.
 * This is the per-folder sync-state store the client needs: Graph has NO mailbox-wide message
 * delta (confirmed by MS — delta is per-folder only), so all-folder sync means one delta cursor
 * PER FOLDER, not the two fixed columns (delta_inbox_cursor / delta_sent_cursor) on wp_zib_accounts.
 *
 * Phase 0.1 (this migration) only ADDS the table + discovery; the sync loop still runs Inbox+Sent
 * until Phase 0.2 switches it over (with a clean re-index). Additive + safe.
 *
 * folder_hash = md5(ms_folder_id) carries the UNIQUE key — immutable folder ids are long, so this
 * dodges MySQL prefix-index length limits and keeps dbDelta happy.
 *
 * @package Zorderz
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZIB_Migrate_1_5_0 {

	public static function run(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$p       = $wpdb->prefix;

		// backfill_status = 'pending' | 'running' | 'done' | 'error'
		// Create-once (see migrate-1.1.0). migrate-1.6.0 widens ms_folder_id varchar(255) -> TEXT, so
		// re-running this dbDelta on an existing table would try to NARROW it back to varchar(255) — a
		// silent truncation risk for long immutable folder ids. 1.6.0's guarded ALTER owns that column;
		// 1.5.0 only ever CREATES. Skip once the table exists.
		if ( ! self::table_exists( "{$p}zib_folders" ) ) { dbDelta( "CREATE TABLE {$p}zib_folders (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			account_id bigint(20) unsigned NOT NULL,
			ms_folder_id varchar(255) NOT NULL DEFAULT '',
			folder_hash char(32) NOT NULL DEFAULT '',
			parent_ms_id varchar(255) NOT NULL DEFAULT '',
			display_name varchar(255) NOT NULL DEFAULT '',
			well_known varchar(64) NOT NULL DEFAULT '',
			total_items int(11) NOT NULL DEFAULT 0,
			is_tracked tinyint(1) NOT NULL DEFAULT 1,
			excluded_reason varchar(64) NOT NULL DEFAULT '',
			delta_cursor mediumtext NULL,
			backfill_status varchar(16) NOT NULL DEFAULT 'pending',
			backfill_cursor text NULL,
			last_synced_at datetime DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY idx_folder (account_id, folder_hash),
			KEY idx_tracked (account_id, is_tracked)
		) {$charset};" ); }
	}

	/** True if $table already exists — keeps the CREATE dbDelta first-time-only. */
	private static function table_exists( string $table ): bool {
		global $wpdb;
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB
	}
}
