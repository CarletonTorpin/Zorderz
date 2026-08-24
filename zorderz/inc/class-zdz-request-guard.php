<?php
/**
 * Zdz_Request_Guard — wall-clock budget + cURL clamp + crash-safe global lock.
 *
 * WHY IT EXISTS
 * A background/relay loop that keeps making outbound HTTP calls until it "runs
 * out of rows" can hold a PHP-FPM worker indefinitely when an upstream hangs:
 * nothing crashes, the worker is simply *held*, and the site returns a
 * 502 Bad Gateway with an empty error log because every worker is parked in a
 * blocking socket read. This guard makes that class of failure structurally
 * impossible for any loop that runs under it:
 *
 *   - A WALL-CLOCK BUDGET that RESERVES the next call's projected cost before
 *     making it (can_afford()) — a loop that only checks elapsed time overshoots
 *     by one full timeout on its last iteration; this refuses the call whose
 *     projected cost would breach the budget.
 *   - A PER-HANDLE cURL CLAMP wired on `http_api_curl` (the only WP HTTP hook
 *     that sees a live curl handle — the http_request_args filters never reach a
 *     curl_multi handle), so a hung upstream can't out-wait the budget. The clamp
 *     NO-OPS when no budget is open, so interactive human requests keep patience.
 *   - A CRASH-SAFE GLOBAL LOCK (guarded()) built on an atomic INSERT IGNORE
 *     against the wp_options name unique index, with a compare-and-swap steal for
 *     a lock orphaned by a killed worker, releasing in finally so a throw can't
 *     leave it locked.
 *   - ADAPTIVE RESERVATION (observe()/projected_cost() = worst-observed × 1.5),
 *     so a run whose calls speed up reserves less and does more per pass.
 *
 * This is a sibling to (not a replacement for) the token-refresh single-flight
 * lock and breaker in class-zdz-token-service.php: that one serialises OAuth
 * refreshes on a provider-specific option namespace; this one is the general
 * request-budget/lock guard every networked sweep composes.
 *
 * Everything here is [CORE] mechanism: no table, no schema, no seeded data, and
 * no provider/vendor name — the lock reuses the wp_options unique index and the
 * service string is always supplied by the caller.
 *
 * @since   1.7.0
 * @package Zorderz
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Thrown by a guarded chokepoint when the next call cannot be afforded. The
 * catch at the sweep boundary MUST treat it as "leave the row untouched — the
 * caller's next tick is the retry": a *recorded* deferral corrupts data and lets
 * a caller page past unread rows (far worse than a slow pass). NON-RECORDING.
 */
if ( ! class_exists( 'Zdz_Budget_Deferred', false ) ) {
	class Zdz_Budget_Deferred extends \Exception {}
}

if ( ! class_exists( 'Zdz_Request_Guard', false ) ) :

class Zdz_Request_Guard {

	/** How many recent per-service cost samples to keep for adaptive reservation. */
	const SAMPLE_RING = 20;

	/** Active budget for THIS request: [ open, start, budget, label, service ]. */
	private static $budget = array( 'open' => false, 'start' => 0.0, 'budget' => 0.0, 'label' => '', 'service' => '' );

	/** Per-service recent cost samples (seconds): service => float[]. */
	private static $samples = array();

	/** Whether the http_api_curl clamp action is registered (once per request). */
	private static $clamp_registered = false;

	// ─────────────────────────────────────────────────────────────────
	// BUDGET LIFECYCLE
	// ─────────────────────────────────────────────────────────────────

