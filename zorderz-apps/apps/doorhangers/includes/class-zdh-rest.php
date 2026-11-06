<?php
/**
 * REST API (the platform's namespace, under /doorhangers). Every route requires a signed-in person who holds the app; WordPress' cookie + nonce
 * handling authenticates the requests.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZDH_REST {

	const BASE = '/doorhangers';   // every route sits under the platform's namespace: <ZDZ_REST_NS>/doorhangers/...

	/** The platform's REST namespace. */
	public static function ns() {
		return defined( 'ZDZ_REST_NS' ) ? ZDZ_REST_NS : 'zorderz/v1';
	}

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	/**
	 * Who may call these routes. The theme decides who HOLDS the app (ZDZ_Plugin_API::user_can_access_app:
	 * Safe Mode -> denied -> admin tier -> zdz_allowed_apps -> the role default); the shared kiosk is refused everywhere.
	 * There is no shared-password mode: a request is a signed-in person with a REST nonce, or it is nothing.
	 */
	public static function can_use() {
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'rest_forbidden', 'Please sign in to use Doorhangers.', array( 'status' => 401 ) );
		}
		if ( ! zdh_user_can_use( get_current_user_id() ) ) {
			return new WP_Error( 'rest_forbidden', zdh_is_kiosk( get_current_user_id() ) ? 'Doorhangers is not available on the shared device.' : 'Your account does not hold the Doorhangers app. Ask an administrator.', array( 'status' => 403 ) );
		}
		return true;
	}
	/** Deleting a drop, the cross-check for someone else, importing: the admin tier. */
	public static function can_admin() {
		$ok = self::can_use();
		if ( true !== $ok ) {
			return $ok;
		}
		return zdh_user_is_admin( get_current_user_id() ) ? true : new WP_Error( 'rest_forbidden', 'Only an administrator can do that.', array( 'status' => 403 ) );
	}
	/** Site-level diagnostics and the saved phone reports: the admin tier too. */
	public static function can_manage() {
		return self::can_admin();
	}

	public static function routes() {
		$use   = array( __CLASS__, 'can_use' );
		$admin = array( __CLASS__, 'can_admin' );
		register_rest_route( self::ns(), self::BASE . '/upload', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'upload' ), 'permission_callback' => $use ) );
		register_rest_route( self::ns(), self::BASE . '/analyze-image', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'analyze_image' ), 'permission_callback' => $use ) );
		register_rest_route( self::ns(), self::BASE . '/analyze-status', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'analyze_status' ), 'permission_callback' => $use ) );
		// the vision job runs in WP-Cron (ZDH_REST::run_job_cron), never through a public route.
		register_rest_route( self::ns(), self::BASE . '/import', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'import_backup' ), 'permission_callback' => $admin ) );
		register_rest_route( self::ns(), self::BASE . '/people', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'people' ), 'permission_callback' => $use ) );
		register_rest_route( self::ns(), self::BASE . '/nosolicit', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'nosolicit' ), 'permission_callback' => $use ) );
		register_rest_route( self::ns(), self::BASE . '/find-addresses', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'find_addresses' ), 'permission_callback' => $use ) );
		register_rest_route( self::ns(), self::BASE . '/find-in-area', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'find_in_area' ), 'permission_callback' => $use ) );
		register_rest_route( self::ns(), self::BASE . '/georeference', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'georeference' ), 'permission_callback' => $use ) );
		register_rest_route( self::ns(), self::BASE . '/zip-extent', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'zip_extent' ), 'permission_callback' => $use ) );
		register_rest_route( self::ns(), self::BASE . '/drops/(?P<id>\d+)/done', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'mark_done' ), 'permission_callback' => $use ) );
		register_rest_route( self::ns(), self::BASE . '/drops/(?P<id>\d+)/start', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'start_delivery' ), 'permission_callback' => $use ) );
		register_rest_route( self::ns(), self::BASE . '/drops/(?P<id>\d+)/reset', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'reset_delivery' ), 'permission_callback' => $use ) );
		register_rest_route( self::ns(), self::BASE . '/drops/(?P<id>\d+)/progress', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'progress' ), 'permission_callback' => $use ) );
		register_rest_route( self::ns(), self::BASE . '/drops/(?P<id>\d+)/finish', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'finish_delivery' ), 'permission_callback' => $use ) );
		register_rest_route( self::ns(), self::BASE . '/drops/(?P<id>\d+)/houses', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'replace_houses' ), 'permission_callback' => $use ) );
		register_rest_route( self::ns(), self::BASE . '/drops/(?P<id>\d+)/walks', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'set_walks' ), 'permission_callback' => $use ) );
		register_rest_route( self::ns(), self::BASE . '/drops/(?P<id>\d+)/discard-quick', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'discard_quick' ), 'permission_callback' => $use ) );
		register_rest_route( self::ns(), self::BASE . '/drops', array(
			array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'list_drops' ), 'permission_callback' => $use ),
			array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'save_drop' ), 'permission_callback' => $use ),
		) );
		register_rest_route( self::ns(), self::BASE . '/drops/(?P<id>\d+)', array(
			array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'get_drop' ), 'permission_callback' => $use ),
			array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'update_drop' ), 'permission_callback' => $use ),
			array( 'methods' => 'DELETE', 'callback' => array( __CLASS__, 'delete_drop' ), 'permission_callback' => $admin ),
		) );
		register_rest_route( self::ns(), self::BASE . '/drops/(?P<id>\d+)/enrich', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'enrich_drop' ), 'permission_callback' => $use ) );
		register_rest_route( self::ns(), self::BASE . '/lookup', array(
			array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'lookup' ), 'permission_callback' => $use ),
			array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'lookup' ), 'permission_callback' => $use ),
		) );
		register_rest_route( self::ns(), self::BASE . '/export', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'export' ), 'permission_callback' => $use ) );
		register_rest_route( self::ns(), self::BASE . '/stats', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'stats' ), 'permission_callback' => $use ) );
		register_rest_route( self::ns(), self::BASE . '/diagnostics', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'diagnostics' ), 'permission_callback' => array( __CLASS__, 'can_manage' ) ) );
		// The CRM (read-only): the team runs the cross-check; the user list and the test are for the settings page
		register_rest_route( self::ns(), self::BASE . '/crm/crosscheck', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'nutshell_crosscheck' ), 'permission_callback' => $use ) );
		register_rest_route( self::ns(), self::BASE . '/crm/users', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'nutshell_users' ), 'permission_callback' => array( __CLASS__, 'can_manage' ) ) );
		register_rest_route( self::ns(), self::BASE . '/crm/check', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'nutshell_check' ), 'permission_callback' => array( __CLASS__, 'can_manage' ) ) );
		// a phone's diagnostics report: any team member can send one; reading / clearing them is for administrators
		register_rest_route( self::ns(), self::BASE . '/diag-report', array(
			array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'diag_report_save' ), 'permission_callback' => $use ),
			array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'diag_report_list' ), 'permission_callback' => array( __CLASS__, 'can_manage' ) ),
			array( 'methods' => 'DELETE', 'callback' => array( __CLASS__, 'diag_report_clear' ), 'permission_callback' => array( __CLASS__, 'can_manage' ) ),
		) );
	}

	/* ------------------------------------------------------------- upload */

	/** Save phone screenshots to the media library. Field name: files[] */
	public static function upload( WP_REST_Request $req ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$files = $req->get_file_params();
		if ( empty( $files['files'] ) ) {
			return new WP_Error( 'no_files', 'No files received.', array( 'status' => 400 ) );
		}
		$f     = $files['files'];
		$names = is_array( $f['name'] ) ? $f['name'] : array( $f['name'] );
		$out   = array();
		$mimes = array( 'jpg|jpeg|jpe' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp', 'heic' => 'image/heic', 'heif' => 'image/heif' );
		foreach ( $names as $i => $name ) {
			$_FILES['zdh_single'] = array(
				'name'     => is_array( $f['name'] ) ? $f['name'][ $i ] : $f['name'],
				'type'     => is_array( $f['type'] ) ? $f['type'][ $i ] : $f['type'],
				'tmp_name' => is_array( $f['tmp_name'] ) ? $f['tmp_name'][ $i ] : $f['tmp_name'],
				'error'    => is_array( $f['error'] ) ? $f['error'][ $i ] : $f['error'],
				'size'     => is_array( $f['size'] ) ? $f['size'][ $i ] : $f['size'],
			);
			$id = media_handle_upload( 'zdh_single', 0, array(
				'post_title' => 'Door hanger screenshot ' . current_time( 'Y-m-d H:i' ),
			), array( 'test_form' => false, 'mimes' => $mimes ) );
			unset( $_FILES['zdh_single'] );
			if ( is_wp_error( $id ) ) {
				return new WP_Error( 'upload_failed', 'Upload failed for ' . sanitize_file_name( $name ) . ': ' . $id->get_error_message(), array( 'status' => 400 ) );
			}
			update_post_meta( $id, '_zdh', 1 );
			$out[] = array( 'id' => (int) $id, 'url' => wp_get_attachment_url( $id ), 'thumb' => wp_get_attachment_image_url( $id, 'medium' ) ?: wp_get_attachment_url( $id ) );
		}
		return rest_ensure_response( array( 'files' => $out ) );
	}

	/** Downscale an attachment to a JPEG the model can read quickly and return it base64-encoded. */
	private static function image_payload( $attachment_id ) {
		$path = get_attached_file( $attachment_id );
		if ( ! $path || ! file_exists( $path ) ) {
			return null;
		}
		$editor = wp_get_image_editor( $path );
		if ( ! is_wp_error( $editor ) ) {
			$editor->resize( 1800, 1800, false );
			$editor->set_quality( 88 );
			$tmp = trailingslashit( get_temp_dir() ) . uniqid( 'zdh-llm-', true ) . '.jpg';
			$res = $editor->save( $tmp, 'image/jpeg' );
			if ( ! is_wp_error( $res ) && ! empty( $res['path'] ) && file_exists( $res['path'] ) ) {
				$data = base64_encode( file_get_contents( $res['path'] ) );
				@unlink( $res['path'] );
				if ( $res['path'] !== $tmp ) {
					@unlink( $tmp );
				}
				return array( 'mime' => 'image/jpeg', 'data' => $data );
			}
			@unlink( $tmp );
		}
		$mime = get_post_mime_type( $attachment_id ) ?: 'image/png';
		return array( 'mime' => $mime, 'data' => base64_encode( file_get_contents( $path ) ) );
	}

	/* ------------------------------------------------------------ analyze */

	private static function hints_from( $req ) {
		$h = (array) $req->get_param( 'hints' );
		return array(
			'zip'          => isset( $h['zip'] ) ? preg_replace( '/[^0-9]/', '', (string) $h['zip'] ) : '',
			'city'         => isset( $h['city'] ) ? sanitize_text_field( $h['city'] ) : '',
			'state'        => isset( $h['state'] ) && '' !== trim( (string) $h['state'] ) ? strtoupper( substr( sanitize_text_field( $h['state'] ), 0, 10 ) ) : zdh_area_defaults()['state'],
			'date'         => isset( $h['date'] ) ? sanitize_text_field( $h['date'] ) : '',
			'time'         => isset( $h['time'] ) ? sanitize_text_field( $h['time'] ) : '',
			'notes'        => isset( $h['notes'] ) ? sanitize_textarea_field( $h['notes'] ) : '',
			'delivered_by' => isset( $h['delivered_by'] ) ? sanitize_text_field( $h['delivered_by'] ) : '',
			'lat'          => isset( $h['lat'] ) && is_numeric( $h['lat'] ) ? (float) $h['lat'] : null,
			'lng'          => isset( $h['lng'] ) && is_numeric( $h['lng'] ) ? (float) $h['lng'] : null,
		);
	}

	/** Collect the (resized, base64) screenshots for a list of attachment ids. */
	private static function images_for( $ids ) {
		$images = array();
		foreach ( array_slice( $ids, 0, 4 ) as $id ) {
			if ( ! get_post_meta( $id, '_zdh', true ) ) {
				continue; // only screenshots uploaded through this tool
			}
			$p = self::image_payload( $id );
			if ( $p ) {
				$images[] = $p;
			}
		}
		return $images;
	}

	/**
	 * Start the vision analysis. The model call takes 20–90 s, so it normally runs in a background
	 * worker (a loopback request) and the browser polls /analyze-status. If the loopback cannot be
	 * started, or the client asks for sync=1, the analysis runs inline instead.
	 */
	public static function analyze_image( WP_REST_Request $req ) {
		$ids = array_values( array_filter( array_map( 'intval', (array) $req->get_param( 'attachment_ids' ) ) ) );
		if ( ! $ids ) {
			return new WP_Error( 'no_images', 'No screenshots were uploaded.', array( 'status' => 400 ) );
		}
		$hints = self::hints_from( $req );
		if ( ! ZDH_LLM::ready() ) {
			return new WP_Error( 'no_key', 'No Poe key is set (Zorderz -> Credentials).', array( 'status' => 400 ) );
		}
		// The browser gave up waiting on a queued job and asks for the direct call: that job is cancelled first, so the
		// cron run never makes a SECOND Poe call for the same images.
		$cancel = preg_replace( '/[^A-Za-z0-9]/', '', (string) $req->get_param( 'cancel' ) );
		if ( '' !== $cancel ) {
			$old = get_transient( 'zdh_job_' . $cancel );
			if ( is_array( $old ) && (int) $old['user'] === get_current_user_id() ) {
				delete_transient( 'zdh_job_' . $cancel );
				wp_clear_scheduled_hook( 'zdh_run_vision_job', array( $cancel ) );
			}
		}
		if ( ! $req->get_param( 'sync' ) ) {
			$job = wp_generate_password( 16, false );
			set_transient( 'zdh_job_' . $job, array(
				'status'  => 'queued',
				'created' => time(),
				'user'    => get_current_user_id(),
				'ids'     => $ids,
				'hints'   => $hints,
			), 20 * MINUTE_IN_SECONDS );
			// A WP-Cron single event, spawned now. With DISABLE_WP_CRON the host's system cron runs it instead (the job's
			// id is in the args, so two analyses in one second are two events). Nothing is public.
			$when      = time();
			$scheduled = wp_schedule_single_event( $when, 'zdh_run_vision_job', array( $job ) );
			if ( false !== $scheduled && ! is_wp_error( $scheduled ) ) {
				if ( ! ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) && function_exists( 'spawn_cron' ) ) {
					spawn_cron();
				}
				return rest_ensure_response( array( 'job' => $job ) );
			}
			wp_unschedule_event( $when, 'zdh_run_vision_job', array( $job ) );
			delete_transient( 'zdh_job_' . $job );
		}
		// Inline fallback
		@set_time_limit( 240 );
		$images = self::images_for( $ids );
		if ( ! $images ) {
			return new WP_Error( 'no_images', 'Could not read the uploaded pictures.', array( 'status' => 400 ) );
		}
		if ( $req->get_param( 'keepalive' ) ) {
			self::analyze_keepalive( $images, $hints ); // answers and exits (returns only in the test suite)
			return null;
		}
		$analysis = ZDH_LLM::analyze( $images, $hints );
		if ( is_wp_error( $analysis ) ) {
			return new WP_Error( $analysis->get_error_code(), $analysis->get_error_message(), array( 'status' => 502 ) );
		}
		return rest_ensure_response( array( 'analysis' => $analysis ) );
	}

	/**
	 * the direct reading, kept alive. A web host's gateway can cut off a request that has sent nothing for about a
	 * minute (a 502 or 504 from its proxy), and the map reader can take longer than that on a photo of a paper sheet. So
	 * the answer starts at once: the headers and 2 KB of spaces, then a space every 5 s while the reader works, then the
	 * JSON (leading spaces are valid JSON). The status has gone out already, so an error is {"error": true, "code",
	 * "message", "secs"} with HTTP 200. Called from analyze_image; never returns.
	 */
	private static function analyze_keepalive( $images, $hints ) {
		ignore_user_abort( false );
		while ( ob_get_level() > 0 ) {
			@ob_end_flush();
		}
		@ini_set( 'zlib.output_compression', '0' );
		@ini_set( 'output_buffering', '0' );
		if ( function_exists( 'apache_setenv' ) ) {
			@apache_setenv( 'no-gzip', '1' );
		}
		if ( ! headers_sent() ) {
			nocache_headers();
			header( 'Content-Type: application/json; charset=utf-8' );
			header( 'X-Accel-Buffering: no' );  // nginx: pass the bytes straight on
			header( 'Content-Encoding: none' ); // and don't hold them back to compress
		}
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed padding spaces that flush the headers through output buffers
		echo str_repeat( ' ', 2048 ); // pushes the headers through any buffer on the way
		@flush();
		$last = microtime( true );
		$tick = function () use ( &$last ) {
			if ( microtime( true ) - $last >= self::KEEPALIVE_S ) {
				$last = microtime( true );
				echo ' ';
				@flush();
			}
		};
		$t0     = microtime( true );
		$result = ZDH_LLM::analyze( $images, $hints, $tick );
		$secs   = round( microtime( true ) - $t0, 1 );
		echo is_wp_error( $result )
			? wp_json_encode( array( 'error' => true, 'code' => $result->get_error_code(), 'message' => $result->get_error_message(), 'secs' => $secs ) )
			: wp_json_encode( array( 'analysis' => $result, 'secs' => $secs ) );
		@flush();
		if ( defined( 'ZDH_TESTING' ) && ZDH_TESTING ) {
			return; // the test suite captures the output instead
		}
		exit;
	}

	/** Seconds between the kept-alive spaces (a constant so the gateway test can see it). */
	const KEEPALIVE_S = 5;

	/** Background worker (WP-Cron, action zdh_run_vision_job). */
	public static function run_job_cron( $job ) {
		$job  = preg_replace( '/[^A-Za-z0-9]/', '', (string) $job );
		$data = $job ? get_transient( 'zdh_job_' . $job ) : false;
		if ( ! $data || 'queued' !== $data['status'] ) {
			return;
		}
		ignore_user_abort( true );
		@set_time_limit( 300 );
		$data['status']  = 'running';
		$data['started'] = time();
		$data['stage']   = 'preparing the picture';
		// what the host is, so a phone's event log can say why a reading stopped
		$data['server']  = substr( isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : 'PHP ' . PHP_VERSION, 0, 40 ) . ' · PHP limit ' . (int) ini_get( 'max_execution_time' ) . ' s';
		set_transient( 'zdh_job_' . $job, $data, 20 * MINUTE_IN_SECONDS );
		// if PHP dies in here (a time limit, out of memory), the job says so instead of staying "running" forever
		register_shutdown_function( array( __CLASS__, 'job_died' ), $job );

		$images = self::images_for( $data['ids'] );
		if ( $images ) {
			$data['stage'] = 'waiting for the map reader (' . ZDH_LLM::model() . ')';
			$data['kb']    = (int) round( strlen( $images[0]['data'] ) * 0.75 / 1024 );
			set_transient( 'zdh_job_' . $job, $data, 20 * MINUTE_IN_SECONDS );
		}
		// a heartbeat every 10 s while the reader works: a worker the host killed stops beating, and the phone notices
		$beat = time();
		$data['beat'] = $beat;
		$cancelled = false;
		$tick = function () use ( &$beat, &$data, $job, &$cancelled ) {
			if ( $cancelled ) {
				return false;
			}
			if ( time() - $beat >= 10 ) {
				// the phone went to the direct read and cancelled this job: stop the reader's transfer (no second paid
				// reading) and write nothing back
				if ( false === get_transient( 'zdh_job_' . $job ) ) {
					$cancelled = true;
					return false;
				}
				$beat          = time();
				$data['beat']  = $beat;
				$data['beats'] = (int) ( $data['beats'] ?? 0 ) + 1;
				set_transient( 'zdh_job_' . $job, $data, 20 * MINUTE_IN_SECONDS );
			}
		};
		$t0     = microtime( true );
		$result = $images ? ZDH_LLM::analyze( $images, $data['hints'], $tick ) : new WP_Error( 'no_images', 'Could not read the uploaded pictures.' );
		if ( $cancelled || false === get_transient( 'zdh_job_' . $job ) ) {
			return rest_ensure_response( array( 'ok' => false, 'cancelled' => true ) );
		}
		$data['reader_s'] = round( microtime( true ) - $t0, 1 );
		if ( is_wp_error( $result ) ) {
			$data['status'] = 'error';
			$data['error']  = $result->get_error_message();
		} else {
			$data['status']   = 'done';
			$data['analysis'] = $result;
		}
		$data['finished'] = time();
		unset( $data['secret'] );
		set_transient( 'zdh_job_' . $job, $data, 20 * MINUTE_IN_SECONDS );
		return rest_ensure_response( array( 'ok' => true ) );
	}

	/** the shutdown hook of a vision job — a job still "running" when PHP stops was stopped by the server. */
	public static function job_died( $job ) {
		$d = get_transient( 'zdh_job_' . $job );
		if ( ! is_array( $d ) || 'running' !== ( $d['status'] ?? '' ) ) {
			return;
		}
		$e           = error_get_last();
		$d['status'] = 'error';
		$d['error']  = 'The server stopped the reading while ' . ( $d['stage'] ?? 'working' ) . ' (after ' . ( time() - (int) ( $d['started'] ?? time() ) ) . ' s' . ( $e ? ': ' . substr( (string) $e['message'], 0, 160 ) : '' ) . ').';
		set_transient( 'zdh_job_' . $job, $d, 20 * MINUTE_IN_SECONDS );
	}

	/** Polled by the browser every few seconds. */
	public static function analyze_status( WP_REST_Request $req ) {
		nocache_headers();
		$job  = preg_replace( '/[^A-Za-z0-9]/', '', (string) $req->get_param( 'job' ) );
		$data = $job ? get_transient( 'zdh_job_' . $job ) : false;
		if ( ! $data || (int) $data['user'] !== get_current_user_id() ) {
			return new WP_Error( 'bad_job', 'Unknown or expired job.', array( 'status' => 404 ) );
		}
		$out = array( 'status' => $data['status'], 'elapsed' => time() - (int) $data['created'] );
		// the worker's step and host for the phone's event log, and how long since its last heartbeat
		foreach ( array( 'stage', 'server', 'kb', 'reader_s' ) as $k ) {
			if ( isset( $data[ $k ] ) ) {
				$out[ $k ] = $data[ $k ];
			}
		}
		if ( 'running' === $data['status'] ) {
			$out['running_s'] = time() - (int) ( $data['started'] ?? $data['created'] );
			if ( ! empty( $data['beats'] ) ) {
				$out['quiet_s'] = time() - (int) $data['beat'];
			}
		}
		if ( 'done' === $data['status'] ) {
			$out['analysis'] = $data['analysis'];
			delete_transient( 'zdh_job_' . $job );
		} elseif ( 'error' === $data['status'] ) {
			$out['error'] = $data['error'];
			delete_transient( 'zdh_job_' . $job );
		}
		return rest_ensure_response( $out );
	}

	public static function find_addresses( WP_REST_Request $req ) {
		@set_time_limit( 180 );
		$streets_in = (array) $req->get_param( 'streets' );
		$streets    = array();
		foreach ( array_slice( $streets_in, 0, 25 ) as $s ) {
			$name = isset( $s['name'] ) ? sanitize_text_field( $s['name'] ) : '';
			if ( $name === '' ) {
				continue;
			}
			$streets[] = array(
				'name'     => $name,
				'coverage' => ( isset( $s['coverage'] ) && 'partial' === $s['coverage'] ) ? 'partial' : 'full',
				'from'     => isset( $s['from'] ) ? sanitize_text_field( $s['from'] ) : '',
				'to'       => isset( $s['to'] ) ? sanitize_text_field( $s['to'] ) : '',
			);
		}
		if ( ! $streets ) {
			return new WP_Error( 'no_streets', 'Add at least one street.', array( 'status' => 400 ) );
		}
		$cross = array();
		foreach ( array_slice( (array) $req->get_param( 'cross' ), 0, 25 ) as $c ) {
			$c = sanitize_text_field( (string) $c );
			if ( $c !== '' ) {
				$cross[] = $c;
			}
		}
		foreach ( $streets as $s ) {
			if ( $s['from'] ) { $cross[] = $s['from']; }
			if ( $s['to'] ) { $cross[] = $s['to']; }
		}
		$res = ZDH_Geo::find( $streets, array_unique( $cross ), self::hints_from( $req ) );
		if ( is_wp_error( $res ) ) {
			return new WP_Error( $res->get_error_code(), $res->get_error_message(), array( 'status' => 502 ) );
		}
		return rest_ensure_response( $res );
	}

	/** Houses inside hand-drawn shapes (map pen) or everything in an envelope (screenshot georeferencing). */
	public static function find_in_area( WP_REST_Request $req ) {
		@set_time_limit( 120 );
		$shapes = array();
		foreach ( array_slice( (array) $req->get_param( 'shapes' ), 0, 20 ) as $sh ) {
			if ( ! is_array( $sh ) || empty( $sh['points'] ) || ! is_array( $sh['points'] ) ) {
				continue;
			}
			$pts = array();
			foreach ( array_slice( $sh['points'], 0, 2000 ) as $pt ) {
				if ( is_array( $pt ) && count( $pt ) >= 2 && is_numeric( $pt[0] ) && is_numeric( $pt[1] ) ) {
					$pts[] = array( round( (float) $pt[0], 7 ), round( (float) $pt[1], 7 ) );
				}
			}
			if ( count( $pts ) >= 2 ) {
				$shapes[] = array( 'type' => ( isset( $sh['type'] ) && 'loop' === $sh['type'] ) ? 'loop' : 'path', 'points' => $pts, 'buffer_m' => isset( $sh['buffer_m'] ) ? max( 5, min( 80, (float) $sh['buffer_m'] ) ) : 30 );
			}
		}
		$env = null;
		$e   = $req->get_param( 'envelope' );
		if ( is_array( $e ) && isset( $e['xmin'], $e['ymin'], $e['xmax'], $e['ymax'] ) ) {
			$env = array( 'xmin' => (float) $e['xmin'], 'ymin' => (float) $e['ymin'], 'xmax' => (float) $e['xmax'], 'ymax' => (float) $e['ymax'] );
		}
		if ( ! $shapes && ! $env ) {
			return new WP_Error( 'no_area', 'Draw a route first.', array( 'status' => 400 ) );
		}
		$res = ZDH_Geo::find_in_area( $shapes, $env );
		if ( is_wp_error( $res ) ) {
			return new WP_Error( $res->get_error_code(), $res->get_error_message(), array( 'status' => 'too_big' === $res->get_error_code() ? 400 : 502 ) );
		}
		$res['shapes'] = $shapes;
		if ( ! empty( $res['addresses'] ) && is_array( $res['addresses'] ) ) { $res['addresses'] = ZDH_NoSolicit::tag( $res['addresses'], 'selected' ); } // a No Soliciting house is offered red and unticked
		return rest_ensure_response( $res );
	}

	/** Resolve the screenshot's intersection anchors to coordinates. */
	public static function georeference( WP_REST_Request $req ) {
		@set_time_limit( 90 );
		$anchors = array();
		foreach ( array_slice( (array) $req->get_param( 'anchors' ), 0, 8 ) as $an ) {
			if ( is_array( $an ) && ! empty( $an['a'] ) && ! empty( $an['b'] ) && isset( $an['x'], $an['y'] ) && is_numeric( $an['x'] ) && is_numeric( $an['y'] ) ) {
				$anchors[] = array( 'a' => sanitize_text_field( $an['a'] ), 'b' => sanitize_text_field( $an['b'] ), 'x' => (float) $an['x'], 'y' => (float) $an['y'] );
			}
		}
		if ( count( $anchors ) < 2 ) {
			return rest_ensure_response( array( 'anchors' => array(), 'segments' => array() ) );
		}
		$hints = self::hints_from( $req );
		$hint  = ( $hints['lat'] && $hints['lng'] ) ? array( $hints['lat'], $hints['lng'] ) : null;
		$res   = ZDH_Geo::resolve_intersections( $anchors, $hints['zip'], $hint );
		return rest_ensure_response( $res );
	}

	public static function zip_extent( WP_REST_Request $req ) {
		$env = ZDH_Geo::zip_extent( (string) $req->get_param( 'zip' ) );
		if ( ! $env ) {
			return new WP_Error( 'not_found', __( 'That ZIP is not on the address source\'s map.', 'zorderz' ), array( 'status' => 404 ) );
		}
		return rest_ensure_response( array( 'envelope' => $env ) );
	}

	public static function start_delivery( WP_REST_Request $req ) {
		$id = (int) $req['id'];
		$d  = ZDH_DB::get_drop( $id );
		if ( ! $d ) {
			return new WP_Error( 'not_found', 'Cluster not found.', array( 'status' => 404 ) );
		}
		if ( 'done' === $d['status'] ) {
			return new WP_Error( 'done', 'This cluster is already completed.', array( 'status' => 400 ) );
		}
		ZDH_DB::start_delivery( $id, self::clean_datetime( $req->get_param( 'started_at' ) ) ); // the phone's own clock when given
		$walk = (int) $req->get_param( 'walk' );
		if ( $walk > 0 ) { ZDH_DB::start_walk( $id, $walk ); }
		return rest_ensure_response( ZDH_DB::get_drop( $id ) );
	}

	/** Put an in-progress cluster back to planned (clears the walk; keeps route + houses). */
	public static function reset_delivery( WP_REST_Request $req ) {
		$id = (int) $req['id'];
		$d  = ZDH_DB::get_drop( $id );
		if ( ! $d ) {
			return new WP_Error( 'not_found', 'Cluster not found.', array( 'status' => 404 ) );
		}
		if ( 'done' === $d['status'] ) {
			return new WP_Error( 'done', 'This cluster is already completed — use Edit if you need to change it.', array( 'status' => 400 ) );
		}
		ZDH_DB::reset_delivery( $id );
		return rest_ensure_response( ZDH_DB::get_drop( $id ) );
	}

	/** Called every ~20 s while delivering (and via sendBeacon when the app goes to the background). */
	public static function progress( WP_REST_Request $req ) {
		$id = (int) $req['id'];
		$d  = ZDH_DB::get_drop( $id );
		if ( ! $d ) {
			return new WP_Error( 'not_found', 'Cluster not found.', array( 'status' => 404 ) );
		}
		if ( 'planned' === $d['status'] ) {
			ZDH_DB::start_delivery( $id );
		}
		$counts = self::apply_progress( $req, $id );
		return rest_ensure_response( $counts );
	}

	/** The phone's ticks / unticks / new track points from a progress or finish call → the database (repeats are harmless). */
	private static function apply_progress( WP_REST_Request $req, $id ) {
		$del   = array_slice( array_map( 'intval', (array) $req->get_param( 'delivered' ) ), 0, 5000 );
		$undel = array_slice( array_map( 'intval', (array) $req->get_param( 'undelivered' ) ), 0, 5000 );
		$track = array();
		foreach ( array_slice( (array) $req->get_param( 'track' ), 0, 3000 ) as $pt ) {
			if ( is_array( $pt ) && count( $pt ) >= 3 && is_numeric( $pt[0] ) && is_numeric( $pt[1] ) ) {
				$row = array( round( (float) $pt[0], 6 ), round( (float) $pt[1], 6 ), (int) $pt[2], isset( $pt[3] ) ? (int) $pt[3] : null );
				if ( isset( $pt[4] ) && 'd' === $pt[4] ) { $row[] = 'd'; }
				$track[] = $row;
			}
		}
		if ( ! $del && ! $undel && ! $track ) {
			return null;
		}
		return ZDH_DB::record_progress( $id, $del, $undel, $track );
	}

	public static function finish_delivery( WP_REST_Request $req ) {
		$id = (int) $req['id'];
		$d  = ZDH_DB::get_drop( $id );
		if ( ! $d ) {
			return new WP_Error( 'not_found', 'Cluster not found.', array( 'status' => 404 ) );
		}
		$qty = $req->has_param( 'quantity' ) && $req->get_param( 'quantity' ) !== '' && is_numeric( $req->get_param( 'quantity' ) ) ? (int) $req->get_param( 'quantity' ) : null;
		$by  = $req->has_param( 'delivered_by' ) ? sanitize_text_field( (string) $req->get_param( 'delivered_by' ) ) : null;
		$nt  = $req->has_param( 'notes' ) ? sanitize_textarea_field( (string) $req->get_param( 'notes' ) ) : null;
		$walk = (int) $req->get_param( 'walk' );
		$fin  = self::clean_datetime( $req->get_param( 'finished_at' ) ); // the phone's own clock (a queued finish can land hours later)
		if ( 'done' === $d['status'] && $walk <= 0 ) {
			// a wrap-up sent twice (the first answer never reached the phone, or it was queued while offline): already done — say so
			$d['cluster_done'] = true;
			return rest_ensure_response( $d );
		}
		// since 1.8 the finish carries every tick and the unsent track, so nothing depends on the earlier syncs having got through
		self::apply_progress( $req, $id );
		if ( $walk > 0 ) {
			// one walk of several: close it; the cluster only completes when the last walk is done
			$all_done = ZDH_DB::finish_walk( $id, $walk, ! empty( $req->get_param( 'mark_remaining' ) ) );
			if ( $by !== null && $by !== '' ) { ZDH_DB::update_drop_fields( $id, array( 'delivered_by' => self::deliverer_name( $by, $req->get_param( 'delivered_by_uid' ) ), 'delivered_by_uid' => self::deliverer_uid( $by, $req->get_param( 'delivered_by_uid' ) ) ) ); }
			if ( ! $all_done ) {
				$d = ZDH_DB::get_drop( $id );
				$d['cluster_done'] = false;
				return rest_ensure_response( $d );
			}
		}
		ZDH_DB::finish_delivery( $id, $walk > 0 ? false : ! empty( $req->get_param( 'mark_remaining' ) ), $qty, $by, $nt, $fin ); // per-walk marking already happened above
		if ( $by !== null && $by !== '' ) { ZDH_DB::update_drop_fields( $id, array( 'delivered_by' => self::deliverer_name( $by, $req->get_param( 'delivered_by_uid' ) ), 'delivered_by_uid' => self::deliverer_uid( $by, $req->get_param( 'delivered_by_uid' ) ) ) ); }
		$d = ZDH_DB::get_drop( $id );
		ZDH_DB::set_enrichment( $id, ZDH_Enrich::build( $d ) );
		$d = ZDH_DB::get_drop( $id );
		$d['cluster_done'] = true;
		return rest_ensure_response( $d );
	}

	/** Contextual editing: replace the houses of a planned cluster. */
	public static function replace_houses( WP_REST_Request $req ) {
		$id = (int) $req['id'];
		$d  = ZDH_DB::get_drop( $id );
		if ( ! $d ) {
			return new WP_Error( 'not_found', 'Cluster not found.', array( 'status' => 404 ) );
		}
		if ( 'done' === $d['status'] ) {
			return new WP_Error( 'done', 'Completed drops are a record — plan a new cluster instead.', array( 'status' => 400 ) );
		}
		$addresses = self::clean_addresses( $req->get_param( 'addresses' ) );
		$streets   = self::clean_streets( $req->get_param( 'streets' ) );
		if ( ! $addresses ) {
			return new WP_Error( 'empty', 'Select at least one house.', array( 'status' => 400 ) );
		}
		$summary = substr( sanitize_text_field( (string) $req->get_param( 'streets_summary' ) ), 0, 255 );
		ZDH_DB::replace_houses( $id, $addresses, $streets, self::clean_geometry( $req->get_param( 'geometry' ) ), $summary, self::clean_walks( $req->get_param( 'walks' ) ) );
		return rest_ensure_response( ZDH_DB::get_drop( $id ) );
	}

	/** Split (or re-split) an existing planned cluster into walks: the walk list plus a walk/order per house. */
	public static function set_walks( WP_REST_Request $req ) {
		$id = (int) $req['id'];
		$d  = ZDH_DB::get_drop( $id );
		if ( ! $d ) {
			return new WP_Error( 'not_found', 'Cluster not found.', array( 'status' => 404 ) );
		}
		if ( 'done' === $d['status'] ) {
			return new WP_Error( 'done', 'This cluster is already completed.', array( 'status' => 400 ) );
		}
		$walks  = self::clean_walks( $req->get_param( 'walks' ) );
		$assign = array();
		foreach ( (array) $req->get_param( 'assign' ) as $aid => $wo ) {
			if ( is_array( $wo ) && count( $wo ) >= 2 ) { $assign[ (int) $aid ] = array( (int) $wo[0], (int) $wo[1] ); }
		}
		if ( ! $walks || ! $assign ) {
			return new WP_Error( 'empty', 'Nothing to split.', array( 'status' => 400 ) );
		}
		ZDH_DB::set_walks( $id, $walks, $assign );
		return rest_ensure_response( ZDH_DB::get_drop( $id ) );
	}

	public static function mark_done( WP_REST_Request $req ) {
		$id = (int) $req['id'];
		$d  = ZDH_DB::get_drop( $id );
		if ( ! $d ) {
			return new WP_Error( 'not_found', 'Drop not found.', array( 'status' => 404 ) );
		}
		$date = (string) $req->get_param( 'drop_date' );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			$date = current_time( 'Y-m-d' );
		}
		$time = $req->has_param( 'drop_time' ) && preg_match( '/^\d{2}:\d{2}$/', (string) $req->get_param( 'drop_time' ) ) ? (string) $req->get_param( 'drop_time' ) : null;
		$qty  = $req->has_param( 'quantity' ) && is_numeric( $req->get_param( 'quantity' ) ) ? (int) $req->get_param( 'quantity' ) : null;
		$by   = $req->has_param( 'delivered_by' ) ? sanitize_text_field( (string) $req->get_param( 'delivered_by' ) ) : null;
		ZDH_DB::mark_done( $id, $date, $time, $qty, $by );
		ZDH_DB::finish_delivery( $id, true, $qty, $by, null ); // every house on the route counts as delivered
		if ( $by !== null && $by !== '' ) { ZDH_DB::update_drop_fields( $id, array( 'delivered_by' => self::deliverer_name( $by, $req->get_param( 'delivered_by_uid' ) ), 'delivered_by_uid' => self::deliverer_uid( $by, $req->get_param( 'delivered_by_uid' ) ) ) ); }
		$d = ZDH_DB::get_drop( $id );
		ZDH_DB::set_enrichment( $id, ZDH_Enrich::build( $d ) );
		return rest_ensure_response( ZDH_DB::get_drop( $id ) );
	}

	/* --------------------------------------------------------------- drops */

	private static function clean_addresses( $list ) {
		$out       = array();
		$def_state = zdh_area_defaults()['state'];
		foreach ( array_slice( (array) $list, 0, 6000 ) as $a ) {
			if ( ! is_array( $a ) ) {
				continue;
			}
			$number = isset( $a['number'] ) ? sanitize_text_field( (string) $a['number'] ) : '';
			$street = isset( $a['street'] ) ? sanitize_text_field( (string) $a['street'] ) : '';
			if ( $number === '' || $street === '' ) {
				continue;
			}
			$unit  = isset( $a['unit'] ) ? sanitize_text_field( (string) $a['unit'] ) : '';
			$city  = isset( $a['city'] ) ? sanitize_text_field( (string) $a['city'] ) : '';
			$state = isset( $a['state'] ) ? strtoupper( substr( sanitize_text_field( (string) $a['state'] ), 0, 10 ) ) : $def_state;
			$zip   = isset( $a['zip'] ) ? preg_replace( '/[^0-9\-]/', '', (string) $a['zip'] ) : '';
			$full  = trim( $number . ' ' . $street . ( $unit !== '' ? ' #' . $unit : '' ) . ', ' . $city . ', ' . $state . ' ' . $zip, ', ' );
			$out[] = array(
				'number'     => $number,
				'unit'       => $unit,
				'street'     => $street,
				'city'       => $city,
				'state'      => $state ?: $def_state,
				'zip'        => $zip,
				'full'       => $full,
				'lat'        => isset( $a['lat'] ) && is_numeric( $a['lat'] ) ? round( (float) $a['lat'], 7 ) : null,
				'lng'        => isset( $a['lng'] ) && is_numeric( $a['lng'] ) ? round( (float) $a['lng'], 7 ) : null,
				'source'     => in_array( $a['source'] ?? '', array( 'region', 'osm', 'manual' ), true ) ? $a['source'] : 'manual',
				'confidence' => in_array( $a['confidence'] ?? '', array( 'verified', 'estimated', 'manual' ), true ) ? $a['confidence'] : 'verified',
				'seg'        => isset( $a['seg'] ) && is_numeric( $a['seg'] ) ? (int) $a['seg'] : null,
				'apn'        => isset( $a['apn'] ) ? substr( preg_replace( '/[^0-9A-Za-z\-]/', '', (string) $a['apn'] ), 0, 20 ) : '',
				'walk'       => isset( $a['walk'] ) && is_numeric( $a['walk'] ) ? max( 1, min( 250, (int) $a['walk'] ) ) : 1,
				'walk_order' => isset( $a['walk_order'] ) && is_numeric( $a['walk_order'] ) ? max( 0, (int) $a['walk_order'] ) : 0,
			);
		}
		return $out;
	}

	/** The walk list a cluster was split into: {size, style, per_hour, list: [{n, count, est_min, streets, status, started_at?, finished_at?}]} or null. */
	private static function clean_walks( $w ) {
		if ( ! is_array( $w ) || empty( $w['list'] ) || ! is_array( $w['list'] ) ) {
			return null;
		}
		$list = array();
		foreach ( array_slice( $w['list'], 0, 250 ) as $i => $x ) {
			if ( ! is_array( $x ) ) { continue; }
			$row = array(
				'n'       => isset( $x['n'] ) ? max( 1, (int) $x['n'] ) : $i + 1,
				'count'   => isset( $x['count'] ) ? max( 0, (int) $x['count'] ) : 0,
				'est_min' => isset( $x['est_min'] ) ? max( 0, (int) $x['est_min'] ) : 0,
				'streets' => isset( $x['streets'] ) ? substr( sanitize_text_field( (string) $x['streets'] ), 0, 200 ) : '',
				'status'  => isset( $x['status'] ) && in_array( $x['status'], array( 'planned', 'in_progress', 'done' ), true ) ? $x['status'] : 'planned',
			);
			foreach ( array( 'started_at', 'finished_at' ) as $k ) { $v = self::clean_datetime( $x[ $k ] ?? '' ); if ( $v ) { $row[ $k ] = $v; } }
			if ( isset( $x['delivered'] ) && is_numeric( $x['delivered'] ) ) { $row['delivered'] = (int) $x['delivered']; }
			$list[] = $row;
		}
		if ( ! $list ) { return null; }
		return array(
			'size'     => isset( $w['size'] ) ? max( 0, (int) $w['size'] ) : 0,
			'style'    => isset( $w['style'] ) && 'street' === $w['style'] ? 'street' : 'loop',
			'per_hour' => isset( $w['per_hour'] ) ? max( 0, (int) $w['per_hour'] ) : 0,
			'list'     => $list,
		);
	}

	private static function clean_streets( $list ) {
		$out = array();
		foreach ( array_slice( (array) $list, 0, 50 ) as $s ) {
			if ( ! is_array( $s ) || empty( $s['street'] ) ) {
				continue;
			}
			$out[] = array(
				'street'   => sanitize_text_field( (string) $s['street'] ),
				'city'     => isset( $s['city'] ) ? sanitize_text_field( (string) $s['city'] ) : '',
				'zip'      => isset( $s['zip'] ) ? preg_replace( '/[^0-9]/', '', (string) $s['zip'] ) : '',
				'coverage' => ( isset( $s['coverage'] ) && 'partial' === $s['coverage'] ) ? 'partial' : 'full',
				'low'      => isset( $s['low'] ) && is_numeric( $s['low'] ) ? (int) $s['low'] : null,
				'high'     => isset( $s['high'] ) && is_numeric( $s['high'] ) ? (int) $s['high'] : null,
				'count'    => isset( $s['count'] ) ? (int) $s['count'] : 0,
			);
		}
		return $out;
	}

	/** Keep only the geometry we draw later: segment paths + the optional hand-drawn region. */
	private static function clean_geometry( $g ) {
		$g   = is_array( $g ) ? $g : array();
		$out = array( 'segments' => array(), 'region' => null );
		foreach ( array_slice( (array) ( $g['segments'] ?? array() ), 0, 300 ) as $seg ) {
			if ( ! is_array( $seg ) || empty( $seg['path'] ) || ! is_array( $seg['path'] ) ) {
				continue;
			}
			$path = array();
			foreach ( array_slice( $seg['path'], 0, 600 ) as $pt ) {
				if ( is_array( $pt ) && count( $pt ) >= 2 && is_numeric( $pt[0] ) && is_numeric( $pt[1] ) ) {
					$path[] = array( round( (float) $pt[0], 6 ), round( (float) $pt[1], 6 ) );
				}
			}
			if ( count( $path ) >= 2 ) {
				$out['segments'][] = array(
					'id'   => isset( $seg['id'] ) ? (int) $seg['id'] : 0,
					'name' => isset( $seg['name'] ) ? sanitize_text_field( (string) $seg['name'] ) : '',
					'path' => $path,
				);
			}
		}
		$out['strokes'] = array();
		foreach ( array_slice( (array) ( $g['strokes'] ?? array() ), 0, 20 ) as $st ) {
			if ( ! is_array( $st ) || empty( $st['points'] ) || ! is_array( $st['points'] ) ) {
				continue;
			}
			$pts = array();
			foreach ( array_slice( $st['points'], 0, 1500 ) as $pt ) {
				if ( is_array( $pt ) && count( $pt ) >= 2 && is_numeric( $pt[0] ) && is_numeric( $pt[1] ) ) {
					$pts[] = array( round( (float) $pt[0], 6 ), round( (float) $pt[1], 6 ) );
				}
			}
			if ( count( $pts ) >= 2 ) {
				$out['strokes'][] = array( 'type' => ( isset( $st['type'] ) && 'loop' === $st['type'] ) ? 'loop' : 'path', 'points' => $pts );
			}
		}
		if ( ! empty( $g['overlay'] ) && is_array( $g['overlay'] ) && isset( $g['overlay']['bounds'] ) ) {
			$b = $g['overlay']['bounds'];
			if ( is_array( $b ) && count( $b ) === 2 ) {
				$out['overlay'] = array( 'attachment_id' => (int) ( $g['overlay']['attachment_id'] ?? 0 ), 'bounds' => array( array( (float) $b[0][0], (float) $b[0][1] ), array( (float) $b[1][0], (float) $b[1][1] ) ) );
			}
		}
		if ( ! empty( $g['region'] ) && is_array( $g['region'] ) ) {
			$poly = array();
			foreach ( array_slice( $g['region'], 0, 200 ) as $pt ) {
				if ( is_array( $pt ) && count( $pt ) >= 2 && is_numeric( $pt[0] ) && is_numeric( $pt[1] ) ) {
					$poly[] = array( round( (float) $pt[0], 6 ), round( (float) $pt[1], 6 ) );
				}
			}
			if ( count( $poly ) >= 3 ) {
				$out['region'] = $poly;
			}
		}
		return $out;
	}

	public static function save_drop( WP_REST_Request $req ) {
		$d = (array) $req->get_param( 'drop' );
		$addresses = self::clean_addresses( $req->get_param( 'addresses' ) );
		$streets   = self::clean_streets( $req->get_param( 'streets' ) );
		// a Quick Start walk is saved to the server the moment it starts (status in_progress, no houses yet) and
		// its track is appended as it goes, so a phone that loses the walk can still have it counted from any device
		$live = isset( $d['method'], $d['status'] ) && 'quick' === $d['method'] && 'in_progress' === $d['status'];
		// a finished Quick Start walk with a trace but no county houses on its streets is saved too (the walk is the record;
		// houses can be added later) -- the phone asks the walker first
		$track_in  = $req->get_param( 'track' );
		$walk_only = isset( $d['method'] ) && 'quick' === $d['method'] && is_array( $track_in ) && count( $track_in ) > 1;
		if ( ! $addresses && ! $streets && ! $live && ! $walk_only ) {
			return new WP_Error( 'empty', 'Nothing to save — select at least one address or street.', array( 'status' => 400 ) );
		}
		// one Quick Start walk is one record. A walk counted on the site (via "Count the houses") could be saved again
		// later from the walker's phone, which still had the walk, and every hanger would count twice. A walk is
		// identified by its start time to the second; if it is already saved, nothing new is written and the phone is
		// told so.
		if ( isset( $d['method'] ) && 'quick' === $d['method'] ) {
			$st = self::clean_datetime( $d['started_at'] ?? '' );
			if ( $st ) {
				$dup = ZDH_DB::done_quick_started( $st );
				if ( $dup ) {
					$lid = (int) $req->get_param( 'live_id' );
					if ( $lid && $lid !== $dup ) { self::discard_live_quick( $lid ); }
					foreach ( ZDH_DB::live_quick_ids_started( $st ) as $lq ) { self::discard_live_quick( $lq ); }
					return rest_ensure_response( array( 'id' => $dup, 'duplicate' => true, 'drop' => ZDH_DB::get_drop( $dup ) ) );
				}
			}
		}
		$date = isset( $d['drop_date'] ) ? sanitize_text_field( $d['drop_date'] ) : '';
		if ( $date && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			$date = '';
		}
		$time = isset( $d['drop_time'] ) ? sanitize_text_field( $d['drop_time'] ) : '';
		if ( $time && ! preg_match( '/^\d{2}:\d{2}$/', $time ) ) {
			$time = '';
		}
		$att_ids = array();
		foreach ( (array) ( $d['attachment_ids'] ?? array() ) as $aid ) {
			$aid = (int) $aid;
			if ( $aid && 'attachment' === get_post_type( $aid ) && get_post_meta( $aid, '_zdh', true ) ) {
				$att_ids[] = $aid;
			}
		}
		$color = isset( $d['marker_color'] ) && preg_match( '/^#[0-9a-fA-F]{6}$/', $d['marker_color'] ) ? $d['marker_color'] : '#e74c3c';
		$s     = zdh_get_settings();
		$area  = zdh_area_defaults();
		$status = ( isset( $d['status'] ) && 'planned' === $d['status'] ) ? 'planned' : ( $live ? 'in_progress' : 'done' );
		$side   = isset( $d['hanger_side'] ) ? substr( sanitize_text_field( (string) $d['hanger_side'] ), 0, 10 ) : '';
		$revisit = isset( $d['revisit_days'] ) && is_numeric( $d['revisit_days'] ) ? max( 0, (int) $d['revisit_days'] ) : (int) $s['revisit_days'];
		// 'paper' = an old route read off a photo or scan of its printed sheet
		$method = isset( $d['method'] ) && in_array( $d['method'], array( 'screenshot', 'map', 'import', 'quick', 'paper' ), true ) ? $d['method'] : 'screenshot';
		$name   = isset( $d['name'] ) ? substr( sanitize_text_field( (string) $d['name'] ), 0, 150 ) : '';

		// centre: mean of the saved addresses, else the value the client computed
		$lat = null; $lng = null; $n = 0;
		foreach ( $addresses as $a ) {
			if ( $a['lat'] !== null && $a['lng'] !== null ) { $lat += $a['lat']; $lng += $a['lng']; $n++; }
		}
		if ( $n ) {
			$lat = round( $lat / $n, 7 ); $lng = round( $lng / $n, 7 );
		} elseif ( isset( $d['lat'], $d['lng'] ) && is_numeric( $d['lat'] ) && is_numeric( $d['lng'] ) ) {
			$lat = round( (float) $d['lat'], 7 ); $lng = round( (float) $d['lng'], 7 );
		}

		// most common ZIP / city among the addresses beats whatever was typed
		$zip  = isset( $d['zip'] ) ? preg_replace( '/[^0-9]/', '', (string) $d['zip'] ) : '';
		$city = isset( $d['city'] ) ? sanitize_text_field( $d['city'] ) : '';
		$zc = array(); $cc = array();
		foreach ( $addresses as $a ) {
			if ( $a['zip'] ) { $zc[ $a['zip'] ] = ( $zc[ $a['zip'] ] ?? 0 ) + 1; }
			if ( $a['city'] ) { $cc[ $a['city'] ] = ( $cc[ $a['city'] ] ?? 0 ) + 1; }
		}
		if ( $zc ) { arsort( $zc ); $zip = (string) array_key_first( $zc ); }
		if ( $cc ) { arsort( $cc ); $city = (string) array_key_first( $cc ); }

		$data = array(
			'drop_date'       => $date,
			'drop_time'       => $time,
			'delivered_by'    => self::deliverer_name( $d['delivered_by'] ?? '', $d['delivered_by_uid'] ?? 0 ),
			'delivered_by_uid' => self::deliverer_uid( $d['delivered_by'] ?? '', $d['delivered_by_uid'] ?? 0 ),
			'hanger_type'     => isset( $d['hanger_type'] ) ? sanitize_text_field( $d['hanger_type'] ) : '',
			'service'         => isset( $d['service'] ) && $d['service'] !== '' ? sanitize_text_field( $d['service'] ) : $s['service'],
			'quantity'        => isset( $d['quantity'] ) && is_numeric( $d['quantity'] ) ? (int) $d['quantity'] : null,
			'city'            => $city ?: $area['city'],
			'state'           => isset( $d['state'] ) && $d['state'] ? strtoupper( substr( sanitize_text_field( $d['state'] ), 0, 10 ) ) : $area['state'],
			'zip'             => $zip,
			'county'          => isset( $d['county'] ) && $d['county'] ? sanitize_text_field( $d['county'] ) : $area['county'],
			'streets_summary' => isset( $d['streets_summary'] ) ? substr( sanitize_text_field( $d['streets_summary'] ), 0, 255 ) : '',
			'description'     => isset( $d['description'] ) ? sanitize_textarea_field( $d['description'] ) : '',
			'notes'           => isset( $d['notes'] ) ? sanitize_textarea_field( $d['notes'] ) : '',
			'lat'             => $lat,
			'lng'             => $lng,
			'marker_color'    => $color,
			'attachment_ids'  => $att_ids,
			'geometry'        => self::clean_geometry( $req->get_param( 'geometry' ) ),
			'analysis'        => self::clean_analysis( $req->get_param( 'analysis' ) ),
			'status'          => $status,
			'hanger_side'     => $side,
			'revisit_days'    => $revisit ?: null,
			'method'          => $method,
			'name'            => $name,
			'started_at'      => self::clean_datetime( $d['started_at'] ?? '' ),
			'finished_at'     => self::clean_datetime( $d['finished_at'] ?? '' ),
			'track'           => self::clean_track( $req->get_param( 'track' ) ),
			'walks'           => 'planned' === $status ? self::clean_walks( $req->get_param( 'walks' ) ) : null,
		);
		$id = ZDH_DB::insert_drop( $data, $addresses, $streets );
		if ( ! $id ) {
			return new WP_Error( 'db', 'Could not save the drop.', array( 'status' => 500 ) );
		}
		// the finished quick walk replaces its live record
		$live_id = (int) $req->get_param( 'live_id' );
		if ( $live_id && $live_id !== $id ) { self::discard_live_quick( $live_id ); }
		// … and so does a live record the phone never learned the id of (it was still being created when the walk
		// was saved — no signal until the end of the route): same walk = same start time to the second.
		if ( 'quick' === $method && 'done' === $status && ! empty( $data['started_at'] ) ) {
			foreach ( ZDH_DB::live_quick_ids_started( $data['started_at'] ) as $lid ) {
				if ( $lid !== $id && $lid !== $live_id ) { self::discard_live_quick( $lid ); }
			}
		}
		return rest_ensure_response( array( 'id' => $id, 'drop' => ZDH_DB::get_drop( $id ) ) );
	}

	/** Delete a live (in-progress, house-less) Quick Start record — nothing else. */
	private static function discard_live_quick( $id ) {
		$d = ZDH_DB::get_drop( (int) $id );
		if ( $d && 'quick' === $d['method'] && 'in_progress' === $d['status'] && empty( $d['addresses'] ) ) {
			ZDH_DB::delete_drop( (int) $id );
			return true;
		}
		return false;
	}
	public static function discard_quick( WP_REST_Request $req ) {
		$id = (int) $req['id'];
		$d  = ZDH_DB::get_drop( $id );
		if ( ! $d ) {
			return rest_ensure_response( array( 'ok' => true, 'gone' => true ) ); // already gone: fine
		}
		if ( ! self::discard_live_quick( $id ) ) {
			return new WP_Error( 'not_live', 'Only an unfinished Quick Start walk can be discarded this way.', array( 'status' => 400 ) );
		}
		return rest_ensure_response( array( 'ok' => true ) );
	}

	/** 'YYYY-MM-DD HH:MM:SS' (site time) or null. */
	private static function clean_datetime( $v ) {
		$v = sanitize_text_field( (string) $v );
		return preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(:\d{2})?$/', $v ) ? ( strlen( $v ) === 16 ? $v . ':00' : $v ) : null;
	}
	/** GPS track points [lat, lng, ms, accuracy, mode] — mode 'd' marks driving. */
	public static function clean_track( $track ) {
		$out = array();
		foreach ( array_slice( (array) $track, 0, 6000 ) as $pt ) {
			if ( is_array( $pt ) && count( $pt ) >= 3 && is_numeric( $pt[0] ) && is_numeric( $pt[1] ) ) {
				$row = array( round( (float) $pt[0], 6 ), round( (float) $pt[1], 6 ), (int) $pt[2], isset( $pt[3] ) && is_numeric( $pt[3] ) ? (int) $pt[3] : null );
				if ( isset( $pt[4] ) && 'd' === $pt[4] ) { $row[] = 'd'; }
				$out[] = $row;
			}
		}
		return $out;
	}

	/** The model's JSON is stored for reference; strip it down to plain scalars/arrays. */
	private static function clean_analysis( $a ) {
		if ( ! is_array( $a ) ) {
			return null;
		}
		if ( strlen( wp_json_encode( $a ) ) > 60000 ) {
			return array( 'truncated' => true );
		}
		return self::deep_sanitize( $a, 0 );
	}

	private static function deep_sanitize( $v, $depth ) {
		if ( $depth > 6 ) {
			return null;
		}
		if ( is_array( $v ) ) {
			$out = array();
			foreach ( array_slice( $v, 0, 200, true ) as $k => $item ) {
				$out[ is_int( $k ) ? $k : sanitize_key( $k ) ] = self::deep_sanitize( $item, $depth + 1 );
			}
			return $out;
		}
		if ( is_string( $v ) ) {
			return sanitize_textarea_field( $v );
		}
		if ( is_int( $v ) || is_float( $v ) || is_bool( $v ) || is_null( $v ) ) {
			return $v;
		}
		return null;
	}

	/* ------------------------------------------------------------- people */

	/**
	 * Who delivered: a person on this site (their user id, shown as their badge) or, for imported rows and a name typed
	 * by hand, text. A person may only name someone who holds the app; otherwise it is themself.
	 */
	public static function deliverer_uid( $name, $uid ) {
		$uid = (int) $uid;
		if ( $uid > 0 && get_userdata( $uid ) && zdh_user_can_use( $uid ) ) {
			return $uid;
		}
		$name = trim( (string) $name );
		if ( '' === $name ) {
			return (int) get_current_user_id();
		}
		// a typed name that is exactly one holder's display name is that person (the picker offers those names)
		$hit = 0;
		foreach ( zdh_people() as $p ) {
			if ( 0 === strcasecmp( $p['name'], $name ) ) {
				if ( $hit ) {
					return 0; // two people with one name: keep the text only
				}
				$hit = (int) $p['id'];
			}
		}
		return $hit;
	}
	public static function deliverer_name( $name, $uid ) {
		$uid  = self::deliverer_uid( $name, $uid );
		$name = sanitize_text_field( (string) $name );
		if ( $uid > 0 ) {
			$u = get_userdata( $uid );
			return $u ? substr( (string) $u->display_name, 0, 100 ) : $name;
		}
		return substr( $name, 0, 100 );
	}
	/** The people who hold the app: id, initials and display name (for the "who delivered" choice). Never e-mails. */
	/** "No-Solicit House" -- where the walker stands becomes the house that is never delivered. */
	public static function nosolicit( WP_REST_Request $req ) {
		$r = ZDH_NoSolicit::add( array(
			'lat'        => $req->get_param( 'lat' ),
			'lng'        => $req->get_param( 'lng' ),
			'acc'        => $req->get_param( 'acc' ),
			'drop_id'    => (int) $req->get_param( 'drop_id' ),
			'address_id' => (int) $req->get_param( 'address_id' ),
			'uid'        => get_current_user_id(),
		) );
		if ( is_wp_error( $r ) ) { return $r; }
		return rest_ensure_response( $r );
	}

	public static function people() {
		return rest_ensure_response( array( 'people' => zdh_people() ) );
	}

	/* ------------------------------------------------------------- import */

	/** The old plugin's JSON backup (Export -> Full backup in Door Hanger Tracker 1.x), or the old map page, into these tables. Admin tier. */
	public static function import_backup( WP_REST_Request $req ) {
		@set_time_limit( 300 );
		$files = $req->get_file_params();
		$json  = null;
		if ( ! empty( $files['file']['tmp_name'] ) && is_uploaded_file( $files['file']['tmp_name'] ) ) {
			$json = file_get_contents( $files['file']['tmp_name'] );
		} else {
			$json = (string) $req->get_param( 'json' );
		}
		if ( '' === trim( (string) $json ) ) {
			return new WP_Error( 'no_file', __( 'Choose the backup file (a .json from Export -> Full backup).', 'zorderz' ), array( 'status' => 400 ) );
		}
		$r = ZDH_Import::from_json( $json, ! empty( $req->get_param( 'replace' ) ) );
		if ( is_wp_error( $r ) ) {
			$r->add_data( array( 'status' => 400 ) );
			return $r;
		}
		return rest_ensure_response( $r );
	}

	public static function list_drops() {
		return rest_ensure_response( array( 'drops' => ZDH_DB::list_drops(), 'stats' => ZDH_DB::stats() ) );
	}

	public static function get_drop( WP_REST_Request $req ) {
		$d = ZDH_DB::get_drop( (int) $req['id'] );
		if ( ! $d ) {
			return new WP_Error( 'not_found', 'Drop not found.', array( 'status' => 404 ) );
		}
		return rest_ensure_response( $d );
	}

	public static function update_drop( WP_REST_Request $req ) {
		$id = (int) $req['id'];
		if ( ! ZDH_DB::get_drop( $id ) ) {
			return new WP_Error( 'not_found', 'Drop not found.', array( 'status' => 404 ) );
		}
		$fields = array();
		foreach ( array( 'responses', 'converted', 'quantity' ) as $k ) {
			if ( $req->has_param( $k ) ) {
				$v = $req->get_param( $k );
				$fields[ $k ] = ( $v === '' || $v === null ) ? null : (int) $v;
			}
		}
		foreach ( array( 'hanger_type', 'drop_time', 'hanger_side', 'name' ) as $k ) {
			if ( $req->has_param( $k ) ) {
				$fields[ $k ] = sanitize_text_field( (string) $req->get_param( $k ) );
			}
		}
		if ( ( $req->has_param( 'delivered_by' ) && '' !== trim( (string) $req->get_param( 'delivered_by' ) ) ) || (int) $req->get_param( 'delivered_by_uid' ) > 0 ) {
			// an empty name on an edit leaves the deliverer as it is (an imported record is never re-stamped with the editor)
			$fields['delivered_by']     = self::deliverer_name( (string) $req->get_param( 'delivered_by' ), (int) $req->get_param( 'delivered_by_uid' ) );
			$fields['delivered_by_uid'] = self::deliverer_uid( (string) $req->get_param( 'delivered_by' ), (int) $req->get_param( 'delivered_by_uid' ) );
		}
		if ( $req->has_param( 'revisit_days' ) ) {
			$v = $req->get_param( 'revisit_days' );
			$fields['revisit_days'] = ( $v === '' || $v === null ) ? null : max( 0, (int) $v );
		}
		if ( $req->has_param( 'drop_date' ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $req->get_param( 'drop_date' ) ) ) {
			$fields['drop_date'] = (string) $req->get_param( 'drop_date' );
		}
		// the walk's own times can be corrected (a start inherited from a stale session, a finish typed from memory)
		foreach ( array( 'started_at', 'finished_at' ) as $k ) {
			if ( $req->has_param( $k ) ) {
				$v = self::clean_datetime( $req->get_param( $k ) );
				if ( $v ) { $fields[ $k ] = $v; }
			}
		}
		if ( $req->has_param( 'notes' ) ) {
			$fields['notes'] = sanitize_textarea_field( (string) $req->get_param( 'notes' ) );
		}
		ZDH_DB::update_drop_fields( $id, $fields );
		return rest_ensure_response( ZDH_DB::get_drop( $id ) );
	}

	public static function delete_drop( WP_REST_Request $req ) {
		$id = (int) $req['id'];
		if ( ! ZDH_DB::get_drop( $id ) ) {
			return new WP_Error( 'not_found', 'Drop not found.', array( 'status' => 404 ) );
		}
		ZDH_DB::delete_drop( $id );
		return rest_ensure_response( array( 'deleted' => $id ) );
	}

	public static function enrich_drop( WP_REST_Request $req ) {
		@set_time_limit( 120 );
		$id = (int) $req['id'];
		$d  = ZDH_DB::get_drop( $id );
		if ( ! $d ) {
			return new WP_Error( 'not_found', 'Drop not found.', array( 'status' => 404 ) );
		}
		$e = ZDH_Enrich::build( $d );
		ZDH_DB::set_enrichment( $id, $e );
		return rest_ensure_response( array( 'id' => $id, 'enrichment' => $e ) );
	}

	/* -------------------------------------------------------------- lookup */

	public static function lookup( WP_REST_Request $req ) {
		$lines = array();
		if ( $req->get_param( 'lines' ) ) {
			$lines = (array) $req->get_param( 'lines' );
		} elseif ( $req->get_param( 'q' ) ) {
			$lines = array( $req->get_param( 'q' ) );
		}
		$results = array();
		$near    = count( $lines ) === 1; // one address typed in: also say how close a route came (one map call)
		foreach ( array_slice( $lines, 0, 200 ) as $line ) {
			$line = sanitize_text_field( (string) $line );
			if ( trim( $line ) === '' ) {
				continue;
			}
			$results[] = ZDH_DB::lookup( $line, $near );
		}
		return rest_ensure_response( array( 'results' => $results ) );
	}

	public static function stats() {
		return rest_ensure_response( ZDH_DB::stats() );
	}

	/* -------------------------------------------------------------- export */

	public static function export( WP_REST_Request $req ) {
		$type = $req->get_param( 'type' );
		$stamp = current_time( 'Y-m-d' );
		if ( 'ics' === $type ) {
			$d = ZDH_DB::get_drop( (int) $req->get_param( 'drop' ) );
			if ( ! $d ) {
				return new WP_Error( 'not_found', 'Drop not found.', array( 'status' => 404 ) );
			}
			nocache_headers();
			header( 'Content-Type: text/calendar; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename="revisit-drop-' . (int) $d['id'] . '.ics"' );
			echo ZDH_DB::ics_for_drop( $d ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a text/calendar attachment, not HTML; ics_for_drop() escapes every text value for the calendar format
			exit;
		}
		if ( 'json' === $type ) {
			if ( ! zdh_user_is_admin( get_current_user_id() ) ) {
				return new WP_Error( 'rest_forbidden', 'The full backup (every walk\'s GPS track) is for an administrator.', array( 'status' => 403 ) );
			}
			$data = ZDH_DB::full_backup();
			nocache_headers();
			header( 'Content-Type: application/json; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename="door-hanger-backup-' . $stamp . '.json"' );
			echo wp_json_encode( $data, JSON_PRETTY_PRINT );
			exit;
		}
		$type = ( 'drops' === $type ) ? 'drops' : 'addresses';
		$rows = ZDH_DB::export_rows( $type );
		if ( class_exists( 'ZDH_Sealed' ) ) { $rows = ZDH_Sealed::deep( $rows ); } // an export never carries a code
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="door-hanger-' . $type . '-' . $stamp . '.csv"' );
		$fh = fopen( 'php://output', 'w' );
		fwrite( $fh, "\xEF\xBB\xBF" ); // UTF-8 BOM so Excel opens it cleanly
		if ( $rows ) {
			fputcsv( $fh, array_keys( $rows[0] ), ',', '"', '\\' );
			foreach ( $rows as $r ) {
				fputcsv( $fh, array_map( array( 'ZDH_DB', 'csv_safe' ), $r ), ',', '"', '\\' );
			}
		} else {
			fputcsv( $fh, array( 'no data yet' ), ',', '"', '\\' );
		}
		fclose( $fh );
		exit;
	}

	/* --------------------------------------------------------- diagnostics */

	/* ---- phone diagnostics reports (kept in an option, newest first, at most 12) ---- */
	const DIAG_OPTION = 'zdh_diag_reports';
	public static function diag_report_save( WP_REST_Request $req ) {
		$text = (string) $req->get_param( 'text' );
		if ( '' === trim( $text ) ) {
			return new WP_Error( 'empty', 'Nothing to send.', array( 'status' => 400 ) );
		}
		if ( strlen( $text ) > 120000 ) { $text = substr( $text, 0, 120000 ) . "\n… (cut)"; }
		$text  = wp_strip_all_tags( $text );
		$label = sanitize_text_field( (string) $req->get_param( 'label' ) );
		$user  = is_user_logged_in() ? wp_get_current_user()->display_name : 'team';
		$list  = get_option( self::DIAG_OPTION, array() );
		if ( ! is_array( $list ) ) { $list = array(); }
		array_unshift( $list, array( 'at' => current_time( 'mysql' ), 'by' => $user, 'label' => trim( $label ), 'ua' => substr( isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '', 0, 200 ), 'text' => $text ) );
		$list = array_slice( $list, 0, 12 );
		update_option( self::DIAG_OPTION, $list, false );
		return rest_ensure_response( array( 'ok' => true, 'kept' => count( $list ) ) );
	}
	public static function diag_report_list() {
		$list = get_option( self::DIAG_OPTION, array() );
		return rest_ensure_response( array( 'reports' => is_array( $list ) ? $list : array() ) );
	}
	public static function diag_report_clear() {
		delete_option( self::DIAG_OPTION );
		return rest_ensure_response( array( 'ok' => true ) );
	}

	/* ------------------------------------------------- the CRM (read-only) */

	/** Which of the chosen salesperson's CRM customers got a door hanger in the last N days. Read-only. */
	public static function nutshell_crosscheck( WP_REST_Request $req ) {
		// An administrator may ask for someone else's CRM user ("12-users"); everyone else gets their own.
		$for = (string) $req->get_param( 'for' );
		if ( '' !== $for ) {
			if ( ! zdh_user_is_admin( get_current_user_id() ) ) {
				return new WP_Error( 'rest_forbidden', 'Only an administrator can cross-check for someone else.', array( 'status' => 403 ) );
			}
			ZDH_Nutshell::set_owner_for( $for );
		}
		if ( ! ZDH_Nutshell::configured() ) {
			return new WP_Error( 'crm_not_configured', ZDH_Nutshell::has_key() ? __( 'No CRM user is matched to you yet. An administrator sets it on your profile, or a site default on Zorderz → Doorhangers.', 'zorderz' ) : __( 'The CRM is not connected yet: the login goes on Zorderz → Settings.', 'zorderz' ), array( 'status' => 409 ) );
		}
		$days = $req->get_param( 'days' );
		$days = null === $days ? 30 : (int) $days;
		$r    = ZDH_Nutshell::crosscheck( $days, ! empty( $req->get_param( 'refresh' ) ) );
		if ( is_wp_error( $r ) ) {
			$r->add_data( array( 'status' => 502 ) );
			return $r;
		}
		return rest_ensure_response( $r );
	}

	public static function nutshell_users( WP_REST_Request $req ) {
		$u = ZDH_Nutshell::users( ! empty( $req->get_param( 'refresh' ) ) );
		if ( is_wp_error( $u ) ) {
			$u->add_data( array( 'status' => 502 ) );
			return $u;
		}
		return rest_ensure_response( array( 'users' => $u, 'cross_check_for' => ZDH_Nutshell::owner() ) );
	}

	public static function nutshell_check() {
		return rest_ensure_response( ZDH_Nutshell::check() );
	}

	public static function diagnostics() {
		@set_time_limit( 120 );
		$out = array();
		$poe = ZDH_LLM::ping();
		$out['ai_reader'] = is_wp_error( $poe ) ? array( 'ok' => false, 'error' => $poe->get_error_message() ) : array( 'ok' => true, 'model' => $poe['model'], 'reply' => trim( (string) $poe['reply'] ) );

		// The probes use the business's own area: its postal code and the point the map opens at. With neither set, a
		// probe that needs one says so instead of asking about a place of the platform's choosing.
		$area   = zdh_area_defaults();
		$zip    = preg_replace( '/[^0-9]/', '', (string) $area['zip'] );
		$center = zdh_map_center();
		$zip_q  = '' !== $zip ? array( 'where' => ZDH_Geo::f( 'addresses', 'zip' ) . " = '" . $zip . "'", 'returnCountOnly' => 'true' ) : null;

		// A local mirror answers the house lists and the walk log's neighborhood, fire and coast figures first.
		$hits0 = ZDH_Local::$hits;
		$mc    = $zip_q ? ZDH_Local::answer( 'addresses', $zip_q ) : null;
		$mp    = $center ? ZDH_Local::place( $center[0], $center[1] ) : null;
		if ( ! has_filter( 'zdh_local_lookup' ) ) {
			$out['local_mirror'] = array( 'ok' => true, 'note' => 'No local mirror is connected; house lists come from the address source.' );
		} elseif ( null === $mc ) {
			$out['local_mirror'] = array( 'ok' => false, 'error' => '' === $zip ? 'Set a postal code on the Business Profile to test the mirror.' : 'The local mirror did not answer; the address source is used meanwhile.' );
		} else {
			$out['local_mirror'] = array( 'ok' => ZDH_Local::$hits > $hits0, 'sample_count' => $mc['count'] ?? null, 'figures_by_zip' => is_array( $mp ) && ! empty( $mp['zip_facts'] ), 'fire_history' => is_array( $mp ) && isset( $mp['fires'] ) );
		}

		if ( ! ZDH_Geo::configured() ) {
			$out['address_source'] = array( 'ok' => ZDH_Geo::osm_on(), 'source' => ZDH_Geo::source_label(), 'note' => ZDH_Geo::osm_on() ? 'No regional address layer is set; the open street map answers.' : 'No address source: set a regional layer or allow the open street map.' );
		} else {
			$sd = $zip_q ? ZDH_Geo::arcgis_query( 'addresses', $zip_q, 15, false ) : ZDH_Geo::arcgis_query( 'addresses', array( 'where' => '1=1', 'returnCountOnly' => 'true' ), 15, false );
			// the one cheap parity check: the mirror's copy and the layer count the same houses in one ZIP
			if ( ! empty( $out['local_mirror']['ok'] ) && ! is_wp_error( $sd ) && isset( $sd['count'], $out['local_mirror']['sample_count'] ) ) {
				$out['local_mirror']['matches_source'] = (int) $sd['count'] === (int) $out['local_mirror']['sample_count'];
			}
			$out['address_points'] = is_wp_error( $sd ) ? array( 'ok' => false, 'error' => $sd->get_error_message() ) : array( 'ok' => true, 'source' => ZDH_Geo::source_label(), 'sample_count' => $sd['count'] ?? null );
			$rd = ZDH_Geo::arcgis_query( 'roads', array( 'where' => '1=1', 'returnCountOnly' => 'true' ), 15 );
			$out['roads'] = is_wp_error( $rd ) ? array( 'ok' => false, 'error' => $rd->get_error_message() ) : array( 'ok' => true, 'segments' => $rd['count'] ?? null );
		}

		if ( $center ) {
			$w = ZDH_Enrich::weather( $center[0], $center[1], gmdate( 'Y-m-d', time() - 10 * 86400 ), '12:00' );
			$out['weather'] = $w ? array( 'ok' => true, 'sample' => $w ) : array( 'ok' => false, 'error' => 'no data returned' );
		} else {
			$out['weather'] = array( 'ok' => false, 'error' => 'Set where the map opens (or a Business Profile address) to test the weather service.' );
		}

		$mine = ZDH_Enrich::census_from_place( $mp );
		if ( $mine ) {
			$out['neighborhood_figures'] = array( 'ok' => true, 'from' => 'local mirror (by ZIP)', 'sample' => $mine );
		} elseif ( ! ZDH_Enrich::census_on() ) {
			$out['neighborhood_figures'] = array( 'ok' => true, 'note' => 'Off (Zorderz → Doorhangers → Neighborhood figures).' );
		} elseif ( ! $center ) {
			$out['neighborhood_figures'] = array( 'ok' => false, 'error' => 'Set where the map opens to test the Census service.' );
		} else {
			$c = ZDH_Enrich::census_from_website( $center[0], $center[1] );
			if ( ! $c ) {
				$out['neighborhood_figures'] = array( 'ok' => false, 'error' => 'The Census geocoder returned no block group (service down or slow, or the point is outside the United States).' );
			} elseif ( empty( $c['acs_year'] ) ) {
				$out['neighborhood_figures'] = array( 'ok' => false, 'geocoder' => 'ok (tract ' . $c['tract'] . ', block group ' . $c['block_group'] . ')', 'error' => $c['acs_note'] ?? 'ACS lookup failed', 'how_to_fix' => 'Get a free Census Data API key and paste it in the Census API key field.' );
			} else {
				$out['neighborhood_figures'] = array( 'ok' => true, 'sample' => $c );
			}
		}

		if ( ! ZDH_Geo::hazard_layers() ) {
			$out['hazard_zones'] = array( 'ok' => true, 'note' => 'No hazard layer is set.' );
		} elseif ( $center ) {
			$f = ZDH_Geo::fire_zone( $center[0], $center[1] );
			$out['hazard_zones'] = is_wp_error( $f ) ? array( 'ok' => false, 'error' => $f->get_error_message() ) : array( 'ok' => true, 'sample' => $f );
		} else {
			$out['hazard_zones'] = array( 'ok' => false, 'error' => 'Set where the map opens to test the hazard layers.' );
		}

		if ( ZDH_Nutshell::configured() ) {
			$nu = ZDH_Nutshell::users();
			$out['crm'] = is_wp_error( $nu ) ? array( 'ok' => false, 'error' => $nu->get_error_message() ) : array( 'ok' => true, 'users' => count( $nu ), 'cross_check_for' => ZDH_Nutshell::owner_label(), 'read_only' => true );
		} elseif ( ZDH_Nutshell::has_key() ) {
			// the key is there; what's missing is which CRM user's customers to check for this person
			$out['crm'] = array( 'ok' => false, 'error' => 'The CRM login is set, but your account isn\'t matched to a CRM user, and no default user is chosen.', 'how_to_fix' => 'An administrator matches you to a CRM user on your profile, or picks a site default on Zorderz → Doorhangers (CRM cross-check).' );
		} else {
			$out['crm'] = array( 'ok' => false, 'error' => 'not set up (the CRM login on Zorderz → Settings)' );
		}

		$out['php'] = array( 'version' => PHP_VERSION, 'max_execution_time' => ini_get( 'max_execution_time' ), 'upload_max_filesize' => ini_get( 'upload_max_filesize' ), 'post_max_size' => ini_get( 'post_max_size' ), 'image_editor' => class_exists( 'Imagick' ) ? 'Imagick' : ( function_exists( 'gd_info' ) ? 'GD' : 'none' ) );
		return rest_ensure_response( $out );
	}
}

ZDH_REST::init();
