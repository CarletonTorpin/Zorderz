<?php
/**
 * Plugin Name: Zorderz Media
 * Description: Organization media library for Zorderz Field OS. Browse public photos & sketches across the org, plus your own uploads — grouped by date, filterable by type/source, with transcribable notes, keyword tags, per-photo visibility, rotate, share to a teammate and drawings on a photo. Add photos directly via bulk upload. Reads from / writes to ZDZ_User_Media.
 * Version: 2.14.0
 * Author: Zorderz
 * Requires PHP: 8.0
 *
 * Behaviour, in short:
 *   - Scopes: My photos, Public, and (admins) All, grouped by date and filterable by type and source.
 *   - Notes and keyword tags on each photo; visibility private / team / public; delete (granular and bulk,
 *     double-confirmed); bulk upload; rotate one photo 90° at a time, in place (owner or admin).
 *   - Share a photo to a teammate as a direct message; move a photo to someone else when the theme allows it.
 *   - The uploader's initials on each photo tile.
 *   - Draw on a photo: the theme kit's ZDZ.ui.marks layer over any viewer's image, stored apart from the photo by
 *     the platform's media-marks service (ZDZ_Media_Marks); without that service the drawing tools stay hidden.
 *   - Another app (Jobs, Estimates) can open the shared viewer on its own list of photos (ZMLMedia.openItems);
 *     an estimate's photos count as photos and can carry drawings like any photo.
 *   - A count of this person's own photos for the home screen (ZML_My_Counts).
 *
 * @package Zorderz
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'ZML_VERSION', '2.14.0' );
define( 'ZML_FILE', __FILE__ );
define( 'ZML_DIR', plugin_dir_path( __FILE__ ) );
define( 'ZML_URL', plugin_dir_url( __FILE__ ) );

// media.my_counts: a home tile's "Photos this week" (counts only).
require_once ZML_DIR . 'inc/class-zml-my-counts.php';
add_filter( 'zdz_register_capabilities', array( 'ZML_My_Counts', 'register' ) );
define( 'ZML_NONCE', 'zml_nonce_v1' );

/* ───────────────────────────────────────────────────────────────────────────
 * 1. Register as Zorderz App(s)
 *
 * We register TWO app surfaces backed by the same plugin:
 *   • 'zdz-media'        — the dashboard WIDGET (inline_widget). Implements
 *                         Widget_App_Interface so the theme pre-renders the
 *                         widget body into .dash-widget-body (where the theme
 *                         already revives inline <script>, per app.js).
 *   • 'zdz-media-all'    — the FULL-SCREEN gallery (ajax_html), opened by the
 *                         widget's "See All" button (Bridge.loadApp) and also
 *                         available as its own dock tile.
 *
 * Keeping them as two registered apps (rather than one app that lies about its
 * bridge_type) means each behaves exactly as the theme expects, and the
 * permission/visibility plumbing (zdz_allowed_apps) treats them independently.
 * ──────────────────────────────────────────────────────────────────────────*/

add_action( 'after_setup_theme', function () {
	if ( ! interface_exists( 'Zorderz\\App_Interface' ) ) return;

	require_once ZML_DIR . 'inc/class-zml-widget-app.php';
	require_once ZML_DIR . 'inc/class-zml-fullscreen-app.php';

	$widget     = new ZML_Widget_App();
	$fullscreen = new ZML_Fullscreen_App();

	add_filter( 'zdz_register_apps', function ( $apps ) use ( $widget, $fullscreen ) {
		$apps[] = $widget;
		$apps[] = $fullscreen;
		return $apps;
	} );
} );

/* ───────────────────────────────────────────────────────────────────────────
 * 1b. One-time allowed-apps seeding (so the surfaces actually appear)
 *
 * App VISIBILITY is gated by each user's `zdz_allowed_apps` meta, not by the
 * config's `roles` key. v2 introduces two NEW app ids ('zdz-media' widget +
 * 'zdz-media-all' full-screen), so without seeding, no existing user would see
 * the dashboard widget and nobody would have the full-screen id.
 *
 * This mirrors the theme's own v2.14.3 `migrate_legacy_allowed_apps` pattern:
 * a single option-flagged pass over existing users that ADDS (never removes)
 * the two ids for roles that should have Media. Admins/owners are skipped —
 * they see all apps by the admin bypass. zdz_general (shared kiosk) is included
 * per product decision: it sees its own captures + organization-public media,
 * which the scope queries already enforce.
 *
 * New users created after this point get the ids via the theme's
 * set_default_apps() once the role defaults include them (a separate, optional
 * theme-side change); until then this migration plus the admin per-user app
 * editor cover assignment. Re-running is safe (idempotent array_unique).
 * ──────────────────────────────────────────────────────────────────────────*/

add_action( 'admin_init', 'zml_seed_allowed_apps' );

function zml_seed_allowed_apps() {
	if ( get_option( 'zml_seeded_apps_v2', false ) ) return;

	// Roles that should see Media. Admin/owner/admins see everything anyway,
	// so we only need to seed the non-admin roles' explicit allow-lists.
	$roles_to_seed = [ 'zdz_sales', 'zdz_operator', 'zdz_mfg', 'zdz_tech', 'zdz_general' ];
	$add_ids       = [ 'zdz-media', 'zdz-media-all' ];

	$users = get_users( [
		'role__in' => $roles_to_seed,
		'fields'   => 'ID',
	] );

	foreach ( $users as $uid ) {
		$allowed = get_user_meta( $uid, 'zdz_allowed_apps', true );
		if ( ! is_array( $allowed ) ) {
			// Don't fabricate a list for users who have none set — leaving it
			// unset means "use role defaults", and overriding could unexpectedly
			// narrow their apps. Skip; the admin editor / a future role-default
			// update will cover them.
			continue;
		}
		// Migrate the legacy single id too: if a user had old 'zdz-media' that's
		// fine — it now maps to the widget. Just ensure both ids are present.
		$merged = array_values( array_unique( array_merge( $allowed, $add_ids ) ) );
		if ( count( $merged ) !== count( $allowed ) ) {
			update_user_meta( $uid, 'zdz_allowed_apps', $merged );
		}
	}

	update_option( 'zml_seeded_apps_v2', true );
}

/* ───────────────────────────────────────────────────────────────────────────
 * 2. Global front-end assets (the shared controller)
 *
 * Enqueued on the SPA front page for any logged-in user. This is what makes the
 * "Both" architecture robust: the controller is parsed and available before any
 * app opens, so neither surface relies on innerHTML-injected <script> executing.
 *
 * We piggy-back on the theme's own front-end stack: depend on 'zdz-app-js' (the
 * SPA core) so window.Bridge / zdzData exist, and load after it.
 * ──────────────────────────────────────────────────────────────────────────*/

