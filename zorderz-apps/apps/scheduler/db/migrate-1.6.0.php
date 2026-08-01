<?php
/**
 * TS Scheduler schema migration — v1.6.0 (Connected Calendars, Phase 0).
 *
 * Idempotent. Safe to run repeatedly; dbDelta handles schema diffs. Additive
 * only — the three v1.0.0 tables are untouched.
 *
 * TABLE INVENTORY (new):
 *   wp_zsch_calendar_accounts  one row per user × provider × external account
 *                               (the encrypted token vault lives on this row)
 *   wp_zsch_calendar_feeds     one row per selected calendar on an account
 *                               ("conflict calendars"; two_way arrives Phase 2)
 *   wp_zsch_external_events    normalized external busy mirror (rolling
 *                               window; populated by the Phase 1 sync engine)
 *
 * DESIGN NOTES:
 *   - `external_id` is the provider's IMMUTABLE account key — Google's `sub`
 *     claim / Entra's `oid` (+`tid`). Email is stored ONLY as a display label
 *     (`email_label`) because a Google login can be any address (AOL, etc.).
 *   - Tokens are encrypted at rest (ZSCH_Vault: sodium secretbox, OpenSSL
 *     AES-256-CTR+HMAC fallback; key derived from wp_salt('auth')). The
 *     columns hold ciphertext only — nothing in this table is usable from a
 *     DB dump alone.
 *   - `token_version` is a monotonic counter powering the vault's
 *     single-flight refresh (GET_LOCK holder bumps it; waiting requests adopt
 *     the sibling's fresh token instead of racing a second refresh — the
 *     FreshBooks token-service lesson, INV-4).
 *   - `wp_zsch_external_events.title` is owner-eyes-only and NULL when the
 *     feed's privacy toggle is "busy-only" (data minimization).
 *   - UNIQUE (owner, provider, external_id): reconnecting the same account
 *     REPLACES the row (Google caps ~100 live refresh tokens per account ×
 *     client — one grant per account, ever).
 *
 * INDEX DISCIPLINE:
 *   - "this user's accounts"                      → KEY idx_acct_owner
 *   - "feeds of an account" / channel renewals    → KEY idx_feed_account, idx_feed_channel_exp
 *   - "busy blocks for feed set in window"        → KEY idx_ext_feed_start, idx_ext_window
 *   - sync upsert "find mirrored row by event id" → UNIQUE idx_ext_event
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZSCH_Migrate_1_6_0 {

	public static function run() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$p       = $wpdb->prefix;

		// ── calendar_accounts ──────────────────────────────────────
		// status = 'ok' | 'reauth_needed' | 'disabled'
		// provider = 'google' | 'microsoft'
		dbDelta( "CREATE TABLE {$p}zsch_calendar_accounts (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			owner_user_id bigint(20) unsigned NOT NULL,
			provider varchar(16) NOT NULL DEFAULT 'google',
			external_id varchar(191) NOT NULL DEFAULT '',
			email_label varchar(191) NOT NULL DEFAULT '',
			scopes text NULL,
			status varchar(20) NOT NULL DEFAULT 'ok',
			access_token_enc text NULL,
			refresh_token_enc text NULL,
			token_expires_at datetime DEFAULT NULL,
			token_version int(11) unsigned NOT NULL DEFAULT 0,
			last_error varchar(255) NOT NULL DEFAULT '',
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY idx_acct_identity (owner_user_id, provider, external_id),
			KEY idx_acct_owner (owner_user_id),
			KEY idx_acct_status (status)
		) {$charset};" );

		// ── calendar_feeds ─────────────────────────────────────────
		// mode = 'conflict' | 'two_way' (two_way is Phase 2; Phase 0/1 rows are
		// always 'conflict'). privacy = 'titles' | 'busy_only'.
		// channel_* / sync_* columns are Phase 1 sync-engine bookkeeping,
		// shipped now so the schema never needs another migration for it.
		dbDelta( "CREATE TABLE {$p}zsch_calendar_feeds (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			account_id bigint(20) unsigned NOT NULL,
			external_cal_id varchar(191) NOT NULL DEFAULT '',
			name varchar(191) NOT NULL DEFAULT '',
			color varchar(16) NOT NULL DEFAULT '',
			mode varchar(16) NOT NULL DEFAULT 'conflict',
			privacy varchar(16) NOT NULL DEFAULT 'titles',
			sync_cursor text NULL,
			channel_id varchar(191) NOT NULL DEFAULT '',
			channel_secret_enc text NULL,
			channel_expires_at datetime DEFAULT NULL,
			last_synced_at datetime DEFAULT NULL,
			sync_status varchar(16) NOT NULL DEFAULT 'ok',
			last_error varchar(255) NOT NULL DEFAULT '',
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY idx_feed_identity (account_id, external_cal_id),
			KEY idx_feed_account (account_id),
			KEY idx_feed_channel_exp (channel_expires_at)
		) {$charset};" );

		// ── external_events ────────────────────────────────────────
		// The normalized busy mirror. Rows exist only inside the rolling sync
		// window (today−1d → +60d); the Phase 1 engine purges as it goes.
		// busy_status = 'busy' | 'tentative' | 'free' (free rows are normally
		// skipped at ingest; the column allows a future policy change).
		dbDelta( "CREATE TABLE {$p}zsch_external_events (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			feed_id bigint(20) unsigned NOT NULL,
			external_event_id varchar(191) NOT NULL DEFAULT '',
			start_utc datetime NOT NULL,
			end_utc datetime NOT NULL,
			time_zone varchar(64) NOT NULL DEFAULT 'UTC',
			is_all_day tinyint(1) NOT NULL DEFAULT 0,
			busy_status varchar(16) NOT NULL DEFAULT 'busy',
			title varchar(191) NULL,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY idx_ext_event (feed_id, external_event_id),
			KEY idx_ext_feed_start (feed_id, start_utc),
			KEY idx_ext_window (start_utc, end_utc)
		) {$charset};" );
	}
}
