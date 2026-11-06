<?php
/**
 * Zorderz Surveys — the time budget for a sweep or a send.
 *
 * Every CRM write this app makes in the background (an auto-close, a review note, the note and
 * the Won mark after an e-mail) runs under a wall-clock budget, so a slow or hanging CRM can
 * hold a worker only for that long, and stops early instead of running past the host's request
 * limit, where the worker would be killed mid-item. Three rules:
 *
 *   1. While a budget is open the kernel's request guard (Zdz_Request_Guard) clamps every
 *      outbound call's timeout, so no single call can out-wait the budget.
 *   2. Before an item starts, the calls it will make are reserved: every call but the last at
 *      what a call TYPICALLY costs this request (the median of the calls made so far — one
 *      slow call must not price every later item out of the run), the last at a full call.
 *      With no evidence yet the first item is allowed to start, so a sweep always progresses.
 *   3. When the CRM's circuit breaker (Zdz_Service_Breaker) is open, nothing starts.
 *
 * Every run logs what it did against the budget, including how many items were left for the
 * next run. Without the kernel guard the budget is still measured and enforced here.
 *
 * @package Zorderz\Surveys
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZSV_Budget {

	/** The breaker and sample key for this app's CRM calls. */
	const SERVICE = 'surveys_crm';

	/** A full CRM call, in seconds (the kernel guard's clamp when present). */
	const FULL_CALL_S = 8.0;

	/** How many recent calls the typical cost looks at. */
	const TYPICAL_WINDOW = 15;

	/** @var float|null microtime the budget opened, null when none is open. */
	private static $started = null;

	/** @var float seconds */
	private static $budget = 0.0;

	/** @var string */
	private static $label = '';

	/** @var float[] seconds each timed call took this request */
	private static $calls = array();

	/** @var bool whether this class opened the kernel guard (and so closes it) */
	private static $opened_guard = false;

	/**
	 * Open a budget. Nested opens keep the outer one (a sweep's budget covers its items).
	 *
	 * @param string $label   For the log line.
	 * @param float  $seconds Budget; filter `zsv_budget_seconds` (per label) may change it.
	 * @return bool True when this call opened it (and must close it).
	 */
	public static function open( string $label, float $seconds ): bool {
		if ( null !== self::$started ) {
			return false;
		}
		$seconds        = (float) apply_filters( 'zsv_budget_seconds', $seconds, $label );
		self::$budget   = max( 2.0 * self::full_call(), $seconds ); // never less than one two-call item
		self::$started  = microtime( true );
		self::$label    = $label;
		self::$calls    = array();
		self::$opened_guard = false;
		if ( class_exists( 'Zdz_Request_Guard' ) && ! Zdz_Request_Guard::is_open() ) {
			Zdz_Request_Guard::open( array( 'budget_seconds' => self::$budget, 'label' => 'surveys:' . $label, 'service' => self::SERVICE ) );
			self::$opened_guard = true;
		}
		return true;
	}

	public static function close(): void {
		if ( self::$opened_guard && class_exists( 'Zdz_Request_Guard' ) ) {
			Zdz_Request_Guard::close();
		}
		self::$started      = null;
		self::$opened_guard = false;
	}

	public static function is_open(): bool {
		return null !== self::$started;
	}

	public static function elapsed(): float {
		return null === self::$started ? 0.0 : microtime( true ) - self::$started;
	}

	public static function remaining(): float {
		return null === self::$started ? INF : max( 0.0, self::$budget - self::elapsed() );
	}

	/** A full call's cost: the kernel clamp when present, else FULL_CALL_S. */
	public static function full_call(): float {
		$clamp = (float) apply_filters( 'zdz_guard_curl_timeout_seconds', self::FULL_CALL_S );
		return $clamp > 0 ? $clamp : self::FULL_CALL_S;
	}

	/** Record what one CRM call cost (seconds), and tell the breaker how it went. */
	public static function observe( float $seconds, bool $ok = true ): void {
		if ( $seconds >= 0 ) {
			self::$calls[] = $seconds;
		}
		if ( class_exists( 'Zdz_Request_Guard' ) ) {
			Zdz_Request_Guard::observe( self::SERVICE, $seconds );
		}
		if ( class_exists( 'Zdz_Service_Breaker' ) ) {
			if ( $ok ) {
				Zdz_Service_Breaker::record_success( self::SERVICE );
			} else {
				Zdz_Service_Breaker::record_failure( self::SERVICE );
			}
		}
	}

	/**
	 * Time one CRM call and record it. A null or false answer counts as a failure for the
	 * breaker (the Core CRM client answers null on a transport or API error).
	 *
	 * @param callable $fn
	 * @return mixed What $fn returned (exceptions propagate after being recorded).
	 */
	public static function call( callable $fn ) {
		$t0 = microtime( true );
		try {
			$r = $fn();
		} catch ( \Throwable $e ) {
			self::observe( microtime( true ) - $t0, false );
			throw $e;
		}
		self::observe( microtime( true ) - $t0, null !== $r && false !== $r );
		return $r;
	}

	/**
	 * What a call TYPICALLY costs this request: the median of the last TYPICAL_WINDOW calls,
	 * never above a full call; a full call before any evidence. Pure given the samples.
	 *
	 * @param float[]|null $samples Defaults to this request's calls.
	 */
	public static function typical( ?array $samples = null ): float {
		$s = null === $samples ? self::$calls : $samples;
		if ( empty( $s ) ) {
			return self::full_call();
		}
		$s = array_slice( array_values( $s ), -self::TYPICAL_WINDOW );
		sort( $s );
		$n   = count( $s );
		$mid = (int) floor( $n / 2 );
		$med = ( $n % 2 ) ? $s[ $mid ] : ( $s[ $mid - 1 ] + $s[ $mid ] ) / 2;
		return min( self::full_call(), max( 0.05, (float) $med ) );
	}

	/** Seconds to reserve before an item that makes $calls CRM calls. */
	public static function item_reserve( int $calls, ?array $samples = null ): float {
		$calls = max( 1, $calls );
		return ( $calls - 1 ) * self::typical( $samples ) + self::full_call();
	}

	/**
	 * May an item that makes $calls CRM calls start now? Always when no budget is open; never
	 * while the breaker is open; the first item of a run always (progress is guaranteed);
	 * otherwise only when its reserve fits in what is left.
	 */
	public static function can_start( int $calls ): bool {
		if ( class_exists( 'Zdz_Service_Breaker' ) && Zdz_Service_Breaker::is_open( self::SERVICE ) ) {
			return false;
		}
		if ( null === self::$started ) {
			return true;
		}
		if ( empty( self::$calls ) ) {
			return true;
		}
		return self::item_reserve( $calls ) <= self::remaining();
	}

	/** One line per run: what was done, against the budget, and what was left for the next run. */
	public static function log_run( string $what, int $done, int $total, ?int $deferred = null ): void {
		$deferred = null === $deferred ? max( 0, $total - $done ) : $deferred;
		$msg      = sprintf( 'Zorderz Surveys: %s processed %d of %d — %.1fs of the %.1fs budget used', $what, $done, $total, self::elapsed(), self::$budget );
		if ( self::elapsed() > self::$budget ) {
			$msg .= sprintf( ' (over budget by %.1fs)', self::elapsed() - self::$budget );
		}
		if ( $deferred > 0 ) {
			$msg .= sprintf( '; stopped early — %d left for the next run', $deferred );
		}
		error_log( $msg );
	}
}
