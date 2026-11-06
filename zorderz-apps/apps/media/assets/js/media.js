/**
 * Zorderz Media - shared controller.
 *
 * requests, toasts, the loader and busy buttons are the theme's kit of parts
 * (ZDZ.ui), and each photo shows its uploader's badge in the lower-right corner.
 * The bulk upload keeps its own XMLHttpRequest, because it shows each file's upload progress and the
 * kit's requests have no progress events.
 *
 * The LIGHTBOX is built around the photo instead of around the chrome.
 * The stage is the full viewport; the top bar and the meta panel float over
 * it, and the meta panel is a collapsed-by-default sheet. A stage squeezed by
 * the chrome left a phone in portrait with wide black borders, small images
 * and no useful zoom. The zoom engine was fine -- the stage was not. The meta
 * panel was a normal-flow flex child running to ~370px of an ~850px viewport,
 * so a portrait photo was being fitted into a landscape-shaped box and
 * pillarboxed. Because zoom scale 1 means "fit", the squeezed stage was also
 * capping how far you could zoom: 4x of an already-shrunken image never reached
 * 1:1 pixels on a 4000px original. Enlarging the fit raises that ceiling by the
 * same ratio, with no change to the zoom maths.
 * Also: real touch double-tap (it previously relied on a synthesised
 * dblclick that touch-action:none suppresses on phones); progressive paint
 * (thumbnail first, original swapped in on decode) instead of staring at an
 * empty stage while 4-8MB streams; an error path for HEIC originals, which
 * every non-Apple browser fails to decode and which used to fail to silent
 * black; a pan re-clamp on load, resize and rotate; and an iOS keyboard inset
 * so the software keyboard cannot cover the note field.
 *
 * (v2.3.3 makes the dashboard WIDGET
 * browsable on its own: a larger first page + a "Load more" button that appends
 * pages in place, so users don't have to open "See All" to see beyond the
 * recent slice. v2.3.0 added the admin-only "All" scope: browse every photo
 * org-wide incl. private; server-enforced admin gate. v2.2.0 added selection
 * mode + delete: granular or bulk, double-confirmed, owner-or-admin.)
 *
 * ONE controller, TWO surfaces:
 *   • window.ZMLMedia.mountWidget(rootEl)      → compact dashboard card
 *   • window.ZMLMedia.mountFullscreen(rootEl)  → roomy #app-body gallery
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
  // Phone tiles must look sharp. A tile on a 3x phone needs ~360 pixels and the thumb is 300; the theme
  // also offers a 768-pixel thumb, which high-density screens now pick.
  function thumbSrcset(it) {
    if (!it || !it.thumbnail_2x_url || !it.thumbnail_url) return '';
    return ' srcset="' + escAttr(it.thumbnail_url) + ' 1x, ' + escAttr(it.thumbnail_2x_url) + ' 2x"';
  }

  /* the app now runs on the theme's kit of parts (ZDZ.ui): its
   * requests, toasts, loader and busy buttons are the kit's, so a 502 page, a timeout or a lost signal
   * reads as one plain sentence, the same in every app. The kit is looked up when it is used (it loads
   * in the footer beside this file), and when it is missing the app says so instead of failing silently. */
  var NEEDS_KIT = 'This app needs a newer Zorderz theme. Reload the app.';
  function ui() { return (window.ZDZ && window.ZDZ.ui) ? window.ZDZ.ui : null; }

  // admin-ajax through the kit: resolves to the `data` of {success:true}; any failure rejects with a
  // ZDZ.ui.RequestError whose message a person can read (the server's own words for {success:false}).
  function ajax(action, data) {
    if (!ui()) return Promise.reject(new Error(NEEDS_KIT));
    return ZDZ.ui.ajax(action, Object.assign({ nonce: cfg().nonce }, data || {})).catch(function (err) {
      // A refusal sent as wp_send_json_error('a sentence', 403) keeps its own words
      // ("You can only edit notes on your own media."), not the kit's sign-in sentence.
      if (err && err.kind === 'auth' && err.status === 403 && err.body) {
        try {
          var j = JSON.parse(err.body);
          if (j && typeof j.data === 'string' && j.data) err.message = j.data;
        } catch (e) { /* not JSON: keep the kit's sentence */ }
      }
      throw err;
    });
  }

  // The kit's toast (kind: 'success' | 'error'). Without the kit there is no toast; the grid says why
  // (needKit below).
  function say(msg, kind) {
    if (ui()) ZDZ.ui.toast(msg, kind ? { kind: kind } : undefined);
  }
  function sayError(err, retry) {
    if (ui()) ZDZ.ui.toastError(err, retry);
  }
  // The kit's busy button: keeps its size, one press at a time.
  function busy(btn, work) {
    if (ui()) return ZDZ.ui.busy(btn, work);
    return Promise.resolve().then(typeof work === 'function' ? work : function () { return work; });
  }
  function loaderHtml(label) {
    return ui() ? ZDZ.ui.loadingHtml(label) : '<div class="zml-empty">' + esc(label) + '</div>';
  }

  /* A small badge in the lower-right corner of each photo says who uploaded it. The server sends the
   * uploader's initials only (never an id, name or email); the kit draws that person's badge (their
   * shape and tone, from zdzData.people). No initials (the shared kiosk, an account without initials,
   * an older server) means no badge. */
  function whoBadge(it, cls) {
    var ini = it && it.uploader_initials ? String(it.uploader_initials) : '';
    if (!ini || !ui() || typeof ZDZ.ui.personChip !== 'function') return '';
    return '<span class="' + cls + '">' + ZDZ.ui.personChip(ini, { size: 'sm', title: 'Uploaded by ' + ini }) + '</span>';
  }

  /* ── Date grouping (iOS-Photos / Google-Photos style section headers) ──── */

  function startOfDay(d) { return new Date(d.getFullYear(), d.getMonth(), d.getDate()).getTime(); }

  function parseDate(ds) {
    if (!ds) return null;
    var d = new Date(String(ds).replace(' ', 'T'));
    return isNaN(d.getTime()) ? null : d;
  }

  // compact relative time for the My-Photos card footer. Mirrors
  // zdz-camera's gallery timeAgo so the two surfaces read identically.
  function timeAgo(ds) {
    var d = parseDate(ds);
    if (!d) return '';
    var s = Math.floor((new Date() - d) / 1000);
    if (s < 60) return 'just now';
    if (s < 3600) return Math.floor(s / 60) + 'm ago';
    if (s < 86400) return Math.floor(s / 3600) + 'h ago';
    if (s < 604800) return Math.floor(s / 86400) + 'd ago';
    return d.toLocaleDateString();
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
    if (item.media_type === 'video') return 'Video';
    if (item.source_app === 'zdz-sketch-pad') return 'Sketch';
    if (item.source_app === 'zdz-camera') return 'Camera';
    if (item.media_type === 'sketch') return 'Sketch';
    return 'Photo';
  }

  // "M:SS" from milliseconds (clip duration).
  function fmtDur(ms) {
    ms = parseInt(ms, 10) || 0;
    var s = Math.round(ms / 1000), m = Math.floor(s / 60); s = s % 60;
    return m + ':' + (s < 10 ? '0' : '') + s;
  }
  function isVideo(item) { return !!(item && (item.is_video || item.media_type === 'video')); }
  // the lower-right video badge (camcorder glyph + duration) drawn on a
  // video cell's thumbnail. Empty for photos/sketches.
  function videoBadge(item) {
    if (!isVideo(item)) return '';
    var dur = item.duration_ms ? ('<span class="zml-cell-vdur">' + esc(fmtDur(item.duration_ms)) + '</span>') : '';
    return '<span class="zml-cell-video" aria-hidden="true">' +
      '<svg viewBox="0 0 24 24" width="13" height="13" fill="currentColor" aria-hidden="true"><path d="M4 6h11a2 2 0 0 1 2 2v1.2l3.3-2.1a.6.6 0 0 1 .95.5v8.8a.6.6 0 0 1-.95.5L17 15.8V17a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2z"/></svg>' +
      dur +
    '</span>';
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

    /* selection mode (granular/bulk delete) */
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
      // the "All" scope is admin-only. The server also enforces this
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
      ZMLMedia._pendingFsState = { scope: self.scope, type: self.type };
      var id = cfg().fullscreenAppId || 'zdz-media-all';
      if (window.Bridge && typeof window.Bridge.loadApp === 'function') {
        window.Bridge.loadApp(id);
      }
    });

    // "Add Photos" → open the bulk-upload sheet. Present on both surfaces.
    var add = this.root.querySelector('[data-action="add"]');
    if (add) add.addEventListener('click', function () { Uploader.open(self); });

    // "Select" toggles selection mode (fullscreen gallery only; the
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
    if (this.selectMode) this.exitSelectMode();   // selection can't survive a reload
    this.offset = 0; this.hasMore = true; this.items = [];
    if (this.gridWrap) this.gridWrap.innerHTML = loaderHtml('Loading…');   // the kit's loader
    this.loadMore(true);
  };

  Surface.prototype.loadMore = function (isFirst) {
    var self = this;
    if (this.loading || (!this.hasMore && !isFirst)) return Promise.resolve();
    this.loading = true;

    var req = { scope: this.scope, media_type: this.type, offset: this.offset };
    // the widget shows a useful first page (12 ≈ 4 rows on phones) and
    // a "Load more" button appends further pages IN PLACE, so the widget is
    // browsable on its own without opening the full-screen gallery. (The
    // full-screen surface keeps the theme's larger page size.)
    if (this.kind === 'widget') req.limit = 12;

    return ajax('zml_list', req).then(function (data) {
      self.loading = false;
      data = data || {};
      var batch = data.items || [];
      self.items = self.items.concat(batch);
      self.hasMore = !!data.has_more;
      self.offset = (typeof data.offset === 'number') ? data.offset : (self.offset + batch.length);
      self.render();
    }).catch(function (err) {
      self.loading = false;
      var msg = (err && err.message) || 'Couldn\u2019t load media. Try again.';
      // the kit's plain sentence, where the grid would be (first page) - or, for a later page,
      // a toast with Try again, and the "Load more" button is put back (it used to stay on "Loading\u2026").
      if (isFirst) {
        if (self.gridWrap) self.gridWrap.innerHTML = '<div class="zml-empty zml-load-error" role="alert">' + esc(msg) + '</div>';
      } else {
        self.render();
        sayError(err, function () { self.loadMore(false); });
      }
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
        html += '<div class="zml-group"><h2 class="zml-group-h">' + esc(g.label) + '</h2><div class="zml-grid zml-grid-cards">';
        g.items.forEach(function (it) { html += self.cellHTML(it); });
        html += '</div></div>';
      });
    } else {
      // Flat compact strip (widget) — newest first, no headers.
      html += '<div class="zml-grid zml-grid-compact">';
      this.items.forEach(function (it) { html += self.cellHTML(it); });
      html += '</div>';
      // "Load more" — append the next page in place so the widget is
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
    this.outlineCells();   // the outline on each photo with drawings this person sees

    wrap.querySelectorAll('.zml-cell').forEach(function (cell) {
      cell.addEventListener('click', function () {
        var pos = parseInt(this.dataset.pos, 10);
        // in selection mode a tap toggles the checkmark instead of
        // opening the viewer.
        if (self.selectMode) { self.toggleSelect(pos, this); return; }
        Lightbox.open(self, pos);
      });
    });

    // wire the widget "Load more" button.
    var moreBtn = wrap.querySelector('.zml-w-more');
    if (moreBtn) {
      moreBtn.addEventListener('click', function () {
        if (self.loading || !self.hasMore) return;
        // the kit's busy button (keeps its size) until the page arrives and
        // loadMore() re-renders the grid.
        busy(this, function () { return self.loadMore(false); });
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
    // selection-mode affordances. Photos the viewer may NOT delete
    // (not theirs, not an admin) render dimmed and unselectable in select mode.
    var selCls = '';
    if (this.selectMode) {
      selCls = it.can_delete ? (this.selected[it.id] ? ' zml-checked' : '') : ' zml-nodel';
    }
    // My-Photos parity footer (revealed only by .zml-grid-cards CSS on
    // the full-screen grid; the compact widget hides it, so its markup is inert
    // there). Name · time-ago · source pill, mirroring zdz-camera's gallery card.
    var when = timeAgo(it.captured_at || it.created_at);
    var foot =
      '<span class="zml-cell-foot">' +
        '<span class="zml-cell-title">' + esc(it.title || 'Untitled') + '</span>' +
        '<span class="zml-cell-metarow">' +
          '<span class="zml-cell-time">' + esc(when) + '</span>' +
          '<span class="zml-cell-src">' + esc(sourceBadge(it)) + '</span>' +
        '</span>' +
      '</span>';
    // The THUMB wraps the image + every overlay so the footer can sit beneath it
    // Overlays stay absolutely positioned against the thumb, not the
    // card, so the badge/check/note/vis land exactly as before.
    // v2.10.3: who uploaded it, in the thumb's lower-right corner (drawn before the clip badge,
    // which steps left of it in CSS).
    var who = whoBadge(it, 'zml-cell-who');
    return '<button type="button" class="zml-cell' + selCls + '" data-pos="' + pos + '" data-id="' + it.id + '" ' +
      'aria-label="' + escAttr((it.title || 'Media') + ' — ' + sourceBadge(it) + (who ? ' — uploaded by ' + it.uploader_initials : '')) + '">' +
      '<span class="zml-cell-thumb">' +
        '<img class="zml-cell-img" src="' + escAttr(it.thumbnail_url) + '"' + thumbSrcset(it) + ' alt="' + escAttr(it.title) + '" loading="lazy" />' +
        '<span class="zml-cell-badge">' + esc(sourceBadge(it)) + '</span>' +
        who +
        videoBadge(it) +
        vis + note +
        '<span class="zml-cell-check" aria-hidden="true">' +
          '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>' +
        '</span>' +
      '</span>' +
      foot +
      '</button>';
  };

  /* 
     the kit's outline and badge on the thumb of every photo with drawings this person sees (marks_n / notes_n, sent
     with the list). Nothing on an older theme. */
  Surface.prototype.outlineCells = function () {
    if (!this.gridWrap || !window.ZDZ || !ZDZ.ui || !ZDZ.ui.marks || !ZDZ.ui.marks.outline) return;
    var self = this;
    this.gridWrap.querySelectorAll('.zml-cell').forEach(function (cell) {
      var it = self.items[parseInt(cell.getAttribute('data-pos'), 10)];
      var thumb = cell.querySelector('.zml-cell-thumb') || cell;
      if (it) ZDZ.ui.marks.outline(thumb, it.marks_n | 0, it.notes_n | 0);
    });
  };

  // Update one item's cell affordances (note dot / visibility dot) in place,
  // without re-rendering the whole grid (no layout shift).
  Surface.prototype.refreshCell = function (it) {
    if (!this.gridWrap) return;
    var pos = this.items.indexOf(it);
    var cell = this.gridWrap.querySelector('.zml-cell[data-pos="' + pos + '"]');
    if (!cell) return;
    // overlays now live inside .zml-cell-thumb (falls back to the cell
    // for any pre-2.7.0 markup that might linger), so create/insert against it.
    var thumb = cell.querySelector('.zml-cell-thumb') || cell;

    var noteDot = thumb.querySelector('.zml-cell-note');
    if (it.note && !noteDot) {
      var n = document.createElement('span');
      n.className = 'zml-cell-note'; n.title = 'Has a note'; n.setAttribute('aria-hidden', 'true'); n.textContent = '\uD83D\uDCDD';
      thumb.appendChild(n);
    } else if (!it.note && noteDot) {
      noteDot.remove();
    }

    var visDot = thumb.querySelector('.zml-cell-vis');
    var shouldShowVis = it.is_owner && it.privacy === 'public';
    if (shouldShowVis && !visDot) {
      var v = document.createElement('span');
      v.className = 'zml-cell-vis'; v.title = 'Visible to everyone'; v.setAttribute('aria-hidden', 'true');
      v.innerHTML = '<svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.2"><circle cx="12" cy="12" r="10"/><path d="M2 12h20"/><path d="M12 2a15.3 15.3 0 0 1 0 20a15.3 15.3 0 0 1 0-20"/></svg>';
      thumb.insertBefore(v, thumb.querySelector('.zml-cell-note') || null);
    } else if (!shouldShowVis && visDot) {
      visDot.remove();
    }
    if (window.ZDZ && ZDZ.ui && ZDZ.ui.marks && ZDZ.ui.marks.outline) ZDZ.ui.marks.outline(thumb, it.marks_n | 0, it.notes_n | 0);   // v2.13.0
  };

  /* ─────────────────────────────────────────────────────────────────────────
   * Selection mode + delete (granular or bulk, double-confirmed).
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
        '<button type="button" class="zml-selbar-tag" data-role="tag" disabled>Tag</button>' +
        '<button type="button" class="zml-selbar-share" data-role="share" disabled>Share</button>' +
        '<button type="button" class="zml-selbar-del" data-role="del" disabled>Delete</button>';
      this.root.appendChild(bar);
      bar.querySelector('[data-role="cancel"]').addEventListener('click', function () { self.exitSelectMode(); });
      // bulk-tag: reuse the selection the user already made for delete.
      bar.querySelector('[data-role="tag"]').addEventListener('click', function () {
        var ids = self.selectedIds(); if (!ids.length) return;
        TagPrompt.open(ids.length).then(function (tag) {
          if (!tag) return;
          bulkTag(self, ids, tag, bar.querySelector('[data-role="tag"]'));
        });
      });
      // each action passes its button; the kit's busy() shows it working.
      bar.querySelector('[data-role="share"]').addEventListener('click', function () {
        var ids = self.selectedIds(); if (!ids.length) return;
        openShareDialog(ids, bar.querySelector('[data-role="share"]'), function () { self.exitSelectMode(); });
      });
      bar.querySelector('[data-role="del"]').addEventListener('click', function () {
        var ids = self.selectedIds(); if (!ids.length) return;
        performDelete(self, ids, { button: bar.querySelector('[data-role="del"]') });
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
    var tag = this.selbar.querySelector('[data-role="tag"]');
    if (count) count.textContent = n + ' selected';
    if (del) { del.disabled = (n === 0); del.textContent = n > 1 ? 'Delete (' + n + ')' : 'Delete'; }
    if (tag) { tag.disabled = (n === 0); tag.textContent = n > 1 ? 'Tag (' + n + ')' : 'Tag'; }
    var share = this.selbar.querySelector('[data-role="share"]');
    if (share) { share.disabled = (n === 0); share.textContent = n > 1 ? 'Share (' + n + ')' : 'Share'; }
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

  /* minimal single-field prompt for bulk-tagging the selected photos.
   * Same top-level-append reasoning as ConfirmSheet; never window.prompt()
   * (unreliable in the PWA/webviews). open() resolves the entered tag, or null. */
  var TagPrompt = {
    built: false, _resolve: null,
    build: function () {
      if (this.built) return;
      var ov = document.createElement('div');
      ov.className = 'zml-cf zml-tagprompt'; ov.id = 'zml-tagprompt';
      ov.setAttribute('role', 'dialog'); ov.setAttribute('aria-modal', 'true');
      ov.setAttribute('aria-labelledby', 'zml-tp-title');
      ov.style.display = 'none';
      ov.innerHTML =
        '<div class="zml-cf-backdrop"></div>' +
        '<div class="zml-cf-card" role="document">' +
          '<h3 class="zml-cf-title" id="zml-tp-title"></h3>' +
          '<input type="text" class="zml-tp-input" id="zml-tp-input" placeholder="Tag to add…" maxlength="60" autocomplete="off" autocapitalize="none" autocorrect="off" spellcheck="false" />' +
          '<div class="zml-cf-actions">' +
            '<button type="button" class="zml-cf-cancel" id="zml-tp-cancel">Cancel</button>' +
            '<button type="button" class="zml-cf-confirm" id="zml-tp-apply">Add tag</button>' +
          '</div>' +
        '</div>';
      document.body.appendChild(ov);
      var self = this;
      byId('zml-tp-cancel').addEventListener('click', function () { self.finish(null); });
      ov.querySelector('.zml-cf-backdrop').addEventListener('click', function () { self.finish(null); });
      byId('zml-tp-apply').addEventListener('click', function () { self.submit(); });
      byId('zml-tp-input').addEventListener('keydown', function (e) {
        if (e.key === 'Enter') { e.preventDefault(); self.submit(); }
        else if (e.key === 'Escape') { e.preventDefault(); self.finish(null); }
      });
      this.built = true;
    },
    submit: function () {
      var inp = byId('zml-tp-input');
      var v = (inp.value || '').trim();
      if (!v) { inp.focus(); return; }
      this.finish(v);
    },
    open: function (count) {
      this.build();
      var self = this;
      byId('zml-tp-title').textContent = (count === 1) ? 'Tag this photo' : 'Tag ' + count + ' photos';
      var inp = byId('zml-tp-input'); inp.value = '';
      return new Promise(function (resolve) {
        self._resolve = resolve;
        byId('zml-tagprompt').style.display = '';
        setTimeout(function () { inp.focus(); }, 30);
      });
    },
    finish: function (val) {
      var ov = byId('zml-tagprompt'); if (ov) ov.style.display = 'none';
      var r = this._resolve; this._resolve = null;
      if (r) r(val);
    }
  };

  // Share-to-teammate dialog: pick a recipient (theme's authoritative
  // roster, kiosk-excluded) + an optional note. Resolves { recipientId,
  // recipientName, note } or null. The server re-authorizes the share.
  var SharePrompt = {
    built: false, _resolve: null, _people: [], _sel: 0,
    build: function () {
      if (this.built) return;
      var ov = document.createElement('div');
      ov.className = 'zml-cf zml-shareprompt'; ov.id = 'zml-shareprompt';
      ov.setAttribute('role', 'dialog'); ov.setAttribute('aria-modal', 'true');
      ov.setAttribute('aria-labelledby', 'zml-sp-title');
      ov.style.display = 'none';
      ov.innerHTML =
        '<div class="zml-cf-backdrop"></div>' +
        '<div class="zml-cf-card" role="document">' +
          '<h3 class="zml-cf-title" id="zml-sp-title">Share to a teammate</h3>' +
          '<input type="text" class="zml-sp-search" id="zml-sp-search" placeholder="Search teammates…" autocomplete="off" autocapitalize="none" autocorrect="off" spellcheck="false" />' +
          '<div class="zml-sp-list" id="zml-sp-list" role="listbox" aria-label="Teammates"></div>' +
          '<textarea class="zml-sp-note" id="zml-sp-note" rows="2" placeholder="Add a note (optional)…" maxlength="500"></textarea>' +
          '<div class="zml-cf-actions">' +
            '<button type="button" class="zml-cf-cancel" id="zml-sp-cancel">Cancel</button>' +
            '<button type="button" class="zml-cf-confirm" id="zml-sp-send" disabled>Share</button>' +
          '</div>' +
        '</div>';
      document.body.appendChild(ov);
      var self = this;
      byId('zml-sp-cancel').addEventListener('click', function () { self.finish(null); });
      ov.querySelector('.zml-cf-backdrop').addEventListener('click', function () { self.finish(null); });
      byId('zml-sp-send').addEventListener('click', function () { self.submit(); });
      var deb;
      byId('zml-sp-search').addEventListener('input', function () {
        var q = this.value; clearTimeout(deb); deb = setTimeout(function () { self.load(q); }, 200);
      });
      byId('zml-sp-search').addEventListener('keydown', function (e) { if (e.key === 'Escape') { e.preventDefault(); self.finish(null); } });
      this.built = true;
    },
    load: function (q) {
      var self = this;
      var list = byId('zml-sp-list'); if (list) list.setAttribute('aria-busy', 'true');
      self._err = '';
      ajax('zml_recipients', { search: q || '' }).then(function (data) {
        self._people = (data && data.people) ? data.people : [];
        self.renderList();
      }).catch(function (err) {
        // say why the list is empty (the kit's sentence), not "No teammates found."
        self._people = []; self._err = (err && err.message) || ''; self.renderList();
      });
    },
    renderList: function () {
      var self = this;
      var list = byId('zml-sp-list'); if (!list) return;
      list.removeAttribute('aria-busy');
      list.textContent = '';
      if (!self._people.length) {
        var e = document.createElement('div'); e.className = 'zml-sp-empty'; e.textContent = self._err || 'No teammates found.';
        if (self._err) e.setAttribute('role', 'alert');
        list.appendChild(e); self.syncSend(); return;
      }
      self._people.forEach(function (p) {
        var row = document.createElement('button');
        row.type = 'button'; row.className = 'zml-sp-person'; row.setAttribute('role', 'option');
        row.dataset.id = p.id;
        var av = document.createElement('span'); av.className = 'zml-sp-av'; av.textContent = p.initials || (p.name || '?').charAt(0).toUpperCase();
        var nm = document.createElement('span'); nm.className = 'zml-sp-nm'; nm.textContent = p.name || ('User ' + p.id);
        row.appendChild(av); row.appendChild(nm);
        if (p.id === self._sel) { row.classList.add('is-sel'); row.setAttribute('aria-selected', 'true'); }
        row.addEventListener('click', function () {
          self._sel = p.id;
          Array.prototype.forEach.call(list.querySelectorAll('.zml-sp-person'), function (n) {
            var on = (Number(n.dataset.id) === self._sel);
            n.classList.toggle('is-sel', on); n.setAttribute('aria-selected', on ? 'true' : 'false');
          });
          self.syncSend();
        });
        list.appendChild(row);
      });
      self.syncSend();
    },
    syncSend: function () { var b = byId('zml-sp-send'); if (b) b.disabled = !(this._sel > 0); },
    submit: function () {
      if (!(this._sel > 0)) return;
      var name = '';
      for (var i = 0; i < this._people.length; i++) { if (this._people[i].id === this._sel) { name = this._people[i].name; break; } }
      var note = (byId('zml-sp-note').value || '').trim();
      this.finish({ recipientId: this._sel, recipientName: name, note: note });
    },
    open: function (count) {
      this.build();
      this._sel = 0; this._people = [];
      byId('zml-sp-title').textContent = (count === 1) ? 'Share this item' : 'Share ' + count + ' items';
      byId('zml-sp-search').value = ''; byId('zml-sp-note').value = '';
      this.syncSend();
      var self = this;
      return new Promise(function (resolve) {
        self._resolve = resolve;
        byId('zml-shareprompt').style.display = '';
        self.load('');
        setTimeout(function () { var s = byId('zml-sp-search'); if (s) s.focus(); }, 30);
      });
    },
    finish: function (val) {
      var ov = byId('zml-shareprompt'); if (ov) ov.style.display = 'none';
      var r = this._resolve; this._resolve = null; if (r) r(val);
    }
  };

  // Open the share dialog for a set of media ids, then POST the share. The
  // media_ids go as a real array (media_ids[]), which the server reads and the
  // theme re-authorizes per item; the note + recipient ride along.
  // through the kit (media_ids as an array is sent as media_ids[], as before); `button`
  // (optional) shows busy while it runs.
  function openShareDialog(ids, button, doneCb) {
    if (!ids || !ids.length) return;
    SharePrompt.open(ids.length).then(function (pick) {
      if (!pick) return;
      busy(button, function () {
        return ajax('zml_share', { recipient_id: pick.recipientId, note: pick.note || '', media_ids: ids });
      }).then(function (data) {
        var n = (data && data.count) ? data.count : ids.length;
        var to = pick.recipientName || 'your teammate';
        say(n > 1 ? (n + ' items shared with ' + to) : ('Shared with ' + to), 'success');
        if (doneCb) doneCb();
      }).catch(function (err) { sayError(err); });
    });
  }

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
   * THE one delete path (bulk select bar AND the lightbox button).
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
      // the kit's busy button + request; a failure is the kit's sentence in a toast.
      return busy(opts.button || null, function () {
        return ajax('zml_delete', { media_ids: JSON.stringify(ids) });
      }).then(function (data) {
        var deleted = (data && data.deleted) || [];
        var failed  = (data && data.failed)  || [];
        if (deleted.length) {
          surface.removeItemsByIds(deleted);
          if (surface.selectMode) surface.exitSelectMode();
          refreshOtherSurface(surface);
        }
        if (failed.length) {
          say(failed.length + ' photo' + (failed.length === 1 ? '' : 's') + ' couldn’t be deleted: ' + String((failed[0] && failed[0].reason) || 'unknown reason'), 'error');
        } else if (deleted.length) {
          say(deleted.length === 1 ? 'Photo deleted' : deleted.length + ' photos deleted', 'success');
        }
        return { deleted: deleted, failed: failed };
      }).catch(function (err) {
        sayError(err);
        return null;
      });
    });
  }

  /* apply ONE tag to many selected photos via the batch endpoint.
   * Per-item owner-or-admin is enforced server-side; skipped items are reported. */
  function bulkTag(surface, ids, tag, button) {
    busy(button || null, function () {
      return ajax('zml_update_tags', {
        media_ids: JSON.stringify(ids),
        add: JSON.stringify([tag])
      });
    }).then(function (data) {
      var d = data || {};
      var n = d.count || 0;
      var failed = (d.failed || []).length;
      // Reflect the new tags on the in-memory items so re-opening a photo shows them.
      (d.tagged || []).forEach(function (t) {
        for (var i = 0; i < surface.items.length; i++) {
          if (surface.items[i] && surface.items[i].id === t.id) { surface.items[i].tags = t.tags; break; }
        }
      });
      if (surface.selectMode) surface.exitSelectMode();
      if (n) {
        say('Tagged ' + n + ' photo' + (n === 1 ? '' : 's') + ' “' + tag + '”' + (failed ? ' · ' + failed + ' skipped' : ''), 'success');
      } else {
        say(failed ? ('Nothing tagged · ' + failed + ' skipped') : 'Nothing tagged', 'error');
      }
    }).catch(function (err) { sayError(err); });
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
    metaOpen: false,      // meta sheet collapsed by default
    _hiRes: null,         // src the progressive paint is upgrading to
    _triedFallback: false,

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
          // "View full size" = real in-lightbox zoom (pinch + double-tap,
          // drag to pan), driven by a CSS transform on the image. Never navigates
          // the window (a <button>, not an <a href=file_url> — that stranded
          // tab-less desktop/PWA webviews on the raw file). "Back"/Esc/backdrop
          // still exit from any zoom level (close action unchanged — invariant
          // I3). Prev/next arrows are shown only at fit (hidden once zoomed in).
          '<button type="button" class="zml-lb-btn" id="zml-lb-open" aria-label="Zoom in" aria-pressed="false" title="Zoom in">' +
            '<svg class="zml-lb-open-in" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/><path d="M11 8v6"/><path d="M8 11h6"/></svg>' +
            '<svg class="zml-lb-open-out" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true" style="display:none;"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/><path d="M8 11h6"/></svg>' +
          '</button>' +
          '<button type="button" class="zml-lb-btn zml-lb-share" id="zml-lb-share" aria-label="Share to a teammate" title="Share to a teammate" style="display:none;">' +
            '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><line x1="8.6" y1="13.5" x2="15.4" y2="17.5"/><line x1="15.4" y1="6.5" x2="8.6" y2="10.5"/></svg>' +
          '</button>' +
          // rotate this photo 90°. Tap to
          // preview instantly; a Save/Reset bar appears over the stage.
          '<button type="button" class="zml-lb-btn zml-lb-rotate" id="zml-lb-rotate" aria-label="Rotate 90°" title="Rotate 90°" style="display:none;">' +
            '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>' +
          '</button>' +
          // draw on the photo without changing it (the theme's ZDZ.ui.marks
          // draws and saves). v2.13.0: anyone who sees the photo draws their own drawing, and the eye
          // is "Drawings" - who drew what, show / hide each, and your own sharing (the kit's list).
          '<button type="button" class="zml-lb-btn zml-lb-draw" id="zml-lb-draw" aria-label="Draw on this photo" title="Draw on this photo" style="display:none;">' +
            '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>' +
          '</button>' +
          '<button type="button" class="zml-lb-btn zml-lb-marks" id="zml-lb-marks" aria-label="Drawings on this photo" title="Drawings on this photo" style="display:none;">' +
            '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 5h18"/><path d="M3 12h18"/><path d="M3 19h12"/></svg>' +
            '<span class="zml-lb-marks-n" id="zml-lb-marks-n"></span>' +
          '</button>' +
        '</div>' +
        '<button type="button" class="zml-lb-nav zml-lb-prev" id="zml-lb-prev" aria-label="Previous">' +
          '<svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg></button>' +
        '<div class="zml-lb-stage" id="zml-lb-stage">' +
          '<img class="zml-lb-img" id="zml-lb-img" alt="" draggable="false" />' +
          // in-browser video player (gated, Range-served; no download).
          '<video class="zml-lb-video" id="zml-lb-video" playsinline webkit-playsinline controls preload="metadata" style="display:none"></video>' +
        '</div>' +
        // rotate action bar (over the stage foot); shown only while a
        // rotation is pending. Save commits (server re-encode); Reset undoes it.
        '<div class="zml-lb-rotate-bar" id="zml-lb-rotate-bar" style="display:none;">' +
          '<span class="zml-lb-rotate-hint" id="zml-lb-rotate-hint">Rotated 90°</span>' +
          '<button type="button" class="zml-lb-rotate-reset" id="zml-lb-rotate-reset">Reset</button>' +
          '<button type="button" class="zml-lb-rotate-save" id="zml-lb-rotate-save">Save</button>' +
        '</div>' +
        '<button type="button" class="zml-lb-nav zml-lb-next" id="zml-lb-next" aria-label="Next">' +
          '<svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg></button>' +
        // the meta panel is a COLLAPSED-BY-DEFAULT overlay sheet. It used
        // to be a normal-flow flex child ~370px tall, which is what crushed the
        // stage and pillarboxed every portrait photo. The summary line (source +
        // date) is now the tappable handle; everything else lives in the body.
        '<div class="zml-lb-meta" id="zml-lb-meta">' +
          '<button type="button" class="zml-lb-meta-toggle" id="zml-lb-meta-toggle" ' +
                  'aria-expanded="false" aria-controls="zml-lb-meta-body">' +
            '<span class="zml-lb-row" id="zml-lb-info"></span>' +
            // v2.10.3: the uploader's badge, at the right end of the summary line.
            '<span class="zml-lb-who" id="zml-lb-who"></span>' +
            '<svg class="zml-lb-meta-caret" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="18 15 12 9 6 15"/></svg>' +
          '</button>' +
          '<div class="zml-lb-meta-body" id="zml-lb-meta-body">' +

          // ── Location (tappable "Located" → reveals place/coords/provenance +
          //    user-tap "Open in Maps"). Populated by render() from the theme's
          //    GET /zorderz/v1/media/{id}/exif. Empty (display:none) when no GPS. ──
          '<div class="zml-lb-loc" id="zml-lb-loc" style="display:none;"></div>' +

          // ── Visibility control ──
          '<div class="zml-lb-vis" id="zml-lb-vis" style="display:none;">' +
            '<div class="zml-lb-vis-seg" role="group" aria-label="Who can see this">' +
              '<button type="button" class="zml-vis-btn" data-visible="me">Visible only to You</button>' +
              '<button type="button" class="zml-vis-btn" data-visible="everyone">Visible to Everyone</button>' +
            '</div>' +
            '<span class="zml-inline-status" id="zml-lb-vis-status" aria-live="polite"></span>' +
          '</div>' +

          // ── Note ──
          '<div class="zml-lb-note-wrap">' +
            '<label class="zml-lb-note-label" for="zml-lb-note">Note</label>' +
            '<textarea class="zml-lb-note" id="zml-lb-note" rows="2" placeholder="Add a note about this photo…" maxlength="2000"></textarea>' +
            '<div class="zml-lb-note-actions" id="zml-lb-note-actions">' +
              '<span class="zml-inline-status" id="zml-lb-note-status" aria-live="polite"></span>' +
              '<button type="button" class="zml-lb-save" id="zml-lb-save">Save note</button>' +
            '</div>' +
            '<p class="zml-lb-note-ro" id="zml-lb-note-ro" style="display:none;"></p>' +
          '</div>' +

          // ── v2.5.0: Tags (chips; owner OR admin can add & remove) ──
          '<div class="zml-lb-tags-wrap" id="zml-lb-tags-wrap" style="display:none;">' +
            '<label class="zml-lb-note-label" id="zml-lb-tags-label">Tags</label>' +
            '<div class="zml-lb-tags" id="zml-lb-tags"></div>' +
            '<div class="zml-lb-tag-add" id="zml-lb-tag-add" style="display:none;">' +
              '<input type="text" class="zml-lb-tag-input" id="zml-lb-tag-input" placeholder="Add a tag…" maxlength="60" autocomplete="off" autocapitalize="none" autocorrect="off" spellcheck="false" />' +
              '<button type="button" class="zml-lb-tag-btn" id="zml-lb-tag-btn">Add</button>' +
            '</div>' +
            '<span class="zml-inline-status" id="zml-lb-tags-status" aria-live="polite"></span>' +
          '</div>' +

          // ── v2.11.0: Belongs to (administrators only — hidden otherwise). The theme keeps who uploaded it. ──
          '<div class="zml-lb-owner" id="zml-lb-owner" style="display:none;">' +
            '<label class="zml-lb-note-label" for="zml-lb-owner-sel">Belongs to</label>' +
            '<div class="zml-lb-tag-add">' +
              '<select class="zml-lb-owner-sel" id="zml-lb-owner-sel" aria-label="Who this photo belongs to"></select>' +
              '<button type="button" class="zml-lb-tag-btn" id="zml-lb-owner-btn">Move</button>' +
            '</div>' +
            '<p class="zml-lb-owner-up" id="zml-lb-owner-up"></p>' +
          '</div>' +

          // ── v2.2.0: Delete. Goes
          //    through the same DOUBLE-confirmed performDelete() path as bulk. ──
          '<div class="zml-lb-danger" id="zml-lb-danger" style="display:none;">' +
            '<button type="button" class="zml-lb-del" id="zml-lb-del">' +
              '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>' +
              '<span>Delete photo</span>' +
            '</button>' +
          '</div>' +
          '</div>' +   // /.zml-lb-meta-body
        '</div>';
      document.body.appendChild(ov);

      byId('zml-lb-close').addEventListener('click', function () { Lightbox.close(); });
      byId('zml-lb-prev').addEventListener('click', function () { Lightbox.step(-1); });
      byId('zml-lb-next').addEventListener('click', function () { Lightbox.step(1); });
      byId('zml-lb-save').addEventListener('click', function () { Lightbox.saveNote(); });
      byId('zml-lb-del').addEventListener('click', function () { Lightbox.deleteCurrent(); });   // v2.2.0
      // tag add (button + Enter); per-chip removal is bound in renderTags().
      byId('zml-lb-tag-btn').addEventListener('click', function () { Lightbox.addTagFromInput(); });
      byId('zml-lb-tag-input').addEventListener('keydown', function (e) {
        if (e.key === 'Enter') { e.preventDefault(); Lightbox.addTagFromInput(); }
      });
      // button does a point-less step zoom (fit ⇄ 2× centered); the
      // stage hosts the real gesture zoom (pinch / double-tap / drag-pan).
      byId('zml-lb-open').addEventListener('click', function () { Lightbox.buttonZoom(); });
      var _shb = byId('zml-lb-share'); if (_shb) _shb.addEventListener('click', function () { Lightbox.shareCurrent(); });
      var _rob = byId('zml-lb-rotate');       if (_rob) _rob.addEventListener('click', function () { Lightbox.rotateStep(); });   // v2.10.0
      var _ror = byId('zml-lb-rotate-reset'); if (_ror) _ror.addEventListener('click', function () { Lightbox.rotateReset(); });
      var _ros = byId('zml-lb-rotate-save');  if (_ros) _ros.addEventListener('click', function () { Lightbox.rotateSave(); });
      var _drb = byId('zml-lb-draw');  if (_drb) _drb.addEventListener('click', function () { Lightbox.drawStart(); });     // v2.12.0
      var _mkb = byId('zml-lb-marks'); if (_mkb) _mkb.addEventListener('click', function () { Lightbox.marksPanel(this); });  // Drawings
      byId('zml-lb-meta-toggle').addEventListener('click', function () { Lightbox.toggleMeta(); });
      this.bindZoomGestures(byId('zml-lb-stage'));

      // the image had NO error handler. A HEIC original -- which is what
      // an iPhone uploads, untouched, by design -- cannot be decoded by Chrome,
      // Firefox, Edge or Android, so the stage rendered EMPTY BLACK with no way
      // to tell that apart from "still loading". Fall back to the JPEG
      // derivative, then to an honest message.
      var img = byId('zml-lb-img');
      img.addEventListener('error', function () { Lightbox._onImgError(); });
      // clampPan() reads img.clientWidth live, so after any src change it would
      // clamp against the PREVIOUS photo's dimensions until the next transform.
      img.addEventListener('load', function () { Lightbox._onImgLoad(); });

      // Nothing re-evaluated the pan clamp on rotate or resize, so a zoomed photo
      // could sit outside the stage after an orientation change.
      var reflow = function () {
        var lb = byId('zml-lb');
        if (lb && lb.style.display !== 'none') Lightbox.applyTransform(false);
      };
      window.addEventListener('resize', reflow);
      window.addEventListener('orientationchange', reflow);

      // iOS keyboard inset. The sheet is position:absolute inside a fixed
      // overlay, so the software keyboard would otherwise slide up OVER the note
      // field the user just tapped into. visualViewport is the only reliable way
      // to learn the keyboard height; --zml-kb feeds the sheet's transform.
      if (window.visualViewport) {
        var vv = window.visualViewport;
        var onVV = function () {
          var lb = byId('zml-lb'); if (!lb || lb.style.display === 'none') return;
          var inset = Math.max(0, window.innerHeight - vv.height - vv.offsetTop);
          lb.style.setProperty('--zml-kb', Math.round(inset) + 'px');
        };
        vv.addEventListener('resize', onVV);
        vv.addEventListener('scroll', onVV);
      }

      // Backdrop / empty stage closes — but NOT while zoomed in (you're panning),
      // and NOT if this "click" was the tail of a drag (see _movedWhilePanning).
      ov.addEventListener('click', function (e) {
        if (Lightbox.scale > Lightbox.fitScale + 0.01) return; // zoomed → ignore
        if (Lightbox._movedWhilePanning) { Lightbox._movedWhilePanning = false; return; }
        if (e.target !== ov && e.target.id !== 'zml-lb-stage') return;
        // with the meta sheet open, a tap on the photo means "put the
        // sheet away", not "leave the viewer". Closing the whole lightbox when
        // someone dismisses a panel would be the wrong-feeling kind of surprise.
        if (Lightbox.metaOpen) { Lightbox.toggleMeta(false); return; }
        Lightbox.close();
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

      // an administrator moves the photo to its rightful owner.
      byId('zml-lb-owner-btn').addEventListener('click', function () { Lightbox.saveOwner(); });

      this.built = true;
    },

    current: function () {
      var s = this.surface;
      return (s && this.index >= 0 && this.index < s.items.length) ? s.items[this.index] : null;
    },

    /* -- v2.4.0: the meta sheet ------------------------------------------
     * Collapsed, it is a slim summary bar. Expanded, it slides up over the
     * photo, capped and scrollable. `inert` is what actually removes the
     * collapsed contents from the tab order and the accessibility tree --
     * max-height:0 with overflow:hidden hides them visually but leaves the note
     * textarea and every button keyboard-reachable, which is a real a11y bug,
     * not a cosmetic one. inert is supported in Safari 17+, and the aria-hidden
     * line keeps older engines honest.
     */
    toggleMeta: function (force) {
      var ov = byId('zml-lb'), body = byId('zml-lb-meta-body'),
          btn = byId('zml-lb-meta-toggle');
      if (!ov || !body || !btn) return;
      var open = (typeof force === 'boolean') ? force : !this.metaOpen;
      this.metaOpen = open;
      ov.classList.toggle('zml-meta-open', open);
      btn.setAttribute('aria-expanded', open ? 'true' : 'false');
      if (open) { body.removeAttribute('inert'); body.removeAttribute('aria-hidden'); }
      else {
        // Never strand focus inside a panel we are about to make inert.
        if (body.contains(document.activeElement)) btn.focus();
        body.setAttribute('inert', '');
        body.setAttribute('aria-hidden', 'true');
      }
    },

    /* -- v2.4.0: image load / error --------------------------------------- */
    _onImgLoad: function () {
      // Re-clamp against the newly loaded photo's real dimensions.
      this.applyTransform(false);
      this._clearFallback();
      // Progressive paint: the thumbnail is up, now pull the full-resolution
      // original in behind it. Guarded on index so a slow original arriving
      // after the user has already stepped away can never clobber the new photo.
      var want = this._hiRes;
      if (!want) return;
      var img = byId('zml-lb-img');
      if (!img || img.getAttribute('src') === want) { this._hiRes = null; return; }
      var idxAtStart = this.index, L = this;
      var pre = new Image();
      pre.onload = function () {
        if (L.index !== idxAtStart) return;          // user moved on
        var cur = byId('zml-lb-img'); if (!cur) return;
        cur.src = want;
        L._hiRes = null;
      };
      pre.onerror = function () { L._hiRes = null; }; // keep the thumbnail
      pre.src = want;
    },

    _onImgError: function () {
      var it = this.current(); if (!it) return;
      var img = byId('zml-lb-img'); if (!img) return;
      // One retry against the derivative, then say something true.
      if (!this._triedFallback && it.thumbnail_url && img.getAttribute('src') !== it.thumbnail_url) {
        this._triedFallback = true;
        this._hiRes = null;
        img.src = it.thumbnail_url;
        return;
      }
      this._showFallback();
    },

    _showFallback: function () {
      var stage = byId('zml-lb-stage'); if (!stage) return;
      this._clearFallback();
      var box = document.createElement('div');
      box.className = 'zml-lb-fallback';
      box.id = 'zml-lb-fallback';
      var strong = document.createElement('strong');
      strong.textContent = 'Preview not available';
      var p = document.createElement('span');
      p.textContent = 'This browser cannot display the original file format. ' +
                      'Safari on iPhone or Mac will show it.';
      box.appendChild(strong); box.appendChild(p);
      stage.appendChild(box);
      var img = byId('zml-lb-img'); if (img) img.style.visibility = 'hidden';
    },

    _clearFallback: function () {
      var old = byId('zml-lb-fallback'); if (old && old.parentNode) old.parentNode.removeChild(old);
      var img = byId('zml-lb-img'); if (img) img.style.visibility = '';
    },

    open: function (surface, pos) {
      this.build();
      this.surface = surface;
      this.index = pos;
      // remember exactly where the gallery was scrolled, so closing the
      // viewer returns there instead of the top. (Closing goes through
      // history.back(), and the theme SPA resets the app's scroller to 0 on that
      // popstate — we restore it in teardown, with retries to beat that reset.)
      this._savedScroll = this._readScroll();
      this.lastFocus = document.activeElement;
      this.noteDirty = false;

      var ov = byId('zml-lb');
      ov.style.display = '';
      document.body.classList.add('zml-lb-open');
      this.toggleMeta(false);        // always open onto the photo, not the panel

      // History entry so phone/browser back closes the viewer.
      try { history.pushState({ zmlLB: 1 }, ''); this.hist = true; } catch (e) { this.hist = false; }

      this.render();
      var c = byId('zml-lb-close'); if (c) c.focus();
      document.addEventListener('keydown', this._onKey);
    },

    teardown: function () {
      var ov = byId('zml-lb'); if (!ov) return;
      ov.style.display = 'none';
      // stop any playing clip so audio never continues under the grid.
      var vidEl = byId('zml-lb-video');
      if (vidEl) { try { vidEl.pause(); } catch (e) {} vidEl.removeAttribute('src'); vidEl.onended = null; vidEl.style.display = 'none'; }
      this.resetZoom(false); // clear zoom transform on close
      var _mi = byId('zml-lb-img'); if (_mi && _mi._zdzMarks) _mi._zdzMarks.cancel();   // a drawing in progress ends with the viewer
      this.toggleMeta(false);
      this._clearFallback();
      ov.style.removeProperty('--zml-kb');
      document.body.classList.remove('zml-lb-open');
      this.hist = false;
      document.removeEventListener('keydown', this._onKey);
      if (this.lastFocus && typeof this.lastFocus.focus === 'function') this.lastFocus.focus();
      this._restoreScroll();   // return the gallery to where the user was
    },

    close: function () {
      if (byId('zml-lb') && byId('zml-lb').style.display === 'none') return;
      if (this.hist) { history.back(); } // popstate → teardown
      else { this.teardown(); }
    },

    // preserve the gallery scroll across the viewer. The fullscreen app
    // scrolls inside #zml-fs-scroll (surface.scrollEl); the widget/dashboard and
    // "openItems" callers scroll the window. Read on open, restore on teardown.
    _readScroll: function () {
      var s = this.surface;
      if (s && s.scrollEl) { return { el: s.scrollEl, top: s.scrollEl.scrollTop || 0 }; }
      return { el: null, top: (window.scrollY || document.documentElement.scrollTop || 0) };
    },
    _restoreScroll: function () {
      var saved = this._savedScroll; if (!saved) return;
      var apply = function () {
        if (saved.el) { try { saved.el.scrollTop = saved.top; } catch (e) {} }
        else { try { window.scrollTo(0, saved.top); } catch (e2) {} }
      };
      apply();                                             // now
      if (window.requestAnimationFrame) requestAnimationFrame(apply);
      setTimeout(apply, 60);
      setTimeout(apply, 220);                              // beat the SPA's async scroll-to-top
    },

    // when a reel clip ends, auto-advance to the next clip in the SAME
    // reel (seq+1) so a multi-clip video plays back-to-back (the Shorts feel).
    // Grid order may be newest-first, so we match by reel_id+seq, not position.
    _wireReelAdvance: function (vidEl, it) {
      var L = this;
      vidEl.onended = function () {
        if (!it || !it.reel_id) return;
        var items = (L.surface && L.surface.items) || [];
        for (var i = 0; i < items.length; i++) {
          var n = items[i];
          if (n && n.reel_id === it.reel_id && (n.media_type === 'video' || n.is_video) && Number(n.seq) === (Number(it.seq) + 1)) {
            L.index = i; L.render();
            var nv = byId('zml-lb-video'); if (nv) { try { nv.play(); } catch (e) {} }
            return;
          }
        }
      };
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
      // while a rotation preview is pending, the image shows rotate()+a
      // fit-scale instead of the zoom transform (zoom is neutralized until the
      // rotation is saved or reset). Any reflow re-applies the preview.
      if (this.pendingCW) {
        var d = this.pendingCW, rs = this._rotFitScale(d);
        img.style.transition = animate ? 'transform 0.18s ease' : 'none';
        img.style.transform = 'rotate(' + d + 'deg) scale(' + rs + ')';
        return;
      }
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
      // hiding the arrows while zoomed is now purely CSS (opacity +
      // pointer-events on .zml-zoomed), which lets the same mechanism hide the
      // top bar and the meta sheet too. Setting style.display here as well would
      // fight that, and the old `display:none !important` fallback could not
      // coexist with any second reason to hide chrome. The :disabled rule still
      // handles the ends of the list.
      if (zoomedIn && this.metaOpen) this.toggleMeta(false);
    },

    // one implementation of "toggle zoom at this point", shared by the
    // desktop dblclick path and the touch double-tap path.
    _toggleZoomAt: function (clientX, clientY) {
      var f = this._focalFromClient(clientX, clientY);
      if (this.scale > this.fitScale + 0.01) this.resetZoom(true);
      else this.zoomTo(2, f.x, f.y, true);
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
        if (isVideo(L.current())) return;   // video has its own controls
        // Pinch-zoom on macOS trackpads arrives as wheel with ctrlKey.
        if (!e.ctrlKey && Math.abs(e.deltaY) < 1) return;
        e.preventDefault();
        var f = L._focalFromClient(e.clientX, e.clientY);
        var factor = Math.exp(-e.deltaY * (e.ctrlKey ? 0.01 : 0.0015));
        L.zoomTo(L.scale * factor, f.x, f.y, false);
      }, { passive: false });

      // Double-click toggles 2x at the point. On touch this fired only via a
      // SYNTHESISED dblclick, which touch-action:none on this stage suppresses in
      // several mobile engines -- so double-tapping a photo on a phone, the
      // universal idiom, often did nothing at all. _lastTapAt guards against
      // handling the same gesture twice where the engine does synthesise one.
      stage.addEventListener('dblclick', function (e) {
        if (isVideo(L.current())) return;   // v2.8.0
        if (Date.now() - (L._lastTapAt || 0) < 500) return;   // touch path handled it
        e.preventDefault();
        L._toggleZoomAt(e.clientX, e.clientY);
      });

      // Mouse drag to pan (only meaningful when zoomed).
      var dragging = false, lastX = 0, lastY = 0;
      stage.addEventListener('mousedown', function (e) {
        if (isVideo(L.current())) return;   // v2.8.0
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
        if (isVideo(L.current())) return;   // let the video controls own touch
        if (e.touches.length === 2) {
          pinchStartDist = dist(e.touches); pinchStartScale = L.scale;
          e.preventDefault();
        } else if (e.touches.length === 1 && L.scale > L.fitScale + 0.01) {
          tPanning = true; tLastX = e.touches[0].clientX; tLastY = e.touches[0].clientY;
          L._movedWhilePanning = false;
        }
      }, { passive: false });
      stage.addEventListener('touchmove', function (e) {
        if (isVideo(L.current())) return;   // v2.8.0
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
      // Manual double-tap detection. Implemented HERE, in the same handler that
      // owns the touch lifecycle, rather than as a second racing source of truth.
      var lastTapT = 0, lastTapX = 0, lastTapY = 0;
      var DOUBLE_MS = 300, DOUBLE_SLOP = 32;
      stage.addEventListener('touchend', function (e) {
        if (isVideo(L.current())) return;   // v2.8.0
        if (e.touches.length < 2) pinchStartDist = 0;
        if (e.touches.length === 0) tPanning = false;

        if (e.touches.length !== 0 || e.changedTouches.length !== 1) return;
        if (L._movedWhilePanning) return;               // that was a pan, not a tap
        var t = e.changedTouches[0];
        // Only on the photo itself. A tap on the surrounding black is the
        // existing "close the viewer" affordance and must stay that way.
        if (e.target && e.target.id !== 'zml-lb-img' && L.scale <= L.fitScale + 0.01) {
          lastTapT = 0; return;
        }
        var now = Date.now();
        if (now - lastTapT < DOUBLE_MS &&
            Math.abs(t.clientX - lastTapX) < DOUBLE_SLOP &&
            Math.abs(t.clientY - lastTapY) < DOUBLE_SLOP) {
          e.preventDefault();                            // suppress the synthesised click
          L._lastTapAt = now;
          lastTapT = 0;
          L._toggleZoomAt(t.clientX, t.clientY);
          return;
        }
        lastTapT = now; lastTapX = t.clientX; lastTapY = t.clientY;
      }, { passive: false });
    },

    shareCurrent: function () {
      var it = this.current(); if (!it || !it.can_share) return;
      openShareDialog([ it.id ], byId('zml-lb-share'));
    },

    // ── v2.10.0: rotate a photo 90° at a time — instant local preview, one save ──
    // Owner/admin, photos only. Each tap adds 90° CW to a preview transform (see
    // applyTransform); Save re-encodes the file + thumbnails server-side (the new
    // official orientation, correct everywhere) and swaps in the fresh image.
    pendingCW: 0,
    _rotFitScale: function (deg) {
      if (deg !== 90 && deg !== 270) return 1;   // 180 keeps the same bounding box
      var img = byId('zml-lb-img'), stage = byId('zml-lb-stage');
      if (!img || !stage) return 1;
      var nw = img.naturalWidth || 0, nh = img.naturalHeight || 0;
      var sw = stage.clientWidth || 1, sh = stage.clientHeight || 1;
      if (nw <= 0 || nh <= 0) return 1;
      var fit = Math.min(sw / nw, sh / nh);       // object-fit:contain displayed size
      var dw = nw * fit, dh = nh * fit;
      if (dw <= 0 || dh <= 0) return 1;
      return Math.min(sw / dh, sh / dw);          // scale so the rotated (dh×dw) box fits
    },
    rotateStep: function () {
      var it = this.current(); if (!it || !it.can_rotate || isVideo(it)) return;
      this.resetZoom(false);                      // preview from fit (no pan/zoom)
      this.pendingCW = ((this.pendingCW || 0) + 90) % 360;
      this.applyTransform(true);                  // guard renders the rotate preview
      this._syncRotateBar();
    },
    rotateReset: function () {
      this.pendingCW = 0;
      this.applyTransform(true);                  // back to the normal fit
      this._syncRotateBar();
    },
    _syncRotateBar: function () {
      var bar = byId('zml-lb-rotate-bar'), hint = byId('zml-lb-rotate-hint');
      if (!bar) return;
      var d = this.pendingCW || 0;
      bar.style.display = d ? '' : 'none';
      if (hint) hint.textContent = 'Rotated ' + d + '°';
    },
    rotateSave: function () {
      var it = this.current(); if (!it) return;
      var d = this.pendingCW || 0;
      if (!d) { this._syncRotateBar(); return; }
      var save = byId('zml-lb-rotate-save');
      var L = this, id = it.id;
      // the kit's busy Save + request; a failure is the kit's sentence in a toast.
      busy(save, function () { return ajax('zml_rotate', { media_id: id, cw: d }); }).then(function (data) {
        data = data || {};
        if (data.file_url)      it.file_url = data.file_url;            // fresh, cache-busted
        if (data.thumbnail_url) it.thumbnail_url = data.thumbnail_url;
        it.thumbnail_2x_url = data.thumbnail_2x_url || '';   // never keep the pre-rotate 2x
        if ('marks_n' in data) { it.marks_n = data.marks_n | 0; it.notes_n = data.notes_n | 0; }   // the theme turned every drawing; renderMarks reads them again
        // The viewer may have moved to another photo while this saved - only the photo still shown is
        // swapped and has its drawings read again (the grid cell below is updated either way).
        if (L.current() === it) {
          L.pendingCW = 0;
          L._syncRotateBar();
          // Swap the lightbox to the correctly-oriented image (normal pipeline re-fits).
          L._hiRes = null;
          var img = byId('zml-lb-img');
          if (img && it.file_url) { img.style.transform = ''; img.src = it.file_url; }
          L.applyTransform(false);
          L.renderMarks(it);
        }
        // Refresh any visible grid cell for this item so it's correct there too.
        if (data.thumbnail_url) {
          var cellImg = document.querySelector('.zml-cell[data-id="' + id + '"] .zml-cell-img');
          if (cellImg) {
            // the srcset would otherwise keep showing the pre-rotate 2x image.
            if (it.thumbnail_2x_url) cellImg.setAttribute('srcset', it.thumbnail_url + ' 1x, ' + it.thumbnail_2x_url + ' 2x');
            else cellImg.removeAttribute('srcset');
            cellImg.src = data.thumbnail_url;
          }
        }
        say('Rotation saved', 'success');
      }).catch(function (err) { sayError(err); });
    },

    // ── v2.12.0: drawings on a photo, apart from the photo ───────────────────
    // The theme's kit draws them over the image and follows the zoom, the pan and the rotate preview by
    // itself (ZDZ.ui.marks); on an older theme there is no layer and both buttons stay hidden.
    // anyone who sees a photo draws on it, each person's drawing is
    // their own (private until they share it), an arrow can end in a Post-it note. The viewer loads the
    // drawings THIS person sees when the photo opens; "Drawings" lists them (the kit's panel).
    _marksLayer: function () {
      var img = byId('zml-lb-img');
      if (!img || !window.ZDZ || !ZDZ.ui || !ZDZ.ui.marks || !ZDZ.ui.marks.panel) return null;
      var layer = ZDZ.ui.marks.attach(img), L = this;
      if (layer && !layer._zml) {
        layer._zml = true;
        layer.on(function () { L.syncMarksToggle(); });   // a load, a save, a show / hide
      }
      return layer;
    },
    renderMarks: function (it) {
      var layer = this._marksLayer();
      var video = !it || isVideo(it);
      if (layer) {
        layer.hide(false);
        if (video) { layer.set(null); }
        else {
          var L = this, got = layer.load(it.id);
          if (got && got.then) got.then(function (v) { if (v && layer.photoId === (it.id | 0)) L._marksCounted(it, layer); });
        }
      }
      this.syncMarksToggle();
    },
    /* Counted from the saved view itself, and only for its own photo (the viewer may have moved on) */
    _viewCounted: function (it, view) {
      if (!view || (view.id | 0) !== (it.id | 0)) return;
      var n = 0, notes = 0;
      (view.layers || []).forEach(function (l) { n++; (l.strokes || []).forEach(function (s) { if (s.n) notes++; }); });
      it.marks_n = n; it.notes_n = notes;
      if (this.surface && this.surface.refreshCell) this.surface.refreshCell(it);
    },
    /* what the layer shows now, on the item and its tile (its outline and badge) */
    _marksCounted: function (it, layer) {
      it.marks_n = layer.count();
      it.notes_n = layer.notes();
      if (this.surface && this.surface.refreshCell) this.surface.refreshCell(it);
    },
    syncMarksToggle: function () {
      var it = this.current(), layer = this._marksLayer(), b = byId('zml-lb-marks'), drb = byId('zml-lb-draw');
      var video = !it || isVideo(it);
      var here = !!(layer && it && layer.photoId === (it.id | 0));
      if (drb) {
        drb.style.display = (layer && !video && (it.can_mark || (here && layer.canMark()))) ? '' : 'none';
        var dl = here && layer.mine() ? 'Change your drawing' : 'Draw on this photo';
        drb.setAttribute('aria-label', dl); drb.setAttribute('title', dl);
      }
      if (!b) return;
      var n = here ? layer.count() : 0, v = here ? (layer.view || {}) : {};
      b.style.display = (layer && !video && (n || (it.marks_n | 0) || (v.is_admin && (v.hidden_n | 0)))) ? '' : 'none';
      var lbl = 'Drawings on this photo' + (n ? ' (' + n + ')' : '');
      b.setAttribute('aria-label', lbl); b.setAttribute('title', lbl);
      var nn = byId('zml-lb-marks-n'); if (nn) nn.textContent = n ? String(n) : '';
    },
    marksPanel: function (anchor) {
      var layer = this._marksLayer(), L = this;
      if (!layer || this.pendingCW) return;
      ZDZ.ui.marks.panel(layer, { anchor: anchor || null, onDraw: function () { L.drawStart(); } });
    },
    drawStart: function () {
      var it = this.current(), layer = this._marksLayer();
      if (!it || !layer || isVideo(it) || this.pendingCW) return;
      if (!it.can_mark && !(layer.photoId === (it.id | 0) && layer.canMark())) return;
      this.resetZoom(false);          // draw on the whole photo, at fit
      this.toggleMeta(false);
      layer.hide(false);
      var L = this;
      layer.edit({ id: it.id,
        done: function (view, saved) {
          if (saved) L._viewCounted(it, view);   // the item and its tile, so paging back and the grid agree
          L.syncMarksToggle();
        },
        // someone else saved first: the bar shows what is there now
        refresh: function (view) { L._viewCounted(it, view); }
      });
    },

    render: function () {
      var it = this.current(); if (!it) return;

      // v2.4.0 PROGRESSIVE PAINT. Until now the lightbox went straight at the
      // full-resolution original -- a 4-8MB iPhone file, streamed by PHP with no
      // range support -- so every open and every arrow press stared at an empty
      // stage until the whole thing arrived. Paint the already-cached thumbnail
      // first, then swap the original in behind it once it has decoded. The
      // thumbnail is already in the payload, so this costs no server change.
      var img = byId('zml-lb-img');
      var vidEl = byId('zml-lb-video');
      byId('zml-lb-title').textContent = it.title || sourceBadge(it);
      var _sb = byId('zml-lb-share'); if (_sb) { _sb.style.display = it.can_share ? '' : 'none'; }
      // rotate is photos-only + owner/admin; reset any pending preview
      // when the item changes so a rotation never carries to the next photo.
      var _ro = byId('zml-lb-rotate'); if (_ro) { _ro.style.display = ( it.can_rotate && ! isVideo(it) ) ? '' : 'none'; }
      this.pendingCW = 0;
      this._syncRotateBar();
      this._triedFallback = false;
      this._clearFallback();

      if (isVideo(it)) {
        // ── Video: stream it inline (gated, Range-served) — never a download ──
        if (img) { img.style.display = 'none'; img.removeAttribute('src'); }
        this._hiRes = null;
        if (vidEl) {
          vidEl.style.display = '';
          try { vidEl.pause(); } catch (e) {}
          if (it.thumbnail_url && it.thumbnail_url !== it.file_url) vidEl.setAttribute('poster', it.thumbnail_url);
          else vidEl.removeAttribute('poster');
          if (vidEl.getAttribute('src') !== it.file_url) { vidEl.setAttribute('src', it.file_url); try { vidEl.load(); } catch (e2) {} }
          this._wireReelAdvance(vidEl, it);
        }
        this.resetZoom(false);
      } else {
        if (vidEl) { try { vidEl.pause(); } catch (e) {} vidEl.removeAttribute('src'); vidEl.onended = null; vidEl.style.display = 'none'; }
        if (img) img.style.display = '';
        // v2.4.0 PROGRESSIVE PAINT: thumbnail first, full-res swapped in on decode.
        if (it.thumbnail_url && it.thumbnail_url !== it.file_url) {
          this._hiRes = it.file_url;
          img.src = it.thumbnail_url;
        } else {
          this._hiRes = null;
          img.src = it.file_url;
        }
        img.alt = it.title || 'Media';
        // each photo (open / prev / next) starts at fit-to-screen.
        this.resetZoom(false);
      }
      this.renderMarks(it);   // v2.12.0 / v2.13.0: the drawings this person sees on it (a video has none)

      // Info row: source · captured/created date. (Location is no longer a
      // dead text label here — it's an interactive control rendered below.)
      var bits = [ sourceBadge(it) ];
      var when = it.captured_at || it.created_at;
      if (when) bits.push(prettyDate(when));
      byId('zml-lb-info').textContent = bits.join('  ·  ');
      var whoEl = byId('zml-lb-who');
      if (whoEl) { whoEl.innerHTML = whoBadge(it, 'zml-lb-who-in'); whoEl.hidden = !whoEl.innerHTML; }
      if (it.owner_initials) {   // moved to someone else - say whose it is now, beside who uploaded it
        byId('zml-lb-info').textContent += '  ·  Belongs to ' + it.owner_initials;
      }

      // ── Location: a tappable "Located" affordance when the photo is geotagged
      // ──
      this.renderLocation(it);

      // ── Visibility control ──
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

      // ── v2.2.0: Delete affordance ──
      var danger = byId('zml-lb-danger');
      if (danger) danger.style.display = it.can_delete ? '' : 'none';

      // ── v2.5.0: keyword tags (chips + add/remove for owner or admin) ──
      this.renderTags();

      // ── v2.11.0: Belongs to (administrators) ──
      this.renderOwner(it);

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
      host.innerHTML = loaderHtml('Finding location…');   // the kit's loader

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
    // through the kit's ZDZ.ui.rest (its base URL and X-WP-Nonce are the theme's zdzData).
    _exifCache: {},
    fetchExif: function (mediaId) {
      if (this._exifCache[mediaId]) return this._exifCache[mediaId];

      var p = (ui()
        ? ZDZ.ui.rest('zorderz/v1/media/' + encodeURIComponent(mediaId) + '/exif', { headers: { 'Accept': 'application/json' } })
        : Promise.reject(new Error(NEEDS_KIT))
      ).then(function (resp) {
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

      // No-shift busy state (v2.10.3: the kit's busy(), which keeps the button's box
      // and takes one press at a time); the status slot says what is happening.
      if (status) { status.textContent = 'Saving…'; status.classList.remove('is-error'); }

      busy(btn, function () { return ajax('zml_save_note', { media_id: it.id, note: note }); }).then(function (data) {
        it.note = (data && typeof data.note === 'string') ? data.note : note;
        self.noteDirty = false;
        btn.disabled = true;                 // nothing new to save now
        if (status) status.textContent = 'Saved \u2713';
        self.surface.refreshCell(it);
        setTimeout(function () { if (status) status.textContent = ''; }, 2000);
      }).catch(function (err) {
        btn.disabled = false;
        // The kit's readable sentence (or the server's own words), in the same status slot.
        if (status) { status.textContent = (err && err.message) || 'Save failed'; status.classList.add('is-error'); }
      });
    },

    // ── v2.5.0: keyword tags ────────────────────────────────────────────────
    renderTags: function () {
      var it = this.current(); if (!it) return;
      var wrap = byId('zml-lb-tags-wrap');
      var box  = byId('zml-lb-tags');
      var add  = byId('zml-lb-tag-add');
      var status = byId('zml-lb-tags-status');
      if (!wrap || !box) return;
      var tags = (it.tags && it.tags.length) ? it.tags : [];
      var canTag = !!it.can_tag;
      if (!tags.length && !canTag) { wrap.style.display = 'none'; return; }
      wrap.style.display = '';
      if (status) status.textContent = '';
      box.innerHTML = '';
      tags.forEach(function (t) {
        var chip = document.createElement('span');
        chip.className = 'zml-tag' + (canTag ? ' zml-tag-editable' : '');
        var label = document.createElement('span');
        label.className = 'zml-tag-label';
        label.textContent = t;                     // textContent — tags are user data
        chip.appendChild(label);
        if (canTag) {
          var x = document.createElement('button');
          x.type = 'button';
          x.className = 'zml-tag-x';
          x.setAttribute('aria-label', 'Remove tag ' + t);
          x.textContent = '×';
          x.addEventListener('click', function () { Lightbox.removeTag(t); });
          chip.appendChild(x);
        }
        box.appendChild(chip);
      });
      if (!tags.length) {
        var empty = document.createElement('span');
        empty.className = 'zml-tag-empty';
        empty.textContent = 'No tags yet';
        box.appendChild(empty);
      }
      if (add) add.style.display = canTag ? '' : 'none';
    },

    addTagFromInput: function () {
      var input = byId('zml-lb-tag-input');
      if (!input) return;
      var val = (input.value || '').trim();
      if (!val) return;
      input.value = '';
      var parts = val.split(',').map(function (s) { return s.trim(); }).filter(Boolean);
      this.applyTagChange(parts, []);
    },

    removeTag: function (tag) {
      this.applyTagChange([], [tag]);
    },

    applyTagChange: function (add, remove) {
      var it = this.current(); if (!it || !it.can_tag) return;
      var self = this;
      var status = byId('zml-lb-tags-status');
      var btn = byId('zml-lb-tag-btn');
      if (status) { status.textContent = 'Saving…'; status.classList.remove('is-error'); }
      busy(btn, function () {
        return ajax('zml_update_tags', {
          media_id: it.id,
          add: JSON.stringify(add || []),
          remove: JSON.stringify(remove || [])
        });
      }).then(function (data) {
        it.tags = (data && data.tags) || [];
        self.renderTags();
        if (self.surface && self.surface.refreshCell) self.surface.refreshCell(it);
        if (status) { status.textContent = 'Saved ✓'; setTimeout(function () { if (status) status.textContent = ''; }, 1800); }
      }).catch(function (err) {
        if (status) { status.textContent = (err && err.message) || 'Could not save tags'; status.classList.add('is-error'); }
      });
    },

    /* "Belongs to": an administrator picks the person and taps Move. The list of
       people is read once, the first time an administrator opens a photo (zml_owner_people). */
    renderOwner: function (it) {
      var wrap = byId('zml-lb-owner');
      if (!wrap) return;
      if (!it || !it.can_move) { wrap.style.display = 'none'; return; }
      wrap.style.display = '';
      var up = byId('zml-lb-owner-up');
      if (up) up.textContent = it.owner_initials && it.uploader_initials ? 'Uploaded by ' + it.uploader_initials + '.' : '';
      var L = this, sel = byId('zml-lb-owner-sel');
      var fill = function () {
        var list = L._people || [];
        sel.innerHTML = (it.owner_id ? '' : '<option value="">Choose a person…</option>') + list.map(function (p) {
          return '<option value="' + escAttr(String(p.id)) + '"' + (+p.id === +it.owner_id ? ' selected' : '') + '>' + esc(p.name) + '</option>';
        }).join('');
        if (it.owner_id && !list.some(function (p) { return +p.id === +it.owner_id; })) {
          sel.insertAdjacentHTML('afterbegin', '<option value="' + escAttr(String(it.owner_id)) + '" selected>' + esc(it.owner_initials || it.uploader_initials || 'Current owner') + '</option>');
        }
      };
      if (this._people) { fill(); return; }
      sel.innerHTML = '<option value="">Loading…</option>';
      if (this._peopleLoading) return;
      this._peopleLoading = true;
      ajax('zml_owner_people', {}).then(function (data) {
        L._people = (data && data.people) || [];
        L._peopleLoading = false;
        var cur = L.current();
        if (cur && cur.can_move) L.renderOwner(cur);
      }).catch(function (err) { L._peopleLoading = false; sel.innerHTML = '<option value="">Could not load the people</option>'; sayError(err); });
    },

    saveOwner: function () {
      var it = this.current(); if (!it || !it.can_move) return;
      var sel = byId('zml-lb-owner-sel'), to = sel ? parseInt(sel.value, 10) || 0 : 0;
      if (!to) { say('Choose who it belongs to first.', 'error'); return; }
      if (to === +it.owner_id) { say('It already belongs to them.', 'info'); return; }
      var L = this, id = it.id;
      busy(byId('zml-lb-owner-btn'), function () { return ajax('zml_set_owner', { media_id: id, user_id: to }); }).then(function (data) {
        var fresh = data && data.item;
        if (fresh) {
          Object.keys(fresh).forEach(function (k) { it[k] = fresh[k]; });
        }
        // The grid tile's badge: always the person who uploaded it (unchanged by a move).
        if (L.current() === it) L.render();
        say('Moved', 'success');
      }).catch(function (err) { sayError(err); });
    },

    saveVisibility: function (visible) {
      var it = this.current(); if (!it || !it.is_owner) return;
      var targetPrivacy = (visible === 'everyone') ? 'public' : 'private';
      if (it.privacy === targetPrivacy) return;  // already there — no-op

      var self = this;
      var wrap = byId('zml-lb-vis');
      var status = byId('zml-lb-vis-status');
      var btns = wrap.querySelectorAll('.zml-vis-btn');

      // the tapped button shows the kit's busy state; the other one waits.
      var tapped = null;
      btns.forEach(function (b) { if (b.dataset.visible === visible) tapped = b; else b.disabled = true; });
      wrap.classList.add('zml-busy');
      if (status) { status.textContent = 'Updating…'; status.classList.remove('is-error'); }

      busy(tapped, function () { return ajax('zml_save_visibility', { media_id: it.id, visible: visible }); }).then(function (data) {
        btns.forEach(function (b) { b.disabled = false; });
        wrap.classList.remove('zml-busy');
        it.privacy = (data && data.privacy) ? data.privacy : targetPrivacy;
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
      }).catch(function (err) {
        btns.forEach(function (b) { b.disabled = false; });
        wrap.classList.remove('zml-busy');
        if (status) { status.textContent = (err && err.message) || 'Update failed'; status.classList.add('is-error'); }
      });
    },

    // delete the photo being viewed. Same double-confirmed path as
    // bulk delete; on success the surface list has already been updated by
    // performDelete(), so we just re-anchor the viewer (next photo, or close
    // when none remain).
    deleteCurrent: function () {
      var it = this.current(); if (!it || !it.can_delete) return;
      var self = this;
      performDelete(this.surface, [it.id], { button: byId('zml-lb-del') }).then(function (result) {
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
      if (ConfirmSheet.isOpen()) return;   // the sheet owns the keys
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

  var ZMLMedia = {
    _pendingFsState: null,

    /**
     * open the shared viewer on an ARBITRARY list of photos.
     *
     * Added so the Jobs app can show a job's photos in the SAME viewer as
     * everything else rather than growing a second, worse one. Two photo
     * viewers in one product is precisely how a shared standard erodes: they drift, one gets the zoom fix
     * and the other does not, and the person using them has to remember which
     * is which.
     *
     * `items` need only carry what the lightbox actually reads:
     *   { id, file_url, thumbnail_url, title, note, captured_at, created_at,
     *     gps_lat, gps_lng, source_app, is_owner, can_delete, privacy }
     * Anything missing degrades to a hidden control, not an error.
     *
     * The caller's array is COPIED. A borrowed surface must never be able to
     * mutate the caller's data, and note/visibility edits here are deliberately
     * inert - the owning app is responsible for its own refresh.
     *
     * @param {Array}  items
     * @param {number} startIndex
     * @returns {boolean} false if there was nothing to show
     */
    openItems: function (items, startIndex) {
      if (!Array.isArray(items) || !items.length) return false;
      Lightbox.build();
      var copy = items.slice();
      var surface = {
        kind: 'external',          // NOT 'fullscreen' -- suppresses pagination
        items: copy,
        hasMore: false,
        loadMore: function () {},
        refreshCell: function () {},          // no grid to refresh
        removeItemsByIds: function (ids) {    // keep delete coherent if it is ever allowed
          if (!Array.isArray(ids)) return;
          for (var i = copy.length - 1; i >= 0; i--) {
            if (ids.indexOf(copy[i].id) > -1) copy.splice(i, 1);
          }
        }
      };
      var pos = Math.max(0, Math.min(parseInt(startIndex, 10) || 0, copy.length - 1));
      Lightbox.open(surface, pos);
      return true;
    },

    mountWidget: function (rootEl) {
      if (!rootEl) rootEl = byId('zml-w');
      if (!rootEl) return;
      if (rootEl.dataset.zmlMounted === '1') return;
      rootEl.dataset.zmlMounted = '1';
      if (!needKit(rootEl.querySelector('#zml-w-grid') || rootEl.querySelector('.zml-w-grid'))) return;

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
      if (rootEl.dataset.zmlMounted === '1') return;
      rootEl.dataset.zmlMounted = '1';
      if (!needKit(rootEl.querySelector('#zml-fs-grid-wrap') || rootEl.querySelector('.zml-fs-grid-wrap'))) return;

      var initial = ZMLMedia._pendingFsState || {};
      ZMLMedia._pendingFsState = null;

      // never start in the admin-only "All" scope for a non-admin
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

  // the app needs the theme's kit (ZDZ.ui). Without it, say so in the grid
  // instead of leaving the loader running forever. Returns true when the kit is there.
  function needKit(gridWrap) {
    if (ui()) return true;
    if (gridWrap) gridWrap.innerHTML = '<div class="zml-empty zml-load-error" role="alert">' + esc(NEEDS_KIT) + '</div>';
    return false;
  }

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
      if (host && host.dataset.zmlMounted !== '1') {
        ZMLMedia.mountFullscreen(host);
      }
    });
    fsObserver.observe(appBody, { childList: true, subtree: true });
    // Catch the case where it's already present at install time.
    var existing = appBody.querySelector('#zml-fs');
    if (existing && existing.dataset.zmlMounted !== '1') ZMLMedia.mountFullscreen(existing);
  }

  window.ZMLMedia = ZMLMedia;

  // Boot: install the #app-body watcher, and mount the widget if it's already
  // on the page. Both are idempotent.
  function boot() {
    watchAppBody();
    var w = byId('zml-w');
    if (w && w.dataset.zmlMounted !== '1') ZMLMedia.mountWidget(w);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
  // The dashboard re-renders widgets on this event; re-run boot to (re)mount.
  document.addEventListener('zdz_widgets_rendered', boot);
})();
