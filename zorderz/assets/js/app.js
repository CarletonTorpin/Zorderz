'use strict';

// ---- DATA ----
// An app's colour means its group, and the THEME decides both (ZDZ_App_Groups: Sales orange · Field green · Team sky
// blue · Office blue · Tools pink; vermilion only for "needs you"). Every app lands in one of the five groups (an app
// with no entry is grouped from the category it declares), so none falls out of the grouped grid.
const ZDZ_GROUPS = (typeof zdzData !== 'undefined' && zdzData.appGroups && Array.isArray(zdzData.appGroups.groups) && zdzData.appGroups.groups.length)
  ? zdzData.appGroups.groups
  : [ { id: 'sales', label: 'Sales', color: '#E69F00', ink: '#1A1A1A' }, { id: 'field', label: 'Field', color: '#009E73', ink: '#1A1A1A' },
      { id: 'team', label: 'Team', color: '#56B4E9', ink: '#1A1A1A' }, { id: 'office', label: 'Office', color: '#0072B2', ink: '#FFFFFF' },
      { id: 'tools', label: 'Tools', color: '#CC79A7', ink: '#1A1A1A' } ];
const CATEGORIES = ZDZ_GROUPS.map(g => ({ id: g.label, color: g.color, ink: g.ink }));
// The icon plate: the group's color, the ink that reads on it, and the app id the "needs you" badge finds it by.
function zdzPlate(a) {
  a = a || {};
  const id = String(a.id || '').replace(/[^a-z0-9_-]/gi, '');
  const hx = (c, f) => (/^#[0-9a-f]{3}([0-9a-f]{3})?$/i.test(String(c || '')) ? c : f);
  return 'data-zdz-app="' + id + '" style="background:' + hx(a.cc, '#5B5B5B') + ';color:' + hx(a.ink, '#FFFFFF') + '"';
}

const PINNED = {
  administrator: ['sales-analytics','estimate-creator','leads','surveys'],
  zdz_owner: ['estimate-creator','sales-analytics'],
  zdz_admin: ['sales-analytics','estimate-creator','leads','surveys'],
  zdz_sales: ['estimate-creator','sales-analytics','leads','game','internal-messaging'],
  zdz_operator: ['estimate-creator','surveys'],
  zdz_mfg: ['estimate-creator'],
  zdz_tech: ['estimate-creator','sales-analytics','surveys','leads','knowledge-vault','stock','game','internal-messaging']
};

// ---- STATE ----
const state = {
  role: zdzData.userRole,
  isAdmin: !!zdzData.isAdmin,
  user: { name: zdzData.userName, ini: zdzData.userInitial, label: zdzData.userRoleLabel || zdzData.userRole, firstName: zdzData.userFirstName || zdzData.userName.split(' ')[0] },
  currentView: 'sv-dash',
  recentApps: [],
  theme: 'system'
};

try {
  const recent = localStorage.getItem('zdz_recent');
  if (recent) state.recentApps = JSON.parse(recent);
  const theme = localStorage.getItem('zdz_theme');
  if (theme) state.theme = theme;
} catch(e) {}

// ---- HAPTICS ----
const Haptics = {
  tap() { try { navigator.vibrate?.(10) } catch(e){} },
  success() { try { navigator.vibrate?.([15,40,15]) } catch(e){} },
  error() { try { navigator.vibrate?.([40,80,40,80,40]) } catch(e){} }
};

// ---- ACTIVITY TRACKING ----
// Fire-and-forget usage events via REST: app opens and how long each stays open, view switches, dashboard loads, the
// heartbeat, searches (never the words) and the actions apps count. Each event carries the device class (phone /
// desktop_app / browser), this page load's session id and how long ago it happened (age, measured here, so a wrong
// phone clock cannot move it); app_close carries how long the app was open (secs) and app_action an app's registered
// action key (act) and a number (ref).
// Nothing is queued when zdzData.track.rec is false: ZDZ_Consent says this person is not recorded. A batch is kept in
// localStorage until the server answers it with JSON; one that failed (401, 403, 5xx, a host's error page, no answer
// before the page closed) is resent ONCE on the next visit, under the same batch id, which the server writes only once.
var _zdzTrackQueue = [];
var _zdzTrackTimer = null;
var ZDZ_TRACK_SID = (Date.now().toString(36) + Math.random().toString(36).slice(2, 8)).slice(-12);
function _zdzTrackOff() { return typeof zdzData !== 'undefined' && zdzData && zdzData.track && zdzData.track.rec === false; }
function zdzTrack(actionType, detail, appId, extra) {
  if (_zdzTrackOff()) return;
  var e = { action_type: actionType || 'page_view', detail: detail || '', app_id: appId || '', device: zdzDeviceClass(), sid: ZDZ_TRACK_SID, at: Date.now() };
  if (extra) { ['secs', 'act', 'ref'].forEach(function (k) { if (extra[k] !== undefined && extra[k] !== null) e[k] = extra[k]; }); }
  _zdzTrackQueue.push(e);
  // Debounce: flush after 2s of quiet, or immediately if queue is large
  clearTimeout(_zdzTrackTimer);
  if (_zdzTrackQueue.length >= 5) {
    _zdzFlushTrack();
  } else {
    _zdzTrackTimer = setTimeout(_zdzFlushTrack, 2000);
  }
}
function _zdzFlushTrack() {
  if (!_zdzTrackQueue.length) return;
  var batch = _zdzTrackQueue.splice(0, 20);
  // a page left open for hours renews its REST nonce first, where the shell offers a way to (a stale one is refused)
  if (typeof window.zdzFreshNonce === 'function' && document.visibilityState === 'visible' && Date.now() - _zdzPageAt > 6 * 3600 * 1000) {
    window.zdzFreshNonce().then(function () { _zdzSendTrack(batch); }, function () { _zdzSendTrack(batch); });
    return;
  }
  _zdzSendTrack(batch);
}
function _zdzSendTrack(batch, resend) {
  try {
    // fetch with keepalive (it outlives the page and carries the X-WP-Nonce header, so the request is signed in);
    // the batch waits in localStorage until the answer is JSON. Ages are worked out at the moment of sending.
    var bid = (resend && resend.bid) || ('b' + Date.now().toString(36) + Math.random().toString(36).slice(2, 8));
    if (!resend) _zdzPendingPut({ bid: bid, events: batch, made: Date.now() });
    var now = Date.now();
    var events = batch.map(function (e) { var o = {}; for (var k in e) { if (k !== 'at') o[k] = e[k]; } o.age = Math.max(0, Math.round((now - (e.at || now)) / 1000)); return o; });
    var body = { bid: bid, events: events };
    if (resend) body.re = 1;   // from an earlier visit: the server does not throttle it (its batch id guards it)
    var payload = JSON.stringify(body);
    var url = zdzData.apiUrl + 'track';
    // sendBeacon cannot send an X-WP-Nonce header, so a browser without fetch carries the nonce in the URL
    var beaconUrl = url + (url.indexOf('?') === -1 ? '?' : '&') + '_wpnonce=' + encodeURIComponent(zdzData.nonce || '');
    var done = function () { _zdzPendingDrop(bid); };
    if (typeof fetch === 'function') {
      var p;
      try {
        p = fetch(url, {
          method: 'POST', headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': zdzData.nonce },
          body: payload, keepalive: true, credentials: 'same-origin'
        });
      } catch (err) { p = null; }
      if (p) {
        p.then(function (r) {
          return r.text().then(function (t) {
            var j = null;
            try { j = JSON.parse(t); } catch (x) { /* a host's error page or a PHP error: not an answer */ }
            if (r.ok && j && j.success) { done(); return; }
            if (resend) done();   // one resend, never more
          });
        }).catch(function () { if (resend) done(); });
        return;
      }
    }
    if (navigator.sendBeacon) {
      var blob = new Blob([payload], { type: 'application/json' });
      navigator.sendBeacon(beaconUrl, blob);   // no answer to read: the batch waits; its id stops a double write
      if (resend) done();
    }
  } catch(e) {}
}
// The batches still waiting for an answer, per person on this device. At most 30 (two hours of heartbeats offline),
// none over 2 days old. They are resent on the next visit, not when the signal comes back.
function _zdzPendingKey() { return 'zdz_track_pending_' + String((typeof zdzData !== 'undefined' && zdzData && zdzData.userId) || 0).replace(/[^0-9]/g, ''); }
function _zdzPendingGet() {
  var list = [];
  try { list = JSON.parse(localStorage.getItem(_zdzPendingKey()) || '[]'); } catch (e) { list = []; }
  var cut = Date.now() - 2 * 86400000;
  return (Array.isArray(list) ? list : []).filter(function (b) { return b && typeof b.bid === 'string' && Array.isArray(b.events) && b.made > cut; });
}
function _zdzPendingSet(list) {
  try { if (list.length) localStorage.setItem(_zdzPendingKey(), JSON.stringify(list.slice(-30))); else localStorage.removeItem(_zdzPendingKey()); } catch (e) { /* private window: nothing is kept */ }
}
function _zdzPendingPut(b) { var l = _zdzPendingGet(); l.push(b); _zdzPendingSet(l); }
function _zdzPendingDrop(bid) { _zdzPendingSet(_zdzPendingGet().filter(function (b) { return b.bid !== bid; })); }
// On the next visit: each batch left from an earlier page is resent once, then forgotten.
function zdzTrackResend() {
  if (_zdzTrackOff()) { _zdzPendingSet([]); return; }
  var left = _zdzPendingGet().filter(function (b) { return b.made < _zdzPageAt; });
  if (!left.length) return;
  var mine = {}; left.forEach(function (b) { mine[b.bid] = 1; });
  _zdzPendingSet(_zdzPendingGet().filter(function (b) { return !mine[b.bid]; }));   // never a second resend
  var go = function () { left.forEach(function (b) { _zdzSendTrack(b.events, { bid: b.bid }); }); };
  if (typeof window.zdzFreshNonce === 'function') { window.zdzFreshNonce().then(go, go); } else { go(); }
}
var _zdzPageAt = Date.now();
// The device class: a phone or tablet; the installed desktop app (a standalone window); or a browser tab.
function zdzDeviceClass() {
  try {
    var ua = navigator.userAgent || '';
    if (/iPhone|iPad|iPod|Android|Mobile/i.test(ua) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1)) return 'phone';
    var mm = function (q) { return !!(window.matchMedia && window.matchMedia(q).matches); };
    return (navigator.standalone === true || mm('(display-mode: standalone)') || mm('(display-mode: fullscreen)')) ? 'desktop_app' : 'browser';
  } catch (e) { return ''; }
}
// ---- A fresh REST nonce ----
// WordPress nonces last 12-24 hours, but the installed app and the desktop app stay open for days: a page left open
// overnight sends a stale nonce and WordPress answers "Cookie check failed". zdzFreshNonce() asks the server for a
// new one (admin-ajax zdz_fresh_nonce, cookie only) and keeps it in zdzData.nonce, which everything here reads. It
// also says whether THIS device is in Demo Mode. Called before Demo Mode's calls, and when the page comes back
// to the front after 6 hours.
var _zdzNonceAt = Date.now();
function zdzFreshNonce() {
  var body = new URLSearchParams();
  body.append('action', 'zdz_fresh_nonce');
  return fetch(zdzData.ajaxUrl || '/wp-admin/admin-ajax.php', {
    method: 'POST', body: body, credentials: 'same-origin'
  }).then(function (r) {
    return r.text().then(function (t) {
      var j = null;
      try { j = JSON.parse(t); } catch (e) { /* a host's error page or a PHP error page: not an answer */ }
      if (!r.ok || !j || !j.success || !j.data || !j.data.rest) { throw new Error(r.status === 401 || r.status === 400 ? 'signed_out' : 'no_answer'); }
      zdzData.nonce = j.data.rest;
      _zdzNonceAt = Date.now();
      return { nonce: j.data.rest, demo: !!j.data.demo };
    });
  });
}
window.zdzFreshNonce = zdzFreshNonce;
document.addEventListener('visibilitychange', function() {
  if (document.visibilityState !== 'visible') return;
  if (zdzData.kioskDemoActive) {
    // An administrator may end this device's Demo Mode from wp-admin. Then every call from this
    // page would fail "Cookie check failed" (its nonce is the General account's), so check on coming to the front
    // and reload as the administrator.
    // Signed out from wp-admin ("Sign the device out"): reload too, to the sign-in page. A lost signal is not that.
    zdzFreshNonce().then(function (st) { if (!st.demo) window.location.reload(); },
      function (e) { if (e && e.message === 'signed_out') window.location.reload(); });
  } else if (Date.now() - _zdzNonceAt > 6 * 3600 * 1000) {
    zdzFreshNonce().catch(function(){});
  }
});
// Flush on page hide (tab close, navigate away); an app open at that moment sends its time open so far first (the
// page may never come back).
document.addEventListener('visibilitychange', function() {
  if (document.visibilityState === 'hidden') { _zdzAppPause(); _zdzFlushTrack(); }
  else _zdzAppResume();
});
window.zdzTrack = zdzTrack;
// An app records one of its own actions. The key must be registered for that app on the server's
// zdz_register_usage_actions filter (or it is dropped there); ref is a number only (a project number), never a name or
// free text. window.zdzTrackAction('estimate-creator', 'estimate_sent', 6523).
window.zdzTrackAction = function (appId, key, ref) {
  if (!/^[a-z0-9_-]{1,64}$/.test(String(appId || '')) || !/^[a-z][a-z0-9_]{0,39}$/.test(String(key || ''))) return;
  var r = (ref === undefined || ref === null) ? '' : String(ref);
  zdzTrack('app_action', '', appId, { act: key, ref: /^\d{1,12}$/.test(r) ? r : undefined });
};

// ---- How long an app stays open ----
// Bridge.currentApp is set whenever an app opens as a page and cleared when it closes, so watching it times every app
// without each app doing anything. Only the time the page is in front counts: when it goes to the back, the time so
// far is sent as an app_close; back in front, the same app carries on as a new stretch, with no new app_open (so opens
// are never double-counted). An inline card that never opens as a page has no time open.
var _zdzOpenNow = null;   // { id, since: ms or 0 while hidden, secs }
function _zdzOpenSecs(o) { return o.secs + (o.since ? Math.max(0, (Date.now() - o.since) / 1000) : 0); }
function _zdzAppOpened(id) {
  // an app's own id only, never a synthetic surface (a built-in web page)
  if (!/^[a-z0-9][a-z0-9_-]{0,63}$/.test(String(id || ''))) { _zdzOpenNow = null; return; }
  _zdzOpenNow = { id: id, since: document.visibilityState === 'visible' ? Date.now() : 0, secs: 0 };
}
function _zdzAppClosed() {
  var o = _zdzOpenNow; _zdzOpenNow = null;
  if (!o || (!o.since && !o.secs)) return;
  zdzTrack('app_close', '', o.id, { secs: Math.round(_zdzOpenSecs(o)) });
}
function _zdzAppPause() {
  var o = _zdzOpenNow;
  if (!o || !o.since) return;
  zdzTrack('app_close', '', o.id, { secs: Math.round(_zdzOpenSecs(o)) });
  o.secs = 0; o.since = 0;
}
function _zdzAppResume() { if (_zdzOpenNow && !_zdzOpenNow.since) _zdzOpenNow.since = Date.now(); }
function zdzUsageWatchApps() {
  var B = window.Bridge;
  if (!B || B.__zdzWatched) return;
  var cur = B.currentApp;
  try {
    Object.defineProperty(B, 'currentApp', {
      configurable: true, enumerable: true,
      get: function () { return cur; },
      set: function (v) {
        var a = cur && cur.id, b = v && v.id;
        cur = v;
        if (a !== b) { if (a) _zdzAppClosed(); if (b) _zdzAppOpened(b); }
      }
    });
    B.__zdzWatched = true;
  } catch (e) { return; }
  if (cur && cur.id) _zdzAppOpened(cur.id);
}

