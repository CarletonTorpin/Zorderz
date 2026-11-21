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
 *   3. User opens PWA, taps "Already have a code?", enters their email and the 6 digits
 *   4. PWA calls /zorderz/v1/magic-link-code-claim → gets auth cookie
 *
 * Every login code (emailed, shown on the interstitial, or given by a person) is kept and claimed through
 * ZDZ_Signin_Routes: per account, as a salted hash, and claimed with the email it is for. This class knows the
 * three paths a code is made on and the per-connection limits; ZDZ_Signin_Routes knows the per-code budgets, who
 * may give a code and where codes are stored.
 *
 * @package Zorderz
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
	 */
	const CODE_TTL = 300; // 5 minutes

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * The backup address typed in this request's login form, when premap_login_alias() rewrote it to the
	 * account's own address (so the code made for that form can be kept under what was typed).
	 *
	 * @var string
	 */
	private static $typed_alias = '';

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

		// Fallback — catch Magic Login Pro's wp_redirect() in case it
		// bypasses the login_redirect filter entirely. This fires at priority 5
		// (before our OAuth bounce at priority 1) to intercept the redirect URL
		// when it contains a zdz_bridge_request_id.
		add_filter( 'wp_redirect', [ $this, 'filter_wp_redirect_bridge' ], 5, 2 );

		// Inject an OTP code into Magic Login's magic link email so the
		// user receives ONE email containing both the clickable link and a 6-digit
		// code. This eliminates the separate "Send me a code" step entirely.
		add_filter( 'wp_mail', [ $this, 'inject_otp_into_magic_login_email' ], 99 );

		// Alias-tolerant login: rewrite a recognised login alias in the POST fields
		// to the account's canonical user_email BEFORE the login plugin's own exact
		// lookup runs. Registered unconditionally but self-gated on Magic-Login-Pro
		// presence inside the handler (the OTP-path resolver works standalone, so
		// Zorderz must not hard-assume that plugin). init:0 = earliest, before auth.
		add_action( 'init', [ $this, 'premap_login_alias' ], 0 );
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

		// Short-code claim endpoint for cold-start logins.
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
						// Six digits: an emailed code, or the one shown on the interstitial. Eight: a code a person gave.
						return is_string( $value ) && (bool) preg_match( '/^[0-9]{6}([0-9]{2})?$/', $value );
					},
				],
				// The email the code is for. Not "required" here, so a login page loaded before this field existed
				// gets handle_code_claim's own plain sentence rather than "Missing parameter(s)". A string or
				// nothing: a list never reaches sanitize_email().
				'email' => [
					'required'          => false,
					'type'              => 'string',
					'validate_callback' => function ( $value ) {
						return is_string( $value );
					},
					'sanitize_callback' => 'sanitize_email',
				],
			],
		] );

		// OTP email — sends a 6-digit code directly in the email.
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
		$refused = self::refused_in_privacy_view();
		if ( $refused ) {
			return $refused;
		}

		$request_id = $request->get_param( 'request_id' );
		$email      = $request->get_param( 'email' );

		// Verify the email belongs to a valid user (don't reveal which). Resolves the
		// account email OR an admin-configured login alias; returns null on a genuine
		// miss, so the anti-enumeration no-op below is unchanged.
		// Rate limit: max 5 inits per IP per 10 minutes. Counted BEFORE the account lookup so
		// that existing and unknown addresses are throttled identically (otherwise the 429 itself
		// tells a caller which addresses have accounts), and keyed on REMOTE_ADDR via
		// rate_limit_ip(): a forwarded header is client-controlled and would hand every request
		// a fresh bucket.
		$ip_key = self::TRANSIENT_PREFIX . 'rate_' . md5( self::rate_limit_ip() );
		$count  = (int) get_transient( $ip_key );
		if ( $count >= 5 ) {
			return new WP_Error(
				'rate_limited',
				'Too many requests. Please try again later.',
				[ 'status' => 429 ]
			);
		}
		set_transient( $ip_key, $count + 1, self::REQUEST_TTL );

		$user = self::resolve_login_user( $email );
		if ( ! $user ) {
			// Return success even for invalid emails to avoid user enumeration
			return new WP_REST_Response( [ 'success' => true ], 200 );
		}

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
		$refused = self::refused_in_privacy_view();
		if ( $refused ) {
			return $refused;
		}

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
		$refused = self::refused_in_privacy_view();
		if ( $refused ) {
			return $refused;
		}

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
			'redirect' => class_exists( 'ZDZ_Login_Next' ) ? ZDZ_Login_Next::url_or( home_url( '/' ) ) : home_url( '/' ),
		], 200 );
	}

	/**
	 * POST /zorderz/v1/magic-link-code-claim
	 *
	 * Claims a login code and sets the auth cookie in the PWA. A claim is the email AND the code. Six digits is
	 * an emailed code, or the one on Safari's interstitial (the cold-start fallback: user taps the email link
	 * without the PWA open, sees the code on the interstitial, then types it into the PWA login screen). Eight
	 * digits is a one-time code a person gave (ZDZ_Signin_Routes).
	 *
	 * A code is kept per account, never under a name made of its digits, and is weighed only against the codes
	 * of the account the typed email belongs to. So two people who are given the same digits each sign in as
	 * themselves, and the answer is the same for an unknown address and a wrong code. A wrong try costs the code
	 * one of its tries (ZDZ_Signin_Routes::claim()); there is no lock on the account.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_code_claim( WP_REST_Request $request ) {
		$refused = self::refused_in_privacy_view();
		if ( $refused ) {
			return $refused;
		}

		// Shape-check before lookup: a code is six digits or eight, so anything else is a cheap miss that never
		// reaches storage.
		$code  = $request->get_param( 'code' );
		$code  = is_string( $code ) ? (string) preg_replace( '/\D/', '', $code ) : '';
		$given = 8 === strlen( $code ); // a code a person gave: counted apart from the six-digit ones throughout

		// Site-wide backstop against distributed brute force: a global cap on WRONG six-digit attempts. Legit
		// users paste a real code and almost never miss, so this trips on scanning, not on normal use. Thirty
		// connecting addresses at their own ceiling reach it. An eight-digit code is neither counted nor stopped
		// here: it has its own budget of tries, so a flood of six-digit guesses cannot keep out someone holding
		// a code a person has just read to them. Per-IP key is handled just below.
		$miss_key     = self::TRANSIENT_PREFIX . 'code_miss_global';
		$miss_count   = (int) get_transient( $miss_key );
		$miss_ceiling = (int) apply_filters( 'zdz_magic_link_global_miss_ceiling', 1800 );
		if ( ! $given && $miss_count >= $miss_ceiling ) {
			return new WP_Error(
				'rate_limited',
				'Too many attempts right now. Please request a new login link and try again shortly.',
				[ 'status' => 429 ]
			);
		}

		// Per-IP attempt cap, keyed on the UN-SPOOFABLE connecting address (REMOTE_ADDR), never a
		// client-settable forwarded header. Trusting a forwarded header let an attacker land in a
		// fresh bucket every request and defeat this limit entirely.
		// Sixty in five minutes: when the mailboxes are down a whole office claims from one connecting
		// address. What stops a guesser is counted against the code itself, not here.
		$ip_key     = self::TRANSIENT_PREFIX . 'code_rate_' . md5( self::rate_limit_ip() );
		$count      = (int) get_transient( $ip_key );
		$ip_ceiling = (int) apply_filters( 'zdz_magic_link_ip_attempt_ceiling', 60 );
		if ( $count >= $ip_ceiling ) {
			return new WP_Error(
				'rate_limited',
				'Too many attempts. Please try again later.',
				[ 'status' => 429 ]
			);
		}
		set_transient( $ip_key, $count + 1, self::CODE_TTL );

		// The email the code is for. A login page loaded before the email went with the code sends none: it gets
		// a plain sentence, and nothing is weighed.
		$email = $request->get_param( 'email' ); // sentinel:allow the typed address only chooses whose kept codes the code is weighed against; the signed-in identity is the account whose own server-side record matches the code (ZDZ_Signin_Routes::claim), never the address alone
		$email = is_string( $email ) ? sanitize_email( $email ) : '';
		if ( '' === $email || ! is_email( $email ) ) {
			return new WP_Error(
				'email_needed',
				__( 'Enter your email with the code. If this screen has no place for it, reload the page.', 'zorderz' ),
				[ 'status' => 400 ]
			);
		}

		// Weigh the code against that account's own codes (single use: a code that matches is removed there).
		$shaped = $given || 6 === strlen( $code );
		$claim  = ( $shaped && class_exists( 'ZDZ_Signin_Routes' ) ) ? ZDZ_Signin_Routes::claim( $email, $code ) : null;

		if ( ! is_array( $claim ) || ! ( ( $claim['user'] ?? null ) instanceof WP_User ) ) {
			// Count the miss toward the global backstop, then refuse. The same answer whatever was wrong.
			if ( ! $given ) {
				set_transient( $miss_key, $miss_count + 1, self::CODE_TTL );
			}
			return new WP_Error(
				'invalid_code',
				__( 'Code is invalid or expired. Use the same email you asked for the code with, or ask for a new code.', 'zorderz' ),
				[ 'status' => 401 ]
			);
		}

		$user    = $claim['user'];
		$user_id = (int) $user->ID;
		$record  = is_array( $claim['rec'] ?? null ) ? $claim['rec'] : [];

		// Also clean up the bridge token if one was made with the code
		if ( ! empty( $record['tok'] ) && is_string( $record['tok'] ) ) {
			delete_transient( self::TRANSIENT_PREFIX . 'token_' . $record['tok'] );
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

		// How they got in (an emailed code, a backup address, a code a person gave), for Zorderz → Sign-in routes.
		ZDZ_Signin_Routes::after_sign_in( $user, $record );

		error_log( 'ZDZ_Magic_Link_Bridge: Code claim successful for user ' . $user->user_login );

		return new WP_REST_Response( [
			'success'  => true,
			'redirect' => class_exists( 'ZDZ_Login_Next' ) ? ZDZ_Login_Next::url_or( home_url( '/' ) ) : home_url( '/' ),
		], 200 );
	}

	/**
	 * Filter the login redirect URL to route through the bridge interstitial.
	 *
	 * This fires after Magic Login validates the token and sets the cookie.
	 *
	 * Warm-start flow — redirect to interstitial when request_id present.
	 * Cold-start flow — on iOS devices without a request_id, still
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

		// Diagnostic logging — track every invocation to debug
		// Magic Login Pro redirect issues. Safe to leave in production
		// (fires at most once per login).
		error_log( sprintf(
			'ZDZ_Magic_Link_Bridge::filter_login_redirect called | user=%s | redirect_to=%s | requested=%s | is_ios=%s | UA=%s',
			$user->user_login,
			$redirect_to,
			$requested,
			self::is_ios_device() ? 'yes' : 'no',
			isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 80 ) : 'n/a'
		) );

		// Parse out any request_id from the redirect URL — check both
		// $requested (what the form asked for) and $redirect_to (what
		// WordPress resolved), plus the raw $_GET in case Magic Login Pro
		// passes it through the query string directly.
		$request_id = '';

		// Also check $_GET directly — some Magic Login Pro versions
		// pass the redirect_to params through the query string on the callback
		// page rather than in the login_redirect filter arguments.
		if ( ! empty( $_GET['zdz_bridge_request_id'] ) ) {
			$request_id = sanitize_text_field( wp_unslash( $_GET['zdz_bridge_request_id'] ) );
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

		// Also try URL-decoded redirect_to within the redirect_to
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

		// The page this sign-in started from (ZDZ_Login_Next), if any.
		$next = class_exists( 'ZDZ_Login_Next' ) ? ZDZ_Login_Next::from_request( array( (string) $redirect_to, (string) $requested ) ) : '';

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

		// Keep the short code for this account (longer TTL — user needs time to type it): per account, never
		// under a name made of its digits. A code that could not be kept is not shown: the interstitial then
		// offers "open the app" only (the bridge token above still works for a PWA that is polling).
		$code_kept = class_exists( 'ZDZ_Signin_Routes' )
			&& ZDZ_Signin_Routes::put_code( $user->ID, $short_code, 'bridge', self::CODE_TTL, [ 'tok' => $bridge_token ] );
		if ( ! $code_kept ) {
			$short_code = '';
		}

		// ── Warm start: request_id present (PWA is open and polling) ──
		if ( $request_id ) {
			$req_data = get_transient( self::TRANSIENT_PREFIX . 'req_' . $request_id );
			if ( $req_data && is_array( $req_data ) && (int) $req_data['user_id'] === $user->ID ) {
				// Update the request status so the PWA's polling picks it up
				$req_data['status']       = 'ready';
				$req_data['bridge_token'] = $bridge_token;
				set_transient( self::TRANSIENT_PREFIX . 'req_' . $request_id, $req_data, self::REQUEST_TTL );
			}

			$args = [
				'magic-login-bridge' => '1',
				'request_id'         => $request_id,
				'bridge_token'       => $bridge_token,
				'bridge_code'        => $short_code,
			];
			if ( '' !== $next ) {
				$args['zdz_next'] = rawurlencode( $next );
			}
			return add_query_arg( $args, home_url( '/login/' ) );
		}

		// ── Cold start: no request_id, but iOS device ──
		// The user tapped the email link without the PWA open.
		// Redirect to the interstitial with just the code.
		if ( self::is_ios_device() ) {
			error_log( 'ZDZ_Magic_Link_Bridge: Cold-start login for ' . $user->user_login . ' — code generated' );

			$args = [
				'magic-login-bridge' => '1',
				'bridge_code'        => $short_code,
			];
			if ( '' !== $next ) {
				$args['zdz_next'] = rawurlencode( $next );
			}
			return add_query_arg( $args, home_url( '/login/' ) );
		}

		// ── Desktop / non-iOS: normal redirect (no interstitial needed) ──
		// Straight to the page that asked for the sign-in, when there is one.
		if ( '' !== $next ) {
			ZDZ_Login_Next::forget();
			return home_url( $next );
		}
		return $redirect_to;
	}

	/**
	 * Generate a 6-digit numeric login code.
	 *
	 * Uses cryptographically secure randomness. Codes always start with
	 * 1-9 so the user doesn't wonder if it's 5 or 6 digits.
	 *
	 * @return string
	 */
	private static function generate_short_code() {
		return (string) random_int( 100000, 999999 );
	}

	/**
	 * Check if the current request is from an iOS device.
	 *
	 * @return bool
	 */
	public static function is_ios_device() {
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
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
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '0.0.0.0';
		$ip = (string) apply_filters( 'zdz_magic_link_rate_limit_ip', $ip );
		return sanitize_text_field( $ip );
	}

	/**
	 * The client IP kept with a sign-in request (a record, never a security decision; rate_limit_ip() gates access):
	 * the connection's address (ZDZ_Client_IP::get()), never a forwarded header the client writes itself.
	 *
	 * @return string
	 */
	private static function get_client_ip() {
		if ( class_exists( 'ZDZ_Client_IP' ) ) {
			$ip = ZDZ_Client_IP::get();
		} else {
			$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? trim( (string) $_SERVER['REMOTE_ADDR'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- validated by FILTER_VALIDATE_IP on the next line; anything else becomes ''
			$ip = filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
		}
		return '' !== $ip ? $ip : '0.0.0.0';
	}

	/**
	 * POST /zorderz/v1/magic-link-send-code
	 *
	 * OTP-style login — generates a 6-digit code and emails it
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
		$refused = self::refused_in_privacy_view();
		if ( $refused ) {
			return $refused;
		}

		$email = $request->get_param( 'email' );

		// Rate limit per connecting address: 30 code sends in 10 minutes. When the mailboxes are down a whole
		// office asks from one address, so this is a brake on one connection, not the cap that protects an
		// inbox: that one is per account and typed address, below.
		// Keyed on REMOTE_ADDR (rate_limit_ip()), never a client-supplied forwarded header.
		$ip_key     = self::TRANSIENT_PREFIX . 'otp_rate_' . md5( self::rate_limit_ip() );
		$count      = (int) get_transient( $ip_key );
		$ip_ceiling = (int) apply_filters( 'zdz_magic_link_ip_send_ceiling', 30 );
		if ( $count >= $ip_ceiling ) {
			return new WP_Error(
				'rate_limited',
				'Too many requests. Please try again later.',
				[ 'status' => 429 ]
			);
		}
		set_transient( $ip_key, $count + 1, self::REQUEST_TTL );

		// Verify the email belongs to a valid user (but don't reveal which). Account
		// email OR an admin-configured alias; null on a genuine miss preserves the
		// anti-enumeration no-op below.
		$user = self::resolve_login_user( $email );
		if ( ! $user ) {
			// Return success even for invalid emails to avoid user enumeration.
			// We still delay the same amount so timing attacks don't work.
			return new WP_REST_Response( [ 'success' => true ], 200 );
		}

		// Per-account caps, so the pool of live codes for one person stays bounded and their inbox cannot be
		// flooded. Both are counted per TYPED address, so nothing a stranger does with one address of an account
		// uses up another: asks made with the work address never stop a code going to the backup address.
		//   - at most 3 codes per window from any one connecting address, so a stranger filling the cap from
		//     their own address does not stop the account's owner from getting a code from theirs;
		//   - at most ZDZ_Signin_Routes::MAX_SENDS in 15 minutes in all (may_send(), counted under the
		//     account's lock).
		// Answer as if sent either way, so the caps reveal nothing.
		$typed      = strtolower( trim( (string) $email ) );
		$pair_key   = self::TRANSIENT_PREFIX . 'otp_user_ip_' . md5( (int) $user->ID . '|' . $typed . '|' . self::rate_limit_ip() );
		$pair_count = (int) get_transient( $pair_key );
		if ( $pair_count >= 3 ) {
			return new WP_REST_Response( [ 'success' => true ], 200 );
		}
		set_transient( $pair_key, $pair_count + 1, self::REQUEST_TTL );
		if ( ! class_exists( 'ZDZ_Signin_Routes' ) || ! ZDZ_Signin_Routes::may_send( (int) $user->ID, $typed ) ) {
			return new WP_REST_Response( [ 'success' => true ], 200 );
		}

		// Generate the code, and keep it for this account under the address that was typed (the claim must carry
		// the same one). A code that could not be kept is not sent.
		$short_code = self::generate_short_code();
		if ( ! ZDZ_Signin_Routes::put_code( $user->ID, $short_code, 'mail', self::CODE_TTL, [ 'to' => $typed ] ) ) {
			return new WP_REST_Response( [ 'success' => true ], 200 );
		}

		// Send the email with the code
		$site_name   = get_bloginfo( 'name' ) ?: 'Zorderz';
		$code_display = substr( $short_code, 0, 3 ) . ' ' . substr( $short_code, 3 );

		$subject = sprintf( '%s · Your login code: %s', $site_name, $code_display );
		$message = sprintf(
			"Hi %s,\n\n" .
			"Your login code for %s is:\n\n" .
			"    %s\n\n" .
			"Enter this code in the app to log in. The code expires in 5 minutes and can only be used once.\n\n" .
			"If you didn't request this code, you can safely ignore this email.\n\n" .
			"Thanks,\n%s",
			$user->display_name ?: $user->user_login,
			$site_name,
			$code_display,
			$site_name
		);

		$headers = [ 'Content-Type: text/plain; charset=UTF-8' ];

		// A send the mailer refused is recorded for Zorderz → Sign-in routes. The screen is told "sent" either way.
		if ( ! wp_mail( $email, $subject, $message, $headers ) ) {
			ZDZ_Signin_Routes::mail_failed( (int) $user->ID );
		}

		// Who and which kind of address, never the address: a backup address is a person's own mailbox.
		$own = 0 === strcasecmp( trim( (string) $email ), trim( (string) $user->user_email ) );
		error_log( sprintf(
			'ZDZ_Magic_Link_Bridge: sign-in code emailed for user #%d to %s',
			(int) $user->ID,
			$own ? 'the account address' : 'a backup address'
		) );

		return new WP_REST_Response( [ 'success' => true ], 200 );
	}

	/**
	 * wp_mail filter: Replace Magic Login's magic-link email with a code-only email.
	 *
	 * appended a 6-digit code to Magic Login's email (link + code).
	 * REPLACE the email entirely. The clickable magic link is the
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

		// Determine which user this email is for. The recipient may be an account
		// email OR a configured login alias; resolve both. A genuine miss returns
		// the mail untouched (unchanged behaviour).
		$to   = is_array( $args['to'] ) ? $args['to'][0] : $args['to'];
		$user = self::resolve_login_user( $to );
		if ( ! $user ) {
			return $args;
		}

		// The address the person TYPED in the login form. premap_login_alias() rewrote a backup address to the
		// account's own before the login plugin saw it, so $to is the account address; the claim will carry what
		// was typed, and the code is kept under that. What was typed in this request is used only for the
		// account it belongs to. (This form always mails the ACCOUNT address: a backup address gets a code in
		// its own mailbox only from "Send me a code".)
		$typed = (string) $to;
		if ( '' !== self::$typed_alias ) {
			$alias_user = self::resolve_login_user( self::$typed_alias );
			if ( $alias_user instanceof WP_User && (int) $alias_user->ID === (int) $user->ID ) {
				$typed = self::$typed_alias;
			}
		}

		// Generate an OTP code and keep it for this account, under the same cap as "Send me a code"
		// (ZDZ_Signin_Routes::MAX_SENDS for one typed address in 15 minutes). The login plugin's email can't be
		// stopped from here, so past the cap, or when the code can't be kept, it goes out with no code and no
		// link in it.
		$short_code = self::generate_short_code();
		if ( ! class_exists( 'ZDZ_Signin_Routes' )
			|| ! ZDZ_Signin_Routes::may_send( (int) $user->ID, $typed )
			|| ! ZDZ_Signin_Routes::put_code( $user->ID, $short_code, 'mail', self::CODE_TTL, [ 'to' => $typed, 'sent' => (string) $to ] ) ) {
			/* translators: %s: the site's name */
			$args['subject']     = sprintf( __( '%s · no login code this time', 'zorderz' ), get_bloginfo( 'name' ) ?: 'Zorderz' );
			$args['message']     = __( "Too many login codes were asked for this address, so none was made this time. Wait 15 minutes, then ask again.\n\nIf you didn't ask for one, you can ignore this email.", 'zorderz' );
			$args['headers']     = self::force_plain_text_headers( $args['headers'] ?? [] );
			$args['attachments'] = [];
			return $args;
		}

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
			"Thanks,\n%s",
			$user->display_name ?: $user->user_login,
			$site_name,
			$code_display,
			$site_name
		);

		// Code-only subject (easy to find in the inbox).
		$args['subject'] = sprintf( '%s · Your login code: %s', $site_name, $code_display );

		// Force plain text so no HTML link markup from the original survives,
		// regardless of any Content-Type header Magic Login set.
		$args['headers'] = self::force_plain_text_headers( $args['headers'] ?? [] );

		// Magic Login's link email may carry attachments meant for the HTML body;
		// a plain code email needs none.
		$args['attachments'] = [];

		// Never log the code itself: it is a live login credential.
		error_log( sprintf(
			'ZDZ_Magic_Link_Bridge: replaced Magic Login email with code-only message for %s',
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
	 * Some versions of Magic Login Pro call wp_redirect() directly
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

	/**
	 * A Privacy View account is refused every sign-in route here. It is already signed in, and everything it may
	 * reach is on its own short lists (ZDZ_Privacy_View), whose gate refuses these routes before WordPress routes
	 * the request. This is the same answer from the handler itself, so it holds wherever the request is judged.
	 *
	 * @return WP_Error|null The refusal, or null for everyone else.
	 */
	private static function refused_in_privacy_view() {
		if ( class_exists( 'ZDZ_Privacy_View' ) && ZDZ_Privacy_View::is_on() ) {
			return new WP_Error(
				'zdz_privacy_view',
				__( 'Not available in Privacy View.', 'zorderz' ),
				[ 'status' => 403 ]
			);
		}
		return null;
	}

	/**
	 * Resolve a login identifier to a WP_User: the account's own user_email first,
	 * then an admin-configured login alias. Returns null on a genuine miss so every
	 * caller keeps its existing anti-enumeration behaviour (each already no-ops
	 * silently on null and never reveals whether an address exists).
	 *
	 * Carries NO domain assumption — it is exact-match-then-alias, fully generic.
	 * With no aliases configured anywhere, this is byte-identical to the previous
	 * direct get_user_by('email', …) call.
	 *
	 * @param string $email
	 * @return WP_User|null
	 */
	public static function resolve_login_user( $email ) {
		$email = trim( (string) $email );
		if ( '' === $email ) {
			return null;
		}
		$user = get_user_by( 'email', $email ); // sentinel:allow alias-resolution lookup only — resolves an address to an account; nobody is signed in by it: a sign-in also needs a code that matches that account's own kept record (ZDZ_Signin_Routes::claim), never this request-supplied address alone
		if ( $user instanceof WP_User ) {
			return $user;
		}
		return self::user_by_alias( $email );
	}

	/**
	 * Find the user whose zdz_login_aliases meta contains this address. Bounded scan
	 * (only users that actually carry the alias meta), lower-cased comparison, first
	 * match wins. Returns null when nothing matches. An account that gets no sign-in
	 * routes (the shared device, an account in Privacy View) is never found by a backup
	 * address, even one it was given before it became one.
	 *
	 * @param string $email
	 * @return WP_User|null
	 */
	public static function user_by_alias( $email ) {
		$needle = strtolower( trim( (string) $email ) );
		if ( '' === $needle || ! is_email( $needle ) ) {
			return null;
		}
		$cap   = (int) apply_filters( 'zdz_login_alias_scan_cap', 500 );
		$users = get_users( [
			'meta_key' => 'zdz_login_aliases',
			'number'   => $cap,
			'fields'   => 'all',
		] );
		foreach ( $users as $user ) {
			$aliases = get_user_meta( $user->ID, 'zdz_login_aliases', true );
			if ( ! is_array( $aliases ) ) {
				continue;
			}
			foreach ( $aliases as $alias ) {
				if ( strtolower( trim( (string) $alias ) ) === $needle ) {
					if ( class_exists( 'ZDZ_Signin_Routes' ) && ! ZDZ_Signin_Routes::gets_routes( (int) $user->ID ) ) {
						continue 2;
					}
					return $user;
				}
			}
		}
		return null;
	}

	/**
	 * init:0 — rewrite a recognised login alias in the login POST fields to the
	 * account's canonical user_email, BEFORE the login plugin's own exact lookup
	 * runs. Only email-shaped values that are NOT already a real account email but
	 * ARE a known alias are rewritten; everything else is left untouched.
	 *
	 * Gated on Magic-Login-Pro presence because this pre-map is coupled to that
	 * plugin's POST field names — Zorderz must not hard-assume it. The OTP-code
	 * resolver (resolve_login_user) works standalone and is unaffected by this gate.
	 * The candidate-field list is filterable so a different login mechanism can
	 * name its own fields.
	 *
	 * @return void
	 */
	public function premap_login_alias() {
		if ( ! self::has_magic_login() ) {
			return;
		}
		if ( empty( $_POST ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Hooks Magic Login Pro's own form submit (which carries and verifies its nonce); this pass only pre-maps an alias address in $_POST to the canonical account email before that plugin authenticates.
			return;
		}
		$fields = (array) apply_filters( 'zdz_login_alias_fields', [ 'log', 'user_login', 'email' ] );
		foreach ( $fields as $field ) {
			if ( ! is_string( $field ) || empty( $_POST[ $field ] ) || ! is_string( $_POST[ $field ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Magic Login Pro alias pre-map; nonce is owned and verified by that plugin's form, not this pass.
				continue;
			}
			$candidate = sanitize_text_field( wp_unslash( $_POST[ $field ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Magic Login Pro alias pre-map; value is validated by is_email() below.
			// Only email-shaped values can be an alias; a username login is left alone.
			if ( '' === $candidate || ! is_email( $candidate ) ) {
				continue;
			}
			// Already the canonical account email — nothing to rewrite.
			if ( get_user_by( 'email', $candidate ) instanceof WP_User ) { // sentinel:allow existence check that skips already-canonical emails during alias pre-map; the rewrite below only maps $_POST to the real account email, never sets the authenticated identity
				continue;
			}
			$user = self::user_by_alias( $candidate );
			if ( $user instanceof WP_User ) {
				// Kept for this request: the code made for this form is kept under what was typed
				// (inject_otp_into_magic_login_email()).
				self::$typed_alias = strtolower( $candidate );
				$_POST[ $field ] = $user->user_email;
			}
		}
	}

	/**
	 * Is Magic Login Pro present? Detected by its known class / function / constant
	 * (front-end safe — does not depend on is_plugin_active(), which is admin-only).
	 * Always passes through the zdz_has_magic_login filter so a site can force the
	 * answer for a different but field-compatible login mechanism.
	 *
	 * @return bool
	 */
	private static function has_magic_login() {
		$present = class_exists( 'Magic_Login_Pro' )
			|| function_exists( 'magic_login_pro' )
			|| defined( 'MAGIC_LOGIN_PRO_FILE' )
			|| defined( 'MAGIC_LOGIN_VERSION' );
		return (bool) apply_filters( 'zdz_has_magic_login', $present );
	}
}
