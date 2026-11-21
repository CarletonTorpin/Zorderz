/*
 * Parts of Prep's widget that decide what a person sees for a shop job, lifted out of the shipped widget.js and run
 * on a few cases (PrepInternalWidgetTest.php).
 *
 *   node <this file> <path to widget.js>
 *
 * Each function is cut out of widget.js at the first closing brace where the cut is a complete function (the
 * JavaScript engine's own parser decides), so the test runs the shipped code, not a copy. The page is a stand-in: an
 * element keeps its children, its class, its attributes and its listeners, and escapes text the way a browser does.
 * Prints one JSON object.
 */
var fs = require('fs');
var src = fs.readFileSync(process.argv[2], 'utf8');

function lift(name) {
  var m = new RegExp('function ' + name + '\\s*\\(').exec(src);
  if (!m) { throw new Error('widget.js has no function ' + name); }
  for (var j = src.indexOf('}', m.index); j !== -1; j = src.indexOf('}', j + 1)) {
    var cut = src.slice(m.index, j + 1);
    try { new Function(cut); return cut; } catch (e) { /* not complete yet */ }
  }
  throw new Error('function ' + name + ' never closes');
}

function escapeText(s) { return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); }
function El(tag) {
  this.tag = tag; this.children = []; this.className = ''; this.attrs = {}; this.style = {}; this.listeners = {};
  this.disabled = false; this.onclick = null; this.html = ''; this.text = '';
  var self = this;
  this.classList = {
    add: function (c) { self.className = (self.className + ' ' + c).trim(); },
    remove: function (c) { self.className = self.className.split(' ').filter(function (x) { return x !== c; }).join(' '); }
  };
}
El.prototype.setAttribute = function (k, v) { this.attrs[k] = String(v); };
El.prototype.addEventListener = function (type, fn) { this.listeners[type] = fn; };
El.prototype.appendChild = function (c) { this.children.push(c); return c; };
Object.defineProperty(El.prototype, 'innerHTML', {
  get: function () { return this.html; },
  set: function (v) { this.html = String(v); if (this.html === '') { this.children = []; } }
});
Object.defineProperty(El.prototype, 'textContent', {
  get: function () { return this.text; },
  set: function (v) { this.text = String(v); this.html = escapeText(v); }
});

var page = {};
var document = { createElement: function (tag) { return new El(tag); }, getElementById: function (id) { return page[id] || null; } };
var visible = /var ATC_VISIBLE = (\d+);/.exec(src);
if (!visible) { throw new Error('widget.js no longer says how many jobs it shows before "Show more"'); }

var names = ['esc', 'hide', 'show', 'fmtIn', 'pageMargin', 'marginLabel', 'isShopCard', 'cardJobKey', 'wrapWithAssign', 'buildInternalCard', 'renderApprovedEmpty',
  'renderApprovedToCut', 'pageStockLabel', 'shopJobRef', 'renderNestingSVG', 'ioAttr', 'ioHoursText', 'ioCardLine'];
var colours = { meshBg: '#aaa', cutLine: '#111', grungy: '#777', label: '#000' };
var body = 'var ATC_VISIBLE = ' + visible[1] + ';\n' +
  'function $(id) { return document.getElementById(id); }\n' +
  'function planInternal() {} function openAssign() {} function setErr() {}\n' +
  'function buildApprovedCard(j) { var e = document.createElement("i"); e.job = j.job_key || ("lead:" + j.lead_id); return e; }\n' +
  names.map(lift).join('\n') + '\nreturn {' + names.map(function (n) { return n + ':' + n; }).join(',') + '};';

function widget(canManage) {
  return new Function('document', 'zprepWidgetData', 'PALETTE', body)(document, { canManage: canManage }, { grey: colours, black: colours, pageBg: '#fff', dim: '#555' });
}
function flat(el) {
  return { tag: el.tag, cls: el.className, attrs: el.attrs, html: el.html, text: el.text, kids: el.children.map(flat), job: el.job };
}

var out = {};
var w = widget(false);

