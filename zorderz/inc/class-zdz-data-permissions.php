<?php
/**
 * Theme-level data permissions - cross-plugin access control.
 *
 * Resolves effective data permissions for any user by merging:
 *   1. Role-based defaults (hardcoded)
 *   2. Per-user overrides (zdz_data_permissions user meta)
 *
 * All plugins consume this via:
 *   ZDZ_Data_Permissions::can( $user_id, 'view_company_revenue' )
 *
 * @package Zorderz
 * @since   2.17.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class ZDZ_Data_Permissions {

	private static $instance = null;

	/** @var array Static request cache - prevents redundant DB queries during a single request. */
	private static $resolved_cache = [];

	/**
	 * Role-based defaults.
	 * 'allow' = permitted | 'deny' = blocked
	 * When zdz_data_permissions meta is 'default' or missing, this resolves it.
	 */
	const ROLE_DEFAULTS = [
		'zdz_owner' => [
			'view_company_revenue'      => 'allow',
			'view_others_data'          => 'allow',
			'view_own_commission'       => 'allow',
			'view_others_commissions'   => 'allow',
			'run_commission_calculation' => 'allow',  // TSCC v2.0
			'edit_cogs_catalog'         => 'allow',  // TSCC v2.0
			'view_commission_audit_trail'=> 'allow',  // TSCC v2.0
			'access_web_research'       => 'allow',
			'access_deep_research'      => 'allow',
			'upload_to_knowledge_vault' => 'allow',
			'lead_crew'                 => 'allow',  // v2.32.0 Crew Lead hierarchy
			'handoff_jobs'              => 'allow',  // v2.32.0 job component handoff
		],
		'zdz_admin' => [
			'view_company_revenue'      => 'allow',
			'view_others_data'          => 'allow',
			'view_own_commission'       => 'allow',
			'view_others_commissions'   => 'allow',
			'run_commission_calculation' => 'allow',  // TSCC v2.0
			'edit_cogs_catalog'         => 'allow',  // TSCC v2.0
			'view_commission_audit_trail'=> 'allow',  // TSCC v2.0
			'access_web_research'       => 'allow',
			'access_deep_research'      => 'allow',
			'upload_to_knowledge_vault' => 'allow',
			'lead_crew'                 => 'allow',  // v2.32.0
			'handoff_jobs'              => 'allow',  // v2.32.0
		],
		'zdz_sales' => [
			'view_company_revenue'      => 'deny',
			'view_others_data'          => 'deny',
			'view_own_commission'       => 'allow',
			'view_others_commissions'   => 'deny',
			'run_commission_calculation' => 'allow',  // TSCC v2.0
			'edit_cogs_catalog'         => 'deny',   // TSCC v2.0
			'view_commission_audit_trail'=> 'deny',   // TSCC v2.0
			'access_web_research'       => 'allow',
			'access_deep_research'      => 'deny',
			'upload_to_knowledge_vault' => 'deny',
			// A salesperson closes the sale, so they may hand a job component to a
			// specialist. They are not a
			// Crew Lead by default - flip lead_crew per-user for anyone who leads.
			'lead_crew'                 => 'deny',
			'handoff_jobs'              => 'allow',
		],
		'zdz_operator' => [
			'view_company_revenue'      => 'deny',
			'view_others_data'          => 'allow',
			'view_own_commission'       => 'deny',
			'view_others_commissions'   => 'deny',
			'run_commission_calculation' => 'allow',  // TSCC v2.0
			'edit_cogs_catalog'         => 'deny',   // TSCC v2.0
			'view_commission_audit_trail'=> 'allow',  // TSCC v2.0
			'access_web_research'       => 'deny',
			'access_deep_research'      => 'deny',
			'upload_to_knowledge_vault' => 'deny',
			// v2.32.0: operators coordinate work, so they can lead a crew and hand off.
			'lead_crew'                 => 'allow',
			'handoff_jobs'              => 'allow',
		],
		'zdz_mfg' => [
			'view_company_revenue'      => 'deny',
			'view_others_data'          => 'deny',
			'view_own_commission'       => 'deny',
			'view_others_commissions'   => 'deny',
			'run_commission_calculation' => 'deny',   // TSCC v2.0
			'edit_cogs_catalog'         => 'deny',   // TSCC v2.0
			'view_commission_audit_trail'=> 'deny',   // TSCC v2.0
			'access_web_research'       => 'deny',
			'access_deep_research'      => 'deny',
			'upload_to_knowledge_vault' => 'deny',
			// v2.32.0: the shop foreman naturally leads a crew.
			'lead_crew'                 => 'allow',
			'handoff_jobs'              => 'deny',
		],
		'zdz_tech' => [
			'view_company_revenue'      => 'deny',
			'view_others_data'          => 'allow',
			'view_own_commission'       => 'deny',
			'view_others_commissions'   => 'deny',
			'run_commission_calculation' => 'deny',   // TSCC v2.0
			'edit_cogs_catalog'         => 'deny',   // TSCC v2.0
			'view_commission_audit_trail'=> 'deny',   // TSCC v2.0
			'access_web_research'       => 'allow',
			'access_deep_research'      => 'allow',
			'upload_to_knowledge_vault' => 'allow',
			// v2.32.0: a field tech is not a Crew Lead by default, but an individual
			// specialist can be made one per-user (the "ADD A SPECIFIC USER" case).
			'lead_crew'                 => 'deny',
			'handoff_jobs'              => 'deny',
		],
		// -- zdz_general (Shared Kiosk): ALL-DENY ----------------------------
		// The most-shared, least-privileged account. Every data permission is
		// denied: no revenue, no other-user data, no commission anything, no
		// COGS editing, no web/deep research (no open web from a shared
		// device), and no Knowledge Vault uploads. This all-deny profile is the
		// single source of truth that the Brain Bot redaction, the dashboard
		// KPI gate (class-zdz-kpi-metrics.php), and the analytics kiosk tier all
		// read through the v2.17.0 bridge - so least privilege cascades from
		// here without scattered per-account checks.
		'zdz_general' => [
			'view_company_revenue'       => 'deny',
			'view_others_data'           => 'deny',
			'view_own_commission'        => 'deny',
			'view_others_commissions'    => 'deny',
			'run_commission_calculation' => 'deny',
			'edit_cogs_catalog'          => 'deny',
			'view_commission_audit_trail'=> 'deny',
			'access_web_research'        => 'deny',
			'access_deep_research'       => 'deny',
			'upload_to_knowledge_vault'  => 'deny',
			'lead_crew'                  => 'deny',  // v2.32.0 - a kiosk is never a lead (INV-10)
			'handoff_jobs'               => 'deny',  // v2.32.0
		],
	];

	/** All known permission keys. Used for validation. */
	const ALL_KEYS = [
		'view_company_revenue',
		'view_others_data',
		'view_own_commission',
		'view_others_commissions',
		'run_commission_calculation',     // TSCC v2.0
		'edit_cogs_catalog',             // TSCC v2.0
		'view_commission_audit_trail',   // TSCC v2.0
		'access_web_research',
		'access_deep_research',
		'upload_to_knowledge_vault',
		'lead_crew',                     // v2.32.0 Crew Lead hierarchy
		'handoff_jobs',                  // v2.32.0 job component handoff
	];

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// No hooks needed - this is a static utility class.
	}

	/**
	 * Check whether a user has a specific data permission.
	 *
	 * @param int    $user_id    WordPress user ID.
	 * @param string $permission One of self::ALL_KEYS.
	 * @return bool True if allowed, false if denied.
	 */
	public static function can( int $user_id, string $permission ): bool {
		$resolved = self::resolve( $user_id );
		return ( $resolved[ $permission ] ?? 'deny' ) === 'allow';
	}

	/**
	 * Resolve all data permissions for a user.
	 *
	 * Resolution order:
	 *   1. Per-user override (zdz_data_permissions meta) - if 'allow' or 'deny'
	 *   2. Role-based default (ROLE_DEFAULTS) - if meta is 'default' or missing
	 *   3. Hard deny - if role not in ROLE_DEFAULTS
	 *
	 * @param int $user_id WordPress user ID.
	 * @return array<string, string> Map of permission key => 'allow'|'deny'.
	 */
	public static function resolve( int $user_id ): array {
		// Request-level cache
		if ( isset( self::$resolved_cache[ $user_id ] ) ) {
			return self::$resolved_cache[ $user_id ];
		}

		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return self::all_deny();
		}

		// Resolve primary role.
		//
		// An unrecognised role must NOT receive a permissive default. This
		// previously fell back to the Field Tech profile, which ALLOWS
		// view_others_data, web/deep research and vault uploads — so a
		// subscriber, a WooCommerce customer, or any account whose role was
		// momentarily unregistered silently acquired those rights. Roles are
		// unregistered routinely: activation used to remove and re-add every
		// role, and a rename does the same. Deny is the only safe base.
		$role = null;
		foreach ( $user->roles as $r ) {
			if ( isset( self::ROLE_DEFAULTS[ $r ] ) || 'administrator' === $r ) {
				$role = $r;
				break;
			}
		}

		if ( null === $role ) {
			// Unknown role: start from all-deny. Explicit per-user overrides
			// below can still grant, so an administrator retains the ability to
			// authorise an individual deliberately.
			$role_defaults = self::all_deny();
		} elseif ( 'administrator' === $role ) {
			$role_defaults = self::ROLE_DEFAULTS['zdz_admin'];
		} else {
			$role_defaults = self::ROLE_DEFAULTS[ $role ];
		}

		// Fetch per-user overrides
		$overrides = get_user_meta( $user_id, 'zdz_data_permissions', true );
		if ( ! is_array( $overrides ) ) {
			$overrides = [];
		}

		$resolved = [];
		foreach ( self::ALL_KEYS as $key ) {
			$user_val = $overrides[ $key ] ?? 'default';
			if ( in_array( $user_val, [ 'allow', 'deny' ], true ) ) {
				$resolved[ $key ] = $user_val;
			} else {
				$resolved[ $key ] = $role_defaults[ $key ] ?? 'deny';
			}
		}

		// Cache for this request lifecycle
		self::$resolved_cache[ $user_id ] = $resolved;
		return $resolved;
	}

	/**
	 * Get a human-readable permissions summary for admin UI and audit logging.
	 */
	public static function get_summary( int $user_id ): array {
		$resolved  = self::resolve( $user_id );
		$overrides = get_user_meta( $user_id, 'zdz_data_permissions', true );
		if ( ! is_array( $overrides ) ) {
			$overrides = [];
		}

		$summary = [];
		foreach ( self::ALL_KEYS as $key ) {
			$summary[ $key ] = [
				'effective' => $resolved[ $key ],
				'source'    => isset( $overrides[ $key ] ) && in_array( $overrides[ $key ], [ 'allow', 'deny' ], true )
					? 'override'
					: 'role_default',
			];
		}
		return $summary;
	}

	/**
	 * Build a permission context block for AI prompt injection.
	 */
	public static function build_prompt_block( int $user_id ): string {
		$resolved = self::resolve( $user_id );
		$user     = get_userdata( $user_id );
		$role     = $user ? ( $user->roles[0] ?? 'unknown' ) : 'unknown';

		$block  = "\n=== DATA PERMISSIONS (theme-level v2.17.0) ===\n";
		$block .= "User: " . ( $user ? $user->display_name : 'Unknown' ) . " ({$role})\n";
		$block .= "Resolved permissions:\n";

		$labels = [
			'view_company_revenue'      => 'Company revenue figures',
			'view_others_data'          => 'Other employees\' data',
			'view_own_commission'       => 'Own commission details',
			'view_others_commissions'   => 'Others\' commission details',
			'run_commission_calculation' => 'Run commission calculations',
			'edit_cogs_catalog'         => 'Edit COGS catalog',
			'view_commission_audit_trail'=> 'Commission audit trail',
			'access_web_research'       => 'Web research queries',
			'access_deep_research'      => 'Deep research queries',
			'upload_to_knowledge_vault' => 'Knowledge Vault uploads',
			'lead_crew'                 => 'Lead a crew (assign & oversee)',
			'handoff_jobs'              => 'Hand off job components',
		];

		foreach ( $resolved as $key => $val ) {
			$label = $labels[ $key ] ?? $key;
			$icon  = $val === 'allow' ? '[y]' : '[x]';
			$block .= "  {$icon} {$label}\n";
		}

		return $block;
	}

	/** Return all-deny permissions (for invalid/guest users). */
	private static function all_deny(): array {
		return array_fill_keys( self::ALL_KEYS, 'deny' );
	}

	/**
	 * Clear the request-level resolve cache. Call after writing a user's
	 * zdz_data_permissions meta within the same request so a subsequent can()
	 * sees the new value (otherwise the first resolve of that uid is cached).
	 *
	 * @param int|null $user_id Clear one user, or all when null.
	 */
	public static function flush_cache( $user_id = null ): void {
		if ( null === $user_id ) {
			self::$resolved_cache = [];
		} else {
			unset( self::$resolved_cache[ (int) $user_id ] );
		}
	}
}
