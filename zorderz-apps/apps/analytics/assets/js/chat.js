/**
 * Zorderz Analytics — the Chat surface.
 *
 * Injects the permanent bottom "Chat" nav item and its sub-view (the theme marks the spot in front-page.php), and
 * exposes window.ZanaChat.open() so the ask bar, the home tiles, Messages' "From your chat" and the digest deep-link land
 * here. Talks to the REST routes under zorderz/v1 (a Privacy View account: admin-ajax only, its replies mask themselves).
 *
 * No business, person or product name anywhere: every label comes from zanaChat.i18n, and the assistant is called by
 * the name the business's voice pack gives it, else "the assistant".
 *
 * An answer is drawn from its words with a small, safe subset of markdown (chat-kit.js parses; this file builds DOM
 * nodes and text nodes only, so nothing in an answer is ever read as HTML). The server writes a few cards into an
 * answer (chat-kit.js lists them); each is drawn here:
 *   - a Confirm card: a change an app would make, held until the person presses Confirm or Cancel, run once;
 *   - a direct message drafted for a teammate, held on the server and sent only by the person's Send press, then the
 *     thread itself docked under the answer; a post drafted for a channel, to copy;
 *   - an email draft: Open in Email (the Email app's composer, filled in), Open in Mail and Copy; the chat never sends;
 *   - "Open in Charts" buttons with a small chart of the same rows, and "Open in <app>" buttons;
 *   - a page of this site, opened in the app;
 *   - an invoice or estimate opened under its own line.
 * A feature whose server side is not installed (zanaChat.features) draws nothing at all: never a button that fails.
 * The shared device gets a note and no input; a Privacy View account gets no button that writes.
 */