// ---- Searches, without the words ----
// "search" says only that a search ran, once per search (until the box is emptied), and in which box: 'ask' (the ask
// bar) or 'apps' (the app search). "command_palette" says only which kind of result was opened: 'app', 'ask' (the
// chat), 'action' (a shortcut), 'answer' (an inline answer) or 'quick_answer' (Enter on a quick answer). The typed
// words never leave this page in these events (the server also keeps nothing else).
var _zdzSearchRan = {};
function zdzUsageWatchSearch() {
  if (window.__zdzSearchWatched) return;
  window.__zdzSearchWatched = true;
  document.addEventListener('input', function (e) {
    var t = e.target, box = t && (t.id === 'cmd-input' ? 'ask' : (t.id === 'app-search' ? 'apps' : ''));
    if (!box) return;
    if (!String(t.value || '').trim()) { _zdzSearchRan[box] = false; return; }
    if (!_zdzSearchRan[box]) { _zdzSearchRan[box] = true; zdzTrack('search', box); }
  }, true);
  document.addEventListener('click', function (e) {
    var el = e.target && e.target.closest ? e.target.closest('#cmd-results .cmd-item, #search-results .search-item') : null;
    if (!el) return;
    var on = el.getAttribute('onclick') || '';
    var kind = on.indexOf('zdzRunInline') !== -1 ? 'answer'
      : (on.indexOf('PendingShellAction') !== -1 ? 'action'
      : (on.indexOf("'sales-analytics'") !== -1 ? 'ask' : 'app'));
    var m = /openApp\('([a-z0-9_-]{1,64})'/.exec(on);
    zdzTrack('command_palette', kind, kind === 'app' && m ? m[1] : '');
    _zdzSearchRan = {};
  }, true);
}

// ---- TOAST ----
function showToast(msg, duration = 2500) {
  const c = document.getElementById('toast-container');
  if (!c) return;
  const t = document.createElement('div');
  t.className = 'toast';
  t.textContent = msg;
  c.appendChild(t);
  setTimeout(() => { t.classList.add('out'); setTimeout(() => t.remove(), 300); }, duration);
}

function refreshIcons() {
  try { if (window.lucide) lucide.createIcons(); } catch(e) {}
}

// ---- TEXT SCALE (accessibility) ----
function applyTextScale(on) {
  if (on) { document.documentElement.setAttribute('data-zdz-textscale', 'lg'); }
  else { document.documentElement.removeAttribute('data-zdz-textscale'); }
  try { localStorage.setItem('zdz_textscale', on ? 'lg' : 'off'); } catch(e) {}
  // launcher names are fitted to the size this sets.
  if (typeof zdzFitLabels === 'function') { try { zdzFitLabels(); } catch (e) {} }
}
function toggleTextScale() {
  applyTextScale(document.documentElement.getAttribute('data-zdz-textscale') !== 'lg');
}

// ---- THEME ----
function applyTheme(t) {
  state.theme = t;
  document.documentElement.setAttribute('data-theme', t);
  try { localStorage.setItem('zdz_theme', t); } catch(e) {}
  const meta = document.querySelector('meta[name="theme-color"]');
  if (t === 'dark' || (t === 'system' && window.matchMedia('(prefers-color-scheme:dark)').matches)) {
    meta?.setAttribute('content', '#020617');
  } else if (t === 'sunlight') {
    meta?.setAttribute('content', '#000000');
  } else {
    meta?.setAttribute('content', '#1E3A5F');
  }
  // Update the nav bar logo for the current theme
  updateNavLogo();
}

// ---- NAV LOGO (theme-aware + A5 vertical sidebar variant) ----
// PHP-side fallback now ensures both logoDark and logoLight
// are always populated (if either is set). JS cascade is kept as a safety net.
function updateNavLogo() {
  const img = document.getElementById('bnav-logo-img');
  if (!img) return;
  const dark     = img.dataset.logoDark;
  const light    = img.dataset.logoLight;
  const vertical = img.dataset.logoVertical;
  const t = state.theme;

  const isDark = (t === 'dark') ||
    (t === 'system' && window.matchMedia('(prefers-color-scheme:dark)').matches);

  // On sidebar (≥820px), prefer the vertical logo if available
  const isSidebar = window.matchMedia('(min-width: 820px)').matches;
  if (isSidebar && vertical) {
    img.src = vertical;
    img.classList.add('logo-vertical');
    return;
  }
  img.classList.remove('logo-vertical');

  if (isDark) {
    img.src = dark || light || img.src;
  } else {
    img.src = light || dark || img.src;
  }
}

// ---- NAVIGATION ----
function switchView(viewId) {
  // BLACK-FLASH FIX. `.sub-view.active{animation:viewFadeIn}` plays an
  // opacity 0→1 fade whenever `.active` is (re)added. Tapping an app icon while
  // already on the dashboard called switchView('sv-dash') again, which removed
  // then re-added `.active` on #sv-dash — RESTARTING the fade from opacity:0, so
  // the whole dashboard flashed to black and only repainted after the launch
  // scroll / a manual scroll. If the requested view is already active, do
  // nothing structural (no class churn, no animation restart, no scroll reset) —
  // the caller's own scroll (e.g. bridge.js launch) then runs on a stable,
  // fully-painted view. Only a genuine view CHANGE toggles `.active` (and thus
  // plays the fade) and resets scroll to top.
  var target = document.getElementById(viewId);
  var isSameView = target && target.classList.contains('active');
  // an app open as a page lives in the dashboard view; going to another view closes it first, or it
  // would stay "open" but hidden with the bottom bar gone.
  if (viewId !== 'sv-dash' && window.Bridge && Bridge._focused) Bridge.closeApp();
  if (!isSameView) {
    document.querySelectorAll('.sub-view').forEach(v => v.classList.remove('active'));
    target?.classList.add('active');
  }

  // Highlight the correct nav item
  document.querySelectorAll('.ni').forEach(n => {
    n.classList.toggle('active', n.dataset.view === viewId);
  });

  // Settings is toggled via the logo button, not a .ni item
  const logoBtn = document.getElementById('bnav-logo');
  if (logoBtn) {
    logoBtn.classList.toggle('settings-active', viewId === 'sv-settings');
  }

  // When settings is active, deactivate all .ni items
  if (viewId === 'sv-settings') {
    document.querySelectorAll('.ni').forEach(n => n.classList.remove('active'));
  }

  state.currentView = viewId;
  // home shown again: fit its names (a browser without ResizeObserver has no other signal).
  if (viewId === 'sv-dash' && !isSameView && typeof zdzFitLabels === 'function') { setTimeout(function () { try { zdzFitLabels(); } catch (e) {} }, 0); }
  // only reset scroll to top on a genuine view CHANGE. On a same-view
  // re-entry (e.g. tapping a dock icon while already on the dashboard) leave the
  // scroll alone so the caller's launch scroll (bridge.js) isn't fought by a
  // top-reset, and there's no jump-to-top → jump-to-widget flicker.
  if (!isSameView) document.getElementById(viewId)?.scrollTo(0, 0);
  Haptics.tap();
  refreshIcons();
  // Track view navigation for audit log
  zdzTrack('view_switch', viewId);
}

// ---- GREETING (T1: 3-tier greeting) ----
function renderGreeting() {
  const el = document.getElementById('greeting-row');
  if (!el) return;
  const hour = new Date().getHours();
  let greeting;
  if      (hour >= 5  && hour < 12) greeting = 'Good morning';
  else if (hour >= 12 && hour < 17) greeting = 'Good afternoon';
  else                               greeting = 'Good evening';
  // Inner wrapper for greeting + refresh button
  el.innerHTML = `<div class="greeting-row-inner">
    <h2>${greeting}, ${state.user.firstName}</h2>
    <button class="zdz-refresh-btn" id="zdz-refresh-btn" aria-label="Refresh" title="Refresh">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
    </button>
  </div>`;
  // Wire up refresh
  const btn = document.getElementById('zdz-refresh-btn');
  if (btn) {
    btn.addEventListener('click', function () {
      Haptics.tap();
      showToast('Refreshing…');
      setTimeout(function () { window.location.reload(); }, 150);
    });
  }
}

// tiny, subtle build stamp at the bottom of the dashboard so the
// CURRENTLY-LOADED theme build is verifiable at a glance (the stale-PWA-shell
// problem: data can be live while the cached shell/CSS is old). Reads the
// version localized into zdzData by functions.php. Fails silently.
function renderBuildStamp() {
  try {
    var el = document.getElementById('zdz-build-stamp');
    if (!el) return;
    var v = (typeof zdzData !== 'undefined' && zdzData.themeVersion) ? zdzData.themeVersion : '';
    el.textContent = v ? ('Zorderz ' + v) : '';
  } catch (e) {}
}

// ---- LEADS ACTION TILE ----
// Surfaces the signed-in salesperson's "you have N leads to contact" at the top
// of the dashboard, sourced from the platform's unified action-items feed
// (GET /zorderz/v1/dashboard-items, which plugins populate via zdz_dashboard_action_items).
// Clicking deep-links into the Leads app in rep mode ({ view: 'my-leads' }).
//
// Reliability notes (matches the robust-systems approach used across the app):
//   • Never breaks the dashboard: any fetch/shape error just hides the tile.
//   • Kiosk (zdz_general) never shows it (most-restrictive-wins; the server also
//     refuses to register a per-user item for kiosk).
//   • Cheap + cached server-side (the producer caches the count in a transient),
//     so calling this on each dashboard render is fine.
//   • Generic over plugins: renders whichever items the feed returns whose
//     app_id is the Leads app; degrades silently if none.
function renderLeadsTile() {
  const el = document.getElementById('leads-tile');
  if (!el) return;

  // Kiosk never sees a personal leads tile.
  if (state.role === 'zdz_general') { el.style.display = 'none'; return; }

  // Default hidden until we know there's something to show (no layout flash).
  el.style.display = 'none';
  el.innerHTML = '';

  if (!zdzData || !zdzData.apiUrl) return;

  fetch(zdzData.apiUrl + 'dashboard-items', {
    headers: { 'X-WP-Nonce': zdzData.nonce },
    credentials: 'same-origin'
  })
    .then(function (r) { return r.ok ? r.json() : null; })
    .then(function (data) {
      if (!data || !data.success || !Array.isArray(data.items)) return;

      // Find the Leads item(s). The producer uses app_id 'leads'.
      const leadItems = data.items.filter(function (it) {
        return it && it.app_id === 'leads' && (it.count | 0) > 0;
      });
      if (!leadItems.length) return; // nothing pending → keep it clean

      const item = leadItems[0];
      const count = item.count | 0;
      const label = String(item.label || (count + ' leads to contact'));
      const urgency = item.urgency || 'low';
      const color = /^#[0-9a-f]{3,8}$/i.test(item.color || '') ? item.color : '#22C55E';

      // Build the tile. Whole tile is a button → opens Leads in rep mode.
      el.style.display = '';
      el.innerHTML =
        '<button type="button" class="leads-tile-btn leads-tile--' + escapeHtml(urgency) + '" ' +
          'style="--leads-tile-accent:' + escapeHtml(color) + '" ' +
          'aria-label="' + escapeHtml(label) + '. Open your leads.">' +
          '<span class="leads-tile-icon" aria-hidden="true">' +
            '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><polyline points="16 11 18 13 22 9"/></svg>' +
          '</span>' +
          '<span class="leads-tile-body">' +
            '<span class="leads-tile-count">' + count + '</span>' +
            '<span class="leads-tile-label">' + escapeHtml(label) + '</span>' +
          '</span>' +
          '<span class="leads-tile-cta" aria-hidden="true">View →</span>' +
        '</button>';

      const btn = el.querySelector('.leads-tile-btn');
      if (btn) {
        btn.addEventListener('click', function () {
          if (typeof Haptics !== 'undefined' && Haptics.tap) { Haptics.tap(); }
          openApp('leads', { view: 'my-leads', source: 'dashboard-tile' });
        });
      }
    })
    .catch(function () { /* tile is optional — never break the dashboard */ });
}

// ---- QUICK STATS / KPI METRICS (role-based) ----
function renderQuickStats() {
  const el = document.getElementById('quick-stats');
  if (!el) return;

  // ── GENERAL (shared kiosk): No KPI strip at all ──
  // The shared workshop iPad must not show revenue-style tiles
  // (Open Estimates / Paid Jobs MTD / Pipeline). Hide the whole strip
  // exactly the way the operator dashboard does. Defence in depth: the
  // KPI REST endpoint also refuses revenue for this role server-side
  // (class-zdz-kpi-metrics.php) via the all-deny view_company_revenue
  // permission, and fetchKPIMetrics() already skips roles outside its
  // kpiRoles allow-list — so zdz_general never even requests the numbers.
  if (state.role === 'zdz_general') {
    el.style.display = 'none';
    return;
  }

  // ── OPERATOR: the operator's own tile set below: the survey call list, who was talked with, notes to read,
  //    review e-mails. ──
  el.style.display = '';

  // ── OWNER / ADMIN: the home tiles ──
  // Each tile is drawn here once, then filled by zdzKpiPaint() from GET /zorderz/v1/kpi-metrics, where every number comes
  // from the app that owns it (the Analytics ledger, Surveys, the website's review bridge) with its definition and the
  // DATA's time.
  // data-zdz-kpi       — the card's id for drag-to-reorder (dashboard-personalization.js), kept so a saved order still
  //                     applies (Leads won keeps "leads-to-jobs", the card it replaces).
  // data-zdz-kpi-tile  — the server's tile key; a tap opens the tile's sheet (what it counts, from where, as of when,
  //                     and the same question in chat).
  // a tile set per role. The salesperson's four tiles are drawn and filled the same way.
  // the field tech's set too; its cards follow the order the server last sent (zdzKpiCardsFor).
  var kpiSet = zdzKpiSetFor(state.role);
  if (kpiSet) {
    zdzKpiDrawSet(el, kpiSet, zdzKpiStoredOrder(kpiSet));
    return;
  }

  // ── SALES / TECH / DEFAULT: KPI stat cards with analytics tap ──
  el.className = 'quick-stats';
  el.setAttribute('aria-label', 'Quick statistics');

  // the field tech's old strip (Jobs Today, Hours Today, Low Stock: three dashes that only opened the
  // chat, which a tech's role does not have) is gone; the tech set above replaces it.
  {
    // Default — same as sales
    el.innerHTML = `
      <div class="stat-pill kpi-action"
           data-zdz-kpi-action="analytics:What is our month-to-date revenue?">
        <div class="stat-icon" style="background:#F59E0B15;color:#F59E0B">
          <i data-lucide="file-text"></i>
        </div>
        <div>
          <div class="stat-val" data-zdz-kpi="estimates-mtd">—</div>
          <div class="stat-label">Estimates MTD</div>
        </div>
      </div>
      <div class="stat-pill kpi-action"
           data-zdz-kpi-action="analytics:How are our paid jobs trending this month?">
        <div class="stat-icon" style="background:#3B82F615;color:#3B82F6">
          <i data-lucide="briefcase"></i>
        </div>
        <div>
          <div class="stat-val" data-zdz-kpi="mtd-revenue">—</div>
          <div class="stat-label">Paid Jobs</div>
        </div>
      </div>
    `;
  }
  refreshIcons();
  initKPIClicks(); // Wire tap-to-analytics for stat pills too
}

// ---- KPI DATA ----
// The tiles, in order. key = the server's tile key; dom = data-zdz-kpi (kept for saved card orders). Labels here are the
// first paint only: the server's `label` wins (a salesperson-shaped answer says "Your estimates this month").
var ZDZ_KPI_TILES = [
  { key: 'ytd_revenue', dom: 'ytd-revenue', label: 'Revenue this year', primary: true },
  { key: 'mtd_revenue', dom: 'mtd-revenue', label: 'Revenue this month', primary: true },
  { key: 'estimates_mtd', dom: 'estimates-mtd', label: 'Estimates this month', icon: 'file-text', color: '#3B82F6' },
  { key: 'surveys_month', dom: 'surveys-month', label: 'Surveys this month', icon: 'mail-check', color: '#8B5CF6' },
  { key: 'google_reviews', dom: 'google-reviews', label: 'Google reviews', icon: 'star', color: '#F59E0B' },
  { key: 'website_reviews', dom: 'website-reviews', label: 'Website reviews', icon: 'message-square-heart', color: '#A855F7' },
  { key: 'new_leads', dom: 'new-leads', label: 'New leads this month', icon: 'user-plus', color: '#10B981' },
  { key: 'leads_contacted', dom: 'leads-contacted', label: 'Contacted', icon: 'phone-call', color: '#06B6D4' },
  // dom stays 'leads-to-jobs' (the card it replaces): a saved card order keeps it in its place
  { key: 'leads_won', dom: 'leads-to-jobs', label: 'Leads won this month', icon: 'trophy', color: '#EC4899' }
];
// the tile set per role, as the server answers it (ZDZ_KPI_Metrics::SETS). The salesperson's: counts
// only, no dollars. dom ids are new: no saved order to keep.
var ZDZ_KPI_SETS = {
  company: ZDZ_KPI_TILES,
  sales: [
    { key: 'my_estimates', dom: 'my-estimates', label: 'Your estimates this month', icon: 'file-text', color: '#3B82F6' },
    { key: 'my_invoices', dom: 'my-invoices', label: 'Your invoices this month', icon: 'receipt', color: '#8B5CF6' },
    { key: 'my_leads', dom: 'my-leads', label: 'Leads to contact', icon: 'phone-call', color: '#10B981' },
    { key: 'my_installed', dom: 'my-installed', label: 'Jobs installed this month', icon: 'house', color: '#F59E0B' }
  ],
  // the shop's: Prep's jobs, then up to three item counts the business names (the Commission app's counts). The item
  // cards' words are the server's (first paint: "Items this month"); the server sends only the ones configured.
  shop: [
    { key: 'shop_prep', dom: 'shop-prep', label: 'Prep jobs', icon: 'scissors', color: '#EF4444' },
    { key: 'shop_item_1', dom: 'shop-item-1', label: 'Items this month', icon: 'package', color: '#3B82F6' },
    { key: 'shop_item_2', dom: 'shop-item-2', label: 'Items this month', icon: 'boxes', color: '#8B5CF6' },
    { key: 'shop_item_3', dom: 'shop-item-3', label: 'Items this month', icon: 'layers', color: '#10B981' }
  ],
  // the operator's: the survey call list now, who was talked with this month, notes to read, review e-mails
  operator: [
    { key: 'op_to_call', dom: 'op-to-call', label: 'Customers to call', icon: 'phone-outgoing', color: '#3B82F6' },
    { key: 'op_talked', dom: 'op-talked', label: 'Talked with this month', icon: 'messages-square', color: '#10B981' },
    { key: 'op_attention', dom: 'op-attention', label: 'Needs a look', icon: 'circle-alert', color: '#F59E0B' },
    { key: 'op_reviews', dom: 'op-reviews', label: 'Review e-mails this month', icon: 'mail-check', color: '#8B5CF6' }
  ],
  // the field tech's POOL: My hours first, then three of the other four, in the order the server sends
  // (`order`, from the tech's own use). Before the first answer the role's starting order is drawn (zdzKpiCardsFor).
  tech: [
    { key: 'tech_hours', dom: 'tech-hours', label: 'My hours this week', icon: 'clock', color: '#3B82F6' },
    { key: 'tech_jobs', dom: 'tech-jobs', label: 'My installs this week', icon: 'house', color: '#F59E0B' },
    { key: 'tech_schedule', dom: 'tech-schedule', label: 'Appointments today', icon: 'calendar-days', color: '#10B981' },
    { key: 'tech_media', dom: 'tech-media', label: 'Photos this week', icon: 'camera', color: '#8B5CF6' },
    { key: 'tech_messages', dom: 'tech-messages', label: 'Unread messages', icon: 'message-circle', color: '#EF4444' }
  ]
};
// the cards a set draws, in order: the keys the server sent last (`order`: the tiles the business chose for the set,
// and the tech's in their own order of use). Before any answer, a set draws its whole list, the shop Prep's card and
// the tech the starting four. A key the page does not know is skipped, never invented.
var ZDZ_KPI_TECH_START = ['tech_hours', 'tech_jobs', 'tech_schedule', 'tech_media'];
function zdzKpiCardsFor(set, order) {
  var list = ZDZ_KPI_SETS[set] || [];
  var given = Array.isArray(order);
  if (!given && set !== 'tech' && set !== 'shop') { return list; }
  var keys = given ? order : (set === 'shop' ? ['shop_prep'] : ZDZ_KPI_TECH_START);
  return keys.map(function (k) { return list.filter(function (t) { return t.key === k; })[0]; }).filter(Boolean);
}
// The order kept with the last answer on this device, for this set (none: the starting order).
function zdzKpiStoredOrder(set) {
  var copy = null;
  try { copy = JSON.parse(localStorage.getItem(zdzKpiStoreKey()) || 'null'); } catch (e) { copy = null; }
  return (copy && copy.set === set && Array.isArray(copy.order)) ? copy.order : null;
}
function zdzKpiDrawnKeys() {
  return Array.prototype.map.call(document.querySelectorAll('[data-zdz-kpi-tile]'), function (c) { return c.getAttribute('data-zdz-kpi-tile'); });
}
// Draw a set's cards into the strip (here, so the tech's cards can be redrawn in the server's order).
function zdzKpiDrawSet(el, kpiSet, order) {
  el.style.display = '';
  el.className = 'kpi-grid';
  el.setAttribute('aria-label', kpiSet === 'sales' ? 'Your numbers this month' : (kpiSet === 'shop' ? 'Shop this month' : (kpiSet === 'operator' ? 'Calls and surveys' : (kpiSet === 'tech' ? 'Your day' : 'Key performance metrics'))));
  el.innerHTML = zdzKpiCardsFor(kpiSet, order).map(function (t) {
    return '<div class="kpi-card kpi-action' + (t.primary ? ' kpi-primary' : '') + '" data-zdz-kpi-tile="' + t.key + '" role="button" tabindex="0">' +
      (t.primary ? '' : '<div class="kpi-icon" style="color:' + t.color + '"><i data-lucide="' + t.icon + '"></i></div>') +
      (t.primary ? '<div class="kpi-label">' + escapeHtml(t.label) + '</div>' : '') +
      // the tech's cards carry no data-zdz-kpi, so the drag-to-reorder script leaves them alone: their
      // order is the tech's use, picked on the server, and a redraw would drop its handles anyway.
      '<div class="kpi-value"' + (kpiSet === 'tech' ? '' : ' data-zdz-kpi="' + t.dom + '"') + '>—</div>' +
      (t.primary ? '' : '<div class="kpi-label">' + escapeHtml(t.label) + '</div>') +
      '<div class="kpi-sub"></div><div class="kpi-when"></div>' +
    '</div>';
  }).join('');
  refreshIcons();
  initKPIClicks();
}
// the answer's own order differs from the cards on screen (the tech's use moved one up, the business chose other
// tiles): draw them again first. In the sets a person may reorder by dragging, only a different choice of cards
// redraws, never the person's own order.
function zdzKpiFollowOrder(json) {
  var set = json ? (json.set || 'company') : '';
  if (!json || !Array.isArray(json.order)) { return; }
  var want = zdzKpiCardsFor(set, json.order).map(function (t) { return t.key; });
  var have = zdzKpiDrawnKeys();
  var own = set === 'tech' || set === 'shop';
  if ((own ? want : want.slice().sort()).join(',') === (own ? have : have.slice().sort()).join(',')) { return; }
  var el = document.getElementById('quick-stats');
  if (el) { zdzKpiDrawSet(el, set, json.order); }
}
// Which set a role gets ('' = none yet: the role keeps its own strip and asks for nothing).
function zdzKpiSetFor(role) {
  if (role === 'zdz_owner' || role === 'administrator' || role === 'zdz_admin') { return 'company'; }
  if (role === 'zdz_sales') { return 'sales'; }
  if (role === 'zdz_mfg') { return 'shop'; }
  if (role === 'zdz_operator') { return 'operator'; }
  if (role === 'zdz_tech') { return 'tech'; }
  return '';
}
var ZDZ_KPI_REFRESH_MS = 120000; // the server keeps each person's answer 2 minutes; a page left open asks again after that
function zdzKpiStoreKey() { return 'zdz_kpi_v2_' + String((typeof zdzData !== 'undefined' && zdzData && zdzData.userId) || 0).replace(/[^0-9]/g, ''); }
// The month now, in the answer's own time zone (the site's): a copy from another month is never shown as "this month".
function zdzKpiMonthNow(tz) {
  try {
    var parts = new Intl.DateTimeFormat('en-CA', { timeZone: tz || 'America/Los_Angeles', year: 'numeric', month: '2-digit' }).formatToParts(new Date());
    var y = '', m = '';
    parts.forEach(function (p) { if (p.type === 'year') { y = p.value; } if (p.type === 'month') { m = p.value; } });
    return y && m ? y + '-' + m : '';
  } catch (e) { return ''; }
}
// an answer that names its day (the tech's: today's appointments, this week's installs) is
// current only on that day, so yesterday's copy is never painted as today's.
function zdzKpiDayNow(tz) {
  try { return new Intl.DateTimeFormat('en-CA', { timeZone: tz || 'America/Los_Angeles', year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date()); }
  catch (e) { return ''; }
}
function zdzKpiThisMonth(data) { return !!(data && data.month && data.month === zdzKpiMonthNow(data.tz) && (!data.day || data.day === zdzKpiDayNow(data.tz))); }
function zdzKpiState() { if (!window.__zdzKpiS) { window.__zdzKpiS = { data: null, failed: false, at: 0, inflight: false }; } return window.__zdzKpiS; }

// The data's age, in words: "as of 10:31" today, "as of 3 Oct" before; a typed number says "entered".
function zdzKpiAgeWords(t) {
  if (!t || !t.as_of) { return t && t.as_of_kind === 'entered' ? 'no date kept' : ''; }
  var d = new Date(t.as_of * 1000), now = new Date();
  var same = d.toDateString() === now.toDateString();
  var when = same ? d.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' })
                  : d.toLocaleDateString([], { day: 'numeric', month: 'short' });
  return (t.as_of_kind === 'entered' ? 'entered ' : 'as of ') + when;
}
// Old = older than its interval, or the app that owns the number marked it stale, or it could not be refreshed. Never "just now" for a copy.
function zdzKpiIsOld(t, failed) {
  if (!t) { return false; }
  if (failed || t.state === 'stale' || t.state === 'error') { return true; }
  if (t.stale_after && t.as_of) { return (Date.now() / 1000 - t.as_of) > t.stale_after; }
  return false;
}
function zdzKpiSubParts(t, failed) {
  var lead = '', age = '', old = false;
  switch (t.state) {
    case 'held': lead = 'not counted yet'; break;
    case 'unavailable': lead = 'not available'; break;
    case 'empty': lead = 'not entered yet'; break;
    case 'counting': lead = 'being counted'; break;   // the owner's first count is on its way; never 0
    case 'error':
      lead = 'couldn’t refresh';
      if (t.last_good) { age = zdzKpiAgeWords({ as_of: t.last_good.as_of, as_of_kind: t.as_of_kind }); }
      old = true;
      break;
    default:
      lead = failed ? 'couldn’t refresh' : (t.detail || '');
      age = zdzKpiAgeWords(t);
      old = zdzKpiIsOld(t, failed);
  }
  return { lead: lead, age: age, old: old };
}
function zdzKpiPaintCard(card, t, failed) {
  var val = card.querySelector('.kpi-value'), sub = card.querySelector('.kpi-sub');
  var labels = card.querySelectorAll('.kpi-label');
  card.hidden = t.state === 'not_shown';
  if (t.label && labels.length) { labels[0].textContent = t.label; }
  var shown = (t.state === 'error' && t.last_good) ? t.last_good.display : (t.display || '—');
  if (val) {
    val.textContent = shown;
    val.classList.remove('kpi-loading');
    val.classList.add('kpi-loaded');
    val.classList.toggle('kpi-value-word', t.state === 'held');
  }
  var p = zdzKpiSubParts(t, failed);
  card.classList.toggle('kpi-stale', p.old);
  card.classList.toggle('kpi-error-card', t.state === 'error' || t.state === 'unavailable' || !!failed);
  card.classList.toggle('kpi-held', t.state === 'held');
  card.setAttribute('data-zdz-kpi-state', failed ? 'failed' : t.state);
  // Two lines: what it is, then how old it is. On one line a phone cut the age off.
  if (sub) { sub.textContent = p.lead; }
  var when = card.querySelector('.kpi-when');
  if (when) {
    when.textContent = '';
    if (p.age) {
      var a = document.createElement('span');
      a.className = 'kpi-age' + (p.old ? ' is-old zdz-pair' : '');
      a.textContent = p.age;
      when.appendChild(a);
    }
  }
  card.setAttribute('aria-label', (t.label || '') + ': ' + shown + (p.lead ? ', ' + p.lead : '') + (p.age ? ', ' + p.age : '') + '. Tap for what it counts.');
}
function zdzKpiPaint(data, failed) {
  if (!data || !data.tiles) { return; }
  var S = zdzKpiState();
  S.data = data; S.failed = !!failed;
  Object.keys(data.tiles).forEach(function (k) {
    var card = document.querySelector('[data-zdz-kpi-tile="' + k + '"]');
    if (card) { zdzKpiPaintCard(card, data.tiles[k], failed); }
  });
}
// A failed fetch never shows 0: with no copy of THIS month to fall back on, every card says it could not load (whatever
// it showed before: a page left open past the month's end must not keep last month's figures under "this month").
function zdzKpiNoData() {
  document.querySelectorAll('[data-zdz-kpi-tile]').forEach(function (card) {
    var v = card.querySelector('.kpi-value');
    if (v) { v.classList.remove('kpi-loading', 'kpi-value-word'); v.textContent = '\u2014'; }
    var sub = card.querySelector('.kpi-sub'), when = card.querySelector('.kpi-when');
    if (sub) { sub.textContent = 'couldn\u2019t load'; }
    if (when) { when.textContent = ''; }
    card.hidden = false;
    card.classList.remove('kpi-stale', 'kpi-held');
    card.classList.add('kpi-error-card');
    card.setAttribute('data-zdz-kpi-state', 'failed');
  });
}

// the cards on screen are another role's (View As): say so on each, never a number, never "couldn't load".
function zdzKpiOtherSet() {
  document.querySelectorAll('[data-zdz-kpi-tile]').forEach(function (card) {
    var v = card.querySelector('.kpi-value');
    if (v) { v.classList.remove('kpi-loading', 'kpi-value-word'); v.textContent = '\u2014'; }
    var sub = card.querySelector('.kpi-sub'), when = card.querySelector('.kpi-when');
    if (sub) { sub.textContent = 'their own figures'; }
    if (when) { when.textContent = 'shown when they sign in'; }
    card.hidden = false;
    card.classList.remove('kpi-stale', 'kpi-held', 'kpi-error-card');
    card.setAttribute('data-zdz-kpi-state', 'other');
  });
}

function fetchKPIMetrics(force) {
  // Only the roles the server answers: owners and admins (the company set), from salespeople (their own set) and,
  // from, the shop foreman (zdz_mfg: the shop set), from the operator (zdz_operator: the operator set), from
  // the field tech (zdz_tech: the tech set).
  // The other roles' strips wait for their sets.
  var want = zdzKpiSetFor(state.role);
  if (!want) { return; }
  if (!document.querySelector('[data-zdz-kpi-tile]')) { return; }
  var S = zdzKpiState();
  if (S.inflight) { return; }
  try { sessionStorage.removeItem('zdz_kpi_cache'); } catch (e) { /* the ≤ per-tab copy */ }

  // The last answer for THIS person, from this device (not per tab: a new tab or a PWA launch paints at once). Its ages
  // are the data's, so an old copy reads as old.
  if (!S.data) {
    var copy = null;
    try { copy = JSON.parse(localStorage.getItem(zdzKpiStoreKey()) || 'null'); } catch (e) { copy = null; }
    // a copy of another set (the person's role changed) is not this screen's: copies carry no set and were the company's
    if (copy && copy.tiles && copy.shape === 2 && zdzKpiThisMonth(copy) && (copy.set || 'company') === want) { zdzKpiFollowOrder(copy); zdzKpiPaint(copy, false); }
    else { document.querySelectorAll('[data-zdz-kpi-tile] .kpi-value').forEach(function (v) { v.classList.add('kpi-loading'); }); }
  }

  S.inflight = true;
  // A request that hangs is given up after 15 s, so the tiles say so and a later look can ask again.
  var ac = (typeof AbortController !== 'undefined') ? new AbortController() : null;
  var giveUp = ac ? setTimeout(function () { ac.abort(); }, 15000) : 0;
  fetch(zdzData.apiUrl + 'kpi-metrics' + (force ? '?force_refresh=1' : ''), {
    method: 'GET',
    headers: { 'X-WP-Nonce': zdzData.nonce },
    credentials: 'same-origin',
    signal: ac ? ac.signal : undefined
  })
  .then(function (r) {
    // A 502 wall page or a login page is text, not JSON: read the text, and anything that is not our answer is a failure.
    return r.text().then(function (txt) {
      var j = null;
      try { j = JSON.parse(txt); } catch (e) { j = null; }
      if (!r.ok || !j || j.shape !== 2 || !j.tiles) { throw new Error('KPI fetch failed: HTTP ' + r.status); }
      return j;
    });
  })
  .then(function (json) {
    clearTimeout(giveUp);
    S.inflight = false; S.at = Date.now();
    // the server answers the SIGNED-IN person's set. In View As (an owner previewing a salesperson's
    // home) the page draws the other role's cards: that answer is not theirs, so it is neither painted nor kept.
    if ((json.set || 'company') !== want) { S.data = null; S.other = true; zdzKpiOtherSet(); return; }
    S.other = false;
    zdzKpiFollowOrder(json);   // the tech's cards in the order this answer gives
    zdzKpiPaint(json, false);
    // a tile still being counted is asked for again shortly (the server keeps such an answer 15 s),
    // a few times, so the count appears without leaving the app.
    var counting = Object.keys(json.tiles).some(function (k) { return json.tiles[k] && json.tiles[k].state === 'counting'; });
    if (counting && (S.recount || 0) < 6) { S.recount = (S.recount || 0) + 1; setTimeout(function () { fetchKPIMetrics(); }, 20000); }
    else if (!counting) { S.recount = 0; }
    try { delete json.cached; localStorage.setItem(zdzKpiStoreKey(), JSON.stringify(json)); } catch (e) { /* storage full or blocked */ }
  })
  .catch(function (err) {
    clearTimeout(giveUp);
    S.inflight = false; S.at = Date.now();
    console.warn('KPI metrics fetch error:', err && err.message);
    // the copy on screen is shown as "couldn't refresh" only while it is still this month's
    if (S.data && zdzKpiThisMonth(S.data)) { zdzKpiPaint(S.data, true); } else { S.data = null; zdzKpiNoData(); }
  });
}

// A home screen left open asks again when it comes back to the front (the answer is 2 minutes old or more).
document.addEventListener('visibilitychange', function () {
  if (document.visibilityState !== 'visible') { return; }
  var S = zdzKpiState();
  if (S.at && (Date.now() - S.at) >= ZDZ_KPI_REFRESH_MS) { fetchKPIMetrics(); }
});

// A tile's sheet: the number, what it counts, where it comes from and as of when; the same question in chat.
function zdzKpiOpenSheet(key) {
  var S = zdzKpiState(), t = S.data && S.data.tiles ? S.data.tiles[key] : null;
  var meta = ZDZ_KPI_SETS.company.concat(ZDZ_KPI_SETS.sales, ZDZ_KPI_SETS.shop, ZDZ_KPI_SETS.operator, ZDZ_KPI_SETS.tech).filter(function (x) { return x.key === key; })[0] || { label: '' };
  if (!window.ZDZ || !ZDZ.ui || !ZDZ.ui.sheet) { if (t && t.ask) { openApp('sales-analytics', { prompt: t.ask }); } return; }
  var body = document.createElement('div');
  body.className = 'kpi-sheet';
  var add = function (tag, cls, text) { var n = document.createElement(tag); if (cls) { n.className = cls; } n.textContent = text; body.appendChild(n); return n; };
  if (!t) {
    add('p', 'kpi-sheet-state', S.other ? 'These are this person’s own figures. They show on their home screen when they sign in, not in View As.' : (S.failed ? 'The figures couldn’t be loaded just now. Try again in a minute.' : 'Still loading…'));
  } else {
    var p = zdzKpiSubParts(t, S.failed);
    var shown = (t.state === 'error' && t.last_good) ? t.last_good.display : (t.display || '—');
    add('div', 'kpi-sheet-value' + (t.state === 'held' ? ' kpi-value-word' : ''), shown);
    var detail = (t.state === 'error' && t.last_good) ? t.last_good.detail : t.detail;
    if (detail && t.state !== 'held') { add('div', 'kpi-sheet-detail', detail); }
    // .x: the tile's own split, e.g. Billed / Collected / Still owed / Invoices (revenue). Text nodes only.
    if (t.parts && t.parts.length && t.state !== 'error') {
      var dl = document.createElement('dl'); dl.className = 'kpi-sheet-parts';
      t.parts.forEach(function (p) {
        var dt = document.createElement('dt'); dt.textContent = p.label || '';
        var dd = document.createElement('dd'); dd.textContent = p.display || '';
        dl.appendChild(dt); dl.appendChild(dd);
      });
      body.appendChild(dl);
    }
    var said = S.failed ? 'This is the last answer on this device; the home screen couldn’t refresh it just now.' : (t.message || '');
    if (t.state === 'error') { said = (t.message || 'It couldn’t be refreshed just now.') + (t.last_good ? ' Showing the last good figure.' : ''); }
    if (t.state === 'stale' && !said) { said = 'This figure is older than it should be: its source has not caught up.'; }
    if (said) { add('p', 'kpi-sheet-state' + (p.old ? ' is-old zdz-pair' : ''), said); }
    if (t.definition) { add('h3', 'kpi-sheet-h', 'What it counts'); add('p', 'kpi-sheet-p', t.definition); }
    if (t.source || p.age) {
      add('h3', 'kpi-sheet-h', 'From');
      var ago = t.as_of ? formatTimeAgo(t.as_of) : '';
      add('p', 'kpi-sheet-p', [t.source, p.age ? p.age + (ago && ago !== 'just now' && t.as_of_kind !== 'entered' ? ' (' + ago + ')' : '') : ''].filter(Boolean).join(' · '));
    }
  }
  var actions = [];
  var go = function (fn) {
    // Open the next screen once the sheet's own history entry is gone (Back would otherwise close the app instead).
    return function () {
      var done = false, run = function () { if (done) { return; } done = true; window.removeEventListener('popstate', run); setTimeout(fn, 0); };
      window.addEventListener('popstate', run);
      setTimeout(run, 400);
    };
  };
  if (t && t.ask) { actions.push({ label: 'Ask in chat', kind: 'primary', onClick: go(function () { openApp('sales-analytics', { prompt: t.ask }); }) }); }
  if (t && t.open) { actions.push({ label: t.open_label || 'Open', onClick: go(function () { openApp(t.open); }) }); }
  actions.push({ label: 'Close' });
  ZDZ.ui.sheet({ title: (t && t.label) || meta.label, body: body, actions: actions });
}

// ---- RECENT APPS ----
function addRecent(appId) {
  state.recentApps = [appId, ...state.recentApps.filter(a => a !== appId)].slice(0, 5);
  try { localStorage.setItem('zdz_recent', JSON.stringify(state.recentApps)); } catch(e) {}
  renderRecentApps();
  // Track app open for audit log
  var appMeta = (zdzData.apps || []).find(function(a){ return a.id === appId; });
  zdzTrack('app_open', appMeta ? appMeta.nm : appId, appId);
}

function renderRecentApps() {
  const el = document.getElementById('recent-row');
  const section = document.getElementById('recent-section');
  if (!el || !section) return;

  if (!state.recentApps.length) {
    const pinned = PINNED[state.role] || [];
    const apps = pinned.map(id => zdzData.apps.find(a => a.id === id)).filter(Boolean).filter(a => !isSecondarySurface(a));
    if (!apps.length) { section.style.display = 'none'; return; }
    section.style.display = '';
    const label = document.querySelector('#recent-section .section-label');
    if (label) label.textContent = 'Suggested';
    el.innerHTML = apps.map(a => `
      <button class="recent-chip" onclick="openApp('${a.id}')" aria-label="Open ${a.nm}">
        <div class="rc-icon" ${zdzPlate(a)}><i data-lucide="${a.icon}"></i></div>
        <span class="rc-name">${a.nm}</span>
      </button>
    `).join('');
  } else {
    // Show max 3 recent apps; hide section if fewer than 2
    const apps = state.recentApps.slice(0, 3).map(id => zdzData.apps.find(a => a.id === id)).filter(Boolean).filter(a => !isSecondarySurface(a));
    if (apps.length < 2) { section.style.display = 'none'; return; }
    section.style.display = '';
    const label = document.querySelector('#recent-section .section-label');
    if (label) label.textContent = 'Recently Used';
    el.innerHTML = apps.map(a => `
      <button class="recent-chip" onclick="openApp('${a.id}')" aria-label="Open ${a.nm}">
        <div class="rc-icon" ${zdzPlate(a)}><i data-lucide="${a.icon}"></i></div>
        <span class="rc-name">${a.nm}</span>
      </button>
    `).join('');
  }
}

// ---- APP GRID ----
// A plugin may register an app that is openable (via Bridge.loadApp /
// the command palette / "See All" deep-links) but should NOT appear as its own
// springboard/dock tile, recent chip, or search result — e.g. a secondary
// full-screen surface of an app whose PRIMARY tile is a dashboard widget (the
// Media library: the 'zdz-media' widget is the tile; 'zdz-media-all' is the
// full-screen gallery reached from "See All"). Such an app opts out with
// `springboard: false` in its config. Default (key absent) = listed as before,
// so every existing plugin is unaffected. This is the single source of truth
// for that rule; all app-list builders consult it. Loading is NEVER affected —
// Bridge.loadApp / /load-app / the viewport header still resolve the app.
function isSecondarySurface(app) {
  return !!app && app.springboard === false;
}

// long dock/grid labels (e.g. "Knowledge", "Commission") crowd or wrap
// at the default size. Return ' is-long' for names at/above the length where the
// short labels (Prep, Leads, Media, Camera, Receipt…) never reach, so CSS can
// shrink ONLY those to a single line. Threshold 9 catches "Knowledge" (9) and up;
// every short label is ≤7. Returns '' (no class) for short labels — unchanged.
var ZDZ_LONG_LABEL_MIN = 9;
function longLabelClass(name) {
  return (name && String(name).length >= ZDZ_LONG_LABEL_MIN) ? ' is-long' : '';
}

/* an app's short name (snm, ten characters at most, from its registration or the theme's
   fallback list) is what the launcher tile, the dock and the sticky strip draw; its full name (nm) is everywhere
   else: the app's page header, search, the large cards, the ask bar, and each tile's title and spoken label. An app
   with no short name draws its full name, ending in "…" if it does not fit. */
function zdzAppShort(a) {
  var s = a && typeof a.snm === 'string' ? a.snm.trim() : '';
  return s || (a && a.nm) || '';
}
// A name as text: a plugin may register an entity-encoded name (esc_html__('Q&A') is "Q&amp;A"), which the old raw
// templates drew as "Q&A". Decode it once, then escape it for HTML, so every place shows the same text.
var _zdzNameDec = null;
function zdzNameText(s) {
  s = (s === null || s === undefined) ? '' : String(s);
  if (s.indexOf('&') < 0) return s;
  try { _zdzNameDec = _zdzNameDec || document.createElement('textarea'); _zdzNameDec.innerHTML = s; return _zdzNameDec.value; } catch (e) { return s; }
}
function zdzNameHtml(s) { return escapeHtml(zdzNameText(s)); }
// The tile's spoken label holds the words it shows: "Open Doorhangers", or "Open Doorhangers (Doors)" once
// the tile shows a short name.
function zdzAppSpoken(a) {
  var full = zdzNameText(a && a.nm), short = zdzNameText(zdzAppShort(a));
  return 'Open ' + full + (short && short !== full ? ' (' + short + ')' : '');
}

function getVisibleApps() {
  // Prefer server-persisted order, fall back to localStorage, then unsorted.
  var saved = null;
  if (zdzData.appOrder && Array.isArray(zdzData.appOrder) && zdzData.appOrder.length) {
    saved = zdzData.appOrder;
  }
  if (!saved) {
    try { saved = JSON.parse(localStorage.getItem('zdz_app_order')); } catch(e) {}
  }
  // the theme now gives every app a color, so "registered without a color" (which kept an app off the
  // launcher) is read from the plugin's own color, cc_plugin.
  var apps = (zdzData.apps || []).filter(a => !!a.nm && !!a.icon && !!a.cc && (!a.grp || !!a.cc_plugin) && !isSecondarySurface(a));
  // Apply saved order if available
  if (saved && Array.isArray(saved) && saved.length) {
    var order = {};
    saved.forEach(function(id, i) { order[id] = i; });
    apps.sort(function(a, b) {
      var oa = order.hasOwnProperty(a.id) ? order[a.id] : 999;
      var ob = order.hasOwnProperty(b.id) ? order[b.id] : 999;
      return oa - ob;
    });
  }
  return apps;
}

// Persist dock/sticky bar order to localStorage + server
function saveAppOrder(order) {
  try { localStorage.setItem('zdz_app_order', JSON.stringify(order)); } catch(e) {}
  // Update in-memory so getVisibleApps() reads it this session
  zdzData.appOrder = order;
  // Fire-and-forget REST save
  try {
    fetch(zdzData.apiUrl + 'user-prefs', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': zdzData.nonce },
      body: JSON.stringify({ app_order: order }),
      keepalive: true
    }).catch(function(){});
  } catch(e) {}
}

function renderAppGrid() {
  const container = document.getElementById('app-grid-container');
  if (!container) return;
  const apps = getVisibleApps();

  // Role-adaptive: ≤4 apps → large action cards (zero-scroll, instant access)
  if (apps.length > 0 && apps.length <= 4) {
    let html = '<div class="app-grid-hero">';
    html += apps.map(a => `
      <button class="action-card" onclick="openApp('${a.id}')" aria-label="Open ${a.nm}">
        <div class="ac-icon" ${zdzPlate(a)}><i data-lucide="${a.icon}"></i></div>
        <div class="ac-text">
          <div class="ac-name">${a.nm}</div>
          <div class="ac-desc">${a.desc || ''}</div>
        </div>
      </button>
    `).join('');
    html += '</div>';
    container.innerHTML = html;
    // no "At work" row here; say which mode the clock gave, so the minute tick does not redraw these
    // cards every minute.
    container.setAttribute('data-zdz-fy-mode', '');
    container.setAttribute('data-zdz-fy-clock', zdzForYouData() ? (zdzForYouMode() || '') : '');
    container.setAttribute('data-zdz-fy-slot', zdzForYouData() ? zdzForYouCell() : '');   // or the tick redraws these every minute
    return;
  }

  // on the focus home, this person's apps for now come first ("At work" or "After hours"), and the
  // rest follow in their groups without them. With the view off, or for the kiosk, the grid is as before.
  // the tile draws the short name; its title and spoken label carry the full one.
  const tile = a => `
          <button class="app-ic" onclick="openApp('${a.id}')" aria-label="${escapeHtml(zdzAppSpoken(a))}" title="${zdzNameHtml(a.nm)}">
            <div class="ic" ${zdzPlate(a)}><i data-lucide="${a.icon}"></i></div>
            <span class="nm${longLabelClass(zdzAppShort(a))}">${zdzNameHtml(zdzAppShort(a))}</span>
          </button>`;
  let html = '';
  let rest = apps;
  const fy = zdzForYouPick(apps);
  // A redraw keeps the keyboard where it was: on the same tile, or on the switch.
  const ae = document.activeElement;
  const keep = (ae && container.contains(ae))
    ? (ae.classList.contains('zdz-fy-switch') ? { sw: true } : { id: (ae.querySelector('.ic') || {}).getAttribute ? ae.querySelector('.ic').getAttribute('data-zdz-app') : '' })
    : null;
  const finish = () => {
    // The mode the clock gave at this draw, even when no row was drawn, so the minute tick sees a change.
    container.setAttribute('data-zdz-fy-mode', fy ? fy.mode : '');
    container.setAttribute('data-zdz-fy-clock', zdzForYouData() ? (zdzForYouMode() || '') : '');
    container.setAttribute('data-zdz-fy-slot', zdzForYouData() ? zdzForYouCell() : '');   // the hour band drawn
    zdzFitLabels(container);
    if (keep) {
      const el = keep.sw ? container.querySelector('.zdz-fy-switch')
        : (keep.id ? (container.querySelector('.ic[data-zdz-app="' + String(keep.id).replace(/[^a-z0-9_-]/gi, '') + '"]') || {}).parentNode : null);
      if (el && typeof el.focus === 'function') { try { el.focus({ preventScroll: true }); } catch (e) { el.focus(); } }
    }
  };
  if (fy) {
    const taken = {};
    fy.apps.forEach(a => { taken[a.id] = 1; });
    rest = apps.filter(a => !taken[a.id]);
    const other = fy.mode === 'work' ? 'Show after-hours apps' : 'Show work apps';
    // Offered only when the other mode would draw a row of its own; else the row and its switch would vanish and
    // leave no way back until the hours change.
    const canSwitch = !!zdzForYouPick(apps, fy.mode === 'work' ? 'off' : 'work');
    html += `<div class="cat-section zdz-fy-section" data-zdz-fy-mode="${fy.mode}">
      <div class="cat-label zdz-fy-label"><span class="zdz-fy-title">${fy.mode === 'work' ? 'At work' : 'After hours'}</span>
        ${canSwitch ? `<button type="button" class="zdz-fy-switch" aria-label="${other}. Now showing ${fy.mode === 'work' ? 'work' : 'after-hours'} apps" onclick="zdzForYouSwitch()">${other}</button>` : ''}</div>
      <div class="app-grid zdz-fy-grid">${fy.apps.map(tile).join('')}
      </div>
    </div>`;
  }

  // Standard category grid for 5+ apps
  const grouped = {};
  CATEGORIES.forEach(c => { grouped[c.id] = []; });
  // an app outside the five groups (an older plugin, a client-side tile) lands in the last group
  // rather than vanishing from the grid, which is how Camera, Media, Sketch, Game, Email and Dot Plot went missing.
  const lastCat = CATEGORIES[CATEGORIES.length - 1].id;
  rest.forEach(a => { (grouped[a.cat] || grouped[lastCat]).push(a); });

  // once "At work" / "After hours" leads, the rest sit under one label in group order (the icon
  // colors still say the group). Five group rows, each part-filled once the row has taken its apps, pushed the
  // last apps two screens down on a phone; one grid keeps every app on the first screen of a 390 x 844 phone and
  // of a 1200 x 616 desktop window.
  if (fy) {
    const more = [];
    CATEGORIES.forEach(cat => { (grouped[cat.id] || []).forEach(a => more.push(a)); });
    if (more.length) {
      html += `<div class="cat-section zdz-fy-more">
      <div class="cat-label">More apps</div>
      <div class="app-grid">${more.map(tile).join('')}
      </div>
    </div>`;
    }
    container.innerHTML = html;
    finish();
    return;
  }

  CATEGORIES.forEach(cat => {
    if (!grouped[cat.id]?.length) return;
    html += `<div class="cat-section">
      <div class="cat-label"><span class="cat-dot" style="background:${cat.color}"></span>${cat.id}</div>
      <div class="app-grid">
        ${grouped[cat.id].map(tile).join('')}
      </div>
    </div>`;
  });
  container.innerHTML = html;
  finish();
}

/* ── Each person's apps, at work and after hours ──────────────────────────────
   The apps each person is most likely to need for their role come first, and the set changes with the clock: an
   "at work" set in their work hours and an "after hours" set the rest of the time. The server
   ranks both lists (ZDZ_For_You: the role's starting list plus what this person opens, by mode) and sends the
   hours and the site's time zone; the mode is worked out here from the clock, so a page left open across the end
   of the day changes over by itself. "Show after-hours apps" switches until the hours next change. */
var ZDZ_FY_DAYS = { Mon: 1, Tue: 2, Wed: 3, Thu: 4, Fri: 5, Sat: 6, Sun: 7 };
function zdzForYouData() {
  var f = (typeof zdzData !== 'undefined' && zdzData) ? zdzData.forYou : null;
  if (!f || typeof f !== 'object' || !f.hours || !Array.isArray(f.work) || !Array.isArray(f.off)) return null;
  return f;
}
// The ISO weekday (1 = Monday) and minutes since midnight in the site's zone; the browser's own zone if the
// site's is not one Intl knows.
var _zdzFyFmt = {};
function zdzSiteClock(tz, now) {
  var d = now || new Date();
  try {
    var key = tz || '';
    // One formatter per zone: making one is the slow part.
    var fmt = _zdzFyFmt[key] || (_zdzFyFmt[key] = new Intl.DateTimeFormat('en-US', { timeZone: tz || undefined, weekday: 'short', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' }));
    var parts = fmt.formatToParts(d);
    var get = function (t) { var p = parts.find(function (x) { return x.type === t; }); return p ? p.value : ''; };
    var h = parseInt(get('hour'), 10) % 24, m = parseInt(get('minute'), 10);
    var dow = ZDZ_FY_DAYS[get('weekday')];
    if (dow && !isNaN(h) && !isNaN(m)) return { dow: dow, min: h * 60 + m };
  } catch (e) { /* an unknown zone: fall through */ }
  return { dow: ((d.getDay() + 6) % 7) + 1, min: d.getHours() * 60 + d.getMinutes() };
}
function zdzHhmm(v) { var m = /^(\d{1,2}):(\d{2})$/.exec(String(v || '')); return m ? (+m[1]) * 60 + (+m[2]) : null; }
// 'work' or 'off' (the same rule as ZDZ_For_You::mode_at: hours past midnight belong to the day they start on).
function zdzWorkMode(hours, clock) {
  if (!hours) return 'off';
  var s = zdzHhmm(hours.start), e = zdzHhmm(hours.end);
  var days = (Array.isArray(hours.days) ? hours.days : []).map(function (x) { return parseInt(x, 10); });
  if (s === null || e === null || s === e) return 'off';
  var on = function (dw) { return days.indexOf(dw) !== -1; };
  if (e > s) return (on(clock.dow) && clock.min >= s && clock.min < e) ? 'work' : 'off';
  var prev = ((clock.dow + 5) % 7) + 1;
  if (on(clock.dow) && clock.min >= s) return 'work';
  if (on(prev) && clock.min < e) return 'work';
  return 'off';
}
// When the clock's mode next changes, in ms (on a whole minute), within 8 days; null if it never does (no work
// days). Worked out from the site's wall clock: the only moments the mode can change are each day's start and end
// minute, so those are the only ones looked at (a 10-minute gap is found too). On the day the clocks change the
// answer can be an hour off; the switch then ends an hour early or late, once.
function zdzNextChangeover(hours, tz, fromMs) {
  var start = Math.floor((fromMs || Date.now()) / 60000) * 60000;
  if (!hours) return null;
  var days = (Array.isArray(hours.days) ? hours.days : []).map(function (x) { return parseInt(x, 10); }).filter(function (x) { return x >= 1 && x <= 7; });
  var s = zdzHhmm(hours.start), e = zdzHhmm(hours.end);
  if (!days.length || s === null || e === null || s === e) return null;
  var now = zdzSiteClock(tz, new Date(start));
  var m0 = zdzWorkMode(hours, now);
  var cands = [];
  for (var k = 0; k <= 8; k++) {
    [s, e].forEach(function (b) {
      var delta = k * 1440 + b - now.min;
      if (delta > 0) cands.push(delta);
    });
  }
  cands.sort(function (a, b) { return a - b; });
  for (var i = 0; i < cands.length; i++) {
    var t = now.min + cands[i];
    var clock = { dow: ((now.dow - 1 + Math.floor(t / 1440)) % 7) + 1, min: t % 1440 };
    if (zdzWorkMode(hours, clock) !== m0) return start + cands[i] * 60000;
  }
  return null;
}
// "Show after-hours apps" is kept per person on this device (a shared shop tablet must not hand one person's
// switch to the next), and only until the hours next change over: it stores when it ends, not the mode it was
// made in, so a switch made Friday evening is gone on Monday evening even if nobody looked in between.
function zdzFySwitchKey() { return 'zdz_fy_switch_' + String((typeof zdzData !== 'undefined' && zdzData && zdzData.userId) || 0).replace(/[^0-9]/g, ''); }
function zdzForYouMode(now) {
  var f = zdzForYouData();
  if (!f) return null;
  var at = now ? now.getTime() : Date.now();
  var mode = zdzWorkMode(f.hours, zdzSiteClock(f.tz, new Date(at)));
  var o = null, raw = null;
  try { raw = localStorage.getItem(zdzFySwitchKey()); } catch (e) { raw = null; }
  try { o = JSON.parse(raw || 'null'); } catch (e) { o = { bad: true }; }
  // The copy in memory stands in only for a browser that would not store the switch.
  if (!o && _zdzFyForce && _zdzFyNoStore) o = _zdzFyForce;
  // A switch never lasts more than 8 days (a phone whose clock was wrong when it was made, a damaged value).
  var live = function (x) { return x && (x.mode === 'work' || x.mode === 'off') && typeof x.until === 'number' && at < x.until && x.until <= at + 8 * 86400000 + 60000; };
  if (_zdzFyForce && !live(_zdzFyForce)) _zdzFyForce = null;
  if (live(o)) return o.mode;
  if (o) { try { localStorage.removeItem(zdzFySwitchKey()); } catch (e) {} }
  try { localStorage.removeItem('zdz_fy_switch'); } catch (e) {}   // the build's shared key, if any
  return mode;
}
// the cell for the site's clock -- 'work|workday|morning' -- when the server sent one that
// differs from its mode's list, else ''. Not while "Show after-hours apps" / "Show work apps" holds the row: that
// switch shows the other mode's list as a whole.
function zdzForYouCell(now) {
  var f = zdzForYouData();
  if (!f || !f.cells || typeof f.cells !== 'object' || !Array.isArray(f.bands)) return '';
  var clock = zdzSiteClock(f.tz, now || new Date());
  var mode = zdzWorkMode(f.hours, clock);
  if (zdzForYouMode(now) !== mode) return '';
  var days = (Array.isArray(f.hours.days) ? f.hours.days : []).map(function (x) { return parseInt(x, 10); });
  var h = Math.floor(clock.min / 60), band = '';
  f.bands.forEach(function (b) {
    if (band || !Array.isArray(b)) return;
    var from = parseInt(b[1], 10), to = parseInt(b[2], 10) % 24;
    if (from < to ? (h >= from && h < to) : (h >= from || h < to)) band = String(b[0]);
  });
  var key = mode + '|' + (days.indexOf(clock.dow) !== -1 ? 'workday' : 'dayoff') + '|' + band;
  return Array.isArray(f.cells[key]) ? key : '';
}
// The apps for now, from those this person can open, or null when the row does not apply.
// by the hour: the cell's order first, then the mode's list fills the rest, so the row keeps its 8 apps; a
// cell that leaves fewer than 4 the person can open is ignored (the mode's list alone, as before).
function zdzForYouPick(apps, asMode) {
  var f = zdzForYouData();
  if (!f || !zdzFocusHome()) return null;
  var mode = asMode || zdzForYouMode();
  var size = Math.max(4, Math.min(12, parseInt(f.size, 10) || 8));
  var byId = {};
  apps.forEach(function (a) { byId[a.id] = a; });
  var fill = function (ids) {
    var out = [];
    ids.forEach(function (id) { if (byId[id] && out.length < size && out.indexOf(byId[id]) === -1) out.push(byId[id]); });
    return out;
  };
  var cell = asMode ? '' : zdzForYouCell();
  var list = cell ? fill(f.cells[cell].concat(f[mode] || [])) : [];
  if (!cell || fill(f.cells[cell]).length < 4) list = fill(f[mode] || []);
  if (list.length < 4 || apps.length - list.length < 1) return null;
  return { mode: mode, apps: list, cell: cell };
}
// "Show after-hours apps" / "Show work apps": until the hours next change over, then the clock decides again.
function zdzForYouSwitch() {
  var f = zdzForYouData();
  if (!f) return;
  var base = zdzWorkMode(f.hours, zdzSiteClock(f.tz));
  var cur = zdzForYouMode();
  var next = cur === 'work' ? 'off' : 'work';
  var sw = null;
  if (next !== base) {
    var until = zdzNextChangeover(f.hours, f.tz, Date.now());
    sw = { mode: next, until: until === null ? Date.now() + 12 * 3600000 : until };
  }
  _zdzFyNoStore = false;
  try {
    if (!sw) localStorage.removeItem(zdzFySwitchKey());
    else localStorage.setItem(zdzFySwitchKey(), JSON.stringify(sw));
  } catch (e) { _zdzFyNoStore = true; /* private window: this page only */ }
  _zdzFyForce = sw;   // held in memory too, for a browser that refuses localStorage
  zdzForYouRedraw();
}
window.zdzForYouSwitch = zdzForYouSwitch;
var _zdzFyForce = null, _zdzFyNoStore = false;
function zdzForYouRedraw() {
  var container = document.getElementById('app-grid-container');
  if (!container) return;
  renderAppGrid();
  if (window.lucide && typeof lucide.createIcons === 'function') { try { lucide.createIcons(); } catch (e) {} }
}
// Every minute (and when the page comes back to the front), redraw the launcher if the mode changed. Not while
// an app is open as a page: home is behind it, and it redraws when the person comes back.
function zdzForYouTick() {
  var container = document.getElementById('app-grid-container');
  if (!container || !zdzForYouData() || !zdzFocusHome()) return;
  if (window.Bridge && Bridge._focused) return;
  // Nor behind a full-screen app (an iframe app in the viewport).
  var vp = document.getElementById('app-viewport');
  if (vp && vp.classList.contains('active')) return;
  var shown = container.getAttribute('data-zdz-fy-clock') || '';
  var now = zdzForYouMode() || '';
  // and when the hour band (the cell) changed, e.g. 11:00 turns the weekday morning into midday.
  var slot = zdzForYouCell();
  if ((now && shown !== now) || (container.getAttribute('data-zdz-fy-slot') || '') !== slot) zdzForYouRedraw();
}
function zdzForYouStart() {
  if (!zdzForYouData()) return;
  setInterval(zdzForYouTick, 60000);
  document.addEventListener('visibilitychange', function () { if (!document.hidden) zdzForYouTick(); });
  document.addEventListener('zdz_app_blur', function () { setTimeout(zdzForYouTick, 0); });
  window.addEventListener('popstate', function () { setTimeout(zdzForYouTick, 400); });
}

/* launcher names on one line, centered under the icon. A name that is wider than
   its column is first drawn a little tighter, then a size smaller at a time, never below 14px. A name that still does
   not fit at 14px ends in "…" rather than being cut mid-letter; its tile's title and label say the whole name.
   The size starts from --zdz-applabel-size, and the fit runs again whenever it could have gone stale: a fit
   measured in the phone's own font before Inter swaps in would cut long names (the one re-fit on fonts.ready may
   already have run). So it also runs when any font face finishes loading
   (document.fonts 'loadingdone'), and when any launcher grid changes size or is shown again. A hidden name keeps its
   last fit until it is shown (it used to be reset and skipped). And the stylesheet alone ends a long name in "…"
   with space between neighbours, so even a fit that never ran cannot make two names touch. */
function zdzFitLabels(root) {
  var scope = root || document.getElementById('app-grid-container');
  if (!scope || !document.body.classList.contains('zdz-focus-home')) return;
  Array.prototype.forEach.call(scope.querySelectorAll('.app-grid .app-ic .nm'), function (el) {
    if (!el.clientWidth) return;   // not laid out (hidden): leave it as it is; it is fitted again when shown
    el.classList.remove('nm-tight', 'nm-ellipsis');
    el.style.removeProperty('font-size');
    // The text's own width against the room inside the padding (scrollWidth does not count text that only runs
    // into the right padding, so it would let two names touch).
    var cs0 = getComputedStyle(el);
    // (The box's exact width: clientWidth is rounded to whole pixels.)
    var room = el.getBoundingClientRect().width - (parseFloat(cs0.paddingLeft) || 0) - (parseFloat(cs0.paddingRight) || 0);
    var range = document.createRange();
    var over = function () { range.selectNodeContents(el); return range.getBoundingClientRect().width > room + 0.1; };
    if (!over()) return;
    el.classList.add('nm-tight');
    var size = parseFloat(getComputedStyle(el).fontSize) || 17;
    // Whole pixels, never under 14.
    while (over() && size > 14) {
      size = Math.max(14, Math.ceil(size) - 1);
      el.style.setProperty('font-size', size + 'px', 'important');
    }
    if (over()) el.classList.add('nm-ellipsis');
  });
}
window.zdzFitLabels = zdzFitLabels;
(function () {
  var t = null;
  var soon = function (ms) { clearTimeout(t); t = setTimeout(function () { zdzFitLabels(); }, ms); };
  window.addEventListener('resize', function () { soon(150); });
  try {
    if (document.fonts) {
      if (document.fonts.ready) document.fonts.ready.then(function () { soon(0); });
      // every time a face finishes loading, not only the first time fonts.ready resolves.
      if (document.fonts.addEventListener) document.fonts.addEventListener('loadingdone', function () { soon(30); });
    }
  } catch (e) {}
  // The launcher's width can change without a window resize (a scroll bar appearing as Today fills in), and a grid
  // can be drawn while home is hidden (Settings open) and shown later at the same window width.: each grid
  // is watched as well as the container, by its own last width, so a grid shown again or redrawn is fitted again.
  try {
    if (typeof ResizeObserver !== 'undefined') {
      var lastW = (typeof WeakMap !== 'undefined') ? new WeakMap() : null;
      var ro = new ResizeObserver(function (entries) {
        var changed = false;
        entries.forEach(function (en) {
          var w = Math.round(en.contentRect.width);
          if (!lastW) { changed = true; return; }
          if (lastW.get(en.target) !== w) { lastW.set(en.target, w); changed = true; }
        });
        if (changed) soon(50);
      });
      var watched = (typeof WeakSet !== 'undefined') ? new WeakSet() : null;
      var watch = function (el) { if (!el || (watched && watched.has(el))) return; if (watched) watched.add(el); ro.observe(el); };
      var hook = function () {
        var c = document.getElementById('app-grid-container');
        if (!c) return;
        watch(c);
        Array.prototype.forEach.call(c.querySelectorAll('.app-grid'), watch);
        // A redraw replaces the grids: watch the new ones too.
        if (typeof MutationObserver !== 'undefined') {
          new MutationObserver(function (recs) {
            // Let go of the grids a redraw replaced, so they can be freed.
            recs.forEach(function (r) {
              Array.prototype.forEach.call(r.removedNodes || [], function (n) {
                if (!n || n.nodeType !== 1) return;
                var gone = n.classList && n.classList.contains('app-grid') ? [n] : Array.prototype.slice.call(n.querySelectorAll('.app-grid'));
                gone.forEach(function (g) { try { ro.unobserve(g); } catch (e) {} if (watched) watched.delete(g); if (lastW) lastW.delete(g); });
              });
            });
            Array.prototype.forEach.call(c.querySelectorAll('.app-grid'), watch);
          }).observe(c, { childList: true });
        }
      };
      if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', hook); else hook();
    }
  } catch (e) {}
})();

// ---- SEARCH ----
function initSearch() {
  const input = document.getElementById('app-search');
  const results = document.getElementById('search-results');
  if (!input || !results) return;

  input.addEventListener('input', () => {
    const q = input.value.toLowerCase().trim();
    if (!q) { results.classList.remove('show'); return; }
    const apps = getVisibleApps();
    const matches = apps.filter(a =>
      a.nm.toLowerCase().includes(q) ||
      (typeof a.snm === 'string' && a.snm.toLowerCase().includes(q)) ||   // the name the tile shows
      (a.desc && a.desc.toLowerCase().includes(q)) ||
      (a.slash && a.slash.includes(q)) ||
      (a.aliases && a.aliases.some(al => al.includes(q)))
    );
    if (!matches.length) { results.classList.remove('show'); return; }
    results.innerHTML = matches.map(a => `
      <button class="search-item" onclick="openApp('${a.id}');document.getElementById('app-search').value='';document.getElementById('search-results').classList.remove('show')">
        <div class="si-icon" ${zdzPlate(a)}><i data-lucide="${a.icon}" style="width:18px;height:18px"></i></div>
        <div class="si-text">
          <div class="si-name">${a.nm}</div>
          <div class="si-hint">${a.desc || ''}</div>
        </div>
        <span class="si-slash">${a.slash || ''}</span>
      </button>
    `).join('');
    results.classList.add('show');
    refreshIcons();
  });

  input.addEventListener('blur', () => {
    setTimeout(() => results.classList.remove('show'), 200);
  });
}

function openApp(appId, options) {
  // The Analytics/Chat app injects a dedicated Chat sub-view (window.ZanaChat).
  // Route to it so KPI-tile prompts and the digest deep-link land on the chat
  // surface with their options intact. Backward-compatible: falls through to the
  // Bridge when the Analytics app is not installed.
  if ((appId === 'sales-analytics' || appId === 'zdz-sales-analytics') &&
      window.ZanaChat && typeof ZanaChat.open === 'function') {
    ZanaChat.open(options || {});
    return;
  }
  if (window.Bridge) {
    Bridge.loadApp(appId, options);
  }
}

// External deep-link router.
// The zdz-sales-analytics Daily/Weekly Digest (r4) emails an "Open this chat →"
// button pointing at app_url() + '#zana-session=<id>'. Clicked cold (or while the
// installed PWA is reactivated), the shell must route to the Analytics surface
// instead of dumping the user on the dashboard. The theme's responsibility is to
// open the right app and PRESERVE the hash; the Analytics widget reads
// '#zana-session=' to select the exact session (it already does this for in-chat
// recall links).
// Guarded so it can never block boot and never fires for a user who doesn't
// actually have the Analytics app (e.g. a forwarded link).
function zdzRouteDeepLink() {
  try {
    var hash = window.location.hash || '';
    var m = hash.match(/#zana-session=(\d+)/);
    if (!m) return;
    var sessionId = parseInt(m[1], 10);
    if (!sessionId) return;
    var hasAnalytics = (typeof zdzData !== 'undefined') && Array.isArray(zdzData.apps)
      && zdzData.apps.some(function (a) {
        return a.id === 'sales-analytics' || a.id === 'zdz-sales-analytics';
      });
    if (!hasAnalytics) return;
    // Open Analytics; pass the session as an option (forward-compatible) and leave
    // the hash intact so the widget can resolve '#zana-session=' on init.
    openApp('sales-analytics', { session: sessionId, source: 'digest-deeplink' });
  } catch (e) { /* deep-link routing must never block boot */ }
}

// ---- KPI CLICK HANDLERS ----
function initKPIClicks() {
  // the owners' and admins' tiles open the tile's sheet (open it inside the tap, so Back closes it).
  document.querySelectorAll('.kpi-card[data-zdz-kpi-tile]').forEach(card => {
    const key = card.getAttribute('data-zdz-kpi-tile');
    card.addEventListener('click', () => { Haptics.tap(); zdzKpiOpenSheet(key); });
    card.addEventListener('keydown', (e) => {
      if (e.target !== card) { return; } // Enter on the drag handle inside the card is the handle's
      if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); zdzKpiOpenSheet(key); }
    });
  });
  // the other roles' stat pills (sales/tech) keep their prompts until the per-role plan.
  document.querySelectorAll('.kpi-card[data-zdz-kpi-action], .stat-pill[data-zdz-kpi-action]').forEach(card => {
    card.addEventListener('click', () => {
      const action = card.dataset.zdzKpiAction || '';
      Haptics.tap();

      if (action.startsWith('analytics:')) {
        // Open Analytics with a pre-filled prompt
        const prompt = action.slice('analytics:'.length);
        openApp('sales-analytics', { prompt });
      } else if (action.startsWith('app:')) {
        // Navigate to the specified app (scroll to widget or open viewport)
        const appId = action.slice('app:'.length);
        openApp(appId);
      }
    });
  });
}

// ---- DASHBOARD WIDGETS ----
function renderWidgets() {
  const zone = document.getElementById('dash-widget-zone');
  if (!zone) return;

  let widgets = (zdzData.apps || []).filter(a => a.bridge_type === 'inline_widget' && a.widget_html);

  if (widgets.length === 0) {
    zone.style.display = 'none';
    return;
  }

  // Sort by saved widget order. Widgets not in the saved
  // order appear at the end in their original registration order.
  const savedOrder = zdzData.widgetOrder || [];
  if (savedOrder.length > 0) {
    widgets.sort((a, b) => {
      const ai = savedOrder.indexOf(a.id);
      const bi = savedOrder.indexOf(b.id);
      // Both in saved order → sort by saved position
      if (ai !== -1 && bi !== -1) return ai - bi;
      // Only one in saved order → it comes first
      if (ai !== -1) return -1;
      if (bi !== -1) return 1;
      // Neither → keep original order
      return 0;
    });
  }

  zone.style.display = '';
  zone.innerHTML = widgets.map((w, i) => {
    const isFirst = i === 0;
    const isLast = i === widgets.length - 1;
    const arrowUp = `<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="18 15 12 9 6 15"/></svg>`;
    const arrowDown = `<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>`;

    return `
    <div class="dash-widget-container" data-app-id="${w.id}">
      <div class="dash-widget-header">
        <div class="dw-icon" ${zdzPlate(w)}><i data-lucide="${w.icon}"></i></div>
        <h3 class="dw-title">${w.nm}</h3>
        <div class="dw-nav" role="group" aria-label="Move through apps">
          <button class="dw-nav-btn" data-dir="up" ${isFirst ? 'disabled' : ''} aria-label="Previous app" title="Previous app">${arrowUp}</button>
          <button class="dw-nav-btn" data-dir="down" ${isLast ? 'disabled' : ''} aria-label="Next app" title="Next app">${arrowDown}</button>
        </div>
      </div>
      <div class="dash-widget-body">
        ${w.widget_html}
      </div>
    </div>
  `}).join('');

  // Activate inline <script> tags inside widget HTML.
  // innerHTML injection silently discards scripts (browser security).
  // Re-create them as live script elements so plugin widgets can
  // include inline JS alongside their HTML. External scripts (src)
  // are also handled for completeness.
  // Copy ALL attributes (type, async, defer, crossorigin,
  // data-*, nonce) so future plugins using module scripts or deferred
  // loading work without surprises.
  zone.querySelectorAll('.dash-widget-body script').forEach(dead => {
    const live = document.createElement('script');
    Array.from(dead.attributes).forEach(a => live.setAttribute(a.name, a.value));
    if (!dead.src) { live.textContent = dead.textContent; }
    dead.parentNode.replaceChild(live, dead);
  });

  zdzApplyHomePins();     // with the focus view, only pinned widgets show on home
  refreshIcons();
  initWidgetNav();       // header arrows now NAVIGATE the stack
  initWidgetVoidGuard(); // post-action gray-void safety net
  initDashScrollAnchor(); // widgets that load late never move what you're looking at

  // Find the widgets' overlays BEFORE plugin JS runs. Below 1200px each card is its own stacking context
  // (isolation:isolate, which guards [data-sticky-header]), so a plugin's position:fixed modal would be sealed under
  // .bnav; overlay-escape.js un-seals the one card that is showing an overlay (it never moves a node). Registering
  // them now, before the event below, means the first one shown is already known.
  try { if (window.ZDZOverlay) window.ZDZOverlay.scan(); } catch (e) {}

  // Notify widget scripts that the DOM is ready
  document.dispatchEvent(new Event('zdz_widgets_rendered'));
}

// The widget-header ^/v arrows are
// NAVIGATION: they page through the whole widget stack top→bottom and stop at
// the ends (the field expectation — "take me up through the apps"). What these
// arrows used to do — reorder the widget one slot — moves into a LONG-PRESS
// edit mode, so the default tap does what people expect. Also defined here: a
// shared sticky-aware "land on this element" scroll used by navigation, the
// public jump-link helper (window.zdzScrollToControl), and the post-action
// gray-void safety net.

/* ── The focus view's home ───────────────────────
   With the focus view on (the default; Settings can turn it off), tapping an app opens it as its own page
   (bridge.js focusWidget), so home no longer stacks every app: it is the ask bar, the launcher, then Today
   (the leads tile, the counts and any widget the person pinned). Every widget is still rendered at boot as
   before, so its scripts start, poll and count exactly as they did; an unpinned one is PARKED: laid out at its
   real width far above the page (so anything it measures is right) and hidden, so it takes no space, no taps
   and no tab stops. Opening it moves it into the page; Back puts it back and parks it again. */
function zdzFocusHome() {
  return !!(window.Bridge && typeof Bridge.focusOn === 'function' && Bridge.focusOn());
}
function zdzParkWidget(container) {
  if (!container || !container.classList) return;
  var id = container.getAttribute('data-app-id');
  var pinned = window.Bridge && Bridge.isPinned && Bridge.isPinned(id);
  // Only a widget that has a tile in the launcher is parked: one with no tile (springboard:false, or no color)
  // could never be opened again, so it stays on home as before.
  var hasTile = getVisibleApps().some(function (a) { return a.id === id; });
  var park = zdzFocusHome() && hasTile && !pinned && !container.classList.contains('zdz-focused');
  container.classList.toggle('is-pinned', !!pinned);
  container.classList.toggle('zdz-parked', park);
  // A card whose plugin opens its own surface from it (a cancelled launch: Camera, Dot Plot) stays LIVE while
  // parked: visible and tappable, still far above the page, so that surface can be seen.
  container.classList.toggle('zdz-parked-live', park && container.dataset.zdzLive === '1');
  if (park && container.dataset.zdzLive !== '1') container.setAttribute('aria-hidden', 'true');
  else container.removeAttribute('aria-hidden');
}
window.zdzParkWidget = zdzParkWidget;
// Settings → "Apps as pages". Saved for the person, then the app reloads into the other layout.
function zdzToggleFocusView(btn) {
  var next = !zdzFocusHome();
  var save = function () {
    return fetch(zdzData.apiUrl + 'user-prefs', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': zdzData.nonce },
      body: JSON.stringify({ focus_view: next }),
      credentials: 'same-origin'
    }).then(function (r) { if (!r.ok) throw new Error('save'); });
  };
  var done = function () { window.location.reload(); };
  var fail = function () { showToast('That setting did not save. Check the signal and try again.'); };
  if (window.ZDZ && ZDZ.ui && typeof ZDZ.ui.busy === 'function' && btn) ZDZ.ui.busy(btn, save).then(done, fail);
  else save().then(done, fail);
}
window.zdzToggleFocusView = zdzToggleFocusView;
function zdzApplyHomePins() {
  var zone = document.getElementById('dash-widget-zone');
  if (!zone) return;
  zone.querySelectorAll(':scope > .dash-widget-container').forEach(zdzParkWidget);
  zdzTodayLabel();
}
// "Today" is shown when something is in it: the leads tile, the counts, or a pinned widget.
function zdzTodayLabel() {
  var label = document.getElementById('zdz-today-label');
  if (!label) return;
  var shows = function (el) { return !!el && el.offsetParent !== null && el.getBoundingClientRect().height > 2; };
  var zone = document.getElementById('dash-widget-zone');
  var pinned = zone ? zone.querySelectorAll(':scope > .dash-widget-container.is-pinned').length : 0;
  label.hidden = !(pinned || shows(document.getElementById('leads-tile')) || shows(document.getElementById('quick-stats')));
}
// Bridge.togglePin() calls this after a pin changes: park or show each widget again, then Today's label.
// (Its own name: a function declared zdzTodayRefresh would BE window.zdzTodayRefresh, and call itself.)
window.zdzTodayRefresh = zdzApplyHomePins;
// Home's order with the focus view: ask bar, launcher, then Today. The markup keeps its classic order (dock,
// counts, widgets, recent, grid) so turning the focus view off changes nothing; this moves the launcher up.
function zdzSetupFocusHome() {
  if (!zdzFocusHome()) return;
  document.body.classList.add('zdz-focus-home');
  var sv = document.getElementById('sv-dash');
  var grid = document.getElementById('app-grid-container');
  var dock = document.getElementById('app-dock');
  var leads = document.getElementById('leads-tile');
  if (sv && grid && dock && dock.parentNode === sv) sv.insertBefore(grid, dock);
  if (sv && leads && leads.parentNode === sv && !document.getElementById('zdz-today-label')) {
    var label = document.createElement('div');
    label.className = 'section-label zdz-today-label';
    label.id = 'zdz-today-label';
    label.textContent = 'Today';
    label.hidden = true;
    sv.insertBefore(label, leads);
  }
  // The leads tile and the counts fill in after their own requests; Today follows them.
  if (typeof MutationObserver !== 'undefined') {
    var queued = false;
    var mo = new MutationObserver(function () {
      if (queued) return;
      queued = true;
      requestAnimationFrame(function () { queued = false; zdzTodayLabel(); });
    });
    ['leads-tile', 'quick-stats'].forEach(function (id) {
      var el = document.getElementById(id);
      if (el) mo.observe(el, { childList: true, subtree: true, attributes: true, attributeFilter: ['style', 'class', 'hidden'] });
    });
  }
}

/* ── Shared sticky-aware scroll (jump links and landings) ─────────── */
// The scroll container an element lives in (its sub-view), or the page of an app in focus.
function zdzActiveScroller(el) {
  return (el && el.closest && (el.closest('.dash-widget-container.zdz-focused > .dash-widget-body') || el.closest('#app-body') || el.closest('.sub-view')))
    || document.getElementById('sv-dash')
    || document.querySelector('.sub-view.active');
}
// Height of fixed chrome a target must clear: dash-top + the sticky app bar.
// Reserves the sticky bar's REAL height even while it is still hidden
// (position:fixed + translated, not display:none) — mirrors bridge.js so a
// jump never lands under the bar.
// inside an app's own page there is no dashboard chrome in the scroll (its header sits above it).
function zdzChromeInset(scroller) {
  if (scroller && !(scroller.classList && scroller.classList.contains('sub-view'))) return 0;
  var rootCS = getComputedStyle(document.documentElement);
  var topH = parseFloat(rootCS.getPropertyValue('--dash-top-h')) || 0;
  var stickyVar = parseFloat(rootCS.getPropertyValue('--dash-sticky-h')) || 0;
  var stickyEl = document.getElementById('dash-sticky');
  var stickyMeasured = stickyEl ? Math.round(stickyEl.getBoundingClientRect().height) : 0;
  return topH + Math.max(stickyVar, stickyMeasured);
}
// Land the element's top just below the chrome, reliably (instant + a short
// rAF re-verify for late layout shifts). Exposed as window.zdzScrollToControl so
// any flow that must move the user to a control uses ONE accurate jump instead
// of "scroll and hope" — a jump that lands in the wrong place is worse than
// none.
function zdzScrollToElement(target, opts) {
  opts = opts || {};
  if (!target) return;
  // a control in a parked app (not on home) — open that app first, then land on it in its page.
  var parked = target.closest && target.closest('.dash-widget-container.zdz-parked');
  if (parked && window.Bridge && !opts._opened) {
    var pid = parked.getAttribute('data-app-id');
    if (Bridge._launching === pid) {
      // Called from that app's own launch handler: the page is about to open; land once it has.
      var onFocus = function (e) {
        if (!e.detail || e.detail.appId !== pid) return;
        document.removeEventListener('zdz_app_focus', onFocus);
        requestAnimationFrame(function () { zdzScrollToElement(target, Object.assign({}, opts, { _opened: true })); });
      };
      document.addEventListener('zdz_app_focus', onFocus);
      setTimeout(function () { document.removeEventListener('zdz_app_focus', onFocus); }, 2000);
      return;
    }
    Bridge.loadApp(pid);
    if (!parked.classList.contains('zdz-parked')) {
      requestAnimationFrame(function () { zdzScrollToElement(target, Object.assign({}, opts, { _opened: true })); });
    }
    return;
  }
  var scroller = zdzActiveScroller(target);
  if (!scroller) { target.scrollIntoView({ block: 'start' }); return; }
  var gap = (opts.gap != null) ? opts.gap : 8;
  var compute = function () {
    var tRect = target.getBoundingClientRect();
    var sRect = scroller.getBoundingClientRect();
    return Math.max(0, scroller.scrollTop + (tRect.top - sRect.top) - zdzChromeInset(scroller) - gap);
  };
  scroller.scrollTop = compute();
  if (typeof window.zdzDashAnchorRepick === 'function') window.zdzDashAnchorRepick();
  var verify = function (n) {
    var t = compute();
    if (Math.abs(scroller.scrollTop - t) > 4) scroller.scrollTop = t;
    if (n > 0) requestAnimationFrame(function () { verify(n - 1); });
  };
  requestAnimationFrame(function () { verify(2); });
}
window.zdzScrollToControl = function (el, opts) { zdzScrollToElement(el, opts); };

// Brief highlight so the eye lands on the arrived widget.
function zdzPulseWidget(el) {
  if (!el) return;
  el.style.boxShadow = 'inset 0 0 0 2px #3B82F6, 0 0 12px rgba(59,130,246,.20)';
  el.style.borderRadius = '12px';
  setTimeout(function () { el.style.boxShadow = ''; }, 1100);
}

/* ── Widget-stack navigation + long-press reorder ───────────── */
function initWidgetNav() {
  var zone = document.getElementById('dash-widget-zone');
  if (!zone) return;
  updateWidgetArrowState();
  if (zone.__zdzNavBound) return; // bind listeners once; survive re-renders
  zone.__zdzNavBound = true;

  // Click an arrow: NAVIGATE by default, REORDER while in edit mode.
  zone.addEventListener('click', function (e) {
    var btn = e.target.closest('.dw-nav-btn');
    if (!btn || btn.disabled) return;
    var container = btn.closest('.dash-widget-container');
    if (!container) return;
    var dir = btn.dataset.dir;

    if (zone.classList.contains('zdz-widget-reordering')) {
      reorderWidget(container, dir);
      return;
    }
    var sibling = dir === 'up' ? container.previousElementSibling : container.nextElementSibling;
    // step over cards that are not on home (parked) or marker-only.
    while (sibling && (sibling.classList.contains('zdz-parked') || !sibling.classList.contains('dash-widget-container'))) {
      sibling = dir === 'up' ? sibling.previousElementSibling : sibling.nextElementSibling;
    }
    if (!sibling || !sibling.classList.contains('dash-widget-container')) return;
    zdzScrollToElement(sibling);
    zdzPulseWidget(sibling);
    try { Haptics.tap(); } catch (e2) {}
  });

  // Long-press a widget header (not on an arrow) → enter reorder edit mode.
  var lpTimer = null, lpX = 0, lpY = 0;
  zone.addEventListener('pointerdown', function (e) {
    var header = e.target.closest('.dash-widget-header');
    if (!header || e.target.closest('.dw-nav-btn')) return;
    if (header.closest('.zdz-focused') || document.body.classList.contains('zdz-focus-home')) return; // no reorder of a page / on the focus home
    lpX = e.clientX; lpY = e.clientY;
    lpTimer = setTimeout(function () { lpTimer = null; enterWidgetReorderMode(); }, 500);
  });
  zone.addEventListener('pointermove', function (e) {
    if (lpTimer) {
      var dx = e.clientX - lpX, dy = e.clientY - lpY;
      if (Math.sqrt(dx * dx + dy * dy) > 10) { clearTimeout(lpTimer); lpTimer = null; }
    }
  });
  var clearLp = function () { if (lpTimer) { clearTimeout(lpTimer); lpTimer = null; } };
  zone.addEventListener('pointerup', clearLp);
  zone.addEventListener('pointercancel', clearLp);
}

// Disabled state at the ends (stop-at-ends nav) + mode-aware labels.
function updateWidgetArrowState() {
  var zone = document.getElementById('dash-widget-zone');
  if (!zone) return;
  var reordering = zone.classList.contains('zdz-widget-reordering');
  var items = zone.querySelectorAll('.dash-widget-container');
  items.forEach(function (item, i) {
    var up = item.querySelector('.dw-nav-btn[data-dir="up"]');
    var down = item.querySelector('.dw-nav-btn[data-dir="down"]');
    if (up) {
      up.disabled = (i === 0);
      up.setAttribute('aria-label', reordering ? 'Move up' : 'Previous app');
      up.setAttribute('title', reordering ? 'Move up' : 'Previous app');
    }
    if (down) {
      down.disabled = (i === items.length - 1);
      down.setAttribute('aria-label', reordering ? 'Move down' : 'Next app');
      down.setAttribute('title', reordering ? 'Move down' : 'Next app');
    }
  });
}

// Swap a widget with its neighbor (edit-mode reorder), animated, then persist.
function reorderWidget(container, dir) {
  var zone = document.getElementById('dash-widget-zone');
  if (!zone || !container) return;
  var sibling = dir === 'up' ? container.previousElementSibling : container.nextElementSibling;
  if (!sibling || !sibling.classList.contains('dash-widget-container')) return;

  var containerRect = container.getBoundingClientRect();
  var siblingRect = sibling.getBoundingClientRect();
  var gap = parseInt(getComputedStyle(zone).gap) || 16;

  container.style.transition = 'transform 0.25s ease';
  sibling.style.transition = 'transform 0.25s ease';
  if (dir === 'up') {
    var distU = containerRect.top - siblingRect.top;
    container.style.transform = 'translateY(' + (-distU) + 'px)';
    sibling.style.transform = 'translateY(' + (containerRect.height + gap) + 'px)';
  } else {
    var distD = siblingRect.top - containerRect.top;
    container.style.transform = 'translateY(' + distD + 'px)';
    sibling.style.transform = 'translateY(' + (-(containerRect.height + gap)) + 'px)';
  }
  setTimeout(function () {
    container.style.transition = ''; container.style.transform = '';
    sibling.style.transition = ''; sibling.style.transform = '';
    if (dir === 'up') { zone.insertBefore(container, sibling); }
    else { zone.insertBefore(sibling, container); }
    // the widgets swapped places; measure the anchor again from here.
    if (typeof window.zdzDashAnchorRepick === 'function') window.zdzDashAnchorRepick();
    updateWidgetArrowState();
    saveWidgetOrder();
  }, 260);
  try { Haptics.tap(); } catch (e) {}
}

function enterWidgetReorderMode() {
  var zone = document.getElementById('dash-widget-zone');
  if (!zone || zone.classList.contains('zdz-widget-reordering')) return;
  zone.classList.add('zdz-widget-reordering');
  try { Haptics.tap(); } catch (e) {}
  var done = document.getElementById('zdz-widget-reorder-done');
  if (!done) {
    done = document.createElement('button');
    done.id = 'zdz-widget-reorder-done';
    done.className = 'zdz-widget-reorder-done';
    done.type = 'button';
    done.textContent = 'Done reordering ✓';
    done.addEventListener('click', function (e) { e.stopPropagation(); exitWidgetReorderMode(); });
    document.body.appendChild(done);
  }
  done.style.display = '';
  updateWidgetArrowState();
  // Defer so this same click doesn't immediately exit via the outside handler.
  setTimeout(function () { document.addEventListener('click', outsideWidgetReorder, true); }, 0);
}
function outsideWidgetReorder(e) {
  if (e.target.closest('.dash-widget-header') || e.target.closest('#zdz-widget-reorder-done')) return;
  exitWidgetReorderMode();
}
function exitWidgetReorderMode() {
  var zone = document.getElementById('dash-widget-zone');
  if (zone) zone.classList.remove('zdz-widget-reordering');
  var done = document.getElementById('zdz-widget-reorder-done');
  if (done) done.style.display = 'none';
  document.removeEventListener('click', outsideWidgetReorder, true);
  updateWidgetArrowState();
}

/* ── Post-action "gray void" safety net ─────────────────────────────── */
// Field report: press a blue action button → work runs → the widget re-renders
// and the view is left in a tall blank scrolled region ("a totally gray screen
// because it's scrolled"), NOT returned to the result. The correct fix is
// plugins adopting NO-SUBMIT-SHIFT (patch in place). This is a SCOPED safety net
// for widgets that still full-re-render: it acts ONLY on the widget the user
// just acted in, ONLY on a material shrink (a full-re-render signature), and
// ONLY when that widget's top has scrolled above the fold — then it lands the
// viewport back on the result/top. KPI refreshes and compliant in-place patches
// never trigger it.
var _zdzLastAction = { widget: null, at: 0 };
document.addEventListener('click', function (e) {
  var body = e.target.closest && e.target.closest('.dash-widget-body');
  if (!body) return;
  if (!e.target.closest('button, .btn, [role="button"], input[type="submit"], input[type="button"]')) return;
  _zdzLastAction = { widget: body.closest('.dash-widget-container'), at: Date.now() };
}, true);

function initWidgetVoidGuard() {
  var zone = document.getElementById('dash-widget-zone');
  if (!zone || zone.__zdzVoidGuard) return;
  zone.__zdzVoidGuard = true;

  var lastH = new WeakMap();
  var snapshot = function () {
    zone.querySelectorAll('.dash-widget-container').forEach(function (c) {
      lastH.set(c, c.getBoundingClientRect().height);
    });
  };
  snapshot();

  var timer = null, pendingTarget = null;
  var obs = new MutationObserver(function (muts) {
    var container = null;
    for (var i = 0; i < muts.length; i++) {
      var t = muts[i].target;
      var c = (t && t.closest) ? t.closest('.dash-widget-container') : null;
      if (c) { container = c; break; }
    }
    if (!container) return;
    pendingTarget = container;
    clearTimeout(timer);
    timer = setTimeout(function () { settleVoid(pendingTarget); }, 60);
  });
  obs.observe(zone, { childList: true, subtree: true });

  function settleVoid(container) {
    if (!container) return;
    var prevH = lastH.get(container) || 0;
    var nowH = container.getBoundingClientRect().height;
    snapshot();

    if (_zdzLastAction.widget !== container) return;   // not the acted widget
    if (Date.now() - _zdzLastAction.at > 4000) return; // stale
    if (prevH - nowH < 80) return;                    // not a material shrink

    var scroller = zdzActiveScroller(container);
    if (!scroller) return;
    var cRect = container.getBoundingClientRect();
    var sRect = scroller.getBoundingClientRect();
    var foldTop = sRect.top + zdzChromeInset();
    if (cRect.top >= foldTop - 4) return;             // top still visible → leave it

    var anchor = container.querySelector('[data-zdz-result]') || container;
    zdzScrollToElement(anchor);
    zdzPulseWidget(container);
    _zdzLastAction.at = 0;                             // consume
  }
}

// DASHBOARD SCROLL ANCHOR (first-load layout shift, all browsers).

// Field report: on a first load, tapping an app icon lands on its
// widget, then the widgets ABOVE it finish loading (Leads, Surveys, Jobs,
// Knowledge, Inbox... grow by up to ~1,400px each) and push it out of view.
// bridge.js.1's pin polled every 100ms, so each growth was PAINTED before
// it was corrected (a visible jump down and back), and it stopped after 4s or
// 500ms of quiet, so a widget that loaded later still shoved the view. Safari
// (the installed app on Mac and iPad) has no native scroll anchoring at all.

// This is scroll anchoring done by the theme, identically everywhere: the
// widget at the top of the view is the ANCHOR, and whenever any widget
// changes size the view is moved by exactly how far the anchor moved, inside
// the ResizeObserver callback — after layout, BEFORE paint — so nothing is
// ever drawn in the wrong place. Growth below the anchor, or inside it, moves
// nothing (its top doesn't move). At the very top of the dashboard (scrollTop
// 0) nothing is anchored, so the icon grid stays put. The anchor is re-chosen
// on every scroll, so a real scroll by the person is always respected.
// Native anchoring is switched off on #sv-dash (app.css) so Chrome doesn't
// correct twice with a different anchor.
function initDashScrollAnchor() {
  var zone = document.getElementById('dash-widget-zone');
  var scroller = document.getElementById('sv-dash');
  if (!zone || !scroller || typeof ResizeObserver === 'undefined') return;

  var st = zone.__zdzAnchor;
  if (!st) {
    st = zone.__zdzAnchor = { el: null, top: 0 };
    var pick = function () {
      st.el = null;
      if (scroller.clientHeight < 2 || scroller.scrollTop <= 0) return;
      var fold = scroller.getBoundingClientRect().top + zdzChromeInset();
      var list = zone.querySelectorAll('.dash-widget-container');
      for (var i = 0; i < list.length; i++) {
        var r = list[i].getBoundingClientRect();
        if (r.height < 1) continue;
        if (r.bottom > fold) { st.el = list[i]; st.top = r.top; return; }
      }
    };
    st.pick = pick;
    scroller.addEventListener('scroll', pick, { passive: true });
    st.ro = new ResizeObserver(function () {
      var a = st.el;
      if (a && a.isConnected && scroller.clientHeight > 1) {
        var ar = a.getBoundingClientRect();
        // an anchor that was just hidden (display:none) reads as a zero rect;
        // "moving" by -top would jump the view. Skip it; pick() chooses a new anchor.
        var d = ar.height >= 1 ? ar.top - st.top : 0;
        if (Math.abs(d) >= 1) scroller.scrollTop += d;
      }
      pick();
    });
    // Anyone who moves the view on purpose (bridge.js launch, jump-links)
    // re-picks at once, so a growth in the same frame is measured against
    // the new position, not the old one.
    window.zdzDashAnchorRepick = pick;
  }
  // (Re)observe: renderWidgets() replaces every container.
  st.ro.disconnect();
  zone.querySelectorAll('.dash-widget-container').forEach(function (c) { st.ro.observe(c); });
  // the tiles ABOVE the widget zone load late too (leads tile, quick stats,
  // the sticky bar). Watched so their growth is absorbed like a widget's; never anchors.
  ['leads-tile', 'quick-stats', 'dash-sticky'].forEach(function (id) {
    var e = document.getElementById(id);
    if (e) st.ro.observe(e);
  });
  st.pick();
}

function saveWidgetOrder() {
  const zone = document.getElementById('dash-widget-zone');
  if (!zone) return;
  const order = Array.from(zone.querySelectorAll('.dash-widget-container'))
    .map(el => el.dataset.appId)
    .filter(Boolean);

  // Update local state so pull-to-refresh preserves the order
  zdzData.widgetOrder = order;

  // Persist via REST
  fetch(zdzData.apiUrl + 'user-prefs', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': zdzData.nonce },
    body: JSON.stringify({ widget_order: order }),
    credentials: 'same-origin'
  }).catch(() => {}); // silent — order is already applied visually
}

// ---- SETTINGS ----
function renderSettings() {
  const area = document.getElementById('profile-card-area');
  if (area) {
    area.innerHTML = `
      <div class="profile-card">
        <div class="profile-avatar${window.ZDZ && ZDZ.ui && ZDZ.ui.person && ZDZ.ui.person(zdzData.userId) ? ' has-badge' : ''}">${
          window.ZDZ && ZDZ.ui && ZDZ.ui.person && ZDZ.ui.person(zdzData.userId) ? ZDZ.ui.personChip(zdzData.userId, { size: 'lg' }) : state.user.ini}</div>
        <div class="profile-info">
          <h3>${state.user.name}</h3>
          <p>${state.user.label}</p>
        </div>
      </div>
    `;
  }

  const themes = [
    { id: 'system', label: 'Auto', preview: 'linear-gradient(135deg,#F8FAFC 50%,#1E293B 50%)' },
    { id: 'light', label: 'Light', preview: '#F8FAFC' },
    { id: 'dark', label: 'Dark', preview: '#1E293B' },
    { id: 'sunlight', label: 'Sun ☀', preview: '#FFFFFF' }
  ];
  const tgrid = document.getElementById('theme-grid');
  if (tgrid) {
    // Larger text (accessibility) — rendered with the theme grid so
    // display prefs live together. Toggles data-zdz-textscale="lg" on <html>.
    const tscOn = document.documentElement.getAttribute('data-zdz-textscale') === 'lg';
    tgrid.innerHTML = themes.map(t => `
      <button class="theme-btn ${state.theme === t.id ? 'active' : ''}" onclick="applyTheme('${t.id}');renderSettings()" aria-label="Theme: ${t.label}">
        <div class="tb-preview" style="background:${t.preview}"></div>
        ${t.label}
      </button>
    `).join('') + `
      <button class="theme-btn ${tscOn ? 'active' : ''}" onclick="toggleTextScale();renderSettings()" aria-pressed="${tscOn}" aria-label="Larger text ${tscOn ? 'on' : 'off'}">
        <div class="tb-preview" style="display:flex;align-items:center;justify-content:center;gap:2px;font-weight:800;color:var(--sys-text);background:var(--sys-surface-raised)"><span style="font-size:12px">A</span><span style="font-size:18px">A</span></div>
        Larger text
      </button>` + (function () {
        // the focus view can be turned off per person, which brings back the one long page.
        var fOn = zdzFocusHome();
        return `
      <button class="theme-btn ${fOn ? 'active' : ''}" id="zdz-focus-toggle" onclick="zdzToggleFocusView(this)" aria-pressed="${fOn}" aria-label="Apps open as their own page: ${fOn ? 'on' : 'off'}">
        <div class="tb-preview" style="display:flex;align-items:center;justify-content:center;color:var(--sys-text);background:var(--sys-surface-raised)"><i data-lucide="${fOn ? 'app-window' : 'rows-3'}" style="width:20px;height:20px"></i></div>
        Apps as pages
      </button>`;
      })();
  }

  const sinfo = document.getElementById('settings-info');
  if (sinfo) {
    const isOnline = navigator.onLine;
    sinfo.innerHTML = `
      <h4>System</h4>
      <div class="settings-row">
        <div class="sr-left"><i data-lucide="wifi"></i><span class="sr-label">Network</span></div>
        <div class="sr-right"><span class="status-badge ${isOnline ? 'online' : 'offline'}"><span class="status-dot"></span>${isOnline ? 'Online' : 'Offline'}</span></div>
      </div>
      <div class="settings-row">
        <div class="sr-left"><i data-lucide="cpu"></i><span class="sr-label">Version</span></div>
        <div class="sr-right">${zdzData.themeVersion || 'v6.0'}</div>
      </div>
      <div class="settings-row">
        <div class="sr-left"><i data-lucide="shield"></i><span class="sr-label">Role</span></div>
        <div class="sr-right">${state.user.label}</div>
      </div>
      <div class="settings-row">
        <div class="sr-left"><i data-lucide="layout-grid"></i><span class="sr-label">Apps Available</span></div>
        <div class="sr-right">${getVisibleApps().length}</div>
      </div>
      ${state.isAdmin ? `<div class="settings-row mt-4">
        <button class="btn btn-outline w-full" onclick="window.location.href='/wp-admin/'">
          <i data-lucide="settings-2"></i> WordPress Admin
        </button>
      </div>` : ''}
      ${zdzData.canEnterKiosk ? `<div class="settings-row mt-4">
        <button class="btn btn-outline w-full" onclick="enterKioskDemo()">
          <i data-lucide="monitor-smartphone"></i> Enter Demo / Kiosk Mode
        </button>
      </div>` : ''}
      ${zdzData.kioskDemoActive ? `<div class="settings-row mt-4">
        <button class="btn btn-outline w-full zdz-kiosk-exit-btn" onclick="exitKioskDemo()">
          <i data-lucide="lock-open"></i> Exit Demo Mode (PIN)
        </button>
      </div>` : ''}
      ${zdzData.kioskDemoActive ? '' : `<div class="settings-row mt-4">
        <button class="btn btn-outline w-full" data-zdz-logout onclick="zdzLogout()">
          <i data-lucide="log-out"></i> Logout
        </button>
      </div>`}
    `;
  }

  // Field Preferences card (after System, before App Authorizations)
  renderFieldPreferences();

  // / App Authorizations card — FreshBooks + Nutshell.
  renderAppAuthorizations();
}

// ---- KIOSK / DEMO MODE ------------------------------------------------
// One-tap, PIN-protected switch into the General (shared-kiosk) account so an
// admin can hand the device to a guest for a demo. The actual identity switch
// is enforced SERVER-SIDE (ZDZ_Kiosk_Demo): for the duration of demo mode every
// request runs as the real General account, so the guest inherits the kiosk's
// all-deny permissions, ephemeral chat, read-only messaging, and locked-down
// dashboard — and cannot reach admin powers even via the API. The admin's true
// login session is untouched and is restored on exit. These client functions
// are just the affordance + PIN prompt; the server is the source of truth.

// the PIN is typed into the app's own sheet -- masked, numeric, with a show/hide eye --
// never a browser prompt(), which can't mask what is typed and which nothing automated may drive. Demo Mode switches
// THIS DEVICE only.
// Before each call the nonce is renewed (zdzFreshNonce): a stale one is WordPress's "Cookie check failed". If a
// step fails the sheet says why in plain words, and "Nothing changed." only once the server has said so.

var _ZDZ_PIN_WHY = {
  no_kiosk_user: 'there is no shared General account to switch to. An administrator needs to create one',
  self_target: 'the General account is set up wrong. It must be a plain shared login, not an administrator',
  forbidden: 'only an administrator can start it',
  no_session: "this device's sign-in can't hold it. Sign out, sign in again, and retry",
  not_saved: "the server couldn't save it",
  rest_cookie_invalid_nonce: "this page's sign-in check is out of date. Reload the app and try again",
  rest_forbidden: "you're signed out. Sign in again",
  bad_pin: 'the PIN must be 4 to 10 digits',
  offline: 'no connection. Check your signal',
  busy: 'the server was busy with this account. Wait a moment',
  server: 'the server had a problem. Try again in a moment'
};

function _zdzPinField(id, label) {
  return '<label class="zdz-pin-field" for="' + id + '"><span class="zdz-pin-label">' + label + '</span>' +
    '<span class="zdz-pin-wrap"><input id="' + id + '" class="zdz-pin-input" type="password" inputmode="numeric" pattern="[0-9]*" ' +
    'autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false" maxlength="10" ' +
    'data-1p-ignore="true" data-lpignore="true" data-form-type="other">' +
    '<button type="button" class="zdz-pin-eye" data-for="' + id + '" aria-label="Show PIN" aria-pressed="false">' +
    '<i data-lucide="eye"></i></button></span></label>';
}

// Digits only, at most 10; the eye shows or hides what is typed.
function _zdzWirePins(root) {
  Array.prototype.forEach.call(root.querySelectorAll('.zdz-pin-input'), function (inp) {
    inp.addEventListener('input', function () {
      var v = inp.value.replace(/\D/g, '').slice(0, 10);
      if (v !== inp.value) inp.value = v;
    });
  });
  Array.prototype.forEach.call(root.querySelectorAll('.zdz-pin-eye'), function (btn) {
    btn.addEventListener('click', function () {
      var inp = document.getElementById(btn.getAttribute('data-for'));
      if (!inp) return;
      var show = inp.type === 'password';
      inp.type = show ? 'text' : 'password';
      btn.setAttribute('aria-pressed', show ? 'true' : 'false');
      btn.setAttribute('aria-label', show ? 'Hide PIN' : 'Show PIN');
      btn.innerHTML = '<i data-lucide="' + (show ? 'eye-off' : 'eye') + '"></i>';
      if (window.lucide && lucide.createIcons) { try { lucide.createIcons(); } catch (e) {} }
      try { inp.focus(); } catch (e) {}
    });
  });
  if (window.lucide && lucide.createIcons) { try { lucide.createIcons(); } catch (e) {} }
}

// POST kiosk-demo/<kind> with a fresh nonce; once more with another fresh one if WordPress still calls it stale.
// Resolves {status, j} -- or {status: 0, j: {code: 'offline'}} when nothing came back.
function _zdzKioskCall(kind, pin) {
  function post() {
    return fetch(zdzData.apiUrl + 'kiosk-demo/' + kind, {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': zdzData.nonce },
      body: JSON.stringify({ pin: pin })
    }).then(function (r) {
      return r.text().then(function (t) {
        var j = null;
        try { j = JSON.parse(t); } catch (e) { /* a host's error page or a PHP error page: not an answer */ }
        // A 5xx, a gateway page or a reply spoiled by a PHP notice may come AFTER the server
        // switched, so such an answer is "unsure": the caller asks the server which state the device is in.
        var unsure = !j || r.status >= 500;
        if (!j) j = { code: r.status >= 500 || r.ok ? 'server' : 'rest_forbidden' };
        return { status: r.status, j: j, unsure: unsure };
      });
    }, function () { return { status: 0, j: { code: 'offline' }, lost: true, unsure: true }; });
  }
  return zdzFreshNonce().then(post, function (e) {
    // Couldn't renew: nothing was sent, so nothing changed.
    return { status: 0, j: { code: e && e.message === 'signed_out' ? 'rest_forbidden' : 'offline' }, unsent: true };
  }).then(function (res) {
    if (res.j && res.j.code === 'rest_cookie_invalid_nonce') {
      return zdzFreshNonce().then(post, function () { return res; });
    }
    return res;
  });
}

// After an unclear answer the server may still be finishing the request (a gateway 504 while PHP
// runs on), so "it did not change" is only said when two checks 4 seconds apart agree; one "it changed" is enough.
// Resolves true (Demo Mode on), false (off), or rejects when the server can't be reached.
function _zdzDemoNow(wantChange) {
  return zdzFreshNonce().then(function (a) {
    if (a.demo === wantChange) return a.demo;
    return new Promise(function (r) { setTimeout(r, 4000); }).then(zdzFreshNonce).then(function (b) { return b.demo; });
  });
}

function _zdzPinSheetFail(sheet, words) {
  var btn = sheet.el.querySelector('.zdz-btn--primary');
  if (btn) btn.textContent = 'Try again';
  Haptics && Haptics.error && Haptics.error();
  return Promise.reject(new Error(words));
}

/* Settings → Logout asks in the app's own sheet, not window.confirm() (a browser dialog blocks the tab and freezes
   an automated browser). Open from inside the tap
   so Back closes the sheet. */
// the home tiles' last answer kept on this device (it can carry revenue) goes with the sign-out.
function zdzKpiForget() {
  try { Object.keys(localStorage).forEach(function (k) { if (/^zdz_kpi_v2_/.test(k)) { localStorage.removeItem(k); } }); } catch (e) { /* storage blocked */ }
}
function zdzLogout() {
  Haptics && Haptics.tap && Haptics.tap();
  var url = String((typeof zdzData !== 'undefined' && zdzData.logoutUrl) || '');
  if (!url) return;
  // The kit always ships with the theme; if it somehow did not load, the tap the person made still signs them out.
  if (!window.ZDZ || !ZDZ.ui || !ZDZ.ui.sheet) { zdzKpiForget(); window.location.href = url; return; }
  ZDZ.ui.sheet({
    title: 'Log out of this account?',
    body: '<p class="zdz-logout-intro">You will need to sign in again on this device.</p>',
    actions: [
      { label: 'Cancel' },
      { label: 'Log out', kind: 'primary', onClick: function (sheet) {
        // From here the sheet cannot be closed: closing it calls history.back(), which would cancel the sign-out page
        // load halfway (the server's session gone, the app still on screen). The kit's own Back handler
        // keeps a sheet that is not dismissible, and every other way out goes through close().
        zdzKpiForget();
        sheet._dismissible = false;
        sheet.close = function () {};
        Array.prototype.forEach.call(sheet.el.querySelectorAll('button'), function (b) { if (!b.classList.contains('zdz-btn--primary')) b.disabled = true; });
        // A Back press cancels a page load in any browser; the person chose Log out, so go there again.
        window.addEventListener('popstate', function () { window.location.href = url; });
        // If Safari brings this page back from its back-forward cache after the sign-out, show the truth.
        window.addEventListener('pageshow', function (e) { if (e.persisted) window.location.reload(); });
        window.location.href = url;
        return new Promise(function () {}); // stay busy until the page changes
      } }
    ]
  });
}

function enterKioskDemo() {
  Haptics && Haptics.tap && Haptics.tap();
  if (!window.ZDZ || !ZDZ.ui || !ZDZ.ui.sheet) { showToast("Couldn't open Demo Mode. Reload the app and try again."); return; }
  var sheet = ZDZ.ui.sheet({
    title: 'Enter Demo Mode',
    body: '<p class="zdz-pin-intro">This device will run as the shared General account until someone types this PIN. ' +
      'Hand it to anyone: they can’t leave Demo Mode without the PIN. Your other devices stay signed in as you.</p>' +
      _zdzPinField('zdz-pin-new', 'Choose a PIN (4 to 10 digits)') +
      _zdzPinField('zdz-pin-again', 'Type the PIN again'),
    actions: [
      { label: 'Cancel' },
      { label: 'Enter Demo Mode', kind: 'primary', onClick: function (s) { return _zdzEnterDemo(s); } }
    ]
  });
  _zdzWirePins(sheet.el);
  setTimeout(function () { var f = document.getElementById('zdz-pin-new'); if (f) { try { f.focus(); } catch (e) {} } }, 60);
}

function _zdzEnterDemo(sheet) {
  var a = (document.getElementById('zdz-pin-new') || {}).value || '';
  var b = (document.getElementById('zdz-pin-again') || {}).value || '';
  if (!/^\d{4,10}$/.test(a)) return Promise.reject(new Error('The PIN must be 4 to 10 digits.'));
  if (a !== b) return Promise.reject(new Error("The two PINs don't match. Type them again."));
  return _zdzKioskCall('enter', a).then(function (res) {
    var j = res.j || {};
    if (j.success && j.active) {
      if (j.nonce) zdzData.nonce = j.nonce;
      window.location.replace(j.redirect || '/');
      return new Promise(function () {}); // stay busy until the page changes
    }
    var why = _ZDZ_PIN_WHY[j.code] || (typeof j.message === 'string' && j.message ? j.message.replace(/\.$/, '') : _ZDZ_PIN_WHY.server);
    if (!res.unsure) return _zdzPinSheetFail(sheet, "Couldn't enter Demo Mode: " + why + '. Nothing changed.');
    // The request went out but no clear answer came back: ask the server which state this device is in.
    return _zdzDemoNow(true).then(function (on) {
      if (on) { window.location.replace('/'); return new Promise(function () {}); }
      return _zdzPinSheetFail(sheet, "Couldn't enter Demo Mode: " + why + '. Nothing changed.');
    }, function () {
      return _zdzPinSheetFail(sheet, "Couldn't reach the server, so it isn't known whether Demo Mode started. Reload the app when you have signal: the green bar shows if it did.");
    });
  });
}

function exitKioskDemo() {
  Haptics && Haptics.tap && Haptics.tap();
  if (!window.ZDZ || !ZDZ.ui || !ZDZ.ui.sheet) { showToast("Couldn't open the PIN sheet. Reload the app and try again."); return; }
  var sheet = ZDZ.ui.sheet({
    title: 'Exit Demo Mode',
    body: '<p class="zdz-pin-intro">Type the PIN chosen when Demo Mode started on this device.</p>' +
      _zdzPinField('zdz-pin-exit', 'PIN'),
    actions: [
      { label: 'Cancel' },
      { label: 'Exit Demo Mode', kind: 'primary', onClick: function (s) { return _zdzExitDemo(s); } }
    ]
  });
  _zdzWirePins(sheet.el);
  setTimeout(function () { var f = document.getElementById('zdz-pin-exit'); if (f) { try { f.focus(); } catch (e) {} } }, 60);
}

function _zdzExitDemo(sheet) {
  var inp = document.getElementById('zdz-pin-exit');
  var pin = (inp && inp.value) || '';
  if (!/^\d{4,10}$/.test(pin)) return Promise.reject(new Error('Type the 4 to 10 digit PIN.'));
  return _zdzKioskCall('exit', pin).then(function (res) {
    var j = res.j || {};
    if (j.success && j.active === false) {
      if (j.nonce) zdzData.nonce = j.nonce;
      window.location.replace(j.redirect || '/');
      return new Promise(function () {});
    }
    if (inp) { inp.value = ''; try { inp.focus(); } catch (e) {} }
    if ((j.code === 'bad_pin' || j.code === 'locked' || j.code === 'busy') && typeof j.message === 'string') {
      return _zdzPinSheetFail(sheet, j.message + ' Still in Demo Mode.');
    }
    var why = _ZDZ_PIN_WHY[j.code] || (typeof j.message === 'string' && j.message ? j.message.replace(/\.$/, '') : _ZDZ_PIN_WHY.server);
    if (!res.unsure) return _zdzPinSheetFail(sheet, "Couldn't exit Demo Mode: " + why + '. Still in Demo Mode.');
    // No clear answer: the PIN may have been accepted. Never say "Still in Demo Mode" without asking the server,
    // or someone may hand over a device that is already back on the administrator's account.
    return _zdzDemoNow(false).then(function (on) {
      if (!on) { window.location.replace('/'); return new Promise(function () {}); }
      return _zdzPinSheetFail(sheet, "Couldn't exit Demo Mode: " + why + '. Still in Demo Mode.');
    }, function () {
      return _zdzPinSheetFail(sheet, "Couldn't reach the server, so it isn't known whether Demo Mode ended. Don't hand the device over: reload the app when you have signal, and the green bar shows if Demo Mode is still on.");
    });
  });
}

// Persistent indicator so it's always obvious the device is in safe demo mode.
// Rendered once at boot when kioskDemoActive is true. It is intentionally
// minimal and non-interactive except for the PIN-gated exit, so a guest can
// see the state but cannot escalate.
function renderKioskDemoBanner() {
  if (!zdzData.kioskDemoActive) return;
  if (document.getElementById('zdz-kiosk-banner')) return;
  var bar = document.createElement('div');
  bar.id = 'zdz-kiosk-banner';
  bar.setAttribute('role', 'status');
  bar.innerHTML =
    '<span class="zdz-kiosk-banner-label">' +
    '<i data-lucide="shield-check"></i> Demo Mode — shared kiosk view</span>' +
    '<button class="zdz-kiosk-banner-exit" onclick="exitKioskDemo()">Exit (PIN)</button>';
  document.body.appendChild(bar);
  if (window.lucide && lucide.createIcons) { try { lucide.createIcons(); } catch (e) {} }
}


// Collapsible card for per-salesperson notation, walkthrough, and estimation habits.
// Visible to salesperson-level roles and above. Loads lazily on first expand.
var _fpLoaded = false;

function renderFieldPreferences() {
  var fieldRoles = ['administrator', 'zdz_owner', 'zdz_admin', 'zdz_sales', 'zdz_tech'];
  if (fieldRoles.indexOf(state.role) === -1) return;

  var sinfo = document.getElementById('settings-info');
  if (!sinfo) return;

  var card = document.getElementById('zdz-field-prefs-card');
  if (!card) {
    card = document.createElement('div');
    card.id = 'zdz-field-prefs-card';
    card.className = 'settings-section zdz-field-prefs-card';
    sinfo.after(card);
  }

  _fpLoaded = false;
  card.innerHTML =
    '<div class="zdz-fp-header" id="zdz-fp-toggle" role="button" tabindex="0" aria-expanded="false" aria-controls="zdz-fp-body">' +
      '<div class="zdz-fp-header-left"><i data-lucide="clipboard-list"></i><h4>Field Preferences</h4></div>' +
      '<i data-lucide="chevron-down" class="zdz-fp-chevron" id="zdz-fp-chevron"></i>' +
    '</div>' +
    '<div id="zdz-fp-body" class="zdz-fp-body" style="display:none">' +
      '<p class="zdz-fp-hint">How you write notes, abbreviations, and walkthrough patterns. ' +
      'The assistant and the Estimate Creator use this to parse your handwritten estimates accurately.</p>' +
      '<div id="zdz-fp-fields"><div class="zdz-fp-loading">Loading…</div></div>' +
    '</div>';
  refreshIcons();

  var fpToggle = document.getElementById('zdz-fp-toggle');
  function toggleFieldPrefs() {
    var body = document.getElementById('zdz-fp-body');
    var chevron = document.getElementById('zdz-fp-chevron');
    var willOpen = body.style.display === 'none';
    if (willOpen) {
      body.style.display = 'block';
      if (chevron) chevron.style.transform = 'rotate(180deg)';
      if (!_fpLoaded) loadFieldPreferences();
    } else {
      body.style.display = 'none';
      if (chevron) chevron.style.transform = '';
    }
    fpToggle.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
  }
  fpToggle.addEventListener('click', toggleFieldPrefs);
  // Keyboard parity: the header is role="button", so Enter/Space must toggle it.
  fpToggle.addEventListener('keydown', function(e) {
    if (e.key === 'Enter' || e.key === ' ' || e.key === 'Spacebar') {
      e.preventDefault();
      toggleFieldPrefs();
    }
  });
}

function loadFieldPreferences() {
  _fpLoaded = true;
  // Fetch schema and profile in parallel — schema is the single source of truth
  Promise.all([
    fetch(zdzData.apiUrl + 'user-profile/field-preferences-schema', {
      headers: { 'X-WP-Nonce': zdzData.nonce },
      credentials: 'same-origin'
    }).then(function(r) { return r.json(); }),
    fetch(zdzData.apiUrl + 'user-profile', {
      headers: { 'X-WP-Nonce': zdzData.nonce },
      credentials: 'same-origin'
    }).then(function(r) { return r.json(); })
  ])
  .then(function(results) {
    var schemaResp = results[0];
    var profileResp = results[1];
    if (!schemaResp || !schemaResp.success) throw new Error('schema-load-failed');
    if (!profileResp || !profileResp.success) throw new Error('profile-load-failed');

    var schemaFields = schemaResp.fields; // [{key, type, label, hint}, ...]
    var fp = profileResp.profile.field_preferences || {};
    var notationProfile = profileResp.profile.notation_profile || {};

    // One-time offer: if zdz_field_preferences is empty but the Estimates
    // notation profile has data, pre-populate the form fields from it.
    var isEmpty = Object.keys(fp).length === 0;
    var hasNotation = Object.keys(notationProfile).length > 0;
    if (isEmpty && hasNotation) {
      fp = fieldPrefsFromNotationProfile(notationProfile, schemaFields);
    }

    renderFieldPreferenceFields(schemaFields, fp, isEmpty && hasNotation);
  })
  .catch(function() {
    var c = document.getElementById('zdz-fp-fields');
    if (c) c.innerHTML = '<p class="zdz-fp-error">Failed to load preferences. Try again later.</p>';
  });
}

function fieldPrefsFromNotationProfile(notation, schemaFields) {
  // Map notation-profile keys onto the zdz_field_preferences schema.
  // Only keys that exist in the current schema are carried.
  var migrated = {};
  schemaFields.forEach(function(f) {
    if (notation[f.key]) {
      if (f.type === 'string_array') {
        migrated[f.key] = Array.isArray(notation[f.key]) ? notation[f.key] : [notation[f.key]];
      } else {
        migrated[f.key] = String(notation[f.key]);
      }
    }
  });
  return migrated;
}

function renderFieldPreferenceFields(schemaFields, fp, isMigration) {
  var html = '';

  if (isMigration) {
    html += '<div class="zdz-fp-migration-banner">' +
      '<i data-lucide="info"></i> ' +
      'Pre-populated from your Estimate Creator notation profile. Review and save to confirm.' +
    '</div>';
  }

  // Render form fields dynamically from schema
  schemaFields.forEach(function(f) {
    var val = fp[f.key] || (f.type === 'string_array' ? [] : '');
    var textVal = f.type === 'string_array' ? val.join('\n') : val;
    var rows = f.type === 'string_array' ? 3 : 2;
    html +=
      '<div class="zdz-fp-field">' +
        '<label class="zdz-fp-label" for="zdz-fp-' + f.key + '">' + escapeHtml(f.label) + '</label>' +
        '<p class="zdz-fp-field-hint">' + escapeHtml(f.hint) + '</p>' +
        '<textarea id="zdz-fp-' + f.key + '" class="zdz-fp-textarea" data-fp-key="' + f.key + '" data-fp-type="' + f.type + '" ' +
          'rows="' + rows + '" placeholder="Not set">' +
          escapeHtml(textVal) +
        '</textarea>' +
      '</div>';
  });

  html += '<div class="zdz-fp-actions">' +
    '<button class="btn btn-brand" id="zdz-fp-save-btn"><i data-lucide="save"></i> Save Field Preferences</button>' +
  '</div>';

  var container = document.getElementById('zdz-fp-fields');
  if (container) {
    container.innerHTML = html;
    refreshIcons();

    // Auto-resize textareas
    container.querySelectorAll('.zdz-fp-textarea').forEach(function(ta) {
      autoResizeTextarea(ta);
      ta.addEventListener('input', function() { autoResizeTextarea(this); });
    });

    document.getElementById('zdz-fp-save-btn').addEventListener('click', saveFieldPreferences);
  }
}

function autoResizeTextarea(ta) {
  ta.style.height = 'auto';
  ta.style.height = Math.max(ta.scrollHeight, 56) + 'px';
}

function saveFieldPreferences() {
  var btn = document.getElementById('zdz-fp-save-btn');
  if (!btn || btn.disabled) return;
  btn.disabled = true;
  btn.textContent = 'Saving…';

  // Build payload from DOM — types come from schema via data-fp-type attributes
  var payload = {};
  document.querySelectorAll('.zdz-fp-textarea').forEach(function(ta) {
    var key = ta.getAttribute('data-fp-key');
    var type = ta.getAttribute('data-fp-type');
    var raw = ta.value.trim();
    if (!raw) return;
    if (type === 'string_array') {
      payload[key] = raw.split('\n').map(function(s) { return s.trim(); }).filter(Boolean);
    } else {
      payload[key] = raw;
    }
  });

  fetch(zdzData.apiUrl + 'user-profile/field-preferences', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': zdzData.nonce },
    body: JSON.stringify(payload),
    credentials: 'same-origin'
  })
  .then(function(r) { return r.json(); })
  .then(function(resp) {
    if (!resp || !resp.success) throw new Error(resp.error || 'save-failed');
    showToast('Field preferences saved');
    if (typeof Haptics !== 'undefined') Haptics.success();
  })
  .catch(function(e) {
    showToast('Save failed — ' + (e.message || 'try again'));
    if (typeof Haptics !== 'undefined') Haptics.error();
  })
  .finally(function() {
    btn.disabled = false;
    btn.innerHTML = '<i data-lucide="save"></i> Save Field Preferences';
    refreshIcons();
  });
}

