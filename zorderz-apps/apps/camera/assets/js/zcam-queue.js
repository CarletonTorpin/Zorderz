/**
 * Zorderz Camera — Durable Upload Queue (zcam-queue.js)
 *
 * IndexedDB-backed queue that is the SOURCE OF TRUTH for photo uploads.
 * The original camera file (Blob/File) is persisted the instant the shutter
 * fires, BEFORE any network call — so a capture survives reload, crash,
 * backgrounding, tab close, and offline. Both the page (foreground drain)
 * and the Service Worker (Background Sync) read and drain this same store.
 *
 * A record is "done" only when its row is deleted. That is what makes the
 * pipeline lossless.
 *
 * Record shape:
 *   {
 *     id:          autoincrement (key),
 *     capture_uid: string,          // idempotency key (dedupes server-side on retry)
 *     buf:         ArrayBuffer,     // ORIGINAL bytes — EXIF preserved, iOS-safe (see 1.2.7)
 *     file:        Blob/File|null,  // LEGACY (≤1.2.6) Blob storage; used only as a fallback
 *     filename:    string,
 *     type:        string,          // mime (used to rebuild the Blob from `buf`)
 *     size:        number,          // v1.2.9: ORIGINAL byte length at capture — lets an
 *                                   // uploader detect an empty/truncated IndexedDB
 *                                   // read-back (iOS) and refuse to POST bad bytes
 *     captured_at: string|null,     // "YYYY-MM-DD HH:MM:SS" (client EXIF, HEIC-safe)
 *     gps_lat:     number|null,
 *     gps_lng:     number|null,
 *     meta:        object,          // e.g. { geo_source: 'exif' | 'device_fallback' }
 *     note:        string,          // optional photo purpose → server description
 *     ajaxurl:     string,          // persisted so the SW (no window.zcamWidget) can POST
 *     nonce:       string,          // LEGACY ZCAM_NONCE; kept as server-side fallback
 *     rest_nonce:  string,          // v1.2.8: theme wp_rest nonce (zdzData.nonce) captured
 *                                   // at shutter — sent as X-WP-Nonce (the fix for the
 *                                   // "nonce check FAILED" rejections; SW uses it too)
 *     status:      'pending' | 'uploading' | 'failed' | 'done',
 *     tries:       number,
 *     created_at:  number,         // epoch ms — when the capture was queued
 *     updated_at:  number,         // epoch ms — last status change (drives stuck-upload recovery)
 *     lease_owner: 'fg' | 'sw' | null,  // which drain currently owns this record
 *     lease_until: number          // epoch ms — lease expiry (0/absent = unleased)
 *   }
 *
 * @since 1.1.0
 * @updated 1.2.0 — record now carries an optional `note` (photo purpose).
 * @updated 1.2.2 — record carries `updated_at`; the foreground drain reclaims
 *                  items stranded in 'uploading' (stuck-spinner self-heal).
 * @updated 1.2.6 — record carries `lease_owner`/`lease_until`. claim()/release()
 *                  give the page and the Service Worker a single-uploader lease
 *                  so one capture is never POSTed twice concurrently.
 * @updated 1.2.7 — THE REAL UPLOAD-STALL FIX. We now persist the ORIGINAL bytes
 *                  as an **ArrayBuffer** (`buf`), not a Blob. iOS Safari's
 *                  IndexedDB does NOT reliably store Blob/File values — a stored
 *                  Blob can come back zero-length/unreadable, so streaming it
 *                  through the upload never completes and the request never even
 *                  reaches the server (no server log; spinner stuck forever).
 *                  An ArrayBuffer is plain structured-clonable data that iOS
 *                  stores reliably, and `bufToBlob()` rebuilds a byte-identical
 *                  Blob at upload time — so ALL EXIF/GPS in the original file is
 *                  preserved exactly (no re-encode). Legacy ≤1.2.6 records that
 *                  still hold a `file` Blob are migrated to `buf` lazily on read.
 *                  DB_VER unchanged (no new index; `buf` is a plain property),
 *                  so the SW still opens the DB with no upgrade path.
 * @updated 1.2.8 — record carries `rest_nonce`: the theme's fresh wp_rest nonce
 *                  (window.zdzData.nonce) captured at shutter. The uploaders send
 *                  it as the X-WP-Nonce header — the fix for every upload being
 *                  rejected with "nonce check FAILED" (the legacy baked-in
 *                  ZCAM_NONCE could belong to a stale session). Plain property;
 *                  DB_VER unchanged, no migration.
 * @updated 1.2.9 — record carries `size` (original byte length at capture).
 *                  iOS Safari can return an EMPTY ArrayBuffer when a large,
 *                  freshly-written record is read straight back (the server saw
 *                  "bytes=0" for every new capture while day-old records
 *                  uploaded at full size). `size` lets uploaders verify a
 *                  read-back before POSTing; widget.js also keeps the captured
 *                  bytes in memory for the session as the preferred source.
 *                  Plain property; DB_VER unchanged, no migration.
 */
