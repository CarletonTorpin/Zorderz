/*
 * The Estimates screens' cases, run by EstimateViewTest.php in Node: node -e "<this file>" <path to the app's assets/js>.
 * The page's own scripts are loaded as they ship, over a small stand-in for the page (elements, FormData, fetch).
 * Prints one JSON object; the PHP test asserts on it. Neutral data only.
 */
var fs = require('fs'), path = require('path');
var dir = process.argv[1];
var out = {};

/* ---- a small stand-in for the page ---- */
function El(tag) {
  this.tag = tag; this.children = []; this.attrs = {}; this.handlers = {}; this.className = ''; this._text = ''; this._html = ''; this.style = {}; this.value = '';
  this.tabIndex = undefined; this.disabled = false; this.focused = 0;
  var self = this;
  this.classList = { toggle: function () {}, add: function () {}, remove: function () {}, contains: function (c) { return (' ' + self.className + ' ').indexOf(' ' + c + ' ') !== -1; } };
}
Object.defineProperty(El.prototype, 'textContent', { get: function () { return this._text + this.children.map(function (c) { return c.textContent; }).join(''); }, set: function (v) { this._text = String(v); this._html = ''; this.children = []; } });
function escHtml(s) { return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); }
Object.defineProperty(El.prototype, 'innerHTML', { get: function () { return this._html || escHtml(this._text); }, set: function (v) { this._html = String(v); this.children = []; this._text = ''; } });
Object.defineProperty(El.prototype, 'firstChild', { get: function () { return this.children[0] || null; } });
El.prototype.appendChild = function (c) { this.children.push(c); c.parent = this; return c; };
El.prototype.insertBefore = function (c) { this.children.unshift(c); c.parent = this; return c; };
El.prototype.removeChild = function (c) { this.children = this.children.filter(function (x) { return x !== c; }); return c; };
El.prototype.setAttribute = function (k, v) { this.attrs[k] = String(v); };
El.prototype.getAttribute = function (k) { return Object.prototype.hasOwnProperty.call(this.attrs, k) ? this.attrs[k] : null; };
El.prototype.addEventListener = function (t, fn) { (this.handlers[t] = this.handlers[t] || []).push(fn); };
El.prototype.fire = function (t, ev) { ev = ev || {}; ev.type = t; ev.target = ev.target || this; ev.stopPropagation = ev.stopPropagation || function () {}; ev.preventDefault = ev.preventDefault || function () {}; (this.handlers[t] || []).forEach(function (fn) { fn(ev); }); };
El.prototype.focus = function () { this.focused++; };
El.prototype.all = function (test, acc) { acc = acc || []; this.children.forEach(function (c) { if (test(c)) { acc.push(c); } c.all(test, acc); }); return acc; };
El.prototype.querySelector = function () { return null; };
El.prototype.querySelectorAll = function () { return []; };
El.prototype.closest = function () { return null; };
function hasClass(c) { return function (e) { return (' ' + e.className + ' ').indexOf(' ' + c + ' ') !== -1; }; }

function page(ids) {
  var els = {};
  (ids || []).forEach(function (id) { els[id] = new El('div'); els[id].id = id; });
  return {
    els: els,
    getElementById: function (id) { return els[id] || null; },
    createElement: function (t) { return new El(t); },
    addEventListener: function () {},
    querySelector: function () { return null; },
    querySelectorAll: function () { return []; },
    body: new El('body')
  };
}
function load(file, win, doc, extra) {
  var src = fs.readFileSync(path.join(dir, file), 'utf8');
  var names = ['window', 'document', 'setInterval', 'clearInterval', 'setTimeout', 'clearTimeout', 'FormData', 'fetch'];
  var e = extra || {};
  var vals = [win, doc, function () { return 0; }, function () {}, e.setTimeout || function (fn) { fn(); return 0; }, function () {}, e.FormData || function () {}, e.fetch || function () { return Promise.resolve({ json: function () { return {}; }, text: function () { return Promise.resolve('{}'); } }); }];
  Function.apply(null, names.concat([src])).apply(win, vals);
}

/* ---- the customer lookup ---- */
var win = {}, doc = page([]);
load('dashboard.js', win, doc);
var M = win.ZESTDash._match, clean = win.ZESTDash._cleanEmail;

