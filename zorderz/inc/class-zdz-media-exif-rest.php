<?php
/**
 * TS Media EXIF REST endpoint.
 *
 * GET /wp-json/zorderz/v1/media/{id}/exif
 *   → Returns the EXIF report for a single media record the current user is
 *     allowed to see. On first call for a geotagged photo, lazily resolves the
 *     place name and CACHES it into meta_json.geo_place so it never resolves
 *     again. This is the only place that triggers geocoding — viewing a
 *     thumbnail or even opening a photo without expanding Details costs nothing.
 *
 * Permission model mirrors ZDZ_User_Media visibility:
 *   - owner can always see their own record
 *   - 'team' records: any logged-in Zorderz user
 *   - 'public' records: any logged-in user (front-end is gated to logged-in)
 *
 * @since   2.21.0
 * @package Zorderz
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZDZ_Media_Exif_REST {

	public static function init(): void {
		add_action( 'rest_api_init', [ __CLASS__, 'register' ] );
	}

	public static function register(): void {
		register_rest_route(
			'zorderz/v1',
			'/media/(?P<id>\d+)/exif',
			[
				'methods'             => 'GET',
				'callback'            => [ __CLASS__, 'handle' ],
				'permission_callback' => function () {
					return is_user_logged_in();
				},
				'args'                => [
					'id' => [
						'validate_callback' => function ( $v ) {
							return is_numeric( $v ) && (int) $v > 0;
						},
					],
				],
			]
		);
	}

	public static function handle( WP_REST_Request $request ) {
		if ( ! class_exists( 'ZDZ_User_Media' ) || ! class_exists( 'ZDZ_Media_Exif' ) ) {
			return new WP_Error( 'zdz_media_unavailable', 'Media subsystem unavailable.', [ 'status' => 500 ] );
		}

		$id = (int) $request['id'];
		$record = ZDZ_User_Media::get_by_id( $id );
		if ( ! $record ) {
			return new WP_Error( 'zdz_media_not_found', 'Media not found.', [ 'status' => 404 ] );
		}

		if ( ! self::can_view( $record ) ) {
			return new WP_Error( 'zdz_media_forbidden', 'You do not have access to this item.', [ 'status' => 403 ] );
		}

		// Lazy resolve + cache-once. Only runs when a Details panel is opened
		// for a geotagged photo whose place hasn't been resolved yet.
		$record = self::maybe_resolve_place( $record );

		$report = ZDZ_Media_Exif::report( $record );

		return rest_ensure_response(
			[
				'id'     => $id,
				'title'  => $record['title'] ?? '',
				'report' => $report,
			]
		);
	}

	/**
	 * If the record is geotagged but has no cached geo_place, resolve it once
	 * and persist it into meta_json. Returns the (possibly updated) record.
	 */
	private static function maybe_resolve_place( array $record ): array {
		$lat = isset( $record['gps_lat'] ) && is_numeric( $record['gps_lat'] ) ? (float) $record['gps_lat'] : null;
		$lng = isset( $record['gps_lng'] ) && is_numeric( $record['gps_lng'] ) ? (float) $record['gps_lng'] : null;
		if ( null === $lat || null === $lng ) {
			return $record;
		}

		$meta = self::decode_meta( $record );

		// Already resolved (or explicitly resolved-to-nothing) → don't re-query.
		if ( array_key_exists( 'geo_place', $meta ) ) {
			return $record;
		}
		if ( ! class_exists( 'ZDZ_Media_Geocoder' ) ) {
			return $record;
		}

		$place = ZDZ_Media_Geocoder::resolve( $lat, $lng );

		// Cache the result — including a null sentinel so we don't retry a
		// coordinate that resolved to nothing (out of coverage). We store
		// false→ null distinction as: geo_place present but empty array means
		// "tried, nothing".
		$meta['geo_place'] = is_array( $place ) ? $place : [];

		self::persist_meta( (int) $record['id'], $meta );

		// Reflect into the in-memory record we pass to the report builder.
		$record['meta_json'] = $meta;
		return $record;
	}

	/* ───────────────────────── permissions ───────────────────────── */

	private static function can_view( array $record ): bool {
		$uid = get_current_user_id();
		$owner = (int) ( $record['user_id'] ?? 0 );
		if ( $owner === $uid ) {
			return true;
		}
		$privacy = $record['privacy'] ?? 'private';
		if ( 'team' === $privacy || 'public' === $privacy ) {
			return true; // front-end already gated to logged-in Zorderz users
		}
		// Admins can view anything.
		return current_user_can( 'manage_options' );
	}

	/* ───────────────────────── meta persistence ───────────────────────── */

	private static function decode_meta( array $record ): array {
		$raw = $record['meta_json'] ?? '';
		if ( is_array( $raw ) ) {
			return $raw;
		}
		if ( ! is_string( $raw ) || '' === $raw ) {
			return [];
		}
		$d = json_decode( $raw, true );
		return is_array( $d ) ? $d : [];
	}

	/**
	 * Persist meta_json for a record. We write directly (the ZDZ_User_Media
	 * update() whitelist intentionally excludes EXIF/provenance fields, and
	 * geo_place is derived provenance we own here). This is a narrow,
	 * additive write of the meta_json column only.
	 */
	private static function persist_meta( int $id, array $meta ): void {
		global $wpdb;
		$table = $wpdb->prefix . ZDZ_User_Media::TABLE;
		$wpdb->update(
			$table,
			[ 'meta_json' => wp_json_encode( $meta ) ],
			[ 'id' => $id ],
			[ '%s' ],
			[ '%d' ]
		);
	}
}

ZDZ_Media_Exif_REST::init();
