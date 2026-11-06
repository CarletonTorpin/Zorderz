<?php
/**
 * ZHR_Bridge -- what the rest of the platform may ask Hours.
 *
 *   hours.lookup   "how many hours did I work this week?" -- a READ. Tier viewer (anyone who holds Hours), never the
 *                  shared device, no side effect. The asker's own hours; the admin tier may name a person (person_id, or
 *                  person = a name that is exactly one person who holds Hours). Registered on zdz_register_capabilities;
 *                  the platform's ZDZ_Capabilities decides who may use it and names the asker from the session
 *                  (requesting_user_id), never from the payload.
 *
 *   apply_filters( 'zdz_hours_summary', null, $uid, $from, $to )   the same numbers for a caller already inside the site:
 *                  ['worked','raw','standard','standard_to_date','days_worked','meal_premiums','meal_open','over','under',
 *                  'overtime','regular'] in minutes (overtime and regular only when the business has an overtime line),
 *                  or null. The caller decides first that its viewer may see $uid's hours (zhr_access()).
 *
 * @package Zorderz\Hours
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZHR_Bridge {

	const VERB = 'hours.lookup';

	public static function init() {
		add_filter( 'zdz_register_capabilities', array( __CLASS__, 'register' ) );
		add_filter( 'zdz_hours_summary', array( __CLASS__, 'summary_filter' ), 10, 4 );
	}

	public static function descriptor() {
		return array(
			'verb'          => self::VERB,
			'provider'      => 'hours',
			'app'           => ZHR_APP_ID,
			'tier'          => 'viewer',
			'needs_app'     => true,
			'callback'      => array( __CLASS__, 'lookup' ),
			'kiosk'         => false,
			'kiosk_bounded' => false,
			'side_effect'   => false,
			'summary'       => 'Hours worked by the asker (today, this week, last week, this month, last month); for the admin tier with the standard hours, and an admin may ask about one person (person or person_id).',
		);
	}

	public static function register( $caps ) {
		if ( ! is_array( $caps ) ) {
			$caps = array();
		}
		$caps[ self::VERB ] = self::descriptor();
		return $caps;
	}

	public static function summary_filter( $null, $uid = 0, $from = '', $to = '' ) {
		$uid = (int) $uid;
		if ( $uid <= 0 || ! ZHR_Time::is_date( $from ) || ! ZHR_Time::is_date( $to ) ) {
			return null;
		}
		$s       = zhr_get_settings();
		$now     = time();
		$rows    = array();
		$by      = array();
		$answers = ZHR_DB::meal_answers( $uid, $from, $to )[ $uid ] ?? array();
		$w       = ZHR_REST::waivers_of( $uid );
		foreach ( ZHR_DB::entries( $uid, $from, $to ) as $e ) {
			if ( $e['work_date'] >= $from && $e['work_date'] <= $to ) {
				$by[ $e['work_date'] ][] = $e;
			}
		}
		foreach ( ZHR_Time::dates( $from, $to ) as $d ) {
			$rows[] = ZHR_Time::day_totals( $s, $d, $by[ $d ] ?? array(), $now, array( 'waiver1' => $w['first_periods'], 'waiver2' => $w['second_periods'], 'answers' => $answers[ $d ] ?? array() ) );
		}
		$t  = ZHR_Time::range_totals( $rows, current_time( 'Y-m-d' ) );
		$ot = ZHR_REST::overtime_for( $uid, $from, $to, $s );
		if ( $ot ) {
			$t['overtime'] = $ot['overtime'];
			$t['regular']  = $ot['regular'];
		}
		return $t;
	}

	/** The period a question names (period key, or words in `query`). */
	public static function period_key( array $payload ) {
		$p = strtolower( (string) ( $payload['period'] ?? '' ) );
		if ( in_array( $p, array( 'today', 'yesterday', 'this_week', 'last_week', 'this_month', 'last_month', 'this_year', 'this_pay_period', 'last_pay_period' ), true ) ) {
			return $p;
		}
		$q = strtolower( (string) ( $payload['query'] ?? '' ) );
		foreach ( array( 'last pay period' => 'last_pay_period', 'this pay period' => 'this_pay_period', 'last week' => 'last_week', 'last month' => 'last_month', 'this month' => 'this_month', 'yesterday' => 'yesterday', 'today' => 'today', 'this year' => 'this_year' ) as $w => $k ) {
			if ( false !== strpos( $q, $w ) ) {
				return $k;
			}
		}
		return 'this_week';
	}

	/**
	 * Who a lookup names, for the admin tier: person_id, or a name that is exactly one person (their whole name, first and
	 * last, initials, or a first name only one person has). Two people or none: ask which.
	 */
	public static function named_person( array $payload, array $people ) {
		$want = ! empty( $payload['person_id'] ) ? (int) $payload['person_id'] : 0;
		if ( $want > 0 ) {
			foreach ( $people as $p ) {
				if ( (int) $p['id'] === $want ) {
					return array( 'id' => $want, 'why' => '' );
				}
			}
			return array( 'id' => 0, 'why' => 'none' );
		}
		$name = (string) ( $payload['person'] ?? '' );
		$m    = ZHR_Attribution::match( $name, $people );
		if ( 'none' === $m['status'] ) {
			// a read, not pay: a first name only one person has is enough to answer, never to attribute hours
			$first = array();
			$n     = ZHR_Attribution::norm( $name );
			foreach ( $people as $p ) {
				$parts = explode( ' ', ZHR_Attribution::norm( (string) $p['name'] ) );
				if ( '' !== $n && false === strpos( $n, ' ' ) && $parts[0] === $n ) {
					$first[] = (int) $p['id'];
				}
			}
			$m = 1 === count( $first ) ? array( 'status' => 'match', 'id' => $first[0] ) : array( 'status' => $first ? 'ambiguous' : 'none', 'id' => 0 );
		}
		if ( 'match' === $m['status'] ) {
			return array( 'id' => (int) $m['id'], 'why' => '' );
		}
		return array( 'id' => 0, 'why' => 'ambiguous' === $m['status'] ? 'ambiguous' : 'none' );
	}

	public static function lookup( array $payload ) {
		$asker = (int) ( $payload['requesting_user_id'] ?? 0 );
		if ( $asker <= 0 || ! empty( $payload['is_kiosk'] ) || ! zhr_user_can_use( $asker ) ) {
			return array( 'success' => false, 'denied' => true, 'message' => __( 'Hours is not available on this account.', 'zorderz' ) );
		}
		// The registry always sets user_id to the asker, so another person is named by person_id or person (a name), and
		// only someone the ladder lets read that person's hours gets an answer.
		$uid   = $asker;
		$other = ( ! empty( $payload['person_id'] ) && (int) $payload['person_id'] !== $asker ) || '' !== trim( (string) ( $payload['person'] ?? '' ) );
		if ( $other ) {
			if ( ! zhr_user_is_admin( $asker ) ) {
				return array( 'success' => false, 'denied' => true, 'message' => __( 'You can only see your own hours.', 'zorderz' ) );
			}
			$hit = self::named_person( $payload, zhr_people() );
			if ( $hit['id'] <= 0 ) {
				return array( 'success' => false, 'needs_clarify' => true, 'message' => 'ambiguous' === $hit['why'] ? __( 'More than one person matches that name.', 'zorderz' ) : __( 'No one by that name uses Hours.', 'zorderz' ) );
			}
			$uid = $hit['id'];
		}
		$s                         = zhr_get_settings();
		list( $from, $to, $label ) = ZHR_Time::period( self::period_key( $payload ), current_time( 'Y-m-d' ), (int) $s['week_start'], '', '', $s['pay_period'] );
		$t                         = self::summary_filter( null, $uid, $from, $to );
		$u                         = get_userdata( $uid );
		$who                       = $uid === $asker ? __( 'You', 'zorderz' ) : ( $u ? $u->display_name : __( 'They', 'zorderz' ) );
		// the comparison with the standard hours, and overtime, are the admin tier's, here as on the Hours screen
		$full = zhr_user_is_admin( $asker );
		if ( ! $full ) {
			$t = array_diff_key( $t, array_flip( array( 'standard', 'standard_to_date', 'over', 'under', 'overtime', 'regular' ) ) );
		}
		$msg = sprintf(
			/* translators: 1: who, 2: hours worked, 3: the period's name, 4: from date, 5: to date */
			__( '%1$s worked %2$s %3$s (%4$s to %5$s)', 'zorderz' ),
			$who,
			ZHR_Time::fmt( $t['worked'] ),
			strtolower( $label ),
			$from,
			$to
		);
		if ( $full && ZHR_Time::has_standard( $s ) ) {
			/* translators: %s: hours */
			$msg .= sprintf( __( ', against %s of standard hours so far', 'zorderz' ), ZHR_Time::fmt( $t['standard_to_date'] ) );
		}
		if ( $full && isset( $t['overtime'] ) && $t['overtime'] > 0 ) {
			/* translators: %s: hours */
			$msg .= sprintf( __( ', %s of it overtime', 'zorderz' ), ZHR_Time::fmt( $t['overtime'] ) );
		}
		if ( $t['meal_open'] ) {
			/* translators: %d: number of days */
			$msg .= sprintf( _n( '; %d day has a meal-break question to answer in Hours', '; %d days have a meal-break question to answer in Hours', (int) $t['meal_open'], 'zorderz' ), (int) $t['meal_open'] );
		}
		return array(
			'success' => true,
			'denied'  => false,
			'message' => $msg . '.',
			'period'  => array( 'label' => $label, 'from' => $from, 'to' => $to ),
			'totals'  => $t,
			'source'  => 'hours',
		);
	}
}
