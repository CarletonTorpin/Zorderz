/**
 * Zorderz Service Worker
 *
 * MINIMAL SERVICE WORKER — bridge token, self-healing shell, durable camera
 * uploads. It caches no assets of its own; a page cache or CDN does that.
 *
 *  1. Login bridge. The custom fetch handler serves the virtual
 *     /_zdz-bridge-token endpoint, which stores and retrieves bridge tokens in
 *     CacheStorage. CacheStorage is shared between Safari and standalone PWA
 *     mode on iOS, which is the key property that lets a login in Safari reach
 *     the installed app.
 *
 *  2. Self-healing shell. Devices reuse a cached dashboard shell for DAYS
 *     (stale baked nonces, stale ?ver= script URLs), so plugin updates never
 *     reach installed PWAs and server purges cannot help: the staleness is in
 *     the device's own HTTP cache. Same-origin GET navigations are therefore
 *     fetched NETWORK-FIRST with an explicit HTTP-cache bypass
 *     (cache:'reload'), falling back to a normal fetch (which may serve from
 *     cache) only when the network fails. Every online launch gets the
 *     CURRENT shell; wp-admin is left untouched. The theme's logged-in
 *     no-cache headers make the first load correct before this SW controls
 *     the page.
 *
 *  3. Updates. activate() deletes EVERY CacheStorage bucket except the bridge
 *     cache, so a theme update can never leave a stale shell or asset behind.
 *     A `message` handler lets the page tell a waiting SW to skipWaiting: the
 *     page's "App update ready" pill invokes it mid-session, and updates found
 *     in the launch window apply on their own.
 *
 *  4. Durable camera uploads. The `sync` handler (tag 'zdz-upload-sync')
 *     drains the camera's IndexedDB store (zdz-camera → uploads) when
 *     connectivity returns, even if the tab/PWA has since closed
 *     (Android/Chromium). See the section below for the lease that stops the
 *     page and this SW uploading the same capture twice, and for why records
 *     carry bytes rather than a Blob.
 *
 * The page registers this file as /zdz-sw (extension-less, so hosts whose
 * nginx serves *.js statically still route it to WordPress).
 */

var BRIDGE_CACHE = 'zdz-bridge-v1';
var BRIDGE_KEY   = '/_zdz-bridge-token';

// ---- INSTALL ----
self.addEventListener('install', function () {
  self.skipWaiting();
});

// ---- ACTIVATE ----
self.addEventListener('activate', function (event) {
  event.waitUntil(
    // On activation of a NEW service worker, delete EVERY CacheStorage bucket
    // EXCEPT the bridge cache (which holds the auth-bridge token + camera upload
    // support and must survive). Clearing everything means a theme update can
    // never leave a stale cached shell/asset behind on an installed PWA — the
    // cause of "deployed but still looks old". The bridge cache is explicitly
    // preserved so login + durable camera uploads are unaffected.
    caches.keys().then(function (names) {
      return Promise.all(
        names
          .filter(function (name) { return name !== BRIDGE_CACHE; })
          .map(function (name) { return caches.delete(name); })
      );
    }).then(function () {
      return self.clients.claim();
    })
  );
});

// ---- MESSAGE: let the page force an immediate update ----
// The page can postMessage({type:'zdz-skip-waiting'}) to tell a freshly
// installed (waiting) SW to take over now, so a stale shell doesn't linger until
// every tab closes. Harmless if no waiting worker exists.
self.addEventListener('message', function (event) {
  if (event.data && event.data.type === 'zdz-skip-waiting') {
    self.skipWaiting();
  }
});

