<?php
/**
 * ZRP_Snap -- which street segment a note was said on.
 *
 * On the SERVER, when a note arrives: the address map's houses and roads around where it was said come from
 * Doorhangers' read verb `doorhangers.area_houses`, asked through the registry AS THE PERSON -- never
 * from Doorhangers' tables or REST routes. The nearest road segment wins, with segments that
 * cross the direction of travel set back (at a corner the cross street is often nearer than the one being driven);
 * "this side" is the driver's right unless "left" was said; "this house" is the nearest mapped house on that side,
 * and its number is kept only when Doorhangers gave one (holders of Doorhangers; a number only for "this house").
 *
 * When the verb is missing (Doorhangers off), refused, or the address map does not answer, the note
 * is kept where it was said with snapped = 0 and the Review list says the street is not known yet. Never a guess.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZRP_Snap {

	const VERB      = 'doorhangers.area_houses';
	const RADIUS_M  = 150;
	const MAX_SEG_M = 60;    // further than this from every road: not on a street
	const HOUSE_M   = 45;

	/** Bearing (0 = north, clockwise) from a to b, [lat, lng]. */
	public static function bearing( $a, $b ) {
		$cos = cos( deg2rad( $a[0] ) );
		$d   = rad2deg( atan2( ( $b[1] - $a[1] ) * $cos, $b[0] - $a[0] ) );
		return fmod( $d + 360.0, 360.0 );
	}

	/** Nearest point of a path: [distance m, segment index, signed side (+1 left / -1 right of the path's direction), bearing]. */
	public static function nearest( $lat, $lng, array $path ) {
		$best = array( INF, 0, 0, 0.0 );
		$cos  = cos( deg2rad( $lat ) );
		for ( $i = 0; $i < count( $path ) - 1; $i++ ) {
			$a  = $path[ $i ];
			$b  = $path[ $i + 1 ];
			$px = ( $lng - $a[1] ) * $cos * 111320; $py = ( $lat - $a[0] ) * 110540;
			$bx = ( $b[1] - $a[1] ) * $cos * 111320; $by = ( $b[0] - $a[0] ) * 110540;
			$l2 = $bx * $bx + $by * $by;
			$u  = $l2 ? max( 0, min( 1, ( $px * $bx + $py * $by ) / $l2 ) ) : 0;
			$dx = $px - $u * $bx; $dy = $py - $u * $by;
			$d  = sqrt( $dx * $dx + $dy * $dy );
			if ( $d < $best[0] ) {
				$best = array( $d, $i, ( $bx * $py - $by * $px ) >= 0 ? 1 : -1, self::bearing( $a, $b ) );
			}
		}
		return $best;
	}

	/** The smallest angle between two bearings, ignoring direction (0..90). */
	public static function cross_angle( $b1, $b2 ) {
		$d = fmod( abs( $b1 - $b2 ), 180.0 );
		return $d > 90 ? 180 - $d : $d;
	}

	/**
	 * Snap one note. $p: lat, lng, heading (deg|null), scope, side ('left'|'right'|''). Returns the fields to save:
	 * snapped, street, street_key, zip, city, seg_id, side ('L'|'R'|''), house_number, plus 'why' when not snapped.
	 */
	public static function snap( array $p ) {
		$out = array( 'snapped' => 0, 'street' => '', 'street_key' => '', 'zip' => '', 'city' => '', 'seg_id' => null, 'side' => '', 'house_number' => '', 'why' => '' );
		if ( ! class_exists( 'ZDZ_Capabilities' ) || ! ZDZ_Capabilities::get( self::VERB ) ) {
			$out['why'] = 'no_doorhangers';
			return $out;
		}
		$a = ZDZ_Capabilities::invoke( self::VERB, array( 'near' => array( 'lat' => (float) $p['lat'], 'lng' => (float) $p['lng'] ), 'radius_m' => self::RADIUS_M ) );
		if ( ! empty( $a['denied'] ) ) { // the registry answers a refusal with success true + denied
			$out['why'] = 'refused';
			return $out;
		}
		if ( empty( $a['success'] ) ) {
			$out['why'] = 'map_down';
			return $out;
		}
		$lat  = (float) $p['lat'];
		$lng  = (float) $p['lng'];
		$head = isset( $p['heading'] ) && is_numeric( $p['heading'] ) ? fmod( (float) $p['heading'] + 360.0, 360.0 ) : null;
		$best = null;
		foreach ( (array) ( $a['segments'] ?? array() ) as $s ) {
			if ( empty( $s['path'] ) || count( $s['path'] ) < 2 ) {
				continue;
			}
			$n     = self::nearest( $lat, $lng, $s['path'] );
			$score = $n[0];
			if ( null !== $head && self::cross_angle( $head, $n[3] ) > 50 ) {
				$score += 25; // a cross street at a corner
			}
			if ( null === $best || $score < $best['score'] ) {
				$best = array( 'score' => $score, 'dist' => $n[0], 'seg' => $s, 'side' => $n[2], 'bearing' => $n[3] );
			}
		}
		if ( ! $best || $best['dist'] > self::MAX_SEG_M ) {
			$out['why'] = 'no_street';
			return $out;
		}
		$seg = $best['seg'];
		$out['snapped']    = 1;
		$out['seg_id']     = isset( $seg['id'] ) ? (int) $seg['id'] : null;
		$out['street']     = (string) ( $seg['name'] ?? '' );
		$out['street_key'] = (string) ( $seg['key'] ?? '' );
		// the ZIP and town of the map's houses on this segment (the most common one)
		$on = array_values( array_filter( (array) ( $a['houses'] ?? array() ), function ( $h ) use ( $seg ) { return isset( $h['seg'] ) && (int) $h['seg'] === (int) ( $seg['id'] ?? -1 ); } ) );
		if ( ! $on ) {
			$on = array_values( array_filter( (array) ( $a['houses'] ?? array() ), function ( $h ) use ( $out ) { return ( $h['street_key'] ?? '' ) === $out['street_key']; } ) );
		}
		$zips = array();
		$city = array();
		foreach ( $on as $h ) {
			if ( ! empty( $h['zip'] ) ) { $zips[ $h['zip'] ] = ( $zips[ $h['zip'] ] ?? 0 ) + 1; }
			if ( ! empty( $h['city'] ) ) { $city[ $h['city'] ] = ( $city[ $h['city'] ] ?? 0 ) + 1; }
		}
		arsort( $zips );
		arsort( $city );
		$out['zip']  = $zips ? (string) array_key_first( $zips ) : (string) ( $a['zip'] ?? '' );
		$out['city'] = $city ? (string) array_key_first( $city ) : '';
		// which side: the one said, else the driver's right (the curb in view). +1 = left of the segment's direction.
		$scope = (string) ( $p['scope'] ?? 'here' );
		if ( 'side' === $scope || 'house' === $scope ) {
			$diff = null === $head ? 0.0 : fmod( $head - $best['bearing'] + 720.0, 360.0 );
			$with = $diff < 90 || $diff > 270; // travelling the way the map drew the segment (no heading: assume so)
			$want_right = 'left' !== (string) ( $p['side'] ?? '' );
			// travelling with the segment's direction, the driver's right is the segment's right (-1); against it, its left
			$seg_side = $with ? ( $want_right ? -1 : 1 ) : ( $want_right ? 1 : -1 );
			$out['side'] = 1 === $seg_side ? 'L' : 'R';
			if ( 'house' === $scope ) {
				$hb = null;
				foreach ( $on as $h ) {
					$d = ZRP_DB::dist_m( $lat, $lng, (float) $h['lat'], (float) $h['lng'] );
					$n = self::nearest( (float) $h['lat'], (float) $h['lng'], $seg['path'] );
					if ( $d <= self::HOUSE_M && $n[2] === $seg_side && ( null === $hb || $d < $hb[0] ) ) {
						$hb = array( $d, $h );
					}
				}
				if ( $hb && isset( $hb[1]['number'] ) ) {
					$out['house_number'] = mb_substr( (string) $hb[1]['number'] . ( ! empty( $hb[1]['unit'] ) ? ' #' . $hb[1]['unit'] : '' ), 0, 20 );
				}
			}
		}
		return $out;
	}
}
