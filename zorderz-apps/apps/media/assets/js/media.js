/**
 * TS Media — shared controller v2.3.3 (v2.3.3 makes the dashboard WIDGET
 * browsable on its own: a larger first page + a "Load more" button that appends
 * pages in place, so users don't have to open "See All" to see beyond the
 * recent slice. v2.3.0 added the admin-only "All" scope: browse every photo
 * org-wide incl. private; server-enforced admin gate. v2.2.0 added selection
 * mode + delete: granular or bulk, double-confirmed, owner-or-admin.)
 *
 * ONE controller, TWO surfaces:
 *   • window.TSMedia.mountWidget(rootEl)      → compact dashboard card
 *   • window.TSMedia.mountFullscreen(rootEl)  → roomy #app-body gallery
 *
 * Enqueued GLOBALLY on the SPA front page (not injected via innerHTML), so it
 * runs reliably on both surfaces — including the full-screen ajax_html surface,
 * which therefore does NOT depend on the theme reviving #app-body scripts.
 *
 * The lightbox is a single shared, top-level layer reused by both surfaces. It
 * is appended to <body> AND uses position:fixed with a very high z-index; on
 * the dashboard there is no transformed ancestor, so fixed positioning behaves
 * correctly (the v1 bug was opening it inside the transformed .app-viewport).
 *
 * Modeled on the iOS Photos mental model: date-section grid, tap to open a
 * viewer, swipe/arrow nav, share/open, in-place notes, and a visibility toggle.
 */
