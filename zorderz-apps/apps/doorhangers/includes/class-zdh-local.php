<?php
/**
 * ZDH_Local -- a local copy of the address layer, asked first.
 *
 * Every house Doorhangers knows comes from one address-point layer through one function, ZDH_Geo::arcgis_query(). A
 * site that keeps its own copy of that layer (a mirror on its own server, refreshed on its own schedule) answers the
 * same questions through the filter `zdh_local_lookup`, in the layer's own reply shape -- the same rows, field names and
 * road segments -- so the planner, the drawn area, the lookup, the fuzzy street check, the no-soliciting match and the
 * import read it unchanged. Only the question shapes Doorhangers itself writes are passed on (a street's name, LIKE, a
 * house number, a ZIP, a count, a box) and only with the parameters it uses; anything else, or a mirror that does not
 * answer, goes to the layer itself. Postal outlines and road lines always come from the layer.
 *
 * The same seam can give the walk log what a place is like: the ZIP's neighborhood figures, past fires and the miles
 * to the coast (`zdh_local_lookup` with 'place'), in place of a public statistics service.
 *
 *   apply_filters( 'zdh_local_lookup', null, 'address_points', $question, $uid )  -> { features | count | exceeded }
 *   apply_filters( 'zdh_local_lookup', null, 'place', { lat, lng }, $uid )         -> { zip, zip_facts, acs_year, fires,
 *                                                                                      last_fire_year, miles_to_coast }
 *
 * Public map and statistics data only; nothing here carries a customer.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZDH_Local {

	/** How many questions this request answered from the mirror / sent to the layer (for diagnostics and the log). */
	public static $hits = 0;
	public static $misses = 0;

	const STR = "'((?:[^']|'')*)'";

	private static function unq( $s ) {
		return str_replace( "''", "'", $s );
	}

	/**
	 * One address-point request (the params arcgis_query() would post) -> the closed question the mirror reads, or
	 * null when it isn't one of Doorhangers' own shapes. The where clause is read with the address source's own field names.
	 */
	public static function points_query( $params ) {
		if ( ! is_array( $params ) ) {
			return null;
		}
		// only the parameters Doorhangers' own questions use; a new one (paging, ordering, statistics) would change
		// the answer, so it goes to the layer
		$known = array( 'where', 'outFields', 'returnGeometry', 'resultRecordCount', 'returnCountOnly', 'geometry',
			'geometryType', 'inSR', 'spatialRel', 'f', 'outSR' );
		if ( array_diff( array_keys( $params ), $known ) ) {
			return null;
		}
		if ( isset( $params['spatialRel'] ) && 'esriSpatialRelIntersects' !== $params['spatialRel'] ) {
			return null;
		}
		if ( ( isset( $params['outSR'] ) && '4326' !== (string) $params['outSR'] ) || ( isset( $params['f'] ) && 'json' !== $params['f'] ) ) {
			return null;
		}
		$q = array();
		if ( isset( $params['resultRecordCount'] ) && is_numeric( $params['resultRecordCount'] ) ) {
			$q['limit'] = max( 1, min( 2000, (int) $params['resultRecordCount'] ) );
		}
		if ( ! empty( $params['returnCountOnly'] ) && 'false' !== (string) $params['returnCountOnly'] ) {
			$q['count_only'] = true;
		}
		if ( isset( $params['geometry'] ) ) {
			if ( ( $params['geometryType'] ?? '' ) !== 'esriGeometryEnvelope' || ( $params['inSR'] ?? '4326' ) !== '4326' ) {
				return null;
			}
			$g = is_string( $params['geometry'] ) ? json_decode( $params['geometry'], true ) : $params['geometry'];
			if ( ! is_array( $g ) ) {
				return null;
			}
			foreach ( array( 'xmin', 'ymin', 'xmax', 'ymax' ) as $k ) {
				if ( ! isset( $g[ $k ] ) || ! is_numeric( $g[ $k ] ) ) {
					return null;
				}
			}
			if ( isset( $params['where'] ) && '' !== trim( (string) $params['where'] ) && '1=1' !== trim( (string) $params['where'] ) ) {
				return null;
			}
			$q['envelope'] = array( (float) $g['xmin'], (float) $g['ymin'], (float) $g['xmax'], (float) $g['ymax'] );
			return $q;
		}
		$w    = trim( (string) ( $params['where'] ?? '' ) );
		$s    = self::STR;
		$num  = preg_quote( ZDH_Geo::f( 'addresses', 'number' ), '/' );
		$name = preg_quote( ZDH_Geo::f( 'addresses', 'name' ), '/' );
		$zip  = preg_quote( ZDH_Geo::f( 'addresses', 'zip' ), '/' );
		if ( preg_match( "/^$num = (\d+) AND $name = $s(?: AND $zip = $s)?$/", $w, $m ) ) {
			$q['num']  = (int) $m[1];
			$q['name'] = self::unq( $m[2] );
			if ( isset( $m[3] ) && '' !== $m[3] ) { $q['zip'] = self::unq( $m[3] ); }
			return $q;
		}
		if ( preg_match( "/^$num = (\d+) AND $zip = $s$/", $w, $m ) ) {
			$q['num'] = (int) $m[1];
			$q['zip'] = self::unq( $m[2] );
			return $q;
		}
		if ( preg_match( "/^$name = $s(?: AND $zip = $s)?$/", $w, $m ) ) {
			$q['name'] = self::unq( $m[1] );
			if ( isset( $m[2] ) && '' !== $m[2] ) { $q['zip'] = self::unq( $m[2] ); }
			return $q;
		}
		if ( preg_match( "/^$name LIKE '%((?:[^'%]|'')*)%'(?: AND $zip = $s)?$/", $w, $m ) ) {
			$q['name'] = self::unq( $m[1] );
			$q['like'] = true;
			if ( isset( $m[2] ) && '' !== $m[2] ) { $q['zip'] = self::unq( $m[2] ); }
			return $q;
		}
		if ( preg_match( "/^$zip = $s$/", $w, $m ) ) {
			$q['zip'] = self::unq( $m[1] );
			return $q;
		}
		return null;
	}

	/** The mirror's answer to an arcgis_query() call, in the layer's reply shape; null = ask the layer. */
	public static function answer( $layer, $params ) {
		if ( ! has_filter( 'zdh_local_lookup' ) ) {
			return null;
		}
		if ( 'addresses' !== $layer ) {              // postal outlines, road lines, hazard zones: the layer's own
			return null;
		}
		$q = self::points_query( $params );
		if ( null === $q ) {
			return null;
		}
		$r = apply_filters( 'zdh_local_lookup', null, 'address_points', $q, get_current_user_id() );
		if ( ! is_array( $r ) ) {
			self::$misses++;
			return null;
		}
		if ( ! empty( $r['exceeded'] ) ) {
			// more than one reply holds: which rows the layer sends follows its own row ids, so a cut answer is the
			// layer's to give
			self::$misses++;
			return null;
		}
		self::$hits++;
		if ( array_key_exists( 'count', $r ) ) {
			return array( 'count' => (int) $r['count'], 'zdh_source' => 'local' );
		}
		return array( 'features' => array_values( (array) ( $r['features'] ?? array() ) ), 'zdh_source' => 'local' );
	}

	/** What a place is like, from the mirror: ZIP, town, the ZIP's figures, past fire, the coast. Null when off. */
	public static function place( $lat, $lng ) {
		if ( ! has_filter( 'zdh_local_lookup' ) || ! is_numeric( $lat ) || ! is_numeric( $lng ) ) {
			return null;
		}
		$p = apply_filters( 'zdh_local_lookup', null, 'place', array( 'lat' => (float) $lat, 'lng' => (float) $lng ), get_current_user_id() );
		return is_array( $p ) ? $p : null;
	}
}
