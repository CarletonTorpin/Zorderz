<?php
/**
 * ZDH_Plan -- a route planned for someone, from outside the app (the chat, the Route Planner).
 *
 * What a planned route from here IS: an ordinary planned drop (status `planned`, method `map`) whose houses are the
 * address map's points on the streets named -- never a street the map does not have, never a house with a No
 * Soliciting sign -- with three more facts:
 *
 *   assigned_uid   who is to walk it (the deliverer); 0 = nobody yet
 *   due_by         the day it should be done by
 *   hours          the hours of the day it can be walked ("9-11,15-18", 24-hour), because it gets dark
 *
 * Who may plan for whom: anyone who holds the app may plan a route for THEMSELVES; only the admin tier may assign one
 * to someone else. The asker is always the session, never a field in the request.
 *
 * Two steps, like every write the chat makes: preview() resolves everything on the server -- the person, the streets,
 * the houses -- and keeps the resolved route under a token for 10 minutes; create() writes the drop from THAT, when a
 * person has pressed the button. Nothing a later message says changes what was shown.
 *
 * Nothing here names a customer: a route is streets and house counts.
 *
 * The preview takes three more things, all optional, so a street-names-only call reads as it always did:
 *
 *   source   who planned it, from a closed list (SOURCES): 'chat' (the default) or 'routeplanner'. Saved as plan_source
 *            and analysis.planned_from.
 *   walks    the planner's walks: a list of walks, each a list of house keys in walking order. A key is the address
 *            map's house as Doorhangers keys it -- "<number>|<unit>|<street key>|<zip>" (house_key(), what
 *            doorhangers.area_houses hands its holders). Checked on the SERVER against the map's points on the streets
 *            named, never trusted from the browser: a key the map does not have on those streets is dropped and said on
 *            the card. So a drawn or edited route can cover part of a street (coverage 'partial', with its lowest and
 *            highest numbers), arrives in the planner's order (walk_order 1, 2, 3 ...: the walk view uses it as is),
 *            and may be more than one walk (walk 1, 2 ...; at most MAX_WALKS).
 *   streets  still required with walks: the map is asked by street name, and the 12-street and 400-house limits hold
 *            either way.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZDH_Plan {

	/** "To be done": orange (Okabe-Ito). Vermilion #D55E00 stays the kit's "needs you". */
	const COLOR      = '#E69F00';
	const TOKEN_TTL  = 600;    // 10 minutes to press the button (the chat's pending action expires at 600 s too)
	const MAX_STREETS = 12;
	const MAX_HOUSES  = 400;   // a route the chat may make in one press; more is two routes (and 400 rows is one request)
	const SOURCES     = array( 'chat', 'routeplanner' ); // who planned it (plan_source); a closed list
	const MAX_WALKS   = 10;    // a planner's route is at most this many walks

	/**
	 * the address map's house as a route key: "<number>|<unit>|<street key>|<zip>", lower case. The ZIP is part of
	 * it: a street can cross a ZIP line, and the same street name exists in more than one town.
	 */
	/**
	 * Minutes a planned house takes, for a route's time estimate: the filter `zdh_plan_minutes_per_house` (rules.yml
	 * `canvassing.plan_minutes_per_house`), else the app's own "houses per hour" setting.
	 */
	public static function min_per_house() {
		$s    = function_exists( 'zdh_get_settings' ) ? zdh_get_settings() : array( 'walk_rate' => 40 );
		$rate = max( 5, (int) ( $s['walk_rate'] ?? 40 ) );
		$m    = function_exists( 'apply_filters' ) ? apply_filters( 'zdh_plan_minutes_per_house', 60 / $rate ) : 60 / $rate;
		return is_numeric( $m ) && $m > 0 ? min( 30.0, (float) $m ) : 60 / $rate;
	}

	public static function house_key( $number, $unit, $street_key, $zip = '' ) {
		$zip = substr( preg_replace( '/[^0-9]/', '', (string) $zip ), 0, 5 );
		return strtolower( trim( (string) $number ) . '|' . trim( (string) $unit ) . '|' . trim( (string) $street_key ) . '|' . $zip );
	}

	/** The ZIP a route key names ('' when none). */
	public static function key_zip( $key ) {
		$p = explode( '|', (string) $key );
		return ( 4 === count( $p ) && preg_match( '/^\d{5}$/', $p[3] ) ) ? $p[3] : '';
	}

	/**
	 * the planner's walks as sent -> [ [key, key, ...], ... ] (plain strings, bounded), or null when none were sent.
	 * A key that appears twice keeps its first place. False when something was sent that is not a list of lists.
	 * $skipped counts keys that were not readable strings.
	 */
	public static function clean_walks( $raw, &$skipped = 0 ) {
		$skipped = 0;
		if ( null === $raw || '' === $raw || array() === $raw ) {
			return null;
		}
		if ( ! is_array( $raw ) ) {
			return false;
		}
		$out  = array();
		$seen = array();
		$n    = 0;
		foreach ( array_values( $raw ) as $w ) {
			if ( ! is_array( $w ) ) {
				return false;
			}
			$keys = array();
			foreach ( array_values( $w ) as $k ) {
				if ( ! is_string( $k ) || '' === trim( $k ) || strlen( $k ) > 160 ) {
					$skipped++;
					continue;
				}
				$k = strtolower( trim( $k ) );
				if ( isset( $seen[ $k ] ) ) {
					continue;
				}
				$seen[ $k ] = true;
				$keys[]     = $k;
				if ( ++$n > self::MAX_HOUSES * 2 ) {
					break 2; // far past the cap: the count check below refuses it
				}
			}
			if ( $keys ) {
				$out[] = $keys;
			}
		}
		return $out;
	}

	public static function init() {
		add_filter( 'zdh_plan_preview', array( __CLASS__, 'preview_filter' ), 10, 3 );
		add_filter( 'zdh_plan_create', array( __CLASS__, 'create_filter' ), 10, 3 );
		add_filter( 'zdh_routes_list', array( __CLASS__, 'list_filter' ), 10, 3 );
	}

	/* ------------------------------------------------------------------ the seams (filters) */

	/** apply_filters( 'zdh_plan_preview', null, $uid, $args ) → the preview array (see preview()). */
	public static function preview_filter( $null, $uid, $args ) {
		return self::preview( is_array( $args ) ? $args : array(), (int) $uid );
	}

	/** apply_filters( 'zdh_plan_create', null, $uid, $token ) → the result array (see create()). */
	public static function create_filter( $null, $uid, $token ) {
		return self::create( (string) $token, (int) $uid );
	}

	/** apply_filters( 'zdh_routes_list', null, $uid, $scope ) → rows (see list_routes()). */
	public static function list_filter( $null, $uid, $scope ) {
		return self::list_routes( (int) $uid, (string) $scope );
	}

	/* ------------------------------------------------------------------ preview */

	/**
	 * Resolve a route request on the server. $args (all from the asker's words, normalized by the caller):
	 *   streets   string[]  street names to cover (the address map's points on each)
	 *   city      string    the town, if said
	 *   zip       string    the ZIP, if said
	 *   for       string    '' | 'me' | a person's name or initials
	 *   due_by    string    Y-m-d
	 *   hours     string    "9-11, 15-18" or "9-11am or 3-6pm" (parsed; stored 24-hour)
	 *   piece     string    the hanger type (free text until the marketing register exists)
	 *   notes     string
	 *   houses    int       how many houses the asker had in mind (a cap; 0 = all on the streets)
	 *
	 * Returns ['ok' => true, 'token', 'summary' => [...]] or ['ok' => false, 'reason' => code, 'message' => words].
	 */
	public static function preview( array $args, int $uid ) {
		$fail = function ( $reason, $msg ) { return array( 'ok' => false, 'reason' => $reason, 'message' => $msg ); };
		if ( $uid <= 0 || ! zdh_user_can_use( $uid ) ) {
			return $fail( 'no_access', 'Doorhangers is not on your account.' );
		}
		// 1. who walks it
		$who = self::resolve_person( (string) ( $args['for'] ?? '' ), $uid );
		if ( ! $who['ok'] ) {
			return $fail( $who['reason'], $who['message'] );
		}
		if ( (int) $who['id'] !== $uid && ! zdh_user_is_admin( $uid ) ) {
			return $fail( 'not_admin', 'Only an administrator can assign a route to someone else. I can plan it for you instead.' );
		}
		// who planned it, and the planner's own walks (both optional)
		$source = strtolower( trim( (string) ( $args['source'] ?? '' ) ) );
		if ( '' === $source ) {
			$source = 'chat';
		}
		if ( ! in_array( $source, self::SOURCES, true ) ) {
			return $fail( 'bad_source', 'That route came from somewhere Doorhangers does not know.' );
		}
		$skipped  = 0;
		$walks_in = self::clean_walks( $args['walks'] ?? null, $skipped );
		if ( false === $walks_in ) {
			return $fail( 'bad_walks', 'The planner\'s walks could not be read. Plan the route again.' );
		}
		if ( is_array( $walks_in ) && ! $walks_in ) {
			return $fail( 'no_houses', 'The planner sent no houses. Pick some houses on the map first.' );
		}
		if ( is_array( $walks_in ) && count( $walks_in ) > self::MAX_WALKS ) {
			return $fail( 'too_many_walks', 'That is ' . count( $walks_in ) . ' walks -- more than one route (' . self::MAX_WALKS . ' at most). Send fewer walks.' );
		}
		if ( is_array( $walks_in ) && array_sum( array_map( 'count', $walks_in ) ) > self::MAX_HOUSES ) {
			return $fail( 'too_many', 'That is more than ' . self::MAX_HOUSES . ' houses -- more than one route. Send fewer houses.' );
		}
		// 2. the streets
		$streets = array();
		$seen    = array();
		foreach ( (array) ( $args['streets'] ?? array() ) as $s ) {
			$s = trim( preg_replace( '/\s+/', ' ', sanitize_text_field( (string) $s ) ) );
			if ( '' === $s || mb_strlen( $s ) > 80 ) { continue; }
			$k = class_exists( 'ZDH_DB' ) ? ZDH_DB::street_key( $s ) : strtolower( $s ); // "Example Dr" and "Example Drive" are one street
			if ( '' === $k || isset( $seen[ $k ] ) ) { continue; }
			$seen[ $k ] = true;
			$streets[]  = array( 'name' => $s, 'coverage' => 'full', 'from' => '', 'to' => '' );
			if ( count( $streets ) >= self::MAX_STREETS ) {
				if ( is_array( $walks_in ) && count( (array) $args['streets'] ) > self::MAX_STREETS ) {
					// a planner's walks on a 13th street would be dropped as "not on the address map"
					return $fail( 'too_many_streets', 'That route is on more than ' . self::MAX_STREETS . ' streets -- more than one route. Send fewer streets.' );
				}
				break;
			}
		}
		if ( ! $streets ) {
			return $fail( 'no_streets', 'Which streets? Name one or more streets (and the town or ZIP) and I will plan the route from the address map\'s houses on them.' );
		}
		$zip  = preg_replace( '/[^0-9]/', '', (string) ( $args['zip'] ?? '' ) );
		$city = sanitize_text_field( (string) ( $args['city'] ?? '' ) );
		$res  = ZDH_Geo::find( $streets, array(), array( 'zip' => $zip, 'city' => $city ) );
		if ( is_wp_error( $res ) ) {
			return $fail( 'map_down', 'The address map did not answer (' . $res->get_error_message() . '). Try again in a minute.' );
		}
		// the map is asked one ZIP per street, so a planner's walk across a ZIP line asks again
		// for each other ZIP its keys name (at most three), and the answers are merged
		if ( is_array( $walks_in ) && 'region' === (string) ( $res['source'] ?? 'region' ) ) {
			$want = array();
			foreach ( $walks_in as $w ) { foreach ( $w as $k ) { $kz = self::key_zip( $k ); if ( '' !== $kz ) { $want[ $kz ] = true; } } }
			$have = array();
			foreach ( (array) $res['addresses'] as $a ) { $have[ substr( (string) ( $a['zip'] ?? '' ), 0, 5 ) ] = true; }
			$seg_ids = array();
			foreach ( (array) $res['segments'] as $sg ) { if ( isset( $sg['id'] ) ) { $seg_ids[ $sg['id'] ] = true; } }
			foreach ( array_slice( array_keys( array_diff_key( $want, $have ) ), 0, 3 ) as $more ) {
				$r2 = ZDH_Geo::find( $streets, array(), array( 'zip' => (string) $more ) );
				if ( is_wp_error( $r2 ) ) {
					return $fail( 'map_down', 'The address map did not answer (' . $r2->get_error_message() . '). Try again in a minute.' );
				}
				if ( 'region' !== (string) ( $r2['source'] ?? 'region' ) ) {
					continue;
				}
				$res['addresses'] = array_merge( (array) $res['addresses'], (array) $r2['addresses'] );
				foreach ( (array) $r2['segments'] as $sg ) {
					if ( ! isset( $sg['id'] ) || ! isset( $seg_ids[ $sg['id'] ] ) ) { $res['segments'][] = $sg; if ( isset( $sg['id'] ) ) { $seg_ids[ $sg['id'] ] = true; } }
				}
				foreach ( (array) $r2['streets'] as $i => $so2 ) {
					if ( isset( $res['streets'][ $i ] ) && (int) $so2['count'] > 0 ) {
						$res['streets'][ $i ]['count'] = (int) $res['streets'][ $i ]['count'] + (int) $so2['count'];
						if ( '' === (string) ( $res['streets'][ $i ]['note'] ?? '' ) ) { $res['streets'][ $i ]['note'] = ''; }
					}
				}
			}
		}
		if ( ! ZDH_Geo::plannable( (string) ( $res['source'] ?? '' ) ) ) {
			// outside the regional layer the open map's houses are for the Plan tab's drawing; a planned route is the
			// regional layer's houses only
			return $fail( 'no_houses', 'The address map has no houses on ' . self::join_words( array_column( $streets, 'name' ) ) . ( $city || $zip ? ' in ' . trim( $city . ' ' . $zip ) : '' )
				. '. Check the street names (and the town or ZIP) -- a route is only ever made from streets the address map has.' );
		}
		$found   = array();
		$missing = array();
		$notes   = array();
		foreach ( (array) $res['streets'] as $so ) {
			if ( (int) $so['count'] > 0 ) { $found[] = $so; } else { $missing[] = (string) $so['input']; }
			$note = (string) ( $so['note'] ?? '' );
			if ( (int) $so['count'] > 0 && '' !== $note ) {
				// the map chose among several places or spellings; with no town or ZIP from the asker that is a guess, not a route
				if ( '' === $zip && '' === $city && false !== strpos( $note, '(none given)' ) ) {
					return $fail( 'need_place', 'Which town or ZIP? ' . (string) $so['input'] . ' is in more than one place on the map.' );
				}
				$notes[] = (string) $so['name'] . ': ' . $note;
			}
		}
		$addresses = array();
		$seen_addr = array();
		foreach ( (array) $res['addresses'] as $a ) {
			$ak = strtolower( trim( (string) $a['number'] . '|' . (string) ( $a['unit'] ?? '' ) . '|' . (string) $a['street_key'] ) );
			if ( isset( $seen_addr[ $ak ] ) ) { continue; }
			$seen_addr[ $ak ] = true;
			$addresses[] = $a;
		}
		if ( ! $addresses ) {
			return $fail( 'no_houses', 'The address map has no houses on ' . self::join_words( $missing ) . ( $city || $zip ? ' in ' . trim( $city . ' ' . $zip ) : '' )
				. '. Check the street names (and the town or ZIP) -- a route is only ever made from streets the address map has.' );
		}
		// the safety floor: a house with a No Soliciting sign is never on a route, whoever planned it
		$ns_out = 0;
		if ( class_exists( 'ZDH_NoSolicit' ) ) {
			list( $addresses, $ns_out ) = ZDH_NoSolicit::without( $addresses, ZDH_NoSolicit::keys() );
			if ( $ns_out ) {
				$notes[] = $ns_out . ' ' . ( 1 === $ns_out ? 'house has' : 'houses have' ) . ' a No Soliciting sign noted, so ' . ( 1 === $ns_out ? 'it is' : 'they are' ) . ' left out';
			}
			if ( ! $addresses ) {
				return $fail( 'no_houses', 'Every house on ' . self::join_words( array_column( $streets, 'name' ) ) . ' has a No Soliciting sign noted. No route was made.' );
			}
		}
		// the planner's walks -- only houses the address map has on these streets, in the planner's order
		$walk_of  = array();   // house key => [walk n, order]
		$on_street = array();  // street key => how many houses that street has
		if ( is_array( $walks_in ) ) {
			$by_key = array();
			foreach ( $addresses as $a ) {
				$by_key[ self::house_key( $a['number'], $a['unit'] ?? '', $a['street_key'], $a['zip'] ?? '' ) ] = $a;
				$on_street[ $a['street_key'] ] = ( $on_street[ $a['street_key'] ] ?? 0 ) + 1;
			}
			$keep    = array();
			$dropped = $skipped;
			$wn      = 0;
			foreach ( $walks_in as $w ) {
				$order = 0;
				$any   = false;
				foreach ( $w as $k ) {
					if ( ! isset( $by_key[ $k ] ) ) {
						$dropped++;
						continue;
					}
					if ( ! $any ) {
						$any = true;
						$wn++;
					}
					$walk_of[ $k ] = array( $wn, ++$order );
					$keep[]        = $by_key[ $k ];
				}
			}
			if ( ! $keep ) {
				return $fail( 'no_houses', 'None of the planner\'s houses are on the address map of ' . self::join_words( array_column( $streets, 'name' ) ) . '. Plan the route again.' );
			}
			if ( $dropped ) {
				$notes[] = $dropped . ' ' . ( 1 === $dropped ? 'house' : 'houses' ) . ' the planner sent ' . ( 1 === $dropped ? 'is' : 'are' ) . ' not on the address map of these streets (or ' . ( 1 === $dropped ? 'has' : 'have' ) . ' a No Soliciting sign), so left out';
			}
			$addresses = $keep;
			$kept_streets = array();
			foreach ( $keep as $a ) { $kept_streets[ $a['street_key'] ] = true; }
			$found = array_values( array_filter( $found, function ( $so ) use ( $kept_streets ) { return isset( $kept_streets[ $so['key'] ] ); } ) );
		}
		// 3. a cap, if the asker named a size: keep whole streets in the order named until the cap is passed
		$cap   = is_array( $walks_in ) ? 0 : max( 0, (int) ( $args['houses'] ?? 0 ) ); // the planner's walks ARE the houses
		if ( $cap > 0 && count( $addresses ) > (int) ( $cap * 1.5 ) ) {
			$keep = array();
			$kept_keys = array();
			foreach ( $found as $so ) {
				if ( count( $keep ) >= $cap ) { break; }
				$kept_keys[ $so['key'] ] = true;
				foreach ( $addresses as $a ) {
					if ( $a['street_key'] === $so['key'] ) { $keep[] = $a; }
				}
			}
			if ( $keep ) {
				$addresses = $keep;
				$found     = array_values( array_filter( $found, function ( $so ) use ( $kept_keys ) { return isset( $kept_keys[ $so['key'] ] ); } ) );
				if ( count( $addresses ) > $cap ) {
					$notes[] = 'whole streets kept: ' . count( $addresses ) . ' houses, more than the ' . $cap . ' you asked for';
				}
			}
		}
		if ( count( $addresses ) > self::MAX_HOUSES ) {
			return $fail( 'too_many', 'That is ' . count( $addresses ) . ' houses -- more than one route (' . self::MAX_HOUSES . ' at most). Name fewer streets, or say how many houses you want and I will keep whole streets up to that.' );
		}
		// 4. when
		$due = self::parse_date( (string) ( $args['due_by'] ?? '' ) );
		if ( '' !== (string) ( $args['due_by'] ?? '' ) && null === $due ) {
			return $fail( 'bad_date', 'Which day should it be done by? Give a date like "Oct 27" or "next Tuesday".' );
		}
		$hours = self::parse_hours( (string) ( $args['hours'] ?? '' ) );
		if ( '' !== trim( (string) ( $args['hours'] ?? '' ) ) && '' === $hours ) {
			return $fail( 'bad_hours', 'Which hours? Say them like "9 to 11 or 3 to 6".' );
		}
		// 5. the drop, as the Plan tab would save it
		$names = array_map( function ( $so ) { return (string) $so['name']; }, $found );
		$top_city = self::top_city( $addresses, $city );
		$piece = sanitize_text_field( (string) ( $args['piece'] ?? '' ) );
		if ( '' === $piece ) {
			$types = zdh_list_setting( 'hanger_types' );
			$piece = $types ? (string) $types[0] : 'Standard';
		}
		$center = is_array( $res['center'] ) ? $res['center'] : array( null, null );
		$geo_segs = (array) $res['segments'];
		if ( is_array( $walks_in ) ) {
			// a part-street route draws the road segments its houses are on, not every segment
			// of every street named (nor the other ZIP's)
			$keep_seg = array();
			foreach ( $addresses as $a ) { if ( isset( $a['seg'] ) && null !== $a['seg'] ) { $keep_seg[ (int) $a['seg'] ] = true; } }
			if ( $keep_seg ) {
				$geo_segs = array_values( array_filter( $geo_segs, function ( $sg ) use ( $keep_seg ) { return isset( $sg['id'] ) && isset( $keep_seg[ (int) $sg['id'] ] ); } ) );
			}
		}
		$settings = zdh_get_settings();
		$area     = zdh_area_defaults();
		$drop = array(
			'drop_date'        => $due ?: null,
			'drop_time'        => '',
			'delivered_by'     => (string) $who['name'],
			'delivered_by_uid' => (int) $who['id'],
			'source_app'       => 'doorhangers',
			'hanger_type'      => $piece,
			'service'          => (string) ( $settings['service'] ?? '' ),
			'quantity'         => null,
			'city'             => $top_city,
			'state'            => $area['state'],
			'zip'              => (string) ( $res['zip'] ?: $zip ),
			'county'           => $area['county'],
			'streets_summary'  => mb_substr( implode( ' / ', $names ), 0, 255 ),
			'description'      => 'Residential streets near ' . implode( ' & ', array_slice( $names, 0, 2 ) ) . ( $top_city ? ', ' . $top_city : '' ),
			'notes'            => mb_substr( sanitize_textarea_field( (string) ( $args['notes'] ?? '' ) ), 0, 2000 ),
			'lat'              => $center[0],
			'lng'              => $center[1],
			'marker_color'     => self::COLOR,
			'attachment_ids'   => array(),
			'geometry'         => array( 'segments' => array_map( function ( $s ) { return array( 'id' => $s['id'] ?? null, 'name' => $s['name'] ?? '', 'path' => $s['path'] ?? array() ); }, $geo_segs ), 'region' => null, 'strokes' => array() ),
			'analysis'         => array( 'planned_from' => $source, 'streets_asked' => array_column( $streets, 'name' ), 'streets_missing' => $missing ),
			'status'           => 'planned',
			'hanger_side'      => '',
			'revisit_days'     => isset( $settings['revisit_days'] ) ? (int) $settings['revisit_days'] : 90,
			'method'           => 'map',
			'name'             => mb_substr( ( $top_city ? $top_city . ' ' : '' ) . ( $res['zip'] ? $res['zip'] . ' — ' : '— ' ) . implode( ' & ', array_slice( $names, 0, 2 ) ) . ( count( $names ) > 2 ? ' +' . ( count( $names ) - 2 ) : '' ), 0, 150 ),
			'assigned_uid'     => (int) $who['id'],
			'due_by'           => $due,
			'hours'            => $hours,
			'plan_source'      => $source,
		);
		if ( is_array( $walks_in ) ) {
			$drop['analysis']['houses_sent'] = array_sum( array_map( 'count', $walks_in ) );
			$drop['analysis']['houses_kept'] = count( $addresses );
		}
		$addr_rows = array();
		foreach ( $addresses as $a ) {
			$wo = $walk_of[ self::house_key( $a['number'], $a['unit'] ?? '', $a['street_key'], $a['zip'] ?? '' ) ] ?? array( 1, 0 );
			$addr_rows[] = array(
				'number' => (string) $a['number'], 'unit' => (string) ( $a['unit'] ?? '' ), 'street' => (string) $a['street'],
				'city' => (string) ( $a['city'] ?: $top_city ), 'state' => $area['state'], 'zip' => (string) ( $a['zip'] ?: $drop['zip'] ),
				'full' => trim( $a['number'] . ( ! empty( $a['unit'] ) ? ' #' . $a['unit'] : '' ) . ' ' . $a['street'] . ', ' . ( $a['city'] ?: $top_city ) . ', ' . trim( $area['state'] . ' ' . ( $a['zip'] ?: $drop['zip'] ) ), ', ' ),
				'lat' => $a['lat'], 'lng' => $a['lng'], 'source' => (string) ( $a['source'] ?? 'region' ), 'confidence' => 'verified',
				'seg' => $a['seg'] ?? null, 'apn' => (string) ( $a['apn'] ?? '' ), 'walk' => $wo[0], 'walk_order' => $wo[1],
			);
		}
		$street_rows = array();
		$this_count = function ( $skey ) use ( $addresses ) { $n = 0; foreach ( $addresses as $a ) { if ( $a['street_key'] === $skey ) { $n++; } } return $n; };
		foreach ( $found as $so ) {
			$nums = array();
			foreach ( $addresses as $a ) { if ( $a['street_key'] === $so['key'] ) { $n = (int) $a['number']; if ( $n > 0 ) { $nums[] = $n; } } }
			// part of a street when the planner's walks left some of the map's houses on it out
			$cov = ( is_array( $walks_in ) && $this_count( $so['key'] ) < (int) ( $on_street[ $so['key'] ] ?? 0 ) ) ? 'partial' : 'full';
			$street_rows[] = array( 'street' => (string) $so['name'], 'city' => $top_city, 'zip' => $drop['zip'], 'coverage' => $cov, 'low' => $nums ? min( $nums ) : null, 'high' => $nums ? max( $nums ) : null, 'count' => count( $nums ) );
		}
		$est_min = (int) ceil( count( $addr_rows ) * self::min_per_house() );
		if ( is_array( $walks_in ) ) {
			// one row per planner's walk, the streets each one is mostly on
			$list = array();
			$by_walk = array();
			foreach ( $addr_rows as $r ) { $by_walk[ $r['walk'] ][] = $r; }
			ksort( $by_walk );
			foreach ( $by_walk as $n => $rows ) {
				$sc = array();
				foreach ( $rows as $r ) { $sc[ $r['street'] ] = ( $sc[ $r['street'] ] ?? 0 ) + 1; }
				arsort( $sc );
				$list[] = array( 'n' => (int) $n, 'count' => count( $rows ), 'est_min' => (int) ceil( count( $rows ) * self::min_per_house() ), 'streets' => implode( ', ', array_slice( array_keys( $sc ), 0, 3 ) ), 'status' => 'planned' );
			}
			$drop['walks'] = array( 'size' => max( array_map( function ( $w ) { return $w['count']; }, $list ) ), 'style' => $source, 'per_hour' => (int) round( 60 / self::min_per_house() ), 'list' => $list );
		} else {
			$drop['walks'] = array( 'size' => count( $addr_rows ), 'style' => 'chat', 'per_hour' => (int) round( 60 / self::min_per_house() ), 'list' => array( array( 'n' => 1, 'count' => count( $addr_rows ), 'est_min' => $est_min, 'streets' => implode( ', ', $names ), 'status' => 'planned' ) ) );
		}

		$token = wp_generate_password( 20, false, false );
		set_transient( 'zdh_plan_' . $token, array( 'uid' => $uid, 'who' => $who, 'drop' => $drop, 'addresses' => $addr_rows, 'streets' => $street_rows, 'made' => time() ), self::TOKEN_TTL );

		$summary = array(
			'name'       => $drop['name'],
			'streets'    => $names,
			'missing'    => $missing,
			'houses'     => count( $addr_rows ),
			'est_min'    => $est_min,
			'city'       => $top_city,
			'zip'        => $drop['zip'],
			'for'        => array( 'id' => (int) $who['id'], 'name' => (string) $who['name'], 'is_you' => (int) $who['id'] === $uid ),
			'due_by'     => $due,
			'due_text'   => $due ? date_i18n( 'D, M j', strtotime( $due ) ) : '',
			'hours'      => $hours,
			'hours_text' => self::hours_text( $hours ),
			'piece'      => $piece,
			'notes'      => (string) $drop['notes'],
			'lookup'     => $notes, // what the map had to decide (a spelling, a ZIP, a cap, a No Soliciting sign) -- said on the card
			'center'     => $center,
		);
		if ( 'chat' !== $source || is_array( $walks_in ) ) {
			// only when the planner sent them, so the chat's card reads exactly as before
			$summary['source'] = $source;
			$summary['walks']  = count( $drop['walks']['list'] );
			$summary['partial'] = array_values( array_map( function ( $r ) { return $r['street']; }, array_filter( $street_rows, function ( $r ) { return 'partial' === $r['coverage']; } ) ) );
		}
		return array( 'ok' => true, 'token' => $token, 'summary' => $summary );
	}

	/* ------------------------------------------------------------------ create */

	/** Write the route the preview showed. The asker must be the person who previewed it. */
	public static function create( string $token, int $uid ) {
		$fail = function ( $reason, $msg ) { return array( 'success' => false, 'reason' => $reason, 'message' => $msg ); };
		if ( $uid <= 0 || ! zdh_user_can_use( $uid ) ) {
			return $fail( 'no_access', 'Doorhangers is not on your account.' );
		}
		if ( ! preg_match( '/^[A-Za-z0-9]{8,40}$/', $token ) ) {
			return $fail( 'bad_token', 'That route preview is gone. Ask for it again.' );
		}
		$row = get_transient( 'zdh_plan_' . $token );
		if ( ! is_array( $row ) || (int) ( $row['uid'] ?? 0 ) !== $uid || empty( $row['drop'] ) ) {
			return $fail( 'expired', 'That route preview has expired (10 minutes). Ask for it again and press the button sooner.' );
		}
		delete_transient( 'zdh_plan_' . $token ); // one press makes one route
		$drop = $row['drop'];
		if ( (int) $drop['assigned_uid'] !== $uid && ! zdh_user_is_admin( $uid ) ) {
			return $fail( 'not_admin', 'Only an administrator can assign a route to someone else.' );
		}
		if ( (int) $drop['assigned_uid'] > 0 && ! zdh_user_can_use( (int) $drop['assigned_uid'] ) ) {
			return $fail( 'assignee_no_app', 'That person no longer holds Doorhangers.' );
		}
		$id = ZDH_DB::insert_drop( $drop, (array) $row['addresses'], (array) $row['streets'] );
		if ( ! $id ) {
			return $fail( 'db', 'The route could not be saved.' );
		}
		global $wpdb;
		// insert_drop rotates its own palette; a route to be done is orange, whoever made it
		$wpdb->update( ZDH_DB::t_drops(), array(
			'marker_color' => self::COLOR,
			'assigned_uid' => (int) $drop['assigned_uid'],
			'due_by'       => $drop['due_by'] ?: null,
			'hours'        => (string) $drop['hours'],
			'plan_source'  => (string) $drop['plan_source'],
		), array( 'id' => $id ) );
		do_action( 'zdh_route_planned', $id, $uid, (int) $drop['assigned_uid'] );
		return array(
			'success' => true,
			'id'      => $id,
			'name'    => (string) $drop['name'],
			'houses'  => count( (array) $row['addresses'] ),
			'for'     => $row['who'],
			'due_by'  => (string) ( $drop['due_by'] ?: '' ),
			'hours'   => (string) $drop['hours'],
			'message' => 'Route #' . $id . ' is planned in Doorhangers' . ( ! empty( $row['who']['name'] ) ? ' for ' . $row['who']['name'] : '' ) . ': ' . count( (array) $row['addresses'] ) . ' houses on ' . (string) $drop['streets_summary'] . ( $drop['due_by'] ? ', by ' . date_i18n( 'M j', strtotime( $drop['due_by'] ) ) : '' ) . ( $drop['hours'] ? ', ' . self::hours_text( (string) $drop['hours'] ) : '' ) . '.',
		);
	}

	/* ------------------------------------------------------------------ list */

	/**
	 * Routes to be done and in progress -- counts and streets, never a house or a person's contact.
	 * $scope 'mine' = assigned to $uid; 'all' = everyone's -- for the admin tier; anyone else asking for 'all' gets 'mine'
	 * (the app's own log shows every holder every drop, but a list handed to the chat is the asker's). null when $uid does
	 * not hold the app (the kiosk, a tech without it): a caller then says it has no list, not "no route is planned".
	 */
	public static function list_routes( int $uid, string $scope = 'all' ) {
		global $wpdb;
		if ( $uid <= 0 || ! zdh_user_can_use( $uid ) ) {
			return null;
		}
		if ( 'mine' !== $scope && ! zdh_user_is_admin( $uid ) ) {
			$scope = 'mine';
		}
		$sql = 'SELECT id, name, streets_summary, city, zip, address_count, status, assigned_uid, delivered_by, due_by, hours, drop_date, created_at, created_by, plan_source, method, started_at FROM ' . ZDH_DB::t_drops()
			. " WHERE status IN ('planned','in_progress') AND NOT (method = 'quick' AND status = 'in_progress')";
		if ( 'mine' === $scope ) {
			$sql .= $wpdb->prepare( ' AND assigned_uid = %d', $uid );
		}
		$sql .= ' ORDER BY (due_by IS NULL), due_by ASC, drop_date ASC, id ASC LIMIT 200';
		$rows = (array) $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql is fixed SQL on ZDH_DB::t_drops() plus a prepare()d assigned_uid clause
		$out  = array();
		foreach ( $rows as $r ) {
			$aid = (int) $r['assigned_uid'];
			$u   = $aid ? get_userdata( $aid ) : null;
			$out[] = array(
				'id'            => (int) $r['id'],
				'name'          => (string) $r['name'],
				'streets'       => (string) $r['streets_summary'],
				'city'          => (string) $r['city'],
				'zip'           => (string) $r['zip'],
				'houses'        => (int) $r['address_count'],
				'status'        => (string) $r['status'],
				'assigned_uid'  => $aid,
				'assigned_name' => $u ? (string) $u->display_name : ( $aid ? '' : (string) $r['delivered_by'] ),
				'due_by'        => (string) ( $r['due_by'] ?: '' ),
				'hours'         => (string) $r['hours'],
				'hours_text'    => self::hours_text( (string) $r['hours'] ),
				'planned_for'   => (string) ( $r['drop_date'] ?: '' ),
				'created_at'    => (string) $r['created_at'],
				'started_at'    => (string) ( $r['started_at'] ?: '' ),
				'source'        => (string) $r['plan_source'],
				'est_min'       => (int) ceil( (int) $r['address_count'] * self::min_per_house() ),
			);
		}
		return $out;
	}

	/* ------------------------------------------------------------------ helpers */

	/** '' / me / my → the asker; else a holder of the app by whole display name, first name, last name, login or initials -- exactly one. */
	public static function resolve_person( string $words, int $uid ) {
		$words = sanitize_text_field( $words );
		$w = strtolower( trim( $words, " \t\"'@" ) );
		if ( '' === $w || in_array( $w, array( 'me', 'my', 'myself', 'i', 'self' ), true ) ) {
			$u = get_userdata( $uid );
			return array( 'ok' => true, 'id' => $uid, 'name' => $u ? (string) $u->display_name : '' );
		}
		$hits = array();
		foreach ( zdh_people() as $p ) {
			$name  = strtolower( (string) $p['name'] );
			$first = strtolower( (string) strtok( (string) $p['name'], ' ' ) );
			$u     = get_userdata( (int) $p['id'] );
			$login = ( $u && isset( $u->user_login ) ) ? strtolower( (string) $u->user_login ) : '';
			$parts = preg_split( '/\s+/', $name );
			$last  = count( $parts ) > 1 ? (string) end( $parts ) : '';
			if ( $w === $name || $w === $first || ( '' !== $last && $w === $last ) || ( '' !== $login && $w === $login ) || $w === strtolower( (string) $p['initials'] ) ) {
				$hits[ (int) $p['id'] ] = $p;
			}
		}
		if ( 1 === count( $hits ) ) {
			$p = reset( $hits );
			return array( 'ok' => true, 'id' => (int) $p['id'], 'name' => (string) $p['name'] );
		}
		if ( count( $hits ) > 1 ) {
			return array( 'ok' => false, 'reason' => 'ambiguous', 'message' => 'More than one person is called ' . $words . ' -- ' . implode( ', ', array_map( function ( $p ) { return (string) $p['name']; }, $hits ) ) . '. Which one?' );
		}
		return array( 'ok' => false, 'reason' => 'unknown_person', 'message' => 'I don\'t know ' . $words . ' as someone who holds Doorhangers. Use their name as it appears in the app.' );
	}

	/** Y-m-d within the next year, or null. */
	public static function parse_date( string $s ) {
		$s = trim( $s );
		if ( '' === $s ) { return null; }
		$now = (int) current_time( 'timestamp' ); // the site's clock, not UTC: "tomorrow" at 6 pm is tomorrow
		$ts  = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $s ) ? strtotime( $s . ' 12:00:00', $now ) : strtotime( $s, $now );
		if ( false === $ts ) { return null; }
		$today = strtotime( date( 'Y-m-d', $now ) . ' 00:00:00', $now );
		if ( $ts < $today - DAY_IN_SECONDS && ! preg_match( '/\d{4}/', $s ) ) {
			$ts = strtotime( '+1 year', $ts ); // "Jan 5" said in December means the coming January
		}
		if ( $ts < $today - DAY_IN_SECONDS || $ts > $today + 366 * DAY_IN_SECONDS ) { return null; }
		return date( 'Y-m-d', $ts );
	}

	/**
	 * "9-11, 15-18" / "9 to 11 or 3 to 6" / "9am-11am, 3pm-6pm" → "9-11,15-18" (24-hour, whole hours). '' when nothing
	 * readable. An hour without am/pm below 7 is read as afternoon (nobody hangs door hangers at 3 in the morning).
	 */
	public static function parse_hours( string $s ) {
		$s = strtolower( trim( $s ) );
		if ( '' === $s ) { return ''; }
		$s = preg_replace( array( '/\bnoon\b/u', '/\bmidday\b/u', '/\bbetween\s+/u' ), array( '12pm', '12pm', '' ), $s );
		$out = array();
		if ( preg_match_all( '/(\d{1,2})(?::\d{2})?\s*(am|pm|a\.m\.|p\.m\.)?\s*(?:-|–|—|to|until|till|thru|through|and)\s*(\d{1,2})(?::\d{2})?\s*(am|pm|a\.m\.|p\.m\.)?/u', $s, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $p ) {
				$a = (int) $p[1]; $b = (int) $p[3];
				$ap = str_replace( '.', '', (string) ( $p[2] ?? '' ) ); $bp = str_replace( '.', '', (string) ( $p[4] ?? '' ) );
				$inherit_a = ( '' === $ap && '' !== $bp );
				if ( $inherit_a ) { $ap = $bp; }
				if ( '' === $bp && '' !== $ap ) { $bp = $ap; }
				$to24 = function ( $h, $p ) { if ( 'pm' === $p && $h < 12 ) { return $h + 12; } if ( 'am' === $p && 12 === $h ) { return 0; } if ( '' === $p && $h < 7 ) { return $h + 12; } return $h; };
				$raw_a = $a;
				$a = $to24( $a, $ap ); $b = $to24( $b, $bp );
				if ( $inherit_a && 'pm' === $ap && $a >= $b && $raw_a < $b ) { $a = $raw_a; } // "9 to noon", "10 to 2pm": the start is in the morning
				if ( '' === $bp && $b <= $a && $b + 12 <= 23 ) { $b += 12; }
				if ( $a < 0 || $a > 23 || $b < 1 || $b > 23 || $b <= $a ) { continue; }
				$out[] = $a . '-' . $b;
				if ( count( $out ) >= 3 ) { break; }
			}
		}
		return implode( ',', array_unique( $out ) );
	}

	/** "9-11,15-18" → "9–11 am · 3–6 pm". */
	public static function hours_text( string $h ) {
		if ( '' === $h ) { return ''; }
		$parts = array();
		foreach ( explode( ',', $h ) as $win ) {
			if ( ! preg_match( '/^(\d{1,2})-(\d{1,2})$/', $win, $m ) ) { continue; }
			$a = (int) $m[1]; $b = (int) $m[2];
			$f = function ( $x ) { $p = $x >= 12 ? 'pm' : 'am'; $x = $x % 12; if ( 0 === $x ) { $x = 12; } return array( $x, $p ); };
			list( $fa, $pa ) = $f( $a ); list( $fb, $pb ) = $f( $b );
			$parts[] = $pa === $pb ? $fa . "\xE2\x80\x93" . $fb . ' ' . $pb : $fa . ' ' . $pa . "\xE2\x80\x93" . $fb . ' ' . $pb;
		}
		return implode( " \xC2\xB7 ", $parts );
	}

	private static function top_city( array $addresses, string $fallback ) {
		$cc = array();
		foreach ( $addresses as $a ) { $c = (string) ( $a['city'] ?? '' ); if ( '' !== $c ) { $cc[ $c ] = ( $cc[ $c ] ?? 0 ) + 1; } }
		if ( ! $cc ) { return $fallback; }
		arsort( $cc );
		return (string) array_key_first( $cc );
	}

	private static function join_words( array $items ) {
		$items = array_values( array_filter( array_map( 'strval', $items ) ) );
		if ( ! $items ) { return 'those streets'; }
		if ( 1 === count( $items ) ) { return $items[0]; }
		$last = array_pop( $items );
		return implode( ', ', $items ) . ' or ' . $last;
	}
}

ZDH_Plan::init();
