/* The bell: in-app notices on the home screen and in the bottom navigation.
 *
 * ZDZ_Alert_Router writes in-app alerts to wp_zdz_notifications and serves them on /zorderz/v1/notifications. This
 * draws them: a bell with an unread badge on the home screen (the greeting row) and in the bottom navigation, and a
 * sheet (ZDZ.ui.sheet, the kit's part) listing the newest 20. Tapping a row marks it read and opens its link
 * (same-site only; the server checks it too). "Mark all read". A "Notices you get" section turns each alert type on
 * or off (POST /zorderz/v1/notifications/prefs). While this device has phone notifications off, the sheet's first
 * line offers to turn them on (ZDZPush.nudge; dismissible, per device), and ZDZBell.open({prefs: true}) opens
 * straight to "Notices you get" (Settings -> Notifications links to it).
 *
 *   - The badge asks /zorderz/v1/notifications/unread-count when the app opens, when it comes back into view, and
 *     once a minute while it is visible. The server caches the count 5 minutes and clears it on every new row.
 *   - Not drawn for the shared device or while Demo Mode runs (zdzData.bellOn false; the server refuses them anyway).
 *   - No confirm(), prompt() or alert(). Four modes through tokens (bell.css).
 *
 * Standalone on purpose: it adds itself to the home screen and the bottom navigation rather than editing app.js, so
 * the shell's own code stays untouched.
 */
