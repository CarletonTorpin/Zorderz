<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZDZ_Plugin_API {

	private static $instance = null;
	private $apps = null;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	/**
	 * Get all registered apps (with type validation).
	 *
	 * @return \Zorderz\App_Interface[]
	 */
	public function get_all_apps(): array {
		if ( null === $this->apps ) {
			$raw = apply_filters( 'zdz_register_apps', [] );
			$this->apps = [];
			foreach ( $raw as $app ) {
				if ( $app instanceof \Zorderz\App_Interface ) {
					// v2.14.3: Index by the app's config 'id' instead of the
					// numeric array position. This ensures zdz_allowed_apps user
					// meta stores stable string IDs ('estimate-creator',
					// 'internal-messaging', etc.) rather than fragile numeric
					// indices that shift when plugins are activated/deactivated
					// or load in a different order.
					$config = $app->get_config();
					$app_id = $config['id'] ?? null;
					if ( $app_id ) {
						$this->apps[ $app_id ] = $app;
					}
				}
			}
		}
		return $this->apps;
	}

	/**
	 * Get app configs filtered by user permissions.
	 *
	 * For apps with bridge_type 'inline_widget' that implement
	 * Widget_App_Interface, the widget HTML is pre-rendered and
	 * included in the config array so the SPA can inject it
	 * without an extra REST call.
	 *
	 * @param int $user_id
	 * @return array
	 */
	/**
	 * Permission order:
	 *   1. Safe Mode ON = user sees NO apps
	 *   2. Denied apps are ALWAYS blocked (even for admins/owners)
	 *   3. Admin/owner roles see all non-denied apps
	 *   4. Other roles only see explicitly allowed apps
	 */
	public function get_user_app_configs( int $user_id ): array {
		$all_apps = $this->get_all_apps();
		$configs = [];

		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return [];
		}

		// ── Safe Mode: if enabled, user sees NO apps ──
		$safe_mode = get_user_meta( $user_id, 'zdz_safe_mode', true );
		if ( $safe_mode ) {
			return [];
		}

		// ── Denied apps: always blocked, even for admins/owners ──
		$denied_apps = get_user_meta( $user_id, 'zdz_denied_apps', true );
		if ( ! is_array( $denied_apps ) ) {
			$denied_apps = [];
		}

		// ── View-As override: let admins preview another role's app list ──
		$emulating = class_exists( 'ZDZ_View_As' ) && ZDZ_View_As::is_emulating();

		if ( $emulating ) {
			// v2.12.3: per-user View-As uses REAL user meta.
			$emulated_uid = method_exists( 'ZDZ_View_As', 'get_emulated_user_id' ) ? ZDZ_View_As::get_emulated_user_id() : null;
			if ( $emulated_uid ) {
				$emu_user     = get_userdata( $emulated_uid );
				$is_admin     = $emu_user ? ZDZ_User_Roles::is_admin_role( $emu_user->roles[0] ?? '' ) : false;
				$allowed_apps = get_user_meta( $emulated_uid, 'zdz_allowed_apps', true );
				$denied_apps  = get_user_meta( $emulated_uid, 'zdz_denied_apps', true );
				if ( ! is_array( $denied_apps ) ) { $denied_apps = []; }
				if ( get_user_meta( $emulated_uid, 'zdz_safe_mode', true ) ) {
					return [];
				}
			} else {
				$emulated_role = ZDZ_View_As::get_emulated_role();
				$allowed_apps  = ZDZ_View_As::get_default_apps_for_role( $emulated_role );
				$is_admin      = ( null === $allowed_apps );
				if ( $is_admin ) {
					$allowed_apps = [];
				}
			}
		} else {
			$is_admin     = ZDZ_User_Roles::is_admin_role( $user->roles[0] ?? '' );
			$allowed_apps = get_user_meta( $user_id, 'zdz_allowed_apps', true );
		}

		if ( ! is_array( $allowed_apps ) ) {
			$allowed_apps = [];
		}

		foreach ( $all_apps as $app_id => $app ) {
			// Denied apps are ALWAYS blocked — even for admins/owners
			if ( in_array( $app_id, $denied_apps, true ) ) {
				continue;
			}

			if ( $is_admin || in_array( $app_id, $allowed_apps, true ) ) {
				$config = $app->get_config();

				// v2.17.1: Normalize config keys — some plugins use compact keys
				// (nm, cat, cc) and some use full keys (name, category, color).
				// The frontend JS reads compact keys, so map full → compact.
				if ( ! isset( $config['nm'] ) && isset( $config['name'] ) ) {
					$config['nm'] = $config['name'];
				}
				if ( ! isset( $config['cat'] ) && isset( $config['category'] ) ) {
					$config['cat'] = $config['category'];
				}
				if ( ! isset( $config['cc'] ) && isset( $config['color'] ) ) {
					$config['cc'] = $config['color'];
				}
				if ( ! isset( $config['desc'] ) && isset( $config['description'] ) ) {
					$config['desc'] = $config['description'];
				}

				// v2.17.1: Display name overrides — shorter names for mobile dock/bar
				$name_overrides = [
					'prep'                  => 'Prep',
					'receipts'              => 'Receipts',
					// v2.21.0: "Commissions" wraps to two lines on phones at
					// --ref-font-lg, inflating its dock tile. The shorter
					// "Commission" reads cleanly on one line next to the
					// calculator icon. (Plugin registers as 'commission-calculator',
					// matching the no-zdz-prefix convention of the keys above.)
					'commission-calculator' => 'Commission',
				];
				if ( isset( $name_overrides[ $app_id ] ) ) {
					$config['nm'] = $name_overrides[ $app_id ];
				}

				// Pre-render widget HTML for inline_widget apps
				if (
					isset( $config['bridge_type'] ) &&
					'inline_widget' === $config['bridge_type'] &&
					$app instanceof \Zorderz\Widget_App_Interface
				) {
					$config['widget_html'] = $app->render_dashboard_widget( $user_id );
				}

				$configs[] = $config;
			}
		}

		return $configs;
	}

	/**
	 * Canonical app-access check (theme pass, item #2).
	 *
	 * The single source of truth for "may this user reach app <app_id>?" — the
	 * same permission ladder get_user_app_configs() uses to decide which tiles a
	 * user sees, plus the role-default fallback the per-plugin gates carried:
	 *
	 *   1. no user / logged out            → false
	 *   2. WP super-admin (manage_options) → true
	 *   3. Safe Mode on                    → false (user sees NO apps)
	 *   4. app in zdz_denied_apps           → false (ALWAYS blocked, even admins)
	 *   5. admin-tier role                 → true  (owner/admin get all non-denied)
	 *   6. explicit zdz_allowed_apps set    → in_array($app_id, allowed)
	 *   7. no explicit list                → this role's DEFAULT app set
	 *                                         (ADD-only: never widens past the role)
	 *
	 * Evaluates the REAL user (NOT a ZDZ_View_As emulation): AJAX/REST writes must
	 * gate on who is actually making the request, and the PIN kiosk (zdz_general)
	 * is a genuine server-side identity switch, so it is correctly bounded here.
	 *
	 * Plugins should call this instead of hand-rolling the ladder; each keeps a
	 * thin local user_can_access() that delegates here when the method exists and
	 * falls back to its inline copy on an older theme, so gating stays identical.
	 *
	 * @param int    $user_id
	 * @param string $app_id   Stable string id, e.g. 'knowledge-vault'.
	 * @return bool
	 */
	public static function user_can_access_app( int $user_id, string $app_id ): bool {
		$user_id = $user_id ?: get_current_user_id();
		if ( ! $user_id || '' === $app_id ) {
			return false;
		}

		// 2. WP super-admins / manage-the-whole-site.
		if ( user_can( $user_id, 'manage_options' ) ) {
			return true;
		}

		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return false;
		}

		// 3. Safe Mode: user sees NO apps.
		if ( get_user_meta( $user_id, 'zdz_safe_mode', true ) ) {
			return false;
		}

		// 4. Denied apps are ALWAYS blocked — even for owners/admins.
		$denied = get_user_meta( $user_id, 'zdz_denied_apps', true );
		if ( is_array( $denied ) && in_array( $app_id, $denied, true ) ) {
			return false;
		}

		$role = $user->roles[0] ?? '';

		// 5. Owner/Admin-tier roles get all apps (except denied, handled above).
		if ( class_exists( 'ZDZ_User_Roles' ) && ZDZ_User_Roles::is_admin_role( $role ) ) {
			return true;
		}

		// 6. Explicit per-user allow list wins.
		$allowed = get_user_meta( $user_id, 'zdz_allowed_apps', true );
		if ( is_array( $allowed ) ) {
			return in_array( $app_id, $allowed, true );
		}

		// 7. No explicit list → fall back to this role's DEFAULT app set.
		//    (ADD-only: never widens beyond what the role would normally get.)
		if ( class_exists( 'ZDZ_User_Roles' ) ) {
			$defaults = ZDZ_User_Roles::get_default_apps_for_role( $role );
			if ( null === $defaults ) {
				return true; // null = "all apps" sentinel (admin-like roles).
			}
			return is_array( $defaults ) && in_array( $app_id, $defaults, true );
		}

		return false;
	}
}
