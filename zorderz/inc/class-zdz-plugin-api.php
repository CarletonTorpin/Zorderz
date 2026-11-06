<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZDZ_Plugin_API {

	private static $instance = null;
	private $apps = null;

	/** App ids declined at boot (a declared hot-path class was undefined) => missing[]. */
	private static $unavailable = [];

	/**
	 * An app's short name, for the launcher tile, the dock and the sticky strip. Its full name (`nm`) stays everywhere
	 * else: the app's page header, search, the large cards, the ask bar, the audit row, and the tile's title and spoken
	 * label.
	 *
	 * An app registers its own with `'short_name' => 'Doors'` (or the compact `'snm'`) in its config: plain text,
	 * SHORT_NAME_MAX characters at most, else ignored. Until an app does, the list below answers for the platform's
	 * own apps whose names do not fit a launcher column; `zdz_app_short_name` (string $short, string $app_id,
	 * array $config) has the last word. With no short name the tile draws the full name, ending in "…" if it does not
	 * fit. An app that registers its own short_name wins over this list; an app id here that is not installed does
	 * nothing.
	 */
	const SHORT_NAME_MAX = 10;
	const SHORT_NAMES    = [
		'doorhangers'   => 'Hangers',    // Doorhangers
		'team-roles'    => 'Roles',      // Team Roles
		'route-planner' => 'Routes',     // Route Planner
		'territories'   => 'Territory',  // Territories
	];

	/**
	 * The short name to send for one app ('' when it has none).
	 *
	 * @param string $app_id
	 * @param array  $config The app's config, after the full-name mapping.
	 * @return string
	 */
	public static function short_name_for( string $app_id, array $config ): string {
		$clean = static function ( $v ): string {
			if ( ! is_string( $v ) ) {
				return '';
			}
			// Text, not markup: entities decoded, then tags stripped (so "&lt;b&gt;" cannot become a tag; the page
			// escapes it anyway), spaces squeezed. An invalid UTF-8 string makes preg_replace() return null, which is ''.
			$v   = trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( html_entity_decode( $v, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) ) );
			$len = function_exists( 'mb_strlen' ) ? mb_strlen( $v, 'UTF-8' ) : strlen( $v );
			return ( '' !== $v && $len <= self::SHORT_NAME_MAX ) ? $v : '';
		};
		$short = $clean( $config['short_name'] ?? '' );
		if ( '' === $short ) {
			$short = $clean( $config['snm'] ?? '' );
		}
		if ( '' === $short ) {
			$short = $clean( self::SHORT_NAMES[ $app_id ] ?? '' );
		}
		return $clean( apply_filters( 'zdz_app_short_name', $short, $app_id, $config ) );
	}

	/**
	 * The launcher fields an app config gets from the theme: its short name (`snm`, sent only when it differs from
	 * `nm`) and its group (`grp`, with the group's label, colour and ink from ZDZ_App_Groups; the app's own colour and
	 * category are kept as `cc_plugin` / `cat_plugin`). The theme, not the app, decides an app's colour: a colour
	 * means a group.
	 *
	 * @param array  $config The app's config, after the full-name mapping.
	 * @param string $app_id
	 * @return array
	 */
	public static function launcher_fields( array $config, string $app_id ): array {
		$short = self::short_name_for( $app_id, $config );
		unset( $config['short_name'], $config['snm'] );
		if ( '' !== $short && $short !== ( $config['nm'] ?? '' ) ) {
			$config['snm'] = $short;
		}
		if ( class_exists( 'ZDZ_App_Groups' ) ) {
			$config = ZDZ_App_Groups::apply( $config, $app_id );
		}
		return $config;
	}

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
					// Index by the app's config 'id' instead of the
					// numeric array position. This ensures zdz_allowed_apps user
					// meta stores stable string IDs ('estimate-creator',
					// 'internal-messaging', etc.) rather than fragile numeric
					// indices that shift when plugins are activated/deactivated
					// or load in a different order.
					$config = $app->get_config();
					$app_id = $config['id'] ?? null;
					if ( ! $app_id ) {
						continue;
					}
					// Boot fail-loud: an app that registered its tile but whose
					// declared hot-path class never loaded (a partly uploaded zip) must
					// NOT render — it would fatal on the first call at wp_head. Decline
					// it gracefully: log a disposition + admin notice and skip, so no
					// half-wired tile appears. A complete install is unaffected (no app
					// declares a class it does not ship, so the miss list is empty).
					$missing = self::missing_declared_classes( $app_id, $config );
					if ( ! empty( $missing ) ) {
						self::$unavailable[ $app_id ] = $missing;
						do_action( 'zdz_disposition', 'app_manifest', [ 'app' => $app_id, 'missing' => $missing ] );
						self::notice_app_unavailable( $app_id, $config, $missing );
						continue;
					}
					$this->apps[ $app_id ] = $app;
				}
			}
		}
		return $this->apps;
	}

	/**
	 * Was app <app_id> available at boot? False once it has been declined because a
	 * declared hot-path class was undefined (a half-installed / partial-zip app).
	 * Other surfaces (tiles, gates) can consult this to stay consistent with the skip.
	 */
	public static function is_app_available( string $app_id ): bool {
		self::get_instance()->get_all_apps(); // ensure the boot assertion has run.
		return ! isset( self::$unavailable[ $app_id ] );
	}

	/**
	 * The declared hot-path classes an app registers with that are NOT defined. An app
	 * may declare them inline in its config ('classes'/'requires_classes' — future apps
	 * and connections packs), and Core seeds/filters a per-id map (code metadata only —
	 * no business literal) via `zdz_app_required_classes`. Empty result = all present.
	 *
	 * @return string[] Missing class names (empty when the app is complete).
	 */
	private static function missing_declared_classes( string $app_id, array $config ): array {
		$declared = [];
		foreach ( [ 'classes', 'requires_classes' ] as $k ) {
			if ( ! empty( $config[ $k ] ) && is_array( $config[ $k ] ) ) {
				$declared = array_merge( $declared, $config[ $k ] );
			}
		}
		$map = apply_filters( 'zdz_app_required_classes', self::default_required_classes() );
		if ( isset( $map[ $app_id ] ) && is_array( $map[ $app_id ] ) ) {
			$declared = array_merge( $declared, $map[ $app_id ] );
		}
		$missing = [];
		foreach ( array_unique( $declared ) as $cls ) {
			$cls = (string) $cls;
			if ( '' !== $cls && ! class_exists( $cls ) && ! interface_exists( $cls ) && ! trait_exists( $cls ) ) {
				$missing[] = $cls;
			}
		}
		return $missing;
	}

	/**
	 * Core-seeded hot-path class map (keyed by the app's config id). Declares the
	 * classes that MUST exist once each app has registered, so a partial install is
	 * caught here instead of fataling at wp_head. Purely declarative code metadata;
	 * the build-time gate in build.sh mirrors it. Extend as apps declare more.
	 */
	private static function default_required_classes(): array {
		return [
			'sales-analytics' => [ 'ZANA_Chat', 'ZANA_Prompt_Builder', 'ZANA_Markers' ],
			'surveys'         => [ 'ZSV_Survey_Manager', 'ZSV_DB' ],
		];
	}

	/** Queue a one-line admin notice that a partially-installed app was disabled. */
	private static function notice_app_unavailable( string $app_id, array $config, array $missing ): void {
		$label = (string) ( $config['nm'] ?? $config['name'] ?? $app_id );
		add_action(
			'admin_notices',
			static function () use ( $label, $missing ) {
				printf(
					'<div class="notice notice-error"><p><strong>Zorderz:</strong> %s</p></div>',
					esc_html( sprintf(
						/* translators: 1: app label, 2: missing class name(s) */
						__( 'The %1$s app is partially installed — %2$s is missing — and has been disabled to protect the site.', 'zorderz' ),
						$label,
						implode( ', ', $missing )
					) )
				);
			}
		);
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
			// per-user View-As uses REAL user meta.
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

		// Additive per-person grants contributed by apps (`zdz_extra_allowed_apps`, ADD-only): applied
		// after Safe Mode and merged into the allow list, which the loop below still checks against
		// the deny list, so neither can be overridden here. Skipped for the admin tier (they already
		// see every app) and for a role-only View-As preview (no person to ask about).
		$extras_uid = $emulating ? (int) ( $emulated_uid ?? 0 ) : $user_id;
		if ( ! $is_admin && $extras_uid > 0 ) {
			$extra = apply_filters( 'zdz_extra_allowed_apps', [], $extras_uid );
			if ( is_array( $extra ) && $extra ) {
				$allowed_apps = array_values( array_unique( array_merge( $allowed_apps, array_filter( $extra, 'is_string' ) ) ) );
			}
		}
		// An account in Privacy View sees only the apps made safe for it, whatever else it may do.
		$privacy = class_exists( 'ZDZ_Privacy_View' ) && ZDZ_Privacy_View::is_on( $user_id );

		foreach ( $all_apps as $app_id => $app ) {
			// Denied apps are ALWAYS blocked — even for admins/owners
			if ( in_array( $app_id, $denied_apps, true ) ) {
				continue;
			}
			if ( $privacy && ! in_array( (string) $app_id, ZDZ_Privacy_View::ALLOWED_APPS, true ) ) {
				continue;
			}

			if ( $is_admin || in_array( $app_id, $allowed_apps, true ) ) {
				$config = $app->get_config();

				// Normalize config keys — some plugins use compact keys
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

				// Display name overrides — shorter names for mobile dock/bar
				$name_overrides = [
					'prep'                  => 'Prep',
					'receipts'              => 'Receipts',
					// "Commissions" wraps to two lines on phones at
					// --ref-font-lg, inflating its dock tile. The shorter
					// "Commission" reads cleanly on one line next to the
					// calculator icon. (Plugin registers as 'commission-calculator',
					// matching the no-zdz-prefix convention of the keys above.)
					'commission-calculator' => 'Commission',
				];
				if ( isset( $name_overrides[ $app_id ] ) ) {
					$config['nm'] = $name_overrides[ $app_id ];
				}

				// The short name and the group (colour, label, ink) the launcher draws.
				$config = self::launcher_fields( $config, (string) $app_id );

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
	 *   1b. Privacy View account           → only ZDZ_Privacy_View::ALLOWED_APPS
	 *   2. WP super-admin (manage_options) → true
	 *   3. Safe Mode on                    → false (user sees NO apps)
	 *   4. app in zdz_denied_apps           → false (ALWAYS blocked, even admins)
	 *   5. admin-tier role                 → true  (owner/admin get all non-denied)
	 *   5b. an app's additive grant         → true  (zdz_extra_allowed_apps; ADD-only)
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

		// 1b. Privacy View reaches only the apps made safe for it, whatever else the account may do.
		if ( class_exists( 'ZDZ_Privacy_View' ) && ZDZ_Privacy_View::is_on( $user_id ) ) {
			return in_array( $app_id, ZDZ_Privacy_View::ALLOWED_APPS, true );
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

		// 5b. Additive per-person grants contributed by apps (ADD-only), placed AFTER Safe Mode (3)
		//     and the deny list (4) so neither can be overridden here.
		$extra_apps = apply_filters( 'zdz_extra_allowed_apps', [], $user_id );
		if ( is_array( $extra_apps ) && in_array( $app_id, $extra_apps, true ) ) {
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
