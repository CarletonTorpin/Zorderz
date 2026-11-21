/*
 * The draft helpers' cases, run by AssistantStyleMemoryTest.php in Node: node -e "<this file>" <path to chat-drafts.js>.
 * Prints one JSON object; the PHP test asserts on it. Neutral data only.
 */
var D = require(process.argv[1]);
var id = '0123456789abcdef01234567';
var out = {};

out.exports = Object.keys(D).sort();

out.askId = [
  '?zana_ask=' + id,
  '?a=1&zana_ask=' + id + '&b=2',
  '?zana_ask=' + id + '#chat',
  '?zana_ask=' + id.toUpperCase(),          // not as the server writes one
  '?zana_ask=' + id + 'ff',                 // too long
  '?zana_ask=' + id.slice(1),               // too short
  '?xzana_ask=' + id,                       // another parameter's name
  '?zana_ask=' + encodeURIComponent('<script>'),
  '',
  null
].map(function (s) { return D.askId(s); });
out.askIdOther = [D.askId('?other_ask=' + id, 'other_ask'), D.askId('?zana_ask=' + id, 'other_ask'), D.askId('?zana_ask=' + id, '')];

out.withoutAsk = [
  '?zana_ask=' + id,
  '?a=1&zana_ask=' + id + '&b=2',
  '?zana_ask=not-an-id&a=1',
  '?a=1',
  '',
  '?'
].map(function (s) { return D.withoutAsk(s); });

out.kindOf = [{ k: 'email' }, { k: 'dm' }, { k: 'post' }, { k: 'confirm' }, { k: 'chips' }, { k: 'page' }, {}, null].map(D.kindOf);

var on = { style: true };
out.editable = [
  D.editable({ k: 'email', body: 'Hello Riley,' }, on),
  D.editable({ k: 'dm', body: 'See you at eight.' }, on),
  D.editable({ k: 'post', body: 'The depot opens late on Friday.' }, on),
  D.editable({ k: 'email', body: 'Hello Riley,' }, { style: false }),   // not an administrator the rules are on for
  D.editable({ k: 'email', body: 'Hello Riley,' }, {}),
  D.editable({ k: 'email', body: 'Hello Riley,' }, null),
  D.editable({ k: 'email', body: '   ' }, on),                          // nothing to edit
  D.editable({ k: 'email' }, on),
  D.editable({ k: 'email', body: ['Hello'] }, on),
  D.editable({ k: 'confirm', body: 'Hello' }, on),                       // a held change is never edited here
  D.editable(null, on)
];

out.heldId = [
  D.heldId({ k: 'dm', id: 'ab'.repeat(16) }),
  D.heldId({ k: 'dm', id: 'AB'.repeat(16) }),
  D.heldId({ k: 'dm', id: 'ab'.repeat(15) }),
  D.heldId({ k: 'dm' }),
  D.heldId({ k: 'post', id: 'ab'.repeat(16) }),
  D.heldId(null)
];

out.sameWords = [
  D.sameWords('Hello  there\n', ' Hello there'),
  D.sameWords('Hello there', 'Hello there.'),
  D.sameWords('', null),
  D.sameWords('a\n\nb', 'a b')
];

var audiences = [{ id: 'all', label: 'All drafts' }, { id: 'customer', label: 'Customers and leads' }, { id: 'staff', label: 'Staff' }];
var rules = [
  { id: 'r1', audience: 'staff', rule: 'First names only.', source: 'added' },
  { id: 'r2', audience: 'all', rule: 'No exclamation marks.', source: 'learned' },
  { id: 'r3', audience: 'staff', rule: 'Say by when.', source: 'learned' },
  { id: 'r4', audience: 'unknown', rule: 'Dropped: no such audience.', source: 'learned' },
  null
];
out.groups = D.groupRules(rules, audiences).map(function (g) { return { id: g.id, label: g.label, ids: g.rules.map(function (r) { return r.id; }) }; });
out.noGroups = [D.groupRules([], audiences), D.groupRules(rules, []), D.groupRules(null, null)];

process.stdout.write(JSON.stringify(out));
