<?php
/**
 * TS Media EXIF — build a clean, human report from a media record's metadata.
 *
 * Source of truth is the row stored by ZDZ_User_Media:
 *   - Normalized columns: captured_at, gps_lat, gps_lng (always queryable).
 *   - meta_json.exif: the COMPLETE raw EXIF block (PHP exif_read_data with the
 *     sections flag), present for JPEG/TIFF; OFTEN ABSENT for iPhone HEIC,
 *     which exif_read_data can't parse. We degrade gracefully.
 *   - meta_json.geo_source: 'exif' | 'device_fallback' (from zdz-camera v1.2.0).
 *   - meta_json.geo_place: CACHED reverse-geocode result (we write this once).
 *
 * Output (consumed by the REST endpoint / front-end panel):
 *   [
 *     'summary'    => 'Mar 14, 2026 · 1:17 PM · near La Mesa, CA',  // one line
 *     'facts'      => [ { label, value, kind } ... ],  // interpreted, ordered
 *     'location'   => { lat, lng, place, geo_source, maps_url } | null,
 *     'verbatim'   => [ { section, rows:[ {key, value} ] } ... ], // raw EXIF
 *     'has_exif'   => bool,   // was a raw EXIF block present?
 *     'tier'       => 'rich' | 'normalized' | 'none',
 *   ]
 *
 * This class never mutates the record. Caching of geo_place is done by the
 * REST layer (which has the media id), so the builder stays pure/testable.
 *
 * @since   2.21.0
 * @package Zorderz
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZDZ_Media_Exif {

	/**
	 * Build the report for a media record.
	 *
	 * @param array $record A row from ZDZ_User_Media (assoc array).
	 * @return array
	 */
	public static function report( array $record ): array {
		$meta = self::decode_meta( $record );
		$exif = isset( $meta['exif'] ) && is_array( $meta['exif'] ) ? $meta['exif'] : [];
		$has_exif = ! empty( $exif );

		$facts = [];

		// ── Capture time ──────────────────────────────────────────────────
		$captured_at = $record['captured_at'] ?? '';
		if ( $captured_at ) {
			$facts[] = [
				'label' => 'Taken',
				'value' => self::human_datetime( $captured_at ),
				'kind'  => 'time',
			];
		}

		// ── Camera / device ───────────────────────────────────────────────
		$make  = self::exif_get( $exif, [ 'IFD0', 'Make' ] );
		$model = self::exif_get( $exif, [ 'IFD0', 'Model' ] );
		$device = trim( self::dedupe_make_model( $make, $model ) );
		if ( '' !== $device ) {
			$facts[] = [ 'label' => 'Camera', 'value' => $device, 'kind' => 'device' ];
		}
		$lens = self::exif_get( $exif, [ 'EXIF', 'UndefinedTag:0xA434' ] ); // LensModel
		if ( ! $lens ) {
			$lens = self::exif_get( $exif, [ 'EXIF', 'LensModel' ] );
		}
		if ( $lens ) {
			$facts[] = [ 'label' => 'Lens', 'value' => (string) $lens, 'kind' => 'device' ];
		}

		// ── Exposure (ƒ-stop, shutter, ISO, focal length) ─────────────────
		$exposure = self::exposure_summary( $exif );
		if ( '' !== $exposure ) {
			$facts[] = [ 'label' => 'Exposure', 'value' => $exposure, 'kind' => 'exposure' ];
		}

		// ── Dimensions / orientation / size ───────────────────────────────
		$dims = self::dimensions( $exif );
		if ( '' !== $dims ) {
			$facts[] = [ 'label' => 'Dimensions', 'value' => $dims, 'kind' => 'image' ];
		}
		$orientation = self::orientation_label( self::exif_get( $exif, [ 'IFD0', 'Orientation' ] ) );
		if ( '' !== $orientation ) {
			$facts[] = [ 'label' => 'Orientation', 'value' => $orientation, 'kind' => 'image' ];
		}
		$bytes = (int) self::exif_get( $exif, [ 'FILE', 'FileSize' ] );
		if ( $bytes > 0 ) {
			$facts[] = [ 'label' => 'File size', 'value' => size_format( $bytes, 1 ), 'kind' => 'image' ];
		}
		$mime = self::exif_get( $exif, [ 'FILE', 'MimeType' ] );
		if ( $mime ) {
			$facts[] = [ 'label' => 'Format', 'value' => (string) $mime, 'kind' => 'image' ];
		}

		// ── Software ───────────────────────────────────────────────────────
		$software = self::exif_get( $exif, [ 'IFD0', 'Software' ] );
		if ( $software ) {
			$facts[] = [ 'label' => 'Software', 'value' => (string) $software, 'kind' => 'meta' ];
		}

		// ── Location block ──────────────────────────────────────────────────
		$location = self::location_block( $record, $meta );

		// ── Verbatim (organized raw EXIF, rationals decoded) ─────────────────
		$verbatim = self::verbatim_sections( $exif );

		// ── Summary line ─────────────────────────────────────────────────────
		$summary = self::summary_line( $captured_at, $location );

		$tier = $has_exif ? 'rich' : ( ( $captured_at || $location ) ? 'normalized' : 'none' );

		return [
			'summary'  => $summary,
			'facts'    => $facts,
			'location' => $location,
			'verbatim' => $verbatim,
			'has_exif' => $has_exif,
			'tier'     => $tier,
		];
	}

	/* ───────────────────────── Location ───────────────────────── */

	/**
	 * Assemble the location block from normalized columns + cached place.
	 * Does NOT resolve geocoding here (the REST layer does that lazily and
	 * passes the cached place back into the record's meta before calling us,
	 * OR we surface just coords + a maps link).
	 */
	private static function location_block( array $record, array $meta ): ?array {
		$lat = isset( $record['gps_lat'] ) && is_numeric( $record['gps_lat'] ) ? (float) $record['gps_lat'] : null;
		$lng = isset( $record['gps_lng'] ) && is_numeric( $record['gps_lng'] ) ? (float) $record['gps_lng'] : null;
		if ( null === $lat || null === $lng ) {
			return null;
		}

		$geo_source = $meta['geo_source'] ?? '';
		$place = ( isset( $meta['geo_place'] ) && is_array( $meta['geo_place'] ) ) ? $meta['geo_place'] : null;

		return [
			'lat'         => round( $lat, 7 ),
			'lng'         => round( $lng, 7 ),
			'coord_label' => self::coord_label( $lat, $lng ),
			'place'       => $place ? ( $place['label'] ?? '' ) : '',
			'place_full'  => $place,                 // full cached object (provider, distance, etc.)
			'geo_source'  => $geo_source,            // 'exif' | 'device_fallback' | ''
			'maps_url'    => self::maps_url( $lat, $lng ),
		];
	}

	/**
	 * A user-initiated maps link. The user's browser contacts the map provider
	 * only if THEY tap it — the server never phones home. geo: first (mobile
	 * deep-link) is not used here because we want a plain https link that works
	 * everywhere; the front-end may add a geo: variant.
	 */
	private static function maps_url( float $lat, float $lng ): string {
		return sprintf( 'https://www.openstreetmap.org/?mlat=%1$.6f&mlon=%2$.6f#map=17/%1$.6f/%2$.6f', $lat, $lng );
	}

	private static function coord_label( float $lat, float $lng ): string {
		$ns = $lat >= 0 ? 'N' : 'S';
		$ew = $lng >= 0 ? 'E' : 'W';
		return sprintf( '%.5f° %s, %.5f° %s', abs( $lat ), $ns, abs( $lng ), $ew );
	}

	/* ───────────────────────── Verbatim ───────────────────────── */

	/**
	 * Organize the raw EXIF into display sections, decoding rationals and
	 * binary into readable strings. We keep EVERYTHING (the principle is "logs
	 * are everything") but render it cleanly. Sections are ordered with the
	 * useful ones first; unknown sections are appended.
	 *
	 * @param array $exif
	 * @return array<int,array{section:string,rows:array<int,array{key:string,value:string}>}>
	 */
	private static function verbatim_sections( array $exif ): array {
		if ( empty( $exif ) ) {
			return [];
		}

		$order = [ 'FILE', 'COMPUTED', 'IFD0', 'EXIF', 'GPS', 'INTEROP', 'MAKERNOTE', 'THUMBNAIL' ];
		$labels = [
			'FILE'      => 'File',
			'COMPUTED'  => 'Computed',
			'IFD0'      => 'Image (IFD0)',
			'EXIF'      => 'Exif',
			'GPS'       => 'GPS',
			'INTEROP'   => 'Interoperability',
			'MAKERNOTE' => 'Maker Note',
			'THUMBNAIL' => 'Thumbnail',
		];

		$sections = [];
		$seen = [];

		$emit = function ( string $name ) use ( $exif, $labels, &$sections, &$seen ) {
			if ( ! isset( $exif[ $name ] ) || ! is_array( $exif[ $name ] ) ) {
				return;
			}
			$seen[ $name ] = true;
			$rows = [];
			foreach ( $exif[ $name ] as $key => $val ) {
				$rows[] = [
					'key'   => self::pretty_key( (string) $key ),
					'value' => self::stringify_exif_value( $key, $val ),
				];
			}
			if ( $rows ) {
				$sections[] = [
					'section' => $labels[ $name ] ?? $name,
					'rows'    => $rows,
				];
			}
		};

		foreach ( $order as $name ) {
			$emit( $name );
		}
		// Any sections we didn't anticipate.
		foreach ( $exif as $name => $blk ) {
			if ( is_array( $blk ) && empty( $seen[ $name ] ) ) {
				$emit( $name );
			}
		}

		return $sections;
	}

	/**
	 * Convert a raw EXIF value to a readable string. Handles:
	 *   - rationals "num/den" → decimal (with sensible rounding)
	 *   - arrays (e.g. GPS coordinate triplets) → joined
	 *   - binary/unprintable → "<binary N bytes>"
	 */
	private static function stringify_exif_value( $key, $val ): string {
		if ( is_array( $val ) ) {
			$parts = array_map( function ( $v ) {
				return is_string( $v ) ? self::maybe_rational( $v ) : (string) $v;
			}, $val );
			return implode( ', ', $parts );
		}

		if ( is_string( $val ) ) {
			// Unprintable / binary guard.
			if ( '' !== $val && ! self::is_printable( $val ) ) {
				return '<binary ' . strlen( $val ) . ' bytes>';
			}
			return self::maybe_rational( $val );
		}

		if ( is_bool( $val ) ) {
			return $val ? 'true' : 'false';
		}

		return (string) $val;
	}

	/** Turn "num/den" into a tidy decimal; pass other strings through. */
	private static function maybe_rational( string $s ): string {
		if ( preg_match( '#^(-?\d+)/(\d+)$#', $s, $m ) ) {
			$num = (float) $m[1];
			$den = (float) $m[2];
			if ( 0.0 === $den ) {
				return '0';
			}
			$d = $num / $den;
			// Keep integers clean; otherwise up to 4 dp, trimmed.
			if ( floor( $d ) === $d ) {
				return (string) (int) $d;
			}
			return rtrim( rtrim( sprintf( '%.4f', $d ), '0' ), '.' );
		}
		return $s;
	}

	/* ───────────────────────── Interpreters ───────────────────────── */

	private static function exposure_summary( array $exif ): string {
		$bits = [];

		// Aperture: FNumber rational, else ApertureValue.
		$fnum = self::rational_to_float( self::exif_get( $exif, [ 'EXIF', 'FNumber' ] ) );
		if ( null !== $fnum && $fnum > 0 ) {
			$bits[] = 'ƒ/' . rtrim( rtrim( sprintf( '%.1f', $fnum ), '0' ), '.' );
		}

		// Shutter: ExposureTime rational → "1/120s" or "0.5s".
		$exp = self::exif_get( $exif, [ 'EXIF', 'ExposureTime' ] );
		$shutter = self::shutter_label( $exp );
		if ( '' !== $shutter ) {
			$bits[] = $shutter;
		}

		// ISO.
		$iso = self::exif_get( $exif, [ 'EXIF', 'ISOSpeedRatings' ] );
		if ( is_array( $iso ) ) {
			$iso = reset( $iso );
		}
		if ( is_numeric( $iso ) && (int) $iso > 0 ) {
			$bits[] = 'ISO ' . (int) $iso;
		}

		// Focal length (mm), plus 35mm-equivalent if present.
		$fl = self::rational_to_float( self::exif_get( $exif, [ 'EXIF', 'FocalLength' ] ) );
		if ( null !== $fl && $fl > 0 ) {
			$bits[] = rtrim( rtrim( sprintf( '%.1f', $fl ), '0' ), '.' ) . 'mm';
		}

		return implode( ' · ', $bits );
	}

	private static function shutter_label( $exp ): string {
		$f = self::rational_to_float( $exp );
		if ( null === $f || $f <= 0 ) {
			return '';
		}
		if ( $f >= 1.0 ) {
			return rtrim( rtrim( sprintf( '%.1f', $f ), '0' ), '.' ) . 's';
		}
		// Sub-second → 1/N.
		$denom = (int) round( 1.0 / $f );
		return '1/' . $denom . 's';
	}

	private static function dimensions( array $exif ): string {
		$w = self::exif_get( $exif, [ 'COMPUTED', 'Width' ] );
		$h = self::exif_get( $exif, [ 'COMPUTED', 'Height' ] );
		if ( ! $w ) {
			$w = self::exif_get( $exif, [ 'EXIF', 'ExifImageWidth' ] );
		}
		if ( ! $h ) {
			$h = self::exif_get( $exif, [ 'EXIF', 'ExifImageLength' ] );
		}
		$w = (int) $w; $h = (int) $h;
		if ( $w > 0 && $h > 0 ) {
			$mp = $w * $h / 1000000.0;
			$mp_str = $mp >= 0.1 ? ' (' . rtrim( rtrim( sprintf( '%.1f', $mp ), '0' ), '.' ) . ' MP)' : '';
			return $w . ' × ' . $h . $mp_str;
		}
		return '';
	}

	private static function orientation_label( $o ): string {
		$o = (int) $o;
		$map = [
			1 => 'Normal',
			2 => 'Mirrored',
			3 => 'Rotated 180°',
			4 => 'Mirrored, rotated 180°',
			5 => 'Mirrored, rotated 90° CCW',
			6 => 'Rotated 90° CW',
			7 => 'Mirrored, rotated 90° CW',
			8 => 'Rotated 90° CCW',
		];
		return $map[ $o ] ?? '';
	}

	private static function dedupe_make_model( $make, $model ): string {
		$make = trim( (string) $make );
		$model = trim( (string) $model );
		if ( '' === $model ) {
			return $make;
		}
		// "Apple" + "iPhone 15 Pro" → "Apple iPhone 15 Pro"; but if the model
		// already starts with the make, don't repeat it.
		if ( '' !== $make && stripos( $model, $make ) === 0 ) {
			return $model;
		}
		return trim( $make . ' ' . $model );
	}

	private static function human_datetime( string $mysql ): string {
		// captured_at is local wall-clock with no tz (per extract_exif). Show it
		// as given; do NOT shift by site tz (that would misrepresent the photo).
		$ts = strtotime( $mysql );
		if ( ! $ts ) {
			return $mysql;
		}
		// e.g. "Mar 14, 2026 · 1:17 PM"
		return date_i18n( 'M j, Y · g:i A', $ts );
	}

	private static function summary_line( string $captured_at, ?array $location ): string {
		$parts = [];
		if ( $captured_at ) {
			$parts[] = self::human_datetime( $captured_at );
		}
		if ( $location ) {
			if ( ! empty( $location['place'] ) ) {
				$parts[] = 'near ' . $location['place'];
			} else {
				$parts[] = $location['coord_label'];
			}
		}
		return implode( ' · ', $parts );
	}

	/* ───────────────────────── Helpers ───────────────────────── */

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

	/** Safely fetch a nested EXIF value, or '' if absent. */
	private static function exif_get( array $exif, array $path ) {
		$cur = $exif;
		foreach ( $path as $k ) {
			if ( is_array( $cur ) && array_key_exists( $k, $cur ) ) {
				$cur = $cur[ $k ];
			} else {
				return '';
			}
		}
		return $cur;
	}

	private static function rational_to_float( $v ): ?float {
		if ( is_array( $v ) ) {
			$v = reset( $v );
		}
		if ( is_numeric( $v ) ) {
			return (float) $v;
		}
		if ( is_string( $v ) && preg_match( '#^(-?\d+)/(\d+)$#', $v, $m ) ) {
			$den = (float) $m[2];
			return 0.0 !== $den ? (float) $m[1] / $den : null;
		}
		return null;
	}

	private static function is_printable( string $s ): bool {
		// Allow normal UTF-8 text; reject if it contains NULs or many control chars.
		if ( strpos( $s, "\0" ) !== false ) {
			return false;
		}
		$ctrl = preg_match_all( '/[\x00-\x08\x0E-\x1F]/', $s );
		return $ctrl < 1;
	}

	/** "UndefinedTag:0xA434" → "0xA434"; "GPSLatitudeRef" → "GPS Latitude Ref". */
	private static function pretty_key( string $key ): string {
		if ( 0 === strpos( $key, 'UndefinedTag:' ) ) {
			return substr( $key, strlen( 'UndefinedTag:' ) );
		}
		// Insert spaces at two boundaries:
		//   1. lower/digit → Upper      (FocalLength      → Focal Length)
		//   2. Upper-run → Upper+lower  (GPSLatitude      → GPS Latitude)
		// (2) splits a trailing acronym from a following CamelCase word so
		// "GPS", "ISO", etc. stay intact but read as separate words.
		$spaced = preg_replace( '/(?<=[a-z0-9])(?=[A-Z])/', ' ', $key );
		$spaced = preg_replace( '/(?<=[A-Z])(?=[A-Z][a-z])/', ' ', $spaced );
		return $spaced ?: $key;
	}
}
