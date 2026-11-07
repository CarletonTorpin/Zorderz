<?php
/**
 * Plugin Name: Zorderz Camera
 * Description: Camera and photo capture for Zorderz Field OS. Photos saved to ZDZ_User_Media for cross-app use.
 * Version: 1.13.16
 * Author: Zorderz
 *
 * Behaviour, in short:
 *   - A live in-page viewfinder (getUserMedia) that stays open between shots: pinch and visible zoom controls with
 *     real lens selection, tap-to-focus/expose, exposure compensation, AE/AF lock, a shutter flash and haptic, and
 *     a full-screen confirmation of each shot. The system camera is the fallback when the live camera is refused.
 *   - Photos AND video are saved upright whatever way the phone is held: the device's real rotation (screen
 *     orientation, plus the motion sensor for the inverted case) rotates the capture canvas, the preview matches,
 *     and the controls re-flow for landscape and inverted portrait. Video records through an upright canvas pipe.
 *   - Video mode: a [Photo | Video] toggle; 1080p30 clips (HEVC where supported, else H.264/AAC MP4), a 2:00 cap
 *     per clip with a 30-seconds-left warning; chunks stream to a durable IndexedDB queue and upload while
 *     recording; on Stop the server joins them into one file with a first-frame poster.
 *   - Durable upload: an IndexedDB queue with single-uploader leases and Background Sync, idempotent by
 *     capture_uid; a washed-out frame (exposure not settled yet) is re-grabbed; a photo over 3000 px on its long
 *     edge is reduced before upload (GPS and time are read from the original first); transient failures back
 *     off, permanent ones wait for Retry.
 *   - Permissions: one at a time, when needed. Location and camera decisions are remembered per person (user meta
 *     + a local mirror), so a person who said no is not asked again and is shown where the switch is.
 *   - A photo or clip taken from a Job fires `zcam_photo_saved( $media_id, $job_id, $user_id )` on the server after
 *     the save, so Jobs can link it at once; a listener's failure never fails the save.
 *
 * @package Zorderz
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'ZCAM_VERSION', '1.13.16' );
//
define( 'ZCAM_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'ZCAM_NONCE', 'zcam_nonce_v1' );

// Where a clip's streamed parts wait, who may stream them, the per-person limits and the hourly clean-up.
require_once __DIR__ . '/includes/class-zcam-video-store.php';
ZCAM_Video_Store::boot();

/** Bundle deactivation: the clean-up event goes with the app. */
function zcam_deactivate() {
	ZCAM_Video_Store::unschedule();
}

/* ── Register as Zorderz Widget App ─────────────────── */

add_action( 'after_setup_theme', function () {
	if ( ! interface_exists( 'Zorderz\\Widget_App_Interface' ) ) return;

	$app = new class implements \Zorderz\Widget_App_Interface {

		public function get_config(): array {
			return [
				'id'          => 'zdz-camera',
				'nm'          => 'Camera',
				'name'        => 'Camera',
				'icon'        => 'camera',
				'cat'         => 'Tools',
				'cc'          => '#0EA5E9',
				'desc'        => 'Take photos and manage your photo library',
				'description' => 'Take photos and manage your photo library',
				// Intended audience (real Zorderz role slugs — verified against
				// the theme's ZDZ_User_Roles). NOTE: access is actually granted by
				// each user's `zdz_allowed_apps` meta (seeded from the role's app
				// defaults) and resolved in ZDZ_Plugin_API::get_user_app_configs();
				// this list is declarative intent, not the live gate. It previously
				// listed 'zdz_installer'/'zdz_office', which DO NOT EXIST as roles —
				// so it both lied and omitted the roles that genuinely get the
				// camera (admin/owner tier, the field roles, and the zdz_general
				// kiosk, whose app default explicitly includes 'zdz-camera').
				'roles'       => [ 'administrator', 'zdz_owner', 'zdz_admin', 'zdz_sales', 'zdz_operator', 'zdz_mfg', 'zdz_tech', 'zdz_general' ],
				'bridge_type' => 'inline_widget',
				'admin_url'   => '',
			];
		}

		public function render_mobile_view( int $user_id ): void {
			echo '<p>Use the dashboard widget.</p>';
		}

		public function render_dashboard_widget( int $user_id ): ?string {
			wp_enqueue_style( 'zcam-widget-css', ZCAM_PLUGIN_URL . 'assets/css/widget.css', [], ZCAM_VERSION );

			// exifr (vendored, no runtime CDN): reads EXIF — incl. HEIC/HEIF —
			// in the browser so iPhone provenance survives (PHP can't read HEIC).
			wp_enqueue_script( 'zcam-exifr', ZCAM_PLUGIN_URL . 'assets/js/vendor/exifr.umd.js', [], '7.1.3', true );
			// Durable IndexedDB upload queue (source of truth for uploads).
			wp_enqueue_script( 'zcam-queue', ZCAM_PLUGIN_URL . 'assets/js/zcam-queue.js', [], ZCAM_VERSION, true );
			// Widget controller depends on both of the above — and on the
			// theme's zdz-app-js, which localizes window.zdzData (the fresh wp_rest
			// nonce the camera now authenticates with). Declaring the dependency is
			// the theme's own documented pattern for "guarantees window.zdzData
			// first" (see functions.php login-panel enqueue). zdz-app-js is always
			// registered on the dashboard (it IS the SPA), so this can't 404 the
			// widget; widget.js additionally re-reads zdzData live at send time.
			wp_enqueue_script( 'zcam-widget-js', ZCAM_PLUGIN_URL . 'assets/js/widget.js', [ 'zcam-exifr', 'zcam-queue', 'zdz-app-js' ], ZCAM_VERSION, true );
			wp_localize_script( 'zcam-widget-js', 'zcamWidget', [
				'ajaxurl'     => admin_url( 'admin-ajax.php' ),
				'nonce'       => wp_create_nonce( ZCAM_NONCE ),
				'version'     => ZCAM_VERSION,
				// Capture the device's location at shutter time as a clearly-labeled
				// fallback when a photo has no embedded GPS EXIF. ON by default
				//: iOS strips embedded GPS from any photo taken *through*
				// the browser, so for fresh field captures this is the ONLY source
				// of coordinates for job-by-location matching. It never overwrites
				// real EXIF GPS (e.g. from an imported library photo). Sites that
				// don't want it can disable via the `zcam_geo_fallback` filter.
				'geoFallback' => (bool) apply_filters( 'zcam_geo_fallback', true ),
				// the browser remembers its own permission grant, but the
				// APP never did -- so it could not tell "declined" from "timed out",
				// and it had no way to stop nagging someone who had said no. This
				// is the server-side memory, which also follows a person across
				// devices; localStorage is the fast local mirror.
				'geoConsent'  => (string) ( get_user_meta( get_current_user_id(), 'zcam_geo_consent', true ) ?: 'unset' ),
				// the same server-side memory for the CAMERA permission,
				// so a grant/deny follows the person across devices and the app
				// stops re-poking a decision iOS already remembers for the app.
				'camConsent'  => (string) ( get_user_meta( get_current_user_id(), 'zcam_cam_consent', true ) ?: 'unset' ),
				// The installed app's name as the phone's Settings list shows it, for the "allow the camera" help.
				'appName'     => class_exists( 'ZDZ_Business_Profile' ) ? (string) ZDZ_Business_Profile::name() : (string) get_bloginfo( 'name' ),
				// Video mode only for someone the server takes clips from (never the shared device or Demo Mode).
				'video'       => '' === ZCAM_Video_Store::may_record( (int) get_current_user_id() ),
			] );

			ob_start(); ?>
			<div class="zcam-w" id="zcam-widget">
				<div class="zcam-w-tabs">
					<button class="zcam-w-tab zcam-w-tab-active" data-tab="capture">Take Photo</button>
					<button class="zcam-w-tab" data-tab="gallery">My Photos</button>
				</div>
				<div class="zcam-w-panel" id="zcam-w-tab-capture">
					<input type="file" accept="image/*" capture="environment" id="zcam-w-file" class="zcam-hidden-input" />
					<!-- v1.8.0: shown ONLY when this person has denied location. Once a
					     browser records a hard deny it never raises the prompt again --
					     getCurrentPosition just fails instantly with no dialog -- so
					     without this a single mis-tap silently removes a field tech from
					     the geo system forever and nobody ever finds out. Empty and
					     hidden by default; widget.js fills it. -->
					<div id="zcam-w-geo-chip" class="zcam-w-geo-chip" hidden></div>
					<!-- the "camera off" sibling of the geo chip; reuses
					     the same .zcam-w-geo-chip styling. Hidden until denied. -->
					<div id="zcam-w-cam-chip" class="zcam-w-geo-chip" hidden></div>
					<div class="zcam-w-actions">
						<button id="zcam-w-capture" class="zcam-w-btn zcam-w-btn-primary zcam-w-btn-full">
							<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14.5 4h-5L7 7H4a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2h-3l-2.5-3z"/><circle cx="12" cy="13" r="3"/></svg>
							Take Photo</button>
					</div>
					<!-- Optional per-photo note. Whatever's typed here is attached to the
					     NEXT photo(s) taken, so an installer can record what they're
					     shooting ("east window, torn screen") before tapping the shutter.
					     Stored in ZDZ_User_Media.description — editable later in Media. -->
					<div class="zcam-w-note-wrap">
						<input type="text" id="zcam-w-note" class="zcam-w-note" maxlength="2000"
						       placeholder="Add a note for the next photo (optional)"
						       autocomplete="off" autocapitalize="off" autocorrect="off" spellcheck="false" />
						<button type="button" id="zcam-w-note-clear" class="zcam-w-note-clear" aria-label="Clear note" hidden>&times;</button>
					</div>
					<p class="zcam-w-hint">Photos upload automatically — keep shooting, they save in the background. They're stored in your Media library.</p>
					<!-- Async upload status (non-blocking). This region RESERVES its space
					     so adding/removing items never shifts the dashboard (CLS fix). -->
					<div id="zcam-w-queue" class="zcam-w-queue" aria-live="polite"></div>
				</div>
				<div class="zcam-w-panel" id="zcam-w-tab-gallery" style="display:none;">
					<div id="zcam-w-gallery" class="zcam-w-gallery"></div>
				</div>
			</div>
			<?php return ob_get_clean();
		}
	};

	if ( function_exists( 'add_filter' ) ) {
		add_filter( 'zdz_register_apps', function ( $apps ) use ( $app ) {
			$apps[] = $app;
			return $apps;
		} );
	}
} );

