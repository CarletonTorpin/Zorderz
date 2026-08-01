<?php
/**
 * ZL Lead Assignment — per-lead ownership by the app user.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHAT THIS IS (Phase 1 of the per-user-leads program)
 * ─────────────────────────────────────────────────────────────────────────────
 * This class makes a *the app user* the owner of a lead, and makes that
 * ownership the **source of truth for assignment** — TS's own checkable data,
 * usable with NO Nutshell account. (Nutshell remains authoritative only for the
 * CRM pipeline *stage*; that's a separate, later concern. See
 * ZL-PER-USER-LEADS-AND-PIPELINE-PLAN-v1.md §1.)
 *
 * The model, per the CRM-integration best practice "choose one system as master
 * and use a source flag to prevent overwrites":
 *   • TS owns WHO the lead is assigned to + the rep's task state.
 *   • Nutshell owns the pipeline stage (advanced automatically on Nutshell).
 *   • Default data flow is TS → Nutshell (push); we never let a CRM read
 *     overwrite TS assignment.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * AUTHORITATIVE vs. FUZZY (the rigor you asked for)
 * ─────────────────────────────────────────────────────────────────────────────
 * Assignment going forward is EXPLICIT: an admin picks a WP user; we store that
 * user id on the lead row (assigned_user_id) with a timestamp and the assigning
 * admin. We do NOT infer ownership from a fuzzy name/initial match at act-time.
 *
 * The legacy fuzzy resolver (ZL_Lead_Interaction::resolve_salesperson_code) maps
 * a *code* like "NW" → a user by username/display-name/initials. That fuzziness
 * is fine for a one-time BACKFILL SEED of pre-existing rows (backfill_from_batches)
 * but is deliberately NOT the authoritative path. New leads get an explicit owner.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * SECURITY: the ownership gate (closes a real gap)
 * ─────────────────────────────────────────────────────────────────────────────
 * Today several rep-facing handlers authorize on "any app user" (zdz_access_app).
 * current_user_can_act_on_lead() is the gate the handlers should call: an admin/
 * operator may act on any lead; a salesperson may act ONLY on a lead assigned to
 * them. On the shared kiosk (ts_general) the per-user lead view is suppressed —
 * a kiosk is not "a salesperson" (most-restrictive-wins).
 *
 * @package Zorderz\Leads
 * @since   2.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZL_Lead_Assignment {

	/** Roles that may see/act on ALL leads (not just their own). */
	const ADMIN_ROLES = array( 'zdz_owner', 'zdz_admin', 'zdz_operator', 'administrator' );

	/* ═══════════════════════════════════════════════════════════════════════
	 * ROLE / GATE HELPERS
	 * ═══════════════════════════════════════════════════════════════════════ */

	/**
	 * Is this user an admin/operator for lead purposes (sees everything)?
	 *
	 * @param int|null $user_id Defaults to current user.
	 * @return bool
	 */
	public static function is_lead_admin( $user_id = null ): bool {
		$user_id = $user_id ? (int) $user_id : get_current_user_id();
		if ( ! $user_id ) {
			return false;
		}
		// manage_options always counts.
		if ( user_can( $user_id, 'manage_options' ) ) {
			return true;
		}
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return false;
		}
		foreach ( (array) $user->roles as $r ) {
			if ( in_array( $r, self::ADMIN_ROLES, true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Is this user on the shared-kiosk tier (ts_general)? A kiosk is never
	 * treated as an individual salesperson, so per-user lead views are hidden.
	 *
	 * @param int|null $user_id
	 * @return bool
	 */
	public static function is_kiosk( $user_id = null ): bool {
		$user_id = $user_id ? (int) $user_id : get_current_user_id();
		$user    = $user_id ? get_userdata( $user_id ) : null;
		return $user && in_array( 'zdz_general', (array) $user->roles, true );
	}

	/**
	 * THE OWNERSHIP GATE. May $user_id act on lead $lead_id?
	 *  - kiosk            → never (no per-user acting on the shared device)
	 *  - admin/operator   → yes, any lead
	 *  - salesperson      → only if the lead is assigned to them
	 *
	 * @param int      $lead_id
	 * @param int|null $user_id Defaults to current user.
	 * @return bool
	 */
	public static function current_user_can_act_on_lead( int $lead_id, $user_id = null ): bool {
		$user_id = $user_id ? (int) $user_id : get_current_user_id();
		if ( ! $user_id || $lead_id <= 0 ) {
			return false;
		}
		if ( self::is_kiosk( $user_id ) ) {
			return false;
		}
		if ( self::is_lead_admin( $user_id ) ) {
			return true;
		}
		// Salesperson: must own the lead.
		return self::get_lead_owner( $lead_id ) === $user_id;
	}

	/* ═══════════════════════════════════════════════════════════════════════
	 * READ
	 * ═══════════════════════════════════════════════════════════════════════ */

	/**
	 * The WP user id a lead is assigned to (0 if unassigned / not found).
	 *
	 * @param int $lead_id
	 * @return int
	 */
	public static function get_lead_owner( int $lead_id ): int {
		global $wpdb;
		$val = $wpdb->get_var( $wpdb->prepare(
			"SELECT assigned_user_id FROM {$wpdb->prefix}zl_leads WHERE id = %d",
			$lead_id
		) );
		return $val ? (int) $val : 0;
	}

	/**
	 * Count a user's assigned leads, optionally only "new today" / only pending.
	 * Used by the dashboard action-items tile.
	 *
	 * @param int   $user_id
	 * @param array $opts { new_today?:bool, pending_only?:bool }
	 * @return int
	 */
	public static function count_assigned( int $user_id, array $opts = array() ): int {
		global $wpdb;
		if ( $user_id <= 0 ) {
			return 0;
		}
		$where = array( 'assigned_user_id = %d' );
		$args  = array( $user_id );

		if ( ! empty( $opts['pending_only'] ) ) {
			$where[] = "contact_status = 'pending'";
		}
		if ( ! empty( $opts['new_today'] ) ) {
			// Site-local midnight boundary (consistent with the TSA tz fix):
			// compare assigned_at (stored UTC) against today's local-midnight in UTC.
			$midnight_local = current_time( 'Y-m-d' ) . ' 00:00:00';
			$midnight_utc   = get_gmt_from_date( $midnight_local );
			$where[]        = 'assigned_at >= %s';
			$args[]         = $midnight_utc;
		}

		$sql = "SELECT COUNT(*) FROM {$wpdb->prefix}zl_leads WHERE " . implode( ' AND ', $where );
		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $args ) );
	}

	/* ═══════════════════════════════════════════════════════════════════════
	 * WRITE (explicit assignment — admin/operator only)
	 * ═══════════════════════════════════════════════════════════════════════ */

	/**
	 * Assign one or more leads to a TS user. Authoritative, explicit, audited.
	 *
	 * Caller MUST have already verified the actor is a lead admin (the AJAX
	 * handler does this); this method also re-checks defensively.
	 *
	 * @param int[] $lead_ids       Local lead ids to assign.
	 * @param int   $assignee_id    The WP user to assign them to (0 = unassign).
	 * @param int   $actor_id       The admin doing the assigning (for audit).
	 * @return array { success:bool, assigned:int, error:string }
	 */
	public static function assign( array $lead_ids, int $assignee_id, int $actor_id = 0 ): array {
		global $wpdb;

		$actor_id = $actor_id ?: get_current_user_id();
		if ( ! self::is_lead_admin( $actor_id ) ) {
			return array( 'success' => false, 'assigned' => 0, 'error' => 'forbidden' );
		}

		$lead_ids = array_values( array_unique( array_filter( array_map( 'intval', $lead_ids ) ) ) );
		if ( empty( $lead_ids ) ) {
			return array( 'success' => false, 'assigned' => 0, 'error' => 'no_leads' );
		}

		// Unassign path (assignee 0): clear the columns.
		if ( $assignee_id <= 0 ) {
			$assigned = 0;
			foreach ( $lead_ids as $lid ) {
				$assigned += (int) $wpdb->update(
					$wpdb->prefix . 'zl_leads',
					array( 'assigned_user_id' => null, 'assigned_at' => null, 'assigned_by' => $actor_id ),
					array( 'id' => $lid ),
					array( '%d', '%s', '%d' ),
					array( '%d' )
				);
			}
			return array( 'success' => true, 'assigned' => $assigned, 'error' => '' );
		}

		// Validate the assignee is a real user who can use the Leads app.
		$assignee = get_userdata( $assignee_id );
		if ( ! $assignee ) {
			return array( 'success' => false, 'assigned' => 0, 'error' => 'bad_assignee' );
		}

		$now_utc  = current_time( 'mysql', true );
		$assigned = 0;
		foreach ( $lead_ids as $lid ) {
			$ok = $wpdb->update(
				$wpdb->prefix . 'zl_leads',
				array(
					'assigned_user_id' => $assignee_id,
					'assigned_at'      => $now_utc,
					'assigned_by'      => $actor_id,
				),
				array( 'id' => $lid ),
				array( '%d', '%s', '%d' ),
				array( '%d' )
			);
			if ( $ok ) {
				$assigned++;
			}
		}

		// Audit trail via the theme's admin-dashboard logger when available
		// (the same audit log the rest of the platform writes to).
		if ( $assigned > 0 && class_exists( '\Zorderz\ZDZ_Admin_Dashboard' )
			&& method_exists( '\Zorderz\ZDZ_Admin_Dashboard', 'log_action' ) ) {
			\Zorderz\ZDZ_Admin_Dashboard::log_action(
				$actor_id,
				'lead_assignment',
				sprintf( 'Assigned %d lead(s) to %s (#%d)', $assigned, $assignee->display_name, $assignee_id ),
				'leads',
				array( 'lead_ids' => $lead_ids, 'assignee_id' => $assignee_id )
			);
		}

		// Notify (busts the assignee's cached dashboard counts, etc.).
		if ( $assigned > 0 ) {
			do_action( 'zl_leads_assigned', $assignee_id, $lead_ids );
		}

		return array( 'success' => true, 'assigned' => $assigned, 'error' => '' );
	}

	/* ═══════════════════════════════════════════════════════════════════════
	 * CODE ↔ USER MAP (hardened; used for backfill + admin convenience)
	 * ═══════════════════════════════════════════════════════════════════════ */

	/**
	 * Resolve a salesperson CODE (e.g. "NW") to a WP user id, explicitly.
	 * Order of authority (most explicit first):
	 *   1. A user whose 'zl_salesperson_code' meta EXACTLY equals the code.
	 *   2. A user whose 'zdz_salesperson_initials' meta exactly equals the code.
	 *   3. (last resort) the legacy fuzzy resolver's inverse — username match.
	 * Returns 0 if unresolved. This is intentionally stricter than the legacy
	 * fuzzy resolver: exact-meta wins, fuzzy is the final fallback only.
	 *
	 * @param string $code
	 * @return int WP user id, or 0.
	 */
	public static function resolve_code_to_user( string $code ): int {
		$code = trim( $code );
		if ( $code === '' ) {
			return 0;
		}

		// 1. Exact zl_salesperson_code meta.
		$users = get_users( array(
			'meta_key'   => 'zl_salesperson_code',
			'meta_value' => $code,
			'number'     => 1,
			'fields'     => 'ID',
		) );
		if ( ! empty( $users ) ) {
			return (int) $users[0];
		}

		// 2. Exact zdz_salesperson_initials meta (case-insensitive compare).
		$users = get_users( array(
			'meta_key'   => 'zdz_salesperson_initials',
			'meta_value' => $code,
			'number'     => 1,
			'fields'     => 'ID',
		) );
		if ( ! empty( $users ) ) {
			return (int) $users[0];
		}

		// 3. Legacy fuzzy fallback (username/display-name) — only as a last resort
		//    for backfill. We invert ZL_Lead_Interaction::resolve_salesperson_code
		//    by scanning candidate app users and asking what code each resolves to.
		if ( class_exists( 'ZL_Lead_Interaction' )
			&& method_exists( 'ZL_Lead_Interaction', 'resolve_salesperson_code' ) ) {
			$candidates = get_users( array( 'fields' => 'ID', 'number' => 200 ) );
			foreach ( $candidates as $uid ) {
				$uid = (int) $uid;
				$resolved = (string) ZL_Lead_Interaction::resolve_salesperson_code( $uid );
				if ( $resolved !== '' && strcasecmp( $resolved, $code ) === 0 ) {
					return $uid;
				}
			}
		}

		return 0;
	}

	/**
	 * One-time backfill: seed assigned_user_id for rows still NULL, from each
	 * lead's batch salesperson code. Idempotent (only touches NULL rows), never
	 * overwrites an explicit assignment. Returns the number of rows seeded.
	 *
	 * @return int
	 */
	public static function backfill_from_batches(): int {
		global $wpdb;

		// Pull distinct (batch_id, code) for batches whose leads still lack an owner.
		$rows = $wpdb->get_results(
			"SELECT DISTINCT b.id AS batch_id, b.assigned_to AS code
			 FROM {$wpdb->prefix}zl_batches b
			 INNER JOIN {$wpdb->prefix}zl_leads l ON l.batch_id = b.id
			 WHERE l.assigned_user_id IS NULL
			   AND b.assigned_to IS NOT NULL AND b.assigned_to <> ''",
			ARRAY_A
		);
		if ( empty( $rows ) ) {
			return 0;
		}

		$seeded     = 0;
		$code_cache = array(); // code → user id (avoid re-resolving)
		$now_utc    = current_time( 'mysql', true );

		foreach ( $rows as $r ) {
			$code     = (string) $r['code'];
			$batch_id = (int) $r['batch_id'];
			if ( ! array_key_exists( $code, $code_cache ) ) {
				$code_cache[ $code ] = self::resolve_code_to_user( $code );
			}
			$uid = (int) $code_cache[ $code ];
			if ( $uid <= 0 ) {
				continue; // leave NULL (admin-only) when unresolved
			}
			// Only seed rows still NULL for this batch (idempotent).
			$seeded += (int) $wpdb->query( $wpdb->prepare(
				"UPDATE {$wpdb->prefix}zl_leads
				 SET assigned_user_id = %d, assigned_at = %s, assigned_by = 0
				 WHERE batch_id = %d AND assigned_user_id IS NULL",
				$uid, $now_utc, $batch_id
			) );
		}

		// Clear any deferred marker now that we've run.
		delete_option( 'zl_assignment_backfill_pending' );

		return $seeded;
	}

	/**
	 * Run a deferred backfill if the migration marked one pending (the helper
	 * wasn't loaded at upgrade time). Cheap no-op otherwise. Hook on init.
	 *
	 * @return void
	 */
	public static function maybe_run_deferred_backfill(): void {
		if ( get_option( 'zl_assignment_backfill_pending' ) ) {
			$seeded = self::backfill_from_batches();
			error_log( 'ZL: deferred assignment backfill seeded ' . (int) $seeded . ' lead(s).' );
		}
	}
}

// Run any deferred backfill once classes are loaded (cheap guarded check).
add_action( 'init', array( 'ZL_Lead_Assignment', 'maybe_run_deferred_backfill' ), 20 );