// ---- FETCH ----
self.addEventListener('fetch', function (event) {
  var url = new URL(event.request.url);

  // ── Self-healing app shell ──────────────────────────────────────────────
  // Same-origin GET navigations (opening the PWA, any page load) are fetched
  // network-first with an explicit HTTP-cache BYPASS, so a device can never
  // keep serving a stale cached shell (stale nonces + stale ?ver= scripts,
  // which broke camera uploads). Offline/failed → fall back
  // to a normal fetch, which may serve the cached copy (desired: a stale
  // shell offline beats no shell). wp-admin is excluded — leave WP's own
  // admin behavior alone. The navigation Request keeps redirect:'manual', so
  // login redirects pass through as opaqueredirect and the browser follows
  // them itself, exactly like an uncontrolled navigation.
  if (event.request.mode === 'navigate' &&
      event.request.method === 'GET' &&
      url.origin === self.location.origin &&
      url.pathname.indexOf('/wp-admin') !== 0) {
    event.respondWith((function () {
      try {
        return fetch(event.request, { cache: 'reload' })
          .catch(function () { return fetch(event.request); });
      } catch (e) {
        // Very old engines that reject the cache override → normal fetch.
        return fetch(event.request);
      }
    })());
    return;
  }

  // Only handle the bridge token virtual endpoint
  if (url.pathname !== BRIDGE_KEY) return;

  var method = event.request.method;

  if (method === 'POST') {
    // Store the bridge token in CacheStorage
    event.respondWith(
      event.request.text().then(function (body) {
        return caches.open(BRIDGE_CACHE).then(function (cache) {
          var response = new Response(body, {
            status: 200,
            headers: { 'Content-Type': 'application/json' }
          });
          return cache.put(BRIDGE_KEY, response);
        }).then(function () {
          return new Response(JSON.stringify({ ok: true }), {
            status: 200,
            headers: { 'Content-Type': 'application/json' }
          });
        });
      }).catch(function () {
        return new Response(JSON.stringify({ ok: false }), {
          status: 500,
          headers: { 'Content-Type': 'application/json' }
        });
      })
    );
    return;
  }

  if (method === 'GET') {
    // Retrieve the bridge token from CacheStorage
    event.respondWith(
      caches.open(BRIDGE_CACHE).then(function (cache) {
        return cache.match(BRIDGE_KEY);
      }).then(function (cached) {
        if (cached) return cached.clone();
        return new Response(JSON.stringify({ bridge_token: null }), {
          status: 200,
          headers: { 'Content-Type': 'application/json' }
        });
      }).catch(function () {
        return new Response(JSON.stringify({ bridge_token: null }), {
          status: 200,
          headers: { 'Content-Type': 'application/json' }
        });
      })
    );
    return;
  }

  if (method === 'DELETE') {
    // Clear the bridge token
    event.respondWith(
      caches.open(BRIDGE_CACHE).then(function (cache) {
        return cache.delete(BRIDGE_KEY);
      }).then(function () {
        return new Response(JSON.stringify({ ok: true }), {
          status: 200,
          headers: { 'Content-Type': 'application/json' }
        });
      }).catch(function () {
        return new Response(JSON.stringify({ ok: false }), {
          status: 500,
          headers: { 'Content-Type': 'application/json' }
        });
      })
    );
    return;
  }

  // All other requests: don't intercept (let the browser handle normally)
});

/* ─────────────────────────────────────────────────────────────────────────
 * Zorderz Camera — Durable photo upload via Background Sync
 *
 * The camera plugin persists each capture (original file + EXIF args) to an
 * IndexedDB store the instant the shutter fires. When a foreground upload
 * can't complete (offline / flaky shop Wi-Fi), the page registers a sync with
 * tag 'zdz-upload-sync'. This handler drains that store when connectivity
 * returns — even if the tab/PWA has since been closed (Android/Chromium).
 *
 * iOS Safari has no Background Sync API; there the camera plugin drains the
 * same store in the foreground (on reopen / online / visibility). Nothing is
 * ever lost either way — the IndexedDB record is the source of truth.
 *
 * This is additive and independent of the bridge-token logic above.
 * ───────────────────────────────────────────────────────────────────────── */

var ZCAM_DB = 'zdz-camera', ZCAM_VER = 1, ZCAM_STORE = 'uploads', ZCAM_SYNC = 'zdz-upload-sync';

self.addEventListener('sync', function (event) {
  if (event.tag === ZCAM_SYNC) {
    event.waitUntil(zcamDrain());
  }
});

function zcamOpen() {
  return new Promise(function (resolve, reject) {
    // No onupgradeneeded: the page owns the schema. If the store doesn't
    // exist yet, there's nothing to drain.
    var req = indexedDB.open(ZCAM_DB, ZCAM_VER);
    req.onsuccess = function () { resolve(req.result); };
    req.onerror   = function () { reject(req.error); };
  });
}

