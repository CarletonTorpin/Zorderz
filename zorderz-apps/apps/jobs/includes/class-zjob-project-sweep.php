<?php
/**
 * Zjob_Project_Sweep — the budgeted background that makes "every estimate is a Project" TRUE.
 *
 * Two passes on one 15-minute cron, each wrapped in the Core Zdz_Sweep (wall-clock + row budget,
 * a per-service breaker, a global lock so workers do not pile up):
 *
 *   1) MINT pass — drain `Zjob_Project::unminted_estimate_ids()` (id >= floor, NOT EXISTS a ref)
 *      through `ensure_for_estimate()`. A minted estimate gains a ref and LEAVES the candidate
 *      set, so the batch limit bounds CANDIDATES, not scanned rows — the sweep never starves.
 *
 *   2) SIGNAL pass — recompute the cached ranking signal for projects whose facet is MISSING
 *      (freshly minted) or STALE (time-decaying terms like "recent"/"stalled" move with the
 *      clock even with no event). A refreshed project's signal_at becomes now and leaves the
 *      candidate set — same anti-starvation shape.
 *
 * THE SWEEP NEVER RETIRES ITSELF. The 1.22 "one-shot plus a guard option" is exactly how Projects
 * froze for six releases; this reschedules unconditionally and self-heals a missing event on boot.
 * A starved/partial pass is LOGGED by Zdz_Sweep, so a chronically under-budgeted sweep is visible
 * rather than silently never draining.
 *
 * @package Zorderz\Jobs
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Zjob_Project_Sweep' ) ) {

	class Zjob_Project_Sweep {

		/** The recurring cron hook. */
		const HOOK = 'zjob_project_sweep';

		/** Custom schedule id (registered below; ~15 minutes). */
		const SCHEDULE = 'zjob_15min';

		/** A project's signal is recomputed at least this often (seconds) even absent an event. */
		const SIGNAL_TTL = 21600; // 6h — bounds how stale a time-decaying term can get.

		/**
		 * Register the schedule, the cron callback, and (idempotently) the event itself. Also seeds
		 * the estimate floor once. Safe to call on every boot — a present event is left as-is.
		 */
		public static function init(): void {
			if ( ! function_exists( 'add_filter' ) ) {
				return;
			}
			add_filter( 'cron_schedules', array( __CLASS__, 'register_schedule' ) );
			add_action( self::HOOK, array( __CLASS__, 'run' ) );

			// Seed the floor once (cheap; idempotent) so candidacy is well-defined from first boot.
			if ( class_exists( 'Zjob_Project' ) && method_exists( 'Zjob_Project', 'seed_estimate_floor' ) ) {
				Zjob_Project::seed_estimate_floor();
			}

			// Self-heal: schedule the recurring event if it is not already scheduled. NEVER unscheduled.
			if ( function_exists( 'wp_next_scheduled' ) && ! wp_next_scheduled( self::HOOK ) ) {
				if ( function_exists( 'wp_schedule_event' ) ) {
					wp_schedule_event( time() + 300, self::SCHEDULE, self::HOOK );
				}
			}
		}

		/**
		 * Add a ~15-minute schedule. Merges; never clobbers an existing entry of the same id.
		 *
		 * @param array $schedules
		 * @return array
		 */
		public static function register_schedule( $schedules ): array {
			$schedules = is_array( $schedules ) ? $schedules : array();
			if ( ! isset( $schedules[ self::SCHEDULE ] ) ) {
				$schedules[ self::SCHEDULE ] = array(
					'interval' => 15 * 60,
					'display'  => 'Every 15 minutes (Zorderz projects)',
				);
			}
			return $schedules;
		}

		/**
		 * The cron callback: run the mint pass, then the signal pass. Each is independently budgeted
		 * and independently locked, so a slow pass cannot starve the other of its whole tick.
		 */
		public static function run(): void {
			if ( ! class_exists( 'Zdz_Sweep' ) || ! class_exists( 'Zjob_Project' ) ) {
				return; // the Core sweep helper (Wave A) is a hard dependency.
			}
			self::run_mint_pass();
			self::run_signal_pass();
		}

		/** Drain unminted estimates into Projects. */
		private static function run_mint_pass(): void {
			Zdz_Sweep::run(
				'zjob_project_mint',
				static function ( $limit ) {
					return Zjob_Project::unminted_estimate_ids( (int) $limit );
				},
				static function ( $estimate_id ) {
					// A throw here defers the row (Zdz_Sweep leaves it for the next tick); a plain
					// failure just yields '' and the estimate stays a candidate — either way it is
					// retried, never silently dropped.
					Zjob_Project::ensure_for_estimate( (int) $estimate_id );
				},
				array(
					'service'     => 'zjob_project_mint',
					'batch_limit' => 40,
				)
			);
		}

		/** Recompute missing / stale project signals. */
		private static function run_signal_pass(): void {
			Zdz_Sweep::run(
				'zjob_project_signal',
				static function ( $limit ) {
					return self::stale_signal_project_ids( (int) $limit );
				},
				static function ( $project_id ) {
					Zjob_Project::refresh_signal( (string) $project_id );
				},
				array(
					'service'     => 'zjob_project_signal',
					'batch_limit' => 40,
				)
			);
		}

		/**
		 * Project ids whose cached signal is MISSING or older than SIGNAL_TTL. A refreshed project
		 * stamps signal_at = now and leaves this set (bound candidates, not rows). Oldest first.
		 *
		 * @param int $limit
		 * @return string[] project (work_item) ids.
		 */
		public static function stale_signal_project_ids( int $limit ): array {
			global $wpdb;
			$limit = max( 1, min( 500, (int) $limit ) );
			if ( ! isset( $wpdb ) || ! class_exists( 'Zdz_Flow_DB' ) || ! class_exists( 'Zjob_Project_Signal' ) ) {
				return array();
			}
			$wi     = Zdz_Flow_DB::work_items();
			$sig    = Zjob_Project_Signal::table();
			$tenant = Zdz_Flow_DB::tenant_id();
			$cutoff = gmdate( 'Y-m-d H:i:s', time() - self::SIGNAL_TTL );

			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT wi.id FROM {$wi} wi
					 LEFT JOIN {$sig} sig ON sig.project_id = wi.id
					 WHERE wi.work_type = %s AND wi.tenant_id = %d
					   AND ( sig.project_id IS NULL OR sig.signal_at IS NULL OR sig.signal_at < %s )
					 ORDER BY ( sig.signal_at IS NULL ) DESC, sig.signal_at ASC
					 LIMIT %d",
					Zjob_Project::WORK_TYPE,
					$tenant,
					$cutoff,
					$limit
				)
			);
			return array_map( 'strval', (array) $ids );
		}
	}
}
