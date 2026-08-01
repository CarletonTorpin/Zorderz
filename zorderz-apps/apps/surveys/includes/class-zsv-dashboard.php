<?php
/**
 * Zorderz Surveys — AJAX endpoints backing the dashboard widget.
 *
 * Every write endpoint re-checks the nonce + capability server-side. Read endpoints
 * scope rows to what the caller may see. The heavy batch/enrich orchestration is the
 * survey manager's; this class is the transport.
 *
 * @package Zorderz\Surveys
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZSV_Dashboard {

	public static function init(): void {
		add_action( 'wp_ajax_zsv_stats', array( __CLASS__, 'ajax_stats' ) );
		add_action( 'wp_ajax_zsv_recent', array( __CLASS__, 'ajax_recent' ) );
		add_action( 'wp_ajax_zsv_run_batch', array( __CLASS__, 'ajax_run_batch' ) );
		add_action( 'wp_ajax_zsv_sync', array( __CLASS__, 'ajax_sync' ) );
		add_action( 'wp_ajax_zsv_send_invites', array( __CLASS__, 'ajax_send_invites' ) );
		add_action( 'wp_ajax_zsv_exclude', array( __CLASS__, 'ajax_exclude' ) );
	}

	/** Guard: valid nonce + operator/admin capability, or die with JSON. */
	private static function guard(): void {
		check_ajax_referer( ZSV_NONCE, 'nonce' );
		$uid = get_current_user_id();
		$ok  = user_can( $uid, 'manage_options' );
		if ( ! $ok && class_exists( 'ZDZ_Data_Permissions' ) && method_exists( 'ZDZ_Data_Permissions', 'can' ) ) {
			$ok = (bool) ZDZ_Data_Permissions::can( $uid, 'view_others_data' );
		}
		if ( ! $ok ) {
			wp_send_json_error( 'Unauthorized' );
		}
	}

	public static function ajax_stats(): void {
		self::guard();
		global $wpdb;
		$leads   = ZSV_DB::leads_table();
		$batches = ZSV_DB::batches_table();
		wp_send_json_success(
			array(
				'batches' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$batches}" ),
				'leads'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$leads}" ),
				'invited' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$leads} WHERE email_sent_at IS NOT NULL" ),
				'reviews' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$leads} WHERE review_left = 1" ),
			)
		);
	}

	public static function ajax_recent(): void {
		self::guard();
		global $wpdb;
		$leads = ZSV_DB::leads_table();
		$rows  = $wpdb->get_results(
			"SELECT id, first_name, last_name, city, operator_status, email_sent_at, review_left, created_at
			 FROM {$leads} ORDER BY created_at DESC LIMIT 30",
			ARRAY_A
		);
		$out = array();
		foreach ( (array) $rows as $r ) {
			$out[] = array(
				'id'      => (int) $r['id'],
				'name'    => trim( ( $r['first_name'] ?? '' ) . ' ' . ( $r['last_name'] ?? '' ) ),
				'city'    => (string) ( $r['city'] ?? '' ),
				'status'  => (string) ( $r['operator_status'] ?? '' ),
				'invited' => ! empty( $r['email_sent_at'] ),
				'reviewed' => ! empty( $r['review_left'] ),
				'date'    => (string) ( $r['created_at'] ?? '' ),
			);
		}
		wp_send_json_success( $out );
	}

	/** Run a batch: fetch settled invoices, screen, open follow-ups. */
	public static function ajax_run_batch(): void {
		self::guard();
		$manager = new ZSV_Survey_Manager();
		wp_send_json_success( $manager->run_batch() );
	}

	/** Sync operator notes for open leads (bounded to the sync window). */
	public static function ajax_sync(): void {
		self::guard();
		global $wpdb;
		$leads  = ZSV_DB::leads_table();
		$window = (int) get_option( 'zsv_sync_window_days', 60 );
		$sql    = "SELECT id, crm_lead_id FROM {$leads} WHERE crm_lead_id IS NOT NULL AND crm_lead_id <> ''";
		if ( $window > 0 ) {
			$sql .= $wpdb->prepare( ' AND created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)', $window );
		}
		$sql .= ' ORDER BY created_at DESC LIMIT 200';
		$rows = $wpdb->get_results( $sql, ARRAY_A );

		$manager = new ZSV_Survey_Manager();
		$results = $manager->sync_operator_notes( (array) $rows );
		foreach ( $results as $wp_id => $data ) {
			$wpdb->update(
				$leads,
				array(
					'operator_notes'  => (string) $data['operator_notes'],
					'operator_status' => (string) $data['operator_status'],
					'crm_synced_at'   => current_time( 'mysql' ),
				),
				array( 'id' => (int) $wp_id ),
				array( '%s', '%s', '%s' ),
				array( '%d' )
			);
		}
		wp_send_json_success( array( 'synced' => count( $results ) ) );
	}

	/**
	 * Send invites to the given lead ids. The status guard is enforced per lead by the
	 * manager (an id list cannot bypass it). Each successful invite closes the loop.
	 */
	public static function ajax_send_invites(): void {
		self::guard();
		$ids = isset( $_POST['lead_ids'] ) ? array_map( 'intval', (array) $_POST['lead_ids'] ) : array();
		if ( empty( $ids ) ) {
			wp_send_json_error( 'No leads selected' );
		}
		global $wpdb;
		$leads   = ZSV_DB::leads_table();
		$manager = new ZSV_Survey_Manager();
		$sent    = 0;
		$held    = array();
		$in      = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$rows    = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$leads} WHERE id IN ($in)", ...$ids ), ARRAY_A );

		foreach ( (array) $rows as $lead ) {
			// The invite guard is the state, not the request shape.
			if ( ! empty( $lead['email_sent_at'] ) ) {
				$held[] = array( 'id' => (int) $lead['id'], 'reason' => 'already_invited' );
				continue;
			}
			$res = $manager->send_invite( $lead );
			if ( ! empty( $res['sent'] ) ) {
				$wpdb->update(
					$leads,
					array( 'email_sent_at' => current_time( 'mysql' ), 'email_type' => (string) $res['channel'] ),
					array( 'id' => (int) $lead['id'] ),
					array( '%s', '%s' ),
					array( '%d' )
				);
				// Emailing is a satisfactory signal → close as Won with a reason.
				if ( ! empty( $lead['crm_lead_id'] ) ) {
					$manager->mark_lead_won_with_reason( (int) $lead['id'], (int) $lead['crm_lead_id'], 'emailed' );
				}
				$sent++;
			} else {
				$held[] = array( 'id' => (int) $lead['id'], 'reason' => (string) $res['reason'] );
			}
		}
		wp_send_json_success( array( 'sent' => $sent, 'held' => $held ) );
	}

	public static function ajax_exclude(): void {
		self::guard();
		$manager = new ZSV_Survey_Manager();
		$res     = $manager->exclude_customer_with_reason(
			array(
				'email'      => isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '',
				'name'       => isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '',
				'reason'     => isset( $_POST['reason'] ) ? sanitize_text_field( wp_unslash( $_POST['reason'] ) ) : '',
				'crm_lead_id' => isset( $_POST['crm_lead_id'] ) ? (int) $_POST['crm_lead_id'] : 0,
				'permanent'  => ! empty( $_POST['permanent'] ),
				'actor'      => wp_get_current_user()->display_name,
			)
		);
		if ( empty( $res['ok'] ) ) {
			wp_send_json_error( 'Nothing was recorded — a reason is required.' );
		}
		wp_send_json_success( $res );
	}
}