(function (global) {
  'use strict';

  var DB_NAME  = 'zdz-camera';
  var DB_VER   = 1;
  var STORE    = 'uploads';
  var SYNC_TAG = 'zdz-upload-sync';

  function openDB() {
    return new Promise(function (resolve, reject) {
      if (!global.indexedDB) { reject(new Error('no-indexeddb')); return; }
      var req = indexedDB.open(DB_NAME, DB_VER);
      req.onupgradeneeded = function (e) {
        var db = e.target.result;
        if (!db.objectStoreNames.contains(STORE)) {
          var os = db.createObjectStore(STORE, { keyPath: 'id', autoIncrement: true });
          os.createIndex('status', 'status', { unique: false });
          os.createIndex('created_at', 'created_at', { unique: false });
        }
      };
      req.onsuccess = function () { resolve(req.result); };
      req.onerror   = function () { reject(req.error); };
    });
  }

  function store(db, mode) {
    return db.transaction(STORE, mode).objectStore(STORE);
  }

  function uid() {
    if (global.crypto && global.crypto.randomUUID) return global.crypto.randomUUID();
    return 'c' + Date.now().toString(36) + Math.random().toString(36).slice(2, 10);
  }

  /* ── Bytes ↔ Blob (the iOS-safe storage core) ──────────────────────────────
   * We persist the ORIGINAL file's bytes as an ArrayBuffer because iOS Safari's
   * IndexedDB cannot reliably round-trip Blob/File values (a stored Blob can come
   * back zero-length / unreadable). An ArrayBuffer is plain structured-clonable
   * data that every browser stores reliably. Reconstructing `new Blob([buf],
   * {type})` is byte-identical to the original, so EXIF/GPS is preserved exactly
   * — no canvas, no re-encode. */

  // Read a File/Blob to an ArrayBuffer. Prefers the modern Blob.arrayBuffer();
  // falls back to FileReader for older Safari. Never re-encodes the bytes.
  function readToBuffer(file) {
    if (!file) return Promise.reject(new Error('no-file'));
    if (typeof file.arrayBuffer === 'function') {
      return file.arrayBuffer();
    }
    return new Promise(function (resolve, reject) {
      try {
        var fr = new FileReader();
        fr.onload = function () { resolve(fr.result); };
        fr.onerror = function () { reject(fr.error || new Error('read-failed')); };
        fr.readAsArrayBuffer(file);
      } catch (e) { reject(e); }
    });
  }

  // Rebuild the upload Blob for a record. Prefers the ArrayBuffer (`buf`, the
  // 1.2.7 path); falls back to a legacy stored `file` Blob (≤1.2.6 records). The
  // returned Blob carries the original mime so the server stores the right type.
  // Returns null if neither is usable.
  function bufToBlob(rec) {
    if (!rec) return null;
    if (rec.buf) {
      try { return new Blob([rec.buf], { type: rec.type || 'application/octet-stream' }); }
      catch (e) { /* fall through to legacy */ }
    }
    if (rec.file) return rec.file; // legacy Blob/File (may be unreliable on iOS)
    return null;
  }

  /**
   * Persist a capture. Resolves with the stored record (including its id)
   * once it is durably written — at that point the capture is SAFE.
   *
   * The original bytes are stored as an ArrayBuffer (`buf`) — see the bytes↔Blob
   * note above for why this, not a Blob, is what makes iOS uploads reliable.
   *
   * @param {Blob|File} file   Original device file (do NOT re-encode).
   * @param {Object}    prov   { captured_at, gps_lat, gps_lng } — any may be null.
   * @param {Object}    meta   Extra metadata (e.g. { geo_source }).
   * @param {Object}    auth   { ajaxurl, nonce, rest_nonce, note } captured at
   *                           shutter time (rest_nonce = zdzData's wp_rest nonce,
   *                           sent as X-WP-Nonce by page AND SW).
   */
  function add(file, prov, meta, auth) {
    // Read the bytes BEFORE opening the write tx (a FileReader/arrayBuffer read
    // can't run inside an IndexedDB transaction without it auto-closing).
    return readToBuffer(file).then(function (buf) {
      return openDB().then(function (db) {
        return new Promise(function (resolve, reject) {
          prov = prov || {}; meta = meta || {}; auth = auth || {};
          var rec = {
            capture_uid: uid(),
            buf: buf,                       // ORIGINAL bytes (iOS-safe) — EXIF intact
            filename: (file && file.name) || ('photo-' + Date.now() + '.jpg'),
            type: (file && file.type) || 'image/jpeg',
            size: (buf && buf.byteLength) || 0, // read-back integrity check
            captured_at: prov.captured_at || null,
            gps_lat: (prov.gps_lat != null) ? prov.gps_lat : null,
            gps_lng: (prov.gps_lng != null) ? prov.gps_lng : null,
            meta: meta,
            note: auth.note || '',         // photo purpose; travels with the record
            // when the capture was launched from a job, the idempotency
            // key doubles as the link back to it. Anything NOT named explicitly
            // in this record shape is DISCARDED on write -- that is why
            // gps_accuracy rides inside `meta` (stored wholesale) rather than
            // as a sibling of gps_lat/gps_lng.
            source_ref: auth.source_ref || '',
            ajaxurl: auth.ajaxurl || '',
            nonce: auth.nonce || '',           // legacy fallback
            rest_nonce: auth.rest_nonce || '', // fresh wp_rest → X-WP-Nonce
            status: 'pending',
            tries: 0,
            created_at: Date.now(),
            updated_at: Date.now()
          };
          var r = store(db, 'readwrite').add(rec);
          r.onsuccess = function () { rec.id = r.result; resolve(rec); };
          r.onerror   = function () { reject(r.error); };
        });
      });
    });
  }

  function all() {
    return openDB().then(function (db) {
      return new Promise(function (resolve, reject) {
        var r = store(db, 'readonly').getAll();
        r.onsuccess = function () { resolve(r.result || []); };
        r.onerror   = function () { reject(r.error); };
      });
    });
  }

  function get(id) {
    return openDB().then(function (db) {
      return new Promise(function (resolve, reject) {
        var r = store(db, 'readonly').get(id);
        r.onsuccess = function () { resolve(r.result || null); };
        r.onerror   = function () { reject(r.error); };
      });
    });
  }

  function update(id, patch) {
    return openDB().then(function (db) {
      return new Promise(function (resolve, reject) {
        var os = store(db, 'readwrite');
        var g = os.get(id);
        g.onsuccess = function () {
          var rec = g.result;
          if (!rec) { resolve(null); return; }
          Object.keys(patch).forEach(function (k) { rec[k] = patch[k]; });
          var p = os.put(rec);
          p.onsuccess = function () { resolve(rec); };
          p.onerror   = function () { reject(p.error); };
        };
        g.onerror = function () { reject(g.error); };
      });
    });
  }

  function remove(id) {
    return openDB().then(function (db) {
      return new Promise(function (resolve, reject) {
        var r = store(db, 'readwrite').delete(id);
        r.onsuccess = function () { resolve(true); };
        r.onerror   = function () { reject(r.error); };
      });
    });
  }

  /* ── Upload lease (single-uploader coordination) ───────────────────────────
   * The page (foreground drain) and the Service Worker (Background-Sync drain)
   * both read and drain THIS SAME store. Without coordination they could each
   * pick up the same record and POST the ORIGINAL file twice, CONCURRENTLY — two
   * media_handle_upload() calls then contend over thumbnail generation for one
   * big HEIC and stall, which is the "stuck at Uploading… / Upload failed" bug.
   *
   * `claim()` is a cooperative lease: a drain may upload a record ONLY if it
   * wins the claim. The claim is atomic w.r.t. other claims because the
   * read-modify-write happens inside ONE readwrite transaction (IndexedDB
   * serializes overlapping readwrite tx on the same store). A lease has an
   * owner tag and an expiry, so a drain that dies mid-upload (tab closed, SW
   * killed) cannot wedge the record forever — the lease simply expires and the
   * next drain reclaims it. capture_uid idempotency still makes any overlap that
   * predates this build harmless on the server.
   *
   * @param {number} id     record id
   * @param {string} owner  'fg' (page) | 'sw' (service worker) — for diagnostics
   * @param {number} ttlMs  lease duration; must exceed the uploader's own timeout
   * Resolves the claimed record on success, or null if it's already actively
   * leased by someone else, missing, or already done.
   */
  function claim(id, owner, ttlMs) {
    return openDB().then(function (db) {
      return new Promise(function (resolve, reject) {
        var os = store(db, 'readwrite');
        var g = os.get(id);
        g.onsuccess = function () {
          var rec = g.result;
          if (!rec || rec.status === 'done') { resolve(null); return; }
          var now = Date.now();
          var leased = rec.lease_until && rec.lease_until > now;
          // Someone else holds a live lease → don't touch it.
          if (leased && rec.lease_owner !== owner) { resolve(null); return; }
          rec.lease_owner = owner;
          rec.lease_until = now + (ttlMs || 60000);
          rec.status = 'uploading';
          rec.updated_at = now;
          var p = os.put(rec);
          p.onsuccess = function () { resolve(rec); };
          p.onerror   = function () { reject(p.error); };
        };
        g.onerror = function () { reject(g.error); };
      });
    });
  }

  /**
   * Release a lease (clear owner/expiry). Optionally fold in a status/`tries`
   * patch in the SAME write (e.g. set 'failed' and bump tries on a failed
   * attempt). Does nothing if the record is gone (already removed on success).
   */
  function release(id, patch) {
    return openDB().then(function (db) {
      return new Promise(function (resolve, reject) {
        var os = store(db, 'readwrite');
        var g = os.get(id);
        g.onsuccess = function () {
          var rec = g.result;
          if (!rec) { resolve(null); return; }
          rec.lease_owner = null;
          rec.lease_until = 0;
          if (patch) Object.keys(patch).forEach(function (k) { rec[k] = patch[k]; });
          rec.updated_at = Date.now();
          var p = os.put(rec);
          p.onsuccess = function () { resolve(rec); };
          p.onerror   = function () { reject(p.error); };
        };
        g.onerror = function () { reject(g.error); };
      });
    });
  }

  // True if a record currently has a live lease held by someone OTHER than
  // `owner`. Used by a drain to skip records another drain is actively working.
  function isLeasedByOther(rec, owner) {
    return !!(rec && rec.lease_until && rec.lease_until > Date.now() && rec.lease_owner !== owner);
  }

  function pendingCount() {
    return all().then(function (items) {
      return items.filter(function (r) { return r.status !== 'done'; }).length;
    });
  }

  global.ZCamQueue = {
    add: add, all: all, get: get, update: update, remove: remove,
    claim: claim, release: release, isLeasedByOther: isLeasedByOther,
    bufToBlob: bufToBlob, readToBuffer: readToBuffer,
    pendingCount: pendingCount,
    DB_NAME: DB_NAME, DB_VER: DB_VER, STORE: STORE, SYNC_TAG: SYNC_TAG
  };
})(window);