add_action( 'wp_enqueue_scripts', function () {
	if ( ! is_user_logged_in() ) return;

	// Gate on the theme's SPA core actually being present, rather than guessing
	// the page condition. The theme enqueues 'zdz-app-js' wherever the SPA shell
	// (dashboard + Bridge + #app-body) renders; if it isn't enqueued, our
	// dependency wouldn't resolve and there's no surface to mount into anyway.
	// This is more robust than is_front_page() (which depends on the WP
	// "front page displays" setting) and automatically tracks the theme.
	if ( ! wp_script_is( 'zdz-app-js', 'enqueued' ) && ! wp_script_is( 'zdz-app-js', 'registered' ) ) {
		return;
	}

	wp_enqueue_style(
		'zml-css',
		ZML_URL . 'assets/css/media.css',
		[ 'zdz-app-css' ],          // load after the theme's tokens/base
		ZML_VERSION
	);

	// exifr (vendored, no runtime CDN): reads EXIF — including HEIC/HEIF, which
	// PHP's exif_read_data() cannot — in the browser, so bulk-uploaded photos
	// keep their capture time + GPS provenance. Mirrors zdz-camera's approach;
	// vendored locally so the Media app has no cross-plugin load dependency.
	wp_enqueue_script( 'zml-exifr', ZML_URL . 'assets/js/vendor/exifr.umd.js', [], '7.1.3', true );

	// the app runs on the theme's kit of parts (ZDZ.ui), so load
	// after it. Only when the theme registers it: an older theme would otherwise drop media.js
	// altogether (a missing dependency), and then nothing could say what is wrong.
	$zml_deps = [ 'zdz-app-js', 'zml-exifr' ];  // window.Bridge / zdzData + exifr available
	if ( wp_script_is( 'zdz-kit-js', 'registered' ) || wp_script_is( 'zdz-kit-js', 'enqueued' ) ) {
		$zml_deps[] = 'zdz-kit-js';
	}
	wp_enqueue_script(
		'zml-js',
		ZML_URL . 'assets/js/media.js',
		$zml_deps,
		ZML_VERSION,
		true                       // in footer, after the SPA core
	);

	wp_localize_script( 'zml-js', 'zmlApp', [
		'ajaxurl' => admin_url( 'admin-ajax.php' ),
		'nonce'   => wp_create_nonce( ZML_NONCE ),
		'version' => ZML_VERSION,
		'user_id' => get_current_user_id(),
		// is the viewer an admin (WP administrator / zdz_owner / zdz_admin)?
		// Drives whether the admin-only "All" scope tab is shown. The server
		// re-checks on every zml_list request regardless — this flag is only a
		// UI convenience, never the authorization.
		'is_admin' => zml_current_user_is_admin(),
		// App ids the controller uses to drive the theme Bridge.
		'widgetAppId'     => 'zdz-media',
		'fullscreenAppId' => 'zdz-media-all',
		'pageSize'        => 40,
		// Bulk upload: server's effective per-file limit (bytes) so the client can
		// reject oversize files up front with a clear message, and the cap on how
		// many files are uploaded concurrently (keeps weak uplinks healthy).
		'maxUploadBytes'  => (int) wp_max_upload_size(),
		'uploadConcurrency' => 3,
	] );
}, 20 );

/* ───────────────────────────────────────────────────────────────────────────
 * 3. AJAX endpoints
 * ──────────────────────────────────────────────────────────────────────────*/

add_action( 'init', function () {
	// media.js posts zml_* actions; each logged-in action has a signed-out denial.
	add_action( 'wp_ajax_zml_list', 'zml_ajax_list' );
	add_action( 'wp_ajax_nopriv_zml_list', 'zml_deny_nopriv' );
	add_action( 'wp_ajax_zml_save_note', 'zml_ajax_save_note' );
	add_action( 'wp_ajax_nopriv_zml_save_note', 'zml_deny_nopriv' );
	add_action( 'wp_ajax_zml_save_visibility', 'zml_ajax_save_visibility' );
	add_action( 'wp_ajax_nopriv_zml_save_visibility', 'zml_deny_nopriv' );
	add_action( 'wp_ajax_zml_upload', 'zml_ajax_upload' );
	add_action( 'wp_ajax_nopriv_zml_upload', 'zml_deny_nopriv' );
	add_action( 'wp_ajax_zml_delete', 'zml_ajax_delete' );
	add_action( 'wp_ajax_nopriv_zml_delete', 'zml_deny_nopriv' );
	add_action( 'wp_ajax_zml_rotate', 'zml_ajax_rotate' );   // rotate a photo 90° in place
	add_action( 'wp_ajax_nopriv_zml_rotate', 'zml_deny_nopriv' );
	add_action( 'wp_ajax_zml_update_tags', 'zml_ajax_update_tags' );   // keyword tagging
	add_action( 'wp_ajax_nopriv_zml_update_tags', 'zml_deny_nopriv' );
	add_action( 'wp_ajax_zml_share', 'zml_ajax_share' );   // share to a teammate via DM
	add_action( 'wp_ajax_nopriv_zml_share', 'zml_deny_nopriv' );
	add_action( 'wp_ajax_zml_unshare', 'zml_ajax_unshare' );   // stop sharing
	add_action( 'wp_ajax_nopriv_zml_unshare', 'zml_deny_nopriv' );
	add_action( 'wp_ajax_zml_recipients', 'zml_ajax_recipients' );   // teammate picker
	add_action( 'wp_ajax_nopriv_zml_recipients', 'zml_deny_nopriv' );
	add_action( 'wp_ajax_zml_set_owner', 'zml_ajax_set_owner' );   // an administrator moves a photo to its owner
	add_action( 'wp_ajax_nopriv_zml_set_owner', 'zml_deny_nopriv' );
	add_action( 'wp_ajax_zml_owner_people', 'zml_ajax_owner_people' );   // who it can be moved to (administrators)
	add_action( 'wp_ajax_nopriv_zml_owner_people', 'zml_deny_nopriv' );
	// Legacy aliases (pre-rename names), kept for pages cached by an older build.
	add_action( 'wp_ajax_tsml_list', 'zml_ajax_list' );
	add_action( 'wp_ajax_nopriv_tsml_list', 'zml_deny_nopriv' );
	add_action( 'wp_ajax_tsml_save_note', 'zml_ajax_save_note' );
	add_action( 'wp_ajax_nopriv_tsml_save_note', 'zml_deny_nopriv' );
	add_action( 'wp_ajax_tsml_save_visibility', 'zml_ajax_save_visibility' );
	add_action( 'wp_ajax_nopriv_tsml_save_visibility', 'zml_deny_nopriv' );
	add_action( 'wp_ajax_tsml_upload', 'zml_ajax_upload' );
	add_action( 'wp_ajax_nopriv_tsml_upload', 'zml_deny_nopriv' );
	add_action( 'wp_ajax_tsml_delete', 'zml_ajax_delete' );
	add_action( 'wp_ajax_nopriv_tsml_delete', 'zml_deny_nopriv' );
} );

function zml_deny_nopriv() {
	wp_send_json_error( 'Authentication required.', 403 );
}

/**
 * Is the current user an admin for Media purposes?
 *
 * WP administrators (manage_options) plus the theme's admin-level roles
 * (zdz_owner / zdz_admin) — the same definition the rest of the platform uses
 * (ZDZ_User_Roles::is_admin_role). Admins may delete ANY photo; everyone else
 * only their own. Used for the per-item can_delete flag and re-checked by the
 * delete endpoint on every request.
 */
function zml_current_user_is_admin(): bool {
	if ( current_user_can( 'manage_options' ) ) return true;
	if ( class_exists( 'ZDZ_User_Roles' ) ) {
		$u = wp_get_current_user();
		if ( $u && $u->exists() && ZDZ_User_Roles::is_admin_role( $u->roles[0] ?? '' ) ) return true;
	}
	return false;
}

