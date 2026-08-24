<?php
/**
 * Custom Roles and Capabilities
 *
 * Zorderz ships a default role shape suited to a small operating business. A
 * business renames the labels and adjusts the permission matrix; the slugs are
 * the platform's own and should not be renamed per-install.
 *
 *   OWNER     (zdz_owner)    — Full access: all apps, all data, analytics.
 *   ADMIN     (zdz_admin)    — Full access, plus platform administration.
 *   SALES     (zdz_sales)    — Sells work: estimates, leads, own commission.
 *   OPERATOR  (zdz_operator) — Coordinates work: estimates, surveys, follow-up.
 *   MFG       (zdz_mfg)      — Production lead: job queue, supply requests.
 *   TECH      (zdz_tech)     — Field staff: field-only tools.
 *   GENERAL   (zdz_general)  — Shared device. Least privilege by design.
 *
 * NOTE: several slugs are matched as literal strings elsewhere in the platform.
 * The most sensitive of those — the shared-device (kiosk) privacy check — now
 * resolves through the ZDZ_User_Roles::is_shared_device() TRAIT (a capability
 * first, with the slug consulted only as one back-compat input), so a role
 * RELABEL no longer disables it. Any remaining direct slug match still relies on
 * the slug being stable: change labels freely; change slugs only via the
 * migration (ZDZ_Rename_Migration), which also carries the trait capability.
 *
 * @package Zorderz
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZDZ_User_Roles {

	/**
	 * Role definitions: slug => [label, capabilities, default_apps].
	 * default_apps = null means "all apps" (admin/owner bypass).
	 */
	private static $role_defs = [
		'zdz_owner' => [
			'label' => 'Owner',
			'caps'  => [], // Gets admin caps dynamically
			'apps'  => null, // All apps
		],
		'zdz_admin' => [
			'label' => 'Administrator',
			'caps'  => [], // Gets admin caps dynamically
			'apps'  => null, // All apps
		],
		'zdz_sales' => [
			'label' => 'Salesperson',
			'caps'  => [ 'read' => true ],
			'apps'  => [ 'estimate-creator', 'sales-analytics', 'lead-generator', 'commission-calculator' ],
		],
		'zdz_operator' => [
			'label' => 'Operator',
			'caps'  => [ 'read' => true ],
			'apps'  => [ 'estimate-creator', 'satisfaction-surveys' ],
		],
		'zdz_mfg' => [
			'label' => 'Shop Foreman',
			'caps'  => [ 'read' => true ],
			'apps'  => [ 'estimate-creator' ],
		],
		'zdz_tech' => [
			'label' => 'Field Tech',
			'caps'  => [ 'read' => true ],
			'apps'  => [],
		],
		// ── zdz_general (Shared Kiosk) ──────────────────────────────────────
		// The shared account used on an unattended device such as a workshop
		// tablet. It is the MOST-shared identity on the platform, so by
		// design it carries the FEWEST privileges. Minimal read-only surface:
		// Analytics chat + Camera + Receipts + (read-only) Internal
		// Messaging. Deliberately NO estimate-creator / lead-generator /
		// commission-calculator / satisfaction-surveys — nothing that creates
		// records or exposes financials. App IDs below are the real registered
		// slugs (verified against each plugin's get_config()):
		//   'sales-analytics'    — analytics chat (optional app)
		//   'zdz-camera'         — photo capture into ZDZ_User_Media
		//   'receipts'           — receipt generator (optional app)
		//   'internal-messaging' — team messaging (announcements; the
		//                          messaging plugin enforces read-only for this
		//                          role at its own capability layer)
		'zdz_general' => [
			'label' => 'General (Shared Kiosk)',
			'caps'  => [ 'read' => true ],
			'apps'  => [ 'sales-analytics', 'zdz-camera', 'receipts', 'internal-messaging' ],
		],
	];

	/**
	 * Human-readable role labels (used by View-As, admin dashboard, etc.)
	 */
	public static function get_role_labels(): array {
		// An applied Identity Pack may relabel roles (a spa calls its production
		// lead something other than "Shop Foreman"). Slugs stay ours; labels are
		// the business's to name.
		$overrides = (array) get_option( 'zdz_role_labels', [] );
		$labels = [
			'administrator' => 'WP Administrator',
			'zdz_owner'      => 'Owner',
			'zdz_admin'      => 'Administrator',
			'zdz_sales'      => 'Salesperson',
			'zdz_operator'   => 'Operator',
			'zdz_mfg'        => 'Shop Foreman',
			'zdz_tech'       => 'Field Tech',
			'zdz_general'    => 'General (Shared Kiosk)',
			'subscriber'    => 'Subscriber',
		];
		return array_merge( $labels, array_intersect_key( $overrides, $labels ) );
	}

	/**
	 * Get the default allowed apps for a given role.
	 * Returns null for "all apps" (owner/admin), or an array of app IDs.
	 */
	public static function get_default_apps_for_role( string $role ) {
		// A pack may set which apps a role gets by default — the app mix is a
		// property of the business, not of the platform. `true` means every app;
		// it is spelled out rather than reusing null, so that "not configured" and
		// "configured to everything" can never be the same stored value.
		$granted = (array) get_option( 'zdz_role_default_apps', [] );
		if ( array_key_exists( $role, $granted ) ) {
			if ( true === $granted[ $role ] ) {
				return null;
			}
			if ( is_array( $granted[ $role ] ) ) {
				return $granted[ $role ];
			}
		}
		if ( isset( self::$role_defs[ $role ] ) ) {
			return self::$role_defs[ $role ]['apps'];
		}
		// WP administrator also gets all apps
		if ( 'administrator' === $role ) {
			return null;
		}
		return [];
	}

	/**
	 * Check if a role is an admin-level role (all apps by default).
	 */
	public static function is_admin_role( string $role ): bool {
		return in_array( $role, [ 'administrator', 'zdz_owner', 'zdz_admin' ], true );
	}

	/**
	 * The platform's shared-device (kiosk) role slug. Kept as ONE input to the
	 * shared-device trait for back-compat; never the sole security test.
	 */
	const SHARED_DEVICE_ROLE = 'zdz_general';

	/**
	 * The first-class shared-device capability/trait. Granted on the shared-device
	 * role at activation, so the kiosk privacy check survives a role RELABEL (the
	 * label is the business's to name; this trait and the slug are the platform's).
	 */
	const SHARED_DEVICE_CAP = 'zdz_is_shared_device';

	/**
	 * Is this user a shared-device (kiosk) identity?
	 *
	 * Security by TRAIT, not by role-slug string match: the capability is the
	 * primary signal; the platform's shared-device role slug is consulted as ONE
	 * additional back-compat input. The shared-device profile is the platform's
	 * most-restrictive identity, so every consumer treats a `true` return as the
	 * MORE-restrictive path — this predicate is therefore fail-closed by design:
	 * a genuine "has neither the trait nor the slug" resolves false (matching the
	 * prior behaviour exactly), while callers themselves fall back to the raw slug
	 * (still restrictive) when this class/method is unavailable.
	 *
	 * @param int $uid
	 * @return bool
	 */
	public static function is_shared_device( int $uid ): bool {
		if ( $uid <= 0 ) {
			return false;
		}
		// Primary signal: the capability/trait (survives a role relabel).
		if ( user_can( $uid, self::SHARED_DEVICE_CAP ) ) {
			return true;
		}
		// Back-compat input: the shared-device role slug (one input, not the sole test).
		$user = get_userdata( $uid );
		if ( $user && in_array( self::SHARED_DEVICE_ROLE, (array) $user->roles, true ) ) {
			return true;
		}
		return false;
	}

	public static function init() {
		add_action( 'user_register', [ __CLASS__, 'set_default_apps' ] );
	}

	/**
	 * Create/update custom roles on theme activation.
	 */
	public static function activate() {
		$admin_caps = get_role( 'administrator' ) ? get_role( 'administrator' )->capabilities : [];

		foreach ( self::$role_defs as $slug => $def ) {
			$caps = ! empty( $def['caps'] ) ? $def['caps'] : $admin_caps;

			// Update capabilities IN PLACE. Never remove_role() then add_role()
			// on a live site: WordPress filters each user's roles against the
			// currently registered set, so between the two calls every holder of
			// this role is role-less, and any permission check running in that
			// window falls through to a default. Activation can fire on any
			// request, so that window is real.
			$existing = get_role( $slug );
			if ( $existing ) {
				foreach ( array_keys( (array) $existing->capabilities ) as $cap ) {
					if ( ! isset( $caps[ $cap ] ) ) {
						$existing->remove_cap( $cap );
					}
				}
				foreach ( $caps as $cap => $grant ) {
					if ( $grant ) {
						$existing->add_cap( $cap );
					}
				}
			} else {
				add_role( $slug, $def['label'], $caps );
			}
		}

		// Add the zdz_access_app capability to all custom roles
		foreach ( array_keys( self::$role_defs ) as $slug ) {
			$role = get_role( $slug );
			if ( $role ) {
				$role->add_cap( 'zdz_access_app' );
			}
		}

		// Grant the shared-device TRAIT as a capability on the shared-device role,
		// so the kiosk / privacy checks that consult is_shared_device() keep working
		// after a role RELABEL. Re-applied every activation (same pattern as
		// zdz_access_app above), so the def-sync remove loop can never strip it for
		// good. The slug stays one input to the trait; this capability is the other.
		$shared = get_role( self::SHARED_DEVICE_ROLE );
		if ( $shared ) {
			$shared->add_cap( self::SHARED_DEVICE_CAP );
		}
	}

	/**
	 * Remove custom roles on theme deactivation.
	 */
	public static function deactivate() {
		foreach ( array_keys( self::$role_defs ) as $slug ) {
			remove_role( $slug );
		}
	}

	/**
	 * Assign default apps when a new user is registered.
	 */
	public static function set_default_apps( $user_id ) {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return;
		}

		$role = $user->roles[0] ?? 'subscriber';
		$apps = self::get_default_apps_for_role( $role );

		// null = "all apps" — fetch all app IDs
		if ( null === $apps && class_exists( 'ZDZ_Plugin_API' ) ) {
			$all_apps = ZDZ_Plugin_API::get_instance()->get_all_apps();
			$apps = array_keys( $all_apps );
		}

		if ( ! is_array( $apps ) ) {
			$apps = [];
		}

		update_user_meta( $user_id, 'zdz_allowed_apps', $apps );
	}
}

ZDZ_User_Roles::init();
