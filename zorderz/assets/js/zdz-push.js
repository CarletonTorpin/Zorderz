/* Phone notifications: one switch, in Settings -> Notifications.
 *
 * window.ZDZPush is the ONE routine for turning push on and off on this device. Settings -> Notifications draws it;
 * an app's own bell (Messages, Chat) may call it, so every switch shows the same state.
 *
 *   - The channels come from GET /zorderz/v1/push/setup (ZDZ_Push_Setup): each owning app answers its own
 *     "<app>.push_setup" registry verb. A channel whose owner is not active, or refuses this person, is simply absent.
 *   - The state is read from the DEVICE, never assumed: the permission, each channel's own worker registration (at
 *     exactly its scope), its push subscription, and whether the owner's list of this person's subscriptions holds
 *     that endpoint (compared as SHA-256; no endpoint is sent to read the state).
 *       on | partial | off | blocked | a2hs (iPhone, Safari tab) | ios-old | unsupported | none (no channel for you)
 *   - enable(): asks for permission FIRST, synchronously, inside the tap (iOS refuses a prompt that is not from a tap);
 *     then, channel by channel: register its worker at its scope, subscribe with its key, post to ITS OWN endpoint
 *     (the writes never pass through the theme). A subscription the owner does not list (a key rotation, a sign-out
 *     elsewhere, a cleared browser) is dropped and made again. The result is reported per channel, in a toast or the
 *     section's status line, never alert().
 *   - disable(): this device only - each channel's endpoint is removed on the server, then on the device.
 *   - heal(): on each start, if permission is already granted and this person turned notifications on here, any
 *     channel that is no longer on is re-created silently (no prompt is needed once granted). A worker's
 *     "zim-push-resubscribe" / "zdz-push-resubscribe" message runs it too, so a key rotation heals with the app closed.
 *   - test(): Send me a test - each owner's own endpoint sends its fixed push to this device's endpoint.
 *   - nudge(): one line at the top of the bell's sheet when this device has push off; dismissible, per device.
 *
 * Not drawn for the shared device or in Demo Mode (zdzData.pushOn false; the server refuses them too).
 * Standalone like zdz-bell.js: it draws into #settings-notify and does not edit app.js. Four modes through tokens
 * (push.css). The app's name in the iPhone steps is zdzData.appName (the name on the Home Screen icon).
 */