/* ── AJAX Handlers ──────────────────────────────────────── */

add_action( 'init', function () {
	// The widget, its upload queues and the theme service worker all post zcam_* actions.
	add_action( 'wp_ajax_zcam_save_photo', 'zcam_ajax_save_photo' );
	add_action( 'wp_ajax_nopriv_zcam_save_photo', 'zcam_deny_nopriv' );
	add_action( 'wp_ajax_zcam_list_photos', 'zcam_ajax_list_photos' );
	add_action( 'wp_ajax_nopriv_zcam_list_photos', 'zcam_deny_nopriv' );
	add_action( 'wp_ajax_zcam_delete_photo', 'zcam_ajax_delete_photo' );
	add_action( 'wp_ajax_nopriv_zcam_delete_photo', 'zcam_deny_nopriv' );
	add_action( 'wp_ajax_zcam_geo_consent', 'zcam_ajax_geo_consent' );
	add_action( 'wp_ajax_nopriv_zcam_geo_consent', 'zcam_deny_nopriv' );
	add_action( 'wp_ajax_zcam_cam_consent', 'zcam_ajax_cam_consent' );
	add_action( 'wp_ajax_nopriv_zcam_cam_consent', 'zcam_deny_nopriv' );
	add_action( 'wp_ajax_zcam_video_chunk', 'zcam_ajax_video_chunk' );
	add_action( 'wp_ajax_nopriv_zcam_video_chunk', 'zcam_deny_nopriv' );
	add_action( 'wp_ajax_zcam_video_finalize', 'zcam_ajax_video_finalize' );
	add_action( 'wp_ajax_nopriv_zcam_video_finalize', 'zcam_deny_nopriv' );
} );

function zcam_deny_nopriv() { wp_send_json_error( 'Authentication required.', 403 ); }

/* ── Nonce verification ─────────────────────────────────────────
 * Dual-accept. PREFERRED: the theme's wp_rest nonce, sent as the standard
 * X-WP-Nonce header (or _wpnonce POST field). It is localized fresh onto
 * zdz-app-js (window.zdzData.nonce) on EVERY real page load — the exact
 * freshness property that makes the Media app's uploads work on this stack.
 * FALLBACK: the legacy ZCAM_NONCE 'nonce' POST field, kept so any in-flight
 * queue records / older clients keep working. The legacy nonce alone proved
 * unreliable because it is baked into widget HTML that the SPA re-injects
 * (and NitroPack can cache), so it can belong to a stale session/identity.
 */

/** The wp_rest nonce on this request: X-WP-Nonce header, else _wpnonce field. */
function zcam_incoming_rest_nonce(): string {
	if ( isset( $_SERVER['HTTP_X_WP_NONCE'] ) ) {
		return sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_WP_NONCE'] ) );
	}
	return isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';
}

