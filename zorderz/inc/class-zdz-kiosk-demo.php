<?php
/**
 * Kiosk / "Demo Mode" — hand the device to a guest safely.
 *
 * Lets a real administrator flip ONE DEVICE into the General (shared-kiosk)
 * account's identity, hand it to someone for a demo, and be confident they
 * cannot do any damage — then flip back with a PIN. The admin's actual login
 * session is never touched, and the admin's other devices stay as they are.
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
 * switch: for the duration of demo mode, every request FROM THAT DEVICE
 * resolves get_current_user_id() to the REAL General account. All downstream
 * systems — chat, ZDZ_User_Media, messaging, ZDZ_Data_Permissions redaction,
 * greeting, dashboard — therefore see the General user and its all-deny
 * zdz_general profile.
 *
 * ─────────────────────────────────────────────────────────────────────────
 *  SECURITY MODEL
 * ─────────────────────────────────────────────────────────────────────────
 *  • ENTER  requires the cookie-authenticated user to hold `manage_options`
 *           and a valid wp_rest nonce. A 4–10 digit PIN is chosen at entry,
 *           typed into the app's own masked sheet (never a browser prompt()).
 *  • STATE  lives in THIS DEVICE'S WordPress login session (the session
 *           record WP_Session_Tokens keeps for the token in the device's
 *           logged-in cookie), under the key `zdz_kiosk_demo`:
 *           { active, pin_hash, target_user_id, started_at, device, fails,
 *             locked_until }. The PIN is stored only as a wp_hash_password()
 *           hash, and is gone when the device leaves Demo Mode or its session
 *           ends. Nothing lives in a cookie the guest could read or edit.
 *           (An account-wide record would switch every device signed in as
 *           that admin, and the desktop app would then send an admin nonce
 *           from a General request, which WordPress refuses.)
 *  • SWITCH happens on every request, very early (determine_current_user),
 *           keyed off that session record. The auth cookie is never rewritten.
 *           The stored target is re-checked on every request: it must
 *           still be a General (zdz_general) account that is not an
 *           administrator. If it is not, the current General account is used;
 *           if there is none, the request runs signed out — never as the admin.
 *  • EXIT   requires the PIN. Five wrong PINs lock exit for 15 minutes, ten for
 *           an hour, fifteen for a day. An administrator on another device can
 *           end Demo Mode for a device, or sign it out, at Zorderz → Demo
 *           Mode in wp-admin.
 *  • RELOAD keeps demo mode active (state is server-side) — the guest cannot
 *           escape by closing the tab. Deleting the cookies signs the device
 *           out entirely (login screen), never back to the admin; with only
 *           the logged-in cookie gone, the auth cookie's token still finds
 *           the record (token()).
 *
 * Upgrading from the account-wide record in user meta: an active record is
 * copied onto the one sign-in it was bound to, or onto EVERY session of that
 * admin when it was not bound (each device that was in Demo Mode stays in it,
 * with the same PIN, and can now leave it on its own), then deleted.
 *
 * @package Zorderz
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZDZ_Kiosk_Demo {

	/** User-meta key of the earlier account-wide record. Read only to migrate it, then deleted. */
	const META_KEY = 'zdz_kiosk_demo';

	/** The key of the demo record inside a device's login-session record. */
	const SESSION_KEY = 'zdz_kiosk_demo';

	/** Option set to '2' once every legacy account-wide record has been moved onto sessions. */
	const STORE_OPTION = 'zdz_kiosk_demo_store';

	/** Wrong PINs in a row before exit locks. */
	const MAX_FAILS = 5;

	/** Cached resolution of the General target user id for this request. */
	private static $target_uid_cache = null;

	/** The real (cookie-authenticated) user id, captured before any switch. */
	private static $real_uid = null;

	/** True once we have actually switched identity this request. */
	private static $switched = false;

	/** Users whose legacy record was already looked for this request. */
	private static $migrated = array();

	/** The General account id this request actually runs as (0 when not switched). */
	private static $current_target = 0;

	/** Seconds a per-user write lock may be held before another request may take it over. */
	const LOCK_STALE_S = 10;

	/** Locks this request holds: uid => [depth, owner value]. */
	private static $held = array();

	// ── Bootstrap ────────────────────────────────────────────────

	public static function init() {
		// Override the current user at the SOURCE. WordPress's own cookie resolver runs at priority 20; we run at
		// 100, receive the genuinely-authenticated user id, decide whether THIS DEVICE is in demo mode, and if so
		// return the General account id instead.
		add_filter( 'determine_current_user', array( __CLASS__, 'filter_current_user' ), 100 );

		// Defensive: if some very early code resolved the current user BEFORE this filter was registered, the
		// admin is cached in $current_user and the filter never runs. On 'init' (priority 0) re-resolve once.
		add_action( 'init', array( __CLASS__, 'ensure_switch_applied' ), 0 );

		// Move any earlier account-wide record onto sessions, once.
		add_action( 'init', array( __CLASS__, 'maybe_migrate_all' ), 1 );

		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );

		// Block WordPress logout while this device is in demo mode: the auth cookie belongs to the admin, so a
		// guest hitting the logout URL would end the admin's session mid-demo.
		add_action( 'login_form_logout', array( __CLASS__, 'block_logout_during_demo' ), 0 );
		add_action( 'wp_logout', array( __CLASS__, 'block_logout_during_demo' ), 0 );
		// wp_logout() destroys the session of get_current_user_id(), the General account in Demo
		// Mode, so the administrator's session (holding the PIN hash) would outlive it. When any code signs this device
		// out, destroy the administrator's session for this device too, before the cookie goes.
		add_action( 'clear_auth_cookie', array( __CLASS__, 'destroy_demo_session_on_sign_out' ), 0 );

		// The administrator's reset: Zorderz → Demo Mode.
		add_action( 'admin_menu', array( __CLASS__, 'add_admin_page' ), 30 );
		add_action( 'admin_post_zdz_kiosk_demo_reset', array( __CLASS__, 'handle_admin_reset' ) );
	}

	/** If this device is in an active demo, refuse logout and bounce home. */
	public static function block_logout_during_demo() {
		$real_uid = self::resolve_real_user_id();
		if ( ! $real_uid ) {
			return;
		}
		$state = self::device_state( $real_uid );
		if ( ! empty( $state['active'] ) ) {
			wp_safe_redirect( home_url( '/' ) );
			exit;
		}
	}

	/** A device in Demo Mode being signed out by any code path: its administrator session goes with it. */
	public static function destroy_demo_session_on_sign_out() {
		$real_uid = self::resolve_real_user_id();
		$token    = self::token();
		if ( ! $real_uid || '' === $token || ! class_exists( 'WP_Session_Tokens' ) ) {
			return;
		}
		$state = self::device_state( $real_uid, $token );
		if ( ! empty( $state['active'] ) ) {
			WP_Session_Tokens::get_instance( $real_uid )->destroy( $token );
		}
	}

	/** Re-resolve the current user if the switch should be active but wasn't applied. */
	public static function ensure_switch_applied() {
		if ( self::$switched ) {
			return;
		}
		$real_uid = self::resolve_real_user_id();
		if ( ! $real_uid ) {
			return;
		}
		$state = self::device_state( $real_uid );
		if ( empty( $state['active'] ) ) {
			return;
		}
		global $current_user;
		$current_user = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- forcing one re-resolution
		wp_get_current_user();
	}

	// ── Where the state lives: this device's login session ───────

	/**
	 * This device's login-session token ('' when there is none). The logged-in cookie's first; without it, the token
	 * of the secure or plain auth cookie (one sign-in shares one token across its cookies). wp-admin and admin-ajax
	 * sign a request in from the auth cookie alone, so a device in Demo Mode whose logged-in cookie was deleted must
	 * still be found in Demo Mode there, never be the administrator again (fail closed).
	 */
	private static function token(): string {
		$t = function_exists( 'wp_get_session_token' ) ? (string) wp_get_session_token() : '';
		if ( '' !== $t || ! function_exists( 'wp_parse_auth_cookie' ) ) {
			return $t;
		}
		foreach ( array( 'secure_auth', 'auth' ) as $scheme ) {
			$c = wp_parse_auth_cookie( '', $scheme );
			if ( is_array( $c ) && ! empty( $c['token'] ) ) {
				return (string) $c['token'];
			}
		}
		return '';
	}

	/** The demo record for this device, or [] (moves a legacy account-wide record first). */
	public static function device_state( int $uid, ?string $token = null ): array {
		$token = null === $token ? self::token() : $token;
		if ( $uid <= 0 || '' === $token || ! class_exists( 'WP_Session_Tokens' ) ) {
			return array();
		}
		$fallback = self::maybe_migrate_user( $uid );
		$session  = WP_Session_Tokens::get_instance( $uid )->get( $token );
		if ( ! is_array( $session ) || empty( $session[ self::SESSION_KEY ] ) || ! is_array( $session[ self::SESSION_KEY ] ) ) {
			return ( is_array( $fallback ) && is_array( $session ) ) ? $fallback : array();
		}
		return $session[ self::SESSION_KEY ];
	}

	/**
	 * Every write to a user's sessions is read-modify-write of ONE user-meta row, so two requests at
	 * once (a burst of wrong PINs, a reset while a PIN is checked, another device signing in) could each write back the
	 * copy they read and undo the other. Our writes take a per-user lock: a row in wp_options inserted with INSERT
	 * IGNORE, which the unique option_name makes atomic. A lock older than LOCK_STALE_S is taken over. Waits up to 3 s.
	 */
	private static function lock( int $uid, int $tries = 30 ): bool {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || empty( $wpdb->options ) ) {
			return true; // no database (a unit test without one): nothing to race
		}
		if ( ! empty( self::$held[ $uid ] ) ) {
			self::$held[ $uid ]['depth']++; // re-entrant within this request
			return true;
		}
		$name = 'zdz_kd_lock_' . $uid;
		for ( $i = 0; $i < $tries; $i++ ) {
			// The value names its owner, so a holder that ran past LOCK_STALE_S and was taken over
			// releases only its own lock, never its successor's.
			$mine = time() . ':' . ( function_exists( 'wp_generate_password' ) ? wp_generate_password( 12, false ) : bin2hex( random_bytes( 6 ) ) );
			$got  = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $name, $mine ) );
			if ( 1 === (int) $got ) {
				self::$held[ $uid ] = array( 'depth' => 1, 'mine' => $mine );
				return true;
			}
			$cur = (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );
			$at  = (int) $cur;
			if ( $at > 0 && $at < time() - self::LOCK_STALE_S ) {
				$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $name, $cur ) );
				continue;
			}
			usleep( 100000 );
		}
		return false;
	}

	private static function unlock( int $uid ) {
		global $wpdb;
		if ( empty( self::$held[ $uid ] ) ) {
			return;
		}
		if ( --self::$held[ $uid ]['depth'] > 0 ) {
			return;
		}
		$mine = self::$held[ $uid ]['mine'];
		unset( self::$held[ $uid ] );
		if ( isset( $wpdb ) && is_object( $wpdb ) && ! empty( $wpdb->options ) ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", 'zdz_kd_lock_' . $uid, $mine ) );
		}
	}

	/** Forget this request's cached copy of the user's meta, so the next read comes from the database. */
	private static function fresh( int $uid ) {
		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( $uid, 'user_meta' );
		}
	}

	/**
	 * Write (array) or remove (null) this device's demo record, then read it back. Returns true only when what is
	 * stored now is what was asked for, so a caller can say "Nothing changed" truthfully when it is not.
	 */
	private static function write_state( int $uid, string $token, $state ): bool {
		if ( $uid <= 0 || '' === $token || ! class_exists( 'WP_Session_Tokens' ) ) {
			return false;
		}
		$manager = WP_Session_Tokens::get_instance( $uid );
		self::fresh( $uid ); // callers hold the lock: read what is in the database now, not this request's copy
		$session = $manager->get( $token );
		if ( ! is_array( $session ) ) {
			return false; // the session ended (signed out, expired): nothing to write to
		}
		if ( null === $state ) {
			unset( $session[ self::SESSION_KEY ] );
		} else {
			$session[ self::SESSION_KEY ] = $state;
		}
		$manager->update( $token, $session );
		self::fresh( $uid );
		$back = $manager->get( $token );
		if ( null === $state ) {
			return is_array( $back ) && ! isset( $back[ self::SESSION_KEY ] );
		}
		return is_array( $back ) && isset( $back[ self::SESSION_KEY ] ) && $back[ self::SESSION_KEY ] === $state;
	}

	// ── Upgrade from the account-wide record ──────────────────

	/**
	 * A legacy record on this user: if it was active, put it on EVERY one of their sessions (every device that was
	 * in Demo Mode stays in it — fail closed), then delete the user meta. Inactive leftovers are just deleted.
	 */
	public static function maybe_migrate_user( int $uid ): ?array {
		if ( isset( self::$migrated[ $uid ] ) ) {
			return null;
		}
		self::$migrated[ $uid ] = true;
		$legacy = get_user_meta( $uid, self::META_KEY, true );
		if ( '' === $legacy || false === $legacy || null === $legacy ) {
			return null;
		}
		if ( ! is_array( $legacy ) || empty( $legacy['active'] ) || empty( $legacy['pin_hash'] ) || ! class_exists( 'WP_Session_Tokens' ) ) {
			delete_user_meta( $uid, self::META_KEY ); // an inactive leftover
			return null;
		}
		if ( null === self::raw_sessions( $uid ) ) {
			// A session store we cannot list. Never drop the record (fail closed): each device
			// signed in before the upgrade copies it into its own session on its next request; after 14 days
			// (longer than any sign-in lasts) the account-wide record is deleted.
			self::migrate_into_this_session( $uid, $legacy );
			return null;
		}
		if ( ! self::lock( $uid ) ) {
			// Busy. The record stays and this request runs in Demo Mode from it (fail closed); the
			// next request tries the move again.
			unset( self::$migrated[ $uid ] );
			return self::legacy_record( $legacy );
		}
		try {
			self::fresh( $uid );
			// Read the record again under the lock: a request that waited must not put back a record
			// another request already moved (and whose Demo Mode may since have been ended with the PIN).
			$legacy = get_user_meta( $uid, self::META_KEY, true );
			if ( ! is_array( $legacy ) || empty( $legacy['active'] ) || empty( $legacy['pin_hash'] ) ) {
				return null;
			}
			$sessions = (array) self::raw_sessions( $uid );
			// A record bound to the one sign-in that started it goes to that sign-in only (if it has ended, the record
			// is stale and is dropped); an unbound record goes to every sign-in of the account (fail closed).
			$bound = (string) ( $legacy['session'] ?? '' );
			$only  = '' !== $bound ? hash( 'sha256', $bound ) : '';
			foreach ( $sessions as $verifier => $session ) {
				if ( '' !== $only && ! hash_equals( $only, (string) $verifier ) ) {
					continue;
				}
				if ( is_array( $session ) && ! isset( $session[ self::SESSION_KEY ] ) ) {
					$sessions[ $verifier ][ self::SESSION_KEY ] = self::legacy_record( $legacy );
				}
			}
			update_user_meta( $uid, 'session_tokens', $sessions );
			delete_user_meta( $uid, self::META_KEY );
			return null;
		} finally {
			self::unlock( $uid );
		}
	}

	/** The custom-store path of the migration: this device only, once, and only a sign-in from before the upgrade. */
	private static function migrate_into_this_session( int $uid, array $legacy ) {
		$at = (int) get_option( self::STORE_OPTION . '_at', 0 );
		if ( ! $at ) {
			$at = time();
			update_option( self::STORE_OPTION . '_at', $at, false );
		}
		$token = self::token();
		if ( '' !== $token ) {
			$manager = WP_Session_Tokens::get_instance( $uid );
			$session = $manager->get( $token );
			if ( is_array( $session ) && empty( $session['zdz_kiosk_demo_carried'] ) && (int) ( $session['login'] ?? 0 ) <= $at ) {
				$session['zdz_kiosk_demo_carried'] = 1;
				$session[ self::SESSION_KEY ]     = self::legacy_record( $legacy );
				$manager->update( $token, $session );
			}
		}
		if ( time() > $at + 14 * DAY_IN_SECONDS ) {
			delete_user_meta( $uid, self::META_KEY );
		}
	}

	private static function legacy_record( array $legacy ): array {
		return array(
			'active'         => true,
			'pin_hash'       => (string) $legacy['pin_hash'],
			'target_user_id' => (int) ( $legacy['target_user_id'] ?? 0 ),
			'started_at'     => (string) ( $legacy['started_at'] ?? current_time( 'mysql' ) ),
			'device'         => 'carried over from an earlier version',
			'fails'          => 0,
			'locked_until'   => 0,
		);
	}

	/** Once per site: move every legacy record (also for admins who never come back, so no PIN hash lingers). */
	public static function maybe_migrate_all() {
		if ( '2' === get_option( self::STORE_OPTION ) ) {
			return;
		}
		$ids = get_users( array( 'meta_key' => self::META_KEY, 'fields' => 'ID', 'number' => -1 ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
		foreach ( (array) $ids as $id ) {
			self::maybe_migrate_user( (int) $id );
		}
		update_option( self::STORE_OPTION, '2', true );
	}

	/**
	 * The user's sessions as WordPress stores them (verifier => session), or null when the site uses a session
	 * store other than WordPress's own user-meta one (then we cannot list or edit other devices' sessions).
	 */
	private static function raw_sessions( int $uid ) {
		$manager_class = apply_filters( 'session_token_manager', 'WP_User_Meta_Session_Tokens' );
		if ( 'WP_User_Meta_Session_Tokens' !== $manager_class ) {
			return null;
		}
		$sessions = get_user_meta( $uid, 'session_tokens', true );
		return is_array( $sessions ) ? $sessions : array();
	}

	// ── Identity switch ──────────────────────────────────────────

	/**
	 * Swap the resolved current-user id to the General account when THIS DEVICE's session is in demo mode.
	 *
	 * @param int|false $user_id The user id WordPress determined from the auth cookie (false if not logged in).
	 * @return int|false
	 */
	public static function filter_current_user( $user_id ) {
		static $in_progress = false;
		if ( $in_progress ) {
			return $user_id;
		}
		$real_uid = (int) $user_id;
		if ( $real_uid <= 0 ) {
			return $user_id;
		}

		$in_progress = true;
		try {
			self::$real_uid = $real_uid;

			$token = self::token();
			$state = self::device_state( $real_uid, $token );
			if ( empty( $state['active'] ) ) {
				return $user_id;
			}

			// The operator must (still) be an administrator.
			if ( ! user_can( $real_uid, 'manage_options' ) ) {
				if ( self::lock( $real_uid ) ) {
					try {
						self::write_state( $real_uid, $token, null ); // stale on a non-admin
					} finally {
						self::unlock( $real_uid );
					}
				}
				return $user_id;
			}

			// re-check the stored target on every request. Only ever the shared General login.
			$target = (int) ( $state['target_user_id'] ?? 0 );
			if ( ! self::is_kiosk_account( $target, $real_uid ) ) {
				self::$target_uid_cache = null;
				$target = self::get_target_user_id();
				if ( ! self::is_kiosk_account( $target, $real_uid ) ) {
					// No General account to be: run signed out rather than as the administrator (fail closed).
					self::$switched = true;
					return 0;
				}
			}

			self::$switched       = true;
			self::$current_target = $target;
			return $target;
		} finally {
			$in_progress = false;
		}
	}

	/** The shared General login, and nobody else: role zdz_general, not an administrator, not the operator. */
	public static function is_kiosk_account( int $uid, int $real_uid = 0 ): bool {
		if ( $uid <= 0 || $uid === $real_uid ) {
			return false;
		}
		$u = get_userdata( $uid );
		if ( ! $u || ! in_array( 'zdz_general', (array) $u->roles, true ) ) {
			return false;
		}
		return ! user_can( $uid, 'manage_options' );
	}

	/** The genuine logged-in user id, independent of any switch. */
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

	/** Find the General (zdz_general) account: the lowest id wins. 0 if none. */
	public static function get_target_user_id(): int {
		if ( null !== self::$target_uid_cache ) {
			return (int) self::$target_uid_cache;
		}
		// The lowest-id General account that is a valid target (never one that is also an
		// administrator), so the Settings button, entering and the per-request check agree on who it is.
		$ids = get_users( array(
			'role'    => 'zdz_general',
			'fields'  => 'ID',
			'number'  => 20,
			'orderby' => 'ID',
			'order'   => 'ASC',
		) );
		self::$target_uid_cache = 0;
		foreach ( (array) $ids as $id ) {
			if ( self::is_kiosk_account( (int) $id ) ) {
				self::$target_uid_cache = (int) $id;
				break;
			}
		}
		return self::$target_uid_cache;
	}

	/** The General account id THIS request runs as (0 outside Demo Mode). */
	public static function get_current_target(): int {
		return (int) self::$current_target;
	}

	// ── REST API ─────────────────────────────────────────────────

	public static function register_routes() {
		register_rest_route( ZDZ_REST_NS, '/kiosk-demo/enter', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'rest_enter' ),
			'permission_callback' => array( __CLASS__, 'can_enter' ),
			'args'                => array( 'pin' => array( 'required' => true ) ),
		) );
		register_rest_route( ZDZ_REST_NS, '/kiosk-demo/exit', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'rest_exit' ),
			// During demo mode the runtime user is the kiosk account, not an admin. Authorisation is the PIN.
			'permission_callback' => function () { return is_user_logged_in(); },
			'args'                => array( 'pin' => array( 'required' => true ) ),
		) );
		register_rest_route( ZDZ_REST_NS, '/kiosk-demo/status', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'rest_status' ),
			'permission_callback' => function () { return is_user_logged_in(); },
		) );
	}

	/**
	 * ENTER permission: the genuine cookie user must be an administrator, on a request that is not already running as
	 * the shared account. A device in Demo Mode never starts it again: the guest holding it could otherwise try to set
	 * an exit PIN of their own.
	 */
	public static function can_enter(): bool {
		$uid = self::resolve_real_user_id();
		return $uid && user_can( $uid, 'manage_options' ) && ! self::$switched;
	}

	/** The PIN as sent, if it is 4–10 digits and nothing else; '' otherwise. */
	private static function clean_pin( $raw ): string {
		$pin = is_scalar( $raw ) ? trim( (string) $raw ) : '';
		return preg_match( '/^[0-9]{4,10}$/', $pin ) ? $pin : '';
	}

	/** ENTER: validate the PIN and target, write the record to THIS DEVICE's session, read it back. */
	public static function rest_enter( WP_REST_Request $request ) {
		$admin_uid = self::resolve_real_user_id();
		if ( ! $admin_uid || ! user_can( $admin_uid, 'manage_options' ) || self::$switched ) {
			return new WP_Error( 'forbidden', 'Only an administrator can start Demo Mode.', array( 'status' => 403 ) );
		}
		$token = self::token();
		if ( '' === $token || ! is_array( WP_Session_Tokens::get_instance( $admin_uid )->get( $token ) ) ) {
			return new WP_Error( 'no_session', "This device's sign-in can't hold Demo Mode. Sign out, sign in again, and retry.", array( 'status' => 409 ) );
		}
		if ( ! self::lock( $admin_uid ) ) {
			return new WP_Error( 'busy', 'the server was busy with this account. Wait a moment', array( 'status' => 429 ) );
		}
		try {
			return self::enter_locked( $request, $admin_uid, $token );
		} finally {
			self::unlock( $admin_uid );
		}
	}

	private static function enter_locked( WP_REST_Request $request, int $admin_uid, string $token ) {
		self::fresh( $admin_uid );
		$now = self::device_state( $admin_uid, $token );
		if ( ! empty( $now['active'] ) ) {
			return rest_ensure_response( array( 'success' => true, 'active' => true, 'redirect' => home_url( '/' ), 'message' => 'Demo Mode is already on for this device.' ) );
		}

		$pin = self::clean_pin( $request->get_param( 'pin' ) );
		if ( '' === $pin ) {
			return new WP_Error( 'bad_pin', 'The PIN must be 4 to 10 digits.', array( 'status' => 400 ) );
		}

		$target = self::get_target_user_id();
		if ( ! $target ) {
			return new WP_Error( 'no_kiosk_user', 'There is no shared General account to switch to. An administrator needs to create one (role General, Shared Kiosk).', array( 'status' => 409 ) );
		}
		if ( ! self::is_kiosk_account( $target, $admin_uid ) ) {
			return new WP_Error( 'self_target', 'The General account must be a plain shared login, not an administrator.', array( 'status' => 409 ) );
		}

		$state = array(
			'active'         => true,
			'pin_hash'       => wp_hash_password( $pin ),
			'target_user_id' => $target,
			'started_at'     => current_time( 'mysql' ),
			'device'         => self::device_label(),
			'fails'          => 0,
			'locked_until'   => 0,
		);
		if ( ! self::write_state( $admin_uid, $token, $state ) ) {
			self::write_state( $admin_uid, $token, null ); // never half-switched
			return new WP_Error( 'not_saved', "The server couldn't save it", array( 'status' => 500 ) );
		}
		self::audit( $admin_uid, 'kiosk_demo_on', 'Demo Mode on for one device (' . $state['device'] . ')' );

		return rest_ensure_response( array(
			'success'  => true,
			'active'   => true,
			'redirect' => home_url( '/' ),
			'nonce'    => self::make_nonce_for_user( $target, 'wp_rest' ), // wp_rest nonce for the kiosk identity
			'message'  => 'Demo Mode is on for this device.',
		) );
	}

	/** Minutes exit stays locked after this many wrong PINs in a row (0 = not locked). */
	public static function lock_minutes( int $fails ): int {
		if ( $fails >= 3 * self::MAX_FAILS ) {
			return 24 * 60; // from the 15th on, every wrong PIN waits a day
		}
		if ( 2 * self::MAX_FAILS === $fails ) {
			return 60;
		}
		if ( self::MAX_FAILS === $fails ) {
			return 15;
		}
		return 0; // after a wait, four more tries before the next one
	}

	private static function locked_error( int $secs ): WP_Error {
		$mins = max( 1, (int) ceil( $secs / 60 ) );
		return new WP_Error( 'locked', sprintf( 'Too many wrong PINs. Try again in %d minute%s, or an administrator can end Demo Mode for this device.', $mins, 1 === $mins ? '' : 's' ), array( 'status' => 429, 'retry_after' => $secs ) );
	}

	/** EXIT: rate-limited PIN check against THIS DEVICE's record, then remove it. */
	public static function rest_exit( WP_REST_Request $request ) {
		$admin_uid = self::resolve_real_user_id();
		if ( ! $admin_uid ) {
			return new WP_Error( 'no_session', 'No session.', array( 'status' => 403 ) );
		}
		$token = self::token();
		// A device whose exit is locked is told so WITHOUT taking the account's lock, so a guest
		// flooding exit cannot keep the lock busy and block an administrator's reset.
		$peek  = self::device_state( $admin_uid, $token );
		$until = (int) ( $peek['locked_until'] ?? 0 );
		if ( ! empty( $peek['active'] ) && $until > time() ) {
			return self::locked_error( $until - time() );
		}
		// One PIN check at a time per account, on a fresh read, so a burst of tries in parallel
		// cannot share one count (about 250 checks before the first lock without this).
		if ( ! self::lock( $admin_uid ) ) {
			return new WP_Error( 'busy', 'Another try is still being checked. Wait a moment and try again.', array( 'status' => 429 ) );
		}
		try {
			return self::exit_locked( $request, $admin_uid, $token );
		} finally {
			self::unlock( $admin_uid );
		}
	}

	private static function exit_locked( WP_REST_Request $request, int $admin_uid, string $token ) {
		self::fresh( $admin_uid );
		$state = self::device_state( $admin_uid, $token );
		if ( empty( $state['active'] ) ) {
			return rest_ensure_response( array( 'success' => true, 'active' => false, 'redirect' => home_url( '/' ) ) );
		}

		$now   = time();
		$until = (int) ( $state['locked_until'] ?? 0 );
		if ( $until > $now ) {
			return self::locked_error( $until - $now );
		}

		$pin = self::clean_pin( $request->get_param( 'pin' ) );
		if ( '' === $pin || ! wp_check_password( $pin, (string) ( $state['pin_hash'] ?? '' ) ) ) {
			$fails                 = (int) ( $state['fails'] ?? 0 ) + 1;
			$state['fails']        = $fails;
			$mins                  = self::lock_minutes( $fails );
			$state['locked_until'] = $mins ? $now + $mins * MINUTE_IN_SECONDS : 0;
			self::write_state( $admin_uid, $token, $state );
			if ( $mins ) {
				self::audit( $admin_uid, 'kiosk_demo_locked', sprintf( 'Demo Mode exit locked for %d minutes after %d wrong PINs (%s)', $mins, $fails, (string) ( $state['device'] ?? '' ) ) );
				return new WP_Error( 'locked', sprintf( 'Too many wrong PINs. Try again in %d minutes, or an administrator can end Demo Mode for this device.', $mins ), array( 'status' => 429, 'retry_after' => $mins * 60 ) );
			}
			$left = self::MAX_FAILS - ( $fails % self::MAX_FAILS );
			return new WP_Error( 'bad_pin', sprintf( "That PIN isn't right. %d more %s before a wait.", $left, 1 === $left ? 'try' : 'tries' ), array( 'status' => 403, 'tries_left' => $left ) );
		}

		if ( ! self::write_state( $admin_uid, $token, null ) ) {
			return new WP_Error( 'not_saved', "The server couldn't save it. Still in Demo Mode.", array( 'status' => 500 ) );
		}
		self::$switched = false;
		self::audit( $admin_uid, 'kiosk_demo_off', 'Demo Mode off for one device (' . (string) ( $state['device'] ?? '' ) . ')' );

		return rest_ensure_response( array(
			'success'  => true,
			'active'   => false,
			'redirect' => home_url( '/' ),
			'nonce'    => self::make_nonce_for_user( $admin_uid, 'wp_rest' ),
			'message'  => 'Returned to administrator.',
		) );
	}

	/** STATUS for this device. */
	public static function rest_status() {
		$admin_uid = self::resolve_real_user_id();
		$state     = $admin_uid ? self::device_state( $admin_uid ) : array();
		$active    = self::$switched || ! empty( $state['active'] );
		return rest_ensure_response( array(
			'active'           => (bool) $active,
			'kiosk_user_id'    => self::$switched ? get_current_user_id() : 0,
			'real_admin_id'    => (int) $admin_uid,
			'kiosk_role_label' => 'General (Shared Kiosk)',
			'started_at'       => $state['started_at'] ?? null,
			'locked_until'     => (int) ( $state['locked_until'] ?? 0 ),
		) );
	}

	// ── The administrator's reset (wp-admin → Zorderz → Demo Mode) ──

	/** Every device in Demo Mode: [ [uid, verifier, state, session], … ], or null when sessions can't be listed. */
	public static function devices_in_demo() {
		if ( null === self::raw_sessions( 0 ) ) {
			return null;
		}
		global $wpdb;
		$uids = $wpdb->get_col( $wpdb->prepare(
			"SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = 'session_tokens' AND meta_value LIKE %s",
			'%' . $wpdb->esc_like( self::SESSION_KEY ) . '%'
		) );
		$out = array();
		foreach ( (array) $uids as $uid ) {
			$uid = (int) $uid;
			self::maybe_migrate_user( $uid );
			foreach ( (array) self::raw_sessions( $uid ) as $verifier => $session ) {
				if ( is_array( $session ) && ! empty( $session[ self::SESSION_KEY ]['active'] ) && (int) ( $session['expiration'] ?? 0 ) >= time() ) {
					$out[] = array( 'uid' => $uid, 'verifier' => (string) $verifier, 'state' => $session[ self::SESSION_KEY ], 'session' => $session );
				}
			}
		}
		return $out;
	}

	public static function add_admin_page() {
		add_submenu_page( 'zdz-core-settings', 'Demo Mode', 'Demo Mode', 'manage_options', 'zdz-kiosk-demo', array( __CLASS__, 'render_admin_page' ) );
	}

	public static function render_admin_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$devices = self::devices_in_demo();
		$done    = isset( $_GET['zdz_demo_done'] ) ? sanitize_key( wp_unslash( $_GET['zdz_demo_done'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only
		echo '<div class="wrap"><h1>Demo Mode</h1>';
		echo '<p>Devices in Demo Mode run as the shared General account until someone types the PIN chosen on that device. '
			. 'Each device is separate: your other devices stay signed in as you.</p>';
		if ( 'ended' === $done ) {
			echo '<div class="notice notice-success"><p>Demo Mode ended for that device. It is back on its administrator account the next time the app is opened or brought to the front.</p></div>';
		} elseif ( 'signed_out' === $done ) {
			echo '<div class="notice notice-success"><p>That device was signed out. It shows the sign-in page on its next tap.</p></div>';
		} elseif ( 'busy' === $done ) {
			echo '<div class="notice notice-warning"><p>The server was busy with that account. Nothing changed. Try again in a moment.</p></div>';
		} elseif ( 'gone' === $done ) {
			echo '<div class="notice notice-warning"><p>That device had already left Demo Mode or signed out. Nothing changed.</p></div>';
		}
		if ( null === $devices ) {
			echo '<p>This site keeps sign-ins somewhere this page cannot read. To end Demo Mode on a device, use <em>Log Out Everywhere Else</em> on that person&rsquo;s profile page.</p></div>';
			return;
		}
		if ( ! $devices ) {
			echo '<p><strong>No device is in Demo Mode.</strong></p></div>';
			return;
		}
		echo '<p><strong>Sign the device out</strong> is the safe choice when someone else still has it: it goes to the sign-in page. '
			. '<strong>End Demo Mode</strong> puts the device straight back on the administrator&rsquo;s account, so use it only when the device is back in your hands.</p>';
		echo '<table class="widefat striped"><thead><tr><th>Administrator</th><th>Device</th><th>Since</th><th>Wrong PINs</th><th></th></tr></thead><tbody>';
		foreach ( $devices as $d ) {
			$u     = get_userdata( $d['uid'] );
			$st    = $d['state'];
			$lock  = (int) ( $st['locked_until'] ?? 0 ) > time() ? ' (locked)' : '';
			$fails = (int) ( $st['fails'] ?? 0 );
			echo '<tr><td>' . esc_html( $u ? $u->display_name : '#' . $d['uid'] ) . '</td>';
			echo '<td>' . esc_html( (string) ( $st['device'] ?? '' ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $st['started_at'] ?? '' ) ) . '</td>';
			echo '<td>' . esc_html( $fails . $lock ) . '</td><td>';
			foreach ( array( 'sign_out' => 'Sign the device out', 'end' => 'End Demo Mode' ) as $what => $label ) {
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin-right:8px">';
				echo '<input type="hidden" name="action" value="zdz_kiosk_demo_reset">';
				echo '<input type="hidden" name="uid" value="' . (int) $d['uid'] . '">';
				echo '<input type="hidden" name="device" value="' . esc_attr( $d['verifier'] ) . '">';
				echo '<input type="hidden" name="what" value="' . esc_attr( $what ) . '">';
				wp_nonce_field( 'zdz_kiosk_demo_reset_' . $d['verifier'] );
				echo '<button type="submit" class="button' . ( 'sign_out' === $what ? ' button-primary' : '' ) . '">' . esc_html( $label ) . '</button></form>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	/** End Demo Mode for one device (remove its record) or sign it out (remove its session). Administrators only. */
	public static function handle_admin_reset() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Only an administrator can do that.', 403 );
		}
		$verifier = isset( $_POST['device'] ) ? preg_replace( '/[^a-f0-9]/', '', sanitize_key( (string) wp_unslash( $_POST['device'] ) ) ) : '';
		check_admin_referer( 'zdz_kiosk_demo_reset_' . $verifier );
		$uid  = isset( $_POST['uid'] ) ? absint( $_POST['uid'] ) : 0;
		$what = isset( $_POST['what'] ) && 'end' === $_POST['what'] ? 'end' : 'sign_out';
		$back = admin_url( 'admin.php?page=zdz-kiosk-demo' );

		$result   = self::reset_device( $uid, $verifier, $what );
		$by       = get_current_user_id();
		if ( 'ended' === $result || 'signed_out' === $result ) {
			self::audit( $uid, 'kiosk_demo_reset', sprintf( 'Demo Mode %s for one device by user #%d', 'end' === $what ? 'ended' : 'device signed out', $by ) );
		}
		wp_safe_redirect( add_query_arg( 'zdz_demo_done', $result, $back ) );
		exit;
	}

	/** 'ended' | 'signed_out' | 'gone'. Public for the tests. */
	public static function reset_device( int $uid, string $verifier, string $what ): string {
		if ( ! self::lock( $uid, 100 ) ) { // the administrator waits up to 10 s, not 3
			return 'busy';
		}
		try {
			return self::reset_locked( $uid, $verifier, $what );
		} finally {
			self::unlock( $uid );
		}
	}

	private static function reset_locked( int $uid, string $verifier, string $what ): string {
		self::fresh( $uid );
		$sessions = self::raw_sessions( $uid );
		if ( ! $sessions || '' === $verifier || ! isset( $sessions[ $verifier ][ self::SESSION_KEY ] ) ) {
			return 'gone';
		}
		if ( 'end' === $what ) {
			unset( $sessions[ $verifier ][ self::SESSION_KEY ] );
			$out = 'ended';
		} else {
			unset( $sessions[ $verifier ] );
			$out = 'signed_out';
		}
		update_user_meta( $uid, 'session_tokens', $sessions );
		return $out;
	}

	// ── Helpers ──────────────────────────────────────────────────

	/** A short label for the device ("iPhone · Safari"), for the admin's list. Never the full agent. */
	public static function device_label( ?string $ua = null ): string {
		$ua = null === $ua ? ( isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) : '' ) : $ua; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- matched, never stored
		$os = 'Device';
		foreach ( array( 'iPhone' => 'iPhone', 'iPad' => 'iPad', 'Android' => 'Android', 'Macintosh' => 'Mac', 'Windows' => 'Windows', 'Linux' => 'Linux' ) as $needle => $name ) {
			if ( false !== stripos( $ua, $needle ) ) {
				$os = $name;
				break;
			}
		}
		$app = '';
		if ( preg_match( '/Edg\//', $ua ) ) {
			$app = 'Edge';
		} elseif ( preg_match( '/(Chrome|CriOS)\//', $ua ) ) {
			$app = 'Chrome';
		} elseif ( preg_match( '/(Firefox|FxiOS)\//', $ua ) ) {
			$app = 'Firefox';
		} elseif ( false !== stripos( $ua, 'Safari/' ) ) {
			$app = 'Safari';
		} elseif ( false !== stripos( $ua, 'AppleWebKit' ) ) {
			$app = 'app'; // a web view without "Safari/": the desktop or home-screen app
		}
		return '' === $app ? $os : $os . ' · ' . $app;
	}

	/** An accountability row in the theme's audit log (never the PIN). */
	private static function audit( int $uid, string $type, string $detail ) {
		if ( class_exists( 'ZDZ_Admin_Dashboard' ) && method_exists( 'ZDZ_Admin_Dashboard', 'log_action' ) ) {
			try {
				ZDZ_Admin_Dashboard::log_action( $uid, $type, $detail );
			} catch ( Throwable $e ) {
				// logging must never break entering or leaving Demo Mode
			}
		}
	}

	/**
	 * Create a nonce as if a specific user were current, then restore the runtime user. Nonces are bound to the
	 * user and this device's session token; entering or leaving demo mode changes which identity the device's next
	 * requests run as.
	 */
	private static function make_nonce_for_user( int $uid, string $action ): string {
		$prev = get_current_user_id();
		wp_set_current_user( $uid );
		$nonce = wp_create_nonce( $action );
		wp_set_current_user( $prev );
		return $nonce;
	}
}

ZDZ_Kiosk_Demo::init();
