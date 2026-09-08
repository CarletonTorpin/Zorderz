<?php
/**
 * Inbox schema migration — v1.6.0 (Phase 0.2a — all-folder sync foundation).
 *
 * Widens wp_zib_folders.ms_folder_id  varchar(255) → TEXT.
 *
 * WHY: Phase 0.2 drives the sync loop off the STORED folder id (GET /me/mailFolders/{id}/messages
 * /delta), so a truncated id is no longer merely cosmetic — it would be an unusable Graph target.
 * Immutable ids (the Prefer: IdType="ImmutableId" header discovery already sends) run longer than
 * Graph's default ids and have no documented ceiling, so varchar(255) is a latent corruption risk.
 * This was review finding #2 in the 0.9.20 README, deferred to "the wave that owns the delta schema"
 * — that wave is 0.2, and this is it. (folder_hash stays char(32) and still carries the UNIQUE key,
 * so widening the id column does not touch any index.)
 *
 * dbDelta is unreliable at detecting a column TYPE change (it compares loosely and often no-ops),
 * so this issues an explicit, idempotent ALTER guarded by information_schema: it runs at most once
 * and is a no-op on a fresh install where 1.5.0 already created the table (there the column is new;
 * we still normalise it to TEXT so both paths converge).
 *
 * MySQL note: TEXT columns cannot carry a literal DEFAULT before 8.0.13, so the column becomes
 * TEXT NOT NULL with no default. Every writer supplies ms_folder_id, and existing rows hold '',
 * which satisfies NOT NULL — so no row is at risk.
 *
 * @package Zorderz
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZIB_Migrate_1_6_0 {

	public static function run(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'zib_folders';

		// Table absent (should not happen — 1.5.0 runs first — but be defensive): nothing to widen.
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB
		if ( $exists !== $table ) {
			return;
		}

		// Already TEXT? Then this migration has run (or a fresh install landed there). No-op.
		$type = $wpdb->get_var( $wpdb->prepare(
			'SELECT DATA_TYPE FROM information_schema.COLUMNS
			 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
			$table,
			'ms_folder_id'
		) ); // phpcs:ignore WordPress.DB

		if ( is_string( $type ) && 'text' === strtolower( $type ) ) {
			return;
		}

		// varchar(255) (or anything not-yet-text) → TEXT NOT NULL. Existing '' values are preserved.
		$wpdb->query( "ALTER TABLE {$table} MODIFY ms_folder_id TEXT NOT NULL" ); // phpcs:ignore WordPress.DB
	}
}
