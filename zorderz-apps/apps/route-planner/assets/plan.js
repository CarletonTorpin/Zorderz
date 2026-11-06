/* Route Planner — choosing and scoring door-hanger routes. Pure functions: no
   DOM, no Leaflet, no network. The walks themselves are Doorhangers' own planWalks (window.ZDH_WALKS, assets/walks.js):
   shared code, not shared data. The page (assets/app.js) and the tests read this as window.ZRP_PLAN.

   An AREA is one neighborhood the ranker ranked, with what POST plan/area read for it:
     { row: the ranker's row | null (a drawn area), houses: [{k, lat, lng, seg, street, street_key, zip, city,
       number?, unit?, no_solicit?}], segments: [{id, name, key, path, fnode, tnode}], tags: {"<street key>|<zip>":
       {tags: {slug: {level, product, label, notes}}, likely: {slug: {product, label}}}} | null, hung: [{key, street,
       date, status, low, high}], wx: the day's forecast for it ({status: ok | flagged | none | error, ...}) }
   A ROUTE is a walk through one area's houses, in walking order:
     { area: index, keys: [house key, ...], kind: planned | edited | drawn, ... measured and scored by measure() }

   The score (0-100) weighs six things, each 0..1:
     tags       the survey's confirmed tags on the route's streets that match a product (a street said for the whole
                street or neighborhood counts fully, a block 0.8, one home seen 0.5, "likely" from a hard-to-see feature
                nearby 0.3), averaged over the route's houses
     census     the ranker's own mix for the neighborhood and its owners / older homes / single-family scores
     fresh      how long since anyone hung there (never: 1; a year or more: 1) -- streets hung within the re-hang
                window are already left out of the route
     customers  the business's past customers nearby (the ranker's count, never a name): 5 or more = 1
     clean      No-Solicit houses skipped on the route's blocks, and streets the survey said are gated or post
                no-soliciting signs, count against it
     weather    the forecast for that day's walking window: good 1, no forecast yet 0.6, over the limits 0.1;
                good but GUSTY (gusts over their limit, under 1.5 x it) 0.4, with the warning "Gusty: hangers
                may blow off doors". Steady wind over its limit, or gusts over 1.5 x theirs, is "over the limits".
                With the weather's weight 0.10, gusts cost a route 6 points of 100 and a flagged day 9. */
