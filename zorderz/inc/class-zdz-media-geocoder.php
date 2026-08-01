<?php
/**
 * TS Media Geocoder — privacy-first reverse geocoding (GPS → place name).
 *
 * Design goals:
 *   - PRIVACY FIRST. The bundled resolver is 100% on-server: it reads a local
 *     dataset and does a nearest-point search. NOTHING leaves the server. No
 *     coordinate is ever sent to a third party by this class.
 *   - PROVIDER-AGNOSTIC. The single seam `zdz_media_reverse_geocode` lets an
 *     operator plug in any resolver they trust (self-hosted Nominatim, a keyed
 *     commercial API, etc.). If a filter returns a result, it wins; otherwise
 *     the offline resolver runs. See docs/GEOCODING.md for a Nominatim adapter.
 *   - RESOLVE ONCE. Callers cache the result (see ZDZ_Media_Exif), so this runs
 *     at most once per photo, lazily, only when a Details panel is opened.
 *
 * Returned shape (or null if nothing could be resolved):
 *   [
 *     'label'        => 'Mount Helix, La Mesa, CA 91941',  // display string
 *     'neighborhood' => 'Mount Helix'  | '',
 *     'city'         => 'La Mesa'       | '',
 *     'admin1'       => 'CA'            | '',   // state / principal subdivision
 *     'postcode'     => '91941'         | '',   // shown only when confident
 *     'country'      => 'US'            | '',
 *     'distance_km'  => 1.7,                    // to the matched place centroid
 *     'provider'     => 'offline-sdcounty',     // provenance
 *     'resolved_at'  => '2026-05-29 20:41:00',  // provenance (UTC, mysql fmt)
 *   ]
 *
 * @since   2.21.0
 * @package Zorderz
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZDZ_Media_Geocoder {

	/**
	 * Max distance (km) from the nearest known place for which we'll show a
	 * place name at all. Beyond this we return null and the UI shows raw coords
	 * only — better to say nothing than to mislabel a remote coordinate.
	 */
	const MAX_PLACE_KM = 30.0;

	/**
	 * "Confident ZIP" threshold (km). The dataset stores one representative
	 * centroid per place; a ZIP is only meaningful very close to that centroid.
	 * Past this distance we keep the city/neighborhood but DROP the postcode,
	 * per the product decision ("show the ZIP only when confident").
	 */
	const ZIP_CONFIDENT_KM = 4.0;

	/** @var array<int,array>|null In-process cache of the parsed dataset. */
	private static $places = null;

	/**
	 * Resolve a coordinate to a place. Tries operator-provided resolvers first
	 * (filter), then the offline dataset.
	 *
	 * @param float $lat
	 * @param float $lng
	 * @return array|null
	 */
	public static function resolve( float $lat, float $lng ): ?array {
		if ( ! self::valid_coord( $lat, $lng ) ) {
			return null;
		}

		/**
		 * Filter: zdz_media_reverse_geocode
		 *
		 * Return a result array (see class docblock for shape; at minimum a
		 * non-empty 'label') to OVERRIDE the offline resolver — this is where
		 * you wire a street-level provider you trust (e.g. self-hosted
		 * Nominatim). Return null/false to fall through to the offline lookup.
		 *
		 * IMPORTANT (privacy): any resolver added here that calls out to a
		 * network service sends the coordinate off-server. The bundled default
		 * does NOT. Only add a networked resolver if you've accepted that.
		 *
		 * @param null|array $pre  Short-circuit value (null by default).
		 * @param float      $lat
		 * @param float      $lng
		 */
		$pre = apply_filters( 'zdz_media_reverse_geocode', null, $lat, $lng );
		if ( is_array( $pre ) && ! empty( $pre['label'] ) ) {
			// Stamp provenance if the filter didn't.
			if ( empty( $pre['provider'] ) ) {
				$pre['provider'] = 'filter';
			}
			if ( empty( $pre['resolved_at'] ) ) {
				$pre['resolved_at'] = gmdate( 'Y-m-d H:i:s' );
			}
			return $pre;
		}

		// Offline resolver toggle (on by default — it's fully local, so it has
		// no privacy cost and, thanks to lazy+cache upstream, no real overhead).
		if ( ! apply_filters( 'zdz_media_offline_geocode_enabled', true ) ) {
			return null;
		}

		return self::resolve_offline( $lat, $lng );
	}

	/**
	 * Offline nearest-named-place lookup over the bundled dataset.
	 *
	 * @param float $lat
	 * @param float $lng
	 * @return array|null
	 */
	private static function resolve_offline( float $lat, float $lng ): ?array {
		$places = self::load_places();
		if ( empty( $places ) ) {
			return null;
		}

		$best = null;
		$best_km = INF;
		foreach ( $places as $p ) {
			$km = self::haversine_km( $lat, $lng, $p['lat'], $p['lng'] );
			if ( $km < $best_km ) {
				$best_km = $km;
				$best = $p;
			}
		}

		if ( null === $best || $best_km > self::MAX_PLACE_KM ) {
			return null;
		}

		// Compose neighborhood + city. A PPLX/neighborhood carries a parent
		// city; an incorporated city (PPLA2) or standalone community (PPL) is
		// its own "city" with no neighborhood line.
		$neighborhood = '';
		$city = '';
		if ( 'PPLX' === $best['fcode'] && '' !== $best['parent'] ) {
			$neighborhood = $best['name'];
			$city = $best['parent'];
		} else {
			$city = $best['name'];
		}

		// ZIP only when confident (close to the place centroid).
		$postcode = ( $best_km <= self::ZIP_CONFIDENT_KM ) ? $best['zip'] : '';

		$label = self::compose_label( $neighborhood, $city, $best['admin1'], $postcode );
		if ( '' === $label ) {
			return null;
		}

		return [
			'label'        => $label,
			'neighborhood' => $neighborhood,
			'city'         => $city,
			'admin1'       => $best['admin1'],
			'postcode'     => $postcode,
			'country'      => $best['country'],
			'distance_km'  => round( $best_km, 2 ),
			'provider'     => 'offline-sdcounty',
			'resolved_at'  => gmdate( 'Y-m-d H:i:s' ),
		];
	}

	/**
	 * Build a human label, e.g. "Mount Helix, La Mesa, CA 91941" or
	 * "El Cajon, CA". Empty parts are skipped.
	 */
	private static function compose_label( string $neighborhood, string $city, string $admin1, string $postcode ): string {
		$primary = [];
		if ( '' !== $neighborhood ) $primary[] = $neighborhood;
		if ( '' !== $city )         $primary[] = $city;
		$line = implode( ', ', $primary );

		$tail = trim( $admin1 . ( '' !== $postcode ? ' ' . $postcode : '' ) );
		if ( '' !== $line && '' !== $tail ) {
			return $line . ', ' . $tail;
		}
		return '' !== $line ? $line : $tail;
	}

	/**
	 * Load + parse the bundled TSV once per request. Statically cached so a
	 * page that inspects several photos only parses the file a single time.
	 * If nobody opens a Details panel, this is never called → zero overhead.
	 *
	 * TSV columns (GeoNames-compatible subset; tab-separated, no header):
	 *   name  lat  lng  feature_code  admin1  country  zip  parent_city
	 *
	 * @return array<int,array>
	 */
	private static function load_places(): array {
		if ( null !== self::$places ) {
			return self::$places;
		}
		self::$places = [];

		/**
		 * Filter: zdz_media_geocode_dataset_path
		 * Point this at a larger GeoNames file (e.g. the full US.txt) to expand
		 * coverage with ZERO code changes — same parser, same format.
		 */
		$path = apply_filters(
			'zdz_media_geocode_dataset_path',
			trailingslashit( dirname( __DIR__ ) ) . 'data/sd-county-places.tsv'
		);

		if ( ! $path || ! is_readable( $path ) ) {
			return self::$places;
		}

		$fh = @fopen( $path, 'r' );
		if ( ! $fh ) {
			return self::$places;
		}
		while ( ( $line = fgets( $fh ) ) !== false ) {
			$line = rtrim( $line, "\r\n" );
			if ( '' === $line ) {
				continue;
			}
			$c = explode( "\t", $line );
			// Need at least name, lat, lng.
			if ( count( $c ) < 3 || ! is_numeric( $c[1] ) || ! is_numeric( $c[2] ) ) {
				continue;
			}
			self::$places[] = [
				'name'    => $c[0],
				'lat'     => (float) $c[1],
				'lng'     => (float) $c[2],
				'fcode'   => $c[3] ?? 'PPL',
				'admin1'  => $c[4] ?? '',
				'country' => $c[5] ?? '',
				'zip'     => $c[6] ?? '',
				'parent'  => $c[7] ?? '',
			];
		}
		fclose( $fh );

		return self::$places;
	}

	/**
	 * Great-circle distance in kilometers (haversine).
	 */
	private static function haversine_km( float $lat1, float $lng1, float $lat2, float $lng2 ): float {
		$r = 6371.0088; // mean Earth radius, km
		$dLat = deg2rad( $lat2 - $lat1 );
		$dLng = deg2rad( $lng2 - $lng1 );
		$a = sin( $dLat / 2 ) ** 2
			+ cos( deg2rad( $lat1 ) ) * cos( deg2rad( $lat2 ) ) * sin( $dLng / 2 ) ** 2;
		return $r * 2 * atan2( sqrt( $a ), sqrt( 1 - $a ) );
	}

	private static function valid_coord( float $lat, float $lng ): bool {
		if ( $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180 ) {
			return false;
		}
		// Treat the exact null island (0,0) as invalid — almost always a bug,
		// never a real field photo for this business.
		if ( 0.0 === $lat && 0.0 === $lng ) {
			return false;
		}
		return true;
	}
}
