/* Doorhangers — planWalks and its helpers, kept apart from app.js so the Route Planner can cut
   the same walks the Plan tab cuts. Pure functions: no DOM, no Leaflet, no network, no data -- shared CODE, not shared data
   (the houses and roads a caller passes in come to it through Doorhangers' own read verb, doorhangers.area_houses).
   app.js and the Route Planner read them as window.ZDH_WALKS.

   planWalks(houses, segments, opts)
     houses   [{k, lat, lng, seg, street, street_key}]   seg = the road segment id the house is on
     segments [{id, name, path: [[lat,lng],...], fnode, tnode}]
     opts     {size, style: 'loop'|'street', perHour, start: [lat,lng]}
     returns  {walks: [{n, keys, count, est_min, streets, path}], walkOf, orderOf, size, style, perHour} */
(function (root) {
'use strict';
function distM(a, b) { // [lat,lng] pairs, equirectangular approximation (fine at street scale)
	var x = (b[1] - a[1]) * Math.cos((a[0] + b[0]) / 2 * Math.PI / 180) * 111320;
	var y = (b[0] - a[0]) * 110540;
	return Math.sqrt(x * x + y * y);
}
function pathLengthM(path) {
	var l = 0;
	for (var i = 1; i < path.length; i++) { l += distM(path[i - 1], path[i]); }
	return l;
}
/* where a point sits along a road centerline: t = 0..1 by length, side = +1 left / -1 right of the path direction */
function projectOnPath(p, path) {
	var cos = Math.cos(p[0] * Math.PI / 180), cum = [0], total = 0, i;
	for (i = 0; i < path.length - 1; i++) { total += distM(path[i], path[i + 1]); cum.push(total); }
	var best = { dist: Infinity, t: 0, side: 1 };
	for (i = 0; i < path.length - 1; i++) {
		var a = path[i], b = path[i + 1];
		var px = (p[1] - a[1]) * cos * 111320, py = (p[0] - a[0]) * 110540, bx = (b[1] - a[1]) * cos * 111320, by = (b[0] - a[0]) * 110540;
		var l2 = bx * bx + by * by; if (!l2) { continue; }
		var u = Math.max(0, Math.min(1, (px * bx + py * by) / l2));
		var dx = px - u * bx, dy = py - u * by, d = Math.sqrt(dx * dx + dy * dy);
		if (d < best.dist) { best = { dist: d, t: total ? (cum[i] + u * Math.sqrt(l2)) / total : 0, side: (bx * py - by * px) >= 0 ? 1 : -1 }; }
	}
	return best;
}
function bearingDeg(from, to) { var cos = Math.cos(from[0] * Math.PI / 180); return Math.atan2((to[1] - from[1]) * cos, to[0] - from[0]) * 180 / Math.PI; } // 0 = north, clockwise
/* road graph: nodes are the road layer's node ids when present, else snapped endpoint coordinates */
function roadGraph(segments) {
	var nodes = {}, edges = [], byId = {};
	function nid(id, pt) { var k = id != null ? 'n' + id : 'c' + pt[0].toFixed(5) + ',' + pt[1].toFixed(5); if (!nodes[k]) { nodes[k] = { k: k, ll: pt, edges: [] }; } return k; }
	segments.forEach(function (s, idx) {
		if (!s.path || s.path.length < 2) { return; }
		var e = { i: idx, seg: s, a: nid(s.fnode, s.path[0]), b: nid(s.tnode, s.path[s.path.length - 1]), len: pathLengthM(s.path) };
		if (e.a === e.b) { return; }
		edges.push(e); nodes[e.a].edges.push(e); nodes[e.b].edges.push(e); byId[s.id] = e;
	});
	return { nodes: nodes, edges: edges, byId: byId, keys: Object.keys(nodes) };
}
function dijkstra(G, src) { // O(V²) — a few hundred nodes at most
	var dist = {}, done = {}, keys = G.keys, n, j;
	for (j = 0; j < keys.length; j++) { dist[keys[j]] = Infinity; }
	dist[src] = 0;
	for (n = 0; n < keys.length; n++) {
		var best = null, bd = Infinity;
		for (j = 0; j < keys.length; j++) { var k = keys[j]; if (!done[k] && dist[k] < bd) { bd = dist[k]; best = k; } }
		if (best === null) { break; }
		done[best] = 1;
		G.nodes[best].edges.forEach(function (e) { var o = e.a === best ? e.b : e.a, d2 = bd + e.len; if (d2 < dist[o]) { dist[o] = d2; } });
	}
	return dist;
}
/* faces of the road graph by the right-hand rule: the face to the right of directed edge e (dir 1 = path forward) */
function traceFaces(G) {
	var faceOf = {}, faces = [];
	function outBearing(e, from) { var p = e.seg.path, q = from === e.a ? p[1] : p[p.length - 2]; return bearingDeg(G.nodes[from].ll, q); }
	G.edges.forEach(function (e0) {
		[1, -1].forEach(function (dir0) {
			if (faceOf[e0.i + ':' + dir0] != null) { return; }
			var face = { id: faces.length, dedges: [] }, cur = e0, cdir = dir0, steps = 0;
			while (steps++ < 3000) {
				var k = cur.i + ':' + cdir;
				if (faceOf[k] != null) { break; }
				faceOf[k] = face.id; face.dedges.push({ e: cur, dir: cdir, from: cdir === 1 ? cur.a : cur.b, to: cdir === 1 ? cur.b : cur.a });
				var to = cdir === 1 ? cur.b : cur.a, back = outBearing(cur, to), bestE = null, bestAng = 361;
				G.nodes[to].edges.forEach(function (c) { // the sharpest right turn = smallest counter-clockwise angle from the way back
					var ang = (back - outBearing(c, to) + 360) % 360;
					if (c === cur && ang < 1e-6) { ang = 360; } // turn around only at a dead end
					if (ang < bestAng) { bestAng = ang; bestE = c; }
				});
				if (!bestE) { break; }
				cur = bestE; cdir = bestE.a === to ? 1 : -1;
			}
			faces.push(face);
		});
	});
	return { faceOf: faceOf, faces: faces };
}
/* Plan the walks. houses: [{k, lat, lng, seg, street, street_key}]; segments: road segments; opts {size, style, perHour, start}.
   Returns {walks: [{n, keys, count, est_min, streets, path}], walkOf, orderOf}. */
function planWalks(houses, segments, opts) {
	var size = Math.max(5, Math.min(500, +opts.size || 60)), perHour = Math.max(5, Math.min(300, +opts.perHour || 40)), style = opts.style === 'street' ? 'street' : 'loop';
	var G = roadGraph(segments || []);
	var units = {}, orphans = [];
	houses.forEach(function (h) {
		var e = h.seg != null ? G.byId[h.seg] : null;
		if (!e || h.lat == null) { orphans.push(h); return; }
		var pr = projectOnPath([h.lat, h.lng], e.seg.path);
		var uk = style === 'street' ? String(e.i) : e.i + ':' + (pr.side === -1 ? 1 : -1); // loop style: side −1 (right of forward) is the face of the forward direction
		if (!units[uk]) { units[uk] = { key: uk, e: e, side: pr.side, houses: [] }; }
		units[uk].houses.push({ h: h, t: pr.t });
	});
	var ukeys = Object.keys(units);
	var tour = []; // ordered list of {unit, dir}
	if (ukeys.length) {
		// starting point: the house nearest the first thing drawn, else the north-western house
		var start = opts.start, firstH = null, bestD = Infinity;
		houses.forEach(function (h) { if (h.lat == null) { return; } var d = start ? distM(start, [h.lat, h.lng]) : -(h.lat - h.lng); if (d < bestD) { bestD = d; firstH = h; } });
		var curNode = null;
		if (firstH && firstH.seg != null && G.byId[firstH.seg]) { var fe = G.byId[firstH.seg], pr0 = projectOnPath([firstH.lat, firstH.lng], fe.seg.path); curNode = pr0.t < 0.5 ? fe.a : fe.b; }
		else { curNode = G.keys[0]; }
		var visited = {};
		if (style === 'street') {
			while (tour.length < ukeys.length) {
				var dist = dijkstra(G, curNode), pick = null, pickCost = Infinity, pickDir = 1;
				ukeys.forEach(function (uk) {
					if (visited[uk]) { return; }
					var u = units[uk], da = dist[u.e.a], db = dist[u.e.b];
					if (!isFinite(da)) { da = distM(G.nodes[curNode].ll, G.nodes[u.e.a].ll) * 1.5; }
					if (!isFinite(db)) { db = distM(G.nodes[curNode].ll, G.nodes[u.e.b].ll) * 1.5; }
					var c = Math.min(da, db); if (c < pickCost) { pickCost = c; pick = u; pickDir = da <= db ? 1 : -1; }
				});
				visited[pick.key] = 1; tour.push({ unit: pick, dir: pickDir });
				curNode = pickDir === 1 ? pick.e.b : pick.e.a;
			}
		} else {
			var F = traceFaces(G), loops = {};
			ukeys.forEach(function (uk) { var u = units[uk], dir = +uk.split(':')[1], fid = F.faceOf[u.e.i + ':' + dir]; if (fid == null) { fid = 'x' + uk; } (loops[fid] = loops[fid] || { fid: fid, units: {} }).units[uk] = 1; });
			var loopIds = Object.keys(loops), doneLoops = {};
			while (Object.keys(doneLoops).length < loopIds.length) {
				var dist2 = dijkstra(G, curNode), bestL = null, bestC = Infinity, entryIdx = 0;
				loopIds.forEach(function (fid) {
					if (doneLoops[fid]) { return; }
					var face = F.faces[fid], seq = face ? face.dedges : [];
					if (!seq.length) { // unit without a face (isolated): treat as its own loop
						var u1 = units[Object.keys(loops[fid].units)[0]], d1 = Math.min(dist2[u1.e.a], dist2[u1.e.b]); if (!isFinite(d1)) { d1 = distM(G.nodes[curNode].ll, G.nodes[u1.e.a].ll) * 1.5; }
						if (d1 < bestC) { bestC = d1; bestL = fid; entryIdx = 0; }
						return;
					}
					seq.forEach(function (de, idx) { if (!loops[fid].units[de.e.i + ':' + de.dir]) { return; } var d3 = dist2[de.from]; if (!isFinite(d3)) { d3 = distM(G.nodes[curNode].ll, G.nodes[de.from].ll) * 1.5; } if (d3 < bestC) { bestC = d3; bestL = fid; entryIdx = idx; } });
				});
				doneLoops[bestL] = 1;
				var face2 = F.faces[bestL], seq2 = face2 ? face2.dedges : [];
				if (!seq2.length) { var u2 = units[Object.keys(loops[bestL].units)[0]]; tour.push({ unit: u2, dir: 1 }); curNode = u2.e.b; continue; }
				for (var s = 0; s < seq2.length; s++) { var de2 = seq2[(entryIdx + s) % seq2.length], uk2 = de2.e.i + ':' + de2.dir; if (loops[bestL].units[uk2]) { tour.push({ unit: units[uk2], dir: de2.dir }); } }
				curNode = seq2[(entryIdx + seq2.length - 1) % seq2.length].to;
			}
		}
	}
	// ordered houses per unit, then chunks of about `size` (balanced, cut at street/side boundaries)
	var total = houses.length - orphans.length, k = Math.max(1, Math.ceil(total / size));
	var ordered = tour.map(function (t) { return t.unit.houses.slice().sort(function (x, y) { return t.dir === 1 ? x.t - y.t : y.t - x.t; }).map(function (x) { return x.h; }); });
	function chunk(target) {
		var out = [], cur = { keys: [], count: 0 };
		function closeWalk() { if (cur.count) { out.push(cur); } cur = { keys: [], count: 0 }; }
		ordered.forEach(function (unitHouses) {
			var hs = unitHouses.slice();
			while (hs.length) {
				var room = size - cur.count;
				if (cur.count && (hs.length > room || (cur.count >= target * 0.85 && Math.abs(cur.count + hs.length - target) > Math.abs(cur.count - target)))) {
					if (hs.length > size) { var part = hs.splice(0, room); part.forEach(function (h) { cur.keys.push(h.k); }); cur.count += part.length; }
					closeWalk(); continue;
				}
				var take = hs.splice(0, Math.min(hs.length, size - cur.count));
				take.forEach(function (h) { cur.keys.push(h.k); }); cur.count += take.length;
				if (cur.count >= size) { closeWalk(); }
			}
		});
		closeWalk();
		return out;
	}
	function spread(ws) { var c = ws.map(function (w) { return w.count; }); return Math.max.apply(null, c) - Math.min.apply(null, c); }
	var walks = chunk(total / k);
	for (var pass = 0; pass < 3 && walks.length > 1; pass++) { // the unit sizes rarely pack exactly: re-aim at the number of walks we actually got
		var again = chunk(total / walks.length);
		if (again.length === walks.length && spread(again) < spread(walks)) { walks = again; } else { break; }
	}
	if (!walks.length) { walks.push({ keys: [], count: 0 }); }
	var byKey = {}; houses.forEach(function (h) { byKey[h.k] = h; });
	orphans.forEach(function (o) { // a house with no road nearby joins the walk of its nearest house
		var bw = walks[0], bi = -1, bd = Infinity;
		walks.forEach(function (w) { w.keys.forEach(function (key, idx) { var h = byKey[key]; if (h && h.lat != null && o.lat != null) { var d = distM([h.lat, h.lng], [o.lat, o.lng]); if (d < bd) { bd = d; bw = w; bi = idx; } } }); });
		bw.keys.splice(bi + 1, 0, o.k); bw.count++;
	});
	var walkOf = {}, orderOf = {};
	walks.forEach(function (w, i) {
		w.n = i + 1; w.est_min = Math.round(w.count / perHour * 60);
		var sc = {}; w.keys.forEach(function (key, idx) { walkOf[key] = w.n; orderOf[key] = idx; var h = byKey[key]; if (h) { sc[h.street] = (sc[h.street] || 0) + 1; } });
		w.streets = Object.keys(sc).sort(function (a, b) { return sc[b] - sc[a]; }).slice(0, 3);
		w.path = w.keys.map(function (key) { var h = byKey[key]; return h && h.lat != null ? [h.lat, h.lng] : null; }).filter(Boolean);
	});
	return { walks: walks, walkOf: walkOf, orderOf: orderOf, size: size, style: style, perHour: perHour };
}

root.ZDH_WALKS = { planWalks: planWalks, roadGraph: roadGraph, projectOnPath: projectOnPath, dijkstra: dijkstra, traceFaces: traceFaces, bearingDeg: bearingDeg, distM: distM, pathLengthM: pathLengthM };
})(typeof window !== 'undefined' ? window : this);
