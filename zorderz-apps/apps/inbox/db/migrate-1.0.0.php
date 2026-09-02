<?php
/**
 * Inbox schema migration — v1.0.0 (P0, Connect).
 *
 * Idempotent (dbDelta). Additive only. Creates the ONE table P0 needs:
 *
 *   wp_zib_accounts  one row per user's connected M365 mailbox (the encrypted
 *                     token vault lives on this row). Nothing else exists yet —
 *                     the Ballast content tables (wp_zib_messages, _participants,
 *                     _seen, _shares, _access_log) arrive in P1/P2 migrations.
 *
 * DESIGN NOTES (mirroring the Scheduler's own Connected-Calendars migration):
 *   - `ms_object_id` is Entra's IMMUTABLE `oid` — the identity key. `upn` /
 *     `email_label` are display only (an address can change; the oid cannot).
 *   - Tokens are ciphertext only (ZIB_Vault); nothing here is usable from a
 *     DB dump alone.
 *   - `token_version` powers the vault's single-flight refresh.
 *   - UNIQUE (owner_user_id): one mailbox per user in v1. Reconnecting REPLACES
 *     the row in place (ZIB_Connections::upsert_account).
 *   - The P1 columns (index_mode, admin_search_enabled, window_start,
 *     backfill_*, delta_* cursors) ship NOW so P1 needs no ALTER — the
 *     "ship the columns early" discipline from the Scheduler. `index_mode`
 *     defaults to 'none': nothing is ever indexed until the owner picks a mode.
 *
 * @package Zorderz
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZIB_Migrate_1_0_0 {

	public static function run(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$p       = $wpdb->prefix;

		// status     = 'ok' | 'reauth_needed' | 'disabled'
		// index_mode = 'none' | 'internal' | 'external' | 'all'
		// backfill_status = 'pending' | 'running' | 'done' | 'error'
		dbDelta( "CREATE TABLE {$p}zib_accounts (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			owner_user_id bigint(20) unsigned NOT NULL,
			provider varchar(16) NOT NULL DEFAULT 'microsoft',
			ms_tenant_id varchar(191) NOT NULL DEFAULT '',
			ms_object_id varchar(191) NOT NULL DEFAULT '',
			upn varchar(191) NOT NULL DEFAULT '',
			email_label varchar(191) NOT NULL DEFAULT '',
			scopes text NULL,
			status varchar(20) NOT NULL DEFAULT 'ok',
			access_token_enc text NULL,
			refresh_token_enc text NULL,
			token_expires_at datetime DEFAULT NULL,
			token_version int(11) unsigned NOT NULL DEFAULT 0,
			index_mode varchar(16) NOT NULL DEFAULT 'none',
			admin_search_enabled tinyint(1) NOT NULL DEFAULT 0,
			window_start datetime DEFAULT NULL,
			backfill_status varchar(16) NOT NULL DEFAULT 'pending',
			backfill_cursor text NULL,
			delta_inbox_cursor text NULL,
			delta_sent_cursor text NULL,
			last_synced_at datetime DEFAULT NULL,
			last_error varchar(255) NOT NULL DEFAULT '',
			connected_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY idx_acct_owner (owner_user_id),
			KEY idx_acct_status (status)
		) {$charset};" );
	}
}
