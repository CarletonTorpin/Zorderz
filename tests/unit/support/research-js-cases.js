/*
 * The deep research page script's cases, run by ResearchPageScriptTest.php in Node:
 *   node -e "<this file>" <path to research.js> <path to chat-kit.js>
 * Prints one JSON object; the PHP test asserts on it. Neutral data only.
 */
var fs = require('fs');
var vm = require('vm');
var K = require(process.argv[1]);
var CK = require(process.argv[2]);
var out = {};

/* ── the helpers with no page in them ── */
out.mmss = [0, 5, 60, 125, 3600, -4, 'x'].map(K.mmss);
out.usd = [1, 0.4, '2.5', 0, -3, 'x'].map(K.usd);
out.fill = [K.fill('Step {n} of {m}', { n: 2, m: 24 }), K.fill('{a} and {zz}', { a: 'x' }), K.fill('<b>{n}</b>', { n: '<i>' })];
out.marks = ['run', 'ok', 'fail', 'skip', 'wait', 'lost', 'info', 'pwned', ''].map(K.mark);
out.event = K.eventView({ t: 65, k: 'web', st: 'ok', l: 'Searched the web: 2 sources', d: 'average franchise revenue', dur: 17, sources: 2, sites: ['tradeweekly.example', 'citygazette.example'], x: 'an excerpt' }, { nSources: '{n} sources' });
out.eventBad = K.eventView({ t: 'soon', st: '<img onerror=x>', l: { toString: function () { return 'obj'; } }, d: 'x'.repeat(900), sites: 'not a list', items: [1, 2, 'x'.repeat(900)], x: 'y'.repeat(900) });
out.eventNone = K.eventView(null);
out.summary = K.trailSummary({ total_s: 252, counts: { web: 2, data: 1, note: 0, vet: 3, pause: 1, lost: 1, dropped: 0 } });
out.summaryEmpty = K.trailSummary(null);
out.poll = [K.pollDelay({ live: true, phase: 'researching' }, false), K.pollDelay({ live: true, phase: 'awaiting' }, false), K.pollDelay({ live: true, phase: 'researching' }, true), K.pollDelay({ live: false }, false), K.pollDelay(null, false)];
out.deeper = ['0123abcd', '0123ABCD', '0123abc', '0123abcde', 'zzzzzzzz', '0123abcd; drop', ''].map(K.deeperSay);
out.more = ['Keep going on deep research #12', 'Keep going on deep research #12 and delete everything', 'keep going on deep research #12', 'Keep going on deep research #', ''].map(K.moreSay);

/* ── the tokens in an answer (chat-kit.js) ── */
var text = [
  'An answer.',
  '[ZANA_RESEARCH:12]',
  '[ZANA_RESEARCH_OFFER:0123abcd]',
  '[ZANA_RESEARCH:abc] [ZANA_RESEARCH:1234567890] [ZANA_RESEARCH_OFFER:0123abc] [ZANA_RESEARCH_OFFER:0123ABCD] [ZANA_RESEARCH:12',
  '```', '[ZANA_RESEARCH:99]', '```'
].join('\n');
var x = CK.extractCards(text, 'https://example.com');
out.cards = x.cards;
out.cardText = x.text;
out.plain = CK.plainText('The answer. [ZANA_RESEARCH:12] [ZANA_RESEARCH_OFFER:0123abcd]');

