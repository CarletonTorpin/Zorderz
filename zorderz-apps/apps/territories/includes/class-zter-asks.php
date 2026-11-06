<?php
/**
 * ZTER_Asks — the last 20 times another app asked territories.route_for, and who may ask, for
 * Territories -> Shadow.
 *
 * WHY: an app that gets no answer from Territories may only write why to the PHP error log, so a quiet failure
 * could not be seen from the app. Two things show it:
 *
 *   Last asked   every route_for call that reaches Territories: when, which app asked (read from the code that
 *                called), the asker's initials and tier, and the outcome — a decision (its number, shadow or live,
 *                test or not), a refusal (its reason), or an error. Nothing about the customer: the verb never
 *                sees any (it gets a ZIP, the lines and a hash), and none of that is kept here either.
 *   Who may ask  each person who can make $0 stubs in the estimate app (zest_create_zero_estimates), with the
 *                registry's own answer for them (ZDZ_Capabilities::why_not). The registry refuses the shared
 *                device, a low tier or a missing app BEFORE Territories is called, so such a refusal never
 *                reaches Last asked; this line shows it anyway.
 *
 * Stored in one option (zter_asks, not autoloaded), newest first, at most 20. Two calls at the same instant may
 * lose one row: it is a window onto the verb, not a record (the decisions table is the record).
 *
 * @package Zorderz\Territories
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZTER_Asks {

	const OPTION = 'zter_asks';
	const KEEP   = 20;
	const VERB   = 'territories.route_for';

	/** Code that asks, by class-name prefix, longest first: the app's name as the people who use it say it. */
	const APPS = array(
		'ZEST_'  => 'Estimates',
		'ZANA_'  => 'Chat',
		'ZJOB_'  => 'Jobs',
		'Zjob_'  => 'Jobs',
		'ZTER_'  => 'Territories',
	);
	/** Which app's code asked: the first caller outside the registry and this verb. '' when it can't be told. */
	public static function asking_app() {
		$trace = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 16 );
		foreach ( $trace as $f ) {
			$cls = (string) ( $f['class'] ?? '' );
			if ( '' === $cls || 'ZTER_Asks' === $cls || 'ZTER_Verbs' === $cls || 'ZDZ_Capabilities' === $cls ) {
				continue;
			}
			foreach ( self::APPS as $pre => $name ) {
				if ( 0 === strpos( $cls, $pre ) ) {
					return $name;
				}
			}
			return 'another app';
		}
		return '';
	}

	/**
	 * One call, as route_for answered it.
	 *
	 * @param array $args   what the registry passed (only user_id, tier and test are read)
	 * @param array $answer route_for's answer
	 * @param string $app   the asking app ('' = read it from the call)
	 */
	public static function record( array $args, array $answer, $app = '' ) {
		$uid = (int) ( $args['user_id'] ?? 0 );
		if ( ! empty( $answer['success'] ) && ! empty( $answer['decision_id'] ) ) {
			$outcome = 'decision';
			$detail  = array( 'id' => (int) $answer['decision_id'], 'mode' => 'live' === ( $answer['mode'] ?? '' ) ? 'live' : 'shadow' );
		} elseif ( ! empty( $answer['denied'] ) ) {
			$outcome = 'refusal';
			$why     = preg_replace( '/[^a-z_]/', '', strtolower( (string) ( $answer['reason'] ?? 'refused' ) ) );
			// route_for's own check refuses the shared device as 'tier': say which it was
			$detail  = array( 'reason' => ( 'tier' === $why && ! empty( $args['is_kiosk'] ) ) ? 'kiosk' : $why );
		} else {
			$outcome = 'error';
			$detail  = array( 'message' => mb_substr( (string) ( $answer['error'] ?? 'No answer.' ), 0, 120 ) );
		}
		$row = array(
			'at'      => ZTER_DB::now(),
			'app'     => '' !== (string) $app ? (string) $app : self::asking_app(),
			'who'     => $uid > 0 ? self::initials( $uid ) : '',
			'tier'    => preg_replace( '/[^a-z]/', '', strtolower( (string) ( $args['tier'] ?? '' ) ) ),
			'test'    => ! empty( $args['test'] ),
			'outcome' => $outcome,
		) + $detail;
		$rows = self::recent();
		array_unshift( $rows, $row );
		update_option( self::OPTION, array_slice( $rows, 0, self::KEEP ), false );
	}

	/** The last calls, newest first. */
	public static function recent() {
		$r = get_option( self::OPTION, array() );
		return is_array( $r ) ? array_values( array_filter( $r, 'is_array' ) ) : array();
	}

	private static function initials( $uid ) {
		$i = zter_initials( (int) $uid );
		if ( preg_match( '/^[A-Z]{2,3}$/', $i ) ) {
			return $i;
		}
		$u = get_userdata( (int) $uid );
		return $u ? (string) preg_split( '/\s+/', trim( (string) $u->display_name ) )[0] : '';
	}

	/** The registry's reasons, in words. */
	public static function reason_words( $why ) {
		$w = array(
			''            => 'can ask',
			'kiosk'       => 'refused: the shared device (the registry never lets it ask Territories)',
			'tier'        => 'refused: the account\'s tier is below staff',
			'app'         => 'refused: no access to the Territories app',
			'signed_out'  => 'refused: not an app user',
			'unknown'     => 'refused: Territories\' verb is not registered on this site',
			'not_allowed' => 'refused: cannot make $0 stubs',
		);
		return $w[ (string) $why ] ?? ( 'refused: ' . (string) $why );
	}

	/**
	 * Each person who can make $0 stubs (the estimate app's operator mode), and whether the registry lets them ask Territories.
	 * At most 30 people. [ { who, kiosk, why, words } ]
	 */
	public static function who_may_ask() {
		$ids = get_users( array( 'capability' => 'zest_create_zero_estimates', 'fields' => 'ID', 'number' => 500 ) );
		$out = array();
		foreach ( (array) $ids as $uid ) {
			$uid = (int) $uid;
			if ( ! user_can( $uid, 'zest_create_zero_estimates' ) ) {
				continue;   // a WordPress that ignores the capability argument lists everyone: keep only these
			}
			if ( count( $out ) >= 30 ) {
				break;
			}
			$why = class_exists( 'ZDZ_Capabilities' ) && method_exists( 'ZDZ_Capabilities', 'why_not' )
				? (string) ZDZ_Capabilities::why_not( self::VERB, $uid ) : 'unknown';
			$out[] = array(
				'who'   => self::initials( $uid ),
				'kiosk' => function_exists( 'zter_is_kiosk' ) ? (bool) zter_is_kiosk( $uid ) : false,
				'why'   => $why,
				'words' => self::reason_words( $why ),
			);
		}
		return $out;
	}
}
