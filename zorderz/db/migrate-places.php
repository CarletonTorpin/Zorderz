<?php
/**
 * Migration: the wp_zdz_places permanent geocode cache (ZDZ_Geocoder).
 *
 * One row per distinct address the platform ever tried to turn into coordinates.
 * Buildings do not move, so a successful row is permanent; a FAILED networked lookup
 * is kept too, with an attempt counter, so an address the provider cannot place is
 * not re-sent on every pass.
 *
 * Standalone, flagged by its own schema option, existence-guarded DDL, idempotent:
 * the option is stamped only once the table is really there, so a failed create is
 * tried again on the next request.
 *
 * Rows carried over from an older cache under a different key (the rename migration
 * copies them across) are re-keyed once from their stored address, so they are hit
 * again instead of sitting unused. A row whose new key is already taken is left as it
 * was.
 *
 * @package Zorderz
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function zdz_migrate_places() {
	if ( ! class_exists( 'ZDZ_Geocoder' ) ) {
		return;
	}
	global $wpdb;
	$table = $wpdb->prefix . ZDZ_Geocoder::TABLE;

	if ( (int) get_option( ZDZ_Geocoder::SCHEMA_OPTION, 0 ) < ZDZ_Geocoder::SCHEMA_VERSION ) {
		$charset = $wpdb->get_charset_collate();
		// addr_hash: sha1 of ZDZ_Geocoder's cache key. matched_address: what the provider
		// says it matched (when a photo lands on the wrong house, this explains why).
		// lat/lng: DECIMAL(10,7), the type zdz_user_media uses for photo coordinates.
		// precision_level: rooftop | parcel | interpolated | street | city | unknown.
		// status: ok | retry | unresolvable. hit_count: a cache nobody reads is one to delete.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$table} is $wpdb->prefix plus ZDZ_Geocoder::TABLE, a class constant
		$wpdb->query( "CREATE TABLE IF NOT EXISTS `{$table}` (
			`id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			`addr_hash` CHAR(40) NOT NULL DEFAULT '',
			`address_raw` VARCHAR(255) NOT NULL DEFAULT '',
			`address_norm` VARCHAR(255) NOT NULL DEFAULT '',
			`matched_address` VARCHAR(255) NOT NULL DEFAULT '',
			`lat` DECIMAL(10,7) NULL DEFAULT NULL,
			`lng` DECIMAL(10,7) NULL DEFAULT NULL,
			`precision_level` VARCHAR(24) NOT NULL DEFAULT 'unknown',
			`provider` VARCHAR(48) NOT NULL DEFAULT '',
			`status` VARCHAR(16) NOT NULL DEFAULT 'retry',
			`attempts` SMALLINT(5) UNSIGNED NOT NULL DEFAULT 0,
			`hit_count` INT(10) UNSIGNED NOT NULL DEFAULT 0,
			`last_attempt_at` DATETIME NULL DEFAULT NULL,
			`created_at` DATETIME NOT NULL,
			`updated_at` DATETIME NOT NULL,
			PRIMARY KEY (`id`),
			UNIQUE KEY `addr_hash` (`addr_hash`),
			KEY `status` (`status`)
		) {$charset};" ); // phpcs:ignore WordPress.DB.PreparedSQL -- table name from a constant
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			error_log( 'Zorderz: the places cache table could not be created (' . $wpdb->last_error . '); will try again.' );
			return;
		}
		update_option( ZDZ_Geocoder::SCHEMA_OPTION, ZDZ_Geocoder::SCHEMA_VERSION, true );
	}

	// Re-key rows carried over under another key, once, a bounded batch per request.
	if ( get_option( 'zdz_places_rekeyed', false ) ) {
		return;
	}
	$from = (int) get_option( 'zdz_places_rekey_cursor', 0 );
	$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, addr_hash, address_raw FROM `{$table}` WHERE id > %d ORDER BY id LIMIT 500", $from ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
	foreach ( $rows as $r ) {
		$from = max( $from, (int) $r['id'] );
		$want = ZDZ_Geocoder::hash( (string) $r['address_raw'] );
		if ( '' === (string) $r['address_raw'] || $want === (string) $r['addr_hash'] ) {
			continue;
		}
		$taken = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM `{$table}` WHERE addr_hash = %s", $want ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		if ( 0 === $taken ) {
			$wpdb->update( $table, array( 'addr_hash' => $want, 'address_norm' => substr( ZDZ_Geocoder::normalize_string( (string) $r['address_raw'] ), 0, 255 ) ), array( 'id' => (int) $r['id'] ) );
		}
	}
	update_option( 'zdz_places_rekey_cursor', $from, false );
	if ( count( $rows ) < 500 ) {
		update_option( 'zdz_places_rekeyed', 1, false );
	}
}

add_action( 'init', 'zdz_migrate_places', 12 );
add_action( 'admin_init', 'zdz_migrate_places', 12 );
