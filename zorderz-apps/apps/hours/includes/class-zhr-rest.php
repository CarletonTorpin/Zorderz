<?php
/**
 * ZHR_REST -- <ZDZ_REST_NS>/hours/…. Who may see what is decided here and only here, on every request (ZHR_Access):
 *   - anyone who holds the Hours app sees and changes their OWN hours (changes: within the business's edit window);
 *   - the admin tier sees everyone's, adds or fixes anyone's at any date, and sets the standard hours and the rules;
 *   - a crew lead, when the business turned it on, reads their crew's hours as the person sees them, and changes nothing;
 *   - the shared device and demo mode never hold the app.
 * A `user` parameter naming someone else is refused for everyone the ladder does not let through ("not found").
 *
 * A reading of words or a photo only ever PROPOSES hours (parse): nothing is written until a person presses Add, and
 * then the server checks every stretch again and adds it only for the one person the reading attributed it to, or the
 * person a human chose for a review item (ZHR_Attribution). The screen's word is never taken for whose hours they are.
 *
 * POST /finish {id, time} sets the finish of an open shift (the person's own, in their edit window, or anyone's for the
 * admin tier), kept as a correction with who set it; POST /ask-office {id} leaves the person's own open shift with the
 * office. A shift's minutes are counted only while its clock really runs: past 16 hours or left with the office, it
 * counts nothing until a person sets the finish (the app never invents one).
 *
 * @package Zorderz\Hours
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZHR_REST {

	const BASE = '/hours';

	/** The platform's REST namespace (every route sits under it: <ZDZ_REST_NS>/hours/…). */
	public static function ns() {
		return defined( 'ZDZ_REST_NS' ) ? ZDZ_REST_NS : 'zorderz/v1';
	}

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes() {
		$ns    = self::ns();
		$b     = self::BASE;
		$use   = array( __CLASS__, 'can_use' );
		$admin = array( __CLASS__, 'can_admin' );
		register_rest_route( $ns, $b . '/summary', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'summary' ), 'permission_callback' => $use ) );
		register_rest_route( $ns, $b . '/day', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'day' ), 'permission_callback' => $use ) );
		register_rest_route( $ns, $b . '/team', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'team' ), 'permission_callback' => $admin ) );
		register_rest_route( $ns, $b . '/clock-in', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'clock_in' ), 'permission_callback' => $use ) );
		register_rest_route( $ns, $b . '/clock-out', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'clock_out' ), 'permission_callback' => $use ) );
		register_rest_route( $ns, $b . '/finish', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'finish' ), 'permission_callback' => $use ) );
		register_rest_route( $ns, $b . '/ask-office', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'ask_office' ), 'permission_callback' => $use ) );
		register_rest_route( $ns, $b . '/meal-start', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'meal_start' ), 'permission_callback' => $use ) );
		register_rest_route( $ns, $b . '/meal-answer', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'meal_answer' ), 'permission_callback' => $use ) );
		register_rest_route( $ns, $b . '/waivers', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'waivers' ), 'permission_callback' => $admin ) );
		register_rest_route( $ns, $b . '/ping', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'ping' ), 'permission_callback' => $use ) );
		register_rest_route( $ns, $b . '/parse', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'parse' ), 'permission_callback' => $use ) );
		register_rest_route( $ns, $b . '/entries', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'create' ), 'permission_callback' => $use ) );
		register_rest_route( $ns, $b . '/entries/batch', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'batch' ), 'permission_callback' => $use ) );
		register_rest_route( $ns, $b . '/entries/(?P<id>\d+)', array(
			array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'update' ), 'permission_callback' => $use ),
			array( 'methods' => 'DELETE', 'callback' => array( __CLASS__, 'delete' ), 'permission_callback' => $use ),
		) );
		register_rest_route( $ns, $b . '/settings', array(
			array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'get_settings' ), 'permission_callback' => $admin ),
			array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'save_settings' ), 'permission_callback' => $admin ),
		) );
	}

	public static function can_use() {
		return zhr_user_can_use( get_current_user_id() );
	}

	public static function can_admin() {
		return zhr_user_is_admin( get_current_user_id() );
	}

	/**
	 * The person a request is about: yourself, or someone the ladder lets the signed-in person reach for $action
	 * ('view' or 'change'). Anyone else is "not found" (never whose).
	 */
	public static function target( WP_REST_Request $req, $action = 'view' ) {
		$me  = get_current_user_id();
		$raw = $req->get_param( 'user' );
		$uid = ( null === $raw || '' === $raw || 'me' === $raw ) ? $me : (int) $raw;
		$d   = zhr_access( $me, $uid, $action );
		if ( ! $d['allow'] ) {
			if ( 'no_person' === $d['why'] || 'not_yours' === $d['why'] ) {
				return new WP_Error( 'no_person', __( 'That person was not found.', 'zorderz' ), array( 'status' => 404 ) );
			}
			return new WP_Error( 'forbidden', __( 'Hours is not available on this account.', 'zorderz' ), array( 'status' => 403 ) );
		}
		return $uid;
	}

	private static function today() {
		return current_time( 'Y-m-d' );
	}

	private static function name_of( $uid ) {
		$u = get_userdata( (int) $uid );
		return $u ? (string) $u->display_name : __( 'Someone', 'zorderz' );
	}

	/** An app's name as the platform shows it, from its own registration (else its id, in words). */
	private static function app_name( $app ) {
		static $names = null;
		if ( null === $names ) {
			$names = array();
			if ( class_exists( 'ZDZ_Plugin_API' ) && method_exists( 'ZDZ_Plugin_API', 'get_instance' ) ) {
				foreach ( (array) ZDZ_Plugin_API::get_instance()->get_all_apps() as $id => $a ) {
					$cfg            = is_object( $a ) && method_exists( $a, 'get_config' ) ? (array) $a->get_config() : array();
					$names[ $id ]   = (string) ( $cfg['nm'] ?? '' );
				}
			}
		}
		$app = (string) $app;
		return '' !== ( $names[ $app ] ?? '' ) ? $names[ $app ] : ucwords( str_replace( array( '-', '_' ), ' ', preg_replace( '/^zdz-/', '', $app ) ) );
	}

	/* ------------------------------------------------------------------ shaping */

	/** Activity rows -> evidence events (Hours' own clock rows are the claim, not evidence of it). */
	private static function evidence_events( array $rows ) {
		$ev = array();
		foreach ( $rows as $r ) {
			if ( 'clock' === $r['kind'] ) {
				continue;
			}
			$t = ZHR_Time::ts( substr( (string) $r['at'], 0, 19 ) );
			if ( null === $t ) {
				continue;
			}
			$row = array( 'ts' => $t, 'kind' => (string) $r['kind'] );
			if ( in_array( $r['kind'], ZHR_Time::SPAN_KINDS, true ) ) {
				$row['until'] = (int) ZHR_Time::ts( substr( (string) $r['until'], 0, 19 ) );
			}
			$ev[] = $row;
		}
		return $ev;
	}

	/**
	 * One entry for the screen. $viewer decides can_edit. $full: the admin tier's view (evidence, flags, places,
	 * history). Without it (everyone outside the admin tier, an admin's "See it as they see it", a crew lead's reading)
	 * those are never computed or sent: nothing on a person's own screen grades them.
	 */
	public static function out_entry( array $e, array $others, $settings, $events, $viewer, $now_ts, $full = null ) {
		$is_admin = null === $full ? zhr_user_is_admin( $viewer ) : (bool) $full;
		$state    = ZHR_Time::open_state( $e, $now_ts );   // '' | live | stale | parked
		$running  = 'live' === $state;                     // only a live clock counts on (and shows "now")
		$s        = ZHR_Time::ts( $e['start_at'] );
		$end_ts   = $running ? $now_ts : ( '' === $state ? ZHR_Time::ts( $e['end_at'] ) : $s + 60 );
		$minutes  = $running ? (int) floor( max( 0, $now_ts - $s ) / 60 ) : ( '' === $state ? (int) $e['minutes'] : 0 );
		$hist     = json_decode( (string) $e['history_json'], true );
		$editable = $is_admin || ( (int) $e['user_id'] === (int) $viewer && $e['work_date'] >= ZHR_Time::edit_from( self::today(), $settings ) );
		$flags    = ZHR_Time::flags( $e, $others, $settings, $now_ts );
		$rt       = ZHR_Time::remind_ts( $e, $settings );
		$out      = array(
			'id'           => (int) $e['id'],
			'date'         => $e['work_date'],
			'start'        => substr( $e['start_at'], 11, 5 ),
			'end'          => '' !== $state ? null : substr( $e['end_at'], 11, 5 ),
			'next_day'     => '' === $state && substr( $e['end_at'], 0, 10 ) !== substr( $e['start_at'], 0, 10 ),
			'start_at'     => $e['start_at'],
			'end_at'       => $e['end_at'],
			'minutes'      => $minutes,
			'running'      => $running,
			'source'       => $e['source'],
			// only the entry's own person, a reader the ladder let through, or the admin tier ever reaches here: codes
			// become the names again from the on-site ledger only for the person themselves or the admin tier
			'note'         => ZHR_Private::decode( $e['note'], (int) $e['user_id'] === (int) get_current_user_id() || zhr_user_is_admin( get_current_user_id() ) ),
			'private'      => ZHR_Private::has_codes( $e['note'] ),
			'can_edit'     => $editable,
			'changes'      => is_array( $hist ) ? count( $hist ) : 0,
			// the one flag a person is told: their own clock has run past 14 hours (a forgotten clock-out)
			'running_long' => in_array( 'running_long', $flags, true ),
			// a shift with no finish yet, and whether to ask for it first; a finish set later, with who set it; the
			// reminder time has passed on a clock still running (offer "set the time")
			'unfinished'   => 'live' === $state ? '' : $state,
			'ask_finish'   => '' !== $state && ZHR_Clock::asks_finish( $e, $now_ts ),
			'finish_set'   => ZHR_Forgot::finish_set( $e ),
			'remind_due'   => $running && null !== $rt && $now_ts >= $rt,
		);
		if ( 'parked' === $state ) {
			$out['asked_at'] = ZHR_Forgot::asked_at( $e );
		}
		if ( 'doorhangers' === $e['source'] ) {
			// what the walk was: minutes on foot hanging doors, and minutes driven between blocks. It describes the work
			// and grades nothing, so the person sees it too.
			$near        = ZHR_Time::events_near( $events, $s, $end_ts );
			$out['walk'] = array(
				'foot'  => ZHR_Time::span_minutes( $near, 'walk_span', $s, $end_ts ),
				'drive' => ZHR_Time::span_minutes( $near, 'drive_span', $s, $end_ts ),
			);
		}
		if ( (int) $e['created_by'] && (int) $e['created_by'] !== (int) $e['user_id'] ) {
			$out['added_by'] = self::name_of( $e['created_by'] );
		}
		if ( ! $is_admin ) {
			return $out;
		}
		$evid               = ZHR_Time::evidence( $s, $end_ts, ZHR_Time::events_near( $events, $s, $end_ts ) );
		$out['flags']       = $flags;
		$out['evidence']    = array( 'pct' => $evid['pct'], 'level' => $evid['level'], 'kinds' => $evid['kinds'] );
		$out['start_place'] = null !== $e['start_lat'] ? array( 'lat' => $e['start_lat'], 'lng' => $e['start_lng'], 'acc' => $e['start_acc'] ) : null;
		$out['end_place']   = null !== $e['end_lat'] ? array( 'lat' => $e['end_lat'], 'lng' => $e['end_lng'], 'acc' => $e['end_acc'] ) : null;
		if ( is_array( $hist ) && $hist ) {
			$out['history'] = array();
			foreach ( $hist as $h ) {
				$before = (array) ( $h['before'] ?? array() );
				if ( isset( $before['note'] ) ) {
					$before['note'] = ZHR_Private::decode( $before['note'], true );
				}
				$out['history'][] = array( 'at' => (string) ( $h['at'] ?? '' ), 'by' => self::name_of( $h['by'] ?? 0 ), 'why' => (string) ( $h['why'] ?? '' ), 'before' => $before );
			}
		}
		if ( '' !== $state && 'live' !== $state ) {
			$out['hints'] = ZHR_Forgot::hints( $e, $viewer ); // the office's hints for a finish, never sent to the person
		}
		return $out;
	}

	/**
	 * May this request have the admin tier's full view? Never for anyone outside the admin tier, whatever they ask. An
	 * admin who asks view=worker ("See it as they see it") gets exactly what $uid gets: the person's answer for a person
	 * or for themselves, and the full view for another admin, whose own screen is the full view.
	 */
	public static function full_view( WP_REST_Request $req, $uid = 0 ) {
		$me = get_current_user_id();
		if ( ! zhr_user_is_admin( $me ) ) {
			return false;
		}
		if ( 'worker' !== (string) $req->get_param( 'view' ) ) {
			return true;
		}
		return (int) $uid > 0 && (int) $uid !== (int) $me && zhr_user_is_admin( $uid );
	}

	/**
	 * Whose eyes a read is built for: the signed-in person, or -- an admin's "See it as they see it", or a crew lead's
	 * reading of their crew -- the person's own (so what they may change, and which meal answers they may give, are theirs).
	 */
	private static function viewer_for( $uid, $full ) {
		$me = get_current_user_id();
		return ( ! $full && (int) $uid !== (int) $me ) ? (int) $uid : (int) $me;
	}

	/** A day's or a period's totals without the comparison with the standard hours (the person's own shape). */
	private static function worker_totals( array $t ) {
		return array_diff_key( $t, array_flip( array( 'standard', 'standard_to_date', 'over', 'under', 'overtime', 'regular' ) ) );
	}

	/**
	 * Overtime for $uid over [$from, $to] by the business's lines, or null when it has none. A weekly line is counted
	 * over whole weeks (the week holding $from is read from its first day), then cut to the period.
	 * Returns ['days' => [date => ['regular','overtime']], 'regular' => n, 'overtime' => n].
	 */
	public static function overtime_for( $uid, $from, $to, $settings ) {
		if ( ! ZHR_Rules::has_overtime( $settings ) ) {
			return null;
		}
		$ws    = (int) $settings['week_start'];
		$start = null !== ( $settings['overtime']['weekly_after'] ?? null ) ? ZHR_Time::week_start( $from, $ws ) : $from;
		$by    = array();
		foreach ( ZHR_DB::entries( (int) $uid, $start, $to ) as $e ) {
			if ( $e['work_date'] >= $start && $e['work_date'] <= $to ) {
				$by[ $e['work_date'] ][] = $e;
			}
		}
		$days = array();
		$now  = time();
		foreach ( ZHR_Time::dates( $start, $to ) as $d ) {
			$days[] = array( 'date' => $d, 'worked' => ZHR_Time::day_totals( $settings, $d, $by[ $d ] ?? array(), $now )['worked'] );
		}
		$ot  = ZHR_Rules::overtime( $days, $settings['overtime'], $ws );
		$out = array( 'days' => array(), 'regular' => 0, 'overtime' => 0 );
		foreach ( $ot['days'] as $d => $row ) {
			if ( $d >= $from && $d <= $to ) {
				$out['days'][ $d ] = $row;
				$out['regular']   += $row['regular'];
				$out['overtime']  += $row['overtime'];
			}
		}
		return $out;
	}

	/**
	 * One person's days over [$from, $to] with totals; the screen's main answer. $full: the admin tier's view (see
	 * out_entry()); null = decided by $viewer's tier. A person's own answer never carries the standard hours, over /
	 * under, overtime, evidence, flags, places or history: they are left out here, not hidden by the screen.
	 */
	public static function build( $uid, $from, $to, $viewer, $with_scheduled = true, $full = null, $with_marks = false ) {
		$full     = null === $full ? zhr_user_is_admin( $viewer ) : (bool) $full;
		$settings = zhr_get_settings();
		$now      = time();
		$entries  = ZHR_DB::entries( $uid, $from, $to );
		$events   = self::evidence_events( ZHR_DB::activity( $uid, ZHR_Time::add_days( $from, -1 ) . ' 00:00:00', ZHR_Time::add_days( $to, 1 ) . ' 23:59:59', 20000 ) );
		$by_day   = array();
		$running  = null;
		foreach ( $entries as $e ) {
			if ( empty( $e['end_at'] ) && '' === $e['end_review'] ) {
				$running = $e; // the running clock: never a shift left with the office
			}
			if ( $e['work_date'] < $from || $e['work_date'] > $to ) {
				continue; // a clock still running from before the period is shown at the top, not in a day
			}
			$by_day[ $e['work_date'] ][] = $e;
		}
		$sched   = $with_scheduled ? ZHR_Schedule::for_person( $uid, $from, $to, $viewer ) : array();
		$days    = array();
		$rows    = array();
		$today   = self::today();
		$answers = ZHR_DB::meal_answers( $uid, $from, $to )[ (int) $uid ] ?? array();
		$waivers = self::waivers_of( $uid );
		$ot      = $full ? self::overtime_for( $uid, $from, $to, $settings ) : null;
		foreach ( ZHR_Time::dates( $from, $to ) as $d ) {
			$list           = $by_day[ $d ] ?? array();
			$tot            = ZHR_Time::day_totals( $settings, $d, $list, $now, array( 'waiver1' => $waivers['first_periods'], 'waiver2' => $waivers['second_periods'], 'answers' => $answers[ $d ] ?? array() ) );
			$rows[]         = $tot;
			$tot['meals']   = self::meals_out( $tot['meals'], $d, $viewer, $uid, $settings, $tot['running'] || $tot['unfinished'] > 0 || ( $d === $today && self::on_meal( $uid ) ), $full );
			$tot['entries'] = array();
			foreach ( $list as $e ) {
				$tot['entries'][] = self::out_entry( $e, $entries, $settings, $events, $viewer, $now, $full );
			}
			$tot['scheduled'] = $sched[ $d ] ?? array();
			$tot['future']    = $d > $today;
			if ( $ot ) {
				$tot['overtime'] = (int) ( $ot['days'][ $d ]['overtime'] ?? 0 );
			}
			if ( ! $full ) {
				unset( $tot['standard'], $tot['std_start'], $tot['std_end'] );
			}
			$days[] = $tot;
		}
		$totals = ZHR_Time::range_totals( $rows, $today );
		if ( $ot ) {
			$totals['overtime'] = $ot['overtime'];
			$totals['regular']  = $ot['regular'];
		}
		// the person's shifts with no finish yet, whatever their day (the Add hours sheet shows them), and those left with
		// the office (the card says so)
		$open = array();
		$wait = array();
		foreach ( ZHR_DB::open_entries( $uid ) as $oe ) {
			$st = ZHR_Time::open_state( $oe, $now );
			if ( 'live' === $st && (string) $oe['work_date'] >= $today ) {
				continue; // today's running clock is the card itself
			}
			$row    = array( 'id' => (int) $oe['id'], 'date' => $oe['work_date'], 'start' => substr( $oe['start_at'], 11, 5 ), 'state' => 'live' === $st ? 'stale' : $st );
			$open[] = $row;
			if ( 'parked' === $st ) {
				$wait[] = $row + array( 'asked_at' => ZHR_Forgot::asked_at( $oe ) );
			}
		}
		return array(
			'open_shifts' => $open,
			'waiting'     => $wait,
			'marks'       => $with_marks ? ZHR_Forgot::marks( $uid, $settings, $today ) : null,
			'person'      => array( 'id' => (int) $uid, 'name' => self::name_of( $uid ) ),
			'from'        => $from,
			'to'          => $to,
			'days'        => $days,
			'totals'      => $full ? $totals : self::worker_totals( $totals ),
			'running'     => $running ? self::out_entry( $running, $entries, $settings, $events, $viewer, $now, $full ) : null,
			'edit_from'   => $full ? null : ZHR_Time::edit_from( $today, $settings ),
			'today'       => $today,
			'on_meal'     => self::on_meal( $uid ),
			'waivers'     => ZHR_Rules::has_meals( $settings ) ? $waivers : null,
			'meal_rules'  => ZHR_Rules::has_meals( $settings ),
			'has_standard' => ZHR_Time::has_standard( $settings ),
		);
	}

	/**
	 * Signed meal-period waivers on file for a person. Each is kept as the periods it was in force, [[signed, revoked|null],
	 * …], so a waiver signed today never covers an earlier day and a revoked one still covers the days it was on file.
	 * first / second: in force today; first_on / second_on: the date signed of the one in force, or ''.
	 */
	public static function waivers_of( $uid ) {
		$out = array();
		foreach ( array( 'first' => 1, 'second' => 2 ) as $k => $n ) {
			$p                      = self::waiver_periods( $uid, $n );
			$open                   = end( $p );
			$cur                    = ( $open && empty( $open[1] ) ) ? (string) $open[0] : '';
			$out[ $k ]              = '' !== $cur && $cur <= self::today();
			$out[ $k . '_on' ]      = $cur;
			$out[ $k . '_periods' ] = $p;
		}
		return $out;
	}

	private static function waiver_periods( $uid, $n ) {
		$raw = get_user_meta( (int) $uid, 'zhr_meal_waiver' . (int) $n, true );
		if ( is_string( $raw ) && ZHR_Time::is_date( $raw ) ) {
			return array( array( $raw, null ) ); // a date alone
		}
		$p   = is_string( $raw ) ? json_decode( $raw, true ) : $raw;
		$out = array();
		foreach ( is_array( $p ) ? $p : array() as $row ) {
			if ( is_array( $row ) && ZHR_Time::is_date( (string) ( $row[0] ?? '' ) ) ) {
				$out[] = array( (string) $row[0], ZHR_Time::is_date( (string) ( $row[1] ?? '' ) ) ? (string) $row[1] : null );
			}
		}
		return $out;
	}

	/** Since when the person has been on a meal break (they pressed Meal break and have not clocked back in), or null. */
	public static function on_meal( $uid ) {
		$t = (int) get_user_meta( (int) $uid, 'zhr_on_meal', true );
		if ( $t <= 0 || time() - $t > 6 * HOUR_IN_SECONDS || ZHR_DB::running( $uid ) ) {
			return null;
		}
		return array( 'since' => ZHR_Time::local( $t ), 'minutes' => (int) floor( ( time() - $t ) / 60 ) );
	}

	/** The meal check for the screen: words, whether it needs an answer, and who may give which answer. */
	private static function meals_out( array $mc, $date, $viewer, $uid, $settings, $open_day = false, $full = null ) {
		$office = null === $full ? zhr_user_is_admin( $viewer ) : (bool) $full;
		$self   = (int) $viewer === (int) $uid;
		$within = $date >= ZHR_Time::edit_from( self::today(), $settings );
		// a day still on the clock (or on a meal break) is not judged yet: no question until it is over
		$ask  = ! $open_day && $date <= self::today();
		$list = array();
		foreach ( $mc['meals'] as $m ) {
			if ( ! $m['required'] && 'not_required' === $m['status'] ) {
				continue;
			}
			$list[] = array(
				'no'                => $m['no'],
				'status'            => $m['status'],
				'words'             => ZHR_Time::meal_words( $m ),
				'violation'         => $m['violation'],
				'answer'            => $m['answer'],
				// the person answers for themselves (within the edit window); the office may only record "not provided"
				'can_answer'        => $ask && $m['violation'] && ( ( $self && $within ) || $office ),
				'can_say_voluntary' => $ask && $m['violation'] && $self && $within,
			);
		}
		return array( 'list' => $list, 'premium' => $mc['premium'], 'premium_minutes' => $mc['premium_minutes'], 'needs_answer' => $ask && $mc['needs_answer'], 'progress' => $mc['progress'] ?? null );
	}

	/** The period a request names, by the business's week start and pay period. */
	private static function period_of( WP_REST_Request $req ) {
		$s = zhr_get_settings();
		return ZHR_Time::period( (string) $req->get_param( 'period' ), self::today(), (int) $s['week_start'], (string) $req->get_param( 'from' ), (string) $req->get_param( 'to' ), $s['pay_period'] );
	}

	/* ------------------------------------------------------------------ reads */

	public static function summary( WP_REST_Request $req ) {
		$uid = self::target( $req, 'view' );
		if ( is_wp_error( $uid ) ) {
			return $uid;
		}
		list( $from, $to, $label ) = self::period_of( $req );
		$full         = self::full_view( $req, $uid );
		$out          = self::build( $uid, $from, $to, self::viewer_for( $uid, $full ), true, $full, true );
		$out['label'] = $label;
		return rest_ensure_response( $out );
	}

	/** One day in full: entries, what was scheduled, and for the admin tier only what the app saw (with places). */
	public static function day( WP_REST_Request $req ) {
		$uid = self::target( $req, 'view' );
		if ( is_wp_error( $uid ) ) {
			return $uid;
		}
		$date = (string) $req->get_param( 'date' );
		if ( ! ZHR_Time::is_date( $date ) ) {
			return new WP_Error( 'date', __( 'That day could not be read.', 'zorderz' ), array( 'status' => 400 ) );
		}
		ZHR_Collect::maybe_run();
		$full = self::full_view( $req, $uid );
		$out  = self::build( $uid, $date, $date, self::viewer_for( $uid, $full ), true, $full );
		if ( ! $full ) {
			return rest_ensure_response( $out ); // "what the app saw" is the admin tier's; it is still collected
		}
		$rows = ZHR_DB::activity( $uid, $date . ' 00:00:00', $date . ' 23:59:59', 3000 );
		$tl   = array();
		$last = array();
		foreach ( $rows as $r ) {
			$k   = (string) $r['kind'];
			$app = (string) $r['app_id'];
			$t   = substr( (string) $r['at'], 11, 5 );
			// heartbeats say "still in the app"; one line per app per 15 minutes is enough to read
			if ( in_array( $k, array( 'heartbeat', 'in_app' ), true ) ) {
				$key = $app . '|' . substr( $t, 0, 2 ) . intdiv( (int) substr( $t, 3, 2 ), 15 );
				if ( isset( $last[ $key ] ) && empty( $r['lat'] ) ) {
					continue;
				}
				$last[ $key ] = true;
			}
			$tl[] = array(
				'time'  => $t,
				'kind'  => $k,
				'app'   => '' !== $app ? self::app_name( $app ) : '',
				'what'  => self::describe( $k, (string) $r['detail'] ),
				'place' => ( null !== $r['lat'] && '' !== (string) $r['lat'] ) ? array( 'lat' => (float) $r['lat'], 'lng' => (float) $r['lng'], 'acc' => null !== $r['acc'] ? (int) $r['acc'] : null ) : null,
				'until' => ! empty( $r['until'] ) ? substr( (string) $r['until'], 11, 5 ) : null,
			);
		}
		$out['timeline'] = array_slice( $tl, 0, 400 );
		return rest_ensure_response( $out );
	}

	private static function describe( $kind, $detail ) {
		switch ( $kind ) {
			case 'login':      return __( 'Signed in', 'zorderz' );
			case 'heartbeat':  return __( 'In the app', 'zorderz' );
			case 'in_app':     return 'app_open' === $detail ? __( 'Opened', 'zorderz' ) : ( 'search' === $detail ? __( 'Searched', 'zorderz' ) : __( 'Used', 'zorderz' ) );
			case 'photo':      return 'video taken' === $detail ? __( 'Took a video', 'zorderz' ) : __( 'Took a photo', 'zorderz' );
			case 'upload':     return __( 'Added a photo', 'zorderz' );
			/* translators: %s: a Jobs step such as "on my way" */
			case 'job':        return sprintf( __( 'Jobs: %s', 'zorderz' ), str_replace( '_', ' ', $detail ) );
			case 'walk':       return ucfirst( $detail );
			case 'walk_span':  return __( 'Door-hanger walk', 'zorderz' );
			case 'drive_span': return __( 'Driving between blocks', 'zorderz' );
			case 'clock':      return ucfirst( $detail );
			case 'ping':       return __( 'Phone was here', 'zorderz' );
		}
		return ucfirst( str_replace( '_', ' ', $detail ) );
	}

	/** Everyone's totals for a period (admin tier). Evidence is per person, on opening them. */
	public static function team( WP_REST_Request $req ) {
		$s = zhr_get_settings();
		list( $from, $to, $label ) = self::period_of( $req );
		$all     = ZHR_DB::entries( 0, $from, $to );
		$by_user = array();
		foreach ( $all as $e ) {
			$by_user[ $e['user_id'] ][] = $e;
		}
		$now     = time();
		$out     = array();
		$answers = ZHR_DB::meal_answers( 0, $from, $to );
		foreach ( zhr_people() as $p ) {
			$w      = self::waivers_of( $p['id'] );
			$list   = $by_user[ $p['id'] ] ?? array();
			$by_day = array();
			$run    = null;
			$flags  = 0;
			foreach ( $list as $e ) {
				if ( empty( $e['end_at'] ) && '' === $e['end_review'] ) {
					$run = $e;
				}
				if ( $e['work_date'] >= $from && $e['work_date'] <= $to ) {
					$by_day[ $e['work_date'] ][] = $e;
				}
				$f      = array_diff( ZHR_Time::flags( $e, $list, $s, $now ), array( 'edited', 'off_day' ) );
				$flags += $f ? 1 : 0;
			}
			$rows = array();
			foreach ( ZHR_Time::dates( $from, $to ) as $d ) {
				$rows[] = ZHR_Time::day_totals( $s, $d, $by_day[ $d ] ?? array(), $now, array( 'waiver1' => $w['first_periods'], 'waiver2' => $w['second_periods'], 'answers' => $answers[ $p['id'] ][ $d ] ?? array() ) );
			}
			$totals = ZHR_Time::range_totals( $rows, self::today() );
			$ot     = self::overtime_for( $p['id'], $from, $to, $s );
			if ( $ot ) {
				$totals['overtime'] = $ot['overtime'];
				$totals['regular']  = $ot['regular'];
			}
			$out[] = array(
				'id'       => $p['id'],
				'name'     => $p['name'],
				'initials' => $p['initials'],
				'totals'   => $totals,
				'running'  => $run ? array( 'since' => $run['start_at'], 'source' => $run['source'], 'open' => ZHR_Clock::asks_finish( $run, $now ) ) : null,
				'flags'    => $flags,
				'entries'  => count( $list ),
			);
		}
		usort( $out, function ( $a, $b ) { return $b['totals']['worked'] <=> $a['totals']['worked'] ?: strcmp( $a['name'], $b['name'] ); } );
		return rest_ensure_response( array( 'from' => $from, 'to' => $to, 'label' => $label, 'people' => $out, 'review' => self::review_list(), 'has_standard' => ZHR_Time::has_standard( $s ) ) );
	}

	/**
	 * Every shift waiting for a finish, whatever the period: those the person left with the office first, then those past
	 * 16 hours or from an earlier day that nobody has answered. With the office's hints. Admin tier only (team() is the
	 * admin's route); a person outside it is never sent this.
	 */
	public static function review_list() {
		ZHR_Collect::maybe_run();
		$people = array();
		foreach ( zhr_people() as $p ) {
			$people[ (int) $p['id'] ] = $p;
		}
		$now = time();
		$out = array();
		foreach ( ZHR_DB::open_entries( 0 ) as $e ) {
			$uid = (int) $e['user_id'];
			if ( ! isset( $people[ $uid ] ) ) {
				continue; // the shared device, and anyone who no longer holds Hours
			}
			$st = ZHR_Time::open_state( $e, $now );
			if ( 'parked' !== $st && ! ZHR_Clock::asks_finish( $e, $now ) ) {
				continue; // a clock running today is just running
			}
			$out[] = array(
				'id'       => (int) $e['id'],
				'person'   => array( 'id' => $uid, 'name' => $people[ $uid ]['name'], 'initials' => $people[ $uid ]['initials'] ),
				'date'     => $e['work_date'],
				'start'    => substr( $e['start_at'], 11, 5 ),
				'source'   => $e['source'],
				'asked'    => 'parked' === $st,
				'asked_at' => 'parked' === $st ? ZHR_Forgot::asked_at( $e ) : '',
				'hints'    => ZHR_Forgot::hints( $e, get_current_user_id() ),
			);
		}
		usort( $out, function ( $a, $b ) { return ( (int) $b['asked'] <=> (int) $a['asked'] ) ?: strcmp( $a['date'] . $a['start'], $b['date'] . $b['start'] ); } );
		return $out;
	}

	/* ------------------------------------------------------------------ the clock */

	public static function clock_in( WP_REST_Request $req ) {
		$uid   = get_current_user_id();
		$place = ZHR_Clock::place( $req->get_param( 'lat' ), $req->get_param( 'lng' ), $req->get_param( 'acc' ) );
		$r     = ZHR_Clock::start( $uid, time(), 'timer', '', $place, ZHR_Private::store_note( (string) $req->get_param( 'note' ) ) );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		delete_user_meta( $uid, 'zhr_on_meal' ); // back from a meal break: the gap is the meal
		return rest_ensure_response( array( 'started' => $r['started'], 'summary' => self::build( $uid, self::today(), self::today(), $uid, false ) ) );
	}

	public static function clock_out( WP_REST_Request $req ) {
		$uid   = get_current_user_id();
		$place = ZHR_Clock::place( $req->get_param( 'lat' ), $req->get_param( 'lng' ), $req->get_param( 'acc' ) );
		$r     = ZHR_Clock::stop( $uid, time(), $place, 'clock_out' );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		return rest_ensure_response( array( 'stopped' => null !== $r, 'summary' => self::build( $uid, self::today(), self::today(), $uid, false ) ) );
	}

	/**
	 * "Clock out as of …". POST {id, time: 'HH:MM'}: the finish of an open shift, the person's own (within the edit
	 * window) or, for the admin tier, anyone's. Another person's shift is "not found" (never whose). The clock-in is kept;
	 * the finish is a correction with who set it. Answers {ok, id, end_at, summary of today}.
	 */
	public static function finish( WP_REST_Request $req ) {
		$e = ZHR_DB::get( (int) $req->get_param( 'id' ) );
		if ( ! $e ) {
			return new WP_Error( 'not_found', __( 'That entry was not found.', 'zorderz' ), array( 'status' => 404 ) );
		}
		return self::locked( (int) $e['user_id'], function () use ( $req ) {
			$e  = ZHR_DB::get( (int) $req->get_param( 'id' ) );
			$ok = self::may_change( $e );
			if ( is_wp_error( $ok ) ) {
				return $ok;
			}
			$r = ZHR_Clock::finish_locked( (int) $e['id'], (string) $req->get_param( 'time' ), get_current_user_id() );
			if ( is_wp_error( $r ) ) {
				return $r;
			}
			$me = get_current_user_id();
			return rest_ensure_response( array( 'ok' => true, 'id' => (int) $r['id'], 'end_at' => $r['end_at'], 'summary' => (int) $r['user_id'] === $me ? self::build( $me, self::today(), self::today(), $me, false ) : null ) );
		} );
	}

	/** "I forgot: ask the office". POST {id}: the signed-in person's own open shift only. */
	public static function ask_office( WP_REST_Request $req ) {
		$e  = ZHR_DB::get( (int) $req->get_param( 'id' ) );
		$me = get_current_user_id();
		if ( ! $e || (int) $e['user_id'] !== $me ) {
			return new WP_Error( 'not_found', __( 'That entry was not found.', 'zorderz' ), array( 'status' => 404 ) );
		}
		return self::locked( $me, function () use ( $e, $me ) {
			$r = ZHR_Clock::ask_office_locked( (int) $e['id'], $me );
			if ( is_wp_error( $r ) ) {
				return $r;
			}
			return rest_ensure_response( array( 'ok' => true, 'id' => (int) $r['id'], 'summary' => self::build( $me, self::today(), self::today(), $me, false ) ) );
		} );
	}

	/**
	 * Meal break: the clock stops (a meal is off the clock: unpaid and off duty) and the card says "On meal break" until
	 * Clock in. The record is the two punches themselves, to the minute, never rounded.
	 */
	public static function meal_start( WP_REST_Request $req ) {
		$uid = get_current_user_id();
		$run = ZHR_DB::running( $uid );
		if ( ! $run ) {
			return new WP_Error( 'not_running', __( 'You are not on the clock.', 'zorderz' ), array( 'status' => 400 ) );
		}
		if ( 'doorhangers' === $run['source'] ) {
			// the walk owns this clock: finishing it stops the clock, and Clock in after the meal starts a new one
			return new WP_Error( 'walk_clock', __( 'Your clock is running with a door-hanger walk. Finish the walk for your meal break, then Clock in when you are back.', 'zorderz' ), array( 'status' => 409 ) );
		}
		$place = ZHR_Clock::place( $req->get_param( 'lat' ), $req->get_param( 'lng' ), $req->get_param( 'acc' ) );
		$now   = time();
		$r     = ZHR_Clock::stop( $uid, $now, $place, 'meal_break' );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		if ( ! $r || ! empty( $r['deleted_at'] ) ) {
			// in and out within a minute is a mis-tap, not a meal: nothing to come back from
			return rest_ensure_response( array( 'on_meal' => false, 'summary' => self::build( $uid, self::today(), self::today(), $uid, false ) ) );
		}
		update_user_meta( $uid, 'zhr_on_meal', $now );
		return rest_ensure_response( array( 'on_meal' => true, 'summary' => self::build( $uid, self::today(), self::today(), $uid, false ) ) );
	}

	/**
	 * The answer about a day's meal, when the business has meal rules. answer:
	 *   voluntary     the person chose to skip, delay or shorten a meal they were given -- the person only, in their window
	 *   not_provided  they could not take it (working, not relieved of duty) -- the person, or the office
	 *   forgot        they took the meal but did not clock out: the stretch is split around it (start = meal_start,
	 *                 end = meal_end, at least the rule's length), as an edit kept in the history -- the person or the office
	 */
	public static function meal_answer( WP_REST_Request $req ) {
		$uid = self::target( $req, 'change' );
		if ( is_wp_error( $uid ) ) {
			return $uid;
		}
		$s = zhr_get_settings();
		if ( ! ZHR_Rules::has_meals( $s ) ) {
			return new WP_Error( 'no_rules', __( 'This business has no meal-break rules, so there is nothing to answer.', 'zorderz' ), array( 'status' => 409 ) );
		}
		$me     = get_current_user_id();
		$admin  = zhr_user_is_admin( $me );
		$date   = (string) $req->get_param( 'date' );
		$no     = (int) $req->get_param( 'no' );
		$answer = (string) $req->get_param( 'answer' );
		if ( ! ZHR_Time::is_date( $date ) || $no < 1 || $no > count( $s['meals']['periods'] ) || ! in_array( $answer, array( 'voluntary', 'not_provided', 'forgot' ), true ) ) {
			return new WP_Error( 'bad', __( 'That answer could not be read.', 'zorderz' ), array( 'status' => 400 ) );
		}
		if ( ! $admin && $date < ZHR_Time::edit_from( self::today(), $s ) ) {
			return new WP_Error( 'too_old', ZHR_Time::too_old_words(), array( 'status' => 403 ) );
		}
		if ( 'voluntary' === $answer && $uid !== $me ) {
			return new WP_Error( 'forbidden', __( 'Only the person who worked the day can say they chose to skip or shorten a meal.', 'zorderz' ), array( 'status' => 403 ) );
		}
		return self::locked( $uid, function () use ( $req, $uid, $date, $no, $answer, $me, $s ) {
			// the answer must be about a meal the day really misses, as the day stands now
			$m = self::meal_as_it_stands( $uid, $date, $no );
			if ( is_wp_error( $m ) ) {
				return $m;
			}
			if ( 'forgot' === $answer ) {
				return self::split_for_meal( $uid, $date, (string) $req->get_param( 'meal_start' ), (string) $req->get_param( 'meal_end' ), $me, (int) $m['length'] );
			}
			if ( ! ZHR_DB::set_meal_answer( $uid, $date, $no, $answer, $me, $m['about'] ) ) {
				return new WP_Error( 'db', __( 'The answer could not be saved. Try again.', 'zorderz' ), array( 'status' => 500 ) );
			}
			return rest_ensure_response( array( 'ok' => true ) );
		} );
	}

	/** The day's meal $no as the day stands now, if it is one that can be answered; else why not. */
	private static function meal_as_it_stands( $uid, $date, $no ) {
		$today = self::today();
		if ( $date > $today ) {
			return new WP_Error( 'future', __( 'That day has not happened yet.', 'zorderz' ), array( 'status' => 400 ) );
		}
		$list    = array();
		$running = false;
		foreach ( ZHR_DB::entries( $uid, $date, $date ) as $e ) {
			if ( $e['work_date'] === $date ) {
				$list[]  = $e;
				$running = $running || empty( $e['end_at'] );
			}
		}
		if ( $running || ( $date === $today && self::on_meal( $uid ) ) ) {
			return new WP_Error( 'open_day', __( 'That day is still on the clock. Answer once it is over.', 'zorderz' ), array( 'status' => 409 ) );
		}
		$w   = self::waivers_of( $uid );
		$tot = ZHR_Time::day_totals( zhr_get_settings(), $date, $list, time(), array( 'waiver1' => $w['first_periods'], 'waiver2' => $w['second_periods'] ) );
		$m   = $tot['meals']['meals'][ $no - 1 ] ?? null;
		if ( ! $m || empty( $m['violation'] ) ) {
			return new WP_Error( 'no_question', __( 'That day\'s meal needs no answer: it was taken on time, waived, or not required.', 'zorderz' ), array( 'status' => 409 ) );
		}
		return $m;
	}

	/**
	 * "I took my meal but forgot to clock out": cut the stretch that holds the meal into two, keeping the history. The
	 * meal is at least the rule's length and at most 2 hours; a night shift's meal after midnight is found too.
	 */
	private static function split_for_meal( $uid, $date, $a, $b, $actor, $min_len ) {
		global $wpdb;
		$min_len = max( 1, (int) $min_len );
		$am      = ZHR_Time::hm_to_min( $a );
		$bm      = ZHR_Time::hm_to_min( $b );
		/* translators: %d: minutes */
		$range = sprintf( __( 'A meal break here is %d minutes to 2 hours. Check the start and end.', 'zorderz' ), $min_len );
		if ( null === $am || null === $bm ) {
			return new WP_Error( 'bad', $range, array( 'status' => 400 ) );
		}
		if ( $bm < $am ) {
			$bm += 1440; // 23:45 to 00:15
		}
		if ( $bm - $am < $min_len || $bm - $am > ZHR_Time::MEAL_SPLIT_MAX ) {
			return new WP_Error( 'bad', $range, array( 'status' => 400 ) );
		}
		$hold = null;
		$ms   = '';
		$me   = '';
		// the meal on the work day itself, or (a night shift) on the next calendar day
		foreach ( array( 0, 1 ) as $shift ) {
			$base = ZHR_Time::add_days( $date, $shift );
			$ms   = $base . ' ' . ZHR_Time::min_to_hm( $am ) . ':00';
			$me   = ( $bm >= 1440 ? ZHR_Time::add_days( $base, 1 ) : $base ) . ' ' . ZHR_Time::min_to_hm( $bm % 1440 ) . ':00';
			foreach ( ZHR_DB::entries( $uid, $date, $date ) as $e ) {
				if ( empty( $e['end_at'] ) || $e['work_date'] !== $date ) {
					continue;
				}
				if ( $e['start_at'] < $ms && $e['end_at'] > $me ) {
					$hold = $e;
					break 2;
				}
			}
		}
		if ( ! $hold ) {
			return new WP_Error( 'no_stretch', __( 'That meal break is not inside one stretch of logged time that day.', 'zorderz' ), array( 'status' => 400 ) );
		}
		$wpdb->query( 'START TRANSACTION' );
		$ok = ZHR_DB::update( $hold['id'], array( 'end_at' => $ms, 'minutes' => ZHR_Time::minutes( $hold['start_at'], $ms ) ), $actor, 'meal_recorded' );
		if ( ! $ok ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'changed', __( 'That entry was changed somewhere else a moment ago. Reload and try again.', 'zorderz' ), array( 'status' => 409 ) );
		}
		$id = ZHR_DB::insert( array(
			'user_id'    => $uid,
			'work_date'  => $date,
			'start_at'   => $me,
			'end_at'     => $hold['end_at'],
			'minutes'    => ZHR_Time::minutes( $me, $hold['end_at'] ),
			'source'     => $hold['source'],
			'source_ref' => $hold['source_ref'],
			'note'       => $hold['note'],
			'created_by' => $actor,
			'end_lat'    => $hold['end_lat'],
			'end_lng'    => $hold['end_lng'],
			'end_acc'    => $hold['end_acc'],
			'raw_input'  => 'split for a meal break recorded after the fact, ' . substr( $ms, 11, 5 ) . '-' . substr( $me, 11, 5 ),
		) );
		if ( ! $id ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'db', __( 'The meal break could not be saved. Nothing was changed.', 'zorderz' ), array( 'status' => 500 ) );
		}
		$wpdb->query( 'COMMIT' );
		return rest_ensure_response( array( 'ok' => true ) );
	}

	/** Admin tier: the signed meal-period waivers on file for a person (the date signed, or none). */
	public static function waivers( WP_REST_Request $req ) {
		$uid = (int) $req->get_param( 'user' );
		$d   = zhr_access( get_current_user_id(), $uid, 'change' );
		if ( ! $d['allow'] ) {
			return new WP_Error( 'no_person', __( 'That person was not found.', 'zorderz' ), array( 'status' => 404 ) );
		}
		$today = self::today();
		foreach ( array( 'first' => 1, 'second' => 2 ) as $k => $n ) {
			if ( ! $req->has_param( $k ) ) {
				continue;
			}
			$v = (string) $req->get_param( $k );
			if ( '' !== $v && ( ! ZHR_Time::is_date( $v ) || $v > $today ) ) {
				return new WP_Error( 'bad_date', __( 'Give the date the waiver was signed (today or earlier).', 'zorderz' ), array( 'status' => 400 ) );
			}
			$p    = self::waiver_periods( $uid, $n );
			$last = count( $p ) - 1;
			$open = $last >= 0 && null === $p[ $last ][1];
			if ( '' === $v ) {
				if ( $open ) {
					$p[ $last ][1] = $today; // revoked from today; the days it was on file stay covered
				}
			} elseif ( ! $open || $p[ $last ][0] !== $v ) {
				if ( $open ) {
					// a corrected signing date replaces the open one; a new one after a revocation starts a new period
					$p[ $last ][0] = $v;
				} else {
					foreach ( $p as $row ) {
						if ( $v < (string) $row[1] ) {
							return new WP_Error( 'bad_date', __( 'That date falls inside a waiver already on file, before it was revoked.', 'zorderz' ), array( 'status' => 400 ) );
						}
					}
					$p[] = array( $v, null );
				}
			}
			update_user_meta( $uid, 'zhr_meal_waiver' . $n, wp_json_encode( $p ) );
		}
		return rest_ensure_response( array( 'waivers' => self::waivers_of( $uid ) ) );
	}

	/** While the clock runs: the phone was here, using this app (only when the setting is on; at most every 4 minutes). */
	public static function ping( WP_REST_Request $req ) {
		$uid = get_current_user_id();
		$s   = zhr_get_settings();
		if ( empty( $s['note_location'] ) || ! ZHR_DB::running( $uid ) ) {
			return rest_ensure_response( array( 'noted' => false ) );
		}
		$k = 'zhr_ping_' . $uid;
		if ( get_transient( $k ) ) {
			return rest_ensure_response( array( 'noted' => false ) );
		}
		$place = ZHR_Clock::place( $req->get_param( 'lat' ), $req->get_param( 'lng' ), $req->get_param( 'acc' ) );
		if ( null === $place[0] ) {
			return rest_ensure_response( array( 'noted' => false ) );
		}
		set_transient( $k, 1, 4 * MINUTE_IN_SECONDS );
		$app = preg_match( '/^[a-z0-9_\-]{1,64}$/', (string) $req->get_param( 'app' ) ) ? (string) $req->get_param( 'app' ) : '';
		$now = time();
		ZHR_DB::add_activity( array( array( 'user_id' => $uid, 'at' => ZHR_Time::local( $now ), 'kind' => 'ping', 'app_id' => $app, 'lat' => $place[0], 'lng' => $place[1], 'acc' => $place[2], 'detail' => 'phone here', 'ref' => 'hours:ping:' . $uid . ':' . $now ) ) );
		return rest_ensure_response( array( 'noted' => true ) );
	}

	/* ------------------------------------------------------------------ adding and changing */

	/** check_entry with this person's other entries around it (and any earlier ones in the same batch). */
	private static function check( $uid, array $in, $except_id = 0, array $batch = array(), $allow_open = false ) {
		$s     = zhr_get_settings();
		$admin = zhr_user_is_admin( get_current_user_id() );
		$ctx   = array( 'now_ts' => time(), 'is_admin' => $admin, 'edit_from' => ZHR_Time::edit_from( self::today(), $s ), 'others' => array(), 'allow_open' => $allow_open );
		$first = ZHR_Time::check_entry( $in, $ctx );
		if ( ! $first['entry'] ) {
			return $first;
		}
		$e             = $first['entry'];
		$end           = $e['end_at'] ? $e['end_at'] : ZHR_Time::local( time() );
		$ctx['others'] = array_merge( ZHR_DB::touching( $uid, $e['start_at'], $end, $except_id ), $batch );
		return ZHR_Time::check_entry( $in, $ctx );
	}

	private static function problems_text( array $problems ) {
		return implode( ' ', array_map( function ( $p ) { return $p[1]; }, $problems ) );
	}

	/** Run $fn holding this person's lock, so a check and its write are never split by another write. */
	private static function locked( $uid, $fn ) {
		if ( ! ZHR_DB::lock( $uid ) ) {
			return new WP_Error( 'busy', __( 'Hours is busy with these hours. Try again in a moment.', 'zorderz' ), array( 'status' => 409 ) );
		}
		try {
			return $fn();
		} finally {
			ZHR_DB::unlock( $uid );
		}
	}

	/** Exact times, typed by a person for themselves (or by the admin tier for the person they opened). */
	public static function create( WP_REST_Request $req ) {
		$uid = self::target( $req, 'change' );
		if ( is_wp_error( $uid ) ) {
			return $uid;
		}
		return self::locked( $uid, function () use ( $req, $uid ) {
			$me = get_current_user_id();
			$in = array( 'date' => $req->get_param( 'date' ), 'start' => $req->get_param( 'start' ), 'end' => $req->get_param( 'end' ), 'note' => $req->get_param( 'note' ) );
			$c  = self::check( $uid, $in );
			if ( ! $c['ok'] ) {
				return new WP_Error( 'not_added', self::problems_text( $c['problems'] ), array( 'status' => 400, 'problems' => $c['problems'] ) );
			}
			$c['entry']['note'] = ZHR_Private::store_note( (string) $in['note'] );
			$id = ZHR_DB::insert( array_merge( $c['entry'], array( 'user_id' => $uid, 'source' => $uid === $me ? 'manual' : 'admin', 'created_by' => $me ) ) );
			if ( ! $id ) {
				return new WP_Error( 'db', __( 'Hours could not save that. Try again.', 'zorderz' ), array( 'status' => 500 ) );
			}
			return rest_ensure_response( array( 'id' => $id, 'summary' => self::build( $uid, $c['entry']['work_date'], $c['entry']['work_date'], $me, false ) ) );
		} );
	}

	/**
	 * Add what a reading proposed and a person kept, for ONE person (`user`). Every stretch must come from the reading
	 * (its `ref` in the server's own note of it, `token`), is checked again here, and is added only when the reading
	 * attributed it to exactly this person, or it was a review item and the adder chose this person (`person`) and may.
	 * The screen is never trusted: not for the times, not for whose they are.
	 */
	public static function batch( WP_REST_Request $req ) {
		$uid = self::target( $req, 'change' );
		if ( is_wp_error( $uid ) ) {
			return $uid;
		}
		$me   = get_current_user_id();
		$tok  = preg_replace( '/[^a-f0-9]/', '', (string) $req->get_param( 'token' ) );
		$read = '' !== $tok ? get_transient( 'zhr_read_' . $me . '_' . $tok ) : false;
		if ( ! is_array( $read ) || ! is_array( $read['items'] ?? null ) ) {
			return new WP_Error( 'reading_gone', __( 'That reading has expired. Read the words or the photo again.', 'zorderz' ), array( 'status' => 409 ) );
		}
		return self::locked( $uid, function () use ( $req, $uid, $me, $read ) {
			return self::batch_locked( $req, $uid, $me, $read );
		} );
	}

	private static function batch_locked( WP_REST_Request $req, $uid, $me, array $read ) {
		$admin  = zhr_user_is_admin( $me );
		$ids    = array_map( 'intval', wp_list_pluck( zhr_people(), 'id' ) );
		$source = 'photo' === ( $read['source'] ?? '' ) ? 'photo' : 'text';
		$raw    = (string) ( $read['raw'] ?? '' );
		$list   = array_slice( (array) $req->get_param( 'entries' ), 0, 31 );
		$added  = array();
		$skip   = array();
		$batch  = array();
		foreach ( $list as $in ) {
			if ( ! is_array( $in ) ) {
				continue;
			}
			$ref  = isset( $in['ref'] ) && is_numeric( $in['ref'] ) ? (int) $in['ref'] : -1;
			$row  = array( 'date' => (string) ( $in['date'] ?? '' ), 'start' => (string) ( $in['start'] ?? '' ), 'end' => (string) ( $in['end'] ?? '' ) );
			$item = $read['items'][ $ref ] ?? null;
			if ( ! is_array( $item ) ) {
				$skip[] = $row + array( 'why' => __( 'That was not part of the reading.', 'zorderz' ) );
				continue;
			}
			// whose: the reading's own attribution, or a person a human chose for a review item -- never the screen's say
			$who = ZHR_Attribution::may_add( $item, $uid, (int) ( $in['person'] ?? 0 ), $me, $admin, $ids );
			if ( ! $who['ok'] ) {
				$skip[] = $row + array( 'why' => 'other_person' === $who['why'] ? __( 'These hours have another person\'s name on them.', 'zorderz' ) : __( 'Choose whose hours these are first.', 'zorderz' ) );
				continue;
			}
			if ( ! empty( $item['finish'] ) ) {
				// "I clocked out at …": the finish of that open shift, checked here again. It must be this person's own
				// (the request's person, never the screen's word), and the adder must be allowed to change it.
				$fe = ZHR_DB::get( (int) ( $item['entry_id'] ?? 0 ) );
				$ok = ( $fe && (int) $fe['user_id'] === (int) $uid ) ? self::may_change( $fe ) : new WP_Error( 'not_found', __( 'That shift was not found.', 'zorderz' ) );
				$r  = is_wp_error( $ok ) ? $ok : ZHR_Clock::finish_locked( (int) $fe['id'], (string) ( $in['end'] ?? '' ), $me );
				if ( is_wp_error( $r ) ) {
					$skip[] = $row + array( 'why' => $r->get_error_message() );
				} else {
					$added[] = (int) $r['id'];
				}
				continue;
			}
			$c = self::check( $uid, $row + array( 'note' => (string) ( $in['note'] ?? '' ) ), 0, $batch );
			if ( ! $c['ok'] ) {
				$skip[] = $row + array( 'why' => self::problems_text( $c['problems'] ) );
				continue;
			}
			$c['entry']['note'] = ZHR_Private::store_note( (string) ( $in['note'] ?? '' ) );
			$how = 'chosen' === $who['how'] ? '(whose hours: chosen when added) ' : '';
			$id  = ZHR_DB::insert( array_merge( $c['entry'], array(
				'user_id'    => $uid,
				'source'     => $uid === $me ? $source : 'admin',
				'created_by' => $me,
				'raw_input'  => ( 'photo' === $source ? '(read from a photo) ' : '' ) . $how . $raw,
			) ) );
			if ( $id ) {
				$added[] = $id;
				$batch[] = array( 'id' => $id, 'start_at' => $c['entry']['start_at'], 'end_at' => $c['entry']['end_at'] );
			} else {
				$skip[] = $row + array( 'why' => __( 'Hours could not save it. Try again.', 'zorderz' ) );
			}
		}
		return rest_ensure_response( array( 'added' => count( $added ), 'skipped' => $skip ) );
	}

	/**
	 * Read words or a photo into proposals, each checked and attributed (so the screen shows what can be added, for whom,
	 * and why not). Writes NOTHING but a 30-minute note of the reading for this signed-in person (its words, coded, and
	 * whose each stretch is), which the batch route reads back.
	 */
	public static function parse( WP_REST_Request $req ) {
		$uid = self::target( $req, 'change' );
		if ( is_wp_error( $uid ) ) {
			return $uid;
		}
		$text  = (string) $req->get_param( 'text' );
		$image = null;
		if ( $req->get_param( 'image' ) ) {
			$image = ZHR_Parse::image_from_data_url( (string) $req->get_param( 'image' ) );
			if ( is_wp_error( $image ) ) {
				return $image;
			}
		}
		if ( '' === trim( $text ) && ! $image ) {
			return new WP_Error( 'empty', __( 'Say or type the hours, or add a photo of them.', 'zorderz' ), array( 'status' => 400 ) );
		}
		$settings = zhr_get_settings();
		$read     = ZHR_Parse::read( ZHR_Private::defuse( $text ), $image, $settings, self::today() );
		if ( is_wp_error( $read ) ) {
			return $read;
		}
		$me     = get_current_user_id();
		$admin  = zhr_user_is_admin( $me );
		$people = zhr_people();
		$ids    = array_map( 'intval', wp_list_pluck( $people, 'id' ) );
		$names  = array();
		foreach ( $people as $p ) {
			$names[ (int) $p['id'] ] = (string) $p['name'];
		}
		$out   = array();
		$items = array();
		$batch = array();
		$extra = array();
		foreach ( $read['entries'] as $p ) {
			$who = ZHR_Attribution::attribute( $p, $read['kind'], $uid, $people );
			if ( ! empty( $p['finish'] ) ) {
				// "I clocked out at 4:30" is the finish of that day's open shift, or nothing at all; only ever the
				// reading's own person's shift
				if ( (int) $who['person'] !== (int) $uid ) {
					$extra[] = __( 'A finish time was read for someone else, so it was left out.', 'zorderz' );
					continue;
				}
				$f = self::finish_proposal( $uid, $p );
				if ( is_string( $f ) ) {
					$extra[] = $f;
					continue;
				}
				$items[ count( $out ) ] = $who + array( 'finish' => true, 'entry_id' => $f['entry_id'] );
				$out[]                  = $f + array( 'ref' => count( $out ), 'review' => '', 'review_words' => '', 'person' => array( 'id' => (int) $uid, 'name' => $names[ (int) $uid ] ?? self::name_of( $uid ) ), 'choices' => array() );
				continue;
			}
			// checked against the person it belongs to, or (a review item) the person the reading is for
			$for = $who['person'] > 0 ? (int) $who['person'] : (int) $uid;
			$mine_or_office = $admin || $for === (int) $me;
			if ( ! empty( $p['needs_start'] ) ) {
				$c = array( 'ok' => false, 'entry' => null, 'problems' => array( array( 'start', sprintf(
					/* translators: %s: a length such as "8 h" */
					__( 'Only a length was read (%s). Add it with an exact start and end.', 'zorderz' ),
					ZHR_Time::fmt( (int) ( $p['minutes_given'] ?? 0 ) )
				) ) ) );
			} elseif ( $mine_or_office ) {
				$c = self::check( $for, $p, 0, $batch );
			} else {
				$c = array( 'ok' => false, 'entry' => null, 'problems' => array() ); // someone else's: not checked against their hours here
			}
			$ref          = count( $out );
			$items[ $ref ] = $who;
			$choices      = array();
			foreach ( ZHR_Attribution::choices( $who, $me, $admin, $ids ) as $cid ) {
				$choices[] = array( 'id' => $cid, 'name' => $names[ $cid ] ?? '' );
			}
			$show_person = $who['person'] > 0 && ( $admin || (int) $who['person'] === (int) $me );
			$out[]       = array(
				'ref'          => $ref,
				'date'         => $p['date'],
				'start'        => $p['start'],
				'end'          => $p['end'],
				'note'         => (string) $p['note'],   // the writer's own words, shown back to them; saved again from plain words
				'assumed'      => ! empty( $p['assumed'] ),
				'needs_start'  => ! empty( $p['needs_start'] ),
				'minutes'      => $c['entry'] ? (int) $c['entry']['minutes'] : 0,
				'next_day'     => $c['entry'] && $c['entry']['end_at'] && substr( $c['entry']['end_at'], 0, 10 ) !== $p['date'],
				'ok'           => (bool) $c['ok'],
				'problems'     => array_map( function ( $x ) { return $x[1]; }, $c['problems'] ),
				'review'       => $who['review'],
				'review_words' => ZHR_Attribution::review_words( $who, (string) $p['name'], $admin, $show_person ? ( $names[ (int) $who['person'] ] ?? '' ) : '' ),
				'person'       => $show_person ? array( 'id' => (int) $who['person'], 'name' => $names[ (int) $who['person'] ] ?? self::name_of( $who['person'] ) ) : null,
				'choices'      => $choices,
			);
			if ( $c['ok'] && $for === (int) $uid ) {
				$batch[] = array( 'id' => 0, 'start_at' => $c['entry']['start_at'], 'end_at' => $c['entry']['end_at'] );
			}
		}
		$tok = bin2hex( random_bytes( 8 ) );
		set_transient( 'zhr_read_' . $me . '_' . $tok, array(
			'source' => $image ? 'photo' : 'text',
			'raw'    => ZHR_Time::cut( ZHR_Private::encode( trim( ZHR_Private::defuse( $text ) ) ), 4000 ),
			'target' => (int) $uid,
			'items'  => $items,
		), 30 * MINUTE_IN_SECONDS );
		return rest_ensure_response( array( 'proposals' => $out, 'unclear' => array_merge( $read['unclear'], $extra ), 'source' => $image ? 'photo' : 'text', 'token' => $tok ) );
	}

	/**
	 * A reading's finish ("I clocked out at 4:30") as a proposal for $uid's open shift on its day: kind 'finish',
	 * entry_id, the shift's own start, checked as /finish would check it. A string (for "Not sure about") when no shift
	 * was open that day.
	 */
	private static function finish_proposal( $uid, array $p ) {
		$hit  = null;
		$prev = ZHR_Time::add_days( $p['date'], -1 );
		foreach ( ZHR_DB::open_entries( $uid ) as $e ) {
			if ( $e['work_date'] === $p['date'] ) {
				$hit = $e;
			} elseif ( ! $hit && $e['work_date'] === $prev ) {
				// "I clocked out at 6 am" after a night shift: the finish is dated the morning after the clock-in
				$f0 = ZHR_Time::finish_at( $e['start_at'], $p['end'], time() );
				if ( $f0['ok'] && substr( $f0['end_at'], 0, 10 ) === $p['date'] ) {
					$hit = $e;
				}
			}
		}
		$day = ( new DateTimeImmutable( $p['date'] . ' 12:00:00', ZHR_Time::tz() ) )->format( 'D M j' );
		$at  = ZHR_Time::time_words( (int) ZHR_Time::ts( $p['date'] . ' ' . $p['end'] . ':00' ) );
		if ( ! $hit ) {
			/* translators: 1: a time, 2: a day */
			return sprintf( __( 'The words say finished at %1$s on %2$s, but no clock was open that day. Give the start and the end to add it.', 'zorderz' ), $at, $day );
		}
		$f    = ZHR_Time::finish_at( $hit['start_at'], $p['end'], time() );
		$prob = $f['ok'] ? array() : array( $f['sentence'] );
		if ( $f['ok'] && ! zhr_user_is_admin( get_current_user_id() ) && $hit['work_date'] < ZHR_Time::edit_from( self::today(), zhr_get_settings() ) ) {
			$prob[] = ZHR_Time::too_old_words();
		}
		if ( $f['ok'] ) {
			foreach ( ZHR_DB::touching( $uid, $hit['start_at'], $f['end_at'], (int) $hit['id'] ) as $o ) {
				/* translators: %s: a stretch of time already logged */
				$prob[] = sprintf( __( 'That overlaps %s, already logged.', 'zorderz' ), ZHR_Time::label_span( $o['start_at'], $o['end_at'] ) );
				break;
			}
		}
		return array(
			'kind'        => 'finish',
			'entry_id'    => (int) $hit['id'],
			'date'        => $p['date'],
			'start'       => substr( $hit['start_at'], 11, 5 ),
			'end'         => $p['end'],
			'note'        => '',
			'assumed'     => false,
			'needs_start' => false,
			'minutes'     => $f['ok'] ? (int) $f['minutes'] : 0,
			'next_day'    => $f['ok'] && substr( $f['end_at'], 0, 10 ) !== substr( $hit['start_at'], 0, 10 ),
			'ok'          => ! $prob,
			'problems'    => $prob,
		);
	}

	/** May the signed-in person change this entry? Its owner within the edit window, or the admin tier. */
	private static function may_change( $e ) {
		$me = get_current_user_id();
		if ( ! $e || $e['deleted_at'] ) {
			return new WP_Error( 'not_found', __( 'That entry was not found.', 'zorderz' ), array( 'status' => 404 ) );
		}
		$d = zhr_access( $me, (int) $e['user_id'], 'change' );
		if ( ! $d['allow'] ) {
			return new WP_Error( 'not_found', __( 'That entry was not found.', 'zorderz' ), array( 'status' => 404 ) ); // never say whose it is
		}
		if ( zhr_user_is_admin( $me ) ) {
			return true;
		}
		if ( $e['work_date'] < ZHR_Time::edit_from( self::today(), zhr_get_settings() ) ) {
			return new WP_Error( 'too_old', ZHR_Time::too_old_words(), array( 'status' => 403 ) );
		}
		return true;
	}

	public static function update( WP_REST_Request $req ) {
		$e = ZHR_DB::get( (int) $req['id'] );
		if ( ! $e ) {
			return new WP_Error( 'not_found', __( 'That entry was not found.', 'zorderz' ), array( 'status' => 404 ) );
		}
		return self::locked( (int) $e['user_id'], function () use ( $req ) {
			return self::update_locked( $req );
		} );
	}

	private static function update_locked( WP_REST_Request $req ) {
		$e  = ZHR_DB::get( (int) $req['id'] );
		$ok = self::may_change( $e );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		$running = empty( $e['end_at'] );
		$in      = array(
			'date'  => $req->has_param( 'date' ) ? $req->get_param( 'date' ) : $e['work_date'],
			'start' => $req->has_param( 'start' ) ? $req->get_param( 'start' ) : substr( $e['start_at'], 11, 5 ),
			'end'   => $req->has_param( 'end' ) ? $req->get_param( 'end' ) : ( $running ? '' : substr( $e['end_at'], 11, 5 ) ),
			'note'  => '', // the note is handled on its own below: the stored one is coded and is never re-cleaned
		);
		$c = self::check( (int) $e['user_id'], $in, (int) $e['id'], array(), $running );
		if ( ! $c['ok'] ) {
			return new WP_Error( 'not_changed', self::problems_text( $c['problems'] ), array( 'status' => 400, 'problems' => $c['problems'] ) );
		}
		$n = $c['entry'];
		if ( $running && null !== $n['end_at'] ) {
			// giving a running clock an end is a clock-out at that time
			$n['minutes'] = ZHR_Time::minutes( $n['start_at'], $n['end_at'] );
		}
		$note = $e['note'];
		if ( $req->has_param( 'note' ) && (string) $req->get_param( 'note' ) !== ZHR_Private::decode( $e['note'], true ) ) {
			$note = ZHR_Private::store_note( (string) $req->get_param( 'note' ) );
		}
		$fields = array( 'work_date' => $n['work_date'], 'start_at' => $n['start_at'], 'end_at' => $n['end_at'], 'minutes' => $n['end_at'] ? $n['minutes'] : 0, 'note' => $note );
		$why    = 'edit';
		if ( $running && null !== $n['end_at'] ) {
			// a finish given to an open shift here is the same correction as "Clock out as of …"
			$why                  = (int) get_current_user_id() === (int) $e['user_id'] ? 'set_end' : 'office_set_end';
			$fields['end_review'] = '';
		}
		$done = ZHR_DB::update( (int) $e['id'], $fields, get_current_user_id(), $why );
		if ( ! $done ) {
			return new WP_Error( 'changed', __( 'That entry was changed somewhere else a moment ago. Reload and try again.', 'zorderz' ), array( 'status' => 409 ) );
		}
		return rest_ensure_response( array( 'id' => (int) $e['id'] ) );
	}

	public static function delete( WP_REST_Request $req ) {
		$e = ZHR_DB::get( (int) $req['id'] );
		if ( ! $e ) {
			return new WP_Error( 'not_found', __( 'That entry was not found.', 'zorderz' ), array( 'status' => 404 ) );
		}
		return self::locked( (int) $e['user_id'], function () use ( $req ) {
			$e  = ZHR_DB::get( (int) $req['id'] );
			$ok = self::may_change( $e );
			if ( is_wp_error( $ok ) ) {
				return $ok;
			}
			if ( ! ZHR_DB::soft_delete( (int) $e['id'], get_current_user_id() ) ) {
				return new WP_Error( 'changed', __( 'That entry was changed somewhere else a moment ago. Reload and try again.', 'zorderz' ), array( 'status' => 409 ) );
			}
			return rest_ensure_response( array( 'deleted' => (int) $e['id'] ) );
		} );
	}

	/* ------------------------------------------------------------------ settings (admin tier) */

	public static function get_settings() {
		$s = zhr_get_settings();
		return rest_ensure_response( array(
			'settings'     => $s,
			'reader'       => ZHR_Parse::ready_label(),
			'ready'        => ZHR_Parse::ready(),
			'meal_words'   => ZHR_Rules::meal_words( $s['meals'] ),
			'stats'        => ZHR_DB::stats(),
			'last_collect' => get_option( 'zhr_last_collect', null ),
		) );
	}

	public static function save_settings( WP_REST_Request $req ) {
		$in = $req->get_param( 'settings' );
		if ( ! is_array( $in ) ) {
			$j  = $req->get_json_params();
			$in = is_array( $j ) ? $j : array();
		}
		$new = ZHR_Time::clean_settings( $in, zhr_get_settings() );
		update_option( 'zhr_settings', $new, false );
		return rest_ensure_response( array( 'settings' => $new, 'meal_words' => ZHR_Rules::meal_words( $new['meals'] ) ) );
	}
}
