<?php
/**
 * Zjob_Appointment_Link — the one write of the `appointment` ref, and the reverse-link helpers.
 *
 * The `appointment/event` namespace has been DECLARED since B2 (Zjob_Project::NAMESPACES) but written
 * nowhere. This class is its one writer: it binds a Scheduler appointment to a Project through the
 * Flow ref map (Zdz_Flow_Refs), so a bare calendar entry can be read back as a Project's install date
 * (Zjob_Install_Date::fill_from_projects) and a Project can be born linked (Zjob_Schedule_Context).
 *
 * TWO GATES, BOTH FAIL-CLOSED, on every write:
 *   GATE 1  Zjob_Project_Visibility::actor_may_use_project()  — the Project gains a date Prep prints;
 *           only someone who may use the Project may link it. (The easy gate to forget, and the one
 *           that reaches PAPER — a wrong link prints a wrong install date on a cut sheet.)
 *   GATE 2  ZSCH_Appointments::can_modify()                   — the estimate #/address land on a
 *           SHARED calendar; only someone who may modify the appointment may bind it. Called through
 *           is_callable(), so before the Scheduler port publishes can_modify() this fails CLOSED
 *           (the link is simply inert, never open).
 *
 * ONE APPOINTMENT PER PROJECT. Zdz_Flow_Refs' PRIMARY KEY(work_item_id, system, entity) allows one
 * appointment ref per Project; UNIQUE(system, entity, external_id) means one Project per appointment.
 * A re-attach to the same Project with a different appointment is therefore a MOVE (reports
 * moved_from); an attempt to bind an appointment another Project already owns throws through as
 * 'owned_by_other'.
 *
 * NO MONEY on a calendar copy. backfill_event() APPENDS, never replaces (a typed title/location
 * wins), and carries only the customer name/address a dispatch entry needs — never a figure. These
 * events sync to personal calendars: a money value here is a hard geo-PII violation.
 *
 * NOTHING AUTO-ATTACHES. suggest() reads the customer name off an event title and OFFERS candidate
 * Projects; only attach() (with an actor and both gates) writes.
 *
 * Ships EMPTY: names no company/person/product/place/provider; seeds nothing.
 *
 * @package Zorderz\Jobs
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Zjob_Appointment_Link' ) ) {

	class Zjob_Appointment_Link {

		/** The ref namespace this class writes (registered generically by Zjob_Project). */
		const SYSTEM = 'appointment';
		const ENTITY = 'event';

		/* ===================================================================
		 * READ / REVERSE-LINK
		 * =================================================================== */

		/**
		 * The one appointment id linked to a Project (0 when none). One-per-project by the PRIMARY KEY.
		 *
		 * @param string $project_id
		 * @return int
		 */
		public static function appointment_for( string $project_id ): int {
			if ( '' === $project_id || ! class_exists( 'Zdz_Flow_Refs' ) ) {
				return 0;
			}
			foreach ( Zdz_Flow_Refs::for( $project_id, self::SYSTEM, self::ENTITY ) as $r ) {
				$ext = (int) ( $r['external_id'] ?? 0 );
				if ( $ext > 0 ) {
					return $ext; // deterministic: at most one row for (project, appointment, event).
				}
			}
			return 0;
		}

		/**
		 * Batch reverse lookup: project_id => appointment_id (0 when a project has none).
		 *
		 * @param array $project_ids
		 * @return array<string,int>
		 */
		public static function appointments_for( array $project_ids ): array {
			$out = array();
			foreach ( $project_ids as $pid ) {
				$key = (string) $pid;
				if ( '' === $key || isset( $out[ $key ] ) ) {
					continue;
				}
				$out[ $key ] = self::appointment_for( $key );
			}
			return $out;
		}

		/**
		 * Which Project owns (system, external_id)? The canonical resolver used by the INV-8 arms — it
		 * resolves the entity for ANY registered system (core or a pack-added provider ns), not just the
		 * container's built-ins.
		 *
		 * @param string $system
		 * @param string $external_id
		 * @return string project id, or '' when none.
		 */
		public static function project_for( string $system, string $external_id ): string {
			$entity = self::entity_for_system( $system );
			if ( '' === $entity || ! class_exists( 'Zdz_Flow_Refs' ) ) {
				return '';
			}
			$id = Zdz_Flow_Refs::get( $system, $entity, (string) $external_id );
			return null === $id ? '' : (string) $id;
		}

		/** Convenience: which Project owns this appointment id? */
		public static function project_for_appointment( int $appt_id ): string {
			return self::project_for( self::SYSTEM, (string) $appt_id );
		}

		/**
		 * The registered entity for a namespace system. Core container namespaces first
		 * (estimate, appointment, lead, ...), then any pack-registered provider namespace (a CRM's lead).
		 *
		 * @param string $system
		 * @return string entity, or '' when the system is not registered.
		 */
		public static function entity_for_system( string $system ): string {
			$system = function_exists( 'sanitize_key' ) ? sanitize_key( $system ) : strtolower( trim( $system ) );
			if ( '' === $system ) {
				return '';
			}
			if ( class_exists( 'Zjob_Project' ) && is_callable( array( 'Zjob_Project', 'entity_for' ) ) ) {
				$e = Zjob_Project::entity_for( $system );
				if ( '' !== $e ) {
					return $e;
				}
			}
			if ( class_exists( 'Zdz_Flow_Refs' ) && is_callable( array( 'Zdz_Flow_Refs', 'namespaces' ) ) ) {
				foreach ( Zdz_Flow_Refs::namespaces() as $ns ) {
					if ( ( $ns['system'] ?? '' ) === $system ) {
						return (string) ( $ns['entity'] ?? '' );
					}
				}
			}
			return '';
		}

		/* ===================================================================
		 * WRITE — double-gated. attach / detach / forget.
		 * =================================================================== */

		/**
		 * Link an appointment to a Project. Double-gated; a re-attach with a different appointment is a
		 * MOVE (reports moved_from). Idempotent when the same pair is already linked.
		 *
		 * @param string $project_id
		 * @param int    $appt_id
		 * @param int    $viewer     the acting user.
		 * @return array{ok:bool,error?:string,project_id?:string,appt_id?:int,moved_from?:int,owner?:string}
		 */
		public static function attach( string $project_id, int $appt_id, int $viewer ): array {
			$project_id = (string) $project_id;
			if ( '' === $project_id || $appt_id <= 0 || $viewer <= 0 ) {
				return array( 'ok' => false, 'error' => 'bad_args' );
			}
			if ( ! class_exists( 'Zjob_Project' ) || ! class_exists( 'Zdz_Flow_Refs' ) ) {
				return array( 'ok' => false, 'error' => 'unavailable' );
			}
			$project = Zjob_Project::get( $project_id );
			if ( ! is_array( $project ) ) {
				return array( 'ok' => false, 'error' => 'no_project' );
			}
			// GATE 1 — may this viewer use this Project? (the gate that reaches paper.)
			if ( ! self::gate_project( $viewer, $project ) ) {
				return array( 'ok' => false, 'error' => 'forbidden_project' );
			}
			// GATE 2 — the appointment must exist and be modifiable by this actor (shared calendar).
			$appt = self::appointment_raw( $appt_id );
			if ( ! is_array( $appt ) ) {
				return array( 'ok' => false, 'error' => 'no_appointment' );
			}
			if ( ! self::gate_appointment( $viewer, $appt ) ) {
				return array( 'ok' => false, 'error' => 'forbidden_appointment' );
			}

			$prev = self::appointment_for( $project_id ); // detect a move.
			try {
				Zdz_Flow_Refs::put( $project_id, self::SYSTEM, self::ENTITY, (string) $appt_id, array( 'sync_state' => 'linked' ) );
			} catch ( \Throwable $e ) {
				// A different Project already owns this appointment (UNIQUE) — surface, never shadow.
				if ( class_exists( 'Zdz_Flow_Ref_Conflict' ) && $e instanceof Zdz_Flow_Ref_Conflict ) {
					return array( 'ok' => false, 'error' => 'owned_by_other', 'owner' => $e->winner() );
				}
				return array( 'ok' => false, 'error' => 'link_failed' );
			}

			$out = array( 'ok' => true, 'project_id' => $project_id, 'appt_id' => $appt_id );
			if ( $prev > 0 && $prev !== $appt_id ) {
				$out['moved_from'] = $prev;
			}
			return $out;
		}

		/**
		 * Unlink a Project's appointment. Gated on the Project (a viewer who may use it may unlink it).
		 *
		 * @param string $project_id
		 * @param int    $viewer
		 * @return array{ok:bool,error?:string}
		 */
		public static function detach( string $project_id, int $viewer ): array {
			$project_id = (string) $project_id;
			if ( '' === $project_id || $viewer <= 0 || ! class_exists( 'Zjob_Project' ) ) {
				return array( 'ok' => false, 'error' => 'bad_args' );
			}
			$project = Zjob_Project::get( $project_id );
			if ( ! is_array( $project ) ) {
				return array( 'ok' => false, 'error' => 'no_project' );
			}
			if ( ! self::gate_project( $viewer, $project ) ) {
				return array( 'ok' => false, 'error' => 'forbidden_project' );
			}
			self::forget( $project_id );
			return array( 'ok' => true );
		}

		/**
		 * Remove a Project's appointment ref, unconditionally (an internal cleanup, e.g. the drift
		 * subscriber after a Scheduler-side delete — not an actor action, so no gate). Optionally scoped
		 * to one appointment id so a stale delete cannot drop a ref that has since moved on.
		 *
		 * NOTE: Zdz_Flow_Refs exposes no delete verb yet; this performs the scoped DELETE directly on
		 * the ref table (a data operation, backticked column names via $wpdb->delete). See FLAGGED — a
		 * later pass should promote a Zdz_Flow_Refs::forget() so this stops reaching around the API.
		 *
		 * @param string   $project_id
		 * @param int|null $appt_id when >0, only forget a ref pointing at exactly this appointment.
		 * @return bool
		 */
		public static function forget( string $project_id, ?int $appt_id = null ): bool {
			global $wpdb;
			if ( '' === $project_id || ! isset( $wpdb ) || ! class_exists( 'Zdz_Flow_DB' ) ) {
				return false;
			}
			$table = Zdz_Flow_DB::refs();
			$where = array(
				'work_item_id' => $project_id,
				'system'       => self::SYSTEM,
				'entity'       => self::ENTITY,
			);
			if ( null !== $appt_id && $appt_id > 0 ) {
				$where['external_id'] = (string) $appt_id;
			}
			$wpdb->delete( $table, $where );
			return true;
		}

		/* ===================================================================
		 * BACKFILL — append-never-replace; NO money.
		 * =================================================================== */

		/**
		 * Fill an EMPTY appointment title/location from the linked Project's customer block. Appends,
		 * never replaces (a typed title/location wins). Carries only the customer name/address a
		 * dispatch entry needs — NEVER a money figure. Double-gated like attach().
		 *
		 * @param int $appt_id
		 * @param int $viewer
		 * @return array{ok:bool,error?:string,filled?:array,noop?:bool}
		 */
		public static function backfill_event( int $appt_id, int $viewer ): array {
			if ( $appt_id <= 0 || $viewer <= 0 ) {
				return array( 'ok' => false, 'error' => 'bad_args' );
			}
			$pid = self::project_for_appointment( $appt_id );
			if ( '' === $pid || ! class_exists( 'Zjob_Project' ) ) {
				return array( 'ok' => false, 'error' => 'no_link' );
			}
			$project = Zjob_Project::get( $pid );
			if ( ! is_array( $project ) || ! self::gate_project( $viewer, $project ) ) {
				return array( 'ok' => false, 'error' => 'forbidden_project' );
			}
			$appt = self::appointment_raw( $appt_id );
			if ( ! is_array( $appt ) || ! self::gate_appointment( $viewer, $appt ) ) {
				return array( 'ok' => false, 'error' => 'forbidden_appointment' );
			}

			// Read the RAW customer fields (the escaped customer_block is for HTML; the Scheduler
			// sanitizes what we pass). Never read or pass any money field.
			$jobs = Zjob_Project::jobs_for( $pid );
			$j0   = is_array( $jobs ) && ! empty( $jobs ) ? (array) $jobs[0] : array();
			$name = trim( (string) ( $j0['customer_name'] ?? '' ) );
			$addr = trim( (string) ( $j0['customer_address'] ?? '' ) );

			$update = array();
			if ( '' === trim( (string) ( $appt['title'] ?? '' ) ) ) {
				$title = self::compose_title( $project, $name );
				if ( '' !== $title ) {
					$update['title'] = $title;
				}
			}
			if ( '' === trim( (string) ( $appt['location'] ?? '' ) ) && '' !== $addr ) {
				$update['location'] = $addr;
			}
			if ( empty( $update ) ) {
				return array( 'ok' => true, 'noop' => true );
			}
			if ( ! is_callable( array( 'ZSCH_Appointments', 'update' ) ) ) {
				return array( 'ok' => false, 'error' => 'scheduler_unavailable' );
			}
			$res = ZSCH_Appointments::update( $viewer, $appt_id, $update );
			return array(
				'ok'     => ! empty( $res['success'] ),
				'error'  => (string) ( $res['error'] ?? '' ),
				'filled' => array_keys( $update ),
			);
		}

		/* ===================================================================
		 * SUGGEST — advisory only; NOTHING auto-attaches.
		 * =================================================================== */

		/**
		 * Offer candidate Projects for a bare appointment, by matching the customer name parsed from the
		 * event title against the viewer's VISIBLE Projects (list_for is already viewer-scoped). Returns
		 * the parsed name and a bounded candidate list; it writes NOTHING — only attach() links.
		 *
		 * @param int $appt_id
		 * @param int $viewer
		 * @return array{ok:bool,customer?:string,candidates?:array,error?:string}
		 */
		public static function suggest( int $appt_id, int $viewer ): array {
			if ( $appt_id <= 0 || $viewer <= 0 || ! class_exists( 'Zjob_Project' ) ) {
				return array( 'ok' => false, 'error' => 'bad_args' );
			}
			$appt = self::appointment_raw( $appt_id );
			if ( ! is_array( $appt ) ) {
				return array( 'ok' => false, 'error' => 'no_appointment' );
			}
			$needle = self::customer_from_title( (string) ( $appt['title'] ?? '' ) );
			if ( '' === $needle ) {
				return array( 'ok' => true, 'customer' => '', 'candidates' => array() );
			}
			$needle_l = function_exists( 'mb_strtolower' ) ? mb_strtolower( $needle ) : strtolower( $needle );

			$candidates = array();
			$projects   = is_callable( array( 'Zjob_Project', 'list_for' ) )
				? Zjob_Project::list_for( $viewer, array( 'limit' => 50 ) )
				: array();
			foreach ( (array) $projects as $p ) {
				$pid = (string) ( $p['id'] ?? '' );
				if ( '' === $pid ) {
					continue;
				}
				$block = is_callable( array( 'Zjob_Project', 'customer_block' ) ) ? Zjob_Project::customer_block( $pid ) : array();
				$hay   = trim( (string) ( $block['name'] ?? '' ) . ' ' . (string) ( $block['business'] ?? '' ) );
				if ( '' === $hay ) {
					continue;
				}
				$hay_l = function_exists( 'mb_strtolower' ) ? mb_strtolower( $hay ) : strtolower( $hay );
				if ( false !== strpos( $hay_l, $needle_l ) ) {
					$candidates[] = array(
						'project_id'    => $pid,
						'human_code'    => (string) ( $p['human_code'] ?? '' ),
						'already_linked' => self::appointment_for( $pid ) > 0,
					);
				}
				if ( count( $candidates ) >= 10 ) {
					break;
				}
			}
			return array( 'ok' => true, 'customer' => $needle, 'candidates' => $candidates );
		}

		/* ===================================================================
		 * INTERNAL
		 * =================================================================== */

		/** GATE 1: relationship != none (fail-closed when the visibility engine is absent). */
		private static function gate_project( int $viewer, array $project ): bool {
			if ( ! class_exists( 'Zjob_Project_Visibility' ) ) {
				return false;
			}
			return Zjob_Project_Visibility::actor_may_use_project( $viewer, $project );
		}

		/**
		 * GATE 2: the actor may modify the appointment. Uses is_callable() so a still-private (not-yet-
		 * published) can_modify() reads as UNAVAILABLE and the gate fails CLOSED — never open.
		 */
		private static function gate_appointment( int $viewer, array $appt ): bool {
			if ( ! is_callable( array( 'ZSCH_Appointments', 'can_modify' ) ) ) {
				return false; // the Scheduler port has not published can_modify() yet — fail closed.
			}
			return (bool) ZSCH_Appointments::can_modify( $viewer, $appt );
		}

		/** The raw appointment row, or null (guarded). */
		private static function appointment_raw( int $appt_id ) {
			if ( ! class_exists( 'ZSCH_Appointments' ) || ! is_callable( array( 'ZSCH_Appointments', 'get_raw' ) ) ) {
				return null;
			}
			$raw = ZSCH_Appointments::get_raw( $appt_id );
			return is_array( $raw ) ? $raw : null;
		}

		/**
		 * A neutral appointment title from the customer name (and, failing that, the Project code). The
		 * "— Est #NNNN" convention is [IDENTITY→document-conventions]; Core ships the generic default and
		 * exposes the `zjob_appointment_title` filter. Never a money figure, never a provider name.
		 */
		private static function compose_title( array $project, string $customer_name ): string {
			$default = '' !== $customer_name ? $customer_name : (string) ( $project['human_code'] ?? '' );
			$title   = function_exists( 'apply_filters' )
				? (string) apply_filters( 'zjob_appointment_title', $default, $project, $customer_name )
				: $default;
			$title = trim( $title );
			return function_exists( 'mb_substr' ) ? mb_substr( $title, 0, 200 ) : substr( $title, 0, 200 );
		}

		/** Parse the customer token from an event title ("<component> - <customer>" / "<customer> — ..."). */
		private static function customer_from_title( string $title ): string {
			$title = trim( $title );
			if ( '' === $title ) {
				return '';
			}
			// Prefer the segment after the last " - " (the ZJOB_Scheduler title convention).
			$pos = strrpos( $title, ' - ' );
			if ( false !== $pos ) {
				$tail = trim( substr( $title, $pos + 3 ) );
				if ( '' !== $tail ) {
					return $tail;
				}
			}
			// Else the segment before an em-dash annotation.
			$pos = strpos( $title, ' — ' );
			if ( false !== $pos ) {
				$head = trim( substr( $title, 0, $pos ) );
				if ( '' !== $head ) {
					return $head;
				}
			}
			return $title;
		}
	}
}