var roll = { color: 'grey', roll_width_in: 48, sheet_length: 40, grungy_margin: 0.5, linear_feet: 3.33, pieces: [{ x: 0, y: 0, w: 20, h: 15, label: '20×15', kind: 'custom' }] };
var sheet = { color: 'grey', roll_width_in: 24, sheet_length: 32, grungy_margin: 0, stock_sheet: { w: 24, l: 32 }, linear_feet: 2.67, pieces: roll.pieces };
out.labels = [w.pageStockLabel(roll), w.pageStockLabel(sheet)];
out.svg = { roll: w.renderNestingSVG(roll, false, {}), sheet: w.renderNestingSVG(sheet, false, {}) };
var roll1 = Object.assign({}, roll, { grungy_margin: 1 }), roll0 = Object.assign({}, roll, { grungy_margin: 0 });
out.margins = { one: w.renderNestingSVG(roll1, false, {}), zero: w.renderNestingSVG(roll0, false, {}),
  quarter: w.marginLabel(0.25), unset: w.pageMargin({ roll_width_in: 48 }) };
out.refs = [w.shopJobRef({ kind: 'internal', ref_code: 'INT-GEN-26-001' }), w.shopJobRef({ kind: 'internal' }), w.shopJobRef({ kind: 'remake', remake_id: 7 }), w.shopJobRef(null)];
out.shop = [w.isShopCard({ kind: 'internal' }), w.isShopCard({ kind: 'remake' }), w.isShopCard({ lead_id: 5 }), w.isShopCard(null)];
out.hours = [w.ioHoursText(1.5), w.ioHoursText('2.256'), w.ioHoursText(0), w.ioHoursText('soon')];
out.attr = w.ioAttr('a "b" <c> & \'d\'');
out.line = [
  w.ioCardLine({ purpose_label: '<b>Fix</b>', for_whom: 'a & b', sheet: { w: 24, l: 32 }, pieces: 1 }),
  w.ioCardLine({ purpose_label: 'Shop fixtures', force_roll: 60, pieces: 6 }),
  w.ioCardLine({ pieces: 0 })
];

var order = { kind: 'internal', job_key: 'remake:4', title: 'Jig <covers>', ref_code: 'INT-GEN-26-004', purpose_label: 'Internal use', for_whom: 'the bench crew', pieces: 6, status: 'open', assignee_id: 0, assignee_name: '' };
function with_(over) { var o = {}; Object.keys(order).forEach(function (k) { o[k] = order[k]; }); Object.keys(over).forEach(function (k) { o[k] = over[k]; }); return o; }
out.card = {
  plain: flat(w.buildInternalCard(order)),
  mine: flat(w.buildInternalCard(with_({ assignee_id: 2, assignee_name: 'Jordan' }))),
  cut: flat(w.buildInternalCard(with_({ status: 'cut', pieces: 1 })))
};
var m = widget(true);
out.managed = {
  free: flat(m.buildInternalCard(order)),
  given: flat(m.buildInternalCard(with_({ assignee_id: 2, assignee_name: 'Jordan' })))
};

// The list: the shop's own pinned jobs all show, above the fold; the fold is for the rest.
function list(jobs) {
  page = { 'zprep-w-atc-list': new El('div'), 'zprep-w-atc-empty': new El('div'), 'zprep-w-atc-more': new El('button') };
  w.renderApprovedToCut(jobs, '');
  var more = page['zprep-w-atc-more'];
  var first = { shown: page['zprep-w-atc-list'].children.map(function (c) { return c.job; }), more: more.style.display === 'block' ? more.text : '', empty: page['zprep-w-atc-empty'].text };
  if (more.onclick) { more.onclick(); }
  first.all = page['zprep-w-atc-list'].children.map(function (c) { return c.job; });
  first.moreAfter = more.style.display;
  return first;
}
var customers = [1, 2, 3, 4, 5].map(function (n) { return { lead_id: n }; });
out.list = {
  mixed: list([{ kind: 'internal', job_key: 'remake:9', pinned: true }, { kind: 'internal', job_key: 'remake:8', pinned: true }].concat(customers, [{ kind: 'remake', job_key: 'remake:2' }])),
  manyPinned: list([1, 2, 3, 4, 5, 6].map(function (n) { return { kind: 'internal', job_key: 'remake:' + n, pinned: true }; })),
  none: list([])
};
out.visible = Number(visible[1]);

process.stdout.write(JSON.stringify(out));