/**
 * Shape a raw ZDZ_User_Media row into the safe payload the front end uses.
 * Only fields appropriate to expose are included. is_owner drives whether the
 * client shows the note editor and the visibility toggle (the server re-checks
 * ownership on every write regardless).
 */
function zml_shape_item( array $m, int $viewer_id ): array {
	$is_owner = ( (int) ( $m['user_id'] ?? 0 ) === $viewer_id );
	$vmeta    = zml_extract_video_meta( $m );   // v2.8.0
	// who uploaded it (kept by the theme when an administrator moves a photo; the owner otherwise).
	$owner    = (int) ( $m['user_id'] ?? 0 );
	$uploader = $owner;
	if ( class_exists( 'ZDZ_User_Media' ) && method_exists( 'ZDZ_User_Media', 'provenance' ) ) {
		$prov     = ZDZ_User_Media::provenance( $m );
		$uploader = (int) ( $prov['uploaded_by'] ?? $owner ) ?: $owner;
	}
	$can_move = zml_can_move_owner( $viewer_id );
	return [
		'id'            => (int) $m['id'],
		'title'         => (string) ( $m['title'] ?? '' ),
		'note'          => (string) ( $m['description'] ?? '' ),
		'media_type'    => (string) ( $m['media_type'] ?? 'photo' ),
		'source_app'    => (string) ( $m['source_app'] ?? '' ),
		'thumbnail_url' => (string) ( $m['thumbnail_url'] ?: $m['file_url'] ),
		// the 768-pixel thumb for high-density screens
		//.
		'thumbnail_2x_url' => (string) ( $m['thumbnail_2x_url'] ?? '' ),
		'file_url'      => (string) $m['file_url'],
		'privacy'       => (string) ( $m['privacy'] ?? 'private' ),
		'created_at'    => (string) ( $m['created_at'] ?? '' ),
		'captured_at'   => isset( $m['captured_at'] ) ? (string) $m['captured_at'] : null,
		'gps_lat'       => isset( $m['gps_lat'] ) && $m['gps_lat'] !== null ? (float) $m['gps_lat'] : null,
		'gps_lng'       => isset( $m['gps_lng'] ) && $m['gps_lng'] !== null ? (float) $m['gps_lng'] : null,
		'is_owner'      => $is_owner,
		// who uploaded it, as INITIALS only (never a user id, name or
		// email), for the badge in the tile's corner. '' for the shared kiosk or anyone without initials.
		// the person who UPLOADED it, even after an administrator moved it to someone else.
		'uploader_initials' => zml_uploader_initials( $uploader ),
		// when it now belongs to someone other than who uploaded it, their initials ("Belongs to").
		'owner_initials'    => $uploader !== $owner ? zml_uploader_initials( $owner ) : '',
		// an administrator may move it to its rightful owner (the theme re-checks); the owner's id only for them.
		'can_move'          => $can_move,
		'owner_id'          => $can_move ? $owner : 0,
		// owners may delete their own photos; admins may delete any.
		// Drives the delete affordances; the server re-checks on every delete.
		'can_delete'    => $is_owner || zml_current_user_is_admin(),
		// keyword tags (stored in meta_json.tags). can_tag mirrors
		// can_delete — the owner OR any admin may add/remove tags (the whole
		// point is letting an admin tag a photo a field user forgot to tag).
		'tags'          => zml_extract_tags( $m ),
		'can_tag'       => $is_owner || zml_current_user_is_admin(),
		// may this viewer SHARE this item to a teammate? Owner or admin,
		// and never from a shared-kiosk account. The server re-authorizes
		// on share (ZDZ_Media_Share::create checks ownership per item).
		'can_share'     => ( $is_owner || zml_current_user_is_admin() ) && ! ( class_exists( 'ZDZ_Media_Share' ) && ZDZ_Media_Share::is_kiosk( $viewer_id ) ),
		// may this viewer ROTATE this item 90° in place? Owner or admin,
		// PHOTOS only (a video can't be rotated in place). Server re-authorizes.
		'can_rotate'    => ( $is_owner || zml_current_user_is_admin() ) && ( (string) ( $m['media_type'] ?? 'photo' ) !== 'video' ),
		// whether this viewer may draw on it (the theme decides and re-checks on save).
		// anyone who sees it, never the shared device; each person draws their own drawing.
		// How many drawings and notes THIS viewer sees on it (theme counts them; never the lines - the viewer
		// loads those when the photo opens), for the outline on its tile.
		'marks_n'       => (int) ( $m['marks_n'] ?? 0 ),
		'notes_n'       => (int) ( $m['notes_n'] ?? 0 ),
		'can_mark'      => class_exists( 'ZDZ_User_Media' ) && method_exists( 'ZDZ_User_Media', 'viewer_may_mark' )
			&& class_exists( 'ZDZ_Media_Marks' ) && method_exists( 'ZDZ_Media_Marks', 'counts_for' ) && ZDZ_User_Media::viewer_may_mark( $viewer_id, $m ),
		// video fields (from meta_json) — drive the grid badge + player +
		// back-to-back reel playback. Zero/empty for photos and sketches.
		'is_video'      => ( (string) ( $m['media_type'] ?? 'photo' ) === 'video' ),
		'duration_ms'   => (int) $vmeta['duration_ms'],
		'reel_id'       => (string) $vmeta['reel_id'],
		'seq'           => (int) $vmeta['seq'],
	];
}

/**
 * the uploader's initials for the badge on each photo.
 *
 * A small coloured badge in the lower-right corner of each photo says who uploaded it. Read the way the
 * theme reads them: ZDZ_Person_Color, else the `zdz_user_initials` user meta. Only the initials leave the server (the browser maps
 * them to the person's shape and tone through zdzData.people, which the theme already sends); the shared
 * kiosk gets none, so its photos carry no badge. Memoized per request (a page has up to 40 photos).
 */
function zml_uploader_initials( int $uid ): string {
	static $memo = [];
	if ( $uid <= 0 ) return '';
	if ( isset( $memo[ $uid ] ) ) return $memo[ $uid ];
	$ini = '';
	if ( class_exists( 'ZDZ_Person_Color' ) && method_exists( 'ZDZ_Person_Color', 'for_user' ) ) {
		$p   = ZDZ_Person_Color::for_user( $uid );
		$ini = ( 'kiosk' === ( $p['shape'] ?? '' ) ) ? '' : (string) ( $p['initials'] ?? '' );
	} elseif ( ! ( class_exists( 'ZDZ_Media_Share' ) && ZDZ_Media_Share::is_kiosk( $uid ) ) ) {
		$ini = (string) get_user_meta( $uid, 'zdz_user_initials', true );
	}
	$ini = trim( sanitize_text_field( $ini ) );
	if ( function_exists( 'mb_substr' ) ) { $ini = mb_substr( $ini, 0, 4 ); } else { $ini = substr( $ini, 0, 4 ); }
	return $memo[ $uid ] = $ini;
}

