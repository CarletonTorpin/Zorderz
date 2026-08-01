<?php
/**
 * ZIM_REST
 *
 * Registers the REST endpoints that other Zorderz ecosystem plugins
 * need to integrate with messaging. These are *read-only* surfaces for
 * cross-plugin UI coordination — NOT a public messaging API for
 * third-party consumers. The endpoints expose only what the current
 * user could already see in the widget.
 *
 * Routes (all under the `tsim/v1` namespace):
 *
 *   GET /wp-json/tsim/v1/unread-total
 *     Returns this user's total unread count across all conversations:
 *        { unread: <int>, by_conversation: [ { id, unread }, ... ] }
 *     Cap-gated on `zdz_access_app`, customer-facing-hidden blocks with 404.
 *     Used by zdz-sales-analytics v1.12.3+ to drive the "💬 Team" unread badge.
 *
 * Why REST and not admin-ajax.php:
 *   the analytics app's embed lives on a different page than messaging's widget, so
 *   `zimData.nonce` (the admin-ajax nonce) isn't in scope. REST uses
 *   the standard `X-WP-Nonce` cookie-bound nonce, which TSA already has
 *   (via `wpApiSettings` from wp-api core or its own localized rest nonce).
 *
 * @since 1.0.2
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZIM_REST {

	const NS = 'tsim/v1';

	public static function register_routes() {
		register_rest_route( self::NS, '/unread-total', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'get_unread_total' ),
			'permission_callback' => array( __CLASS__, 'permission_check' ),
		) );

		// v1.0.3 — resolve a WordPress login to a teammate record.
		// Returns 404 for unknown logins, or users lacking zdz_access_app
		// (so typos and outsiders don't leak membership information).
		register_rest_route( self::NS, '/user-by-login', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'get_user_by_login' ),
			'permission_callback' => array( __CLASS__, 'permission_check' ),
			'args' => array(
				'login' => array(
					'required' => true,
					'validate_callback' => function ( $v ) {
						// user_login validation is permissive; we enforce the
						// `@login` charset we documented elsewhere.
						return is_string( $v ) && (bool) preg_match( '/^[a-z0-9._\-]{1,60}$/i', $v );
					},
					'sanitize_callback' => function ( $v ) {
						return strtolower( (string) $v );
					},
				),
			),
		) );

		// v1.0.20: List channels the current user belongs to.
		// Used by TSA and TSKV for "share to channel" pickers.
		register_rest_route( self::NS, '/channels', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'get_channels' ),
			'permission_callback' => array( __CLASS__, 'permission_check' ),
		) );

		// v1.0.20: Post a message to a channel on behalf of the current user.
		// Used by TSA Brain Bot "post to #channel" feature.
		// Requires: channel_slug (string) + body (string).
		// The message is posted AS the current logged-in user (not a bot).
		register_rest_route( self::NS, '/post', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'post_message' ),
			'permission_callback' => array( __CLASS__, 'permission_check' ),
			'args' => array(
				'channel_slug' => array( 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
				'body'         => array( 'required' => true, 'sanitize_callback' => 'wp_kses_post' ),
			),
		) );
	}

	public static function permission_check() {
		return is_user_logged_in() && zim_user_has_access();
	}

	/**
	 * Sum unread counts across the user's conversations.
	 *
	 * Delegates to the same list_for_user() calls the sidebar poll uses,
	 * so the number here always matches the sum of sidebar badges. That
	 * consistency matters — if the analytics app's embed says "5" and messaging's own
	 * sidebar also says "5", the user isn't left wondering which is stale.
	 */
	public static function get_unread_total( WP_REST_Request $req ) {
		// Customer-facing hard-block (Trap 5). Same 404-not-403 behavior as
		// the admin-ajax gates: never leak existence to a screen a customer
		// might be watching.
		if ( ! ZIM_Widget::should_render() ) {
			return new WP_Error( 'zim_unavailable', 'Not available.', array( 'status' => 404 ) );
		}

		$user_id = get_current_user_id();
		$channels = ZIM_Channels::list_for_user( $user_id );
		$dms      = ZIM_DMs::list_for_user( $user_id );

		$total = 0;
		$by_conv = array();
		foreach ( $channels as $c ) {
			$u = (int) ( $c['unread'] ?? 0 );
			$total += $u;
			if ( $u > 0 ) {
				$by_conv[] = array( 'id' => (int) $c['id'], 'unread' => $u );
			}
		}
		foreach ( $dms as $d ) {
			$u = (int) ( $d['unread'] ?? 0 );
			$total += $u;
			if ( $u > 0 ) {
				$by_conv[] = array( 'id' => (int) $d['id'], 'unread' => $u );
			}
		}

		return rest_ensure_response( array(
			'unread'          => $total,
			'by_conversation' => $by_conv,
		) );
	}

	/**
	 * Resolve a login → teammate record for the @-mention router in the analytics app's
	 * embed. Returns 404 for unknown or non-teammate users so the caller
	 * can fall back cleanly (in the analytics app's case, restore the input and hint).
	 */
	public static function get_user_by_login( WP_REST_Request $req ) {
		if ( ! ZIM_Widget::should_render() ) {
			return new WP_Error( 'zim_unavailable', 'Not available.', array( 'status' => 404 ) );
		}
		$login = (string) $req['login'];
		$user  = get_user_by( 'login', $login );
		if ( ! $user || ! zim_user_has_access( $user->ID ) ) {
			return new WP_Error( 'zim_not_teammate', 'Not a teammate.', array( 'status' => 404 ) );
		}
		// Don't let users resolve themselves — makes no sense to DM yourself.
		if ( (int) $user->ID === get_current_user_id() ) {
			return new WP_Error( 'zim_self', "You can't DM yourself.", array( 'status' => 404 ) );
		}
		return rest_ensure_response( array(
			'user_id' => (int) $user->ID,
			'login'   => (string) $user->user_login,
			'name'    => (string) $user->display_name,
		) );
	}

	/**
	 * v1.0.20: List channels the current user belongs to.
	 * Returns slug, name, and whether it's the announcements channel.
	 */
	public static function get_channels( WP_REST_Request $req ) {
		if ( ! ZIM_Widget::should_render() ) {
			return new WP_Error( 'zim_unavailable', 'Not available.', array( 'status' => 404 ) );
		}
		$channels = ZIM_Channels::list_for_user( get_current_user_id() );
		$out = array();
		foreach ( $channels as $c ) {
			$out[] = array(
				'id'               => (int) $c['id'],
				'slug'             => $c['slug'],
				'name'             => $c['name'],
				'is_announcements' => (int) $c['is_announcements'],
			);
		}
		return rest_ensure_response( array( 'success' => true, 'channels' => $out ) );
	}

	/**
	 * v1.0.20: Post a message to a channel as the current user.
	 *
	 * Cross-plugin integration point — the analytics app's Brain Bot uses this to post
	 * AI-generated content to channels when the user says "post this to #X".
	 * The message appears as FROM the user who asked Brain Bot, not from a bot.
	 *
	 * Security: same permission stack as the AJAX post handler —
	 * logged-in + zdz_access_app + membership + customer-facing gate.
	 * Announcements admin check delegated to ZIM_Messages::post().
	 */
	public static function post_message( WP_REST_Request $req ) {
		if ( ! ZIM_Widget::should_render() ) {
			return new WP_Error( 'zim_unavailable', 'Not available.', array( 'status' => 404 ) );
		}

		// v1.0.24 — Read-only roles (the shared kiosk `zdz_general`) cannot post.
		// This route is the cross-plugin "post to #channel" surface Brain Bot
		// uses; blocking it here gives a clean 403 and makes the lockdown
		// explicit at the integration boundary. ZIM_Messages::post() refuses
		// read-only authors too, so this is belt-and-suspenders.
		if ( function_exists( 'zim_user_can_write' ) && ! zim_user_can_write() ) {
			return new WP_Error(
				'zim_read_only',
				'This account has read-only messaging access and cannot post.',
				array( 'status' => 403 )
			);
		}

		$slug = sanitize_text_field( $req->get_param( 'channel_slug' ) );
		$body = wp_kses_post( $req->get_param( 'body' ) );
		$user_id = get_current_user_id();

		if ( empty( $slug ) || empty( trim( $body ) ) ) {
			return new WP_Error( 'zim_bad_request', 'channel_slug and body are required.', array( 'status' => 400 ) );
		}

		// Resolve channel slug to conversation.
		$channel = ZIM_Channels::get_by_slug( $slug );
		if ( ! $channel ) {
			return new WP_Error( 'zim_channel_not_found', 'Channel #' . $slug . ' not found.', array( 'status' => 404 ) );
		}

		$conv_id = (int) $channel->id;

		// Verify the posting user is a member.
		if ( ! ZIM_Membership::is_member( $user_id, $conv_id ) ) {
			return new WP_Error( 'zim_not_member', 'You are not a member of #' . $slug . '.', array( 'status' => 403 ) );
		}

		// Post the message. ZIM_Messages::post() handles announcements admin check,
		// mention reconciliation, and notification dispatch.
		$result = ZIM_Messages::post( $conv_id, $user_id, $body );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( array(
			'success'    => true,
			'message_id' => (int) $result,
			'channel'    => '#' . $slug,
			'posted_by'  => wp_get_current_user()->display_name,
		) );
	}
}