function zcamGetAll(db) {
  return new Promise(function (resolve, reject) {
    if (!db.objectStoreNames.contains(ZCAM_STORE)) { resolve([]); return; }
    var os = db.transaction(ZCAM_STORE, 'readonly').objectStore(ZCAM_STORE);
    var r = os.getAll();
    r.onsuccess = function () { resolve(r.result || []); };
    r.onerror   = function () { reject(r.error); };
  });
}

function zcamDelete(db, id) {
  return new Promise(function (resolve) {
    var os = db.transaction(ZCAM_STORE, 'readwrite').objectStore(ZCAM_STORE);
    var r = os.delete(id);
    r.onsuccess = function () { resolve(true); };
    r.onerror   = function () { resolve(false); };
  });
}

/* ── Single-uploader lease (mirrors zcam-queue.js claim()/release()) ──────────
 * The page and this SW drain the SAME store. To stop both POSTing one capture
 * concurrently (the double media_handle_upload() that stalled big-HEIC uploads),
 * a drain may upload a record only if it WINS a claim. The claim's read-modify-
 * write happens inside ONE readwrite transaction, which IndexedDB serializes
 * against other readwrite tx on the same store — so two drains can't both think
 * they won. The lease carries an owner ('sw' here) and an expiry, so a drain
 * that dies mid-upload can't wedge the record (the lease just expires).
 * Resolves the claimed record, or null if it's already actively leased by the
 * page ('fg'), missing, or done. */
var ZCAM_SW_OWNER   = 'sw';
var ZCAM_SW_LEASE_MS = 120000; // generous: Background Sync + a big upload can be slow
var ZCAM_SW_FETCH_MS = 90000;  // abort a hung SW fetch so it can't hold the lease forever

function zcamClaim(db, id) {
  return new Promise(function (resolve, reject) {
    var os = db.transaction(ZCAM_STORE, 'readwrite').objectStore(ZCAM_STORE);
    var g = os.get(id);
    g.onsuccess = function () {
      var rec = g.result;
      if (!rec || rec.status === 'done') { resolve(null); return; }
      var now = Date.now();
      var leased = rec.lease_until && rec.lease_until > now;
      // The page holds a live lease → leave it to the foreground drain.
      if (leased && rec.lease_owner !== ZCAM_SW_OWNER) { resolve(null); return; }
      rec.lease_owner = ZCAM_SW_OWNER;
      rec.lease_until = now + ZCAM_SW_LEASE_MS;
      rec.status = 'uploading';
      rec.updated_at = now;
      var p = os.put(rec);
      p.onsuccess = function () { resolve(rec); };
      p.onerror   = function () { reject(p.error); };
    };
    g.onerror = function () { reject(g.error); };
  });
}

// Clear our lease (and optionally patch status/tries) so a later sync — or the
// page on next foreground — can retry. No-op if the record is already gone.
function zcamRelease(db, id, patch) {
  return new Promise(function (resolve) {
    var os = db.transaction(ZCAM_STORE, 'readwrite').objectStore(ZCAM_STORE);
    var g = os.get(id);
    g.onsuccess = function () {
      var rec = g.result;
      if (!rec) { resolve(false); return; }
      rec.lease_owner = null;
      rec.lease_until = 0;
      if (patch) Object.keys(patch).forEach(function (k) { rec[k] = patch[k]; });
      rec.updated_at = Date.now();
      var p = os.put(rec);
      p.onsuccess = function () { resolve(true); };
      p.onerror   = function () { resolve(false); };
    };
    g.onerror = function () { resolve(false); };
  });
}

// Rebuild the upload Blob for a record. The camera stores the ORIGINAL bytes as
// an ArrayBuffer (`buf`) because iOS Safari can't reliably store Blobs in
// IndexedDB; new Blob([buf]) is byte-identical so EXIF/GPS is preserved. Older
// records that still carry a `file` Blob are used as a fallback. Null if neither.
function zcamBlob(rec) {
  if (rec && rec.buf) {
    try { return new Blob([rec.buf], { type: rec.type || 'application/octet-stream' }); }
    catch (e) { /* fall through */ }
  }
  return (rec && rec.file) ? rec.file : null;
}

