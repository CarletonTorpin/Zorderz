<?php
/**
 * ZAW_Money -- an answer's money, seen from both sides: this site's Ai ledger and the engine's own.
 *
 *   A turn from this site   BEFORE it is sent, a HOLD in the platform's Ai ledger (ZDZ_LLM_Ledger::reserve) for its
 *                           most: the workspace's per-answer cap, or less when the month has less left under the
 *                           business's budget. With no budget set nothing is sent (the router's rule). Under 5 cents
 *                           nothing is sent either. The hold IS the turn's headroom: it travels with the turn and the
 *                           engine holds the turn to it. At the PULL (the moment the provider key leaves this site) the
 *                           hold becomes ONE row in the Ai ledger at the hold's amount, outcome in_progress, caller
 *                           app:ai-workspace (numbers only); then the hold is released. When the turn ends, that row is
 *                           SETTLED to the engine's real figures. A hold counts for at most 15 minutes; a row counts for
 *                           the month, so a turn whose end is late or never comes is never uncounted.
 *   Whose line              the engine spends on the business's provider account, not inside this site: its rows count
 *                           toward the account's line ('all') and not the apps' line, through zdz_llm_external_callers.
 *   The engine's own spend  every status answer from the hub carries the engine's month without this site's turns
 *                           (`other_usd`); kept in the option zaw_money and added to the router's total through the
 *                           filter zdz_llm_external_month_usd. No double count: this site's turns are rows, the rest is
 *                           the filter.
 *   Not heard from the hub  for more than an hour: the filter counts the most the engine could have spent this month
 *                           that this site can't see: max( the last figure, the engine's own line less this site's own
 *                           rows ) when the engine has a line, else the last figure. Spend that can't be seen can't be
 *                           capped. The five-minute sweep keeps it fresh while the hub is reachable.
 *   A turn the hub lost     (no end within 20 minutes): its row stays at its hold, the most it could have cost, marked
 *                           estimated; if the hub reports it later, the row is settled to what it says.
 *
 * @package Zorderz\AiWorkspace
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZAW_Money {

	const OPTION    = 'zaw_money';
	const MIN_USD   = 0.05;          // under this much headroom nothing is sent
	const STALE_S   = 3600;          // an hour without word from the hub
	const HOLD_LIFE = 900;           // the ledger's longest hold; the hub never starts a turn that waited over 4 minutes
	const CALLER    = 'app:ai-workspace';

	/** The clock (a suite swaps it). */
	public static $now = null;

	private static function now() {
		return self::$now ? (int) call_user_func( self::$now ) : time();
	}

	public static function init() {
		add_filter( 'zdz_llm_external_month_usd', array( __CLASS__, 'filter' ), 10, 1 );
		add_filter( 'zdz_llm_external_callers', array( __CLASS__, 'external_caller' ), 10, 1 );
	}

	/** The engine's rows are the provider account's spend, not the apps': counted toward 'all' only. */
	public static function external_caller( $list ) {
		$list   = is_array( $list ) ? $list : array();
		$list[] = self::CALLER;
		return array_values( array_unique( $list ) );
	}

	/** The business's monthly lines in thousandths, or null when no budget is set (then nothing is sent). */
	public static function lines() {
		if ( ! class_exists( 'ZDZ_Core_LLM' ) || ! method_exists( 'ZDZ_Core_LLM', 'budgets' ) ) {
			return null;
		}
		$b = ZDZ_Core_LLM::budgets();
		if ( empty( $b['set'] ) ) {
			return null;
		}
		return array( 'app' => (int) round( (float) $b['app'] * 1000 ), 'all' => (int) round( (float) $b['all'] * 1000 ) );
	}

	/**
	 * Book a turn's most before it is sent. -> [ 'id' => int, 'usd' => float, 'why' => '' | 'no_budget' | 'none_left' ]
	 * (usd 0: the turn is not sent), or null when the ledger can't be read (the turn is not sent either).
	 */
	public static function hold( $want_usd ) {
		if ( ! class_exists( 'ZDZ_LLM_Ledger' ) || ! method_exists( 'ZDZ_LLM_Ledger', 'reserve' ) ) {
			return null;
		}
		$lines = self::lines();
		if ( null === $lines ) {
			return array( 'id' => 0, 'usd' => 0.0, 'why' => 'no_budget' );
		}
		if ( method_exists( 'ZDZ_LLM_Ledger', 'maybe_install' ) && ! ZDZ_LLM_Ledger::maybe_install() ) {
			return null;
		}
		$h = ZDZ_LLM_Ledger::reserve( (float) $want_usd, $lines, false, self::HOLD_LIFE );
		if ( ! is_array( $h ) ) {
			return null;
		}
		if ( (float) $h['usd'] < self::MIN_USD ) {
			if ( (int) $h['id'] > 0 ) {
				ZDZ_LLM_Ledger::release( (int) $h['id'] );
			}
			return array( 'id' => 0, 'usd' => 0.0, 'why' => 'none_left' );
		}
		return array( 'id' => (int) $h['id'], 'usd' => (float) $h['usd'], 'why' => '' );
	}

	public static function release( $id ) {
		if ( (int) $id > 0 && class_exists( 'ZDZ_LLM_Ledger' ) ) {
			ZDZ_LLM_Ledger::release( (int) $id );
		}
	}

	/** A model id as the router writes it in the ledger. */
	public static function model_code( $model ) {
		if ( class_exists( 'ZDZ_Core_LLM' ) && method_exists( 'ZDZ_Core_LLM', 'model_code' ) ) {
			return (string) ZDZ_Core_LLM::model_code( (string) $model );
		}
		$c = trim( (string) preg_replace( '/[^a-z0-9_.:\-]+/', '-', strtolower( trim( (string) $model ) ) ), '-' );
		return '' === $c ? 'other' : substr( $c, 0, 32 );
	}

	/** A turn's end in the router's outcome words: done "ok"; the engine's error and the runner's failure. */
	public static function outcome( $state, $est ) {
		if ( $est ) {
			return 'estimated';
		}
		$map = array( 'done' => 'ok', 'error' => 'error_engine', 'failed' => 'error_runner' );
		$s   = preg_replace( '/[^a-z0-9_]/', '', strtolower( (string) $state ) );
		return $map[ $s ] ?? $s;
	}

	/** The row's fields from the engine's usage {in, out, cache_read, cache_write_5m, cache_write_1h, searches, usd}. */
	private static function fields( array $turn, array $u, $outcome, $model, $fallback, $est ) {
		return array(
			'caller'      => self::CALLER,
			'role'        => 'assistant',
			'provider'    => 'anthropic',
			'model'       => self::model_code( $model ),
			'in_tokens'   => (int) ( $u['in'] ?? 0 ),
			'cache_read'  => (int) ( $u['cache_read'] ?? 0 ),
			'cache_write' => (int) ( $u['cache_write_5m'] ?? 0 ) + (int) ( $u['cache_write_1h'] ?? 0 ),
			'out_tokens'  => (int) ( $u['out'] ?? 0 ),
			'searches'    => (int) ( $u['searches'] ?? 0 ),
			'usd_milli'   => (int) ceil( max( 0.0, (float) ( $u['usd'] ?? 0 ) ) * 1000 - 1e-9 ),
			'ms'          => max( 0, (int) ( self::now() - strtotime( (string) ( $turn['created'] ?? '' ) . ' UTC' ) ) * 1000 ),
			'outcome'     => self::outcome( $outcome, $est ),
			'fallback'    => $fallback ? 1 : 0,
		);
	}

	/** The turn's one ledger row. -> its id, or 0. */
	public static function book( array $turn, array $u, $outcome, $model, $fallback, $est ) {
		global $wpdb;
		if ( ! class_exists( 'ZDZ_LLM_Ledger' ) ) {
			return 0;
		}
		$row = self::fields( $turn, $u, $outcome, $model, $fallback, $est ) + array(
			'coded_count'  => (int) ( $turn['coded_count'] ?? 0 ),
			'caught_count' => (int) ( $turn['caught_count'] ?? 0 ),
		);
		if ( ! ZDZ_LLM_Ledger::write( $row ) ) {
			return 0;
		}
		return (int) $wpdb->insert_id;
	}

	/**
	 * The turn's row, written at its hold when the hub pulled it, now set to what the hub reported (or kept at the hold
	 * and marked estimated). The same figures written twice change nothing, so two pollers may both settle. Its time
	 * (ts) stays the pull's. -> true when stored.
	 */
	public static function settle( $ledger_id, array $turn, array $u, $outcome, $model, $fallback, $est ) {
		global $wpdb;
		if ( (int) $ledger_id <= 0 || ! class_exists( 'ZDZ_LLM_Ledger' ) ) {
			return false;
		}
		$row  = ZDZ_LLM_Ledger::clean( self::fields( $turn, $u, $outcome, $model, $fallback, $est ) );
		$keep = array( 'model', 'in_tokens', 'cache_read', 'cache_write', 'out_tokens', 'searches', 'usd_milli', 'ms', 'outcome', 'fallback' );
		$f    = array_intersect_key( $row, array_flip( $keep ) );
		$fmt  = array();
		foreach ( array_keys( $f ) as $k ) {
			$fmt[] = in_array( $k, array( 'model', 'outcome' ), true ) ? '%s' : '%d';
		}
		return false !== $wpdb->update( ZDZ_LLM_Ledger::table(), $f, array( 'id' => (int) $ledger_id, 'caller' => self::CALLER ), $fmt, array( '%d', '%s' ) );
	}

	/* ------------------------------------------------------------------ the engine's month, through the filter */

	/** Keep what the hub said: { month, engine_usd, other_usd, cap_usd (or null), as_of }. */
	public static function note( $m ) {
		if ( ! is_array( $m ) || ! isset( $m['other_usd'], $m['month'] ) || ! preg_match( '/^\d{4}-\d{2}$/', (string) $m['month'] ) ) {
			return false;                                     // an unreadable ledger on the hub: nothing new is kept
		}
		$v = array(
			'month'  => (string) $m['month'],
			'other'  => max( 0.0, (float) $m['other_usd'] ),
			'engine' => max( 0.0, (float) ( $m['engine_usd'] ?? 0 ) ),
			'cap'    => ( isset( $m['cap_usd'] ) && is_numeric( $m['cap_usd'] ) ) ? max( 0.0, (float) $m['cap_usd'] ) : null,
			'at'     => self::now(),
		);
		update_option( self::OPTION, $v, false );
		return true;
	}

	/** This site's own workspace rows this month (USD). */
	public static function site_engine_usd() {
		global $wpdb;
		if ( ! class_exists( 'ZDZ_LLM_Ledger' ) ) {
			return 0.0;
		}
		$v = $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(usd_milli),0) FROM ' . ZDZ_LLM_Ledger::table() . ' WHERE ts >= %s AND caller = %s', gmdate( 'Y-m-01 00:00:00', self::now() ), self::CALLER ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is the ledger's
		return is_numeric( $v ) ? (float) $v / 1000.0 : 0.0;
	}

	/** What the engine spent this month that this site can't see as rows. */
	public static function external_usd() {
		$m     = get_option( self::OPTION, array() );
		$month = gmdate( 'Y-m', self::now() );
		$fresh = is_array( $m ) && isset( $m['at'] ) && self::now() - (int) $m['at'] <= self::STALE_S;
		$last  = ( is_array( $m ) && ( $m['month'] ?? '' ) === $month ) ? (float) ( $m['other'] ?? 0 ) : 0.0;
		if ( $fresh && ( $m['month'] ?? '' ) === $month ) {
			return $last;
		}
		$cap = is_array( $m ) && isset( $m['cap'] ) && is_numeric( $m['cap'] ) ? (float) $m['cap'] : null;
		if ( null === $cap ) {
			return $last;                                     // the engine has no line of its own: its last word stands
		}
		return max( $last, $cap - self::site_engine_usd() );  // stale (or a new month not yet heard): the most unseen
	}

	public static function filter( $usd ) {
		return (float) $usd + self::external_usd();
	}

	/** For the page: is the engine's figure fresh, and how old. */
	public static function freshness() {
		$m = get_option( self::OPTION, array() );
		if ( ! is_array( $m ) || ! isset( $m['at'] ) ) {
			return array( 'fresh' => false, 'age_s' => null );
		}
		return array( 'fresh' => self::now() - (int) $m['at'] <= self::STALE_S, 'age_s' => self::now() - (int) $m['at'] );
	}
}
