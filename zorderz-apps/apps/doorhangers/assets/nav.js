/* Doorhangers — the walk view's navigation maths.
   Pure functions: no DOM, no Leaflet, no network, so they can be tested on their own.
   app.js reads them as window.ZDH_NAV.

   A planned walk is the cluster's houses in walk order (planWalks put them there; each house knows its street and its
   road segment). That ordered list is the route: there is no road geometry between houses and no routing
   service. A "step" is a run of consecutive houses on one block (one road segment, and in a loop walk one side of it).
   The next-step banner says what happens at the end of the step the walker is on: a turn onto another street, the next
   block of the same street, crossing back along the other side, or the end of the route. */
(function (root) {
	'use strict';
	var FT = 3.28084, MI = 1609.34;

	/* ------------------------------------------------------------ geometry (metres at street scale) */
	function distM(a, b) {
		var x = (b[1] - a[1]) * Math.cos((a[0] + b[0]) / 2 * Math.PI / 180) * 111320;
		var y = (b[0] - a[0]) * 110540;
		return Math.sqrt(x * x + y * y);
	}
	function bearing(a, b) { // 0 = north, clockwise, 0..360
		var cos = Math.cos(a[0] * Math.PI / 180);
		var d = Math.atan2((b[1] - a[1]) * cos, b[0] - a[0]) * 180 / Math.PI;
		return (d + 360) % 360;
	}
	function turnOf(from, to) { return ((to - from) % 360 + 540) % 360 - 180; } // -180..180, + = clockwise (right)
	/* nearest point of a polyline: segment index, fraction, distance, signed side (+1 = left of the line's direction) */
	function nearestOnLine(p, line) {
		var best = { i: 0, u: 0, dist: Infinity, side: 0 };
		if (!line || !line.length) { return best; }
		if (line.length === 1) { best.dist = distM(p, line[0]); return best; }
		var cos = Math.cos(p[0] * Math.PI / 180);
		for (var i = 0; i < line.length - 1; i++) {
			var a = line[i], b = line[i + 1];
			var px = (p[1] - a[1]) * cos * 111320, py = (p[0] - a[0]) * 110540;
			var bx = (b[1] - a[1]) * cos * 111320, by = (b[0] - a[0]) * 110540;
			var l2 = bx * bx + by * by;
			var u = l2 ? Math.max(0, Math.min(1, (px * bx + py * by) / l2)) : 0;
			var dx = px - u * bx, dy = py - u * by, d = Math.sqrt(dx * dx + dy * dy);
			if (d < best.dist) { best = { i: i, u: u, dist: d, side: (bx * py - by * px) >= 0 ? 1 : -1, nx: l2 ? -by / Math.sqrt(l2) : 0, ny: l2 ? bx / Math.sqrt(l2) : 0 }; }
		}
		return best;
	}
	function mid(a, b) { return b ? [(a[0] + b[0]) / 2, (a[1] + b[1]) / 2] : a; }
	function pointOnLine(p, line) {
		var n = nearestOnLine(p, line), a = line[n.i], b = line[Math.min(n.i + 1, line.length - 1)];
		return [a[0] + (b[0] - a[0]) * n.u, a[1] + (b[1] - a[1]) * n.u];
	}
	function cumulative(path) { var c = [0]; for (var i = 1; i < path.length; i++) { c.push(c[i - 1] + distM(path[i - 1], path[i])); } return c; }
	/* how far along a road segment a point sits (metres from the segment's first vertex) */
	function alongLine(p, line) {
		var n = nearestOnLine(p, line), c = 0;
		for (var i = 0; i < n.i; i++) { c += distM(line[i], line[i + 1]); }
		if (line.length > 1) { c += n.u * distM(line[n.i], line[n.i + 1]); }
		return c;
	}

	/* ------------------------------------------------------------ words */
	var COMPASS = ['north', 'northeast', 'east', 'southeast', 'south', 'southwest', 'west', 'northwest'];
	function compass(deg) { return COMPASS[Math.round((((deg % 360) + 360) % 360) / 45) % 8]; }
	/* the side of a road a signed side is, as a compass word (normal nx east / ny north, side +1 = left of the line) */
	function sideCompass(nx, ny, side) { return compass(Math.atan2(nx * side, ny * side) * 180 / Math.PI); }
	/* Imperial and rounded: feet under 0.1 mile (to 10 ft under 100 ft, then to 50 ft), then tenths of a mile.
	   miles from 525 ft — 525–528 ft would round to "550 ft", past the switch (it printed "500 ft" here). */
	function fmtDist(m) {
		if (!(m >= 0) || !isFinite(m)) { return ''; }
		if (m * FT < 525) {
			var ft = m * FT;
			if (ft < 100) { return Math.max(10, Math.round(ft / 10) * 10) + ' ft'; }
			return Math.round(ft / 50) * 50 + ' ft';
		}
		var mi = m / MI;
		return (mi >= 10 ? String(Math.round(mi)) : mi.toFixed(1)) + ' mi';
	}
	/* the spoken form: "200 feet", "0.3 miles", and "Rd" read as "Road" */
	var SAY = { st: 'Street', rd: 'Road', ave: 'Avenue', av: 'Avenue', dr: 'Drive', ct: 'Court', ln: 'Lane', pl: 'Place', blvd: 'Boulevard', cir: 'Circle', ter: 'Terrace', trl: 'Trail', pkwy: 'Parkway', hwy: 'Highway', cv: 'Cove', wy: 'Way', sq: 'Square', pt: 'Point', gln: 'Glen', vw: 'View', vis: 'Vista', xing: 'Crossing', mt: 'Mount', n: 'North', s: 'South', e: 'East', w: 'West' };
	function sayable(text) {
		return String(text || '')
			.replace(/(\d+(?:\.\d+)?) ft\b/g, function (m, n) { return n + ' feet'; })
			.replace(/(\d+(?:\.\d+)?) mi\b/g, function (m, n) { return n + (n === '1.0' || n === '1' ? ' mile' : ' miles'); })
			// a street type is read out only at the end of a name ("St Louis Ave" is not Street Louis); N/S/E/W only before a name
			.replace(/\b([A-Z][a-z]{1,3})\b\.?(?=(?:\s+[NSEW])?(?:\s+in\b|\s*$|\s*[·,;]))/g, function (m, w) { var k = w.toLowerCase(); return SAY[k] && k.length > 1 ? SAY[k] : m; })
			.replace(/\b([NSEW])\b(?=\s+[A-Z][a-z])/g, function (m, w) { return SAY[w.toLowerCase()]; })
			.replace(/(\w) ([NSEW])\b(?=\s+in\b|\s*$|\s*[·,;])/g, function (m, c, w) { return c + ' ' + SAY[w.toLowerCase()]; });
	}
	function housesText(st) {
		var n = st.count, h = n + ' house' + (n === 1 ? '' : 's');
		if (st.sides === 'both') { return h + ', both sides'; }
		if (st.sides === 'left') { return h + ' on your left'; }
		if (st.sides === 'right') { return h + ' on your right'; }
		return h;
	}

	/* ------------------------------------------------------------ the route model
	   houses: in walk order, [{id, lat, lng, street, key, seg}] (houses without a position are skipped);
	   segPaths: {segId: [[lat,lng],…]} — the road centerlines the cluster carries (d.geometry.segments). */
	function model(houses, segPaths) {
		segPaths = segPaths || {};
		var hs = (houses || []).filter(function (h) { return h && h.lat != null && h.lng != null && isFinite(h.lat) && isFinite(h.lng); });
		var path = hs.map(function (h) { return [+h.lat, +h.lng]; });
		/* distances and the snap run along each house's spot on its road's centre line, not house to house —
		   on a both-sides walk the house-to-house line crosses the street at every house and nearly doubled the distance. */
		var line = hs.map(function (h, i) { var sp = h.seg != null ? segPaths[h.seg] : null; return sp && sp.length > 1 ? pointOnLine(path[i], sp) : path[i]; });
		var cum = cumulative(line);
		// absolute side of each house against its segment's drawn direction (+1 left, -1 right, 0 unknown)
		var abs = hs.map(function (h, i) { var sp = h.seg != null ? segPaths[h.seg] : null; return sp && sp.length > 1 ? nearestOnLine(path[i], sp).side : 0; });
		// runs: a new step where the block (segment, else the street) changes
		var runs = [], cur = null;
		hs.forEach(function (h, i) {
			var blk = h.seg != null && segPaths[h.seg] ? 's' + h.seg : 'k' + (h.key || h.street || '');
			if (!cur || cur.blk !== blk) { cur = { blk: blk, start: i, end: i }; runs.push(cur); } else { cur.end = i; }
		});
		// a loop walk goes down one side of a dead end and back up the other: that is two steps (AAAA BBBB), but a
		// zig-zag across a street (ABAB) is one
		var split = [];
		runs.forEach(function (r) {
			var flips = [], i;
			for (i = r.start + 1; i <= r.end; i++) { if (abs[i] && abs[i - 1] && abs[i] !== abs[i - 1]) { flips.push(i); } }
			if (flips.length === 1 && flips[0] - r.start >= 2 && r.end - flips[0] + 1 >= 2) {
				split.push({ blk: r.blk, start: r.start, end: flips[0] - 1 }); split.push({ blk: r.blk, start: flips[0], end: r.end, back: true });
			} else { split.push(r); }
		});
		var steps = split.map(function (r) {
			var h0 = hs[r.start], pts = path.slice(r.start, r.end + 1);
			return { start: r.start, end: r.end, count: r.end - r.start + 1, street: h0.street || '', key: h0.key || '', seg: h0.seg, back: !!r.back, ids: hs.slice(r.start, r.end + 1).map(function (h) { return h.id; }), heading: pts.length > 1 ? bearing(line[r.start], line[r.end]) : null, sides: null };
		});
		/* a one-house step points along its road, the way the walker goes (the bearing from the last house of the block
		   before would cut the corner diagonally and name a full turn "Bear right"); with no road, the way in or out */
		steps.forEach(function (st, k) {
			if (st.heading != null) { return; }
			var from = k > 0 ? line[steps[k - 1].end] : line[st.start], to = steps[k + 1] ? line[steps[k + 1].start] : line[st.start];
			var travel = from !== to ? bearing(from, to) : 0, sp = st.seg != null ? segPaths[st.seg] : null;
			if (sp && sp.length > 1) { var nl = nearestOnLine(path[st.start], sp), sb = bearing(sp[nl.i], sp[nl.i + 1]); st.heading = Math.abs(turnOf(sb, travel)) <= 90 ? sb : (sb + 180) % 360; }
			else if (k > 0) { st.heading = bearing(line[steps[k - 1].end], line[st.start]); }
			else { st.heading = travel; }
		});
		// which side(s) of the walker the step's houses are on
		steps.forEach(function (st) {
			var sp = st.seg != null ? segPaths[st.seg] : null; if (!sp || sp.length < 2) { return; }
			var dir;
			if (st.count > 1) { dir = alongLine(path[st.end], sp) >= alongLine(path[st.start], sp) ? 1 : -1; }
			else { var nl = nearestOnLine(path[st.start], sp), segB = bearing(sp[nl.i], sp[nl.i + 1]); dir = Math.abs(turnOf(segB, st.heading)) <= 90 ? 1 : -1; }
			var l = 0, r = 0;
			for (var i = st.start; i <= st.end; i++) { var s = abs[i] * dir; if (s > 0) { l++; } else if (s < 0) { r++; } }
			st.sides = l && r ? 'both' : (l ? 'left' : (r ? 'right' : null));
		});
		var stepOf = [];
		steps.forEach(function (st, k) { for (var i = st.start; i <= st.end; i++) { stepOf[i] = k; } });
		return { houses: hs, path: path, line: line, cum: cum, total: cum.length ? cum[cum.length - 1] : 0, steps: steps, stepOf: stepOf };
	}

	/* What happens at the end of step k. type: left | right | slight-left | slight-right | straight | next-block | back | uturn | end */
	function maneuver(M, k) {
		var st = M.steps[k], nx = M.steps[k + 1];
		if (!st) { return null; }
		if (!nx) { return { type: 'end', text: 'End of route', detail: '' }; }
		var same = (nx.key && nx.key === st.key) || (!nx.key && nx.street === st.street);
		var d = turnOf(st.heading, nx.heading), a = Math.abs(d), side = d < 0 ? 'left' : 'right';
		var onto = nx.street ? ' onto ' + nx.street : '';
		var m;
		if (nx.back && nx.seg === st.seg) { m = { type: 'back', text: 'Cross and come back along ' + (nx.street || 'the other side') }; }
		else if (a < 35) { m = same ? { type: 'next-block', text: 'Next block' } : { type: 'straight', text: 'Continue' + onto }; }
		else if (a < 60) { m = { type: 'slight-' + side, text: 'Bear ' + side + (same ? ' to stay on ' + nx.street : onto) }; }
		else if (a <= 150) { m = { type: side, text: 'Turn ' + side + (same ? ' to stay on ' + nx.street : onto) }; }
		else { m = same ? { type: 'back', text: 'Cross and come back along ' + (nx.street || 'the other side') } : { type: 'uturn', text: 'Turn around' + onto }; }
		m.detail = housesText(nx);
		return m;
	}

	/* Where the walker is on the route. hint = the segment index found last time: a loop comes back past its own start,
	   so a nearby segment just ahead of the hint wins over a slightly nearer one far behind or far ahead. */
	function snap(M, pos, hint) {
		var path = M.line || M.path, n = path.length;
		if (!n) { return null; }
		if (n === 1) { return { i: 0, u: 0, dist: distM(pos, path[0]), along: 0 }; }
		var cos = Math.cos(pos[0] * Math.PI / 180), g = null, loc = null;
		var lo = hint != null ? Math.max(0, hint - 2) : -1, hi = hint != null ? Math.min(n - 2, hint + 25) : -1;
		for (var i = 0; i < n - 1; i++) {
			var a = path[i], b = path[i + 1];
			var px = (pos[1] - a[1]) * cos * 111320, py = (pos[0] - a[0]) * 110540;
			var bx = (b[1] - a[1]) * cos * 111320, by = (b[0] - a[0]) * 110540;
			var l2 = bx * bx + by * by, u = l2 ? Math.max(0, Math.min(1, (px * bx + py * by) / l2)) : 0;
			var dx = px - u * bx, dy = py - u * by, d = Math.sqrt(dx * dx + dy * dy);
			var c = { i: i, u: u, dist: d };
			if (!g || d < g.dist) { g = c; }
			if (i >= lo && i <= hi && (!loc || d < loc.dist)) { loc = c; }
		}
		var best = loc && loc.dist <= g.dist + 15 ? loc : g;
		best.along = M.cum[best.i] + best.u * (M.cum[best.i + 1] - M.cum[best.i]);
		return best;
	}

	/* The turn is about halfway between the last house of a block and the first house of the next (the corner). */
	function turnPoint(M, k) {
		var st = M.steps[k]; if (!st) { return M.total; }
		if (st.end + 1 >= M.path.length) { return M.total; }
		return (M.cum[st.end] + M.cum[st.end + 1]) / 2;
	}
	/* The step the walker is on: the block of the segment they are walking, until they are past its turn point. */
	function stepAt(M, sp) {
		var k = M.stepOf[sp.i] || 0, st = M.steps[k];
		if (st && sp.i === st.end && sp.i + 1 < M.path.length && sp.u >= 0.5) { k++; }
		return Math.min(k, M.steps.length - 1);
	}
	var OFF_ROUTE_M = 60;
	/* The banner for a planned walk. pos [lat,lng]; hint = last snap index; done = {houseId: truthy} ticked so far; skip =
	   houses never to send the walker to (No-Solicit, and those unticked on purpose); skipped = the unticked ones.
	   Returns {mode: 'route'|'join'|'done'|'empty'|'wait', type, main, detail, dist, distM, then, snap, step, left, doneN,
	   totalN, target, say}. */
	function banner(M, pos, hint, done, skip, skipped) {
		done = done || {}; skip = skip || {}; skipped = skipped || {};
		var totalN = M.houses.length, doneN = 0, left = 0, nSkipped = 0;
		M.houses.forEach(function (h) { if (done[h.id]) { doneN++; } else if (!skip[h.id]) { left++; } if (skipped[h.id] && !done[h.id]) { nSkipped++; } });
		if (!totalN) { return { mode: 'empty', main: 'No houses with a position on this walk', detail: '', dist: '', then: null, doneN: 0, totalN: 0 }; }
		// nothing left to do — say so instead of pointing at a skipped house or the end of the route
		if (!left) { return { mode: 'done', type: 'done', main: 'Nothing left on this walk', detail: doneN + ' ticked' + (nSkipped ? ' · ' + nSkipped + ' skipped' : ''), dist: '', then: null, doneN: doneN, totalN: totalN, say: '' }; }
		if (!pos) { return { mode: 'wait', main: 'Waiting for GPS…', detail: '', dist: '', then: null, doneN: doneN, totalN: totalN }; }
		var sp = snap(M, pos, hint);
		if (sp.dist > OFF_ROUTE_M) {
			// not on the route yet (or wandered off): head to the first house not yet ticked, else the nearest point of the route
			/* before starting, the first house to do; after, the first house still to do from the nearest part
			   of the route on (a skipped house at the very start no longer pulls the walker back across the whole walk), never
			   a No-Solicit house; with none left ahead, the nearest point of the route */
			var started = doneN > 0, tIdx = -1, from = started ? sp.i : 0;
			for (var i = from; i < M.houses.length; i++) { if (!done[M.houses[i].id] && !skip[M.houses[i].id]) { tIdx = i; break; } }
			var target = tIdx >= 0 ? M.path[tIdx] : M.line[Math.min(M.line.length - 1, sp.i + (sp.u >= 0.5 ? 1 : 0))];
			var th = tIdx >= 0 ? M.houses[tIdx] : null;
			var dm = distM(pos, target);
			return { mode: 'join', type: 'head', main: 'Head ' + compass(bearing(pos, target)) + (started ? ' back to the route' : ' to the start'), detail: th ? (th.number ? th.number + ' ' : '') + (th.street || '') : '', dist: fmtDist(dm), distM: dm, then: null, snap: sp, target: target, doneN: doneN, totalN: totalN, left: M.total - sp.along, say: '' };
		}
		var k = stepAt(M, sp);
		var st = M.steps[k], m = maneuver(M, k);
		var at = m.type === 'end' ? M.total : turnPoint(M, k);
		var dm2 = Math.max(0, at - sp.along);
		/* "Then …": the next real manoeuvre. Block after block of the same street is not one, so it looks past them to
		   the next turn and says how far that is — a walker sees a street or two ahead. Ahead flies there too. */
		var then = null, target = m.type === 'end' ? M.path[M.path.length - 1] : mid(M.line[st.end], M.line[st.end + 1]);
		if (m.type !== 'end') {
			var j = k + 1;
			if (m.type === 'next-block' || m.type === 'straight') { while (j < M.steps.length - 1 && maneuver(M, j).type === 'next-block') { j++; } }
			var m2 = maneuver(M, j);
			if (m2) {
				var far = j > k + 1 || m.type === 'next-block';
				then = { type: m2.type, text: m2.text + (far && m2.type !== 'end' ? ' · ' + fmtDist(Math.max(0, turnPoint(M, j) - sp.along)) : ''), detail: m2.type === 'end' ? '' : m2.detail };
				if (m.type === 'next-block' && m2.type !== 'end' && M.steps[j]) { target = mid(M.line[M.steps[j].end], M.line[M.steps[j].end + 1]); }
			}
		}
		var main = m.type === 'end' ? 'End of route' : m.text;
		return { mode: 'route', type: m.type, main: main, detail: m.type === 'end' ? (st.street ? st.street + ' · ' : '') + housesText(st) : m.detail, dist: fmtDist(dm2), distM: dm2, then: then, snap: sp, step: k, target: target, street: st.street, doneN: doneN, totalN: totalN, left: Math.max(0, M.total - sp.along), say: sayable((m.type === 'end' ? 'End of route' : m.text) + ' in ' + fmtDist(dm2)) };
	}
	/* the heading of the route where the walker is (for the look-ahead camera): steadier than the phone's own */
	function routeHeading(M, sp) {
		if (!sp || M.path.length < 2) { return null; }
		var st = M.steps[stepAt(M, sp)];
		return st && st.heading != null ? st.heading : bearing(M.path[sp.i], M.path[Math.min(sp.i + 1, M.path.length - 1)]);
	}

	/* ------------------------------------------------------------ Quick Start (no plan)
	   ctx: {segments: [{id, name, path}], addresses: [{lat, lng, seg, street, number, covered}]} — the map data around
	   the walker, fetched as the walk goes. Returns the street, the side being logged, this block's houses on that side,
	   and the nearest block not yet walked (its direction and distance). */
	function quickContext(ctx, pos) {
		if (!ctx || !pos || !ctx.segments || !ctx.segments.length) { return null; }
		var segById = {}, best = null;
		ctx.segments.forEach(function (s) {
			if (!s.path || s.path.length < 2) { return; }
			segById[s.id] = s;
			var n = nearestOnLine(pos, s.path);
			if (!best || n.dist < best.n.dist) { best = { s: s, n: n }; }
		});
		var out = { street: '', side: '', here: 0, hereDone: 0, next: null };
		var groups = {}, curGroup = null, curSeg = null;
		(ctx.addresses || []).forEach(function (a) {
			if (a.lat == null || a.seg == null || !segById[a.seg]) { return; }
			if (a._side == null) { a._side = nearestOnLine([a.lat, a.lng], segById[a.seg].path).side; }
			var gk = a.seg + ':' + a._side;
			(groups[gk] = groups[gk] || { seg: a.seg, side: a._side, list: [] }).list.push(a);
		});
		if (best && best.n.dist <= 35) {
			out.street = best.s.name || '';
			if (best.n.dist >= 2.5) { out.side = sideCompass(best.n.nx, best.n.ny, best.n.side) + ' side'; curGroup = best.s.id + ':' + best.n.side; }
			else { curSeg = best.s.id; } // on the centre line: neither side of this block is offered as the next one
			var g0 = curGroup ? groups[curGroup] : null;
			if (g0) { out.here = g0.list.length; out.hereDone = g0.list.filter(function (a) { return a.covered; }).length; }
		}
		var nb = null;
		Object.keys(groups).forEach(function (gk) {
			if (gk === curGroup || groups[gk].seg === curSeg) { return; }
			var g = groups[gk], open = g.list.filter(function (a) { return !a.covered; });
			if (!open.length || open.length < g.list.length / 2) { return; } // half the block or more already walked
			var near = null;
			open.forEach(function (a) { var d = distM(pos, [a.lat, a.lng]); if (!near || d < near.d) { near = { d: d, a: a }; } });
			if (!nb || near.d < nb.d) { nb = { d: near.d, a: near.a, g: g }; }
		});
		if (nb) {
			var s2 = segById[nb.g.seg], n2 = nearestOnLine([nb.a.lat, nb.a.lng], s2.path);
			out.next = { street: s2.name || nb.a.street || '', side: sideCompass(n2.nx, n2.ny, nb.g.side) + ' side', houses: nb.g.list.length, dir: compass(bearing(pos, [nb.a.lat, nb.a.lng])), dist: fmtDist(nb.d), distM: nb.d, target: [nb.a.lat, nb.a.lng] };
		}
		return out;
	}
	/* tick the mapped houses a walking fix passed (the same 35 m buffer Done uses to list them) */
	/* houses in ~100 m cells, so a long track against a big area is not every point × every house */
	function grid(ctx) {
		if (ctx._grid) { return ctx._grid; }
		var g = {}; (ctx.addresses || []).forEach(function (a) { if (a.lat == null) { return; } var k = Math.floor(a.lat / 0.0009) + ':' + Math.floor(a.lng / 0.0011); (g[k] = g[k] || []).push(a); });
		ctx._grid = g; return g;
	}
	function markCovered(ctx, p, radius) {
		var r = radius || 35, n = 0;
		if (!ctx || !ctx.addresses) { return 0; }
		var g = grid(ctx), cy = Math.floor(p[0] / 0.0009), cx = Math.floor(p[1] / 0.0011);
		for (var dy = -1; dy <= 1; dy++) {
			for (var dx = -1; dx <= 1; dx++) {
				(g[(cy + dy) + ':' + (cx + dx)] || []).forEach(function (a) { if (!a.covered && distM(p, [a.lat, a.lng]) <= r) { a.covered = 1; n++; } });
			}
		}
		return n;
	}

	/* ------------------------------------------------------------ the camera
	   A blend of headings that copes with 359° → 1° (a = old, b = new, w = weight of the new). */
	function blendHeading(a, b, w) {
		if (a == null) { return b; } if (b == null) { return a; }
		return (a + turnOf(a, b) * w + 360) % 360;
	}
	/* screen offset (px) that puts the walker's dot in the lower third, ahead = the direction of travel (north-up map) */
	function lookAhead(heading, w, h) {
		if (heading == null || !w || !h) { return [0, 0]; }
		var r = heading * Math.PI / 180;
		return [Math.sin(r) * w / 5, -Math.cos(r) * h / 5]; // a fifth: the dot sits 70 % down, inside the lower third
	}

	root.ZDH_NAV = { distM: distM, bearing: bearing, turnOf: turnOf, compass: compass, fmtDist: fmtDist, sayable: sayable, model: model, maneuver: maneuver, snap: snap, banner: banner, routeHeading: routeHeading, stepAt: stepAt, turnPoint: turnPoint, quickContext: quickContext, markCovered: markCovered, blendHeading: blendHeading, lookAhead: lookAhead, OFF_ROUTE_M: OFF_ROUTE_M };
})(typeof window !== 'undefined' ? window : this);