// POST one record with an abort timeout, so a hung request rejects (and frees
// the lease) instead of holding the SW — and the record — open indefinitely.
function zcamPost(rec) {
  var blob = zcamBlob(rec);
  // Also refuse EMPTY or size-mismatched bytes (iOS fresh-write
  // read-back flakiness) — never POST a body the server can't use. Returning
  // false marks the record failed; a later drain retries when the stored
  // bytes have materialized.
  if (!blob || !blob.size || (rec.size && blob.size !== rec.size)) {
    return Promise.resolve(false); // no usable bytes this attempt → leave for retry
  }
  var ctrl = (typeof AbortController !== 'undefined') ? new AbortController() : null;
  var timer = ctrl ? setTimeout(function () { ctrl.abort(); }, ZCAM_SW_FETCH_MS) : null;
  var fd = new FormData();
  fd.append('action', 'zcam_save_photo');
  fd.append('nonce', rec.nonce || '');
  fd.append('photo', blob, rec.filename);       // ORIGINAL bytes → EXIF preserved
  fd.append('capture_uid', rec.capture_uid || ''); // server-side dedupe
  if (rec.captured_at) fd.append('captured_at', rec.captured_at);
  if (rec.gps_lat != null) fd.append('gps_lat', rec.gps_lat);
  if (rec.gps_lng != null) fd.append('gps_lng', rec.gps_lng);
  if (rec.meta && rec.meta.geo_source) fd.append('geo_source', rec.meta.geo_source);
  if (rec.meta && rec.meta.time_source) fd.append('time_source', rec.meta.time_source);
  if (rec.note) fd.append('note', rec.note); // photo purpose
  var opts = { method: 'POST', body: fd, credentials: 'same-origin' };
  // Authenticate with the fresh wp_rest
  // nonce captured at shutter (rec.rest_nonce) via the standard X-WP-Nonce
  // header. The legacy `nonce` field above stays as a server-side fallback.
  if (rec.rest_nonce) opts.headers = { 'X-WP-Nonce': rec.rest_nonce };
  if (ctrl) opts.signal = ctrl.signal;
  return fetch(rec.ajaxurl, opts)
    .then(function (resp) { return resp.json(); })
    .then(function (j) { return !!(j && j.success); })
    .catch(function () { return false; })
    .then(function (ok) { if (timer) clearTimeout(timer); return ok; });
}

function zcamDrain() {
  return zcamOpen().then(function (db) {
    return zcamGetAll(db).then(function (items) {
      var now = Date.now();
      var pending = items
        // Don't even consider a record the PAGE is actively uploading (a live
        // lease it owns) — that's the foreground drain's; we'd only double-POST.
        .filter(function (r) {
          if (r.status === 'done') return false;
          if (r.lease_until && r.lease_until > now && r.lease_owner !== ZCAM_SW_OWNER) return false;
          return true;
        })
        .sort(function (a, b) { return a.created_at - b.created_at; });

      var hadFailure = false;

      // Sequential so we don't hammer a weak connection.
      return pending.reduce(function (chain, rec) {
        return chain.then(function () {
          if (!rec.ajaxurl) return; // can't post without the endpoint
          // CLAIM atomically. If we lost the race (page grabbed it just now),
          // skip — but count it as "still pending" so the sync is retried.
          return zcamClaim(db, rec.id).then(function (claimed) {
            if (!claimed) { hadFailure = true; return; }
            return zcamPost(claimed).then(function (ok) {
              if (ok) { return zcamDelete(db, claimed.id); }
              // Failed: drop our lease + mark failed so a retry can reclaim it.
              hadFailure = true;
              return zcamRelease(db, claimed.id, { status: 'failed' });
            });
          });
        });
      }, Promise.resolve()).then(function () {
        // Reject so the browser retries the sync later if anything remains.
        if (hadFailure) return Promise.reject(new Error('zdz-upload-incomplete'));
      });
    });
  });
}