out.email = {};
['alex@example.com.', 'alex@example.com,', 'alex@example.com;', 'alex@example.com!?', '<alex@example.com>', '(alex@example.com).', '"alex@example.com"', 'mailto:alex@example.com',
  "mailto:'alex@example.com'", '“alex@example.com”.', '﻿alex@example.com', 'Alex.Morgan@Example.com', 'a.l.e.x@example.com', 'unknown', 'unknown.', '', '.',
  'alex.example.com.', 'alex example@example.com', '!alex@example.com', '{alex}@example.com']
  .forEach(function (v) { out.email[v] = clean(v); });
out.emailNull = clean(null);
var t0 = Date.now();
out.emailLong = clean(new Array(100001).join('.') + 'alex@example.com');
out.emailLongFast = Date.now() - t0 < 500;

function P(id, f, l, more) { var c = { id: String(id), fname: f, lname: l, city: 'Sampletown', state: 'CA', auto_fill: false, match_score: 0 }; Object.keys(more || {}).forEach(function (k) { c[k] = more[k]; }); return c; }
var TEN = ['Robin', 'Casey', 'Riley', 'Sam', 'Devin', 'Jordan', 'Blake', 'Alex', 'Example', 'Sample'].map(function (f, i) { return P(i + 1, f, 'Examplewell'); });
var LISTS = {
  one: [P(1, 'Morgan', 'Examplewell')],
  two_far: [P(1, 'Morgan', 'Examplewell'), P(2, 'Robin', 'Examplewell')],
  two_letter: [P(1, 'Morgan', 'Examplewell'), P(2, 'Morgan', 'Examplewel')],
  two_changed: [P(1, 'Morgan', 'Examplewell'), P(2, 'Morgan', 'Examplewall')],
  two_same: [P(1, 'Morgan', 'Examplewell'), P(2, 'morgan', 'EXAMPLEWELL')],
  two_two_letters: [P(1, 'Morgan', 'Examplewell'), P(2, 'Morgan', 'Examplewe')],
  two_two_changed: [P(1, 'Morgan', 'Examplewell'), P(2, 'Morgan', 'Examplemill')],
  two_swapped: [P(1, 'Morgan', 'Examplewell'), P(2, 'Morgan', 'Exampleewll')],
  two_spaced: [P(1, 'Morgan', 'Example-Well'), P(2, 'Morgan ', 'Example well')],
  two_blank: [P(1, '', ''), P(2, '', '')],
  five: TEN.slice(0, 5),
  ten: TEN,
  ten_with_exact: TEN.slice(0, 9).concat([P(10, 'Morgan', 'Examplewell')]),
  near_then_rest: [P(1, 'Morgan', 'Examplewell'), P(2, 'Morgan', 'Examplewel'), P(3, 'Robin', 'Examplewell'), P(4, 'Casey', 'Examplewell')],
  three_same: [P(1, 'Morgan', 'Examplewell'), P(2, 'Morgan', 'Examplewell'), P(3, 'Morgan', 'Examplewell')],
  nickname_first: [P(1, 'Morgana', 'Examplewell'), P(2, 'Morgan', 'Examplewell')],
  couple_first: [P(1, 'Sam & Morgan', 'Examplewell'), P(2, 'Morgan', 'Examplewell')],
  same_split: [P(1, 'Morgan', 'Examplewell'), P(2, 'Morganna', 'Examplewell'), P(3, 'Morgan', 'Examplewell')],
  none_exact: [P(1, 'Robin', 'Examplewell'), P(2, 'Morganna', 'Examplewell'), P(3, 'Jordan', 'Examplewell')],
  spaced_exact: [P(1, 'Robin', 'Examplewell'), P(2, 'Morgan', 'Example-Well')],
  two_contacts: [P(1, 'Morgan', 'Examplewell', { auto_fill: true }), P(2, 'Example', 'Property Co', { auto_fill: true }), P(3, 'Robin', 'Examplewell')],
  contact_other_name: [P(1, 'Example', 'Property Co', { auto_fill: true }), P(2, 'Robin', 'Examplewell'), P(3, 'Morgan', 'Examplewell')],
  none: []
};
out.match = {};
Object.keys(LISTS).forEach(function (name) {
  var clients = LISTS[name], key = M.key('Morgan', 'Examplewell');
  var order = M.order(clients, key), up = M.upFront(clients, order, key);
  out.match[name] = { shown: order.slice(0, up).map(function (i) { return clients[i].id; }), more: clients.length - up, order: order.map(function (i) { return clients[i].id; }) };
});
out.near = [['a', 'a'], ['ab', 'a'], ['a', 'ab'], ['ab', 'ac'], ['abc', 'a'], ['ab', 'ba'], ['', 'a'], ['', '']].map(function (p) { return M.near(p[0], p[1]); });
out.keyOf = [M.key(' Mor-gan ', "O'Example"), M.key(null, undefined), M.key('Sam & Morgan', 'Example (Co).')];

