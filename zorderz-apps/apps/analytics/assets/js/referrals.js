/**
 * Zorderz Analytics — Needs a referral.
 *
 * The panel behind the chat's "Needs a referral" button. The server decides who sees which case and does every step
 * (ZANA_Referrals); this draws what it is sent and asks for one step per press:
 *   Find a provider   one web search with only the ZIP, place and kind of work
 *   Mark Vetted       a person marks the candidate they checked (nothing is sent)
 *   Press to email    only after vetting: opens a filled-in email in the Email app or the mail program. The person
 *                     sends it there. Nothing is sent from here.
 * Everything is built with textContent; links are the server's cleaned http(s) addresses only. An answer the server
 * could not read says so and offers Try again (never "none found"); the email button is drawn only where the server
 * allows it; a step that asks first asks with the kit's sheet (ZDZ.ui.sheet), never the browser's confirm box.
 */
(function () {
  'use strict';
  var CFG = window.zanaChat || {};
  var I = CFG.i18n || {};
  var FIND_WAIT_MS = 120000;   // the search may take up to a minute on the server
  var panel, list, btn, countEl, state = { cases: [], admin: false };

  function el(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) { n.className = cls; }
    if (text !== undefined && text !== null) { n.textContent = String(text); }
    return n;
  }

  /** The panel's REST call: { ok, data } with the server's message on a refusal. */
  function call(path, body, waitMs) {
    var ctl = typeof AbortController === 'function' ? new AbortController() : null;
    var timer = ctl ? setTimeout(function () { ctl.abort(); }, waitMs || 30000) : null;
    var opts = { method: body ? 'POST' : 'GET', credentials: 'same-origin', headers: { 'X-WP-Nonce': CFG.nonce || '', 'Content-Type': 'application/json' } };
    if (body) { opts.body = JSON.stringify(body); }
    if (ctl) { opts.signal = ctl.signal; }
    return fetch((CFG.apiUrl || '') + path, opts).then(function (r) {
      if (timer) { clearTimeout(timer); }
      return r.json().then(function (j) { return { ok: r.ok, data: j }; });
    }).catch(function (e) {
      if (timer) { clearTimeout(timer); }
      return { ok: false, aborted: !!(e && e.name === 'AbortError'), data: {} };
    });
  }
  function msgOf(resp, fallback) {
    var d = resp && resp.data;
    return (d && typeof d === 'object' && d.message) ? String(d.message) : fallback;
  }
  function safeHref(u) { return /^https?:\/\//i.test(String(u || '')) ? String(u) : ''; }
  function when(s) {
    var t = Date.parse(String(s || '').replace(' ', 'T') + 'Z');
    return isNaN(t) ? '' : new Date(t).toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
  }
  function setCount(n) {
    if (!countEl) { return; }
    countEl.textContent = String(n);
    countEl.hidden = !(n > 0);
  }

  function open() {
    panel.hidden = false;
    var c = panel.querySelector('.zana-ref-close');
    if (c) { c.focus(); }
    load();
  }
  function close() { panel.hidden = true; if (btn) { btn.focus(); } }

  function load() {
    list.textContent = '';
    list.appendChild(el('p', 'zana-ref-hint', '…'));
    call('/referrals').then(function (resp) {
      list.textContent = '';
      if (!resp.ok) { list.appendChild(el('p', 'zana-ref-err', msgOf(resp, I.error || ''))); return; }
      state.cases = resp.data.cases || [];
      state.admin = !!resp.data.admin;
      setCount(state.cases.length);
      draw();
    });
  }
  function replace(v) {
    state.cases = state.cases.map(function (c) { return c.id === v.id ? v : c; });
    draw();
  }
  function drop(id) {
    state.cases = state.cases.filter(function (c) { return c.id !== id; });
    setCount(state.cases.length);
    draw();
  }
  function draw() {
    list.textContent = '';
    if (!state.cases.length) { list.appendChild(el('p', 'zana-ref-hint', I.refEmpty || '')); return; }
    state.cases.forEach(function (c) { list.appendChild(card(c)); });
  }

  function step(c, box, label, path, data, after) {
    var b = el('button', 'zana-ref-btn' + (after.primary ? ' zana-ref-btn--primary' : ''), label);
    b.type = 'button';
    b.addEventListener('click', function () {
      if (after.ask) {
        var ui = window.ZDZ && window.ZDZ.ui;
        if (!ui || typeof ui.sheet !== 'function') { return; }   // no kit, no step that asks first
        ui.sheet({
          mount: panel,
          title: after.ask.title,
          body: el('p', '', after.ask.body),
          actions: [{ label: after.ask.keep }, { label: after.ask.go, kind: 'primary', onClick: function () { go(); return true; } }]
        });
        return;
      }
      go();
    });
    function go() {
      b.disabled = true;
      var was = b.textContent;
      b.textContent = after.busy || I.working || '…';
      var err = box.querySelector('.zana-ref-err');
      if (err) { err.remove(); }
      call('/referrals/' + path, Object.assign({ 'case': c.id }, data || {}), path === 'find' ? FIND_WAIT_MS : 30000).then(function (resp) {
        if (!resp.ok) {
          b.disabled = false; b.textContent = was;
          // a search that outlived the wait may still finish on the server: never "try again" (it would pay twice)
          box.appendChild(el('p', 'zana-ref-err', resp.aborted && path === 'find' ? (I.refStillRun || '') : msgOf(resp, I.error || '')));
          return;
        }
        after.done(resp.data);
      });
    }
    return b;
  }

  function card(c) {
    var box = el('section', 'zana-ref-card');
    var h = el('div', 'zana-ref-head');
    h.appendChild(el('strong', '', c.zip + (c.place ? ' · ' + c.place : '')));
    if (c.mode !== 'live') { h.appendChild(el('span', 'zana-ref-badge', I.refShadow || '')); }
    if (c.test) { h.appendChild(el('span', 'zana-ref-badge', I.refTest || '')); }
    box.appendChild(h);
    var meta = [c.work];
    if (c.estimate) { meta.push('#' + c.estimate); }
    if (c.salesperson) { meta.push(c.salesperson); }
    if (when(c.created)) { meta.push(when(c.created)); }
    box.appendChild(el('p', 'zana-ref-meta', meta.join(' · ')));

    var cands = c.candidates || [];
    if (!cands.length) {
      box.appendChild(el('p', 'zana-ref-hint', c.unreadable ? (I.refUnread || '') : (c.found ? (I.refNone || '') : (I.refHint || ''))));
      if (c.findsLeft > 0) {
        box.appendChild(step(c, box, c.unreadable ? (I.refTryAgain || '') : (I.refFind || ''), 'find', {}, { primary: true, busy: I.refSearching, done: replace }));
      }
    } else {
      var ul = el('ul', 'zana-ref-cands');
      cands.forEach(function (p, i) {
        if (c.vetted >= 0 && c.vetted !== i) { return; }
        var li = el('li', 'zana-ref-cand');
        li.appendChild(el('strong', '', p.name));
        var line = el('div', 'zana-ref-links');
        if (safeHref(p.website)) { var a = el('a', '', p.website.replace(/^https?:\/\/(www\.)?/i, '').replace(/\/$/, '')); a.href = safeHref(p.website); a.target = '_blank'; a.rel = 'noopener noreferrer'; line.appendChild(a); }
        if (p.phone) { var t = el('a', '', p.phone); t.href = 'tel:' + String(p.phone).replace(/[^0-9+]/g, ''); line.appendChild(t); }
        if (safeHref(p.source)) { var s = el('a', 'zana-ref-src', I.refWhere || ''); s.href = safeHref(p.source); s.target = '_blank'; s.rel = 'noopener noreferrer'; line.appendChild(s); }
        li.appendChild(line);
        if (c.vetted === i) {
          li.appendChild(el('p', 'zana-ref-hint', (I.refVetted || '') + (c.vettedBy && c.vettedBy.by ? ' · ' + c.vettedBy.by : '') + (c.note ? ': ' + c.note : '')));
        } else {
          var note = el('input', 'zana-ref-note');
          note.type = 'text'; note.maxLength = 300; note.placeholder = I.refNote || '';
          note.setAttribute('aria-label', (I.refNote || '') + ' — ' + p.name);
          li.appendChild(note);
          var vb = el('button', 'zana-ref-btn', I.refVet || '');
          vb.type = 'button';
          vb.addEventListener('click', function () {
            vb.disabled = true;
            call('/referrals/vet', { 'case': c.id, idx: i, note: note.value || '' }).then(function (resp) {
              if (!resp.ok) { vb.disabled = false; li.appendChild(el('p', 'zana-ref-err', msgOf(resp, I.error || ''))); return; }
              replace(resp.data);
            });
          });
          li.appendChild(vb);
        }
        ul.appendChild(li);
      });
      box.appendChild(ul);
      if (c.vetted < 0) { box.appendChild(el('p', 'zana-ref-hint', I.refCheck || '')); }
    }

    var acts = el('div', 'zana-ref-acts');
    if (c.vetted >= 0 && !c.saved) { box.appendChild(el('p', 'zana-ref-hint', I.refNotSaved || '')); }
    if (c.vetted >= 0 && c.saved && c.mayEmail === false) { box.appendChild(el('p', 'zana-ref-hint', String(c.emailBlocked || ''))); }
    if (c.vetted >= 0 && c.saved && c.mayEmail !== false) {
      acts.appendChild(step(c, box, I.refEmail || '', 'email', {}, { primary: true, done: function (d) {
        var opened = false;
        if (d.mode === 'inbox' && /^[a-f0-9]{32}$/.test(String(d.token || '')) && window.Bridge && typeof window.Bridge.loadApp === 'function') {
          try { window.Bridge.loadApp(String(d.app || 'inbox'), { handoff: String(d.token) }); opened = true; } catch (e) {}
        }
        if (!opened && /^mailto:/i.test(String(d.href || ''))) { window.location.href = String(d.href); }
        drop(c.id);
        if (d.note) { list.insertBefore(el('p', 'zana-ref-hint', d.note), list.firstChild); }
      } }));
    }
    if (c.canClose) {
      acts.appendChild(step(c, box, I.refClose || '', 'close', {}, {
        ask: { title: I.refClose || '', body: I.refCloseAsk || '', go: I.refClose || '', keep: I.refKeep || '' },
        done: function () { drop(c.id); }
      }));
    }
    if (acts.childNodes.length) { box.appendChild(acts); }
    return box;
  }

  function init() {
    if (!CFG.referrals || CFG.isKiosk || CFG.privacyView) { return; }
    var head = document.querySelector('#sv-chat .zana-head');
    if (!head) { setTimeout(init, 300); return; }
    if (head.querySelector('.zana-ref-open')) { return; }
    btn = el('button', 'zana-ref-open', I.refTitle || '');
    btn.type = 'button';
    countEl = el('span', 'zana-ref-count');
    countEl.hidden = true;
    btn.appendChild(countEl);
    head.appendChild(btn);

    panel = el('div', 'zana-ref-panel');
    panel.hidden = true;
    panel.setAttribute('role', 'dialog');
    panel.setAttribute('aria-label', I.refTitle || '');
    var top = el('div', 'zana-ref-top');
    top.appendChild(el('h4', '', I.refTitle || ''));
    var x = el('button', 'zana-ref-close', '×');
    x.type = 'button';
    x.setAttribute('aria-label', I.cancel || 'Close');
    x.addEventListener('click', close);
    top.appendChild(x);
    panel.appendChild(top);
    list = el('div', 'zana-ref-list');
    panel.appendChild(list);
    document.body.appendChild(panel);
    btn.addEventListener('click', open);
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && panel && !panel.hidden) { close(); } });
    // the count, without opening the panel
    call('/referrals').then(function (resp) { if (resp.ok) { setCount((resp.data.cases || []).length); } });
  }
  if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', init); } else { init(); }
})();