// ---- APP AUTHORIZATIONS (/) --------------------------------
function renderAppAuthorizations() {
  const sinfo = document.getElementById('settings-info');
  if (!sinfo) return;

  let card = document.getElementById('zdz-app-auth-card');
  if (!card) {
    card = document.createElement('div');
    card.id = 'zdz-app-auth-card';
    card.className = 'settings-section zdz-app-auth-card';
    card.innerHTML = `
      <h4>App Authorizations</h4>
      <div id="zdz-app-auth-rows" class="zdz-app-auth-rows">
        <div class="settings-row"><div class="sr-left"><span class="sr-label">Loading…</span></div></div>
      </div>
    `;
    // Insert after Field Preferences card, or after System info if no field prefs
    var anchor = document.getElementById('zdz-field-prefs-card') || sinfo;
    anchor.after(card);
  }

  fetch(zdzData.apiUrl + 'app-authorizations', {
    headers: { 'X-WP-Nonce': zdzData.nonce },
    credentials: 'same-origin'
  })
  .then(r => r.json())
  .then(resp => {
    if (!resp || !resp.success) throw new Error('auth-status-failed');
    const d  = resp.data || {};
    const fb = d.freshbooks || {};
    const ns = d.nutshell   || {};
    const cal = d.calendars || null; // v1.1.0 — Connected Calendars (present only when a scheduler plugin registers it via zdz_app_authorizations)
    const rows = document.getElementById('zdz-app-auth-rows');
    if (!rows) return;

    const isAdmin = !!(zdzData && zdzData.isAdmin);

    const badge = on => on
      ? '<span class="status-badge online"><span class="status-dot"></span>Connected</span>'
      : '<span class="status-badge offline"><span class="status-dot"></span>Not connected</span>';

    // ---- FreshBooks row (unchanged — per-user flow) ----
    const fbBtnLabel = fb.connected ? 'Re-authorize FreshBooks' : 'Authorize FreshBooks';
    const fbDisabled = fb.configured ? '' : 'disabled';
    const fbHint = fb.configured
      ? 'Connects this account to FreshBooks so estimates and invoices can be created on your behalf.'
      : 'Waiting for an administrator to paste the FreshBooks Client ID and Client Secret in wp-admin.';

    // ---- Nutshell row (company-wide —) ----
    let nsHint, nsAction;
    if (ns.connected && isAdmin) {
      nsHint = 'Company account is connected as <strong>' + escapeHtml(ns.email || '') + '</strong>. ' +
               'Everyone on the team uses this same Nutshell connection — no individual setup required.';
      nsAction = `
        <div class="settings-row mt-4">
          <button id="zdz-authorize-ns-btn" class="btn btn-outline w-full">
            <i data-lucide="key-round"></i> Update Company Credentials
          </button>
        </div>`;
    } else if (ns.connected && !isAdmin) {
      nsHint = 'Your team\'s Nutshell connection is active. Nothing for you to set up — estimates and leads sync automatically through the company account.';
      nsAction = ''; // read-only for non-admins
    } else if (!ns.connected && isAdmin) {
      nsHint = 'Set this once on behalf of the whole team. You\'ll need the company\'s Nutshell login email + an API key from <strong>Nutshell → Setup → API Keys</strong>. Every Zorderz user will inherit this connection.';
      nsAction = `
        <div class="settings-row mt-4">
          <button id="zdz-authorize-ns-btn" class="btn btn-brand w-full">
            <i data-lucide="key-round"></i> Set Company Credentials
          </button>
        </div>`;
    } else {
      nsHint = '<em>Not yet connected.</em> Ask an administrator to configure Nutshell from their Settings view — once set, it works for everyone automatically.';
      nsAction = ''; // no button for non-admins
    }

    // ---- Connected Calendars block (v1.1.0 — scheduler plugin, per-user) ----
    // Rendered only when a scheduler plugin registers `calendars` via the
    // zdz_app_authorizations filter (typically a per-user, non-kiosk
    // connection). The button deep-links into the schedule widget's existing
    // connect modal via a neutral query var the scheduler plugin listens for.
    let calBlock = '';
    if (cal) {
      const calHint = cal.connected
        ? 'Your outside calendars (Google / Microsoft Exchange) are connected — the team scheduler treats their events as busy time. Only you see the details; teammates just see that you\'re busy.'
        : 'Connect your own Google or Microsoft (Exchange) calendar so the team scheduler knows when you\'re busy outside this app. Only you see event details — teammates just see busy time.';
      const calBtnLabel = cal.connected
        ? ('Manage Calendars' + (cal.count ? ' (' + cal.count + ')' : ''))
        : 'Connect a Calendar';
      const calBtnClass = cal.connected ? 'btn-outline' : 'btn-brand';
      calBlock = `
      <div class="zdz-auth-divider"></div>
      <div class="settings-row">
        <div class="sr-left"><i data-lucide="calendar-check"></i><span class="sr-label">Connected Calendars <span class="zdz-auth-scope">(your calendars)</span></span></div>
        <div class="sr-right">${badge(!!cal.connected)}</div>
      </div>
      <p class="zdz-auth-hint">${calHint}</p>
      <div class="settings-row mt-4">
        <button id="zdz-connect-cal-btn" class="btn ${calBtnClass} w-full">
          <i data-lucide="calendar-plus"></i> ${calBtnLabel}
        </button>
      </div>`;
    }

    rows.innerHTML = `
      <div class="settings-row">
        <div class="sr-left"><i data-lucide="link"></i><span class="sr-label">FreshBooks</span></div>
        <div class="sr-right">${badge(fb.connected)}</div>
      </div>
      <p class="zdz-auth-hint">${fbHint}</p>
      <div class="settings-row mt-4">
        <button id="zdz-authorize-fb-btn" class="btn btn-brand w-full" ${fbDisabled}>
          <i data-lucide="shield-check"></i> ${fbBtnLabel}
        </button>
      </div>

      <div class="zdz-auth-divider"></div>

      <div class="settings-row">
        <div class="sr-left"><i data-lucide="users"></i><span class="sr-label">Nutshell CRM <span class="zdz-auth-scope">(company account)</span></span></div>
        <div class="sr-right">${badge(ns.connected)}</div>
      </div>
      <p class="zdz-auth-hint">${nsHint}</p>
      ${nsAction}
      ${calBlock}
    `;

    const fbBtn = document.getElementById('zdz-authorize-fb-btn');
    if (fbBtn && !fbBtn.disabled) fbBtn.addEventListener('click', startFreshBooksAuthorize);

    // Only wire up the Nutshell button if it actually exists (admin paths only)
    const nsBtn = document.getElementById('zdz-authorize-ns-btn');
    if (nsBtn) nsBtn.addEventListener('click', () => openNutshellModal(ns.email || ''));

    // v1.1.0 — Connected Calendars: deep-link into the schedule widget's connect
    // modal (the scheduler plugin owns the OAuth flow; we just route to it via a
    // neutral namespaced query var the scheduler port must listen for).
    const calBtn = document.getElementById('zdz-connect-cal-btn');
    if (calBtn) calBtn.addEventListener('click', () => {
      window.location.href = window.location.origin + '/?zdz_connect_calendar=open';
    });

    refreshIcons();
  })
  .catch(() => {
    const rows = document.getElementById('zdz-app-auth-rows');
    if (rows) rows.innerHTML = '<div class="settings-row"><div class="sr-left"><span class="sr-label">Unavailable right now</span></div></div>';
  });
}

