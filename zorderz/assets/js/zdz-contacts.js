/* Contacts made from the page: a Call, Text or E-mail tap, and how the call went.
 *
 * "Contacted" means the customer talked with us, in some form. A call only counts once the person says it reached
 * someone, so after a call the app asks once, when they come back to it:
 *
 *     How did the call go?    [No answer]  [Left a voicemail]  [Talked with them]
 *
 * Closing it any other way leaves the call as a try. The server (ZDZ_Contacts) decides which answers reach someone.
 *
 *   - Only links an app marks are logged:  <a href="tel:…" data-zdz-contact-app="leads"
 *       data-zdz-contact-lead="123" [data-zdz-contact-ref="job:45"] [data-zdz-contact-label="Example Co"]>Call</a>
 *     tel: is a call, sms: a text, mailto: an e-mail. Unmarked links are left alone, so nothing changes until an app
 *     marks its own.
 *   - "They replied": any button with data-zdz-contact-replied="<id>" (and data-zdz-contact-key when the app has it), or
 *     ZDZContacts.replied(id, key). The server lets anyone who has the contact's app say so.
 *   - The call waiting for its answer belongs to the person who made it (zdzData.userId): someone else signing in on the
 *     same browser is never shown it. A second tap on the same link within 3 seconds is the same tap.
 *   - The tap is sent with keepalive, so it is not lost when the phone app opens. The call waiting for its answer is
 *     kept in localStorage (wrapped: the prompt simply does not come back in a private window) for four hours.
 *
 * Standalone like zdz-consent.js: uses the kit's sheet and toast, and does not edit app.js.
 */
