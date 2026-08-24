<?php
/**
 * Zjob_Schedule_Context — the jobs-side of the `zdz_compose_context` handoff.
 *
 * THE SEAM. Another app (the Scheduler intake) hands context IN — an origin `{ns, ref_id}`, the acting
 * user — and gets a prefill bundle OUT, WITHOUT learning any Projects internal. The contract is one
 * filter:
 *
 *     apply_filters( 'zdz_compose_context', array $bundle, array $context )
 *
 *   $context (from the HOOK PAYLOAD only — never the request body; INV-1):
 *     { ns:'project', ref_id:'<project work_item id>', actor:<user id>, origin?:{...} }
 *   returned $bundle (this contributor, when ns === 'project'):
 *     { origin:{ns,ref_id},
 *       prefill:{ title, location, body, owner_id, attendees:[], duration_min },
 *       suggest:[],            // free-slot suggestion is the Scheduler side (Zsch_Suggest)
 *       facts:[ ... <= 4 ] }   // context chips; NO money
 *   or, for an already-booked Project, a refusal naming the date:
 *     { origin, refused:'already_booked', appt_id, when }
 *
 * TWO HARD RULES.
 *   - NO MONEY and NO NETWORK in the bundle. The prefill is title/location/body/owner/duration and a
 *     handful of non-money facts; nothing here fetches, and nothing here is a figure. (The bundle
 *     rides to a calendar that syncs to personal mailboxes — a money value is a geo-PII violation.)
 *   - ONE APPOINTMENT PER PROJECT, enforced TWICE: compose() refuses an already-booked Project, and
 *     on_created() refuses again at save time (the intake token is claimable for its TTL, so a second
 *     appointment could arrive between compose and save). A second appointment is REFUSED, never
 *     silently shadowed — else Prep prints the first date forever while a second exists.
 *
 * origin is trusted ONLY from the hook payload. On save the Scheduler reads origin from the stashed
 * token, never from the submitted body.
 *
 * The single-user TTL token vault, the intake route, and the `data-zdz-schedule` launch button live on
 * the SCHEDULER side (Zsch_Intake / Zsch_Suggest, the Wave-C Scheduler port). This class owns the
 * contract and the jobs-side contributor + save-subscriber; it defines the Core-default token TTL the
 * intake reads.
 *
 * Ships EMPTY: names no company/person/product/place/provider; seeds nothing.
 *
 * @package Zorderz\Jobs
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Zjob_Schedule_Context' ) ) {

	class Zjob_Schedule_Context {

		/** The origin namespace this contributor answers for. */
		const NS = 'project';

		/**
		 * The Core-default token TTL (seconds). Raised to 2h from the original 15 min (S4-06 slow-save
		 * fix): a token must outlive a slow save. The Scheduler intake reads this; a tenant tunes the
		 * `zdz_compose_context_token_ttl` filter.
		 */
		const TOKEN_TTL_DEFAULT = 7200;

		/**
		 * The Core-default prefill duration (minutes). [IDENTITY→profile]; resolved through the existing
		 * ZJOB_Scheduler duration mechanism so it is never a second hardcoded literal.
		 */
		const DURATION_MIN_DEFAULT = 120;

		/** Subscribe the compose filter and the created action. Idempotent; seeds nothing. */
		public static function init(): void {
			if ( function_exists( 'add_filter' ) ) {
				add_filter( 'zdz_compose_context', array( __CLASS__, 'compose' ), 10, 2 );
			}
			if ( function_exists( 'add_action' ) ) {
				add_action( 'zsch_appointment_created', array( __CLASS__, 'on_created' ), 10, 1 );
			}
		}

		/**
		 * The `zdz_compose_context` subscriber. When the origin ns is 'project', gate the actor, refuse
		 * an already-booked Project (naming the date), and return the prefill bundle. For any other ns,
		 * pass the bundle through untouched. A missing/forbidden Project is a SILENT pass-through (never
		 * reveal existence).
		 *
		 * @param mixed $bundle  the accumulating bundle.
		 * @param mixed $context { ns, ref_id, actor, origin? } — from the hook payload only.
		 * @return array
		 */
		public static function compose( $bundle, $context ): array {
			$bundle  = is_array( $bundle ) ? $bundle : array();
			$context = is_array( $context ) ? $context : array();

			$ns = function_exists( 'sanitize_key' )
				? sanitize_key( (string) ( $context['ns'] ?? '' ) )
				: strtolower( trim( (string) ( $context['ns'] ?? '' ) ) );
			if ( self::NS !== $ns ) {
				return $bundle; // not ours.
			}
			$ref    = (string) ( $context['ref_id'] ?? '' );
			$viewer = (int) ( $context['actor'] ?? 0 );
			if ( '' === $ref || $viewer <= 0 || ! class_exists( 'Zjob_Project' ) ) {
				return $bundle;
			}
			$project = Zjob_Project::get( $ref );
			if ( ! is_array( $project ) || ! self::gate( $viewer, $project ) ) {
				return $bundle; // silent pass-through: do not reveal whether the Project exists.
			}

			// ONE APPOINTMENT (compose-time): refuse an already-booked Project, naming the date so the
			// UI can jump to the existing appointment instead of creating a second.
			$existing = class_exists( 'Zjob_Appointment_Link' ) ? Zjob_Appointment_Link::appointment_for( $ref ) : 0;
			if ( $existing > 0 ) {
				$when = null;
				if ( class_exists( 'Zjob_Install_Date' ) ) {
					$inst = Zjob_Install_Date::for_project( $ref, $viewer );
					$when = is_array( $inst ) ? ( $inst['date'] ?? null ) : null;
				}
				return array_merge(
					$bundle,
					array(
						'origin'  => self::origin( $ref ),
						'refused' => 'already_booked',
						'appt_id' => $existing,
						'when'    => $when,
					)
				);
			}

			$jobs = Zjob_Project::jobs_for( $ref );
			$j0   = is_array( $jobs ) && ! empty( $jobs ) ? (array) $jobs[0] : array();

			$prefill = array(
				'title'        => self::title( $project, (string) ( $j0['customer_name'] ?? '' ) ),
				'location'     => trim( (string) ( $j0['customer_address'] ?? '' ) ),
				'body'         => self::body( $project, $jobs ),
				'owner_id'     => (int) ( $j0['assigned_user_id'] ?? 0 ),
				'attendees'    => array(),
				'duration_min' => self::duration_min( $j0 ),
			);

			return array_merge(
				$bundle,
				array(
					'origin'  => self::origin( $ref ),
					'prefill' => $prefill,
					'suggest' => array(), // the free-slot finder is the Scheduler side.
					'facts'   => self::facts( $project ),
				)
			);
		}

		/**
		 * The `zsch_appointment_created` subscriber. When the appointment was born from a project
		 * handoff, write the appointment ref (born-linked) — but only after re-checking one-appointment
		 * at save time. A second appointment is REFUSED (logged via `zjob_schedule_context_refused`),
		 * never shadowed. origin is read from the payload (which the intake filled from the token), never
		 * from the request body.
		 *
		 * @param mixed $payload { appt_id|id, actor, origin:{ns,ref_id} }
		 */
		public static function on_created( $payload ): void {
			if ( ! is_array( $payload ) ) {
				return;
			}
			$origin = ( isset( $payload['origin'] ) && is_array( $payload['origin'] ) ) ? $payload['origin'] : array();
			$ns     = function_exists( 'sanitize_key' )
				? sanitize_key( (string) ( $origin['ns'] ?? '' ) )
				: strtolower( trim( (string) ( $origin['ns'] ?? '' ) ) );
			$ref     = (string) ( $origin['ref_id'] ?? '' );
			$appt_id = (int) ( $payload['appt_id'] ?? $payload['id'] ?? 0 );
			$actor   = (int) ( $payload['actor'] ?? 0 );

			if ( self::NS !== $ns || '' === $ref || $appt_id <= 0 ) {
				return; // not a project handoff.
			}
			if ( ! class_exists( 'Zjob_Appointment_Link' ) ) {
				return;
			}

			// ONE APPOINTMENT (save-time): a different appointment already linked must not be shadowed.
			$existing = Zjob_Appointment_Link::appointment_for( $ref );
			if ( $existing > 0 && $existing !== $appt_id ) {
				if ( function_exists( 'do_action' ) ) {
					do_action( 'zjob_schedule_context_refused', $ref, $appt_id, $existing );
				}
				return;
			}

			// Born linked. attach() re-runs both gates and is idempotent when already linked.
			Zjob_Appointment_Link::attach( $ref, $appt_id, $actor );
		}

		/** The Core-default compose-context token TTL (seconds); the Scheduler intake reads this. */
		public static function token_ttl(): int {
			$ttl = function_exists( 'apply_filters' )
				? (int) apply_filters( 'zdz_compose_context_token_ttl', self::TOKEN_TTL_DEFAULT )
				: self::TOKEN_TTL_DEFAULT;
			return $ttl > 0 ? $ttl : self::TOKEN_TTL_DEFAULT;
		}

		/* ===================================================================
		 * INTERNAL
		 * =================================================================== */

		/** The trusted origin echoed back for the intake to stash under its token. */
		private static function origin( string $ref ): array {
			return array( 'ns' => self::NS, 'ref_id' => (string) $ref );
		}

		/** GATE: the actor may use this Project (fail-closed). */
		private static function gate( int $viewer, array $project ): bool {
			if ( ! class_exists( 'Zjob_Project_Visibility' ) ) {
				return false;
			}
			return Zjob_Project_Visibility::actor_may_use_project( $viewer, $project );
		}

		/** A neutral prefill title (delegates to the appointment-link title convention). */
		private static function title( array $project, string $customer_name ): string {
			$default = '' !== trim( $customer_name ) ? trim( $customer_name ) : (string) ( $project['human_code'] ?? '' );
			$title   = function_exists( 'apply_filters' )
				? (string) apply_filters( 'zjob_appointment_title', $default, $project, $customer_name )
				: $default;
			return trim( $title );
		}

		/**
		 * Concise internal dispatch context — never a customer artifact, never a money figure. A short,
		 * neutral line the scheduler shows alongside the booking.
		 */
		private static function body( array $project, array $jobs ): string {
			$code  = (string) ( $project['human_code'] ?? '' );
			$count = is_array( $jobs ) ? count( $jobs ) : 0;
			$lines = array();
			$lines[] = ( '' !== $code ? $code . ' — ' : '' ) . $count . ' component(s).';
			$lines[] = 'Internal dispatch context; not a customer invoice line.';
			return implode( "\n", $lines );
		}

		/** The prefill duration (minutes) via the existing ZJOB_Scheduler mechanism (no new literal). */
		private static function duration_min( array $row ): int {
			if ( class_exists( 'ZJOB_Scheduler' ) && is_callable( array( 'ZJOB_Scheduler', 'default_duration_min' ) ) ) {
				return (int) ZJOB_Scheduler::default_duration_min( $row );
			}
			return self::DURATION_MIN_DEFAULT;
		}

		/**
		 * Up to four context chips for the scheduler UI — code, derived status, component count, and
		 * whether an appointment already exists. NO money, NO customer PII beyond what the prefill
		 * already needs.
		 *
		 * @param array $project
		 * @return array<int,array{label:string,value:string}>
		 */
		private static function facts( array $project ): array {
			$pid    = (string) ( $project['id'] ?? '' );
			$counts = ( isset( $project['counts'] ) && is_array( $project['counts'] ) ) ? $project['counts'] : array();
			$total  = 0;
			foreach ( $counts as $c ) {
				$total += (int) $c;
			}
			$facts   = array();
			$facts[] = array( 'label' => 'code', 'value' => (string) ( $project['human_code'] ?? '' ) );
			$facts[] = array( 'label' => 'status', 'value' => (string) ( $project['state'] ?? '' ) );
			$facts[] = array( 'label' => 'components', 'value' => (string) $total );
			$linked  = ( '' !== $pid && class_exists( 'Zjob_Appointment_Link' ) ) ? ( Zjob_Appointment_Link::appointment_for( $pid ) > 0 ) : false;
			$facts[] = array( 'label' => 'appointment', 'value' => $linked ? 'linked' : 'none' );
			return array_slice( $facts, 0, 4 );
		}
	}
}
