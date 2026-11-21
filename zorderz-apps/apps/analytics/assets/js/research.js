/**
 * Zorderz Analytics — deep research in the chat.
 *
 * The server writes two tokens into an answer (chat-kit.js turns them into cards; chat.js hands them here):
 *   - a run ([ZANA_RESEARCH:<id>]): drawn as the plan card while it waits for Start, as the progress card while it
 *     works (what it is doing now, the steps so far, Stop), and, under the finished answer, as "How this was
 *     researched" with the "Keep going" button when the run stopped on its time or its cost;
 *   - an offer ([ZANA_RESEARCH_OFFER:<tag>]): the "Go deeper" button under a normal answer.
 * A token is only a reference: the server checks whose run it is on every request, and decides everything.
 *
 * Every word a run reports is written as text, never as HTML. Every label comes from zanaResearch.i18n. No business,
 * person or product is named here.
 *
 * The first part is plain helpers with no page in them: the tests run them in Node.
 */
(function (root) {
  'use strict';

  /* ── helpers with no page in them ───────────────────────────────────────────────────────────────── */

  function str(v) { return v == null ? '' : String(v); }
  function int(v) { var n = parseInt(v, 10); return isFinite(n) && n > 0 ? n : 0; }

  var Kit = {
    /** 125 → "2:05" */
    mmss: function (s) {
      s = int(s);
      var sec = s % 60;
      return Math.floor(s / 60) + ':' + (sec < 10 ? '0' : '') + sec;
    },

    /** 1 → "$1.00" */
    usd: function (n) {
      var v = parseFloat(n);
      return '$' + (isFinite(v) && v > 0 ? v : 0).toFixed(2);
    },

    /** "{n} of {m}" with its values put in; an unknown key is left as it is. */
    fill: function (text, values) {
      return str(text).replace(/\{([a-z]+)\}/g, function (all, k) {
        return values && values[k] != null ? str(values[k]) : all;
      });
    },

    /** The mark in front of an event, by how it ended. */
    mark: function (st) {
      return { run: '…', ok: '✓', fail: '✕', skip: '–', wait: '⏸', lost: '?', info: '•' }[str(st)] || '•';
    },

    /**
     * One event as the card writes it: { time, mark, state, label, detail, extras[], excerpt }. Nothing here is
     * trusted as HTML: every field is a string the page writes as text.
     */
    eventView: function (e, T) {
      e = e && typeof e === 'object' ? e : {};
      T = T || {};
      var extras = [];
      if (e.dur != null && int(e.dur) > 0) { extras.push(Kit.mmss(e.dur)); }
      if (e.sources != null) { extras.push(Kit.fill(T.nSources || '{n} sources', { n: int(e.sources) })); }
      if (Array.isArray(e.sites) && e.sites.length) { extras.push(e.sites.slice(0, 8).map(str).join(', ')); }
      return {
        time: Kit.mmss(e.t),
        mark: Kit.mark(e.st),
        state: /^(run|ok|fail|skip|wait|lost|info)$/.test(str(e.st)) ? str(e.st) : 'info',
        label: str(e.l).slice(0, 200),
        detail: str(e.d).slice(0, 500),
        extras: extras,
        items: Array.isArray(e.items) ? e.items.slice(0, 14).map(function (x) { return str(x).slice(0, 240); }) : [],
        excerpt: str(e.x).slice(0, 320)
      };
    },

    /** The totals of a finished run, in one line: "2 web searches · 1 data answer · 3 facts checked · 4:12". */
    trailSummary: function (trail, T) {
      T = T || {};
      var c = (trail && trail.counts) || {};
      var bits = [];
      function add(n, one, many) { n = int(n); if (n > 0) { bits.push(Kit.fill(n === 1 ? one : many, { n: n })); } }
      add(c.web, T.webOne || '{n} web search', T.webMany || '{n} web searches');
      add(c.data, T.dataOne || '{n} data answer', T.dataMany || '{n} data answers');
      add(c.note, T.noteOne || '{n} finding kept', T.noteMany || '{n} findings kept');
      add(c.vet, T.vetOne || '{n} fact checked', T.vetMany || '{n} facts checked');
      add(c.lost, T.lostOne || '{n} step restarted', T.lostMany || '{n} steps restarted');
      if (trail && int(trail.total_s) > 0) { bits.push(Kit.mmss(trail.total_s)); }
      return bits.join(' · ');
    },

    /** How long to wait before asking again: sooner while it works, slower in a hidden tab or a waiting plan. */
    pollDelay: function (payload, hidden) {
      if (!payload || !payload.live) { return 0; }
      if (hidden) { return 15000; }
      return payload.phase === 'awaiting' ? 6000 : 2500;
    },

    /** The sentence a "Go deeper" button sends; '' unless the tag is exactly eight hex characters. */
    deeperSay: function (tag) {
      return /^[a-f0-9]{8}$/.test(str(tag)) ? 'Deep research on my question above #' + tag : '';
    },

    /** The sentence a "Keep going" button sends, exactly as the server wrote it; '' for anything else. */
    moreSay: function (say) {
      return /^Keep going on deep research #\d{1,9}$/.test(str(say)) ? str(say) : '';
    }
  };

  if (typeof module !== 'undefined' && module.exports) { module.exports = Kit; return; }

  /* ── the page ───────────────────────────────────────────────────────────────────────────────────── */

  var CFG = root.zanaResearch || {};
  var T = CFG.i18n || {};
  var doc = root.document;
  if (!doc) { return; }

  function t(key, fallback) { return (T[key] != null && T[key] !== '') ? String(T[key]) : (fallback || ''); }
  function chat() { return root.ZanaChat || null; }
  function h(tag, cls, text) {
    var e = doc.createElement(tag);
    if (cls) { e.className = cls; }
    if (text != null) { e.textContent = String(text); }
    return e;
  }
  function btn(cls, text) {
    var b = h('button', cls, text);
    b.type = 'button';
    return b;
  }
  function api(path, opts) {
    var c = chat();
    if (!c || typeof c.api !== 'function') { return Promise.reject(new Error(t('error', 'Something went wrong. Please try again.'))); }
    return c.api(path, opts || {});
  }
  function errText(e) { return (e && e.message) ? String(e.message) : t('error', 'Something went wrong. Please try again.'); }

  /* one line of the activity list */
  function eventRow(e, live) {
    var v = Kit.eventView(e, T);
    var li = h('li', 'zana-research-ev is-' + v.state);
    li.appendChild(h('span', 'zana-research-ev-time', v.time));
    li.appendChild(h('span', 'zana-research-ev-mark', v.mark));
    var main = h('span', 'zana-research-ev-main');
    main.appendChild(h('span', 'zana-research-ev-label', v.label));
    if (v.detail) { main.appendChild(h('span', 'zana-research-ev-detail', v.detail)); }
    if (v.extras.length) { main.appendChild(h('span', 'zana-research-ev-extra', v.extras.join(' · '))); }
    if (v.items.length) {
      var ul = h('ul', 'zana-research-ev-items');
      v.items.forEach(function (x) { ul.appendChild(h('li', '', x)); });
      main.appendChild(ul);
    }
    if (live && v.excerpt) { main.appendChild(h('span', 'zana-research-ev-excerpt', v.excerpt)); }
    li.appendChild(main);
    return li;
  }
  function eventList(events, live) {
    var ol = h('ol', 'zana-research-events');
    (Array.isArray(events) ? events : []).forEach(function (e) { ol.appendChild(eventRow(e, live)); });
    return ol;
  }

  /* a list that opens and closes, remembered on the card across redraws */
  function toggle(box, key, label, build) {
    var wrap = h('div', 'zana-research-toggle');
    var b = btn('zdz-btn zdz-btn--quiet zdz-btn--sm zana-research-toggle-btn', label);
    var body = h('div', 'zana-research-toggle-body');
    function show(open) {
      body.textContent = '';
      b.setAttribute('aria-expanded', open ? 'true' : 'false');
      if (open) { body.appendChild(build()); }
    }
    b.addEventListener('click', function () {
      box.__open = box.__open || {};
      box.__open[key] = !box.__open[key];
      show(box.__open[key]);
    });
    wrap.appendChild(b);
    wrap.appendChild(body);
    show(!!(box.__open && box.__open[key]));
    return wrap;
  }

  function note(box, text, bad) {
    var n = box.querySelector('.zana-research-note');
    if (!n) { n = h('p', 'zana-card-note zana-research-note'); n.setAttribute('role', 'status'); box.appendChild(n); }
    n.textContent = text || '';
    n.classList.toggle('is-bad', !!bad);
  }

  /* a press that asks the server and redraws with what comes back; one press at a time a card */
  function press(box, button, path, body, ctx) {
    if (box.__busy) { return; }
    box.__busy = true;
    Array.prototype.forEach.call(box.querySelectorAll('button'), function (b) { b.disabled = true; });
    button.textContent = t('working', 'Working…');
    api(path, { method: 'POST', body: body || {}, retry: 0 }).then(function (p) {
      box.__busy = false;
      draw(box, p, ctx);
    }).catch(function (e) {
      box.__busy = false;
      load(box, ctx, errText(e));
    });
  }

  /* ── the plan, waiting for Start ── */
  function planCard(box, p, ctx) {
    box.appendChild(h('div', 'zana-card-kicker', '🔬 ' + t('planTitle', 'Deep research: the plan')));
    var plan = p.plan || {};
    if (plan.objective) { box.appendChild(h('p', 'zana-research-objective', plan.objective)); }
    var ol = h('ol', 'zana-research-plan');
    (Array.isArray(plan.questions) ? plan.questions : []).forEach(function (q) {
      var li = h('li', '');
      var tool = str(q && q.tool);
      li.appendChild(h('span', 'zana-research-tool is-' + (/^(web|records|data)$/.test(tool) ? tool : 'data'),
        tool === 'web' ? t('toolWeb', 'the web') : (tool === 'records' ? t('toolRecords', 'your records') : t('toolData', 'your data'))));
      li.appendChild(h('span', 'zana-research-q', str(q && q.q)));
      ol.appendChild(li);
    });
    if (ol.childNodes.length) { box.appendChild(ol); }
    box.appendChild(h('p', 'zana-card-note', Kit.fill(t('planCost', 'Nothing more is spent until you press Start. One round: up to {min} min and {usd}.'), { min: int(p.round_min), usd: Kit.usd(p.round_usd) })));
    var row = h('div', 'zana-card-actions');
    if (p.web_ok) {
      var withWeb = btn('zdz-btn zdz-btn--primary', t('startWeb', 'Start, with the web'));
      withWeb.addEventListener('click', function () { press(box, withWeb, '/research/' + p.id + '/start', { web: true }, ctx); });
      row.appendChild(withWeb);
    }
    var own = btn('zdz-btn ' + (p.web_ok ? 'zdz-btn--secondary' : 'zdz-btn--primary'), p.web_ok ? t('startOwn', 'Start, your own data only') : t('start', 'Start'));
    own.addEventListener('click', function () { press(box, own, '/research/' + p.id + '/start', { web: false }, ctx); });
    row.appendChild(own);
    var cancel = btn('zdz-btn zdz-btn--quiet', t('cancel', 'Cancel'));
    cancel.addEventListener('click', function () { press(box, cancel, '/research/' + p.id + '/stop', {}, ctx); });
    row.appendChild(cancel);
    box.appendChild(row);
    if (!p.web_ok) { box.appendChild(h('p', 'zana-card-note', t('noWeb', 'Web research is off for your account, so this run uses the business\'s own data only.'))); }
  }

  /* ── working ── */
  function progressCard(box, p, ctx) {
    var head = h('div', 'zana-card-head');
    head.appendChild(h('span', 'zana-card-kicker', '🔬 ' + str(p.label)));
    head.appendChild(h('span', 'zana-card-app', Kit.mmss(p.elapsed_s) + (int(p.left_s) > 0 ? ' · ' + Kit.fill(t('left', '{t} left'), { t: Kit.mmss(p.left_s) }) : '')));
    box.appendChild(head);
    var act = p.activity || {};
    if (act.now && act.now.label) {
      var now = h('p', 'zana-research-now' + (act.now.quiet ? ' is-quiet' : ''), str(act.now.label));
      now.setAttribute('role', 'status');
      box.appendChild(now);
    }
    box.appendChild(h('p', 'zana-card-note', [
      Kit.fill(t('stepOf', 'Step {n} of {m}'), { n: int(p.step), m: int(p.max_steps) }),
      Kit.fill(t('nSources', '{n} sources'), { n: int(p.sources) }),
      Kit.fill(t('costOf', '{a} of {b}'), { a: Kit.usd(p.cost_usd), b: Kit.usd(p.cap_usd) }),
      p.web ? t('withWeb', 'with the web') : t('ownOnly', 'your own data only')
    ].join(' · ')));
    var events = Array.isArray(act.events) ? act.events : [];
    if (events.length) {
      box.appendChild(toggle(box, 'log', Kit.fill(t('showSteps', 'What it has done ({n})'), { n: events.length }), function () { return eventList(events, true); }));
    }
    var row = h('div', 'zana-card-actions');
    var stop = btn('zdz-btn zdz-btn--secondary', t('stop', 'Stop'));
    stop.addEventListener('click', function () { press(box, stop, '/research/' + p.id + '/stop', {}, ctx); });
    row.appendChild(stop);
    box.appendChild(row);
  }

  /* ── under the finished answer ── */
  function endedCard(box, p) {
    var trail = p.trail && typeof p.trail === 'object' ? p.trail : null;
    var events = trail && Array.isArray(trail.events) ? trail.events : [];
    if (events.length) {
      var sum = Kit.trailSummary(trail, T);
      box.appendChild(toggle(box, 'trail', t('howResearched', 'How this was researched') + (sum ? ' (' + sum + ')' : ''), function () { return eventList(events, false); }));
    }
    var say = p.more ? Kit.moreSay(p.more.say) : '';
    var c = chat();
    if (say && c && typeof c.send === 'function' && CFG.canStart) {
      var row = h('div', 'zana-card-actions');
      var more = btn('zdz-btn zdz-btn--secondary', '➕ ' + Kit.fill(t('keepGoing', 'Keep going (up to {min} min · {usd})'), { min: int(p.more.minutes), usd: Kit.usd(p.more.usd) }));
      more.addEventListener('click', function () { more.disabled = true; c.send(say); });
      row.appendChild(more);
      box.appendChild(row);
    } else if (p.spent) {
      box.appendChild(h('p', 'zana-card-note', t('spent', 'This run has used its whole time or cost limit.')));
    }
    box.classList.add('is-ended');
    if (!box.childNodes.length) { box.hidden = true; }
  }

  /* Is the card all there is in its message (the run's own message, before its answer was written)? */
  function aloneInMessage(box) {
    var body = box.closest ? box.closest('.zana-msg-body') : null;
    if (!body) { return false; }
    return body.textContent.replace(box.textContent, '').trim() === '';
  }

  function draw(box, p, ctx) {
    if (!box.isConnected) { return; }
    if (box.__timer) { root.clearTimeout(box.__timer); box.__timer = 0; }
    if (!p || typeof p !== 'object' || !int(p.id)) { box.hidden = true; return; }
    var c = chat();
    // the run has ended and this message still shows only its card: the answer takes the card's place
    if (!p.live && p.body && aloneInMessage(box) && ctx && ctx.msg && c && typeof c.fill === 'function') {
      c.fill(ctx.msg, str(p.body) + '\n\n[ZANA_RESEARCH:' + int(p.id) + ']', ctx.meta || {});
      return;
    }
    box.hidden = false;
    box.textContent = '';
    box.classList.remove('is-ended');
    if (!p.live) { endedCard(box, p); return; }
    if (p.phase === 'awaiting') { planCard(box, p, ctx); } else { progressCard(box, p, ctx); }
    var wait = Kit.pollDelay(p, !!doc.hidden);
    if (wait > 0) { box.__timer = root.setTimeout(function () { load(box, ctx); }, wait); }
  }

  function load(box, ctx, problem) {
    if (!box.isConnected) { return; }
    var id = int(box.getAttribute('data-run'));
    api('/research/' + id, { retry: 0 }).then(function (p) {
      draw(box, p, ctx);
      if (problem) { note(box, problem, true); }
    }).catch(function (e) {
      if (e && (e.status === 404 || e.status === 403)) { box.textContent = ''; box.hidden = true; return; }
      // the server did not answer just now: say so once, and ask again later
      note(box, problem || errText(e), true);
      box.__timer = root.setTimeout(function () { load(box, ctx); }, 8000);
    });
  }

  function researchCard(c, ctx) {
    var id = int(c && c.id);
    if (!id || !chat()) { return null; }
    var box = h('div', 'zana-card zana-research');
    box.setAttribute('data-run', String(id));
    box.appendChild(h('p', 'zana-card-note', t('loading', 'Deep research…')));
    // drawn once the card is on the page (chat.js adds it after this returns)
    root.setTimeout(function () { load(box, ctx || {}); }, 0);
    return box;
  }

  function offerCard(c) {
    var say = Kit.deeperSay(c && c.tag);
    var ch = chat();
    if (!say || !CFG.canStart || !ch || typeof ch.send !== 'function') { return null; }
    var box = h('div', 'zana-research-offer');
    var b = btn('zdz-btn zdz-btn--secondary zana-chip', '🔬 ' + Kit.fill(t('goDeeper', 'Go deeper (deep research: up to {min} min · {usd})'), { min: int(CFG.roundMin), usd: Kit.usd(CFG.roundUsd) }));
    b.addEventListener('click', function () { b.disabled = true; ch.send(say); });
    box.appendChild(b);
    return box;
  }

  root.ZanaCards = root.ZanaCards || {};
  root.ZanaCards.research = researchCard;
  root.ZanaCards.research_offer = offerCard;
  root.ZanaResearchKit = Kit;
})(typeof window !== 'undefined' ? window : this);