/** v2.8.0 — Pull video fields (duration/reel/seq) off a row's meta_json. */
function zml_extract_video_meta( array $m ): array {
	$out = [ 'duration_ms' => 0, 'reel_id' => '', 'seq' => 0 ];
	$mj = $m['meta_json'] ?? '';
	if ( is_string( $mj ) && $mj !== '' ) {
		$meta = json_decode( $mj, true );
		if ( is_array( $meta ) ) {
			if ( isset( $meta['duration_ms'] ) ) { $out['duration_ms'] = (int) $meta['duration_ms']; }
			if ( isset( $meta['reel_id'] ) )     { $out['reel_id']     = (string) $meta['reel_id']; }
			if ( isset( $meta['seq'] ) )         { $out['seq']         = (int) $meta['seq']; }
		}
	}
	return $out;
}

/**
 * Read the keyword tags off a raw media row's meta_json.tags.
 */
function zml_extract_tags( array $m ): array {
	$mj = $m['meta_json'] ?? '';
	if ( is_string( $mj ) && $mj !== '' ) {
		$meta = json_decode( $mj, true );
		if ( is_array( $meta ) && ! empty( $meta['tags'] ) && is_array( $meta['tags'] ) ) {
			$out = [];
			foreach ( $meta['tags'] as $t ) {
				$t = trim( (string) $t );
				if ( $t !== '' ) { $out[] = $t; }
			}
			return array_values( array_slice( $out, 0, 40 ) );
		}
	}
	return [];
}

/**
 * Normalize a posted tag list (JSON array, or comma/newline string)
 * into a clean, capped, de-duped string[].
 */
function zml_tag_list_from_post( $raw ): array {
	$raw  = wp_unslash( $raw );
	$list = [];
	if ( is_array( $raw ) ) {
		$list = $raw;
	} elseif ( is_string( $raw ) && $raw !== '' ) {
		$maybe = json_decode( $raw, true );
		$list  = is_array( $maybe ) ? $maybe : preg_split( '/[,\n]+/', $raw );
	}
	$out = [];
	foreach ( (array) $list as $t ) {
		$t = trim( sanitize_text_field( (string) $t ) );
		if ( $t !== '' && mb_strlen( $t ) <= 60 ) { $out[] = $t; }
	}
	return array_slice( array_values( array_unique( $out ) ), 0, 20 );
}

/**
 * Add/remove keyword tags on a media item.
 * OWNER-OR-ADMIN (mirrors delete): the whole point is letting an admin tag a
 * photo a field user uploaded and forgot to tag. Provenance (who/when/why) is
 * recorded by ZDZ_User_Media::apply_tags() into meta_json + the admin audit log.
 */
