/*
 * Zorderz guided tour: plays the steps ZDZ_Tour hands the page in zdzData.tour.
 *
 * A step puts a ring around one thing on the screen and shows a card beside it: what it is, what to try, and Back,
 * Show me and Next. A step may want the Home Screen or an app open first, and the player opens it. When a step's
 * target is not on the screen (another layout, an app that drew differently) the card still shows, centred, so a
 * changed screen never strands anyone. Each move is saved for the person, so the tour picks up where it was left.
 *
 * Started from the offer on the Home Screen, from Settings → Guided tour, from the ask bar ("guided tour"), from
 * the link the sample loader shows (?zdz_tour=start), or by any script with ZDZ.tour.start().
 *
 * The server chooses the tour (an administrator's sample tour, or a team member's own) and writes it for the person.
 * An administrator also gets, in Settings → Guided tour, the switch that offers the team tours and a link to see the
 * tour each role gets (a preview, which is never saved).
 */
(function (w, d) {
  'use strict';

  var S = { on: false, i: 0, list: [], ring: null, veil: null, card: null, target: null, raf: 0, poll: 0, saveT: 0, clickOff: null, tapOff: null, watch: 0, lastBox: '', lastFocus: null, min: false };

  function cfg() { return (w.zdzData && w.zdzData.tour) || {}; }
  function steps() { return Array.isArray(cfg().steps) ? cfg().steps : []; }
  function label(k, dflt) { var l = cfg().labels || {}; return typeof l[k] === 'string' && l[k] !== '' ? l[k] : dflt; }
  function chapterName(id) { var c = cfg().chapters || {}; return typeof c[id] === 'string' ? c[id] : ''; }
  function state() { var s = cfg().state; return s && typeof s === 'object' ? s : { step: '', status: 'new' }; }

  function indexOf(id) {
    var list = steps();
    for (var i = 0; i < list.length; i++) { if (list[i].id === id) { return i; } }
    return -1;
  }

  function el(tag, cls, text) {
    var n = d.createElement(tag);
    if (cls) { n.className = cls; }
    if (text != null) { n.textContent = text; }
    return n;
  }

  function btn(text, cls, onClick) {
    var b = el('button', cls, text);
    b.type = 'button';
    b.addEventListener('click', onClick);
    return b;
  }

  function fmt(s, a, b) { return String(s).replace('%1$d', a).replace('%2$d', b); }

  /* ---------------------------------------------------------------- saving the person's place */

  function save(status) {
    var t = cfg();
    var cur = steps()[S.i] || {};
    t.state = { step: status === 'new' ? '' : (cur.id || ''), status: status };
    if (!t.rest) { return; }
    clearTimeout(S.saveT);
    var body = JSON.stringify(t.state);
    S.saveT = setTimeout(function () {
      try {
        fetch(t.rest, {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': (w.zdzData && w.zdzData.nonce) || '' },
          body: body
        }).catch(function () { /* the next move saves again */ });
      } catch (e) { /* no fetch: the tour still plays */ }
    }, 200);
  }

  /* ---------------------------------------------------------------- the screen a step wants */

  function bridge() { return w.Bridge || null; }
  function openAppId() { var b = bridge(); return b && b.currentApp ? b.currentApp.id : ''; }

  function goHome() {
    var b = bridge();
    if (b && b.currentApp && typeof b.closeApp === 'function') { b.closeApp(); }
    if (typeof w.switchView === 'function') { w.switchView('sv-dash'); }
  }

  function openApp(id) {
    if (!id) { return; }
    if (openAppId() === id) { return; }
    if (typeof w.switchView === 'function') { w.switchView('sv-dash'); }
    if (typeof w.openApp === 'function') { w.openApp(id); return; }
    var tile = d.querySelector('[data-zdz-app="' + id.replace(/[^a-z0-9_-]/gi, '') + '"]');
    if (tile) { tile.click(); }
  }

  function openSettings() {
    var b = bridge();
    if (b && b.currentApp && typeof b.closeApp === 'function') { b.closeApp(); }
    if (typeof w.switchView === 'function') { w.switchView('sv-settings'); return; }
    var logo = d.getElementById('bnav-logo');
    if (logo) { logo.click(); }
  }

  function settle(step) {
    if (step.screen === 'home') { goHome(); }
    else if (step.screen === 'settings') { openSettings(); }
    else if (step.screen) { openApp(step.screen); }
  }

  function doShow(step) {
    var sh = step.show || {};
    if (sh.open_app) { openApp(sh.open_app); }
    else if (sh.settings) { openSettings(); }
    else if (sh.home) { goHome(); }
    else if (sh.click) { var t = find(sh.click); if (t) { t.click(); } }
    else if (sh.admin) { w.open(sh.admin, '_blank', 'noopener'); }
    // whatever it opened may now hold the target
    clearInterval(S.poll);
    look(step, 16);
  }

  /* ---------------------------------------------------------------- finding and ringing the target */

  function visible(n) {
    if (!n || !n.getBoundingClientRect) { return false; }
    var r = n.getBoundingClientRect();
    if (r.width < 2 || r.height < 2) { return false; }
    var cs = w.getComputedStyle(n);
    return cs.visibility !== 'hidden' && cs.display !== 'none' && parseFloat(cs.opacity || '1') > 0.05;
  }

  function find(sel) {
    if (!sel) { return null; }
    var all;
    try { all = d.querySelectorAll(sel); } catch (e) { return null; }
    for (var i = 0; i < all.length; i++) { if (visible(all[i])) { return all[i]; } }
    return null;
  }

  // Look for the step's target a few times while an app draws; the card shows straight away either way. A step
  // with `reach` names what brings its target on screen (a tab, a filter): it is clicked once if the target is not
  // there after the app has had a moment to draw.
  function look(step, tries) {
    var n = 0, reached = false;
    clearInterval(S.poll);
    var once = function () {
      var t = find(step.target);
      if (!t && step.reach && !reached && n >= 2) {
        var r = find(step.reach);
        if (r) { reached = true; r.click(); }
      }
      if (t || ++n >= tries) {
        clearInterval(S.poll);
        aim(t, step);
      }
    };
    once();
    if (!S.target && step.target) { S.poll = setInterval(once, 250); }
  }

  function aim(t, step) {
    S.target = t || null;
    var note = S.card && S.card.querySelector('.zdz-tour-missing');
    if (note) { note.hidden = !(step.target && !t); }
    if (t) { into(t); }
    wire(t, step);
    place();
    follow(step);
  }

  function onScreen(t) {
    var r = t.getBoundingClientRect();
    return r.top >= 0 && r.left >= 0 && r.bottom <= w.innerHeight && r.right <= w.innerWidth;
  }

  function into(t) {
    try { t.scrollIntoView({ block: 'center', inline: 'nearest', behavior: reduced() ? 'auto' : 'smooth' }); } catch (e) { t.scrollIntoView(); }
  }

  // A step that moves on when its place is clicked (next_on: click) listens on that element.
  function wire(t, step) {
    if (S.clickOff) { S.clickOff(); S.clickOff = null; }
    if (!t || step.next_on !== 'click') { return; }
    var h = function () { setTimeout(function () { if (S.on && steps()[S.i] === step) { go(S.i + 1); } }, 500); };
    t.addEventListener('click', h, { once: true });
    S.clickOff = function () { t.removeEventListener('click', h); };
  }

  // The marked place can still change after the step starts: a bar slides back in when an app closes, a list fills
  // in, an app draws its list again with new elements, or the place shows up only after the step gave up looking.
  // The ring and the card follow it, and find it again when it is drawn anew.
  function follow(step) {
    clearInterval(S.watch);
    if (!step || !step.target) { return; }
    S.watch = setInterval(function () {
      if (!S.on || steps()[S.i] !== step) { clearInterval(S.watch); return; }
      if (S.target && !d.contains(S.target)) { S.target = null; place(); } // drawn away: the ring goes
      if (!S.target) {
        var again = find(step.target);
        if (!again) { return; }
        S.target = again;
        var note = S.card && S.card.querySelector('.zdz-tour-missing');
        if (note) { note.hidden = true; }
        if (!onScreen(again)) { into(again); }
        wire(again, step);
        place();
        return;
      }
      if (boxOf(S.target.getBoundingClientRect()) !== S.lastBox) { place(); }
    }, 300);
  }

  function boxOf(r) { return [r.left, r.top, r.width, r.height].map(Math.round).join(','); }

  function reduced() { try { return w.matchMedia('(prefers-reduced-motion: reduce)').matches; } catch (e) { return false; } }

  function place() {
    cancelAnimationFrame(S.raf);
    S.raf = requestAnimationFrame(function () {
      if (!S.on || !S.card) { return; }
      var t = S.target && d.contains(S.target) && visible(S.target) ? S.target : null;
      var vw = w.innerWidth, vh = w.innerHeight, pad = 6;
      if (S.min) { tuck(t, vw, vh); }
      if (t) {
        var r = t.getBoundingClientRect();
        S.lastBox = boxOf(r);
        var left = Math.max(4, r.left - pad), top = Math.max(4, r.top - pad);
        var right = Math.min(vw - 4, r.right + pad), bottom = Math.min(vh - 4, r.bottom + pad);
        S.ring.style.left = left + 'px';
        S.ring.style.top = top + 'px';
        S.ring.style.width = Math.max(8, right - left) + 'px';
        S.ring.style.height = Math.max(8, bottom - top) + 'px';
        S.ring.hidden = false;
        S.veil.hidden = true;
        if (!S.min) { placeCard(r, vw, vh); covers(t); }
      } else {
        if (!S.min) {
          // the marked place is gone (a dialog closed): nothing is covered any more
          untap();
          var cover = S.card.querySelector('.zdz-tour-covering');
          if (cover) { cover.hidden = true; }
        }
        S.ring.hidden = true;
        S.veil.hidden = S.min;
        if (!S.min) {
          S.card.style.width = '';
          var c = S.card.getBoundingClientRect();
          S.card.style.left = Math.max(12, (vw - c.width) / 2) + 'px';
          S.card.style.top = Math.max(12, (vh - c.height) / 2) + 'px';
        }
      }
    });
  }

  // The folded card is a small tab in a corner, clear of the marked place and of the bottom bar, so a step that
  // points at the bar (Chat, Notices) can still be tapped.
  function tuck(t, vw, vh) {
    S.card.style.width = '';
    var m = S.card.getBoundingClientRect(), r = t ? t.getBoundingClientRect() : null;
    var bar = d.querySelector('nav.bnav'), floor = vh - 12;
    if (bar && visible(bar)) {
      var b = bar.getBoundingClientRect();
      if (b.top > vh / 2) { floor = Math.min(floor, b.top - 8); }
    }
    var right = vw - m.width - 16, low = floor - m.height;
    var spots = [[right, low], [right, 12], [16, low], [16, 12]], pick = null, best = 9;
    // the first corner that keeps off the marked place and off any button or field; covering a control is worse
    S.card.style.pointerEvents = 'none';
    for (var i = 0; i < spots.length && best > 0; i++) {
      var x = Math.max(12, spots[i][0]), y = Math.max(12, spots[i][1]);
      var onTarget = r && !(x > r.right + 4 || x + m.width < r.left - 4 || y > r.bottom + 4 || y + m.height < r.top - 4);
      var score = (onControl(x, y, m.width, m.height) ? 2 : 0) + (onTarget ? 1 : 0);
      if (score < best) { best = score; pick = [x, y]; }
    }
    S.card.style.pointerEvents = '';
    S.card.style.left = pick[0] + 'px';
    S.card.style.top = pick[1] + 'px';
  }

  var CONTROLS = 'button, a[href], input, select, textarea, label, summary, [role="button"], [role="tab"], [contenteditable="true"]';

  function onControl(x, y, cw, ch) {
    var pts = [[x + 4, y + 4], [x + cw - 4, y + 4], [x + 4, y + ch - 4], [x + cw - 4, y + ch - 4], [x + cw / 2, y + ch / 2]];
    for (var i = 0; i < pts.length; i++) {
      var e = d.elementFromPoint(pts[i][0], pts[i][1]);
      if (e && e.closest && !S.card.contains(e) && e.closest(CONTROLS)) { return true; }
    }
    return false;
  }

  // When the open card has to sit on part of the place where the step asks you to do something (a dialog on a
  // phone), it says so, and the first click or tap outside it tucks it away; that tap still reaches the app. Once
  // said, it holds for the step, so the card's own growth or a moving page never takes the promise back.
  function covers(t) {
    var step = steps()[S.i];
    if (!step || !step.do || S.tapOff) { return; }
    var c = S.card.getBoundingClientRect(), r = t.getBoundingClientRect();
    if (!(c.left < r.right && c.right > r.left && c.top < r.bottom && c.bottom > r.top)) { return; }
    var hint = S.card.querySelector('.zdz-tour-covering');
    var h = function (e) {
      if (!S.on || S.min || !S.card || S.card.contains(e.target)) { return; }
      untap();
      fold(true, true);
    };
    d.addEventListener('pointerdown', h, true);
    S.tapOff = function () { d.removeEventListener('pointerdown', h, true); };
    if (hint && hint.hidden) { hint.hidden = false; place(); } // the card is taller now: place it again
  }

  function untap() { if (S.tapOff) { S.tapOff(); S.tapOff = null; } }

  // Beside the target where it fits: below, above, right, left. A target that fills the middle (a dialog) gets a
  // narrower card in the wider free side, so the card never sits on the controls the step asks you to use. Only
  // when no side has room does it go to the bottom of the screen, where Hide folds it away.
  function placeCard(r, vw, vh) {
    S.card.style.width = '';
    var c = S.card.getBoundingClientRect(), gap = 14, x, y;
    if (vw <= 640) {
      // A phone: the stylesheet docks the card to the bottom edge, or to the top edge when the marked place is
      // down there. A place that fills the screen keeps the bottom free, where a dialog keeps its buttons.
      S.card.classList.toggle('is-top', r.bottom > vh - c.height - 24);
      return;
    }
    S.card.classList.remove('is-top');
    if (r.bottom + gap + c.height <= vh - 8) { y = r.bottom + gap; x = r.left; }
    else if (r.top - gap - c.height >= 8) { y = r.top - gap - c.height; x = r.left; }
    else if (r.right + gap + c.width <= vw - 8) { x = r.right + gap; y = r.top; }
    else if (r.left - gap - c.width >= 8) { x = r.left - gap - c.width; y = r.top; }
    else {
      var leftRoom = r.left - gap - 12, rightRoom = vw - r.right - gap - 12, room = Math.max(leftRoom, rightRoom);
      if (room >= 240) {
        S.card.style.width = Math.min(380, room) + 'px';
        c = S.card.getBoundingClientRect();
        x = leftRoom >= rightRoom ? r.left - gap - c.width : r.right + gap;
        y = r.top + (r.height - c.height) / 2;
      } else { x = (vw - c.width) / 2; y = vh - c.height - 12; }
    }
    S.card.style.left = Math.min(Math.max(12, x), vw - c.width - 12) + 'px';
    S.card.style.top = Math.min(Math.max(12, y), vh - c.height - 12) + 'px';
  }

  /* ---------------------------------------------------------------- the card */

  function build() {
    if (S.card) { return; }
    S.ring = el('div', 'zdz-tour-ring');
    S.ring.hidden = true;
    S.ring.setAttribute('aria-hidden', 'true');
    S.veil = el('div', 'zdz-tour-veil');
    S.veil.hidden = true;
    S.veil.setAttribute('aria-hidden', 'true');
    S.card = el('div', 'zdz-tour-card');
    S.card.setAttribute('role', 'dialog');
    S.card.setAttribute('aria-modal', 'false');
    S.card.setAttribute('aria-label', label('dialog', 'Guided tour'));
    S.card.tabIndex = -1;
    d.body.appendChild(S.veil);
    d.body.appendChild(S.ring);
    d.body.appendChild(S.card);
    w.addEventListener('resize', place);
    w.addEventListener('scroll', place, true);
    d.addEventListener('keydown', onKey, true);
  }

  function teardown() {
    untap();
    clearInterval(S.poll);
    clearInterval(S.watch);
    cancelAnimationFrame(S.raf);
    if (S.clickOff) { S.clickOff(); S.clickOff = null; }
    w.removeEventListener('resize', place);
    w.removeEventListener('scroll', place, true);
    d.removeEventListener('keydown', onKey, true);
    [S.card, S.ring, S.veil].forEach(function (n) { if (n && n.parentNode) { n.parentNode.removeChild(n); } });
    S.card = S.ring = S.veil = S.target = null;
  }

  function onKey(e) {
    if (!S.on) { return; }
    var inField = e.target && /^(INPUT|TEXTAREA|SELECT)$/.test(e.target.tagName);
    if (e.key === 'Escape') { e.preventDefault(); end(); }
    else if (!inField && e.key === 'ArrowRight' && S.card.contains(d.activeElement)) { go(S.i + 1); }
    else if (!inField && e.key === 'ArrowLeft' && S.card.contains(d.activeElement)) { go(S.i - 1); }
  }

  // quiet: folded by a tap on the app, so the focus stays where that tap puts it
  function fold(on, quiet) {
    S.min = !!on;
    S.card.classList.toggle('is-min', S.min);
    var tab = S.card.querySelector('.zdz-tour-tab');
    if (S.min && tab && !quiet) { try { tab.focus({ preventScroll: true }); } catch (e) { tab.focus(); } }
    if (!S.min) { var nx = S.card.querySelector('.zdz-tour-bar .zdz-tour-btn-primary'); if (nx) { try { nx.focus({ preventScroll: true }); } catch (e) { nx.focus(); } } }
    place();
  }

  function render(step) {
    var list = steps(), last = S.i === list.length - 1;
    untap();
    S.min = false;
    S.card.classList.remove('is-min');
    S.card.innerHTML = '';
    var count = fmt(label('step_of', 'Step %1$d of %2$d'), S.i + 1, list.length);
    // folded: one small tab that brings the card back
    var tab = btn(label('show_card', 'Show the tour') + ' · ' + count, 'zdz-tour-tab', function () { fold(false); });
    S.card.appendChild(tab);
    var meta = el('div', 'zdz-tour-meta');
    meta.appendChild(el('span', '', chapterName(step.chapter)));
    var right = el('span', 'zdz-tour-meta-right');
    right.appendChild(el('span', '', count));
    var hide = btn(label('hide_card', 'Hide'), 'zdz-tour-fold', function () { fold(true); });
    hide.setAttribute('aria-label', label('hide_card_aria', 'Hide the tour card while you work'));
    right.appendChild(hide);
    meta.appendChild(right);
    S.card.appendChild(meta);
    var h = el('h2', 'zdz-tour-title', step.title);
    h.id = 'zdz-tour-title';
    S.card.setAttribute('aria-labelledby', 'zdz-tour-title');
    S.card.appendChild(h);
    S.card.appendChild(el('p', 'zdz-tour-body', step.body));
    if (Array.isArray(step.list) && step.list.length) {
      var ul = el('ul', 'zdz-tour-list');
      step.list.forEach(function (line) { ul.appendChild(el('li', '', String(line))); });
      S.card.appendChild(ul);
    }
    if (step.do) {
      var p = el('p', 'zdz-tour-do');
      p.appendChild(el('strong', '', label('your_turn', 'Your turn:') + ' '));
      p.appendChild(d.createTextNode(step.do));
      S.card.appendChild(p);
    }
    if (step.note) { S.card.appendChild(el('p', 'zdz-tour-note', step.note)); }
    var miss = el('p', 'zdz-tour-note zdz-tour-missing', label('not_on_screen', 'This part is not on the screen right now. Use Show me, or read on and press Next.'));
    miss.hidden = true;
    S.card.appendChild(miss);
    var cover = el('p', 'zdz-tour-note zdz-tour-covering', label('covering', 'This card covers part of the screen. Hide tucks it into a corner, and so does a click or tap outside it. The tab brings it back.'));
    cover.hidden = true;
    S.card.appendChild(cover);

    var bar = el('div', 'zdz-tour-bar');
    bar.appendChild(btn(label('end', 'End tour'), 'zdz-tour-link zdz-tour-end', function () { end(); }));
    if (S.i > 0) { bar.appendChild(btn(label('back', 'Back'), 'zdz-tour-btn', function () { go(S.i - 1); })); }
    if (step.show) {
      var showText = step.show.admin && step.show.label ? step.show.label : label('show', 'Show me');
      bar.appendChild(btn(showText, 'zdz-tour-btn', function () { doShow(step); }));
    }
    var next = btn(last ? label('finish', 'Finish') : label('next', 'Next'), 'zdz-tour-btn zdz-tour-btn-primary', function () { if (last) { finish(); } else { go(S.i + 1); } });
    bar.appendChild(next);
    S.card.appendChild(bar);
    S.card.hidden = false;
    try { next.focus({ preventScroll: true }); } catch (e) { next.focus(); }
  }

  /* ---------------------------------------------------------------- moving through */

  function go(i) {
    var list = steps();
    if (!list.length) { return; }
    S.i = Math.max(0, Math.min(list.length - 1, i));
    var step = list[S.i];
    if (S.clickOff) { S.clickOff(); S.clickOff = null; }
    clearInterval(S.watch);
    S.target = null;
    settle(step);
    render(step);
    place();
    look(step, step.screen && step.screen !== 'home' ? 20 : 8);
    save('running');
  }

  function start(fromId) {
    if (!cfg().available || !steps().length) { return false; }
    hideOffer();
    S.lastFocus = d.activeElement;
    S.on = true;
    build();
    var at = fromId ? indexOf(fromId) : -1;
    go(at >= 0 ? at : 0);
    return true;
  }

  function resume() {
    var st = state();
    return start(st.step || '');
  }

  function stop(status) {
    S.on = false;
    save(status);
    teardown();
    try { if (S.lastFocus && S.lastFocus.focus) { S.lastFocus.focus(); } } catch (e) { /* gone */ }
    drawSettings();
  }

  function end() {
    stop('paused');
    toast(label('ended', 'Tour paused. Settings → Guided tour picks it up again.'));
    drawOffer();
  }

  function finish() {
    stop('done');
    goHome();
  }

  function toast(text) {
    if (typeof w.showToast === 'function') { w.showToast(text); return; }
    var t = el('div', 'zdz-tour-toast', text);
    t.setAttribute('role', 'status');
    d.body.appendChild(t);
    setTimeout(function () { if (t.parentNode) { t.parentNode.removeChild(t); } }, 4000);
  }

  /* ---------------------------------------------------------------- where it is offered */

  function hideOffer() { var o = d.getElementById('zdz-tour-offer'); if (o && o.parentNode) { o.parentNode.removeChild(o); } }

  // The Home Screen card: first time, or after "End tour"; gone once done or hidden.
  function drawOffer() {
    hideOffer();
    var st = state();
    if (!cfg().available || S.on || st.status === 'done' || st.status === 'hidden') { return; }
    var dash = d.getElementById('sv-dash'), top = d.getElementById('dash-top');
    if (!dash) { return; }
    var paused = st.status === 'paused' || st.status === 'running';
    var box = el('section', 'zdz-tour-offer');
    box.id = 'zdz-tour-offer';
    box.setAttribute('aria-label', label('offer_title', 'Take the guided tour'));
    var text = el('div', 'zdz-tour-offer-text');
    text.appendChild(el('p', 'zdz-tour-offer-title', label('offer_title', 'Take the guided tour')));
    text.appendChild(el('p', 'zdz-tour-offer-body', paused ? label('offer_resume', 'Pick up the guided tour where you left it.') : label('offer_body', '')));
    box.appendChild(text);
    var bar = el('div', 'zdz-tour-bar');
    if (paused) {
      bar.appendChild(btn(label('resume', 'Pick up where I left off'), 'zdz-tour-btn zdz-tour-btn-primary', function () { resume(); }));
      bar.appendChild(btn(label('restart', 'Start over'), 'zdz-tour-btn', function () { start(''); }));
    } else {
      bar.appendChild(btn(label('start', 'Start the tour'), 'zdz-tour-btn zdz-tour-btn-primary', function () { start(''); }));
    }
    bar.appendChild(btn(paused ? label('hide', 'Hide this') : label('not_now', 'Not now'), 'zdz-tour-link', function () { save('hidden'); hideOffer(); drawSettings(); }));
    box.appendChild(bar);
    if (top && top.parentNode === dash) { dash.insertBefore(box, top.nextSibling); } else { dash.insertBefore(box, dash.firstChild); }
  }

  // Settings → Guided tour: the person's own tour while it is offered, so it can be taken again, and for an
  // administrator the team tours' switch and previews. Rows like the rows around them.
  function drawSettings() {
    var host = d.getElementById('settings-tour');
    if (!host) { return; }
    host.innerHTML = '';
    var team = cfg().team;
    if (!cfg().available && !team) { host.hidden = true; return; }
    host.appendChild(el('h4', '', label('settings_row', 'Guided tour')));
    if (cfg().available) { host.appendChild(ownRow()); }
    if (team) { drawTeam(host, team); }
    host.hidden = false;
  }

  function ownRow() {
    var st = state(), at = indexOf(st.step), paused = (st.status === 'paused' || st.status === 'running') && at > 0;
    var row = el('div', 'settings-row zdz-consent-row');
    var left = el('div', 'sr-left'), text = el('span', 'zdz-consent-text');
    text.appendChild(el('span', 'sr-label', label('settings_label', 'Show me around')));
    var sub = el('span', 'zdz-consent-sub', paused
      ? fmt(label('settings_paused', 'Paused at step %1$d of %2$d.'), at + 1, steps().length)
      : (cfg().preview && label('settings_preview', '')) || label('settings_hint', 'A click-through of the app and one test job.'));
    sub.id = 'zdz-tour-settings-sub';
    text.appendChild(sub);
    left.appendChild(text);
    row.appendChild(left);
    var right = el('div', 'sr-right zdz-tour-settings-actions');
    var add = function (text, cls, fn) {
      var b = btn(text, 'zdz-btn ' + cls, fn);
      b.setAttribute('aria-describedby', 'zdz-tour-settings-sub');
      right.appendChild(b);
    };
    if (paused) {
      add(label('resume', 'Pick up where I left off'), 'zdz-btn--primary', function () { resume(); });
      add(label('restart', 'Start over'), 'zdz-btn--secondary', function () { start(''); });
    } else {
      add(label('start', 'Start the tour'), 'zdz-btn--secondary', function () { start(''); });
    }
    row.appendChild(right);
    return row;
  }

  // The team tours: one switch (on: everyone else is offered their own tour), and a link per role to see its tour.
  function drawTeam(host, team) {
    var row = el('div', 'settings-row zdz-consent-row zdz-tour-team-row');
    var left = el('div', 'sr-left'), text = el('span', 'zdz-consent-text');
    var name = el('span', 'sr-label', label('team_title', 'Tours for your team'));
    name.id = 'zdz-tour-team-label';
    text.appendChild(name);
    var sub = el('span', 'zdz-consent-sub', team.on ? label('team_on', '') : label('team_off', ''));
    sub.id = 'zdz-tour-team-sub';
    text.appendChild(sub);
    left.appendChild(text);
    row.appendChild(left);
    var right = el('div', 'sr-right');
    var sw = el('button', 'zdz-push-switch zdz-tour-team-switch');
    sw.type = 'button';
    sw.setAttribute('role', 'switch');
    sw.setAttribute('aria-checked', team.on ? 'true' : 'false');
    sw.setAttribute('aria-label', label('team_switch', 'Offer tours to your team'));
    sw.setAttribute('aria-describedby', 'zdz-tour-team-sub');
    var knob = el('span', 'zdz-push-knob');
    knob.setAttribute('aria-hidden', 'true');
    sw.appendChild(knob);
    sw.addEventListener('click', function () { switchTeam(team, !team.on, sw); });
    right.appendChild(sw);
    row.appendChild(right);
    host.appendChild(row);
    var roles = Array.isArray(team.roles) ? team.roles : [];
    if (!roles.length) { return; }
    var box = el('div', 'zdz-tour-previews');
    var lead = el('p', 'zdz-tour-previews-lead', label('team_preview', 'See the tour a role gets:'));
    lead.id = 'zdz-tour-previews-lead';
    box.appendChild(lead);
    var list = el('div', 'zdz-tour-previews-list');
    list.setAttribute('role', 'group');
    list.setAttribute('aria-labelledby', 'zdz-tour-previews-lead');
    roles.forEach(function (r) {
      if (!r || !r.url || !r.label) { return; }
      var a = el('a', 'zdz-btn zdz-btn--secondary zdz-btn--sm', r.label);
      a.href = r.url;
      list.appendChild(a);
    });
    box.appendChild(list);
    host.appendChild(box);
  }

  function switchTeam(team, on, sw) {
    if (!team.rest || sw.disabled) { return; }
    sw.disabled = true;
    sw.classList.add('is-busy');
    var failed = function () {
      sw.disabled = false;
      sw.classList.remove('is-busy');
      toast(label('team_failed', 'That did not save. Try again.'));
    };
    try {
      fetch(team.rest, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': (w.zdzData && w.zdzData.nonce) || '' },
        body: JSON.stringify({ on: on })
      }).then(function (r) { return r.ok ? r.json() : Promise.reject(r.status); })
        .then(function (j) {
          team.on = !!(j && j.on);
          drawSettings();
          var again = d.querySelector('.zdz-tour-team-switch');
          if (again) { try { again.focus({ preventScroll: true }); } catch (e) { again.focus(); } }
        })
        .catch(failed);
    } catch (e) { failed(); }
  }

  /* ---------------------------------------------------------------- the ask bar */

  function norm(q) { return String(q || '').toLowerCase().replace(/[^a-z0-9 ]+/g, ' ').replace(/\s+/g, ' ').trim(); }

  function asksForTour(q) {
    q = norm(q);
    if (q.length < 3) { return false; }
    if (/\btour\b/.test(q)) { return true; }
    var list = Array.isArray(cfg().phrases) ? cfg().phrases : [];
    for (var i = 0; i < list.length; i++) {
      var p = norm(list[i]);
      if (p && (p === q || p.indexOf(q) === 0 || q.indexOf(p) === 0)) { return true; }
    }
    return false;
  }

  // The ask bar draws its own rows as the person types; when the words ask for the tour, its row goes first, so
  // Enter (which presses the first row) starts it.
  function askRow() {
    var input = d.getElementById('cmd-input'), results = d.getElementById('cmd-results');
    if (!input || !results) { return; }
    var add = function () {
      var had = results.querySelector('.zdz-tour-cmd');
      if (!asksForTour(input.value)) { if (had) { had.parentNode.removeChild(had); } return; }
      if (had) { if (results.firstChild !== had) { results.insertBefore(had, results.firstChild); } return; }
      var b = el('button', 'cmd-item zdz-tour-cmd');
      b.type = 'button';
      var ic = el('div', 'ci-icon');
      ic.style.background = 'var(--sys-brand, #433FBD)';
      ic.style.color = 'var(--sys-text-brand, #FFFFFF)';
      var i = el('i');
      i.setAttribute('data-lucide', 'map');
      ic.appendChild(i);
      var tx = el('div', 'ci-text');
      tx.appendChild(el('div', 'ci-name', label('cmd_name', 'Start the guided tour')));
      tx.appendChild(el('div', 'ci-desc', label('settings_hint', 'A click-through of the app and one test job.')));
      b.appendChild(ic);
      b.appendChild(tx);
      b.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        if (typeof w.closeCmdPalette === 'function') { w.closeCmdPalette(); }
        input.value = '';
        // Enter in the ask bar lands here. Start once that key is done, or it would press the card's Next too.
        setTimeout(function () { start(''); }, 0);
      });
      results.insertBefore(b, results.firstChild);
      if (typeof w.refreshIcons === 'function') { try { w.refreshIcons(); } catch (err) { /* the row still works */ } }
    };
    input.addEventListener('input', add);
    input.addEventListener('focus', function () { setTimeout(add, 0); });
  }

  function boot() {
    if (!cfg().available) { drawSettings(); return; }
    drawSettings();
    askRow();
    var asked = false;
    try {
      var u = new URL(w.location.href);
      if (u.searchParams.get('zdz_tour') === 'start') {
        asked = true;
        u.searchParams.delete('zdz_tour');
        w.history.replaceState(w.history.state, '', u.pathname + (u.search ? u.search : '') + u.hash);
      }
    } catch (e) { /* an old browser: the offer still shows */ }
    // the home screen draws its tiles after load; start once it is ready
    var ready = function () { if (asked) { start(''); } else { drawOffer(); } };
    if (d.body.classList.contains('zdz-ready')) { setTimeout(ready, 300); }
    else {
      var waited = 0;
      var t = setInterval(function () {
        waited += 250;
        if (d.body.classList.contains('zdz-ready') || waited >= 6000) { clearInterval(t); ready(); }
      }, 250);
    }
  }

  w.ZDZ = w.ZDZ || {};
  w.ZDZ.tour = { start: function (id) { return start(id || ''); }, resume: resume, end: function () { if (S.on) { end(); } }, active: function () { return S.on; } };

  if (d.readyState === 'loading') { d.addEventListener('DOMContentLoaded', boot); } else { boot(); }
})(window, document);