(function () {
  'use strict';
  var w = window, d = document;
  var data = w.zdzData || {};
  if (!data.bellOn) { return; }

  var POLL_MS = 60000;
  var state = { count: 0, timer: null, inflight: null, sheet: null, sheetBody: null, lastAsk: 0 };
  var BELL_SVG = '<svg class="zdz-bell-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" ' +
    'stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' +
    '<path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/></svg>';

  function ui() { return (w.ZDZ && w.ZDZ.ui) || null; }
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function rest(path, opts) {
    var k = ui();
    if (!k || !k.rest) { return Promise.reject(new Error('The app is still loading. Try again in a moment.')); }
    // zdzData.apiUrl is ".../wp-json/zorderz/v1/" with pretty links, or ".../?rest_route=/zorderz/v1/" without them:
    // a query string then joins with "&", never a second "?".
    var base = String(data.apiUrl || '/wp-json/zorderz/v1/');
    var url = base + (base.indexOf('?') !== -1 ? String(path).replace('?', '&') : path);
    if (!/^https?:|^\//.test(url)) { url = '/' + url; }
    return k.rest(url, opts || {});
  }

  /* ---------------------------------------------------------------- the buttons and the badge */

  function label(n) { return n > 0 ? 'Notices, ' + n + ' unread' : 'Notices'; }

  // Writes only what changed. Every write is a DOM mutation, and the observer in start() watches the page: writing the
  // same text again re-fired it forever and froze the tab when a bell's container was missing.
  function paint() {
    var n = state.count, lab = label(n), txt = n > 99 ? '99+' : String(n);
    Array.prototype.forEach.call(d.querySelectorAll('.zdz-bell'), function (b) {
      if (b.getAttribute('aria-label') !== lab) { b.setAttribute('aria-label', lab); }
      if (b.getAttribute('title') !== lab) { b.setAttribute('title', lab); }
      var badge = b.querySelector('.zdz-bell-badge');
      if (n > 0) {
        if (!badge) { badge = d.createElement('span'); badge.className = 'zdz-bell-badge'; badge.setAttribute('aria-hidden', 'true'); b.appendChild(badge); }
        if (badge.textContent !== txt) { badge.textContent = txt; }
      } else if (badge) {
        badge.parentNode.removeChild(badge);
      }
    });
    var sum = state.sheet && state.sheetBody && state.sheetBody.querySelector('.zdz-bell-sum');
    if (sum) { var st = n ? n + ' unread' : 'All read'; if (sum.textContent !== st) { sum.textContent = st; } }
  }

  function makeButton(where) {
    var b = d.createElement('button');
    b.type = 'button';
    b.className = 'zdz-bell zdz-bell--' + where;
    b.setAttribute('data-zdz-bell', where);
    b.innerHTML = BELL_SVG + (where === 'nav' ? '<span class="ni-label">Notices</span>' : '');
    b.addEventListener('click', function () { open(); });   // (never the click event as opts)
    return b;
  }

  /** Is a bell missing from a container that is on the page? (Only then is there anything for place() to do.) */
  function missing() {
    var row = d.querySelector('#greeting-row .greeting-row-inner');
    if (row && !row.querySelector('[data-zdz-bell="home"]')) { return true; }
    var nav = d.querySelector('nav.bnav');
    return !!(nav && !nav.querySelector('[data-zdz-bell="nav"]'));
  }

  /** Put the bells in place (the greeting row is redrawn by the shell, so this runs again when it is). */
  function place() {
    var row = d.querySelector('#greeting-row .greeting-row-inner');
    if (row && !row.querySelector('[data-zdz-bell="home"]')) {
      var refresh = row.querySelector('#zdz-refresh-btn');
      var holder = d.createElement('span');
      holder.className = 'zdz-bell-home-wrap';
      holder.appendChild(makeButton('home'));
      if (refresh) { row.insertBefore(holder, refresh); } else { row.appendChild(holder); }
    }
    var nav = d.querySelector('nav.bnav');
    if (nav && !nav.querySelector('[data-zdz-bell="nav"]')) {
      nav.appendChild(makeButton('nav'));
    }
    paint();
  }

  /* ---------------------------------------------------------------- the count */

  function ask(force) {
    if (d.visibilityState === 'hidden') { return; }
    var now = Date.now();
    if (!force && state.inflight) { return; }
    if (!force && now - state.lastAsk < 5000) { return; }
    state.lastAsk = now;
    state.inflight = rest('notifications/unread-count', { retry: 0, timeout: 15000 }).then(function (r) {
      state.count = Math.max(0, parseInt(r && r.count, 10) || 0);
      paint();
    }, function () { /* a missed poll is retried next minute; the badge keeps its number */ }).then(function () {
      state.inflight = null;
    });
  }

  function startPolling() {
    stopPolling();
    state.timer = setInterval(function () { ask(false); }, POLL_MS);
  }
  function stopPolling() { if (state.timer) { clearInterval(state.timer); state.timer = null; } }

  /* ---------------------------------------------------------------- the list */

  function age(ts) {
    // created_at is the site's local time ("Y-m-d H:i:s"); zdzData.siteOffsetMin says how far that is from UTC.
    var m = /^(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2})(?::(\d{2}))?/.exec(String(ts || ''));
    if (!m) { return ''; }
    var off = parseInt(data.siteOffsetMin, 10) || 0;
    var t = Date.UTC(+m[1], +m[2] - 1, +m[3], +m[4], +m[5], +(m[6] || 0)) - off * 60000;
    var s = Math.max(0, Math.round((Date.now() - t) / 1000));
    if (s < 60) { return 'just now'; }
    if (s < 3600) { return Math.round(s / 60) + ' min ago'; }
    if (s < 86400) { var h = Math.round(s / 3600); return h + (h === 1 ? ' hour ago' : ' hours ago'); }
    var dd = Math.round(s / 86400);
    if (dd < 7) { return dd === 1 ? 'yesterday' : dd + ' days ago'; }
    return new Date(t).toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
  }

  function sameSite(link) {
    if (!link) { return ''; }
    try {
      var u = new URL(link, w.location.href);
      return u.origin === w.location.origin ? u.href : '';
    } catch (e) { return ''; }
  }

  function rowHtml(n) {
    var unread = +n.is_read !== 1;
    var link = sameSite(n.link);
    return '<li class="zdz-bell-item' + (unread ? ' is-unread' : '') + '">' +
      '<button type="button" class="zdz-bell-row" data-id="' + esc(n.id) + '" data-link="' + esc(link) + '">' +
        '<span class="zdz-bell-dot" aria-hidden="true"></span>' +
        '<span class="zdz-bell-text">' +
          '<span class="zdz-bell-title">' + esc(n.title) + (unread ? '<span class="zdz-sr"> (unread)</span>' : '') + '</span>' +
          (n.message ? '<span class="zdz-bell-msg">' + esc(n.message) + '</span>' : '') +
          '<span class="zdz-bell-age">' + esc(age(n.created_at)) + (n.type_label ? ' · ' + esc(n.type_label) : '') + '</span>' +
        '</span>' +
      '</button></li>';
  }

  function prefsHtml(types) {
    if (!types || !types.length) { return ''; }
    return '<details class="zdz-bell-prefs"><summary>Notices you get</summary>' +
      '<p class="zdz-bell-prefs-hint">Turn a kind off and it stops arriving here and on your phone.</p>' +
      '<ul class="zdz-bell-prefs-list">' + types.map(function (t) {
        var id = 'zdz-bell-pref-' + esc(t.type);
        return '<li><label for="' + id + '"><span>' + esc(t.label) + '</span>' +
          '<input type="checkbox" role="switch" id="' + id + '" data-type="' + esc(t.type) + '"' + (t.on ? ' checked' : '') + '>' +
          '</label></li>';
      }).join('') + '</ul><p class="zdz-bell-prefs-msg" role="status" aria-live="polite"></p></details>';
  }

  function draw(body, list, types) {
    // the server's count, not the rows loaded: with 45 unread the sheet shows 20 rows and says "45 unread"
    var unread = Math.max(state.count, list.filter(function (n) { return +n.is_read !== 1; }).length);
    body.innerHTML =
      '<div class="zdz-bell-head">' +
        '<span class="zdz-bell-sum">' + (unread ? unread + ' unread' : 'All read') + '</span>' +
        (unread ? '<button type="button" class="zdz-btn zdz-btn--quiet zdz-btn--sm" data-bell-all>Mark all read</button>' : '') +
      '</div>' +
      (list.length ? '<ul class="zdz-bell-list">' + list.map(rowHtml).join('') + '</ul>'
        : '<p class="zdz-bell-empty">No notices yet. When something needs you - an estimate you sold is accepted, a job is yours - it shows here.</p>') +
      '<p class="zdz-bell-status" role="status" aria-live="polite"></p>' +
      prefsHtml(types);
    wire(body, list, types);
    // one line while phone notifications are off on this device (never a prompt by itself)
    if (w.ZDZPush && w.ZDZPush.nudge) { w.ZDZPush.nudge(body, function () { if (state.sheet) { state.sheet.close('settings'); } }); }
    if (state.wantPrefs) {
      state.wantPrefs = false;
      var det = body.querySelector('.zdz-bell-prefs');
      if (det) { det.open = true; setTimeout(function () { try { det.scrollIntoView({ block: 'start', behavior: 'smooth' }); } catch (e) { det.scrollIntoView(); } }, 60); }
    }
  }

  function wire(body, list, types) {
    var k = ui();
    Array.prototype.forEach.call(body.querySelectorAll('.zdz-bell-row'), function (b) {
      b.addEventListener('click', function () {
        var id = b.getAttribute('data-id'), link = b.getAttribute('data-link');
        var item = b.closest('.zdz-bell-item');
        var go = function () {
          if (link) {
            if (state.sheet) { state.sheet.close('open'); }
            setTimeout(function () { w.location.href = link; }, 30);
          }
        };
        if (item && item.classList.contains('is-unread')) {
          item.classList.remove('is-unread');
          var sr = item.querySelector('.zdz-sr'); if (sr) { sr.textContent = ''; }
          state.count = Math.max(0, state.count - 1); paint();
          rest('notifications/' + encodeURIComponent(id) + '/read', { method: 'POST', body: {} }).then(function (r) {
            if (r && r.unread_count != null) { state.count = +r.unread_count || 0; paint(); }
          }, function () {
            // not marked: show it unread again, and put the count back
            item.classList.add('is-unread'); if (sr) { sr.textContent = ' (unread)'; }
            state.count += 1; paint();
          }).then(go);
        } else {
          go();
        }
      });
    });
    var all = body.querySelector('[data-bell-all]');
    if (all && k && k.busy) {
      all.addEventListener('click', function () {
        k.busy(all, function () {
          return rest('notifications/read-all', { method: 'POST', body: {} }).then(function () {
            state.count = 0; paint();
            list.forEach(function (n) { n.is_read = 1; });
            draw(body, list, types);
          });
        }).catch(function (e) {
          // the kit's sheet has a status line only when it has actions; the bell's has none, so it says it here
          var st = body.querySelector('.zdz-bell-status');
          if (st) { st.textContent = (e && e.message) || 'That did not work. Try again.'; st.classList.add('is-error'); }
        });
      });
    }
    Array.prototype.forEach.call(body.querySelectorAll('.zdz-bell-prefs input[data-type]'), function (cb) {
      cb.addEventListener('change', function () {
        var msg = body.querySelector('.zdz-bell-prefs-msg');
        cb.disabled = true;
        rest('notifications/prefs', { method: 'POST', body: { type: cb.getAttribute('data-type'), on: cb.checked } }).then(function (r) {
          if (msg) { msg.textContent = cb.checked ? 'On.' : 'Off. You can turn it back on here.'; }
          if (r && r.types) { types = r.types; }
        }, function (e) {
          cb.checked = !cb.checked;
          if (msg) { msg.textContent = (e && e.message) || 'That did not save. Try again.'; }
        }).then(function () { cb.disabled = false; });
      });
    });
  }

  function open(opts) {
    var k = ui();
    if (!k || !k.sheet) { return; }
    state.wantPrefs = !!(opts && opts.prefs);   // straight to "Notices you get"
    if (state.sheet) { return; }
    var body = d.createElement('div');
    body.className = 'zdz-bell-sheet';
    body.innerHTML = k.loadingHtml ? k.loadingHtml('Loading your notices…') : '<p>Loading your notices…</p>';
    state.sheetBody = body;
    state.sheet = k.sheet({ title: 'Notices', body: body, onClose: function () { state.sheet = null; state.sheetBody = null; ask(true); } });
    Promise.all([
      rest('notifications?limit=20'),
      rest('notifications/prefs').catch(function () { return { types: [] }; })
    ]).then(function (r) {
      var list = (r[0] && r[0].data) || [];
      state.count = Math.max(0, parseInt(r[0] && r[0].unread_count, 10) || 0); paint();
      draw(body, list, (r[1] && r[1].types) || []);
    }, function (e) {
      body.innerHTML = '<p class="zdz-bell-empty" role="alert">' + esc((e && e.message) || 'Your notices could not be loaded.') +
        '</p><button type="button" class="zdz-btn zdz-btn--secondary" data-bell-retry>Try again</button>';
      var again = body.querySelector('[data-bell-retry]');
      if (again) { again.addEventListener('click', function () { if (state.sheet) { state.sheet.close('retry'); } open(); }); }
    });
  }

  /* ---------------------------------------------------------------- start */

  function start() {
    place();
    ask(true);
    startPolling();
    // the shell redraws the greeting row (and may rebuild the nav): put the bells back when it does
    try {
      // only when a container that IS on the page lacks its bell, and never for the bell's own changes
      var mo = new MutationObserver(function (recs) {
        var outside = false;
        for (var i = 0; i < recs.length && !outside; i++) {
          var t = recs[i].target;
          if (!(t && t.closest && t.closest('.zdz-bell'))) { outside = true; }
        }
        if (outside && missing()) { place(); }
      });
      mo.observe(d.getElementById('view-main') || d.body, { childList: true, subtree: true });
    } catch (e) { /* place() on the events below is enough */ }
    d.addEventListener('visibilitychange', function () {
      if (d.visibilityState === 'visible') { ask(true); startPolling(); } else { stopPolling(); }
    });
    d.addEventListener('zdz_widgets_rendered', place);
    w.addEventListener('focus', function () { ask(false); });
  }

  /* open({prefs:true}) opens with "Notices you get" expanded. */
  w.ZDZBell = { refresh: function () { ask(true); }, open: open, count: function () { return state.count; } };
  if (d.readyState === 'loading') { d.addEventListener('DOMContentLoaded', start); } else { start(); }
})();
