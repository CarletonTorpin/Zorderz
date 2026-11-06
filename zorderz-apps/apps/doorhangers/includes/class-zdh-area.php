<?php
/**
 * ZDH_Area -- the address source's houses and roads of an area, for another app. The houses come through the address
 * seam (`zdh_address_provider`, else the regional address layer, a local mirror of it first, else the open street map).
 *
 * Two registry READ verbs, registered by ZDH_Bridge:
 *
 *   doorhangers.area_houses   the houses and road segments of an area -- what planWalks (assets/walks.js) needs
 *                             to cut walks, and what a drive-by survey needs to know which block it is on.
 *       Input (one of):  envelope {xmin, ymin, xmax, ymax} (lng/lat; at most ~5 x 5.5 km, ZDH_Geo's own cap)
 *                        near {lat, lng} + radius_m (25-800 m)
 *                        streets [names] (at most 12) + zip / city -- whole streets, the address source's houses only
 *       Output, by who asks:
 *         | who asks                         | gets                                                                  |
 *         |----------------------------------|-----------------------------------------------------------------------|
 *         | a holder of Doorhangers          | houses {k, lat, lng, seg, street, street_key, zip, city, number, unit,|
 *         |                                  | no_solicit}; k is the route key ZDH_Plan checks (house_key())      |
 *         | anyone else the verb allows      | houses {k, lat, lng, seg, street, street_key, zip, city}: an opaque k,|
 *         |                                  | no house number; No-Solicit houses left out; counts per street        |
 *         | both                             | segments {id, name, key, path, fnode, tnode}; counts                  |
 *         | the shared kiosk                 | nothing (refused by the registry and here)                            |
 *         | anyone                           | never a name, a contact, a delivery record or who lives there         |
 *       Tier staff (sales and admin plan routes), no Doorhangers needed (needs_app false: the planner's people may not
 *       hold Doorhangers, and they get the minimum form). A holder sees a No-Solicit house flagged, so a map draws it
 *       red and a planner leaves it out; anyone else never sees it at all.
 *
 *   doorhangers.hung_streets  the streets door hangers went on, or are planned for -- ZDH_Bridge::hung_streets() as a
 *                             verb, the same shape as the filter `zdh_hung_streets` (which stays, for PHP callers).
 *       Input: days (1-3650, default 365). Holders of Doorhangers only (the whole log is every holder's); never the
 *       kiosk. No house, no person.
 *
 * Both: the person comes from the registry's stamp ($args['user_id']), never from the caller; a failed read is an error,
 * never an empty success. Nothing is stored.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZDH_Area {

	const VERB_AREA   = 'doorhangers.area_houses';
	const VERB_HUNG   = 'doorhangers.hung_streets';
	const MAX_HOUSES  = 2000;   // an address layer's usual page size for one envelope
	const MAX_SEGS    = 1500;

	public static function descriptors(): array {
		return array(
			self::VERB_AREA => array(
				'verb'          => self::VERB_AREA,
				'provider'      => 'doorhangers',
				'app'           => 'doorhangers',
				'tier'          => 'staff',
				'needs_app'     => false,
				'callback'      => array( __CLASS__, 'area_from_capability' ),
				'kiosk'         => false,
				'kiosk_bounded' => false,
				'side_effect'   => false,
				'summary'       => 'The houses and road segments in an area (a box, a point and radius, or up to 12 streets): '
					. 'points, streets and segments for anyone it allows; house numbers and No-Solicit flags only for holders of Doorhangers, '
					. 'No-Solicit houses left out for anyone else. '
					. 'Never a name or contact; never the shared device.',
			),
			self::VERB_HUNG => array(
				'verb'          => self::VERB_HUNG,
				'provider'      => 'doorhangers',
				'app'           => 'doorhangers',
				'tier'          => 'viewer',
				'needs_app'     => true,
				'callback'      => array( __CLASS__, 'hung_from_capability' ),
				'kiosk'         => false,
				'kiosk_bounded' => false,
				'side_effect'   => false,
				'summary'       => 'The streets door hangers went on (or are planned for) lately: street, ZIP, town, day, status, house count '
					. 'and number range. Holders of Doorhangers; never a house or a person.',
			),
		);
	}

	private static function no( $verb, $why, $msg, $denied = false ) {
		$out = array( 'success' => false, 'verb' => $verb, 'message' => $msg );
		if ( $denied ) {
			$out['denied'] = true;
			$out['reason'] = $why;
		} else {
			$out['error'] = $why;
		}
		return $out;
	}

	/* ------------------------------------------------------------------ doorhangers.hung_streets */

	public static function hung_from_capability( array $args ): array {
		global $wpdb;
		$uid = (int) ( $args['user_id'] ?? 0 );
		if ( ! empty( $args['is_kiosk'] ) || zdh_is_kiosk( $uid ) ) {
			return self::no( self::VERB_HUNG, 'kiosk', 'Doorhangers is not available on the shared device.', true );
		}
		if ( $uid <= 0 || ! zdh_user_can_use( $uid ) ) {
			return self::no( self::VERB_HUNG, 'app', 'You do not hold the Doorhangers app.', true );
		}
		$days = isset( $args['days'] ) && is_numeric( $args['days'] ) ? (int) $args['days'] : 365;
		$days = max( 1, min( 3650, $days ) );
		if ( is_object( $wpdb ) && property_exists( $wpdb, 'last_error' ) ) {
			$wpdb->last_error = '';
		}
		$rows = ZDH_Bridge::hung_streets( $uid, $days );
		if ( ! is_array( $rows ) || ( is_object( $wpdb ) && ! empty( $wpdb->last_error ) ) ) {
			return self::no( self::VERB_HUNG, 'db', 'The door-hanger log could not be read just now.' );
		}
		return array( 'success' => true, 'verb' => self::VERB_HUNG, 'days' => $days, 'rows' => $rows, 'count' => count( $rows ) );
	}

	/* ------------------------------------------------------------------ doorhangers.area_houses */

	/** An envelope from {xmin,ymin,xmax,ymax} or {lat,lng} + radius; null when neither reads. */
	public static function envelope_from( array $args ) {
		$e = $args['envelope'] ?? null;
		if ( is_string( $e ) ) {
			$e = json_decode( $e, true );
		}
		if ( is_array( $e ) && isset( $e['xmin'], $e['ymin'], $e['xmax'], $e['ymax'] )
			&& is_numeric( $e['xmin'] ) && is_numeric( $e['ymin'] ) && is_numeric( $e['xmax'] ) && is_numeric( $e['ymax'] ) ) {
			$x0 = (float) $e['xmin']; $x1 = (float) $e['xmax']; $y0 = (float) $e['ymin']; $y1 = (float) $e['ymax'];
			if ( $x1 <= $x0 || $y1 <= $y0 || abs( $y0 ) > 90 || abs( $y1 ) > 90 || abs( $x0 ) > 180 || abs( $x1 ) > 180 ) {
				return null;
			}
			return array( 'xmin' => $x0, 'ymin' => $y0, 'xmax' => $x1, 'ymax' => $y1 );
		}
		$n = $args['near'] ?? null;
		if ( is_array( $n ) && isset( $n['lat'], $n['lng'] ) && is_numeric( $n['lat'] ) && is_numeric( $n['lng'] )
			&& abs( (float) $n['lat'] ) <= 90 && abs( (float) $n['lng'] ) <= 180 ) {
			$r   = isset( $args['radius_m'] ) && is_numeric( $args['radius_m'] ) ? (float) $args['radius_m'] : 300.0;
			$r   = max( 25.0, min( 800.0, $r ) );
			$lat = (float) $n['lat'];
			$dy  = $r / 110540.0;
			$dx  = $r / ( 111320.0 * max( 0.01, cos( deg2rad( $lat ) ) ) );
			return array( 'xmin' => (float) $n['lng'] - $dx, 'ymin' => $lat - $dy, 'xmax' => (float) $n['lng'] + $dx, 'ymax' => $lat + $dy );
		}
		return null;
	}

	/** The key a non-holder sees: stable on this site, says nothing about the address. */
	public static function opaque_key( $route_key ) {
		$salt = function_exists( 'wp_salt' ) ? wp_salt( 'nonce' ) : 'zdh';
		return 'x' . substr( hash_hmac( 'sha256', 'zdh-area|' . $route_key, $salt ), 0, 16 );
	}

	public static function area_from_capability( array $args ): array {
		$v   = self::VERB_AREA;
		$uid = (int) ( $args['user_id'] ?? 0 );
		if ( ! empty( $args['is_kiosk'] ) || zdh_is_kiosk( $uid ) ) {
			return self::no( $v, 'kiosk', 'Doorhangers is not available on the shared device.', true );
		}
		if ( $uid <= 0 ) {
			return self::no( $v, 'signed_out', 'Sign in first.', true );
		}
		$holder = zdh_user_can_use( $uid );
		$env    = self::envelope_from( $args );
		$names  = array();
		foreach ( array_slice( (array) ( $args['streets'] ?? array() ), 0, 40 ) as $s ) {
			if ( ! is_scalar( $s ) ) {
				continue;
			}
			$s = trim( preg_replace( '/\s+/', ' ', sanitize_text_field( (string) $s ) ) );
			if ( '' !== $s && mb_strlen( $s ) <= 80 && ! in_array( strtolower( $s ), array_map( 'strtolower', $names ), true ) ) {
				$names[] = $s;
			}
		}
		if ( count( $names ) > ZDH_Plan::MAX_STREETS ) {
			return self::no( $v, 'too_many_streets', 'At most ' . ZDH_Plan::MAX_STREETS . ' streets at a time.' );
		}
		if ( ! $env && ! $names ) {
			return self::no( $v, 'no_area', 'Give an area: a box, a point and radius, or street names.' );
		}
		if ( $env ) {
			$res = ZDH_Geo::find_in_area( array(), $env );
		} else {
			$streets = array_map( function ( $n ) { return array( 'name' => $n, 'coverage' => 'full', 'from' => '', 'to' => '' ); }, $names );
			$zip     = substr( preg_replace( '/[^0-9]/', '', (string) ( $args['zip'] ?? '' ) ), 0, 5 );
			$city    = mb_substr( sanitize_text_field( (string) ( $args['city'] ?? '' ) ), 0, 60 );
			$res     = ZDH_Geo::find( $streets, array(), array( 'zip' => $zip, 'city' => $city ) );
		}
		if ( is_wp_error( $res ) ) {
			$code = (string) $res->get_error_code();
			return self::no( $v, 'too_big' === $code ? 'too_big' : ( 'zdh_no_source' === $code ? 'no_source' : 'map_down' ),
				'too_big' === $code ? 'That area is too large. Ask for a smaller one.' : ( 'zdh_no_source' === $code ? $res->get_error_message() : 'The address map did not answer. Try again in a minute.' ) );
		}
		if ( ! is_array( $res ) || ! isset( $res['addresses'] ) || ! is_array( $res['addresses'] ) ) {
			return self::no( $v, 'map_down', 'The address map did not answer. Try again in a minute.' );
		}
		if ( ! ZDH_Geo::plannable( (string) ( $res['source'] ?? '' ) ) ) {
			// outside the regional layer: the open map's guesses are for drawing, never for planning (as the plan seam)
			return self::no( $v, 'not_region', 'The regional address layer has no houses there. Routes are only planned from it.' );
		}
		$addr = array_values( array_filter( $res['addresses'], function ( $a ) { return is_array( $a ) && isset( $a['lat'], $a['lng'] ) && null !== $a['lat'] && null !== $a['lng']; } ) );
		$truncated = count( $addr ) >= self::MAX_HOUSES;
		$addr = array_slice( $addr, 0, self::MAX_HOUSES );
		if ( class_exists( 'ZDH_NoSolicit' ) ) {
			global $wpdb;
			if ( is_object( $wpdb ) && property_exists( $wpdb, 'last_error' ) ) { $wpdb->last_error = ''; }
			$addr = ZDH_NoSolicit::tag( $addr );
			if ( is_object( $wpdb ) && ! empty( $wpdb->last_error ) ) {
				// an unread No-Solicit list must not look like "no house has a sign"
				return self::no( $v, 'db', 'The No-Solicit list could not be read just now.' );
			}
		}
		$houses    = array();
		$by_street = array();
		$ns_count  = 0;
		$seen      = array();
		foreach ( $addr as $a ) {
			$skey = (string) ( $a['street_key'] ?? '' );
			$rk   = ZDH_Plan::house_key( (string) ( $a['number'] ?? '' ), (string) ( $a['unit'] ?? '' ), $skey, (string) ( $a['zip'] ?? '' ) );
			if ( isset( $seen[ $rk ] ) ) {
				continue;
			}
			$seen[ $rk ] = true;
			$ns = ! empty( $a['no_solicit'] );
			if ( $ns ) {
				$ns_count++;
			}
			if ( $ns && ! $holder ) {
				// the safety floor: someone who cannot see the flag never gets the house at all (only the count)
				if ( ! isset( $by_street[ $skey ] ) ) {
					$by_street[ $skey ] = array( 'street' => (string) ( $a['street'] ?? '' ), 'street_key' => $skey, 'houses' => 0, 'no_solicit' => 0 );
				}
				$by_street[ $skey ]['no_solicit']++;
				continue;
			}
			$st = (string) ( $a['street'] ?? '' );
			if ( ! isset( $by_street[ $skey ] ) ) {
				$by_street[ $skey ] = array( 'street' => $st, 'street_key' => $skey, 'houses' => 0, 'no_solicit' => 0 );
			}
			$by_street[ $skey ]['houses']++;
			if ( $ns ) {
				$by_street[ $skey ]['no_solicit']++;
			}
			$h = array(
				'k'          => $holder ? $rk : self::opaque_key( $rk ),
				'lat'        => round( (float) $a['lat'], 7 ),
				'lng'        => round( (float) $a['lng'], 7 ),
				'seg'        => isset( $a['seg'] ) && null !== $a['seg'] ? (int) $a['seg'] : null,
				'street'     => $st,
				'street_key' => $skey,
				'zip'        => substr( preg_replace( '/[^0-9]/', '', (string) ( $a['zip'] ?? '' ) ), 0, 5 ),
				'city'       => (string) ( $a['city'] ?? '' ),
			);
			if ( $holder ) {
				$h['number']     = (string) ( $a['number'] ?? '' );
				$h['unit']       = (string) ( $a['unit'] ?? '' );
				$h['no_solicit'] = $ns ? 1 : 0;
			}
			$houses[] = $h;
		}
		$segments = array();
		foreach ( array_slice( (array) ( $res['segments'] ?? array() ), 0, self::MAX_SEGS ) as $s ) {
			if ( ! is_array( $s ) || empty( $s['path'] ) || ! is_array( $s['path'] ) ) {
				continue;
			}
			$path = array();
			foreach ( array_slice( $s['path'], 0, 400 ) as $p ) {
				if ( is_array( $p ) && count( $p ) >= 2 && is_numeric( $p[0] ) && is_numeric( $p[1] ) ) {
					$path[] = array( round( (float) $p[0], 7 ), round( (float) $p[1], 7 ) );
				}
			}
			if ( count( $path ) < 2 ) {
				continue;
			}
			$segments[] = array(
				'id'    => isset( $s['id'] ) ? (int) $s['id'] : null,
				'name'  => (string) ( $s['name'] ?? '' ),
				'key'   => (string) ( $s['key'] ?? ( class_exists( 'ZDH_DB' ) ? ZDH_DB::street_key( (string) ( $s['name'] ?? '' ) ) : '' ) ),
				'path'  => $path,
				'fnode' => isset( $s['fnode'] ) && is_numeric( $s['fnode'] ) ? (int) $s['fnode'] : null,
				'tnode' => isset( $s['tnode'] ) && is_numeric( $s['tnode'] ) ? (int) $s['tnode'] : null,
			);
		}
		if ( $houses && ! $segments ) {
			// the road layer did not answer (ZDH_Geo keeps that quiet); houses with no roads would plan as one walk in no
			// order
			return self::no( $v, 'map_down', 'The road map did not answer. Try again in a minute.' );
		}
		return array(
			'success'   => true,
			'verb'      => $v,
			'holder'    => (bool) $holder,           // whether house numbers and No-Solicit flags are included
			'source'    => (string) ( $res['source'] ?? 'region' ),
			'houses'    => $houses,
			'segments'  => $segments,
			'streets'   => array_values( $by_street ),
			'counts'    => array( 'houses' => count( $houses ), 'segments' => count( $segments ), 'no_solicit' => $ns_count ),
			'truncated' => $truncated,
			'envelope'  => $env ?: ( $res['envelope'] ?? null ),
			'zip'       => (string) ( $res['zip'] ?? '' ),
		);
	}
}
