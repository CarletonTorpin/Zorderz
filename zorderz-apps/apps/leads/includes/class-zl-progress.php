<?php
/**
 * Zorderz Leads — Batch Progress Tracker
 *
 * Wraps a transient per batch — `zlg_batch_progress_{batch_id}` — for the
 * frontend progress bar + stall detection.
 *
 * ──────────────────────────────────────────────────────────────────────────
 * TRAP 3 — HEARTBEAT PROMISE
 * ──────────────────────────────────────────────────────────────────────────
 * The backend MUST call {@see heartbeat()} at least once every 30s even
 * when no real progress has been made (e.g. waiting on a slow FreshBooks
 * response). The frontend watchdog considers the batch stalled if it
 * hasn't seen a heartbeat in 120s and surfaces the stall banner.
 *
 * ──────────────────────────────────────────────────────────────────────────
 * TRAP 7 — HONEST PROGRESS BAR
 * ──────────────────────────────────────────────────────────────────────────
 * Progress payload MUST carry current/total integers (never a synthetic
 * percentage) OR an elapsed-only mode. Frontend renders one or the other
 * but never fakes a percentage.
 *
 * Payload shape:
 *   {
 *     batch_id       int
 *     stage          string  — human-readable "Fetching invoices…"
 *     stage_key      string  — stable slug for the stage (e.g. 'enrich')
 *     current        int     — items completed in the current stage
 *     total          int     — total items in the current stage (0 = unknown)
 *     elapsed_s      int     — seconds since started
 *     updated_at     int     — unix timestamp of the last write
 *     warnings       array   — non-fatal notes (e.g. "429 rate limit — cap halved")
 *     done           bool    — true when the batch has finished (success or fail)
 *     failed         bool    — true when the batch terminated with an error
 *     error_message  string  — when failed=true
 *   }
 *
 * @package Zorderz\Leads
 * @since   1.8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZL_Progress {

	/** Transient TTL — long enough that a stalled batch still has a record. */
	const TTL_SECONDS = 6 * HOUR_IN_SECONDS;

	/** Stall threshold — if no heartbeat in this many seconds, frontend shows banner. */
	const STALL_THRESHOLD_S = 120;

	/** Heartbeat interval — backend MUST write at least this often. */
	const HEARTBEAT_INTERVAL_S = 30;

	/**
	 * Start a batch — creates the initial progress record.
	 *
	 * @param int    $batch_id
	 * @param string $initial_stage
	 * @return void
	 */
	public static function start( $batch_id, $initial_stage = 'Starting…' ) {
		$batch_id = (int) $batch_id;
		if ( $batch_id <= 0 ) {
			return;
		}
		$payload = array(
			'batch_id'      => $batch_id,
			'stage'         => (string) $initial_stage,
			'stage_key'     => 'start',
			'current'       => 0,
			'total'         => 0,
			'elapsed_s'     => 0,
			'started_at'    => time(),
			'updated_at'    => time(),
			'warnings'      => array(),
			'done'          => false,
			'failed'        => false,
			'error_message' => '',
		);
		set_transient( self::key( $batch_id ), $payload, self::TTL_SECONDS );
	}

	/**
	 * Update the current stage. Resets current/total (since we've moved on).
	 *
	 * @param int    $batch_id
	 * @param string $stage       Display string.
	 * @param string $stage_key   Stable slug.
	 * @param int    $total       Total for this stage (0 if unknown).
	 * @return void
	 */
	public static function stage( $batch_id, $stage, $stage_key = '', $total = 0 ) {
		self::patch( $batch_id, array(
			'stage'     => (string) $stage,
			'stage_key' => (string) $stage_key,
			'current'   => 0,
			'total'     => (int) $total,
		) );
	}

	/**
	 * Increment the "current" counter by $by and write the heartbeat.
	 * Call this from progress callbacks inside batch loops.
	 *
	 * @param int $batch_id
	 * @param int $by      Default 1.
	 * @return void
	 */
	public static function advance( $batch_id, $by = 1 ) {
		$cur = self::get( $batch_id );
		if ( ! $cur ) {
			return;
		}
		self::patch( $batch_id, array(
			'current' => (int) ( $cur['current'] ?? 0 ) + (int) $by,
		) );
	}

	/**
	 * Update the total (e.g. once we learn it from a page response).
	 *
	 * @param int $batch_id
	 * @param int $total
	 * @return void
	 */
	public static function set_total( $batch_id, $total ) {
		self::patch( $batch_id, array( 'total' => (int) $total ) );
	}

	/**
	 * Heartbeat — refresh updated_at without changing anything else.
	 * Safe to call as often as the pipeline wants; cheap.
	 *
	 * Trap 3 compliance: call this at LEAST every HEARTBEAT_INTERVAL_S
	 * regardless of whether concrete progress has been made.
	 *
	 * @param int $batch_id
	 * @return void
	 */
	public static function heartbeat( $batch_id ) {
		self::patch( $batch_id, array() );
	}

	/**
	 * Append a non-fatal warning to the batch.
	 *
	 * @param int    $batch_id
	 * @param string $message
	 * @return void
	 */
	public static function warn( $batch_id, $message ) {
		$cur = self::get( $batch_id );
		$warnings = is_array( $cur['warnings'] ?? null ) ? $cur['warnings'] : array();
		$warnings[] = array(
			'at'   => time(),
			'msg'  => (string) $message,
		);
		// Cap at 20 — protect the transient from unbounded growth if something
		// goes really wrong (e.g. repeated 429s over the life of the batch).
		if ( count( $warnings ) > 20 ) {
			$warnings = array_slice( $warnings, -20 );
		}
		self::patch( $batch_id, array( 'warnings' => $warnings ) );
	}

	/**
	 * Mark the batch as successfully complete.
	 *
	 * @param int $batch_id
	 * @return void
	 */
	public static function complete( $batch_id ) {
		self::patch( $batch_id, array(
			'done'      => true,
			'failed'    => false,
			'stage'     => 'Complete',
			'stage_key' => 'complete',
		) );
	}

	/**
	 * Mark the batch as failed.
	 *
	 * @param int    $batch_id
	 * @param string $message
	 * @return void
	 */
	public static function fail( $batch_id, $message ) {
		self::patch( $batch_id, array(
			'done'          => true,
			'failed'        => true,
			'error_message' => (string) $message,
			'stage'         => 'Failed',
			'stage_key'     => 'failed',
		) );
	}

	/**
	 * Read the current progress payload. Recomputes elapsed_s at read time
	 * so the frontend always gets up-to-date numbers without extra writes.
	 *
	 * @param int $batch_id
	 * @return array|null
	 */
	public static function get( $batch_id ) {
		$batch_id = (int) $batch_id;
		if ( $batch_id <= 0 ) {
			return null;
		}
		$raw = get_transient( self::key( $batch_id ) );
		if ( ! is_array( $raw ) ) {
			return null;
		}
		$started = (int) ( $raw['started_at'] ?? time() );
		$raw['elapsed_s']        = max( 0, time() - $started );
		$raw['stalled']          = ! ( $raw['done'] ?? false )
			&& ( time() - (int) ( $raw['updated_at'] ?? time() ) ) > self::STALL_THRESHOLD_S;
		$raw['stall_threshold_s'] = self::STALL_THRESHOLD_S;
		return $raw;
	}

	/**
	 * Purge a batch's progress record. Called after the frontend has
	 * acknowledged completion.
	 *
	 * @param int $batch_id
	 * @return void
	 */
	public static function forget( $batch_id ) {
		delete_transient( self::key( (int) $batch_id ) );
	}

	/**
	 * Merge-patch the current payload. Always refreshes updated_at.
	 *
	 * @param int   $batch_id
	 * @param array $patch
	 * @return void
	 */
	private static function patch( $batch_id, array $patch ) {
		$batch_id = (int) $batch_id;
		if ( $batch_id <= 0 ) {
			return;
		}
		$cur = self::get( $batch_id );
		if ( ! is_array( $cur ) ) {
			// If nothing exists yet, bootstrap with start() first so
			// started_at is correct.
			self::start( $batch_id );
			$cur = self::get( $batch_id );
			if ( ! is_array( $cur ) ) {
				return;
			}
		}
		$merged = array_merge( $cur, $patch );
		$merged['updated_at'] = time();
		set_transient( self::key( $batch_id ), $merged, self::TTL_SECONDS );
	}

	/**
	 * Transient key for a batch.
	 *
	 * @param int $batch_id
	 * @return string
	 */
	private static function key( $batch_id ) {
		return 'zlg_batch_progress_' . (int) $batch_id;
	}
}