function escapeHtml(str) {
  return String(str).replace(/[&<>"']/g, function (c) {
    return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;','\'':'&#39;'})[c];
  });
}

// v1.6.2: zdzMapsUrl — the ONE shared map-URL builder for the
// whole platform. Replaces the drifting per-surface copies (this theme's
// quick-info card, the analytics chat address linkifier, the jobs dossier).
// Exposed as window.zdzMapsUrl so the app scripts reuse it instead of forking.
//
// Builds a device-appropriate deep link from a free-text address: an Apple UA
// (iPhone/iPad/iPod/Mac) opens Apple Maps, everyone else opens Google Maps.
//
//   opts.directions === true  -> a turn-by-turn route link (daddr / dir)
//   otherwise                 -> a search/pin link (q / search)
//
// SECURITY: the scheme is ALWAYS a hardcoded 'https://' baked into the provider
// bases, and the address is ALWAYS encodeURIComponent()-escaped — no prose
// scanning of the field, no way to inject a javascript:/data: scheme or break
// out of the query via a crafted address string. Callers still esc() the
// returned URL before putting it in an href.
//
// The maps PROVIDER/base-URLs are Identity-configurable — a
// business may run a different maps service — via the PHP filter
// `zdz_maps_providers` (ZDZ_Maps::providers()), surfaced to the client as
// window.zdzMapsProviders. Core ships a generic default per device family +
// intent, present right here so the helper works even if the config is absent.
function zdzMapsUrl(addr, opts) {
  var q = encodeURIComponent(String(addr == null ? '' : addr).trim());
  if (!q) { return ''; }
  opts = opts || {};
  var directions = !!opts.directions;
  var cfg = (typeof window !== 'undefined' && window.zdzMapsProviders) || {};
  var ua = (typeof navigator !== 'undefined' && navigator.userAgent) || '';
  var isApple = /iPhone|iPad|iPod|Macintosh/i.test(ua);
  var base;
  if (isApple) {
    base = directions
      ? (cfg.appleDirections  || 'https://maps.apple.com/?daddr=')
      : (cfg.appleSearch      || 'https://maps.apple.com/?q=');
  } else {
    base = directions
      ? (cfg.googleDirections || 'https://www.google.com/maps/dir/?api=1&destination=')
      : (cfg.googleSearch     || 'https://www.google.com/maps/search/?api=1&query=');
  }
  return base + q;
}

// Start the FreshBooks OAuth flow from the front-end.
function startFreshBooksAuthorize() {
  const btn = document.getElementById('zdz-authorize-fb-btn');
  if (btn) { btn.disabled = true; btn.classList.add('is-loading'); }
  showToast('Opening FreshBooks…');
  fetch(zdzData.apiUrl + 'fb-auth-start', {
    headers: { 'X-WP-Nonce': zdzData.nonce },
    credentials: 'same-origin'
  })
  .then(r => r.json().then(j => ({ ok: r.ok, body: j })))
  .then(({ ok, body }) => {
    if (!ok || !body || !body.success) {
      const msg = (body && body.message) ? body.message : 'Unable to start FreshBooks authorization.';
      if (btn) { btn.disabled = false; btn.classList.remove('is-loading'); }
      showToast(msg, 6000);
      return;
    }
    window.location.href = body.data.auth_url;
  })
  .catch(() => {
    if (btn) { btn.disabled = false; btn.classList.remove('is-loading'); }
    showToast('Network error. Please try again.', 5000);
  });
}

