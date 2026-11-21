<?php
/**
 * ZANA_Research_REST — what the chat page may ask about a deep research run, and the two presses.
 *
 *   GET  /zorderz/v1/analytics/research/<id>         how the run stands (its owner only)
 *   POST /zorderz/v1/analytics/research/<id>/start   Start on its plan { web: bool } (its owner only)
 *   POST /zorderz/v1/analytics/research/<id>/stop    Stop (its owner, or an administrator)
 *
 * WHO. Every route needs a signed-in person who may open the chat (the chat's own check), and is refused for the
 * shared device and for a Privacy View account: neither can ever have a run. Then each route asks ZANA_Research
 * whose run it is. A run that is not the caller's reads as "not found": a run's number says nothing about who else
 * has one. A new run is never started here: that happens in the chat, from the person's own message.
 *
 * @package Zorderz\Analytics
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZANA_Research_REST {

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
		register_rest_route( $ns, '/analytics/research/(?P<id>\d+)', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'rest_status' ),
			'permission_callback' => array( __CLASS__, 'can_access' ),
			'args'                => array( 'id' => array( 'sanitize_callback' => 'absint' ) ),
		) );
		register_rest_route( $ns, '/analytics/research/(?P<id>\d+)/start', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'rest_start' ),
			'permission_callback' => array( __CLASS__, 'can_access' ),
			'args'                => array(
				'id'  => array( 'sanitize_callback' => 'absint' ),
				'web' => array( 'sanitize_callback' => 'rest_sanitize_boolean' ),
			),
		) );
		register_rest_route( $ns, '/analytics/research/(?P<id>\d+)/stop', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'rest_stop' ),
			'permission_callback' => array( __CLASS__, 'can_access' ),
			'args'                => array( 'id' => array( 'sanitize_callback' => 'absint' ) ),
		) );
	}

	/** A signed-in person who may open the chat, on their own device, not in Privacy View. */
	public static function can_access(): bool {
		$uid = (int) get_current_user_id();
		if ( $uid <= 0 || ! class_exists( 'ZANA_REST' ) || ! ZANA_REST::can_access() ) {
			return false;
		}
		if ( ! class_exists( 'ZANA_LLM_Client' ) || ZANA_LLM_Client::is_shared_device( $uid ) ) {
			return false;
		}
		return ! ZANA_Research_Gate::privacy_view( $uid );
	}

	private static function not_found() {
		return new WP_Error( 'zana_research_not_found', __( 'That research run was not found.', 'zorderz' ), array( 'status' => 404 ) );
	}

	public static function rest_status( WP_REST_Request $request ) {
		$out = ZANA_Research::status( (int) $request->get_param( 'id' ), (int) get_current_user_id() );
		return null === $out ? self::not_found() : rest_ensure_response( $out );
	}

	public static function rest_start( WP_REST_Request $request ) {
		$uid = (int) get_current_user_id();
		$id  = (int) $request->get_param( 'id' );
		$run = ZANA_Research_Store::get( $id );
		if ( ! $run || (int) $run['user_id'] !== $uid ) {
			return self::not_found();
		}
		$web = $request->get_param( 'web' );
		$no  = ZANA_Research::approve( $id, $uid, true === $web || 1 === $web || '1' === $web || 'true' === $web );
		if ( '' !== $no ) {
			return new WP_Error( 'zana_research_not_started', $no, array( 'status' => 409 ) );
		}
		return rest_ensure_response( array( 'ok' => true ) + (array) ZANA_Research::status( $id, $uid ) );
	}

	public static function rest_stop( WP_REST_Request $request ) {
		$uid = (int) get_current_user_id();
		$id  = (int) $request->get_param( 'id' );
		$run = ZANA_Research_Store::get( $id );
		$own = $run && (int) $run['user_id'] === $uid;
		if ( ! $run || ( ! $own && ! current_user_can( 'manage_options' ) ) ) {
			return self::not_found();
		}
		$no = ZANA_Research::stop( $id, $uid );
		if ( '' !== $no ) {
			return new WP_Error( 'zana_research_not_stopped', $no, array( 'status' => 403 ) );
		}
		// an administrator stopping someone else's run is told it is done, and shown nothing of it
		return rest_ensure_response( $own ? array( 'ok' => true ) + (array) ZANA_Research::status( $id, $uid ) : array( 'ok' => true ) );
	}
}