// the cards as drawn: a list of ten, "More matches", and what Use carries
var list = new El('div'), used = [], state = { open: false };
var cards = M.draw(list, TEN, { fname: 'Morgan', lname: 'Examplewell' }, state, function (c) { used.push(c.id); });
var more = list.all(hasClass('zest-match-more'))[0];
out.draw = { before: cards.length, moreLabel: more ? more.textContent : '', cardTab: cards[0].tabIndex };
more.fire('click');
var after = list.all(hasClass('zest-match'));
out.draw.after = after.length;
out.draw.moreGone = list.all(hasClass('zest-match-more')).length === 0;
out.draw.focusOnThirdCard = after[2].focused === 1 && after.every(function (c, i) { return i === 2 || c.focused === 0; });
out.draw.noButtonFocused = list.all(hasClass('zest-match-use')).every(function (b) { return b.focused === 0; });
out.draw.staysOpen = state.open === true;
after[6].all(hasClass('zest-match-use'))[0].fire('click');
after[1].fire('click');
out.draw.used = used;
var evil = new El('div');
M.draw(evil, [P(1, 'Morgan<img src=x onerror=1>', 'Examplewell', { withheld: true, has_email: true })], { fname: '', lname: '' }, { open: false }, function () {});
out.draw.evilIsText = evil.all(hasClass('zest-row-name'))[0].textContent === 'Morgan<img src=x onerror=1> Examplewell' && evil.all(function (e) { return e.tag === 'img'; }).length === 0;
out.draw.withheld = evil.all(hasClass('zest-row-sub'))[0].textContent;

/* ---- the follow-up strip ---- */
var fwin = { zestWidget: { nonce: 'n' } }, fdoc = page([]);
load('followup.js', fwin, fdoc);
var F = fwin.ZESTFollowup;
function acts(html) { var m, re = /data-fu-act="([a-z]+)"/g, a = []; while ((m = re.exec(html))) { a.push(m[1]); } return a; }
function strip(e, meta) { F._state.meta = meta; return F._strip(e); }
var CAN = { can_send: true }, CANNOT = { can_send: false }, RO = { can_send: false, read_only: true };
var asked = { id: 4, state: 'open', to: 'AA', by: 'CC', words: 'CC asked AA to follow up', asked: '2026-01-05 10:00:00' };
var done = { id: 4, state: 'done', words: 'AA reached the customer: Still deciding', when: '2026-01-06 10:00:00', doc_state: 'written' };
out.strip = {
  none_admin: acts(strip({ id: 1, can_follow: true }, CAN)),
  none_sales: acts(strip({ id: 1, can_follow: true }, CANNOT)),
  none_closed: acts(strip({ id: 1, can_follow: false }, CAN)),
  history_only: acts(strip({ id: 1, can_follow: false, followup_history: true }, CAN)),
  mine: acts(strip({ id: 1, can_follow: true, followup: Object.assign({ mine: true }, asked) }, CANNOT)),
  asked_admin: acts(strip({ id: 1, can_follow: true, followup: asked }, CAN)),
  asked_by_me: acts(strip({ id: 1, can_follow: true, followup: Object.assign({ by_me: true }, asked) }, CANNOT)),
  asked_other: acts(strip({ id: 1, can_follow: true, followup: asked }, CANNOT)),
  tried: acts(strip({ id: 1, can_follow: true, followup: { id: 4, state: 'tried', words: 'AA tried, no answer yet' } }, CANNOT)),
  done: acts(strip({ id: 1, can_follow: true, followup: done }, CAN)),
  declined: acts(strip({ id: 1, can_follow: true, followup: Object.assign({ declined: true }, done) }, CAN)),
  failed: acts(strip({ id: 1, can_follow: true, followup: Object.assign({}, done, { doc_state: 'failed', doc_why: 'the billing system could not be read just now' }) }, CAN)),
  read_only: acts(strip({ id: 1, can_follow: true, followup: Object.assign({ mine: true, declined: true }, done, { doc_state: 'failed' }) }, RO)),
  read_only_none: strip({ id: 1, can_follow: true }, RO)
};
var mineHtml = strip({ id: 1, can_follow: true, followup: Object.assign({ mine: true }, asked) }, CANNOT);
out.stripWords = {
  yours: mineHtml.indexOf('Yours to do') !== -1,
  answers: (mineHtml.match(/data-fu-answer="(reached|tried|handled)"/g) || []).length,
  declinedTag: strip({ id: 1, can_follow: true, followup: Object.assign({ declined: true }, done) }, CAN).indexOf('>Declined<') !== -1,
  skippedSaysWhy: strip({ id: 1, can_follow: true, followup: Object.assign({}, done, { doc_state: 'skipped', doc_why: 'the follow-up line on billing estimates is switched off' }) }, CAN).indexOf('left as it is: the follow-up line on billing estimates is switched off') !== -1,
  escaped: strip({ id: 1, can_follow: true, followup: { id: 4, state: 'done', words: '<b>x</b> & "y"' } }, CAN).indexOf('&lt;b&gt;x&lt;/b&gt; &amp; &quot;y&quot;') !== -1,
  noProviderNamed: !/freshbooks|nutshell/i.test(fs.readFileSync(path.join(dir, 'followup.js'), 'utf8'))
};
var hist = F._history([{ state: 'withdrawn', asked: '2026-01-05 10:00:00', by: 'CC', to: 'AA', ask_note: '<i>typed</i>', tried: [{ who: 'AA', channel: 'Call', note: 'no pick-up' }],
  answer: 'reached', words: 'AA reached the customer: Still deciding', note: 'will decide', doc_line: 'Follow-up 6/1/2026 (AA): Customer is still deciding.', crm: 'posted',
  undone: [{ kind: 'answer', by: 'CC', words: 'AA reached the customer', note: 'old words' }] }]);
