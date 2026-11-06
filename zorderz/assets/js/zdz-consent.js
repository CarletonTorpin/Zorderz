/* The usage-records card and Settings -> Usage records.
 *
 *   - The card: one ZDZ.ui.sheet (the kit's own; never confirm()) the next time the person opens the app, and again only
 *     when the notice's version changes. "I agree" and "Not now" are answers (POST /zorderz/v1/consent); closing it any
 *     other way answers nothing, so it shows again on the next visit.
 *   - Settings -> Usage records: what the person answered, with "Read and agree" or "Withdraw" (in its own sheet).
 *   - Nothing at all while Zorderz -> Settings has it off (zdzData.consent.on false), on a shared device, or in Demo
 *     Mode: the server sends {on:false} and refuses them too.
 *   - An admin can preview the card while it is off: /?zdz-consent-preview=1. Nothing is saved.
 *
 * The notice is paragraphs (a blank line between), each its own <p>; "In short" (zdzData.consent.summary, three
 * lines) on top; "The full notice" underneath, a heading over each paragraph (zdzData.consent.headings).
 *
 * Standalone: draws into #settings-consent and does not edit app.js. Four modes through tokens (consent.css).
 */
(function () {
  'use strict';
  var w = window, d = document;
  var data = w.zdzData || {};
  var c = data.consent || { on: false };

  function ui() { return w.ZDZ && w.ZDZ.ui; }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (ch) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch]; }); }
  function when(iso) {
    var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(iso || ''));
    if (!m) { return ''; }
    var mon = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'][parseInt(m[2], 10) - 1];
    return parseInt(m[3], 10) + ' ' + mon + ' ' + m[1];
  }

  function send(answer) {
    var k = ui();
    if (!k || !k.rest) { return Promise.reject(new Error('The app is still loading. Try again in a moment.')); }
    return k.rest('zorderz/v1/consent', { method: 'POST', body: { answer: answer } }).then(function (r) {
      if (r && typeof r === 'object') { c = r; }
      drawRow();
      return r;
    });
  }

  var ICON = {
    yes: '<path d="M20 6 9 17l-5-5"/>',
    no: '<path d="M18 6 6 18"/><path d="m6 6 12 12"/>',
    you: '<rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>'
  };
  function icon(k) {
    return '<svg class="zdz-consent-ico" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" ' +
      'stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + (ICON[k] || ICON.yes) + '</svg>';
  }

  /**
   * "In short" first (three lines, each with its own mark), then "The full notice", each paragraph under its
   * heading. A server without a summary gets the paragraphs alone, as before.
   */
  function cardBody(preview) {
    var paras = String(c.wording || '').split(/\n\s*\n/).map(function (p) { return p.trim(); }).filter(Boolean);
    var heads = Array.isArray(c.headings) ? c.headings : [];
    var sum = c.summary && typeof c.summary === 'object' ? c.summary : null;
    var lines = sum ? ['yes', 'no', 'you'].filter(function (k) { return sum[k]; }) : [];
    var full = paras.map(function (p, i) {
      return (heads[i] ? '<h4 class="zdz-consent-h4">' + esc(heads[i]) + '</h4>' : '') + '<p>' + esc(p) + '</p>';
    }).join('');
    return '<div class="zdz-consent-card">' +
      (c.draft ? '<span class="zdz-consent-draft zdz-pair">Draft wording</span>' : '') +
      (lines.length
        ? '<section class="zdz-consent-short zdz-pair" aria-labelledby="zdz-consent-short-h"><h3 class="zdz-consent-h3" id="zdz-consent-short-h">In short</h3>' +
          '<ul class="zdz-consent-list">' + lines.map(function (k) {
            return '<li class="zdz-consent-li is-' + k + '">' + icon(k) + '<span>' + esc(sum[k]) + '</span></li>';
          }).join('') + '</ul></section>' +
          '<section class="zdz-consent-full" aria-labelledby="zdz-consent-full-h"><h3 class="zdz-consent-h3" id="zdz-consent-full-h">The full notice</h3>' +
          '<div class="zdz-consent-words">' + full + '</div></section>'
        : '<div class="zdz-consent-words">' + full + '</div>') +
      '<p class="zdz-consent-more">' + (preview
        ? 'Preview: nothing is saved, and nobody else sees this until the card is switched on in Zorderz → Settings.'
        : 'You can change your answer at any time in Settings → Usage records.') + '</p>' +
      '</div>';
  }

  /** The card. preview: an admin looking at it while it is off; the buttons only close it. */
  function card(preview) {
    var k = ui();
    if (!k || !k.sheet || !c.wording) { return null; }
    return k.sheet({
      title: 'Records of how you use this app',
      body: cardBody(preview),
      actions: [
        { label: 'Not now', onClick: function () { return preview ? Promise.resolve() : send('later'); } },
        { label: 'I agree', kind: 'primary', onClick: function () {
          if (preview) { return Promise.resolve(); }
          return send('accept').then(function () { if (k.toast) { k.toast('Thanks. Your answer is saved.'); } });
        } }
      ]
    });
  }

  function section() { return d.getElementById('settings-consent'); }

  /** Settings -> Usage records. */
  function drawRow() {
    var host = section();
    if (!host) { return; }
    if (!c.on) { host.hidden = true; host.innerHTML = ''; return; }
    var agreed = !!c.agreed;
    host.hidden = false;
    host.innerHTML =
      '<h4>Usage records</h4>' +
      '<div class="settings-row zdz-consent-row">' +
        '<div class="sr-left"><span class="zdz-consent-text">' +
          '<span class="sr-label" id="zdz-consent-label">Records of how you use the app</span>' +
          '<span class="zdz-consent-sub" id="zdz-consent-sub">' + (agreed ? 'You agreed on ' + esc(when(c.agreed)) + '.' : 'You have not agreed. Nothing is recorded about you.') + '</span>' +
        '</span></div>' +
        '<div class="sr-right"><button type="button" class="zdz-btn zdz-btn--secondary" data-consent="' + (agreed ? 'withdraw' : 'read') + '" aria-describedby="zdz-consent-sub">' +
          (agreed ? 'Withdraw' : 'Read and agree') + '</button></div>' +
      '</div>' +
      '<p class="zdz-consent-status" role="status" aria-live="polite"></p>';
    var b = host.querySelector('[data-consent]');
    b.addEventListener('click', function () {
      var k = ui();
      if (!k || !k.sheet) { return; }
      if (b.getAttribute('data-consent') === 'read') { card(false); return; }
      k.sheet({
        title: 'Stop keeping usage records about you?',
        body: '<div class="zdz-consent-card"><p>From now on the app keeps no records of how you use it. Features that need them, such as the Assistant, ask again before you use them.</p></div>',
        actions: [
          { label: 'Cancel' },
          { label: 'Withdraw', kind: 'primary', onClick: function () {
            return send('withdraw').then(function () { if (k.toast) { k.toast('Withdrawn. Nothing more is recorded about you.'); } });
          } }
        ]
      });
    });
  }

  /** Open the card once the app has settled, never over another sheet. */
  function askSoon(preview, tries) {
    tries = tries || 0;
    if (d.documentElement.classList.contains('zdz-sheet-open') || !ui() || !ui().sheet) {
      if (tries < 20) { setTimeout(function () { askSoon(preview, tries + 1); }, 1500); }
      return;
    }
    card(preview);
  }

  function start() {
    if (c.on) { drawRow(); }
    var wantPreview = !!c.preview && /[?&]zdz-consent-preview=1(?:&|$)/.test(String(w.location.search || ''));
    if (wantPreview) { setTimeout(function () { askSoon(true); }, 600); return; }
    if (c.on && c.ask) { setTimeout(function () { askSoon(false); }, 1500); }
  }

  w.ZDZConsent = {
    version: '2.77.2',
    open: function () { return card(false); },
    state: function () { return c; },
    _draw: drawRow
  };

  if (d.readyState === 'loading') { d.addEventListener('DOMContentLoaded', start); } else { start(); }
})();
