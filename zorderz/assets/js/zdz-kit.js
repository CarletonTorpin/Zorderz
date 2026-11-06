/**
 * ZDZ.ui — the kit of parts.
 *
 * Without a shared kit each app grows its own spinner, its own toast, its own buttons and its own way of
 * asking the server for data, so no two apps behave alike and every fix has to be made a dozen times.
 * This file is the one copy. An app that uses it looks and behaves like every other app without trying.
 *
 *   ZDZ.ui.busy(button, work)          the button keeps its size and shows it is working; one press at a time
 *   ZDZ.ui.loading(el, label)          the one loader: three dots that breathe (still readable with Reduce Motion)
 *   ZDZ.ui.toast(text, {action, …})    a short message, optionally with one action such as Undo
 *   ZDZ.ui.sheet({title, body, …})     a bottom sheet on a phone, a centered dialog on a computer; Back closes it
 *   ZDZ.ui.ajax / post / rest          data requests that turn a 502 page, a timeout or a lost signal into a
 *                                     plain sentence instead of a crash
 *   ZDZ.ui.needsYou(appId, count)      the vermilion "needs you" badge on that app's icon, wherever it is drawn
 *   ZDZ.ui.person(id) / personChip(id) each person's badge everywhere: initials on a shape that says their
 *                                     privilege (more sides, more privileges) in a quiet blue/teal/gray tone
 *   ZDZ.ui.badge(shape, opts)          the same shape without a person (role badges, legends)
 *   ZDZ.ui.paper(doc, opts)            an estimate or invoice drawn like the billing system's own PDF, with
 *                                     "Copy customer link" and never a link out
 *   ZDZ.ui.paperGhost(text)            the blank sheet shown while a document is read
 *   ZDZ.ui.marks.attach(img)           drawings on a photo, laid over any viewer's image and following its zoom,
 *                                     pan and rotation; .edit() is Draw. A drawing per person, shared like a
 *                                     photo, arrows ending in Post-it notes; .load(id), ZDZ.ui.marks.panel() and
 *                                     .outline()
 *
 * The contract is KIT-OF-PARTS-v1.md in the theme root.
 *
 * Loads after app.js on the app page, and on wp-admin pages opened inside the app (?zdz_mobile=1), where there
 * is no shell: there the kit handles Back for its own sheets.
 *
 * [CORE] — no company data in this file.
 */
