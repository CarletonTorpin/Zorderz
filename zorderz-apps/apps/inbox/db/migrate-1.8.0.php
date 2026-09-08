<?php
/**
 * Inbox schema migration — v1.8.0 (Phase 0.2c — immutable-message-id readiness).
 *
 * Adds a message-id HASH to both id-bearing tables so dedup no longer depends on the raw id fitting
 * a column or an index:
 *   wp_zib_messages.ms_message_hash char(32)  = md5(ms_message_id)
 *   wp_zib_seen.ms_message_hash     char(32)  = md5(ms_message_id)
 *   + non-unique KEY (account_id, ms_message_hash) on each (dedup lookups are index-served).
 *
 * WHY: 0.2c turns on the per-folder loop, which writes IMMUTABLE message ids — longer than the raw
 * ids, with no documented ceiling. A char(32) md5 is fixed-width, so the unique/dedup key can move
 * off the variable-length id (varchar → TEXT) without prefix-index pain. This migration is the SAFE,
 * ADDITIVE half: it only ADDS the hash column + a NON-unique index + backfills existing rows. It does
 * NOT widen ms_message_id and does NOT touch the existing UNIQUE keys — that half (drop old key, widen
 * to TEXT, add the hash UNIQUE key) runs in the "Rebuild index" handler on the EMPTIED tables, where a
 * key swap can't collide on duplicate ''-hashes. So a normal upgrade is non-destructive and the legacy
 * inbox/sent path keeps working; only a deliberate Rebuild flips to immutable ids.
 *
 * Idempotent, guarded ALTERs (information_schema) — the house idiom (mirrors 1.6.0 / 1.7.0).
 *
 * @package Zorderz
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZIB_Migrate_1_8_0 {

	public static function run(): void {
		global $wpdb;
		foreach ( array( 'zib_messages', 'zib_seen' ) as $suffix ) {
			$t = $wpdb->prefix . $suffix;
			if ( $t !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) ) { // phpcs:ignore WordPress.DB
				continue;
			}
			self::add_column( $t, 'ms_message_hash', "char(32) NOT NULL DEFAULT '' AFTER ms_message_id" );
			// Backfill existing rows once (cheap after: the WHERE matches nothing on later runs).
			$wpdb->query( "UPDATE {$t} SET ms_message_hash = MD5(ms_message_id) WHERE ms_message_hash = '' AND ms_message_id <> ''" ); // phpcs:ignore WordPress.DB
			self::add_key( $t, 'idx_msg_hash', '(account_id, ms_message_hash)' );
		}
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
		// suppress_errors: a slipped race / stale information_schema would log a harmless "Duplicate
		// column" (the column exists). Silence it — the guard handles the normal case (see 0.9.22 note).
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
