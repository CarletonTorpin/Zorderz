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
		add_action( 'wp_ajax_zprep_cut_state_get', array( __CLASS__, 'ajax_cut_state_get' ) );
		add_action( 'wp_ajax_zprep_cut_state_mark', array( __CLASS__, 'ajax_cut_state_mark' ) );
		add_action( 'wp_ajax_zprep_cut_state_reopen', array( __CLASS__, 'ajax_cut_state_reopen' ) );
		add_action( 'wp_ajax_zprep_section_save', array( __CLASS__, 'ajax_section_save' ) );
		add_action( 'wp_ajax_zprep_section_list', array( __CLASS__, 'ajax_section_list' ) );
		add_action( 'wp_ajax_zprep_section_delete', array( __CLASS__, 'ajax_section_delete' ) );
		add_action( 'wp_ajax_zprep_remake_create', array( __CLASS__, 'ajax_remake_create' ) );
		add_action( 'wp_ajax_zprep_remake_parse', array( __CLASS__, 'ajax_remake_parse' ) );
		add_action( 'wp_ajax_zprep_remakes_list', array( __CLASS__, 'ajax_remakes_list' ) );
		add_action( 'wp_ajax_zprep_remake_set_status', array( __CLASS__, 'ajax_remake_set_status' ) );
		add_action( 'wp_ajax_zprep_assign', array( __CLASS__, 'ajax_assign' ) );
		add_action( 'wp_ajax_zprep_unassign', array( __CLASS__, 'ajax_unassign' ) );
		add_action( 'wp_ajax_zprep_users_list', array( __CLASS__, 'ajax_users_list' ) );
	}

	/**
	 * Prep access WITHOUT any job-assignment grant: the person's own role/app grants. Used to
	 * tell a team member from a person who only holds assignments.
	 */
	public static function base_access( int $user_id ): bool {
		if ( ! class_exists( 'ZPREP_Assignments' ) ) {
			return self::user_can_access( $user_id );
		}
		return (bool) ZPREP_Assignments::without_grants( static function () use ( $user_id ) {
			return self::user_can_access( $user_id );
		} );
	}

	/**
	 * A person who only holds job assignments may work only those jobs: refuse any other.
	 * Managers and team members pass.
	 */
	private static function enforce_job_scope( int $lead_id, string $source_job ): void {
		$uid = (int) get_current_user_id();
		if ( 'assignee' !== self::queue_mode( $uid ) ) {
			return;
		}
		// A job may be assigned under its estimate number or its CRM lead: either key counts.
		$keys = array();
		if ( class_exists( 'ZPREP_Assignments' ) ) {
			$keys[] = ZPREP_Assignments::job_key_for( $lead_id, $source_job );
			if ( $lead_id > 0 ) {
				$keys[] = 'lead:' . $lead_id;
			}
		}
		foreach ( array_unique( array_filter( $keys ) ) as $key ) {
			if ( ZPREP_Assignments::viewer_assigned_to_job( $uid, $key ) ) {
				return;
			}
		}
		wp_send_json_error( array( 'message' => __( 'You can only work the jobs assigned to you.', 'zorderz' ) ), 403 );
	}

	/** Manager-only endpoints (owner/admin level), on top of the normal guard. */
	private static function manager_guard(): void {
		self::guard();
		if ( ! self::is_manager( (int) get_current_user_id() ) ) {
			wp_send_json_error( array( 'message' => __( 'Only a manager can do that.', 'zorderz' ) ), 403 );
		}
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
		if ( 'assignee' === self::queue_mode( (int) get_current_user_id() ) ) {
			wp_send_json_error( array( 'message' => __( 'You can only work the jobs assigned to you.', 'zorderz' ) ), 403 );
		}
		$query = isset( $_POST['query'] ) ? sanitize_text_field( wp_unslash( $_POST['query'] ) ) : '';
		if ( '' === $query ) {
			wp_send_json_error( array( 'message' => __( 'Please enter a search term.', 'zorderz' ) ) );
		}

		$crm = new ZPREP_Crm();
		if ( $crm->is_ready() ) {
			$all_leads = $crm->find_all_leads_for_query( $query );

			if ( count( $all_leads ) === 1 ) {
				$lead      = $all_leads[0];
				$block     = $crm->pick_measurement_block( $lead['notes'] );
				$meta      = self::extract_job_meta_from_note( $block );
				$est_num   = $meta['estimate_number'] ?? $lead['meta']['estimate_number'] ?? '';
				// Server-bake the install date (both arms) so the cut sheet's Install line paints
				// without a second round trip. DISPLAY-ONLY; degrades to null when jobs is absent.
				$install   = ZPREP_Install_Date::for_reference( (int) $lead['id'], (string) $est_num, (int) get_current_user_id() );
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
							'estimate_number' => $est_num,
							'salesperson'     => $meta['salesperson'] ?? '',
						),
						'install'   => $install,
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
				// Server-bake the install date onto each picker row (both arms, one batch).
				// DISPLAY-ONLY: annotate never reorders/filters the choices.
				$choices = ZPREP_Install_Date::annotate_jobs( $choices, (int) get_current_user_id() );
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
		// Server-bake the install date onto each billing match (estimate arm only — a billing
		// record carries no CRM lead id). DISPLAY-ONLY; degrades to null when jobs is absent.
		$viewer  = (int) get_current_user_id();
		$matches = array();
		foreach ( (array) $result['matches'] as $match ) {
			if ( is_array( $match ) ) {
				$match['install_fallback'] = ZPREP_Install_Date::for_reference( 0, (string) ( $match['number'] ?? '' ), $viewer );
			}
			$matches[] = $match;
		}
		wp_send_json_success( array( 'source' => 'billing', 'query' => $query, 'matches' => $matches ) );
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
		self::enforce_job_scope( $lead_id, isset( $_POST['source_job'] ) ? sanitize_text_field( wp_unslash( $_POST['source_job'] ) ) : (string) ( $customer['estimate_number'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce and app access checked in self::guard() above.
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

		$lead_id = isset( $_POST['lead_id'] ) ? (int) $_POST['lead_id'] : 0;
		self::enforce_job_scope( $lead_id, $source_job );

		// The job's field-measured sections join the plan from the server's own record (never from the browser), so a
		// run measured on one phone is cut from on another.
		$measurements = array_values( array_filter( $measurements, static function ( $m ) {
			return ! is_array( $m ) || 'section' !== strtolower( (string) ( $m['measure'] ?? '' ) );
		} ) );
		if ( class_exists( 'ZPREP_Sections' ) && ( $lead_id > 0 || '' !== $source_job ) ) {
			foreach ( ZPREP_Sections::for_job( $lead_id, $source_job ) as $sec ) {
				foreach ( ZPREP_Sections::to_measurements( $sec ) as $m ) {
					$measurements[] = $m;
				}
			}
		}

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
		// The job's cut-state rows follow its latest plan (totals; cut counts are kept).
		if ( class_exists( 'ZPREP_Cut_State' ) && ( $lead_id > 0 || '' !== $source_job ) ) {
			$plan['cut_state'] = ZPREP_Cut_State::sync_from_plan( $lead_id, $source_job, $plan )['state'];
		}
		zprep_usage( 'cut_plan_computed', $lead_id, array( 'once' => 'day' ) );
		// Remember this job's plan for its queue card (keyed per job, never by name alone).
		$customer = isset( $_POST['customer_name'] ) ? sanitize_text_field( wp_unslash( $_POST['customer_name'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce and app access checked in self::guard() above.
		if ( class_exists( 'ZPREP_Plan_Preview' ) && '' !== $customer ) {
			ZPREP_Plan_Preview::remember(
				$customer,
				isset( $_POST['customer_id'] ) ? sanitize_text_field( wp_unslash( $_POST['customer_id'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce and app access checked in self::guard() above.
				$plan,
				isset( $_POST['card_ref'] ) ? sanitize_text_field( wp_unslash( $_POST['card_ref'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce and app access checked in self::guard() above.
				$source_job,
				$lead_id
			);
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
		self::enforce_job_scope( $lead_id, isset( $_POST['source_job'] ) ? sanitize_text_field( wp_unslash( $_POST['source_job'] ) ) : '' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce and app access checked in self::guard() above.
		if ( $lead_id <= 0 || '' === $body ) {
			wp_send_json_error( array( 'message' => __( 'Missing lead or note body.', 'zorderz' ) ) );
		}
		$crm = new ZPREP_Crm();
		if ( ! $crm->is_ready() ) {
			wp_send_json_error( array( 'message' => __( 'The CRM is not configured.', 'zorderz' ) ) );
		}
		// "Finished" means the shop's bench work is done: every bench item of the job is
		// marked cut. Field runs keep their own state (they are cut on site).
		$source_sync = isset( $_POST['source_job'] ) ? sanitize_text_field( wp_unslash( $_POST['source_job'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce and app access checked in self::guard() above.
		self::mark_bench_complete( $lead_id, $source_sync );
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
		zprep_usage( 'cut_completed', $lead_id );
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
		$q = self::build_queue( (int) get_current_user_id(), true );
		wp_send_json_success(
			array(
				'configured' => $q['configured'],
				'stage'      => $q['stage'],
				'jobs'       => $q['jobs'],
				'mode'       => self::queue_mode( (int) get_current_user_id() ),
				'is_admin'   => current_user_can( 'manage_options' ),
				'trace'      => $q['trace'],
			)
		);
	}

	/**
	 * The Approved-to-Cut queue for one person: the CRM cut stage, the billing invoice arm
	 * (de-duplicated), the billing chain (paperwork date guess, done/paid), the per-job plan
	 * preview, the install date (jobs answer first) and, for a person who only holds job
	 * assignments, only their own jobs plus their remakes. Read-only apart from the optional
	 * billing ground-truth sync, which runs only when $self_heal is true.
	 *
	 * @return array{configured:bool,stage:string,jobs:array,trace:array}
	 */
	public static function build_queue( int $me, bool $self_heal, bool $with_ts = false ): array {
		$crm  = new ZPREP_Crm();
		$mode = self::queue_mode( $me );
		$out  = array( 'configured' => true, 'stage' => ZPREP_Settings::cut_stage_name(), 'jobs' => array(), 'trace' => array() );
		if ( ! $crm->is_ready() ) {
			$out['configured'] = false;
			$out['trace']      = array( 'CRM not configured.' );
			return self::finish_queue( $out, $me, $mode );
		}
		if ( '' === ZPREP_Settings::cut_stage_name() ) {
			$out['trace'] = array( 'Cut stage not configured.' );
			return self::finish_queue( $out, $me, $mode );
		}

		$jobs = $crm->list_leads_by_stage( 100 );

		// Optional billing ground-truth union (off by default).
		if ( $self_heal && 'yes' === get_option( 'zprep_billing_ground_truth', 'no' ) ) {
			if ( false === get_transient( 'zprep_billing_sync_ran' ) ) {
				set_transient( 'zprep_billing_sync_ran', 1, MINUTE_IN_SECONDS );
				$synced = ZPREP_Billing::run_approved_sync( 'widget-open' );
				if ( ! empty( $synced['moved'] ) ) {
					$jobs = $crm->list_leads_by_stage( 100 );
				}
			}
		}

		// INVOICE ARM — fold recent QUEUE invoices in, deduped against the CRM cards. A job that
		// HAS an invoice but whose estimate never read accepted/invoiced still surfaces, and never
		// as a second card for a job already shown (classify_invoice_card). An invoice dedupes only
		// against OTHER-SOURCE (CRM) cards by customer, and against another invoice only when they
		// share a billing estimate id — so two distinct invoices for one customer stay two cards.
		// Degrades to nothing when billing is not configured (list returns []).
		$fbc       = new ZPREP_Billing();
		$seen_cust = array();
		$shown     = array(); // [ name, cid ] pairs for the fuzzy fallback
		$seen_est  = array(); // billing estimate ids shown by the invoice arm (invoice-vs-invoice de-dup)
		foreach ( $jobs as $j ) {
			$cid0 = (string) ( $j['customer_id'] ?? '' );
			if ( '' !== $cid0 ) { $seen_cust[ 'c:' . $cid0 ] = true; }
			$nm0 = self::norm_name( (string) ( $j['customer'] ?? '' ) );
			if ( '' !== $nm0 ) { $seen_cust[ 'n:' . $nm0 ] = true; }
			$shown[] = array( (string) ( $j['customer'] ?? '' ), $cid0 );
		}
		$possible_days = (int) apply_filters( 'zprep_atc_possible_days', 30 );
		$invoices      = $fbc->list_recent_queue_invoices();
		foreach ( $invoices as $inv ) {
			$inum = (string) ( $inv['invoice_number'] ?? '' );
			$cid  = (string) ( $inv['customer_id'] ?? '' );
			$name = (string) ( $inv['customer_name'] ?? '' );
			$nm   = self::norm_name( $name );
			$eid  = (string) ( $inv['estimateid'] ?? '' );
			if ( ! empty( $inv['installed'] ) ) { continue; } // done (receipted) — drop
			$rel = self::classify_invoice_card( $name, $cid, $nm, $eid, $seen_cust, $shown, $seen_est );
			if ( 'other_source' === $rel || 'dup_invoice' === $rel ) { continue; } // deduped
			$possible = empty( $inv['scheduled'] );
			if ( $possible && $possible_days > 0 ) {
				$age = self::invoice_age_days( (string) ( $inv['created_at'] ?? '' ) );
				if ( $age !== null && $age > $possible_days ) { continue; } // stale possible — drop
			}
			if ( '' !== $eid ) { $seen_est[ $eid ] = true; }
			$jobs[] = array(
				'lead_id'            => 0,
				'customer'           => $name,
				'customer_id'        => $cid,
				'description'        => 'Invoice #' . $inum . ' — ' . $name,
				'estimate_number'    => $inum, // tap/lookup key — an invoice # resolves too
				'city'               => '',
				'date'               => '',
				'screen_count'       => 0,
				'mesh'               => null,
				'fb_pending'         => true,
				'fb_possible'        => $possible,
				'install_fallback'   => null,
				'billing_source'     => 'invoice',
				'billing_estimateid' => $eid,
				'fb_note'            => $possible ? __( 'Possible — not yet approved', 'zorderz' ) : __( 'Invoiced · not yet cut', 'zorderz' ),
			);
		}

		// BILLING CHAIN — the newest billing document of each card gives its paperwork date
		// guess and its done/paid state. Display and retirement only; the jobs app's date
		// still wins (ZPREP_Install_Date::precedence()).
		if ( class_exists( 'ZPREP_Billing_Chain' ) && $fbc->is_ready() ) {
			$chain = ZPREP_Billing_Chain::apply( $jobs, $fbc->list_approved_queue_estimates( 60 ), $invoices );
			$jobs  = $chain['jobs'];
			$out['trace'] = array_merge( $out['trace'], $chain['trace'] );
		}

		// PLAN PREVIEW — each card's own last plan (pieces · area), matched per job; a customer
		// with two jobs on the queue never shows one job's plan on the other's card.
		if ( class_exists( 'ZPREP_Plan_Preview' ) ) {
			$jobs = ZPREP_Plan_Preview::annotate_jobs( $jobs );
		}

		// FIRST-PAINT SERVER BAKE: resolve the install date for the whole queue via the
		// two published resolver arms (both lead + estimate keys) and write install_fallback onto
		// each card, so the install chip paints on first render with no second async round trip.
		// DISPLAY-ONLY: this ADDS a key and never reorders/filters/drops a card (install is not a
		// gate or a sort). Degrades to no install line when the jobs resolver is absent.
		$out['jobs']  = ZPREP_Install_Date::annotate_jobs( $jobs, $me, $with_ts );
		$out['trace'] = array_merge( $out['trace'], $crm->get_last_trace() );
		return self::finish_queue( $out, $me, $mode );
	}

	/**
	 * Narrow the queue to what this person may see. A person who only holds job assignments
	 * sees only the jobs assigned to them; a job assigned to someone else is hidden from other
	 * non-managers. Remake cards are added for the person's own remakes (managers see every
	 * open remake). Cards are never reordered.
	 */
	private static function finish_queue( array $out, int $me, string $mode ): array {
		$jobs = $out['jobs'];
		if ( class_exists( 'ZPREP_Assignments' ) ) {
			$jobs = ZPREP_Assignments::filter_cards( $jobs, $me, $mode );
		}
		if ( class_exists( 'ZPREP_Remakes' ) ) {
			$jobs = array_merge( $jobs, ZPREP_Remakes::cards_for( $me, 'manager' === $mode ) );
		}
		$out['jobs'] = array_values( $jobs );
		if ( ! $out['configured'] && ! empty( $out['jobs'] ) ) {
			$out['configured'] = true; // remake cards still show without a CRM
		}
		return $out;
	}

	/**
	 * How this person uses the queue: 'manager' (owner/admin level: sees everything, assigns
	 * and manages remakes), 'team' (has Prep access: sees every job not assigned to someone
	 * else), 'assignee' (has no Prep access of their own, only job assignments: sees only their
	 * jobs), or 'none'.
	 */
	public static function queue_mode( int $uid ): string {
		if ( $uid <= 0 ) {
			return 'none';
		}
		if ( self::is_manager( $uid ) ) {
			return 'manager';
		}
		if ( self::base_access( $uid ) ) {
			return 'team';
		}
		if ( class_exists( 'ZPREP_Assignments' ) && ZPREP_Assignments::has_live_grant( $uid ) ) {
			return 'assignee';
		}
		return 'none';
	}

	/** Owner/admin level: may assign jobs and manage remakes. */
	public static function is_manager( int $uid ): bool {
		if ( $uid <= 0 ) {
			return false;
		}
		if ( user_can( $uid, 'manage_options' ) ) {
			return true;
		}
		$u = get_userdata( $uid );
		if ( $u && class_exists( 'ZDZ_User_Roles' ) && method_exists( 'ZDZ_User_Roles', 'is_admin_role' ) ) {
			foreach ( (array) $u->roles as $r ) {
				if ( ZDZ_User_Roles::is_admin_role( (string) $r ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/* ================================================================
	 * 5b. CUT STATE
	 * ================================================================ */

	private static function job_args(): array {
		return array(
			isset( $_POST['lead_id'] ) ? (int) $_POST['lead_id'] : 0, // phpcs:ignore WordPress.Security.NonceVerification.Missing -- private helper, called only after self::guard() checked the nonce.
			isset( $_POST['source_job'] ) ? sanitize_text_field( wp_unslash( $_POST['source_job'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Missing -- private helper, called only after self::guard() checked the nonce.
		);
	}

	/** Mark every bench item of a job cut (the shop finished its part). */
	private static function mark_bench_complete( int $lead_id, string $source_job ): void {
		if ( ! class_exists( 'ZPREP_Cut_State' ) ) {
			return;
		}
		$bench = array();
		foreach ( ZPREP_Cut_State::rows_for_job( ZPREP_Cut_State::resolve_job_key( $lead_id, $source_job ) ) as $row ) {
			if ( ZPREP_Cut_State::CLASS_BENCH !== (string) $row['work_class'] ) {
				continue;
			}
			$bench[] = array(
				'work_class' => ZPREP_Cut_State::CLASS_BENCH,
				'item_key'   => (string) $row['item_key'],
				'item_label' => (string) $row['item_label'],
				'qty_total'  => (int) $row['qty_total'],
				'qty_cut'    => (int) $row['qty_total'],
			);
		}
		if ( $bench ) {
			ZPREP_Cut_State::mark_cut( $lead_id, $source_job, $bench, (int) get_current_user_id(), ZPREP_Cut_State::WHERE_SHOP );
		}
	}

	public static function ajax_cut_state_get(): void {
		self::guard();
		list( $lead_id, $source_job ) = self::job_args();
		self::enforce_job_scope( $lead_id, $source_job );
		wp_send_json_success( array( 'state' => ZPREP_Cut_State::for_job( $lead_id, $source_job ) ) );
	}

	public static function ajax_cut_state_mark(): void {
		self::guard();
		list( $lead_id, $source_job ) = self::job_args();
		self::enforce_job_scope( $lead_id, $source_job );
		$items = json_decode( isset( $_POST['items'] ) ? (string) wp_unslash( $_POST['items'] ) : '[]', true ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce checked in self::guard() above; each decoded item is validated and cast in ZPREP_Cut_State::mark_cut().
		if ( ! is_array( $items ) || empty( $items ) || count( $items ) > 200 ) {
			wp_send_json_error( array( 'message' => __( 'Nothing to mark.', 'zorderz' ) ) );
		}
		$where = ( isset( $_POST['where'] ) && 'site' === $_POST['where'] ) ? ZPREP_Cut_State::WHERE_SITE : ZPREP_Cut_State::WHERE_SHOP; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked in self::guard() above; the value is only compared to a literal.
		$res   = ZPREP_Cut_State::mark_cut( $lead_id, $source_job, $items, (int) get_current_user_id(), $where );
		if ( $res['changed'] > 0 ) {
			zprep_usage( 'cut_marked', $lead_id );
		}
		wp_send_json_success( $res );
	}

	public static function ajax_cut_state_reopen(): void {
		self::guard();
		list( $lead_id, $source_job ) = self::job_args();
		self::enforce_job_scope( $lead_id, $source_job );
		$keys = json_decode( isset( $_POST['item_keys'] ) ? (string) wp_unslash( $_POST['item_keys'] ) : '[]', true ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce checked in self::guard() above; each decoded key is cast to a string, capped and used only as a prepared value in ZPREP_Cut_State::reopen().
		if ( ! is_array( $keys ) || empty( $keys ) ) {
			wp_send_json_error( array( 'message' => __( 'Nothing to reopen.', 'zorderz' ) ) );
		}
		wp_send_json_success( ZPREP_Cut_State::reopen( $lead_id, $source_job, array_slice( $keys, 0, 200 ), (int) get_current_user_id() ) );
	}

	/* ================================================================
	 * 5b2. SECTIONS (field measurements of a long run)
	 * ================================================================ */

	public static function ajax_section_save(): void {
		self::guard();
		list( $lead_id, $source_job ) = self::job_args();
		self::enforce_job_scope( $lead_id, $source_job );
		$t   = static function ( string $k ): string {
			return isset( $_POST[ $k ] ) ? sanitize_text_field( wp_unslash( $_POST[ $k ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- this reader runs after self::guard() above checked the nonce.
		};
		$res = ZPREP_Sections::save(
			array(
				'id'            => isset( $_POST['id'] ) ? (int) $_POST['id'] : 0, // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce and app access checked in self::guard() above.
				'lead_id'       => $lead_id,
				'source_job'    => $source_job,
				'name'          => $t( 'name' ),
				'kind'          => $t( 'kind' ),
				'color'         => $t( 'color' ),
				'run_length_in' => $t( 'run_length_in' ),
				'run_height_in' => $t( 'run_height_in' ),
				'panels'        => isset( $_POST['panels'] ) ? json_decode( (string) wp_unslash( $_POST['panels'] ), true ) : array(), // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce checked in self::guard() above; the decoded panel list is validated and cast in ZPREP_Sections::clean_panels().
				'notes'         => isset( $_POST['notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['notes'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce and app access checked in self::guard() above.
			),
			(int) get_current_user_id()
		);
		if ( empty( $res['ok'] ) ) {
			wp_send_json_error( array( 'message' => (string) ( $res['message'] ?? __( 'The section could not be saved.', 'zorderz' ) ) ) );
		}
		zprep_usage( 'section_saved', $lead_id );
		wp_send_json_success( $res );
	}

	public static function ajax_section_list(): void {
		self::guard();
		list( $lead_id, $source_job ) = self::job_args();
		self::enforce_job_scope( $lead_id, $source_job );
		wp_send_json_success( array( 'sections' => ZPREP_Sections::for_job( $lead_id, $source_job ), 'grace_in' => ZPREP_Sections::grace_in() ) );
	}

	public static function ajax_section_delete(): void {
		self::guard();
		list( $lead_id, $source_job ) = self::job_args();
		self::enforce_job_scope( $lead_id, $source_job );
		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		if ( $id <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'Missing section.', 'zorderz' ) ) );
		}
		wp_send_json_success( array( 'deleted' => ZPREP_Sections::delete( $id, $lead_id, $source_job ) ) );
	}

	/* ================================================================
	 * 5c. REMAKES + ASSIGNMENTS (managers)
	 * ================================================================ */

	private static function read_remake_args(): array {
		$rows = json_decode( isset( $_POST['pieces'] ) ? (string) wp_unslash( $_POST['pieces'] ) : '[]', true ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- private helper, called only after self::manager_guard(); the decoded pieces are validated and cast in ZPREP_Remakes::normalize_pieces().
		$t    = static function ( string $k ): string {
			return isset( $_POST[ $k ] ) ? sanitize_text_field( wp_unslash( $_POST[ $k ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- private helper, called only after self::manager_guard() checked the nonce.
		};
		return array(
			'parent_job_key' => $t( 'parent_job_key' ),
			'parent_label'   => $t( 'parent_label' ),
			'customer_label' => $t( 'customer_label' ),
			'reason_code'    => sanitize_key( $t( 'reason_code' ) ),
			'reason_text'    => $t( 'reason_text' ),
			'standard_note'  => isset( $_POST['standard_note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['standard_note'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Missing -- private helper, called only after self::manager_guard() checked the nonce.
			'color'          => $t( 'color' ),
			'force_roll'     => isset( $_POST['force_roll'] ) ? (int) $_POST['force_roll'] : 0, // phpcs:ignore WordPress.Security.NonceVerification.Missing -- private helper, called only after self::manager_guard() checked the nonce.
			'install_date'   => $t( 'install_date' ),
			'pieces'         => is_array( $rows ) ? $rows : array(),
		);
	}

	public static function ajax_remake_create(): void {
		self::manager_guard();
		$row = ZPREP_Remakes::create( self::read_remake_args(), (int) get_current_user_id() );
		if ( is_wp_error( $row ) ) {
			wp_send_json_error( array( 'message' => $row->get_error_message() ) );
		}
		$assignee = isset( $_POST['assignee_id'] ) ? (int) $_POST['assignee_id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce, app access and manager role checked in self::manager_guard() above.
		if ( $assignee > 0 ) {
			$a = ZPREP_Assignments::create( (int) get_current_user_id(), $assignee, ZPREP_Remakes::job_key( (int) $row['id'] ), (string) $row['customer_label'], 'remake' );
			if ( is_wp_error( $a ) ) {
				wp_send_json_success( array( 'remake' => ZPREP_Remakes::card( $row ), 'warning' => $a->get_error_message() ) );
			}
		}
		wp_send_json_success( array( 'remake' => ZPREP_Remakes::card( ZPREP_Remakes::get( (int) $row['id'] ) ?? $row ) ) );
	}

	/**
	 * Paste → rows: read a pasted cut list with the measurement reader (the same one the main
	 * flow uses). Unknowns stay blank for the manager to fill in.
	 */
	public static function ajax_remake_parse(): void {
		self::manager_guard();
		$text = isset( $_POST['text'] ) ? trim( (string) wp_unslash( $_POST['text'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce checked in self::manager_guard() above; the pasted text only goes to the measurement reader and two digit-only patterns, and is not stored.
		if ( '' === $text ) {
			wp_send_json_error( array( 'message' => __( 'Paste some text to read.', 'zorderz' ) ) );
		}
		$text   = mb_substr( $text, 0, 20000 );
		$parser = new ZPREP_Parser();
		if ( ! $parser->is_ready() ) {
			wp_send_json_error( array( 'message' => __( 'The Ai reader is not set up. Add the rows by hand below.', 'zorderz' ) ) );
		}
		$parsed = $parser->parse_measurements( $text );
		$rows   = ( is_array( $parsed ) && isset( $parsed['measurements'] ) && is_array( $parsed['measurements'] ) ) ? array_values( $parsed['measurements'] ) : array();
		if ( empty( $rows ) ) {
			wp_send_json_error( array( 'message' => __( 'No pieces were found in that text. Add them by hand below.', 'zorderz' ) ) );
		}
		$colors = ZPREP_Settings::roll_colors();
		$out    = array();
		foreach ( $rows as $m ) {
			$c     = strtolower( (string) ( $m['color'] ?? '' ) );
			$out[] = array(
				'kind'   => (string) ( $m['kind'] ?? 'custom' ),
				'qty'    => max( 1, (int) ( $m['qty'] ?? 1 ) ),
				'width'  => ( isset( $m['width_in'] ) && is_numeric( $m['width_in'] ) ) ? (float) $m['width_in'] : '',
				'height' => ( isset( $m['height_in'] ) && is_numeric( $m['height_in'] ) ) ? (float) $m['height_in'] : '',
				'shape'  => (string) ( $m['shape'] ?? 'rectangle' ),
				'color'  => in_array( $c, $colors, true ) ? $c : '',
				'notes'  => (string) ( $m['notes'] ?? '' ),
			);
		}
		$parent = array( 'type' => '', 'num' => '' );
		if ( preg_match( '/\b(?:estimate|est)\s*#?\s*(\d{3,8})\b/i', $text, $mm ) ) {
			$parent = array( 'type' => 'est', 'num' => $mm[1] );
		} elseif ( preg_match( '/\blead\s*#?\s*(\d{3,8})\b/i', $text, $mm ) ) {
			$parent = array( 'type' => 'lead', 'num' => $mm[1] );
		}
		$pieces = 0;
		foreach ( $out as $r ) {
			$pieces += (int) $r['qty'];
		}
		wp_send_json_success( array( 'pieces' => $out, 'count' => $pieces, 'parent' => $parent ) );
	}

	public static function ajax_remakes_list(): void {
		self::guard();
		$uid  = (int) get_current_user_id();
		$mode = self::queue_mode( $uid );
		if ( 'manager' === $mode ) {
			$cards = array_map( array( 'ZPREP_Remakes', 'card' ), ZPREP_Remakes::list_open() );
		} elseif ( 'assignee' === $mode || 'team' === $mode ) {
			$cards = ZPREP_Remakes::cards_for( $uid, false );
		} else {
			$cards = array();
		}
		wp_send_json_success(
			array(
				'remakes'    => array_values( $cards ),
				'can_manage' => 'manager' === $mode,
				'mode'       => $mode,
				'reasons'    => ZPREP_Remakes::reasons(),
				'colors'     => ZPREP_Settings::roll_colors(),
				'widths'     => array_values( array_unique( array_map( static function ( $r ) { return (int) $r['width_in']; }, ZPREP_Settings::rolls() ) ) ),
			)
		);
	}

	public static function ajax_remake_set_status(): void {
		self::manager_guard();
		$id     = isset( $_POST['remake_id'] ) ? (int) $_POST['remake_id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce, app access and manager role checked in self::manager_guard() above.
		$status = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce, app access and manager role checked in self::manager_guard() above.
		if ( ! ZPREP_Remakes::set_status( $id, $status ) ) {
			wp_send_json_error( array( 'message' => __( 'Could not update the status.', 'zorderz' ) ) );
		}
		$row = ZPREP_Remakes::get( $id );
		wp_send_json_success( array( 'remake' => $row ? ZPREP_Remakes::card( $row ) : null ) );
	}

	public static function ajax_assign(): void {
		self::manager_guard();
		$job_key = isset( $_POST['job_key'] ) ? sanitize_text_field( wp_unslash( $_POST['job_key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce, app access and manager role checked in self::manager_guard() above.
		if ( ! preg_match( '/^(est|lead|remake):[A-Za-z0-9._-]{1,60}$/', $job_key ) ) {
			wp_send_json_error( array( 'message' => __( 'No job to assign.', 'zorderz' ) ) );
		}
		$res = ZPREP_Assignments::create(
			(int) get_current_user_id(),
			isset( $_POST['assignee_id'] ) ? (int) $_POST['assignee_id'] : 0, // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce, app access and manager role checked in self::manager_guard() above.
			$job_key,
			isset( $_POST['job_label'] ) ? sanitize_text_field( wp_unslash( $_POST['job_label'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce, app access and manager role checked in self::manager_guard() above.
			isset( $_POST['reason'] ) ? sanitize_text_field( wp_unslash( $_POST['reason'] ) ) : '' // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce, app access and manager role checked in self::manager_guard() above.
		);
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message() ) );
		}
		$out = array( 'assignee_id' => (int) $res['assignee_id'], 'assignee_name' => ZPREP_Assignments::display_name( (int) $res['assignee_id'] ) );
		$rid = ZPREP_Remakes::id_from_job_key( $job_key );
		if ( $rid > 0 && ( $row = ZPREP_Remakes::get( $rid ) ) ) {
			$out['remake'] = ZPREP_Remakes::card( $row );
		}
		wp_send_json_success( $out );
	}

	public static function ajax_unassign(): void {
		self::manager_guard();
		$job_key = isset( $_POST['job_key'] ) ? sanitize_text_field( wp_unslash( $_POST['job_key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce, app access and manager role checked in self::manager_guard() above.
		if ( ! ZPREP_Assignments::revoke_job( (int) get_current_user_id(), $job_key ) ) {
			wp_send_json_error( array( 'message' => __( 'Nothing to unassign.', 'zorderz' ) ) );
		}
		$out = array( 'unassigned' => true );
		$rid = ZPREP_Remakes::id_from_job_key( $job_key );
		if ( $rid > 0 && ( $row = ZPREP_Remakes::get( $rid ) ) ) {
			$out['remake'] = ZPREP_Remakes::card( $row );
		}
		wp_send_json_success( $out );
	}

	/** People a manager may assign Prep work to: never the shared device; able, when configured. */
	public static function ajax_users_list(): void {
		self::manager_guard();
		$labels = ( class_exists( 'ZDZ_User_Roles' ) && method_exists( 'ZDZ_User_Roles', 'get_role_labels' ) ) ? ZDZ_User_Roles::get_role_labels() : array();
		$out    = array();
		foreach ( get_users( array( 'fields' => array( 'ID', 'display_name' ), 'number' => 500, 'orderby' => 'display_name' ) ) as $u ) {
			$id = (int) $u->ID;
			if ( ZPREP_Assignments::is_shared_device( $id ) || ! ZPREP_Assignments::is_able( $id ) ) {
				continue;
			}
			$ud    = get_userdata( $id );
			$role  = $ud ? (string) ( $ud->roles[0] ?? '' ) : '';
			$out[] = array( 'id' => $id, 'name' => (string) $u->display_name, 'role_label' => (string) ( $labels[ $role ] ?? $role ) );
		}
		wp_send_json_success( array( 'users' => $out ) );
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

	/* ================================================================
	 * INVOICE DE-DUP — pure deciders (no WP/network; unit-tested)
	 * ================================================================ */

	/** Normalise a customer name for de-duplication (lower, single-spaced). */
	public static function norm_name( string $n ): string {
		return strtolower( trim( (string) preg_replace( '/\s+/', ' ', $n ) ) );
	}

	/**
	 * A { surname, givens[] } signature for fuzzy same-customer matching. A billing
	 * invoice may bill "Alex Rivera" while the CRM lead / estimate reads "Alex / Sam
	 * Rivera" (a paired household). Split on the pair separators and keep the LAST
	 * token as the surname and the rest as given names, so the two still match.
	 */
	public static function customer_signature( string $name ): array {
		$n = strtolower( trim( (string) $name ) );
		$n = str_replace( array( '/', '&', '+', ',' ), ' ', $n );
		$n = (string) preg_replace( '/\b(?:and|und)\b/', ' ', $n );
		$toks = array_values( array_filter( preg_split( '/\s+/', $n ), static function ( $t ) { return $t !== ''; } ) );
		if ( ! $toks ) { return array( 'surname' => '', 'givens' => array() ); }
		$surname = (string) end( $toks );
		$givens  = array_values( array_unique( array_slice( $toks, 0, count( $toks ) - 1 ) ) );
		return array( 'surname' => $surname, 'givens' => $givens );
	}

	/**
	 * Do two names denote the same customer? An exact billing customer id wins
	 * outright; otherwise the names must share a surname AND either share a given name
	 * or one side is surname-only. This catches "Alex / Sam Rivera" == "Alex Rivera"
	 * WITHOUT merging "Jordan Rivera" into "Alex Rivera" — different given names on the
	 * same surname stay distinct.
	 *
	 * The surnames may also be SOUNDALIKE (one source spells it one way, another
	 * differently) — but a soundalike surname folds ONLY when a given name also agrees,
	 * so it never merges two different people who merely sound alike. A soundalike
	 * surname with surname-only on either side stays DISTINCT (no given to corroborate;
	 * never merge on sound alone). Guarded by ZDZ_Name_Match — absent, only EXACT
	 * surnames fold, exactly as before.
	 */
	public static function same_customer( string $name_a, string $cid_a, string $name_b, string $cid_b ): bool {
		if ( $cid_a !== '' && $cid_b !== '' ) { return $cid_a === $cid_b; }
		$a = self::customer_signature( $name_a );
		$b = self::customer_signature( $name_b );
		if ( $a['surname'] === '' || $b['surname'] === '' ) { return false; }
		$surname_same  = ( $a['surname'] === $b['surname'] );
		$surname_sound = ( ! $surname_same && class_exists( 'ZDZ_Name_Match' )
			&& \ZDZ_Name_Match::sounds_like( $a['surname'], $b['surname'] ) );
		if ( ! $surname_same && ! $surname_sound ) { return false; }
		if ( ! $a['givens'] || ! $b['givens'] ) {
			return $surname_same; // surname-only side: fold on EXACT surname only, never on sound
		}
		return (bool) array_intersect( $a['givens'], $b['givens'] );
	}

	/**
	 * How does an invoice-sourced card relate to what is already shown?
	 *
	 * The Approved-to-Cut queue folds recent queue invoices in AFTER the CRM /
	 * approved-estimate pass. An invoice that bills a job ALREADY shown from one of
	 * those sources folds into it (dedupe). But two DISTINCT invoices for the SAME
	 * customer are two different jobs and must each surface — so an invoice dedupes
	 * ONLY against the OTHER-SOURCE cards (customer match), and against another INVOICE
	 * only when they share a billing estimate id. Pure + unit-testable.
	 *
	 *   'other_source' — same customer as a CRM/estimate card: dedupe
	 *   'dup_invoice'  — same estimate id as an invoice already shown this pass: dedupe
	 *   'new'          — a distinct job: show it
	 *
	 * @param array $seen_cust keys 'c:<id>' / 'n:<norm name>' seeded from NON-invoice cards only
	 * @param array $shown     [name,cid] pairs from those same non-invoice cards (fuzzy fallback)
	 * @param array $seen_est  estimate ids already shown by the invoice arm this pass
	 */
	public static function classify_invoice_card( string $name, string $cid, string $nm, string $estimate_id, array $seen_cust, array $shown, array $seen_est ): string {
		$dup = ( '' !== $cid && isset( $seen_cust[ 'c:' . $cid ] ) ) || ( '' !== $nm && isset( $seen_cust[ 'n:' . $nm ] ) );
		if ( ! $dup ) {
			foreach ( $shown as $sc ) {
				if ( self::same_customer( $name, $cid, (string) ( $sc[0] ?? '' ), (string) ( $sc[1] ?? '' ) ) ) { $dup = true; break; }
			}
		}
		if ( $dup ) { return 'other_source'; }
		if ( '' !== $estimate_id && isset( $seen_est[ $estimate_id ] ) ) { return 'dup_invoice'; }
		return 'new';
	}

	/** Age of a billing document in whole days, or null when unparseable. */
	private static function invoice_age_days( string $date ): ?int {
		$date = trim( $date );
		if ( $date === '' ) { return null; }
		$ts = strtotime( $date );
		if ( ! $ts ) { return null; }
		return (int) floor( ( time() - $ts ) / DAY_IN_SECONDS );
	}
}
