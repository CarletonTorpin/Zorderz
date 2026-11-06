<?php
/**
 * Zjob_Install_Date — the SINGLE scheduling authority for a Project's install date, and the
 * PUBLISHED cross-plugin resolver boundary that Prep and the Scheduler consume.
 *
 * WHY ONE AUTHORITY. Three readers once tested the schedule columns themselves and disagreed —
 * "that is how there came to be three answers." Every consumer now calls this class; nobody re-reads
 * `scheduled_appt_id` / `scheduled_start_utc` on their own.
 *
 * THE THREE-STATE CONTRACT (never conflated). Every answer carries a coarse machine `state` and a
 * finer paper `status`. The `state` is the epistemic trichotomy other apps branch on:
 *
 *   STATE_KNOWN          a real, valid install date is present.               (date is a Y-m-d)
 *   STATE_UNKNOWN        no date is known, but one could still exist.         (date is null)
 *   STATE_NOT_APPLICABLE an install date is not a meaningful outcome here.    (date is null)
 *
 * The `status` REFINES that trichotomy so the answer reaches paper DISTINCTLY — an
 * "unscheduled" job and an "unknown" lead must not print the same:
 *
 *   status        → state              meaning
 *   scheduled     → known             a live appointment with a non-zero datetime.
 *   unscheduled   → unknown           a live component awaiting a date (we do not know it YET).
 *   unknown       → unknown           indeterminate: no project, gated out, or nothing linked.
 *   not_applicable→ not_applicable    every component is dead/cancelled — a date will never apply.
 *
 * The status → state mapping is TOTAL and enforced (state_for_status): a real date may ONLY ride STATE_KNOWN, and
 * the '0000-00-00 00:00:00' sentinel is guarded at the source so a zeroed column never reads as a
 * date. "unknown" is never conflated with a real date or with 0000-00-00.
 *
 * A DATE IS DISPLAY-ONLY. It gates nothing (approval is the authority to cut). There is no overdue /
 * late / fault token: a missing date is deliberately NOT a fault, and printInstallText() (paper_line)
 * never returns empty — a blank field reads as "nobody filled this in".
 *
 * NO MONEY, EVER. The envelope returns a date and a status — never a figure. These answers ride onto
 * cut sheets and (via the Scheduler write-back) personal calendars; a money value must never travel
 * with them. This is a hard geo-PII rule and is asserted recursively by the harness.
 *
 * THE PUBLISHED BOUNDARY. `for_leads()` and `for_estimate_numbers()` are the two — and only
 * two — methods Prep/Scheduler call. Each runs two arms:
 *
 *   1. the JOB ROWS: every job carrying the lead (as the parent lead OR the child lead a hand-off made)
 *      or the estimate. EACH ROW passes ZJOB_Scope::can_see() for the viewer or contributes nothing —
 *      not a date and not to job_count. If the scope class is missing, every row fails CLOSED.
 *   2. the PROJECT, for anything a job row did not schedule: lead / estimate → Project (ref map) →
 *      its own jobs (gated per row again) → its own linked appointment. The Project itself must pass
 *      the Projects visibility rule. A missing project and a forbidden viewer return the SAME unknown
 *      envelope (existence-oracle-safe). This arm only FILLS: it never overwrites a scheduled answer.
 *
 * Every requested key is present in the answer, so a caller never handles a missing key. Neither arm
 * exposes a jobs table: Prep names none.
 *
 * AUTHORITY. This resolver returns only CONFIRMED dates (authority='confirmed') read off booked
 * appointments. The advisory paperwork parser (authority='inferred') is a SEPARATE service (S4); a
 * consumer that merges the two must never downgrade a scheduled answer to unknown, and never
 * overwrite a real appointment with an inferred guess (see merge_non_known()).
 *
 * Ships EMPTY: names no company/person/product/place/provider; seeds nothing; the CRM lead namespace
 * and every wording default resolve through filters with generic Core defaults.
 *
 * Promotion path: this reads only the Flow ref map + the jobs cache; it moves beside Flow unchanged.
 *
 * @package Zorderz\Jobs
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Zjob_Install_Date' ) ) {

	class Zjob_Install_Date {

		/** The three — and only three — epistemic states. Constants so a value is never typed twice. */
		const STATE_KNOWN          = 'known';
		const STATE_UNKNOWN        = 'unknown';
		const STATE_NOT_APPLICABLE = 'not_applicable';

		/** The paper-facing statuses (a refinement of state; honest output needs these to print distinctly). */
		const STATUS_SCHEDULED      = 'scheduled';
		const STATUS_UNSCHEDULED    = 'unscheduled';
		const STATUS_UNKNOWN        = 'unknown';
		const STATUS_NOT_APPLICABLE = 'not_applicable';

		/** Where a KNOWN date came from (precedence: job row wins over the project's own appointment). */
		const SOURCE_JOB_ROW      = 'job_row';
		const SOURCE_PROJECT_APPT = 'project_appointment';
		const SOURCE_NONE         = 'none';

		/** The provenance CLASS. This resolver only ever emits 'confirmed'; the S4 parser emits 'inferred'. */
		const AUTHORITY_CONFIRMED = 'confirmed';
		const AUTHORITY_NONE      = 'none';

		/** The zeroed-datetime sentinel a MySQL DATETIME can carry; guarded everywhere as "no time". */
		const ZERO_DATETIME = '0000-00-00 00:00:00';

		/** Job statuses that are not worked for and never carry a live date (excluded from the pick). */
		const DEAD_STATUSES = array( 'done', 'cancelled' );

		/**
		 * The columns every job-row read selects. created_by, assigned_user_id and sp_code are here
		 * because ZJOB_Scope::can_see() reads them off the row: selecting fewer makes the gate answer
		 * "no" for everyone, a permission boundary produced by a typo.
		 */
		const ROW_COLS = array(
			'id',
			'parent_lead_id',
			'crm_child_lead_id',
			'estimate_id',
			'created_by',
			'assigned_user_id',
			'sp_code',
			'status',
			'scheduled_appt_id',
			'scheduled_start_utc',
			'scheduled_end_utc',
			'scheduled_tz',
		);

		/** Per-request memo for the Project gate (flushable: a gate a test cannot vary is untestable). */
		private static $project_gate_memo = array();

		/** The Core-default CRM-lead ref system. A connections pack points this at its provider ns. */
		const LEAD_SYSTEM_DEFAULT = 'lead';

		/* ===================================================================
		 * INIT — the drift subscriber + the ongoing lead-ref reconcile. Nothing seeds.
		 * =================================================================== */

		/**
		 * Subscribe the Scheduler drift actions (zsch_appointment_updated / _deleted) and register the
		 * ongoing lead-ref reconcile on the projects sweep + a version-gated boot run. Idempotent.
		 */
		public static function init(): void {
			if ( ! function_exists( 'add_action' ) ) {
				return;
			}
			// Drift: a calendar edit/delete elsewhere must not leave the cached date lying. The Scheduler
			// fires both with the appointment id first (zsch_appointment_updated / _deleted).
			add_action( 'zsch_appointment_updated', array( __CLASS__, 'on_appointment_changed' ), 10, 1 );
			add_action( 'zsch_appointment_deleted', array( __CLASS__, 'on_appointment_deleted' ), 10, 1 );

			// The lead-ref reconcile is an ONGOING pass (a one-shot is how Projects froze for six
			// releases). It rides the existing 15-min projects sweep as a SECOND subscriber (no edit to
			// the sweep) and also runs once per code version on boot to catch up after a deploy. The
			// sweep hook name is the sweep's own constant when present, else the known default.
			$sweep_hook = class_exists( 'Zjob_Project_Sweep' ) ? (string) Zjob_Project_Sweep::HOOK : 'zjob_project_sweep';
			add_action( $sweep_hook, array( __CLASS__, 'reconcile_tick' ) );
			add_action( 'init', array( __CLASS__, 'maybe_boot_reconcile' ), 99 );
		}

		/** The same as init(): subscribe the drift subscriber and the reconcile sweep. */
		public static function boot(): void {
			self::init();
		}

		/* ===================================================================
		 * PICK — the selection algorithm over a set of child-job rows.
		 * =================================================================== */

		/**
		 * Resolve the install date for a set of child-job rows.
		 *
		 * "scheduled" = a live component (status not in DEAD_STATUSES) with a linked appointment id AND
		 * a non-zero datetime; EARLIEST wins. Dead components are excluded. The zeroed sentinel is
		 * guarded. When nothing is scheduled: a live component yields UNSCHEDULED (a date could still
		 * come), all-dead yields NOT_APPLICABLE, and no components at all yields UNKNOWN.
		 *
		 * @param array $jobs child-job rows (each an assoc array; may include dead ones — pick excludes them).
		 * @return array the full envelope (see envelope()).
		 */
		public static function pick( array $jobs ): array {
			$has_any  = false;
			$has_live = false;
			$best     = null; // array{ start_utc:string, job:array }
			$count    = 0;
			$seen     = array(); // distinct scheduled starts (spread)

			foreach ( $jobs as $job ) {
				if ( ! is_array( $job ) ) {
					continue;
				}
				$has_any = true;

				$status = strtolower( trim( (string) ( $job['status'] ?? '' ) ) );
				if ( in_array( $status, self::DEAD_STATUSES, true ) ) {
					continue; // dead components excluded (a cancelled job is not "unscheduled").
				}
				$has_live = true;
				$count++;

				$appt = (int) ( $job['scheduled_appt_id'] ?? 0 );
				$st   = trim( (string) ( $job['scheduled_start_utc'] ?? '' ) );
				if ( $appt <= 0 ) {
					continue; // a datetime with no appointment id is NOT a booking (the 1.31 fix).
				}
				if ( '' === $st || self::ZERO_DATETIME === $st ) {
					continue; // guard the zeroed sentinel — never a date.
				}
				$seen[ $st ] = true;
				// EARLIEST wins: the first visit is the deadline, the later ones are slack.
				if ( null === $best || $st < $best['start_utc'] ) {
					$best = array( 'start_utc' => $st, 'job' => $job );
				}
			}

			if ( null !== $best ) {
				$tz    = trim( (string) ( $best['job']['scheduled_tz'] ?? '' ) );
				$tz    = ( '' !== $tz ) ? $tz : self::default_tz();
				$parts = self::local_parts( $best['start_utc'], $tz );
				return self::envelope(
					self::STATUS_SCHEDULED,
					array(
						'date'             => $parts['date'],
						'time'             => $parts['time'],
						'start_utc'        => $best['start_utc'],
						'tz'               => $tz,
						'assignee_user_id' => (int) ( $best['job']['assigned_user_id'] ?? 0 ),
						'source'           => self::SOURCE_JOB_ROW,
						'confidence'       => 'confirmed',
						'authority'        => self::AUTHORITY_CONFIRMED,
						'basis'            => 'job_row_appointment',
						'job_id'           => (int) ( $best['job']['id'] ?? 0 ),
						'appt_id'          => (int) ( $best['job']['scheduled_appt_id'] ?? 0 ),
						'job_count'        => $count,
						// Several dates across the visible jobs: shown as "+1 more", never as a warning.
						'spread'           => count( $seen ) > 1,
					)
				);
			}

			if ( $has_live ) {
				return self::envelope( self::STATUS_UNSCHEDULED, array( 'basis' => 'live_component_no_appointment', 'job_count' => $count ) );
			}
			if ( $has_any ) {
				return self::envelope( self::STATUS_NOT_APPLICABLE, array( 'basis' => 'all_components_dead' ) );
			}
			return self::envelope( self::STATUS_UNKNOWN, array( 'basis' => 'no_components' ) );
		}

		/* ===================================================================
		 * PER-PROJECT resolution (the precedence rule) — used by the published arms.
		 * =================================================================== */

		/**
		 * Resolve one Project's install date for a viewer, GATED and fail-closed.
		 *
		 * Precedence (a [CORE] rule with tests): a JOB ROW always wins; only if no job row yields a
		 * known date does the Project's OWN linked appointment fill in. Neither known → the stronger of
		 * the two non-known answers (never a downgrade).
		 *
		 * Existence oracle: a missing project and a forbidden viewer both return the SAME unknown
		 * envelope (basis 'unresolved') — two responses cannot enumerate ids.
		 *
		 * @param string $project_id the project work_item id.
		 * @param int    $viewer     WP user id.
		 * @return array the full envelope.
		 */
		public static function for_project( string $project_id, int $viewer ): array {
			$project_id = (string) $project_id;
			if ( '' === $project_id || ! class_exists( 'Zjob_Project' ) ) {
				return self::envelope( self::STATUS_UNKNOWN, array( 'basis' => 'unresolved' ) );
			}
			$project = Zjob_Project::get( $project_id );
			if ( ! is_array( $project ) || ! self::actor_may_use( $viewer, $project ) ) {
				// Missing OR forbidden — one indistinguishable answer (oracle stays closed).
				return self::envelope( self::STATUS_UNKNOWN, array( 'basis' => 'unresolved' ) );
			}

			// Arm 1 — a job row always wins; each row the viewer may not see contributes nothing.
			$picked = self::pick( self::visible_rows( $viewer, Zjob_Project::jobs_for( $project_id ) ) );
			if ( self::STATE_KNOWN === $picked['state'] ) {
				return $picked;
			}

			// Arm 2 (fill_from_projects) — the Project's own linked appointment only FILLS.
			$filled = self::fill_from_project_appointment( $project_id );
			if ( self::STATE_KNOWN === $filled['state'] ) {
				return $filled;
			}

			// Neither known: keep the more informative answer; never downgrade.
			return self::merge_non_known( $picked, $filled );
		}

		/**
		 * The Project's OWN linked appointment (the reverse-link fill). Reads the one `appointment` ref,
		 * re-reads the live appointment, and returns a KNOWN date only for a live, non-zero booking.
		 *
		 * @param string $project_id
		 * @return array
		 */
		private static function fill_from_project_appointment( string $project_id ): array {
			if ( ! class_exists( 'Zjob_Appointment_Link' ) ) {
				return self::envelope( self::STATUS_UNKNOWN, array( 'basis' => 'no_link_engine' ) );
			}
			$appt_id = Zjob_Appointment_Link::appointment_for( $project_id );
			if ( $appt_id <= 0 ) {
				return self::envelope( self::STATUS_UNKNOWN, array( 'basis' => 'no_project_appointment' ) );
			}
			if ( ! class_exists( 'ZSCH_Appointments' ) || ! is_callable( array( 'ZSCH_Appointments', 'get_raw' ) ) ) {
				return self::envelope( self::STATUS_UNKNOWN, array( 'basis' => 'scheduler_unavailable' ) );
			}
			$raw = ZSCH_Appointments::get_raw( $appt_id );
			if ( ! is_array( $raw ) ) {
				return self::envelope( self::STATUS_UNSCHEDULED, array( 'basis' => 'project_appointment_missing' ) );
			}
			// A deleted / cancelled appointment is not a known date (do not answer "known" forever).
			if ( ! empty( $raw['deleted_at'] ) || 'cancelled' === strtolower( (string) ( $raw['status'] ?? '' ) ) ) {
				return self::envelope( self::STATUS_UNSCHEDULED, array( 'basis' => 'project_appointment_cancelled' ) );
			}
			$st = trim( (string) ( $raw['start_utc'] ?? '' ) );
			if ( '' === $st || self::ZERO_DATETIME === $st ) {
				return self::envelope( self::STATUS_UNSCHEDULED, array( 'basis' => 'project_appointment_no_time' ) );
			}
			$tz    = trim( (string) ( $raw['time_zone'] ?? '' ) );
			$tz    = ( '' !== $tz ) ? $tz : self::default_tz();
			$parts = self::local_parts( $st, $tz );
			return self::envelope(
				self::STATUS_SCHEDULED,
				array(
					'date'             => $parts['date'],
					'time'             => $parts['time'],
					'start_utc'        => $st,
					'tz'               => $tz,
					'assignee_user_id' => (int) ( $raw['owner_user_id'] ?? 0 ),
					'source'           => self::SOURCE_PROJECT_APPT,
					'confidence'       => 'confirmed',
					'authority'        => self::AUTHORITY_CONFIRMED,
					'basis'            => 'project_appointment',
					'appt_id'          => $appt_id,
				)
			);
		}

		/* ===================================================================
		 * The two PUBLISHED arms Prep and the Scheduler consume.
		 * =================================================================== */

		/**
		 * Resolve install dates for a batch of CRM lead ids — the key for anything that found its job
		 * through the CRM. BOTH lead columns are matched: one estimate becomes several jobs, and a
		 * hand-off makes a CRM child lead per job, so the lead a caller looked up may be the parent the
		 * estimate came from or a child a job was created against.
		 *
		 * Arm 1: the job rows, each gated (ZJOB_Scope::can_see, fail-closed). Arm 2: the Project the
		 * lead ref points at (the lead ref system is `lead` unless a connections pack points
		 * `zdz_project_lead_system` at its provider namespace), only for a lead arm 1 did not schedule.
		 *
		 * @param array $lead_ids external CRM lead ids.
		 * @param int   $viewer   WP user id.
		 * @return array<string,array> lead_id => envelope; every requested id is present.
		 */
		public static function for_leads( array $lead_ids, int $viewer ): array {
			$ids = self::clean_ids( $lead_ids );
			$out = array();
			foreach ( $ids as $id ) {
				$out[ (string) $id ] = self::envelope( self::STATUS_UNKNOWN, array( 'basis' => 'unresolved' ) );
			}
			if ( empty( $ids ) || $viewer <= 0 ) {
				return $out;
			}

			// Arm 1 — the job rows.
			$buckets = array_fill_keys( array_map( 'strval', $ids ), array() );
			foreach ( self::visible_rows( $viewer, self::rows_for_leads( $ids ) ) as $r ) {
				// One row can answer for more than one requested lead (a parent and its child).
				$p = (string) (int) ( $r['parent_lead_id'] ?? 0 );
				$c = (string) (int) ( $r['crm_child_lead_id'] ?? 0 );
				if ( '0' !== $p && isset( $buckets[ $p ] ) ) {
					$buckets[ $p ][] = $r;
				}
				if ( '0' !== $c && $c !== $p && isset( $buckets[ $c ] ) ) {
					$buckets[ $c ][] = $r;
				}
			}
			foreach ( $buckets as $key => $rows ) {
				if ( $rows ) {
					$out[ $key ] = self::pick( $rows );
				}
			}

			// Arm 2 — the Project, for what a job row did not schedule.
			$system = self::lead_system();
			foreach ( $out as $key => $env ) {
				$out[ $key ] = self::fill_from_project( $env, self::project_for( $system, (string) $key ), $viewer );
			}
			return $out;
		}

		/** Single-lead convenience. Wraps the batch; never a second query shape. */
		public static function for_lead( int $lead_id, int $viewer ): array {
			$all = self::for_leads( array( $lead_id ), $viewer );
			return $all[ (string) $lead_id ] ?? self::envelope( self::STATUS_UNKNOWN, array( 'basis' => 'unresolved' ) );
		}

		/**
		 * Resolve install dates for a batch of estimate NUMBERS — for a card that has no lead id. Each
		 * number is the human document number, disambiguated to the estimate app's own id through the
		 * `zdz_estimate_id_for_number` seam (estimate #12 and invoice #12 are different records). Arm 1:
		 * the jobs made from that estimate, gated per row. Arm 2: the Project holding the estimate.
		 *
		 * @param array $est_nums estimate document numbers.
		 * @param int   $viewer   WP user id.
		 * @return array<string,array> estimate_number => envelope; every requested number is present.
		 */
		public static function for_estimate_numbers( array $est_nums, int $viewer ): array {
			$nums = array();
			foreach ( $est_nums as $n ) {
				$n = trim( (string) $n );
				if ( '' !== $n ) {
					$nums[ $n ] = true;
				}
			}
			$out = array();
			foreach ( array_keys( $nums ) as $n ) {
				$out[ (string) $n ] = self::envelope( self::STATUS_UNKNOWN, array( 'basis' => 'unresolved' ) );
			}
			if ( empty( $out ) || $viewer <= 0 ) {
				return $out;
			}
			foreach ( array_keys( $out ) as $key ) {
				$eid = self::estimate_id_for_number( (string) $key );
				if ( $eid <= 0 ) {
					continue;
				}
				$rows = self::visible_rows( $viewer, self::rows_for_estimate( $eid ) );
				if ( $rows ) {
					$out[ $key ] = self::pick( $rows );
				}
				$out[ $key ] = self::fill_from_project( $out[ $key ], self::project_for( 'estimate', (string) $eid ), $viewer );
			}
			return $out;
		}

		/**
		 * The Project arm for one key: only when the job-row answer is not already scheduled, and only
		 * for a Project the viewer may use. Keeps the more informative answer; never a downgrade.
		 */
		private static function fill_from_project( array $env, string $project_id, int $viewer ): array {
			if ( self::STATE_KNOWN === ( $env['state'] ?? '' ) || '' === $project_id ) {
				return $env; // a job row already answered: it wins.
			}
			$proj = self::for_project_memo( $project_id, $viewer );
			if ( self::STATE_KNOWN === ( $proj['state'] ?? '' ) ) {
				// Keep "we know of N jobs" from the job-side answer: a truer sentence than "nothing".
				$proj['job_count'] = max( (int) ( $proj['job_count'] ?? 0 ), (int) ( $env['job_count'] ?? 0 ) );
				return $proj;
			}
			return self::merge_non_known( $env, $proj );
		}

		/** for_project(), memoised per request (a batch can name one Project many times). */
		private static function for_project_memo( string $project_id, int $viewer ): array {
			$k = $viewer . ':' . $project_id;
			if ( ! isset( self::$project_gate_memo[ $k ] ) ) {
				self::$project_gate_memo[ $k ] = self::for_project( $project_id, $viewer );
			}
			return self::$project_gate_memo[ $k ];
		}

		/** Drop the per-request memo (tests; anything that changes scope mid-request). */
		public static function flush_memo(): void {
			self::$project_gate_memo = array();
		}

		/**
		 * The rows this viewer may see, ONE BY ONE (ZJOB_Scope::can_see). Fails CLOSED: with no scope
		 * class every row is dropped, because "we could not check" must never read as "allowed".
		 *
		 * @param int   $viewer
		 * @param array $rows job rows (each carrying created_by, assigned_user_id, sp_code).
		 * @return array the visible rows, in order.
		 */
		public static function visible_rows( int $viewer, array $rows ): array {
			if ( $viewer <= 0 || ! class_exists( 'ZJOB_Scope' ) || ! method_exists( 'ZJOB_Scope', 'can_see' ) ) {
				return array();
			}
			$out = array();
			foreach ( $rows as $r ) {
				if ( is_array( $r ) && ZJOB_Scope::can_see( $viewer, $r ) ) {
					$out[] = $r;
				}
			}
			return $out;
		}

		/** Job rows carrying any of these leads (parent or child), every status (pick() drops the dead). */
		private static function rows_for_leads( array $ids ): array {
			global $wpdb;
			if ( empty( $ids ) || ! isset( $wpdb ) || ! class_exists( 'ZJOB_DB' ) ) {
				return array();
			}
			$in    = implode( ',', array_map( 'intval', $ids ) ); // ints only (clean_ids)
			$table = ZJOB_DB::table();
			$cols  = implode( ', ', self::ROW_COLS );
			$rows  = $wpdb->get_results(
				"SELECT {$cols} FROM {$table} WHERE parent_lead_id IN ({$in}) OR crm_child_lead_id IN ({$in}) LIMIT 2000", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed table and column names (ZJOB_DB::table(), self::ROW_COLS); the IN list is ints only (clean_ids, intval).
				ARRAY_A
			);
			return is_array( $rows ) ? $rows : array();
		}

		/** Job rows made from one estimate (the estimate app's own id), every status. */
		private static function rows_for_estimate( int $estimate_id ): array {
			global $wpdb;
			if ( $estimate_id <= 0 || ! isset( $wpdb ) || ! class_exists( 'ZJOB_DB' ) ) {
				return array();
			}
			$table = ZJOB_DB::table();
			$cols  = implode( ', ', self::ROW_COLS );
			$rows  = $wpdb->get_results(
				$wpdb->prepare( "SELECT {$cols} FROM {$table} WHERE estimate_id = %d LIMIT 500", $estimate_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed table and column names (ZJOB_DB::table(), self::ROW_COLS); the id is a placeholder.
				ARRAY_A
			);
			return is_array( $rows ) ? $rows : array();
		}

		/** Positive unique ints only: what makes the IN clause safe. */
		private static function clean_ids( array $ids ): array {
			$out = array();
			foreach ( $ids as $id ) {
				$id = (int) $id;
				if ( $id > 0 ) {
					$out[ $id ] = true;
				}
			}
			return array_keys( $out );
		}

		/* ===================================================================
		 * PAPER — printInstallText()-equivalent. Never empty; no fault token.
		 * =================================================================== */

		/**
		 * A non-empty display line for a resolved envelope. The three non-scheduled states print
		 * DISTINCTLY. Wording is [IDENTITY→document-conventions] via the
		 * `zjob_install_date_paper_line` filter; the Core defaults are generic English (no company /
		 * person / product / place / provider name) and carry NO overdue/late/fault token — a missing
		 * date is not a fault.
		 *
		 * @param array $result an envelope from pick()/for_project()/for_leads()/for_estimate_numbers().
		 * @return string never empty.
		 */
		public static function paper_line( array $result ): string {
			$status = (string) ( $result['status'] ?? self::STATUS_UNKNOWN );
			if ( self::STATUS_SCHEDULED === $status && ! empty( $result['date'] ) ) {
				$default = (string) $result['date']; // ISO date; a tenant formats it via the filter.
			} else {
				$map     = array(
					self::STATUS_UNSCHEDULED    => 'Unscheduled',
					self::STATUS_UNKNOWN        => 'Unknown',
					self::STATUS_NOT_APPLICABLE => 'Not applicable',
				);
				$default = $map[ $status ] ?? 'Unknown';
			}
			$line = function_exists( 'apply_filters' )
				? (string) apply_filters( 'zjob_install_date_paper_line', $default, $result )
				: $default;
			$line = trim( $line );
			return '' !== $line ? $line : $default; // never empty: a blank reads as "nobody filled this in".
		}

		/** Convenience: does this envelope carry a real, known install date? */
		public static function is_scheduled( array $result ): bool {
			return self::STATE_KNOWN === ( $result['state'] ?? '' );
		}

		/* ===================================================================
		 * STATUS → STATE MAP — total; state and status never conflate.
		 * =================================================================== */

		/**
		 * The one place status → state is decided. Total: every status maps to exactly one state, and a
		 * real date may only ride STATE_KNOWN.
		 *
		 * @param string $status
		 * @return string one of the STATE_* constants.
		 */
		public static function state_for_status( string $status ): string {
			switch ( $status ) {
				case self::STATUS_SCHEDULED:
					return self::STATE_KNOWN;
				case self::STATUS_NOT_APPLICABLE:
					return self::STATE_NOT_APPLICABLE;
				case self::STATUS_UNSCHEDULED:
				case self::STATUS_UNKNOWN:
				default:
					return self::STATE_UNKNOWN;
			}
		}

		/* ===================================================================
		 * DRIFT — keep the cached date honest against a calendar edit elsewhere.
		 * =================================================================== */

		/**
		 * A linked appointment CHANGED elsewhere (moved in the Scheduler, or reconciled from an external
		 * calendar). Refresh every job row cached against it.
		 *
		 * Deliberately ignores the hook payload and re-reads the appointment: the Scheduler's call sites
		 * pass differently shaped data, and a subscriber that trusts one shape writes a wrong date the
		 * first time the other fires. get_raw() is authoritative; this is a write path, not a render.
		 *
		 * Only rows whose cached start, end or time zone differ are written, and a history line
		 * (`job_schedule_synced`) is recorded only for a row whose START moved: the hook also fires from
		 * the periodic calendar pull, and a line per tick would bury the history in noise. A gone,
		 * deleted or cancelled appointment is treated as a delete. `zjob_schedule_drifted` fires once
		 * when any start moved.
		 *
		 * @param int $appt_id
		 */
		public static function on_appointment_changed( $appt_id ): void {
			$appt_id = (int) $appt_id;
			if ( $appt_id <= 0 || ! class_exists( 'ZSCH_Appointments' ) || ! is_callable( array( 'ZSCH_Appointments', 'get_raw' ) ) ) {
				return;
			}
			try {
				$raw = ZSCH_Appointments::get_raw( $appt_id );
				if ( ! is_array( $raw ) || ! empty( $raw['deleted_at'] ) || 'cancelled' === strtolower( (string) ( $raw['status'] ?? '' ) ) ) {
					self::on_appointment_deleted( $appt_id );
					return;
				}
				global $wpdb;
				if ( ! isset( $wpdb ) || ! class_exists( 'ZJOB_DB' ) ) {
					return;
				}
				$table = ZJOB_DB::table();
				$rows  = $wpdb->get_results(
					$wpdb->prepare( "SELECT id, scheduled_start_utc, scheduled_end_utc, scheduled_tz FROM {$table} WHERE scheduled_appt_id = %d", $appt_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is $wpdb->prefix plus a fixed name; the id is a placeholder.
					ARRAY_A
				);
				$plan  = self::drift_plan( is_array( $rows ) ? $rows : array(), $raw );
				$moved = false;
				$now   = self::now();
				foreach ( $plan as $u ) {
					$wpdb->update(
						$table,
						array(
							'scheduled_start_utc' => $u['start'],
							'scheduled_end_utc'   => $u['end'],
							'scheduled_tz'        => $u['tz'],
							'updated_at'          => $now,
						),
						array( 'id' => $u['id'] ),
						array( '%s', '%s', '%s', '%s' ),
						array( '%d' )
					);
					if ( $u['moved'] ) {
						$moved = true;
						self::history(
							$u['id'],
							'job_schedule_synced',
							'The appointment moved in the Scheduler. The job\'s time was updated to match.',
							array( 'from' => $u['from'], 'to' => (string) $u['start'], 'appt_id' => $appt_id, 'via' => 'scheduler-hook' )
						);
					}
				}
				if ( $moved && function_exists( 'do_action' ) ) {
					do_action( 'zjob_schedule_drifted', $appt_id, (string) ( $plan[0]['start'] ?? '' ) );
				}
			} catch ( \Throwable $e ) {
				error_log( 'Zorderz Jobs: install-date sync failed for appointment ' . $appt_id . '.' );
			}
		}

		/** The same subscriber under its earlier name. */
		public static function on_appointment_updated( $appt_id ): void {
			self::on_appointment_changed( $appt_id );
		}

		/**
		 * PURE: which cached rows to write after an appointment changed, and which of them moved.
		 * A row is written only when its start, end or time zone differs from the appointment; it is
		 * "moved" only when its start differs. A zeroed or empty time is stored as NULL, never as a date.
		 *
		 * @param array $rows rows of { id, scheduled_start_utc, scheduled_end_utc, scheduled_tz }.
		 * @param array $raw  the appointment as the Scheduler stores it (start_utc, end_utc, time_zone).
		 * @return array<int,array{id:int,start:?string,end:?string,tz:string,moved:bool,from:string}>
		 */
		public static function drift_plan( array $rows, array $raw ): array {
			$clean = static function ( $v ): ?string {
				$v = trim( (string) $v );
				return ( '' === $v || self::ZERO_DATETIME === $v ) ? null : $v;
			};
			$start = $clean( $raw['start_utc'] ?? '' );
			$end   = $clean( $raw['end_utc'] ?? '' );
			$tz    = (string) ( $raw['time_zone'] ?? '' );
			$out   = array();
			foreach ( $rows as $r ) {
				$id = (int) ( $r['id'] ?? 0 );
				if ( $id <= 0 ) {
					continue;
				}
				$was_start = $clean( $r['scheduled_start_utc'] ?? '' );
				$was_end   = $clean( $r['scheduled_end_utc'] ?? '' );
				$was_tz    = (string) ( $r['scheduled_tz'] ?? '' );
				$moved     = $was_start !== $start;
				if ( ! $moved && $was_end === $end && $was_tz === $tz ) {
					continue; // nothing changed for this row: no write, no history line.
				}
				$out[] = array(
					'id'    => $id,
					'start' => $start,
					'end'   => $end,
					'tz'    => $tz,
					'moved' => $moved,
					'from'  => (string) $was_start,
				);
			}
			return $out;
		}

		/**
		 * A linked appointment was DELETED elsewhere. Break the link on every job that cached it (else
		 * is_scheduled() answers true forever for an appointment that no longer exists), record why on
		 * each job, and FORGET the Project's appointment ref (else the unique index blocks re-use of that
		 * appointment id). A system reaction to an authoritative event, so no permission gate.
		 *
		 * @param int $appt_id
		 */
		public static function on_appointment_deleted( $appt_id ): void {
			$appt_id = (int) $appt_id;
			if ( $appt_id <= 0 ) {
				return;
			}
			global $wpdb;
			try {
				if ( isset( $wpdb ) && class_exists( 'ZJOB_DB' ) ) {
					$table = ZJOB_DB::table();
					$ids   = (array) $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE scheduled_appt_id = %d", $appt_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is $wpdb->prefix plus a fixed name; the id is a placeholder.
					foreach ( $ids as $jid ) {
						$wpdb->update(
							$table,
							array(
								'scheduled_appt_id'   => 0,
								'scheduled_start_utc' => null,
								'scheduled_end_utc'   => null,
								'scheduled_tz'        => '',
								'scheduled_by'        => 0,
								'scheduled_at'        => null,
								'updated_at'          => self::now(),
							),
							array( 'id' => (int) $jid ),
							array( '%d', '%s', '%s', '%s', '%d', '%s', '%s' ),
							array( '%d' )
						);
						self::history(
							(int) $jid,
							'job_schedule_cleared',
							'The appointment was deleted in the Scheduler. This job is waiting for a time again.',
							array( 'appt_id' => $appt_id, 'via' => 'scheduler-hook' )
						);
					}
				}
				// Drop the Project's appointment ref so the calendar and the container agree again.
				if ( class_exists( 'Zdz_Flow_Refs' ) && class_exists( 'Zjob_Appointment_Link' ) ) {
					$pid = Zdz_Flow_Refs::get( Zjob_Appointment_Link::SYSTEM, Zjob_Appointment_Link::ENTITY, (string) $appt_id );
					if ( null !== $pid ) {
						Zjob_Appointment_Link::forget( (string) $pid, $appt_id );
					}
				}
			} catch ( \Throwable $e ) {
				error_log( 'Zorderz Jobs: install-date clear failed for appointment ' . $appt_id . '.' );
			}
		}

		/** One history line on a job (through the published job face, which also refreshes its Project). */
		private static function history( int $job_id, string $type, string $words, array $context ): void {
			if ( class_exists( 'ZJOB_Handoff' ) ) {
				ZJOB_Handoff::record_event( $job_id, $type, 0, $words, $context );
			}
		}

		/* ===================================================================
		 * RECONCILE — the ongoing lead-ref backfill (idempotent; converges).
		 * =================================================================== */

		/**
		 * WHY. An estimate-born Project is minted ~120 s after the estimate exists, while the CRM lead
		 * syncs on its own later clock, so the `lead` ref is routinely missing at mint time and Prep's
		 * lead arm (for_leads) cannot find the Project. This backfill reads the lead id back from the
		 * estimate (via the `zdz_estimate_lead_id` seam) and writes the ref. It is an ONGOING pass, not a
		 * one-shot (a one-shot is how Projects froze for six releases): a reconciled Project leaves the
		 * candidate set, and a Project whose estimate has no lead id yet is simply retried a later tick.
		 * A written ref is DATA, not schema — it survives a rollback.
		 *
		 * Bounded driver (used by the version-gated boot run and as the no-Sweep fallback); the sweep
		 * path uses reconcile_candidate_ids()/reconcile_one() directly so each row is budgeted.
		 *
		 * @param int $limit max candidates this pass.
		 * @return int number of refs written.
		 */
		public static function reconcile_lead_refs( int $limit = 100 ): int {
			$written = 0;
			foreach ( self::reconcile_candidate_ids( $limit ) as $pid ) {
				if ( self::reconcile_one( (string) $pid ) ) {
					$written++;
				}
			}
			return $written;
		}

		/**
		 * Project ids that own an estimate ref but no lead ref yet (the reconcile candidates). A
		 * reconciled Project stops matching and leaves the set — bound candidates, not scanned rows.
		 *
		 * @param int $limit
		 * @return string[] project (work_item) ids.
		 */
		public static function reconcile_candidate_ids( int $limit ): array {
			global $wpdb;
			if ( ! isset( $wpdb ) || ! class_exists( 'Zdz_Flow_DB' ) ) {
				return array();
			}
			$limit  = max( 1, min( 500, $limit ) );
			$system = self::lead_system();
			if ( '' === self::lead_entity( $system ) ) {
				return array(); // the lead namespace is not registered — nothing safe to write.
			}
			$refs   = Zdz_Flow_DB::refs();
			$items  = Zdz_Flow_DB::work_items();
			$tenant = Zdz_Flow_DB::tenant_id();

			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT er.work_item_id
					 FROM {$refs} er
					 JOIN {$items} wi ON wi.id = er.work_item_id AND wi.work_type = %s AND wi.tenant_id = %d
					 WHERE er.`system` = %s AND er.entity = %s
					   AND NOT EXISTS (
						 SELECT 1 FROM {$refs} lr WHERE lr.work_item_id = er.work_item_id AND lr.`system` = %s
					   )
					 GROUP BY er.work_item_id
					 LIMIT %d",
					class_exists( 'Zjob_Project' ) ? Zjob_Project::WORK_TYPE : 'project',
					$tenant,
					'estimate',
					'document',
					$system,
					$limit
				)
			);
			return array_map( 'strval', (array) $ids );
		}

		/**
		 * Reconcile ONE Project: read the lead id back from its estimate and write the lead ref. Returns
		 * true only when a ref was written; false (no throw) when the estimate has no lead id yet, so the
		 * candidate is retried next tick.
		 *
		 * @param string $project_id
		 * @return bool
		 */
		public static function reconcile_one( string $project_id ): bool {
			if ( '' === $project_id || ! class_exists( 'Zdz_Flow_Refs' ) ) {
				return false;
			}
			$system = self::lead_system();
			$entity = self::lead_entity( $system );
			if ( '' === $entity ) {
				return false;
			}
			// The estimate id from this Project's estimate ref.
			$est_id = 0;
			foreach ( Zdz_Flow_Refs::for( $project_id, 'estimate', 'document' ) as $r ) {
				$est_id = (int) ( $r['external_id'] ?? 0 );
				if ( $est_id > 0 ) {
					break;
				}
			}
			if ( $est_id <= 0 ) {
				return false;
			}
			$lead_id = function_exists( 'apply_filters' ) ? (int) apply_filters( 'zdz_estimate_lead_id', 0, $est_id ) : 0;
			if ( $lead_id <= 0 ) {
				return false; // no lead id yet — a later tick retries (ongoing pass).
			}
			try {
				Zdz_Flow_Refs::put( $project_id, $system, $entity, (string) $lead_id, array( 'sync_state' => 'reconciled' ) );
				return true;
			} catch ( \Throwable $e ) {
				// A conflict (another Project owns that lead) or a transient failure — skip; idempotent.
				return false;
			}
		}

		/** Sweep subscriber: drain the reconcile candidates under the Core budget, else a bounded pass. */
		public static function reconcile_tick(): void {
			if ( class_exists( 'Zdz_Sweep' ) && is_callable( array( 'Zdz_Sweep', 'run' ) ) ) {
				Zdz_Sweep::run(
					'zjob_install_lead_reconcile',
					static function ( $limit ) {
						return Zjob_Install_Date::reconcile_candidate_ids( (int) $limit );
					},
					static function ( $project_id ) {
						Zjob_Install_Date::reconcile_one( (string) $project_id );
					},
					array(
						'service'     => 'zjob_install_lead_reconcile',
						'batch_limit' => 100,
					)
				);
				return;
			}
			self::reconcile_lead_refs( 100 );
		}

		/** Run one catch-up reconcile per code version (a deploy triggers exactly one), then record it. */
		public static function maybe_boot_reconcile(): void {
			if ( ! function_exists( 'get_option' ) || ! function_exists( 'update_option' ) ) {
				return;
			}
			$version = '1.0.0';
			if ( (string) get_option( 'zjob_install_date_reconcile_version', '' ) === $version ) {
				return;
			}
			self::reconcile_lead_refs( 200 );
			update_option( 'zjob_install_date_reconcile_version', $version );
		}

		/* ===================================================================
		 * INTERNAL
		 * =================================================================== */

		/**
		 * Build a well-formed envelope from a paper status. The state is DERIVED from the status (the
		 * status → state mapping is the only authority), and a real date/time/start_utc is stripped for any non-known
		 * state — so "unknown" is structurally incapable of carrying a real date or the zeroed sentinel.
		 *
		 * @param string $status one of the STATUS_* constants.
		 * @param array  $extra  optional overrides (date, time, start_utc, tz, source, ...).
		 * @return array
		 */
		private static function envelope( string $status, array $extra = array() ): array {
			// A "scheduled" claim with no real date is not knowledge — demote it rather than lie.
			if ( self::STATUS_SCHEDULED === $status && empty( $extra['date'] ) ) {
				$status = self::STATUS_UNKNOWN;
			}
			$state = self::state_for_status( $status );

			$out = array(
				'state'            => $state,
				'status'           => $status,
				'date'             => null,
				'time'             => null,
				'start_utc'        => null,
				'tz'               => '',
				'assignee'         => '',
				'assignee_user_id' => 0,
				'source'           => self::SOURCE_NONE,
				'confidence'       => 'none',
				'basis'            => '',
				'authority'        => self::AUTHORITY_NONE,
				'job_id'           => 0,
				'appt_id'          => 0,
				'job_count'        => 0,
				'spread'           => false,
				'cached_at'        => self::now(),
			);
			foreach ( $extra as $k => $v ) {
				if ( array_key_exists( $k, $out ) ) {
					$out[ $k ] = $v;
				}
			}

			// Defence in depth: a real date may ONLY ride STATE_KNOWN.
			if ( self::STATE_KNOWN !== $state ) {
				$out['date']       = null;
				$out['time']       = null;
				$out['start_utc']  = null;
				$out['source']     = self::SOURCE_NONE;
				$out['confidence'] = 'none';
				$out['authority']  = self::AUTHORITY_NONE;
				$out['job_id']     = 0;
				$out['appt_id']    = 0;
				$out['spread']     = false;
			}
			return $out;
		}

		/** Keep the more informative of two non-known answers; never downgrade a stronger answer. */
		private static function merge_non_known( array $a, array $b ): array {
			return ( self::rank( $a['status'] ?? '' ) >= self::rank( $b['status'] ?? '' ) ) ? $a : $b;
		}

		/** Informativeness rank: scheduled > unscheduled > not_applicable > unknown. */
		private static function rank( string $status ): int {
			switch ( $status ) {
				case self::STATUS_SCHEDULED:
					return 3;
				case self::STATUS_UNSCHEDULED:
					return 2;
				case self::STATUS_NOT_APPLICABLE:
					return 1;
				default:
					return 0;
			}
		}

		/** UTC 'Y-m-d H:i:s' → local date/time parts in $tz. A valid instant always yields a date. */
		private static function local_parts( string $start_utc, string $tz ): array {
			$start_utc = trim( $start_utc );
			if ( '' === $start_utc || self::ZERO_DATETIME === $start_utc ) {
				return array( 'date' => null, 'time' => null );
			}
			try {
				$dt = new \DateTime( $start_utc, new \DateTimeZone( 'UTC' ) );
			} catch ( \Exception $e ) {
				return array( 'date' => null, 'time' => null );
			}
			if ( '' !== $tz ) {
				try {
					$dt->setTimezone( new \DateTimeZone( $tz ) );
				} catch ( \Exception $e ) {
					// keep UTC — a bad tz must not cost us the date.
					$tz = '';
				}
			}
			return array( 'date' => $dt->format( 'Y-m-d' ), 'time' => $dt->format( 'H:i' ) );
		}

		/** The configured CRM-lead ref system (Core default 'lead'; a pack redirects to its provider ns). */
		private static function lead_system(): string {
			$sys = function_exists( 'apply_filters' )
				? (string) apply_filters( 'zdz_project_lead_system', self::LEAD_SYSTEM_DEFAULT )
				: self::LEAD_SYSTEM_DEFAULT;
			$sys = function_exists( 'sanitize_key' ) ? sanitize_key( $sys ) : strtolower( trim( $sys ) );
			return '' !== $sys ? $sys : self::LEAD_SYSTEM_DEFAULT;
		}

		/** The registered entity for the lead system (via the shared namespace resolver). */
		private static function lead_entity( string $system ): string {
			if ( class_exists( 'Zjob_Appointment_Link' ) && is_callable( array( 'Zjob_Appointment_Link', 'entity_for_system' ) ) ) {
				return Zjob_Appointment_Link::entity_for_system( $system );
			}
			if ( class_exists( 'Zjob_Project' ) && is_callable( array( 'Zjob_Project', 'entity_for' ) ) ) {
				return Zjob_Project::entity_for( $system );
			}
			return '';
		}

		/** Which project owns (system, external_id)? Delegates to the canonical resolver. */
		private static function project_for( string $system, string $external_id ): string {
			if ( class_exists( 'Zjob_Appointment_Link' ) && is_callable( array( 'Zjob_Appointment_Link', 'project_for' ) ) ) {
				return Zjob_Appointment_Link::project_for( $system, $external_id );
			}
			if ( class_exists( 'Zjob_Project' ) && is_callable( array( 'Zjob_Project', 'find_by_origin' ) ) ) {
				return Zjob_Project::find_by_origin( $system, $external_id );
			}
			return '';
		}

		/**
		 * Map an estimate DOCUMENT NUMBER to a local estimate id. The key is (doc_type=estimate, number)
		 * — never a bare number (estimate #5982 != invoice #5982). Resolved through the
		 * `zdz_estimate_id_for_number` seam; the Core default reads the estimate store when present.
		 *
		 * @param string $number
		 * @return int local estimate id, or 0 when unresolvable.
		 */
		private static function estimate_id_for_number( string $number ): int {
			$number = trim( $number );
			if ( '' === $number ) {
				return 0;
			}
			$default = self::core_estimate_id_lookup( $number );
			$id      = function_exists( 'apply_filters' )
				? (int) apply_filters( 'zdz_estimate_id_for_number', $default, $number )
				: $default;
			return max( 0, $id );
		}

		/** Guarded Core lookup: the estimate row whose doc_number matches, preferring doc_type=estimate. */
		private static function core_estimate_id_lookup( string $number ): int {
			global $wpdb;
			if ( ! isset( $wpdb ) || ! class_exists( 'ZEST_DB' ) || ! is_callable( array( 'ZEST_DB', 'estimates_table' ) ) ) {
				return 0;
			}
			$table = ZEST_DB::estimates_table();
			$id    = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$table} WHERE doc_number = %s ORDER BY ( doc_type = 'estimate' ) DESC, id ASC LIMIT 1",
					$number
				)
			);
			return null === $id ? 0 : (int) $id;
		}

		/** The business tz (Scheduler setting → site tz → UTC). Never a hardcoded place. */
		private static function default_tz(): string {
			if ( class_exists( 'ZJOB_Scheduler' ) && is_callable( array( 'ZJOB_Scheduler', 'default_tz' ) ) ) {
				$tz = (string) ZJOB_Scheduler::default_tz();
				if ( '' !== $tz ) {
					return $tz;
				}
			}
			if ( function_exists( 'wp_timezone_string' ) ) {
				$tz = (string) wp_timezone_string();
				if ( '' !== $tz ) {
					return $tz;
				}
			}
			return 'UTC';
		}

		/** GATE 1, fail-closed: may this viewer see this project at all (relationship != none, reading)? */
		private static function actor_may_use( int $viewer, array $project ): bool {
			if ( ! class_exists( 'Zjob_Project_Visibility' ) ) {
				return false; // refuse, do not guess.
			}
			return Zjob_Project_Visibility::may_view_project( $viewer, $project );
		}

		/** Current UTC timestamp (WP when present, else gmdate). */
		private static function now(): string {
			return function_exists( 'current_time' ) ? (string) current_time( 'mysql', true ) : gmdate( 'Y-m-d H:i:s' );
		}
	}
}
