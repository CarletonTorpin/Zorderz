/*
 * The Surveys widget on a page that is not there (SurveysWidgetTest.php): the shipped
 * widget-logic.js and widget.js, with stand-ins for the page's few parts, the question box and
 * the network. No kit of parts, so the widget's own request and busy code runs.
 *
 *   node surveys-widget-page.js <widget-logic.js> <widget.js> <a JSON file: { name => scenario }>
 *
 * A scenario:
 *   rows     what the site lists (zsv_recent)
 *   gr       what the site answers for the Google reviews panel (zsv_greviews_list)
 *   queue    { action: [ answers ] }: each request takes the next answer; the last one repeats.
 *            An answer is the site's JSON, or "502" (an answer that is not JSON), or "offline".
 *   answer_ok  what the person answers to a question (default true)
 *   steps    what the person does, in order:
 *            { press: "zsv-check-reviews" | "zsv-send" | "zsv-run-batch" | "zsv-sync" }
 *            { hint: <customer id> }                        a tap on that customer's hint line
 *            { panel: { cls | id, review, lead, name, on } } a tap on a button of the Google panel
 * Prints one JSON object: name => what was seen.
 */
var fs = require('fs');
var logicSrc = fs.readFileSync(process.argv[2], 'utf8');
var widgetSrc = fs.readFileSync(process.argv[3], 'utf8');
var scenarios = JSON.parse(fs.readFileSync(process.argv[4], 'utf8'));

function classes(initial) {
  var set = {};
  (initial || []).forEach(function (c) { set[c] = true; });
  return {
    contains: function (c) { return !!set[c]; },
    toggle: function (c, on) { if (on === undefined ? !set[c] : on) { set[c] = true; } else { delete set[c]; } },
    list: function () { return Object.keys(set).sort(); }
  };
}

function element(id) {
  var el = {
    id: id, textContent: '', innerHTML: '', hidden: false, disabled: false, attrs: {}, listeners: {},
    classList: classes(),
    addEventListener: function (type, fn) { el.listeners[type] = fn; },
    setAttribute: function (n, v) { el.attrs[n] = String(v); },
    removeAttribute: function (n) { delete el.attrs[n]; },
    getAttribute: function (n) { return Object.prototype.hasOwnProperty.call(el.attrs, n) ? el.attrs[n] : null; },
    closest: function () { return null; },
    querySelector: function () { return null; }
  };
  return el;
}

