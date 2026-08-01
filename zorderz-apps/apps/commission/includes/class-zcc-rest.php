<?php
/**
 * ZCC_REST — REST API for the Commission app.
 *
 * Every route lives under the single ZDZ_REST_NS namespace constant (never the
 * literal typed twice — the v1.0.1 404 came from exactly that mistake). Every
 * route is logged-in only and app-access gated; the sensitive ones defer their
 * real tier decision to ZCC_TSA_Bridge / ZDZ_Data_Permissions.
 *
 * @package Zorderz\Commission
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZCC_REST {

	private static function ns(): string {
		return defined( 'ZDZ_REST_NS' ) ? ZDZ_REST_NS : 'zorderz/v1';
	}

	public static function register_routes(): void {
		$ns = self::ns();

		register_rest_route( $ns, '/commission/calculate', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'calculate' ],
			'permission_callback' => [ __CLASS__, 'can_access' ],
			'args'                => [
				'subject' => [ 'type' => 'string', 'required' => false ],
				'period'  => [ 'type' => 'string', 'required' => false ],
			],
		] );

		register_rest_route( $ns, '/commission/units', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'units' ],
			'permission_callback' => [ __CLASS__, 'can_access' ],
			'args'                => [ 'period' => [ 'type' => 'string', 'required' => false ] ],
		] );

		register_rest_route( $ns, '/commission/pay', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'pay' ],
			'permission_callback' => [ __CLASS__, 'can_access' ],
			'args'                => [ 'period' => [ 'type' => 'string', 'required' => false ] ],
		] );

		register_rest_route( $ns, '/commission/rep-override', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'rep_override' ],
			'permission_callback' => [ __CLASS__, 'can_admin' ],
		] );

		register_rest_route( $ns, '/commission/self-test', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'self_test' ],
			'permission_callback' => [ __CLASS__, 'can_admin' ],
		] );
	}

	public static function can_access( WP_REST_Request $req ) {
		$uid = get_current_user_id();
		if ( ! $uid ) {
			return new WP_Error( 'zcc_forbidden', 'Login required.', [ 'status' => 401 ] );
		}
		if ( class_exists( 'ZDZ_Plugin_API' ) && method_exists( 'ZDZ_Plugin_API', 'user_can_access_app' ) ) {
			return ZDZ_Plugin_API::user_can_access_app( $uid, ZCC_APP_ID ) ? true : new WP_Error( 'zcc_forbidden', 'No access to this app.', [ 'status' => 403 ] );
		}
		return true;
	}

	public static function can_admin( WP_REST_Request $req ) {
		return current_user_can( 'manage_options' ) ? true : new WP_Error( 'zcc_forbidden', 'Admin only.', [ 'status' => 403 ] );
	}

	public static function calculate( WP_REST_Request $req ) {
		if ( ! class_exists( 'ZCC_TSA_Bridge' ) ) {
			return new WP_Error( 'zcc_unavailable', 'Bridge unavailable.', [ 'status' => 503 ] );
		}
		return rest_ensure_response( ZCC_TSA_Bridge::commission_calc_for_tsa( [
			'subject'            => (string) $req->get_param( 'subject' ),
			'period'             => (string) ( $req->get_param( 'period' ) ?: 'this_month' ),
			'requesting_user_id' => get_current_user_id(),
		] ) );
	}

	public static function units( WP_REST_Request $req ) {
		if ( ! class_exists( 'ZCC_TSA_Bridge' ) ) {
			return new WP_Error( 'zcc_unavailable', 'Bridge unavailable.', [ 'status' => 503 ] );
		}
		return rest_ensure_response( ZCC_TSA_Bridge::unit_counts_for_tsa( [
			'period'             => (string) ( $req->get_param( 'period' ) ?: 'this_month' ),
			'requesting_user_id' => get_current_user_id(),
		] ) );
	}

	/** A piece worker's own paycheck for a window (company-wide shop counts). */
	public static function pay( WP_REST_Request $req ) {
		$uid  = get_current_user_id();
		$plan = class_exists( 'ZDZ_Compensation' ) ? ZDZ_Compensation::get_plan( $uid ) : [ 'is_piece_worker' => false ];
		if ( empty( $plan['is_piece_worker'] ) && ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'zcc_forbidden', 'Not a piece worker.', [ 'status' => 403 ] );
		}
		$period = (string) ( $req->get_param( 'period' ) ?: 'this_month' );
		$start  = preg_match( '/^\d{4}-\d{2}$/', $period ) ? $period . '-01' : current_time( 'Y-m-01' );
		$end    = preg_match( '/^\d{4}-\d{2}$/', $period ) ? gmdate( 'Y-m-t', strtotime( $start ) ) : current_time( 'Y-m-d' );
		return rest_ensure_response( ZCC_Installer_Pay::run_paycheck( 'ALL', $start, $end, null ) );
	}

	public static function rep_override( WP_REST_Request $req ) {
		$invoice_id = (int) $req->get_param( 'invoice_id' );
		$action     = (string) $req->get_param( 'action' );
		if ( $action === 'remove' ) {
			return rest_ensure_response( ZCC_Rep_Overrides::remove( $invoice_id ) );
		}
		return rest_ensure_response( ZCC_Rep_Overrides::assign( $invoice_id, (string) $req->get_param( 'rep_code' ), get_current_user_id(), (string) $req->get_param( 'note' ) ) );
	}

	public static function self_test( WP_REST_Request $req ) {
		return rest_ensure_response( class_exists( 'ZCC_Self_Test' ) ? ZCC_Self_Test::run() : [ 'passed' => false, 'error' => 'self-test unavailable' ] );
	}
}