(function () {
  'use strict';

  var CFG = window.zanaChat || {};
  var I18N = CFG.i18n || {};
  var F = CFG.features || {};
  var K = window.ZanaChatKit;
  var state = { sessionId: 0, sending: false, booted: false, loadingId: 0, loadedAt: 0, title: '', flash: null, pushOn: false };
  var held = {};      // Confirm card id -> { busy } | { done: {ok, msg} } (kept across redraws of the same answer)
  var dmSent = {};    // answer id -> { conversation_id, display_name, target, dock } (this page's sends, and the server's record)
  // wp_localize_script hands top-level values over as strings: numbers are read once, here.
  var POLL_MS = parseInt(CFG.pollMs, 10) || 1500;
  var MAX_POLL_MS = parseInt(CFG.maxPollMs, 10) || 180000;
  var CONFIRM_TTL = parseInt(CFG.confirmTtl, 10) || 600;
  var NO_WRITES = !!(CFG.privacyView || CFG.isKiosk);

  if (!K) { return; }   // the helpers did not load (a stale cache): no half-drawn chat

  function t(key, fallback) { return (I18N[key] != null && I18N[key] !== '') ? String(I18N[key]) : (fallback || ''); }
  function kit() { return (window.ZDZ && window.ZDZ.ui) || null; }

  function h(tag, cls, text) {
    var e = document.createElement(tag);
    if (cls) { e.className = cls; }
    if (text != null) { e.textContent = String(text); }
    return e;
  }
  function btn(cls, text, label) {
    var b = h('button', cls, text);
    b.type = 'button';
    if (label) { b.setAttribute('aria-label', label); b.title = label; }
    return b;
  }
  function icon(name) {
    var i = document.createElement('i');
    i.setAttribute('data-lucide', name);
    i.setAttribute('aria-hidden', 'true');
    return i;
  }
  function icons() { if (window.lucide && typeof lucide.createIcons === 'function') { try { lucide.createIcons(); } catch (e) { /* icons are decoration */ } } }
  function toast(text, kind) {
    var k = kit();
    if (k && k.toast) { k.toast(text, kind ? { kind: kind } : {}); }
  }
  function appIds() {
    var d = window.zdzData || {};
    return (d.apps || []).map(function (a) { return a && a.id; }).filter(Boolean);
  }
  function appName(id) {
    var d = window.zdzData || {};
    var a = (d.apps || []).filter(function (x) { return x && x.id === id; })[0];
    return (a && (a.nm || a.name)) || id;
  }
  function bridge() { return (window.Bridge && typeof window.Bridge.loadApp === 'function') ? window.Bridge : null; }

  /* ── the server ────────────────────────────────────────────────────────────────────────────────── */

  // The chat's REST routes: the kit's request (a 502 page, a timeout or a lost signal becomes a plain sentence).
  function api(path, opts) {
    opts = opts || {};
    var url = (CFG.apiUrl || '') + path;
    var k = kit();
    if (k && k.rest) {
      return k.rest(url, { method: opts.method || 'GET', body: opts.body, timeout: opts.timeout, retry: opts.retry, headers: { 'X-WP-Nonce': CFG.nonce || '' } });
    }
    return fetch(url, {
      method: opts.method || 'GET',
      credentials: 'same-origin',
      headers: { 'X-WP-Nonce': CFG.nonce || '', 'Content-Type': 'application/json' },
      body: opts.body ? JSON.stringify(opts.body) : undefined
    }).then(function (r) {
      return r.json().then(function (j) {
        if (!r.ok) { var e = new Error((j && j.message) || t('error')); e.status = r.status; throw e; }
        return j;
      });
    });
  }

  // admin-ajax (a Privacy View account's only road, and the chat's own actions): the reply's data, or an Error whose
  // message is the server's sentence. Never retried: a press that reached the server is not sent twice.
  function ajax(action, fields) {
    var body = { nonce: CFG.ajaxNonce || '' };
    Object.keys(fields || {}).forEach(function (k) { body[k] = fields[k]; });
    var k = kit();
    if (k && k.ajax) { return k.ajax(action, body, { retry: 0, timeout: 60000 }); }
    var p = new URLSearchParams();
    p.append('action', action);
    Object.keys(body).forEach(function (k2) { p.append(k2, String(body[k2])); });
    return fetch(CFG.ajaxUrl || '', { method: 'POST', credentials: 'same-origin', body: p })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (res && res.success) { return res.data || {}; }
        var d = res && res.data;
        var e = new Error((d && typeof d === 'object' && d.message) || (typeof d === 'string' && d) || t('error'));
        e.body = res;
        throw e;
      });
  }
  function errText(e, fallback) { return (e && e.message) ? String(e.message) : (fallback || t('error')); }

  /* ── nav item and sub-view ─────────────────────────────────────────────────────────────────────── */

  function ensureNav() {
    var nav = document.querySelector('nav.bnav');
    if (!nav || nav.querySelector('.ni-chat')) { return; }
    var b = h('button', 'ni ni-chat');
    b.appendChild(icon('message-circle'));
    b.appendChild(h('span', 'ni-label', t('title', 'Chat')));
    b.setAttribute('data-view', 'sv-chat');
    b.setAttribute('aria-label', t('title', 'Chat'));
    b.addEventListener('click', function () { showChat(); });
    nav.appendChild(b);
    icons();
  }

  function headBtn(cls, iconName, label) {
    var b = btn('zana-head-btn ' + cls, null, label);
    b.appendChild(icon(iconName));
    return b;
  }

  function ensureSubview() {
    var have = document.getElementById('sv-chat');
    if (have) { return have; }
    var host = document.querySelector('.app-shell') || document.getElementById('view-main') || document.body;
    var sv = h('div', 'sub-view zana-chat zdz-fullwidth');
    sv.id = 'sv-chat';
    sv.setAttribute('role', 'main');

    var head = h('div', 'zana-head');
    var title = h('h4', 'zana-title', t('title', 'Chat'));
    title.id = 'zana-title';
    head.appendChild(title);
    var acts = h('div', 'zana-head-actions');
    var bNew = headBtn('zana-new', 'square-pen', t('newChat', 'New chat'));
    bNew.addEventListener('click', function () { newChat(true); });
    acts.appendChild(bNew);
    var bList = headBtn('zana-chats', 'history', t('chats', 'Chats'));
    bList.addEventListener('click', openChats);
    acts.appendChild(bList);
    if (F.memory && !CFG.isKiosk) {
      var bMem = headBtn('zana-about', 'user-round', t('aboutYou', 'About you'));
      bMem.addEventListener('click', openMemory);
      acts.appendChild(bMem);
    }
    var bell = headBtn('zana-bell', 'bell', t('bellOff', 'Turn on notifications'));
    bell.hidden = true;   // shown once this device's state is read and the chat's answers can be pushed
    acts.appendChild(bell);
    head.appendChild(acts);
    sv.appendChild(head);

    var notes = h('div', 'zana-notes');
    if (CFG.isKiosk) { notes.appendChild(h('p', 'zana-note', t('kioskNote'))); }
    if (CFG.privacyView) { notes.appendChild(h('p', 'zana-note zana-pv-note', t('pvNote'))); }
    if (!CFG.isKiosk && (CFG.allowancePct || 0) >= 80) { notes.appendChild(h('p', 'zana-note', t('allowance'))); }
    if (notes.childNodes.length) { sv.appendChild(notes); }

    var box = h('div', 'zana-messages');
    box.id = 'zana-messages';
    box.setAttribute('role', 'log');
    box.setAttribute('aria-live', 'polite');
    sv.appendChild(box);

    var form = h('form', 'zana-form');
    form.id = 'zana-form';
    var ta = document.createElement('textarea');
    ta.id = 'zana-input';
    ta.rows = 1;
    ta.placeholder = t('placeholder');
    ta.setAttribute('aria-label', t('placeholder') || t('title', 'Chat'));
    ta.setAttribute('enterkeyhint', 'send');
    var send = h('button', 'zana-send');
    send.type = 'submit';
    send.setAttribute('aria-label', t('send', 'Send'));
    send.appendChild(icon('arrow-up'));
    send.appendChild(h('span', 'zana-send-label', t('send', 'Send')));
    send.disabled = true;
    form.appendChild(ta);
    form.appendChild(send);
    sv.appendChild(form);
    host.appendChild(sv);

    if (CFG.isKiosk) {
      form.hidden = true;   // the shared device has no assistant: no input to type into
    }
    form.addEventListener('submit', function (ev) {
      ev.preventDefault();
      submitTyped();
    });
    ta.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' && !e.shiftKey && !e.isComposing && e.keyCode !== 229) {
        e.preventDefault();
        submitTyped();
      }
    });
    ta.addEventListener('input', function () { sizeInput(ta); updateSend(); });
    welcome(box);
    wireBell(bell);
    icons();
    return sv;
  }

  function sizeInput(ta) {
    ta.style.height = 'auto';
    ta.style.height = Math.min(ta.scrollHeight, 160) + 'px';
  }
  function updateSend() {
    var ta = document.getElementById('zana-input');
    var b = document.querySelector('#zana-form .zana-send');
    if (b && ta) { b.disabled = state.sending || !(ta.value || '').trim(); }
  }
  function submitTyped() {
    var ta = document.getElementById('zana-input');
    if (!ta) { return; }
    var text = (ta.value || '').trim();
    if (!text || state.sending) { return; }
    ta.value = '';
    sizeInput(ta);
    send(text);
  }

  /* The empty chat: a line, and the business's starter questions (if it lists any), a few at a time. The ones nothing
     records yet weigh less (zanaChat.promptWeights), so over time they are offered about a quarter as often. */
  function welcome(box) {
    box.textContent = '';
    var w = h('div', 'zana-empty');
    w.appendChild(h('p', 'zana-empty-line', t('empty')));
    var pool = Array.isArray(CFG.starters) ? CFG.starters.filter(function (q) { return typeof q === 'string' && q.trim(); }) : [];
    if (pool.length && !CFG.isKiosk) {
      var list = h('div', 'zana-starters');
      K.weightedPick(pool, 3, function (q) { return q; }, CFG.promptWeights || {}).forEach(function (q) {
        var b = btn('zana-starter', q);
        b.addEventListener('click', function () { send(q); });
        list.appendChild(b);
      });
      w.appendChild(list);
    }
    box.appendChild(w);
  }

  function showChat() {
    ensureSubview();
    // The chat takes the screen: a page or document open over the app shell is closed first (its Back entry spent).
    var B = window.Bridge;
    try { if (B && B.docOpen && typeof B.closeDoc === 'function') { B.closeDoc(false); } } catch (e) { /* nothing open */ }
    try { if (B && B.currentApp && typeof B.closeApp === 'function') { B.closeApp(); } } catch (e2) { /* nothing open */ }
    document.querySelectorAll('.sub-view').forEach(function (v) { v.classList.remove('active'); });
    document.querySelectorAll('.bnav .ni').forEach(function (n) { n.classList.remove('active'); });
    var sv = document.getElementById('sv-chat');
    if (sv) { sv.classList.add('active'); }
    var b = document.querySelector('.ni-chat');
    if (b) { b.classList.add('active'); }
  }

  function setTitle(text) {
    state.title = String(text || '').slice(0, 80);
    var el = document.getElementById('zana-title');
    if (el) { el.textContent = state.title || t('title', 'Chat'); }
  }

  /* ── opening a chat ────────────────────────────────────────────────────────────────────────────── */

  /**
   * The ask bar, a home tile, Messages' "From your chat" and the digest link land here. A session opens that chat; a
   * prompt alone starts a new chat with it (a question asked from elsewhere is its own conversation).
   */
  function open(options) {
    options = options || {};
    showChat();
    var sid = parseInt(options.session, 10) || 0;
    if (sid > 0 && !options.prompt) { loadSession(sid); return; }
    if (options.prompt) {
      var words = String(options.prompt).slice(0, 4000);
      if (state.sending) {
        // a turn is still running: the question waits in the box, never lost and never sent into the wrong chat
        var ta = document.getElementById('zana-input');
        if (ta) { ta.value = words; sizeInput(ta); updateSend(); }
        return;
      }
      if (sid > 0) { state.sessionId = sid; } else { newChat(false); }
      send(words);
    }
  }

  function newChat(focus) {
    if (state.sending) { return; }
    state.sessionId = 0;
    state.loadingId = 0;
    setTitle('');
    var box = document.getElementById('zana-messages');
    if (box) { welcome(box); }
    if (focus) {
      var ta = document.getElementById('zana-input');
      if (ta && !CFG.isKiosk) { try { ta.focus(); } catch (e) { /* not focusable yet */ } }
    }
  }

  function loadSession(id) {
    id = parseInt(id, 10) || 0;
    if (id <= 0) { return; }
    if (state.loadingId === id && state.sessionId === id && Date.now() - state.loadedAt < 2000) { return; }   // the hash and the app shell both ask
    state.loadingId = id;
    state.loadedAt = Date.now();
    state.sessionId = id;
    var load = CFG.privacyView ? ajax('zana_load_session', { session_id: id }) : api('/session/' + id);
    load.then(function (res) {
      var box = document.getElementById('zana-messages');
      if (!box || state.sessionId !== id) { return; }
      box.textContent = '';
      if (res && res.title) { setTitle(res.title); }
      var msgs = (res && res.messages) || [];
      msgs.forEach(function (m) {
        appendMsg(m.role, m.body != null ? m.body : m.content, {
          id: m.id || 0, verdict: m.verdict, createdAt: K.parseTime(m.created_at), history: true
        });
      });
      if (!msgs.length) { welcome(box); }
      scrollEnd(true);
    }).catch(function (e) {
      toast(errText(e), 'error');
    });
  }

  /* ── a turn ────────────────────────────────────────────────────────────────────────────────────── */

  function send(text) {
    if (state.sending || CFG.isKiosk) { return; }
    text = String(text || '').trim();
    if (!text) { return; }
    state.sending = true;
    updateSend();
    var box = document.getElementById('zana-messages');
    var empty = box && box.querySelector('.zana-empty');
    if (empty) { empty.remove(); }
    if (!state.sessionId) { setTitle(text.slice(0, 60)); }
    appendMsg('user', text, {});
    var pending = appendMsg('assistant', t('thinking', '…'), { pending: true });
    state.turn = (state.turn || 0) + 1;
    if (pending) { pending.__zanaTurn = state.turn; }
    // The async road (enqueue, then ask about the turn) so a slow turn cannot time out at the gateway; the
    // synchronous route whenever that is off or fails. A Privacy View account uses admin-ajax.
    if (CFG.privacyView) {
      sendAjax(text, pending);
    } else if (CFG.async) {
      sendAsync(text, pending);
    } else {
      sendSync(text, pending);
    }
  }

  /**
   * The answer replaces the thinking bubble and the lock is released. When the person moved to another chat while the
   * turn ran (its bubble is gone), the answer is not drawn here: it is saved in its own chat, which shows it when opened.
   */
  function release(pending) {
    // only the turn that holds the lock releases it (an older turn ending late never frees a newer one's)
    if (!pending || pending.__zanaTurn === state.turn) { state.sending = false; updateSend(); }
  }
  function finish(pending, body, meta, r) {
    release(pending);
    if (!pending || !pending.isConnected) { return; }
    if (r) { afterAnswer(r); }
    pending.parentNode.removeChild(pending);
    meta = meta || {};
    meta.fresh = true;
    appendMsg('assistant', body, meta);
    answerArrived();
  }

  // Answers the server works out by rule from the business's own records, with no model call.
  var INSTANT_ROUTES = ['hours', 'app_read', 'ledger', 'quick'];
  function metaOf(r) {
    r = r || {};
    return {
      verdict: r.verdict,
      id: parseInt(r.message_id, 10) || 0,
      createdAt: K.parseTime(r.created_at) || Date.now(),
      instant: r.instant === true || INSTANT_ROUTES.indexOf(String(r.route || '')) !== -1
    };
  }
  function afterAnswer(r) {
    if (r && r.session_id) { state.sessionId = parseInt(r.session_id, 10) || state.sessionId; }
    if (r && r.title) { setTitle(r.title); }
  }

  function sendSync(text, pending) {
    api('/chat', {
      method: 'POST',
      body: { message: text, session_id: state.sessionId || 0 },
      timeout: MAX_POLL_MS
    }).then(function (res) {
      finish(pending, (res && (res.answer || res.error)) || t('error', '…'), metaOf(res), res);
    }).catch(function (e) {
      finish(pending, errText(e), { verdict: 'refuse' });
    });
  }

  function sendAjax(text, pending) {
    ajax('zana_send_message', { message: text, session_id: state.sessionId || 0 }).then(function (res) {
      finish(pending, (res && (res.answer || res.message || res.response)) || t('error', '…'), metaOf(res), res);
    }).catch(function (e) {
      finish(pending, errText(e), { verdict: 'refuse' });
    });
  }

  // Enqueue, then ask about the turn. An answer the server worked out at once from stored data comes back in the
  // enqueue reply itself (no job): it is drawn right away.
  function sendAsync(text, pending) {
    api('/chat/enqueue', {
      method: 'POST',
      body: { message: text, session_id: state.sessionId || 0 }
    }).then(function (res) {
      if (res && !res.job && (res.answer || res.response)) {
        return finish(pending, res.answer || res.response, metaOf(res), res);
      }
      if (!res || res.ok === false || !res.job) { return sendSync(text, pending); }
      watchAway(res.job);
      pollTurn(res.job, pending, Date.now(), { limit: MAX_POLL_MS, extendedFor: 1 });
    }).catch(function () {
      sendSync(text, pending);
    });
  }

  /**
   * A turn still running when the person leaves the app: the server is asked to push "your answer is ready" when it
   * ends (only when this device receives the chat's answers); coming back before it ends takes the request back.
   */
  var away = { job: 0, asked: false, fn: null };
  function watchAway(job) {
    endAway();
    if (!F.notify || !job) { return; }
    away.job = job;
    away.fn = function () {
      if (!away.job) { return; }
      var want = !!document.hidden;
      if (want === away.asked || (want && !state.pushOn)) { return; }
      away.asked = want;
      api('/turn/' + away.job + '/notify', { method: 'POST', body: { notify: want }, retry: 0 }).catch(function () { /* the answer still shows here */ });
    };
    document.addEventListener('visibilitychange', away.fn);
  }
  function endAway() {
    if (away.fn) { document.removeEventListener('visibilitychange', away.fn); }
    away = { job: 0, asked: false, fn: null };
  }

  function pollTurn(job, pending, startedAt, budget) {
    var every = POLL_MS;
    if (!pending || !pending.isConnected) {
      // the person opened another chat: this turn's answer is saved in its own chat
      if (away.job === job) { endAway(); }
      release(pending);
      return;
    }
    api('/turn/' + job).then(function (res) {
      if (!res || res.ok === false) {
        return finish(pending, t('error', '…'), { verdict: 'refuse' });
      }
      var r = res.result || {};
      // a turn the server carried into a fresh request earns more time
      if (typeof res.attempt === 'number' || typeof r.attempt === 'number') {
        budget = K.pollLimit(MAX_POLL_MS, res.attempt || r.attempt, budget.extendedFor);
      }
      if (res.status === 'done' || res.status === 'error') {
        if (away.job === job) { away.asked = false; endAway(); }
        return finish(pending, r.answer || res.error || t('error', '…'), metaOf(r), r);
      }
      if (res.partial && typeof res.partial === 'string' && pending) {
        pending.classList.add('zana-streaming');
        fillAnswer(pending, res.partial, { partial: true });
      }
      if (Date.now() - startedAt > budget.limit) { return stopPolling(pending); }
      setTimeout(function () { pollTurn(job, pending, startedAt, budget); }, every);
    }).catch(function () {
      if (Date.now() - startedAt > budget.limit) { return stopPolling(pending); }
      setTimeout(function () { pollTurn(job, pending, startedAt, budget); }, every);
    });
  }

  // The time is up: the turn is still running on the server and will be saved in this chat, so say so and stop.
  function stopPolling(pending) {
    endAway();
    release(pending);
    if (pending) {
      pending.classList.remove('zana-pending', 'zana-streaming');
      pending.textContent = t('timeout', '…');
    }
  }

  /* An answer that arrives while the app is in the background: the tab's title says so until it is looked at. */
  function answerArrived() {
    if (!document.hidden || state.flash) { return; }
    var orig = document.title;
    var on = false;
    var word = t('answerReady', 'Answer ready');
    state.flash = setInterval(function () {
      on = !on;
      document.title = on ? '● ' + word : orig;
    }, 1200);
    var stop = function () {
      if (document.hidden) { return; }
      clearInterval(state.flash);
      state.flash = null;
      document.title = orig;
      document.removeEventListener('visibilitychange', stop);
    };
    document.addEventListener('visibilitychange', stop);
  }

  /* ── drawing a message ─────────────────────────────────────────────────────────────────────────── */

  function scrollEnd(force) {
    var box = document.getElementById('zana-messages');
    if (!box) { return; }
    var near = box.scrollHeight - box.scrollTop - box.clientHeight < 160;
    if (force || near) { box.scrollTop = box.scrollHeight; }
  }

  function appendMsg(role, body, meta) {
    var box = document.getElementById('zana-messages');
    if (!box) { return null; }
    meta = meta || {};
    var empty = box.querySelector('.zana-empty');
    if (empty) { empty.remove(); }
    var mine = role !== 'assistant';
    var msg = h('div', 'zana-msg zana-msg-' + (mine ? 'user' : 'assistant') + (meta.pending ? ' zana-pending' : ''));
    if (meta.id) { msg.setAttribute('data-message-id', String(meta.id)); }
    if (mine || meta.pending) {
      msg.textContent = String(body || '');
    } else {
      fillAnswer(msg, String(body || ''), meta);
    }
    box.appendChild(msg);
    scrollEnd(!meta.history || mine);
    return msg;
  }

  /** Draw an answer's words and cards into msg (again, for a partial answer that grows). */
  function fillAnswer(msg, body, meta) {
    meta = meta || {};
    msg.textContent = '';
    var x = K.extractCards(body, window.location.origin);
    var content = h('div', 'zana-msg-body');
    var ctx = { cards: x.cards, docs: x.docs, meta: meta, msg: msg };
    K.parseBlocks(x.text).forEach(function (b) { var n = block(b, ctx); if (n) { content.appendChild(n); } });
    msg.appendChild(content);
    if (meta.partial) { return; }
    if (meta.verdict && meta.verdict !== 'ok') {
      msg.appendChild(h('span', 'zana-verdict zana-verdict-' + String(meta.verdict).replace(/[^a-z_-]/g, ''), String(meta.verdict)));
    }
    // An answer worked out by code from the business's own records: say so, so its speed reads as intended. Only when
    // it arrives, never on a reload.
    if (meta.instant && meta.fresh) { msg.appendChild(h('div', 'zana-instant', t('instant', '⚡ Instant · worked out from your records, no Ai step'))); }
    msg.appendChild(actionsRow(body));
    // Address links, last, over the answer's own words (never inside a draft or a card).
    try { linkifyAddresses(content); } catch (e) { /* the words stay as they are */ }
  }

  function actionsRow(body) {
    var row = h('div', 'zana-msg-actions');
    var copy = btn('zdz-btn zdz-btn--quiet zdz-btn--sm zana-act', t('copy', 'Copy'));
    copy.addEventListener('click', function () {
      var text = K.plainText(body);
      var done = function () { copy.textContent = t('copied', 'Copied'); setTimeout(function () { copy.textContent = t('copy', 'Copy'); }, 2000); };
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(done, function () { toast(t('copyFailed', 'Copy did not work here.'), 'error'); });
      }
    });
    row.appendChild(copy);
    return row;
  }

  /* ── markdown blocks to DOM ────────────────────────────────────────────────────────────────────── */

  function block(b, ctx) {
    var n, i;
    switch (b.t) {
      case 'p':
        n = h('p');
        b.lines.forEach(function (line, k) { if (k) { n.appendChild(document.createElement('br')); } inlineInto(n, line, ctx); });
        return n;
      case 'h':
        n = h('h' + Math.min(6, Math.max(3, b.level + 2)), 'zana-h');
        inlineInto(n, b.c, ctx);
        return n;
      case 'ul':
      case 'ol':
        n = document.createElement(b.t);
        if (b.t === 'ol' && b.start > 1) { n.setAttribute('start', String(b.start)); }
        b.items.forEach(function (it) { var li = document.createElement('li'); inlineInto(li, it, ctx); n.appendChild(li); });
        return n;
      case 'code':
        n = document.createElement('pre');
        n.appendChild(h('code', null, b.v));
        return n;
      case 'quote':
        n = document.createElement('blockquote');
        b.blocks.forEach(function (x) { var c = block(x, ctx); if (c) { n.appendChild(c); } });
        return n;
      case 'hr':
        return document.createElement('hr');
      case 'table':
        var wrap = h('div', 'zana-table-wrap');
        var tbl = document.createElement('table');
        var thead = document.createElement('thead');
        var tr = document.createElement('tr');
        b.head.forEach(function (c, k) { var th = document.createElement('th'); if (b.align[k]) { th.style.textAlign = b.align[k]; } inlineInto(th, c, ctx); tr.appendChild(th); });
        thead.appendChild(tr);
        tbl.appendChild(thead);
        var tb = document.createElement('tbody');
        // a "#1001" in a column headed Invoice or Estimate opens that document under its row
        var kinds = K.tableDocKinds(b.head.map(K.inlineText));
        b.rows.forEach(function (row) {
          var r = document.createElement('tr');
          for (i = 0; i < b.head.length; i++) {
            var td = document.createElement('td');
            if (b.align[i]) { td.style.textAlign = b.align[i]; }
            var cellText = K.inlineText(row[i] || []).trim();
            var ref = kinds[i] && /^#[A-Za-z0-9-]{1,20}$/.test(cellText) ? K.docRef(cellText, kinds[i]) : null;
            if (ref) { td.appendChild(docOpener({ kind: ref.kind, number: ref.number, text: cellText })); } else { inlineInto(td, row[i] || [], ctx); }
            r.appendChild(td);
          }
          tb.appendChild(r);
        });
        tbl.appendChild(tb);
        wrap.appendChild(tbl);
        return wrap;
      case 'card':
        return card(ctx.cards[b.i], ctx);
    }
    return null;
  }

  function inlineInto(parent, toks, ctx) {
    (toks || []).forEach(function (tk) {
      var n;
      switch (tk.t) {
        case 'text': parent.appendChild(document.createTextNode(tk.v)); return;
        case 'br': parent.appendChild(document.createElement('br')); return;
        case 'strong': n = document.createElement('strong'); inlineInto(n, tk.c, ctx); parent.appendChild(n); return;
        case 'em': n = document.createElement('em'); inlineInto(n, tk.c, ctx); parent.appendChild(n); return;
        case 'del': n = document.createElement('del'); inlineInto(n, tk.c, ctx); parent.appendChild(n); return;
        case 'code': parent.appendChild(h('code', null, tk.v)); return;
        case 'chg': parent.appendChild(h('span', 'zana-chg zana-chg-' + tk.dir, tk.v)); return;
        case 'link': parent.appendChild(link(tk, ctx)); return;
        case 'doc': parent.appendChild(docOpener(ctx.docs[tk.i])); return;
      }
    });
  }

  /**
   * A link in an answer. A page of this site (a document in the library, a project page) opens in the app's document
   * layer, where the app's sign-in holds; anything else opens in a new tab. The address was checked by the helpers
   * (https, mailto, tel, or this site only).
   */
  function link(tk, ctx) {
    var a = document.createElement('a');
    a.setAttribute('href', tk.href);
    inlineInto(a, tk.c, ctx);
    var site = K.safeSiteUrl(tk.href, window.location.origin);
    if (site) {
      a.className = K.isVaultHref(tk.href, window.location.origin) ? 'zana-link zana-vault-link' : 'zana-link';
      a.addEventListener('click', function (e) {
        if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey || (typeof e.button === 'number' && e.button !== 0)) { return; }
        var B = window.Bridge;
        if (B && typeof B.openDoc === 'function') {
          e.preventDefault();
          if (!B.openDoc(site, (a.textContent || '').slice(0, 80))) { window.open(site, '_blank', 'noopener'); }
        }
      });
    } else if (/^https:/i.test(tk.href)) {
      a.setAttribute('target', '_blank');
      a.setAttribute('rel', 'noopener nofollow');
    }
    return a;
  }

  /* ── cards ─────────────────────────────────────────────────────────────────────────────────────── */

  function card(c, ctx) {
    if (!c) { return null; }
    var n = null;
    if (c.k === 'confirm') { n = confirmCard(c.id, ctx.meta); }
    else if (c.k === 'dm' || c.k === 'post') { n = draftCard(c, ctx.meta); }
    else if (c.k === 'email') { n = emailCard(c); }
    else if (c.k === 'chips') { n = chipsCard(c.items); }
    else if (c.k === 'page') { n = pageCard(c, ctx.meta); }
    if (n) { n.setAttribute('data-no-linkify', '1'); }
    return n;
  }

  function settledLine(ok, msg) {
    var d = h('div', 'zana-card-done ' + (ok ? 'is-ok' : 'is-bad'), (ok ? '✓ ' : '⚠️ ') + String(msg || (ok ? t('done', 'Done.') : t('notDone', 'That did not go through.'))));
    d.setAttribute('role', 'status');
    return d;
  }

  /**
   * A held change. Nothing has happened until the person presses Confirm; Confirm runs it once (the server claims the
   * hold before it runs anything; the page keeps the press's state, so a redraw of the same answer never offers it
   * again). A card older than the hold's life is drawn as expired. Privacy View and the shared device: no buttons.
   */
  function confirmCard(id, meta) {
    var box = h('div', 'zana-card zana-confirm-card');
    box.setAttribute('data-confirm-id', id);
    if (NO_WRITES) {
      box.appendChild(h('p', 'zana-card-note', t('confirmNoWrite', 'Changes cannot be made from this account.')));
      return box;
    }
    var st = held[id];
    if (st && st.done) { box.appendChild(settledLine(st.done.ok, st.done.msg)); return box; }
    if (st && st.busy) { box.appendChild(h('p', 'zana-card-note', t('working', 'Working on this now…'))); return box; }
    if (meta && meta.createdAt && K.confirmExpired(meta.createdAt, CONFIRM_TTL, Date.now())) {
      box.classList.add('is-expired');
      box.appendChild(h('p', 'zana-card-note', t('expiredCard', 'This confirmation has expired and nothing was run. Ask again and it will be set up fresh.')));
      return box;
    }
    var row = h('div', 'zana-card-actions');
    var ok = btn('zdz-btn zdz-btn--primary', t('confirm', 'Confirm'));
    var no = btn('zdz-btn zdz-btn--quiet', t('cancel', 'Cancel'));
    var note = h('p', 'zana-card-note');
    note.setAttribute('aria-live', 'polite');
    row.appendChild(ok);
    row.appendChild(no);
    box.appendChild(row);
    box.appendChild(note);
    function press(kind) {
      if (held[id] && (held[id].busy || held[id].done)) { return; }
      held[id] = { busy: true };
      ok.disabled = true;
      no.disabled = true;
      note.textContent = t('working', 'Working on this now…');
      api('/action/' + id + '/' + kind, { method: 'POST', body: { session_id: state.sessionId || 0 }, retry: 0, timeout: 60000 }).then(function (res) {
        var good = !!(res && res.success);
        var msg = String((res && res.message) || (kind === 'cancel' ? t('cancelled', 'Cancelled. Nothing was changed.') : t('expired', '')));
        held[id] = { done: { ok: good, msg: msg } };
        box.textContent = '';
        box.appendChild(settledLine(good, msg));
        if (good && kind === 'confirm') { afterConfirm(res, box); }
      }).catch(function (e) {
        // The hold is claimed once on the server, so pressing again is safe: a hold already used answers "expired".
        held[id] = null;
        ok.disabled = false;
        no.disabled = false;
        note.textContent = errText(e);
      });
    }
    ok.addEventListener('click', function () { press('confirm'); });
    no.addEventListener('click', function () { press('cancel'); });
    return box;
  }

  /** What a confirmed change hands back to open: the Email app's composer, a mail program, or another app's page. */
  function afterConfirm(res, box) {
    var B = bridge();
    var oa = res.open_app || null;
    if (oa && B && /^[a-z0-9-]{2,40}$/.test(String(oa.app || '')) && /^[a-f0-9]{32}$/.test(String(oa.handoff || ''))) {
      try { B.loadApp(String(oa.app), { handoff: String(oa.handoff) }); return; } catch (e) { /* fall through */ }
    }
    if (oa && B && /^[a-z0-9-]{2,40}$/.test(String(oa.app || '')) && appIds().indexOf(String(oa.app)) !== -1) {
      var opts = K.cleanOpenOptions(oa.options || {});
      if (opts) {
        var go = btn('zdz-btn zdz-btn--secondary zana-open-app', t('openIn', 'Open in %s').replace('%s', appName(String(oa.app))));
        go.addEventListener('click', function () { try { B.loadApp(String(oa.app), opts); } catch (e) { /* the app is gone */ } });
        box.appendChild(go);
      }
    }
    if (/^mailto:/i.test(String(res.open_href || ''))) { window.location.href = String(res.open_href); }
  }

  /**
   * A message drafted for a teammate (held on the server under its id), or a post drafted for a channel. Send is the
   * person's press; the server checks the hold, the person and the recipient again and sends it through Messages, once.
   * Once sent, the thread itself is docked under the answer. A post is copied (there is no Post press). The shared
   * device reads Messages only.
   */
  function draftCard(c, meta) {
    var isDm = c.k === 'dm';
    var box = h('div', 'zana-card zana-draft-card' + (isDm ? ' zana-dm-card' : ' zana-post-card'));
    var who = isDm ? (c.name || '@' + c.target) : '#' + c.channel;
    var sent = isDm && meta && meta.id && dmSent[meta.id + ':' + c.id];
    if (sent && !CFG.isKiosk) { dmDock(box, sent, false); return box; }
    var head = h('div', 'zana-card-head');
    head.appendChild(h('span', 'zana-card-kicker', (isDm ? t('draftTo', 'Draft message to %s') : t('draftFor', 'Draft for %s')).replace('%s', who)));
    head.appendChild(h('span', 'zana-card-app', t('messagesApp', 'Messages')));
    box.appendChild(head);
    box.appendChild(h('div', 'zana-draft-body', c.body));
    var acts = h('div', 'zana-card-actions');
    box.appendChild(acts);
    if (CFG.isKiosk) {
      acts.appendChild(h('span', 'zana-card-note', t('readOnlyMessages', 'Messages is read-only on this shared device.')));
      return box;
    }
    if (!isDm || !F.dm || !c.id) {
      acts.appendChild(h('span', 'zana-card-note', t('copyToSend', 'Copy it to send it yourself.')));
      var cp = btn('zdz-btn zdz-btn--secondary', t('copy', 'Copy'));
      cp.addEventListener('click', function () { copyText(c.body, cp); });
      acts.appendChild(cp);
      return box;
    }
    var go = btn('zdz-btn zdz-btn--primary', t('sendTo', 'Send to %s').replace('%s', who));
    var no = btn('zdz-btn zdz-btn--quiet', t('cancel', 'Cancel'));
    acts.appendChild(go);
    acts.appendChild(no);
    go.addEventListener('click', function () {
      if (go.disabled) { return; }
      go.disabled = true;
      no.disabled = true;
      go.textContent = t('sending', 'Sending\u2026');
      ajax('zana_confirm_zim_dm', { id: c.id, session_id: state.sessionId || 0 }).then(function (d) {
        d = d || {};
        if ((parseInt(d.conversation_id, 10) || 0) > 0) {
          var info = { conversation_id: parseInt(d.conversation_id, 10), display_name: String(d.display_name || c.name || ''), target: c.target, dock: d.dock !== false };
          if (meta && meta.id) { dmSent[meta.id + ':' + c.id] = info; }
          dmDock(box, info, true);
          return;
        }
        box.textContent = '';
        box.appendChild(settledLine(true, String(d.message || t('dmSent', 'Sent.'))));
      }).catch(function (e) {
        var code = e && e.body && e.body.data && e.body.data.code;
        if (code === 'already_sent') {
          // sent before (another tab, a retry after a lost answer): nothing is sent twice
          box.textContent = '';
          box.appendChild(settledLine(true, errText(e)));
          return;
        }
        go.disabled = false;
        no.disabled = false;
        go.textContent = t('sendTo', 'Send to %s').replace('%s', who);
        var old = box.querySelector('.zana-card-error');
        if (old) { old.remove(); }
        var er = h('div', 'zana-card-error', '\u26A0\uFE0F ' + errText(e));
        er.setAttribute('role', 'alert');
        box.appendChild(er);
      });
    });
    no.addEventListener('click', function () {
      box.textContent = '';
      box.appendChild(h('div', 'zana-card-note', t('draftCancelled', 'Draft cancelled.')));
    });
    return box;
  }

  /* The sent message, docked: a line saying where it went, Open in Messages, and the thread (Messages draws it). */
  var dockObserver = null;
  function dmDock(box, info, justSent) {
    var cid = parseInt(info.conversation_id, 10) || 0;
    var who = String(info.display_name || (info.target ? '@' + info.target : ''));
    box.className = 'zana-card zana-dm-dock';
    box.setAttribute('data-no-linkify', '1');
    box.textContent = '';
    var head = h('div', 'zana-dock-head');
    head.setAttribute('role', 'status');
    var said = h('span', 'zana-dock-said');
    said.appendChild(h('span', 'zana-dock-tick', '✓ '));
    said.appendChild(document.createTextNode(t('sentToIn', 'Sent to %s in Messages').split('%s')[0]));
    said.appendChild(h('strong', null, who || t('teammate', 'your teammate')));
    said.appendChild(document.createTextNode(t('sentToIn', 'Sent to %s in Messages').split('%s')[1] || ''));
    var openB = btn('zdz-btn zdz-btn--secondary zana-dock-open', t('openInMessages', 'Open in Messages'));
    openB.addEventListener('click', function () { openThread(cid); });
    head.appendChild(said);
    head.appendChild(openB);
    box.appendChild(head);
    var src = K.dockSrc(CFG.homeUrl, cid);
    if (!info.dock || !src) { box.classList.add('is-plain'); return; }
    var frame = document.createElement('iframe');
    frame.className = 'zana-dock-frame';
    frame.title = t('threadWith', 'Your messages with %s').replace('%s', who || t('teammate', 'your teammate'));
    frame.setAttribute('loading', justSent ? 'eager' : 'lazy');
    frame.src = src;
    box.appendChild(frame);
    // A dock scrolled out of view tells Messages so, and Messages stops asking the server for it.
    if (typeof IntersectionObserver === 'function') {
      if (!dockObserver) {
        dockObserver = new IntersectionObserver(function (entries) {
          entries.forEach(function (en) {
            try { en.target.contentWindow && en.target.contentWindow.postMessage({ type: 'zim-embed-visibility', visible: !!en.isIntersecting }, window.location.origin); } catch (e) { /* gone */ }
          });
        });
      }
      dockObserver.observe(frame);
      frame.addEventListener('load', function () { if (dockObserver) { dockObserver.unobserve(frame); dockObserver.observe(frame); } });
    }
  }

  /** Open a thread: whoever hosts Messages beside the chat answers the event; else the Team tab; else the Messages app. */
  function openThread(cid) {
    var handled = false;
    try {
      var ev = new CustomEvent('zana-open-team-thread', { detail: { conversation_id: cid }, cancelable: true });
      handled = !window.dispatchEvent(ev);
    } catch (e) { handled = false; }
    if (handled) { return; }
    var frame = document.getElementById('zim-team-tab-frame');
    var tab = document.getElementById('ni-team');
    if (frame && tab) {
      var live = frame.getAttribute('src') && frame.getAttribute('src') !== 'about:blank';
      if (live) {
        try { frame.contentWindow.postMessage({ type: 'zim-embed-open-conversation', conversation_id: cid }, window.location.origin); } catch (e2) { /* gone */ }
      } else if (frame.dataset && frame.dataset.src) {
        frame.dataset.src = frame.dataset.src.replace(/([?&])c=\d+&?/, '$1').replace(/[?&]$/, '') + '&c=' + cid;
      }
      tab.click();
      return;
    }
    var B = bridge();
    if (B) { try { B.loadApp('internal-messaging', { conversation_id: cid }); } catch (e3) { /* not installed */ } }
  }

  function copyText(text, button) {
    if (!(navigator.clipboard && navigator.clipboard.writeText)) { return; }
    navigator.clipboard.writeText(String(text || '')).then(function () {
      var was = button.textContent;
      button.textContent = t('copied', 'Copied');
      setTimeout(function () { button.textContent = was; }, 2000);
    }, function () { toast(t('copyFailed', 'Copy did not work here.'), 'error'); });
  }

  /**
   * An email draft: To and Subject, the words, then Open in Email (the Email app's composer, filled in, when this site
   * has it), Open in Mail (always offered, filled in) and Copy. The chat never sends an email.
   */
  function emailCard(c) {
    var box = h('div', 'zana-card zana-email-card');
    var head = h('div', 'zana-card-head');
    head.appendChild(h('span', 'zana-card-kicker', t('emailDraft', 'Email draft')));
    box.appendChild(head);
    if (c.to) { box.appendChild(h('div', 'zana-email-line', t('emailTo', 'To') + ': ' + c.to)); }
    if (c.subject) { box.appendChild(h('div', 'zana-email-line', t('emailSubject', 'Subject') + ': ' + c.subject)); }
    box.appendChild(h('div', 'zana-draft-body', c.body));
    var mt = K.mailtoFor(c.to, c.subject, c.body);
    var acts = h('div', 'zana-card-actions');
    if (F.compose && !NO_WRITES) {
      var ib = btn('zdz-btn zdz-btn--primary', t('openInEmail', 'Open in Email'));
      ib.addEventListener('click', function () {
        if (ib.disabled) { return; }
        ib.disabled = true;
        api('/compose', { method: 'POST', body: { to: c.to, subject: c.subject, body: c.body }, retry: 0 }).then(function (r) {
          ib.disabled = false;
          var B = bridge();
          if (r && r.mode === 'inbox' && B && /^[a-f0-9]{32}$/.test(String(r.token || '')) && /^[a-z0-9-]{2,40}$/.test(String(r.app || ''))) {
            B.loadApp(String(r.app), { handoff: String(r.token) });
            return;
          }
          if (r && /^mailto:/i.test(String(r.href || ''))) { window.location.href = String(r.href); return; }
          toast(t('error'), 'error');
        }, function (e) { ib.disabled = false; toast(errText(e), 'error'); });
      });
      acts.appendChild(ib);
    }
    var a = h('a', 'zdz-btn ' + (F.compose && !NO_WRITES ? 'zdz-btn--secondary' : 'zdz-btn--primary'), mt.hasAddress ? t('openInMail', 'Open in Mail') : t('openInMailAdd', 'Open in Mail (add recipient)'));
    a.setAttribute('href', mt.url);
    a.setAttribute('target', '_blank');
    a.setAttribute('rel', 'noopener');
    acts.appendChild(a);
    var cp = btn('zdz-btn zdz-btn--secondary', t('copy', 'Copy'));
    cp.addEventListener('click', function () { copyText((c.subject ? c.subject + '\n\n' : '') + c.body, cp); });
    acts.appendChild(cp);
    box.appendChild(acts);
    return box;
  }

  /* "Open in Charts" and "Open in <app>" buttons, with a small chart of each chart's rows above them (at most four). */
  function chipsCard(items) {
    var box = h('div', 'zana-chips-card');
    var B = bridge();
    var ids = appIds();
    var drawn = 0;
    items.forEach(function (it) {
      if (it.kind === 'chart' && it.mini && drawn < 4) {
        var m = K.miniModel(it.mini);
        if (m) {
          var mb = h('div', 'zana-mini');
          mb.appendChild(h('div', 'zana-mini-cap', it.label.replace(/^\s*\S+\s*/, '')));
          mb.appendChild(miniSvg(m, it.mini));
          if (m.key) { mb.appendChild(miniKey(m)); }
          box.appendChild(mb);
          drawn++;
        }
      }
    });
    var row = h('div', 'zana-chips');
    items.forEach(function (it) {
      var b;
      if (it.kind === 'chart') {
        if (!B || ids.indexOf('charts') === -1) { return; }
        b = btn('zdz-btn zdz-btn--secondary zana-chip', it.label);
        if (it.title) { b.title = it.title; }
        b.addEventListener('click', function () {
          var p = K.chartsPrompt(it.spec);
          if (p) { try { B.loadApp('charts', { prompt: p }); } catch (e) { /* Charts is gone */ } }
        });
      } else if (it.kind === 'say') {
        if (CFG.isKiosk) { return; }
        b = btn('zdz-btn zdz-btn--secondary zana-chip', it.label);
        b.addEventListener('click', function () { send(it.say); });
      } else if (it.kind === 'app') {
        if (!B || ids.indexOf(it.app) === -1) { return; }
        b = btn('zdz-btn zdz-btn--secondary zana-chip', it.label);
        b.addEventListener('click', function () { try { B.loadApp(it.app, it.options); } catch (e) { /* the app is gone */ } });
      }
      if (b) { row.appendChild(b); }
    });
    if (row.childNodes.length) { box.appendChild(row); }
    return box.childNodes.length ? box : null;
  }

  var SVGNS = 'http://www.w3.org/2000/svg';
  function svgEl(tag, attrs) {
    var e = document.createElementNS(SVGNS, tag);
    Object.keys(attrs || {}).forEach(function (k) { e.setAttribute(k, String(attrs[k])); });
    return e;
  }
  /** The small chart, from the helpers' shapes. Colours: the brand for the first series, then steps of the text. */
  function miniSvg(m) {
    var svg = svgEl('svg', { viewBox: '0 0 ' + m.W + ' ' + m.H, 'class': 'zana-mini-svg' + (m.kind === 'h' ? ' is-h' : ''), role: 'img', 'aria-label': t('miniLabel', 'A small chart of the rows under this answer') });
    if (m.kind === 'h') {
      m.rows.forEach(function (r) {
        var lab = svgEl('text', { x: r.x - 6, y: r.ty, 'text-anchor': 'end', 'class': 'zana-mini-text' });
        lab.textContent = r.label;
        svg.appendChild(lab);
        var rect = svgEl('rect', { x: r.x, y: r.y, width: r.w, height: r.h, 'class': 'zana-mini-s0' });
        var tt = svgEl('title');
        tt.textContent = r.title;
        rect.appendChild(tt);
        svg.appendChild(rect);
        var v = svgEl('text', { x: r.x + r.w + 4, y: r.ty, 'class': 'zana-mini-text' });
        v.textContent = r.value;
        svg.appendChild(v);
      });
      return svg;
    }
    var g = svgEl('g');
    m.series.forEach(function (s) {
      if (s.points) {
        g.appendChild(svgEl('polyline', { points: s.points, fill: 'none', 'class': 'zana-mini-line zana-mini-s' + s.shade, 'stroke-width': 1.8 }));
        return;
      }
      s.rects.forEach(function (r) {
        var rect = svgEl('rect', { x: r.x, y: r.y, width: r.w, height: r.h, 'class': 'zana-mini-s' + s.shade });
        var tt = svgEl('title');
        tt.textContent = r.title;
        rect.appendChild(tt);
        g.appendChild(rect);
      });
    });
    svg.appendChild(g);
    m.labels.forEach(function (l) {
      var tx = svgEl('text', { x: l.x, y: l.y, 'text-anchor': l.anchor, 'class': 'zana-mini-text' });
      tx.textContent = l.text;
      svg.appendChild(tx);
    });
    return svg;
  }
  function miniKey(m) {
    var key = h('div', 'zana-mini-key');
    m.series.forEach(function (s) {
      var it = h('span', 'zana-mini-k');
      it.appendChild(h('i', 'zana-mini-sw zana-mini-s' + s.shade));
      it.appendChild(document.createTextNode(' ' + s.label));
      key.appendChild(it);
    });
    return key;
  }

  /**
   * A page of this site the person asked to see: opened in the app's document layer when the answer arrives (never on
   * a reload), and a button to open it again.
   */
  function pageCard(c, meta) {
    var B = window.Bridge;
    var openIt = function () {
      if (B && typeof B.openDoc === 'function' && B.openDoc(c.url, c.title)) { return; }
      window.open(c.url, '_blank', 'noopener');
    };
    var b = btn('zdz-btn zdz-btn--secondary zana-page-open', t('openPage', 'Open %s').replace('%s', c.title));
    b.insertBefore(icon('external-link'), b.firstChild);
    b.addEventListener('click', openIt);
    if (meta && meta.fresh) { setTimeout(openIt, 60); }
    var box = h('div', 'zana-page-card');
    box.appendChild(b);
    setTimeout(icons, 0);
    return box;
  }

  /* ── an invoice or estimate, opened under its line ─────────────────────────────────────────────── */

  function docOpener(d) {
    if (!d) { return document.createTextNode(''); }
    var k = kit();
    if (!F.docs || CFG.isKiosk || !k || !k.paper) { return document.createTextNode(d.text); }
    var b = btn('zana-doc-link', d.text);
    b.setAttribute('aria-expanded', 'false');
    b.addEventListener('click', function () { toggleDoc(b, d); });
    return b;
  }

  /** Where an opened document goes: under the link's own row, list item or line. Returns {holder, mount}. */
  function docSlot(link) {
    var mount = h('div', 'zana-doc-wrap');
    mount.setAttribute('aria-live', 'polite');
    var tr = link.closest('tr');
    if (tr && tr.parentNode) {
      var cells = 0;
      Array.prototype.forEach.call(tr.children, function (c) { cells += Number(c.colSpan || 1); });
      var row = h('tr', 'zana-doc-row');
      var cell = h('td', 'zana-doc-cell');
      cell.setAttribute('colspan', String(Math.max(1, cells)));
      cell.appendChild(mount);
      row.appendChild(cell);
      tr.parentNode.insertBefore(row, tr.nextSibling);
      return { holder: row, mount: mount };
    }
    var li = link.closest('li');
    if (li) { li.appendChild(mount); return { holder: mount, mount: mount }; }
    var blockEl = link.closest('p, blockquote, h3, h4, h5, h6');
    if (blockEl && blockEl.parentNode) {
      var top = link;
      while (top.parentNode && top.parentNode !== blockEl) { top = top.parentNode; }
      var n = top.nextSibling;
      while (n && !(n.nodeType === 1 && n.tagName === 'BR')) { n = n.nextSibling; }
      if (n) { blockEl.insertBefore(mount, n.nextSibling); } else { blockEl.parentNode.insertBefore(mount, blockEl.nextSibling); }
      return { holder: mount, mount: mount };
    }
    link.parentNode.insertBefore(mount, link.nextSibling);
    return { holder: mount, mount: mount };
  }

  function toggleDoc(link, d) {
    var open = link.__zanaDoc;
    if (open && open.holder && open.holder.parentNode) {
      open.holder.parentNode.removeChild(open.holder);
      link.__zanaDoc = null;
      link.setAttribute('aria-expanded', 'false');
      return;
    }
    var slot = docSlot(link);
    var seq = (link.__zanaSeq || 0) + 1;
    link.__zanaSeq = seq;
    link.__zanaDoc = slot;
    link.setAttribute('aria-expanded', 'true');
    var still = function () { return link.__zanaDoc === slot && link.__zanaSeq === seq; };
    var close = function () {
      if (slot.holder.parentNode) { slot.holder.parentNode.removeChild(slot.holder); }
      if (link.__zanaDoc === slot) { link.__zanaDoc = null; link.setAttribute('aria-expanded', 'false'); }
    };
    var k = kit();
    slot.mount.appendChild(k.paperGhost ? k.paperGhost(t('opening', 'Opening %s…').replace('%s', d.text)) : h('p', 'zana-card-note', t('opening', 'Opening %s…').replace('%s', d.text)));
    api('/invoice-doc?kind=' + encodeURIComponent(d.kind) + '&number=' + encodeURIComponent(d.number), { retry: 0 }).then(function (res) {
      if (!still()) { return; }
      slot.mount.textContent = '';
      var doc = res && res.ok && res.doc;
      if (!doc) { throw new Error((res && typeof res.message === 'string' && res.message) || t('docClosed', 'That one could not be opened.')); }
      var opts = { label: (d.kind === 'estimate' ? t('estimate', 'Estimate') : t('invoice', 'Invoice')) + ' ' + d.text };
      if (F.docLink && d.kind === 'invoice' && doc.can_share && !doc.draft) {
        opts.copy = function () {
          return api('/invoice-link', { method: 'POST', body: { number: d.number }, retry: 0 }).then(function (r) {
            if (r && r.ok && typeof r.link === 'string') { return r.link; }
            throw { text: (r && typeof r.message === 'string' && r.message) || t('noLink', 'No customer link came back just now. Try again in a minute.') };
          }, function (e) { throw { text: errText(e) }; });
        };
      }
      slot.mount.appendChild(k.paper(doc, opts));
      var x = btn('zana-doc-close', '×', t('close', 'Close'));
      x.addEventListener('click', close);
      slot.mount.insertBefore(x, slot.mount.firstChild);
    }).catch(function (e) {
      if (!still()) { return; }
      slot.mount.textContent = '';
      var bar = h('div', 'zana-doc-bar');
      bar.appendChild(h('span', 'zana-card-note', errText(e, t('docClosed', 'That one could not be opened.'))));
      var x = btn('zana-doc-close', '×', t('close', 'Close'));
      x.addEventListener('click', close);
      bar.appendChild(x);
      slot.mount.appendChild(bar);
    });
  }

  /* ── About you (the assistant's sentences about this person) ─────────────────────────────────────── */

  /**
   * The sentences the assistant keeps about this person, each with its date and a Delete button (a Privacy View
   * account reads its list only). A sentence is added by telling the assistant "remember that I…".
   */
  function openMemory() {
    var k = kit();
    if (!k || !k.sheet) { return; }
    var body = h('div', 'zana-mem');
    body.appendChild(h('p', 'zana-mem-intro', t('memIntro', 'Short sentences the assistant keeps about you, so its answers fit how you work. Each one is yours to delete.')));
    var list = h('ul', 'zana-mem-list');
    body.appendChild(list);
    var status = h('p', 'zana-mem-status');
    status.setAttribute('role', 'status');
    body.appendChild(status);
    var canDelete = F.memoryDelete && !NO_WRITES;
    function empty(words) {
      list.textContent = '';
      list.appendChild(h('li', 'zana-mem-empty', words || t('memEmpty', 'Nothing saved about you yet.')));
    }
    function row(m) {
      var li = h('li', 'zana-mem-item');
      li.appendChild(h('p', 'zana-mem-text', String(m.text || m.fact_value || '')));
      var meta = h('div', 'zana-mem-meta');
      if (m.created_at) { meta.appendChild(h('span', 'zana-mem-date', String(m.created_at).slice(0, 10))); }
      var id = parseInt(m.id, 10) || 0;
      if (canDelete && id > 0) {
        var del = btn('zdz-btn zdz-btn--secondary zana-mem-del', t('memDelete', 'Delete'));
        del.addEventListener('click', function () {
          var work = api('/memory/' + id, { method: 'DELETE', retry: 0 }).then(function (r) {
            if (r && r.deleted) {
              li.remove();
              if (!list.querySelector('.zana-mem-item')) { empty(); }
            } else {
              status.textContent = t('notDone', 'That did not go through.');
            }
          }, function (er) { status.textContent = errText(er); });
          if (k.busy) { k.busy(del, work); }
        });
        meta.appendChild(del);
      }
      li.appendChild(meta);
      return li;
    }
    k.sheet({ title: t('aboutYou', 'About you'), body: body, actions: [{ label: t('close', 'Close') }] });
    empty(t('loading', 'Loading\u2026'));
    var load = CFG.privacyView ? ajax('zana_list_memories', {}) : api('/memory');
    load.then(function (d) {
      var rows = (d && (d.sentences || d.memories)) || [];
      if (!rows.length) { empty(d && d.enabled === false ? t('memOff', '') : ''); return; }
      list.textContent = '';
      rows.forEach(function (m) { list.appendChild(row(m)); });
    }, function (er) { empty(errText(er)); });
  }

  /* ── earlier chats ─────────────────────────────────────────────────────────────────────────────── */

  function openChats() {
    var k = kit();
    var list = h('ul', 'zana-chat-list');
    list.appendChild(h('li', 'zana-mem-empty', t('loading', 'Loading…')));
    var sh = null;
    if (k && k.sheet) { sh = k.sheet({ title: t('chats', 'Chats'), body: list, actions: [{ label: t('close', 'Close') }] }); }
    var load = CFG.privacyView ? ajax('zana_list_sessions', {}) : api('/sessions');
    load.then(function (res) {
      var rows = (res && res.sessions) || [];
      list.textContent = '';
      if (!rows.length) { list.appendChild(h('li', 'zana-mem-empty', t('noChats', 'No earlier chats yet.'))); return; }
      rows.forEach(function (s) {
        var li = h('li');
        var b = btn('zana-chat-row');
        b.appendChild(h('span', 'zana-chat-row-title', String(s.title || t('untitled', 'Chat'))));
        if (s.updated_at) { b.appendChild(h('span', 'zana-chat-row-meta', String(s.updated_at).slice(0, 16).replace('T', ' '))); }
        b.addEventListener('click', function () {
          if (sh) { sh.close('action'); }
          setTitle(s.title || '');
          loadSession(s.id);
        });
        li.appendChild(b);
        list.appendChild(li);
      });
    }).catch(function (e) {
      list.textContent = '';
      list.appendChild(h('li', 'zana-mem-empty', errText(e)));
    });
  }

  /* ── notifications for the chat's answers ──────────────────────────────────────────────────────── */

  /**
   * The bell is the app's one switch (ZDZPush: notices, Messages and the chat's answers on this device, the state
   * Settings → Notifications shows). It is shown only when this person's answers can be pushed here (a "chat"
   * channel), and it shows this device's real state, never the browser's permission alone.
   */
  function wireBell(bell) {
    var P = window.ZDZPush;
    if (!P || typeof P.status !== 'function' || CFG.isKiosk || CFG.privacyView) { return; }
    var on = false;
    function paint(st) {
      var has = !!(st && st.channels && st.channels.some(function (c) { return c.channel === 'chat'; }));
      bell.hidden = !has;
      state.pushOn = has && P.channelOn(st, 'chat');   // a turn left running can be pushed when it ends (watchAway)
      if (!has) { return; }
      on = P.channelOn(st, 'chat');
      bell.setAttribute('aria-pressed', on ? 'true' : 'false');
      var label = on ? t('bellOn', 'Notifications are on for this device (tap to turn off)') : t('bellOff', 'Turn on notifications');
      bell.setAttribute('aria-label', label);
      bell.title = label;
      bell.textContent = '';
      bell.appendChild(icon(on ? 'bell-ring' : 'bell-off'));
      icons();
    }
    P.status().then(paint, function () { /* stays hidden */ });
    window.addEventListener('zdz-push-change', function (ev) { if (ev && ev.detail) { paint(ev.detail); } });
    bell.addEventListener('click', function () {
      // enable() asks for permission first, inside this tap (a phone refuses a prompt that is not from one)
      var work = on ? P.disable({ toast: true, source: 'chat' }) : P.enable({ toast: true, source: 'chat' });
      Promise.resolve(work).then(function (st) { if (st) { paint(st); } }, function () { P.status().then(paint, function () {}); });
    });
  }

  /* ── address linkifier ─────────────────────────────────────────────────────────────────────────── */
  // Address-shaped text in an ANSWER becomes a map link through the one shared helper, window.zdzMapsUrl(). A two-pass
  // walk over TEXT NODES ONLY (never a regex on markup). Precision over recall: a house number is always required;
  // street types are strong (abbreviations, may end bare), weak (full words, need a direction, unit or city/ZIP after
  // them) and Spanish-prefix. It never touches a link, code, a button or any draft or card (DRAFT_SEL), so a link can
  // never be baked into the words of a message a later press sends. The visible text is set with textContent.
  // The street words are a US + common Spanish default; the business's region adds its own on window.zdzStreetGrammar
  // (ZDZ_Maps::street_grammar(): the knowledge pack's street-first words, or the zdz_street_grammar filter).
  var SKIP_TAGS = { A: 1, CODE: 1, PRE: 1, SCRIPT: 1, STYLE: 1, TEXTAREA: 1, BUTTON: 1, KBD: 1, SAMP: 1, SVG: 1 };
  var DRAFT_SEL = '.zana-card, .zana-draft-card, .zana-chips-card, .zana-doc-wrap, .zdz-draft-card, [data-draft], [data-draft-card], [data-no-linkify]';

  var ADDR_RE = (function () {
    var G = (typeof window !== 'undefined' && window.zdzStreetGrammar) || {};
    function esc1(s) { return String(s).replace(/[.*+?^${}()|[\]\\]/g, '\\$&'); }
    function alt(a) { return a.map(esc1).join('|'); }
    var STRONG = G.strong || ['St', 'Ave', 'Av', 'Blvd', 'Rd', 'Dr', 'Ln', 'Ct', 'Pl', 'Ter', 'Cir', 'Hwy', 'Pkwy', 'Pky', 'Trl', 'Sq', 'Wy', 'Xing', 'Cv', 'Pt'];
    var WEAK = G.weak || ['Street', 'Avenue', 'Boulevard', 'Road', 'Drive', 'Lane', 'Court', 'Place', 'Terrace', 'Circle', 'Highway', 'Parkway', 'Trail', 'Square', 'Way', 'Crossing', 'Cove', 'Point'];
    var SPAN = G.spanish || ['Via', 'Calle', 'Camino', 'Avenida', 'Paseo', 'Plaza'];
    var DIR = '(?:N|S|E|W|NE|NW|SE|SW|North|South|East|West|Northeast|Northwest|Southeast|Southwest)';
    var UNIT = '(?:#\\s?[0-9A-Za-z\\-]+|(?:Apt|Ste|Suite|Unit|Bldg|Fl|Floor|Rm|No)\\.?\\s?[0-9A-Za-z\\-]+)';
    var CITYZIP = ',\\s?[A-Za-z][A-Za-z.\'\\- ]{1,38}?(?:\\s+[A-Z]{2})?\\s+\\d{5}(?:-\\d{4})?';
    var HOUSE = '\\d{1,6}(?:[\\-\\u2013]\\d{1,4})?[A-Za-z]?';
    var TOK = '(?:[A-Z][A-Za-z.\'\\-]*|\\d{1,3}(?:st|nd|rd|th))';
    var NAME = TOK + '(?:\\s+' + TOK + '){0,3}';
    // (?![A-Za-z]) so a type never matches as the start of a longer word ("St" inside "Street", "Way" inside "Wayne").
    var STRONG_RE = '(?:' + alt(STRONG) + ')(?![A-Za-z])\\.?';
    var WEAK_RE = '(?:' + alt(WEAK) + ')(?![A-Za-z])';
    var SPAN_RE = '(?:' + alt(SPAN) + ')(?![A-Za-z])';
    var TAIL = '(?:\\s+' + DIR + '\\b|\\s+' + UNIT + '|\\s*' + CITYZIP + ')';
    var pat =
      '\\b(?:' +
        '(?:' + HOUSE + '\\s+' + SPAN_RE + '\\s+' + NAME + '(?:\\s+' + DIR + '\\b)?(?:' + TAIL + ')?)' +
        '|' +
        '(?:' + HOUSE + '\\s+(?:' + DIR + '\\s+)?' + NAME + '\\s+' + STRONG_RE + '(?:\\s+' + DIR + '\\b)?(?:' + TAIL + ')?)' +
        '|' +
        // weak: the tail is required (keeps "3 First Place" a ranking)
        '(?:' + HOUSE + '\\s+(?:' + DIR + '\\s+)?' + NAME + '\\s+' + WEAK_RE + TAIL + ')' +
      ')';
    return new RegExp(pat, 'g');
  })();

  // Refuse runaway captures and a count phrase ("3 First Place", "1 Second Prize": a ranking, not an address).
  function passesGuards(s) {
    if (!s || s.length > 140) { return false; }
    if (!/\d/.test(s)) { return false; }
    if (/^\d+\s+(First|Second|Third|Fourth|Fifth|Sixth|Seventh|Eighth|Ninth|Tenth)\s+(Place|Pl|Prize)\b/i.test(s) && !/\d{5}/.test(s)) {
      return false;
    }
    return true;
  }

  // A map address through the shared helper; the same rule here when the theme's helper is not on the page.
  function addrUrl(addr) {
    if (typeof window !== 'undefined' && typeof window.zdzMapsUrl === 'function') {
      return window.zdzMapsUrl(addr);
    }
    var q = encodeURIComponent(String(addr == null ? '' : addr).trim());
    if (!q) { return ''; }
    var ua = (typeof navigator !== 'undefined' && navigator.userAgent) || '';
    return /iPhone|iPad|iPod|Macintosh/i.test(ua)
      ? 'https://maps.apple.com/?q=' + q
      : 'https://www.google.com/maps/search/?api=1&query=' + q;
  }

  function eachAddressMatch(text, cb) {
    ADDR_RE.lastIndex = 0;
    var m;
    while ((m = ADDR_RE.exec(text))) {
      if (m[0] === '') { ADDR_RE.lastIndex++; continue; }
      if (passesGuards(m[0])) { cb(m[0], m.index); }
    }
  }

  // Pure helper (and a test seam): the address strings a text yields.
  function matchAddresses(text) {
    var out = [];
    eachAddressMatch(String(text == null ? '' : text), function (s) { out.push(s); });
    return out;
  }

  function inSkippedSubtree(node, root) {
    for (var el = node.parentNode; el && el.nodeType === 1; el = el.parentNode) {
      var tn = el.tagName ? String(el.tagName).toUpperCase() : '';
      if (SKIP_TAGS[tn]) { return true; }
      if (el.matches && el.matches(DRAFT_SEL)) { return true; }
      if (el === root) { break; }
    }
    return false;
  }

  function linkifyTextNode(node) {
    var text = node.nodeValue;
    var frag = null, last = 0;
    eachAddressMatch(text, function (matched, index) {
      var url = addrUrl(matched.replace(/\s+/g, ' ').trim());
      if (!url) { return; }
      if (!frag) { frag = document.createDocumentFragment(); }
      if (index > last) { frag.appendChild(document.createTextNode(text.slice(last, index))); }
      var a = document.createElement('a');
      a.setAttribute('href', url);
      a.setAttribute('target', '_blank');
      a.setAttribute('rel', 'noopener');
      a.className = 'zana-addr-link';
      a.textContent = matched;
      frag.appendChild(a);
      last = index + matched.length;
    });
    if (frag) {
      if (last < text.length) { frag.appendChild(document.createTextNode(text.slice(last))); }
      if (node.parentNode) { node.parentNode.replaceChild(frag, node); }
    }
  }

  function linkifyAddresses(root) {
    if (!root || typeof document === 'undefined' || !document.createTreeWalker) { return; }
    if (root.nodeType === 1 && root.closest && root.closest(DRAFT_SEL)) { return; }
    var walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, {
      acceptNode: function (n) {
        if (!n.nodeValue) { return NodeFilter.FILTER_REJECT; }
        ADDR_RE.lastIndex = 0;
        if (!ADDR_RE.test(n.nodeValue)) { return NodeFilter.FILTER_REJECT; }
        if (inSkippedSubtree(n, root)) { return NodeFilter.FILTER_REJECT; }
        return NodeFilter.FILTER_ACCEPT;
      }
    });
    var targets = [], tn;
    while ((tn = walker.nextNode())) { targets.push(tn); }
    for (var i = 0; i < targets.length; i++) { linkifyTextNode(targets[i]); }
  }

  /* ── start ─────────────────────────────────────────────────────────────────────────────────────── */

  /**
   * Every way into the chat lands here. The shell's own launcher (Bridge.loadApp) would draw this app as an empty page
   * of its own, so the chat claims its launches: Messages' "From your chat", Charts' "Ask the chat" and any other app
   * that opens it by id reach open() with their options, as the home screen's openApp() already does.
   */
  function claimLaunches() {
    var B = window.Bridge;
    if (!B || typeof B.loadApp !== 'function' || B.loadApp.__zanaChat) { return; }
    var shell = B.loadApp;
    var mine = function (appId, options) {
      if (appId === (CFG.appId || 'sales-analytics')) { open(options || {}); return; }
      return shell.apply(this, arguments);
    };
    mine.__zanaChat = true;
    B.loadApp = mine;
  }

  function init() {
    if (state.booted) { return; }
    state.booted = true;
    claimLaunches();
    ensureNav();
    ensureSubview();
    // The digest / recall deep link: #zana-session=<id> (the older #tsa-session= is still read); a tapped "your answer
    // is ready" notification: ?zana_session=<id> (taken out of the address once read, so a reload starts at home).
    var m = (window.location.hash || '').match(/#(?:zana|tsa)-session=(\d+)/);
    var q = (window.location.search || '').match(/[?&]zana_session=(\d+)/);
    if (q && window.history && typeof window.history.replaceState === 'function') {
      try {
        var rest = window.location.search.replace(/([?&])zana_session=\d+&?/, '$1').replace(/[?&]$/, '');
        window.history.replaceState(window.history.state, '', window.location.pathname + rest + window.location.hash);
      } catch (e) { /* the address stays */ }
    }
    if (m || q) { open({ session: parseInt((m || q)[1], 10) }); }
  }

  window.ZanaChat = { open: open, showChat: showChat, ajax: ajax, newChat: newChat };
  // The address matcher and linkifier, for the test harness and for another surface that wants them.
  window.ZanaChat.matchAddresses = matchAddresses;
  window.ZanaChat.linkifyAddresses = linkifyAddresses;

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
