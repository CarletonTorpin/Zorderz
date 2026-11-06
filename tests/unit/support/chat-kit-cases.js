/*
 * The chat helpers' cases, run by ChatSurfaceTest.php in Node: node -e "<this file>" <path to chat-kit.js>.
 * Prints one JSON object; the PHP test asserts on it. Neutral data only.
 */
var K = require(process.argv[1]);
function b64u(o) { return Buffer.from(JSON.stringify(o)).toString('base64').replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, ''); }
// A fixed sequence, so the weighted pick is the same on every run.
function lcg(seed) { var s = seed >>> 0; return function () { s = (Math.imul(s, 1664525) + 1013904223) >>> 0; return (s + 0.5) / 4294967296; }; }
var out = {};

out.safeHref = {};
['https://example.com/a', 'mailto:alex@example.com', 'tel:555-0100', '/vault/handbook', 'javascript:alert(1)', 'JaVaScRiPt:alert(1)',
  'data:text/html,x', 'http://example.com/', '//example.org/x', '/\\example.org', 'https://example.com/a b', 'https://example.com/\u0007']
  .forEach(function (h) { out.safeHref[h] = K.safeHref(h); });
out.safeSiteUrl = ['/x', 'https://example.com/y', 'https://example.org/y', '//example.com/y', 'javascript:x']
  .map(function (u) { return K.safeSiteUrl(u, 'https://example.com'); });
out.isVault = ['/vault/handbook', 'https://example.com/vault/a-1', 'https://example.org/vault/a', '/vaults/x']
  .map(function (u) { return K.isVaultHref(u, 'https://example.com'); });

out.blocks = K.parseBlocks([
  '**Bold** first line', 'second line', '',
  '- one', '- two', '',
  '3. three', '4. four', '',
  '| A | B | C |', '|---|--:|:-:|', '| 1 | 2 | 3 |', '| 4 | 5 | 6 |', '',
  '```', '[ZDZ_CONFIRM:x]', '**not bold**', '```', '',
  '> quoted', '',
  '### Heading', '',
  '---'
].join('\n'));
out.inline = {
  math: K.parseInline('2*3*4'),
  snake: K.parseInline('snake_case_word'),
  links: K.parseInline('See [the report](https://example.com/report) now'),
  badlink: K.parseInline('[click](javascript:void0)'),
  auto: K.parseInline('Go to https://example.com/a.'),
  chg: K.parseInline('Sales ▲ +12% and costs ▼ $300')
};
out.plain = K.plainText('**Hello** [Alex](https://example.com/x) [ZANA_DM_DRAFT:' + 'c'.repeat(32) + '|@alex|Alex]Hi[/ZANA_DM_DRAFT]');
out.docColumns = K.tableDocKinds(['Invoice', 'Customer', 'Estimate #', 'Invoices', 'invoice no.', 'Invoiced']);

var mini = { p: ['Jan', 'Feb', 'Mar'], s: [{ g: 'All', v: [1, 2, 3] }], u: '$' };
var text = [
  'Here it is.',
  '<!--ZANA_OPEN_PAGE:{"url":"/projects/","title":"Projects","id":"projects"}-->',
  '<!--ZANA_OPEN_PAGE:{"url":"https://evil.example/x","title":"Other"}-->',
  '<!-- hidden note -->',
  '[ZANA_DM_DRAFT:' + 'c'.repeat(32) + '|@alex|Alex Example]Hi Alex, the handbook is at /vault/handbook[/ZANA_DM_DRAFT]',
  '[ZANA_DM_DRAFT:@jordan]Not held[/ZANA_DM_DRAFT]',
  '[ZANA_POST_DRAFT:#General]Team lunch at noon.[/ZANA_POST_DRAFT]',
  '[ZDZ_EMAIL_DRAFT:Jordan]to: jordan@example.com', 'subject: Your visit', '---', 'Hello Jordan,', 'See you Tuesday.[/ZDZ_EMAIL_DRAFT]',
  '<div class="zana-charts-chips"><button type="button" class="zana-charts-open" data-zch="eyJhIjoxfQ" data-mini="' + b64u(mini) +
    '" title="Sales">&#x1F4CA; Chart 1: Sales &amp; visits</button> <button type="button" class="zana-charts-open zana-plan-route" ' +
    'data-say="Plan a canvassing route on Example St in 00000 for me">&#x1F6B6; Plan route 1: Example St</button></div>',
  '<div class="zana-app-chips"><button type="button" class="zana-app-open zana-rp-open" data-rp="' + b64u({ mode: 'plan', area: 'North', count: 9, go: 'yes' }) +
    '">Open in Route Planner</button><button type="button" class="zana-app-open" data-app="jobs" data-open="' + b64u({ project: 12 }) + '">Open the project</button></div>',
  'See <a href="#" data-zana-doc-kind="invoice">#1001</a> and <b>kept as text</b>.',
  '[ZDZ_CONFIRM:' + 'a'.repeat(32) + ']',
  '```', '[ZDZ_CONFIRM:' + 'b'.repeat(32) + ']', '```'
].join('\n');
out.cards = K.extractCards(text, 'https://example.com');

