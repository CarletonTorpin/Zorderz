<?php
/**
 * Zorderz Magic Link Bridge
 *
 * Bridges authentication between Safari (where Magic Login email links open)
 * and the standalone PWA (where the user actually uses the app) on iOS.
 *
 * iOS isolates cookies, localStorage, and sessionStorage between Safari and
 * standalone PWA mode. This class implements a Device Authorization Flow
 * pattern using WordPress transients as the coordination layer.
 *
 * Flow A (warm start — PWA is open and polling):
 *   1. PWA generates a request_id, calls /zorderz/v1/magic-link-init
 *   2. Magic Login validates the token in Safari, redirects to interstitial
 *   3. Interstitial creates a bridge token, stores it, updates request status
 *   4. PWA polls /zorderz/v1/magic-link-status, receives bridge token when ready
 *   5. PWA calls /zorderz/v1/magic-link-claim with bridge token, gets auth cookie
 *
 * Flow B (cold start — user taps email link without PWA open):
 *   1. User taps magic link in email → Safari opens and authenticates
 *   2. Interstitial shows a 6-digit login code
 *   3. User opens PWA, taps "Have a login code?", enters the 6 digits
 *   4. PWA calls /zorderz/v1/magic-link-code-claim → gets auth cookie
 *
 * @package Zorderz
 * @since   2.18.0
 * @updated 2.19.0 — Added short-code fallback for cold-start logins
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZDZ_Magic_Link_Bridge {

	/**
	 * Transient prefix for request tracking.
	 */
	const TRANSIENT_PREFIX = 'zdz_ml_bridge_';

	/**
	 * How long a request_id remains valid (seconds).
	 */
	const REQUEST_TTL = 600; // 10 minutes

	/**
	 * How long a bridge token remains valid (seconds).
	 */
	const TOKEN_TTL = 120; // 2 minutes

	/**
	 * How long a short login code remains valid (seconds).
	 *
	 * @since 2.19.0
	 */
	const CODE_TTL = 300; // 5 minutes

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Get singleton instance.
	 *
	 * @return self
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor — hooks into WordPress.
	 */
	private function __construct() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
		add_filter( 'login_redirect', [ $this, 'filter_login_redirect' ], 20, 3 );

		// v2.20.0: Fallback — catch Magic Login Pro's wp_redirect() in case it
		// bypasses the login_redirect filter entirely. This fires at priority 5
		// (before our OAuth bounce at priority 1) to intercept the redirect URL
		// when it contains a zdz_bridge_request_id.
		add_filter( 'wp_redirect', [ $this, 'filter_wp_redirect_bridge' ], 5, 2 );

		// v2.20.3: Inject an OTP code into Magic Login's magic link email so the
		// user receives ONE email containing both the clickable link and a 6-digit
		// code. This eliminates the separate "Send me a code" step entirely.
		add_filter( 'wp_mail', [ $this, 'inject_otp_into_magic_login_email' ], 99 );
	}

	/**
	 * Register REST API routes.
	 */
	public function register_routes() {
		register_rest_route( 'zorderz/v1', '/magic-link-init', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'handle_init' ],
			'permission_callback' => '__return_true',
			'args'                => [
				'request_id' => [
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
					'validate_callback' => function ( $value ) {
						// UUID v4 format
						return (bool) preg_match(
							'/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
							$value
						);
					},
				],
				'email' => [
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_email',
					'validate_callback' => 'is_email',
				],
			],
		] );

		register_rest_route( 'zorderz/v1', '/magic-link-status', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'handle_status' ],
			'permission_callback' => '__return_true',
			'args'                => [
				'request_id' => [
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				],
			],
		] );

		register_rest_route( 'zorderz/v1', '/magic-link-claim', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'handle_claim' ],
			'permission_callback' => '__return_true',
			'args'                => [
				'bridge_token' => [
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
					'validate_callback' => function ( $value ) {
						return (bool) preg_match( '/^[0-9a-f]{64}$/i', $value );
					},
				],
			],
		] );

		// v2.19.0: Short-code claim endpoint for cold-start logins.
		register_rest_route( 'zorderz/v1', '/magic-link-code-claim', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'handle_code_claim' ],
			'permission_callback' => '__return_true',
			'args'                => [
				'code' => [
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
					'validate_callback' => function ( $value ) {
						return (bool) preg_match( '/^[0-9]{6}$/', $value );
					},
				],
			],
		] );

		// v2.20.0: OTP email — sends a 6-digit code directly in the email.
		// Eliminates Safari from the login flow entirely for PWA users.
		register_rest_route( 'zorderz/v1', '/magic-link-send-code', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'handle_send_code' ],
			'permission_callback' => '__return_true',
			'args'                => [
				'email' => [
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_email',
					'validate_callback' => 'is_email',
				],
			],
		] );
	}

	/**
	 * POST /zorderz/v1/magic-link-init
	 *
	 * Registers a request_id for polling. The PWA calls this before/after
	 * the Magic Login form submission.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_init( WP_REST_Request $request ) {
		$request_id = $request->get_param( 'request_id' );
		$email      = $request->get_param( 'email' );

		// Verify the email belongs to a valid user (don't reveal which)
		$user = get_user_by( 'email', $email );
		if ( ! $user ) {
			// Return success even for invalid emails to avoid user enumeration
			return new WP_REST_Response( [ 'success' => true ], 200 );
		}

		// Rate limit: max 5 inits per IP per 10 minutes
		$ip_key = self::TRANSIENT_PREFIX . 'rate_' . md5( self::get_client_ip() );
		$count  = (int) get_transient( $ip_key );
		if ( $count >= 5 ) {
			return new WP_Error(
				'rate_limited',
				'Too many requests. Please try again later.',
				[ 'status' => 429 ]
			);
		}
		set_transient( $ip_key, $count + 1, self::REQUEST_TTL );

		// Store the request
		$data = [
			'status'    => 'pending',
			'email'     => $email,
			'user_id'   => $user->ID,
			'created'   => time(),
			'ip'        => self::get_client_ip(),
		];
		set_transient( self::TRANSIENT_PREFIX . 'req_' . $request_id, $data, self::REQUEST_TTL );

		return new WP_REST_Response( [ 'success' => true ], 200 );
	}

	/**
	 * GET /zorderz/v1/magic-link-status
	 *
	 * Polling endpoint. Returns the current status of a request.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public function handle_status( WP_REST_Request $request ) {
		$request_id = $request->get_param( 'request_id' );
		$data       = get_transient( self::TRANSIENT_PREFIX . 'req_' . $request_id );

		if ( ! $data || ! is_array( $data ) ) {
			// Don't reveal whether the request_id ever existed
			return new WP_REST_Response( [ 'status' => 'pending' ], 200 );
		}

		if ( 'ready' === $data['status'] && ! empty( $data['bridge_token'] ) ) {
			return new WP_REST_Response( [
				'status'       => 'ready',
				'bridge_token' => $data['bridge_token'],
			], 200 );
		}

		return new WP_REST_Response( [ 'status' => 'pending' ], 200 );
	}

	/**
	 * POST /zorderz/v1/magic-link-claim
	 *
	 * Claims a bridge token and sets the auth cookie.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_claim( WP_REST_Request $request ) {
		$bridge_token = $request->get_param( 'bridge_token' );

		// Look up the token
		$token_data = get_transient( self::TRANSIENT_PREFIX . 'token_' . $bridge_token );

		if ( ! $token_data || ! is_array( $token_data ) ) {
			return new WP_Error(
				'invalid_token',
				'Bridge token is invalid or expired.',
				[ 'status' => 401 ]
			);
		}

		$user_id = (int) $token_data['user_id'];

		// Delete the token immediately (one-time use)
		delete_transient( self::TRANSIENT_PREFIX . 'token_' . $bridge_token );

		// Also clean up the request transient
		if ( ! empty( $token_data['request_id'] ) ) {
			delete_transient( self::TRANSIENT_PREFIX . 'req_' . $token_data['request_id'] );
		}

		// Verify the user still exists and is active
		$user = get_user_by( 'id', $user_id );
		if ( ! $user ) {
			return new WP_Error(
				'user_not_found',
				'User account not found.',
				[ 'status' => 401 ]
			);
		}

		// Set the auth cookie in the PWA context
		wp_clear_auth_cookie();
		wp_set_current_user( $user_id );
		wp_set_auth_cookie( $user_id, true, is_ssl() );

		// Log the bridge claim for audit
		if ( class_exists( 'ZDZ_Admin_Dashboard' ) && method_exists( 'ZDZ_Admin_Dashboard', 'track_login' ) ) {
			ZDZ_Admin_Dashboard::track_login( $user->user_login, $user );
		}

		// Fire the standard WordPress login action
		do_action( 'wp_login', $user->user_login, $user );

		return new WP_REST_Response( [
			'success'  => true,
			'redirect' => home_url( '/' ),
		], 200 );
	}

	/**
	 * POST /zorderz/v1/magic-link-code-claim
	 *
	 * Claims a 6-digit short code and sets the auth cookie in the PWA.
	 * This is the cold-start fallback: user taps email link without
	 * the PWA open, sees the code on Safari's interstitial, then types
	 * it into the PWA login screen.
	 *
	 * @since 2.19.0
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_code_claim( WP_REST_Request $request ) {
		$code = $request->get_param( 'code' );

		// Site-wide backstop against distributed or spoofed brute force: a global cap on
		// WRONG-code attempts. Legit users paste a real code and almost never miss, so this
		// trips on scanning, not on normal use. Per-IP key is handled just below.
		$miss_key     = self::TRANSIENT_PREFIX . 'code_miss_global';
		$miss_count   = (int) get_transient( $miss_key );
		$miss_ceiling = (int) apply_filters( 'zdz_magic_link_global_miss_ceiling', 300 );
		if ( $miss_count >= $miss_ceiling ) {
			return new WP_Error(
				'rate_limited',
				'Too many attempts right now. Please request a new login link and try again shortly.',
				[ 'status' => 429 ]
			);
		}

		// Per-IP attempt cap, keyed on the UN-SPOOFABLE connecting address (REMOTE_ADDR), never a
		// client-settable forwarded header. Trusting a forwarded header let an attacker land in a
		// fresh bucket every request and defeat this limit entirely (security audit, Aug 2026).
		$ip_key     = self::TRANSIENT_PREFIX . 'code_rate_' . md5( self::rate_limit_ip() );
		$count      = (int) get_transient( $ip_key );
		$ip_ceiling = (int) apply_filters( 'zdz_magic_link_ip_attempt_ceiling', 10 );
		if ( $count >= $ip_ceiling ) {
			return new WP_Error(
				'rate_limited',
				'Too many attempts. Please try again later.',
				[ 'status' => 429 ]
			);
		}
		set_transient( $ip_key, $count + 1, self::CODE_TTL );

		// Shape-check before lookup: codes are exactly six digits, so anything else is a cheap
		// miss and never reaches transient storage with an attacker-shaped key.
		$code = preg_replace( '/\D/', '', (string) $code );

		// Look up the code (single-use; deleted below on success).
		$code_data = ( 6 === strlen( (string) $code ) ) ? get_transient( self::TRANSIENT_PREFIX . 'code_' . $code ) : false;

		if ( ! $code_data || ! is_array( $code_data ) ) {
			// Count the miss toward the global backstop, then refuse.
			set_transient( $miss_key, $miss_count + 1, self::CODE_TTL );
			return new WP_Error(
				'invalid_code',
				'Code is invalid or expired. Please request a new login link.',
				[ 'status' => 401 ]
			);
		}

		$user_id = (int) $code_data['user_id'];

		// Delete the code immediately (one-time use)
		delete_transient( self::TRANSIENT_PREFIX . 'code_' . $code );

		// Also clean up the bridge token if one was associated
		if ( ! empty( $code_data['bridge_token'] ) ) {
			delete_transient( self::TRANSIENT_PREFIX . 'token_' . $code_data['bridge_token'] );
		}

		// Verify the user still exists
		$user = get_user_by( 'id', $user_id );
		if ( ! $user ) {
			return new WP_Error(
				'user_not_found',
				'User account not found.',
				[ 'status' => 401 ]
			);
		}

		// Set the auth cookie in the PWA context
		wp_clear_auth_cookie();
		wp_set_current_user( $user_id );
		wp_set_auth_cookie( $user_id, true, is_ssl() );

		// Log the bridge claim for audit
		if ( class_exists( 'ZDZ_Admin_Dashboard' ) && method_exists( 'ZDZ_Admin_Dashboard', 'track_login' ) ) {
			ZDZ_Admin_Dashboard::track_login( $user->user_login, $user );
		}

		do_action( 'wp_login', $user->user_login, $user );

		error_log( 'ZDZ_Magic_Link_Bridge: Code claim successful for user ' . $user->user_login . ' (cold-start flow)' );

		return new WP_REST_Response( [
			'success'  => true,
			'redirect' => home_url( '/' ),
		], 200 );
	}

	/**
	 * Filter the login redirect URL to route through the bridge interstitial.
	 *
	 * This fires after Magic Login validates the token and sets the cookie.
	 *
	 * v2.18.0: Warm-start flow — redirect to interstitial when request_id present.
	 * v2.19.0: Cold-start flow — on iOS devices without a request_id, still
	 *          redirect to interstitial with a 6-digit code so the user can
	 *          transfer the session into the PWA manually.
	 *
	 * @param string           $redirect_to  Default redirect URL.
	 * @param string           $requested    Requested redirect URL.
	 * @param WP_User|WP_Error $user         Logged-in user or error.
	 * @return string
	 */
	public function filter_login_redirect( $redirect_to, $requested, $user ) {
		// Only act on successful logins
		if ( is_wp_error( $user ) || ! $user instanceof WP_User ) {
			return $redirect_to;
		}

		// v2.20.0: Diagnostic logging — track every invocation to debug
		// Magic Login Pro redirect issues. Safe to leave in production
		// (fires at most once per login).
		error_log( sprintf(
			'ZDZ_Magic_Link_Bridge::filter_login_redirect called | user=%s | redirect_to=%s | requested=%s | is_ios=%s | UA=%s',
			$user->user_login,
			$redirect_to,
			$requested,
			self::is_ios_device() ? 'yes' : 'no',
			isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( $_SERVER['HTTP_USER_AGENT'], 0, 80 ) : 'n/a'
		) );

		// Parse out any request_id from the redirect URL — check both
		// $requested (what the form asked for) and $redirect_to (what
		// WordPress resolved), plus the raw $_GET in case Magic Login Pro
		// passes it through the query string directly.
		$request_id = '';

		// v2.20.0: Also check $_GET directly — some Magic Login Pro versions
		// pass the redirect_to params through the query string on the callback
		// page rather than in the login_redirect filter arguments.
		if ( ! empty( $_GET['zdz_bridge_request_id'] ) ) {
			$request_id = sanitize_text_field( $_GET['zdz_bridge_request_id'] );
		}

		if ( ! $request_id ) {
			$parsed = wp_parse_url( $requested );
			if ( empty( $parsed['query'] ) ) {
				$parsed = wp_parse_url( $redirect_to );
			}
			if ( ! empty( $parsed['query'] ) ) {
				parse_str( $parsed['query'], $params );
				$request_id = isset( $params['zdz_bridge_request_id'] ) ? sanitize_text_field( $params['zdz_bridge_request_id'] ) : '';
			}
		}

		// v2.20.0: Also try URL-decoded redirect_to within the redirect_to
		// (Magic Login Pro sometimes double-wraps the URL)
		if ( ! $request_id ) {
			foreach ( [ $redirect_to, $requested ] as $url ) {
				if ( preg_match( '/zdz_bridge_request_id[=%]([0-9a-f\-]{36})/i', urldecode( $url ), $m ) ) {
					$request_id = sanitize_text_field( $m[1] );
					break;
				}
			}
		}

		error_log( 'ZDZ_Magic_Link_Bridge: Resolved request_id=' . ( $request_id ?: '(none)' ) );

		// ── Always generate a short code + bridge token (used by both flows) ──
		$short_code   = self::generate_short_code();
		$bridge_token = bin2hex( random_bytes( 32 ) ); // 64 hex chars

		// Store the bridge token
		$token_data = [
			'user_id'    => $user->ID,
			'request_id' => $request_id,
			'created'    => time(),
		];
		set_transient( self::TRANSIENT_PREFIX . 'token_' . $bridge_token, $token_data, self::TOKEN_TTL );

		// Store the short code (longer TTL — user needs time to type it)
		$code_data = [
			'user_id'      => $user->ID,
			'bridge_token' => $bridge_token,
			'created'      => time(),
		];
		set_transient( self::TRANSIENT_PREFIX . 'code_' . $short_code, $code_data, self::CODE_TTL );

		// ── Warm start: request_id present (PWA is open and polling) ──
		if ( $request_id ) {
			$req_data = get_transient( self::TRANSIENT_PREFIX . 'req_' . $request_id );
			if ( $req_data && is_array( $req_data ) && (int) $req_data['user_id'] === $user->ID ) {
				// Update the request status so the PWA's polling picks it up
				$req_data['status']       = 'ready';
				$req_data['bridge_token'] = $bridge_token;
				set_transient( self::TRANSIENT_PREFIX . 'req_' . $request_id, $req_data, self::REQUEST_TTL );
			}

			return add_query_arg(
				[
					'magic-login-bridge' => '1',
					'request_id'         => $request_id,
					'bridge_token'       => $bridge_token,
					'bridge_code'        => $short_code,
				],
				home_url( '/login/' )
			);
		}

		// ── Cold start: no request_id, but iOS device ──
		// The user tapped the email link without the PWA open.
		// Redirect to the interstitial with just the code.
		if ( self::is_ios_device() ) {
			error_log( 'ZDZ_Magic_Link_Bridge: Cold-start login for ' . $user->user_login . ' — code generated' );

			return add_query_arg(
				[
					'magic-login-bridge' => '1',
					'bridge_code'        => $short_code,
				],
				home_url( '/login/' )
			);
		}

		// ── Desktop / non-iOS: normal redirect (no interstitial needed) ──
		return $redirect_to;
	}

	/**
	 * Generate a 6-digit numeric login code.
	 *
	 * Uses cryptographically secure randomness. Codes always start with
	 * 1-9 so the user doesn't wonder if it's 5 or 6 digits.
	 *
	 * @since 2.19.0
	 * @return string
	 */
	private static function generate_short_code() {
		return (string) random_int( 100000, 999999 );
	}

	/**
	 * Check if the current request is from an iOS device.
	 *
	 * @since 2.19.0
	 * @return bool
	 */
	public static function is_ios_device() {
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? $_SERVER['HTTP_USER_AGENT'] : '';
		return (bool) preg_match( '/iPhone|iPad|iPod/', $ua );
	}

	/**
	 * The address used for RATE LIMITING: the raw TCP peer (REMOTE_ADDR), which a client cannot
	 * forge. Deliberately NOT a forwarded header (CF-Connecting-IP / X-Forwarded-For), which a
	 * client can set at will and which made the OTP rate limit bypassable. A deployment behind a
	 * trusted reverse proxy that terminates client connections may override this via the filter
	 * with a value it has itself validated. Do not route a forwarded header in here by default.
	 *
	 * @return string
	 */
	private static function rate_limit_ip(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
		$ip = (string) apply_filters( 'zdz_magic_link_rate_limit_ip', $ip );
		return sanitize_text_field( $ip );
	}

	/**
	 * Get client IP address (for logging/display only — never for a security decision; use
	 * rate_limit_ip() for anything that gates access).
	 *
	 * @return string
	 */
	private static function get_client_ip() {
		$headers = [
			'HTTP_CF_CONNECTING_IP', // Cloudflare
			'HTTP_X_FORWARDED_FOR',
			'HTTP_X_REAL_IP',
			'REMOTE_ADDR',
		];
		foreach ( $headers as $header ) {
			if ( ! empty( $_SERVER[ $header ] ) ) {
				$ip = sanitize_text_field( $_SERVER[ $header ] );
				// X-Forwarded-For may contain multiple IPs
				if ( strpos( $ip, ',' ) !== false ) {
					$ip = trim( explode( ',', $ip )[0] );
				}
				return $ip;
			}
		}
		return '0.0.0.0';
	}

	/**
	 * POST /zorderz/v1/magic-link-send-code
	 *
	 * v2.20.0: OTP-style login — generates a 6-digit code and emails it
	 * directly to the user. The user enters the code in the PWA login
	 * screen and it's claimed via /magic-link-code-claim.
	 *
	 * This eliminates Safari from the login flow entirely. The user never
	 * leaves the PWA — they just check their email for the code, type it
	 * in, and they're authenticated.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_send_code( WP_REST_Request $request ) {
		$email = $request->get_param( 'email' );

		// Rate limit: max 5 code sends per IP per 10 minutes
		$ip_key = self::TRANSIENT_PREFIX . 'otp_rate_' . md5( self::get_client_ip() );
		$count  = (int) get_transient( $ip_key );
		if ( $count >= 5 ) {
			return new WP_Error(
				'rate_limited',
				'Too many requests. Please try again later.',
				[ 'status' => 429 ]
			);
		}
		set_transient( $ip_key, $count + 1, self::REQUEST_TTL );

		// Verify the email belongs to a valid user (but don't reveal which)
		$user = get_user_by( 'email', $email );
		if ( ! $user ) {
			// Return success even for invalid emails to avoid user enumeration.
			// We still delay the same amount so timing attacks don't work.
			return new WP_REST_Response( [ 'success' => true ], 200 );
		}

		// Generate the code
		$short_code   = self::generate_short_code();
		$bridge_token = bin2hex( random_bytes( 32 ) );

		// Store the bridge token
		set_transient( self::TRANSIENT_PREFIX . 'token_' . $bridge_token, [
			'user_id'    => $user->ID,
			'request_id' => '',
			'created'    => time(),
		], self::TOKEN_TTL * 3 ); // 6 minutes for OTP (user needs time to check email)

		// Store the short code (longer TTL for OTP flow)
		set_transient( self::TRANSIENT_PREFIX . 'code_' . $short_code, [
			'user_id'      => $user->ID,
			'bridge_token' => $bridge_token,
			'created'      => time(),
		], self::CODE_TTL );

		// Send the email with the code
		$site_name   = get_bloginfo( 'name' ) ?: 'Zorderz';
		$code_display = substr( $short_code, 0, 3 ) . ' ' . substr( $short_code, 3 );

		$subject = sprintf( '%s — Your login code: %s', $site_name, $code_display );
		$message = sprintf(
			"Hi %s,\n\n" .
			"Your login code for %s is:\n\n" .
			"    %s\n\n" .
			"Enter this code in the app to log in. The code expires in 5 minutes and can only be used once.\n\n" .
			"If you didn't request this code, you can safely ignore this email.\n\n" .
			"— %s",
			$user->display_name ?: $user->user_login,
			$site_name,
			$code_display,
			$site_name
		);

		$headers = [ 'Content-Type: text/plain; charset=UTF-8' ];

		wp_mail( $email, $subject, $message, $headers );

		error_log( sprintf(
			'ZDZ_Magic_Link_Bridge: OTP code emailed to %s for user %s',
			$email,
			$user->user_login
		) );

		return new WP_REST_Response( [ 'success' => true ], 200 );
	}

	/**
	 * wp_mail filter: Replace Magic Login's magic-link email with a code-only email.
	 *
	 * v2.20.3: appended a 6-digit code to Magic Login's email (link + code).
	 * v2.21.0: REPLACE the email entirely. The clickable magic link is the
	 *   problem on iOS — tapping a link in Mail opens Safari (a separate context
	 *   from the installed standalone PWA), so the session never lands in the
	 *   app. We keep Magic Login installed as the *sender/engine* (it still
	 *   triggers this email and remains the auth mechanism elsewhere), but when
	 *   it emails a magic link we throw the body away and substitute a basic
	 *   plain-text email that contains ONLY the 6-digit code — no link to tap.
	 *
	 *   The code itself is claimed via /zorderz/v1/magic-link-code-claim, which sets
	 *   the auth cookie directly (see handle_code_claim) — the link is not needed.
	 *   Format intentionally mirrors handle_send_code() so both delivery paths
	 *   produce an identical-looking email.
	 *
	 * @param array $args wp_mail arguments: to, subject, message, headers, attachments.
	 * @return array Modified args.
	 */
	public function inject_otp_into_magic_login_email( $args ) {
		// Detect Magic Login emails by the presence of the magic-login token URL
		// in the body. (We only override Magic Login's own link emails; any other
		// mail the site sends passes through untouched.)
		if (
			! is_array( $args ) ||
			empty( $args['message'] ) ||
			( stripos( $args['message'], 'magic-login' ) === false &&
			  stripos( $args['message'], 'magic_login' ) === false )
		) {
			return $args;
		}

		// Determine which user this email is for
		$to   = is_array( $args['to'] ) ? $args['to'][0] : $args['to'];
		$user = get_user_by( 'email', $to );
		if ( ! $user ) {
			return $args;
		}

		// Generate an OTP code
		$short_code   = self::generate_short_code();
		$bridge_token = bin2hex( random_bytes( 32 ) );

		// Store the bridge token (TTL gives the user time to check email)
		set_transient( self::TRANSIENT_PREFIX . 'token_' . $bridge_token, [
			'user_id'    => $user->ID,
			'request_id' => '',
			'created'    => time(),
		], self::TOKEN_TTL * 3 );

		// Store the short code
		set_transient( self::TRANSIENT_PREFIX . 'code_' . $short_code, [
			'user_id'      => $user->ID,
			'bridge_token' => $bridge_token,
			'created'      => time(),
		], self::CODE_TTL );

		$site_name    = get_bloginfo( 'name' ) ?: 'Zorderz';
		$code_display = substr( $short_code, 0, 3 ) . ' ' . substr( $short_code, 3 );

		// REPLACE the body entirely — basic, code-only, no clickable link.
		// (Replacing rather than stripping is robust to Magic Login template
		// changes: we never parse their markup, we just substitute our own.)
		$args['message'] = sprintf(
			"Hi %s,\n\n" .
			"Your login code for %s is:\n\n" .
			"    %s\n\n" .
			"Enter this code in the app to log in. The code expires in 5 minutes and can only be used once.\n\n" .
			"If you didn't request this code, you can safely ignore this email.\n\n" .
			"— %s",
			$user->display_name ?: $user->user_login,
			$site_name,
			$code_display,
			$site_name
		);

		// Code-only subject (easy to find in the inbox).
		$args['subject'] = sprintf( '%s — Your login code: %s', $site_name, $code_display );

		// Force plain text so no HTML link markup from the original survives,
		// regardless of any Content-Type header Magic Login set.
		$args['headers'] = self::force_plain_text_headers( $args['headers'] ?? [] );

		// Magic Login's link email may carry attachments meant for the HTML body;
		// a plain code email needs none.
		$args['attachments'] = [];

		error_log( sprintf(
			'ZDZ_Magic_Link_Bridge: replaced Magic Login email with code-only message (code %s) for %s',
			$code_display,
			$user->user_login
		) );

		return $args;
	}

	/**
	 * Normalize wp_mail headers to a single text/plain Content-Type.
	 *
	 * Magic Login may send HTML (text/html). Since we replace the body with a
	 * plain-text code email, strip any existing Content-Type header(s) and pin
	 * text/plain so mail clients never try to render leftover/again-added HTML.
	 * Accepts the header in either array or string form (both are valid for
	 * wp_mail) and always returns an array.
	 *
	 * @param array|string $headers Existing wp_mail headers.
	 * @return array Headers with exactly one text/plain Content-Type.
	 */
	private static function force_plain_text_headers( $headers ) {
		if ( is_string( $headers ) ) {
			$headers = preg_split( '/\r\n|\r|\n/', $headers );
		}
		if ( ! is_array( $headers ) ) {
			$headers = [];
		}

		$kept = [];
		foreach ( $headers as $header ) {
			// Drop any existing Content-Type line (we set our own below).
			if ( is_string( $header ) && stripos( ltrim( $header ), 'content-type:' ) === 0 ) {
				continue;
			}
			$kept[] = $header;
		}
		$kept[] = 'Content-Type: text/plain; charset=UTF-8';

		return $kept;
	}

	/**
	 * wp_redirect filter fallback for Magic Login Pro.
	 *
	 * v2.20.0: Some versions of Magic Login Pro call wp_redirect() directly
	 * after authenticating, bypassing the login_redirect filter entirely.
	 * This filter catches those redirects when they contain a
	 * zdz_bridge_request_id in the URL and rewrites them to the bridge
	 * interstitial.
	 *
	 * It also catches any iOS login redirect (even without a request_id)
	 * and ensures the interstitial with the 6-digit code is shown.
	 *
	 * @param string $location  The redirect URL.
	 * @param int    $status    The HTTP status code.
	 * @return string
	 */
	public function filter_wp_redirect_bridge( $location, $status ) {
		if ( ! is_user_logged_in() ) {
			return $location;
		}

		// Only act when redirecting to the home URL or a URL with bridge params
		$home = home_url( '/' );
		$has_bridge_id = ( strpos( $location, 'zdz_bridge_request_id' ) !== false );
		$is_home       = ( strpos( $location, $home ) === 0 );

		if ( ! $has_bridge_id && ! $is_home ) {
			return $location;
		}

		// Don't intercept if we're already going to the bridge interstitial
		if ( strpos( $location, 'magic-login-bridge=1' ) !== false ) {
			return $location;
		}

		// Don't intercept the OAuth bounce (handled separately)
		if ( strpos( $location, 'zdz_authorized=' ) !== false || strpos( $location, 'zdz_auth_error=' ) !== false ) {
			return $location;
		}

		// Check if this looks like a post-authentication redirect
		// (user just got authenticated and is being sent to the homepage)
		$user = wp_get_current_user();
		if ( ! $user || ! $user->ID ) {
			return $location;
		}

		// Only trigger for iOS devices (desktop users don't need the bridge)
		if ( ! self::is_ios_device() ) {
			return $location;
		}

		// Check if we've already handled this request (prevent infinite redirect)
		$handled_key = self::TRANSIENT_PREFIX . 'handled_' . $user->ID;
		if ( get_transient( $handled_key ) ) {
			return $location;
		}
		set_transient( $handled_key, 1, 30 ); // 30-second cooldown

		// Extract request_id if present
		$request_id = '';
		if ( $has_bridge_id ) {
			$decoded = urldecode( $location );
			if ( preg_match( '/zdz_bridge_request_id=([0-9a-f\-]{36})/i', $decoded, $m ) ) {
				$request_id = sanitize_text_field( $m[1] );
			}
		}

		// Delegate to the main bridge logic
		error_log( 'ZDZ_Magic_Link_Bridge: wp_redirect fallback caught redirect for ' . $user->user_login );
		return $this->filter_login_redirect( $location, $location, $user );
	}
}
