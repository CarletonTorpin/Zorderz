<?php
/**
 * Inbox schema migration — v1.1.0 (P1: Ballast + ingest).
 *
 * Idempotent, additive. Creates the sealed Ballast:
 *   wp_zib_messages     — one row per in-scope indexed message. Bodies are
 *                          CIPHERTEXT (body_enc, ZIB_Crypto). subject / snippet /
 *                          parties_text are plaintext + FULLTEXT (D4 hybrid).
 *   wp_zib_participants — from/to/cc parties per message (classification + match).
 *   wp_zib_seen         — dedupe ledger incl. EXCLUDED messages, which carry NO
 *                          content — an out-of-scope body is never even stored.
 *
 * The FULLTEXT index is added by a guarded ALTER (not dbDelta) — dbDelta mangles
 * FULLTEXT KEY definitions and would re-add them every load.
 *
 * @package Zorderz
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZIB_Migrate_1_1_0 {

	public static function run(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$p       = $wpdb->prefix;

		// class = 'internal' | 'external' ; direction = 'in' | 'out' ; folder = 'inbox' | 'sent'
		dbDelta( "CREATE TABLE {$p}zib_messages (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			owner_user_id bigint(20) unsigned NOT NULL,
			account_id bigint(20) unsigned NOT NULL,
			ms_message_id varchar(255) NOT NULL,
			ms_internet_message_id varchar(255) NOT NULL DEFAULT '',
			ms_conversation_id varchar(255) NOT NULL DEFAULT '',
			folder varchar(16) NOT NULL DEFAULT '',
			direction varchar(8) NOT NULL DEFAULT 'in',
			class varchar(16) NOT NULL DEFAULT 'external',
			from_addr varchar(255) NOT NULL DEFAULT '',
			from_name varchar(255) NOT NULL DEFAULT '',
			received_at datetime DEFAULT NULL,
			sent_at datetime DEFAULT NULL,
			subject varchar(512) NOT NULL DEFAULT '',
			snippet varchar(512) NOT NULL DEFAULT '',
			parties_text text NULL,
			body_enc longtext NULL,
			body_format varchar(8) NOT NULL DEFAULT 'text',
			has_attachments tinyint(1) NOT NULL DEFAULT 0,
			importance varchar(16) NOT NULL DEFAULT 'normal',
			indexed_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY uq_msg (account_id, ms_message_id),
			KEY idx_owner_recv (owner_user_id, received_at),
			KEY idx_conv (ms_conversation_id)
		) {$charset};" );

		dbDelta( "CREATE TABLE {$p}zib_participants (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			message_id bigint(20) unsigned NOT NULL,
			owner_user_id bigint(20) unsigned NOT NULL,
			role varchar(4) NOT NULL DEFAULT 'to',
			addr varchar(255) NOT NULL DEFAULT '',
			name varchar(255) NOT NULL DEFAULT '',
			is_internal tinyint(1) NOT NULL DEFAULT 0,
			matched_user_id bigint(20) unsigned DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY idx_msg (message_id),
			KEY idx_addr (addr),
			KEY idx_owner (owner_user_id)
		) {$charset};" );

		// decision = 'indexed' | 'excluded'. Excluded rows store NO subject/body.
		dbDelta( "CREATE TABLE {$p}zib_seen (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			account_id bigint(20) unsigned NOT NULL,
			ms_message_id varchar(255) NOT NULL,
			class varchar(16) NOT NULL DEFAULT 'external',
			decision varchar(12) NOT NULL DEFAULT 'excluded',
			received_at datetime DEFAULT NULL,
			seen_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY uq_seen (account_id, ms_message_id),
			KEY idx_acct (account_id)
		) {$charset};" );

		self::ensure_fulltext();
	}

	/** Add the FULLTEXT(subject, snippet, parties_text) index once (idempotent). */
	public static function ensure_fulltext(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'zib_messages';
		$have  = $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(1) FROM information_schema.STATISTICS
			 WHERE table_schema = %s AND table_name = %s AND index_name = 'ft_zib_search'",
			DB_NAME, $table
		) );
		if ( (int) $have > 0 ) {
			return;
		}
		// InnoDB (MySQL 5.6+) and MyISAM both support FULLTEXT.
		$wpdb->query( "ALTER TABLE {$table} ADD FULLTEXT KEY ft_zib_search (subject, snippet, parties_text)" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}
}
