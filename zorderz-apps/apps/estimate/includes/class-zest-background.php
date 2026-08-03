<?php
/**
 * ZEST_Background — background parse processing (loopback relay).
 *
 * A photo parse can take 30-90s; running it inline blocks the request. The widget
 * enqueues a job (rows in zest_parse_jobs), a non-blocking loopback request processes
 * it, and the widget polls status. All endpoints are nonce-checked and scoped to the
 * job's own user — a caller can only read/enqueue their own jobs. No identity here.
 *
 * @package Zorderz\Estimate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZEST_Background {

	/** @var ZEST_Estimate_Engine|null */
	private static $engine = null;

	public static function boot( $engine = null ): void {
		if ( $engine instanceof ZEST_Estimate_Engine ) {
			self::$engine = $engine;
		}
		add_action( 'wp_ajax_zest_enqueue_parse', array( __CLASS__, 'ajax_enqueue' ) );
		add_action( 'wp_ajax_zest_job_status', array( __CLASS__, 'ajax_status' ) );
		// The loopback runner is a SEPARATE request — register it on every load, not
		// only after an enqueue, or the background job never runs. Signature-gated.
		add_action( 'wp_ajax_zest_run_loopback', array( __CLASS__, 'ajax_loopback' ) );
		add_action( 'wp_ajax_nopriv_zest_run_loopback', array( __CLASS__, 'ajax_loopback' ) );
		add_action( 'zest_run_parse_job', array( __CLASS__, 'run_job' ) );
		add_action( 'zest_cleanup_stale_jobs', array( __CLASS__, 'cleanup_stale' ) );
		if ( ! wp_next_scheduled( 'zest_cleanup_stale_jobs' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'zest_cleanup_stale_jobs' );
		}
	}

	private static function engine(): ZEST_Estimate_Engine {
		if ( ! self::$engine ) {
			self::$engine = new ZEST_Estimate_Engine();
		}
		return self::$engine;
	}

	/** Enqueue a parse job for the current user and kick a loopback runner. */
	public static function ajax_enqueue(): void {
		if ( ! check_ajax_referer( ZEST_NONCE, 'nonce', false ) || ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => 'Not allowed.' ), 403 );
		}
		global $wpdb;
		$uid   = get_current_user_id();
		$text  = isset( $_POST['text'] ) ? wp_kses_post( wp_unslash( $_POST['text'] ) ) : '';
		$imgs  = isset( $_POST['images'] ) ? array_map( 'esc_url_raw', (array) wp_unslash( $_POST['images'] ) ) : array();
		$mode  = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : '';
		$kind  = isset( $_POST['kind'] ) ? sanitize_key( wp_unslash( $_POST['kind'] ) ) : '';
		$ctx   = array( 'user_id' => $uid );
		// Milestone #54: an 'import' job carries the extracted PDF text of an existing
		// business's estimate/invoice — parsed VERBATIM (no pricing) by parse_document().
		if ( 'import' === $mode ) {
			$ctx['mode'] = 'import';
			if ( in_array( $kind, array( 'estimate', 'invoice' ), true ) ) {
				$ctx['kind'] = $kind;
			}
		}

		$wpdb->insert( ZEST_DB::jobs_table(), array(
			'user_id'      => $uid,
			'status'       => 'queued',
			'input_text'   => $text,
			'image_urls'   => wp_json_encode( $imgs ),
			'context_json' => wp_json_encode( $ctx ),
		) );
		$job_id = (int) $wpdb->insert_id;
		ZEST_Progress::set( $job_id, 'queued', 5 );

		// Fire a non-blocking loopback so the request returns immediately.
		wp_remote_post( admin_url( 'admin-ajax.php' ), array(
			'timeout'   => 0.01,
			'blocking'  => false,
			'body'      => array( 'action' => 'zest_run_loopback', 'job' => $job_id, 'sig' => self::sig( $job_id ) ),
			'cookies'   => $_COOKIE,
		) );

		wp_send_json_success( array( 'job' => $job_id ) );
	}

	/** The loopback entry — verifies the signature, then runs the job. */
	public static function ajax_loopback(): void {
		$job = isset( $_POST['job'] ) ? (int) $_POST['job'] : 0;
		$sig = isset( $_POST['sig'] ) ? sanitize_text_field( wp_unslash( $_POST['sig'] ) ) : '';
		if ( $job <= 0 || ! hash_equals( self::sig( $job ), $sig ) ) {
			wp_die( '', '', array( 'response' => 403 ) );
		}
		self::run_job( $job );
		wp_die();
	}

	/** Process one queued job: parse (vision when images present) and store the result. */
	public static function run_job( $job_id ): void {
		global $wpdb;
		$job_id = (int) $job_id;
		$table  = ZEST_DB::jobs_table();
		$row    = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $job_id ), ARRAY_A );
		if ( ! $row || 'queued' !== $row['status'] ) {
			return;
		}
		$wpdb->update( $table, array( 'status' => 'running', 'updated_at' => current_time( 'mysql' ) ), array( 'id' => $job_id ) );
		ZEST_Progress::set( $job_id, 'reading', 30 );

		$ctx    = (array) json_decode( (string) $row['context_json'], true );
		$images = (array) json_decode( (string) $row['image_urls'], true );
		$engine = self::engine();

		if ( 'import' === ( $ctx['mode'] ?? '' ) ) {
			// Verbatim import parse: returns { ok, doc, warnings, error } — no pricing.
			$result = $engine->parse_document( (string) $row['input_text'], $ctx );
		} elseif ( ! empty( $images ) ) {
			$result = $engine->parse_vision( $images, $ctx );
		} else {
			$result = $engine->parse( (string) $row['input_text'], $ctx );
		}

		ZEST_Progress::set( $job_id, 'pricing', 80 );
		$ok = ! empty( $result['ok'] );
		$wpdb->update( $table, array(
			'status'      => $ok ? 'done' : 'error',
			'result_json' => wp_json_encode( $result ),
			'error_msg'   => $ok ? '' : (string) ( $result['error'] ?? 'Parse failed.' ),
			'updated_at'  => current_time( 'mysql' ),
		), array( 'id' => $job_id ) );
		ZEST_Progress::set( $job_id, $ok ? 'done' : 'error', 100 );
	}

	/** Poll a job's status/result — scoped to the caller's own jobs. */
	public static function ajax_status(): void {
		if ( ! check_ajax_referer( ZEST_NONCE, 'nonce', false ) || ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => 'Not allowed.' ), 403 );
		}
		global $wpdb;
		$job_id = isset( $_POST['job'] ) ? (int) $_POST['job'] : 0;
		$row = $wpdb->get_row( $wpdb->prepare(
			'SELECT * FROM ' . ZEST_DB::jobs_table() . ' WHERE id = %d AND user_id = %d',
			$job_id, get_current_user_id()
		), ARRAY_A );
		if ( ! $row ) {
			wp_send_json_error( array( 'message' => 'Job not found.' ), 404 );
		}
		wp_send_json_success( array(
			'status'   => $row['status'],
			'progress' => ZEST_Progress::get( $job_id ),
			'result'   => 'done' === $row['status'] ? json_decode( (string) $row['result_json'], true ) : null,
			'error'    => (string) $row['error_msg'],
		) );
	}

	/** Drop jobs older than a day so the queue never grows without bound. */
	public static function cleanup_stale(): void {
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . ZEST_DB::jobs_table() . ' WHERE created_at < DATE_SUB(NOW(), INTERVAL 1 DAY)' );
	}

	private static function sig( int $job_id ): string {
		return wp_hash( 'zest_job_' . $job_id . '|' . get_current_user_id() );
	}
}
