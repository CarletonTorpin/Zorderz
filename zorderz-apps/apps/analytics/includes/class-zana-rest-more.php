<?php
/**
 * ZANA_REST_More — the chat's routes for "About you", starters, email hand-off, notifications and the business's facts.
 *
 * All under ZDZ_REST_NS, signed-in and app-access gated (ZANA_REST::can_access) unless marked admin:
 *   GET    {ns}/analytics/memory                { sentences: [ { id, text, created_at } ], enabled }
 *   DELETE {ns}/analytics/memory/<id>           { deleted: bool }                       (the person's own only)
 *   GET    {ns}/analytics/starters              { prompts: [ string ] }
 *   POST   {ns}/analytics/compose               { to, cc?, subject, body } → { mode: 'inbox', token, app, href } | { mode: 'mailto', href }
 *   POST   {ns}/analytics/email/sent            { name?, email?, context? } → { logged: bool }  (an outreach email the person sent)
 *   POST   {ns}/analytics/turn/<id>/notify      { notify: bool }: push "your answer is ready" when that queued turn ends
 *   GET    {ns}/analytics/timings               admin: { kinds: [ summary rows ] }; ?format=csv → text/csv rows
 *   GET    {ns}/analytics/facts                 admin: { facts: [ … ] }
 *   POST   {ns}/analytics/facts                 admin: one fact → { success, fact | error }
 *   DELETE {ns}/analytics/facts/<id>            admin
 *   POST   {ns}/analytics/facts/order           admin: { ids: [] }
 *   GET    {ns}/analytics/facts/csv             admin: text/csv;  POST same path { csv } → { saved, errors }
 * And admin-ajax: zana_list_memories (the same list as GET memory; Privacy View's road), with nonce zana_nonce.
 *
 * @package Zorderz\Analytics
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZANA_REST_More {

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_action( 'wp_ajax_zana_list_memories', array( __CLASS__, 'ajax_list_memories' ) );
	}

	public static function admin(): bool {
		return current_user_can( 'manage_options' );
	}

	public static function register_routes(): void {
		$ns   = ZDZ_REST_NS;
		$user = array( 'ZANA_REST', 'can_access' );
		$adm  = array( __CLASS__, 'admin' );
		register_rest_route( $ns, '/analytics/memory', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'memory_list' ), 'permission_callback' => $user ) );
		register_rest_route( $ns, '/analytics/memory/(?P<id>\d+)', array( 'methods' => 'DELETE', 'callback' => array( __CLASS__, 'memory_delete' ), 'permission_callback' => $user, 'args' => array( 'id' => array( 'sanitize_callback' => 'absint' ) ) ) );
		register_rest_route( $ns, '/analytics/starters', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'starters' ), 'permission_callback' => $user ) );
		register_rest_route( $ns, '/analytics/compose', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'compose' ), 'permission_callback' => $user ) );
		register_rest_route( $ns, '/analytics/email/sent', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'email_sent' ), 'permission_callback' => $user ) );
		register_rest_route( $ns, '/analytics/turn/(?P<id>\d+)/notify', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'turn_notify' ), 'permission_callback' => $user, 'args' => array( 'id' => array( 'sanitize_callback' => 'absint' ) ) ) );
		register_rest_route( $ns, '/analytics/timings', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'timings' ), 'permission_callback' => $adm ) );
		register_rest_route( $ns, '/analytics/facts', array(
			array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'facts_list' ), 'permission_callback' => $adm ),
			array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'facts_save' ), 'permission_callback' => $adm ),
		) );
		register_rest_route( $ns, '/analytics/facts/order', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'facts_order' ), 'permission_callback' => $adm ) );
		register_rest_route( $ns, '/analytics/facts/csv', array(
			array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'facts_csv' ), 'permission_callback' => $adm ),
			array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'facts_import' ), 'permission_callback' => $adm ),
		) );
		register_rest_route( $ns, '/analytics/facts/(?P<id>[a-z0-9_\-]{1,64})', array( 'methods' => 'DELETE', 'callback' => array( __CLASS__, 'facts_delete' ), 'permission_callback' => $adm ) );
	}

	/* ── About you ── */

	public static function memory_rows( int $uid ): array {
		return array_map( function ( $r ) {
			return array( 'id' => (int) $r['id'], 'text' => (string) $r['fact_value'], 'created_at' => (string) $r['created_at'] );
		}, ZANA_User_Memory::sentences( $uid ) );
	}

	public static function memory_list() {
		$uid = (int) get_current_user_id();
		return rest_ensure_response( array( 'sentences' => self::memory_rows( $uid ), 'enabled' => ZANA_User_Memory::enabled() && ! ZANA_User_Memory::refused( $uid ) ) );
	}

	public static function memory_delete( WP_REST_Request $req ) {
		return rest_ensure_response( array( 'deleted' => ZANA_User_Memory::delete( (int) get_current_user_id(), (int) $req->get_param( 'id' ) ) ) );
	}

	public static function ajax_list_memories(): void {
		check_ajax_referer( ZANA_NONCE, 'nonce' );
		if ( ! ZANA_REST::can_access() ) {
			wp_send_json_error( array( 'message' => __( 'The chat is not available on this account.', 'zorderz' ) ), 403 );
		}
		wp_send_json_success( array( 'sentences' => self::memory_rows( (int) get_current_user_id() ) ) );
	}

	/* ── starters, email ── */

	public static function starters() {
		return rest_ensure_response( array( 'prompts' => ZANA_Starters::for_user( (int) get_current_user_id() ) ) );
	}

	public static function compose( WP_REST_Request $req ) {
		$r = ZANA_Compose::open_in_email( (int) get_current_user_id(), array(
			'to'      => (string) $req->get_param( 'to' ),
			'cc'      => (string) $req->get_param( 'cc' ),
			'subject' => (string) $req->get_param( 'subject' ),
			'body'    => sanitize_textarea_field( (string) $req->get_param( 'body' ) ),
		) );
		if ( isset( $r['error'] ) ) {
			return new WP_Error( 'zana_compose', $r['error'], array( 'status' => 403 ) );
		}
		return rest_ensure_response( $r );
	}

	public static function email_sent( WP_REST_Request $req ) {
		$ok = ZANA_Email_Cooldown::log_sent( (int) get_current_user_id(), (string) $req->get_param( 'name' ), (string) $req->get_param( 'email' ), (string) $req->get_param( 'context' ) );
		return rest_ensure_response( array( 'logged' => $ok ) );
	}

	/** Push when this person's queued turn ends (the page was left while it ran). Owner-scoped by the job's row. */
	public static function turn_notify( WP_REST_Request $req ) {
		$uid = (int) get_current_user_id();
		$id  = (int) $req->get_param( 'id' );
		$st  = class_exists( 'ZANA_Background' ) ? ZANA_Background::status( $uid, $id ) : array( 'ok' => false );
		if ( empty( $st['ok'] ) ) {
			return new WP_Error( 'zana_turn', __( 'Turn not found.', 'zorderz' ), array( 'status' => 404 ) );
		}
		if ( false === (bool) $req->get_param( 'notify' ) && null !== $req->get_param( 'notify' ) ) {
			delete_transient( 'zana_notify_' . $id );
			return rest_ensure_response( array( 'notify' => false ) );
		}
		set_transient( 'zana_notify_' . $id, $uid, DAY_IN_SECONDS );
		return rest_ensure_response( array( 'notify' => true ) );
	}

	/** When a queued turn ends: the push, if the person asked for one (ZANA_Background calls this). */
	public static function turn_done( int $job_id, int $uid, int $session_id ): void {
		$want = (int) get_transient( 'zana_notify_' . $job_id );
		if ( $want <= 0 || $want !== $uid ) {
			return;
		}
		delete_transient( 'zana_notify_' . $job_id );
		if ( class_exists( 'ZANA_Push' ) ) {
			global $wpdb;
			$title = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT title FROM ' . ZANA_DB::sessions_table() . ' WHERE id = %d AND user_id = %d', $session_id, $uid ) );   // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			ZANA_Push::notify_answer( $uid, $session_id, $title );
		}
	}

	/* ── admin ── */

	public static function timings( WP_REST_Request $req ) {
		$days = max( 1, min( 90, (int) ( $req->get_param( 'days' ) ?: 7 ) ) );
		$rows = ZANA_Turn_Timings::rows( $days );
		if ( 'csv' === $req->get_param( 'format' ) ) {
			$res = new WP_REST_Response( ZANA_Turn_Timings::csv( $rows ) );
			$res->header( 'Content-Type', 'text/csv; charset=utf-8' );
			return $res;
		}
		return rest_ensure_response( array( 'days' => $days, 'kinds' => ZANA_Turn_Timings::summary( $rows ) ) );
	}

	public static function facts_list() {
		return rest_ensure_response( array( 'facts' => ZANA_Company_Facts::get_all() ) );
	}

	public static function facts_save( WP_REST_Request $req ) {
		$r = ZANA_Company_Facts::save( (array) $req->get_json_params() + (array) $req->get_body_params() );
		return empty( $r['success'] ) ? new WP_Error( 'zana_fact', (string) $r['error'], array( 'status' => 400 ) ) : rest_ensure_response( $r );
	}

	public static function facts_delete( WP_REST_Request $req ) {
		return rest_ensure_response( array( 'deleted' => ZANA_Company_Facts::delete( (string) $req->get_param( 'id' ) ) ) );
	}

	public static function facts_order( WP_REST_Request $req ) {
		return rest_ensure_response( array( 'updated' => ZANA_Company_Facts::reorder( (array) $req->get_param( 'ids' ) ) ) );
	}

	public static function facts_csv() {
		$res = new WP_REST_Response( ZANA_Company_Facts::export_csv() );
		$res->header( 'Content-Type', 'text/csv; charset=utf-8' );
		return $res;
	}

	public static function facts_import( WP_REST_Request $req ) {
		return rest_ensure_response( ZANA_Company_Facts::import_csv( (string) $req->get_param( 'csv' ) ) );
	}
}
