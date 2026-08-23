<?php
/**
 * Zdz_Sweep — a budgeted, wall-clock, lock-serialised background sweep.
 *
 * One place every cron/relay loop on the platform is written the same way: take
 * the global lock, open a wall-clock budget, pull a bounded batch, process rows
 * while the budget can still AFFORD the next one, and defer the rest cleanly for
 * the next tick. It composes Zdz_Request_Guard (lock + budget + reserve-next-call
 * affordability + cURL clamp) and Zdz_Service_Breaker (short-circuit a sick
 * upstream), so no loop has to re-derive those decisions.
 *
 * The deferral is NON-RECORDING: rows left unprocessed are simply not touched, so
 * the source's next pull picks them up again. A row is never marked processed on
 * a defer, and no row is double-processed.
 *
 * This is Core from the start (unlike the jobs-app-local Zdz_Flow): a sweep holds
 * no per-app state. Plan 01's Projects reconcile and Plan 02's lead-ref backfill
 * consume this rather than re-implementing a loop — there is exactly one sweep
 * helper and this is it. [CORE] entirely; no Identity value.
 *
 * @since   1.7.0
 * @package Zorderz
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Zdz_Sweep', false ) ) :

class Zdz_Sweep {

	/**
	 * Run a budgeted sweep.
	 *
	 * @param string   $lock_key global lock key (serialises across workers).
	 * @param callable $source   fn(int $limit): array — pull a bounded batch.
	 * @param callable $work     fn(mixed $row): void — process one row; a row is
	 *                           the caller's own shape (marking it processed is
	 *                           the caller's job, so a defer leaves it untouched).
	 * @param array    $opts {
	 *     @type float  $budget_seconds  wall-clock budget (default: guard filter, 20).
	 *     @type string $service         breaker/observe key (a caller-supplied
	 *                                    provider name; never hardcoded here).
	 *     @type int    $batch_limit     rows to pull per tick (default 25).
	 *     @type string $cursor_key      option name to persist a cursor into
	 *                                   (optional; off by default — most consumers
	 *                                   let the unprocessed data BE the cursor).
	 *     @type callable $cursor_of     fn(mixed $row): scalar — cursor value of a
	 *                                   processed row (required if cursor_key set).
	 * }
	 * @return array { processed:int, deferred:int, elapsed:float, budget:float,
	 *                 skipped_locked?:bool }
	 */
	public static function run( $lock_key, callable $source, callable $work, array $opts = array() ) {
		$service     = isset( $opts['service'] ) ? (string) $opts['service'] : (string) $lock_key;
		$batch_limit = isset( $opts['batch_limit'] ) ? max( 1, (int) $opts['batch_limit'] ) : 25;
		$budget_secs = isset( $opts['budget_seconds'] ) ? $opts['budget_seconds'] : null;
		$cursor_key  = isset( $opts['cursor_key'] ) ? (string) $opts['cursor_key'] : '';
		$cursor_of   = ( isset( $opts['cursor_of'] ) && is_callable( $opts['cursor_of'] ) ) ? $opts['cursor_of'] : null;

		$result = Zdz_Request_Guard::guarded( $lock_key, function () use (
			$source, $work, $service, $batch_limit, $budget_secs, $cursor_key, $cursor_of
		) {
			Zdz_Request_Guard::open( array(
				'budget_seconds' => $budget_secs,
				'service'        => $service,
				'label'          => 'sweep:' . $service,
			) );

			$rows = call_user_func( $source, $batch_limit );
			if ( ! is_array( $rows ) ) {
				$rows = array();
			}
			$total = count( $rows );

			$processed = 0;
			$last_cursor = null;
			$stopped_early = false;

			foreach ( $rows as $row ) {
				// Reserve the next call's projected cost, and short-circuit a sick
				// upstream — BEFORE doing the work, never after (measure-after
				// overshoots by one full timeout).
				if ( ! Zdz_Request_Guard::can_afford( null )
					|| ( '' !== $service && Zdz_Service_Breaker::is_open( $service ) ) ) {
					$stopped_early = true;
					break; // leave the rest untouched — non-recording
				}

				try {
					$t = microtime( true );
					call_user_func( $work, $row );
					Zdz_Request_Guard::observe( $service, microtime( true ) - $t );
				} catch ( \Zdz_Budget_Deferred $e ) {
					// A deeper chokepoint refused the next call. Non-recording:
					// stop here, leave this row and the rest for the next tick.
					$stopped_early = true;
					break;
				}

				$processed++;
				if ( $cursor_of ) {
					$last_cursor = call_user_func( $cursor_of, $row );
				}
			}

			// Persist a cursor on BOTH stop paths (early defer or natural end),
			// when the caller opted into explicit cursoring.
			if ( '' !== $cursor_key && null !== $last_cursor ) {
				update_option( $cursor_key, $last_cursor, false );
			}

			$deferred = max( 0, $total - $processed );
			$elapsed  = Zdz_Request_Guard::elapsed();
			$budget   = Zdz_Request_Guard::remaining() + $elapsed;

			// Log a starved/partial pass so a chronically under-budgeted sweep is
			// visible rather than silently never draining.
			if ( $stopped_early || $deferred > 0 ) {
				self::log_partial( $service, $processed, $deferred, $total, $elapsed, $budget );
			}

			Zdz_Request_Guard::close();

			return array(
				'processed' => $processed,
				'deferred'  => $deferred,
				'elapsed'   => $elapsed,
				'budget'    => $budget,
			);
		} );

		if ( null === $result ) {
			// Lock held by a live worker — never an error, just a no-op tick.
			return array( 'processed' => 0, 'deferred' => 0, 'skipped_locked' => true );
		}

		return $result;
	}

	private static function log_partial( $service, $processed, $deferred, $total, $elapsed, $budget ) {
		$detail = sprintf(
			'processed=%d deferred=%d of=%d elapsed=%.2fs budget=%.2fs%s',
			$processed, $deferred, $total, $elapsed, $budget,
			( 0 === $processed && $total > 0 ) ? ' STARVED (zero rows processed)' : ''
		);
		if ( function_exists( 'do_action' ) ) {
			do_action( 'zdz_flow_disposition', 'sweep', 'partial_pass', array(
				'service'   => (string) $service,
				'processed' => (int) $processed,
				'deferred'  => (int) $deferred,
			) );
		}
		error_log( '[Zdz_Sweep] service=' . (string) $service . ' ' . $detail );
	}
}

endif;
