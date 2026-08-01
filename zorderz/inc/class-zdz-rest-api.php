<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZDZ_Rest_API {

	private static $instance = null;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes() {
		register_rest_route( 'zorderz/v1', '/load-app', [
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'handle_load_app' ],
			'permission_callback' => [ $this, 'check_permission' ],
			'args'                => [
				'app_id' => [
					'required'          => true,
					'sanitize_callback' => 'sanitize_text_field',
				],
			],
		] );

		// Note: /report-bug route is registered by ZDZ_Bug_Tracker class

		register_rest_route( 'zorderz/v1', '/user-apps', [
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => [ $this, 'handle_user_apps' ],
			'permission_callback' => [ $this, 'check_permission' ],
		] );

		// v2.11.0: Front-end OAuth authorize flow — allow non-admin TS users
		// (zdz_sales / zdz_operator / zdz_mfg / zdz_tech) to authorize FreshBooks
		// from the Settings view without needing wp-admin access.
		register_rest_route( 'zorderz/v1', '/fb-auth-start', [
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => [ $this, 'handle_fb_auth_start' ],
			'permission_callback' => [ $this, 'check_authorize_permission' ],
		] );

		// Status endpoint — stays open to any logged-in TS user so the
		// front-end can render the 'Connected (company account)' state
		// for all roles.
		register_rest_route( 'zorderz/v1', '/app-authorizations', [
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => [ $this, 'handle_app_authorizations' ],
			'permission_callback' => [ $this, 'check_authorize_permission' ],
		] );

		// v2.13.0: Nutshell is now COMPANY-WIDE. Only administrators may
		// set/update the company credentials. Regular TS users just inherit
		// the connection — they never hit this endpoint.
		register_rest_route( 'zorderz/v1', '/ns-auth-save', [
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'handle_ns_auth_save' ],
			'permission_callback' => [ $this, 'check_authorize_admin_permission' ],
			'args'                => [
				'email'   => [ 'required' => true, 'sanitize_callback' => 'sanitize_email'      ],
				'api_key' => [ 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ],
			],
		] );

		// ──────────────────────────────────────────────────────────────
		// v2.13.0: purely additive endpoints.
		// Do NOT add args or behavior to /kpi-metrics here — that
		// endpoint belongs to class-zdz-kpi-metrics.php and we're
		// deliberately leaving it alone for this release.
		// ──────────────────────────────────────────────────────────────
		register_rest_route( 'zorderz/v1', '/user-goals', [
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => [ $this, 'handle_user_goals' ],
			'permission_callback' => [ $this, 'check_authorize_permission' ],
		] );

		// v2.13.1: per-user all-time bests for the Personal Records strip.
		register_rest_route( 'zorderz/v1', '/user-records', [
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => [ $this, 'handle_user_records' ],
			'permission_callback' => [ $this, 'check_authorize_permission' ],
		] );

		// v2.20.0 r4: Dashboard action items — aggregated from all plugins.
		// Plugins register pending tasks via the zdz_dashboard_action_items filter.
		register_rest_route( 'zorderz/v1', '/dashboard-items', [
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => [ $this, 'handle_dashboard_items' ],
			'permission_callback' => [ $this, 'check_authorize_permission' ],
		] );

		// v2.20.0 r4: User profile data (phone, email name, territories, etc.)
		register_rest_route( 'zorderz/v1', '/user-profile', [
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => [ $this, 'handle_user_profile' ],
			'permission_callback' => [ $this, 'check_authorize_permission' ],
		] );

		// v2.21.5: Contact lookup — powers the dashboard "ask" field's inline
		// contact card. Proxies ZDZ_Contact_Bridge::lookup_for_tsa() so the result
		// renders in-place on the dashboard (no Poe round-trip, no chat switch).
		// All disclosure (kiosk = name+city) and scope (relationship / shared-job)
		// are enforced inside the bridge against the CURRENT user — never the model.
		register_rest_route( 'zorderz/v1', '/contact-lookup', [
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => [ $this, 'handle_contact_lookup' ],
			'permission_callback' => [ $this, 'check_authorize_permission' ],
			'args'                => array(
				'query' => array(
					'required'          => true,
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
		] );

		// v2.22.0: Orchestrate — the dashboard "ask" field's deterministic, Poe-free
		// intent classifier. Returns either inline read-verb data (contact / document
		// lookup, resolved via the capability bridges) or route:'chat' to hand the
		// query to the full Brain Bot. Replaces the brittle JS-only intent detection
		// with one server-authoritative classifier.
		register_rest_route( 'zorderz/v1', '/orchestrate', [
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => [ $this, 'handle_orchestrate' ],
			'permission_callback' => [ $this, 'check_authorize_permission' ],
			'args'                => array(
				'query' => array(
					'required'          => true,
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
		] );

		// v2.20.2: Field Preferences — save per-salesperson notation and estimation habits
		register_rest_route( 'zorderz/v1', '/user-profile/field-preferences', [
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'handle_field_preferences_save' ],
			'permission_callback' => [ $this, 'check_authorize_permission' ],
		] );

		// v2.20.2: Field Preferences schema — single source of truth for the front-end form
		register_rest_route( 'zorderz/v1', '/user-profile/field-preferences-schema', [
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => [ $this, 'handle_field_preferences_schema' ],
			'permission_callback' => [ $this, 'check_authorize_permission' ],
		] );

		register_rest_route( 'zorderz/v1', '/user-prefs', [
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'handle_user_prefs_get' ],
				'permission_callback' => [ $this, 'check_authorize_permission' ],
			),
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'handle_user_prefs_save' ],
				'permission_callback' => [ $this, 'check_authorize_permission' ],
				'args'                => array(
					'card_order'   => array( 'required' => false ),
					'widget_order' => array( 'required' => false ),
					'app_order'    => array( 'required' => false ),
					'card_scope'   => array( 'required' => false ),
					'global_range' => array( 'required' => false ),
					'reset'        => array( 'required' => false, 'sanitize_callback' => 'rest_sanitize_boolean' ),
				),
			),
		] );

		// v2.14.5: Review Bridge — check whether a customer left a review
		// on the marketing site. Proxies through ZDZ_Core_ReviewBridge so the
		// frontend never touches the remote API key.
		register_rest_route( 'zorderz/v1', '/review-check', [
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => [ $this, 'handle_review_check' ],
			'permission_callback' => [ $this, 'check_authorize_permission' ],
			'args'                => [
				'email' => [
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_email',
					'validate_callback' => function ( $v ) { return is_email( $v ); },
				],
				'name' => [
					'required'          => false,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
					'default'           => '',
				],
				'bypass_cache' => [
					'required'          => false,
					'default'           => '0',
					'sanitize_callback' => 'sanitize_text_field',
				],
			],
		] );
	}

	/**
	 * v2.11.0: Permission for front-end authorize endpoints.
	 * Admins always pass; everyone else needs the Zorderz access cap.
	 */
	public function check_authorize_permission() {
		if ( ! is_user_logged_in() ) return false;
		if ( current_user_can( 'manage_options' ) ) return true;
		if ( current_user_can( 'zdz_access_app' ) ) return true;
		return false;
	}

	/**
	 * v2.13.0: Admin-only permission for mutating company-wide credentials
	 * (Nutshell is shared across the whole Zorderz workspace).
	 */
	public function check_authorize_admin_permission() {
		if ( ! is_user_logged_in() ) return false;
		return current_user_can( 'manage_options' );
	}

	/**
	 * v2.11.0: Return the FreshBooks OAuth authorize URL for the current user.
	 * Uses TSEC_Admin (the canonical credential owner) to build the URL with
	 * the correct scopes + state token. Returns 400 if FB credentials aren't
	 * configured yet.
	 */
	public function handle_fb_auth_start( WP_REST_Request $request ) {
		if ( ! class_exists( 'TSEC_Admin' ) || ! class_exists( 'TSEC_FreshBooks' ) ) {
			return new WP_Error( 'plugin_missing', 'TS Est Maker plugin is not active.', [ 'status' => 503 ] );
		}

		$admin = new TSEC_Admin();
		$client_id     = $admin->get_fb_client_id();
		$client_secret = $admin->get_fb_client_secret();

		if ( empty( $client_id ) || empty( $client_secret ) ) {
			return new WP_Error(
				'not_configured',
				'FreshBooks credentials are not yet configured. Please ask an administrator to paste the Client ID and Client Secret in wp-admin → TS Est Maker → Settings.',
				[ 'status' => 400 ]
			);
		}

		$fb = new TSEC_FreshBooks( $client_id, $client_secret, '', '', '' );

		$ref = new \ReflectionClass( $admin );
		$m   = $ref->getMethod( 'build_oauth_state' );
		$m->setAccessible( true );
		$state = $m->invoke( $admin );

		$redirect_uri = $admin->get_fb_redirect_uri();
		$auth_url     = $fb->get_auth_url( $redirect_uri, $state );

		// v2.14.0 / v2.15.0: Mark this user as having started the OAuth
		// flow from the front-end so the theme's wp_redirect filter can
		// bounce them back home instead of leaving them stranded on
		// wp-admin after callback. (v2.15.0 reads this transient via
		// location-based detection — see functions.php::zdz_frontend_oauth_bounce.)
		set_transient( 'zdz_fb_auth_origin_' . get_current_user_id(), 'frontend', 10 * MINUTE_IN_SECONDS );

		return rest_ensure_response( [
			'success' => true,
			'data'    => [
				'auth_url'     => $auth_url,
				'redirect_uri' => $redirect_uri,
			],
		] );
	}

	/**
	 * v2.11.0 / v2.13.0: Report connection status for every integration
	 * the Settings view cares about. Nutshell is reported as a company-wide
	 * flag (no per-user state).
	 */
	public function handle_app_authorizations( WP_REST_Request $request ) {
		$fb_connected  = false;
		$ns_connected  = false;
		$poe_connected = false;
		$fb_configured = false;
		$ns_email      = '';

		if ( class_exists( 'TSEC_Admin' ) ) {
			$admin         = new TSEC_Admin();
			$fb_connected  = ! empty( $admin->get_fb_access_token() );
			$fb_configured = ! empty( $admin->get_fb_client_id() ) && ! empty( $admin->get_fb_client_secret() );
			$ns_email      = (string) $admin->get_ns_email();
			$ns_connected  = ! empty( $admin->get_ns_api_key() ) && ! empty( $ns_email );
			$poe_connected = ! empty( $admin->get_poe_api_key() );
		}

		$data = [
			'freshbooks' => [
				'connected'  => $fb_connected,
				'configured' => $fb_configured,
			],
			'nutshell'   => [
				'connected' => $ns_connected,
				'email'     => $ns_email,
				'scope'     => 'company', // v2.13.0 — informational, for the UI
			],
			'poe'        => [ 'connected' => $poe_connected ],
		];

		/**
		 * v1.1.0 — let plugins add their own authorization entries to the
		 * Settings -> App Authorizations section (rendered by app.js
		 * renderAppAuthorizations()). A scheduler plugin uses this to surface a
		 * per-user Connected Calendars connect/status card. Filter receives the
		 * data array + the current user id; additive and backward-compatible
		 * (the card renders only when a plugin registers an entry).
		 *
		 * @param array $data    The integration-status payload.
		 * @param int   $user_id The current user id.
		 */
		$data = (array) apply_filters( 'zdz_app_authorizations', $data, get_current_user_id() );

		return rest_ensure_response( [
			'success' => true,
			'data'    => $data,
		] );
	}

	/**
	 * v2.13.0: Save company-wide Nutshell credentials. Admin-gated by the
	 * route's permission_callback, so this method does not have to re-check
	 * — but the logic is identical to the previous per-user flow: encrypt
	 * the API key with TSEC_Admin::encrypt() and cascade to sibling TS
	 * plugins so Estimates / Surveys / Leads / Analytics all pick it up.
	 */
	public function handle_ns_auth_save( WP_REST_Request $request ) {
		if ( ! class_exists( 'TSEC_Admin' ) ) {
			return new WP_Error( 'plugin_missing', 'TS Est Maker plugin is not active.', [ 'status' => 503 ] );
		}

		$email   = sanitize_email( (string) $request->get_param( 'email' ) );
		$api_key = trim( (string) $request->get_param( 'api_key' ) );

		if ( empty( $email ) || ! is_email( $email ) ) {
			return new WP_Error( 'invalid_email', 'Please enter a valid Nutshell login email.', [ 'status' => 400 ] );
		}
		if ( empty( $api_key ) ) {
			return new WP_Error( 'invalid_key', 'Please enter your Nutshell API key.', [ 'status' => 400 ] );
		}
		if ( strlen( $api_key ) < 16 ) {
			return new WP_Error( 'short_key', 'That API key looks too short. Double-check the value from Nutshell → Setup → API Keys.', [ 'status' => 400 ] );
		}

		$admin     = new TSEC_Admin();
		$encrypted = $admin->encrypt( $api_key );

		// Primary TSEC options
		update_option( 'tsec_ns_email',   $email );
		update_option( 'tsec_ns_api_key', $encrypted );

		// Sibling cascade — keeps the ecosystem in sync (same logic used by
		// TSEC_Admin::sanitize_encrypted_field fallback path).
		foreach ( [ 'tsl_', 'tsa_', 'zdz_surveys_' ] as $prefix ) {
			update_option( $prefix . 'ns_email',   $email );
			update_option( $prefix . 'ns_api_key', $encrypted );
		}

		return rest_ensure_response( [
			'success' => true,
			'data'    => [
				'connected' => true,
				'email'     => $email,
			],
		] );
	}

	public function check_permission() {
		return is_user_logged_in();
	}

	public function handle_load_app( WP_REST_Request $request ) {
		$app_id = $request->get_param( 'app_id' );
		$user_id = get_current_user_id();

		$user = get_userdata( $user_id );

		// ── Safe Mode: user sees NO apps ──
		$safe_mode = get_user_meta( $user_id, 'zdz_safe_mode', true );
		if ( $safe_mode ) {
			return new WP_Error( 'forbidden', 'Safe Mode is active.', [ 'status' => 403 ] );
		}

		// ── Denied apps: always blocked, even for admins/owners ──
		$denied_apps = get_user_meta( $user_id, 'zdz_denied_apps', true );
		if ( is_array( $denied_apps ) && in_array( $app_id, $denied_apps, true ) ) {
			return new WP_Error( 'forbidden', 'Access denied.', [ 'status' => 403 ] );
		}

		// ── Use centralised role helper for admin check ──
		$is_admin = class_exists( 'ZDZ_User_Roles' )
			? ZDZ_User_Roles::is_admin_role( $user->roles[0] ?? '' )
			: ( in_array( 'administrator', (array) $user->roles, true ) || in_array( 'zdz_admin', (array) $user->roles, true ) || in_array( 'zdz_owner', (array) $user->roles, true ) );
		$allowed = get_user_meta( $user_id, 'zdz_allowed_apps', true );

		if ( ! $is_admin && ( ! is_array( $allowed ) || ! in_array( $app_id, $allowed, true ) ) ) {
			return new WP_Error( 'forbidden', 'Access denied.', [ 'status' => 403 ] );
		}

		$apps = ZDZ_Plugin_API::get_instance()->get_all_apps();
		if ( ! isset( $apps[ $app_id ] ) ) {
			return new WP_Error( 'not_found', 'App not found.', [ 'status' => 404 ] );
		}

		ob_start();
		$apps[ $app_id ]->render_mobile_view( $user_id );
		$html = ob_get_clean();

		return rest_ensure_response( [ 'success' => true, 'data' => [ 'html' => $html ] ] );
	}

	public function handle_user_apps( WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		$configs = ZDZ_Plugin_API::get_instance()->get_user_app_configs( $user_id );
		return rest_ensure_response( [ 'success' => true, 'data' => $configs ] );
	}

	/* ================================================================
	 *  v2.13.0 — Purely additive endpoints
	 *  These do not affect /kpi-metrics or any existing route.
	 * ================================================================ */

	public function handle_user_goals( WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		$goals   = class_exists( 'ZDZ_User_Goals' ) ? ZDZ_User_Goals::get_goals_for_user( $user_id ) : array();

		$with_progress = array();
		foreach ( $goals as $g ) {
			$with_progress[] = array(
				'goal'     => $g,
				'progress' => class_exists( 'ZDZ_User_Goals' )
					? ZDZ_User_Goals::calc_goal_progress(
						0.0, // actual is joined client-side from whatever metric source
						(float) $g['target_value'],
						$g['period_type'],
						$g['period_start']
					  )
					: array(),
			);
		}

		return rest_ensure_response( array(
			'success' => true,
			'data'    => array(
				'goals'         => $with_progress,
				'cache_version' => class_exists( 'ZDZ_User_Goals' ) ? ZDZ_User_Goals::cache_version() : 0,
			),
		) );
	}

	public function handle_user_records( WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		$records = class_exists( 'ZDZ_Personal_Records' )
			? ZDZ_Personal_Records::get_records_for_user( $user_id )
			: array();
		return rest_ensure_response( array(
			'success' => true,
			'data'    => array(
				'records' => $records,
			),
		) );
	}

	public function handle_user_prefs_get( WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		return rest_ensure_response( array(
			'success' => true,
			'data'    => array(
				'card_order'   => get_user_meta( $user_id, 'zdz_dash_card_order', true ) ?: array(),
				'widget_order' => get_user_meta( $user_id, 'zdz_dash_widget_order', true ) ?: array(),
				'app_order'    => get_user_meta( $user_id, 'zdz_app_order', true ) ?: array(),
				'card_scope'   => get_user_meta( $user_id, 'zdz_dash_card_scope', true ) ?: array(),
				'global_range' => get_user_meta( $user_id, 'zdz_dash_global_range', true ) ?: 'month',
			),
		) );
	}

	public function handle_user_prefs_save( WP_REST_Request $request ) {
		$user_id = get_current_user_id();

		if ( $request->get_param( 'reset' ) ) {
			delete_user_meta( $user_id, 'zdz_dash_card_order' );
			delete_user_meta( $user_id, 'zdz_dash_widget_order' );
			delete_user_meta( $user_id, 'zdz_app_order' );
			delete_user_meta( $user_id, 'zdz_dash_card_scope' );
			delete_user_meta( $user_id, 'zdz_dash_global_range' );
			return rest_ensure_response( array( 'success' => true, 'reset' => true ) );
		}

		// v2.17.2: App dock/sticky bar order — persists across devices.
		$app_order = $request->get_param( 'app_order' );
		if ( is_array( $app_order ) ) {
			$clean = array_values( array_filter( array_map( function( $id ) {
				return preg_match( '/^[a-z0-9_\-]{1,64}$/', (string) $id ) ? (string) $id : null;
			}, $app_order ) ) );
			update_user_meta( $user_id, 'zdz_app_order', $clean );
		}

		$card_order = $request->get_param( 'card_order' );
		if ( is_array( $card_order ) ) {
			$clean = array_values( array_filter( array_map( function( $id ) {
				return preg_match( '/^[a-z0-9_\-]{1,64}$/', (string) $id ) ? (string) $id : null;
			}, $card_order ) ) );
			update_user_meta( $user_id, 'zdz_dash_card_order', $clean );
		}

		// v2.14.3.1: Widget order — same sanitization pattern as card_order.
		$widget_order = $request->get_param( 'widget_order' );
		if ( is_array( $widget_order ) ) {
			$clean = array_values( array_filter( array_map( function( $id ) {
				return preg_match( '/^[a-z0-9_\-]{1,64}$/', (string) $id ) ? (string) $id : null;
			}, $widget_order ) ) );
			update_user_meta( $user_id, 'zdz_dash_widget_order', $clean );
		}

		$card_scope = $request->get_param( 'card_scope' );
		if ( is_array( $card_scope ) ) {
			$clean = array();
			foreach ( $card_scope as $k => $v ) {
				$key   = (string) $k;
				$scope = (string) $v;
				if ( preg_match( '/^[a-z0-9_\-]{1,64}$/', $key ) && in_array( $scope, array( 'me', 'team', 'company' ), true ) ) {
					$clean[ $key ] = $scope;
				}
			}
			update_user_meta( $user_id, 'zdz_dash_card_scope', $clean );
		}

		$range = $request->get_param( 'global_range' );
		if ( is_string( $range ) && in_array( $range, array( 'today', 'week', 'month', 'quarter', 'year', 'all', 'custom' ), true ) ) {
			update_user_meta( $user_id, 'zdz_dash_global_range', $range );
		}

		return rest_ensure_response( array( 'success' => true ) );
	}

	/* ------------------------------------------------------------------ */
	/*  v2.14.5: Review Bridge Proxy                                       */
	/* ------------------------------------------------------------------ */

	/**
	 * Proxy a review check through the TS Review Bridge.
	 *
	 * This keeps the bridge API key server-side — the frontend JS only
	 * needs the logged-in user's WP REST nonce, not the bridge secret.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public function handle_review_check( WP_REST_Request $request ): WP_REST_Response {
		$bridge = new ZDZ_Core_ReviewBridge();

		if ( ! $bridge->is_configured() ) {
			return new WP_REST_Response( [
				'success' => false,
				'error'   => 'Review Bridge is not configured. Add the URL and API key in Zorderz Core Settings.',
			], 503 );
		}

		$email        = $request->get_param( 'email' );
		$name         = $request->get_param( 'name' );
		$bypass_cache = $request->get_param( 'bypass_cache' ) === '1';

		$result = $bridge->check_review( $email, $name, $bypass_cache );

		if ( null === $result ) {
			return new WP_REST_Response( [
				'success' => false,
				'error'   => 'Review Bridge request failed. Check server logs for details.',
			], 502 );
		}

		return rest_ensure_response( [
			'success' => true,
			'data'    => $result,
		] );
	}

	/**
	 * GET /zorderz/v1/dashboard-items
	 *
	 * v2.20.0 r4: Returns aggregated action items from all plugins.
	 * Plugins hook into the zdz_dashboard_action_items filter to register
	 * their pending tasks. Each item should be an array with:
	 *   - plugin:  (string) Plugin ID (e.g. 'zdz-sales-leads')
	 *   - label:   (string) Human-readable summary (e.g. '50 leads pending contact')
	 *   - count:   (int)    Number of pending items
	 *   - app_id:  (string) App to open when tapped (e.g. 'sales-leads')
	 *   - icon:    (string) Lucide icon name (e.g. 'target')
	 *   - color:   (string) Icon color (e.g. '#22C55E')
	 *   - urgency: (string) 'low' | 'medium' | 'high'
	 */
	public function handle_dashboard_items( WP_REST_Request $request ): WP_REST_Response {
		$user_id = get_current_user_id();

		/**
		 * Filter: zdz_dashboard_action_items
		 *
		 * Plugins use this to register pending tasks for the current user's
		 * dashboard. Return an array of item arrays.
		 *
		 * @param array $items   Current items (empty initially).
		 * @param int   $user_id The current user's ID.
		 * @return array
		 */
		$items = apply_filters( 'zdz_dashboard_action_items', [], $user_id );

		// Sanitize and validate items
		$clean_items = [];
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) || empty( $item['label'] ) ) {
				continue;
			}
			$clean_items[] = [
				'plugin'  => sanitize_text_field( $item['plugin'] ?? '' ),
				'label'   => sanitize_text_field( $item['label'] ),
				'count'   => absint( $item['count'] ?? 0 ),
				'app_id'  => sanitize_text_field( $item['app_id'] ?? '' ),
				'icon'    => sanitize_text_field( $item['icon'] ?? 'circle' ),
				'color'   => sanitize_hex_color( $item['color'] ?? '#6B7280' ) ?: '#6B7280',
				'urgency' => in_array( $item['urgency'] ?? '', [ 'low', 'medium', 'high' ], true )
					? $item['urgency'] : 'low',
			];
		}

		return rest_ensure_response( [
			'success' => true,
			'items'   => $clean_items,
		] );
	}

	/**
	 * GET /zorderz/v1/contact-lookup?query=<name>
	 *
	 * v2.21.5: Backs the dashboard "ask" field's inline contact card. Calls the
	 * shared ZDZ_Contact_Bridge with the CURRENT user's context; the bridge does
	 * all disclosure (kiosk = name+city) and scope (relationship / shared-job)
	 * enforcement server-side. Returns the bridge's structured result verbatim,
	 * plus a small `render` hint the frontend uses to decide card vs. handoff.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public function handle_contact_lookup( WP_REST_Request $request ): WP_REST_Response {
		$query = trim( (string) $request->get_param( 'query' ) );

		if ( $query === '' ) {
			return rest_ensure_response( [
				'success'       => true,
				'needs_clarify' => true,
				'message'       => 'Which customer should I look up?',
			] );
		}

		if ( ! class_exists( 'ZDZ_Contact_Bridge' ) || ! ZDZ_Contact_Bridge::is_available() ) {
			return rest_ensure_response( [
				'success' => false,
				'error'   => 'Contact lookup is unavailable (CRM and FreshBooks both inactive).',
			] );
		}

		$result = ZDZ_Contact_Bridge::lookup_for_tsa( [
			'query'              => $query,
			'tier'               => '',                       // bridge resolves tier from the user
			'is_kiosk'           => false,                    // bridge re-derives kiosk most-restrictive-wins
			'requesting_user_id' => get_current_user_id(),
		] );

		// `render` hint: a clean single contact with details is card-friendly;
		// a denial/clarify/empty is a short message; anything else suggests the
		// full chat. The frontend uses this to choose card vs. "Open in chat →".
		$contact = is_array( $result['contact'] ?? null ) ? $result['contact'] : [];
		$has_detail = ! empty( $contact['phone'] ) || ! empty( $contact['email'] ) || ! empty( $contact['address'] );
		if ( ! empty( $result['error'] ) ) {
			$result['render'] = 'error';
		} elseif ( ! empty( $result['needs_clarify'] ) ) {
			$result['render'] = 'clarify';
		} elseif ( ! empty( $result['denied'] ) ) {
			$result['render'] = 'denied';
		} elseif ( $has_detail ) {
			$result['render'] = 'card';
		} else {
			$result['render'] = 'message';
		}

		return rest_ensure_response( $result );
	}

	/**
	 * GET /zorderz/v1/orchestrate?query=<text>
	 *
	 * v2.22.0: Deterministic, Poe-free intent classification for the dashboard
	 * "ask" field. Delegates to ZDZ_Orchestrator::classify(), which detects the
	 * read verbs we can answer in place (contact / document lookup) and resolves
	 * them through the capability bridges, or returns route:'chat' for everything
	 * else. The dashboard renders inline cards for 'inline' routes and opens the
	 * full Brain Bot chat for 'chat'.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public function handle_orchestrate( WP_REST_Request $request ): WP_REST_Response {
		$query = trim( (string) $request->get_param( 'query' ) );

		if ( $query === '' || ! class_exists( 'ZDZ_Orchestrator' ) ) {
			return rest_ensure_response( array( 'verb' => 'chat', 'route' => 'chat' ) );
		}

		$out = ZDZ_Orchestrator::classify( $query, get_current_user_id() );
		return rest_ensure_response( $out );
	}

	/**
	 * GET /zorderz/v1/user-profile
	 *
	 * v2.20.0 r4: Returns the current user's extended profile fields
	 * for cross-plugin use (phone, email name, territories, etc.).
	 */
	public function handle_user_profile( WP_REST_Request $request ): WP_REST_Response {
		$user_id = get_current_user_id();
		$user    = get_userdata( $user_id );

		if ( ! $user ) {
			return new WP_REST_Response( [ 'success' => false, 'error' => 'Not authenticated' ], 401 );
		}

		$profile = [
			'user_id'            => $user_id,
			'display_name'       => $user->display_name,
			'email'              => $user->user_email,
			'role'               => $user->roles[0] ?? 'subscriber',
			'salesperson_code'   => ZDZ_Core_Settings::get_salesperson_code( $user_id ),
			'salesperson_initials' => get_user_meta( $user_id, 'zdz_salesperson_initials', true ) ?: '',
			'phone'              => ZDZ_Core_Settings::get_user_phone( $user_id ),
			'email_name'         => ZDZ_Core_Settings::get_user_email_name( $user_id ),
			'territories'        => ZDZ_Core_Settings::get_user_territories( $user_id ),
			'company_phone'      => ZDZ_Core_Settings::get_company_phone(),
			'office_hours'       => ZDZ_Core_Settings::get_receptionist_hours(),
			'field_preferences'  => json_decode( get_user_meta( $user_id, 'zdz_field_preferences', true ) ?: '{}', true ),
			// v2.20.2: Expose TSEC notation profile (read-only) for front-end migration offer
			'tsec_notation_profile' => json_decode( get_user_meta( $user_id, 'tsec_notation_profile', true ) ?: '{}', true ),
		];

		return rest_ensure_response( [
			'success' => true,
			'profile' => $profile,
		] );
	}

	/**
	 * POST /zorderz/v1/user-profile/field-preferences
	 *
	 * v2.20.2: Save the current user's field preferences JSON blob.
	 * Validates against the canonical schema from ZDZ_Core_Settings, sanitizes
	 * each field, and bumps the zdz_field_prefs_version counter so consuming
	 * plugins (TSA, TSEC) can cache-bust.
	 */
	public function handle_field_preferences_save( WP_REST_Request $request ): WP_REST_Response {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return new WP_REST_Response( [ 'success' => false, 'error' => 'Not authenticated' ], 401 );
		}

		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			return new WP_REST_Response( [ 'success' => false, 'error' => 'Request body must be a JSON object' ], 400 );
		}

		// Read the canonical schema — single source of truth
		$schema = ZDZ_Core_Settings::get_field_preferences_schema();

		// Reject any keys not in the schema
		$extra_keys = array_diff( array_keys( $body ), array_keys( $schema ) );
		if ( ! empty( $extra_keys ) ) {
			return new WP_REST_Response( [
				'success' => false,
				'error'   => 'Unknown fields: ' . implode( ', ', $extra_keys ),
			], 400 );
		}

		$sanitized = [];
		foreach ( $schema as $key => $def ) {
			if ( ! isset( $body[ $key ] ) ) {
				continue;
			}

			$type = $def['type'];

			if ( 'string_array' === $type ) {
				if ( ! is_array( $body[ $key ] ) ) {
					return new WP_REST_Response( [
						'success' => false,
						'error'   => "Field '{$key}' must be an array of strings",
					], 400 );
				}
				$sanitized[ $key ] = array_values( array_filter(
					array_map( 'sanitize_textarea_field', $body[ $key ] ),
					function ( $v ) { return '' !== $v; }
				) );
			} else {
				if ( ! is_string( $body[ $key ] ) ) {
					return new WP_REST_Response( [
						'success' => false,
						'error'   => "Field '{$key}' must be a string",
					], 400 );
				}
				$val = sanitize_textarea_field( $body[ $key ] );
				if ( '' !== $val ) {
					$sanitized[ $key ] = $val;
				}
			}
		}

		$json = wp_json_encode( $sanitized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		if ( false === $json ) {
			return new WP_REST_Response( [ 'success' => false, 'error' => 'Failed to encode preferences' ], 500 );
		}

		update_user_meta( $user_id, 'zdz_field_preferences', $json );

		// Bump version counter so TSA/TSEC can cache-bust
		$version = (int) get_option( 'zdz_field_prefs_version', 0 );
		update_option( 'zdz_field_prefs_version', $version + 1, false );

		return rest_ensure_response( [
			'success'          => true,
			'field_preferences' => $sanitized,
		] );
	}

	/**
	 * GET /zorderz/v1/user-profile/field-preferences-schema
	 *
	 * v2.20.2: Returns the canonical field preferences schema so the front-end
	 * can render the form dynamically. Adding a field to
	 * ZDZ_Core_Settings::get_field_preferences_schema() automatically makes it
	 * appear in the Settings UI, validate on save, and render in AI prompts —
	 * no JS or plugin changes required.
	 */
	public function handle_field_preferences_schema( WP_REST_Request $request ): WP_REST_Response {
		$schema = ZDZ_Core_Settings::get_field_preferences_schema();

		// Reshape for the front-end: array of {key, type, label, hint}
		$fields = [];
		foreach ( $schema as $key => $def ) {
			$fields[] = [
				'key'   => $key,
				'type'  => $def['type'],
				'label' => $def['label'],
				'hint'  => $def['hint'],
			];
		}

		return rest_ensure_response( [
			'success' => true,
			'fields'  => $fields,
		] );
	}
}