	/**
	 * Open a wall-clock budget for THIS request. Idempotent-ish: re-opening
	 * resets the clock and (optionally) the budget.
	 *
	 * @param array $opts { budget_seconds?:float, label?:string, service?:string }
	 */
	public static function open( array $opts = array() ) {
		$budget = isset( $opts['budget_seconds'] ) && null !== $opts['budget_seconds']
			? (float) $opts['budget_seconds']
			: (float) apply_filters( 'zdz_guard_budget_seconds', 20 );

		self::$budget = array(
			'open'    => true,
			'start'   => microtime( true ),
			'budget'  => max( 0.0, $budget ),
			'label'   => isset( $opts['label'] ) ? (string) $opts['label'] : '',
			'service' => isset( $opts['service'] ) ? (string) $opts['service'] : '',
		);

		// Register the per-handle clamp exactly once per request. It no-ops
		// whenever a budget is not open, so leaving it registered is harmless.
		if ( ! self::$clamp_registered ) {
			add_action( 'http_api_curl', array( __CLASS__, 'clamp_handle' ), 10, 1 );
			self::$clamp_registered = true;
		}
	}

	/** Is a budget currently open for this request? */
	public static function is_open() {
		return (bool) self::$budget['open'];
	}

	/** Seconds elapsed since the budget opened (0.0 if none open). */
	public static function elapsed() {
		if ( ! self::$budget['open'] ) {
			return 0.0;
		}
		return microtime( true ) - (float) self::$budget['start'];
	}

	/** Seconds left in the budget (0.0 floor; full budget-ish if none open). */
	public static function remaining() {
		if ( ! self::$budget['open'] ) {
			return 0.0;
		}
		return max( 0.0, (float) self::$budget['budget'] - self::elapsed() );
	}

	/** Close the budget (does not unregister the clamp — it no-ops when closed). */
	public static function close() {
		self::$budget['open'] = false;
	}

	// ─────────────────────────────────────────────────────────────────
	// RESERVE-NEXT-CALL AFFORDABILITY (never measure-after)
	// ─────────────────────────────────────────────────────────────────

	/**
	 * Can the budget afford the NEXT call? Reserves its projected cost BEFORE it
	 * is made: ( elapsed + cost ) <= budget. A loop that only checks elapsed
	 * overshoots by one full timeout on its final iteration; this refuses the
	 * call whose projected cost would breach the budget.
	 *
	 * With no budget open, everything is affordable (interactive path).
	 *
	 * @param float|null $cost projected seconds for the next call; null → estimate.
	 */
	public static function can_afford( $cost = null ) {
		if ( ! self::$budget['open'] ) {
			return true;
		}
		$cost = ( null === $cost ) ? self::projected_cost( (string) self::$budget['service'] ) : (float) $cost;
		return ( self::elapsed() + $cost ) <= (float) self::$budget['budget'];
	}

	/**
	 * Record a real per-call cost sample for adaptive reservation. Keeps a
	 * bounded ring of the most recent samples per service.
	 */
	public static function observe( $service, $seconds ) {
		$service = (string) $service;
		$seconds = max( 0.0, (float) $seconds );
		if ( ! isset( self::$samples[ $service ] ) ) {
			self::$samples[ $service ] = array();
		}
		self::$samples[ $service ][] = $seconds;
		$overflow = count( self::$samples[ $service ] ) - self::SAMPLE_RING;
		if ( $overflow > 0 ) {
			self::$samples[ $service ] = array_slice( self::$samples[ $service ], $overflow );
		}
	}

	/**
	 * Projected cost of the next call: worst observed (over the recent ring) × 1.5.
	 * With no sample yet, a conservative floor of the cURL read timeout (so the
	 * first reservation never under-budgets a call that might hang to the clamp).
	 */
	public static function projected_cost( $service = '' ) {
		$service = (string) $service;
		// No sample yet → the conservative floor (a call could hang to the clamp).
		if ( empty( self::$samples[ $service ] ) ) {
			return (float) apply_filters(
				'zdz_guard_default_cost',
				(float) apply_filters( 'zdz_guard_curl_timeout_seconds', 8 )
			);
		}
		// Once we have real samples, TRUST them: worst observed × 1.5, WITHOUT
		// re-flooring — this is what lets an increasingly-fast service reserve
		// less and do more per pass (the adaptive 14→7 request shape).
		$worst = max( self::$samples[ $service ] );
		return max( 0.0, $worst * 1.5 );
	}

