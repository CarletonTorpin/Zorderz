/* Doorhangers — the app in its card (vanilla JS + Leaflet), on the theme's kit of parts (ZDZ.ui) where it is present.
   The theme builds every card AFTER this script runs (app.js `zone.innerHTML = …`, then `zdz_widgets_rendered`), so the app
   starts when its root exists — at once if it already does, else on that event, with a DOMContentLoaded fallback for an older theme. */
(function () {
	'use strict';
	function zdhStart() {
	var C = window.ZDH_CONFIG || {};
	var root = document.getElementById('zdh-app');
	if (!root || !window.L || root.getAttribute('data-zdh-init')) { return; }
	root.setAttribute('data-zdh-init', '1');
	/* The theme's kit of parts: toasts, sheets, people chips. Guarded, so an older theme still runs the app. */
	function kit() { return (window.ZDZ && window.ZDZ.ui) ? window.ZDZ.ui : null; }
	var PEOPLE = Array.isArray(C.people) ? C.people : [];
	function peopleNames() { return PEOPLE.map(function (p) { return p.name; }); }
	function personChip(name) {
		if (!name) { return ''; }
		var k = kit(), p = null;
		for (var i = 0; i < PEOPLE.length; i++) { if (PEOPLE[i].name.toLowerCase() === String(name).toLowerCase()) { p = PEOPLE[i]; break; } }
		if (p && k && k.personChip) { try { var c = k.personChip(p.id, { size: 'sm', title: p.name }); if (c && c.outerHTML) { return c.outerHTML + ' '; } } catch (e) {} }
		return '';
	}
	/* Scroll the card's own scroll (the page's body in the focus view), not the window. */
	function scrollTo(elm, block) { try { if (elm) { elm.scrollIntoView({ behavior: 'smooth', block: block || 'start' }); } } catch (e) {} }
	function scrollTop() { var c = root.closest('.dash-widget-body') || root; try { c.scrollTo({ top: 0, behavior: 'smooth' }); } catch (e) { c.scrollTop = 0; } }
	L.Icon.Default.imagePath = C.leafletImages || '';

	/* ------------------------------------------------------------ utils */
	function esc(s) {
		return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
		});
	}
	/* Short wording on phones, fuller wording on a desk (CSS picks one). */
	function tx(mobile, desk) { return '<span class="zdh-mob">' + mobile + '</span><span class="zdh-desk">' + desk + '</span>'; }
	function el(html) {
		var t = document.createElement('template');
		t.innerHTML = html.trim();
		return t.content.firstElementChild;
	}
	function todayISO() {
		// the phone's own date (the page can come from a cached copy a few hours old, so the server's date may be yesterday's);
		// the server's date only wins when the phone clock is clearly wrong
		var d = new Date();
		var local = d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
		if (C.today && Math.abs(new Date(local + 'T12:00:00') - new Date(C.today + 'T12:00:00')) > 2 * 86400000) { return C.today; }
		return local;
	}
	function nowHM() {
		var d = new Date();
		return String(d.getHours()).padStart(2, '0') + ':' + String(d.getMinutes()).padStart(2, '0');
	}
	function fmtDate(iso, short) {
		if (!iso) { return '—'; }
		var p = iso.split('-');
		var d = new Date(+p[0], +p[1] - 1, +p[2]);
		return d.toLocaleDateString('en-US', short ? { month: 'short', day: 'numeric', year: 'numeric' } : { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' });
	}
	function fmtTime(hm) {
		if (!hm) { return ''; }
		var p = hm.split(':');
		var h = +p[0], m = p[1];
		return ((h % 12) || 12) + ':' + m + ' ' + (h >= 12 ? 'PM' : 'AM');
	}
	function daysUntil(iso) {
		if (!iso) { return null; }
		var a = new Date(todayISO() + 'T00:00:00'), b = new Date(iso + 'T00:00:00');
		return Math.round((b - a) / 86400000);
	}
	function store(k, v) { try { localStorage.setItem('zdh_' + k, v); } catch (e) {} }
	function recall(k) { try { return localStorage.getItem('zdh_' + k) || ''; } catch (e) { return ''; } }

	var SUFFIX = { avenue: 'ave', av: 'ave', boulevard: 'blvd', circle: 'cir', court: 'ct', cove: 'cv', drive: 'dr', expressway: 'expy', freeway: 'fwy', glen: 'gln', heights: 'hts', highway: 'hwy', lane: 'ln', loop: 'loop', parkway: 'pkwy', place: 'pl', plaza: 'plz', point: 'pt', road: 'rd', ridge: 'rdg', row: 'row', run: 'run', square: 'sq', street: 'st', terrace: 'ter', terr: 'ter', trail: 'trl', way: 'way', walk: 'walk', view: 'vw', vista: 'vis', crossing: 'xing', alley: 'aly', path: 'path', pass: 'pass', mount: 'mt', lake: 'lk', ave: 'ave', blvd: 'blvd', cir: 'cir', ct: 'ct', cv: 'cv', dr: 'dr', expy: 'expy', fwy: 'fwy', gln: 'gln', hts: 'hts', hwy: 'hwy', ln: 'ln', pkwy: 'pkwy', pky: 'pkwy', pl: 'pl', plz: 'plz', pt: 'pt', rd: 'rd', rdg: 'rdg', sq: 'sq', st: 'st', ter: 'ter', trl: 'trl', vw: 'vw', vis: 'vis', xing: 'xing', aly: 'aly', mt: 'mt', lk: 'lk' };
	var DIRS = { north: 'n', south: 's', east: 'e', west: 'w', n: 'n', s: 's', e: 'e', w: 'w', northeast: 'ne', northwest: 'nw', southeast: 'se', southwest: 'sw', ne: 'ne', nw: 'nw', se: 'se', sw: 'sw' };
	/* Mirrors ZDH_DB::street_key() so the browser groups streets exactly like the server does. */
	function streetKey(s) {
		s = String(s || '').toLowerCase().replace(/[.,#]/g, ' ').replace(/[^a-z0-9' \-]/g, ' ').replace(/\s+/g, ' ').trim();
		if (!s) { return ''; }
		var p = s.split(' '), n = p.length, si;
		if (n > 1 && DIRS[p[0]]) { p[0] = DIRS[p[0]]; }
		if (n > 2 && DIRS[p[n - 1]]) { p[n - 1] = DIRS[p[n - 1]]; si = n - 2; } else { si = n - 1; }
		if (si > 0 && SUFFIX[p[si]]) { p[si] = SUFFIX[p[si]]; }
		var f = (n > 1 && DIRS[s.split(' ')[0]]) ? 1 : 0; // Mount → Mt, Saint → St (same as the server)
		if (n - f > 2) { if (p[f] === 'mount') { p[f] = 'mt'; } if (p[f] === 'saint') { p[f] = 'st'; } }
		return p.join(' ');
	}
	/* a street's name without its type and direction ("Oak Hollow Dr" -> "oak hollow"), for a street the reader named
	   without a suffix. A name read WITH its suffix is matched exactly, so "Oak Ct" never takes in Oak St. */
	var SFXV = {}; Object.keys(SUFFIX).forEach(function (k) { SFXV[SUFFIX[k]] = 1; });
	function streetCore(s) {
		var p = streetKey(s).split(' ').filter(Boolean);
		if (p.length > 1 && DIRS[p[p.length - 1]]) { p.pop(); }
		if (p.length > 1 && SFXV[p[p.length - 1]]) { p.pop(); }
		if (p.length > 1 && DIRS[p[0]]) { p.shift(); }
		return p.join(' ');
	}
	/* the streets the reader named, for the paper rule: {exact: {key: 1}, core: {core: 1}} */
	function namedStreets(list) {
		var out = { exact: {}, core: {} };
		(list || []).forEach(function (cs) {
			if (!cs || !cs.name) { return; }
			var k = streetKey(cs.name), w = k.split(' ');
			if (w.length > 1 && (SFXV[w[w.length - 1]] || DIRS[w[w.length - 1]])) { out.exact[k] = 1; } else { out.core[streetCore(k)] = 1; }
		});
		return out;
	}
	function paperLib() { return window.ZDH_PAPER || null; } // assets/paper.js (old routes from paper); read at call time
	/* a notes box that stays small outdoors — repeated lines merge ("ZIP 00002 had no match for 5 streets — used
	   00001") and everything past the first line folds behind "+N more". */
	function noteBox(list, id) {
		var P = paperLib(), lines = P ? P.mergeNotes(list) : (list || []).filter(function (n, i, a) { return n && a.indexOf(n) === i; }).map(String);
		if (!lines.length) { return ''; }
		var idA = id ? ' id="' + id + '"' : '';
		if (lines.length === 1) { return '<div class="zdh-note"' + idA + '>' + esc(lines[0]) + '</div>'; }
		return '<details class="zdh-note zdh-notebox"' + idA + '><summary>' + esc(lines[0]) + ' <span class="zdh-note-more">+' + (lines.length - 1) + ' more</span></summary>' + lines.slice(1).map(function (l) { return '<div>' + esc(l) + '</div>'; }).join('') + '</details>';
	}
	function distM(a, b) { // [lat,lng] pairs, equirectangular approximation (fine at street scale)
		var x = (b[1] - a[1]) * Math.cos((a[0] + b[0]) / 2 * Math.PI / 180) * 111320;
		var y = (b[0] - a[0]) * 110540;
		return Math.sqrt(x * x + y * y);
	}
	function distToSegM(p, a, b) {
		var cos = Math.cos(p[0] * Math.PI / 180);
		var px = (p[1] - a[1]) * cos * 111320, py = (p[0] - a[0]) * 110540;
		var bx = (b[1] - a[1]) * cos * 111320, by = (b[0] - a[0]) * 110540;
		var l2 = bx * bx + by * by;
		var t = l2 ? Math.max(0, Math.min(1, (px * bx + py * by) / l2)) : 0;
		var dx = px - t * bx, dy = py - t * by;
		return Math.sqrt(dx * dx + dy * dy);
	}
	function distToPathM(p, path) {
		var best = Infinity;
		for (var i = 0; i < path.length - 1; i++) { best = Math.min(best, distToSegM(p, path[i], path[i + 1])); }
		if (path.length === 1) { best = distM(p, path[0]); }
		return best;
	}
	function pathLengthM(path) {
		var l = 0;
		for (var i = 1; i < path.length; i++) { l += distM(path[i - 1], path[i]); }
		return l;
	}
	function pointInPoly(p, poly) {
		var inside = false;
		for (var i = 0, j = poly.length - 1; i < poly.length; j = i++) {
			var yi = poly[i][0], xi = poly[i][1], yj = poly[j][0], xj = poly[j][1];
			if (((yi > p[0]) !== (yj > p[0])) && (p[1] < (xj - xi) * (p[0] - yi) / (yj - yi) + xi)) { inside = !inside; }
		}
		return inside;
	}
	var PALETTE = ['#e67e22', '#2ecc71', '#9b59b6', '#3498db', '#e74c3c', '#1abc9c', '#f1c40f', '#e84393', '#00cec9', '#6c5ce7'];
	var COLOR_DONE = '#0072B2', COLOR_PLANNED = '#009E73', COLOR_ACTIVE = '#CC79A7', COLOR_TODO = '#E69F00', COLOR_STROKE = '#F2812A';
	// a planned route that is somebody's to do (assigned, from the chat or the app) is ORANGE: "to be done". In progress
	// is the palette's reddish purple so the two never read alike.
	function isTodo(d) { return d && d.status === 'planned' && +d.assigned_uid > 0; }
	function hoursText(h) { if (!h) { return ''; } return String(h).split(',').map(function (w) { var m = /^(\d{1,2})-(\d{1,2})$/.exec(w); if (!m) { return ''; } var f = function (x) { x = +x; var p = x >= 12 ? 'pm' : 'am'; x = x % 12; if (!x) { x = 12; } return [x, p]; }; var a = f(m[1]), b = f(m[2]); return a[1] === b[1] ? a[0] + '\u2013' + b[0] + ' ' + b[1] : a[0] + ' ' + a[1] + '\u2013' + b[0] + ' ' + b[1]; }).filter(Boolean).join(' \u00b7 '); }
	function todoLine(d) { if (!isTodo(d) && !(d.status === 'in_progress' && +d.assigned_uid > 0)) { return ''; } var who = PEOPLE.filter(function (p) { return +p.id === +d.assigned_uid; })[0]; var bits = []; if (d.due_by) { var n = daysUntil(d.due_by); bits.push('by ' + fmtDate(d.due_by, true) + (n < 0 ? ' (' + Math.abs(n) + ' days late)' : (n === 0 ? ' (today)' : ' (in ' + n + ' days)'))); } if (d.hours) { bits.push(hoursText(d.hours)); } return '<strong>To be done by:</strong> ' + personChip(who ? who.name : d.delivered_by) + esc(who ? who.name : (d.delivered_by || '\u2014')) + (bits.length ? ' \u00b7 ' + esc(bits.join(' \u00b7 ')) : '') + '<br>'; }

	/* -------------------------------------------------------------- api */
	/* Works with pretty permalinks (…/wp-json/zorderz/v1/doorhangers/) and plain ones (…?rest_route=/zorderz/v1/doorhangers/). */
	function url(path) {
		var base = C.restUrl || '';
		if (base.indexOf('?') >= 0) { path = path.replace('?', '&'); }
		return base + path;
	}
	/* ---- event log: a small ring buffer in localStorage that the Diagnostics screen can share.
	   One entry = [time, type, detail]. GPS fixes are summarised, never logged one by one. */
	var LOG_KEY = 'zdh_log', LOG_MAX = 400, logBuf = null;
	function logEv(type, detail) {
		try {
			if (!logBuf) { var j = localStorage.getItem(LOG_KEY); logBuf = j ? JSON.parse(j) : []; if (!Array.isArray(logBuf)) { logBuf = []; } }
			var d = detail === undefined ? null : detail;
			if (typeof d === 'string' && d.length > 180) { d = d.slice(0, 180); }
			logBuf.push([Date.now(), type, d]);
			if (logBuf.length > LOG_MAX) { logBuf = logBuf.slice(-LOG_MAX); }
			localStorage.setItem(LOG_KEY, JSON.stringify(logBuf));
		} catch (e) {}
	}
	function logEntries() { try { var j = localStorage.getItem(LOG_KEY); var a = j ? JSON.parse(j) : []; return Array.isArray(a) ? a : []; } catch (e) { return []; } }
	window.addEventListener('error', function (e) { logEv('jserror', (e.message || 'error') + ' @' + ((e.filename || '').split('/').pop()) + ':' + (e.lineno || 0)); });
	window.addEventListener('unhandledrejection', function (e) { var r = e.reason; logEv('jsreject', r && r.message ? r.message : String(r)); });
	window.addEventListener('online', function () { logEv('online'); flushOutbox(); });
	window.addEventListener('offline', function () { logEv('offline'); });
	/* A failed fetch (no signal, DNS, cut off) — as opposed to a server answer — so callers can queue instead of losing work. */
	function isNetError(e) { return !!(e && e.network); }
	function api(path, opts) {
		opts = opts || {};
		var init = { method: opts.method || 'GET', credentials: 'same-origin', headers: { 'X-WP-Nonce': C.nonce } };
		if (opts.form) { init.body = opts.form; }
		else if (opts.body !== undefined) { init.headers['Content-Type'] = 'application/json'; init.body = JSON.stringify(opts.body); }
		var short = path.split('?')[0];
		return fetch(url(path), init).catch(function (e) {
			logEv('net', (init.method === 'GET' ? '' : init.method + ' ') + short + ': ' + (e && e.message ? e.message : 'failed'));
			var err = new Error('No connection — ' + (e && e.message ? e.message : 'the request did not go through')); err.network = true; throw err;
		}).then(function (r) {
			return r.text().then(function (t) {
				var j = null;
				try { j = JSON.parse(t); } catch (e) {}
				if (!r.ok) {
					logEv('api', (init.method === 'GET' ? '' : init.method + ' ') + short + ' → ' + r.status + (j && j.code ? ' ' + j.code : ''));
					if (j && (j.code === 'rest_cookie_invalid_nonce' || r.status === 401)) {
						throw new Error('You are signed out. Reload the app and sign in again — your route and progress are kept on this phone.');
					}
					if (r.status === 403) {
						throw new Error((j && j.message) ? j.message : 'You are not allowed to do that.');
					}
					var msg = (j && j.message) ? j.message : ('HTTP ' + r.status + (t ? ': ' + t.slice(0, 200) : ''));
					var err = new Error(msg); err.status = r.status; err.code = j && j.code;
					throw err;
				}
				return j;
			});
		});
	}
	/* Base map: OpenFreeMap vector tiles (OpenStreetMap data; free, no API key, no limits) drawn by MapLibre GL
	   inside Leaflet, so the app sets the map's own type: street names bold and near-black with a white halo, a quarter
	   larger than the style's default — or 70 % larger in "big text" mode (the Aa button, on by default on phones,
	   remembered per device) — crisp at any size, road outlines and buildings darker. Without WebGL 2 (or if MapLibre
	   fails to load or the style cannot be fetched) the map falls back to OpenStreetMap's raster tiles, where big text
	   shows each tile at twice its size. Both are cached by the service worker. */
	var STYLE_URL = 'https://tiles.openfreemap.org/styles/bright';
	function bigMap() { var v = recall('bigmap'); if (v === '1') { return true; } if (v === '0') { return false; } return !!(window.matchMedia && window.matchMedia('(max-width: 640px)').matches); }
	function textFactor() { return bigMap() ? 1.7 : 1.25; }
	var glOK = null; // null = not tested yet
	function glSupported() {
		if (glOK !== null) { return glOK; }
		glOK = false;
		try {
			if (window.maplibregl && L.MaplibreGL && !/[?&]zdh_raster=1/.test(location.search)) {
				var c = document.createElement('canvas'), gl = c.getContext('webgl2');
				glOK = !!gl;
				if (gl) { var lose = gl.getExtension('WEBGL_lose_context'); if (lose) { lose.loseContext(); } }
			}
		} catch (e) { glOK = false; }
		if (glOK) { try { if (typeof maplibregl.prewarm === 'function') { maplibregl.prewarm(); } } catch (e) {} }
		return glOK;
	}
	/* Scale a text-size value (number, legacy stops function or expression) by f — zoom stops stay, outputs grow. */
	function scaleSize(v, f) {
		var out, i;
		if (typeof v === 'number') { return v * f; }
		if (Array.isArray(v)) {
			var op = v[0];
			if (op === 'interpolate' || op === 'interpolate-hcl' || op === 'interpolate-lab') { out = v.slice(0, 3); for (i = 3; i < v.length; i += 2) { out.push(v[i], scaleSize(v[i + 1], f)); } return out; }
			if (op === 'step') { out = [v[0], v[1], scaleSize(v[2], f)]; for (i = 3; i < v.length; i += 2) { out.push(v[i], scaleSize(v[i + 1], f)); } return out; }
			if (op === 'case') { out = [v[0]]; for (i = 1; i < v.length - 1; i += 2) { out.push(v[i], scaleSize(v[i + 1], f)); } out.push(scaleSize(v[v.length - 1], f)); return out; }
			if (op === 'match') { out = [v[0], v[1]]; for (i = 2; i < v.length - 1; i += 2) { out.push(v[i], scaleSize(v[i + 1], f)); } out.push(scaleSize(v[v.length - 1], f)); return out; }
			if (op === 'coalesce') { return [v[0]].concat(v.slice(1).map(function (x) { return scaleSize(x, f); })); }
			if (op === 'literal') { return v; }
			if (JSON.stringify(v).indexOf('["zoom"]') === -1) { return ['*', f, v]; }
			return v;
		}
		if (v && typeof v === 'object' && v.stops) { out = {}; for (var k in v) { out[k] = v[k]; } out.stops = v.stops.map(function (s) { return [s[0], scaleSize(s[1], f)]; }); return out; }
		return v;
	}
	/* The app's own look on top of the OpenFreeMap "bright" style: every label f times larger, street names bold,
	   near-black with a white halo, minor roads outlined in grey, main roads in a deeper orange, buildings darker.
	   Matches layers by name pattern so a style update upstream degrades gracefully. Re-run when Aa is toggled. */
	function tuneStyle(gm, f) {
		var orig = gm.__dhOrig || (gm.__dhOrig = {}), style = gm.getStyle();
		if (!style || !style.layers) { return; }
		style.layers.forEach(function (l) {
			var id = l.id, lay = l.layout || {};
			try {
				if (l.type === 'symbol') {
					if (/housenum/i.test(id)) { gm.setLayoutProperty(id, 'visibility', 'none'); return; } // the app draws the address map's house numbers itself
					if (lay['text-field'] === undefined) { return; }
					if (!(id in orig)) { orig[id] = lay['text-size'] === undefined ? 16 : lay['text-size']; }
					gm.setLayoutProperty(id, 'text-size', scaleSize(orig[id], f));
					var road = /highway-name|road_name|road_label|transportation_name|street/.test(id), place = /^label_|place/.test(id), water = /water/.test(id);
					gm.setPaintProperty(id, 'text-halo-color', '#FFFFFF');
					gm.setPaintProperty(id, 'text-halo-width', road ? 2.2 : 1.8);
					gm.setPaintProperty(id, 'text-halo-blur', 0.3);
					if (road) { gm.setPaintProperty(id, 'text-color', '#1F2328'); gm.setLayoutProperty(id, 'text-font', ['Noto Sans Bold']); }
					else if (place) { gm.setPaintProperty(id, 'text-color', '#111111'); }
					else if (water) { gm.setPaintProperty(id, 'text-color', '#2C5C9C'); }
					else { gm.setPaintProperty(id, 'text-color', '#3A3F47'); }
				} else if (l.type === 'line') {
					if (/(minor|service-track|service_track|link)[-_]casing/.test(id)) { gm.setPaintProperty(id, 'line-color', '#8E959E'); }
					else if (/(secondary-tertiary|trunk-primary|primary|trunk|motorway)[-_]casing/.test(id)) { gm.setPaintProperty(id, 'line-color', '#CF8B45'); }
					else if (/(^|-)path$/.test(id)) { gm.setPaintProperty(id, 'line-color', '#9A8C7C'); }
				} else if (l.type === 'fill') {
					if (id === 'building') { gm.setPaintProperty(id, 'fill-color', ['interpolate', ['linear'], ['zoom'], 15.5, '#EBE3DA', 16, '#D3CCC4']); gm.setPaintProperty(id, 'fill-outline-color', '#B4AAA0'); }
					else if (id === 'building-top') { gm.setPaintProperty(id, 'fill-color', '#E8E0D7'); gm.setPaintProperty(id, 'fill-outline-color', '#BDB3A8'); }
				}
			} catch (e) {}
		});
	}
	function rasterLayer() {
		var big = bigMap();
		return L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
			attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
			maxZoom: 20, maxNativeZoom: 19, tileSize: big ? 512 : 256, zoomOffset: big ? -1 : 0, className: 'zdh-tiles' + (big ? ' zdh-tiles-big' : '')
		});
	}
	/* The vector layer: registers its zoom limit with Leaflet like a tile layer does, and falls back to raster tiles
	   if WebGL or the style fails. */
	var GLLayer = (window.L && L.MaplibreGL) ? L.MaplibreGL.extend({
		beforeAdd: function (map) { map._addZoomLimit(this); },
		onAdd: function (map) {
			var self = this;
			try { L.MaplibreGL.prototype.onAdd.call(this, map); }
			catch (e) {
				this._failed = true; glOK = false;
				setTimeout(function () { try { map.removeLayer(self); } catch (err) {} if (map._loaded) { rasterLayer().addTo(map); } }, 0);
				return;
			}
			hookGL(this, map);
		},
		onRemove: function (map) {
			if (!this._failed) { try { L.MaplibreGL.prototype.onRemove.call(this, map); } catch (e) {} }
			map._removeZoomLimit(this);
		}
	}) : null;
	function hookGL(layer, map) {
		var gm = layer.getMaplibreMap(); if (!gm) { return; }
		var loaded = false;
		var tune = function () { loaded = true; try { tuneStyle(gm, textFactor()); } catch (e) {} };
		gm.on('style.load', tune);
		if (gm.isStyleLoaded()) { tune(); }
		gm.on('error', function (e) {
			var err = e && e.error, where = String((err && err.url) || '');
			if (loaded || layer._fellBack) { return; } // a tile / font hiccup after the style is in is not fatal
			if (where && where.indexOf('/styles/') < 0) { return; }
			layer._fellBack = true; glOK = false;
			setTimeout(function () { // outside MapLibre's own event dispatch
				try { map.removeLayer(layer); } catch (e2) {}
				if (map._loaded && map._container && map._container.isConnected) { rasterLayer().addTo(map); }
			}, 0);
		});
	}
	function tileLayer() {
		if (glSupported() && GLLayer) {
			return new GLLayer({ style: STYLE_URL, maxZoom: 21, className: 'zdh-gl', fadeDuration: 150, refreshExpiredTiles: false });
		}
		return rasterLayer();
	}
	var ICON_TEXT = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 18 8.5 5l5.5 13"/><path d="M5 13.5h7"/><path d="M15 18l3-7 3 7"/><path d="M16.2 15.5h3.6"/></svg>';
	/* the Aa button: bigger map text on / off, remembered on this device, applied to every open map at once */
	function addTextControl(map) {
		var Ctl = L.Control.extend({ options: { position: 'topleft' }, onAdd: function () {
			var div = L.DomUtil.create('div', 'leaflet-bar');
			var a = L.DomUtil.create('a', 'zdh-textsize' + (bigMap() ? ' on' : ''), div);
			a.href = '#'; a.title = 'Bigger map text'; a.setAttribute('role', 'button'); a.setAttribute('aria-pressed', bigMap() ? 'true' : 'false'); a.innerHTML = ICON_TEXT;
			L.DomEvent.disableClickPropagation(div);
			L.DomEvent.on(a, 'click', function (e) {
				L.DomEvent.preventDefault(e);
				store('bigmap', bigMap() ? '0' : '1');
				[draw.map, review.map, D.map, Q.map, logMap].forEach(function (m) { if (m && m._loaded) { swapTiles(m); } });
				toast(bigMap() ? 'Bigger map text on.' : 'Map text back to normal size.', 2500);
			});
			return div;
		} });
		map.addControl(new Ctl());
		map.on('zoomend', function () { labelZoom(map); });
		labelZoom(map);
	}
	/* Aa toggled: the vector map re-sizes its labels in place; raster tiles are re-requested at the other size. */
	function swapTiles(map) {
		var rasters = [];
		map.eachLayer(function (l) {
			if (GLLayer && l instanceof GLLayer) { var gm = l.getMaplibreMap(); if (gm && gm.isStyleLoaded()) { try { tuneStyle(gm, textFactor()); } catch (e) {} } }
			else if (l instanceof L.TileLayer) { rasters.push(l); }
		});
		rasters.forEach(function (l) { map.removeLayer(l); rasterLayer().addTo(map); });
		map.getContainer().querySelectorAll('.zdh-textsize').forEach(function (a) { a.classList.toggle('on', bigMap()); a.setAttribute('aria-pressed', bigMap() ? 'true' : 'false'); });
	}
	/* house-number labels only from zoom 18 in (too dense before that), placed on the far side of the house from its street */
	function labelZoom(map) { map.getContainer().classList.toggle('zdh-labels', map.getZoom() >= 18); }
	function labelSide(pt, path) { // 'top' | 'bottom' | 'left' | 'right': away from the road
		if (!path || path.length < 2) { return 'right'; }
		var best = Infinity, near = null, cos = Math.cos(pt[0] * Math.PI / 180);
		for (var i = 0; i < path.length - 1; i++) {
			var a = path[i], b = path[i + 1], px = (pt[1] - a[1]) * cos, py = pt[0] - a[0], bx = (b[1] - a[1]) * cos, by = b[0] - a[0], l2 = bx * bx + by * by; if (!l2) { continue; }
			var u = Math.max(0, Math.min(1, (px * bx + py * by) / l2)), dx = px - u * bx, dy = py - u * by, d = dx * dx + dy * dy;
			if (d < best) { best = d; near = [dx, dy]; }
		}
		if (!near) { return 'right'; }
		return Math.abs(near[1]) > Math.abs(near[0]) ? (near[1] > 0 ? 'top' : 'bottom') : (near[0] > 0 ? 'right' : 'left');
	}
	function numberLabel(dot, number, side) {
		var off = { top: [0, -8], bottom: [0, 8], left: [-8, 0], right: [8, 0] }[side || 'right'];
		dot.bindTooltip(String(number), { permanent: true, direction: side || 'right', offset: off, className: 'zdh-num', interactive: false, opacity: 1 });
	}
	function loadDrops() {
		if (S.drops) { return Promise.resolve({ drops: S.drops, stats: S.stats }); }
		return api('drops').then(function (r) { S.drops = r.drops; S.stats = r.stats; return r; });
	}
	/* Previous coverage painted on any map: done = blue, planned = green. */
	/* the pop-up is a short card, not a report (it listed every street, ran off the top of the map and sat under
	   the zoom buttons): the name; planned / in progress / done · date · houses; two streets "+N more"; who; one button. */
	function dropPopupHTML(d) {
		var planned = d.status === 'planned', active = d.status === 'in_progress';
		var sts = String(d.streets_summary || '').split(' / ').filter(Boolean), stLine = sts.slice(0, 2).join(', ') + (sts.length > 2 ? ' +' + (sts.length - 2) + ' more' : '');
		var got = d.delivered_count != null ? d.delivered_count : d.address_count;
		var houses = planned ? d.address_count + ' houses' : got + (d.delivered_count != null && d.delivered_count !== d.address_count ? ' of ' + d.address_count : '') + ' houses';
		return '<div class="zdh-pop-title">' + esc(d.name || stLine || ('#' + d.id)) + '</div>' +
			'<div class="zdh-pop-line"><strong>' + (isTodo(d) ? 'To be done' : (planned ? 'Planned' : (active ? 'In progress' : 'Done'))) + '</strong> &bull; ' + esc(fmtDate(d.drop_date, true)) + ' &bull; <strong>' + esc(houses) + '</strong></div>' +
			(d.name && stLine ? '<div class="zdh-pop-line zdh-pop-streets">' + esc(stLine) + '</div>' : '') +
			(d.delivered_by ? '<div class="zdh-pop-line">' + esc(d.delivered_by) + '</div>' : '') +
			(d.id && document.getElementById('zdh-card-' + d.id) ? '<a class="zdh-pop-btn" href="#zdh-card-' + d.id + '" data-card="' + d.id + '">Show the card</a>' : '');
	}
	// at most 260 px wide; auto-panned clear of the zoom and text-size buttons (top left)
	var POPUP_OPTS = { maxWidth: 260, minWidth: 200, autoPanPaddingTopLeft: [64, 12], autoPanPaddingBottomRight: [12, 12], className: 'zdh-popup' };
	function paintCoverage(map, drops, opts) {
		opts = opts || {};
		var pts = [];
		// the cluster dots were big (up to 22 px) and sat on top of the very streets they stand for (the walker's
		// phone view). They are small now and step aside once the map is close enough to read the streets.
		// (hidden with a CSS class on the map box: adding/removing a layer group while the vector map was still starting
		// up threw "this._renderer._bounds" on iPhones and took the whole Clusters tab down)
		if (opts.markers) {
			var syncMarkers = function () { try { var c = map.getContainer(); if (c && c.classList) { c.classList.toggle('zdh-hide-marks', map.getZoom() >= 15); } } catch (e) {} };
			map.on('zoomend', syncMarkers);
			map.whenReady(syncMarkers);
		}
		(drops || []).forEach(function (d) {
			var planned = d.status === 'planned', active = d.status === 'in_progress';
			var color = opts.byDrop ? d.marker_color : (isTodo(d) ? COLOR_TODO : (planned ? COLOR_PLANNED : (active ? COLOR_ACTIVE : COLOR_DONE)));
			var g = d.geometry || {};
			var style = { color: color, weight: planned ? 5 : 5, opacity: planned ? 0.9 : 0.75, dashArray: planned ? '8 6' : null, lineCap: 'round', lineJoin: 'round' };
			var mine = { lines: [], pts: [], color: color, style: style };
			if (opts.byId) { opts.byId[d.id] = mine; }
			(g.segments || []).forEach(function (s) {
				var line = L.polyline(s.path, style).addTo(map);
				line.bindTooltip(s.name + ' — ' + (planned ? 'planned' : (active ? 'in progress' : fmtDate(d.drop_date, true))) + ' (#' + d.id + ')', { direction: 'top' });
				if (opts.markers) { line.bindPopup(function () { return dropPopupHTML(d); }, POPUP_OPTS); }
				mine.lines.push(line);
				s.path.forEach(function (p) { pts.push(p); mine.pts.push(p); });
			});
			(g.strokes || []).forEach(function (st) {
				if (st.type === 'loop') { L.polygon(st.points, { color: color, weight: 2, fillOpacity: 0.08, dashArray: planned ? '6 4' : null }).addTo(map); }
				else { L.polyline(st.points, { color: color, weight: 3, opacity: 0.35 }).addTo(map); }
			});
			if (g.region) { L.polygon(g.region, { color: color, weight: 2, fillOpacity: 0.08, dashArray: '4 4' }).addTo(map); }
			if (d.lat != null && opts.markers) {
				var m = L.circleMarker([d.lat, d.lng], { radius: Math.min(6 + (d.quantity || d.address_count || 0) / 60, 10), fillColor: color, color: '#fff', weight: 2, fillOpacity: planned ? 0.6 : 0.85, className: 'zdh-clmark' }).addTo(map);
				m.bindPopup(function () { return dropPopupHTML(d); }, POPUP_OPTS);
				mine.marker = m;
				pts.push([d.lat, d.lng]);
				if (!mine.pts.length) { mine.pts.push([d.lat, d.lng]); }
			}
		});
		return pts;
	}

	/* ------------------------------------------------------------ state */
	var S = { tab: 'new', drops: null, stats: null, nd: null, batch: null, batchPrev: null };
	function resetNewDrop() {
		clearDraft();
		S.nd = makeND();
	}
	/* A fresh route state, also one per sheet of the old-routes list, so several sheets are read in the background
	   while another is on screen (the reading, lining up, house lookup and saving take a state, not the screen's). */
	function makeND() {
		return {
			mode: 'map',      // 'map' | 'shot'
			step: 'draw',     // draw | form | busy | streets | review | done
			editId: null,     // set when editing an existing planned cluster
			files: [], uploaded: [],
			form: {
				date: todayISO(), time: nowHM(), delivered_by: recall('deliverer') || C.userName || '', hanger_type: recall('type') || (C.hangerTypes && C.hangerTypes[0]) || 'Standard',
				hanger_side: recall('side') || '', quantity: '', zip: recall('zip') || '', city: C.defaultCity || '', notes: '', lat: null, lng: null,
				status: 'planned', revisit_days: C.revisitDays || 90, name: ''
			},
			analysis: null, streets: [], cross: [], geo: null, sel: {}, region: null, strokes: [], georef: null, mask: null, maskColor: 'orange', skipUnits: recall('skipunits') === '1',
			walkOpts: { size: +recall('walk_size') || +C.walkSize || 60, style: recall('route_style') || C.routeStyle || 'loop' }, walks: null,
			error: '', busy: ''
		};
	}
	/* the walks for the current selection */
	function computeWalks() {
		var nd = S.nd, g = nd.geo;
		if (!g || nd.quick || !nd.walkOpts) { nd.walks = null; return; }
		var houses = g.addresses.filter(function (a) { return nd.sel[a._k] && a.lat != null; }).map(function (a) { return { k: a._k, lat: a.lat, lng: a.lng, seg: a._seg, street: a.street, street_key: a.street_key }; });
		var start = nd.strokes && nd.strokes.length ? nd.strokes[0].points[0] : null;
		nd.walks = houses.length ? planWalks(houses, g.segments || [], { size: nd.walkOpts.size, style: nd.walkOpts.style, perHour: +C.walkRate || 40, start: start }) : null;
	}
	resetNewDrop();

	/* ----------------------------------------------------------- render */
	function render() {
		var tabs = [['new', 'Plan'], ['map', 'Clusters'], ['lookup', 'Lookup'], ['export', 'Export']];
		// the title and the tab bar stay stuck to the top of the screen — the anchor of the app
		// the theme's card header is the page header (Back, Ask chat, Pin); the app keeps only its tab bar stuck to the top of its scroll
		var html = '<div class="zdh-top">';
		html += '<nav class="zdh-tabs">' + tabs.map(function (t) { return '<button class="zdh-tab' + (S.tab === t[0] ? ' active' : '') + '" data-tab="' + t[0] + '">' + t[1] + '</button>'; }).join('') + '</nav></div>';
		var ses = session(), q = quick();
		if (S.tab !== 'deliver' && S.tab !== 'quick' && !(S.nd && S.nd.quick && S.tab === 'new')) {
			if (q && q.ended) { html += '<div class="zdh-quick zdh-quick-on zdh-quick-ended" id="zdh-quick"><span class="zdh-quick-text">✓ Walk ' + esc(fmtClock(q.started)) + '–' + esc(fmtClock(q.ended)) + ' recorded &bull; <span id="qs-stats">' + esc(quickStatsText(q)) + '</span> &bull; not counted yet</span><span class="zdh-banner-btns"><button type="button" class="zdh-mini" id="qs-open">Details</button><button type="button" class="zdh-primary zdh-stop-mini" id="qs-done">Count the houses</button></span></div>'; }
			else if (q) { html += '<div class="zdh-quick zdh-quick-on" id="zdh-quick"><span class="zdh-quick-text">' + (q.pace === 'drive' ? '🚗 Driving — not counting' : '🚶 Recording your walk') + ' since ' + esc(fmtClock(q.started)) + ' &bull; <span id="qs-stats">' + esc(quickStatsText(q)) + '</span></span><span class="zdh-banner-btns"><button type="button" class="zdh-mini" id="qs-open">Map</button><button type="button" class="zdh-stop zdh-stop-mini" id="qs-done">■ Done delivering</button></span></div>'; }
			else if (!ses) { html += '<div class="zdh-quick" id="zdh-quick"><button type="button" class="zdh-go" id="qs-start">▶ Quick Start</button><span class="zdh-quick-help">' + tx('No plan needed — walk, then tap Done.', 'No plan needed: walk, and the app logs the houses you pass and which side of the street.') + '</span></div>'; }
		}
		if (ses && S.tab !== 'deliver') { html += '<div class="zdh-banner" id="zdh-banner"><span>Delivering <strong>' + esc(ses.name || ('cluster #' + ses.id)) + '</strong> — ' + Object.keys(ses.delivered).length + ' of ' + ses.total + ' done</span><span class="zdh-banner-btns"><button type="button" class="zdh-mini zdh-mini-primary" id="zdh-banner-resume">Resume</button><button type="button" class="zdh-mini" id="zdh-banner-leave" title="Stop for now — you can resume it from Clusters">Stop</button></span></div>'; }
		html += '<main class="zdh-main" id="zdh-main"></main>';
		html += '<footer class="zdh-footer">' + (C.llmReady ? '' : '<span class="zdh-warn">Map reading is off: no AI key or vision model on Zorderz → Settings.</span> &bull; ') + '<button type="button" class="zdh-linkbtn" id="zdh-refresh">↻ Reload data</button> &bull; <button type="button" class="zdh-linkbtn" id="zdh-help-link">How it works</button> &bull; <button type="button" class="zdh-linkbtn" id="zdh-diag-link">Diagnostics</button></footer>';
		teardownMaps();
		document.body.classList.remove('zdh-has-bar'); // the views that need the bottom bar add it back
		root.innerHTML = html;
		renderOutboxNote();
		root.querySelectorAll('.zdh-tab').forEach(function (b) { b.addEventListener('click', function () {
			var from = S.tab;
			S.tab = b.dataset.tab; store('tab', S.tab); if (S.tab === 'new' && S.nd.step === 'done') { resetNewDrop(); } render();
			if (from === 'deliver' && session()) { toast('Still delivering — GPS keeps running. Tap Resume in the yellow bar to come back.', 5000); }
			if (from === 'quick' && quick()) { toast('Still recording — the red Done button is at the top.', 5000); }
		}); });
		document.getElementById('zdh-refresh').addEventListener('click', refreshApp);
		var br = document.getElementById('zdh-banner-resume');
		if (br) { br.addEventListener('click', function () { openDelivery(ses.id); }); }
		var bl = document.getElementById('zdh-banner-leave');
		if (bl) { bl.addEventListener('click', function () { leaveDelivery('Stopped for now — tap Resume on the cluster when you\'re back.'); }); }
		document.getElementById('zdh-help-link').addEventListener('click', function () { store('help_seen', ''); if (S.nd && S.nd.step === 'done') { resetNewDrop(); } S.tab = 'new'; store('tab', 'new'); render(); scrollTop(); });
		document.getElementById('zdh-diag-link').addEventListener('click', function () { S.tab = 'export'; store('tab', 'export'); render(); scrollTo(document.getElementById('zdh-diag')); });
		var qsS = document.getElementById('qs-start'), qsO = document.getElementById('qs-open'), qsD = document.getElementById('qs-done');
		if (qsS) { qsS.addEventListener('click', startQuick); }
		if (qsO) { qsO.addEventListener('click', function () { S.tab = 'quick'; render(); }); }
		if (qsD) { qsD.addEventListener('click', finishQuick); }
		var main = document.getElementById('zdh-main');
		if (S.tab === 'quick') { renderQuick(main); }
		else if (S.tab === 'deliver') { renderDelivery(main); }
		else if (S.tab === 'new') { renderNew(main); }
		else if (S.tab === 'map') { renderMapLog(main); }
		else if (S.tab === 'lookup') { renderLookup(main); }
		else { renderExport(main); }
	}
	/* The one button a walk needs — Done — sits in a bar pinned to the bottom of the phone's screen, never below the fold
	  . Inline on wide screens. */
	/* the walk map with the next-step banner over its top edge and a Re-center button (shown after a drag) */
	function navWrap(mapId) {
		return '<div class="zdh-navwrap">' +
			'<div class="zdh-nav zdz-pair" id="dv-nav" hidden></div><span class="zdh-sr" id="dv-nav-sr" aria-live="polite"></span>' +
			'<div id="' + mapId + '" class="zdh-map"></div>' +
			'<button type="button" class="zdh-recenter zdz-pair" id="dv-recenter" hidden></button>' +
		'</div>';
	}
	function actionBar(inner) {
		document.body.classList.add('zdh-has-bar');
		return '<div class="zdh-actionbar" id="zdh-actionbar">' + inner + '</div>';
	}
	/* Every render rebuilds its own map; kill the old ones first so Leaflet never animates into a detached container. */
	function killMap(m) {
		if (!m) { return; }
		try { m._animatingZoom = false; if (m.stop) { m.stop(); } } catch (e) {}
		try { m.off(); } catch (e) {}
		try { m.remove(); } catch (e) {}
		try { m._loaded = false; } catch (e) {} // late addTo() calls from async callbacks queue harmlessly instead of throwing
	}
	function teardownMaps() {
		var maps = [[D, 'map'], [draw, 'map'], [review, 'map'], [Q, 'map']];
		maps.forEach(function (m) { if (m[0] && m[0][m[1]]) { killMap(m[0][m[1]]); m[0][m[1]] = null; } });
		if (logMap) { killMap(logMap); logMap = null; }
		if (D) { D.dots = {}; D.me = null; D.acc = null; D.trail = null; D.drive = null; D.tracks = null; D.beam = null; }
		Q.tracks = null; Q.drive = null; Q.beam = null; endPeek(true);
	}
	/* Leaflet needs a size recheck once the card has laid out — but only if that map is still on the page. */
	function settle(map) {
		setTimeout(function () { try { if (map && map._mapPane && map._container && map._container.isConnected) { map.invalidateSize(); } } catch (e) {} }, 200);
	}
	function isStandalone() { return window.matchMedia && window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true; }
	/* No service worker of its own: the theme's service worker owns scope "/". */

	/* The app's overlays (walk-mode sheets, pocket mode, the location help) mount on <body>, above the open page (z above 205). */
	var install = { root: null, sheet: null };
	function platform() {
		var ua = navigator.userAgent || '';
		var iOS = /iPhone|iPad|iPod/.test(ua) || (/Macintosh/.test(ua) && navigator.maxTouchPoints > 1);
		var android = /Android/i.test(ua);
		var chromeLike = /Chrome|Chromium|CriOS|EdgiOS|Edg\//.test(ua);
		var safari = /Safari/.test(ua) && !chromeLike && !/FxiOS|Firefox|OPR|OPiOS/.test(ua);
		return { iOS: iOS, android: android, mobile: iOS || android, safari: safari, chromeLike: chromeLike, mac: /Macintosh/.test(ua) && !iOS, iosSafari: iOS && safari, iosOther: iOS && !safari };
	}
	var ICONS = {
		dots: '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="5" cy="12" r="2.2"/><circle cx="12" cy="12" r="2.2"/><circle cx="19" cy="12" r="2.2"/></svg>',
		vdots: '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="5" r="2.2"/><circle cx="12" cy="12" r="2.2"/><circle cx="12" cy="19" r="2.2"/></svg>',
		share: '<svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 15V3"/><path d="M8 7l4-4 4 4"/><path d="M5 11v8a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-8"/></svg>',
		chev: '<svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9l6 6 6-6"/></svg>',
		check: '<svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>',
		plus: '<svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3.5" y="3.5" width="17" height="17" rx="4"/><path d="M12 8v8M8 12h8"/></svg>',
		menu: '<svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M4 7h16M4 12h16M4 17h16"/></svg>',
		dock: '<svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="12" rx="2"/><path d="M8 20h8"/></svg>',
		download: '<svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 4v11"/><path d="M8 11l4 4 4-4"/><path d="M5 19h14"/></svg>'
	};
	function installRoot() {
		if (install.root) { return install.root; }
		install.root = el('<div class="zdh-app zdh-install-root"></div>');
		document.body.appendChild(install.root);
		return install.root;
	}
	/* One builder for the install and location sheets. d.top = pin a compact card to the top of the screen (iPhone Safari:
	   the •••/Share sheet slides up over the bottom half, so the steps must live above it); otherwise a bottom sheet. */
	function buildSheet(d, extraHtml) {
		var wrap = el('<div class="zdh-install-wrap' + (d.top ? ' zdh-sheet-top' : '') + '" role="dialog" aria-modal="true" aria-label="' + esc(d.title) + '"><div class="zdh-install-sheet' + (d.top ? ' zdh-compact' : '') + '">' +
			'<button type="button" class="zdh-install-close" aria-label="Close">×</button>' +
			'<h2 class="zdh-install-title">' + esc(d.title) + '</h2>' + (d.sub ? '<p class="zdh-install-sub">' + esc(d.sub) + '</p>' : '') +
			'<ol class="zdh-install-steps">' + d.steps.map(function (s, i) { return '<li><span class="zdh-inst-n">' + (i + 1) + '</span><span class="zdh-inst-ic">' + s[0] + '</span><span class="zdh-inst-t">' + s[1] + '</span></li>'; }).join('') + '</ol>' +
			(d.foot ? '<p class="zdh-install-foot">' + d.foot + '</p>' : '') +
			(extraHtml || '') +
			(d.arrow && !d.top ? '<div class="zdh-install-arrow" aria-hidden="true">↓</div>' : '') +
		'</div>' + (d.arrow && d.top ? '<div class="zdh-install-arrow zdh-arrow-fixed" aria-hidden="true">↓</div>' : '') + '</div>');
		var close = function () { wrap.remove(); if (install.sheet === wrap) { install.sheet = null; } };
		wrap.querySelector('.zdh-install-close').addEventListener('click', close);
		wrap.addEventListener('click', function (e) { if (e.target === wrap) { close(); } });
		wrap._close = close;
		return wrap;
	}
	function closeSheet() { if (install.sheet) { install.sheet.remove(); install.sheet = null; } }

	/* ===================================================== LOCATION PERMISSION
	   The browser owns the permission; what we can do is ask at a sensible moment (a tap), remember the answer,
	   and show exactly where to flip it back on when it was denied. */
	function primeLocation(cb) {
		if (!navigator.geolocation) { if (cb) { cb('unsupported'); } return; }
		navigator.geolocation.getCurrentPosition(function () { store('loc', 'ok'); if (cb) { cb('ok'); } }, function (err) {
			if (err.code === 1) { store('loc', 'denied'); if (cb) { cb('denied'); } } else if (cb) { cb('unavailable'); }
		}, { timeout: 8000, maximumAge: 60000, enableHighAccuracy: false });
	}
	function watchPermission() {
		if (!(navigator.permissions && navigator.permissions.query)) { return; }
		try {
			navigator.permissions.query({ name: 'geolocation' }).then(function (p) {
				var apply = function () { if (p.state === 'granted') { store('loc', 'ok'); } else if (p.state === 'denied') { store('loc', 'denied'); } };
				apply(); p.onchange = function () { apply(); if (p.state === 'granted' && session() && S.tab === 'deliver') { startGps(); } };
			}).catch(function () {});
		} catch (e) {}
	}
	watchPermission();
	function locationSteps() {
		var p = platform(), hi = function (t) { return '<b>' + t + '</b>'; }, site = location.hostname.replace(/^www\./, '');
		if (p.iOS && isStandalone()) { return { title: 'Turn location on for the app', sub: 'The home-screen app uses Safari\'s website settings:', steps: [
			[ICONS.menu, 'Open ' + hi('Settings → Apps → Safari → Location')],
			[ICONS.check, 'Find ' + hi(site) + ' and set it to ' + hi('Allow')],
			[ICONS.dots, 'Still nothing? ' + hi('Settings → Privacy & Security → Location Services → Safari Websites') + ' must be ' + hi('While Using')]
		], foot: 'If it never asks at all: remove the app from the home screen, open the page in Safari, tap Allow when asked, then add it to the home screen again.' }; }
		if (p.iOS) { return { title: 'Turn location on in Safari', sub: 'Keep this open:', steps: [
			[ICONS.dots, 'Tap ' + hi('•••') + ' (or ' + hi('aA') + ') at the bottom, then ' + hi('Website Settings')],
			[ICONS.check, 'Set ' + hi('Location') + ' to ' + hi('Allow')],
			[ICONS.menu, 'Make it permanent: ' + hi('Settings → Apps → Safari → Location → ' + site + ' → Allow')]
		], foot: 'A one-time Allow is forgotten after a day; the Settings step (or the installed app) keeps it.' }; }
		if (p.android) { return { title: 'Turn location on in Chrome', sub: 'It then stays on for this site:', steps: [
			[ICONS.dots, 'Tap the ' + hi('tune / lock icon') + ' next to the address, then ' + hi('Permissions')],
			[ICONS.check, 'Set ' + hi('Location') + ' to ' + hi('Allow')],
			[ICONS.menu, 'Phone off the grid? Check ' + hi('Settings → Location') + ' is on and Chrome is allowed']
		] }; }
		if (p.mac && p.safari) { return { title: 'Turn location on in Safari', sub: '', steps: [
			[ICONS.menu, hi('Safari → Settings → Websites → Location')],
			[ICONS.check, 'Set ' + hi(site) + ' to ' + hi('Allow') + ', then reload']
		] }; }
		return { title: 'Turn location on', sub: '', steps: [
			[ICONS.dots, 'Click the ' + hi('icon left of the address bar') + ' (tune / lock)'],
			[ICONS.check, hi('Location → Allow') + ', then reload the page']
		] };
	}
	function openLocationHelp() {
		var d = locationSteps();
		var p = platform();
		if (p.iOS && !isStandalone() && p.safari) { d.top = true; }
		closeSheet();
		var sheet = buildSheet(d, '<div class="zdh-actions" style="margin-top:.8rem"><button type="button" class="zdh-primary" id="loc-retry">I turned it on — try again</button></div>');
		var close = sheet._close;
		sheet.querySelector('#loc-retry').addEventListener('click', function () {
			primeLocation(function (r) { if (r === 'ok') { close(); toast('Location is on.'); if (session() && S.tab === 'deliver') { startGps(); } } else if (r === 'denied') { toast('Still blocked — the setting has not changed yet.'); } else { close(); toast('Location allowed, waiting for a GPS fix…'); if (session() && S.tab === 'deliver') { startGps(); } } });
		});
		install.sheet = sheet;
		installRoot().appendChild(sheet);
	}

	/* ===================================================== NEW DROP */
	function renderNew(main) {
		var nd = S.nd;
		if (nd.step === 'choose') { nd.mode = 'map'; nd.step = 'draw'; }
		if (nd.step === 'batch') { return renderBatch(main); }
		if (nd.step === 'draw') { return renderDraw(main); }
		if (nd.step === 'form') { return renderForm(main); }
		if (nd.step === 'streets') { return renderStreets(main); }
		if (nd.step === 'review') { return renderReview(main); }
		if (nd.step === 'done') { return renderDone(main); }
		main.innerHTML = '<div class="zdh-card zdh-busy"><div class="zdh-spinner"></div><div class="zdh-busy-text">' + esc(nd.busy) + '</div><div class="zdh-busy-sub">' + (nd.mode === 'shot' ? 'The map reading usually takes 30–90 seconds. It keeps running on the server even if your screen locks — just come back to this page.' : tx('', 'Pulling the address points for that area…')) + '</div></div>';
	}

	function renderChoose(main) {
		var nd = S.nd;
		main.innerHTML =
			'<div class="zdh-card">' +
				'<h2>Log a drop</h2>' +
				'<p class="zdh-help">Two ways to record which houses got a hanger. Streets you already covered show up in blue so you only mark the new ones.</p>' +
				'<button type="button" class="zdh-choice" id="c-map"><span class="zdh-choice-title">Draw on the map</span><span class="zdh-choice-sub">Trace the streets you walked (or circle the blocks) with your finger. Instant, no screenshot needed.</span></button>' +
				'<button type="button" class="zdh-choice" id="c-shot"><span class="zdh-choice-title">Upload a marked-up screenshot</span><span class="zdh-choice-sub">Circle or trace the area in Apple/Google Maps, screenshot it, and the AI reads it.</span></button>' +
			'</div>' +
			(nd.error ? '<div class="zdh-error">' + esc(nd.error) + '</div>' : '');
		document.getElementById('c-map').addEventListener('click', function () { nd.mode = 'map'; nd.step = 'draw'; nd.error = ''; render(); });
		document.getElementById('c-shot').addEventListener('click', function () { nd.mode = 'shot'; nd.step = 'form'; nd.error = ''; render(); });
	}

	/* Shared "Drop details" fields. */
	function detailsHTML(f, opts) {
		opts = opts || {};
		var types = (C.hangerTypes && C.hangerTypes.length) ? C.hangerTypes : ['Standard'];
		if (types.indexOf(f.hanger_type) < 0) { f.hanger_type = types[0]; }
		if (opts.compact) {
			return '<div class="zdh-grid">' +
				'<label>Hanger type<select id="f-type">' + types.map(function (t) { return '<option' + (t === f.hanger_type ? ' selected' : '') + '>' + esc(t) + '</option>'; }).join('') + '</select></label>' +
				'<label>Side out<select id="f-side"><option value=""' + (!f.hanger_side ? ' selected' : '') + '>Single-sided / n/a</option><option value="A"' + (f.hanger_side === 'A' ? ' selected' : '') + '>Side A</option><option value="B"' + (f.hanger_side === 'B' ? ' selected' : '') + '>Side B</option></select></label>' +
				'<label>Deliverer<input type="text" id="f-by" list="zdh-team" value="' + esc(f.delivered_by) + '" placeholder="Name"><datalist id="zdh-team">' + peopleNames().map(function (t) { return '<option value="' + esc(t) + '">'; }).join('') + '</datalist></label>' +
				'<label>Planned date<input type="date" id="f-date" value="' + esc(f.date) + '"></label>' +
				'<label>Revisit in (days)<input type="number" id="f-revisit" inputmode="numeric" min="0" value="' + esc(f.revisit_days) + '"></label>' +
				'<label>City<input type="text" id="f-city" value="' + esc(f.city) + '"></label>' +
				'<label class="zdh-span2">Notes<textarea id="f-notes" rows="2" placeholder="Skip the cul-de-sac, park at… (a gate code is kept sealed)">' + esc(f.notes) + '</textarea></label>' +
			'</div>';
		}
		return '<div class="zdh-grid">' +
			(opts.status ? '<label class="zdh-span2">This route is<div class="zdh-seg"><button type="button" class="zdh-segbtn' + (f.status !== 'planned' ? ' on' : '') + '" data-status="done">Done — I walked it</button><button type="button" class="zdh-segbtn' + (f.status === 'planned' ? ' on' : '') + '" data-status="planned">Planned for later</button></div></label>' : '') +
			'<label>' + (f.status === 'planned' ? 'Planned date' : 'Date') + '<input type="date" id="f-date" value="' + esc(f.date) + '"></label>' +
			'<label>Time<input type="time" id="f-time" value="' + esc(f.time) + '"></label>' +
			'<label>Delivered by<input type="text" id="f-by" list="zdh-team" value="' + esc(f.delivered_by) + '" placeholder="Name"><datalist id="zdh-team">' + peopleNames().map(function (t) { return '<option value="' + esc(t) + '">'; }).join('') + '</datalist></label>' +
			'<label>Door hanger type<select id="f-type">' + types.map(function (t) { return '<option' + (t === f.hanger_type ? ' selected' : '') + '>' + esc(t) + '</option>'; }).join('') + '</select></label>' +
			'<label>Side facing out<select id="f-side"><option value=""' + (!f.hanger_side ? ' selected' : '') + '>Single-sided / n/a</option><option value="A"' + (f.hanger_side === 'A' ? ' selected' : '') + '>Side A</option><option value="B"' + (f.hanger_side === 'B' ? ' selected' : '') + '>Side B</option></select></label>' +
			'<label>Quantity hung<input type="number" id="f-qty" inputmode="numeric" min="0" value="' + esc(f.quantity) + '" placeholder="e.g. 90"></label>' +
			'<label>ZIP code<input type="text" id="f-zip" inputmode="numeric" pattern="[0-9]*" maxlength="5" value="' + esc(f.zip) + '" placeholder="' + esc(C.zipHint || '') + '"></label>' +
			'<label>City<input type="text" id="f-city" value="' + esc(f.city) + '"></label>' +
			'<label>Revisit in (days)<input type="number" id="f-revisit" inputmode="numeric" min="0" value="' + esc(f.revisit_days) + '"></label>' +
			'<label class="zdh-span2">Notes<textarea id="f-notes" rows="2" placeholder="Anything useful — e.g. skipped the cul-de-sac (a gate code is kept sealed)">' + esc(f.notes) + '</textarea></label>' +
		'</div>';
	}
	function readDetails(f) {
		var g = function (id) { var e = document.getElementById(id); return e ? e.value : ''; };
		var has = function (id) { return !!document.getElementById(id); };
		f.date = g('f-date') || f.date; if (has('f-time')) { f.time = g('f-time'); }
		f.delivered_by = g('f-by').trim() || f.delivered_by; f.hanger_type = g('f-type') || f.hanger_type; f.hanger_side = has('f-side') ? g('f-side') : f.hanger_side;
		if (has('f-qty')) { f.quantity = g('f-qty'); } if (has('f-zip')) { f.zip = g('f-zip').replace(/\D/g, ''); } if (has('f-city')) { f.city = g('f-city').trim(); }
		if (has('f-revisit')) { f.revisit_days = g('f-revisit'); } if (has('f-notes')) { f.notes = g('f-notes').trim(); } if (has('f-name')) { f.name = g('f-name').trim(); }
		store('deliverer', f.delivered_by); store('type', f.hanger_type); store('side', f.hanger_side); store('zip', f.zip);
	}
	function bindStatusToggle(f, onChange) {
		root.querySelectorAll('.zdh-segbtn').forEach(function (b) {
			b.addEventListener('click', function () {
				f.status = b.dataset.status;
				root.querySelectorAll('.zdh-segbtn').forEach(function (x) { x.classList.toggle('on', x === b); });
				if (onChange) { onChange(); }
			});
		});
	}
	function hints(nd) {
		var f = (nd || S.nd).form;
		return { zip: f.zip, city: f.city, state: C.defaultState || '', date: f.date, time: f.time, notes: f.notes, delivered_by: f.delivered_by, lat: f.lat, lng: f.lng };
	}

	/* ------------------------------------------------ mode A: draw on map */
	var draw = { map: null, drawing: false, layers: [], current: null, currentLayer: null, coverage: null };

	var ICON_HAND = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 11V6a2 2 0 0 0-4 0v5"/><path d="M14 10V4a2 2 0 0 0-4 0v6"/><path d="M10 10.5V6a2 2 0 0 0-4 0v8"/><path d="M18 8a2 2 0 1 1 4 0v6a8 8 0 0 1-8 8h-2c-2.8 0-4.5-.86-5.99-2.34l-3.6-3.6a2 2 0 0 1 2.83-2.82L7 15"/></svg>';
	var ICON_PEN = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>';
	var ICON_UNDO = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 7v6h6"/><path d="M21 17a9 9 0 0 0-15-6.7L3 13"/></svg>';
	var ICON_CAMERA = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 8h3l2-3h6l2 3h3v11H4z"/><circle cx="12" cy="13" r="3.5"/></svg>';
	var ICON_TARGET = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="7"/><circle cx="12" cy="12" r="2.5" fill="currentColor" stroke="none"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3"/></svg>';
	/* "My location" as a button on the map (under the zoom buttons), like every maps app */
	/* Where a map opens when nothing on it says where to look: the business's map centre (the setting, else its
	   address rounded to about a kilometre), else a world view. Never a place of the app's own choosing. */
	function homeView(map, zoom) {
		var c = C.mapCenter && C.mapCenter.point;
		if (c && c.length === 2 && isFinite(+c[0]) && isFinite(+c[1])) { map.setView([+c[0], +c[1]], zoom); }
		else { map.setView([20, 0], 2); }
	}
	function addLocateControl(map, onFix) {
		var Ctl = L.Control.extend({ options: { position: 'topleft' }, onAdd: function () {
			var div = L.DomUtil.create('div', 'leaflet-bar');
			var a = L.DomUtil.create('a', 'zdh-locate', div);
			a.href = '#'; a.id = 'd-loc'; a.title = 'My location'; a.setAttribute('role', 'button'); a.setAttribute('aria-label', 'My location'); a.innerHTML = ICON_TARGET;
			L.DomEvent.disableClickPropagation(div);
			L.DomEvent.on(a, 'click', function (e) {
				L.DomEvent.preventDefault(e);
				a.classList.add('busy');
				locate(function (lat, lng) { a.classList.remove('busy'); onFix(lat, lng); },
					function (why) { a.classList.remove('busy'); if (why === 'denied') { openLocationHelp(); } else { toast('No GPS fix yet — try again outside, or type the ZIP.'); } });
			});
			return div;
		} });
		map.addControl(new Ctl());
	}
	/* First-run help: the three things anyone needs to know, shown on the Plan screen until dismissed (footer link brings it back). */
	function helpCardHTML() {
		if (recall('help_seen') === '1') { return ''; }
		return '<div class="zdh-card zdh-help-card" id="zdh-help-card"><h2>How it works</h2><ol class="zdh-help-steps">' +
			'<li><strong>Set off with Quick Start</strong> (green button) — or plan a route first and start it from Clusters.</li>' +
			'<li><strong>Keep the app open and the screen on.</strong> A locked iPhone stops the GPS: what you walk then is not recorded. The app keeps the screen awake by itself; just don\'t press the side button.</li>' +
			'<li><strong>Tap Done when you finish.</strong> The houses you passed appear on a map for you to confirm; tap a grey dot to add one, a green dot to remove one, then Save.</li></ol>' +
			'<p class="zdh-help">Walk on one side of the street and the app works out which side. Driving is detected and skipped — if it says "driving" while you are on foot, tap <em>I\'m on foot</em>. No signal? Everything is kept on the phone and on the site and finishes by itself later.</p>' +
			'<div class="zdh-actions"><button type="button" class="zdh-secondary" id="zdh-help-ok">Got it</button></div></div>';
	}
	function renderDraw(main) {
		var nd = S.nd, f = nd.form;
		main.innerHTML = (nd.editId ? '' : helpCardHTML()) +
			'<div class="zdh-card zdh-card-tight">' +
				'<h2>' + (nd.editId ? 'Edit the route' : tx('Plan a route', 'Plan a neighborhood cluster')) + '</h2>' +
				(!nd.editId && plannerHeld() ? '<p class="zdh-help"><button type="button" class="zdh-secondary zdh-rp-link" id="d-rplan">Plan with Route Planner</button> Three routes picked for you from the census, the forecast and drive-by notes: choose one, edit it or draw your own, and it comes back here.</p>' +
					'<p class="zdh-help"><button type="button" class="zdh-secondary zdh-rp-link" id="d-survey">Drive-by survey</button> Drive a neighborhood and say what you see in the Route Planner.</p>' : '') +
				'<p class="zdh-help zdh-desk">Type a ZIP (or tap the target button on the map), zoom in on the block, switch to <strong>Draw route</strong>, then trace the streets you\'ll walk or circle a block. Blue = already covered, green = planned, orange = to be done, pink = in progress.</p>' +
				'<div class="zdh-grid" style="margin-bottom:.5rem"><label>ZIP<input type="text" id="f-zip" inputmode="numeric" maxlength="5" value="' + esc(f.zip) + '" placeholder="' + esc(C.zipHint || '') + '"></label><label>' + tx('Name', 'Cluster name') + '<input type="text" id="f-name" value="' + esc(f.name) + '" placeholder="optional"></label></div>' +
				'<div class="zdh-modeseg" role="group" aria-label="Map mode">' +
					'<button type="button" class="zdh-mode-move" id="d-pan" aria-pressed="true">' + ICON_HAND + ' Move map</button>' +
					'<button type="button" class="zdh-mode-draw" id="d-draw" aria-pressed="false">' + ICON_PEN + ' Draw route</button>' +
				'</div>' +
				'<p class="zdh-mode-hint" id="d-hint"></p>' +
				'<div class="zdh-mapwrap"><div id="d-map" class="zdh-map"></div><div class="zdh-map-mode" id="d-mode">Move map</div></div>' +
				'<div class="zdh-maptools" style="margin-top:.5rem">' +
					'<button type="button" class="zdh-tool" id="d-undo">' + ICON_UNDO + ' Undo</button>' +
					'<button type="button" class="zdh-tool" id="d-clear">Clear all</button>' +
					'<span class="zdh-count" id="d-count" style="margin:0 0 0 auto;align-self:center">' + tx('Nothing drawn yet.', 'No strokes yet.') + '</span>' +
				'</div>' +
			'</div>' +
			'<details class="zdh-card zdh-details" id="d-details"' + (recall('details') === 'closed' ? '' : ' open') + '><summary>' + tx('Details', 'Details (type, side, deliverer, date, revisit)') + '</summary>' + detailsHTML(f, { status: false, compact: true }) + '</details>' +
			(nd.error ? '<div class="zdh-error">' + esc(nd.error) + '</div>' : '') +
			'<div class="zdh-actions">' + (nd.editId ? '<button class="zdh-secondary" id="d-cancel">Cancel</button>' : '') + '<button class="zdh-primary" id="d-go">Find the houses →</button></div>' +
			(nd.editId ? '' : (paperLib() ? '<button type="button" class="zdh-choice zdh-paper-choice" id="d-paper"><span class="zdh-choice-title">' + ICON_CAMERA + ' Add routes you already walked</span><span class="zdh-choice-sub">' + (S.batch && S.batch.sheets.length ? esc(batchSummaryText()) + ' — tap to go back to them.' : tx('Photos or a PDF of the printed route sheets. Each sheet becomes a route.', 'Photos (or a PDF) of the printed route sheets with the walked streets highlighted. Pick them all at once: each sheet is read and lined up with the street map, you check them in one list and save.')) + '</span></button>' : '') +
				'<p class="zdh-help zdh-desk" style="text-align:center">Walked it already and marked a map screenshot? <button type="button" class="zdh-linkbtn" id="d-shot">Upload the screenshot instead</button></p>');

		buildDrawMap();
		var hk = document.getElementById('zdh-help-ok'); if (hk) { hk.addEventListener('click', function () { store('help_seen', '1'); var hc = document.getElementById('zdh-help-card'); if (hc) { hc.remove(); } settle(draw.map); }); }
		var dd = document.getElementById('d-details'); if (dd) { dd.addEventListener('toggle', function () { store('details', dd.open ? 'open' : 'closed'); }); }
		document.getElementById('d-draw').addEventListener('click', function () { setDrawing(true); });
		document.getElementById('d-pan').addEventListener('click', function () { setDrawing(false); });
		var sv = document.getElementById('d-survey'); if (sv) { sv.addEventListener('click', function () { logEv('open', 'routeplanner survey'); window.Bridge.loadApp('route-planner', { mode: 'survey' }); }); }
		// planning in the Route Planner, with the ZIP typed here
		var rp = document.getElementById('d-rplan'); if (rp) { rp.addEventListener('click', function () { var z = String((document.getElementById('f-zip') || {}).value || f.zip || '').replace(/\D/g, ''); logEv('open', 'routeplanner plan'); window.Bridge.loadApp('route-planner', z.length === 5 ? { mode: 'plan', zip: z } : { mode: 'plan' }); }); }
		document.getElementById('d-undo').addEventListener('click', function () { var l = draw.layers.pop(); if (l) { l.remove(); nd.strokes.pop(); } updateStrokeCount(); });
		document.getElementById('d-clear').addEventListener('click', function () { draw.layers.forEach(function (l) { l.remove(); }); draw.layers = []; nd.strokes = []; updateStrokeCount(); });
		setDrawing(false); // sets the switch, the hint and the map label
		document.getElementById('f-zip').addEventListener('change', function (e) { var z = e.target.value.replace(/\D/g, ''); f.zip = z; store('zip', z); if (z.length === 5 && !nd.strokes.length) { centerOnZip(z); } });
		var sh = document.getElementById('d-shot');
		if (sh) { sh.addEventListener('click', function () { readDetails(f); nd.mode = 'shot'; nd.step = 'form'; nd.error = ''; render(); }); }
		var dp = document.getElementById('d-paper');
		if (dp) { dp.addEventListener('click', function () { readDetails(f); openBatch(); }); }
		var cc = document.getElementById('d-cancel');
		if (cc) { cc.addEventListener('click', function () { resetNewDrop(); S.tab = 'map'; render(); }); }
		document.getElementById('d-go').addEventListener('click', function () {
			readDetails(f);
			if (!nd.strokes.length) { nd.error = 'Draw at least one street first.'; render(); return; }
			nd.error = ''; nd.step = 'busy'; nd.busy = 'Finding the houses along your route…'; render();
			api('find-in-area', { method: 'POST', body: { shapes: nd.strokes.map(function (s) { return { type: s.type, points: s.points, buffer_m: 30 }; }) } }).then(function (r) {
				nd.geo = r; nd.geo.spatial = true;
				if (!r.addresses.length) { nd.error = 'No house addresses there from ' + (C.areaSource || 'the address source') + '. Zoom in and draw along the houses.'; nd.step = 'draw'; render(); return; }
				proposeSelection();
				if (nd.editSel) { // editing: keep the houses that were saved before
					r.addresses.forEach(function (a) { if (nd.editSel[a._k]) { nd.sel[a._k] = true; } });
				}
				nd.step = 'review'; render();
			}).catch(function (e) { nd.error = 'House lookup failed: ' + e.message; nd.step = 'draw'; render(); });
		});
	}
	function locate(cb, onFail) {
		if (!navigator.geolocation) { if (onFail) { onFail('unsupported'); } return; }
		navigator.geolocation.getCurrentPosition(function (pos) { store('loc', 'ok'); cb(pos.coords.latitude, pos.coords.longitude); }, function (err) {
			if (err.code === 1) { store('loc', 'denied'); }
			if (onFail) { onFail(err.code === 1 ? 'denied' : 'unavailable'); }
		}, { timeout: 10000, enableHighAccuracy: true });
	}
	function centerOnZip(zip) {
		api('zip-extent?zip=' + zip).then(function (r) {
			var e = r.envelope;
			if (draw.map) { draw.map.fitBounds([[e.ymin, e.xmin], [e.ymax, e.xmax]]); }
		}).catch(function () {});
	}
	function buildDrawMap() {
		var nd = S.nd, f = nd.form;
		if (draw.map) { killMap(draw.map); }
		draw = { map: null, drawing: false, layers: [], current: null, currentLayer: null };
		var map = L.map('d-map', { tap: false, zoomControl: true });
		draw.map = map;
		tileLayer().addTo(map);
		homeView(map, 10);
		addLocateControl(map, function (lat, lng) { f.lat = lat; f.lng = lng; map.setView([lat, lng], 17); });
		addTextControl(map);
		loadDrops().then(function (r) {
			if (draw.map !== map) { return; } // the screen changed while the clusters were loading
			var pts = paintCoverage(map, r.drops, {});
			// re-draw strokes already made (coming back from review)
			nd.strokes.forEach(function (s) { draw.layers.push(strokeLayer(s).addTo(map)); });
			if (nd.strokes.length) { map.fitBounds(L.latLngBounds([].concat.apply([], nd.strokes.map(function (s) { return s.points; }))).pad(0.3)); }
			else if (f.lat) { map.setView([f.lat, f.lng], 17); }
			else if (f.zip && f.zip.length === 5) { centerOnZip(f.zip); }
			else if (pts.length) { map.fitBounds(L.latLngBounds(pts).pad(0.3)); }
			updateStrokeCount();
		});
		if (!f.lat && !nd.strokes.length) { locate(function (lat, lng) { if (!nd.strokes.length && draw.map) { f.lat = lat; f.lng = lng; draw.map.setView([lat, lng], 17); } }); }
		settle(map);

		var box = map.getContainer();
		function ll(ev) { var r = box.getBoundingClientRect(); return map.containerPointToLatLng([ev.clientX - r.left, ev.clientY - r.top]); }
		box.addEventListener('pointerdown', function (ev) {
			if (!draw.drawing || ev.button > 0) { return; }
			ev.preventDefault(); try { box.setPointerCapture(ev.pointerId); } catch (e) {}
			var p = ll(ev);
			draw.current = [[p.lat, p.lng]];
			draw.currentLayer = L.polyline(draw.current, { color: COLOR_STROKE, weight: 8, opacity: 0.75, lineCap: 'round', lineJoin: 'round' }).addTo(map);
		});
		box.addEventListener('pointermove', function (ev) {
			if (!draw.drawing || !draw.current) { return; }
			ev.preventDefault();
			var p = ll(ev), last = draw.current[draw.current.length - 1];
			if (distM(last, [p.lat, p.lng]) < 2) { return; }
			draw.current.push([p.lat, p.lng]);
			draw.currentLayer.setLatLngs(draw.current);
		});
		function finish(ev) {
			if (!draw.drawing || !draw.current) { return; }
			var pts = draw.current; draw.current = null;
			if (draw.currentLayer) { draw.currentLayer.remove(); draw.currentLayer = null; }
			if (pts.length < 3 || pathLengthM(pts) < 15) { return; }
			var len = pathLengthM(pts);
			var closed = len > 80 && distM(pts[0], pts[pts.length - 1]) < Math.max(25, len * 0.12);
			var stroke = { type: closed ? 'loop' : 'path', points: pts };
			nd.strokes.push(stroke);
			draw.layers.push(strokeLayer(stroke).addTo(map));
			updateStrokeCount();
		}
		box.addEventListener('pointerup', finish);
		box.addEventListener('pointercancel', finish);
	}
	function strokeLayer(s) {
		return s.type === 'loop'
			? L.polygon(s.points, { color: COLOR_STROKE, weight: 4, fillOpacity: 0.15 })
			: L.polyline(s.points, { color: COLOR_STROKE, weight: 8, opacity: 0.75, lineCap: 'round', lineJoin: 'round' });
	}
	function setDrawing(on) {
		draw.drawing = on;
		var map = draw.map; if (!map) { return; }
		var c = map.getContainer();
		if (on) { map.dragging.disable(); map.touchZoom.disable(); map.doubleClickZoom.disable(); map.scrollWheelZoom.disable(); c.classList.add('zdh-drawing'); c.style.touchAction = 'none'; }
		else { map.dragging.enable(); map.touchZoom.enable(); map.doubleClickZoom.enable(); map.scrollWheelZoom.enable(); c.classList.remove('zdh-drawing'); c.style.touchAction = ''; }
		var d = document.getElementById('d-draw'), p = document.getElementById('d-pan'), h = document.getElementById('d-hint'), m = document.getElementById('d-mode');
		if (d) { d.setAttribute('aria-pressed', on ? 'true' : 'false'); }
		if (p) { p.setAttribute('aria-pressed', on ? 'false' : 'true'); }
		if (h) { h.innerHTML = on ? tx('<strong>Drawing:</strong> drag along the streets, or circle the block. Lift your finger between streets.', '<strong>Drawing:</strong> drag along the streets you\'ll walk (lift between streets) or circle a block. Switch back to <strong>Move map</strong> to pan or zoom.') : tx('<strong>Moving:</strong> drag and pinch to zoom in on the block, then tap <strong>Draw route</strong>.', '<strong>Moving the map:</strong> drag to pan and zoom in on the block, then tap <strong>Draw route</strong>.'); }
		if (m) { m.textContent = on ? 'Drawing' : 'Move map'; m.classList.toggle('drawing', on); }
	}
	/* ---------------------------------------------- draft: the route being planned survives a refresh */
	var DRAFT_KEY = 'zdh_draft';
	function saveDraft() {
		var nd = S.nd; if (!nd || nd.mode !== 'map' || nd.quick) { return; }
		if (nd.step === 'done' || (!nd.strokes.length && !nd.editId)) { clearDraft(); return; } // saved already, or nothing drawn
		var d = { ts: Date.now(), step: nd.step === 'review' ? 'review' : 'draw', strokes: nd.strokes, form: nd.form, editId: nd.editId || null, editSel: nd.editSel || null, skipUnits: !!nd.skipUnits, selKeys: nd.step === 'review' && nd.geo ? Object.keys(nd.sel) : null };
		try { localStorage.setItem(DRAFT_KEY, JSON.stringify(d)); } catch (e) {}
	}
	function clearDraft() { try { localStorage.removeItem(DRAFT_KEY); } catch (e) {} }
	function loadDraft() { try { var j = localStorage.getItem(DRAFT_KEY); var d = j ? JSON.parse(j) : null; return d && Date.now() - d.ts < 12 * 3600000 ? d : null; } catch (e) { return null; } }
	function restoreDraft(d, showNow) {
		var nd = S.nd;
		nd.mode = 'map'; nd.strokes = d.strokes || []; nd.editId = d.editId || null; nd.editSel = d.editSel || null; nd.skipUnits = !!d.skipUnits;
		if (d.form) { Object.keys(d.form).forEach(function (k) { nd.form[k] = d.form[k]; }); }
		nd.step = 'draw';
		if (showNow && d.step === 'review' && nd.strokes.length) {
			nd.step = 'busy'; nd.busy = 'Restoring your route…'; render();
			api('find-in-area', { method: 'POST', body: { shapes: nd.strokes.map(function (st) { return { type: st.type, points: st.points, buffer_m: 30 }; }) } }).then(function (r) {
				nd.geo = r; nd.geo.spatial = true; proposeSelection();
				if (d.selKeys) { nd.sel = {}; d.selKeys.forEach(function (k) { nd.sel[k] = true; }); }
				nd.step = 'review'; render(); saveDraft();
			}).catch(function () { nd.step = 'draw'; render(); });
			return;
		}
		if (showNow) { render(); }
		saveDraft();
	}
	function updateStrokeCount() {
		saveDraft();
		var n = S.nd.strokes.length, c = document.getElementById('d-count');
		if (!c) { return; }
		var loops = S.nd.strokes.filter(function (s) { return s.type === 'loop'; }).length;
		c.innerHTML = n ? (n + ' stroke' + (n > 1 ? 's' : '') + (loops ? ' (' + loops + ' circled)' : '') + tx('', ' — tap "Find the houses" when done.')) : tx('Nothing drawn yet.', 'No strokes yet.');
	}

	/* ------------------------------------------- mode B: screenshot + AI */
	function renderForm(main) {
		var nd = S.nd, f = nd.form;
		main.innerHTML =
			'<div class="zdh-card">' +
				'<h2>1. ' + tx('Screenshot or scan', 'Screenshot, photo or scan of the map') + '</h2>' +
				'<p class="zdh-help">Mark the area on a map screenshot (circle it or trace the streets you walked), then add it here. One map per drop reads best — the app lines it up with the street map and picks the houses inside your marking.' + tx('', ' <strong>Old paper route maps</strong> work too: a color scan (JPG, PNG or a PDF — pick the page) of the highlighted sheet.') + '</p>' +
				'<label class="zdh-filebtn"><input type="file" id="zdh-files" accept="image/*,image/heic,image/heif,.heic,.heif,application/pdf,.pdf" multiple> Choose screenshot, photo or PDF</label>' +
				'<div class="zdh-thumbs" id="zdh-thumbs"></div>' +
				'<div id="zdh-pdf"></div>' +
				'<p class="zdh-help zdh-muted" id="zdh-keynote">' + (C.paperKey ? 'Paper maps are read with the team\'s highlighter key (Zorderz → Doorhangers → Paper map key).' : 'Paper maps with more than one highlighter color: put what each color means under Zorderz → Doorhangers → Paper map key, so the reader knows which marks mean delivered.') + '</p>' +
			'</div>' +
			'<div class="zdh-card"><h2>2. Drop details</h2>' + detailsHTML(f, { status: false }) +
				'<p class="zdh-help"><button type="button" class="zdh-linkbtn" id="f-geo">Use my current location as a hint</button> <span id="f-geo-out">' + (f.lat ? 'location captured (' + f.lat.toFixed(4) + ', ' + f.lng.toFixed(4) + ')' : '') + '</span></p>' +
			'</div>' +
			(nd.error ? '<div class="zdh-error">' + esc(nd.error) + '</div>' : '') +
			'<div class="zdh-actions"><button class="zdh-secondary" id="f-back">← Back</button><button class="zdh-primary" id="f-go"' + (C.llmReady ? '' : ' disabled') + '>Upload &amp; analyze →</button></div>' +
			(C.llmReady ? '' : '<p class="zdh-help zdh-warn">Map reading needs the AI key and a vision model on Zorderz → Settings first.</p>');

		var thumbs = document.getElementById('zdh-thumbs');
		function drawThumbs() {
			thumbs.innerHTML = '';
			nd.files.forEach(function (file, i) {
				var t = el('<div class="zdh-thumb"><img alt=""><button type="button" title="Remove">✕</button></div>');
				t.querySelector('img').src = URL.createObjectURL(file);
				t.querySelector('button').addEventListener('click', function () { nd.files.splice(i, 1); drawThumbs(); });
				thumbs.appendChild(t);
			});
		}
		drawThumbs();
		document.getElementById('zdh-files').addEventListener('change', function (e) {
			var list = Array.prototype.slice.call(e.target.files);
			e.target.value = '';
			(function next(i) { // one at a time: an iPhone photo may need converting first
				if (i >= list.length) { drawThumbs(); return; }
				var file = list[i];
				if (/pdf$/i.test(file.type) || /\.pdf$/i.test(file.name)) { pdfPicker(file, function (img) { if (nd.files.length >= 4) { toast('Four images at most per drop.'); return false; } nd.files.push(img); drawThumbs(); return true; }); next(i + 1); return; }
				if (nd.files.length >= 4) { next(i + 1); return; }
				usableImage(file).then(function (img) { nd.files.push(img); }).catch(function (err) { toast('Could not open ' + file.name + ': ' + err.message, 8000); logEv('photo', 'open failed: ' + err.message); }).then(function () { drawThumbs(); next(i + 1); });
			})(0);
		});
		document.getElementById('f-geo').addEventListener('click', function () {
			var out = document.getElementById('f-geo-out');
			if (!navigator.geolocation) { out.textContent = 'not available on this device'; return; }
			out.textContent = 'locating…';
			locate(function (lat, lng) { f.lat = lat; f.lng = lng; out.textContent = 'location captured (' + lat.toFixed(4) + ', ' + lng.toFixed(4) + ')'; });
		});
		document.getElementById('f-back').addEventListener('click', function () { readDetails(f); nd.step = 'choose'; render(); });
		document.getElementById('f-go').addEventListener('click', function () {
			readDetails(f);
			if (!nd.files.length) { nd.error = 'Add a screenshot, photo or scan first.'; render(); return; }
			nd.error = '';
			startAnalysis();
		});
	}

	/* iPhone photos are HEIC. Safari draws them; Chrome and Firefox can't, so there they are converted to JPEG in the
	   page (heic2any 0.0.4, bundled in assets/vendor/heic, loaded only when such a photo is picked). */
	var heicLib = null;
	function isHeic(file) { return /hei[cf]$/i.test(file.type || '') || /\.hei[cf]$/i.test(file.name || ''); }
	function canDecode(file) {
		return new Promise(function (resolve) {
			var u = URL.createObjectURL(file), img = new Image();
			img.onload = function () { URL.revokeObjectURL(u); resolve(img.naturalWidth > 0); };
			img.onerror = function () { URL.revokeObjectURL(u); resolve(false); };
			img.src = u;
		});
	}
	function loadHeic() {
		if (heicLib) { return Promise.resolve(heicLib); }
		if (window.heic2any) { heicLib = window.heic2any; return Promise.resolve(heicLib); }
		if (!C.heicUrl) { return Promise.reject(new Error('this browser cannot open iPhone (HEIC) photos — export it as JPEG')); }
		return new Promise(function (resolve, reject) {
			var sc = document.createElement('script'); sc.src = C.heicUrl; sc.async = true;
			sc.onload = function () { if (window.heic2any) { heicLib = window.heic2any; resolve(heicLib); } else { reject(new Error('the HEIC converter did not load')); } };
			sc.onerror = function () { reject(new Error('the HEIC converter did not load')); };
			document.head.appendChild(sc);
		});
	}
	function usableImage(file) {
		if (!isHeic(file)) { return Promise.resolve(file); }
		return canDecode(file).then(function (ok) {
			if (ok) { return file; }
			logEv('photo', 'converting HEIC ' + Math.round(file.size / 1024) + ' KB');
			return loadHeic().then(function (lib) { return lib({ blob: file, toType: 'image/jpeg', quality: 0.92 }); }).then(function (out) {
				var blob = Array.isArray(out) ? out[0] : out;
				return new File([blob], (file.name || 'photo').replace(/\.[^.]+$/, '') + '.jpg', { type: 'image/jpeg' });
			});
		});
	}

	/* scanned paper route maps arrive as PDFs. pdf.js (Mozilla, the bundle's one copy, loaded only when a
	   PDF is chosen) draws each page; tapping a page adds it as a JPEG, exactly like a screenshot. */
	var pdfLib = null;
	function loadPdfJs() {
		if (pdfLib) { return Promise.resolve(pdfLib); }
		if (!C.pdfjs) { return Promise.reject(new Error('the PDF reader is not available')); }
		return new Promise(function (resolve, reject) {
			var done = false, to = setTimeout(function () { if (!done) { reject(new Error('the PDF reader did not load (old browser?) — save the page as an image instead')); } }, 20000);
			window.addEventListener('zdh-pdfjs', function () { done = true; clearTimeout(to); pdfLib = window.dhPdfjs; try { pdfLib.GlobalWorkerOptions.workerSrc = C.pdfjs.worker; } catch (e) {} resolve(pdfLib); }, { once: true });
			var sc = document.createElement('script'); sc.type = 'module';
			sc.textContent = 'import * as p from ' + JSON.stringify(C.pdfjs.lib) + '; window.dhPdfjs = p; window.dispatchEvent(new Event("zdh-pdfjs"));';
			document.head.appendChild(sc);
		});
	}
	function pdfPageImage(pdf, n, longSide, name) {
		return pdf.getPage(n).then(function (page) {
			var v1 = page.getViewport({ scale: 1 }), scale = longSide / Math.max(v1.width, v1.height), vp = page.getViewport({ scale: scale });
			var c = document.createElement('canvas'); c.width = Math.round(vp.width); c.height = Math.round(vp.height);
			var ctx = c.getContext('2d'); ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, c.width, c.height);
			return page.render({ canvasContext: ctx, viewport: vp }).promise.then(function () {
				return new Promise(function (resolve) { c.toBlob(function (b) { c.width = c.height = 0; resolve(b ? new File([b], name + '-p' + n + '.jpg', { type: 'image/jpeg' }) : null); }, 'image/jpeg', 0.9); }); // width 0 frees the canvas (iOS caps canvas memory)
			});
		});
	}
	function pdfPicker(file, add) {
		var box = document.getElementById('zdh-pdf'); if (!box) { return; }
		var base = file.name.replace(/\.pdf$/i, '').replace(/[^\w\-]+/g, '-').slice(0, 40) || 'scan';
		box.innerHTML = '<div class="zdh-note">Opening ' + esc(file.name) + '…</div>';
		logEv('pdf', file.name + ' ' + Math.round(file.size / 1024) + ' KB');
		loadPdfJs().then(function (lib) {
			return file.arrayBuffer().then(function (buf) { return lib.getDocument({ data: buf }).promise; });
		}).then(function (pdf) {
			var n = Math.min(pdf.numPages, 40);
			box.innerHTML = '<div class="zdh-pdf-head"><strong>' + esc(file.name) + '</strong> — ' + pdf.numPages + ' page' + (pdf.numPages > 1 ? 's' : '') + '. Tap the page with this drop\'s route' + (pdf.numPages > 40 ? ' (first 40 shown)' : '') + '. <button type="button" class="zdh-linkbtn" id="pdf-close">Close</button></div><div class="zdh-pdf-pages" id="pdf-pages"></div>';
			box.querySelector('#pdf-close').addEventListener('click', function () { box.innerHTML = ''; });
			var grid = box.querySelector('#pdf-pages');
			(function thumb(i) {
				if (i > n || !grid.isConnected) { return; }
				pdfPageImage(pdf, i, 360, base).then(function (f) {
					if (!f || !grid.isConnected) { return; }
					var b = el('<button type="button" class="zdh-pdf-page"><img alt=""><span>Page ' + i + '</span></button>');
					b.querySelector('img').src = URL.createObjectURL(f);
					b.addEventListener('click', function () {
						if (b.classList.contains('zdh-pdf-added')) { return; }
						b.disabled = true; b.querySelector('span').textContent = 'Preparing…';
						pdfPageImage(pdf, i, 2600, base).then(function (big) {
							b.disabled = false;
							if (big && add(big)) { b.classList.add('zdh-pdf-added'); b.querySelector('span').textContent = 'Page ' + i + ' ✓ added'; } else { b.querySelector('span').textContent = 'Page ' + i; }
						}).catch(function (e) { b.disabled = false; b.querySelector('span').textContent = 'Page ' + i; toast('Could not read that page: ' + e.message); });
					});
					grid.appendChild(b);
				}).catch(function () {}).then(function () { thumb(i + 1); });
			})(1);
		}).catch(function (e) { box.innerHTML = '<div class="zdh-error">Could not open the PDF: ' + esc(e.message) + '</div>'; logEv('pdf', 'failed: ' + e.message); });
	}

	/* Phone screenshots are 1.5–3 MB PNGs; re-encode big ones as JPEG so they clear typical upload limits and go faster on cellular. */
	/* force: always a JPEG of at most 2400 px, so the server's copy and the mask see one picture */
	function prepareFile(file, force) {
		if (!force && file.size < 1000000 && /image\/(jpeg|png)/.test(file.type)) { return Promise.resolve(file); }
		return new Promise(function (resolve) {
			var img = new Image();
			var u = URL.createObjectURL(file);
			img.onload = function () {
				try {
					var max = 2400, w = img.naturalWidth, h = img.naturalHeight, scale = Math.min(1, max / Math.max(w, h));
					var c = document.createElement('canvas');
					c.width = Math.round(w * scale); c.height = Math.round(h * scale);
					var ctx = c.getContext('2d');
					ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, c.width, c.height);
					ctx.drawImage(img, 0, 0, c.width, c.height);
					c.toBlob(function (blob) {
						URL.revokeObjectURL(u); c.width = c.height = 0;
						if (!blob) { return resolve(file); }
						resolve(new File([blob], file.name.replace(/\.[^.]+$/, '') + '.jpg', { type: 'image/jpeg' }));
					}, 'image/jpeg', 0.92);
				} catch (e) { URL.revokeObjectURL(u); resolve(file); }
			};
			img.onerror = function () { URL.revokeObjectURL(u); resolve(file); };
			img.src = u;
		});
	}

	function setBusy(text) {
		var nd = S.nd;
		nd.busy = text;
		var e = document.querySelector('.zdh-busy-text');
		if (e) { e.textContent = text; } else { render(); }
	}

	/* The reader needs 20–90 s (a photo of a paper sheet can take longer). The server runs it in a background job and the
	   phone polls, so a locked phone or a flaky connection does not lose the answer.
	   a web host can stop the background job once the request that started it has gone, and its gateway can cut off
	   a request that sends nothing for about a minute. So the phone watches the job and switches to the DIRECT read — which
	   the server keeps alive with a space every few seconds — when the job is still queued after 20 s, has had no heartbeat
	   for 30 s (or, with no heartbeats at all, is still running after 170 s), comes back "server stopped", or is lost (404).
	   After the background job has failed once, this phone goes straight to the direct read for 30 days. Every step goes in
	   the event log (Diagnostics), which is how a host's 502 is told apart from a slow reader without access to the host.
	   nd: whose read (a sheet in the old-routes list, or the screen's); say(full, short): progress text. */
	/* this page's background jobs and their last status: WordPress's own cron runs one job at a time, so with two sheets
	   read at once the second is "queued" behind the first for its whole reading — that is not a host stopping it */
	var READ_JOBS = {}; // job → {s: last status, end: when it finished (ms), 0 while it runs}
	function otherJobs(job, fn) { var now = Date.now(); return Object.keys(READ_JOBS).some(function (k) { var j = READ_JOBS[k]; if (j.end && now - j.end > 300000) { delete READ_JOBS[k]; return false; } return k !== job && fn(j, now); }); }
	function analyzeWithPolling(ids, nd, say) {
		nd = nd || S.nd; say = say || setBusy;
		var started = Date.now(), label = 'Reading the map with ' + (C.model || 'the vision model') + '…';
		function direct(why, cancel) {
			logEv('read', 'direct: ' + why);
			say(label + ' (direct — ' + why + ')', 'Reading the map…');
			var t0 = Date.now();
			return api('analyze-image', { method: 'POST', body: { attachment_ids: ids, hints: hints(nd), sync: 1, keepalive: 1, cancel: cancel || '' } }).then(function (r2) {
				if (r2 && r2.error) { var er = new Error((r2.message || 'The map reading failed.') + (r2.secs != null ? ' (after ' + Math.round(r2.secs) + ' s)' : '')); er.code = r2.code; throw er; }
				if (!r2 || !r2.analysis) { throw new Error('Unexpected reply from the server.'); }
				logEv('read', 'direct done in ' + Math.round((Date.now() - t0) / 1000) + ' s' + (r2.secs != null ? ' (reader ' + r2.secs + ' s)' : ''));
				return r2.analysis;
			}, function (e) { logEv('read', 'direct failed after ' + Math.round((Date.now() - t0) / 1000) + ' s: ' + e.message); throw e; });
		}
		var bad = +recall('read_direct') || 0;
		if (bad && Date.now() - bad < 30 * 86400000) { return direct('this site needs it'); }
		function noBackground(why) { store('read_direct', String(Date.now())); return why; }
		return api('analyze-image', { method: 'POST', body: { attachment_ids: ids, hints: hints(nd) } }).then(function (r) {
			if (r.analysis) { logEv('read', 'done inline'); return r.analysis; }
			if (!r.job) { throw new Error('Unexpected reply from the server.'); }
			logEv('read', 'job started');
			return new Promise(function (resolve, reject) {
				var lastStage = '', lastLog = 0, gone = false;
				READ_JOBS[r.job] = { s: 'queued', end: 0 };
				var ended = function () { READ_JOBS[r.job] = { s: 'ended', end: Date.now() }; };
				var done = function (fn) { return function (v) { ended(); fn(v); }; };
				resolve = done(resolve); reject = done(reject);
				function goDirect(why) { if (gone) { return; } gone = true; ended(); direct(why, r.job).then(resolve, reject); }
				function tick() {
					var secs = Math.round((Date.now() - started) / 1000);
					api('analyze-status?job=' + encodeURIComponent(r.job)).then(function (st) {
						if (st.stage && st.stage !== lastStage) { lastStage = st.stage; logEv('read', secs + ' s: ' + st.stage + (st.kb ? ' · ' + st.kb + ' KB' : '') + (st.server ? ' · ' + st.server : '')); }
						else if (secs - lastLog >= 30) { lastLog = secs; logEv('read', secs + ' s: still ' + st.status); }
						say(label + ' (' + secs + ' s' + (st.stage ? ' — ' + st.stage : '') + ')', 'Reading the map' + (st.stage ? ' — ' + st.stage : '') + '…');
						if (st.status === 'done') { logEv('read', 'done in ' + secs + ' s' + (st.reader_s != null ? ' (reader ' + st.reader_s + ' s)' : '')); return resolve(st.analysis); }
						if (st.status === 'error') {
							logEv('read', 'error: ' + st.error);
							if (/server stopped/i.test(st.error || '')) { return goDirect(noBackground('the background reading was stopped')); }
							return reject(new Error(st.error || 'The map reading failed.'));
						}
						READ_JOBS[r.job] = { s: st.status, end: 0 };
						if (st.status === 'queued' && secs > 20) {
							// Behind another of this page's readings: its turn will come. While that one runs, wait (up to two
							// minutes); once it has finished, 20 s more for the next cron run to pick this one up. Going direct
							// after that is not remembered — only a job that never starts on its own says the host stops them.
							var running = otherJobs(r.job, function (j) { return j.s === 'running'; });
							var justDone = otherJobs(r.job, function (j, now) { return j.end && now - j.end < 20000; });
							var lately = otherJobs(r.job, function (j, now) { return j.s === 'running' || (j.end && now - j.end < 120000); });
							if (running) { if (secs > 120) { return goDirect('waited two minutes behind another reading'); } }
							else if (justDone) { /* its turn is next */ }
							else if (lately) { return goDirect('still waiting after another reading'); }
							else { return goDirect(noBackground('the background reading did not start')); }
						}
						if (st.status === 'running' && (st.quiet_s > 30 || st.running_s > 170)) { return goDirect(noBackground('the background reading went quiet')); }
						if (secs > 330) { return reject(new Error('The map reader did not answer in time — try again. (Diagnostics has the details.)')); }
						setTimeout(tick, 3000);
					}).catch(function (e) {
						logEv('read', secs + ' s: status check failed: ' + e.message);
						if (e.status === 404) { return goDirect('the server lost the job'); }
						if (secs > 330) { return reject(e); }
						setTimeout(tick, 4000);
					});
				}
				say(label, 'Reading the map…');
				setTimeout(tick, 2500);
			});
		});
	}

	function startAnalysis() {
		var nd = S.nd;
		nd.step = 'busy'; nd.busy = 'Preparing ' + nd.files.length + ' picture' + (nd.files.length > 1 ? 's' : '') + '…'; render();
		Promise.all(nd.files.map(function (fl) { return prepareFile(fl); })).then(function (files) {
			nd.prepared = files;
			logEv('read', 'uploading ' + files.map(function (fl) { return Math.round(fl.size / 1024) + ' KB'; }).join(', '));
			setBusy('Uploading to the media library…');
			var fd = new FormData();
			files.forEach(function (file) { fd.append('files[]', file, file.name); });
			return api('upload', { method: 'POST', form: fd });
		}).then(function (r) {
			nd.uploaded = r.files;
			setBusy('Saved. Reading the map with ' + (C.model || 'the vision model') + '…');
			return analyzeWithPolling(nd.uploaded.map(function (u) { return u.id; }), nd);
		}).then(function (analysis) {
			applyAnalysis(nd, analysis);
			nd.step = 'streets'; render();
		}).catch(function (e) {
			logEv('read', 'failed: ' + e.message);
			nd.error = 'Analysis failed: ' + e.message + (nd.uploaded.length ? ' (the pictures were saved to the media library — you can retry without re-uploading)' : '');
			nd.step = 'form'; render();
		});
	}
	/* What the reader found, onto a route's state (the screenshot form and every sheet of the old-routes list). */
	function applyAnalysis(nd, a) {
		nd.analysis = a;
		var f = nd.form, P = paperLib(), hn = a.handwritten_notes || {};
		logEv('read', 'read: ' + (a.source_type || '?') + (a.sheet_label ? ' "' + a.sheet_label + '"' : '') + ' · ' + (a.covered_streets || []).length + ' streets · ' + (a.anchors || []).length + ' intersections · ' + (a.marking_color || '?') + (a._secs != null ? ' · ' + a._secs + ' s' : ''));
		nd.streets = (a.covered_streets || []).map(function (s) { return { name: s.name, coverage: s.coverage, from: s.from_cross_street || '', to: s.to_cross_street || '', notes: s.notes || '' }; });
		nd.cross = (a.cross_streets || []).slice();
		nd.maskColor = ['orange', 'red', 'yellow', 'pink', 'green', 'blue'].indexOf(a.marking_color) >= 0 ? a.marking_color : 'orange';
		// the ZIP comes from the map, not from the last route (a sheet once came out under a ZIP remembered from another
		// city, and none of its intersections were found)
		// A screenshot keeps the ZIP typed on the form: the rule is for paper sheets.
		var sheetZip = /paper/i.test(a.source_type || '') || !!nd.batchItem;
		var z = P && sheetZip ? P.zipFor(f.zip, a.zip_guess, a.zip_confidence, !!nd.batchItem) : { zip: f.zip || String(a.zip_guess || '').replace(/\D/g, '').slice(0, 5), note: '' };
		f.zip = z.zip; nd.zipNote = z.note;
		if (a.city_guess && (!f.city || (f.city === C.defaultCity && a.zip_confidence !== 'low'))) { f.city = a.city_guess; } // the default city is only a placeholder
		nd.fromSheet = {};
		if (!f.quantity && hn.quantity) { f.quantity = String(hn.quantity); nd.fromSheet.quantity = 1; }
		if (hn.hanger_type && (C.hangerTypes || []).indexOf(hn.hanger_type) >= 0) { f.hanger_type = hn.hanger_type; nd.fromSheet.type = 1; }
		nd.paper = /paper/i.test(a.source_type || ''); nd.photo = nd.paper && /photo/i.test(a.source_type || ''); // a scan is flat: no perspective
		if (a.sheet_label && !f.name) { f.name = a.sheet_label; }
		if (!f.date && /^\d{4}-\d{2}-\d{2}$/.test(hn.date || '')) { f.date = hn.date; nd.dateFromSheet = true; }
	}

	function markColor(c) { var m = { yellow: '#F5D90A', blue: '#3B82F6', green: '#22A45D', pink: '#EC4899', orange: '#F2812A', red: '#DC2626', purple: '#8B5CF6' }; return m[String(c || '').toLowerCase()] || '#9CA3AF'; }
	function renderStreets(main) {
		var nd = S.nd, a = nd.analysis || {}, f = nd.form;
		var sr = a.screen_readings || {}, hn = a.handwritten_notes || {};
		var readings = [];
		if (sr.temperature_f != null) { readings.push('🌡 ' + sr.temperature_f + '°F on screen'); }
		if (sr.aqi != null) { readings.push('AQI ' + sr.aqi); }
		if (sr.clock) { readings.push('🕘 ' + sr.clock); }
		if (hn.quantity) { readings.push('✍️ quantity ' + hn.quantity); }
		if (hn.hanger_type) { readings.push('✍️ ' + hn.hanger_type); }
		if (nd.dateFromSheet && f.date) { readings.push('✍️ walked ' + f.date + ' (read off the sheet — check it on the next screen)'); }
		var nAnch = (a.anchors || []).length + (a.pin && a.pin.label ? 1 : 0);
		var canGeoref = canGeorefOf(nd), isPaper = /paper/.test(a.source_type || '');
		main.innerHTML =
			'<div class="zdh-card">' +
				'<h2>' + (nd.batchItem ? 'What was read off ' + esc(sheetTitle(nd.batchItem)) : '3. Confirm what the AI read') + '</h2>' +
				'<div class="zdh-ai-summary">' +
					'<div><strong>' + esc(isPaper ? 'Paper map' + (a.sheet_label ? ' “' + a.sheet_label + '”' : '') : (a.map_app || 'Map')) + '</strong> &bull; marking: <strong>' + esc(a.marking_style || '?') + '</strong> &bull; confidence: <strong>' + esc(a.confidence || '?') + '</strong></div>' +
					(a.pin_label ? '<div>📌 ' + esc(a.pin_label) + '</div>' : '') +
					'<div>📍 ' + esc(a.city_guess || '?') + (a.neighborhood_guess ? ' (' + esc(a.neighborhood_guess) + ')' : '') + ' &bull; ZIP guess ' + esc(a.zip_guess || '—') + ' (' + esc(a.zip_confidence || 'low') + ')</div>' +
					(readings.length ? '<div>' + readings.map(esc).join(' &bull; ') + '</div>' : '') +
					(isPaper ? '<div>🗺 Read as a <strong>' + (a.source_type === 'photo of paper map' ? 'photo of a printed map' : 'scanned paper map') + '</strong>' + (C.paperKey ? ' with the team\'s key' : ' (no key set — every highlighted street is counted)') + '.</div>' : '') +
					((a.marks || []).length ? '<div class="zdh-marks">' + a.marks.map(function (m) { return '<div><span class="zdh-mark-sw" style="background:' + esc(markColor(m.color)) + '"></span><strong>' + esc(m.color || 'mark') + '</strong> = ' + esc(m.meaning || 'unknown') + (m.streets && m.streets.length ? ': ' + esc(m.streets.join(', ')) : '') + '</div>'; }).join('') + '</div>' : '') +
					'<div class="zdh-muted">' + (canGeoref ? 'Map alignment: ' + nAnch + ' intersections found on the picture — the houses along your marking will be picked automatically.' : 'Map alignment not available (' + ((nd.files || []).length > 1 ? 'more than one picture' : 'fewer than 2 readable intersections') + ') — the street list below will be used instead.') + '</div>' +
					(a.reasoning ? '<div class="zdh-muted">' + esc(a.reasoning) + '</div>' : '') +
				'</div>' +
				'<div class="zdh-grid">' +
					'<label>ZIP code (used to find the addresses)<input type="text" id="s-zip" inputmode="numeric" maxlength="5" value="' + esc(f.zip) + '"></label>' +
					'<label>City<input type="text" id="s-city" value="' + esc(f.city) + '"></label>' +
					(canGeoref ? '<label>Marking color on the screenshot<select id="s-color">' + ['orange', 'red', 'yellow', 'pink', 'green', 'blue'].map(function (c) { return '<option' + (c === nd.maskColor ? ' selected' : '') + '>' + c + '</option>'; }).join('') + '</select></label>' : '') +
				'</div>' +
				'<h3>Streets covered</h3>' +
				'<p class="zdh-help">Fix any misread names. "Partial" means only the stretch between the two cross streets was covered — you can still fine-tune on the map next.</p>' +
				'<div id="s-list"></div>' +
				'<button type="button" class="zdh-secondary" id="s-add">+ Add a street</button>' +
			'</div>' +
			(nd.error ? '<div class="zdh-error">' + esc(nd.error) + '</div>' : '') +
			'<div class="zdh-actions"><button class="zdh-secondary" id="s-back">← Back</button><button class="zdh-primary" id="s-go">Find addresses →</button></div>';

		var list = document.getElementById('s-list');
		function drawList() {
			list.innerHTML = '';
			if (!nd.streets.length) { list.innerHTML = '<p class="zdh-muted">No streets yet — add one.</p>'; }
			nd.streets.forEach(function (s, i) {
				var row = el('<div class="zdh-street-row">' +
					'<input type="text" class="sr-name" value="' + esc(s.name) + '" placeholder="Street name">' +
					'<button type="button" class="sr-del" title="Remove">✕</button>' +
					'<select class="sr-cov"><option value="full"' + (s.coverage !== 'partial' ? ' selected' : '') + '>Whole street covered</option><option value="partial"' + (s.coverage === 'partial' ? ' selected' : '') + '>Partial — between two cross streets</option></select>' +
					'<div class="sr-bounds"' + (s.coverage === 'partial' ? '' : ' hidden') + '><input type="text" class="sr-from" value="' + esc(s.from) + '" placeholder="from cross street"><span>to</span><input type="text" class="sr-to" value="' + esc(s.to) + '" placeholder="to cross street"></div>' +
					(s.notes ? '<div class="zdh-muted sr-note">' + esc(s.notes) + '</div>' : '') +
				'</div>');
				row.querySelector('.sr-name').addEventListener('input', function (e) { s.name = e.target.value; });
				row.querySelector('.sr-cov').addEventListener('change', function (e) { s.coverage = e.target.value; row.querySelector('.sr-bounds').hidden = s.coverage !== 'partial'; });
				row.querySelector('.sr-from').addEventListener('input', function (e) { s.from = e.target.value; });
				row.querySelector('.sr-to').addEventListener('input', function (e) { s.to = e.target.value; });
				row.querySelector('.sr-del').addEventListener('click', function () { nd.streets.splice(i, 1); drawList(); });
				list.appendChild(row);
			});
		}
		drawList();
		document.getElementById('s-add').addEventListener('click', function () { nd.streets.push({ name: '', coverage: 'full', from: '', to: '', notes: '' }); drawList(); list.lastElementChild.querySelector('.sr-name').focus(); });
		document.getElementById('s-back').addEventListener('click', function () { if (nd.batchItem) { backToBatch(); return; } nd.step = 'form'; render(); });
		document.getElementById('s-go').addEventListener('click', function () {
			f.zip = document.getElementById('s-zip').value.replace(/\D/g, ''); f.city = document.getElementById('s-city').value.trim();
			var ce = document.getElementById('s-color'); if (ce) { nd.maskColor = ce.value; }
			var streets = nd.streets.filter(function (s) { return s.name.trim(); });
			if (!streets.length && !canGeoref) { nd.error = 'Add at least one street.'; render(); return; }
			nd.error = ''; nd.step = 'busy'; nd.busy = 'Finding the houses…'; render();
			findHouses(nd, setBusy).then(function () { nd.step = 'review'; render(); })
				.catch(function (e) { nd.error = 'Address lookup failed: ' + e.message; nd.step = 'streets'; render(); });
		});
	}

	/* the houses for a read picture — lined up on the map where it can be, else by the street list. Works on any
	   route's state (the old-routes list reads several at once); resolves when nd.geo and nd.sel are set and never renders.
	   say(text): progress. */
	function canGeorefOf(nd) {
		var a = nd.analysis || {};
		return (nd.files || []).length === 1 && ((a.anchors || []).length + (a.pin && a.pin.label ? 1 : 0)) >= 2;
	}
	function findHouses(nd, say) {
		say = say || function () {};
		var streets = (nd.streets || []).filter(function (s) { return s.name && s.name.trim(); });
		if (nd.georef && nd.georef.info) { try { URL.revokeObjectURL(nd.georef.info.url); } catch (e) {} nd.georef.info.img = null; } // a re-run lets the last picture go
		nd.georefNote = ''; nd.georefFailed = ''; nd.georef = null; nd.mask = null;
		return (canGeorefOf(nd) ? findByGeoreference(nd, say) : Promise.reject(new Error('skip'))).catch(function (e) {
			if (e && e.message !== 'skip') { nd.georefNote = 'Lining the picture up failed (' + e.message + ') — used the street list instead.'; nd.georefFailed = e.message; logEv('georef', 'failed: ' + e.message); }
			if (!streets.length) { throw (e && e.message !== 'skip') ? e : new Error('no streets were read off the picture'); }
			return findByStreets(streets, nd, say);
		});
	}
	function findByStreets(streets, nd, say) {
		nd = nd || S.nd; say = say || setBusy;
		say('Looking up house addresses on ' + streets.length + ' street' + (streets.length > 1 ? 's' : '') + ' (address map)…');
		return api('find-addresses', { method: 'POST', body: { streets: streets, cross: nd.cross, hints: hints(nd) } }).then(function (r) {
			if (!r.addresses.length && !r.segments.length) {
				throw new Error('no addresses or streets were found for that ZIP and street list — check the street names and ZIP' + (r.notes && r.notes.length ? ' (' + r.notes.join('; ') + ')' : ''));
			}
			nd.geo = r; nd.geo.spatial = false;
			proposeSelection(nd);
		});
	}

	/* ---- georeferencing: anchors (AI) + marking mask (pixels) ---- */
	function loadImageInfo(file) {
		return new Promise(function (resolve, reject) {
			var img = new Image(); var u = URL.createObjectURL(file);
			img.onload = function () { resolve({ img: img, url: u, w: img.naturalWidth, h: img.naturalHeight }); };
			img.onerror = function () { URL.revokeObjectURL(u); reject(new Error('could not read the picture')); };
			img.src = u;
		});
	}
	/* Similarity transform (scale, rotation, translation) from pixel space to local metres; y flipped so north is up. */
	function fitSimilarity(pairs, w, h) {
		var lat0 = 0, lng0 = 0;
		pairs.forEach(function (p) { lat0 += p.lat; lng0 += p.lng; });
		lat0 /= pairs.length; lng0 /= pairs.length;
		var cosL = Math.cos(lat0 * Math.PI / 180);
		var P = pairs.map(function (p) { return [p.x / 1000 * w, -p.y / 1000 * h]; });
		var Q = pairs.map(function (p) { return [(p.lng - lng0) * cosL * 111320, (p.lat - lat0) * 110540]; });
		var pb = [0, 0], qb = [0, 0], n = pairs.length;
		P.forEach(function (p, i) { pb[0] += p[0] / n; pb[1] += p[1] / n; qb[0] += Q[i][0] / n; qb[1] += Q[i][1] / n; });
		var a = 0, b = 0, pp = 0;
		P.forEach(function (p, i) { var px = p[0] - pb[0], py = p[1] - pb[1], qx = Q[i][0] - qb[0], qy = Q[i][1] - qb[1]; a += px * qx + py * qy; b += px * qy - py * qx; pp += px * px + py * py; });
		if (!pp) { return null; }
		var theta = Math.atan2(b, a), s = Math.sqrt(a * a + b * b) / pp;
		var cs = Math.cos(theta), sn = Math.sin(theta);
		var t = [qb[0] - s * (cs * pb[0] - sn * pb[1]), qb[1] - s * (sn * pb[0] + cs * pb[1])];
		function fwd(px, py) { // pixel -> [lat,lng]
			var x = px, y = -py;
			var X = s * (cs * x - sn * y) + t[0], Y = s * (sn * x + cs * y) + t[1];
			return [lat0 + Y / 110540, lng0 + X / (cosL * 111320)];
		}
		function inv(lat, lng) { // [lat,lng] -> pixel
			var X = (lng - lng0) * cosL * 111320 - t[0], Y = (lat - lat0) * 110540 - t[1];
			var x = (cs * X + sn * Y) / s, y = (-sn * X + cs * Y) / s;
			return [x, -y];
		}
		var rms = 0;
		pairs.forEach(function (p, i) { var g = fwd(P[i][0], -P[i][1]); rms += Math.pow(distM(g, [p.lat, p.lng]), 2); });
		rms = Math.sqrt(rms / n);
		return { fwd: fwd, inv: inv, scale: s, rotationDeg: theta * 180 / Math.PI, rms: rms, n: n };
	}
	/* Colour mask of the hand-drawn marking + the region it encloses. Cells: near (line, dilated) and interior (inside a closed loop).
	   The screenshot rule (a circled screenshot means the area inside); a paper sheet uses buildPaperMask() below. */
	function buildMask(info, color) {
		var maxW = 640, scale = Math.min(1, maxW / info.w);
		var w = Math.max(1, Math.round(info.w * scale)), h = Math.max(1, Math.round(info.h * scale));
		var c = document.createElement('canvas'); c.width = w; c.height = h;
		var ctx = c.getContext('2d'); ctx.drawImage(info.img, 0, 0, w, h);
		var d = ctx.getImageData(0, 0, w, h).data;
		var marked = new Uint8Array(w * h);
		var ranges = { orange: [[8, 48], 0.42, 0.5], red: [[340, 372], 0.45, 0.4], yellow: [[45, 72], 0.45, 0.6], pink: [[295, 350], 0.3, 0.55], green: [[85, 165], 0.5, 0.35], blue: [[195, 250], 0.5, 0.35] };
		var rg = ranges[color] || ranges.orange, hlo = rg[0][0], hhi = rg[0][1], smin = rg[1], vmin = rg[2];
		for (var i = 0, n = w * h; i < n; i++) {
			var r = d[i * 4] / 255, g = d[i * 4 + 1] / 255, bl = d[i * 4 + 2] / 255;
			var mx = Math.max(r, g, bl), mn = Math.min(r, g, bl), v = mx, sat = mx ? (mx - mn) / mx : 0;
			if (sat < smin || v < vmin) { continue; }
			var hue = 0, dd = mx - mn;
			if (dd > 0) {
				if (mx === r) { hue = 60 * (((g - bl) / dd) % 6); } else if (mx === g) { hue = 60 * ((bl - r) / dd + 2); } else { hue = 60 * ((r - g) / dd + 4); }
				if (hue < 0) { hue += 360; }
			}
			var h2 = hue < hlo ? hue + 360 : hue; // lets the red range wrap past 360
			if ((hue >= hlo && hue <= hhi) || (h2 >= hlo && h2 <= hhi)) { marked[i] = 1; }
		}
		// dilate the line a little so houses right next to it count (≈1.2% of width)
		var rad = Math.max(2, Math.round(w * 0.012));
		var near = new Uint8Array(w * h);
		for (var y = 0; y < h; y++) {
			for (var x = 0; x < w; x++) {
				if (!marked[y * w + x]) { continue; }
				for (var dy = -rad; dy <= rad; dy++) { var yy = y + dy; if (yy < 0 || yy >= h) { continue; }
					for (var dx = -rad; dx <= rad; dx++) { var xx = x + dx; if (xx < 0 || xx >= w) { continue; } near[yy * w + xx] = 1; } }
			}
		}
		// flood the outside from the border; whatever is neither reachable nor line is enclosed
		var visited = new Uint8Array(w * h), stack = [];
		for (var x2 = 0; x2 < w; x2++) { stack.push(x2); stack.push((h - 1) * w + x2); }
		for (var y2 = 0; y2 < h; y2++) { stack.push(y2 * w); stack.push(y2 * w + w - 1); }
		while (stack.length) {
			var idx = stack.pop();
			if (visited[idx] || near[idx]) { continue; }
			visited[idx] = 1;
			var cx = idx % w, cy = (idx - cx) / w;
			if (cx > 0) { stack.push(idx - 1); } if (cx < w - 1) { stack.push(idx + 1); }
			if (cy > 0) { stack.push(idx - w); } if (cy < h - 1) { stack.push(idx + w); }
		}
		var interior = 0, lineCells = 0;
		for (var k = 0; k < w * h; k++) { if (near[k]) { lineCells++; } else if (!visited[k]) { interior++; } }
		return { w: w, h: h, near: near, visited: visited, scale: scale, lineCells: lineCells, interiorCells: interior,
			hit: function (px, py) { // px,py in original image pixels
				var x = Math.round(px * scale), y = Math.round(py * scale);
				if (x < 0 || y < 0 || x >= w || y >= h) { return false; }
				var i2 = y * w + x;
				return !!near[i2] || !visited[i2];
			} };
	}
	/* the highlighter on a paper sheet (assets/paper.js does the maths): the picture drawn at most 640 px wide, the
	   marked pixels widened to about 22 m on the ground, line only (a ring of highlighted streets is not a filled-in block),
	   solid patches wider than the strokes dropped, and only the sheet counted. The screenshot rule is buildMask() above. */
	function buildPaperMask(info, color, radPx, clip) {
		var P = paperLib(), scale = Math.min(1, 640 / info.w);
		var w = Math.max(1, Math.round(info.w * scale)), h = Math.max(1, Math.round(info.h * scale));
		var c = document.createElement('canvas'); c.width = w; c.height = h;
		var ctx = c.getContext('2d'); ctx.drawImage(info.img, 0, 0, w, h);
		var px = ctx.getImageData(0, 0, w, h).data;
		c.width = c.height = 0; // the pixels are copied: let the canvas go
		return P.buildMask(px, w, h, scale, color, { radPx: radPx, lineOnly: true, clip: clip, patches: true });
	}
	function findByGeoreference(nd, say) {
		nd = nd || S.nd; say = say || setBusy;
		var a = nd.analysis, P = paperLib();
		say('Lining the picture up with the street map…');
		var anchors = (a.anchors || []).slice();
		if (a.pin && a.pin.label && /&/.test(a.pin.label)) {
			var parts = a.pin.label.split('&').map(function (s) { return s.trim(); });
			if (parts.length === 2) { anchors.push({ a: parts[0], b: parts[1], x: a.pin.x, y: a.pin.y }); }
		}
		var info;
		return loadImageInfo((nd.prepared && nd.prepared[0]) || nd.files[0]).then(function (inf) {
			info = inf;
			return api('georeference', { method: 'POST', body: { anchors: anchors, hints: hints(nd) } }).then(function (r) {
				// under a wrong ZIP none of the intersections are found: look region-wide once and say so
				if ((r.anchors || []).length < 2 && nd.form.zip) {
					logEv('georef', 'retry region-wide (' + (r.anchors || []).length + ' found in ' + nd.form.zip + ')');
					var h2 = hints(nd); h2.zip = '';
					return api('georeference', { method: 'POST', body: { anchors: anchors, hints: h2 } }).then(function (r2) {
						if ((r2.anchors || []).length >= 2) { nd.zipNote = 'The intersections were found outside ZIP ' + nd.form.zip + ' — check the ZIP'; return r2; }
						return r;
					});
				}
				return r;
			});
		}).then(function (r) {
			var pairs = r.anchors || [];
			if (pairs.length < 2) { throw new Error('only ' + pairs.length + ' intersection' + (pairs.length === 1 ? '' : 's') + ' could be located'); }
			if (P && nd.paper) {
				// a paper sheet. A photo is seen at an angle: a perspective fit where 5+ intersections back it up, and a
				// misread intersection is left out instead of dragging the whole fit.
				var pf = P.fitPhoto(pairs, info.w, info.h, { perspective: !!nd.photo });
				var ok = P.acceptFit(pf, pairs.length);
				if (!ok.ok) { throw new Error(ok.why); }
				nd.mask = buildPaperMask(info, nd.maskColor, 22 / pf.mPerPx, P.sheetBox(anchors, info.w, info.h));
				if (!nd.mask.lineCells || !nd.mask.markedBox) { throw new Error('no ' + nd.maskColor + ' marking found on the picture'); }
				var mb = nd.mask.markedBox, mc = [pf.fwd(mb[0], mb[1]), pf.fwd(mb[2], mb[1]), pf.fwd(mb[2], mb[3]), pf.fwd(mb[0], mb[3])].filter(Boolean);
				if (mc.length < 4) { throw new Error('the picture could not be placed on the map'); }
				var la = mc.map(function (q) { return q[0]; }), ln = mc.map(function (q) { return q[1]; }), pad = 0.0012;
				var env2 = { xmin: Math.min.apply(null, ln) - pad, ymin: Math.min.apply(null, la) - pad, xmax: Math.max.apply(null, ln) + pad, ymax: Math.max.apply(null, la) + pad };
				nd.georef = { paper: true, fit: pf, info: info, anchors: pairs, envelope: env2,
					loose: P.looseFit(pf) ? { rms: pf.inRms, used: pf.inliers } : null,
					note: 'Lined up with ' + pf.inliers + ' of ' + pairs.length + ' intersections (±' + Math.round(pf.inRms) + ' m, ' + (pf.kind === 'perspective' ? 'corrected for the camera angle' : 'flat map') + ').' };
				logEv('georef', pf.kind + ' ' + pf.inliers + '/' + pairs.length + ' ±' + Math.round(pf.inRms) + ' m, ' + pf.mPerPx.toFixed(2) + ' m/px' + (nd.georef.loose ? ', loose' : ''));
				say('Finding the houses along the highlighted streets…');
				return api('find-in-area', { method: 'POST', body: { envelope: env2 } });
			}
			// a screenshot: the flat fit and the marking rule
			var fit = fitSimilarity(pairs, info.w, info.h);
			if (fit && pairs.length >= 3 && fit.rms > 35) { // drop the worst anchor once if it is clearly off
				var best = fit;
				pairs.forEach(function (_, i) { var sub = pairs.filter(function (_, j) { return j !== i; }); var f2 = fitSimilarity(sub, info.w, info.h); if (f2 && f2.rms < best.rms) { best = f2; } });
				fit = best;
			}
			if (!fit || fit.rms > 90 || fit.scale < 0.15 || fit.scale > 40) { throw new Error('the intersections did not line up (error ' + (fit ? Math.round(fit.rms) : '?') + ' m)'); }
			nd.mask = buildMask(info, nd.maskColor);
			if (!nd.mask.lineCells) { throw new Error('no ' + nd.maskColor + ' marking found on the screenshot'); }
			var c1 = fit.fwd(0, 0), c2 = fit.fwd(info.w, 0), c3 = fit.fwd(info.w, info.h), c4 = fit.fwd(0, info.h);
			var lats = [c1[0], c2[0], c3[0], c4[0]], lngs = [c1[1], c2[1], c3[1], c4[1]];
			var env = { xmin: Math.min.apply(null, lngs), ymin: Math.min.apply(null, lats), xmax: Math.max.apply(null, lngs), ymax: Math.max.apply(null, lats) };
			nd.georef = { fit: fit, info: info, anchors: pairs, envelope: env, note: 'Aligned with ' + fit.n + ' intersections (±' + Math.round(fit.rms) + ' m' + (Math.abs(fit.rotationDeg) > 2 ? ', rotated ' + fit.rotationDeg.toFixed(1) + '°' : '') + ').' };
			say('Pulling every house on the screenshot from the address map…');
			return api('find-in-area', { method: 'POST', body: { envelope: env } });
		}).then(function (r) {
			var fit = nd.georef.fit, mask = nd.mask, n = 0;
			if (nd.georef.paper) {
				// a house counts when it is inside the marking AND its street was named, or the marking covers half its road
				// segment (a court the reader missed); the first houses of a cross street the stroke runs into stay out
				var run = {}, cover = P.segmentCover(r.segments, fit.inv, mask.hit, run), spillM = (mask.radPx + mask.strokePx / 2) * fit.mPerPx + 10; // a corner spill: the widening and half a stroke
				n = P.selectHouses(r.addresses, { inv: fit.inv, hit: mask.hit, paper: true, cover: cover, run: run, spillM: spillM, dead: P.deadEnds(r.segments), named: namedStreets((nd.analysis || {}).covered_streets), coreOf: streetCore });
			} else {
				r.addresses.forEach(function (ad) {
					if (ad.lat == null) { ad.selected = false; return; }
					var p = fit.inv(ad.lat, ad.lng);
					ad.selected = mask.hit(p[0], p[1]);
					if (ad.selected) { n++; }
				});
			}
			if (!n) { throw new Error('no houses fell inside the marking'); }
			r.streets = streetsFromAddresses(r.addresses);
			nd.geo = r; nd.geo.spatial = true; nd.geo.notes = (r.notes || []).concat([nd.georef.note]);
			proposeSelection(nd);
		}).catch(function (e) { // a line-up that failed lets its picture go
			if (info && !(nd.georef && nd.georef.info === info)) { try { URL.revokeObjectURL(info.url); } catch (e2) {} info.img = null; }
			throw e;
		});
	}
	function streetsFromAddresses(addresses) {
		var by = {};
		addresses.forEach(function (a) {
			var k = a.street_key;
			if (!by[k]) { by[k] = { name: a.street, key: k, count: 0, selected: 0, zip: a.zip, city: a.city, coverage: 'partial', from: '', to: '', note: '' }; }
			by[k].count++; if (a.selected) { by[k].selected++; }
		});
		return Object.keys(by).map(function (k) { return by[k]; }).sort(function (x, y) { return (y.selected - x.selected) || x.name.localeCompare(y.name); });
	}

	/* ===================================================== WALKS
	   A cluster bigger than one session is split into walks of about N houses, each a contiguous stretch a
	   person can do on foot, in walking order.
	   'loop'   — one side of the street at a time: walk around the block (right-hand rule over the road graph)
	              and come back to where you started; the houses across the street belong to the next block's loop.
	   'street' — both sides of a street at once (zig-zag), then the nearest unfinished street. */
	var WALK_COLORS = ['#1F77B4', '#2CA02C', '#9467BD', '#E0641C', '#17BECF', '#8C564B', '#D62728', '#BCBD22', '#E377C2', '#7F7F7F'];
	function walkColor(n) { return WALK_COLORS[(n - 1) % WALK_COLORS.length]; }
	function fmtMin(m) { m = Math.round(m); if (m < 60) { return m + ' min'; } var h = Math.floor(m / 60), r = m % 60; return h + ' h' + (r ? ' ' + r + ' min' : ''); }
	function fmtMiles(m) { var mi = m / 1609.34; return mi >= 0.1 ? mi.toFixed(1) + ' mi' : Math.round(m * 3.28084) + ' ft'; }
	/* houses (hangers) per hour of a finished walk — the real pace, next to the 40 an hour the walk planner assumes */
	function paceOf(d) {
		var n = d.quantity != null ? +d.quantity : (d.delivered_count != null ? +d.delivered_count : 0);
		if (!n || !d.duration_min || d.duration_min < 10) { return ''; }
		return Math.round(n / (d.duration_min / 60));
	}
	/* The few lines the walker texts the office after a route ("does 120 seem right?") — one tap instead of typing it. */
	function dropSummaryText(d) {
		var n = d.quantity != null ? d.quantity : (d.delivered_count != null ? d.delivered_count : d.address_count), pc = paceOf(d);
		var lines = [(d.name || d.streets_summary || 'Door hanger walk')];
		lines.push(fmtDate(d.drop_date) + (d.started_at ? ', ' + fmtTime(String(d.started_at).slice(11, 16)) + (d.finished_at ? '–' + fmtTime(String(d.finished_at).slice(11, 16)) : '') : (d.drop_time ? ' at ' + fmtTime(d.drop_time) : '')));
		lines.push(n + ' hangers' + (d.address_count && d.delivered_count != null && d.delivered_count !== d.address_count ? ' (' + d.delivered_count + ' of ' + d.address_count + ' houses)' : '') + (d.duration_min ? ' · ' + fmtMin(d.duration_min) : '') + (d.walked_m ? ' · ' + fmtMiles(d.walked_m) : '') + (pc ? ' · ' + pc + '/hour' : ''));
		if (d.streets_summary) { lines.push(d.streets_summary + (d.city ? ' — ' + d.city : '') + (d.zip ? ' ' + d.zip : '')); }
		var w = (d.enrichment || {}).weather || {};
		if (w.temp_f != null) { lines.push(w.temp_f + '°F' + (w.heat_index_f != null && w.heat_index_f > w.temp_f ? ', felt like ' + w.heat_index_f + '°F' : '') + (w.aqi_us != null ? ' · AQI ' + w.aqi_us : '')); }
		if (d.delivered_by) { lines.push('By ' + d.delivered_by); }
		return lines.join('\n');
	}
	/* Phone share sheet (Messages, Mail…), else copy to the clipboard. */
	function shareText(title, text) {
		logEv('share', title);
		if (navigator.share) { return navigator.share({ title: title, text: text }).catch(function (e) { if (e && e.name === 'AbortError') { return; } copyText(text); }); }
		copyText(text);
	}
	function copyText(text) {
		var done = function () { toast('Copied — paste it into a text or email.'); };
		if (navigator.clipboard && navigator.clipboard.writeText) { navigator.clipboard.writeText(text).then(done, function () { legacyCopy(text) ? done() : toast(text, 20000); }); return; }
		if (legacyCopy(text)) { done(); } else { toast(text, 20000); }
	}
	function legacyCopy(text) { try { var t = document.createElement('textarea'); t.value = text; t.style.position = 'fixed'; t.style.opacity = '0'; document.body.appendChild(t); t.select(); var ok = document.execCommand('copy'); t.remove(); return ok; } catch (e) { return false; } }
	/* the Route Planner (its own app) is one tap away on the Plan tab when this person holds it: the
	   theme lists only the apps a person may open in zdzData.apps, and the Bridge opens it in place. */
	function plannerHeld() {
		try { return !!(window.Bridge && typeof window.Bridge.loadApp === 'function' && window.zdzData && Array.isArray(window.zdzData.apps) && window.zdzData.apps.some(function (a) { return a && a.id === 'route-planner'; })); } catch (e) { return false; }
	}
	/* planWalks and its helpers (projectOnPath, roadGraph, dijkstra, traceFaces) live in assets/walks.js as
	   window.ZDH_WALKS, shared with the Route Planner. Read at call time. If walks.js did not load (a 404,
	   a blocked script) the Plan tab and a chat walk still work as ONE walk in the order given, and the log says so. */
	function planWalks(houses, segments, opts) {
		var W = window.ZDH_WALKS;
		if (W && typeof W.planWalks === 'function') { return W.planWalks(houses, segments, opts); }
		if (!planWalks.warned) { planWalks.warned = true; logEv('walks', 'walks.js missing: one walk in the order given'); } // once
		var perHour = Math.max(5, Math.min(300, +opts.perHour || 40)), keys = [], walkOf = {}, orderOf = {}, sc = {};
		houses.forEach(function (h, i) { keys.push(h.k); walkOf[h.k] = 1; orderOf[h.k] = i; sc[h.street] = (sc[h.street] || 0) + 1; });
		var w = { n: 1, keys: keys, count: keys.length, est_min: Math.round(keys.length / perHour * 60),
			streets: Object.keys(sc).sort(function (a, b) { return sc[b] - sc[a]; }).slice(0, 3),
			path: houses.filter(function (h) { return h.lat != null; }).map(function (h) { return [h.lat, h.lng]; }) };
		return { walks: [w], walkOf: walkOf, orderOf: orderOf, size: Math.max(5, Math.min(500, +opts.size || 60)), style: opts.style === 'street' ? 'street' : 'loop', perHour: perHour };
	}

	/* ---- selection logic (shared by both modes) ---- */
	function addrKey(a) { return a.street_key + '|' + a.number + '|' + (a.unit || ''); }

	function prepareGeo(nd) {
		var g = (nd || S.nd).geo;
		g.segByKey = {}; g.addrByKey = {};
		g.segments.forEach(function (s) { (g.segByKey[s.key] = g.segByKey[s.key] || []).push(s); });
		g.addresses.forEach(function (a) {
			a._k = addrKey(a);
			(g.addrByKey[a.street_key] = g.addrByKey[a.street_key] || []).push(a);
		});
		g.addresses.forEach(function (a) {
			var segs = g.segByKey[a.street_key] || [];
			var found = null;
			if (a.seg) { for (var i = 0; i < segs.length; i++) { if (segs[i].id === a.seg) { found = segs[i]; break; } } }
			if (!found && a.lat != null && segs.length) {
				var best = Infinity;
				segs.forEach(function (s) { var d = distToPathM([a.lat, a.lng], s.path); if (d < best) { best = d; found = s; } });
				if (best > 120) { found = null; }
			}
			a._seg = found ? found.id : null;
		});
	}
	function sharedNodes(segsA, segsB) {
		var nodes = {};
		segsA.forEach(function (s) { if (s.fnode != null) { nodes[s.fnode] = 1; } if (s.tnode != null) { nodes[s.tnode] = 1; } });
		var out = {};
		segsB.forEach(function (s) { if (s.fnode != null && nodes[s.fnode]) { out[s.fnode] = 1; } if (s.tnode != null && nodes[s.tnode]) { out[s.tnode] = 1; } });
		return Object.keys(out).map(Number);
	}
	function segPath(segs, fromNodes, toNodes) {
		var byNode = {};
		segs.forEach(function (s) { [s.fnode, s.tnode].forEach(function (n) { if (n != null) { (byNode[n] = byNode[n] || []).push(s); } }); });
		var target = {}; toNodes.forEach(function (n) { target[n] = 1; });
		var queue = fromNodes.map(function (n) { return { node: n, segs: [] }; });
		var seen = {};
		while (queue.length) {
			var cur = queue.shift();
			if (target[cur.node] && cur.segs.length) { return cur.segs; }
			if (seen[cur.node]) { continue; }
			seen[cur.node] = 1;
			(byNode[cur.node] || []).forEach(function (s) {
				var other = s.fnode === cur.node ? s.tnode : s.fnode;
				if (other != null && !seen[other]) { queue.push({ node: other, segs: cur.segs.concat([s]) }); }
			});
		}
		return null;
	}
	function proposeSelection(nd) {
		nd = nd || S.nd; var g = nd.geo;
		prepareGeo(nd);
		nd.sel = {}; nd.region = null;
		if (g.spatial) {
			g.addresses.forEach(function (a) { if (a.selected && !a.no_solicit && !(nd.skipUnits && a.unit)) { nd.sel[a._k] = true; } });
			g.streets.forEach(function (st) { st.selectedSegs = {}; st.autoNote = ''; });
			return;
		}
		g.streets.forEach(function (st) {
			var addrs = g.addrByKey[st.key] || [];
			var segs = g.segByKey[st.key] || [];
			st.selectedSegs = {};
			if (st.coverage !== 'partial' || !segs.length) {
				addrs.forEach(function (a) { nd.sel[a._k] = true; });
				segs.forEach(function (s) { st.selectedSegs[s.id] = true; });
				st.autoNote = st.coverage === 'partial' ? 'No road segments available — all addresses pre-selected; untick the ones not covered.' : '';
				return;
			}
			var fromSegs = g.segByKey[streetKey(st.from)] || [];
			var toSegs = g.segByKey[streetKey(st.to)] || [];
			var nf = fromSegs.length ? sharedNodes(segs, fromSegs) : [];
			var nt = toSegs.length ? sharedNodes(segs, toSegs) : [];
			var chosen = null;
			if (nf.length && nt.length) { chosen = segPath(segs, nf, nt); }
			if (!chosen && (nf.length || nt.length)) {
				var ns = nf.length ? nf : nt;
				chosen = segs.filter(function (s) { return ns.indexOf(s.fnode) >= 0 || ns.indexOf(s.tnode) >= 0; });
				st.autoNote = 'Only one cross street was found (' + (nf.length ? st.from : st.to) + ') — extend the selection by tapping road segments.';
			}
			if (!chosen) {
				st.autoNote = 'Could not locate the cross streets ' + (st.from || '?') + ' / ' + (st.to || '?') + ' — tap the covered road segments or draw an area.';
				return;
			}
			chosen.forEach(function (s) { st.selectedSegs[s.id] = true; });
			addrs.forEach(function (a) { if (a._seg != null && st.selectedSegs[a._seg]) { nd.sel[a._k] = true; } });
		});
	}

	/* ---- review map ---- */
	var review = { map: null, dots: {}, lines: {}, poly: null, drawing: false, pts: [], ptMarkers: [], overlay: null };

	function renderReview(main) {
		var nd = S.nd, g = nd.geo, f = nd.form;
		var totalSel = Object.keys(nd.sel).length;
		var unitTotal = g.addresses.filter(function (a) { return a.unit; }).length;
		var notes = (g.notes || []).slice(); if (nd.georefNote) { notes.push(nd.georefNote); }
		var qk = nd.quick;
		if (qk) {
			var mins = Math.max(1, Math.round((qk.finished - qk.started) / 60000)), mi = qk.walkM / 1609.34;
			var walkLine = 'Walked ' + (mi >= 0.1 ? mi.toFixed(1) + ' mi' : Math.round(qk.walkM * 3.28) + ' ft') + ' in ' + mins + ' min' + (qk.driveMin ? ' (' + qk.driveMin + ' min driving skipped)' : '') + '.';
			if (qk.gaps && qk.gaps.length) { notes.push('⚠ GPS was off ' + qk.gaps.map(function (g) { return fmtClock(g[0]) + '–' + fmtClock(g[1]); }).join(', ') + ' (phone locked?) — streets walked then are not ticked. ' + (qk.gapHouses ? 'The houses around that stretch are on the map in grey: tap a dot or a road to add them, or use Draw area.' : 'Tap their houses on the map, use a street\'s All button, or use Draw area — it also looks up houses outside the map.')); }
		}
		main.innerHTML =
			'<div class="zdh-card zdh-card-tight">' +
				'<h2>' + (qk ? tx('Houses you passed', 'Confirm the houses you passed') : (nd.mode === 'map' ? 'Confirm the houses' : (nd.batchItem ? 'Check the houses — ' + esc(sheetTitle(nd.batchItem)) : '4. Confirm the houses'))) + '</h2>' +
				'<p class="zdh-help">' + tx('Colored dots = logged. Tap a dot or a road to change it. Missing a street? Draw area around it.', (qk ? 'Green dots get logged as delivered. Tap a dot to add or remove a house, or tap a road to flip that whole stretch. A street you walked that is not on the map at all: <em>Draw area</em> around it and its houses are looked up.' : 'Colored dots are the houses that will be recorded (one color per walk). Tap a dot to toggle one house, tap a road to toggle that whole stretch, or use <em>Draw area</em> and tap around the covered blocks — houses outside the map are looked up too.')) + '</p>' +
				(qk ? '<div class="zdh-note" id="r-walknote">' + esc(walkLine) + ' <span id="r-sidenote">' + esc(sideLine(qk)) + '</span></div>' : '') +
				noteBox(notes) +
				'<div class="zdh-maptools">' +
					(qk && qk.farKeys && qk.farKeys.length ? '<button type="button" class="zdh-tool" id="r-sides" aria-pressed="' + (qk.bothSides ? 'true' : 'false') + '">Both sides of the street</button>' : '') +
					'<button type="button" class="zdh-tool" id="r-draw">Draw area</button>' +
					'<button type="button" class="zdh-tool zdh-tool-on" id="r-finish" hidden>' + ICON_PEN + ' Finish area</button>' +
					'<button type="button" class="zdh-tool" id="r-clear" hidden>Clear area</button>' +
					'<button type="button" class="zdh-tool" id="r-all">Select all</button>' +
					'<button type="button" class="zdh-tool" id="r-none">Clear all</button>' +
					(unitTotal ? '<button type="button" class="zdh-tool" id="r-units" aria-pressed="' + (nd.skipUnits ? 'true' : 'false') + '">Skip apartments</button>' : '') +
					(nd.georef && nd.georef.fit.kind !== 'perspective' && Math.abs(nd.georef.fit.rotationDeg) < 4 ? '<button type="button" class="zdh-tool" id="r-overlay" aria-pressed="false">' + (nd.paper ? 'Picture overlay' : 'Screenshot overlay') + '</button>' : '') +
				'</div>' +
				'<div id="r-map" class="zdh-map"></div>' +
				'<div class="zdh-count"><strong id="r-count">' + totalSel + '</strong> of ' + g.addresses.length + ' ' + tx('selected', 'addresses selected') + '<span id="r-units-note"></span><span id="r-walks-note"></span><span class="zdh-desk"> &bull; source: ' + esc(g.source === 'osm' ? 'OpenStreetMap' : (C.areaSource || 'address map')) + '</span></div>' +
			'</div>' +
			(qk ? '' : '<div class="zdh-card zdh-walks" id="r-walks-card"><h3>Walks</h3>' +
				'<p class="zdh-help">' + tx('Split into sessions of about this many houses, in walking order.', 'The route is split into walks of about this many houses — each a contiguous stretch in walking order, so one walk is one session on foot. Change the number or the style and the split updates.') + '</p>' +
				'<div class="zdh-grid" style="margin-bottom:.5rem"><label>' + tx('Houses per walk', 'Houses per walk (one session)') + '<input type="number" id="w-size" inputmode="numeric" min="5" max="500" value="' + esc(nd.walkOpts.size) + '"></label>' +
				'<label>' + tx('Walk style', 'How each walk goes') + '<select id="w-style"><option value="loop"' + (nd.walkOpts.style === 'loop' ? ' selected' : '') + '>Loop the block — one side, round and back</option><option value="street"' + (nd.walkOpts.style === 'street' ? ' selected' : '') + '>Street by street — both sides, then the next street</option></select></label></div>' +
				'<div id="w-list"></div></div>') +
			'<div class="zdh-card"><h3>Streets</h3><div id="r-streets"></div></div>' +
			(nd.mode === 'map'
				? '<details class="zdh-card zdh-details" id="r-details"' + (recall('details') === 'closed' ? '' : ' open') + '><summary>' + tx('Details', 'Details (name, type, side, deliverer, date, revisit)') + '</summary><div class="zdh-grid" style="margin-bottom:.6rem"><label class="zdh-span2">Cluster name<input type="text" id="f-name" value="' + esc(f.name) + '" placeholder="auto from streets"></label></div>' + detailsHTML(f, { status: false, compact: true }) + '</details>'
				: '<div class="zdh-card"><h3>Drop summary</h3>' + detailsHTML(f, { status: true }) + '</div>') +
			(nd.error ? '<div class="zdh-error">' + esc(nd.error) + '</div>' : '') +
			(qk
				? '<div class="zdh-actions"><button class="zdh-secondary" id="r-back">Discard</button><button class="zdh-primary" id="r-save">' + tx('Save', 'Save as delivered') + ' (<span id="r-count2">' + totalSel + '</span>)</button></div>'
				: nd.mode === 'map'
				? '<div class="zdh-actions"><button class="zdh-secondary" id="r-back">← ' + tx('Back', 'Route') + '</button><button class="zdh-secondary zdh-secondary-solid" id="r-save">' + (nd.editId ? 'Save changes' : 'Save plan') + '</button><button class="zdh-primary" id="r-start">' + (nd.editId ? 'Save &amp; start' : 'Start delivering') + '</button></div><p class="zdh-help" style="text-align:center"><span id="r-count2">' + totalSel + '</span> houses</p>'
				: nd.batchItem
				? '<p class="zdh-help" style="text-align:center"><button type="button" class="zdh-linkbtn" id="r-streetlist">Change the street list or ZIP</button></p><div class="zdh-actions"><button class="zdh-secondary" id="r-back">← All sheets</button><button class="zdh-primary" id="r-save">Save this route (<span id="r-count2">' + totalSel + '</span> houses)</button></div>'
				: '<div class="zdh-actions"><button class="zdh-secondary" id="r-back">← Back</button><button class="zdh-primary" id="r-save">' + (f.status === 'planned' ? 'Save plan' : 'Save drop') + ' (<span id="r-count2">' + totalSel + '</span> addresses)</button></div>');

		bindStatusToggle(f, function () { var b = document.getElementById('r-save'); if (b && nd.mode !== 'map' && !nd.batchItem) { b.firstChild.textContent = (f.status === 'planned' ? 'Save plan' : 'Save drop') + ' ('; } });
		var rd = document.getElementById('r-details'); if (rd) { rd.addEventListener('toggle', function () { store('details', rd.open ? 'open' : 'closed'); }); }
		buildReviewMap();
		drawStreetList();
		document.getElementById('r-back').addEventListener('click', function () {
			if (nd.quick) { askSheet({ title: 'Discard this walk?', html: 'Nothing will be logged for it.', primary: 'Discard the walk', danger: true, onPrimary: function () { var lid = nd.quick.id; saveQuick(null); paceReset(); resetNewDrop(); if (lid) { discardLiveQuick(lid); S.drops = null; } logEv('quick.discard', 'review' + (lid ? ' #' + lid : '')); render(); toast('Walk discarded.'); }, secondary: 'Keep it' }); return; }
			readDetails(f);
			if (nd.batchItem) { markOwn(nd); backToBatch(); return; } // Fix -> back to the sheets, details changed there kept
			nd.step = nd.mode === 'map' ? 'draw' : 'streets'; render();
		});
		var rsl = document.getElementById('r-streetlist');
		if (rsl) { rsl.addEventListener('click', function () { readDetails(f); markOwn(nd); nd.step = 'streets'; render(); }); }
		var rs = document.getElementById('r-start');
		if (rs) { rs.addEventListener('click', function () { readDetails(f); primeLocation(); saveDrop(true); }); }
		document.getElementById('r-all').addEventListener('click', function () { g.addresses.forEach(function (a) { if (!(nd.skipUnits && a.unit)) { nd.sel[a._k] = true; } }); refreshSelection(); });
		document.getElementById('r-none').addEventListener('click', function () { nd.sel = {}; refreshSelection(); });
		var rs = document.getElementById('r-sides');
		if (rs) {
			rs.addEventListener('click', function () {
				nd.quick.bothSides = !nd.quick.bothSides;
				store('quick_sides', nd.quick.bothSides ? 'both' : 'walked');
				quickSides(nd);
				rs.setAttribute('aria-pressed', nd.quick.bothSides ? 'true' : 'false');
				var sn = document.getElementById('r-sidenote'); if (sn) { sn.textContent = sideLine(nd.quick); }
				refreshSelection();
				toast(nd.quick.bothSides ? 'Both sides kept — ' + nd.quick.farKeys.length + ' houses across the street added back.' : 'Only the side you walked — ' + nd.quick.farKeys.length + ' houses across the street left out.');
			});
		}
		var ru = document.getElementById('r-units');
		if (ru) {
			ru.addEventListener('click', function () {
				nd.skipUnits = !nd.skipUnits;
				g.addresses.forEach(function (a) {
					if (!a.unit) { return; }
					if (nd.skipUnits) { delete nd.sel[a._k]; }
					else if (a.selected || !g.spatial) { nd.sel[a._k] = true; } // back to what the drawing covered
				});
				store('skipunits', nd.skipUnits ? '1' : '0');
				ru.setAttribute('aria-pressed', nd.skipUnits ? 'true' : 'false');
				refreshSelection();
			});
		}
		document.getElementById('r-draw').addEventListener('click', function () { startDraw(); });
		document.getElementById('r-finish').addEventListener('click', function () { finishDraw(); });
		document.getElementById('r-clear').addEventListener('click', function () { clearDraw(); });
		var ov = document.getElementById('r-overlay');
		if (ov) { ov.addEventListener('click', function () { toggleOverlay(ov); }); }
		document.getElementById('r-save').addEventListener('click', function () { readDetails(f); if (nd.batchItem) { markOwn(nd); } saveDrop(false); });
		var ws = document.getElementById('w-size'), wst = document.getElementById('w-style');
		if (ws) { ws.addEventListener('change', function () { var v = Math.max(5, Math.min(500, parseInt(ws.value, 10) || 60)); ws.value = v; nd.walkOpts.size = v; store('walk_size', String(v)); refreshSelection(); }); }
		if (wst) { wst.addEventListener('change', function () { nd.walkOpts.style = wst.value === 'street' ? 'street' : 'loop'; store('route_style', nd.walkOpts.style); refreshSelection(); }); }
	}
	/* colour the selected houses by walk, draw each walk's path and list the walks */
	function paintWalks() {
		var nd = S.nd, g = nd.geo, W = nd.walks, list = document.getElementById('w-list');
		(review.walkLines || []).forEach(function (l) { l.remove(); }); review.walkLines = [];
		if (!W || !list) { if (list) { list.innerHTML = '<p class="zdh-help">Select some houses first.</p>'; } return; }
		var many = W.walks.length > 1;
		W.walks.forEach(function (w) {
			if (w.path.length > 1 && review.map) { review.walkLines.push(L.polyline(w.path, { color: walkColor(w.n), weight: 3, opacity: 0.55, dashArray: '2 6', lineCap: 'round', interactive: false }).addTo(review.map)); }
			if (w.path.length && review.map) { review.walkLines.push(L.circleMarker(w.path[0], { radius: 9, color: '#fff', weight: 2, fillColor: walkColor(w.n), fillOpacity: 1, interactive: false }).bindTooltip('Walk ' + w.n + ' starts here', { permanent: false }).addTo(review.map)); }
		});
		var totalMin = W.walks.reduce(function (s, w) { return s + w.est_min; }, 0);
		var wn = document.getElementById('r-walks-note'); if (wn) { wn.textContent = many ? ' · ' + W.walks.length + ' walks' : ''; }
		list.innerHTML = '<p class="zdh-walks-sum"><strong>' + W.walks.length + ' walk' + (many ? 's' : '') + '</strong> &bull; ' + esc(fmtMin(totalMin)) + ' on foot at ' + esc(W.perHour) + ' houses/hour' + (many ? '' : tx('', ' — everything fits in one session')) + '</p>' +
			W.walks.map(function (w) { return '<div class="zdh-walk-row"><span class="zdh-walk-swatch" style="background:' + walkColor(w.n) + '"></span><span class="zdh-walk-main"><strong>Walk ' + w.n + '</strong> &bull; ' + w.count + ' houses &bull; ≈ ' + esc(fmtMin(w.est_min)) + '<br><span class="zdh-walk-streets">' + esc(w.streets.join(', ')) + '</span></span></div>'; }).join('');
	}

	function buildReviewMap() {
		var nd = S.nd, g = nd.geo;
		if (review.map) { killMap(review.map); }
		review = { map: null, dots: {}, lines: {}, poly: null, drawing: false, pts: [], ptMarkers: [], overlay: null };
		var map = L.map('r-map', { tap: false });
		review.map = map;
		tileLayer().addTo(map);
		addTextControl(map);
		var bounds = [];
		var colorByKey = {};
		var covered = g.streets.filter(function (s) { return g.spatial ? s.selected > 0 : true; });
		covered.forEach(function (s, i) { colorByKey[s.key] = PALETTE[i % PALETTE.length]; });
		// strokes / marking drawn under everything
		(nd.strokes || []).forEach(function (s) { strokeLayer(s).addTo(map); s.points.forEach(function (p) { bounds.push(p); }); });
		g.segments.forEach(function (seg) {
			var isCovered = !!colorByKey[seg.key];
			var line = L.polyline(seg.path, { color: isCovered ? colorByKey[seg.key] : '#95a5a6', weight: isCovered ? 6 : 3, opacity: isCovered ? 0.9 : 0.5, dashArray: isCovered ? null : '4 6', lineCap: 'round' }).addTo(map);
			line.bindTooltip(seg.name + (seg.low ? ' (' + seg.low + '–' + seg.high + ')' : ''), { sticky: true });
			line._seg = seg; line._covered = isCovered; line._color = colorByKey[seg.key];
			line.on('click', function (e) { L.DomEvent.stop(e); if (review.drawing) { addDrawPoint(e.latlng); return; } toggleSegment(seg); });
			review.lines[seg.id] = line;
			// zoom to what was drawn (a long road segment would otherwise drag the view out to the whole street)
			if (isCovered && !(nd.strokes || []).length) { seg.path.forEach(function (p) { bounds.push(p); }); }
		});
		g.addresses.forEach(function (a) {
			if (a.lat == null) { return; }
			var dot = L.circleMarker([a.lat, a.lng], { radius: 6, weight: 1.5, color: '#fff', fillOpacity: 0.95 }).addTo(map);
			var segForLabel = a._seg != null ? (g.segByKey[a.street_key] || []).filter(function (s2) { return s2.id === a._seg; })[0] : null;
			numberLabel(dot, a.number + (a.unit ? '#' + a.unit : ''), labelSide([a.lat, a.lng], segForLabel ? segForLabel.path : null));
			dot.on('click', function (e) { L.DomEvent.stop(e); if (review.drawing) { addDrawPoint(e.latlng); return; } if (a.no_solicit) { toast('No Soliciting sign noted at ' + a.number + ' ' + a.street + ' — left off the route.', 5000); return; } nd.sel[a._k] ? delete nd.sel[a._k] : (nd.sel[a._k] = true); refreshSelection(); });
			if (a.no_solicit) { dot.bindTooltip('No Soliciting — left off', { direction: 'top' }); dot._nosol = true; }
			review.dots[a._k] = dot;
			if (nd.sel[a._k] || !g.spatial) { bounds.push([a.lat, a.lng]); }
		});
		map.on('click', function (e) { if (review.drawing) { addDrawPoint(e.latlng); } });
		if (bounds.length) { map.fitBounds(L.latLngBounds(bounds).pad(0.12)); } else { homeView(map, 12); }
		settle(map);
		refreshSelection();
	}
	function toggleOverlay(btn) {
		var nd = S.nd;
		if (review.overlay) { review.overlay.remove(); review.overlay = null; btn.setAttribute('aria-pressed', 'false'); return; }
		var fit = nd.georef.fit, info = nd.georef.info;
		var sw = fit.fwd(0, info.h), ne = fit.fwd(info.w, 0);
		review.overlay = L.imageOverlay(info.url, [[sw[0], sw[1]], [ne[0], ne[1]]], { opacity: 0.55, interactive: false }).addTo(review.map);
		btn.setAttribute('aria-pressed', 'true');
	}
	function toggleSegment(seg) {
		var nd = S.nd, g = nd.geo;
		var addrs = (g.addrByKey[seg.key] || []).filter(function (a) { return a._seg === seg.id; });
		if (!addrs.length) { addrs = (g.addrByKey[seg.key] || []).filter(function (a) { return a.lat != null && distToPathM([a.lat, a.lng], seg.path) < 45; }); }
		if (!addrs.length) { return; }
		var allOn = addrs.every(function (a) { return nd.sel[a._k]; });
		addrs.forEach(function (a) { if (allOn) { delete nd.sel[a._k]; } else { nd.sel[a._k] = true; } });
		refreshSelection();
	}
	function refreshSelection() {
		var nd = S.nd, g = nd.geo;
		var n = 0;
		computeWalks();
		var W = nd.walks, many = !!(W && W.walks.length > 1);
		g.addresses.forEach(function (a) {
			var on = !!nd.sel[a._k];
			if (on) { n++; }
			var d = review.dots[a._k];
			if (d && d._nosol) { d.setStyle({ fillColor: '#111', color: '#c0392b', weight: 3, radius: 6, fillOpacity: 1 }); return; }
			if (d) { d.setStyle({ fillColor: on ? (many && W.walkOf[a._k] ? walkColor(W.walkOf[a._k]) : '#27ae60') : '#b2bec3', radius: on ? 6 : 5, fillOpacity: on ? 0.95 : 0.6 }); }
		});
		paintWalks();
		Object.keys(review.lines).forEach(function (id) {
			var line = review.lines[id], seg = line._seg;
			if (!line._covered) { return; }
			var addrs = (g.addrByKey[seg.key] || []).filter(function (a) { return a._seg === seg.id; });
			var on = addrs.length ? addrs.filter(function (a) { return nd.sel[a._k]; }).length / addrs.length : 0;
			line.setStyle({ color: on > 0.5 ? line._color : '#7f8c8d', opacity: on > 0.5 ? 0.9 : 0.45, dashArray: on > 0.5 ? null : '6 6' });
		});
		saveDraft();
		var c1 = document.getElementById('r-count'), c2 = document.getElementById('r-count2'), un = document.getElementById('r-units-note');
		if (c1) { c1.textContent = n; }
		if (c2) { c2.textContent = n; }
		if (un) {
			var units = g.addresses.filter(function (a) { return a.unit && nd.sel[a._k]; }).length;
			un.innerHTML = units ? tx(' · ' + units + ' units', ' (' + units + ' of them apartment/condo units)') : '';
		}
		drawStreetList();
	}
	function drawStreetList() {
		var nd = S.nd, g = nd.geo, box = document.getElementById('r-streets');
		if (!box) { return; }
		box.innerHTML = '';
		var streets = g.streets.slice();
		if (g.spatial) { streets.sort(function (x, y) { var sx = (g.addrByKey[x.key] || []).filter(function (a) { return nd.sel[a._k]; }).length, sy = (g.addrByKey[y.key] || []).filter(function (a) { return nd.sel[a._k]; }).length; return (sy - sx) || x.name.localeCompare(y.name); }); }
		var shown = 0;
		streets.forEach(function (st, i) {
			var addrs = g.addrByKey[st.key] || [];
			var on = addrs.filter(function (a) { return nd.sel[a._k]; }).length;
			if (g.spatial && !on && shown >= 12) { return; } // keep the list short: unselected streets after the first dozen are still on the map
			shown++;
			var nums = addrs.filter(function (a) { return nd.sel[a._k]; }).map(function (a) { return parseInt(a.number, 10); }).filter(function (x) { return x > 0; });
			var range = nums.length ? Math.min.apply(null, nums) + '–' + Math.max.apply(null, nums) : '';
			var unitsOn = addrs.filter(function (a) { return a.unit && nd.sel[a._k]; }).length;
			var cov = g.spatial ? (on === addrs.length ? 'full' : (on ? 'partial' : 'none')) : st.coverage;
			var row = el('<div class="zdh-streetline' + (on ? '' : ' zdh-streetline-off') + '"><span class="zdh-swatch" style="background:' + (on ? PALETTE[i % PALETTE.length] : '#cfd3dc') + '"></span>' +
				'<div class="zdh-streetline-main"><strong>' + esc(st.name) + '</strong> <span class="zdh-badge-soft">' + esc(cov) + '</span>' +
				'<div class="zdh-muted">' + on + ' of ' + addrs.length + ' addresses' + (range ? ' &bull; ' + range : '') + (unitsOn ? ' &bull; ' + unitsOn + ' unit' + (unitsOn > 1 ? 's' : '') + ' in complexes' : '') + (!g.spatial && st.coverage === 'partial' && (st.from || st.to) ? ' &bull; ' + esc(st.from || '?') + ' → ' + esc(st.to || '?') : '') + '</div>' +
				(st.note ? '<div class="zdh-muted">' + esc(st.note) + '</div>' : '') + (st.autoNote ? '<div class="zdh-muted zdh-warn">' + esc(st.autoNote) + '</div>' : '') +
				(!addrs.length ? '<div class="zdh-muted zdh-warn">No address points found for this street — it will still be recorded as covered by name.</div>' : '') + '</div>' +
				(addrs.length ? '<div class="zdh-streetline-btns"><button type="button" class="zdh-mini zdh-checkbtn" data-a="all" aria-pressed="' + (on === addrs.length ? 'true' : (on ? 'mixed' : 'false')) + '" title="Tap to select or clear every house on this street">All</button></div>' : '') + '</div>');
			var tb = row.querySelector('[data-a="all"]');
			if (tb) { tb.addEventListener('click', function () { // all → none, anything else → all
				if (on === addrs.length) { addrs.forEach(function (a) { delete nd.sel[a._k]; }); }
				else { addrs.forEach(function (a) { if (!(nd.skipUnits && a.unit)) { nd.sel[a._k] = true; } }); }
				refreshSelection();
			}); }
			box.appendChild(row);
		});
	}
	function startDraw() {
		clearDraw();
		review.drawing = true;
		document.getElementById('r-draw').hidden = true; document.getElementById('r-finish').hidden = false; document.getElementById('r-clear').hidden = false;
		document.getElementById('r-map').classList.add('zdh-drawing');
	}
	function addDrawPoint(latlng) {
		review.pts.push([latlng.lat, latlng.lng]);
		var m = L.circleMarker(latlng, { radius: 5, color: '#e67e22', fillColor: '#fff', fillOpacity: 1, weight: 2 }).addTo(review.map);
		review.ptMarkers.push(m);
		if (review.poly) { review.poly.setLatLngs(review.pts); } else { review.poly = L.polygon(review.pts, { color: '#e67e22', weight: 3, fillOpacity: 0.12, dashArray: '6 4' }).addTo(review.map); }
	}
	function finishDraw() {
		var nd = S.nd, g = nd.geo;
		review.drawing = false;
		document.getElementById('r-draw').hidden = false; document.getElementById('r-finish').hidden = true;
		document.getElementById('r-map').classList.remove('zdh-drawing');
		if (review.pts.length >= 3) {
			g.addresses.forEach(function (a) { if (a.lat != null && pointInPoly([a.lat, a.lng], review.pts)) { nd.sel[a._k] = true; } });
			nd.region = review.pts.slice();
			if (review.poly) { review.poly.setStyle({ dashArray: null }); }
			refreshSelection();
			if (g.spatial) { lookUpMoreInArea(review.pts.slice()); }
		} else { clearDraw(); }
	}
	/* the review map holds the houses near the route (or the GPS track). A drawn area also asks the address map
	   for the houses inside it, so a street walked with the GPS off — or left off the plan — can be added even when
	   none of its houses were on the map. */
	function lookUpMoreInArea(poly) {
		var nd = S.nd, g = nd.geo;
		toast('Looking up the houses in that area…', 4000);
		api('find-in-area', { method: 'POST', body: { shapes: [{ type: 'loop', points: poly }] } }).then(function (r) {
			if (S.nd !== nd || nd.step !== 'review') { return; }
			var have = {}; g.addresses.forEach(function (a) { have[addrKey(a)] = true; });
			var segHave = {}; g.segments.forEach(function (sg) { segHave[sg.id] = true; });
			var added = 0;
			r.addresses.forEach(function (a) {
				var k = addrKey(a); if (have[k]) { return; }
				have[k] = true; g.addresses.push(a); added++;
				if (a.selected && !(nd.skipUnits && a.unit)) { nd.sel[k] = true; }
			});
			r.segments.forEach(function (sg) { if (!segHave[sg.id]) { segHave[sg.id] = true; g.segments.push(sg); } });
			logEv('review.area', added + ' more houses');
			if (!added) { toast('No other houses in that area — the ones inside it are ticked.', 5000); return; }
			prepareGeo();
			g.streets = streetsFromAddresses(g.addresses.map(function (a) { return { street: a.street, street_key: a.street_key, zip: a.zip, city: a.city, selected: !!nd.sel[a._k] }; }));
			var view = review.map ? { c: review.map.getCenter(), z: review.map.getZoom() } : null;
			render();
			if (view && review.map) { review.map.setView(view.c, view.z, { animate: false }); }
			toast('Added ' + added + ' house' + (added > 1 ? 's' : '') + ' from the area you drew.', 5000);
		}).catch(function (e) { toast('Could not look up that area: ' + e.message, 7000); });
	}
	function clearDraw() {
		review.drawing = false; review.pts = []; S.nd.region = null;
		review.ptMarkers.forEach(function (m) { m.remove(); }); review.ptMarkers = [];
		if (review.poly) { review.poly.remove(); review.poly = null; }
		var d = document.getElementById('r-draw'), fi = document.getElementById('r-finish'), cl = document.getElementById('r-clear'), mp = document.getElementById('r-map');
		if (d) { d.hidden = false; } if (fi) { fi.hidden = true; } if (cl) { cl.hidden = true; } if (mp) { mp.classList.remove('zdh-drawing'); }
	}

	function autoName(f, names) {
		var core = names.slice(0, 2).join(' & ') + (names.length > 2 ? ' +' + (names.length - 2) : '');
		return ((f.city ? f.city + ' ' : '') + (f.zip ? f.zip + ' — ' : '— ') + core).trim();
	}
	/* a route's parts (houses, streets, segments, walks, status) and its saved form, from any route's state — the
	   screen's, or a sheet of the old-routes list being saved straight from its card. */
	function routeParts(nd, startNow) {
		var g = nd.geo, f = nd.form, W = nd.walks;
		var addresses = g.addresses.filter(function (x) { return nd.sel[x._k]; }).map(function (x) {
			return { number: x.number, unit: x.unit || '', street: x.street, city: x.city || f.city, state: C.defaultState || '', zip: x.zip || g.zip || f.zip, lat: x.lat, lng: x.lng, source: x.source || 'region', confidence: x.confidence === 'estimated' ? 'estimated' : 'verified', seg: x._seg, apn: x.apn || '', walk: W && W.walkOf[x._k] ? W.walkOf[x._k] : 1, walk_order: W && W.orderOf[x._k] != null ? W.orderOf[x._k] : 0 };
		});
		var walksMeta = W ? { size: W.size, style: W.style, per_hour: W.perHour, list: W.walks.map(function (w) { return { n: w.n, count: w.count, est_min: w.est_min, streets: w.streets.join(', '), status: 'planned' }; }) } : null;
		var streets = g.streets.map(function (st) {
			var addrs = g.addrByKey[st.key] || [];
			var on = addrs.filter(function (x) { return nd.sel[x._k]; });
			var nums = on.map(function (x) { return parseInt(x.number, 10); }).filter(function (n) { return n > 0; });
			var full = addrs.length ? on.length === addrs.length : (!g.spatial && st.coverage !== 'partial');
			return { street: st.name, city: (on[0] && on[0].city) || f.city, zip: (on[0] && on[0].zip) || g.zip || f.zip, coverage: full ? 'full' : 'partial', low: nums.length ? Math.min.apply(null, nums) : null, high: nums.length ? Math.max.apply(null, nums) : null, count: on.length };
		}).filter(function (st) { return st.count > 0 || (!g.spatial && st.coverage === 'full'); });
		var empty = !addresses.length && !streets.length;
		var selSegs = {};
		addresses.forEach(function (x) { if (x.seg != null) { selSegs[x.seg] = true; } });
		var segments = g.segments.filter(function (s) { return selSegs[s.id]; }).map(function (s) { return { id: s.id, name: s.name, path: s.path }; });
		if (!g.spatial) { g.streets.forEach(function (st) { if (!(g.addrByKey[st.key] || []).length) { (g.segByKey[st.key] || []).forEach(function (s) { segments.push({ id: s.id, name: s.name, path: s.path }); }); } }); }
		var names = streets.map(function (st) { return st.street; });
		if (g.spatial && addresses.length) { // the address map's community name of most of the houses, not the default city
			var cc = {}, topC = '', topN = 0;
			addresses.forEach(function (x) { if (x.city) { cc[x.city] = (cc[x.city] || 0) + 1; if (cc[x.city] > topN) { topN = cc[x.city]; topC = x.city; } } });
			if (topC && (nd.quick || !f.city || f.city === C.defaultCity)) { f.city = topC; }
		}
		var status = nd.mode === 'map' ? (startNow ? 'planned' : f.status) : 'done';
		if (nd.mode === 'map' && !startNow) { status = 'planned'; }
		if (nd.quick) { status = 'done'; }
		return { empty: empty, addresses: addresses, streets: streets, segments: segments, names: names, summary: names.join(' / '), geometry: { segments: segments, region: nd.region, strokes: nd.strokes || [] }, status: status, walksMeta: walksMeta };
	}
	function dropPayload(nd, rp) {
		var g = nd.geo, f = nd.form, a = nd.analysis || {}, names = rp.names, geometry = rp.geometry;
		// the picture as a map overlay — only for a flat fit (a photo taken at an angle is not a rectangle on the ground) that
		// is square to the map (a sheet photographed turned on the table is not north-up either)
		if (nd.georef && nd.georef.fit.kind !== 'perspective' && (!nd.paper || !(Math.abs(nd.georef.fit.rotationDeg || 0) > 2))) { /* a screenshot */ var fit = nd.georef.fit, info = nd.georef.info, sw = fit.fwd(0, info.h), ne = fit.fwd(info.w, 0); geometry.overlay = { attachment_id: nd.uploaded[0] ? nd.uploaded[0].id : 0, bounds: [[sw[0], sw[1]], [ne[0], ne[1]]] }; }
		var paper = nd.mode === 'shot' && nd.paper;
		return {
			drop: {
				// an old sheet's time of day is not known: none is made up
				drop_date: f.date, drop_time: paper && nd.batchItem ? '' : f.time, delivered_by: f.delivered_by, hanger_type: f.hanger_type, hanger_side: f.hanger_side, service: C.service || '',
				quantity: f.quantity === '' ? null : +f.quantity, city: f.city, state: C.defaultState || '', zip: g.zip || f.zip, county: C.county || '',
				streets_summary: rp.summary.slice(0, 255), description: (names.length ? 'Residential streets near ' + (a.pin_label || names.slice(0, 2).join(' & ')) : 'Walk with no mapped houses on the streets walked') + (f.city ? ', ' + f.city : ''),
				notes: f.notes + ((a.marks || []).length && !nd.quick ? (f.notes ? ' ' : '') + '[Map marks: ' + a.marks.map(function (m) { return (m.color || 'mark') + ' = ' + (m.meaning || '?') + (m.streets && m.streets.length ? ' (' + m.streets.slice(0, 8).join(', ') + ')' : ''); }).join('; ') + ']' : ''), lat: g.center ? g.center[0] : null, lng: g.center ? g.center[1] : null,
				attachment_ids: nd.uploaded.map(function (u) { return u.id; }),
				status: rp.status, revisit_days: f.revisit_days === '' ? null : +f.revisit_days, method: nd.quick ? 'quick' : (nd.mode === 'map' ? 'map' : (paper ? 'paper' : 'screenshot')),
				name: f.name || (nd.quick && !names.length ? 'Quick walk — ' + fmtDate(localDT(nd.quick.started).slice(0, 10), true) + ' ' + fmtClock(nd.quick.started) + ' (no houses)' : autoName(f, names))
			},
			analysis: a, geometry: geometry, addresses: rp.addresses, streets: rp.streets, walks: rp.walksMeta
		};
	}
	function saveDrop(startNow) {
		var nd = S.nd, f = nd.form;
		var rp = routeParts(nd, startNow);
		var addresses = rp.addresses, streets = rp.streets, names = rp.names, summary = rp.summary, geometry = rp.geometry, walksMeta = rp.walksMeta;
		if (rp.empty) {
			// a Quick Start walk that passed no mapped houses (a mall, a commercial strip, a street the map has not
			// mapped yet) is still a walk: its trace and times are worth keeping, and houses can be added later with Draw area.
			// Save (0) keeps it; Discard is not the only way out.
			if (nd.quick && nd.quick.track && nd.quick.track.length > 1) {
				if (!nd.quick.saveEmpty) {
					askSheet({ title: 'Save the walk with no houses?', html: 'No mapped address point sits on the streets you walked, so nothing will be counted as delivered. The walk itself (' + esc(quickDescription(nd.quick)) + ') is kept in the log and you can add houses to it later with <em>Draw area</em>.', primary: 'Save the walk', onPrimary: function () { nd.quick.saveEmpty = true; saveDrop(startNow); }, secondary: 'Not yet' });
					return;
				}
			} else {
				nd.error = 'Select at least one address.'; render(); return;
			}
		}
		if (nd.editId) {
			nd.step = 'busy'; nd.busy = 'Saving the route…'; render();
			api('drops/' + nd.editId + '/houses', { method: 'POST', body: { addresses: addresses, streets: streets, geometry: geometry, streets_summary: summary.slice(0, 255), walks: walksMeta } }).then(function () {
				return api('drops/' + nd.editId, { method: 'POST', body: { name: f.name || autoName(f, names), hanger_type: f.hanger_type, hanger_side: f.hanger_side, delivered_by: f.delivered_by, drop_date: f.date, revisit_days: f.revisit_days, notes: f.notes } });
			}).then(function () {
				S.drops = null; clearDraft();
				if (startNow) { return startSession(nd.editId).catch(function (e) { if (!e.silent) { throw e; } resetNewDrop(); S.tab = 'map'; render(); toast('The route is saved — start it from Clusters when you are ready.'); }); }
				resetNewDrop(); S.tab = 'map'; render();
			}).catch(function (e) { nd.error = 'Save failed: ' + e.message; nd.step = 'review'; render(); });
			return;
		}
		if (nd.mode === 'shot' && !f.date) { nd.error = 'When was this route walked? Add the date (roughly is fine) under Drop summary.'; render(); return; }
		if (nd.batchItem) { // saved already (Save all got there first) or being saved — never posted twice
			var bsh = nd.batchItem;
			if (bsh.status === 'saved' || bsh.status === 'saving') { toast(bsh.status === 'saved' ? 'This sheet is already saved.' : 'This sheet is being saved.'); backToBatch(); return; }
			if (!S.batch || S.batch.sheets.indexOf(bsh) < 0) { toast('This sheet was removed from the list.'); backToBatch(); return; }
			bsh.status = 'saving';
		}
		var payload = dropPayload(nd, rp);
		if (nd.quick) {
			payload.drop.started_at = localDT(nd.quick.started); payload.drop.finished_at = localDT(nd.quick.finished);
			payload.drop.drop_date = localDT(nd.quick.started).slice(0, 10); // the day it was walked, not the day it was counted
			payload.drop.drop_time = localDT(nd.quick.started).slice(11, 16);
			if (payload.drop.quantity == null) { payload.drop.quantity = addresses.length; }
			payload.track = nd.quick.track;
			payload.drop.notes = (f.notes ? f.notes + ' ' : '') + '[Quick walk' + (nd.quick.sideNotes && nd.quick.sideNotes.length ? ': ' + nd.quick.sideNotes.filter(function (n) { return !/^tap a grey dot/.test(n); }).join('; ') : '') + (nd.quick.gaps && nd.quick.gaps.length ? '; GPS off ' + nd.quick.gaps.map(function (g) { return fmtClock(g[0]) + '–' + fmtClock(g[1]); }).join(', ') : '') + ']';
			var lq = quick(); if (!nd.quick.id && lq && lq.started === nd.quick.started && lq.id) { nd.quick.id = lq.id; } // created while the houses were being counted
			if (nd.quick.id) { payload.live_id = nd.quick.id; }
		}
		nd.step = 'busy'; nd.busy = addresses.length ? 'Saving ' + addresses.length + ' addresses…' : 'Saving the walk…'; render();
		api('drops', { method: 'POST', body: payload }).then(function (r) {
			if (r.duplicate && nd.quick) { // this walk was saved already — show that one, write nothing
				clearDraft(); saveQuick(null); paceReset(); S.drops = null;
				logEv('quick.dup', 'save refused: already #' + r.id);
				toast('This walk was already saved as #' + r.id + ' — nothing was added twice.', 9000);
				resetNewDrop(); S.tab = 'map'; store('tab', 'map'); render();
				return 'dup';
			}
			if (nd.batchItem) {
				sheetSaved(nd.batchItem, r.id, addresses.length);
				// back to the list — also when the walker went to another tab meanwhile
				if (S.nd === nd) { if (S.tab === 'new') { backToBatch(); } else { S.nd = makeND(); S.nd.mode = 'batch'; S.nd.step = 'batch'; } }
				return 'batch';
			}
			nd.savedId = r.id; nd.savedCount = addresses.length; nd.savedStatus = payload.drop.status; nd.savedName = payload.drop.name; nd.savedDate = payload.drop.drop_date; nd.savedDrop = payload.drop;
			clearDraft();
			if (nd.quick) { nd.finishedMeta = { minutes: Math.max(1, Math.round((nd.quick.finished - nd.quick.started) / 60000)), walkM: nd.quick.walkM || 0 }; saveQuick(null); paceReset(); }
			S.drops = null;
			if (startNow) { return startSession(r.id).then(function () { return 'started'; }).catch(function (e) { if (!e.silent) { throw e; } toast('The route is saved — start it from Clusters when you are ready.'); return null; }); }
			if (payload.drop.status === 'planned') { return null; }
			setBusy('Saved as drop #' + r.id + '. Adding weather, air quality, fire-zone and neighborhood data…');
			return api('drops/' + r.id + '/enrich', { method: 'POST' }).catch(function () { return null; });
		}).then(function (r) {
			if (r === 'started' || r === 'dup' || r === 'batch') { return; }
			nd.enrichment = r ? r.enrichment : null;
			nd.step = 'done'; render();
		}).catch(function (e) { if (nd.batchItem && nd.batchItem.status === 'saving') { nd.batchItem.status = 'ready'; } nd.error = 'Save failed: ' + e.message; nd.step = 'review'; render(); });
	}

	function renderDone(main) {
		var nd = S.nd, e = nd.enrichment || {}, w = e.weather || {}, c = e.census || {};
		var chips = [];
		if (w.temp_f != null) { chips.push('🌡 ' + w.temp_f + '°F'); }
		if (w.heat_index_f != null && w.heat_index_f !== w.temp_f) { chips.push('feels like ' + w.heat_index_f + '°F'); }
		if (w.wind_mph != null) { chips.push('wind ' + w.wind_mph + ' mph' + (w.wind_direction ? ' ' + w.wind_direction : '')); }
		if (w.aqi_us != null) { chips.push('AQI ' + w.aqi_us); }
		if (e.fire_hazard_zone) { chips.push('🔥 ' + e.fire_hazard_zone); }
		if (c.median_home_value) { chips.push('🏠 median $' + Math.round(c.median_home_value / 1000) + 'k' + (c.acs_level === 'ZIP' ? ' (ZIP)' : '')); }
		if (c.median_year_built) { chips.push('built ~' + c.median_year_built); }
		if (e.fire_history && e.fire_history.last_fire_year) { chips.push('past fire ' + e.fire_history.last_fire_year); }
		var planned = nd.savedStatus === 'planned';
		var fm = nd.finishedMeta;
		var when = nd.savedDate ? fmtDate(nd.savedDate) : '';
		// a finished walk gets its numbers in big type — the "checked it off the list" moment the walker likes — and one tap to send them
		var sd = nd.savedDrop || {}, summaryD = null;
		if (!planned && sd) {
			summaryD = { id: nd.savedId, name: sd.name, drop_date: sd.drop_date, drop_time: sd.drop_time, started_at: sd.started_at, finished_at: sd.finished_at, quantity: sd.quantity != null ? sd.quantity : nd.savedCount, address_count: nd.savedCount, delivered_count: nd.savedCount, duration_min: fm ? fm.minutes : null, walked_m: fm ? Math.round(fm.walkM || 0) : null, streets_summary: sd.streets_summary, city: sd.city, zip: sd.zip, delivered_by: sd.delivered_by, enrichment: nd.enrichment };
		}
		var pcD = summaryD ? paceOf(summaryD) : '';
		var big = fm ? '<div class="zdh-done-nums"><div><strong>' + esc(summaryD.quantity) + '</strong><span>hangers</span></div><div><strong>' + esc(fmtMin(fm.minutes)) + '</strong><span>on foot</span></div>' + (fm.walkM >= 160 ? '<div><strong>' + esc(fmtMiles(fm.walkM)) + '</strong><span>walked</span></div>' : '') + (pcD ? '<div><strong>' + pcD + '</strong><span>an hour</span></div>' : '') + '</div>' : '';
		main.innerHTML = '<div class="zdh-card zdh-done"><div class="zdh-done-icon">✓</div><h2>' + (planned ? esc(nd.savedName || ('Cluster #' + nd.savedId)) + ' is planned' : (fm ? 'Done — logged' : 'Drop #' + esc(nd.savedId) + ' saved')) + '</h2>' + big + '<p>' + esc(nd.savedCount) + ' houses ' + (planned ? (when ? 'for <strong>' + esc(when) + '</strong>' : 'queued') + tx('.', ' — it shows in green under Clusters; tap "Start delivering" when you\'re there.') : (fm ? 'delivered' + tx('.', '. Logged with the time, your walking track and the weather.') : 'recorded' + (nd.uploaded.length ? ' and ' + nd.uploaded.length + ' screenshot' + (nd.uploaded.length > 1 ? 's' : '') + ' filed in the media library.' : '.'))) + '</p>' +
			(chips.length ? '<div class="zdh-chips">' + chips.map(function (x) { return '<span class="zdh-chip">' + esc(x) + '</span>'; }).join('') + '</div>' : (planned ? '' : '<p class="zdh-muted">Context data (weather, neighborhood) could not be fetched right now — use “Refresh data” on the drop card later.</p>')) +
			'<div class="zdh-actions">' + (summaryD ? '<button class="zdh-secondary" id="d-share">Share</button>' : '') + '<button class="zdh-primary" id="d-new">Plan another</button></div><p class="zdh-help" style="text-align:center"><button type="button" class="zdh-linkbtn" id="d-map">See it under Clusters</button></p></div>';
		var dsh = document.getElementById('d-share'); if (dsh) { dsh.addEventListener('click', function () { shareText('Door-hanger walk #' + nd.savedId, dropSummaryText(summaryD)); }); }
		document.getElementById('d-new').addEventListener('click', function () { resetNewDrop(); render(); });
		document.getElementById('d-map').addEventListener('click', function () { resetNewDrop(); S.tab = 'map'; store('tab', 'map'); render(); });
	}


	/* ===================================================== OLD ROUTES FROM PAPER
	   The routes walked before the app are printed map sheets ("North 2") with the walked streets in highlighter. Pick the
	   photos, or a PDF (every page is a sheet), and the reading starts at once, two sheets at a time, while the list fills
	   in. Each sheet becomes a card: what was read, the houses as a sketch beside the photo, the date. "Looks right" saves
	   it; "Fix" opens the full map review (and the street list) for that one sheet and comes back to the list. Sheets that
	   need a look — lined up loosely or by street names only, the reader unsure, an unusual number of houses — are flagged
	   and left out of "Save all"; a sheet with no date can't be saved. The list lives on this phone until the app reloads.
	   Guarantees: a sheet is never saved twice; 600 px copies in the list and pictures let go; typing in a field never
	   swallows the next tap; details changed in Fix stay that sheet's own; the route being drawn comes back; a
	   delivery's done screen never writes into a sheet; a signed-out request does not reload the page (this app never
	   reloads by itself, so the list is not lost); a sheet removed mid-read stops and frees its pictures, and only two
	   read at once. */
	var BATCH_PAR = 2;
	var BT = { wake: null, wakeOff: false, timer: null, tick: null, nextId: 1, inflight: 0, savingAll: false, vis: false };
	function openBatch() {
		if (!S.batch) {
			S.batch = { sheets: [], common: { date: '', delivered_by: recall('deliverer') || C.userName || '', hanger_type: recall('type') || (C.hangerTypes && C.hangerTypes[0]) || 'Standard', hanger_side: recall('side') || '', revisit_days: C.revisitDays || 90 } };
		}
		// a sheet put right by hand (the street list) has houses now, even if its reading had failed
		S.batch.sheets.forEach(function (sh) { if ((sh.status === 'failed' || sh.status === 'empty') && sh.nd.geo && sh.nd.sel && Object.keys(sh.nd.sel).length) { sh.status = 'ready'; sh.error = ''; } });
		if (S.nd && !S.nd.batchItem && S.nd.step !== 'batch' && !S.nd.quick && S.nd.step !== 'done') { S.batchPrev = S.nd; } // B5
		S.nd = makeND(); S.nd.mode = 'batch'; S.nd.step = 'batch';
		S.tab = 'new'; store('tab', 'new');
		render(); scrollTop();
	}
	function backToBatch() { openBatch(); }
	function batchCounts() {
		var c = { all: 0, waiting: 0, busy: 0, ready: 0, flagged: 0, saved: 0, failed: 0, empty: 0 };
		((S.batch || {}).sheets || []).forEach(function (sh) {
			c.all++;
			if (sh.status === 'waiting') { c.waiting++; } else if (sh.status === 'reading' || sh.status === 'finding' || sh.status === 'saving') { c.busy++; }
			else if (sh.status === 'ready') { c.ready++; if (sheetFlags(sh).some(function (x) { return x.level !== 'info'; })) { c.flagged++; } }
			else if (sh.status === 'saved') { c.saved++; } else if (sh.status === 'failed') { c.failed++; } else if (sh.status === 'empty') { c.empty++; }
		});
		return c;
	}
	function batchSummaryText() {
		var c = batchCounts(), parts = [];
		if (c.busy + c.waiting) { parts.push((c.busy + c.waiting) + ' being read'); }
		if (c.ready) { parts.push(c.ready + ' to check'); }
		if (c.saved) { parts.push(c.saved + ' saved'); }
		if (c.failed) { parts.push(c.failed + ' failed'); }
		return c.all + ' sheet' + (c.all === 1 ? '' : 's') + (parts.length ? ': ' + parts.join(', ') : '');
	}
	function sheetDate(sh) { return sh.nd.form.date || (S.batch ? S.batch.common.date : '') || ''; }
	function sheetFlags(sh) {
		var nd = sh.nd, P = paperLib();
		if (!P) { return []; }
		return P.sheetFlags({ status: sh.status, houses: nd.sel ? Object.keys(nd.sel).length : 0, date: sheetDate(sh), alignFailed: nd.georefFailed || '', aligned: !!nd.georef, paper: !!nd.paper, loose: nd.georef ? nd.georef.loose : null, confidence: (nd.analysis || {}).confidence, zipNote: nd.zipNote || '', dateFromSheet: !!nd.dateFromSheet });
	}
	function addBatchFiles(list) {
		var files = Array.prototype.slice.call(list || []);
		if (!files.length) { return; }
		logEv('batch', 'adding ' + files.length + ' file' + (files.length > 1 ? 's' : ''));
		var cd = document.getElementById('zdh-batch-common'); if (cd && cd.open) { cd.open = false; } // the sheets are what you see once they are added
		(function next(i) {
			if (i >= files.length) { renderBatchList(); pumpBatch(); return; }
			var file = files[i];
			if (/pdf$/i.test(file.type) || /\.pdf$/i.test(file.name)) {
				batchNote('Opening ' + file.name + '…');
				var base = file.name.replace(/\.pdf$/i, '').replace(/[^\w\-]+/g, '-').slice(0, 40) || 'scan';
				loadPdfJs().then(function (lib) { return file.arrayBuffer().then(function (buf) { return lib.getDocument({ data: buf }).promise; }); }).then(function (pdf) {
					var n = Math.min(pdf.numPages, 40);
					logEv('pdf', file.name + ': ' + pdf.numPages + ' pages');
					return (function page(k) {
						if (k > n || !S.batch) { return Promise.resolve(); }
						batchNote('Opening ' + file.name + ' — page ' + k + ' of ' + n + '…');
						return pdfPageImage(pdf, k, 2600, base).then(function (img) { if (img) { addSheet(img, (pdf.numPages > 1 ? 'Page ' + k + ' of ' : '') + file.name); renderBatchList(); pumpBatch(); } }).then(function () { return page(k + 1); });
					})(1).then(function () { if (pdf.numPages > 40) { toast('Only the first 40 pages of ' + file.name + ' were added.', 6000); } try { pdf.destroy(); } catch (e) {} });
				}).catch(function (e) { toast('Could not open ' + file.name + ': ' + e.message, 8000); logEv('pdf', 'failed: ' + e.message); })
					.then(function () { batchNote(''); next(i + 1); });
				return;
			}
			usableImage(file).then(function (img) { addSheet(img, file.name || 'Photo'); }).catch(function (e) { toast('Could not open ' + file.name + ': ' + e.message, 8000); logEv('photo', 'open failed: ' + e.message); })
				.then(function () { renderBatchList(); pumpBatch(); next(i + 1); });
		})(0);
	}
	function addSheet(file, label) {
		if (!S.batch) { return null; } // the list was closed while this file was still opening
		var nd = makeND(), f = nd.form;
		nd.mode = 'shot'; nd.step = 'review'; nd.files = [file];
		// the sheet says where and when it is: nothing carries over from the last route (a remembered ZIP only misleads the
		// reader), and the time of day of an old walk is not known, so none is made up
		f.status = 'done'; f.date = ''; f.time = ''; f.name = ''; f.zip = '';
		var sh = { id: BT.nextId++, file: file, url: URL.createObjectURL(file), thumb: '', label: label, status: 'waiting', progress: '', error: '', tries: 0, nd: nd };
		nd.batchItem = sh;
		S.batch.sheets.push(sh);
		makeThumb(file).then(function (u) {
			if (!S.batch || S.batch.sheets.indexOf(sh) < 0) { try { if (u) { URL.revokeObjectURL(u); } } catch (e) {} return; }
			sh.thumb = u;
			if (sh.status !== 'reading' && sh.status !== 'finding') { updateSheetCard(sh); } else { var im = document.querySelector('#zdh-bs-' + sh.id + ' .zdh-bs-thumb'); if (im && im.tagName === 'IMG') { im.src = u; } }
		});
		return sh;
	}
	/* the list shows a 600 px copy, not the 12-megapixel photo (twenty of those make Safari reload the page) */
	function makeThumb(file) {
		return new Promise(function (resolve) {
			var u = URL.createObjectURL(file), img = new Image();
			img.onload = function () {
				try {
					var sc = Math.min(1, 600 / Math.max(img.naturalWidth, img.naturalHeight)), c = document.createElement('canvas');
					c.width = Math.max(1, Math.round(img.naturalWidth * sc)); c.height = Math.max(1, Math.round(img.naturalHeight * sc));
					c.getContext('2d').drawImage(img, 0, 0, c.width, c.height);
					c.toBlob(function (b) { URL.revokeObjectURL(u); c.width = c.height = 0; resolve(b ? URL.createObjectURL(b) : ''); }, 'image/jpeg', 0.8);
				} catch (e) { URL.revokeObjectURL(u); resolve(''); }
			};
			img.onerror = function () { URL.revokeObjectURL(u); resolve(''); };
			img.src = u;
		});
	}
	/* let a sheet's pictures go (removed, or saved: the photo is in the media library then) */
	function freeSheet(sh, keepThumb) {
		try { if (sh.url) { URL.revokeObjectURL(sh.url); } } catch (e) {}
		sh.url = '';
		if (!keepThumb) { try { if (sh.thumb) { URL.revokeObjectURL(sh.thumb); } } catch (e) {} sh.thumb = ''; }
		var g = sh.nd.georef; if (g && g.info) { try { URL.revokeObjectURL(g.info.url); } catch (e) {} g.info.img = null; }
		sh.nd.mask = null; sh.nd.prepared = null; sh.file = null; sh.nd.files = [];
	}
	function pumpBatch() {
		if (!S.batch) { return; }
		if (!BT.vis) { BT.vis = true; document.addEventListener('visibilitychange', function () { if (!document.hidden && S.batch) { pumpBatch(); } }); }
		var busy = BT.inflight; // counts a sheet removed while it was being read, too
		S.batch.sheets.forEach(function (sh) { if (busy < BATCH_PAR && sh.status === 'waiting' && !(sh.after && Date.now() < sh.after)) { busy++; readSheet(sh); } });
		batchWake(S.batch.sheets.some(function (sh) { return sh.status === 'waiting' || sh.status === 'reading' || sh.status === 'finding' || sh.status === 'saving'; }));
		clearTimeout(BT.timer);
		if (S.batch.sheets.some(function (sh) { return sh.status === 'waiting'; })) { BT.timer = setTimeout(pumpBatch, 4000); }
	}
	/* the screen stays on while sheets are read — a locked iPhone pauses the page */
	function batchWake(on) {
		if (on && !BT.wake && 'wakeLock' in navigator && !document.hidden) {
			BT.wake = 'pending';
			navigator.wakeLock.request('screen').then(function (w) {
				BT.wake = w; w.addEventListener('release', function () { BT.wake = null; });
				if (BT.wakeOff) { BT.wakeOff = false; try { w.release(); } catch (e) {} } // turned off while the request was pending
			}).catch(function () { BT.wake = null; });
		} else if (!on && BT.wake === 'pending') { BT.wakeOff = true; }
		else if (!on && BT.wake) { try { BT.wake.release(); } catch (e) {} BT.wake = null; }
		if (on) { BT.wakeOff = false; }
	}
	function readSheet(sh) {
		var nd = sh.nd, t0 = Date.now();
		var gone = function () { return !S.batch || S.batch.sheets.indexOf(sh) < 0; }; // removed while being read: stop at the next step
		BT.inflight++;
		sh.status = 'reading'; sh.error = ''; sh.progress = 'Preparing the picture…'; sh.started = t0;
		updateSheetCard(sh);
		var say = function (text, short) { sh.progress = short || text; updateSheetCard(sh, true); };
		var stop = new Error('removed'); stop.removed = true;
		logEv('batch', '#' + sh.id + ' reading ' + sh.label);
		var upload = nd.uploaded && nd.uploaded.length ? Promise.resolve() : prepareFile(sh.file, true).then(function (file) {
			if (gone()) { throw stop; }
			nd.prepared = [file];
			say('Uploading…');
			var fd = new FormData(); fd.append('files[]', file, file.name);
			return api('upload', { method: 'POST', form: fd }).then(function (r) { nd.uploaded = r.files; });
		});
		upload.then(function () {
			if (gone()) { throw stop; }
			say('Reading the map…');
			return nd.analysis ? nd.analysis : analyzeWithPolling(nd.uploaded.map(function (u) { return u.id; }), nd, say);
		}).then(function (analysis) {
			if (gone()) { throw stop; }
			if (!nd.analysis) { applyAnalysis(nd, analysis); }
			if (!nd.prepared) { nd.prepared = [sh.file]; }
			var a = nd.analysis;
			if (!(a.covered_streets || []).length && !(a.anchors || []).length) { sh.status = 'empty'; sh.progress = ''; logEv('batch', '#' + sh.id + ' nothing to log'); return; }
			sh.status = 'finding'; say('Finding the houses…');
			return findHouses(nd, function (t) { say(t); }).then(function () {
				if (gone()) { throw stop; }
				sh.status = 'ready'; sh.progress = ''; sh.secs = Math.round((Date.now() - t0) / 1000);
				logEv('batch', '#' + sh.id + ' ready in ' + sh.secs + ' s: ' + Object.keys(nd.sel).length + ' houses' + (nd.georef ? ', lined up' + (nd.georef.loose ? ' loosely' : '') : ', by street names'));
			});
		}).catch(function (e) {
			if (e && e.removed) { logEv('batch', '#' + sh.id + ' removed while being read'); return; }
			logEv('batch', '#' + sh.id + ' failed: ' + e.message);
			sh.tries++;
			if (sh.tries < 2 && (e.network || document.hidden)) { sh.status = 'waiting'; sh.after = Date.now() + 5000; sh.progress = 'Connection dropped — trying again…'; return; }
			sh.status = 'failed'; sh.error = e.message; sh.progress = '';
		}).then(function () { BT.inflight = Math.max(0, BT.inflight - 1); if (gone()) { freeSheet(sh); } updateSheetCard(sh); renderBatchHead(); pumpBatch(); });
	}
	/* the stack's details, for a sheet that has none of its own (B4: details changed in Fix are that sheet's) */
	function applyCommon(sh) {
		var f = sh.nd.form, c = S.batch.common;
		if (sh.nd.ownDetails) { if (!f.date) { f.date = c.date; } return; }
		f.delivered_by = c.delivered_by; f.hanger_type = sh.nd.fromSheet && sh.nd.fromSheet.type ? f.hanger_type : c.hanger_type; f.hanger_side = c.hanger_side; f.revisit_days = c.revisit_days;
		if (!f.date) { f.date = c.date; } // a date written on the sheet wins
	}
	function saveSheet(sh) {
		var nd = sh.nd;
		if (sh.status !== 'ready' || !S.batch || S.batch.sheets.indexOf(sh) < 0) { return Promise.resolve(false); } // saved, being saved, or removed
		if (S.nd === nd) { return Promise.resolve(false); } // open in Fix: saved from there, with what is on its screen
		applyCommon(sh);
		if (!nd.form.date) { toast('Add the date it was walked first.'); updateSheetCard(sh); return Promise.resolve(false); }
		var rp = routeParts(nd, false);
		if (rp.empty) { toast('Pick at least one house first (Fix).'); return Promise.resolve(false); }
		var payload = dropPayload(nd, rp);
		sh.status = 'saving'; updateSheetCard(sh);
		return api('drops', { method: 'POST', body: payload }).then(function (r) {
			sheetSaved(sh, r.id, rp.addresses.length);
			return true;
		}).catch(function (e) { sh.status = 'ready'; updateSheetCard(sh); toast('Could not save ' + sheetTitle(sh) + ': ' + e.message, 8000); return false; });
	}
	function sheetSaved(sh, id, count) {
		sh.status = 'saved'; sh.savedId = id; sh.savedCount = count; S.drops = null;
		logEv('batch', '#' + sh.id + ' saved as #' + id + ' (' + count + ' houses)');
		api('drops/' + id + '/enrich', { method: 'POST' }).catch(function () {}); // weather and the rest, in the background
		freeSheet(sh, true);
		updateSheetCard(sh); renderBatchHead();
	}
	/* a sheet only looked at in Fix still takes later "For all sheets" changes; one changed there keeps its own */
	function detailsKey(f) { return [f.date, f.delivered_by, f.hanger_type, f.hanger_side, f.revisit_days, f.quantity, f.name, f.notes, f.city, f.zip].map(function (v) { return String(v == null ? '' : v); }).join('\u0001'); } // as text: a field reads back 90 as "90"
	function markOwn(nd) { if (nd.detailsAt != null && detailsKey(nd.form) !== nd.detailsAt) { nd.ownDetails = true; } }
	function sheetTitle(sh) { var a = sh.nd.analysis || {}; return a.sheet_label ? a.sheet_label : (sh.nd.form.name || sh.label); }
	function sheetStreets(sh) {
		var g = sh.nd.geo; if (!g) { return []; }
		var by = {};
		g.addresses.forEach(function (x) { if (sh.nd.sel[x._k]) { by[x.street] = (by[x.street] || 0) + 1; } });
		return Object.keys(by).sort(function (p, q) { return by[q] - by[p]; });
	}
	/* the houses as a small drawing (no base map: twenty maps would be too much for a phone) */
	function sheetSketch(sh) {
		var g = sh.nd.geo; if (!g) { return ''; }
		var on = g.addresses.filter(function (x) { return sh.nd.sel[x._k] && x.lat != null; });
		if (!on.length) { return ''; }
		var la0 = Infinity, la1 = -Infinity, ln0 = Infinity, ln1 = -Infinity;
		on.forEach(function (x) { la0 = Math.min(la0, x.lat); la1 = Math.max(la1, x.lat); ln0 = Math.min(ln0, x.lng); ln1 = Math.max(ln1, x.lng); });
		var cos = Math.cos((la0 + la1) / 2 * Math.PI / 180), padLa = Math.max((la1 - la0) * 0.12, 0.0004), padLn = Math.max((ln1 - ln0) * 0.12, 0.0004 / cos);
		la0 -= padLa; la1 += padLa; ln0 -= padLn; ln1 += padLn;
		var W = 300, H = 200, sc = Math.min(W / ((ln1 - ln0) * cos), H / (la1 - la0));
		var ox = (W - (ln1 - ln0) * cos * sc) / 2, oy = (H - (la1 - la0) * sc) / 2;
		var P = function (lat, lng) { return (ox + (lng - ln0) * cos * sc).toFixed(1) + ',' + (oy + (la1 - lat) * sc).toFixed(1); };
		var inBox = function (pt) { return pt[0] >= la0 && pt[0] <= la1 && pt[1] >= ln0 && pt[1] <= ln1; };
		var selSeg = {}; on.forEach(function (x) { if (x._seg != null) { selSeg[x._seg] = 1; } });
		var roads = (g.segments || []).filter(function (s) { return (s.path || []).some(inBox); }).map(function (s) {
			return '<polyline points="' + s.path.map(function (pt) { return P(pt[0], pt[1]); }).join(' ') + '" class="' + (selSeg[s.id] ? 'zdh-sk-road zdh-sk-on' : 'zdh-sk-road') + '"/>';
		}).join('');
		var dots = on.map(function (x) { var xy = P(x.lat, x.lng).split(','); return '<circle cx="' + xy[0] + '" cy="' + xy[1] + '" r="2.6"/>'; }).join('');
		return '<svg class="zdh-sketch" viewBox="0 0 ' + W + ' ' + H + '" role="img" aria-label="' + on.length + ' houses">' + roads + '<g class="zdh-sk-dots">' + dots + '</g></svg>';
	}
	function flagsHTML(flags) { return flags.map(function (x) { return '<li class="zdh-bs-flag zdh-bs-flag-' + x.level + '">' + esc(x.text) + '</li>'; }).join(''); }
	function sheetCardHTML(sh) {
		var nd = sh.nd, n = nd.sel ? Object.keys(nd.sel).length : 0, title = esc(sheetTitle(sh));
		var small = sh.thumb || '', thumb = small ? '<img class="zdh-bs-thumb" src="' + small + '" alt="">' : '<span class="zdh-bs-thumb"></span>';
		var head = function (sub) { return '<div class="zdh-bs-head">' + thumb + '<div class="zdh-bs-titles"><div class="zdh-bs-title">' + title + '</div><div class="zdh-bs-sub">' + sub + '</div></div></div>'; };
		var remove = '<button type="button" class="zdh-tool" data-a="remove">Remove</button>';
		if (sh.status === 'waiting' || sh.status === 'reading' || sh.status === 'finding') {
			var secs = sh.started && sh.status !== 'waiting' ? Math.round((Date.now() - sh.started) / 1000) : 0;
			return head('<span class="zdh-spinner zdh-bs-busy" aria-hidden="true"></span><span class="zdh-bs-progress">' + esc(sh.status === 'waiting' ? (sh.progress || 'Waiting its turn…') : sh.progress) + '</span>' + (sh.status !== 'waiting' ? ' <span class="zdh-bs-secs">' + secs + ' s</span>' : '')) +
				'<div class="zdh-bs-actions">' + remove + '</div>';
		}
		if (sh.status === 'saving') { return head('<span class="zdh-spinner zdh-bs-busy" aria-hidden="true"></span>Saving…'); }
		if (sh.status === 'saved') { return head('<span class="zdh-bs-ok">✓ Saved</span> &bull; ' + esc(sh.savedCount) + ' houses &bull; ' + esc(fmtDate(sheetDate(sh), true))); }
		if (sh.status === 'empty') {
			return head('Nothing to log on this page — no highlighted streets were read.') +
				'<div class="zdh-bs-actions">' + remove + '<button type="button" class="zdh-tool" data-a="streets">Add streets by hand</button></div>';
		}
		if (sh.status === 'failed') {
			return head('<span class="zdh-bs-bad">Could not read it:</span> ' + esc(sh.error)) +
				'<div class="zdh-bs-actions">' + remove + (nd.analysis ? '<button type="button" class="zdh-tool" data-a="streets">Fix by hand</button>' : '') + '<button type="button" class="zdh-primary zdh-bs-go" data-a="retry">Try again</button></div>';
		}
		var flags = sheetFlags(sh), blocked = flags.some(function (x) { return x.level === 'block'; });
		var sts = sheetStreets(sh), stLine = sts.slice(0, 3).join(', ') + (sts.length > 3 ? ' +' + (sts.length - 3) : '');
		return head('<strong>' + n + ' houses</strong>' + (stLine ? ' &bull; ' + esc(stLine) : '')) +
			'<div class="zdh-bs-pics">' + (small ? '<a class="zdh-bs-photo"' + (sh.url ? ' href="' + sh.url + '" target="_blank" rel="noopener"' : '') + '><img src="' + small + '" alt="The sheet"></a>' : '<span></span>') + sheetSketch(sh) + '</div>' +
			'<label class="zdh-bs-date">Walked on <input type="date" data-a="date" value="' + esc(sheetDate(sh)) + '"></label>' +
			(flags.length ? '<ul class="zdh-bs-flags">' + flagsHTML(flags) + '</ul>' : '') +
			'<div class="zdh-bs-actions">' + remove + '<button type="button" class="zdh-tool" data-a="fix">Fix</button><button type="button" class="zdh-primary zdh-bs-go" data-a="save"' + (blocked ? ' disabled' : '') + '>✓ Looks right — save</button></div>';
	}
	function updateSheetCard(sh, textOnly) {
		var card = document.getElementById('zdh-bs-' + sh.id); if (!card) { return; }
		if (textOnly) {
			var pe = card.querySelector('.zdh-bs-progress'), se = card.querySelector('.zdh-bs-secs');
			if (pe) { pe.textContent = sh.progress; if (se && sh.started) { se.textContent = Math.round((Date.now() - sh.started) / 1000) + ' s'; } return; }
		}
		card.className = 'zdh-card zdh-bsheet zdh-bs-' + sh.status;
		card.innerHTML = sheetCardHTML(sh);
		bindSheetCard(sh, card);
	}
	/* only the date, the flags and the save button (B3: leaving a field fires "change" on the tap that follows, and
	   rebuilding the card then swallowed that tap) */
	function refreshSheetBits(sh) {
		var card = document.getElementById('zdh-bs-' + sh.id); if (!card || sh.status !== 'ready') { return; }
		var flags = sheetFlags(sh), di = card.querySelector('[data-a=date]'), sv = card.querySelector('[data-a=save]'), ul = card.querySelector('.zdh-bs-flags');
		if (di && di !== document.activeElement) { di.value = sheetDate(sh); }
		if (sv) { sv.disabled = flags.some(function (x) { return x.level === 'block'; }); }
		var html = flagsHTML(flags);
		if (ul) { if (html) { if (ul.innerHTML !== html) { ul.innerHTML = html; } } else { ul.remove(); } }
		else if (html && di) { var nu = el('<ul class="zdh-bs-flags">' + html + '</ul>'); di.parentNode.parentNode.insertBefore(nu, di.parentNode.nextSibling); }
	}
	function bindSheetCard(sh, card) {
		card.querySelectorAll('[data-a]').forEach(function (b) {
			var act = b.getAttribute('data-a');
			if (act === 'date') { b.addEventListener('change', function () { sh.nd.form.date = b.value; sh.nd.dateFromSheet = false; refreshSheetBits(sh); renderBatchHead(); }); return; }
			b.addEventListener('click', function () {
				if (act === 'remove') {
					var reading = sh.status === 'reading' || sh.status === 'finding';
					S.batch.sheets = S.batch.sheets.filter(function (x) { return x !== sh; }); card.remove();
					if (!reading) { freeSheet(sh); } // one still being read lets go when its step ends
					renderBatchHead(); pumpBatch(); return;
				}
				if (act === 'retry') { sh.tries = 0; sh.status = 'waiting'; sh.error = ''; updateSheetCard(sh); pumpBatch(); return; }
				if (act === 'save') { saveSheet(sh).then(function () { renderBatchHead(); }); return; }
				if (act === 'fix' || act === 'streets') {
					applyCommon(sh);
					S.nd = sh.nd;
					if (act === 'streets' || !sh.nd.geo) { sh.nd.step = 'streets'; if (!sh.nd.analysis) { sh.nd.analysis = { covered_streets: [], anchors: [] }; } sh.nd.streets = sh.nd.streets || []; }
					else { sh.nd.step = 'review'; }
					sh.nd.detailsAt = detailsKey(sh.nd.form); // B4: its own details only if they are changed in Fix
					render(); scrollTop();
				}
			});
		});
	}
	function batchNote(t) { var e = document.getElementById('zdh-batch-note'); if (e) { e.textContent = t; e.hidden = !t; } }
	function renderBatchHead() {
		var e = document.getElementById('zdh-batch-head'); if (!e || !S.batch) { return; }
		var looksRight = function () { return S.batch ? S.batch.sheets.filter(function (sh) { return sh.status === 'ready' && S.nd !== sh.nd && !sheetFlags(sh).some(function (x) { return x.level !== 'info'; }); }) : []; };
		var c = batchCounts(), okReady = looksRight();
		var html = c.all ? '<div class="zdh-batch-sum">' + esc(batchSummaryText()) + '</div>' + (BT.savingAll ? '<div class="zdh-note">Saving…</div>' : (okReady.length > 1 ? '<button type="button" class="zdh-primary" id="zdh-batch-saveall">✓ Save all ' + okReady.length + ' that look right</button>' : '')) : '';
		if (e.getAttribute('data-html') === html) { return; } // unchanged: leave the button alone so a tap in progress lands
		e.setAttribute('data-html', html); e.innerHTML = html;
		var sa = document.getElementById('zdh-batch-saveall');
		if (sa) { sa.addEventListener('click', function () {
			if (BT.savingAll) { return; } // one Save all at a time
			BT.savingAll = true; renderBatchHead();
			var n0 = 0;
			looksRight().reduce(function (pr, sh) { return pr.then(function () { return saveSheet(sh).then(function (ok) { if (ok) { n0++; } }); }); }, Promise.resolve())
				.then(function () { BT.savingAll = false; renderBatchHead(); toast('Saved ' + n0 + ' route' + (n0 === 1 ? '' : 's') + '.'); });
		}); }
	}
	function renderBatchList() {
		var list = document.getElementById('zdh-batch-list'); if (!list || !S.batch) { return; }
		var have = {};
		list.querySelectorAll('.zdh-bsheet').forEach(function (c) { have[c.id] = c; });
		S.batch.sheets.forEach(function (sh) {
			if (!have['zdh-bs-' + sh.id]) {
				var card = el('<div class="zdh-card zdh-bsheet zdh-bs-' + sh.status + '" id="zdh-bs-' + sh.id + '"></div>');
				list.appendChild(card); updateSheetCard(sh);
			}
		});
		var empty = document.getElementById('zdh-batch-empty'); if (empty) { empty.hidden = S.batch.sheets.length > 0; }
		renderBatchHead();
	}
	function renderBatch(main) {
		var c = S.batch.common, types = (C.hangerTypes && C.hangerTypes.length) ? C.hangerTypes : ['Standard'];
		main.innerHTML =
			'<div class="zdh-card zdh-batch-intro">' +
				'<h2>Routes you already walked</h2>' +
				'<p class="zdh-help">' + tx('Photos or a PDF of the highlighted route sheets. Each sheet becomes a route.', 'Photos (or a PDF) of the printed route sheets with the walked streets highlighted. Pick as many as you like — each sheet becomes its own route. Reading starts straight away; check each one below and save it.') + '</p>' +
				'<label class="zdh-filebtn zdh-batch-add"><input type="file" id="zdh-batch-files" accept="image/*,image/heic,image/heif,.heic,.heif,application/pdf,.pdf" multiple> ' + ICON_CAMERA + ' Add photos or a PDF</label>' +
				'<p class="zdh-help zdh-muted" id="zdh-batch-empty"' + (S.batch.sheets.length ? ' hidden' : '') + '>' + tx('Sheet flat, all of it in the picture, street names readable.', 'For a good read: the sheet flat, all of it in the picture, good light, street names readable at four or more corners.') + '</p>' +
				'<p class="zdh-help zdh-muted">' + (C.paperKey ? tx('Colors are read with the team\'s key.', 'Highlighter colors are read with the team\'s key (Zorderz → Doorhangers → Paper map key).') : tx('Several highlighter colors? Set the key in Zorderz → Doorhangers.', 'More than one highlighter color on the sheets? Put what each color means under Zorderz → Doorhangers → Paper map key.')) + '</p>' +
				'<p class="zdh-note" id="zdh-batch-note" hidden></p>' +
				'<details class="zdh-details zdh-batch-common" id="zdh-batch-common"' + (S.batch.sheets.length ? '' : ' open') + '><summary>For all sheets: ' + esc(c.delivered_by || 'who walked?') + ' &bull; ' + esc(c.hanger_type) + (c.date ? ' &bull; ' + esc(fmtDate(c.date, true)) : '') + '</summary><div class="zdh-grid">' +
					'<label>Walked by<input type="text" id="b-by" list="zdh-team" value="' + esc(c.delivered_by) + '"><datalist id="zdh-team">' + peopleNames().map(function (t) { return '<option value="' + esc(t) + '">'; }).join('') + '</datalist></label>' +
					'<label>Door hanger<select id="b-type">' + types.map(function (t) { return '<option' + (t === c.hanger_type ? ' selected' : '') + '>' + esc(t) + '</option>'; }).join('') + '</select></label>' +
					'<label>Date, if a sheet has none<input type="date" id="b-date" value="' + esc(c.date) + '"></label>' +
					'<label>Revisit in (days)<input type="number" id="b-revisit" inputmode="numeric" min="0" value="' + esc(c.revisit_days) + '"></label>' +
				'</div></details>' +
			'</div>' +
			'<div id="zdh-batch-head" class="zdh-batch-head"></div>' +
			'<div id="zdh-batch-list"></div>' +
			'<div class="zdh-actions"><button class="zdh-secondary" id="zdh-batch-done">← Back to planning</button></div>';
		document.getElementById('zdh-batch-files').addEventListener('change', function (e) { var l = e.target.files; addBatchFiles(l); e.target.value = ''; });
		var upd = function () { // B3: only the bits that depend on these fields are touched, never the cards
			c.delivered_by = document.getElementById('b-by').value.trim(); c.hanger_type = document.getElementById('b-type').value;
			c.date = document.getElementById('b-date').value; c.revisit_days = document.getElementById('b-revisit').value;
			store('deliverer', c.delivered_by); store('type', c.hanger_type);
			S.batch.sheets.forEach(function (sh) { if (sh.status === 'ready') { refreshSheetBits(sh); } }); renderBatchHead();
		};
		['b-by', 'b-type', 'b-date', 'b-revisit'].forEach(function (id) { document.getElementById(id).addEventListener('change', upd); });
		document.getElementById('zdh-batch-done').addEventListener('click', function () {
			var c2 = batchCounts();
			var leave = function () { if (S.batchPrev) { S.nd = S.batchPrev; S.batchPrev = null; S.nd.step = S.nd.step === 'review' && S.nd.geo ? 'review' : 'draw'; } else { resetNewDrop(); } render(); };
			if (c2.ready || c2.busy || c2.waiting) { askSheet({ title: 'Leave the sheets for now?', html: esc(batchSummaryText()) + '. They stay here until the app reloads — tap “Add routes you already walked” to come back to them.', primary: 'Back to planning', onPrimary: leave, secondary: 'Stay' }); return; }
			if (c2.all && c2.saved === c2.all) { S.batch.sheets.forEach(function (sh) { freeSheet(sh); }); S.batch = null; }
			leave();
		});
		renderBatchList();
		pumpBatch();
		clearInterval(BT.tick);
		BT.tick = setInterval(function () { // the seconds counters
			if (!document.getElementById('zdh-batch-list') || !S.batch) { clearInterval(BT.tick); return; }
			S.batch.sheets.forEach(function (sh) { if (sh.status === 'reading' || sh.status === 'finding') { updateSheetCard(sh, true); } });
		}, 1000);
	}

	/* ===================================================== DELIVERY (plan → execute) */
	var SESSION_KEY = 'zdh_session';
	function session() { try { var j = localStorage.getItem(SESSION_KEY); return j ? JSON.parse(j) : null; } catch (e) { return null; } }
	function saveSession(ses) { try { if (ses) { localStorage.setItem(SESSION_KEY, JSON.stringify(ses)); } else { localStorage.removeItem(SESSION_KEY); } } catch (e) {} }
	var D = { cluster: null, map: null, dots: {}, me: null, acc: null, trail: null, watch: null, follow: true, wake: null, timer: null, lastFix: null, status: 'starting', retry: null };

	/* ---- walks of a cluster (a cluster planned before walks existed counts as one walk) ---- */
	function walkList(d) {
		if (d.walks && d.walks.list && d.walks.list.length) { return d.walks.list; }
		return [{ n: 1, count: d.address_count, est_min: 0, streets: d.streets_summary || '', status: d.status === 'done' ? 'done' : (d.status === 'in_progress' ? 'in_progress' : 'planned') }];
	}
	function nextWalkOf(d) {
		var list = walkList(d), w = null;
		list.forEach(function (x) { if (!w && x.status === 'in_progress') { w = x; } });
		if (!w) { list.forEach(function (x) { if (!w && x.status !== 'done') { w = x; } }); }
		return w ? +w.n : 1;
	}
	function walkHouses(d, n) { return (d.addresses || []).filter(function (a) { return (+a.walk || 1) === n; }); }
	function walkPathOf(d, n) { return walkHouses(d, n).filter(function (a) { return a.lat != null; }).sort(function (a, b) { return (a.walk_order || 0) - (b.walk_order || 0); }).map(function (a) { return [a.lat, a.lng]; }); }
	function walkLabel(d, n) { var list = walkList(d); return list.length > 1 ? 'Walk ' + n + ' of ' + list.length : ''; }

	/* Start (or resume) delivering one walk of a cluster: the server marks it in progress, we open the delivery view. */
	function startSession(id, walk) {
		var q = quick();
		if (q) {
			// a Quick Start walk is open on this phone: ask what to do with it instead of refusing with a toast
			return new Promise(function (resolve, reject) {
				var little = quickWalkMeters(q) < 50;
				askSheet({ title: q.ended ? 'A recorded walk has not been counted' : 'A Quick Start walk is still open', html: 'It ' + esc(quickDescription(q)) + '. ' + (little ? 'There is too little in it to log any houses.' : (q.ended ? 'Count its houses first (Clusters → Count the houses), or discard it.' : 'Wrap it up first to log its houses, or discard it.')) + ' A planned cluster cannot run at the same time.',
					primary: little ? 'Discard it and start this cluster' : 'Discard it and start this cluster', danger: true,
					onPrimary: function () { discardQuick('for cluster #' + id); startSession(id, walk).then(resolve, reject); },
					secondary: little ? 'Not now' : 'Wrap it up first', onSecondary: function () { var e = new Error('cancelled'); e.silent = true; reject(e); if (!little) { finishQuick(); } } });
			});
		}
		logEv('start', '#' + id + (walk ? ' walk ' + walk : ''));
		return api('drops/' + id + '/start', { method: 'POST', body: { walk: walk || 0, started_at: localDT(Date.now()) } }).then(function (d) {
			var n = walk || nextWalkOf(d);
			var ses = session();
			if (!ses || ses.id !== d.id || (ses.walk || 1) !== n) {
				paceReset(); // a new walk starts walking
				ses = { id: d.id, walk: n, name: d.name || d.streets_summary, started: Date.now(), lastActive: Date.now(), total: walkHouses(d, n).length, delivered: {}, undel: {}, pending: {}, track: [], lastSync: 0 };
				d.addresses.forEach(function (a) { if (a.delivered) { ses.delivered[a.id] = 1; } });
			}
			saveSession(ses);
			S.drops = null; // status changed → the Clusters list must reload
			openDelivery(d.id, d);
		});
	}
	function openDelivery(id, cluster) {
		S.tab = 'deliver'; navReset();
		D.cluster = cluster || null;
		render();
	}

	function renderDelivery(main) {
		var ses = session();
		if (!ses) { S.tab = 'map'; return render(); }
		if (!D.cluster || D.cluster.id !== ses.id) {
			main.innerHTML = '<div class="zdh-card zdh-busy"><div class="zdh-spinner"></div><div class="zdh-busy-text">Loading the cluster…</div></div>';
			api('drops/' + ses.id).then(function (d) {
				if (d.status !== 'in_progress') { leaveDelivery(d.status === 'done' ? 'That cluster was already finished — nothing left to deliver.' : 'That cluster is back to planned — start it again from Clusters when you\'re ready.', true); return; }
				D.cluster = d; if (S.tab === 'deliver') { render(); }
			}).catch(function (e) {
				if (e.status === 404) { leaveDelivery('The cluster you had open was deleted — cleared.', true); return; }
				main.innerHTML = '<div class="zdh-error">' + esc(e.message) + '</div><div class="zdh-actions"><button class="zdh-secondary" id="dv-retry">Try again</button><button class="zdh-secondary" id="dv-leave">Stop for now</button></div>';
				document.getElementById('dv-retry').addEventListener('click', function () { render(); });
				document.getElementById('dv-leave').addEventListener('click', function () { leaveDelivery('Stopped for now — resume it from Clusters later.'); });
			});
			return;
		}
		var d = D.cluster;
		var wn = ses.walk || 1, wl = walkLabel(d, wn), mine = walkHouses(d, wn);
		var done = mine.filter(function (a) { return ses.delivered[a.id]; }).length;
		main.innerHTML =
			'<div class="zdh-card zdh-card-tight zdh-deliver">' +
				'<div class="zdh-deliver-head"><div>' + (wl ? '<div class="zdh-walk-tag" style="background:' + walkColor(wn) + '">' + esc(wl) + '</div>' : '') + '<h2>' + esc(d.name || d.streets_summary) + '</h2><div class="zdh-muted">' + esc(d.city) + ' ' + esc(d.zip) + ' &bull; started ' + esc(new Date(ses.started).toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' })) + '<span id="dv-wx">' + (wxText() ? ' · ' + esc(wxText()) : '') + '</span></div></div>' +
					'<div class="zdh-deliver-count"><strong id="dv-done">' + done + '</strong><span>of ' + mine.length + '</span></div></div>' +
				'<div class="zdh-deliver-status" id="dv-status">Finding your position…</div>' +
				'<div class="zdh-maptools">' +
					'<button type="button" class="zdh-tool" id="dv-follow" aria-pressed="' + (D.follow ? 'true' : 'false') + '">Follow me</button>' +
					'<button type="button" class="zdh-tool" id="dv-wake" aria-pressed="' + (D.wake ? 'true' : 'false') + '">' + tx('Screen on', 'Keep screen on') + '</button>' +
					'<button type="button" class="zdh-tool zdh-tool-warn" id="dv-pause" aria-pressed="true">GPS on</button>' +
					'<button type="button" class="zdh-tool" id="dv-stretch" aria-pressed="false" title="Tap the first and the last house of a run to tick every house between them">Mark a stretch</button>' +
					'<button type="button" class="zdh-tool zdh-tool-warn" id="dv-nosol" aria-pressed="false" title="Standing at a house with a No Soliciting sign? Press this: it is marked not delivered and remembered for every future route">🚫 No-Solicit House</button>' +
					'<button type="button" class="zdh-tool" id="dv-pocket" title="Black, touch-proof screen for your pocket — the GPS keeps recording">🌙 Pocket</button>' +
				'</div>' +
				navWrap('dv-map') +
				'<p class="zdh-help" style="margin-top:.6rem">' + tx('Houses turn green as you pass. Tap one to change it. Keep the screen on — a locked iPhone stops the GPS.', 'Houses turn green as you pass them (within ' + (C.autoRadius || 25) + ' m). Tap a house to tick or untick it by hand; <strong>Mark a stretch</strong> ticks a whole run between two houses. Keep the app open with the screen on: an iPhone stops location for web apps the moment the screen locks.') + (wl ? tx(' Grey dots = other walks.', ' The dotted line is the suggested order; grey dots belong to the other walks.') : '') + '</p>' +
			'</div>' +
			actionBar('<button class="zdh-stop" id="dv-finish">■ ' + (wl ? 'Done with this walk' : 'Done delivering') + '</button>');
		buildDeliveryMap();
		document.getElementById('dv-follow').addEventListener('click', function () { setFollow(!D.follow); });
		bindNav(D.map);
		document.getElementById('dv-wake').addEventListener('click', function (e) { toggleWake(e.currentTarget); });
		document.getElementById('dv-pause').addEventListener('click', function (e) { var b = e.currentTarget; if (D.watch != null) { stopGps(); b.textContent = 'GPS paused'; b.setAttribute('aria-pressed', 'false'); setStatus('GPS paused — houses only tick by hand.'); } else { startGps(); b.textContent = 'GPS on'; b.setAttribute('aria-pressed', 'true'); } });
		D.stretch = { on: false, first: null };
		document.getElementById('dv-stretch').addEventListener('click', function (e) { stretchToggle(e.currentTarget); });
		document.getElementById('dv-nosol').addEventListener('click', function () { noSolicitHere('walk'); });
		document.getElementById('dv-finish').addEventListener('click', showFinish);
		document.getElementById('dv-pocket').addEventListener('click', pocketOn);
		startGps();
		requestWake(null);
		if (!D.timer) { D.timer = setInterval(syncProgress, 20000); }
		if (ses.gaps && ses.gaps.length) { var g = ses.gaps[ses.gaps.length - 1]; if (Date.now() - g[1] < 3600000) { showGapNote(g[0], g[1], 'planned'); } } // the last hole in the GPS, while it is recent
	}
	/* the words live in their own span, so the I'm driving / I'm on foot button beside them is not rebuilt on every fix
	   */
	function setStatus(t) {
		var e = document.getElementById('dv-status'); if (!e) { return; }
		var w = e.querySelector('.zdh-status-t');
		if (!w) { e.textContent = ''; w = document.createElement('span'); w.className = 'zdh-status-t'; e.appendChild(w); }
		w.textContent = t + (navigator.onLine === false ? ' · 📵 no signal — still recording' : '');
	}
	/* While the detector says "driving", the walker can overrule it with one tap — and the other way round. */
	function onFootButton(kind) {
		var e = document.getElementById('dv-status'); if (!e || e.querySelector('#dv-onfoot')) { return; }
		var b = el('<button type="button" class="zdh-mini zdh-mini-primary zdh-pace-btn" id="dv-onfoot">I\'m on foot</button>');
		b.addEventListener('click', function () { var st = kind === 'quick' ? quick() : session(); if (!st) { return; } forceWalking(st, kind); setStatus('GPS on — counting again.'); redrawTrack(kind); paceButton(kind, 'walk'); });
		e.appendChild(b);
	}
	function drivingButton(kind) {
		var e = document.getElementById('dv-status'); if (!e || e.querySelector('#dv-driving')) { return; }
		var b = el('<button type="button" class="zdh-mini zdh-pace-btn" id="dv-driving" title="In the car? Tap this so the houses you drive past are not ticked">🚗 I\'m driving</button>');
		b.addEventListener('click', function () { var st = kind === 'quick' ? quick() : session(); if (!st) { return; } forceDriving(st, kind); setStatus('🚗 driving — not ticking houses'); paceButton(kind, 'drive'); });
		e.appendChild(b);
	}
	/* the one override that fits the moment: "I'm on foot" while driving, "I'm driving" while walking */
	function paceButton(kind, pace) {
		var other = document.getElementById(pace === 'drive' ? 'dv-driving' : 'dv-onfoot'); if (other) { other.remove(); }
		if (pace === 'drive') { onFootButton(kind); } else { drivingButton(kind); }
	}
	function buildDeliveryMap() {
		var ses = session(), d = D.cluster;
		if (D.map) { killMap(D.map); }
		D.map = L.map('dv-map', { tap: false }); D.dots = {}; D.me = null; D.acc = null; D.trail = null; D.drive = null; D.tracks = null; D.beam = null;
		tileLayer().addTo(D.map);
		addTextControl(D.map);
		var pts = [];
		var g = d.geometry || {};
		(g.strokes || []).forEach(function (st) { strokeLayer(st).setStyle({ opacity: 0.25, fillOpacity: 0.05 }).addTo(D.map); });
		(g.segments || []).forEach(function (sg) { L.polyline(sg.path, { color: '#7f8c8d', weight: 3, opacity: 0.35 }).addTo(D.map); });
		var wn = ses.walk || 1, multi = walkList(d).length > 1;
		var segPaths = {}; (g.segments || []).forEach(function (sg) { segPaths[sg.id] = sg.path; });
		var nm = navModel(), path = nm ? nm.path : walkPathOf(d, wn); // the order the banner follows
		if (path.length > 1) { L.polyline(path, { color: walkColor(wn), weight: 3, opacity: 0.45, dashArray: '2 7', lineCap: 'round', interactive: false }).addTo(D.map); }
		D.startMk = null; D.zoomed = false;
		if (path.length) { D.startMk = L.circleMarker(path[0], { radius: 10, color: '#fff', weight: 2, fillColor: walkColor(wn), fillOpacity: 1, interactive: false }).bindTooltip('Start here', { permanent: true, direction: 'right', offset: [8, 0], className: 'zdh-start-tip' }).addTo(D.map); }
		d.addresses.forEach(function (a) {
			if (a.lat == null) { return; }
			var other = multi && (+a.walk || 1) !== wn;
			var dot = L.circleMarker([a.lat, a.lng], { radius: other ? 4 : 7, weight: other ? 1 : 1.5, color: '#fff', fillOpacity: other ? 0.7 : 0.95 }).addTo(D.map);
			if (!other) { numberLabel(dot, a.house_number + (a.unit ? '#' + a.unit : ''), labelSide([a.lat, a.lng], segPaths[a.road_seg_id] || null)); } else { dot.bindTooltip(a.house_number + ' ' + a.street + ' (walk ' + a.walk + ')', { direction: 'top', offset: [0, -6] }); }
			dot.on('click', function (e) { L.DomEvent.stop(e); if (a.no_solicit) { toast('No Soliciting sign noted at ' + (a.full_address || (a.house_number + ' ' + a.street)) + ' — this house is never ticked.', 5000); return; } toggleHouse(a.id); });
			dot._other = other; dot._nosol = !!a.no_solicit;
			if (a.no_solicit) { dot.bindTooltip('No Soliciting — not delivered', { direction: 'top' }); }
			D.dots[a.id] = dot;
			if (!other) { pts.push([a.lat, a.lng]); }
		});
		if (ses.track && ses.track.length) { drawTrack(D, ses.track, 'planned'); } // walked solid, driven dashed
		if (pts.length) { D.map.fitBounds(L.latLngBounds(pts).pad(0.15)); }
		settle(D.map);
		paintDelivery();
	}
	function paintDelivery() {
		var ses = session(); if (!ses) { return; }
		Object.keys(D.dots).forEach(function (id) { var on = !!ses.delivered[id], dot = D.dots[id]; if (dot._nosol || (ses.nosolicit && ses.nosolicit[id])) { dot.setStyle({ fillColor: '#111', color: '#c0392b', weight: 3, fillOpacity: 1 }); return; } dot.setStyle(dot._other ? { fillColor: on ? '#8fd3a9' : '#b8bec8', fillOpacity: 0.7 } : { fillColor: on ? '#27ae60' : '#c0392b', fillOpacity: on ? 0.95 : 0.85 }); });
		var c = document.getElementById('dv-done'); if (c) { var wn = ses.walk || 1; c.textContent = D.cluster ? walkHouses(D.cluster, wn).filter(function (a) { return ses.delivered[a.id]; }).length : Object.keys(ses.delivered).length; }
		paintNav();
	}
	function toggleHouse(id) {
		if (D.stretch && D.stretch.on) { return stretchTap(id); }
		var ses = session(); if (!ses) { return; }
		if (ses.delivered[id]) {
			var tickedAt = +ses.delivered[id] || 0;
			delete ses.delivered[id]; ses.undel[id] = 1; delete ses.pending[id];
			// an untick is a skip the banner remembers (it does not send the walker back for it) — unless it comes
			// within 30 s of the tick, which is a correction. ses.skip outlives the sync that empties ses.undel.
			if (Date.now() - tickedAt > 30000) { ses.skip = ses.skip || {}; ses.skip[id] = 1; }
		}
		else { ses.delivered[id] = Date.now(); ses.pending[id] = 1; delete ses.undel[id]; if (ses.skip) { delete ses.skip[id]; } }
		ses.lastActive = Date.now();
		saveSession(ses); paintDelivery();
	}
	/* ---- Mark a stretch: tap the first and the last house of a run and every house between them is ticked —
	   for a block walked while the phone was locked, or GPS that never settled. "Between" follows the walk order when
	   the cluster has one, otherwise the house numbers on that street (one side only when both taps share a parity). */
	function stretchToggle(btn) {
		D.stretch = D.stretch && D.stretch.on ? { on: false, first: null } : { on: true, first: null };
		var b = btn || document.getElementById('dv-stretch'); if (b) { b.setAttribute('aria-pressed', D.stretch.on ? 'true' : 'false'); }
		stretchPaint();
		setStatus(D.stretch.on ? 'Mark a stretch: tap the FIRST house of the run.' : 'GPS on — houses tick as you pass them.');
		if (!D.stretch.on) { var h = document.getElementById('dv-stretch-hint'); if (h) { h.remove(); } }
	}
	function stretchPaint() {
		Object.keys(D.dots).forEach(function (id) { var dot = D.dots[id]; if (D.stretch && D.stretch.first === +id) { dot.setStyle({ color: '#F2C200', weight: 4 }); } else { dot.setStyle({ color: '#fff', weight: dot._other ? 1 : 1.5 }); } });
	}
	function stretchHouses(a, b) {
		var d = D.cluster, ses = session(); if (!d || !ses) { return []; }
		var wn = ses.walk || 1, multi = walkList(d).length > 1;
		var pool = d.addresses.filter(function (x) { return !multi || (+x.walk || 1) === wn; });
		if (a.street_key === b.street_key) {
			// the same street: the house numbers between the two, one side only when both taps share a parity
			var n1 = +a.house_number || 0, n2 = +b.house_number || 0, nlo = Math.min(n1, n2), nhi = Math.max(n1, n2), par = (n1 % 2) === (n2 % 2) ? n1 % 2 : -1;
			return pool.filter(function (x) { var n = +x.house_number || 0; return x.street_key === a.street_key && n >= nlo && n <= nhi && (par < 0 || n % 2 === par); });
		}
		if (a.walk_order > 0 && b.walk_order > 0 && (+a.walk || 1) === (+b.walk || 1)) {
			// round a corner: everything between the two in the walk's order
			var lo = Math.min(a.walk_order, b.walk_order), hi = Math.max(a.walk_order, b.walk_order);
			return pool.filter(function (x) { return x.walk_order >= lo && x.walk_order <= hi; });
		}
		return null;
	}
	function stretchTap(id) {
		var d = D.cluster, ses = session(); if (!d || !ses) { return; }
		var byId = {}; d.addresses.forEach(function (x) { byId[x.id] = x; });
		var house = byId[id]; if (!house) { return; }
		if (D.stretch.first == null) { D.stretch.first = +id; stretchPaint(); setStatus('First: ' + house.house_number + ' ' + house.street + '. Now tap the LAST house of the run.'); return; }
		var first = byId[D.stretch.first];
		if (first.id === house.id) { return; }
		var list = stretchHouses(first, house);
		if (list === null) { toast('Tap two houses on the same street (or, to go round a corner, two houses of the same walk).', 6000); return; }
		var label = first.house_number + ' ' + first.street + ' → ' + house.house_number + ' ' + house.street;
		var apply = function () {
			var s1 = session(); if (!s1) { return; }
			var added = [];
			list.forEach(function (x) { if (!s1.delivered[x.id]) { s1.delivered[x.id] = Date.now(); s1.pending[x.id] = 1; delete s1.undel[x.id]; added.push(x.id); } });
			s1.lastActive = Date.now(); saveSession(s1); paintDelivery();
			logEv('stretch', label + ': +' + added.length + ' of ' + list.length);
			toastAction(added.length + ' house' + (added.length === 1 ? '' : 's') + ' marked delivered (' + label + ').', 'Undo', function () {
				var s2 = session(); if (!s2) { return; }
				added.forEach(function (hid) { delete s2.delivered[hid]; delete s2.pending[hid]; s2.undel[hid] = 1; });
				saveSession(s2); paintDelivery(); logEv('stretch.undo', '-' + added.length);
			});
		};
		D.stretch = { on: false, first: null }; var b = document.getElementById('dv-stretch'); if (b) { b.setAttribute('aria-pressed', 'false'); }
		stretchPaint(); setStatus('GPS on — houses tick as you pass them.');
		if (list.length > 80) { askSheet({ title: 'Mark ' + list.length + ' houses?', html: 'That is every house between ' + esc(label) + ' in the order of this walk. Tap two houses on the same street to mark just that street.', primary: 'Mark them', onPrimary: apply, secondary: 'Cancel' }); return; }
		apply();
	}
	function markNear(lat, lng, acc) {
		var ses = session(); if (!ses || !D.cluster) { return 0; }
		var radius = C.autoRadius || 25;
		if (acc > 45) { return 0; } // don't guess with a poor fix
		var changed = 0;
		var wn = ses.walk || 1, multi = walkList(D.cluster).length > 1;
		D.cluster.addresses.forEach(function (a) {
			if (a.lat == null || ses.delivered[a.id]) { return; }
			if (a.no_solicit || (ses.nosolicit && ses.nosolicit[a.id])) { return; } // a No Soliciting house never ticks by GPS
			if (multi && (+a.walk || 1) !== wn) { return; } // the other walks' houses only tick by hand
			if (distM([lat, lng], [a.lat, a.lng]) <= radius) { ses.delivered[a.id] = Date.now(); ses.pending[a.id] = 1; delete ses.undel[a.id]; changed++; }
		});
		if (changed) { saveSession(ses); paintDelivery(); }
		return changed;
	}
	/* GPS fixes are summarised for the log every 60 fixes / 5 minutes: count, accuracy range, poor fixes, auto-ticks. */
	var FIX = { n: 0, poor: 0, best: Infinity, worst: 0, ticks: 0, since: 0 };
	function fixNote(pos, ticks) {
		var acc = Math.round(pos.coords.accuracy || 0);
		if (!FIX.since) { FIX.since = Date.now(); }
		FIX.n++; if (acc > 45) { FIX.poor++; } if (acc < FIX.best) { FIX.best = acc; } if (acc > FIX.worst) { FIX.worst = acc; } FIX.ticks += ticks || 0;
		if (FIX.n >= 60 || Date.now() - FIX.since > 300000) { flushFixNote(); }
	}
	function flushFixNote() {
		if (!FIX.n) { return; }
		logEv('gps.fixes', FIX.n + ' fixes in ' + Math.round((Date.now() - FIX.since) / 1000) + ' s, ±' + FIX.best + '–' + FIX.worst + ' m, ' + FIX.poor + ' poor, ' + FIX.ticks + ' auto-ticks');
		FIX = { n: 0, poor: 0, best: Infinity, worst: 0, ticks: 0, since: 0 };
	}
	/* A hole in the fixes (phone locked, app in the background, no GPS): remembered on the walk and shown once. */
	function noteGap(state, kind, now) {
		var last = state.lastFixAt || 0;
		state.lastFixAt = now;
		if (!last || now - last < 3 * 60000) { return; }
		state.gaps = state.gaps || []; state.gaps.push([last, now]);
		if (state.gaps.length > 40) { state.gaps = state.gaps.slice(-40); }
		logEv('gps.gap', Math.round((now - last) / 60000) + ' min (' + fmtClock(last) + '–' + fmtClock(now) + ')' + (kind === 'quick' ? ' quick' : ''));
		showGapNote(last, now, kind);
	}
	function showGapNote(from, to, kind) {
		var host = document.getElementById(kind === 'quick' ? 'qv-map' : 'dv-map'); if (!host) { return; }
		var old = document.getElementById('zdh-gap'); if (old) { old.remove(); }
		var min = Math.round((to - from) / 60000);
		var n = el('<div class="zdh-gap" id="zdh-gap"><strong>GPS was off for ' + min + ' min</strong> (' + esc(fmtClock(from)) + '–' + esc(fmtClock(to)) + ' — phone locked or app in the background). ' +
			(kind === 'quick' ? 'Nothing was recorded for that stretch — when you tap Done, the houses around it are shown in grey so you can add the ones you did.' : 'Houses you passed then were <em>not</em> ticked: tap them, or use <strong>Mark a stretch</strong> to tick a run of houses at once.') +
			' <button type="button" class="zdh-mini" id="zdh-gap-ok">OK</button></div>');
		host.parentNode.insertBefore(n, host);
		n.querySelector('#zdh-gap-ok').addEventListener('click', function () { n.remove(); });
	}
	function startGps() {
		if (!navigator.geolocation) { setStatus('This device has no GPS access — tick houses by hand.'); logEv('gps', 'no geolocation'); return; }
		stopGps();
		if (recall('loc') === 'denied') { setStatusDenied(); }
		logEv('gps.start', S.tab);
		D.watch = navigator.geolocation.watchPosition(onFix, function (err) {
			logEv('gps.error', err.code + ' ' + (err.message || ''));
			if (err.code === 1) { store('loc', 'denied'); setStatusDenied(); return; }
			setStatus(err.code === 2 ? 'No GPS signal yet — houses can be ticked by hand meanwhile.' : 'Waiting for GPS…');
			if (S.tab === 'deliver' || S.tab === 'quick') { clearTimeout(D.retry); D.retry = setTimeout(startGps, 5000); }
		}, { enableHighAccuracy: true, maximumAge: 2000, timeout: 20000 });
	}
	function setStatusDenied() {
		var e = document.getElementById('dv-status'); if (!e) { return; }
		e.innerHTML = 'Location is blocked for this site — houses only tick by hand. <button type="button" class="zdh-linkbtn" id="dv-lochelp">How to turn it on</button>';
		var b = document.getElementById('dv-lochelp'); if (b) { b.addEventListener('click', openLocationHelp); }
	}
	function stopGps() { if (D.watch != null && navigator.geolocation) { navigator.geolocation.clearWatch(D.watch); logEv('gps.stop'); flushFixNote(); } D.watch = null; clearTimeout(D.retry); }
	function onFix(pos) {
		if (quick() && !session()) { return quickFix(pos); }
		var lat = pos.coords.latitude, lng = pos.coords.longitude, acc = Math.round(pos.coords.accuracy || 0);
		var ll = [lat, lng], now = Date.now();
		D.lastFix = ll; D.lastAcc = acc; D.lastFixAt = now;
		if (recall('loc') !== 'ok') { store('loc', 'ok'); }
		var was = PACE.state, pace = paceUpdate(lat, lng, now, acc, gpsSpeed(pos));
		weatherFor(ll);
		setStatus('GPS ±' + acc + ' m' + (acc > 45 ? ' — weak signal, auto-tick paused' : '') + (pace === 'drive' ? ' — 🚗 driving, not ticking houses' : ''));
		paceButton('planned', pace);
		var ses = session(), ticked = 0;
		if (ses) {
			ses.lastActive = now;
			noteGap(ses, 'planned', now);
			var last = ses.track.length ? ses.track[ses.track.length - 1] : null, pushed = false, jump = false;
			if (!last || distM([last[0], last[1]], ll) > 4 || now - last[2] > 15000) {
				var pt = [+lat.toFixed(6), +lng.toFixed(6), now, acc]; if (pace === 'drive') { pt.push('d'); }
				ses.track.push(pt);
				if (ses.track.length > 6000) { ses.track = ses.track.slice(-6000); }
				jump = !!(last && (distM([last[0], last[1]], ll) > 150 || now - last[2] > 300000)); pushed = true;
			}
			if (pace === 'drive' && PACE.driveFrom) { backdateDriving(ses.track, PACE.driveFrom); }
			if (was === 'drive' && pace === 'walk') { closeForced(ses, now); }
			saveSession(ses);
			if (D.map) { if (was !== pace) { drawTrack(D, ses.track, 'planned'); } else if (pushed) { growTrack(D, 'planned', ll, pace, jump); } } // driven stretches dashed, live
			if (pace !== 'drive') { ticked = markNear(lat, lng, acc); }
		}
		fixNote(pos, ticked);
		paintNav();
		var hd = navHeading(pos, NV.last);
		if (D.map) {
			drawMe(D, ll, acc, hd);
			// the first fix zooms to walking level; the "Start here" label goes once the walker is at the start
			if (!D.zoomed && acc <= 60) { D.zoomed = true; try { if (D.map.getZoom() < 16) { D.map.setZoom(17, { animate: false }); } } catch (e) {} }
			if (D.startMk && distM(ll, [D.startMk.getLatLng().lat, D.startMk.getLatLng().lng]) < 40) { try { D.startMk.unbindTooltip(); } catch (e) {} D.startMk = null; }
			if (D.follow) { followCam(D.map, ll); }
		}
		if (ses) { askIfDone(ses, 'planned'); }
	}

	/* ===================================================== NAVIGATION VIEW
	   A walking view like a phone maps app's. While a walk runs, a quiet banner on
	   top of the map says what comes next and how far it is ("Turn left onto Example Rd · 120 ft", "Next block · 14 houses,
	   both sides", "End of route · 0.3 mi") with a "Then …" line under it and a thin progress strip; the map follows the
	   walker with more of what is ahead in view (the dot in the lower third, toward the direction of travel); "Ahead"
	   flies to the next turn and eases back; a drag leaves follow mode until Re-center. North stays up: Leaflet cannot
	   rotate, and a rotating map would be a project of its own. The maths is assets/nav.js.
	   Quick Start has no plan, so its banner is the street, the side being logged, this block's houses, and the nearest
	   block not yet walked. A spoken cue exists only in pocket mode, only when the site turns it on (off by default). */
	var NAVM = window.ZDH_NAV || null;
	var NV = { model: null, key: '', hint: null, head: null, move: null, headFrom: null, peek: null, peekT: null, peekZoom: null, said: '', last: null, html: '', sr: '', unlocked: false };
	function navReset() { clearTimeout(NV.peekT); NV = { model: null, key: '', hint: null, head: null, move: null, headFrom: null, peek: null, peekT: null, peekZoom: null, said: '', last: null, html: '', sr: '', unlocked: NV.unlocked }; }
	/* The route: this walk's houses in walk order. A walk with no order (a route made from chat, which names streets only)
	   is ordered here with the planner's own planWalks, so it still has a next turn; the order is not saved. */
	function navModelFor(d, wn) {
		var hs = walkHouses(d, wn).filter(function (a) { return a.lat != null && a.lng != null; });
		var segs = ((d.geometry || {}).segments || []).filter(function (s) { return s && s.path && s.path.length > 1; });
		var segPaths = {}; segs.forEach(function (s) { if (s.id != null) { segPaths[s.id] = s.path; } });
		var ordered = hs.some(function (a) { return (+a.walk_order || 0) > 0; }), list = hs, derived = false;
		if (ordered || hs.length < 3) { list = hs.slice().sort(function (a, b) { return (a.walk_order || 0) - (b.walk_order || 0); }); }
		else {
			try {
				var byId = {}; hs.forEach(function (a) { byId[a.id] = a; });
				var W = planWalks(hs.map(function (a) { return { k: a.id, lat: a.lat, lng: a.lng, seg: a.road_seg_id, street: a.street, street_key: a.street_key }; }), segs, { size: 500, style: C.routeStyle, perHour: +C.walkRate || 40 });
				var out = []; W.walks.forEach(function (w) { w.keys.forEach(function (k) { if (byId[k]) { out.push(byId[k]); } }); });
				if (out.length === hs.length) { list = out; derived = true; }
			} catch (e) { logEv('nav', 'order failed: ' + e.message); }
		}
		var M = NAVM.model(list.map(function (a) { return { id: a.id, lat: +a.lat, lng: +a.lng, street: a.street, key: a.street_key, seg: a.road_seg_id, number: a.house_number }; }), segPaths);
		M.derived = derived;
		return M;
	}
	function navModel() {
		var ses = session(), d = D.cluster; if (!NAVM || !ses || !d || d.id !== ses.id) { return null; }
		var key = d.id + ':' + (ses.walk || 1);
		if (NV.key !== key || !NV.model) { NV.model = navModelFor(d, ses.walk || 1); NV.key = key; NV.hint = null; NV.said = ''; }
		return NV.model;
	}
	function routeBanner() {
		var M = navModel(), ses = session(); if (!M) { return null; }
		var stale = !D.lastFixAt || Date.now() - D.lastFixAt > 120000;
		var skip = {}; (D.cluster.addresses || []).forEach(function (a) { if (a.no_solicit) { skip[a.id] = 1; } }); Object.keys(ses.nosolicit || {}).forEach(function (k) { skip[k] = 1; });
		Object.keys(ses.skip || {}).forEach(function (k) { skip[k] = 1; }); // unticked on purpose
		var b = NAVM.banner(M, stale ? null : D.lastFix, NV.hint, ses.delivered, skip, ses.skip || {});
		if (b.snap && b.mode === 'route') { NV.hint = b.snap.i; }
		if (b.mode === 'join' && b.target && D.lastFix) { b.rot = NAVM.bearing(D.lastFix, b.target); }
		return b;
	}
	/* ---- Quick Start: the mapped houses and streets around the walker, fetched as the walk goes (find-in-area with an
	   envelope of about 650 m, again when the walker nears its edge; never while driving, at most every 30 s) */
	var QN = { ctx: null, env: null, busy: false, at: 0, of: 0 };
	function quickNavReset() { QN = { ctx: null, env: null, busy: false, at: 0, of: 0, ticket: (QN && QN.ticket || 0) + 1 }; }
	function quickNearby(ll, acc, pace) {
		var q = quick(); if (!NAVM || !q || q.ended || pace === 'drive' || acc > 30 || navigator.onLine === false || QN.busy) { return; }
		if (QN.of !== q.started) { quickNavReset(); QN.of = q.started; }
		if (QN.env) {
			var inside = ll[0] > QN.env.ymin && ll[0] < QN.env.ymax && ll[1] > QN.env.xmin && ll[1] < QN.env.xmax;
			var mx = 111320 * Math.cos(ll[0] * Math.PI / 180); // metres per degree of longitude here
			var edge = Math.min((QN.env.ymax - ll[0]) * 110540, (ll[0] - QN.env.ymin) * 110540, (QN.env.xmax - ll[1]) * mx, (ll[1] - QN.env.xmin) * mx);
			if (inside && edge > 120) { return; }
		}
		if (Date.now() - QN.at < 30000) { return; }
		var env = { ymin: ll[0] - 0.003, ymax: ll[0] + 0.003, xmin: ll[1] - 0.0036, xmax: ll[1] + 0.0036 }, of = q.started;
		var ticket = QN.ticket = (QN.ticket || 0) + 1;
		QN.busy = true; QN.at = Date.now();
		var gaveUp = setTimeout(function () { if (QN.ticket === ticket) { QN.busy = false; QN.ticket++; logEv('nav.nearby', 'no answer in 25 s'); } }, 25000); // a hung request must not stop the lookups
		api('find-in-area', { method: 'POST', body: { envelope: env } }).then(function (r) {
			clearTimeout(gaveUp); if (QN.ticket !== ticket) { return; } // given up on, or a newer walk
			QN.busy = false;
			var q2 = quick(); if (!q2 || q2.ended || q2.started !== of) { return; }
			var ctx = {
				segments: (r.segments || []).filter(function (s) { return s && s.path && s.path.length > 1; }).map(function (s) { return { id: s.id, name: s.name, path: s.path }; }),
				addresses: (r.addresses || []).filter(function (a) { return a && a.lat != null && a.seg != null; }).map(function (a) { return { lat: +a.lat, lng: +a.lng, seg: a.seg, street: a.street, number: a.number }; })
			};
			(q2.track || []).forEach(function (t) { // what this walk has already passed
				if (t[4] === 'd' || (t[3] != null && t[3] > 40)) { return; }
				if (t[0] < env.ymin - 0.0005 || t[0] > env.ymax + 0.0005 || t[1] < env.xmin - 0.0006 || t[1] > env.xmax + 0.0006) { return; }
				NAVM.markCovered(ctx, [t[0], t[1]], 35);
			});
			QN.ctx = ctx; QN.env = env; QN.of = of;
			logEv('nav.nearby', ctx.segments.length + ' streets, ' + ctx.addresses.length + ' houses');
			paintNav();
		}).catch(function (e) { clearTimeout(gaveUp); if (QN.ticket === ticket) { QN.busy = false; } logEv('nav.nearby', 'failed: ' + e.message); });
	}
	function quickBanner() {
		var q = quick(); if (!NAVM || !q || q.ended) { return null; }
		if (!QN.ctx || QN.of !== q.started) { return QN.busy ? { mode: 'wait', type: 'wait', main: 'Finding the streets around you…', detail: '' } : null; }
		var c = D.lastFix ? NAVM.quickContext(QN.ctx, D.lastFix) : null; if (!c) { return null; }
		var b = { mode: 'quick', type: 'walk', main: c.street ? c.street + (c.side ? ' · ' + c.side : '') : 'Walking', detail: c.here ? c.here + ' house' + (c.here === 1 ? '' : 's') + ' on this side of the block · ' + c.hereDone + ' passed' : '', dist: '', then: null, target: null, totalN: 0 };
		if (c.next) { b.then = { type: 'head', rot: NAVM.bearing(D.lastFix, c.next.target), text: 'Next block not walked: ' + c.next.street + ' (' + c.next.side + ') · ' + c.next.dist + ' ' + c.next.dir }; b.target = c.next.target; }
		return b;
	}
	/* ---- the banner */
	var NAV_PATHS = {
		straight: '<path d="M12 20V5"/><path d="M6 11l6-6 6 6"/>',
		left: '<path d="M17 20v-6a5 5 0 0 0-5-5H5"/><path d="M9 5L5 9l4 4"/>',
		right: '<path d="M7 20v-6a5 5 0 0 1 5-5h7"/><path d="M15 5l4 4-4 4"/>',
		'slight-left': '<path d="M15 20v-7L8 6"/><path d="M8 12V6h6"/>',
		'slight-right': '<path d="M9 20v-7l7-7"/><path d="M10 6h6v6"/>',
		back: '<path d="M8 20V10a4 4 0 0 1 8 0v4"/><path d="M12 11l4 4 4-4"/>',
		end: '<path d="M6 21V4"/><path d="M6 4h11l-2.5 4L17 12H6"/>',
		head: '<path d="M12 3l6.5 17L12 16l-6.5 4z"/>',
		walk: '<circle cx="13" cy="4.5" r="1.8"/><path d="M13 8l-1.5 6 3 3v4"/><path d="M11.5 14L9 21"/><path d="M8.5 11.5L12 8l3.5 3"/>',
		wait: '<circle cx="6" cy="12" r="1.2"/><circle cx="12" cy="12" r="1.2"/><circle cx="18" cy="12" r="1.2"/>',
		done: '<path d="M4 12.5l5 5L20 6.5"/>'
	};
	NAV_PATHS['next-block'] = NAV_PATHS.straight; NAV_PATHS.uturn = NAV_PATHS.back;
	function navIcon(type, rot, cls) {
		return '<svg class="' + (cls || 'zdh-nav-svg') + '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"' + (rot != null ? ' style="transform:rotate(' + Math.round(rot) + 'deg)"' : '') + '>' + (NAV_PATHS[type] || NAV_PATHS.straight) + '</svg>';
	}
	var ICON_AHEAD = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12z"/><circle cx="12" cy="12" r="2.8"/></svg>';
	var ICON_CENTER = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="3.5"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3"/><circle cx="12" cy="12" r="7.5"/></svg>';
	function navHtml(b) {
		var pct = b.totalN ? Math.round(b.doneN / b.totalN * 100) : null;
		return '<div class="zdh-nav-main">' +
				'<div class="zdh-nav-icon">' + navIcon(b.type, b.rot) + (b.dist ? '<span class="zdh-nav-dist">' + esc(b.dist) + '</span>' : '') + '</div>' +
				'<div class="zdh-nav-text"><div class="zdh-nav-what">' + esc(b.main) + '</div>' + (b.detail ? '<div class="zdh-nav-detail">' + esc(b.detail) + '</div>' : '') + '</div>' +
				(b.target ? '<button type="button" class="zdh-nav-ahead" data-nav="ahead" title="Zoom ahead to ' + (b.mode === 'quick' ? 'that block' : 'the next turn') + ', then back to you">' + ICON_AHEAD + '<span>Ahead</span></button>' : '') +
			'</div>' +
			(b.then ? '<div class="zdh-nav-then"><span class="zdh-nav-then-l">' + (b.mode === 'quick' ? '' : 'Then') + '</span>' + navIcon(b.then.type, b.then.rot, 'zdh-nav-svg-s') + '<span class="zdh-nav-then-t">' + esc(b.then.text) + (b.then.detail ? ' <span class="zdh-nav-then-d">· ' + esc(b.then.detail) + '</span>' : '') + '</span></div>' : '') +
			(pct != null ? '<div class="zdh-nav-progress" role="progressbar" aria-label="Houses done on this walk" aria-valuemin="0" aria-valuemax="' + b.totalN + '" aria-valuenow="' + b.doneN + '"><i style="width:' + pct + '%"></i></div>' : '');
	}
	/* Rebuild the banner only when its shape changes (Ahead or a Then line appears or goes); otherwise rewrite its words and
	   the progress strip in place, so the Ahead button under a finger is the same button when the next fix lands. */
	function navPaint(host, b) {
		var shape = b.mode + (b.target ? 'A' : '') + (b.then ? 'T' : '') + (b.totalN ? 'P' : '');
		if (NV.html !== shape || !host.querySelector('.zdh-nav-main')) { host.innerHTML = navHtml(b); NV.html = shape; return; }
		var tmp = document.createElement('div'); tmp.innerHTML = navHtml(b);
		['.zdh-nav-icon', '.zdh-nav-text', '.zdh-nav-then'].forEach(function (sel) {
			var a = host.querySelector(sel), n = tmp.querySelector(sel);
			if (a && n && a.innerHTML !== n.innerHTML) { a.innerHTML = n.innerHTML; }
		});
		var bar = host.querySelector('.zdh-nav-progress'), nb = tmp.querySelector('.zdh-nav-progress');
		if (bar && nb) { bar.setAttribute('aria-valuenow', nb.getAttribute('aria-valuenow')); bar.setAttribute('aria-valuemax', nb.getAttribute('aria-valuemax')); bar.firstChild.style.width = nb.firstChild.style.width; }
	}
	function paintNav() {
		var host = document.getElementById('dv-nav'); if (!host || !NAVM) { return; }
		var b = S.tab === 'quick' ? quickBanner() : (S.tab === 'deliver' ? routeBanner() : null);
		NV.last = b;
		if (!b) { if (!host.hidden) { host.hidden = true; NV.html = ''; } navOffset(); return; }
		navPaint(host, b);
		host.hidden = false;
		var sr = document.getElementById('dv-nav-sr'), line = b.main + (b.dist ? ', ' + b.dist : '');
		if (sr && b.main !== NV.sr) { NV.sr = b.main; sr.textContent = line; } // a screen reader hears a new instruction, not every metre
		navOffset();
		voiceCue(b);
		pocketNav(b);
	}
	/* Leaflet's corner buttons (zoom, Aa) move below the banner. */
	function navOffset() {
		var host = document.getElementById('dv-nav'), wrap = host ? host.parentNode : null; if (!wrap || !wrap.style) { return; }
		wrap.style.setProperty('--zdh-nav-h', (host.hidden ? 0 : (host.offsetHeight || 0) + 8) + 'px');
	}
	function bindNav(map) {
		var host = document.getElementById('dv-nav'), rc = document.getElementById('dv-recenter');
		NV.html = ''; NV.sr = ''; // a fresh, empty banner: paint it in full next time
		if (host) { host.addEventListener('click', function (e) { var t = e.target && e.target.closest ? e.target.closest('[data-nav="ahead"]') : null; if (t) { zoomAhead(); } }); }
		if (rc) { rc.innerHTML = ICON_CENTER + '<span>Re-center</span>'; rc.addEventListener('click', function () { setFollow(true); }); rc.hidden = !!D.follow; }
		if (map && map.on) {
			map.on('dragstart', function () { endPeek(true); if (D.follow) { setFollow(false); logEv('nav', 'drag: follow off'); } });
			map.on('click', function () { if (NV.peek) { endPeek(); } });
		}
	}
	/* ---- the camera */
	function curMap() { return S.tab === 'quick' ? Q.map : D.map; }
	function setFollow(on) {
		D.follow = !!on;
		var b = document.getElementById('dv-follow'); if (b) { b.setAttribute('aria-pressed', D.follow ? 'true' : 'false'); }
		var rc = document.getElementById('dv-recenter'); if (rc) { rc.hidden = D.follow; }
		if (D.follow) { endPeek(true); if (D.lastFix) { followCam(curMap(), D.lastFix); } }
	}
	/* the way the walker is going: the route's own heading on a planned walk (steady), else the phone's heading when it is
	   moving, else the bearing of the last 12 m walked */
	/* Two headings: the camera looks along the route (steady, and the way the walker is meant to go); the beam on the dot
	   shows the way the walker is actually going (the phone's heading while moving, else the bearing of the last 12 m). */
	function navHeading(pos, b) {
		if (!NAVM) { return null; } // nav.js did not load: the walk view still works, without the banner
		var mv = null;
		if (D.lastFix) {
			var sp = gpsSpeed(pos), hd = pos && pos.coords ? pos.coords.heading : null;
			if (sp != null && sp > 0.6 && hd != null && isFinite(hd) && hd >= 0) { mv = +hd; }
			else if (!NV.headFrom) { NV.headFrom = D.lastFix; }
			else if (NAVM.distM(NV.headFrom, D.lastFix) >= 12) { mv = NAVM.bearing(NV.headFrom, D.lastFix); NV.headFrom = D.lastFix; }
		}
		if (mv != null) { NV.move = NAVM.blendHeading(NV.move, mv, 0.5); }
		var cam = b && b.mode === 'route' && NV.model ? NAVM.routeHeading(NV.model, b.snap) : null;
		NV.head = cam != null ? NAVM.blendHeading(NV.head, cam, 0.5) : NV.move;
		return NV.move;
	}
	/* the banner covers the top of the map: the camera works in the part below it */
	function navInset() { var host = document.getElementById('dv-nav'); return host && !host.hidden ? (host.offsetHeight || 0) + 8 : 0; }
	/* the map centre that puts a point at the middle of the visible map, moved by off (px) */
	function centreFor(map, ll, z, off) {
		var top = navInset();
		return map.unproject(map.project(L.latLng(ll[0], ll[1]), z).add(L.point(off[0], off[1] - top / 2)), z);
	}
	function followCam(map, ll, zoom) {
		if (!map || !ll || NV.peek || !NAVM) { if (map && ll && !NAVM) { map.panTo(ll, { animate: true }); } return; }
		try {
			var z = zoom != null ? zoom : map.getZoom(), sz = map.getSize(), off = NAVM.lookAhead(NV.head, sz.x, Math.max(120, sz.y - navInset()));
			var c = centreFor(map, ll, z, off);
			if (zoom != null && zoom !== map.getZoom()) { map.flyTo(c, z, { duration: 0.7 }); } else { map.panTo(c, { animate: true, duration: 0.5 }); }
		} catch (e) { try { map.panTo(ll); } catch (x) {} }
	}
	function zoomAhead() {
		var m = curMap(), b = NV.last; if (!m || !b || !b.target) { return; }
		clearTimeout(NV.peekT);
		if (!NV.peek) { NV.peekZoom = m.getZoom(); }
		NV.peek = Date.now();
		var z = Math.max(m.getZoom(), 18);
		try { m.flyTo(centreFor(m, b.target, z, [0, 0]), z, { duration: 0.8 }); } catch (e) { try { m.setView(b.target, 18); } catch (x) {} }
		NV.peekT = setTimeout(function () { endPeek(); }, 6000);
		logEv('nav', 'zoom ahead');
	}
	function endPeek(stay) {
		clearTimeout(NV.peekT); var was = NV.peek, z = NV.peekZoom; NV.peek = null; NV.peekZoom = null;
		if (was && !stay && D.follow && D.lastFix) { followCam(curMap(), D.lastFix, z); }
	}
	/* ---- pocket mode: one dim line, and the spoken cue (site setting, then this phone's switch; both off by default) */
	function voiceOn() { return !!C.navVoice && recall('voice') === '1' && typeof window.speechSynthesis !== 'undefined' && typeof window.SpeechSynthesisUtterance !== 'undefined'; }
	function speak(t) { try { var u = new window.SpeechSynthesisUtterance(t); window.speechSynthesis.cancel(); window.speechSynthesis.speak(u); logEv('nav.say', t); } catch (e) {} }
	function voiceCue(b) {
		if (!voiceOn() || !document.getElementById('zdh-pocket') || !b || b.mode !== 'route' || !b.say || !(b.distM <= 61)) { return; }
		var key = NV.key + ':' + b.step + ':' + b.type;
		if (NV.said === key) { return; }
		NV.said = key; speak(b.say); // once per step, 200 ft or less before it
	}
	function pocketNav(b) {
		var e = document.getElementById('pk-nav'); if (!e) { return; }
		e.textContent = b && b.mode !== 'wait' ? b.main + (b.dist ? ' · ' + b.dist : '') : '';
	}

	/* ------------------------------------------------ pace: walking vs. driving
	   Speed is averaged over the last 30 s of usable fixes. Faster than 5 mph for 12 s → driving, and the change is
	   backdated to the moment the pace picked up; slower than 2.6 mph for 20 s → walking again. While driving nothing
	   is counted (no auto-ticks, no houses tabulated), even if tracking stays on. */
	/* speed is the straight-line displacement over the last 12–25 s of good fixes (±15 m or better), not
	   fix-to-fix — GPS jitter of 10 m between two fixes a second apart reads as 20 mph but averages out over 20 s.
	   Driving = over 6 mph for 15 s; walking again = under 4.5 mph for 15 s (a brisk walk is 3.5–4 mph, so a walker
	   always gets back; the old 2.6 mph exit was below a walker's pace and once tripped, "driving" never ended).
	   A hole of 45 s or more in the fixes empties the window: the first fixes after a phone unlock are noisy. */
	/* the limits are settings (Zorderz → Doorhangers → Walking vs. driving), today's
	   values the defaults, so a field test can tune them without a release. When the phone reports its own speed
	   (coords.speed, from the GPS's Doppler shift: steadier than distance between fixes) the median of the last 10 s of
	   it is the pace; a phone that gives none keeps the windowed-distance method above. "I'm driving" (the reverse of
	   "I'm on foot") holds driving until the walker is moving at walking pace for a minute, or taps "I'm on foot". */
	function paceLimits() {
		var p = (C.pace && typeof C.pace === 'object') ? C.pace : {};
		function n(v, d) { v = parseFloat(v); return isFinite(v) && v > 0 ? v : d; }
		var L0 = { driveMph: n(p.driveMph, 6), driveS: n(p.driveS, 15), walkMph: n(p.walkMph, 4.5), walkS: n(p.walkS, 15), accM: n(p.accM, 15), jumpM: n(p.jumpM, 300) };
		if (L0.walkMph >= L0.driveMph) { L0.walkMph = Math.max(1, L0.driveMph - 0.5); } // never a band where both are true
		return L0;
	}
	var PL = paceLimits();
	var PACE = { state: 'walk', win: [], spd: [], last: null, fastSince: null, slowSince: null, driveFrom: null, mph: 0, src: '', forced: false };
	function paceReset() { PACE = { state: 'walk', win: [], spd: [], last: null, fastSince: null, slowSince: null, driveFrom: null, mph: 0, src: '', forced: false }; }
	/* a usable GPS speed (m/s): iOS reports -1 and most desktops null when it has none */
	function gpsSpeed(pos) { var s = pos && pos.coords ? pos.coords.speed : null; return (s != null && isFinite(s) && s >= 0) ? +s : null; }
	function median(a) { var b = a.slice().sort(function (x, y) { return x - y; }), m = b.length >> 1; return b.length % 2 ? b[m] : (b[m - 1] + b[m]) / 2; }
	function paceUpdate(lat, lng, t, acc, speed) {
		if (acc > PL.accM) { return PACE.state; } // a loose fix says nothing about speed
		if (PACE.last && t - PACE.last[2] >= 45000) { PACE.win = []; PACE.spd = []; PACE.fastSince = null; PACE.slowSince = null; } // GPS was away: start the window afresh
		if (PACE.last && t - PACE.last[2] < 1000) { return PACE.state; } // a second fix in the same second (iOS delivers pairs)
		if (PACE.last && t - PACE.last[2] < 90000 && distM([PACE.last[0], PACE.last[1]], [lat, lng]) > PL.jumpM) { // impossible on foot
			PACE.state = 'drive'; PACE.driveFrom = PACE.driveFrom || PACE.last[2]; PACE.slowSince = null;
		}
		PACE.last = [lat, lng, t];
		PACE.win.push([t, lat, lng]);
		PACE.win = PACE.win.filter(function (w) { return t - w[0] <= 25000; });
		if (speed != null) { PACE.spd.push([t, speed]); }
		PACE.spd = PACE.spd.filter(function (s) { return t - s[0] <= 10000; });
		// two measures: the phone's own speed (median of the last 10 s) and the displacement over the 12–25 s window
		var oldest = PACE.win[0], span = (t - oldest[0]) / 1000;
		var track = span >= 12 ? distM([oldest[1], oldest[2]], [lat, lng]) / span * 2.23694 : null;
		var gps = PACE.spd.length >= 2 ? median(PACE.spd.map(function (s) { return s[1]; })) * 2.23694 : null;
		if (track == null && gps == null) { return PACE.state; } // not enough of a baseline yet
		/* The phone's speed drops to 0 within seconds of a stop, so leaving "driving" on it alone would flip a car at a
		   red light back to walking in ~20 s and tick the next houses. So the larger of the two measures decides, both
		   ways: the phone's speed can only help to SEE a drive, and leaving one needs both to agree. Without
		   coords.speed the displacement window decides alone. */
		// the larger of the two both ways: a phone that wrongly reports 0 (some Android browsers) still sees a drive the
		// window sees, and a phone's speed can only add to what the window caught
		var fast = Math.max(gps == null ? 0 : gps, track == null ? 0 : track), slow = fast;
		PACE.src = gps != null ? 'gps' : 'track';
		PACE.mph = PACE.state === 'walk' ? fast : slow;
		if (PACE.state === 'walk') {
			if (fast > PL.driveMph) { if (!PACE.fastSince) { PACE.fastSince = t; } if (t - PACE.fastSince >= PL.driveS * 1000) { PACE.state = 'drive'; PACE.driveFrom = PACE.fastSince - PL.driveS * 1000; PACE.slowSince = null; logEv('pace', 'driving ' + fast.toFixed(1) + ' mph (' + PACE.src + ')'); } }
			else { PACE.fastSince = null; }
		} else if (track == null) {
			// leaving "driving" waits for the 12 s window (just after a GPS gap the phone's speed alone decides nothing)
		} else if (PACE.forced) {
			// the walker said "I'm driving": a stop at a light or a parked car is still driving; a minute at walking pace is not.
			// Door-to-door walking (stops at each door) averages 1+ mph over the 25 s window, so the larger measure is used.
			if (slow >= 1 && slow < PL.walkMph) { if (!PACE.slowSince) { PACE.slowSince = t; } if (t - PACE.slowSince >= 60000) { PACE.state = 'walk'; PACE.forced = false; PACE.fastSince = null; PACE.slowSince = null; PACE.driveFrom = null; logEv('pace', 'walking ' + slow.toFixed(1) + ' mph (after "I\'m driving")'); } }
			else { PACE.slowSince = null; }
		} else {
			if (slow < PL.walkMph) { if (!PACE.slowSince) { PACE.slowSince = t; } if (t - PACE.slowSince >= PL.walkS * 1000) { PACE.state = 'walk'; PACE.fastSince = null; PACE.slowSince = null; PACE.driveFrom = null; logEv('pace', 'walking ' + slow.toFixed(1) + ' mph (' + PACE.src + ')'); } }
			else { PACE.slowSince = null; }
		}
		return PACE.state;
	}
	function backdateDriving(track, since) {
		for (var i = track.length - 1; i >= 0 && track[i][2] >= since - 1000; i--) { if (track[i][4] !== 'd') { track[i][4] = 'd'; } }
	}
	/* "I'm on foot": the walker overrules the detector — walking from now on, and the last two minutes were walking too. */
	/* the end of an "I'm driving" stretch (the walker said "I'm on foot", or a minute at walking pace) */
	function closeForced(state, t) { var f = state.forced; if (f && f.length && !f[f.length - 1][1]) { f[f.length - 1][1] = t; } }
	function forceWalking(state, kind) {
		closeForced(state, Date.now());
		paceReset();
		var cut = Date.now() - 120000, n = 0;
		(state.track || []).forEach(function (p) { if (p[4] === 'd' && p[2] >= cut) { p.length = 4; n++; } });
		state.pace = 'walk';
		if (kind === 'quick') { saveQuick(state); } else { saveSession(state); }
		logEv('pace', 'forced walking, cleared ' + n);
		toast('Counting houses again — the last two minutes count as walking.', 5000);
	}
	/* "I'm driving": the walker overrules the detector the other way — driving from now on, so no house ticks and the
	   stretch is drawn dashed (and marked 'd' in the track, which Hours reads). Houses already ticked stay ticked: tap one
	   to untick it. */
	function forceDriving(state, kind) {
		var now = Date.now();
		PACE.state = 'drive'; PACE.forced = true; PACE.fastSince = null; PACE.slowSince = null; PACE.driveFrom = now;
		state.pace = 'drive';
		state.forced = (state.forced || []).filter(function (f) { return f[1]; }); state.forced.push([now, 0]); // kept for Done's clean-up
		var t = state.track || [], last = t.length ? t[t.length - 1] : null;
		if (last && now - last[2] < 15000 && last[4] !== 'd') { last[4] = 'd'; } // the fix the walker is standing on
		if (kind === 'quick') { saveQuick(state); } else { saveSession(state); }
		logEv('pace', 'forced driving');
		toast('Driving — houses won\'t tick until you\'re on foot again.', 5000);
		redrawTrack(kind);
	}
	/* A walk is one outing: after a hole of 6+ hours in the fixes the earlier points belong to another day (a session that
	   was left open — one walk was stamped "9:59 PM" from a start a week before). The walk starts at the first point after
	   the last such hole; the record's start time follows. */
	function trimOldTrack(q) {
		var t = q.track || []; if (t.length < 2) { return false; }
		var cut = 0;
		for (var i = 1; i < t.length; i++) { if (t[i][2] - t[i - 1][2] >= 6 * 3600000) { cut = i; } }
		var changed = false;
		if (cut > 0) { logEv('quick.trim', cut + ' points from before ' + fmtT(t[cut][2]) + ' dropped'); q.track = t.slice(cut); q.pin = [q.track[0][0], q.track[0][1]]; changed = true; }
		var first = q.track[0][2];
		if (first && Math.abs(first - q.started) > 30 * 60000) { logEv('quick.trim', 'start ' + fmtT(q.started) + ' → ' + fmtT(first)); q.started = first; changed = true; }
		return changed;
	}
	/* Before a Quick Start walk is tabulated: a "driving" stretch that lasted 2+ minutes at under 4.5 mph was walking.
	   (A detector can latch onto "driving" for the rest of a walk after a GPS hiccup.) */
	function sanityDriving(track, forced) {
		var fixed = 0, i = 0;
		var said = function (t) { return (forced || []).some(function (f) { return t >= f[0] - 1000 && (!f[1] || t <= f[1]); }); };
		while (i < track.length) {
			if (track[i][4] !== 'd') { i++; continue; }
			var j = i; while (j < track.length && track[j][4] === 'd') { j++; }
			var run = track.slice(i, j), secs = (run[run.length - 1][2] - run[0][2]) / 1000, m = 0;
			if (run.some(function (p) { return said(p[2]); })) { i = j; continue; } // the walker said "I'm driving" here (a parked car is slow, not walking)
			for (var k = 1; k < run.length; k++) { m += distM([run[k - 1][0], run[k - 1][1]], [run[k][0], run[k][1]]); }
			if (secs >= 120 && m / secs * 2.23694 < PL.walkMph) { run.forEach(function (p) { p.length = 4; }); fixed += run.length; }
			i = j;
		}
		if (fixed) { logEv('pace', 'sanity: ' + fixed + ' "driving" points were walking'); }
		return fixed;
	}
	/* After 4 hours (and hourly after that) ask whether the walk is actually finished. */
	function askIfDone(state, kind) {
		var started = state.started || 0, asked = state.asked || 0, now = Date.now();
		if (!started || now - started < 4 * 3600000 || (asked && now - asked < 3600000) || document.getElementById('zdh-ask')) { return; }
		state.asked = now; if (kind === 'quick') { saveQuick(state); } else { saveSession(state); }
		var hrs = Math.round((now - started) / 360000) / 10;
		logEv('ask', kind + ' ' + hrs + ' h');
		var box = el('<div class="zdh-install-wrap" id="zdh-ask" role="dialog" aria-modal="true"><div class="zdh-install-sheet"><h2 class="zdh-install-title">Still delivering?</h2><p class="zdh-install-sub">You started ' + hrs + ' hours ago. If the walk is over, wrap it up so the houses get logged.</p><div class="zdh-actions"><button type="button" class="zdh-secondary" id="ask-more">Keep going</button><button type="button" class="zdh-stop" id="ask-done">■ I\'m done delivering</button></div></div></div>');
		box.querySelector('#ask-more').addEventListener('click', function () { box.remove(); logEv('ask', 'keep going'); });
		box.querySelector('#ask-done').addEventListener('click', function () { box.remove(); logEv('ask', 'done'); if (kind === 'quick') { finishQuick(); } else { S.tab = 'deliver'; render(); setTimeout(function () { showFinish(); var f = document.getElementById('dv-finish-box'); if (f) { f.scrollIntoView({ behavior: 'smooth' }); } }, 400); } });
		installRoot().appendChild(box);
	}
	/* Send what changed since the last sync. `beacon` = fire-and-forget when the app is going to the background.
	   a beacon is only *queued* by the browser, so it no longer clears the pending ticks — the next confirmed
	   sync sends them again (the server ignores repeats), which is what keeps a walk in poor reception intact. */
	function syncProgress(beacon) { // resolves true when the server has everything, false when it does not (never rejects)
		var ses = session(); if (!ses) { return Promise.resolve(true); }
		var del = Object.keys(ses.pending).map(Number), undel = Object.keys(ses.undel).map(Number);
		var track = ses.track.filter(function (t) { return t[2] > (ses.lastSync || 0); });
		if (!del.length && !undel.length && !track.length) { return Promise.resolve(true); }
		var body = { delivered: del, undelivered: undel, track: track };
		var mark = Date.now();
		if (beacon && navigator.sendBeacon) {
			var ok = navigator.sendBeacon(url('drops/' + ses.id + '/progress?_wpnonce=' + encodeURIComponent(C.nonce)), new Blob([JSON.stringify(body)], { type: 'application/json' }));
			logEv('sync.beacon', (ok ? 'queued' : 'refused') + ' ' + del.length + '/' + undel.length + '/' + track.length);
			return Promise.resolve(false);
		}
		return api('drops/' + ses.id + '/progress', { method: 'POST', body: body }).then(function (r) {
			var s2 = session(); if (!s2 || s2.id !== ses.id) { return true; }
			del.forEach(function (id) { delete s2.pending[id]; }); undel.forEach(function (id) { delete s2.undel[id]; });
			s2.lastSync = mark; s2.serverDelivered = r && r.delivered; saveSession(s2);
			logEv('sync.ok', del.length + '/' + undel.length + '/' + track.length + ' → server ' + (r ? r.delivered + '/' + r.total : '?'));
			return true;
		}).catch(function (e) { logEv('sync.fail', e.message); return false; });
	}
	/* Everything the phone believes about the walk, for a finish or a stop that has to survive no signal. */
	function progressSnapshot(ses) {
		return { delivered: Object.keys(ses.delivered).map(Number), undelivered: Object.keys(ses.undel).map(Number), track: ses.track.filter(function (t) { return t[2] > (ses.lastSync || 0); }) };
	}

	/* ---- outbox: progress or a finish that could not reach the server is kept here and sent by itself
	   when the phone is back online (online event, app shown again, every 30 s while open, next start). */
	var OUTBOX_KEY = 'zdh_outbox', outboxBusy = false;
	function outbox() { try { var j = localStorage.getItem(OUTBOX_KEY); var a = j ? JSON.parse(j) : []; return Array.isArray(a) ? a : []; } catch (e) { return []; } }
	function saveOutbox(a) { try { if (a && a.length) { localStorage.setItem(OUTBOX_KEY, JSON.stringify(a)); } else { localStorage.removeItem(OUTBOX_KEY); } } catch (e) {} renderOutboxNote(); }
	function queueOutbox(item) { var a = outbox(); item.at = Date.now(); item.tries = 0; a.push(item); saveOutbox(a); logEv('outbox.add', item.kind + ' #' + item.id); }
	function flushOutbox() {
		var a = outbox(); if (!a.length || outboxBusy || navigator.onLine === false) { return Promise.resolve(); }
		outboxBusy = true;
		var item = a[0];
		var p = item.kind === 'finish'
			? api('drops/' + item.id + '/finish', { method: 'POST', body: item.body })
			: (item.kind === 'discard' ? api('drops/' + item.id + '/discard-quick', { method: 'POST' })
			: (item.kind === 'nosolicit' ? api('nosolicit', { method: 'POST', body: item.body })
			: api('drops/' + item.id + '/progress', { method: 'POST', body: item.body })));
		return p.then(function (r) {
			var b = outbox(); b.shift(); saveOutbox(b); S.drops = null;
			logEv('outbox.sent', item.kind + ' #' + item.id);
			if (item.kind === 'finish') { toast((item.name ? '“' + item.name + '”' : 'Cluster #' + item.id) + (item.body.walk ? ' — walk ' + item.body.walk : '') + ' finished — the queued wrap-up went through.', 8000); if (S.tab === 'map') { render(); } }
			outboxBusy = false;
			return flushOutbox();
		}).catch(function (e) {
			outboxBusy = false;
			if (isNetError(e)) { var b = outbox(); if (b[0]) { b[0].tries = (b[0].tries || 0) + 1; saveOutbox(b); } return; } // still no signal — later
			// the server answered no (cluster deleted, already finished…): nothing more can be done with this one
			var c = outbox(); c.shift(); saveOutbox(c); S.drops = null;
			logEv('outbox.drop', item.kind + ' #' + item.id + ': ' + e.message);
			toast('Could not send the queued ' + (item.kind === 'finish' ? 'wrap-up' : 'progress') + ' for cluster #' + item.id + ': ' + e.message, 9000);
			return flushOutbox();
		});
	}
	function renderOutboxNote() {
		var a = outbox(), n = document.getElementById('zdh-outbox');
		if (!a.length) { if (n) { n.remove(); } return; }
		var txt = a.length === 1 ? (a[0].kind === 'finish' ? 'A wrap-up is waiting to be sent' : (a[0].kind === 'discard' ? 'A discarded walk is waiting to be cleared' : 'Ticked houses are waiting to be sent')) : a.length + ' updates are waiting to be sent';
		var html = '<span>📶 ' + txt + ' — it goes by itself when the phone is online.</span><span class="zdh-banner-btns"><button type="button" class="zdh-mini" id="zdh-outbox-send">Send now</button></span>';
		if (!n) { n = el('<div class="zdh-banner zdh-outbox" id="zdh-outbox"></div>'); var top = document.querySelector('.zdh-top'); if (top && top.parentNode) { top.parentNode.insertBefore(n, top.nextSibling); } else { return; } }
		n.innerHTML = html;
		n.querySelector('#zdh-outbox-send').addEventListener('click', function () { flushOutbox().then(function () { if (outbox().length) { toast('Still no connection — it will keep trying.'); } }); });
	}
	setInterval(function () { if (outbox().length) { flushOutbox(); } }, 30000);
	function wakeBtn(on) { var b = document.getElementById('dv-wake'); if (b) { b.setAttribute('aria-pressed', on ? 'true' : 'false'); } }
	function requestWake(btn) {
		if (!('wakeLock' in navigator)) { var b0 = btn || document.getElementById('dv-wake'); if (b0) { b0.disabled = true; b0.title = 'Not supported by this browser'; } logEv('wake', 'unsupported'); return; }
		navigator.wakeLock.request('screen').then(function (w) { D.wake = w; logEv('wake', 'on'); w.addEventListener('release', function () { D.wake = null; wakeBtn(false); logEv('wake', 'released'); }); wakeBtn(true); }).catch(function (e) { wakeBtn(false); logEv('wake', 'refused ' + (e && e.message ? e.message : '')); });
	}
	function toggleWake(btn) { if (D.wake) { D.wake.release(); D.wake = null; wakeBtn(false); } else { requestWake(btn); } }

	/* ===================================================== POCKET MODE
	   The screen cannot be turned off yet: a web app on an iPhone gets no
	   location once the screen locks — that is Apple's rule, not ours. What we can do: a black, touch-proof screen.
	   The screen stays on (so the GPS keeps recording) but shows almost nothing — black pixels cost next to no battery
	   on an OLED iPhone — and a pocket can't tap the map. Hold the button for a second to come back. */
	/* ===================================================== NO-SOLICIT HOUSE
	   A house with a "No Soliciting" sign gets no hanger, but the GPS walked up to its door and would tick it. The walker presses
	   No-Solicit House (pocket mode, or the walk view) where they stand: the house they are at is marked NOT delivered on this
	   walk (and the auto-tick leaves it alone from now on), and the server remembers the house -- by its address, not the person --
	   so every future route draws it red and skips it, and Lookup says so. With no signal the press waits in the outbox. */
	function noSolicitHere(where) {
		var ses = session(), q = quick(), fix = D.lastFix, acc = D.lastAcc == null ? null : D.lastAcc;
		var ageS = D.lastFixAt ? (Date.now() - D.lastFixAt) / 1000 : null;
		if (!fix || ageS == null || ageS > 120) { noSolicitSay(where, 'No GPS fix just now — wait for the GPS, then press again.', true); logEv('nosol', where + ': no fix'); return; }
		var house = null, dist = null;
		if (ses && D.cluster && D.cluster.id === ses.id) {
			var wn = ses.walk || 1, multi = walkList(D.cluster).length > 1;
			D.cluster.addresses.forEach(function (a) {
				if (a.lat == null || (multi && (+a.walk || 1) !== wn)) { return; }
				var dm = distM(fix, [a.lat, a.lng]);
				if (dm <= 40 && (dist == null || dm < dist)) { dist = dm; house = a; }
			});
			if (house) {
				ses.nosolicit = ses.nosolicit || {}; ses.nosolicit[house.id] = Date.now();
				if (ses.delivered[house.id]) { delete ses.delivered[house.id]; delete ses.pending[house.id]; }
				ses.undel[house.id] = 1; // the server hears "not delivered" with the next sync too
				saveSession(ses); house.no_solicit = 1; var dd = D.dots[house.id]; if (dd) { dd._nosol = true; } paintDelivery();
			}
		}
		if (q && !q.ended && !ses) { q.nosolicit = q.nosolicit || []; q.nosolicit.push([+fix[0].toFixed(6), +fix[1].toFixed(6), Date.now()]); saveQuick(q); }
		var body = { lat: fix[0], lng: fix[1], acc: acc, drop_id: ses ? ses.id : (q && q.id ? q.id : 0), address_id: house ? house.id : 0 };
		logEv('nosol', where + (house ? ': #' + house.id + ' ' + Math.round(dist) + ' m' : ': by position') + ' ±' + acc);
		var label = house ? (house.full_address || (house.house_number + ' ' + house.street)) : null;
		noSolicitSay(where, 'No Soliciting noted' + (label ? ' — ' + label : ' at this spot') + '. Not delivered.' + (navigator.onLine === false ? ' (sent when back in signal)' : ''), false);
		if (navigator.onLine === false) { queueOutbox({ kind: 'nosolicit', id: body.drop_id, body: body }); return; }
		api('nosolicit', { method: 'POST', body: body }).then(function (r) {
			if (r && r.full_address && !label) { noSolicitSay(where, 'No Soliciting noted — ' + r.full_address + '. Not delivered.', false); }
			logEv('nosol.ok', (r && r.resolved) + (r && r.already ? ' (known)' : '') + ' ' + (r && r.full_address ? 'addr' : 'no addr'));
		}).catch(function (e) {
			if (isNetError(e)) { queueOutbox({ kind: 'nosolicit', id: body.drop_id, body: body }); return; }
			noSolicitSay(where, 'Could not record the sign: ' + e.message, true); logEv('nosol.err', e.message);
		});
	}
	function noSolicitSay(where, msg, isErr) {
		if (where === 'pocket' && document.getElementById('zdh-pocket')) { var n = document.getElementById('pk-note'); if (n) { n.textContent = msg; } POCKET.noteUntil = Date.now() + 8000; if (navigator.vibrate) { try { navigator.vibrate(isErr ? [60, 60, 60] : 120); } catch (e) {} } return; }
		if (isErr) { toastError(msg); } else { toast(msg, 6000); }
	}
	var POCKET = { timer: null, hold: null, nosol: null, voice: null, noteUntil: 0 };
	function pocketStats() {
		var q = quick(), ses = session();
		if (q && !q.ended) { return { big: fmtMiles(quickWalkMeters(q)), label: 'walked', sub: 'Recording since ' + fmtClock(q.started) + ' · ' + fmtMin((Date.now() - q.started) / 60000), fix: q.lastFixAt }; }
		if (ses && D.cluster && D.cluster.id === ses.id) {
			var mine = walkHouses(D.cluster, ses.walk || 1), done = mine.filter(function (a) { return ses.delivered[a.id]; }).length;
			return { big: done + ' / ' + mine.length, label: 'houses ticked', sub: (D.cluster.name || 'Delivering') + ' · since ' + fmtClock(ses.started), fix: ses.lastFixAt };
		}
		return null;
	}
	function pocketOn() {
		if (document.getElementById('zdh-pocket')) { return; }
		if (!pocketStats()) { return; }
		requestWake(null);
		var box = el('<div class="zdh-pocket" id="zdh-pocket" role="dialog" aria-modal="true" aria-label="Pocket mode"><div class="zdh-pocket-in">' +
			'<div class="zdh-pocket-big" id="pk-big"></div><div class="zdh-pocket-label" id="pk-label"></div><div class="zdh-pocket-sub" id="pk-sub"></div><div class="zdh-pocket-nav" id="pk-nav"></div><div class="zdh-pocket-gps" id="pk-gps"></div><div class="zdh-pocket-note" id="pk-note"></div>' +
			'<button type="button" class="zdh-pocket-unlock" id="pk-hold"><span class="zdh-pocket-fill"></span><span class="zdh-pocket-txt">Hold to unlock</span></button>' +
			'<button type="button" class="zdh-pocket-unlock zdh-pocket-nosol" id="pk-nosol"><span class="zdh-pocket-fill"></span><span class="zdh-pocket-txt">🚫 No-Solicit House</span></button>' +
			(C.navVoice && session() ? '<button type="button" class="zdh-pocket-unlock zdh-pocket-voice" id="pk-voice" aria-pressed="' + (voiceOn() ? 'true' : 'false') + '"><span class="zdh-pocket-fill"></span><span class="zdh-pocket-txt" id="pk-voice-t">' + (voiceOn() ? '🔊 Spoken turns: on' : '🔈 Spoken turns: off') + '</span></button>' : '') +
			'<p class="zdh-pocket-help">The screen stays on so the GPS keeps recording, but black uses very little battery. A pocket can\'t tap anything here. Hold <strong>No-Solicit House</strong> at the door of a house with the sign: it is marked not delivered and remembered.</p></div></div>');
		document.body.appendChild(box);
		document.body.classList.add('zdh-pocket-on');
		logEv('pocket', 'on');
		var hold = box.querySelector('#pk-hold');
		var start = function (e) { e.preventDefault(); hold.classList.add('zdh-holding'); clearTimeout(POCKET.hold); POCKET.hold = setTimeout(pocketOff, 900); };
		var stop = function () { hold.classList.remove('zdh-holding'); clearTimeout(POCKET.hold); };
		hold.addEventListener('pointerdown', start); hold.addEventListener('pointerup', stop); hold.addEventListener('pointerleave', stop); hold.addEventListener('pointercancel', stop);
		hold.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { pocketOff(); } }); // keyboard / VoiceOver
		// the No-Solicit button holds too (a pocket cannot hold for 0.6 s), then records the house the walker stands at
		var nos = box.querySelector('#pk-nosol');
		var nstart = function (e) { e.preventDefault(); nos.classList.add('zdh-holding'); clearTimeout(POCKET.nosol); POCKET.nosol = setTimeout(function () { nos.classList.remove('zdh-holding'); noSolicitHere('pocket'); }, 600); };
		var nstop = function () { nos.classList.remove('zdh-holding'); clearTimeout(POCKET.nosol); };
		nos.addEventListener('pointerdown', nstart); nos.addEventListener('pointerup', nstop); nos.addEventListener('pointerleave', nstop); nos.addEventListener('pointercancel', nstop);
		nos.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); noSolicitHere('pocket'); } });
		// the spoken cue's switch for this phone (only when the site turned spoken cues on); held, like the others
		var vb = box.querySelector('#pk-voice');
		if (vb) {
			var vflip = function () { var on = !voiceOn(); store('voice', on ? '1' : '0'); vb.setAttribute('aria-pressed', on ? 'true' : 'false'); var vt = document.getElementById('pk-voice-t'); if (vt) { vt.textContent = on ? '🔊 Spoken turns: on' : '🔈 Spoken turns: off'; } if (on) { NV.unlocked = true; speak('Spoken turns on'); } else { try { window.speechSynthesis.cancel(); } catch (x) {} } logEv('nav.voice', on ? 'on' : 'off'); };
			var vstart = function (e) { e.preventDefault(); vb.classList.add('zdh-holding'); clearTimeout(POCKET.voice); POCKET.voice = setTimeout(function () { vb.classList.remove('zdh-holding'); vflip(); }, 600); };
			var vstop = function () { vb.classList.remove('zdh-holding'); clearTimeout(POCKET.voice); };
			vb.addEventListener('pointerdown', vstart); vb.addEventListener('pointerup', vstop); vb.addEventListener('pointerleave', vstop); vb.addEventListener('pointercancel', vstop);
			vb.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); vflip(); } });
		}
		if (voiceOn() && session() && !NV.unlocked) { NV.unlocked = true; speak('Spoken turns on'); } // iOS speaks only after a tap: the Pocket tap is one
		box.addEventListener('touchmove', function (e) { e.preventDefault(); }, { passive: false });
		pocketPaint();
		POCKET.timer = setInterval(pocketPaint, 5000);
	}
	function pocketOff() {
		clearInterval(POCKET.timer); clearTimeout(POCKET.hold); clearTimeout(POCKET.nosol); clearTimeout(POCKET.voice);
		var b = document.getElementById('zdh-pocket'); if (b) { b.remove(); logEv('pocket', 'off'); }
		document.body.classList.remove('zdh-pocket-on');
		if (Q.map) { try { Q.map.invalidateSize(); } catch (e) {} } if (D.map) { try { D.map.invalidateSize(); } catch (e) {} }
	}
	function pocketPaint() {
		var st = pocketStats();
		if (!st) { pocketOff(); return; } // the walk ended
		var set = function (id, t) { var e = document.getElementById(id); if (e) { e.textContent = t; } };
		set('pk-big', st.big); set('pk-label', st.label); set('pk-sub', st.sub); pocketNav(NV.last);
		if (POCKET.noteUntil && Date.now() > POCKET.noteUntil) { POCKET.noteUntil = 0; set('pk-note', ''); }
		var ago = st.fix ? Math.round((Date.now() - st.fix) / 1000) : null;
		set('pk-gps', (ago == null ? 'Waiting for GPS…' : (ago < 180 ? 'GPS recording ✓' : 'No GPS fix for ' + fmtMin(ago / 60) + ' — is location on?')) + (navigator.onLine === false ? ' · no signal (fine — it syncs later)' : '') + (WX.feels != null ? ' · ' + WX.feels + '°F' : ''));
	}

	/* ===================================================== WEATHER + WATER BREAKS
	   Hot days on foot: the walk view shows the temperature
	   (Open-Meteo, keyless, straight from the phone) and nudges a water break every 45 minutes on foot.
	   every 35 minutes when it feels like 90 °F or more, every 30 minutes at 95 °F or more.
	   "Mute today" silences it until tomorrow. */
	function waterEvery(feels) { return feels == null ? 45 : (feels >= 95 ? 30 : (feels >= 90 ? 35 : 45)); }
	var WX = { at: 0, ll: null, temp: null, feels: null };
	function weatherFor(ll) {
		if (!ll || (Date.now() - WX.at < 30 * 60000 && WX.ll && distM(WX.ll, ll) < 5000)) { return; }
		if (navigator.onLine === false || !window.fetch) { return; }
		WX.at = Date.now();
		fetch('https://api.open-meteo.com/v1/forecast?latitude=' + ll[0].toFixed(3) + '&longitude=' + ll[1].toFixed(3) + '&current=temperature_2m,apparent_temperature&temperature_unit=fahrenheit&timezone=auto')
			.then(function (r) { return r.ok ? r.json() : null; })
			.then(function (j) { if (!j || !j.current) { return; } WX.ll = ll; WX.temp = Math.round(j.current.temperature_2m); WX.feels = Math.round(j.current.apparent_temperature); paintWx(); logEv('wx', WX.temp + '°F feels ' + WX.feels); })
			.catch(function () { WX.at = Date.now() - 25 * 60000; }); // try again in ~5 min
	}
	function wxText() { if (WX.temp == null) { return ''; } return '🌡 ' + WX.temp + '°F' + (WX.feels >= WX.temp + 3 ? ' (feels ' + WX.feels + ')' : ''); }
	function paintWx() { var e = document.getElementById('dv-wx'); if (e) { var t = wxText(); e.textContent = t ? ' · ' + t : ''; } }
	function waterCheck() {
		var q = quick(), ses = session(), w = q && !q.ended ? q : ses;
		if (!w || !w.started) { return; }
		if (recall('water_mute') === todayISO()) { return; }
		var mark = recall('water_at').split(':'), last = +mark[0] === w.started ? +mark[1] : w.started;
		var mins = waterEvery(WX.feels), hot = mins < 45, every = mins * 60000;
		if (Date.now() - last < every) { return; }
		if (!w.lastFixAt || Date.now() - w.lastFixAt > 10 * 60000) { return; } // not out walking right now
		store('water_at', w.started + ':' + Date.now());
		var msg = '💧 ' + fmtMin((Date.now() - w.started) / 60000) + ' in' + (WX.feels != null ? ', feels like ' + WX.feels + '°F' : '') + ' — time for some water' + (hot ? ' and a pinch of salt' : '') + '.';
		logEv('water', msg);
		try { if (navigator.vibrate) { navigator.vibrate([90, 60, 90]); } } catch (e) {}
		var pn = document.getElementById('pk-note'); if (pn) { pn.textContent = msg; }
		toastAction(msg, 'Mute today', function () { store('water_mute', todayISO()); toast('No more water reminders today.'); }, 30000);
	}
	setInterval(waterCheck, 60000);
	function showFinish() {
		var ses = session(), d = D.cluster; if (!ses || !d) { return; }
		var old = document.getElementById('dv-finish-box'); if (old) { old.remove(); }
		var wrap = el('<div class="zdh-install-wrap" id="dv-finish-box" role="dialog" aria-modal="true"><div class="zdh-install-sheet zdh-sheet-scroll"></div></div>');
		var box = wrap.firstChild;
		installRoot().appendChild(wrap);
		var wn = ses.walk || 1, list = walkList(d), multi = list.length > 1, mine = multi ? walkHouses(d, wn) : d.addresses;
		var done = mine.filter(function (a) { return ses.delivered[a.id]; }).length, remaining = mine.length - done;
		var others = list.filter(function (w) { return +w.n !== wn && w.status !== 'done'; }); // walks still to do after this one
		var last = !others.length; // finishing this walk completes the cluster
		var clusterDone = d.addresses.filter(function (a) { return ses.delivered[a.id]; }).length;
		box.innerHTML = '<div><h2 class="zdh-install-title">' + (multi ? 'Wrap up walk ' + wn + ' of ' + list.length : 'Wrap up') + '</h2><p class="zdh-help">' + done + ' of ' + mine.length + ' ticked' + (remaining ? ', ' + remaining + ' not passed.' : '.') + (multi && !last ? ' ' + others.length + ' walk' + (others.length > 1 ? 's' : '') + ' still to do.' : '') + '</p>' +
			(remaining ? '<label class="zdh-check"><input type="checkbox" id="fin-rest" checked> ' + tx('Mark the other ' + remaining + ' delivered too', 'I hung the remaining ' + remaining + ' too — mark them delivered') + '</label>' : '') +
			(last ? '<div class="zdh-grid"><label>Hangers hung<input type="number" id="fin-qty" value="' + (remaining ? clusterDone + remaining : clusterDone) + '"></label><label>By<input type="text" id="fin-by" list="zdh-team" value="' + esc(d.delivered_by || recall('deliverer') || C.userName || '') + '"><datalist id="zdh-team">' + peopleNames().map(function (t) { return '<option value="' + esc(t) + '">'; }).join('') + '</datalist></label><label class="zdh-span2">Notes<textarea id="fin-notes" rows="2">' + esc(d.notes || '') + '</textarea></label></div>' : '<div class="zdh-grid"><label>By<input type="text" id="fin-by" list="zdh-team" value="' + esc(d.delivered_by || recall('deliverer') || C.userName || '') + '"><datalist id="zdh-team">' + peopleNames().map(function (t) { return '<option value="' + esc(t) + '">'; }).join('') + '</datalist></label></div>') +
			'<div class="zdh-actions"><button class="zdh-secondary" id="fin-cancel">Keep going</button><button class="zdh-primary" id="fin-go">' + (last ? (multi ? 'Finish — cluster done' : 'Finish') : 'Finish walk ' + wn) + '</button></div>' +
			'<p class="zdh-help" style="text-align:center;margin-top:.8rem"><button type="button" class="zdh-linkbtn" id="fin-leave">Stop for now</button>' + tx('', ' — what you ticked is saved and you can resume from Clusters') + '</p></div>';
		document.getElementById('fin-leave').addEventListener('click', function () { wrap.remove(); leaveDelivery('Stopped for now — tap Resume on the cluster when you\'re back.'); });
		var rest = document.getElementById('fin-rest'), qty = document.getElementById('fin-qty');
		if (rest && qty) { rest.addEventListener('change', function () { var s2 = session(); var cd = d.addresses.filter(function (a) { return s2.delivered[a.id]; }).length; qty.value = rest.checked ? cd + remaining : cd; }); }
		document.getElementById('fin-cancel').addEventListener('click', function () { wrap.remove(); });
		document.getElementById('fin-go').addEventListener('click', function (ev) {
			ev.target.disabled = true; ev.target.textContent = 'Finishing…';
			stopGps(); clearInterval(D.timer); D.timer = null; if (D.wake) { D.wake.release(); D.wake = null; }
			var s0 = session() || ses;
			// the finish carries everything the phone knows (every tick, the unsent track) so nothing depends on earlier syncs having got through
			var body = progressSnapshot(s0);
			body.mark_remaining = rest ? rest.checked : false; body.delivered_by = document.getElementById('fin-by').value; body.finished_at = localDT(Date.now());
			if (multi) { body.walk = wn; }
			if (last) { body.quantity = qty ? qty.value : ''; body.notes = document.getElementById('fin-notes').value; }
			logEv('finish.try', '#' + s0.id + (multi ? ' walk ' + wn : '') + ' ticked ' + body.delivered.length + (body.mark_remaining ? ' +rest' : ''));
			api('drops/' + s0.id + '/finish', { method: 'POST', body: body }).then(function (full) {
				logEv('finish.ok', '#' + s0.id + ' delivered ' + full.delivered_count + (full.cluster_done === false ? ' (walk)' : ''));
				wrap.remove();
				saveSession(null); D.cluster = null; S.drops = null;
				if (multi && full.cluster_done === false) { showNextWalk(full, wn); return; }
				var w = (full.enrichment || {}).weather || {};
				S.nd = makeND(); S.nd.step = 'done'; S.nd.savedId = full.id; S.nd.savedCount = full.delivered_count; S.nd.savedStatus = 'done'; S.nd.enrichment = full.enrichment; S.nd.uploaded = [];
				S.nd.finishedMeta = { minutes: full.duration_min, temp: w.temp_f };
				S.tab = 'new'; render();
			}).catch(function (e) {
				if (isNetError(e)) {
					// no signal: keep the wrap-up in the outbox; it completes by itself when the phone is back online
					wrap.remove();
					queueOutbox({ kind: 'finish', id: s0.id, name: s0.name, body: body });
					saveSession(null); D.cluster = null;
					S.tab = 'map'; render();
					toast('No connection right now — the wrap-up is saved on this phone and will finish the ' + (multi ? 'walk' : 'cluster') + ' by itself as soon as you have signal.', 12000);
					return;
				}
				logEv('finish.fail', e.message);
				ev.target.disabled = false; ev.target.textContent = last ? 'Finish' : 'Finish walk ' + wn; box.insertAdjacentHTML('beforeend', '<div class="zdh-error">' + esc(e.message) + '</div>');
			});
		});
	}
	/* One walk down, more to go: hand over to the next walk or stop for now. */
	function showNextWalk(d, doneN) {
		var main = document.getElementById('zdh-main'); if (!main) { return; }
		var list = walkList(d), next = null, doneW = null;
		list.forEach(function (w) { if (+w.n === doneN) { doneW = w; } if (!next && w.status !== 'done') { next = w; } });
		var left = list.filter(function (w) { return w.status !== 'done'; }).length;
		main.innerHTML = '<div class="zdh-card zdh-done"><div class="zdh-done-icon">✓</div><h2>Walk ' + doneN + ' done</h2><p>' + (doneW && doneW.delivered != null ? doneW.delivered + ' houses delivered. ' : '') + left + ' walk' + (left > 1 ? 's' : '') + ' of ' + list.length + ' still to do.</p>' +
			(next ? '<div class="zdh-walk-row zdh-walk-next"><span class="zdh-walk-swatch" style="background:' + walkColor(+next.n) + '"></span><span class="zdh-walk-main"><strong>Next: Walk ' + next.n + '</strong> &bull; ' + next.count + ' houses' + (next.est_min ? ' &bull; ≈ ' + esc(fmtMin(next.est_min)) : '') + '<br><span class="zdh-walk-streets">' + esc(next.streets || '') + '</span></span></div>' : '') +
			'<div class="zdh-actions">' + (next ? '<button class="zdh-go" id="nw-start">▶ Start walk ' + next.n + '</button>' : '') + '<button class="zdh-secondary" id="nw-stop">Stop for now</button></div>' +
			'<p class="zdh-help" style="text-align:center">' + tx('Stopping keeps everything saved.', 'Stop for now keeps everything saved — the cluster stays in progress and the next walk waits under Clusters.') + '</p></div>';
		var st = document.getElementById('nw-start');
		if (st) { st.addEventListener('click', function () { st.disabled = true; st.textContent = 'Starting…'; primeLocation(); startSession(d.id, +next.n).catch(function (e) { st.disabled = false; st.textContent = '▶ Start walk ' + next.n; if (!e.silent) { toast(e.message); } }); }); }
		document.getElementById('nw-stop').addEventListener('click', function () { S.tab = 'map'; render(); });
	}
	/* Resilience: when the phone is unlocked / the app comes back, pick the GPS up again immediately; flush progress when it goes away. */
	var hiddenAt = 0;
	document.addEventListener('visibilitychange', function () {
		if (document.visibilityState === 'visible') {
			logEv('visible', hiddenAt ? 'after ' + Math.round((Date.now() - hiddenAt) / 1000) + ' s' : '');
			hiddenAt = 0;
			if ((session() && S.tab === 'deliver') || (quick() && S.tab === 'quick')) { startGps(); requestWake(null); }
			flushOutbox();
			if (session() && S.tab === 'deliver') { syncProgress(false); }
		} else {
			hiddenAt = Date.now();
			logEv('hidden', (session() ? 'delivery #' + session().id : (quick() ? 'quick' : S.tab)) + (D.watch != null ? ' gps on' : ''));
			flushFixNote();
			syncProgress(true);
			quickSync(true);
		}
	});
	window.addEventListener('pageshow', function () { if ((session() && S.tab === 'deliver') || (quick() && S.tab === 'quick')) { startGps(); } });
	window.addEventListener('pagehide', function () { syncProgress(true); });

	/* ===================================================== QUICK START
	   No plan: tap the green button where you are, walk, tap the red button. The walk is recorded (with a driving
	   detector), and at the end every mapped address point along the walked streets is tabulated — on the side of
	   the street the walker kept to, judged from where the GPS track ran relative to the road centerline. */
	var QUICK_KEY = 'zdh_quick';
	function quick() { try { var j = localStorage.getItem(QUICK_KEY); return j ? JSON.parse(j) : null; } catch (e) { return null; } }
	function saveQuick(q) { try { if (q) { localStorage.setItem(QUICK_KEY, JSON.stringify(q)); } else { localStorage.removeItem(QUICK_KEY); } } catch (e) {} }
	var Q = { map: null, me: null, acc: null, trail: null, pin: null };
	function fmtClock(ms) { return new Date(ms).toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' }); }
	function localDT(ms) { var d = new Date(ms), p = function (n) { return (n < 10 ? '0' : '') + n; }; return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate()) + ' ' + p(d.getHours()) + ':' + p(d.getMinutes()) + ':' + p(d.getSeconds()); }
	function quickWalkMeters(q) {
		var m = 0, prev = null;
		(q.track || []).forEach(function (t) { if (t[4] === 'd') { prev = null; return; } if (prev) { var d = distM([prev[0], prev[1]], [t[0], t[1]]); if (d <= 150 && t[2] - prev[2] <= 300000) { m += d; } } prev = t; });
		return m;
	}
	/* The walked trail as separate lines: a jump of 150 m+ or 5 min+ between fixes (phone away, driving unnoticed) breaks the line. */
	function walkLines(track) {
		var lines = [], cur = [], prev = null;
		(track || []).forEach(function (t) {
			if (t[4] === 'd') { if (cur.length > 1) { lines.push(cur); } cur = []; prev = null; return; }
			if (prev && (distM([prev[0], prev[1]], [t[0], t[1]]) > 150 || t[2] - prev[2] > 300000)) { if (cur.length > 1) { lines.push(cur); } cur = []; }
			cur.push([t[0], t[1]]); prev = t;
		});
		if (cur.length > 1) { lines.push(cur); }
		return lines;
	}
	/* the driven stretches as their own lines (a 5 min hole breaks one), each joined to the walking fix before it,
	   so a drive reads as one dashed line from where the walker got in — on the planned map as on Quick Start's. */
	function driveRuns(track) {
		var runs = [], cur = [], prev = null;
		(track || []).forEach(function (t) {
			if (t[4] !== 'd') { if (cur.length > 1) { runs.push(cur); } cur = []; prev = t; return; }
			if (!cur.length && prev && prev[4] !== 'd' && t[2] - prev[2] <= 300000) { cur.push([prev[0], prev[1]]); }
			else if (cur.length && prev && t[2] - prev[2] > 300000) { if (cur.length > 1) { runs.push(cur); } cur = []; }
			cur.push([t[0], t[1]]); prev = t;
		});
		if (cur.length > 1) { runs.push(cur); }
		return runs;
	}
	var TRAIL = { planned: { color: '#E69F00', weight: 3, opacity: 0.6 }, quick: { color: '#1E8E5A', weight: 4, opacity: 0.8 } };
	var DRIVE_LINE = { color: '#7f8c8d', weight: 3, opacity: 0.6, dashArray: '4 6', interactive: false };
	/* The whole track again, into its own layer group: after a stretch was backdated to "driving", or an override. */
	function drawTrack(st, track, kind) {
		if (!st.map) { return; }
		if (st.tracks) { try { st.tracks.clearLayers(); } catch (e) {} } else { st.tracks = L.layerGroup().addTo(st.map); }
		var style = TRAIL[kind] || TRAIL.planned, lines = walkLines(track), runs = driveRuns(track), last = track && track.length ? track[track.length - 1] : null;
		st.trail = null; st.drive = null;
		lines.forEach(function (ln) { st.trail = L.polyline(ln, style).addTo(st.tracks); });
		runs.forEach(function (ln) { st.drive = L.polyline(ln, DRIVE_LINE).addTo(st.tracks); });
		// only the line that ends at the walker's last fix grows (just after a drive the post-drive line has one point, and
		// growing the line from BEFORE the drive would draw a solid line straight across the whole drive)
		var ends = function (arr) { var e = arr && arr.length ? arr[arr.length - 1] : null; return !!(e && last && e[0] === last[0] && e[1] === last[1]); };
		if (last && last[4] === 'd') { st.trail = null; if (!ends(runs[runs.length - 1])) { st.drive = null; } }
		else { st.drive = null; if (!ends(lines[lines.length - 1])) { st.trail = null; } }
	}
	function redrawTrack(kind) {
		if (kind === 'quick') { var q = quick(); if (q && Q.map) { drawTrack(Q, q.track, 'quick'); } return; }
		var ses = session(); if (ses && D.map) { drawTrack(D, ses.track, 'planned'); }
	}
	/* One new fix on the map: grow the line the walker is on, or start one (a jump of 150 m / 5 min starts a new line).
	   A change between walking and driving redraws the whole track instead (the change is backdated). */
	function growTrack(st, kind, ll, pace, jump) {
		if (!st.map) { return; }
		if (!st.tracks) { st.tracks = L.layerGroup().addTo(st.map); }
		if (pace === 'drive') { if (st.drive && !jump) { st.drive.addLatLng(ll); } else { st.drive = L.polyline([ll], DRIVE_LINE).addTo(st.tracks); } return; }
		if (st.trail && !jump) { st.trail.addLatLng(ll); } else { st.trail = L.polyline([ll], TRAIL[kind] || TRAIL.planned).addTo(st.tracks); }
	}
	/* The blue dot, its accuracy ring and a soft beam pointing the way the walker is going. */
	var ME_COLOR = '#2980b9';
	var BEAM_SVG = '<svg viewBox="0 0 80 80" aria-hidden="true"><defs><radialGradient id="zdh-beam-g" cx="40" cy="40" r="38" gradientUnits="userSpaceOnUse"><stop offset="0" stop-color="currentColor" stop-opacity=".55"/><stop offset="1" stop-color="currentColor" stop-opacity="0"/></radialGradient></defs><path d="M40 40 L23 4 A38 38 0 0 1 57 4 Z" fill="url(#zdh-beam-g)"/></svg>';
	function drawMe(st, ll, acc, heading) {
		if (!st.map) { return; }
		if (!st.me) {
			try { if (!st.map.getPane('zdhBeam')) { var pane = st.map.createPane('zdhBeam'); pane.style.zIndex = 390; pane.style.pointerEvents = 'none'; } } catch (e) {}
			st.beam = L.marker(ll, { pane: 'zdhBeam', interactive: false, keyboard: false, icon: L.divIcon({ className: 'zdh-beam', html: '<div class="zdh-beam-in">' + BEAM_SVG + '</div>', iconSize: [80, 80], iconAnchor: [40, 40] }) }).addTo(st.map);
			st.me = L.circleMarker(ll, { radius: 8, color: '#fff', weight: 2, fillColor: ME_COLOR, fillOpacity: 1 }).addTo(st.map);
			st.acc = L.circle(ll, { radius: acc, color: ME_COLOR, weight: 1, fillOpacity: 0.08 }).addTo(st.map);
		} else { st.me.setLatLng(ll); st.acc.setLatLng(ll); st.acc.setRadius(acc); if (st.beam) { st.beam.setLatLng(ll); } }
		try { var be = st.beam && st.beam.getElement ? st.beam.getElement() : null, inn = be && be.querySelector ? be.querySelector('.zdh-beam-in') : null; if (inn) { inn.style.transform = 'rotate(' + Math.round(heading || 0) + 'deg)'; inn.style.opacity = heading == null ? '0' : '1'; } } catch (e) {}
	}
	function quickStatsText(q) {
		var min = Math.round((Date.now() - q.started) / 60000), mi = quickWalkMeters(q) / 1609.34;
		return (min >= 60 ? Math.floor(min / 60) + ' h ' + (min % 60) + ' min' : min + ' min') + ' · ' + (mi >= 0.1 ? mi.toFixed(1) + ' mi walked' : Math.round(mi * 5280) + ' ft walked');
	}
	function startQuick() {
		if (session()) { toast('You are already delivering a planned cluster — finish or stop it first.'); return; }
		if (quick()) { S.tab = 'quick'; render(); return; }
		paceReset();
		primeLocation();
		saveQuick({ started: Date.now(), lastActive: Date.now(), pin: null, track: [], pace: 'walk', asked: 0, id: null, syncedTo: 0 });
		navReset(); quickNavReset();
		logEv('quick.start');
		S.tab = 'quick'; render();
		quickSync();
	}
	/* ---- the walk lives on the server as it happens. The moment a Quick Start walk begins it is saved as an
	   in-progress record (no houses yet) and every 20 s the new GPS points are appended, so a phone that loses the
	   walk — storage cleared, app reinstalled, a different browser — still has it on the Clusters tab of every device,
	   where it can be counted or discarded. No signal: the record is created as soon as there is some. */
	var quickCreating = false;
	function quickBody(q) {
		return { drop: { status: 'in_progress', method: 'quick', name: 'Quick walk — ' + fmtDate(localDT(q.started).slice(0, 10), true) + ' ' + fmtClock(q.started), started_at: localDT(q.started), drop_date: localDT(q.started).slice(0, 10), drop_time: localDT(q.started).slice(11, 16), delivered_by: recall('deliverer') || '', city: C.defaultCity || '', state: C.defaultState || '', service: C.service || '' }, addresses: [], streets: [], track: q.track.slice(0, 3000) };
	}
	function quickSync(beacon) {
		var q = quick(); if (!q || navigator.onLine === false) { return Promise.resolve(false); }
		if (!q.id) {
			if (quickCreating || beacon) { return Promise.resolve(false); }
			quickCreating = true;
			return api('drops', { method: 'POST', body: quickBody(q) }).then(function (r) {
				quickCreating = false;
				if (r.duplicate) { alreadySavedQuick(r.id); return false; } // saved already, from the site or another phone
				var q2 = quick();
				if (!q2 || q2.started !== q.started) {
					// the walk was saved or discarded while this record was still being created (signal came back right
					// at Done — the walker's border walk) — it would sit under "In progress" for ever, so it goes straight away
					logEv('quick.live', '#' + r.id + ' created after the walk ended — discarded');
					discardLiveQuick(r.id); S.drops = null;
					return false;
				}
				q2.id = r.id; q2.syncedTo = q.track.length ? q.track[q.track.length - 1][2] : 0; saveQuick(q2);
				S.drops = null; // the Clusters list now has a live walk in it
				logEv('quick.live', '#' + r.id + ' created, ' + q.track.length + ' pts');
				return true;
			}).catch(function (e) { quickCreating = false; logEv('quick.live', 'create failed: ' + e.message); return false; });
		}
		var pts = q.track.filter(function (t) { return t[2] > (q.syncedTo || 0); });
		if (!pts.length) { return Promise.resolve(true); }
		if (beacon && navigator.sendBeacon) {
			navigator.sendBeacon(url('drops/' + q.id + '/progress?_wpnonce=' + encodeURIComponent(C.nonce)), new Blob([JSON.stringify({ track: pts.slice(-3000) })], { type: 'application/json' }));
			return Promise.resolve(false);
		}
		return api('drops/' + q.id + '/progress', { method: 'POST', body: { track: pts.slice(-3000) } }).then(function () {
			var q2 = quick(); if (!q2 || q2.id !== q.id) { return true; }
			q2.syncedTo = pts[pts.length - 1][2]; saveQuick(q2);
			return true;
		}).catch(function (e) {
			if (e.status === 404) { var q3 = quick(); if (q3 && q3.id === q.id) { q3.id = null; q3.syncedTo = 0; saveQuick(q3); logEv('quick.live', 'record gone, will recreate'); } } // discarded from another device
			return false;
		});
	}
	setInterval(function () { if (quick()) { quickSync(); } }, 20000);
	/* this phone still had a walk that is already saved on the site (counted from Clusters → Count the houses, or
	   from another phone). Before, the phone re-created a live record and the walk was later saved a second time. */
	function alreadySavedQuick(id) {
		var q = quick();
		stopGps(); saveQuick(null); paceReset(); S.drops = null;
		logEv('quick.dup', 'walk already saved as #' + id + (q ? ' (' + Math.round(quickWalkMeters(q)) + ' m on this phone)' : ''));
		if (S.tab === 'quick') { S.tab = 'map'; store('tab', 'map'); }
		render();
		toast('This walk is already saved (#' + id + ') — nothing was added twice.', 8000);
	}
	function discardLiveQuick(id) {
		if (!id) { return; }
		api('drops/' + id + '/discard-quick', { method: 'POST' }).catch(function (e) { if (isNetError(e)) { queueOutbox({ kind: 'discard', id: id }); } });
	}
	function quickDescription(q) {
		var m = quickWalkMeters(q), mi = m / 1609.34, dist = mi >= 0.1 ? mi.toFixed(1) + ' mi' : Math.round(m * 3.28084) + ' ft';
		var when = new Date(q.started), today = new Date();
		var day = when.toDateString() === today.toDateString() ? 'today' : when.toLocaleDateString('en-US', { weekday: 'long', month: 'short', day: 'numeric' });
		return 'started ' + day + ' at ' + fmtClock(q.started) + ', ' + dist + ' of walking recorded';
	}
	function discardQuick(why) {
		var q = quick();
		stopGps(); saveQuick(null); paceReset();
		logEv('quick.discard', (why || '') + (q ? ' ' + Math.round(quickWalkMeters(q)) + ' m' + (q.id ? ' #' + q.id : '') : ''));
		if (q && q.id) { discardLiveQuick(q.id); S.drops = null; }
		if (S.tab === 'quick') { S.tab = 'new'; }
		render(); toast('Walk discarded.');
	}
	/* A Quick Start walk that has been open for many hours (the phone was locked, the app closed) — ask instead of leaving
	   a green "Delivering since Tuesday" strip in place that also blocks planned clusters. */
	function staleQuickCheck() {
		var q = quick(); if (!q || session()) { return false; }
		var idle = Date.now() - (q.lastActive || q.started || 0);
		if (idle < 8 * 3600000 || document.getElementById('zdh-ask')) { return false; }
		logEv('quick.stale', Math.round(idle / 3600000) + ' h' + (q.ended ? ' ended' : ''));
		if (q.ended) {
			askSheet({ title: 'A recorded walk has not been counted', html: 'It ' + esc(quickDescription(q)) + ' and ended at ' + esc(fmtClock(q.ended)) + '. Count the houses along it now (needs a connection), or discard it.',
				primary: 'Count the houses', onPrimary: function () { finishQuick(); }, secondary: 'Discard it', onSecondary: function () { discardQuick('stale'); } });
			return true;
		}
		askSheet({ title: 'A Quick Start walk is still open', html: 'It ' + esc(quickDescription(q)) + '; the last GPS fix was ' + Math.round(idle / 3600000) + ' hours ago. ' + (quickWalkMeters(q) < 50 ? 'There is too little in it to work out any houses.' : 'Wrap it up to log the houses along the recorded stretch, or discard it.'),
			primary: 'Discard it', danger: true, onPrimary: function () { discardQuick('stale'); },
			secondary: quickWalkMeters(q) < 50 ? 'Keep it' : 'Wrap it up', onSecondary: function () { if (quickWalkMeters(q) < 50) { S.tab = 'quick'; render(); } else { finishQuick(); } } });
		return true;
	}
	function renderQuick(main) {
		var q = quick();
		if (!q) { S.tab = 'new'; return render(); }
		if (q.ended) { return renderQuickEnded(main, q); }
		main.innerHTML =
			'<div class="zdh-card zdh-card-tight zdh-deliver">' +
				'<div class="zdh-deliver-head"><div><h2>Delivering</h2><div class="zdh-muted">Since ' + esc(fmtClock(q.started)) + ' &bull; <span id="qv-stats">' + esc(quickStatsText(q)) + '</span><span id="dv-wx">' + (wxText() ? ' · ' + esc(wxText()) : '') + '</span></div></div>' +
					'<div class="zdh-deliver-count"><strong id="qv-pace">' + (q.pace === 'drive' ? '🚗' : '🚶') + '</strong><span id="qv-pace-l">' + (q.pace === 'drive' ? 'driving' : 'walking') + '</span></div></div>' +
				'<div class="zdh-deliver-status" id="dv-status">Finding your position…</div>' +
				'<div class="zdh-maptools">' +
					'<button type="button" class="zdh-tool" id="dv-follow" aria-pressed="' + (D.follow ? 'true' : 'false') + '">Follow me</button>' +
					'<button type="button" class="zdh-tool" id="dv-wake" aria-pressed="' + (D.wake ? 'true' : 'false') + '">' + tx('Screen on', 'Keep screen on') + '</button>' +
					'<button type="button" class="zdh-tool zdh-tool-warn" id="dv-nosol" aria-pressed="false" title="Standing at a house with a No Soliciting sign? Press this: it is marked not delivered and remembered for every future route">🚫 No-Solicit House</button>' +
					'<button type="button" class="zdh-tool" id="dv-pocket" title="Black, touch-proof screen for your pocket — the GPS keeps recording">🌙 Pocket</button>' +
				'</div>' +
				navWrap('qv-map') +
				'<p class="zdh-help" style="margin-top:.6rem">' + tx('Walk as usual. Tap Done when finished.', 'Walk the streets as usual. Driving is detected from your pace and skipped. When you tap <strong>Done delivering</strong>, every house along the walked streets — on the side you kept to — is listed for you to confirm.') + '</p>' +
			'</div>' +
			'<p class="zdh-help" style="text-align:center"><button type="button" class="zdh-linkbtn" id="qv-discard">Discard this walk</button></p>' +
			actionBar('<button class="zdh-stop" id="qv-done">■ Done delivering</button>');
		buildQuickMap(q);
		document.getElementById('dv-follow').addEventListener('click', function () { setFollow(!D.follow); });
		bindNav(Q.map);
		document.getElementById('dv-wake').addEventListener('click', function (e) { toggleWake(e.currentTarget); });
		document.getElementById('qv-done').addEventListener('click', finishQuick);
		document.getElementById('dv-pocket').addEventListener('click', pocketOn);
		document.getElementById('dv-nosol').addEventListener('click', function () { noSolicitHere('walk'); });
		document.getElementById('qv-discard').addEventListener('click', function () {
			askSheet({ title: 'Discard this walk?', html: 'Nothing will be logged for it (' + esc(quickDescription(q)) + ').', primary: 'Discard the walk', danger: true, onPrimary: function () { discardQuick('button'); }, secondary: 'Keep it' });
		});
		startGps();
		requestWake(null);
		if (q.gaps && q.gaps.length) { var g = q.gaps[q.gaps.length - 1]; if (Date.now() - g[1] < 3600000) { showGapNote(g[0], g[1], 'quick'); } }
	}
	/* The walk is over but its houses have not been worked out yet (no signal when Done was tapped): count later, from anywhere. */
	function renderQuickEnded(main, q) {
		var holes = (q.gaps || []).map(function (g) { return fmtClock(g[0]) + '–' + fmtClock(g[1]); });
		main.innerHTML =
			'<div class="zdh-card zdh-card-tight zdh-deliver"><div class="zdh-deliver-head"><div><h2>Walk recorded</h2><div class="zdh-muted">' + esc(fmtClock(q.started)) + ' – ' + esc(fmtClock(q.ended)) + ' &bull; ' + esc(quickStatsText(q).replace(/^[^·]*· /, '')) + '</div></div></div>' +
				'<p class="zdh-help">The houses along it have not been counted yet — that needs a connection to the address map. ' + (q.id ? 'The walk is also saved on the site (#' + q.id + '), so it can be counted from any device under Clusters.' : 'It is kept on this phone until then.') + (holes.length ? ' GPS was off for a while (' + esc(holes.join(', ')) + ') — streets walked then will not be in the list; tap them or draw them when you confirm.' : '') + '</p>' +
				'<div id="qv-map" class="zdh-map"></div>' +
			'</div>' +
			'<p class="zdh-help" style="text-align:center"><button type="button" class="zdh-linkbtn" id="qv-discard">Discard this walk</button></p>' +
			actionBar('<button class="zdh-primary" id="qv-done">Count the houses</button>');
		buildQuickMap(q);
		document.getElementById('qv-done').addEventListener('click', finishQuick);
		document.getElementById('qv-discard').addEventListener('click', function () {
			askSheet({ title: 'Discard this walk?', html: 'Nothing will be logged for it (' + esc(quickDescription(q)) + ').', primary: 'Discard the walk', danger: true, onPrimary: function () { discardQuick('button'); }, secondary: 'Keep it' });
		});
		if (navigator.onLine === false) { toast('No connection right now — tap Count the houses when you have signal.', 6000); }
	}
	/* A live Quick Start walk found on the server (this phone lost it, or another device made it): count it here. */
	function countQuickFromServer(d) {
		if (quick()) { toast('Finish or discard the Quick Start walk on this phone first.'); return; }
		api('drops/' + d.id).then(function (full) {
			var t = (full.track || []).filter(function (p) { return p && p.length >= 3; });
			if (!t.length) { toast('That walk has no GPS points on the site — discard it.'); return; }
			paceReset();
			saveQuick({ started: t[0][2], lastActive: Date.now(), pin: [t[0][0], t[0][1]], track: t, pace: 'walk', asked: 0, id: full.id, syncedTo: t[t.length - 1][2], ended: t[t.length - 1][2], recovered: true });
			logEv('quick.recover', '#' + full.id + ' ' + t.length + ' pts');
			finishQuick();
		}).catch(function (e) { toast('Could not load the walk: ' + e.message); });
	}
	function buildQuickMap(q) {
		if (Q.map) { killMap(Q.map); }
		Q.map = L.map('qv-map', { tap: false }); Q.me = null; Q.acc = null; Q.trail = null; Q.pin = null; Q.drive = null; Q.tracks = null; Q.beam = null;
		tileLayer().addTo(Q.map);
		addTextControl(Q.map);
		var lines = walkLines(q.track), walk = [];
		lines.forEach(function (ln) { ln.forEach(function (p) { walk.push(p); }); });
		if (q.pin) { Q.pin = quickPin(q.pin).bindTooltip('Start', { permanent: false }); }
		drawTrack(Q, q.track, 'quick'); // walked solid, each driven stretch dashed
		if (walk.length > 1) { Q.map.fitBounds(L.latLngBounds(walk).pad(0.3)); } else if (q.pin) { Q.map.setView(q.pin, 17); } else if (D.lastFix) { Q.map.setView(D.lastFix, 17); } else { homeView(Q.map, 12); }
		settle(Q.map);
	}
	function quickPin(ll) { return L.circleMarker(ll, { radius: 9, color: '#fff', weight: 2, fillColor: TRAIL.quick.color, fillOpacity: 1 }).addTo(Q.map); }
	function quickFix(pos) {
		var q = quick(); if (!q || q.ended) { return; }
		var lat = pos.coords.latitude, lng = pos.coords.longitude, acc = Math.round(pos.coords.accuracy || 0), t = Date.now();
		var ll = [lat, lng];
		D.lastFix = ll; D.lastAcc = acc; D.lastFixAt = t;
		if (recall('loc') !== 'ok') { store('loc', 'ok'); }
		if (!q.pin && acc <= 60) { q.pin = [+lat.toFixed(6), +lng.toFixed(6)]; if (Q.map) { Q.pin = quickPin(q.pin); Q.map.setView(q.pin, 17); } }
		var was = q.pace || 'walk', pace = paceUpdate(lat, lng, t, acc, gpsSpeed(pos));
		noteGap(q, 'quick', t);
		weatherFor(ll);
		fixNote(pos, 0);
		var last = q.track.length ? q.track[q.track.length - 1] : null, pushed = false, jump = false;
		if (acc <= 60 && (!last || distM([last[0], last[1]], ll) > 3 || Date.now() - last[2] > 15000)) {
			var pt = [+lat.toFixed(6), +lng.toFixed(6), Date.now(), acc]; if (pace === 'drive') { pt.push('d'); }
			jump = !!(last && (distM([last[0], last[1]], ll) > 150 || Date.now() - last[2] > 300000));
			q.track.push(pt); pushed = true;
			if (q.track.length > 8000) { q.track = q.track.slice(-8000); }
		}
		if (pace === 'drive' && PACE.driveFrom) { backdateDriving(q.track, PACE.driveFrom); }
		if (was === 'drive' && pace === 'walk') { closeForced(q, t); }
		q.pace = pace; q.lastActive = Date.now();
		saveQuick(q);
		if (Q.map) { if (was !== pace) { drawTrack(Q, q.track, 'quick'); } else if (pushed) { growTrack(Q, 'quick', ll, pace, jump); } }
		if (pace !== 'drive' && acc <= 40 && QN.ctx && QN.of === q.started && NAVM) { NAVM.markCovered(QN.ctx, ll, 35); } // this block's houses passed
		quickNearby(ll, acc, pace);
		setStatus('GPS ±' + acc + ' m · ' + (pace === 'drive' ? '🚗 driving — not counting' : '🚶 walking' + (PACE.mph ? ' ' + PACE.mph.toFixed(1) + ' mph' : '')));
		paceButton('quick', pace);
		var st = document.getElementById('qv-stats'), st2 = document.getElementById('qs-stats'), pc = document.getElementById('qv-pace'), pl = document.getElementById('qv-pace-l');
		if (st) { st.textContent = quickStatsText(q); } if (st2) { st2.textContent = quickStatsText(q); }
		if (pc) { pc.textContent = pace === 'drive' ? '🚗' : '🚶'; } if (pl) { pl.textContent = pace === 'drive' ? 'driving' : 'walking'; }
		paintNav();
		var hd = navHeading(pos, null);
		if (Q.map) { drawMe(Q, ll, acc, hd); if (D.follow) { followCam(Q.map, ll); } }
		askIfDone(q, 'quick');
	}

	/* --- geometry for the side-of-street inference (local metres around the walk) --- */
	function sideAndDist(p, path) { // nearest segment of the road centerline: signed side (+1 left of the line's direction) and distance in m
		var best = { dist: Infinity, side: 0, i: 0 };
		for (var i = 0; i < path.length - 1; i++) {
			var a = path[i], b = path[i + 1];
			var cos = Math.cos(p[0] * Math.PI / 180);
			var px = (p[1] - a[1]) * cos * 111320, py = (p[0] - a[0]) * 110540;
			var bx = (b[1] - a[1]) * cos * 111320, by = (b[0] - a[0]) * 110540;
			var l2 = bx * bx + by * by; if (!l2) { continue; }
			var t = Math.max(0, Math.min(1, (px * bx + py * by) / l2));
			var dx = px - t * bx, dy = py - t * by, d = Math.sqrt(dx * dx + dy * dy);
			if (d < best.dist) { best = { dist: d, side: (bx * py - by * px) >= 0 ? 1 : -1, i: i, nx: -by / Math.sqrt(l2), ny: bx / Math.sqrt(l2) }; }
		}
		return best;
	}
	function compassOfNormal(nx, ny, side) { // which compass side of the road a signed side is
		var x = nx * side, y = ny * side; // x east, y north
		var ang = Math.atan2(y, x) * 180 / Math.PI; // 0 = east, 90 = north
		if (ang >= -22.5 && ang < 22.5) { return 'east'; } if (ang >= 22.5 && ang < 67.5) { return 'northeast'; } if (ang >= 67.5 && ang < 112.5) { return 'north'; } if (ang >= 112.5 && ang < 157.5) { return 'northwest'; }
		if (ang >= 157.5 || ang < -157.5) { return 'west'; } if (ang >= -157.5 && ang < -112.5) { return 'southwest'; } if (ang >= -112.5 && ang < -67.5) { return 'south'; } return 'southeast';
	}
	/* Split the walked track into path strokes (driving and gaps break a stroke). */
	function quickStrokes(track) {
		var strokes = [], cur = [], prev = null;
		track.forEach(function (t) {
			if (t[4] === 'd' || (t[3] != null && t[3] > 40)) { if (cur.length >= 2) { strokes.push(cur); } cur = []; prev = null; return; }
			if (prev && (distM([prev[0], prev[1]], [t[0], t[1]]) > 150 || t[2] - prev[2] > 300000)) { if (cur.length >= 2) { strokes.push(cur); } cur = []; }
			cur.push([t[0], t[1]]); prev = t;
		});
		if (cur.length >= 2) { strokes.push(cur); }
		return strokes.filter(function (pts) { return pathLengthM(pts) >= 15; }).map(function (pts) { return { type: 'path', points: pts }; });
	}
	/* Group strokes so each server call stays under the area cap (~3 km). */
	function strokeGroups(strokes) {
		var groups = [], cur = [], box = null;
		function grow(b, pts) { pts.forEach(function (p) { if (!b.n || p[0] > b.n) { b.n = p[0]; } if (!b.s || p[0] < b.s) { b.s = p[0]; } if (!b.e || p[1] > b.e) { b.e = p[1]; } if (!b.w || p[1] < b.w) { b.w = p[1]; } }); return b; }
		strokes.forEach(function (st) {
			var trial = grow(JSON.parse(JSON.stringify(box || {})), st.points);
			if (box && ((trial.n - trial.s) > 0.026 || (trial.e - trial.w) > 0.03)) { groups.push(cur); cur = []; box = null; trial = grow({}, st.points); }
			cur.push(st); box = trial;
		});
		if (cur.length) { groups.push(cur); }
		return groups;
	}
	function finishQuick() {
		var q = quick(); if (!q) { return; }
		var finished = Date.now();
		if (trimOldTrack(q)) { saveQuick(q); } // points from an earlier outing (a session left open) are not this walk
		if (sanityDriving(q.track, q.forced)) { saveQuick(q); } // a "driving" stretch at walking speed was walking
		var strokes = quickStrokes(q.track);
		if (!strokes.length) {
			// nothing usable to work houses out from: say so plainly and offer the way out (a toast was easy to miss)
			var poor = (q.track || []).filter(function (t) { return t[3] != null && t[3] > 40; }).length, all = (q.track || []).length;
			logEv('quick.done', 'no strokes: ' + all + ' pts, ' + poor + ' poor');
			askSheet({ title: 'Nothing to log yet', html: 'This walk ' + esc(quickDescription(q)) + ' — too little for the app to work out any houses' + (all && poor >= all / 2 ? ' (most of the GPS fixes were too inaccurate to use)' : (all ? '' : ' (no GPS fixes at all — was the screen locked?)')) + '. Keep walking with the app open and the screen on, or discard this walk.',
				primary: 'Discard this walk', danger: true, onPrimary: function () { discardQuick('nothing'); },
				secondary: 'Keep going', onSecondary: function () { if (S.tab !== 'quick') { S.tab = 'quick'; render(); } } });
			return;
		}
		stopGps(); if (D.wake) { try { D.wake.release(); } catch (e) {} D.wake = null; }
		if (q.ended) { finished = q.ended; } else { q.ended = finished; saveQuick(q); } // the walk is over from here on, whatever happens to the lookup
		quickSync(); // the server gets the whole track (best effort)
		logEv('quick.done', strokes.length + ' strokes, ' + Math.round(quickWalkMeters(q)) + ' m' + (q.id ? ' #' + q.id : ''));
		resetNewDrop();
		var nd = S.nd;
		nd.mode = 'map'; nd.step = 'busy'; nd.busy = 'Working out the houses you passed…'; nd.strokes = strokes;
		var driveMs = 0; for (var di = 1; di < q.track.length; di++) { if (q.track[di][4] === 'd' && q.track[di - 1][4] === 'd') { driveMs += q.track[di][2] - q.track[di - 1][2]; } }
		nd.quick = { started: q.started, finished: finished, track: q.track, pin: q.pin, walkM: quickWalkMeters(q), driveMin: Math.round(driveMs / 60000), id: q.id || null, gaps: q.gaps || [] };
		S.tab = 'new'; render();
		var groups = strokeGroups(strokes);
		var merged = { addresses: [], segments: [], streets: [], source: 'region', spatial: true, notes: [] }, seenA = {}, seenS = {};
		(function next(i) {
			if (i >= groups.length) { return gapCandidates(); }
			api('find-in-area', { method: 'POST', body: { shapes: groups[i].map(function (st) { return { type: 'path', points: st.points, buffer_m: 35 }; }) } }).then(function (r) {
				r.addresses.forEach(function (a) { var k = a.street_key + '|' + a.number + '|' + (a.unit || ''); if (seenA[k]) { if (a.selected) { seenA[k].selected = true; } return; } seenA[k] = a; merged.addresses.push(a); });
				r.segments.forEach(function (sg) { if (!seenS[sg.id]) { seenS[sg.id] = 1; merged.segments.push(sg); } });
				if (r.zip && !merged.zip) { merged.zip = r.zip; }
				next(i + 1);
			}).catch(function (e) { nd.error = 'House lookup failed: ' + e.message; nd.step = 'draw'; logEv('quick.done', 'lookup failed: ' + e.message); S.tab = 'quick'; render(); toast('Could not look up the houses (' + e.message + '). The walk is kept on this phone' + (q.id ? ' and on the site' : '') + ' — tap Count the houses when you have signal.', 10000); });
		})(0);
		/* GPS holes (a locked screen, a pocket, the border with no signal…). the walker walks those streets anyway and
		   confirms them on the review map — but the houses were only there if they happened to sit near the recorded
		   track. Now the stretch between the last fix before a hole and the first one after it is looked up too; those
		   houses arrive grey (not selected) so one tap on a dot or a road adds them. */
		function gapCandidates() {
			var shapes = [];
			(q.gaps || []).forEach(function (gp) {
				var before = null, after = null;
				q.track.forEach(function (t) { if (t[2] <= gp[0] + 1000) { before = t; } if (!after && t[2] >= gp[1] - 1000) { after = t; } });
				if (!before || !after || before === after) { return; }
				var dd = distM([before[0], before[1]], [after[0], after[1]]);
				if (dd < 40 || dd > 2500) { return; }
				shapes.push({ type: 'path', points: [[before[0], before[1]], [after[0], after[1]]], buffer_m: 0 });
			});
			if (!shapes.length) { return doneFinding(); }
			var got = 0;
			(function nextGap(j) {
				if (j >= shapes.length) {
					// how many houses sit along the unrecorded stretches (fetched now or already on the map) — for the note
					nd.quick.gapHouses = merged.addresses.filter(function (a) { return a.lat != null && shapes.some(function (sh) { return distToPathM([a.lat, a.lng], sh.points) <= 60; }); }).length;
					return doneFinding();
				}
				api('find-in-area', { method: 'POST', body: { shapes: [shapes[j]] } }).then(function (r) {
					r.addresses.forEach(function (a) { var k = a.street_key + '|' + a.number + '|' + (a.unit || ''); if (seenA[k]) { return; } a.selected = false; seenA[k] = a; merged.addresses.push(a); got++; });
					r.segments.forEach(function (sg) { if (!seenS[sg.id]) { seenS[sg.id] = 1; merged.segments.push(sg); } });
				}).catch(function (e) { logEv('quick.done', 'gap lookup failed: ' + e.message); }).then(function () { nextGap(j + 1); });
			})(0);
		}
		function doneFinding() {
			merged.streets = streetsFromAddresses(merged.addresses);
			nd.geo = merged;
			proposeSelection();
			var sides = inferSides(q.track, merged);
			nd.quick.sides = sides.summary; nd.quick.farKeys = sides.farKeys; nd.quick.offRoute = sides.offRoute; nd.quick.oneSided = sides.oneSided;
			nd.quick.bothSides = bothSidesDefault();
			quickSides(nd);
			if (!merged.addresses.some(function (a) { return nd.sel[a._k]; })) { nd.error = 'No address points were found along the walked streets.'; }
			nd.form.name = 'Quick walk — ' + fmtDate(localDT(q.started).slice(0, 10), true) + ' ' + fmtClock(q.started); nd.form.date = localDT(q.started).slice(0, 10);
			nd.form.status = 'done'; nd.form.time = new Date(q.started).toTimeString().slice(0, 5);
			nd.step = 'review'; render();
		}
	}
	/* Streets list from address points (same shape the server returns for drawn routes). */
	function streetsFromAddresses(addresses) {
		var by = {};
		addresses.forEach(function (a) { var k = a.street_key; if (!by[k]) { by[k] = { name: a.street, key: k, count: 0, selected: 0, zip: a.zip, city: a.city, coverage: 'partial', from: '', to: '', note: '' }; } by[k].count++; if (a.selected) { by[k].selected++; } });
		return Object.keys(by).map(function (k) { return by[k]; }).sort(function (x, y) { return (y.selected - x.selected) || x.name.localeCompare(y.name); });
	}
	/* Which side of each road did the walker keep to? The houses across the street are kept too — a walker often
	   hangs both sides, and re-adding them by hand on every walk is slow. They are collected in farKeys; quickSides()
	   keeps them (default) or leaves them out when "Both sides" is switched off. */
	function inferSides(track, g) {
		var nd = S.nd;
		var walk = track.filter(function (t) { return t[4] !== 'd' && (t[3] == null || t[3] <= 30); });
		var perSeg = {};
		walk.forEach(function (t) {
			var p = [t[0], t[1]], best = null, bestD = 30;
			g.segments.forEach(function (sg) { var r = sideAndDist(p, sg.path); if (r.dist < bestD) { bestD = r.dist; best = { sg: sg, r: r }; } });
			if (!best) { return; }
			var e = perSeg[best.sg.id] = perSeg[best.sg.id] || { sg: best.sg, n: 0, sum: 0, pos: 0, nx: 0, ny: 0 };
			e.n++; e.sum += best.r.side * best.r.dist; if (best.r.side > 0) { e.pos++; } e.nx += best.r.nx; e.ny += best.r.ny;
		});
		// a road counts as walked when at least 6 usable fixes ran along it
		var walked = {}, bias = {}, notes = [], summary = {};
		Object.keys(perSeg).forEach(function (id) {
			var e = perSeg[id];
			if (e.n < 6) { return; }
			walked[id] = e;
			var mean = e.sum / e.n, share = Math.max(e.pos, e.n - e.pos) / e.n;
			var label = e.sg.name + (e.sg.low ? ' ' + e.sg.low + '–' + e.sg.high : '');
			if (Math.abs(mean) >= 2 && share >= 0.7) { bias[id] = mean > 0 ? 1 : -1; summary[label] = compassOfNormal(e.nx / e.n, e.ny / e.n, bias[id]) + ' side'; }
			else { bias[id] = 0; summary[label] = 'both sides'; }
		});
		var byId = {}; g.segments.forEach(function (sg) { byId[sg.id] = sg; });
		var farSide = 0, offRoute = 0, farKeys = [];
		g.addresses.forEach(function (a) {
			if (!nd.sel[a._k] || a.lat == null) { return; }
			var sg = null, r = null;
			if (a._seg != null && byId[a._seg]) {
				if (!walked[a._seg]) { delete nd.sel[a._k]; offRoute++; return; } // faces a street that was not walked (corner houses on the cross street, the driven stretch)
				sg = byId[a._seg]; r = sideAndDist([a.lat, a.lng], sg.path);
			} else { // no road id on the point: nearest walked road within 40 m, else off the route
				var bestD = 40;
				Object.keys(walked).forEach(function (id) { var rr = sideAndDist([a.lat, a.lng], walked[id].sg.path); if (rr.dist < bestD) { bestD = rr.dist; sg = walked[id].sg; r = rr; } });
				if (!sg) { delete nd.sel[a._k]; offRoute++; return; }
			}
			var b = bias[sg.id];
			if (b && r.side !== b) { farKeys.push(a._k); farSide++; }
		});
		return { summary: summary, farKeys: farKeys, offRoute: offRoute, oneSided: Object.keys(bias).filter(function (id) { return bias[id]; }).length };
	}
	function sideLine(qk) {
		if (!qk.farKeys || !qk.farKeys.length) { return 'Both sides kept.'; }
		return qk.bothSides ? 'Both sides kept — on ' + qk.oneSided + ' block' + (qk.oneSided === 1 ? '' : 's') + ' your GPS stayed on one side; ' + qk.farKeys.length + ' houses across the street are included. Switch off "Both sides" if you skipped them.'
			: 'Only the side you walked: ' + qk.farKeys.length + ' houses across the street are left out (grey). Tap them to add, or switch "Both sides" back on.';
	}
	/* Both sides of the street (default) or only the side the GPS walked — applied to the selection, notes to match. */
	function bothSidesDefault() { return recall('quick_sides') !== 'walked'; }
	function quickSides(nd) {
		var qk = nd.quick; if (!qk || !qk.farKeys) { return; }
		var byK = {}; nd.geo.addresses.forEach(function (a) { byK[a._k] = a; });
		qk.farKeys.forEach(function (k) {
			var a = byK[k]; if (!a) { return; }
			if (qk.bothSides) { if (!(nd.skipUnits && a.unit)) { nd.sel[k] = true; } } else { delete nd.sel[k]; }
		});
		var notes = [];
		if (qk.bothSides) {
			notes.push('both sides of every street' + (qk.farKeys.length ? ' (' + qk.farKeys.length + ' across from where the GPS walked)' : ''));
		} else {
			Object.keys(qk.sides || {}).forEach(function (nm) { notes.push(nm + ': ' + qk.sides[nm]); });
			if (qk.farKeys.length) { notes.push(qk.farKeys.length + ' on the far side left out'); }
		}
		if (qk.offRoute) { notes.push(qk.offRoute + ' on other streets left out'); }
		qk.sideNotes = notes;
	}

	/* ===================================================== CLUSTERS */
	var logMap = null, logLayers = {};
	/* the walker likes the map for looking back at past routes: a card's Map button brings its route into view and lights it up. */
	function showOnLogMap(id) {
		var m = logMap, L1 = logLayers[id];
		if (!m || !L1 || !L1.pts.length) { toast('This cluster has no route drawn on the map.'); return; }
		var box = document.getElementById('log-map');
		if (box) { scrollTo(box); }
		var cardEl = document.getElementById('zdh-card-' + id); if (cardEl) { setCardOpen(cardEl, true); }
		if (L1.pts.length === 1) { m.setView(L1.pts[0], 17); } else { m.fitBounds(L.latLngBounds(L1.pts).pad(0.2), { maxZoom: 18 }); }
		L1.lines.forEach(function (ln) { ln.setStyle({ color: '#F2812A', weight: 9, opacity: 1 }); ln.bringToFront(); });
		setTimeout(function () { L1.lines.forEach(function (ln) { try { ln.setStyle(L1.style); } catch (e) {} }); }, 2600);
		var first = L1.lines[0] || L1.marker;
		if (first) { setTimeout(function () { try { first.openPopup(L1.pts[Math.floor(L1.pts.length / 2)]); } catch (e) {} }, 450); }
	}
	function renderMapLog(main) {
		main.innerHTML = '<div class="zdh-card zdh-busy"><div class="zdh-spinner"></div><div class="zdh-busy-text">Loading drops…</div></div>';
		loadDrops().then(function () { drawMapLog(main); }).catch(function (e) { logEv('error', 'clusters: ' + (e && e.stack ? String(e.stack).slice(0, 600) : e)); main.innerHTML = '<div class="zdh-error">' + esc(e.message) + '</div>'; });
	}
	function groupByDate(list) {
		var by = {};
		list.forEach(function (d) { var k = d.drop_date || 'undated'; (by[k] = by[k] || []).push(d); });
		return Object.keys(by).sort().reverse().map(function (k) { return { date: k, items: by[k] }; });
	}
	function drawMapLog(main) {
		var drops = S.drops, st = S.stats || {};
		var totalResp = 0, totalConv = 0, anyUp = false;
		drops.forEach(function (d) { if (d.responses != null) { totalResp += d.responses; anyUp = true; } if (d.converted != null) { totalConv += d.converted; anyUp = true; } });
		// "Houses on file" always equalled "Hangers hung" on the phone — this month's count says more, and the
		// miles walked are on the wide screen. The month is the phone's own.
		var ym = todayISO().slice(0, 7), monthHung = 0, walkedM = 0;
		drops.forEach(function (d) {
			if (d.status !== 'done') { return; }
			if ((d.drop_date || '').slice(0, 7) === ym) { monthHung += d.quantity != null ? +d.quantity : (d.delivered_count != null ? +d.delivered_count : +d.address_count || 0); }
			if (d.walked_m) { walkedM += +d.walked_m; }
		});
		var monthName = new Date(todayISO() + 'T12:00:00').toLocaleDateString('en-US', { month: 'long' });
		var stats = [[st.hangers || 0, 'Hangers hung', ''], [monthHung, 'In ' + monthName, ''], [st.drops || 0, 'Clusters done', ''], [walkedM ? (walkedM / 1609.34).toFixed(walkedM < 16093 ? 1 : 0) : '—', 'Miles walked', 'zdh-desk'], [st.addresses || 0, 'Houses on file', 'zdh-desk'], [st.zips || 0, 'ZIPs', 'zdh-desk'], [anyUp ? totalResp : '—', 'Responses', 'zdh-desk'], [anyUp ? totalConv : '—', 'Converted', 'zdh-desk']];
		var due = drops.filter(function (d) { return d.status === 'done' && d.revisit_due && daysUntil(d.revisit_due) <= 14; }).sort(function (a, b) { return a.revisit_due < b.revisit_due ? -1 : 1; });
		var active = drops.filter(function (d) { return d.status === 'in_progress'; });
		var todo = drops.filter(isTodo).sort(function (a, b) { return (a.due_by || a.drop_date || '9999') < (b.due_by || b.drop_date || '9999') ? -1 : 1; });
		var planned = drops.filter(function (d) { return d.status === 'planned' && !isTodo(d); }).sort(function (a, b) { return (a.drop_date || '') < (b.drop_date || '') ? -1 : 1; });
		var done = drops.filter(function (d) { return d.status === 'done'; });
		main.innerHTML = '<div id="log-map" class="zdh-map zdh-map-tall"></div>' +
			'<div class="zdh-legend"><span><i style="background:' + COLOR_DONE + '"></i> covered</span><span><i style="background:' + COLOR_TODO + ';border:1px dashed #fff"></i> to be done</span><span><i style="background:' + COLOR_PLANNED + ';border:1px dashed #fff"></i> planned</span><span><i style="background:' + COLOR_ACTIVE + '"></i> in progress</span></div>' +
			'<div class="zdh-stats">' + stats.map(function (s) { return '<div class="zdh-stat ' + s[2] + '"><div class="zdh-stat-n">' + esc(s[0]) + '</div><div class="zdh-stat-l">' + esc(s[1]) + '</div></div>'; }).join('') + '</div>' +
			(active.length ? '<details class="zdh-log" open><summary><h2>In progress <span class="zdh-log-n">' + active.length + '</span></h2></summary><div id="log-active"></div></details>' : '') +
			(due.length ? '<details class="zdh-log" open><summary><h2>Due for another round <span class="zdh-log-n">' + due.length + '</span></h2></summary>' + due.map(function (d) { var n = daysUntil(d.revisit_due); return '<div class="zdh-due"><div><strong>' + esc(d.name || d.streets_summary) + '</strong><div class="zdh-muted">' + esc(d.city) + ' ' + esc(d.zip) + ' &bull; covered ' + esc(fmtDate(d.drop_date, true)) + ' &bull; ' + (n < 0 ? Math.abs(n) + ' days overdue' : (n === 0 ? 'due today' : 'due in ' + n + ' days')) + '</div></div><div class="zdh-due-btns"><button type="button" class="zdh-mini" data-replan="' + d.id + '">Plan again</button><a class="zdh-mini" href="' + esc(url('export?_wpnonce=' + encodeURIComponent(C.nonce) + '&type=ics&drop=' + d.id)) + '">Calendar</a></div></div>'; }).join('') + '</details>' : '') +
			(todo.length ? '<details class="zdh-log" open><summary><h2>To be done <span class="zdh-log-n">' + todo.length + '</span></h2></summary><div id="log-todo"></div></details>' : '') +
			'<details class="zdh-log" open><summary><h2>Planned <span class="zdh-log-n">' + planned.length + '</span></h2></summary><div id="log-planned">' + (planned.length ? '' : '<p class="zdh-muted">Nothing planned yet.</p>') + '</div></details>' +
			'<details class="zdh-log" open><summary><h2>Completed <span class="zdh-log-n">' + done.length + '</span></h2></summary><div id="log-done">' + (done.length ? '' : '<p class="zdh-muted">Nothing completed yet.</p>') + '</div></details>';

		if (logMap) { killMap(logMap); logMap = null; }
		var map = L.map('log-map', { tap: false });
		logMap = map;
		tileLayer().addTo(map);
		addTextControl(map);
		logLayers = {};
		var pts = paintCoverage(map, drops, { markers: true, byId: logLayers });
		if (pts.length) { map.fitBounds(L.latLngBounds(pts).pad(0.15)); } else { homeView(map, 9); }
		settle(map);
		map.on('popupopen', function (e) { // "Show the card" inside a route's popup scrolls to that cluster's card
			var a = e.popup.getElement() && e.popup.getElement().querySelector('[data-card]');
			if (a) { a.addEventListener('click', function (ev) { ev.preventDefault(); var c = document.getElementById('zdh-card-' + a.dataset.card); if (c) { var det = c.closest('details'); if (det) { det.open = true; } setCardOpen(c, true); c.scrollIntoView({ behavior: 'smooth', block: 'center' }); c.classList.add('zdh-flash'); setTimeout(function () { c.classList.remove('zdh-flash'); }, 1600); } }); }
		});

		var la = document.getElementById('log-active'); if (la) { active.forEach(function (d) { la.appendChild(dropCard(d)); }); }
		var lt = document.getElementById('log-todo'); if (lt) { todo.forEach(function (d) { lt.appendChild(dropCard(d)); }); }
		var lp = document.getElementById('log-planned');
		groupByDate(planned).reverse().forEach(function (grp) { lp.appendChild(el('<h3 class="zdh-datehead">' + esc(grp.date === 'undated' ? 'No date' : fmtDate(grp.date)) + '</h3>')); grp.items.forEach(function (d) { lp.appendChild(dropCard(d)); }); });
		var ld = document.getElementById('log-done');
		if (done.length > 1) {
			var tog = el('<p class="zdh-log-tools"><button type="button" class="zdh-linkbtn" id="log-openall">Open all</button></p>');
			ld.appendChild(tog);
			tog.querySelector('button').addEventListener('click', function (e) {
				var b = e.currentTarget, openAll = b.textContent === 'Open all';
				ld.querySelectorAll('.zdh-drop').forEach(function (c) { setCardOpen(c, openAll); });
				b.textContent = openAll ? 'Fold all' : 'Open all';
			});
		}
		groupByDate(done).forEach(function (grp) { ld.appendChild(el('<h3 class="zdh-datehead">' + esc(grp.date === 'undated' ? 'No date' : fmtDate(grp.date)) + '</h3>')); grp.items.forEach(function (d) { ld.appendChild(dropCard(d)); }); });
		main.querySelectorAll('[data-replan]').forEach(function (b) { b.addEventListener('click', function () { replan(+b.dataset.replan); }); });
	}
	/* Copy a finished cluster into a new planned one (same houses), for the next round. */
	function replan(id) {
		api('drops/' + id).then(function (d) {
			var addresses = d.addresses.map(function (a) { return { number: a.house_number, unit: a.unit, street: a.street, city: a.city, state: a.state, zip: a.zip, lat: a.lat, lng: a.lng, source: a.source, confidence: a.confidence, seg: a.road_seg_id, apn: a.apn }; });
			var f = S.nd.form;
			return api('drops', { method: 'POST', body: { drop: { drop_date: todayISO(), drop_time: '', delivered_by: f.delivered_by, hanger_type: f.hanger_type, hanger_side: f.hanger_side, service: C.service || '', city: d.city, state: d.state, zip: d.zip, county: d.county, streets_summary: d.streets_summary, description: d.description, notes: '', lat: d.lat, lng: d.lng, attachment_ids: [], status: 'planned', revisit_days: d.revisit_days, method: 'map', name: (d.name || d.streets_summary) + ' (again)' }, geometry: d.geometry || {}, addresses: addresses, streets: d.streets.map(function (s) { return { street: s.street, city: s.city, zip: s.zip, coverage: s.coverage, low: s.low_number, high: s.high_number, count: s.address_count }; }) } });
		}).then(function () { S.drops = null; render(); }).catch(function (e) { toastError(e); });
	}
	/* Load a planned cluster into the Plan screen for contextual editing. */
	function editRoute(id) {
		api('drops/' + id).then(function (d) {
			resetNewDrop();
			var nd = S.nd, f = nd.form;
			nd.editId = d.id; nd.mode = 'map'; nd.step = 'draw';
			f.name = d.name || ''; f.hanger_type = d.hanger_type || f.hanger_type; f.hanger_side = d.hanger_side || ''; f.delivered_by = d.delivered_by || f.delivered_by; f.date = d.drop_date || f.date; f.revisit_days = d.revisit_days != null ? d.revisit_days : f.revisit_days; f.notes = d.notes || ''; f.zip = d.zip || f.zip; f.city = d.city || f.city;
			nd.strokes = ((d.geometry || {}).strokes || []).map(function (st) { return { type: st.type, points: st.points }; });
			nd.editSel = {};
			d.addresses.forEach(function (a) { nd.editSel[streetKey(a.street) + '|' + a.house_number + '|' + (a.unit || '')] = true; });
			S.tab = 'new'; render();
		}).catch(function (e) { toastError(e); });
	}

	/* Split an already-planned cluster into walks from its stored houses and road geometry. */
	function splitExisting(id, size, style) {
		return api('drops/' + id).then(function (full) {
			var houses = full.addresses.filter(function (a) { return a.lat != null; }).map(function (a) { return { k: String(a.id), lat: a.lat, lng: a.lng, seg: a.road_seg_id != null ? +a.road_seg_id : null, street: a.street, street_key: a.street_key }; });
			if (!houses.length) { throw new Error('This cluster has no mapped houses to split.'); }
			var g = full.geometry || {}, start = g.strokes && g.strokes[0] && g.strokes[0].points ? g.strokes[0].points[0] : null;
			var W = planWalks(houses, g.segments || [], { size: size, style: style, perHour: +C.walkRate || 40, start: start });
			var assign = {};
			W.walks.forEach(function (w) { w.keys.forEach(function (k, i) { assign[k] = [w.n, i]; }); });
			var meta = { size: W.size, style: W.style, per_hour: W.perHour, list: W.walks.map(function (w) { return { n: w.n, count: w.count, est_min: w.est_min, streets: w.streets.join(', '), status: 'planned' }; }) };
			return api('drops/' + id + '/walks', { method: 'POST', body: { walks: meta, assign: assign } });
		});
	}
	/* A Quick Start walk in progress on the server: this phone's own (open it) or one from another device / a lost session (count or discard it). */
	function quickLiveCard(d) {
		var q = quick(), mine = q && q.id === d.id;
		var mi = d.track_m != null ? (d.track_m / 1609.34).toFixed(1) + ' mi' : '';
		var when = d.track_first ? fmtClock(d.track_first) + (d.track_last ? '–' + fmtClock(d.track_last) : '') : (d.started_at ? String(d.started_at).slice(11, 16) : '');
		var card = el('<div class="zdh-drop zdh-drop-active zdh-drop-quicklive" style="border-left-color:' + COLOR_ACTIVE + '">' +
			'<div class="zdh-drop-title"><span class="zdh-badge" style="background:' + COLOR_ACTIVE + '">In progress #' + d.id + '</span><span class="zdh-badge-soft">quick walk</span></div>' +
			'<div class="zdh-drop-name">' + esc(d.name || 'Quick walk') + '</div>' +
			'<div class="zdh-drop-detail"><strong>Recorded:</strong> ' + esc(fmtDate(d.drop_date, true)) + (when ? ' ' + esc(when) : '') + (mi ? ' &bull; ' + esc(mi) : '') + (d.track_points ? ' &bull; ' + d.track_points + ' GPS points' : ' &bull; no GPS points yet') + '<br>' +
				(mine ? '<em>Recording on this phone.</em>' : '<em>The houses have not been counted yet' + (q ? '' : ' — count them here, or discard the walk if it was a mistake') + '.</em>') + '</div>' +
			'<div class="zdh-drop-btns">' + (mine ? '<button type="button" class="zdh-mini zdh-mini-primary" data-a="open">Open</button>' : '<button type="button" class="zdh-mini zdh-mini-primary" data-a="count"' + (q ? ' disabled title="Finish the walk on this phone first"' : '') + '>Count the houses</button>') + '<button type="button" class="zdh-mini zdh-danger" data-a="discard">Discard</button></div>' +
			'<div class="zdh-drop-extra" hidden></div></div>');
		var o = card.querySelector('[data-a="open"]'); if (o) { o.addEventListener('click', function () { S.tab = 'quick'; render(); }); }
		var cnt = card.querySelector('[data-a="count"]'); if (cnt) { cnt.addEventListener('click', function () { cnt.disabled = true; cnt.textContent = 'Loading…'; countQuickFromServer(d); }); }
		card.querySelector('[data-a="discard"]').addEventListener('click', function () {
			askSheet({ title: 'Discard this walk?', html: 'The recorded track (' + (mi || 'no distance') + ') is deleted from the site' + (mine ? ' and from this phone' : '') + '. Nothing is logged.', primary: 'Discard it', danger: true, onPrimary: function () {
				if (mine) { discardQuick('card'); return; }
				api('drops/' + d.id + '/discard-quick', { method: 'POST' }).then(function () { S.drops = null; render(); toast('Walk discarded.'); }).catch(function (e) { toast('Could not discard: ' + e.message); });
			}, secondary: 'Keep it' });
		});
		return card;
	}
	/* cards start folded to one tap-target — the name and the few numbers that matter; the rest opens on a tap.
	   Which cards are open is remembered while the app is open (not across reloads). */
	var OPEN_CARDS = {};
	function setCardOpen(card, open) {
		var id = +String(card.id).replace('zdh-card-', ''), body = card.querySelector('.zdh-drop-body'), head = card.querySelector('.zdh-drop-head');
		if (!body) { return; }
		body.hidden = !open; card.classList.toggle('zdh-drop-open', open);
		if (head) { head.setAttribute('aria-expanded', open ? 'true' : 'false'); }
		if (open) { OPEN_CARDS[id] = 1; } else { delete OPEN_CARDS[id]; }
	}
	function cardTitle(d) {
		var nm = d.name || d.streets_summary || ('Cluster #' + d.id);
		if (/^Quick walk\b/.test(nm) && d.streets_summary) { // "Quick walk — Jan 5, 2026 9:56 AM" says nothing the date heading doesn't
			var st = d.streets_summary.split(' / ');
			nm = st.slice(0, 2).join(' & ') + (st.length > 2 ? ' +' + (st.length - 2) : '');
		}
		return nm;
	}
	function cardSummary(d) {
		var bits = [], planned = d.status === 'planned', active = d.status === 'in_progress';
		if (planned) {
			bits.push(fmtDate(d.drop_date, true)); bits.push(d.address_count + (d.address_count === 1 ? ' house' : ' houses'));
			var wl = walkList(d); if (wl.length > 1) { bits.push(wl.length + ' walks'); }
		} else if (active) {
			bits.push('in progress'); bits.push((d.delivered_count != null ? d.delivered_count : 0) + ' of ' + d.address_count + ' houses');
		} else {
			if (d.started_at) { bits.push(fmtTime(String(d.started_at).slice(11, 16))); } else if (d.drop_time) { bits.push(fmtTime(d.drop_time)); }
			bits.push((d.quantity != null ? d.quantity : (d.delivered_count != null ? d.delivered_count : d.address_count)) + ' hangers');
			if (d.duration_min) { bits.push(fmtMin(d.duration_min)); }
			if (d.walked_m) { bits.push(fmtMiles(d.walked_m)); }
		}
		if (d.city) { bits.push(d.city); }
		if (d.delivered_by && !planned) { bits.push(d.delivered_by); }
		return bits.join(' · ');
	}
	function dropCard(d) {
		if (d.method === 'quick' && d.status === 'in_progress') { return quickLiveCard(d); }
		var e = d.enrichment || {}, w = e.weather || {}, c = e.census || {}, ss = e.screenshot || {};
		var planned = d.status === 'planned', active = d.status === 'in_progress';
		var ctx = [];
		if (w.temp_f != null) { ctx.push('🌡 ' + w.temp_f + '°F' + (w.heat_index_f != null && w.heat_index_f > w.temp_f ? ' (heat index ' + w.heat_index_f + ')' : '')); }
		else if (ss.temperature_f != null) { ctx.push('🌡 ' + ss.temperature_f + '°F (screenshot)'); }
		if (w.humidity_pct != null) { ctx.push('💧 ' + w.humidity_pct + '%'); }
		if (w.wind_mph != null) { ctx.push('wind ' + w.wind_mph + ' mph' + (w.wind_direction ? ' ' + w.wind_direction : '') + (w.wind_gusts_mph ? ' (gusts ' + w.wind_gusts_mph + ')' : '')); }
		if (w.aqi_us != null) { ctx.push('AQI ' + w.aqi_us); } else if (ss.aqi != null) { ctx.push('AQI ' + ss.aqi + ' (screenshot)'); }
		if (w.daily_high_f != null) { ctx.push('high ' + w.daily_high_f + '°F'); }
		if (e.fire_hazard_zone) { ctx.push('🔥 ' + e.fire_hazard_zone); }
		if (e.elevation_ft != null) { ctx.push('⛰ ' + e.elevation_ft + ' ft'); }
		if (e.fire_history && e.fire_history.last_fire_year) { ctx.push('past fire ' + e.fire_history.last_fire_year + (e.fire_history.fires > 1 ? ' (' + e.fire_history.fires + ' fires)' : '')); }
		if (e.miles_to_coast != null) { ctx.push(e.miles_to_coast + ' mi to the coast'); }
		if (c.acs_level === 'ZIP' && c.zip) { ctx.push('ZIP ' + c.zip + ' figures' + (c.acs_year ? ' (ACS ' + c.acs_year + ')' : '') + ':'); }
		if (c.median_household_income) { ctx.push('income $' + Math.round(c.median_household_income / 1000) + 'k'); }
		if (c.median_home_value) { ctx.push('home $' + Math.round(c.median_home_value / 1000) + 'k'); }
		if (c.median_year_built) { ctx.push('built ~' + c.median_year_built); }
		if (c.owner_occupied_pct != null) { ctx.push(c.owner_occupied_pct + '% owner-occ.'); }
		var dueTxt = '';
		if (!planned && d.revisit_due) { var n = daysUntil(d.revisit_due); dueTxt = '<br><strong>Revisit:</strong> ' + esc(fmtDate(d.revisit_due, true)) + ' (' + (n < 0 ? Math.abs(n) + ' days overdue' : 'in ' + n + ' days') + ') &bull; <a href="' + esc(url('export?_wpnonce=' + encodeURIComponent(C.nonce) + '&type=ics&drop=' + d.id)) + '">add to calendar</a>'; }
		// the side bar says the status, in the map legend's colours (blue = covered, green = planned, pink = in progress, orange = to be done);
		// the old per-cluster colour was arbitrary
		var color = isTodo(d) ? COLOR_TODO : (planned ? COLOR_PLANNED : (active ? COLOR_ACTIVE : COLOR_DONE));
		var timing = '';
		if (!planned && d.started_at) { var pc = paceOf(d); timing = '<br><strong>Walked:</strong> ' + esc(fmtTime(String(d.started_at).slice(11, 16))) + (d.finished_at ? ' – ' + esc(fmtTime(String(d.finished_at).slice(11, 16))) : ' (in progress)') + (d.duration_min != null ? ' &bull; ' + esc(fmtMin(d.duration_min)) : '') + (d.walked_m ? ' &bull; ' + esc(fmtMiles(d.walked_m)) : '') + (pc ? ' &bull; ' + pc + ' an hour' : ''); }
		var wlist = walkList(d), multi = wlist.length > 1, nextN = nextWalkOf(d), wc = d.walk_counts || {};
		var walksHtml = '';
		if (multi) {
			walksHtml = '<div class="zdh-walklist">' + wlist.map(function (w) {
				var c = wc[w.n] || [0, w.count], st = w.status, icon = st === 'done' ? '✓' : (st === 'in_progress' ? '▶' : String(w.n));
				return '<div class="zdh-walk-row zdh-walk-' + esc(st) + '"><span class="zdh-walk-swatch" style="background:' + walkColor(+w.n) + '">' + icon + '</span><span class="zdh-walk-main"><strong>Walk ' + w.n + '</strong> &bull; ' + (st === 'planned' ? c[1] + ' houses' : c[0] + ' of ' + c[1]) + (w.est_min && st !== 'done' ? ' &bull; ≈ ' + esc(fmtMin(w.est_min)) : '') + (st === 'in_progress' ? ' &bull; <em>in progress</em>' : (st === 'done' ? ' &bull; done' : '')) + '<br><span class="zdh-walk-streets">' + esc(w.streets || '') + '</span></span>' + (!planned && !active ? '' : (st === 'done' || +w.n === nextN ? '' : '<button type="button" class="zdh-mini" data-walk="' + w.n + '">' + (st === 'in_progress' ? 'Resume' : 'Start') + '</button>')) + '</div>';
			}).join('') + '</div>';
		}
		var canSplit = planned && !multi && d.address_count > (+C.walkSize || 60);
		var open = !!OPEN_CARDS[d.id];
		var BTNS = '<div class="zdh-drop-btns">' + (planned ? '<button type="button" class="zdh-mini zdh-mini-primary" data-a="start">' + (multi ? 'Start walk ' + nextN : 'Start delivering') + '</button>' : '') + (active ? '<button type="button" class="zdh-mini zdh-mini-primary" data-a="start">' + (multi ? 'Resume walk ' + nextN : 'Resume') + '</button>' : '') + (logLayers[d.id] && logLayers[d.id].pts.length ? '<button type="button" class="zdh-mini" data-a="showmap" title="Show this route on the map above">📍 Map</button>' : '') + (planned ? '' : '<button type="button" class="zdh-mini" data-a="share" title="Send a summary of this walk (Messages, Mail…)">Share</button>') + '<button type="button" class="zdh-mini zdh-more" data-a="more" aria-expanded="false">More ▾</button></div>' +
			'<div class="zdh-drop-btns zdh-drop-more" hidden>' + (planned ? '<button type="button" class="zdh-mini" data-a="route">Edit route</button>' + (canSplit ? '<button type="button" class="zdh-mini" data-a="split">Split into walks</button>' : '') + '<button type="button" class="zdh-mini" data-a="done">Mark done</button>' : '') + (active ? '<button type="button" class="zdh-mini" data-a="done">Mark done</button><button type="button" class="zdh-mini" data-a="reset">Back to planned</button>' : '') + '<button type="button" class="zdh-mini" data-a="addr">Houses</button><button type="button" class="zdh-mini" data-a="uptake">Edit</button>' + (planned || active ? '' : '<button type="button" class="zdh-mini" data-a="enrich">Refresh data</button>') + (C.isAdmin ? '<button type="button" class="zdh-mini zdh-danger" data-a="del">Delete</button>' : '') + '</div>' + '<div class="zdh-drop-extra" hidden></div>';
		var card = el('<div class="zdh-drop' + (planned ? ' zdh-drop-planned' : '') + (active ? ' zdh-drop-active' : '') + (open ? ' zdh-drop-open' : '') + '" id="zdh-card-' + d.id + '" style="border-left-color:' + esc(color) + '">' +
			'<button type="button" class="zdh-drop-head" aria-expanded="' + (open ? 'true' : 'false') + '"><span class="zdh-drop-head-main"><span class="zdh-drop-hname">' + esc(cardTitle(d)) + '</span><span class="zdh-drop-sum">' + esc(cardSummary(d)) + '</span></span><span class="zdh-drop-chev" aria-hidden="true">▾</span></button>' +
			(planned || active ? BTNS : '') +
			'<div class="zdh-drop-body"' + (open ? '' : ' hidden') + '>' +
			'<div class="zdh-drop-title"><span class="zdh-badge" style="background:' + esc(color) + '">' + (isTodo(d) ? 'To be done ' : (planned ? 'Planned ' : (active ? 'In progress ' : ''))) + '#' + d.id + '</span><span class="zdh-badge zdh-badge-type">' + esc(d.hanger_type || 'Standard') + (d.hanger_side ? ' · side ' + esc(d.hanger_side) : '') + '</span>' + (d.method === 'map' ? '<span class="zdh-badge-soft">drawn</span>' : (d.method === 'quick' ? '<span class="zdh-badge-soft">quick walk</span>' : (d.method === 'paper' ? '<span class="zdh-badge-soft">paper map</span>' : '<span class="zdh-badge-soft">screenshot</span>'))) + '</div>' +
			(cardTitle(d) !== (d.name || d.streets_summary) && !/^Quick walk\b/.test(d.name || '') ? '<div class="zdh-drop-name">' + esc(d.name || d.streets_summary) + '</div>' : '') +
			'<div class="zdh-drop-detail">' +
				'<span class="zdh-streets"><strong>Streets:</strong> ' + esc(d.streets_summary) + ', ' + esc(d.city) + ' ' + esc(d.zip) + '</span><br>' +
				'<strong>' + (planned ? 'Planned for' : 'Date') + ':</strong> ' + tx(esc(fmtDate(d.drop_date, true)), esc(fmtDate(d.drop_date))) + (!planned && d.drop_time ? ' at ' + esc(fmtTime(d.drop_time)) : '') + timing + '<br>' +
				(planned ? '<strong>' + d.address_count + '</strong> houses on the route' + (multi ? ' in <strong>' + wlist.length + '</strong> walks' : '') : '<strong>' + (d.delivered_count != null ? d.delivered_count : d.address_count) + '</strong> of ' + d.address_count + ' houses delivered' + (d.quantity != null ? ' &bull; <strong>' + esc(d.quantity) + '</strong> hangers hung' : '')) + '<br>' +
				todoLine(d) +
				(todoLine(d) ? '' : '<strong>' + (planned ? 'Deliverer' : 'Delivered by') + ':</strong> ' + personChip(d.delivered_by) + esc(d.delivered_by || '—')) +
				dueTxt +
				(d.notes ? '<br><strong>Notes:</strong> ' + esc(String(d.notes).replace(/;? ?tap a grey dot to add it/g, '')) : '') +
				(window.ZDZSealed ? window.ZDZSealed.buttons(d.sealed) : '') + // Show code for the walker on the route's day
			'</div>' +
			(ctx.length ? '<div class="zdh-chips">' + ctx.map(function (x) { return '<span class="zdh-chip">' + esc(x) + '</span>'; }).join('') + '</div>' : '') +
			(planned || active ? '' : '<div class="zdh-uptake"><span class="zdh-chip ' + (d.responses != null ? 'has' : '') + '">Responses: <b>' + esc(d.responses != null ? d.responses : '—') + '</b></span><span class="zdh-chip ' + (d.converted != null ? 'has' : '') + '">Converted: <b>' + esc(d.converted != null ? d.converted : '—') + '</b></span></div>') +
			'<div class="zdh-shots">' + (d.screenshots || []).map(function (s) { return '<a href="' + esc(s.url) + '" target="_blank" rel="noopener"><img src="' + esc(s.thumb) + '" alt="screenshot"></a>'; }).join('') + '</div>' +
			walksHtml +
			(planned || active ? '' : BTNS) +
			'</div>' +
		'</div>');
		var head = card.querySelector('.zdh-drop-head'), body = card.querySelector('.zdh-drop-body');
		head.addEventListener('click', function () { setCardOpen(card, body.hidden); });
		var extra = card.querySelector('.zdh-drop-extra');
		var moreBtn = card.querySelector('[data-a="more"]'), moreRow = card.querySelector('.zdh-drop-more');
		moreBtn.addEventListener('click', function () { var open = moreRow.hidden; moreRow.hidden = !open; moreBtn.setAttribute('aria-expanded', open ? 'true' : 'false'); moreBtn.textContent = open ? 'Less ▴' : 'More ▾'; });
		var mapBtn = card.querySelector('[data-a="showmap"]'); if (mapBtn) { mapBtn.addEventListener('click', function () { showOnLogMap(d.id); }); }
		var shareBtn = card.querySelector('[data-a="share"]'); if (shareBtn) { shareBtn.addEventListener('click', function () { shareText('Door-hanger walk #' + d.id, dropSummaryText(d)); }); }
		var startBtn = card.querySelector('[data-a="start"]');
		if (startBtn) { startBtn.addEventListener('click', function () { var label = startBtn.textContent; startBtn.disabled = true; startBtn.textContent = 'Starting…'; primeLocation(); startSession(d.id, nextN).catch(function (err) { startBtn.disabled = false; startBtn.textContent = label; if (err.silent) { return; } extra.hidden = false; extra.innerHTML = '<div class="zdh-error">' + esc(err.message) + '</div>'; }); }); }
		card.querySelectorAll('[data-walk]').forEach(function (b) {
			b.addEventListener('click', function () { var label = b.textContent, n = +b.dataset.walk; b.disabled = true; b.textContent = 'Starting…'; primeLocation(); startSession(d.id, n).catch(function (err) { b.disabled = false; b.textContent = label; if (err.silent) { return; } extra.hidden = false; extra.innerHTML = '<div class="zdh-error">' + esc(err.message) + '</div>'; }); });
		});
		var splitBtn = card.querySelector('[data-a="split"]');
		if (splitBtn) {
			splitBtn.addEventListener('click', function () {
				if (!extra.hidden && extra.dataset.mode === 'split') { extra.hidden = true; return; }
				extra.hidden = false; extra.dataset.mode = 'split';
				extra.innerHTML = '<p class="zdh-help">Split this route into walks of about this many houses, each a contiguous stretch in walking order.</p><div class="zdh-grid"><label>Houses per walk<input type="number" class="m-size" inputmode="numeric" min="5" max="500" value="' + esc(+recall('walk_size') || +C.walkSize || 60) + '"></label><label>Walk style<select class="m-style"><option value="loop">Loop the block — one side, round and back</option><option value="street">Street by street — both sides, then the next</option></select></label></div><div class="zdh-actions"><button type="button" class="zdh-secondary m-cancel">Cancel</button><button type="button" class="zdh-primary m-split">Split</button></div>';
				extra.querySelector('.m-style').value = recall('route_style') || C.routeStyle || 'loop';
				extra.querySelector('.m-cancel').addEventListener('click', function () { extra.hidden = true; });
				extra.querySelector('.m-split').addEventListener('click', function (ev) {
					ev.target.disabled = true; ev.target.textContent = 'Splitting…';
					var size = Math.max(5, Math.min(500, parseInt(extra.querySelector('.m-size').value, 10) || 60)), style = extra.querySelector('.m-style').value;
					store('walk_size', String(size)); store('route_style', style);
					splitExisting(d.id, size, style).then(function () { S.drops = null; render(); }).catch(function (err) { ev.target.disabled = false; ev.target.textContent = 'Split'; extra.insertAdjacentHTML('beforeend', '<div class="zdh-error">' + esc(err.message) + '</div>'); });
				});
			});
		}
		var routeBtn = card.querySelector('[data-a="route"]');
		if (routeBtn) { routeBtn.addEventListener('click', function () { editRoute(d.id); }); }
		var resetBtn = card.querySelector('[data-a="reset"]');
		if (resetBtn) {
			resetBtn.addEventListener('click', function () {
				if (!extra.hidden && extra.dataset.mode === 'reset') { extra.hidden = true; return; }
				extra.hidden = false; extra.dataset.mode = 'reset';
				extra.innerHTML = '<p class="zdh-help">Put this cluster back to <strong>planned</strong>? The start time and any ticked houses are cleared; the route and houses stay.</p><div class="zdh-actions"><button type="button" class="zdh-secondary m-cancel">Keep it</button><button type="button" class="zdh-primary m-reset">Back to planned</button></div>';
				extra.querySelector('.m-cancel').addEventListener('click', function () { extra.hidden = true; });
				extra.querySelector('.m-reset').addEventListener('click', function (ev) {
					ev.target.disabled = true; ev.target.textContent = 'Resetting…';
					api('drops/' + d.id + '/reset', { method: 'POST' }).then(function () { var ses = session(); if (ses && ses.id === d.id) { saveSession(null); stopGps(); } S.drops = null; render(); }).catch(function (err) { extra.insertAdjacentHTML('beforeend', '<div class="zdh-error">' + esc(err.message) + '</div>'); });
				});
			});
		}
		var doneBtn = card.querySelector('[data-a="done"]');
		if (doneBtn) {
			doneBtn.addEventListener('click', function () {
				if (!extra.hidden && extra.dataset.mode === 'done') { extra.hidden = true; return; }
				extra.hidden = false; extra.dataset.mode = 'done';
				extra.innerHTML = '<div class="zdh-grid"><label>Date walked<input type="date" class="m-date" value="' + esc(todayISO()) + '"></label><label>Time<input type="time" class="m-time" value="' + esc(nowHM()) + '"></label><label>Quantity hung<input type="number" class="m-qty" value="' + esc(d.quantity != null ? d.quantity : '') + '"></label><label>Delivered by<input type="text" class="m-by" list="zdh-team" value="' + esc(d.delivered_by || recall('deliverer') || C.userName || '') + '"></label></div><button type="button" class="zdh-primary m-save">Mark as done</button>';
				extra.querySelector('.m-save').addEventListener('click', function (ev) {
					ev.target.disabled = true; ev.target.textContent = 'Saving…';
					api('drops/' + d.id + '/done', { method: 'POST', body: { drop_date: extra.querySelector('.m-date').value, drop_time: extra.querySelector('.m-time').value, quantity: extra.querySelector('.m-qty').value, delivered_by: extra.querySelector('.m-by').value } }).then(function () { var ses = session(); if (ses && ses.id === d.id) { saveSession(null); stopGps(); } S.drops = null; render(); }).catch(function (err) { extra.insertAdjacentHTML('beforeend', '<div class="zdh-error">' + esc(err.message) + '</div>'); });
				});
			});
		}
		card.querySelector('[data-a="addr"]').addEventListener('click', function () {
			if (!extra.hidden && extra.dataset.mode === 'addr') { extra.hidden = true; return; }
			extra.hidden = false; extra.dataset.mode = 'addr'; extra.innerHTML = '<div class="zdh-muted">Loading…</div>';
			api('drops/' + d.id).then(function (full) {
				var by = {};
				full.addresses.forEach(function (a) { (by[a.street] = by[a.street] || []).push(a); });
				extra.innerHTML = Object.keys(by).map(function (street) {
					return '<div class="zdh-addr-street"><strong>' + esc(street) + '</strong> <span class="zdh-muted">(' + by[street].length + ')</span><div class="zdh-addr-nums">' + by[street].map(function (a) { return '<span>' + esc(a.house_number) + (a.unit ? '#' + esc(a.unit) : '') + '</span>'; }).join('') + '</div></div>';
				}).join('') || '<div class="zdh-muted">No individual addresses — recorded by street name only: ' + esc(full.streets.map(function (s) { return s.street; }).join(', ')) + '</div>';
			}).catch(function (err) { extra.innerHTML = '<div class="zdh-error">' + esc(err.message) + '</div>'; });
		});
		card.querySelector('[data-a="uptake"]').addEventListener('click', function () {
			if (!extra.hidden && extra.dataset.mode === 'uptake') { extra.hidden = true; return; }
			extra.hidden = false; extra.dataset.mode = 'uptake';
			var types = (C.hangerTypes || []).concat(d.hanger_type && (C.hangerTypes || []).indexOf(d.hanger_type) < 0 ? [d.hanger_type] : []);
			extra.innerHTML = '<div class="zdh-grid"><label class="zdh-span2">Cluster name<input type="text" class="u-name" value="' + esc(d.name || '') + '"></label>' + (planned ? '' : '<label>Responses<input type="number" class="u-resp" value="' + esc(d.responses != null ? d.responses : '') + '"></label><label>Converted<input type="number" class="u-conv" value="' + esc(d.converted != null ? d.converted : '') + '"></label>') +
				'<label>Quantity<input type="number" class="u-qty" value="' + esc(d.quantity != null ? d.quantity : '') + '"></label><label>Hanger type<select class="u-type">' + types.map(function (t) { return '<option' + (t === d.hanger_type ? ' selected' : '') + '>' + esc(t) + '</option>'; }).join('') + '</select></label>' +
				'<label>Side facing out<select class="u-side"><option value=""' + (!d.hanger_side ? ' selected' : '') + '>Single-sided / n/a</option><option value="A"' + (d.hanger_side === 'A' ? ' selected' : '') + '>Side A</option><option value="B"' + (d.hanger_side === 'B' ? ' selected' : '') + '>Side B</option></select></label>' +
				'<label>Revisit in (days)<input type="number" class="u-revisit" value="' + esc(d.revisit_days != null ? d.revisit_days : '') + '"></label>' +
				'<label>' + (planned ? 'Planned date' : 'Date') + '<input type="date" class="u-date" value="' + esc(d.drop_date || '') + '"></label><label>Delivered by<input type="text" class="u-by" list="zdh-team" value="' + esc(d.delivered_by || '') + '"></label>' +
				(planned ? '' : '<label>Walked from<input type="time" class="u-from" value="' + esc(String(d.started_at || '').slice(11, 16)) + '"></label><label>Walked until<input type="time" class="u-to" value="' + esc(String(d.finished_at || '').slice(11, 16)) + '"></label>') +
				'<label class="zdh-span2">Notes<textarea class="u-notes" rows="2">' + esc(d.notes || '') + '</textarea></label></div><button type="button" class="zdh-secondary u-save">Save</button>';
			extra.querySelector('.u-save').addEventListener('click', function () {
				var q = function (sel) { var x = extra.querySelector(sel); return x ? x.value : undefined; };
				var body = { name: q('.u-name'), quantity: q('.u-qty'), hanger_type: q('.u-type'), hanger_side: q('.u-side'), revisit_days: q('.u-revisit'), drop_date: q('.u-date'), delivered_by: q('.u-by'), notes: q('.u-notes') };
				if (!planned) {
					body.responses = q('.u-resp'); body.converted = q('.u-conv');
					// the walk's times: on the chosen date (a finish before the start is the next morning)
					var date = q('.u-date') || d.drop_date, from = q('.u-from'), to = q('.u-to');
					if (date && from) { body.started_at = date + ' ' + from + ':00'; body.drop_time = from; }
					if (date && to) { var fd = date; if (from && to < from) { var nx = new Date(date + 'T12:00:00'); nx.setDate(nx.getDate() + 1); fd = nx.toISOString().slice(0, 10); } body.finished_at = fd + ' ' + to + ':00'; }
				}
				api('drops/' + d.id, { method: 'POST', body: body }).then(function () { S.drops = null; render(); }).catch(function (err) { extra.insertAdjacentHTML('beforeend', '<div class="zdh-error">' + esc(err.message) + '</div>'); });
			});
		});
		var en = card.querySelector('[data-a="enrich"]');
		if (en) {
			en.addEventListener('click', function (ev) {
				ev.target.disabled = true; ev.target.textContent = 'Refreshing…';
				api('drops/' + d.id + '/enrich', { method: 'POST' }).then(function () { S.drops = null; render(); }).catch(function (err) { ev.target.textContent = 'Failed: ' + err.message; });
			});
		}
		var del = card.querySelector('[data-a="del"]');
		if (del) {
			del.addEventListener('click', function () {
				if (del.dataset.armed) {
					api('drops/' + d.id, { method: 'DELETE' }).then(function () { var ses = session(); if (ses && ses.id === d.id) { saveSession(null); stopGps(); } S.drops = null; render(); }).catch(function (err) { del.textContent = 'Failed: ' + err.message; });
				} else { del.dataset.armed = '1'; del.textContent = 'Tap again to delete'; setTimeout(function () { delete del.dataset.armed; del.textContent = 'Delete'; }, 4000); }
			});
		}
		return card;
	}

	/* ======================================================== LOOKUP */
	function renderLookup(main) {
		main.innerHTML =
			'<div class="zdh-card"><h2>' + tx('Check an address', 'Did this address get a door hanger?') + '</h2>' +
				'<p class="zdh-help">' + tx('Street address only, e.g. <em>4100 Example Dr</em>.', 'Type the customer\'s street address (no names are stored here — only addresses). Example: <em>4100 Example Dr</em> or <em>4400 Example St, Exampleton 00000</em>. Planned routes don\'t count until they are marked done.') + '</p>' +
				'<div class="zdh-lookup-row"><input type="text" id="lk-q" placeholder="123 Street Name, City ZIP" autocomplete="off"><button class="zdh-primary" id="lk-go">Check</button></div>' +
				'<div id="lk-out"></div>' +
			'</div>' +
			'<div class="zdh-card zdh-desk"><h3>Check a list</h3><p class="zdh-help">One address per line (paste from a spreadsheet).</p><textarea id="lk-bulk" rows="5" placeholder="4100 Example Dr\n4400 Example St"></textarea><button class="zdh-secondary" id="lk-bulk-go">Check all</button><div id="lk-bulk-out"></div></div>' +
			nutshellCardHTML();
		wireNutshell();
		var q = document.getElementById('lk-q'), out = document.getElementById('lk-out');
		function run() {
			var v = q.value.trim();
			if (!v) { return; }
			out.innerHTML = '<div class="zdh-muted">Checking…</div>';
			api('lookup?q=' + encodeURIComponent(v)).then(function (r) {
				out.innerHTML = r.results.length ? lookupResult(r.results[0]) : '<div class="zdh-muted">No result.</div>';
				if (r.results[0] && r.results[0].corrected) { logEv('lookup', 'corrected "' + r.results[0].corrected.typed + '" → ' + r.results[0].corrected.street); }
				Array.prototype.forEach.call(out.querySelectorAll('.zdh-suggest'), function (b) { b.addEventListener('click', function () { q.value = b.getAttribute('data-q'); run(); }); });
			}).catch(function (e) { out.innerHTML = '<div class="zdh-error">' + esc(e.message) + '</div>'; });
		}
		document.getElementById('lk-go').addEventListener('click', run);
		q.addEventListener('keydown', function (e) { if (e.key === 'Enter') { run(); } });
		q.focus();
		document.getElementById('lk-bulk-go').addEventListener('click', function () {
			var lines = document.getElementById('lk-bulk').value.split(/\n/).map(function (l) { return l.trim(); }).filter(Boolean);
			var bo = document.getElementById('lk-bulk-out');
			if (!lines.length) { return; }
			bo.innerHTML = '<div class="zdh-muted">Checking ' + lines.length + '…</div>';
			api('lookup', { method: 'POST', body: { lines: lines } }).then(function (r) {
				bo.innerHTML = '<table class="zdh-table"><thead><tr><th>Address</th><th>Result</th><th>Details</th></tr></thead><tbody>' + r.results.map(function (res) {
					var d = res.matches[0] || res.streets[0];
					return '<tr><td>' + esc(res.query) + '</td><td>' + statusBadge(res.status) + '</td><td>' + (d ? esc((d.drop_date || '') + ' · ' + (d.hanger_type || '') + ' · ' + (d.delivered_by || '')) : '') + '</td></tr>';
				}).join('') + '</tbody></table>';
			}).catch(function (e) { bo.innerHTML = '<div class="zdh-error">' + esc(e.message) + '</div>'; });
		});
	}
	/* ---- CRM cross-check (read-only): which of the salesperson's CRM customers got a door hanger ---- */
	function nutshellCardHTML() {
		var ns = C.crm || {};
		if (!ns.ready) {
			if (!C.isAdmin) { return ''; }
			return '<div class="zdh-card zdh-ns"><h2>CRM customers</h2><p class="zdh-help">' + (ns.hasKey ? 'The CRM is connected, but no salesperson is chosen yet. Under Zorderz → Doorhangers → CRM cross-check, pick the one user whose customers this card may check.' : 'Not connected yet. The site administrator adds the CRM login on Zorderz → Settings and picks the one salesperson whose customers this card may check on Zorderz → Doorhangers.') + '</p></div>';
		}
		var who = esc(ns.owner) + '\'s';
		var whoLong = esc(ns.owner) + '\'s People, Companies and the customers behind ' + esc(ns.owner) + '\'s Leads';
		return '<div class="zdh-card zdh-ns" id="zdh-ns"><h2>' + tx('CRM customers', 'Did any CRM customers get a door hanger?') + '</h2>' +
			'<p class="zdh-help">' + tx('Checks ' + who + ' customers in the CRM against the houses we delivered to.', 'Checks ' + whoLong + ' in the CRM against the houses that got a door hanger in the chosen period. Names come straight from the CRM and are never stored here: the app only reads from it.') + '</p>' +
			'<div class="zdh-ns-row"><select id="ns-days" aria-label="Period"><option value="7">Last 7 days</option><option value="30" selected>Last 30 days</option><option value="90">Last 90 days</option><option value="365">Last year</option><option value="0">All batches</option></select>' +
			'<button type="button" class="zdh-primary" id="ns-go">' + tx('Check the CRM', 'Check CRM customers') + '</button></div>' +
			'<div id="ns-out"></div></div>';
	}
	function wireNutshell() {
		var go = document.getElementById('ns-go'), out = document.getElementById('ns-out'), sel = document.getElementById('ns-days');
		if (!go) { return; }
		var last = recall('ns_days'); if (last !== null && last !== undefined && sel.querySelector('option[value="' + last + '"]')) { sel.value = last; }
		function run(refresh) {
			store('ns_days', sel.value);
			go.disabled = true;
			out.innerHTML = '<div class="zdh-muted" id="ns-wait">Asking the CRM… <span class="zdh-desk">(a big customer list can take up to half a minute the first time; after that it is quick for 10 minutes)</span></div>';
			logEv('nutshell', 'check ' + sel.value + 'd' + (refresh ? ' refresh' : ''));
			api('crm/crosscheck?days=' + encodeURIComponent(sel.value) + (refresh ? '&refresh=1' : '')).then(function (r) {
				go.disabled = false;
				out.innerHTML = nutshellResults(r);
				var rf = document.getElementById('ns-refresh'); if (rf) { rf.addEventListener('click', function (e) { e.preventDefault(); run(true); }); }
				logEv('nutshell', r.matches.length + ' matches of ' + r.counts.addresses + ' addresses' + (r.cached ? ' (cached)' : '') + ' in ' + r.ms + ' ms');
			}).catch(function (e) {
				go.disabled = false;
				out.innerHTML = '<div class="zdh-error">' + esc(e.message) + '</div>';
				logEv('nutshell', 'failed: ' + e.message);
			});
		}
		go.addEventListener('click', function () { run(false); });
	}
	function nutshellResults(r) {
		var c = r.counts || {}, n = r.matches.length;
		var period = r.days ? 'since ' + esc(fmtDate(r.since)) : 'in every batch on file';
		var head = '<div class="zdh-ns-sum"><strong>' + (n ? n + (n === 1 ? ' customer' : ' customers') + ' got a door hanger ' + period : (!(c.addresses) && ((c.contacts || 0) + (c.accounts || 0) + (c.from_leads || 0)) ? 'Couldn\'t check — no customer addresses came through from the CRM' : 'No CRM customer is at a house we delivered to ' + period)) + '.</strong>' +
			'<div class="zdh-muted">Checked ' + (c.contacts || 0) + ' people, ' + (c.accounts || 0) + ' companies and ' + (c.leads || 0) + ' leads' + (c.from_leads ? ' (+' + c.from_leads + ' customers behind the leads)' : '') + ' — ' + (c.addresses || 0) + ' addresses — against ' + r.batches + (r.batches === 1 ? ' batch' : ' batches') + '.' +
			nsNoAddrNote(r) +
			(r.partial ? ' <span class="zdh-warn">The CRM has more records than fit in one check — the list may be incomplete; try again in a minute.</span>' : '') +
			(r.cached ? ' Customer list as of ' + esc(r.fetched_at) + ' · <a href="#" id="ns-refresh">refresh from the CRM</a>' : '') + '</div></div>';
		if (!n) { return head; }
		return head + '<ul class="zdh-list zdh-ns-list">' + r.matches.map(function (m) {
			var lead = m.leads && m.leads.length ? '<div class="zdh-muted">Lead: ' + m.leads.map(function (l) { return '<a href="' + esc(l.url) + '" target="_blank" rel="noopener">' + esc(l.name) + '</a>'; }).join(', ') + '</div>' : '';
			var lines = m.deliveries.map(function (d) {
				return '<div class="zdh-ns-del">✓ ' + esc(fmtDate(d.drop_date, true)) + (d.drop_time ? ' ' + esc(fmtTime(d.drop_time)) : '') + ' &bull; ' + esc(d.drop_name || ('Cluster #' + d.drop_id)) + (d.hanger_type ? ' &bull; ' + esc(d.hanger_type) : '') + (d.delivered_by ? ' &bull; by ' + esc(d.delivered_by) : '') + '</div>';
			}).join('');
			return '<li class="zdh-ns-match"><div class="zdh-ns-top"><a href="' + esc(m.url) + '" target="_blank" rel="noopener" class="zdh-ns-name">' + esc(m.name) + '</a> <span class="zdh-status zdh-st-yes">' + esc(m.kind) + '</span></div>' +
				'<div class="zdh-ns-addr">' + esc(m.address) + (m.level === 'building' ? ' <span class="zdh-warn">— same building, unit not confirmed</span>' : '') + (m.level === 'spelling' && m.deliveries[0] ? ' <span class="zdh-warn">— spelled differently in the CRM; matched to ' + esc(m.deliveries[0].full_address) + '</span>' : '') + (m.zip_checked ? '' : ' <span class="zdh-muted">(ZIP not checked)</span>') + '</div>' + lead + lines + '</li>';
		}).join('') + '</ul>';
	}
	/* a check that read people but no addresses can never match — say so plainly instead of "no customer". */
	function nsNoAddrNote(r) {
		var c = r.counts || {}, sh = r.shape || {}, people = (c.contacts || 0) + (c.accounts || 0) + (c.from_leads || 0);
		if (!people || c.addresses) { return ''; }
		var why = sh.no_field && sh.no_field === sh.records ? 'The CRM sent the records without an address field.' :
			(sh.entries ? 'The CRM sent ' + sh.entries + ' address entr' + (sh.entries === 1 ? 'y' : 'ies') + ' but none had a street line the app could read' + (sh.po_or_place ? ' (' + sh.po_or_place + ' were PO boxes or place names)' : '') + '.' : 'None of these CRM records has a street address filled in.');
		logEv('nutshell', 'no addresses: ' + JSON.stringify(sh));
		return ' <div class="zdh-warn" style="margin-top:6px">⚠ No street addresses were readable, so nothing could match. ' + why + (sh.shape ? ' <span class="zdh-muted">Address fields seen: ' + esc(sh.shape) + '</span>' : '') + ' — send this line (or Export → Diagnostics) to whoever looks after the app.</div>';
	}
	function statusBadge(s) {
		var map = { delivered: ['zdh-st-yes', '✓ Delivered'], likely: ['zdh-st-likely', '~ Likely (street covered)'], street_partial: ['zdh-st-maybe', '? Street covered, number not on file'], planned: ['zdh-st-maybe', '⏳ On an upcoming route'], none: ['zdh-st-no', 'No record'], invalid: ['zdh-st-no', 'Could not read address'] };
		var m = map[s] || map.none;
		return '<span class="zdh-status ' + m[0] + '">' + m[1] + '</span>';
	}
	function lookupResult(r) {
		var html = '<div class="zdh-lookup-res">' + statusBadge(r.status) + ' <span class="zdh-muted">' + (r.corrected ? 'checked as: ' : 'parsed as: ') + esc((r.parsed.number + ' ' + r.parsed.street).trim()) + (r.parsed.unit ? ' #' + esc(r.parsed.unit) : '') + (r.parsed.zip ? ' ' + esc(r.parsed.zip) : '') + '</span>';
		if (r.no_solicit) { html += '<div class="zdh-fix zdh-nosol-note">🚫 <strong>No Soliciting sign</strong> noted here on ' + esc(r.no_solicit.noted) + (r.no_solicit.address ? ' (' + esc(r.no_solicit.address) + ')' : '') + ' — no hanger is left at this house, and routes skip it.</div>'; }
		if (r.corrected) { html += '<div class="zdh-fix">✎ Spelling corrected: you typed <strong>' + esc(r.corrected.typed) + '</strong>, checked <strong>' + esc(r.corrected.street) + '</strong> (' + esc(r.corrected.how) + (r.corrected.source === 'region' ? ', from the address map' : ', a street we delivered to') + '). Same house number' + (r.parsed.zip ? ' and ZIP' : '') + '.</div>'; }
		if (r.suggestions && r.suggestions.length) { html += '<div class="zdh-fix">Did you mean ' + r.suggestions.map(function (x) { return '<button type="button" class="zdh-linkbtn zdh-suggest" data-q="' + esc(x + (r.parsed.zip ? ' ' + r.parsed.zip : '')) + '">' + esc(x) + '</button>'; }).join(' or ') + '? (More than one street fits, so the app didn\'t pick one.)</div>'; }
		if (r.matches.length) {
			html += '<ul class="zdh-list">' + r.matches.map(function (m) { return '<li><strong>' + esc(m.full_address) + '</strong><br>Drop #' + m.drop_id + ' &bull; ' + esc(fmtDate(m.drop_date)) + (m.drop_time ? ' ' + esc(fmtTime(m.drop_time)) : '') + ' &bull; ' + esc(m.hanger_type) + ' &bull; by ' + esc(m.delivered_by || '—') + ' &bull; ' + esc(m.confidence) + ' (' + esc(m.source) + ')</li>'; }).join('') + '</ul>';
		}
		if (r.streets.length) {
			html += '<div class="zdh-muted" style="margin-top:6px">Street coverage on file:</div><ul class="zdh-list">' + r.streets.map(function (s) { return '<li>' + esc(s.street) + ', ' + esc(s.city) + ' ' + esc(s.zip) + ' — <strong>' + esc(s.coverage) + '</strong>' + (s.low_number ? ' (' + s.low_number + '–' + s.high_number + ')' : '') + (s.number_in_range === false ? ' <span class="zdh-warn">number outside recorded range</span>' : '') + ' &bull; drop #' + s.drop_id + ' on ' + esc(s.drop_date) + ' &bull; ' + esc(s.hanger_type) + '</li>'; }).join('') + '</ul>';
		}
		if (r.upcoming && r.upcoming.length) {
			html += '<div class="zdh-muted" style="margin-top:6px">Not delivered yet, but on a route:</div><ul class="zdh-list">' + r.upcoming.map(function (u) { return '<li><strong>' + esc(u.full_address) + '</strong><br>' + esc(u.name || ('Cluster #' + u.drop_id)) + ' &bull; ' + (u.status === 'in_progress' ? 'being delivered now' : 'planned for ' + esc(fmtDate(u.drop_date))) + (u.delivered_by ? ' &bull; ' + esc(u.delivered_by) : '') + '</li>'; }).join('') + '</ul>';
		}
		html += nearbyHTML(r);
		if (r.status === 'none' && !r.nearby) { html += '<p class="zdh-muted">Nothing on file for this street. Double-check the spelling or add the ZIP.</p>'; }
		return html + '</div>';
	}
	/* "did the app actually find that address?": when the house isn't on file as delivered, the server finds it on
	   the address map and reports the closest delivered houses and the closest walked GPS track. */
	function fmtDist(m) { return m < 1000 ? m + ' m' : (m / 1609.34).toFixed(1) + ' mi'; }
	function nearbyHTML(r) {
		if (r.status === 'delivered' || r.geo === undefined) { return ''; }
		if (r.geo === null) { return '<p class="zdh-muted">This address isn\'t on the address map (check the house number and street spelling), so the app can\'t say how close a route came.</p>'; }
		var nb = r.nearby || {}, hs = nb.houses || [], h = hs[0], t = nb.track;
		var batch = function (x) { return esc(x.name || ('Cluster #' + x.drop_id)) + ' (' + esc(fmtDate(x.drop_date, true)) + ')'; };
		var verdict;
		if (!h) {
			verdict = 'No delivered house on file within about ¾ mile. <strong>No recorded route went near this address</strong> — if it got a hanger, it was before the app (the paper-map days) or on a walk that wasn\'t recorded.';
		} else if (h.dist_m <= 45) {
			verdict = 'A route went right past it: <strong>' + esc(h.full_address) + '</strong>, ' + fmtDist(h.dist_m) + ' away, got one on ' + batch(h) + '. This house just isn\'t on that route\'s list — it may have been skipped, or left off when the houses were confirmed.';
		} else if (h.dist_m <= 250) {
			verdict = 'A route came close but didn\'t include this house: nearest delivered house is <strong>' + esc(h.full_address) + '</strong>, ' + fmtDist(h.dist_m) + ' away, on ' + batch(h) + '.';
		} else {
			verdict = 'No route on file reached this street. The nearest delivered house is <strong>' + esc(h.full_address) + '</strong>, ' + fmtDist(h.dist_m) + ' away, on ' + batch(h) + '.';
		}
		var extra = '';
		if (t) { extra += '<li>Closest the walker\'s GPS came: ' + fmtDist(t.dist_m) + ' on ' + batch(t) + (t.dist_m <= 40 && (!h || h.dist_m > 45) ? ' — <span class="zdh-warn">they walked past; worth asking whether they hung one here</span>' : '') + '</li>'; }
		if (nb.within_150) { extra += '<li>' + nb.within_150 + ' delivered house' + (nb.within_150 === 1 ? '' : 's') + ' within 150 m (about a block)</li>'; }
		if (hs.length > 1) { extra += '<li>Next closest: ' + hs.slice(1).map(function (x) { return esc(x.full_address) + ' (' + fmtDist(x.dist_m) + ', ' + esc(fmtDate(x.drop_date, true)) + ')'; }).join('; ') + '</li>'; }
		return '<div class="zdh-nearby"><div class="zdh-nearby-head">How close did a route come?</div><p>' + verdict + '</p>' + (extra ? '<ul class="zdh-list">' + extra + '</ul>' : '') + '</div>';
	}

	/* ======================================================== EXPORT */
	function renderExport(main) {
		var u = url('export?_wpnonce=' + encodeURIComponent(C.nonce) + '&type=');
		main.innerHTML = '<div class="zdh-card"><h2>Export</h2><p class="zdh-help zdh-desk">Everything lives in your WordPress database; these downloads are for spreadsheets, backups, or matching against a customer list elsewhere.</p>' +
			'<div class="zdh-export-btns">' +
				'<a class="zdh-primary" href="' + esc(u + 'addresses') + '">Download addresses CSV</a><p class="zdh-muted">One row per house: address, ZIP, coordinates, APN, drop date, hanger type and side, deliverer, weather and wind, fire zone, neighborhood stats.</p>' +
				'<a class="zdh-primary" href="' + esc(u + 'drops') + '">Download drops CSV</a><p class="zdh-muted">One row per distribution run (and planned route) with totals, revisit dates, uptake and screenshot links.</p>' +
				'<a class="zdh-secondary" href="' + esc(u + 'json') + '">Download full JSON backup</a><p class="zdh-muted">Raw tables (drops, addresses, streets) including the AI analysis and geometry.</p>' +
			'</div><div id="ex-stats" class="zdh-muted"></div></div>' +
			'<div class="zdh-card" id="zdh-diag"><h2>Diagnostics</h2><p class="zdh-help">If a walk did not record the way you expected, this report shows what this phone did: GPS fixes and gaps, ticks, what reached the server, what is still waiting, errors. It holds no customer data — only addresses, times and counts.</p>' +
				'<div class="zdh-actions zdh-diag-btns"><button type="button" class="zdh-primary" id="dg-share">Share report…</button><button type="button" class="zdh-secondary" id="dg-copy">Copy</button><button type="button" class="zdh-secondary" id="dg-send">Send to the site</button></div>' +
				'<p class="zdh-muted" id="dg-note">Share… opens the phone\'s share sheet (Messages, Mail, Files…). Send to the site keeps a copy under Zorderz → Doorhangers for whoever looks after the site.</p>' +
				'<details class="zdh-log"><summary>Show the report</summary><textarea id="dg-text" class="zdh-diag-text" readonly rows="14" spellcheck="false">Building…</textarea></details>' +
			'</div>';
		api('stats').then(function (s) { document.getElementById('ex-stats').textContent = s.drops + ' drops · ' + (s.planned || 0) + ' planned · ' + s.addresses + ' addresses · ' + s.streets + ' street records · ' + s.hangers + ' hangers logged'; }).catch(function () {});
		wireDiagnostics();
	}

	/* ===================================================== DIAGNOSTICS
	   A plain-text report of what this phone knows: device and app, the delivery / Quick Start state, the outbox,
	   and the event log. Shared through the phone's share sheet, copied, or posted to the site (kept in settings). */
	function fmtT(ms) { var d = new Date(ms), p = function (n) { return (n < 10 ? '0' : '') + n; }; return p(d.getMonth() + 1) + '/' + p(d.getDate()) + ' ' + p(d.getHours()) + ':' + p(d.getMinutes()) + ':' + p(d.getSeconds()); }
	function trackSummary(track) {
		if (!track || !track.length) { return 'no points'; }
		var first = track[0][2], last = track[track.length - 1][2], poor = 0, drive = 0, gaps = 0, prev = null, m = 0;
		track.forEach(function (t) { if (t[3] > 45) { poor++; } if (t[4] === 'd') { drive++; } if (prev && t[2] - prev[2] > 180000) { gaps++; } if (prev && t[4] !== 'd') { var dd = distM([prev[0], prev[1]], [t[0], t[1]]); if (dd <= 150 && t[2] - prev[2] <= 300000) { m += dd; } } prev = t; });
		return track.length + ' points ' + fmtT(first) + ' → ' + fmtT(last) + ', ' + (m / 1609.34).toFixed(2) + ' mi walked, ' + poor + ' poor (±>45 m), ' + drive + ' driving, ' + gaps + ' holes > 3 min';
	}
	function diagReport() {
		var ses = session(), q = quick(), ob = outbox(), log = logEntries(), lines = [];
		var conn = navigator.connection || {};
		lines.push('Doorhangers ' + (C.version || '') + ' diagnostics — ' + new Date().toString());
		lines.push('Doorhangers ' + (C.version || '?') + ' · ' + (isStandalone() ? 'home-screen app' : 'browser') + ' · ' + location.pathname + ' · kit ' + !!kit() + ' · page ' + !!root.closest('.zdz-focused'));
		lines.push('Device: ' + navigator.userAgent);
		lines.push('Screen ' + window.innerWidth + '×' + window.innerHeight + ' @' + (window.devicePixelRatio || 1) + ' · online ' + navigator.onLine + (conn.effectiveType ? ' · net ' + conn.effectiveType + (conn.downlink ? ' ' + conn.downlink + ' Mb/s' : '') + (conn.saveData ? ' data-saver' : '') : '') + ' · webgl2 ' + (glOK === null ? '?' : glOK) + ' · wake lock ' + ('wakeLock' in navigator) + ' · share ' + !!navigator.share);
		lines.push('Location permission: ' + (recall('loc') || 'never asked') + ' · time zone ' + (Intl.DateTimeFormat().resolvedOptions().timeZone || '?') + ' · site clock at page load ' + (C.now || '?') + ' · phone day ' + todayISO() + ' (the app stamps walks with the phone\'s clock)');
		lines.push('Settings: big text ' + bigMap() + ' · skip units ' + (recall('skipunits') || '-') + ' · quick sides ' + (recall('quick_sides') || 'both') + ' · walk size ' + (recall('walk_size') || C.walkSize) + ' · style ' + (recall('route_style') || C.routeStyle) + ' · deliverer ' + (recall('deliverer') || '-') + ' · tab ' + (recall('tab') || '-'));
		var sizes = []; try { for (var i = 0; i < localStorage.length; i++) { var k = localStorage.key(i); if (/^zdh_/.test(k)) { sizes.push(k + ' ' + Math.round((localStorage.getItem(k) || '').length / 1024) + ' KB'); } } } catch (e) {}
		lines.push('Storage: ' + (sizes.join(', ') || 'nothing'));
		lines.push('');
		if (ses) {
			lines.push('DELIVERY SESSION on this phone: cluster #' + ses.id + ' “' + (ses.name || '') + '” walk ' + (ses.walk || 1));
			lines.push('  started ' + fmtT(ses.started) + ' · last active ' + fmtT(ses.lastActive || ses.started) + ' · last GPS fix ' + (ses.lastFixAt ? fmtT(ses.lastFixAt) : 'none'));
			lines.push('  ticked ' + Object.keys(ses.delivered).length + ' of ' + ses.total + ' · not yet sent ' + Object.keys(ses.pending).length + ' · unticks not yet sent ' + Object.keys(ses.undel).length + ' · last confirmed sync ' + (ses.lastSync ? fmtT(ses.lastSync) : 'never') + (ses.serverDelivered != null ? ' (server then had ' + ses.serverDelivered + ')' : ''));
			lines.push('  track: ' + trackSummary(ses.track));
			if (ses.gaps && ses.gaps.length) { lines.push('  GPS holes: ' + ses.gaps.map(function (g) { return fmtT(g[0]).slice(6) + '–' + fmtT(g[1]).slice(6) + ' (' + Math.round((g[1] - g[0]) / 60000) + ' min)'; }).join(', ')); }
		} else { lines.push('No delivery session on this phone.'); }
		if (q) {
			lines.push('QUICK START walk on this phone: started ' + fmtT(q.started) + (q.ended ? ' · ended ' + fmtT(q.ended) + ' (not counted yet)' : '') + ' · last active ' + fmtT(q.lastActive || q.started) + ' · last GPS fix ' + (q.lastFixAt ? fmtT(q.lastFixAt) : 'none') + ' · pace ' + q.pace + ' · pin ' + (q.pin ? q.pin.join(',') : 'none') + ' · on the site: ' + (q.id ? '#' + q.id + ' (synced to ' + (q.syncedTo ? fmtT(q.syncedTo) : 'nothing') + ')' : 'not yet'));
			lines.push('  track: ' + trackSummary(q.track) + ' · usable strokes ' + quickStrokes(q.track || []).length);
			if (q.gaps && q.gaps.length) { lines.push('  GPS holes: ' + q.gaps.map(function (g) { return fmtT(g[0]).slice(6) + '–' + fmtT(g[1]).slice(6) + ' (' + Math.round((g[1] - g[0]) / 60000) + ' min)'; }).join(', ')); }
		} else { lines.push('No Quick Start walk on this phone.'); }
		lines.push(ob.length ? 'OUTBOX (waiting for signal): ' + ob.map(function (o) { return o.kind + ' for #' + o.id + ' from ' + fmtT(o.at) + ', ' + (o.tries || 0) + ' tries' + (o.body && o.body.delivered ? ', ' + o.body.delivered.length + ' ticks' : ''); }).join('; ') : 'Outbox empty.');
		lines.push(loadDraft() ? 'A route draft is saved.' : 'No route draft.');
		lines.push('');
		lines.push('EVENT LOG (' + log.length + ' most recent; newest last)');
		log.forEach(function (e) { lines.push(fmtT(e[0]) + '  ' + e[1] + (e[2] != null && e[2] !== '' ? '  ' + (typeof e[2] === 'string' ? e[2] : JSON.stringify(e[2])) : '')); });
		var text = lines.join('\n');
		// what the server has for the open cluster, when it can be reached
		if (!ses) { return Promise.resolve(text); }
		return api('drops/' + ses.id).then(function (d) {
			var walks = d.walk_counts ? Object.keys(d.walk_counts).map(function (k) { return 'walk ' + k + ' ' + d.walk_counts[k][0] + '/' + d.walk_counts[k][1]; }).join(', ') : '';
			return text.replace('\nEVENT LOG', '\nSERVER for #' + d.id + ': status ' + d.status + ' · delivered ' + (d.delivered_count != null ? d.delivered_count : '?') + ' of ' + (d.addresses ? d.addresses.length : '?') + (walks ? ' · ' + walks : '') + ' · track points ' + (d.track ? d.track.length : (d.track_points != null ? d.track_points : '?')) + '\n\nEVENT LOG');
		}).catch(function (e) { return text.replace('\nEVENT LOG', '\nSERVER: not reachable (' + e.message + ')\n\nEVENT LOG'); });
	}
	function wireDiagnostics() {
		var ta = document.getElementById('dg-text'); if (!ta) { return; }
		var current = '';
		var build = function () { return diagReport().then(function (t) { current = t; if (ta.isConnected) { ta.value = t; } return t; }); };
		build();
		document.getElementById('dg-copy').addEventListener('click', function () {
			build().then(function (t) {
				var done = function () { toast('Report copied — paste it into a message.', 5000); };
				if (navigator.clipboard && navigator.clipboard.writeText) { navigator.clipboard.writeText(t).then(done, function () { ta.parentNode.open = true; ta.focus(); ta.select(); toast('Select the text in the box and copy it.', 6000); }); }
				else { ta.parentNode.open = true; ta.focus(); ta.select(); try { document.execCommand('copy'); done(); } catch (e) { toast('Select the text in the box and copy it.', 6000); } }
			});
		});
		document.getElementById('dg-share').addEventListener('click', function () {
			build().then(function (t) {
				var name = 'doorhangers-diagnostics-' + todayISO() + '.txt';
				var file = null; try { file = new File([t], name, { type: 'text/plain' }); } catch (e) {}
				if (navigator.share && file && navigator.canShare && navigator.canShare({ files: [file] })) { return navigator.share({ files: [file], title: 'Doorhangers diagnostics' }).then(function () { logEv('diag', 'shared file'); }).catch(function (e) { if (e && e.name !== 'AbortError') { toast('Could not share: ' + e.message); } }); }
				if (navigator.share) { return navigator.share({ title: 'Doorhangers diagnostics', text: t }).then(function () { logEv('diag', 'shared text'); }).catch(function (e) { if (e && e.name !== 'AbortError') { toast('Could not share: ' + e.message); } }); }
				document.getElementById('dg-copy').click();
			});
		});
		document.getElementById('dg-send').addEventListener('click', function () {
			var b = document.getElementById('dg-send'); b.disabled = true; b.textContent = 'Sending…';
			build().then(function (t) { return api('diag-report', { method: 'POST', body: { text: t, label: (recall('deliverer') || '') + ' ' + (isStandalone() ? 'app' : 'browser') } }); }).then(function () {
				logEv('diag', 'sent to site'); b.textContent = 'Sent ✓'; toast('Report sent — it is kept under Zorderz → Doorhangers on the site.', 7000); setTimeout(function () { b.disabled = false; b.textContent = 'Send to the site'; }, 4000);
			}).catch(function (e) { b.disabled = false; b.textContent = 'Send to the site'; toast('Could not send: ' + e.message + (isNetError(e) ? ' — use Share or Copy instead.' : ''), 8000); });
		});
	}

	/* Drop the local delivery session (progress already synced) and land on the Plan tab with a note. */
	function leaveDelivery(msg, silentSync) {
		var ses = session();
		stopGps(); clearInterval(D.timer); D.timer = null; if (D.wake) { try { D.wake.release(); } catch (e) {} D.wake = null; }
		logEv('leave', ses ? '#' + ses.id + (silentSync ? ' (cleared)' : '') : '-');
		var done = (ses && !silentSync) ? syncProgress(false) : Promise.resolve(true);
		done.then(function (ok) {
			var s2 = session();
			if (!ok && s2 && (Object.keys(s2.pending).length || Object.keys(s2.undel).length)) {
				// no signal: the ticks go to the outbox instead of being dropped with the session
				queueOutbox({ kind: 'progress', id: s2.id, name: s2.name, body: progressSnapshot(s2) });
				msg = (msg ? msg + ' ' : '') + 'Your ticks have not reached the server yet — they are kept on this phone and will be sent when you have signal.';
			}
			saveSession(null); D.cluster = null; S.drops = null;
			if (S.tab === 'deliver') { S.tab = 'new'; }
			render();
			if (msg) { toast(msg, 9000); }
		});
	}
	/* Reload data: keep everything (route being drawn, ticks, walk), flush progress, then draw the tab again from the server. */
	function refreshApp() {
		var b = document.getElementById('zdh-refresh'); if (b) { b.disabled = true; b.textContent = '↻ Reloading…'; }
		try { if (S.tab === 'new' && S.nd && (S.nd.step === 'draw' || S.nd.step === 'review')) { readDetails(S.nd.form); } } catch (e) {}
		saveDraft();
		var ses = session(); if (ses) { ses.lastActive = Date.now(); saveSession(ses); }
		var q = quick(); if (q) { q.lastActive = Date.now(); saveQuick(q); }
		var flush = ses ? syncProgress(false) : Promise.resolve();
		var go = function () { S.drops = null; if (S.tab === 'deliver' || S.tab === 'quick') { S.tab = 'new'; } render(); };
		Promise.race([flush, new Promise(function (r) { setTimeout(r, 4000); })]).then(go, go);
	}
	function toastError(e, retry) { var k = kit(); if (k && k.toastError) { try { k.toastError(e, retry); return; } catch (x) {} } toast((e && e.message) ? e.message : String(e)); }
	function toast(msg, ms) {
		var k = kit(); if (k && k.toast) { try { k.toast(msg); return; } catch (x) {} }
		var old = document.getElementById('zdh-toast'); if (old) { old.remove(); }
		var b = el('<div class="zdh-toast" id="zdh-toast"><span>' + esc(msg) + '</span><button type="button" class="zdh-mini">OK</button></div>');
		b.querySelector('button').addEventListener('click', function () { b.remove(); });
		document.body.appendChild(b);
		setTimeout(function () { if (b.parentNode) { b.remove(); } }, ms || 9000);
	}
	/* A toast with one action (Undo…) next to OK. */
	function toastAction(msg, label, fn, ms) {
		var k = kit(); if (k && k.toast) { try { k.toast(msg, { action: label, onAction: fn }); return; } catch (x) {} }
		var old = document.getElementById('zdh-toast'); if (old) { old.remove(); }
		var b = el('<div class="zdh-toast" id="zdh-toast"><span>' + esc(msg) + '</span><button type="button" class="zdh-mini zdh-mini-primary" data-act>' + esc(label) + '</button><button type="button" class="zdh-mini">OK</button></div>');
		b.querySelector('[data-act]').addEventListener('click', function () { b.remove(); fn(); });
		b.querySelector('button:not([data-act])').addEventListener('click', function () { b.remove(); });
		document.body.appendChild(b);
		setTimeout(function () { if (b.parentNode) { b.remove(); } }, ms || 15000);
	}
	/* A modal sheet with a question and one or two buttons (the same look as the install / "Still delivering?" sheets). */
	function askSheet(opts) {
		var old = document.getElementById('zdh-ask'); if (old) { old.remove(); }
		var box = el('<div class="zdh-install-wrap" id="zdh-ask" role="dialog" aria-modal="true"><div class="zdh-install-sheet"><h2 class="zdh-install-title">' + esc(opts.title) + '</h2><p class="zdh-install-sub">' + opts.html + '</p><div class="zdh-actions">' +
			(opts.secondary ? '<button type="button" class="zdh-secondary" id="ask-b">' + esc(opts.secondary) + '</button>' : '') +
			'<button type="button" class="' + (opts.danger ? 'zdh-stop' : 'zdh-primary') + '" id="ask-a">' + esc(opts.primary) + '</button></div>' +
			(opts.link ? '<p class="zdh-help" style="text-align:center;margin-top:.8rem"><button type="button" class="zdh-linkbtn" id="ask-c">' + esc(opts.link) + '</button></p>' : '') + '</div></div>');
		box.querySelector('#ask-a').addEventListener('click', function () { box.remove(); if (opts.onPrimary) { opts.onPrimary(); } });
		var b = box.querySelector('#ask-b'); if (b) { b.addEventListener('click', function () { box.remove(); if (opts.onSecondary) { opts.onSecondary(); } }); }
		var c = box.querySelector('#ask-c'); if (c) { c.addEventListener('click', function () { box.remove(); if (opts.onLink) { opts.onLink(); } }); }
		installRoot().appendChild(box);
		return box;
	}

	/* Startup: always land on Plan. A delivery that was active a few minutes ago (screen locked, app killed) is
	   re-opened after the server confirms it is still in progress; anything stale, finished or deleted is cleared. */
	(function boot() {
		S.tab = 'new';
		var t = recall('tab'); if (t === 'map' || t === 'lookup' || t === 'export') { S.tab = t; }
		var ses = session(), q = quick();
		if (q && !ses && Date.now() - (q.lastActive || q.started || 0) < 10 * 60000) { S.tab = 'quick'; }
		// a route that was being planned comes back: shown at once when Plan is the tab to open, otherwise waiting there
		var draft = loadDraft();
		if (draft && !ses && S.tab === 'new') { restoreDraft(draft, true); if (S.nd.step === 'busy') { return; } }
		else if (draft) { restoreDraft(draft, false); }
		/* a launch with { drop_id } (Jobs' My next, the chat's Open in Doorhangers) opens Clusters on that route — its
		 * card open and scrolled to. Published here because S, render and setCardOpen live in this closure. */
		OPEN_DROP = function (id) {
			S.tab = 'map'; try { store('tab', 'map'); } catch (err) {}
			S.drops = null; // the route was made elsewhere (the chat) after this card loaded: reload the list, never show a stale one
			render();
			var tries = 0, t = setInterval(function () {
				var c = document.getElementById('zdh-card-' + id);
				if (c) {
					var det = c.closest('details'); if (det) { det.open = true; }
					setCardOpen(c, true);
					try { c.scrollIntoView({ behavior: 'smooth', block: 'start' }); } catch (e) {}
				}
				if (c || ++tries > 40) { clearInterval(t); if (PENDING_DROP === id) { PENDING_DROP = 0; } if (!c) { toast('Route #' + id + ' is not in the list -- it may have been finished or deleted.', 6000); } }
			}, 250);
		};
		render();
		if (PENDING_DROP) { OPEN_DROP(PENDING_DROP); }
		logEv('boot', (isStandalone() ? 'app' : 'browser') + ' tab=' + S.tab + (ses ? ' session #' + ses.id + ' pending ' + Object.keys(ses.pending).length : '') + (q ? ' quick ' + Math.round(quickWalkMeters(q)) + ' m' : '') + (outbox().length ? ' outbox ' + outbox().length : '') + (navigator.onLine === false ? ' offline' : ''));
		if (draft && S.tab === 'new' && S.nd.strokes.length) { toast('Your route is back where you left it.', 5000); }
		flushOutbox();
		if (!ses) { staleQuickCheck(); return; }
		var recent = Date.now() - (ses.lastActive || ses.started || 0) < 10 * 60000;
		api('drops/' + ses.id).then(function (d) {
			if (d.status !== 'in_progress') { leaveDelivery(d.status === 'done' ? 'The delivery you had open was already finished — cleared.' : 'The delivery you had open is back to planned — cleared.', true); return; }
			if (ses.total !== d.addresses.length) { ses.total = d.addresses.length; saveSession(ses); }
			if (recent) { openDelivery(d.id, d); }
			else if (Object.keys(ses.pending).length || Object.keys(ses.undel).length) { syncProgress(false); } // ticks made before the phone was put away go up now
		}).catch(function (e) {
			if (e.status === 404) { leaveDelivery('The delivery you had open was deleted — cleared.', true); }
		});
	})();
	/* "Still delivering?" also on a clock, so a walk that never gets another GPS fix still gets asked. */
	setInterval(function () { var s = session(), q = quick(); if (s && S.tab === 'deliver') { askIfDone(s, 'planned'); } else if (q && !s && !q.ended) { askIfDone(q, 'quick'); } }, 60000);
	}
	/* Bridge.loadApp('doorhangers', { drop_id: N }) (Jobs' My next, the chat's Open in Doorhangers) opens Clusters on that
	 * route: its card open and scrolled to. Not cancelled, so the theme still brings the card into view. Declared BEFORE the
	 * first zdhStart(): when the root already exists, boot publishes OPEN_DROP synchronously and a later `var` would null it. */
	var PENDING_DROP = 0, OPEN_DROP = null; // OPEN_DROP is published by zdhStart once the app's state exists
	/* Start when the card exists: the theme fires `zdz_widgets_rendered` once it has written the widgets into the page. */
	zdhStart();
	document.addEventListener('zdz_app_launch', function (e) {
		var d = e && e.detail || {};
		if (String(d.appId || d.app || '') !== 'doorhangers') { return; }
		var id = parseInt((d.options || {}).drop_id, 10) || 0;
		if (id <= 0) { return; }
		PENDING_DROP = id;
		if (OPEN_DROP) { OPEN_DROP(id); } // else zdhStart consumes it at boot
	});
	document.addEventListener('zdz_widgets_rendered', zdhStart);
	document.addEventListener('DOMContentLoaded', function () { setTimeout(zdhStart, 300); });
})();
