<?php
/**
 * Inbox schema migration — v1.10.0 (v0.15.0 performance pass).
 *
 * Index hygiene on wp_zib_messages, from the v0.15.0 performance audit. All guarded + idempotent
 * (information_schema-checked ALTERs — the house idiom, mirroring migrate-1.7.0). InnoDB runs ADD/DROP
 * KEY in-place (ALGORITHM=INPLACE) so these do not rebuild or lock the table.
 *
 *   1. ADD KEY idx_enrich_ver (enrich_ver)
 *        The enrichment backfill scans `WHERE enrich_ver < N AND body_enc <> '' ORDER BY received_at
 *        DESC LIMIT 40` on EVERY 10-minute cron tick, cross-owner. enrich_ver (added 1.3.0) was never
 *        keyed, so once catch-up is done this is a full-table scan 144×/day just to return zero rows.
 *        The index turns steady state into a cheap index dive.
 *
 *   2. ADD KEY idx_owner_folder_recv (owner_user_id, folder_hash, received_at, id)
 *        The Mail list always browses by real folder: `WHERE owner_user_id=? AND folder_hash=? ORDER BY
 *        received_at DESC`. Neither existing key covers it — idx_owner_folder (1.7.0) has no sort col,
 *        idx_owner_recv (1.1.0) has no folder filter — so MySQL filesorts or over-scans. This composite
 *        serves the filter AND the sort (trailing id also sets up seek/keyset pagination later).
 *
 *   3. DROP KEY ft_zib_search (subject, snippet, parties_text)
 *        Dead FULLTEXT index. It was superseded by ft_zib_all (5 cols, added 1.3.0); every live MATCH()
 *        in the gatekeeper (owner_search / admin_search / owner_chat / like_recall) targets the 5-col
 *        index. Maintaining two overlapping FULLTEXT indexes taxed every INSERT/enrich UPDATE for no
 *        benefit. Verified no query references the 3-col index. (Reversible: re-add if ever needed.)
 *
 * @package Zorderz
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZIB_Migrate_1_10_0 {

	public static function run(): void {
		global $wpdb;
		$t = $wpdb->prefix . 'zib_messages';

		// Defensive: messages table must exist (1.1.0 runs first).
		if ( $t !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) ) { // phpcs:ignore WordPress.DB
			return;
		}

		self::add_key( $t, 'idx_enrich_ver', '(enrich_ver)' );
		self::add_key( $t, 'idx_owner_folder_recv', '(owner_user_id, folder_hash, received_at, id)' );
		self::drop_key( $t, 'ft_zib_search' );
	}

	/** ADD KEY once (skip if present). $name/$cols are code literals, never user input. */
	private static function add_key( string $table, string $name, string $cols ): void {
		global $wpdb;
		if ( self::has_index( $table, $name ) ) {
			return;
		}
		$prev = $wpdb->suppress_errors( true );
		$wpdb->query( "ALTER TABLE {$table} ADD KEY {$name} {$cols}" ); // phpcs:ignore WordPress.DB
		$wpdb->suppress_errors( $prev );
	}

	/** DROP KEY once (skip if already gone). */
	private static function drop_key( string $table, string $name ): void {
		global $wpdb;
		if ( ! self::has_index( $table, $name ) ) {
			return;
		}
		$prev = $wpdb->suppress_errors( true );
		$wpdb->query( "ALTER TABLE {$table} DROP INDEX {$name}" ); // phpcs:ignore WordPress.DB
		$wpdb->suppress_errors( $prev );
	}

	private static function has_index( string $table, string $name ): bool {
		global $wpdb;
		$have = $wpdb->get_var( $wpdb->prepare(
			'SELECT COUNT(1) FROM information_schema.STATISTICS
			 WHERE table_schema = DATABASE() AND table_name = %s AND index_name = %s',
			$table,
			$name
		) ); // phpcs:ignore WordPress.DB
		return (int) $have > 0;
	}
}
