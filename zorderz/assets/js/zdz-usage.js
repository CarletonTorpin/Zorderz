/* Settings -> Your app use.
 *
 *   - "What's recorded about you": the person's own last 30 days, per app (opens, time open, last use), from
 *     GET /zorderz/v1/usage/mine, in the kit's sheet. Only their own rows: the server reads by the signed-in person.
 *   - "Reset my app order": where the home screen learns an app order from use (zdzData.forYou.learn), forget what
 *     this person opened so far (POST /zorderz/v1/for-you/reset); the role's starting list leads again until new
 *     opens build up. Not drawn unless that feature is on.
 *   - Nothing for the shared device or in Demo Mode (zdzData.track.mine false; the server refuses them too).
 *
 * Standalone like zdz-consent.js: draws into #settings-usage; the styles are in consent.css (tokens only).
 */
(function () {
  'use strict';
  var w = window, d = document;
  var data = w.zdzData || {};
  var tr = data.track || {};

  function ui() { return w.ZDZ && w.ZDZ.ui; }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (ch) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch]; }); }
  function dur(secs) {
    var s = Math.max(0, parseInt(secs, 10) || 0);
    if (s < 60) { return s ? 'under a minute' : '-'; }
    var m = Math.round(s / 60);
    if (m < 60) { return m + ' min'; }
    var h = Math.floor(m / 60), r = m % 60;
    return h + ' h' + (r ? ' ' + r + ' min' : '');
  }
  function day(iso) {
    var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(iso || ''));
    if (!m) { return '-'; }
    var mon = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'][parseInt(m[2], 10) - 1];
    return parseInt(m[3], 10) + ' ' + mon;
  }
  function learning() { var f = w.zdzData && w.zdzData.forYou; return !!(f && f.learn); }
  function section() { return d.getElementById('settings-usage'); }

  function mineBody(r) {
    var apps = (r && Array.isArray(r.apps)) ? r.apps : [];
    var head = r && r.recording === false
      ? '<p>Nothing is recorded about you now. What is listed below was recorded before.</p>'
      : '<p>Which apps you opened in the last 30 days, and for how long. Kept to improve the app, never to grade anyone.</p>';
    if (!apps.length) { return '<div class="zdz-consent-card zdz-usage-card">' + head + '<p class="zdz-consent-more">Nothing yet.</p></div>'; }
    return '<div class="zdz-consent-card zdz-usage-card">' + head +
      '<table class="zdz-usage-table"><caption class="zdz-usage-cap">Your last 30 days</caption>' +
      '<thead><tr><th scope="col">App</th><th scope="col">Opens</th><th scope="col">Time open</th><th scope="col">Last</th></tr></thead><tbody>' +
      apps.map(function (a) {
        return '<tr><th scope="row">' + esc(a.name || a.id) + '</th><td>' + (parseInt(a.opens, 10) || 0) + '</td><td>' + esc(dur(a.secs)) + '</td><td>' + esc(day(a.last)) + '</td></tr>';
      }).join('') + '</tbody></table>' +
      '<p class="zdz-consent-more">Time open counts only while an app is open as its own page and the app is on screen.</p></div>';
  }

  function showMine() {
    var k = ui();
    if (!k || !k.sheet || !k.rest) { return; }
    return k.rest('zorderz/v1/usage/mine').then(function (r) {
      k.sheet({ title: "What's recorded about you", body: mineBody(r), actions: [{ label: 'Close', kind: 'primary' }] });
    }, function () {
      if (k.toast) { k.toast('That could not be loaded. Try again in a moment.'); }
    });
  }

  function resetOrder() {
    var k = ui();
    if (!k || !k.sheet || !k.rest) { return; }
    k.sheet({
      title: 'Reset your app order?',
      body: '<div class="zdz-consent-card"><p>Home forgets which apps you opened at each time of day, and starts again from your role\'s usual apps. Nothing else changes, and no app is added or taken away.</p></div>',
      actions: [
        { label: 'Cancel' },
        { label: 'Reset', kind: 'primary', onClick: function () {
          return k.rest('zorderz/v1/for-you/reset', { method: 'POST', body: {} }).then(function (r) {
            if (r && r.forYou && w.zdzData) { w.zdzData.forYou = r.forYou; }
            if (typeof w.zdzForYouRedraw === 'function') { try { w.zdzForYouRedraw(); } catch (e) { /* home redraws on its own next time */ } }
            if (k.toast) { k.toast('Your app order starts fresh.'); }
          });
        } }
      ]
    });
  }

  function draw() {
    var host = section();
    if (!host) { return; }
    if (!tr.mine) { host.hidden = true; host.innerHTML = ''; return; }
    host.hidden = false;
    var rec = tr.rec !== false;
    host.innerHTML =
      '<h4>Your app use</h4>' +
      '<div class="settings-row zdz-consent-row">' +
        '<div class="sr-left"><span class="zdz-consent-text">' +
          '<span class="sr-label">What\'s recorded about you</span>' +
          '<span class="zdz-consent-sub" id="zdz-usage-sub">' + (rec ? 'Which apps you open and for how long, to improve the app.' : 'Nothing is recorded about you now.') + '</span>' +
        '</span></div>' +
        '<div class="sr-right"><button type="button" class="zdz-btn zdz-btn--secondary" data-usage="mine" aria-describedby="zdz-usage-sub">See it</button></div>' +
      '</div>' +
      (learning()
        ? '<div class="settings-row zdz-consent-row">' +
            '<div class="sr-left"><span class="zdz-consent-text">' +
              '<span class="sr-label">Your home apps</span>' +
              '<span class="zdz-consent-sub" id="zdz-usage-order-sub">Ordered by what you open at each time of day.</span>' +
            '</span></div>' +
            '<div class="sr-right"><button type="button" class="zdz-btn zdz-btn--secondary" data-usage="reset" aria-describedby="zdz-usage-order-sub">Reset my order</button></div>' +
          '</div>'
        : '');
    host.querySelector('[data-usage="mine"]').addEventListener('click', showMine);
    var r = host.querySelector('[data-usage="reset"]');
    if (r) { r.addEventListener('click', resetOrder); }
  }

  w.ZDZUsage = { draw: draw, mine: showMine, reset: resetOrder };
  if (d.readyState === 'loading') { d.addEventListener('DOMContentLoaded', draw); } else { draw(); }
})();
