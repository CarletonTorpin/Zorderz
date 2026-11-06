<?php
/**
 * Geo layer: exact house addresses and street segments for an area or a list of streets.
 *
 *   Regional — an ArcGIS feature service the business names (`zdh_address_source`, territories.yml
 *              `canvassing.address_source`): an address-point layer, a road-centerline layer with per-side address
 *              ranges, and a postal-area layer. Each layer's field names are mapped onto the canonical names below,
 *              so the rest of the app reads one shape whatever the region publishes. Core names no region: with
 *              nothing configured, the regional source is off.
 *   Open map — OpenStreetMap (Nominatim + Overpass) when no regional layer answers, unless the business turned it off.
 *
 * Canonical attribute names (what every feature's attributes are translated to on the way in):
 *   addresses  number, fraction, pre_dir, name, post_dir, suffix, unit, zip, community, jurisdiction, segment, parcel, kind
 *   roads      segment, full, pre_dir, name, suffix, post_dir, left_low, left_high, right_low, right_high, from_node,
 *              to_node, road_class
 *   zips       zip
 * A source whose fields already carry these names needs no field map.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZDH_Geo {

	const SOURCE_OPTION = 'zdh_address_source';
	const HAZARD_OPTION = 'zdh_hazard_layers';
	const NOMINATIM     = 'https://nominatim.openstreetmap.org/search';
	const OVERPASS      = 'https://overpass-api.de/api/interpreter';
	const UA            = 'Zorderz-Doorhangers/1.0 (WordPress)';

	const LAYERS = array(
		'addresses' => array( 'number', 'fraction', 'pre_dir', 'name', 'post_dir', 'suffix', 'unit', 'zip', 'community', 'jurisdiction', 'segment', 'parcel', 'kind' ),
		'roads'     => array( 'segment', 'full', 'pre_dir', 'name', 'suffix', 'post_dir', 'left_low', 'left_high', 'right_low', 'right_high', 'from_node', 'to_node', 'road_class' ),
		'zips'      => array( 'zip' ),
	);

	/* ---------------------------------------------- the address source */

	/** The shape of the regional source, empty: no region of the platform's own. */
	public static function source_defaults() {
		$fields = array();
		foreach ( self::LAYERS as $layer => $keys ) {
			$fields[ $layer ] = array_combine( $keys, $keys );
		}
		return array(
			'label'  => '',
			'base'   => '',
			'path'   => 'FeatureServer/0/query',
			'layers' => array( 'addresses' => '', 'roads' => '', 'zips' => '' ),
			'fields' => $fields,
			'bbox'   => array(),   // [xmin, ymin, xmax, ymax]: the whole region, for a lookup with nothing narrower
		);
	}

	/** The regional source as configured (option, then the `zdh_address_source` filter), over the empty defaults. */
	public static function source() {
		$d   = self::source_defaults();
		$raw = function_exists( 'get_option' ) ? get_option( self::SOURCE_OPTION, array() ) : array();
		$raw = is_array( $raw ) ? $raw : array();
		if ( function_exists( 'apply_filters' ) ) {
			$f   = apply_filters( 'zdh_address_source', $raw );
			$raw = is_array( $f ) ? $f : $raw;
		}
		return self::clean_source( $raw, $d );
	}

	/** Validate a source array: https base, plain layer and field names, a numeric box. Unknown keys are dropped. */
	public static function clean_source( array $raw, array $d = array() ) {
		$d   = $d ? $d : self::source_defaults();
		$out = $d;
		$out['label'] = isset( $raw['label'] ) ? mb_substr( trim( wp_strip_all_tags( (string) $raw['label'] ) ), 0, 80 ) : '';
		$base         = isset( $raw['base'] ) ? trim( (string) $raw['base'] ) : '';
		if ( '' !== $base && preg_match( '#^https://[A-Za-z0-9.\-]+(?::\d+)?/[A-Za-z0-9_./\-]*$#', $base ) ) {
			$out['base'] = rtrim( $base, '/' ) . '/';
		}
		if ( isset( $raw['path'] ) && preg_match( '#^[A-Za-z0-9_][A-Za-z0-9_./\-]{0,79}$#', trim( (string) $raw['path'] ) ) && false === strpos( (string) $raw['path'], '..' ) ) {
			$out['path'] = trim( (string) $raw['path'] );
		}
		foreach ( array_keys( $d['layers'] ) as $layer ) {
			$v = isset( $raw['layers'][ $layer ] ) ? trim( (string) $raw['layers'][ $layer ] ) : '';
			$out['layers'][ $layer ] = preg_match( '/^[A-Za-z0-9_.\-]{1,80}$/', $v ) && false === strpos( $v, '..' ) ? $v : '';
		}
		foreach ( self::LAYERS as $layer => $keys ) {
			foreach ( $keys as $k ) {
				$v = isset( $raw['fields'][ $layer ][ $k ] ) ? trim( (string) $raw['fields'][ $layer ][ $k ] ) : '';
				if ( preg_match( '/^[A-Za-z_][A-Za-z0-9_]{0,40}$/', $v ) ) {
					$out['fields'][ $layer ][ $k ] = $v;
				}
			}
		}
		$out['bbox'] = array();
		if ( isset( $raw['bbox'] ) && is_array( $raw['bbox'] ) && 4 === count( $raw['bbox'] ) ) {
			$b = array_map( 'floatval', array_values( $raw['bbox'] ) );
			if ( $b[2] > $b[0] && $b[3] > $b[1] && abs( $b[1] ) <= 90 && abs( $b[3] ) <= 90 && abs( $b[0] ) <= 180 && abs( $b[2] ) <= 180 ) {
				$out['bbox'] = $b;
			}
		}
		return $out;
	}

	/** register_setting's sanitizer for the settings page's JSON box: a bad JSON keeps what was stored. */
	public static function sanitize_source_input( $in ) {
		$cur = get_option( self::SOURCE_OPTION, array() );
		$cur = is_array( $cur ) ? $cur : array();
		if ( ! is_array( $in ) ) {
			return $cur;
		}
		if ( ! array_key_exists( 'json', $in ) ) {
			return self::clean_source( $in ); // a pack or a script writing the array itself
		}
		$text = trim( (string) wp_unslash( $in['json'] ) );
		if ( '' === $text ) {
			return array();
		}
		$j = json_decode( $text, true );
		if ( ! is_array( $j ) ) {
			if ( function_exists( 'add_settings_error' ) ) {
				add_settings_error( self::SOURCE_OPTION, 'zdh_bad_json', __( 'The address source is not valid JSON; the stored one was kept.', 'zorderz' ) );
			}
			return $cur;
		}
		return self::clean_source( $j );
	}

	/** Is a regional address layer set? */
	public static function configured() {
		$s = self::source();
		return '' !== $s['base'] && '' !== $s['layers']['addresses'];
	}

	/** Words for the screens: where the houses come from now. */
	public static function source_label() {
		$s = self::source();
		if ( self::configured() ) {
			return '' !== $s['label'] ? $s['label'] : __( 'the regional address layer', 'zorderz' );
		}
		return self::osm_on() ? __( 'the open street map', 'zorderz' ) : __( 'no address source', 'zorderz' );
	}

	/**
	 * May a route be planned from houses of this source? The regional layer's, always; the open map's only when no
	 * regional layer is set (where one is, an area it does not cover is not planned from the open map's guesses).
	 */
	public static function plannable( $source ) {
		if ( 'region' === $source || 'local' === $source ) {
			return true;
		}
		return 'osm' === $source && ! self::configured();
	}

	/** May the open street map be asked? */
	public static function osm_on() {
		if ( ! function_exists( 'zdh_get_settings' ) ) {
			return true;
		}
		$s = zdh_get_settings();
		return ! empty( $s['osm_fallback'] );
	}

	/** The provider's field name for a canonical one. */
	public static function f( $layer, $canon ) {
		$s = self::source();
		return (string) ( $s['fields'][ $layer ][ $canon ] ?? $canon );
	}

	/** The comma list of provider fields for a layer (outFields). */
	public static function out_fields( $layer, array $canon = array() ) {
		$canon = $canon ? $canon : self::LAYERS[ $layer ];
		return implode( ',', array_map( function ( $k ) use ( $layer ) { return ZDH_Geo::f( $layer, $k ); }, $canon ) );
	}

	/** Provider attributes -> canonical attributes, for every feature of a reply. */
	public static function canon( $layer, $json ) {
		if ( ! is_array( $json ) || empty( $json['features'] ) || ! isset( self::LAYERS[ $layer ] ) ) {
			return $json;
		}
		$s   = self::source();
		$map = array();
		foreach ( self::LAYERS[ $layer ] as $k ) {
			$map[ strtolower( (string) ( $s['fields'][ $layer ][ $k ] ?? $k ) ) ] = $k;
		}
		foreach ( $json['features'] as $i => $f ) {
			$at  = isset( $f['attributes'] ) && is_array( $f['attributes'] ) ? $f['attributes'] : array();
			$out = array();
			foreach ( $at as $name => $v ) {
				$lk = strtolower( (string) $name );
				if ( isset( $map[ $lk ] ) ) {
					$out[ $map[ $lk ] ] = $v;
				}
			}
			$json['features'][ $i ]['attributes'] = $out;
		}
		return $json;
	}

	/* ------------------------------------------------------- street names */

	/**
	 * "N Main Ave" -> [pdir=>'N', core=>'MAIN', sfx=>'AVE', postd=>'']
	 * "Via Example" -> [pdir=>'',  core=>'VIA EXAMPLE', sfx=>'', postd=>'']
	 */
	public static function parse_street( $name ) {
		$s = strtoupper( trim( (string) $name ) );
		$s = str_replace( array( '.', ',' ), ' ', $s );
		$s = preg_replace( '/[^A-Z0-9\' \-]/', ' ', $s );
		$s = preg_replace( '/\s+/', ' ', trim( $s ) );
		$parts = $s === '' ? array() : explode( ' ', $s );
		$sfx_map = array_change_key_case( ZDH_DB::suffix_map(), CASE_UPPER );
		$dir_map = array_change_key_case( ZDH_DB::dir_map(), CASE_UPPER );
		$out = array( 'pdir' => '', 'core' => '', 'sfx' => '', 'postd' => '', 'display' => trim( (string) $name ) );
		if ( count( $parts ) > 1 && isset( $dir_map[ $parts[0] ] ) ) {
			$out['pdir'] = strtoupper( $dir_map[ array_shift( $parts ) ] );
		}
		if ( count( $parts ) > 2 && isset( $dir_map[ end( $parts ) ] ) ) {
			$out['postd'] = strtoupper( $dir_map[ array_pop( $parts ) ] );
		}
		if ( count( $parts ) > 1 && isset( $sfx_map[ end( $parts ) ] ) ) {
			$out['sfx'] = strtoupper( $sfx_map[ array_pop( $parts ) ] );
		}
		$out['core'] = implode( ' ', $parts );
		return $out;
	}

	/** Pretty display form from the layer's pieces: "OAK HOLLOW" + "DR" -> "Oak Hollow Dr". */
	public static function display_name( $pdir, $core, $sfx, $postd = '' ) {
		$words = array_filter( array( $pdir, $core, $sfx, $postd ), function ( $w ) { return $w !== null && trim( (string) $w ) !== ''; } );
		$str   = strtolower( implode( ' ', $words ) );
		$str   = ucwords( $str, " -'" );
		// keep directionals as uppercase letters (N, S, E, W, NE …)
		$str   = preg_replace_callback( '/\b(n|s|e|w|ne|nw|se|sw)\b/i', function ( $m ) { return strtoupper( $m[1] ); }, $str );
		return $str;
	}

	/** Escape a value for an ArcGIS SQL where clause (single-quoted string). */
	public static function sql_str( $v ) {
		return "'" . str_replace( "'", "''", (string) $v ) . "'";
	}

	/* ----------------------------------------------------------- HTTP */

	/**
	 * One question to the regional source. $layer is 'addresses', 'roads' or 'zips' (resolved against the configured
	 * source), or a full https URL of another feature service (a hazard layer). The reply comes back with canonical
	 * attribute names for the three regional layers. An address-point question goes to the local mirror first
	 * (ZDH_Local) when one answers.
	 */
	public static function arcgis_query( $layer, $params, $timeout = 25, $local = true ) {
		$kind = isset( self::LAYERS[ $layer ] ) ? $layer : '';
		if ( 'addresses' === $kind && $local && class_exists( 'ZDH_Local' ) ) {
			$mine = ZDH_Local::answer( $kind, $params );
			if ( null !== $mine ) {
				return self::canon( $kind, $mine );
			}
		}
		if ( '' !== $kind ) {
			$s = self::source();
			if ( '' === $s['base'] || '' === $s['layers'][ $kind ] ) {
				return new WP_Error( 'zdh_no_source', __( 'No regional address layer is set.', 'zorderz' ) );
			}
			$url = $s['base'] . $s['layers'][ $kind ] . '/' . ltrim( $s['path'], '/' );
		} elseif ( is_string( $layer ) && 0 === strpos( $layer, 'https://' ) ) {
			$url = $layer;
		} else {
			return new WP_Error( 'zdh_bad_layer', __( 'Unknown map layer.', 'zorderz' ) );
		}
		$defaults = array( 'f' => 'json', 'outSR' => '4326' );
		$args     = array(
			'timeout' => $timeout,
			'headers' => array( 'User-Agent' => self::UA ),
			'body'    => array_merge( $defaults, $params ),
		);
		// A configured layer through the safe call: no private address, at most two redirects, each checked again.
		$resp = class_exists( 'ZDZ_Outbound' )
			? ZDZ_Outbound::send( 'POST', $url, $args, false, 2 )
			: wp_remote_post( $url, $args );
		if ( is_wp_error( $resp ) ) {
			return new WP_Error( 'zdh_gis_http', sprintf( __( 'The map layer could not be reached: %s', 'zorderz' ), $resp->get_error_message() ) );
		}
		$code = wp_remote_retrieve_response_code( $resp );
		$json = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( 200 !== $code || ! is_array( $json ) ) {
			return new WP_Error( 'zdh_gis_bad', sprintf( __( 'The map layer returned HTTP %d.', 'zorderz' ), (int) $code ) );
		}
		if ( isset( $json['error'] ) ) {
			return new WP_Error( 'zdh_gis_err', sprintf( __( 'The map layer said: %s', 'zorderz' ), (string) ( $json['error']['message'] ?? 'unknown' ) ) );
		}
		return '' !== $kind ? self::canon( $kind, $json ) : $json;
	}

	/* --------------------------------------------------------- regional */

	/** Where clause pieces in the provider's own field names. */
	private static function w_name( $core ) {
		return self::f( 'addresses', 'name' ) . ' = ' . self::sql_str( $core );
	}
	private static function w_like( $core ) {
		return self::f( 'addresses', 'name' ) . ' LIKE ' . self::sql_str( '%' . $core . '%' );
	}
	private static function w_zip( $zip ) {
		return self::f( 'addresses', 'zip' ) . ' = ' . self::sql_str( $zip );
	}
	private static function w_num( $num ) {
		return self::f( 'addresses', 'number' ) . ' = ' . (int) $num;
	}

	/**
	 * Fetch address points for one street. Returns [ 'addresses' => [...], 'note' => string, 'zip' => chosen zip ].
	 * $ctx: [ 'zip' => '00000', 'city' => 'Exampleton', 'anchor' => [lat,lng]|null ]
	 */
	public static function addresses_for_street( $street, $ctx ) {
		$p    = self::parse_street( $street );
		$note = '';
		if ( $p['core'] === '' ) {
			return array( 'addresses' => array(), 'note' => 'empty street name', 'zip' => '' );
		}
		$zip = isset( $ctx['zip'] ) ? preg_replace( '/[^0-9]/', '', (string) $ctx['zip'] ) : '';

		$attempts = array();
		if ( $zip ) {
			$attempts[] = array( 'where' => self::w_name( $p['core'] ) . ' AND ' . self::w_zip( $zip ), 'how' => 'exact+zip' );
		}
		$attempts[] = array( 'where' => self::w_name( $p['core'] ), 'how' => 'exact' );
		if ( $zip ) {
			$attempts[] = array( 'where' => self::w_like( $p['core'] ) . ' AND ' . self::w_zip( $zip ), 'how' => 'like+zip' );
		}
		$attempts[] = array( 'where' => self::w_like( $p['core'] ), 'how' => 'like' );

		$features = array();
		$how      = '';
		foreach ( $attempts as $a ) {
			$json = self::arcgis_query( 'addresses', array(
				'where'             => $a['where'],
				'outFields'         => self::out_fields( 'addresses' ),
				'returnGeometry'    => 'true',
				'resultRecordCount' => '2000',
			) );
			if ( is_wp_error( $json ) ) {
				return $json;
			}
			$feat = isset( $json['features'] ) ? $json['features'] : array();
			$feat = self::filter_by_suffix( $feat, $p );
			if ( $feat ) {
				$features = $feat;
				$how      = $a['how'];
				break;
			}
		}
		if ( ! $features ) {
			return array( 'addresses' => array(), 'note' => 'no address points found', 'zip' => $zip );
		}

		// Choose a ZIP cluster when the query was not ZIP-constrained.
		if ( 'exact' === $how || 'like' === $how ) {
			$groups = array();
			foreach ( $features as $f ) {
				$z = (string) ( $f['attributes']['zip'] ?? '' );
				$groups[ $z ][] = $f;
			}
			if ( count( $groups ) > 1 ) {
				$best = self::pick_group( $groups, $ctx );
				$features = $groups[ $best ];
				$note     = 'ZIP ' . ( $zip ?: '(none given)' ) . ' had no match; used ' . $best;
			} elseif ( $zip ) {
				$note = 'ZIP ' . $zip . ' had no match; used ' . array_key_first( $groups );
			}
		}
		if ( 'like' === $how || 'like+zip' === $how ) {
			// keep only the most common full street name among the fuzzy matches
			$names = array();
			foreach ( $features as $f ) {
				$names[ self::full_from_attrs( $f['attributes'] ) ][] = $f;
			}
			uasort( $names, function ( $a, $b ) { return count( $b ) - count( $a ); } );
			$features = reset( $names );
			$note    .= ( $note ? '; ' : '' ) . 'fuzzy-matched as ' . array_key_first( $names );
		}

		$addresses = self::addresses_from_features( $features );
		$zip_used = $addresses ? $addresses[0]['zip'] : $zip;
		return array( 'addresses' => $addresses, 'note' => $note, 'zip' => $zip_used, 'how' => $how );
	}

	/**
	 * Where is one house? The regional layer by number + street (+ ZIP), cached a week; else the platform's forward
	 * geocoder (ZDZ_Geocoder), whose coordinates are marked 'private' => true: the caller keeps them on the server.
	 * Returns array( lat, lng, label, zip[, private] ) or null.
	 */
	public static function geocode_one( $number, $street, $zip = '' ) {
		$num = (int) preg_replace( '/[^0-9]/', '', (string) $number );
		$p   = self::parse_street( $street );
		if ( $num <= 0 || $p['core'] === '' ) {
			return null;
		}
		$zip = preg_replace( '/[^0-9]/', '', (string) $zip );
		if ( self::configured() ) {
			$tkey = 'zdh_geo1_' . md5( $num . '|' . $p['core'] . '|' . $p['sfx'] . '|' . $p['pdir'] . '|' . $zip );
			$hit  = get_transient( $tkey );
			if ( is_array( $hit ) ) {
				return $hit['lat'] === null ? null : $hit;
			}
			$where = array();
			if ( $zip ) {
				$where[] = self::w_num( $num ) . ' AND ' . self::w_name( $p['core'] ) . ' AND ' . self::w_zip( $zip );
			}
			$where[] = self::w_num( $num ) . ' AND ' . self::w_name( $p['core'] );
			$found   = null;
			foreach ( $where as $w ) {
				$json = self::arcgis_query( 'addresses', array( 'where' => $w, 'outFields' => self::out_fields( 'addresses' ), 'returnGeometry' => 'true', 'resultRecordCount' => '50' ), 12 );
				if ( is_wp_error( $json ) ) {
					return null; // the layer is down: not cached, try again next time
				}
				$feat = self::filter_by_suffix( $json['features'] ?? array(), $p );
				$adds = self::addresses_from_features( $feat );
				$adds = array_values( array_filter( $adds, function ( $a ) { return $a['lat'] !== null; } ) );
				if ( $adds ) {
					$zips = array_unique( array_map( function ( $a ) { return $a['zip']; }, $adds ) );
					if ( count( $zips ) > 1 && ! $zip ) {
						break; // the same number on the same street name in two towns: can't tell which one is meant
					}
					$a     = $adds[0];
					$found = array( 'lat' => (float) $a['lat'], 'lng' => (float) $a['lng'], 'label' => $a['number'] . ' ' . $a['street'] . ( $a['city'] ? ', ' . $a['city'] : '' ) . ' ' . $a['zip'], 'zip' => $a['zip'] );
					break;
				}
			}
			set_transient( $tkey, $found ? $found : array( 'lat' => null ), $found ? WEEK_IN_SECONDS : DAY_IN_SECONDS );
			if ( $found ) {
				return $found;
			}
		}
		if ( class_exists( 'ZDZ_Geocoder' ) && method_exists( 'ZDZ_Geocoder', 'resolve_address' ) ) {
			$area = function_exists( 'zdh_area_defaults' ) ? zdh_area_defaults() : array( 'city' => '', 'state' => '' );
			$r    = ZDZ_Geocoder::resolve_address( array( 'street' => $num . ' ' . $street, 'city' => $area['city'], 'state' => $area['state'], 'zip' => $zip ) );
			if ( is_array( $r ) && isset( $r['lat'], $r['lng'] ) && is_numeric( $r['lat'] ) && is_numeric( $r['lng'] ) ) {
				return array( 'lat' => (float) $r['lat'], 'lng' => (float) $r['lng'], 'label' => trim( $num . ' ' . $street . ' ' . $zip ), 'zip' => $zip, 'private' => true );
			}
		}
		return null;
	}

	/**
	 * The region's streets that have this house number in this ZIP -> the one the typed (misspelled) street means.
	 * Returns [street, how, source] / [ambiguous => [...]] / null. Cached a week per number+ZIP.
	 */
	public static function fuzzy_region_street( $number, $street, $zip ) {
		$num = (int) preg_replace( '/[^0-9]/', '', (string) $number );
		$zip = preg_replace( '/[^0-9]/', '', (string) $zip );
		if ( $num <= 0 || strlen( $zip ) !== 5 || ! self::configured() ) {
			return null;
		}
		$tkey  = 'zdh_geonz_' . $num . '_' . $zip;
		$names = get_transient( $tkey );
		if ( ! is_array( $names ) ) {
			$json = self::arcgis_query( 'addresses', array( 'where' => self::w_num( $num ) . ' AND ' . self::w_zip( $zip ), 'outFields' => self::out_fields( 'addresses', array( 'pre_dir', 'name', 'suffix', 'post_dir' ) ), 'returnGeometry' => 'false', 'resultRecordCount' => '500' ), 12 );
			if ( is_wp_error( $json ) ) {
				return null;
			}
			$names = array();
			foreach ( (array) ( $json['features'] ?? array() ) as $f ) {
				$at = $f['attributes'];
				$d  = self::display_name( $at['pre_dir'] ?? '', $at['name'] ?? '', $at['suffix'] ?? '', $at['post_dir'] ?? '' );
				$names[ ZDH_DB::street_key( $d ) ] = $d;
			}
			set_transient( $tkey, $names, WEEK_IN_SECONDS );
		}
		$typed = ZDH_DB::street_key( $street );
		if ( isset( $names[ $typed ] ) ) {
			return null; // the address exists as typed
		}
		$pick = ZDH_DB::fuzzy_pick( $typed, array_keys( $names ) );
		if ( ! $pick ) {
			return null;
		}
		if ( isset( $pick['ambiguous'] ) ) {
			return array( 'ambiguous' => array_map( function ( $k ) use ( $num, $names ) { return $num . ' ' . $names[ $k ]; }, $pick['ambiguous'] ) );
		}
		$how = array( 0 => 'same name written differently', 1 => 'one letter off', 2 => 'shortened name', 3 => 'two letters off', 4 => 'sounds the same' )[ (int) floor( $pick['score'] ) ] ?? 'close spelling';
		return array( 'street' => $names[ $pick['key'] ], 'how' => $how, 'source' => 'region' );
	}

	/** Normalize address-point features (canonical attributes) into the app's address shape (deduplicated). */
	public static function addresses_from_features( $features ) {
		$addresses = array();
		$seen      = array();
		foreach ( (array) $features as $f ) {
			$at  = isset( $f['attributes'] ) && is_array( $f['attributes'] ) ? $f['attributes'] : array();
			$num = isset( $at['number'] ) && $at['number'] !== null ? (string) (int) $at['number'] : '';
			if ( $num === '' || $num === '0' ) {
				continue;
			}
			if ( ! empty( $at['fraction'] ) ) {
				$num .= ' ' . trim( (string) $at['fraction'] );
			}
			$unit  = isset( $at['unit'] ) ? trim( (string) $at['unit'] ) : '';
			$disp  = self::display_name( $at['pre_dir'] ?? '', $at['name'] ?? '', $at['suffix'] ?? '', $at['post_dir'] ?? '' );
			$key   = $num . '|' . $unit . '|' . $disp;
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$addresses[]  = array(
				'number'     => $num,
				'unit'       => $unit,
				'street'     => $disp,
				'street_key' => ZDH_DB::street_key( $disp ),
				'city'       => isset( $at['community'] ) ? ucwords( strtolower( trim( (string) $at['community'] ) ) ) : '',
				'zip'        => (string) ( $at['zip'] ?? '' ),
				'lat'        => isset( $f['geometry']['y'] ) ? round( (float) $f['geometry']['y'], 7 ) : null,
				'lng'        => isset( $f['geometry']['x'] ) ? round( (float) $f['geometry']['x'], 7 ) : null,
				'seg'        => isset( $at['segment'] ) ? (int) $at['segment'] : null,
				'apn'        => isset( $at['parcel'] ) ? (string) $at['parcel'] : '',
				'type'       => isset( $at['kind'] ) ? (string) $at['kind'] : '',
				'jur'        => isset( $at['jurisdiction'] ) ? (string) $at['jurisdiction'] : '',
				'source'     => 'region',
			);
		}
		return $addresses;
	}

	/** An envelope as the ArcGIS geometry parameter. */
	private static function env_geometry( $env ) {
		return wp_json_encode( array( 'xmin' => $env['xmin'], 'ymin' => $env['ymin'], 'xmax' => $env['xmax'], 'ymax' => $env['ymax'], 'spatialReference' => array( 'wkid' => 4326 ) ) );
	}

	/** Every address point inside an envelope (lng/lat). */
	public static function addresses_in_envelope( $env ) {
		$json = self::arcgis_query( 'addresses', array(
			'geometry'          => self::env_geometry( $env ),
			'geometryType'      => 'esriGeometryEnvelope',
			'inSR'              => '4326',
			'spatialRel'        => 'esriSpatialRelIntersects',
			'outFields'         => self::out_fields( 'addresses' ),
			'returnGeometry'    => 'true',
			'resultRecordCount' => '2000',
		), 30 );
		if ( is_wp_error( $json ) ) {
			return $json;
		}
		return self::addresses_from_features( $json['features'] ?? array() );
	}

	/** Road segments of every street inside an envelope (for the map and intersection logic). */
	public static function segments_in_envelope( $env ) {
		return self::segments( null, $env );
	}

	/** Point-in-polygon (ray casting); $poly is a list of [lat,lng]. */
	public static function point_in_poly( $lat, $lng, $poly ) {
		$inside = false;
		$n      = count( $poly );
		for ( $i = 0, $j = $n - 1; $i < $n; $j = $i++ ) {
			$yi = $poly[ $i ][0]; $xi = $poly[ $i ][1]; $yj = $poly[ $j ][0]; $xj = $poly[ $j ][1];
			if ( ( $yi > $lat ) !== ( $yj > $lat ) && $lng < ( $xj - $xi ) * ( $lat - $yi ) / ( $yj - $yi ) + $xi ) {
				$inside = ! $inside;
			}
		}
		return $inside;
	}

	/** Distance (m) from a point to a polyline of [lat,lng] points. */
	public static function dist_to_path_m( $lat, $lng, $path ) {
		$best = INF;
		$cos  = cos( deg2rad( $lat ) );
		$n    = count( $path );
		if ( 1 === $n ) {
			return self::distance_m( $lat, $lng, $path[0][0], $path[0][1] );
		}
		for ( $i = 0; $i < $n - 1; $i++ ) {
			$a = $path[ $i ]; $b = $path[ $i + 1 ];
			$px = ( $lng - $a[1] ) * $cos * 111320; $py = ( $lat - $a[0] ) * 110540;
			$bx = ( $b[1] - $a[1] ) * $cos * 111320; $by = ( $b[0] - $a[0] ) * 110540;
			$l2 = $bx * $bx + $by * $by;
			$t  = $l2 ? max( 0, min( 1, ( $px * $bx + $py * $by ) / $l2 ) ) : 0;
			$dx = $px - $t * $bx; $dy = $py - $t * $by;
			$best = min( $best, sqrt( $dx * $dx + $dy * $dy ) );
		}
		return $best;
	}

	/**
	 * Houses inside hand-drawn shapes. $shapes: [ [ 'type' => 'loop'|'path', 'points' => [[lat,lng],...], 'buffer_m' => 30 ], ... ]
	 * With no shapes and an $envelope, returns everything in the envelope unselected (the caller decides).
	 * Returns addresses (with 'selected'), segments, streets (derived from the selected points), center, envelope.
	 */
	public static function find_in_area( $shapes, $envelope = null ) {
		/*
		 * A site may answer here with its own provider, in the same shapes: addresses {number, unit, street,
		 * street_key, city, zip, lat, lng, seg, apn} and segments {id, name, key, path, low, high, llow, lhigh, rlow,
		 * rhigh, fnode, tnode}; this method then steps aside.
		 */
		$pre = apply_filters( 'zdh_address_provider', null, $shapes, $envelope );
		if ( null !== $pre ) {
			return $pre;
		}
		$pts = array();
		foreach ( $shapes as $sh ) {
			foreach ( $sh['points'] as $p ) {
				$pts[] = array( 'lat' => $p[0], 'lng' => $p[1] );
			}
		}
		$env = $pts ? self::envelope( $pts, 0.0008 ) : $envelope; // ~90 m padding so houses just outside a stroke are still offered
		if ( ! $env ) {
			return new WP_Error( 'no_area', __( 'No area given.', 'zorderz' ) );
		}
		// keep the query area sane: ≈ 3 km for hand-drawn routes, ≈ 5 km for a whole screenshot
		$max_x = $pts ? 0.035 : 0.06;
		$max_y = $pts ? 0.03 : 0.05;
		if ( ( $env['xmax'] - $env['xmin'] ) > $max_x || ( $env['ymax'] - $env['ymin'] ) > $max_y ) {
			return new WP_Error( 'too_big', $pts ? __( 'That area is too large for one drop: zoom in and draw a smaller route.', 'zorderz' ) : __( 'The screenshot covers too large an area: zoom the map in before taking it.', 'zorderz' ) );
		}
		$source    = 'region';
		$addresses = array();
		$segments  = array();
		if ( self::configured() ) {
			$addresses = self::addresses_in_envelope( $env );
			if ( is_wp_error( $addresses ) ) {
				if ( ! self::osm_on() ) {
					return $addresses;
				}
				$addresses = array();
			}
			$segments = $addresses ? self::segments_in_envelope( $env ) : array();
			if ( is_wp_error( $segments ) ) {
				$segments = array();
			}
		}
		if ( ! $addresses && self::osm_on() ) {
			$osm = self::osm_in_envelope( $env );
			if ( is_wp_error( $osm ) ) {
				return $osm;
			}
			$source    = 'osm';
			$addresses = $osm['addresses'];
			$segments  = $osm['segments'];
		} elseif ( ! $addresses && ! self::configured() ) {
			return new WP_Error( 'zdh_no_source', __( 'No address source is set for this site (Zorderz → Doorhangers → House addresses).', 'zorderz' ) );
		}
		$spread = self::spread_stacked( $addresses, $segments );
		$n_sel = 0;
		foreach ( $addresses as &$a ) {
			$sel = false;
			if ( $a['lat'] !== null ) {
				foreach ( $shapes as $sh ) {
					if ( 'loop' === $sh['type'] && count( $sh['points'] ) >= 3 && self::point_in_poly( $a['lat'], $a['lng'], $sh['points'] ) ) {
						$sel = true; break;
					}
					$buf = isset( $sh['buffer_m'] ) ? (float) $sh['buffer_m'] : 30;
					if ( self::dist_to_path_m( $a['lat'], $a['lng'], $sh['points'] ) <= $buf ) {
						$sel = true; break;
					}
				}
			}
			$a['selected'] = $sel;
			if ( $sel ) { $n_sel++; }
		}
		unset( $a );
		$streets = self::streets_from_addresses( $addresses );
		$center  = null;
		$lat = 0; $lng = 0; $n = 0;
		foreach ( $addresses as $a ) {
			if ( $a['selected'] && $a['lat'] !== null ) { $lat += $a['lat']; $lng += $a['lng']; $n++; }
		}
		if ( $n ) {
			$center = array( round( $lat / $n, 6 ), round( $lng / $n, 6 ) );
		} elseif ( $pts ) {
			$center = array( round( ( $env['ymin'] + $env['ymax'] ) / 2, 6 ), round( ( $env['xmin'] + $env['xmax'] ) / 2, 6 ) );
		}
		$notes = array();
		if ( $addresses && $spread ) {
			$notes[] = sprintf( __( '%d addresses were placed along their street by house number: the map has that complex as a single point, so their positions are approximate.', 'zorderz' ), (int) $spread );
		} elseif ( ! $addresses ) {
			$notes[] = sprintf( __( 'No house addresses in this area from %s.', 'zorderz' ), self::source_label() );
		}
		return array(
			'source'    => $source,
			'addresses' => $addresses,
			'segments'  => $segments,
			'streets'   => $streets,
			'center'    => $center,
			'envelope'  => $env,
			'selected'  => $n_sel,
			'notes'     => $notes,
		);
	}

	/**
	 * Condo / PUD developments: an address layer often maps every unit of a complex to ONE site point (its entrance),
	 * with distinct house numbers and no unit label, so a whole street of duplexes shows up as a single stacked dot.
	 * Spread such stacks along their road segment by house number (address-range interpolation, offset to the side
	 * the segment's left/right ranges say), so the houses appear along the street they are actually on.
	 * Returns the number of addresses moved; moved ones get confidence 'estimated' and placed 'range'.
	 */
	public static function spread_stacked( &$addresses, $segments ) {
		if ( ! $addresses || ! $segments ) {
			return 0;
		}
		$seg_by_id = array();
		$seg_by_key = array();
		foreach ( $segments as $sg ) {
			if ( empty( $sg['path'] ) || count( $sg['path'] ) < 2 ) { continue; }
			$seg_by_id[ (int) $sg['id'] ] = $sg;
			$seg_by_key[ $sg['key'] ][] = $sg;
		}
		// stacks: the same coordinates shared by several addresses with distinct numbers
		$groups = array();
		foreach ( $addresses as $i => $a ) {
			if ( $a['lat'] === null ) { continue; }
			$groups[ round( $a['lat'], 6 ) . ',' . round( $a['lng'], 6 ) ][] = $i;
		}
		$moved = 0;
		foreach ( $groups as $idx ) {
			$nums = array(); $segs = array();
			foreach ( $idx as $i ) {
				if ( '' === $addresses[ $i ]['unit'] ) { $nums[ (int) $addresses[ $i ]['number'] ] = true; }
				if ( ! empty( $addresses[ $i ]['seg'] ) ) { $segs[ (int) $addresses[ $i ]['seg'] ] = true; }
			}
			if ( count( $nums ) < 4 && ! ( count( $nums ) >= 3 && count( $segs ) >= 2 ) ) {
				continue; // a duplex/triplex genuinely shares a point: leave it
			}
			foreach ( $idx as $i ) {
				$a = $addresses[ $i ];
				if ( '' !== $a['unit'] ) { continue; } // true apartment units stay at the building
				$num = (int) $a['number'];
				if ( $num <= 0 ) { continue; }
				$sg = null;
				if ( ! empty( $a['seg'] ) && isset( $seg_by_id[ (int) $a['seg'] ] ) && self::seg_has_number( $seg_by_id[ (int) $a['seg'] ], $num ) ) {
					$sg = $seg_by_id[ (int) $a['seg'] ];
				} else {
					foreach ( (array) ( $seg_by_key[ $a['street_key'] ] ?? array() ) as $cand ) {
						if ( self::seg_has_number( $cand, $num ) ) { $sg = $cand; break; }
					}
				}
				if ( ! $sg ) { continue; }
				$pos = self::place_on_segment( $sg, $num );
				if ( ! $pos ) { continue; }
				$addresses[ $i ]['lat']        = $pos[0];
				$addresses[ $i ]['lng']        = $pos[1];
				$addresses[ $i ]['seg']        = (int) $sg['id'];
				$addresses[ $i ]['confidence'] = 'estimated';
				$addresses[ $i ]['placed']     = 'range';
				$moved++;
			}
		}
		return $moved;
	}
	private static function seg_has_number( $sg, $num ) {
		return null !== $sg['low'] && null !== $sg['high'] && $num >= $sg['low'] && $num <= $sg['high'];
	}
	/** Point ~12 m to the correct side of the segment, at the fraction of its length the number sits at within the range. */
	private static function place_on_segment( $sg, $num ) {
		$path = $sg['path'];
		$span = max( 1, (int) $sg['high'] - (int) $sg['low'] );
		$t    = ( $num - (int) $sg['low'] ) / $span;
		$t    = max( 0.06, min( 0.94, $t ) ); // never on the corner
		// which side: the range the number belongs to (parity), left/right in the fnode→tnode direction
		$side = 0;
		if ( null !== ( $sg['llow'] ?? null ) && null !== ( $sg['lhigh'] ?? null ) && $num >= $sg['llow'] && $num <= $sg['lhigh'] && ( $num % 2 ) === ( $sg['llow'] % 2 ) ) { $side = 1; }
		elseif ( null !== ( $sg['rlow'] ?? null ) && null !== ( $sg['rhigh'] ?? null ) && $num >= $sg['rlow'] && $num <= $sg['rhigh'] && ( $num % 2 ) === ( $sg['rlow'] % 2 ) ) { $side = -1; }
		elseif ( null !== ( $sg['llow'] ?? null ) ) { $side = ( $num % 2 ) === ( $sg['llow'] % 2 ) ? 1 : -1; }
		elseif ( null !== ( $sg['rlow'] ?? null ) ) { $side = ( $num % 2 ) === ( $sg['rlow'] % 2 ) ? -1 : 1; }
		// walk the path to the fraction t of its length
		$lens = array(); $total = 0;
		for ( $i = 0; $i < count( $path ) - 1; $i++ ) { $l = self::distance_m( $path[ $i ][0], $path[ $i ][1], $path[ $i + 1 ][0], $path[ $i + 1 ][1] ); $lens[] = $l; $total += $l; }
		if ( $total <= 0 ) { return null; }
		$want = $t * $total; $acc = 0;
		for ( $i = 0; $i < count( $lens ); $i++ ) {
			if ( $acc + $lens[ $i ] >= $want || $i === count( $lens ) - 1 ) {
				$f = $lens[ $i ] > 0 ? ( $want - $acc ) / $lens[ $i ] : 0;
				$a = $path[ $i ]; $b = $path[ $i + 1 ];
				$lat = $a[0] + ( $b[0] - $a[0] ) * $f; $lng = $a[1] + ( $b[1] - $a[1] ) * $f;
				// perpendicular offset (12 m): left of the direction a→b is +, matching the client's side convention
				$cos = cos( deg2rad( $lat ) );
				$dx = ( $b[1] - $a[1] ) * $cos * 111320; $dy = ( $b[0] - $a[0] ) * 110540;
				$len = sqrt( $dx * $dx + $dy * $dy );
				if ( $len > 0 && $side ) {
					$nx = -$dy / $len; $ny = $dx / $len; // left normal (x east, y north)
					$lat += ( $ny * 12 * $side ) / 110540;
					$lng += ( $nx * 12 * $side ) / ( 111320 * $cos );
				}
				return array( round( $lat, 7 ), round( $lng, 7 ) );
			}
			$acc += $lens[ $i ];
		}
		return null;
	}

	/** Group address points by street: [ name, key, count (all), selected, zip, city ]. */
	public static function streets_from_addresses( $addresses ) {
		$by = array();
		foreach ( $addresses as $a ) {
			$k = $a['street_key'];
			if ( ! isset( $by[ $k ] ) ) {
				$by[ $k ] = array( 'name' => $a['street'], 'key' => $k, 'count' => 0, 'selected' => 0, 'zip' => $a['zip'], 'city' => $a['city'], 'coverage' => 'partial', 'from' => '', 'to' => '', 'note' => '' );
			}
			$by[ $k ]['count']++;
			if ( ! empty( $a['selected'] ) ) {
				$by[ $k ]['selected']++;
			}
		}
		$out = array_values( $by );
		usort( $out, function ( $x, $y ) { return $y['selected'] - $x['selected'] ?: strcmp( $x['name'], $y['name'] ); } );
		return $out;
	}

	/* ----------------------------------------------- screenshot georeferencing */

	/**
	 * Resolve "Street A & Street B" intersections to coordinates using the road layer's nodes.
	 * $anchors: [ [ 'a' => 'Oak St', 'b' => 'Elm Ave', 'x' => 0-1000, 'y' => 0-1000 ], ... ]
	 * Returns the anchors that resolved, each with lat/lng, plus the segments fetched (reusable for the map).
	 */
	public static function resolve_intersections( $anchors, $zip, $hint_ll = null ) {
		$names = array();
		foreach ( $anchors as $an ) {
			$names[] = $an['a'];
			$names[] = $an['b'];
		}
		$names = array_values( array_unique( array_filter( array_map( 'trim', $names ) ) ) );
		if ( count( $names ) < 2 || ! self::configured() ) {
			return array( 'anchors' => array(), 'segments' => array() );
		}
		$env = $zip ? self::zip_extent( $zip ) : null;
		if ( ! $env && $hint_ll ) {
			$env = array( 'xmin' => $hint_ll[1] - 0.03, 'ymin' => $hint_ll[0] - 0.025, 'xmax' => $hint_ll[1] + 0.03, 'ymax' => $hint_ll[0] + 0.025 );
		}
		if ( ! $env ) {
			$b = self::source()['bbox'];
			if ( 4 !== count( $b ) ) {
				return array( 'anchors' => array(), 'segments' => array() );
			}
			$env = array( 'xmin' => $b[0], 'ymin' => $b[1], 'xmax' => $b[2], 'ymax' => $b[3] ); // the whole region
		}
		// pad the ZIP extent a little: intersections sit on ZIP boundaries all the time
		$env = array( 'xmin' => $env['xmin'] - 0.01, 'ymin' => $env['ymin'] - 0.01, 'xmax' => $env['xmax'] + 0.01, 'ymax' => $env['ymax'] + 0.01 );
		$segments = self::segments( $names, $env );
		if ( is_wp_error( $segments ) || ! $segments ) {
			return array( 'anchors' => array(), 'segments' => array() );
		}
		// node id -> [coords, set of street keys]
		$nodes = array();
		foreach ( $segments as $sg ) {
			$k = $sg['key'];
			if ( $sg['fnode'] !== null ) {
				$nodes[ $sg['fnode'] ]['ll'] = $sg['path'][0];
				$nodes[ $sg['fnode'] ]['streets'][ $k ] = true;
			}
			if ( $sg['tnode'] !== null ) {
				$nodes[ $sg['tnode'] ]['ll'] = $sg['path'][ count( $sg['path'] ) - 1 ];
				$nodes[ $sg['tnode'] ]['streets'][ $k ] = true;
			}
		}
		$resolved = array();
		foreach ( $anchors as $an ) {
			$ka = ZDH_DB::street_key( self::canonical_name( $an['a'] ) );
			$kb = ZDH_DB::street_key( self::canonical_name( $an['b'] ) );
			$hits = array();
			foreach ( $nodes as $nid => $nd ) {
				if ( isset( $nd['streets'][ $ka ], $nd['streets'][ $kb ] ) ) {
					$hits[] = $nd['ll'];
				}
			}
			// suffix-less names ("Oak" vs "Oak Ln"): try a looser match on the core words
			if ( ! $hits ) {
				$ca = self::parse_street( $an['a'] )['core']; $cb = self::parse_street( $an['b'] )['core'];
				foreach ( $nodes as $nid => $nd ) {
					$keys = array_keys( $nd['streets'] );
					$ma = false; $mb = false;
					foreach ( $keys as $kk ) {
						$core = strtoupper( self::parse_street( $kk )['core'] );
						if ( $core === $ca ) { $ma = true; }
						if ( $core === $cb ) { $mb = true; }
					}
					if ( $ma && $mb ) { $hits[] = $nd['ll']; }
				}
			}
			if ( ! $hits ) {
				continue;
			}
			// a street can cross another twice (loops): keep the pair's first hit unless a hint says otherwise
			$best = $hits[0];
			if ( count( $hits ) > 1 && $hint_ll ) {
				$bd = INF;
				foreach ( $hits as $h ) {
					$d = self::distance_m( $hint_ll[0], $hint_ll[1], $h[0], $h[1] );
					if ( $d < $bd ) { $bd = $d; $best = $h; }
				}
			}
			$resolved[] = array( 'a' => $an['a'], 'b' => $an['b'], 'x' => (float) $an['x'], 'y' => (float) $an['y'], 'lat' => $best[0], 'lng' => $best[1], 'ambiguous' => count( $hits ) > 1 );
		}
		return array( 'anchors' => $resolved, 'segments' => $segments );
	}

	/** Keeps the key computation for an intersection's names in one place (a hook for aliasing). */
	private static function canonical_name( $n ) {
		return trim( (string) $n );
	}

	private static function full_from_attrs( $at ) {
		return trim( preg_replace( '/\s+/', ' ', ( $at['pre_dir'] ?? '' ) . ' ' . ( $at['name'] ?? '' ) . ' ' . ( $at['suffix'] ?? '' ) . ' ' . ( $at['post_dir'] ?? '' ) ) );
	}

	/** Keep the rows whose suffix/directional agree with what was asked ("Oak Hollow Dr" must not pull in "Oak Hollow Pl"). */
	private static function filter_by_suffix( $features, $p ) {
		if ( ! $features ) {
			return $features;
		}
		$want_sfx  = $p['sfx'];
		$want_pdir = $p['pdir'];
		$strict    = array_values( array_filter( $features, function ( $f ) use ( $want_sfx, $want_pdir ) {
			$at   = $f['attributes'];
			$sfx  = strtoupper( trim( (string) ( $at['suffix'] ?? '' ) ) );
			$pdir = strtoupper( trim( (string) ( $at['pre_dir'] ?? '' ) ) );
			$ok   = $want_sfx === '' ? $sfx === '' : $sfx === $want_sfx;
			if ( $want_pdir !== '' ) {
				$ok = $ok && $pdir === $want_pdir;
			}
			return $ok;
		} ) );
		if ( $strict ) {
			return $strict;
		}
		// The suffix may have been dropped ("Oak" for "Oak Ln"): accept a single suffix variant.
		if ( $want_sfx === '' ) {
			$by_sfx = array();
			foreach ( $features as $f ) {
				$by_sfx[ strtoupper( trim( (string) ( $f['attributes']['suffix'] ?? '' ) ) ) ][] = $f;
			}
			if ( count( $by_sfx ) === 1 ) {
				return $features;
			}
			uasort( $by_sfx, function ( $a, $b ) { return count( $b ) - count( $a ); } );
			return reset( $by_sfx );
		}
		return array();
	}

	/** Pick the best ZIP group: matching city hint, else nearest to the anchor, else the biggest. */
	private static function pick_group( $groups, $ctx ) {
		$city = isset( $ctx['city'] ) ? strtolower( trim( (string) $ctx['city'] ) ) : '';
		if ( $city ) {
			foreach ( $groups as $z => $feat ) {
				$comm = strtolower( trim( (string) ( $feat[0]['attributes']['community'] ?? '' ) ) );
				if ( $comm === $city ) {
					return $z;
				}
			}
		}
		if ( ! empty( $ctx['anchor'] ) && is_array( $ctx['anchor'] ) ) {
			$best = null;
			$bd   = INF;
			foreach ( $groups as $z => $feat ) {
				$d = self::distance_m( $ctx['anchor'][0], $ctx['anchor'][1], (float) $feat[0]['geometry']['y'], (float) $feat[0]['geometry']['x'] );
				if ( $d < $bd ) {
					$bd   = $d;
					$best = (string) $z;
				}
			}
			return $best;
		}
		uasort( $groups, function ( $a, $b ) { return count( $b ) - count( $a ); } );
		$first = array_key_first( $groups );
		return null === $first ? null : (string) $first; // a ZIP key of five digits is stored as an integer
	}

	/**
	 * Road centerline segments for a set of street names inside an envelope.
	 * Returns segments: [ id, name (display), key, path [[lat,lng]...], low, high, fnode, tnode, funclass ]
	 */
	public static function segments( $street_names, $env ) {
		$cores = array();
		if ( is_array( $street_names ) ) {
			foreach ( $street_names as $n ) {
				$p = self::parse_street( $n );
				if ( $p['core'] !== '' ) {
					$cores[ $p['core'] ] = true;
				}
			}
			if ( ! $cores ) {
				return array();
			}
		}
		if ( ! $env ) {
			return array();
		}
		$s = self::source();
		if ( '' === $s['base'] || '' === $s['layers']['roads'] ) {
			return array();
		}
		$where = $cores ? self::f( 'roads', 'name' ) . ' IN (' . implode( ',', array_map( array( __CLASS__, 'sql_str' ), array_keys( $cores ) ) ) . ')' : '1=1';
		$json  = self::arcgis_query( 'roads', array(
			'where'              => $where,
			'geometry'           => self::env_geometry( $env ),
			'geometryType'       => 'esriGeometryEnvelope',
			'inSR'               => '4326',
			'spatialRel'         => 'esriSpatialRelIntersects',
			'outFields'          => self::out_fields( 'roads' ),
			'returnGeometry'     => 'true',
			'geometryPrecision'  => '6',
			'maxAllowableOffset' => '0.00002',
			'resultRecordCount'  => '2000',
		), 30 );
		if ( is_wp_error( $json ) ) {
			return $json;
		}
		$segments = array();
		foreach ( (array) ( $json['features'] ?? array() ) as $f ) {
			$at    = $f['attributes'];
			$paths = $f['geometry']['paths'] ?? array();
			if ( ! $paths ) {
				continue;
			}
			// A segment may (rarely) have several parts; flatten in order.
			$path = array();
			foreach ( $paths as $part ) {
				foreach ( $part as $xy ) {
					$path[] = array( round( (float) $xy[1], 6 ), round( (float) $xy[0], 6 ) );
				}
			}
			$disp  = self::display_name( $at['pre_dir'] ?? '', $at['name'] ?? '', $at['suffix'] ?? '', $at['post_dir'] ?? '' );
			$pos   = function ( $k ) use ( $at ) { return isset( $at[ $k ] ) && (int) $at[ $k ] > 0 ? (int) $at[ $k ] : null; };
			$lows  = array_filter( array( $pos( 'left_low' ), $pos( 'right_low' ) ) );
			$highs = array_filter( array( $pos( 'left_high' ), $pos( 'right_high' ) ) );
			$segments[] = array(
				'id'       => (int) ( $at['segment'] ?? 0 ),
				'name'     => $disp,
				'key'      => ZDH_DB::street_key( $disp ),
				'path'     => $path,
				'low'      => $lows ? min( $lows ) : null,
				'high'     => $highs ? max( $highs ) : null,
				'llow'     => $pos( 'left_low' ),
				'lhigh'    => $pos( 'left_high' ),
				'rlow'     => $pos( 'right_low' ),
				'rhigh'    => $pos( 'right_high' ),
				'fnode'    => isset( $at['from_node'] ) ? (int) $at['from_node'] : null,
				'tnode'    => isset( $at['to_node'] ) ? (int) $at['to_node'] : null,
				'funclass' => (string) ( $at['road_class'] ?? '' ),
			);
		}
		return $segments;
	}

	/** Envelope of a ZIP code polygon (used when no address points were found). */
	public static function zip_extent( $zip ) {
		$zip = (int) preg_replace( '/[^0-9]/', '', (string) $zip );
		$s   = self::source();
		if ( ! $zip || '' === $s['base'] || '' === $s['layers']['zips'] ) {
			return null;
		}
		$json = self::arcgis_query( 'zips', array( 'where' => self::f( 'zips', 'zip' ) . ' = ' . $zip, 'returnExtentOnly' => 'true' ), 15 );
		if ( is_wp_error( $json ) || empty( $json['extent'] ) || ! isset( $json['extent']['xmin'] ) ) {
			return null;
		}
		$e = $json['extent'];
		return array( 'xmin' => (float) $e['xmin'], 'ymin' => (float) $e['ymin'], 'xmax' => (float) $e['xmax'], 'ymax' => (float) $e['ymax'] );
	}

	/* ------------------------------------------------------------ hazard zones */

	/**
	 * The hazard layers the business names (`zdh_hazard_layers` option, territories.yml `canvassing.hazard_layers`):
	 * [ [ 'url' => https query URL, 'fields' => [class field, ...], 'label' => 'who publishes it',
	 *     'rank' => [ lowest class, ..., highest class ] ], ... ]. Core ships none.
	 */
	public static function hazard_layers() {
		$raw = function_exists( 'get_option' ) ? get_option( self::HAZARD_OPTION, array() ) : array();
		$raw = is_array( $raw ) ? $raw : array();
		if ( function_exists( 'apply_filters' ) ) {
			$f   = apply_filters( 'zdh_hazard_layers', $raw );
			$raw = is_array( $f ) ? $f : $raw;
		}
		$out = array();
		foreach ( $raw as $l ) {
			if ( ! is_array( $l ) || empty( $l['url'] ) || ! preg_match( '#^https://[A-Za-z0-9.\-]+(?::\d+)?/[A-Za-z0-9_./\-]*$#', (string) $l['url'] ) ) {
				continue;
			}
			$fields = array_values( array_filter( array_map( 'strval', (array) ( $l['fields'] ?? array() ) ), function ( $f ) { return (bool) preg_match( '/^[A-Za-z_][A-Za-z0-9_]{0,40}$/', $f ); } ) );
			if ( ! $fields ) {
				continue;
			}
			$out[] = array(
				'url'    => (string) $l['url'],
				'fields' => $fields,
				'label'  => mb_substr( wp_strip_all_tags( (string) ( $l['label'] ?? '' ) ), 0, 80 ),
				'rank'   => array_values( array_filter( array_map( 'strval', (array) ( $l['rank'] ?? array() ) ) ) ),
			);
		}
		return $out;
	}

	/**
	 * The hazard zone at a point, from the configured layers, asked in order. With a rank list, the highest class any
	 * layer reports wins and the top class stops the asking; without one, the first class reported wins.
	 * Returns [ 'zone' => class or '', 'source' => label ], null when no layer is configured, or WP_Error when every
	 * layer failed.
	 */
	public static function fire_zone( $lat, $lng ) {
		$layers = self::hazard_layers();
		if ( ! $layers ) {
			return null;
		}
		$point = array(
			'geometry'       => $lng . ',' . $lat,
			'geometryType'   => 'esriGeometryPoint',
			'inSR'           => '4326',
			'spatialRel'     => 'esriSpatialRelIntersects',
			'returnGeometry' => 'false',
		);
		$best   = '';
		$bscore = -1;
		$source = '';
		$errors = 0;
		foreach ( $layers as $l ) {
			$r = self::arcgis_query( $l['url'], $point + array( 'outFields' => implode( ',', $l['fields'] ) ), 12 );
			if ( is_wp_error( $r ) ) {
				$errors++;
				continue;
			}
			foreach ( (array) ( $r['features'] ?? array() ) as $f ) {
				$c = '';
				foreach ( $l['fields'] as $fld ) {
					if ( isset( $f['attributes'][ $fld ] ) && '' !== trim( (string) $f['attributes'][ $fld ] ) ) {
						$c = trim( (string) $f['attributes'][ $fld ] );
						break;
					}
				}
				if ( '' === $c ) {
					continue;
				}
				$score = $l['rank'] ? array_search( $c, $l['rank'], true ) : 0;
				if ( false === $score ) {
					continue; // a class outside the layer's rank list is not a zone
				}
				if ( $score > $bscore ) {
					$best   = $c;
					$bscore = (int) $score;
					$source = $l['label'];
				}
			}
			if ( '' !== $best && ( ! $l['rank'] || $bscore === count( $l['rank'] ) - 1 ) ) {
				break;
			}
		}
		if ( '' === $best && $errors >= count( $layers ) ) {
			return new WP_Error( 'zdh_hazard', __( 'The hazard map layers could not be reached.', 'zorderz' ) );
		}
		return array( 'zone' => $best, 'source' => '' !== $best ? $source : __( 'not inside a mapped zone', 'zorderz' ) );
	}

	/* ------------------------------------------------------------ OSM */

	/** The country to name to the open map's search: the Business Profile's, else none. */
	private static function country() {
		return function_exists( 'zdh_profile' ) ? zdh_profile( 'contact.address.country' ) : '';
	}

	/**
	 * The open street map for named streets: address nodes/buildings and the streets' ways.
	 * Returns [ 'addresses' => [...], 'segments' => [...], 'note' => '' ]
	 */
	public static function osm_lookup( $street_names, $city, $state, $zip ) {
		$bbox   = null;
		$note   = '';
		foreach ( $street_names as $i => $name ) {
			if ( $i > 0 ) {
				sleep( 1 ); // Nominatim usage policy: max 1 request / second
			}
			$q = array( 'street' => rawurlencode( $name ), 'format' => 'jsonv2', 'limit' => 3 );
			if ( '' !== (string) $city ) { $q['city'] = rawurlencode( $city ); }
			if ( '' !== (string) $state ) { $q['state'] = rawurlencode( $state ); }
			if ( '' !== (string) $zip ) { $q['postalcode'] = rawurlencode( $zip ); }
			if ( '' !== self::country() ) { $q['country'] = rawurlencode( self::country() ); }
			$resp = wp_remote_get( add_query_arg( $q, self::NOMINATIM ), array( 'timeout' => 15, 'headers' => array( 'User-Agent' => self::UA ) ) );
			if ( is_wp_error( $resp ) ) {
				continue;
			}
			$json = json_decode( wp_remote_retrieve_body( $resp ), true );
			if ( ! is_array( $json ) || ! $json ) {
				continue;
			}
			$r = $json[0];
			if ( ! empty( $r['boundingbox'] ) ) {
				$b = array_map( 'floatval', $r['boundingbox'] ); // [south, north, west, east]
				if ( null === $bbox ) {
					$bbox = array( $b[0], $b[2], $b[1], $b[3] );
				} else {
					$bbox = array( min( $bbox[0], $b[0] ), min( $bbox[1], $b[2] ), max( $bbox[2], $b[1] ), max( $bbox[3], $b[3] ) );
				}
			}
		}
		if ( null === $bbox ) {
			return array( 'addresses' => array(), 'segments' => array(), 'note' => 'The open map could not locate these streets' );
		}
		$pad  = 0.003;
		$bbox = array( $bbox[0] - $pad, $bbox[1] - $pad, $bbox[2] + $pad, $bbox[3] + $pad );
		$bb   = implode( ',', $bbox );

		// address points + highway ways for the street names (both long and short suffix forms)
		$alts = array();
		foreach ( $street_names as $name ) {
			$alts[] = self::overpass_re( $name );
			$k      = ZDH_DB::street_key( $name );
			if ( $k ) {
				$alts[] = self::overpass_re( $k );
				$alts[] = self::overpass_re( self::expand_suffix( $name ) );
			}
		}
		$re    = '^(' . implode( '|', array_unique( $alts ) ) . ')$';
		$query = "[out:json][timeout:40];(node[\"addr:housenumber\"][\"addr:street\"~\"$re\",i]($bb);way[\"addr:housenumber\"][\"addr:street\"~\"$re\",i]($bb);way[\"highway\"][\"name\"~\"$re\",i]($bb););out body geom;";
		$res   = self::overpass( $query, $city, $zip );
		if ( is_wp_error( $res ) ) {
			return array( 'addresses' => array(), 'segments' => array(), 'note' => $res->get_error_message() );
		}
		$res['note'] = $note;
		$res['bbox'] = $bbox;
		return $res;
	}

	/**
	 * The open street map inside an envelope: every address node or building and every named road. Used to draw and
	 * walk an area where no regional layer is set.
	 */
	public static function osm_in_envelope( $env ) {
		$bb    = implode( ',', array( $env['ymin'], $env['xmin'], $env['ymax'], $env['xmax'] ) );
		$query = "[out:json][timeout:40];(node[\"addr:housenumber\"][\"addr:street\"]($bb);way[\"addr:housenumber\"][\"addr:street\"]($bb);way[\"highway\"][\"name\"]($bb););out body geom;";
		return self::overpass( $query, '', '' );
	}

	/** A street name as an Overpass regex alternative: letters, digits, spaces and apostrophes only. */
	private static function overpass_re( $name ) {
		return preg_quote( preg_replace( '/[^A-Za-z0-9 \'\-]/', '', (string) $name ), '/' );
	}

	/** One Overpass question -> [ addresses, segments ] in the app's shapes, or WP_Error. */
	private static function overpass( $query, $city, $zip ) {
		$resp = wp_remote_post( self::OVERPASS, array( 'timeout' => 45, 'headers' => array( 'User-Agent' => self::UA ), 'body' => array( 'data' => $query ) ) );
		if ( is_wp_error( $resp ) ) {
			return new WP_Error( 'zdh_osm', sprintf( __( 'The open map could not be reached: %s', 'zorderz' ), $resp->get_error_message() ) );
		}
		$json      = json_decode( wp_remote_retrieve_body( $resp ), true );
		$addresses = array();
		$segments  = array();
		foreach ( (array) ( $json['elements'] ?? array() ) as $el ) {
			$tags = $el['tags'] ?? array();
			if ( isset( $tags['addr:housenumber'], $tags['addr:street'] ) ) {
				if ( 'node' === $el['type'] ) {
					$lat = $el['lat']; $lng = $el['lon'];
				} else {
					// building outline: centroid of its vertices
					$lat = 0; $lng = 0; $n = 0;
					foreach ( (array) ( $el['geometry'] ?? array() ) as $pt ) { $lat += $pt['lat']; $lng += $pt['lon']; $n++; }
					if ( ! $n ) { continue; }
					$lat /= $n; $lng /= $n;
				}
				$street      = (string) $tags['addr:street'];
				$addresses[] = array(
					'number'     => (string) $tags['addr:housenumber'],
					'unit'       => (string) ( $tags['addr:unit'] ?? '' ),
					'street'     => $street,
					'street_key' => ZDH_DB::street_key( $street ),
					'city'       => (string) ( $tags['addr:city'] ?? $city ),
					'zip'        => (string) ( $tags['addr:postcode'] ?? $zip ),
					'lat'        => round( $lat, 7 ),
					'lng'        => round( $lng, 7 ),
					'seg'        => null,
					'apn'        => '',
					'type'       => '',
					'jur'        => '',
					'source'     => 'osm',
				);
			} elseif ( 'way' === $el['type'] && isset( $tags['highway'], $tags['name'] ) ) {
				$path = array();
				foreach ( (array) ( $el['geometry'] ?? array() ) as $pt ) {
					$path[] = array( round( $pt['lat'], 6 ), round( $pt['lon'], 6 ) );
				}
				$nodes = $el['nodes'] ?? array();
				$segments[] = array(
					'id'       => (int) $el['id'],
					'name'     => (string) $tags['name'],
					'key'      => ZDH_DB::street_key( $tags['name'] ),
					'path'     => $path,
					'low'      => null,
					'high'     => null,
					'llow'     => null,
					'lhigh'    => null,
					'rlow'     => null,
					'rhigh'    => null,
					'fnode'    => $nodes ? (int) $nodes[0] : null,
					'tnode'    => $nodes ? (int) end( $nodes ) : null,
					'funclass' => (string) $tags['highway'],
				);
			}
		}
		return array( 'addresses' => $addresses, 'segments' => $segments, 'note' => '' );
	}

	/** "Oak Hollow Dr" -> "Oak Hollow Drive" (for the open map's name matching). */
	public static function expand_suffix( $name ) {
		$long = array( 'dr' => 'Drive', 'ave' => 'Avenue', 'st' => 'Street', 'ln' => 'Lane', 'ct' => 'Court', 'rd' => 'Road', 'pl' => 'Place', 'blvd' => 'Boulevard', 'cir' => 'Circle', 'ter' => 'Terrace', 'trl' => 'Trail', 'pkwy' => 'Parkway', 'hwy' => 'Highway', 'pt' => 'Point', 'gln' => 'Glen', 'rdg' => 'Ridge', 'vw' => 'View', 'cv' => 'Cove', 'sq' => 'Square', 'hts' => 'Heights', 'xing' => 'Crossing', 'aly' => 'Alley', 'plz' => 'Plaza', 'expy' => 'Expressway', 'fwy' => 'Freeway' );
		$parts = explode( ' ', trim( (string) $name ) );
		$last  = strtolower( rtrim( end( $parts ), '.' ) );
		if ( count( $parts ) > 1 && isset( $long[ $last ] ) ) {
			$parts[ count( $parts ) - 1 ] = $long[ $last ];
		}
		return implode( ' ', $parts );
	}

	/* ------------------------------------------------------------ misc */

	public static function distance_m( $lat1, $lng1, $lat2, $lng2 ) {
		$r  = 6371000;
		$p1 = deg2rad( $lat1 ); $p2 = deg2rad( $lat2 );
		$dp = deg2rad( $lat2 - $lat1 ); $dl = deg2rad( $lng2 - $lng1 );
		$a  = sin( $dp / 2 ) ** 2 + cos( $p1 ) * cos( $p2 ) * sin( $dl / 2 ) ** 2;
		return 2 * $r * atan2( sqrt( $a ), sqrt( 1 - $a ) );
	}

	/** Envelope (xmin,ymin,xmax,ymax in lng/lat) around a set of points, padded in degrees. */
	public static function envelope( $points, $pad = 0.004 ) {
		$xmin = $ymin = INF; $xmax = $ymax = -INF;
		foreach ( $points as $p ) {
			if ( $p['lat'] === null || $p['lng'] === null ) { continue; }
			$xmin = min( $xmin, $p['lng'] ); $xmax = max( $xmax, $p['lng'] );
			$ymin = min( $ymin, $p['lat'] ); $ymax = max( $ymax, $p['lat'] );
		}
		if ( ! is_finite( $xmin ) ) {
			return null;
		}
		// the same distance east-west as north-south: a degree of longitude shrinks with the cosine of the latitude
		$px = $pad / max( 0.2, cos( deg2rad( ( $ymin + $ymax ) / 2 ) ) );
		return array( 'xmin' => $xmin - $px, 'ymin' => $ymin - $pad, 'xmax' => $xmax + $px, 'ymax' => $ymax + $pad );
	}

	/**
	 * Main entry: given a street list and hints, return everything the review map needs.
	 * $streets: [ [ 'name' => 'Oak Hollow Dr', 'coverage' => 'full|partial', 'from' => '', 'to' => '' ], ... ]
	 * $cross:   extra street names to fetch segments for (cross streets used as bounds)
	 */
	public static function find( $streets, $cross, $hints ) {
		$area  = function_exists( 'zdh_area_defaults' ) ? zdh_area_defaults() : array( 'state' => '' );
		$zip   = preg_replace( '/[^0-9]/', '', (string) ( $hints['zip'] ?? '' ) );
		$city  = (string) ( $hints['city'] ?? '' );
		$state = (string) ( ( $hints['state'] ?? '' ) ?: $area['state'] );
		$anchor = ( isset( $hints['lat'], $hints['lng'] ) && $hints['lat'] && $hints['lng'] ) ? array( (float) $hints['lat'], (float) $hints['lng'] ) : null;

		$all_addresses = array();
		$street_out    = array();
		$notes         = array();
		$found_zip     = '';
		$source        = 'region';
		$ctx           = array( 'zip' => $zip, 'city' => $city, 'anchor' => $anchor );
		$regional      = self::configured();

		foreach ( $streets as $st ) {
			$res = $regional ? self::addresses_for_street( $st['name'], $ctx ) : array( 'addresses' => array(), 'note' => '', 'zip' => $zip );
			if ( is_wp_error( $res ) ) {
				if ( ! self::osm_on() ) {
					return $res;
				}
				$notes[]  = $res->get_error_message();
				$regional = false;
				$res      = array( 'addresses' => array(), 'note' => '', 'zip' => $zip );
			}
			$addrs = $res['addresses'];
			if ( $addrs && ! $anchor ) {
				$ctx['anchor'] = array( $addrs[0]['lat'], $addrs[0]['lng'] ); // later streets snap to the same neighborhood
			}
			if ( $addrs && ! $found_zip ) {
				$found_zip = $res['zip'];
				if ( ! $ctx['zip'] ) {
					$ctx['zip'] = $found_zip;
				}
			}
			$pp      = self::parse_street( $st['name'] );
			$display = $addrs ? $addrs[0]['street'] : self::display_name( $pp['pdir'], $pp['core'], $pp['sfx'], $pp['postd'] );
			$street_out[] = array(
				'name'      => $display,
				'input'     => $st['name'],
				'key'       => ZDH_DB::street_key( $display ),
				'coverage'  => ( $st['coverage'] ?? 'full' ) === 'partial' ? 'partial' : 'full',
				'from'      => (string) ( $st['from'] ?? '' ),
				'to'        => (string) ( $st['to'] ?? '' ),
				'count'     => count( $addrs ),
				'note'      => $res['note'],
			);
			if ( $res['note'] ) {
				$notes[] = $display . ': ' . $res['note'];
			}
			foreach ( $addrs as $a ) {
				$all_addresses[] = $a;
			}
		}

		$segments = array();
		if ( $regional ) {
			$env = self::envelope( $all_addresses, 0.004 );
			if ( ! $env && ( $zip || $found_zip ) ) {
				$env = self::zip_extent( $zip ?: $found_zip );
			}
			if ( $env ) {
				$names = array_merge( array_column( $street_out, 'name' ), array_map( 'strval', $cross ) );
				$segs  = self::segments( array_unique( array_filter( $names ) ), $env );
				if ( ! is_wp_error( $segs ) ) {
					$segments = $segs;
				} else {
					$notes[] = 'Road segments unavailable: ' . $segs->get_error_message();
				}
			}
		}

		// Nothing from the regional layer? Try the open street map.
		if ( ! $all_addresses && self::osm_on() ) {
			$osm = self::osm_lookup( array_column( $streets, 'name' ), $city, $state, $zip );
			if ( $osm['addresses'] || $osm['segments'] ) {
				$source = 'osm';
				foreach ( $osm['addresses'] as $a ) {
					$a['street_key']  = ZDH_DB::street_key( $a['street'] );
					$all_addresses[] = $a;
				}
				$segments = $osm['segments'];
				foreach ( $street_out as &$so ) {
					$so['count'] = count( array_filter( $all_addresses, function ( $a ) use ( $so ) { return $a['street_key'] === $so['key']; } ) );
				}
				unset( $so );
			}
			if ( $osm['note'] ) {
				$notes[] = $osm['note'];
			}
		} elseif ( ! $all_addresses && ! self::configured() ) {
			$notes[] = __( 'No address source is set for this site.', 'zorderz' );
		}

		$spread = self::spread_stacked( $all_addresses, $segments );
		if ( $spread ) { $notes[] = sprintf( __( '%d addresses were placed along their street by house number (the map has that complex as a single point).', 'zorderz' ), (int) $spread ); }
		$center = null;
		if ( $all_addresses ) {
			$lat = 0; $lng = 0; $n = 0;
			foreach ( $all_addresses as $a ) { if ( $a['lat'] !== null ) { $lat += $a['lat']; $lng += $a['lng']; $n++; } }
			if ( $n ) { $center = array( round( $lat / $n, 6 ), round( $lng / $n, 6 ) ); }
		} elseif ( $segments ) {
			$p = $segments[0]['path'];
			$center = $p[ intdiv( count( $p ), 2 ) ];
		}

		return array(
			'source'    => $source,
			'zip'       => $zip ?: $found_zip,
			'streets'   => $street_out,
			'addresses' => $all_addresses,
			'segments'  => $segments,
			'center'    => $center,
			'notes'     => $notes,
		);
	}
}
