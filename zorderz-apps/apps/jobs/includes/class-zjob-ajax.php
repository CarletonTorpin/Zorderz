<?php
/**
 * Zorderz Jobs — AJAX endpoints (the dashboard widget's server API).
 *
 * Every handler: verifies the nonce, resolves the TRUE server-side user
 * (get_current_user_id — never trusts a client-supplied id), and gates on the data
 * permission / oversight rule. The shared kiosk is refused everywhere.
 *
 * @package Zorderz\Jobs
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZJOB_AJAX {

	public static function init(): void {
		add_action( 'wp_ajax_zjob_create',              [ __CLASS__, 'create' ] );
		add_action( 'wp_ajax_zjob_list',                [ __CLASS__, 'list_jobs' ] );
		add_action( 'wp_ajax_zjob_assignees',           [ __CLASS__, 'assignees' ] );
		add_action( 'wp_ajax_zjob_reassign',            [ __CLASS__, 'reassign' ] );
		add_action( 'wp_ajax_zjob_set_status',          [ __CLASS__, 'set_status' ] );
		add_action( 'wp_ajax_zjob_set_eta',             [ __CLASS__, 'set_eta' ] );
		add_action( 'wp_ajax_zjob_set_schedule',        [ __CLASS__, 'set_schedule' ] );
		add_action( 'wp_ajax_zjob_clear_schedule',      [ __CLASS__, 'clear_schedule' ] );
		add_action( 'wp_ajax_zjob_create_from_estimate', [ __CLASS__, 'create_from_estimate' ] );
		add_action( 'wp_ajax_zjob_estimate_rollup',     [ __CLASS__, 'estimate_rollup' ] );
		add_action( 'wp_ajax_zjob_worker_complete',     [ __CLASS__, 'worker_complete' ] );
		add_action( 'wp_ajax_zjob_close_job',           [ __CLASS__, 'close_job' ] );
		add_action( 'wp_ajax_zjob_self_attest_close',   [ __CLASS__, 'self_attest_close' ] ); // solo single-party attestation
		add_action( 'wp_ajax_zjob_extend_close',        [ __CLASS__, 'extend_close' ] );
	}

	/** Shared preamble: verify nonce, return the true current user id, block kiosk. */
	private static function gate(): int {
		check_ajax_referer( ZJOB_NONCE, 'nonce' );
		$uid = get_current_user_id();
		if ( $uid <= 0 ) {
			wp_send_json_error( [ 'message' => 'not_logged_in' ], 403 );
		}
		if ( class_exists( 'ZDZ_Hierarchy' ) && ZDZ_Hierarchy::is_kiosk( $uid ) ) {
			wp_send_json_error( [ 'message' => 'kiosk_forbidden' ], 403 );
		}
		return $uid;
	}

	/** Create a job and mirror it to the CRM (child lead). Requires the handoff permission. */
	public static function create(): void {
		$uid = self::gate();

		if ( ! ZJOB_Jobs::user_can_hand_off( $uid ) ) {
			wp_send_json_error( [ 'message' => 'not_permitted' ], 403 );
		}

		$assignee = isset( $_POST['assigned_user_id'] ) ? (int) $_POST['assigned_user_id'] : 0;
		if ( $assignee <= 0 || ! get_userdata( $assignee ) ) {
			wp_send_json_error( [ 'message' => 'bad_assignee' ], 400 );
		}

		$data = [
			'component'         => isset( $_POST['component'] ) ? sanitize_key( wp_unslash( $_POST['component'] ) ) : '',
			'customer_name'     => isset( $_POST['customer_name'] ) ? sanitize_text_field( wp_unslash( $_POST['customer_name'] ) ) : '',
			'source_ref'        => isset( $_POST['source_ref'] ) ? sanitize_text_field( wp_unslash( $_POST['source_ref'] ) ) : '',
			'parent_lead_id'    => isset( $_POST['parent_lead_id'] ) ? (int) $_POST['parent_lead_id'] : 0,
			'crm_contact_id'    => isset( $_POST['crm_contact_id'] ) ? (int) $_POST['crm_contact_id'] : ( isset( $_POST['nutshell_contact_id'] ) ? (int) $_POST['nutshell_contact_id'] : 0 ),
			'assigned_user_id'  => $assignee,
			'brand'             => isset( $_POST['brand'] ) ? sanitize_text_field( wp_unslash( $_POST['brand'] ) ) : '',
			'qty'               => isset( $_POST['qty'] ) ? (int) $_POST['qty'] : 0,
			'notes'             => isset( $_POST['notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['notes'] ) ) : '',
			'customer_business' => isset( $_POST['customer_business'] ) ? sanitize_text_field( wp_unslash( $_POST['customer_business'] ) ) : '',
			'customer_address'  => isset( $_POST['customer_address'] ) ? sanitize_text_field( wp_unslash( $_POST['customer_address'] ) ) : '',
			'customer_phone'    => isset( $_POST['customer_phone'] ) ? sanitize_text_field( wp_unslash( $_POST['customer_phone'] ) ) : '',
			'access_notes'      => isset( $_POST['access_notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['access_notes'] ) ) : '',
		];

		$id = ZJOB_Jobs::create( $data, $uid );
		if ( $id <= 0 ) {
			wp_send_json_error( [ 'message' => 'create_failed' ], 500 );
		}

		// Mirror into the CRM (best-effort; the app record already exists).
		$crm = [ 'ok' => false, 'child_lead_id' => 0, 'steps' => [], 'error' => 'skipped' ];
		$row = ZJOB_Jobs::get( $id );
		if ( $row && class_exists( 'ZJOB_CRM' ) ) {
			$crm = ZJOB_CRM::provider()->create_child_lead( $row );
			if ( ! empty( $crm['child_lead_id'] ) ) {
				ZJOB_Jobs::attach_child_lead( $id, (int) $crm['child_lead_id'] );
			}
		}

		wp_send_json_success( [
			'id'       => $id,
			'crm'      => [
				'ok'            => (bool) $crm['ok'],
				'child_lead_id' => (int) $crm['child_lead_id'],
				'steps'         => $crm['steps'],
				'error'         => (string) $crm['error'],
			],
			'message'  => $crm['ok']
				? 'Job created and mirrored to the CRM.'
				: 'Job saved. CRM sync incomplete (' . $crm['error'] . ') - the app record is intact.',
		] );
	}

	/** List jobs the caller may see, bucketed Past/Present/Future. */
	public static function list_jobs(): void {
		$uid = self::gate();
		if ( class_exists( 'ZJOB_User_Log' ) && method_exists( 'ZJOB_User_Log', 'log_active_daily' ) ) {
			ZJOB_User_Log::log_active_daily( $uid, 'jobs_opened', 'jobs' );
		}
		$bucket_req = isset( $_POST['bucket'] ) ? sanitize_key( wp_unslash( $_POST['bucket'] ) ) : '';
		$status     = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : '';
		$rows       = ZJOB_Jobs::list_for( $uid, '' === $bucket_req ? $status : '' );

		$out = array_map( static function ( $r ) use ( $uid ) {
			$assignee = get_userdata( (int) $r['assigned_user_id'] );
			$creator  = get_userdata( (int) $r['created_by'] );
			$is_mine  = ( (int) $r['assigned_user_id'] === (int) $uid );
			$status   = (string) $r['status'];

			$finish_photos = [];
			$finish_done   = in_array( $status, [ 'pending_close', 'done' ], true );
			if ( $finish_done && class_exists( 'ZJOB_Photos' ) ) {
				$mids = json_decode( (string) ( $r['finish_media_ids'] ?? '' ), true );
				if ( is_array( $mids ) && ! empty( $mids ) ) {
					$finish_photos = ZJOB_Photos::links_for( $mids );
				}
			}
			$can_complete = ( $is_mine || ( class_exists( 'ZDZ_Hierarchy' ) && ZDZ_Hierarchy::is_admin( (int) $uid ) ) )
				&& in_array( $status, [ 'open', 'in_progress' ], true );

			$can_close      = ( 'pending_close' === $status ) && ZJOB_Jobs::actor_can_close( (int) $uid, $r );
			// Solo operator: the worker may self-attest ONLY when no distinct second
			// party exists to close it. Recorded as single_party_attested (never two_party).
			$can_self_attest = ( 'pending_close' === $status ) && $is_mine && ! $can_close
				&& ZJOB_Jobs::self_attest_allowed( $r, (int) $uid );
			$closed_by = (int) ( $r['closed_by'] ?? 0 );
			$closer    = $closed_by > 0 ? get_userdata( $closed_by ) : null;

			return [
				'id'                => (int) $r['id'],
				'component'         => $r['component'],
				'component_label'   => function_exists( 'zjob_component_label' ) ? zjob_component_label( (string) $r['component'] ) : (string) $r['component'],
				'customer_name'     => $r['customer_name'],
				'customer_business' => $r['customer_business'] ?? '',
				'customer_address'  => $r['customer_address'] ?? '',
				'customer_phone'    => $r['customer_phone'] ?? '',
				'access_notes'      => $r['access_notes'] ?? '',
				'source_ref'        => $r['source_ref'],
				'brand'             => $r['brand'],
				'qty'               => (int) $r['qty'],
				'notes'             => $r['notes'],
				'status'            => $status,
				'assignee_name'     => $assignee ? $assignee->display_name : ( '#' . $r['assigned_user_id'] ),
				'creator_name'      => $creator ? $creator->display_name : ( '#' . $r['created_by'] ),
				'is_mine'           => $is_mine,
				'bucket'            => ZJOB_Jobs::bucket_for( $r, (int) $uid ),
				'can_schedule'      => ZJOB_Jobs::actor_can_self_schedule( $uid, $r ),
				'can_complete'      => $can_complete,
				'can_close'         => $can_close,
				'can_self_attest'   => $can_self_attest,
				'assurance_level'   => (string) ( $r['assurance_level'] ?? '' ),
				'scheduled_appt_id'   => (int) ( $r['scheduled_appt_id'] ?? 0 ),
				'scheduled_start_utc' => $r['scheduled_start_utc'] ?? '',
				'scheduled_end_utc'   => $r['scheduled_end_utc'] ?? '',
				'scheduled_tz'        => $r['scheduled_tz'] ?? '',
				'eta_status'        => $r['eta_status'] ?? '',
				'eta_at'            => $r['eta_at'] ?? '',
				'started_at'        => $r['started_at'] ?? '',
				'worker_done_at'    => $r['worker_done_at'] ?? '',
				'finish_verified'   => (int) ( $r['finish_verified'] ?? 0 ) === 1,
				'finish_photos'     => $finish_photos,
				'closed_at'         => $r['closed_at'] ?? '',
				'closed_by_name'    => $closer ? $closer->display_name : '',
				'close_deadline'      => $r['close_deadline'] ?? '',
				'close_extended_count'=> (int) ( $r['close_extended_count'] ?? 0 ),
				'child_lead_id'     => (int) $r['crm_child_lead_id'],
				'created_at'        => $r['created_at'],
			];
		}, $rows );

		if ( in_array( $bucket_req, [ 'present', 'future', 'past' ], true ) ) {
			$out = array_values( array_filter( $out, static function ( $h ) use ( $bucket_req ) {
				return ( $h['bucket'] ?? '' ) === $bucket_req;
			} ) );
		}

		wp_send_json_success( [
			'jobs'                => $out,
			'pending_close_count' => ZJOB_Jobs::count_closable_pending( $uid ),
		] );
	}

	/**
	 * The specialist roster a caller may assign to. Sourced from the platform Party
	 * register (ZDZ_Party::selectable_people) — the single authoritative roster —
	 * excluding the caller. Falls back to app users when the Party service is absent.
	 */
	public static function assignees(): void {
		$uid = self::gate();
		if ( ! ZJOB_Jobs::user_can_hand_off( $uid ) ) {
			wp_send_json_error( [ 'message' => 'not_permitted' ], 403 );
		}
		$out = [];
		if ( class_exists( 'ZDZ_Party' ) && method_exists( 'ZDZ_Party', 'selectable_people' ) ) {
			$people = ZDZ_Party::selectable_people( [ 'exclude' => [ $uid ], 'include_self' => false ] );
			foreach ( $people as $p ) {
				$out[] = [ 'id' => (int) $p['id'], 'name' => (string) $p['name'] ];
			}
		} else {
			// Fallback: app users minus the caller and the kiosk.
			$users = get_users( [ 'fields' => [ 'ID', 'display_name' ], 'number' => 500, 'orderby' => 'display_name', 'order' => 'ASC' ] );
			foreach ( $users as $u ) {
				$id = (int) $u->ID;
				if ( $id === $uid ) {
					continue;
				}
				if ( class_exists( 'ZDZ_Hierarchy' ) && ZDZ_Hierarchy::is_kiosk( $id ) ) {
					continue;
				}
				$out[] = [ 'id' => $id, 'name' => $u->display_name ];
			}
		}
		wp_send_json_success( [ 'assignees' => $out ] );
	}

	/** Reassign a job (actor must be able to manage it). */
	public static function reassign(): void {
		$uid          = self::gate();
		$id           = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		$new_assignee = isset( $_POST['assigned_user_id'] ) ? (int) $_POST['assigned_user_id'] : 0;
		$ok = ZJOB_Jobs::reassign( $id, $new_assignee, $uid );
		if ( ! $ok ) {
			wp_send_json_error( [ 'message' => 'reassign_failed' ], 403 );
		}
		if ( class_exists( 'ZJOB_Notify' ) ) {
			$row = ZJOB_Jobs::get( $id );
			if ( $row ) { ZJOB_Notify::job_assigned( $row, $uid ); }
		}
		wp_send_json_success( [ 'id' => $id, 'assigned_user_id' => $new_assignee ] );
	}

	/** Set a job status (actor must be able to manage it). */
	public static function set_status(): void {
		$uid    = self::gate();
		$id     = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		$status = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : '';
		$ok = ZJOB_Jobs::set_status( $id, $status, $uid );
		if ( ! $ok ) {
			wp_send_json_error( [ 'message' => 'status_failed' ], 403 );
		}
		wp_send_json_success( [ 'id' => $id, 'status' => $status ] );
	}

	/** Worker inbox: record an ETA signal on the worker's own job. */
	public static function set_eta(): void {
		$uid = self::gate();
		$id  = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		$eta = isset( $_POST['eta'] ) ? sanitize_key( wp_unslash( $_POST['eta'] ) ) : '';
		if ( $id <= 0 ) {
			wp_send_json_error( [ 'message' => 'bad_request' ], 400 );
		}
		$res = ZJOB_Jobs::set_eta( $id, $eta, $uid );
		if ( empty( $res['ok'] ) ) {
			$map  = [ 'bad_eta' => 400, 'not_found' => 404, 'not_permitted' => 403, 'kiosk_forbidden' => 403, 'bad_state' => 409 ];
			$code = $map[ (string) $res['error'] ] ?? 400;
			wp_send_json_error( [ 'message' => (string) $res['error'] ], $code );
		}
		$row = ZJOB_Jobs::get( $id );
		if ( $row && class_exists( 'ZJOB_CRM' ) ) {
			ZJOB_CRM::provider()->post_eta_note( $row, $eta, $uid );
		}
		wp_send_json_success( [
			'id'         => $id,
			'status'     => (string) $res['status'],
			'eta_status' => (string) $res['eta_status'],
			'started'    => (bool) $res['started'],
			'message'    => ( 'on_my_way' === $eta ) ? 'On your way - marked started.' : 'Flagged: running late.',
		] );
	}

	/** Set/move a job's scheduled time. Dispatch-gated. */
	public static function set_schedule(): void {
		$uid   = self::gate();
		$id    = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		$start = isset( $_POST['start_local'] ) ? sanitize_text_field( wp_unslash( $_POST['start_local'] ) ) : '';
		$dur   = isset( $_POST['duration_min'] ) ? (int) $_POST['duration_min'] : 0;
		if ( $id <= 0 || $start === '' ) {
			wp_send_json_error( [ 'message' => 'bad_request' ], 400 );
		}
		$res = ZJOB_Jobs::set_schedule( $id, $start, $dur, $uid );
		if ( empty( $res['ok'] ) ) {
			$code = ( $res['error'] === 'not_permitted' ) ? 403 : 400;
			wp_send_json_error( [ 'message' => $res['error'] ], $code );
		}
		if ( class_exists( 'ZJOB_Notify' ) ) {
			$row = ZJOB_Jobs::get( $id );
			if ( $row ) { ZJOB_Notify::job_assigned( $row, $uid ); }
		}
		wp_send_json_success( [
			'id'                  => $id,
			'appt_id'             => (int) $res['appt_id'],
			'scheduled_start_utc' => $res['scheduled_start_utc'],
			'scheduled_tz'        => $res['scheduled_tz'],
		] );
	}

	/** Clear a job's schedule. Dispatch-gated. */
	public static function clear_schedule(): void {
		$uid = self::gate();
		$id  = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		if ( $id <= 0 ) {
			wp_send_json_error( [ 'message' => 'bad_request' ], 400 );
		}
		$res = ZJOB_Jobs::clear_schedule( $id, $uid );
		if ( empty( $res['ok'] ) ) {
			$code = ( $res['error'] === 'not_permitted' ) ? 403 : 400;
			wp_send_json_error( [ 'message' => $res['error'] ], $code );
		}
		wp_send_json_success( [ 'id' => $id ] );
	}

	/**
	 * Create one or more jobs from selected estimate line items. The Estimates app
	 * posts the already-known line + customer data here; this endpoint owns job
	 * creation, so Jobs stays the single source of truth.
	 */
	public static function create_from_estimate(): void {
		$uid = self::gate();
		if ( ! ZJOB_Jobs::user_can_hand_off( $uid ) ) {
			wp_send_json_error( [ 'message' => 'not_permitted' ], 403 );
		}

		$assignee = isset( $_POST['assigned_user_id'] ) ? (int) $_POST['assigned_user_id'] : 0;
		if ( $assignee <= 0 || ! get_userdata( $assignee ) ) {
			wp_send_json_error( [ 'message' => 'bad_assignee' ], 400 );
		}

		$lines = json_decode( isset( $_POST['lines_json'] ) ? (string) wp_unslash( $_POST['lines_json'] ) : '', true );
		if ( ! is_array( $lines ) || empty( $lines ) ) {
			wp_send_json_error( [ 'message' => 'no_lines' ], 400 );
		}

		$customer_name     = isset( $_POST['customer_name'] ) ? sanitize_text_field( wp_unslash( $_POST['customer_name'] ) ) : '';
		$customer_business = isset( $_POST['customer_business'] ) ? sanitize_text_field( wp_unslash( $_POST['customer_business'] ) ) : '';
		$customer_address  = isset( $_POST['customer_address'] ) ? sanitize_text_field( wp_unslash( $_POST['customer_address'] ) ) : '';
		$customer_phone    = isset( $_POST['customer_phone'] ) ? sanitize_text_field( wp_unslash( $_POST['customer_phone'] ) ) : '';
		$crm_lead_id       = isset( $_POST['crm_lead_id'] ) ? (int) $_POST['crm_lead_id'] : ( isset( $_POST['ns_lead_id'] ) ? (int) $_POST['ns_lead_id'] : 0 );
		$crm_contact_id    = isset( $_POST['crm_contact_id'] ) ? (int) $_POST['crm_contact_id'] : ( isset( $_POST['ns_contact_id'] ) ? (int) $_POST['ns_contact_id'] : 0 );
		$estimate_num      = isset( $_POST['estimate_num'] ) ? sanitize_text_field( wp_unslash( $_POST['estimate_num'] ) ) : '';
		$estimate_id       = isset( $_POST['estimate_id'] ) ? (int) $_POST['estimate_id'] : 0;
		$context_note      = isset( $_POST['context_note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['context_note'] ) ) : '';

		if ( ( $customer_address === '' || $customer_phone === '' || $customer_business === '' ) && $crm_contact_id > 0
			&& class_exists( 'ZJOB_CRM' ) ) {
			$r = ZJOB_CRM::provider()->resolve_contact_info( $crm_contact_id );
			if ( $customer_address === '' && ! empty( $r['address'] ) )   { $customer_address = $r['address']; }
			if ( $customer_phone === '' && ! empty( $r['phone'] ) )       { $customer_phone = $r['phone']; }
			if ( $customer_business === '' && ! empty( $r['business'] ) )  { $customer_business = $r['business']; }
		}

		$source_ref = $estimate_num !== '' ? ( 'estimate #' . $estimate_num ) : 'estimate';
		$results    = [];

		foreach ( $lines as $ln ) {
			if ( ! is_array( $ln ) ) {
				continue;
			}
			$desc = isset( $ln['description'] ) ? sanitize_text_field( (string) $ln['description'] ) : '';
			if ( $desc === '' ) {
				continue;
			}
			$sub  = isset( $ln['sub_description'] ) ? sanitize_text_field( (string) $ln['sub_description'] ) : '';
			$dims = isset( $ln['dimensions'] ) ? sanitize_text_field( (string) $ln['dimensions'] ) : '';
			$qty  = isset( $ln['quantity'] ) ? (int) $ln['quantity'] : ( isset( $ln['qty'] ) ? (int) $ln['qty'] : 0 );
			$component = ( ! empty( $ln['component'] ) ) ? sanitize_key( (string) $ln['component'] ) : self::guess_component( $desc . ' ' . $sub );
			$brand     = self::guess_brand( $desc . ' ' . $sub );
			$line_index = isset( $ln['line_index'] ) ? (int) $ln['line_index'] : -1;
			$line_sig   = ZJOB_Jobs::line_sig( $desc, $sub, $dims, $qty );

			$note = implode( "\n", array_filter( [
				$desc,
				$sub,
				( $dims !== '' ? ( 'Size: ' . $dims ) : '' ),
				( $context_note !== '' ? ( 'Note: ' . $context_note ) : '' ),
			] ) );

			$data = [
				'component'         => $component,
				'customer_name'     => $customer_name,
				'customer_business' => $customer_business,
				'customer_address'  => $customer_address,
				'customer_phone'    => $customer_phone,
				'source_ref'        => $source_ref,
				'parent_lead_id'    => $crm_lead_id,
				'crm_contact_id'    => $crm_contact_id,
				'assigned_user_id'  => $assignee,
				'brand'             => $brand,
				'qty'               => $qty,
				'notes'             => $note,
				'access_notes'      => '',
				'estimate_id'       => $estimate_id,
				'estimate_line_index' => $line_index,
				'estimate_line_sig' => $line_sig,
			];

			$id = ZJOB_Jobs::create( $data, $uid );
			if ( $id <= 0 ) {
				$results[] = [ 'ok' => false, 'error' => 'create_failed', 'desc' => $desc ];
				continue;
			}

			$crm_res = [ 'ok' => false, 'child_lead_id' => 0 ];
			$row     = ZJOB_Jobs::get( $id );
			if ( $row && class_exists( 'ZJOB_CRM' ) ) {
				$crm_res = ZJOB_CRM::provider()->create_child_lead( $row );
				if ( ! empty( $crm_res['child_lead_id'] ) ) {
					ZJOB_Jobs::attach_child_lead( $id, (int) $crm_res['child_lead_id'] );
				}
			}
			$results[] = [
				'ok'            => true,
				'id'            => $id,
				'component'     => $component,
				'child_lead_id' => (int) ( $crm_res['child_lead_id'] ?? 0 ),
				'desc'          => $desc,
			];
		}

		$created = count( array_filter( $results, static function ( $r ) { return ! empty( $r['ok'] ); } ) );
		wp_send_json_success( [
			'created'          => $created,
			'total'            => count( $results ),
			'assigned_user_id' => $assignee,
			'results'          => $results,
			'message'          => sprintf( '%d of %d line(s) sent as jobs.', $created, count( $results ) ),
		] );
	}

	/** Return the job rollup for an estimate. Read-only; handoff-gated. */
	public static function estimate_rollup(): void {
		$uid = self::gate();
		if ( ! ZJOB_Jobs::user_can_hand_off( $uid ) ) {
			wp_send_json_error( [ 'message' => 'not_permitted' ], 403 );
		}
		$estimate_id = isset( $_POST['estimate_id'] ) ? (int) $_POST['estimate_id'] : 0;
		if ( $estimate_id <= 0 ) {
			wp_send_json_error( [ 'message' => 'bad_request' ], 400 );
		}
		wp_send_json_success( ZJOB_Jobs::rollup_for_estimate( $estimate_id ) );
	}

	/* =======================================================================
	 * TWO-PARTY COMPLETION
	 * ======================================================================= */

	/** The worker marks THEIR part of a job complete (mandatory finish photos + GPS). */
	public static function worker_complete(): void {
		$uid = self::gate();
		$id  = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		if ( $id <= 0 ) {
			wp_send_json_error( [ 'message' => 'bad_request' ], 400 );
		}

		$media_ids = self::parse_id_list( isset( $_POST['media_ids'] ) ? (string) wp_unslash( $_POST['media_ids'] ) : '' );
		$gps = [
			'lat'      => isset( $_POST['gps_lat'] ) ? (string) $_POST['gps_lat'] : '',
			'lng'      => isset( $_POST['gps_lng'] ) ? (string) $_POST['gps_lng'] : '',
			'accuracy' => isset( $_POST['gps_accuracy'] ) ? (string) $_POST['gps_accuracy'] : '',
		];

		$res = ZJOB_Jobs::worker_complete( $id, $media_ids, $gps, $uid );
		if ( empty( $res['ok'] ) ) {
			$map  = [ 'not_found' => 404, 'not_permitted' => 403, 'kiosk_forbidden' => 403, 'photos_required' => 400, 'bad_state' => 409 ];
			$code = $map[ (string) $res['error'] ] ?? 400;
			wp_send_json_error( [ 'message' => (string) $res['error'] ], $code );
		}

		$crm         = [ 'ok' => false, 'error' => 'skipped' ];
		$row         = ZJOB_Jobs::get( $id );
		$photo_links = class_exists( 'ZJOB_Photos' ) ? ZJOB_Photos::links_for( $res['media_ids'] ) : [];
		if ( $row && class_exists( 'ZJOB_CRM' ) ) {
			$crm = ZJOB_CRM::provider()->post_completion_note( $row, $photo_links, (bool) $res['verified'], $uid );
		}

		if ( $row && class_exists( 'ZJOB_Notify' ) ) {
			ZJOB_Notify::ready_to_close( $row, $uid );
		}

		wp_send_json_success( [
			'id'       => $id,
			'status'   => (string) $res['status'],
			'verified' => (bool) $res['verified'],
			'location' => (string) ( $res['location'] ?? '' ),
			'photos'   => $photo_links,
			'crm'      => [ 'ok' => (bool) ( $crm['ok'] ?? false ), 'error' => (string) ( $crm['error'] ?? '' ) ],
			'message'  => 'Marked complete. It can now be closed out.',
		] );
	}

	/** The two-party close-out: pending_close -> done (assurance: two_party). */
	public static function close_job(): void {
		$uid = self::gate();
		$id  = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		if ( $id <= 0 ) {
			wp_send_json_error( [ 'message' => 'bad_request' ], 400 );
		}

		$res = ZJOB_Jobs::close_job( $id, $uid );
		if ( empty( $res['ok'] ) ) {
			$map  = [ 'not_found' => 404, 'not_permitted' => 403, 'kiosk_forbidden' => 403, 'not_pending_close' => 409 ];
			$code = $map[ (string) $res['error'] ] ?? 400;
			wp_send_json_error( [ 'message' => (string) $res['error'] ], $code );
		}

		$crm = [ 'ok' => false, 'error' => 'skipped' ];
		$row = ZJOB_Jobs::get( $id );
		if ( $row && class_exists( 'ZJOB_CRM' ) ) {
			$crm = ZJOB_CRM::provider()->close_child_lead( $row, $uid );
		}

		wp_send_json_success( [
			'id'        => $id,
			'status'    => (string) $res['status'],
			'assurance' => (string) ( $res['assurance'] ?? '' ),
			'crm'       => [
				'ok'            => (bool) ( $crm['ok'] ?? false ),
				'error'         => (string) ( $crm['error'] ?? '' ),
				'status_after'  => $crm['status_after'] ?? null,
				'outcome'       => (string) ( $crm['outcome'] ?? '' ),
				'needs_outcome' => (bool) ( $crm['needs_outcome'] ?? false ),
				'steps'         => $crm['steps'] ?? [],
			],
			'message'   => 'Closed out. Nice work.',
		] );
	}

	/**
	 * Solo-operator single-party attestation close. When no distinct second party
	 * exists, the worker closes their own job with a RECORDED attestation reason.
	 * Recorded as single_party_attested (never two_party) and logged as a disposition.
	 */
	public static function self_attest_close(): void {
		$uid    = self::gate();
		$id     = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		$reason = isset( $_POST['reason'] ) ? sanitize_textarea_field( wp_unslash( $_POST['reason'] ) ) : '';
		if ( $id <= 0 ) {
			wp_send_json_error( [ 'message' => 'bad_request' ], 400 );
		}
		$res = ZJOB_Jobs::worker_self_attest_close( $id, $reason, $uid );
		if ( empty( $res['ok'] ) ) {
			$map  = [ 'reason_required' => 400, 'not_found' => 404, 'not_permitted' => 403, 'kiosk_forbidden' => 403, 'not_pending_close' => 409, 'two_party_required' => 409 ];
			$code = $map[ (string) $res['error'] ] ?? 400;
			wp_send_json_error( [ 'message' => (string) $res['error'] ], $code );
		}

		// Mirror the close to the CRM the same not-a-sale way a two-party close does.
		$crm = [ 'ok' => false, 'error' => 'skipped' ];
		$row = ZJOB_Jobs::get( $id );
		if ( $row && class_exists( 'ZJOB_CRM' ) ) {
			$crm = ZJOB_CRM::provider()->close_child_lead( $row, $uid );
		}

		wp_send_json_success( [
			'id'        => $id,
			'status'    => (string) $res['status'],
			'assurance' => (string) ( $res['assurance'] ?? '' ),
			'crm'       => [ 'ok' => (bool) ( $crm['ok'] ?? false ), 'error' => (string) ( $crm['error'] ?? '' ) ],
			'message'   => 'Closed with a recorded single-party attestation.',
		] );
	}

	/** Extend a pending_close job's auto-close deadline. Requires a written reason. */
	public static function extend_close(): void {
		$uid    = self::gate();
		$id     = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		$reason = isset( $_POST['reason'] ) ? sanitize_textarea_field( wp_unslash( $_POST['reason'] ) ) : '';
		$days   = isset( $_POST['days'] ) ? (int) $_POST['days'] : 0;
		if ( $id <= 0 ) {
			wp_send_json_error( [ 'message' => 'bad_request' ], 400 );
		}
		$res = ZJOB_Jobs::extend_close( $id, $reason, $days, $uid );
		if ( empty( $res['ok'] ) ) {
			$map  = [ 'reason_required' => 400, 'not_found' => 404, 'not_permitted' => 403, 'kiosk_forbidden' => 403, 'not_pending_close' => 409 ];
			$code = $map[ (string) $res['error'] ] ?? 400;
			wp_send_json_error( [ 'message' => (string) $res['error'] ], $code );
		}
		wp_send_json_success( [
			'id'             => $id,
			'close_deadline' => (string) ( $res['close_deadline'] ?? '' ),
			'count'          => (int) ( $res['count'] ?? 0 ),
			'message'        => 'Deadline extended.',
		] );
	}

	/** Parse a JSON array or comma-separated list into unique positive ints. */
	private static function parse_id_list( string $raw ): array {
		$raw = trim( $raw );
		if ( $raw === '' ) {
			return [];
		}
		$decoded = json_decode( $raw, true );
		$vals    = is_array( $decoded ) ? $decoded : explode( ',', $raw );
		$out     = [];
		foreach ( $vals as $v ) {
			$n = (int) $v;
			if ( $n > 0 ) {
				$out[] = $n;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Guess the job component from a line-item description.
	 *
	 * Item Engine binding: classification belongs to the catalog. We bind
	 * `zdz_job_classify_component` (return a component key to decide); absent that,
	 * a GENERIC work-type heuristic maps a repair/service line to 'service'; otherwise
	 * the default job kind. NO product name is hardcoded.
	 */
	private static function guess_component( string $text ): string {
		$pre = apply_filters( 'zdz_job_classify_component', null, $text );
		if ( is_string( $pre ) && $pre !== '' ) {
			return sanitize_key( $pre );
		}
		$t = strtolower( $text );
		if ( strpos( $t, 'repair' ) !== false || strpos( $t, 'service' ) !== false
			|| strpos( $t, 'rescreen' ) !== false || strpos( $t, 're-screen' ) !== false || strpos( $t, 'fix' ) !== false ) {
			return 'service';
		}
		return function_exists( 'zjob_default_component' ) ? zjob_default_component() : 'other';
	}

	/**
	 * Best-effort brand/model detection from a line-item description.
	 *
	 * Item Engine binding: brand/model tokens live in the catalog. We bind
	 * `zdz_job_detect_brand` (return the detected brand string); the neutral fallback
	 * is '' — NO brand list is hardcoded.
	 */
	private static function guess_brand( string $text ): string {
		$b = apply_filters( 'zdz_job_detect_brand', '', $text );
		return is_string( $b ) ? sanitize_text_field( $b ) : '';
	}
}