function play(s) {
  var IDS = ['zsv-widget', 'zsv-progress', 'zsv-list', 'zsv-run-batch', 'zsv-sync', 'zsv-check-reviews', 'zsv-review-status', 'zsv-send', 'zsv-greviews',
    'zsv-st-batches', 'zsv-st-leads', 'zsv-st-invited', 'zsv-st-reviews'];
  var els = {};
  IDS.forEach(function (id) { els[id] = element(id); });
  els['zsv-review-status'].hidden = true;
  els['zsv-greviews'].hidden = true;
  els['zsv-send'].hidden = true;

  var seen = { requests: [], questions: [], during_check: [], pending: 0 };
  var queue = JSON.parse(JSON.stringify(s.queue || {}));
  var win = {
    zsvWidget: { ajaxurl: 'https://app.example.com/wp-admin/admin-ajax.php', nonce: 'n-1', version: '0' },
    confirm: function (q) { seen.questions.push(q); return s.answer_ok !== false; }
  };
  var doc = { getElementById: function (id) { return els[id] || null; } };

  function answerFor(action) {
    var q = queue[action];
    if (q && q.length) { return q.length > 1 ? q.shift() : q[0]; }
    if (action === 'zsv_stats') { return { success: true, data: { batches: 1, leads: (s.rows || []).length, invited: 0, reviews: 0 } }; }
    if (action === 'zsv_recent') { return { success: true, data: s.rows || [] }; }
    if (action === 'zsv_greviews_list') { return { success: true, data: s.gr || { ready: false } }; }
    return { success: true, data: {} };
  }
  var fetchFn = function (url, opts) {
    var f = {};
    new URLSearchParams(opts.body).forEach(function (v, k) { f[k] = v; });
    seen.requests.push(f);
    if (f.action === 'zsv_check_reviews') {
      seen.during_check.push({
        run: els['zsv-run-batch'].disabled, sync: els['zsv-sync'].disabled, check: els['zsv-check-reviews'].disabled,
        busy: els['zsv-check-reviews'].getAttribute('aria-busy')
      });
    }
    var a = answerFor(f.action);
    seen.pending++;
    return new Promise(function (resolve, reject) {
      setTimeout(function () {
        seen.pending--;
        if (a === 'offline') { reject(new Error('offline')); return; }
        resolve({ json: function () { return a === '502' ? Promise.reject(new Error('not JSON')) : Promise.resolve(a); } });
      }, 0);
    });
  };

  var scope = {};
  new Function('self', logicSrc).call(scope, scope);   // the logic registers itself as ZSVLogic on the page
  win.ZSVLogic = scope.ZSVLogic;
  new Function('window', 'document', 'fetch', 'URLSearchParams', widgetSrc)(win, doc, fetchFn, URLSearchParams);

  function settle() {
    return new Promise(function (resolve) {
      var quiet = 0;
      (function tick() {
        quiet = seen.pending === 0 ? quiet + 1 : 0;
        if (quiet >= 4) { resolve(); } else { setTimeout(tick, 1); }
      })();
    });
  }

  var hints = {};
  function tapHint(id) {
    // The customer's row as the page drew it: is its panel drawn with the notes showing?
    var html = els['zsv-list'].innerHTML;
    var at = html.indexOf('<div class="zsv-row" data-id="' + id + '">');
    var end = html.indexOf('<div class="zsv-row" data-id="', at + 1);
    var rowHtml = at === -1 ? '' : html.slice(at, end === -1 ? html.length : end);
    if (!hints[id]) {
      var panel = { classList: classes(/zsv-row-panel zsv-notes-first/.test(rowHtml) ? ['zsv-notes-first'] : []) };
      var row = { getAttribute: function () { return String(id); }, querySelector: function (sel) { return sel === '.zsv-row-panel' ? panel : null; } };
      var hint = element('hint-' + id);
      hint.closest = function (sel) { return sel === '.zsv-row-hint' ? hint : (sel === '.zsv-row' ? row : null); };
      hints[id] = { hint: hint, panel: panel };
    }
    els['zsv-list'].listeners.click({ target: hints[id].hint });
    return { classes: hints[id].panel.classList.list(), label: hints[id].hint.textContent, expanded: hints[id].hint.getAttribute('aria-expanded') };
  }

  function tapPanel(p) {
    var b = element(p.id || '');
    b.classList = classes(p.cls ? [p.cls] : []);
    b.attrs = { 'data-review': p.review || '', 'data-lead': p.lead || '', 'data-name': p.name || '', 'data-on': p.on || '' };
    b.closest = function (sel) { return sel === 'button' ? b : null; };
    els['zsv-greviews'].listeners.click({ target: b });
  }

  var steps = (s.steps || []).slice();
  seen.steps = [];
  function snapshot(extra) {
    var o = {
      progress: els['zsv-progress'].textContent,
      review_status: els['zsv-review-status'].hidden ? null : els['zsv-review-status'].textContent,
      disabled: { run: els['zsv-run-batch'].disabled, sync: els['zsv-sync'].disabled, check: els['zsv-check-reviews'].disabled, send: els['zsv-send'].disabled },
      busy: els['zsv-check-reviews'].getAttribute('aria-busy'),
      send: els['zsv-send'].hidden ? null : els['zsv-send'].textContent,
      list: els['zsv-list'].innerHTML,
      greviews: els['zsv-greviews'].hidden ? null : els['zsv-greviews'].innerHTML
    };
    if (extra) { o.tap = extra; }
    return o;
  }
  function run() {
    return settle().then(function () {
      seen.loaded = snapshot();
      return (function next() {
        if (!steps.length) { return null; }
        var st = steps.shift(), tap = null;
        if (st.press) { els[st.press].listeners.click({ target: els[st.press] }); }
        if (st.hint) { tap = tapHint(st.hint); }
        if (st.panel) { tapPanel(st.panel); }
        return settle().then(function () { seen.steps.push(snapshot(tap)); return next(); });
      })();
    }).then(function () { delete seen.pending; return seen; });
  }
  return run();
}

var names = Object.keys(scenarios), result = {};
(function next(i) {
  if (i >= names.length) { process.stdout.write(JSON.stringify(result)); return; }
  play(scenarios[names[i]]).then(function (r) { result[names[i]] = r; next(i + 1); }, function (e) {
    result[names[i]] = { crashed: String(e && e.stack ? e.stack : e) }; next(i + 1);
  });
})(0);
