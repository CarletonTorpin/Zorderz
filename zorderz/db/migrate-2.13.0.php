<?php
/**
 * Zorderz Theme — v2.13.0 Database Migration
 *
 * Creates two tables for backend infrastructure that supports
 * per-user goals and all-time personal records. Admin-facing UI
 * for setting goals lives in wp-admin (Users → Team Goals).
 * Frontend visualization is deferred — the tables simply exist
 * for plugins to read/write.
 *
 * Tables:
 *   wp_zdz_user_goals        — per-user KPI goals
 *   wp_zdz_personal_records  — per-user all-time bests
 *
 * Idempotent: hooked to `admin_init` and `after_switch_theme`,
 * checks `zdz_theme_db_version` option, uses dbDelta.
 *
 * @package Zorderz
 * @since   2.13.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function zdz_theme_migrate_2_13_0() {
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$charset = $wpdb->get_charset_collate();

	$t_goals   = $wpdb->prefix . 'zdz_user_goals';
	$t_records = $wpdb->prefix . 'zdz_personal_records';

	dbDelta( "CREATE TABLE {$t_goals} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		user_id bigint(20) unsigned NOT NULL,
		kpi_key varchar(64) NOT NULL,
		period_type varchar(16) NOT NULL DEFAULT 'month',
		period_start date NOT NULL,
		target_value decimal(14,2) NOT NULL DEFAULT 0.00,
		unit varchar(16) NOT NULL DEFAULT 'dollars',
		set_by bigint(20) unsigned DEFAULT NULL,
		set_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
		notes text,
		PRIMARY KEY  (id),
		UNIQUE KEY user_kpi_period (user_id, kpi_key, period_type, period_start),
		KEY user_id (user_id),
		KEY kpi_key (kpi_key)
	) {$charset};" );

	dbDelta( "CREATE TABLE {$t_records} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		user_id bigint(20) unsigned NOT NULL,
		record_type varchar(64) NOT NULL,
		record_value decimal(14,2) NOT NULL DEFAULT 0.00,
		achieved_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
		context_json text,
		PRIMARY KEY  (id),
		UNIQUE KEY user_record_type (user_id, record_type),
		KEY user_id (user_id)
	) {$charset};" );

	update_option( 'zdz_theme_db_version', '2.13.0' );
}

// Run on admin page loads if version stale. NEVER fires on frontend
// (admin_init is admin-only), so this cannot slow down the SPA.
add_action( 'admin_init', function() {
	$stored = (string) get_option( 'zdz_theme_db_version', '' );
	if ( version_compare( $stored, '2.13.0', '<' ) ) {
		zdz_theme_migrate_2_13_0();
	}
} );

// Also run once on theme switch.
add_action( 'after_switch_theme', 'zdz_theme_migrate_2_13_0' );
