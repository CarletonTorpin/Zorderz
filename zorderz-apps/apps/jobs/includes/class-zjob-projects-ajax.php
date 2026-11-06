<?php
/**
 * ZJOB_Projects_Ajax — the Projects hub's two reads: the list and one opened Project.
 *
 *   zdz_jobs_projects_list    the Projects a person may see (the Projects scope, in SQL), each with its install chip
 *                             (ZJOB_Install_Date, gated per row), whether it is theirs, and its orders in brief.
 *   zdz_jobs_project_detail   one Project: where it stands, the install date, its jobs, its people, its material
 *                             orders (ZJOB_Project_Orders) and the go sign for ordering (ZJOB_Order_Gate).
 *
 * Both: signed in, nonce, never the shared device, the Projects app on the person's account; the detail also passes
 * the Projects visibility rule, and a missing Project and a forbidden one get the same answer. A Privacy View account
 * gets both reads masked on the server (ZJOB_Privacy_View) and nothing that writes. No phone number, no amount.
 *
 * @package Zorderz\Jobs
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZJOB_Projects_Ajax {

	const LIST_LIMIT = 60;

	public static function init(): void {
		add_action( 'wp_ajax_zdz_jobs_projects_list', array( __CLASS__, 'ajax_list' ) );
		add_action( 'wp_ajax_zdz_jobs_project_detail', array( __CLASS__, 'ajax_detail' ) );
		// No _nopriv variants.
	}

	/** The gate both reads share. Returns the signed-in person's id, or ends the request. */
	private static function gate(): int {
		check_ajax_referer( ZJOB_NONCE, 'nonce' );
		$uid = (int) get_current_user_id();
		if ( $uid <= 0 ) {
			wp_send_json_error( array( 'message' => 'not_logged_in' ), 403 );
		}
		if ( class_exists( 'ZDZ_Hierarchy' ) && ZDZ_Hierarchy::is_kiosk( $uid ) ) {
			wp_send_json_error( array( 'message' => 'kiosk_forbidden' ), 403 );
		}
		if ( class_exists( 'ZDZ_Plugin_API' ) && method_exists( 'ZDZ_Plugin_API', 'user_can_access_app' )
			&& ! ZDZ_Plugin_API::user_can_access_app( $uid, ZJOB_Projects_App::APP_ID ) ) {
			wp_send_json_error( array( 'message' => 'not_permitted' ), 403 );
		}
		return $uid;
	}

	/** A person's display name (a pseudonym in Privacy View). */
	public static function person( int $uid ): string {
		if ( $uid <= 0 ) {
			return '';
		}
		if ( ZJOB_Privacy_View::on() && class_exists( 'ZDZ_Privacy_View' ) ) {
			return (string) ZDZ_Privacy_View::staff( $uid );
		}
		$u = get_userdata( $uid );
		return $u ? (string) $u->display_name : '';
	}

	private static function plain( $s ): string {
		return html_entity_decode( (string) $s, ENT_QUOTES, 'UTF-8' );
	}

	/** The install chip for a Project: the paper line and its status, never a raw date to render. */
	public static function install_chip( string $pid, int $uid ): array {
		if ( ! class_exists( 'Zjob_Install_Date' ) ) {
			return array( 'line' => '', 'status' => 'unknown' );
		}
		$env = Zjob_Install_Date::for_project( $pid, $uid );
		return array(
			'line'   => Zjob_Install_Date::paper_line( $env ),
			'status' => (string) ( $env['status'] ?? 'unknown' ),
			'time'   => Zjob_Install_Date::is_scheduled( $env ) ? (string) ( $env['time'] ?? '' ) : '',
			'spread' => ! empty( $env['spread'] ),
		);
	}

	/** Is this Project the person's own (they made it, are on one of its jobs, or are confirmed on a step)? */
	private static function is_mine( string $pid, int $uid ): bool {
		if ( in_array( $uid, Zjob_Project::participant_ids( $pid ), true ) ) {
			return true;
		}
		if ( class_exists( 'ZJOB_Project_People' ) ) {
			foreach ( ZJOB_Project_People::confirmed( $pid ) as $c ) {
				if ( (int) ( $c['user_id'] ?? 0 ) === $uid ) {
					return true;
				}
			}
		}
		// The person owns an open step on it (My next's own rule, read once per request).
		static $steps = array();
		if ( ! isset( $steps[ $uid ] ) ) {
			$steps[ $uid ] = class_exists( 'ZJOB_My_Next' ) ? array_flip( ZJOB_My_Next::step_project_ids( $uid ) ) : array();
		}
		return isset( $steps[ $uid ][ $pid ] );
	}

	public static function ajax_list(): void {
		$uid  = self::gate();
		$view = isset( $_POST['view'] ) ? sanitize_key( wp_unslash( $_POST['view'] ) ) : 'mine'; // phpcs:ignore WordPress.Security.NonceVerification -- checked in gate().
		wp_send_json_success( self::list_reply( $uid, $view ) );
	}

	/** The list reply for a person ('mine' | 'all'). */
	public static function list_reply( int $uid, string $view ): array {
		$rows = array();
		if ( class_exists( 'Zjob_Project' ) ) {
			// "All I can see" includes every Project's basic info for a person with the reading grant.
			// The Projects the person is confirmed on are theirs to read in either view.
			foreach ( Zjob_Project::list_for( $uid, array( 'limit' => 200, 'reading' => 'all' === $view, 'confirmed' => true ) ) as $wi ) {
				$pid = (string) ( $wi['id'] ?? '' );
				if ( '' === $pid ) {
					continue;
				}
				$mine = self::is_mine( $pid, $uid );
				if ( 'all' !== $view && ! $mine ) {
					continue;
				}
				$p      = Zjob_Project::get( $pid );
				$cust   = is_array( $p ) ? (array) ( $p['customer'] ?? array() ) : array();
				$orders = class_exists( 'ZJOB_Project_Orders' ) ? ZJOB_Project_Orders::for_project( $pid ) : array();
				$placed = count( array_filter( $orders, static function ( $o ) {
					return ZJOB_Project_Orders::ST_ORDERED === $o['status'];
				} ) );
				$home = '';
				if ( class_exists( 'ZJOB_Project_Homes' ) && ZJOB_Project_Homes::on() ) {
					$pj   = Zjob_Project::jobs_for( $pid );
					$home = ZJOB_Project_Homes::home_key( ZJOB_Project_Homes::client_of( $pid, $pj ), (string) ( $pj[0]['customer_address'] ?? '' ) );
				}
				$rows[] = array(
					'id'       => $pid,
					'home'     => $home,
					'code'     => (string) ( $wi['human_code'] ?? '' ),
					'status'   => (string) ( $wi['state'] ?? '' ),
					'customer' => self::plain( $cust['name'] ?? '' ),
					'business' => self::plain( $cust['business'] ?? '' ),
					'address'  => self::plain( $cust['address'] ?? '' ),
					'mine'     => $mine,
					'install'  => self::install_chip( $pid, $uid ),
					'orders'   => array( 'count' => count( $orders ), 'placed' => $placed ),
					'created'  => substr( (string) ( $wi['created_at'] ?? '' ), 0, 10 ),
				);
				if ( count( $rows ) >= self::LIST_LIMIT ) {
					break;
				}
			}
		}
		// One card per home (the same client and street): its other estimates ride on it as parts.
		if ( class_exists( 'ZJOB_Project_Homes' ) && ZJOB_Project_Homes::on() ) {
			$rows = ZJOB_Project_Homes::group( $rows );
		}
		$scope = class_exists( 'ZJOB_Scope' ) ? ZJOB_Scope::for_user( $uid ) : array( 'tier' => 'none' );
		$reply = array(
			'rows'  => $rows,
			'view'  => 'all' === $view ? 'all' : 'mine',
			'label' => 'all' === $view ? self::all_label( $uid, $scope ) : __( 'Mine', 'zorderz' ),
		);
		return ZJOB_Privacy_View::on() ? ZJOB_Privacy_View::rows( $reply ) : $reply;
	}

	/** The "All I can see" heading: every Project for the reading grant, else the person's scope. */
	private static function all_label( int $uid, array $scope ): string {
		if ( empty( $scope['sees_all'] ) && class_exists( 'Zjob_Project_Visibility' ) && Zjob_Project_Visibility::sees_all_basic( $uid ) ) {
			return __( 'All projects', 'zorderz' );
		}
		return class_exists( 'ZJOB_Scope' ) ? ZJOB_Scope::project_label( $scope ) : '';
	}

	public static function ajax_detail(): void {
		$uid = self::gate();
		$pid = isset( $_POST['project_id'] ) ? sanitize_text_field( wp_unslash( $_POST['project_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- checked in gate().
		$out = self::detail_reply( $uid, $pid );
		if ( null === $out ) {
			// One answer for a missing Project and a forbidden one.
			wp_send_json_error( array( 'message' => __( 'That project is not available.', 'zorderz' ) ), 404 );
		}
		wp_send_json_success( $out );
	}

	/** The opened Project for a person, or null when it is missing or not theirs to see. */
	public static function detail_reply( int $uid, string $pid ): ?array {
		$p = ( '' !== $pid && class_exists( 'Zjob_Project' ) ) ? Zjob_Project::get( $pid ) : null;
		if ( ! $p || ! class_exists( 'Zjob_Project_Visibility' ) || ! Zjob_Project_Visibility::may_view_project( $uid, $p ) ) {
			return null;
		}
		$jobs = Zjob_Project::jobs_for( $pid );
		$vis  = class_exists( 'Zjob_Install_Date' ) ? Zjob_Install_Date::visible_rows( $uid, $jobs ) : array();
		$cust = (array) ( $p['customer'] ?? array() );

		$job_rows = array();
		foreach ( $vis as $j ) {
			$job_rows[] = array(
				'id'        => (int) $j['id'],
				'kind'      => function_exists( 'zjob_component_label' ) ? zjob_component_label( (string) $j['component'] ) : (string) $j['component'],
				'status'    => (string) $j['status'],
				'assignee'  => self::person( (int) $j['assigned_user_id'] ),
				'scheduled' => (int) ( $j['scheduled_appt_id'] ?? 0 ) > 0,
			);
		}
		$people = array();
		if ( class_exists( 'ZJOB_Project_People' ) ) {
			$labels = ZJOB_Project_People::labels();
			foreach ( ZJOB_Project_People::confirmed( $pid ) as $role => $c ) {
				$people[] = array( 'role' => (string) $role, 'label' => (string) ( $labels[ $role ] ?? $role ), 'name' => self::person( (int) $c['user_id'] ) );
			}
		}
		$out = array(
			'project' => array(
				'id'                => $pid,
				'code'              => (string) ( $p['human_code'] ?? '' ),
				'status'            => (string) ( $p['state'] ?? '' ),
				'customer_name'     => self::plain( $cust['name'] ?? '' ),
				'customer_business' => self::plain( $cust['business'] ?? '' ),
				'customer_address'  => self::plain( $cust['address'] ?? '' ),
				'created'           => substr( (string) ( $p['created_at'] ?? '' ), 0, 10 ),
			),
			'install' => self::install_chip( $pid, $uid ),
			'jobs'    => $job_rows,
			'people'  => $people,
			'orders'  => class_exists( 'ZJOB_Project_Orders' ) && ZJOB_Project_Orders::ready() ? ZJOB_Project_Orders::view( $p, $jobs, $uid ) : null,
			'gate'    => class_exists( 'ZJOB_Order_Gate' ) ? ZJOB_Order_Gate::view( $p, $jobs ) : null,
			'home'    => class_exists( 'ZJOB_Project_Homes' ) && ! ZJOB_Privacy_View::on() ? ZJOB_Project_Homes::view( $p, $jobs, $uid ) : null,
		);
		if ( class_exists( 'ZJOB_Sealed' ) ) {
			$out = ZJOB_Sealed::deep( $out );
		}
		return ZJOB_Privacy_View::on() ? ZJOB_Privacy_View::detail( $out ) : $out;
	}
}
