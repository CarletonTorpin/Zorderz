<?php
/**
 * TSIM schema migration — v1.0.0
 *
 * Idempotent. Safe to run repeatedly; dbDelta handles schema diffs.
 *
 * TABLE INVENTORY:
 *   wp_zim_conversations        channels + DMs (unified id namespace)
 *   wp_zim_members              user ↔ conversation + per-user read cursor
 *   wp_zim_messages             message bodies (FULLTEXT on body)
 *   wp_zim_attachments          attachment metadata
 *   wp_zim_mentions             mention rows (preserved on soft-delete)
 *   wp_zim_push_subscriptions   Web Push endpoints
 *   wp_zim_notification_queue   deferred/in-flight push jobs
 *
 * INDEX DISCIPLINE (Trap 8):
 *   - Indexes are declared at CREATE TABLE, not "added later".
 *   - Hot query — "latest 50 messages in a conversation" — is covered by
 *     idx_conv_created on wp_zim_messages.
 *   - Hot query — "my mentions in the last N days" — is covered by
 *     idx_user_created on wp_zim_mentions.
 *
 * FULLTEXT on messages.body powers per-conversation search. Requires InnoDB
 * with FT support (default on WP-supported MySQL 5.6+).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZIM_Migrate_1_0_0 {

	public static function run() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$p       = $wpdb->prefix;

		// ── conversations ──────────────────────────────────────────
		// kind='channel': slug/name/description populated, user_a/user_b NULL.
		// kind='dm'     : user_a < user_b, slug/name/description NULL.
		dbDelta( "CREATE TABLE {$p}zim_conversations (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			kind varchar(16) NOT NULL DEFAULT 'channel',
			slug varchar(64) DEFAULT NULL,
			name varchar(128) DEFAULT NULL,
			description varchar(512) DEFAULT NULL,
			is_private tinyint(1) NOT NULL DEFAULT 0,
			is_announcements tinyint(1) NOT NULL DEFAULT 0,
			user_a bigint(20) unsigned DEFAULT NULL,
			user_b bigint(20) unsigned DEFAULT NULL,
			created_by bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			last_message_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY idx_slug (slug),
			UNIQUE KEY idx_dm_pair (user_a, user_b),
			KEY idx_kind (kind),
			KEY idx_last_msg (last_message_at)
		) {$charset};" );

		// ── members ───────────────────────────────────────────────
		// role = 'member' | 'admin'. Admins can remove other members.
		// last_read_message_id drives unread counts.
		dbDelta( "CREATE TABLE {$p}zim_members (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			conversation_id bigint(20) unsigned NOT NULL,
			user_id bigint(20) unsigned NOT NULL,
			role varchar(16) NOT NULL DEFAULT 'member',
			joined_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			last_read_message_id bigint(20) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY idx_conv_user (conversation_id, user_id),
			KEY idx_user (user_id)
		) {$charset};" );

		// ── messages ──────────────────────────────────────────────
		// Soft-delete: deleted_at IS NOT NULL. Body preserved only for audit.
		// edited_at updated when resolve() reconciles mentions.
		//
		// NB: dbDelta cannot add FULLTEXT indexes reliably (it treats them as
		// regular KEYs). We declare normal key at CREATE, then ALTER to FULLTEXT
		// if missing. See the FT-bootstrap at the bottom of this method.
		dbDelta( "CREATE TABLE {$p}zim_messages (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			conversation_id bigint(20) unsigned NOT NULL,
			author_user_id bigint(20) unsigned NOT NULL,
			body longtext NOT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			edited_at datetime DEFAULT NULL,
			deleted_at datetime DEFAULT NULL,
			deleted_by_user_id bigint(20) unsigned DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY idx_conv_created (conversation_id, created_at),
			KEY idx_author (author_user_id),
			KEY idx_deleted (deleted_at)
		) {$charset};" );

		// ── attachments ───────────────────────────────────────────
		// attachment_post_id — the WP attachment post we created so uploads
		// share the standard media pipeline (Trap 9).
		dbDelta( "CREATE TABLE {$p}zim_attachments (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			message_id bigint(20) unsigned NOT NULL DEFAULT 0,
			attachment_post_id bigint(20) unsigned NOT NULL,
			file_url varchar(2048) NOT NULL,
			mime varchar(128) NOT NULL,
			size_bytes bigint(20) unsigned NOT NULL DEFAULT 0,
			original_name varchar(255) NOT NULL DEFAULT '',
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			purged_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY idx_message (message_id),
			KEY idx_post (attachment_post_id),
			KEY idx_purged (purged_at)
		) {$charset};" );

		// ── mentions ──────────────────────────────────────────────
		// Preserve on soft-delete (Trap 4). removed_at: set during edit
		// reconciliation if user was un-mentioned. Never physically deleted.
		dbDelta( "CREATE TABLE {$p}zim_mentions (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			message_id bigint(20) unsigned NOT NULL,
			mentioned_user_id bigint(20) unsigned NOT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			removed_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY idx_msg_user (message_id, mentioned_user_id),
			KEY idx_user_created (mentioned_user_id, created_at)
		) {$charset};" );

		// ── push_subscriptions ────────────────────────────────────
		// endpoint is globally unique across all users — Web Push contract.
		// user_id ties it to the session that registered it; on logout we
		// delete this row. On delivery we re-check user_id matches current
		// session owner (Trap 6 / acceptance #13).
		dbDelta( "CREATE TABLE {$p}zim_push_subscriptions (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL,
			endpoint varchar(2048) NOT NULL,
			endpoint_hash char(64) NOT NULL,
			p256dh varchar(255) NOT NULL,
			auth varchar(128) NOT NULL,
			user_agent varchar(255) DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			rotated_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY idx_endpoint (endpoint_hash),
			KEY idx_user (user_id)
		) {$charset};" );

		// ── notification_queue ────────────────────────────────────
		// kind: 'mention' | 'first_unread' | 'digest'.
		// release_at: when the cron may dispatch. During quiet hours this is
		// shifted to the end-of-quiet-hours timestamp (Trap 7).
		dbDelta( "CREATE TABLE {$p}zim_notification_queue (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL,
			conversation_id bigint(20) unsigned NOT NULL,
			message_id bigint(20) unsigned NOT NULL DEFAULT 0,
			kind varchar(16) NOT NULL DEFAULT 'mention',
			release_at datetime NOT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			fired_at datetime DEFAULT NULL,
			cancelled_at datetime DEFAULT NULL,
			payload_json longtext DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY idx_release (release_at, fired_at),
			KEY idx_user_conv (user_id, conversation_id),
			KEY idx_fired (fired_at)
		) {$charset};" );

		// ── FULLTEXT bootstrap on messages.body ──────────────────
		// dbDelta doesn't reliably manage FT indexes; ALTER TABLE explicitly
		// after CREATE. Guard on existence so this stays idempotent.
		$messages_tbl = $p . 'zim_messages';
		$has_ft = $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM information_schema.STATISTICS
			 WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s
			   AND INDEX_NAME = 'idx_body_ft'",
			DB_NAME,
			$messages_tbl
		) );
		if ( empty( $has_ft ) ) {
			// FULLTEXT requires InnoDB (WP default on MySQL 5.6+); if the host
			// is on MyISAM or missing FT support this will error. Suppress and
			// search will fall back to LIKE in ZIM_Search.
			// phpcs:ignore WordPress.DB.PreparedSQL
			$wpdb->suppress_errors( true );
			$wpdb->query( "ALTER TABLE {$messages_tbl} ADD FULLTEXT KEY idx_body_ft (body)" );
			$wpdb->suppress_errors( false );
		}
	}
}