out.history = {
  takenBack: hist.indexOf('taken back') !== -1, escaped: hist.indexOf('&lt;i&gt;typed&lt;/i&gt;') !== -1 && hist.indexOf('<i>typed</i>') === -1,
  line: hist.indexOf('On the billing estimate') !== -1, crm: hist.indexOf('Noted on the lead in the CRM') !== -1, undone: hist.indexOf('It had said: old words') !== -1,
  empty: F._history([])
};

/* ---- the look-only sheet ---- */
var pwin = { zestWidget: { nonce: 'n' } };
load('privacy-view.js', pwin, page([]));
var sheet = pwin.ZESTPrivacyView._html({ kind: 'estimate', number: 'Bcdf·AAAA1', customer: '<script>x</script>', test: true, salesperson: 'Bcdf·BBBB2 & [name]', status: 'sent', created: '2026-01-05 10:00:00',
  street: 'Bcdf·CCCC3', state: 'CA', has_email: true, has_phone: false, in_billing: true, in_crm: false, lines: [{ words: true, qty: 3 }, { words: false, qty: 1.5 }], left_out: 1,
  followup: { words: 'Bcdf·BBBB2 reached the customer: Still deciding', doc_state: 'skipped', doc_why: 'the billing system is not connected' }, followup_lines: ['Follow-up 6/1/2026: Customer will call back.'], notes_hidden: 2 });
out.sheet = {
  escaped: sheet.indexOf('&lt;script&gt;x&lt;/script&gt;') !== -1 && sheet.indexOf('<script>') === -1,
  noControl: !/<(button|input|select|textarea|a|form)\b/i.test(sheet),
  noMoney: sheet.indexOf('$') === -1,
  rows: (sheet.match(/<tr>/g) || []).length,
  noWords: sheet.indexOf('(no words)') !== -1,
  hidden: sheet.indexOf('2 other lines of notes are not shown') !== -1,
  ours: sheet.indexOf('Customer will call back.') !== -1,
  test: sheet.indexOf('zest-w-pv-test') !== -1,
  empty: pwin.ZESTPrivacyView._html({}).indexOf('No lines') !== -1
};
var opened = [];
pwin.ZDZ = { ui: { sheet: function () { return { el: { querySelector: function () { return null; } } }; }, ajax: function (a, b) { opened.push([a, Object.keys(b).sort().join(',')]); return Promise.resolve({}); } } };
['7', 7, '1001x', '0', '', "7' OR 1", -3].forEach(function (id) { pwin.ZESTPrivacyView.open(id); });
out.sheetAsks = opened;

