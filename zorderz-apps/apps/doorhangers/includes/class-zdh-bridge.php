<?php
/**
 * ZDH_Bridge -- what the rest of the platform may ask Doorhangers.
 *
 *   doorhangers.lookup  "did <address> get a hanger?"  a READ: delivered / likely / street_partial / planned / none,
 *                       with the date and how close the nearest route came. tier viewer, never the kiosk, no side
 *                       effect, needs the app. Registered on zdz_register_capabilities; the theme's ZDZ_Capabilities
 *                       decides who may use it and invoke() names the asker from the session.
 *
 *   apply_filters( 'zdz_doorhangers_lookup', null, $address_line )  the PHP seam for Jobs: the same answer, for a
 *                       caller already inside the site. The caller must have checked its own viewer first.
 *
 *   doorhangers.list    the routes to be done and in progress: streets, house counts, who, by when, which hours.
 *                       tier viewer, never the kiosk, no side effect. A READ the chat can quote.
 *   doorhangers.plan    plan a route for someone from street names: a WRITE (side_effect), tier staff, never the
 *                       kiosk. The registry's invoke() never runs it (writes wait for a click); the chat previews it
 *                       through `zdh_plan_preview` and makes it through `zdh_plan_create` when a person presses the
 *                       button. The descriptor is here so the registry can SAY the verb exists.
 *
 *   apply_filters( 'zdh_routes_list', null, $uid, 'mine'|'all' )   routes for Jobs' next steps and the chat (ZDH_Plan).
 *
 *   doorhangers.area_houses  the address map's houses and road segments of an area, for the Route Planner's walks and
 *                       survey; doorhangers.hung_streets the filter below as a verb. Both in ZDH_Area, with their
 *                       per-asker shapes; registered here.
 *
 *   apply_filters( 'zdh_hung_streets', null, $uid, $days )   the streets door hangers went on, or are planned for:
 *                       street, ZIP, town, the day, the status (done / in_progress / planned) and how many houses on
 *                       that street, and the lowest and highest house numbers the route covered there (when every part
 *                       of it has them) -- never a house. For a neighborhood ranker that leaves out where the team has
 *                       been lately, read through WordPress so nothing copies the table. Done drops within $days
 *                       (1-3650, default 365; a drop with no date counts by the day it was finished or saved); every
 *                       route still planned or in progress and due (or made) in the last 90 days, dated today
 *                       (someone is going), listed first so the 2,000-row cap never drops one. Any holder of the app
 *                       (the whole log is every holder's); null for anyone else and the kiosk.
 *
 * Nothing here returns a customer's name: a house is an address, and the answer is about the address.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZDH_Bridge {

	const VERB      = 'doorhangers.lookup';
	const VERB_LIST = 'doorhangers.list';
	const VERB_PLAN = 'doorhangers.plan';
	const STALE_PLANNED_DAYS = 90;

	public static function init() {
		add_filter( 'zdz_register_capabilities', array( __CLASS__, 'register' ) );
		add_filter( 'zdz_doorhangers_lookup', array( __CLASS__, 'lookup_filter' ), 10, 2 );
		add_filter( 'zdh_hung_streets', array( __CLASS__, 'hung_streets_filter' ), 10, 3 );
	}

	/** apply_filters( 'zdh_hung_streets', null, $uid, $days ) -> rows | null (see the header). */
	public static function hung_streets_filter( $null, $uid = 0, $days = 365 ) {
		return self::hung_streets( (int) $uid, (int) $days );
	}

	public static function hung_streets( int $uid, int $days = 365 ) {
		global $wpdb;
		if ( $uid <= 0 || ! zdh_user_can_use( $uid ) ) {
			return null;
		}
		$days  = max( 1, min( 3650, $days ?: 365 ) );
		$today = current_time( 'Y-m-d' );
		$since = gmdate( 'Y-m-d', strtotime( $today . ' -' . $days . ' days' ) );
		// a route still planned counts while it is live: due within the last STALE_PLANNED_DAYS, or (no due date)
		// made or started that recently. A plan nobody walked in a year isn't "someone is going".
		$live  = gmdate( 'Y-m-d', strtotime( $today . ' -' . self::STALE_PLANNED_DAYS . ' days' ) );
		// a done drop's day: its drop date, else the day it was finished, else the day it was saved
		$done_day = 'COALESCE(d.drop_date, DATE(d.finished_at), DATE(d.created_at))';
		$plan_day = 'COALESCE(d.due_by, DATE(d.started_at), DATE(d.created_at))';
		$sql   = 'SELECT st.street AS street, st.zip AS zip, st.city AS city, d.status AS status, ' . $done_day . ' AS hung_day,'
			. ' SUM(st.address_count) AS houses,'
			. ' CASE WHEN COUNT(st.low_number) = COUNT(*) THEN MIN(st.low_number) END AS low,'
			. ' CASE WHEN COUNT(st.high_number) = COUNT(*) THEN MAX(st.high_number) END AS high'
			. ' FROM ' . ZDH_DB::t_streets() . ' st INNER JOIN ' . ZDH_DB::t_drops() . ' d ON d.id = st.drop_id'
			. $wpdb->prepare( " WHERE ( ( d.status IN ('planned','in_progress') AND $plan_day >= %s ) OR ( d.status = 'done' AND $done_day >= %s ) )", $live, $since ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $plan_day and $done_day are fixed SQL expressions set above; both dates are placeholders
			// per drop: two drops on one street and day keep their own number ranges (100-120 and 500-520 are not 100-520)
			. ' GROUP BY d.id, st.street, st.zip, st.city, d.status, ' . $done_day
			. " ORDER BY CASE WHEN d.status = 'done' THEN 1 ELSE 0 END, hung_day DESC LIMIT 2000"; // the live routes first
		$out = array();
		foreach ( (array) $wpdb->get_results( $sql, ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql is built above from the ZDH_DB table helpers, fixed expressions and a prepare()d WHERE clause
			$status = (string) $r['status'];
			$day    = 'done' === $status ? (string) $r['hung_day'] : $today;
			if ( '' === trim( (string) $r['street'] ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}/', $day ) ) {
				continue;
			}
			$low  = is_numeric( $r['low'] ?? null ) && (int) $r['low'] > 0 ? (int) $r['low'] : null;
			$high = is_numeric( $r['high'] ?? null ) && (int) $r['high'] > 0 ? (int) $r['high'] : null;
			$out[] = array(
				'street' => (string) $r['street'],
				'zip'    => substr( (string) $r['zip'], 0, 5 ),
				'city'   => (string) $r['city'],
				'date'   => substr( $day, 0, 10 ),
				'status' => $status,
				'houses' => (int) $r['houses'],
				'low'    => ( null !== $low && null !== $high ) ? min( $low, $high ) : null,
				'high'   => ( null !== $low && null !== $high ) ? max( $low, $high ) : null,
			);
		}
		return $out;
	}

	public static function descriptor(): array {
		return array(
			'verb'          => self::VERB,
			'provider'      => 'doorhangers',
			'app'           => 'doorhangers',
			'tier'          => 'viewer',
			'needs_app'     => true,
			'callback'      => array( __CLASS__, 'lookup_for_chat' ),
			'kiosk'         => false,
			'kiosk_bounded' => false,
			'side_effect'   => false,
			'summary'       => 'Whether a street address got a door hanger, and when (an address, never a person).',
		);
	}

	public static function register( $caps ) {
		if ( ! is_array( $caps ) ) {
			$caps = array();
		}
		$d = self::descriptor();
		$caps[ $d['verb'] ] = $d;
		$caps[ self::VERB_LIST ] = array(
			'verb'          => self::VERB_LIST,
			'provider'      => 'doorhangers',
			'app'           => 'doorhangers',
			'tier'          => 'viewer',
			'needs_app'     => true,
			'callback'      => array( __CLASS__, 'list_for_chat' ),
			'kiosk'         => false,
			'kiosk_bounded' => false,
			'side_effect'   => false,
			'summary'       => 'The door-hanger routes to be done and in progress: streets, house counts, who, by when, which hours.',
		);
		$caps[ self::VERB_PLAN ] = array(
			'verb'          => self::VERB_PLAN,
			'provider'      => 'doorhangers',
			'app'           => 'doorhangers',
			'tier'          => 'staff',
			'needs_app'     => true,
			'callback'      => array( __CLASS__, 'plan_refused' ),
			'kiosk'         => false,
			'kiosk_bounded' => false,
			'side_effect'   => true,
			'summary'       => 'Plan a door-hanger route for someone from street names (the address map\'s houses on them); it waits for a press.',
		);
		if ( class_exists( 'ZDH_Area' ) ) { // doorhangers.area_houses, doorhangers.hung_streets
			foreach ( ZDH_Area::descriptors() as $verb => $d ) {
				$caps[ $verb ] = $d;
			}
		}
		return $caps;
	}

	/**
	 * The registry's callback. $payload['query'] is one address line. The asker is the session (the registry sets it;
	 * this check is the app's own floor): must hold the app, never the kiosk.
	 */
	public static function lookup_for_chat( array $payload ): array {
		$res = array( 'success' => false, 'verb' => self::VERB, 'message' => '' );
		$uid = (int) get_current_user_id();
		if ( $uid <= 0 || ! zdh_user_can_use( $uid ) ) {
			$res['message'] = zdh_is_kiosk( $uid ) ? 'Doorhangers is not available on the shared device.' : 'You do not hold the Doorhangers app.';
			return $res;
		}
		$q = trim( (string) ( $payload['query'] ?? $payload['address'] ?? '' ) );
		if ( '' === $q ) {
			$res['success'] = true;
			$res['message'] = 'Which address?';
			return $res;
		}
		$r = self::answer( $q );
		return array_merge( $res, array( 'success' => true ), $r );
	}

	/** doorhangers.list: the routes, for the asker (the session). $payload['scope'] 'mine' | 'all' (default all). */
	public static function list_for_chat( array $payload ): array {
		$res = array( 'success' => false, 'verb' => self::VERB_LIST, 'message' => '', 'routes' => array() );
		$uid = (int) get_current_user_id();
		if ( $uid <= 0 || ! zdh_user_can_use( $uid ) ) {
			$res['message'] = zdh_is_kiosk( $uid ) ? 'Doorhangers is not available on the shared device.' : 'You do not hold the Doorhangers app.';
			return $res;
		}
		$scope = ( 'mine' === (string) ( $payload['scope'] ?? '' ) || ! zdh_user_is_admin( $uid ) ) ? 'mine' : 'all'; // everyone's routes: the admin tier only
		$res['success'] = true;
		$res['scope']   = $scope;
		$res['routes']  = (array) ZDH_Plan::list_routes( $uid, $scope );
		$res['message'] = $res['routes'] ? count( $res['routes'] ) . ' route(s).' : ( 'mine' === $scope ? 'Nothing is planned for you.' : 'No route is planned or in progress.' );
		return $res;
	}

	/** doorhangers.plan through invoke(): a write never runs from a verb call. The chat holds it for a press instead. */
	public static function plan_refused( array $payload ): array {
		return array( 'success' => false, 'verb' => self::VERB_PLAN, 'message' => 'A route is planned only when a person presses the button in the chat.', 'side_effect' => true );
	}

	/** The filter form: null in, the answer out. */
	public static function lookup_filter( $pre, $address ) {
		if ( null !== $pre ) {
			return $pre;
		}
		$address = trim( (string) $address );
		return '' === $address ? null : self::answer( $address );
	}

	/** One address → {status, sentence, date, drop_id, distance_m, corrected}. */
	public static function answer( string $address ): array {
		$one    = ZDH_DB::lookup( $address, true );
		$one    = is_array( $one ) ? $one : array();
		$status = (string) ( $one['status'] ?? 'none' );
		$match  = ( ! empty( $one['matches'] ) && is_array( $one['matches'] ) ) ? $one['matches'][0] : null;
		$date   = $match ? substr( (string) ( $match['drop_date'] ?? $match['delivered_at'] ?? '' ), 0, 10 ) : '';
		$dist   = null;
		if ( ! empty( $one['nearby']['houses'][0]['dist_m'] ) ) {
			$dist = (int) $one['nearby']['houses'][0]['dist_m'];
		}
		$typed  = substr( $address, 0, 120 );
		switch ( $status ) {
			case 'delivered':
				$s = $typed . ' got a door hanger' . ( $date ? ' on ' . $date : '' ) . '.';
				break;
			case 'likely':
				$s = $typed . ' is inside a street range that was covered' . ( $date ? ' on ' . $date : '' ) . ' (likely delivered).';
				break;
			case 'street_partial':
				$s = 'Part of that street was covered, but not that number.';
				break;
			case 'planned':
				$s = $typed . ' is on a planned route that has not been walked yet.';
				break;
			case 'invalid':
				$s = 'That does not read as a street address.';
				break;
			default:
				$s = 'No record of a door hanger at ' . $typed . '.' . ( null !== $dist ? ' The nearest route came within ' . $dist . ' m.' : '' );
		}
		return array(
			'status'     => $status,
			'sentence'   => $s,
			'date'       => $date,
			'drop_id'    => $match ? (int) ( $match['drop_id'] ?? 0 ) : 0,
			'distance_m' => $dist,
			'corrected'  => $one['corrected'] ?? null,
		);
	}
}

ZDH_Bridge::init();
