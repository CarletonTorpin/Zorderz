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

	/**
	 * May this person see survey customers at all? An administrator, or someone the data
	 * permissions let see other people's customers; never the shared device. The one question
	 * every survey endpoint asks (the list, the sends, the Google reviews panel).
	 */
	public static function can_see( int $uid ): bool {
		if ( $uid < 1 ) {
			return false;
		}
		if ( class_exists( 'ZDZ_User_Roles' ) && method_exists( 'ZDZ_User_Roles', 'is_shared_device' ) && ZDZ_User_Roles::is_shared_device( $uid ) ) {
			return false;
		}
		if ( user_can( $uid, 'manage_options' ) ) {
			return true;
		}
		return class_exists( 'ZDZ_Data_Permissions' ) && method_exists( 'ZDZ_Data_Permissions', 'can' ) && (bool) ZDZ_Data_Permissions::can( $uid, 'view_others_data' );
	}

	/** Guard: valid nonce + operator/admin capability, or die with JSON. */
	private static function guard(): void {
		check_ajax_referer( ZSV_NONCE, 'nonce' );
		if ( ! self::can_see( get_current_user_id() ) ) {
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

	/** CRM states that mean a person has closed the lead (any outcome). */
	const CRM_CLOSED = array( 'won', 'lost', 'cancelled', 'canceled', 'deleted' );

	/**
	 * One row of the widget's list, from a survey row. `invited` is true when the customer was
	 * e-mailed by the row's own time, its send log, or the list of e-mails whose time the row would
	 * not take ($apart), so the page never offers Send for a customer the site would refuse.
	 * `closed` says the lead is closed in the CRM. With `invited` and `reviewed` it is what the page
	 * needs to know whether anybody has handled the customer yet. Pure.
	 */
	public static function recent_row( array $r, array $apart = array() ): array {
		$id      = (int) ( $r['id'] ?? 0 );
		$emailed = ZSV_Send_Once::emailed_at( $r );
		if ( '' === $emailed && isset( $apart[ $id ] ) ) {
			$emailed = (string) $apart[ $id ];
		}
		return array(
			'id'         => $id,
			'name'       => trim( ( $r['first_name'] ?? '' ) . ' ' . ( $r['last_name'] ?? '' ) ),
			'city'       => (string) ( $r['city'] ?? '' ),
			'status'     => (string) ( $r['operator_status'] ?? '' ),
			'invited'    => '' !== $emailed,
			'emailed_at' => $emailed,
			'reviewed'   => ! empty( $r['review_left'] ),
			'closed'     => in_array( strtolower( trim( (string) ( $r['crm_status'] ?? '' ) ) ), self::CRM_CLOSED, true ),
			'notes'      => trim( (string) ( $r['operator_notes'] ?? '' ) ),
			'work'       => trim( (string) ( $r['work_description'] ?? '' ) ),
			'date'       => (string) ( $r['created_at'] ?? '' ),
		);
	}

	public static function ajax_recent(): void {
		self::guard();
		global $wpdb;
		$leads = ZSV_DB::leads_table();
		$rows  = $wpdb->get_results(
			"SELECT id, first_name, last_name, city, operator_status, operator_notes, work_description, crm_status, email_sent_at, email_send_log, review_left, created_at
			 FROM {$leads} ORDER BY created_at DESC LIMIT 30",
			ARRAY_A
		);
		$apart = ZSV_Send_Once::unrecorded();
		$out   = array();
		foreach ( (array) $rows as $r ) {
			$out[] = self::recent_row( (array) $r, $apart );
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
	 * ONE E-MAIL PER CUSTOMER, UNLESS A PERSON ASKS AGAIN (ZSV_Send_Once). In order:
	 *   1. what holds an invite back whoever asks (no address, the call outcome, no review
	 *      destination): a skip, and the customer's lock is never taken;
	 *   2. the customer's send lock, without waiting: held elsewhere, or not answered by the
	 *      database, and nothing is sent;
	 *   3. the row read AGAIN under the lock, then the list of e-mails whose time a row would not
	 *      take: a customer already e-mailed is skipped unless $again;
	 *   4. a lead a person closed as lost or cancelled in the CRM is skipped;
	 *   5. the e-mail; then the time and the send log are written from the fresh copy. A write that
	 *      fails is tried once more, and failing that the customer is remembered apart;
	 *   6. the lock is let go, on every way out, before the CRM work.
	 *
	 * @param bool $again A person asked to e-mail this customer again (see asked_again()).
	 * @return array{id:int,result:string,reason:string,crm:string} plus, when they apply:
	 *   already_emailed + emailed_at (skipped: e-mailed before), in_progress (skipped: another
	 *   request is e-mailing them now), send_no (sent: which send this was), unrecorded (sent, but
	 *   the row would not take the time; the site remembers it and will refuse a second send).
	 */
	public static function send_one( ZSV_Survey_Manager $manager, int $lead_id, bool $again = false ): array {
		global $wpdb;
		$leads = ZSV_DB::leads_table();
		$lead  = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$leads} WHERE id = %d", $lead_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $leads is $wpdb->prefix plus a fixed table name; the id is a placeholder.
		$skip  = static function ( string $reason, array $more = array() ) use ( $lead_id ): array {
			return array( 'id' => $lead_id, 'result' => 'skipped', 'reason' => $reason, 'crm' => 'none' ) + $more;
		};
		if ( ! $lead ) {
			return $skip( 'not_found' );
		}
		// The invite guard is the state, not the request shape; it needs no lock.
		$hold = $manager->invite_hold( $lead );
		if ( '' !== $hold ) {
			return $skip( $hold );
		}

		$lock = ZSV_Send_Once::lock( $lead_id );
		if ( 1 !== $lock ) {
			if ( -1 === $lock ) {
				error_log( sprintf( 'Zorderz Surveys: survey e-mail — #%d NOT sent: the database did not answer the send lock.', $lead_id ) );
				return $skip( 'lock_unavailable' );
			}
			return $skip( 'send_in_progress', array( 'in_progress' => true ) );
		}

		$unrecorded = false;
		try {
			$fresh = $wpdb->get_row( $wpdb->prepare( "SELECT email_sent_at, email_send_log FROM {$leads} WHERE id = %d", $lead_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $leads is $wpdb->prefix plus a fixed table name; the id is a placeholder.
			if ( ! is_array( $fresh ) ) {
				return $skip( 'row_unreadable' ); // never sent on a guess
			}
			$lead['email_sent_at']  = $fresh['email_sent_at'] ?? null;
			$lead['email_send_log'] = $fresh['email_send_log'] ?? null;
			$log        = ZSV_Send_Once::send_log( $lead );
			$emailed_at = ZSV_Send_Once::emailed_at( $lead );
			if ( '' === $emailed_at ) {
				$apart = ZSV_Send_Once::unrecorded();
				if ( isset( $apart[ $lead_id ] ) ) {
					$emailed_at = (string) $apart[ $lead_id ];
				}
			}
			if ( '' !== $emailed_at && ! $again ) {
				return $skip( 'already_invited', array( 'already_emailed' => true, 'emailed_at' => $emailed_at ) );
			}
			// A send the log never took still counts, so a send a person asks for again is send #2.
			if ( '' !== $emailed_at && ! $log ) {
				$log = array( $emailed_at );
			}
			if ( in_array( strtolower( trim( (string) ( $lead['crm_status'] ?? '' ) ) ), array( 'lost', 'cancelled', 'canceled', 'deleted' ), true ) ) {
				return $skip( 'crm_closed' );
			}

			$res = $manager->send_invite( $lead );
			if ( empty( $res['sent'] ) ) {
				$reason = (string) $res['reason'];
				return array( 'id' => $lead_id, 'result' => 'send_failed' === $reason ? 'failed' : 'skipped', 'reason' => $reason, 'crm' => 'none' );
			}

			$now   = current_time( 'mysql' );
			$log[] = $now;
			$data  = array( 'email_sent_at' => $now, 'email_type' => (string) $res['channel'], 'email_send_log' => wp_json_encode( $log ) );
			$wrote = $wpdb->update( $leads, $data, array( 'id' => $lead_id ), array( '%s', '%s', '%s' ), array( '%d' ) );
			if ( false === $wrote ) {
				$wrote = $wpdb->update( $leads, $data, array( 'id' => $lead_id ), array( '%s', '%s', '%s' ), array( '%d' ) );
			}
			if ( false === $wrote ) {
				// The e-mail went and the row would not take its time: remembered apart, while the
				// lock is still held, so the next press is refused all the same.
				$unrecorded = true;
				ZSV_Send_Once::unrecorded_add( $lead_id, $now );
				error_log( sprintf( 'Zorderz Surveys: survey e-mail — #%d WAS E-MAILED but the time could not be written to its row (tried twice). It is remembered as e-mailed and will not be sent again; check the row.', $lead_id ) );
			} else {
				ZSV_Send_Once::unrecorded_forget( $lead_id );
			}
		} finally {
			ZSV_Send_Once::unlock( $lead_id );
		}

		$send_no = count( $log );
		if ( $again && $send_no > 1 ) {
			error_log( sprintf( 'Zorderz Surveys: survey e-mail — #%d e-mailed AGAIN because a person asked (send #%d).', $lead_id, $send_no ) );
		}
		zsv_usage( 'survey_emails_sent', $lead_id );
		// Emailing is a satisfactory signal → close as Won with a reason (owed when it fails).
		$crm = ! empty( $lead['crm_lead_id'] ) ? $manager->after_send_bookkeeping( $lead_id, (int) $lead['crm_lead_id'], (string) $res['channel'], $send_no ) : 'none';
		$out = array( 'id' => $lead_id, 'result' => 'sent', 'reason' => '', 'crm' => $crm, 'send_no' => $send_no );
		if ( $unrecorded ) {
			$out['unrecorded'] = true;
		}
		return $out;
	}

	/**
	 * The customers of this request a person asked to e-mail AGAIN: the request's `send_again`,
	 * counted only for customers the same request names, and only when the person asking is an
	 * administrator who may see survey customers (the one screen that asks the question is
	 * theirs; never the shared device). For anyone else it is ignored, and a customer already
	 * e-mailed is skipped as usual.
	 *
	 * @param int[] $lead_ids The customers this request is for.
	 * @param mixed $raw      The request's send_again value.
	 * @param int   $uid      Who is asking.
	 * @return int[]
	 */
	public static function asked_again( array $lead_ids, $raw, int $uid ): array {
		if ( null === $raw || '' === $raw || ! user_can( $uid, 'manage_options' ) || ! self::can_see( $uid ) ) {
			return array();
		}
		return ZSV_Send_Once::named_again( $raw, $lead_ids );
	}

	/** One customer per request (the widget sends them in order and shows "Sending 2 of 6"). */
	public static function ajax_send_invite(): void {
		self::guard();
		$id = isset( $_POST['lead_id'] ) ? (int) $_POST['lead_id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in self::guard(); the id is cast to int.
		if ( $id < 1 ) {
			wp_send_json_error( 'No lead selected' );
		}
		$raw   = isset( $_POST['send_again'] ) ? wp_unslash( $_POST['send_again'] ) : null; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified in self::guard(); asked_again() keeps whole numbers only.
		$again = self::asked_again( array( $id ), $raw, get_current_user_id() );
		wp_send_json_success( self::send_one( new ZSV_Survey_Manager(), $id, in_array( $id, $again, true ) ) );
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
		$raw     = isset( $_POST['send_again'] ) ? wp_unslash( $_POST['send_again'] ) : null; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified in self::guard(); asked_again() keeps whole numbers only.
		$again   = self::asked_again( $ids, $raw, get_current_user_id() );
		$manager = new ZSV_Survey_Manager();
		$t0      = microtime( true );
		$out     = array();
		foreach ( $ids as $id ) {
			if ( microtime( true ) - $t0 > self::SEND_SOFT_LIMIT_S ) {
				$out[] = array( 'id' => $id, 'result' => 'not_reached', 'reason' => 'time', 'crm' => 'none' );
				continue;
			}
			$out[] = self::send_one( $manager, $id, in_array( $id, $again, true ) );
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
			if ( ! preg_match( '/^(already_invited|send_in_progress|lock_unavailable|row_unreadable|crm_closed|not_found|no_email|no_review_destination|send_failed|time|request_failed|no_answer|status_holds_[a-z_]{1,30})$/', $reason ) ) {
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
			'Zorderz Surveys: survey e-mails sent %d, skipped %d%s, failed %d, not reached %d%s',
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
			wp_send_json_error( 'Nothing was recorded: a reason is required.' );
		}
		wp_send_json_success( $res );
	}
}
