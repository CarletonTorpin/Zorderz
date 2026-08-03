<?php
/**
 * Zorderz Jobs — the Job model (app-side source of truth) + the oversight gate.
 *
 * A job is a unit of field work. It may be a whole job or a COMPONENT of a mixed
 * order handed to a specialist as a separate tracked sub-job. This class owns the
 * app-side record; ZJOB_Nutshell mirrors it into the CRM (when configured).
 *
 * CORE-SERVICE BINDINGS (services that do not exist yet bind through a documented
 * filter with a graceful fallback — no competing taxonomy is invented):
 *   - Item Engine : the job `component` kind. Default via `zdz_default_job_component`
 *                   (fallback 'other'); see zjob_default_component() in app.php.
 *   - Flow        : the state machine + dispositions. States stay in-app for now;
 *                   every drop/attestation is logged as a disposition and also fired
 *                   on `zdz_flow_disposition` so the Flow service can consume it.
 *   - Service Area: the finish-location gate binds `zdz_job_location_verified` with
 *                   a graceful fallback to the accuracy gate; the theme geocoder
 *                   (ZDZ_Media_Geocoder) reverse-geocodes the fix for provenance.
 *
 * @package Zorderz\Jobs
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZJOB_Jobs {

	// pending_close = the worker marked THEIR part complete (with finish photos);
	// it awaits the originator's official close-out (or, for a solo operator, a
	// recorded single-party attestation) -> done.
	const STATUSES = [ 'open', 'in_progress', 'pending_close', 'done', 'cancelled' ];

	/** Defaults (each is filterable — see the getters below). */
	const MIN_FINISH_PHOTOS_DEFAULT   = 1;
	const GPS_ACCURACY_MAX_M_DEFAULT  = 100;
	const CLOSE_MAX_DAYS_DEFAULT      = 60;
	const CLOSE_EXTEND_DEFAULT_DAYS   = 60;
	const CLOSE_SOON_DAYS_DEFAULT     = 7;
	const CLOSE_EXTEND_CEILING_DAYS   = 3650; // a sane ~10-year hard ceiling on any single extension

	/** How a job reached `done`. Never laundered: a solo attestation is distinct. */
	const ASSURANCE_TWO_PARTY   = 'two_party';
	const ASSURANCE_SINGLE      = 'single_party_attested';
	const ASSURANCE_SYSTEM_AUTO = 'system_auto';

	/** Worker-inbox ETA signals (on_my_way doubles as auto-start; running_late flags the dispatcher). */
	const ETA_STATUSES = [ 'on_my_way', 'running_late' ];

	/* =======================================================================
	 * CONFIGURABLE RULE GETTERS (Rule Governance: parameterised, tenant-tunable)
	 * ======================================================================= */

	public static function min_finish_photos(): int {
		return max( 1, (int) apply_filters( 'zdz_job_min_finish_photos', self::MIN_FINISH_PHOTOS_DEFAULT ) );
	}
	public static function gps_accuracy_max_m(): int {
		return max( 1, (int) apply_filters( 'zdz_job_gps_accuracy_max_m', self::GPS_ACCURACY_MAX_M_DEFAULT ) );
	}
	/** The auto-close window (days). Configurable rule; default 60. */
	public static function close_max_days(): int {
		$opt = (int) get_option( 'zjob_close_max_days', 0 );
		$val = $opt > 0 ? $opt : self::CLOSE_MAX_DAYS_DEFAULT;
		return max( 1, (int) apply_filters( 'zdz_job_close_max_days', $val ) );
	}
	public static function close_extend_default_days(): int {
		return max( 1, (int) apply_filters( 'zdz_job_close_extend_default_days', self::CLOSE_EXTEND_DEFAULT_DAYS ) );
	}
	public static function close_soon_days(): int {
		return max( 1, (int) apply_filters( 'zdz_job_close_soon_days', self::CLOSE_SOON_DAYS_DEFAULT ) );
	}

	/* =======================================================================
	 * GATES (server-authoritative; kiosk-forbidden)
	 * ======================================================================= */

	/** The data-permission slug that authorises creating job handoffs (filterable). */
	public static function handoff_permission(): string {
		return (string) apply_filters( 'zdz_job_handoff_permission', 'handoff_jobs' );
	}

	/** May $user_id create job handoffs at all? Needs the data permission; kiosk never. */
	public static function user_can_hand_off( int $user_id ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}
		if ( class_exists( 'ZDZ_Hierarchy' ) && ZDZ_Hierarchy::is_kiosk( $user_id ) ) {
			return false; // a shared device is not a person
		}
		if ( class_exists( 'ZDZ_Data_Permissions' ) ) {
			return ZDZ_Data_Permissions::can( $user_id, self::handoff_permission() );
		}
		// Fail closed if the permission engine is unavailable (deliberate posture).
		return false;
	}

	/**
	 * May $actor see/manage job $row? The assignee, the creator, an overseeing crew
	 * lead of either, or an admin. Kiosk never.
	 */
	public static function actor_can_manage( int $actor_id, array $row ): bool {
		if ( $actor_id <= 0 || empty( $row ) ) {
			return false;
		}
		if ( class_exists( 'ZDZ_Hierarchy' ) && ZDZ_Hierarchy::is_kiosk( $actor_id ) ) {
			return false;
		}
		$assignee = (int) ( $row['assigned_user_id'] ?? 0 );
		$creator  = (int) ( $row['created_by'] ?? 0 );

		if ( $actor_id === $assignee || $actor_id === $creator ) {
			return true;
		}
		if ( class_exists( 'ZDZ_Hierarchy' ) ) {
			if ( ZDZ_Hierarchy::is_admin( $actor_id ) ) {
				return true;
			}
			if ( ( $assignee && ZDZ_Hierarchy::can_oversee( $actor_id, $assignee ) )
				|| ( $creator && ZDZ_Hierarchy::can_oversee( $actor_id, $creator ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * May $actor SCHEDULE (set/move/clear the time of) job $row? Stricter than
	 * manage: the plain ASSIGNEE (the worker doing it) may manage their own status
	 * but must NOT choose their own time. Allowed: admin, the originator, or an
	 * overseeing crew lead. Kiosk never.
	 */
	public static function actor_can_schedule( int $actor_id, array $row ): bool {
		if ( $actor_id <= 0 || empty( $row ) ) {
			return false;
		}
		if ( class_exists( 'ZDZ_Hierarchy' ) && ZDZ_Hierarchy::is_kiosk( $actor_id ) ) {
			return false;
		}
		if ( $actor_id === (int) ( $row['created_by'] ?? 0 ) ) {
			return true;
		}
		if ( class_exists( 'ZDZ_Hierarchy' ) ) {
			if ( ZDZ_Hierarchy::is_admin( $actor_id ) ) {
				return true;
			}
			$assignee = (int) ( $row['assigned_user_id'] ?? 0 );
			if ( $assignee && ZDZ_Hierarchy::can_oversee( $actor_id, $assignee ) ) {
				return true;
			}
		}
		return false; // deliberately NOT the plain assignee.
	}

	/**
	 * May $actor SCHEDULE this job, INCLUDING the single-operator relaxation? This is
	 * the gate the scheduling OPERATIONS use (set_schedule / clear_schedule, the "when"
	 * control, and the bucket that decides "act now vs. wait on a dispatcher").
	 *
	 * It is a strict SUPERSET of actor_can_schedule(): every dispatcher who already
	 * qualifies still qualifies, and — ONLY when the org has turned on single-operator
	 * mode / self-scheduling (see workers_may_self_schedule()) — the plain ASSIGNEE may
	 * also schedule their OWN job. With the mode off (the default) this collapses to
	 * exactly actor_can_schedule(), so the dispatcher-only rule is unchanged.
	 *
	 * Deliberately NOT wired into actor_can_close() or the set_status() 'done' gate:
	 * the two-party close and the photo-gated completion floor stay bound to the strict
	 * actor_can_schedule(), so a solo operator may set their own time yet still cannot
	 * jump straight to `done` or launder a two-party close on their own job.
	 */
	public static function actor_can_self_schedule( int $actor_id, array $row ): bool {
		if ( self::actor_can_schedule( $actor_id, $row ) ) {
			return true; // the dispatcher-only rule already grants it
		}
		if ( $actor_id <= 0 || empty( $row ) ) {
			return false;
		}
		if ( class_exists( 'ZDZ_Hierarchy' ) && ZDZ_Hierarchy::is_kiosk( $actor_id ) ) {
			return false; // a shared device is not a person
		}
		// Single-operator relaxation: the assignee may set their OWN time when the org
		// has enabled it. Off by default -> this branch never fires and the strict
		// dispatcher rule above stands.
		if ( $actor_id === (int) ( $row['assigned_user_id'] ?? 0 ) && self::workers_may_self_schedule() ) {
			return true;
		}
		return false;
	}

	/**
	 * May $actor CLOSE OUT job $row (the two-party sign-off)? Same set as dispatch:
	 * the originator, an admin, or an overseeing lead — never the plain worker.
	 * (The solo-operator single-party attestation is a SEPARATE, recorded path:
	 * see worker_self_attest_close().)
	 */
	public static function actor_can_close( int $actor_id, array $row ): bool {
		// Two-party integrity (safety floor): a two-party close requires a SECOND
		// party, so the worker who did the job may never record the two-party close on
		// their OWN job — that would launder a single-party close into `two_party`. A
		// solo operator (worker == originator, no distinct lead) closes via the recorded
		// single-party attestation path instead (worker_self_attest_close()). Without
		// this guard a solo operator, who is also the originator, would satisfy
		// actor_can_schedule() and silently close as two_party, defeating the floor.
		if ( $actor_id > 0 && $actor_id === (int) ( $row['assigned_user_id'] ?? 0 ) ) {
			return false;
		}
		return self::actor_can_schedule( $actor_id, $row );
	}

	/**
	 * Is a job a genuine solo case — no second qualified party exists to close it?
	 * True only when the worker is also the originator AND has no distinct crew lead.
	 * A distinct originator or overseeing lead means the two-party close applies.
	 */
	public static function is_genuine_solo( array $row, int $worker_id ): bool {
		$assignee = (int) ( $row['assigned_user_id'] ?? 0 );
		$creator  = (int) ( $row['created_by'] ?? 0 );
		if ( $worker_id <= 0 || $worker_id !== $assignee ) {
			return false; // only the worker self-attests
		}
		if ( $creator > 0 && $creator !== $worker_id ) {
			return false; // a distinct originator can close it
		}
		if ( class_exists( 'ZDZ_Hierarchy' ) ) {
			$lead = (int) ZDZ_Hierarchy::get_lead( $worker_id );
			if ( $lead > 0 && $lead !== $worker_id ) {
				return false; // a distinct crew lead can close it
			}
		}
		return true;
	}

	/**
	 * Is a solo single-party attestation permitted for this job + actor? Default:
	 * a genuine solo case, OR the org has declared itself a solo operator
	 * (option zjob_solo_operator). Filterable so a business can set its own policy.
	 * When a distinct second party exists this returns false — the two-party close
	 * stands (safety floor).
	 */
	public static function self_attest_allowed( array $row, int $actor_id ): bool {
		$default = self::is_genuine_solo( $row, $actor_id ) || (bool) get_option( 'zjob_solo_operator', false );
		return (bool) apply_filters( 'zdz_job_allow_self_attest', $default, $row, $actor_id );
	}

	/**
	 * Is worker self-scheduling permitted org-wide (the `workers_may_self_schedule`
	 * capability)? Default: single-operator mode is on (option zjob_solo_operator), OR
	 * the dedicated option (zjob_workers_may_self_schedule) is set. Filterable via
	 * `zdz_job_workers_may_self_schedule` so a business can set its own policy.
	 *
	 * OFF by default -> the dispatcher-only schedule rule stands (see
	 * actor_can_self_schedule(), which is the per-row gate that consumes this).
	 */
	public static function workers_may_self_schedule(): bool {
		$default = (bool) get_option( 'zjob_solo_operator', false )
			|| (bool) get_option( 'zjob_workers_may_self_schedule', false );
		return (bool) apply_filters( 'zdz_job_workers_may_self_schedule', $default );
	}

	/* =======================================================================
	 * READ
	 * ======================================================================= */

	/** Fetch one row by id (assoc), or null. */
	public static function get( int $id ): ?array {
		global $wpdb;
		if ( $id <= 0 ) {
			return null;
		}
		$table = ZJOB_DB::table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
		return $row ?: null;
	}

	/**
	 * List jobs an actor may see. Admins see all; a crew lead sees their crew's
	 * (+ their own); everyone else sees only jobs they created or are assigned.
	 * Optionally filter by status.
	 *
	 * @return array<int, array>
	 */
	public static function list_for( int $actor_id, string $status_filter = '' ): array {
		global $wpdb;
		if ( $actor_id <= 0 ) {
			return [];
		}
		$table = ZJOB_DB::table();

		$where = [];
		$args  = [];

		$is_admin = class_exists( 'ZDZ_Hierarchy' ) && ZDZ_Hierarchy::is_admin( $actor_id );
		if ( ! $is_admin ) {
			$ids = [ $actor_id ];
			if ( class_exists( 'ZDZ_Hierarchy' ) ) {
				$ids = ZDZ_Hierarchy::overseeable_user_ids( $actor_id );
			}
			$ids = array_values( array_unique( array_map( 'intval', $ids ) ) );
			if ( ! in_array( $actor_id, $ids, true ) ) {
				$ids[] = $actor_id;
			}
			$crew = array_values( array_diff( $ids, [ $actor_id ] ) );

			$clauses = [];
			// (a) Anything I originated — my backlog, scheduled or not.
			$ph_all    = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			$clauses[] = "created_by IN ({$ph_all})";
			$args      = array_merge( $args, $ids );
			// (b) Work I assigned out to my crew (supervisor oversight) — any status.
			if ( ! empty( $crew ) ) {
				$ph_crew   = implode( ',', array_fill( 0, count( $crew ), '%d' ) );
				$clauses[] = "assigned_user_id IN ({$ph_crew})";
				$args      = array_merge( $args, $crew );
			}
			// (c) Work assigned TO ME as a worker. A worker can SEE an incoming job
			//     before it is scheduled (Future view); present-tense actionability
			//     stays scheduled-only via bucket_for(). Filterable to revert.
			if ( apply_filters( 'zdz_job_worker_sees_future', true ) ) {
				$clauses[] = '( assigned_user_id = %d )';
			} else {
				$clauses[] = '( assigned_user_id = %d AND scheduled_appt_id > 0 )';
			}
			$args[] = $actor_id;

			$where[] = '( ' . implode( ' OR ', $clauses ) . ' )';
		}

		if ( $status_filter !== '' && in_array( $status_filter, self::STATUSES, true ) ) {
			$where[] = 'status = %s';
			$args[]  = $status_filter;
		}

		$sql = "SELECT * FROM {$table}";
		if ( $where ) {
			$sql .= ' WHERE ' . implode( ' AND ', $where );
		}
		$sql .= ' ORDER BY created_at DESC LIMIT 200';

		if ( $args ) {
			$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );
		} else {
			$rows = $wpdb->get_results( $sql, ARRAY_A );
		}
		return $rows ?: [];
	}

	/* =======================================================================
	 * ESTIMATE POINTERS + ROLLUP
	 * ======================================================================= */

	/**
	 * A stable signature for an estimate line item — used to dedup the picker and
	 * detect orphans. MUST match the JS `lineSig()` in estimate-bridge.js exactly.
	 */
	public static function line_sig( string $desc, string $sub, string $dims, int $qty ): string {
		$norm = static function ( $s ) {
			return preg_replace( '/\s+/', ' ', strtolower( trim( (string) $s ) ) );
		};
		return substr( $norm( $desc . ' ' . $sub ) . '||' . $norm( $dims ) . '||q' . $qty, 0, 191 );
	}

	/** All jobs created from a given estimate (compact fields for the rollup). */
	public static function jobs_for_estimate( int $estimate_id ): array {
		global $wpdb;
		if ( $estimate_id <= 0 ) {
			return [];
		}
		$table = ZJOB_DB::table();
		$rows  = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, component, status, assigned_user_id, estimate_line_index, estimate_line_sig, crm_child_lead_id
			 FROM {$table} WHERE estimate_id = %d ORDER BY id ASC",
			$estimate_id
		), ARRAY_A );
		return $rows ?: [];
	}

	/**
	 * Rollup of an estimate's jobs: per-status counts, an "all active jobs done"
	 * flag, and the per-job list (with the line pointer so the picker can mark
	 * already-sent lines + flag orphans).
	 */
	public static function rollup_for_estimate( int $estimate_id ): array {
		$jobs   = self::jobs_for_estimate( $estimate_id );
		$counts = [ 'open' => 0, 'in_progress' => 0, 'done' => 0, 'cancelled' => 0 ];
		foreach ( $jobs as $j ) {
			$s = (string) $j['status'];
			if ( isset( $counts[ $s ] ) ) {
				$counts[ $s ]++;
			}
		}
		$active   = count( $jobs ) - $counts['cancelled'];
		$all_done = ( $active > 0 && $counts['done'] === $active );
		$list = array_map( static function ( $j ) {
			$u = get_userdata( (int) $j['assigned_user_id'] );
			return [
				'id'         => (int) $j['id'],
				'component'  => (string) $j['component'],
				'status'     => (string) $j['status'],
				'assignee'   => $u ? $u->display_name : ( '#' . (int) $j['assigned_user_id'] ),
				'line_index' => (int) $j['estimate_line_index'],
				'line_sig'   => (string) $j['estimate_line_sig'],
				'child_lead' => (int) $j['crm_child_lead_id'],
			];
		}, $jobs );
		return [
			'estimate_id' => $estimate_id,
			'total'       => count( $jobs ),
			'counts'      => $counts,
			'all_done'    => $all_done,
			'jobs'        => $list,
		];
	}

	/* =======================================================================
	 * WRITE
	 * ======================================================================= */

	/**
	 * Create a job row (app-side). Does NOT do the CRM write — the caller does that
	 * after this succeeds and stores the child lead id via attach_child_lead().
	 *
	 * @param array $data component, customer_name, source_ref, parent_lead_id,
	 *                    crm_contact_id, assigned_user_id, brand, qty, notes, …
	 * @param int   $actor_id  The person creating the job.
	 * @return int New row id, or 0.
	 */
	public static function create( array $data, int $actor_id ): int {
		global $wpdb;
		$now = current_time( 'mysql', true );

		$assignee = (int) ( $data['assigned_user_id'] ?? 0 );
		if ( $assignee <= 0 || ! get_userdata( $assignee ) ) {
			return 0;
		}

		$component = sanitize_key( (string) ( $data['component'] ?? '' ) );
		if ( $component === '' ) {
			// Item Engine binding: the default job kind (fallback 'other').
			$component = function_exists( 'zjob_default_component' ) ? zjob_default_component() : 'other';
		}

		// Accept both the generalized crm_contact_id and the legacy nutshell_contact_id.
		$contact_id = (int) ( $data['crm_contact_id'] ?? ( $data['nutshell_contact_id'] ?? 0 ) );

		$ok = $wpdb->insert(
			ZJOB_DB::table(),
			[
				'component'         => $component,
				'customer_name'     => sanitize_text_field( (string) ( $data['customer_name'] ?? '' ) ),
				'source_ref'        => sanitize_text_field( (string) ( $data['source_ref'] ?? '' ) ),
				'parent_lead_id'    => (int) ( $data['parent_lead_id'] ?? 0 ),
				'crm_child_lead_id' => 0,
				'crm_contact_id'    => $contact_id,
				'assigned_user_id'  => $assignee,
				'created_by'        => $actor_id,
				'brand'             => sanitize_text_field( (string) ( $data['brand'] ?? '' ) ),
				'qty'               => max( 0, (int) ( $data['qty'] ?? 0 ) ),
				'notes'             => sanitize_textarea_field( (string) ( $data['notes'] ?? '' ) ),
				'customer_business' => sanitize_text_field( (string) ( $data['customer_business'] ?? '' ) ),
				'customer_address'  => sanitize_text_field( (string) ( $data['customer_address'] ?? '' ) ),
				'customer_phone'    => sanitize_text_field( (string) ( $data['customer_phone'] ?? '' ) ),
				'access_notes'      => sanitize_textarea_field( (string) ( $data['access_notes'] ?? '' ) ),
				'estimate_id'       => (int) ( $data['estimate_id'] ?? 0 ),
				'estimate_line_index' => isset( $data['estimate_line_index'] ) ? (int) $data['estimate_line_index'] : -1,
				'estimate_line_sig' => substr( sanitize_text_field( (string) ( $data['estimate_line_sig'] ?? '' ) ), 0, 191 ),
				'status'            => 'open',
				'created_at'        => $now,
				'updated_at'        => $now,
			],
			[ '%s','%s','%s','%d','%d','%d','%d','%d','%s','%d','%s','%s','%s','%s','%s','%d','%d','%s','%s','%s','%s' ]
		);
		if ( ! $ok ) {
			return 0;
		}
		$id = (int) $wpdb->insert_id;

		self::audit( $actor_id, 'job_created',
			sprintf( 'Created %s for %s, assigned to user #%d', $component, (string) ( $data['customer_name'] ?? '' ), $assignee ),
			[ 'job_id' => $id, 'assignee' => $assignee ] );

		return $id;
	}

	/** Store the CRM child-lead id after the CRM write succeeds. */
	public static function attach_child_lead( int $id, int $child_lead_id ): void {
		global $wpdb;
		$wpdb->update(
			ZJOB_DB::table(),
			[ 'crm_child_lead_id' => max( 0, $child_lead_id ), 'updated_at' => current_time( 'mysql', true ) ],
			[ 'id' => $id ],
			[ '%d', '%s' ],
			[ '%d' ]
		);
	}

	/** Reassign a job to a different specialist. Actor must be able to manage it. */
	public static function reassign( int $id, int $new_assignee, int $actor_id ): bool {
		global $wpdb;
		$row = self::get( $id );
		if ( ! $row || ! self::actor_can_manage( $actor_id, $row ) ) {
			return false;
		}
		if ( $new_assignee <= 0 || ! get_userdata( $new_assignee ) ) {
			return false;
		}
		$wpdb->update(
			ZJOB_DB::table(),
			[ 'assigned_user_id' => $new_assignee, 'updated_at' => current_time( 'mysql', true ) ],
			[ 'id' => $id ],
			[ '%d', '%s' ],
			[ '%d' ]
		);
		self::audit( $actor_id, 'job_reassigned',
			sprintf( 'Reassigned job #%d to user #%d', $id, $new_assignee ),
			[ 'job_id' => $id, 'new_assignee' => $new_assignee ] );
		return true;
	}

	/** Set a job's status. Actor must be able to manage it. */
	public static function set_status( int $id, string $status, int $actor_id ): bool {
		global $wpdb;
		if ( ! in_array( $status, self::STATUSES, true ) ) {
			return false;
		}
		$row = self::get( $id );
		if ( ! $row || ! self::actor_can_manage( $actor_id, $row ) ) {
			return false;
		}
		// Two-party integrity:
		//  * `pending_close` is reachable ONLY through the photo-gated worker_complete().
		//  * A plain worker may not jump straight to `done`; that goes through
		//    "mark my part complete" then a distinct close-out (or a solo attestation).
		if ( 'pending_close' === $status ) {
			return false;
		}
		if ( 'done' === $status && ! self::actor_can_schedule( $actor_id, $row ) ) {
			return false;
		}
		$wpdb->update(
			ZJOB_DB::table(),
			[ 'status' => $status, 'updated_at' => current_time( 'mysql', true ) ],
			[ 'id' => $id ],
			[ '%s', '%s' ],
			[ '%d' ]
		);
		self::audit( $actor_id, 'job_status',
			sprintf( 'Set job #%d to %s', $id, $status ),
			[ 'job_id' => $id, 'status' => $status ] );
		return true;
	}

	/**
	 * Worker inbox: record an ETA signal on the worker's own job. 'on_my_way' also
	 * AUTO-STARTS an open job (open -> in_progress, capturing started_at);
	 * 'running_late' flags the dispatcher without changing status. Only the assignee
	 * (or an admin) may signal.
	 *
	 * @return array{ok:bool,error:string,status?:string,eta_status?:string,started?:bool}
	 */
	public static function set_eta( int $id, string $eta, int $actor_id ): array {
		global $wpdb;
		$out = [ 'ok' => false, 'error' => '' ];

		if ( ! in_array( $eta, self::ETA_STATUSES, true ) ) {
			$out['error'] = 'bad_eta';
			return $out;
		}
		$row = self::get( $id );
		if ( ! $row ) {
			$out['error'] = 'not_found';
			return $out;
		}
		if ( class_exists( 'ZDZ_Hierarchy' ) && ZDZ_Hierarchy::is_kiosk( $actor_id ) ) {
			$out['error'] = 'kiosk_forbidden';
			return $out;
		}
		$is_admin = class_exists( 'ZDZ_Hierarchy' ) && ZDZ_Hierarchy::is_admin( $actor_id );
		if ( $actor_id !== (int) ( $row['assigned_user_id'] ?? 0 ) && ! $is_admin ) {
			$out['error'] = 'not_permitted';
			return $out;
		}
		if ( ! in_array( (string) $row['status'], [ 'open', 'in_progress' ], true ) ) {
			$out['error'] = 'bad_state';
			return $out;
		}

		$now     = current_time( 'mysql', true );
		$data    = [ 'eta_status' => $eta, 'eta_at' => $now, 'updated_at' => $now ];
		$fmt     = [ '%s', '%s', '%s' ];
		$started = false;
		if ( 'on_my_way' === $eta && 'open' === (string) $row['status'] ) {
			$data['status']     = 'in_progress';
			$data['started_at'] = $now;
			$fmt[]              = '%s';
			$fmt[]              = '%s';
			$started            = true;
		}
		$wpdb->update( ZJOB_DB::table(), $data, [ 'id' => $id ], $fmt, [ '%d' ] );

		self::audit( $actor_id, 'job_eta',
			sprintf( 'Job #%d ETA: %s%s', $id, $eta, $started ? ' (auto-started)' : '' ),
			[ 'job_id' => $id, 'eta' => $eta, 'started' => $started ] );

		$out['ok']         = true;
		$out['status']     = $started ? 'in_progress' : (string) $row['status'];
		$out['eta_status'] = $eta;
		$out['started']    = $started;
		return $out;
	}

	/* =======================================================================
	 * SCHEDULING (bridges to the Scheduler app)
	 * ======================================================================= */

	/** A job is "scheduled" once it is linked to a Scheduler appointment. */
	public static function is_scheduled( array $row ): bool {
		return (int) ( $row['scheduled_appt_id'] ?? 0 ) > 0;
	}

	/**
	 * Set (or move) a job's time. Only someone who can dispatch may schedule it —
	 * never the plain assignee — UNLESS single-operator mode / self-scheduling is on,
	 * in which case the assignee may set their own time (actor_can_self_schedule()).
	 * Creates/updates a native Scheduler appointment.
	 *
	 * @return array{ok:bool,error:string,appt_id:int,scheduled_start_utc:string,scheduled_tz:string}
	 */
	public static function set_schedule( int $id, string $start_local, int $duration_min, int $actor_id ): array {
		global $wpdb;
		$out = [ 'ok' => false, 'error' => '', 'appt_id' => 0, 'scheduled_start_utc' => '', 'scheduled_tz' => '' ];

		$row = self::get( $id );
		if ( ! $row || ! self::actor_can_self_schedule( $actor_id, $row ) ) {
			$out['error'] = 'not_permitted';
			return $out;
		}
		if ( ! class_exists( 'ZJOB_Scheduler' ) ) {
			$out['error'] = 'scheduler_unavailable';
			return $out;
		}

		$res = ZJOB_Scheduler::schedule_job( $row, $start_local, $duration_min, $actor_id );
		if ( empty( $res['ok'] ) ) {
			$out['error'] = $res['error'] !== '' ? $res['error'] : 'schedule_failed';
			return $out;
		}

		$now = current_time( 'mysql', true );
		$wpdb->update(
			ZJOB_DB::table(),
			[
				'scheduled_appt_id'   => (int) $res['appt_id'],
				'scheduled_start_utc' => $res['start_utc'] !== '' ? $res['start_utc'] : null,
				'scheduled_end_utc'   => $res['end_utc'] !== '' ? $res['end_utc'] : null,
				'scheduled_tz'        => (string) $res['tz'],
				'scheduled_by'        => $actor_id,
				'scheduled_at'        => $now,
				'updated_at'          => $now,
			],
			[ 'id' => $id ],
			[ '%d', '%s', '%s', '%s', '%d', '%s', '%s' ],
			[ '%d' ]
		);

		self::audit( $actor_id, 'job_scheduled',
			sprintf( 'Scheduled job #%d for %s UTC (%s), appt #%d', $id, $res['start_utc'], $res['tz'], $res['appt_id'] ),
			[ 'job_id' => $id, 'appt_id' => (int) $res['appt_id'], 'start_utc' => $res['start_utc'] ] );

		$out['ok']                  = true;
		$out['appt_id']             = (int) $res['appt_id'];
		$out['scheduled_start_utc'] = (string) $res['start_utc'];
		$out['scheduled_tz']        = (string) $res['tz'];
		return $out;
	}

	/**
	 * Clear a job's schedule (delete the linked appointment). Dispatch-gated, with the
	 * same single-operator relaxation as set_schedule() (actor_can_self_schedule()).
	 *
	 * @return array{ok:bool,error:string}
	 */
	public static function clear_schedule( int $id, int $actor_id ): array {
		global $wpdb;
		$out = [ 'ok' => false, 'error' => '' ];

		$row = self::get( $id );
		if ( ! $row || ! self::actor_can_self_schedule( $actor_id, $row ) ) {
			$out['error'] = 'not_permitted';
			return $out;
		}

		if ( class_exists( 'ZJOB_Scheduler' ) ) {
			$res = ZJOB_Scheduler::unschedule_job( $row, $actor_id );
			if ( empty( $res['ok'] ) ) {
				$out['error'] = $res['error'] !== '' ? $res['error'] : 'unschedule_failed';
				return $out;
			}
		}

		$now = current_time( 'mysql', true );
		$wpdb->update(
			ZJOB_DB::table(),
			[
				'scheduled_appt_id'   => 0,
				'scheduled_start_utc' => null,
				'scheduled_end_utc'   => null,
				'scheduled_tz'        => '',
				'scheduled_by'        => 0,
				'scheduled_at'        => null,
				'updated_at'          => $now,
			],
			[ 'id' => $id ],
			[ '%d', '%s', '%s', '%s', '%d', '%s', '%s' ],
			[ '%d' ]
		);

		self::audit( $actor_id, 'job_unscheduled', sprintf( 'Cleared schedule on job #%d', $id ), [ 'job_id' => $id ] );
		$out['ok'] = true;
		return $out;
	}

	/* =======================================================================
	 * TWO-PARTY COMPLETION (worker marks their part complete)
	 * ======================================================================= */

	/**
	 * The worker marks THEIR part complete: mandatory finish photos + the location
	 * fix taken at capture. Moves the job to `pending_close` (awaiting close-out).
	 * Only the assignee or an admin may call this.
	 *
	 * @param int[] $media_ids  Media Library ids of the finish photos (>= min).
	 * @param array $gps        { lat, lng, accuracy } from navigator.geolocation.
	 * @return array{ok:bool,error:string,status?:string,verified?:bool,media_ids?:int[],location?:string}
	 */
	public static function worker_complete( int $id, array $media_ids, array $gps, int $actor_id ): array {
		global $wpdb;
		$out = [ 'ok' => false, 'error' => '' ];

		$row = self::get( $id );
		if ( ! $row ) {
			$out['error'] = 'not_found';
			return $out;
		}
		if ( class_exists( 'ZDZ_Hierarchy' ) && ZDZ_Hierarchy::is_kiosk( $actor_id ) ) {
			$out['error'] = 'kiosk_forbidden';
			return $out;
		}
		$assignee = (int) ( $row['assigned_user_id'] ?? 0 );
		$is_admin = class_exists( 'ZDZ_Hierarchy' ) && ZDZ_Hierarchy::is_admin( $actor_id );
		if ( $actor_id !== $assignee && ! $is_admin ) {
			$out['error'] = 'not_permitted';
			return $out;
		}
		if ( ! in_array( (string) $row['status'], [ 'open', 'in_progress' ], true ) ) {
			$out['error'] = 'bad_state';
			return $out;
		}

		$media_ids = array_values( array_unique( array_filter(
			array_map( 'intval', $media_ids ),
			static function ( $n ) { return $n > 0; }
		) ) );
		if ( count( $media_ids ) < self::min_finish_photos() ) {
			$out['error'] = 'photos_required';
			return $out;
		}

		$lat = ( isset( $gps['lat'] ) && $gps['lat'] !== '' ) ? round( (float) $gps['lat'], 7 ) : null;
		$lng = ( isset( $gps['lng'] ) && $gps['lng'] !== '' ) ? round( (float) $gps['lng'], 7 ) : null;
		$acc = ( isset( $gps['accuracy'] ) && $gps['accuracy'] !== '' ) ? max( 0, (int) $gps['accuracy'] ) : null;

		$loc      = self::verify_location( $row, $lat, $lng, $acc );
		$verified = ! empty( $loc['verified'] ) ? 1 : 0;

		$now      = current_time( 'mysql', true );
		// Start the auto-close countdown the moment the worker finishes.
		$deadline = gmdate( 'Y-m-d H:i:s', time() + self::close_max_days() * DAY_IN_SECONDS );
		$wpdb->update(
			ZJOB_DB::table(),
			[
				'status'              => 'pending_close',
				'worker_done_at'      => $now,
				'finish_media_ids'    => wp_json_encode( $media_ids ),
				'finish_gps_lat'      => $lat,
				'finish_gps_lng'      => $lng,
				'finish_gps_accuracy' => $acc,
				'finish_verified'     => $verified,
				'close_deadline'      => $deadline,
				'updated_at'          => $now,
			],
			[ 'id' => $id ],
			[ '%s', '%s', '%s', '%f', '%f', '%d', '%d', '%s', '%s' ],
			[ '%d' ]
		);

		self::audit( $actor_id, 'job_worker_complete',
			sprintf( 'Marked own part complete on job #%d (%d photo(s), location %s via %s)',
				$id, count( $media_ids ), $verified ? 'verified' : 'unverified', (string) ( $loc['method'] ?? 'none' ) ),
			[ 'job_id' => $id, 'media_ids' => $media_ids, 'verified' => (bool) $verified, 'location_method' => (string) ( $loc['method'] ?? 'none' ) ] );

		$out['ok']        = true;
		$out['status']    = 'pending_close';
		$out['verified']  = (bool) $verified;
		$out['media_ids'] = $media_ids;
		$out['location']  = (string) ( $loc['place'] ?? '' );
		return $out;
	}

	/**
	 * The two-party close-out: pending_close -> done, recorded as `two_party`
	 * assurance. Only actor_can_close (a distinct party — never the plain worker)
	 * may call this, and only from `pending_close`. The system auto-close sweep
	 * ($is_auto) runs unattended and is recorded as `system_auto`.
	 *
	 * @return array{ok:bool,error:string,status?:string,assurance?:string,auto?:bool}
	 */
	public static function close_job( int $id, int $actor_id, bool $is_auto = false ): array {
		global $wpdb;
		$out = [ 'ok' => false, 'error' => '' ];

		$row = self::get( $id );
		if ( ! $row ) {
			$out['error'] = 'not_found';
			return $out;
		}
		if ( ! $is_auto ) {
			if ( class_exists( 'ZDZ_Hierarchy' ) && ZDZ_Hierarchy::is_kiosk( $actor_id ) ) {
				$out['error'] = 'kiosk_forbidden';
				return $out;
			}
			if ( ! self::actor_can_close( $actor_id, $row ) ) {
				$out['error'] = 'not_permitted'; // the plain worker cannot close their own
				return $out;
			}
		}
		if ( 'pending_close' !== (string) $row['status'] ) {
			$out['error'] = 'not_pending_close';
			return $out;
		}

		$now       = current_time( 'mysql', true );
		$closed_by = $is_auto ? 0 : $actor_id;
		$assurance = $is_auto ? self::ASSURANCE_SYSTEM_AUTO : self::ASSURANCE_TWO_PARTY;
		$wpdb->update(
			ZJOB_DB::table(),
			[ 'status' => 'done', 'closed_at' => $now, 'closed_by' => $closed_by, 'assurance_level' => $assurance, 'close_deadline' => null, 'updated_at' => $now ],
			[ 'id' => $id ],
			[ '%s', '%s', '%d', '%s', '%s', '%s' ],
			[ '%d' ]
		);

		if ( $is_auto ) {
			// A system terminal transition is always attributed and logged as a disposition.
			self::audit( 0, 'job_auto_closed',
				sprintf( 'Auto-closed job #%d — %d-day close window reached with no sign-off', $id, self::close_max_days() ),
				[ 'job_id' => $id, 'auto' => true, 'assurance' => $assurance ] );
			self::disposition( 'job_auto_closed', 0,
				sprintf( 'Job #%d auto-closed by the system (no human sign-off)', $id ),
				[ 'job_id' => $id, 'assurance' => $assurance ] );
		} else {
			self::audit( $actor_id, 'job_closed',
				sprintf( 'Closed out job #%d (two-party sign-off)', $id ),
				[ 'job_id' => $id, 'assurance' => $assurance ] );
		}

		$out['ok']        = true;
		$out['status']    = 'done';
		$out['assurance'] = $assurance;
		$out['auto']      = $is_auto;
		return $out;
	}

	/**
	 * SOLO-OPERATOR SINGLE-PARTY ATTESTATION (safety floor).
	 *
	 * When no distinct second party exists to sign off, the worker may satisfy the
	 * two-party close by giving a RECORDED single-party attestation. This raises the
	 * job to `done` with assurance_level = `single_party_attested` — a distinct value
	 * that is NEVER laundered into `two_party`, so a downstream consumer can still
	 * require a genuine two-party close. The attestation reason is mandatory, and the
	 * whole event is logged as a disposition (and fired on `zdz_flow_disposition`).
	 *
	 * @return array{ok:bool,error:string,status?:string,assurance?:string}
	 */
	public static function worker_self_attest_close( int $id, string $reason, int $actor_id ): array {
		global $wpdb;
		$out = [ 'ok' => false, 'error' => '' ];

		$reason = trim( $reason );
		if ( '' === $reason ) {
			$out['error'] = 'reason_required'; // the attestation must be recorded
			return $out;
		}
		$row = self::get( $id );
		if ( ! $row ) {
			$out['error'] = 'not_found';
			return $out;
		}
		if ( class_exists( 'ZDZ_Hierarchy' ) && ZDZ_Hierarchy::is_kiosk( $actor_id ) ) {
			$out['error'] = 'kiosk_forbidden';
			return $out;
		}
		// Only the worker who did the job self-attests.
		if ( $actor_id !== (int) ( $row['assigned_user_id'] ?? 0 ) ) {
			$out['error'] = 'not_permitted';
			return $out;
		}
		if ( 'pending_close' !== (string) $row['status'] ) {
			$out['error'] = 'not_pending_close';
			return $out;
		}
		// A distinct second party exists -> the two-party close stands (safety floor).
		if ( ! self::self_attest_allowed( $row, $actor_id ) ) {
			$out['error'] = 'two_party_required';
			return $out;
		}

		$now = current_time( 'mysql', true );
		$wpdb->update(
			ZJOB_DB::table(),
			[
				'status'             => 'done',
				'closed_at'          => $now,
				'closed_by'          => $actor_id,
				'assurance_level'    => self::ASSURANCE_SINGLE,
				'attestation_reason' => $reason,
				'attested_by'        => $actor_id,
				'attested_at'        => $now,
				'close_deadline'     => null,
				'updated_at'         => $now,
			],
			[ 'id' => $id ],
			[ '%s', '%s', '%d', '%s', '%s', '%d', '%s', '%s', '%s' ],
			[ '%d' ]
		);

		self::audit( $actor_id, 'job_self_attested',
			sprintf( 'Solo single-party attestation closed job #%d. Reason: %s', $id, $reason ),
			[ 'job_id' => $id, 'assurance' => self::ASSURANCE_SINGLE, 'reason' => $reason ] );
		// Safety floor: the attestation is logged as a disposition.
		self::disposition( 'single_party_attestation', $actor_id,
			sprintf( 'Job #%d closed by a solo operator with a recorded single-party attestation (not two-party).', $id ),
			[ 'job_id' => $id, 'assurance' => self::ASSURANCE_SINGLE, 'reason' => $reason ] );

		$out['ok']        = true;
		$out['status']    = 'done';
		$out['assurance'] = self::ASSURANCE_SINGLE;
		return $out;
	}

	/**
	 * Extend a pending_close job's auto-close deadline. Requires an authorized
	 * closer AND a written reason. The deadline may be pushed out any number of
	 * times, but never silently.
	 *
	 * @return array{ok:bool,error:string,close_deadline?:string,count?:int}
	 */
	public static function extend_close( int $id, string $reason, int $days, int $actor_id ): array {
		global $wpdb;
		$out = [ 'ok' => false, 'error' => '' ];

		$reason = trim( $reason );
		if ( '' === $reason ) {
			$out['error'] = 'reason_required';
			return $out;
		}
		$row = self::get( $id );
		if ( ! $row ) {
			$out['error'] = 'not_found';
			return $out;
		}
		if ( class_exists( 'ZDZ_Hierarchy' ) && ZDZ_Hierarchy::is_kiosk( $actor_id ) ) {
			$out['error'] = 'kiosk_forbidden';
			return $out;
		}
		if ( ! self::actor_can_close( $actor_id, $row ) ) {
			$out['error'] = 'not_permitted';
			return $out;
		}
		if ( 'pending_close' !== (string) $row['status'] ) {
			$out['error'] = 'not_pending_close';
			return $out;
		}

		$days = (int) $days;
		if ( $days <= 0 ) {
			$days = self::close_extend_default_days();
		}
		$days = min( $days, self::CLOSE_EXTEND_CEILING_DAYS );

		$base_ts  = strtotime( (string) ( $row['close_deadline'] ?? '' ) . ' UTC' );
		$now_ts   = time();
		$from_ts  = ( $base_ts && $base_ts > $now_ts ) ? $base_ts : $now_ts;
		$deadline = gmdate( 'Y-m-d H:i:s', $from_ts + $days * DAY_IN_SECONDS );
		$now      = current_time( 'mysql', true );
		$count    = (int) ( $row['close_extended_count'] ?? 0 ) + 1;

		$wpdb->update(
			ZJOB_DB::table(),
			[
				'close_deadline'       => $deadline,
				'close_extended_count' => $count,
				'close_extended_by'    => $actor_id,
				'close_extended_at'    => $now,
				'close_extend_reason'  => $reason,
				'updated_at'           => $now,
			],
			[ 'id' => $id ],
			[ '%s', '%d', '%d', '%s', '%s', '%s' ],
			[ '%d' ]
		);

		self::audit( $actor_id, 'job_close_extended',
			sprintf( 'Extended close deadline of job #%d by %d day(s) to %s UTC. Reason: %s', $id, $days, $deadline, $reason ),
			[ 'job_id' => $id, 'days' => $days, 'deadline' => $deadline, 'reason' => $reason ] );

		$out['ok']             = true;
		$out['close_deadline'] = $deadline;
		$out['count']          = $count;
		return $out;
	}

	/** Pending_close jobs whose auto-close deadline has passed (for the daily sweep). */
	public static function due_for_auto_close( int $limit = 50 ): array {
		global $wpdb;
		$table = ZJOB_DB::table();
		$now   = current_time( 'mysql', true );
		$limit = max( 1, min( 200, $limit ) );
		$rows  = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$table}
			 WHERE status = 'pending_close' AND close_deadline IS NOT NULL AND close_deadline <= %s
			 ORDER BY close_deadline ASC LIMIT %d",
			$now, $limit
		), ARRAY_A );
		return $rows ?: [];
	}

	/** How many pending_close jobs the actor may CLOSE (the queue badge count). */
	public static function count_closable_pending( int $actor_id ): int {
		$rows = self::list_for( $actor_id, 'pending_close' );
		$n = 0;
		foreach ( $rows as $r ) {
			if ( self::actor_can_close( $actor_id, $r ) ) {
				$n++;
			} elseif ( (int) ( $r['assigned_user_id'] ?? 0 ) === $actor_id
				&& self::self_attest_allowed( $r, $actor_id ) ) {
				$n++; // a solo operator's own job awaiting a recorded single-party attestation
			}
		}
		return $n;
	}

	/**
	 * Role-relative inbox bucket for $row from $viewer's perspective:
	 *   present = the viewer's move, now; future = waiting on someone else;
	 *   past    = terminal, or the baton has already passed the viewer.
	 */
	public static function bucket_for( array $row, int $viewer ): string {
		$status = (string) ( $row['status'] ?? '' );
		if ( 'done' === $status || 'cancelled' === $status ) {
			return 'past';
		}
		$is_mine = ( (int) ( $row['assigned_user_id'] ?? 0 ) === $viewer );
		if ( 'pending_close' === $status ) {
			if ( self::actor_can_close( $viewer, $row ) ) {
				return 'present';
			}
			// A solo operator whose own job awaits attestation acts now.
			if ( $is_mine && self::self_attest_allowed( $row, $viewer ) ) {
				return 'present';
			}
			return $is_mine ? 'past' : 'future';
		}
		$scheduled = ( (int) ( $row['scheduled_appt_id'] ?? 0 ) > 0 );
		if ( ! $scheduled ) {
			// Self-schedule aware: a solo operator's own unscheduled job is their move now.
			return self::actor_can_self_schedule( $viewer, $row ) ? 'present' : 'future';
		}
		return $is_mine ? 'present' : 'future';
	}

	/* =======================================================================
	 * HELPERS
	 * ======================================================================= */

	/**
	 * Decide whether a finish was on-site.
	 *
	 * Service Area binding: a real proximity/geofence check (haversine of the fix
	 * against the geocoded site address) belongs to the Service Area / Flow service,
	 * which does not exist yet. We bind it via the `zdz_job_location_verified` filter
	 * (return a bool to decide authoritatively) and, absent that, fall back to the
	 * browser's accuracy gate. The theme geocoder (ZDZ_Media_Geocoder) reverse-
	 * geocodes the fix for provenance/display. NOTE: the accuracy gate is a
	 * confidence-radius check, not a geofence — a clean fix far from the site still
	 * passes it; wire `zdz_job_location_verified` to a proximity check to close that.
	 *
	 * @return array{verified:bool,method:string,place:string}
	 */
	private static function verify_location( array $row, ?float $lat, ?float $lng, ?int $acc ): array {
		// 1) Authoritative binding for a real Service Area / proximity gate.
		$pre = apply_filters( 'zdz_job_location_verified', null, $row, $lat, $lng, $acc );
		$place = '';
		if ( null !== $lat && null !== $lng && class_exists( 'ZDZ_Media_Geocoder' ) ) {
			$resolved = ZDZ_Media_Geocoder::resolve( (float) $lat, (float) $lng );
			if ( is_array( $resolved ) && ! empty( $resolved['label'] ) ) {
				$place = (string) $resolved['label'];
			}
		}
		if ( is_bool( $pre ) ) {
			return [ 'verified' => $pre, 'method' => 'proximity', 'place' => $place ];
		}
		// 2) Fallback: the browser accuracy gate (a quality signal, not a geofence).
		$verified = ( null !== $lat && null !== $lng && null !== $acc && $acc <= self::gps_accuracy_max_m() );
		return [ 'verified' => $verified, 'method' => 'accuracy', 'place' => $place ];
	}

	/** Best-effort audit into the platform's shared admin-dashboard log + the activity log. */
	private static function audit( int $actor_id, string $action, string $message, array $context = [] ): void {
		if ( class_exists( '\Zorderz\ZDZ_Admin_Dashboard' )
			&& method_exists( '\Zorderz\ZDZ_Admin_Dashboard', 'log_action' ) ) {
			\Zorderz\ZDZ_Admin_Dashboard::log_action( $actor_id, $action, $message, 'jobs', $context );
		}
		if ( class_exists( 'ZJOB_User_Log' ) && method_exists( 'ZJOB_User_Log', 'log' ) ) {
			ZJOB_User_Log::log( $actor_id, $action, $message, 'jobs', $context );
		}
	}

	/**
	 * Record a disposition (a drop / refusal / fallback / attestation). Nothing is
	 * silent: the disposition is audited AND fired on `zdz_flow_disposition` so the
	 * Core Flow service (when it lands) can consume the same event. This is the
	 * binding point for the Flow disposition ledger.
	 */
	private static function disposition( string $code, int $actor_id, string $message, array $context = [] ): void {
		$context = array_merge( [ 'code' => $code, 'app' => 'jobs' ], $context );
		/**
		 * Fires for every Jobs disposition. The future Core Flow service subscribes
		 * here to write the disposition ledger; until then it is audited below.
		 */
		do_action( 'zdz_flow_disposition', $code, $context, $actor_id );
		self::audit( $actor_id, 'disposition_' . $code, $message, $context );
	}
}
