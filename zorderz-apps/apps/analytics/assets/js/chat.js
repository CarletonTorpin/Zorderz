/**
 * Zorderz Analytics — the Chat surface.
 *
 * Injects the permanent bottom "Chat" nav item and its sub-view (the theme marks
 * the spot in front-page.php), and exposes window.ZanaChat.open() so the KPI tiles
 * and the digest deep-link land here. Talks to the REST routes under zorderz/v1.
 *
 * No company/person/product name anywhere; every label comes from zanaChat.i18n.
 */
(function () {
  'use strict';

  var CFG = window.zanaChat || {};
  var I18N = CFG.i18n || {};
  var state = { sessionId: 0, sending: false, booted: false };

  function h(tag, cls, html) {
    var e = document.createElement(tag);
    if (cls) e.className = cls;
    if (html != null) e.innerHTML = html;
    return e;
  }

  function api(path, opts) {
    opts = opts || {};
    opts.headers = Object.assign({ 'X-WP-Nonce': CFG.nonce || '', 'Content-Type': 'application/json' }, opts.headers || {});
    opts.credentials = 'same-origin';
    return fetch((CFG.apiUrl || '') + path, opts).then(function (r) { return r.json(); });
  }

  // ── Nav item ────────────────────────────────────────────────────────────
  function ensureNav() {
    var nav = document.querySelector('nav.bnav');
    if (!nav || nav.querySelector('.ni-chat')) return;
    var btn = h('button', 'ni ni-chat', '<i data-lucide="message-circle"></i><span class="ni-label">' + esc(I18N.title || 'Chat') + '</span>');
    btn.setAttribute('data-view', 'sv-chat');
    btn.setAttribute('aria-label', esc(I18N.title || 'Chat'));
    btn.addEventListener('click', function () { showChat(); });
    nav.appendChild(btn);
    if (window.lucide && typeof lucide.createIcons === 'function') { try { lucide.createIcons(); } catch (e) {} }
  }

  // ── Sub-view ────────────────────────────────────────────────────────────
  function ensureSubview() {
    if (document.getElementById('sv-chat')) return document.getElementById('sv-chat');
    var host = document.querySelector('.app-shell') || document.body;
    var sv = h('div', 'sub-view zana-chat');
    sv.id = 'sv-chat';
    sv.setAttribute('role', 'main');
    sv.innerHTML =
      '<div class="zana-head"><h4>' + esc(I18N.title || 'Chat') + '</h4>' +
      (CFG.isKiosk ? '<span class="zana-kiosk-note">' + esc(I18N.kioskNote || '') + '</span>' : '') +
      '</div>' +
      '<div class="zana-messages" id="zana-messages"><p class="zana-empty">' + esc(I18N.empty || '') + '</p></div>' +
      '<form class="zana-form" id="zana-form">' +
      '<textarea id="zana-input" rows="1" placeholder="' + esc(I18N.placeholder || '') + '"></textarea>' +
      '<button type="submit" class="zana-send">' + esc(I18N.send || 'Send') + '</button>' +
      '</form>';
    host.appendChild(sv);

    sv.querySelector('#zana-form').addEventListener('submit', function (ev) {
      ev.preventDefault();
      var ta = document.getElementById('zana-input');
      var text = (ta.value || '').trim();
      if (text) { ta.value = ''; send(text); }
    });
    return sv;
  }

  function showChat() {
    ensureSubview();
    document.querySelectorAll('.sub-view').forEach(function (v) { v.classList.remove('active'); });
    document.querySelectorAll('.bnav .ni').forEach(function (n) { n.classList.remove('active'); });
    var sv = document.getElementById('sv-chat');
    if (sv) sv.classList.add('active');
    var btn = document.querySelector('.ni-chat');
    if (btn) btn.classList.add('active');
  }

  // ── Public entry point (KPI tiles / digest deep-link route here) ─────────
  function open(options) {
    options = options || {};
    showChat();
    if (options.session) { loadSession(parseInt(options.session, 10)); }
    if (options.prompt) {
      var ta = document.getElementById('zana-input');
      if (ta) { ta.value = options.prompt; }
      send(String(options.prompt));
    }
  }

  function loadSession(id) {
    if (!id) return;
    state.sessionId = id;
    api('/session/' + id).then(function (res) {
      var box = document.getElementById('zana-messages');
      if (!box) return;
      box.innerHTML = '';
      (res.messages || []).forEach(function (m) { appendMsg(m.role, m.body, m); });
    }).catch(function () {});
  }

  function send(text) {
    if (state.sending) return;
    state.sending = true;
    appendMsg('user', text, {});
    var pending = appendMsg('assistant', I18N.thinking || '…', { pending: true });
    // Prefer the async (enqueue + poll) path so a slow turn can't 502; fall back to
    // the synchronous /chat route whenever async is disabled or unavailable. Kiosk
    // (shared device) always uses the sync route — its turns are never persisted, and
    // the async path would need a job row (see ZANA_Background::enqueue).
    if (CFG.async && !CFG.isKiosk) {
      sendAsync(text, pending);
    } else {
      sendSync(text, pending);
    }
  }

  // Replace the thinking bubble with the final answer and release the lock.
  function finish(pending, body, meta) {
    state.sending = false;
    if (pending && pending.parentNode) pending.parentNode.removeChild(pending);
    appendMsg('assistant', body, meta || {});
  }

  // The original synchronous turn — one request holds open for the whole model call.
  // Kept intact as the fallback path.
  function sendSync(text, pending) {
    api('/chat', {
      method: 'POST',
      body: JSON.stringify({ message: text, session_id: state.sessionId || 0 })
    }).then(function (res) {
      if (res && res.session_id) state.sessionId = res.session_id;
      finish(pending, (res && res.answer) || '…', res || {});
    }).catch(function () {
      finish(pending, I18N.error || '…', { verdict: 'refuse' });
    });
  }

  // Async turn: enqueue, then poll for status. Any failure to enqueue falls back to
  // the sync path so behaviour never regresses.
  function sendAsync(text, pending) {
    api('/chat/enqueue', {
      method: 'POST',
      body: JSON.stringify({ message: text, session_id: state.sessionId || 0 })
    }).then(function (res) {
      if (!res || res.ok === false || !res.job) {
        // Async unavailable (e.g. 501) or refused — use the sync route instead.
        return sendSync(text, pending);
      }
      pollTurn(res.job, pending, Date.now());
    }).catch(function () {
      // Network / route error on enqueue — use the sync route instead.
      sendSync(text, pending);
    });
  }

  function pollTurn(job, pending, startedAt) {
    var everyMs = CFG.pollMs || 1500;
    var maxMs = CFG.maxPollMs || 180000;
    api('/turn/' + job).then(function (res) {
      if (!res || res.ok === false) {
        // Job not found / unreadable — honest failure rather than an endless spinner.
        return finish(pending, I18N.error || '…', { verdict: 'refuse' });
      }
      var st = res.status;
      if (st === 'done' || st === 'error') {
        var r = res.result || {};
        if (r.session_id) state.sessionId = r.session_id;
        // The result carries the honest answer even on error; prefer it, then the
        // stored error message, then a generic honest fallback.
        var body = r.answer || res.error || (I18N.error || '…');
        return finish(pending, body, r);
      }
      // Still queued / running — keep the thinking state until the cap.
      if (Date.now() - startedAt > maxMs) return stopPolling(pending);
      setTimeout(function () { pollTurn(job, pending, startedAt); }, everyMs);
    }).catch(function () {
      // Transient poll error — retry until the cap, then stop honestly.
      if (Date.now() - startedAt > maxMs) return stopPolling(pending);
      setTimeout(function () { pollTurn(job, pending, startedAt); }, everyMs);
    });
  }

  // Cap reached: the turn is still running server-side and will be persisted to the
  // transcript, so say so honestly and stop spinning. Reopening the session shows it.
  function stopPolling(pending) {
    state.sending = false;
    if (pending) {
      pending.classList.remove('zana-pending');
      pending.textContent = I18N.timeout || '…';
    }
  }

  function appendMsg(role, body, meta) {
    var box = document.getElementById('zana-messages');
    if (!box) return null;
    var empty = box.querySelector('.zana-empty');
    if (empty) empty.remove();
    var cls = 'zana-msg zana-msg-' + (role === 'assistant' ? 'assistant' : 'user');
    if (meta && meta.pending) cls += ' zana-pending';
    var msg = h('div', cls);
    msg.textContent = String(body || '');
    if (meta && meta.verdict && meta.verdict !== 'ok') {
      msg.appendChild(h('span', 'zana-verdict zana-verdict-' + meta.verdict, esc(meta.verdict)));
    }
    box.appendChild(msg);
    box.scrollTop = box.scrollHeight;
    return msg;
  }

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function init() {
    if (state.booted) return;
    state.booted = true;
    ensureNav();
    ensureSubview();
    // Digest / recall deep-link: #zana-session=<id> (legacy #tsa-session= also honoured).
    var m = (window.location.hash || '').match(/#(?:zana|tsa)-session=(\d+)/);
    if (m) { open({ session: parseInt(m[1], 10) }); }
  }

  window.ZanaChat = { open: open, showChat: showChat };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