(function () {
  'use strict';

  function cfg() { return window.zmlApp || {}; }
  function byId(id) { return document.getElementById(id); }
  function esc(s) { var d = document.createElement('div'); d.textContent = (s == null ? '' : s); return d.innerHTML; }
  function escAttr(s) { return esc(s).replace(/"/g, '&quot;'); }

  function ajax(action, data) {
    var fd = new FormData();
    fd.append('action', action);
    fd.append('nonce', cfg().nonce);
    for (var k in data) if (Object.prototype.hasOwnProperty.call(data, k)) fd.append(k, data[k]);
    return fetch(cfg().ajaxurl, { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json(); });
  }

  /* ── v2.2.0: transient toast (top-level, like the lightbox, so transformed
   * ancestors can't trap it). Used to report delete results. ── */
  var _toastEl = null, _toastTimer = null;
  function tsmlToast(msg, isError) {
    if (!_toastEl) {
      _toastEl = document.createElement('div');
      _toastEl.className = 'zml-toast';
      _toastEl.setAttribute('role', 'status');
      _toastEl.setAttribute('aria-live', 'polite');
      document.body.appendChild(_toastEl);
    }
    _toastEl.textContent = msg;
    _toastEl.classList.toggle('zml-toast-err', !!isError);
    _toastEl.classList.add('zml-toast-show');
    clearTimeout(_toastTimer);
    _toastTimer = setTimeout(function () { _toastEl.classList.remove('zml-toast-show'); }, 3200);
  }

  /* ── Date grouping (iOS-Photos / Google-Photos style section headers) ──── */

  function startOfDay(d) { return new Date(d.getFullYear(), d.getMonth(), d.getDate()).getTime(); }

  function parseDate(ds) {
    if (!ds) return null;
    var d = new Date(String(ds).replace(' ', 'T'));
    return isNaN(d.getTime()) ? null : d;
  }

  function groupLabel(ds) {
    var d = parseDate(ds);
    if (!d) return 'Earlier';
    var today = startOfDay(new Date());
    var day = startOfDay(d);
    var dayMs = 86400000;
    if (day === today) return 'Today';
    if (day === today - dayMs) return 'Yesterday';
    if (day > today - 7 * dayMs) return 'This Week';
    var now = new Date();
    if (d.getFullYear() === now.getFullYear() && d.getMonth() === now.getMonth()) return 'This Month';
    if (d.getFullYear() === now.getFullYear()) return d.toLocaleDateString(undefined, { month: 'long' });
    return d.toLocaleDateString(undefined, { month: 'long', year: 'numeric' });
  }

  function prettyDate(ds) {
    var d = parseDate(ds);
    if (!d) return '';
    return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' }) +
      ', ' + d.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' });
  }

  function sourceBadge(item) {
    if (item.source_app === 'zdz-sketch-pad') return 'Sketch';
    if (item.source_app === 'zdz-camera') return 'Camera';
    if (item.media_type === 'sketch') return 'Sketch';
    return 'Photo';
  }

  /* ─────────────────────────────────────────────────────────────────────────
   * Surface — a reusable controller for one grid (widget or fullscreen).
   * Each surface owns its own scope/type/paging state and DOM nodes, but they
   * share the single global lightbox.
   * ──────────────────────────────────────────────────────────────────────── */

  function Surface(opts) {
    this.kind = opts.kind;                 // 'widget' | 'fullscreen'
    this.root = opts.root;
    this.gridWrap = opts.gridWrap;
    this.scrollEl = opts.scrollEl || null; // fullscreen only
    this.sentinel = opts.sentinel || null; // fullscreen only
    this.pageSize = opts.pageSize || (cfg().pageSize || 40);
    this.grouped = opts.grouped !== false; // widget: false (flat strip), fs: true

    this.scope = opts.scope || 'public';
    this.type = '';
    this.offset = 0;
    this.hasMore = true;
    this.loading = false;
    this.items = [];
    this.io = null;

    /* v2.2.0 — selection mode (granular/bulk delete) */
    this.selectMode = false;
    this.selected = {};      // media id -> true
    this.selbar = null;      // lazily-built bottom action bar
  }

  Surface.prototype.bindControls = function () {
    var self = this;

    // Scope segmented control
    var seg = this.root.querySelector('.zml-seg');
    if (seg) seg.addEventListener('click', function (e) {
      var btn = e.target.closest('.zml-seg-btn'); if (!btn) return;
      if (btn.dataset.scope === self.scope) return;
      // v2.3.0 — the "All" scope is admin-only. The server also enforces this
      // (downgrades a non-admin to 'public'), and the tab is only rendered for
      // admins; this is a belt-and-suspenders guard so a stale/injected button
      // can't drive the client into an unauthorized scope.
      if (btn.dataset.scope === 'all' && !cfg().is_admin) return;
      self.scope = btn.dataset.scope;
      seg.querySelectorAll('.zml-seg-btn').forEach(function (b) {
        var on = b === btn;
        b.classList.toggle('zml-active', on);
        b.setAttribute('aria-selected', on ? 'true' : 'false');
      });
      self.resetAndLoad();
    });

    // Type chips
    var chips = this.root.querySelector('.zml-chips, .zml-w-chips');
    if (chips) chips.addEventListener('click', function (e) {
      var btn = e.target.closest('.zml-chip'); if (!btn) return;
      if (btn.dataset.type === self.type) return;
      self.type = btn.dataset.type;
      chips.querySelectorAll('.zml-chip').forEach(function (b) { b.classList.toggle('zml-active', b === btn); });
      self.resetAndLoad();
    });

    // Widget "See All" → open the full-screen gallery via the theme Bridge,
    // carrying the current scope/type so the big view opens where you were.
    var seeall = this.root.querySelector('[data-action="seeall"]');
    if (seeall) seeall.addEventListener('click', function () {
      TSMedia._pendingFsState = { scope: self.scope, type: self.type };
      var id = cfg().fullscreenAppId || 'zdz-media-all';
      if (window.Bridge && typeof window.Bridge.loadApp === 'function') {
        window.Bridge.loadApp(id);
      }
    });

    // "Add Photos" → open the bulk-upload sheet. Present on both surfaces.
    var add = this.root.querySelector('[data-action="add"]');
    if (add) add.addEventListener('click', function () { Uploader.open(self); });

    // v2.2.0 — "Select" toggles selection mode (fullscreen gallery only; the
    // compact widget deletes via the lightbox instead).
    var sel = this.root.querySelector('[data-action="select"]');
    if (sel) sel.addEventListener('click', function () { self.toggleSelectMode(); });

    // Infinite scroll (fullscreen only)
    if (this.kind === 'fullscreen' && this.sentinel && this.scrollEl && ('IntersectionObserver' in window)) {
      this.io = new IntersectionObserver(function (entries) {
        entries.forEach(function (en) { if (en.isIntersecting) self.loadMore(false); });
      }, { root: this.scrollEl, rootMargin: '300px' });
      this.io.observe(this.sentinel);
    }
  };

  Surface.prototype.resetAndLoad = function () {
    if (this.selectMode) this.exitSelectMode();   // v2.2.0 — selection can't survive a reload
    this.offset = 0; this.hasMore = true; this.items = [];
    if (this.gridWrap) this.gridWrap.innerHTML = '<div class="zml-loading">Loading…</div>';
    this.loadMore(true);
  };

  Surface.prototype.loadMore = function (isFirst) {
    var self = this;
    if (this.loading || (!this.hasMore && !isFirst)) return;
    this.loading = true;

    var req = { scope: this.scope, media_type: this.type, offset: this.offset };
    // v2.3.3: the widget shows a useful first page (12 ≈ 4 rows on phones) and
    // a "Load more" button appends further pages IN PLACE, so the widget is
    // browsable on its own without opening the full-screen gallery. (The
    // full-screen surface keeps the theme's larger page size.)
    if (this.kind === 'widget') req.limit = 12;

    ajax('zml_list', req).then(function (resp) {
      self.loading = false;
      if (!resp || !resp.success) {
        if (isFirst && self.gridWrap) self.gridWrap.innerHTML = '<div class="zml-empty">Couldn\u2019t load media. Try again.</div>';
        return;
      }
      var data = resp.data || {};
      var batch = data.items || [];
      self.items = self.items.concat(batch);
      self.hasMore = !!data.has_more;
      self.offset = (typeof data.offset === 'number') ? data.offset : (self.offset + batch.length);
      self.render();
    }).catch(function () {
      self.loading = false;
      if (isFirst && self.gridWrap) self.gridWrap.innerHTML = '<div class="zml-empty">Connection error. Try again.</div>';
    });
  };

  Surface.prototype.emptyMessage = function () {
    if (this.scope === 'mine') {
      return 'You haven\u2019t added any media yet. Add photos here, take them with the Camera app, or save a sketch \u2014 they\u2019ll all show up here.';
    }
    // v2.3.0 \u2014 admin-only "All" scope: every photo org-wide, incl. private.
    if (this.scope === 'all') {
      return 'No media in the organization yet. Every photo \u2014 from any user, including private ones \u2014 will appear here.';
    }
    return 'No public media yet. Items shared to the organization will appear here.';
  };

  // Called when a bulk upload finishes (\u22651 photo saved). Bulk uploads land in
  // the user's own library at privacy=private, so they only belong in the "My
  // Photos" scope. Switch there (reflecting the control) and reload so the new
  // photos appear at the top \u2014 simplest correct refresh, no manual splicing.
  Surface.prototype.afterUpload = function () {
    if (this.scope !== 'mine') {
      this.scope = 'mine';
      reflectControls(this.root, this.scope, this.type);
    }
    this.resetAndLoad();
  };

  Surface.prototype.render = function () {
    var self = this;
    var wrap = this.gridWrap; if (!wrap) return;

    if (!this.items.length) {
      wrap.innerHTML = '<div class="zml-empty">' + esc(this.emptyMessage()) + '</div>';
      return;
    }

    var html = '';
    if (this.grouped) {
      // Date-section grouped grid (fullscreen).
      var groups = [], index = {};
      this.items.forEach(function (it) {
        var label = groupLabel(it.created_at);
        if (!(label in index)) { index[label] = groups.length; groups.push({ label: label, items: [] }); }
        groups[index[label]].items.push(it);
      });
      groups.forEach(function (g) {
        html += '<div class="zml-group"><h2 class="zml-group-h">' + esc(g.label) + '</h2><div class="zml-grid">';
        g.items.forEach(function (it) { html += self.cellHTML(it); });
        html += '</div></div>';
      });
    } else {
      // Flat compact strip (widget) — newest first, no headers.
      html += '<div class="zml-grid zml-grid-compact">';
      this.items.forEach(function (it) { html += self.cellHTML(it); });
      html += '</div>';
      // v2.3.3: "Load more" — append the next page in place so the widget is
      // browsable beyond the first slice WITHOUT opening the full-screen view.
      // The card simply grows downward (no nested scroll → honours the theme's
      // WIDGET-OVERFLOW-CONTRACT). Shown only while more pages exist; a "See
      // All" is still available for the roomy grouped gallery.
      if (this.hasMore) {
        var moreLabel = this.loading ? 'Loading…' : 'Load more';
        html += '<button type="button" class="zml-w-more" data-action="more"' +
          (this.loading ? ' disabled' : '') + '>' +
          '<span>' + moreLabel + '</span>' +
          '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><polyline points="6 9 12 15 18 9"/></svg>' +
          '</button>';
      }
    }

    wrap.innerHTML = html;

    wrap.querySelectorAll('.zml-cell').forEach(function (cell) {
      cell.addEventListener('click', function () {
        var pos = parseInt(this.dataset.pos, 10);
        // v2.2.0 — in selection mode a tap toggles the checkmark instead of
        // opening the viewer.
        if (self.selectMode) { self.toggleSelect(pos, this); return; }
        Lightbox.open(self, pos);
      });
    });

    // v2.3.3: wire the widget "Load more" button.
    var moreBtn = wrap.querySelector('.zml-w-more');
    if (moreBtn) {
      moreBtn.addEventListener('click', function () {
        if (self.loading || !self.hasMore) return;
        // Reflect a loading state on the button immediately (no full re-render
        // yet — loadMore() re-renders when the page arrives).
        this.disabled = true;
        var lbl = this.querySelector('span'); if (lbl) lbl.textContent = 'Loading…';
        self.loadMore(false);
      });
    }
    if (this.selectMode) this.syncSelectionUI();
  };

  Surface.prototype.cellHTML = function (it) {
    var pos = this.items.indexOf(it);
    var note = it.note ? '<span class="zml-cell-note" title="Has a note" aria-hidden="true">\uD83D\uDCDD</span>' : '';
    // Visibility dot — only meaningful (and only shown) for the owner's items.
    var vis = '';
    if (it.is_owner && it.privacy === 'public') {
      vis = '<span class="zml-cell-vis" title="Visible to everyone" aria-hidden="true">' +
        '<svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.2"><circle cx="12" cy="12" r="10"/><path d="M2 12h20"/><path d="M12 2a15.3 15.3 0 0 1 0 20a15.3 15.3 0 0 1 0-20"/></svg></span>';
    }
    // v2.2.0 — selection-mode affordances. Photos the viewer may NOT delete
    // (not theirs, not an admin) render dimmed and unselectable in select mode.
    var selCls = '';
    if (this.selectMode) {
      selCls = it.can_delete ? (this.selected[it.id] ? ' zml-checked' : '') : ' zml-nodel';
    }
    return '<button type="button" class="zml-cell' + selCls + '" data-pos="' + pos + '" data-id="' + it.id + '" ' +
      'aria-label="' + escAttr((it.title || 'Media') + ' — ' + sourceBadge(it)) + '">' +
      '<img class="zml-cell-img" src="' + escAttr(it.thumbnail_url) + '" alt="' + escAttr(it.title) + '" loading="lazy" />' +
      '<span class="zml-cell-badge">' + esc(sourceBadge(it)) + '</span>' +
      vis + note +
      '<span class="zml-cell-check" aria-hidden="true">' +
        '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>' +
      '</span>' +
      '</button>';
  };

  // Update one item's cell affordances (note dot / visibility dot) in place,
  // without re-rendering the whole grid (no layout shift).
  Surface.prototype.refreshCell = function (it) {
    if (!this.gridWrap) return;
    var pos = this.items.indexOf(it);
    var cell = this.gridWrap.querySelector('.zml-cell[data-pos="' + pos + '"]');
    if (!cell) return;

    var noteDot = cell.querySelector('.zml-cell-note');
    if (it.note && !noteDot) {
      var n = document.createElement('span');
      n.className = 'zml-cell-note'; n.title = 'Has a note'; n.setAttribute('aria-hidden', 'true'); n.textContent = '\uD83D\uDCDD';
      cell.appendChild(n);
    } else if (!it.note && noteDot) {
      noteDot.remove();
    }

    var visDot = cell.querySelector('.zml-cell-vis');
    var shouldShowVis = it.is_owner && it.privacy === 'public';
    if (shouldShowVis && !visDot) {
      var v = document.createElement('span');
      v.className = 'zml-cell-vis'; v.title = 'Visible to everyone'; v.setAttribute('aria-hidden', 'true');
      v.innerHTML = '<svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.2"><circle cx="12" cy="12" r="10"/><path d="M2 12h20"/><path d="M12 2a15.3 15.3 0 0 1 0 20a15.3 15.3 0 0 1 0-20"/></svg>';
      cell.insertBefore(v, cell.querySelector('.zml-cell-note') || null);
    } else if (!shouldShowVis && visDot) {
      visDot.remove();
    }
  };

  /* ─────────────────────────────────────────────────────────────────────────
   * v2.2.0 — Selection mode + delete (granular or bulk, double-confirmed).
   *
   * Entered via the gallery bar's "Select" toggle. Taps check/uncheck photos
   * (only ones the viewer may delete — can_delete, computed server-side);
   * a bottom action bar shows the live count and the Delete button. EVERY
   * delete — here or from the lightbox — funnels through performDelete(),
   * which asks for confirmation TWICE before touching the server.
   * ──────────────────────────────────────────────────────────────────────── */

  Surface.prototype.toggleSelectMode = function () {
    if (this.selectMode) this.exitSelectMode(); else this.enterSelectMode();
  };

  Surface.prototype.enterSelectMode = function () {
    this.selectMode = true;
    this.selected = {};
    this.root.classList.add('zml-selecting');
    var btn = this.root.querySelector('[data-action="select"]');
    if (btn) { btn.classList.add('zml-active'); btn.setAttribute('aria-pressed', 'true'); btn.textContent = 'Done'; }
    this.buildSelBar();
    this.syncSelectionUI();
  };

  Surface.prototype.exitSelectMode = function () {
    this.selectMode = false;
    this.selected = {};
    this.root.classList.remove('zml-selecting');
    var btn = this.root.querySelector('[data-action="select"]');
    if (btn) { btn.classList.remove('zml-active'); btn.setAttribute('aria-pressed', 'false'); btn.textContent = 'Select'; }
    if (this.selbar) this.selbar.style.display = 'none';
    this.syncSelectionUI();
  };

  Surface.prototype.selectedIds = function () {
    return Object.keys(this.selected).map(Number);
  };

  Surface.prototype.toggleSelect = function (pos, cellEl) {
    var it = this.items[pos]; if (!it) return;
    if (!it.can_delete) return;   // not yours (and you're not an admin)
    if (this.selected[it.id]) delete this.selected[it.id];
    else this.selected[it.id] = true;
    if (cellEl) cellEl.classList.toggle('zml-checked', !!this.selected[it.id]);
    this.syncSelBar();
  };

  // Re-apply selection classes to every rendered cell (after re-renders —
  // e.g. infinite scroll appending a page mid-selection, or mode toggles).
  Surface.prototype.syncSelectionUI = function () {
    var self = this;
    if (!this.gridWrap) return;
    this.gridWrap.querySelectorAll('.zml-cell').forEach(function (cell) {
      var it = self.items[parseInt(cell.dataset.pos, 10)];
      if (!it) return;
      cell.classList.toggle('zml-nodel',   self.selectMode && !it.can_delete);
      cell.classList.toggle('zml-checked', self.selectMode && !!self.selected[it.id]);
    });
    this.syncSelBar();
  };

  Surface.prototype.buildSelBar = function () {
    var self = this;
    if (!this.selbar) {
      var bar = document.createElement('div');
      bar.className = 'zml-selbar';
      bar.innerHTML =
        '<span class="zml-selbar-count" data-role="count" aria-live="polite">0 selected</span>' +
        '<button type="button" class="zml-selbar-cancel" data-role="cancel">Cancel</button>' +
        '<button type="button" class="zml-selbar-del" data-role="del" disabled>Delete</button>';
      this.root.appendChild(bar);
      bar.querySelector('[data-role="cancel"]').addEventListener('click', function () { self.exitSelectMode(); });
      bar.querySelector('[data-role="del"]').addEventListener('click', function () {
        var ids = self.selectedIds(); if (!ids.length) return;
        performDelete(self, ids, {
          busy: function (on) {
            var del = bar.querySelector('[data-role="del"]');
            if (del) { del.disabled = on; del.classList.toggle('zdz-btn-busy', on); }
          }
        });
      });
      this.selbar = bar;
    }
    this.selbar.style.display = '';
    this.syncSelBar();
  };

  Surface.prototype.syncSelBar = function () {
    if (!this.selbar) return;
    var n = this.selectedIds().length;
    var count = this.selbar.querySelector('[data-role="count"]');
    var del = this.selbar.querySelector('[data-role="del"]');
    if (count) count.textContent = n + ' selected';
    if (del) { del.disabled = (n === 0); del.textContent = n > 1 ? 'Delete (' + n + ')' : 'Delete'; }
  };

  // Surgically remove deleted ids from this surface (no refetch) and re-render.
  // The server's list just shrank, so pull the pagination cursor back to keep
  // "load more" aligned.
  Surface.prototype.removeItemsByIds = function (ids) {
    var lookup = {};
    ids.forEach(function (id) { lookup[id] = true; });
    var before = this.items.length;
    this.items = this.items.filter(function (it) { return !lookup[it.id]; });
    var removed = before - this.items.length;
    if (removed > 0) this.offset = Math.max(0, this.offset - removed);
    this.render();
  };

  /* ─────────────────────────────────────────────────────────────────────────
   * Uploader — bulk "Add Photos" sheet, shared by both surfaces.
   *
   * Flow: pick many photos → (optional) one batch note → upload. Each file is
   * POSTed individually to zml_upload (mirrors the camera's resilient
   * one-file-per-request path) with limited concurrency so a weak shop uplink
   * isn't overwhelmed. All files in one "Add Photos" action share a batch id, so
   * the server can group them (and later attach a job/customer to the batch).
   *
   * METADATA: for each file we read EXIF IN-BROWSER via exifr (the only way to
   * recover HEIC provenance, which PHP can't parse) and send captured_at / GPS
   * alongside the original file. The server stores the ORIGINAL as the
   * attachment, so JPEG/TIFF EXIF also survives server-side. Unlike the live
   * camera, these are EXISTING photos: when a file carries no EXIF date we fall
   * back to the file's lastModified (NOT "now"), and we never substitute the
   * device's current GPS for a photo taken elsewhere.
   * ──────────────────────────────────────────────────────────────────────── */

  function genBatchId() {
    if (window.crypto && crypto.randomUUID) return 'batch_' + crypto.randomUUID();
    return 'batch_' + Date.now().toString(36) + '_' + Math.random().toString(36).slice(2, 10);
  }

  function round7(n) { return Math.round(n * 1e7) / 1e7; }

  // Local wall-clock "YYYY-MM-DD HH:MM:SS" (local getters only — matches the
  // camera's storage convention; never UTC).
  function toSqlDateTime(d) {
    var p = function (n) { return (n < 10 ? '0' : '') + n; };
    return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate()) + ' ' +
           p(d.getHours()) + ':' + p(d.getMinutes()) + ':' + p(d.getSeconds());
  }

  function humanSize(bytes) {
    if (!bytes && bytes !== 0) return '';
    if (bytes < 1024) return bytes + ' B';
    var kb = bytes / 1024;
    if (kb < 1024) return Math.round(kb) + ' KB';
    return (kb / 1024).toFixed(kb / 1024 < 10 ? 1 : 0) + ' MB';
  }

  // Read provenance from an existing image file. Resolves to
  // { captured_at, gps_lat, gps_lng, geo_source, time_source }. Best-effort:
  // never rejects (an unreadable HEIC just yields the lastModified fallback).
  function readFileProvenance(file) {
    var out = { captured_at: null, gps_lat: null, gps_lng: null, geo_source: null, time_source: null };
    var finish = function () {
      if (!out.captured_at) {
        // Existing photo with no EXIF date → use the file's own modified time,
        // which is the best available proxy for when it was taken/saved. This
        // deliberately differs from the live camera (which uses the device clock
        // at shutter) because these files were created in the past.
        var lm = (file && file.lastModified) ? new Date(file.lastModified) : new Date();
        out.captured_at = toSqlDateTime(lm);
        out.time_source = 'file_mtime';
      }
      if (out.gps_lat !== null && out.gps_lng !== null && !out.geo_source) {
        out.geo_source = 'exif';
      }
      return out;
    };
    var exifr = window.exifr;
    if (!exifr || typeof exifr.parse !== 'function') return Promise.resolve(finish());
    return exifr.parse(file, {
      tiff: true, ifd0: true, exif: true, gps: true,
      pick: ['DateTimeOriginal', 'CreateDate', 'OffsetTimeOriginal',
             'GPSLatitude', 'GPSLongitude', 'GPSLatitudeRef', 'GPSLongitudeRef']
    }).then(function (data) {
      if (data) {
        var dt = (data.DateTimeOriginal instanceof Date && !isNaN(data.DateTimeOriginal.getTime()))
                   ? data.DateTimeOriginal
                   : ((data.CreateDate instanceof Date && !isNaN(data.CreateDate.getTime()))
                        ? data.CreateDate : null);
        if (dt) { out.captured_at = toSqlDateTime(dt); out.time_source = 'exif'; }
        if (typeof data.latitude === 'number')  out.gps_lat = round7(data.latitude);
        if (typeof data.longitude === 'number') out.gps_lng = round7(data.longitude);
      }
      return finish();
    }).catch(function () { return finish(); });
  }

  var Uploader = {
    el: null,          // overlay root
    surface: null,     // the Surface that opened it (refreshed on success)
    files: [],         // chosen File[]
    busy: false,

    open: function (surface) {
      this.surface = surface;
      this.build();
      this.reset();
      this.el.style.display = 'flex';
      document.body.classList.add('zml-no-scroll');
      // Open the OS picker straight away — the sheet is the staging/review step.
      var input = byId('zml-up-input');
      if (input) input.click();
    },

    build: function () {
      if (this.el) return;
      var ov = document.createElement('div');
      ov.className = 'zml-up';
      ov.id = 'zml-up';
      ov.style.display = 'none';
      ov.setAttribute('role', 'dialog');
      ov.setAttribute('aria-modal', 'true');
      ov.setAttribute('aria-label', 'Add photos');
      ov.innerHTML =
        '<div class="zml-up-backdrop" data-up="close"></div>' +
        '<div class="zml-up-sheet" role="document">' +
          '<div class="zml-up-head">' +
            '<button type="button" class="zml-up-back" data-up="close" aria-label="Cancel">' +
              '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><polyline points="15 18 9 12 15 6"/></svg>' +
              '<span>Cancel</span>' +
            '</button>' +
            '<div class="zml-up-title">Add Photos</div>' +
            '<button type="button" class="zml-up-go" data-up="start" disabled>Upload</button>' +
          '</div>' +
          '<div class="zml-up-body">' +
            '<label class="zml-up-note-label" for="zml-up-note">Note for this batch <span class="zml-up-note-opt">(optional)</span></label>' +
            '<textarea id="zml-up-note" class="zml-up-note" rows="2" maxlength="2000" ' +
              'placeholder="Add a note for every photo in this batch — e.g. site, job, or what these show."></textarea>' +
            '<p class="zml-up-note-hint">This note is saved on each photo (you can edit it per-photo later) and tagged on the whole batch.</p>' +
            '<div class="zml-up-pickrow">' +
              '<button type="button" class="zml-up-pick" data-up="pick">Choose photos…</button>' +
              '<span class="zml-up-count" id="zml-up-count">No photos selected</span>' +
            '</div>' +
            '<input type="file" id="zml-up-input" class="zml-up-input" accept="image/*" multiple />' +
            '<div class="zml-up-list" id="zml-up-list" aria-live="polite"></div>' +
          '</div>' +
          '<div class="zml-up-foot" id="zml-up-foot"></div>' +
        '</div>';
      document.body.appendChild(ov);
      this.el = ov;

      var self = this;
      ov.addEventListener('click', function (e) {
        var t = e.target.closest('[data-up]'); if (!t) return;
        var act = t.getAttribute('data-up');
        if (act === 'close') self.close();
        else if (act === 'pick') byId('zml-up-input').click();
        else if (act === 'start') self.start();
        else if (act === 'remove') self.removeAt(parseInt(t.dataset.pos, 10));
      });
      byId('zml-up-input').addEventListener('change', function (e) {
        self.addFiles(e.target.files);
        // Allow re-picking the same file(s) after a removal.
        e.target.value = '';
      });
      // Esc closes (when not uploading).
      ov.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !self.busy) { e.preventDefault(); self.close(); }
      });
    },

    reset: function () {
      this.files = [];
      this.busy = false;
      var note = byId('zml-up-note'); if (note) { note.value = ''; note.disabled = false; }
      var foot = byId('zml-up-foot'); if (foot) foot.innerHTML = '';
      this.renderList();
    },

    close: function () {
      if (this.busy) return; // don't abandon an in-flight batch by accident
      if (this.el) this.el.style.display = 'none';
      document.body.classList.remove('zml-no-scroll');
    },

    addFiles: function (fileList) {
      if (!fileList || !fileList.length) return;
      var max = (cfg().maxUploadBytes || 0);
      var added = 0, skipped = [];
      for (var i = 0; i < fileList.length; i++) {
        var f = fileList[i];
        var isImg = /^image\//.test(f.type) || /\.(jpe?g|png|gif|webp|heic|heif|tiff?|bmp)$/i.test(f.name);
        if (!isImg) { skipped.push(f.name + ' (not an image)'); continue; }
        if (max && f.size > max) { skipped.push(f.name + ' (over ' + humanSize(max) + ')'); continue; }
        this.files.push({ file: f, status: 'ready', pct: 0, error: '' });
        added++;
      }
      this.renderList();
      if (skipped.length) {
        var foot = byId('zml-up-foot');
        if (foot) foot.innerHTML = '<div class="zml-up-skip">Skipped ' + skipped.length +
          ': ' + esc(skipped.slice(0, 4).join(', ')) + (skipped.length > 4 ? '…' : '') + '</div>';
      }
    },

    removeAt: function (pos) {
      if (this.busy) return;
      if (pos >= 0 && pos < this.files.length) { this.files.splice(pos, 1); this.renderList(); }
    },

    renderList: function () {
      var list = byId('zml-up-list');
      var count = byId('zml-up-count');
      var go = this.el ? this.el.querySelector('[data-up="start"]') : null;
      if (!list) return;
      var n = this.files.length;
      if (count) count.textContent = n ? (n + (n === 1 ? ' photo selected' : ' photos selected')) : 'No photos selected';
      if (go) go.disabled = (n === 0 || this.busy);
      if (!n) { list.innerHTML = '<div class="zml-up-empty">Choose one or more photos to add. JPEG, PNG, HEIC and more are supported.</div>'; return; }
      var html = '';
      for (var i = 0; i < this.files.length; i++) {
        var it = this.files[i];
        var st = it.status;
        var statusText = st === 'done' ? 'Added' :
                         st === 'error' ? (it.error || 'Failed') :
                         st === 'uploading' ? (it.pct ? it.pct + '%' : 'Uploading…') : humanSize(it.file.size);
        var rowCls = 'zml-up-row' + (st === 'done' ? ' is-done' : '') + (st === 'error' ? ' is-error' : '');
        html += '<div class="' + rowCls + '">' +
          '<span class="zml-up-name" title="' + escAttr(it.file.name) + '">' + esc(it.file.name) + '</span>' +
          '<span class="zml-up-status">' + esc(statusText) + '</span>' +
          (this.busy || st === 'done'
            ? '<span class="zml-up-bar"><span class="zml-up-bar-fill" style="width:' + (st === 'done' ? 100 : (it.pct || 0)) + '%"></span></span>'
            : '<button type="button" class="zml-up-x" data-up="remove" data-pos="' + i + '" aria-label="Remove">&times;</button>') +
          '</div>';
      }
      list.innerHTML = html;
    },

    // Upload one staged item. Reads provenance, then POSTs the original file +
    // batch fields. Resolves true on success, false on failure (never rejects),
    // so the batch driver can keep going and report a summary.
    uploadOne: function (it, batchId, batchSeq, batchCount, note) {
      var self = this;
      it.status = 'uploading'; it.pct = 0; this.renderList();
      return readFileProvenance(it.file).then(function (prov) {
        return new Promise(function (resolve) {
          var fd = new FormData();
          fd.append('action', 'zml_upload');
          fd.append('nonce', cfg().nonce);
          fd.append('photo', it.file, it.file.name);
          fd.append('batch_id', batchId);
          fd.append('batch_seq', String(batchSeq));
          fd.append('batch_count', String(batchCount));
          if (note) fd.append('note', note);
          if (prov.captured_at) fd.append('captured_at', prov.captured_at);
          if (prov.gps_lat !== null) fd.append('gps_lat', String(prov.gps_lat));
          if (prov.gps_lng !== null) fd.append('gps_lng', String(prov.gps_lng));
          if (prov.geo_source)  fd.append('geo_source', prov.geo_source);
          if (prov.time_source) fd.append('time_source', prov.time_source);

          var xhr = new XMLHttpRequest();
          xhr.open('POST', cfg().ajaxurl, true);
          xhr.withCredentials = true;
          if (xhr.upload) {
            xhr.upload.onprogress = function (e) {
              if (e.lengthComputable) { it.pct = Math.round((e.loaded / e.total) * 100); self.renderList(); }
            };
          }
          xhr.onload = function () {
            var ok = false, resp = null;
            try { resp = JSON.parse(xhr.responseText); } catch (err) { resp = null; }
            ok = !!(resp && resp.success);
            if (ok) {
              it.status = 'done'; it.pct = 100;
            } else {
              it.status = 'error';
              it.error = (resp && resp.data) ? String(resp.data) : ('Failed (' + xhr.status + ')');
            }
            self.renderList();
            resolve(ok);
          };
          xhr.onerror = function () {
            it.status = 'error'; it.error = 'Network error'; self.renderList(); resolve(false);
          };
          xhr.send(fd);
        });
      });
    },

    start: function () {
      if (this.busy || !this.files.length) return;
      this.busy = true;
      var self = this;
      var note = (byId('zml-up-note') ? byId('zml-up-note').value : '').trim();
      var batchId = genBatchId();
      var go = this.el.querySelector('[data-up="start"]');
      // The Cancel control is the .zml-up-back button — NOT [data-up="close"],
      // whose first match is the backdrop <div> (disabling that is a no-op).
      var back = this.el.querySelector('.zml-up-back');
      var noteEl = byId('zml-up-note');
      if (go) { go.disabled = true; go.textContent = 'Uploading…'; }
      if (back) back.disabled = true;
      if (noteEl) noteEl.disabled = true;
      this.renderList();

      // Only the files still pending count toward this batch's sequence/size.
      var queue = [];
      for (var i = 0; i < this.files.length; i++) {
        if (this.files[i].status === 'ready' || this.files[i].status === 'error') queue.push(this.files[i]);
      }
      var batchCount = queue.length;
      var nextIndex = 0, done = 0, ok = 0;
      var concurrency = Math.max(1, Math.min(6, cfg().uploadConcurrency || 3));

      function pump() {
        if (nextIndex >= queue.length) return Promise.resolve();
        var myIndex = nextIndex++;
        var it = queue[myIndex];
        it.error = '';
        return self.uploadOne(it, batchId, myIndex, batchCount, note).then(function (good) {
          done++; if (good) ok++;
          return pump(); // this worker pulls the next item
        });
      }

      var workers = [];
      for (var w = 0; w < Math.min(concurrency, queue.length); w++) workers.push(pump());

      Promise.all(workers).then(function () {
        self.busy = false;
        // Count failures against what THIS run actually attempted (the queue),
        // not all staged rows — otherwise photos already 'done' from a previous
        // partial run would be miscounted as failures on a retry.
        var failed = batchCount - ok;
        var foot = byId('zml-up-foot');
        if (ok > 0 && self.surface && typeof self.surface.afterUpload === 'function') {
          // Refresh the gallery so the new photos show immediately.
          self.surface.afterUpload();
        }
        if (foot) {
          if (ok > 0 && failed === 0) {
            foot.innerHTML = '<div class="zml-up-ok">Added ' + ok + (ok === 1 ? ' photo' : ' photos') + ' ✓</div>';
            setTimeout(function () { self.close(); }, 900);
          } else if (ok > 0) {
            foot.innerHTML = '<div class="zml-up-ok">Added ' + ok + ', ' + failed + ' failed. You can retry the failed ones.</div>';
          } else {
            foot.innerHTML = '<div class="zml-up-skip">Upload failed. Check your connection and try again.</div>';
          }
        }
        if (go) { go.textContent = 'Upload'; go.disabled = false; }
        if (back) back.disabled = false;
        if (noteEl) noteEl.disabled = false;
        self.renderList();
      });
    }
  };

  /* ─────────────────────────────────────────────────────────────────────────
   * ConfirmSheet — the DOUBLE confirmation every delete goes through (product
   * decision: single AND bulk ask twice, because deletion also removes the
   * underlying attachment file — it is genuinely unrecoverable).
   *
   *   Step 1: summary — "Delete N photos?"
   *   Step 2: the point of no return — explicit "can't be undone" copy, with
   *           the destructive button visually distinct.
   *
   * Built once, appended to <body> (same reasoning as the lightbox: a
   * transformed .app-viewport ancestor can't trap position:fixed). Promise
   * API: ConfirmSheet.open(count) → resolves true only after BOTH confirms.
   * Never window.confirm() — unreliable in the PWA/desktop webviews.
   * ──────────────────────────────────────────────────────────────────────── */

  var ConfirmSheet = {
    built: false, _resolve: null, _step: 0, _count: 1,

    build: function () {
      if (this.built) return;
      var ov = document.createElement('div');
      ov.className = 'zml-cf'; ov.id = 'zml-cf';
      ov.setAttribute('role', 'alertdialog');
      ov.setAttribute('aria-modal', 'true');
      ov.setAttribute('aria-labelledby', 'zml-cf-title');
      ov.style.display = 'none';
      ov.innerHTML =
        '<div class="zml-cf-backdrop"></div>' +
        '<div class="zml-cf-card" role="document">' +
          '<div class="zml-cf-step" id="zml-cf-step"></div>' +
          '<h3 class="zml-cf-title" id="zml-cf-title"></h3>' +
          '<p class="zml-cf-body" id="zml-cf-body"></p>' +
          '<div class="zml-cf-actions">' +
            '<button type="button" class="zml-cf-cancel" id="zml-cf-cancel"></button>' +
            '<button type="button" class="zml-cf-confirm" id="zml-cf-confirm"></button>' +
          '</div>' +
        '</div>';
      document.body.appendChild(ov);
      var self = this;
      byId('zml-cf-cancel').addEventListener('click', function () { self.finish(false); });
      ov.querySelector('.zml-cf-backdrop').addEventListener('click', function () { self.finish(false); });
      byId('zml-cf-confirm').addEventListener('click', function () {
        if (self._step === 1) self.renderStep(2);   // first Yes → second ask
        else self.finish(true);                     // second Yes → do it
      });
      ov.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); self.finish(false); }
      });
      this.built = true;
    },

    open: function (count) {
      this.build();
      this._count = count;
      var self = this;
      return new Promise(function (resolve) {
        self._resolve = resolve;
        byId('zml-cf').style.display = '';
        self.renderStep(1);
      });
    },

    renderStep: function (step) {
      this._step = step;
      var n = this._count, one = (n === 1);
      var stepEl = byId('zml-cf-step'), t = byId('zml-cf-title'), b = byId('zml-cf-body');
      var cancel = byId('zml-cf-cancel'), confirmBtn = byId('zml-cf-confirm');
      if (step === 1) {
        stepEl.textContent = 'Step 1 of 2';
        t.textContent = one ? 'Delete this photo?' : 'Delete ' + n + ' photos?';
        b.textContent = one
          ? 'It will be removed from the library for everyone.'
          : 'They will be removed from the library for everyone.';
        cancel.textContent = 'Cancel';
        confirmBtn.textContent = 'Delete…';
        confirmBtn.classList.remove('zml-cf-final');
      } else {
        stepEl.textContent = 'Step 2 of 2 — confirm again';
        t.textContent = 'Permanently delete ' + (one ? 'this photo' : n + ' photos') + '?';
        b.textContent = 'This can’t be undone. ' + (one
          ? 'The photo and its file are deleted permanently.'
          : 'The photos and their files are deleted permanently.');
        cancel.textContent = one ? 'Keep photo' : 'Keep photos';
        confirmBtn.textContent = 'Yes, delete permanently';
        confirmBtn.classList.add('zml-cf-final');
      }
      cancel.focus();   // safe default focus — never the destructive button
    },

    finish: function (ok) {
      var ov = byId('zml-cf'); if (ov) ov.style.display = 'none';
      var r = this._resolve; this._resolve = null;
      if (r) r(ok);
    },

    isOpen: function () {
      var ov = byId('zml-cf');
      return !!(ov && ov.style.display !== 'none');
    }
  };

  // After a delete, refresh "the other" surface (widget ⇄ fullscreen) so the
  // two never disagree about what exists. The active surface was already
  // updated surgically.
  function refreshOtherSurface(active) {
    [widgetSurface, fsSurface].forEach(function (s) {
      if (s && s !== active && s.root && document.body.contains(s.root)) {
        s.resetAndLoad();
      }
    });
  }

  /*
   * v2.2.0 — THE one delete path (bulk select bar AND the lightbox button).
   * Double-confirms via ConfirmSheet, POSTs zml_delete, then removes exactly
   * the server-confirmed `deleted` ids from the active surface and refreshes
   * the other one. Partial failures keep their cells and are reported via
   * toast — a partial failure never reads as success.
   * Resolves with {deleted, failed} on completion, or null when cancelled /
   * errored (callers use this to know whether to re-anchor their own UI).
   */
  function performDelete(surface, ids, opts) {
    opts = opts || {};
    return ConfirmSheet.open(ids.length).then(function (ok) {
      if (!ok) return null;
      if (opts.busy) opts.busy(true);
      return ajax('zml_delete', { media_ids: JSON.stringify(ids) }).then(function (resp) {
        if (opts.busy) opts.busy(false);
        if (!resp || !resp.success) {
          tsmlToast((resp && resp.data) ? String(resp.data) : 'Delete failed. Please try again.', true);
          return null;
        }
        var deleted = (resp.data && resp.data.deleted) || [];
        var failed  = (resp.data && resp.data.failed)  || [];
        if (deleted.length) {
          surface.removeItemsByIds(deleted);
          if (surface.selectMode) surface.exitSelectMode();
          refreshOtherSurface(surface);
        }
        if (failed.length) {
          tsmlToast(failed.length + ' photo' + (failed.length === 1 ? '' : 's') + ' couldn’t be deleted: ' + String((failed[0] && failed[0].reason) || 'unknown reason'), true);
        } else if (deleted.length) {
          tsmlToast(deleted.length === 1 ? 'Photo deleted' : deleted.length + ' photos deleted');
        }
        return { deleted: deleted, failed: failed };
      }).catch(function () {
        if (opts.busy) opts.busy(false);
        tsmlToast('Connection error. Nothing was deleted.', true);
        return null;
      });
    });
  }

  /* ─────────────────────────────────────────────────────────────────────────
   * Lightbox — a single shared, top-level viewer reused by both surfaces.
   * Built once, lazily, and reparented to <body>. Operates against whichever
   * Surface opened it (so prev/next paginate that surface's items).
   * ──────────────────────────────────────────────────────────────────────── */

  var Lightbox = {
    built: false,
    surface: null,
    index: -1,
    lastFocus: null,
    hist: false,
    noteDirty: false,

    build: function () {
      if (this.built) return;
      var ov = document.createElement('div');
      ov.className = 'zml-lb'; ov.id = 'zml-lb';
      ov.setAttribute('role', 'dialog');
      ov.setAttribute('aria-modal', 'true');
      ov.setAttribute('aria-label', 'Media viewer');
      ov.style.display = 'none';
      ov.innerHTML =
        '<div class="zml-lb-top">' +
          '<button type="button" class="zml-lb-btn zml-lb-back" id="zml-lb-close" aria-label="Back to gallery">' +
            '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="15 18 9 12 15 6"/></svg>' +
            '<span class="zml-lb-back-label">Back</span>' +
          '</button>' +
          '<span class="zml-lb-title" id="zml-lb-title"></span>' +
          // v2.0.3: "View full size" = real in-lightbox zoom (pinch + double-tap,
          // drag to pan), driven by a CSS transform on the image. Never navigates
          // the window (a <button>, not an <a href=file_url> — that stranded
          // tab-less desktop/PWA webviews on the raw file). "Back"/Esc/backdrop
          // still exit from any zoom level (close action unchanged — invariant
          // I3). Prev/next arrows are shown only at fit (hidden once zoomed in).
          '<button type="button" class="zml-lb-btn" id="zml-lb-open" aria-label="Zoom in" aria-pressed="false" title="Zoom in">' +
            '<svg class="zml-lb-open-in" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/><path d="M11 8v6"/><path d="M8 11h6"/></svg>' +
            '<svg class="zml-lb-open-out" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true" style="display:none;"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/><path d="M8 11h6"/></svg>' +
          '</button>' +
        '</div>' +
        '<button type="button" class="zml-lb-nav zml-lb-prev" id="zml-lb-prev" aria-label="Previous">' +
          '<svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg></button>' +
        '<div class="zml-lb-stage" id="zml-lb-stage"><img class="zml-lb-img" id="zml-lb-img" alt="" draggable="false" /></div>' +
        '<button type="button" class="zml-lb-nav zml-lb-next" id="zml-lb-next" aria-label="Next">' +
          '<svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg></button>' +
        '<div class="zml-lb-meta" id="zml-lb-meta">' +
          '<div class="zml-lb-row" id="zml-lb-info"></div>' +

          // ── Location (tappable "Located" → reveals place/coords/provenance +
          //    user-tap "Open in Maps"). Populated by render() from the theme's
          //    GET /zorderz/v1/media/{id}/exif. Empty (display:none) when no GPS. ──
          '<div class="zml-lb-loc" id="zml-lb-loc" style="display:none;"></div>' +

          // ── Visibility control (owner-only; hidden for non-owners) ──
          '<div class="zml-lb-vis" id="zml-lb-vis" style="display:none;">' +
            '<div class="zml-lb-vis-seg" role="group" aria-label="Who can see this">' +
              '<button type="button" class="zml-vis-btn" data-visible="me">Visible only to You</button>' +
              '<button type="button" class="zml-vis-btn" data-visible="everyone">Visible to Everyone</button>' +
            '</div>' +
            '<span class="zml-inline-status" id="zml-lb-vis-status" aria-live="polite"></span>' +
          '</div>' +

          // ── Note (owner edits; others read) ──
          '<div class="zml-lb-note-wrap">' +
            '<label class="zml-lb-note-label" for="zml-lb-note">Note</label>' +
            '<textarea class="zml-lb-note" id="zml-lb-note" rows="2" placeholder="Add a note about this photo…" maxlength="2000"></textarea>' +
            '<div class="zml-lb-note-actions" id="zml-lb-note-actions">' +
              '<span class="zml-inline-status" id="zml-lb-note-status" aria-live="polite"></span>' +
              '<button type="button" class="zml-lb-save" id="zml-lb-save">Save note</button>' +
            '</div>' +
            '<p class="zml-lb-note-ro" id="zml-lb-note-ro" style="display:none;"></p>' +
          '</div>' +

          // ── v2.2.0: Delete (owner or admin only — hidden otherwise). Goes
          //    through the same DOUBLE-confirmed performDelete() path as bulk. ──
          '<div class="zml-lb-danger" id="zml-lb-danger" style="display:none;">' +
            '<button type="button" class="zml-lb-del" id="zml-lb-del">' +
              '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>' +
              '<span>Delete photo</span>' +
            '</button>' +
          '</div>' +
        '</div>';
      document.body.appendChild(ov);

      byId('zml-lb-close').addEventListener('click', function () { Lightbox.close(); });
      byId('zml-lb-prev').addEventListener('click', function () { Lightbox.step(-1); });
      byId('zml-lb-next').addEventListener('click', function () { Lightbox.step(1); });
      byId('zml-lb-save').addEventListener('click', function () { Lightbox.saveNote(); });
      byId('zml-lb-del').addEventListener('click', function () { Lightbox.deleteCurrent(); });   // v2.2.0
      // v2.0.3: button does a point-less step zoom (fit ⇄ 2× centered); the
      // stage hosts the real gesture zoom (pinch / double-tap / drag-pan).
      byId('zml-lb-open').addEventListener('click', function () { Lightbox.buttonZoom(); });
      this.bindZoomGestures(byId('zml-lb-stage'));

      // Backdrop / empty stage closes — but NOT while zoomed in (you're panning),
      // and NOT if this "click" was the tail of a drag (see _movedWhilePanning).
      ov.addEventListener('click', function (e) {
        if (Lightbox.scale > Lightbox.fitScale + 0.01) return; // zoomed → ignore
        if (Lightbox._movedWhilePanning) { Lightbox._movedWhilePanning = false; return; }
        if (e.target === ov || e.target.id === 'zml-lb-stage') Lightbox.close();
      });

      // Note editing toggles a dirty flag + Save affordance.
      var ta = byId('zml-lb-note');
      ta.addEventListener('input', function () {
        Lightbox.noteDirty = (ta.value !== (Lightbox.current() ? (Lightbox.current().note || '') : ''));
        var st = byId('zml-lb-note-status'); if (st) st.textContent = '';
        Lightbox.syncSaveEnabled();
      });

      // Visibility buttons.
      byId('zml-lb-vis').addEventListener('click', function (e) {
        var btn = e.target.closest('.zml-vis-btn'); if (!btn) return;
        Lightbox.saveVisibility(btn.dataset.visible);
      });

      this.built = true;
    },

    current: function () {
      var s = this.surface;
      return (s && this.index >= 0 && this.index < s.items.length) ? s.items[this.index] : null;
    },

    open: function (surface, pos) {
      this.build();
      this.surface = surface;
      this.index = pos;
      this.lastFocus = document.activeElement;
      this.noteDirty = false;

      var ov = byId('zml-lb');
      ov.style.display = '';
      document.body.classList.add('zml-lb-open');

      // History entry so phone/browser back closes the viewer.
      try { history.pushState({ tsmlLB: 1 }, ''); this.hist = true; } catch (e) { this.hist = false; }

      this.render();
      var c = byId('zml-lb-close'); if (c) c.focus();
      document.addEventListener('keydown', this._onKey);
    },

    teardown: function () {
      var ov = byId('zml-lb'); if (!ov) return;
      ov.style.display = 'none';
      this.resetZoom(false); // v2.0.3: clear zoom transform on close
      document.body.classList.remove('zml-lb-open');
      this.hist = false;
      document.removeEventListener('keydown', this._onKey);
      if (this.lastFocus && typeof this.lastFocus.focus === 'function') this.lastFocus.focus();
    },

    close: function () {
      if (byId('zml-lb') && byId('zml-lb').style.display === 'none') return;
      if (this.hist) { history.back(); } // popstate → teardown
      else { this.teardown(); }
    },

    step: function (dir) {
      var s = this.surface; if (!s) return;
      var n = this.index + dir;
      if (n < 0 || n >= s.items.length) return;
      this.index = n;
      this.noteDirty = false;
      this.render();
      // Near the end of a paginating (fullscreen) surface → fetch more.
      if (s.kind === 'fullscreen' && n >= s.items.length - 3 && s.hasMore) s.loadMore(false);
    },
    // ── v2.0.3: Real in-lightbox zoom ──────────────────────────────────────
    // The image is fit-to-screen by CSS (object-fit:contain) at scale 1; zoom
    // is a CSS transform we drive in JS: translate(tx,ty) scale(s). Supports
    // pinch (trackpad/touch), double-tap/click (toggle 2× at the point), and
    // drag-to-pan. Never navigates the window. "Back"/Esc/backdrop still exit.
    fitScale: 1,
    maxScale: 4,
    scale: 1,
    tx: 0,
    ty: 0,

    applyTransform: function (animate) {
      var img = byId('zml-lb-img'); if (!img) return;
      this.clampPan();
      img.style.transition = animate ? 'transform 0.18s ease' : 'none';
      img.style.transform = 'translate(' + this.tx + 'px,' + this.ty + 'px) scale(' + this.scale + ')';
    },

    // Keep the (scaled) image from being dragged completely off the stage.
    clampPan: function () {
      var stage = byId('zml-lb-stage'), img = byId('zml-lb-img');
      if (!stage || !img) return;
      // Rendered (fit) size of the image at scale 1, then the extra from scale.
      var sw = stage.clientWidth, sh = stage.clientHeight;
      var nw = img.clientWidth * this.scale, nh = img.clientHeight * this.scale;
      var maxX = Math.max(0, (nw - sw) / 2);
      var maxY = Math.max(0, (nh - sh) / 2);
      if (this.tx > maxX) this.tx = maxX; if (this.tx < -maxX) this.tx = -maxX;
      if (this.ty > maxY) this.ty = maxY; if (this.ty < -maxY) this.ty = -maxY;
      if (maxX === 0) this.tx = 0;
      if (maxY === 0) this.ty = 0;
    },

    // Zoom toward a point (sx,sy) given in stage-content coordinates measured
    // from the stage center, so the pixel under the cursor/fingers stays put.
    zoomTo: function (newScale, sx, sy, animate) {
      newScale = Math.max(this.fitScale, Math.min(this.maxScale, newScale));
      var k = newScale / this.scale;
      // Adjust pan so the focal point is preserved: p' = k*p + (1-k)*focal.
      this.tx = k * this.tx + (1 - k) * sx;
      this.ty = k * this.ty + (1 - k) * sy;
      this.scale = newScale;
      this.applyTransform(animate);
      this.syncZoomUI();
    },

    // Button: step between fit and 2×, centered on the image.
    buttonZoom: function () {
      if (this.scale > this.fitScale + 0.01) { this.resetZoom(true); }
      else { this.zoomTo(2, 0, 0, true); }
    },

    resetZoom: function (animate) {
      this.scale = this.fitScale; this.tx = 0; this.ty = 0;
      this.applyTransform(animate);
      this.syncZoomUI();
    },

    // Reflect zoom state in the UI: button icon/label + arrow visibility
    // (arrows only at fit; hidden once zoomed in). Stage gets .zml-zoomed for
    // cursor + to suppress backdrop-close.
    syncZoomUI: function () {
      var zoomedIn = this.scale > this.fitScale + 0.01;
      var ov = byId('zml-lb'); if (ov) ov.classList.toggle('zml-zoomed', zoomedIn);
      var btn = byId('zml-lb-open');
      if (btn) {
        btn.setAttribute('aria-pressed', zoomedIn ? 'true' : 'false');
        btn.setAttribute('aria-label', zoomedIn ? 'Fit to screen' : 'Zoom in');
        btn.setAttribute('title', zoomedIn ? 'Fit to screen' : 'Zoom in');
        var zin = btn.querySelector('.zml-lb-open-in');
        var zout = btn.querySelector('.zml-lb-open-out');
        if (zin) zin.style.display = zoomedIn ? 'none' : '';
        if (zout) zout.style.display = zoomedIn ? '' : 'none';
      }
      var prev = byId('zml-lb-prev'), next = byId('zml-lb-next');
      // Hide arrows when zoomed in; otherwise let step()/disabled logic show them.
      if (prev) prev.style.display = zoomedIn ? 'none' : '';
      if (next) next.style.display = zoomedIn ? 'none' : '';
    },

    // Convert a client (px) point to stage-center-relative coords.
    _focalFromClient: function (clientX, clientY) {
      var stage = byId('zml-lb-stage'); if (!stage) return { x: 0, y: 0 };
      var r = stage.getBoundingClientRect();
      return { x: clientX - (r.left + r.width / 2), y: clientY - (r.top + r.height / 2) };
    },

    bindZoomGestures: function (stage) {
      if (!stage || stage._zoomBound) return;
      stage._zoomBound = true;
      var L = this;

      // Trackpad pinch (ctrl+wheel) and wheel zoom.
      stage.addEventListener('wheel', function (e) {
        // Pinch-zoom on macOS trackpads arrives as wheel with ctrlKey.
        if (!e.ctrlKey && Math.abs(e.deltaY) < 1) return;
        e.preventDefault();
        var f = L._focalFromClient(e.clientX, e.clientY);
        var factor = Math.exp(-e.deltaY * (e.ctrlKey ? 0.01 : 0.0015));
        L.zoomTo(L.scale * factor, f.x, f.y, false);
      }, { passive: false });

      // Double-click / double-tap → toggle 2× at the point.
      stage.addEventListener('dblclick', function (e) {
        e.preventDefault();
        var f = L._focalFromClient(e.clientX, e.clientY);
        if (L.scale > L.fitScale + 0.01) L.resetZoom(true);
        else L.zoomTo(2, f.x, f.y, true);
      });

      // Mouse drag to pan (only meaningful when zoomed).
      var dragging = false, lastX = 0, lastY = 0;
      stage.addEventListener('mousedown', function (e) {
        if (L.scale <= L.fitScale + 0.01) return;
        dragging = true; lastX = e.clientX; lastY = e.clientY;
        L._movedWhilePanning = false;
        e.preventDefault();
      });
      window.addEventListener('mousemove', function (e) {
        if (!dragging) return;
        L.tx += (e.clientX - lastX); L.ty += (e.clientY - lastY);
        lastX = e.clientX; lastY = e.clientY;
        L._movedWhilePanning = true;
        L.applyTransform(false);
      });
      window.addEventListener('mouseup', function () { dragging = false; });

      // Touch: 1-finger pan (when zoomed), 2-finger pinch.
      var pinchStartDist = 0, pinchStartScale = 1, tLastX = 0, tLastY = 0, tPanning = false;
      function dist(t) {
        var dx = t[0].clientX - t[1].clientX, dy = t[0].clientY - t[1].clientY;
        return Math.hypot(dx, dy);
      }
      function mid(t) {
        return { x: (t[0].clientX + t[1].clientX) / 2, y: (t[0].clientY + t[1].clientY) / 2 };
      }
      stage.addEventListener('touchstart', function (e) {
        if (e.touches.length === 2) {
          pinchStartDist = dist(e.touches); pinchStartScale = L.scale;
          e.preventDefault();
        } else if (e.touches.length === 1 && L.scale > L.fitScale + 0.01) {
          tPanning = true; tLastX = e.touches[0].clientX; tLastY = e.touches[0].clientY;
          L._movedWhilePanning = false;
        }
      }, { passive: false });
      stage.addEventListener('touchmove', function (e) {
        if (e.touches.length === 2 && pinchStartDist > 0) {
          e.preventDefault();
          var m = mid(e.touches); var f = L._focalFromClient(m.x, m.y);
          L.zoomTo(pinchStartScale * (dist(e.touches) / pinchStartDist), f.x, f.y, false);
        } else if (tPanning && e.touches.length === 1) {
          e.preventDefault();
          L.tx += (e.touches[0].clientX - tLastX); L.ty += (e.touches[0].clientY - tLastY);
          tLastX = e.touches[0].clientX; tLastY = e.touches[0].clientY;
          L._movedWhilePanning = true;
          L.applyTransform(false);
        }
      }, { passive: false });
      stage.addEventListener('touchend', function (e) {
        if (e.touches.length < 2) pinchStartDist = 0;
        if (e.touches.length === 0) tPanning = false;
      });
    },

    render: function () {
      var it = this.current(); if (!it) return;

      byId('zml-lb-img').src = it.file_url;
      byId('zml-lb-img').alt = it.title || 'Media';
      byId('zml-lb-title').textContent = it.title || sourceBadge(it);
      // v2.0.3: each photo (open / prev / next) starts at fit-to-screen.
      this.resetZoom(false);

      // Info row: source · captured/created date. (Location is no longer a
      // dead text label here — it's an interactive control rendered below.)
      var bits = [ sourceBadge(it) ];
      var when = it.captured_at || it.created_at;
      if (when) bits.push(prettyDate(when));
      byId('zml-lb-info').textContent = bits.join('  ·  ');

      // ── Location: a tappable "Located" affordance when the photo is geotagged
      //    (Field review: "Why can't I press that and see where it is?"). ──
      this.renderLocation(it);

      // ── Visibility control (owner only) ──
      var visWrap = byId('zml-lb-vis');
      if (it.is_owner) {
        visWrap.style.display = '';
        var isPublic = (it.privacy === 'public');
        visWrap.querySelectorAll('.zml-vis-btn').forEach(function (b) {
          var on = (b.dataset.visible === (isPublic ? 'everyone' : 'me'));
          b.classList.toggle('zml-active', on);
          b.setAttribute('aria-pressed', on ? 'true' : 'false');
        });
        byId('zml-lb-vis-status').textContent = '';
      } else {
        visWrap.style.display = 'none';
      }

      // ── Note: owner edits; everyone else sees read-only (or nothing) ──
      var ta = byId('zml-lb-note');
      var actions = byId('zml-lb-note-actions');
      var ro = byId('zml-lb-note-ro');
      var label = byId('zml-lb').querySelector('.zml-lb-note-label');
      if (it.is_owner) {
        ta.style.display = ''; actions.style.display = ''; ro.style.display = 'none';
        if (label) label.style.display = '';
        ta.value = it.note || '';
        byId('zml-lb-note-status').textContent = '';
        this.noteDirty = false;
        this.syncSaveEnabled();
      } else {
        ta.style.display = 'none'; actions.style.display = 'none';
        if (it.note) {
          if (label) label.style.display = '';
          ro.style.display = ''; ro.textContent = it.note;
        } else {
          if (label) label.style.display = 'none';
          ro.style.display = 'none';
        }
      }

      // ── v2.2.0: Delete affordance (owner or admin; double-confirmed) ──
      var danger = byId('zml-lb-danger');
      if (danger) danger.style.display = it.can_delete ? '' : 'none';

      // Disable nav at the ends.
      byId('zml-lb-prev').disabled = (this.index <= 0);
      byId('zml-lb-next').disabled = (this.index >= this.surface.items.length - 1);
    },

    /* ── Location (Option 2: self-contained — no theme EXIF panel dependency) ──
     * The cell payload tells us IF a photo is geotagged (gps_lat/gps_lng). When
     * it is, we show a compact, tappable "Located" control. Tapping it fetches
     * the theme's GET /zorderz/v1/media/{id}/exif ONCE (cached per id) and reveals the
     * resolved place name, the formatted coordinates, a provenance chip
     * (Photo GPS vs Device location), and a user-tap "Open in Maps" link. The map
     * link only contacts a provider if the user taps it — the privacy model the
     * inspector was built around is preserved; we're only rendering the report.
     */
    renderLocation: function (it) {
      var box = byId('zml-lb-loc'); if (!box) return;

      // Reset the slot for this item.
      box.innerHTML = '';
      box.classList.remove('zml-lb-loc-open');

      var hasGps = (it.gps_lat != null && it.gps_lng != null);
      if (!hasGps) { box.style.display = 'none'; return; }
      box.style.display = '';

      var self = this;
      var mediaId = it.id;

      // The toggle: "📍 Located" → on tap, fetch (once) + reveal details.
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'zml-lb-loc-toggle';
      btn.setAttribute('aria-expanded', 'false');
      btn.innerHTML =
        '<span class="zml-lb-loc-pin" aria-hidden="true">\uD83D\uDCCD</span>' +
        '<span class="zml-lb-loc-label">Located</span>' +
        '<svg class="zml-lb-loc-caret" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="6 9 12 15 18 9"/></svg>';

      var detail = document.createElement('div');
      detail.className = 'zml-lb-loc-detail';
      detail.hidden = true;

      box.appendChild(btn);
      box.appendChild(detail);

      btn.addEventListener('click', function () {
        var open = btn.getAttribute('aria-expanded') === 'true';
        open = !open;
        btn.setAttribute('aria-expanded', open ? 'true' : 'false');
        box.classList.toggle('zml-lb-loc-open', open);
        detail.hidden = !open;
        if (open && !detail.dataset.built) {
          self.loadLocationInto(detail, mediaId, it);
        }
      });
    },

    // Fetch the EXIF report (cached per media id) and render the location block
    // into `host`. Falls back to the raw coordinates we already have if the
    // endpoint is unavailable, so "Located" is never a dead tap.
    loadLocationInto: function (host, mediaId, it) {
      var self = this;
      host.dataset.built = '1';
      host.innerHTML = '<div class="zml-lb-loc-loading"><span class="zml-lb-loc-spin" aria-hidden="true"></span>Finding location…</div>';

      this.fetchExif(mediaId).then(function (report) {
        var loc = report && report.location ? report.location : null;
        // If the endpoint had no location block (shouldn't happen when GPS is
        // present), synthesize a minimal one from the cell's own coordinates.
        if (!loc) {
          loc = {
            lat: it.gps_lat, lng: it.gps_lng,
            coord_label: self.coordLabel(it.gps_lat, it.gps_lng),
            place: '', geo_source: '',
            maps_url: self.mapsUrl(it.gps_lat, it.gps_lng)
          };
        }
        host.innerHTML = self.locationHTML(loc);
      }).catch(function () {
        // Network/endpoint failure — still honor the tap with coordinates + map.
        var lat = it.gps_lat, lng = it.gps_lng;
        host.innerHTML = self.locationHTML({
          lat: lat, lng: lng,
          coord_label: self.coordLabel(lat, lng),
          place: '', geo_source: '',
          maps_url: self.mapsUrl(lat, lng)
        });
      });
    },

    // Build the revealed location markup. Place name (if resolved) prominent,
    // coordinates secondary, a provenance chip, and the user-tap Maps link.
    locationHTML: function (loc) {
      var rows = '';
      if (loc.place) {
        rows += '<div class="zml-lb-loc-place">' + esc(loc.place) + '</div>';
        if (loc.coord_label) rows += '<div class="zml-lb-loc-coords">' + esc(loc.coord_label) + '</div>';
      } else if (loc.coord_label) {
        rows += '<div class="zml-lb-loc-place">' + esc(loc.coord_label) + '</div>';
      }

      var chip = '';
      if (loc.geo_source === 'exif') chip = 'Photo GPS (EXIF)';
      else if (loc.geo_source === 'device_fallback') chip = 'Device location';
      if (chip) rows += '<div class="zml-lb-loc-chips"><span class="zml-lb-loc-chip">' + esc(chip) + '</span></div>';

      if (loc.maps_url) {
        rows += '<a class="zml-lb-loc-maps" href="' + escAttr(loc.maps_url) + '" target="_blank" rel="noopener">' +
          'Open in Maps' +
          '<svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>' +
          '</a>';
      }
      return rows || '<div class="zml-lb-loc-coords">Location unavailable.</div>';
    },

    // GET /zorderz/v1/media/{id}/exif with the theme's wp_rest nonce. Cached per id
    // for the session so re-opening a photo (or toggling) never refetches. The
    // theme resolves + caches the place name server-side on first call.
    _exifCache: {},
    fetchExif: function (mediaId) {
      if (this._exifCache[mediaId]) return this._exifCache[mediaId];

      var base = (window.zdzData && zdzData.apiUrl)
        ? String(zdzData.apiUrl).replace(/\/$/, '')
        : ((window.wpApiSettings && wpApiSettings.root)
            ? String(wpApiSettings.root).replace(/\/$/, '') + '/zorderz/v1'
            : '/wp-json/zorderz/v1');
      var nonce = (window.zdzData && zdzData.nonce) ? zdzData.nonce
        : ((window.wpApiSettings && wpApiSettings.nonce) ? wpApiSettings.nonce : '');

      var headers = { 'Accept': 'application/json' };
      if (nonce) headers['X-WP-Nonce'] = nonce;

      var p = fetch(base + '/media/' + encodeURIComponent(mediaId) + '/exif', {
        headers: headers,
        credentials: 'same-origin'
      }).then(function (r) {
        if (!r.ok) throw new Error('HTTP ' + r.status);
        return r.json();
      }).then(function (resp) {
        return resp && resp.report ? resp.report : null;
      });

      // Cache the promise (so concurrent toggles share one request); drop it on
      // failure so a later tap can retry.
      this._exifCache[mediaId] = p;
      var self = this;
      p.catch(function () { delete self._exifCache[mediaId]; });
      return p;
    },

    // Local formatters mirroring the server's, used for the fallback path.
    coordLabel: function (lat, lng) {
      if (lat == null || lng == null) return '';
      var ns = lat >= 0 ? 'N' : 'S';
      var ew = lng >= 0 ? 'E' : 'W';
      return Math.abs(lat).toFixed(5) + '\u00b0 ' + ns + ', ' + Math.abs(lng).toFixed(5) + '\u00b0 ' + ew;
    },
    mapsUrl: function (lat, lng) {
      var la = Number(lat).toFixed(6), lo = Number(lng).toFixed(6);
      return 'https://www.openstreetmap.org/?mlat=' + la + '&mlon=' + lo + '#map=17/' + la + '/' + lo;
    },

    syncSaveEnabled: function () {
      var btn = byId('zml-lb-save'); if (!btn) return;
      // Save is enabled only when the text actually changed (prevents the
      // "kept saving the same thing again and again" behavior).
      if (btn.classList.contains('zdz-btn-busy')) return; // mid-save, leave as-is
      btn.disabled = !this.noteDirty;
    },

    saveNote: function () {
      var it = this.current(); if (!it || !it.is_owner) return;
      if (!this.noteDirty) return;
      var self = this;
      var ta = byId('zml-lb-note');
      var status = byId('zml-lb-note-status');
      var btn = byId('zml-lb-save');
      var note = ta.value;

      // No-shift busy state: keep the button's box, show spinner, lock input.
      btn.classList.add('zdz-btn-busy');
      btn.disabled = true;
      btn.setAttribute('aria-busy', 'true');
      if (status) status.textContent = 'Saving…';

      ajax('zml_save_note', { media_id: it.id, note: note }).then(function (resp) {
        btn.classList.remove('zdz-btn-busy');
        btn.removeAttribute('aria-busy');
        if (resp && resp.success) {
          it.note = resp.data.note;
          self.noteDirty = false;
          btn.disabled = true;                 // nothing new to save now
          if (status) status.textContent = 'Saved \u2713';
          self.surface.refreshCell(it);
          setTimeout(function () { if (status) status.textContent = ''; }, 2000);
        } else {
          btn.disabled = false;
          if (status) status.textContent = (resp && resp.data) ? String(resp.data) : 'Save failed';
        }
      }).catch(function () {
        btn.classList.remove('zdz-btn-busy');
        btn.removeAttribute('aria-busy');
        btn.disabled = false;
        if (status) status.textContent = 'Save failed';
      });
    },

    saveVisibility: function (visible) {
      var it = this.current(); if (!it || !it.is_owner) return;
      var targetPrivacy = (visible === 'everyone') ? 'public' : 'private';
      if (it.privacy === targetPrivacy) return;  // already there — no-op

      var self = this;
      var wrap = byId('zml-lb-vis');
      var status = byId('zml-lb-vis-status');
      var btns = wrap.querySelectorAll('.zml-vis-btn');

      btns.forEach(function (b) { b.disabled = true; });
      wrap.classList.add('zml-busy');
      if (status) status.textContent = 'Updating…';

      ajax('zml_save_visibility', { media_id: it.id, visible: visible }).then(function (resp) {
        btns.forEach(function (b) { b.disabled = false; });
        wrap.classList.remove('zml-busy');
        if (resp && resp.success) {
          it.privacy = resp.data.privacy;
          // Reflect selected state.
          var isPublic = (it.privacy === 'public');
          btns.forEach(function (b) {
            var on = (b.dataset.visible === (isPublic ? 'everyone' : 'me'));
            b.classList.toggle('zml-active', on);
            b.setAttribute('aria-pressed', on ? 'true' : 'false');
          });
          if (status) status.textContent = isPublic ? 'Shared with everyone \u2713' : 'Now private \u2713';
          self.surface.refreshCell(it);
          // If we made it private while viewing the Organization scope, it no
          // longer belongs there — drop it from that surface's list quietly.
          if (!isPublic && self.surface.scope === 'public') {
            self.dropCurrentFromSurface();
          }
          setTimeout(function () { if (status) status.textContent = ''; }, 2200);
        } else {
          if (status) status.textContent = (resp && resp.data) ? String(resp.data) : 'Update failed';
        }
      }).catch(function () {
        btns.forEach(function (b) { b.disabled = false; });
        wrap.classList.remove('zml-busy');
        if (status) status.textContent = 'Update failed';
      });
    },

    // v2.2.0 — delete the photo being viewed. Same double-confirmed path as
    // bulk delete; on success the surface list has already been updated by
    // performDelete(), so we just re-anchor the viewer (next photo, or close
    // when none remain).
    deleteCurrent: function () {
      var it = this.current(); if (!it || !it.can_delete) return;
      var self = this;
      var btn = byId('zml-lb-del');
      performDelete(this.surface, [it.id], {
        busy: function (on) {
          if (!btn) return;
          btn.disabled = on;
          btn.classList.toggle('zdz-btn-busy', on);
          btn.setAttribute('aria-busy', on ? 'true' : 'false');
        }
      }).then(function (result) {
        if (!result || !result.deleted || !result.deleted.length) return;  // cancelled or failed
        var s = self.surface;
        if (!s.items.length) { self.close(); return; }
        self.index = Math.min(self.index, s.items.length - 1);
        self.render();
      });
    },

    // Remove the currently-viewed item from its surface (used when an item
    // stops qualifying for the active scope), then re-render and re-anchor.
    dropCurrentFromSurface: function () {
      var s = this.surface; if (!s) return;
      var removedIndex = this.index;
      s.items.splice(removedIndex, 1);
      s.render();
      if (!s.items.length) { this.close(); return; }
      this.index = Math.min(removedIndex, s.items.length - 1);
      this.render();
    },

    _onKey: function (e) {
      var lb = byId('zml-lb');
      if (!lb || lb.style.display === 'none') return;
      if (ConfirmSheet.isOpen()) return;   // v2.2.0 — the sheet owns the keys
      var typing = (e.target && e.target.id === 'zml-lb-note');
      if (e.key === 'Escape') { e.preventDefault(); Lightbox.close(); return; }
      if (typing) return;
      if (e.key === 'ArrowLeft') { e.preventDefault(); Lightbox.step(-1); }
      else if (e.key === 'ArrowRight') { e.preventDefault(); Lightbox.step(1); }
      else if (e.key === 'Tab') { Lightbox._trap(e); }
    },

    _trap: function (e) {
      var ov = byId('zml-lb'); if (!ov) return;
      var focusables = ov.querySelectorAll('button:not([disabled]), a[href], textarea');
      if (!focusables.length) return;
      var first = focusables[0], last = focusables[focusables.length - 1];
      if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
      else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
    }
  };

  // Hardware/browser back closes the viewer.
  window.addEventListener('popstate', function () {
    var lb = byId('zml-lb');
    if (lb && lb.style.display !== 'none') Lightbox.teardown();
  });

  /* ─────────────────────────────────────────────────────────────────────────
   * Public API + full-screen auto-discovery
   * ──────────────────────────────────────────────────────────────────────── */

  var widgetSurface = null;
  var fsSurface = null;
  var fsObserver = null;

  var TSMedia = {
    _pendingFsState: null,

    mountWidget: function (rootEl) {
      if (!rootEl) rootEl = byId('zml-w');
      if (!rootEl) return;
      if (rootEl.dataset.tsmlMounted === '1') return;
      rootEl.dataset.tsmlMounted = '1';

      widgetSurface = new Surface({
        kind: 'widget',
        root: rootEl,
        gridWrap: rootEl.querySelector('#zml-w-grid') || rootEl.querySelector('.zml-w-grid'),
        grouped: false,
        scope: 'mine'   // the dashboard widget defaults to "My Photos"
      });
      widgetSurface.bindControls();
      widgetSurface.resetAndLoad();
    },

    mountFullscreen: function (rootEl) {
      if (!rootEl) rootEl = byId('zml-fs');
      if (!rootEl) return;
      if (rootEl.dataset.tsmlMounted === '1') return;
      rootEl.dataset.tsmlMounted = '1';

      var initial = TSMedia._pendingFsState || {};
      TSMedia._pendingFsState = null;

      // v2.3.0 — never start in the admin-only "All" scope for a non-admin
      // (e.g. a carried-over state); fall back to the public org view. The
      // server enforces this too; this keeps the UI coherent (a scope whose
      // tab isn't rendered would leave no active segment).
      var initialScope = initial.scope || 'public';
      if (initialScope === 'all' && !cfg().is_admin) initialScope = 'public';

      fsSurface = new Surface({
        kind: 'fullscreen',
        root: rootEl,
        gridWrap: rootEl.querySelector('#zml-fs-grid-wrap') || rootEl.querySelector('.zml-fs-grid-wrap'),
        scrollEl: rootEl.querySelector('#zml-fs-scroll') || rootEl.querySelector('.zml-fs-scroll'),
        sentinel: rootEl.querySelector('#zml-fs-sentinel') || rootEl.querySelector('.zml-sentinel'),
        grouped: true,
        scope: initialScope
      });
      if (initial.type) fsSurface.type = initial.type;

      // Reflect carried-over scope/type in the server-rendered controls.
      reflectControls(rootEl, fsSurface.scope, fsSurface.type);

      fsSurface.bindControls();
      fsSurface.resetAndLoad();
    }
  };

  // Sync the server-rendered segmented/chip active states to the controller's
  // starting scope/type (when "See All" carried them over).
  function reflectControls(root, scope, type) {
    root.querySelectorAll('.zml-seg-btn').forEach(function (b) {
      var on = b.dataset.scope === scope;
      b.classList.toggle('zml-active', on);
      b.setAttribute('aria-selected', on ? 'true' : 'false');
    });
    root.querySelectorAll('.zml-chip').forEach(function (b) {
      b.classList.toggle('zml-active', (b.dataset.type || '') === (type || ''));
    });
  }

  // Auto-discover the full-screen host whenever it is injected into #app-body,
  // however that happens (See All, dock tile, deep link). A single observer is
  // cheap and avoids coupling to the Bridge's internal flow.
  function watchAppBody() {
    var appBody = byId('app-body');
    if (!appBody || fsObserver) return;
    fsObserver = new MutationObserver(function () {
      var host = appBody.querySelector('#zml-fs');
      if (host && host.dataset.tsmlMounted !== '1') {
        TSMedia.mountFullscreen(host);
      }
    });
    fsObserver.observe(appBody, { childList: true, subtree: true });
    // Catch the case where it's already present at install time.
    var existing = appBody.querySelector('#zml-fs');
    if (existing && existing.dataset.tsmlMounted !== '1') TSMedia.mountFullscreen(existing);
  }

  window.TSMedia = TSMedia;

  // Boot: install the #app-body watcher, and mount the widget if it's already
  // on the page. Both are idempotent.
  function boot() {
    watchAppBody();
    var w = byId('zml-w');
    if (w && w.dataset.tsmlMounted !== '1') TSMedia.mountWidget(w);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
  // The dashboard re-renders widgets on this event; re-run boot to (re)mount.
  document.addEventListener('zdz_widgets_rendered', boot);
})();