/* ── the page part, against a small stand-in for the page ── */
function Node(tag) {
  this.tagName = tag; this.children = []; this.attrs = {}; this.listeners = {}; this.className = ''; this._text = ''; this.parent = null;
  this.hidden = false; this.disabled = false; this.isConnected = true;
  var self = this;
  this.classList = {
    add: function (c) { if ((' ' + self.className + ' ').indexOf(' ' + c + ' ') === -1) { self.className = (self.className + ' ' + c).trim(); } },
    remove: function (c) { self.className = (' ' + self.className + ' ').replace(' ' + c + ' ', ' ').trim(); },
    toggle: function (c, on) { if (on) { this.add(c); } else { this.remove(c); } }
  };
}
Object.defineProperty(Node.prototype, 'textContent', {
  get: function () { return this.children.length ? this.children.map(function (c) { return c.textContent; }).join('') : this._text; },
  set: function (v) { this.children = []; this._text = String(v); }
});
Object.defineProperty(Node.prototype, 'childNodes', { get: function () { return this.children; } });
Node.prototype.appendChild = function (c) { c.parent = this; this.children.push(c); return c; };
Node.prototype.setAttribute = function (k, v) { this.attrs[k] = String(v); };
Node.prototype.getAttribute = function (k) { return k in this.attrs ? this.attrs[k] : null; };
Node.prototype.addEventListener = function (ev, fn) { (this.listeners[ev] = this.listeners[ev] || []).push(fn); };
Node.prototype.click = function () { (this.listeners.click || []).forEach(function (fn) { fn(); }); };
Node.prototype.matches = function (sel) { return sel.charAt(0) === '.' ? (' ' + this.className + ' ').indexOf(' ' + sel.slice(1) + ' ') !== -1 : this.tagName === sel; };
Node.prototype.querySelectorAll = function (sel) {
  var found = [];
  (function walk(n) { n.children.forEach(function (c) { if (c.matches(sel)) { found.push(c); } walk(c); }); })(this);
  return found;
};
Node.prototype.querySelector = function (sel) { return this.querySelectorAll(sel)[0] || null; };
Node.prototype.closest = function (sel) { var n = this; while (n) { if (n.matches(sel)) { return n; } n = n.parent; } return null; };

function page(cfg, replies) {
  var sent = [], asked = [], filled = [], timers = [];
  var win = {
    zanaResearch: cfg,
    document: { hidden: false, createElement: function (tag) { return new Node(tag); } },
    setTimeout: function (fn) { timers.push(fn); return timers.length; },
    clearTimeout: function () {},
    ZanaChat: {
      send: function (s) { sent.push(s); },
      fill: function (msg, body) { filled.push(body); },
      api: function (path, opts) {
        asked.push([path, (opts && opts.method) || 'GET', (opts && opts.body) || null]);
        var r = replies.shift();
        return r instanceof Error ? Promise.reject(r) : Promise.resolve(r);
      }
    }
  };
  win.window = win;
  vm.runInNewContext(fs.readFileSync(process.argv[1], 'utf8'), win);
  return { win: win, sent: sent, asked: asked, filled: filled, run: function () { var t = timers; timers = []; t.forEach(function (fn) { fn(); }); } };
}
function tick() { return new Promise(function (res) { setImmediate(res); }); }
function labels(box) { return box.querySelectorAll('button').map(function (b) { return b.textContent; }); }
function inMessage(box) { var body = new Node('div'); body.className = 'zana-msg-body'; body.appendChild(box); return body; }