/** True if EITHER the fresh wp_rest nonce OR the legacy ZCAM_NONCE verifies. */
function zcam_verify_request_nonce(): bool {
	$rest = zcam_incoming_rest_nonce();
	if ( $rest !== '' && wp_verify_nonce( $rest, 'wp_rest' ) ) {
		return true;
	}
	return (bool) check_ajax_referer( ZCAM_NONCE, 'nonce', false );
}

/**
 * A posted coordinate as a number within ±$bound degrees, or '' when it is missing, not a
 * number or out of range. The value is written to the media row and to the save log line,
 * so nothing but a plain number gets through.
 */
function zcam_posted_coord( string $key, float $bound ) {
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- called only from the save handlers, after zcam_verify_request_nonce() passed
	$raw = isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';
	if ( '' === $raw || ! is_numeric( $raw ) ) {
		return '';
	}
	$v = (float) $raw;
	return abs( $v ) <= $bound ? $v : '';
}

/**
 * - record this person's location-permission decision.
 *
 * Purely a UX memory. It grants nothing and gates nothing: the browser is still
 * the only authority on whether geolocation is allowed, and a capture works the
 * same either way. What it buys is the ability to STOP ASKING someone who
 * declined, and to show them where the switch is -- because once a browser
 * records a hard deny it never raises the prompt again, so an app that keeps
 * calling getCurrentPosition just fails instantly, forever, in silence.
 */
function zcam_ajax_geo_consent() {
	check_ajax_referer( ZCAM_NONCE, 'nonce' );
	if ( ! is_user_logged_in() ) { wp_send_json_error( 'Not signed in.' ); }

	$state = isset( $_POST['state'] ) ? sanitize_key( wp_unslash( $_POST['state'] ) ) : '';
	if ( ! in_array( $state, [ 'granted', 'denied', 'unset' ], true ) ) {
		wp_send_json_error( 'Bad state.' );
	}

	$uid = get_current_user_id();
	update_user_meta( $uid, 'zcam_geo_consent', $state );
	update_user_meta( $uid, 'zcam_geo_consent_at', gmdate( 'Y-m-d H:i:s' ) );
	if ( 'denied' === $state ) {
		$n = (int) get_user_meta( $uid, 'zcam_geo_prompt_count', true );
		update_user_meta( $uid, 'zcam_geo_prompt_count', $n + 1 );
	}
	error_log( 'ZCAM geo-consent: uid=' . $uid . ' state=' . $state );
	wp_send_json_success( [ 'state' => $state ] );
}

/**
 * - record this person's CAMERA-permission decision.
 *
 * The exact sibling of zcam_ajax_geo_consent(). Purely a UX memory: it grants
 * nothing and gates nothing -- the browser remains the only authority on camera
 * access, and a capture works either way (the system camera is always the
 * fallback). What it buys is the ability to STOP re-poking a decision iOS
 * already remembers for the installed app, to explain the system-camera
 * fallback, and to carry the record across the person's devices.
 */
function zcam_ajax_cam_consent() {
	check_ajax_referer( ZCAM_NONCE, 'nonce' );
	if ( ! is_user_logged_in() ) { wp_send_json_error( 'Not signed in.' ); }

	$state = isset( $_POST['state'] ) ? sanitize_key( wp_unslash( $_POST['state'] ) ) : '';
	if ( ! in_array( $state, [ 'granted', 'denied', 'unset' ], true ) ) {
		wp_send_json_error( 'Bad state.' );
	}

	$uid = get_current_user_id();
	update_user_meta( $uid, 'zcam_cam_consent', $state );
	update_user_meta( $uid, 'zcam_cam_consent_at', gmdate( 'Y-m-d H:i:s' ) );
	if ( 'denied' === $state ) {
		$n = (int) get_user_meta( $uid, 'zcam_cam_prompt_count', true );
		update_user_meta( $uid, 'zcam_cam_prompt_count', $n + 1 );
	}
	error_log( 'ZCAM cam-consent: uid=' . $uid . ' state=' . $state );
	wp_send_json_success( [ 'state' => $state ] );
}