function zml_ajax_update_tags() {
	check_ajax_referer( ZML_NONCE, 'nonce' );
	if ( ! is_user_logged_in() ) {
		wp_send_json_error( 'Not logged in.' );
	}
	if ( ! class_exists( 'ZDZ_User_Media' ) || ! method_exists( 'ZDZ_User_Media', 'apply_tags' ) ) {
		wp_send_json_error( 'Tagging isn’t available yet — please update the Zorderz theme.' );
	}

	$add    = zml_tag_list_from_post( $_POST['add'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- zml_tag_list_from_post() unslashes and runs sanitize_text_field() on every tag
	$remove = zml_tag_list_from_post( $_POST['remove'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- zml_tag_list_from_post() unslashes and runs sanitize_text_field() on every tag
	$reason = isset( $_POST['reason'] ) ? sanitize_text_field( wp_unslash( $_POST['reason'] ) ) : '';
	if ( empty( $add ) && empty( $remove ) ) {
		wp_send_json_error( 'Nothing to change.' );
	}

	$uid      = get_current_user_id();
	$is_admin = zml_current_user_is_admin();

	// BATCH mode: media_ids[] tags many photos at once (bulk-tag from the
	// selection toolbar). Per-item owner-or-admin, mirroring bulk delete; partial
	// failures are reported, never silently dropped.
	$ids_raw = isset( $_POST['media_ids'] ) ? sanitize_text_field( wp_unslash( $_POST['media_ids'] ) ) : '';
	if ( $ids_raw !== '' && $ids_raw !== '[]' ) {
		$ids = json_decode( $ids_raw, true );
		if ( ! is_array( $ids ) ) {
			wp_send_json_error( 'Invalid selection.' );
		}
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		if ( empty( $ids ) ) {
			wp_send_json_error( 'No photos selected.' );
		}
		if ( count( $ids ) > 100 ) {
			wp_send_json_error( 'Too many photos in one request (max 100).' );
		}

		$tagged = [];
		$failed = [];
		foreach ( $ids as $mid ) {
			$row = ZDZ_User_Media::get_by_id( $mid );
			if ( ! $row ) {
				$failed[] = [ 'id' => $mid, 'reason' => 'not found' ];
				continue;
			}
			if ( (int) ( $row['user_id'] ?? 0 ) !== $uid && ! $is_admin ) {
				$failed[] = [ 'id' => $mid, 'reason' => 'not yours' ];
				continue;
			}
			$r = ZDZ_User_Media::apply_tags( $mid, $add, $remove, $uid, $reason );
			if ( null === $r ) {
				$failed[] = [ 'id' => $mid, 'reason' => 'save failed' ];
				continue;
			}
			$tagged[] = [ 'id' => $mid, 'tags' => $r['tags'] ];
		}
		wp_send_json_success( [ 'tagged' => $tagged, 'failed' => $failed, 'count' => count( $tagged ) ] );
	}

	// Single-item mode.
	$id = (int) ( $_POST['media_id'] ?? 0 );
	if ( $id <= 0 ) {
		wp_send_json_error( 'Invalid item.' );
	}

	$row = ZDZ_User_Media::get_by_id( $id );
	if ( ! $row ) {
		wp_send_json_error( 'Not found.' );
	}
	// Owner OR admin — server-enforced (the client's can_tag flag is only a hint).
	if ( (int) ( $row['user_id'] ?? 0 ) !== $uid && ! $is_admin ) {
		wp_send_json_error( 'You don’t have permission to tag this photo.', 403 );
	}

	$res = ZDZ_User_Media::apply_tags( $id, $add, $remove, $uid, $reason );
	if ( null === $res ) {
		wp_send_json_error( 'Could not save tags. Please try again.' );
	}

	wp_send_json_success( [
		'media_id' => $id,
		'tags'     => $res['tags'],
		'added'    => $res['added'],
		'removed'  => $res['removed'],
	] );
}

/**
 * List media for a scope.
 *   scope=public → org-wide media with privacy = 'public' only.
 *   scope=mine   → the current user's own uploads (any privacy).
 *   scope=all    → EVERY photo org-wide, any owner, any privacy. ADMIN-ONLY
 *                  (WP administrator / zdz_owner / zdz_admin, via
 *                  zml_current_user_is_admin) — the browse surface that lets an
 *                  admin DISCOVER photos stored 'private' (which the serve()
 *                  layer already lets admins OPEN, but which never appear in the
 *                  'public'/'mine' lists). A non-admin asking for scope=all is
 *                  silently downgraded to 'public' — the server NEVER trusts the
 *                  client to have hidden the tab.
 * Optional media_type filter. Paginated via offset (page size 40).
 *
 * limit accepts an override (used by the dashboard widget to request a small
 * recent slice, e.g. 6) but is hard-clamped 1..40 so it can't be abused.
 */
function zml_ajax_list() {
	check_ajax_referer( ZML_NONCE, 'nonce' );
	if ( ! is_user_logged_in() ) wp_send_json_error( 'Not logged in.' );
	if ( ! class_exists( 'ZDZ_User_Media' ) ) { wp_send_json_success( [ 'items' => [], 'has_more' => false, 'is_admin' => false ] ); return; }

	$viewer   = get_current_user_id();
	$is_admin = zml_current_user_is_admin();
	$scope    = sanitize_key( $_POST['scope'] ?? 'public' );
	$type     = sanitize_key( $_POST['media_type'] ?? '' );
	$offset   = max( 0, (int) ( $_POST['offset'] ?? 0 ) );
	$limit    = (int) ( $_POST['limit'] ?? 40 );
	$limit    = max( 1, min( 40, $limit ) );   // clamp 1..40

	// The admin-only "All" scope is authorized SERVER-SIDE. A non-admin who
	// somehow posts scope=all (crafted request, stale client) is downgraded to
	// the public org-wide view — the tab being hidden in the UI is a
	// convenience, never the gate.
	if ( $scope === 'all' && ! $is_admin ) {
		$scope = 'public';
	}

	$filters = [ 'limit' => $limit, 'offset' => $offset ];
	if ( $type !== '' ) $filters['media_type'] = $type;

	if ( $scope === 'mine' ) {
		$rows = ZDZ_User_Media::get_user_media( $viewer, $filters );
	} elseif ( $scope === 'shared' ) {
		// "Shared With Me": items another teammate granted to this viewer.
		// Read-only here; the grants + gating live in the theme (ZDZ_Media_Share).
		$rows = method_exists( 'ZDZ_User_Media', 'get_shared_with_me' )
			? ZDZ_User_Media::get_shared_with_me( $viewer, $filters )
			: [];
	} elseif ( $scope === 'all' ) {
		// Admin browse-all: every row regardless of owner/privacy. Requires the
		// theme getter; on an older theme, fail safe by falling
		// back to the public view rather than exposing nothing or erroring.
		if ( method_exists( 'ZDZ_User_Media', 'get_all_media' ) ) {
			$rows = ZDZ_User_Media::get_all_media( $filters );
		} else {
			$scope = 'public';
			$rows  = array_values( array_filter(
				ZDZ_User_Media::get_team_media( $filters ),
				function ( $r ) { return ( $r['privacy'] ?? '' ) === 'public'; }
			) );
		}
	} else {
		// get_team_media returns privacy IN ('team','public'); the Media app
		// only surfaces fully PUBLIC items org-wide, so filter to 'public'.
		$rows = array_values( array_filter(
			ZDZ_User_Media::get_team_media( $filters ),
			function ( $r ) { return ( $r['privacy'] ?? '' ) === 'public'; }
		) );
	}

	$items = array_map( function ( $r ) use ( $viewer, $scope ) {
		$it = zml_shape_item( $r, $viewer );
		if ( $scope === 'shared' ) {
			// A recipient is not the owner: view-only (no delete, tag, or re-share).
			$it['is_shared']      = true;
			$it['shared_by_name'] = (string) ( $r['shared_by_name'] ?? '' );
			$it['shared_at']      = (string) ( $r['shared_at'] ?? '' );
			$it['share_group']    = (string) ( $r['share_group'] ?? '' );
			$it['can_delete']     = false;
			$it['can_tag']        = false;
			$it['can_share']      = false;
		}
		return $it;
	}, $rows );

	wp_send_json_success( [
		'items'    => $items,
		// A full page back implies there may be more. (Public scope filters
		// post-query, so this is a best-effort hint, which is fine for v1.)
		'has_more' => count( $rows ) >= $limit,
		'offset'   => $offset + count( $rows ),
		// Echo the scope actually served (may differ from the request if a
		// non-admin/older-theme was downgraded) and whether the viewer is an
		// admin, so the client can show/hide the "All" tab correctly.
		'scope'    => $scope,
		'is_admin' => $is_admin,
	] );
}

/**
 * Save (transcribe / edit) a note on a media item.
 * OWNER-ONLY: only the user who uploaded the item may edit its note.
 * The note is stored in ZDZ_User_Media's `description` field.
 */
function zml_ajax_save_note() {
	check_ajax_referer( ZML_NONCE, 'nonce' );
	if ( ! is_user_logged_in() ) wp_send_json_error( 'Not logged in.' );
	if ( ! class_exists( 'ZDZ_User_Media' ) ) wp_send_json_error( 'Unavailable.' );

	$id   = (int) ( $_POST['media_id'] ?? 0 );
	$note = isset( $_POST['note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['note'] ) ) : '';

	if ( $id <= 0 ) wp_send_json_error( 'Invalid item.' );

	// Practical length cap so a "note" stays a note.
	if ( mb_strlen( $note ) > 2000 ) {
		$note = mb_substr( $note, 0, 2000 );
	}

	$row = ZDZ_User_Media::get_by_id( $id );
	if ( ! $row ) wp_send_json_error( 'Not found.' );

	// Owner-only edit — server-enforced.
	if ( (int) ( $row['user_id'] ?? 0 ) !== get_current_user_id() ) {
		wp_send_json_error( 'You can only edit notes on your own media.', 403 );
	}

	$ok = ZDZ_User_Media::update( $id, [ 'description' => $note ] );
	if ( ! $ok ) wp_send_json_error( 'Could not save note. Please try again.' );

	wp_send_json_success( [ 'media_id' => $id, 'note' => $note ] );
}

/**
 * Change a photo's visibility.
 *   visible=everyone → privacy = 'public'  ("Visible to Everyone")
 *   visible=me       → privacy = 'private' ("Visible only to You")
 * OWNER-ONLY, server-enforced. We deliberately do NOT expose 'team' here — the
 * Media app's mental model is a binary Everyone/You toggle, matching the UI.
 * EXIF provenance is untouched (update() whitelists privacy only).
 */
function zml_ajax_save_visibility() {
	check_ajax_referer( ZML_NONCE, 'nonce' );
	if ( ! is_user_logged_in() ) wp_send_json_error( 'Not logged in.' );
	if ( ! class_exists( 'ZDZ_User_Media' ) ) wp_send_json_error( 'Unavailable.' );

	$id      = (int) ( $_POST['media_id'] ?? 0 );
	$visible = sanitize_key( $_POST['visible'] ?? '' ); // 'everyone' | 'me'

	if ( $id <= 0 ) wp_send_json_error( 'Invalid item.' );
	if ( ! in_array( $visible, [ 'everyone', 'me' ], true ) ) {
		wp_send_json_error( 'Invalid visibility.' );
	}

	$row = ZDZ_User_Media::get_by_id( $id );
	if ( ! $row ) wp_send_json_error( 'Not found.' );

	// Owner-only — server-enforced.
	if ( (int) ( $row['user_id'] ?? 0 ) !== get_current_user_id() ) {
		wp_send_json_error( 'You can only change visibility on your own media.', 403 );
	}

	$privacy = ( $visible === 'everyone' ) ? 'public' : 'private';
	$ok = ZDZ_User_Media::update( $id, [ 'privacy' => $privacy ] );
	if ( ! $ok ) wp_send_json_error( 'Could not update visibility. Please try again.' );

	wp_send_json_success( [
		'media_id' => $id,
		'privacy'  => $privacy,
		'visible'  => $visible,
	] );
}

/**
 * Bulk photo upload — store ONE photo per request into ZDZ_User_Media.
 *
 * The front end lets the user pick many photos at once and (optionally) type a
 * single note for the whole batch. To keep each request small and resilient on
 * weak shop uplinks — and to mirror the camera's proven one-file-per-POST path —
 * the controller fans the selection out into one zml_upload call per file,
 * uploaded with limited concurrency. Each call is fully self-contained.
 *
 * METADATA PRESERVATION (parity with zdz-camera, requirement #1):
 *   We store the ORIGINAL uploaded file as the full-size attachment via
 *   media_handle_upload(), exactly as zdz-camera does. ZDZ_User_Media::save()
 *   then reads EXIF (capture time + GPS + the full raw block) server-side from
 *   get_attached_file() — which is that untouched original — so JPEG/TIFF
 *   provenance survives end-to-end and the complete EXIF block is preserved in
 *   meta_json. For HEIC/HEIF (PHP's exif_read_data() can't read it) the client
 *   reads EXIF in-browser via the vendored exifr and sends captured_at / gps_*
 *   / geo_source / time_source alongside the file; save() prefers those when
 *   present and still logs whatever raw EXIF it can read. No re-encode, no strip.
 *
 * BATCH NOTE + FUTURE JOB/CUSTOMER ASSOCIATION (requirements #2 / forward-looking):
 *   A single batch note is applied to every photo in the batch in TWO places,
 *   intentionally:
 *     1. description — the same owner-editable field the camera writes and the
 *        Media lightbox already shows/edits, so the note is immediately VISIBLE
 *        and per-photo editable after upload (user reference).
 *     2. meta_json.batch — a structured, batch-level record { id, note, seq,
 *        count, uploaded_at } shared by every photo in the batch (our metadata
 *        tagging). The batch id groups a single upload, and the `batch` envelope
 *        is deliberately open so a future "job"/"customer" key can be added here
 *        (e.g. batch.job_id) WITHOUT touching the storage schema or this
 *        contract — the association would just be more fields on the same
 *        envelope. Nothing here assumes a job model exists yet.
 *
 * Owner + privacy: items are saved for the current user at privacy='private'
 * (ZDZ_User_Media's default — "Visible only to You"); the user can flip any photo
 * to "Everyone" afterward via the existing visibility toggle.
 */
function zml_ajax_upload() {
	global $wpdb;

	check_ajax_referer( ZML_NONCE, 'nonce' );
	if ( ! is_user_logged_in() )            wp_send_json_error( 'Not logged in.' );
	if ( ! class_exists( 'ZDZ_User_Media' ) ) wp_send_json_error( 'Photo storage is unavailable. Please try again.' );

	if ( empty( $_FILES['photo'] ) || ! isset( $_FILES['photo']['name'] ) ) {
		wp_send_json_error( 'No photo received. Please try again.' );
	}

	// Only accept images. media_handle_upload() also enforces allowed MIME types
	// (incl. the theme's HEIC/HEIF allowance), but we reject obvious non-images
	// early with a clear, per-file message the UI can surface.
	$ftype = wp_check_filetype( $_FILES['photo']['name'] );
	if ( empty( $ftype['type'] ) || strpos( (string) $ftype['type'], 'image/' ) !== 0 ) {
		wp_send_json_error( 'That file isn’t an image. Only photos can be added here.' );
	}

	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';

	// Store the ORIGINAL as the full-size attachment (EXIF-preserving — see docblock).
	$att_id = media_handle_upload( 'photo', 0, [], [ 'test_form' => false ] );
	if ( is_wp_error( $att_id ) ) {
		error_log( 'ZML upload: ' . $att_id->get_error_message() );
		wp_send_json_error( 'Upload failed. Please try again.' );
	}

	$url   = wp_get_attachment_url( $att_id );
	$thumb = wp_get_attachment_image_url( $att_id, 'medium' );

	// ── Batch identity (shared across every file in one upload) ───────────
	// The client generates a stable id once per "Add Photos" action and sends it
	// with every file, so all photos from one selection share a batch handle.
	$batch_id = isset( $_POST['batch_id'] ) ? sanitize_text_field( wp_unslash( $_POST['batch_id'] ) ) : '';
	if ( $batch_id === '' ) {
		$batch_id = 'batch_' . wp_generate_uuid4();
	}
	$batch_seq   = max( 0, (int) ( $_POST['batch_seq'] ?? 0 ) );    // 0-based index in the batch
	$batch_count = max( 0, (int) ( $_POST['batch_count'] ?? 0 ) );  // total files in the batch

	// ── Batch note ────────────────────────────────────────────────────────
	// One note for the whole batch. Capped to match the Media app's note cap.
	$note = isset( $_POST['note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['note'] ) ) : '';
	if ( mb_strlen( $note ) > 2000 ) {
		$note = mb_substr( $note, 0, 2000 );
	}

	// ── Client-supplied provenance (HEIC safety net — parity with zdz-camera) ─
	$captured_at = isset( $_POST['captured_at'] ) ? sanitize_text_field( wp_unslash( $_POST['captured_at'] ) ) : '';
	$gps_lat     = isset( $_POST['gps_lat'] ) && $_POST['gps_lat'] !== '' ? $_POST['gps_lat'] : '';
	$gps_lng     = isset( $_POST['gps_lng'] ) && $_POST['gps_lng'] !== '' ? $_POST['gps_lng'] : '';
	$geo_source  = isset( $_POST['geo_source'] ) ? sanitize_key( $_POST['geo_source'] ) : '';
	$time_source = isset( $_POST['time_source'] ) ? sanitize_key( $_POST['time_source'] ) : '';

	// Auto-title: prefer the original filename (meaningful for picked photos),
	// fall back to a timestamped label.
	$orig_name = sanitize_file_name( $_FILES['photo']['name'] );
	$title     = $orig_name !== '' ? $orig_name : ( 'Photo — ' . date( 'M j, g:i A' ) );

	// Batch-level envelope stored on EVERY photo of the batch. Deliberately
	// open-ended so a future job/customer link is an additive field here.
	$batch_meta = [
		'id'          => $batch_id,
		'note'        => $note,
		'seq'         => $batch_seq,
		'count'       => $batch_count,
		'uploaded_at' => current_time( 'mysql' ),
		// FUTURE: 'job_id' / 'customer_id' would be added here once that model
		// exists — no schema or contract change required.
	];

	// Privacy: intentionally NOT set, so ZDZ_User_Media::save() applies its
	// 'private' default. 'private' already means "the UPLOADER and any ADMIN can
	// view it" (the theme serve() gate: private → owner + admin-tier), so a
	// bulk-uploaded photo defaults to owner-visible-in-lists + admin-viewable;
	// admins DISCOVER it via the new admin-only "All" scope. The user
	// can still flip any photo to "Everyone" (public) afterward via the
	// visibility toggle. Do NOT default to 'public' — that exposes it org-wide.
	$save_args = [
		'user_id'          => get_current_user_id(),
		'file_url'         => $url,
		'thumbnail_url'    => $thumb ?: $url,
		'filename'         => $_FILES['photo']['name'],
		'media_type'       => 'photo',
		'source_app'       => 'zdz-media',
		'source_ref'       => 'bulk:' . $batch_id . ':' . $batch_seq,
		'title'            => $title,
		'description'      => $note,   // visible + per-photo editable, like a camera note
		'wp_attachment_id' => $att_id,
		'captured_at'      => $captured_at,
		'gps_lat'          => $gps_lat,
		'gps_lng'          => $gps_lng,
		'meta'             => [ 'batch' => $batch_meta ],
	];
	if ( $geo_source !== '' )  { $save_args['meta']['geo_source']  = $geo_source; }
	if ( $time_source !== '' ) { $save_args['meta']['time_source'] = $time_source; }

	$media = ZDZ_User_Media::save( $save_args );
	if ( ! $media ) {
		error_log( 'ZML upload: metadata save failed. db_error:' . $wpdb->last_error );
		wp_send_json_error( 'Could not save photo details. Please try again.' );
	}

	// Return the shaped item so the controller can insert it straight into the
	// grid (same shape zml_list emits), plus the batch id for client tracking.
	wp_send_json_success( [
		'item'     => zml_shape_item( $media, get_current_user_id() ),
		'batch_id' => $batch_id,
	] );
}

/**
 * Permanently delete media items — single or bulk.
 *
 * AUTHORIZATION (server-enforced PER ID, regardless of what the UI showed):
 *   • The item's OWNER may delete it (users manage their own uploads/captures).
 *   • ADMINS (zml_current_user_is_admin: WP administrator / zdz_owner /
 *     zdz_admin) may delete ANY item.
 *   Anything else lands in `failed` with a human-readable reason.
 *
 * The client asks for confirmation TWICE before calling this (product
 * decision) because deletion is genuinely unrecoverable: ZDZ_User_Media::delete()
 * removes the row AND the underlying WP attachment file.
 *
 * Accepts media_ids as a JSON array (preferred) or a comma list; hard-capped at
 * 100 ids per call. Returns { deleted:[ids], failed:[{id,reason}] } so partial
 * failures surface honestly — the UI removes exactly the `deleted` ids from the
 * grid and reports the rest.
 */
function zml_ajax_delete() {
	check_ajax_referer( ZML_NONCE, 'nonce' );
	if ( ! is_user_logged_in() )             wp_send_json_error( 'Not logged in.' );
	if ( ! class_exists( 'ZDZ_User_Media' ) ) wp_send_json_error( 'Unavailable.' );

	$raw = isset( $_POST['media_ids'] ) ? trim( (string) wp_unslash( $_POST['media_ids'] ) ) : '';
	$ids = [];
	if ( $raw !== '' ) {
		$decoded = json_decode( $raw, true );
		$ids = is_array( $decoded ) ? $decoded : explode( ',', $raw );
	}
	$ids = array_values( array_unique( array_filter(
		array_map( 'intval', $ids ),
		function ( $v ) { return $v > 0; }
	) ) );

	if ( empty( $ids ) )       wp_send_json_error( 'No items to delete.' );
	if ( count( $ids ) > 100 ) wp_send_json_error( 'Too many items in one request (max 100).' );

	$viewer   = get_current_user_id();
	$is_admin = zml_current_user_is_admin();

	$deleted = [];
	$failed  = [];

	foreach ( $ids as $id ) {
		$row = ZDZ_User_Media::get_by_id( $id );
		if ( ! $row ) {
			$failed[] = [ 'id' => $id, 'reason' => 'Not found.' ];
			continue;
		}
		$owner_id = (int) ( $row['user_id'] ?? 0 );

		// Owner-or-admin — the ONLY rule. (Server-side; the UI's can_delete is
		// a convenience, never trusted.)
		if ( $owner_id !== $viewer && ! $is_admin ) {
			$failed[] = [ 'id' => $id, 'reason' => 'You can only delete your own photos.' ];
			continue;
		}

		// Authorization happened above. We pass the ROW owner's id so the
		// store's own owner-or-manage_options check passes for admin deletes
		// too — correct even if a custom admin role's cap set ever drifts.
		// true = also delete the WP attachment file.
		if ( ZDZ_User_Media::delete( $id, $owner_id, true ) ) {
			$deleted[] = $id;
		} else {
			$failed[] = [ 'id' => $id, 'reason' => 'Delete failed. Please try again.' ];
		}
	}

	wp_send_json_success( [
		'deleted' => $deleted,
		'failed'  => $failed,
	] );
}

/**
 * Rotate ONE photo 90° at a time, IN PLACE. Owner or admin only; photos
 * only. The pixel work + thumbnail regeneration live in the theme
 * (ZDZ_User_Media::rotate_photo, the one media authority), which re-authorizes and
 * bumps the row's updated_at so the gated URL's cache-buster refreshes the image
 * immediately. Returns the fresh URLs so the client can swap the <img> at once.
 */
function zml_ajax_rotate() {
	check_ajax_referer( ZML_NONCE, 'nonce' );
	if ( ! is_user_logged_in() )             wp_send_json_error( 'Not logged in.' );
	if ( ! class_exists( 'ZDZ_User_Media' ) ) wp_send_json_error( 'Unavailable.' );
	if ( ! method_exists( 'ZDZ_User_Media', 'rotate_photo' ) ) wp_send_json_error( 'Rotate needs the theme updated first.' );

	$id = isset( $_POST['media_id'] ) ? absint( $_POST['media_id'] ) : 0;
	$cw = isset( $_POST['cw'] ) ? absint( $_POST['cw'] ) : 0;
	if ( $id <= 0 )                                        wp_send_json_error( 'No item.' );
	if ( ! in_array( $cw, [ 90, 180, 270 ], true ) )       wp_send_json_error( 'Rotation must be 90, 180, or 270.' );

	$row = ZDZ_User_Media::get_by_id( $id );
	if ( ! $row ) wp_send_json_error( 'Not found.' );

	$viewer = get_current_user_id();
	// Owner-or-admin — the same rule as delete. (Server-side; the UI's can_rotate
	// flag is a convenience, never trusted. The theme re-authorizes as well.)
	if ( (int) ( $row['user_id'] ?? 0 ) !== $viewer && ! zml_current_user_is_admin() ) {
		wp_send_json_error( 'You can only rotate your own photos.' );
	}
	if ( (string) ( $row['media_type'] ?? 'photo' ) === 'video' ) {
		wp_send_json_error( 'Only photos can be rotated.' );
	}

	$res = ZDZ_User_Media::rotate_photo( $id, $viewer, $cw );
	if ( ! $res ) wp_send_json_error( 'Rotate failed. Please try again.' );

	wp_send_json_success( is_array( $res ) ? $res : [] );
}

/* ───────────────────────────────────────────────────────────────────────────
 * Share media to a teammate via DM
 *
 * The Media app is a thin caller here: it validates the nonce + login + a light
 * rate limit, then hands off to the DM plugin's published bridge, which records
 * the per-recipient grants in the theme (ZDZ_Media_Share::create authorizes the
 * sender against every media id) and posts the gallery card. Authorization is
 * NOT done here — it lives in the theme, the one media authority.
 * ──────────────────────────────────────────────────────────────────────────*/

/** Share one or more media items to a teammate via DM. */
function zml_ajax_share() {
	check_ajax_referer( ZML_NONCE, 'nonce' );
	if ( ! is_user_logged_in() ) { wp_send_json_error( 'Not logged in.', 403 ); }

	$sender = get_current_user_id();

	// Light per-user rate limit: blunt the "share bomb" without getting in
	// a normal user's way. Fail-open if the object cache is unavailable.
	$rk   = 'zml_share_rl_' . $sender;
	$hits = (int) get_transient( $rk );
	if ( $hits >= 20 ) { wp_send_json_error( 'You are sharing too fast — give it a moment.', 429 ); }
	set_transient( $rk, $hits + 1, 60 );

	$recipient = intval( wp_unslash( $_POST['recipient_id'] ?? 0 ) );
	$note      = isset( $_POST['note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['note'] ) ) : '';
	$ids       = array_values( array_filter( array_map( 'absint', (array) ( $_POST['media_ids'] ?? [] ) ) ) );

	if ( $recipient <= 0 || empty( $ids ) ) {
		wp_send_json_error( 'Pick a teammate and at least one item.', 400 );
	}
	if ( count( $ids ) > 100 ) {
		wp_send_json_error( 'You can share up to 100 items at once.', 400 );
	}
	if ( ! class_exists( 'ZIM_Media_Cards' ) ) {
		wp_send_json_error( 'Messaging is unavailable, so sharing to a DM is not possible right now.', 503 );
	}

	$res = ZIM_Media_Cards::post_share_to_dm( $sender, $recipient, $ids, $note );
	if ( is_wp_error( $res ) ) {
		wp_send_json_error( $res->get_error_message(), 400 );
	}
	wp_send_json_success( $res );
}

/** Stop sharing (revoke a whole share group). Sender or admin only (enforced in the theme). */
function zml_ajax_unshare() {
	check_ajax_referer( ZML_NONCE, 'nonce' );
	if ( ! is_user_logged_in() ) { wp_send_json_error( 'Not logged in.', 403 ); }
	if ( ! class_exists( 'ZDZ_Media_Share' ) ) { wp_send_json_error( 'Unavailable.', 503 ); }

	$group = isset( $_POST['group'] ) ? preg_replace( '/[^a-f0-9]/i', '', sanitize_text_field( wp_unslash( $_POST['group'] ) ) ) : '';
	if ( '' === $group ) { wp_send_json_error( 'Nothing to revoke.', 400 ); }

	$ok = ZDZ_Media_Share::revoke( get_current_user_id(), $group );
	if ( ! $ok ) { wp_send_json_error( 'You cannot stop this share.', 403 ); }
	wp_send_json_success( [ 'revoked' => true ] );
}

/**
 * may this person move a photo to someone else? The theme decides
 * (ZDZ_User_Media::actor_may_reassign: administrators only, never the shared kiosk); false on an older theme.
 */
function zml_can_move_owner( int $uid ): bool {
	static $memo = [];
	if ( ! isset( $memo[ $uid ] ) ) {
		$memo[ $uid ] = $uid > 0 && class_exists( 'ZDZ_User_Media' ) && method_exists( 'ZDZ_User_Media', 'actor_may_reassign' )
			&& method_exists( 'ZDZ_User_Media', 'reassign_owner' ) && ZDZ_User_Media::actor_may_reassign( $uid );
	}
	return $memo[ $uid ];
}

/** v2.11.0 - an administrator moves a photo to its rightful owner. The theme keeps who uploaded it and every move. */
function zml_ajax_set_owner() {
	check_ajax_referer( ZML_NONCE, 'nonce' );
	if ( ! is_user_logged_in() ) { wp_send_json_error( 'Not logged in.', 403 ); }
	$actor = get_current_user_id();
	if ( ! class_exists( 'ZDZ_User_Media' ) || ! method_exists( 'ZDZ_User_Media', 'reassign_owner' ) ) {
		wp_send_json_error( 'Moving a photo needs the theme updated first.', 400 );
	}
	if ( ! zml_can_move_owner( $actor ) ) {
		wp_send_json_error( 'Only an administrator can move a photo to someone else.', 403 );
	}
	$id = isset( $_POST['media_id'] ) ? absint( $_POST['media_id'] ) : 0;
	$to = isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0;
	if ( $id <= 0 || $to <= 0 ) { wp_send_json_error( 'Choose a photo and a person.', 400 ); }
	$r = ZDZ_User_Media::reassign_owner( $id, $to, $actor );
	if ( is_wp_error( $r ) ) { wp_send_json_error( $r->get_error_message(), 400 ); }
	$row = ZDZ_User_Media::get_by_id( $id );
	wp_send_json_success( [ 'item' => $row ? zml_shape_item( $row, $actor ) : null, 'moved' => ! empty( $r['moved'] ) ] );
}

/** v2.11.0 - who a photo can be moved to (administrators only): the theme's selectable people, the administrator too. */
function zml_ajax_owner_people() {
	check_ajax_referer( ZML_NONCE, 'nonce' );
	if ( ! is_user_logged_in() ) { wp_send_json_error( 'Not logged in.', 403 ); }
	if ( ! zml_can_move_owner( get_current_user_id() ) ) { wp_send_json_error( 'Only an administrator can move a photo to someone else.', 403 ); }
	$people = class_exists( 'ZDZ_Party' ) ? ZDZ_Party::selectable_people( [ 'include_self' => true ] ) : [];
	wp_send_json_success( [ 'people' => array_values( array_map( function ( $p ) {
		return [ 'id' => (int) $p['id'], 'name' => (string) $p['name'], 'initials' => (string) ( $p['initials'] ?? '' ) ];
	}, (array) $people ) ) ] );
}

/**
 * Teammate picker for the share dialog. Reads the theme's authoritative
 * selectable-people roster (ZDZ_Party), which already excludes the shared-kiosk
 * role. A kiosk account itself gets an empty list (it can't share).
 */
function zml_ajax_recipients() {
	check_ajax_referer( ZML_NONCE, 'nonce' );
	if ( ! is_user_logged_in() ) { wp_send_json_error( 'Not logged in.', 403 ); }

	if ( class_exists( 'ZDZ_Media_Share' ) && ZDZ_Media_Share::is_kiosk( get_current_user_id() ) ) {
		wp_send_json_success( [ 'people' => [] ] );
	}

	$search = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';
	$people = class_exists( 'ZDZ_Party' )
		? ZDZ_Party::selectable_people( [ 'include_self' => false, 'search' => $search ] )
		: [];

	$out = array_map(
		function ( $p ) {
			return [
				'id'       => (int) $p['id'],
				'name'     => (string) $p['name'],
				'initials' => (string) ( $p['initials'] ?? '' ),
			];
		},
		$people
	);
	wp_send_json_success( [ 'people' => $out ] );
}

/**
 * Register this plugin's rename map with the platform migration.
 * Plugins declare; the kernel migrates. No per-plugin migration code.
 */
add_filter( 'zdz_rename_map', function ( $map ) {
		$map['options'] = array_merge( $map['options'] ?? [], [
			'tsml_seeded_apps_v2' => 'zml_seeded_apps_v2',
		] );
	return $map;
} );