/**
 * Zorderz Camera — Durable VIDEO Chunk Queue (zcam-queue.js)
 *
 * A SEPARATE IndexedDB database ('zdz-camera-video') from the photo queue above,
 * on purpose: the photo store, its DB_VER and the Service-Worker drain that
 * reads it are battle-tested and MUST NOT be disturbed. Video is additive.
 *
 * The model mirrors the photo queue's hard-won lessons (ORIGINAL bytes stored as
 * an ArrayBuffer — iOS Safari can't round-trip a Blob; size recorded for the
 * empty-read-back guard; single-uploader lease; idempotency) but is shaped for
 * streaming: one CLIP (a single MediaRecorder session, ≤2 min) is an ordered set
 * of CHUNKS emitted by ondataavailable. Chunks upload WHILE recording, so by
 * Stop nearly everything is already on the server.
 *
 * Two stores:
 *   clips:  keyPath 'capture_uid'  — one row per clip; the manifest + status.
 *           { capture_uid, reel_id, seq, mime, width, height, duration_ms,
 *             captured_at, gps_lat, gps_lng, geo_source, gps_accuracy, job_id,
 *             note, speaker_hint, status:'recording'|'complete'|'finalizing'|'done',
 *             total_chunks(null until complete), ajaxurl, rest_nonce, nonce,
 *             created_at, updated_at }
 *   chunks: keyPath 'id' autoinc — the bytes. Index 'uid' groups a clip's chunks.
 *           { id, capture_uid, idx, buf(ArrayBuffer), size, type, final(bool),
 *             status:'pending'|'uploading'|'done', tries, lease_owner, lease_until,
 *             created_at, updated_at }
 *
 * A clip is "done" (row + chunks deleted) only after the server ACKs finalize.
 * That is what makes video capture lossless across reload / crash / offline —
 * the same contract as photos.
 */