(function (root) {
'use strict';
var WEIGHTS = { tags: 0.30, census: 0.25, fresh: 0.15, customers: 0.10, clean: 0.10, weather: 0.10 };
var LEVEL = { neighborhood: 1, street: 1, block: 0.8, seen: 0.5 };
var LIKELY = 0.3;
var WX = { ok: 1, gusty: 0.4, none: 0.6, error: 0.6, flagged: 0.1 }; // gusty, a good day with gusts over their limit
var COLORS = ['#E69F00', '#0072B2', '#009E73', '#CC79A7', '#56B4E9']; // Okabe-Ito, told apart by most eyes (vermilion stays the kit's "needs you"; black vanishes in dark mode)
var INK = { '#E69F00': '#000000', '#0072B2': '#FFFFFF', '#009E73': '#000000', '#CC79A7': '#000000', '#56B4E9': '#000000' }; // the badge's text: 4.5:1 or better on each
var NEGATIVE = ['no_solicit_signs', 'hoa_gated']; // the Core canvassing tags; configure() adds the site's own
/* Houses an hour when the caller says none: the site's planning pace (ZRP_CONFIG.perHour), else 40. */
function perHourDefault() { var c = root.ZRP_CONFIG; return (c && +c.perHour > 0) ? +c.perHour : 40; }
var CENSUS = ['owners', 'older_homes', 'single_family'];

function W() { return root.ZDH_WALKS; }
function distM(a, b) {
	var x = (b[1] - a[1]) * Math.cos((a[0] + b[0]) / 2 * Math.PI / 180) * 111320, y = (b[0] - a[0]) * 110540;
	return Math.sqrt(x * x + y * y);
}
function skey(h) { return (h.street_key || '') + '|' + (h.zip || ''); }
function clamp(v) { return Math.max(0, Math.min(1, v)); }
function num(v) { return typeof v === 'number' && isFinite(v) ? v : (typeof v === 'string' && v !== '' && isFinite(+v) ? +v : null); }

/* The houses of an area a route may use: mapped houses with a position, minus No-Solicit houses and any stretch
   hung (or planned) within the re-hang window. A hung row with a number range leaves out only those numbers on that
   street; without one (or a house without a number) the whole street. */
function prepare(area) {
	if (area._prep) { return area._prep; }
	var hung = {}, byKey = {}, bySeg = {}, usable = [], ns = [], hungOut = [], hungStreets = {};
	(area.hung || []).forEach(function (r) { (hung[r.key] = hung[r.key] || []).push(r); });
	(area.houses || []).forEach(function (h) {
		if (h.lat == null || h.lng == null || !h.k) { return; }
		byKey[h.k] = h;
		var on = hung[h.street_key || '']; // by the street alone: Doorhangers files a route's streets under one ZIP
		if (on) {
			var n = parseInt(h.number, 10), hit = on.filter(function (r) { var lo = num(r.low), hi = num(r.high); return lo == null || hi == null || !isFinite(n) || (n >= lo && n <= hi); })[0];
			if (hit) { hungOut.push(h); hungStreets[h.street] = hit; return; }
		}
		if (+h.no_solicit) { ns.push(h); return; }
		usable.push(h);
		if (h.seg != null) { (bySeg[h.seg] = bySeg[h.seg] || []).push(h.k); }
	});
	area._prep = { usable: usable, byKey: byKey, bySeg: bySeg, ns: ns, hungOut: hungOut, hungStreets: hungStreets };
	return area._prep;
}

/* The best product tag on a house's street (0..1), and whether the survey said the street is gated or posted. */
function tagOf(area, h) {
	var t = area.tags && area.tags[skey(h)], w = 0, neg = false, slugs = [];
	if (!t) { return { w: 0, neg: false, slugs: slugs }; }
	Object.keys(t.tags || {}).forEach(function (slug) {
		var i = t.tags[slug];
		if (NEGATIVE.indexOf(slug) !== -1) { neg = true; return; }
		if (i.product) { w = Math.max(w, LEVEL[i.level] || LEVEL.seen); slugs.push(slug); }
	});
	Object.keys(t.likely || {}).forEach(function (slug) { var i = t.likely[slug]; if (i.product && NEGATIVE.indexOf(slug) === -1) { w = Math.max(w, LIKELY); if (slugs.indexOf(slug) === -1) { slugs.push(slug); } } });
	return { w: w, neg: neg, slugs: slugs };
}

/* At most `max` streets, the route's order kept: a house on a street past the cap is left out (Doorhangers' 12). */
function capStreets(keys, byKey, max) {
	var seen = {}, n = 0, out = [];
	keys.forEach(function (k) {
		var h = byKey[k]; if (!h) { return; }
		var s = skey(h);
		if (!seen[s]) { if (n >= max) { return; } seen[s] = true; n++; }
		out.push(k);
	});
	return out;
}

/* Walking order for a set of houses: Doorhangers' loops over the map's roads (one walk; no doubling back where the
   blocks allow). Without walks.js the order given is kept. */
function order(area, keys, start) {
	var p = prepare(area), hs = keys.map(function (k) { return p.byKey[k]; }).filter(Boolean);
	var Wk = W(); if (!Wk || !hs.length) { return hs.map(function (h) { return h.k; }); }
	var r = Wk.planWalks(hs, area.segments || [], { size: 500, style: 'loop', perHour: perHourDefault(), start: start || undefined });
	var out = []; r.walks.forEach(function (w) { out = out.concat(w.keys); });
	return out;
}

/* Everything a card shows about a route, and its score. ctx: {perHour, size} */
function measure(route, areas, ctx) {
	var area = areas[route.area], p = prepare(area), row = area.row || null, hs = route.keys.map(function (k) { return p.byKey[k]; }).filter(Boolean);
	var n = hs.length, perHour = (ctx && ctx.perHour) || perHourDefault();
	var streets = {}, zips = {}, towns = {}, segs = {}, tagH = 0, negH = 0, tagCount = {}, len = 0;
	hs.forEach(function (h, i) {
		streets[h.street] = (streets[h.street] || 0) + 1;
		if (h.zip) { zips[h.zip] = (zips[h.zip] || 0) + 1; }
		if (h.city) { towns[h.city] = (towns[h.city] || 0) + 1; }
		if (h.seg != null) { segs[h.seg] = true; }
		var t = tagOf(area, h); tagH += t.w; if (t.neg) { negH++; }
		t.slugs.forEach(function (s) { tagCount[s] = (tagCount[s] || 0) + 1; });
		if (i) { len += distM([hs[i - 1].lat, hs[i - 1].lng], [h.lat, h.lng]); }
	});
	var nsOn = p.ns.filter(function (h) { return h.seg != null && segs[h.seg]; }).length;
	var top = function (o) { return Object.keys(o).sort(function (a, b) { return o[b] - o[a]; }); };
	// the six parts
	var f = {};
	f.tags = n ? clamp(tagH / n) : 0;
	var mix = row && num(row.mix) != null ? clamp(num(row.mix) / 100) : null, ing = [];
	CENSUS.forEach(function (k) { var s = row && row.ingredients && row.ingredients[k] ? num(row.ingredients[k].score) : null; if (s != null) { ing.push(clamp(s / 100)); } }); // the ranker's scores are 0-100 (a 1 is 1 %)
	var ingM = ing.length ? ing.reduce(function (a, b) { return a + b; }, 0) / ing.length : null;
	f.census = mix != null && ingM != null ? (mix + ingM) / 2 : (mix != null ? mix : (ingM != null ? ingM : 0.5));
	var lh = row ? num(row.last_hung_days) : null;
	f.fresh = lh == null ? 1 : clamp(lh / 365);
	// distance from recent coverage: streets hung within the window are left out; a route walking right
	// beside them is fresher the further it keeps away (full marks from 400 m)
	var near = Infinity;
	if (p.hungOut.length) { hs.forEach(function (h) { p.hungOut.forEach(function (o) { var d = distM([h.lat, h.lng], [o.lat, o.lng]); if (d < near) { near = d; } }); }); }
	if (isFinite(near)) { f.fresh = Math.min(f.fresh, 0.5 + 0.5 * clamp(near / 400)); }
	f.customers = row ? clamp((num(row.customers) || 0) / 5) : 0;
	f.clean = n ? clamp(1 - nsOn / (n + nsOn) - 0.5 * (negH / n)) : 0;
	var wx = area.wx || { status: 'error' };
	var gusty = wx.status === 'ok' && !!wx.gusty;
	f.weather = gusty ? WX.gusty : (WX[wx.status] != null ? WX[wx.status] : WX.error);
	var total = 0; Object.keys(WEIGHTS).forEach(function (k) { total += WEIGHTS[k] * f[k]; });
	// why, in words (counts and places only: never a customer, never a house number)
	var why = [];
	var tagged = Object.keys(tagCount).sort(function (a, b) { return tagCount[b] - tagCount[a]; });
	if (tagged.length) { why.push('survey: ' + tagged.slice(0, 3).map(function (s) { return labelOf(area, s) + ' (' + tagCount[s] + ' houses)'; }).join(', ')); }
	if (row && row.customers) { why.push(row.customers + ' past customer' + (row.customers === 1 ? '' : 's') + ' nearby'); }
	if (lh == null && row) { why.push('never hung'); } else if (lh != null) { why.push('last hung ' + lh + ' days ago'); }
	if (isFinite(near) && near < 400) { why.push(Math.round(near) + ' m from a street hung lately'); }
	if (nsOn) { why.push(nsOn + ' No-Solicit house' + (nsOn === 1 ? '' : 's') + ' skipped'); }
	if (negH) { why.push(negH + ' houses on streets noted as gated or no-soliciting'); }
	if (gusty) { why.push('gusty: hangers may blow off doors' + (wx.gust_mph != null ? ' (gusts to ' + wx.gust_mph + ' mph)' : '')); }
	route.houses = n;
	route.est_min = Math.round(n / perHour * 60);
	route.length_m = Math.round(len);
	route.streets = top(streets).map(function (s) { return { name: s, count: streets[s] }; });
	route.zip = top(zips)[0] || (row && row.zip) || '';
	route.city = top(towns)[0] || (row && row.town) || '';
	route.segs = segs;
	route.nsSkipped = nsOn;
	route.tagged = tagged.map(function (s) { return { slug: s, label: labelOf(area, s), product: productOf(area, s), houses: tagCount[s] }; });
	route.f = f;
	route.score = Math.round(total * 100);
	route.why = why;
	route.path = hs.map(function (h) { return [h.lat, h.lng]; });
	route.wx = wx;
	route.gusty = gusty;
	return route;
}
function labelOf(area, slug) {
	var out = slug;
	Object.keys(area.tags || {}).some(function (k) { var t = area.tags[k], i = (t.tags && t.tags[slug]) || (t.likely && t.likely[slug]); if (i && i.label) { out = i.label; return true; } return false; });
	return out;
}
function productOf(area, slug) {
	var out = '';
	Object.keys(area.tags || {}).some(function (k) { var t = area.tags[k], i = (t.tags && t.tags[slug]) || (t.likely && t.likely[slug]); if (i && i.product) { out = i.product; return true; } return false; });
	return out;
}

/* Candidate walks: each area cut into walks of about the walker's time (Doorhangers' planWalks, loop style), at most
   12 streets each. o: {minutes, perHour, maxStreets, start: [lat, lng] | null} */
function candidates(areas, o) {
	var Wk = W(), perHour = o.perHour || perHourDefault(), size = Math.max(5, Math.round((o.minutes || 120) / 60 * perHour)), out = [];
	if (!Wk) { return out; }
	areas.forEach(function (area, ai) {
		if (!area || area.error) { return; }
		var p = prepare(area);
		if (p.usable.length < 5) { return; }
		var row = area.row, start = o.start || (row && row.lat ? [row.lat, row.lon] : null);
		var r = Wk.planWalks(p.usable, area.segments || [], { size: size, style: 'loop', perHour: perHour, start: start || undefined });
		r.walks.forEach(function (w) {
			var keys = capStreets(w.keys, p.byKey, o.maxStreets || 12);
			if (keys.length) { out.push({ area: ai, keys: keys, kind: 'planned' }); }
		});
	});
	return out;
}

/* N distinct routes: the best scores first, never two that share a house; a second route from the same neighborhood
   gives way (x 0.85 for each one already chosen there) so the three are real alternatives, not three walks of one block; a walk under 40 % of the
   walker's time is chosen only when nothing fuller is left. */
function choose(cands, count, size) {
	var picked = [], used = {};
	function free(c) { return c.keys.every(function (k) { return !used[k]; }); }
	function same(c) { return picked.filter(function (p) { return p.area === c.area; }).length; }
	while (picked.length < count) {
		var best = null, bestV = -1, fullLeft = cands.some(function (c) { return picked.indexOf(c) === -1 && free(c) && c.keys.length >= 0.4 * size; });
		cands.forEach(function (c) {
			if (picked.indexOf(c) !== -1 || !free(c)) { return; }
			if (fullLeft && c.keys.length < 0.4 * size) { return; }
			var v = c.score * Math.pow(0.85, same(c));
			if (v > bestV) { bestV = v; best = c; }
		});
		if (!best) { break; }
		picked.push(best);
		best.keys.forEach(function (k) { used[k] = true; });
	}
	return picked;
}

/* The whole step: candidates, measured and scored, the N chosen, numbered and colored. */
function buildRoutes(areas, o) {
	var perHour = o.perHour || perHourDefault(), size = Math.max(5, Math.round((o.minutes || 120) / 60 * perHour));
	var cands = candidates(areas, o);
	cands.forEach(function (c) { measure(c, areas, { perHour: perHour }); });
	var routes = choose(cands, o.count || 3, size);
	routes.forEach(function (r, i) { r.n = i + 1; r.color = COLORS[i % COLORS.length]; r.ink = INK[r.color]; });
	return { routes: routes, candidates: cands.length, size: size };
}

/* Edit: a block (a road segment) in or out. In: the usable houses on it join; out: they leave. Then the walking
   order is worked out again and the route measured again. */
function toggleBlock(route, areas, segId, ctx, taken) {
	// taken: the houses other routes on the page hold -- a block put in brings only the ones nobody has
	var area = areas[route.area], p = prepare(area), on = (p.bySeg[segId] || []).filter(function (k) { return !(taken && taken[k]); });
	var has = route.keys.some(function (k) { var h = p.byKey[k]; return h && String(h.seg) === String(segId); });
	var keys = has ? route.keys.filter(function (k) { var h = p.byKey[k]; return !(h && String(h.seg) === String(segId)); }) : route.keys.concat(on.filter(function (k) { return route.keys.indexOf(k) === -1; }));
	route.keys = keys.length ? order(area, keys, route.path && route.path[0]) : [];
	route.kind = route.kind === 'drawn' ? 'drawn' : 'edited';
	return measure(route, areas, ctx);
}

/* The blocks worth offering in Edit: the route's own and the ones touching them (sharing a road node). */
function nearBlocks(route, areas) {
	var area = areas[route.area], p = prepare(area), segs = area.segments || [], mine = {}, nodes = {};
	route.keys.forEach(function (k) { var h = p.byKey[k]; if (h && h.seg != null) { mine[h.seg] = true; } });
	segs.forEach(function (s) { if (mine[s.id]) { if (s.fnode != null) { nodes[s.fnode] = true; } if (s.tnode != null) { nodes[s.tnode] = true; } } });
	return segs.filter(function (s) { return (mine[s.id] || (s.fnode != null && nodes[s.fnode]) || (s.tnode != null && nodes[s.tnode])) && (p.bySeg[s.id] || []).length; }).map(function (s) {
		var hs = (p.bySeg[s.id] || []).map(function (k) { return p.byKey[k]; }), ns = hs.map(function (h) { return parseInt(h.number, 10); }).filter(isFinite);
		return { id: s.id, name: s.name || (hs[0] && hs[0].street) || 'Block', houses: hs.length, low: ns.length ? Math.min.apply(null, ns) : null, high: ns.length ? Math.max.apply(null, ns) : null, on: !!mine[s.id], path: s.path };
	});
}

/* Draw: the houses a drawn stroke covers -- within `buf` metres of a traced line, or inside a circled loop. */
function within(pt, path, buf) {
	var cos = Math.cos(pt[0] * Math.PI / 180);
	for (var i = 0; i < path.length - 1; i++) {
		var a = path[i], b = path[i + 1];
		var px = (pt[1] - a[1]) * cos * 111320, py = (pt[0] - a[0]) * 110540, bx = (b[1] - a[1]) * cos * 111320, by = (b[0] - a[0]) * 110540;
		var l2 = bx * bx + by * by, u = l2 ? Math.max(0, Math.min(1, (px * bx + py * by) / l2)) : 0;
		var dx = px - u * bx, dy = py - u * by;
		if (dx * dx + dy * dy <= buf * buf) { return true; }
	}
	return path.length === 1 && distM(pt, path[0]) <= buf;
}
function inside(pt, poly) {
	var c = false;
	for (var i = 0, j = poly.length - 1; i < poly.length; j = i++) {
		var yi = poly[i][0], xi = poly[i][1], yj = poly[j][0], xj = poly[j][1];
		if (((yi > pt[0]) !== (yj > pt[0])) && (pt[1] < (xj - xi) * (pt[0] - yi) / ((yj - yi) || 1e-12) + xi)) { c = !c; }
	}
	return c;
}
/* A block is TRACED when at least half of it lies within `buf` of a stroke (sampled every ~10 m): a line drawn along
   one block does not take the corner houses of the next one, which a plain 30 m buffer would. */
function traced(seg, strokes, buf) {
	var path = seg && seg.path; if (!path || path.length < 2) { return false; }
	var pts = [];
	for (var i = 0; i < path.length - 1; i++) {
		var n = Math.max(1, Math.round(distM(path[i], path[i + 1]) / 10));
		for (var j = 0; j < n; j++) { pts.push([path[i][0] + (path[i + 1][0] - path[i][0]) * j / n, path[i][1] + (path[i + 1][1] - path[i][1]) * j / n]); }
	}
	pts.push(path[path.length - 1]);
	var near = pts.filter(function (pt) { return strokes.some(function (s) { return within(pt, s.type === 'loop' ? s.points.concat([s.points[0]]) : s.points, buf); }); }).length;
	return near / pts.length >= 0.5;
}
/* The houses a drawing covers: inside a circled loop; or within `buf` m of a line (or a loop's edge) on a block the
   drawing traces. segments: the area's roads (a house with no known block is judged by distance alone). */
function selectByStrokes(houses, strokes, buf, segments) {
	buf = buf || 30;
	var segById = {}, memo = {};
	(segments || []).forEach(function (s) { segById[s.id] = s; });
	function onTraced(h) {
		if (h.seg == null || !segById[h.seg]) { return true; }
		if (memo[h.seg] == null) { memo[h.seg] = traced(segById[h.seg], strokes, buf); }
		return memo[h.seg];
	}
	return houses.filter(function (h) {
		if (h.lat == null) { return false; }
		var pt = [h.lat, h.lng];
		if (strokes.some(function (s) { return s.type === 'loop' && inside(pt, s.points); })) { return true; }
		var close = strokes.some(function (s) { return within(pt, s.type === 'loop' ? s.points.concat([s.points[0]]) : s.points, buf); });
		return close && onTraced(h);
	}).map(function (h) { return h.k; });
}
/* A stroke: a loop when it ends near where it began (Doorhangers' own rule). */
function strokeOf(points) {
	var len = 0; for (var i = 1; i < points.length; i++) { len += distM(points[i - 1], points[i]); }
	var closed = len > 80 && distM(points[0], points[points.length - 1]) < Math.max(25, len * 0.12);
	return { type: closed ? 'loop' : 'path', points: points, length: len };
}
/* A drawn route over an area: the usable houses the strokes cover, in walking order. */
function drawnRoute(areas, ai, strokes, ctx, taken) {
	var area = areas[ai], p = prepare(area);
	var keys = capStreets(selectByStrokes(p.usable, strokes, 30, area.segments).filter(function (k) { return !(taken && taken[k]); }), p.byKey, (ctx && ctx.maxStreets) || 12);
	var start = strokes[0] && strokes[0].points[0];
	var r = { area: ai, keys: keys.length ? order(area, keys, start) : [], kind: 'drawn' };
	return measure(r, areas, ctx);
}

/* The houses the other routes hold (for edit and draw: no house in two routes). */
function takenBy(routes, except) {
	var t = {}; routes.forEach(function (r) { if (r !== except) { r.keys.forEach(function (k) { t[k] = true; }); } }); return t;
}

/* "10-12": Doorhangers' hours for a walk starting at `start` that lasts up to `hours` (as ZRP_Weather::window). */
function hoursWindow(start, hours) {
	var a = Math.max(0, Math.min(22, parseInt(start, 10) || 0)), b = Math.max(a + 1, Math.min(23, Math.ceil(a + Math.max(0.5, +hours || 2))));
	return a + '-' + b;
}

/* What Send to Doorhangers posts for a route: its houses as one walk, in the card's order, and its streets. */
function sendBody(route, req) {
	var names = route.streets.map(function (s) { return s.name; });
	return { walks: [route.keys.slice()], streets: names, zip: route.zip, city: route.city, date: req.date, hours: hoursWindow(req.start, req.hours),
		kind: route.kind, score: route.score, weather: route.wx && route.wx.status, area: req.area };
}

/* The site's own settings: which tags count against a route. The two Core canvassing tags always stay on the list. */
function configure(o) { if (o && Array.isArray(o.negative)) { NEGATIVE = ['no_solicit_signs', 'hoa_gated'].concat(o.negative.map(String).filter(function (t) { return t !== 'no_solicit_signs' && t !== 'hoa_gated'; })); } }

root.ZRP_PLAN = { configure: configure, WEIGHTS: WEIGHTS, COLORS: COLORS, INK: INK, prepare: prepare, tagOf: tagOf, capStreets: capStreets, order: order, measure: measure, candidates: candidates,
	choose: choose, buildRoutes: buildRoutes, toggleBlock: toggleBlock, nearBlocks: nearBlocks, selectByStrokes: selectByStrokes, strokeOf: strokeOf,
	drawnRoute: drawnRoute, takenBy: takenBy, hoursWindow: hoursWindow, sendBody: sendBody, distM: distM };
})(typeof window !== 'undefined' ? window : this);
