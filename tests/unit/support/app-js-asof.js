/*
 * A home tile's "as of", lifted out of the theme's app.js and run with three locales (FreshSiteQuietTest.php).
 *
 *   node -e "<this file>" <path to app.js>
 *
 * The function is cut out of app.js at the first closing brace where the cut is a complete function (the JavaScript
 * engine's own parser decides), so the test runs the shipped code, not a copy. Prints one JSON object.
 */
var fs = require('fs');
var src = fs.readFileSync(process.argv[1], 'utf8');

function lift(name) {
  var m = new RegExp('function ' + name + '\\s*\\(').exec(src);
  if (!m) { throw new Error('app.js has no function ' + name); }
  for (var j = src.indexOf('}', m.index); j !== -1; j = src.indexOf('}', j + 1)) {
    var cut = src.slice(m.index, j + 1);
    try { new Function(cut); return cut; } catch (e) { /* not complete yet */ }
  }
  throw new Error('function ' + name + ' never closes');
}

var run = new Function('zdzData', lift('zdzKpiAgeWords') + '\nreturn zdzKpiAgeWords;');
var d = new Date();
d.setHours(22, 5, 0, 0);
var t = { as_of: Math.floor(d.getTime() / 1000) };
process.stdout.write(JSON.stringify({
  gb: run({ locale: 'en-GB', l10n: { hour12: false } })(t),
  us: run({ locale: 'en-US', l10n: { hour12: true } })(t),
  odd: run({ locale: 'not a tag', l10n: {} })(t)
}));
