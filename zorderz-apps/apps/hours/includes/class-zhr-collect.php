<?php
/**
 * ZHR_Collect -- what the app saw each person do, copied into wp_zhr_activity to the minute, with a place when the
 * source had one, and the door-hanger walk timer.
 *
 * Read, never written: the platform's usage log (sign-ins, apps opened, the "still here" heartbeat, searches; through
 * ZDZ_Admin_Dashboard::rows_after, its public reader), the platform's photo table (a photo taken in the app, with its
 * GPS when the phone gave one), the Jobs app's step log (On my way, Start, a note -- the step's kind only, never its
 * words, which can hold a customer's name), and the Doorhangers app's walks (start, finish and a point every 5 minutes
 * of the GPS trace). Each source is read from where the last pass stopped (an id cursor per source), on WP-Cron every 15
 * minutes and when someone opens a day (at most once in 5 minutes site-wide). A source that is not installed is skipped.
 * Nothing here ever changes another app's data.
 *
 * The walk timer: when a person starts a door-hanger walk, their clock starts (when it is not already running); when the
 * walk ends, the clock that walk started stops. Hours watches the Doorhangers app's own REST answers
 * (rest_request_after_callbacks) for the person who pressed: a route or walk started, a Quick Start walk begun, a walk
 * finished or saved, a route reset or a walk discarded. Doorhangers is not changed and never waits on Hours: any failure
 * here is logged and its answer goes out as it was.
 *
 * @package Zorderz\Hours
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZHR_Collect {

	const THROTTLE_S      = 300;  // opening a day collects at most every 5 minutes site-wide; cron does the rest
	const FIRST_DAYS      = 60;   // the first pass reaches back this far, not to the beginning of the log
	const AUDIT_KINDS     = array( 'login' => 'login', 'heartbeat' => 'heartbeat', 'app_open' => 'in_app', 'view_switch' => 'in_app', 'dashboard_load' => 'in_app', 'widget_interact' => 'in_app', 'search' => 'in_app', 'command_palette' => 'in_app' );
	const TRACK_STEP_S    = 300;  // one walk point every 5 minutes
	const MISTAKE_S       = 300;  // a walk reset within 5 minutes of its start was a mistake: its clock entry goes too
	const WALK_DAYS       = 3;    // walks are re-copied whole while they are this recent (a corrected finish, a new walker)
	const DOORHANGER_BASE = '/doorhangers/drops';

	public static function init() {
		add_filter( 'rest_request_after_callbacks', array( __CLASS__, 'watch_rest' ), 20, 3 );
		add_action( 'zhr_collect', array( __CLASS__, 'run' ) );
	}

	/** On open of a day: collect at most once every THROTTLE_S seconds site-wide. */
	public static function maybe_run() {
		if ( get_transient( 'zhr_collect_ran' ) ) {
			return;
		}
		set_transient( 'zhr_collect_ran', 1, self::THROTTLE_S );
		self::run();
	}

	public static function run() {
		$n = 0;
		foreach ( array( 'audit', 'media', 'jobs', 'walks' ) as $src ) {
			try {
				$n += (int) call_user_func( array( __CLASS__, 'collect_' . $src ) );
			} catch ( Throwable $e ) {
				error_log( 'Hours: collecting ' . $src . ' failed: ' . $e->getMessage() ); // phpcs:ignore
			}
		}
		update_option( 'zhr_last_collect', array( 'at' => current_time( 'mysql' ), 'added' => $n ), false );
		return $n;
	}

	private static function cursor( $src ) {
		$c = get_option( 'zhr_cursor_' . $src, null );
		return null === $c ? null : (int) $c;
	}

	private static function set_cursor( $src, $id ) {
		update_option( 'zhr_cursor_' . $src, (int) $id, false );
	}

	private static function app_id( $raw ) {
		return preg_match( '/^[a-z0-9_\-]{1,64}$/', (string) $raw ) ? (string) $raw : '';
	}

	/** The platform's usage log, through its own reader. created_at is MySQL's clock, so MySQL turns it into a moment. */
	public static function collect_audit() {
		global $wpdb;
		$t = $wpdb->prefix . 'zdz_audit_log';
		if ( ! ZHR_DB::table_exists( $t ) ) {
			return 0;
		}
		$cur   = self::cursor( 'audit' );
		$kinds = array_keys( self::AUDIT_KINDS );
		$since = null === $cur ? time() - self::FIRST_DAYS * DAY_IN_SECONDS : 0;
		if ( class_exists( 'ZDZ_Admin_Dashboard' ) && method_exists( 'ZDZ_Admin_Dashboard', 'rows_after' ) ) {
			$rows = (array) ZDZ_Admin_Dashboard::rows_after( (int) $cur, $kinds, 5000, $since );
		} else {
			$in   = implode( ',', array_fill( 0, count( $kinds ), '%s' ) );
			$args = array_merge( array( (int) $cur ), $kinds, array( (int) $since ) );
			$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, user_id, action_type, app_id, UNIX_TIMESTAMP(created_at) AS ts FROM {$t} WHERE id > %d AND action_type IN ($in) AND created_at >= FROM_UNIXTIME(%d) ORDER BY id ASC LIMIT 5000", $args ), ARRAY_A ); // phpcs:ignore
		}
		$out = array();
		$max = (int) $cur;
		foreach ( $rows as $r ) {
			$max = max( $max, (int) $r['id'] );
			$ts  = (int) ( $r['ts'] ?? 0 );
			if ( (int) $r['user_id'] <= 0 || $ts <= 0 || ! isset( self::AUDIT_KINDS[ $r['action_type'] ] ) ) {
				continue;
			}
			$out[] = array(
				'user_id' => (int) $r['user_id'],
				'at'      => ZHR_Time::local( $ts ),
				'kind'    => self::AUDIT_KINDS[ $r['action_type'] ],
				'app_id'  => self::app_id( $r['app_id'] ?? '' ),
				'detail'  => (string) $r['action_type'],
				'ref'     => 'audit:' . (int) $r['id'],
			);
		}
		$n = ZHR_DB::add_activity( $out );
		if ( $rows ) {
			self::set_cursor( 'audit', $max );
		} elseif ( null === $cur ) {
			self::set_cursor( 'audit', (int) $wpdb->get_var( "SELECT COALESCE(MAX(id),0) FROM {$t}" ) ); // phpcs:ignore
		}
		return $n;
	}

	/**
	 * Photos and videos taken or added in the app. A photo taken within 6 hours of its upload is placed where and when it
	 * was taken (its GPS); an older photo added today is only "used the app" at the upload, with no place -- a photo
	 * taken last year does not put anyone anywhere last year.
	 */
	public static function collect_media() {
		global $wpdb;
		$t = $wpdb->prefix . 'zdz_user_media';
		if ( ! ZHR_DB::table_exists( $t ) ) {
			return 0;
		}
		// an older table without the capture columns is read without them (upload time only)
		$cols = (array) $wpdb->get_col( "SHOW COLUMNS FROM {$t}" ); // phpcs:ignore
		$geo  = in_array( 'captured_at', $cols, true ) && in_array( 'gps_lat', $cols, true ) && in_array( 'gps_lng', $cols, true );
		$sel  = 'id, user_id, media_type, source_app, created_at' . ( $geo ? ', captured_at, gps_lat, gps_lng' : '' );
		$cur  = self::cursor( 'media' );
		if ( null === $cur ) {
			$since = gmdate( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) ) - self::FIRST_DAYS * DAY_IN_SECONDS );
			$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT {$sel} FROM {$t} WHERE created_at >= %s ORDER BY id ASC LIMIT 3000", $since ), ARRAY_A ); // phpcs:ignore
		} else {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT {$sel} FROM {$t} WHERE id > %d ORDER BY id ASC LIMIT 3000", $cur ), ARRAY_A ); // phpcs:ignore
		}
		$rows = (array) $rows;
		$out  = array();
		$max  = (int) $cur;
		foreach ( $rows as $r ) {
			$max = max( $max, (int) $r['id'] );
			$uid = (int) $r['user_id'];
			$up  = ZHR_Time::ts( substr( (string) $r['created_at'], 0, 19 ) );
			if ( $uid <= 0 || null === $up ) {
				continue;
			}
			$shot = ! empty( $r['captured_at'] ) ? ZHR_Time::ts( substr( (string) $r['captured_at'], 0, 19 ) ) : null;
			$app  = self::app_id( $r['source_app'] ?? '' );
			$kind = 'video' === (string) $r['media_type'] ? 'video' : 'photo';
			if ( null !== $shot && abs( $up - $shot ) <= 6 * HOUR_IN_SECONDS ) {
				$has   = is_numeric( $r['gps_lat'] ?? null ) && is_numeric( $r['gps_lng'] ?? null ) && ( 0.0 !== (float) $r['gps_lat'] || 0.0 !== (float) $r['gps_lng'] );
				$out[] = array( 'user_id' => $uid, 'at' => ZHR_Time::local( $shot ), 'kind' => 'photo', 'app_id' => $app, 'lat' => $has ? (float) $r['gps_lat'] : null, 'lng' => $has ? (float) $r['gps_lng'] : null, 'detail' => $kind . ' taken', 'ref' => 'media:' . (int) $r['id'] );
			} else {
				$out[] = array( 'user_id' => $uid, 'at' => ZHR_Time::local( $up ), 'kind' => 'upload', 'app_id' => $app, 'detail' => $kind . ' added', 'ref' => 'media:' . (int) $r['id'] );
			}
		}
		$n = ZHR_DB::add_activity( $out );
		if ( $rows ) {
			self::set_cursor( 'media', $max );
		} elseif ( null === $cur ) {
			self::set_cursor( 'media', (int) $wpdb->get_var( "SELECT COALESCE(MAX(id),0) FROM {$t}" ) ); // phpcs:ignore
		}
		return $n;
	}

	/** The Jobs app's step log: who did a step and when. The step's kind only (on_my_way, started, note …), never its words. */
	public static function collect_jobs() {
		global $wpdb;
		$t = $wpdb->prefix . 'zdz_job_events';
		if ( ! ZHR_DB::table_exists( $t ) ) {
			return 0;
		}
		$cur = self::cursor( 'jobs' );
		if ( null === $cur ) {
			$since = gmdate( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) ) - self::FIRST_DAYS * DAY_IN_SECONDS );
			$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT id, actor_id, actor_kind, event_type, created_at FROM {$t} WHERE created_at >= %s ORDER BY id ASC LIMIT 3000", $since ), ARRAY_A ); // phpcs:ignore
		} else {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, actor_id, actor_kind, event_type, created_at FROM {$t} WHERE id > %d ORDER BY id ASC LIMIT 3000", $cur ), ARRAY_A ); // phpcs:ignore
		}
		$rows = (array) $rows;
		$out  = array();
		$max  = (int) $cur;
		foreach ( $rows as $r ) {
			$max = max( $max, (int) $r['id'] );
			$uid = (int) $r['actor_id'];
			if ( $uid <= 0 || 'user' !== (string) ( $r['actor_kind'] ?? 'user' ) || null === ZHR_Time::ts( substr( (string) $r['created_at'], 0, 19 ) ) ) {
				continue; // a step the system wrote is when the record was made, not when anyone did anything
			}
			$type  = preg_replace( '/[^a-z0-9_]/', '', strtolower( (string) $r['event_type'] ) );
			$out[] = array( 'user_id' => $uid, 'at' => substr( (string) $r['created_at'], 0, 19 ), 'kind' => 'job', 'app_id' => 'jobs', 'detail' => substr( $type, 0, 40 ), 'ref' => 'job:' . (int) $r['id'] );
		}
		$n = ZHR_DB::add_activity( $out );
		if ( $rows ) {
			self::set_cursor( 'jobs', $max );
		} elseif ( null === $cur ) {
			self::set_cursor( 'jobs', (int) $wpdb->get_var( "SELECT COALESCE(MAX(id),0) FROM {$t}" ) ); // phpcs:ignore
		}
		return $n;
	}

	/**
	 * The Doorhangers app's walks of the last few days: the stretches on foot from start to finish, and a point every 5
	 * minutes of the GPS trace on foot. The walker is who delivered it, else who it was assigned to, else who made it.
	 *
	 * A track point fixed at driving speed carries 'd' (point index 4). Those points are not door hanging: no "walking a
	 * route" point is logged for them, and each driven stretch -- from its first 'd' point to the next point on foot (or
	 * the walk's finish) -- is cut out of the walk's span and logged as a 'drive_span', "driving between blocks". A walk
	 * that was all driving has no door-hanging span at all. Each walk is re-copied whole on every pass while it is in the
	 * window, so a row this pass no longer makes (a point that turned out driven, another walker, a corrected finish) is
	 * removed.
	 */
	public static function collect_walks() {
		global $wpdb;
		$t = $wpdb->prefix . 'zdh_drops';
		if ( ! ZHR_DB::table_exists( $t ) ) {
			return 0;
		}
		$since = gmdate( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) ) - self::WALK_DAYS * DAY_IN_SECONDS );
		$rows  = (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT id, created_by, delivered_by_uid, assigned_uid, status, started_at, finished_at, track_json FROM {$t} WHERE started_at IS NOT NULL AND ( started_at >= %s OR finished_at >= %s OR status = 'in_progress' ) ORDER BY id DESC LIMIT 200", // phpcs:ignore
			$since, $since
		), ARRAY_A );
		$n = 0;
		foreach ( $rows as $r ) {
			$uid = (int) $r['delivered_by_uid'] ?: ( (int) $r['assigned_uid'] ?: (int) $r['created_by'] );
			$s   = ZHR_Time::ts( substr( (string) $r['started_at'], 0, 19 ) );
			if ( $uid <= 0 || null === $s ) {
				continue;
			}
			$done  = 'done' === (string) $r['status'] && ! empty( $r['finished_at'] );
			$fin   = $done ? ZHR_Time::ts( substr( (string) $r['finished_at'], 0, 19 ) ) : null;
			$rows1 = self::walk_rows( $uid, (int) $r['id'], (string) $r['started_at'], $done ? (string) $r['finished_at'] : '', $s, $fin, json_decode( (string) $r['track_json'], true ) );
			// first remove this walk's rows that the re-copy no longer makes as they are (another walker, other times), so
			// the insert below writes them again; INSERT IGNORE alone never updates a row
			if ( $rows1['whole'] ) { // no track to read (none yet, or cleared): what was copied before stays as it is
				ZHR_DB::prune_walk_activity( (int) $r['id'], $rows1['rows'] );
			}
			$n += ZHR_DB::add_activity( $rows1['rows'] );
		}
		return $n;
	}

	/**
	 * One walk's activity rows. $track: the walk's points [lat, lng, ms, acc, 'd'?]. Returns ['rows', 'whole']; 'whole'
	 * is true when the rows are the walk's complete record (a track was there to read), so older rows of the same walk
	 * that are not among them can go.
	 */
	public static function walk_rows( $uid, $drop, $started_at, $finished_at, $s, $fin, $track ) {
		$out = array();
		$pts = array();
		if ( is_array( $track ) ) {
			foreach ( $track as $pt ) {
				if ( ! is_array( $pt ) || count( $pt ) < 3 || ! is_numeric( $pt[0] ) || ! is_numeric( $pt[1] ) || ! is_numeric( $pt[2] ) ) {
					continue;
				}
				$ts = (int) floor( (float) $pt[2] / 1000 ); // the phone's milliseconds
				if ( $ts < $s - HOUR_IN_SECONDS ) {
					continue;
				}
				$pts[] = array( 'ts' => $ts, 'lat' => (float) $pt[0], 'lng' => (float) $pt[1], 'acc' => isset( $pt[3] ) && is_numeric( $pt[3] ) ? (int) $pt[3] : null, 'drive' => isset( $pt[4] ) && 'd' === $pt[4] );
			}
		}
		usort( $pts, function ( $a, $b ) { return $a['ts'] <=> $b['ts']; } );

		// the driven stretches: [first 'd' point (the walk's start when the walk began driving), next point on foot]
		$drives = array();
		$open   = null;
		foreach ( $pts as $i => $p ) {
			if ( $p['drive'] && null === $open ) {
				$open = 0 === $i ? min( $s, $p['ts'] ) : $p['ts'];
			} elseif ( ! $p['drive'] && null !== $open ) {
				$drives[] = array( $open, $p['ts'] );
				$open     = null;
			}
		}
		if ( null !== $open && null !== $fin ) {
			$drives[] = array( $open, $fin ); // drove to the end of a finished walk; a running walk's drive is logged once it ends
		}
		$lo = $s;
		$hi = null !== $fin ? $fin : PHP_INT_MAX;
		foreach ( $drives as $k => $d ) {
			$drives[ $k ] = array( max( $lo, $d[0] ), min( $hi, $d[1] ) );
		}
		$drives = array_values( array_filter( $drives, function ( $d ) { return $d[1] - $d[0] >= 60; } ) );

		$drove = (bool) array_filter( $pts, function ( $p ) { return $p['drive']; } );
		// where the walk's stretches on foot end: its finish; or, while a walk that has driven is still running, its last
		// fix so far. A running walk that has not driven gets no span until it finishes.
		$end = null !== $fin ? $fin : ( $drove && $pts ? max( $s, (int) $pts[ count( $pts ) - 1 ]['ts'] ) : null );
		if ( null !== $end ) {
			if ( null !== $fin && ! $drives && ! $drove ) {
				$out[] = array( 'user_id' => $uid, 'at' => substr( $started_at, 0, 19 ), 'until' => substr( $finished_at, 0, 19 ), 'kind' => 'walk_span', 'app_id' => 'doorhangers', 'detail' => 'door-hanger walk', 'ref' => 'zdh:span:' . $drop );
			} else {
				$cur = $s;
				foreach ( array_merge( $drives, array( array( $end, $end ) ) ) as $d ) {
					if ( $d[0] - $cur >= 60 ) {
						$out[] = array( 'user_id' => $uid, 'at' => ZHR_Time::local( $cur ), 'until' => ZHR_Time::local( $d[0] ), 'kind' => 'walk_span', 'app_id' => 'doorhangers', 'detail' => 'door-hanger walk', 'ref' => 'zdh:span:' . $drop . ':' . $cur . '-' . $d[0] );
					}
					$cur = max( $cur, $d[1] );
				}
			}
		}
		foreach ( $drives as $d ) {
			$out[] = array( 'user_id' => $uid, 'at' => ZHR_Time::local( $d[0] ), 'until' => ZHR_Time::local( $d[1] ), 'kind' => 'drive_span', 'app_id' => 'doorhangers', 'detail' => 'driving between blocks', 'ref' => 'zdh:drv:' . $drop . ':' . $d[0] . '-' . $d[1] );
		}
		// a point on foot every 5 minutes; points fixed while driving are not door hanging and are not logged
		$last = 0;
		foreach ( $pts as $p ) {
			if ( $p['drive'] || $p['ts'] - $last < self::TRACK_STEP_S ) {
				continue;
			}
			$last  = $p['ts'];
			$out[] = array( 'user_id' => $uid, 'at' => ZHR_Time::local( $p['ts'] ), 'kind' => 'walk', 'app_id' => 'doorhangers', 'lat' => round( $p['lat'], 6 ), 'lng' => round( $p['lng'], 6 ), 'acc' => $p['acc'], 'detail' => 'walking a route', 'ref' => 'zdh:pt:' . $drop . ':' . $p['ts'] );
		}
		return array( 'rows' => $out, 'whole' => (bool) $pts );
	}

	/* ------------------------------------------------------------------ the walk timer */

	/** The Doorhangers app's routes for walks, under the platform's REST namespace. */
	private static function walk_route_base() {
		return '/' . ( defined( 'ZDZ_REST_NS' ) ? ZDZ_REST_NS : 'zorderz/v1' ) . self::DOORHANGER_BASE;
	}

	/**
	 * rest_request_after_callbacks: the Doorhangers app's answer is already made; Hours reads it and the request, and
	 * returns the answer unchanged. Only a successful answer to a POST or DELETE counts, for the signed-in person.
	 * The answer may be a WP_REST_Response, a plain array (a callback that did not wrap it) or a WP_Error.
	 */
	public static function watch_rest( $result, $handler, $request ) {
		try {
			if ( ! ( $request instanceof WP_REST_Request ) || ! in_array( $request->get_method(), array( 'POST', 'DELETE' ), true ) ) {
				return $result;
			}
			$route = (string) $request->get_route();
			if ( 0 !== strpos( $route, self::walk_route_base() ) ) {
				return $result;
			}
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			if ( $result instanceof WP_HTTP_Response ) {
				if ( (int) $result->get_status() >= 400 ) {
					return $result;
				}
				$data = $result->get_data();
			} elseif ( is_array( $result ) ) {
				$data = $result;
			} else {
				return $result;
			}
			$uid = get_current_user_id();
			if ( $uid <= 0 ) {
				return $result;
			}
			self::walk_event( $uid, $route, $request, is_array( $data ) ? $data : array() );
		} catch ( Throwable $e ) {
			error_log( 'Hours: the walk timer missed a Doorhangers answer: ' . $e->getMessage() ); // phpcs:ignore
		}
		return $result;
	}

	/** The phone's own clock when it sent one that makes sense (in the last 12 hours, not ahead of now), else now. */
	private static function when( $raw, $now_ts ) {
		$raw = trim( str_replace( 'T', ' ', (string) $raw ) );
		if ( preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(:\d{2})?/', $raw, $m ) ) {
			$ts = ZHR_Time::ts( substr( $m[0], 0, 19 ) );
			if ( null !== $ts && $ts <= $now_ts + ZHR_Time::FUTURE_SLACK_S && $ts >= $now_ts - 12 * HOUR_IN_SECONDS ) {
				return min( $ts, $now_ts );
			}
		}
		return $now_ts;
	}

	/** Which walk event an answer is: start | finish | cancel | '' ; and the drop id. */
	public static function classify( $route, $method, array $params, array $data ) {
		$base = preg_quote( self::walk_route_base(), '#' );
		if ( 'DELETE' === $method ) {
			return preg_match( '#^' . $base . '/(\d+)$#', $route, $m ) ? array( 'cancel', (int) $m[1] ) : array( '', 0 );
		}
		if ( preg_match( '#^' . $base . '/(\d+)/start$#', $route, $m ) ) {
			return array( 'start', (int) $m[1] );
		}
		if ( preg_match( '#^' . $base . '/(\d+)/(finish|done)$#', $route, $m ) ) {
			return array( 'finish', (int) $m[1] );
		}
		if ( preg_match( '#^' . $base . '/(\d+)/(reset|discard-quick)$#', $route, $m ) ) {
			return array( 'cancel', (int) $m[1] );
		}
		if ( self::walk_route_base() === $route ) {
			$d = isset( $params['drop'] ) && is_array( $params['drop'] ) ? $params['drop'] : array();
			if ( 'quick' !== ( $d['method'] ?? '' ) ) {
				return array( '', 0 );
			}
			$id = (int) ( $data['id'] ?? 0 );
			if ( 'in_progress' === ( $d['status'] ?? '' ) ) {
				return array( 'start', $id );
			}
			return array( 'finish', $id ); // a Quick Start walk saved finished (or found already saved)
		}
		return array( '', 0 );
	}

	/** Who walks this drop: who delivered it, else who it is assigned to, else (a walk with neither) the presser. */
	private static function walker( array $data, $presser ) {
		$d = isset( $data['drop'] ) && is_array( $data['drop'] ) ? $data['drop'] : $data;
		foreach ( array( 'delivered_by_uid', 'assigned_uid' ) as $k ) {
			if ( ! empty( $d[ $k ] ) && (int) $d[ $k ] > 0 ) {
				return (int) $d[ $k ];
			}
		}
		return (int) $presser;
	}

	/**
	 * A walk event for the signed-in presser. Start: only the walker's own start starts a clock (a route started from the
	 * office for someone else starts nobody's), and only when they hold Hours. Finish / cancel: the clock that walk
	 * started stops or goes, whoever's it is and whoever pressed it; nothing else is touched.
	 */
	public static function walk_event( $uid, $route, $request, array $data ) {
		$params            = (array) $request->get_params();
		list( $ev, $drop ) = self::classify( $route, $request->get_method(), $params, $data );
		if ( '' === $ev ) {
			return;
		}
		$now    = time();
		$d      = isset( $params['drop'] ) && is_array( $params['drop'] ) ? $params['drop'] : array();
		$lat    = isset( $data['lat'] ) && is_numeric( $data['lat'] ) ? (float) $data['lat'] : null;
		$lng    = isset( $data['lng'] ) && is_numeric( $data['lng'] ) ? (float) $data['lng'] : null;
		$walker = self::walker( $data, $uid );

		if ( 'start' === $ev ) {
			if ( $walker !== (int) $uid || ! zhr_user_can_use( $uid ) || $drop <= 0 ) {
				return;
			}
			$at = self::when( $params['started_at'] ?? ( $d['started_at'] ?? ( $data['started_at'] ?? '' ) ), $now );
			ZHR_DB::add_activity( array( array( 'user_id' => $uid, 'at' => ZHR_Time::local( $at ), 'kind' => 'walk', 'app_id' => 'doorhangers', 'lat' => $lat, 'lng' => $lng, 'detail' => 'route started', 'ref' => 'zdh:start:' . $drop . ':' . $at ) ) );
			ZHR_Clock::start_for_walk( $uid, $drop, $at );
			return;
		}
		// the walk's ids: this one, the live id a Quick Start walk began under, and the saved one it became
		$ids = array( $drop, (int) ( $params['live_id'] ?? 0 ), (int) ( $data['id'] ?? 0 ) );
		if ( 'finish' === $ev ) {
			$at  = self::when( $params['finished_at'] ?? ( $d['finished_at'] ?? '' ), $now );
			$got = ZHR_Clock::stop_for_walk( $ids, $at, 'route_finished' );
			$who = is_array( $got ) ? (int) $got['user_id'] : $walker;
			ZHR_DB::add_activity( array( array( 'user_id' => $who, 'at' => ZHR_Time::local( $at ), 'kind' => 'walk', 'app_id' => 'doorhangers', 'lat' => $lat, 'lng' => $lng, 'detail' => 'route finished', 'ref' => 'zdh:finish:' . $drop . ':' . $at ) ) );
			return;
		}
		// cancel: a walk started by mistake (under 5 minutes) loses its clock entry; a longer one stops at now
		ZHR_Clock::cancel_for_walk( $ids, $now, self::MISTAKE_S );
	}
}