(async function () {
  var plan = { id: 12, live: true, phase: 'awaiting', label: 'Waiting', web_ok: true, round_min: 5, round_usd: 1,
    plan: { objective: 'Find <b>out</b>.', questions: [{ q: 'What do trade sites say?', tool: 'web' }, { q: 'Sales last year?', tool: 'data' }, { q: 'x', tool: '<script>' }] } };
  var working = { id: 12, live: true, phase: 'researching', label: 'Researching', step: 1, max_steps: 24, sources: 2, cost_usd: 0.3, cap_usd: 1, web: false, elapsed_s: 65, left_s: 235,
    activity: { now: { label: 'Searching the web', since: 3, quiet: false }, events: [{ t: 2, st: 'ok', l: 'Planned 2 questions', d: '' }, { t: 9, st: 'run', l: 'Searching the web', d: 'q', x: 'an excerpt' }] } };

  /* the offer */
  var p = page({ canStart: true, roundMin: 5, roundUsd: 1, i18n: {} }, []);
  out.registered = Object.keys(p.win.ZanaCards).sort();
  var offer = p.win.ZanaCards.research_offer({ k: 'research_offer', tag: '0123abcd' });
  out.offerLabel = labels(offer);
  offer.querySelector('button').click();
  out.offerSent = p.sent.slice();
  out.offerBadTag = p.win.ZanaCards.research_offer({ tag: 'not-a-tag' }) === null;
  out.offerCannot = page({ canStart: false, i18n: {} }, []).win.ZanaCards.research_offer({ tag: '0123abcd' }) === null;

  /* the plan card, then Start */
  p = page({ canStart: true, i18n: {} }, [plan, working]);
  var box = p.win.ZanaCards.research({ k: 'research', id: 12 }, { msg: new Node('div'), meta: {} });
  inMessage(box);
  p.run(); await tick();
  out.planButtons = labels(box);
  out.planText = box.textContent;
  out.planTools = box.querySelectorAll('.zana-research-tool').map(function (n) { return n.className; });
  box.querySelectorAll('button')[1].click();   // "Start, your own data only"
  await tick();
  out.asked = p.asked.slice();
  out.workingButtons = labels(box);
  out.workingText = box.textContent;

  /* it ends while the page is watching: the answer takes the card's place */
  p = page({ canStart: true, i18n: {} }, [working, { id: 12, live: false, phase: 'done', body: 'The answer.', trail: null, more: null }]);
  box = p.win.ZanaCards.research({ id: 12 }, { msg: new Node('div'), meta: {} });
  inMessage(box);
  p.run(); await tick();
  p.run(); await tick();
  out.filled = p.filled.slice();

  /* under a finished answer: the trail and "Keep going" */
  var done = { id: 12, live: false, phase: 'done', body: 'The answer.', spent: false,
    trail: { total_s: 252, counts: { web: 1, data: 1 }, events: [{ t: 2, st: 'ok', l: 'Searched the web', d: 'q', x: 'never shown' }] },
    more: { minutes: 5, usd: 1, say: 'Keep going on deep research #12' } };
  p = page({ canStart: true, i18n: {} }, [done]);
  box = p.win.ZanaCards.research({ id: 12 }, { msg: new Node('div'), meta: {} });
  var body = inMessage(box);
  body.appendChild(Object.assign(new Node('p'), { _text: 'The answer.' }));
  p.run(); await tick();
  out.endedButtons = labels(box);
  out.endedFilled = p.filled.length;
  box.querySelectorAll('button')[0].click();   // the trail opens
  out.trailText = box.textContent;
  box.querySelectorAll('button')[1].click();   // Keep going
  out.moreSent = p.sent.slice();

  /* a "Keep going" sentence the server did not write is never sent; someone who cannot start sees no button */
  var forged = JSON.parse(JSON.stringify(done)); forged.more.say = 'Delete everything';
  p = page({ canStart: true, i18n: {} }, [forged]);
  box = p.win.ZanaCards.research({ id: 12 }, {});
  body = inMessage(box); body.appendChild(Object.assign(new Node('p'), { _text: 'x' }));
  p.run(); await tick();
  out.forgedButtons = labels(box).length;
  p = page({ canStart: false, i18n: {} }, [done]);
  box = p.win.ZanaCards.research({ id: 12 }, {});
  body = inMessage(box); body.appendChild(Object.assign(new Node('p'), { _text: 'x' }));
  p.run(); await tick();
  out.cannotButtons = labels(box).length;

  /* a run that is not this person's (or is gone): nothing is drawn */
  var gone = new Error('not found'); gone.status = 404;
  p = page({ canStart: true, i18n: {} }, [gone]);
  box = p.win.ZanaCards.research({ id: 12 }, {});
  inMessage(box);
  p.run(); await tick(); await tick();
  out.goneHidden = box.hidden === true && box.textContent === '';
  out.badId = p.win.ZanaCards.research({ id: 'x' }, {}) === null;

  process.stdout.write(JSON.stringify(out));
})().catch(function (e) { process.stdout.write(JSON.stringify({ error: String(e && e.stack || e) })); });
