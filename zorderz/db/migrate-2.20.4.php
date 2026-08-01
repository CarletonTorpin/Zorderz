<?php
/**
 * Migration: v2.20.4 — EXIF provenance columns on zdz_user_media.
 *
 * Adds three indexed columns used to retain photo provenance and to match
 * photos to an invoice by date + location for the receipt flow:
 *   - captured_at  DATETIME       (EXIF DateTimeOriginal, user-local)
 *   - gps_lat      DECIMAL(10,7)  (signed decimal degrees)
 *   - gps_lng      DECIMAL(10,7)  (signed decimal degrees)
 *   + KEY idx_captured (captured_at)
 *   + KEY idx_geo (gps_lat, gps_lng)
 *
 * These values are written ONCE at ingest by ZDZ_User_Media::save() and are
 * deliberately excluded from ZDZ_User_Media::update()'s whitelist, so the
 * provenance can be read but never altered.
 *
 * ZDZ_User_Media::ensure_table() also performs this same change on first save
 * (schema option bumped 2 → 3). This standalone migration covers installs
 * where the table already exists and admin_init runs before any media save.
 *
 * Idempotent: each column/index is guarded by an existence check, and the
 * whole routine short-circuits on the zdz_migrated_2_20_4 option flag.
 *
 * @since 2.20.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function zdz_migrate_2_20_4() {
	if ( get_option( 'zdz_migrated_2_20_4', false ) ) {
		return;
	}

	global $wpdb;
	$table = $wpdb->prefix . 'zdz_user_media';

	// Nothing to migrate if the table doesn't exist yet — it will be created
	// at schema 3 (with these columns) on first save.
	if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) !== $table ) {
		update_option( 'zdz_migrated_2_20_4', true, false );
		return;
	}

	$cols = $wpdb->get_col( "DESCRIBE `{$table}`", 0 );

	if ( ! in_array( 'captured_at', $cols, true ) ) {
		$wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN `captured_at` datetime NULL AFTER `updated_at`" );
	}
	if ( ! in_array( 'gps_lat', $cols, true ) ) {
		$wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN `gps_lat` decimal(10,7) NULL AFTER `captured_at`" );
	}
	if ( ! in_array( 'gps_lng', $cols, true ) ) {
		$wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN `gps_lng` decimal(10,7) NULL AFTER `gps_lat`" );
	}

	// Indexes — re-adding an existing KEY errors, so guard on Key_name.
	$idx = $wpdb->get_col( "SHOW INDEX FROM `{$table}`", 2 );
	if ( ! in_array( 'idx_captured', (array) $idx, true ) ) {
		$wpdb->query( "ALTER TABLE `{$table}` ADD KEY `idx_captured` (`captured_at`)" );
	}
	if ( ! in_array( 'idx_geo', (array) $idx, true ) ) {
		$wpdb->query( "ALTER TABLE `{$table}` ADD KEY `idx_geo` (`gps_lat`, `gps_lng`)" );
	}

	// Keep the shared schema-version option in lockstep with ensure_table().
	if ( (int) get_option( 'zdz_media_schema', 0 ) < 3 ) {
		update_option( 'zdz_media_schema', 3, true );
	}

	error_log( 'TS migrate-2.20.4: ensured EXIF columns (captured_at, gps_lat, gps_lng) on zdz_user_media.' );
	update_option( 'zdz_migrated_2_20_4', true, false );
}

zdz_migrate_2_20_4();