(function (global) {
  'use strict';

  var VDB_NAME = 'zdz-camera-video';
  var VDB_VER  = 1;
  var CLIPS    = 'clips';
  var CHUNKS   = 'chunks';
  var VSYNC    = 'zdz-video-sync';

  function vOpen() {
    return new Promise(function (resolve, reject) {
      if (!global.indexedDB) { reject(new Error('no-indexeddb')); return; }
      var req = indexedDB.open(VDB_NAME, VDB_VER);
      req.onupgradeneeded = function (e) {
        var db = e.target.result;
        if (!db.objectStoreNames.contains(CLIPS)) {
          db.createObjectStore(CLIPS, { keyPath: 'capture_uid' });
        }
        if (!db.objectStoreNames.contains(CHUNKS)) {
          var cs = db.createObjectStore(CHUNKS, { keyPath: 'id', autoIncrement: true });
          cs.createIndex('uid', 'capture_uid', { unique: false });
          cs.createIndex('status', 'status', { unique: false });
        }
      };
      req.onsuccess = function () { resolve(req.result); };
      req.onerror   = function () { reject(req.error); };
    });
  }

  function vuid() {
    if (global.crypto && global.crypto.randomUUID) return global.crypto.randomUUID();
    return 'v' + Date.now().toString(36) + Math.random().toString(36).slice(2, 10);
  }

  // Reuse the photo module's byte helpers if present; fall back to local copies.
  function toBuffer(file) {
    if (global.ZCamQueue && ZCamQueue.readToBuffer) return ZCamQueue.readToBuffer(file);
    if (typeof file.arrayBuffer === 'function') return file.arrayBuffer();
    return new Promise(function (resolve, reject) {
      var fr = new FileReader();
      fr.onload = function () { resolve(fr.result); };
      fr.onerror = function () { reject(fr.error || new Error('read-failed')); };
      fr.readAsArrayBuffer(file);
    });
  }
  function chunkToBlob(rec) {
    if (!rec) return null;
    if (rec.buf) { try { return new Blob([rec.buf], { type: rec.type || 'application/octet-stream' }); } catch (e) {} }
    if (rec.blob) return rec.blob;
    return null;
  }

  /** Create the clip manifest row (status 'recording'). Resolves the clip. */
  function startClip(manifest) {
    manifest = manifest || {};
    return vOpen().then(function (db) {
      return new Promise(function (resolve, reject) {
        var now = Date.now();
        var rec = {
          capture_uid: manifest.capture_uid || vuid(),
          reel_id: manifest.reel_id || '',
          seq: (manifest.seq != null) ? manifest.seq : 0,
          mime: manifest.mime || 'video/mp4',
          width: manifest.width || 0,
          height: manifest.height || 0,
          duration_ms: manifest.duration_ms || 0,
          captured_at: manifest.captured_at || null,
          gps_lat: (manifest.gps_lat != null) ? manifest.gps_lat : null,
          gps_lng: (manifest.gps_lng != null) ? manifest.gps_lng : null,
          geo_source: manifest.geo_source || null,
          gps_accuracy: (manifest.gps_accuracy != null) ? manifest.gps_accuracy : null,
          job_id: manifest.job_id || 0,
          note: manifest.note || '',
          speaker_hint: manifest.speaker_hint || '',
          status: 'recording',
          total_chunks: null,
          ajaxurl: manifest.ajaxurl || '',
          rest_nonce: manifest.rest_nonce || '',
          nonce: manifest.nonce || '',
          created_at: now,
          updated_at: now
        };
        var r = vStore(db, CLIPS, 'readwrite').put(rec);
        r.onsuccess = function () { resolve(rec); };
        r.onerror   = function () { reject(r.error); };
      });
    });
  }

  function vStore(db, name, mode) { return db.transaction(name, mode).objectStore(name); }

  /**
   * Persist one recorder chunk. Resolves once durably written (the chunk is SAFE
   * from that instant). Stores the ORIGINAL bytes as an ArrayBuffer (iOS-safe).
   */
  function addChunk(capture_uid, idx, blob, isFinal) {
    return toBuffer(blob).then(function (buf) {
      return vOpen().then(function (db) {
        return new Promise(function (resolve, reject) {
          var rec = {
            capture_uid: capture_uid,
            idx: idx,
            buf: buf,
            size: (buf && buf.byteLength) || 0,
            type: (blob && blob.type) || 'video/mp4',
            final: !!isFinal,
            status: 'pending',
            tries: 0,
            lease_owner: null,
            lease_until: 0,
            created_at: Date.now(),
            updated_at: Date.now()
          };
          var r = vStore(db, CHUNKS, 'readwrite').add(rec);
          r.onsuccess = function () { rec.id = r.result; resolve(rec); };
          r.onerror   = function () { reject(r.error); };
        });
      });
    });
  }

  function getClip(capture_uid) {
    return vOpen().then(function (db) {
      return new Promise(function (resolve, reject) {
        var r = vStore(db, CLIPS, 'readonly').get(capture_uid);
        r.onsuccess = function () { resolve(r.result || null); };
        r.onerror   = function () { reject(r.error); };
      });
    });
  }

  function updateClip(capture_uid, patch) {
    return vOpen().then(function (db) {
      return new Promise(function (resolve, reject) {
        var os = vStore(db, CLIPS, 'readwrite');
        var g = os.get(capture_uid);
        g.onsuccess = function () {
          var rec = g.result;
          if (!rec) { resolve(null); return; }
          Object.keys(patch).forEach(function (k) { rec[k] = patch[k]; });
          rec.updated_at = Date.now();
          var p = os.put(rec);
          p.onsuccess = function () { resolve(rec); };
          p.onerror   = function () { reject(p.error); };
        };
        g.onerror = function () { reject(g.error); };
      });
    });
  }

  function allClips() {
    return vOpen().then(function (db) {
      return new Promise(function (resolve, reject) {
        var r = vStore(db, CLIPS, 'readonly').getAll();
        r.onsuccess = function () { resolve(r.result || []); };
        r.onerror   = function () { reject(r.error); };
      });
    });
  }

  /** Clips not yet fully uploaded — used on relaunch to resume. */
  function incompleteClips() {
    return allClips().then(function (cs) {
      return cs.filter(function (c) { return c.status !== 'done'; });
    });
  }

  /** A clip's chunks, sorted by idx. */
  function chunksFor(capture_uid) {
    return vOpen().then(function (db) {
      return new Promise(function (resolve, reject) {
        var idx = vStore(db, CHUNKS, 'readonly').index('uid');
        var r = idx.getAll(IDBKeyRange.only(capture_uid));
        r.onsuccess = function () {
          var arr = (r.result || []).sort(function (a, b) { return a.idx - b.idx; });
          resolve(arr);
        };
        r.onerror = function () { reject(r.error); };
      });
    });
  }

  /** Oldest pending, unleased chunk for a clip (drain order = idx order). */
  function nextChunk(capture_uid, owner) {
    return chunksFor(capture_uid).then(function (arr) {
      var now = Date.now();
      for (var i = 0; i < arr.length; i++) {
        var c = arr[i];
        if (c.status === 'done') continue;
        var leased = c.lease_until && c.lease_until > now && c.lease_owner !== owner;
        if (leased) continue;
        return c;
      }
      return null;
    });
  }

  function claimChunk(id, owner, ttlMs) {
    return vOpen().then(function (db) {
      return new Promise(function (resolve, reject) {
        var os = vStore(db, CHUNKS, 'readwrite');
        var g = os.get(id);
        g.onsuccess = function () {
          var rec = g.result;
          if (!rec || rec.status === 'done') { resolve(null); return; }
          var now = Date.now();
          if (rec.lease_until && rec.lease_until > now && rec.lease_owner !== owner) { resolve(null); return; }
          rec.lease_owner = owner; rec.lease_until = now + (ttlMs || 60000);
          rec.status = 'uploading'; rec.updated_at = now;
          var p = os.put(rec);
          p.onsuccess = function () { resolve(rec); };
          p.onerror   = function () { reject(p.error); };
        };
        g.onerror = function () { reject(g.error); };
      });
    });
  }

  function releaseChunk(id, patch) {
    return vOpen().then(function (db) {
      return new Promise(function (resolve, reject) {
        var os = vStore(db, CHUNKS, 'readwrite');
        var g = os.get(id);
        g.onsuccess = function () {
          var rec = g.result;
          if (!rec) { resolve(null); return; }
          rec.lease_owner = null; rec.lease_until = 0;
          if (patch) Object.keys(patch).forEach(function (k) { rec[k] = patch[k]; });
          rec.updated_at = Date.now();
          var p = os.put(rec);
          p.onsuccess = function () { resolve(rec); };
          p.onerror   = function () { reject(p.error); };
        };
        g.onerror = function () { reject(g.error); };
      });
    });
  }

  function removeChunk(id) {
    return vOpen().then(function (db) {
      return new Promise(function (resolve, reject) {
        var r = vStore(db, CHUNKS, 'readwrite').delete(id);
        r.onsuccess = function () { resolve(true); };
        r.onerror   = function () { reject(r.error); };
      });
    });
  }

  /** Delete a clip's manifest AND all its chunks (call after finalize is ACKed). */
  function removeClip(capture_uid) {
    return chunksFor(capture_uid).then(function (arr) {
      return vOpen().then(function (db) {
        return new Promise(function (resolve, reject) {
          var tx = db.transaction([CLIPS, CHUNKS], 'readwrite');
          tx.objectStore(CLIPS).delete(capture_uid);
          var cs = tx.objectStore(CHUNKS);
          arr.forEach(function (c) { cs.delete(c.id); });
          tx.oncomplete = function () { resolve(true); };
          tx.onerror    = function () { reject(tx.error); };
        });
      });
    });
  }

  function pendingBytes(capture_uid) {
    return chunksFor(capture_uid).then(function (arr) {
      return arr.reduce(function (n, c) { return n + (c.status !== 'done' ? (c.size || 0) : 0); }, 0);
    });
  }

  global.ZCamVideoQueue = {
    startClip: startClip, addChunk: addChunk,
    getClip: getClip, updateClip: updateClip, allClips: allClips, incompleteClips: incompleteClips,
    chunksFor: chunksFor, nextChunk: nextChunk,
    claimChunk: claimChunk, releaseChunk: releaseChunk,
    removeChunk: removeChunk, removeClip: removeClip,
    chunkToBlob: chunkToBlob, pendingBytes: pendingBytes, uid: vuid,
    DB_NAME: VDB_NAME, DB_VER: VDB_VER, CLIPS: CLIPS, CHUNKS: CHUNKS, SYNC_TAG: VSYNC
  };
})(window);
