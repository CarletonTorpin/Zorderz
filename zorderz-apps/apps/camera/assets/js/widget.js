/**
 * Zorderz Camera — Dashboard Widget
 *
 * A live in-page viewfinder that stays open between shots, with the durable upload pipeline behind it:
 *
 *   - Permissions are asked one at a time, when needed: the camera when it opens; motion at the first shutter
 *     press (iOS only lets a tap ask for it; the screen's own rotation covers the shot until then); location after
 *     the first photo; the microphone only in Video. A recorded "no" is remembered per person and not re-asked;
 *     a quiet chip says where the switch is.
 *   - Orientation: photos AND video are saved upright however the phone is held. The device's rotation (screen
 *     orientation, fused with gravity for the inverted case iOS does not report) rotates the capture canvas; the
 *     preview matches; the controls follow the physical bottom edge and stay clear of the camera island. A
 *     read-out of the orientation state is one gesture away (?zcamori=1, localStorage zcam_ori_debug=1, or a
 *     triple-tap on the counter).
 *   - Zoom: pinch, a pill of real lens stops and a fine slider; tap to focus/expose, drag for exposure, press and
 *     hold for an AE/AF lock (native constraints where the browser has them, matched software gain otherwise).
 *   - Each shot: a 70 ms white flash and a haptic, then the captured photo full-screen for a beat.
 *   - Video: a [Photo | Video] toggle, push to record / push to stop, a 2:00 cap with a 30-seconds-left warning;
 *     chunks stream to a durable IndexedDB queue and upload while recording.
 *   - The live camera is a layer over the page with its own history entry: Back closes the camera, not the app; a
 *     stream that ends while the page is showing closes the layer with a message.
 *   - Uploads: queued in IndexedDB (ArrayBuffer bytes, size-checked on read-back) the moment a photo is chosen,
 *     then drained in the foreground and via Service Worker Background Sync — nothing is lost across reload /
 *     crash / offline. Failed uploads get one summary line: Retry all, or Discard (two taps).
 *
 * Requires: zcam-queue.js (IndexedDB queue) and vendor/exifr.umd.js (EXIF).
 */
