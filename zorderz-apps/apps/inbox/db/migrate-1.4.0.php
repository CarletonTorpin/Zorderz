<?php
/**
 * Inbox schema migration — v1.4.0: composite indexes on the access log.
 *
 * The dot-plot access sources (ZIB_Dotplot_Sources) read wp_zib_access_log grouped by
 * (actor|subject, local-day) over a date window. The v1.2.0 single-column indexes —
 * (actor), (subject), (ts) — don't serve that shape, so add (actor_user_id, ts) and
 * (subject_owner_user_id, ts). This keeps the grouped reads index-served AND keeps the
 * ts-dot-plot registry's missing-index check quiet (an unindexed source is a latent outage,
 * per that plugin's own scar log). Idempotent: each key is added only if absent. No data change.
 *
 * @package Zorderz
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZIB_Migrate_1_4_0 {

	public static function run(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'zib_access_log';
		// The log table is created by 1.2.0; if it isn't there yet, there is nothing to index.
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return;
		}
		$wants = array(
			'idx_actor_ts'   => '(actor_user_id, ts)',
			'idx_subject_ts' => '(subject_owner_user_id, ts)',
		);
		$have = array();
		foreach ( (array) $wpdb->get_results( "SHOW INDEX FROM $table", ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB
			$have[ (string) $r['Key_name'] ] = true;
		}
		foreach ( $wants as $name => $cols ) {
			if ( isset( $have[ $name ] ) ) {
				continue;
			}
			$wpdb->query( "ALTER TABLE $table ADD KEY $name $cols" ); // phpcs:ignore WordPress.DB
		}
	}
}
