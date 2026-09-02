<?php
/**
 * Inbox schema migration — v1.2.0 (P2: Gatekeeper access log).
 *
 * wp_zib_access_log — the immutable audit trail. EVERY Gatekeeper read (allow
 * or deny) appends a row. Search terms are NOT stored (query_hash only), so the
 * audit proves who reached what without itself becoming a copy of everyone's
 * searches.
 *
 * @package Zorderz
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZIB_Migrate_1_2_0 {

	public static function run(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$p       = $wpdb->prefix;

		// path = owner_search|owner_chat|admin_search|owner_share ; decision = allow|deny
		dbDelta( "CREATE TABLE {$p}zib_access_log (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			ts datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			actor_user_id bigint(20) unsigned NOT NULL,
			subject_owner_user_id bigint(20) unsigned NOT NULL,
			path varchar(20) NOT NULL DEFAULT '',
			decision varchar(8) NOT NULL DEFAULT 'deny',
			reason varchar(255) NOT NULL DEFAULT '',
			query_hash varchar(64) NOT NULL DEFAULT '',
			result_count int(11) NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY idx_actor (actor_user_id),
			KEY idx_subject (subject_owner_user_id),
			KEY idx_ts (ts)
		) {$charset};" );
	}
}
