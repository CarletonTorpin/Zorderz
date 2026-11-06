<?php
/**
 * The bell's first day.
 *
 * In-app alerts may have been written to wp_zdz_notifications before there was a bell to
 * show them. On the bell's first day the old ones would otherwise arrive as one wall of
 * unread rows. This pass keeps the last 30 days unread and marks older ones read. Nothing
 * is deleted; the bell lists them all, newest first.
 *
 * Runs once, flagged by the option zdz_notifications_bell_seed. Unread counts are cached
 * five minutes per person (transient zdz_unread_alerts_<id>), so the pass clears that cache
 * for everyone it touched and no badge shows the old pile afterwards.
 *
 * @package Zorderz
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function zdz_theme_migrate_bell_first_day() {
	if ( (int) get_option( 'zdz_notifications_bell_seed', 0 ) >= 1 ) {
		return;
	}
	if ( (int) get_option( 'zdz_notifications_schema', 0 ) < 1 ) {
		return; // the table first (migrate-alert-router.php runs earlier on the same hook)
	}
	global $wpdb;
	$table  = $wpdb->prefix . 'zdz_notifications';
	$cutoff = gmdate( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) . ' UTC' ) - 30 * DAY_IN_SECONDS );   // created_at is site-local
	$uids   = $wpdb->get_col( $wpdb->prepare(
		"SELECT DISTINCT user_id FROM `{$table}` WHERE is_read = 0 AND created_at < %s", $cutoff // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$table} is $wpdb->prefix plus a fixed table name
	) );
	$n = $wpdb->query( $wpdb->prepare(
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$table} is $wpdb->prefix plus a fixed table name
		"UPDATE `{$table}` SET is_read = 1, read_at = %s WHERE is_read = 0 AND created_at < %s",
		current_time( 'mysql' ), $cutoff
	) );
	if ( false === $n ) {
		error_log( 'Zorderz: the bell\'s first-day pass could not run (' . $wpdb->last_error . '); it will try again.' );
		return;
	}
	foreach ( (array) $uids as $uid ) {
		delete_transient( 'zdz_unread_alerts_' . (int) $uid );
	}
	update_option( 'zdz_notifications_bell_seed', 1, true );
	error_log( sprintf( 'Zorderz: the bell\'s first day - %d alert(s) older than 30 days marked read; newer ones stay unread.', (int) $n ) );
}

add_action( 'init', 'zdz_theme_migrate_bell_first_day', 11 );
add_action( 'admin_init', 'zdz_theme_migrate_bell_first_day', 11 );
