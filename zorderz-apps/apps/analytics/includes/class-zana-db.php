<?php
/**
 * ZANA_DB — the session/message store for the chat surface.
 *
 * Two tables, created SCHEMA-ONLY on activation (no rows ever seeded):
 *   - wp_zana_sessions : one row per conversation (owner, title, timestamps).
 *   - wp_zana_messages : one row per turn (session, role, body, tier, created).
 *
 * The store is deliberately small: the ported core keeps a durable transcript so a
 * user can reopen a conversation and the digest deep-link can resolve a session id.
 * The memory table, the response cache and the company-facts table from the source
 * are DEFERRED surfaces and are NOT created here (memory + facts must ship empty and
 * are gated behind consent; the cache is a performance concern, not correctness).
 *
 * @package Zorderz\Analytics
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZANA_DB {

	const DB_VERSION = '1.1.0';

	public static function sessions_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'zana_sessions';
	}

	public static function messages_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'zana_messages';
	}

	/** Create/upgrade the schema. Idempotent (dbDelta). No data is seeded. */
	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();

		$sessions = self::sessions_table();
		$messages = self::messages_table();

		$sql_sessions = "CREATE TABLE {$sessions} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL,
			title VARCHAR(200) NOT NULL DEFAULT '',
			created_at DATETIME NOT NULL DEFAULT '1970-01-01 00:00:00',
			updated_at DATETIME NOT NULL DEFAULT '1970-01-01 00:00:00',
			PRIMARY KEY  (id),
			KEY user_id (user_id),
			KEY updated_at (updated_at)
		) {$charset};";

		$sql_messages = "CREATE TABLE {$messages} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			session_id BIGINT UNSIGNED NOT NULL,
			user_id BIGINT UNSIGNED NOT NULL,
			role VARCHAR(16) NOT NULL DEFAULT 'user',
			body MEDIUMTEXT NOT NULL,
			tier VARCHAR(16) NOT NULL DEFAULT 'unknown',
			verdict VARCHAR(16) NOT NULL DEFAULT 'ok',
			created_at DATETIME NOT NULL DEFAULT '1970-01-01 00:00:00',
			PRIMARY KEY  (id),
			KEY session_id (session_id),
			KEY user_id (user_id)
		) {$charset};";

		dbDelta( $sql_sessions );
		dbDelta( $sql_messages );
		update_option( 'zana_db_version', self::DB_VERSION, false );
	}

	/** Self-heal the schema on a file-overwrite that skipped activation. */
	public static function maybe_upgrade(): void {
		if ( get_option( 'zana_db_version' ) !== self::DB_VERSION ) {
			self::install();
		}
	}
}
