<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class ZKV_Categories {

	public static function get_all() {
		global $wpdb;
		$rows = $wpdb->get_results(
			"SELECT id, slug, label, description, sort_order FROM {$wpdb->prefix}zkv_categories ORDER BY sort_order ASC, label ASC",
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	public static function get_by_slug( $slug ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}zkv_categories WHERE slug = %s", $slug
		), ARRAY_A );
	}

	public static function seed_defaults() {
		global $wpdb;
		$table = $wpdb->prefix . 'zkv_categories';
		$json  = ZKV_PLUGIN_DIR . 'defaults/categories.json';
		if ( ! file_exists( $json ) ) { return 0; }
		$list = json_decode( file_get_contents( $json ), true );
		if ( ! is_array( $list ) ) { return 0; }
		$added = 0;
		$order = 10;
		foreach ( $list as $c ) {
			$slug = sanitize_title( $c['slug'] ?? '' );
			if ( empty( $slug ) ) { $order += 10; continue; }
			$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE slug = %s", $slug ) );
			if ( $exists ) { $order += 10; continue; }
			$wpdb->insert( $table, array(
				'slug'        => $slug,
				'label'       => sanitize_text_field( $c['label'] ?? ucfirst( $slug ) ),
				'description' => sanitize_text_field( $c['description'] ?? '' ),
				'sort_order'  => $order,
				'created_at'  => current_time( 'mysql' ),
			), array( '%s','%s','%s','%d','%s' ) );
			$added++;
			$order += 10;
		}
		return $added;
	}
}
