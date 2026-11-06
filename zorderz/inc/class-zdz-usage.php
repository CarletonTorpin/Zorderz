<?php
/**
 * ZDZ_Usage -- what the app's usage records say, for admins (the Usage view) and for each person (their own).
 *
 * To improve the app a business needs to know what is used and when, and what is not used at all. These records say
 * that, and nothing that grades a person.
 *
 *   GET /zorderz/v1/admin/usage?days=7|30|90|180[&user=<id>|shared][&app=<id>]   administrators: the Usage tab in
 *       wp-admin -> User Management. Per app and per person: opens, people, time open, the device class, last use;
 *       opens by hour of day x weekday in the site's zone; apps nobody opened in 30 days; each app's registered
 *       actions; how many searches ran and which kind of result was opened (never the words); the count of every
 *       usage kind (the stream itself); pruning's last run; and whether the usage-records card is on.
 *   GET /zorderz/v1/usage/mine   anyone signed in but the shared account: their own last 30 days, per app.
 *   record( $app, $key, $ref )   an app counts one of its own actions where it succeeds.
 *
 * Reads raw rows (the audit log) for the window, which never reaches past the raw-row days, and the daily rollups
 * (zdz_usage_daily) for everything older. The shared account is "Shared device", never a person. Nothing here grades
 * anyone: the view is for improving the app, and says so.
 *
 * @package Zorderz
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZDZ_Usage {

	const RANGES      = array( 7, 30, 90, 180 );
	const UNUSED_DAYS = 30;
	/** The report is kept this long (it takes seconds on a very large log); Refresh reads afresh. */
	const CACHE_TTL = 300;

	/** Pruning runs daily; a last run older than this is reported as late. */
	const PRUNE_LATE_S = 2 * 86400 + 6 * 3600;

	public static function boot() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes() {
		register_rest_route( ZDZ_REST_NS, '/admin/usage', array(
			'methods'             => 'GET',
			'permission_callback' => function () { return current_user_can( 'manage_options' ); },
			'callback'            => function ( $req ) {
				$days = (int) $req->get_param( 'days' );
				$user = (string) $req->get_param( 'user' );
				$app  = (string) $req->get_param( 'app' );
				return rest_ensure_response( array( 'success' => true, 'data' => self::report( $days, null, array( 'user' => $user, 'app' => $app, 'fresh' => (bool) $req->get_param( 'fresh' ) ) ) ) );
			},
		) );
		register_rest_route( ZDZ_REST_NS, '/usage/mine', array(
			'methods'             => 'GET',
			'permission_callback' => function () { return is_user_logged_in(); },
			'callback'            => function () {
				$uid = (int) get_current_user_id();
				if ( self::is_shared( $uid ) ) {
					return new WP_Error( 'zdz_usage_shared', __( 'The shared account has no records of its own.', 'zorderz' ), array( 'status' => 403 ) );
				}
				return rest_ensure_response( self::mine( $uid ) );
			},
		) );
	}

	/**
	 * An app records one of its own actions from the server, at the point the action succeeded:
	 *
	 *   ZDZ_Usage::record( 'estimate-creator', 'estimate_sent', $estimate_id );
	 *   ZDZ_Usage::record( 'jobs', 'plan_computed', $job_id, array( 'once' => 'day' ) );
	 *
	 * The key must be registered for that app on the zdz_register_usage_actions filter (see
	 * ZDZ_Admin_Dashboard::usage_actions()); $ref is a
	 * number (an estimate, job or project id) or nothing, never a name or free text. ZDZ_Admin_Dashboard::log_action()
	 * does the rest, as for the page's own events: ZDZ_Consent::may_record() first, no IP, the device class from the
	 * user agent. $opts: 'uid' (default: the signed-in person); 'once' => 'day' keeps at most one row per person, key
	 * and ref in a site day (for an action people repeat while doing one thing, like pressing Compute).
	 * Never throws: whatever happens here, the app's own answer goes out as it would have.
	 *
	 * @return bool true when a row was written
	 */
	public static function record( $app_id, $key, $ref = 0, array $opts = array() ) {
		try {
			if ( ! class_exists( 'ZDZ_Admin_Dashboard' ) || ! self::clean_id( (string) $app_id ) ) {
				return false;
			}
			static $reg = null;
			if ( null === $reg ) {
				$reg = ZDZ_Admin_Dashboard::usage_actions();
			}
			if ( ! isset( $reg[ $app_id ][ (string) $key ] ) ) {
				return false;
			}
			$uid = isset( $opts['uid'] ) ? (int) $opts['uid'] : (int) get_current_user_id();
			if ( $uid <= 0 ) {
				return false;
			}
			$ref  = ( is_int( $ref ) || is_string( $ref ) ) && preg_match( '/^[1-9][0-9]{0,11}$/', (string) $ref ) ? (string) $ref : '';
			$meta = array( 'act' => (string) $key );
			if ( '' !== $ref ) {
				$meta['ref'] = $ref;
			}
			$once = '';
			if ( isset( $opts['once'] ) && 'day' === $opts['once'] ) {
				// 'scope' tells apart things with no number (a job keyed by its source): hashed into the key, never stored.
				$day   = ( new DateTimeImmutable( 'now', wp_timezone() ) )->format( 'Y-m-d' );
				$scope = isset( $opts['scope'] ) && is_scalar( $opts['scope'] ) ? (string) $opts['scope'] : '';
				$once  = 'zdz_ua_' . substr( md5( $uid . '|' . $app_id . '|' . $key . '|' . $ref . '|' . $scope . '|' . $day ), 0, 16 );
				if ( get_transient( $once ) ) {
					return false;
				}
			}
			$done = (bool) ZDZ_Admin_Dashboard::log_action( $uid, 'app_action', (string) $key, (string) $app_id, $meta );
			if ( $done && '' !== $once ) {
				set_transient( $once, 1, DAY_IN_SECONDS );   // only once written: a refused or failed write keeps the day's slot
			}
			return $done;
		} catch ( Throwable $e ) {
			error_log( 'Zorderz: usage record skipped: ' . $e->getMessage() ); // phpcs:ignore
			return false;
		}
	}

	/** The shared kiosk / General account: never a person. */
	public static function is_shared( $uid ) {
		$uid = (int) $uid;
		if ( $uid <= 0 ) {
			return false;
		}
		if ( class_exists( 'ZDZ_Hierarchy' ) && ZDZ_Hierarchy::is_kiosk( $uid ) ) {
			return true;
		}
		if ( class_exists( 'ZDZ_User_Roles' ) && method_exists( 'ZDZ_User_Roles', 'is_shared_device' ) && ZDZ_User_Roles::is_shared_device( $uid ) ) {
			return true;
		}
		$u = function_exists( 'get_userdata' ) ? get_userdata( $uid ) : null;
		return $u && in_array( 'zdz_general', (array) $u->roles, true );
	}

	/** A clean app id (older rows could hold anything in app_id). */
	public static function clean_id( $id ) {
		return is_string( $id ) && 1 === preg_match( '/^[a-z0-9][a-z0-9_\-]{0,63}$/', $id );
	}

	/** Registered apps: id => name. */
	public static function apps() {
		$out = array();
		if ( class_exists( 'ZDZ_Plugin_API' ) ) {
			foreach ( ZDZ_Plugin_API::get_instance()->get_all_apps() as $id => $app ) {
				$c          = $app->get_config();
				$out[ $id ] = (string) ( $c['nm'] ?? $c['name'] ?? $id );
			}
		}
		$out = apply_filters( 'zdz_usage_apps', $out );
		return is_array( $out ) ? $out : array();
	}

	/** The site's offset from UTC now, in seconds (for "days used"; a daylight-saving change moves it an hour, twice a year). */
	private static function offset( $now ) {
		return (int) wp_timezone()->getOffset( new DateTimeImmutable( '@' . (int) $now ) );
	}

	/** ISO weekday (1..7) and hour (0..23) in the site's zone of an hour bucket (UNIX hour number). */
	private static function local_slot( $hour_bucket, DateTimeZone $tz ) {
		$d = ( new DateTimeImmutable( '@' . ( (int) $hour_bucket * 3600 ) ) )->setTimezone( $tz );
		return array( (int) $d->format( 'N' ), (int) $d->format( 'G' ) );
	}

	private static function name_of( $uid, array &$cache ) {
		$uid = (int) $uid;
		if ( ! isset( $cache[ $uid ] ) ) {
			if ( self::is_shared( $uid ) ) {
				$cache[ $uid ] = array( 'name' => __( 'Shared device', 'zorderz' ), 'shared' => true );
			} else {
				$u             = function_exists( 'get_userdata' ) ? get_userdata( $uid ) : null;
				/* translators: %d: a user id no longer on the site */
				$cache[ $uid ] = array( 'name' => $u ? (string) $u->display_name : sprintf( __( 'Former user #%d', 'zorderz' ), $uid ), 'shared' => false );
			}
		}
		return $cache[ $uid ];
	}

	/**
	 * The Usage view's data. $days is one of RANGES (anything else: 30). $f: user ('' | person id | 'shared'), app ('' |
	 * app id) narrow the hour grid and the totals; the apps and people tables always cover the whole range.
	 */
	public static function report( $days, $now_ts = null, array $f = array() ) {
		$key = 'zdz_usage_rep_' . substr( md5( wp_json_encode( array( (int) $days, (string) ( $f['user'] ?? '' ), (string) ( $f['app'] ?? '' ), null === $now_ts ? 0 : (int) $now_ts ) ) ), 0, 12 );
		if ( empty( $f['fresh'] ) ) {
			$hit = get_transient( $key );
			if ( is_array( $hit ) && isset( $hit['apps'] ) ) {
				return self::status( $hit, $now_ts );
			}
		}
		$out = self::build( $days, $now_ts, $f );
		set_transient( $key, $out, self::CACHE_TTL );
		return self::status( $out, $now_ts );
	}

	/** Pruning's last run and the card's state: cheap, so never from the cache. */
	private static function status( array $out, $now_ts ) {
		$now   = null === $now_ts ? time() : (int) $now_ts;
		$tz    = wp_timezone();
		$prune = get_option( 'zdz_audit_prune_last', array() );
		$prune = is_array( $prune ) ? $prune : array();
		$p_at  = isset( $prune['at'] ) ? strtotime( (string) $prune['at'] ) : 0;
		$on    = class_exists( 'ZDZ_Consent' ) && ZDZ_Consent::enabled();
		$out['prune']   = array(
			'at'      => $p_at ? self::iso( $p_at, $tz ) : '',
			'deleted' => (int) ( $prune['deleted'] ?? 0 ),
			'rolled'  => (int) ( $prune['rolled'] ?? 0 ),
			'error'   => (string) ( $prune['error'] ?? '' ),
			'late'    => ! $p_at || ( $now - $p_at ) > self::PRUNE_LATE_S,
		);
		$out['consent'] = array(
			'card_on' => $on,
			'rule'    => $on
				? __( 'The usage-records card is on: records are kept only for people who agreed.', 'zorderz' )
				: __( 'The usage-records card is off: records are kept as before for everyone. Once it is on, only for people who agreed.', 'zorderz' ),
		);
		return $out;
	}

	private static function build( $days, $now_ts, array $f ) {
		global $wpdb;
		$t     = $wpdb->prefix . 'zdz_audit_log';
		$daily = $wpdb->prefix . 'zdz_usage_daily';
		$now   = null === $now_ts ? time() : (int) $now_ts;
		$days  = in_array( (int) $days, self::RANGES, true ) ? (int) $days : 30;
		$raw   = class_exists( 'ZDZ_Admin_Dashboard' ) && method_exists( 'ZDZ_Admin_Dashboard', 'raw_days' ) ? ZDZ_Admin_Dashboard::raw_days() : 180;
		$days  = min( $days, $raw );
		$since = $now - $days * DAY_IN_SECONDS;
		$tz    = wp_timezone();
		$names = array();
		$apps  = self::apps();

		// The filter: one person, the shared account, or everyone. And one app, or all.
		$fu    = isset( $f['user'] ) ? (string) $f['user'] : '';
		$fuid  = ctype_digit( $fu ) ? (int) $fu : 0;
		$fapp  = isset( $f['app'] ) && preg_match( '/^[a-z0-9_\-]{1,64}$/', (string) $f['app'] ) ? (string) $f['app'] : '';
		$match = function ( $uid, $app = null ) use ( $fu, $fuid, $fapp ) {
			if ( $fuid > 0 && (int) $uid !== $fuid ) {
				return false;
			}
			if ( 'shared' === $fu && ! self::is_shared( $uid ) ) {
				return false;
			}
			return ! ( null !== $app && '' !== $fapp && $app !== $fapp );
		};

		// 1. Opens per app and person, with the last one.
		$rows = (array) $wpdb->get_results( $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$t} is $wpdb->prefix plus a fixed table name
			"SELECT app_id, user_id, COUNT(*) AS n, MAX(UNIX_TIMESTAMP(created_at)) AS last FROM {$t} WHERE action_type = 'app_open' AND created_at >= FROM_UNIXTIME(%d) GROUP BY app_id, user_id",
			$since
		), ARRAY_A );
		$by_app = array();
		$people = array();
		$total  = array( 'opens' => 0, 'secs' => 0, 'people' => array() );
		foreach ( $rows as $r ) {
			$a = (string) $r['app_id'];
			if ( ! self::clean_id( $a ) ) {
				continue;
			}
			$u = (int) $r['user_id'];
			$n = (int) $r['n'];
			$l = (int) $r['last'];
			if ( ! isset( $by_app[ $a ] ) ) {
				$by_app[ $a ] = array( 'id' => $a, 'name' => $apps[ $a ] ?? $a, 'opens' => 0, 'people' => array(), 'secs' => 0, 'closes' => 0, 'last' => 0, 'devices' => array() );
			}
			$by_app[ $a ]['opens']       += $n;
			$by_app[ $a ]['people'][ $u ] = 1;
			$by_app[ $a ]['last']         = max( $by_app[ $a ]['last'], $l );
			if ( ! isset( $people[ $u ] ) ) {
				$people[ $u ] = array( 'opens' => 0, 'secs' => 0, 'last' => 0, 'apps' => array(), 'days' => 0 );
			}
			$people[ $u ]['opens']      += $n;
			$people[ $u ]['last']        = max( $people[ $u ]['last'], $l );
			$people[ $u ]['apps'][ $a ]  = ( $people[ $u ]['apps'][ $a ] ?? 0 ) + $n;
			if ( $match( $u, $a ) ) {
				$total['opens']             += $n;
				$total['people'][ $u ]       = 1;
			}
		}

		// 2. Time open, from app_close (rows from before time was recorded have none).
		$rows = (array) $wpdb->get_results( $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$t} is $wpdb->prefix plus a fixed table name
			"SELECT app_id, user_id, COUNT(*) AS n, SUM(secs) AS s FROM {$t} WHERE action_type = 'app_close' AND created_at >= FROM_UNIXTIME(%d) GROUP BY app_id, user_id",
			$since
		), ARRAY_A );
		foreach ( $rows as $r ) {
			$a = (string) $r['app_id'];
			$u = (int) $r['user_id'];
			$s = (int) $r['s'];
			if ( ! isset( $by_app[ $a ] ) ) {
				continue;   // an app with no open in the window, or not a clean id (the built-in web page): not an app's time
			}
			$by_app[ $a ]['secs']   += $s;
			$by_app[ $a ]['closes'] += (int) $r['n'];
			if ( isset( $people[ $u ] ) ) {
				$people[ $u ]['secs'] += $s;
			}
			if ( $match( $u, $a ) ) {
				$total['secs'] += $s;
			}
		}

		// 3. The device class of each open.
		$rows = (array) $wpdb->get_results( $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$t} is $wpdb->prefix plus a fixed table name
			"SELECT app_id, user_id, device, COUNT(*) AS n FROM {$t} WHERE action_type = 'app_open' AND created_at >= FROM_UNIXTIME(%d) GROUP BY app_id, user_id, device",
			$since
		), ARRAY_A );
		$devices = array( 'phone' => 0, 'desktop_app' => 0, 'browser' => 0, 'unknown' => 0 );
		foreach ( $rows as $r ) {
			$a = (string) $r['app_id'];
			if ( ! isset( $by_app[ $a ] ) ) {
				continue;
			}
			$d = in_array( (string) $r['device'], array( 'phone', 'desktop_app', 'browser' ), true ) ? (string) $r['device'] : 'unknown';
			if ( isset( $by_app[ $a ] ) ) {
				$by_app[ $a ]['devices'][ $d ] = ( $by_app[ $a ]['devices'][ $d ] ?? 0 ) + (int) $r['n'];
			}
			if ( $match( (int) $r['user_id'], $a ) ) {
				$devices[ $d ] += (int) $r['n'];
			}
		}

		// 4. Opens by hour of day x weekday, in the site's zone (hour buckets turned into local time here, so a
		//    daylight-saving change lands every open in its right hour).
		$grid = array();
		for ( $d = 1; $d <= 7; $d++ ) {
			$grid[ $d ] = array_fill( 0, 24, 0 );
		}
		// Narrowed in SQL (a large log would otherwise hand PHP every row): by person and app, never by app id
		// alone, so the grid's rows are people x hours.
		$gw = '';
		$ga = array( $since );
		if ( $fuid > 0 ) {
			$gw  .= ' AND user_id = %d';
			$ga[] = $fuid;
		}
		if ( '' !== $fapp ) {
			$gw  .= ' AND app_id = %s';
			$ga[] = $fapp;
		}
		$rows = (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT user_id, FLOOR(UNIX_TIMESTAMP(created_at) / 3600) AS hb, COUNT(*) AS n FROM {$t} WHERE action_type = 'app_open' AND created_at >= FROM_UNIXTIME(%d){$gw} GROUP BY user_id, hb", // phpcs:ignore
			$ga
		), ARRAY_A );
		foreach ( $rows as $r ) {
			if ( ! $match( (int) $r['user_id'] ) ) {
				continue;
			}
			list( $dow, $h ) = self::local_slot( (int) $r['hb'], $tz );
			$grid[ $dow ][ $h ] += (int) $r['n'];
		}

		// 5. Days each person used the app at all (any usage kind), in the site's days.
		$kinds = class_exists( 'ZDZ_Admin_Dashboard' ) ? ZDZ_Admin_Dashboard::USAGE_KINDS : array( 'app_open' );
		$in    = implode( ',', array_fill( 0, count( $kinds ), '%s' ) );
		$rows  = (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT user_id, COUNT(DISTINCT FLOOR((UNIX_TIMESTAMP(created_at) + %d) / 86400)) AS d, MAX(UNIX_TIMESTAMP(created_at)) AS last FROM {$t} WHERE created_at >= FROM_UNIXTIME(%d) AND action_type IN ($in) GROUP BY user_id", // phpcs:ignore
			array_merge( array( self::offset( $now ), $since ), $kinds )
		), ARRAY_A );
		foreach ( $rows as $r ) {
			$u = (int) $r['user_id'];
			if ( ! isset( $people[ $u ] ) ) {
				$people[ $u ] = array( 'opens' => 0, 'secs' => 0, 'last' => 0, 'apps' => array(), 'days' => 0 );
			}
			$people[ $u ]['days'] = (int) $r['d'];
			$people[ $u ]['last'] = max( $people[ $u ]['last'], (int) $r['last'] );
		}

		// 6. Every kind's count in the window: the stream itself, sign-ins included.
		$all_kinds = array_merge( $kinds, array( 'login', 'logout' ) );
		$in2       = implode( ',', array_fill( 0, count( $all_kinds ), '%s' ) );
		$rows      = (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT action_type, COUNT(*) AS n FROM {$t} WHERE created_at >= FROM_UNIXTIME(%d) AND action_type IN ($in2) GROUP BY action_type", // phpcs:ignore
			array_merge( array( $since ), $all_kinds )
		), ARRAY_A );
		$stream = array_fill_keys( $all_kinds, 0 );
		foreach ( $rows as $r ) {
			$stream[ (string) $r['action_type'] ] = (int) $r['n'];
		}

		// 7. Each app's registered actions, searches and palette results (kinds of result only, never words).
		$reg  = class_exists( 'ZDZ_Admin_Dashboard' ) ? ZDZ_Admin_Dashboard::usage_actions() : array();
		$rows = (array) $wpdb->get_results( $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$t} is $wpdb->prefix plus a fixed table name
			"SELECT action_type, app_id, action_detail, COUNT(*) AS n, COUNT(DISTINCT user_id) AS p FROM {$t} WHERE action_type IN ('app_action','search','command_palette') AND created_at >= FROM_UNIXTIME(%d) GROUP BY action_type, app_id, action_detail",
			$since
		), ARRAY_A );
		$actions = array();
		$search  = array( 'ask' => 0, 'apps' => 0 );
		$palette = array( 'app' => 0, 'ask' => 0, 'action' => 0, 'answer' => 0, 'quick_answer' => 0 );
		foreach ( $rows as $r ) {
			$k = (string) $r['action_type'];
			$d = (string) $r['action_detail'];
			if ( 'search' === $k && isset( $search[ $d ] ) ) {
				$search[ $d ] += (int) $r['n'];
			} elseif ( 'command_palette' === $k && isset( $palette[ $d ] ) ) {
				$palette[ $d ] += (int) $r['n'];
			} elseif ( 'app_action' === $k && self::clean_id( (string) $r['app_id'] ) && 1 === preg_match( '/^[a-z][a-z0-9_]{0,39}$/', $d ) ) {
				$a         = (string) $r['app_id'];
				$actions[] = array( 'app' => $a, 'app_name' => $apps[ $a ] ?? $a, 'key' => $d, 'label' => $reg[ $a ][ $d ] ?? $d, 'n' => (int) $r['n'], 'people' => (int) $r['p'] );
			}
		}
		// Registered but never used in the window: listed with 0, so a silent action shows.
		foreach ( $reg as $a => $acts ) {
			foreach ( $acts as $key => $label ) {
				$seen = false;
				foreach ( $actions as $x ) {
					if ( $x['app'] === $a && $x['key'] === $key ) {
						$seen = true;
						break;
					}
				}
				if ( ! $seen ) {
					$actions[] = array( 'app' => $a, 'app_name' => $apps[ $a ] ?? $a, 'key' => $key, 'label' => $label, 'n' => 0, 'people' => 0 );
				}
			}
		}
		usort( $actions, function ( $x, $y ) { return $y['n'] <=> $x['n'] ?: strcmp( $x['app'] . $x['key'], $y['app'] . $y['key'] ); } );

		// 8. Before the raw window: the daily rollups (opens and time open per app, all time).
		$older = array();
		$rows  = (array) $wpdb->get_results( "SELECT app_id, action_type, SUM(n) AS n, SUM(secs) AS s, MAX(day) AS last FROM {$daily} WHERE action_type IN ('app_open','app_close') GROUP BY app_id, action_type", ARRAY_A ); // phpcs:ignore
		foreach ( $rows as $r ) {
			$a = (string) $r['app_id'];
			if ( ! self::clean_id( $a ) ) {
				continue;
			}
			if ( ! isset( $older[ $a ] ) ) {
				$older[ $a ] = array( 'opens' => 0, 'secs' => 0, 'last_day' => '' );
			}
			if ( 'app_open' === $r['action_type'] ) {
				$older[ $a ]['opens']    = (int) $r['n'];
				$older[ $a ]['last_day'] = (string) $r['last'];
			} else {
				$older[ $a ]['secs'] = (int) $r['s'];
			}
		}

		// 9. Apps nobody opened in 30 days, with when each was last opened at all.
		$cut30 = $now - self::UNUSED_DAYS * DAY_IN_SECONDS;
		$rows  = (array) $wpdb->get_results( "SELECT app_id, MAX(UNIX_TIMESTAMP(created_at)) AS last FROM {$t} WHERE action_type = 'app_open' GROUP BY app_id", ARRAY_A ); // phpcs:ignore
		$ever  = array();
		foreach ( $rows as $r ) {
			$ever[ (string) $r['app_id'] ] = (int) $r['last'];
		}
		$unused = array();
		foreach ( $apps as $id => $name ) {
			$last = $ever[ $id ] ?? 0;
			if ( $last >= $cut30 ) {
				continue;
			}
			$unused[] = array(
				'id'       => $id,
				'name'     => $name,
				'last'     => $last ? self::iso( $last, $tz ) : '',
				'last_day' => $last ? '' : ( $older[ $id ]['last_day'] ?? '' ),
			);
		}

		// The tables.
		$app_list = array();
		foreach ( $by_app as $a => $x ) {
			$app_list[] = array(
				'id'       => $a,
				'name'     => $x['name'],
				'opens'    => $x['opens'],
				'people'   => count( $x['people'] ),
				'secs'     => $x['secs'],
				'closes'   => $x['closes'],
				// Time open over opens: putting the page to the back sends a close each time, so one
				// open can have several closes.
				'per_open' => $x['opens'] > 0 ? (int) round( $x['secs'] / $x['opens'] ) : 0,
				'last'     => self::iso( $x['last'], $tz ),
				'devices'  => $x['devices'],
				'older'    => $older[ $a ] ?? array( 'opens' => 0, 'secs' => 0, 'last_day' => '' ),
			);
		}
		usort( $app_list, function ( $x, $y ) { return $y['opens'] <=> $x['opens'] ?: strcmp( $x['id'], $y['id'] ); } );

		$person_list = array();
		foreach ( $people as $u => $x ) {
			$who = self::name_of( $u, $names );
			arsort( $x['apps'] );
			$person_list[] = array(
				'id'     => $who['shared'] ? 'shared' : (string) $u,
				'uid'    => (int) $u,
				'name'   => $who['name'],
				'shared' => $who['shared'],
				'opens'  => $x['opens'],
				'secs'   => $x['secs'],
				'days'   => $x['days'],
				'last'   => self::iso( $x['last'], $tz ),
				'top'    => array_slice( array_keys( $x['apps'] ), 0, 3 ),
			);
		}
		usort( $person_list, function ( $x, $y ) { return $y['opens'] <=> $x['opens'] ?: strcmp( $x['name'], $y['name'] ); } );

		return array(
			'days'      => $days,
			'raw_days'  => $raw,
			'from'      => self::iso( $since, $tz ),
			'to'        => self::iso( $now, $tz ),
			'tz'        => wp_timezone_string(),
			'filter'    => array( 'user' => $fuid > 0 ? (string) $fuid : ( 'shared' === $fu ? 'shared' : '' ), 'app' => $fapp ),
			'totals'    => array( 'opens' => $total['opens'], 'people' => count( $total['people'] ), 'secs' => $total['secs'], 'devices' => $devices ),
			'apps'      => $app_list,
			'people'    => $person_list,
			'grid'      => array_values( array_map( 'array_values', $grid ) ),   // [Mon..Sun][0..23]
			'unused'    => $unused,
			'actions'   => $actions,
			'search'    => $search,
			'palette'   => $palette,
			'stream'    => $stream,
			'beat'      => class_exists( 'ZDZ_Admin_Dashboard' ) ? ZDZ_Admin_Dashboard::HEARTBEAT_S : 240,
		);
	}

	/** This person's own last 30 days: per app, opens, time open and last use. Never anyone else's. */
	public static function mine( $uid, $now_ts = null ) {
		global $wpdb;
		$t     = $wpdb->prefix . 'zdz_audit_log';
		$now   = null === $now_ts ? time() : (int) $now_ts;
		$since = $now - 30 * DAY_IN_SECONDS;
		$tz    = wp_timezone();
		$apps  = self::apps();
		$rows  = (array) $wpdb->get_results( $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$t} is $wpdb->prefix plus a fixed table name
			"SELECT app_id, action_type, COUNT(*) AS n, SUM(secs) AS s, MAX(UNIX_TIMESTAMP(created_at)) AS last FROM {$t} WHERE user_id = %d AND action_type IN ('app_open','app_close') AND created_at >= FROM_UNIXTIME(%d) GROUP BY app_id, action_type",
			(int) $uid, $since
		), ARRAY_A );
		$by = array();
		foreach ( $rows as $r ) {
			$a = (string) $r['app_id'];
			if ( ! self::clean_id( $a ) ) {
				continue;
			}
			if ( ! isset( $by[ $a ] ) ) {
				$by[ $a ] = array( 'id' => $a, 'name' => $apps[ $a ] ?? $a, 'opens' => 0, 'secs' => 0, 'last' => '' );
			}
			if ( 'app_open' === $r['action_type'] ) {
				$by[ $a ]['opens'] = (int) $r['n'];
				$by[ $a ]['last']  = self::iso( (int) $r['last'], $tz );
			} else {
				$by[ $a ]['secs'] = (int) $r['s'];
			}
		}
		$list = array_values( array_filter( $by, function ( $x ) { return $x['opens'] > 0; } ) );
		usort( $list, function ( $x, $y ) { return $y['opens'] <=> $x['opens'] ?: strcmp( $x['id'], $y['id'] ); } );
		return array(
			'days'      => 30,
			'recording' => class_exists( 'ZDZ_Admin_Dashboard' ) ? ZDZ_Admin_Dashboard::may_record( $uid ) : true,
			'card_on'   => class_exists( 'ZDZ_Consent' ) && ZDZ_Consent::enabled(),
			'apps'      => $list,
		);
	}

	private static function iso( $ts, DateTimeZone $tz ) {
		return (int) $ts > 0 ? ( new DateTimeImmutable( '@' . (int) $ts ) )->setTimezone( $tz )->format( 'c' ) : '';
	}
}
