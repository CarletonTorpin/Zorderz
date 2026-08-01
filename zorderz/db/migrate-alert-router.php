<?php
/**
 * Zorderz Theme — Alert Router Database Migration
 *
 * Creates the wp_zdz_notifications table for in-app alert delivery.
 * Cached via option so it only runs ONCE, not on every request.
 *
 * @since   2.19.0
 * @package Zorderz
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function zdz_theme_migrate_alert_router() {
	// Quick exit: if schema version is current, skip entirely (no DB queries)
	if ( get_option( 'zdz_notifications_schema', 0 ) >= 1 ) {
		return;
	}

	global $wpdb;
	$table   = $wpdb->prefix . 'zdz_notifications';
	$charset = $wpdb->get_charset_collate();

	$exists = $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) === $table;

	if ( ! $exists ) {
		$wpdb->query(
			"CREATE TABLE IF NOT EXISTS `{$table}` (
				`id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				`user_id` bigint(20) unsigned NOT NULL,
				`alert_type` varchar(50) NOT NULL,
				`title` varchar(255) NOT NULL,
				`message` text NOT NULL,
				`source_plugin` varchar(100) DEFAULT NULL,
				`source_id` bigint(20) unsigned DEFAULT NULL,
				`source_meta` text DEFAULT NULL,
				`is_read` tinyint(1) NOT NULL DEFAULT 0,
				`read_at` datetime DEFAULT NULL,
				`created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY (`id`),
				KEY `idx_user_unread` (`user_id`, `is_read`, `created_at`),
				KEY `idx_type` (`alert_type`),
				KEY `idx_source` (`source_plugin`, `source_id`)
			) {$charset};"
		);
		if ( $wpdb->last_error ) {
			error_log( 'Zorderz: FAILED to create zdz_notifications: ' . $wpdb->last_error );
			return; // Don't set the flag — retry next time
		}
	}

	// Mark schema as complete — never run again
	update_option( 'zdz_notifications_schema', 1, true );
	error_log( 'Zorderz: zdz_notifications schema v1 complete.' );
}

add_action( 'admin_init', 'zdz_theme_migrate_alert_router' );
add_action( 'after_switch_theme', 'zdz_theme_migrate_alert_router' );
// Runs on init ONCE — the option check exits immediately on subsequent requests
add_action( 'init', 'zdz_theme_migrate_alert_router' );