// Nutshell authorize modal — admin-only. Sets the COMPANY credentials.
function openNutshellModal(currentEmail) {
  closeNutshellModal(); // idempotent

  const overlay = document.createElement('div');
  overlay.id = 'zdz-ns-overlay';
  overlay.className = 'zdz-ns-overlay';
  overlay.innerHTML = `
    <div class="zdz-ns-modal" role="dialog" aria-modal="true" aria-labelledby="zdz-ns-title">
      <div class="zdz-ns-header">
        <h3 id="zdz-ns-title"><i data-lucide="key-round"></i> Nutshell — Company Credentials</h3>
        <button type="button" class="btn-icon" id="zdz-ns-close" aria-label="Close">
          <i data-lucide="x" style="width:20px;height:20px"></i>
        </button>
      </div>
      <div class="zdz-ns-body">
        <p class="zdz-ns-hint" style="margin-bottom:4px">
          These credentials are shared by <strong>every Zorderz user</strong>. Paste the
          company Nutshell login + API key once — the rest of the team will not need to do anything.
        </p>
        <label class="zdz-ns-label">Nutshell Login Email
          <input type="email" id="zdz-ns-email" autocomplete="email"
                 value="${escapeHtml(currentEmail || '')}"
                 placeholder="you@company.com" />
        </label>
        <label class="zdz-ns-label">API Key
          <input type="password" id="zdz-ns-key" autocomplete="off"
                 placeholder="Paste your Nutshell API key" />
        </label>
        <p class="zdz-ns-hint">
          Find the key at <strong>Nutshell → Setup → API Keys</strong>. Stored encrypted
          and cascaded to all Zorderz apps (Estimates, Surveys, Leads, Analytics).
        </p>
        <div class="zdz-ns-error" id="zdz-ns-error" style="display:none"></div>
      </div>
      <div class="zdz-ns-footer">
        <button type="button" class="btn btn-outline" id="zdz-ns-cancel">Cancel</button>
        <button type="button" class="btn btn-brand"   id="zdz-ns-save">
          <i data-lucide="save"></i> Save &amp; Connect
        </button>
      </div>
    </div>
  `;
  document.body.appendChild(overlay);
  refreshIcons();
  setTimeout(() => { overlay.classList.add('show'); }, 10);

  const close = () => closeNutshellModal();
  overlay.addEventListener('click', e => { if (e.target === overlay) close(); });
  document.getElementById('zdz-ns-close').addEventListener('click', close);
  document.getElementById('zdz-ns-cancel').addEventListener('click', close);
  document.getElementById('zdz-ns-save').addEventListener('click', saveNutshellCredentials);

  // Focus handling
  const emailInput = document.getElementById('zdz-ns-email');
  const keyInput   = document.getElementById('zdz-ns-key');
  setTimeout(() => { (currentEmail ? keyInput : emailInput).focus(); }, 60);

  // Enter submits
  [emailInput, keyInput].forEach(el => {
    el.addEventListener('keydown', e => {
      if (e.key === 'Enter') { e.preventDefault(); saveNutshellCredentials(); }
      if (e.key === 'Escape') { close(); }
    });
  });
}

function closeNutshellModal() {
  const o = document.getElementById('zdz-ns-overlay');
  if (o) o.remove();
}

function saveNutshellCredentials() {
  const email = (document.getElementById('zdz-ns-email') || {}).value || '';
  const key   = (document.getElementById('zdz-ns-key')   || {}).value || '';
  const err   = document.getElementById('zdz-ns-error');
  const btn   = document.getElementById('zdz-ns-save');

  const show = msg => { if (err) { err.textContent = msg; err.style.display = 'block'; } };
  if (err) { err.style.display = 'none'; err.textContent = ''; }

  if (!email || email.indexOf('@') < 1) { show('Please enter a valid email address.'); return; }
  if (!key || key.length < 16) { show('That API key looks too short. Double-check the value from Nutshell.'); return; }

  if (btn) { btn.disabled = true; btn.classList.add('is-loading'); }

  fetch(zdzData.apiUrl + 'ns-auth-save', {
    method: 'POST',
    headers: {
      'X-WP-Nonce': zdzData.nonce,
      'Content-Type': 'application/json'
    },
    credentials: 'same-origin',
    body: JSON.stringify({ email: email, api_key: key })
  })
  .then(r => r.json().then(j => ({ ok: r.ok, body: j })))
  .then(({ ok, body }) => {
    if (btn) { btn.disabled = false; btn.classList.remove('is-loading'); }
    if (!ok || !body || !body.success) {
      show((body && body.message) ? body.message : 'Unable to save Nutshell credentials.');
      return;
    }
    closeNutshellModal();
    showToast('✓ Company Nutshell connection saved — all users inherit this.');
    renderAppAuthorizations(); // refresh status badge
  })
  .catch(() => {
    if (btn) { btn.disabled = false; btn.classList.remove('is-loading'); }
    show('Network error. Please try again.');
  });
}

// ---- COMMAND PALETTE ----
// ---- COMMAND PALETTE ----
// The older simple app-search version has been removed.
// The enhanced version with the assistant routing and fuzzy match is
// defined below (see the second initCommandPalette block).

// ---- APP VIEWPORT CONTROLS ----
function initAppViewport() {
  // Back button in the app header
  const backBtn = document.getElementById('app-back');
  if (backBtn) {
    backBtn.addEventListener('click', () => {
      if (window.Bridge && Bridge.currentApp) {
        // Go back in history so popstate fires cleanly
        history.back();
      }
    });
  }

  // Browser / phone back button closes the active app
  window.addEventListener('popstate', (e) => {
    // a document layer (Bridge.openDoc) sits above the app — Back closes
    // only that layer. The pop that closeDoc() itself triggers is swallowed here,
    // so it never closes the app underneath.
    // closes queued in one tap (a sheet and the sheet under it, or a sheet and a document) each
    // count one skipped pop; a single yes/no flag swallowed only the first, and the second closed the app.
    if (window.Bridge && Bridge._skipPops > 0) { Bridge._skipPops--; if (window.ZDZ && ZDZ.ui && ZDZ.ui._afterPop) ZDZ.ui._afterPop(); return; }
    if (window.Bridge && Bridge._skipNextPop) { Bridge._skipNextPop = false; return; }
    // a kit sheet (ZDZ.ui.sheet) is the topmost layer — Back closes it, nothing under it.
    if (window.ZDZ && ZDZ.ui && ZDZ.ui._popLayer && ZDZ.ui._popLayer()) return;
    if (window.Bridge && Bridge.docOpen) { Bridge.closeDoc(true); return; }
    if (window.Bridge && Bridge.currentApp) {
      Bridge.closeApp(true); // true = the browser already went back (closeApp spends the entry otherwise)
    }
  });
}

// ---- PULL-TO-REFRESH ----
// On iOS the browser chrome disappears on scroll, locking users out of the
// browser's refresh bar. The body has overscroll-behavior-y:none which kills
// the browser's native PTR. This adds a pull-down gesture at the top of any
// sub-view that triggers a full page reload.
//
// Does NOT conflict with plugin-level PTR (Analytics, Messaging) because those attach
// to their own internal scroll containers (.zana-messages, #zim-w-messages).
// The theme PTR only fires when the .sub-view itself is at scrollTop 0.
function initPullToRefresh() {
  const viewMain = document.getElementById('view-main');
  if (!viewMain) return;

  let startY = 0;
  let startX = 0;
  let pulling = false;
  let indicator = null;
  let startTime = 0;
  const threshold = 45;

  viewMain.addEventListener('touchstart', function (e) {
    // an app open as a page is inside #view-main too; its own body scrolls, never this pull.
    if (document.body.classList.contains('zdz-focus-open')) return;
    // Skip interactive elements — long-pressing text, tapping inputs, etc.
    // Without this, text selection triggers a refresh.
    const tag = (e.target.tagName || '').toLowerCase();
    if (tag === 'textarea' || tag === 'input' || tag === 'select' || tag === 'button') return;
    if (e.target.isContentEditable) return;
    // Also skip if inside a contenteditable ancestor or a form control wrapper
    if (e.target.closest('textarea, input, select, button, [contenteditable="true"]')) return;

    // Find the currently active sub-view
    const active = viewMain.querySelector('.sub-view.active');
    if (!active) return;

    // Only activate when the sub-view is scrolled to its very top
    if (active.scrollTop <= 0) {
      // Don't activate if the touch originated inside a nested scrollable
      // that has its own PTR (e.g., .zana-messages, .zim-w-messages).
      // Those elements handle their own pull-to-refresh.
      const nested = e.target.closest('.zana-messages, .zim-w-messages, #zim-w-messages');
      if (nested && nested.scrollTop <= 0) return; // let the plugin PTR handle it
      if (nested) return; // nested scroller not at top — let it scroll normally

      startY = e.touches[0].clientY;
      startX = e.touches[0].clientX;
      startTime = Date.now();
      pulling = true;
    }
  }, { passive: true });

  viewMain.addEventListener('touchmove', function (e) {
    if (!pulling) return;
    const active = viewMain.querySelector('.sub-view.active');
    if (!active || active.scrollTop > 0) { pulling = false; return; }

    const deltaY = e.touches[0].clientY - startY;
    const deltaX = Math.abs(e.touches[0].clientX - startX);

    // If moving more horizontally than vertically, this is a swipe/selection — abort.
    if (deltaX > Math.abs(deltaY) + 5) { pulling = false; return; }

    // If touch has been held > 300ms without significant movement, it's a
    // long-press (text selection). Cancel PTR.
    if (deltaY < 15 && Date.now() - startTime > 300) { pulling = false; return; }

    if (deltaY > 15) {
      if (!indicator) {
        indicator = document.createElement('div');
        indicator.className = 'zdz-pull-indicator';
        indicator.textContent = '\u2193 Pull to refresh';
        active.insertBefore(indicator, active.firstChild);
      }

      const progress = Math.min(deltaY / threshold, 1);
      indicator.style.height = Math.min(deltaY * 0.5, 44) + 'px';
      indicator.style.opacity = progress;
      indicator.textContent = progress >= 1 ? '\u21BB Release to refresh' : '\u2193 Pull to refresh';
    }
  }, { passive: true });

  viewMain.addEventListener('touchend', function () {
    if (!pulling) return;
    pulling = false;

    if (indicator) {
      const wasReady = indicator.textContent.indexOf('Release') !== -1;
      indicator.remove();
      indicator = null;

      if (wasReady) {
        // Full page reload — the theme-level refresh re-fetches everything.
        showToast('Refreshing…');
        setTimeout(function () { window.location.reload(); }, 150);
      }
    }
  }, { passive: true });
}

// ---- LONG PRESS SUNLIGHT ----
function initLongPressSunlight() {
  // Long-press the Dashboard nav item to toggle sunlight mode
  const dashBtn = document.querySelector('.ni[data-view="sv-dash"]');
  if (!dashBtn) return;
  let timer = null;
  dashBtn.addEventListener('touchstart', () => {
    timer = setTimeout(() => {
      const newTheme = state.theme === 'sunlight' ? 'system' : 'sunlight';
      applyTheme(newTheme);
      renderSettings();
      showToast(newTheme === 'sunlight' ? '☀ Sunlight Mode ON' : '🌙 Sunlight Mode OFF');
      Haptics.success();
    }, 600);
  }, { passive: true });
  dashBtn.addEventListener('touchend', () => clearTimeout(timer));
  dashBtn.addEventListener('touchmove', () => clearTimeout(timer));
}

// ---- PREFETCH TOP APPS ----
function prefetchTopApps() {
  const apps = getVisibleApps();
  const pinned = PINNED[state.role] || [];
  const topApps = pinned.slice(0, 2).map(id => apps.find(a => a.id === id)).filter(Boolean);
  topApps.forEach(app => {
    if (app.bridge_type === 'iframe' && app.url) {
      const link = document.createElement('link');
      link.rel = 'prefetch';
      link.href = app.url;
      document.head.appendChild(link);
    }
  });
}

// ---- T16: Human-readable time-ago helper for KPI freshness ----
function formatTimeAgo(ts) {
  if (!ts) return '';
  const diff = (Date.now() / 1000) - ts;
  if (diff < 60) return 'just now';
  if (diff < 3600) return Math.floor(diff / 60) + 'm ago';
  if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
  return new Date(ts * 1000).toLocaleDateString();
}

// ---- A4: APP DOCK (icon grid above KPI cards) ----
function renderAppDock() {
  var dock = document.getElementById('app-dock');
  if (!dock) return;
  var allApps = getVisibleApps();
  if (!allApps.length) { dock.style.display = 'none'; return; }
  if (allApps.length >= 7) dock.classList.add('dock-many');
  else dock.classList.remove('dock-many');
  var last = allApps.length - 1;
  dock.innerHTML = allApps.map(function (a, i) {
    var pos = i === 0 ? 'first' : i === last ? 'last' : '';
    return '<button class="dock-app" data-app-id="' + a.id + '" data-idx="' + i + '"' + (pos ? ' data-pos="' + pos + '"' : '') + ' aria-label="' + escapeHtml(zdzAppSpoken(a).replace(/^Open /, '')) + '" title="' + zdzNameHtml(a.nm) + '">' +
      '<div class="dock-icon" ' + zdzPlate(a) + '><i data-lucide="' + a.icon + '"></i></div>' +
      '<span class="dock-label' + longLabelClass(zdzAppShort(a)) + '">' + zdzNameHtml(zdzAppShort(a)) + '</span>' +
      '<div class="dock-reorder-arrows">' +
        '<span class="dock-arr dock-arr-left" data-dir="up" role="button" aria-label="Move left"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg></span>' +
        '<span class="dock-arr dock-arr-right" data-dir="down" role="button" aria-label="Move right"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg></span>' +
      '</div>' +
    '</button>';
  }).join('');
  refreshIcons();
  initDockReorder(dock);
}

/* ── Unified Reorder System (dock + sticky bar) ──────── */
var _zdzEditMode = false;

function getDockOrder(container) {
  var btns = container.querySelectorAll('[data-app-id]');
  var order = [];
  btns.forEach(function(b) { if (b.dataset.appId) order.push(b.dataset.appId); });
  return order;
}

/** Update data-idx and data-pos on all items in a container */
function reindexItems(container) {
  var items = container.querySelectorAll('[data-app-id]');
  var last = items.length - 1;
  items.forEach(function(b, i) {
    b.dataset.idx = i;
    if (i === 0) b.dataset.pos = 'first';
    else if (i === last) b.dataset.pos = 'last';
    else delete b.dataset.pos;
  });
}

/** Swap two adjacent items with animation */
function swapItems(container, idx, dir) {
  var items = Array.from(container.querySelectorAll('[data-app-id]'));
  var targetIdx = dir === 'up' ? idx - 1 : idx + 1;
  if (targetIdx < 0 || targetIdx >= items.length) return;
  var el = items[idx];
  var target = items[targetIdx];
  el.classList.add('dock-swapping');
  target.classList.add('dock-swapping');
  if (dir === 'up') {
    container.insertBefore(el, target);
  } else {
    container.insertBefore(target, el);
  }
  reindexItems(container);
  Haptics.tap();
  setTimeout(function() {
    el.classList.remove('dock-swapping');
    target.classList.remove('dock-swapping');
  }, 200);
}

/** After a swap in one container, reorder the other to match */
function syncContainers(sourceContainer) {
  var order = getDockOrder(sourceContainer);
  var dock = document.getElementById('app-dock');
  var strip = document.querySelector('.sticky-app-strip');
  var target = (sourceContainer === dock) ? strip : dock;
  if (!target) return;
  order.forEach(function(appId) {
    var node = target.querySelector('[data-app-id="' + appId + '"]');
    if (node) target.appendChild(node);
  });
  reindexItems(target);
}

function enterAppEditMode() {
  if (_zdzEditMode) return;
  _zdzEditMode = true;
  Haptics.success();
  var dock = document.getElementById('app-dock');
  var stickyWrap = document.getElementById('dash-sticky');
  var strip = stickyWrap ? stickyWrap.querySelector('.sticky-app-strip') : null;
  if (dock) dock.classList.add('dock-edit-mode');
  if (strip) strip.classList.add('dock-edit-mode');
  // Pin sticky bar visible so user can reorder there too
  if (stickyWrap) stickyWrap.classList.add('visible', 'edit-pinned');
  // Add compact Done button inside sticky bar (always reachable)
  if (stickyWrap && !stickyWrap.querySelector('.dock-edit-done-sticky')) {
    var stickyDone = document.createElement('button');
    stickyDone.className = 'dock-edit-done dock-edit-done-sticky';
    stickyDone.textContent = 'Done ✓';
    stickyDone.addEventListener('click', function(e) { e.stopPropagation(); exitAppEditMode(); });
    stickyDone.addEventListener('touchend', function(e) { e.preventDefault(); e.stopPropagation(); exitAppEditMode(); });
    stickyWrap.appendChild(stickyDone);
  }
  // Also add Done button below dock for when user is scrolled to top
  if (dock && !dock.parentNode.querySelector('.dock-edit-done:not(.dock-edit-done-sticky)')) {
    var done = document.createElement('button');
    done.className = 'dock-edit-done';
    done.textContent = 'Done Reordering';
    done.addEventListener('click', function(e) { e.stopPropagation(); exitAppEditMode(); });
    dock.parentNode.insertBefore(done, dock.nextSibling);
  }
  // Tap outside dock/sticky → exit edit mode (pointer + touch for iOS)
  setTimeout(function() {
    document.addEventListener('pointerdown', _zdzEditOutsideHandler, true);
    document.addEventListener('touchend', _zdzEditOutsideTouchHandler, true);
  }, 250);
}

function _zdzEditOutsideHandler(e) {
  if (!_zdzEditMode) return;
  var dock = document.getElementById('app-dock');
  var stickyWrap = document.getElementById('dash-sticky');
  if ((dock && dock.contains(e.target)) ||
      (stickyWrap && stickyWrap.contains(e.target)) ||
      e.target.closest('.dock-edit-done')) return;
  exitAppEditMode();
}

function _zdzEditOutsideTouchHandler(e) {
  if (!_zdzEditMode) return;
  // Use changedTouches for touchend
  var touch = e.changedTouches ? e.changedTouches[0] : null;
  if (!touch) return;
  var target = document.elementFromPoint(touch.clientX, touch.clientY);
  if (!target) return;
  var dock = document.getElementById('app-dock');
  var stickyWrap = document.getElementById('dash-sticky');
  if ((dock && dock.contains(target)) ||
      (stickyWrap && stickyWrap.contains(target)) ||
      target.closest('.dock-edit-done')) return;
  exitAppEditMode();
}

function exitAppEditMode() {
  if (!_zdzEditMode) return;
  _zdzEditMode = false;
  document.removeEventListener('pointerdown', _zdzEditOutsideHandler, true);
  document.removeEventListener('touchend', _zdzEditOutsideTouchHandler, true);
  var dock = document.getElementById('app-dock');
  var stickyWrap = document.getElementById('dash-sticky');
  var strip = stickyWrap ? stickyWrap.querySelector('.sticky-app-strip') : null;
  if (dock) dock.classList.remove('dock-edit-mode');
  if (strip) strip.classList.remove('dock-edit-mode');
  // Remove pinned state — let the scroll handler re-evaluate visibility
  if (stickyWrap) {
    stickyWrap.classList.remove('edit-pinned');
    stickyWrap.classList.remove('visible');
  }
  document.querySelectorAll('.dock-edit-done').forEach(function(d) { d.remove(); });
  // Save order from dock (canonical)
  if (dock) {
    var order = getDockOrder(dock);
    saveAppOrder(order);
  }
  showToast('App order saved ✓');
  // Rebuild sticky bar to match new order
  initStickyAppBar();
}

/** Attach long-press + chevron handlers to a container */
function initReorderHandlers(container, itemSelector, arrowSelector) {
  var longPressTimer = null;
  var startX = 0, startY = 0;

  container.addEventListener('pointerdown', function(e) {
    var btn = e.target.closest(itemSelector);
    if (!btn || e.target.closest(arrowSelector)) return;
    startX = e.clientX; startY = e.clientY;
    longPressTimer = setTimeout(function() {
      longPressTimer = null;
      enterAppEditMode();
    }, 500);
  });
  container.addEventListener('pointermove', function(e) {
    if (longPressTimer) {
      var dx = e.clientX - startX, dy = e.clientY - startY;
      if (Math.sqrt(dx*dx + dy*dy) > 10) { clearTimeout(longPressTimer); longPressTimer = null; }
    }
  });
  container.addEventListener('pointerup', function() {
    if (longPressTimer) { clearTimeout(longPressTimer); longPressTimer = null; }
  });
  container.addEventListener('pointercancel', function() {
    if (longPressTimer) { clearTimeout(longPressTimer); longPressTimer = null; }
  });

  container.addEventListener('click', function(e) {
    var arr = e.target.closest(arrowSelector);
    if (arr && _zdzEditMode) {
      e.preventDefault(); e.stopPropagation();
      var btn = arr.closest(itemSelector);
      var idx = parseInt(btn.dataset.idx, 10);
      swapItems(container, idx, arr.dataset.dir);
      syncContainers(container);
      return;
    }
    if (_zdzEditMode) { e.preventDefault(); e.stopPropagation(); return; }
    var appBtn = e.target.closest(itemSelector);
    if (appBtn && appBtn.dataset.appId) { openApp(appBtn.dataset.appId); }
  }, true);
}

function initDockReorder(dock) {
  initReorderHandlers(dock, '.dock-app', '.dock-arr');
}

// ---- T4 / STICKY APP BAR ----
function initStickyAppBar() {
  var dock = document.getElementById('app-dock');
  var sticky = document.getElementById('dash-sticky');
  var dashTop = document.getElementById('dash-top');
  if (!dock || !sticky) return;

  // Measure dash-top height for positioning
  function measureDashTop() {
    if (dashTop) {
      var h = dashTop.getBoundingClientRect().height;
      document.documentElement.style.setProperty('--dash-top-h', Math.round(h) + 'px');
    }
    // Measure the sticky icon bar height so widget headers
    // can stick below it instead of behind it. Set to 0 when hidden.
    if (sticky) {
      if (sticky.classList.contains('visible')) {
        var sh = sticky.getBoundingClientRect().height;
        document.documentElement.style.setProperty('--dash-sticky-h', Math.round(sh) + 'px');
      } else {
        document.documentElement.style.setProperty('--dash-sticky-h', '0px');
      }
    }
  }
  measureDashTop();
  var resizeTimer;
  window.addEventListener('resize', function () {
    clearTimeout(resizeTimer);
    resizeTimer = setTimeout(measureDashTop, 150);
  });

  // with the focus view home is short and the launcher sits under the ask bar, so the strip
  // that chased the dock down a long page has nothing to do.
  if (document.body.classList.contains('zdz-focus-home')) {
    sticky.classList.remove('visible');
    document.documentElement.style.setProperty('--dash-sticky-h', '0px');
    return;
  }

  var allApps = getVisibleApps();
  if (!allApps.length) { sticky.style.display = 'none'; return; }
  var last = allApps.length - 1;

  var strip = document.createElement('div');
  strip.className = 'sticky-app-strip';
  strip.innerHTML = allApps.map(function (a, i) {
    var pos = i === 0 ? 'first' : i === last ? 'last' : '';
    return '<button class="sticky-app-btn" data-app-id="' + a.id + '" data-idx="' + i + '"' + (pos ? ' data-pos="' + pos + '"' : '') + ' aria-label="' + escapeHtml(zdzAppSpoken(a).replace(/^Open /, '')) + '" title="' + zdzNameHtml(a.nm) + '">' +
      '<div class="sticky-app-icon" ' + zdzPlate(a) + '><i data-lucide="' + a.icon + '"></i></div>' +
      '<span class="sticky-app-label">' + zdzNameHtml(zdzAppShort(a)) + '</span>' +
      '<div class="sticky-reorder-arrows">' +
        '<span class="sticky-arr sticky-arr-left" data-dir="up" role="button" aria-label="Move left"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg></span>' +
        '<span class="sticky-arr sticky-arr-right" data-dir="down" role="button" aria-label="Move right"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg></span>' +
      '</div>' +
    '</button>';
  }).join('');
  sticky.innerHTML = '';
  sticky.appendChild(strip);
  refreshIcons();

  // Reorder handlers for sticky bar
  initReorderHandlers(strip, '.sticky-app-btn', '.sticky-arr');

  // Ensure sticky starts hidden, disconnect old observer/listener
  sticky.classList.remove('visible');
  if (window._zdzStickyObs) { try { window._zdzStickyObs.disconnect(); } catch(e){} }
  if (window._zdzStickyScroll) {
    var oldRoot = document.getElementById('sv-dash') || dock.closest('.sub-view');
    if (oldRoot) oldRoot.removeEventListener('scroll', window._zdzStickyScroll);
  }
  // Remove old sentinel if it exists
  var oldSentinel = document.getElementById('dock-sentinel');
  if (oldSentinel) oldSentinel.remove();

  var scrollRoot = document.getElementById('sv-dash') || dock.closest('.sub-view');
  if (scrollRoot) {
    // Calculate threshold: show sticky bar once 2 rows of the dock grid are scrolled
    // behind the fixed header. We measure the 4th dock-app child (end of row 2 on mobile)
    // or the 6th (end of row 2 on 3-col desktop). Fall back to a fixed pixel value.
    function _getStickyThreshold() {
      var apps = dock.querySelectorAll('.dock-app');
      // On a 2-col mobile grid, the 4th app ends row 2.
      // On a 3-col desktop grid, the 6th app ends row 2.
      // We detect by comparing the offsetTop of the 3rd vs 1st app.
      var cols = 2;
      if (apps.length >= 3 && apps[2].offsetTop === apps[0].offsetTop) cols = 3;
      var endOfRow2 = cols * 2; // 4 for 2-col, 6 for 3-col
      var target = apps[Math.min(endOfRow2 - 1, apps.length - 1)];
      if (target) {
        // We want the sticky bar to appear when this app has scrolled ABOVE the header.
        // Get bottom edge of target relative to the dock's top.
        return target.offsetTop + target.offsetHeight;
      }
      return 300; // fallback: ~300px
    }

    function _onDashScroll() {
      if (_zdzEditMode) return;

      // Re-measure dash-top height on EVERY scroll tick.
      // The old approach measured once on init, but the height can change
      // after font loading, icon rendering, or compact mode transitions.
      // A stale --dash-top-h causes the sticky bar to misposition.
      if (dashTop) {
        var currentH = Math.round(dashTop.getBoundingClientRect().height);
        var storedH = parseInt(getComputedStyle(document.documentElement).getPropertyValue('--dash-top-h')) || 0;
        if (currentH !== storedH) {
          document.documentElement.style.setProperty('--dash-top-h', currentH + 'px');
        }
      }

      // How far the dock's top has scrolled above the scroll container's visible area
      var dockRect = dock.getBoundingClientRect();
      var rootRect = scrollRoot.getBoundingClientRect();
      // Distance the dock top has moved above the scroll container top (header area)
      var scrolledPast = rootRect.top - dockRect.top;
      var threshold = _getStickyThreshold();

      // Compact search bar — triggers at 40% of the sticky threshold
      // so the greeting is already hidden and search is small BEFORE the
      // icon strip slides in. --dash-top-h is re-measured continuously at
      // the top of this handler, so no explicit remeasurement needed here.
      var compactThreshold = Math.max(threshold * 0.4, 40);
      if (dashTop) {
        if (scrolledPast >= compactThreshold) {
          if (!dashTop.classList.contains('dash-compact')) {
            dashTop.classList.add('dash-compact');
          }
        } else {
          if (dashTop.classList.contains('dash-compact')) {
            dashTop.classList.remove('dash-compact');
          }
        }
      }

      // the strip follows the scroll instead of popping in.
      // Over the last ~70px before the threshold it moves in by the same amount (CSS: .zdz-strip-partial,
      // a fade only under Reduce Motion); at the threshold the usual .visible state takes over.
      var _ramp = Math.max(60, Math.round((sticky.offsetHeight || 56) * 1.25));
      var _p = (scrolledPast - (threshold - _ramp)) / _ramp;
      _p = _p < 0 ? 0 : (_p > 1 ? 1 : _p);
      if (_p > 0 && _p < 1 && !sticky.classList.contains('visible')) {
        sticky.style.setProperty('--strip-p', _p.toFixed(3));
        sticky.classList.add('zdz-strip-partial');
      } else if (sticky.classList.contains('zdz-strip-partial')) {
        sticky.classList.remove('zdz-strip-partial');
        sticky.style.removeProperty('--strip-p');
      }

      if (scrolledPast >= threshold) {
        if (!sticky.classList.contains('visible')) {
          sticky.classList.add('visible');
          // Mark dash-top so box-shadow gap cover only activates
          // when BOTH compact AND sticky are active
          if (dashTop) dashTop.classList.add('dash-sticky-on');
          // Update --dash-sticky-h when icon bar appears
          requestAnimationFrame(function() {
            var sh = sticky.getBoundingClientRect().height;
            document.documentElement.style.setProperty('--dash-sticky-h', Math.round(sh) + 'px');
          });
        }
      } else {
        if (sticky.classList.contains('visible')) {
          sticky.classList.remove('visible');
          if (dashTop) dashTop.classList.remove('dash-sticky-on');
          // Reset --dash-sticky-h when icon bar disappears
          document.documentElement.style.setProperty('--dash-sticky-h', '0px');
        }
      }
    }
    window._zdzStickyScroll = _onDashScroll;
    scrollRoot.addEventListener('scroll', _onDashScroll, { passive: true });

    // Force scrollLeft=0 on every scroll event. iOS WebKit
    // sometimes allows a few pixels of horizontal scroll even with
    // overflow-x:hidden when inertia scrolling or rubber-banding occurs.
    // This immediately resets any horizontal displacement.
    scrollRoot.addEventListener('scroll', function() {
      if (scrollRoot.scrollLeft !== 0) {
        scrollRoot.scrollLeft = 0;
      }
    }, { passive: true });
    // Run once after layout settles to set initial state
    requestAnimationFrame(function() {
      requestAnimationFrame(_onDashScroll);
    });
  }
}

// ---- A8: FUZZY NAME / APP MATCHING ----
function fuzzyMatch(needle, haystack) {
  // Lowercase, strip non-alpha, and compare
  var n = needle.toLowerCase().replace(/[^a-z0-9]/g, '');
  var h = haystack.toLowerCase().replace(/[^a-z0-9]/g, '');
  if (!n) return 0;
  if (h === n) return 1.0;       // exact match
  if (h.includes(n)) return 0.9; // substring match
  // Check if needle matches after common letter omissions (silent letters, typos)
  // E.g. "jonathon" matches "jonathan" (one vowel off)
  var ni = 0;
  var matched = 0;
  for (var hi = 0; hi < h.length && ni < n.length; hi++) {
    if (h[hi] === n[ni]) { matched++; ni++; }
  }
  var score = ni === n.length ? (matched / Math.max(h.length, n.length)) : 0;
  // Bonus if all chars were found in sequence
  if (ni === n.length && matched === n.length) score = Math.max(score, 0.7);
  return score;
}

