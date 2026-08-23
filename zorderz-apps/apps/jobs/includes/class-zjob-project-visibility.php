<?php
/**
 * Zjob_Project_Visibility — the Projects visibility engine.
 *
 * This class DEFINES NO PERMISSION OF ITS OWN. That is the whole point: it COMPOSES the Core services
 * that already exist (ZDZ_Data_Permissions for the money keys, ZDZ_Hierarchy for oversight and the
 * shared-device trait, and the container's own creator/participant facts) into two decisions:
 *
 *   1. relationship( viewer, project ) -> one of four tiers, resolved MOST-RESTRICTIVE-WINS. Any
 *      restriction (a kiosk viewer, or a MISSING permission engine) overrides every grant; within
 *      grants the viewer receives the tier they actually hold. The tiers, widest-this-project-access
 *      first:
 *        REL_ALL       sees the work AND all money  — holds `view_company_revenue` (admin/owner).
 *        REL_RELATED   sees the work AND own money  — created it / assigned / its sp_code is theirs.
 *        REL_OVERSIGHT sees the work only            — a crew lead over someone on it (NOT money).
 *        REL_NONE      sees nothing                  — everyone else, AND the kiosk ALWAYS.
 *      (REL_RELATED outranks REL_OVERSIGHT deliberately: for THIS project a personal tie carries
 *      own-work money that oversight does not, so a viewer who is both keeps their own-work money.)
 *
 *   2. decide_money( viewer, project ) -> a SECOND, fail-closed boolean layered on the first, reading
 *      EXACTLY `view_company_revenue` and `view_own_commission` and defining none. Company-revenue
 *      sees money everywhere; own-commission sees money only on the viewer's OWN work (REL_RELATED).
 *      Oversight does NOT grant money (a crew lead gets the work view, not the totals). One tenant
 *      seam: the `zdz_project_may_see_money` filter (per-trade policy; e.g. a firm paying techs on
 *      completed work flips it).
 *
 * REFUSE, DO NOT GUESS: if ZDZ_Data_Permissions is absent, relationship() returns REL_NONE and
 * decide_money() returns false. The kiosk is refused via the shared-device TRAIT (ZDZ_Hierarchy::
 * is_kiosk), never a role-slug literal.
 *
 * Ships EMPTY: names no company/person/product/place/provider; seeds nothing.
 *
 * @package Zorderz\Jobs
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Zjob_Project_Visibility' ) ) {

	class Zjob_Project_Visibility {

		/** The four relationship tiers. Constants so the values are never typed twice. */
		const REL_ALL       = 'all';       // work + all money (view_company_revenue).
		const REL_RELATED   = 'related';   // work + own money (created / assigned / sp_code).
		const REL_OVERSIGHT = 'oversight'; // work only (crew lead over someone on it).
		const REL_NONE      = 'none';      // nothing (everyone else, and the kiosk always).

		/** The two — and only two — money permission keys this engine reads. It defines neither. */
		const KEY_REVENUE    = 'view_company_revenue';
		const KEY_COMMISSION = 'view_own_commission';

		/* ===================================================================
		 * RELATIONSHIP — the four tiers, most-restrictive-wins.
		 * =================================================================== */

		/**
		 * Resolve the viewer's relationship to a project.
		 *
		 * @param int   $viewer  WP user id.
		 * @param array $project an enriched project (Zjob_Project::get()); needs at least `id`, and
		 *                       may carry `created_by`, `sp_code`, `participants` to avoid re-querying.
		 * @return string one of the REL_* constants.
		 */
		public static function relationship( int $viewer, array $project ): string {
			if ( $viewer <= 0 ) {
				return self::REL_NONE;
			}

			// MOST-RESTRICTIVE-WINS #1: the kiosk is refused ALWAYS — a shared device is not a person.
			// Detected by the shared-device trait, never a slug. This overrides even admin.
			if ( class_exists( 'ZDZ_Hierarchy' ) && ZDZ_Hierarchy::is_kiosk( $viewer ) ) {
				return self::REL_NONE;
			}

			// REFUSE, DO NOT GUESS: without the permission engine we cannot resolve REL_ALL, so we
			// fail closed to REL_NONE rather than inventing an answer.
			if ( ! class_exists( 'ZDZ_Data_Permissions' ) ) {
				return self::REL_NONE;
			}

			// REL_ALL — the company-revenue viewer (admin/owner) sees everything.
			if ( ZDZ_Data_Permissions::can( $viewer, self::KEY_REVENUE ) ) {
				return self::REL_ALL;
			}

			$people = self::people_on( $viewer, $project );

			// REL_RELATED — a personal tie to THIS project (checked before oversight: it carries the
			// own-work money that oversight does not).
			if ( self::is_related( $viewer, $project, $people ) ) {
				return self::REL_RELATED;
			}

			// REL_OVERSIGHT — a crew lead over someone (other than themselves) on the project.
			if ( class_exists( 'ZDZ_Hierarchy' ) ) {
				foreach ( $people as $uid ) {
					if ( $uid > 0 && $uid !== $viewer && ZDZ_Hierarchy::can_oversee( $viewer, $uid ) ) {
						return self::REL_OVERSIGHT;
					}
				}
			}

			return self::REL_NONE;
		}

		/**
		 * Convenience gate used by write-side callers (e.g. the calendar link in C1): may this viewer
		 * act on / use this project at all? True for any relationship other than REL_NONE.
		 *
		 * @param int   $viewer
		 * @param array $project
		 * @return bool
		 */
		public static function actor_may_use_project( int $viewer, array $project ): bool {
			return self::REL_NONE !== self::relationship( $viewer, $project );
		}

		/* ===================================================================
		 * MONEY — the second, fail-closed decision (two keys; defines none).
		 * =================================================================== */

		/**
		 * May this viewer see money-class figures on this project? Fail-closed.
		 *
		 *   - the permission engine is absent               -> false (refuse, do not guess).
		 *   - the kiosk                                      -> false (shared-device trait).
		 *   - `view_company_revenue`                         -> true  (all money).
		 *   - `view_own_commission` AND relationship RELATED -> true  (money on their OWN work only).
		 *   - otherwise                                      -> false.
		 *
		 * The single tenant seam is the `zdz_project_may_see_money` filter (per-trade policy).
		 *
		 * @param int   $viewer
		 * @param array $project
		 * @return bool
		 */
		public static function decide_money( int $viewer, array $project ): bool {
			// REFUSE, DO NOT GUESS.
			if ( ! class_exists( 'ZDZ_Data_Permissions' ) ) {
				return false;
			}
			if ( $viewer <= 0 ) {
				return false;
			}
			// The kiosk never sees money (one of the several independent refusals — preserve it).
			if ( class_exists( 'ZDZ_Hierarchy' ) && ZDZ_Hierarchy::is_kiosk( $viewer ) ) {
				return false;
			}

			$base = false;
			if ( ZDZ_Data_Permissions::can( $viewer, self::KEY_REVENUE ) ) {
				$base = true; // company revenue — money everywhere.
			} elseif ( ZDZ_Data_Permissions::can( $viewer, self::KEY_COMMISSION ) ) {
				// own commission — money only on the viewer's OWN work.
				$base = ( self::REL_RELATED === self::relationship( $viewer, $project ) );
			}

			/**
			 * Per-trade money policy. The default equals the baseline role defaults (no new permission
			 * is defined here). A firm with a different policy (e.g. techs paid on completed work) flips
			 * this filter; it can only ever be consulted with $base already computed fail-closed.
			 *
			 * @param bool  $base    the fail-closed decision from the two keys.
			 * @param int   $viewer
			 * @param array $project
			 */
			return (bool) apply_filters( 'zdz_project_may_see_money', $base, $viewer, $project );
		}

		/* ===================================================================
		 * INTERNAL
		 * =================================================================== */

		/**
		 * Everyone on the project (the originator + child-job creators/assignees). Prefers the
		 * pre-computed `participants`/`created_by` on an enriched project; falls back to the container
		 * when only an id is present.
		 *
		 * @param int   $viewer  (unused here; kept for signature symmetry / future per-viewer scoping).
		 * @param array $project
		 * @return int[]
		 */
		private static function people_on( int $viewer, array $project ): array {
			unset( $viewer );
			$people = array();
			if ( isset( $project['participants'] ) && is_array( $project['participants'] ) ) {
				$people = array_map( 'intval', $project['participants'] );
			} elseif ( ! empty( $project['id'] ) && class_exists( 'Zjob_Project' ) ) {
				$people = Zjob_Project::participant_ids( (string) $project['id'] );
			}
			$cb = (int) ( $project['created_by'] ?? 0 );
			if ( $cb > 0 ) {
				$people[] = $cb;
			}
			return array_values( array_unique( array_filter( array_map( 'intval', $people ) ) ) );
		}

		/**
		 * Is the viewer PERSONALLY related to the project — created it, is on it (a child job's creator
		 * or assignee), or its sp_code is theirs?
		 *
		 * @param int   $viewer
		 * @param array $project
		 * @param int[] $people  precomputed people-on-it (created_by + child-job creators/assignees).
		 * @return bool
		 */
		private static function is_related( int $viewer, array $project, array $people ): bool {
			if ( (int) ( $project['created_by'] ?? 0 ) === $viewer ) {
				return true;
			}
			if ( in_array( $viewer, $people, true ) ) {
				return true; // creator or assignee of one of its jobs.
			}
			// sp_code arm: estimate-minted projects carry none (created_by is the tie); when a tenant
			// populates it (via the zdz_project_sp_code filter on get()), a matching viewer sp_code is
			// a relation. Resolved through a filter so this class defines no attribution of its own.
			$sp = (string) ( $project['sp_code'] ?? '' );
			if ( '' !== $sp ) {
				$viewer_sp = (string) apply_filters( 'zdz_project_viewer_sp_code', '', $viewer );
				if ( '' !== $viewer_sp && strcasecmp( $viewer_sp, $sp ) === 0 ) {
					return true;
				}
			}
			return false;
		}
	}
}
