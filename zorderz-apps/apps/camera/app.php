<?php
/**
 * Plugin Name: Zorderz - TS - Camera
 * Description: Camera and photo capture for Zorderz Field OS. Photos saved to ZDZ_User_Media for cross-app use.
 * Version: 1.7.4
 * == 1.7.4 == DOC-ONLY (no behavior change): documented, at the capture save
 *   path, that a new photo intentionally defaults to privacy='private' and that
 *   'private' on this platform already means "the uploader AND any admin can
 *   view it" (the theme serve() gate, v2.33.0: private → owner + admin-tier).
 *   So a field photo is owner-visible in the lists and admin-viewable by
 *   default; admins DISCOVER private photos via the Media app's new admin-only
 *   "All" scope (zdz-media-library v2.3.0). This clarifies "viewable to the
 *   uploader and any admin by default" without changing the stored value or any
 *   capture behavior. Version bump only cache-busts the asset banner.
 * == 1.7.1 == FIX (field test): the zoom PILL buttons (.5×/1×/3×) didn't change
 *   zoom on tap — only the slider worked. Two causes, both fixed: (1) the
 *   single-finger tap-to-focus handler calls preventDefault() on the live
 *   view's touchstart, which swallowed the buttons' synthetic click; onControl()
 *   now recognizes the zoom pill + slider so the focus gesture leaves them
 *   alone. (2) Belt-and-suspenders: the buttons now fire on touchend directly
 *   (with click de-dupe) so a tap registers on iOS regardless of synthetic-
 *   click quirks, and stopPropagation keeps a button tap from also dropping a
 *   focus point underneath. Slider behavior unchanged.
 * == 1.7.0 == VISIBLE ZOOM CONTROLS (frontend-only; discoverability). Until now
 *   zoom was pinch-only with a 0.9s chip, so users never knew it existed — an
 *   employee shot a whole job on the wide lens by mistake. The live viewfinder
 *   now has an ALWAYS-VISIBLE, iOS-style zoom UI sitting just above the shutter:
 *   (1) a ZOOM PILL of tappable factor buttons built from the lenses actually
 *   discovered for the current facing — multi-lens phones show the real optical
 *   stops (e.g. .5 / 1× / 3×); single-lens phones/front camera show digital
 *   steps (1× / 2× / 4× / max) so the control still says "you can zoom"
 *   everywhere. The active stop is highlighted yellow and shows the live factor
 *   when between stops (e.g. 1.7×). (2) A vertical FINE-ZOOM SLIDER on the right
 *   edge (drag for continuous zoom across the whole range). (3) A one-time
 *   "Pinch or tap to zoom" HINT on first open (remembered in localStorage).
 *   All of this drives the EXISTING setZoom()/zoomSt engine — no change to lens
 *   selection, digital-crop capture (still WYSIWYG), or any other behavior;
 *   two-finger pinch keeps working and now moves the pill/slider in sync.
 * == 1.6.1 == FIX: press-and-hold (AE/AF lock) no longer triggers iOS text
 *   selection (blue tint + magnifier loupe). user-select and the long-press
 *   callout are disabled across the live view, and non-control single-finger
 *   touches preventDefault() so the system gesture never starts. Frontend
 *   only; controls and all other behavior unchanged.
 * == 1.6.0 == iOS-CAMERA CONTROLS (frontend-only): tap-to-focus/expose,
 *   ± exposure compensation, AE/AF lock — the native camera's gesture
 *   language on the live viewfinder so the app feels like an extension of
 *   it. TAP → yellow focus square + AF/AE re-meter + sun slider (comp resets,
 *   lock clears). VERTICAL DRAG → ± exposure compensation (≈±1.25 EV),
 *   WYSIWYG: preview filter and captured JPEG get the IDENTICAL gain.
 *   PRESS & HOLD → AE/AF LOCK yellow pill + square pulse; comp stays
 *   adjustable while locked; next tap unlocks. Two-finger pinch still zooms;
 *   one unified touch controller routes all gestures, buttons excluded.
 *   Platform reality (researched): WebKit ships only zoom/torch/
 *   whiteBalanceMode — focusMode/exposureCompensation/pointsOfInterest are
 *   NOT exposed on iOS yet. All controls PROBE getCapabilities() and use the
 *   native constraint when present (a future Safari upgrades the camera
 *   automatically, zero code change); exposure comp otherwise uses software
 *   gain (canvas ctx.filter, composite-blend fallback) baked into the capture
 *   to match the preview exactly. Tap re-metering leans on iOS's always-on
 *   continuous AF; the lock is a true hardware lock only where constraints
 *   exist, else it pins the software exposure (README documents this).
 * == 1.5.1 == SEAMLESS LENS SWITCH + FULL-SCREEN CAPTURE CONFIRMATION
 *   (frontend-only polish on 1.5.0, per field feedback). (1) Lens switches no
 *   longer black out: the current frame is frozen onto an overlay canvas, the
 *   new lens's stream is acquired BEFORE the old one is stopped, and the
 *   freeze lifts when the new stream paints (1.5s safety; failure falls back
 *   to the previous lens). Flip (front/back) hides its swap the same way.
 *   (2) Each shutter tap now shows THE CAPTURED PHOTO full-screen for a beat
 *   — revealed by a slightly longer shutter blink — before it shrinks toward
 *   the corner thumbnail. One tap, one unmistakable full-screen confirmation.
 * == 1.5.0 == PINCH-ZOOM with real lens selection + shutter feedback
 *   (frontend-only). Pinch on the live viewfinder zooms like the native
 *   camera: iOS Safari has NO `zoom` track constraint (Android-only), but
 *   iOS 16.3+ exposes each physical back lens as its own device — so pinch
 *   SWITCHES to the proper lens (Ultra Wide 0.5× / Wide 1× / Telephoto,
 *   assumed 3×) at the breakpoints and applies WYSIWYG digital zoom between
 *   them: the preview scales and the capture crops the exact same center
 *   region (real pixels, never upscaled). Yellow zoom chip while pinching.
 *   Every shutter tap now fires a native-style 2–3-frame black shutter blink
 *   AND a captured-frame thumbnail pop, so each photo gets an unmistakable
 *   "you just took 1 photo" confirmation. Localized/missing device labels
 *   degrade gracefully to single-lens digital zoom; front camera digital-only.
 * == 1.4.0 == LIVE CAMERA — the camera view STAYS on screen (replaces 1.3.0's
 *   overlay same-day on field feedback: on iOS builds that refuse the
 *   auto-relaunch, the overlay ADDED a step per shot; what the field wants is
 *   the viewfinder staying open). Take Photo now opens an in-page getUserMedia
 *   viewfinder (rear camera, max available resolution): every shutter tap
 *   grabs the current frame (canvas → JPEG q0.92, flash blip) and feeds the
 *   EXACT v1.2.x durable pipeline — note/sticky label, device-clock
 *   captured_at, geolocation fallback, IndexedDB + in-memory bytes,
 *   X-WP-Nonce upload — while the camera keeps running. Live counter
 *   ("N photos · uploading · saved") overlays the view; Flip switches
 *   front/rear; Done ends the session; backgrounding kills tracks → they
 *   re-acquire on return. EXPLICIT TRADE-OFF (product decision): stream
 *   frames (up to ~4K) are below the native camera's processed stills —
 *   accepted for true continuous shooting, since iOS user-activation rules
 *   force the native file-input path into one round-trip per shot and
 *   Safari/iOS has no ImageCapture.takePhoto(). Provenance is identical on
 *   both paths (iOS strips EXIF from ALL browser captures anyway). The
 *   system camera remains the automatic fallback when getUserMedia is
 *   unavailable/denied (first use prompts for camera permission — Allow).
 * == 1.3.0 == BURST MODE — shoot photo sequences like the native iPhone camera
 *   (frontend-only; transport/queue untouched). The first Take Photo now opens
 *   a shooting SESSION: after every native-camera round-trip the user lands on
 *   a full-screen overlay — giant "Next photo" button (one direct tap back
 *   into the camera), Done button, the active label, and a live
 *   "N photos · uploading · saved" counter — instead of being dropped back
 *   onto the dashboard. Uploads continue in the background through the
 *   existing durable pipeline while the user keeps shooting; the session ends
 *   via Done or by canceling inside the native camera ('cancel' event,
 *   Safari 16.4+). A zero-tap auto-relaunch of the camera is ATTEMPTED after
 *   each capture (some iOS builds honor the lingering user activation; where
 *   iOS declines, the overlay button is the single-tap fallback — researched
 *   constraint: iOS opens the file-input camera only from a direct user
 *   gesture, and Safari/iOS has no ImageCapture.takePhoto(), while a
 *   getUserMedia viewfinder would capture video-grade frames — so the
 *   full-quality native camera path stays).
 * == 1.2.10 == DISCARD for stuck uploads (frontend-only). Some queued records
 *   on iOS never produce readable bytes (the 1.2.9 empty-bytes flakiness can
 *   be permanent for a given record); they used to sit in the upload strip
 *   forever — re-rendered on every load, retried every 30s, with no way out
 *   short of clearing the site's website data (which nukes the whole queue).
 *   Failed items now show a discard (×) control next to Retry: first tap arms
 *   a red "Discard?" confirm (auto-disarms in 4s), second tap deletes the
 *   IndexedDB record + its in-memory bytes and removes the row. Two-tap on
 *   purpose: for a never-uploaded photo this deletes the ONLY copy. Also
 *   FIELD-VERIFIED tonight on a fresh shell: 6/6 captures uploaded full-size
 *   and saved, legacy nonce verifying again (tscam=1) — confirming the whole
 *   remaining failure mode was the device's cached page shell (stale JS +
 *   baked stale nonce), per the v1.2.9 README's purge/PWA notes.
 * == 1.2.9 == THE EMPTY-BYTES FIX (the bug the nonce was hiding). With 1.2.8
 *   live, the debug.log proved the nonce path is FIXED (every request:
 *   "auth:ok user=2", "wprest_hdr=1", zero nonce failures) and yesterday's
 *   queued photos — stored ~24h earlier — uploaded at FULL size and saved.
 *   But every NEW capture arrived as "files=1 bytes=0 … file:image.jpg size=0"
 *   → "File is empty", consistently across retries. So the client streamed an
 *   EMPTY file part: iOS Safari's IndexedDB can return an EMPTY ArrayBuffer
 *   when a large, freshly-written record is read straight back (the same
 *   WebKit storage flakiness family as the 1.2.7 Blob bug — write durability /
 *   read-back lag for big binary values), while the SAME records read back
 *   fine much later (yesterday's uploaded perfectly today). Yesterday's
 *   "bytes=0" log lines were this exact bug, mis-read as concurrent-retry
 *   noise. Three changes (frontend + one server guard, no business logic):
 *     1. widget.js keeps the just-captured bytes IN MEMORY for the session
 *        (liveBytes map, capture_uid → ArrayBuffer, the exact buffer that was
 *        written to IndexedDB). An upload attempt uses the IndexedDB copy only
 *        if it is non-empty AND matches the recorded size; otherwise it falls
 *        back to the in-memory bytes. First-attempt uploads therefore never
 *        depend on an IndexedDB round-trip — the same reliability property as
 *        the Media app, which uploads the live picker File directly.
 *     2. NEVER POST EMPTY: zcam-queue.js records the original byte length
 *        (`size`) at capture; if neither source yields usable bytes, the
 *        attempt is SKIPPED (failed → auto-retried by the 30s sweep / Retry /
 *        reload) instead of sending a 0-byte body. The IndexedDB copy provably
 *        materializes later (yesterday's records), so retries can succeed.
 *     3. Server logs a distinct "EMPTY file body" line and returns a clean
 *        JSON error if a 0-byte part ever still arrives, instead of the
 *        misleading php.ini-flavored media_handle_upload message.
 *   Theme sw.js 2.21.4 (optional, Android-only) also skips empty blobs.
 *   No DB change (`size` is a plain property); bump cache-busts JS.
 * == 1.2.8 == THE NONCE FIX. v1.2.7's entry log PROVED full-res photos now reach
 *   PHP ("TSCAM save: entry … bytes=2257604"), but every request was then
 *   rejected by check_ajax_referer(ZCAM_NONCE) ("TSCAM save: nonce check
 *   FAILED"). The camera's legacy nonce is minted in render_dashboard_widget()
 *   and baked into widget HTML that the SPA re-injects via innerHTML (and that
 *   NitroPack can serve from cache) — so window.zcamWidget.nonce can drift
 *   from the user's live session. The Media app never hits this because its
 *   nonce rides a normally-enqueued global script, re-localized on every real
 *   page load. Fix — authenticate the way Media effectively does:
 *     1. Server: all three ajax handlers (save/list/delete) now ALSO accept the
 *        theme's wp_rest nonce via the standard X-WP-Nonce header (or _wpnonce
 *        POST field). The legacy ZCAM_NONCE 'nonce' field is KEPT as a
 *        fallback — nothing regresses.
 *     2. Client: widget.js sends X-WP-Nonce on every request, reading the
 *        always-fresh window.zdzData.nonce (localized on zdz-app-js each real
 *        page load) LIVE at send time; the value at shutter is also persisted
 *        on the queue record (rest_nonce) so a Service-Worker Background-Sync
 *        retry can authenticate too (theme sw.js 2.21.3 pairs with this).
 *     3. zcam-widget-js now declares zdz-app-js as a dependency — the theme's
 *        own documented pattern to guarantee window.zdzData is present.
 *   Also adds a one-line "TSCAM nonce-debug" log on every save attempt (uid +
 *   verify result of BOTH nonces) so any residual identity mismatch — e.g. the
 *   kiosk/demo determine_current_user switch minting the nonce for a different
 *   user than the one verifying it — shows up in debug.log instead of being
 *   guessed at. No DB change; bump cache-busts JS.
 * == 1.2.7 == THE ACTUAL UPLOAD FIX (supersedes the 1.2.6 theory). A server debug.log
 *   proved that with 1.2.6 live, captured photos still hung at "Uploading…" and the
 *   server logged NOTHING for them — the POST never completed a round-trip to PHP (this
 *   handler logs on every failure path, so a reached-but-failed request would appear).
 *   Meanwhile the Media app uploads the SAME photos fine. Decisive difference: the camera
 *   persisted the original as a Blob in IndexedDB and streamed THAT to the server, whereas
 *   Media uploads the live picker File directly. iOS Safari's IndexedDB does NOT reliably
 *   store Blob/File values (documented WebKit limitation) — the stored Blob returns
 *   unreadable/zero-length, so the multipart body never finishes and the request stalls
 *   before reaching the server. Frontend fix (no business-logic change):
 *     1. zcam-queue.js stores the ORIGINAL bytes as an ArrayBuffer (iOS-safe) and rebuilds
 *        a byte-identical Blob at upload — ALL EXIF/GPS preserved exactly, no re-encode.
 *     2. widget.js uploads via XMLHttpRequest (the Media app's proven transport) instead of
 *        fetch(): onload/onerror/ontimeout always fire, so a request can't hang forever.
 *   Also adds a one-line ENTRY log at the very top of zcam_ajax_save_photo (before the
 *   nonce check) so the server records that a request ARRIVED — making this class of "never
 *   reached PHP" bug visible next time. The 1.2.6 single-uploader LEASE is retained; durable
 *   queue + Retry + capture_uid idempotency unchanged. Legacy ≤1.2.6 Blob records read via a
 *   fallback. DB_VER unchanged (ArrayBuffer is a plain property), so the SW needs no migration.
 * == 1.2.6 == Uploads no longer stall (theory, later found secondary): the page's foreground
 *   drain and the Service Worker's Background-Sync drain read the SAME IndexedDB queue and
 *   could each POST the same file CONCURRENTLY. Fix is a cooperative single-uploader LEASE
 *   (claim()/release() in zcam-queue.js). Pairs with theme sw.js v2.21.1. Retained in 1.2.7,
 *   but was not the cause of the stall (see 1.2.7).
 * == 1.2.5 == Per-photo note field: disabled iOS/browser autocorrect, autocapitalize,
 *   and spellcheck (autocorrect="off" autocapitalize="off" spellcheck="false") so a typed
 *   label or customer name isn't silently "corrected" (e.g. Rivera→Rivera). Frontend-only.
 * Author: Zorderz
 * Requires PHP: 7.4
 *
 * == Changelog ==
 * 1.2.4 — Orchestrator pre-label: launching the Camera with options.prelabel
 *         (e.g. from the dashboard "ask" field: "open the camera and label all my
 *         photos as 'before'") pre-fills the per-photo note AND re-applies it to
 *         every shot until cleared, the app closes, or a 1-hour safety window
 *         elapses. Frontend-only (widget.js + widget.css + version); the note still
 *         flows to ZDZ_User_Media.description via the existing capture POST path —
 *         no PHP/business-logic/DB change.
 * 1.2.3
 *   - Fix: uploads could stick at "Uploading…" forever (observed: three photos
 *     spinning indefinitely). Root cause was in the foreground drain: an item was
 *     set to status:'uploading' BEFORE the fetch, and fetch() has no timeout — so
 *     if the request hung (response never completed: weak shop uplink, large HEIC,
 *     stalled keep-alive), the promise neither resolved nor rejected, the item was
 *     stranded in 'uploading', and EVERY retry path (online / visibility / 30s
 *     interval / Retry button) skips it because they only re-scan pending||failed.
 *     The spinner therefore never resolved. Three independent fixes:
 *       1. uploadRecord() now wraps fetch in an AbortController with a 45s timeout,
 *          so a hung request REJECTS → the item becomes 'failed' → Retry is shown.
 *       2. pumpForeground() now reclaims items stranded in 'uploading' longer than
 *          90s (> the timeout, so an in-flight attempt is never reclaimed) back to
 *          'failed' — self-healing any spinner stuck from a tab-close mid-upload or
 *          from a pre-1.2.3 build. Records now carry `updated_at` to drive this.
 *       3. renderQueueItem() is status-aware: a record restored from IndexedDB in
 *          a 'failed' state rehydrates with its Retry button immediately, instead
 *          of showing a spinner that only corrects on the next sweep.
 *     capture_uid idempotency makes every resulting retry safe even if the server
 *     had already inserted the row before the response was lost (dedupe returns the
 *     existing row). No server change required for this fix.
 *   - NOTE (not changed here; flagged for a follow-up): the attachment auto-TITLE
 *     at `date('M j, g:i A')` uses the SERVER timezone (UTC), so a photo's *title*
 *     can read in UTC even though its captured_at is correct local time. This is
 *     cosmetic (title only) and separate from the capture-time pipeline; left as-is
 *     pending a decision on whether titles should use the client local time too.
 * 1.2.2
 *   - Fix: capture time could be wrong (e.g. an 11:03 AM photo recorded as a
 *     UTC-looking 6:03 PM) or missing. Root cause was a fresh iOS capture taken
 *     *through* the browser, which strips EXIF (and GPS): exifr then found no
 *     usable date, the client sent no `captured_at`, and the row was stored with
 *     a NULL/ambiguous time. `readProvenance()` now resolves capture time to a
 *     guaranteed LOCAL wall-clock string: it prefers EXIF `DateTimeOriginal`
 *     (only falling back to `CreateDate`, which some devices write in UTC), and
 *     when the file carries no usable EXIF date it falls back to the DEVICE's
 *     local clock at shutter (`toSqlDateTime(new Date())` — never
 *     `toISOString()`/UTC). The value sent is therefore always present and always
 *     local, matching the platform's "store local wall-clock" convention.
 *   - Provenance: a new `time_source` ('exif' | 'device_clock') travels with the
 *     capture (IndexedDB record → upload → `meta_json`), so the EXIF inspector can
 *     label a device-clock time honestly rather than implying it came from the
 *     file. `geo_source` is left strictly describing GPS provenance.
 *   - Fix: "stray line on the Take Photo button." After the native-camera
 *     round-trip the button kept focus, so the theme's global
 *     `button:focus-visible` box-shadow lingered as a ring/line. We now blur the
 *     button on return (JS) and clear any non-:focus-visible box-shadow on the
 *     primary button (CSS belt), preserving real keyboard focus rings.
 *   - Hardening: defensive surface lock on `.zcam-w` (`resize:none`,
 *     `max-width:100%`, `overflow-x:hidden`, universal `box-sizing`) so the widget
 *     can never be dragged larger/smaller or shifted left/right and nothing inside
 *     overflows the dashboard column. NOTE: the pinch/drag of the live preview
 *     with the "black corners" is the iOS NATIVE camera surface (an OS behavior
 *     outside the web layer), not this element; this lock removes any web-layer
 *     contribution and guards against future regressions.
 *   - No theme storage change: ZDZ_User_Media still stores `captured_at` verbatim.
 *     (Pairs with the theme/standalone EXIF-inspector `human_datetime()` display
 *     fix, which renders the stored wall clock verbatim instead of re-shifting it
 *     against the WP site timezone.)
 * 1.2.1
 *   - Fix: the `roles` config array listed two roles that don't exist on the
 *     platform ('zdz_installer', 'zdz_office') and omitted the ones that actually
 *     use the camera. Replaced with the real Zorderz role slugs (admin/owner
 *     tier, the field roles zdz_tech/zdz_operator/zdz_mfg, zdz_sales, and the
 *     zdz_general kiosk). This array is declarative — live access is granted via
 *     each user's zdz_allowed_apps meta and resolved in ZDZ_Plugin_API — so the
 *     bug was misleading metadata rather than a hard lockout, but it's now
 *     accurate and won't mislead any code that reads it.
 * 1.2.0
 *   - CLS / "jumps to another view on close" fix: the capture UI no longer
 *     changes the dashboard's height when shots are added/removed (the queue
 *     strip reserves its own space and overlays rather than pushing content),
 *     and after the native-camera round-trip the widget is scrolled back into
 *     view. Photographing 10-15 in a row no longer walks the page away.
 *   - Geo-fallback is now ON by default and clearly labeled as DEVICE location
 *     (geo_source = 'device_fallback'). Rationale: iOS strips embedded GPS from
 *     any photo captured *through* the browser (both <input capture> and
 *     getUserMedia) as a privacy measure — only library picks keep EXIF GPS.
 *     So for fresh field photos the device-location fallback is the ONLY way to
 *     geotag for job-by-location matching. EXIF GPS, when present (e.g. an
 *     imported library photo), still wins; the fallback never overwrites it.
 *   - Per-photo NOTE: an optional note can be attached at capture time and is
 *     stored in ZDZ_User_Media.description (the same owner-editable field the
 *     Media library shows and lets you edit later). Captures the photo's
 *     PURPOSE without an in-page draw surface.
 *   - "Saved to your Media library" confirmation so the user knows where the
 *     photo lives and that other apps (Receipts, Media) can reach it.
 * 1.1.0
 *   - Tap-to-capture: opens the native camera directly via the theme's
 *     `zdz_app_launch` intent (requires theme Bridge v3.2). No dashboard scroll.
 *   - Removed the preview/confirm and separate "Save Photo" step — choosing the
 *     photo is the commit.
 *   - Provenance: keeps the ORIGINAL device file (no canvas re-encode) and reads
 *     EXIF in-browser via exifr (incl. HEIC, which PHP can't), sending
 *     captured_at/GPS as explicit args to ZDZ_User_Media::save().
 *   - Durable uploads: each capture persists to IndexedDB (zcam-queue.js) and
 *     drains in the foreground + via Service Worker Background Sync.
 *   - zcam_save_photo: idempotent on capture_uid, forwards EXIF args, logs
 *     debug server-side instead of to the user.
 *   - Accepts .heic/.heif uploads (upload_mimes).
 * 1.0.0
 *   - Initial release: file-picker capture, preview, save to ZDZ_User_Media.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'ZCAM_VERSION', '1.7.4' );
define( 'ZCAM_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'ZCAM_NONCE', 'zcam_nonce_v1' );

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
			// Widget controller depends on both of the above — and (v1.2.8) on the
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
				// (v1.2.0): iOS strips embedded GPS from any photo taken *through*
				// the browser, so for fresh field captures this is the ONLY source
				// of coordinates for job-by-location matching. It never overwrites
				// real EXIF GPS (e.g. from an imported library photo). Sites that
				// don't want it can disable via the `zcam_geo_fallback` filter.
				'geoFallback' => (bool) apply_filters( 'zcam_geo_fallback', true ),
			] );

			ob_start(); ?>
			<div class="zcam-w" id="zcam-widget">
				<div class="zcam-w-tabs">
					<button class="zcam-w-tab zcam-w-tab-active" data-tab="capture">Take Photo</button>
					<button class="zcam-w-tab" data-tab="gallery">My Photos</button>
				</div>
				<div class="zcam-w-panel" id="zcam-w-tab-capture">
					<input type="file" accept="image/*" capture="environment" id="zcam-w-file" class="zcam-hidden-input" />
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
	add_action( 'wp_ajax_tscam_save_photo', 'zcam_ajax_save_photo' );
	add_action( 'wp_ajax_nopriv_tscam_save_photo', 'zcam_deny_nopriv' );
	add_action( 'wp_ajax_tscam_list_photos', 'zcam_ajax_list_photos' );
	add_action( 'wp_ajax_nopriv_tscam_list_photos', 'zcam_deny_nopriv' );
	add_action( 'wp_ajax_tscam_delete_photo', 'zcam_ajax_delete_photo' );
	add_action( 'wp_ajax_nopriv_tscam_delete_photo', 'zcam_deny_nopriv' );
} );

function zcam_deny_nopriv() { wp_send_json_error( 'Authentication required.', 403 ); }

/* ── Nonce verification (v1.2.8) ─────────────────────────────────────────
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

function zcam_ajax_save_photo() {
	global $wpdb;
	$debug = [];

	// ── Entry log (v1.2.7) ───────────────────────────────────────────────────
	// Records that a save request ARRIVED at PHP, BEFORE the nonce check. The
	// nonce check (check_ajax_referer) calls wp_die() with no log of its own, so
	// a stale/missing nonce previously produced a totally silent failure — which
	// is exactly the "spinner stuck, nothing in the server log" symptom. With
	// this line, the log distinguishes "request never arrived" (no TSCAM line at
	// all → client/transport/proxy) from "arrived but nonce-rejected" (this line
	// present, then a nonce-fail line). Cheap, safe, one line per upload attempt.
	$debug[] = 'entry uid=' . ( isset( $_POST['capture_uid'] ) ? substr( preg_replace( '/[^A-Za-z0-9\-]/', '', (string) $_POST['capture_uid'] ), 0, 40 ) : '-' )
	         . ' files=' . ( isset( $_FILES['photo'] ) ? '1' : '0' )
	         . ' bytes=' . ( isset( $_FILES['photo']['size'] ) ? (int) $_FILES['photo']['size'] : 0 );
	error_log( 'TSCAM save: ' . implode( ' | ', $debug ) );

	// ── Nonce-debug (v1.2.8, the §4 diagnostic) ─────────────────────────────
	// One line per save attempt, logged BEFORE the gate: the uid this request
	// resolved to, plus whether EACH nonce verifies for that uid. Reading it:
	//   uid=0                      → request arrived logged OUT (cookie/session
	//                                not sent or stripped) — no nonce can pass.
	//   uid=<kiosk/General id>     → the kiosk/demo determine_current_user
	//                                switch is in play (handoff hypothesis B).
	//   tscam=false wprest_hdr=1   → legacy nonce stale, fresh wp_rest accepted
	//                                — the v1.2.8 fix path working as designed.
	//   both false, uid logged in  → deeper session-token mismatch (handoff §6).
	// wp_verify_nonce returns 1|2 (valid, by age) or false. Remove this line
	// once a release has been verified clean, if log noise matters.
	$legacy_nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
	error_log( 'TSCAM nonce-debug: uid=' . get_current_user_id()
		. ' tscam=' . var_export( wp_verify_nonce( $legacy_nonce, ZCAM_NONCE ), true )
		. ' wprest_hdr=' . var_export( wp_verify_nonce( zcam_incoming_rest_nonce(), 'wp_rest' ), true ) );

	// Verify WITHOUT the silent wp_die, so a failure is logged and the client
	// gets a clean JSON error it can surface (instead of a hung request).
	// v1.2.8: dual-accept — fresh wp_rest nonce (X-WP-Nonce) OR legacy nonce.
	if ( ! zcam_verify_request_nonce() ) {
		error_log( 'TSCAM save: nonce check FAILED (stale/missing). ' . implode( ' | ', $debug ) );
		wp_send_json_error( 'Your session expired — please reload and try again.', 403 );
	}
	if ( ! is_user_logged_in() ) wp_send_json_error( 'Not logged in.' );
	$debug[] = 'auth:ok user=' . get_current_user_id();

	if ( ! class_exists( 'ZDZ_User_Media' ) ) {
		error_log( 'TSCAM save: ZDZ_User_Media class not found. ' . implode( ' | ', $debug ) );
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
		error_log( 'TSCAM save: no photo file. ' . implode( ' | ', $debug ) );
		wp_send_json_error( 'No photo received. Please try again.' );
	}

	// ── Empty-body guard (v1.2.9) ────────────────────────────────────────
	// A present-but-0-byte file part means the CLIENT streamed zero bytes —
	// the iOS IndexedDB read-back bug (see widget.js 1.2.9), NOT a server
	// upload config problem. Catch it before media_handle_upload so the log
	// gets a truthful line instead of the misleading php.ini-flavored "File
	// is empty" error. The client only marks an item failed-and-retryable on
	// this response, and since 1.2.9 it won't send a body it knows is empty —
	// so this line appearing means bytes were lost IN TRANSIT (new info).
	if ( (int) ( $_FILES['photo']['error'] ?? 0 ) === UPLOAD_ERR_OK
	     && (int) ( $_FILES['photo']['size'] ?? 0 ) === 0 ) {
		error_log( 'TSCAM save: EMPTY file body (0 bytes) — client-side bytes lost. ' . implode( ' | ', $debug ) );
		wp_send_json_error( 'The photo data didn\'t arrive — it will retry automatically.' );
	}
	// v1.7.2: server-side image-type guard (mirrors zdz-media-library). The save
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
		error_log( 'TSCAM save: upload_err ' . $att_id->get_error_message() . ' | ' . implode( ' | ', $debug ) );
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
	$gps_lat     = isset( $_POST['gps_lat'] ) && $_POST['gps_lat'] !== '' ? $_POST['gps_lat'] : '';
	$gps_lng     = isset( $_POST['gps_lng'] ) && $_POST['gps_lng'] !== '' ? $_POST['gps_lng'] : '';
	$geo_source  = isset( $_POST['geo_source'] ) ? sanitize_key( $_POST['geo_source'] ) : '';
	// Capture-time provenance: 'exif' (from the file) or 'device_clock' (the
	// device's local clock at shutter, used when the file carried no EXIF date —
	// the common iOS capture-through-browser case). Independent of geo_source.
	$time_source = isset( $_POST['time_source'] ) ? sanitize_key( $_POST['time_source'] ) : '';

	// Optional per-photo note → description (owner-editable later in Media).
	// Mirrors the Media app's 2000-char cap on description.
	$note = isset( $_POST['note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['note'] ) ) : '';
	if ( $note !== '' && function_exists( 'mb_substr' ) ) {
		$note = mb_substr( $note, 0, 2000 );
	} elseif ( $note !== '' ) {
		$note = substr( $note, 0, 2000 );
	}

	// Privacy: intentionally NOT set here, so ZDZ_User_Media::save() applies its
	// 'private' default. On this platform 'private' already means "the UPLOADER
	// and any ADMIN can view it" — the theme's gated serve() layer (v2.33.0)
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
		'source_ref'       => $capture_uid !== '' ? ( 'photo:uid:' . $capture_uid ) : ( 'photo:' . $att_id ),
		'title'            => $title,
		'description'      => $note,            // photo purpose, set at capture (optional)
		'wp_attachment_id' => $att_id,
		'captured_at'      => $captured_at,   // Tier-1 arg (preferred when present)
		'gps_lat'          => $gps_lat,
		'gps_lng'          => $gps_lng,
	];
	$meta = [];
	if ( $geo_source !== '' )  { $meta['geo_source']  = $geo_source; }
	if ( $time_source !== '' ) { $meta['time_source'] = $time_source; }
	if ( ! empty( $meta ) ) {
		$save_args['meta'] = $meta;
	}

	$media = ZDZ_User_Media::save( $save_args );

	if ( ! $media ) {
		error_log( 'TSCAM save: metadata save failed. last_db_error:' . $wpdb->last_error . ' | ' . implode( ' | ', $debug ) );
		wp_send_json_error( 'Could not save photo details. Please try again.' );
	}

	wp_send_json_success( [ 'media_id' => $media['id'], 'file_url' => $url, 'thumbnail_url' => $thumb ?: $url, 'title' => $media['title'] ] );
}

/**
 * Look up a previously-saved camera photo by its client capture_uid.
 * Used for Background-Sync idempotency. Scans this user's recent camera
 * photos for a source_ref of "photo:uid:<uid>".
 */
function zcam_find_by_capture_uid( int $user_id, string $capture_uid ): ?array {
	if ( $capture_uid === '' || ! class_exists( 'ZDZ_User_Media' ) ) return null;
	$needle = 'photo:uid:' . $capture_uid;
	// v1.7.3: indexed single-row lookup (was a scan of only the 50 newest camera
	// photos — a retried upload whose original was older than that window missed
	// and thus inserted a DUPLICATE). Uses the theme's idx_source_ref getter when
	// available (theme >= 2.36.0); falls back to the bounded scan on an older
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
	// v1.2.8: dual-accept (fresh wp_rest via X-WP-Nonce, OR legacy nonce) and a
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
			'file_url'      => $p['file_url'],
			'source_app'    => $p['source_app'],
			'privacy'       => $p['privacy'],
			'created_at'    => $p['created_at'],
		];
	}, $items );
	wp_send_json_success( [ 'photos' => $out ] );
}

function zcam_ajax_delete_photo() {
	// v1.2.8: dual-accept (fresh wp_rest via X-WP-Nonce, OR legacy nonce) and a
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
	return $mimes;
} );
