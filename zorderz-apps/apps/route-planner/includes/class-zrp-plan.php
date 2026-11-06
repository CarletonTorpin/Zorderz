<?php
/**
 * ZRP_Plan -- "Plan N routes": candidate door-hanger walks from a neighborhood ranker, the survey's tags and the
 * forecast, sent to Doorhangers by a press.
 *
 * Who does what (data crosses apps only through read verbs; writes only through the seam and a press):
 *
 *   POST plan         the request (area, date, count, hours, start) -> a neighborhood ranker connected to the platform
 *                     ranks neighborhoods through its read verb (Charts' `charts.prospect` by default; filter
 *                     `zrp_rank_verb`),
 *                     with the re-hang window as exclude.hung_within_days, and the planner's own Open-Meteo call judges
 *                     the day for each neighborhood (ZRP_Weather). Without a ranker, routes are drawn on the map.
 *   POST plan/area    one neighborhood's material, each read as the person through the registry:
 *                       doorhangers.area_houses   the address map's houses (house keys, No-Solicit flags for holders)
 *                                                 and roads
 *                       routeplanner.street_tags  the survey's CONFIRMED tags on those streets (this app's own verb)
 *                       doorhangers.hung_streets  what was hung (or is planned) within the re-hang window
 *                     The page cuts walks from it with Doorhangers' own planWalks (assets/walks.js) and scores them in
 *                     the planner (assets/plan.js): the planner owns the tags; the ranker's ingredient list stays its own.
 *   POST plan/send    a route the person picked, edited or drew -> Doorhangers' preview (filter zdh_plan_preview, source
 *                     'routeplanner', the walks as house keys in walking order). Doorhangers checks every key against
 *                     the address map on the server (never trusted from the browser), keeps 12 streets and 400 houses
 *                     at most, never a No-Solicit house, and holds the resolved route for 10 minutes under a token.
 *   POST plan/create  the press: Doorhangers writes the route (filter zdh_plan_create). Logged here: who pressed, when,
 *                     which route, its houses -- wp_zrp_sends.
 *   GET  plan/sends   this person's recent sends (the admin tier: everyone's).
 *
 * The ranker's contract: args { recipe: { near, how_many, exclude: { hung_within_days }, spread }, app }; answer
 * { success, rows: [ { lat, lon, bounds: [4 numbers], streets: [...], ... } ], notes, ingredients, read_as,
 * data_as_of }. The area said is one of: a ZIP; a town, community or neighborhood name (the ranker's own `near`
 * words); or an address -- a past job's, say -- found on the address map through Doorhangers, whose ZIP the ranker
 * ranks and whose nearest neighborhoods are kept (`near` is a place, not an address).
 *
 * Sales and the admin tier (zrp_user_can_use: never the shared kiosk). Sending needs Doorhangers on the person's
 * account (Doorhangers' own rule). Nothing here names a customer: a route is streets, houses and counts.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZRP_Plan {

	const MAX_COUNT   = 5;
	const MAX_HOURS   = 4.0;
	const MAX_AHEAD   = 60;      // days: a plan further out than this has no forecast and no ranking worth keeping
	const ADDRESS_MI  = 2.0;     // "near an address": the neighborhoods within this many miles, nearest first
	const MAX_WX      = 4;       // forecast calls per plan (cells further apart share the nearest one)
	const MAX_WALKS   = 10;      // as Doorhangers (ZDH_Plan::MAX_WALKS)
	const MAX_HOUSES  = 400;     // as Doorhangers
	const MAX_STREETS = 12;      // as Doorhangers
	const BOX_DEG     = 0.012;   // a neighborhood's padded box is at most this (a ranker's patch is ~0.003 deg)
	const DRAW_DEG    = 0.03;    // a drawn route's box (Doorhangers' own cap for a hand-drawn route is ~0.035 x 0.03)
	const VERB_RANK   = 'charts.prospect';
	const VERB_AREA   = 'doorhangers.area_houses';
	const VERB_HUNG   = 'doorhangers.hung_streets';
	const VERB_TAGS   = 'routeplanner.street_tags';

	/** The ranker's read verb: Charts' `charts.prospect`, or the one a site names (filter `zrp_rank_verb`). */
	public static function rank_verb() {
		$v = function_exists( 'apply_filters' ) ? (string) apply_filters( 'zrp_rank_verb', self::VERB_RANK ) : self::VERB_RANK;
		return preg_match( '/^[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$/', $v ) ? $v : self::VERB_RANK;
	}

	/** Is a neighborhood ranker registered? */
	public static function ranker_ready() {
		return class_exists( 'ZDZ_Capabilities' ) && method_exists( 'ZDZ_Capabilities', 'get' ) && (bool) ZDZ_Capabilities::get( self::rank_verb() );
	}

	/** Houses an hour for a route's time: Doorhangers' own planning pace (ZDH_Plan::min_per_house()). */
	public static function per_hour() {
		if ( class_exists( 'ZDH_Plan' ) && method_exists( 'ZDH_Plan', 'min_per_house' ) ) {
			$m = (float) ZDH_Plan::min_per_house();
			return $m > 0 ? 60 / $m : 40.0;
		}
		return 40.0;
	}

	public static function t_sends() { global $wpdb; return $wpdb->prefix . 'zrp_sends'; }

	public static function create_tables() {
		global $wpdb;
		$c = $wpdb->get_charset_collate();
		dbDelta( 'CREATE TABLE ' . self::t_sends() . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			drop_id bigint(20) unsigned NOT NULL DEFAULT 0,
			assigned_uid bigint(20) unsigned NOT NULL DEFAULT 0,
			houses int NOT NULL DEFAULT 0,
			walks int NOT NULL DEFAULT 1,
			streets varchar(255) NOT NULL DEFAULT '',
			zip varchar(5) NOT NULL DEFAULT '',
			plan_date date NULL,
			hours varchar(20) NOT NULL DEFAULT '',
			kind varchar(10) NOT NULL DEFAULT 'planned',
			score int NULL,
			weather varchar(10) NOT NULL DEFAULT '',
			area varchar(80) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			KEY user_created (user_id, created_at),
			KEY drop_id (drop_id)
		) $c;" );
	}

	public static function routes( $may ) {
		register_rest_route( ZRP_REST::ns(), ZRP_REST::BASE . '/plan', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'rest_plan' ), 'permission_callback' => $may ) );
		register_rest_route( ZRP_REST::ns(), ZRP_REST::BASE . '/plan/area', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'rest_area' ), 'permission_callback' => $may ) );
		register_rest_route( ZRP_REST::ns(), ZRP_REST::BASE . '/plan/send', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'rest_send' ), 'permission_callback' => $may ) );
		register_rest_route( ZRP_REST::ns(), ZRP_REST::BASE . '/plan/create', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'rest_create' ), 'permission_callback' => $may ) );
		register_rest_route( ZRP_REST::ns(), ZRP_REST::BASE . '/plan/sends', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'rest_sends' ), 'permission_callback' => $may ) );
	}

	private static function err( $code, $msg, $status = 400 ) {
		return new WP_Error( $code, $msg, array( 'status' => $status ) );
	}

	/** The site's today (its own clock, not UTC: at 6 pm "tomorrow" is tomorrow). */
	public static function today() {
		return function_exists( 'current_time' ) ? (string) current_time( 'Y-m-d' ) : gmdate( 'Y-m-d' );
	}

	/** A plan date: Y-m-d, today to MAX_AHEAD days ahead; null otherwise. */
	public static function clean_date( $s ) {
		$s = trim( (string) $s );
		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m ) || ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
			return null;
		}
		$today = self::today();
		if ( $s < $today || $s > gmdate( 'Y-m-d', strtotime( $today . ' 12:00:00 UTC' ) + self::MAX_AHEAD * 86400 ) ) {
			return null;
		}
		return $s;
	}

	/**
	 * What kind of area was said: zip | address | place. An address is a house number then a street ("4100 Example Dr",
	 * "4100 Example Dr, Exampleton", "4100 Example Dr 00000").
	 */
	public static function read_area( $raw ) {
		$s = trim( preg_replace( '/\s+/', ' ', sanitize_text_field( (string) $raw ) ) );
		$s = mb_substr( $s, 0, 80 );
		if ( preg_match( '/^(\d{5})(?:[\s,]+[A-Za-z][A-Za-z .\'-]*)?$/', $s, $z ) ) {
			return array( 'kind' => 'zip', 'text' => $z[1], 'zip' => $z[1] ); // "00001 Testville": the ZIP
		}
		$s = trim( preg_replace( '/\s*(?:#|\b(?:apt|unit|ste|suite)\.?\s*)\s*[A-Za-z0-9-]+\s*(?=,|$|\s\d{5})/i', '', $s ) ); // a unit is not part of the street
		// the business's state words ("ST", "State") may stand before the ZIP; never a guess at any two letters ("Dr")
		$words = ( class_exists( 'ZDH_DB' ) && method_exists( 'ZDH_DB', 'state_words' ) ) ? ZDH_DB::state_words() : array();
		$state = $words ? '(?:(?:' . implode( '|', array_map( function ( $w ) { return preg_quote( $w, '/' ); }, $words ) ) . ')\.?\s*)?' : '';
		if ( preg_match( '/^(\d{1,6})\s+([A-Za-z0-9 .\'-]+?)(?:\s*,\s*([A-Za-z .\'-]+?))?(?:[\s,]+' . $state . '(\d{5}))?$/i', $s, $m ) && preg_match( '/[A-Za-z]{2}/', $m[2] ) ) {
			return array( 'kind' => 'address', 'text' => $s, 'number' => $m[1], 'street' => trim( $m[2] ), 'city' => trim( $m[3] ?? '' ), 'zip' => $m[4] ?? '' );
		}
		return array( 'kind' => 'place', 'text' => $s );
	}

	/** A registry read as the signed-in person. A missing verb is null (the caller says which app is missing). */
	private static function ask( $verb, array $args ) {
		if ( ! class_exists( 'ZDZ_Capabilities' ) || ! ZDZ_Capabilities::get( $verb ) ) {
			return null;
		}
		$r = ZDZ_Capabilities::invoke( $verb, $args );
		return is_array( $r ) ? $r : array( 'success' => false, 'error' => 'bad_answer', 'message' => 'No answer.' );
	}

	/** A verb's answer that is not a success, as words for the page (a refusal is success true + denied). */
	private static function failed( $r ) {
		if ( ! is_array( $r ) ) {
			return 'No answer.';
		}
		if ( ! empty( $r['denied'] ) || empty( $r['success'] ) ) {
			return (string) ( $r['message'] ?? ( $r['error'] ?? 'Refused.' ) );
		}
		return '';
	}

	/** Miles between two points. */
	public static function miles( $a_lat, $a_lng, $b_lat, $b_lng ) {
		return ZRP_DB::dist_m( (float) $a_lat, (float) $a_lng, (float) $b_lat, (float) $b_lng ) / 1609.34;
	}

	/**
	 * A neighborhood's box from the ranker's row: its four bounds numbers (whichever order they come in: the two nearest
	 * the row's latitude are latitudes) padded by about 60 m, so the houses facing the patch's edge streets come too.
	 * Null when the row has no usable bounds.
	 */
	public static function envelope_of( array $row ) {
		$b = array_values( array_filter( (array) ( $row['bounds'] ?? array() ), 'is_numeric' ) );
		if ( 4 !== count( $b ) || empty( $row['lat'] ) || empty( $row['lon'] ) ) {
			return null;
		}
		$lat = (float) $row['lat'];
		usort( $b, function ( $x, $y ) use ( $lat ) { return abs( $x - $lat ) <=> abs( $y - $lat ); } );
		$lats = array( (float) $b[0], (float) $b[1] );
		$lngs = array( (float) $b[2], (float) $b[3] );
		$pad_y = 60 / 110540;
		$pad_x = 60 / ( 111320 * max( 0.01, cos( deg2rad( $lat ) ) ) );
		$e = array( 'xmin' => min( $lngs ) - $pad_x, 'ymin' => min( $lats ) - $pad_y, 'xmax' => max( $lngs ) + $pad_x, 'ymax' => max( $lats ) + $pad_y );
		if ( $e['xmax'] - $e['xmin'] > self::BOX_DEG || $e['ymax'] - $e['ymin'] > self::BOX_DEG || $e['xmax'] <= $e['xmin'] || $e['ymax'] <= $e['ymin'] ) {
			return null; // not a patch (about 330 x 280 m, so ~0.0045 deg padded): fall back to the row's streets
		}
		return array_map( function ( $v ) { return round( $v, 6 ); }, $e );
	}

	/* ------------------------------------------------------------------ POST plan */

	public static function rest_plan( WP_REST_Request $req ) {
		$uid   = get_current_user_id();
		$s     = zrp_get_settings();
		$area  = self::read_area( $req->get_param( 'area' ) );
		if ( '' === $area['text'] ) {
			return self::err( 'no_area', 'Where? Give a ZIP, a neighborhood or town, or an address (a past job\'s, say).' );
		}
		$date = self::clean_date( $req->get_param( 'date' ) );
		if ( null === $date ) {
			return self::err( 'bad_date', 'Pick a day from today to ' . self::MAX_AHEAD . ' days ahead.' );
		}
		$count = (int) ( $req->get_param( 'count' ) ?: 3 );
		$count = max( 1, min( self::MAX_COUNT, $count ) );
		$hours = is_numeric( $req->get_param( 'hours' ) ) ? (float) $req->get_param( 'hours' ) : 2.0;
		$hours = max( 0.5, min( self::MAX_HOURS, round( $hours * 2 ) / 2 ) );
		$start = is_numeric( $req->get_param( 'start' ) ) ? (int) $req->get_param( 'start' ) : 10;
		$start = max( 6, min( 18, $start ) );

		// an address (a past job's): found on the address map, so the ranker can rank its ZIP and the planner keep the nearest
		$point = null;
		$near  = $area['text'];
		if ( 'address' === $area['kind'] ) {
			$a = self::ask( self::VERB_AREA, array( 'streets' => array( $area['street'] ), 'zip' => $area['zip'], 'city' => $area['city'] ) );
			if ( null === $a ) {
				return self::err( 'no_doorhangers', 'Finding an address needs Doorhangers on this site. Give the ZIP or the neighborhood instead.', 503 );
			}
			if ( '' !== ( $why = self::failed( $a ) ) ) {
				return self::err( 'address', 'That address could not be found: ' . $why );
			}
			$hits = array();
			foreach ( (array) ( $a['houses'] ?? array() ) as $h ) {
				$num = isset( $h['number'] ) ? (string) $h['number'] : '';
				if ( '' !== $num && ltrim( $num, '0' ) === ltrim( $area['number'], '0' ) ) {
					$hits[] = $h;
				}
			}
			// the map may answer for another town's street of that name, or a near spelling; the house
			// found must be in the town and ZIP typed, and one street in two ZIPs is a question, not a guess
			$town = strtolower( trim( $area['city'] ) );
			$miss = array();
			$hits = array_values( array_filter( $hits, function ( $h ) use ( $area, $town, &$miss ) {
				$ok = ( '' === $area['zip'] || (string) ( $h['zip'] ?? '' ) === $area['zip'] ) && ( '' === $town || strtolower( (string) ( $h['city'] ?? '' ) ) === $town );
				if ( ! $ok ) { $miss[] = trim( ( $h['city'] ?? '' ) . ' ' . ( $h['zip'] ?? '' ) ); }
				return $ok;
			} ) );
			$zips = array_unique( array_map( function ( $h ) { return (string) ( $h['zip'] ?? '' ); }, $hits ) );
			if ( count( $zips ) > 1 ) {
				return self::err( 'address', 'There is a ' . $area['number'] . ' ' . $area['street'] . ' in ' . implode( ' and ', $zips ) . '. Add the ZIP.' );
			}
			if ( $hits ) {
				$h     = $hits[0];
				$point = array( (float) $h['lat'], (float) $h['lng'], (string) ( $h['zip'] ?? '' ), trim( $h['number'] . ' ' . $h['street'] . ', ' . ( $h['city'] ?? '' ) . ' ' . ( $h['zip'] ?? '' ) ) );
			} elseif ( $miss ) {
				return self::err( 'address', 'The address map has ' . $area['number'] . ' ' . $area['street'] . ' in ' . implode( ', ', array_unique( $miss ) ) . ', not ' . trim( $area['city'] . ' ' . $area['zip'] ) . '. Check the town or ZIP.' );
			}
			if ( ! $point && empty( $a['holder'] ) ) {
				return self::err( 'address', 'Finding a house by its number needs Doorhangers on your account. Give the ZIP or the neighborhood instead.', 403 );
			}
			if ( ! $point || '' === $point[2] ) {
				return self::err( 'address', 'The address map has no ' . $area['number'] . ' ' . $area['street'] . ( $area['city'] || $area['zip'] ? ' in ' . trim( $area['city'] . ' ' . $area['zip'] ) : '' ) . '. Check the number and street, or give the ZIP.' );
			}
			$near = $point[2];
		}

		$recipe = array(
			'near'     => $near,
			'how_many' => min( 20, 'address' === $area['kind'] ? 20 : $count * 2 + 2 ),
			'exclude'  => array( 'hung_within_days' => (int) $s['rehang_days'] ),
			'spread'   => true,
		);
		$r = self::ask( self::rank_verb(), array( 'recipe' => $recipe, 'app' => ZRP_APP_ID ) );
		if ( null === $r ) {
			return self::err( 'no_ranker', __( 'Ranking neighborhoods needs a neighborhood ranker connected to the platform. You can still draw a route on the map.', 'zorderz' ), 503 );
		}
		if ( '' !== ( $why = self::failed( $r ) ) ) {
			$code = ! empty( $r['denied'] ) ? 'ranker_refused' : (string) ( $r['error'] ?? 'ranker' );
			return self::err( $code, 'clarify' === $code ? $why : 'The neighborhoods could not be ranked: ' . $why . ' You can still draw a route on the map.', 'clarify' === $code ? 400 : 502 );
		}
		$rows = array();
		foreach ( (array) ( $r['rows'] ?? array() ) as $row ) {
			if ( ! is_array( $row ) || empty( $row['lat'] ) || empty( $row['lon'] ) || ( empty( $row['streets'] ) && empty( $row['bounds'] ) ) ) {
				continue;
			}
			$row['envelope'] = self::envelope_of( $row );
			if ( $point ) {
				$row['from_address_mi'] = round( self::miles( $point[0], $point[1], $row['lat'], $row['lon'] ), 2 );
			}
			$rows[] = $row;
		}
		if ( $point ) {
			usort( $rows, function ( $x, $y ) { return $x['from_address_mi'] <=> $y['from_address_mi']; } );
			$close = array_values( array_filter( $rows, function ( $x ) { return $x['from_address_mi'] <= self::ADDRESS_MI; } ) );
			$rows  = array_slice( count( $close ) >= $count ? $close : $rows, 0, $count * 2 + 2 );
		}
		if ( ! $rows ) {
			$left = $r['notes'] ?? array();
			return self::err( 'no_rows', 'The ranker found no neighborhoods to hang near ' . $area['text'] . ' (streets hung in the last ' . (int) $s['rehang_days'] . ' days are left out).' . ( $left ? ' ' . implode( ' ', array_slice( (array) $left, 0, 2 ) ) : '' ), 404 );
		}

		// the forecast: one call per ~2 km square, at most MAX_WX; rows further apart share the nearest square's answer
		$cells = array();
		foreach ( $rows as $i => $row ) {
			$c = ZRP_Weather::cell( $row['lat'], $row['lon'] );
			if ( ! isset( $cells[ $c ] ) && count( $cells ) < self::MAX_WX ) {
				$cells[ $c ] = ZRP_Weather::for_walk( $row['lat'], $row['lon'], $date, $start, $hours );
			}
			if ( ! isset( $cells[ $c ] ) ) {
				$best = null;
				foreach ( array_keys( $cells ) as $k ) {
					list( $la, $ln ) = array_map( 'floatval', explode( ',', $k ) );
					$d = self::miles( $la, $ln, $row['lat'], $row['lon'] );
					if ( null === $best || $d < $best[0] ) { $best = array( $d, $k ); }
				}
				$c = $best[1];
			}
			$rows[ $i ]['wx'] = $c;
		}
		return rest_ensure_response( array(
			'ok'          => true,
			'request'     => array( 'area' => $area['text'], 'kind' => $area['kind'], 'date' => $date, 'count' => $count, 'hours' => $hours, 'start' => $start,
				'window' => ZRP_Weather::window( $start, $hours ), 'point' => $point ? array( $point[0], $point[1] ) : null, 'place' => $point ? $point[3] : '' ),
			'rows'        => $rows,
			'forecasts'   => $cells,
			'forecast'    => $cells[ $rows[0]['wx'] ],
			'ingredients' => $r['ingredients'] ?? array(),
			'notes'       => array_values( (array) ( $r['notes'] ?? array() ) ),
			'read_as'     => array_values( (array) ( $r['read_as'] ?? array() ) ),
			'data_as_of'  => (string) ( $r['data_as_of'] ?? '' ),
			'rehang_days' => (int) $s['rehang_days'],
			'per_hour'    => (int) round( self::per_hour() ),
			'limits'      => ZRP_Weather::limits(),
		) );
	}

	/* ------------------------------------------------------------------ POST plan/area */

	/** A box as given, bounded (ZDH_Geo's own cap is about 5 x 5.5 km); null when it is not one. */
	private static function clean_box( $e ) {
		if ( ! is_array( $e ) || ! isset( $e['xmin'], $e['ymin'], $e['xmax'], $e['ymax'] ) ) {
			return null;
		}
		foreach ( array( 'xmin', 'ymin', 'xmax', 'ymax' ) as $k ) {
			if ( ! is_numeric( $e[ $k ] ) ) { return null; }
		}
		$b = array( 'xmin' => (float) $e['xmin'], 'ymin' => (float) $e['ymin'], 'xmax' => (float) $e['xmax'], 'ymax' => (float) $e['ymax'] );
		if ( $b['xmax'] <= $b['xmin'] || $b['ymax'] <= $b['ymin'] || abs( $b['ymin'] ) > 90 || abs( $b['ymax'] ) > 90 || abs( $b['xmin'] ) > 180 || abs( $b['xmax'] ) > 180 ) {
			return null;
		}
		if ( $b['xmax'] - $b['xmin'] > self::DRAW_DEG || $b['ymax'] - $b['ymin'] > self::DRAW_DEG ) {
			return false; // too big: a neighborhood or a drawing, never a town
		}
		return $b;
	}

	public static function rest_area( WP_REST_Request $req ) {
		$s   = zrp_get_settings();
		$box = self::clean_box( $req->get_param( 'envelope' ) );
		if ( false === $box ) {
			return self::err( 'too_big', 'That area is too large. Zoom in, or draw a smaller route.' );
		}
		$streets = array();
		foreach ( array_slice( (array) $req->get_param( 'streets' ), 0, self::MAX_STREETS + 1 ) as $st ) {
			if ( is_scalar( $st ) && '' !== trim( (string) $st ) ) {
				$streets[] = mb_substr( trim( sanitize_text_field( (string) $st ) ), 0, 80 );
			}
		}
		$streets = array_slice( array_values( array_unique( $streets ) ), 0, self::MAX_STREETS );
		$zip     = substr( preg_replace( '/[^0-9]/', '', (string) $req->get_param( 'zip' ) ), 0, 5 );
		if ( ! $box && ! $streets ) {
			return self::err( 'no_area', 'Which area? A box or the streets.' );
		}
		$args = $box ? array( 'envelope' => $box ) : array( 'streets' => $streets, 'zip' => $zip );
		$a    = self::ask( self::VERB_AREA, $args );
		if ( null === $a ) {
			return self::err( 'no_doorhangers', 'Planning walks needs Doorhangers on this site (it reads the address map\'s houses and streets).', 503 );
		}
		if ( '' !== ( $why = self::failed( $a ) ) ) {
			return self::err( (string) ( $a['reason'] ?? ( $a['error'] ?? 'map' ) ), $why, ! empty( $a['denied'] ) ? 403 : 502 );
		}
		$houses = (array) ( $a['houses'] ?? array() );

		// the survey's confirmed tags on these streets (this app's own verb, as anyone else reads it)
		$tag_args = array();
		if ( $box ) {
			$tag_args['envelope'] = $box;
		} else {
			$tag_args['streets'] = array_map( function ( $st ) use ( $zip ) { return array( 'street' => $st, 'zip' => $zip ); }, $streets );
		}
		$tags   = array();
		$t_note = '';
		$t      = self::ask( self::VERB_TAGS, $tag_args );
		if ( null === $t || '' !== self::failed( $t ) ) {
			$t_note = 'The survey\'s notes could not be read just now' . ( $t ? ' (' . self::failed( $t ) . ')' : '' ) . ': routes are scored without them.';
			$tags   = null;
		} else {
			foreach ( (array) ( $t['streets'] ?? array() ) as $st ) {
				$k = (string) $st['street_key'] . '|' . (string) $st['zip'];
				$tags[ $k ] = array( 'street' => (string) $st['street'], 'tags' => array(), 'likely' => array() );
				foreach ( (array) ( $st['tags'] ?? array() ) as $slug => $info ) {
					$tags[ $k ]['tags'][ $slug ] = array( 'level' => (string) $info['level'], 'notes' => (int) $info['notes'], 'product' => (string) $info['product'], 'label' => (string) $info['label'] );
				}
				foreach ( (array) ( $st['likely'] ?? array() ) as $slug => $info ) {
					$tags[ $k ]['likely'][ $slug ] = array( 'product' => (string) $info['product'], 'label' => (string) $info['label'] );
				}
			}
		}

		// a hard-to-see feature seen nearby (the verb's "likely" areas) speaks for streets with no notes yet
		if ( is_array( $tags ) && ! empty( $t['areas'] ) ) {
			$mid = array();
			foreach ( $houses as $h ) {
				$k = (string) $h['street_key'] . '|' . (string) $h['zip'];
				$mid[ $k ] = $mid[ $k ] ?? array( 0.0, 0.0, 0, (string) $h['street'] );
				$mid[ $k ][0] += (float) $h['lat']; $mid[ $k ][1] += (float) $h['lng']; $mid[ $k ][2]++;
			}
			foreach ( $mid as $k => $m ) {
				foreach ( (array) $t['areas'] as $ar ) {
					if ( empty( $ar['center'] ) || '' === (string) ( $ar['product'] ?? '' ) ) { continue; }
					// a hard-to-see feature seen, or a claim a second street corroborated; one street's word
					// for "the whole neighborhood" counts for that street only, until another street says it too
					if ( 'likely' !== ( $ar['kind'] ?? '' ) && empty( $ar['corroborated'] ) ) { continue; }
					if ( isset( $tags[ $k ]['tags'][ $ar['tag'] ] ) || isset( $tags[ $k ]['likely'][ $ar['tag'] ] ) ) { continue; }
					if ( ZRP_DB::dist_m( $m[0] / $m[2], $m[1] / $m[2], (float) $ar['center'][0], (float) $ar['center'][1] ) <= (float) $ar['radius_m'] ) {
						$tags[ $k ] = $tags[ $k ] ?? array( 'street' => $m[3], 'tags' => array(), 'likely' => array() );
						$tags[ $k ]['likely'][ $ar['tag'] ] = array( 'product' => (string) $ar['product'], 'label' => (string) $ar['label'] );
					}
				}
			}
		}

		// what was hung (or is planned) within the re-hang window, on these streets: those houses are left out
		$hung   = array();
		$h_note = '';
		$keys   = array();
		foreach ( $houses as $h ) { $keys[ (string) $h['street_key'] ] = true; }
		$hs = self::ask( self::VERB_HUNG, array( 'days' => (int) $s['rehang_days'] ) );
		if ( null === $hs ) {
			$h_note = 'Doorhangers did not say what was hung lately; the ranker already left out neighborhoods hung in the last ' . (int) $s['rehang_days'] . ' days.';
		} elseif ( '' !== self::failed( $hs ) ) {
			$h_note = 'What was hung lately could not be read (' . self::failed( $hs ) . '); the ranker already left out neighborhoods hung in the last ' . (int) $s['rehang_days'] . ' days.';
		} else {
			foreach ( (array) ( $hs['rows'] ?? array() ) as $row ) {
				// by the street alone, within this neighborhood's box -- Doorhangers saves every street of a
				// route under the route's one ZIP (a route across a ZIP line), and older routes under none
				$k = zrp_street_key( (string) $row['street'] );
				if ( ! isset( $keys[ $k ] ) ) {
					continue;
				}
				$hung[] = array( 'key' => $k, 'street' => (string) $row['street'], 'date' => (string) $row['date'], 'status' => (string) $row['status'],
					'low' => isset( $row['low'] ) ? $row['low'] : null, 'high' => isset( $row['high'] ) ? $row['high'] : null );
			}
		}
		return rest_ensure_response( array(
			'ok'        => true,
			'holder'    => ! empty( $a['holder'] ),
			'houses'    => $houses,
			'segments'  => (array) ( $a['segments'] ?? array() ),
			'streets'   => (array) ( $a['streets'] ?? array() ),
			'counts'    => (array) ( $a['counts'] ?? array() ),
			'truncated' => ! empty( $a['truncated'] ),
			'tags'      => $tags,
			'hung'      => $hung,
			'notes'     => array_values( array_filter( array( $t_note, $h_note, ! empty( $a['truncated'] ) ? 'The address map answered with its first ' . count( $houses ) . ' houses only here: plan a smaller area, or draw.' : '' ) ) ),
		) );
	}

	/* ------------------------------------------------------------------ POST plan/send, plan/create */

	public static function rest_send( WP_REST_Request $req ) {
		$uid = get_current_user_id();
		if ( ! function_exists( 'zdh_user_can_use' ) || ! has_filter( 'zdh_plan_preview' ) ) {
			return self::err( 'no_doorhangers', 'Sending a route needs Doorhangers on this site.', 503 );
		}
		if ( ! zdh_user_can_use( $uid ) ) {
			return self::err( 'no_access', 'Doorhangers is not on your account, so the route cannot be sent. Ask an administrator.', 403 );
		}
		$walks = $req->get_param( 'walks' );
		if ( ! is_array( $walks ) || ! $walks || count( $walks ) > self::MAX_WALKS ) {
			return self::err( 'bad_walks', 'Pick a route first.' );
		}
		$n = 0;
		foreach ( $walks as $w ) {
			if ( ! is_array( $w ) ) {
				return self::err( 'bad_walks', 'The route could not be read. Plan it again.' );
			}
			$n += count( $w );
		}
		if ( $n < 1 ) {
			return self::err( 'bad_walks', 'That route has no houses.' );
		}
		if ( $n > self::MAX_HOUSES ) {
			return self::err( 'too_many', 'That is ' . $n . ' houses, more than one route (' . self::MAX_HOUSES . ' at most). Take some blocks out.' );
		}
		$streets = array();
		foreach ( (array) $req->get_param( 'streets' ) as $st ) {
			if ( is_scalar( $st ) && '' !== trim( (string) $st ) ) {
				$streets[ strtolower( trim( (string) $st ) ) ] = mb_substr( trim( sanitize_text_field( (string) $st ) ), 0, 80 );
			}
		}
		if ( count( $streets ) > self::MAX_STREETS ) {
			return self::err( 'too_many_streets', 'That route is on ' . count( $streets ) . ' streets, more than one route (' . self::MAX_STREETS . ' at most). Take some blocks out.' );
		}
		$date  = self::clean_date( $req->get_param( 'date' ) );
		$hours = (string) $req->get_param( 'hours' );
		$args  = array(
			'source'  => 'routeplanner',
			'walks'   => array_values( $walks ),
			'streets' => array_values( $streets ),
			'zip'     => substr( preg_replace( '/[^0-9]/', '', (string) $req->get_param( 'zip' ) ), 0, 5 ),
			'city'    => mb_substr( sanitize_text_field( (string) $req->get_param( 'city' ) ), 0, 60 ),
			'for'     => mb_substr( sanitize_text_field( (string) $req->get_param( 'for' ) ), 0, 60 ),
			'due_by'  => $date ?: '',
			'hours'   => preg_match( '/^\d{1,2}-\d{1,2}$/', $hours ) ? $hours : '',
			'notes'   => mb_substr( sanitize_textarea_field( (string) $req->get_param( 'notes' ) ), 0, 500 ),
		);
		$p = apply_filters( 'zdh_plan_preview', null, $uid, $args );
		if ( ! is_array( $p ) ) {
			return self::err( 'no_doorhangers', 'Doorhangers did not answer.', 502 );
		}
		if ( empty( $p['ok'] ) ) {
			return self::err( (string) ( $p['reason'] ?? 'refused' ), (string) ( $p['message'] ?? 'Doorhangers refused the route.' ), 400 );
		}
		// what the press will log, kept with Doorhangers' token for its 10 minutes
		$kind = (string) $req->get_param( 'kind' );
		set_transient( 'zrp_send_' . md5( (string) $p['token'] ), array(
			'uid'     => $uid,
			'kind'    => in_array( $kind, array( 'planned', 'edited', 'drawn' ), true ) ? $kind : 'planned',
			'score'   => is_numeric( $req->get_param( 'score' ) ) ? max( 0, min( 100, (int) $req->get_param( 'score' ) ) ) : null,
			'weather' => in_array( (string) $req->get_param( 'weather' ), array( 'ok', 'flagged', 'none', 'error' ), true ) ? (string) $req->get_param( 'weather' ) : '',
			'area'    => mb_substr( sanitize_text_field( (string) $req->get_param( 'area' ) ), 0, 80 ),
			'walks'   => count( $walks ),
			'date'    => $date,
			'zip'     => $args['zip'],
		), 600 );
		return rest_ensure_response( $p );
	}

	public static function rest_create( WP_REST_Request $req ) {
		global $wpdb;
		$uid   = get_current_user_id();
		$token = (string) $req->get_param( 'token' );
		if ( ! has_filter( 'zdh_plan_create' ) ) {
			return self::err( 'no_doorhangers', 'Sending a route needs Doorhangers on this site.', 503 );
		}
		$meta = get_transient( 'zrp_send_' . md5( $token ) );
		$r    = apply_filters( 'zdh_plan_create', null, $uid, $token );
		if ( ! is_array( $r ) ) {
			return self::err( 'no_doorhangers', 'Doorhangers did not answer.', 502 );
		}
		if ( empty( $r['success'] ) ) {
			return self::err( (string) ( $r['reason'] ?? 'refused' ), (string) ( $r['message'] ?? 'Doorhangers refused the route.' ), 400 );
		}
		delete_transient( 'zrp_send_' . md5( $token ) );
		$meta = is_array( $meta ) && (int) ( $meta['uid'] ?? 0 ) === $uid ? $meta : array();
		// who pressed -- the press is this request's person, never a field
		$wpdb->insert( self::t_sends(), array(
			'user_id'      => $uid,
			'created_at'   => ZRP_DB::now(),
			'drop_id'      => (int) $r['id'],
			'assigned_uid' => (int) ( $r['for']['id'] ?? 0 ),
			'houses'       => (int) ( $r['houses'] ?? 0 ),
			'walks'        => (int) ( $meta['walks'] ?? 1 ),
			'streets'      => mb_substr( (string) ( $r['name'] ?? '' ), 0, 255 ),
			'zip'          => (string) ( $meta['zip'] ?? '' ),
			'plan_date'    => ! empty( $r['due_by'] ) ? (string) $r['due_by'] : null,
			'hours'        => (string) ( $r['hours'] ?? '' ),
			'kind'         => (string) ( $meta['kind'] ?? 'planned' ),
			'score'        => $meta['score'] ?? null,
			'weather'      => (string) ( $meta['weather'] ?? '' ),
			'area'         => (string) ( $meta['area'] ?? '' ),
		) );
		$r['logged'] = (int) $wpdb->insert_id;
		return rest_ensure_response( $r );
	}

	public static function rest_sends( WP_REST_Request $req ) {
		global $wpdb;
		$uid = get_current_user_id();
		$sql = 'SELECT * FROM ' . self::t_sends() . ( zrp_user_is_admin( $uid ) ? '' : $wpdb->prepare( ' WHERE user_id = %d', $uid ) ) . ' ORDER BY id DESC LIMIT 20';
		$out = array();
		foreach ( (array) $wpdb->get_results( $sql, ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql is fixed SQL on self::t_sends() plus a prepare()d user_id clause
			$u = get_userdata( (int) $r['user_id'] );
			$a = (int) $r['assigned_uid'] ? get_userdata( (int) $r['assigned_uid'] ) : null;
			$out[] = array( 'id' => (int) $r['id'], 'at' => (string) $r['created_at'], 'by' => $u ? (string) $u->display_name : '', 'for' => $a ? (string) $a->display_name : '',
				'drop_id' => (int) $r['drop_id'], 'houses' => (int) $r['houses'], 'name' => (string) $r['streets'], 'date' => (string) ( $r['plan_date'] ?? '' ),
				'hours' => (string) $r['hours'], 'kind' => (string) $r['kind'], 'weather' => (string) $r['weather'] );
		}
		return rest_ensure_response( array( 'sends' => $out ) );
	}
}