(function (w, d) {
  'use strict';

  var ZDZ = w.ZDZ = w.ZDZ || {};
  if (ZDZ.ui && ZDZ.ui.version) { return; } // loaded twice (theme + an app that bundles an old copy): keep the first

  var data = w.zdzData || {};
  var DESKTOP = '(min-width: 820px)';

  // ------------------------------------------------------------------------------------------------ helpers

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function hex(c, fallback) {
    return /^#[0-9a-f]{3}([0-9a-f]{3})?$/i.test(String(c || '')) ? String(c) : fallback;
  }

  function el(tag, cls, text) {
    var n = d.createElement(tag);
    if (cls) { n.className = cls; }
    if (text != null) { n.textContent = text; }
    return n;
  }

  function fill(node, body) {
    if (body == null) { return; }
    if (typeof body === 'string') { node.innerHTML = body; } else { node.appendChild(body); }
  }

  // ------------------------------------------------------------------------------------------------ requests

  /**
   * An error a person can read. kind is one of:
   *   offline   — no signal (fetch failed before the server answered)
   *   timeout   — no answer in time
   *   auth      — signed out, or not allowed (401/403, or admin-ajax's bare "-1" / "0")
   *   server    — the server failed (5xx, or an HTML error page where JSON was expected)
   *   app       — the app said no ({success:false} or a WP_Error body); message is the app's own words
   *   response  — a 2xx answer that was not the JSON the caller asked for
   */
  function RequestError(kind, message, status, body) {
    this.name = 'RequestError';
    this.kind = kind;
    this.message = message;
    this.status = status || 0;
    this.body = body;
  }
  RequestError.prototype = Object.create(Error.prototype);
  RequestError.prototype.constructor = RequestError;

  var WORDS = {
    offline: 'No connection. Check your signal and try again.',
    timeout: 'The server took too long to answer. Try again.',
    auth: 'You are signed out or not allowed to do that. Reload the app and try again.',
    server: 'The server had a problem. Try again in a moment.',
    response: 'The server sent something unexpected. Try again.'
  };

  function sleep(ms) { return new Promise(function (r) { setTimeout(r, ms); }); }

  function encode(body) {
    if (body == null) { return undefined; }
    if (typeof FormData !== 'undefined' && body instanceof FormData) { return body; }
    if (typeof URLSearchParams !== 'undefined' && body instanceof URLSearchParams) { return body; }
    if (typeof body === 'string') { return body; }
    var p = new URLSearchParams();
    Object.keys(body).forEach(function (k) {
      var v = body[k];
      if (v == null) { return; }
      if (Array.isArray(v)) {
        v.forEach(function (x) { p.append(k + '[]', typeof x === 'object' ? JSON.stringify(x) : String(x)); });
      } else if (typeof v === 'object') {
        p.append(k, JSON.stringify(v));
      } else if (typeof v === 'boolean') {
        p.append(k, v ? '1' : '0');
      } else {
        p.append(k, String(v));
      }
    });
    return p;
  }

  /**
   * The one fetch. opts: method, headers, body (already encoded), timeout (ms, default 30000),
   * retry (extra tries on 502/503/504 and lost signal; default 1 for GET, 0 otherwise — a POST may have
   * written before it failed, so it is never repeated unless the caller says it is safe), raw (return the
   * Response's parsed JSON without unwrapping {success,data}), wp (unwrap admin-ajax {success,data}).
   */
  function request(url, opts) {
    opts = opts || {};
    var method = (opts.method || 'GET').toUpperCase();
    var tries = 1 + Math.max(0, opts.retry != null ? (opts.retry | 0) : (method === 'GET' ? 1 : 0));
    var timeout = opts.timeout || 30000;

    function once() {
      var ctl = typeof AbortController !== 'undefined' ? new AbortController() : null;
      var timer = null;
      // The clock runs until the whole body is in: a reply that stalls after its headers is a timeout too,
      // and the race settles even when the stream itself never does.
      var clock = new Promise(function (_, reject) {
        timer = setTimeout(function () {
          if (ctl) { try { ctl.abort(); } catch (e) { /* already done */ } }
          reject(new RequestError('timeout', WORDS.timeout));
        }, timeout);
      });
      var work = fetch(url, {
        method: method,
        headers: opts.headers || {},
        body: method === 'GET' ? undefined : opts.body,
        credentials: 'same-origin',
        signal: ctl ? ctl.signal : undefined
      }).then(function (res) {
        return res.text().then(function (text) { return { res: res, text: text }; });
      });
      work.catch(function () { /* the loser of the race must not surface as an unhandled rejection */ });
      return Promise.race([work, clock]).then(function (r) {
        clearTimeout(timer);
        return r;
      }, function (err) {
        clearTimeout(timer);
        if (err instanceof RequestError) { throw err; }
        if (err && err.name === 'AbortError') { throw new RequestError('timeout', WORDS.timeout); }
        throw new RequestError('offline', WORDS.offline); // no signal, or the line dropped mid-answer
      }).then(function (r) {
        var res = r.res, text = r.text, json, ok = true;
        try { json = text === '' ? null : JSON.parse(text); } catch (e) { ok = false; }
        var t = (text || '').trim();
        if (res.status === 401 || res.status === 403 || (opts.ajaxAuth && (t === '-1' || t === '0'))) {
          // WordPress handlers answer wp_send_json_error( 'a sentence', 403 ), so the sentence is the
          // reply's `data` itself; a 403 keeps it ("Only the seller, the team and admins can open this one.").
          // A 401 is always the sign-in sentence: the person is signed out, whatever the body says.
          var m = ok && json && (json.message || (typeof json.data === 'string' ? json.data : (json.data && json.data.message)));
          throw new RequestError('auth', m && res.status === 403 ? String(m) : WORDS.auth, res.status, text);
        }
        if (res.status >= 500) { throw new RequestError('server', WORDS.server, res.status, text); }
        if (!ok) {
          // An HTML page where JSON was expected is almost always a proxy or PHP error page.
          var kind = /^\s*</.test(text) ? 'server' : 'response';
          throw new RequestError(kind, WORDS[kind], res.status, text);
        }
        if (opts.raw) { return json; }
        if (opts.wp && json && typeof json === 'object' && 'success' in json) {
          if (json.success) { return json.data; }
          var d0 = json.data;
          var msg = (d0 && typeof d0 === 'object' && (d0.message || d0.error)) || (typeof d0 === 'string' ? d0 : '');
          throw new RequestError('app', msg || 'That did not work. Try again.', res.status, json);
        }
        if (!res.ok) {
          var wm = json && (json.message || (json.data && json.data.message));
          throw new RequestError('app', wm || 'That did not work. Try again.', res.status, json);
        }
        return json;
      });
    }

    var attempt = 0;
    function go() {
      attempt++;
      return once().catch(function (e) {
        var again = attempt < tries && e && (e.kind === 'offline' || (e.kind === 'server' && [502, 503, 504].indexOf(e.status) !== -1));
        if (!again) { throw e; }
        return sleep(700 * attempt).then(go);
      });
    }
    return go();
  }

  /** admin-ajax: ZDZ.ui.ajax('zprep_mark_printed', {nonce, job_id}) → the `data` of {success:true,data}. */
  function ajax(action, body, opts) {
    var p = typeof body === 'string' ? new URLSearchParams(body) : (encode(body || {}) || new URLSearchParams());
    if (p.append && !(p.has && p.has('action'))) { p.append('action', action); }
    return request(data.ajaxUrl || '/wp-admin/admin-ajax.php', Object.assign({ method: 'POST', body: p, wp: true, ajaxAuth: true }, opts || {}));
  }

  /** Any form POST to a URL (unwraps {success,data} when the answer has that shape). */
  function post(url, body, opts) {
    return request(url, Object.assign({ method: 'POST', body: encode(body), wp: true }, opts || {}));
  }

  /** The theme's REST API (or any wp-json path): ZDZ.ui.rest('zorderz/v1/media', {method:'POST', body:{…}}). */
  function rest(path, opts) {
    opts = Object.assign({}, opts || {});
    var base = (data.apiUrl || '/wp-json/zorderz/v1/').replace(/zorderz\/v1\/?$/, '');
    var url = /^https?:|^\//.test(path) ? path : base + String(path).replace(/^\//, '');
    var headers = Object.assign({ 'X-WP-Nonce': data.nonce || '' }, opts.headers || {});
    var body = opts.body;
    var plain = body != null && (Array.isArray(body) || Object.prototype.toString.call(body) === '[object Object]');
    if (plain) { // FormData, URLSearchParams, Blob and strings go as they are
      headers['Content-Type'] = 'application/json';
      body = JSON.stringify(body);
    }
    return request(url, Object.assign(opts, { headers: headers, body: body }));
  }

  // ------------------------------------------------------------------------------------------------ busy

  var busyMap = typeof WeakMap !== 'undefined' ? new WeakMap() : null;

  /**
   * Run work with the button busy. work: a promise, or a function returning one. A second press while
   * busy gets the same promise back, so a double tap never sends twice. The button keeps its size
   * (NO-SUBMIT-SHIFT-CONTRACT-v1).
   */
  function busy(btn, work) {
    if (!btn) { return Promise.resolve(typeof work === 'function' ? work() : work); }
    if (busyMap && busyMap.has(btn)) { return busyMap.get(btn); }
    // the mark takes the button's own ink (read before the label goes transparent), so it shows on a
    // white button too. The stylesheets fall back to the brand ink when this is not set.
    try { btn.style.setProperty('--zdz-busy-ink', window.getComputedStyle(btn).color); } catch (e) { /* no styles */ }
    btn.classList.add('zdz-btn-busy');
    btn.setAttribute('aria-busy', 'true');
    var wasDisabled = btn.disabled;
    var p = Promise.resolve().then(function () { return typeof work === 'function' ? work() : work; });
    var done = function () {
      btn.classList.remove('zdz-btn-busy');
      btn.style.removeProperty('--zdz-busy-ink');
      btn.removeAttribute('aria-busy');
      btn.disabled = wasDisabled;
      if (busyMap) { busyMap.delete(btn); }
    };
    var out = p.then(function (v) { done(); return v; }, function (e) { done(); throw e; });
    if (busyMap) { busyMap.set(btn, out); }
    return out;
  }

  // ------------------------------------------------------------------------------------------------ loading

  function loadingHtml(label) {
    return '<div class="zdz-loading" role="status" aria-live="polite">' +
      '<span class="zdz-loading-dots" aria-hidden="true"><i></i><i></i><i></i></span>' +
      '<span class="zdz-loading-label">' + esc(label || 'Loading…') + '</span></div>';
  }

  /** Put the loader in el (replacing what is there). Returns {el, label(text), done()}. */
  function loading(target, label) {
    var host = typeof target === 'string' ? d.querySelector(target) : target;
    var wrap = el('div');
    wrap.innerHTML = loadingHtml(label);
    var node = wrap.firstChild;
    if (host) { host.innerHTML = ''; host.appendChild(node); }
    return {
      el: node,
      label: function (t) { var l = node.querySelector('.zdz-loading-label'); if (l) { l.textContent = t; } },
      done: function () { if (node.parentNode) { node.parentNode.removeChild(node); } }
    };
  }

  // ------------------------------------------------------------------------------------------------ toast

  function toastHost() {
    var c = d.getElementById('toast-container');
    if (!c) { // no shell (an app's own frame): the kit's own host, styled by zdz-kit.css alone
      c = el('div', 'zdz-toast-host');
      c.id = 'toast-container';
      d.body.appendChild(c);
    }
    return c;
  }

  /**
   * ZDZ.ui.toast('Moved to Done', {action:'Undo', onAction: fn, kind:'success'|'error', duration}).
   * One action at most. With an action the toast stays 6 s (time to read and reach it); without, 3 s.
   * Errors are announced at once (role=alert); everything else politely.
   */
  function toast(text, opts) {
    opts = opts || {};
    var host = toastHost();
    var t = el('div', 'toast zdz-toast' + (opts.kind ? ' zdz-toast--' + opts.kind : ''));
    t.setAttribute('role', opts.kind === 'error' ? 'alert' : 'status');
    t.appendChild(el('span', 'zdz-toast-text', text));
    var closed = false, timer = null;
    var api = {
      el: t,
      close: function () {
        if (closed) { return; }
        closed = true;
        if (timer) { clearTimeout(timer); }
        t.classList.add('out');
        setTimeout(function () { if (t.parentNode) { t.parentNode.removeChild(t); } }, 300);
      }
    };
    if (opts.action) {
      var b = el('button', 'zdz-toast-action', opts.action);
      b.type = 'button';
      b.addEventListener('click', function () {
        api.close();
        if (typeof opts.onAction === 'function') { opts.onAction(); }
      });
      t.appendChild(b);
    }
    host.appendChild(t);
    var ms = opts.duration || (opts.action ? 6000 : (opts.kind === 'error' ? 5000 : 3000));
    timer = setTimeout(api.close, ms);
    return api;
  }

  /** A request error as a toast, with Try again when the caller gives a way to retry. */
  function toastError(err, retry) {
    var msg = (err && err.message) || WORDS.server;
    return toast(msg, retry ? { kind: 'error', action: 'Try again', onAction: retry } : { kind: 'error' });
  }

  // ------------------------------------------------------------------------------------------------ sheet

  var sheets = [];   // open sheets, newest last
  var sheetSeq = 0;
  var ownPop = false; // true when there is no shell (wp-admin inside the app): the kit listens to Back itself
  var skipOwn = 0;

  // Counted, not yes/no: two layers closed in one tap queue two pops, and both must be swallowed, or the
  // second reaches the shell and closes the app.
  function skipNextPop() {
    if (w.Bridge && typeof w.Bridge === 'object') { w.Bridge._skipPops = (w.Bridge._skipPops || 0) + 1; } else { skipOwn++; }
  }
  // An entry left behind by a sheet that closed while it was not the current entry (a confirm sheet closing
  // the sheet under it in one tap). When a pop lands on one, it is taken back too — one history.back() per
  // pop, never two in one tap, which some browsers merge into one.
  var stale = {};
  function afterPop() {
    var st = history.state;
    if (st && st.zdzSheet && stale[st.zdzSheet]) {
      delete stale[st.zdzSheet];
      skipNextPop();
      try { history.back(); } catch (e) { unskip(); }
      return true;
    }
    return false;
  }
  function unskip() {
    if (w.Bridge && typeof w.Bridge === 'object') { if (w.Bridge._skipPops > 0) { w.Bridge._skipPops--; } } else if (skipOwn > 0) { skipOwn--; }
  }

  /** Called by the shell's popstate handler first: closes the top sheet and reports that it did. */
  function popLayer() {
    var s = sheets[sheets.length - 1];
    if (!s) { return false; }
    if (!s._dismissible) {
      // A sheet that must be answered stays: put its entry back, so the app's own entry is not spent.
      try { history.pushState({ zdzSheet: s.el.id }, ''); s._pushed = true; } catch (e) { s._pushed = false; }
      return true;
    }
    s._pushed = false; // the browser already went back
    s.close('back');
    afterPop();
    return true;
  }

  var FOCUSABLE = 'a[href],button:not([disabled]),input:not([disabled]):not([type="hidden"]),select:not([disabled]),' +
    'textarea:not([disabled]),[tabindex]:not([tabindex="-1"])';

  /**
   * ZDZ.ui.sheet({
   *   title: 'More tools',
   *   body: '<p>…</p>' | Node,
   *   actions: [{label:'Save', kind:'primary', onClick(sheet){ return ZDZ.ui.ajax(…) }}, {label:'Cancel'}],
   *   dismissible: true,        // backdrop, Escape and Back close it (default true)
   *   onClose(reason){}         // 'action' | 'back' | 'escape' | 'backdrop' | 'close' | 'api'
   * }) → {el, body, close(), closed: Promise<reason>, status(text, kind)}
   *
   * An action's onClick may return a promise: its button shows busy, the sheet closes when it resolves
   * (unless it resolves to false), and a failure is written in the sheet's status line, which is always
   * there so nothing moves (NO-SUBMIT-SHIFT-CONTRACT-v1).
   *
   * Open a sheet from inside the tap that asks for it: browsers ignore a history entry added later, and
   * then Back would leave the app instead of closing the sheet.
   */
  function sheet(opts) {
    opts = opts || {};
    var id = 'zdz-sheet-' + (++sheetSeq);
    var dismissible = opts.dismissible !== false;
    var lastFocus = d.activeElement;

    var root = el('div', 'zdz-sheet-root');
    var backdrop = el('div', 'zdz-sheet-backdrop');
    var panel = el('div', 'zdz-sheet');
    panel.setAttribute('role', 'dialog');
    panel.setAttribute('aria-modal', 'true');
    panel.setAttribute('tabindex', '-1');
    panel.id = id;

    var head = el('div', 'zdz-sheet-head');
    head.appendChild(el('span', 'zdz-sheet-grab'));
    if (opts.title) {
      var h = el('h2', 'zdz-sheet-title', opts.title);
      h.id = id + '-title';
      head.appendChild(h);
      panel.setAttribute('aria-labelledby', h.id);
    } else if (opts.label) {
      panel.setAttribute('aria-label', opts.label);
    }
    var x = el('button', 'zdz-sheet-x');
    x.type = 'button';
    x.setAttribute('aria-label', 'Close');
    x.innerHTML = '<span aria-hidden="true">&times;</span>';
    head.appendChild(x);
    panel.appendChild(head);

    var body = el('div', 'zdz-sheet-body');
    fill(body, opts.body);
    panel.appendChild(body);

    var foot = el('div', 'zdz-sheet-foot');
    var status = el('div', 'zdz-inline-status zdz-sheet-status');
    status.setAttribute('role', 'status');
    status.setAttribute('aria-live', 'polite');
    var row = el('div', 'zdz-sheet-actions');
    (opts.actions || []).forEach(function (a) {
      var b = el('button', 'zdz-btn zdz-btn--' + (a.kind || 'secondary'), a.label);
      b.type = 'button';
      b.addEventListener('click', function () {
        if (!a.onClick) { api.close('action'); return; }
        setStatus('', '');
        busy(b, function () { return a.onClick(api); }).then(function (r) {
          if (r !== false) { api.close('action'); }
        }, function (e) {
          setStatus((e && e.message) || WORDS.server, 'error');
        });
      });
      row.appendChild(b);
    });
    if (opts.actions && opts.actions.length) {
      foot.appendChild(status);
      foot.appendChild(row);
      panel.appendChild(foot);
    }

    root.appendChild(backdrop);
    root.appendChild(panel);
    (opts.mount || d.body).appendChild(root);

    function setStatus(text, kind) {
      status.className = 'zdz-inline-status zdz-sheet-status' + (text ? ' is-visible' : '') + (kind ? ' is-' + kind : '');
      status.textContent = text || '';
    }

    var resolveClosed;
    var api = {
      el: panel,
      body: body,
      status: setStatus,
      closed: new Promise(function (r) { resolveClosed = r; }),
      _pushed: false,
      _dismissible: dismissible,
      close: function (reason) {
        var i = sheets.indexOf(api);
        if (i === -1) { return; }
        sheets.splice(i, 1);
        d.removeEventListener('keydown', onKey, true);
        root.classList.remove('is-open');
        root.classList.add('is-closing');
        setTimeout(function () { if (root.parentNode) { root.parentNode.removeChild(root); } }, 260);
        if (!sheets.length) { d.documentElement.classList.remove('zdz-sheet-open'); }
        if (api._pushed) {
          api._pushed = false;
          var cur = history.state;
          if (cur && cur.zdzSheet === id) {
            skipNextPop();
            try { history.back(); } catch (e) { unskip(); }
          } else {
            stale[id] = true; // not the current entry: taken back when a pop lands on it
          }
        }
        try { if (lastFocus && lastFocus.focus && d.contains(lastFocus)) { lastFocus.focus(); } } catch (e) { /* gone */ }
        if (typeof opts.onClose === 'function') { opts.onClose(reason || 'api'); }
        resolveClosed(reason || 'api');
      }
    };

    function onKey(e) {
      if (sheets[sheets.length - 1] !== api) { return; }
      if (e.key === 'Escape') {
        // the sheet is on top: Escape is its, even when it must be answered (never the layer under it)
        e.preventDefault(); e.stopPropagation();
        if (dismissible) { api.close('escape'); }
        return;
      }
      if (e.key !== 'Tab') { return; }
      var f = Array.prototype.filter.call(panel.querySelectorAll(FOCUSABLE), function (n) {
        return n.offsetParent !== null || n === d.activeElement;
      });
      if (!f.length) { e.preventDefault(); panel.focus(); return; }
      var first = f[0], last = f[f.length - 1];
      if (e.shiftKey && (d.activeElement === first || d.activeElement === panel)) { e.preventDefault(); last.focus(); }
      else if (!e.shiftKey && d.activeElement === last) { e.preventDefault(); first.focus(); }
    }

    x.addEventListener('click', function () { api.close('close'); });
    backdrop.addEventListener('click', function () { if (dismissible) { api.close('backdrop'); } });
    d.addEventListener('keydown', onKey, true);

    try { history.pushState({ zdzSheet: id }, ''); api._pushed = true; } catch (e) { api._pushed = false; }
    sheets.push(api);
    d.documentElement.classList.add('zdz-sheet-open');
    root.classList.toggle('is-desktop', !!(w.matchMedia && w.matchMedia(DESKTOP).matches));
    // next frame, so the slide-in transition runs
    (w.requestAnimationFrame || setTimeout)(function () {
      root.classList.add('is-open');
      var target = panel.querySelector('[autofocus]') || panel;
      try { target.focus({ preventScroll: true }); } catch (e) { target.focus(); }
    });
    return api;
  }

  // Back with no shell (a wp-admin page inside the app): the kit's own listener.
  if (!w.Bridge) {
    ownPop = true;
    w.addEventListener('popstate', function () {
      if (skipOwn > 0) { skipOwn--; afterPop(); return; }
      popLayer();
    });
  }

  // ------------------------------------------------------------------------------------------------ needs you

  var needs = {}; // appId => count
  var groups = data.appGroups || {};
  var NEEDS_COLOR = hex(groups.needsYou, '#D55E00');
  var NEEDS_INK = hex(groups.needsYouInk, '#FFFFFF');

  function paintNeeds(root) {
    var scope = root || d;
    Object.keys(needs).forEach(function (id) {
      var n = needs[id];
      var plates = scope.querySelectorAll('[data-zdz-app="' + id.replace(/"/g, '') + '"]');
      Array.prototype.forEach.call(plates, function (p) {
        var b = p.querySelector(':scope > .zdz-needs-you');
        if (!n) { if (b) { b.parentNode.removeChild(b); } p.classList.remove('zdz-has-needs'); return; }
        if (!b) {
          b = el('span', 'zdz-needs-you');
          b.style.background = NEEDS_COLOR;
          b.style.color = NEEDS_INK;
          p.appendChild(b);
          if (w.getComputedStyle && w.getComputedStyle(p).position === 'static') { p.classList.add('zdz-has-needs'); }
        }
        var label = n > 99 ? '99+' : String(n);
        if (b.textContent !== label) { b.textContent = label; }
        b.setAttribute('aria-label', n + (n === 1 ? ' item needs you' : ' items need you'));
      });
    });
  }

  var painting = false;
  function schedulePaint() {
    if (painting) { return; }
    painting = true;
    (w.requestAnimationFrame || setTimeout)(function () { painting = false; paintNeeds(); });
  }

  /**
   * ZDZ.ui.needsYou('prep', 3) puts a vermilion "3" on Prep's icon everywhere it is drawn (launcher,
   * dock, recents, search), and keeps it there when the launcher redraws. 0 removes it. Inside an app's own
   * frame it is passed up to the shell. Fires `zdz:needs-you` {appId, count} on window.
   */
  function needsYou(appId, count) {
    appId = String(appId || '').replace(/[^a-z0-9_-]/gi, '');
    if (!appId) { return; }
    count = Math.max(0, parseInt(count, 10) || 0);
    try {
      if (w.parent && w.parent !== w && w.parent.ZDZ && w.parent.ZDZ.ui && w.parent.ZDZ.ui.needsYou) {
        w.parent.ZDZ.ui.needsYou(appId, count);
        return;
      }
    } catch (e) { /* another origin: keep it local */ }
    if (count) { needs[appId] = count; } else { delete needs[appId]; }
    paintNeeds();
    if (count === 0) {
      Array.prototype.forEach.call(d.querySelectorAll('[data-zdz-app="' + appId + '"] > .zdz-needs-you'), function (b) {
        b.parentNode.classList.remove('zdz-has-needs');
        b.parentNode.removeChild(b);
      });
    }
    try { w.dispatchEvent(new CustomEvent('zdz:needs-you', { detail: { appId: appId, count: count } })); } catch (e) { /* old browser */ }
  }

  if (typeof MutationObserver !== 'undefined') {
    var mo = new MutationObserver(function (list) {
      if (!Object.keys(needs).length) { return; }
      for (var i = 0; i < list.length; i++) {
        if (list[i].addedNodes && list[i].addedNodes.length) { schedulePaint(); return; }
      }
    });
    var startMo = function () { mo.observe(d.body, { childList: true, subtree: true }); };
    if (d.body) { startMo(); } else { d.addEventListener('DOMContentLoaded', startMo); }
  }

  // ------------------------------------------------------------------------------------------------ people

  var peopleById = {};
  (data.people || []).forEach(function (p) { peopleById[String(p.id)] = p; });

  /** {id, initials, color, ink} for a user id, or for initials ('FR'); null when unknown. */
  function person(key) {
    if (key == null) { return null; }
    var p = peopleById[String(key)];
    if (p) { return p; }
    var k = String(key).toUpperCase();
    for (var id in peopleById) {
      if (Object.prototype.hasOwnProperty.call(peopleById, id) && String(peopleById[id].initials).toUpperCase() === k) {
        return peopleById[id];
      }
    }
    return null;
  }

  /* the shapes.
     Drawn in a 32×32 box; the initials sit where each shape is widest. */
  var SHAPES = {
    triangle: { d: '<path class="zdz-person-shape" d="M16 2.5 30.2 28.6H1.8Z"/>', y: 22.6, f2: 8.4, f3: 6.6 },
    square:   { d: '<rect class="zdz-person-shape" x="3" y="3" width="26" height="26" rx="5"/>', y: 16, f2: 11.5, f3: 9.2 },
    pentagon: { d: '<path class="zdz-person-shape" d="M16 2.5 29.8 12.5 24.5 28.7H7.5L2.2 12.5Z"/>', y: 17.6, f2: 10.6, f3: 8.4 },
    hexagon:  { d: '<path class="zdz-person-shape" d="M16 1.5 28.6 8.8V23.2L16 30.5 3.4 23.2V8.8Z"/>', y: 16, f2: 11.2, f3: 9 },
    circle:   { d: '<circle class="zdz-person-shape" cx="16" cy="16" r="14"/>', y: 16, f2: 11.5, f3: 9.2 },
    kiosk:    { d: '<rect class="zdz-person-shape" x="2" y="7" width="28" height="18" rx="9"/>', y: 16, f2: 10, f3: 8.4 }
  };
  var SHAPE_LABEL = { triangle: 'Sales, Field Tech, Shop Foreman', square: 'Operator', pentagon: 'Administrator',
    hexagon: 'Owner', circle: 'WordPress Administrator', kiosk: 'Shared kiosk' };

  function badgeSvg(shape, bg, ink, edge, text) {
    var s = SHAPES[shape] || SHAPES.triangle;
    var t = String(text || '');
    var size = t.length >= 3 ? s.f3 : s.f2;
    return '<svg viewBox="0 0 32 32" aria-hidden="true" focusable="false">' +
      '<g fill="' + bg + '" stroke="' + edge + '" stroke-width="1.5" stroke-linejoin="round">' + s.d + '</g>' +
      (t ? '<text x="16" y="' + s.y + '" text-anchor="middle" dominant-baseline="central" fill="' + ink +
        '" font-size="' + size + '">' + esc(t) + '</text>' : '') + '</svg>';
  }

  /** A shape without a person: badge('pentagon') for a role badge or a legend. */
  function badge(shape, opts) {
    opts = opts || {};
    shape = SHAPES[shape] ? shape : 'triangle';
    var bg = hex(opts.color, '#E1E5EA'), ink = hex(opts.ink, '#1F2933'), edge = hex(opts.edge, '#5E6976');
    var label = opts.label || SHAPE_LABEL[shape];
    return '<span class="zdz-person zdz-person--' + shape + (opts.size ? ' zdz-person--' + esc(opts.size) : '') +
      '" role="img" aria-label="' + esc(label) + '" title="' + esc(label) + '">' + badgeSvg(shape, bg, ink, edge, opts.text || '') + '</span>';
  }

  /** The person's initials on their shape and tone — the same badge in every app. Unknown → plain gray. */
  function personChip(key, opts) {
    opts = opts || {};
    var p = person(key);
    var ini = p ? p.initials : String(key || '?').slice(0, 3).toUpperCase();
    var shape = p && SHAPES[p.shape] ? p.shape : (p ? 'triangle' : 'kiosk');
    var bg = p ? hex(p.color, '#EEF0F3') : '#EEF0F3';
    var ink = p ? hex(p.ink, '#1F2933') : '#4A5560';
    var edge = p ? hex(p.edge, '#5E6976') : '#B8C0C9';
    var label = ini + (p ? ' · ' + SHAPE_LABEL[shape] : '');
    return '<span class="zdz-person zdz-person--' + shape + (opts.size ? ' zdz-person--' + esc(opts.size) : '') +
      '" role="img" aria-label="' + esc(label) + '" title="' + esc(opts.title || label) + '">' +
      badgeSvg(shape, bg, ink, edge, ini) + '</span>';
  }

  // ------------------------------------------------------------------------------------------------ paper

  /**
   * ZDZ.ui.paper(doc, opts) — an estimate or invoice drawn like the billing system's own PDF, so Messages, Jobs
   * and a homeowner's project page draw the same paper.
   *
   * doc — the shape the estimate app's document-for-viewer endpoint and the charts invoice card both produce:
   *   { kind: 'estimate'|'invoice' (missing = invoice), number, status, issued, due, reference, currency, billed_to: [lines],
   *     lines: [{ name, description, rate, qty, amount }], totals: { subtotal, discount, tax, total, paid, due } | null,
   *     notes, terms, money, business: { name, lines: [] } | null, logo: 'https://…' | '' }
   * opts — { print: true, note: '' (one line above the paper), label: '' (what a screen reader calls it),
   *          copy: function () → Promise of the customer's link }
   *   doc.draft — true for a document not sent yet: it has no customer link, so the bar says so instead of a button.
   *
   * The paper never links out to the billing system. A caller that may hand out the customer's share link passes
   * opts.copy, a function that asks its own server for it. The kit draws "Copy customer link", copies inside the tap
   * (ClipboardItem with a promise, then writeText, then a read-only box to select), and only ever copies a whole
   * address matching one of the billing connector's customer-link patterns (zdzData.customerLinkPatterns): it never
   * opens it and never puts it in an <a>. A rejection's { text } is shown as the reason.
   *
   * Text nodes only: nothing from a document is ever read as HTML. Amounts appear only when doc.money is true.
   * Returns the element (a wrapper holding a small bar and the paper); the caller places it.
   */
  function cash2(v) {
    var n = Number(v);
    if (!isFinite(n)) { return ''; }
    return n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }
  function usDate(v) {
    var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(v || ''));
    return m ? m[2] + '/' + m[3] + '/' + m[1] : String(v || '');
  }
  function have(v) { return v !== null && v !== undefined && v !== ''; }
  function paper(doc, opts) {
    doc = doc || {};
    opts = opts || {};
    var inv = doc.kind !== 'estimate';   // the charts invoice shape has no kind; anything not an estimate is an invoice
    var noun = inv ? 'Invoice' : 'Estimate';
    var cur = String(doc.currency || 'USD');
    var T = doc.totals || null;
    var money = !!doc.money && !!T;
    var wrap = el('div', 'zdz-paper-wrap');
    var page = el('div', 'zdz-paper' + (inv ? '' : ' is-estimate'));
    page.setAttribute('role', 'document');
    page.setAttribute('aria-label', opts.label || (noun + ' ' + (doc.number || '')));

    var biz = doc.business || null;
    var logo = /^https:\/\//i.test(String(doc.logo || '')) ? String(doc.logo) : '';
    if (logo || (biz && (biz.name || (biz.lines || []).length))) {
      var top = el('div', 'zdz-paper-top');
      if (logo) {
        var img = el('img', 'zdz-paper-logo');
        img.src = logo;
        img.alt = (biz && biz.name) || 'Logo';
        top.appendChild(img);
      } else {
        top.appendChild(el('span'));
      }
      if (biz) {
        var b = el('div', 'zdz-paper-biz');
        [biz.name].concat(biz.lines || []).filter(have).forEach(function (x) {
          b.appendChild(el('div', x === biz.name ? 'zdz-paper-biz-name' : '', String(x)));
        });
        top.appendChild(b);
      }
      page.appendChild(top);
    }

    function field(label, value) {
      var f = el('div', 'zdz-paper-field');
      f.appendChild(el('div', 'zdz-paper-label', label));
      f.appendChild(el('div', 'zdz-paper-value', have(value) ? String(value) : ''));
      return f;
    }
    // an estimate is laid out as the billing system's estimate PDF is: "Prepared For",
    // "Estimate Date", no big amount at the top, and the totals end on "Estimate Total (USD) $…".
    var due = null;
    if (money && inv) {
      due = have(T.due) ? T.due : (have(T.total) ? Number(T.total) - Number(T.paid || 0) : null);
    }
    var meta = el('div', 'zdz-paper-meta');
    var billed = el('div', 'zdz-paper-billed');
    billed.appendChild(el('div', 'zdz-paper-label', inv ? 'Billed To' : 'Prepared For'));
    (doc.billed_to || []).forEach(function (x) { billed.appendChild(el('div', 'zdz-paper-value', String(x))); });
    meta.appendChild(billed);
    var c1 = el('div', 'zdz-paper-col');
    c1.appendChild(field(inv ? 'Date of Issue' : 'Estimate Date', usDate(doc.issued)));
    if (inv && doc.due) { c1.appendChild(field('Due Date', usDate(doc.due))); }
    meta.appendChild(c1);
    var c2 = el('div', 'zdz-paper-col');
    c2.appendChild(field(noun + ' Number', doc.number || ''));
    if (doc.reference) { c2.appendChild(field('Reference', doc.reference)); }
    meta.appendChild(c2);
    // Only when there is an amount to show: an empty cell made a blank full-width row on a phone.
    // An estimate has none; on a wide screen its fourth column stays white, as on the estimate PDF.
    if (inv && money && have(due)) {
      var big = el('div', 'zdz-paper-due');
      big.appendChild(el('div', 'zdz-paper-label', 'Amount Due (' + cur + ')'));
      big.appendChild(el('div', 'zdz-paper-big', '$' + cash2(due)));
      meta.appendChild(big);
    }
    page.appendChild(meta);

    var table = el('table', 'zdz-paper-lines');
    var thead = el('thead');
    var hr = el('tr');
    hr.appendChild(el('th', '', 'Description'));
    ['Rate', 'Qty', 'Line Total'].forEach(function (h) { hr.appendChild(el('th', 'num', h)); });
    thead.appendChild(hr);
    table.appendChild(thead);
    var tb = el('tbody');
    (doc.lines || []).forEach(function (l) {
      var tr = el('tr');
      var d0 = el('td', 'zdz-paper-desc');
      d0.appendChild(el('div', 'zdz-paper-item', String(l.name || '')));
      if (l.description) { d0.appendChild(el('div', 'zdz-paper-item-desc', String(l.description))); }
      tr.appendChild(d0);
      var cells = [['Rate', money && have(l.rate) ? '$' + cash2(l.rate) : ''],
        ['Qty', have(l.qty) ? String(l.qty) : ''],
        ['Line Total', money && have(l.amount) ? '$' + cash2(l.amount) : '']];
      cells.forEach(function (c) {
        var td = el('td', 'num', c[1]);
        td.setAttribute('data-label', c[0]);
        tr.appendChild(td);
      });
      tb.appendChild(tr);
    });
    table.appendChild(tb);
    page.appendChild(table);

    if (money) {
      var tot = el('div', 'zdz-paper-totals');
      var row = function (label, v, cls) {
        var r = el('div', 'zdz-paper-trow' + (cls ? ' ' + cls : ''));
        r.appendChild(el('span', '', label));
        r.appendChild(el('span', 'num', have(v) ? cash2(v) : ''));
        tot.appendChild(r);
      };
      var any = false;
      if (have(T.subtotal)) { row('Subtotal', T.subtotal); any = true; }
      if (have(T.discount) && Number(T.discount)) { row('Discount', -Math.abs(Number(T.discount))); any = true; }
      if (have(T.tax)) { row('Tax', T.tax); any = true; }
      if (inv && have(T.total)) { row('Total', T.total, any ? 'is-rule' : ''); }
      if (!inv && have(T.total)) {
        var et = el('div', 'zdz-paper-trow is-due');
        et.appendChild(el('span', '', 'Estimate Total (' + cur + ')'));
        et.appendChild(el('span', 'num', '$' + cash2(T.total)));
        tot.appendChild(et);
      }
      if (inv && have(T.paid)) { row('Amount Paid', T.paid); }
      if (inv && have(due)) {
        var r2 = el('div', 'zdz-paper-trow is-due');
        r2.appendChild(el('span', '', 'Amount Due (' + cur + ')'));
        r2.appendChild(el('span', 'num', '$' + cash2(due)));
        tot.appendChild(r2);
      }
      page.appendChild(tot);
    }
    [['Notes', doc.notes], ['Terms', doc.terms]].forEach(function (n) {
      if (!n[1]) { return; }
      var box = el('div', 'zdz-paper-notes');
      box.appendChild(el('div', 'zdz-paper-label', n[0]));
      box.appendChild(el('div', 'zdz-paper-value', String(n[1])));
      page.appendChild(box);
    });

    var bar = el('div', 'zdz-paper-bar');
    if (opts.note) { bar.appendChild(el('span', 'zdz-paper-note', String(opts.note))); }
    if (opts.print !== false && typeof w.print === 'function') {
      var pb = el('button', 'zdz-btn zdz-btn--secondary zdz-btn--sm zdz-paper-print', 'Print');
      pb.type = 'button';
      pb.addEventListener('click', function () { printPaper(page); });
      bar.appendChild(pb);
    }
    // opts.link is ignored -- never a link out. The customer's link is copied, never opened.
    if (doc.draft && typeof opts.copy === 'function') {
      bar.appendChild(el('span', 'zdz-paper-copy-note', 'Still a draft: no customer link yet.'));
    } else if (typeof opts.copy === 'function') {
      var cp = el('button', 'zdz-btn zdz-btn--secondary zdz-btn--sm zdz-paper-copy', 'Copy customer link');
      cp.type = 'button';
      var cnote = el('span', 'zdz-paper-copy-note');
      cnote.setAttribute('role', 'status');
      cp.addEventListener('click', function () { copyLink(cp, cnote, opts.copy, wrap); });
      bar.appendChild(cp);
      bar.appendChild(cnote);
    }
    if (bar.childNodes.length) { wrap.appendChild(bar); }
    wrap.appendChild(page);
    return wrap;
  }
  // The billing connector's customer-link shapes (regex sources, from the server), read on first use.
  // None set: nothing is copied. A bad pattern is skipped, never trusted.
  var LINK_RES = null;
  function linkRes() {
    if (LINK_RES) { return LINK_RES; }
    var src = (w.zdzData && w.zdzData.customerLinkPatterns) || [];
    LINK_RES = [];
    for (var i = 0; i < src.length; i++) {
      try { LINK_RES.push(new RegExp(String(src[i]), 'i')); } catch (e) { /* skipped */ }
    }
    return LINK_RES;
  }
  var LINK_RE = { test: function (s) { var r = linkRes(); for (var i = 0; i < r.length; i++) { if (r[i].test(s)) { return true; } } return false; } };
  var NO_LINK = 'The billing system didn\u2019t give a customer link for this document just now. Try again in a minute.';
  function copyLink(cp, note, ask, wrap) {
    if (cp.__busy) { return; }
    cp.__busy = true; cp.disabled = true;
    note.textContent = 'Getting the link\u2026';
    var done = function (msg) { note.textContent = msg; cp.__busy = false; cp.disabled = false; };
    var got;
    try { got = Promise.resolve(ask()); } catch (e) { got = Promise.reject(e); }
    // a caller's request that never answers must not leave the button waiting for ever
    got = Promise.race([got, new Promise(function (res, rej) { setTimeout(function () { rej({ text: NO_LINK }); }, 30000); })]);
    got = got.then(function (link) {
      if (typeof link === 'string' && LINK_RE.test(link)) { return link; }
      throw { text: NO_LINK };
    });
    var showBox = function (link) {
      var old = wrap.querySelector('.zdz-paper-copy-box');
      if (old) { old.parentNode.removeChild(old); }
      var box = el('input', 'zdz-paper-copy-box');
      box.type = 'text'; box.readOnly = true; box.value = link;
      box.setAttribute('aria-label', 'Customer link');
      wrap.insertBefore(box, wrap.children[1] || null);
      try { box.focus(); box.select(); } catch (e) { /* older browsers */ }
      done('Select the link and copy it.');
    };
    var copied = function () { done('Copied. Paste it into your text or email.'); };
    var failed = function (err) { done((err && typeof err.text === 'string' && err.text) || NO_LINK); };
    var nav = w.navigator || {};
    var viaItem = null;
    try {
      if (w.ClipboardItem && nav.clipboard && typeof nav.clipboard.write === 'function') {
        var blob = got.then(function (l) { return new Blob([l], { type: 'text/plain' }); });
        blob.catch(function () { /* the refusal is shown by fallback(); this copy of it needs no second reader */ });
        viaItem = nav.clipboard.write([new w.ClipboardItem({ 'text/plain': blob })]);
      }
    } catch (e0) { viaItem = null; }
    var fallback = function () {
      got.then(function (link) {
        if (nav.clipboard && typeof nav.clipboard.writeText === 'function') {
          nav.clipboard.writeText(link).then(copied, function () { showBox(link); });
        } else {
          showBox(link);
        }
      }, failed);
    };
    got.catch(function () { /* each path below reads the refusal; never an unhandled rejection */ });
    if (viaItem) { viaItem.then(function () { return got.then(copied); }, fallback); } else { fallback(); }
  }
  /** 2.93.0: the blank sheet, the size of a document, under a bar with Print waiting; the caller swaps in ZDZ.ui.paper. */
  function paperGhost(text) {
    var wrap = el('div', 'zdz-paper-wrap');
    var bar = el('div', 'zdz-paper-bar');
    var pb = el('button', 'zdz-btn zdz-btn--secondary zdz-btn--sm zdz-paper-print', 'Print');
    pb.type = 'button'; pb.disabled = true;
    bar.appendChild(pb);
    wrap.appendChild(bar);
    var g = el('div', 'zdz-paper-ghost');
    g.setAttribute('aria-busy', 'true');
    g.appendChild(el('p', 'zdz-paper-ghost-msg', String(text || 'Opening\u2026')));
    wrap.appendChild(g);
    return wrap;
  }
  // Prints a copy placed straight under <body>: the paper usually sits in a panel that clips it (overflow, a
  // fixed height) and in a container-query box, either of which would cut or shift the printout.
  function printPaper(page) {
    var body = d.body;
    if (!body) { return; }
    var copy = page.cloneNode(true);
    copy.classList.add('is-printing', 'zdz-paper-print-copy');
    body.appendChild(copy);
    body.classList.add('zdz-paper-printing');
    var done = function () {
      if (copy.parentNode) { copy.parentNode.removeChild(copy); }
      body.classList.remove('zdz-paper-printing');
      w.removeEventListener('afterprint', done);
    };
    w.addEventListener('afterprint', done);
    w.print();
    setTimeout(done, 1000);
  }

  // ------------------------------------------------------------------------------------------------ marks

  /*
   * ZDZ.ui.marks — drawings on a photo, kept apart from the photo.
   *
   * one set of lines per photo, drawn by its owner or an administrator.
   * anyone who sees a photo draws on it, and each person's drawing is
   * their own LAYER - seen by them alone until they share it (only me / everyone who can see the photo / chosen people /
   * the Project's people); a line can be an arrow ending in a Post-it that holds a note; administrators can choose to see
   * everyone's. The theme decides who sees what (ZDZ_Media_Marks); this draws what it is sent.
   *
   * Points are whole-number fractions (0..10000) of the photo's own width and height, drawn as an SVG laid exactly over
   * the viewer's <img>: the same box, a viewBox of the photo's natural size with the same fit as the image, and the
   * image's own transform copied across, so zoom, pan and the rotate preview carry the drawings along.
   *
   *   var layer = ZDZ.ui.marks.attach(img);   one per <img>; attach again returns the same one
   *   layer.load(id, { query })              fetch and draw the drawings this person sees (query: a plugin's grant)
   *   layer.set(view)                        draw a view already in hand (null draws none)
   *   layer.count() / .has() / .mine()       how many drawings are shown / any / this person's own
   *   layer.toggle(key, on) / .hide(on)      show or hide one drawing / all of them
   *   layer.on(fn)                           told whenever what is shown changes (to label a Drawings button)
   *   layer.edit({ id, context, query })     Draw: pens, widths, Note, Who sees it, Undo, Clear, Cancel, Save
   *   ZDZ.ui.marks.panel(layer, opts)         the Drawings list: who drew what, show/hide, your sharing, admin choice
   *   ZDZ.ui.marks.outline(el, n, notes)      the outline on a photo tile that has drawings
   *   ZDZ.ui.marks.editing()                  true while a person is drawing
   *
   * Post-its are the one part of a drawing that takes a tap (a card opens with the note, who wrote it and when). While
   * drawing, every tap, key and gesture outside the bar is held back at the window, before any viewer sees it.
   */
  var SVGNS = 'http://www.w3.org/2000/svg';
  var MARK_PEN = { r: '#E53935', y: '#FDD835', b: '#1E88E5', w: '#FFFFFF', k: '#111111' };
  var MARK_PEN_NAME = { r: 'Red', y: 'Yellow', b: 'Blue', w: 'White', k: 'Black' };
  var MARK_WIDTH = { 1: 0.006, 2: 0.012, 3: 0.022 };       // of the photo's short side, which a turn keeps
  var MARK_SCALE = 10000;
  var MARK_MAX_POINTS = 1000;   // the server's limits (ZDZ_Media_Marks), so a drawing the bar accepts is one it can save
  var MARK_MAX_STROKES = 100;
  var MARK_MAX_TOTAL = 6000;    // per person's drawing
  var MARK_MAX_NOTES = 20;
  var MARK_NOTE_MAX = 500;
  var MARK_MIN_STEP = 12;                                   // in 1/10000ths: closer points add nothing you can see
  var MARK_POSTIT = '#FFD54F';
  var MARK_SHARE_WORDS = {
    'private': 'Only you',
    team: 'Everyone who can see this photo',
    people: 'Chosen people',
    project: "This Project's people"
  };
  var marking = null;                                       // the layer being drawn on, if any

  function own(o, k) { return Object.prototype.hasOwnProperty.call(o, String(k)); }

  function markId() {
    var s = '';
    var a = 'abcdefghijklmnopqrstuvwxyz0123456789';
    var r = (w.crypto && w.crypto.getRandomValues) ? w.crypto.getRandomValues(new Uint8Array(10)) : null;
    for (var i = 0; i < 10; i++) { s += a.charAt(r ? r[i] % 36 : Math.floor(Math.random() * 36)); }
    return s;
  }

  /** Lines from a server, kept only if they have the shape the server promises (anything else draws nothing). */
  function markStrokes(list) {
    var out = [];
    (Array.isArray(list) ? list : []).forEach(function (s) {
      // own keys only: "constructor" is truthy on any object
      if (!s || !own(MARK_PEN, s.c) || !own(MARK_WIDTH, s.s) || !Array.isArray(s.p) || s.p.length < 2 || s.p.length % 2) { return; }
      if (!/^[a-z0-9]{6,16}$/.test(String(s.id || ''))) { return; }
      var k = s.k === 'a' ? 'a' : 'l';
      if (k === 'a' && s.p.length !== 4) { return; }
      var p = [];
      for (var i = 0; i < s.p.length; i++) {
        var n = Number(s.p[i]);
        if (!isFinite(n)) { return; }
        p.push(Math.max(0, Math.min(MARK_SCALE, Math.round(n))));
      }
      var one = { id: String(s.id), c: s.c, s: s.s, p: p, k: k };
      if (k === 'a' && typeof s.n === 'string' && s.n !== '') { one.n = s.n.slice(0, MARK_NOTE_MAX); }
      out.push(one);
    });
    return out;
  }

  function markPerson(p) {
    return p && typeof p === 'object' ? { id: p.id | 0, name: String(p.name || ''), initials: String(p.initials || '?') } : null;
  }

  /** A view from the server (or the older one-set shape of lines), made safe. */
  function markView(v) {
    var out = { id: 0, layers: [], can_mark: false, is_admin: false, show_all: false, hidden_n: 0, my_rev: 0 };
    if (!v || typeof v !== 'object') { return out; }
    if (Array.isArray(v.layers)) {
      out.id = v.id | 0;
      out.can_mark = !!v.can_mark;
      out.is_admin = !!v.is_admin;
      out.show_all = !!v.show_all;
      out.hidden_n = Math.max(0, v.hidden_n | 0);
      out.my_rev = Math.max(0, v.my_rev | 0);   // this person's revision when they have no drawing here (cleared)
      v.layers.forEach(function (l) {
        if (!l || typeof l !== 'object') { return; }
        var strokes = markStrokes(l.strokes);
        if (!strokes.length) { return; }
        out.layers.push({
          key: String(l.key || ''),
          mine: !!l.mine,
          seen_as: String(l.seen_as || ''),
          name: String(l.name || ''),
          initials: String(l.initials || '?'),
          at: String(l.at || ''),
          share: own(MARK_SHARE_WORDS, l.share) ? l.share : 'private',
          rev: l.rev | 0,
          context: typeof l.context === 'string' ? l.context : '',
          people: Array.isArray(l.people) ? l.people.map(markPerson).filter(Boolean) : [],
          strokes: strokes
        });
      });
      return out;
    }
    if (Array.isArray(v.strokes)) { // the older shape: one set, nobody's in particular
      var s = markStrokes(v.strokes);
      if (s.length) { out.layers.push({ key: 'v1', mine: false, seen_as: 'team', name: '', initials: '', at: '', share: 'team', rev: v.rev | 0, context: '', people: [], strokes: s }); }
    }
    return out;
  }

  function markQuery(q) {
    if (!q || typeof q !== 'object') { return ''; }
    var parts = [];
    Object.keys(q).forEach(function (k) {
      if (/^[a-z_]{1,20}$/.test(k) && q[k] != null && q[k] !== '') { parts.push(encodeURIComponent(k) + '=' + encodeURIComponent(String(q[k]))); }
    });
    return parts.length ? '?' + parts.join('&') : '';
  }

  /** An app's grant on the photo's own address (the jobs app's zprj_g / zprj_e), for the drawing calls to carry. */
  function markGrantFrom(url) {
    var q = {};
    String(url || '').replace(/[?&](zprj_g|zprj_e)=([^&#]*)/g, function (m, k, v) { q[k] = decodeURIComponent(v); return m; });
    return q;
  }

  function markWhen(at) {
    var m = /^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/.exec(String(at || ''));
    if (!m) { return ''; }
    var dt = new Date(+m[1], +m[2] - 1, +m[3], +m[4], +m[5]);
    try { return dt.toLocaleString(undefined, { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' }); } catch (e) { return m[2] + '/' + m[3] + ' ' + m[4] + ':' + m[5]; }
  }

  function markLayer(img) {
    if (!img || img.nodeType !== 1) { return null; }
    if (img._zdzMarks) { return img._zdzMarks; }
    var svg = d.createElementNS(SVGNS, 'svg');
    svg.setAttribute('class', 'zdz-marks');
    svg.setAttribute('aria-hidden', 'true');
    svg.setAttribute('focusable', 'false');
    svg.style.display = 'none';
    var g = d.createElementNS(SVGNS, 'g');
    svg.appendChild(g);
    var L = { img: img, svg: svg, view: markView(null), off: {}, hidden: false, editing: false, draft: null, natW: 0, natH: 0, photoId: 0, _seq: 0, _subs: [] };
    img._zdzMarks = L;

    function place() {
      var parent = img.parentNode;
      if (!parent) { return; }
      if (svg.parentNode !== parent || svg.previousSibling !== img) {
        try { if (w.getComputedStyle(parent).position === 'static') { parent.style.position = 'relative'; } } catch (e) { /* no styles yet */ }
        parent.insertBefore(svg, img.nextSibling);
      }
    }

    // On screen at all (the viewer has not closed or hidden it)...
    function there() {
      return !!(img.isConnected && img.offsetWidth && img.offsetHeight && img.getAttribute('src'));
    }
    // ... and its picture is loaded, so its size is known.
    function shown() {
      return !!(there() && img.complete && img.naturalWidth);
    }

    /** The drawings to draw now: every shown layer; while drawing, this person's draft in place of their layer. */
    function layersNow() {
      var out = [];
      var mineDone = false;
      L.view.layers.forEach(function (l) {
        if (L.editing && l.mine) { mineDone = true; out.push({ key: l.key, mine: true, strokes: L.draft || [], layer: l }); return; }
        if (L.off[l.key]) { return; }
        out.push({ key: l.key, mine: l.mine, strokes: l.strokes, layer: l });
      });
      if (L.editing && !mineDone) { out.unshift({ key: 'mine', mine: true, strokes: L.draft || [], layer: null }); }
      return out;
    }

    function pathFor(s, W, H) {
      var pts = [];
      for (var i = 0; i + 1 < s.p.length; i += 2) {
        pts.push((s.p[i] / MARK_SCALE * W).toFixed(1) + ',' + (s.p[i + 1] / MARK_SCALE * H).toFixed(1));
      }
      if (pts.length === 1) { pts.push(pts[0]); } // a tap is a dot
      return pts.join(' ');
    }

    function line(s, W, H, under) {
      var n = d.createElementNS(SVGNS, 'polyline');
      var width = (MARK_WIDTH[s.s] || MARK_WIDTH[2]) * Math.min(W, H);
      n.setAttribute('points', pathFor(s, W, H));
      n.setAttribute('fill', 'none');
      n.setAttribute('stroke-linecap', 'round');
      n.setAttribute('stroke-linejoin', 'round');
      if (under) { // a soft edge so a yellow or white line reads on a bright wall
        n.setAttribute('stroke', 'rgba(0,0,0,0.35)');
        n.setAttribute('stroke-width', (width * 1.45).toFixed(1));
      } else {
        n.setAttribute('stroke', MARK_PEN[s.c] || MARK_PEN.r);
        n.setAttribute('stroke-width', width.toFixed(1));
      }
      return n;
    }

    /** An arrow: its head at the first point (the part it points at), its tail at the second, where a Post-it sits. */
    function arrow(s, W, H, gg, mine, key) {
      var x0 = s.p[0] / MARK_SCALE * W, y0 = s.p[1] / MARK_SCALE * H;
      var x1 = s.p[2] / MARK_SCALE * W, y1 = s.p[3] / MARK_SCALE * H;
      var len = Math.sqrt((x1 - x0) * (x1 - x0) + (y1 - y0) * (y1 - y0));
      var width = (MARK_WIDTH[s.s] || MARK_WIDTH[2]) * Math.min(W, H);
      var postit = 0.06 * Math.min(W, H);
      if (len > postit * 0.6) {
        if (s.c !== 'k') { gg.appendChild(line(s, W, H, true)); }
        gg.appendChild(line(s, W, H, false));
        var ux = (x0 - x1) / len, uy = (y0 - y1) / len;           // pointing toward the head
        var hl = Math.max(width * 3.2, Math.min(W, H) * 0.025), hw = hl * 0.6;
        var bx = x0 - ux * hl, by = y0 - uy * hl;
        var head = d.createElementNS(SVGNS, 'polygon');
        head.setAttribute('points', [x0.toFixed(1) + ',' + y0.toFixed(1), (bx - uy * hw).toFixed(1) + ',' + (by + ux * hw).toFixed(1), (bx + uy * hw).toFixed(1) + ',' + (by - ux * hw).toFixed(1)].join(' '));
        head.setAttribute('fill', MARK_PEN[s.c] || MARK_PEN.r);
        head.setAttribute('stroke', 'rgba(0,0,0,0.35)');
        head.setAttribute('stroke-width', (width * 0.25).toFixed(1));
        gg.appendChild(head);
      }
      if (s.n) {
        var note = d.createElementNS(SVGNS, 'g');
        note.setAttribute('class', 'zdz-marks-note' + (mine ? ' is-mine' : ''));
        note.setAttribute('data-key', key);
        note.setAttribute('data-id', s.id);
        var h = postit / 2, f = postit * 0.28;
        var paper = d.createElementNS(SVGNS, 'path');
        paper.setAttribute('d', 'M' + (x1 - h) + ' ' + (y1 - h) + ' h' + postit + ' v' + (postit - f) + ' l' + (-f) + ' ' + f + ' h' + (-(postit - f)) + ' z');
        paper.setAttribute('fill', MARK_POSTIT);
        paper.setAttribute('stroke', 'rgba(0,0,0,0.45)');
        paper.setAttribute('stroke-width', (postit * 0.04).toFixed(1));
        note.appendChild(paper);
        var fold = d.createElementNS(SVGNS, 'path');
        fold.setAttribute('d', 'M' + (x1 + h - f) + ' ' + (y1 + h) + ' v' + (-f) + ' h' + f);
        fold.setAttribute('fill', 'rgba(0,0,0,0.18)');
        note.appendChild(fold);
        for (var r = 0; r < 3; r++) { // three ruled lines say "there is writing here"
          var ln = d.createElementNS(SVGNS, 'line');
          var yy = y1 - h + postit * (0.3 + r * 0.2);
          ln.setAttribute('x1', (x1 - h + postit * 0.18).toFixed(1));
          ln.setAttribute('x2', (x1 + h - postit * (r === 2 ? 0.4 : 0.18)).toFixed(1));
          ln.setAttribute('y1', yy.toFixed(1));
          ln.setAttribute('y2', yy.toFixed(1));
          ln.setAttribute('stroke', 'rgba(0,0,0,0.45)');
          ln.setAttribute('stroke-width', (postit * 0.035).toFixed(1));
          note.appendChild(ln);
        }
        var t = d.createElementNS(SVGNS, 'title');
        t.textContent = 'Note';
        note.appendChild(t);
        gg.appendChild(note);
      } else if (len <= postit * 0.6) { // an arrow with no length and no note: a dot, so it is not lost
        gg.appendChild(line({ c: s.c, s: s.s, p: [s.p[0], s.p[1]] }, W, H, false));
      }
    }

    L.render = function () {
      var W = L.natW, H = L.natH;
      while (g.firstChild) { g.removeChild(g.firstChild); }
      if (!W || !H) { return; }
      layersNow().forEach(function (one) {
        var gg = d.createElementNS(SVGNS, 'g');
        gg.setAttribute('data-layer', one.key);
        one.strokes.forEach(function (s) {
          if (s.k === 'a') { arrow(s, W, H, gg, one.mine, one.key); return; }
          if (s.c !== 'k') { gg.appendChild(line(s, W, H, true)); }
          var n = line(s, W, H, false);
          if (L.editing && L.cur && s === L.cur) { L.curNode = n; }
          gg.appendChild(n);
        });
        g.appendChild(gg);
      });
    };

    function anyShown() {
      return layersNow().some(function (one) { return one.strokes.length > 0; });
    }

    L.sync = function () {
      place();
      // The viewer closed or hid the photo under the pen: the drawing ends. A photo still LOADING is not that: Media paints
      // the thumbnail and swaps the original in, and a drawing in progress must survive the swap.
      if (L.editing && !there()) { L.cancel(); return; }
      if (L.editing && !shown()) { return; }
      if (!there()) { markOwnClose(L); }   // the viewer closed: its note card AND its Drawings list go
      if (!shown() || L.hidden || (!anyShown() && !L.editing)) { svg.style.display = 'none'; return; }
      var W = img.naturalWidth, H = img.naturalHeight;
      if (W !== L.natW || H !== L.natH) { L.natW = W; L.natH = H; svg.setAttribute('viewBox', '0 0 ' + W + ' ' + H); L.render(); }
      var cs = w.getComputedStyle(img);
      var fit = cs.objectFit || 'fill';
      var pos = (cs.objectPosition || '50% 50%').split(' ');
      var ax = pos[0] === '0%' || pos[0] === 'left' ? 'xMin' : (pos[0] === '100%' || pos[0] === 'right' ? 'xMax' : 'xMid');
      var ay = pos[1] === '0%' || pos[1] === 'top' ? 'YMin' : (pos[1] === '100%' || pos[1] === 'bottom' ? 'YMax' : 'YMid');
      svg.setAttribute('preserveAspectRatio', fit === 'fill' ? 'none' : (ax + ay + (fit === 'cover' ? ' slice' : ' meet')));
      svg.style.left = img.offsetLeft + 'px';
      svg.style.top = img.offsetTop + 'px';
      svg.style.width = img.offsetWidth + 'px';
      svg.style.height = img.offsetHeight + 'px';
      svg.style.transition = img.style.transition || '';
      svg.style.transformOrigin = cs.transformOrigin || '';
      var tf = img.style.transform || (cs.transform !== 'none' ? cs.transform : '');
      svg.style.transform = tf;
      svg.style.display = '';
    };

    function notify() {
      L._subs.slice().forEach(function (fn) { try { fn(L); } catch (e) { /* a viewer's own trouble */ } });
    }
    L.on = function (fn) { if (typeof fn === 'function') { L._subs.push(fn); } return L; };

    L._unwait = function () {};
    /*
     * set() from a viewer ends any load still on its way (a late answer never draws over a photo
     * set since), and an answer that belongs to a photo the layer has left is dropped (a share saved, then Next). load()
     * draws its own answers through apply().
     */
    L.set = function (v) {
      L._seq++;
      L._loading = null;
      L._waitTok = null;   // a Draw waiting for the drawings of the last photo is dropped
      return apply(v);
    };
    L._answer = function (pid, v) {   // a reply to something asked about photo pid: drawn only while that photo is shown
      if ((pid | 0) !== L.photoId) { return false; }
      apply(v);
      return true;
    };
    function apply(v) {
      L._unwait(); // another photo (or this one again): a Draw still waiting for the last one is dropped
      var view = markView(v);
      if (view.id !== L.photoId) {
        L.off = {};
        if (markPop && markPop.owner === L) { markPopClose(); }
      }
      L.photoId = view.id;   // set(null) leaves no photo behind for a late answer to land on (re-check, MEDIUM)
      L.view = view;
      L.natW = 0; // re-read the photo's size: a new photo, or the full one after its thumb
      markCardClose(L);
      L.render();
      L.sync();
      notify();
      return L;
    }
    /** Fetch the drawings this person sees on photo id and draw them; a slower answer for an earlier photo is dropped. */
    L.load = function (id, opts) {
      opts = opts || {};
      var seq = ++L._seq;
      if ((id | 0) !== L.photoId) {
        // another photo: what was hidden on the last one, and its Drawings list or note card, belong to that one
        L.off = {};
        if (markPop && markPop.owner === L) { markPopClose(); }
      }
      L.photoId = id | 0;
      L.query = opts.query || markGrantFrom(img.getAttribute('src'));
      apply({ id: id | 0, layers: [] });
      var got = rest('zorderz/v1/media/' + (id | 0) + '/marks' + markQuery(L.query), { method: 'GET' }).then(function (v) {
        if (seq !== L._seq) { return null; }
        L._loading = null;
        apply(v);
        return L.view;
      }, function () {
        if (seq === L._seq) { L._loading = null; apply({ id: id | 0, layers: [] }); }
        return null;
      });
      L._loading = got;
      return got;
    };
    L.hide = function (on) { L.hidden = !!on; L.sync(); notify(); return L; };
    L.toggle = function (key, on) {
      if (on) { delete L.off[key]; } else { L.off[key] = true; }
      L.render();
      L.sync();
      notify();
      return L;
    };
    L.shown = function (key) { return !L.off[key]; };
    L.has = function () { return L.view.layers.length > 0; };
    L.count = function () { return L.view.layers.length; };
    L.notes = function () { var n = 0; L.view.layers.forEach(function (l) { l.strokes.forEach(function (s) { if (s.n) { n++; } }); }); return n; };
    L.mine = function () { for (var i = 0; i < L.view.layers.length; i++) { if (L.view.layers[i].mine) { return L.view.layers[i]; } } return null; };
    L.canMark = function () { return !!L.view.can_mark; };

    // A Post-it is the one part that takes a tap outside drawing; nothing under it (the viewer's pan, its close-on-tap)
    // ever sees that tap.
    function noteTarget(e) {
      if (L.editing) { return null; }
      var t = e.target;
      return t && t.closest ? t.closest('.zdz-marks-note') : null;
    }
    function noteStop(e) { if (noteTarget(e)) { e.stopPropagation(); } }
    ['pointerdown', 'mousedown', 'touchstart', 'pointerup', 'mouseup', 'touchend', 'dblclick'].forEach(function (t) {
      svg.addEventListener(t, noteStop, false);
    });
    svg.addEventListener('click', function (e) {
      var n = noteTarget(e);
      if (!n) { return; }
      e.preventDefault();
      e.stopPropagation();
      markCardOpen(L, n.getAttribute('data-key'), n.getAttribute('data-id'), n);
    }, false);

    var ro = typeof ResizeObserver !== 'undefined' ? new ResizeObserver(function () { L.sync(); }) : null;
    if (ro) { ro.observe(img); }
    // Only a new picture means a new size; a pan or a pinch changes only the style.
    var mo = typeof MutationObserver !== 'undefined' ? new MutationObserver(function (list) {
      for (var i = 0; i < list.length; i++) { if (list[i].attributeName === 'src' || list[i].attributeName === 'srcset') { L.natW = 0; break; } }
      L.sync();
    }) : null;
    if (mo) { mo.observe(img, { attributes: true, attributeFilter: ['style', 'class', 'src', 'srcset'] }); }
    function onLoad() { L.natW = 0; L.sync(); }
    function onResize() { L.sync(); }
    img.addEventListener('load', onLoad);
    w.addEventListener('resize', onResize);

    L.edit = function (opts) { return markEdit(L, opts || {}); };
    L.destroy = function () {
      L._unwait();
      L._waitTok = null;
      if (L.editing) { L.cancel(); }
      markOwnClose(L);
      if (ro) { ro.disconnect(); }
      if (mo) { mo.disconnect(); }
      img.removeEventListener('load', onLoad);
      w.removeEventListener('resize', onResize);
      if (svg.parentNode) { svg.parentNode.removeChild(svg); }
      img._zdzMarks = null;
    };
    L.cancel = function () { L._unwait(); L._waitTok = null; markOwnClose(L); if (L._close) { L._close(null); } };

    place();
    L.sync();
    return L;
  }

  // ---- popups: the note card and the Drawings list (above every viewer; a tap or Escape there never reaches it)

  var markPop = null; // { el, close, owner }
  function markPopClose() { if (markPop) { var p = markPop; markPop = null; p.close(); } }
  function markCardClose(L) { if (markPop && markPop.owner === L && markPop.kind === 'card') { markPopClose(); } }
  function markOwnClose(L) { if (markPop && markPop.owner === L) { markPopClose(); } }

  function markPopOpen(node, owner, kind, anchor, onClose) {
    markPopClose();
    var back = d.activeElement;
    node.setAttribute('role', 'dialog');
    ['pointerdown', 'mousedown', 'touchstart', 'pointerup', 'mouseup', 'touchend', 'click', 'dblclick', 'wheel'].forEach(function (t) {
      node.addEventListener(t, function (e) { e.stopPropagation(); }, false);
    });
    d.body.appendChild(node);
    if (anchor && anchor.getBoundingClientRect) {
      var r = anchor.getBoundingClientRect();
      var vw = w.innerWidth || 360, vh = w.innerHeight || 640;
      var bw = node.offsetWidth || 280, bh = node.offsetHeight || 120;
      var left = Math.max(8, Math.min(vw - bw - 8, r.left + r.width / 2 - bw / 2));
      var top = r.bottom + 8 + bh > vh - 8 ? Math.max(8, r.top - bh - 8) : r.bottom + 8;
      node.style.left = Math.round(left) + 'px';
      node.style.top = Math.round(top) + 'px';
    }
    function outside(e) {
      if (node.contains(e.target)) { return; }
      if (anchor && anchor.contains && anchor.contains(e.target)) { return; }   // its own button toggles it
      markPopClose();
    }
    function key(e) {
      if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); markPopClose(); }
    }
    w.addEventListener('pointerdown', outside, true);
    w.addEventListener('keydown', key, true);
    markPop = {
      el: node, owner: owner, kind: kind,
      close: function () {
        w.removeEventListener('pointerdown', outside, true);
        w.removeEventListener('keydown', key, true);
        var hadFocus = node.contains(d.activeElement);
        if (node.parentNode) { node.parentNode.removeChild(node); }
        if (typeof onClose === 'function') { try { onClose(); } catch (e) { /* a viewer's own trouble */ } }
        // focus goes back where it was (the Drawings button, the Post-it), not to the page
        if (hadFocus && back && back.isConnected && back.focus) { try { back.focus({ preventScroll: true }); } catch (e) { /* gone */ } }
      }
    };
    var f = node.querySelector('button');
    if (f) { try { f.focus({ preventScroll: true }); } catch (e) { /* not focusable */ } }
    return markPop;
  }

  /** The note card: what the Post-it says, who wrote it and when. Text only (never markup). */
  function markCardOpen(L, key, id, anchor) {
    var layer = null, stroke = null;
    L.view.layers.forEach(function (l) { if (l.key === key) { layer = l; } });
    if (!layer) { return; }
    layer.strokes.forEach(function (s) { if (s.id === id) { stroke = s; } });
    if (!stroke || !stroke.n) { return; }
    var card = el('div', 'zdz-marks-card');
    var head = el('div', 'zdz-marks-card-head');
    var who = el('span', 'zdz-marks-card-who', layer.mine ? 'Your note' : ('Note from ' + (layer.name || 'someone')));
    head.appendChild(who);
    var x = el('button', 'zdz-marks-card-x', '×');
    x.type = 'button';
    x.setAttribute('aria-label', 'Close the note');
    x.addEventListener('click', markPopClose);
    head.appendChild(x);
    card.appendChild(head);
    card.appendChild(el('div', 'zdz-marks-card-text', stroke.n));
    var when = markWhen(layer.at);
    if (when) { card.appendChild(el('div', 'zdz-marks-card-when', when)); }
    card.setAttribute('aria-label', who.textContent);
    markPopOpen(card, L, 'card', anchor);
  }

  /** Who a drawing on this photo can be shared with (app users who can see the photo). */
  function markPeople(id, query) {
    return rest('zorderz/v1/media/' + (id | 0) + '/marks/people' + markQuery(query), { method: 'GET' }).then(function (r) {
      return (r && Array.isArray(r.people) ? r.people : []).map(markPerson).filter(Boolean);
    });
  }

  /**
   * The "Who sees it" choices, drawn into host: Only me / Everyone who can see this photo / Chosen people (a list of the
   * people who can see it) / This Project's people (when the drawing has a Project). state: { share, people:[ids],
   * context }; onPick(state) after every change.
   */
  function markShareChooser(host, state, id, query, onPick) {
    while (host.firstChild) { host.removeChild(host.firstChild); }
    var opts = ['private', 'team', 'people'];
    if (state.context) { opts.push('project'); }
    var row = el('div', 'zdz-marks-share-opts');
    row.setAttribute('role', 'radiogroup');
    row.setAttribute('aria-label', 'Who sees your drawing');
    var list = el('div', 'zdz-marks-share-people');
    function paint() {
      Array.prototype.forEach.call(row.querySelectorAll('button'), function (b) {
        b.setAttribute('aria-checked', b.getAttribute('data-share') === state.share ? 'true' : 'false');
      });
      list.style.display = state.share === 'people' ? '' : 'none';
      Array.prototype.forEach.call(list.querySelectorAll('button[data-person]'), function (b) {
        b.setAttribute('aria-pressed', state.people.indexOf(parseInt(b.getAttribute('data-person'), 10)) >= 0 ? 'true' : 'false');
      });
    }
    opts.forEach(function (k) {
      var b = el('button', 'zdz-marks-share-opt', MARK_SHARE_WORDS[k]);
      b.type = 'button';
      b.setAttribute('role', 'radio');
      b.setAttribute('data-share', k);
      b._zdzPick = function () {
        state.share = k;
        if (k === 'people' && !list.getAttribute('data-loaded')) { loadPeople(); }
        paint();
        onPick(state);
      };
      b.addEventListener('click', b._zdzPick);
      row.appendChild(b);
    });
    host.appendChild(row);
    host.appendChild(list);
    function loadPeople() {
      list.setAttribute('data-loaded', '1');
      list.textContent = 'Finding who can see this photo…';
      markPeople(id, query).then(function (people) {
        list.textContent = '';
        if (!people.length) { list.appendChild(el('div', 'zdz-marks-share-empty', 'Nobody else can see this photo yet.')); return; }
        people.forEach(function (p) {
          var b = el('button', 'zdz-marks-person');
          b.type = 'button';
          b.setAttribute('data-person', String(p.id));
          b.appendChild(el('span', 'zdz-marks-person-ini', p.initials));
          b.appendChild(el('span', 'zdz-marks-person-name', p.name));
          b._zdzPick = function () {
            var i = state.people.indexOf(p.id);
            if (i >= 0) { state.people.splice(i, 1); } else { state.people.push(p.id); }
            paint();
            onPick(state);
          };
          b.addEventListener('click', b._zdzPick);
          list.appendChild(b);
        });
        paint();
      }, function () {
        list.removeAttribute('data-loaded');
        list.textContent = 'The list of people did not load. Try again.';
      });
    }
    if (state.share === 'people') { loadPeople(); }
    paint();
  }

  function markShareLine(layer) {
    if (!layer) { return ''; }
    if (layer.share === 'people') { return 'Shared with ' + (layer.people.length ? layer.people.map(function (p) { return p.name; }).join(', ') : 'chosen people'); }
    if (layer.share === 'team') { return 'Everyone who can see this photo sees it'; }
    if (layer.share === 'project') { return "This Project's people see it"; }
    return 'Only you see it';
  }

  /**
   * The Drawings list for one photo: each drawing this person sees (theirs first) with who drew it, how many lines and
   * notes, when, and a show/hide switch; their own sharing ("Change who sees it"); for an administrator, "Show everyone's
   * drawings" (theirs to turn on; off by default). opts: { anchor, onDraw }.
   */
  function markPanel(L, opts) {
    opts = opts || {};
    if (!L) { return null; }
    // the Drawings button again while its list is open closes it
    if (markPop && markPop.owner === L && markPop.kind === 'panel') { markPopClose(); return null; }
    var box = el('div', 'zdz-marks-panel');
    box.setAttribute('aria-label', 'Drawings on this photo');
    function paint() {
      while (box.firstChild) { box.removeChild(box.firstChild); }
      var head = el('div', 'zdz-marks-card-head');
      head.appendChild(el('span', 'zdz-marks-card-who', 'Drawings on this photo'));
      var x = el('button', 'zdz-marks-card-x', '×');
      x.type = 'button';
      x.setAttribute('aria-label', 'Close');
      x.addEventListener('click', markPopClose);
      head.appendChild(x);
      box.appendChild(head);
      if (!L.view.layers.length) {
        box.appendChild(el('div', 'zdz-marks-panel-empty', L.view.can_mark ? 'No drawings you can see yet. Tap Draw to add yours.' : 'No drawings you can see on this photo.'));
      }
      L.view.layers.forEach(function (l) {
        var r = el('div', 'zdz-marks-panel-row' + (l.mine ? ' is-mine' : ''));
        var eye = el('button', 'zdz-marks-eye');
        eye.type = 'button';
        var on = L.shown(l.key);
        eye.setAttribute('aria-pressed', on ? 'true' : 'false');
        eye.setAttribute('aria-label', 'Show ' + (l.mine ? 'your drawing' : (l.name || 'this') + "'s drawing") + ' on the photo');   // pressed = shown (review 2, #9)
        eye.textContent = on ? 'Shown' : 'Hidden';
        eye.addEventListener('click', function () { L.toggle(l.key, !L.shown(l.key)); paint(); });
        var ini = el('span', 'zdz-marks-person-ini', l.mine ? 'You' : l.initials);
        var txt = el('div', 'zdz-marks-panel-txt');
        txt.appendChild(el('div', 'zdz-marks-panel-name', l.mine ? 'Your drawing' : (l.name || 'A drawing')));
        var bits = [l.strokes.length + (l.strokes.length === 1 ? ' mark' : ' marks')];
        var nn = l.strokes.filter(function (s) { return !!s.n; }).length;
        if (nn) { bits.push(nn + (nn === 1 ? ' note' : ' notes')); }
        var when = markWhen(l.at);
        if (when) { bits.push(when); }
        txt.appendChild(el('div', 'zdz-marks-panel-meta', bits.join(' · ')));
        // the notes as text too: a Post-it is drawn in the picture, which a screen reader does not read
        var nl = l.strokes.filter(function (s) { return !!s.n; });
        if (nl.length) {
          var ul = el('ul', 'zdz-marks-panel-notes');
          nl.forEach(function (s) { ul.appendChild(el('li', '', s.n)); });
          txt.appendChild(ul);
        }
        if (l.mine) { txt.appendChild(el('div', 'zdz-marks-panel-share', markShareLine(l))); }
        else if (l.seen_as === 'admin') { txt.appendChild(el('div', 'zdz-marks-panel-share', 'Not shared - you see it as an administrator')); }
        r.appendChild(ini);
        r.appendChild(txt);
        r.appendChild(eye);
        box.appendChild(r);
        if (l.mine) {
          var ch = el('button', 'zdz-btn zdz-btn--sm zdz-btn--secondary zdz-marks-panel-change', 'Change who sees it');
          ch.type = 'button';
          var host = el('div', 'zdz-marks-panel-chooser');
          host.style.display = 'none';
          var state = { share: l.share, people: l.people.map(function (p) { return p.id; }), context: l.context };
          var saveBtn = el('button', 'zdz-btn zdz-btn--sm zdz-btn--primary', 'Save who sees it');
          saveBtn.type = 'button';
          saveBtn.style.display = 'none';
          ch.addEventListener('click', function () {
            host.style.display = '';
            saveBtn.style.display = '';
            ch.style.display = 'none';
            markShareChooser(host, state, L.photoId, L.query, function () {});
          });
          saveBtn.addEventListener('click', function () {
            var pid = L.photoId;
            if (state.share === 'people' && !state.people.length) { markSay('Choose at least one person.'); return; }
            busy(saveBtn, function () {
              return rest('zorderz/v1/media/' + (L.photoId | 0) + '/marks/share' + markQuery(L.query), { method: 'POST', body: { share: state.share, people: state.people } });
            }).then(function (v) {
              // the viewer may have moved to another photo while this was saved: its answer is not that photo's
              if (!L._answer(pid, v)) { markSay('Saved. Who sees your drawing on that photo is changed.', { kind: 'success' }); return; }
              markSay('Saved. ' + markShareLine(L.mine()) + '.', { kind: 'success' });
              paint();
            }, function (err) { markSay('', { error: err }); });
          });
          box.appendChild(ch);
          box.appendChild(host);
          box.appendChild(saveBtn);
        }
      });
      if (L.view.can_mark && typeof opts.onDraw === 'function') {
        var dr = el('button', 'zdz-btn zdz-btn--sm zdz-btn--primary zdz-marks-panel-draw', L.mine() ? 'Change your drawing' : 'Draw on this photo');
        dr.type = 'button';
        dr.addEventListener('click', function () { markPopClose(); opts.onDraw(); });
        box.appendChild(dr);
      }
      if (L.view.is_admin) {
        var adm = el('button', 'zdz-marks-admin');
        adm.type = 'button';
        adm.setAttribute('aria-pressed', L.view.show_all ? 'true' : 'false');
        adm.textContent = L.view.show_all ? "Showing everyone's drawings (as an administrator)" : ("Show everyone's drawings" + (L.view.hidden_n ? ' (' + L.view.hidden_n + ' more)' : ''));
        adm.addEventListener('click', function () {
          var pid = L.photoId;
          busy(adm, function () {
            return rest('zorderz/v1/media/marks/prefs', { method: 'POST', body: { show_all: !L.view.show_all } });
          }).then(function () { return L.photoId === pid ? L.load(pid, { query: L.query }) : null; }).then(function () { if (L.photoId === pid) { paint(); } }, function (err) { markSay('', { error: err }); });
        });
        box.appendChild(adm);
      }
    }
    paint();
    // repainted when what the layer shows changes (a load that lands while the list is open) - not while a choice is open
    var sub = function () { if (markPop && markPop.el === box && !box.querySelector('.zdz-marks-panel-chooser:not([style*="none"])')) { paint(); } };
    L._subs.push(sub);
    return markPopOpen(box, L, 'panel', opts.anchor || null, function () {
      var i = L._subs.indexOf(sub);
      if (i >= 0) { L._subs.splice(i, 1); }
    });
  }

  /** The outline on a photo tile with drawings this person can see (and a small badge: how many, and notes). */
  function markOutline(node, n, notes) {
    if (!node || !node.classList) { return; }
    n = n | 0;
    notes = notes | 0;
    node.classList.toggle('zdz-has-marks', n > 0);
    var b = node.querySelector(':scope > .zdz-marks-badge');
    var was = node.getAttribute('data-marks');
    if (n <= 0) {
      if (b) { b.parentNode.removeChild(b); }
      if (was !== null) { node.removeAttribute('data-marks'); if (node.getAttribute('title') === was) { node.removeAttribute('title'); } }
      return;
    }
    if (!b) { b = el('span', 'zdz-marks-badge'); b.setAttribute('aria-hidden', 'true'); node.appendChild(b); }
    // short, so it fits a 60 px tile: the pen and how many, then the notes; the words are in the label
    b.textContent = '✎' + (n > 1 ? n : '') + (notes ? ' ✉' + notes : '');
    var label = n + (n === 1 ? ' drawing' : ' drawings') + (notes ? ', ' + notes + (notes === 1 ? ' note' : ' notes') : '');
    node.setAttribute('data-marks', label);
    if (!node.getAttribute('title') || node.getAttribute('title') === was) { node.setAttribute('title', label); }
  }

  /** Save MY drawing through the theme: POST zorderz/v1/media/{id}/marks -> the view after the save. */
  function markSave(id, rev, strokes, extra) {
    var body = { rev: rev | 0, strokes: strokes.map(function (s) { var o = { id: s.id, c: s.c, s: s.s, p: s.p, k: s.k || 'l' }; if (s.n) { o.n = s.n; } return o; }) };
    extra = extra || {};
    if (extra.context) { body.context = extra.context; }
    if (extra.share) { body.share = extra.share; body.people = extra.people || []; }
    return rest('zorderz/v1/media/' + (id | 0) + '/marks' + markQuery(extra.query), { method: 'POST', body: body });
  }

  /*
   * the app's toasts sit at z-index 300, under every photo viewer
   * (Projects 100060, Media's), so "Saved", "someone else saved first" and the limits were said where nobody could see
   * them. A message from the marks lifts the toast layer above the viewers and the bar until it has gone.
   */
  var markSaidTimer = null, markSaidUntil = 0;
  function markSay(text, opts) {
    opts = opts || {};
    try { d.documentElement.classList.add('zdz-marks-said'); } catch (e) { /* no root */ }
    var t = opts.error ? toastError(opts.error) : toast(text, opts);
    var ms = (opts.duration || (opts.kind === 'error' || opts.error ? 5000 : 3000)) + 500;
    var until = Date.now() + ms;
    if (until <= markSaidUntil) { return t; } // an earlier, longer message keeps the layer up
    markSaidUntil = until;
    if (markSaidTimer) { clearTimeout(markSaidTimer); }
    markSaidTimer = setTimeout(function () {
      markSaidTimer = null;
      markSaidUntil = 0;
      try { d.documentElement.classList.remove('zdz-marks-said'); } catch (e) { /* no root */ }
    }, ms);
    return t;
  }

  function markEdit(L, opts) {
    if (marking) { return null; }
    var img = L.img, svg = L.svg;
    if (!img.complete || !img.naturalWidth || !img.offsetWidth) {
      // Draw tapped while the photo is still coming: open as soon as it is here. The wait belongs to THIS photo:
      // set(), cancel() or destroy() end it silently, so a Draw never opens on the next photo with this one's id.
      if (L._waiting) { return null; }
      L._waiting = true;
      markSay('Loading the photo. Drawing opens when it is here.');
      var gaveUp = setTimeout(function () { done(false); }, 20000);
      var done = function (loaded) {
        if (!L._waiting) { return; }
        L._unwait();
        var here = img.isConnected && img.offsetWidth && img.offsetHeight;
        if (!loaded) { if (here) { markSay('The photo did not load, so drawing could not start. Try again.', { kind: 'error' }); } return; }
        if (!marking && here && img.complete && img.naturalWidth) { markEdit(L, opts); }
      };
      var onload = function () { setTimeout(function () { done(true); }, 0); };
      L._unwait = function () {
        L._waiting = false;
        clearTimeout(gaveUp);
        img.removeEventListener('load', onload);
        L._unwait = function () {};
      };
      img.addEventListener('load', onload);
      return null;
    }
    var want = (opts.id | 0) || L.photoId;
    // the drawings on screen must be this photo's, or a save would put them on the wrong one
    if (L.view.id && want && L.view.id !== want) { markSay('Open the photo again to draw on it.'); return null; }
    // Draw tapped while this person's drawings are still coming - open when they are here, with theirs
    if (L._loading && L.photoId === want) {
      var tok = L._waitTok = {};   // the latest tap wins; cancel() and destroy() end the wait (re-check, LOW)
      L._loading.then(function () {
        if (L._waitTok !== tok) { return; }
        L._waitTok = null;
        if (!marking && L.photoId === want && !L._loading && L.view.id === want) { markEdit(L, opts); }
      });
      return null;
    }
    markPopClose();
    var id = want;
    var query = opts.query || L.query || markGrantFrom(img.getAttribute('src'));
    var base = L.mine();
    // a drawing keeps the context it was first made in (the server never moves it); only a new one takes
    // the viewer's, so "This Project's people" is offered only where the server will accept it
    var context = base ? base.context : (typeof opts.context === 'string' ? opts.context : '');
    var share = { share: base ? base.share : (context ? 'project' : 'private'), people: base ? base.people.map(function (p) { return p.id; }) : [], context: context };
    var shareAtStart = JSON.stringify([share.share, share.people.slice().sort()]);
    var start = base ? base.strokes.map(function (s) { var o = { id: s.id, c: s.c, s: s.s, p: s.p.slice(), k: s.k }; if (s.n) { o.n = s.n; } return o; }) : [];
    var undo = [];
    var pen = 'r', size = 2, tool = 'line';
    var active = null;  // the pointer drawing now
    var saving = false; // a Save is on its way: the drawing is frozen until the server answers
    var noteFor = null; // the arrow whose note is being written
    marking = L;
    try { d.documentElement.classList.add('zdz-marking'); } catch (e) { /* no root */ }
    L.editing = true;
    L.draft = start;
    L.cur = null;
    svg.classList.add('is-editing');
    L.natW = 0;
    L.sync();

    // ---- the bar
    var bar = el('div', 'zdz-marks-bar');
    bar.setAttribute('role', 'toolbar');
    bar.setAttribute('aria-label', 'Draw on this photo');
    var hint = el('div', 'zdz-marks-hint', 'Your drawing. The photo itself never changes.');
    bar.appendChild(hint);
    if (opts.top) { bar.classList.add('is-top'); }
    var row = el('div', 'zdz-marks-row');
    bar.appendChild(row);
    var tools = el('div', 'zdz-marks-group');
    [['line', 'Pen'], ['note', 'Note']].forEach(function (t) {
      var b = el('button', 'zdz-marks-tool', t[1]);
      b.type = 'button';
      b.setAttribute('data-tool', t[0]);
      b.setAttribute('aria-label', t[0] === 'note' ? 'Note: drag from the part you mean to where the note sits' : 'Pen');
      tools.appendChild(b);
    });
    row.appendChild(tools);
    var pens = el('div', 'zdz-marks-group');
    Object.keys(MARK_PEN).forEach(function (c) {
      var b = el('button', 'zdz-marks-pen');
      b.type = 'button';
      b.setAttribute('data-pen', c);
      b.setAttribute('aria-label', MARK_PEN_NAME[c] + ' pen');
      var sw = el('span', 'zdz-marks-swatch');
      sw.style.background = MARK_PEN[c];
      b.appendChild(sw);
      pens.appendChild(b);
    });
    row.appendChild(pens);
    var sizes = el('div', 'zdz-marks-group');
    [1, 2, 3].forEach(function (n) {
      var b = el('button', 'zdz-marks-size');
      b.type = 'button';
      b.setAttribute('data-size', String(n));
      b.setAttribute('aria-label', ['Thin', 'Medium', 'Thick'][n - 1] + ' line');
      b.appendChild(el('span', 'zdz-marks-dot zdz-marks-dot--' + n));
      sizes.appendChild(b);
    });
    row.appendChild(sizes);
    var acts = el('div', 'zdz-marks-group zdz-marks-acts');
    function act(label, cls, name) {
      var b = el('button', 'zdz-btn zdz-btn--sm ' + cls, label);
      b.type = 'button';
      b.setAttribute('data-act', name);
      acts.appendChild(b);
      return b;
    }
    var bUndo = act('Undo', 'zdz-btn--secondary', 'undo');
    var bClear = act('Clear', 'zdz-btn--secondary', 'clear');
    var bShare = act('Who sees it', 'zdz-btn--secondary', 'share');
    var bMove = act('Move bar up', 'zdz-btn--quiet', 'move'); // the bar covers part of the photo; it can sit at either end
    act('Cancel', 'zdz-btn--quiet', 'cancel');
    var bSave = act('Save', 'zdz-btn--primary', 'save');
    function paintMove() { bMove.textContent = bar.classList.contains('is-top') ? 'Move bar down' : 'Move bar up'; }
    paintMove();
    row.appendChild(acts);
    // the note box (shown after an arrow is drawn) and the Who sees it choices
    var noteBox = el('div', 'zdz-marks-notebox');
    noteBox.style.display = 'none';
    var noteLabel = el('label', 'zdz-marks-notelabel', 'Your note on this arrow');
    var noteText = el('textarea', 'zdz-marks-notetext');
    noteText.setAttribute('maxlength', String(MARK_NOTE_MAX));
    noteText.setAttribute('rows', '3');
    noteText.id = 'zdz-marks-note-' + markId();
    noteLabel.setAttribute('for', noteText.id);
    var noteActs = el('div', 'zdz-marks-group');
    var nDone = el('button', 'zdz-btn zdz-btn--sm zdz-btn--primary', 'Done');
    nDone.type = 'button';
    nDone.setAttribute('data-act', 'note-done');
    var nRemove = el('button', 'zdz-btn zdz-btn--sm zdz-btn--quiet', 'Remove this note');
    nRemove.type = 'button';
    nRemove.setAttribute('data-act', 'note-remove');
    noteActs.appendChild(nDone);
    noteActs.appendChild(nRemove);
    noteBox.appendChild(noteLabel);
    noteBox.appendChild(noteText);
    noteBox.appendChild(noteActs);
    bar.appendChild(noteBox);
    var shareBox = el('div', 'zdz-marks-sharebox');
    shareBox.style.display = 'none';
    bar.appendChild(shareBox);
    var shareSay = el('div', 'zdz-marks-shareline');
    bar.appendChild(shareSay);
    d.body.appendChild(bar);

    function notesCount() { return L.draft.filter(function (s) { return !!s.n; }).length; }
    function paintBar() {
      Array.prototype.forEach.call(bar.querySelectorAll('.zdz-marks-pen'), function (b) {
        b.setAttribute('aria-pressed', b.getAttribute('data-pen') === pen ? 'true' : 'false');
      });
      Array.prototype.forEach.call(bar.querySelectorAll('.zdz-marks-size'), function (b) {
        b.setAttribute('aria-pressed', b.getAttribute('data-size') === String(size) ? 'true' : 'false');
      });
      Array.prototype.forEach.call(bar.querySelectorAll('.zdz-marks-tool'), function (b) {
        b.setAttribute('aria-pressed', b.getAttribute('data-tool') === tool ? 'true' : 'false');
      });
      bUndo.disabled = saving || !undo.length || !!noteFor;
      bClear.disabled = saving || !L.draft.length || !!noteFor;
      bShare.disabled = saving || !!noteFor;
      shareSay.textContent = share.share === 'people'
        ? 'Who sees it: ' + (share.people.length ? share.people.length + (share.people.length === 1 ? ' person' : ' people') + ' you chose' : 'choose people')
        : 'Who sees it: ' + (share.share === 'private' ? 'only you' : MARK_SHARE_WORDS[share.share]);
      hint.textContent = tool === 'note' ? 'Note: drag from the part you mean to where the note should sit.' : 'Your drawing. The photo itself never changes.';
      bar.classList.toggle('is-saving', saving);
    }
    paintBar();

    function snapshot() {
      undo.push(L.draft.map(function (s) { return s; }));
      if (undo.length > 100) { undo.shift(); }
    }

    function at(e) {
      var m = svg.getScreenCTM && svg.getScreenCTM();
      if (!m || !L.natW || !L.natH) { return null; }
      var pt = svg.createSVGPoint();
      pt.x = e.clientX; pt.y = e.clientY;
      var q = pt.matrixTransform(m.inverse());
      return [
        Math.max(0, Math.min(MARK_SCALE, Math.round(q.x / L.natW * MARK_SCALE))),
        Math.max(0, Math.min(MARK_SCALE, Math.round(q.y / L.natH * MARK_SCALE)))
      ];
    }

    function total() {
      var n = 0;
      for (var i = 0; i < L.draft.length; i++) { n += L.draft[i].p.length / 2; }
      return n;
    }

    function inside(node, host) { return !!(node && host && (node === host || host.contains(node))); }

    function down(e) {
      if (saving || noteFor || active !== null || (e.button && e.button !== 0)) { return; }
      if (L.draft.length >= MARK_MAX_STROKES) { markSay('That is as many lines as one drawing can hold. Undo or Clear some first.'); return; }
      if (total() >= MARK_MAX_TOTAL - 2) { markSay('That is as much drawing as one person can put on a photo. Undo or Clear some first.'); return; }
      if (tool === 'note' && notesCount() >= MARK_MAX_NOTES) { markSay('That is as many notes as one drawing can hold.'); return; }
      var p = at(e);
      if (!p) { return; }
      active = e.pointerId;
      try { svg.setPointerCapture(e.pointerId); } catch (x) { /* not capturable */ }
      snapshot();
      L.cur = tool === 'note' ? { id: markId(), c: pen, s: size, p: [p[0], p[1], p[0], p[1]], k: 'a' } : { id: markId(), c: pen, s: size, p: p, k: 'l' };
      L.draft = L.draft.concat([L.cur]);
      L.render();
      paintBar();
    }

    function move(e) {
      if (active === null || e.pointerId !== active || !L.cur) { return; }
      var p = at(e);
      if (!p) { return; }
      if (L.cur.k === 'a') { L.cur.p[2] = p[0]; L.cur.p[3] = p[1]; L.render(); return; }
      if (L.cur.p.length >= 2 * MARK_MAX_POINTS || total() >= MARK_MAX_TOTAL) {
        if (!L.cur.full) { L.cur.full = true; markSay(L.cur.p.length >= 2 * MARK_MAX_POINTS ? 'That line is as long as one line can be. Lift and start another.' : 'That is as much drawing as one person can put on a photo.'); }
        return;
      }
      var n = L.cur.p.length;
      if (Math.abs(p[0] - L.cur.p[n - 2]) + Math.abs(p[1] - L.cur.p[n - 1]) < MARK_MIN_STEP) { return; }
      L.cur.p.push(p[0], p[1]);
      if (L.curNode) {
        L.curNode.setAttribute('points', L.curNode.getAttribute('points') + ' '
          + (p[0] / MARK_SCALE * L.natW).toFixed(1) + ',' + (p[1] / MARK_SCALE * L.natH).toFixed(1));
        var under = L.curNode.previousSibling;
        if (under && L.cur.c !== 'k') { under.setAttribute('points', L.curNode.getAttribute('points')); }
      } else { L.render(); }
    }

    function up(e) {
      if (active === null || e.pointerId !== active) { return; }
      active = null;
      var was = L.cur;
      L.cur = null;
      L.curNode = null;
      L.render();
      if (was && was.k === 'a') { openNote(was); }
    }

    // ---- the note on an arrow: written in the bar, never in a browser dialog
    function openNote(s) {
      noteFor = s;
      noteText.value = s.n || '';
      noteBox.style.display = '';
      shareBox.style.display = 'none';
      paintBar();
      // Focused while the lift of the finger is still being handled (a phone opens its keyboard only for a focus made
      // during the person's own gesture), and again just after, in case the browser moved focus once the gesture ended.
      try { noteText.focus({ preventScroll: true }); } catch (x) { /* not focusable */ }
      setTimeout(function () { if (noteFor === s && d.activeElement !== noteText) { try { noteText.focus({ preventScroll: true }); } catch (x) { /* not focusable */ } } }, 0);
    }
    function closeNote(keep) {
      if (!noteFor) { return; }
      var s = noteFor;
      noteFor = null;
      noteBox.style.display = 'none';
      if (keep) {
        var t = String(noteText.value || '').replace(/\r\n?/g, '\n').trim().slice(0, MARK_NOTE_MAX);
        var copy = { id: s.id, c: s.c, s: s.s, p: s.p.slice(), k: 'a' };
        if (t) { copy.n = t; }
        L.draft = L.draft.map(function (x) { return x === s ? copy : x; });
        if (!t && Math.abs(s.p[0] - s.p[2]) + Math.abs(s.p[1] - s.p[3]) < 2 * MARK_MIN_STEP) {
          L.draft = L.draft.filter(function (x) { return x !== copy; }); // no arrow and no words: nothing to keep
        }
      } else {
        L.draft = L.draft.filter(function (x) { return x !== s; });
      }
      L.render();
      paintBar();
    }

    function pressBar(t) {
      var b = t && t.closest ? t.closest('button') : null;
      if (!b || !bar.contains(b) || b.disabled || saving) { return; }
      if (shareBox.contains(b)) { return; } // the chooser's own buttons handle themselves
      var a = b.getAttribute('data-act');
      if (a === 'note-done') { closeNote(true); return; }
      if (a === 'note-remove') { closeNote(false); return; }
      if (noteFor) { return; } // finish the note first
      if (b.hasAttribute('data-tool')) { tool = b.getAttribute('data-tool') === 'note' ? 'note' : 'line'; paintBar(); return; }
      if (b.hasAttribute('data-pen')) { pen = b.getAttribute('data-pen'); paintBar(); return; }
      if (b.hasAttribute('data-size')) { size = parseInt(b.getAttribute('data-size'), 10) || 2; paintBar(); return; }
      if (a === 'undo' && undo.length) { L.draft = undo.pop(); L.render(); paintBar(); }
      else if (a === 'clear' && L.draft.length) { snapshot(); L.draft = []; L.render(); paintBar(); }
      else if (a === 'share') {
        var open = shareBox.style.display === 'none';
        shareBox.style.display = open ? '' : 'none';
        if (open) { markShareChooser(shareBox, share, id, query, function () { paintBar(); }); }
      }
      else if (a === 'move') {
        bar.classList.toggle('is-top');
        paintMove();
        try { d.documentElement.classList.toggle('zdz-marks-bar-top', bar.classList.contains('is-top')); } catch (e) { /* no root */ }
      }
      else if (a === 'cancel') { close(null); }
      else if (a === 'save') { save(); }
    }

    function sameStroke(a, b) {
      return a.id === b.id && a.c === b.c && a.s === b.s && (a.k || 'l') === (b.k || 'l') && (a.n || '') === (b.n || '') && a.p.length === b.p.length && a.p.join(',') === b.p.join(',');
    }
    function changed() {
      var was = base ? base.strokes : [];
      if (was.length !== L.draft.length) { return true; }
      for (var i = 0; i < was.length; i++) { if (!sameStroke(was[i], L.draft[i])) { return true; } }
      return false;
    }
    function shareChanged() { return JSON.stringify([share.share, share.people.slice().sort()]) !== shareAtStart; }

    function save() {
      if (saving) { return; } // a second tap is the same Save
      if (noteFor) { closeNote(true); }
      var strokesMoved = changed();
      var shareMoved = shareChanged() && (L.draft.length > 0);
      if (!strokesMoved && !shareMoved) { close(null); return; }
      if (share.share === 'people' && !share.people.length && L.draft.length) { markSay('Choose who sees it, or pick another choice under Who sees it.'); return; }
      var sent = L.draft.slice();
      saving = true;
      paintBar();
      var extra = { query: query, context: context };
      if (shareMoved || (!base && L.draft.length)) { extra.share = share.share; extra.people = share.people.slice(); }
      var send = opts.save || function (strokes, rev) { return markSave(id, rev, strokes, extra); };
      busy(bSave, function () { return send(sent, base ? base.rev : (L.view.my_rev | 0)); }).then(function (v) {
        saving = false;
        var stillHere = L._close === close;
        if (stillHere) { close(v, true); } // still this drawing on this photo
        else if (typeof opts.done === 'function') { opts.done(v, true); } // the viewer moved on: tell it, leave the layer alone
        var mine = markView(v).layers.filter(function (l) { return l.mine; })[0];
        markSay(mine ? 'Saved. ' + markShareLine(mine) + '. The photo itself is unchanged.' : 'Your drawing is cleared.', { kind: 'success' });
      }, function (err) {
        saving = false;
        var now = err && err.status === 409 && err.body && err.body.data && err.body.data.view ? err.body.data.view : null;
        if (now && L._close === close) {
          // This person's own drawing was saved from somewhere else: show it, keep the lines added here, Save on the next tap.
          var had = {};
          (base ? base.strokes : []).forEach(function (s) { had[s.id] = true; });
          var nv = markView(now);
          var theirs = nv.layers.filter(function (l) { return l.mine; })[0] || null;
          var seen = {};
          (theirs ? theirs.strokes : []).forEach(function (s) { seen[s.id] = true; });
          var added = L.draft.filter(function (s) { return !had[s.id] && !seen[s.id]; });
          L.view = nv;
          base = theirs;
          if (typeof opts.refresh === 'function') { opts.refresh(nv); }
          L.draft = (theirs ? theirs.strokes.map(function (s) { return s; }) : []).concat(added);
          undo = [];
          L.render();
        }
        if (L._close === close) { paintBar(); }
        markSay('', { error: err }); // the drawing stays on screen
      });
    }

    // Every input while drawing is decided here, at the window, before any viewer can see it.
    var TYPES = ['pointerdown', 'pointermove', 'pointerup', 'pointercancel', 'mousedown', 'mouseup', 'click', 'dblclick',
      'touchstart', 'touchmove', 'touchend', 'wheel', 'contextmenu', 'gesturestart'];
    function guard(e) {
      var t = e.target;
      if (inside(t, bar)) {
        // A button press is ours; typing in the note box and choosing people work as they normally would.
        // While drawing nothing below the window sees an event (a viewer's own listeners included), so a press on one of the
        // Who sees it choices is run here, from the handler the chooser left on the button.
        if (e.type === 'click' && inside(t, shareBox)) {
          e.preventDefault(); e.stopPropagation();
          var pb = t.closest ? t.closest('button') : null;
          if (pb && typeof pb._zdzPick === 'function' && !saving) { pb._zdzPick(); }
        } else if (e.type === 'click' && t.tagName !== 'TEXTAREA') { e.preventDefault(); e.stopPropagation(); pressBar(t); }
        else { e.stopPropagation(); }
        return;
      }
      if (inside(t, svg) || (active !== null && e.pointerId === active)) {
        if (e.type === 'pointerdown') { down(e); }
        else if (e.type === 'pointermove') { move(e); }
        else if (e.type === 'pointerup' || e.type === 'pointercancel') { up(e); }
        if (e.cancelable && e.type !== 'pointerdown') { e.preventDefault(); }
        e.stopPropagation();
        return;
      }
      if (e.cancelable) { e.preventDefault(); }
      e.stopPropagation();
    }
    function keys(e) {
      var typing = inside(e.target, bar) && (e.target.tagName === 'TEXTAREA' || e.target.tagName === 'INPUT');
      if (e.key === 'Escape') {
        e.preventDefault(); e.stopPropagation();
        if (noteFor) { closeNote(true); return; }
        if (!saving) { close(null); }
        return;
      }
      if (typing) { e.stopPropagation(); return; } // the note box takes every other key as typed
      if ((e.ctrlKey || e.metaKey) && (e.key === 'z' || e.key === 'Z')) {
        e.preventDefault(); e.stopPropagation();
        if (undo.length && !noteFor) { L.draft = undo.pop(); L.render(); paintBar(); }
        return;
      }
      if (inside(e.target, bar) && (e.key === 'Tab' || e.key === 'Enter' || e.key === ' ')) { e.stopPropagation(); return; }
      e.stopPropagation();
      if (/^Arrow/.test(e.key)) { e.preventDefault(); }
    }
    TYPES.forEach(function (t) { w.addEventListener(t, guard, { capture: true, passive: false }); });
    w.addEventListener('keydown', keys, true);

    function close(v, saved) {
      if (!L.editing) { return; }
      TYPES.forEach(function (t) { w.removeEventListener(t, guard, { capture: true, passive: false }); });
      w.removeEventListener('keydown', keys, true);
      if (bar.parentNode) { bar.parentNode.removeChild(bar); }
      try { d.documentElement.classList.remove('zdz-marking'); d.documentElement.classList.remove('zdz-marks-bar-top'); } catch (e) { /* no root */ }
      L.editing = false;
      L.draft = null;
      L.cur = null;
      L.curNode = null;
      L._close = null;
      marking = null;
      svg.classList.remove('is-editing');
      if (saved) { L.set(v); } else { L.render(); L.sync(); }
      if (typeof opts.done === 'function') { opts.done(saved ? L.view : null, !!saved); }
    }
    L._close = close;
    return { close: function () { close(null); } };
  }

  // ------------------------------------------------------------------------------------------------ export

  ZDZ.ui = {
    version: '1.6.0',
    RequestError: RequestError,
    request: request,
    ajax: ajax,
    post: post,
    rest: rest,
    busy: busy,
    loading: loading,
    loadingHtml: loadingHtml,
    toast: toast,
    toastError: toastError,
    sheet: sheet,
    needsYou: needsYou,
    person: person,
    personChip: personChip,
    badge: badge,
    paper: paper,
    paperGhost: paperGhost,
    marks: {
      attach: markLayer,
      editing: function () { return !!marking; },
      save: markSave,
      panel: markPanel,
      outline: markOutline,
      grantFrom: markGrantFrom,
      shares: function () { var o = {}; for (var k in MARK_SHARE_WORDS) { if (own(MARK_SHARE_WORDS, k)) { o[k] = MARK_SHARE_WORDS[k]; } } return o; },
      pens: function () { var o = {}; for (var k in MARK_PEN) { if (Object.prototype.hasOwnProperty.call(MARK_PEN, k)) { o[k] = { color: MARK_PEN[k], name: MARK_PEN_NAME[k] }; } } return o; }
    },
    shapes: function () { var o = {}; for (var k in SHAPE_LABEL) { if (Object.prototype.hasOwnProperty.call(SHAPE_LABEL, k)) { o[k] = SHAPE_LABEL[k]; } } return o; },
    groups: function () { return (groups.groups || []).slice(); },
    esc: esc,
    _popLayer: popLayer,
    _afterPop: afterPop,
    _ownPop: function () { return ownPop; },
    _openSheets: function () { return sheets.length; }
  };
}(window, document));
