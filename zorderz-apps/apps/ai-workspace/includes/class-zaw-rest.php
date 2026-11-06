<?php
/**
 * ZAW_REST -- the routes, under ZDZ_REST_NS . '/ai-workspace'.
 *
 *   For the person (signed in, granted, never the shared device, never in Privacy View; the REST nonce):
 *     POST turn                 { text, conv? }            start a turn                    ZAW_Turns::start
 *     GET  turn/<id>            follow it (asks the hub once; the page polls)             ZAW_Turns::advance + view
 *     POST turn/<id>/cancel     stop it                                                    ZAW_Turns::cancel
 *     GET  thread?conv=…        a conversation's turns; without conv, the conversations
 *     GET  hub                  the runner's state and the engine's month (numbers only)
 *   For an owner-level account (the route checks manage_options; ZAW_Grant::grant_self checks the rest):
 *     POST grant { on }         turn the workspace on (or off) for themselves
 *   For the hub only (no session; the single-use token or the sealed envelope is the key; anything wrong is a bare 404):
 *     GET  turn-fetch/<id>?k=…  the turn's body, once                                      ZAW_Turns::fetch
 *     POST tool                 a site tool, run as the turn's person                      ZAW_Tools::rest_tool
 *
 * @package Zorderz\AiWorkspace
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZAW_REST {

	const BASE = '/ai-workspace';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function person() {
		return ZAW_Grant::may_use( get_current_user_id() );
	}

	public static function admin() {
		$uid = get_current_user_id();
		return $uid > 0 && current_user_can( 'manage_options' ) && '' === ZAW_Grant::refused( $uid );
	}

	public static function routes() {
		$ns = ZDZ_REST_NS;
		$b  = self::BASE;
		$id = '(?P<id>[a-f0-9]{32})';
		register_rest_route( $ns, $b . '/turn', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'post_turn' ), 'permission_callback' => array( __CLASS__, 'person' ) ) );
		register_rest_route( $ns, $b . '/turn/' . $id, array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'get_turn' ), 'permission_callback' => array( __CLASS__, 'person' ) ) );
		register_rest_route( $ns, $b . '/turn/' . $id . '/cancel', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'post_cancel' ), 'permission_callback' => array( __CLASS__, 'person' ) ) );
		register_rest_route( $ns, $b . '/thread', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'get_thread' ), 'permission_callback' => array( __CLASS__, 'person' ) ) );
		register_rest_route( $ns, $b . '/hub', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'get_hub' ), 'permission_callback' => array( __CLASS__, 'person' ) ) );
		register_rest_route( $ns, $b . '/grant', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'post_grant' ), 'permission_callback' => array( __CLASS__, 'admin' ) ) );
		register_rest_route( $ns, $b . '/turn-fetch/' . $id, array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'get_fetch' ), 'permission_callback' => '__return_true' ) ); // sentinel:allow hub-called route; single-use token / sealed envelope checked in the callback
		register_rest_route( $ns, $b . '/tool', array( 'methods' => 'POST', 'callback' => array( 'ZAW_Tools', 'rest_tool' ), 'permission_callback' => '__return_true' ) ); // sentinel:allow hub-called route; single-use token / sealed envelope checked in the callback
	}

	public static function post_turn( $req ) {
		$r = ZAW_Turns::start( get_current_user_id(), (string) $req->get_param( 'text' ), (string) ( $req->get_param( 'conv' ) ?? '' ) );
		return is_wp_error( $r ) ? $r : rest_ensure_response( $r );
	}

	public static function get_turn( $req ) {
		$uid = get_current_user_id();
		$t   = ZAW_DB::turn( (string) $req['id'] );
		if ( ! $t || (int) $t['user_id'] !== (int) $uid ) {
			return new WP_Error( 'not_found', __( 'No such turn.', 'zorderz' ), array( 'status' => 404 ) );
		}
		return rest_ensure_response( ZAW_Turns::view( ZAW_Turns::advance( $t ), $uid ) );
	}

	public static function post_cancel( $req ) {
		$r = ZAW_Turns::cancel( (string) $req['id'], get_current_user_id() );
		return is_wp_error( $r ) ? $r : rest_ensure_response( $r );
	}

	public static function get_thread( $req ) {
		$uid  = get_current_user_id();
		$conv = (string) ( $req->get_param( 'conv' ) ?? '' );
		if ( '' === $conv ) {
			return rest_ensure_response( array( 'conversations' => ZAW_Turns::conversations( $uid ) ) );
		}
		return rest_ensure_response( array( 'conv' => $conv, 'turns' => ZAW_Turns::thread( $conv, $uid ) ) );
	}

	/** The side panel: whether the runner is up, and the engine's month. Numbers only. */
	public static function get_hub( $req ) {
		$r = ZAW_Hub::call( 'assistant_status', array() );
		if ( empty( $r['ok'] ) ) {
			return rest_ensure_response( array( 'ok' => false, 'say' => ZAW_Hub::say( $r ), 'money' => ZAW_Money::freshness() ) );
		}
		$d = is_array( $r['data'] ?? null ) ? $r['data'] : array();
		$m = is_array( $d['money'] ?? null ) ? $d['money'] : array();
		return rest_ensure_response( array(
			'ok'     => true,
			'runner' => in_array( $d['runner'] ?? '', array( 'up', 'down' ), true ) ? $d['runner'] : 'unknown',
			'money'  => array(
				'engine_usd' => (float) ( $m['engine_usd'] ?? 0 ),
				'other_usd'  => (float) ( $m['other_usd'] ?? 0 ),
				'cap_usd'    => isset( $m['cap_usd'] ) && is_numeric( $m['cap_usd'] ) ? (float) $m['cap_usd'] : null,
			),
		) );
	}

	public static function post_grant( $req ) {
		$uid = get_current_user_id();
		if ( $req->get_param( 'on' ) ) {
			$why = ZAW_Grant::grant_self( $uid );
			if ( '' !== $why ) {
				return new WP_Error( 'grant', $why, array( 'status' => 403 ) );
			}
		} else {
			ZAW_Grant::revoke_self( $uid );
		}
		return rest_ensure_response( array( 'on' => ZAW_Grant::may_use( $uid ) ) );
	}

	public static function get_fetch( $req ) {
		$r = ZAW_Turns::fetch( (string) $req['id'], (string) $req->get_param( 'k' ) );
		return is_wp_error( $r ) ? $r : rest_ensure_response( $r );
	}
}
