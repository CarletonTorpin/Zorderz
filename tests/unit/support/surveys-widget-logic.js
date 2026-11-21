/*
 * The Surveys widget's decisions and sentences, run on a list of calls (SurveysWidgetTest.php).
 *
 *   node surveys-widget-logic.js <path to widget-logic.js> <path to a JSON file: [ [ name, function, [ arguments ] ], ... ]>
 *
 * Prints one JSON object: name => what the function returned (a name that is not a function
 * answers its value).
 */
var fs = require('fs');
var L = require(process.argv[2]);
var calls = JSON.parse(fs.readFileSync(process.argv[3], 'utf8'));
var out = {};
calls.forEach(function (c) {
  var name = c[0], fn = c[1], args = c[2] || [];
  out[name] = typeof L[fn] === 'function' ? L[fn].apply(null, args) : L[fn];
});
process.stdout.write(JSON.stringify(out));
