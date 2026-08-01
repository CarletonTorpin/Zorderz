<?php
/**
 * Zorderz Theme — v2.17.1 Database Migration
 *
 * Creates/patches the shared user media table.
 * Cached via option so it only runs ONCE, not on every request.
 *
 * @since   2.17.1
 * @package Zorderz
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function zdz_theme_migrate_2_17_1() {
	// Quick exit: if schema version is current, skip entirely (no DB queries)
	if ( get_option( 'zdz_media_schema', 0 ) >= 2 ) {
		return;
	}

	global $wpdb;
	$table   = $wpdb->prefix . 'zdz_user_media';
	$charset = $wpdb->get_charset_collate();

	$exists = $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) === $table;

	if ( ! $exists ) {
		$wpdb->query(
			"CREATE TABLE IF NOT EXISTS `{$table}` (
				`id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				`user_id` bigint(20) unsigned NOT NULL,
				`file_url` varchar(512) NOT NULL DEFAULT '',
				`thumbnail_url` varchar(512) NOT NULL DEFAULT '',
				`filename` varchar(255) NOT NULL DEFAULT '',
				`media_type` varchar(32) NOT NULL DEFAULT 'photo',
				`source_app` varchar(64) NOT NULL DEFAULT '',
				`source_ref` varchar(128) NOT NULL DEFAULT '',
				`title` varchar(255) NOT NULL DEFAULT 'Untitled',
				`description` text,
				`privacy` varchar(16) NOT NULL DEFAULT 'private',
				`wp_attachment_id` bigint(20) unsigned DEFAULT 0,
				`meta_json` longtext,
				`created_at` datetime NOT NULL,
				`updated_at` datetime NOT NULL,
				PRIMARY KEY (`id`),
				KEY `user_id` (`user_id`),
				KEY `media_type` (`media_type`),
				KEY `source_app` (`source_app`),
				KEY `privacy` (`privacy`),
				KEY `user_type` (`user_id`, `media_type`),
				KEY `user_app` (`user_id`, `source_app`)
			) {$charset};"
		);
		if ( $wpdb->last_error ) {
			error_log( 'Zorderz: FAILED to create zdz_user_media: ' . $wpdb->last_error );
			return; // Don't set the flag — retry next time
		}
	} else {
		// Patch missing columns
		$cols = $wpdb->get_col( "DESCRIBE `{$table}`", 0 );
		if ( ! in_array( 'meta_json', $cols, true ) ) {
			$wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN `meta_json` longtext AFTER `wp_attachment_id`" );
		}
		if ( ! in_array( 'description', $cols, true ) ) {
			$wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN `description` text AFTER `title`" );
		}
	}

	// Mark schema as complete — never run again
	update_option( 'zdz_media_schema', 2, true );
	error_log( 'Zorderz: zdz_user_media schema v2 complete.' );
}

add_action( 'admin_init', 'zdz_theme_migrate_2_17_1' );
add_action( 'after_switch_theme', 'zdz_theme_migrate_2_17_1' );
// Runs on init ONCE — the option check exits immediately on subsequent requests
add_action( 'init', 'zdz_theme_migrate_2_17_1' );
