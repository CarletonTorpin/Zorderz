<?php
/**
 * ZCC_REST — REST API for the Commission app.
 *
 * Every route lives under the single ZDZ_REST_NS namespace constant (never the
 * literal typed twice — the v1.0.1 404 came from exactly that mistake). Every
 * route is logged-in only and app-access gated; the sensitive ones defer their
 * real tier decision to ZCC_ZANA_Bridge / ZDZ_Data_Permissions.
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
				'subject'    => [ 'type' => 'string', 'required' => false ],
				'period'     => [ 'type' => 'string', 'required' => false ],
				'date_start' => [ 'type' => 'string', 'required' => false ],
				'date_end'   => [ 'type' => 'string', 'required' => false ],
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

		// The payer locks a person's ended month after paying it (POST; the server works it out).
		register_rest_route( $ns, '/commission/lock-month', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'lock_month' ],
			'permission_callback' => [ __CLASS__, 'can_access' ],
			'args'                => [
				'user_id' => [ 'type' => 'integer', 'required' => true ],
				'month'   => [ 'type' => 'string', 'required' => true ],
			],
		] );

		// One invoice drawn inside the app, read live; and the customer link to copy.
		register_rest_route( $ns, '/commission/invoice', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'invoice' ],
			'permission_callback' => [ __CLASS__, 'can_access' ],
			'args'                => [
				'number' => [ 'type' => 'string', 'required' => true ],
				'where'  => [ 'type' => 'string', 'required' => false ],
				'target' => [ 'type' => 'integer', 'required' => false ],
			],
		] );
		register_rest_route( $ns, '/commission/invoice-link', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'invoice_link' ],
			'permission_callback' => [ __CLASS__, 'can_access' ],
		] );

		// Review items and Ai proposals: administrators who may see costs.
		register_rest_route( $ns, '/commission/review', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'review' ],
			'permission_callback' => [ __CLASS__, 'can_admin' ],
		] );
		register_rest_route( $ns, '/commission/ai-propose', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'ai_propose' ],
			'permission_callback' => [ __CLASS__, 'can_admin' ],
			'args'                => [ 'period' => [ 'type' => 'string', 'required' => false ] ],
		] );
		register_rest_route( $ns, '/commission/ai-settle', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'ai_settle' ],
			'permission_callback' => [ __CLASS__, 'can_admin' ],
		] );
		register_rest_route( $ns, '/commission/material-report', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'material_report' ],
			'permission_callback' => [ __CLASS__, 'can_admin' ],
			'args'                => [
				'user_id' => [ 'type' => 'integer', 'required' => true ],
				'month'   => [ 'type' => 'string', 'required' => true ],
			],
		] );

		// ADMIN-ONLY coverage telemetry. Reports counts + the single unassigned-
		// revenue total; never a per-person figure. Read-only — cannot move a dollar.
		register_rest_route( $ns, '/commission/coverage', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'coverage' ],
			'permission_callback' => [ __CLASS__, 'can_admin' ],
			'args'                => [ 'period' => [ 'type' => 'string', 'required' => false ] ],
		] );
	}

	public static function can_access( WP_REST_Request $req ) {
		$uid = get_current_user_id();
		if ( ! $uid ) {
			return new WP_Error( 'zcc_forbidden', 'Login required.', [ 'status' => 401 ] );
		}
		if ( class_exists( 'ZDZ_Capabilities' ) && method_exists( 'ZDZ_Capabilities', 'tier_of' ) && 'kiosk' === ZDZ_Capabilities::tier_of( $uid ) ) {
			return new WP_Error( 'zcc_forbidden', 'Not available on the shared device.', [ 'status' => 403 ] );
		}
		if ( class_exists( 'ZDZ_Plugin_API' ) && method_exists( 'ZDZ_Plugin_API', 'user_can_access_app' ) ) {
			return ZDZ_Plugin_API::user_can_access_app( $uid, ZCC_APP_ID ) ? true : new WP_Error( 'zcc_forbidden', 'No access to this app.', [ 'status' => 403 ] );
		}
		return true;
	}

	public static function can_admin( WP_REST_Request $req ) {
		$uid = get_current_user_id();
		return ( current_user_can( 'manage_options' ) && ZCC_Visibility::may_see_costs( $uid ) ) ? true : new WP_Error( 'zcc_forbidden', 'Admin only.', [ 'status' => 403 ] );
	}

	/** A month window from a period word or YYYY-MM. */
	private static function window( string $period ): array {
		$start = preg_match( '/^\d{4}-\d{2}$/', $period ) ? $period . '-01' : ( 'last_month' === $period ? gmdate( 'Y-m-01', strtotime( 'first day of last month', strtotime( current_time( 'Y-m-d' ) ) ) ) : current_time( 'Y-m-01' ) );
		$end   = ( preg_match( '/^\d{4}-\d{2}$/', $period ) || 'last_month' === $period ) ? gmdate( 'Y-m-t', strtotime( $start ) ) : current_time( 'Y-m-d' );
		return [ $start, $end ];
	}

	public static function calculate( WP_REST_Request $req ) {
		if ( ! class_exists( 'ZCC_ZANA_Bridge' ) ) {
			return new WP_Error( 'zcc_unavailable', 'Bridge unavailable.', [ 'status' => 503 ] );
		}
		$payload = [
			'subject'            => (string) $req->get_param( 'subject' ),
			'period'             => (string) ( $req->get_param( 'period' ) ?: 'this_month' ),
			'requesting_user_id' => get_current_user_id(),
		];
		$ds = (string) $req->get_param( 'date_start' );
		$de = (string) $req->get_param( 'date_end' );
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $ds ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $de ) && $ds <= $de ) {
			$payload['date_start'] = $ds;
			$payload['date_end']   = $de;
		}
		return rest_ensure_response( ZCC_ZANA_Bridge::commission_calc_for_zana( $payload ) );
	}

	public static function units( WP_REST_Request $req ) {
		if ( ! class_exists( 'ZCC_ZANA_Bridge' ) ) {
			return new WP_Error( 'zcc_unavailable', 'Bridge unavailable.', [ 'status' => 503 ] );
		}
		return rest_ensure_response( ZCC_ZANA_Bridge::unit_counts_for_zana( [
			'period'             => (string) ( $req->get_param( 'period' ) ?: 'this_month' ),
			'requesting_user_id' => get_current_user_id(),
		] ) );
	}

	/**
	 * A piece worker's own paycheck for a window: only the jobs attributed to them. A pay admin
	 * may ask for someone else's with `subject` (a user id).
	 */
	public static function pay( WP_REST_Request $req ) {
		$uid     = get_current_user_id();
		$subject = (int) $req->get_param( 'subject' );
		if ( $subject > 0 && $subject !== $uid ) {
			if ( ! ZCC_Visibility::is_pay_admin( $uid ) ) {
				return new WP_Error( 'zcc_forbidden', 'Only the person who pays may see another person\'s pay.', [ 'status' => 403 ] );
			}
		} else {
			$subject = $uid;
		}
		$plan = class_exists( 'ZDZ_Compensation' ) ? ZDZ_Compensation::get_plan( $subject ) : [ 'is_piece_worker' => false ];
		if ( empty( $plan['is_piece_worker'] ) ) {
			return new WP_Error( 'zcc_forbidden', 'Not a piece worker.', [ 'status' => 403 ] );
		}
		list( $start, $end ) = self::window( (string) ( $req->get_param( 'period' ) ?: 'this_month' ) );
		$out = ZCC_Installer_Pay::run_paycheck_for_party( $subject, $start, $end, null );
		// The review rows name invoice numbers and people: only the payer reads them.
		if ( ! ZCC_Visibility::is_pay_admin( $uid ) ) {
			$out['review_count'] = count( (array) ( $out['review'] ?? [] ) );
			unset( $out['review'] );
		}
		return rest_ensure_response( $out );
	}

	public static function lock_month( WP_REST_Request $req ) {
		return rest_ensure_response( ZCC_Month_Lock::answer( get_current_user_id(), (int) $req->get_param( 'user_id' ), (string) $req->get_param( 'month' ) ) );
	}

	public static function invoice( WP_REST_Request $req ) {
		$where = (string) ( $req->get_param( 'where' ) ?: 'commission' );
		return rest_ensure_response( ZCC_Invoice_View::answer( get_current_user_id(), (string) $req->get_param( 'number' ), $where, (int) $req->get_param( 'target' ) ) );
	}

	public static function invoice_link( WP_REST_Request $req ) {
		$where = (string) ( $req->get_param( 'where' ) ?: 'commission' );
		return rest_ensure_response( ZCC_Invoice_View::link_answer( get_current_user_id(), (string) $req->get_param( 'number' ), $where, (int) $req->get_param( 'target' ) ) );
	}

	public static function review( WP_REST_Request $req ) {
		$items = [];
		foreach ( ZCC_Review::open() as $key => $r ) {
			$items[] = [ 'key' => $key, 'why' => ZCC_Attribution::reason_text( (string) ( $r['reason'] ?? '' ) ) ] + $r;
		}
		return rest_ensure_response( [ 'items' => $items, 'proposals' => ZCC_Ai::all() ] );
	}

	public static function ai_propose( WP_REST_Request $req ) {
		list( $start, $end ) = self::window( (string) ( $req->get_param( 'period' ) ?: 'this_month' ) );
		$invoices = ZCC_FreshBooks::get_invoices( $start, $end );
		return rest_ensure_response( ZCC_Ai::propose( ZCC_Ai::unclear_lines( $invoices ), get_current_user_id() ) );
	}

	public static function ai_settle( WP_REST_Request $req ) {
		$decision = 'confirm' === (string) $req->get_param( 'decision' ) ? 'confirm' : 'reject';
		return rest_ensure_response( ZCC_Ai::settle( (string) $req->get_param( 'key' ), $decision, sanitize_key( (string) $req->get_param( 'item_id' ) ), get_current_user_id() ) );
	}

	public static function material_report( WP_REST_Request $req ) {
		return rest_ensure_response( ZCC_Material_Cost::report_row( (int) $req->get_param( 'user_id' ), (string) $req->get_param( 'month' ) ) );
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

	/**
	 * Admin-only attribution-coverage snapshot for a period (default this month).
	 * Counts + one unassigned-revenue total only; no per-person figure. Read-only.
	 */
	public static function coverage( WP_REST_Request $req ) {
		if ( ! class_exists( 'ZCC_Coverage' ) || ! class_exists( 'ZCC_FreshBooks' ) ) {
			return new WP_Error( 'zcc_unavailable', 'Coverage unavailable.', [ 'status' => 503 ] );
		}
		list( $start, $end ) = self::window( (string) ( $req->get_param( 'period' ) ?: '' ) );
		$invoices = ZCC_FreshBooks::is_connected() ? ZCC_FreshBooks::get_invoices( $start, $end ) : [];
		return rest_ensure_response( ZCC_Coverage::snapshot( is_array( $invoices ) ? $invoices : [] ) );
	}
}