function zcam_ajax_save_photo() {
	global $wpdb;
	$debug = [];

	// ── Entry log ───────────────────────────────────────────────────
	// Records that a save request ARRIVED at PHP, BEFORE the nonce check. The
	// nonce check (check_ajax_referer) calls wp_die() with no log of its own, so
	// a stale/missing nonce previously produced a totally silent failure — which
	// is exactly the "spinner stuck, nothing in the server log" symptom. With
	// this line, the log distinguishes "request never arrived" (no ZCAM line at
	// all → client/transport/proxy) from "arrived but nonce-rejected" (this line
	// present, then a nonce-fail line). Cheap, safe, one line per upload attempt.
	$debug[] = 'entry uid=' . ( isset( $_POST['capture_uid'] ) ? substr( preg_replace( '/[^A-Za-z0-9\-]/', '', (string) $_POST['capture_uid'] ), 0, 40 ) : '-' )
	         . ' files=' . ( isset( $_FILES['photo'] ) ? '1' : '0' )
	         . ' bytes=' . ( isset( $_FILES['photo']['size'] ) ? (int) $_FILES['photo']['size'] : 0 );
	error_log( 'ZCAM save: ' . implode( ' | ', $debug ) );

	// ── Nonce-debug ─────────────────────────────
	// One line per save attempt, logged BEFORE the gate: the uid this request
	// resolved to, plus whether EACH nonce verifies for that uid. Reading it:
	//   uid=0                      → request arrived logged OUT (cookie/session
	//                                not sent or stripped) — no nonce can pass.
	//   uid=<kiosk/General id>     → the kiosk/demo determine_current_user
	//                                switch is in play.
	//   zcam=false wprest_hdr=1   → legacy nonce stale, fresh wp_rest accepted
	//                                — the v1.2.8 fix path working as designed.
	//   both false, uid logged in  → deeper session-token mismatch.
	// wp_verify_nonce returns 1|2 (valid, by age) or false. Remove this line
	// once a release has been verified clean, if log noise matters.
	$legacy_nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
	error_log( 'ZCAM nonce-debug: uid=' . get_current_user_id()
		. ' zcam=' . var_export( wp_verify_nonce( $legacy_nonce, ZCAM_NONCE ), true )
		. ' wprest_hdr=' . var_export( wp_verify_nonce( zcam_incoming_rest_nonce(), 'wp_rest' ), true ) );

	// Verify WITHOUT the silent wp_die, so a failure is logged and the client
	// gets a clean JSON error it can surface (instead of a hung request).
	// dual-accept — fresh wp_rest nonce (X-WP-Nonce) OR legacy nonce.
	if ( ! zcam_verify_request_nonce() ) {
		error_log( 'ZCAM save: nonce check FAILED (stale/missing). ' . implode( ' | ', $debug ) );
		wp_send_json_error( 'Your session expired — please reload and try again.', 403 );
	}
	if ( ! is_user_logged_in() ) wp_send_json_error( 'Not logged in.' );
	$debug[] = 'auth:ok user=' . get_current_user_id();

	if ( ! class_exists( 'ZDZ_User_Media' ) ) {
		error_log( 'ZCAM save: ZDZ_User_Media class not found. ' . implode( ' | ', $debug ) );
		wp_send_json_error( 'Photo storage is unavailable. Please try again.' );
	}
	$debug[] = 'class:ok';

	// ── Idempotency ──────────────────────────────────────────────────────
	// Background Sync can re-deliver a request whose response was lost on a
	// dropped connection. The client sends a stable capture_uid; if we've
	// already stored it, return the existing row instead of inserting a dupe.
	$capture_uid = isset( $_POST['capture_uid'] ) ? sanitize_text_field( wp_unslash( $_POST['capture_uid'] ) ) : '';
	if ( $capture_uid !== '' ) {
		$existing = zcam_find_by_capture_uid( get_current_user_id(), $capture_uid );
		if ( $existing ) {
			wp_send_json_success( [
				'media_id'      => (int) $existing['id'],
				'file_url'      => $existing['file_url'],
				'thumbnail_url' => $existing['thumbnail_url'] ?: $existing['file_url'],
				'title'         => $existing['title'],
				'duplicate'     => true,
			] );
		}
	}

	if ( empty( $_FILES['photo'] ) ) {
		error_log( 'ZCAM save: no photo file. ' . implode( ' | ', $debug ) );
		wp_send_json_error( 'No photo received. Please try again.' );
	}

	// ── Empty-body guard ────────────────────────────────────────
	// A present-but-0-byte file part means the CLIENT streamed zero bytes —
	// the iOS IndexedDB read-back bug (see widget.js 1.2.9), NOT a server
	// upload config problem. Catch it before media_handle_upload so the log
	// gets a truthful line instead of the misleading php.ini-flavored "File
	// is empty" error. The client only marks an item failed-and-retryable on
	// this response, and since 1.2.9 it won't send a body it knows is empty —
	// so this line appearing means bytes were lost IN TRANSIT (new info).
	if ( (int) ( $_FILES['photo']['error'] ?? 0 ) === UPLOAD_ERR_OK
	     && (int) ( $_FILES['photo']['size'] ?? 0 ) === 0 ) {
		error_log( 'ZCAM save: EMPTY file body (0 bytes) — client-side bytes lost. ' . implode( ' | ', $debug ) );
		wp_send_json_error( 'The photo data didn\'t arrive — it will retry automatically.' );
	}
	// server-side image-type guard (mirrors zdz-media-library). The save
	// path widens upload_mimes for HEIC, so verify the file really is an image
	// before handing it to media_handle_upload — a non-image (e.g. a PDF) gets a
	// clear rejection and can never land in the photo store.
	$ftype = wp_check_filetype( (string) ( $_FILES['photo']['name'] ?? '' ) );
	if ( empty( $ftype['type'] ) || strpos( (string) $ftype['type'], 'image/' ) !== 0 ) {
		wp_send_json_error( 'That file isn\'t an image. Only photos can be added here.' );
	}

	$debug[] = 'file:' . $_FILES['photo']['name'] . ' size=' . $_FILES['photo']['size'];

	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';

	// Stores the ORIGINAL uploaded file as the full-size attachment.
	// get_attached_file() (used by ZDZ_User_Media for server-side EXIF) returns
	// exactly this original — so JPEG/TIFF EXIF survives end-to-end.
	$att_id = media_handle_upload( 'photo', 0, [], [ 'test_form' => false ] );
	if ( is_wp_error( $att_id ) ) {
		error_log( 'ZCAM save: upload_err ' . $att_id->get_error_message() . ' | ' . implode( ' | ', $debug ) );
		wp_send_json_error( 'Upload failed. Please try again.' );
	}
	$debug[] = 'attachment:' . $att_id;

	$url   = wp_get_attachment_url( $att_id );
	$thumb = wp_get_attachment_image_url( $att_id, 'medium' );

	// Auto-title: "Photo — May 10, 3:45 PM"
	$title = 'Photo — ' . date( 'M j, g:i A' );

	// ── Client-supplied provenance (HEIC safety net) ─────────────────────
	// exifr reads EXIF — including HEIC, which PHP's exif_read_data() cannot —
	// in the browser. We pass those values through; ZDZ_User_Media::save()
	// prefers them, then falls back to reading EXIF from the original (JPEG),
	// and always logs the raw EXIF block to meta_json.
	$captured_at = isset( $_POST['captured_at'] ) ? sanitize_text_field( wp_unslash( $_POST['captured_at'] ) ) : '';
	$gps_lat     = zcam_posted_coord( 'gps_lat', 90.0 );
	$gps_lng     = zcam_posted_coord( 'gps_lng', 180.0 );
	$geo_source  = isset( $_POST['geo_source'] ) ? sanitize_key( $_POST['geo_source'] ) : '';
	// Capture-time provenance: 'exif' (from the file) or 'device_clock' (the
	// device's local clock at shutter, used when the file carried no EXIF date —
	// the common iOS capture-through-browser case). Independent of geo_source.
	$time_source = isset( $_POST['time_source'] ) ? sanitize_key( $_POST['time_source'] ) : '';

	// - GPS accuracy, in metres, as reported by the device.
	// Deliberately a meta_json value rather than a column: nothing queries on it
	// yet, and adding a column to the zdz_user_media table means walking into
	// ZDZ_User_Media::ensure_table()'s version fast-path. When the job-matching
	// work needs to filter on accuracy it can add an indexed column and backfill
	// from here, because the value will already be on every row by then.
	// NOTE this only ever exists for the device_fallback path -- EXIF has no
	// accuracy concept -- and it is client-supplied and unverifiable, so treat
	// it as a hint. meta_json.geo_source stays the real discriminator.
	$gps_accuracy = '';
	if ( isset( $_POST['gps_accuracy'] ) && is_numeric( $_POST['gps_accuracy'] ) ) {
		$acc = (float) $_POST['gps_accuracy'];
		if ( $acc >= 0 && $acc < 1000000 ) { $gps_accuracy = round( $acc, 2 ); }
	}

	// - job context. Set only when the camera was launched FROM a job,
	// which makes it a deliberate assertion rather than an inference.
	$job_id = isset( $_POST['job_id'] ) ? absint( $_POST['job_id'] ) : 0;

	// Optional per-photo note → description.
	// Mirrors the Media app's 2000-char cap on description.
	$note = isset( $_POST['note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['note'] ) ) : '';
	if ( $note !== '' && function_exists( 'mb_substr' ) ) {
		$note = mb_substr( $note, 0, 2000 );
	} elseif ( $note !== '' ) {
		$note = substr( $note, 0, 2000 );
	}

	// Privacy: intentionally NOT set here, so ZDZ_User_Media::save() applies its
	// 'private' default. On this platform 'private' already means "the UPLOADER
	// and any ADMIN can view it" — the theme's gated serve() layer
	// resolves private → owner + admin-tier (manage_options / view_others_data /
	// zdz_owner|zdz_admin). So a field photo defaults to owner-visible-in-lists +
	// admin-viewable, and admins DISCOVER it via the Media app's admin-only
	// "All" scope (zdz-media-library >= 2.3.0). Non-admin staff and the shared
	// kiosk do not see it. Do NOT add 'privacy' => 'public' here — that would
	// expose every capture org-wide to everyone.
	$save_args = [
		'user_id'          => get_current_user_id(),
		'file_url'         => $url,
		'thumbnail_url'    => $thumb ?: $url,
		'filename'         => $_FILES['photo']['name'],
		'media_type'       => 'photo',
		'source_app'       => 'zdz-camera',
		// Embed the capture_uid so future retries can dedupe (see lookup helper).
		// The uid form is the idempotency key that get_by_source_ref() dedupes on,
		// so it stays authoritative; the job link rides in meta_json instead of
		// overwriting it. (A job-scoped source_ref would break retry dedupe.)
		'source_ref'       => $capture_uid !== '' ? ( 'photo:uid:' . $capture_uid ) : ( 'photo:' . $att_id ),
		'title'            => $title,
		'description'      => $note,            // photo purpose, set at capture (optional)
		'wp_attachment_id' => $att_id,
		'captured_at'      => $captured_at,   // Tier-1 arg (preferred when present)
		'gps_lat'          => $gps_lat,
		'gps_lng'          => $gps_lng,
	];
	$meta = [];
	if ( $geo_source !== '' )   { $meta['geo_source']   = $geo_source; }
	if ( $time_source !== '' )  { $meta['time_source']  = $time_source; }
	if ( $gps_accuracy !== '' ) { $meta['gps_accuracy'] = $gps_accuracy; }
	if ( $job_id > 0 )          { $meta['job_id']       = $job_id; }
	if ( ! empty( $meta ) ) {
		$save_args['meta'] = $meta;
	}

	$media = ZDZ_User_Media::save( $save_args );

	if ( ! $media ) {
		error_log( 'ZCAM save: metadata save failed. last_db_error:' . $wpdb->last_error . ' | ' . implode( ' | ', $debug ) );
		wp_send_json_error( 'Could not save photo details. Please try again.' );
	}

	/* - SUCCESS LOG. There was not one before: the only 'ZCAM save:'
	 * line on a good upload printed uid/files/bytes and every other line in this
	 * file is an error path. That is why the 30-Jul field investigation
	 * concluded "no gps/lat/lng appears in the save lines" and inferred the user
	 * had declined -- the save line never printed GPS at all, so its absence was
	 * never evidence of anything. Print what was actually stored, so real-world
	 * geotag coverage can be measured instead of guessed. */
	$geo_log = ( $gps_lat !== '' && $gps_lng !== '' )
		? ( 'gps=' . $gps_lat . ',' . $gps_lng
			. ' acc=' . ( $gps_accuracy !== '' ? $gps_accuracy . 'm' : '?' )
			. ' geo_source=' . ( $geo_source !== '' ? $geo_source : '?' ) )
		: ( 'gps=none reason=' . ( isset( $_POST['geo_reason'] ) ? sanitize_key( $_POST['geo_reason'] ) : 'not_sent' ) );
	error_log( 'ZCAM save: ok media=' . (int) $media['id'] . ' att=' . (int) $att_id
		. ' ' . $geo_log
		. ' time_source=' . ( $time_source !== '' ? $time_source : '?' )
		. ( $job_id > 0 ? ' job=' . $job_id : '' ) );

	zcam_photo_saved( (int) $media['id'], $job_id, (int) get_current_user_id() );

	wp_send_json_success( [ 'media_id' => $media['id'], 'file_url' => $url, 'thumbnail_url' => $thumb ?: $url, 'title' => $media['title'] ] );
}

/**
 * Tell the server a photo was saved. A Jobs app listens (checking the photographer may work that Job before it links
 * anything); without this only the browser hears about a save (widget.js dispatches its own event), so a photo taken
 * from a Job would reach the Job only when someone looked for it. Fired once per new save, only with a Job on it, and
 * never lets a listener's failure fail the upload.
 */
function zcam_photo_saved( int $media_id, int $job_id, int $user_id ) {
	if ( $media_id <= 0 || $job_id <= 0 || $user_id <= 0 ) {
		return;
	}
	try {
		do_action( 'zcam_photo_saved', $media_id, $job_id, $user_id );
	} catch ( \Throwable $e ) {
		error_log( 'ZCAM save: a zcam_photo_saved listener failed (' . get_class( $e ) . '); the photo is saved.' );
	}
}

/**
 * Look up a previously-saved camera photo by its client capture_uid.
 * Used for Background-Sync idempotency. Scans this user's recent camera
 * photos for a source_ref of "photo:uid:<uid>".
 */
function zcam_find_by_capture_uid( int $user_id, string $capture_uid ): ?array {
	if ( $capture_uid === '' || ! class_exists( 'ZDZ_User_Media' ) ) return null;
	$needle = 'photo:uid:' . $capture_uid;
	// indexed single-row lookup (was a scan of only the 50 newest camera
	// photos — a retried upload whose original was older than that window missed
	// and thus inserted a DUPLICATE). Uses the theme's idx_source_ref getter when
	// available; falls back to the bounded scan on an older
	// theme so the plugin stays self-contained.
	if ( method_exists( 'ZDZ_User_Media', 'get_by_source_ref' ) ) {
		$hit = ZDZ_User_Media::get_by_source_ref( $user_id, $needle );
		return $hit ?: null;
	}
	$items  = ZDZ_User_Media::get_user_media( $user_id, [
		'media_type' => 'photo',
		'source_app' => 'zdz-camera',
		'limit'      => 50,
	] );
	foreach ( (array) $items as $it ) {
		if ( isset( $it['source_ref'] ) && $it['source_ref'] === $needle ) {
			return $it;
		}
	}
	return null;
}

function zcam_ajax_list_photos() {
	// dual-accept (fresh wp_rest via X-WP-Nonce, OR legacy nonce) and a
	// clean JSON 403 instead of check_ajax_referer's silent wp_die('-1').
	if ( ! zcam_verify_request_nonce() ) {
		wp_send_json_error( 'Your session expired — please reload and try again.', 403 );
	}
	if ( ! is_user_logged_in() ) wp_send_json_error( 'Not logged in.' );
	if ( ! class_exists( 'ZDZ_User_Media' ) ) { wp_send_json_success( [ 'photos' => [] ] ); return; }
	$items = ZDZ_User_Media::get_user_media( get_current_user_id(), [
		'media_type' => 'photo',
		'limit'      => 30,
		'offset'     => (int) ( $_POST['offset'] ?? 0 ),
	] );
	$out = array_map( function ( $p ) {
		return [
			'id'            => (int) $p['id'],
			'title'         => $p['title'],
			'description'   => $p['description'] ?? '',
			'thumbnail_url' => $p['thumbnail_url'] ?: $p['file_url'],
			'thumbnail_2x_url' => (string) ( $p['thumbnail_2x_url'] ?? '' ), // the theme's 768-pixel thumb, when it makes one
			'file_url'      => $p['file_url'],
			'source_app'    => $p['source_app'],
			'privacy'       => $p['privacy'],
			'created_at'    => $p['created_at'],
		];
	}, $items );
	wp_send_json_success( [ 'photos' => $out ] );
}

function zcam_ajax_delete_photo() {
	// dual-accept (fresh wp_rest via X-WP-Nonce, OR legacy nonce) and a
	// clean JSON 403 instead of check_ajax_referer's silent wp_die('-1').
	if ( ! zcam_verify_request_nonce() ) {
		wp_send_json_error( 'Your session expired — please reload and try again.', 403 );
	}
	if ( ! is_user_logged_in() ) wp_send_json_error( 'Not logged in.' );
	$id = (int) ( $_POST['media_id'] ?? 0 );
	if ( ! $id || ! class_exists( 'ZDZ_User_Media' ) ) wp_send_json_error( 'Invalid.' );
	$deleted = ZDZ_User_Media::delete( $id, get_current_user_id() );
	if ( ! $deleted ) wp_send_json_error( 'Delete failed.' );
	wp_send_json_success();
}

/* ── Allow HEIC/HEIF uploads ─────────────────────────────────────────────
 * iPhones capture HEIC by default, and that original file is what carries
 * EXIF provenance. WordPress doesn't allow .heic/.heif by default, so the
 * original would be rejected and we'd lose provenance. Permit them here.
 * Note: we store the ORIGINAL untouched. If a separate process transcodes
 * HEIC for browser display, it must write a COPY — get_attached_file() must
 * keep returning the original for EXIF reads.
 */
add_filter( 'upload_mimes', function ( $mimes ) {
	$mimes['heic'] = 'image/heic';
	$mimes['heif'] = 'image/heif';
	// video clips. MediaRecorder on iOS produces MP4 (H.264/HEVC+AAC);
	// Chrome/Firefox produce WebM. MOV is allowed for any native-shell path later.
	$mimes['mp4']  = 'video/mp4';
	$mimes['m4v']  = 'video/mp4';
	$mimes['mov']  = 'video/quicktime';
	$mimes['webm'] = 'video/webm';
	return $mimes;
} );

/* ══════════════════════════════════════════════════════════════════════════
 * VIDEO — chunked, resumable clip upload.
 *
 * A clip (one MediaRecorder session, ≤2 min) is streamed to the server AS IT
 * RECORDS as an ordered set of small "parts". zcam_video_chunk appends one part
 * idempotently (a retried part overwrites the same file — no double-write). When
 * the recorder stops, zcam_video_finalize concatenates parts 0..N-1 into one
 * file (the concatenation of MediaRecorder timeslice chunks IS a valid MP4/WebM;
 * individual parts are NOT independently playable), sideloads it as a WP video
 * attachment + a poster image, writes a ZDZ_User_Media row (media_type='video'),
 * and deletes the parts. Idempotent by capture_uid, so a re-delivered finalize
 * returns the existing row instead of creating a duplicate.
 *
 * Both requests need the Camera app and are refused for the shared device and
 * Demo Mode; a part over the person's limits (unfinished clips, bytes waiting)
 * is refused with 429 so the phone sends it again later. Where the parts wait,
 * and the hourly clean-up of abandoned ones: ZCAM_Video_Store.
 * ════════════════════════════════════════════════════════════════════════ */

/** Safe per-clip temp dir for streamed parts (outside the Media Library; see ZCAM_Video_Store). */
function zcam_video_parts_dir( int $user_id, string $capture_uid, bool $create = false ): string {
	return ZCAM_Video_Store::parts_dir( $user_id, $capture_uid, $create );
}

/** Refuse a clip request from someone the server takes no clips from (403, in words). */
function zcam_video_gate() {
	$why = ZCAM_Video_Store::may_record( (int) get_current_user_id() );
	if ( '' !== $why ) {
		wp_send_json_error( ZCAM_Video_Store::refusal( $why ), 403 );
	}
}

/** Receive one clip part and append it durably (idempotent by idx). */
function zcam_ajax_video_chunk() {
	if ( ! zcam_verify_request_nonce() ) { wp_send_json_error( 'Your session expired — please reload and try again.', 403 ); }
	if ( ! is_user_logged_in() ) { wp_send_json_error( 'Not logged in.', 403 ); }
	zcam_video_gate();

	$uid = isset( $_POST['capture_uid'] ) ? sanitize_text_field( wp_unslash( $_POST['capture_uid'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked by zcam_verify_request_nonce() at the top of this handler
	if ( ! preg_match( '/^[A-Za-z0-9\-]{8,64}$/', $uid ) ) { wp_send_json_error( 'Bad clip id.' ); }
	$idx = isset( $_POST['idx'] ) ? absint( $_POST['idx'] ) : -1; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked by zcam_verify_request_nonce() at the top of this handler
	if ( $idx < 0 || $idx > 20000 ) { wp_send_json_error( 'Bad chunk index.' ); }
	$chunk      = ( isset( $_FILES['chunk'] ) && is_array( $_FILES['chunk'] ) ) ? $_FILES['chunk'] : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce checked by zcam_verify_request_nonce() at the top of this handler; tmp_name is checked with is_uploaded_file() and size cast to int below
	$chunk_tmp  = isset( $chunk['tmp_name'] ) && is_string( $chunk['tmp_name'] ) ? $chunk['tmp_name'] : '';
	$chunk_size = isset( $chunk['size'] ) ? (int) $chunk['size'] : 0;
	if ( '' === $chunk_tmp || ! is_uploaded_file( $chunk_tmp ) ) {
		wp_send_json_error( 'No chunk received.' );
	}
	// A 2s slice at ~5 Mbps is ~1.25 MB; 25 MB is a generous ceiling that still
	// refuses a runaway upload.
	if ( $chunk_size > 25 * 1024 * 1024 ) { wp_send_json_error( 'Chunk too large.' ); }

	// The person's limits: unfinished clips and bytes waiting. Over either, "try later" (429): the phone keeps the
	// part and sends it again once earlier clips are finished.
	$me      = (int) get_current_user_id();
	$safe    = (string) preg_replace( '/[^A-Za-z0-9\-]/', '', $uid );
	$known   = zcam_video_parts_dir( $me, $uid, false );
	$replace = ( '' !== $known && is_file( $known . '/' . $idx . '.part' ) ) ? (int) filesize( $known . '/' . $idx . '.part' ) : 0;
	$over    = ZCAM_Video_Store::admit(
		ZCAM_Video_Store::usage( ZCAM_Video_Store::user_dirs( $me ) ),
		$safe,
		$chunk_size,
		$replace,
		ZCAM_Video_Store::max_open(),
		ZCAM_Video_Store::quota_bytes()
	);
	if ( 'open' === $over ) {
		wp_send_json_error( __( 'Earlier clips are still uploading. This one will follow.', 'zorderz' ), 429 );
	}
	if ( 'bytes' === $over ) {
		wp_send_json_error( __( 'Too much video is waiting to upload. This clip will follow.', 'zorderz' ), 429 );
	}

	$dir = zcam_video_parts_dir( $me, $uid, true );
	if ( $dir === '' || ! is_dir( $dir ) ) { wp_send_json_error( 'Could not open clip storage.' ); }

	$dest = $dir . '/' . $idx . '.part';
	if ( ! @move_uploaded_file( $chunk_tmp, $dest ) ) { // sentinel:allow video chunk into the private per-user clip store after nonce, app grant and quota checks
		// Fallback copy (some hosts): still idempotent.
		if ( ! @copy( $chunk_tmp, $dest ) ) { wp_send_json_error( 'Could not store chunk.' ); }
	}
	wp_send_json_success( [ 'idx' => $idx ] );
}

/** Assemble a clip's parts → attachment + poster + ZDZ_User_Media video row. */
function zcam_ajax_video_finalize() {
	global $wpdb;
	if ( ! zcam_verify_request_nonce() ) { wp_send_json_error( 'Your session expired — please reload and try again.', 403 ); }
	if ( ! is_user_logged_in() ) { wp_send_json_error( 'Not logged in.', 403 ); }
	zcam_video_gate();
	if ( ! class_exists( 'ZDZ_User_Media' ) ) { wp_send_json_error( 'Video storage is unavailable.' ); }

	$uid = isset( $_POST['capture_uid'] ) ? sanitize_text_field( wp_unslash( $_POST['capture_uid'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked by zcam_verify_request_nonce() at the top of this handler
	if ( ! preg_match( '/^[A-Za-z0-9\-]{8,64}$/', $uid ) ) { wp_send_json_error( 'Bad clip id.' ); }
	$user_id = get_current_user_id();

	// ── Idempotency: a re-delivered finalize returns the existing row ─────────
	$source_ref = 'video:uid:' . $uid;
	if ( method_exists( 'ZDZ_User_Media', 'get_by_source_ref' ) ) {
		$existing = ZDZ_User_Media::get_by_source_ref( $user_id, $source_ref );
		if ( $existing && ! empty( $existing['id'] ) ) {
			wp_send_json_success( [ 'media_id' => (int) $existing['id'], 'file_url' => $existing['file_url'] ?? '', 'duplicate' => true ] );
		}
	}

	$total = isset( $_POST['total_chunks'] ) ? absint( $_POST['total_chunks'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked by zcam_verify_request_nonce() at the top of this handler
	if ( $total < 1 || $total > 20000 ) { wp_send_json_error( 'Nothing to finalize.' ); }
	$ext   = isset( $_POST['ext'] ) ? preg_replace( '/[^a-z0-9]/', '', sanitize_key( wp_unslash( $_POST['ext'] ) ) ) : 'mp4'; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked by zcam_verify_request_nonce() at the top of this handler
	if ( ! in_array( $ext, [ 'mp4', 'webm', 'm4v', 'mov' ], true ) ) { $ext = 'mp4'; }

	$dir = zcam_video_parts_dir( $user_id, $uid, false );
	if ( $dir === '' || ! is_dir( $dir ) ) { wp_send_json_error( 'Clip storage not found — please retry.' ); }

	// ── Concatenate parts 0..total-1 into one file (streamed, low memory) ─────
	$assembled = $dir . '/clip.' . $ext;
	$out = @fopen( $assembled, 'wb' );
	if ( ! $out ) { wp_send_json_error( 'Could not assemble clip.' ); }
	for ( $i = 0; $i < $total; $i++ ) {
		$part = $dir . '/' . $i . '.part';
		if ( ! file_exists( $part ) ) {
			fclose( $out ); @unlink( $assembled );
			wp_send_json_error( 'Missing chunk ' . $i . ' — please retry.' ); // client re-drains + re-finalizes
		}
		$in = @fopen( $part, 'rb' );
		if ( ! $in ) { fclose( $out ); @unlink( $assembled ); wp_send_json_error( 'Unreadable chunk ' . $i . '.' ); }
		stream_copy_to_stream( $in, $out );
		fclose( $in );
	}
	fclose( $out );
	if ( ! file_exists( $assembled ) || filesize( $assembled ) < 1 ) { @unlink( $assembled ); wp_send_json_error( 'Empty clip.' ); }

	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';

	// Sideload the assembled clip as a video attachment (moves it into uploads).
	$name = 'clip-' . gmdate( 'Ymd-His' ) . '-' . substr( $uid, 0, 8 ) . '.' . $ext;
	$file_array = [ 'name' => $name, 'tmp_name' => $assembled ];
	$att_id = media_handle_sideload( $file_array, 0, null, [ 'test_form' => false ] );
	if ( is_wp_error( $att_id ) ) {
		@unlink( $assembled );
		zcam_video_cleanup_dir( $dir );
		error_log( 'ZCAM video finalize: sideload failed ' . $att_id->get_error_message() );
		wp_send_json_error( 'Could not save the video. Please try again.' );
	}
	$file_url = wp_get_attachment_url( $att_id );

	// Poster (first-frame JPEG the client sent) → its own image attachment.
	$poster_att = 0; $thumb_url = '';
	$poster     = ( isset( $_FILES['poster'] ) && is_array( $_FILES['poster'] ) ) ? $_FILES['poster'] : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce checked by zcam_verify_request_nonce() at the top of this handler; tmp_name is checked with is_uploaded_file() and the name only gives the file type
	$poster_tmp = isset( $poster['tmp_name'] ) && is_string( $poster['tmp_name'] ) ? $poster['tmp_name'] : '';
	if ( '' !== $poster_tmp && is_uploaded_file( $poster_tmp ) ) {
		$pft = wp_check_filetype( sanitize_file_name( isset( $poster['name'] ) && is_string( $poster['name'] ) ? $poster['name'] : 'poster.jpg' ) );
		if ( ! empty( $pft['type'] ) && strpos( $pft['type'], 'image/' ) === 0 ) {
			$poster_att = media_handle_upload( 'poster', 0, [], [ 'test_form' => false ] );
			if ( ! is_wp_error( $poster_att ) && $poster_att ) {
				$thumb_url = wp_get_attachment_image_url( $poster_att, 'medium' ) ?: wp_get_attachment_url( $poster_att );
			} else { $poster_att = 0; }
		}
	}

	// Provenance + reel metadata (mirrors the photo path; extras ride meta_json).
	// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce checked by zcam_verify_request_nonce() at the top of this handler; each field is sanitized or cast as it is read
	$captured_at  = isset( $_POST['captured_at'] ) ? sanitize_text_field( wp_unslash( $_POST['captured_at'] ) ) : '';
	$gps_lat      = zcam_posted_coord( 'gps_lat', 90.0 );
	$gps_lng      = zcam_posted_coord( 'gps_lng', 180.0 );
	$geo_source   = isset( $_POST['geo_source'] ) ? sanitize_key( $_POST['geo_source'] ) : '';
	$time_source  = isset( $_POST['time_source'] ) ? sanitize_key( $_POST['time_source'] ) : '';
	$job_id       = isset( $_POST['job_id'] ) ? absint( $_POST['job_id'] ) : 0;
	$reel_id      = isset( $_POST['reel_id'] ) ? preg_replace( '/[^A-Za-z0-9\-]/', '', sanitize_text_field( wp_unslash( $_POST['reel_id'] ) ) ) : '';
	$seq          = isset( $_POST['seq'] ) ? absint( $_POST['seq'] ) : 0;
	$duration_ms  = isset( $_POST['duration_ms'] ) ? absint( $_POST['duration_ms'] ) : 0;
	$width        = isset( $_POST['width'] ) ? absint( $_POST['width'] ) : 0;
	$height       = isset( $_POST['height'] ) ? absint( $_POST['height'] ) : 0;
	$codec        = isset( $_POST['mime'] ) ? sanitize_text_field( wp_unslash( $_POST['mime'] ) ) : '';
	$note         = isset( $_POST['note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['note'] ) ) : '';
	if ( $note !== '' ) { $note = function_exists( 'mb_substr' ) ? mb_substr( $note, 0, 2000 ) : substr( $note, 0, 2000 ); }

	$gps_accuracy = '';
	if ( isset( $_POST['gps_accuracy'] ) && is_numeric( $_POST['gps_accuracy'] ) ) {
		$acc = (float) $_POST['gps_accuracy'];
		if ( $acc >= 0 && $acc < 1000000 ) { $gps_accuracy = round( $acc, 2 ); }
	}
	// phpcs:enable WordPress.Security.NonceVerification.Missing

	$meta = [
		'reel_id'     => $reel_id,
		'seq'         => $seq,
		'duration_ms' => $duration_ms,
		'width'       => $width,
		'height'      => $height,
		'codec'       => $codec,       // e.g. "video/mp4;codecs=hvc1"
		'has_hdr'     => false,        // PWA capture is SDR; true HDR = native tier
	];
	if ( $poster_att )         { $meta['poster_attachment_id'] = (int) $poster_att; }
	if ( $geo_source !== '' )  { $meta['geo_source']   = $geo_source; }
	if ( $time_source !== '' ) { $meta['time_source']  = $time_source; }
	if ( $gps_accuracy !== '' ){ $meta['gps_accuracy'] = $gps_accuracy; }
	if ( $job_id > 0 )         { $meta['job_id']       = $job_id; }

	// privacy: intentionally NOT set → ZDZ_User_Media defaults to 'private', which
	// on this platform means "uploader + admin-tier can view", discoverable via
	// the Media app's admin "All" scope — the camera-photo model,
	// so clips ride onto Projects/Receipts the way photos do.
	$save_args = [
		'user_id'          => $user_id,
		'file_url'         => $file_url,
		'thumbnail_url'    => $thumb_url ?: $file_url,
		'filename'         => $name,
		'media_type'       => 'video',
		'source_app'       => 'zdz-camera',
		'source_ref'       => $source_ref,   // idempotency key
		'title'            => 'Video — ' . date( 'M j, g:i A' ),
		'description'      => $note,
		'wp_attachment_id' => $att_id,
		'captured_at'      => $captured_at,
		'gps_lat'          => $gps_lat,
		'gps_lng'          => $gps_lng,
		'meta'             => $meta,
	];
	$media = ZDZ_User_Media::save( $save_args );
	if ( ! $media ) {
		error_log( 'ZCAM video finalize: ZDZ_User_Media::save failed. ' . $wpdb->last_error );
		wp_send_json_error( 'Could not save video details. Please try again.' );
	}

	zcam_video_cleanup_dir( $dir );

	error_log( 'ZCAM video: ok media=' . (int) $media['id'] . ' att=' . (int) $att_id
		. ' reel=' . $reel_id . ' seq=' . $seq . ' chunks=' . $total
		. ' dur=' . $duration_ms . 'ms ' . ( $gps_lat !== '' ? 'gps=' . $gps_lat . ',' . $gps_lng : 'gps=none' )
		. ( $job_id ? ' job=' . $job_id : '' ) );

	zcam_photo_saved( (int) $media['id'], $job_id, $user_id );

	wp_send_json_success( [
		'media_id'      => (int) $media['id'],
		'file_url'      => $file_url,
		'thumbnail_url' => $thumb_url,
		'title'         => $media['title'],
		'reel_id'       => $reel_id,
		'seq'           => $seq,
	] );
}

/** Remove a clip's temp parts directory (best-effort). */
function zcam_video_cleanup_dir( string $dir ) {
	if ( $dir === '' || ! is_dir( $dir ) ) { return; }
	$files = glob( $dir . '/*' );
	if ( is_array( $files ) ) { foreach ( $files as $f ) { @unlink( $f ); } }
	@rmdir( $dir );
}
