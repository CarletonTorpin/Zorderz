<?php
/**
 * "View As" Role Switcher for Admins
 *
 * Lets administrators preview the frontend exactly as any custom role
 * would see it — restricted app list, correct role label, etc. — while
 * remaining logged in with full admin privileges.
 *
 * Usage:
 *   ?zdz_view_as=zdz_operator   → preview as Operator
 *   ?zdz_view_as=zdz_sales      → preview as Salesperson
 *   ?zdz_view_as=zdz_tech       → preview as Field Tech
 *   ?zdz_view_as=zdz_admin      → preview as TS Admin (sees all apps)
 *   ?zdz_view_as=reset         → exit preview, return to real admin view
 *
 * UI surfaces (v2.14.0):
 *   1. WP Admin Bar item (admin-only, in wp-admin) — primary entry point.
 *      The role-switching links live under a "👁 View As…" node added to
 *      the top-secondary area of the admin bar. See add_admin_bar_menu().
 *   2. Frontend banner — shown ONLY while actively emulating a role,
 *      strictly as a "you're previewing as X" indicator with an Exit link.
 *      Rendered by render_banner().
 *
 * The pre-2.14 always-visible floating button was removed because it
 * sat at top:8px right:12px with z-index:99999 and overlapped controls
 * in the TS Internal Messaging plugin (search/bell/settings) on iPhone
 * and iPad viewports. Admins who want quick frontend access can either
 * (a) use the URL param mechanism, (b) bookmark common roles, or
 * (c) enable their own admin bar from User Profile → Toolbar.
 *
 * Implementation: Uses a session cookie (zdz_view_as) so the emulation
 * persists across page loads but clears when the browser closes.
 *
 * Security: Only users with `manage_options` can activate view-as mode.
 * AJAX endpoints are NOT affected — the admin retains full privileges
 * for all backend operations. Only the frontend app visibility and
 * displayed role are overridden.
 *
 * @package Zorderz
 * @since   2.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZDZ_View_As {

	/** @var string|null  The role being emulated, or null if not emulating. */
	private static $emulated_role = null;

	/** @var int|null  If set, emulate this specific user's actual meta. */
	private static $emulated_user_id = null;

	/** @var string[]  Roles that can be emulated. */
	private static $valid_roles = [ 'zdz_owner', 'zdz_admin', 'zdz_sales', 'zdz_operator', 'zdz_mfg', 'zdz_tech', 'zdz_general' ];

	// ── Bootstrap ────────────────────────────────────────────────

	public static function init() {
		add_action( 'init', [ __CLASS__, 'handle_query_param' ], 1 );
		add_action( 'wp_footer', [ __CLASS__, 'render_banner' ] );
		// v2.14.0: primary UI moved off the front-end to the WP Admin Bar.
		// Priority 90 puts our node after WP's stock items (Howdy, Logout)
		// while still keeping it within the user-facing right-side cluster.
		add_action( 'admin_bar_menu', [ __CLASS__, 'add_admin_bar_menu' ], 90 );
	}

	// ── Query Param / Cookie Handling ────────────────────────────

	/**
	 * On every page load, check for ?zdz_view_as=ROLE and set/clear the cookie.
	 * Runs on the `init` hook (before any output) so setcookie() works.
	 */
	public static function handle_query_param() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( isset( $_GET['zdz_view_as'] ) ) { // phpcs:ignore WordPress.Security
			$role = sanitize_text_field( wp_unslash( $_GET['zdz_view_as'] ) );

			if ( 'reset' === $role || '' === $role ) {
				// Clear emulation
				setcookie( 'zdz_view_as', '', time() - 3600, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true );
				self::$emulated_role = null;
			} elseif ( in_array( $role, self::$valid_roles, true ) ) {
				// Activate emulation (session cookie — no expiry)
				setcookie( 'zdz_view_as', $role, 0, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true );
				self::$emulated_role = $role;
			}
		} elseif ( isset( $_COOKIE['zdz_view_as'] ) ) { // phpcs:ignore WordPress.Security
			$role = sanitize_text_field( wp_unslash( $_COOKIE['zdz_view_as'] ) );
			if ( in_array( $role, self::$valid_roles, true ) ) {
				self::$emulated_role = $role;
			}
		}

		// v2.12.3: optional per-user View-As.
		if ( isset( $_GET['zdz_view_as_user'] ) ) { // phpcs:ignore WordPress.Security
			$uid = absint( wp_unslash( $_GET['zdz_view_as_user'] ) );
			if ( 0 === $uid ) {
				setcookie( 'zdz_view_as_user', '', time() - 3600, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true );
				self::$emulated_user_id = null;
			} else {
				$u = get_userdata( $uid );
				if ( $u ) {
					setcookie( 'zdz_view_as_user', (string) $uid, 0, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true );
					self::$emulated_user_id = $uid;
					$r = $u->roles[0] ?? '';
					if ( in_array( $r, self::$valid_roles, true ) ) {
						setcookie( 'zdz_view_as', $r, 0, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true );
						self::$emulated_role = $r;
					}
				}
			}
		} elseif ( isset( $_COOKIE['zdz_view_as_user'] ) ) { // phpcs:ignore WordPress.Security
			self::$emulated_user_id = absint( wp_unslash( $_COOKIE['zdz_view_as_user'] ) ) ?: null;
		}
	}

	// ── Public Getters ───────────────────────────────────────────

	/** Whether an admin is currently previewing as another role. */
	public static function is_emulating(): bool {
		return ! empty( self::$emulated_role ) && current_user_can( 'manage_options' );
	}

	/** The role slug being emulated (e.g. 'zdz_operator'), or null. */
	public static function get_emulated_role(): ?string {
		return self::$emulated_role;
	}

	/** The user ID being emulated (if any), or null. */
	public static function get_emulated_user_id(): ?int {
		return self::$emulated_user_id;
	}

	/**
	 * Get the default allowed apps for a given role.
	 * Delegates to the centralised ZDZ_User_Roles class.
	 *
	 * @param string $role  Role slug.
	 * @return array|null   Array of app IDs, or null for "all apps" (admin).
	 */
	public static function get_default_apps_for_role( string $role ) {
		if ( class_exists( 'ZDZ_User_Roles' ) ) {
			return ZDZ_User_Roles::get_default_apps_for_role( $role );
		}
		return [];
	}

	/** Human-readable label for a role slug. */
	public static function get_role_label( string $role ): string {
		if ( class_exists( 'ZDZ_User_Roles' ) ) {
			$labels = ZDZ_User_Roles::get_role_labels();
			return $labels[ $role ] ?? $role;
		}
		return $role;
	}

	// ── Banner UI ────────────────────────────────────────────────

	/**
	 * Renders a thin "you're previewing as X" indicator at the top of the
	 * page when — and only when — an admin is actively emulating a role.
	 *
	 * Hooked to `wp_footer` so it renders after the SPA shell.
	 *
	 * v2.14.0: This used to also render an always-visible "👁 View As…"
	 * floating button at top:8px right:12px so admins could activate
	 * emulation from the front-end. That button overlapped the messaging
	 * plugin's header controls (search/bell/settings) on iPhone and iPad
	 * viewports and was visible on every page load even when not in use.
	 * The role-switching UI moved to the WP Admin Bar (see
	 * add_admin_bar_menu()). This method now renders nothing unless an
	 * emulation session is in progress.
	 */
	public static function render_banner() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Don't render on admin pages or login.
		if ( is_admin() || is_page( 'login' ) || is_page( 'register' ) ) {
			return;
		}

		// v2.14.0: render NOTHING unless actively emulating. The role
		// picker lives in the admin bar now; the front-end surface is
		// strictly an "active session" indicator.
		if ( ! self::is_emulating() ) {
			return;
		}

		$current_role  = self::$emulated_role;
		$current_label = self::get_role_label( $current_role );
		$reset_url     = esc_url( add_query_arg( 'zdz_view_as', 'reset' ) );
		?>

		<!-- TS View-As active-session banner (admin only, only while emulating) -->
		<div id="zdz-view-as" style="
			position: fixed;
			top: 0; left: 0; right: 0;
			z-index: 99999;
			font-family: 'Inter', -apple-system, sans-serif;
			font-size: 13px;
		">
			<div style="
				background: linear-gradient(135deg, #7c3aed 0%, #6d28d9 100%);
				color: #fff;
				padding: 8px 16px;
				display: flex;
				align-items: center;
				justify-content: center;
				gap: 14px;
				flex-wrap: wrap;
				box-shadow: 0 2px 12px rgba(109,40,217,.35);
			">
				<span style="font-weight: 600;">
					&#128065; Viewing as: <strong><?php echo esc_html( $current_label ); ?></strong>
				</span>

				<!-- Role switcher buttons (kept here for one-tap switching while previewing) -->
				<span style="display:flex; gap:6px;">
					<?php foreach ( self::$valid_roles as $r ) :
						$label = self::get_role_label( $r );
						$url   = esc_url( add_query_arg( 'zdz_view_as', $r ) );
						$bg    = ( $r === $current_role ) ? 'rgba(255,255,255,.3)' : 'rgba(255,255,255,.12)';
						$brd   = ( $r === $current_role ) ? '2px solid rgba(255,255,255,.6)' : '2px solid transparent';
					?>
						<a href="<?php echo $url; ?>" style="
							background: <?php echo $bg; ?>;
							border: <?php echo $brd; ?>;
							color: #fff;
							padding: 3px 10px;
							border-radius: 4px;
							text-decoration: none;
							font-size: 12px;
							font-weight: 500;
							white-space: nowrap;
						"><?php echo esc_html( $label ); ?></a>
					<?php endforeach; ?>
				</span>

				<!-- Exit button -->
				<a href="<?php echo $reset_url; ?>" style="
					background: rgba(255,255,255,.18);
					color: #fff;
					padding: 4px 14px;
					border-radius: 4px;
					text-decoration: none;
					font-size: 12px;
					font-weight: 600;
					margin-left: 4px;
				">&#10005; Exit Preview</a>
			</div>
		</div>

		<!-- Push SPA content down to avoid overlap -->
		<style>
			#view-main { padding-top: 42px !important; }
		</style>

		<?php
	}

	// ── WP Admin Bar UI (v2.14.0) ────────────────────────────────

	/**
	 * Register a "View As…" menu in the WP Admin Bar.
	 *
	 * Replaces the front-end floating button removed in v2.14.0. The node
	 * is added to the `top-secondary` cluster (right side, next to Howdy)
	 * so it sits among other user-context controls. Each role becomes a
	 * child node; an "Exit Preview" entry appears while emulating.
	 *
	 * The admin bar is suppressed on the front-end by the theme
	 * (`add_filter('show_admin_bar', '__return_false')` in functions.php),
	 * so this menu is visible in wp-admin. Admins flip a role there, then
	 * navigate to the front-end to preview. The `?zdz_view_as=ROLE` URL
	 * param mechanism continues to work for power-user flows.
	 *
	 * @param WP_Admin_Bar $wp_admin_bar  The admin bar instance.
	 */
	public static function add_admin_bar_menu( $wp_admin_bar ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$current_role  = self::$emulated_role;
		$current_label = $current_role ? self::get_role_label( $current_role ) : '';

		// Top-level node title reflects current state at a glance.
		$title = $current_role
			? sprintf(
				/* translators: %s = role label currently being emulated */
				esc_html__( '👁 Viewing as: %s', 'zorderz' ),
				esc_html( $current_label )
			)
			: esc_html__( '👁 View As…', 'zorderz' );

		$wp_admin_bar->add_node( [
			'id'     => 'zdz-view-as',
			'parent' => 'top-secondary',
			'title'  => $title,
			'href'   => '#',
			'meta'   => [
				'title' => $current_role
					? esc_attr__( 'Currently previewing the front-end as another role. Click to switch or exit.', 'zorderz' )
					: esc_attr__( 'Preview the front-end as a non-admin role without losing your admin session.', 'zorderz' ),
			],
		] );

		// Role children — one node per valid role. Hrefs target the
		// front-end home so the cookie is set against COOKIEPATH and the
		// admin lands on the SPA already in preview mode.
		foreach ( self::$valid_roles as $r ) {
			$label      = self::get_role_label( $r );
			$is_current = ( $r === $current_role );
			$wp_admin_bar->add_node( [
				'parent' => 'zdz-view-as',
				'id'     => 'zdz-view-as-' . $r,
				'title'  => ( $is_current ? '✓ ' : '' ) . esc_html( $label ),
				'href'   => esc_url( add_query_arg( 'zdz_view_as', $r, home_url( '/' ) ) ),
			] );
		}

		// Exit Preview entry — only while emulating.
		if ( $current_role ) {
			$wp_admin_bar->add_node( [
				'parent' => 'zdz-view-as',
				'id'     => 'zdz-view-as-reset',
				'title'  => esc_html__( '✕ Exit Preview', 'zorderz' ),
				'href'   => esc_url( add_query_arg( 'zdz_view_as', 'reset', home_url( '/' ) ) ),
			] );
		}
	}
}
