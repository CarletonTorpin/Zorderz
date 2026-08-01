/**
 * TS Camera — Dashboard Widget v1.7.2
 *
 * v1.6.1 — FIX: the AE/AF press-and-hold could trigger iOS TEXT SELECTION
 *   (everything tinted blue + the magnifier loupe) because the long-press was
 *   also reaching Safari's default gesture recognizers. Two-layer fix:
 *   user-select/-webkit-touch-callout disabled across the live view (CSS),
 *   and preventDefault() on non-control single-finger touchstart so the
 *   system gesture never starts (controls excluded; viewfinder taps are
 *   touch-driven, so nothing else changes).
 *
 * v1.6.0 — iOS-CAMERA CONTROLS: tap-to-focus/expose, ± exposure compensation,
 *   AE/AF lock. The full native gesture language on the live viewfinder:
 *     • TAP → yellow focus square pops at the point, AF/AE re-meter, the sun
 *       slider appears beside it, any exposure comp resets, any lock clears.
 *     • DRAG vertically (after a tap, or while locked) → ± exposure
 *       compensation; the sun rides the slider. WYSIWYG: the preview and the
 *       CAPTURED JPEG get the same exposure.
 *     • PRESS & HOLD (~550 ms) → AE/AF LOCK pill (iOS-style yellow banner) +
 *       square pulse; comp stays adjustable while locked; next tap unlocks.
 *     • Two-finger pinch still zooms (v1.5.x) — gestures are routed by a
 *       single touch controller and never collide.
 *   Platform reality (researched, June 2026): WebKit ships only zoom/torch/
 *   whiteBalanceMode constraints — focusMode / exposureCompensation /
 *   pointsOfInterest from the Image Capture spec are NOT yet exposed on iOS.
 *   So every control PROBES getCapabilities() first and uses the native
 *   constraint when present (future Safari picks this up automatically with
 *   zero code change); exposure comp otherwise falls back to software gain
 *   (brightness) applied IDENTICALLY to the preview filter and the capture
 *   canvas (ctx.filter when supported, composite blend fallback) — honest
 *   WYSIWYG either way. Tap re-metering leans on iOS's always-on continuous
 *   AF; the lock banner reflects a true hardware lock only where the
 *   constraints exist, else it pins the software exposure.
 *
 * v1.5.1 — SEAMLESS LENS SWITCH + FULL-SCREEN CAPTURE CONFIRMATION.
 *   1. No more black-out when pinching across a lens breakpoint: the current
 *      frame is FROZEN onto an overlay canvas (matching the digital-zoom
 *      transform), the NEW lens stream is acquired BEFORE the old one is
 *      stopped, and the freeze lifts only when the new stream paints its
 *      first frame (with a 1.5s safety). Failure falls back to the previous
 *      lens. The front/back Flip uses the same freeze (front+back can't run
 *      concurrently, so that swap stops first — but the gap is hidden).
 *   2. The shutter is now unmissable: the black blink reveals THE PHOTO JUST
 *      TAKEN at full screen, which holds for a beat and then shrinks toward
 *      the corner thumbnail (native capture idiom, writ large). Driven from
 *      the already-captured canvas — one extra GPU blit, no upload-path
 *      changes; restartable mid-burst.
 *
 * v1.5.0 — PINCH-ZOOM (real lenses) + SHUTTER FEEDBACK. Pinch anywhere on the
 *   viewfinder to zoom: crossing a lens breakpoint switches to the iPhone's
 *   PHYSICAL lens for that range (iOS 16.3+ exposes Back Ultra Wide 0.5× /
 *   Back 1× / Back Telephoto as separate devices — iOS Safari has no `zoom`
 *   track constraint, so lens selection + WYSIWYG digital zoom IS the native
 *   pattern here). Between breakpoints the preview scales and the capture
 *   crops the exact same center region (real sensor pixels, no upscaling).
 *   A yellow zoom chip shows the factor while pinching. Each shutter tap now
 *   fires a native-style 2–3-frame black blink plus a captured-frame
 *   thumbnail pop (roll-style) so one photo == one unmistakable confirmation.
 *   Telephoto factor is assumed 3× (labels don't carry it); localized or
 *   missing labels degrade to single-lens digital zoom; front camera is
 *   digital-only.
 *
 * v1.4.0 — LIVE CAMERA (the camera view STAYS on screen). Replaces v1.3.0's
 *   between-shots overlay same-day on field feedback: on iOS builds that
 *   refuse the auto-relaunch, the overlay ADDED a step per shot. What the
 *   field needs is the actual camera view staying open — shutter, shutter,
 *   shutter — like the native camera app. So Take Photo now opens an IN-PAGE
 *   viewfinder (getUserMedia, rear camera, max available resolution): every
 *   shutter tap grabs a full-resolution frame (canvas → JPEG q0.92), shows a
 *   flash blip, and feeds the EXACT same durable pipeline (note/sticky label,
 *   provenance incl. geolocation fallback, IndexedDB + liveBytes, X-WP-Nonce
 *   upload) while the viewfinder keeps running. A live "N photos · uploading
 *   · saved" counter overlays the view; Flip switches cameras; Done ends the
 *   session. Backgrounding the app kills camera tracks — they re-acquire on
 *   return. EXPLICIT TRADE-OFF (product decision): stream frames (up to ~4K)
 *   are below the native camera's processed stills, accepted for true
 *   continuous shooting — iOS user-activation rules make the native file-
 *   input path one round-trip per shot forever, and Safari/iOS has no
 *   ImageCapture.takePhoto(). Provenance is unchanged either way (iOS strips
 *   EXIF from ALL browser captures; time = device clock, GPS = geolocation
 *   fallback — both paths identical). The system camera remains the
 *   automatic fallback when getUserMedia is unavailable or denied.
 *
 * v1.2.10 — DISCARD for stuck uploads. A failed queue item now has a × control
 *   next to Retry: tap once to arm ("Discard?", auto-disarms in 4s), tap again
 *   to delete the IndexedDB record (+ in-memory bytes) and remove the row.
 *   Records whose stored bytes never read back (iOS flakiness, see 1.2.9)
 *   previously haunted the strip forever with no user-facing way out.
 *
 * v1.2.9 — THE EMPTY-BYTES FIX (the bug the nonce was hiding). With 1.2.8 the
 *   server log shows the nonce path FIXED ("auth:ok user=2", "wprest_hdr=1",
 *   zero nonce failures) and yesterday's day-old queued records uploading at
 *   FULL size — but every NEW capture arrived "bytes=0" ("File is empty"),
 *   consistently across retries. Root cause: iOS Safari's IndexedDB can return
 *   an EMPTY ArrayBuffer when a large, freshly-written record is read straight
 *   back (same WebKit storage-flakiness family as the 1.2.7 Blob bug), while
 *   the same record reads back fine much later. The drain reads records out of
 *   IndexedDB (claim()), so a fresh capture could stream 0 bytes. Fix:
 *     • liveBytes: the EXACT ArrayBuffer written to IndexedDB is kept in
 *       memory (capture_uid → buf) for this session. resolveUploadBlob()
 *       prefers the IndexedDB copy only when it is non-empty AND matches the
 *       recorded original `size`; otherwise it uses the in-memory bytes — so a
 *       first-attempt upload never depends on an IndexedDB round-trip (the
 *       same property that makes Media reliable: it uploads the live File).
 *     • NEVER POST EMPTY: with no usable bytes, the attempt is skipped
 *       (failed → auto-retried by the 30s sweep / Retry / reload) instead of
 *       sending a 0-byte body. The IndexedDB copy provably materializes later
 *       (yesterday's records uploaded perfectly today), so retries can win.
 *     • Entries are forgotten on upload success; map capped at 12 captures.
 *
 * v1.2.8 — THE NONCE FIX. v1.2.7's server entry log PROVED full-res photos now
 *   ARRIVE at PHP — but every request was then rejected by the legacy
 *   ZCAM_NONCE check ("TSCAM save: nonce check FAILED"). That nonce is baked
 *   into widget HTML the SPA re-injects via innerHTML (and NitroPack can serve
 *   from cache), so window.zcamWidget.nonce can belong to a stale session.
 *   The Media app never hits this: its nonce is localized fresh onto a
 *   normally-enqueued global script on every real page load. The camera now
 *   authenticates with that same freshness: the theme's wp_rest nonce
 *   (window.zdzData.nonce, localized on zdz-app-js — now a declared dependency)
 *   is sent as the standard X-WP-Nonce header on EVERY request (uploads, list,
 *   delete, direct fallback). It is captured at shutter onto the queue record
 *   (rest_nonce) so a Service-Worker Background-Sync retry carries it too, and
 *   re-read LIVE from zdzData at send time so even old records get a current
 *   nonce. The legacy `nonce` field is still sent — the server (1.2.8)
 *   dual-accepts, so nothing regresses.
 *
 * v1.2.7 — THE ACTUAL UPLOAD FIX (supersedes the 1.2.6 theory). Evidence from a
 *   server debug.log: with 1.2.6 live, captured photos still hung at
 *   "Uploading…" and the server logged NOTHING for them — i.e. the POST never
 *   completed a round-trip to PHP at all (the handler logs on every failure
 *   path). The Media app uploads the SAME photos fine. The decisive difference:
 *   the camera persisted the original as a Blob in IndexedDB and streamed THAT
 *   to the server, while Media uploads the live picker File directly.
 *   iOS Safari's IndexedDB does NOT reliably store Blob/File values (documented
 *   WebKit limitation) — the stored Blob comes back unreadable/zero-length, so
 *   the multipart body never finishes and the request stalls before reaching
 *   the server. Two changes remove this:
 *     1. zcam-queue.js stores the ORIGINAL **bytes as an ArrayBuffer** (`buf`),
 *        which iOS stores reliably, and rebuilds a byte-identical Blob at upload
 *        time — so ALL EXIF/GPS is preserved exactly (no re-encode).
 *     2. uploadRecord() now uses **XMLHttpRequest** (the same transport the
 *        working Media app uses) instead of fetch(): onload/onerror/ontimeout
 *        ALWAYS fire, so a request can never hang forever, and we get real
 *        upload progress.
 *   The 1.2.6 single-uploader LEASE is kept (it's still correct), as is the
 *   durable queue + Retry + capture_uid idempotency. Legacy ≤1.2.6 Blob records
 *   already in IndexedDB are read via a fallback. No server change.
 *
 * Provenance-first, friction-free capture:
 *   • Tap the Camera icon (shortcut bar / dock / tile) → camera opens directly
 *     via the theme's `zdz_app_launch` intent. One tap, straight to capture.
 *   • Capture uses the device's NATIVE camera file (capture="environment").
 *     The ORIGINAL file is kept — never drawn to canvas / re-encoded — so EXIF
 *     (capture time + GPS) survives. iPhone HEIC is read IN-BROWSER (PHP can't)
 *     and its provenance is sent as explicit args to the server.
 *   • Capture time is ALWAYS recorded as LOCAL wall-clock: from EXIF
 *     DateTimeOriginal when present, else from the device clock at shutter
 *     (time_source = 'exif' | 'device_clock'). Never UTC/toISOString — this is
 *     what keeps the stored time correct for fresh iOS captures (which the OS
 *     strips of EXIF) and conformant to the platform's local-wall-clock rule.
 *   • No "Use Photo" confirm, no separate "Save Photo" click. Choosing the photo
 *     IS the commit.
 *   • Uploads are DURABLE: the original is persisted to IndexedDB the instant
 *     it's chosen, then drained in the foreground and via Service Worker
 *     Background Sync — nothing is lost across reload / crash / offline.
 *
 * Requires: zcam-queue.js (IndexedDB queue) and vendor/exifr.umd.js (EXIF).
 */
