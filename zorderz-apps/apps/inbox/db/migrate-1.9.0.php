<?php
/**
 * Inbox schema migration — v1.9.0 (Mail view — read/unread state).
 *
 * Adds ONE column to the message index so the Mail list can bold unread mail:
 *   wp_zib_messages.is_read tinyint(1) NOT NULL DEFAULT 1
 *
 * WHY DEFAULT 1 (read): existing indexed rows predate this column and have no captured Graph isRead.
 * Defaulting them to READ means an upgrade never suddenly bolds the entire mailbox. Genuinely-unread
 * mail becomes bold as ingest repopulates it: new messages carry their real isRead on INSERT, and the
 * per-folder DELTA loop refreshes is_read on already-indexed rows when it re-surfaces them (a mark
 * read/unread in Outlook is a tracked change → it rides the next delta). Backfill deliberately does
 * NOT refresh, so a rebuild doesn't churn the column for thousands of historical rows.
 *
 * Idempotent, guarded ALTER (information_schema) — the house idiom (mirrors 1.6.0 / 1.7.0 / 1.8.0).
 *
 * @package Zorderz
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZIB_Migrate_1_9_0 {

	public static function run(): void {
		global $wpdb;
		$t = $wpdb->prefix . 'zib_messages';
		if ( $t !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) ) { // phpcs:ignore WordPress.DB
			return;
		}
		self::add_column( $t, 'is_read', "tinyint(1) NOT NULL DEFAULT 1 AFTER importance" );
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
}
