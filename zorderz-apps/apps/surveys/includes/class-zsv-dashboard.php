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
		add_action( 'wp_ajax_zsv_send_invite', array( __CLASS__, 'ajax_send_invite' ) );
		add_action( 'wp_ajax_zsv_send_log', array( __CLASS__, 'ajax_send_log' ) );
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
		$stats   = $manager->run_batch();
		if ( ! empty( $stats['created'] ) ) {
			zsv_usage( 'survey_batch_created' );
		}
		wp_send_json_success( $stats );
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

	/** Seconds into a request after which no further customer's e-mail is started. */
	const SEND_SOFT_LIMIT_S = 30;

	/**
	 * Send ONE customer's review e-mail. The page sends one customer per request, in order,
	 * so a slow CRM on one customer never stops the rest. The status guard is enforced by the
	 * manager (an id cannot bypass it); the CRM's part runs after the e-mail under its own
	 * short budget, and what it could not do is owed, never lost.
	 *
	 * @return array{id:int,result:string,reason:string,crm:string}
	 */
	public static function send_one( ZSV_Survey_Manager $manager, int $lead_id ): array {
		global $wpdb;
		$leads = ZSV_DB::leads_table();
		$lead  = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$leads} WHERE id = %d", $lead_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $leads is $wpdb->prefix plus a fixed table name; the id is a placeholder.
		if ( ! $lead ) {
			return array( 'id' => $lead_id, 'result' => 'skipped', 'reason' => 'not_found', 'crm' => 'none' );
		}
		// The invite guard is the state, not the request shape.
		if ( ! empty( $lead['email_sent_at'] ) ) {
			return array( 'id' => $lead_id, 'result' => 'skipped', 'reason' => 'already_invited', 'crm' => 'none' );
		}
		$res = $manager->send_invite( $lead );
		if ( empty( $res['sent'] ) ) {
			$reason = (string) $res['reason'];
			return array( 'id' => $lead_id, 'result' => 'send_failed' === $reason ? 'failed' : 'skipped', 'reason' => $reason, 'crm' => 'none' );
		}
		$wpdb->update(
			$leads,
			array( 'email_sent_at' => current_time( 'mysql' ), 'email_type' => (string) $res['channel'] ),
			array( 'id' => $lead_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
		zsv_usage( 'survey_emails_sent', $lead_id );
		// Emailing is a satisfactory signal → close as Won with a reason (owed when it fails).
		$crm = ! empty( $lead['crm_lead_id'] ) ? $manager->after_send_bookkeeping( $lead_id, (int) $lead['crm_lead_id'], (string) $res['channel'] ) : 'none';
		return array( 'id' => $lead_id, 'result' => 'sent', 'reason' => '', 'crm' => $crm );
	}

	/** One customer per request (the widget sends them in order and shows "Sending 2 of 6"). */
	public static function ajax_send_invite(): void {
		self::guard();
		$id = isset( $_POST['lead_id'] ) ? (int) $_POST['lead_id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in self::guard(); the id is cast to int.
		if ( $id < 1 ) {
			wp_send_json_error( 'No lead selected' );
		}
		wp_send_json_success( self::send_one( new ZSV_Survey_Manager(), $id ) );
	}

	/**
	 * Several customers in one request (older callers). Each goes through send_one(); after
	 * SEND_SOFT_LIMIT_S no further customer is started, and those are answered "not reached".
	 */
	public static function ajax_send_invites(): void {
		self::guard();
		$ids = isset( $_POST['lead_ids'] ) ? array_values( array_unique( array_filter( array_map( 'intval', (array) $_POST['lead_ids'] ) ) ) ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in self::guard(); each id is cast to int.
		if ( empty( $ids ) ) {
			wp_send_json_error( 'No leads selected' );
		}
		$manager = new ZSV_Survey_Manager();
		$t0      = microtime( true );
		$out     = array();
		foreach ( $ids as $id ) {
			if ( microtime( true ) - $t0 > self::SEND_SOFT_LIMIT_S ) {
				$out[] = array( 'id' => $id, 'result' => 'not_reached', 'reason' => 'time', 'crm' => 'none' );
				continue;
			}
			$out[] = self::send_one( $manager, $id );
		}
		self::log_press( $out );
		$sent = count( array_filter( $out, function ( $r ) { return 'sent' === $r['result']; } ) );
		$held = array();
		foreach ( $out as $r ) {
			if ( 'sent' !== $r['result'] ) {
				$held[] = array( 'id' => $r['id'], 'reason' => $r['reason'] );
			}
		}
		wp_send_json_success( array( 'sent' => $sent, 'held' => $held, 'results' => $out ) );
	}

	/** The widget's summary of one press, logged as one line. */
	public static function ajax_send_log(): void {
		self::guard();
		$rows = isset( $_POST['results'] ) ? json_decode( wp_unslash( (string) $_POST['results'] ), true ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified in self::guard(); the JSON is decoded and log_press() keeps only ints and known reason codes.
		self::log_press( is_array( $rows ) ? $rows : array() );
		wp_send_json_success( array( 'logged' => true ) );
	}

	/**
	 * One log line per press: sent, skipped (with the reason, by lead number), failed, not
	 * reached. Lead numbers and reason codes only — never a name or an address.
	 */
	public static function log_press( array $rows ): string {
		$sent = $failed = $not = 0;
		$skipped = array();
		$crm_owed = 0;
		foreach ( array_slice( $rows, 0, 200 ) as $r ) {
			if ( ! is_array( $r ) ) {
				continue;
			}
			$id     = (int) ( $r['id'] ?? 0 );
			$result = preg_replace( '/[^a-z_]/', '', (string) ( $r['result'] ?? '' ) );
			$reason = strtolower( (string) ( $r['reason'] ?? '' ) );
			if ( ! preg_match( '/^(already_invited|not_found|no_email|no_review_destination|send_failed|time|request_failed|no_answer|status_holds_[a-z_]{1,30})$/', $reason ) ) {
				$reason = '' === $reason ? '' : 'other'; // the widget sends codes; anything else is never logged as written
			}
			if ( 'sent' === $result ) {
				$sent++;
				if ( 'owed' === ( $r['crm'] ?? '' ) ) {
					$crm_owed++;
				}
			} elseif ( 'failed' === $result ) {
				$failed++;
			} elseif ( 'not_reached' === $result ) {
				$not++;
			} else {
				$skipped[] = '#' . $id . ( '' !== $reason ? ' ' . $reason : '' );
			}
		}
		$line = sprintf(
			'Zorderz Surveys: survey e-mails — sent %d, skipped %d%s, failed %d, not reached %d%s',
			$sent,
			count( $skipped ),
			$skipped ? ' (' . implode( ', ', array_slice( $skipped, 0, 30 ) ) . ')' : '',
			$failed,
			$not,
			$crm_owed ? sprintf( '; %d Won mark(s) owed to the daily sweep', $crm_owed ) : ''
		);
		error_log( $line );
		return $line;
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