(function () {
  'use strict';
  var w = window, d = document;
  var data = w.zdzData || {};
  var STORE = 'zdz_contact_call';
  var MAX_AGE = 4 * 3600 * 1000;   // a call older than this is not asked about
  var LEAVE_WITHIN = 60 * 1000;    // leaving the app this soon after the tap is the call itself
  var REPEAT = 3000;                // a second tap on the same link this soon is the same tap
  var pending = null;              // {id, key, label, t, away, u}
  var lastTap = { a: null, t: 0 };
  function me() { return String(data.userId || ''); }
  var asking = false;

  function ui() { return w.ZDZ && w.ZDZ.ui; }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (ch) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch]; }); }

  function save() {
    try {
      if (pending && pending.id) { w.localStorage.setItem(STORE, JSON.stringify(pending)); } else { w.localStorage.removeItem(STORE); }
    } catch (e) { /* no storage: the prompt only works while the page stays open */ }
  }
  function load() {
    try {
      var p = JSON.parse(w.localStorage.getItem(STORE) || 'null');
      // Only the person who made the call is asked (a shared browser: the next person signed in is not).
      if (p && p.id && p.key && Date.now() - p.t < MAX_AGE && me() !== '' && String(p.u || '') === me()) { return p; }
      w.localStorage.removeItem(STORE);
    } catch (e) { /* nothing kept */ }
    return null;
  }

  function base() { return String(data.apiUrl || '/wp-json/zorderz/v1/').replace(/\/?$/, '/'); }
  function post(path, body) {
    return fetch(base() + path, {
      method: 'POST', credentials: 'same-origin', keepalive: true,
      headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': data.nonce || '' },
      body: JSON.stringify(body)
    }).then(function (r) {
      return r.json().catch(function () { return {}; }).then(function (j) {
        if (!r.ok) { throw new Error((j && j.message) || 'That was not saved. Try again.'); }
        return j;
      });
    });
  }

  function channelOf(href) {
    var h = String(href || '').trim().toLowerCase();
    if (h.indexOf('tel:') === 0) { return 'call'; }
    if (h.indexOf('sms:') === 0) { return 'text'; }
    if (h.indexOf('mailto:') === 0) { return 'email'; }
    return '';
  }

  /** What a marked link says about itself. */
  function contactOf(a) {
    var app = a.getAttribute('data-zdz-contact-app');
    var channel = channelOf(a.getAttribute('href'));
    if (!app || !channel) { return null; }
    var body = { app: app, channel: channel };
    var lead = a.getAttribute('data-zdz-contact-lead');
    if (lead && /^[1-9]\d{0,15}$/.test(lead)) { body.lead_id = lead; }
    var ref = /^([a-z]+):([1-9]\d{0,15})$/.exec(a.getAttribute('data-zdz-contact-ref') || '');
    if (ref) { body.ref_kind = ref[1]; body.ref_id = ref[2]; }
    if (!body.lead_id && !body.ref_id) { return null; }
    return { body: body, label: a.getAttribute('data-zdz-contact-label') || '' };
  }

  function onTap(e) {
    var a = e.target && e.target.closest ? e.target.closest('a[href]') : null;
    if (!a) { return; }
    var c = contactOf(a);
    if (!c) { return; }
    if (lastTap.a === a && Date.now() - lastTap.t < REPEAT) { return; }
    lastTap = { a: a, t: Date.now() };
    var call = c.body.channel === 'call';
    var mine = call ? { t: Date.now(), label: c.label, away: false, u: me() } : null;
    if (call) { pending = mine; }
    post('contacts', c.body).then(function (r) {
      if (!call || pending !== mine) { return; }
      if (r && r.id && r.key) {
        mine.id = r.id; mine.key = r.key;
        save();
        maybeAsk();
      } else {
        pending = null;
      }
    }, function () {
      if (pending === mine) { pending = null; save(); }   // not logged: nothing to ask about
    });
  }

  function left() {
    if (pending && !pending.away && Date.now() - pending.t < LEAVE_WITHIN) {
      pending.away = true;
      save();
    }
  }
  function back() { setTimeout(maybeAsk, 400); }

  function forget() { pending = null; save(); }

  function answer(result) {
    var p = pending;
    if (!p || !p.id) { return Promise.resolve(); }
    return post('contacts/' + encodeURIComponent(p.id) + '/outcome', { result: result, key: p.key }).then(function () {
      if (pending === p) { forget(); }
      var k = ui();
      if (k && k.toast) { k.toast(result === 'talked' ? 'Saved: you talked with them.' : 'Saved.'); }
    });
  }

  function maybeAsk(tries) {
    tries = tries || 0;
    if (asking || !pending || !pending.id || !pending.away || d.hidden) { return; }
    if (Date.now() - pending.t > MAX_AGE) { forget(); return; }
    var k = ui();
    if (!k || !k.sheet || d.documentElement.classList.contains('zdz-sheet-open')) {
      if (tries < 20) { setTimeout(function () { maybeAsk(tries + 1); }, 1500); }
      return;
    }
    asking = true;
    var p = pending;
    var s = k.sheet({
      title: 'How did the call go?',
      body: '<div class="zdz-contacts-ask"><p>Your call' + (p.label ? ' to <strong>' + esc(p.label) + '</strong>' : '') + ' just now.</p>' +
        '<p class="zdz-contacts-sub">If you close this, it counts as a try.</p></div>',
      actions: [
        { label: 'No answer', onClick: function () { return answer('no_answer'); } },
        { label: 'Left a voicemail', onClick: function () { return answer('voicemail'); } },
        { label: 'Talked with them', kind: 'primary', onClick: function () { return answer('talked'); } }
      ],
      onClose: function () {
        asking = false;
        if (pending === p) { forget(); }   // closed without an answer: it stays a try
      }
    });
    if (!s) { asking = false; }
  }

  /** "They replied" for a text or an e-mail (or a call back). */
  function replied(id, key) {
    var body = { result: 'replied' };
    if (key) { body.key = String(key); }
    return post('contacts/' + encodeURIComponent(id) + '/outcome', body);
  }

  function onReplied(e) {
    var b = e.target && e.target.closest ? e.target.closest('[data-zdz-contact-replied]') : null;
    if (!b) { return; }
    var id = b.getAttribute('data-zdz-contact-replied');
    if (!/^[1-9]\d{0,15}$/.test(id || '')) { return; }
    e.preventDefault();
    var k = ui();
    var run = function () {
      return replied(id, b.getAttribute('data-zdz-contact-key') || '').then(function () {
        b.setAttribute('aria-pressed', 'true');
        if (k && k.toast) { k.toast('Saved: they replied.'); }
      }, function (err) {
        if (k && k.toast) { k.toast((err && err.message) || 'That was not saved. Try again.'); }
      });
    };
    if (k && k.busy) { k.busy(b, run); } else { run(); }
  }

  function start() {
    var p = load();
    if (p) { pending = p; pending.away = true; maybeAsk(); }   // the page was reloaded or reopened after the call
  }

  d.addEventListener('click', onTap, true);
  d.addEventListener('click', onReplied);
  d.addEventListener('visibilitychange', function () { if (d.hidden) { left(); } else { back(); } });
  w.addEventListener('blur', left);
  w.addEventListener('focus', back);
  w.addEventListener('pageshow', back);

  w.ZDZContacts = {
    version: '1.0.0',
    replied: replied,
    pending: function () { return pending; },
    _ask: maybeAsk
  };

  if (d.readyState === 'loading') { d.addEventListener('DOMContentLoaded', start); } else { start(); }
})();
