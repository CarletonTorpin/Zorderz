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
    api('/chat', {
      method: 'POST',
      body: JSON.stringify({ message: text, session_id: state.sessionId || 0 })
    }).then(function (res) {
      state.sending = false;
      if (res && res.session_id) state.sessionId = res.session_id;
      if (pending && pending.parentNode) pending.parentNode.removeChild(pending);
      appendMsg('assistant', (res && res.answer) || '…', res || {});
    }).catch(function () {
      state.sending = false;
      if (pending) { pending.classList.remove('zana-pending'); pending.textContent = '…'; }
    });
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
