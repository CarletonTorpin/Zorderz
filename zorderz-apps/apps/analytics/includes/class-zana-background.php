<?php
/**
 * ZANA_Background — run a slow chat turn off the browser request (loopback relay).
 *
 * WHY: a chat turn is fully synchronous. ZANA_Chat::send() calls the model through
 * ZDZ_Core_Poe with a 90-180s blocking wp_remote_post, and the REST route holds the
 * browser request open for the whole call. On a managed host a slow (vault-augmented /
 * thinking) turn can exceed the ~60s gateway timeout and return a 502.
 *
 * HOW (the proven ZEST_Background pattern): the client enqueues a turn (a row in
 * wp_zana_turn_jobs), the enqueue fires a NON-BLOCKING loopback (wp_remote_post with
 * timeout 0.01 to admin-ajax) and returns a job id immediately; the loopback request
 * runs the model call and persists the result; the client polls a status endpoint.
 *
 * NO REGRESSION — this class is ADDITIVE. run_job() calls the exact same
 * ZANA_Chat::send() the synchronous REST route calls, so the prompt assembly, the
 * model gateway, the SINGLE outbound gate (ZDZ_Answer_Authority) and the transcript
 * persistence are byte-for-byte identical. Async only changes WHERE the turn runs,
 * never WHAT it does. If anything about async is unavailable the surface falls back to
 * the synchronous path (see ZANA_REST + assets/js/chat.js).
 *
 * SECURITY: the client-facing enqueue/status endpoints are REST routes gated exactly
 * like the chat route (ZANA_REST::can_access, on the REAL user) and every read is
 * scoped to the caller's own jobs. The loopback runner is a SEPARATE request that
 * cannot carry that gate, so it is signature-gated (wp_hash over job id + the job's
 * stored owner) — a caller cannot trigger a run without the site secret. No identity
 * or business data lives here; a job row holds only the user's own message.
 *
 * @package Zorderz\Analytics
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZANA_Background {

	/** Register the loopback runner + housekeeping. Client endpoints live in ZANA_REST. */
	public static function boot(): void {
		// The loopback runner is a SEPARATE request — register it on every load, not
		// only after an enqueue, or the background turn never runs. Signature-gated.
		add_action( 'wp_ajax_zana_run_loopback', array( __CLASS__, 'ajax_loopback' ) );
		add_action( 'wp_ajax_nopriv_zana_run_loopback', array( __CLASS__, 'ajax_loopback' ) );
		add_action( 'zana_run_turn_job', array( __CLASS__, 'run_job' ) );
		add_action( 'zana_cleanup_stale_jobs', array( __CLASS__, 'cleanup_stale' ) );
		if ( ! wp_next_scheduled( 'zana_cleanup_stale_jobs' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'zana_cleanup_stale_jobs' );
		}
	}

	/**
	 * Enqueue a turn for $user_id and kick a non-blocking loopback runner.
	 * Access is already gated by the REST permission_callback (can_access) on the
	 * REAL user before we get here.
	 *
	 * @return array {ok, job?, error?}
	 */
	public static function enqueue( int $user_id, int $session_id, string $message ): array {
		$message = trim( wp_strip_all_tags( $message ) );
		if ( '' === $message ) {
			return array( 'ok' => false, 'error' => __( 'Empty message.', 'zorderz' ) );
		}

		// Shared-device (kiosk) turns are NEVER persisted (INV-10 / memory-privacy).
		// A job row would be a transient transcript, so we refuse to queue them; the
		// client uses the synchronous route on kiosk (and this ok=false makes any async
		// caller fall back to it too). Kiosk uses the fast slot, so 502 risk is low.
		if ( class_exists( 'ZDZ_Hierarchy' ) && ZDZ_Hierarchy::is_kiosk( $user_id ) ) {
			return array( 'ok' => false, 'error' => __( 'Async is not available on this device.', 'zorderz' ) );
		}

		global $wpdb;
		$now = current_time( 'mysql' );
		$inserted = $wpdb->insert(
			ZANA_DB::jobs_table(),
			array(
				'user_id'    => $user_id,
				'session_id' => $session_id,
				'status'     => 'queued',
				'message'    => $message,
				'created_at' => $now,
				'updated_at' => $now,
			),
			array( '%d', '%d', '%s', '%s', '%s', '%s' )
		);
		$job_id = (int) $wpdb->insert_id;
		if ( ! $inserted || $job_id <= 0 ) {
			return array( 'ok' => false, 'error' => __( 'Could not queue the turn.', 'zorderz' ) );
		}

		// Fire a non-blocking loopback so the HTTP request returns immediately. Cookies
		// are passed so the background turn runs as the same logged-in user (any Core
		// service that reads the current user behaves exactly as on the sync path); the
		// signature — not the cookie — is the authorisation gate on the runner.
		wp_remote_post(
			admin_url( 'admin-ajax.php' ),
			array(
				'timeout'  => 0.01,
				'blocking' => false,
				'body'     => array(
					'action' => 'zana_run_loopback',
					'job'    => $job_id,
					'sig'    => self::sig( $job_id, $user_id ),
				),
				'cookies'  => $_COOKIE,
			)
		);

		return array( 'ok' => true, 'job' => $job_id, 'status' => 'queued' );
	}

	/** The loopback entry — verifies the signature against the job's owner, then runs it. */
	public static function ajax_loopback(): void {
		$job = isset( $_POST['job'] ) ? (int) $_POST['job'] : 0;
		$sig = isset( $_POST['sig'] ) ? sanitize_text_field( wp_unslash( $_POST['sig'] ) ) : '';
		if ( $job <= 0 || '' === $sig ) {
			wp_die( '', '', array( 'response' => 403 ) );
		}
		global $wpdb;
		$owner = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT user_id FROM ' . ZANA_DB::jobs_table() . ' WHERE id = %d', $job )
		);
		if ( $owner <= 0 || ! hash_equals( self::sig( $job, $owner ), $sig ) ) {
			wp_die( '', '', array( 'response' => 403 ) );
		}
		self::run_job( $job );
		wp_die();
	}

	/**
	 * Process one queued turn: run the SAME synchronous turn the sync route runs and
	 * store its result. Guarded so a turn is never double-run.
	 */
	public static function run_job( $job_id ): void {
		global $wpdb;
		$job_id = (int) $job_id;
		$table  = ZANA_DB::jobs_table();
		$row    = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $job_id ), ARRAY_A );
		if ( ! $row || 'queued' !== $row['status'] ) {
			return; // Already running/done/gone — never run a turn twice.
		}
		$wpdb->update(
			$table,
			array( 'status' => 'running', 'updated_at' => current_time( 'mysql' ) ),
			array( 'id' => $job_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		try {
			// IDENTICAL to the synchronous REST path: same prompt, same model gateway,
			// same ZDZ_Answer_Authority gate, same transcript persistence.
			$result = ZANA_Chat::send(
				(int) $row['user_id'],
				(int) $row['session_id'],
				(string) $row['message']
			);
		} catch ( \Throwable $e ) {
			error_log( '[ZANA_Background] turn ' . $job_id . ' failed: ' . $e->getMessage() );
			$wpdb->update(
				$table,
				array(
					'status'     => 'error',
					'error_msg'  => __( 'The assistant hit an unexpected error.', 'zorderz' ),
					'updated_at' => current_time( 'mysql' ),
				),
				array( 'id' => $job_id ),
				array( '%s', '%s', '%s' ),
				array( '%d' )
			);
			return;
		}

		// send() never throws for a model/config failure — it returns ok=false with an
		// honest 'answer'. Store the whole result either way so the client can render
		// that honest message; the status just distinguishes done from error.
		$ok = ! empty( $result['ok'] );
		$wpdb->update(
			$table,
			array(
				'status'      => $ok ? 'done' : 'error',
				'result_json' => wp_json_encode( $result ),
				'error_msg'   => $ok ? '' : (string) ( $result['error'] ?? __( 'The assistant could not answer.', 'zorderz' ) ),
				'updated_at'  => current_time( 'mysql' ),
			),
			array( 'id' => $job_id ),
			array( '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Read a turn's status/result — scoped to the caller's own jobs.
	 *
	 * @return array {ok, status, result?, error?}
	 */
	public static function status( int $user_id, int $job_id ): array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT status, result_json, error_msg FROM ' . ZANA_DB::jobs_table() . ' WHERE id = %d AND user_id = %d',
				$job_id,
				$user_id
			),
			ARRAY_A
		);
		if ( ! $row ) {
			return array( 'ok' => false, 'status' => 'not_found', 'error' => __( 'Turn not found.', 'zorderz' ) );
		}
		$result = ( null !== $row['result_json'] && '' !== (string) $row['result_json'] )
			? json_decode( (string) $row['result_json'], true )
			: null;
		return array(
			'ok'     => true,
			'status' => (string) $row['status'], // queued | running | done | error
			'result' => is_array( $result ) ? $result : null,
			'error'  => (string) $row['error_msg'],
		);
	}

	/** Drop jobs older than a day so the queue never grows without bound. */
	public static function cleanup_stale(): void {
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . ZANA_DB::jobs_table() . ' WHERE created_at < DATE_SUB(NOW(), INTERVAL 1 DAY)' );
	}

	/** Runner authorisation: unforgeable without the site secret; bound to the owner. */
	private static function sig( int $job_id, int $user_id ): string {
		return wp_hash( 'zana_turn_' . $job_id . '|' . $user_id );
	}
}
