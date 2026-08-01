<?php
/**
 * Zorderz Prep — AJAX endpoints backing the dashboard widget.
 *
 * Every write endpoint re-checks the nonce + real app-access (via ZDZ_Plugin_API, so the
 * shared kiosk can't reach the CRM write path). The heavy orchestration lives in the
 * engine / CRM / parser / billing adapters; this class is the transport.
 *
 * @package Zorderz\Prep
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZPREP_Dashboard {

	public static function init(): void {
		add_action( 'wp_ajax_zprep_lookup', array( __CLASS__, 'ajax_lookup' ) );
		add_action( 'wp_ajax_zprep_parse_measurements', array( __CLASS__, 'ajax_parse_measurements' ) );
		add_action( 'wp_ajax_zprep_compute_cuts', array( __CLASS__, 'ajax_compute_cuts' ) );
		add_action( 'wp_ajax_zprep_sync_crm', array( __CLASS__, 'ajax_sync_crm' ) );
		add_action( 'wp_ajax_zprep_approved_to_cut', array( __CLASS__, 'ajax_approved_to_cut' ) );
		add_action( 'wp_ajax_zprep_report_problem', array( __CLASS__, 'ajax_report_problem' ) );
		add_action( 'wp_ajax_zprep_test_crm', array( __CLASS__, 'ajax_test_crm' ) );
		add_action( 'wp_ajax_zprep_test_billing', array( __CLASS__, 'ajax_test_billing' ) );
		add_action( 'wp_ajax_zprep_test_ai', array( __CLASS__, 'ajax_test_ai' ) );
		add_action( 'wp_ajax_zprep_leftovers_list', array( __CLASS__, 'ajax_leftovers_list' ) );
		add_action( 'wp_ajax_zprep_leftovers_update_bin', array( __CLASS__, 'ajax_leftovers_update_bin' ) );
		add_action( 'wp_ajax_zprep_leftovers_discard', array( __CLASS__, 'ajax_leftovers_discard' ) );
		add_action( 'wp_ajax_zprep_leftovers_export_csv', array( __CLASS__, 'ajax_leftovers_export_csv' ) );
	}

	/**
	 * Server-authoritative app-access check. Uses the theme's canonical resolver so only
	 * users the platform would show the Prep tile to (owner/admin + explicitly-assigned
	 * roles) pass; the shared kiosk is locked out.
	 */
	public static function user_can_access( ?int $user_id = null ): bool {
		$user_id = $user_id ?? get_current_user_id();
		if ( ! $user_id ) {
			return false;
		}
		if ( is_callable( array( 'ZDZ_Plugin_API', 'user_can_access_app' ) ) ) {
			return ZDZ_Plugin_API::user_can_access_app( (int) $user_id, ZPREP_APP_ID );
		}
		return user_can( $user_id, 'manage_options' );
	}

	/** Guard: keep AJAX JSON clean, verify nonce, gate on real app-access. */
	private static function guard(): void {
		if ( ! headers_sent() ) {
			@ini_set( 'display_errors', '0' );
			while ( ob_get_level() > 0 ) {
				@ob_end_clean();
			}
			ob_start();
		}
		check_ajax_referer( ZPREP_NONCE, 'nonce' );
		if ( ! self::user_can_access() ) {
			wp_send_json_error( array( 'message' => __( 'Access denied.', 'zorderz' ) ), 403 );
		}
	}

	private static function admin_guard(): void {
		check_ajax_referer( ZPREP_NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Admin only.', 'zorderz' ) ), 403 );
		}
	}

	/* ================================================================
	 * 1. LOOKUP
	 * ================================================================ */
	public static function ajax_lookup(): void {
		self::guard();
		$query = isset( $_POST['query'] ) ? sanitize_text_field( wp_unslash( $_POST['query'] ) ) : '';
		if ( '' === $query ) {
			wp_send_json_error( array( 'message' => __( 'Please enter a search term.', 'zorderz' ) ) );
		}

		$crm = new ZPREP_Crm();
		if ( $crm->is_ready() ) {
			$all_leads = $crm->find_all_leads_for_query( $query );

			if ( count( $all_leads ) === 1 ) {
				$lead  = $all_leads[0];
				$block = $crm->pick_measurement_block( $lead['notes'] );
				$meta  = self::extract_job_meta_from_note( $block );
				wp_send_json_success(
					array(
						'source'    => 'crm',
						'query'     => $query,
						'lead_id'   => $lead['id'],
						'lead_desc' => $lead['description'],
						'customer'  => array(
							'name'            => $meta['customer'] ?? $lead['meta']['customer'] ?? $query,
							'email'           => $meta['email'] ?? '',
							'phone'           => $meta['phone'] ?? '',
							'address'         => $meta['address'] ?? '',
							'estimate_number' => $meta['estimate_number'] ?? $lead['meta']['estimate_number'] ?? '',
							'salesperson'     => $meta['salesperson'] ?? '',
						),
						'notes'     => $lead['notes'],
						'trace'     => $crm->get_last_trace(),
					)
				);
			}

			if ( count( $all_leads ) > 1 ) {
				$choices = array();
				foreach ( $all_leads as $lead ) {
					$choices[] = array(
						'lead_id'      => $lead['id'],
						'description'  => $lead['description'],
						'customer'     => $lead['meta']['customer'] ?? '',
						'city'         => $lead['meta']['city'] ?? '',
						'date'         => $lead['meta']['date'] ?? '',
						'cut_count' => (int) ( $lead['meta']['cut_count'] ?? 0 ),
						'estimate_num' => $lead['meta']['estimate_number'] ?? '',
						'notes'        => $lead['notes'],
					);
				}
				wp_send_json_success( array( 'source' => 'crm_multi', 'query' => $query, 'leads' => $choices, 'trace' => $crm->get_last_trace() ) );
			}
		}

		// Fallback: billing.
		$fb = new ZPREP_Billing();
		if ( ! $fb->is_ready() ) {
			$trace = $crm->is_ready() ? $crm->get_last_trace() : array( 'CRM not configured.' );
			wp_send_json_error( array( 'message' => __( 'No matching jobs found.', 'zorderz' ), 'trace' => $trace ) );
		}
		$result = $fb->search( $query );
		if ( 'ok' !== $result['status'] || empty( $result['matches'] ) ) {
			wp_send_json_error( array( 'message' => $result['message'] ?? __( 'No jobs found.', 'zorderz' ) ) );
		}
		wp_send_json_success( array( 'source' => 'billing', 'query' => $query, 'matches' => $result['matches'] ) );
	}

	private static function extract_job_meta_from_note( string $block ): array {
		$meta = array();
		if ( preg_match( '/Customer:\s*(.+)/i', $block, $m ) ) {
			$meta['customer'] = trim( $m[1] );
		}
		if ( preg_match( '/Address:\s*(.+)/i', $block, $m ) ) {
			$meta['address'] = trim( $m[1] );
		}
		if ( preg_match( '/Phone:\s*(.+)/i', $block, $m ) ) {
			$meta['phone'] = trim( $m[1] );
		}
		if ( preg_match( '/Email:\s*(\S+)/i', $block, $m ) ) {
			$meta['email'] = trim( $m[1] );
		}
		if ( preg_match( '/Estimate\s*#?:?\s*(\d+)/i', $block, $m ) ) {
			$meta['estimate_number'] = trim( $m[1] );
		}
		if ( preg_match( '/Salesperson:\s*(.+)/i', $block, $m ) ) {
			$meta['salesperson'] = trim( $m[1] );
		}
		return $meta;
	}

	/* ================================================================
	 * 2. PARSE MEASUREMENTS
	 * ================================================================ */
	public static function ajax_parse_measurements(): void {
		self::guard();
		$customer = json_decode( isset( $_POST['customer'] ) ? wp_unslash( $_POST['customer'] ) : '', true );
		if ( ! is_array( $customer ) ) {
			wp_send_json_error( array( 'message' => __( 'Missing customer payload.', 'zorderz' ) ) );
		}
		$cached_notes = json_decode( isset( $_POST['notes'] ) ? wp_unslash( $_POST['notes'] ) : '', true );

		$crm     = new ZPREP_Crm();
		$lead_id = isset( $_POST['lead_id'] ) ? (int) $_POST['lead_id'] : 0;
		$notes   = array();

		if ( is_array( $cached_notes ) && ! empty( $cached_notes ) ) {
			$notes = $cached_notes;
		} elseif ( $crm->is_ready() && $lead_id > 0 ) {
			$lead = $crm->get_lead_by_id( $lead_id );
			if ( $lead ) {
				$lead_id = $lead['id'];
				$notes   = $lead['notes'];
			}
		} elseif ( $crm->is_ready() ) {
			$lead = $crm->find_lead_for_customer( $customer );
			if ( $lead ) {
				$lead_id = $lead['id'];
				$notes   = $lead['notes'];
			}
		}

		if ( empty( $notes ) ) {
			wp_send_json_error( array( 'message' => __( 'No measurement notes found.', 'zorderz' ) ) );
		}

		$block = $crm->pick_measurement_block( $notes );
		if ( '' === $block ) {
			wp_send_json_error( array( 'message' => __( 'Lead found but no measurement notes posted yet.', 'zorderz' ) ) );
		}

		$parse_input = $crm->build_parse_input( $notes, $block );

		$parser = new ZPREP_Parser();
		if ( ! $parser->is_ready() ) {
			wp_send_json_error( array( 'message' => __( 'The AI service is not configured.', 'zorderz' ) ) );
		}
		$parsed = $parser->parse_measurements( $parse_input );
		if ( ! is_array( $parsed ) ) {
			wp_send_json_error( array( 'message' => __( 'AI parser returned no usable data.', 'zorderz' ) ) );
		}

		wp_send_json_success(
			array(
				'lead_id'             => $lead_id,
				'raw_note'            => $block,
				'parse_input'         => $parse_input,
				'applied_adjustments' => ( $parse_input !== $block ),
				'note_count'          => count( $notes ),
				'parsed'              => $parsed,
			)
		);
	}

	/* ================================================================
	 * 3. COMPUTE CUTS
	 * ================================================================ */
	public static function ajax_compute_cuts(): void {
		self::guard();
		$measurements = json_decode( isset( $_POST['measurements'] ) ? wp_unslash( $_POST['measurements'] ) : '', true );
		if ( ! is_array( $measurements ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid measurements.', 'zorderz' ) ) );
		}
		$use_leftovers = ! empty( $_POST['use_leftovers'] ) && '0' !== $_POST['use_leftovers'];
		$source_job    = isset( $_POST['source_job'] ) ? sanitize_text_field( wp_unslash( $_POST['source_job'] ) ) : '';
		$workspace     = isset( $_POST['workspace'] ) ? sanitize_text_field( wp_unslash( $_POST['workspace'] ) ) : 'flat';
		$force_roll    = isset( $_POST['force_roll'] ) ? (int) $_POST['force_roll'] : 0;
		$debug         = ! empty( $_POST['debug'] ) && '0' !== $_POST['debug'];

		$engine = new ZPREP_Engine();
		$plan   = $engine->compute_plan(
			$measurements,
			array(
				'use_leftovers' => $use_leftovers,
				'source_job'    => $source_job,
				'workspace'     => in_array( $workspace, array( 'flat', 'roller' ), true ) ? $workspace : 'flat',
				'force_roll'    => $force_roll,
				'debug'         => $debug,
			)
		);

		if ( '' !== $source_job ) {
			ZPREP_Leftovers::auto_log_from_plan( $plan, $source_job, get_current_user_id() );
		}
		wp_send_json_success( $plan );
	}

	/* ================================================================
	 * 4. SYNC TO CRM
	 * ================================================================ */
	public static function ajax_sync_crm(): void {
		self::guard();
		$lead_id = isset( $_POST['lead_id'] ) ? (int) $_POST['lead_id'] : 0;
		$body    = isset( $_POST['body'] ) ? wp_kses_post( wp_unslash( $_POST['body'] ) ) : '';
		if ( $lead_id <= 0 || '' === $body ) {
			wp_send_json_error( array( 'message' => __( 'Missing lead or note body.', 'zorderz' ) ) );
		}
		$crm = new ZPREP_Crm();
		if ( ! $crm->is_ready() ) {
			wp_send_json_error( array( 'message' => __( 'The CRM is not configured.', 'zorderz' ) ) );
		}
		$res = $crm->sync_completion( $lead_id, $body );

		if ( $res['note_ok'] && ! empty( $_POST['reserved_leftover_ids'] ) ) {
			$ids_raw = wp_unslash( $_POST['reserved_leftover_ids'] );
			$ids     = is_array( $ids_raw ) ? $ids_raw : json_decode( $ids_raw, true );
			$source  = isset( $_POST['source_job'] ) ? sanitize_text_field( wp_unslash( $_POST['source_job'] ) ) : '';
			if ( is_array( $ids ) ) {
				foreach ( $ids as $lid ) {
					ZPREP_Leftovers::commit_reservation( (int) $lid, $source );
				}
			}
		}

		if ( ! $res['note_ok'] ) {
			wp_send_json_error( array( 'message' => __( 'Failed to post note to the CRM.', 'zorderz' ) ) );
		}
		wp_send_json_success(
			array(
				'message'        => __( 'Synced to the CRM.', 'zorderz' ),
				'note_ok'        => $res['note_ok'],
				'advance_ok'     => $res['advance_ok'],
				'new_stage'      => $res['new_stage'],
				'advance_status' => $res['advance_status'],
				'errors'         => $res['errors'],
			)
		);
	}

	/* ================================================================
	 * 5. APPROVED TO CUT
	 * ================================================================ */
	public static function ajax_approved_to_cut(): void {
		self::guard();
		$crm = new ZPREP_Crm();
		if ( ! $crm->is_ready() ) {
			wp_send_json_success( array( 'configured' => false, 'stage' => ZPREP_Settings::cut_stage_name(), 'jobs' => array(), 'trace' => array( 'CRM not configured.' ) ) );
		}
		if ( '' === ZPREP_Settings::cut_stage_name() ) {
			wp_send_json_success( array( 'configured' => true, 'stage' => '', 'jobs' => array(), 'trace' => array( 'Cut stage not configured.' ) ) );
		}

		$jobs = $crm->list_leads_by_stage( 100 );

		// Optional billing ground-truth union (off by default).
		if ( 'yes' === get_option( 'zprep_billing_ground_truth', 'no' ) ) {
			if ( false === get_transient( 'zprep_billing_sync_ran' ) ) {
				set_transient( 'zprep_billing_sync_ran', 1, MINUTE_IN_SECONDS );
				$synced = ZPREP_Billing::run_approved_sync( 'widget-open' );
				if ( ! empty( $synced['moved'] ) ) {
					$jobs = $crm->list_leads_by_stage( 100 );
				}
			}
		}

		wp_send_json_success(
			array(
				'configured' => true,
				'stage'      => ZPREP_Settings::cut_stage_name(),
				'jobs'       => $jobs,
				'is_admin'   => current_user_can( 'manage_options' ),
				'trace'      => $crm->get_last_trace(),
			)
		);
	}

	/* ================================================================
	 * 6. ONE-CLICK PROBLEM REPORT (non-admin)
	 * ================================================================ */
	public static function ajax_report_problem(): void {
		self::guard();
		$user    = wp_get_current_user();
		$context = isset( $_POST['context'] ) ? sanitize_text_field( wp_unslash( $_POST['context'] ) ) : 'Prep';
		$detail  = isset( $_POST['detail'] ) ? sanitize_textarea_field( wp_unslash( $_POST['detail'] ) ) : '';
		$lead    = isset( $_POST['lead_id'] ) ? (int) $_POST['lead_id'] : 0;

		$when = current_time( 'mysql' );
		error_log( sprintf( '[Zorderz Prep] PROBLEM REPORT [%s] by %s (uid %d) context="%s" lead=%d detail="%s"', $when, $user->display_name ?: 'unknown', (int) $user->ID, $context, $lead, $detail ) );

		$emailed = false;
		$admin_email = get_option( 'admin_email', '' );
		if ( $admin_email ) {
			$body    = "A Prep user reported a problem.\n\nWhen: {$when}\nUser: " . ( $user->display_name ?: 'unknown' ) . "\nContext: {$context}\n" . ( $lead ? "Lead: #{$lead}\n" : '' ) . "\nNote:\n{$detail}\n";
			$emailed = (bool) wp_mail( $admin_email, __( 'Zorderz Prep — problem report', 'zorderz' ), $body );
		}
		wp_send_json_success( array( 'reported' => true, 'emailed' => $emailed, 'message' => __( 'Thanks — your report was sent to the admin.', 'zorderz' ) ) );
	}

	/* ================================================================
	 * DIAGNOSTICS (admin)
	 * ================================================================ */
	public static function ajax_test_crm(): void {
		self::admin_guard();
		$crm = new ZPREP_Crm();
		wp_send_json_success( array( 'result' => array( 'ok' => $crm->is_ready(), 'message' => $crm->is_ready() ? __( 'CRM configured.', 'zorderz' ) : __( 'CRM not configured (set credentials in Core Settings).', 'zorderz' ) ) ) );
	}

	public static function ajax_test_billing(): void {
		self::admin_guard();
		$fb = new ZPREP_Billing();
		wp_send_json_success( array( 'result' => array( 'ok' => $fb->is_ready(), 'message' => $fb->is_ready() ? __( 'Billing configured.', 'zorderz' ) : __( 'Billing not configured.', 'zorderz' ) ) ) );
	}

	public static function ajax_test_ai(): void {
		self::admin_guard();
		$ai = new ZPREP_Parser();
		wp_send_json_success( array( 'result' => array( 'ok' => $ai->is_ready(), 'message' => $ai->is_ready() ? __( 'AI service configured.', 'zorderz' ) : __( 'AI service not configured.', 'zorderz' ) ) ) );
	}

	/* ================================================================
	 * LEFTOVER INVENTORY
	 * ================================================================ */
	public static function ajax_leftovers_list(): void {
		self::guard();
		$filters = array(
			'material'   => isset( $_POST['material'] ) ? sanitize_text_field( wp_unslash( $_POST['material'] ) ) : '',
			'status'     => isset( $_POST['status'] ) ? sanitize_text_field( wp_unslash( $_POST['status'] ) ) : '',
			'min_width'  => isset( $_POST['min_width'] ) ? (float) $_POST['min_width'] : 0,
			'min_length' => isset( $_POST['min_length'] ) ? (float) $_POST['min_length'] : 0,
		);
		$rows = ZPREP_Leftovers::list_rows( $filters );
		$out  = array_map(
			function ( $r ) {
				return array(
					'id'            => (int) $r['id'],
					'created_at'    => $r['created_at'],
					'source_job'    => $r['source_job'],
					'material'      => $r['material'],
					'roll_width_in' => (int) $r['roll_width_in'],
					'width_in'      => (float) $r['width_in'],
					'length_in'     => (float) $r['length_in'],
					'bin_location'  => $r['bin_location'] ?? '',
					'status'        => $r['status'],
					'used_in_job'   => $r['used_in_job'] ?? '',
				);
			},
			$rows
		);
		wp_send_json_success( array( 'rows' => $out, 'count' => count( $out ) ) );
	}

	public static function ajax_leftovers_update_bin(): void {
		self::admin_guard();
		$id  = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		$bin = isset( $_POST['bin'] ) ? sanitize_text_field( wp_unslash( $_POST['bin'] ) ) : '';
		if ( $id <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'Missing id.', 'zorderz' ) ) );
		}
		if ( ! ZPREP_Leftovers::update_bin( $id, $bin ) ) {
			wp_send_json_error( array( 'message' => __( 'Update failed.', 'zorderz' ) ) );
		}
		wp_send_json_success( array( 'id' => $id, 'bin' => $bin ) );
	}

	public static function ajax_leftovers_discard(): void {
		self::admin_guard();
		$ids_raw = isset( $_POST['ids'] ) ? wp_unslash( $_POST['ids'] ) : '';
		$ids     = is_array( $ids_raw ) ? $ids_raw : json_decode( $ids_raw, true );
		if ( ! is_array( $ids ) || ! $ids ) {
			wp_send_json_error( array( 'message' => __( 'No ids.', 'zorderz' ) ) );
		}
		wp_send_json_success( array( 'discarded' => ZPREP_Leftovers::bulk_discard( $ids ) ) );
	}

	public static function ajax_leftovers_export_csv(): void {
		check_ajax_referer( ZPREP_NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Admin only.', 'zorderz' ) ), 403 );
		}
		ZPREP_Leftovers::stream_csv( array() );
	}
}
