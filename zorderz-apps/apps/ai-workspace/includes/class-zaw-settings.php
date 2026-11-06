<?php
/**
 * ZAW_Settings -- what the business decides about its Ai Workspace (option zaw_settings).
 *
 *   turn_usd     the most one answer may cost, in USD. Core default 1.00 (the platform router's per-call default);
 *                at most the router's own per-call ceiling. The business's monthly Ai budget still bounds every
 *                answer: with no budget set, nothing is sent at all.
 *   max_grants   how many people may hold the workspace at once. Core default 1: it starts as one person's tool and
 *                an owner-level account widens it on purpose. The filter zaw_max_grants may only narrow it further.
 *   effort       how hard the model thinks: low | medium | high. Core default medium (the router's chat default).
 *   web          whether a conversation may use web search. Core default off; when on, it is offered only until the
 *                conversation holds business data (once any app has answered in it), never after.
 *
 * @package Zorderz\AiWorkspace
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZAW_Settings {

	const OPTION       = 'zaw_settings';
	const TURN_USD     = 1.0;
	const MIN_TURN_USD = 0.05;
	const MAX_GRANTS   = 50;
	const EFFORTS      = array( 'low', 'medium', 'high' );

	/** The most one answer may be given: the platform router's own per-call ceiling. */
	public static function ceiling() {
		return ( class_exists( 'ZDZ_Core_LLM' ) && defined( 'ZDZ_Core_LLM::MAX_CAP_USD' ) ) ? (float) ZDZ_Core_LLM::MAX_CAP_USD : 5.0;
	}

	/** The stored values, each checked, with Core's defaults. */
	public static function all() {
		$raw = get_option( self::OPTION, array() );
		return self::clean( is_array( $raw ) ? $raw : array() );
	}

	public static function clean( array $in ) {
		$usd = isset( $in['turn_usd'] ) && is_numeric( $in['turn_usd'] ) ? (float) $in['turn_usd'] : self::TURN_USD;
		$max = isset( $in['max_grants'] ) && is_numeric( $in['max_grants'] ) ? (int) $in['max_grants'] : 1;
		$eff = in_array( $in['effort'] ?? '', self::EFFORTS, true ) ? $in['effort'] : 'medium';
		return array(
			'turn_usd'   => round( max( self::MIN_TURN_USD, min( self::ceiling(), $usd ) ), 2 ),
			'max_grants' => max( 1, min( self::MAX_GRANTS, $max ) ),
			'effort'     => $eff,
			'web'        => ! empty( $in['web'] ),
		);
	}

	public static function get( $key ) {
		$all = self::all();
		return $all[ $key ] ?? null;
	}

	public static function save( array $in ) {
		update_option( self::OPTION, self::clean( $in ), false );
	}
}
