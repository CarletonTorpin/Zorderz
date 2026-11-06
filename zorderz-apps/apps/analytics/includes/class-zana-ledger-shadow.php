<?php
/**
 * ZANA_Ledger_Shadow — the ledger in shadow: would it have given the same documents as a live billing read?
 *
 * Before a question moves from paging the billing system live to reading the ledger, real turns should show the ledger
 * holds what the billing system holds. A caller that pages a window of invoices or estimates live hands the documents
 * here (run()); this compares them with the ledger's own rows for the same window, by document id and amount, and
 * returns a verdict and a reason from fixed word lists. It changes nothing anyone sees or anything a model is sent, and
 * keeps no document, figure, name or text: only a daily tally of verdicts (record()), for the settings panel.
 *
 *   same      every live document is in the ledger with the same amount, and the ledger has none the live pages lack
 *   differs   ledger behind  every difference is a document the billing system changed around or after the ledger's
 *                            last good sync (explained by the hourly sync, not by the ledger's rules)
 *             ledger lacks   a live document the ledger does not hold
 *             amounts        the same document with another amount
 *             ledger extra   the ledger holds a document the live pages did not return
 *   missing   the ledger can't speak for this window: not complete, not fresh, filtered (the live read asked for a
 *             status), clamped or truncated (the live sweep did not cover the window), cached (the live side came from
 *             a cache), memory, no window, error
 *
 * Both sides drop deleted and archived documents and keep drafts: the question is whether the ledger holds what the
 * billing system holds, not how a report counts it.
 *
 * @package Zorderz\Analytics
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZANA_Ledger_Shadow {

	/** The verdicts, worst last. */
	const VERDICTS    = array( 'same', 'missing', 'differs' );
	const DIFFERS_WHY = array( 'ledger lacks', 'amounts', 'ledger extra', 'ledger behind' );
	const MISSING_WHY = array( 'not complete', 'not fresh', 'filtered', 'clamped', 'truncated', 'cached', 'memory', 'no window', 'error' );

	/** Window keys that are not a filter (paging). */
	const WINDOW_KEYS = array( 'date_min', 'date_max', 'per_page' );

	/** The windows a caller pages. */
	const WINDOWS = array( 'invoices', 'invoices_comparison', 'estimates', 'estimates_comparison' );

	/** Fresh = a good sync in the last three hours. */
	const FRESH_S = 10800;

	/** A document changed no earlier than this before the last good sync counts as "the ledger is behind". */
	const LAG_S = 21600;

	/** The daily tally (option): date => verdict => count. Kept 30 days. */
	const TALLY_OPTION = 'zana_ledger_shadow_tally';

	/**
	 * Compare one window, pure. $live_docs: raw billing documents. $ledger_rows: [ {id, amount, d, vis_state, u} ].
	 * $cov: ZANA_Ledger::coverage(). $sweep: { clamped, truncated, cached, memory }.
	 */
	public static function compare( string $kind, array $window, array $live_docs, array $ledger_rows, array $cov, array $sweep, int $now ): array {
		$base = self::base_kind( $kind );
		if ( '' === $base ) {
			return self::verdict( 'missing', 'error' );
		}
		$min = ZANA_Ledger::date( $window['date_min'] ?? '' );
		$max = ZANA_Ledger::date( $window['date_max'] ?? '' );
		if ( null === $min ) {
			return self::verdict( 'missing', 'no window' );
		}
		$max = null === $max ? gmdate( 'Y-m-d', $now ) : $max;
		$c   = (array) ( $cov[ $base ] ?? array() );
		if ( empty( $c['complete'] ) ) {
			return self::verdict( 'missing', 'not complete' );
		}
		$last_ok = '' !== (string) ( $c['last_ok'] ?? '' ) ? strtotime( (string) $c['last_ok'] . ' UTC' ) : false;
		if ( ! $last_ok || $now - $last_ok > self::FRESH_S ) {
			return self::verdict( 'missing', 'not fresh' );
		}
		foreach ( $window as $wk => $wv ) {
			if ( ! in_array( (string) $wk, self::WINDOW_KEYS, true ) && null !== $wv && '' !== $wv && array() !== $wv && false !== $wv ) {
				return self::verdict( 'missing', 'filtered' );
			}
		}
		foreach ( array( 'clamped', 'truncated', 'cached', 'memory' ) as $k ) {
			if ( ! empty( $sweep[ $k ] ) ) {
				return self::verdict( 'missing', $k );
			}
		}
		$live   = array();
		$id_key = 'invoices' === $base ? 'invoiceid' : 'estimateid';
		foreach ( $live_docs as $doc ) {
			if ( ! is_array( $doc ) ) {
				continue;
			}
			$id = (int) ( $doc[ $id_key ] ?? $doc['id'] ?? 0 );
			$d  = ZANA_Ledger::date( $doc['create_date'] ?? '' );
			if ( $id <= 0 || null === $d || $d < $min || $d > $max || in_array( (int) ( $doc['vis_state'] ?? 0 ), array( 1, 2 ), true ) ) {
				continue;
			}
			$live[ $id ] = array( ZANA_Ledger::money( $doc['amount'] ?? 0 ), ZANA_Ledger::datetime( $doc['updated'] ?? '' ) );
		}
		$ledger = array();
		foreach ( $ledger_rows as $r ) {
			$id = (int) ( $r['id'] ?? 0 );
			$d  = ZANA_Ledger::date( $r['d'] ?? '' );
			if ( $id <= 0 || null === $d || $d < $min || $d > $max || in_array( (int) ( $r['vis_state'] ?? 0 ), array( 1, 2 ), true ) ) {
				continue;
			}
			$ledger[ $id ] = array( round( (float) ( $r['amount'] ?? 0 ), 2 ), ZANA_Ledger::datetime( $r['u'] ?? '' ) );
		}
		$recent = function ( $updated ) use ( $last_ok ) {
			$t = null !== $updated ? strtotime( $updated . ' UTC' ) : false;
			return $t && $t >= $last_ok - self::LAG_S;
		};
		$lacks   = 0;
		$amounts = 0;
		$behind  = 0;
		foreach ( $live as $id => $l ) {
			if ( ! array_key_exists( $id, $ledger ) ) {
				$recent( $l[1] ) ? $behind++ : $lacks++;
			} elseif ( abs( $ledger[ $id ][0] - $l[0] ) > 0.004 ) {
				( $recent( $l[1] ) || $recent( $ledger[ $id ][1] ) ) ? $behind++ : $amounts++;
			}
		}
		$extra = 0;
		foreach ( array_diff_key( $ledger, $live ) as $lr ) {
			$recent( $lr[1] ) ? $behind++ : $extra++;
		}
		if ( $lacks ) {
			return self::verdict( 'differs', 'ledger lacks' );
		}
		if ( $amounts ) {
			return self::verdict( 'differs', 'amounts' );
		}
		if ( $extra ) {
			return self::verdict( 'differs', 'ledger extra' );
		}
		if ( $behind ) {
			return self::verdict( 'differs', 'ledger behind' );
		}
		return self::verdict( 'same', '' );
	}

	/** The ledger's rows for a window (deleted and archived never leave the database). */
	public static function ledger_rows( string $kind, array $window, int $now ): array {
		global $wpdb;
		$base = self::base_kind( $kind );
		$min  = ZANA_Ledger::date( $window['date_min'] ?? '' );
		$max  = ZANA_Ledger::date( $window['date_max'] ?? '' );
		if ( '' === $base || null === $min || ! isset( $wpdb ) ) {
			return array();
		}
		$max = null !== $max ? $max : gmdate( 'Y-m-d', $now );
		$sql = 'invoices' === $base
			? 'SELECT invoice_id AS id, amount, inv_date AS d, vis_state, fb_updated AS u FROM ' . ZANA_Ledger_Store::table( 'invoices' ) . ' WHERE inv_date >= %s AND inv_date <= %s AND vis_state NOT IN (1,2)'
			: 'SELECT estimate_id AS id, amount, est_date AS d, vis_state, fb_updated AS u FROM ' . ZANA_Ledger_Store::table( 'estimates' ) . ' WHERE est_date >= %s AND est_date <= %s AND vis_state NOT IN (1,2)';
		return (array) $wpdb->get_results( $wpdb->prepare( $sql, $min, $max ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL -- fixed table names.
	}

	/** One window end to end, never throwing; the verdict is tallied. */
	public static function run( string $kind, $window, $live_docs, array $sweep = array(), $now = null ): array {
		try {
			$now = null === $now ? time() : (int) $now;
			if ( ! is_array( $window ) || ! is_array( $live_docs ) ) {
				return self::record( self::verdict( 'missing', 'error' ) );
			}
			if ( null === self::$cov || $now - self::$cov_at > 60 ) {
				self::$cov    = ZANA_Ledger::coverage();
				self::$cov_at = $now;
			}
			$pre = self::compare( $kind, $window, array(), array(), self::$cov, $sweep, $now );
			if ( 'missing' === $pre['verdict'] ) {
				return self::record( $pre );
			}
			return self::record( self::compare( $kind, $window, $live_docs, self::ledger_rows( $kind, $window, $now ), self::$cov, $sweep, $now ) );
		} catch ( \Throwable $e ) {
			error_log( 'Zorderz Analytics ledger shadow (' . self::base_kind( $kind ) . '): ' . get_class( $e ) );
			return self::verdict( 'missing', 'error' );
		}
	}

	/** Add a verdict to today's tally (no document, figure or name is kept). */
	public static function record( array $v ): array {
		if ( function_exists( 'get_option' ) && function_exists( 'update_option' ) ) {
			$t   = get_option( self::TALLY_OPTION, array() );
			$t   = is_array( $t ) ? $t : array();
			$day = gmdate( 'Y-m-d' );
			$key = $v['verdict'] . ( '' !== $v['why'] ? ' (' . $v['why'] . ')' : '' );
			$t[ $day ][ $key ] = (int) ( $t[ $day ][ $key ] ?? 0 ) + 1;
			ksort( $t );
			update_option( self::TALLY_OPTION, array_slice( $t, -30, null, true ), false );
		}
		return $v;
	}

	/** The step label for one window: "shadow: ledger same (invoices)", "shadow: ledger differs (estimates, amounts)". */
	public static function label( string $kind, array $v ): string {
		$kind = in_array( $kind, self::WINDOWS, true ) ? $kind : 'other';
		$verd = in_array( $v['verdict'] ?? '', self::VERDICTS, true ) ? $v['verdict'] : 'missing';
		$why  = (string) ( $v['why'] ?? '' );
		if ( 'same' === $verd || ! in_array( $why, array_merge( self::DIFFERS_WHY, self::MISSING_WHY ), true ) ) {
			$why = 'same' === $verd ? '' : 'error';
		}
		return 'shadow: ledger ' . $verd . ' (' . $kind . ( '' !== $why ? ', ' . $why : '' ) . ')';
	}

	/** A turn's verdict from its step labels: the worst window's, or null when it paged none. */
	public static function turn_verdict( string $steps ): ?array {
		if ( ! preg_match_all( '/shadow: ledger (same|missing|differs) \(([a-z_]+)(?:, ([a-z ]+))?\)/', $steps, $m, PREG_SET_ORDER ) ) {
			return null;
		}
		$best = null;
		foreach ( $m as $x ) {
			$rank = (int) array_search( $x[1], self::VERDICTS, true );
			if ( null === $best || $rank > $best[0] ) {
				$best = array( $rank, $x[1], (string) ( $x[3] ?? '' ) );
			}
		}
		return array( 'verdict' => $best[1], 'why' => $best[2] );
	}

	/** This request's coverage, read at most once a minute. */
	protected static $cov    = null;
	protected static $cov_at = 0;

	public static function reset(): void {
		self::$cov    = null;
		self::$cov_at = 0;
	}

	protected static function verdict( string $v, string $why ): array {
		return array( 'verdict' => $v, 'why' => $why );
	}

	protected static function base_kind( string $kind ): string {
		if ( 0 === strpos( $kind, 'invoices' ) ) {
			return 'invoices';
		}
		return 0 === strpos( $kind, 'estimates' ) ? 'estimates' : '';
	}
}
