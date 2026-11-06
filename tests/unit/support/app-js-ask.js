/*
 * The ask bar's page interpreters, lifted out of the theme's app.js and run on a corpus (AskBarPageParityTest.php).
 *
 *   node -e "<this file>" <path to app.js> <path to a JSON file: {phrases, apps, corpus}>
 *
 * Each function is cut out of app.js at the first closing brace where the cut is a complete function (the JavaScript
 * engine's own parser decides), so the test runs the shipped code, not a copy. Prints one JSON object.
 */
var fs = require('fs');
var src = fs.readFileSync(process.argv[1], 'utf8');
var input = JSON.parse(fs.readFileSync(process.argv[2], 'utf8'));

function lift(name) {
  var m = new RegExp('function ' + name + '\\s*\\(').exec(src);
  if (!m) { throw new Error('app.js has no function ' + name); }
  for (var j = src.indexOf('}', m.index); j !== -1; j = src.indexOf('}', j + 1)) {
    var cut = src.slice(m.index, j + 1);
    try { new Function(cut); return cut; } catch (e) { /* not complete yet */ }
  }
  throw new Error('function ' + name + ' never closes');
}

var names = ['zdzAskStock', 'zdzDetectCommissionInline', 'zdzDetectCommissionInlineLocal', 'zdzAskActionNormalize', 'zdzAskRe',
  'zdzAskActionName', 'zdzAskActionNumber', 'zdzAskAction', 'zdzDetectAppLaunch', 'zdzDetectAppLaunchLocal', 'zdzHhmm', 'zdzWorkMode'];
var body = 'var _zdzAskReCache = {};\n' + names.map(lift).join('\n') + '\nreturn {' + names.map(function (n) { return n + ':' + n; }).join(',') + '};';
var visible = input.apps.map(function (id) { return { id: id, nm: id }; });
var zdzData = { askPhrases: input.phrases, apps: visible };
var api = new Function('zdzData', 'getVisibleApps', 'Haptics', 'openApp', body)(zdzData, function () { return visible; }, { tap: function () {} }, function () {});

var out = { actions: {}, commission: {}, stock: {}, launch: {}, mode: [] };
input.corpus.actions.forEach(function (q) {
  var r = api.zdzAskAction(q, null);
  out.actions[q] = r ? { id: r.id, app: r.app, name: r.name, number: r.number } : null;
});
input.corpus.commission.forEach(function (q) {
  var r = api.zdzDetectCommissionInline(q, q.toLowerCase());
  out.commission[q] = r ? { subject: r.query === 'my' ? '' : r.query, period: r.options.orchestrator_hint.period, focus: r.focus } : null;
});
input.corpus.stock.forEach(function (q) { out.stock[q] = api.zdzAskStock(q.toLowerCase().trim()); });
input.corpus.launch.forEach(function (q) {
  var r = api.zdzDetectAppLaunch(q, q.toLowerCase());
  out.launch[q] = r ? { id: r.appId, name: r.name } : null;
});
input.corpus.mode.forEach(function (c) { out.mode.push(api.zdzWorkMode(c.hours, { dow: c.dow, min: c.min })); });
process.stdout.write(JSON.stringify(out));
