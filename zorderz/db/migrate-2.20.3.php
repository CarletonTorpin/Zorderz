<?php
/**
 * Migration: v2.20.3 — Clean up deprecated Personal Records user meta keys.
 *
 * The Personal Records system was redesigned in v2.20.0 to track effort-based
 * achievements instead of financial KPIs. The old keys were:
 *   - zdz_pr_best_single_day_revenue
 *   - zdz_pr_fastest_install_turnaround
 *   - zdz_pr_best_mom_growth_pct
 *
 * These are no longer written or read by any code but may still exist in
 * wp_usermeta from pre-v2.20.0 installs. This migration removes them.
 *
 * Idempotent: safe to run multiple times — DELETE WHERE ignores missing rows.
 *
 * @since 2.20.3
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function zdz_migrate_2_20_3() {
	if ( get_option( 'zdz_migrated_2_20_3', false ) ) {
		return;
	}

	global $wpdb;

	$deprecated_keys = [
		'zdz_pr_best_single_day_revenue',
		'zdz_pr_fastest_install_turnaround',
		'zdz_pr_best_mom_growth_pct',
	];

	$placeholders = implode( ', ', array_fill( 0, count( $deprecated_keys ), '%s' ) );

	// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->usermeta} WHERE meta_key IN ({$placeholders})",
			...$deprecated_keys
		)
	);

	$deleted = $wpdb->rows_affected;
	if ( $deleted > 0 ) {
		error_log( "TS migrate-2.20.3: Removed {$deleted} deprecated zdz_pr_* user meta rows." );
	}

	update_option( 'zdz_migrated_2_20_3', true, false );
}

zdz_migrate_2_20_3();