// ---- INLINE ASK FIELD (type directly; results drop down beneath) ----
// Replaces the pop-up command-palette overlay. The ask field lives inline in the
// dashboard header (#dash-ask); typing renders results into #cmd-results, shown as
// a dropdown. Empty field → dropdown hidden (just the field). openCmdPalette /
// closeCmdPalette are kept as compatibility shims (other code + inline onclicks
// call them) but now just focus the field / hide the dropdown — no overlay.
function initCommandPalette() {
  var field = document.getElementById('dash-ask');
  var input = document.getElementById('cmd-input');
  var results = document.getElementById('cmd-results');
  if (!input || !results) return;

  // Analytical query detection keywords
  var ANALYTICS_WORDS = ['revenue','invoice','estimate','how many','how much',
    'total','this month','this week','today','ytd','mtd','compare','trend',
    'customer','lead','survey','paid','billing','sales','job','average',
    'last month','last week','last year','yoy','growth','decline'];

  function showResults() {
    results.classList.add('show');
    if (input.setAttribute) input.setAttribute('aria-expanded', 'true');
  }
  function hideResults() {
    results.classList.remove('show');
    if (input.setAttribute) input.setAttribute('aria-expanded', 'false');
  }

  // Compatibility shims for existing callers / inline onclicks.
  window.openCmdPalette = function () { input.focus(); };
  window.closeCmdPalette = function () {
    hideResults();
    // Clearing keeps the dashboard tidy after an action fires.
    input.value = '';
  };

  // Render-on-type: show the dropdown only when there's a query.
  input.addEventListener('input', function () {
    var q = input.value;
    renderCmdResults(q);
    if (q && q.trim()) { showResults(); } else { hideResults(); }
  });

  // Re-open the dropdown on focus if there's already text.
  input.addEventListener('focus', function () {
    if (input.value && input.value.trim()) { renderCmdResults(input.value); showResults(); }
  });

  input.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') { hideResults(); input.blur(); }
    if (e.key === 'Enter') {
      // a question with a quick answer is answered right here; Enter never opens the chat over it.
      if (typeof zdzQaEnter === 'function' && zdzQaEnter(input.value, results)) { e.preventDefault(); return; }
      var first = results.querySelector('.cmd-item');
      if (first) first.click();
    }
  });

  // Click outside the ask field closes the dropdown (without clearing the text).
  document.addEventListener('click', function (e) {
    if (field && !field.contains(e.target)) { hideResults(); }
  });

  // Ctrl/Cmd-K focuses the inline field; Escape closes the dropdown.
  document.addEventListener('keydown', function (e) {
    if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
      e.preventDefault();
      input.focus();
    }
    if (e.key === 'Escape' && results.classList.contains('show')) {
      hideResults();
    }
  });

  function isAnalyticalQuery(q) {
    var lower = q.toLowerCase();
    return ANALYTICS_WORDS.some(function (w) { return lower.includes(w); });
  }

  // (cross-app orchestrator): detect a customer-document LOOKUP query
  // for palette LABELING only. Mirrors the bot's two-signal test (RULE TT2):
  // a lookup needs a named person AND retrieval framing, and must NOT be an
  // aggregate/analytic question. Returns the extracted customer name (string)
  // to show on the row, or null if it's not a lookup. This never changes the
  // routing — the query still goes to the assistant, which makes the real call.
  function detectLookupIntent(raw) {
    if (!raw) return null;
    var q = raw.trim();
    var lower = q.toLowerCase();

    // Aggregate / analytic framing wins — these are NOT lookups even with a noun.
    if (/\b(how many|how much|total|average|avg|count|number of|by (salesperson|rep|city|source|product|type|month)|this (month|quarter|year|week)|last (month|quarter|year|week)|ytd|mtd|qtd|breakdown|compare|trend|ranking|top \d)\b/.test(lower)) {
      return null;
    }

    var nouns = '(?:estimate|estimates|quote|quotes|bid|bids|invoice|invoices|bill|bills|job|jobs|work|paperwork|account)';

    // Pattern A: "<noun> for <Name>"  e.g. "estimate for Sam Rivera"
    var m = q.match(new RegExp('\\b' + nouns + '\\s+for\\s+([A-Za-z][\\w.\\-]*(?:\\s+[A-Za-z][\\w.\\-]*){0,3})$', 'i'));
    if (m && m[1]) return cleanName(m[1]);

    // Pattern B: "pull up / show / find / open / look up <Name>'s <noun>"
    m = q.match(new RegExp('\\b(?:pull up|pull|show me|show|find|open|look up|lookup|get)\\s+([A-Za-z][\\w.\\-]*(?:\\s+[A-Za-z][\\w.\\-]*){0,3})(?:\'s|s\')\\s+' + nouns, 'i'));
    if (m && m[1]) return cleanName(m[1]);

    // Pattern C: "<Name>'s <noun>"  e.g. "Rivera's estimate", "Riley's jobs"
    m = q.match(new RegExp('^([A-Za-z][\\w.\\-]*(?:\\s+[A-Za-z][\\w.\\-]*){0,3})(?:\'s|s\')\\s+' + nouns + '\\b', 'i'));
    if (m && m[1]) return cleanName(m[1]);

    // Pattern D: "show me / pull up / find the <Name> <noun>"  e.g. "show me the Rivera estimate"
    m = q.match(new RegExp('\\b(?:pull up|show me|show|find|open|look up|get)\\s+(?:the\\s+)?([A-Za-z][\\w.\\-]*(?:\\s+[A-Za-z][\\w.\\-]*){0,2})\\s+' + nouns + '\\b', 'i'));
    if (m && m[1] && !/^(my|our|all|any|recent|open|last|this)$/i.test(m[1])) return cleanName(m[1]);

    // Pattern E: "what's on <Name>'s <noun>"  e.g. "what's on Riley's quote?"
    m = q.match(new RegExp('^\\s*what(?:\'s|s| is| are)\\s+on\\s+([A-Za-z][\\w.\\-]*(?:\\s+[A-Za-z][\\w.\\-]*){0,3})(?:\'s|s\')\\s+' + nouns + '\\b', 'i'));
    if (m && m[1]) return cleanName(m[1]);

    return null;

    function cleanName(s) {
      var name = s.replace(/\s+/g, ' ').trim();
      // Guard: a real customer name doesn't start with an interrogative or verb.
      // (Stops Pattern C from over-capturing "what is on Rivera" etc.)
      if (/^(what|who|when|where|why|how|is|are|was|were|do|does|did|can|could|the|a|an|my|our|all|any)\b/i.test(name)) {
        // Keep only the trailing 1–2 tokens, which are the actual name.
        var toks = name.split(' ');
        name = toks.slice(Math.max(0, toks.length - 2)).join(' ');
        // Drop a leading preposition if the trim left one ("on Rivera" → "Rivera").
        name = name.replace(/^(on|for|to|of|about|re)\s+/i, '');
      }
      return name;
    }
  }

  // ── (cross-app orchestrator): the dashboard "ask" router ──
  // Turns the top-of-dashboard ask field into an operator entry point. It
  // CLASSIFIES a natural-language command into one of:
  //   • { kind:'shell', ... }  — an instant in-shell action (e.g. open Camera
  //                              with a sticky pre-label). No round-trip to the assistant.
  //   • { kind:'route', ... }  — route to the assistant (sales-analytics) with the
  //                              prompt + an orchestrator_hint so the user sees the
  //                              intent was understood; the bot/engine does the work.
  //   • null                    — not a recognized command; fall through to the
  //                              normal "Ask the assistant" behavior (nothing regresses).
  // It is deterministic (regex + the existing detectLookupIntent), so the clear
  // commands work WITHOUT any Poe change and answer instantly. Anything it can't
  // classify still goes to the bot exactly as before.
  function zdzOrchestratorRoute(raw) {
    if (!raw) return null;
    // one plain space for any run of spaces (a no-break space included), as the server does.
    var q = String(raw).replace(/[\s\u00A0]+/g, ' ').trim();
    var lower = q.toLowerCase();

    // ---- 0. the work itself, said or typed ("I'm on my way to the Rivera job", "file the Carter photos").
    // Before the camera, whose wide photo words would otherwise take "file the Carter photos".
    var act = zdzDetectAppAction(q);
    if (act) return act;

    // ---- 1. CAMERA pre-label: "open the camera and label all my photos as 'before'",
    // "take a photo with the phrase 'before' added", "snap some before pics", etc.
    // Signal A: a camera/photo-capture intent (open the camera OR take/snap a photo).
    // Signal B (for the shell action): a label directive. Handled in-shell (instant).
    var cameraOpen =
         /\b(open|launch|start|fire up|bring up|go to|use|pull up)\b[^.]*\bcamera\b/.test(lower)
      || /\bcamera\b[^.]*\b(open|on|up|please)\b/.test(lower)
      || /^camera\b/.test(lower)
      // capture verbs: take/snap/shoot/grab/capture (typo-tolerant: tak(e), snp, etc.)
      // + a photo noun (photo/picture/pic/shot/image/selfie, typo-tolerant).
      || (/\b(tak(?:e|es|ing)?|snap|snp|shoot|grab|captur(?:e|ing)?|get)\b/.test(lower)
          && /\b(photos?|phot|pics?|pix|pictures?|pics?|shots?|images?|selfies?)\b/.test(lower))
      // "a <label> photo/pic" implies capture even without an explicit verb
      || /\b(a|some|the)\s+[\w'-]+\s+(photos?|pics?|pictures?|shots?)\b/.test(lower)
      || /\b(photo|picture|pic)\s+session\b/.test(lower)
      || /\bstart\b[^.]*\b(shooting|taking|snapping)\b/.test(lower);
    if (cameraOpen) {
      var label = extractPrelabel(q);
      var hasCamera = getVisibleApps().some(function (a) {
        return a.id === 'camera' || a.id === 'zdz-camera';
      });
      if (hasCamera) {
        // a camera-capture command ALWAYS opens the camera in-shell —
        // with OR without a pre-label. Previously only the labeled form became a
        // shell action and a bare "take a picture" fell through to the assistant
        // (which tried to "take a picture" itself — wrong). Now any recognized
        // capture intent opens the real camera; the label is applied if present.
        var camName = label
          ? 'Open Camera — label photos "' + label + '"'
          : 'Open Camera';
        var camDesc = label
          ? 'Tags every photo you take as "' + label + '" until you stop'
          : 'Opens the camera to take photos';
        return {
          kind: 'shell',
          name: camName,
          desc: camDesc,
          icon: 'camera',
          color: '#0EA5E9',
          run: function () {
            // Confirm the launch with a haptic tap (the shutter-button haptic
            // itself lives in the Camera app).
            try { Haptics.tap(); } catch (e) {}
            // The camera app registers as 'zdz-camera' (and its launch handler only
            // claims that id); use whichever id is actually present.
            var camApp = getVisibleApps().filter(function (a) { return a.id === 'zdz-camera' || a.id === 'camera'; })[0];
            var opts = { source: 'orchestrator' };
            if (label) { opts.prelabel = label; }
            openApp(camApp ? camApp.id : 'zdz-camera', opts);
          }
        };
      }
      // No camera app available → fall through to normal handling.
    }

    // ---- 1a. COMMISSION for a NAMED person → inline card. "commission
    // for Alex, May 2026", "Alex's commission for May", "give me Riley's
    // commission". A figure for a specific salesperson is a bounded answer, so we
    // render it INLINE under the field (headline + expandable drill-down) instead
    // of opening the whole widget — like the contact card. Checked BEFORE the
    // generic launch so "commission for Alex" ≠ "open the Commission app". A
    // bare "open commission" / "commission for last month" (no person) has no
    // subject here, returns null, and falls through to the launch below.
    var commCard = zdzDetectCommissionInline(q, lower);
    if (commCard) return commCard;

    // ---- 1b. GENERIC APP LAUNCH: "open the schedule", "new estimate
    // for the Smith job", "start a sketch", "check stock for sliders", "pull up
    // commission for last month", etc. Like the Camera block, these are INSTANT
    // in-shell shell actions — openApp() jumps to the app's dashboard widget (no
    // the assistant round-trip). Each app can optionally capture a bit of context
    // (a customer/job name, a date/period) passed through openApp options so the
    // widget can pre-focus. Camera is handled above (it has bespoke label logic);
    // email/contact/document lookups are handled below and take precedence over a
    // bare "open" only when their stronger signals match — so we run this AFTER
    // camera but it returns null unless an explicit launch verb + app is present.
    var launch = zdzDetectAppLaunch(q, lower);
    if (launch) return launch;

    // ---- 1c. STOCK for an item: "<item> available", "how much <item> do we have".
    // A bounded answer (how many on hand), so it renders inline. Quantities only; the server decides, and an
    // ask that matches nothing in the stock list goes on to chat as before.
    var stockItem = zdzAskStock(lower);
    if (stockItem) {
      return {
        kind: 'inline',
        verb: 'stock',
        query: stockItem,
        rawQuery: q,
        name: 'Stock: ' + stockItem,
        desc: 'How many are on hand — shown right here',
        icon: 'package',
        color: '#7C3AED',
        options: { prompt: q, orchestrator_hint: { verb: 'stock', query: stockItem } }
      };
    }

    // ---- 2. EMAIL compose: "write/draft/send an email to Sam Carter about ..." ----
    // Checked BEFORE contact: "email <Name> about X" is a COMPOSE intent, not a
    // request to look up <Name>'s email address. Compose opens the full chat (the
    // editable house-voice draft card lives there).
    var emailTo = detectEmailIntent(q);
    if (emailTo) {
      return {
        kind: 'route',
        appId: 'sales-analytics',
        name: 'Draft an email to ' + emailTo,
        desc: 'Writes it in the house voice — click to send',
        icon: 'mail',
        color: '#6366F1',
        options: { prompt: q, orchestrator_hint: { verb: 'email', to: emailTo } }
      };
    }

    // ---- 3. CONTACT lookup: "contact info for Casey Rivera", "Riley's phone number"
    // Contextual delivery: a contact is a bounded fact, so we render it INLINE on
    // the dashboard via /zorderz/v1/orchestrate (no chat switch, no Poe round-trip).
    // If the result is rich/denied/ambiguous, the inline card offers "Open in
    // chat →" to hand off to the full Analytics surface (see renderInlineContact).
    var contactName = detectContactIntent(q);
    if (contactName) {
      return {
        kind: 'inline',
        verb: 'contact',
        query: contactName,
        rawQuery: q,              // server re-classifies from the exact words
        name: 'Get ' + contactName + '’s contact info',
        desc: 'Phone, email & address — shown right here',
        icon: 'contact',
        color: '#6366F1',
        // Fallback prompt if the user chooses the chat handoff.
        options: { prompt: q, orchestrator_hint: { verb: 'contact', query: contactName } }
      };
    }

    // ---- 4. DOCUMENT lookup: "estimate for Sam Rivera", "pull up Rivera's quote" ----
    // Inline too: a short open-docs summary renders here, with "Open in chat →" for
    // the full interactive estimate cards. Server (/orchestrate) is the authority.
    var lookupName = detectLookupIntent(q);
    if (lookupName) {
      return {
        kind: 'inline',
        verb: 'lookup',
        query: lookupName,
        rawQuery: q,
        name: 'Look up ' + lookupName,
        desc: 'Their open estimates & invoices — shown right here',
        icon: 'file-search',
        color: '#6366F1',
        options: { prompt: q, orchestrator_hint: { verb: 'lookup', query: lookupName } }
      };
    }

    return null;

    // Extract the pre-label word/phrase from a camera command. Handles quoted and
    // UNQUOTED forms, and several natural phrasings ("with the phrase 'before' added",
    // "labeled before", "a before photo", "tagged X", "call them X").
    function extractPrelabel(s) {
      var m;
      // 1) ANY quoted token wins — that's almost always the intended label.
      //    e.g. take a photo with the phrase "before" added  /  label them 'before'
      m = s.match(/["'“”]([^"'“”]{1,40})["'“”]/);
      if (m && m[1] && cleanLabel(m[1])) return cleanLabel(m[1]);
      // 2) "...the phrase/word/label/tag <X> (added|applied|on them)" — unquoted
      m = s.match(/\b(?:phrase|word|label|tag|note|caption)\s+([A-Za-z0-9][\w \-]{0,30}?)\s*(?:added|applied|on (?:them|each|all)|to (?:each|all|every))?\s*(?:\.|,|$)/i);
      if (m && m[1] && cleanLabel(m[1])) return cleanLabel(m[1]);
      // 3) "(pre)label/tag/mark/call (them/all/these/the photos) (as|to|with) <X>"
      m = s.match(/\b(?:pre[\s-]?label|label|tag|mark|call|name)\b\s+(?:them|all|these|the\s+(?:photos?|pics?|images?))?\s*(?:as|to|with)?\s*([A-Za-z0-9][\w \-]{0,30}?)\s*(?:\.|,|$|photos?|pics?|images?)/i);
      if (m && m[1] && cleanLabel(m[1])) return cleanLabel(m[1]);
      // 4) "(take|snap) ... a/some <X> photo(s)/pic(s)"  e.g. "take a before photo"
      m = s.match(/\b(?:tak(?:e|ing)?|snap|snp|shoot|grab|captur(?:e|ing)?|get)\b[^]*?\b(?:a|some|the)\s+([A-Za-z][\w \-]{0,20}?)\s+(?:photos?|phot|pics?|pix|pictures?|shots?|images?)\b/i);
      if (m && m[1] && cleanLabel(m[1])) return cleanLabel(m[1]);
      // 5) "labeled/labelled/tagged/marked/called <X>" (unquoted, anywhere)
      m = s.match(/\b(?:labell?ed|tagged|marked|named|called)\s+["'“]?([A-Za-z0-9][\w \-]{0,30}?)["'”]?\s*(?:\.|,|$)/i);
      if (m && m[1] && cleanLabel(m[1])) return cleanLabel(m[1]);
      return '';
    }
    function cleanLabel(s) {
      var v = String(s).replace(/\s+/g, ' ').trim();
      // Drop leading filler/connectors.
      v = v.replace(/^(?:the|a|an|as|to|with|phrase|word|label|tag|of)\s+/i, '');
      // Drop trailing nouns/verbs the capture may have swept in.
      v = v.replace(/\s+(?:photos?|pics?|pictures?|images?|shots?|examples?|added|applied|on|to|each|all|every|them)$/i, '');
      v = v.trim();
      // Reject pure filler that isn't a real label.
      if (/^(?:them|all|these|those|it|that|this|each|every|some|the|a|an|my|our|photo|photos|pic|pics|picture|pictures)$/i.test(v)) return '';
      return v;
    }

    // Contact-intent detector — typo/punctuation tolerant by design (field use is
    // messy: "what';s devin riveras info/", "devin rivera info", "whats Devin's #").
    // Strategy: (1) bail on analytics/aggregate framing; (2) NORMALIZE away stray
    // punctuation (apostrophes, semicolons, slashes, extra spaces); (3) fire if an
    // "info word" and a plausible person-name BOTH appear — in any order. The
    // server still resolves/redacts; over-firing just means a card instead of chat.
    function detectContactIntent(s) {
      var raw = String(s || '');
      var lo = raw.toLowerCase();
      // Analytics/aggregate framing wins — never a single-contact lookup.
      if (/\b(how many|how much|count|number of|list all|list the|total|average|avg|by (salesperson|rep|city|zip|source|product|type|month)|this (month|quarter|year|week)|last (month|quarter|year|week)|ytd|mtd|qtd|breakdown|revenue|sales|top \d)\b/.test(lo)) return null;

      var infoTerm = '(?:contact|info|information|details?|number|phone|e-?mail|cell|address|card|reach)';

      // ── Pass 1: the clean, structured patterns (fast path, precise name capture) ──
      var nameRe = '([A-Za-z][\\w.\\-]*(?:\\s+[A-Za-z][\\w.\\-]*){0,3})';
      var m;
      m = raw.match(new RegExp('\\b' + infoTerm + '(?:\\s+(?:info|information|details?|number|card))?\\s+(?:for|on|of|about)\\s+' + nameRe, 'i'));
      if (m && m[1]) return cleanName(m[1]);
      m = raw.match(new RegExp('\\b' + nameRe + '(?:\'s|’s|s\')\\s+' + infoTerm + '\\b', 'i'));
      if (m && m[1]) return cleanName(m[1]);
      m = raw.match(new RegExp('\\bhow (?:do|can) i (?:reach|contact|get a hold of|call|email)\\s+' + nameRe, 'i'));
      if (m && m[1]) return cleanName(m[1]);
      m = raw.match(new RegExp('^(?:who(?:\'s| is)|look up|lookup|pull up)\\s+' + nameRe + '\\??$', 'i'));
      if (m && m[1] && !/^(my|our|all|the|a|an|everyone|anyone)\b/i.test(m[1])) return cleanName(m[1]);

      // ── Pass 2: punctuation-tolerant fallback (catches mangled typing) ──
      // Normalize: drop apostrophes/possessives/semicolons/slashes, collapse spaces.
      var norm = lo
        .replace(/['’;:/\\?!.,]+/g, ' ')   // strip stray punctuation incl. ';' and '/'
        .replace(/\b([a-z]+)s\b/g, '$1s')  // (no-op safeguard; keeps tokens intact)
        .replace(/\s+/g, ' ')
        .trim();
      var hasInfoWord = new RegExp('\\b' + infoTerm + '\\b').test(norm);
      if (!hasInfoWord) return null;

      // Remove the question/filler words + the info word itself, leaving the name.
      var leftover = norm
        .replace(new RegExp('\\b' + infoTerm + '\\b', 'g'), ' ')
        .replace(/\b(what|whats|what s|who|whos|who s|is|are|the|a|an|me|my|our|give|get|find|show|pull|up|look|lookup|please|can|you|i|for|on|of|about|to|s)\b/g, ' ')
        .replace(/\s+/g, ' ')
        .trim();
      // What's left should look like a name: 1–4 alphabetic tokens.
      if (leftover && /^[a-z][a-z .\-]{1,40}$/.test(leftover)) {
        var toks = leftover.split(' ').filter(Boolean);
        if (toks.length >= 1 && toks.length <= 4) {
          // Reject obvious non-names (single super-common words).
          if (!/^(everyone|anyone|someone|customer|customers|client|clients|them|people)$/.test(leftover)) {
            return cleanName(leftover);
          }
        }
      }
      return null;
    }

    // Email-compose detector. Verb (write/draft/send/email) + recipient name.
    function detectEmailIntent(s) {
      var nameRe = '([A-Za-z][\\w.\\-]*(?:\\s+[A-Za-z][\\w.\\-]*){0,3})';
      var m = s.match(new RegExp('\\b(?:write|draft|compose|send|shoot|fire off)\\s+(?:an?\\s+)?(?:email|e-mail|note|message)\\s+(?:to\\s+)?' + nameRe, 'i'));
      if (m && m[1]) return cleanName(m[1]);
      m = s.match(new RegExp('\\bemail\\s+' + nameRe + '\\b', 'i'));
      if (m && m[1] && !/^(me|us|them|him|her|everyone|the)\b/i.test(m[1])) return cleanName(m[1]);
      return null;
    }

    function cleanName(s) {
      var name = String(s).replace(/\s+/g, ' ').trim();
      // Strip a leading possessive fragment left by "what's"/"that's" etc.
      // ("s Sam Rivera" → "Sam Rivera").
      name = name.replace(/^s\s+/i, '');
      // Strip leading filler verbs/pronouns/determiners the regex may have swept in
      // ("give me Casey Rivera" → "Casey Rivera"). Repeat until none remain.
      var fillerRe = /^(?:get|give|grab|find|pull|look|show|me|us|the|a|an|my|our|up|to|for|of|please|can|you|i|me)\b\s*/i;
      while (fillerRe.test(name)) { name = name.replace(fillerRe, ''); }
      // Drop trailing connector words the regex may have swept in.
      name = name.replace(/\s+\b(about|re|regarding|confirming|to confirm|and|for|that|when|tomorrow|today|asap)\b.*$/i, '');
      if (/^(what|who|when|where|why|how|is|are|was|were|do|does|did|could)\b/i.test(name)) {
        var toks = name.split(' ');
        name = toks.slice(Math.max(0, toks.length - 2)).join(' ');
        name = name.replace(/^(on|for|to|of|about|re)\s+/i, '');
      }
      return name.trim();
    }
  }

  // the item in a stock question, or ''. The rule is ZDZ_Ask_Phrases::stock() (the same
  // steps as ZDZ_Ask_Phrases::match_stock on the server).
  function zdzAskStock(lower) {
    var S = (typeof zdzData !== 'undefined' && zdzData.askPhrases && zdzData.askPhrases.stock) || null;
    if (!S || !lower) return '';   // no stock rule: not someone who may ask Stock (the shared device), or no Stock
    try {
      for (var i = 0; i < S.frames.length; i++) {
        var m = lower.trim().match(new RegExp(S.frames[i], 'i'));
        if (!m) continue;
        var item = m[1].trim().replace(new RegExp(S.trim, 'i'), '').trim();
        if (!item || item.length > 60 || new RegExp(S.not, 'i').test(item)) return '';
        return item;
      }
    } catch (e) {}
    return '';
  }

  // ── Commission-for-a-named-person inline detector. Returns a kind:'inline' verb:'commission' route,
  // or null. Requires "commission(s)" (or an earnings frame) AND a person (a name or "my"), so aggregates
  // ("total commissions this month", "commission by rep") fall through to the launch and chat paths.
  // The rule itself is ZDZ_Ask_Phrases::commission() (one copy, on the server).
  function zdzDetectCommissionInline(q, lower) {
    if (!lower) return null;
    // the rule is DATA from the server (ZDZ_Ask_Phrases::commission → zdzData.askPhrases),
    // run here by the same steps ZDZ_Ask_Phrases::match_commission runs, so the row and the server's answer
    // can no longer drift. No phrases on the page (an older cache) → no commission row; the ask goes on below.
    var P = (typeof zdzData !== 'undefined' && zdzData.askPhrases && zdzData.askPhrases.commission) || null;
    if (!P) return zdzDetectCommissionInlineLocal(q, lower);   // no phrase rules served: the rule written out below
    var reCache = {};
    // A pattern this browser cannot compile (a plugin's row the server let through) never stops the ask bar:
    // it matches nothing.
    function R(src) {
      if (reCache[src]) return reCache[src];
      try { reCache[src] = new RegExp(src, 'i'); } catch (e) { reCache[src] = /(?!)/; }
      return reCache[src];
    }
    function take(s) {
      var t = P.take;
      var n = String(s || '').replace(/\s+/g, ' ').trim();
      if (!n || R(t.self_frag).test(n)) return '';
      for (var guard = 0; guard < 20 && R(t.lead).test(n); guard++) {
        var next = n.replace(R(t.lead), '');
        if (next === n) break;
        n = next;
      }
      n = n.replace(/^s\s+/i, '');
      n = n.replace(R(t.trail_conn), '').trim();
      n = n.replace(R(t.trail_period), '').trim();
      var toks = n.split(' ').filter(Boolean);
      if (toks.length > 2) toks = toks.slice(-2);
      var last = toks[toks.length - 1];
      if (last && last.length > 2 && R(t.plural_s).test(last)) toks[toks.length - 1] = last.slice(0, -1);
      n = toks.join(' ');
      if (n === '' || n.length < 2 || R(t.stop).test(n) || (t.last_not && R(t.last_not).test(n))) return '';
      return n;
    }
    var has = lower.indexOf(P.word) !== -1;
    var earn = P.earn.some(function (p) { return R(p).test(q); });
    if (!has && !earn) return null;
    if (!R(P.person_specific).test(q) && R(P.company_wide).test(lower)) return null;
    var isSelf = P.self.some(function (p) { return R(p).test(q); });
    var subject = '', multiNames = null, m;
    for (var i = 0; i < P.subject.length && !subject; i++) {
      var row = P.subject[i];
      if (!(m = q.match(R(row.re)))) continue;
      var cand = m[row.g] || '';
      if (row.plural) cand = cand.replace(/^([A-Za-z][\w.\-]*[a-rt-z])s$/i, '$1');
      subject = take(cand);
      if (subject && row.multi) {
        var others = row.multi.map(function (g) { return take(m[g] || ''); }).filter(Boolean);
        if (others.length) multiNames = [subject].concat(others);
      }
    }
    if (!subject && !isSelf) return null;
    var pm = q.match(R(P.period));
    var period = pm ? pm[1].trim() : '';
    var focus = '';
    for (var f = 0; f < P.focus.length; f++) { if (R(P.focus[f][1]).test(lower)) { focus = P.focus[f][0]; break; } }

    var who = subject || 'my';
    var label = subject ? (subject + '’s commission') : 'My commission';
    if (multiNames && multiNames.length > 1) { label = subject + '’s commission (of ' + multiNames.length + ' — showing ' + subject + ')'; }
    var focusLabel = focus === 'products' ? ' — by product' : (focus === 'jobs' ? ' — jobs' : (focus === 'basis' ? ' — how it’s calculated' : ''));
    return {
      kind: 'inline',
      verb: 'commission',
      query: who,
      rawQuery: q,                 // server re-classifies from the exact words
      focus: focus,                // '' | 'jobs' | 'products' | 'basis' → auto-open
      name: label + (period ? ' — ' + period : '') + (period ? '' : focusLabel),
      desc: focus
        ? 'Opens straight to ' + (focus === 'products' ? 'the product split' : (focus === 'jobs' ? 'the jobs' : 'the rate basis')) + ' — shown right here'
        : 'The figure with a tap-to-expand breakdown — shown right here',
      icon: 'calculator',
      color: '#10B981',
      options: { prompt: q, orchestrator_hint: { verb: 'commission', subject: who, period: period, focus: focus } }
    };
  }

  // ── The work people do, said or typed ("I'm on my way to the Rivera job", "send the estimate to Carter",
  // "mark the Rivera project installed"), opens the app that does it. The rules are DATA from the server
  // (ZDZ_Ask_Actions -> zdzData.askPhrases.actions); this is the client twin of ZDZ_Ask_Actions::match(), step for step:
  // normalize what dictation adds, never treat a question as an action, then the first row whose whole phrase, or verb
  // AND object, is in the ask (and whose `not` is not) for an app this person can open. It only opens the app.
  function zdzAskActionNormalize(A, q) {
    var s = String(q || '').replace(/[\u2018\u2019`]/g, "'");
    // Accented letters to plain ones, A-Z only to lower case, other non-ASCII runs to a space, at most 300 (as the server).
    var F = A.fold || [];
    for (var f = 0; f < F.length; f++) { if (s.indexOf(F[f][0]) !== -1) s = s.split(F[f][0]).join(F[f][1]); }
    s = s.replace(/[A-Z]+/g, function (c) { return c.toLowerCase(); }).replace(/[^\x00-\x7f]+/g, ' ');
    s = s.replace(/\s+/g, ' ').trim().slice(0, 300);
    for (var pass = 0; pass < 2; pass++) {
      for (var i = 0; i < A.normalize.length; i++) {
        var r = A.normalize[i];
        s = s.replace(zdzAskRe(r[0], 'g'), r[1]);
      }
    }
    return s.trim();
  }
  var _zdzAskReCache = {};
  function zdzAskRe(src, flags) {
    var k = (flags || '') + '|' + src;
    if (_zdzAskReCache[k]) return _zdzAskReCache[k];
    try { _zdzAskReCache[k] = new RegExp(src, 'i' + (flags || '')); } catch (e) { _zdzAskReCache[k] = /(?!)/; }
    return _zdzAskReCache[k];
  }
  function zdzAskActionName(A, s, person) {
    var C = A.ctx || {};
    // A message goes to a person: "tell Jordan the Rivera job moved" names Jordan first (as name_in on the server).
    var P = person ? (C.name || []).slice(4).concat((C.name || []).slice(0, 4)) : (C.name || []);
    for (var i = 0; i < P.length; i++) {
      // Every place the pattern fits, left to right (as preg_match_all on the server).
      var re = new RegExp(zdzAskRe(P[i]).source, 'ig'), m;
      while ((m = re.exec(s)) !== null) {
        if (m[0] === '') { re.lastIndex++; continue; }
        var n = (' ' + String(m[1] || '').trim()).replace(zdzAskRe(C.trail), '').trim().replace(/'s$/, '').trim();
        if (!n || n.length < 2 || zdzAskRe(C.stop).test(n) || zdzAskRe(C.stop).test(n.split(' ')[0]) || /\d/.test(n)) continue;
        return n.split(' ').map(function (w) { return w ? w.charAt(0).toUpperCase() + w.slice(1) : w; }).join(' ');
      }
    }
    return '';
  }
  function zdzAskActionNumber(A, s) {
    var m = s.match(zdzAskRe((A.ctx || {}).number || '(?!)'));
    return m ? (m[1] || m[2] || '') : '';
  }
  function zdzAskAction(q, appIds) {
    var A = (typeof zdzData !== 'undefined' && zdzData.askPhrases && zdzData.askPhrases.actions) || null;
    if (!A || !A.rows || !A.normalize) return null;
    var s = zdzAskActionNormalize(A, q);
    if (!s || s.length > 240 || zdzAskRe(A.question).test(s) || (A.analytic && zdzAskRe(A.analytic).test(s)) || (A.report && zdzAskRe(A.report).test(s) && !zdzAskRe(A.doing || '(?!)').test(s)) || /\?\s*$/.test(String(q || ''))) return null;
    for (var i = 0; i < A.rows.length; i++) {
      var r = A.rows[i], hit = false;
      for (var p = 0; p < (r.phrases || []).length; p++) { if (zdzAskRe(r.phrases[p]).test(s)) { hit = true; break; } }
      if (!hit && r.verbs && zdzAskRe(r.verbs).test(s) && zdzAskRe(r.objects).test(s)) hit = true;
      if (!hit || (r.not && zdzAskRe(r.not).test(s))) continue;
      var app = '';
      for (var a = 0; a < r.apps.length; a++) { if (!appIds || appIds.indexOf(r.apps[a]) !== -1) { app = r.apps[a]; break; } }
      if (!app) continue;
      var name = (r.ctx === 'name' || r.ctx === 'both') ? zdzAskActionName(A, s, r.key === 'to') : '';
      var number = (r.ctx === 'number' || r.ctx === 'both') ? zdzAskActionNumber(A, s) : '';
      var opts = { source: 'ask', action: r.id };
      for (var k in (r.open || {})) { if (Object.prototype.hasOwnProperty.call(r.open, k)) opts[k] = r.open[k]; }
      if (name) opts[r.key] = name;
      if (number) opts.estimate_number = number;
      return { id: r.id, app: app, label: r.label, name: name, number: number, options: opts };
    }
    return null;
  }
  // The ask bar's row for it: what will open, and that nothing is done until the person does it there.
  function zdzDetectAppAction(q) {
    // Every app this person may open (zdzData.apps, from get_user_app_configs), not only the launcher's: an app kept off
    // the launcher still opens, as Analytics does for the chat.
    var visible = (zdzData.apps || []).filter(function (a) { return a && a.id; });
    var m = zdzAskAction(q, visible.map(function (a) { return a.id; }));
    if (!m) return null;
    var appCfg = visible.filter(function (a) { return a.id === m.app; })[0] || {};
    var appName = appCfg.nm || m.app;
    var what = m.name ? ' \u2014 ' + m.name : (m.number ? ' \u2014 #' + m.number : '');
    return {
      kind: 'shell',
      name: m.label + what,
      desc: 'Opens ' + appName + '. Nothing is done until you do it there.',
      icon: appCfg.icon || 'arrow-right',
      color: appCfg.cc || '#64748B',
      appId: m.app, ink: appCfg.ink,
      action: m.id,
      run: (function (id, o) {
        return function () { try { Haptics.tap(); } catch (e) {} openApp(id, o); };
      })(m.app, m.options)
    };
  }

  // ── Generic app-launch intent detector for the dashboard ask field. Recognizes "<launch verb> <app>"
  // (+ optional context) and returns a kind:'shell' action that opens the app's dashboard widget via openApp().
  // An explicit launch verb, or the app word at the very start, is required, so analytic questions ("how many
  // estimates…") fall through.: the rows are ZDZ_Ask_Phrases::launch_rows() (one copy, on the server).
  // The rules written out, for a page served without the phrase rules (no ZDZ_Ask_Phrases on the server): the same
  // phrasings, so the ask bar works the same either way.
  function zdzDetectCommissionInlineLocal(q, lower) {
      if (!lower) return null;
      var hasComm = lower.indexOf('commission') !== -1;
      // EARNINGS SHORTHAND: on this sales dashboard, an unambiguous
      // personal-earnings question with NO other app keyword means "commission"
      // even when the word is absent — e.g. "how much did Jordan earn last month",
      // "what did Sam make", "how much does Alex take home". Require a
      // did/does/do <Name> <earn-verb> OR <Name> earned/made/took-home frame so
      // generic "how do I earn rewards" never matches.
      var earnFrame = /\b(?:did|does|do)\s+[A-Za-z][\w.\-]*(?:\s+[A-Za-z][\w.\-]*){0,2}?\s+(?:earn|earns|earned|make|makes|made|get|gets|got|bring in|brings in|brought in|pull|pulls|pulled|take home|takes home|took home)\b/i.test(q)
        || /\b[A-Za-z][\w.\-]*(?:\s+[A-Za-z][\w.\-]*){0,2}?\s+(?:earned|made|brought in|pulled in|took home)\b/i.test(q);
      if (!hasComm && !earnFrame) return null;
      // PERSON-SPECIFIC override: a possessive-name query like
      // "Jordan's commission rate" / "what's Sam's May commission" is about ONE
      // person, so it must NOT be swept into the company-wide rollup skip below
      // (which would otherwise catch "commission rate(s)", "total commissions", etc.).
      var personSpecific = /\b[A-Za-z][\w.\-]*(?:'s|’s)\s+(?:(?:this|last|next)\s+(?:month|quarter|year|week)\s+|(?:january|february|march|april|may|june|july|august|september|october|november|december|q[1-4]|mtd|ytd|\d{4})\s+)?commissions?\b/i.test(q);
      // COMPANY-WIDE / cross-person framing → chat (a real rollup, no single person):
      // "commission by salesperson", "did we pay everyone", "average commission",
      // "commission report", "how do commissions work". Note: "by PRODUCT/type" is
      // NOT in this list — for a NAMED person it's a valid drill-down (focus below).
      if (!personSpecific && /\b(by (?:salesperson|rep|person|month)|everyone|all (?:reps|salespeople|staff)|the (?:whole )?team|company-?wide|total commissions?|did we pay|do we pay|we paid|our (?:total |average )?commission|average commission|commission report|how do commissions?|how does commission|commission rates?|commission structure|commission plan)\b/.test(lower)) return null;

      // Words that are NOT a person — used to reject a bad subject capture. Includes
      // periods, launch verbs, and common verbs/adjectives the name regex might grab
      // ("make", "earn", "average", "did", "pay", etc.).
      var STOP = /^(i|have|has|had|commission|commissions|this|last|next|for|of|the|a|an|report|summary|by|total|owed|due|rate|rates|check|breakdown|history|my|me|mine|our|us|we|today|tomorrow|yesterday|month|year|quarter|week|q[1-4]|january|february|march|april|may|june|july|august|september|october|november|december|jan|feb|mar|apr|jun|jul|aug|sep|sept|oct|nov|dec|mtd|ytd|qtd|open|launch|start|pull|show|go|goto|check|view|bring|jump|fire|new|create|add|begin|see|calculate|calc|make|makes|made|making|earn|earns|earned|earning|get|gets|got|pay|paid|paying|average|avg|much|did|do|does|done|is|are|was|were|how|what|whats)\b/i;
      // A person name: 1–3 capitalized-ish tokens, NOT followed by "for"/period.
      // Stop the capture before a trailing "for <period>" so "Alex for May" → "Alex".
      var NAME = '([A-Za-z][\\w.\\-]*(?:\\s+[A-Za-z][\\w.\\-]*){0,2}?)';

      // Leading filler/verbs/pronouns the regex may sweep into the name.
      var LEAD = /^(?:give|show|get|grab|find|pull|look|tell|me|us|the|a|an|my|our|up|and|or|vs|versus|commission|commissions|to|for|of|on|in|into|at|toward|towards|count|counts|counted|counting|please|can|you|i|what|whats|what's|is|are|was|were|do|does|did|could|how|much|see|make|makes|made|earn|earns|earned|pay|paid|rate|basis|math|break|breakdown|down|drill|detail|details)\b\s*/i;
      function takeName(s) {
        if (!s) return '';
        var n = String(s).replace(/\s+/g, ' ').trim();
        // never let a first-person/auxiliary fragment become a name
        // ("I", "have I", "did I"): these are self-references, handled by isSelf.
        if (/^(?:i|have i|has i|had i|did i|do i|i have|i did)$/i.test(n)) return '';
        // Strip leading filler repeatedly ("is Rileys" → "Rileys", "up" → "").
        while (LEAD.test(n)) { n = n.replace(LEAD, ''); }
        // Strip an orphaned leading "s " left when "what's"/"that's" backtracks the
        // possessive regex onto the bare "s" — no real name is a lone "s".
        n = n.replace(/^s\s+/i, '');
        // Trim a trailing "for/in/this/last ..." / period fragment.
        n = n.replace(/\s+\b(for|in|during|this|last|next|on)\b.*$/i, '').trim();
        n = n.replace(/\s+\b(january|february|march|april|may|june|july|august|september|october|november|december|today|tomorrow|yesterday|month|year|quarter|week|mtd|ytd|q[1-4]|\d{4})\b.*$/i, '').trim();
        // Keep at most the last 2 tokens (a name is 1–2 words: "a salesperson", "a specialist").
        var toks = n.split(' ').filter(Boolean);
        if (toks.length > 2) { n = toks.slice(-2).join(' '); toks = n.split(' '); }
        // Strip a bare trailing-s possessive on the LAST token ("Rileys" → "Riley"),
        // but leave names that legitimately end in "ss" and 1-letter initials alone.
        var last = toks[toks.length - 1];
        if (last && /^[A-Za-z][\w.\-]*[a-rt-z]s$/i.test(last) && last.length > 2) {
          toks[toks.length - 1] = last.slice(0, -1);
          n = toks.join(' ');
        }
        if (n === '' || STOP.test(n) || n.length < 2) return '';
        return n;
      }

      // self also matches first-person EARNINGS phrasing so
      // "how much did I earn this month" / "what have I made" resolves to the
      // current user instead of trying to read "I"/"have I" as a salesperson name.
      var isSelf = /\b(my|me|mine)\b/.test(lower)
        || /\b(?:did|do|have|has|how much (?:have|did))?\s*i\s+(?:have\s+)?(?:earn|earned|make|made|made|get|got|take home|took home|bring in|brought in|pull|pulled)\b/i.test(q)
        || /\bmy\s+(?:total\s+)?(?:pay|earnings|take)\b/i.test(lower);
      var subject = '';
      var m;
      // A period/time word that can sit BETWEEN the name and "commission"
      // ("Alex's MAY commission", "Alex's LAST MONTH commission"). We allow it
      // optionally in the patterns so it doesn't get captured as the name.
      var PERIODW = '(?:(?:this|last|next)\\s+(?:month|quarter|year|week)\\s+|(?:january|february|march|april|may|june|july|august|september|october|november|december|q[1-4]|mtd|ytd|\\d{4})\\s+)?';
      // PERSON tokens that are clearly a name (1–2), used where we need to be strict.
      var PNAME = '([A-Za-z][\\w.\\-]*(?:\\s+[A-Za-z][\\w.\\-]*)?)';

      // "<Name>'s [period] commission" — strongest signal; period word optional.
      if ((m = q.match(new RegExp('\\b' + PNAME + '(?:\'s|’s)\\s+' + PERIODW + 'commissions?\\b', 'i')))) {
        subject = takeName(m[1]);
      }
      // MULTI-PERSON ("Jordan and Sam commission", "Alex & Riley",
      // "commission for Jordan and Sam"): the card shows ONE person, so we take
      // the FIRST valid name and flag it; the row label notes others were named.
      var multiNames = null;
      if (!subject) {
        var mm2 = q.match(new RegExp('\\b' + PNAME + '\\s+(?:and|&|,|or|vs|versus)\\s+' + PNAME + '(?:\\s+(?:and|&|,)\\s+' + PNAME + ')?\\s+(?:[a-z]+\\s+)?commissions?\\b', 'i'))
               || q.match(new RegExp('\\bcommissions?\\s+(?:for|of)\\s+' + PNAME + '\\s+(?:and|&|,)\\s+' + PNAME, 'i'));
        if (mm2) {
          var first = takeName(mm2[1]);
          var second = takeName(mm2[2] || '');
          if (first) { subject = first; if (second) multiNames = [first, second].concat(mm2[3] ? [takeName(mm2[3])] : []).filter(Boolean); }
        }
      }
      // "commission(s) for/of/owed to <Name>".
      if (!subject && (m = q.match(new RegExp('\\bcommissions?\\s+(?:for|of|owed to|earned by|due to)\\s+' + NAME, 'i')))) subject = takeName(m[1]);
      // "the rate/basis on <Name>'s commission" — "what's the rate on Alex's …".
      if (!subject && (m = q.match(new RegExp('\\b(?:rate|basis|math|breakdown)\\s+(?:on|for|of)\\s+' + PNAME + '(?:\'s|’s)?\\s+' + PERIODW + 'commissions?', 'i')))) subject = takeName(m[1]);
      // "did/does <Name> earn/make/get …".
      if (!subject && (m = q.match(new RegExp('\\b(?:did|does|do)\\s+' + NAME + '\\s+(?:earn|earns|earned|make|makes|made|get|gets|got|bring in|brings in|brought in|pull|pulls|pulled|take home|takes home|took home)', 'i')))) subject = takeName(m[1]);
      // "<Name> earned/made/took home …" (the "how much" variant where the verb follows the name).
      if (!subject && (m = q.match(new RegExp('\\b' + NAME + '\\s+(?:earned|made|brought in|pulled in|took home)\\b', 'i')))) subject = takeName(m[1]);
      // "… commission … for <Name>" (verb phrasing with an explicit "for").
      if (!subject && (m = q.match(new RegExp('\\bcommissions?\\b.*?\\bfor\\s+' + NAME, 'i')))) subject = takeName(m[1]);
      // "make up <Name>'s [period] commission" / "in <Name>'s commission" — drill-down
      // phrasings where the name+possessive precede an intervening period word.
      if (!subject && (m = q.match(new RegExp('\\b(?:up|in|on|of|toward|towards|count toward|count towards|counts toward|counted toward)\\s+' + PNAME + '(?:\'s|’s)?\\s+' + PERIODW + 'commissions?', 'i')))) subject = takeName(m[1]);
      // "<Name> [period] commission" (loosest; period word optional). takeName()
      // rejects any non-name token via the STOP list.
      if (!subject && (m = q.match(new RegExp('\\b' + PNAME + '\\s+' + PERIODW + 'commissions?\\b', 'i')))) {
        var cand = m[1].replace(/^([A-Za-z][\w.\-]*[a-rt-z])s$/i, '$1'); // Rileys→Riley
        subject = takeName(cand);
      }

      if (!subject && !isSelf) return null;

      // Period phrase (optional) for the row label.
      var period = '';
      var pm = q.match(/\b((?:january|february|march|april|may|june|july|august|september|october|november|december)(?:\s+\d{4})?|(?:first|second|third|fourth|1st|2nd|3rd|4th)\s+quarter(?:\s+\d{4})?|q[1-4](?:\s+\d{4})?|this (?:month|quarter|year)|last (?:month|quarter|year)|quarter to date|month to date|year to date|year-to-date|qtd|mtd|ytd|\d{4}-\d{2}|\d{4})\b/i);
      if (pm) period = pm[1];

      // SMART FOCUS: the card always leads with the headline figure and
      // keeps the detail boxes COLLAPSED — unless the request explicitly asks about
      // a section, in which case we auto-open just that one. Only set when the user
      // clearly wants a drill-down; a plain "Alex's commission" leaves all closed.
      var focus = '';
      if (/\b(by product|by category|product (?:line|split|breakdown|mix)|which products?|what products?)\b/.test(lower)) focus = 'products';
      else if (/\b(which jobs?|what jobs?|jobs? (?:counted|list|breakdown|make up|are in)|per job|by job|list (?:the )?jobs?|what invoices?|which invoices?)\b/.test(lower)) focus = 'jobs';
      else if (/\b(how (?:is|was|are) .*calculated|how .*calculate|what(?:'s| is) the (?:rate|basis)|the rate on|commission rate|what rate|breakdown of (?:the )?(?:rate|math)|how much .*net|net commissionable)\b/.test(lower)) focus = 'basis';
      else if (/\b(breakdown|break it down|detail|details|drill (?:down|in))\b/.test(lower) && /\bproduct/.test(lower)) focus = 'products';

      var who = subject || 'my';
      var label = subject ? (subject + '’s commission') : 'My commission';
      if (multiNames && multiNames.length > 1) { label = subject + '’s commission (of ' + multiNames.length + ' — showing ' + subject + ')'; }
      var focusLabel = focus === 'products' ? ' — by product' : (focus === 'jobs' ? ' — jobs' : (focus === 'basis' ? ' — how it’s calculated' : ''));
      return {
        kind: 'inline',
        verb: 'commission',
        query: who,
        rawQuery: q,                 // server re-classifies from the exact words
        focus: focus,                // '' | 'jobs' | 'products' | 'basis' → auto-open
        name: label + (period ? ' — ' + period : '') + (period ? '' : focusLabel),
        desc: focus
          ? 'Opens straight to ' + (focus === 'products' ? 'the product split' : (focus === 'jobs' ? 'the jobs' : 'the rate basis')) + ' — shown right here'
          : 'The figure with a tap-to-expand breakdown — shown right here',
        icon: 'calculator',
        color: '#10B981',
        options: { prompt: q, orchestrator_hint: { verb: 'commission', subject: who, period: period, focus: focus } }
      };
    }

  function zdzDetectAppLaunchLocal(q, lower) {
      if (!lower) return null;

      // A launch verb anywhere, OR the query starting with the app word.
      var LAUNCH_VERB = /\b(open|launch|start|go to|goto|bring up|pull up|show|show me|take me to|jump to|fire up|new|create|add|begin|check|view|play)\b/;
      // tolerate common dictation/typing slips on the launch verb
      // ("opne", "oepn", "luanch", "strat", "shwo", "chekc") via a tight
      // edit-distance-1 check against the single-word verbs. Analytic questions
      // ("how many estimates") contain no near-verb word, so they still fall through.
      var SINGLE_VERBS = ['open','launch','start','show','create','begin','check','view'];
      // Damerau-Levenshtein (optimal string alignment), early-out beyond 1. Adjacent
      // transposition counts as distance 1 — that's the common dictation/typing slip.
      function dl1(a, b) {
        if (a === b) return 0;
        var la = a.length, lb = b.length;
        if (Math.abs(la - lb) > 1) return 9;
        var prev2 = [], prev = [], cur = [];
        for (var j = 0; j <= lb; j++) prev[j] = j;
        for (var i = 1; i <= la; i++) {
          cur = [i];
          for (var k = 1; k <= lb; k++) {
            var cost = a[i-1] === b[k-1] ? 0 : 1;
            var val = Math.min(prev[k] + 1, cur[k-1] + 1, prev[k-1] + cost);
            if (i > 1 && k > 1 && a[i-1] === b[k-2] && a[i-2] === b[k-1]) val = Math.min(val, prev2[k-2] + 1);
            cur[k] = val;
          }
          prev2 = prev; prev = cur;
        }
        return prev[lb];
      }
      function fuzzyVerb(lo) {
        var words = lo.split(/[^a-z]+/);
        for (var w = 0; w < words.length; w++) {
          var wd = words[w];
          if (wd.length < 4) continue;            // >=4 so 'how'/'many'/'new' can't false-match
          for (var v = 0; v < SINGLE_VERBS.length; v++) {
            var vb = SINGLE_VERBS[v];
            if (Math.abs(wd.length - vb.length) <= 1 && wd !== vb && dl1(wd, vb) <= 1) return true;
          }
        }
        return false;
      }
      var hasVerb = LAUNCH_VERB.test(lower) || fuzzyVerb(lower);

      // Helper: capture a trailing "for <name/job>" or "<name>'s" context phrase.
      function grabFor(s) {
        var m = s.match(/\bfor\s+([A-Za-z][\w'’.\-]*(?:\s+[A-Za-z][\w'’.\-]*){0,3})/i);
        if (m && m[1]) {
          var n = m[1].replace(/\s+\b(job|estimate|quote|account|today|tomorrow|this week|please)\b.*$/i, '').trim();
          // drop a leading article/possessive so "for the Smith job" → "Smith",
          // "for a Johnson estimate" → "Johnson", "for my Wilson job" → "Wilson".
          n = n.replace(/^(?:the|a|an|my|our|their|his|her)\s+/i, '').trim();
          return n;
        }
        return '';
      }
      // Helper: capture a date / period phrase for schedule & commission.
      function grabPeriod(s) {
        var m = s.match(/\b(today|tomorrow|yesterday|this (?:week|month|quarter|year)|last (?:week|month|quarter|year)|next (?:week|month)|month to date|year to date|mtd|ytd|q[1-4]|january|february|march|april|may|june|july|august|september|october|november|december|mon(?:day)?|tue(?:sday)?|wed(?:nesday)?|thu(?:rsday)?|fri(?:day)?|sat(?:urday)?|sun(?:day)?)\b/i);
        return m ? m[1] : '';
      }

      // requireVerb:true rows ONLY launch on an explicit launch verb (new/open/…),
      // never on a bare "<app> for <name>" — so "estimate for Riley" / "stock for
      // sliders" still fall through to the inline document/contact lookups below.
      // Rows without requireVerb may also launch when the query STARTS with the app
      // word (e.g. typing "schedule" or "commission" alone).
      var TABLE = [
        { ids:['scheduler'],            re:/\b(schedule|scheduler|calendar|appointments?|availability)\b/,         icon:'calendar',     cc:'#0EA5E9', verb:'Open',  ctx:function(s){var p=grabPeriod(s); return p?{options:{focus:p}, suffix:' — '+p}:null;} },
        { ids:['estimate-creator'],     re:/\b(estimate|estimates|quote|quotes|bid|bids|proposal)\b/,             icon:'file-text',    cc:'#F59E0B', verb:'New',   requireVerb:true, ctx:function(s){var n=grabFor(s); return n?{options:{customer:n}, suffix:' for '+n}:null;} },
        { ids:['zdz-sketch-pad','sketch'], re:/\b(sketch|sketchpad|draw(?:ing)?|doodle|diagram)\b/,                 icon:'pencil',       cc:'#F59E0B', verb:'Start', ctx:null },
        { ids:['stock'],                re:/\b(stock|inventory|in stock|on hand)\b/,                              icon:'package',      cc:'#3B82F6', verb:'Check', requireVerb:true, ctx:function(s){var n=grabFor(s); return n?{options:{query:n}, suffix:' for '+n}:null;} },
        { ids:['commission-calculator'], re:/\b(commission|commissions|payroll|installer pay|sales ledger|my pay)\b/, icon:'calculator',  cc:'#10B981', verb:'Open',  ctx:function(s){var p=grabPeriod(s); return p?{options:{period:p}, suffix:' — '+p}:null;} },
        { ids:['leads'],       re:/\b(leads?|lead generator|prospects?)\b/,                              icon:'target',       cc:'#22C55E', verb:'Open',  requireVerb:true, ctx:null },
        { ids:['surveys'], re:/\b(surveys?|satisfaction|csat)\b/,                                    icon:'clipboard-check', cc:'#3B82F6', verb:'Open', requireVerb:true, ctx:null },
        { ids:['prep'],                 re:/\b(prep|cutter|cut(?:ting)? queue|fabricat|shop queue)\b/,            icon:'scissors',     cc:'#F59E0B', verb:'Open',  ctx:null },
        { ids:['receipts'],             re:/\b(receipts?)\b/,                                                     icon:'receipt',      cc:'#0EA5E9', verb:'Open',  requireVerb:true, ctx:null },
        { ids:['knowledge-vault'],      re:/\b(knowledge|knowledge vault|knowledge base)\b/,                      icon:'book-open',    cc:'#22C55E', verb:'Open',  ctx:null },
        { ids:['internal-messaging'],   re:/\b(messages?|messaging|team chat|inbox)\b/,                           icon:'message-square', cc:'#3B82F6', verb:'Open', requireVerb:true, ctx:null },
        { ids:['zdz-media','zdz-media-all'], re:/\b(media|photos? library|gallery|image library)\b/,                icon:'image',        cc:'#64748B', verb:'Open',  ctx:null },
        { ids:['game'],                 re:/\b(game|play a game)\b/,                                              icon:'gamepad-2',    cc:'#84CC16', verb:'Open',  ctx:null }
      ];

      // aggregate/analytic COMMISSION questions ("commission by
      // salesperson", "commission report for the team", "average commission",
      // "how much commission did we pay") must NOT launch the widget — they're
      // questions for the assistant. Skip the launch entirely so they fall through
      // to chat. (A named-person commission was already handled as an inline card
      // before the launch detector ran.)
      var commAggregate = /\bcommissions?\b/.test(lower)
        && /\b(by (?:salesperson|rep|person|product|type|month)|report|the (?:whole )?team|everyone|company-?wide|did we pay|do we pay|we paid|our (?:total |average )?commission|average commission|total commissions?|how do commissions?|how does commission|commission rates?|commission structure)\b/.test(lower);

      for (var i = 0; i < TABLE.length; i++) {
        var row = TABLE[i];
        if (!row.re.test(lower)) continue;
        // Don't launch the Commission app on an aggregate/analytic commission query.
        if (commAggregate && row.ids.indexOf('commission-calculator') !== -1) return null;
        // Require a launch verb, OR (for non-requireVerb rows) the query begins with
        // the app word (e.g. "schedule" / "commission" typed alone).
        var startsWithApp = !row.requireVerb && row.re.test(lower.slice(0, 14));
        if (!hasVerb && !startsWithApp) continue;
        // Resolve the first registered+visible id for this app.
        var visible = (typeof getVisibleApps === 'function') ? getVisibleApps() : (zdzData.apps || []);
        var appId = null;
        for (var k = 0; k < row.ids.length; k++) {
          if (visible.some(function (a) { return a.id === row.ids[k]; })) { appId = row.ids[k]; break; }
        }
        if (!appId) continue; // app not installed/visible → let it fall through
        var appCfg = visible.filter(function (a) { return a.id === appId; })[0] || {};
        var appName = appCfg.nm || appId;
        var ctx = row.ctx ? row.ctx(q) : null;
        var opts = { source: 'orchestrator' };
        if (ctx && ctx.options) { for (var key in ctx.options) opts[key] = ctx.options[key]; }
        return {
          kind: 'shell',
          name: row.verb + ' ' + appName + (ctx && ctx.suffix ? ctx.suffix : ''),
          desc: 'Jumps to ' + appName + ' on your dashboard',
          icon: appCfg.icon || row.icon,
          color: appCfg.cc || row.cc,
          run: (function (id, o) {
            return function () { try { Haptics.tap(); } catch (e) {} openApp(id, o); };
          })(appId, opts)
        };
      }
      return null;
    }

  function zdzDetectAppLaunch(q, lower) {
    if (!lower) return null;
    // the launch table is DATA from the server (ZDZ_Ask_Phrases::launch_rows →
    // zdzData.askPhrases.launch; plugins add rows with the filter zdz_ask_launch_rows). No table → no launch row.
    var AP = (typeof zdzData !== 'undefined' && zdzData.askPhrases) || null;
    if (!AP || !AP.launch) return zdzDetectAppLaunchLocal(q, lower);   // no launch rows served: the table written out below
    var reCache = {};
    // A pattern this browser cannot compile (a plugin's row the server let through) never stops the ask bar:
    // it matches nothing.
    function R(src) {
      if (reCache[src]) return reCache[src];
      try { reCache[src] = new RegExp(src, 'i'); } catch (e) { reCache[src] = /(?!)/; }
      return reCache[src];
    }

    // tolerate common dictation/typing slips on the launch verb ("opne", "luanch", "chekc") via a
    // tight Damerau-Levenshtein distance-1 check against the single-word verbs.
    var SINGLE_VERBS = AP.fuzzyVerbs || [];
    function dl1(a, b) {
      if (a === b) return 0;
      var la = a.length, lb = b.length;
      if (Math.abs(la - lb) > 1) return 9;
      var prev2 = [], prev = [], cur = [];
      for (var j = 0; j <= lb; j++) prev[j] = j;
      for (var i = 1; i <= la; i++) {
        cur = [i];
        for (var k = 1; k <= lb; k++) {
          var cost = a[i-1] === b[k-1] ? 0 : 1;
          var val = Math.min(prev[k] + 1, cur[k-1] + 1, prev[k-1] + cost);
          if (i > 1 && k > 1 && a[i-1] === b[k-2] && a[i-2] === b[k-1]) val = Math.min(val, prev2[k-2] + 1);
          cur[k] = val;
        }
        prev2 = prev; prev = cur;
      }
      return prev[lb];
    }
    function fuzzyVerb(lo) {
      var words = lo.split(/[^a-z]+/);
      for (var w = 0; w < words.length; w++) {
        var wd = words[w];
        if (wd.length < 4) continue;            // >=4 so 'how'/'many'/'new' can't false-match
        for (var v = 0; v < SINGLE_VERBS.length; v++) {
          var vb = SINGLE_VERBS[v];
          if (Math.abs(wd.length - vb.length) <= 1 && wd !== vb && dl1(wd, vb) <= 1) return true;
        }
      }
      return false;
    }
    var hasVerb = R(AP.launchVerb).test(lower) || fuzzyVerb(lower);
    var C = AP.ctx || {};
    function grabFor(s) {
      var m = s.match(R(C['for']));
      if (m && m[1]) return m[1].replace(R(C.for_trim), '').trim().replace(R(C.for_lead), '').trim();
      return '';
    }
    function grabPeriod(s) { var m = s.match(R(C.period)); return m ? m[1] : ''; }

    // Aggregate/analytic commission questions never launch the widget; they go to chat.
    var commAggregate = /\bcommissions?\b/.test(lower) && R(AP.commAggregate).test(lower);

    for (var i = 0; i < AP.launch.length; i++) {
      var row = AP.launch[i];
      var re = R(row.re);
      if (!re.test(lower)) continue;
      if (commAggregate && row.ids.indexOf('commission-calculator') !== -1) return null;
      // A launch verb, OR (rows that allow it) the ask starts with the app word ("schedule" typed alone).
      var startsWithApp = !row.require_verb && re.test(lower.slice(0, 14));
      if (!hasVerb && !startsWithApp) continue;
      var visible = (typeof getVisibleApps === 'function') ? getVisibleApps() : (zdzData.apps || []);
      var appId = null;
      for (var k = 0; k < row.ids.length; k++) {
        if (visible.some(function (a) { return a.id === row.ids[k]; })) { appId = row.ids[k]; break; }
      }
      if (!appId) continue; // app not installed/visible → let it fall through
      var appCfg = visible.filter(function (a) { return a.id === appId; })[0] || {};
      var appName = appCfg.nm || appId;
      var ctxVal = row.ctx === 'for' ? grabFor(q) : (row.ctx === 'period' ? grabPeriod(q) : '');
      var opts = { source: 'orchestrator' };
      var suffix = '';
      if (ctxVal && row.key) { opts[row.key] = ctxVal; suffix = row.ctx === 'for' ? ' for ' + ctxVal : ' — ' + ctxVal; }
      return {
        kind: 'shell',
        name: row.verb + ' ' + appName + suffix,
        desc: 'Jumps to ' + appName + ' on your dashboard',
        icon: appCfg.icon || row.icon,
        color: appCfg.cc || row.cc,
        appId: appId, ink: appCfg.ink, // the plate is drawn with the group's ink (white on orange was 2:1)
        run: (function (id, o) {
          return function () { try { Haptics.tap(); } catch (e) {} openApp(id, o); };
        })(appId, opts)
      };
    }
    return null;
  }

  function renderCmdResults(q) {
    // drop secondary surfaces (springboard:false) from the palette —
    // both the empty-query quick-launch list and the fuzzy name/desc matches
    // below read from this one array.
    var allApps = (zdzData.apps || []).filter(function (a) { return !isSecondarySurface(a); });
    var query = q.toLowerCase().trim();

    // ── Empty query: show all apps as quick-launch ──
    if (!query) {
      var display = allApps.slice(0, 10);
      results.innerHTML = display.map(function (a) {
        return '<button class="cmd-item" onclick="closeCmdPalette();openApp(\'' + a.id + '\')">' +
          '<div class="ci-icon" ' + zdzPlate(a) + '><i data-lucide="' + a.icon + '"></i></div>' +
          '<div class="ci-text"><div class="ci-name">' + a.nm + '</div><div class="ci-desc">' + (a.desc || '') + '</div></div>' +
          '<span class="ci-shortcut">' + (a.slash || '') + '</span></button>';
      }).join('');
      refreshIcons();
      return;
    }

    var html = '';

    // ── ALWAYS show "Ask the assistant" as the primary action ──
    // Guard against Analytics (sales-analytics) being inactive.
    // Check the REGISTERED apps (zdzData.apps), not getVisibleApps().
    // Analytics is a secondary surface (springboard:false) so it no longer shows
    // as a top-bar icon — but it IS still registered/active and must remain
    // reachable from search as "Ask the assistant". Keying on getVisibleApps() would
    // silently drop the row once Analytics left the springboard.
    var hasAssistant = (zdzData.apps || []).some(function(a) {
      return a && (a.id === 'sales-analytics' || a.id === 'zdz-sales-analytics');
    });
    // (cross-app orchestrator): consult the deterministic router first.
    // A 'shell' action (e.g. open Camera w/ a pre-label) becomes its OWN primary
    // row and runs instantly. A 'route' relabels the assistant row and carries an
    // orchestrator_hint. Anything else falls through to the plain "Ask the assistant".
    var route = zdzOrchestratorRoute(q.trim());

    if (route && route.kind === 'inline') {
      // Inline-answer row: clicking (or Enter) fetches the result and renders a
      // compact card IN the palette, instead of opening the full chat.
      window.__zdzPendingInline = route;
      html += '<button class="cmd-item cmd-item-analytics" onclick="zdzRunInline()">' +
        '<div class="ci-icon" ' + zdzPlate({ id: route.appId || '', cc: route.color, ink: route.ink }) + '><i data-lucide="' + route.icon + '"></i></div>' +
        '<div class="ci-text"><div class="ci-name">' + escapeHtml(route.name) + '</div><div class="ci-desc">' + escapeHtml(route.desc) + '</div></div>' +
        '<span class="ci-shortcut" style="font-size:11px;color:var(--sys-text-sec)">⏎</span></button>' +
        '<div id="zdz-inline-answer"></div>';
    } else if (route && route.kind === 'shell') {
      // Stash the action so the inline onclick can call it without serializing.
      window.__zdzPendingShellAction = route.run;
      html += '<button class="cmd-item cmd-item-analytics" onclick="closeCmdPalette();(window.__zdzPendingShellAction&&window.__zdzPendingShellAction())">' +
        '<div class="ci-icon" ' + zdzPlate({ id: route.appId || '', cc: route.color, ink: route.ink }) + '><i data-lucide="' + route.icon + '"></i></div>' +
        '<div class="ci-text"><div class="ci-name">' + escapeHtml(route.name) + '</div><div class="ci-desc">' + escapeHtml(route.desc) + '</div></div>' +
        '<span class="ci-shortcut" style="font-size:11px;color:var(--sys-text-sec)">⏎</span></button>';
    }
    // An action row only opens an app, so the chat row stays under it.
    if (hasAssistant && !(route && (route.kind === 'inline' || (route.kind === 'shell' && !route.action)))) {
      var truncated = q.trim().length > 50 ? q.trim().substring(0, 50) + '…' : q.trim();

      // Default the assistant row.
      var biName = 'Ask the assistant';
      var biIcon = 'sparkles';
      var biDesc = '"' + truncated + '"';
      var biOptions = { prompt: q.trim() };

      // A recognized 'route' relabels the row + attaches the structured hint.
      if (route && route.kind === 'route') {
        biName    = route.name;
        biIcon    = route.icon;
        biDesc    = route.desc;
        biOptions = route.options;
      }

      // Stash options so the inline onclick passes the hint through to the app.
      window.__zdzPendingAskOptions = biOptions;
      // Where a quick answer appears (above the chat row), for a question the ledger can answer.
      // not under an action row, so Enter opens the app at once (a report never gets an action row: ANALYTIC).
      if (!(route && route.action)) html += '<div id="zdz-quick-answer" aria-live="polite"></div>';
      html += '<button class="cmd-item cmd-item-analytics" onclick="closeCmdPalette();openApp(\'sales-analytics\',window.__zdzPendingAskOptions||{})">' +
        '<div class="ci-icon" style="background:#6366F1"><i data-lucide="' + biIcon + '"></i></div>' +
        '<div class="ci-text"><div class="ci-name">' + escapeHtml(biName) + '</div><div class="ci-desc">' + escapeHtml(biDesc) + '</div></div>' +
        '<span class="ci-shortcut" style="font-size:11px;color:#6366F1">⏎</span></button>';
    }

    // ── 1. App name matches (fuzzy) ──
    var appMatches = allApps.map(function (a) {
      var nameScore = fuzzyMatch(query, a.nm);
      if (typeof a.snm === 'string' && a.snm) nameScore = Math.max(nameScore, fuzzyMatch(query, a.snm));
      var descScore = a.desc ? fuzzyMatch(query, a.desc) * 0.5 : 0;
      var aliasScore = 0;
      if (a.aliases) {
        a.aliases.forEach(function (al) {
          aliasScore = Math.max(aliasScore, fuzzyMatch(query, al));
        });
      }
      return { app: a, score: Math.max(nameScore, descScore, aliasScore) };
    }).filter(function (m) { return m.score >= 0.5; })
      .sort(function (a, b) { return b.score - a.score; });

    if (appMatches.length) {
      html += appMatches.map(function (m) {
        return '<button class="cmd-item" onclick="closeCmdPalette();openApp(\'' + m.app.id + '\')">' +
          '<div class="ci-icon" ' + zdzPlate(m.app) + '><i data-lucide="' + m.app.icon + '"></i></div>' +
          '<div class="ci-text"><div class="ci-name">' + m.app.nm + '</div><div class="ci-desc">' + (m.app.desc || '') + '</div></div></button>';
      }).join('');
    }

    // ── 2. App matches follow (already rendered above) ──
    // The assistant row is always first, app matches follow.

    results.innerHTML = html;
    refreshIcons();
    // look ahead for a quick answer once typing pauses (or show the one already fetched).
    if (typeof zdzQaUpdate === 'function') { zdzQaUpdate(q); }
  }
}

// ---- INLINE ORCHESTRATOR ANSWER (dashboard "ask" field) ----
// The SERVER is the authority. We send the raw query to /zorderz/v1/orchestrate, which
// classifies it (deterministic, Poe-free) and — for the read verbs — returns the
// resolved data in the same call. This makes phrasing robustness a server concern,
// not a JS-regex guess: even if the palette row label guessed wrong, the action
// reflects what the server actually found. 'chat' (or any miss) hands off to the
// full assistant. Contact/lookup render as compact cards with an "Open in chat →".
// INSTANT SKELETON. The client detector already knows the verb,
// subject and period before the server replies, so we paint a real card frame
// immediately (subject + period + a shimmering figure placeholder) instead of a
// blank "Looking that up…". On a cache hit the server returns in ~50ms and the
// skeleton is barely seen; on a cold FreshBooks call the box still feels alive.
function zdzInlineSkeleton(route) {
  var hint = (route && route.options && route.options.orchestrator_hint) || {};
  var verb = (route && route.verb) || hint.verb || '';
  var esc = (typeof escapeHtml === 'function') ? escapeHtml : function (x){ return String(x==null?'':x); };
  if (verb === 'commission') {
    var subj = hint.subject && hint.subject !== 'my' ? hint.subject : 'Your';
    var who  = (subj === 'Your') ? 'Your commission' : (subj + '\u2019s commission');
    var per  = hint.period ? (' \u00b7 ' + esc(hint.period)) : '';
    return '<div class="zdz-inline-card zdz-cc-card zdz-inline-skel" aria-busy="true">' +
      '<div class="zdz-cc-head">' +
        '<span class="zdz-cc-amount zdz-shimmer">$\u2009\u2009\u2009\u2009</span>' +
        '<span class="zdz-cc-who">' + esc(who) + per + '</span>' +
      '</div>' +
      '<div class="zdz-cc-basis zdz-shimmer-line"></div>' +
    '</div>';
  }
  if (verb === 'contact') {
    var nm = (route && route.query) ? esc(route.query) : 'Contact';
    return '<div class="zdz-inline-card zdz-contact-card zdz-inline-skel" aria-busy="true">' +
      '<div class="zdz-ct-name">' + nm + '</div>' +
      '<div class="zdz-ct-row zdz-shimmer-line"></div>' +
      '<div class="zdz-ct-row zdz-shimmer-line"></div>' +
    '</div>';
  }
  if (verb === 'lookup' || verb === 'stock') {
    return '<div class="zdz-inline-card zdz-inline-skel" aria-busy="true">' +
      '<div class="zdz-ct-row zdz-shimmer-line"></div>' +
      '<div class="zdz-ct-row zdz-shimmer-line"></div>' +
      '<div class="zdz-ct-row zdz-shimmer-line"></div>' +
    '</div>';
  }
  return '<div class="zdz-inline-card zdz-inline-loading">Looking that up\u2026</div>';
}

function zdzRunInline() {
  var route = window.__zdzPendingInline;
  if (!route) return;
  var mount = document.getElementById('zdz-inline-answer');
  if (!mount) return;

  mount.innerHTML = zdzInlineSkeleton(route);

  var q = (route.query && route.rawQuery) ? route.rawQuery : (route.rawQuery || route.query || '');
  var url = zdzData.apiUrl + 'orchestrate?query=' + encodeURIComponent(q);
  fetch(url, { headers: { 'X-WP-Nonce': zdzData.nonce } })
    .then(function (r) { return r.json(); })
    .then(function (res) {
      if (!res || res.route !== 'inline') {
        // Server says this isn't an inline answer → open the full chat.
        mount.innerHTML = '';
        zdzInlineHandoff(route);
        return;
      }
      // stash any secondary-intent hint on the route so every renderer
      // can surface the "+ also: …" chip uniformly (contact gets res.result, not
      // the outer res, so it would otherwise miss res.secondary).
      if (route && typeof route === 'object') route.secondary = res.secondary || null;
      // where "Open in …" goes, and with what, as the server resolved it.
      if (route && typeof route === 'object') { route.app = res.app || ''; route.launch = res.launch_options || null; }
      if (res.verb === 'contact') {
        renderInlineContact(mount, res.result || {}, route);
      } else if (res.verb === 'lookup') {
        renderInlineDocs(mount, res, route);
      } else if (res.verb === 'commission') {
        renderInlineCommission(mount, res, route);
      } else if (res.verb === 'stock') {
        renderInlineStock(mount, res, route);
      } else if (res.verb === 'answer') {
        zdzQaRender(mount, res, q); // a quick answer from stored data
      } else {
        mount.innerHTML = '';
        zdzInlineHandoff(route);
      }
    })
    .catch(function () {
      // Network/endpoint failure → graceful handoff to the full chat.
      mount.innerHTML = '';
      zdzInlineHandoff(route);
    });
}

// ---- QUICK ANSWERS under the ask bar ----
// A question asked in the home ask bar that the stored data can answer quickly gets its answer right underneath.
// While the person types a question that sounds like one (sales, jobs, estimates, owed, customers, leads, sizes), the
// bar asks /zorderz/v1/orchestrate?mode=answer once typing pauses. The Analytics app answers it by code from its ledger
// or says it is not one of its own; an answer is drawn above the chat row with "⚡ Instant · from our records" and
// "Explore more →". Enter on a question with an answer keeps the answer; on one without, the
// chat opens as before. Answers are remembered two minutes per wording (never across people: the page is one person's).
// Also the words of the questions ask.quick answers from Team Roles and Hours ("who installs …?", "is anyone trained
// on …", "my hours"). A business adds its own trade's words on the server (zdzData.askHint, built from a word list).
var ZDZ_QA_HINT = /\b(sales?|sold|sell|selling|revenue|billed|invoic\w*|jobs?|estimates?|quotes?|owed|owes?|outstanding|receivables?|customers?|clients?|leads?|sizes?|dimensions?|average|ticket|accepted|towns?|bought|spent|who|can|trained|install(?:s|ed|er|ers|ing)?|hours?)\b/i;
// A business's own words that also sound like a question for the ledger (its trade's items), served as one pattern.
var ZDZ_QA_HINT_MORE = (function () {
  try {
    var src = typeof zdzData !== 'undefined' && zdzData.askHint;
    return src ? new RegExp(String(src), 'i') : null;
  } catch (e) { return null; }
})();
var ZDZ_QA_TTL = 120000;
function zdzQaState() {
  if (!window.__zdzQA) { window.__zdzQA = { cache: {}, order: [], timer: 0, inflight: null, shownFor: '' }; }
  return window.__zdzQA;
}
function zdzQaKey(q) { return String(q == null ? '' : q).replace(/[\s\u00A0]+/g, ' ').trim().toLowerCase(); }
function zdzQaEligible(q) { var k = zdzQaKey(q); return k.length >= 8 && k.length <= 400 && (ZDZ_QA_HINT.test(k) || !!(ZDZ_QA_HINT_MORE && ZDZ_QA_HINT_MORE.test(k))); }
function zdzQaCached(k) {
  var hit = zdzQaState().cache[k];
  return (hit && (Date.now() - hit.t) < ZDZ_QA_TTL) ? hit : null;
}
function zdzQaFetch(q) {
  var S = zdzQaState(), k = zdzQaKey(q);
  var hit = zdzQaCached(k);
  if (hit) { return Promise.resolve(hit); }
  if (S.inflight && S.inflight.k === k) { return S.inflight.p; }
  if (typeof zdzData === 'undefined' || !zdzData.apiUrl) { return Promise.resolve({ miss: true, t: Date.now() }); }
  var p = fetch(zdzData.apiUrl + 'orchestrate?mode=answer&query=' + encodeURIComponent(String(q).trim()), { headers: { 'X-WP-Nonce': zdzData.nonce } })
    .then(function (r) { return r.json(); })
    .then(function (res) {
      var ok = res && res.route === 'inline' && res.verb === 'answer' && res.result && res.result.html;
      var entry = ok ? { res: res, t: Date.now() } : { miss: true, t: Date.now() };
      S.cache[k] = entry; S.order.push(k);
      while (S.order.length > 40) { delete S.cache[S.order.shift()]; }
      return entry;
    })
    .catch(function () { return { miss: true, error: true, t: Date.now() }; })
    .then(function (entry) { if (S.inflight && S.inflight.k === k) { S.inflight = null; } return entry; });
  S.inflight = { k: k, p: p };
  return p;
}
function zdzQaCurrent() { var i = document.getElementById('cmd-input'); return i ? i.value : ''; }
function zdzQaUpdate(q) {
  var S = zdzQaState(), k = zdzQaKey(q);
  clearTimeout(S.timer);
  if (!document.getElementById('zdz-quick-answer') || !zdzQaEligible(q)) { return; }
  var hit = zdzQaCached(k);
  if (hit) { if (!hit.miss) { zdzQaRender(document.getElementById('zdz-quick-answer'), hit.res, q); } return; }
  S.timer = setTimeout(function () {
    zdzQaFetch(q).then(function (entry) {
      var mount = document.getElementById('zdz-quick-answer');
      if (!mount || zdzQaKey(zdzQaCurrent()) !== k || !entry || entry.miss) { return; }
      zdzQaRender(mount, entry.res, q);
    });
  }, 380);
}
// Enter: true when the quick answer handles it (shown, or fetched now); false lets the bar do what it always did.
function zdzQaEnter(q, results) {
  var mount = document.getElementById('zdz-quick-answer');
  if (!mount || !zdzQaEligible(q)) { return false; }
  var S = zdzQaState(), k = zdzQaKey(q);
  clearTimeout(S.timer);
  var hit = zdzQaCached(k);
  if (hit && hit.miss) { return false; }
  if (hit) { zdzQaRender(mount, hit.res, q); zdzQaNudge(mount); if (typeof zdzTrack === 'function') zdzTrack('command_palette', 'quick_answer'); return true; }   // the kind only
  if (S.entering === k) { return true; } // Enter again while it is looking: one look, one chat at most
  S.entering = k;
  mount.innerHTML = '<div class="zdz-inline-card zdz-qa-card zdz-inline-skel" aria-busy="true"><div class="zdz-qa-badge">\u26A1 Looking in our records\u2026</div>' +
    '<div class="zdz-ct-row zdz-shimmer-line"></div><div class="zdz-ct-row zdz-shimmer-line"></div></div>';
  var done = false;
  var toChat = function () {
    if (done) { return; } done = true;
    if (S.entering === k) { S.entering = ''; }
    mount.innerHTML = '';
    var row = results && results.querySelector('.cmd-item');
    if (row && zdzQaKey(zdzQaCurrent()) === k) { row.click(); }
  };
  var giveUp = setTimeout(toChat, 2500); // never leave the person waiting on a look-ahead
  zdzQaFetch(q).then(function (entry) {
    if (done) { return; }
    clearTimeout(giveUp);
    if (entry && !entry.miss) {
      done = true;
      if (S.entering === k) { S.entering = ''; }
      // drawn only while the field still asks this question
      var m = document.getElementById('zdz-quick-answer');
      if (m && zdzQaKey(zdzQaCurrent()) === k) { zdzQaRender(m, entry.res, q); if (typeof zdzTrack === 'function') zdzTrack('command_palette', 'quick_answer'); }
      else if (m && /Looking in our records/.test(m.innerHTML)) { m.innerHTML = ''; } // round 2: only its own placeholder, never a newer answer
      return;
    }
    toChat();
  });
  return true;
}
function zdzQaNudge(mount) {
  var card = mount && mount.querySelector('.zdz-qa-card');
  if (!card) { return; }
  card.classList.remove('zdz-qa-nudge'); void card.offsetWidth; card.classList.add('zdz-qa-nudge');
}
// The server's HTML is built by the Analytics app from its own markdown, every character of content escaped there
// (ZANA_Quick_Answers::html: p, strong, em, ul/li, table/tr/th/td only).
function zdzQaRender(mount, res, q) {
  var r = (res && res.result) || {};
  if (!mount || !r.html) { return; }
  zdzQaState().shownFor = zdzQaKey(q);
  mount.innerHTML =
    '<div class="zdz-inline-card zdz-qa-card" role="region" aria-label="Instant answer">' +
      '<div class="zdz-qa-badge"><span aria-hidden="true">\u26A1</span> Instant \u00b7 from our records</div>' +
      '<div class="zdz-qa-body">' + r.html + '</div>' +
      // the card is the answer in two lines; "Explore more" opens Analytics with the full answer
      '<button type="button" class="zdz-inline-chat" onclick="zdzQaOpenChat()">Explore more \u2192</button>' +
    '</div>';
}
function zdzQaOpenChat() {
  var q = String(zdzQaCurrent() || '').trim();
  closeCmdPalette();
  openApp('sales-analytics', { prompt: q });
}

// Open the full Analytics chat with the original prompt (the handoff path).
function zdzInlineHandoff(route) {
  closeCmdPalette();
  openApp('sales-analytics', (route && route.options) || {});
}

// run the SECONDARY intent of a compound ask in place. classify()
// resolves only the first intent; when it also reports a `secondary` hint
// ("Devin's phone and Jordan's commission" → primary commission + secondary
// contact), the card shows a "+ also: …" chip that re-runs the inline answer for
// the second half here — so the dropped intent is one tap away instead of lost.
function zdzRunInlineSecondary(secQuery) {
  if (!secQuery) return;
  // Reflect it in the search box (if open) so the user sees what's being shown,
  // then re-run the inline pipeline against the secondary text.
  try {
    var input = document.getElementById('cmd-input');
    if (input) input.value = secQuery;
  } catch (e) {}
  window.__zdzPendingInline = { query: secQuery, rawQuery: secQuery, options: { prompt: secQuery } };
  zdzRunInline();
}

// Build the "+ also: <label>" chip HTML from a classify() `secondary` hint, or ''.
function zdzSecondaryChip(secondary) {
  if (!secondary || !secondary.query || !secondary.verb) return '';
  var esc = (typeof escapeHtml === 'function') ? escapeHtml : function (s) { return String(s == null ? '' : s); };
  var verbLabel = secondary.verb === 'commission' ? 'commission'
                : secondary.verb === 'contact' ? 'contact'
                : secondary.verb === 'lookup' ? 'documents' : 'more';
  // Encode the query for a safe inline onclick (single-quoted JS string arg).
  var safeArg = String(secondary.query).replace(/\\/g, '\\\\').replace(/'/g, "\\'");
  return '<button class="zdz-inline-chip zdz-inline-more" onclick="zdzRunInlineSecondary(\'' + safeArg + '\')">' +
           '+ also: ' + esc(secondary.query) + ' (' + verbLabel + ') →' +
         '</button>';
}

// Render a document-lookup result (estimates/invoices) inline. A single doc shows
// a compact summary; several show a short list. Either way, "Open in chat →" hands
// off to the full Analytics view where the interactive estimate cards live.
function renderInlineDocs(mount, res, route) {
  var esc = (typeof escapeHtml === 'function') ? escapeHtml : function (s) { return String(s == null ? '' : s); };
  var r = res.result || {};
  if (r.error || r.needs_clarify || res.render === 'message') {
    var msg = r.message || r.error || 'No open estimates or invoices found.';
    mount.innerHTML =
      '<div class="zdz-inline-card">' +
        '<div class="zdz-inline-msg">' + esc(msg) + '</div>' +
        '<button class="zdz-inline-chat" onclick="zdzInlineHandoff(window.__zdzPendingInline)">Open in chat →</button>' +
      '</div>';
    return;
  }
  var docs = r.documents || [];
  var name = r.customer_name || (route && route.query) || 'that customer';
  var rows = docs.slice(0, 6).map(function (d) {
    var num = d.estimate_num || d.invoice_num || d.number || '';
    var status = d.status_text || d.status || '';
    var total = (d.total_hidden ? '' : (d.total != null ? ('$' + d.total) : ''));
    var label = (d.doc_type ? (d.doc_type.charAt(0).toUpperCase() + d.doc_type.slice(1)) : 'Doc') + (num ? ' #' + num : '');
    var meta = [status, total].filter(Boolean).join(' · ');
    return '<div class="zdz-inline-row"><span class="zdz-inline-ic">📄</span>' + esc(label) + (meta ? ' — ' + esc(meta) : '') + '</div>';
  }).join('');

  // CLOSEST-MATCH BANNER — the document card resolves the customer
  // through ZEST_ZANA_Bridge, which (like the contact bridge) can accept a match
  // on a shared last name when the asked first name matched nobody (live:
  // "estimates for Sam Rivera" returned Casey Rivera's docs). When the bridge
  // flags closest_match, say so instead of presenting the wrong customer's
  // documents as exact.
  var askedBanner = '';
  if (r.closest_match && r.asked) {
    askedBanner =
      '<div class="zdz-inline-note" style="color:var(--sys-warn,#b45309);">' +
        'No exact match for "' + esc(r.asked) + '" — showing the closest customer, ' +
        esc(name) + '. Open in chat to search more.' +
      '</div>';
  }
  // "+ also: …" chip for a compound ask's second intent (route carries it).
  var moreChip = zdzSecondaryChip(route && route.secondary);

  mount.innerHTML =
    '<div class="zdz-inline-card">' +
      '<div class="zdz-inline-name">' + esc(name) + ' — open documents</div>' +
      askedBanner +
      rows +
      '<button class="zdz-inline-chat" onclick="zdzInlineHandoff(window.__zdzPendingInline)">Open in chat →</button>' +
      moreChip +
    '</div>';
}

// A stock answer inline: each matching item and how many are on hand. Quantities only. "Open in Stock" only for
// someone who has the Stock app.
function renderInlineStock(mount, res, route) {
  var esc = (typeof escapeHtml === 'function') ? escapeHtml : function (s) { return String(s == null ? '' : s); };
  var r = res.result || {};
  if (r.denied || !r.items || !r.items.length) {
    mount.innerHTML = '<div class="zdz-inline-card"><div class="zdz-inline-msg">' + esc(r.message || 'Nothing in the stock list matches.') + '</div></div>';
    return;
  }
  var words = { out: 'out', critical: 'very low', low: 'low', good: '' };
  var rows = r.items.map(function (it) {
    var st = words[it.status] || '';
    return '<div class="zdz-inline-row"><span class="zdz-inline-ic">📦</span>' + esc(it.name) + ' — <strong>' +
      esc(String(it.on_hand)) + '</strong> ' + esc(it.unit || '') + (st ? ' · ' + esc(st) : '') + '</div>';
  }).join('');
  var more = r.more ? '<div class="zdz-inline-note">and ' + esc(String(r.more)) + ' more</div>' : '';
  var hasApp = (typeof getVisibleApps === 'function') && getVisibleApps().some(function (a) { return a.id === 'stock'; });
  var open = hasApp ? '<button class="zdz-inline-chip" onclick="zdzStockOpenWidget()">Open in Stock ↗</button>' : '';
  mount.innerHTML = '<div class="zdz-inline-card"><div class="zdz-inline-name">On hand: ' + esc((route && route.query) || r.query || '') + '</div>' +
    rows + more + open + '</div>';
}
function zdzStockOpenWidget() {
  var route = window.__zdzPendingInline || {};
  var q = (route.launch && route.launch.query) || route.query || '';
  closeCmdPalette();
  openApp('stock', { source: 'orchestrator', query: q });
}

// Render a commission result inline — headline figure up front, with
// collapsible drill-down (jobs counted, rate basis, by-product split). The full
// detail is CONTAINED but collapsed for a fast, scannable answer. Denied/kiosk
// and clarify states show the bridge's friendly message + a chat handoff.
function renderInlineCommission(mount, res, route) {
  var esc = (typeof escapeHtml === 'function') ? escapeHtml : function (s) { return String(s == null ? '' : s); };
  var r = res.result || {};
  function money(n) { return '$' + Number(n || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }

  // Non-figure states (denied on kiosk, error, no-match) → message + handoff.
  // ALSO treat "no amount produced" as a non-figure state. The bridge
  // returns success:true WITHOUT an `amount` key when the named person isn't a
  // salesperson with a commission profile (it sets `message` instead) — see
  // ZCC_ZANA_Bridge::commission_calc_for_zana. Previously this fell through to the
  // card and money(undefined) rendered a misleading "$0.00 · Quinn", reading as
  // "earned $0" rather than "not a known rep". A real rep with genuinely zero
  // commission DOES carry amount:0, so this only catches the not-found case.
  var hasAmount = (r.amount !== null && r.amount !== undefined);
  if (res.render === 'denied' || res.render === 'error' || res.render === 'clarify' || !r.success || !hasAmount) {
    var msg = r.message || r.error || 'I couldn’t pull that commission figure.';
    mount.innerHTML =
      '<div class="zdz-inline-card">' +
        '<div class="zdz-inline-msg">' + esc(msg) + '</div>' +
        '<button class="zdz-inline-chat" onclick="zdzInlineHandoff(window.__zdzPendingInline)">Ask in chat →</button>' +
      '</div>';
    return;
  }

  var b = r.breakdown || {};
  var subject = r.subject || (route && route.query) || 'Salesperson';
  var period = r.period || '';

  // Build the collapsible sections (only those with content).
  var sections = '';

  // 1) Jobs counted.
  var jobs = b.jobs || [];
  if (jobs.length) {
    var jobRows = jobs.slice(0, 12).map(function (j) {
      var head = (j.number ? '#' + esc(j.number) : 'Job') + (j.customer ? ' · ' + esc(j.customer) : '');
      var right = money(j.commission);
      return '<div class="zdz-cc-row"><span class="zdz-cc-row-l">' + head + '</span><span class="zdz-cc-row-r">' + right + '</span></div>';
    }).join('');
    sections += zdzCcSection('jobs', 'Jobs counted', '(' + (b.job_count || jobs.length) + ')', jobRows);
  }

  // 2) Rate basis.
  var basisBits = [];
  if (b.rate) basisBits.push('<div class="zdz-cc-row"><span class="zdz-cc-row-l">Rate</span><span class="zdz-cc-row-r">' + esc(b.rate) + '</span></div>');
  if (b.net != null) basisBits.push('<div class="zdz-cc-row"><span class="zdz-cc-row-l">Net commissionable</span><span class="zdz-cc-row-r">' + money(b.net) + '</span></div>');
  if (b.invoice_count != null) basisBits.push('<div class="zdz-cc-row"><span class="zdz-cc-row-l">Invoices</span><span class="zdz-cc-row-r">' + esc(String(b.invoice_count)) + '</span></div>');
  if (basisBits.length) sections += zdzCcSection('basis', 'How it’s calculated', '', basisBits.join(''));

  // 3) By product line.
  var prod = b.product_split || [];
  if (prod.length) {
    var prodRows = prod.map(function (p) {
      return '<div class="zdz-cc-row"><span class="zdz-cc-row-l">' + esc(p.label) + '</span><span class="zdz-cc-row-r">' + money(p.net) + ' net</span></div>';
    }).join('');
    sections += zdzCcSection('products', 'By product line', '', prodRows);
  }

  mount.innerHTML =
    '<div class="zdz-inline-card zdz-cc-card">' +
      '<div class="zdz-cc-head">' +
        '<span class="zdz-cc-amount">' + money(r.amount) + '</span>' +
        '<span class="zdz-cc-who">' + esc(subject) + (period ? ' · ' + esc(period) : '') + '</span>' +
      '</div>' +
      (r.basis ? '<div class="zdz-cc-basis">' + esc(r.basis) + '</div>' : '') +
      sections +
      // show data recency when this came from the 10-min cache so the
      // figure is trusted; a live (just-computed) figure shows nothing.
      (r.cached && r.cached_at ? '<div class="zdz-cc-fresh"><span class="zdz-cc-dot">\u25CF</span> Updated ' + esc(r.cached_at) + '</div>' : '') +
      '<div class="zdz-cc-actions">' +
        '<button class="zdz-inline-chip" onclick="zdzCommissionOpenWidget()">Open in Commission ↗</button>' +
        '<button class="zdz-inline-chip" onclick="zdzInlineHandoff(window.__zdzPendingInline)">Ask in chat ↗</button>' +
        zdzSecondaryChip(res.secondary) +
      '</div>' +
    '</div>';

  // Wire the collapsible section toggles.
  Array.prototype.forEach.call(mount.querySelectorAll('.zdz-cc-sec-head'), function (h) {
    h.addEventListener('click', function () {
      var sec = h.parentNode;
      sec.classList.toggle('is-open');
    });
  });

  // SMART AUTO-EXPAND. The card defaults to all-collapsed (just the
  // headline), but if the request explicitly asked about a section, open ONLY
  // that one. focus comes from the client route (route.focus) or, if the server
  // forwarded it, res.focus / the orchestrator hint.
  var focus = (route && route.focus)
    || (res && res.focus)
    || (route && route.options && route.options.orchestrator_hint && route.options.orchestrator_hint.focus)
    || '';
  if (focus) {
    var target = mount.querySelector('.zdz-cc-sec[data-sec="' + focus + '"]');
    if (target) target.classList.add('is-open');
  }
}

// One collapsible section: a tappable header (title + optional count + caret)
// and a hidden body that expands on click.
function zdzCcSection(key, title, count, bodyHtml) {
  var esc = (typeof escapeHtml === 'function') ? escapeHtml : function (s) { return String(s == null ? '' : s); };
  return '<div class="zdz-cc-sec" data-sec="' + key + '">' +
    '<button type="button" class="zdz-cc-sec-head">' +
      '<span class="zdz-cc-sec-title">' + esc(title) + (count ? ' <span class="zdz-cc-sec-count">' + esc(count) + '</span>' : '') + '</span>' +
      '<span class="zdz-cc-caret">▸</span>' +
    '</button>' +
    '<div class="zdz-cc-sec-body">' + bodyHtml + '</div>' +
  '</div>';
}

// Open the full Commission widget from the inline card (carries the prompt so
// the widget/orchestrator hint can pre-focus the salesperson + period).
function zdzCommissionOpenWidget() {
  var route = window.__zdzPendingInline || {};
  var opts = { source: 'orchestrator' };
  var hint = (route.options && route.options.orchestrator_hint) || {};
  if (hint.subject) opts.salesperson = hint.subject;
  if (hint.period) opts.period = hint.period;
  // the person and the dates the server resolved, so Commission opens on this figure.
  var L = route.launch || null;
  if (L && L.user_id) { opts.user_id = L.user_id; opts.date_start = L.date_start || ''; opts.date_end = L.date_end || ''; }
  closeCmdPalette();
  openApp('commission-calculator', opts);
}

function renderInlineContact(mount, res, route) {
  if (!res || typeof res !== 'object') { mount.innerHTML = ''; zdzInlineHandoff(route); return; }

  var esc = (typeof escapeHtml === 'function') ? escapeHtml : function (s) { return String(s == null ? '' : s); };
  var render = res.render || '';

  // Error / clarify / denied → short message, with a chat handoff to dig deeper.
  if (render === 'error' || render === 'clarify' || render === 'denied' || render === 'message') {
    var msg = res.message || res.error || 'No contact details available.';
    var nm = (res.contact && res.contact.name) ? res.contact.name : '';
    var loc = (res.contact && res.contact.city) ? res.contact.city : '';
    var sub = (nm ? ('<div class="zdz-inline-sub"><strong>' + esc(nm) + '</strong>' + (loc ? ' — ' + esc(loc) : '') + '</div>') : '');
    mount.innerHTML =
      '<div class="zdz-inline-card">' +
        '<div class="zdz-inline-msg">' + esc(msg) + '</div>' + sub +
        '<button class="zdz-inline-chat" onclick="zdzInlineHandoff(window.__zdzPendingInline)">Open in chat →</button>' +
      '</div>';
    return;
  }

  // Card: a resolved contact with details.
  var c = res.contact || {};
  var rows = '';
  if (c.phone) {
    var tel = String(c.phone).replace(/[^0-9+]/g, '');
    rows += '<a class="zdz-inline-row" href="tel:' + esc(tel) + '"><span class="zdz-inline-ic">📞</span>' + esc(c.phone) + '</a>';
  }
  if (c.email) {
    rows += '<a class="zdz-inline-row" href="mailto:' + esc(c.email) + '"><span class="zdz-inline-ic">✉️</span>' + esc(c.email) + '</a>';
  }
  // v1.6.2: the address / city-fallback row becomes a device-appropriate
  // map link (matching the phone tel: and email mailto: rows above) — but ONLY
  // when non-empty; an empty/whitespace value stays a static <div> with no dead
  // link. Href built by the shared zdzMapsUrl() (hardcoded https + encodeURIComponent),
  // then esc()'d into the attribute; the visible text is esc()'d as before.
  var locText = String((c.address != null ? c.address : (c.city != null ? c.city : ''))).trim();
  if (locText) {
    var mapUrl = zdzMapsUrl(locText);
    rows += mapUrl
      ? '<a class="zdz-inline-row" href="' + esc(mapUrl) + '" target="_blank" rel="noopener"><span class="zdz-inline-ic">📍</span>' + esc(locText) + '</a>'
      : '<div class="zdz-inline-row"><span class="zdz-inline-ic">📍</span>' + esc(locText) + '</div>';
  }

  var header = esc(c.name || 'Contact');
  if (c.company && c.company !== c.name) header += ' <span class="zdz-inline-co">· ' + esc(c.company) + '</span>';

  // CLOSEST-MATCH BANNER — when the bridge resolved a different person
  // than was asked (fuzzy surname / dropped first name), say so up front instead
  // of presenting the wrong contact as exact (live: "who is Sam Rivera" →
  // Casey Rivera). The card still shows the match (it's useful), but labeled.
  var askedBanner = '';
  if (res.closest_match && res.asked) {
    askedBanner =
      '<div class="zdz-inline-note" style="color:var(--sys-warn,#b45309);">' +
        'No exact match for "' + esc(res.asked) + '" — showing the closest contact, ' +
        esc(c.name || 'this customer') + '. Ask chat to search more.' +
      '</div>';
  }

  // A note from the bridge (e.g. "no email on file") and the chat handoff.
  var note = res.message ? '<div class="zdz-inline-note">' + esc(res.message) + '</div>' : '';

  // "+ also: …" chip for a compound ask's second intent (route carries it).
  var moreChip = zdzSecondaryChip(route && route.secondary);

  // the card's actions are Call, Message and Directions; chat is a quiet text link at the
  // end.
  var acts = zdzContactActions(c);
  var first = String(c.name || '').trim().split(/\s+/)[0] || 'them';

  mount.innerHTML =
    '<div class="zdz-inline-card">' +
      '<div class="zdz-inline-name">' + header + '</div>' +
      askedBanner +
      rows + note +
      acts +
      '<button class="zdz-inline-chat zdz-inline-chat--link" onclick="zdzInlineHandoff(window.__zdzPendingInline)">Ask chat about ' + esc(first) + '</button>' +
      moreChip +
    '</div>';
}

// Call / Message / Directions buttons for a contact card. A button appears only when the
// card has what it needs (a phone number for Call and Message, an address or town for Directions).
function zdzDirectionsUrl(addr) {
  var q = encodeURIComponent(String(addr == null ? '' : addr).trim());
  if (!q) return '';
  var ua = (typeof navigator !== 'undefined' && navigator.userAgent) ? navigator.userAgent : '';
  return /iPhone|iPad|iPod|Macintosh|Mac OS X/i.test(ua)
    ? 'https://maps.apple.com/?daddr=' + q
    : 'https://www.google.com/maps/dir/?api=1&destination=' + q;
}
function zdzContactActions(c) {
  c = c || {};
  var esc = (typeof escapeHtml === 'function') ? escapeHtml : function (s) { return String(s == null ? '' : s); };
  var ico = {
    call: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1.9.4 1.8.7 2.7a2 2 0 0 1-.5 2.1L8.1 9.8a16 16 0 0 0 6 6l1.3-1.3a2 2 0 0 1 2.1-.4c.9.3 1.8.6 2.7.7a2 2 0 0 1 1.7 2z"/></svg>',
    msg: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>',
    dir: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><polygon points="3 11 22 2 13 21 11 13 3 11"/></svg>'
  };
  var out = '';
  var tel = c.phone ? String(c.phone).replace(/[^0-9+]/g, '') : '';
  if (tel) {
    out += '<a class="zdz-inline-act" href="tel:' + esc(tel) + '">' + ico.call + 'Call</a>';
    out += '<a class="zdz-inline-act" href="sms:' + esc(tel) + '">' + ico.msg + 'Message</a>';
  }
  var place = c.address || c.city || '';
  var dir = place ? zdzDirectionsUrl(place) : '';
  if (dir) out += '<a class="zdz-inline-act" href="' + esc(dir) + '" target="_blank" rel="noopener">' + ico.dir + 'Directions</a>';
  return out ? '<div class="zdz-inline-actions">' + out + '</div>' : '';
}

// ---- D1: ADD-TO-HOME-SCREEN BANNER ----
function initInstallBanner() {
  // Skip if already running as installed web app
  // Note: iOS 26 defaults all home screen shortcuts to standalone mode,
  // so this check remains valid — if the user is in standalone, they
  // already have it "installed".
  var isStandalone = window.matchMedia('(display-mode: standalone)').matches ||
                     window.navigator.standalone === true;
  if (isStandalone) return;

  // Time-limited dismissal (7 days) instead of permanent
  try {
    var dismissed = localStorage.getItem('zdz_install_dismissed');
    if (dismissed) {
      var dismissedAt = parseInt(dismissed, 10);
      if (dismissedAt && (Date.now() - dismissedAt) < 7 * 24 * 60 * 60 * 1000) {
        return; // Still within 7-day cooldown
      }
      // Expired — remove and re-show
      localStorage.removeItem('zdz_install_dismissed');
    }
  } catch(e) {}

  var banner = document.getElementById('zdz-install-banner');
  if (!banner) return;

  var howBtn = document.getElementById('zdz-install-how');
  var dismissBtn = document.getElementById('zdz-install-dismiss');
  var textEl = banner.querySelector('.zdz-install-text');

  // Detect platform and browser for specific install instructions
  var ua = navigator.userAgent || '';
  var isIOS = /iphone|ipad|ipod/i.test(ua);
  var isSafari = /safari/i.test(ua) && !/chrome|crios|fxios|edgios/i.test(ua);
  var isChrome = /chrome/i.test(ua) && !/edg/i.test(ua);
  var isEdge = /edg/i.test(ua);
  var isFirefox = /firefox|fxios/i.test(ua);
  var isMac = /macintosh/i.test(ua);
  var isAndroid = /android/i.test(ua);

  // Improved copy — emphasize the benefit, not the action
  if (isIOS && isSafari) {
    if (textEl) textEl.textContent = 'Get faster loading & full-screen mode';
    if (howBtn) howBtn.textContent = 'Add to Home Screen';
  } else if (isIOS) {
    // Non-Safari iOS browser — need to open in Safari first
    if (textEl) textEl.textContent = 'Open in Safari to install as a full-screen app';
    if (howBtn) howBtn.textContent = 'Copy Link';
  } else {
    if (textEl) textEl.textContent = 'Install Zorderz for faster loading';
    if (howBtn) howBtn.textContent = 'Install';
  }

  // Show after a brief delay so it doesn't flash on first paint
  setTimeout(function () {
    banner.style.display = 'flex';
  }, 2000);

  if (howBtn) {
    howBtn.addEventListener('click', function () {
      // If we have a deferred prompt (Chrome/Edge), use it for one-click install
      if (window._zdzDeferredPrompt) {
        window._zdzDeferredPrompt.prompt();
        window._zdzDeferredPrompt.userChoice.then(function () {
          banner.style.display = 'none';
          window._zdzDeferredPrompt = null;
        });
        return;
      }
      // iOS Safari — show the visual step-by-step guide overlay
      if (isIOS && isSafari) {
        var guide = document.getElementById('zdz-ios-install-guide');
        if (guide) {
          guide.style.display = 'flex';
          banner.style.display = 'none';

          // Add bounce animation to the guide arrow
          var arrow = document.getElementById('zdz-ios-guide-arrow');
          if (arrow) arrow.classList.add('zdz-arrow-bounce');

          // Close handlers
          var closeBtn = document.getElementById('zdz-ios-guide-close');
          var backdrop = guide.querySelector('.zdz-ios-guide-backdrop');
          function closeGuide() {
            guide.style.display = 'none';
            banner.style.display = 'flex';
            var arrow2 = document.getElementById('zdz-ios-guide-arrow');
            if (arrow2) arrow2.classList.remove('zdz-arrow-bounce');
          }
          if (closeBtn) closeBtn.onclick = closeGuide;
          if (backdrop) backdrop.onclick = closeGuide;
        }
        return;
      }
      // Non-Safari iOS — show dedicated guide overlay with Copy Link
      if (isIOS) {
        var nsGuide = document.getElementById('zdz-nonsafari-guide');
        if (nsGuide) {
          nsGuide.style.display = 'flex';
          banner.style.display = 'none';
          var nsCopy = document.getElementById('zdz-nonsafari-copy');
          var nsClose = document.getElementById('zdz-nonsafari-close');
          var nsBackdrop = document.getElementById('zdz-nonsafari-backdrop');
          function closeNsGuide() {
            nsGuide.style.display = 'none';
            banner.style.display = 'flex';
          }
          if (nsCopy) {
            nsCopy.addEventListener('click', function() {
              try {
                navigator.clipboard.writeText(window.location.origin).then(function() {
                  nsCopy.textContent = '✓ Copied!';
                  nsCopy.style.background = '#059669';
                  setTimeout(function() {
                    nsCopy.textContent = 'Copy Link';
                    nsCopy.style.background = '';
                  }, 2000);
                }).catch(function() {
                  showToast('Open ' + location.hostname + ' in Safari to install', 6000);
                });
              } catch(e) {
                showToast('Open ' + location.hostname + ' in Safari to install', 6000);
              }
            });
          }
          if (nsClose) nsClose.onclick = closeNsGuide;
          if (nsBackdrop) nsBackdrop.onclick = closeNsGuide;
        } else {
          showToast('Open ' + location.hostname + ' in Safari → tap ⋯ → Share → Add to Home Screen', 8000);
        }
        return;
      }
      // Other platforms — show platform-specific instructions
      if (isChrome && !isAndroid) {
        showToast('Look for the install icon (⊕) in the right side of the address bar, or go to Menu (⋮) → "Save and share" → "Install Zorderz"', 8000);
      } else if (isEdge) {
        showToast('Click Menu (⋯) → Apps → "Install Zorderz" — or look for the install icon in the address bar', 7000);
      } else if (isFirefox) {
        showToast('Firefox doesn\'t support app install — use Chrome or Edge for the best experience, or bookmark this page', 7000);
      } else if (isSafari && isMac) {
        showToast('In Safari: File → "Add to Dock" to install Zorderz as a desktop app', 6000);
      } else {
        showToast('Check your browser menu for "Install app" or "Add to Home Screen"', 6000);
      }
    });
  }

  if (dismissBtn) {
    dismissBtn.addEventListener('click', function () {
      banner.style.display = 'none';
      // Store timestamp instead of boolean (7-day cooldown)
      try { localStorage.setItem('zdz_install_dismissed', String(Date.now())); } catch(e) {}
    });
  }

  // Listen for the native install prompt (Chrome/Edge/Android) — enables one-click
  window.addEventListener('beforeinstallprompt', function (e) {
    e.preventDefault();
    window._zdzDeferredPrompt = e;
    if (howBtn) {
      howBtn.textContent = 'Install';
    }
    if (textEl) {
      textEl.textContent = 'Install Zorderz for faster loading';
    }
    // Show banner immediately if it was hidden
    banner.style.display = 'flex';
  });

  // Hide banner if app gets installed
  window.addEventListener('appinstalled', function () {
    banner.style.display = 'none';
    window._zdzDeferredPrompt = null;
    try { localStorage.setItem('zdz_install_dismissed', String(Date.now())); } catch(e) {}
    showToast('✓ Zorderz installed');
  });
}

// ---- PLUGIN CONTRACT ----
// Explicitly expose functions that plugins call via window.*.
// These are already top-level in this script, but explicit assignment
// documents the contract and guards against future module refactoring.
window.switchView    = switchView;
window.refreshIcons  = refreshIcons;
window.openApp       = openApp;
window.showToast     = showToast;
window.applyTheme    = applyTheme;
window.zdzRunInline    = zdzRunInline;     // inline orchestrator answer
window.zdzInlineHandoff = zdzInlineHandoff;
window.zdzMapsUrl       = zdzMapsUrl;       // v1.6.2: shared map-URL helper, reused by chat + jobs surfaces

// ---- INIT ----

/* Boot recovery timer — if zdz-ready hasn't fired after 5 seconds,
 * the skeleton hides and a retry option appears. This catches JS errors,
 * script load failures, and edge cases where the SPA never renders. */
(function () {
  var recoveryTimer = setTimeout(function () {
    if (document.body.classList.contains('zdz-ready')) return;
    // Remove skeleton
    var skel = document.querySelector('.zdz-skeleton');
    if (skel) skel.remove();
    // Show content even in error state
    document.body.classList.add('zdz-ready');
    // Inject recovery UI
    var main = document.getElementById('sv-dash');
    if (main) {
      var recovery = document.createElement('div');
      recovery.style.cssText = 'padding:40px 20px;text-align:center;';
      recovery.innerHTML = '<p style="margin-bottom:16px;color:var(--sys-text-sec,#64748B);">Something went wrong loading the app.</p>'
        + '<button onclick="location.reload()" style="padding:12px 24px;border-radius:8px;background:var(--sys-brand,#2C5F8A);color:#fff;font-weight:600;font-size:17px;cursor:pointer;border:none;">Tap to Retry</button>';
      main.prepend(recovery);
    }
  }, 5000);
  // Clear the timer once boot succeeds
  window.__zdzClearRecovery = function () { clearTimeout(recoveryTimer); };
})();

document.addEventListener('DOMContentLoaded', () => {
  try {
  // Standard nav items (Apps — more may be injected by plugins)
  document.querySelectorAll('.ni').forEach(btn => {
    btn.addEventListener('click', () => switchView(btn.dataset.view));
  });

  // Logo button → Settings
  const logoBtn = document.getElementById('bnav-logo');
  if (logoBtn) {
    logoBtn.addEventListener('click', () => switchView('sv-settings'));
  }

  applyTheme(state.theme);
  zdzSetupFocusHome();      // ask bar, launcher, Today
  renderGreeting();
  renderBuildStamp();      // show the running theme build (stale-shell check)
  renderLeadsTile();       // "N new leads" tile from the unified feed
  renderKioskDemoBanner();  // show persistent demo-mode indicator if active
  renderAppDock();
  initStickyAppBar();
  renderQuickStats();
  fetchKPIMetrics();
  renderRecentApps();
  renderAppGrid();
  zdzForYouStart();         // at work / after hours follows the clock
  renderWidgets();
  renderSettings();
  prefetchTopApps();

  initSearch();
  initCommandPalette();
  initAppViewport();
  initLongPressSunlight();
  initPullToRefresh();
  initInstallBanner();

  window.addEventListener('online', () => { showToast('Back online ✓'); renderSettings(); });
  window.addEventListener('offline', () => { showToast('You are offline'); renderSettings(); });

  // Connection quality indicator — surfaces "Low signal" badge
  // when Network Information API reports a slow effective type so field
  // techs understand why things are slower (cellular at job sites).
  try {
    var conn = navigator.connection || navigator.mozConnection || navigator.webkitConnection;
    if (conn && conn.effectiveType && /^(slow-2g|2g)$/.test(conn.effectiveType)) {
      showToast('Low signal — some features may be slower', 4000);
    }
  } catch(e) {}

  // Listen for system theme changes to update the nav logo
  window.matchMedia('(prefers-color-scheme:dark)').addEventListener('change', () => {
    if (state.theme === 'system') updateNavLogo();
  });

  // Update logo when crossing the sidebar breakpoint (tablet rotation)
  window.matchMedia('(min-width: 820px)').addEventListener('change', () => {
    updateNavLogo();
  });

  document.body.classList.add('zdz-ready');
  if (window.__zdzClearRecovery) window.__zdzClearRecovery();
  refreshIcons();

  // route an external #zana-session=<id> deep link (Analytics digest "Open this
  // chat →") now that the shell + Bridge are ready, and again whenever the hash
  // changes (an installed PWA is reactivated rather than cold-booted on link tap).
  zdzRouteDeepLink();
  window.addEventListener('hashchange', zdzRouteDeepLink);

  // Track the dashboard load and keep the heartbeat while the page is in front (zdzData.track.beat, the server's one
  // rhythm); time open per app, searches without their words, and a batch the last visit could not deliver, once.
  zdzTrack('dashboard_load', 'SPA shell ready');
  var zdzBeatS = (zdzData.track && parseInt(zdzData.track.beat, 10)) || 240;
  setInterval(function () {
    if (document.visibilityState === 'visible') {
      zdzTrack('heartbeat', state.currentView);
    }
  }, Math.max(60, zdzBeatS) * 1000);
  zdzUsageWatchApps();
  zdzUsageWatchSearch();
  setTimeout(zdzTrackResend, 3000);

  } catch (bootErr) {
    // If boot crashes, ensure the user can still see something
    console.error('[Zorderz] Boot failed:', bootErr);
    document.body.classList.add('zdz-ready');
    if (window.__zdzClearRecovery) window.__zdzClearRecovery();
    var dash = document.getElementById('sv-dash');
    if (dash && !dash.querySelector('.zdz-boot-error')) {
      var errDiv = document.createElement('div');
      errDiv.className = 'zdz-boot-error';
      errDiv.style.cssText = 'padding:40px 20px;text-align:center;';
      errDiv.innerHTML = '<p style="margin-bottom:16px;color:var(--sys-text-sec,#64748B);">Something went wrong. Tap to retry.</p>'
        + '<button onclick="location.reload()" style="padding:12px 24px;border-radius:8px;background:var(--sys-brand,#2C5F8A);color:#fff;font-weight:600;font-size:17px;cursor:pointer;border:none;">Retry</button>';
      dash.prepend(errDiv);
    }
  }
});


// ============================================================================
// OAuth return handler — shows a toast and jumps to Settings when
// FreshBooks sends the user back with ?zdz_authorized=freshbooks in the URL.
// ============================================================================
(function () {
  try {
    var qs = new URLSearchParams(window.location.search);
    var flag = qs.get('zdz_authorized');
    var err  = qs.get('zdz_auth_error');
    if (!flag && !err) return;

    qs.delete('zdz_authorized');
    qs.delete('zdz_auth_error');
    var clean = window.location.pathname + (qs.toString() ? ('?' + qs.toString()) : '') + window.location.hash;
    window.history.replaceState({}, '', clean);

    var ran = function () {
      if (err) {
        showToast('FreshBooks authorization failed: ' + err, 8000);
      } else if (flag === 'freshbooks') {
        showToast('✓ FreshBooks authorized successfully');
      } else {
        showToast('✓ ' + flag + ' authorized successfully');
      }
      if (typeof switchView === 'function') switchView('sv-settings');
      if (typeof renderSettings === 'function') renderSettings();
    };
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', ran);
    } else {
      ran();
    }
  } catch (e) { /* no-op */ }
})();


// ── iOS 26 platform hardening ──────────────────────────────────────────
// 1. Ask for durable storage once per boot: without persist(), iOS can evict
//    IndexedDB/CacheStorage for an installed PWA under pressure (camera-queue
//    photos live there). Silent no-op elsewhere.
// 2. Keyboard-dismiss re-sync: iOS 26 sometimes leaves the layout offset
//    after the keyboard closes (visualViewport shrinks-then-grows but the
//    fixed chrome doesn't repaint — the chat-composer misalignment class).
//    When the visual viewport returns to full height, force one layout tick.
(() => {
  try {
    if (navigator.storage && navigator.storage.persist) {
      navigator.storage.persist().catch(() => {});
    }
  } catch (e) { /* no-op */ }
  try {
    if (window.visualViewport) {
      let t = 0;
      window.visualViewport.addEventListener('resize', () => {
        clearTimeout(t);
        t = setTimeout(() => {
          if (Math.abs(window.innerHeight - window.visualViewport.height) < 2) {
            // Keyboard just closed — nudge a reflow so fixed bars re-anchor.
            document.documentElement.style.minHeight = '100.01%';
            requestAnimationFrame(() => { document.documentElement.style.minHeight = ''; });
          }
        }, 120);
      });
    }
  } catch (e) { /* no-op */ }
})();

// viewport check. Open the app with ?zdz_viewport=1 to see the real layout width, the
// zoom iOS applied, the orientation and which layout matched (sidebar or bottom bar). It measures why the
// phone turned on its side showed the portrait layout stretched before any
// landscape layout change is made. Read-only; nothing is stored or sent. Tap the read-out to close it.
(function zdzViewportProbe() {
  try {
    if (!/[?&]zdz_viewport=1\b/.test(window.location.search || '')) return;
    var box = null;
    function q(m) { try { return window.matchMedia(m).matches ? 'yes' : 'no'; } catch (e) { return '?'; } }
    function draw() {
      if (!document.body) return;
      if (!box) {
        box = document.createElement('div');
        box.id = 'zdz-vp-probe';
        box.setAttribute('role', 'status');
        box.addEventListener('click', function () { box.remove(); box = null; });
        document.body.appendChild(box);
      }
      var vv = window.visualViewport || {};
      function sa(side) {
        var d = document.createElement('div');
        d.style.cssText = 'position:fixed;visibility:hidden;padding-' + side + ':env(safe-area-inset-' + side + ',0px)';
        document.body.appendChild(d);
        var v = getComputedStyle(d)['padding' + side.charAt(0).toUpperCase() + side.slice(1)];
        d.remove();
        return v;
      }
      box.textContent =
        'innerWidth ' + window.innerWidth + ' × innerHeight ' + window.innerHeight + '\n' +
        'screen ' + screen.width + ' × ' + screen.height + '  dpr ' + (window.devicePixelRatio || 1) + '\n' +
        'visualViewport ' + Math.round(vv.width || 0) + ' × ' + Math.round(vv.height || 0) + '  scale ' + (vv.scale || 1) + '\n' +
        'orientation ' + ((screen.orientation && screen.orientation.type) || (window.orientation + '°')) + '\n' +
        'sidebar (min-width 820px): ' + q('(min-width: 820px)') + '   standalone: ' + q('(display-mode: standalone)') + '\n' +
        'safe-area L/R/B ' + sa('left') + ' / ' + sa('right') + ' / ' + sa('bottom') + '\n' +
        'Tap to close. Turn the phone and the numbers update.';
    }
    window.addEventListener('resize', draw);
    window.addEventListener('orientationchange', function () { setTimeout(draw, 300); });
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', draw); else draw();
  } catch (e) { /* a diagnostic must never break the app */ }
})();