(function () {
  'use strict';

  var st = { galLoaded: false, fgBusy: false, geoFallback: false, savedToastShown: false };

  function el(id) { return document.getElementById(id); }
  function cfg() { return window.zcamWidget || {}; }

  // the theme's always-fresh wp_rest nonce — window.zdzData is localized
  // onto zdz-app-js on every real page load (the same freshness property the
  // working Media app's nonce has). Sent as the standard X-WP-Nonce header.
  // Prefers the value captured at shutter (rec.rest_nonce, which travels with
  // the durable record for SW retries); falls back to reading zdzData LIVE at
  // send time, so even a record queued before zdzData existed — or one that
  // outlived its original session — gets the current session's nonce.
  function restNonce(rec) {
    return (rec && rec.rest_nonce) || (window.zdzData && window.zdzData.nonce) || '';
  }

  /* ── Live bytes ─────────────────────────
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
    var blob = ZCamQueue.bufToBlob(rec);
    if (blob && blob.size > 0 && (!expected || blob.size === expected)) return blob;
    if (live && live.byteLength) {
      if (window.console) console.warn('ZCAM: IndexedDB read-back gave ' +
        (blob ? blob.size : 'no') + ' bytes for ' + rec.capture_uid +
        ' (expected ' + expected + ') — uploading from in-memory bytes');
      try { return new Blob([live], { type: (rec && rec.type) || 'application/octet-stream' }); }
      catch (e) { /* fall through */ }
    }
    if (window.console) console.warn('ZCAM: no usable bytes for ' +
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

  /* - JOB CONTEXT (plumbing only).
   * When the Camera is launched FROM a job, the launcher passes options.job_id.
   * That is a deliberate human assertion about which job these photos belong
   * to -- no geometry, no confidence tier, no guessing -- so downstream it
   * outranks any inferred geo match. Stored the same way the sticky label is,
   * with the same TTL: an in-memory value that does not survive a reload, so
   * the launcher must re-send it each time. It is deliberately NOT persisted;
   * a stale job id silently tagging tomorrow's photos would be far worse than
   * having no id at all.
   * The button that SENDS this lives in the Jobs app and is not built yet. */
  var jobCtx = { id: 0, setAt: 0 };
  function jobContext() {
    if (!jobCtx.id) return 0;
    if ((Date.now() - jobCtx.setAt) >= STICKY_TTL_MS) { jobCtx.id = 0; return 0; }
    return jobCtx.id;
  }
  function setJobContext(id) {
    id = parseInt(id, 10) || 0;
    if (id <= 0) return;
    jobCtx.id = id; jobCtx.setAt = Date.now();
  }

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
    // authenticate with the fresh wp_rest nonce (X-WP-Nonce). The
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

  /* == v1.8.0: GEOLOCATION, REBUILT ==========================================
   * When a photo has NO GPS EXIF -- which on iOS is EVERY photo taken through
   * the browser, because the browser strips it -- we take the device's own
   * position as a clearly-labelled fallback. It never overwrites real EXIF GPS.
   *
   * What was wrong before v1.8.0, in order of how much it cost:
   *
   *  1. The fix was requested AFTER the shutter, with a 4s timeout, and it sat
   *     in the promise chain IN FRONT of ZCamQueue.add(). So every shot stalled
   *     up to 4 seconds before the bytes reached IndexedDB, and during that
   *     window the photo existed only in a JS closure -- background the app and
   *     it was GONE. That contradicted this module's own contract ("persisted
   *     the instant the shutter fires, BEFORE any network call") and, in a
   *     burst, serialised: ten shots was ~40s of exposure.
   *  2. A cold high-accuracy GPS fix on a phone that just woke up routinely
   *     takes longer than 4 seconds, so the request began at the worst possible
   *     moment and then gave up early.
   *  3. The error callback was `function () { resolve(null); }` -- it threw away
   *     the PERMISSION_DENIED code, so nobody could tell "denied" from "timed
   *     out", and a user who denied once was invisible forever.
   *  4. pos.coords.accuracy was in scope and simply never read.
   *
   * Now: we PREWARM at capture time (while the user is still framing the shot),
   * cache the fix briefly, queue the photo IMMEDIATELY, and patch the
   * coordinates onto the durable record when they arrive.
   * ========================================================================= */

  var GEO_TIMEOUT_MS  = 10000;  // no longer on the critical path, so it can wait
  var GEO_FRESH_MS    = 120000; // how long a prewarmed fix stays usable
  var geoFix = null;            // { lat, lng, acc, at }
  var geoPending = null;        // in-flight prewarm promise

  // PositionError codes: 1 PERMISSION_DENIED, 2 POSITION_UNAVAILABLE, 3 TIMEOUT
  function geoReason(err) {
    if (!err) return 'unavailable';
    if (err.code === 1) return 'denied';
    if (err.code === 2) return 'unavailable';
    if (err.code === 3) return 'timeout';
    return 'unavailable';
  }

  function requestFix(timeoutMs) {
    // one permission dialog at a time — a location
    // request waits for a motion request still on screen.
    return permQueue.then(function () { return requestFixNow(timeoutMs); });
  }
  function requestFixNow(timeoutMs) {
    return new Promise(function (resolve) {
      if (!navigator.geolocation) { resolve({ fix: null, reason: 'no_permission' }); return; }
      var done = false;
      navigator.geolocation.getCurrentPosition(
        function (pos) {
          if (done) return; done = true;
          var c = pos.coords;
          resolve({ fix: { lat: round7(c.latitude), lng: round7(c.longitude),
                           acc: (typeof c.accuracy === 'number') ? Math.round(c.accuracy * 10) / 10 : null,
                           at: Date.now() }, reason: '' });
        },
        function (err) {
          if (done) return; done = true;
          var reason = geoReason(err);
          // Record the decision so we stop re-prompting a user who said no, and
          // so the UI can explain how to turn it back on. After a hard deny the
          // browser never shows the prompt again -- re-enabling is a
          // site-settings action the person has to perform themselves.
          if (reason === 'denied') noteGeoConsent('denied');
          resolve({ fix: null, reason: reason });
        },
        { enableHighAccuracy: true, timeout: timeoutMs || GEO_TIMEOUT_MS, maximumAge: GEO_FRESH_MS }
      );
    });
  }

  // Fired when the capture tile is tapped -- AFTER the camera stream is
  // acquired, never alongside it: two OS permission dialogs raised in the same
  // tick on iOS means one of them gets dropped.
  function prewarmGeo() {
    if (!st.geoFallback) return;
    if (geoConsent() === 'denied' && !geoRepromptDue()) return;
    if (geoPending) return;
    if (geoFix && (Date.now() - geoFix.at) < GEO_FRESH_MS) return;
    geoPending = requestFix(GEO_TIMEOUT_MS).then(function (r) {
      geoPending = null;
      if (r.fix) { geoFix = r.fix; noteGeoConsent('granted'); }
      return r;
    });
  }

  // Resolve a usable fix: the prewarmed one if it is still fresh, otherwise
  // whatever is in flight, otherwise a new request. Never rejects.
  function resolveGeo() {
    if (!st.geoFallback) return Promise.resolve({ fix: null, reason: 'disabled' });
    if (geoFix && (Date.now() - geoFix.at) < GEO_FRESH_MS) {
      return Promise.resolve({ fix: geoFix, reason: '' });
    }
    if (geoPending) return geoPending;
    // Respect a recorded denial here too, not only in prewarmGeo(). Without
    // this the capture path re-requests on EVERY shot: the browser shows no
    // dialog after a hard deny, so it is invisible rather than annoying, but it
    // burns a call and re-runs the consent write each time for no gain. The
    // reprompt window is the only way back in, and that is deliberate.
    if (geoConsent() === 'denied' && !geoRepromptDue()) {
      return Promise.resolve({ fix: null, reason: 'denied' });
    }
    return requestFix(GEO_TIMEOUT_MS).then(function (r) {
      if (r.fix) { geoFix = r.fix; noteGeoConsent('granted'); }
      return r;
    });
  }

  /* -- Consent memory -------------------------------------------------------
   * The browser persists its own permission grant. What we lacked was OUR
   * record of what happened, which is what stops the app nagging and lets it
   * explain itself. Server-side user meta is authoritative (it follows the
   * person across devices); localStorage is the fast local mirror.
   * ------------------------------------------------------------------------ */
  var GEO_CONSENT_KEY   = 'zcam_geo_consent';
  var GEO_CONSENT_AT    = 'zcam_geo_consent_at';
  var GEO_REPROMPT_DAYS = 14;

  function geoConsent() {
    try { return localStorage.getItem(GEO_CONSENT_KEY) || (cfg().geoConsent || 'unset'); }
    catch (e) { return cfg().geoConsent || 'unset'; }
  }
  function geoRepromptDue() {
    var at = 0;
    try { at = parseInt(localStorage.getItem(GEO_CONSENT_AT) || '0', 10) || 0; } catch (e) {}
    return (Date.now() - at) > (GEO_REPROMPT_DAYS * 24 * 60 * 60 * 1000);
  }
  function noteGeoConsent(state) {
    var prev = geoConsent();
    try {
      localStorage.setItem(GEO_CONSENT_KEY, state);
      localStorage.setItem(GEO_CONSENT_AT, String(Date.now()));
    } catch (e) {}
    syncGeoChip();
    if (prev === state) return;             // only tell the server about changes
    try {
      var fd = new FormData();
      fd.append('action', 'zcam_geo_consent');
      fd.append('nonce', cfg().nonce);
      fd.append('state', state);
      fetch(cfg().ajaxurl, { method: 'POST', body: fd, credentials: 'same-origin' })
        .catch(function () {});             // best-effort; never blocks a capture
    } catch (e) {}
  }

  /* The "Location off" affordance. Deliberately quiet: one line, dismissible,
   * and it does NOT nag. A denied user is re-prompted at most once every
   * GEO_REPROMPT_DAYS; the rest of the time this is the only signal, and its
   * job is to say WHERE the switch is, because the app can no longer raise the
   * prompt itself. */
  function syncGeoChip() {
    var box = el('zcam-w-geo-chip');
    if (!box) return;
    if (!st.geoFallback || geoConsent() !== 'denied') { box.hidden = true; box.innerHTML = ''; return; }
    if (box.getAttribute('data-dismissed') === '1') { box.hidden = true; return; }
    if (box.innerHTML) { box.hidden = false; return; }
    box.hidden = false;
    box.innerHTML =
      '<span class="zcam-w-geo-chip-txt">Location is off &mdash; photos are saving without a place.</span>' +
      '<button type="button" class="zcam-w-geo-chip-how" id="zcam-w-geo-how">How to turn it on</button>' +
      '<button type="button" class="zcam-w-geo-chip-x" id="zcam-w-geo-x" aria-label="Dismiss">&times;</button>' +
      '<p class="zcam-w-geo-chip-help" id="zcam-w-geo-help" hidden></p>';
    var help = el('zcam-w-geo-help');
    var how = el('zcam-w-geo-how');
    if (how) how.addEventListener('click', function () {
      if (!help) return;
      if (!help.innerHTML) help.innerHTML = geoHelpText();
      help.hidden = !help.hidden;
    });
    var x = el('zcam-w-geo-x');
    if (x) x.addEventListener('click', function () {
      box.setAttribute('data-dismissed', '1');
      box.hidden = true;
    });
  }

  /* ── Camera consent memory ─────────────────────────────────────
   * The exact sibling of the geo-consent block above, for the CAMERA prompt.
   * Same reasoning: iOS/WebKit remembers its own grant (especially for the
   * installed Home-Screen app the crew runs), but WE kept no record — so the
   * app could not tell "granted" from "not yet asked", could not stop poking a
   * hard deny, and could not explain itself when it fell back to the system
   * camera. This adds that record (server user-meta, authoritative + follows
   * the person across devices; localStorage the fast local mirror), and the
   * Permissions API reconciles it with the browser's actual state where the
   * engine supports it. It GRANTS nothing and never blocks a capture: on any
   * uncertainty the code proceeds exactly as before.
   * ------------------------------------------------------------------------ */
  var CAM_CONSENT_KEY   = 'zcam_cam_consent';
  var CAM_CONSENT_AT    = 'zcam_cam_consent_at';
  var CAM_REPROMPT_DAYS = 14;

  function camConsent() {
    try { return localStorage.getItem(CAM_CONSENT_KEY) || (cfg().camConsent || 'unset'); }
    catch (e) { return cfg().camConsent || 'unset'; }
  }
  function camRepromptDue() {
    var at = 0;
    try { at = parseInt(localStorage.getItem(CAM_CONSENT_AT) || '0', 10) || 0; } catch (e) {}
    return (Date.now() - at) > (CAM_REPROMPT_DAYS * 24 * 60 * 60 * 1000);
  }
  function noteCamConsent(state) {
    var prev = camConsent();
    try {
      localStorage.setItem(CAM_CONSENT_KEY, state);
      localStorage.setItem(CAM_CONSENT_AT, String(Date.now()));
    } catch (e) {}
    syncCamChip();
    if (prev === state) return;             // only tell the server about changes
    try {
      var fd = new FormData();
      fd.append('action', 'zcam_cam_consent');
      fd.append('nonce', cfg().nonce);
      fd.append('state', state);
      fetch(cfg().ajaxurl, { method: 'POST', body: fd, credentials: 'same-origin' })
        .catch(function () {});             // best-effort; never blocks a capture
    } catch (e) {}
  }

  /* Reconcile OUR record with the browser's ACTUAL camera permission, where the
   * engine exposes it. navigator.permissions.query({name:'camera'}) is missing
   * or throws on some WebKit builds, so this is strictly best-effort: it only
   * ever teaches us something, and reacts if the person flips the switch in
   * Settings while the app is open. It never itself raises a prompt. */
  function syncCamPermission() {
    try {
      if (!navigator.permissions || !navigator.permissions.query) return;
      navigator.permissions.query({ name: 'camera' }).then(function (status) {
        applyCamPermState(status.state);
        try { status.onchange = function () { applyCamPermState(status.state); }; } catch (e) {}
      }).catch(function () {});             // Safari throws on the unsupported name
    } catch (e) {}
  }
  var camPermLive = '';   // what the browser says right now ('' = it would not say)
  function applyCamPermState(state) {
    camPermLive = state || '';
    if (state === 'granted') { if (camConsent() !== 'granted') noteCamConsent('granted'); }
    else if (state === 'denied') { if (camConsent() !== 'denied') noteCamConsent('denied'); }
    // 'prompt' → leave our record as-is; the next acquire will settle it.
  }

  /* The "Camera off" affordance — the sibling of the "Location off" chip, and it
   * reuses that chip's styling verbatim (same classes) so there is no new CSS.
   * Only appears on a recorded deny; says WHY the system camera showed up
   * instead of the in-app viewfinder, and where the switch is. Quiet and
   * dismissible; it never nags. */
  function syncCamChip() {
    var box = el('zcam-w-cam-chip');
    if (!box) return;
    if (camConsent() !== 'denied') { box.hidden = true; box.innerHTML = ''; return; }
    if (box.getAttribute('data-dismissed') === '1') { box.hidden = true; return; }
    if (box.innerHTML) { box.hidden = false; return; }
    box.hidden = false;
    box.innerHTML =
      '<span class="zcam-w-geo-chip-txt">Camera access is off &mdash; using your device&rsquo;s camera app instead.</span>' +
      '<button type="button" class="zcam-w-geo-chip-how" id="zcam-w-cam-how">How to turn it on</button>' +
      '<button type="button" class="zcam-w-geo-chip-x" id="zcam-w-cam-x" aria-label="Dismiss">&times;</button>' +
      '<p class="zcam-w-geo-chip-help" id="zcam-w-cam-help" hidden></p>';
    var help = el('zcam-w-cam-help');
    var how = el('zcam-w-cam-how');
    if (how) how.addEventListener('click', function () {
      if (!help) return;
      if (!help.innerHTML) help.innerHTML = camHelpText();
      help.hidden = !help.hidden;
    });
    var x = el('zcam-w-cam-x');
    if (x) x.addEventListener('click', function () {
      box.setAttribute('data-dismissed', '1');
      box.hidden = true;
    });
  }

  // Per-browser camera path, same shape as geoHelpText().
  /* The installed app's name, as the phone's Settings list shows it (the business's name). */
  function escAppName() {
    var n = String(cfg().appName || 'this app');
    return n.replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; });
  }

  function camHelpText() {
    var ua = navigator.userAgent || '';
    var iOS = /iPad|iPhone|iPod/.test(ua) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
    if (iOS && /Safari/.test(ua) && !/CriOS|FxiOS|EdgiOS/.test(ua)) {
      return 'Safari: tap the <strong>aA</strong> icon in the address bar &rarr; ' +
             '<strong>Website Settings</strong> &rarr; set <strong>Camera</strong> to <em>Ask</em> or <em>Allow</em>. ' +
             'If this is the installed app, use <strong>Settings &rarr; ' + escAppName() + ' &rarr; Camera</strong>.';
    }
    if (/CriOS|Chrome/.test(ua)) {
      return 'Chrome: tap the <strong>lock</strong> or <strong>tune</strong> icon beside the address &rarr; ' +
             '<strong>Permissions</strong> &rarr; allow <strong>Camera</strong>, then reload.';
    }
    return 'Open this site’s settings in your browser and allow <strong>Camera</strong>, then reload.';
  }

  // Per-browser, because the path really is different and "check your settings"
  // helps nobody standing on a roof.
  function geoHelpText() {
    var ua = navigator.userAgent || '';
    var iOS = /iPad|iPhone|iPod/.test(ua) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
    if (iOS && /Safari/.test(ua) && !/CriOS|FxiOS|EdgiOS/.test(ua)) {
      return 'Safari: tap the <strong>aA</strong> icon in the address bar &rarr; ' +
             '<strong>Website Settings</strong> &rarr; set <strong>Location</strong> to <em>Ask</em> or <em>Allow</em>. ' +
             'If this is the installed app, use <strong>Settings &rarr; Privacy &amp; Security &rarr; Location Services</strong>.';
    }
    if (/CriOS|Chrome/.test(ua)) {
      return 'Chrome: tap the <strong>lock</strong> or <strong>tune</strong> icon beside the address &rarr; ' +
             '<strong>Permissions</strong> &rarr; allow <strong>Location</strong>, then reload.';
    }
    return 'Open this site\u2019s settings in your browser and allow <strong>Location</strong>, then reload.';
  }

  // Back-compat shim: the old name, same contract as before (lat/lng or null),
  // so nothing that still calls it changes behaviour.
  function getGeoFallback(timeoutMs) {
    return resolveGeo().then(function (r) {
      return r.fix ? { lat: r.fix.lat, lng: r.fix.lng, acc: r.fix.acc } : null;
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

  /* ── Live camera: the camera view STAYS on screen ──────────────
   * Take Photo opens an in-page viewfinder. Every shutter tap queues a photo
   * into the durable pipeline while the viewfinder keeps running — shutter,
   * shutter, shutter, Done. No per-shot round-trip, no confirmation step.
   * The native system camera is the automatic fallback when getUserMedia is
   * unavailable or the user denied camera permission. */

  var live = { open: false, stream: null, facing: 'environment', shots: 0, saved: 0, uids: {}, busy: false };

  /* ── Video mode ────────────────────────────────────────────────
   * A second capture mode on the SAME viewfinder: [Photo | Video]. Video is
   * inherently a screen-on, app-in-front activity (you are aiming the camera),
   * so unlike the voice recorder it fits the PWA — the iOS "muted on background"
   * ceiling doesn't bind. We record 1080p30, HEVC where the device supports it
   * (isTypeSupported) else H.264/AAC in an MP4 (universally playable on iOS),
   * push-to-record / push-to-stop, ≤2 min per clip with a 30-second warning,
   * and stream chunks to the durable video queue AS THEY RECORD (nothing is
   * ever only-in-memory). Multiple clips share one reel_id and play back-to-back
   * in the Media app. Lens/zoom is chosen before recording and locked for the
   * clip; changing lens ends the clip and starts the next in the reel. */
  var VID = {
    MAX_MS:   120000,   // hard 2:00 cap per clip
    WARN_MS:   90000,   // 30-seconds-left warning fires at 1:30
    TICK_MS:  115000,   // final ~5s tick
    SLICE_MS:   2000,   // timeslice — chunk emitted every ~2s (durable + streamed)
    VBPS:    5000000,   // ~5 Mbps 1080p
    ABPS:     128000    // 128 kbps AAC/Opus narration
  };
  var vid = {
    mode: 'photo', recording: false, recorder: null,
    reelId: null, seq: 0, captureUid: null, idx: 0,
    startTs: 0, timer: null, warned: false, ticked: false,
    wakeLock: null, mime: '', posterBlob: null,
    drainBusy: false, stopping: false, clipProv: null,
    pipe: null, recordCW: 0   // rotated-record canvas pipe + locked orientation
  };
  function videoSupported() {
    // The server says whether it takes clips from this person (never the shared device or Demo Mode). The
    // localized value arrives as "1" or "" (an older page without it: allowed, the server still decides).
    if (window.zcamWidget && ('video' in zcamWidget) && !zcamWidget.video) return false;
    return !!(window.MediaRecorder && navigator.mediaDevices &&
              navigator.mediaDevices.getUserMedia && window.ZCamVideoQueue);
  }
  function pickVideoMime() {
    // Prefer HEVC (≈half the size at equal quality; iOS 18.4+ capable devices),
    // then H.264 baseline + AAC (records AND plays on every current iPhone),
    // then WebM for Chrome/Firefox reviewers. Never assume — probe each.
    var cands = [
      'video/mp4;codecs=hvc1',
      'video/mp4;codecs=avc1.42E01E,mp4a.40.2',
      'video/mp4;codecs=avc1.4D401F,mp4a.40.2',
      'video/mp4',
      'video/webm;codecs=vp9,opus',
      'video/webm;codecs=vp8,opus',
      'video/webm'
    ];
    if (window.MediaRecorder && MediaRecorder.isTypeSupported) {
      for (var i = 0; i < cands.length; i++) {
        try { if (MediaRecorder.isTypeSupported(cands[i])) return cands[i]; } catch (e) {}
      }
    }
    return '';
  }
  function vidExtFor(mime) {
    if (/webm/i.test(mime || '')) return 'webm';
    return 'mp4';
  }
  function loadMode() {
    try { var m = localStorage.getItem('zcam_mode'); if (m === 'video' || m === 'photo') vid.mode = m; } catch (e) {}
    if (vid.mode === 'video' && !videoSupported()) vid.mode = 'photo'; // never boot into an unusable mode
  }
  function saveMode() { try { localStorage.setItem('zcam_mode', vid.mode); } catch (e) {} }

  /* ══════════════════════════════════════════════════════════════════════════
   * ORIENTATION — always-correct photo & video orientation.
   *
   * THE BUG THIS FIXES: iOS hands getUserMedia frames oriented to the PHYSICAL
   * DEVICE, and ctx.drawImage(video) baked THAT straight into the JPEG with no
   * correction. Held upright the frame is upright — so portrait always worked —
   * but every rotation (90°/180°/270°) saved rotated. And because the PWA
   * manifest is orientation:"any", iOS reflows the app to landscape (90°/270°)
   * yet never to inverted portrait (180°): that reflow put the portrait-tuned
   * control offsets in the middle of the short landscape viewport (the "UI
   * didn't update"), and left 180° undetectable by screen.orientation (which
   * stays 0 there — only the motion sensor can see upside-down on iPhone).
   *
   * THE FIX: know the device's real physical rotation
   * (screen.orientation for 90°/270°, the motion sensor for 180°) and rotate ONLY
   * the SAVED capture canvas so its pixels are upright. The live PREVIEW is left
   * alone — the phone's screen rotates with the sensor, so the raw frame already
   * reads upright to whoever holds it (a "window"): iOS reflows 90°/270°, and for
   * 180° the screen-flip cancels the sensor-flip (the earlier build wrongly
   * rotated the 180° preview and it looked upside-down). The controls DO NOT move
   * (shutter stays bottom/side-centered, under the thumb); only their glyphs
   * rotate in place (.zcam-live-ori180) so text stays readable when inverted.
   *
   * CALIBRATION (the on-screen read-out shows every raw signal; a field test
   * across the four orientations confirms it):
   *   • The capture correction is the RESIDUAL (physicalAngle − screenAngle),
   *     computed in captureCorrectionCW from the live frame dimensions: 0 for
   *     0/90/270 (iOS pre-orients those to the interface — its frame dims swap
   *     with screen.orientation), and 180 for inverted portrait. If an upside-down
   *     photo still saves inverted while the read-out shows angle=180 / capCW=180,
   *     iOS is gravity-orienting that frame too — then special-case 180 → 0.
   *   • MOTION_UD_SIGN — flip if the read-out marks upright as upside-down (or
   *     vice-versa); the gy sign is reversed on that device. (Field-confirmed:
   *     upright gy≈−9.8, inverted gy≈+9.8, so the default sign is correct.)
   *   • v1.13.5 ROTATION LOCK: with iOS Portrait Orientation Lock ON,
   *     screen.orientation stays 0 even in physical landscape, so a landscape shot
   *     would save sideways. gravLand (motionHandler, gx axis) supplies the true
   *     90°/270° in that case and recomputeOri feeds it through the same residual.
   *     MOTION_LR_SIGN — flip if a locked-landscape save comes out rotated the
   *     wrong way (90↔270 swapped); the gx sign is reversed on that device. The
   *     read-out now prints gravLand so one locked-landscape shot confirms it.
   * Turn on the on-screen debug read-out to see every raw signal live: append
   * ?zcamori=1 to the URL, set localStorage zcam_ori_debug=1, or triple-tap
   * the photo counter at the top of the viewfinder.
   * ════════════════════════════════════════════════════════════════════════ */

  // FALLBACK correction table: consulted ONLY if a device delivers a raw
  // sensor frame whose aspect does NOT follow the interface (see
  // captureCorrectionCW). It undoes the full physical rotation. On current iOS the
  // frame follows the interface, so the residual path is used and this table is not
  // consulted. Field-swap 90↔270 here only if that raw-sensor path is ever hit and
  // a landscape photo comes out 180° off.
  var CAP_CORRECTION = { 0: 0, 90: 270, 180: 180, 270: 90 };

  // orientation is field-confirmed correct in all 8 states (locked +
  // unlocked × portrait / both landscapes / inverted), so the on-screen read-out
  // ships OFF by default. Turn it back on any time WITHOUT a redeploy: append
  // ?zcamori=1 to the URL, set localStorage zcam_ori_debug=1, or triple-tap the
  // photo counter at the top of the viewfinder. (It was ON by default through the
  // 1.13.5 field-calibration cycle.)
  var ORI_DEBUG_DEFAULT = false;   // read-out OFF again (default); the 1.13.12 diagnostic did its job. Still one gesture away: ?zcamori=1 / localStorage / triple-tap the counter

  // Flip if the 180° (upside-down) case reads inverted on a device (compare gy in
  // the read-out: upright portrait is one sign, inverted is the other).
  var MOTION_UD_SIGN = 1;

  // left/right calibration for the ROTATION-LOCK landscape case. When
  // iOS Portrait Orientation Lock is ON, screen.orientation stays 0 even in
  // physical landscape, so gravity's X axis is the only signal for which way the
  // phone is turned. Field-derived mapping: gx>0 (right edge down, turned 90° CW,
  // notch on the right) → angle 90; gx<0 (turned 90° CCW, notch on the left) →
  // angle 270. If a locked-landscape photo/video saves rotated the WRONG way
  // (i.e. 90↔270 swapped — check the read-out: gravLand vs how the save came out),
  // flip this to -1. Only affects the rotation-locked path; the unlocked
  // (screen.orientation) path is untouched.
  var MOTION_LR_SIGN = 1;

  var oriSt = {
    angle: 0,            // physical CW rotation from portrait-upright: 0/90/180/270
    upsideDown: false,   // motion sensor: portrait-but-inverted (screen.orientation can't tell)
    gravLand: 0,         // gravity-detected landscape (0=none, 90 or 270) — used
                         //          only when Rotation Lock pins screen.orientation to 0
    motionOK: false,     // devicemotion actually delivering (permission granted)
    gx: null, gy: null,  // last in-plane gravity (debug)
    watching: false, debug: false,
    onScreen: null, onMotion: null
  };

  function oriDebugOn() {
    if (oriSt.debug) return true;
    try { if (/[?&]zcamori=0/.test(location.search)) return false; } catch (e0) {}
    try { if (/[?&]zcamori=1/.test(location.search)) return true; } catch (e) {}
    try { var ls = localStorage.getItem('zcam_ori_debug'); if (ls === '1') return true; if (ls === '0') return false; } catch (e2) {}
    if (typeof window.zcamOriDebug === 'boolean') return window.zcamOriDebug;
    return ORI_DEBUG_DEFAULT;   // default-on during field calibration
  }

  function screenAngle() {
    try {
      if (window.screen && screen.orientation && typeof screen.orientation.angle === 'number') {
        return ((screen.orientation.angle % 360) + 360) % 360;   // 0/90/180/270
      }
    } catch (e) {}
    // Legacy fallback: window.orientation is sign-inverted vs screen.orientation
    // (its 90 == screen 270). Normalize into the screen.orientation frame.
    if (typeof window.orientation === 'number') {
      var w = window.orientation;
      if (w === 90) return 270;
      if (w === -90) return 90;
      return ((w % 360) + 360) % 360;   // 0 or 180
    }
    return 0;
  }

  // Recompute the physical angle. screen.orientation is authoritative WHENEVER it
  // reflects the device (rotation unlocked): it gives 90°/270° deterministically
  // with no permission. The motion sensor fills the gaps that live INSIDE
  // screen-angle 0 — the two orientations iOS won't reflow to on an iPhone:
  //   • inverted portrait (180°): upsideDown latch (gy), and
  //   • v1.13.5: rotation-LOCKED landscape (90°/270°): gravLand latch (gx),
  //     which only applies while screen.orientation is pinned at 0.
  // Precedence: screen-angle wins when it's non-zero (unlocked, authoritative);
  // at screen-angle 0, gravity decides inverted vs locked-landscape vs upright.
  function recomputeOri() {
    var prev = oriSt.angle;
    var s = screenAngle();
    var a;
    if (s !== 0)                a = s;               // unlocked: screen.orientation authoritative
    else if (oriSt.upsideDown)  a = 180;             // locked/portrait, physically inverted (gy)
    else if (oriSt.gravLand)    a = oriSt.gravLand;  // rotation-locked landscape (gx): 90 or 270
    else                        a = 0;               // upright portrait
    a = ((Math.round(a / 90) * 90) % 360 + 360) % 360;
    oriSt.angle = a;
    if (a !== prev) scheduleOriLayout();   // v1.13.14: capture uses the angle now; the layout waits
    updateOriDebug();
  }

  // Turning the phone must not rebuild the controls over and over. The layout is rebuilt only
  // once the reading has held still for 250 ms, so a
  // phone turned slowly or held near 45 degrees moves the controls once. The saved photo's
  // rotation still uses the newest reading at the moment of the shot.
  var ORI_SETTLE_MS = 250, oriLayoutTimer = null;
  function scheduleOriLayout() {
    if (oriLayoutTimer) clearTimeout(oriLayoutTimer);
    oriLayoutTimer = setTimeout(function () { oriLayoutTimer = null; onOriChanged(); }, ORI_SETTLE_MS);
  }

  // Gravity → which in-plane orientation is the phone actually in? Uses only the
  // in-plane (x,y) component, so it's robust to the phone being tilted forward/back
  // (aiming up or down at a wall/roof). Two independent latches, one per axis:
  //   • Y axis (portrait-ish, |y| dominant): inverted-portrait (upsideDown).
  //   • X axis (landscape-ish, |x| dominant): rotation-locked landscape (gravLand).
  // Whichever axis clearly dominates decides, and clears the OTHER latch so a stale
  // value can't linger. Near-diagonal or near-flat holds both (hysteresis) — you
  // can't read in-plane rotation lying flat. When rotation is UNLOCKED,
  // screen.orientation reports 90°/270° for real landscape and recomputeOri ignores
  // gravLand; gravLand only takes effect when Rotation Lock pins screen at 0.
  function motionHandler(ev) {
    var g = ev && ev.accelerationIncludingGravity;
    if (!g || g.x == null || g.y == null) return;
    oriSt.motionOK = true;
    var x = g.x || 0, y = g.y || 0;
    oriSt.gx = x; oriSt.gy = y;
    var changed = false;

    // resolve the in-plane gravity direction to ONE quadrant on every
    // reading, via a continuous angle, instead of the old two threshold branches.
    // Those branches had a DIAGONAL DEAD ZONE — when |y| was not > |x|*1.3 AND
    // |x| was not > |y|*1.3 (phone held ~30–60° between orientations) NEITHER ran,
    // so upsideDown/gravLand kept a STALE value. Rotating landscape→inverted could
    // leave the landscape latch (gravLand=90) stuck, so an upside-down shot saved
    // sideways/inverted (the reported 5:48 case). atan2 always yields a definite
    // angle (no gap); a hysteresis band holds the current quadrant through boundary
    // wobble so a shutter wobble can't drop the correction. Sign knobs
    // (MOTION_LR_SIGN / MOTION_UD_SIGN) and the OUTPUTS (upsideDown, gravLand) are
    // unchanged, so recomputeOri / captureCorrectionCW are untouched. This path is
    // consulted ONLY when screen.orientation reads 0 (portrait interface: upright,
    // inverted, or rotation-locked landscape); unlocked landscapes read
    // screen.orientation directly and are unaffected.
    var xEff = MOTION_LR_SIGN * x, yEff = MOTION_UD_SIGN * y;
    var mag = Math.sqrt(xEff * xEff + yEff * yEff);
    if (mag >= 4.5) {   // enough in-plane gravity to be meaningful (phone not near-flat); else hold
      // 0 = upright · 90 = notch-right · 180 = inverted · 270 = notch-left
      var ang = Math.atan2(xEff, -yEff) * 180 / Math.PI;
      ang = (ang % 360 + 360) % 360;
      var qPrev = oriSt.upsideDown ? 180 : (oriSt.gravLand || 0);
      var qNear = (Math.round(ang / 90) * 90) % 360;
      var dPrev = Math.abs(((ang - qPrev) % 360 + 540) % 360 - 180);   // 0..180° from the current latch
      var q = (qNear === qPrev) ? qPrev : (dPrev > 57 ? qNear : qPrev); // 45° + 12° hysteresis
      var ud = (q === 180);
      var gl = (q === 90 || q === 270) ? q : 0;
      if (ud !== oriSt.upsideDown) { oriSt.upsideDown = ud; changed = true; }
      if (gl !== oriSt.gravLand)   { oriSt.gravLand = gl;   changed = true; }
    }
    if (changed) { recomputeOri(); return; }
    updateOriDebug();
  }

  // the SAVED-capture correction, computed from the field read-out.
  // iOS delivers the getUserMedia frame ORIENTED TO THE INTERFACE — its pixel
  // dimensions swap with screen.orientation (portrait 3024x4032, landscape
  // 4032x3024). So 0°/90°/270° already arrive upright (screen.orientation
  // reflowed to match the device) and need NO rotation; only the residual iOS
  // couldn't reflow to needs fixing — the inverted-portrait case, where
  // screen.orientation is stuck at 0 while the phone is physically 180°. That
  // residual is (physicalAngle − screenAngle): 0 for 0/90/270, 180 for inverted.
  // with Rotation Lock ON, physical landscape leaves screen.orientation at
  // 0 while the frame stays PORTRAIT-shaped (iOS renders it to the locked portrait
  // interface). recomputeOri sets physicalAngle to gravLand (90/270) there, so the
  // residual becomes (90−0)=90 / (270−0)=270 and rotates the sideways frame upright.
  // The frame-aspect guard below is unaffected: a portrait frame with screen=0 has
  // frameLand===ifaceLand (both false), so the residual path runs, not the fallback.
  // If a device ever delivers a raw sensor frame instead (its aspect would NOT
  // match the interface), fall back to undoing the full physical rotation.
  function captureCorrectionCW() {
    var s = screenAngle();
    var phys = oriSt.angle;
    // INVERTED — trust the MOTION sensor, not screen.orientation. Field read-out on
    // iPhone 17 Pro Max proved that when the phone is held upside-down iOS reports a LANDSCAPE
    // screen.orientation (screen=90, angle=90) that is indistinguishable from a real landscape —
    // only `upsideDown` (gy≈+9.9) tells them apart. Because the earlier guards keyed on
    // `screen==0 && phys==180`, they were DEAD for this hold (it's screen==90), so the landscape
    // residual (90-90=0) applied zero rotation and the frame saved upside-down. When upsideDown
    // is latched the phone is 180° from upright, so the correct capture rotation is
    // (180 + screen) % 360: screen=90 -> 270 (field-confirmed via the Media Rotate tool),
    // screen=0 -> 180, screen=270 -> 90. This overrides the residual/fallback below. A REAL
    // landscape has upsideDown=false and is unaffected.
    if (oriSt.upsideDown) return (180 + s) % 360;
    var v = el('zcam-live-video');
    if (v && v.videoWidth && v.videoHeight) {
      var frameLand = v.videoWidth > v.videoHeight;
      var ifaceLand = (s === 90 || s === 270);
      if (frameLand !== ifaceLand) return CAP_CORRECTION[phys] || 0;   // raw-sensor fallback
    }
    return ((phys - s) % 360 + 360) % 360;                             // residual (interface-oriented)
  }

  function previewRotationDeg() {
    // the live preview needs NO rotation from us — the phone's screen
    // rotates with the sensor, so the raw frame always reads upright to the
    // person holding it (iOS reflows 90°/270°; the 180° screen-flip cancels the
    // sensor-flip). Rotating it here made the upside-down preview wrong. Only the
    // SAVED file is rotated (grabFrameCanvas → orientedCanvas). Kept as a function
    // so the debug read-out and callers are unchanged.
    return 0;
  }

  // Compose the preview transform: orientation rotation first, then the existing
  // digital-zoom scale. freezeFrame() copies video.style.transform verbatim, so
  // the freeze/lens-swap frame inherits the same rotation automatically.
  function previewTransformStr() {
    var r = previewRotationDeg();
    var s = (zoomSt.digital > 1.001) ? zoomSt.digital : 1;
    var parts = [];
    if (r) parts.push('rotate(' + r + 'deg)');
    if (s !== 1) parts.push('scale(' + s + ')');
    return parts.join(' ');
  }

  // Rotate a source canvas clockwise by `cw` (0/90/180/270) into a new upright
  // canvas, swapping dimensions on the quarter turns. cw===0 returns the input unchanged.
  function orientedCanvas(src, cw) {
    if (!src) return src;
    cw = ((cw % 360) + 360) % 360;
    if (cw === 0) return src;
    var swap = (cw === 90 || cw === 270);
    var out = document.createElement('canvas');
    out.width  = swap ? src.height : src.width;
    out.height = swap ? src.width  : src.height;
    var ctx = out.getContext('2d');
    if (!ctx) return src;
    try {
      ctx.save();
      ctx.translate(out.width / 2, out.height / 2);
      ctx.rotate(cw * Math.PI / 180);
      ctx.drawImage(src, -src.width / 2, -src.height / 2);
      ctx.restore();
    } catch (e) { return src; }
    releaseCanvas(src);   // v1.13.14: the unrotated copy is not needed any more
    return out;
  }

  function onOriChanged() {
    var v = el('zcam-live');
    if (v) {
      v.classList.toggle('zcam-live-ori180', oriSt.angle === 180);
      // when the INTERFACE is landscape (unlocked reflow), move the shutter to
      // the physical-bottom short edge — screen=90 → right, screen=270 → left. Keyed on
      // screenAngle (the interface), NOT oriSt.angle, so rotation-locked landscape
      // (screen=0, portrait UI) is deliberately excluded and its layout stays put.
      var s = screenAngle();
      v.classList.toggle('zcam-live-land90', s === 90);
      v.classList.toggle('zcam-live-land270', s === 270);
    }
    applyPreviewZoom();          // recompute the video transform (rotation + scale)
    var f = el('zcam-live-freeze'), video = el('zcam-live-video');
    if (f && video && f.classList.contains('zcam-live-freeze-on')) {
      f.style.transform = video.style.transform || '';   // keep a showing freeze consistent
    }
  }

  // iOS 13+ gates DeviceMotion/Orientation behind a per-session grant that must
  // be requested from a user gesture (opening the camera qualifies). Fired
  // in-gesture, fire-and-forget: getUserMedia is untouched, and a denial is
  // non-fatal — we degrade to screen.orientation, which still fixes 90°/270°.
  /* Opening the camera must not set off a burst of permission prompts (camera, motion,
   * orientation and location) on every open. Each is asked when it is needed, one at a time: the camera when it opens; motion
   * at the first shutter press (iOS only lets a tap ask for it; the screen's own
   * rotation covers the shot until then); location after that first photo; the
   * microphone only in Video. Motion and orientation are ONE question (only the motion
   * gate is asked; the orientation gate was a second dialog for the same sensors), the
   * answer is remembered, and a "no" is not asked again for 14 days. */
  var MOTION_CONSENT_KEY = 'zcam_motion_consent', MOTION_CONSENT_AT = 'zcam_motion_consent_at';
  var permQueue = Promise.resolve();   // location requests wait on a motion dialog
  function motionConsent() {
    try { return localStorage.getItem(MOTION_CONSENT_KEY) || 'unset'; } catch (e) { return 'unset'; }
  }
  function noteMotionConsent(v) {
    try { localStorage.setItem(MOTION_CONSENT_KEY, v); localStorage.setItem(MOTION_CONSENT_AT, String(Date.now())); } catch (e) {}
  }
  function motionRepromptDue() {
    var at = 0;
    try { at = parseInt(localStorage.getItem(MOTION_CONSENT_AT) || '0', 10) || 0; } catch (e) {}
    return (Date.now() - at) > (14 * 24 * 60 * 60 * 1000);
  }
  function requestOrientationPermission() {
    if (oriSt.motionAsked) return;      // once per app launch at most
    oriSt.motionAsked = true;
    try {
      if (window.DeviceMotionEvent && typeof DeviceMotionEvent.requestPermission === 'function') {
        if (motionConsent() === 'denied' && !motionRepromptDue()) { oriSt.motionAllowed = false; return; }
        permQueue = DeviceMotionEvent.requestPermission().then(function (r) {
          oriSt.motionAllowed = (r === 'granted');
          noteMotionConsent(r === 'granted' ? 'granted' : 'denied');
        }).catch(function () {
          // Refused without an answer (asked outside a tap, e.g. a launch from the ask
          // bar): not a "no" — ask again at the next tap.
          oriSt.motionAsked = false;
        });
      } else {
        oriSt.motionAllowed = true;   // Android / older iOS: no gate
      }
    } catch (e) {}
  }

  function startOrientationWatch() {
    if (oriSt.watching) return;
    oriSt.watching = true;
    oriSt.debug = oriDebugOn();
    oriSt.onScreen = function () { recomputeOri(); };
    oriSt.onMotion = motionHandler;
    try {
      if (window.screen && screen.orientation && screen.orientation.addEventListener) {
        screen.orientation.addEventListener('change', oriSt.onScreen);
      } else {
        window.addEventListener('orientationchange', oriSt.onScreen);
      }
    } catch (e) { try { window.addEventListener('orientationchange', oriSt.onScreen); } catch (e2) {} }
    // The motion listener is harmless before/without permission (it simply never
    // fires on iOS until granted), so add it unconditionally.
    try { window.addEventListener('devicemotion', oriSt.onMotion); } catch (e3) {}
    if (oriSt.debug) buildOriDebug();
    showBuildBadge();   // brief version confirm on each open
    recomputeOri();
    if (oriLayoutTimer) { clearTimeout(oriLayoutTimer); oriLayoutTimer = null; }
    onOriChanged();     // the opening layout is applied at once, not after the settle
  }

  function stopOrientationWatch() {
    if (!oriSt.watching) return;
    oriSt.watching = false;
    try { if (window.screen && screen.orientation && screen.orientation.removeEventListener) screen.orientation.removeEventListener('change', oriSt.onScreen); } catch (e) {}
    try { window.removeEventListener('orientationchange', oriSt.onScreen); } catch (e2) {}
    try { window.removeEventListener('devicemotion', oriSt.onMotion); } catch (e3) {}
    oriSt.onScreen = oriSt.onMotion = null;
    if (oriLayoutTimer) { clearTimeout(oriLayoutTimer); oriLayoutTimer = null; }
    oriSt.upsideDown = false;   // transient; re-derived on next open
    oriSt.gravLand = 0;         // transient; re-derived on next open
  }

  /* ── Orientation debug read-out (opt-in) ─────────────────────────────────── */
  function buildOriDebug() {
    if (el('zcam-ori-dbg')) return;
    var host = el('zcam-live'); if (!host) return;
    var d = document.createElement('div');
    d.id = 'zcam-ori-dbg';
    d.className = 'zcam-ori-dbg';
    host.appendChild(d);
    updateOriDebug();
  }
  function updateOriDebug() {
    if (!oriSt.debug) return;
    var d = el('zcam-ori-dbg'); if (!d) return;
    var video = el('zcam-live-video');
    d.textContent =
      'angle=' + oriSt.angle + '  capCW=' + captureCorrectionCW() + '  prevRot=' + previewRotationDeg() + '\n' +
      'screen=' + screenAngle() + '  winOri=' + (typeof window.orientation === 'number' ? window.orientation : 'na') + '\n' +
      'motionOK=' + oriSt.motionOK + '  upsideDown=' + oriSt.upsideDown + '  gravLand=' + oriSt.gravLand + '\n' +
      'gx=' + (oriSt.gx != null ? oriSt.gx.toFixed(1) : '-') + '  gy=' + (oriSt.gy != null ? oriSt.gy.toFixed(1) : '-') + '\n' +
      'video=' + (video && video.videoWidth ? (video.videoWidth + 'x' + video.videoHeight) : '-');
  }
  function toggleOriDebug() {
    oriSt.debug = !oriSt.debug;
    try { localStorage.setItem('zcam_ori_debug', oriSt.debug ? '1' : '0'); } catch (e) {}
    if (oriSt.debug) buildOriDebug();
    else { var d = el('zcam-ori-dbg'); if (d && d.parentNode) d.parentNode.removeChild(d); }
  }

  /* ── Build badge: brief auto-fading version confirm ─────────────
   * Distinct from the orientation read-out above (that stays opt-in / OFF).
   * This shows for ~4s on each camera open so a wrong or cached install can
   * be spotted at a glance. JS_BUILD is baked into THIS file; the server's
   * PHP version arrives via zcamWidget.version (wp_localize_script). If the
   * PHP file wasn't replaced by the install, phpVer will differ (or the whole
   * new widget.js won't load at all → no badge) — either way the true state
   * is visible. Remove showBuildBadge() + its call + .zcam-build-badge CSS
   * once the field build is confirmed. */
  var JS_BUILD = '1.13.16';   // the server now fires zcam_photo_saved (no change here); kept equal to ZCAM_VERSION
  function showBuildBadge() {
    try {
      var host = el('zcam-live'); if (!host) return;
      var old = el('zcam-build-badge'); if (old && old.parentNode) old.parentNode.removeChild(old);
      var phpVer = '';
      try { phpVer = (window.zcamWidget && zcamWidget.version) ? String(zcamWidget.version) : ''; } catch (e) {}
      var txt = 'Camera ' + JS_BUILD;
      if (phpVer && phpVer !== JS_BUILD) txt += '  ·  server PHP ' + phpVer;
      var b = document.createElement('div');
      b.id = 'zcam-build-badge';
      b.className = 'zcam-build-badge';
      b.textContent = txt;
      host.appendChild(b);
      void b.offsetWidth; // force reflow so the fade-in transition runs
      b.classList.add('zcam-build-badge-in');
      setTimeout(function () { b.classList.add('zcam-build-badge-out'); }, 4000);
      setTimeout(function () { if (b && b.parentNode) b.parentNode.removeChild(b); }, 5300);
    } catch (e) {}
  }

  /* ── Zoom: pinch → real lens selection + WYSIWYG digital zoom ──
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
    if (video) video.style.transform = previewTransformStr();   // orientation rotation + digital-zoom scale
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

  /* ── Freeze-frame: hide the lens-switch gap ────────────────────
   * Re-acquiring a stream blanks the <video> for a beat. Before any swap we
   * paint the CURRENT frame onto an overlay canvas (with the same digital-
   * zoom transform, so it's pixel-continuous with the preview), keep it on
   * top until the new stream renders its first frame, then drop it — the
   * user sees freeze → new lens, never black. */
  var freezeSafety = null;
  // the cover canvases are only looked at, so they are drawn
  // at screen size (long side <= 1280 px) instead of the 12-megapixel frame. Each full-size
  // copy held about 48 MB, and the freeze frame and the captured still both stayed alive.
  var COVER_MAX = 1280;
  function coverSize(w, h) {
    var k = Math.min(1, COVER_MAX / Math.max(w || 1, h || 1));
    return { w: Math.max(1, Math.round(w * k)), h: Math.max(1, Math.round(h * k)) };
  }
  // Free a canvas's pixels now rather than at the next garbage collection (iOS counts
  // canvas memory per page and ends the page when it runs out).
  function releaseCanvas(c) { if (c && c.getContext) { try { c.width = 0; c.height = 0; } catch (e) {} } }
  function freezeFrame() {
    var video = el('zcam-live-video'), f = el('zcam-live-freeze');
    if (!video || !f || !video.videoWidth) return;
    var fs = coverSize(video.videoWidth, video.videoHeight);
    f.width = fs.w; f.height = fs.h;
    try { f.getContext('2d').drawImage(video, 0, 0, fs.w, fs.h); } catch (e) { return; }
    f.style.transform = video.style.transform || '';
    f.style.filter = video.style.filter || ''; // carry software EV
    f.classList.add('zcam-live-freeze-on');
  }
  function unfreeze() {
    var f = el('zcam-live-freeze');
    if (f) { f.classList.remove('zcam-live-freeze-on'); setTimeout(function () { if (!f.classList.contains('zcam-live-freeze-on')) releaseCanvas(f); }, 200); }
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
    syncZoomUI(); // keep the persistent pill + slider in step with z
  }

  /* ── v1.9.0: BOOT ON THE WIDEST LENS ───────────────────
   * Field users want the widest field of view by default (".5 is the one to
   * start with"). The first acquire uses facingMode (~1x on iOS) because lens
   * labels/deviceIds aren't known until AFTER a granted acquire; once
   * discoverLenses() has mapped the rear lenses we snap to the widest optical
   * lens exactly once. Self-contained (does NOT route through setZoom, so a
   * lens that enumerates-but-refuses can never loop) and reverts cleanly to
   * the acquired 1x lens on failure. No-op on devices without an ultra-wide,
   * on the front camera, or when we're already there. */
  function bootToWidestLens() {
    if (live.facing !== 'environment') return;         // rear only; front = digital
    var ls = zoomSt.lenses || [];
    if (!ls.length) return;
    var widestIdx = 0;                                 // discoverLenses sorts ascending -> widest first
    var lens = ls[widestIdx];
    if (!lens || lens.f >= 0.999) return;              // no sub-1x (ultra-wide) lens -> already widest
    if (!lens.deviceId) return;                        // can't pin the physical lens -> leave as-is
    if (zoomSt.lensIdx === widestIdx && Math.abs(zoomSt.z - lens.f) < 0.001) { syncZoomUI(); return; }
    zoomSt.switching = true;
    var prevIdx = zoomSt.lensIdx, prevZ = zoomSt.z;
    zoomSt.lensIdx = widestIdx;
    freezeFrame();
    var old = live.stream;
    acquireStream().then(function () {
      if (old && old !== live.stream) old.getTracks().forEach(function (t) { try { t.stop(); } catch (e) {} });
      zoomSt.z = lens.f; zoomSt.digital = 1;           // 0.5x, no digital crop
      zoomSt.switching = false;
      unfreezeWhenReady();
      applyPreviewZoom();
      syncZoomUI();                                    // pill shows .5x active
    }).catch(function () {
      zoomSt.lensIdx = prevIdx; zoomSt.z = prevZ; zoomSt.digital = 1;  // ultra-wide refused -> revert to 1x
      zoomSt.switching = false;
      unfreeze();
      syncZoomUI();
    });
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
        // handle BOTH touchend and click. The single-finger focus
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
    if (!slider || slider._zcamBound) return;
    slider._zcamBound = true;
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

  /* ── Focus / exposure / lock ───────────────────────────────────
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
    // the zoom pill + slider must count as controls, or the
    // single-finger focus/exposure handler calls preventDefault() on their
    // touchstart and swallows the button's synthetic `click` (the slider
    // worked only because it uses pointer events). Including them here lets
    // taps reach the zoom buttons and keeps a slider drag from also dropping
    // a focus point underneath it.
    return !!(target && target.closest &&
      target.closest('.zcam-live-controls, .zcam-live-btn, .zcam-live-shutter, .zcam-live-zoombar, .zcam-live-zoom-btn, .zcam-live-zoomslider, .zcam-live-modebar, .zcam-live-mode, .zcam-live-rec, .zcam-live-warn'));
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
      // stop iOS from starting TEXT SELECTION / the magnifier loupe
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
      // a close button where people look for one.
      '<button type="button" class="zcam-live-close" id="zcam-live-close" aria-label="Close camera">&times;</button>' +
      '<div class="zcam-live-top">' +
        '<div class="zcam-live-status" id="zcam-live-status" aria-live="polite"></div>' +
        '<div class="zcam-live-note" id="zcam-live-note"></div>' +
        // recording HUD (video mode) — red dot + running timer /2:00 cap,
        // and a 30-seconds-left warning chip. Hidden in photo mode.
        '<div class="zcam-live-rec" id="zcam-live-rec" hidden aria-live="polite">' +
          '<span class="zcam-live-rec-dot"></span>' +
          '<span class="zcam-live-rec-time" id="zcam-live-rec-time">0:00</span>' +
          '<span class="zcam-live-rec-max"> / 2:00</span>' +
        '</div>' +
        '<div class="zcam-live-warn" id="zcam-live-warn" hidden>30s left</div>' +
      '</div>' +
      '<div class="zcam-live-zoom" id="zcam-live-zoom"></div>' +
      '<div class="zcam-live-lock" id="zcam-live-lock">AE/AF LOCK</div>' +
      '<div class="zcam-live-focus" id="zcam-live-focus">' +
        '<div class="zcam-live-focus-box"></div>' +
        '<div class="zcam-live-ev"><div class="zcam-live-ev-track"></div><div class="zcam-live-ev-sun" id="zcam-live-ev-sun">&#9728;</div></div>' +
      '</div>' +
      '<div class="zcam-live-thumb" id="zcam-live-thumb"><img id="zcam-live-thumb-img" alt="" /></div>' +
      // one-time discoverability hint + persistent zoom pill +
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
      // mode toggle — one tap to switch Photo ⇄ Video, remembered.
      '<div class="zcam-live-modebar" id="zcam-live-modebar" role="tablist" aria-label="Camera mode">' +
        '<button type="button" class="zcam-live-mode zcam-live-mode-active" id="zcam-live-mode-photo" role="tab" aria-selected="true">Photo</button>' +
        '<button type="button" class="zcam-live-mode" id="zcam-live-mode-video" role="tab" aria-selected="false">Video</button>' +
      '</div>' +
      '<div class="zcam-live-controls">' +
        '<button type="button" class="zcam-live-btn" id="zcam-live-flip" aria-label="Switch camera">Flip</button>' +
        '<button type="button" class="zcam-live-shutter" id="zcam-live-shutter" aria-label="Take photo"></button>' +
        '<button type="button" class="zcam-live-btn" id="zcam-live-done">Done</button>' +
      '</div>';
    document.body.appendChild(v);
    el('zcam-live-shutter').addEventListener('click', liveShutter);
    el('zcam-live-done').addEventListener('click', function () { closeLiveCamera(); });
    el('zcam-live-close').addEventListener('click', function () { closeLiveCamera(); });
    el('zcam-live-flip').addEventListener('click', flipLiveCamera);
    // mode toggle wiring (no-op if MediaRecorder unsupported → Video
    // button hides in applyMode()).
    var _mp = el('zcam-live-mode-photo'), _mv = el('zcam-live-mode-video');
    if (_mp) _mp.addEventListener('click', function () { setMode('photo'); });
    if (_mv) _mv.addEventListener('click', function () { setMode('video'); });
    // pinch-to-zoom (two-finger; single taps pass through untouched)
    v.addEventListener('touchstart', onPinchStart, { passive: false });
    v.addEventListener('touchmove',  onPinchMove,  { passive: false });
    v.addEventListener('touchend',   onPinchEnd,   { passive: false });
    v.addEventListener('touchcancel', onPinchEnd,  { passive: false });
    bindZoomSlider(); // wire the vertical fine-zoom slider drag
    // hidden orientation-debug toggle — triple-tap the top counter.
    (function () {
      var s = el('zcam-live-status'); if (!s) return;
      var taps = 0, tmr = null;
      s.addEventListener('click', function () {
        taps++;
        if (tmr) clearTimeout(tmr);
        tmr = setTimeout(function () { taps = 0; }, 600);
        if (taps >= 3) { taps = 0; toggleOriDebug(); }
      });
    })();
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

  // native-style SHUTTER BLINK — a fast black blip (like the iOS
  // camera). v1.5.1 pairs it with the full-screen captured-still below.
  // A clear sign the photo was taken: a 70 ms WHITE flash, switched on and
  // off by a timer rather than a CSS animation: the theme's Reduce Motion rule cuts every
  // animation to 0.01 ms, which made the old 180 ms black blink invisible there.
  var blinkTimer = null;
  function shutterBlink() {
    var f = el('zcam-live-blink');
    if (!f) return;
    if (blinkTimer) clearTimeout(blinkTimer);
    f.classList.add('zcam-live-blink-on');
    blinkTimer = setTimeout(function () { f.classList.remove('zcam-live-blink-on'); blinkTimer = null; }, 70);
  }
  // A light tap with the shot. Android: vibrate. iPhone (iOS 18+): Safari plays the
  // system haptic when a switch control is toggled, so a hidden one is toggled in the
  // same tap. Neither is required; the flash is the signal.
  var hapticSwitch = null;
  function shutterHaptic() {
    if (navigator.vibrate) { try { navigator.vibrate(12); return; } catch (e) {} }
    try {
      if (!hapticSwitch) {
        var lab = document.createElement('label');
        lab.className = 'zcam-haptic'; lab.setAttribute('aria-hidden', 'true');
        lab.innerHTML = '<input type="checkbox" switch tabindex="-1" />';
        (el('zcam-live') || document.body).appendChild(lab);
        hapticSwitch = lab;
      }
      hapticSwitch.click();
    } catch (e2) {}
  }

  // FULL-SCREEN capture confirmation. The shutter blink reveals the
  // PHOTO THAT WAS JUST TAKEN, full screen, which holds for a beat and then
  // shrinks away toward the corner thumbnail — the same idiom as the native
  // camera's capture animation, but bigger and unmissable. Driven entirely
  // from the already-captured canvas, so it costs one GPU blit.
  var stillTimer = null;
  function showCapturedStill(srcCanvas) {
    var s = el('zcam-live-still');
    if (!s || !srcCanvas || !srcCanvas.width) return;
    var ss = coverSize(srcCanvas.width, srcCanvas.height); // v1.13.14: screen size, not 12 MP
    s.width = ss.w; s.height = ss.h;
    try { s.getContext('2d').drawImage(srcCanvas, 0, 0, ss.w, ss.h); } catch (e) { return; }
    s.classList.remove('zcam-live-still-on');
    void s.offsetWidth; // restart cleanly on rapid sequential shots
    s.classList.add('zcam-live-still-on');
    if (stillTimer) clearTimeout(stillTimer);
    stillTimer = setTimeout(function () { s.classList.remove('zcam-live-still-on'); releaseCanvas(s); }, 750);
  }

  // captured-frame thumbnail pop (bottom-left) — second confirmation
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
  // is selected, else by facing mode — at the highest
  // resolution the device will give a video track.
  function acquireStream() {
    var lens = (live.facing === 'environment') ? zoomSt.lenses[zoomSt.lensIdx] : null;
    var videoMode = (vid.mode === 'video');
    var vc = (lens && lens.deviceId)
      ? { deviceId: { exact: lens.deviceId } }
      : { facingMode: { ideal: live.facing } };
    if (videoMode) {
      // Cap at 1080p30 (file size + heat). Constraints are requests; modern
      // iPhones honor these.
      vc.width = { ideal: 1920 }; vc.height = { ideal: 1080 }; vc.frameRate = { ideal: 30, max: 30 };
    } else {
      vc.width = { ideal: 4032 }; vc.height = { ideal: 3024 };
    }
    // Video adds the mic so narration is captured. On iOS the OS auto-routes to
    // AirPods when connected; a specific input can't be reliably chosen by
    // deviceId there and doesn't need to be. Photo mode stays audio:false so no
    // mic indicator lights and no power is spent.
    var audio = videoMode ? { echoCancellation: true, noiseSuppression: true, autoGainControl: true } : false;
    return navigator.mediaDevices.getUserMedia({ audio: audio, video: vc }).then(function (stream) {
      live.stream = stream;
      watchStreamEnd(stream);   // v1.13.14
      // a stream in hand IS the grant. Record it once here — the single
      // acquire point, so open / flip / background-recover all keep the memory
      // (and the server + other devices) current, and clear any stale "off" chip.
      noteCamConsent('granted');
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

  /* Without a way back, a person in the live camera can feel the app has crashed. The live camera is a layer over
   * the page: it now adds a history entry, so a back swipe closes the camera (not the app
   * underneath), and a camera stream that ends while the page is showing closes the layer
   * with a message instead of leaving a black screen. A stream that ends because the app
   * went to the background is left to the visibility handler, which restarts it. */
  function watchStreamEnd(stream) {
    try {
      stream.getVideoTracks().forEach(function (t) {
        t.addEventListener('ended', function () {
          // An app switch can end the track a moment before the page reports itself
          // hidden; look again shortly, and leave a hidden page to the visibility handler.
          setTimeout(function () {
            if (!live.open || live.stream !== stream || zoomSt.switching) return;
            if (document.visibilityState !== 'visible') return;
            closeLiveCamera({ message: 'The camera stopped. Tap Take Photo to open it again.' });
          }, 500);
        });
      });
    } catch (e) {}
  }
  // The theme's Back handler (app.js) runs before this one and closes the open app on any
  // popstate, unless Bridge._skipNextPop is set (the flag its document layer uses). The
  // camera sets it with its own entry, so the one pop that removes the entry, whether a
  // back swipe or the close button, closes the camera and not the app underneath.
  function pushCameraHistory() {
    if (live.historyPushed) return;
    try { history.pushState({ zcamLive: 1 }, ''); live.historyPushed = true; } catch (e) { live.historyPushed = false; return; }
    if (window.Bridge) { try { window.Bridge._skipNextPop = true; } catch (e2) {} }
  }
  // Take the camera's entry back off (Done, the close button, a failed open).
  function popCameraHistory() {
    if (!live.historyPushed) return;
    live.backPending = true;
    setTimeout(function () { if (live.backPending) { live.backPending = false; live.historyPushed = false; } }, 800);   // no popstate came: the entry is gone or never will be
    try { history.back(); } catch (e) { live.historyPushed = false; live.backPending = false; }
  }
  window.addEventListener('popstate', function () {
    live.backPending = false;
    if (!live.historyPushed) return;
    live.historyPushed = false;
    if (live.open) closeLiveCamera({ fromPop: true });
    else live.cancelOpen = true;   // still starting: do not open over the page Back returned to
  });

  function openLiveCamera() {
    if (live.open) return;
    if (live.backPending) return;   // the last close is still taking its history entry back
    if (!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia)) {
      openNativeCamera(); // ancient engine → system camera
      return;
    }
    // if the camera was DENIED and we're not inside the 14-day reprompt
    // window, don't fire getUserMedia. A hard deny makes the browser raise no
    // dialog — so the call just fails, invisibly, on every single tap — and on
    // some engines re-pokes a prompt the person already dismissed. Go straight to
    // the system camera (photos still capture) and show the "camera off" chip so
    // turning it back on is a deliberate, findable choice. Any other state
    // (granted / unset / reprompt-due) proceeds exactly as before.
    if (camConsent() === 'denied' && !camRepromptDue()) {
      syncCamChip();
      openNativeCamera();
      return;
    }
    var v = liveView();
    loadMode();           // honor the remembered Photo/Video mode BEFORE acquire
    resetZoomState();
    resetExposureState(); // fresh session = neutral exposure, no lock
    // v1.13.14: motion is asked at the first shutter press and location after the
    // first photo (see requestOrientationPermission), so opening asks for the camera only.
    // Someone who already allowed motion is asked here, in the tap, as before: the browser
    // answers from memory without a dialog, and the upside-down save fix (which reads the
    // motion sensor) then works from the first shot. A location the person already allowed
    // also warms up now, without a dialog.
    // When the browser itself says the camera is allowed (no camera dialog will show),
    // motion is asked here too — still one dialog at a time — and the upside-down fix works
    // from shot one. Our own "granted" record is not enough: a Home Screen app on iPhone can
    // ask for the camera again at each launch.
    if (motionConsent() === 'granted' || camPermLive === 'granted') requestOrientationPermission();
    pushCameraHistory();   // in the tap: a history entry added later may be skipped by Back
    live.cancelOpen = false;
    acquireStream().then(function () {
      if (live.cancelOpen) {   // Back was pressed while the camera was starting
        live.cancelOpen = false;
        if (live.stream) { live.stream.getTracks().forEach(function (t) { try { t.stop(); } catch (e) {} }); live.stream = null; }
        return;
      }
      if (geoConsent() === 'granted') prewarmGeo();
      live.open = true;
      live.shots = 0; live.saved = 0; live.uids = {};
      // fresh video session — new reel, zero saved clips, clear posters.
      vid.reelId = null; vid.seq = 0; vid.savedClips = 0; vidPosters = {};
      v.classList.add('zcam-live-open');
      startOrientationWatch();   // track device rotation → capture + preview + UI
      updateLiveStatus();
      applyMode();          // reflect Photo/Video in the shutter + mode bar
      drainVideoChunks();   // resume any clip left un-uploaded by a prior session (crash-safety)
      // map the back lenses for zoom breakpoints; v1.7.0: once known,
      // build the persistent zoom pill/slider and show the one-time hint.
      discoverLenses().then(function () { buildZoomUI(); bootToWidestLens(); maybeShowZoomHint(); });
    }).catch(function (err) {
      // Denied / unavailable / in use → system camera fallback. Retried on
      // every Take Photo tap, so granting permission later self-heals.
      // if this was a PERMISSION refusal (not merely "device busy"),
      // remember it — that's what stops the invisible per-tap re-fire and raises
      // the "camera off" chip. A NotFound/NotReadable is hardware/busy, NOT a
      // deny, so we must NOT record those as denied or we'd suppress the live
      // camera for a transient glitch.
      var name = err && err.name;
      if (name === 'NotAllowedError' || name === 'PermissionDeniedError' || name === 'SecurityError') {
        noteCamConsent('denied'); // also refreshes the chip
      }
      if (window.console) console.warn('ZCAM: live camera unavailable (' + name + ') — using system camera');
      popCameraHistory();   // the entry pushed in the tap is not needed
      if (live.cancelOpen) { live.cancelOpen = false; return; }   // Back was pressed while it started
      openNativeCamera();
    });
  }

  function closeLiveCamera(opts) {
    opts = (opts && typeof opts === 'object' && !opts.type) ? opts : {};   // (an Event is not options)
    // if a clip is recording, stop it first so it's saved (chunks are
    // already durable + uploading; stop() flushes the tail and finalizes), then
    // end the reel. releaseWakeLock is idempotent.
    if (vid.recording) { try { stopRecording('closed'); } catch (e) {} }
    vid.reelId = null; vid.seq = 0;
    releaseWakeLock();
    live.open = false;
    if (live.stream) {
      live.stream.getTracks().forEach(function (t) { try { t.stop(); } catch (e) {} });
      live.stream = null;
    }
    var video = el('zcam-live-video');
    if (video) { try { video.srcObject = null; } catch (e) {} }
    unfreeze(); // never leave a stale freeze/still up for next open
    var still = el('zcam-live-still');
    if (still) still.classList.remove('zcam-live-still-on');
    resetExposureState(); // clear EV filter / focus UI / lock pill
    stopOrientationWatch();   // v1.13.0
    teardownPipe();           // stop any rotated-record canvas pump
    var v = el('zcam-live');
    if (v) { v.classList.remove('zcam-live-open'); v.classList.remove('zcam-live-ori180'); }
    // v1.13.14: take back the history entry the camera added (the popstate this
    // causes is swallowed above, so the app underneath stays open).
    if (!opts.fromPop) popCameraHistory();
    if (opts.message && typeof window.showToast === 'function') { try { window.showToast(opts.message, 4000); } catch (e2) {} }
    keepWidgetInView(); // back to the widget; the queue strip has the detail
  }

  function flipLiveCamera() {
    // no camera swap mid-recording — it would re-acquire the stream and
    // break the MediaRecorder. (Between clips it's free.)
    if (vid.recording) return;
    live.facing = (live.facing === 'environment') ? 'user' : 'environment';
    freezeFrame(); // hide the front/back swap behind the last frame
    if (live.stream) {
      // front+back can't run concurrently — stop first for the flip case
      live.stream.getTracks().forEach(function (t) { try { t.stop(); } catch (e) {} });
      live.stream = null;
    }
    resetZoomState(); // zoom is per-facing; front = digital only
    resetExposureState(); // new scene → neutral exposure, no lock
    acquireStream().then(function () {
      unfreezeWhenReady();
      // rebuild the zoom pill for the new facing (front = digital only,
      // so it'll show digital steps; back = the discovered optical lenses).
      discoverLenses().then(function () { buildZoomUI(); bootToWidestLens(); });
    }).catch(function () { unfreeze(); closeLiveCamera(); });
  }

  /* ══════════════════════════════════════════════════════════════════════════
   * VIDEO MODE — record 1080p30 clips, stream chunks to the durable
   * video queue as they record, finalize into a ZDZ_User_Media video row. Reuses
   * the same viewfinder, lens engine and geo/job provenance as
   * photos. See the VID/vid state block near the top for the contract.
   * ════════════════════════════════════════════════════════════════════════ */
  var vidPosters = {};   // capture_uid → first-frame JPEG Blob (in-memory, this session)

  function nowSqlLocal() {
    var d = new Date(); function p(n) { return (n < 10 ? '0' : '') + n; }
    return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate()) + ' ' +
           p(d.getHours()) + ':' + p(d.getMinutes()) + ':' + p(d.getSeconds());
  }
  function fmtClock(ms) { var s = Math.floor(ms / 1000), m = Math.floor(s / 60); s = s % 60; return m + ':' + (s < 10 ? '0' : '') + s; }
  function haptic(pattern) { // best-effort; iOS Safari ignores vibrate, so the VISUAL warning is primary
    if (navigator.vibrate) { try { navigator.vibrate(pattern); } catch (e) {} }
  }
  function vidError(msg) {
    var s = el('zcam-live-status'); if (s) s.textContent = msg;
    if (window.console) console.warn('ZCAM video: ' + msg);
  }

  function requestWakeLock() {
    if (!('wakeLock' in navigator)) return; // iOS 18.4+ supports it; older no-op
    try {
      navigator.wakeLock.request('screen').then(function (w) { vid.wakeLock = w; }).catch(function () {});
    } catch (e) {}
  }
  function releaseWakeLock() {
    if (vid.wakeLock) { try { vid.wakeLock.release(); } catch (e) {} vid.wakeLock = null; }
  }

  function setMode(mode) {
    if (mode !== 'photo' && mode !== 'video') return;
    if (vid.recording) return;                       // never switch mid-record
    if (mode === 'video' && !videoSupported()) { vidError('Video isn’t supported in this browser.'); return; }
    var was = vid.mode;
    vid.mode = mode; saveMode();
    applyMode();
    // Re-acquire only if the audio requirement changed (photo = no mic).
    if (was !== mode && live.open) {
      freezeFrame();
      if (live.stream) { live.stream.getTracks().forEach(function (t) { try { t.stop(); } catch (e) {} }); live.stream = null; }
      acquireStream().then(function () { unfreezeWhenReady(); }).catch(function () { unfreeze(); });
    }
  }

  function applyMode() {
    var supported = videoSupported();
    var mp = el('zcam-live-mode-photo'), mv = el('zcam-live-mode-video');
    if (mv) mv.hidden = !supported;
    if (!supported && vid.mode === 'video') { vid.mode = 'photo'; saveMode(); }
    if (mp) { mp.classList.toggle('zcam-live-mode-active', vid.mode === 'photo'); mp.setAttribute('aria-selected', vid.mode === 'photo' ? 'true' : 'false'); }
    if (mv) { mv.classList.toggle('zcam-live-mode-active', vid.mode === 'video'); mv.setAttribute('aria-selected', vid.mode === 'video' ? 'true' : 'false'); }
    var sh = el('zcam-live-shutter');
    if (sh) { sh.classList.toggle('zcam-live-shutter-video', vid.mode === 'video'); sh.setAttribute('aria-label', vid.mode === 'video' ? 'Record video' : 'Take photo'); }
    var rec = el('zcam-live-rec'); if (rec && !vid.recording) rec.hidden = true;
    var warn = el('zcam-live-warn'); if (warn && !vid.recording) warn.hidden = true;
    // Photo status counter vs video reel status.
    if (vid.mode === 'video') updateVidStatus(); else updateLiveStatus();
  }

  function updateVidStatus() {
    var s = el('zcam-live-status'); if (!s || vid.mode !== 'video') return;
    if (vid.recording) return;                       // the timer HUD speaks while recording
    var saved = vid.savedClips || 0;
    s.textContent = saved > 0 ? (saved + ' clip' + (saved === 1 ? '' : 's') + ' saved') : 'Ready to record';
  }

  function startReelIfNeeded() {
    if (!vid.reelId) { vid.reelId = ZCamVideoQueue.uid(); vid.seq = 0; }
  }

  function toggleRecording() {
    if (!videoSupported()) { vidError('Video recording isn’t supported in this browser.'); return; }
    if (!vid.recording) {
      var video = el('zcam-live-video');
      if (!video || !video.videoWidth || !live.stream) return;   // stream not ready yet
      startRecording();
    } else {
      stopRecording('user');
    }
  }

  /* rotated-record pipe. When the device is turned, draw each preview
   * frame onto a canvas rotated upright and record THAT (audio re-added from the
   * live stream), so the saved clip is upright everywhere with no rotation
   * metadata to lose. Orientation is LOCKED for the clip. Returns the recordable
   * MediaStream, or null if canvas.captureStream isn't available (the caller then
   * records the raw stream — no worse than before). */
  function buildRotatedRecordStream(cw) {
    var video = el('zcam-live-video');
    if (!video || !video.videoWidth) return null;
    var cnv = document.createElement('canvas');
    if (typeof cnv.captureStream !== 'function') return null;
    var vw = video.videoWidth, vh = video.videoHeight, swap = (cw === 90 || cw === 270);
    cnv.width = swap ? vh : vw; cnv.height = swap ? vw : vh;
    var ctx = cnv.getContext('2d');
    if (!ctx) return null;
    var stream;
    try { stream = cnv.captureStream(30); } catch (e) { return null; }
    if (!stream) return null;
    try {
      var at = (live.stream && live.stream.getAudioTracks) ? live.stream.getAudioTracks()[0] : null;
      if (at) stream.addTrack(at);   // shared mic track — NEVER stopped on teardown
    } catch (e2) {}
    var raf = { on: true, id: 0 };
    (function draw() {
      if (!raf.on) return;
      try {
        ctx.save();
        ctx.translate(cnv.width / 2, cnv.height / 2);
        ctx.rotate(cw * Math.PI / 180);
        ctx.drawImage(video, -vw / 2, -vh / 2, vw, vh);
        ctx.restore();
      } catch (e3) {}
      raf.id = (window.requestAnimationFrame || function (f) { return setTimeout(f, 33); })(draw);
    })();
    vid.pipe = { canvas: cnv, stream: stream, raf: raf };
    return stream;
  }
  function teardownPipe() {
    if (!vid.pipe) return;
    try { vid.pipe.raf.on = false; if (vid.pipe.raf.id && window.cancelAnimationFrame) cancelAnimationFrame(vid.pipe.raf.id); } catch (e) {}
    // Stop ONLY the canvas video track; the audio track belongs to live.stream.
    try { vid.pipe.stream.getVideoTracks().forEach(function (t) { try { t.stop(); } catch (e2) {} }); } catch (e3) {}
    vid.pipe = null;
  }

  function startRecording() {
    var video = el('zcam-live-video');
    if (!video || !live.stream) return;
    var mime = pickVideoMime();
    // lock this clip's orientation. Rotated → record the upright canvas
    // pipe; upright (cw===0) → record the raw stream exactly as before.
    var cw = captureCorrectionCW();
    vid.recordCW = cw;
    teardownPipe();
    var recStream = live.stream;
    if (cw !== 0) {
      var piped = buildRotatedRecordStream(cw);
      if (piped) { recStream = piped; }
      else { vid.recordCW = 0; if (window.console) console.warn('ZCAM video: rotated-record pipe unavailable — recording raw (may be rotated)'); }
    }
    var recW = video.videoWidth || 1920, recH = video.videoHeight || 1080;
    if (vid.recordCW === 90 || vid.recordCW === 270) { var _sw = recW; recW = recH; recH = _sw; }
    var mr;
    try {
      var opts = { videoBitsPerSecond: VID.VBPS, audioBitsPerSecond: VID.ABPS };
      if (mime) opts.mimeType = mime;
      mr = new MediaRecorder(recStream, opts);
    } catch (e) {
      try { mr = new MediaRecorder(recStream); } catch (e2) { teardownPipe(); vidError('This device can’t record video in the browser.'); return; }
    }
    vid.recorder = mr;
    vid.mime = mr.mimeType || mime || 'video/mp4';
    vid.captureUid = ZCamVideoQueue.uid();
    vid.idx = 0;
    startReelIfNeeded();
    var seq = vid.seq;
    var prov = { captured_at: nowSqlLocal() };
    vid.clipProv = prov;

    // Poster: first frame, a moment after start (once AE has painted a frame).
    vidPosters[vid.captureUid] = null;
    setTimeout(function () { grabPoster(vid.captureUid); }, 350);

    ZCamVideoQueue.startClip({
      capture_uid: vid.captureUid, reel_id: vid.reelId, seq: seq,
      mime: vid.mime, width: recW, height: recH,
      captured_at: prov.captured_at, time_source: 'device_clock',
      job_id: jobContext() || 0, note: readNote() || '',
      ajaxurl: cfg().ajaxurl, nonce: cfg().nonce,
      rest_nonce: (window.zdzData && window.zdzData.nonce) || ''
    }).then(function () {
      // Device-fallback GPS, patched onto the manifest when it resolves — the
      // finalize POST (seconds later) usually carries the fix. Never blocks.
      if (st.geoFallback) {
        resolveGeo().then(function (r) {
          if (r && r.fix) {
            ZCamVideoQueue.updateClip(vid.captureUid, {
              gps_lat: r.fix.lat, gps_lng: r.fix.lng, geo_source: 'device_fallback',
              gps_accuracy: (r.fix.acc != null ? r.fix.acc : null)
            });
          }
        }).catch(function () {});
      }
    }).catch(function () {});

    mr.ondataavailable = function (e) { if (e.data && e.data.size) onVidChunk(e.data); };
    mr.onstop = function () { onVidStop(); };
    mr.onerror = function () {}; // keep whatever chunks already landed
    try { mr.start(VID.SLICE_MS); } catch (e3) { try { mr.start(); } catch (e4) { vidError('Could not start recording.'); return; } }

    vid.recording = true;
    requestWakeLock();
    applyRecUI(true);
    startRecTimer();
  }

  function stopRecording(reason) {
    if (!vid.recording) return;
    vid.recording = false;
    stopRecTimer();
    var warn = el('zcam-live-warn'); if (warn) warn.hidden = true;
    applyRecUI(false);
    releaseWakeLock();
    var mr = vid.recorder;
    if (mr && mr.state !== 'inactive') { try { mr.stop(); } catch (e) { onVidStop(); } }
    else { onVidStop(); }
    vid.seq = (vid.seq || 0) + 1;      // next clip in the reel
    updateVidStatus();
  }

  function onVidChunk(blob) {
    var uid = vid.captureUid, idx = vid.idx++;
    ZCamVideoQueue.addChunk(uid, idx, blob, false).then(function () {
      drainVideoChunks();
    }).catch(function (e) { if (window.console) console.warn('ZCAM video: chunk persist failed', e && e.message); });
  }

  function onVidStop() {
    teardownPipe();   // stop the rotated-record canvas pump (after the recorder flushed)
    var uid = vid.captureUid, dur = Date.now() - vid.startTs;
    ZCamVideoQueue.updateClip(uid, { status: 'complete', total_chunks: vid.idx, duration_ms: dur })
      .then(function () { drainVideoChunks(); })
      .catch(function () { drainVideoChunks(); });
  }

  function grabPoster(uid) {
    var c = grabFrameCanvas();
    if (!c || !c.toBlob) return;
    try { c.toBlob(function (blob) { releaseCanvas(c); if (blob && blob.size) vidPosters[uid] = blob; }, 'image/jpeg', 0.7); } catch (e) { releaseCanvas(c); }
  }

  /* ── Recording timer + 30-second warning ─────────────────────────────────── */
  function startRecTimer() {
    vid.startTs = Date.now(); vid.warned = false; vid.ticked = false;
    var rec = el('zcam-live-rec'); if (rec) { rec.hidden = false; rec.classList.remove('zcam-live-rec-warn'); }
    updateRecTime(0);
    vid.timer = setInterval(tickRec, 250);
  }
  function stopRecTimer() {
    if (vid.timer) { clearInterval(vid.timer); vid.timer = null; }
    var rec = el('zcam-live-rec'); if (rec) rec.classList.remove('zcam-live-rec-warn');
  }
  function tickRec() {
    var elapsed = Date.now() - vid.startTs;
    updateRecTime(elapsed);
    if (elapsed >= VID.WARN_MS) {
      if (!vid.warned) { vid.warned = true; var rec = el('zcam-live-rec'); if (rec) rec.classList.add('zcam-live-rec-warn'); haptic([40, 60, 40]); }
      var w = el('zcam-live-warn');
      if (w) { w.hidden = false; w.textContent = Math.max(0, Math.ceil((VID.MAX_MS - elapsed) / 1000)) + 's left'; }
    }
    if (!vid.ticked && elapsed >= VID.TICK_MS) { vid.ticked = true; haptic([30]); }
    if (elapsed >= VID.MAX_MS) { stopRecording('cap'); }   // hard 2:00 auto-stop-and-save
  }
  function updateRecTime(elapsed) {
    if (elapsed == null) elapsed = Date.now() - vid.startTs;
    var t = el('zcam-live-rec-time'); if (t) t.textContent = fmtClock(elapsed);
  }

  function applyRecUI(on) {
    var sh = el('zcam-live-shutter'); if (sh) sh.classList.toggle('zcam-live-shutter-rec', on);
    var rec = el('zcam-live-rec'); if (rec) rec.hidden = !on;
    var flip = el('zcam-live-flip'); if (flip) flip.disabled = on;   // no camera swap mid-record
    var mb = el('zcam-live-modebar'); if (mb) mb.classList.toggle('zcam-live-modebar-lock', on);
    if (!on) { var t = el('zcam-live-rec-time'); if (t) t.textContent = '0:00'; var w = el('zcam-live-warn'); if (w) w.hidden = true; }
  }

  /* ── Foreground chunk drain → finalize (single-flight) ───────────────────── */
  function drainVideoChunks() {
    if (vid.drainBusy) return;
    vid.drainBusy = true;
    pumpVideo().then(function () { vid.drainBusy = false; }).catch(function () { vid.drainBusy = false; });
  }
  function pumpVideo() {
    if (!window.ZCamVideoQueue) return Promise.resolve();
    return ZCamVideoQueue.incompleteClips().then(function (clips) {
      return clips.reduce(function (chain, clip) {
        return chain.then(function () { return uploadClipChunks(clip); });
      }, Promise.resolve());
    });
  }
  function uploadClipChunks(clip) {
    return ZCamVideoQueue.nextChunk(clip.capture_uid, 'fg').then(function (chunk) {
      if (!chunk) { return maybeFinalizeClip(clip); }
      return ZCamVideoQueue.claimChunk(chunk.id, 'fg', 60000).then(function (claimed) {
        if (!claimed) return;   // leased by another drain, or already done
        return postChunk(clip, claimed).then(function () {
          return ZCamVideoQueue.releaseChunk(claimed.id, { status: 'done' }).then(function () {
            return uploadClipChunks(clip);  // next chunk in idx order
          });
        }).catch(function () {
          // transient — return it to the queue; the next drain retries (backoff).
          return ZCamVideoQueue.releaseChunk(claimed.id, { status: 'pending', tries: (claimed.tries || 0) + 1 });
        });
      });
    });
  }
  function maybeFinalizeClip(clip) {
    if (clip.status !== 'complete') return Promise.resolve();   // recorder still running
    return ZCamVideoQueue.chunksFor(clip.capture_uid).then(function (arr) {
      var pending = arr.filter(function (c) { return c.status !== 'done'; });
      if (pending.length) return;                                          // chunks still uploading
      if (clip.total_chunks != null && arr.length < clip.total_chunks) return; // not all persisted yet
      return ZCamVideoQueue.updateClip(clip.capture_uid, { status: 'finalizing' }).then(function (updated) {
        var c2 = updated || clip;
        c2._poster = vidPosters[clip.capture_uid] || null;
        return postFinalize(c2).then(function () {
          vid.savedClips = (vid.savedClips || 0) + 1;
          try { delete vidPosters[clip.capture_uid]; } catch (e) {}
          updateVidStatus();
          return ZCamVideoQueue.removeClip(clip.capture_uid);   // clip is lossless-done: rows gone
        }).catch(function () {
          // finalize failed → back to 'complete' so a later drain retries it.
          return ZCamVideoQueue.updateClip(clip.capture_uid, { status: 'complete' });
        });
      });
    });
  }

  function postChunk(clip, chunk) {
    return new Promise(function (resolve, reject) {
      var blob = ZCamVideoQueue.chunkToBlob(chunk);
      if (!blob) { reject(new Error('no-bytes')); return; }
      var fd = new FormData();
      fd.append('action', 'zcam_video_chunk');
      fd.append('nonce', clip.nonce || cfg().nonce);
      fd.append('capture_uid', clip.capture_uid);
      fd.append('idx', chunk.idx);
      fd.append('chunk', blob, clip.capture_uid + '-' + chunk.idx + '.part');
      var xhr = new XMLHttpRequest();
      xhr.open('POST', clip.ajaxurl || cfg().ajaxurl, true);
      xhr.withCredentials = true;
      xhr.timeout = 45000;
      var rn = clip.rest_nonce || (window.zdzData && window.zdzData.nonce) || '';
      if (rn) xhr.setRequestHeader('X-WP-Nonce', rn);
      xhr.onload = function () {
        var resp = null; try { resp = JSON.parse(xhr.responseText); } catch (e) {}
        if (resp && resp.success) { resolve(resp.data); return; }
        var er = new Error(resp && resp.data ? String(resp.data) : ('Failed (' + xhr.status + ')'));
        er.retriable = isRetriableStatus(xhr.status); reject(er);
      };
      xhr.onerror = function () { var e = new Error('network'); e.retriable = true; reject(e); };
      xhr.ontimeout = function () { var e = new Error('timeout'); e.retriable = true; reject(e); };
      xhr.send(fd);
    });
  }

  function postFinalize(clip) {
    return new Promise(function (resolve, reject) {
      var fd = new FormData();
      fd.append('action', 'zcam_video_finalize');
      fd.append('nonce', clip.nonce || cfg().nonce);
      fd.append('capture_uid', clip.capture_uid);
      fd.append('total_chunks', clip.total_chunks != null ? clip.total_chunks : 0);
      fd.append('reel_id', clip.reel_id || '');
      fd.append('seq', clip.seq != null ? clip.seq : 0);
      fd.append('mime', clip.mime || 'video/mp4');
      fd.append('ext', vidExtFor(clip.mime));
      if (clip.width) fd.append('width', clip.width);
      if (clip.height) fd.append('height', clip.height);
      if (clip.duration_ms) fd.append('duration_ms', clip.duration_ms);
      if (clip.captured_at) fd.append('captured_at', clip.captured_at);
      if (clip.gps_lat != null) fd.append('gps_lat', clip.gps_lat);
      if (clip.gps_lng != null) fd.append('gps_lng', clip.gps_lng);
      if (clip.geo_source) fd.append('geo_source', clip.geo_source);
      fd.append('time_source', clip.time_source || 'device_clock');
      if (clip.gps_accuracy != null) fd.append('gps_accuracy', clip.gps_accuracy);
      if (clip.job_id) fd.append('job_id', clip.job_id);
      if (clip.note) fd.append('note', clip.note);
      if (clip._poster) fd.append('poster', clip._poster, clip.capture_uid + '-poster.jpg');
      var xhr = new XMLHttpRequest();
      xhr.open('POST', clip.ajaxurl || cfg().ajaxurl, true);
      xhr.withCredentials = true;
      xhr.timeout = 60000;
      var rn = clip.rest_nonce || (window.zdzData && window.zdzData.nonce) || '';
      if (rn) xhr.setRequestHeader('X-WP-Nonce', rn);
      xhr.onload = function () {
        var resp = null; try { resp = JSON.parse(xhr.responseText); } catch (e) {}
        if (resp && resp.success) { resolve(resp.data); return; }
        var er = new Error(resp && resp.data ? String(resp.data) : ('Failed (' + xhr.status + ')'));
        er.retriable = isRetriableStatus(xhr.status); reject(er);
      };
      xhr.onerror = function () { var e = new Error('network'); e.retriable = true; reject(e); };
      xhr.ontimeout = function () { var e = new Error('timeout'); e.retriable = true; reject(e); };
      xhr.send(fd);
    });
  }

  // Shutter: grab the current frame and feed it to the standard ingest
  // pipeline. The viewfinder keeps running throughout. v1.5.0: when digital
  // zoom is active the capture crops the SAME center region the preview
  // shows (real pixels, no upscaling) — WYSIWYG.
  // grab the current viewfinder frame (center-cropped to the active
  // digital zoom, with software-EV baked in) onto a fresh canvas. Returns the
  // canvas, or null if the stream isn't paintable yet.
  function grabFrameCanvas() {
    var video = el('zcam-live-video');
    if (!video || !video.videoWidth) return null;
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
      // bake SOFTWARE exposure comp into the capture so the JPEG
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
    } catch (e) { return null; }
    // bake device orientation in so the saved pixels are always upright
    // (dimensions swap on the quarter turns). cw===0 (upright) returns c as-is.
    return orientedCanvas(c, captureCorrectionCW());
  }

  // is this a blown-out / not-yet-settled frame? TRUE only for a
  // near-uniform, near-white frame — the classic iOS "auto-exposure hasn't
  // converged yet" grab (the whole frame washes out bright for the first few
  // hundred ms after the stream starts / a lens switch). A genuinely bright but
  // STRUCTURED scene (sky, snow, a sunlit house) has real tonal variance and is
  // kept. Never throws: a read error (e.g. a tainted canvas) returns false so
  // it can never block a capture.
  function frameLooksBlownOut(c) {
    try {
      var w = 40, h = 40;
      var t = document.createElement('canvas'); t.width = w; t.height = h;
      var tc = t.getContext('2d'); tc.drawImage(c, 0, 0, w, h);
      var d = tc.getImageData(0, 0, w, h).data;
      var n = w * h, sat = 0, sum = 0, sumsq = 0;
      for (var i = 0; i < d.length; i += 4) {
        var lum = d[i] * 0.299 + d[i + 1] * 0.587 + d[i + 2] * 0.114;
        sum += lum; sumsq += lum * lum;
        if (d[i] >= 249 && d[i + 1] >= 249 && d[i + 2] >= 249) sat++;
      }
      var mean = sum / n;
      var variance = (sumsq / n) - (mean * mean);
      return (sat / n) >= 0.82 && mean >= 236 && variance <= 25;
    } catch (e) { return false; }
  }

  function liveShutter() {
    requestOrientationPermission(); // v1.13.14: in the tap, once, after the shot is framed
    // in video mode the shutter is a push-to-record / push-to-stop
    // toggle (not a photo). Guarded so an unsupported engine can never record.
    if (vid.mode === 'video') { toggleRecording(); return; }
    if (live.busy) return;
    var video = el('zcam-live-video');
    if (!video || !video.videoWidth) return; // stream not ready yet
    live.busy = true;
    shutterBlink();
    shutterHaptic();
    attemptCapture(0);
  }

  // capture with a settle-retry. If the grabbed frame is a blown-out
  // not-yet-settled frame, wait a beat for the sensor's auto-exposure to
  // converge and grab again (up to a few tries, ~1s total) — so a washed-out
  // frame is never what gets saved. On the last try we keep whatever we have,
  // so a shot is never lost (a genuinely uniform-bright scene still saves).
  function attemptCapture(tries) {
    var c = grabFrameCanvas();
    if (!c) { live.busy = false; return; }
    if (tries < 4 && frameLooksBlownOut(c)) {
      releaseCanvas(c);   // v1.13.14
      setTimeout(function () { attemptCapture(tries + 1); }, 280);
      return;
    }
    finishCapture(c);
  }

  function finishCapture(c) {
    showCapturedStill(c); // full-screen "this is the photo you took"
    if (!c.toBlob) { live.busy = false; return; } // (Safari 11+ has toBlob)
    c.toBlob(function (blob) {
      live.busy = false;
      releaseCanvas(c);   // v1.13.14: the JPEG is made; free the 48 MB frame now
      if (!blob || !blob.size) {
        if (window.console) console.warn('ZCAM: empty frame — try again');
        return;
      }
      popThumb(blob); // roll-style confirmation
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
    // the system-camera round trip is long; a fix requested now is
    // usually waiting by the time the user comes back with a photo.
    prewarmGeo();
    var input = el('zcam-w-file');
    if (input) input.click();
  }

  function triggerCapture() {
    openLiveCamera(); // live viewfinder first; system camera fallback
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

  // Shared ingest for BOTH capture paths — the in-page live camera
  // and the native file-input fallback. Reads the note/sticky label, resolves
  // provenance, persists durably (IndexedDB + liveBytes), renders the queue
  // item, and kicks the background upload. The single entry point into the
  // proven v1.2.x pipeline.
  /* ── v1.11.0: Upload payload prep + retry policy — the same as the Media app
   * so both apps share the same resilient uploader.
   * Resolution-first shrink (a photo already within UP_MAX_EDGE uploads
   * UNTOUCHED; a bigger one is reduced to UP_MAX_EDGE at q0.92 — the LTE speed
   * win), a DNG's embedded JPEG pulled out rather than sending the RAW, and a
   * transient-vs-permanent retry classification with backoff. GPS/where/when are
   * read from the ORIGINAL (readProvenance) BEFORE prep and sent as fields, so
   * the shrink (which strips EXIF) never loses the geotag. Every path falls back
   * to the ORIGINAL file on any trouble — reliability then rests on the durable
   * queue + retry, exactly as before. ──────────────────────────────────────── */
  var UP_MAX_EDGE   = 3000;      // only shrink photos LARGER than this on the long edge
  var UP_JPEG_Q     = 0.92;      // re-encode quality (high — resolution is paramount)
  var UP_MIN_SHRINK = 1200000;   // don't even decode files under ~1.2 MB
  function upExt(name) { var m = /\.([a-z0-9]+)$/i.exec(name || ''); return m ? m[1].toLowerCase() : ''; }
  function upBase(name) { return (name || 'photo').replace(/\.[^.]+$/, ''); }
  function looksRaw(file) { return /dng|x-adobe-dng|(^|\W)raw(\W|$)/i.test(file.type || '') || upExt(file.name) === 'dng'; }
  function isRasterable(file) {
    if (looksRaw(file)) return false;
    if (/^image\/(jpeg|png|webp|gif|bmp|heic|heif|tiff?)$/i.test(file.type || '')) return true;
    return /\.(jpe?g|png|webp|gif|bmp|heic|heif|tiff?)$/i.test(file.name || '');
  }
  // Pull the largest embedded JPEG (FFD8…FFD9) out of a DNG/RAW file's bytes.
  function extractJpegFromRaw(file) {
    var read = file.arrayBuffer
      ? file.arrayBuffer()
      : new Promise(function (res, rej) {
          var fr = new FileReader();
          fr.onload = function () { res(fr.result); };
          fr.onerror = function () { rej(fr.error); };
          fr.readAsArrayBuffer(file);
        });
    return read.then(function (buf) {
      var b = new Uint8Array(buf), n = b.length, i = 0, best = null, bestLen = 0;
      while (i < n - 1) {
        if (b[i] === 0xFF && b[i + 1] === 0xD8) {          // SOI
          var j = i + 2;
          while (j < n - 1 && !(b[j] === 0xFF && b[j + 1] === 0xD9)) j++;
          if (j < n - 1) j += 2;                            // include EOI
          var len = j - i;
          if (len > bestLen) { bestLen = len; best = [i, j]; }
          i = j;
        } else { i++; }
      }
      if (best && bestLen > 20000) { return new Blob([b.subarray(best[0], best[1])], { type: 'image/jpeg' }); }
      return null;
    }).catch(function () { return null; });
  }
  // Downscale a rasterable image to UP_MAX_EDGE, baking EXIF orientation in.
  function downscaleRaster(file) {
    if (typeof createImageBitmap !== 'function') return Promise.resolve(null);
    return createImageBitmap(file, { imageOrientation: 'from-image' }).then(function (bmp) {
      var w = bmp.width, h = bmp.height, longest = Math.max(w, h);
      // Resolution-first: within the cap → leave ALONE (null → upload original).
      if (longest <= UP_MAX_EDGE) { if (bmp.close) bmp.close(); return null; }
      var s = UP_MAX_EDGE / longest;
      var cw = Math.max(1, Math.round(w * s)), ch = Math.max(1, Math.round(h * s));
      var c = document.createElement('canvas'); c.width = cw; c.height = ch;
      c.getContext('2d').drawImage(bmp, 0, 0, cw, ch);
      if (bmp.close) bmp.close();
      return new Promise(function (res) { c.toBlob(function (blob) { res(blob); }, 'image/jpeg', UP_JPEG_Q); });
    }).catch(function () { return null; });
  }
  // Decide what actually gets uploaded. ALWAYS resolves to a File; on any
  // trouble it returns the ORIGINAL file untouched.
  function prepareUpload(file) {
    function asFile(blob, name, type) {
      if (blob === file) return file;
      try { return new File([blob], name, { type: type || blob.type || 'image/jpeg' }); }
      catch (e) { try { blob.name = name; } catch (e2) {} return blob; }
    }
    if (!file) return Promise.resolve(file);
    if (looksRaw(file)) {
      return extractJpegFromRaw(file).then(function (blob) {
        return blob ? asFile(blob, upBase(file.name) + '.jpg', 'image/jpeg') : file;
      });
    }
    if (!isRasterable(file) || (file.size || 0) < UP_MIN_SHRINK) {
      return Promise.resolve(file);
    }
    return downscaleRaster(file).then(function (blob) {
      return (blob && blob.size && blob.size < (file.size || Infinity))
        ? asFile(blob, upBase(file.name) + '.jpg', 'image/jpeg')
        : file;
    });
  }
  // Retry policy: transient/gateway statuses retry with backoff; a
  // real "no" (auth, too large, bad file) is NOT auto-retried so it isn't
  // hammered forever — it stays in the queue with a manual Retry button.
  function isRetriableStatus(s) { return s === 0 || s === 408 || s === 429 || s === 500 || s === 502 || s === 503 || s === 504; }
  function uploadBackoffMs(tries) { return Math.min(30000, 1500 * Math.pow(2, Math.max(0, tries - 1))) + Math.floor(Math.random() * 500); }

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

    // v1.8.0 ORDERING CHANGE, and it is the most important line in this file:
    // NOTHING may sit between reading the file and persisting it. Geolocation
    // used to be awaited here, which meant a 4-second window per shot where the
    // photo lived only in this closure and a backgrounded app lost it outright.
    // Now we queue first and patch coordinates onto the durable record after.
    prepareUpload(file).then(function (uploadFile) {
    return readProvenance(file).then(function (prov) {
      // GPS that came straight off the file's EXIF is 'exif'-sourced and always
      // wins; a device fix can only ever fill a gap.
      if (prov.gps_lat != null && !prov.geo_source) prov.geo_source = 'exif';

      // geo_source describes the GPS provenance; time_source describes the
      // capture-time provenance ('exif' vs 'device_clock'). They are independent
      // and both feed the inspector's provenance display.
      var meta = {};
      if (prov.geo_source)  meta.geo_source  = prov.geo_source;
      if (prov.time_source) meta.time_source = prov.time_source;
      if (jobContext())     meta.job_id      = jobContext();   // v1.8.0

      // Persist to IndexedDB — the capture is now DURABLE. The note travels
      // with the record so a Background-Sync retry still carries it.
      // also capture the theme's fresh wp_rest nonce (zdzData.nonce) at
      // shutter — it rides the record (rest_nonce) so the Service Worker, which
      // has no window.zdzData, can authenticate a Background-Sync retry too.
      return ZCamQueue.add(uploadFile, prov, meta, {
        ajaxurl: cfg().ajaxurl,
        nonce: cfg().nonce,
        rest_nonce: (window.zdzData && window.zdzData.nonce) || '',
        note: note,
        source_ref: jobContext() ? ('job:' + jobContext()) : ''
      }).then(function (rec) {
        // Safe from here. Now go looking for coordinates.
        if (prov.gps_lat != null || !st.geoFallback) return rec;
        return resolveGeo().then(function (r) {
          var m = rec.meta || {};
          if (!r.fix) {
            // Carry WHY, so the server log can separate "declined" from "no
            // signal in a metal-roofed patio" -- previously indistinguishable.
            m.geo_reason = r.reason || 'unavailable';
            return ZCamQueue.update(rec.id, { meta: m })
              .then(function (u) { return u || rec; })
              .catch(function () { return rec; });
          }
          m.geo_source = 'device_fallback';
          if (r.fix.acc != null) m.gps_accuracy = r.fix.acc;
          return ZCamQueue.update(rec.id, {
            gps_lat: r.fix.lat, gps_lng: r.fix.lng, meta: m
          }).then(function (updated) { return updated || rec; });
        }).catch(function () { return rec; });  // a geo failure must never lose a photo
      });
    });
    }).then(function (rec) {
      // rec.buf is the in-memory ArrayBuffer add() just wrote (NOT a
      // DB read-back). Keep it for this session so the upload can't be
      // defeated by iOS returning an empty buffer for a fresh record.
      rememberLiveBytes(rec.capture_uid, rec.buf);
      // session bookkeeping — count this capture's save when it lands.
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
      if (window.console) console.warn('ZCAM queue unavailable, direct upload:', err && err.message);
    });
  }

  /* ── Foreground drain (runs while an app tab is open) ─────────────────── */

  // the caller resolves (and verifies) the upload bytes first and
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
    // accuracy in metres, and the job this capture was launched from.
    // NOTE both builders forward meta keys BY NAME -- a new key is not carried
    // automatically. Anything added here must be added to directUploadFallback
    // too, or it silently vanishes on the no-IndexedDB path.
    if (rec.meta && rec.meta.gps_accuracy != null) fd.append('gps_accuracy', rec.meta.gps_accuracy);
    if (rec.meta && rec.meta.geo_reason) fd.append('geo_reason', rec.meta.geo_reason);
    if (rec.meta && rec.meta.job_id) fd.append('job_id', rec.meta.job_id);
    if (rec.source_ref) fd.append('source_ref', rec.source_ref);
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
      // verified bytes only (IndexedDB read-back checked against the
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
      // fresh wp_rest auth via X-WP-Nonce (legacy nonce field is still
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
        if (resp && resp.success) { resolve(resp.data); return; }
        // classify — a transient/gateway status is retriable; a real
        // "no" (auth, 413 too-large, bad file) is permanent and fails fast so a
        // genuinely bad photo isn't auto-retried forever.
        var e = new Error(resp && resp.data ? String(resp.data) : ('Failed (' + xhr.status + ')'));
        e.retriable = isRetriableStatus(xhr.status);
        e.httpStatus = xhr.status;
        reject(e);
      };
      xhr.onerror   = function () { var e = new Error('network'); e.retriable = true; reject(e); };
      xhr.ontimeout = function () { var e = new Error('timeout'); e.retriable = true; reject(e); };
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
  function pumpForeground(force) {
    if (st.fgBusy) return Promise.resolve();
    st.fgBusy = true;
    var now = Date.now();
    return ZCamQueue.all().then(function (items) {
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
          return ZCamQueue.release(rec.id, { status: 'failed' }).then(function () {
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
          if (ZCamQueue.isLeasedByOther(r, 'fg')) return false;
          // a PERMANENT failure (auth / 413 / bad file) is not auto-
          // retried — it waits for a manual Retry. A transient failure waits out
          // its backoff before the next auto-attempt. `force` (reconnect / manual
          // Retry) bypasses both so a recovered network retries immediately.
          if (!force) {
            if (r.permanent) return false;
            if (r.next_retry_at && r.next_retry_at > Date.now()) return false;
          }
          return true;
        })
        .sort(function (a, b) { return a.created_at - b.created_at; });

      // Sequential to avoid hammering a weak shop connection.
      return queue.reduce(function (chain, rec) {
        return chain.then(function () {
          // CLAIM the record (atomic read-modify-write). If we don't win — another
          // drain grabbed it between our scan and now — skip it this pass.
          return ZCamQueue.claim(rec.id, 'fg', FG_LEASE_MS).then(function (claimed) {
            if (!claimed) return; // lost the race; leave it to the lease holder
            return uploadRecord(claimed).then(function (data) {
              return ZCamQueue.remove(claimed.id).then(function () {
                forgetLiveBytes(claimed.capture_uid); // saved — release the bytes
                markQueueItemDone(claimed.id, data);
                st.galLoaded = false; // gallery is now stale
                // carry the capture_uid so the session counter can
                // attribute this save to the current shooting session.
                if (data && typeof data === 'object') data._capture_uid = claimed.capture_uid;
                document.dispatchEvent(new CustomEvent('zcam_photo_saved', { detail: data }));
              });
            }).catch(function (err) {
              // classify the failure and release the lease in ONE write.
              // A transient/gateway failure gets a BACKOFF before the next auto-
              // attempt (no hammering a weak uplink); a PERMANENT failure (auth /
              // 413 / bad file) is NOT auto-retried — it waits for a manual Retry.
              // Either way the record STAYS (lossless); capture_uid keeps any
              // retry idempotent. A reconnect (online) or manual Retry forces it.
              var tries = (claimed.tries || 0) + 1;
              var permanent = !!(err && err.retriable === false);
              var patch = {
                status: 'failed',
                tries: tries,
                permanent: permanent,
                next_retry_at: permanent ? 0 : (Date.now() + uploadBackoffMs(tries))
              };
              return ZCamQueue.release(claimed.id, patch)
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
        reg.sync.register(ZCamQueue.SYNC_TAG).catch(function () { /* fg covers it */ });
      }
      // No SyncManager (iOS Safari): the foreground drain is the path.
    }).catch(function () {});
  }

  // IndexedDB-less fallback: one-shot upload, no persistence.
  function directUploadFallback(file, note) {
    // Same uid for the life of this attempt, including retries.
    var fallbackUid = 'f' + Date.now().toString(36) + '-' +
      Math.floor(Math.random() * 0x10000).toString(36);
    readProvenance(file).then(function (prov) {
      var finish = function () {
        var fd = new FormData();
        fd.append('action', 'zcam_save_photo');
        fd.append('nonce', cfg().nonce);
        fd.append('photo', file, file.name || ('photo-' + Date.now() + '.jpg'));
        // this path used to omit capture_uid entirely, so it had NO
        // server-side idempotency -- source_ref fell back to 'photo:<att_id>'
        // and a retry inserted a duplicate row. Mint one here too.
        fd.append('capture_uid', fallbackUid);
        // captured_at is now always present (EXIF or device-clock fallback).
        if (prov.captured_at) fd.append('captured_at', prov.captured_at);
        if (prov.gps_lat != null) fd.append('gps_lat', prov.gps_lat);
        if (prov.gps_lng != null) fd.append('gps_lng', prov.gps_lng);
        if (prov.geo_source) fd.append('geo_source', prov.geo_source);
        if (prov.time_source) fd.append('time_source', prov.time_source);
        if (prov.gps_accuracy != null) fd.append('gps_accuracy', prov.gps_accuracy);
        if (jobContext()) { fd.append('job_id', jobContext()); fd.append('source_ref', 'job:' + jobContext()); }
        if (note) fd.append('note', note);
        var tmp = { id: 'tmp' + Date.now() };
        var thumb = ''; try { thumb = URL.createObjectURL(file); } catch (e) {}
        renderQueueItem({ id: tmp.id }, thumb);
        // XHR here too (parity with the durable path + the Media app).
        var xhr = new XMLHttpRequest();
        xhr.open('POST', cfg().ajaxurl, true);
        xhr.withCredentials = true;
        xhr.timeout = UPLOAD_TIMEOUT_MS;
        // fresh wp_rest auth via X-WP-Nonce (dual-accept server-side).
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
      // Honour geo-fallback here too so direct-upload captures are still
      // geotagged. This path has no durable record to patch afterwards, so it
      // does have to wait -- but it now uses the PREWARMED fix, which is
      // usually already in hand, and it is the rare no-IndexedDB path.
      if (prov.gps_lat == null && st.geoFallback) {
        resolveGeo().then(function (r) {
          if (r.fix) {
            prov.gps_lat = r.fix.lat; prov.gps_lng = r.fix.lng;
            prov.geo_source = 'device_fallback';
            if (r.fix.acc != null) prov.gps_accuracy = r.fix.acc;
          }
          finish();
        }).catch(finish);
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
    if (item.classList.contains('zcam-w-q-item-failed')) { item.classList.remove('zcam-w-q-item-failed'); syncFailSummary(); }

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
    item.classList.remove('zcam-w-q-item-failed');
    syncFailSummary();
    item.style.opacity = '0';
    setTimeout(function () { if (item.parentNode) item.parentNode.removeChild(item); }, 300);
  }

  /* Clearing failed uploads one row at a time, two taps each, is slow. With two or more failed photos, one
   * line above the list now says how many and acts on all of them: Retry all, or Discard
   * (two taps, because for a photo that never uploaded this deletes the only copy). */
  function failedRows() {
    var q = el('zcam-w-queue');
    return q ? Array.prototype.slice.call(q.querySelectorAll('.zcam-w-q-item-failed')) : [];
  }
  function rowRecordId(item) {
    var n = parseInt(String(item.id || '').replace('zcam-q-', ''), 10);
    return isNaN(n) ? null : n;
  }
  function syncFailSummary() {
    var q = el('zcam-w-queue'); if (!q) return;
    var rows = failedRows(), box = el('zcam-w-q-summary');
    if (rows.length < 2) { if (box && box.parentNode) box.parentNode.removeChild(box); return; }
    if (!box) {
      box = document.createElement('div');
      box.id = 'zcam-w-q-summary'; box.className = 'zcam-w-q-summary'; box.setAttribute('role', 'status');
      box.innerHTML = '<span class="zcam-w-q-summary-text"></span>' +
        '<button type="button" class="zcam-w-q-retry" id="zcam-w-q-retry-all">Retry all</button>' +
        '<button type="button" class="zcam-w-q-discard zcam-w-q-discard-all" id="zcam-w-q-discard-all">Discard</button>';
      q.parentNode.insertBefore(box, q);
      el('zcam-w-q-retry-all').addEventListener('click', retryAllFailed);
      el('zcam-w-q-discard-all').addEventListener('click', discardAllFailed);
    }
    box.querySelector('.zcam-w-q-summary-text').textContent = rows.length + ' photos didn\u2019t upload';
    var d = el('zcam-w-q-discard-all');
    if (d && d.dataset.armed) d.textContent = 'Discard ' + rows.length + '?';
  }
  function retryAllFailed() {
    var rows = failedRows(), ids = [];
    rows.forEach(function (item) {
      var id = rowRecordId(item); if (id === null) return;
      ids.push(id);
      item.classList.remove('zcam-w-q-item-failed');
      var state = item.querySelector('.zcam-w-q-state');
      if (state) state.innerHTML = '<span class="zcam-w-spinner"></span><span class="zcam-w-q-label">Uploading\u2026</span>';
    });
    syncFailSummary();
    Promise.all(ids.map(function (id) {
      return ZCamQueue.update(id, { status: 'pending', permanent: false, next_retry_at: 0 }).catch(function () {});
    })).then(function () { pumpForeground(true); });
  }
  function discardAllFailed() {
    var d = el('zcam-w-q-discard-all'); if (!d) return;
    var rows = failedRows();
    if (!d.dataset.armed) {
      d.dataset.armed = '1';
      d.textContent = 'Discard ' + rows.length + '?';
      d.classList.add('zcam-w-q-discard-armed');
      setTimeout(function () {
        if (!d.parentNode) return;
        delete d.dataset.armed; d.textContent = 'Discard'; d.classList.remove('zcam-w-q-discard-armed');
      }, 4000);
      return;
    }
    rows.forEach(function (item) {
      var id = rowRecordId(item);
      if (id === null) { removeQueueItemRow(item); return; }
      ZCamQueue.get(id).then(function (rec) {
        if (rec && rec.capture_uid) forgetLiveBytes(rec.capture_uid);
        return ZCamQueue.remove(id);
      }).then(function () { removeQueueItemRow(item); }, function () { removeQueueItemRow(item); });
    });
  }

  function markQueueItemFailed(id) {
    var item = el('zcam-q-' + id); if (!item) return;
    var state = item.querySelector('.zcam-w-q-state');
    if (state) {
      // a failed item can now be DISCARDED by the user, not only
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
          // a manual Retry clears any permanent/backoff gate and forces
          // an immediate attempt.
          ZCamQueue.update(realId, { status: 'pending', permanent: false, next_retry_at: 0 }).then(function () {
            state.innerHTML = '<span class="zcam-w-spinner"></span><span class="zcam-w-q-label">Uploading…</span>';
            item.classList.remove('zcam-w-q-item-failed'); syncFailSummary(); // v1.13.14
            pumpForeground(true);
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
        ZCamQueue.get(realId).then(function (rec) {
          if (rec && rec.capture_uid) forgetLiveBytes(rec.capture_uid);
          return ZCamQueue.remove(realId);
        }).then(function () {
          removeQueueItemRow(item);
        }).catch(function () {
          removeQueueItemRow(item); // row goes regardless; worst case the
          // record resurfaces on next load and can be discarded again
        });
      });
    }
    item.classList.add('zcam-w-q-item-failed');
    syncFailSummary();   // v1.13.14
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
            '<img class="zcam-w-gallery-thumb" src="' + esc(p.thumbnail_url) + '"' +
              // high-density screens take the 768-pixel thumb.
              (p.thumbnail_2x_url ? ' srcset="' + esc(p.thumbnail_url) + ' 1x, ' + esc(p.thumbnail_2x_url) + ' 2x"' : '') +
              ' alt="' + esc(p.title) + '" loading="lazy" />' +
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
    if (!window.ZCamQueue) return;
    ZCamQueue.all().then(function (items) {
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
        return chain.then(function () { return ZCamQueue.release(r.id, { status: 'pending' }); });
      }, Promise.resolve()).then(function () { return items; });
    }).then(function (items) {
      items.filter(function (r) { return r.status !== 'done'; })
           .sort(function (a, b) { return a.created_at - b.created_at; })
           .forEach(function (r) {
             // Rebuild a thumb from the stored bytes (buf → Blob; legacy file as fallback).
             var thumb = ''; try { var b = ZCamQueue.bufToBlob(r); if (b) thumb = URL.createObjectURL(b); } catch (e) {}
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
    // live-view counter — attribute saves to the current shooting
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

    // reflect a previously-denied location permission on load, so the
    // "how to turn it back on" path exists even if the user never taps capture.
    syncGeoChip();

    // same for the camera — reconcile our record with the browser's
    // actual permission where the engine exposes it, and surface the "camera
    // off" chip on load if it was denied.
    syncCamPermission();
    syncCamChip();

    resumeQueue();
  }

  // Retry triggers that work even WITHOUT Background Sync (covers iOS Safari):
  window.addEventListener('online', function () { pumpForeground(true); }); // reconnect → retry now, ignore backoff
  document.addEventListener('visibilitychange', function () {
    if (document.visibilityState === 'visible') {
      pumpForeground();
      // iOS stops camera tracks when the app is backgrounded —
      // re-acquire the stream if the live view is open with a dead track.
      if (live.open) {
        var dead = !live.stream || live.stream.getVideoTracks().every(function (t) { return t.readyState === 'ended'; });
        if (dead) acquireStream().catch(function () { closeLiveCamera({ message: 'The camera stopped. Tap Take Photo to open it again.' }); });
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
    if (opts.job_id) { setJobContext(opts.job_id); }   // v1.8.0
    triggerCapture();
  });
})();
