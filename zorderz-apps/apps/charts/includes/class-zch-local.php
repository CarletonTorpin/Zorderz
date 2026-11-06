<?php
/**
 * Our own data first, for the canvassing app.
 *
 * The region's address points, its ZIP areas, census figures by ZIP and the fire history live
 * on the Charts hub (its region pack). The canvassing app asks through one filter and falls back
 * to public websites on null:
 *
 *   apply_filters('zdh_local_lookup', null, 'address_points', $q, $uid)   one address-point question in a closed shape
 *        $q: name / like / num / zip / count_only / envelope [xmin, ymin, xmax, ymax] / limit
 *        -> ['features' => [{attributes:{…}, geometry:{x, y}}], 'exceeded' => bool] | ['count' => n] | null
 *        ('county_points' is accepted as another name for the same kind.)
 *   apply_filters('zdh_local_lookup', null, 'place', ['lat' => .., 'lng' => ..], $uid)
 *        -> ['zip', 'town', 'zip_facts' => [...], 'acs_year', 'fires', 'last_fire_year', 'miles_to_coast'] | null
 *
 * The hub answers from the region's layer kept in the layer's own words, so a question is passed
 * on exactly as the canvassing app wrote it, never "cleaned" into a different one (a name
 * outside the layer's own letters is refused, and the public website is asked).
 *
 * Public address and census data only (no customer, no store figure). As the person asking
 * (their Charts run_v2 access, else, for someone who holds the canvassing app without Charts,
 * the narrowest block there is: public data only); never the shared device; skipped for 5
 * minutes after the hub did not answer, so a slow import or plan never waits 20 s a question.
 *
 * @package Zorderz\Charts
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZCH_Local {
	const SKIP_AFTER_FAIL = 300;
	const OFF_FOR         = 600;        // the hub has no copy of the layer yet: do not ask it for 10 minutes.
	const CANVASS_APP     = 'doorhangers';

	/** The hub call for one spec; the reply's result when its status is $want, else null. */
	private static function hub( array $spec, int $uid, string $want ) {
		$uid = $uid ? $uid : get_current_user_id();
		if ( $uid <= 0 ) {
			return null;
		}
		// Its own switch: a slow answer here never turns Charts' own prospecting off.
		$last = (int) get_option( 'zch_local_fail', 0 );
		if ( $last && time() - $last < self::SKIP_AFTER_FAIL ) {
			return null;
		}
		$off = (int) get_option( 'zch_local_off', 0 );
		if ( $off && time() - $off < self::OFF_FOR ) {
			return null;
		}
		$client = ZCH_Hub_Client::from_config();
		if ( ! $client ) {
			return null;
		}
		$b = ZCH_Access::block( $uid, 'ui' );
		if ( ! in_array( 'run_v2', $b['ops'], true ) || array() === $b['scope'] ) {   // no Charts, or Charts with no initials.
			$b = self::public_block( $uid );
			if ( ! $b ) {
				return null;
			}
		}
		$reply = $client->call( 'run_v2', array( 'spec' => $spec, 'access' => $b ), 'wp_' . $uid, wp_date( 'Y-m-d' ) );
		ZCH_Store::audit( 'run_v2', ! empty( $reply['ok'] ), $reply['error']['code'] ?? null, null, $client->last_ms, null, 'ui' );
		if ( 'hub_unavailable' === ( $reply['error']['code'] ?? '' ) ) {
			update_option( 'zch_local_fail', time(), false );
			return null;
		}
		$status = $reply['data']['status'] ?? '';
		if ( ! empty( $reply['ok'] ) && ( 'invalid' === $status || 'no_copy' === ( $reply['data']['reason'] ?? '' ) ) ) {
			update_option( 'zch_local_off', time(), false );   // a hub without this layer, or no copy yet.
			return null;
		}
		if ( empty( $reply['ok'] ) || $status !== $want ) {
			return null;
		}
		return (array) ( $reply['data']['result'] ?? array() );
	}

	/** Does this person hold the canvassing app? */
	private static function holds_canvassing( int $uid ): bool {
		if ( function_exists( 'zdh_user_can_use' ) ) {
			return (bool) zdh_user_can_use( $uid );
		}
		return class_exists( 'ZDZ_Plugin_API' ) && method_exists( 'ZDZ_Plugin_API', 'user_can_access_app' )
			&& ZDZ_Plugin_API::user_can_access_app( $uid, self::CANVASS_APP );
	}

	/**
	 * Address points and census figures by ZIP are public, and the person who plans most routes
	 * may hold the canvassing app without Charts. For such a person the hub is asked with the
	 * narrowest block there is: no scope, no money, no names, no line items; the store's figures
	 * stay out of reach (and only the address and place specs this class writes reach the hub on
	 * it). Never the shared device, never someone who does not hold the canvassing app.
	 */
	public static function public_block( int $uid ) {
		$user = get_userdata( $uid );
		if ( ! $user || 'zdz_general' === ZCH_Access::governing_role( $user ) || ZCH_Access::is_kiosk( $uid ) ) {
			return null;
		}
		if ( ! self::holds_canvassing( $uid ) ) {
			return null;
		}
		return array(
			'caller'      => 'ui',
			'ops'         => array( 'health', 'run_v2' ),
			'scope'       => array(),
			'money'       => false,
			'by_person'   => false,
			'line_items'  => 'none',
			'kiosk'       => false,
			'accounts'    => false,
			'invoices'    => 'none',
			'me'          => null,
			'compare'     => false,
			'public_only' => true,   // the hub then allows the address and place specs only.
		);
	}

	/** One address-point question, cleaned to the closed shape the hub reads. */
	public static function clean_points_query( $q ): ?array {
		if ( ! is_array( $q ) ) {
			return null;
		}
		$out = array();
		if ( isset( $q['name'] ) ) {
			// Passed on as written (the layer compares it exactly); anything outside its letters: the public website.
			if ( ! is_string( $q['name'] ) || ! preg_match( "/^[A-Z0-9'][A-Z0-9' \-]{0,79}$/D", $q['name'] ) ) {
				return null;
			}
			$out['name'] = $q['name'];
			if ( ! empty( $q['like'] ) ) {
				$out['like'] = true;
			}
		}
		if ( isset( $q['num'] ) ) {
			if ( ! is_numeric( $q['num'] ) || (int) $q['num'] <= 0 ) {
				return null;
			}
			$out['num'] = (int) $q['num'];
		}
		if ( isset( $q['zip'] ) ) {
			if ( ! is_string( $q['zip'] ) || ! preg_match( '/^\d{5}$/D', $q['zip'] ) ) {
				return null;
			}
			$out['zip'] = $q['zip'];
		}
		if ( isset( $q['envelope'] ) ) {
			$e = $q['envelope'];
			if ( ! is_array( $e ) || 4 !== count( $e ) ) {
				return null;
			}
			$e = array_map( 'floatval', array_values( $e ) );
			if ( $e[2] < $e[0] || $e[3] < $e[1] || $e[2] - $e[0] > 0.2 || $e[3] - $e[1] > 0.2 ) {
				return null;
			}
			$out['envelope'] = $e;
		}
		if ( ! empty( $q['count_only'] ) ) {
			$out['count_only'] = true;
		}
		if ( isset( $q['limit'] ) && is_numeric( $q['limit'] ) ) {
			$out['limit'] = max( 1, min( 2000, (int) $q['limit'] ) );
		}
		if ( ! isset( $out['name'] ) && ! isset( $out['zip'] ) && ! isset( $out['envelope'] ) ) {
			return null;
		}
		return $out;
	}

	/** One address-point feature, only the layer's public fields, bounded. */
	public static function clean_feature( $f ): ?array {
		if ( ! is_array( $f ) || ! is_array( $f['attributes'] ?? null ) ) {
			return null;
		}
		$a = $f['attributes'];
		$g = (array) ( $f['geometry'] ?? array() );
		$s = function ( $k, $len ) use ( $a ) {
			return is_scalar( $a[ $k ] ?? null ) ? substr( (string) $a[ $k ], 0, $len ) : '';
		};
		return array(
			'attributes' => array(
				'num'       => is_numeric( $a['num'] ?? null ) ? (int) $a['num'] : null,
				'frac'      => $s( 'frac', 8 ),
				'predir'    => $s( 'predir', 4 ),
				'name'      => $s( 'name', 80 ),
				'suffix'    => $s( 'suffix', 8 ),
				'postdir'   => $s( 'postdir', 4 ),
				'unit'      => $s( 'unit', 16 ),
				'zip'       => $s( 'zip', 5 ),
				'community' => $s( 'community', 60 ),
				'juris'     => $s( 'juris', 8 ),
				'segment'   => is_numeric( $a['segment'] ?? null ) ? (int) $a['segment'] : null,
				'parcel'    => $s( 'parcel', 20 ),
				'kind'      => $s( 'kind', 30 ),
			),
			'geometry'   => array(
				'x' => is_numeric( $g['x'] ?? null ) ? (float) $g['x'] : null,
				'y' => is_numeric( $g['y'] ?? null ) ? (float) $g['y'] : null,
			),
		);
	}

	/** apply_filters('zdh_local_lookup', null, $kind, $args, $uid): see the header. */
	public static function lookup( $null, $kind = '', $args = array(), $uid = 0 ) {
		if ( null !== $null ) {
			return $null;
		}
		$uid = (int) $uid;
		if ( 'address_points' === $kind || 'county_points' === $kind ) {
			$q = self::clean_points_query( $args );
			if ( ! $q ) {
				return null;
			}
			$r = self::hub( array( 'points' => $q ), $uid, 'points' );
			if ( null === $r ) {
				return null;
			}
			if ( array_key_exists( 'count', $r ) ) {
				return array( 'count' => (int) $r['count'] );
			}
			$out = array();
			foreach ( array_slice( (array) ( $r['features'] ?? array() ), 0, 2000 ) as $f ) {
				$c = self::clean_feature( $f );
				if ( $c ) {
					$out[] = $c;
				}
			}
			return array( 'features' => $out, 'exceeded' => ! empty( $r['exceeded'] ) );
		}
		if ( 'place' === $kind ) {
			if ( ! is_numeric( $args['lat'] ?? null ) || ! is_numeric( $args['lng'] ?? null ) ) {
				return null;
			}
			$r = self::hub( array( 'place' => array( 'lat' => (float) $args['lat'], 'lng' => (float) $args['lng'] ) ), $uid, 'place' );
			if ( null === $r ) {
				return null;
			}
			$num   = function ( $v ) {
				return is_numeric( $v ) ? $v + 0 : null;
			};
			$facts = array();
			foreach ( (array) ( $r['zip_facts'] ?? array() ) as $k => $v ) {
				if ( is_string( $k ) && preg_match( '/^[a-z0-9_]{1,30}$/', $k ) && is_numeric( $v ) ) {
					$facts[ $k ] = $v + 0;
				}
			}
			return array(
				'zip'            => substr( preg_replace( '/[^0-9]/', '', (string) ( $r['zip'] ?? '' ) ), 0, 5 ),
				'town'           => is_scalar( $r['town'] ?? null ) ? substr( (string) $r['town'], 0, 60 ) : '',
				'zip_facts'      => $facts,
				'acs_year'       => $num( $r['acs_year'] ?? null ),
				'fires'          => $num( $r['fires'] ?? null ),
				'last_fire_year' => $num( $r['last_fire_year'] ?? null ),
				'miles_to_coast' => $num( $r['miles_to_coast'] ?? null ),
			);
		}
		return null;
	}
}

if ( function_exists( 'add_filter' ) ) {
	add_filter( 'zdh_local_lookup', array( 'ZCH_Local', 'lookup' ), 10, 4 );
}
