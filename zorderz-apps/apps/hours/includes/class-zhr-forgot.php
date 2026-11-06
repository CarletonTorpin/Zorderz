<?php
/**
 * ZHR_Forgot -- the forgotten clock-out. The app never invents a clock-out time: a person sets it, and every correction
 * shows who set it.
 *
 *   remind()      a clock still running at the business's reminder time (Hours → Settings; none until it is set) gets
 *                 ONE notice through the platform's alert router: the bell, and a push held to quiet hours. One per
 *                 shift (user meta zhr_open_notice = the entry id), never one per cron tick. Never the shared device.
 *   hints()       for the office only: the day's last app use, last photo, last Jobs step and last appointment, as hints
 *                 for setting a finish. Read from Hours' own copy of what the app saw and the Scheduler's public read.
 *                 Never sent to the worker.
 *   finish_set()  who set a finish later, and when, from the entry's own history.
 *   marks()       for each period chip: shifts with no finish yet, and finishes set later.
 *
 * Nothing here ever ends a shift. The cron hook only reads and sends.
 *
 * @package Zorderz\Hours
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZHR_Forgot {

	const ALERT    = 'zhr_still_clocked_in';
	const CATEGORY = 'hours';   // the business may grant this category the bell and push per role (zdz_alert_role_category_grants)
	const META     = 'zhr_open_notice';
	const PERIODS  = array( 'today', 'this_week', 'last_week', 'this_month', 'last_month' );
	const SET_WHYS = array( 'set_end', 'office_set_end' );

	public static function init() {
		add_action( 'zdz_register_alert_types', array( __CLASS__, 'register_type' ) );
		add_action( 'zhr_collect', array( __CLASS__, 'remind' ), 20 ); // the 15-minute cron, after the collector
	}

	/** The notice's type. The business decides which roles get it on which channel (the alert router's settings). */
	public static function register_type( $router ) {
		if ( ! is_object( $router ) || ! method_exists( $router, 'register_type' ) ) {
			return;
		}
		$router->register_type( self::ALERT, array(
			'label'            => __( 'Still clocked in (Hours)', 'zorderz' ),
			'description'      => __( 'Your clock is still running at the end of the day: clock out, or set the time you finished.', 'zorderz' ),
			'default_channels' => array( 'in_app', 'push' ),
			'source_plugin'    => 'hours',
			'category'         => self::CATEGORY,
		) );
	}

	/** The notice's words. The push shows only the title and push_body (a lock screen): no customer is ever in them. */
	public static function words( array $e, $today, $now_ts = null ) {
		$since = ZHR_Time::time_words( (int) ZHR_Time::ts( $e['start_at'] ), $today );
		if ( 'stale' === ZHR_Time::open_state( $e, null === $now_ts ? time() : (int) $now_ts ) ) {
			// past 16 hours Clock out is refused, so the notice never offers it
			return array(
				'title'     => __( "You're still clocked in", 'zorderz' ),
				/* translators: %s: when the clock started */
				'message'   => sprintf( __( "You're still clocked in since %s, more than 16 hours. Set the time you finished.", 'zorderz' ), $since ),
				/* translators: %s: when the clock started */
				'push_body' => sprintf( __( 'Since %s. Open Hours to set when you finished.', 'zorderz' ), $since ),
			);
		}
		return array(
			'title'     => __( "You're still clocked in", 'zorderz' ),
			/* translators: %s: when the clock started */
			'message'   => sprintf( __( "You're still clocked in since %s. Clock out now, or set the time you finished.", 'zorderz' ), $since ),
			/* translators: %s: when the clock started */
			'push_body' => sprintf( __( 'Since %s. Open Hours to clock out, or to set when you finished.', 'zorderz' ), $since ),
		);
	}

	/** The link the bell and the push open: the home screen, which opens Hours on its card (app.js, ?zhr=finish). */
	public static function link() {
		return add_query_arg( array( 'zhr' => 'finish' ), home_url( '/' ) );
	}

	/** One notice for each clock still running at the reminder time. Returns how many were sent. */
	public static function remind( $now_ts = null ) {
		$now = null === $now_ts ? time() : (int) $now_ts;
		$s   = zhr_get_settings();
		if ( '' === (string) ( $s['remind_at'] ?? '' ) ) {
			return 0;
		}
		if ( ! class_exists( 'ZDZ_Alert_Router' ) || ! method_exists( 'ZDZ_Alert_Router', 'send' ) ) {
			return 0; // nothing to send with, and nothing marked, so a later router still reminds
		}
		$today = current_time( 'Y-m-d' );
		$sent  = 0;
		foreach ( ZHR_DB::running_all() as $uid => $e ) {
			$uid = (int) $uid;
			if ( ! zhr_user_can_use( $uid ) ) {
				continue; // the shared device, and anyone who no longer holds Hours
			}
			$due = ZHR_Time::remind_ts( $e, $s );
			if ( null === $due || $now < $due || (int) get_user_meta( $uid, self::META, true ) === (int) $e['id'] ) {
				continue;
			}
			if ( ! ZHR_DB::lock( $uid ) ) {
				continue; // the next tick tries again
			}
			try {
				if ( function_exists( 'wp_cache_delete' ) ) {
					wp_cache_delete( $uid, 'user_meta' ); // read the mark fresh under the lock, not this request's cache
				}
				$run = ZHR_DB::running( $uid );
				if ( ! $run || (int) $run['id'] !== (int) $e['id'] || (int) get_user_meta( $uid, self::META, true ) === (int) $e['id'] ) {
					continue;
				}
				update_user_meta( $uid, self::META, (int) $e['id'] ); // marked first: a send that fails is never repeated each tick
			} finally {
				ZHR_DB::unlock( $uid );
			}
			$w    = self::words( $e, $today, $now );
			$used = (array) ZDZ_Alert_Router::send( self::ALERT, $uid, $w['title'], $w['message'], array(
				'plugin'    => 'hours',
				'source_id' => (int) $e['id'],
				'url'       => self::link(),
				'push_body' => $w['push_body'],
				'sender_id' => 0,
			) );
			$sent++;
			error_log( 'Hours: still-clocked-in notice for entry ' . (int) $e['id'] . ' (' . ( $used ? implode( ', ', $used ) : 'no channel' ) . ')' ); // phpcs:ignore -- ids and channels only
		}
		return $sent;
	}

	/** A person's initials, as the team sees them (the platform's person colour, else from the display name). */
	public static function initials( $uid ) {
		$uid = (int) $uid;
		foreach ( zhr_people() as $p ) {
			if ( (int) $p['id'] === $uid && '' !== (string) $p['initials'] ) {
				return (string) $p['initials'];
			}
		}
		return zhr_initials_of( $uid );
	}

	/**
	 * The last finish set later on this entry: ['by' => initials, 'at' => when it was set ('Y-m-d H:i:s'),
	 * 'office' => set by someone other than the person], or null.
	 */
	public static function finish_set( array $e ) {
		if ( empty( $e['end_at'] ) ) {
			return null;
		}
		$h = json_decode( (string) ( $e['history_json'] ?? '' ), true );
		if ( ! is_array( $h ) ) {
			return null;
		}
		// the LAST change of the end decides: a finish set later and changed since (an edit, a meal split) is that later
		// change's, and the row no longer says who set it; the history still does
		for ( $i = count( $h ) - 1; $i >= 0; $i-- ) {
			$x = $h[ $i ];
			if ( empty( $x['before'] ) || ! array_key_exists( 'end_at', (array) $x['before'] ) ) {
				continue;
			}
			if ( in_array( (string) ( $x['why'] ?? '' ), self::SET_WHYS, true ) ) {
				return array( 'by' => self::initials( (int) $x['by'] ), 'at' => (string) ( $x['at'] ?? '' ), 'office' => 'office_set_end' === $x['why'] );
			}
			return null;
		}
		return null;
	}

	/** When the person asked the office ('Y-m-d H:i:s'), from the history, or ''. */
	public static function asked_at( array $e ) {
		$h = json_decode( (string) ( $e['history_json'] ?? '' ), true );
		foreach ( array_reverse( is_array( $h ) ? $h : array() ) as $x ) {
			if ( 'ask_office' === ( $x['why'] ?? '' ) ) {
				return (string) ( $x['at'] ?? '' );
			}
		}
		return '';
	}

	/**
	 * Hints for the office, from what the app saw that day after the clock-in (at most 16 hours on): the last app use,
	 * the last photo, the last Jobs step, and the end of the person's last appointment. 'HH:MM' each, or ''. Hints only:
	 * the office decides; nothing here sets a time.
	 */
	public static function hints( array $e, $viewer ) {
		$uid  = (int) $e['user_id'];
		$s    = (int) ZHR_Time::ts( $e['start_at'] );
		$to   = ZHR_Time::local( min( time(), $s + ZHR_Time::MAX_ENTRY_MIN * 60 ) );
		$out  = array( 'last_app' => '', 'last_photo' => '', 'last_job' => '', 'last_appointment' => '' );
		$last = array();
		foreach ( ZHR_DB::activity( $uid, $e['start_at'], $to, 5000 ) as $r ) {
			$k = (string) $r['kind'];
			$t = substr( (string) $r['at'], 0, 19 );
			if ( $t < $e['start_at'] ) {
				continue;
			}
			$slot = in_array( $k, array( 'in_app', 'heartbeat', 'login' ), true ) ? 'last_app' : ( in_array( $k, array( 'photo', 'upload' ), true ) ? 'last_photo' : ( 'job' === $k ? 'last_job' : '' ) );
			if ( '' !== $slot && ( ! isset( $last[ $slot ] ) || $t > $last[ $slot ] ) ) {
				$last[ $slot ] = $t;
			}
		}
		foreach ( $last as $k => $t ) {
			$out[ $k ] = substr( $t, 11, 5 ) . ( substr( $t, 0, 10 ) !== $e['work_date'] ? '+1' : '' );
		}
		foreach ( (array) ( ZHR_Schedule::for_person( $uid, $e['work_date'], $e['work_date'], $viewer )[ $e['work_date'] ] ?? array() ) as $a ) {
			if ( empty( $a['all_day'] ) && $a['end'] > $out['last_appointment'] ) {
				$out['last_appointment'] = $a['end'];
			}
		}
		return $out;
	}

	/**
	 * For each period chip, how many shifts in it have no finish yet ('open': past 16 hours, from an earlier day, or with
	 * the office) and how many finishes were set later ('set'). $uid's own, for the person or the office.
	 */
	public static function marks( $uid, $settings, $today ) {
		$ranges = array();
		$lo     = $today;
		$hi     = $today;
		$keys   = self::PERIODS;
		if ( '' !== ( $settings['pay_period']['kind'] ?? '' ) ) {
			$keys = array_merge( $keys, array( 'this_pay_period', 'last_pay_period' ) );
		}
		foreach ( $keys as $p ) {
			list( $f, $t ) = ZHR_Time::period( $p, $today, (int) $settings['week_start'], '', '', (array) ( $settings['pay_period'] ?? array() ) );
			$ranges[ $p ] = array( $f, $t );
			$lo = min( $lo, $f );
			$hi = max( $hi, $t );
		}
		$out = array_fill_keys( $keys, array( 'open' => 0, 'set' => 0 ) );
		$now = time();
		foreach ( ZHR_DB::entries( (int) $uid, $lo, $hi ) as $e ) {
			$open = empty( $e['end_at'] ) && ( 'parked' === ZHR_Time::open_state( $e, $now ) || ZHR_Clock::asks_finish( $e, $now ) );
			$set  = ! $open && null !== self::finish_set( $e );
			if ( ! $open && ! $set ) {
				continue;
			}
			foreach ( $ranges as $p => $r ) {
				if ( $e['work_date'] >= $r[0] && $e['work_date'] <= $r[1] ) {
					$out[ $p ][ $open ? 'open' : 'set' ]++;
				}
			}
		}
		return $out;
	}
}
