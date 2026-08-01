/**
 * TS Sketch Pad — SPA Dashboard Widget v1.0.4
 *
 * v1.0.4: One-tap finish + intuitive flow.
 *   - "Done" in the fullscreen overlay is now SAVE: it commits the sketch and
 *     returns to the dashboard, then auto-scrolls the Sketch widget into view
 *     and opens "My Sketches" so the saved result is visible (no manual scroll,
 *     no separate "Save Sketch" tap). A small Close (X) exits without saving.
 *   - Loaded sketches open in VIEW mode (drawing disabled) so an accidental
 *     touch can't draw on them; an explicit "Add to this sketch" confirmation
 *     switches to edit mode.
 *   - Launch-to-canvas and back-button/history parity unchanged.
 * v1.0.2: Landscape safe-area insets — canvas sizes to content area,
 *   excluding env(safe-area-inset-*) padding on Dynamic Island devices.
 * v1.0.1: Scale-to-fit in widget after fullscreen drawing.
 *   Theme-aware ink: MutationObserver redraws on theme switch.
 *   Yellow notebook paper in light mode, visible lines in dark mode.
 *   Auto-title (no title input). Lucide icon in fullscreen header.
 */
(function () {
  'use strict';

  var SW = 2.5, PAD = 20, SK = 'zsp_draft_strokes';
  var st = {
    strokes: [], redo: [], drawing: false, cur: [],
    canvas: null, ctx: null, dpr: 1, saveT: null,
    fsOv: null, fsC: null, fsX: null,
    busy: false, galLoaded: false,
    // v1.0.4: when a saved sketch is opened, it starts read-only. Drawing is
    // disabled until the user confirms "Add to this sketch". `loadedId` tracks
    // which saved sketch is on the canvas (so a future "update in place" could
    // use it; today every save is a new sketch, matching prior behavior).
    viewMode: false, loadedId: null, _justSaved: false
  };

  function el(id) { return document.getElementById(id); }
  function cfg() { return window.zspWidget || {}; }

  function getTheme() {
    var t = (document.documentElement.getAttribute('data-theme') || '').toLowerCase();
    if (t === 'sunlight') return 'sunlight';
    if (t === 'light') return 'light';
    if (t === 'dark') return 'dark';
    // system / auto
    return window.matchMedia('(prefers-color-scheme:dark)').matches ? 'dark' : 'light';
  }
  function sCol() { return getTheme() === 'dark' ? 'rgba(230,240,255,0.92)' : '#1a1a2e'; }
  function esc(s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }

  function timeAgo(ds) {
    if (!ds) return '';
    // MySQL datetime uses space: "2026-05-10 15:58:00" — replace with T for JS parsing
    var d = new Date(ds.replace(' ', 'T'));
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
    return fetch(cfg().ajaxurl, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); });
  }

  /* ── Canvas Engine ─────────────────────────────────────── */

  function initC(c) {
    var dpr = window.devicePixelRatio || 1; st.dpr = dpr;
    var r = c.getBoundingClientRect();
    c.width = r.width * dpr; c.height = r.height * dpr;
    var x = c.getContext('2d'); x.scale(dpr, dpr);
    x.lineCap = 'round'; x.lineJoin = 'round'; x.lineWidth = SW; x.strokeStyle = sCol();
    return x;
  }

  /**
   * Redraw all strokes. If scaleToFit is true (widget canvas after fullscreen),
   * calculate bounding box and scale/translate to fit within canvas bounds.
   */
  function redraw(x, c, scaleToFit) {
    var d = st.dpr;
    var cw = c.width / d, ch = c.height / d;
    x.save();
    x.setTransform(d, 0, 0, d, 0, 0); // Reset to DPR scale
    x.clearRect(0, 0, cw, ch);
    x.lineCap = 'round'; x.lineJoin = 'round';

    if (!st.strokes.length) { x.restore(); return; }

    // Calculate bounding box of all strokes
    var mX = Infinity, mY = Infinity, MX = -Infinity, MY = -Infinity;
    for (var s = 0; s < st.strokes.length; s++) {
      for (var p = 0; p < st.strokes[s].length; p++) {
        var pt = st.strokes[s][p];
        if (pt.x < mX) mX = pt.x; if (pt.y < mY) mY = pt.y;
        if (pt.x > MX) MX = pt.x; if (pt.y > MY) MY = pt.y;
      }
    }

    var sw = MX - mX, sh = MY - mY;
    var scale = 1, ox = 0, oy = 0;

    if (scaleToFit && (sw > cw - PAD * 2 || sh > ch - PAD * 2)) {
      // Drawing is larger than widget canvas — scale to fit
      scale = Math.min((cw - PAD * 2) / Math.max(sw, 1), (ch - PAD * 2) / Math.max(sh, 1));
      ox = (cw - sw * scale) / 2 - mX * scale;
      oy = (ch - sh * scale) / 2 - mY * scale;
    }

    x.strokeStyle = sCol();
    x.lineWidth = SW * (scaleToFit ? scale : 1);
    for (var s2 = 0; s2 < st.strokes.length; s2++) {
      var pts = st.strokes[s2]; if (pts.length < 2) continue;
      x.beginPath();
      x.moveTo(pts[0].x * scale + ox, pts[0].y * scale + oy);
      for (var i = 1; i < pts.length; i++) {
        x.lineTo(pts[i].x * scale + ox, pts[i].y * scale + oy);
      }
      x.stroke();
    }
    x.restore();
  }

  function pPos(e, c) { var r = c.getBoundingClientRect(); return { x: e.clientX - r.left, y: e.clientY - r.top }; }

  function bindC(c, x) {
    c.addEventListener('pointerdown', function (e) {
      if (e.button && e.button !== 0) return;
      // v1.0.4: In view mode (a saved sketch was opened), a touch must NOT
      // draw. Instead, offer to start editing. This prevents an accidental
      // stray mark on someone's saved sketch.
      if (st.viewMode) {
        e.preventDefault();
        promptEnterEdit();
        return;
      }
      e.preventDefault();
      st.drawing = true; st.cur = [];
      var p = pPos(e, c); st.cur.push(p);
      x.strokeStyle = sCol(); x.lineWidth = SW;
      x.beginPath(); x.moveTo(p.x, p.y); updPH();
    });
    c.addEventListener('pointermove', function (e) {
      if (!st.drawing) return; e.preventDefault();
      var p = pPos(e, c); st.cur.push(p);
      x.lineTo(p.x, p.y); x.stroke(); x.beginPath(); x.moveTo(p.x, p.y);
    });
    function up() {
      if (!st.drawing) return; st.drawing = false;
      if (st.cur.length > 0) {
        st.strokes.push(st.cur); st.redo = []; st.cur = [];
        updCtrl(); schedSave();
        // Sync between widget and fullscreen canvases
        if (c === st.canvas && st.fsX) redraw(st.fsX, st.fsC, false);
        else if (c !== st.canvas && st.ctx) redraw(st.ctx, st.canvas, true);
      }
    }
    c.addEventListener('pointerup', up);
    c.addEventListener('pointerleave', up);
    c.addEventListener('pointercancel', up);
  }

  /* ── Controls ──────────────────────────────────────────── */

  function updCtrl() {
    var u = el('zsp-w-undo'), r = el('zsp-w-redo'), c = el('zsp-w-clear'), s = el('zsp-w-save');
    if (u) u.disabled = !st.strokes.length; if (r) r.disabled = !st.redo.length;
    if (c) c.disabled = !st.strokes.length; if (s) s.disabled = !st.strokes.length;
    updPH();
  }

  function updPH() {
    var p = el('zsp-w-placeholder');
    var hasContent = st.strokes.length > 0 || st.drawing;
    if (p) p.classList.toggle('zsp-hidden', hasContent);
    // v1.0.4: the corner "Tap to draw" hint hides once there's a drawing so it
    // doesn't sit on top of strokes.
    var hint = el('zsp-w-canvas-hint');
    if (hint) hint.classList.toggle('zsp-hidden', hasContent);
  }

  function undo() {
    if (!st.strokes.length) return; st.redo.push(st.strokes.pop());
    if (st.ctx) redraw(st.ctx, st.canvas, true);
    if (st.fsX) redraw(st.fsX, st.fsC, false);
    updCtrl(); schedSave();
  }

  function redo2() {
    if (!st.redo.length) return; st.strokes.push(st.redo.pop());
    if (st.ctx) redraw(st.ctx, st.canvas, true);
    if (st.fsX) redraw(st.fsX, st.fsC, false);
    updCtrl(); schedSave();
  }

  function clr() {
    st.strokes = []; st.redo = []; st.cur = []; sessionStorage.removeItem(SK);
    if (st.ctx) redraw(st.ctx, st.canvas, false);
    if (st.fsX) redraw(st.fsX, st.fsC, false);
    updCtrl();
  }

  /* ── Draft Persistence ─────────────────────────────────── */

  function schedSave() {
    if (st.saveT) clearTimeout(st.saveT);
    st.saveT = setTimeout(function () {
      try {
        sessionStorage.setItem(SK, JSON.stringify(st.strokes));
        var s = el('zsp-w-status');
        if (s) { s.textContent = 'Draft saved'; setTimeout(function () { if (s) s.textContent = ''; }, 1500); }
      } catch (e) { /* silent */ }
    }, 500);
  }

  function restoreDraft() {
    try {
      var r = sessionStorage.getItem(SK); if (!r) return;
      var d = JSON.parse(r);
      if (Array.isArray(d) && d.length) {
        st.strokes = d; st.redo = [];
        if (st.ctx) redraw(st.ctx, st.canvas, true);
        updCtrl();
      }
    } catch (e) { /* corrupt */ }
  }

  /* ── Export ────────────────────────────────────────────── */

  function exportBlob() {
    if (!st.strokes.length) return null;
    var mX = Infinity, mY = Infinity, MX = -Infinity, MY = -Infinity;
    for (var s = 0; s < st.strokes.length; s++) {
      for (var p = 0; p < st.strokes[s].length; p++) {
        var pt = st.strokes[s][p];
        if (pt.x < mX) mX = pt.x; if (pt.y < mY) mY = pt.y;
        if (pt.x > MX) MX = pt.x; if (pt.y > MY) MY = pt.y;
      }
    }
    var w = Math.max(200, (MX - mX) + PAD * 2), h = Math.max(100, (MY - mY) + PAD * 2);
    var c = document.createElement('canvas'), dpr = 2;
    c.width = Math.round(w * dpr); c.height = Math.round(h * dpr);
    var x = c.getContext('2d'); x.scale(dpr, dpr);
    // Export always on white with dark ink
    x.fillStyle = '#FFF'; x.fillRect(0, 0, w, h);
    x.lineCap = 'round'; x.lineJoin = 'round'; x.lineWidth = SW; x.strokeStyle = '#1a1a2e';
    for (var s2 = 0; s2 < st.strokes.length; s2++) {
      var pts = st.strokes[s2]; if (pts.length < 2) continue;
      x.beginPath(); x.moveTo(pts[0].x - mX + PAD, pts[0].y - mY + PAD);
      for (var p2 = 1; p2 < pts.length; p2++) x.lineTo(pts[p2].x - mX + PAD, pts[p2].y - mY + PAD);
      x.stroke();
    }
    var d = c.toDataURL('image/jpeg', 0.92), parts = d.split(',');
    var b = atob(parts[1]), buf = new ArrayBuffer(b.length), v = new Uint8Array(buf);
    for (var i = 0; i < b.length; i++) v[i] = b.charCodeAt(i);
    return new Blob([buf], { type: 'image/jpeg' });
  }

  /* ── Save ──────────────────────────────────────────────── */

  // v1.0.4: Save is now driven by the fullscreen "Done" (primary) button.
  // On success we close the overlay and land the user back on the Sketch
  // widget — auto-scrolled into view, with "My Sketches" open — so the saved
  // result is visible without any manual scrolling or a second tap.
  function handleSave() {
    if (st.busy || !st.strokes.length) return;
    st.busy = true;

    // Drive the primary fullscreen button into a busy state if present.
    var fsSave = el('zsp-fs-save');
    if (fsSave) { fsSave.disabled = true; fsSave.classList.add('zsp-w-fs-saving'); }
    var fsStatus = el('zsp-fs-status');
    if (fsStatus) fsStatus.textContent = 'Saving…';
    // Legacy widget save button (kept for safety if ever rendered).
    var saveBtn = el('zsp-w-save');
    if (saveBtn) saveBtn.disabled = true;

    var blob = exportBlob();
    if (!blob) {
      st.busy = false;
      if (fsSave) { fsSave.disabled = false; fsSave.classList.remove('zsp-w-fs-saving'); }
      if (fsStatus) fsStatus.textContent = '';
      return;
    }

    // Auto-title: "Sketch — May 10, 2:31 PM"
    var now = new Date();
    var title = 'Sketch — ' + now.toLocaleDateString(undefined, { month:'short', day:'numeric' }) + ', ' +
      now.toLocaleTimeString(undefined, { hour:'numeric', minute:'2-digit' });

    var canvasData = JSON.stringify(st.strokes);
    var fd = new FormData();
    fd.append('action', 'zsp_save_sketch');
    fd.append('nonce', cfg().nonce);
    fd.append('image', blob, 'sketch.jpg');
    fd.append('title', title);
    fd.append('canvas_data', canvasData);

    fetch(cfg().ajaxurl, { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (resp) {
        st.busy = false;
        if (resp.success) {
          // Clear the working canvas/draft and force the gallery to refresh.
          clr();
          st.galLoaded = false;
          st.loadedId = null;
          st._justSaved = true;
          // Close the fullscreen overlay (via history so back-button stays
          // symmetric) and land on the widget gallery. The landing happens in
          // closeFS() when it sees _justSaved.
          if (st.fsOv) { requestCloseFS(); }
          else { landOnWidget(); }
        } else {
          if (fsSave) { fsSave.disabled = false; fsSave.classList.remove('zsp-w-fs-saving'); }
          if (fsStatus) fsStatus.textContent = '';
          if (saveBtn) saveBtn.disabled = !st.strokes.length;
          // Show full debug trace — the server returns step-by-step diagnostics.
          var errMsg = typeof resp.data === 'string' ? resp.data : JSON.stringify(resp.data);
          console.error('TSSP Save Debug:', errMsg);
          alert('Save failed: ' + errMsg);
        }
      })
      .catch(function (err) {
        st.busy = false;
        if (fsSave) { fsSave.disabled = false; fsSave.classList.remove('zsp-w-fs-saving'); }
        if (fsStatus) fsStatus.textContent = '';
        if (saveBtn) saveBtn.disabled = false;
        alert('Save error: ' + (err.message || 'Network error'));
      });
  }

  // v1.0.4: After saving (or any "Done"), bring the user back to the Sketch
  // widget instead of leaving them on a blank dashboard. Scroll it into view
  // and open "My Sketches" so the just-saved sketch is right there.
  function landOnWidget() {
    var widget = document.querySelector('.dash-widget-container[data-app-id="zdz-sketch-pad"]')
      || document.querySelector('.zsp-w');
    // If we just saved, show the gallery so the result is visible.
    if (st._justSaved) {
      showGalleryTab();
      var toast = document.querySelector('.zsp-w');
      if (toast) flashSavedToast(toast);
    }
    if (widget && typeof widget.scrollIntoView === 'function') {
      // Defer one frame so the tab switch/layout settles before scrolling.
      requestAnimationFrame(function () {
        widget.scrollIntoView({ behavior: 'smooth', block: 'start' });
      });
    }
    st._justSaved = false;
  }

  // Switch the widget's tabs to "My Sketches" and (re)load it.
  function showGalleryTab() {
    var tabs = document.querySelectorAll('#zsp-widget .zsp-w-tab');
    tabs.forEach(function (t) {
      t.classList.toggle('zsp-w-tab-active', t.getAttribute('data-tab') === 'gallery');
    });
    document.querySelectorAll('#zsp-widget .zsp-w-panel').forEach(function (p) {
      p.style.display = p.id === 'zsp-w-tab-gallery' ? '' : 'none';
    });
    loadGallery();
  }

  function flashSavedToast(panelHost) {
    var msg = document.createElement('div');
    msg.className = 'zsp-w-success-msg';
    msg.textContent = '\u2705 Sketch saved!';
    panelHost.insertBefore(msg, panelHost.firstChild);
    setTimeout(function () { if (msg.parentNode) msg.parentNode.removeChild(msg); }, 3000);
  }

  /* ── Gallery ───────────────────────────────────────────── */

  function loadSketch(id) {
    if (st.busy) return;
    if (st.strokes.length && !confirm('You have an unsaved sketch. Load this one instead?')) return;
    st.busy = true;
    ajaxPost('zsp_load_sketch', { media_id: id })
      .then(function (resp) {
        st.busy = false;
        if (!resp.success || !resp.data) { alert('Could not load sketch.'); return; }
        var d = resp.data;
        var strokes = null;
        if (d.canvas_data) {
          try { strokes = typeof d.canvas_data === 'string' ? JSON.parse(d.canvas_data) : d.canvas_data; } catch(e) { strokes = null; }
        }
        if (strokes && Array.isArray(strokes) && strokes.length) {
          st.strokes = strokes; st.redo = []; st.cur = [];
          st.loadedId = d.id || null;
          if (st.ctx) redraw(st.ctx, st.canvas, true);
          updCtrl();
          // v1.0.4: open the loaded sketch READ-ONLY (view mode). A stray touch
          // won't draw; the user must explicitly choose "Add to this sketch".
          openFS({ view: true, title: d.title });
        } else if (d.file_url) {
          // No stroke data — open the image in a fullscreen viewer
          viewImage(d.file_url, d.title);
        } else {
          alert('This sketch has no editable data.');
        }
      })
      .catch(function () { st.busy = false; alert('Network error loading sketch.'); });
  }

  function viewImage(url, title) {
    var ov = document.createElement('div'); ov.className = 'zsp-w-fs-overlay zsp-w-img-viewer';
    ov.innerHTML =
      '<div class="zsp-w-fs-header">' +
        '<span class="zsp-w-fs-title">' + esc(title || 'Sketch') + '</span>' +
        '<button type="button" class="zsp-w-fs-done" id="zsp-iv-close"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg> Close</button>' +
      '</div>' +
      '<div class="zsp-w-fs-body zsp-w-img-body"><img src="' + esc(url) + '" class="zsp-w-img-full" alt="' + esc(title || '') + '" /></div>';
    document.body.appendChild(ov); document.body.style.overflow = 'hidden';
    document.getElementById('zsp-iv-close').addEventListener('click', function () {
      if (ov.parentNode) ov.parentNode.removeChild(ov);
      document.body.style.overflow = '';
    });
  }

  function loadGallery() {
    var gal = el('zsp-w-gallery'); if (!gal) return;
    gal.innerHTML = '<div class="zsp-w-loading">Loading sketches\u2026</div>';
    ajaxPost('zsp_list_sketches', {})
      .then(function (resp) {
        if (!resp.success) { gal.innerHTML = '<div class="zsp-w-gallery-empty">Failed to load.</div>'; return; }
        var sketches = resp.data.sketches || [];
        if (!sketches.length) {
          gal.innerHTML = '<div class="zsp-w-gallery-empty">No sketches yet. Draw something and save it!</div>';
          return;
        }
        gal.innerHTML = '';
        sketches.forEach(function (s) {
          var item = document.createElement('div'); item.className = 'zsp-w-gallery-item';
          var badge = s.has_strokes ? '<span class="zsp-w-gal-edit-badge">Tap to edit</span>' : '<span class="zsp-w-gal-view-badge">Tap to view</span>';
          item.innerHTML =
            '<div class="zsp-w-gal-tap" data-id="' + s.id + '">' +
            '<img class="zsp-w-gallery-thumb" src="' + esc(s.thumbnail_url) + '" alt="' + esc(s.title) + '" loading="lazy" />' +
            badge + '</div>' +
            '<div class="zsp-w-gallery-info"><div class="zsp-w-gallery-title">' + esc(s.title) + '</div>' +
            '<div class="zsp-w-gallery-date">' + timeAgo(s.created_at) + '</div></div>' +
            '<div class="zsp-w-gallery-actions">' +
            '<button class="zsp-w-btn zsp-w-btn-sm zsp-w-gal-del" data-id="' + s.id + '" style="color:#DC2626;">Delete</button></div>';
          gal.appendChild(item);
        });
        // Tap thumbnail → load sketch
        gal.querySelectorAll('.zsp-w-gal-tap').forEach(function (tap) {
          tap.addEventListener('click', function () { loadSketch(parseInt(this.dataset.id, 10)); });
        });
        gal.querySelectorAll('.zsp-w-gal-del').forEach(function (btn) {
          btn.addEventListener('click', function (e) {
            e.stopPropagation();
            var id = this.dataset.id, row = this.closest('.zsp-w-gallery-item');
            ajaxPost('zsp_delete_sketch', { media_id: id }).then(function (resp) {
              if (resp.success && row) { row.style.opacity = '0'; setTimeout(function () { if (row.parentNode) row.parentNode.removeChild(row); }, 200); }
            });
          });
        });
        st.galLoaded = true;
      })
      .catch(function () { gal.innerHTML = '<div class="zsp-w-gallery-empty">Error loading sketches.</div>'; });
  }

  /* ── Fullscreen ────────────────────────────────────────── */

  function openFS(opts) {
    if (st.fsOv) return;
    opts = opts || {};
    st.viewMode = !!opts.view;
    var titleText = opts.title ? esc(opts.title) : 'Sketch';

    var pencilSVG = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/><path d="m15 5 4 4"/></svg>';
    var closeSVG  = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>';
    var checkSVG  = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>';
    var editSVG   = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>';

    // v1.0.6: Save moved OFF the top-right (a long thumb-stretch on a phone) and
    // INTO the bottom action bar, right-aligned next to the draw tools — where
    // the thumb naturally rests for one-handed use. The header keeps only the
    // small Close (X) for "exit without saving". In view mode the bottom-right
    // shows a "Viewing" badge instead of Save.
    var bottomRight = st.viewMode
      ? '<span class="zsp-w-fs-viewbadge" id="zsp-fs-viewbadge">Viewing</span>'
      : '<button type="button" class="zsp-w-fs-save" id="zsp-fs-save">' + checkSVG + ' <span>Save</span></button>';

    var ov = document.createElement('div'); ov.className = 'zsp-w-fs-overlay' + (st.viewMode ? ' zsp-w-fs-viewing' : '');
    ov.innerHTML =
      '<div class="zsp-w-fs-header">' +
        '<button type="button" class="zsp-w-fs-close" id="zsp-fs-close" aria-label="Close without saving">' + closeSVG + '</button>' +
        '<div class="zsp-w-fs-icon">' + pencilSVG + '</div>' +
        '<span class="zsp-w-fs-title">' + titleText + '</span>' +
      '</div>' +
      '<div class="zsp-w-fs-body"><canvas class="zsp-w-fs-canvas" id="zsp-fs-canvas"></canvas></div>' +
      // View-mode action banner — explicit confirmation before drawing.
      (st.viewMode
        ? '<div class="zsp-w-fs-viewbar" id="zsp-fs-viewbar">' +
            '<span class="zsp-w-fs-viewbar-label">Saved sketch — read only</span>' +
            '<button type="button" class="zsp-w-btn zsp-w-btn-primary zsp-w-fs-editbtn" id="zsp-fs-edit">' + editSVG + ' Add to this sketch</button>' +
          '</div>'
        : '') +
      // Bottom action bar — draw tools on the LEFT, primary Save on the RIGHT,
      // all within easy thumb reach. (v1.0.6: Save relocated here from the header.)
      '<div class="zsp-w-fs-toolbar" id="zsp-fs-toolbar"' + (st.viewMode ? ' style="display:none;"' : '') + '>' +
        '<button type="button" class="zsp-w-tool" id="zsp-fs-undo"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 7v6h6"/><path d="M21 17a9 9 0 0 0-9-9 9 9 0 0 0-6 2.3L3 13"/></svg></button>' +
        '<button type="button" class="zsp-w-tool" id="zsp-fs-redo"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 7v6h-6"/><path d="M3 17a9 9 0 0 1 9-9 9 9 0 0 1 6 2.3L21 13"/></svg></button>' +
        '<div class="zsp-w-sep"></div>' +
        '<button type="button" class="zsp-w-tool" id="zsp-fs-clear"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/></svg></button>' +
        // .zsp-w-status is flex:1, so it absorbs the slack and pushes Save to
        // the far right of the bar (no extra spacer needed).
        '<span class="zsp-w-status" id="zsp-fs-status"></span>' +
        bottomRight +
      '</div>';
    document.body.appendChild(ov); st.fsOv = ov; document.body.style.overflow = 'hidden';

    // History parity with full-screen apps: push an entry so the phone /
    // browser back button (and Android hardware back) closes the overlay.
    try { history.pushState({ tsspFS: 1 }, ''); st._fsHist = true; } catch (e) { st._fsHist = false; }

    requestAnimationFrame(function () {
      var body = ov.querySelector('.zsp-w-fs-body');
      var fc = document.getElementById('zsp-fs-canvas');
      var cs = getComputedStyle(body);
      var bw = body.clientWidth - (parseFloat(cs.paddingLeft)||0) - (parseFloat(cs.paddingRight)||0);
      var bh = body.clientHeight - (parseFloat(cs.paddingTop)||0) - (parseFloat(cs.paddingBottom)||0);
      fc.style.width = bw + 'px'; fc.style.height = bh + 'px';
      var dpr = window.devicePixelRatio || 1;
      fc.width = bw * dpr; fc.height = bh * dpr;
      var x = fc.getContext('2d'); x.scale(dpr, dpr);
      x.lineCap = 'round'; x.lineJoin = 'round'; x.lineWidth = SW; x.strokeStyle = sCol();
      st.fsC = fc; st.fsX = x;
      redraw(x, fc, false); bindC(fc, x);

      // Close (X) always exits without saving.
      var closeBtn = document.getElementById('zsp-fs-close');
      if (closeBtn) closeBtn.addEventListener('click', requestCloseFS);

      // Save (edit mode only) commits and returns to the widget.
      var saveBtn = document.getElementById('zsp-fs-save');
      if (saveBtn) saveBtn.addEventListener('click', handleSave);

      // "Add to this sketch" (view mode only) → confirm, then enter edit mode.
      var editBtn = document.getElementById('zsp-fs-edit');
      if (editBtn) editBtn.addEventListener('click', promptEnterEdit);
      var viewbadge = document.getElementById('zsp-fs-viewbadge');
      if (viewbadge) viewbadge.addEventListener('click', promptEnterEdit);

      document.getElementById('zsp-fs-undo').addEventListener('click', undo);
      document.getElementById('zsp-fs-redo').addEventListener('click', redo2);
      document.getElementById('zsp-fs-clear').addEventListener('click', function () { if (st.strokes.length) clr(); });

      // Resize canvas on orientation change or window resize (landscape support)
      function resizeFS() {
        var cs2 = getComputedStyle(body);
        var bw2 = body.clientWidth - (parseFloat(cs2.paddingLeft)||0) - (parseFloat(cs2.paddingRight)||0);
        var bh2 = body.clientHeight - (parseFloat(cs2.paddingTop)||0) - (parseFloat(cs2.paddingBottom)||0);
        fc.style.width = bw2 + 'px'; fc.style.height = bh2 + 'px';
        var dpr2 = window.devicePixelRatio || 1;
        fc.width = bw2 * dpr2; fc.height = bh2 * dpr2;
        var x2 = fc.getContext('2d'); x2.scale(dpr2, dpr2);
        x2.lineCap = 'round'; x2.lineJoin = 'round'; x2.lineWidth = SW; x2.strokeStyle = sCol();
        st.fsX = x2;
        redraw(x2, fc, false);
      }
      window.addEventListener('resize', resizeFS);
      st._fsResize = resizeFS; // store ref for cleanup
    });
  }

  // v1.0.4: Confirm before turning a read-only loaded sketch into an editable
  // one. On "yes", reveal the draw toolbar + Save button and enable drawing.
  function promptEnterEdit() {
    if (!st.viewMode) return;
    if (!confirm('Do you want to add to this sketch?')) return;
    enterEditMode();
  }

  function enterEditMode() {
    st.viewMode = false;
    var ov = st.fsOv; if (!ov) return;
    ov.classList.remove('zsp-w-fs-viewing');
    // Reveal the toolbar.
    var toolbar = document.getElementById('zsp-fs-toolbar');
    if (toolbar) toolbar.style.display = '';
    // Hide the view banner.
    var viewbar = document.getElementById('zsp-fs-viewbar');
    if (viewbar) viewbar.parentNode && viewbar.parentNode.removeChild(viewbar);
    // Swap the bottom-bar "Viewing" badge for a real Save button (in place, so
    // it stays in the thumb-reachable toolbar — v1.0.6).
    var badge = document.getElementById('zsp-fs-viewbadge');
    if (badge && badge.parentNode) {
      var checkSVG = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>';
      var btn = document.createElement('button');
      btn.type = 'button'; btn.className = 'zsp-w-fs-save'; btn.id = 'zsp-fs-save';
      btn.innerHTML = checkSVG + ' <span>Save</span>';
      btn.addEventListener('click', handleSave);
      badge.parentNode.replaceChild(btn, badge);
    }
    // Recompute canvas geometry (toolbar appearing changed body height).
    if (st._fsResize) st._fsResize();
    updCtrl();
  }

  // Close (X) / explicit close: step back through history, which fires
  // popstate → closeFS(). Keeps the back button and Close symmetric. If we
  // never managed to push a history entry, tear down directly.
  function requestCloseFS() {
    if (!st.fsOv) return;
    if (st._fsHist) { history.back(); }
    else { closeFS(); }
  }

  function closeFS() {
    var ov = st.fsOv; if (!ov) return;
    if (st._fsResize) { window.removeEventListener('resize', st._fsResize); st._fsResize = null; }
    if (st.ctx && st.canvas) redraw(st.ctx, st.canvas, true);
    updCtrl();
    if (ov.parentNode) ov.parentNode.removeChild(ov);
    st.fsOv = null; st.fsC = null; st.fsX = null;
    st._fsHist = false;
    st.viewMode = false;
    document.body.style.overflow = '';
    // v1.0.4: after a save, land the user on the Sketch widget (scrolled into
    // view, gallery open) instead of a blank dashboard.
    if (st._justSaved) { landOnWidget(); }
  }

  // Hardware/browser back closes the overlay (mirrors full-screen apps).
  window.addEventListener('popstate', function () {
    if (st.fsOv) closeFS();
  });

  /* ── Theme Observer ────────────────────────────────────── */

  function onThemeChange() {
    // Redraw both canvases with updated ink color
    if (st.ctx && st.canvas) redraw(st.ctx, st.canvas, true);
    if (st.fsX && st.fsC) redraw(st.fsX, st.fsC, false);
  }

  /* ── Tabs ──────────────────────────────────────────────── */

  function initTabs() {
    var tabs = document.querySelectorAll('#zsp-widget .zsp-w-tab');
    tabs.forEach(function (tab) {
      tab.addEventListener('click', function () {
        tabs.forEach(function (t) { t.classList.remove('zsp-w-tab-active'); });
        tab.classList.add('zsp-w-tab-active');
        var target = tab.getAttribute('data-tab');
        document.querySelectorAll('#zsp-widget .zsp-w-panel').forEach(function (p) {
          p.style.display = p.id === 'zsp-w-tab-' + target ? '' : 'none';
        });
        if (target === 'gallery' && !st.galLoaded) loadGallery();
      });
    });
  }

  /* ── Init ──────────────────────────────────────────────── */

  function init() {
    var w = document.querySelector('.zsp-w'); if (!w) return;
    if (w.dataset.init === '1') return; w.dataset.init = '1';
    initTabs();
    var c = el('zsp-w-canvas');
    if (c) {
      st.canvas = c; st.ctx = initC(c); restoreDraft(); updCtrl();
      // v1.0.5: The in-widget canvas is now a LIVE drawing surface, consistent
      // with every other app (tap the icon → land on the dashboard widget and
      // start working right there — no app takeover). bindC() wires pointer
      // drawing and already keeps the widget and fullscreen canvases in sync,
      // so strokes made here appear if/when the user expands. The Expand (⤢)
      // button still opens the fullscreen canvas for detailed work.
      bindC(c, st.ctx);
      c.style.cursor = 'crosshair';
      c.style.touchAction = 'none'; // let pointer events drive drawing on touch
    }
    if (el('zsp-w-undo')) el('zsp-w-undo').addEventListener('click', undo);
    if (el('zsp-w-redo')) el('zsp-w-redo').addEventListener('click', redo2);
    if (el('zsp-w-clear')) el('zsp-w-clear').addEventListener('click', function () { if (st.strokes.length) clr(); });
    if (el('zsp-w-expand')) el('zsp-w-expand').addEventListener('click', openFS);
    if (el('zsp-w-save')) el('zsp-w-save').addEventListener('click', handleSave);

    // Watch for theme changes to update ink color
    if (typeof MutationObserver !== 'undefined') {
      new MutationObserver(function (muts) {
        muts.forEach(function (m) { if (m.attributeName === 'data-theme') onThemeChange(); });
      }).observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });
    }

    var rt; window.addEventListener('resize', function () {
      clearTimeout(rt); rt = setTimeout(function () {
        if (st.canvas) { st.ctx = initC(st.canvas); redraw(st.ctx, st.canvas, true); }
      }, 250);
    });
    window.addEventListener('beforeunload', function () {
      if (st.strokes.length) try { sessionStorage.setItem(SK, JSON.stringify(st.strokes)); } catch (e) { /* */ }
    });
  }

  if (document.querySelector('.zsp-w')) init();
  document.addEventListener('zdz_widgets_rendered', init, { once: true });
  document.addEventListener('DOMContentLoaded', function () {
    setTimeout(function () { if (!document.querySelector('.zsp-w[data-init="1"]')) init(); }, 300);
  });

  // ── Launch intent (theme Bridge v3.2) ───────────────────────────
  // v1.0.5: Sketch now behaves like every other dashboard app — tapping the
  // Sketch icon JUMPS to the in-dashboard Sketch widget (where the user can
  // start drawing immediately), rather than taking over the screen. We do NOT
  // call preventDefault(): by letting the event pass, the theme Bridge runs
  // its standard scroll-the-widget-into-view behavior. We only make sure the
  // widget is initialized so the canvas is ready the moment it scrolls in.
  // The Expand (⤢) button inside the widget remains the path to fullscreen.
  document.addEventListener('zdz_app_launch', function (e) {
    if (!e.detail || e.detail.appId !== 'zdz-sketch-pad') return;
    if (!document.querySelector('.zsp-w[data-init="1"]')) init();
    // No preventDefault() — the Bridge scrolls the widget into view for us.
  });
})();