var bad = [
  '<button type="button" class="zana-charts-open" data-zch="not base64!">x</button>',
  '<button type="button" class="zana-plan-route zana-charts-open" data-say="Plan something">x</button>',
  '<button type="button" class="other" data-zch="eyJhIjoxfQ">x</button>',
  '<button type="button" class="zana-app-open" data-app="Bad App" data-open="eyJhIjoxfQ">x</button>'
].join('');
var many = '';
for (var i = 0; i < 10; i++) { many += '<button type="button" class="zana-charts-open" data-zch="eyJhIjoxfQ">Chart ' + i + '</button>'; }
out.chips = { bad: K.parseChips(bad), many: K.parseChips(many) };
out.chartsPrompt = K.chartsPrompt('eyJhIjoxfQ');
out.openOptions = K.cleanOpenOptions({ kept: 'x', n: 2, on: true, Bad: 1, 'with space': 1, obj: {}, html: '<b>', long: 'x'.repeat(201), nan: NaN });

var thirty = [];
for (var j = 0; j < 30; j++) { thirty.push('D' + j); }
out.mini = {
  bars: K.miniModel({ p: ['Jan', 'Feb', 'Mar'], s: [{ g: 'A', v: [1000, 2000, 1000] }, { g: 'B', v: [500, 1000, 0] }], u: '$' }),
  line: K.miniModel({ p: thirty, s: [{ g: 'A', v: thirty.map(function (_, k) { return k + 1; }) }] }),
  h: K.miniModel({ h: 1, p: ['North', 'South'], s: [{ g: 'x', v: [5, 10] }] }),
  one: K.miniModel({ p: ['Jan'], s: [{ g: 'A', v: [1] }] }),
  zero: K.miniModel({ p: ['a', 'b'], s: [{ g: 'A', v: [0, 0] }] }),
  fmt: [K.fmtMini(999, ''), K.fmtMini(1500, ''), K.fmtMini(12000, ''), K.fmtMini(2500000, '$')]
};

var items = ['a', 'b', 'c', 'd', 'zero'];
var id = function (x) { return x; };
var rnd = lcg(7);
var heavy = 0, light = 0;
for (var n = 0; n < 4000; n++) {
  var first = K.weightedPick(['h', 'l'], 1, id, { l: 0.25 }, rnd)[0];
  if (first === 'h') { heavy++; } else { light++; }
}
out.weighted = {
  three: K.weightedPick(items, 3, id, { zero: 0 }, lcg(3)),
  all: K.weightedPick(items, 5, id, { zero: 0 }, lcg(5)),
  heavy: heavy,
  light: light
};

var at = 1778000000000;
out.time = {
  expired: [K.confirmExpired(at, 600, at + 600000), K.confirmExpired(at, 600, at + 660000), K.confirmExpired(at, 600, at + 660001),
    K.confirmExpired(0, 600, at), K.confirmExpired(at, 0, at + 9e9)],
  parsed: [K.parseTime('2026-05-05T08:53:20-08:00'), K.parseTime('2026-05-06 00:53:20'), K.parseTime('not a time')],
  poll: [K.pollLimit(180000, 1, 1), K.pollLimit(180000, 2, 1), K.pollLimit(180000, 2, 2)],
  dock: [K.dockSrc('https://example.com', 42), K.dockSrc('https://example.com/', 0)]
};
out.mail = {
  two: K.mailtoFor('Alex <alex@example.com>, jordan@example.com, ALEX@example.com', 'Hi', 'Hello **Alex** (https://example.com)'),
  none: K.mailtoFor('[no email on file]', 'Hi', '')
};
out.docRef = [K.docRef('#1001', 'invoice'), K.docRef('estimate 2002', 'estimate'), K.docRef('#abc', 'invoice'), K.docRef('#1001', 'receipt')];

process.stdout.write(JSON.stringify(out));
