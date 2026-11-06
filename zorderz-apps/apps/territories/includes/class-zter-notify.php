<?php
/**
 * ZTER_Notify — the "assigned to you" notice, sent only once a LIVE assignment has really been written.
 *
 * Territories never writes the estimate. The estimate app writes its own salesperson, then tells this app
 * which decision it used:
 *
 *     do_action( 'zest_operator_routed', $decision_id, $estimate_number, $initials_written );
 *
 * On that, and only for a live decision whose initials are the ones written, this app marks the decision
 * applied and notifies the person: an alert of type `territories_assigned` through ZDZ_Alert_Router (the bell
 * and push), and a direct message when the Messaging app is here. Never the shared device; never someone
 * notifying themselves. A live answer may name two people ("AB & CD"): each is notified, and each takes the
 * live turn under the rule that picked them (the decision keeps both rules: "r1+territory").
 *
 * Won jobs: the Jobs app asks territories.installer_for, shows the suggestion to the salesperson (Yes /
 * Another), and when they confirm someone it says which decision it was, who, and which part of a job split
 * between two installers (0 or 1):
 *
 *     do_action( 'zjob_installer_confirmed', $decision_id, $user_id, $job_number, $part );
 *
 * $job_number is the job's number (an int, or a short id like "J1042"); anything else is kept as '' (a title
 * can carry a customer's name). The decision is marked with who was confirmed, and, when an install rule was
 * live, that person takes its live turn (Yes or Another: whoever got the work). The Jobs app tells the
 * installer itself; this app sends none.
 *
 * Re-assigning existing stubs: the estimate app says what it wrote from a preview_route batch
 * (`zest_reassign_applied`) or undid (`zest_reassign_undone`). A referral case is closed by the app that
 * works the referral list (`zdz_referral_closed`).
 *
 * @package Zorderz\Territories
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZTER_Notify {

	const TYPE = 'territories_assigned';

	public static function init() {
		add_action( 'zdz_register_alert_types', array( __CLASS__, 'register_type' ) );
		add_action( 'zest_operator_routed', array( __CLASS__, 'on_routed' ), 10, 3 );
		add_action( 'zest_reassign_applied', array( __CLASS__, 'on_reassigned' ), 10, 3 );
		add_action( 'zest_reassign_undone', array( __CLASS__, 'on_reassign_undone' ), 10, 3 );
		add_action( 'zjob_installer_confirmed', array( __CLASS__, 'on_installer_confirmed' ), 10, 4 );
		add_action( 'zdz_referral_closed', array( __CLASS__, 'on_referral_closed' ), 10, 3 );
	}

	/**
	 * The referral list closed a case: the customer's email was opened from its button by a person
	 * ('emailed'), or an admin closed it ('closed'). Only the admin tier closes a case by hand; the estimate's own
	 * salesperson may close it by opening the email. Never the shared device. Returns what was done, for the tests.
	 */
	public static function on_referral_closed( $case_id = 0, $by = 0, $how = 'closed' ) {
		$by   = (int) $by;
		$how  = 'emailed' === $how ? 'emailed' : 'closed';
		$case = ZTER_DB::referral_case( (int) $case_id );
		if ( ! $case ) {
			return 'unknown';
		}
		if ( '' !== $case['closed_at'] ) {
			return 'already';
		}
		$admin = zter_is_admin( $by );
		// the same people territories.uncovered shows the case to, so the referral list and this app agree
		if ( $by <= 0 || zter_is_kiosk( $by ) || ! ( $admin || ( 'emailed' === $how && ZTER_Verbs::may_see_referral( $by, $case ) ) ) ) {
			return 'not_allowed';
		}
		ZTER_DB::close_referral( $case, $by, $how );
		ZTER_DB::log( $by, 0, 'referral', array( 'case' => (int) $case['id'], 'zip' => $case['zip'], 'how' => $how ) );
		return 'closed';
	}

	/**
	 * The salesperson confirmed an installer for a won job. Returns what was done, for the tests.
	 * $part - which installer of a two-installer job (0: the deciding rule's items, 1: the rest). Each part is
	 * confirmed once (confirmed_people "uid0,uid1"); each takes the live turn of the rule that picked that part. A
	 * one-installer decision has only part 0.
	 */
	public static function on_installer_confirmed( $decision_id = 0, $user_id = 0, $doc = '', $part = 0 ) {
		// two parts confirmed at the same moment must not overwrite each other's confirmed_people
		$lock = 'zter_confirm_' . (int) $decision_id;
		$got  = ZTER_DB::lock( $lock );
		try {
			return self::confirm_part( $decision_id, $user_id, $doc, $part );
		} finally {
			if ( $got ) {
				ZTER_DB::unlock( $lock );
			}
		}
	}

	private static function confirm_part( $decision_id, $user_id, $doc, $part ) {
		$d    = ZTER_DB::decision( (int) $decision_id );
		$uid  = (int) $user_id;
		$part = (int) $part;
		if ( ! $d || 'install' !== $d['stage'] ) {
			return 'unknown';
		}
		$parts = max( 1, count( (array) $d['live_people'] ) );
		if ( $part < 0 || $part >= $parts ) {
			return 'unknown_part';
		}
		$done = array_map( 'intval', (array) $d['confirmed_people'] );
		if ( ! $done && ! empty( $d['applied_at'] ) ) {
			$done = array( (int) $d['confirmed_user_id'] ); // a one-installer confirmation: part 0
		}
		$done = array_pad( $done, $parts, 0 );
		if ( $done[ $part ] > 0 ) {
			return 'already';
		}
		if ( ! zter_assignable( $uid ) ) {
			return 'not_assignable';
		}
		// the job NUMBER only: never a title, which can carry a customer's name
		$doc = is_int( $doc ) || ( is_string( $doc ) && preg_match( '/^[A-Z#-]{0,4}\d{1,10}$/i', trim( $doc ) ) ) ? trim( (string) $doc ) : '';
		$done[ $part ] = $uid;
		$fields = array( 'confirmed_people' => substr( implode( ',', $done ), 0, 64 ) );
		if ( empty( $d['applied_at'] ) ) {
			$fields += array( 'applied_at' => ZTER_DB::now(), 'applied_doc' => $doc, 'confirmed_user_id' => $uid );
		}
		ZTER_DB::update_decision( $d['id'], $fields );
		$rids      = explode( '+', (string) $d['live_rule_id'] );
		$rid       = (string) ( $rids[ $part ] ?? '' );
		$suggested = (int) ( $d['live_people'][ $part ] ?? ( 0 === $part ? $d['live_user_id'] : 0 ) );
		$turn      = 'live' === $d['mode'] && '' !== $rid;
		if ( $turn ) {
			ZTER_DB::take_turn( $d['is_test'] ? 'test' : 'live', ZTER_Rules::turn_key( 'install', $rid ), $uid );
		}
		ZTER_DB::log( (int) get_current_user_id(), $uid, 'installer', array( 'decision' => $d['id'], 'part' => $part, 'suggested' => $suggested, 'would' => (int) ( $d['chosen_people'][ $part ] ?? ( 0 === $part ? $d['chosen_user_id'] : 0 ) ), 'confirmed' => $uid, 'another' => $uid !== $suggested, 'turn' => $turn ) );
		return $turn ? 'applied' : 'recorded';
	}

	/**
	 * The estimate app wrote a batch of re-assignments it previewed with territories.preview_route. Each person given
	 * work takes the live turn under the rule that picked them (a test stub: the test book), so the next new stub is
	 * not handed to the same person again. Only a rule that is live now, and only someone who can be assigned work with
	 * exactly those initials; anything else is skipped. One History row for the batch. The estimate app sends the notices
	 * (one per person per batch); this sends none.
	 *
	 * @param int   $batch the estimate app's batch number
	 * @param array $rows  [ { doc, rule_id, user_id, initials, test }, … ]
	 * @return array { turns, skipped } (for the tests)
	 */
	public static function on_reassigned( $batch = 0, $rows = array(), $by = 0 ) {
		$rules = ZTER_Rules::get( 'stub' );
		$live  = array();
		foreach ( $rules['rows'] as $r ) {
			if ( ! empty( $r['live'] ) ) {
				$live[ $r['id'] ] = true;
			}
		}
		$turns = 0;
		$skip  = 0;
		$docs  = array();
		foreach ( is_array( $rows ) ? $rows : array() as $r ) {
			$r   = is_array( $r ) ? $r : array();
			$uid = (int) ( $r['user_id'] ?? 0 );
			$rid = (string) ( $r['rule_id'] ?? '' );
			$ini = strtoupper( trim( (string) ( $r['initials'] ?? '' ) ) );
			if ( ! isset( $live[ $rid ] ) || ! zter_assignable( $uid ) || zter_initials( $uid ) !== $ini || zter_user_by_initials( $ini ) !== $uid ) {
				$skip++;
				continue;
			}
			ZTER_DB::take_turn( empty( $r['test'] ) ? 'live' : 'test', $rid, $uid );
			$turns++;
			$docs[] = substr( preg_replace( '/[^A-Za-z0-9#\-]/', '', (string) ( $r['doc'] ?? '' ) ), 0, 20 ) . '→' . $ini;
		}
		ZTER_DB::log( (int) $by, 0, 'reassign', array( 'batch' => (int) $batch, 'turns' => $turns, 'skipped' => $skip, 'docs' => array_slice( $docs, 0, 200 ) ) );
		return array( 'turns' => $turns, 'skipped' => $skip );
	}

	/** The estimate app undid (some of) a batch. Recorded in History; turns already taken stay taken. */
	public static function on_reassign_undone( $batch = 0, $docs = array(), $by = 0 ) {
		$list = array();
		foreach ( is_array( $docs ) ? $docs : array() as $d ) {
			$list[] = substr( preg_replace( '/[^A-Za-z0-9#\-]/', '', (string) $d ), 0, 20 );
		}
		ZTER_DB::log( (int) $by, 0, 'reassign', array( 'batch' => (int) $batch, 'undone' => array_slice( $list, 0, 200 ) ) );
	}

	public static function register_type( $router ) {
		if ( is_object( $router ) && method_exists( $router, 'register_type' ) ) {
			$router->register_type( self::TYPE, array(
				'label'            => __( 'Territories: a new estimate assigned to you', 'zorderz' ),
				'description'      => __( 'Territories assigned you a new estimate stub by its rules (ZIP and kind of work).', 'zorderz' ),
				'default_channels' => array( 'in_app' ),
				'source_plugin'    => 'territories',
				'category'         => 'sales',
			) );
		}
	}

	/** The estimate app wrote the initials of a decision. Returns what was done, for the tests: 'applied' or why not. */
	public static function on_routed( $decision_id = 0, $doc = '', $initials = '' ) {
		$d = ZTER_DB::decision( (int) $decision_id );
		if ( ! $d ) {
			return 'unknown';
		}
		if ( ! empty( $d['refer'] ) ) {
			// a referral case keeps the estimate number (shadow or live), so the referral list can find the
			// estimate again and ask the estimate app for the address as the person who presses; and who it was written to
			$num = substr( preg_replace( '/[^0-9]/', '', ltrim( (string) $doc, '#' ) ), 0, 20 );
			$ini = substr( self::norm( $initials ), 0, 16 );
			if ( '' !== $num && '' === (string) ( $d['estimate_number'] ?? '' ) ) {
				ZTER_DB::update_decision( $d['id'], array( 'estimate_number' => $num, 'written_initials' => preg_match( '/^[A-Z]{2,3}( & [A-Z]{2,3})?$/', $ini ) ? $ini : '' ) );
			}
		}
		if ( 'live' !== $d['mode'] || $d['live_user_id'] <= 0 ) {
			return 'not_live';
		}
		if ( ! empty( $d['applied_at'] ) ) {
			return 'already';
		}
		if ( self::norm( $initials ) !== self::norm( $d['answered_initials'] ) ) {
			error_log( sprintf( 'Zorderz Territories: decision %d - the estimate app wrote %s, not %s; no notice.', $d['id'], sanitize_text_field( (string) $initials ), $d['answered_initials'] ) );
			return 'mismatch';
		}
		$doc = substr( preg_replace( '/[^A-Za-z0-9#\-]/', '', (string) $doc ), 0, 40 );
		ZTER_DB::update_decision( $d['id'], array( 'applied_at' => ZTER_DB::now(), 'applied_doc' => $doc ) );
		$people = $d['live_people'] ? array_values( $d['live_people'] ) : array( (int) $d['live_user_id'] );
		$rids   = explode( '+', (string) $d['live_rule_id'] );
		$used   = array();
		foreach ( $people as $n => $to ) {
			if ( $to <= 0 ) {
				continue; // "keep the default" for the rest of the work: the asking app's own pick, nobody of this app's to notify
			}
			// The live turn moves only now, when the work was really given. A test stub has its own book.
			ZTER_DB::take_turn( $d['is_test'] ? 'test' : 'live', (string) ( $rids[ $n ] ?? $rids[0] ), $to );
			$used = array_merge( $used, self::tell( $d, $to, $doc ) );
		}
		ZTER_DB::update_decision( $d['id'], array( 'notice' => substr( implode( ',', array_unique( $used ) ), 0, 40 ) ) );
		return 'applied';
	}

	/** "(ab & cd)", "AB&CD" and "AB & CD" are the same writing: the estimate app may bracket a pair. */
	public static function norm( $ini ) {
		$s = strtoupper( trim( (string) $ini, " \t()" ) );
		return (string) preg_replace( array( '/[()]/', '/\s*&\s*/', '/\s+/' ), array( '', ' & ', ' ' ), $s );
	}

	/** The notice for one person given the work: the bell (and push) through the router, and a DM. */
	private static function tell( array $d, $to, $doc ) {
		if ( zter_is_kiosk( $to ) || ! zter_is_team_member( $to ) ) {
			return array();
		}
		$kinds = array();
		$abs   = ZTER_Kinds::abilities();
		foreach ( $d['kinds'] as $k ) {
			$kinds[] = ZTER_Kinds::stored_label( $k, $abs );
		}
		$title = ( $d['is_test'] ? 'TEST: ' : '' ) . 'A new estimate for you';
		$msg   = 'Territories assigned you a new estimate stub' . ( '' !== $doc ? ' (#' . ltrim( $doc, '#' ) . ')' : '' )
			. ( '' !== $d['zip'] ? ' in ' . $d['zip'] : '' ) . ( $kinds ? ': ' . implode( ', ', $kinds ) : '' ) . '.';
		$used = array();
		if ( class_exists( 'ZDZ_Alert_Router' ) && method_exists( 'ZDZ_Alert_Router', 'send' ) ) {
			try {
				$used = (array) ZDZ_Alert_Router::send( self::TYPE, $to, $title, $msg, array( 'sender_id' => (int) $d['asker_id'], 'plugin' => 'territories', 'source_id' => (string) $d['id'] ) );
			} catch ( \Throwable $e ) {
				$used = array();
			}
		}
		if ( apply_filters( 'zter_also_dm', true ) && self::dm( (int) $d['asker_id'], $to, $title . '. ' . $msg ) ) {
			$used[] = 'dm';
		}
		return $used;
	}

	/**
	 * A best-effort direct message through the Messaging app's own seam (`zim_dm_send`), which
	 * decides who may send and receive and makes the post. Never throws.
	 */
	public static function dm( $from, $to, $body ) {
		try {
			if ( ! function_exists( 'has_filter' ) || false === has_filter( 'zim_dm_send' ) ) {
				return false;
			}
			$from = (int) $from;
			$to   = (int) $to;
			if ( $from <= 0 || $to <= 0 || $from === $to || zter_is_kiosk( $from ) || zter_is_kiosk( $to ) ) {
				return false;
			}
			$res = apply_filters( 'zim_dm_send', null, $from, $to, (string) $body, array() );
			return is_array( $res ) && ! empty( $res['message_id'] );
		} catch ( \Throwable $e ) {
			return false;
		}
	}
}
