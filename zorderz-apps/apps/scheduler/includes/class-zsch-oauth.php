<?php
/**
 * ZSCH_OAuth — per-user OAuth connect/callback/disconnect for Connected
 * Calendars. The platform's FIRST per-user OAuth surface, so the hardening
 * here is deliberate and heavier than the code around it.
 *
 * ROUTES (template_redirect, not REST — cookie-authenticated top-level
 * navigations carry no REST nonce, and identity MUST come from the live WP
 * session, INV-Session):
 *
 *   GET /?zsch_oauth=start&provider={google|microsoft}&_wpnonce=…
 *        Logged-in + app access + can_write (kiosk = read-only = DENIED) +
 *        feature enabled + nonce + 5/hr rate gate → 302 to the provider.
 *
 *   GET /?zsch_oauth=callback&provider={google|microsoft}&code=…&state=…
 *        The registered redirect URI. State is verified constant-time
 *        (ZDZ_Share_Link 'zsch-oauth' namespace — its first runtime caller),
 *        bound to a single-use 10-minute transient holding the initiating
 *        user id; ANY mismatch renders a bare 404 (never 403 — INV-Token,
 *        non-enumerable). Success exchanges the code server-side, stores the
 *        account + encrypted tokens, defaults the primary calendar ON as a
 *        conflict feed, and lands back on the dashboard.
 *
 * Provider error payloads are never echoed to the browser; failures log one
 * line and land on the dashboard with a neutral "didn't complete" flag.
 *
 * @since 1.6.0 (Connected Calendars Phase 0)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZSCH_OAuth {

	const STATE_NS  = 'zsch-oauth';
	const STATE_TTL = 600; // 10 minutes.

	/** Wire the front-end routes. Called from the bootstrap after includes load. */
	public static function register(): void {
		add_action( 'template_redirect', array( __CLASS__, 'route' ), 5 );
	}

	/**
	 * Feature gate: flag option AND at least one provider configured. Default
	 * OFF — with the flag down every surface (routes, card, REST) no-ops,
	 * the Graph-sync "safe no-op when unconfigured" posture.
	 */
	public static function feature_enabled(): bool {
		if ( get_option( 'zsch_connected_cals', 'no' ) !== 'yes' ) {
			return false;
		}
		$cfg = ZSCH_Settings::conncal_config();
		return ( '' !== $cfg['google_client_id'] && ZSCH_Settings::has_google_secret() )
			|| ( '' !== $cfg['ms_client_id'] && ZSCH_Settings::has_ms_delegated_secret() );
	}

	/** Which providers are ready to offer Connect buttons for. */
	public static function providers_available(): array {
		$cfg = ZSCH_Settings::conncal_config();
		return array(
			'google'    => ( '' !== $cfg['google_client_id'] && ZSCH_Settings::has_google_secret() ),
			'microsoft' => ( '' !== $cfg['ms_client_id'] && ZSCH_Settings::has_ms_delegated_secret() ),
		);
	}

	/**
	 * The EXACT redirect URI registered in both consoles. Query-param style —
	 * no rewrite rules to flush; unique OAuth params defeat page caching and
	 * we send nocache headers regardless.
	 */
	public static function redirect_uri( string $provider ): string {
		return home_url( '/?zsch_oauth=callback&provider=' . rawurlencode( $provider ) );
	}

	/**
	 * The start URL the widget's Connect buttons navigate to (nonce-armed).
	 *
	 * Built with add_query_arg so the ampersands stay LITERAL ('&', not the
	 * HTML-encoded '&amp;' that wp_nonce_url() emits). This string is handed to
	 * JavaScript via wp_localize_script and assigned to `window.location.href`;
	 * a JS location assignment navigates to the string verbatim and does NOT
	 * decode HTML entities, so a '&amp;'-encoded URL would send the browser to
	 * `?zsch_oauth=start&amp;provider=…` — the server then sees the params
	 * keyed `amp;provider` / `amp;_wpnonce`, the real provider + nonce go
	 * missing, and the whole authorize→callback→exchange chain fails with
	 * invalid_grant. (v1.6.1 fix.)
	 */
	public static function start_url( string $provider ): string {
		return add_query_arg(
			array(
				'zsch_oauth' => 'start',
				'provider'    => $provider,
				'_wpnonce'    => wp_create_nonce( 'zsch_oauth_start' ),
			),
			home_url( '/' )
		);
	}

	// ── router ─────────────────────────────────────────────────────

	public static function route(): void {
		$action = isset( $_GET['zsch_oauth'] ) ? sanitize_key( wp_unslash( $_GET['zsch_oauth'] ) ) : '';
		if ( '' === $action ) {
			return;
		}
		nocache_headers();

		$provider = isset( $_GET['provider'] ) ? sanitize_key( wp_unslash( $_GET['provider'] ) ) : '';
		if ( ! in_array( $provider, array( 'google', 'microsoft' ), true ) ) {
			self::bail_404();
		}

		if ( 'start' === $action ) {
			self::handle_start( $provider );
		} elseif ( 'callback' === $action ) {
			self::handle_callback( $provider );
		} else {
			self::bail_404();
		}
		exit;
	}

	// ── start ──────────────────────────────────────────────────────

	private static function handle_start( string $provider ): void {
		// Session-derived identity + explicit kiosk deny (read-only users can
		// never initiate a connect) + feature flag.
		if ( ! is_user_logged_in() || ! zsch_user_has_access() || ! zsch_user_can_write() || ! self::feature_enabled() ) {
			self::bail_404();
		}
		// CSRF on the initiation itself.
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ?? '' ) ), 'zsch_oauth_start' ) ) {
			self::bail_404();
		}
		if ( ! class_exists( 'ZDZ_Share_Link' ) ) {
			self::log( 'start refused: ZDZ_Share_Link missing (theme < 2.36)' );
			self::land( 'err' );
		}
		// Rate: 5 connects/hr/user (fail-open transient, house pattern).
		$uid = get_current_user_id();
		$rk  = 'zsch_oauth_rl_' . $uid . '_' . (int) floor( time() / 3600 );
		$n   = (int) get_transient( $rk );
		if ( $n >= 5 ) {
			self::land( 'rate' );
		}
		set_transient( $rk, $n + 1, 3605 );

		// Mint single-use state: random id → transient payload; the URL carries
		// "{id}.{HMAC}" (domain-separated 'zsch-oauth' namespace).
		try {
			$state_id = random_int( 1, PHP_INT_MAX );
		} catch ( Exception $e ) {
			self::log( 'start refused: no CSPRNG' );
			self::land( 'err' );
			return;
		}
		set_transient( 'zsch_oauth_state_' . $state_id, array(
			'user_id'  => $uid,
			'provider' => $provider,
			'created'  => time(),
		), self::STATE_TTL );
		$state = $state_id . '.' . ZDZ_Share_Link::sign( self::STATE_NS, $state_id );

		$url = ( 'microsoft' === $provider )
			? ZSCH_Graph_Delegated::auth_url( $state )
			: ZSCH_Google::auth_url( $state );
		if ( is_wp_error( $url ) ) {
			self::log( 'start refused: ' . $url->get_error_code() );
			self::land( 'err' );
		}
		wp_redirect( $url ); // phpcs:ignore WordPress.Security.SafeRedirect — provider authorize URL, allowlisted hosts only.
		exit;
	}

	// ── callback ───────────────────────────────────────────────────

	private static function handle_callback( string $provider ): void {
		// The browser followed the provider redirect WITH its WP cookies, so a
		// live session is expected. No session → neutral landing (the transient
		// stays; retrying from the app works).
		if ( ! is_user_logged_in() ) {
			self::land( 'login' );
		}
		if ( ! class_exists( 'ZDZ_Share_Link' ) ) {
			self::bail_404();
		}

		// ── state: parse → constant-time verify → single-use → user-bind ──
		if ( ! self::consume_state(
			sanitize_text_field( wp_unslash( $_GET['state'] ?? '' ) ),
			get_current_user_id(),
			$provider
		) ) {
			self::bail_404();
		}

		// ── provider-reported errors (user hit Cancel, consent blocked…) ──
		if ( ! empty( $_GET['error'] ) ) {
			self::log( "callback {$provider}: provider error " . sanitize_key( wp_unslash( $_GET['error'] ) ) );
			self::land( 'cancel' );
		}
		$code = sanitize_text_field( wp_unslash( $_GET['code'] ?? '' ) );
		if ( '' === $code ) {
			self::land( 'err' );
		}

		// ── code → tokens (server-side, secret never leaves the server) ──
		$tokens = ( 'microsoft' === $provider )
			? ZSCH_Graph_Delegated::exchange_code( $code )
			: ZSCH_Google::exchange_code( $code );
		if ( is_wp_error( $tokens ) ) {
			self::log( "callback {$provider}: exchange failed — " . $tokens->get_error_code() );
			self::land( 'err' );
		}

		// ── identity from the id_token (immutable key, email = label only) ──
		$id_token = (string) ( $tokens['id_token'] ?? '' );
		if ( 'microsoft' === $provider ) {
			$who         = ZSCH_Graph_Delegated::identity_from_id_token( $id_token );
			$external_id = $who['external_id'];
		} else {
			$who         = ZSCH_Google::identity_from_id_token( $id_token );
			$external_id = $who['sub'];
		}
		if ( '' === $external_id ) {
			self::log( "callback {$provider}: no identity in id_token" );
			self::land( 'err' );
		}

		$account_id = ZSCH_Connections::upsert_account(
			get_current_user_id(),
			$provider,
			$external_id,
			(string) $who['email'],
			(string) ( $tokens['scope'] ?? '' ),
			array(
				'access_token'  => (string) $tokens['access_token'],
				'refresh_token' => (string) ( $tokens['refresh_token'] ?? '' ),
				'expires_in'    => (int) ( $tokens['expires_in'] ?? 3600 ),
			)
		);
		if ( is_wp_error( $account_id ) ) {
			self::log( "callback {$provider}: " . $account_id->get_error_code() );
			self::land( 'err' );
		}

		// ── default: the account's primary calendar ON as a conflict feed ──
		$cals = ( 'microsoft' === $provider )
			? ZSCH_Graph_Delegated::calendar_list( $account_id )
			: ZSCH_Google::calendar_list( $account_id );
		if ( ! is_wp_error( $cals ) ) {
			foreach ( $cals as $cal ) {
				if ( ! empty( $cal['primary'] ) ) {
					ZSCH_Connections::enable_feed( get_current_user_id(), $account_id, $cal['id'], $cal['name'], $cal['color'] );
					break;
				}
			}
		}

		self::log( "callback {$provider}: connected acct {$account_id} for user " . get_current_user_id() );
		self::land( 'ok' );
	}

	/**
	 * Validate + consume an OAuth state blob. TRUE only when ALL hold:
	 * well-formed "{id}.{sig}" → HMAC verifies constant-time in the
	 * 'zsch-oauth' namespace → the single-use transient exists (and is
	 * deleted HERE, pass or fail below) → it was minted by THIS logged-in
	 * user → for THIS provider. Split out so the harness can attack it
	 * directly (_test_oauth_state).
	 *
	 * @return bool
	 */
	public static function consume_state( string $raw, int $current_user_id, string $provider ): bool {
		if ( ! preg_match( '/^(\d{1,19})\.([a-f0-9]{32})$/', $raw, $m ) ) {
			return false;
		}
		$state_id = (int) $m[1];
		if ( ! ZDZ_Share_Link::verify_signed( self::STATE_NS, $state_id, $m[2] ) ) {
			return false;
		}
		$payload = get_transient( 'zsch_oauth_state_' . $state_id );
		delete_transient( 'zsch_oauth_state_' . $state_id ); // Single-use, even on failure below.
		if ( ! is_array( $payload )
			|| $current_user_id <= 0
			|| (int) $payload['user_id'] !== $current_user_id
			|| (string) $payload['provider'] !== $provider ) {
			return false;
		}
		return true;
	}

	// ── disconnect (called from the REST layer, owner-scoped there) ──

	/**
	 * Best-effort provider revoke, then delete the account + feeds + mirror.
	 *
	 * @return bool
	 */
	public static function disconnect( int $user_id, int $account_id ): bool {
		global $wpdb;
		$row = ZSCH_Connections::get_owned_account( $user_id, $account_id );
		if ( ! $row ) {
			return false;
		}
		// Revoke needs the raw refresh token — fetch it before deletion.
		$enc = $wpdb->get_var( $wpdb->prepare(
			"SELECT refresh_token_enc FROM {$wpdb->prefix}zsch_calendar_accounts WHERE id = %d AND owner_user_id = %d",
			$account_id, $user_id
		) );
		$refresh = ZSCH_Vault::decrypt( (string) $enc );
		if ( 'google' === $row->provider ) {
			ZSCH_Google::revoke( $refresh );
		} else {
			ZSCH_Graph_Delegated::revoke( $refresh );
		}
		return ZSCH_Connections::delete_account( $user_id, $account_id );
	}

	// ── terminal helpers ───────────────────────────────────────────

	/** Bare 404 — never 403, never a hint (INV-Token). Does not return. */
	private static function bail_404(): void {
		if ( class_exists( 'ZDZ_Share_Link' ) ) {
			ZDZ_Share_Link::not_found();
		}
		status_header( 404 );
		nocache_headers();
		exit;
	}

	/**
	 * Land back on the dashboard with a neutral outcome flag the widget turns
	 * into a toast. Does not return.
	 */
	private static function land( string $outcome ): void {
		wp_safe_redirect( home_url( '/?zsch_connected=' . rawurlencode( $outcome ) ) );
		exit;
	}

	private static function log( string $msg ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( 'ZSCH OAuth: ' . $msg );
		}
	}
}
