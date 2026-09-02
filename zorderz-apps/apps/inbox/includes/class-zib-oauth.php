<?php
/**
 * ZIB_OAuth — per-user OAuth connect / callback / disconnect (Zorderz Inbox).
 *
 * A faithful clone of ZSCH_OAuth (the platform's first per-user OAuth surface),
 * narrowed to one provider (microsoft) and hardened one notch further: the
 * shared kiosk (`zdz_general`) is denied at EVERY door, because a mailbox is
 * personal and a kiosk is a shared device (INV-10). There is no read-only seat
 * here — unlike the Scheduler, Zorderz Inbox admits nobody who cannot write.
 *
 * ROUTES (template_redirect, not REST — a cookie-authenticated top-level
 * navigation carries no REST nonce, and identity MUST come from the live WP
 * session):
 *
 *   GET /?zib_oauth=start&_wpnonce=…
 *        Logged-in + app access (kiosk DENIED) + feature enabled + nonce +
 *        5/hr rate gate → 302 to Microsoft.
 *
 *   GET /?zib_oauth=callback&code=…&state=…
 *        The registered redirect URI. State is HMAC-verified constant-time in
 *        the 'zib-oauth' namespace, single-use, and user-bound; ANY mismatch
 *        renders a bare 404 (never 403). Success exchanges the code
 *        server-side, stores the account + encrypted tokens, lands on the
 *        dashboard. NO mailbox is read here — indexing is a separate, later
 *        step gated on the user's chosen mode.
 *
 * @since 0.1.0 (P0 — Connect)
 * @package Zorderz
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZIB_OAuth {

	const STATE_NS  = 'zib-oauth';
	const STATE_TTL = 600; // 10 minutes.

	private static $last_state = array();

	/** Wire the front-end routes. Called from the bootstrap. */
	public static function register(): void {
		add_action( 'template_redirect', array( __CLASS__, 'route' ), 5 );
	}

	/** Feature gate — delegated to Settings (flag ON *and* fully configured). */
	public static function feature_enabled(): bool {
		return ZIB_Settings::feature_enabled();
	}

	/** The EXACT redirect URI registered in Entra. */
	public static function redirect_uri(): string {
		return home_url( '/?zib_oauth=callback&provider=microsoft' );
	}

	/**
	 * The nonce-armed start URL the Connect button navigates to. Built with
	 * add_query_arg so the ampersands stay LITERAL — a JS location assignment
	 * does not decode '&amp;', and an entity-encoded URL breaks the flow with
	 * invalid_grant (the Scheduler's v1.6.1 bug, carried forward as fixed).
	 */
	public static function start_url(): string {
		return add_query_arg( array(
			'zib_oauth' => 'start',
			'_wpnonce'  => wp_create_nonce( 'zib_oauth_start' ),
		), home_url( '/' ) );
	}

	// ── router ─────────────────────────────────────────────────────

	public static function route(): void {
		$action = isset( $_GET['zib_oauth'] ) ? sanitize_key( wp_unslash( $_GET['zib_oauth'] ) ) : '';
		if ( '' === $action ) {
			return;
		}
		nocache_headers();

		if ( 'start' === $action ) {
			self::handle_start();
		} elseif ( 'callback' === $action ) {
			self::handle_callback();
		} else {
			self::bail_404();
		}
		exit;
	}

	// ── start ──────────────────────────────────────────────────────

	private static function handle_start(): void {
		// Session-derived identity + kiosk deny (zib_user_has_access excludes
		// the read-only kiosk) + feature flag.
		if ( ! is_user_logged_in() || ! zib_user_has_access() || ! zib_user_can_write() || ! self::feature_enabled() ) {
			self::bail_404();
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ?? '' ) ), 'zib_oauth_start' ) ) {
			self::bail_404();
		}
		if ( ! class_exists( 'ZDZ_Share_Link' ) ) {
			self::log( 'start refused: ZDZ_Share_Link missing (theme < 2.36)' );
			self::land( 'err' );
		}

		$uid = get_current_user_id();
		$rk  = 'zib_oauth_rl_' . $uid . '_' . (int) floor( time() / 3600 );
		$n   = (int) get_transient( $rk );
		if ( $n >= 5 ) {
			self::land( 'rate' );
		}
		set_transient( $rk, $n + 1, 3605 );

		try {
			$state_id = random_int( 1, PHP_INT_MAX );
		} catch ( Exception $e ) {
			self::log( 'start refused: no CSPRNG' );
			self::land( 'err' );
			return;
		}
		set_transient( 'zib_oauth_state_' . $state_id, array(
			'user_id' => $uid,
			'created' => time(),
		), self::STATE_TTL );
		$state = $state_id . '.' . ZDZ_Share_Link::sign( self::STATE_NS, $state_id );

		$url = ZIB_Graph::auth_url( $state );
		if ( is_wp_error( $url ) ) {
			self::log( 'start refused: ' . $url->get_error_code() );
			self::land( 'err' );
		}
		wp_redirect( $url ); // phpcs:ignore WordPress.Security.SafeRedirect — Microsoft authorize URL.
		exit;
	}

	// ── callback ───────────────────────────────────────────────────

	private static function handle_callback(): void {
		if ( ! is_user_logged_in() ) {
			self::land( 'login' );
		}
		if ( ! class_exists( 'ZDZ_Share_Link' ) ) {
			self::bail_404();
		}
		if ( ! self::consume_state(
			sanitize_text_field( wp_unslash( $_GET['state'] ?? '' ) ),
			get_current_user_id()
		) ) {
			self::bail_404();
		}
		if ( ! empty( $_GET['error'] ) ) {
			self::log( 'callback: provider error ' . sanitize_key( wp_unslash( $_GET['error'] ) ) );
			self::land( 'cancel' );
		}
		$code = sanitize_text_field( wp_unslash( $_GET['code'] ?? '' ) );
		if ( '' === $code ) {
			self::land( 'err' );
		}

		$tokens = ZIB_Graph::exchange_code( $code );
		if ( is_wp_error( $tokens ) ) {
			self::log( 'callback: exchange failed — ' . $tokens->get_error_code() );
			self::land( 'err' );
		}

		$who = ZIB_Graph::identity_from_id_token( (string) ( $tokens['id_token'] ?? '' ) );
		if ( '' === $who['external_id'] ) {
			self::log( 'callback: no identity in id_token' );
			self::land( 'err' );
		}

		$account_id = ZIB_Connections::upsert_account(
			get_current_user_id(),
			$who,
			(string) ( $tokens['scope'] ?? '' ),
			array(
				'access_token'  => (string) $tokens['access_token'],
				'refresh_token' => (string) ( $tokens['refresh_token'] ?? '' ),
				'expires_in'    => (int) ( $tokens['expires_in'] ?? 3600 ),
			)
		);
		if ( is_wp_error( $account_id ) ) {
			self::log( 'callback: ' . $account_id->get_error_code() );
			self::land( 'err' );
		}

		self::log( 'callback: connected acct ' . (int) $account_id . ' for user ' . get_current_user_id() );
		self::land( 'ok' );
	}

	/**
	 * Validate + consume an OAuth state blob. TRUE only when ALL hold:
	 * well-formed "{id}.{sig}" → HMAC verifies constant-time in the
	 * 'zib-oauth' namespace → the single-use transient exists (deleted HERE,
	 * pass or fail) → it was minted by THIS logged-in user.
	 */
	public static function consume_state( string $raw, int $current_user_id ): bool {
		if ( ! preg_match( '/^(\d{1,19})\.([a-f0-9]{32})$/', $raw, $m ) ) {
			return false;
		}
		$state_id = (int) $m[1];
		if ( ! ZDZ_Share_Link::verify_signed( self::STATE_NS, $state_id, $m[2] ) ) {
			return false;
		}
		$payload = get_transient( 'zib_oauth_state_' . $state_id );
		delete_transient( 'zib_oauth_state_' . $state_id ); // Single-use, even on failure.
		if ( ! is_array( $payload ) || $current_user_id <= 0 || (int) $payload['user_id'] !== $current_user_id ) {
			self::$last_state = array();
			return false;
		}
		self::$last_state = $payload;
		return true;
	}

	// ── disconnect (called owner-scoped from ZIB_REST) ─────────────

	/**
	 * Best-effort revoke, then delete the account (and, from P1, purge its
	 * Ballast). Owner-checked by the caller.
	 */
	public static function disconnect( int $user_id, int $account_id ): bool {
		global $wpdb;
		$row = ZIB_Connections::get_owned_account( $user_id, $account_id );
		if ( ! $row ) {
			return false;
		}
		$enc = $wpdb->get_var( $wpdb->prepare(
			"SELECT refresh_token_enc FROM {$wpdb->prefix}zib_accounts WHERE id = %d AND owner_user_id = %d",
			$account_id, $user_id
		) );
		ZIB_Graph::revoke( ZIB_Vault::decrypt( (string) $enc ) );
		return ZIB_Connections::delete_account( $user_id, $account_id );
	}

	// ── terminal helpers ───────────────────────────────────────────

	private static function bail_404(): void {
		if ( class_exists( 'ZDZ_Share_Link' ) ) {
			ZDZ_Share_Link::not_found();
		}
		status_header( 404 );
		nocache_headers();
		exit;
	}

	private static function land( string $outcome ): void {
		wp_safe_redirect( home_url( '/?zib_connected=' . rawurlencode( $outcome ) ) );
		exit;
	}

	private static function log( string $msg ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( 'ZIB OAuth: ' . $msg );
		}
	}
}
