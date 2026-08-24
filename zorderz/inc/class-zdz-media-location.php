<?php
/**
 * ZDZ_Media_Location — media-set location classifier (geo-PII boundary).
 *
 * Classifies a photo/media capture set's location into a CATEGORICAL STATUS by
 * comparing the set's centroid against a job anchor (from ZDZ_Geocoder::
 * resolve_address()). Sibling to ZDZ_Media_Exif, so receipts today and
 * jobs/camera later reuse one classifier.
 *
 * ── THE GEO-PII GATE (the entire point of this class) ────────────────────────
 * classify() COMPUTES with coordinates internally (a haversine distance) but
 * EXPOSES ONLY a categorical status. The return array carries no coordinate, no
 * centroid, no anchor, no radius, and no distance number — just:
 *     [ 'loc_status' => <one of the STATUS_* vocab>, 'selectable' => bool ]
 * A customer's home coordinates are derived from install photos at a private
 * residence (GDPR/CCPA weight); the client, a public receipt page, and any AJAX
 * payload may receive the status ONLY. Callers must never re-attach coordinates.
 *
 * Status vocabulary (from the location-integrity spec, S5-07 / §78):
 *   - on_site   : centroid <= onsite radius (250 m default) — SELECTABLE.
 *   - off_site  : onsite..candidate radius (250 m..3 km)    — BLOCKED (returned,
 *                 shown blocked in the picker — not silently dropped).
 *   - dropped   : beyond the candidate radius (> 3 km)      — the caller must NOT
 *                 return this set to the client at all.
 *   - unlocated : the set has no GPS                         — SELECTABLE always
 *                 (a missing geotag can't be disproven).
 *   - located   : the set has GPS but there is NO anchor     — SELECTABLE; the
 *                 client infers an anchor from the located sets (never invent the
 *                 property server-side).
 *
 * Fail-open on the geocode/anchor: no anchor never makes a set fatal — it stays
 * selectable/located or unlocated.
 *
 * @since   1.7.0
 * @package Zorderz
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZDZ_Media_Location {

	/** Core-tunable radius defaults (meters). Behavioural: "same lot vs different job". */
	const ONSITE_RADIUS_M    = 250.0;
	const CANDIDATE_RADIUS_M = 3000.0;

	/** The ONLY categorical statuses that may cross the public boundary. */
	const STATUS_ON_SITE   = 'on_site';
	const STATUS_OFF_SITE  = 'off_site';
	const STATUS_DROPPED   = 'dropped';
	const STATUS_UNLOCATED = 'unlocated';
	const STATUS_LOCATED   = 'located';

	/**
	 * Classify a capture set against an anchor. Returns ONLY a categorical status
	 * and a selectable flag — never a coordinate, centroid, radius, or distance.
	 *
	 * @param array      $set_centroid The set's centroid: numeric lat/lng under any
	 *                                 of lat|latitude|gps_lat and lng|lon|longitude|
	 *                                 gps_lng. Missing/invalid => 'unlocated'.
	 * @param array|null $anchor       The job anchor (e.g. ZDZ_Geocoder::
	 *                                 resolve_address() output). Null => no anchor.
	 * @param array      $opts         Reserved for future context; unused today.
	 * @return array{loc_status:string,selectable:bool}
	 */
	public static function classify( array $set_centroid, ?array $anchor, array $opts = array() ): array {
		unset( $opts ); // reserved; no coordinate-bearing option is honored.

		$c = self::coord( $set_centroid );

		// No GPS on the set -> unlocated, always selectable.
		if ( null === $c ) {
			return self::result( self::STATUS_UNLOCATED, true );
		}

		$a = ( null === $anchor ) ? null : self::coord( $anchor );

		// Located set but no anchor -> the client infers an anchor from located
		// sets; we never invent the property. Selectable.
		if ( null === $a ) {
			return self::result( self::STATUS_LOCATED, true );
		}

		$onsite    = (float) self::filter( 'zdz_media_onsite_radius_m', self::ONSITE_RADIUS_M );
		$candidate = (float) self::filter( 'zdz_media_candidate_radius_m', self::CANDIDATE_RADIUS_M );
		if ( $candidate < $onsite ) {
			$candidate = $onsite; // never let a mis-set filter invert the bands.
		}

		// Distance is computed HERE and discarded — it never leaves this method.
		$dist_m = self::haversine_m( $c[0], $c[1], $a[0], $a[1] );

		if ( $dist_m <= $onsite ) {
			return self::result( self::STATUS_ON_SITE, true );
		}
		if ( $dist_m <= $candidate ) {
			return self::result( self::STATUS_OFF_SITE, false ); // blocked, shown
		}
		return self::result( self::STATUS_DROPPED, false );      // caller must drop
	}

	/**
	 * Whether a status means the caller may include the set in a client payload at
	 * all. 'dropped' is the strict-drop status (also used for the cross_user admin
	 * whole-team pull, which keeps a strict drop of off-property captures).
	 */
	public static function is_droppable( string $loc_status ): bool {
		return self::STATUS_DROPPED === $loc_status;
	}

	/**
	 * Sanctioned URL for a (possibly geotagged) media row: always routes through
	 * ZDZ_User_Media::secure_url(), which returns a membership/privacy-gated proxy
	 * URL — never a raw wp-uploads path. Fails CLOSED to '' if the media layer is
	 * unavailable, so a geotagged asset can never be emitted as a raw URL.
	 *
	 * @param array  $row  A media record row (as shaped by ZDZ_User_Media).
	 * @param string $size 'full' | 'thumb'.
	 */
	public static function safe_media_url( array $row, string $size = 'full' ): string {
		if ( class_exists( 'ZDZ_User_Media' ) && method_exists( 'ZDZ_User_Media', 'secure_url' ) ) {
			return (string) ZDZ_User_Media::secure_url( $row, $size );
		}
		return '';
	}

	/* ───────────────────────── Internals ───────────────────────── */

	/** The boundary shape — the ONLY thing that leaves this class. */
	private static function result( string $loc_status, bool $selectable ): array {
		return array(
			'loc_status' => $loc_status,
			'selectable' => $selectable,
		);
	}

	/**
	 * Extract a validated [lat, lng] pair, or null when absent/invalid.
	 *
	 * @return array{0:float,1:float}|null
	 */
	private static function coord( array $arr ): ?array {
		$lat = self::num( $arr, array( 'lat', 'latitude', 'gps_lat' ) );
		$lng = self::num( $arr, array( 'lng', 'lon', 'long', 'longitude', 'gps_lng' ) );
		if ( null === $lat || null === $lng ) {
			return null;
		}
		if ( $lat < -90.0 || $lat > 90.0 || $lng < -180.0 || $lng > 180.0 ) {
			return null;
		}
		if ( 0.0 === $lat && 0.0 === $lng ) {
			return null; // null island — treat as no fix.
		}
		return array( $lat, $lng );
	}

	private static function num( array $arr, array $keys ): ?float {
		foreach ( $keys as $k ) {
			if ( isset( $arr[ $k ] ) && is_numeric( $arr[ $k ] ) ) {
				return (float) $arr[ $k ];
			}
		}
		return null;
	}

	/** Great-circle distance in meters (haversine). */
	private static function haversine_m( float $lat1, float $lng1, float $lat2, float $lng2 ): float {
		$r    = 6371008.8; // mean Earth radius, meters
		$d_la = deg2rad( $lat2 - $lat1 );
		$d_ln = deg2rad( $lng2 - $lng1 );
		$a    = sin( $d_la / 2 ) ** 2
			+ cos( deg2rad( $lat1 ) ) * cos( deg2rad( $lat2 ) ) * sin( $d_ln / 2 ) ** 2;
		return $r * 2 * atan2( sqrt( $a ), sqrt( 1 - $a ) );
	}

	/** apply_filters wrapper that degrades to the default when WP is absent. */
	private static function filter( string $tag, $default, ...$args ) {
		if ( function_exists( 'apply_filters' ) ) {
			return apply_filters( $tag, $default, ...$args );
		}
		return $default;
	}
}