(function () {
  'use strict';
  var w = window, d = document;
  var data = w.zdzData || {};
  var uid = parseInt(data.userId, 10) || 0;
  var APP = String(data.appName || '').trim() || 'this app';   // the name on the Home Screen icon
  var LS_ON = 'zdz_push_on_' + uid;          // this person turned notifications on, on this device
  var LS_NUDGE = 'zdz_push_nudge_off';       // the bell's one line was dismissed on this device
  var LS_ALIAS = 'zdz_push_alias_' + uid;    // a local endpoint hash -> the owner's, if they ever differ

  var mem = { setup: null, setupAt: 0, inflight: null, last: null, lastKey: '', healing: null, busy: false, acting: 0 };

  /* ------------------------------------------------------------------------------------------------ small helpers */

  function ls(k, v) {
    try {
      if (v === undefined) { return w.localStorage.getItem(k); }
      if (v === null) { w.localStorage.removeItem(k); } else { w.localStorage.setItem(k, v); }
    } catch (e) { /* private mode: the switch still works for this visit */ }
    return null;
  }
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function ui() { return (w.ZDZ && w.ZDZ.ui) || null; }
  function icons() { if (w.lucide && w.lucide.createIcons) { try { w.lucide.createIcons(); } catch (e) { /* icons are decoration */ } } }
  function abs(u) { try { return new URL(u, w.location.href).href; } catch (e) { return String(u || ''); } }
  function each(list, fn) {   // one after another: each channel's worker, key and endpoint are its own
    return (list || []).reduce(function (p, x) { return p.then(function () { return fn(x); }); }, Promise.resolve());
  }
  /** "notices, Messages and Chat answers" */
  function joinParts(list) {
    var a = [];
    (list || []).forEach(function (x) { (x.parts && x.parts.length ? x.parts : [x.label]).forEach(function (p) { if (p && a.indexOf(p) < 0) { a.push(p); } }); });
    if (a.length <= 1) { return a.join(''); }
    return a.slice(0, -1).join(', ') + ' and ' + a[a.length - 1];
  }
  function cap(s) { s = String(s || ''); return s.charAt(0).toUpperCase() + s.slice(1); }

  /* ------------------------------------------------------------------------------------------------ the device */

  function isIOS() {
    var ua = navigator.userAgent || '';
    return /iPad|iPhone|iPod/.test(ua) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
  }
  function standalone() {
    try {
      return navigator.standalone === true || w.matchMedia('(display-mode: standalone)').matches ||
        w.matchMedia('(display-mode: fullscreen)').matches;
    } catch (e) { return false; }
  }
  function supported() {
    return ('serviceWorker' in navigator) && ('PushManager' in w) && ('Notification' in w) && w.isSecureContext !== false;
  }
  /** 'ok', or why this device cannot have push at all. */
  function env() {
    if (isIOS() && !standalone()) { return 'a2hs'; }          // iPhone: only the home-screen app gets push (iOS 16.4+)
    if (!supported()) { return isIOS() ? 'ios-old' : 'unsupported'; }
    return 'ok';
  }
  function permission() { return ('Notification' in w) ? Notification.permission : 'default'; }
  function askPermission() {
    // Promise form, and the old callback form (Safari before 15)
    return new Promise(function (resolve) {
      var done = false;
      var fin = function (p) { if (!done) { done = true; resolve(p || Notification.permission); } };
      try {
        var r = Notification.requestPermission(fin);
        if (r && typeof r.then === 'function') { r.then(fin, function () { fin(Notification.permission); }); }
      } catch (e) { fin(Notification.permission); }
    });
  }

  function sha256hex(str) {
    if (!w.crypto || !w.crypto.subtle || !w.TextEncoder) { return Promise.resolve(''); }
    return w.crypto.subtle.digest('SHA-256', new TextEncoder().encode(String(str))).then(function (buf) {
      return Array.prototype.map.call(new Uint8Array(buf), function (b) { return ('0' + b.toString(16)).slice(-2); }).join('');
    }, function () { return ''; });
  }
  function keyBytes(b64) {
    var pad = '='.repeat((4 - b64.length % 4) % 4);
    var raw = atob(String(b64).replace(/-/g, '+').replace(/_/g, '/') + pad);
    var a = new Uint8Array(raw.length);
    for (var i = 0; i < raw.length; i++) { a[i] = raw.charCodeAt(i); }
    return a;
  }
  function aliases() { try { return JSON.parse(ls(LS_ALIAS) || '{}') || {}; } catch (e) { return {}; } }
  function inList(hs, h) {
    if (!h) { return false; }
    if (hs.indexOf(h) !== -1) { return true; }
    var a = aliases()[h];
    return !!(a && hs.indexOf(a) !== -1);
  }
  /** The owner has this endpoint for this person. */
  function listed(ch, h) { return inList(ch.subscribed_hashes || [], h); }
  /**
   * ...and its row carries THIS sign-in session. A listed one that does not (made in an earlier session, or before
   * a new version) is posted again, or signing out on this device would not find it.
   */
  function current(ch, h) { return inList(ch.current_hashes || [], h); }

  /* ------------------------------------------------------------------------------------------------ the server */

  function rest(path, opts) {
    var k = ui();
    if (!k || !k.rest) { return Promise.reject(new Error('The app is still loading. Try again in a moment.')); }
    var base = String(data.apiUrl || '/wp-json/zorderz/v1/');
    var url = base + (base.indexOf('?') !== -1 ? String(path).replace('?', '&') : path);
    if (!/^https?:|^\//.test(url)) { url = '/' + url; }
    return k.rest(url, opts || {});
  }
  /** A write, to the OWNER's own admin-ajax action (never through the theme). */
  function ownerAjax(act, body) {
    var k = ui();
    if (!k || !k.ajax) { return Promise.reject(new Error('The app is still loading. Try again in a moment.')); }
    if (!act || !act.action) { return Promise.reject(new Error('That is not available.')); }
    var b = { nonce: act.nonce };
    Object.keys(body || {}).forEach(function (x) { b[x] = body[x]; });
    return k.ajax(act.action, b, { retry: 0, timeout: 20000 });
  }
  /** The channels for this person (fresh nonces when forced). */
  function setup(force) {
    if (!data.pushOn) { return Promise.resolve([]); }
    if (!force && mem.setup && Date.now() - mem.setupAt < 30000) { return Promise.resolve(mem.setup); }
    if (mem.inflight) { return mem.inflight; }
    mem.inflight = rest('push/setup', { timeout: 15000 }).then(function (r) {
      mem.setup = (r && Array.isArray(r.channels)) ? r.channels : [];
      mem.setupAt = Date.now();
      mem.inflight = null;
      return mem.setup;
    }, function (e) { mem.inflight = null; throw e; });
    return mem.inflight;
  }

  /* ------------------------------------------------------------------------------------------------ one channel */

  /** Read only: the registration at EXACTLY this channel's scope (getRegistration answers the nearest one). */
  function registration(ch) {
    var want = abs(ch.sw_scope);
    return navigator.serviceWorker.getRegistration(want).then(function (reg) {
      return reg && reg.scope === want ? reg : null;
    }, function () { return null; });
  }
  function inspect(ch) {
    return registration(ch).then(function (reg) {
      if (!reg) { return { ch: ch, on: false, sub: null }; }
      return reg.pushManager.getSubscription().then(function (sub) {
        if (!sub) { return { ch: ch, on: false, sub: null, reg: reg }; }
        return sha256hex(sub.endpoint).then(function (h) { return { ch: ch, on: listed(ch, h), cur: current(ch, h), sub: sub, reg: reg, hash: h }; });
      });
    }).catch(function () { return { ch: ch, on: false, sub: null }; });
  }
  function waitActive(reg) {
    if (reg.active) { return Promise.resolve(reg); }
    return new Promise(function (resolve, reject) {
      var sw = reg.installing || reg.waiting;
      if (!sw) { resolve(reg); return; }
      var t = setTimeout(function () { reject(new Error('its worker did not start')); }, 15000);
      sw.addEventListener('statechange', function () {
        if (sw.state === 'activated') { clearTimeout(t); resolve(reg); }
        else if (sw.state === 'redundant') { clearTimeout(t); reject(new Error('its worker did not install')); }
      });
    });
  }
  /** Post a subscription to its owner (subscribe is an upsert: it also re-stamps the row with this session). */
  function postSub(ch, s) {
    var j = s.toJSON ? s.toJSON() : {};
    var k = j.keys || {};
    return ownerAjax(ch.subscribe, { endpoint: s.endpoint, p256dh: k.p256dh || '', auth: k.auth || '' }).then(function (res) {
      return sha256hex(s.endpoint).then(function (h) {
        var server = res && /^[0-9a-f]{64}$/.test(res.endpoint_hash || '') ? res.endpoint_hash : h;
        if (h && server !== h) { var a = aliases(); a[h] = server; ls(LS_ALIAS, JSON.stringify(a)); }
        ch.subscribed_hashes = (ch.subscribed_hashes || []).concat([server]);
        ch.current_hashes = (ch.current_hashes || []).concat([server]);
      });
    });
  }

  /** Turn one channel on for this device (no prompt: the permission is already granted). */
  function ensure(ch) {
    return navigator.serviceWorker.register(ch.sw_url, { scope: ch.sw_scope }).then(waitActive).then(function (reg) {
      return reg.pushManager.getSubscription().then(function (sub) {
        if (!sub) { return null; }
        return sha256hex(sub.endpoint).then(function (h) {
          if (listed(ch, h)) {
            // on; posted again when its row is from another sign-in session, so sign-out here finds it
            return current(ch, h) ? sub : postSub(ch, sub).then(function () { return sub; });
          }
          // The owner does not have it (a key rotation, a sign-out elsewhere, a cleared browser): start clean, or
          // subscribe() with a new key would be refused while the old subscription exists.
          return sub.unsubscribe().then(function () { return null; }, function () { return null; });
        });
      }).then(function (sub) {
        if (sub) { return null; }
        return reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: keyBytes(ch.vapid_public_key) }).then(function (s) {
          return postSub(ch, s).then(null, function (err) {
            // the owner refused it: leave no browser subscription it does not know about
            return s.unsubscribe().catch(function () {}).then(function () { throw err; });
          });
        });
      });
    });
  }
  function why(err) {
    var n = err && err.name;
    if (n === 'NotAllowedError') { return 'notifications are blocked for this app'; }
    if (n === 'AbortError') { return 'the phone could not reach its notification service. Try again'; }
    if (n === 'InvalidStateError') { return 'the browser was busy. Try again'; }
    var m = (err && err.message) || '';
    return m ? m.replace(/\.$/, '') : 'it did not work. Try again';
  }

  /* ------------------------------------------------------------------------------------------------ the state */

  function summarize(infos) {
    var perm = permission();
    var chans = infos.map(function (i) {
      return { channel: i.ch.channel, label: i.ch.label, parts: i.ch.parts || [i.ch.label], on: !!i.on && perm === 'granted' };
    });
    var st;
    if (perm === 'denied') { st = 'blocked'; }
    else if (!chans.length) { st = 'none'; }
    else {
      var n = chans.filter(function (c) { return c.on; }).length;
      st = n === chans.length ? 'on' : (n ? 'partial' : 'off');
    }
    return { state: st, permission: perm, channels: chans };
  }
  function remember(st) {
    mem.last = st;
    if ((st.state === 'on' || st.state === 'partial') && !mem.acting) { ls(LS_ON, '1'); }
    var key = JSON.stringify([st.state, st.channels]);
    if (key !== mem.lastKey) {
      mem.lastKey = key;
      try { w.dispatchEvent(new CustomEvent('zdz-push-change', { detail: st })); } catch (e) { /* old browser: bells repaint on open */ }
    }
    return st;
  }
  /** This device's state. {state, permission, channels:[{channel, label, parts, on}]} */
  function status(opts) {
    opts = opts || {};
    if (!data.pushOn) { return Promise.resolve({ state: 'none', permission: permission(), channels: [] }); }
    var e = env();
    if (e !== 'ok') { return Promise.resolve(remember({ state: e, permission: 'default', channels: [] })); }
    return setup(!!opts.fresh).then(function (chs) {
      return Promise.all(chs.map(inspect));
    }).then(function (infos) { return remember(summarize(infos)); });
  }
  function channelOn(st, channel) {
    return !!(st && st.channels && st.channels.some(function (c) { return c.channel === channel && c.on; }));
  }

  /* ------------------------------------------------------------------------------------------------ the actions */

  function say(st, verb) {
    var on = (st.channels || []).filter(function (c) { return c.on; });
    var off = (st.channels || []).filter(function (c) { return !c.on; });
    if (verb === 'disable') {
      return st.state === 'off' || st.state === 'none' ? { text: 'Notifications are off on this device.' }
        : { text: 'Some notifications could not be turned off: ' + joinParts(on) + '. Try again.', kind: 'error' };
    }
    if (st.state === 'on') { return { text: 'Notifications on: ' + joinParts(on) + '.', kind: 'success' }; }
    if (st.state === 'partial') {
      var r = (st.results || []).filter(function (x) { return !x.ok; }).map(function (x) { return x.error; })[0];
      return { text: 'On for ' + joinParts(on) + '. ' + cap(joinParts(off)) + ' did not turn on' + (r ? ': ' + r : '') + '.', kind: 'error' };
    }
    if (st.state === 'blocked') { return { text: 'Notifications are blocked for this app. Settings → Notifications shows how to allow them.', kind: 'error' }; }
    if (st.state === 'a2hs') { return { text: 'On iPhone, add ' + APP + ' to your Home Screen first. Settings → Notifications shows how.', kind: 'error' }; }
    if (st.state === 'ios-old') { return { text: 'Notifications need iOS 16.4 or later.', kind: 'error' }; }
    if (st.state === 'unsupported') { return { text: 'This browser cannot receive notifications.', kind: 'error' }; }
    if (st.state === 'none') { return { text: 'There are no notifications for your account yet.' }; }
    var r2 = (st.results || []).filter(function (x) { return !x.ok; }).map(function (x) { return x.error; })[0];
    return { text: r2 ? 'Notifications did not turn on: ' + r2 + '.' : 'Notifications were not turned on.', kind: r2 ? 'error' : undefined };
  }
  function toast(m) { var k = ui(); if (k && k.toast && m && m.text) { k.toast(m.text, m.kind ? { kind: m.kind } : {}); } }

  /**
   * Turn every channel on for this device. Call it from the tap itself: the permission prompt is asked first, before
   * anything else, because iOS refuses a prompt that is not from a tap. opts.toast: say the result in a toast.
   */
  function enable(opts) {
    opts = opts || {};
    if (!data.pushOn) { return Promise.resolve({ state: 'none', permission: permission(), channels: [] }); }
    var e = env();
    if (e !== 'ok') {
      var s0 = remember({ state: e, permission: 'default', channels: [] });
      if (opts.toast) { toast(say(s0, 'enable')); }
      return Promise.resolve(s0);
    }
    var p0 = permission();
    var permP = p0 === 'granted' ? Promise.resolve('granted') : (p0 === 'denied' ? Promise.resolve('denied') : askPermission());
    mem.acting++;
    // a repair already running finishes first, so the two never cross
    var healed = mem.healing ? mem.healing.then(null, function () {}) : Promise.resolve();
    var setupP = healed.then(function () { return setup(true); });
    setupP.catch(function () { /* reported below */ });
    var results = [];
    return permP.then(function (perm) {
      if (perm !== 'granted') { return status(); }
      return setupP.then(function (chs) {
        return each(chs, function (ch) {
          return ensure(ch).then(function () { results.push({ channel: ch.channel, ok: true }); },
            function (err) { results.push({ channel: ch.channel, ok: false, error: why(err) }); });
        });
      }).then(function () {
        if (results.some(function (r) { return r.ok; })) { ls(LS_ON, '1'); }
        return status({ fresh: true });
      });
    }).then(function (st) {
      mem.acting--;
      st.results = results;
      if (opts.toast) { toast(say(st, 'enable')); }
      return st;
    }, function (err) {
      mem.acting--;
      var st = { state: 'error', permission: permission(), channels: [], error: why(err) };
      if (opts.toast) { toast({ text: 'Notifications could not be checked: ' + why(err) + '.', kind: 'error' }); }
      return st;
    });
  }

  /** Turn every channel off for THIS device: on the server first (the owner's endpoint), then on the device. */
  function disable(opts) {
    opts = opts || {};
    if (!data.pushOn || env() !== 'ok') { return status(); }
    mem.acting++;
    ls(LS_ON, null);   // first, so a repair that is running stops before its next channel
    var healed = mem.healing ? mem.healing.then(null, function () {}) : Promise.resolve();
    return healed.then(function () { return setup(true); }).catch(function () { return mem.setup || []; }).then(function (chs) {
      return each(chs, function (ch) {
        return registration(ch).then(function (reg) {
          if (!reg) { return null; }
          return reg.pushManager.getSubscription().then(function (sub) {
            if (!sub) { return null; }
            return ownerAjax(ch.unsubscribe, { endpoint: sub.endpoint }).catch(function () { /* the device still stops */ })
              .then(function () { return sub.unsubscribe(); });
          });
        }).catch(function () { /* one channel failing does not stop the others */ });
      });
    }).then(function () {
      ls(LS_ON, null);
      return status({ fresh: true });
    }).then(function (st) {
      mem.acting--;
      if (opts.toast) { toast(say(st, 'disable')); }
      return st;
    }, function (err) { mem.acting--; throw err; });
  }

  /**
   * On each start (and when a worker says its subscription changed): if permission is already granted and this person
   * turned notifications on here, re-create any channel that is no longer on, silently. Never prompts.
   */
  function heal() {
    if (!data.pushOn || env() !== 'ok' || permission() !== 'granted') { return Promise.resolve(null); }
    if (mem.healing) { return mem.healing; }
    if (mem.acting) { return Promise.resolve(null); }   // the person is turning it on or off right now
    mem.healing = setup(true).then(function (chs) {
      return Promise.all(chs.map(inspect)).then(function (infos) {
        if (infos.some(function (i) { return i.on; })) { ls(LS_ON, '1'); }   // turned on from a bell, or earlier
        if (ls(LS_ON) !== '1') { return null; }                             // never turned on here by this person
        // missing, or on but saved under an earlier sign-in (re-stamped so sign-out here finds it)
        var missing = infos.filter(function (i) { return !i.on || !i.cur; }).map(function (i) { return i.ch; });
        return each(missing, function (ch) {
          if (mem.acting || ls(LS_ON) !== '1') { return null; }   // turned off meanwhile: stop
          return ensure(ch).catch(function (err) { if (w.console) { console.warn('[ZDZPush] could not restore ' + ch.channel + ': ' + why(err)); } });
        });
      });
    }).then(function () { return status({ fresh: true }); }, function () { return null; }).then(function (st) {
      mem.healing = null;
      return st;
    });
    return mem.healing;
  }

  /** Send me a test: each owner sends its fixed push to THIS device's endpoint. Resolves {ok, text}. */
  function test() {
    return setup(true).then(function (chs) {
      return Promise.all(chs.map(inspect));
    }).then(function (infos) {
      var on = infos.filter(function (i) { return i.on && i.sub && i.ch.test; });
      if (!on.length) { return { ok: false, text: 'Turn notifications on first.' }; }
      var good = [], bad = [];
      return each(on, function (i) {
        return ownerAjax(i.ch.test, { endpoint: i.sub.endpoint }).then(function (r) {
          if (r && r.sent > 0) { good.push(i.ch); } else { bad.push({ ch: i.ch, error: 'the push service did not take it' }); }
        }, function (err) { bad.push({ ch: i.ch, error: why(err) }); });
      }).then(function () {
        if (!bad.length) { return { ok: true, text: 'Sent. It should arrive in a few seconds, even with the app closed.' }; }
        var msgs = [];
        bad.forEach(function (b) { if (msgs.indexOf(b.error) < 0) { msgs.push(b.error); } });
        if (!good.length) { return { ok: false, text: cap(msgs.join('. ')) + '.' }; }
        return { ok: false, text: 'Sent for ' + joinParts(good) + '. ' + cap(joinParts(bad.map(function (b) { return b.ch; }))) + ': ' + msgs.join('. ') + '.' };
      });
    }, function (err) { return { ok: false, text: cap(why(err)) + '.' }; });
  }

  /* ------------------------------------------------------------------------------------------------ Settings */

  var BELL = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" ' +
    'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/>' +
    '<path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/></svg>';
  var CHEV = '<svg class="zdz-push-chev" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" ' +
    'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="9 18 15 12 9 6"/></svg>';

  function hm12(v) {
    var m = /^(\d{2}):(\d{2})$/.exec(String(v || ''));
    if (!m) { return ''; }
    var h = +m[1], ap = h >= 12 ? 'PM' : 'AM';
    h = h % 12 || 12;
    return h + ':' + m[2] + ' ' + ap;
  }
  function quietOf() {
    var q = null;
    (mem.setup || []).forEach(function (ch) { if (!q && ch.quiet_hours) { q = ch.quiet_hours; } });
    return q;
  }

  function section() { return d.getElementById('settings-notify'); }

  function detailHtml(st) {
    var ios = isIOS();
    if (st.state === 'a2hs') {
      return '<div class="zdz-push-detail"><p>iPhone sends notifications only to the ' + esc(APP) + ' app on your Home Screen.</p>' +
        '<ol class="zdz-push-steps"><li>In Safari, tap <strong>Share</strong> (the square with an arrow).</li>' +
        '<li>Tap <strong>Add to Home Screen</strong>, then <strong>Add</strong>.</li>' +
        '<li>Open ' + esc(APP) + ' from your Home Screen and come back here.</li></ol></div>';
    }
    if (st.state === 'blocked') {
      return '<div class="zdz-push-detail"><p>' + (ios
        ? 'Notifications are blocked for ' + esc(APP) + '. On your iPhone open <strong>Settings → Notifications → ' + esc(APP) + '</strong> and turn on <strong>Allow Notifications</strong>, then come back here.'
        : 'This browser is blocking notifications for this site. Click the icon to the left of the address, set <strong>Notifications</strong> to <strong>Allow</strong>, then come back here.') +
        '</p><button type="button" class="zdz-btn zdz-btn--secondary zdz-btn--sm" data-push="recheck">Check again</button></div>';
    }
    if (st.state === 'ios-old') {
      return '<div class="zdz-push-detail"><p>Notifications need iOS 16.4 or later. Update in <strong>Settings → General → Software Update</strong>.</p></div>';
    }
    if (st.state === 'unsupported') {
      return '<div class="zdz-push-detail"><p>This browser cannot receive notifications. Use Safari, Chrome or Edge, or the ' + esc(APP) + ' app on your iPhone\'s Home Screen.</p></div>';
    }
    if (st.state === 'error') {
      return '<div class="zdz-push-detail"><p>' + esc(cap(st.error || 'notifications could not be checked just now')) + '.</p>' +
        '<button type="button" class="zdz-btn zdz-btn--secondary zdz-btn--sm" data-push="recheck">Try again</button></div>';
    }
    if (st.state === 'partial') {
      var off = st.channels.filter(function (c) { return !c.on; });
      return '<div class="zdz-push-detail"><p>' + esc(cap(joinParts(off))) + ' ' + (off.length === 1 && (off[0].parts || []).length < 2 ? 'is' : 'are') +
        ' not on yet.</p><button type="button" class="zdz-btn zdz-btn--secondary zdz-btn--sm" data-push="retry">Turn on the rest</button></div>';
    }
    return '';
  }

  function draw(st) {
    var host = section();
    if (!host) { return; }
    if (!data.pushOn || (st && st.state === 'none')) { host.hidden = true; host.innerHTML = ''; return; }
    host.hidden = false;
    st = st || { state: 'loading', channels: [] };
    var all = joinParts(st.channels.length ? st.channels : (mem.setup || []));
    var everything = all || 'notices, Messages and Chat answers';
    var toggle = st.state === 'on' || st.state === 'partial' || st.state === 'off';
    var checked = st.state === 'on' || st.state === 'partial';
    var sub = {
      loading: 'Checking this device…',
      on: cap(everything) + ' come to this device, even when the app is closed.',
      partial: 'On for ' + joinParts(st.channels.filter(function (c) { return c.on; })) + '.',
      off: 'Get ' + everything + ' on this device, even when the app is closed.',
      blocked: 'Blocked on this device.',
      a2hs: 'Add to Home Screen first.',
      'ios-old': 'Not available on this iPhone yet.',
      unsupported: 'Not available in this browser.',
      error: 'Could not check this device.'
    }[st.state] || '';
    // short: the line under the label says the rest (a long pill squeezed the label on a phone)
    var pill = { blocked: 'Blocked', a2hs: 'Off', 'ios-old': 'Update', unsupported: 'Unavailable', error: 'Unknown' }[st.state];
    var right = toggle
      ? '<button type="button" class="zdz-push-switch" role="switch" id="zdz-push-switch" aria-checked="' + checked + '" aria-labelledby="zdz-push-label" aria-describedby="zdz-push-sub" data-push="toggle"><span class="zdz-push-knob" aria-hidden="true"></span></button>'
      : (pill ? '<span class="zdz-push-pill zdz-push-pill--' + esc(st.state) + '">' + esc(pill) + '</span>' : '<span class="zdz-push-pill">…</span>');
    var q = quietOf();
    var links = '';
    if (w.ZDZBell && w.ZDZBell.open) {
      links += '<button type="button" class="settings-row zdz-push-link" data-push="notices"><span class="sr-left"><span class="sr-label">Notices you get</span></span>' +
        '<span class="sr-right">' + CHEV + '</span></button>';
    }
    if (q) {
      links += '<button type="button" class="settings-row zdz-push-link" data-push="quiet"><span class="sr-left"><span class="sr-label">Quiet hours</span></span>' +
        '<span class="sr-right"><span class="zdz-push-quiet"><span>' + esc(hm12(q.start)) + ' –</span> <span>' + esc(hm12(q.end)) + '</span></span>' + CHEV + '</span></button>';
    }
    host.innerHTML =
      '<h4>Notifications</h4>' +
      '<div class="settings-row zdz-push-row zdz-push--' + esc(st.state) + '">' +
        '<div class="sr-left"><span class="zdz-push-ico">' + BELL + '</span><span class="zdz-push-text">' +
          '<span class="sr-label" id="zdz-push-label">Phone notifications</span>' +
          '<span class="zdz-push-sub" id="zdz-push-sub">' + esc(sub) + '</span></span></div>' +
        '<div class="sr-right">' + right + '</div>' +
      '</div>' +
      detailHtml(st) +
      (checked ? '<div class="zdz-push-actions"><button type="button" class="zdz-btn zdz-btn--secondary" data-push="test">Send me a test</button></div>' : '') +
      '<p class="zdz-push-status" role="status" aria-live="polite"></p>' +
      links;
    wire(host);
  }

  function setStatus(text, kind) {
    var p = section() && section().querySelector('.zdz-push-status');
    if (!p) { return; }
    p.textContent = text || '';
    p.className = 'zdz-push-status' + (kind ? ' is-' + kind : '');
  }

  function wire(host) {
    Array.prototype.forEach.call(host.querySelectorAll('[data-push]'), function (b) {
      b.addEventListener('click', function () { act(b.getAttribute('data-push'), b); });
    });
  }

  function act(what, btn) {
    var k = ui();
    if (what === 'toggle') {
      if (mem.busy) { return; }
      var on = btn.getAttribute('aria-checked') === 'true';
      mem.busy = true;
      btn.setAttribute('aria-busy', 'true');
      btn.classList.add('is-busy');
      // enable() asks for permission synchronously, right here in the tap.
      var work = on ? disable({}) : enable({});
      setStatus(on ? 'Turning off…' : 'Turning on…');
      work.then(function (st) {
        mem.busy = false;
        draw(st);
        var m = say(st, on ? 'disable' : 'enable');
        setStatus(m.text, m.kind === 'error' ? 'error' : '');
        var sw = d.getElementById('zdz-push-switch');
        if (sw) { try { sw.focus(); } catch (e) {} }
      });
      return;
    }
    if (what === 'retry') {
      mem.busy = true;
      enable({}).then(function (st) { mem.busy = false; draw(st); var m = say(st, 'enable'); setStatus(m.text, m.kind === 'error' ? 'error' : ''); });
      return;
    }
    if (what === 'recheck') { refresh(true); return; }
    if (what === 'test') {
      var run = function () { return test().then(function (r) { setStatus(r.text, r.ok ? '' : 'error'); }); };
      if (k && k.busy) { k.busy(btn, run); } else { run(); }
      return;
    }
    if (what === 'notices') { if (w.ZDZBell && w.ZDZBell.open) { w.ZDZBell.open({ prefs: true }); } return; }
    if (what === 'quiet') { quietSheet(); return; }
  }

  /** Quiet hours: Messages' own setting, written to Messages' own endpoint. */
  function quietSheet() {
    var k = ui(), q = quietOf();
    if (!k || !k.sheet || !q) { return; }
    var body = '<p class="zdz-push-sheet-hint">Notices and Messages that arrive during quiet hours wait until they end. ' +
      'Send me a test and Chat answers are not held.</p>' +
      '<div class="zdz-push-times"><label for="zdz-push-qs"><span>From</span><input type="time" id="zdz-push-qs" value="' + esc(q.start) + '" required></label>' +
      '<label for="zdz-push-qe"><span>Until</span><input type="time" id="zdz-push-qe" value="' + esc(q.end) + '" required></label></div>';
    k.sheet({
      title: 'Quiet hours',
      body: body,
      actions: [
        { label: 'Save', kind: 'primary', onClick: function (sh) {
          var s = sh.el.querySelector('#zdz-push-qs').value, e = sh.el.querySelector('#zdz-push-qe').value;
          if (!/^\d{2}:\d{2}$/.test(s) || !/^\d{2}:\d{2}$/.test(e)) { return Promise.reject(new Error('Choose both times.')); }
          return ownerAjax(q, { start: s, end: e }).then(function () {
            q.start = s; q.end = e;
            if (k.toast) { k.toast('Quiet hours: ' + hm12(s) + ' – ' + hm12(e) + '.'); }
            setup(true).then(function () { draw(mem.last); }, function () { draw(mem.last); });
          });
        } },
        { label: 'Cancel' }
      ]
    });
  }

  function settingsOpen() { var v = d.getElementById('sv-settings'); return !!(v && v.classList.contains('active')); }

  function refresh(fresh) {
    if (!data.pushOn) { draw({ state: 'none', channels: [] }); return Promise.resolve(); }
    if (!mem.last) { draw(null); }
    return status({ fresh: !!fresh }).then(draw, function (err) {
      draw({ state: 'error', channels: [], error: why(err) });
    });
  }

  /** Settings → Notifications, from anywhere (the bell's line). */
  function openSettings() {
    if (typeof w.switchView === 'function') { w.switchView('sv-settings'); }
    setTimeout(function () {
      var s = section();
      if (s && s.scrollIntoView) { try { s.scrollIntoView({ block: 'start', behavior: 'smooth' }); } catch (e) { s.scrollIntoView(); } }
      var sw = d.getElementById('zdz-push-switch');
      if (sw) { try { sw.focus({ preventScroll: true }); } catch (e) {} }
    }, 120);
  }

  /* ------------------------------------------------------------------------------------------------ the bell's line */

  /** One line at the top of the bell's sheet while this device has push off. Dismissible; stays dismissed here. */
  function nudge(container, close) {
    if (!container || !data.pushOn || ls(LS_NUDGE) === '1') { return; }
    status().then(function (st) {
      if (['off', 'partial', 'a2hs'].indexOf(st.state) < 0 || container.querySelector('.zdz-push-nudge')) { return; }
      var row = d.createElement('div');
      row.className = 'zdz-push-nudge';
      row.innerHTML = '<button type="button" class="zdz-push-nudge-go">Turn on phone notifications <span aria-hidden="true">→</span></button>' +
        '<button type="button" class="zdz-push-nudge-x" aria-label="Hide this">&times;</button>';
      row.querySelector('.zdz-push-nudge-go').addEventListener('click', function () {
        if (typeof close === 'function') { close(); }
        openSettings();
      });
      row.querySelector('.zdz-push-nudge-x').addEventListener('click', function () {
        ls(LS_NUDGE, '1');
        if (row.parentNode) { row.parentNode.removeChild(row); }
      });
      container.insertBefore(row, container.firstChild);
    }, function () { /* no line if the state cannot be read */ });
  }

  /* ------------------------------------------------------------------------------------------------ start */

  function start() {
    if (data.pushOn) {
      // Settings: draw when it opens, and again when the app comes back (from the iPhone's Settings, say).
      var v = d.getElementById('sv-settings');
      if (v && w.MutationObserver) {
        var was = settingsOpen();
        new MutationObserver(function () {
          var now = settingsOpen();
          if (now && !was) { refresh(true); }
          was = now;
        }).observe(v, { attributes: true, attributeFilter: ['class'] });
      }
      if (settingsOpen()) { refresh(true); } else { draw(null); }
      d.addEventListener('visibilitychange', function () {
        if (d.visibilityState === 'visible' && settingsOpen() && !mem.busy) { refresh(true); }
      });
      // A change made elsewhere (the heal on start, a Messages or Chat bell) redraws an open Settings.
      w.addEventListener('zdz-push-change', function (ev) {
        if (ev && ev.detail && !mem.busy && section() && !section().hidden) { draw(ev.detail); }
      });
      // A worker whose subscription changed (the messaging app's sends zim-push-resubscribe; any owner may send zdz-push-resubscribe).
      if ('serviceWorker' in navigator) {
        navigator.serviceWorker.addEventListener('message', function (ev) {
          var t = ev && ev.data && ev.data.type;
          if (t === 'zim-push-resubscribe' || t === 'zdz-push-resubscribe') { heal(); }
        });
      }
      // Heal once the app has settled (never a prompt; nothing at all unless permission is already granted).
      setTimeout(function () { heal(); }, 2500);
    } else {
      draw({ state: 'none', channels: [] });
    }
  }

  w.ZDZPush = {
    version: '1.0.0',
    status: status,
    enable: enable,
    disable: disable,
    heal: heal,
    test: test,
    channelOn: channelOn,
    nudge: nudge,
    openSettings: openSettings,
    refresh: refresh,
    _env: env
  };
  if (d.readyState === 'loading') { d.addEventListener('DOMContentLoaded', start); } else { start(); }
})();
