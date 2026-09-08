<?php
/**
 * Inbox schema migration — v1.7.0 (Phase 0.2b — per-folder ingest: message folder identity).
 *
 * Adds, to wp_zib_messages, the identity of the FOLDER a message was indexed from:
 *   folder_hash  char(32)     — md5(ms_folder_id); joins a message to its wp_zib_folders row.
 *   folder_name  varchar(255) — denormalized display name (Archive, "Projects"), so the render
 *                               layer can show a real folder later without a join.
 *   KEY idx_owner_folder (owner_user_id, folder_hash) — per-folder reads.
 *
 * The existing `folder varchar(16)` COARSE label (inbox|sent|other) is KEPT and still populated, so
 * the chat / Brain-Bot render layer is untouched by 0.2b — it keeps reading `folder` exactly as
 * before. Surfacing real folder names IN chat is a later polish, not this sub-wave.
 *
 * Additive + idempotent. Uses guarded ALTERs (information_schema), the house idiom here — dbDelta is
 * unreliable at incremental column/key changes and would need the whole CREATE TABLE reproduced
 * (drift risk). Mirrors ZIB_Migrate_1_1_0::ensure_fulltext().
 *
 * NOTE (0.2c precondition, deliberately NOT done here): ms_message_id stays varchar(255). Immutable
 * MESSAGE ids (longer, no ceiling) are only ever WRITTEN once the per-folder loop is enabled, which
 * is 0.2c's clean re-index — so 0.2c owns widening ms_message_id (+ a hash unique key) on the emptied
 * table. 0.2b ships the loop dormant (switch default off), so no immutable id is stored yet.
 *
 * @package Zorderz
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZIB_Migrate_1_7_0 {

	public static function run(): void {
		global $wpdb;
		$t = $wpdb->prefix . 'zib_messages';

		// Defensive: messages table must exist (1.1.0 runs first).
		if ( $t !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) ) { // phpcs:ignore WordPress.DB
			return;
		}

		self::add_column( $t, 'folder_hash', "char(32) NOT NULL DEFAULT '' AFTER folder" );
		self::add_column( $t, 'folder_name', "varchar(255) NOT NULL DEFAULT '' AFTER folder_hash" );
		self::add_key( $t, 'idx_owner_folder', '(owner_user_id, folder_hash)' );
	}

	/** ADD COLUMN once. $col/$def are code literals (never user input). */
	private static function add_column( string $table, string $col, string $def ): void {
		global $wpdb;
		$have = $wpdb->get_var( $wpdb->prepare(
			'SELECT COUNT(1) FROM information_schema.COLUMNS
			 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
			$table,
			$col
		) ); // phpcs:ignore WordPress.DB
		if ( (int) $have > 0 ) {
			return;
		}
		// suppress_errors: if a concurrent upgrade or stale information_schema slips past the guard, a
		// "Duplicate column" error is harmless (the column exists) but would spam debug.log. Silence it.
		$prev = $wpdb->suppress_errors( true );
		$wpdb->query( "ALTER TABLE {$table} ADD COLUMN {$col} {$def}" ); // phpcs:ignore WordPress.DB
		$wpdb->suppress_errors( $prev );
	}

	/** ADD KEY once. */
	private static function add_key( string $table, string $name, string $cols ): void {
		global $wpdb;
		$have = $wpdb->get_var( $wpdb->prepare(
			'SELECT COUNT(1) FROM information_schema.STATISTICS
			 WHERE table_schema = DATABASE() AND table_name = %s AND index_name = %s',
			$table,
			$name
		) ); // phpcs:ignore WordPress.DB
		if ( (int) $have > 0 ) {
			return;
		}
		$prev = $wpdb->suppress_errors( true );
		$wpdb->query( "ALTER TABLE {$table} ADD KEY {$name} {$cols}" ); // phpcs:ignore WordPress.DB
		$wpdb->suppress_errors( $prev );
	}
}
