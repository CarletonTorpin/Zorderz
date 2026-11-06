<?php
/**
 * ZANA_REST — the chat surface's HTTP routes, all under ZDZ_REST_NS.
 *
 * Never types the namespace literal — every route hangs off the single ZDZ_REST_NS
 * constant, so the whole surface moves with one edit.
 *
 * Routes (logged-in + app-access gated):
 *   POST  {ns}/analytics/chat                  — run one turn SYNCHRONOUSLY (kept intact)
 *   POST  {ns}/analytics/chat/enqueue          — queue one turn, return a job id (async)
 *   GET   {ns}/analytics/turn/<id>             — poll one async turn's status/result
 *   GET   {ns}/analytics/sessions              — list this user's sessions
 *   GET   {ns}/analytics/session/<id>          — one session's messages
 *   POST  {ns}/analytics/action/<id>/confirm   — a person pressed Confirm on a held write (ZANA_Actions::confirm)
 *   POST  {ns}/analytics/action/<id>/cancel    — a person pressed Cancel: the held write goes, nothing runs
 *
 * The async pair (enqueue + turn) exists so a slow turn cannot 502 behind a managed
 * host's gateway timeout: enqueue returns immediately and a background loopback runs
 * the SAME ZANA_Chat::send() the sync route runs (see ZANA_Background). The sync
 * /chat route is unchanged and is the client's fallback whenever async is unavailable.
 *
 * Every route re-checks access server-side on the REAL user (ZDZ_Plugin_API::
 * user_can_access_app) — the model is never trusted to gate itself. The async pair
 * and the Confirm / Cancel presses use the very same permission_callback (can_access)
 * as the sync chat route; a press carries only the held row's id and its chat.
 *
 * @package Zorderz\Analytics
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZANA_REST {

	private static $booted = false;

	public static function init(): void {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes(): void {
		$ns = ZDZ_REST_NS;

		register_rest_route(
			$ns,
			'/analytics/chat',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'rest_chat' ),
				'permission_callback' => array( __CLASS__, 'can_access' ),
				'args'                => array(
					'message'    => array( 'required' => true, 'sanitize_callback' => 'sanitize_textarea_field' ),
					'session_id' => array( 'sanitize_callback' => 'absint' ),
				),
			)
		);

		// ── Async pair: enqueue a turn, then poll it. Same gate as /chat. ──
		register_rest_route(
			$ns,
			'/analytics/chat/enqueue',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'rest_chat_enqueue' ),
				'permission_callback' => array( __CLASS__, 'can_access' ),
				'args'                => array(
					'message'    => array( 'required' => true, 'sanitize_callback' => 'sanitize_textarea_field' ),
					'session_id' => array( 'sanitize_callback' => 'absint' ),
				),
			)
		);

		register_rest_route(
			$ns,
			'/analytics/turn/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'rest_turn_status' ),
				'permission_callback' => array( __CLASS__, 'can_access' ),
				'args'                => array(
					'id' => array( 'sanitize_callback' => 'absint' ),
				),
			)
		);

		register_rest_route(
			$ns,
			'/analytics/sessions',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'rest_sessions' ),
				'permission_callback' => array( __CLASS__, 'can_access' ),
			)
		);

		foreach ( array( 'confirm', 'cancel' ) as $press ) {
			register_rest_route(
				$ns,
				'/analytics/action/(?P<id>[a-f0-9]{32})/' . $press,
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'rest_action_' . $press ),
					'permission_callback' => array( __CLASS__, 'can_access' ),
					'args'                => array(
						'session_id' => array( 'required' => true, 'sanitize_callback' => 'absint' ),
					),
				)
			);
		}

		register_rest_route(
			$ns,
			'/analytics/session/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'rest_session' ),
				'permission_callback' => array( __CLASS__, 'can_access' ),
				'args'                => array(
					'id' => array( 'sanitize_callback' => 'absint' ),
				),
			)
		);
	}

	/** App-access gate on the real user. */
	public static function can_access(): bool {
		$uid = get_current_user_id();
		if ( ! $uid ) {
			return false;
		}
		if ( class_exists( 'ZDZ_Plugin_API' ) && method_exists( 'ZDZ_Plugin_API', 'user_can_access_app' ) ) {
			return ZDZ_Plugin_API::user_can_access_app( $uid, ZANA_APP_ID );
		}
		return user_can( $uid, 'zdz_access_app' ) || user_can( $uid, 'manage_options' );
	}

	public static function rest_chat( WP_REST_Request $request ) {
		$uid     = get_current_user_id();
		$message = (string) $request->get_param( 'message' );
		$session = (int) $request->get_param( 'session_id' );

		$result = ZANA_Chat::send( $uid, $session, $message );
		return rest_ensure_response( $result );
	}

	/**
	 * Enqueue an async turn and return a job id immediately. If the async runner is
	 * unavailable, return 501 so the client falls back to the sync /chat route.
	 */
	public static function rest_chat_enqueue( WP_REST_Request $request ) {
		if ( ! class_exists( 'ZANA_Background' ) ) {
			return new WP_Error( 'zana_async_unavailable', __( 'Async runner unavailable.', 'zorderz' ), array( 'status' => 501 ) );
		}
		$uid     = get_current_user_id();
		$message = (string) $request->get_param( 'message' );
		$session = (int) $request->get_param( 'session_id' );
		return rest_ensure_response( ZANA_Background::enqueue( $uid, $session, $message ) );
	}

	/** Poll one async turn — owner-scoped inside ZANA_Background::status(). */
	public static function rest_turn_status( WP_REST_Request $request ) {
		if ( ! class_exists( 'ZANA_Background' ) ) {
			return new WP_Error( 'zana_async_unavailable', __( 'Async runner unavailable.', 'zorderz' ), array( 'status' => 501 ) );
		}
		$uid = get_current_user_id();
		$id  = (int) $request->get_param( 'id' );
		return rest_ensure_response( ZANA_Background::status( $uid, $id ) );
	}

	public static function rest_sessions( WP_REST_Request $request ) {
		$uid = get_current_user_id();
		return rest_ensure_response( array( 'sessions' => ZANA_Chat::sessions( $uid ) ) );
	}

	/** A person pressed Confirm: the held write runs from its stored arguments, once. */
	public static function rest_action_confirm( WP_REST_Request $request ) {
		$uid = get_current_user_id();
		$sid = (int) $request->get_param( 'session_id' );
		$id  = strtolower( (string) $request->get_param( 'id' ) );
		$r   = ZANA_Actions::confirm( $uid, $sid, $id );
		ZANA_Chat::settle_card( $sid, $uid, $id, $r['success'] ? __( 'Confirmed', 'zorderz' ) . ': ' . $r['message'] : $r['message'] );
		return rest_ensure_response( $r );
	}

	/** A person pressed Cancel: nothing runs. */
	public static function rest_action_cancel( WP_REST_Request $request ) {
		$uid = get_current_user_id();
		$sid = (int) $request->get_param( 'session_id' );
		$id  = strtolower( (string) $request->get_param( 'id' ) );
		$ok  = ZANA_Actions::cancel( $uid, $sid, $id );
		ZANA_Chat::settle_card( $sid, $uid, $id, __( 'Cancelled. Nothing was changed.', 'zorderz' ) );
		return rest_ensure_response( array( 'success' => $ok, 'message' => $ok ? __( 'Cancelled. Nothing was changed.', 'zorderz' ) : __( 'That preview had already gone.', 'zorderz' ) ) );
	}

	public static function rest_session( WP_REST_Request $request ) {
		$uid = get_current_user_id();
		$id  = (int) $request->get_param( 'id' );
		return rest_ensure_response(
			array(
				'session_id' => $id,
				'messages'   => ZANA_Chat::session_messages( $id, $uid ),
			)
		);
	}
}