(function () {
  'use strict';

  var st = { galLoaded: false, fgBusy: false, geoFallback: false, savedToastShown: false };

  function el(id) { return document.getElementById(id); }
  function cfg() { return window.zcamWidget || {}; }

  // v1.2.8: the theme's always-fresh wp_rest nonce — window.zdzData is localized
  // onto zdz-app-js on every real page load (the same freshness property the
  // working Media app's nonce has). Sent as the standard X-WP-Nonce header.
  // Prefers the value captured at shutter (rec.rest_nonce, which travels with
  // the durable record for SW retries); falls back to reading zdzData LIVE at
  // send time, so even a record queued before zdzData existed — or one that
  // outlived its original session — gets the current session's nonce.
  function restNonce(rec) {
    return (rec && rec.rest_nonce) || (window.zdzData && window.zdzData.nonce) || '';
  }

  /* ── Live bytes (v1.2.9: the empty-bytes fix) ─────────────────────────
   * iOS Safari can return an EMPTY ArrayBuffer when a large, freshly-written
   * IndexedDB record is read straight back — the server kept receiving
   * "bytes=0" for new captures while day-old records uploaded at full size.
   * So we keep the EXACT buffer we just wrote (the one readToBuffer produced)
   * in memory for this session, keyed by capture_uid. Uploads prefer a
   * VERIFIED IndexedDB read-back (non-empty + matches the recorded size) and
   * fall back to these bytes. Forgotten on success; capped to bound memory.
   * After a reload the map is empty — by then the IndexedDB copy reads back
   * fine (proven: yesterday's records uploaded perfectly today). */
  var liveBytes = {};
  var liveOrder = [];
  var LIVE_BYTES_MAX = 12;

  function rememberLiveBytes(uid, buf) {
    if (!uid || !buf || !buf.byteLength) return;
    if (!(uid in liveBytes)) liveOrder.push(uid);
    liveBytes[uid] = buf;
    while (liveOrder.length > LIVE_BYTES_MAX) { delete liveBytes[liveOrder.shift()]; }
  }
  function forgetLiveBytes(uid) {
    if (!uid || !(uid in liveBytes)) return;
    delete liveBytes[uid];
    var i = liveOrder.indexOf(uid);
    if (i >= 0) liveOrder.splice(i, 1);
  }

  // Resolve the bytes to upload for a record, or null to SKIP this attempt
  // (never POST an empty/truncated body — the server can't use it and the
  // misleading "File is empty" error helps no one). Order:
  //   1. IndexedDB read-back, if non-empty AND matching the recorded size;
  //   2. the in-memory bytes captured at shutter (this session only);
  //   3. null → caller marks the attempt failed; the sweep retries later,
  //      when the IndexedDB copy may have materialized.
  function resolveUploadBlob(rec) {
    var live = (rec && rec.capture_uid && liveBytes[rec.capture_uid]) || null;
    var expected = (rec && rec.size) || (live ? live.byteLength : 0);
    var blob = TSCamQueue.bufToBlob(rec);
    if (blob && blob.size > 0 && (!expected || blob.size === expected)) return blob;
    if (live && live.byteLength) {
      if (window.console) console.warn('TSCAM: IndexedDB read-back gave ' +
        (blob ? blob.size : 'no') + ' bytes for ' + rec.capture_uid +
        ' (expected ' + expected + ') — uploading from in-memory bytes');
      try { return new Blob([live], { type: (rec && rec.type) || 'application/octet-stream' }); }
      catch (e) { /* fall through */ }
    }
    if (window.console) console.warn('TSCAM: no usable bytes for ' +
      (rec && rec.capture_uid) + ' this attempt (db=' + (blob ? blob.size : 'none') +
      ', expected=' + expected + ') — skipping; will retry');
    return null;
  }
  function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : s; return d.innerHTML; }

  /* ── Per-photo note ─────────────────────────────────────────────────── */

  function readNote() {
    var n = el('zcam-w-note');
    return n ? (n.value || '').trim() : '';
  }
  function clearNote() {
    var n = el('zcam-w-note');
    if (n) n.value = '';
    var c = el('zcam-w-note-clear');
    if (c) c.hidden = true;
  }

  /* ── Sticky pre-label (orchestrator: "label all my photos as 'before'") ──
     When the Camera is launched with options.prelabel (e.g. from the dashboard
     "ask" field), the note is pre-filled AND re-applied to every shot until the
     user clears it OR the app closes OR a 1-hour safety window elapses — so a
     stale "before" can never silently tag a later, unrelated session. */
  var STICKY_TTL_MS = 60 * 60 * 1000; // 1 hour
  var stickyLabel = { value: '', setAt: 0 };

  function isStickyActive() {
    return stickyLabel.value !== '' && (Date.now() - stickyLabel.setAt) < STICKY_TTL_MS;
  }
  function setStickyLabel(label) {
    label = (label == null ? '' : String(label)).trim().slice(0, 2000);
    if (!label) { return; }
    stickyLabel.value = label;
    stickyLabel.setAt = Date.now();
    var n = el('zcam-w-note');
    if (n) {
      n.value = label;
      var c = el('zcam-w-note-clear');
      if (c) c.hidden = false;
    }
    renderStickyChip();
  }
  function clearStickyLabel(reason) {
    stickyLabel.value = ''; stickyLabel.setAt = 0;
    var chip = el('zcam-w-sticky-chip');
    if (chip) chip.remove();
    if (reason === 'expired') {
      // Quiet, non-blocking note; clearing the field too so nothing carries over.
      clearNote();
    }
  }
  function renderStickyChip() {
    var wrap = el('zcam-w-note-wrap') || (el('zcam-w-note') && el('zcam-w-note').parentNode);
    if (!wrap) { return; }
    var chip = el('zcam-w-sticky-chip');
    if (!chip) {
      chip = document.createElement('div');
      chip.id = 'zcam-w-sticky-chip';
      chip.className = 'zcam-w-sticky-chip';
      chip.setAttribute('role', 'status');
      wrap.parentNode.insertBefore(chip, wrap);
    }
    chip.innerHTML = 'Labeling every photo: <strong>' + esc(stickyLabel.value) + '</strong>' +
      ' <button type="button" class="zcam-w-sticky-clear" aria-label="Stop labeling">Stop</button>';
    var btn = chip.querySelector('.zcam-w-sticky-clear');
    if (btn) btn.addEventListener('click', function () { clearStickyLabel('user'); });
  }

  function timeAgo(ds) {
    if (!ds) return '';
    var d = new Date(String(ds).replace(' ', 'T'));
    if (isNaN(d.getTime())) return '';
    var s = Math.floor((new Date() - d) / 1000);
    if (s < 60) return 'just now'; if (s < 3600) return Math.floor(s / 60) + 'm ago';
    if (s < 86400) return Math.floor(s / 3600) + 'h ago';
    if (s < 604800) return Math.floor(s / 86400) + 'd ago';
    return d.toLocaleDateString();
  }

  function ajaxPost(action, data) {
    var fd = new FormData(); fd.append('action', action); fd.append('nonce', cfg().nonce);
    for (var k in data) if (data.hasOwnProperty(k)) fd.append(k, data[k]);
    // v1.2.8: authenticate with the fresh wp_rest nonce (X-WP-Nonce). The
    // legacy `nonce` field above is kept; the server dual-accepts.
    var headers = {};
    var rn = restNonce(null);
    if (rn) headers['X-WP-Nonce'] = rn;
    return fetch(cfg().ajaxurl, { method: 'POST', body: fd, credentials: 'same-origin', headers: headers }).then(function (r) { return r.json(); });
  }

  /* ── Provenance: read EXIF from the ORIGINAL, in the browser ─────────── */

  function round7(n) { return Math.round(n * 1e7) / 1e7; }

  // Format a JS Date as the platform's storage convention: LOCAL wall-clock,
  // "YYYY-MM-DD HH:MM:SS", with NO timezone suffix and NO UTC conversion. This
  // mirrors how EXIF DateTimeOriginal is defined and how the theme stores it
  // (verbatim, local). Uses local getters only — never toISOString()/getUTC*().
  function toSqlDateTime(d) {
    var p = function (n) { return (n < 10 ? '0' : '') + n; };
    return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate()) + ' ' +
           p(d.getHours()) + ':' + p(d.getMinutes()) + ':' + p(d.getSeconds());
  }

  // Resolve a single capture-time Date from exifr's parsed fields to a LOCAL
  // wall-clock SQL string, defending against the two ways a wrong (UTC-looking)
  // time gets in:
  //   1. We strictly PREFER DateTimeOriginal (the "shutter" time, written local
  //      by virtually all cameras) over CreateDate (which some devices/pipelines
  //      record in UTC). Only fall back to CreateDate if DateTimeOriginal is
  //      absent. exifr revives both into Date objects via its LOCAL parser, so a
  //      well-formed EXIF wall clock round-trips with no shift.
  //   2. exifr may also expose an explicit offset/zone (OffsetTimeOriginal /
  //      tzMin) for files that carried one. When present, the parsed Date is an
  //      absolute instant, and reading it back with LOCAL getters already yields
  //      the device's local wall clock — which is exactly what we want to store.
  // Returns { value: 'YYYY-MM-DD HH:MM:SS', source: 'exif' } or null.
  function resolveExifTime(data) {
    if (!data) return null;
    var dt = (data.DateTimeOriginal instanceof Date && !isNaN(data.DateTimeOriginal.getTime()))
               ? data.DateTimeOriginal
               : ((data.CreateDate instanceof Date && !isNaN(data.CreateDate.getTime()))
                    ? data.CreateDate
                    : null);
    if (!dt) return null;
    return { value: toSqlDateTime(dt), source: 'exif' };
  }

  // exifr reads JPEG/TIFF AND HEIC/HEIF in-browser. This is the only way to
  // preserve iPhone HEIC provenance, since server-side exif_read_data() can't
  // parse HEIC. Best-effort: never throws, never blocks the capture.
  //
  // Capture time is ALWAYS resolved to local wall-clock:
  //   • from EXIF when the file carries a date (time_source = 'exif'); else
  //   • from the DEVICE CLOCK at capture (time_source = 'device_clock').
  // The device-clock fallback matters because iOS strips EXIF (and GPS) from
  // photos taken *through* the browser, so a fresh field capture often has NO
  // EXIF date at all. Without this fallback the client sent nothing, the server
  // could not read a HEIC date either, and captured_at was stored NULL (or, on
  // a JPEG whose EXIF happened to be UTC, a +7h value). Sending the device's
  // LOCAL clock guarantees a correct, present, convention-conforming time and
  // can never be a UTC artifact.
  function readProvenance(file) {
    var out = { captured_at: null, gps_lat: null, gps_lng: null, time_source: null };
    var exifr = window.exifr;
    var finish = function () {
      if (!out.captured_at) {                       // no usable EXIF date →
        out.captured_at = toSqlDateTime(new Date()); // device LOCAL clock
        out.time_source = 'device_clock';
      }
      return out;
    };
    if (!exifr || typeof exifr.parse !== 'function') return Promise.resolve(finish());
    return exifr.parse(file, {
      tiff: true, ifd0: true, exif: true, gps: true,
      pick: ['DateTimeOriginal', 'CreateDate', 'OffsetTimeOriginal',
             'GPSLatitude', 'GPSLongitude', 'GPSLatitudeRef', 'GPSLongitudeRef']
    }).then(function (data) {
      if (data) {
        var t = resolveExifTime(data);
        if (t) { out.captured_at = t.value; out.time_source = t.source; }
        // exifr converts GPS to signed decimal degrees on `latitude`/`longitude`.
        if (typeof data.latitude === 'number')  out.gps_lat = round7(data.latitude);
        if (typeof data.longitude === 'number') out.gps_lng = round7(data.longitude);
      }
      return finish();
    }).catch(function () { return finish(); }); // HEIC parse can fail on odd files — fine.
  }

  // Optional, opt-in: when a photo has NO GPS EXIF, capture the device's
  // current position as a clearly-labeled fallback (never overwrites EXIF GPS).
  function getGeoFallback(timeoutMs) {
    return new Promise(function (resolve) {
      if (!navigator.geolocation) return resolve(null);
      navigator.geolocation.getCurrentPosition(
        function (pos) { resolve({ lat: round7(pos.coords.latitude), lng: round7(pos.coords.longitude) }); },
        function () { resolve(null); },
        { enableHighAccuracy: true, timeout: timeoutMs || 4000, maximumAge: 15000 }
      );
    });
  }

  /* ── Keep the camera widget anchored (CLS / "jumps away on close" fix) ──
   * The native camera is a full-screen OS round-trip: the page is torn down
   * and restored, and the browser can land the user at a stale scroll offset
   * — which felt like being "taken to a different app" after each shot. We
   * re-anchor the camera widget into view when we return from a capture. Uses
   * the theme's own widget container as the scroll target so it works
   * regardless of which sub-view the icon was tapped from. */
  function keepWidgetInView() {
    var w = document.querySelector('.zcam-w');
    if (!w) return;
    var target = w.closest('.dash-widget-container') || w;
    try {
      target.scrollIntoView({ behavior: 'auto', block: 'center' });
    } catch (e) {
      try { target.scrollIntoView(); } catch (e2) {}
    }
  }

  /* ── Live camera (v1.4.0): the camera view STAYS on screen ──────────────
   * Take Photo opens an in-page viewfinder. Every shutter tap queues a photo
   * into the durable pipeline while the viewfinder keeps running — shutter,
   * shutter, shutter, Done. No per-shot round-trip, no confirmation step.
   * The native system camera is the automatic fallback when getUserMedia is
   * unavailable or the user denied camera permission. */

  var live = { open: false, stream: null, facing: 'environment', shots: 0, saved: 0, uids: {}, busy: false };

  /* ── Zoom (v1.5.0): pinch → real lens selection + WYSIWYG digital zoom ──
   * iOS Safari does NOT support the `zoom` track constraint (Android-only),
   * but since iOS 16.3 it DOES expose every physical back lens as its own
   * device ("Back Ultra Wide Camera" 0.5×, "Back Camera" 1×, "Back Telephoto
   * Camera"). So pinch works like the native camera: crossing a lens
   * breakpoint re-acquires the stream from the PROPER LENS; between
   * breakpoints we apply digital zoom that is WYSIWYG — the preview scales
   * (CSS transform) and the capture crops the exact same center region of
   * the frame (real pixels, no upscaling). Telephoto factor is assumed 3×
   * (labels don't carry it; on 2×/5× models the handoff point shifts but
   * zoom stays continuous). Localized/missing labels degrade gracefully to
   * single-lens digital zoom. Front camera: digital only. */

  var zoomSt = { z: 1, minZ: 1, maxZ: 8, digital: 1, lenses: [{ f: 1, deviceId: null }], lensIdx: 0, switching: false, chipTimer: null };

  function resetZoomState() {
    zoomSt.z = 1; zoomSt.digital = 1;
    zoomSt.lenses = [{ f: 1, deviceId: null }];
    zoomSt.lensIdx = 0; zoomSt.minZ = 1; zoomSt.maxZ = 8;
    applyPreviewZoom();
  }

  // Map device labels to lens factors. Only meaningful for the back cameras;
  // virtual combo devices (Dual/Triple) and Desk View are excluded. Requires
  // granted permission for labels to be non-empty (we call this only after a
  // successful acquire, so they are).
  function discoverLenses() {
    if (live.facing !== 'environment' ||
        !(navigator.mediaDevices && navigator.mediaDevices.enumerateDevices)) {
      return Promise.resolve();
    }
    return navigator.mediaDevices.enumerateDevices().then(function (devs) {
      var found = [];
      devs.forEach(function (d) {
        if (d.kind !== 'videoinput') return;
        var L = (d.label || '').toLowerCase();
        if (!L) return;
        if (L.indexOf('front') !== -1 || L.indexOf('desk') !== -1) return;
        if (L.indexOf('dual') !== -1 || L.indexOf('triple') !== -1) return;
        if (L.indexOf('back') === -1 && L.indexOf('rear') === -1) return;
        if (L.indexOf('ultra') !== -1)          found.push({ f: 0.5, deviceId: d.deviceId });
        else if (L.indexOf('telephoto') !== -1) found.push({ f: 3,   deviceId: d.deviceId });
        else                                    found.push({ f: 1,   deviceId: d.deviceId });
      });
      if (found.length) {
        found.sort(function (a, b) { return a.f - b.f; });
        zoomSt.lenses = found;
        // start on the 1× (or closest-below) lens
        zoomSt.lensIdx = 0;
        for (var i = 0; i < found.length; i++) if (found[i].f <= 1) zoomSt.lensIdx = i;
      }
      zoomSt.minZ = zoomSt.lenses[0].f;
      zoomSt.maxZ = Math.min(10, zoomSt.lenses[zoomSt.lenses.length - 1].f * 4);
    }).catch(function () {});
  }

  function applyPreviewZoom() {
    var video = el('zcam-live-video');
    if (video) video.style.transform = (zoomSt.digital > 1.001) ? ('scale(' + zoomSt.digital + ')') : '';
  }

  function showZoomChip() {
    var c = el('zcam-live-zoom');
    if (!c) return;
    var z = zoomSt.z;
    c.textContent = ((z >= 10) ? String(Math.round(z)) : (Math.round(z * 10) / 10).toFixed(1)) + '×';
    c.classList.add('zcam-live-zoom-on');
    if (zoomSt.chipTimer) clearTimeout(zoomSt.chipTimer);
    zoomSt.chipTimer = setTimeout(function () { c.classList.remove('zcam-live-zoom-on'); }, 900);
  }

  /* ── Freeze-frame (v1.5.1): hide the lens-switch gap ────────────────────
   * Re-acquiring a stream blanks the <video> for a beat. Before any swap we
   * paint the CURRENT frame onto an overlay canvas (with the same digital-
   * zoom transform, so it's pixel-continuous with the preview), keep it on
   * top until the new stream renders its first frame, then drop it — the
   * user sees freeze → new lens, never black. */
  var freezeSafety = null;
  function freezeFrame() {
    var video = el('zcam-live-video'), f = el('zcam-live-freeze');
    if (!video || !f || !video.videoWidth) return;
    f.width = video.videoWidth; f.height = video.videoHeight;
    try { f.getContext('2d').drawImage(video, 0, 0); } catch (e) { return; }
    f.style.transform = video.style.transform || '';
    f.style.filter = video.style.filter || ''; // v1.6.0: carry software EV
    f.classList.add('zcam-live-freeze-on');
  }
  function unfreeze() {
    var f = el('zcam-live-freeze');
    if (f) f.classList.remove('zcam-live-freeze-on');
    if (freezeSafety) { clearTimeout(freezeSafety); freezeSafety = null; }
  }
  function unfreezeWhenReady() {
    var video = el('zcam-live-video');
    if (!video) { unfreeze(); return; }
    var done = function () {
      video.removeEventListener('loadeddata', done);
      video.removeEventListener('playing', done);
      // one extra frame so the new stream is actually painting
      setTimeout(unfreeze, 80);
    };
    video.addEventListener('loadeddata', done);
    video.addEventListener('playing', done);
    if (freezeSafety) clearTimeout(freezeSafety);
    freezeSafety = setTimeout(unfreeze, 1500); // safety: never freeze forever
  }

  // Re-acquire from a different physical lens. v1.5.1: SEAMLESS — freeze the
  // current frame, acquire the NEW stream first, only then stop the old one
  // (if iOS kills the old track during acquisition, the freeze hides it).
  // Serialized: pinch keeps moving the digital factor meanwhile, so the
  // gesture never feels stuck.
  function switchLens(idx) {
    if (!zoomSt.lenses[idx] || zoomSt.switching) return;
    zoomSt.switching = true;
    var prevIdx = zoomSt.lensIdx;
    zoomSt.lensIdx = idx;
    freezeFrame();
    var old = live.stream;
    acquireStream().then(function () {
      if (old) old.getTracks().forEach(function (t) { try { t.stop(); } catch (e) {} });
      unfreezeWhenReady();
      zoomSt.switching = false;
      setZoom(zoomSt.z); // recompute digital factor against the new lens
    }).catch(function () {
      // New lens refused — fall back to the previous one (re-acquire in case
      // iOS killed the old track when we asked for the new one).
      zoomSt.lensIdx = prevIdx;
      acquireStream().then(function () {
        if (old && old !== live.stream) old.getTracks().forEach(function (t) { try { t.stop(); } catch (e) {} });
        unfreezeWhenReady();
        zoomSt.switching = false;
        setZoom(zoomSt.z);
      }).catch(function () {
        unfreeze();
        zoomSt.switching = false;
        closeLiveCamera();
      });
    });
  }

  function setZoom(z) {
    z = Math.max(zoomSt.minZ, Math.min(zoomSt.maxZ, z));
    zoomSt.z = z;
    var ls = zoomSt.lenses, want = 0;
    for (var i = 0; i < ls.length; i++) if (ls[i].f <= z * 1.001) want = i;
    if (want !== zoomSt.lensIdx) switchLens(want); // no-op while switching
    var f = ls[Math.min((zoomSt.switching ? zoomSt.lensIdx : want), ls.length - 1)].f;
    zoomSt.digital = Math.max(1, z / f);
    applyPreviewZoom();
    showZoomChip();
    syncZoomUI(); // v1.7.0: keep the persistent pill + slider in step with z
  }

  /* ── v1.7.0: PERSISTENT ZOOM UI ──────────────────────────────────────────
   * Renders an always-visible iOS-style zoom pill + a vertical fine slider so
   * users SEE that zoom exists (the wide-angle-only mistake). Everything below
   * is pure UI on top of the existing setZoom()/zoomSt engine — no capture or
   * lens logic changes. The buttons are derived from the lenses actually
   * discovered for the current facing:
   *   - multi-lens (e.g. .5 / 1 / 3): one button per real lens factor
   *   - single-lens: digital steps (1× / 2× / 4×, clamped to maxZ) so the
   *     control still communicates "you can zoom" everywhere.
   */

  // Build the list of "stops" shown on the pill for the current lens set.
  function zoomStops() {
    var ls = zoomSt.lenses || [];
    var stops = [];
    if (ls.length > 1) {
      // Real optical lenses — one stop each (.5, 1, 3, …), de-duped.
      ls.forEach(function (l) { if (stops.indexOf(l.f) === -1) stops.push(l.f); });
    } else {
      // Single lens → digital steps within range. 1× always; add 2× / 4× /
      // (max) when the range allows, so there are at least two stops to tap.
      var base = (ls[0] && ls[0].f) || 1;
      [base, base * 2, base * 4].forEach(function (s) {
        if (s <= zoomSt.maxZ + 0.001 && stops.indexOf(s) === -1) stops.push(s);
      });
      // Always include the true max as the last stop if it isn't already.
      if (zoomSt.maxZ > (stops[stops.length - 1] || 0) + 0.05) stops.push(zoomSt.maxZ);
    }
    stops.sort(function (a, b) { return a - b; });
    return stops;
  }

  // Format a factor the way iOS does: "1×", ".5", "3×" — whole numbers get a
  // ×, sub-1 shows a leading-dot decimal, others one decimal place.
  function fmtZoom(f, active) {
    var s;
    if (f < 1) s = (Math.round(f * 10) / 10).toString().replace(/^0/, ''); // .5
    else if (Math.abs(f - Math.round(f)) < 0.05) s = String(Math.round(f));
    else s = (Math.round(f * 10) / 10).toFixed(1);
    return s + (active || f >= 1 ? '×' : ''); // × on active/whole stops
  }

  // (Re)render the pill buttons. Called after lenses are discovered.
  function buildZoomUI() {
    var bar = el('zcam-live-zoombar');
    if (!bar) return;
    var stops = zoomStops();
    // A single trivial stop (e.g. a 1×-only webcam) → hide the pill entirely,
    // but keep the slider only if there's actually range to cover.
    var hasRange = zoomSt.maxZ > zoomSt.minZ + 0.05;
    bar.innerHTML = '';
    if (stops.length < 2 && !hasRange) {
      bar.style.display = 'none';
    } else {
      bar.style.display = '';
      stops.forEach(function (f) {
        var b = document.createElement('button');
        b.type = 'button';
        b.className = 'zcam-live-zoom-btn';
        b.setAttribute('data-z', String(f));
        b.setAttribute('aria-label', 'Zoom ' + fmtZoom(f, true));
        b.textContent = fmtZoom(f, false);
        // v1.7.1: handle BOTH touchend and click. The single-finger focus
        // gesture used to swallow the synthetic click (fixed via onControl),
        // but on iOS a tapped <button> inside a preventDefault-heavy view can
        // still miss its click — so we fire on touchend directly and de-dupe
        // so a device that also delivers click doesn't zoom twice.
        (function (btn) {
          var lastTap = 0;
          var go = function (e) {
            var now = Date.now();
            if (now - lastTap < 350) return; // de-dupe touchend→click pair
            lastTap = now;
            if (e) { e.preventDefault(); e.stopPropagation(); }
            setZoom(parseFloat(btn.getAttribute('data-z')));
          };
          btn.addEventListener('touchend', go, { passive: false });
          btn.addEventListener('click', go);
        })(b);
        bar.appendChild(b);
      });
    }
    // Slider only matters when there's a continuous range to traverse.
    var slider = el('zcam-live-zoomslider');
    if (slider) {
      slider.style.display = hasRange ? '' : 'none';
      slider.setAttribute('aria-valuemin', String(zoomSt.minZ));
      slider.setAttribute('aria-valuemax', String(zoomSt.maxZ));
    }
    syncZoomUI();
  }

  // Reflect the current zoom on the pill (highlight nearest stop) and slider
  // (thumb position + fill). Cheap; safe to call every setZoom().
  function syncZoomUI() {
    var bar = el('zcam-live-zoombar');
    if (bar) {
      var btns = bar.querySelectorAll('.zcam-live-zoom-btn');
      // Highlight the highest stop that is <= current z (the "active" lens/step).
      var activeBtn = null;
      btns.forEach(function (b) {
        b.classList.remove('zcam-live-zoom-btn-active');
        var bz = parseFloat(b.getAttribute('data-z'));
        if (bz <= zoomSt.z * 1.001) activeBtn = b;
      });
      if (!activeBtn && btns.length) activeBtn = btns[0];
      if (activeBtn) {
        activeBtn.classList.add('zcam-live-zoom-btn-active');
        // Show the LIVE factor on the active chip when between stops (e.g. 1.7×),
        // otherwise the clean stop label.
        var az = parseFloat(activeBtn.getAttribute('data-z'));
        activeBtn.textContent = (Math.abs(zoomSt.z - az) > 0.05)
          ? fmtZoom(zoomSt.z, true) : fmtZoom(az, true);
        // Reset the non-active chips back to their clean stop labels.
        btns.forEach(function (b) {
          if (b !== activeBtn) b.textContent = fmtZoom(parseFloat(b.getAttribute('data-z')), false);
        });
      }
    }
    // Slider thumb/fill: map z within [minZ, maxZ] to 0..1 of the track height.
    var slider = el('zcam-live-zoomslider');
    var thumb = el('zcam-live-zoomslider-thumb');
    var fill = el('zcam-live-zoomslider-fill');
    if (slider && thumb) {
      var span = Math.max(0.0001, zoomSt.maxZ - zoomSt.minZ);
      var frac = Math.max(0, Math.min(1, (zoomSt.z - zoomSt.minZ) / span));
      // Track has 8px insets top & bottom (see CSS). Bottom = min zoom.
      var h = slider.clientHeight - 16;
      var yFromBottom = 8 + frac * h;
      thumb.style.bottom = yFromBottom + 'px';
      thumb.style.top = 'auto';
      if (fill) fill.style.height = (frac * h) + 'px';
      slider.setAttribute('aria-valuenow', (Math.round(zoomSt.z * 10) / 10).toString());
    }
  }

  // Drag handler for the vertical slider. Pointer events cover touch + mouse.
  function bindZoomSlider() {
    var slider = el('zcam-live-zoomslider');
    if (!slider || slider._tscamBound) return;
    slider._tscamBound = true;
    var dragging = false;
    function zoomFromEvent(clientY) {
      var r = slider.getBoundingClientRect();
      var h = r.height - 16;          // match CSS insets
      var top = r.top + 8;
      // Top of track = max zoom, bottom = min zoom.
      var frac = 1 - Math.max(0, Math.min(1, (clientY - top) / h));
      setZoom(zoomSt.minZ + frac * (zoomSt.maxZ - zoomSt.minZ));
    }
    slider.addEventListener('pointerdown', function (e) {
      dragging = true;
      try { slider.setPointerCapture(e.pointerId); } catch (x) {}
      zoomFromEvent(e.clientY); e.preventDefault();
    });
    slider.addEventListener('pointermove', function (e) {
      if (dragging) { zoomFromEvent(e.clientY); e.preventDefault(); }
    });
    function end() { dragging = false; }
    slider.addEventListener('pointerup', end);
    slider.addEventListener('pointercancel', end);
  }

  // One-time hint so users discover the zoom controls the first time they open
  // the live camera. Persisted in localStorage (best-effort; if storage is
  // blocked it simply shows each session — harmless).
  function maybeShowZoomHint() {
    var hint = el('zcam-live-zoomhint');
    if (!hint) return;
    var seen = false;
    try { seen = localStorage.getItem('zcam_zoom_hint_seen') === '1'; } catch (e) {}
    if (seen) return;
    hint.classList.add('zcam-live-zoomhint-on');
    setTimeout(function () { hint.classList.remove('zcam-live-zoomhint-on'); }, 2600);
    try { localStorage.setItem('zcam_zoom_hint_seen', '1'); } catch (e) {}
  }

  /* ── Focus / exposure / lock (v1.6.0) ───────────────────────────────────
   * The iOS camera gesture language. Native constraints are PROBED first
   * (focusMode / exposureCompensation / pointsOfInterest — not shipped in
   * WebKit as of mid-2026, but the probe means a future Safari upgrades us
   * automatically); exposure comp falls back to software gain applied
   * identically to the preview and the captured JPEG (WYSIWYG). */

  var EV_MAX = 1.25; // ± range, ≈ EV stops
  var expoSt = { ev: 0, locked: false, nativeEv: false, uiTimer: null, lastX: 0, lastY: 0 };

  // One-time probe: does canvas 2D support ctx.filter (for capture gain)?
  var canvasFilterOK = (function () {
    try {
      var t = document.createElement('canvas').getContext('2d');
      if (!t || !('filter' in t)) return false;
      t.filter = 'brightness(1.5)';
      return String(t.filter).indexOf('bright') !== -1;
    } catch (e) { return false; }
  })();

  function videoTrack() {
    return (live.stream && live.stream.getVideoTracks()[0]) || null;
  }
  function trackCaps() {
    var t = videoTrack();
    if (!t || !t.getCapabilities) return {};
    try { return t.getCapabilities() || {}; } catch (e) { return {}; }
  }

  // Apply the current exposure compensation. Native constraint when the
  // device exposes a range; otherwise software gain (preview filter now,
  // identical gain baked into the capture in liveShutter()).
  function applyEv() {
    var t = videoTrack();
    var caps = trackCaps();
    var video = el('zcam-live-video');
    var range = caps.exposureCompensation;
    if (t && range && typeof range.min === 'number' && typeof range.max === 'number' && range.max > range.min) {
      var mid = (range.min + range.max) / 2;
      var val = mid + (expoSt.ev / EV_MAX) * ((range.max - range.min) / 2);
      expoSt.nativeEv = true;
      if (video) video.style.filter = '';
      t.applyConstraints({ advanced: [{ exposureMode: 'continuous', exposureCompensation: val }] })
        .catch(function () { expoSt.nativeEv = false; applySoftwareEv(); });
    } else {
      expoSt.nativeEv = false;
      applySoftwareEv();
    }
    positionEvSun();
  }
  function applySoftwareEv() {
    var video = el('zcam-live-video');
    if (!video) return;
    var g = Math.pow(2, expoSt.ev);
    video.style.filter = (Math.abs(expoSt.ev) > 0.02) ? ('brightness(' + g.toFixed(3) + ')') : '';
  }

  // Re-meter focus/exposure at a normalized point. iOS Safari ignores these
  // today (its continuous AF still re-meters on scene change); when WebKit
  // ships pointsOfInterest this starts steering the hardware, zero changes.
  function applyFocusPoint(nx, ny) {
    var t = videoTrack();
    if (!t || !t.applyConstraints) return;
    t.applyConstraints({ advanced: [{
      pointsOfInterest: [{ x: Math.min(1, Math.max(0, nx)), y: Math.min(1, Math.max(0, ny)) }],
      focusMode: 'continuous',
      exposureMode: 'continuous'
    }] }).catch(function () {
      t.applyConstraints({ advanced: [{ focusMode: 'continuous' }] }).catch(function () {});
    });
  }

  function attemptNativeLock() {
    var t = videoTrack();
    if (!t || !t.applyConstraints) return;
    var caps = trackCaps();
    var st = (t.getSettings && t.getSettings()) || {};
    var adv = {};
    if (caps.focusMode && caps.focusMode.indexOf) {
      if (caps.focusMode.indexOf('manual') !== -1 && typeof st.focusDistance === 'number') {
        adv.focusMode = 'manual'; adv.focusDistance = st.focusDistance;
      } else if (caps.focusMode.indexOf('single-shot') !== -1) {
        adv.focusMode = 'single-shot';
      }
    }
    if (caps.exposureMode && caps.exposureMode.indexOf && caps.exposureMode.indexOf('manual') !== -1 && typeof st.exposureTime === 'number') {
      adv.exposureMode = 'manual'; adv.exposureTime = st.exposureTime;
    }
    if (adv.focusMode || adv.exposureMode) {
      t.applyConstraints({ advanced: [adv] }).catch(function () {});
    }
  }
  function releaseNativeLock() {
    var t = videoTrack();
    if (!t || !t.applyConstraints) return;
    t.applyConstraints({ advanced: [{ focusMode: 'continuous', exposureMode: 'continuous' }] }).catch(function () {});
  }

  /* Focus square + sun slider UI */
  function showFocusUI(x, y, pulse) {
    var box = el('zcam-live-focus');
    if (!box) return;
    expoSt.lastX = x; expoSt.lastY = y;
    var W = window.innerWidth, H = window.innerHeight;
    x = Math.min(W - 70, Math.max(70, x));
    y = Math.min(H - 130, Math.max(110, y));
    box.style.left = x + 'px';
    box.style.top = y + 'px';
    // slider flips to the left side near the right edge (iOS behavior)
    box.classList.toggle('zcam-live-focus-flip', x > W - 120);
    box.classList.remove('zcam-live-focus-on', 'zcam-live-focus-pulse');
    void box.offsetWidth;
    box.classList.add('zcam-live-focus-on');
    if (pulse) box.classList.add('zcam-live-focus-pulse');
    positionEvSun();
    scheduleFocusFade();
  }
  function scheduleFocusFade() {
    if (expoSt.uiTimer) clearTimeout(expoSt.uiTimer);
    expoSt.uiTimer = setTimeout(function () {
      var box = el('zcam-live-focus');
      if (box) box.classList.remove('zcam-live-focus-on', 'zcam-live-focus-pulse');
    }, expoSt.locked ? 1800 : 2600);
  }
  function positionEvSun() {
    var sun = el('zcam-live-ev-sun');
    if (!sun) return;
    // track is 110px tall; ev=+max → top, ev=−max → bottom
    var frac = (EV_MAX - expoSt.ev) / (2 * EV_MAX);
    sun.style.top = Math.round(frac * 86) + 'px'; // 110 - sun height margin
  }
  function setLocked(on) {
    expoSt.locked = on;
    var pill = el('zcam-live-lock');
    if (pill) pill.classList.toggle('zcam-live-lock-on', on);
    if (on) attemptNativeLock(); else releaseNativeLock();
  }

  function resetExposureState() {
    expoSt.ev = 0; expoSt.locked = false; expoSt.nativeEv = false;
    if (expoSt.uiTimer) { clearTimeout(expoSt.uiTimer); expoSt.uiTimer = null; }
    var video = el('zcam-live-video');
    if (video) video.style.filter = '';
    var box = el('zcam-live-focus');
    if (box) box.classList.remove('zcam-live-focus-on', 'zcam-live-focus-pulse');
    var pill = el('zcam-live-lock');
    if (pill) pill.classList.remove('zcam-live-lock-on');
  }

  /* ── Unified touch controller ──────────────────────────────────────────
   * Routes: 2 fingers → pinch zoom · quick tap → focus/expose point ·
   * press-and-hold → AE/AF lock · vertical drag (after tap / while locked)
   * → exposure compensation. Touches on the buttons are ignored entirely. */
  var pinch = { on: false, d0: 0, z0: 1 };
  var tch = { mode: null, x0: 0, y0: 0, t0: 0, lpTimer: null, evStart: 0 };

  function touchDist(t) {
    var dx = t[0].clientX - t[1].clientX, dy = t[0].clientY - t[1].clientY;
    return Math.sqrt(dx * dx + dy * dy);
  }
  function onControl(target) {
    // v1.7.1: the zoom pill + slider (v1.7.0) must count as controls, or the
    // single-finger focus/exposure handler calls preventDefault() on their
    // touchstart and swallows the button's synthetic `click` (the slider
    // worked only because it uses pointer events). Including them here lets
    // taps reach the zoom buttons and keeps a slider drag from also dropping
    // a focus point underneath it.
    return !!(target && target.closest &&
      target.closest('.zcam-live-controls, .zcam-live-btn, .zcam-live-shutter, .zcam-live-zoombar, .zcam-live-zoom-btn, .zcam-live-zoomslider'));
  }
  function cancelLongPress() {
    if (tch.lpTimer) { clearTimeout(tch.lpTimer); tch.lpTimer = null; }
  }
  function focusUiActive() {
    var box = el('zcam-live-focus');
    return expoSt.locked || !!(box && box.classList.contains('zcam-live-focus-on'));
  }

  function onPinchStart(e) {
    if (!e.touches) return;
    if (e.touches.length === 2) {
      cancelLongPress();
      tch.mode = 'pinch';
      pinch.on = true; pinch.d0 = touchDist(e.touches); pinch.z0 = zoomSt.z;
      e.preventDefault();
      return;
    }
    if (e.touches.length === 1) {
      if (onControl(e.target)) { tch.mode = null; return; }
      // v1.6.1: stop iOS from starting TEXT SELECTION / the magnifier loupe
      // on press-and-hold (the AE/AF gesture was tinting the whole view
      // blue). Safe here: controls are excluded above and the viewfinder's
      // own tap handling is touch-based, not click-based.
      e.preventDefault();
      tch.mode = 'maybe-tap';
      tch.x0 = e.touches[0].clientX; tch.y0 = e.touches[0].clientY; tch.t0 = Date.now();
      cancelLongPress();
      tch.lpTimer = setTimeout(function () {
        // press & hold → AE/AF LOCK at the held point
        tch.mode = 'held';
        setLocked(true);
        showFocusUI(tch.x0, tch.y0, true);
      }, 550);
    }
  }
  function onPinchMove(e) {
    if (!e.touches) return;
    if (pinch.on && e.touches.length === 2) {
      e.preventDefault();
      if (pinch.d0 > 0) setZoom(pinch.z0 * (touchDist(e.touches) / pinch.d0));
      return;
    }
    if (e.touches.length === 1 && tch.mode) {
      var dx = e.touches[0].clientX - tch.x0;
      var dy = e.touches[0].clientY - tch.y0;
      var dist = Math.sqrt(dx * dx + dy * dy);
      if (tch.mode === 'maybe-tap' && dist > 12) {
        cancelLongPress();
        if (focusUiActive() && Math.abs(dy) > Math.abs(dx)) {
          tch.mode = 'ev-drag';
          tch.evStart = expoSt.ev;
          showFocusUI(expoSt.lastX, expoSt.lastY, false); // re-show if fading
        } else {
          tch.mode = 'ignore';
        }
      }
      if ((tch.mode === 'ev-drag' || tch.mode === 'held') && Math.abs(dy) > 4) {
        if (tch.mode === 'held') { tch.mode = 'ev-drag'; tch.evStart = expoSt.ev; }
        e.preventDefault();
        // drag UP = brighter (iOS); ~140px per EV stop
        var ev = tch.evStart + (-dy / 140) * 1.0;
        expoSt.ev = Math.max(-EV_MAX, Math.min(EV_MAX, ev));
        applyEv();
        scheduleFocusFade();
      }
    }
  }
  function onPinchEnd(e) {
    var remaining = (e.touches && e.touches.length) || 0;
    if (remaining < 2) pinch.on = false;
    if (remaining === 0) {
      cancelLongPress();
      if (tch.mode === 'maybe-tap' && (Date.now() - tch.t0) < 450) {
        // TAP: new focus/exposure point — clears lock, resets comp (iOS)
        if (expoSt.locked) setLocked(false);
        expoSt.ev = 0;
        applyEv();
        showFocusUI(tch.x0, tch.y0, false);
        applyFocusPoint(tch.x0 / window.innerWidth, tch.y0 / window.innerHeight);
      }
      tch.mode = null;
    }
  }

  function liveView() {
    var v = el('zcam-live');
    if (v) return v;
    v = document.createElement('div');
    v.id = 'zcam-live';
    v.className = 'zcam-live';
    v.setAttribute('role', 'dialog');
    v.setAttribute('aria-label', 'Camera');
    v.innerHTML =
      '<video id="zcam-live-video" autoplay muted playsinline webkit-playsinline></video>' +
      '<canvas id="zcam-live-freeze" class="zcam-live-cover"></canvas>' +
      '<canvas id="zcam-live-still" class="zcam-live-cover"></canvas>' +
      '<div class="zcam-live-blink" id="zcam-live-blink"></div>' +
      '<div class="zcam-live-top">' +
        '<div class="zcam-live-status" id="zcam-live-status" aria-live="polite"></div>' +
        '<div class="zcam-live-note" id="zcam-live-note"></div>' +
      '</div>' +
      '<div class="zcam-live-zoom" id="zcam-live-zoom"></div>' +
      '<div class="zcam-live-lock" id="zcam-live-lock">AE/AF LOCK</div>' +
      '<div class="zcam-live-focus" id="zcam-live-focus">' +
        '<div class="zcam-live-focus-box"></div>' +
        '<div class="zcam-live-ev"><div class="zcam-live-ev-track"></div><div class="zcam-live-ev-sun" id="zcam-live-ev-sun">&#9728;</div></div>' +
      '</div>' +
      '<div class="zcam-live-thumb" id="zcam-live-thumb"><img id="zcam-live-thumb-img" alt="" /></div>' +
      // v1.7.0: one-time discoverability hint + persistent zoom pill +
      // vertical fine-zoom slider. The pill's buttons are filled in by
      // buildZoomUI() once the lenses for the current facing are known.
      '<div class="zcam-live-zoomhint" id="zcam-live-zoomhint">Pinch or tap to zoom</div>' +
      '<div class="zcam-live-zoombar" id="zcam-live-zoombar" role="group" aria-label="Zoom level"></div>' +
      '<div class="zcam-live-zoomslider" id="zcam-live-zoomslider" aria-label="Zoom" role="slider" aria-valuemin="1" aria-valuemax="1" aria-valuenow="1">' +
        '<div class="zcam-live-zoomslider-cap zcam-live-zoomslider-cap-top">+</div>' +
        '<div class="zcam-live-zoomslider-track">' +
          '<div class="zcam-live-zoomslider-fill" id="zcam-live-zoomslider-fill"></div>' +
        '</div>' +
        '<div class="zcam-live-zoomslider-thumb" id="zcam-live-zoomslider-thumb"></div>' +
        '<div class="zcam-live-zoomslider-cap zcam-live-zoomslider-cap-bot">&minus;</div>' +
      '</div>' +
      '<div class="zcam-live-controls">' +
        '<button type="button" class="zcam-live-btn" id="zcam-live-flip" aria-label="Switch camera">Flip</button>' +
        '<button type="button" class="zcam-live-shutter" id="zcam-live-shutter" aria-label="Take photo"></button>' +
        '<button type="button" class="zcam-live-btn" id="zcam-live-done">Done</button>' +
      '</div>';
    document.body.appendChild(v);
    el('zcam-live-shutter').addEventListener('click', liveShutter);
    el('zcam-live-done').addEventListener('click', closeLiveCamera);
    el('zcam-live-flip').addEventListener('click', flipLiveCamera);
    // v1.5.0: pinch-to-zoom (two-finger; single taps pass through untouched)
    v.addEventListener('touchstart', onPinchStart, { passive: false });
    v.addEventListener('touchmove',  onPinchMove,  { passive: false });
    v.addEventListener('touchend',   onPinchEnd,   { passive: false });
    v.addEventListener('touchcancel', onPinchEnd,  { passive: false });
    bindZoomSlider(); // v1.7.0: wire the vertical fine-zoom slider drag
    return v;
  }

  function updateLiveStatus() {
    var s = el('zcam-live-status');
    if (!s) return;
    var uploading = Math.max(0, live.shots - live.saved);
    s.innerHTML =
      live.shots + ' photo' + (live.shots === 1 ? '' : 's') + ' · ' +
      (uploading > 0 ? '<span class="zcam-live-up">' + uploading + ' uploading…</span> ' : '') +
      '<span class="zcam-live-saved">' + live.saved + ' saved</span>';
    var n = el('zcam-live-note');
    if (n) {
      var note = readNote();
      n.textContent = note ? ('Label: ' + note) : '';
      n.style.display = note ? '' : 'none';
    }
  }

  // v1.5.0: native-style SHUTTER BLINK — a fast black blip (like the iOS
  // camera). v1.5.1 pairs it with the full-screen captured-still below.
  function shutterBlink() {
    var f = el('zcam-live-blink');
    if (!f) return;
    f.classList.remove('zcam-live-blink-on');
    // restart the animation even on rapid sequential shots
    void f.offsetWidth;
    f.classList.add('zcam-live-blink-on');
  }

  // v1.5.1: FULL-SCREEN capture confirmation. The shutter blink reveals the
  // PHOTO THAT WAS JUST TAKEN, full screen, which holds for a beat and then
  // shrinks away toward the corner thumbnail — the same idiom as the native
  // camera's capture animation, but bigger and unmissable. Driven entirely
  // from the already-captured canvas, so it costs one GPU blit.
  var stillTimer = null;
  function showCapturedStill(srcCanvas) {
    var s = el('zcam-live-still');
    if (!s || !srcCanvas || !srcCanvas.width) return;
    s.width = srcCanvas.width; s.height = srcCanvas.height;
    try { s.getContext('2d').drawImage(srcCanvas, 0, 0); } catch (e) { return; }
    s.classList.remove('zcam-live-still-on');
    void s.offsetWidth; // restart cleanly on rapid sequential shots
    s.classList.add('zcam-live-still-on');
    if (stillTimer) clearTimeout(stillTimer);
    stillTimer = setTimeout(function () { s.classList.remove('zcam-live-still-on'); }, 750);
  }

  // v1.5.0: captured-frame thumbnail pop (bottom-left) — second confirmation
  // signal, mirroring the native camera's roll preview.
  var thumbTimers = { hide: null, revoke: null };
  function popThumb(blob) {
    var box = el('zcam-live-thumb'), img = el('zcam-live-thumb-img');
    if (!box || !img) return;
    var url = '';
    try { url = URL.createObjectURL(blob); } catch (e) { return; }
    if (thumbTimers.hide) clearTimeout(thumbTimers.hide);
    if (thumbTimers.revoke) clearTimeout(thumbTimers.revoke);
    var old = img.src;
    img.src = url;
    if (old && old.indexOf('blob:') === 0) { try { URL.revokeObjectURL(old); } catch (e2) {} }
    box.classList.add('zcam-live-thumb-on');
    thumbTimers.hide = setTimeout(function () { box.classList.remove('zcam-live-thumb-on'); }, 1200);
    thumbTimers.revoke = setTimeout(function () {
      if (img.src === url) { img.removeAttribute('src'); }
      try { URL.revokeObjectURL(url); } catch (e3) {}
    }, 1600);
  }

  // (Re)acquire the camera stream — from the SPECIFIC physical lens when one
  // is selected (v1.5.0 zoom), else by facing mode — at the highest
  // resolution the device will give a video track.
  function acquireStream() {
    var lens = (live.facing === 'environment') ? zoomSt.lenses[zoomSt.lensIdx] : null;
    var vc = (lens && lens.deviceId)
      ? { deviceId: { exact: lens.deviceId }, width: { ideal: 4032 }, height: { ideal: 3024 } }
      : { facingMode: { ideal: live.facing }, width: { ideal: 4032 }, height: { ideal: 3024 } };
    return navigator.mediaDevices.getUserMedia({ audio: false, video: vc }).then(function (stream) {
      live.stream = stream;
      var video = el('zcam-live-video');
      if (video) {
        video.srcObject = stream;
        var p = video.play();
        if (p && p.catch) p.catch(function () {});
      }
      applyPreviewZoom(); // keep the digital factor across re-acquires
      return stream;
    });
  }

  function openLiveCamera() {
    if (live.open) return;
    if (!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia)) {
      openNativeCamera(); // ancient engine → system camera
      return;
    }
    var v = liveView();
    resetZoomState();
    resetExposureState(); // v1.6.0: fresh session = neutral exposure, no lock
    acquireStream().then(function () {
      live.open = true;
      live.shots = 0; live.saved = 0; live.uids = {};
      v.classList.add('zcam-live-open');
      updateLiveStatus();
      // v1.5.0: map the back lenses for zoom breakpoints; v1.7.0: once known,
      // build the persistent zoom pill/slider and show the one-time hint.
      discoverLenses().then(function () { buildZoomUI(); maybeShowZoomHint(); });
    }).catch(function (err) {
      // Denied / unavailable / in use → system camera fallback. Retried on
      // every Take Photo tap, so granting permission later self-heals.
      if (window.console) console.warn('TSCAM: live camera unavailable (' + (err && err.name) + ') — using system camera');
      openNativeCamera();
    });
  }

  function closeLiveCamera() {
    live.open = false;
    if (live.stream) {
      live.stream.getTracks().forEach(function (t) { try { t.stop(); } catch (e) {} });
      live.stream = null;
    }
    var video = el('zcam-live-video');
    if (video) { try { video.srcObject = null; } catch (e) {} }
    unfreeze(); // v1.5.1: never leave a stale freeze/still up for next open
    var still = el('zcam-live-still');
    if (still) still.classList.remove('zcam-live-still-on');
    resetExposureState(); // v1.6.0: clear EV filter / focus UI / lock pill
    var v = el('zcam-live');
    if (v) v.classList.remove('zcam-live-open');
    keepWidgetInView(); // back to the widget; the queue strip has the detail
  }

  function flipLiveCamera() {
    live.facing = (live.facing === 'environment') ? 'user' : 'environment';
    freezeFrame(); // v1.5.1: hide the front/back swap behind the last frame
    if (live.stream) {
      // front+back can't run concurrently — stop first for the flip case
      live.stream.getTracks().forEach(function (t) { try { t.stop(); } catch (e) {} });
      live.stream = null;
    }
    resetZoomState(); // v1.5.0: zoom is per-facing; front = digital only
    resetExposureState(); // v1.6.0: new scene → neutral exposure, no lock
    acquireStream().then(function () {
      unfreezeWhenReady();
      // v1.7.0: rebuild the zoom pill for the new facing (front = digital only,
      // so it'll show digital steps; back = the discovered optical lenses).
      discoverLenses().then(function () { buildZoomUI(); });
    }).catch(function () { unfreeze(); closeLiveCamera(); });
  }

  // Shutter: grab the current frame and feed it to the standard ingest
  // pipeline. The viewfinder keeps running throughout. v1.5.0: when digital
  // zoom is active the capture crops the SAME center region the preview
  // shows (real pixels, no upscaling) — WYSIWYG.
  function liveShutter() {
    if (live.busy) return;
    var video = el('zcam-live-video');
    if (!video || !video.videoWidth) return; // stream not ready yet
    live.busy = true;
    shutterBlink();
    var D = Math.max(1, zoomSt.digital || 1);
    var sw = Math.round(video.videoWidth / D);
    var sh = Math.round(video.videoHeight / D);
    var sx = Math.round((video.videoWidth - sw) / 2);
    var sy = Math.round((video.videoHeight - sh) / 2);
    var c = document.createElement('canvas');
    c.width = sw;
    c.height = sh;
    try {
      var ctx = c.getContext('2d');
      // v1.6.0: bake SOFTWARE exposure comp into the capture so the JPEG
      // matches the preview exactly (native exposureCompensation, when the
      // device supports it, is already in the sensor stream — no baking).
      var g = (!expoSt.nativeEv && Math.abs(expoSt.ev) > 0.02) ? Math.pow(2, expoSt.ev) : 1;
      if (g !== 1 && canvasFilterOK) {
        ctx.filter = 'brightness(' + g.toFixed(3) + ')';
        ctx.drawImage(video, sx, sy, sw, sh, 0, 0, sw, sh);
        ctx.filter = 'none';
      } else {
        ctx.drawImage(video, sx, sy, sw, sh, 0, 0, sw, sh);
        if (g > 1.02) {        // approximate brighten: screen-blend gray
          ctx.globalCompositeOperation = 'screen';
          var k = Math.min(200, Math.round((g - 1) * 160));
          ctx.fillStyle = 'rgb(' + k + ',' + k + ',' + k + ')';
          ctx.fillRect(0, 0, sw, sh);
          ctx.globalCompositeOperation = 'source-over';
        } else if (g < 0.98) { // approximate darken: multiply-blend gray
          ctx.globalCompositeOperation = 'multiply';
          var k2 = Math.round(255 * Math.max(0.45, g));
          ctx.fillStyle = 'rgb(' + k2 + ',' + k2 + ',' + k2 + ')';
          ctx.fillRect(0, 0, sw, sh);
          ctx.globalCompositeOperation = 'source-over';
        }
      }
    } catch (e) { live.busy = false; return; }
    showCapturedStill(c); // v1.5.1: full-screen "this is the photo you took"
    if (!c.toBlob) { live.busy = false; return; } // (Safari 11+ has toBlob)
    c.toBlob(function (blob) {
      live.busy = false;
      if (!blob || !blob.size) {
        if (window.console) console.warn('TSCAM: empty frame — try again');
        return;
      }
      popThumb(blob); // v1.5.0: roll-style confirmation
      var name = 'photo-' + Date.now() + '.jpg';
      var file;
      try { file = new File([blob], name, { type: 'image/jpeg' }); }
      catch (e2) { file = blob; try { file.name = name; } catch (e3) {} }
      live.shots++;
      updateLiveStatus();
      ingestCapture(file);
    }, 'image/jpeg', 0.92);
  }

  /* ── Capture entry points ───────────────────────────────────────────── */

  function openNativeCamera() {
    var input = el('zcam-w-file');
    if (input) input.click();
  }

  function triggerCapture() {
    openLiveCamera(); // v1.4.0: live viewfinder first; system camera fallback
  }

  function onPhotoChosen(e) {
    var files = e.target.files;
    if (!files || !files.length) return;
    var file = files[0];
    e.target.value = ''; // allow re-pick of the same file

    // After the native-camera round-trip the Take Photo button (or the hidden
    // file input) typically keeps :focus, so the theme's global
    // `button:focus-visible` box-shadow lingers as a stray ring/line on the
    // button. Drop focus on return so the button reads clean between shots.
    var cap = el('zcam-w-capture');
    if (cap && typeof cap.blur === 'function') cap.blur();
    if (e.target && typeof e.target.blur === 'function') e.target.blur();

    // We've just returned from the full-screen native camera (fallback path).
    // Re-anchor the widget so the user lands back on it, not a stale offset.
    keepWidgetInView();

    ingestCapture(file);
  }

  // Shared ingest for BOTH capture paths — the in-page live camera (v1.4.0)
  // and the native file-input fallback. Reads the note/sticky label, resolves
  // provenance, persists durably (IndexedDB + liveBytes), renders the queue
  // item, and kicks the background upload. The single entry point into the
  // proven v1.2.x pipeline.
  function ingestCapture(file) {
    // Grab the note typed before this shot. Normally we clear the field so a
    // one-off note doesn't stick to the next, unrelated photo — BUT if a sticky
    // pre-label is active (and not expired), we re-apply it so every shot in the
    // session carries it. An expired sticky clears itself with a quiet note.
    var note = readNote();
    if (isStickyActive()) {
      note = stickyLabel.value;        // authoritative while active
      // leave the field populated for the next shot (re-assert in case edited)
      var nEl = el('zcam-w-note'); if (nEl) nEl.value = stickyLabel.value;
    } else {
      if (stickyLabel.value !== '') { clearStickyLabel('expired'); }
      clearNote();
    }

    readProvenance(file).then(function (prov) {
      if (prov.gps_lat == null && st.geoFallback) {
        return getGeoFallback(4000).then(function (g) {
          if (g) { prov.gps_lat = g.lat; prov.gps_lng = g.lng; prov.geo_source = 'device_fallback'; }
          return prov;
        });
      }
      // GPS that came straight off the file's EXIF is 'exif'-sourced.
      if (prov.gps_lat != null && !prov.geo_source) prov.geo_source = 'exif';
      return prov;
    }).then(function (prov) {
      // geo_source describes the GPS provenance; time_source describes the
      // capture-time provenance ('exif' vs 'device_clock'). They are independent
      // and both feed the inspector's provenance display.
      var meta = {};
      if (prov.geo_source)  meta.geo_source  = prov.geo_source;
      if (prov.time_source) meta.time_source = prov.time_source;
      // Persist to IndexedDB — the capture is now DURABLE. The note travels
      // with the record so a Background-Sync retry still carries it.
      // v1.2.8: also capture the theme's fresh wp_rest nonce (zdzData.nonce) at
      // shutter — it rides the record (rest_nonce) so the Service Worker, which
      // has no window.zdzData, can authenticate a Background-Sync retry too.
      return TSCamQueue.add(file, prov, meta, {
        ajaxurl: cfg().ajaxurl,
        nonce: cfg().nonce,
        rest_nonce: (window.zdzData && window.zdzData.nonce) || '',
        note: note
      });
    }).then(function (rec) {
      // v1.2.9: rec.buf is the in-memory ArrayBuffer add() just wrote (NOT a
      // DB read-back). Keep it for this session so the upload can't be
      // defeated by iOS returning an empty buffer for a fresh record.
      rememberLiveBytes(rec.capture_uid, rec.buf);
      // v1.4.0: session bookkeeping — count this capture's save when it lands.
      if (live.open) { live.uids[rec.capture_uid] = 1; updateLiveStatus(); }
      var thumbUrl = '';
      // Use the live picker File for the optimistic thumb (still in scope, most
      // reliable). The durable bytes live in rec.buf for the actual upload.
      try { thumbUrl = URL.createObjectURL(file); } catch (e2) {}
      renderQueueItem(rec, thumbUrl);   // optimistic thumb + "Uploading…"
      pumpForeground();                 // try now
      registerBackgroundSync();         // safety net even if the app closes
    }).catch(function (err) {
      // IndexedDB unavailable (rare). Fall back to a direct one-shot upload so
      // the user is never blocked — provenance + note still sent, just not durable.
      directUploadFallback(file, note);
      if (window.console) console.warn('TSCAM queue unavailable, direct upload:', err && err.message);
    });
  }

  /* ── Foreground drain (runs while a TS tab is open) ─────────────────── */

  // v1.2.9: the caller resolves (and verifies) the upload bytes first and
  // passes them in — this form NEVER carries an unverified IndexedDB read.
  function buildUploadForm(rec, blob) {
    var fd = new FormData();
    fd.append('action', 'zcam_save_photo');
    fd.append('nonce', rec.nonce || cfg().nonce);
    fd.append('photo', blob, rec.filename);       // ORIGINAL bytes → EXIF preserved
    fd.append('capture_uid', rec.capture_uid);    // server-side idempotency
    if (rec.captured_at) fd.append('captured_at', rec.captured_at);
    if (rec.gps_lat != null) fd.append('gps_lat', rec.gps_lat);
    if (rec.gps_lng != null) fd.append('gps_lng', rec.gps_lng);
    if (rec.meta && rec.meta.geo_source) fd.append('geo_source', rec.meta.geo_source);
    if (rec.meta && rec.meta.time_source) fd.append('time_source', rec.meta.time_source);
    if (rec.note) fd.append('note', rec.note);    // photo purpose (optional)
    return fd;
  }

  // Upload one record via XMLHttpRequest — the SAME transport the Media app uses
  // successfully. We moved off fetch() deliberately: fetch gives no upload
  // progress and, combined with a Blob streamed out of IndexedDB, was part of the
  // path that hung with no server log. XHR's onload/onerror/ontimeout ALWAYS
  // fire (even on odd HTTP statuses or a stalled body), so a request can never
  // sit forever — it resolves, errors, or times out. Resolves with resp.data on
  // success; rejects otherwise. capture_uid keeps any retry idempotent.
  var UPLOAD_TIMEOUT_MS = 45000; // generous for a big HEIC on a slow site link
  function uploadRecord(rec) {
    return new Promise(function (resolve, reject) {
      // v1.2.9: verified bytes only (IndexedDB read-back checked against the
      // recorded size, in-memory fallback) — never POST an empty body.
      var blob = resolveUploadBlob(rec);
      if (!blob) { reject(new Error('no-bytes')); return; } // skip; sweep retries
      var fd;
      try { fd = buildUploadForm(rec, blob); }
      catch (e) { reject(e); return; }

      var xhr = new XMLHttpRequest();
      xhr.open('POST', rec.ajaxurl || cfg().ajaxurl, true);
      xhr.withCredentials = true;                 // send the auth cookie (parity w/ Media)
      xhr.timeout = UPLOAD_TIMEOUT_MS;            // hard stop → ontimeout fires
      // v1.2.8: fresh wp_rest auth via X-WP-Nonce (legacy nonce field is still
      // in the form body as a server-side fallback — dual-accept).
      var rn = restNonce(rec);
      if (rn) xhr.setRequestHeader('X-WP-Nonce', rn);
      if (xhr.upload) {
        xhr.upload.onprogress = function (e) {
          if (e.lengthComputable) updateQueueItemProgress(rec.id, Math.round((e.loaded / e.total) * 100));
        };
      }
      xhr.onload = function () {
        var resp = null;
        try { resp = JSON.parse(xhr.responseText); } catch (err) { resp = null; }
        if (resp && resp.success) { resolve(resp.data); }
        else {
          reject(new Error(resp && resp.data ? String(resp.data) : ('Failed (' + xhr.status + ')')));
        }
      };
      xhr.onerror   = function () { reject(new Error('network')); };
      xhr.ontimeout = function () { reject(new Error('timeout')); };
      xhr.send(fd);
    });
  }

  // An item is "stranded" if it's been in 'uploading' longer than this — i.e. a
  // previous attempt's fetch hung and never settled (e.g. the tab was closed mid-
  // upload, or a pre-timeout build left it stuck). We reclaim it as 'failed' so it
  // re-enters the retry flow instead of spinning forever. Must exceed the upload
  // timeout so we never reclaim an attempt that's legitimately still in flight.
  var STUCK_UPLOAD_MS = 90000;
  // Foreground lease lifetime. Must EXCEED UPLOAD_TIMEOUT_MS (45s) so a request
  // that is legitimately still in flight never has its lease expire out from
  // under it, yet stay UNDER STUCK_UPLOAD_MS (90s) so a genuinely dead attempt
  // is still reclaimable. 60s sits cleanly between the two.
  var FG_LEASE_MS = 60000;
  function pumpForeground() {
    if (st.fgBusy) return Promise.resolve();
    st.fgBusy = true;
    var now = Date.now();
    return TSCamQueue.all().then(function (items) {
      // Recover stranded 'uploading' items first (self-heal stuck spinners).
      // Only reclaim ones whose LEASE has ALSO expired — an item another drain
      // is legitimately still working (live lease) is left alone even if it has
      // been 'uploading' for a while.
      var stranded = items.filter(function (r) {
        return r.status === 'uploading' &&
               (now - (r.updated_at || r.created_at || now)) > STUCK_UPLOAD_MS &&
               !(r.lease_until && r.lease_until > now);
      });
      return stranded.reduce(function (chain, rec) {
        return chain.then(function () {
          return TSCamQueue.release(rec.id, { status: 'failed' }).then(function () {
            markQueueItemFailed(rec.id); // ensure a Retry affordance exists
          });
        });
      }, Promise.resolve()).then(function () { return items; });
    }).then(function (items) {
      var queue = items
        .filter(function (r) {
          if (r.status !== 'pending' && r.status !== 'failed') return false;
          // Skip anything the Service Worker is actively uploading (a live lease
          // it owns). This is the page half of the single-uploader guarantee: we
          // never start a second POST for a record the SW already holds.
          if (TSCamQueue.isLeasedByOther(r, 'fg')) return false;
          return true;
        })
        .sort(function (a, b) { return a.created_at - b.created_at; });

      // Sequential to avoid hammering a weak shop connection.
      return queue.reduce(function (chain, rec) {
        return chain.then(function () {
          // CLAIM the record (atomic read-modify-write). If we don't win — another
          // drain grabbed it between our scan and now — skip it this pass.
          return TSCamQueue.claim(rec.id, 'fg', FG_LEASE_MS).then(function (claimed) {
            if (!claimed) return; // lost the race; leave it to the lease holder
            return uploadRecord(claimed).then(function (data) {
              return TSCamQueue.remove(claimed.id).then(function () {
                forgetLiveBytes(claimed.capture_uid); // v1.2.9: saved — release the bytes
                markQueueItemDone(claimed.id, data);
                st.galLoaded = false; // gallery is now stale
                // v1.3.0: carry the capture_uid so the session counter can
                // attribute this save to the current shooting session.
                if (data && typeof data === 'object') data._capture_uid = claimed.capture_uid;
                document.dispatchEvent(new CustomEvent('zcam_photo_saved', { detail: data }));
              });
            }).catch(function () {
              // Release the lease AND mark failed in ONE write, so a retry path
              // (online / visibility / 30s / Retry / Background Sync) can pick it
              // straight back up. capture_uid keeps that retry idempotent.
              return TSCamQueue.release(claimed.id, { status: 'failed', tries: (claimed.tries || 0) + 1 })
                .then(function () { markQueueItemFailed(claimed.id); });
            });
          });
        });
      }, Promise.resolve());
    }).then(function () { st.fgBusy = false; })
      .catch(function () { st.fgBusy = false; });
  }

  function registerBackgroundSync() {
    if (!('serviceWorker' in navigator)) return;
    navigator.serviceWorker.ready.then(function (reg) {
      if (reg && 'sync' in reg) {
        reg.sync.register(TSCamQueue.SYNC_TAG).catch(function () { /* fg covers it */ });
      }
      // No SyncManager (iOS Safari): the foreground drain is the path.
    }).catch(function () {});
  }

  // IndexedDB-less fallback: one-shot upload, no persistence.
  function directUploadFallback(file, note) {
    readProvenance(file).then(function (prov) {
      var finish = function () {
        var fd = new FormData();
        fd.append('action', 'zcam_save_photo');
        fd.append('nonce', cfg().nonce);
        fd.append('photo', file, file.name || ('photo-' + Date.now() + '.jpg'));
        // captured_at is now always present (EXIF or device-clock fallback).
        if (prov.captured_at) fd.append('captured_at', prov.captured_at);
        if (prov.gps_lat != null) fd.append('gps_lat', prov.gps_lat);
        if (prov.gps_lng != null) fd.append('gps_lng', prov.gps_lng);
        if (prov.geo_source) fd.append('geo_source', prov.geo_source);
        if (prov.time_source) fd.append('time_source', prov.time_source);
        if (note) fd.append('note', note);
        var tmp = { id: 'tmp' + Date.now() };
        var thumb = ''; try { thumb = URL.createObjectURL(file); } catch (e) {}
        renderQueueItem({ id: tmp.id }, thumb);
        // XHR here too (parity with the durable path + the Media app).
        var xhr = new XMLHttpRequest();
        xhr.open('POST', cfg().ajaxurl, true);
        xhr.withCredentials = true;
        xhr.timeout = UPLOAD_TIMEOUT_MS;
        // v1.2.8: fresh wp_rest auth via X-WP-Nonce (dual-accept server-side).
        var rn = restNonce(null);
        if (rn) xhr.setRequestHeader('X-WP-Nonce', rn);
        if (xhr.upload) {
          xhr.upload.onprogress = function (e) {
            if (e.lengthComputable) updateQueueItemProgress(tmp.id, Math.round((e.loaded / e.total) * 100));
          };
        }
        xhr.onload = function () {
          var resp = null; try { resp = JSON.parse(xhr.responseText); } catch (e) { resp = null; }
          if (resp && resp.success) { markQueueItemDone(tmp.id, resp.data); st.galLoaded = false; }
          else markQueueItemFailed(tmp.id);
        };
        xhr.onerror   = function () { markQueueItemFailed(tmp.id); };
        xhr.ontimeout = function () { markQueueItemFailed(tmp.id); };
        xhr.send(fd);
      };
      // Honour geo-fallback here too so direct-upload captures are still geotagged.
      if (prov.gps_lat == null && st.geoFallback) {
        getGeoFallback(4000).then(function (g) {
          if (g) { prov.gps_lat = g.lat; prov.gps_lng = g.lng; prov.geo_source = 'device_fallback'; }
          finish();
        });
      } else {
        if (prov.gps_lat != null && !prov.geo_source) prov.geo_source = 'exif';
        finish();
      }
    });
  }

  /* ── Upload status strip (non-blocking) ─────────────────────────────── */

  function queueStrip() {
    var strip = el('zcam-w-queue');
    if (!strip) return null;
    // No display toggling: the strip always occupies its reserved space (CSS
    // min-height), so adding/removing items never changes the widget's height
    // and the dashboard never shifts under the user (the CLS fix).
    return strip;
  }

  function renderQueueItem(rec, thumbUrl) {
    var strip = queueStrip(); if (!strip) return;
    var id = 'zcam-q-' + rec.id;
    if (el(id)) return; // already shown (e.g. resumed on load)
    var item = document.createElement('div');
    item.className = 'zcam-w-q-item'; item.id = id;
    item.innerHTML =
      '<div class="zcam-w-q-thumb">' + (thumbUrl ? '<img src="' + esc(thumbUrl) + '" alt="" />' : '') + '</div>' +
      '<div class="zcam-w-q-state"><span class="zcam-w-spinner"></span><span class="zcam-w-q-label">Uploading…</span></div>';
    strip.insertBefore(item, strip.firstChild);
    // Status-aware rehydration: an item restored from IndexedDB in a 'failed'
    // state must show its Retry affordance immediately, not a spinner that only
    // corrects later. (Fresh captures are 'pending' → spinner is right.)
    if (rec && rec.status === 'failed') markQueueItemFailed(rec.id);
  }

  // Reflect upload progress in the item's label ("Uploading… 42%"). Cosmetic and
  // shift-free (it only swaps the label text). Driven by xhr.upload.onprogress.
  function updateQueueItemProgress(id, pct) {
    var item = el('zcam-q-' + id); if (!item) return;
    var label = item.querySelector('.zcam-w-q-label');
    if (label && pct >= 0 && pct < 100) label.textContent = 'Uploading… ' + pct + '%';
  }

  function markQueueItemDone(id, data) {
    var item = el('zcam-q-' + id); if (!item) return;
    var thumb = item.querySelector('.zcam-w-q-thumb img');
    if (thumb && data && data.thumbnail_url) thumb.src = data.thumbnail_url;
    var state = item.querySelector('.zcam-w-q-state');
    if (state) state.innerHTML = '<span class="zcam-w-q-done">\u2713</span><span class="zcam-w-q-label">Saved to Media</span>';
    item.classList.add('zcam-w-q-item-done');

    // One-time, non-blocking confirmation that photos land in the Media library,
    // so the user knows where they live and that other apps can reach them.
    // (Skip on dedupe — a Background-Sync re-delivery isn't a new save.)
    if (!(data && data.duplicate) && !st.savedToastShown) {
      st.savedToastShown = true;
      if (typeof window.showToast === 'function') {
        window.showToast('Saved to your Media library');
      }
    }

    setTimeout(function () {
      if (!item.parentNode) return;
      item.style.opacity = '0';
      setTimeout(function () { if (item.parentNode) item.parentNode.removeChild(item); }, 300);
    }, 2200);
  }

  // Remove a queue item's row from the strip with the same fade the
  // success path uses.
  function removeQueueItemRow(item) {
    if (!item || !item.parentNode) return;
    item.style.opacity = '0';
    setTimeout(function () { if (item.parentNode) item.parentNode.removeChild(item); }, 300);
  }

  function markQueueItemFailed(id) {
    var item = el('zcam-q-' + id); if (!item) return;
    var state = item.querySelector('.zcam-w-q-state');
    if (state) {
      // v1.2.10: a failed item can now be DISCARDED by the user, not only
      // retried. Records whose stored bytes never read back (the iOS empty-
      // bytes flakiness) used to sit in the queue forever, re-rendered on
      // every load with no way out short of clearing site data. Discard is
      // two-tap (arm → confirm) because for a never-uploaded photo this
      // deletes the ONLY copy.
      state.innerHTML = '<span class="zcam-w-q-label zcam-w-q-fail">Upload failed</span>' +
                        '<button type="button" class="zcam-w-q-retry">Retry</button>' +
                        '<button type="button" class="zcam-w-q-discard" aria-label="Discard this photo">&times;</button>';
      var btn = state.querySelector('.zcam-w-q-retry');
      if (btn) btn.addEventListener('click', function () {
        // `id` here is the raw IndexedDB record id passed into this function.
        var realId = (typeof id === 'number') ? id : parseInt(id, 10);
        if (!isNaN(realId)) {
          TSCamQueue.update(realId, { status: 'pending' }).then(function () {
            state.innerHTML = '<span class="zcam-w-spinner"></span><span class="zcam-w-q-label">Uploading…</span>';
            pumpForeground();
          });
        }
      });
      var del = state.querySelector('.zcam-w-q-discard');
      if (del) del.addEventListener('click', function () {
        // First tap arms; second tap (within 4s) actually discards.
        if (!del.dataset.armed) {
          del.dataset.armed = '1';
          del.textContent = 'Discard?';
          del.classList.add('zcam-w-q-discard-armed');
          setTimeout(function () {
            if (!del.parentNode) return;
            delete del.dataset.armed;
            del.innerHTML = '&times;';
            del.classList.remove('zcam-w-q-discard-armed');
          }, 4000);
          return;
        }
        var realId = (typeof id === 'number') ? id : parseInt(id, 10);
        if (isNaN(realId)) { removeQueueItemRow(item); return; } // tmp (non-DB) row
        TSCamQueue.get(realId).then(function (rec) {
          if (rec && rec.capture_uid) forgetLiveBytes(rec.capture_uid);
          return TSCamQueue.remove(realId);
        }).then(function () {
          removeQueueItemRow(item);
        }).catch(function () {
          removeQueueItemRow(item); // row goes regardless; worst case the
          // record resurfaces on next load and can be discarded again
        });
      });
    }
    item.classList.add('zcam-w-q-item-failed');
  }

  /* ── Gallery (recent photos) ────────────────────────────────────────── */

  function loadGallery() {
    var gal = el('zcam-w-gallery'); if (!gal) return;
    gal.innerHTML = '<div class="zcam-w-loading">Loading photos\u2026</div>';
    ajaxPost('zcam_list_photos', {})
      .then(function (resp) {
        if (!resp.success) { gal.innerHTML = '<div class="zcam-w-gallery-empty">Failed to load.</div>'; return; }
        var photos = (resp.data && resp.data.photos) || [];
        if (!photos.length) {
          gal.innerHTML = '<div class="zcam-w-gallery-empty">No photos yet. Take a photo to get started!</div>';
          st.galLoaded = true; return;
        }
        gal.innerHTML = '';
        photos.forEach(function (p) {
          var src = p.source_app === 'zdz-sketch-pad' ? 'Sketch' : 'Camera';
          var item = document.createElement('div'); item.className = 'zcam-w-gallery-item';
          var noteHtml = (p.description && p.description.trim())
            ? '<div class="zcam-w-gallery-note">' + esc(p.description) + '</div>' : '';
          item.innerHTML =
            '<img class="zcam-w-gallery-thumb" src="' + esc(p.thumbnail_url) + '" alt="' + esc(p.title) + '" loading="lazy" />' +
            '<div class="zcam-w-gallery-info"><div class="zcam-w-gallery-title">' + esc(p.title) + '</div>' +
            noteHtml +
            '<div class="zcam-w-gallery-meta"><span>' + timeAgo(p.created_at) + '</span><span class="zcam-w-badge">' + src + '</span></div></div>' +
            '<div class="zcam-w-gallery-actions">' +
            '<a class="zcam-w-btn zcam-w-btn-sm zcam-w-gal-view" href="' + esc(p.file_url) + '" target="_blank" rel="noopener">View</a>' +
            '<button class="zcam-w-btn zcam-w-btn-sm zcam-w-gal-del" data-id="' + p.id + '" style="color:#DC2626;">Delete</button></div>';
          gal.appendChild(item);
        });
        gal.querySelectorAll('.zcam-w-gal-del').forEach(function (btn) {
          btn.addEventListener('click', function () {
            var id = this.dataset.id, row = this.closest('.zcam-w-gallery-item');
            ajaxPost('zcam_delete_photo', { media_id: id }).then(function (resp) {
              if (resp.success && row) { row.style.opacity = '0'; setTimeout(function () { if (row.parentNode) row.parentNode.removeChild(row); }, 200); }
            });
          });
        });
        st.galLoaded = true;
      })
      .catch(function () { gal.innerHTML = '<div class="zcam-w-gallery-empty">Error loading photos.</div>'; });
  }

  /* ── Tabs ───────────────────────────────────────────────────────────── */

  function initTabs() {
    var tabs = document.querySelectorAll('#zcam-widget .zcam-w-tab');
    tabs.forEach(function (tab) {
      tab.addEventListener('click', function () {
        tabs.forEach(function (t) { t.classList.remove('zcam-w-tab-active'); });
        tab.classList.add('zcam-w-tab-active');
        var target = tab.getAttribute('data-tab');
        document.querySelectorAll('#zcam-widget .zcam-w-panel').forEach(function (p) {
          p.style.display = p.id === 'zcam-w-tab-' + target ? '' : 'none';
        });
        if (target === 'gallery' && !st.galLoaded) loadGallery();
      });
    });
  }

  /* ── Resume any unfinished uploads from a previous session ──────────── */

  function resumeQueue() {
    if (!window.TSCamQueue) return;
    TSCamQueue.all().then(function (items) {
      var now = Date.now();
      // On a fresh load there is NO in-flight foreground request, so any record
      // still marked 'uploading' is a leftover from a previous session (crash /
      // tab-close mid-upload). Reset those to 'pending' so they upload again —
      // EXCEPT any the Service Worker is genuinely draining right now (a live
      // lease it owns); leave those for the SW. Clearing the stale state here is
      // what lets a fresh failed/orphaned record show its spinner→retry honestly
      // instead of waiting out the 90s stuck-sweep.
      var orphans = items.filter(function (r) {
        return r.status === 'uploading' && !(r.lease_until && r.lease_until > now && r.lease_owner === 'sw');
      });
      return orphans.reduce(function (chain, r) {
        return chain.then(function () { return TSCamQueue.release(r.id, { status: 'pending' }); });
      }, Promise.resolve()).then(function () { return items; });
    }).then(function (items) {
      items.filter(function (r) { return r.status !== 'done'; })
           .sort(function (a, b) { return a.created_at - b.created_at; })
           .forEach(function (r) {
             // Rebuild a thumb from the stored bytes (buf → Blob; legacy file as fallback).
             var thumb = ''; try { var b = TSCamQueue.bufToBlob(r); if (b) thumb = URL.createObjectURL(b); } catch (e) {}
             renderQueueItem(r, thumb);
           });
      pumpForeground();
    }).catch(function () {});
  }

  /* ── Init ───────────────────────────────────────────────────────────── */

  function init() {
    var w = document.querySelector('.zcam-w'); if (!w) return;
    if (w.dataset.init === '1') return; w.dataset.init = '1';

    // Optional geo fallback flag (set by PHP localize if enabled).
    st.geoFallback = !!(cfg().geoFallback);

    initTabs();

    var fileInput = el('zcam-w-file');
    if (fileInput) fileInput.addEventListener('change', onPhotoChosen);
    // v1.4.0: live-view counter — attribute saves to the current shooting
    // session (saves of older queued records don't move the counter).
    document.addEventListener('zcam_photo_saved', function (e) {
      var uid = e.detail && e.detail._capture_uid;
      if (uid && live.uids[uid]) {
        live.saved++;
        if (live.open) updateLiveStatus();
      }
    });

    var captureBtn = el('zcam-w-capture');
    if (captureBtn) captureBtn.addEventListener('click', triggerCapture);

    // Note field: toggle the little clear (×) button as the user types.
    var noteInput = el('zcam-w-note');
    var noteClear = el('zcam-w-note-clear');
    if (noteInput && noteClear) {
      noteInput.addEventListener('input', function () {
        noteClear.hidden = noteInput.value.length === 0;
      });
      noteClear.addEventListener('click', function () {
        clearNote();
        noteInput.focus();
      });
    }

    resumeQueue();
  }

  // Retry triggers that work even WITHOUT Background Sync (covers iOS Safari):
  window.addEventListener('online', function () { pumpForeground(); });
  document.addEventListener('visibilitychange', function () {
    if (document.visibilityState === 'visible') {
      pumpForeground();
      // v1.4.0: iOS stops camera tracks when the app is backgrounded —
      // re-acquire the stream if the live view is open with a dead track.
      if (live.open) {
        var dead = !live.stream || live.stream.getVideoTracks().every(function (t) { return t.readyState === 'ended'; });
        if (dead) acquireStream().catch(function () { closeLiveCamera(); });
      }
    }
  });
  // Light periodic nudge for failed/pending items while the app is open.
  setInterval(function () { if (navigator.onLine) pumpForeground(); }, 30000);

  if (document.querySelector('.zcam-w')) init();
  document.addEventListener('zdz_widgets_rendered', init, { once: true });
  document.addEventListener('DOMContentLoaded', function () {
    setTimeout(function () { if (!document.querySelector('.zcam-w[data-init="1"]')) init(); }, 300);
  });

  // ── Launch intent (theme Bridge v3.2) ────────────────────────────────
  // Tapping the Camera icon dispatches `zdz_app_launch`. We claim it and open
  // the native camera immediately — one tap to capture, no dashboard scroll.
  document.addEventListener('zdz_app_launch', function (e) {
    if (!e.detail || e.detail.appId !== 'zdz-camera') return;
    e.preventDefault();
    if (!document.querySelector('.zcam-w[data-init="1"]')) init();
    // Orchestrator: a pre-label ("label all my photos as 'before'") arrives as
    // options.prelabel. Apply it as a sticky label BEFORE the first capture so
    // shot #1 is already tagged.
    var opts = e.detail.options || {};
    var pre = opts.prelabel || opts.label || '';
    if (pre) { setStickyLabel(pre); }
    triggerCapture();
  });
})();
