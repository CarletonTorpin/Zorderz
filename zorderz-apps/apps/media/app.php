<?php
/**
 * Plugin Name: Zorderz Media
 * Description: Organization media library for Zorderz Field OS. Browse public photos & sketches across the org, plus your own uploads — grouped by date, filterable by type/source, with transcribable notes and per-photo visibility control. Add photos directly via bulk upload. Reads from / writes to ZDZ_User_Media.
 * Version: 2.3.3
 * Author: Zorderz
 * Requires PHP: 8.0
 *
 * == Changelog ==
 * 2.3.3  (Widget usability: browse more in place + tab-label fit — the owner)
 *   - LOAD MORE IN THE WIDGET. The dashboard Media widget used to show only the
 *     6 most-recent items, forcing "See All" to see anything older. It now loads
 *     a fuller first page (12) and shows a "Load more" button that appends the
 *     next page IN PLACE — the card grows downward (no nested scroll, so it still
 *     honours the theme's WIDGET-OVERFLOW-CONTRACT). The widget is now genuinely
 *     browsable on its own; "See All" remains for the roomy grouped gallery.
 *     Reuses the existing offset/has_more paging (client-only change).
 *   - TAB LABELS NO LONGER TRUNCATE. The widget scope tabs were forced into equal
 *     thirds (flex:1), which clipped "Organization" to "Organizatio…" whenever
 *     another tab was active. Tabs are now sized to their labels and centered;
 *     if all three ever exceed the width they scroll horizontally (no clipping).
 *   - CSS + JS only (media.css, media.js) + version banners; no PHP logic, no
 *     permission/data path touched. Verified: CSS parses (rules > 0), JS clean.
 *
 * 2.3.2  (CRITICAL: Media CSS wasn't loading at all — the owner)
 *   - The media.css banner comment contained the two-character sequence that
 *     CLOSES a CSS comment (the token pair "--sys-*" immediately followed by
 *     "/--ref-*"), which ended the opening comment early. The browser then tried
 *     to parse the rest of the banner prose as CSS, hit an error, and DISCARDED
 *     THE WHOLE STYLESHEET — 0 rules applied. That's why the Media widget showed
 *     plain unstyled text ("My Photos Organization All", "+ Add See All", "All
 *     Photos Sketches") with no pills, no segmented control, and no hover/click
 *     affordances on desktop or mobile. Diagnosed live: the file served fine
 *     (HTTP 200, correct bytes) but the browser parsed it to 0 rules.
 *   - Fix: reworded the comment so the comment-closing sequence never appears in
 *     comment text. No style rule changed — this simply makes the v2.3.1 layout
 *     (and every rule before it) actually load. Verified by parsing the file
 *     through the real browser engine: 218 rules now parse (was 0).
 *   - NOTE: this offending sequence predates the recent work, so the Media
 *     widget's controls were likely unstyled before too; this is the real fix.
 *
 * 2.3.1  (Mobile bar layout fix for the 3-tab scope set — the owner)
 *   - CSS-ONLY, no behavior change. Adding the admin "All" scope in v2.3.0 gave
 *     the Media bar THREE scope tabs (My Photos / Organization / All), but the
 *     bar's layout was built for two: on mobile the segmented control wrapped
 *     into an ugly vertical dark stack and the "Add" button sat off-center,
 *     crammed against "See All".
 *   - WIDGET bar is now TWO clean rows: the segmented control on its own
 *     full-width row (equal-width tabs that never wrap — they scroll
 *     horizontally if ever too many, mirroring zdz-estimate-creator's
 *     .tsec-w-tabs pattern), and Add + See All right-aligned on a second row.
 *   - FULL-SCREEN bar reserves a top row for the pinned Select (left) / Add
 *     (right) corner buttons, so the centered scope segment sits BELOW them and
 *     can never overlap them at any width (the old CSS squeezed the segment
 *     between the corners, so a 3-tab set tucked under the buttons).
 *   - Consistent ~40–44px tap targets; dark / system / sunlight parity kept.
 *     Verified by rendering both surfaces at phone + tablet widths in all
 *     themes. Only assets/css/media.css changed (+ version banners for cache
 *     bust); no PHP/JS logic, no permission or data path touched.
 *
 * 2.3.0  (Admin "All" scope — browse every photo, incl. private — the owner)
 *   - WHY. Photos default to privacy='private'. Private already means "the
 *     uploader AND any admin can OPEN the file" (the theme serve() gate,
 *     v2.33.0: "private → owner + admins"), so an admin handed a link — e.g. a
 *     Nutshell photo deep-link — can already view it. What was missing was
 *     DISCOVERY: private photos never appear in a browsable gallery. "Mine"
 *     shows only your own; "Organization" shows only fully-public photos. So an
 *     admin could open a private photo but had no way to find one.
 *   - NEW ADMIN-ONLY "ALL" SCOPE. The gallery gains a third scope tab, "All",
 *     shown ONLY to admins (WP administrator / zdz_owner / zdz_admin — the same
 *     zml_current_user_is_admin() definition that already authorizes
 *     delete-any-photo). It lists EVERY photo org-wide regardless of owner or
 *     privacy, backed by the new theme getter ZDZ_User_Media::get_all_media()
 *     (theme ≥ 2.37.0), so an admin can browse and reach any uploader's photos.
 *   - SERVER-ENFORCED. The zml_list endpoint authorizes scope=all on the
 *     server via zml_current_user_is_admin() and DOWNGRADES a non-admin (or a
 *     crafted/stale request) to the public view; the hidden tab is never the
 *     gate. On a pre-2.37.0 theme the scope fails safe to 'public'. This does
 *     NOT widen file access — serve() already lets admins open private media;
 *     it only makes those rows discoverable. Nothing changes for non-admins,
 *     and the shared-kiosk all-deny posture is untouched.
 *   - The default upload privacy is UNCHANGED (still 'private'): a new photo is
 *     still owner-visible in the lists and admin-openable/discoverable — exactly
 *     "viewable to the uploader and any admin by default." No schema change; no
 *     migration. Companion theme: zdz-theme-2 v2.37.0 (get_all_media()).
 *
 * 2.2.0  (Delete photos — granular + bulk, double-confirmed — the owner)
 *   - DELETE. Owners can delete their OWN photos; admins (WP administrator /
 *     zdz_owner / zdz_admin — the platform's standard admin definition) can
 *     delete ANY photo. New server endpoint zml_delete (nonce-checked, auth +
 *     nopriv-deny like the others) authorizes EVERY id individually
 *     (owner-or-admin), then removes the ZDZ_User_Media row AND its underlying
 *     WP attachment file via ZDZ_User_Media::delete(). Per-id results
 *     ({deleted, failed[{id,reason}]}) are returned so a partial failure never
 *     reads as success.
 *   - GRANULAR OR BULK. The full-screen gallery gains a "Select" mode: tap
 *     photos to check them (only photos you're allowed to delete are
 *     selectable — others dim), with a bottom action bar (live count, Delete,
 *     Cancel). The lightbox gains a per-photo "Delete photo" button
 *     (owners/admins only) on both surfaces. Both paths share one guarded flow.
 *   - DOUBLE CONFIRMATION (product decision). EVERY delete — single or bulk —
 *     asks twice: first a summary sheet ("Delete N photos?"), then an explicit
 *     second sheet ("Permanently delete — this can't be undone") whose
 *     destructive button is visually distinct. Implemented as an in-app
 *     top-level sheet (never window.confirm(), which is unreliable in the
 *     PWA/desktop webviews this app lives in) and reparented to <body> like
 *     the lightbox so transformed ancestors can't trap it.
 *   - The grid updates in place after deletion (no full reload), the lightbox
 *     steps to the next photo or closes, and the OTHER surface (widget ⇄
 *     fullscreen) is refreshed so the two never disagree. A new per-item
 *     can_delete flag (computed server-side) drives every affordance; the
 *     server re-checks authorization on every delete regardless.
 *
 * 2.1.0  (Bulk photo upload — add photos directly to the Media app — the owner)
 *   - ADD PHOTOS. The Media app can now INGEST photos, not just browse them. An
 *     "Add Photos" button on the full-screen gallery bar and a compact "Add" on
 *     the dashboard widget open a bulk-upload sheet: pick MANY photos at once,
 *     optionally type ONE note for the whole batch, and upload. New server
 *     endpoint zml_upload (nonce-checked, auth + nopriv-deny like the others)
 *     stores ONE photo per request into ZDZ_User_Media; the client fans the
 *     selection out into concurrent per-file POSTs (limited concurrency) with a
 *     shared batch id — mirroring zdz-camera's resilient one-file-per-request
 *     path. Saved to the uploader's own library at privacy=private (flip to
 *     Everyone per-photo afterward). On completion the gallery switches to "My
 *     Photos" and refreshes so the new photos appear.
 *   - METADATA PRESERVED (parity with zdz-camera). The ORIGINAL file is stored as
 *     the attachment via media_handle_upload(), so ZDZ_User_Media::save() reads
 *     EXIF (capture time + GPS + the full raw block) server-side from the
 *     untouched original — JPEG/TIFF provenance survives end-to-end. For HEIC/
 *     HEIF (PHP can't parse it) the client reads EXIF IN-BROWSER via the vendored
 *     exifr and sends captured_at / gps_* / geo_source / time_source alongside
 *     the file; save() prefers those. No re-encode, no EXIF/GPS strip. Unlike the
 *     live camera, a file with no EXIF date falls back to the file's lastModified
 *     (time_source='file_mtime'), never "now", and the device's current GPS is
 *     never substituted for an existing photo.
 *   - BATCH NOTE, VISIBLE + TAGGED (forward-looking). The one batch note is
 *     written to every photo's `description` (the same owner-editable field the
 *     camera uses and the lightbox already shows/edits — so it's visible and
 *     per-photo editable for the user's reference) AND mirrored into
 *     meta_json.batch = { id, note, seq, count, uploaded_at } on every photo of
 *     the batch (our metadata tagging). The batch envelope is deliberately
 *     open-ended so a future "job"/"customer" association is an ADDITIVE field
 *     here (e.g. batch.job_id) with no schema or contract change. No job model is
 *     assumed yet.
 *   - exifr 7.1.3 vendored under assets/js/vendor (no runtime CDN), enqueued as a
 *     dependency of media.js. No theme change required.
 *
 * 2.0.3  (Lightbox full-size = real zoom — the owner follow-up)
 *   - REAL ZOOM, NOT A BINARY TOGGLE. v2.0.2 made "full size" a single fixed
 *     jump to natural resolution with no navigation — usable, but crude (you
 *     landed mid-image with only "fit to screen"). It is now a proper
 *     image zoom INSIDE the lightbox: pinch (touch + trackpad), double-tap /
 *     double-click to toggle 2× at the tapped point, and drag to pan. Driven by
 *     a CSS transform (translate + scale) on the image; the stage clips. Still a
 *     <button>, so it never navigates the window (the v2.0.1 dead-end on tab-less
 *     desktop/PWA webviews stays fixed). Prev/next arrows show at fit and hide
 *     once zoomed in (per request); "Back"/Esc/backdrop exit from any zoom level
 *     (close action unchanged — invariant I3); prev/next + re-open reset to fit.
 *     JS: Lightbox.{applyTransform,clampPan,zoomTo,buttonZoom,resetZoom,
 *     syncZoomUI,bindZoomGestures} (replaces v2.0.2 setZoom/toggleZoom). CSS:
 *     transform-based .zml-lb-img + .zml-zoomed. No data/permission/EXIF/
 *     registration path touched; no theme change required.
 *
 * 2.0.2  (Lightbox "View full size" no longer strands the user — the owner)
 *   - SAFE FULL-SIZE. The lightbox's top-right control was an
 *     <a href="{file_url}" target="_blank"> "Open full size". In a tab-less
 *     standalone / desktop webview, target="_blank" has nowhere to open, so it
 *     navigated the CURRENT window to the bare image file — a dead-end page with
 *     only the OS window's back arrow and no in-app "Back". It is now a <button>
 *     "View full size" that toggles full-resolution zoom INSIDE the lightbox
 *     (the image renders at natural size; the stage pans/scrolls). A button
 *     cannot navigate the window, so the dead-end is impossible. "Back" / Esc /
 *     backdrop / hardware-back still exit the viewer from either zoom state
 *     (close action unchanged — invariant I3); prev/next and re-open reset to
 *     fit-to-screen. JS: Lightbox.setZoom/toggleZoom + per-photo reset in
 *     render() + teardown reset. CSS: .zml-lb.zml-zoomed stage/image/arrows.
 *     No data, permission, EXIF, or registration path touched.
 *
 * 2.0.1  (Mobile field-review follow-ups — doc 08)
 *   - SINGLE DOCK TILE. The full-screen gallery ('zdz-media-all') no longer
 *     registers as its own second "Media" dock tile (the duplicate). It now sets
 *     'springboard' => false (theme v2.21.0 flag), so it is excluded from the
 *     springboard grid, dock, recents, and command palette while remaining fully
 *     loadable — it stays server-registered and in zdz_allowed_apps so /load-app
 *     authorizes it and the viewport header reads correctly. The Media app is now
 *     ONE tile (the 'zdz-media' dashboard widget); the gallery still opens from
 *     the widget's "See All". Seeding is unchanged (both ids remain allowed so
 *     "See All" works for non-admins).
 *   - CENTERED IN THE WIDGET TOO. The scope segmented control + type chips are
 *     now centered on the dashboard-widget surface (v2.0.0 only centered them on
 *     the full-screen surface). "See All" stays pinned at the right edge via a
 *     3-column grid that keeps the segment truly centered and never clipped.
 *   - OBVIOUS BACK. The lightbox's top-left control is now an explicit "‹ Back"
 *     affordance (chevron + label) instead of a bare ✕, so the full-screen viewer
 *     never reads as a dead-end page. Same close behavior (history-back / Esc /
 *     backdrop / hardware back all still work); only the affordance changed.
 *   - "LOCATED" IS NOW TAPPABLE. In the viewer, a geotagged photo's "📍 Located"
 *     is an interactive control: tapping it fetches the theme's
 *     GET /zorderz/v1/media/{id}/exif (once, cached per id) and reveals the resolved
 *     place name, coordinates, a provenance chip (Photo GPS vs Device location),
 *     and a user-tap "Open in Maps" link. Privacy model preserved — the map link
 *     only contacts a provider when the user taps it; geocoding still resolves
 *     once, server-side. Falls back to coordinates + map link if the endpoint is
 *     unavailable, so "Located" is never a dead tap.
 *
 * 2.0.0  (Mobile-review rebuild — "Both" architecture)
 *   - DUAL SURFACE. The library now lives in two coordinated places:
 *       • A DASHBOARD WIDGET (bridge_type inline_widget) — a compact, iOS-Photos
 *         style "recent media" card on the home dashboard. This is the primary
 *         at-a-glance surface, with a "See All" affordance.
 *       • A FULL-SCREEN gallery (bridge_type ajax_html) — the roomy "see
 *         everything" view with infinite scroll, reached from "See All" or the
 *         app dock.
 *   - SHARED CONTROLLER. A single front-end controller (assets/js/media.js) is
 *     enqueued GLOBALLY on the SPA front page and exposes window.TSMedia with
 *     mountWidget() / mountFullscreen(). Because it is a real enqueued asset
 *     (not injected via innerHTML) it runs reliably on BOTH surfaces today —
 *     in particular the full-screen ajax_html surface does NOT depend on the
 *     theme reviving inline <script> in #app-body (Bridge v3.2 gap). When the
 *     theme ships that revival, nothing here needs to change.
 *   - LIGHTBOX FIXED. The viewer is rendered into a dedicated top-level layer,
 *     never trapped by the transformed .app-viewport ancestor; inputs are ≥16px
 *     so iOS no longer auto-zooms the note field; close + hardware back are
 *     always reachable; saving uses the no-shift busy pattern (button keeps its
 *     box, inline status, no reflow, can't be double-fired).
 *   - VISIBILITY TOGGLE. Owners can flip a photo between "Visible to Everyone"
 *     (privacy=public) and "Visible only to You" (privacy=private) right in the
 *     viewer. Server-enforced owner-only (zml_save_visibility → 403 otherwise).
 *   - Centered the scope segmented control + filter chips.
 *   - Tokens corrected to the theme's real palette (brand #2C5F8A, not #6366F1).
 *
 * 1.0.0
 *   - Initial release. Full-screen gallery app (bridge_type ajax_html).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'ZML_VERSION', '2.3.3' );
define( 'ZML_FILE', __FILE__ );
define( 'ZML_DIR', plugin_dir_path( __FILE__ ) );
define( 'ZML_URL', plugin_dir_url( __FILE__ ) );
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

	// Roles that should see Media. Admin/owner/admin-TS see everything anyway,
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

	wp_enqueue_script(
		'zml-js',
		ZML_URL . 'assets/js/media.js',
		[ 'zdz-app-js', 'zml-exifr' ],  // window.Bridge / zdzData + exifr available
		ZML_VERSION,
		true                       // in footer, after the SPA core
	);

	wp_localize_script( 'zml-js', 'zmlApp', [
		'ajaxurl' => admin_url( 'admin-ajax.php' ),
		'nonce'   => wp_create_nonce( ZML_NONCE ),
		'version' => ZML_VERSION,
		'user_id' => get_current_user_id(),
		// v2.3.0: is the viewer an admin (WP administrator / zdz_owner / zdz_admin)?
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
	add_action( 'wp_ajax_tsml_list',            'zml_ajax_list' );
	add_action( 'wp_ajax_tsml_save_note',       'zml_ajax_save_note' );
	add_action( 'wp_ajax_tsml_save_visibility', 'zml_ajax_save_visibility' );
	add_action( 'wp_ajax_tsml_upload',          'zml_ajax_upload' );
	add_action( 'wp_ajax_tsml_delete',          'zml_ajax_delete' );

	foreach ( [ 'zml_list', 'zml_save_note', 'zml_save_visibility', 'zml_upload', 'zml_delete' ] as $a ) {
		add_action( "wp_ajax_nopriv_$a", 'zml_deny_nopriv' );
	}
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
	return [
		'id'            => (int) $m['id'],
		'title'         => (string) ( $m['title'] ?? '' ),
		'note'          => (string) ( $m['description'] ?? '' ),
		'media_type'    => (string) ( $m['media_type'] ?? 'photo' ),
		'source_app'    => (string) ( $m['source_app'] ?? '' ),
		'thumbnail_url' => (string) ( $m['thumbnail_url'] ?: $m['file_url'] ),
		'file_url'      => (string) $m['file_url'],
		'privacy'       => (string) ( $m['privacy'] ?? 'private' ),
		'created_at'    => (string) ( $m['created_at'] ?? '' ),
		'captured_at'   => isset( $m['captured_at'] ) ? (string) $m['captured_at'] : null,
		'gps_lat'       => isset( $m['gps_lat'] ) && $m['gps_lat'] !== null ? (float) $m['gps_lat'] : null,
		'gps_lng'       => isset( $m['gps_lng'] ) && $m['gps_lng'] !== null ? (float) $m['gps_lng'] : null,
		'is_owner'      => $is_owner,
		// v2.2.0: owners may delete their own photos; admins may delete any.
		// Drives the delete affordances; the server re-checks on every delete.
		'can_delete'    => $is_owner || zml_current_user_is_admin(),
	];
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
 *                  client to have hidden the tab (v2.3.0).
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
	} elseif ( $scope === 'all' ) {
		// Admin browse-all: every row regardless of owner/privacy. Requires the
		// theme getter (theme ≥ 2.37.0); on an older theme, fail safe by falling
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

	$items = array_map( function ( $r ) use ( $viewer ) { return zml_shape_item( $r, $viewer ); }, $rows );

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
		error_log( 'TSML upload: ' . $att_id->get_error_message() );
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
	// view it" (theme serve() gate, v2.33.0: private → owner + admin-tier), so a
	// bulk-uploaded photo defaults to owner-visible-in-lists + admin-viewable;
	// admins DISCOVER it via the new admin-only "All" scope (v2.3.0). The user
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
		error_log( 'TSML upload: metadata save failed. db_error:' . $wpdb->last_error );
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
 * Permanently delete media items — single or bulk (v2.2.0).
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
 * Register this plugin's rename map with the platform migration.
 * Plugins declare; the kernel migrates. No per-plugin migration code.
 */
add_filter( 'zdz_rename_map', function ( $map ) {
		$map['options'] = array_merge( $map['options'] ?? [], [
			'tsml_seeded_apps_v2' => 'zml_seeded_apps_v2',
		] );
	return $map;
} );
