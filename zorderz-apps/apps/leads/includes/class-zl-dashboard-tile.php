<?php
/**
 * ZL Dashboard Tile — surface a salesperson's assigned leads on their dashboard.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHAT THIS IS (Phase 2 of the per-user-leads program)
 * ─────────────────────────────────────────────────────────────────────────────
 * Two jobs, both server-authoritative:
 *
 *  1. REGISTER into the theme's `zdz_dashboard_action_items` filter so a rep's
 *     "you have N leads to contact" item flows into the platform's unified
 *     "Today's Queue" (the theme exposes the filter + GET zorderz/v1/dashboard-items;
 *     the unified frontend render is deferred theme-side, but registering now
 *     means leads light up the moment it ships — and other surfaces can read it).
 *
 *  2. Provide the single, FINDABLE source of truth for "rep mode" decisions:
 *     who is a salesperson (vs admin/operator/kiosk), and what counts they see.
 *     The widget render + AJAX both call these, so the rule lives in one place.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * RELIABILITY PATTERNS (from the robust-systems research)
 * ─────────────────────────────────────────────────────────────────────────────
 *  • CACHED with a grace fallback. The per-user count is cached in a short
 *    transient (TTL ~2 min) so a dashboard that polls doesn't hammer the DB; the
 *    count query itself is cheap (indexed on assigned_user_id, assigned_at), so
 *    the cache is a courtesy, not a crutch. On any error we return a SAFE zeroed
 *    structure rather than throwing — a dashboard tile must never break the page.
 *  • SERVER-AUTHORITATIVE. Counts are computed from the signed-in user's own
 *    assignment rows (ZL_Lead_Assignment). The UI never decides what a rep may
 *    see; the server scopes it. (RBAC defense-in-depth: the UI adapts for
 *    usability, the server enforces.)
 *  • DECOUPLED from Nutshell. Everything here reads TS's own tables; no CRM call.
 *
 * @package Zorderz\Leads
 * @since   2.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZL_Dashboard_Tile {

	/** Transient key prefix for the per-user count cache. */
	const CACHE_PREFIX = 'zl_myleads_counts_';

	/** Count cache TTL (seconds). Short — assignment can change any time. */
	const CACHE_TTL = 120;

	/* ═══════════════════════════════════════════════════════════════════════
	 * BOOTSTRAP
	 * ═══════════════════════════════════════════════════════════════════════ */

	/**
	 * Wire the theme action-items registration. Called once from the plugin
	 * bootstrap. Safe to call even if the theme filter never fires.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_filter( 'zdz_dashboard_action_items', array( __CLASS__, 'register_action_item' ), 10, 2 );
		// Bust a user's count cache whenever their lead state changes.
		add_action( 'zl_lead_status_changed', array( __CLASS__, 'bust_cache_for_user' ), 10, 1 );
		add_action( 'zl_leads_assigned',      array( __CLASS__, 'bust_cache_for_user' ), 10, 1 );
	}

	/* ═══════════════════════════════════════════════════════════════════════
	 * REP-MODE DETERMINATION (single source of truth)
	 * ═══════════════════════════════════════════════════════════════════════ */

	/**
	 * Is this user in "rep mode" for the Leads widget — i.e. a salesperson who
	 * should see ONLY their assigned leads and NOT the generate controls?
	 *
	 * Rep mode = has the app, is NOT an admin/operator, is NOT kiosk.
	 * (Admins/operators get full mode; kiosk gets no per-user view at all.)
	 *
	 * @param int|null $user_id
	 * @return bool
	 */
	public static function is_rep_mode( $user_id = null ): bool {
		$user_id = $user_id ? (int) $user_id : get_current_user_id();
		if ( ! $user_id ) {
			return false;
		}
		if ( ! class_exists( 'ZL_Lead_Assignment' ) ) {
			return false;
		}
		// Kiosk is never a rep; admins/operators are full-mode, not rep-mode.
		if ( ZL_Lead_Assignment::is_kiosk( $user_id ) ) {
			return false;
		}
		if ( ZL_Lead_Assignment::is_lead_admin( $user_id ) ) {
			return false;
		}
		// Anyone else who can use the app is a rep (zdz_sales, zdz_tech, etc.).
		return user_can( $user_id, 'zdz_access_app' ) || user_can( $user_id, 'read' );
	}

	/* ═══════════════════════════════════════════════════════════════════════
	 * COUNTS (cached, safe, authoritative)
	 * ═══════════════════════════════════════════════════════════════════════ */

	/**
	 * The current (or given) user's assigned-lead counts for the tile/banner.
	 * Cached briefly; always returns a well-formed array (never throws).
	 *
	 * @param int|null $user_id
	 * @return array { new_today:int, open_pending:int, total:int }
	 */
	public static function get_counts( $user_id = null ): array {
		$user_id = $user_id ? (int) $user_id : get_current_user_id();
		$safe    = array( 'new_today' => 0, 'open_pending' => 0, 'total' => 0 );
		if ( ! $user_id || ! class_exists( 'ZL_Lead_Assignment' ) ) {
			return $safe;
		}

		$key    = self::CACHE_PREFIX . $user_id;
		$cached = get_transient( $key );
		if ( is_array( $cached ) && isset( $cached['new_today'], $cached['open_pending'], $cached['total'] ) ) {
			return $cached;
		}

		try {
			$counts = array(
				'new_today'    => ZL_Lead_Assignment::count_assigned( $user_id, array( 'new_today' => true, 'pending_only' => true ) ),
				'open_pending' => ZL_Lead_Assignment::count_assigned( $user_id, array( 'pending_only' => true ) ),
				'total'        => ZL_Lead_Assignment::count_assigned( $user_id ),
			);
			set_transient( $key, $counts, self::CACHE_TTL );
			return $counts;
		} catch ( \Throwable $e ) {
			error_log( 'ZL_Dashboard_Tile::get_counts error: ' . $e->getMessage() );
			return $safe;
		}
	}

	/**
	 * Invalidate a user's cached counts (called on assignment / status change).
	 *
	 * @param int $user_id
	 * @return void
	 */
	public static function bust_cache_for_user( $user_id ): void {
		$user_id = (int) $user_id;
		if ( $user_id > 0 ) {
			delete_transient( self::CACHE_PREFIX . $user_id );
		}
	}

	/* ═══════════════════════════════════════════════════════════════════════
	 * THEME ACTION-ITEMS REGISTRATION
	 * ═══════════════════════════════════════════════════════════════════════ */

	/**
	 * Contribute a "leads to contact" item to the platform's unified queue.
	 * Item shape per the theme contract (handle_dashboard_items):
	 *   plugin, label, count, app_id, icon, color, urgency.
	 *
	 * We surface the rep's OPEN PENDING assigned leads (the actionable backlog),
	 * highlighting when some arrived today. Returns the items array unchanged
	 * (with our item appended) so we compose cleanly with other plugins.
	 *
	 * @param array $items   Items registered so far.
	 * @param int   $user_id The dashboard's current user.
	 * @return array
	 */
	public static function register_action_item( $items, $user_id ): array {
		if ( ! is_array( $items ) ) {
			$items = array();
		}
		$user_id = (int) $user_id;
		if ( ! $user_id ) {
			return $items;
		}

		// Kiosk gets no per-user queue item (most-restrictive-wins).
		if ( class_exists( 'ZL_Lead_Assignment' ) && ZL_Lead_Assignment::is_kiosk( $user_id ) ) {
			return $items;
		}

		$counts = self::get_counts( $user_id );
		$open   = (int) $counts['open_pending'];
		if ( $open <= 0 ) {
			return $items; // nothing pending → no clutter on the dashboard
		}

		$today = (int) $counts['new_today'];
		if ( $today > 0 ) {
			$label   = sprintf(
				/* translators: 1: total pending leads, 2: leads new today */
				_n( '%1$d lead to contact (%2$d new today)', '%1$d leads to contact (%2$d new today)', $open, 'zorderz' ),
				$open, $today
			);
			$urgency = 'high';
		} else {
			$label   = sprintf(
				_n( '%d lead to contact', '%d leads to contact', $open, 'zorderz' ),
				$open
			);
			$urgency = $open >= 5 ? 'medium' : 'low';
		}

		$items[] = array(
			'plugin'  => 'zorderz',
			'label'   => $label,
			'count'   => $open,
			'app_id'  => 'leads', // matches zdz_register_apps id
			'icon'    => 'user-check',
			'color'   => '#22C55E',
			'urgency' => $urgency,
		);

		return $items;
	}
}