/* ---- the widget's lists, for the team and look-only ---- */
function widget(cfg, answer) {
  var w = { zestWidget: cfg, ZESTFollowup: { decorate: function (el, data) { w.decorated = (w.decorated || 0) + 1; w.meta = data.followups; } }, ZESTPrivacyView: { open: function (id) { (w.looked = w.looked || []).push(id); } } };
  var d = page(['zest-widget', 'zest-open-list', 'zest-history-list']);
  var sent = [];
  function FD() { this.f = {}; }
  FD.prototype.append = function (k, v) { this.f[k] = v; };
  load('widget.js', w, d, { FormData: FD, fetch: function (url, o) { sent.push(o.body.f.action); return { then: function (fn) { var r = fn({ json: function () { return answer; } }); return { then: function (g) { g(r); return { catch: function () {} }; }, catch: function () { return { then: function (g) { g(r); } }; } }; } }; } });
  return { win: w, doc: d, sent: sent, root: d.els['zest-widget'], list: d.els['zest-open-list'] };
}
var ROWS = [
  { id: 3, customer: '<b>Morgan</b>', number: '1003', status: 'sent', items: 2, followup: { state: 'open' }, declined: false },
  { id: 2, customer: 'Robin', number: '', status: 'created', items: 1, followup: null, declined: false },
  { id: 1, customer: 'Casey', number: '1001', status: 'sent', items: 1, followup: { state: 'done', declined: true }, declined: true }
];
var team = widget({ ajaxurl: '/a', nonce: 'n' }, { success: true, data: { rows: ROWS, followups: { can_send: true } } });
var html = team.list.innerHTML;
out.widget = {
  sent: team.sent,
  cards: (html.match(/class="zest-fu-card"/g) || []).length,
  strips: (html.match(/class="zest-fu-strip" data-fu-id="\d+"/g) || []).length,
  declinedGroup: /<details class="zest-fu-declined"><summary>Declined estimates \(1\)<\/summary><div class="zest-fu-card"><div class="zest-row" data-id="1"/.test(html),
  declinedLast: html.indexOf('data-id="1"') > html.indexOf('data-id="2"'),
  escaped: html.indexOf('&lt;b&gt;Morgan&lt;/b&gt;') !== -1 && html.indexOf('<b>Morgan</b>') === -1,
  decorated: team.win.decorated || 0
};
var old = widget({ ajaxurl: '/a', nonce: 'n' }, { success: true, data: { rows: ROWS } });
out.widgetNoFollowups = { cards: (old.list.innerHTML.match(/zest-fu-card/g) || []).length, rows: (old.list.innerHTML.match(/class="zest-row"/g) || []).length, decorated: old.win.decorated || 0 };

out.look = {};
[true, 1, '1'].forEach(function (flag) {
  var pv = widget({ ajaxurl: '/a', nonce: 'n', privacyView: flag }, { success: true, data: { rows: [{ id: 7, customer: 'Bcdf·AAAA1', number: 'Bcdf·BBBB2', status: 'sent', items: 1, test: true }], followups: { read_only: true } } });
  var row = { getAttribute: function (k) { return k === 'data-id' ? '7' : null; } };
  pv.root.fire('click', { target: { closest: function () { return row; } } });
  pv.root.fire('keydown', { key: 'a', target: { closest: function () { return row; } } });
  pv.root.fire('keydown', { key: 'Enter', target: { closest: function () { return row; } } });
  pv.root.fire('click', { target: { closest: function () { return null; } } });
  out.look[typeof flag + ':' + flag] = { sent: pv.sent, looked: pv.win.looked || [], testTag: pv.list.innerHTML.indexOf('zest-w-pv-test') !== -1, readOnly: !!(pv.win.meta && pv.win.meta.read_only) };
});
['', '0', 0, false, undefined, 'yes'].forEach(function (flag) {
  var w = widget({ ajaxurl: '/a', nonce: 'n', privacyView: flag }, { success: true, data: { rows: ROWS, followups: {} } });
  out.look['off:' + String(flag)] = (w.root.handlers.click || []).length;
});
var src = fs.readFileSync(path.join(dir, 'widget.js'), 'utf8');
out.lookGuard = /if \( pv && LOOK\.indexOf\( action \) === -1 \) \{\s+return Promise\.resolve\( \{ success: false/.test(src) && /var LOOK = \[ 'zest_list_open', 'zest_history' \];/.test(src);

process.stdout.write(JSON.stringify(out));
