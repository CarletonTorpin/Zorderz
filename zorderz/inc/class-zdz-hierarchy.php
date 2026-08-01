<?php
/**
 * TS Hierarchy - Crew Lead relationships & the oversight gate.
 *
 * -----------------------------------------------------------------------------
 * WHAT THIS IS (the "one person in charge" model)
 * -----------------------------------------------------------------------------
 * Zorderz roles (ZDZ_User_Roles) are flat - a Field Tech is a Field Tech. This
 * class adds a lightweight HIERARCHY on top of the flat roles: any user can be a
 * "Crew Lead" who oversees a named set of other users (their "Crew"). It does NOT
 * add a role; it layers a relationship + a capability (ZDZ_Data_Permissions key
 * `lead_crew`) onto whatever role a person already has. So a Salesperson, a Field
 * Tech, or an Operator can all be made a Crew Lead over specific people without a
 * new WordPress role.
 *
 * TERMINOLOGY (chosen with CT - deliberately field-culture, not clinical):
 *   * Crew Lead      - the person in charge of a crew.
 *   * Crew           - the set of users a Crew Lead oversees.
 *   * "reports to"   - the inverse pointer (a crew member reports to their Lead).
 *
 * -----------------------------------------------------------------------------
 * STORAGE (user meta - no table needed for the relationship)
 * -----------------------------------------------------------------------------
 *   * zdz_crew_members (on the LEAD)  = int[] of WP user ids this person leads.
 *   * zdz_reports_to   (on the MEMBER) = int  WP user id of this person's Lead (0/none).
 * The two are kept in sync by set_crew() so either direction is a cheap lookup.
 * `zdz_crew_members` is the authoritative store; `zdz_reports_to` is a derived mirror
 * rebuilt whenever any crew changes (so a member is never claimed by two leads).
 *
 * -----------------------------------------------------------------------------
 * THE OVERSIGHT GATE (mirrors TSL_Lead_Assignment::current_user_can_act_on_lead)
 * -----------------------------------------------------------------------------
 * can_oversee($actor, $target):
 *   * kiosk (zdz_general) actor            -> NEVER (INV-10; a shared device is not a person)
 *   * admin/owner (is_admin_role)         -> yes, anyone
 *   * a Crew Lead (has `lead_crew` cap)   -> yes, but ONLY for members of their own crew
 *   * everyone else                       -> only themselves
 * Every server-side handler that lets a Lead see/act on a crew member's work MUST
 * call this (INV-1: the endpoint, not the UI, is the boundary).
 *
 * @package Zorderz
 * @since   2.32.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZDZ_Hierarchy {

	const META_CREW       = 'zdz_crew_members'; // int[] on the Lead
	const META_REPORTS_TO = 'zdz_reports_to';   // int   on the member
	const CAP_LEAD        = 'lead_crew';        // ZDZ_Data_Permissions key

	/* =======================================================================
	 * ROLE / CAP HELPERS
	 * ======================================================================= */

	/**
	 * Is this user an admin-tier user (sees/acts on everyone)?
	 * Uses ZDZ_User_Roles when present; falls back to manage_options.
	 */
	public static function is_admin( int $user_id ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}
		if ( user_can( $user_id, 'manage_options' ) ) {
			return true;
		}
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return false;
		}
		if ( class_exists( 'ZDZ_User_Roles' ) ) {
			foreach ( (array) $user->roles as $r ) {
				if ( ZDZ_User_Roles::is_admin_role( $r ) ) {
					return true;
				}
			}
			return false;
		}
		// Fallback if roles class missing.
		return (bool) array_intersect( [ 'administrator', 'zdz_owner', 'zdz_admin' ], (array) $user->roles );
	}

	/** Is this user on the shared-kiosk tier (zdz_general)? Never treated as a person. */
	public static function is_kiosk( int $user_id ): bool {
		$user = $user_id ? get_userdata( $user_id ) : null;
		return $user && in_array( 'zdz_general', (array) $user->roles, true );
	}

	/**
	 * Does this user hold the Crew-Lead capability? Resolved through the theme's
	 * data-permission engine (role default + per-user override), so an admin can
	 * flip any single person on/off from the profile screen ("ADD A SPECIFIC USER").
	 * Admins are always leads.
	 */
	public static function is_crew_lead( int $user_id ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}
		if ( self::is_kiosk( $user_id ) ) {
			return false; // INV-10 - a shared device is never a lead
		}
		if ( self::is_admin( $user_id ) ) {
			return true;
		}
		if ( class_exists( 'ZDZ_Data_Permissions' ) ) {
			return ZDZ_Data_Permissions::can( $user_id, self::CAP_LEAD );
		}
		return false;
	}

	/* =======================================================================
	 * READ
	 * ======================================================================= */

	/**
	 * The WP user ids a Crew Lead oversees. Always returns a clean int[] of ids
	 * that still exist. (Deleted users are filtered out on read.)
	 *
	 * @param int $lead_id
	 * @return int[]
	 */
	public static function get_crew( int $lead_id ): array {
		if ( $lead_id <= 0 ) {
			return [];
		}
		$raw = get_user_meta( $lead_id, self::META_CREW, true );
		if ( ! is_array( $raw ) ) {
			return [];
		}
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $raw ) ) ) );
		// Drop ids that no longer resolve to a real user.
		$ids = array_values( array_filter( $ids, static function ( $id ) {
			return (bool) get_userdata( $id );
		} ) );
		return $ids;
	}

	/**
	 * The WP user id of a member's Crew Lead (0 if none / not found).
	 *
	 * @param int $member_id
	 * @return int
	 */
	public static function get_lead( int $member_id ): int {
		if ( $member_id <= 0 ) {
			return 0;
		}
		$lead = (int) get_user_meta( $member_id, self::META_REPORTS_TO, true );
		if ( $lead > 0 && get_userdata( $lead ) ) {
			return $lead;
		}
		return 0;
	}

	/** Is $member in $lead's crew? */
	public static function is_lead_of( int $lead_id, int $member_id ): bool {
		if ( $lead_id <= 0 || $member_id <= 0 ) {
			return false;
		}
		return in_array( (int) $member_id, self::get_crew( $lead_id ), true );
	}

	/* =======================================================================
	 * THE OVERSIGHT GATE
	 * ======================================================================= */

	/**
	 * May $actor see/act on $target's work?
	 *  - kiosk actor          -> never
	 *  - admin/owner          -> anyone
	 *  - self                 -> yes (you can always act on your own work)
	 *  - Crew Lead            -> yes, only for members of their own crew
	 *  - otherwise            -> no
	 *
	 * @param int $actor_id
	 * @param int $target_id
	 * @return bool
	 */
	public static function can_oversee( int $actor_id, int $target_id ): bool {
		if ( $actor_id <= 0 || $target_id <= 0 ) {
			return false;
		}
		if ( self::is_kiosk( $actor_id ) ) {
			return false; // INV-10
		}
		if ( $actor_id === $target_id ) {
			return true;
		}
		if ( self::is_admin( $actor_id ) ) {
			return true;
		}
		// Crew Lead may oversee only their own crew.
		if ( self::is_crew_lead( $actor_id ) && self::is_lead_of( $actor_id, $target_id ) ) {
			return true;
		}
		return false;
	}

	/* =======================================================================
	 * WRITE (set a Lead's crew - admin/edit_users only; called from the profile UI)
	 * ======================================================================= */

	/**
	 * Set the full crew for a Crew Lead. Authoritative + mirror-syncing:
	 *   1. Stores the (validated) member id list on the lead (META_CREW).
	 *   2. Rewrites each member's reports_to pointer (META_REPORTS_TO):
	 *        - members newly added         -> point at this lead
	 *        - members removed from crew    -> cleared if they still point here
	 *      A member can report to only ONE lead; assigning them here moves them.
	 *
	 * Caller MUST be allowed to edit users (the profile-save handler checks
	 * current_user_can('edit_user', ...)); this method also re-guards defensively.
	 *
	 * @param int   $lead_id     The Crew Lead.
	 * @param int[] $member_ids  Desired crew (WP user ids). [] clears the crew.
	 * @param int   $actor_id    Who is making the change (audit). 0 = current user.
	 * @return array { success:bool, crew:int[], error:string }
	 */
	public static function set_crew( int $lead_id, array $member_ids, int $actor_id = 0 ): array {
		$actor_id = $actor_id ?: get_current_user_id();

		if ( $lead_id <= 0 || ! get_userdata( $lead_id ) ) {
			return [ 'success' => false, 'crew' => [], 'error' => 'bad_lead' ];
		}
		// Defensive re-guard: only someone who can edit this user may set their crew.
		if ( ! user_can( $actor_id, 'manage_options' ) && ! user_can( $actor_id, 'edit_users' ) ) {
			return [ 'success' => false, 'crew' => [], 'error' => 'forbidden' ];
		}

		// Sanitize desired member list: real users, not the lead themselves, unique.
		$desired = [];
		foreach ( $member_ids as $mid ) {
			$mid = (int) $mid;
			if ( $mid > 0 && $mid !== $lead_id && get_userdata( $mid ) ) {
				$desired[ $mid ] = true;
			}
		}
		$desired = array_map( 'intval', array_keys( $desired ) );

		$previous = self::get_crew( $lead_id );

		// 1. Store the authoritative crew list on the lead.
		update_user_meta( $lead_id, self::META_CREW, array_values( $desired ) );

		// 2. Point every desired member at this lead, and EVICT them from any other
		//    lead's crew list first (a person reports to exactly one lead). Without
		//    this eviction a member could linger in a prior lead's crew array.
		foreach ( $desired as $mid ) {
			$prior_lead = (int) get_user_meta( $mid, self::META_REPORTS_TO, true );
			if ( $prior_lead > 0 && $prior_lead !== $lead_id ) {
				$prior_crew = self::get_crew( $prior_lead );
				$prior_crew = array_values( array_diff( $prior_crew, [ $mid ] ) );
				update_user_meta( $prior_lead, self::META_CREW, $prior_crew );
			}
			update_user_meta( $mid, self::META_REPORTS_TO, $lead_id );
		}

		// 3. For members removed from this crew, clear their pointer IF it still
		//    names this lead (don't stomp a pointer that was moved elsewhere).
		$removed = array_diff( $previous, $desired );
		foreach ( $removed as $mid ) {
			if ( (int) get_user_meta( $mid, self::META_REPORTS_TO, true ) === $lead_id ) {
				delete_user_meta( $mid, self::META_REPORTS_TO );
			}
		}

		// Audit (best-effort; same log the rest of the platform writes to).
		if ( class_exists( '\Zorderz\ZDZ_Admin_Dashboard' )
			&& method_exists( '\Zorderz\ZDZ_Admin_Dashboard', 'log_action' ) ) {
			$lead_user = get_userdata( $lead_id );
			\Zorderz\ZDZ_Admin_Dashboard::log_action(
				$actor_id,
				'crew_set',
				sprintf(
					'Set %s\'s crew to %d member(s)',
					$lead_user ? $lead_user->display_name : ( '#' . $lead_id ),
					count( $desired )
				),
				'theme-hierarchy',
				[ 'lead_id' => $lead_id, 'crew' => array_values( $desired ) ]
			);
		}

		/**
		 * Fires after a crew is (re)set. Consumers can bust caches, notify, etc.
		 *
		 * @param int   $lead_id
		 * @param int[] $desired  New crew.
		 * @param int[] $previous Prior crew.
		 */
		do_action( 'zdz_crew_updated', $lead_id, array_values( $desired ), $previous );

		return [ 'success' => true, 'crew' => array_values( $desired ), 'error' => '' ];
	}

	/* =======================================================================
	 * CONVENIENCE (for dashboards / rollups)
	 * ======================================================================= */

	/**
	 * All users the current viewer may oversee (themselves + their crew if a Lead,
	 * or everyone if admin). Handy for building a "my team" rollup without each
	 * caller re-deriving the rule. Returns WP user ids.
	 *
	 * @param int|null $viewer_id Defaults to current user.
	 * @return int[]
	 */
	public static function overseeable_user_ids( $viewer_id = null ): array {
		$viewer_id = $viewer_id ? (int) $viewer_id : get_current_user_id();
		if ( $viewer_id <= 0 || self::is_kiosk( $viewer_id ) ) {
			return [];
		}
		if ( self::is_admin( $viewer_id ) ) {
			// All app users (bounded; admins are few and this is admin-only UI).
			$ids = get_users( [ 'fields' => 'ID', 'number' => 500 ] );
			return array_map( 'intval', $ids );
		}
		$ids = [ $viewer_id ];
		if ( self::is_crew_lead( $viewer_id ) ) {
			$ids = array_merge( $ids, self::get_crew( $viewer_id ) );
		}
		return array_values( array_unique( array_map( 'intval', $ids ) ) );
	}
}
