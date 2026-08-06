<?php
/**
 * Kiosk / "Demo Mode" — hand the device to a guest safely.
 *
 * Lets a real administrator flip the running app into the General
 * (shared-kiosk) account's identity with one tap, hand the phone to someone
 * for a demo, and be confident they cannot do any damage — then flip back
 * with a PIN. The admin's actual login session is never touched.
 *
 * ─────────────────────────────────────────────────────────────────────────
 *  WHY THIS IS NOT just "View As"
 * ─────────────────────────────────────────────────────────────────────────
 * ZDZ_View_As overrides only the *displayed* role and the front-end app list;
 * its own docblock notes "AJAX endpoints are NOT affected — the admin retains
 * full privileges for all backend operations." That is fine for previewing a
 * layout, but it is unsafe for handing the device to a stranger: they could
 * hit an admin AJAX/REST endpoint directly and act with full admin power.
 *
 * This feature instead performs a true, reversible, server-side IDENTITY
 * switch: for the duration of demo mode, every request resolves
 * get_current_user_id() to the REAL General account. All downstream systems —
 * chat session CRUD, ZDZ_User_Media, messaging capabilities, ZDZ_Data_Permissions
 * redaction, greeting, dashboard — therefore see the General user and its
 * all-deny zdz_general profile. There is no elevated surface left exposed,
 * because at runtime the request *is* the kiosk user, not the admin.
 *
 * ─────────────────────────────────────────────────────────────────────────
 *  SECURITY MODEL
 * ─────────────────────────────────────────────────────────────────────────
 *  • ENTER  requires the cookie-authenticated user to currently hold
 *           `manage_options` (capability check, not a role-string check) and
 *           a valid wp_rest nonce. A 4–10 digit PIN is chosen at entry.
 *  • STATE  is recorded in the admin's OWN user-meta (`zdz_kiosk_demo`):
 *           { active, pin_hash, target_user_id, started_at, real_user_id }.
 *           The PIN is stored only as a wp_hash_password() hash. Nothing
 *           sensitive lives in a cookie the guest could read or edit.
 *  • SWITCH happens on every request, very early (set_current_user), keyed
 *           off that meta. The WordPress AUTH COOKIE IS NEVER REWRITTEN — so
 *           the true admin session is intact and is restored the instant the
 *           meta is cleared.
 *  • EXIT   requires POSTing the correct PIN. The server verifies the hash
 *           against the stored value, clears the meta, and the next request
 *           resolves back to the admin. A guest without the PIN cannot leave
 *           kiosk mode and cannot reach admin powers.
 *  • RELOAD keeps demo mode active (state is server-side), per design — the
 *           guest cannot escape it by closing/reopening the tab.
 *
 * The "real admin" is identified independently of the switched runtime user
 * by validating the auth cookie (wp_validate_auth_cookie) — so even after the
 * runtime identity has become the kiosk user, the enter/exit endpoints still
 * know who the operator is and that they were authorised to start the demo.
 *
 * @package Zorderz
 * @since   2.21.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZDZ_Kiosk_Demo {

	/** User-meta key holding the active demo record (stored on the ADMIN user). */
	const META_KEY = 'zdz_kiosk_demo';

	/** Cached resolution of the General target user id for this request. */
	private static $target_uid_cache = null;

	/** The real (cookie-authenticated) user id, captured before any switch. */
	private static $real_uid = null;

	/** True once we have actually switched identity this request. */
	private static $switched = false;

	// ── Bootstrap ────────────────────────────────────────────────

	public static function init() {
		// Override the current user at the SOURCE, the canonical way. WordPress
		// applies the `determine_current_user` filter inside its user-population
		// routine; its own cookie-based resolver runs at priority 20. We run at
		// 100 (after) so we receive the genuinely-authenticated user id as
		// $user_id, decide whether this admin is in demo mode, and if so return
		// the General account id instead. Because this intercepts at the point
		// every reader (REST, AJAX, front-end, get_current_user_id) ultimately
		// pulls from, there is no hook-ordering fragility — the switch is total
		// and consistent for the whole request. The auth COOKIE is never
		// altered, so the real admin session is intact and is restored the
		// instant the demo record is cleared.
		add_filter( 'determine_current_user', [ __CLASS__, 'filter_current_user' ], 100 );

		// Defensive: if some very early code (an aggressive plugin) already
		// triggered current-user resolution BEFORE this filter was registered,
		// WordPress will have cached the admin in the $current_user global and
		// our filter would never be consulted. On 'init' (priority 0) we detect
		// that case — an authenticated admin who has an active demo record but
		// whose runtime id was NOT switched — and force a single clean
		// re-resolution so the switch applies. This is a no-op on the common
		// path where the filter already ran.
		add_action( 'init', [ __CLASS__, 'ensure_switch_applied' ], 0 );

		// REST endpoints for enter / exit / status.
		add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );

		// Safety: block WordPress logout while demo mode is active. The auth
		// cookie belongs to the admin, so a guest hitting the logout URL would
		// otherwise end the admin's real session and strand them at the login
		// screen mid-demo. Demo mode must be exited (PIN) first; only then can
		// the admin log out. We hook the logout request handler directly so
		// this holds even if the UI button is bypassed.
		add_action( 'login_form_logout', [ __CLASS__, 'block_logout_during_demo' ], 0 );
		add_action( 'wp_logout', [ __CLASS__, 'block_logout_during_demo' ], 0 );
	}

	/**
	 * If the real admin is in an active demo, refuse logout and bounce home.
	 */
	public static function block_logout_during_demo() {
		$real_uid = self::resolve_real_user_id();
		if ( ! $real_uid ) {
			return;
		}
		$state = get_user_meta( $real_uid, self::META_KEY, true );
		if ( ! empty( $state ) && ! empty( $state['active'] ) ) {
			wp_safe_redirect( home_url( '/' ) );
			exit;
		}
	}

	/**
	 * Force a re-resolution of the current user if the demo switch should be
	 * active but wasn't applied (because the user global was populated before
	 * our determine_current_user filter registered).
	 */
	public static function ensure_switch_applied() {
		if ( self::$switched ) {
			return; // filter already did its job
		}
		$real_uid = self::resolve_real_user_id();
		if ( ! $real_uid ) {
			return;
		}
		$state = get_user_meta( $real_uid, self::META_KEY, true );
		if ( empty( $state ) || empty( $state['active'] ) ) {
			return;
		}
		// A demo record exists but we never switched → the global was cached
		// early. Clear it and pull the current user again; our filter (still
		// attached) will now perform the swap.
		global $current_user;
		$current_user = null;
		wp_get_current_user();
	}

	// ── Identity switch ──────────────────────────────────────────

	/**
	 * Swap the resolved current-user id to the General account when the
	 * authenticated admin has an active demo record.
	 *
	 * @param int|false $user_id The user id WordPress has already determined
	 *                           from the auth cookie (false if not logged in).
	 * @return int|false The General account id while demo mode is active for
	 *                   this admin; otherwise the unchanged $user_id.
	 */
	public static function filter_current_user( $user_id ) {
		// Re-entrancy guard: get_users()/user_can() below can trigger user
		// lookups; never let this filter recurse into itself.
		static $in_progress = false;
		if ( $in_progress ) {
			return $user_id;
		}

		$real_uid = (int) $user_id;
		if ( $real_uid <= 0 ) {
			return $user_id; // not logged in — nothing to switch
		}

		$in_progress = true;
		try {
			// Remember the genuine admin id for the enter/exit endpoints, which
			// must authorise the operator even after the runtime id is swapped.
			self::$real_uid = $real_uid;

			$state = get_user_meta( $real_uid, self::META_KEY, true );
			if ( empty( $state ) || empty( $state['active'] ) ) {
				return $user_id;
			}

			// The operator must (still) be an administrator. user_can() checks a
			// specific id without depending on the global current user.
			if ( ! user_can( $real_uid, 'manage_options' ) ) {
				delete_user_meta( $real_uid, self::META_KEY ); // stale on a non-admin
				return $user_id;
			}

			$target = (int) ( $state['target_user_id'] ?? 0 );
			if ( ! $target || $target === $real_uid || ! get_userdata( $target ) ) {
				return $user_id;
			}

			self::$switched = true;
			return $target; // ← every reader now sees the General account
		} finally {
			$in_progress = false;
		}
	}

	/**
	 * Resolve the genuine logged-in user id, independent of any switch.
	 *
	 * During a switched request, $real_uid is already captured by
	 * filter_current_user(). Otherwise we validate the auth cookie directly so
	 * the value is correct even before the filter has run (e.g. very early
	 * calls) and regardless of the swapped runtime user.
	 */
	private static function resolve_real_user_id(): int {
		if ( null !== self::$real_uid ) {
			return (int) self::$real_uid;
		}
		$uid = function_exists( 'wp_validate_auth_cookie' ) ? wp_validate_auth_cookie( '', 'logged_in' ) : 0;
		self::$real_uid = (int) $uid;
		return self::$real_uid;
	}

	/** Whether the current request is running inside an active demo switch. */
	public static function is_active(): bool {
		return self::$switched;
	}

	/** Public accessor for the genuine (cookie-authenticated) admin user id. */
	public static function get_real_user_id_public(): int {
		return self::resolve_real_user_id();
	}

	/**
	 * Find the General (zdz_general) account to impersonate.
	 *
	 * Resolved dynamically by role so there is no hardcoded id. If multiple
	 * users somehow hold zdz_general, the lowest id (oldest) wins for
	 * determinism. Returns 0 if no such account exists.
	 */
	public static function get_target_user_id(): int {
		if ( null !== self::$target_uid_cache ) {
			return (int) self::$target_uid_cache;
		}
		$ids = get_users( [
			'role'    => 'zdz_general',
			'fields'  => 'ID',
			'number'  => 1,
			'orderby' => 'ID',
			'order'   => 'ASC',
		] );
		self::$target_uid_cache = ! empty( $ids ) ? (int) $ids[0] : 0;
		return self::$target_uid_cache;
	}

	// ── REST API ─────────────────────────────────────────────────

	public static function register_routes() {
		// ENTER kiosk/demo mode.
		register_rest_route( 'zorderz/v1', '/kiosk-demo/enter', [
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => [ __CLASS__, 'rest_enter' ],
			'permission_callback' => [ __CLASS__, 'can_enter' ],
			'args'                => [
				'pin' => [
					'required'          => true,
					'sanitize_callback' => 'sanitize_text_field',
				],
			],
		] );

		// EXIT kiosk/demo mode (PIN required).
		register_rest_route( 'zorderz/v1', '/kiosk-demo/exit', [
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => [ __CLASS__, 'rest_exit' ],
			// NOTE: permission is intentionally just "logged in" — during demo
			// mode the runtime user is the kiosk account, which is NOT an
			// admin. Authorisation to exit is the PIN, verified in the handler.
			'permission_callback' => function () { return is_user_logged_in(); },
			'args'                => [
				'pin' => [
					'required'          => true,
					'sanitize_callback' => 'sanitize_text_field',
				],
			],
		] );

		// STATUS — lightweight, for the SPA to know which UI to show.
		register_rest_route( 'zorderz/v1', '/kiosk-demo/status', [
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => [ __CLASS__, 'rest_status' ],
			'permission_callback' => function () { return is_user_logged_in(); },
		] );
	}

	/**
	 * Permission check for ENTER: the genuine cookie user must be an admin.
	 * (We check the real user, not the runtime user, so this is correct even
	 * if a prior request already switched identity.)
	 */
	public static function can_enter(): bool {
		$uid = self::resolve_real_user_id();
		return $uid && user_can( $uid, 'manage_options' );
	}

	/**
	 * ENTER handler. Validates the PIN, finds the General target, and writes
	 * the demo record to the admin's user-meta.
	 */
	public static function rest_enter( WP_REST_Request $request ) {
		$admin_uid = self::resolve_real_user_id();
		if ( ! $admin_uid || ! user_can( $admin_uid, 'manage_options' ) ) {
			return new WP_Error( 'forbidden', 'Administrator session required.', [ 'status' => 403 ] );
		}

		$pin = preg_replace( '/\D/', '', (string) $request->get_param( 'pin' ) );
		if ( strlen( $pin ) < 4 || strlen( $pin ) > 10 ) {
			return new WP_Error( 'bad_pin', 'PIN must be 4–10 digits.', [ 'status' => 400 ] );
		}

		$target = self::get_target_user_id();
		if ( ! $target ) {
			return new WP_Error(
				'no_kiosk_user',
				'No account with the General (Shared Kiosk) role exists. Create/assign one first, then re-activate the theme so the role is present.',
				[ 'status' => 409 ]
			);
		}
		if ( $target === $admin_uid ) {
			return new WP_Error( 'self_target', 'The administrator account cannot itself be the kiosk target.', [ 'status' => 409 ] );
		}

		update_user_meta( $admin_uid, self::META_KEY, [
			'active'         => true,
			'pin_hash'       => wp_hash_password( $pin ),
			'target_user_id' => $target,
			'started_at'     => current_time( 'mysql' ),
			'real_user_id'   => $admin_uid,
		] );

		// Hand back a nonce valid for the GENERAL identity. The very next
		// request will switch identity, so the SPA's subsequent X-WP-Nonce
		// calls must verify against the kiosk user. We compute it by briefly
		// adopting the target identity to mint the nonce, then restoring.
		$nonce = self::make_nonce_for_user( $target, 'wp_rest' );

		return rest_ensure_response( [
			'success'       => true,
			'active'        => true,
			'redirect'      => home_url( '/' ),
			'nonce'         => $nonce,   // wp_rest nonce for the kiosk identity
			'message'       => 'Kiosk / Demo mode enabled. Reload to apply.',
		] );
	}

	/**
	 * EXIT handler. Verifies the PIN against the stored hash and clears the
	 * demo record. Works even though the runtime user is the kiosk account,
	 * because we look up the admin's record via the real cookie user.
	 */
	public static function rest_exit( WP_REST_Request $request ) {
		$admin_uid = self::resolve_real_user_id();
		if ( ! $admin_uid ) {
			return new WP_Error( 'no_session', 'No session.', [ 'status' => 403 ] );
		}

		$state = get_user_meta( $admin_uid, self::META_KEY, true );
		if ( empty( $state ) || empty( $state['active'] ) ) {
			// Already out — treat as success so the UI can settle.
			return rest_ensure_response( [ 'success' => true, 'active' => false ] );
		}

		// Throttle PIN guessing: the exit PIN is short (4 to 10 digits), so without a limit a
		// guest at the shared kiosk could brute-force back into the administrator's live session.
		// Lock the exit endpoint for this record after a handful of wrong tries; a correct PIN
		// clears the counter. Keyed on the admin whose kiosk this is (the target of the guessing).
		$lock_key = 'zdz_kiosk_pin_fail_' . $admin_uid;
		$fails    = (int) get_transient( $lock_key );
		$max      = (int) apply_filters( 'zdz_kiosk_pin_max_attempts', 5 );
		if ( $fails >= $max ) {
			return new WP_Error( 'locked', 'Too many incorrect PIN attempts. Wait a minute and try again.', [ 'status' => 429 ] );
		}

		$pin = preg_replace( '/\D/', '', (string) $request->get_param( 'pin' ) );
		if ( '' === $pin || ! wp_check_password( $pin, $state['pin_hash'] ?? '' ) ) {
			// Count the miss (with a cooldown window), then refuse without revealing closeness.
			set_transient( $lock_key, $fails + 1, (int) apply_filters( 'zdz_kiosk_pin_lockout_window', MINUTE_IN_SECONDS ) );
			return new WP_Error( 'bad_pin', 'Incorrect PIN.', [ 'status' => 403 ] );
		}

		delete_transient( $lock_key ); // correct PIN: clear the throttle
		delete_user_meta( $admin_uid, self::META_KEY );

		// Mint a fresh admin-identity nonce for the SPA's next calls.
		$nonce = self::make_nonce_for_user( $admin_uid, 'wp_rest' );

		return rest_ensure_response( [
			'success'  => true,
			'active'   => false,
			'redirect' => home_url( '/' ),
			'nonce'    => $nonce,
			'message'  => 'Returned to administrator.',
		] );
	}

	/** STATUS handler — what the SPA needs to decide which control to show. */
	public static function rest_status() {
		$admin_uid = self::resolve_real_user_id();
		$state     = $admin_uid ? get_user_meta( $admin_uid, self::META_KEY, true ) : '';
		$active    = self::$switched || ( ! empty( $state ) && ! empty( $state['active'] ) );

		return rest_ensure_response( [
			'active'           => (bool) $active,
			'kiosk_user_id'    => self::$switched ? get_current_user_id() : 0,
			'real_admin_id'    => (int) $admin_uid,
			'kiosk_role_label' => 'General (Shared Kiosk)',
			'started_at'       => $state['started_at'] ?? null,
		] );
	}

	// ── Helpers ──────────────────────────────────────────────────

	/**
	 * Create a nonce as if a specific user were current, then restore the
	 * runtime user. Nonces are user-bound; entering/exiting demo mode changes
	 * which identity subsequent requests run as, so the SPA needs a nonce that
	 * matches the identity it will have after the reload.
	 */
	private static function make_nonce_for_user( int $uid, string $action ): string {
		$prev = get_current_user_id();
		set_current_user( $uid );
		$nonce = wp_create_nonce( $action );
		set_current_user( $prev ); // restore runtime user for the rest of this request
		return $nonce;
	}
}

ZDZ_Kiosk_Demo::init();