	// ─────────────────────────────────────────────────────────────────
	// PER-HANDLE cURL CLAMP (http_api_curl — the only hook with a live handle)
	// ─────────────────────────────────────────────────────────────────

	/**
	 * Clamp a live cURL handle's read/connect timeouts while a budget is open.
	 * NO-OP when no budget is open, so a bare wp_remote_get() outside any sweep
	 * keeps its default (patient) timeout — interactive human requests are never
	 * clamped. Wired via `http_api_curl`, which is the only WordPress HTTP hook
	 * that exposes the raw handle (the http_request_args filters never see a
	 * curl_multi handle).
	 *
	 * @param resource|\CurlHandle $handle
	 */
	public static function clamp_handle( $handle ) {
		if ( ! self::$budget['open'] ) {
			return; // interactive / no-budget path keeps its patience
		}
		if ( ! function_exists( 'curl_setopt' ) ) {
			return;
		}
		$read    = (int) apply_filters( 'zdz_guard_curl_timeout_seconds', 8 );
		$connect = (int) apply_filters( 'zdz_guard_curl_connect_seconds', 5 );
		@curl_setopt( $handle, CURLOPT_TIMEOUT, max( 1, $read ) );
		@curl_setopt( $handle, CURLOPT_CONNECTTIMEOUT, max( 1, $connect ) );
	}

	// ─────────────────────────────────────────────────────────────────
	// CRASH-SAFE GLOBAL CRON LOCK (atomic INSERT IGNORE + CAS steal)
	// ─────────────────────────────────────────────────────────────────

	/**
	 * Run $fn behind a global lock that serialises across every concurrent
	 * worker for $lock_key. Acquisition is an atomic INSERT IGNORE against the
	 * wp_options `option_name` unique index; a lock orphaned by a killed worker
	 * (row older than the stale window) is stolen via a compare-and-swap
	 * (DELETE the stale row, then re-INSERT IGNORE — the conditional re-insert is
	 * the CAS). $fn always runs inside try/finally so the lock is released even
	 * on a throw.
	 *
	 * @param string   $lock_key short key (namespaced to zdz_guard_lock_<key>).
	 * @param callable $fn       the critical section.
	 * @param array    $opts     { stale_seconds?:int }
	 * @return mixed|null  $fn's return value, or null when the lock is held by a
	 *                     live worker (no run).
	 */
	public static function guarded( $lock_key, callable $fn, array $opts = array() ) {
		global $wpdb;

		$option = 'zdz_guard_lock_' . sanitize_key( $lock_key );
		$stale  = isset( $opts['stale_seconds'] )
			? (int) $opts['stale_seconds']
			: (int) apply_filters( 'zdz_guard_lock_stale_seconds', 900 );
		$now   = time();
		// A timestamp-prefixed, uniquely-suffixed value: the (int) prefix drives
		// the staleness CAS; the full value lets release delete ONLY our own row
		// (so a mid-run steal can't be clobbered by our finally).
		$token = $now . ':' . wp_generate_password( 8, false, false );

		$inserted = $wpdb->query( $wpdb->prepare(
			"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
			$option, $token
		) );

		if ( 1 !== (int) $inserted ) {
			// Row exists — steal it only if it is stale (killed-worker orphan).
			$val = $wpdb->get_var( $wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
				$option
			) );
			if ( null === $val || ( $now - (int) $val ) <= $stale ) {
				return null; // held by a live worker — no run
			}
			$wpdb->query( $wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name = %s",
				$option
			) );
			$re = $wpdb->query( $wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
				$option, $token
			) );
			if ( 1 !== (int) $re ) {
				return null; // another worker won the steal
			}
			error_log( '[Zdz_Request_Guard] broke stale lock (> ' . $stale . 's) for ' . $option );
		}

		try {
			return $fn();
		} finally {
			$wpdb->query( $wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
				$option, $token
			) );
		}
	}
}

endif;
