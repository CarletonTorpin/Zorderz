/*
 * The "Send again" button of the Surveys settings screen, run on a page that is not there
 * (SurveysWidgetTest.php): the shipped admin.js with a table, a button, a question and a network
 * that are stand-ins.
 *
 *   node surveys-send-again.js <path to admin.js> <path to a JSON file: { name => scenario }>
 *
 * A scenario: { answer_ok: true|false (what the person answers), reply: what the site answers,
 * or "fails" (no answer), customer: { id, name, at }, table: false (the screen has no list) }.
 * Prints one JSON object: name => { questions, requests, said, sends, disabled }.
 */
var fs = require('fs');
var src = fs.readFileSync(process.argv[2], 'utf8');
var scenarios = JSON.parse(fs.readFileSync(process.argv[3], 'utf8'));

var WORDS = {
  ask: '%1$s was ALREADY E-MAILED (%2$s). Send AGAIN?',
  sending: 'Sending',
  sent: 'Sent again (send #%3$s).',
  unrecorded: 'Sent again, time not saved.',
  in_progress: 'Another send is running.',
  lock: 'Could not make sure.',
  unreadable: 'Could not read.',
  crm_closed: 'Closed in the CRM.',
  held: 'Held back.',
  failed: 'The mail service did not take it.',
  refused: 'Not sent again.',
  error: 'That did not work.'
};

function play(s) {
  var seen = { questions: [], requests: [], said: '', sends: '1', disabled: false, bound: false };
  var out = { textContent: '' }, sendsCell = { textContent: '1' };
  var row = { querySelector: function (sel) { return sel === '.zsv-send-again-result' ? out : (sel === '.zsv-sends' ? sendsCell : null); } };
  var c = s.customer || { id: 7, name: 'Riley Example', at: '2026-03-02 10:00' };
  var btn = {
    disabled: false,
    getAttribute: function (n) { return { 'data-id': String(c.id), 'data-name': c.name, 'data-at': c.at }[n] || null; },
    closest: function (sel) { return sel === '.zsv-send-again' ? btn : (sel === 'tr' ? row : null); }
  };
  var click = null;
  var table = { addEventListener: function (type, fn) { if (type === 'click') { click = fn; seen.bound = true; } } };
  var win = {
    zsvAdmin: { ajaxurl: 'https://app.example.com/wp-admin/admin-ajax.php', nonce: 'n-1', words: WORDS },
    confirm: function (q) { seen.questions.push(q); return !!s.answer_ok; }
  };
  var doc = { getElementById: function (id) { return id === 'zsv-sent' && s.table !== false ? table : null; } };
  var fetchFn = function (url, opts) {
    var f = {};
    new URLSearchParams(opts.body).forEach(function (v, k) { f[k] = v; });
    seen.requests.push({ url: url, method: opts.method, fields: f });
    if (s.reply === 'fails') { return Promise.reject(new Error('offline')); }
    return Promise.resolve({ json: function () { return Promise.resolve(s.reply); } });
  };
  new Function('window', 'document', 'fetch', 'URLSearchParams', src)(win, doc, fetchFn, URLSearchParams);
  if (click) {
    click({ target: btn });
    if (s.twice) { click({ target: btn }); }
  }
  return new Promise(function (resolve) {
    setTimeout(function () {
      seen.said = out.textContent.trim();
      seen.sends = sendsCell.textContent;
      seen.disabled = btn.disabled;
      resolve(seen);
    }, 20);
  });
}

var names = Object.keys(scenarios), result = {};
(function next(i) {
  if (i >= names.length) { process.stdout.write(JSON.stringify(result)); return; }
  play(scenarios[names[i]]).then(function (r) { result[names[i]] = r; next(i + 1); });
})(0);
