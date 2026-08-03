<?php
/**
 * ZANA_DB — the session/message store for the chat surface.
 *
 * Three tables, created SCHEMA-ONLY on activation (no rows ever seeded):
 *   - wp_zana_sessions  : one row per conversation (owner, title, timestamps).
 *   - wp_zana_messages  : one row per turn (session, role, body, tier, created).
 *   - wp_zana_turn_jobs : one row per QUEUED async turn (owner, session, message,
 *     status, result). Added in DB 1.2.0 so a slow (vault-augmented / thinking) turn
 *     can run in a background loopback and be polled, instead of holding the browser
 *     request open past a managed host's gateway timeout. The row carries no company
 *     data — only the user's own message and the same result the sync turn returns.
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

	const DB_VERSION = '1.2.0';

	public static function sessions_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'zana_sessions';
	}

	public static function messages_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'zana_messages';
	}

	/** Queue for async chat turns run in a background loopback (DB 1.2.0). */
	public static function jobs_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'zana_turn_jobs';
	}

	/** Create/upgrade the schema. Idempotent (dbDelta). No data is seeded. */
	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();

		$sessions = self::sessions_table();
		$messages = self::messages_table();
		$jobs     = self::jobs_table();

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

		// One row per queued async turn. Deleted after a day by the cleanup cron;
		// the durable transcript lives in wp_zana_messages, written by ZANA_Chat::send.
		$sql_jobs = "CREATE TABLE {$jobs} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL,
			session_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			status VARCHAR(16) NOT NULL DEFAULT 'queued',
			message MEDIUMTEXT NOT NULL,
			result_json LONGTEXT NULL,
			error_msg TEXT NULL,
			created_at DATETIME NOT NULL DEFAULT '1970-01-01 00:00:00',
			updated_at DATETIME NOT NULL DEFAULT '1970-01-01 00:00:00',
			PRIMARY KEY  (id),
			KEY user_id (user_id),
			KEY status (status),
			KEY created_at (created_at)
		) {$charset};";

		dbDelta( $sql_sessions );
		dbDelta( $sql_messages );
		dbDelta( $sql_jobs );
		update_option( 'zana_db_version', self::DB_VERSION, false );
	}

	/** Self-heal the schema on a file-overwrite that skipped activation. */
	public static function maybe_upgrade(): void {
		if ( get_option( 'zana_db_version' ) !== self::DB_VERSION ) {
			self::install();
		}
	}
}
