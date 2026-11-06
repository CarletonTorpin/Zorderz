/* Doorhangers — old routes from paper: the maths (window.ZDH_PAPER).
   Pure functions: no DOM, no Leaflet, no network, so they can be tested on made-up sheets.
   app.js draws the picture on a canvas, hands the pixels here, and keeps the screens.

   A printed route sheet ("North 2", the walked streets in highlighter) reaches us three ways: a phone PHOTO of the paper (seen
   at an angle, so sizes change across the sheet), a flat SCAN or PDF page, or a map SCREENSHOT. The reader names 5–8
   intersections with their position in the picture (0–1000 of its width and height); the server finds each one on
   the map's roads. From those pairs this file lines the picture up with the ground, finds the highlighter, and decides
   which mapped houses it marks.

   Lining up — the rules, settled by probing with random sheets (see the tests):
   - FLAT fit (scale, rotation, shift) from 2+ intersections: screenshots, scans, and the default.
   - PERSPECTIVE fit (a homography, Hartley-normalised DLT) only for a photo, and only with 5+ intersections kept — one
     more than it needs, so it can't simply bend to whatever it is given.
   - Every 2-intersection seed (flat) and every 4-intersection seed (perspective, at most 220) keeps the intersections
     it explains within 45 m, is fitted again on those, and again, until the kept set stops growing. Only refits are
     ranked: a 4-point seed fits its own 4 exactly and would always look perfect.
   - Leave-one-out: each kept intersection must be predicted by a fit made without it. The MEDIAN of those errors must
     stay within 1.5 × 45 m (one corner is always predicted poorly; a misread intersection makes the set disagree widely).
   - The best flat and the best perspective fit are kept apart and compared ONCE at the end (comparing as we went made
     the answer depend on the order of the trials). More intersections explained wins. Two or more extra: perspective.
     A tie: perspective only when the flat fit is visibly off (over 15 m) and perspective is under 60 % of it, both
     scaled up for the parameters they spend. Exactly one extra: each intersection only perspective explains must also be
     predicted by a perspective fit made without it, else perspective bent to a misread one and flat is used.
   - Accepted when at least min(3, n) are kept, the error over them is ≤ 40 m (perspective) or ≤ 60 m (flat), and the
     scale is between 0.02 and 40 metres per pixel. */
(function (root) {
	'use strict';
	var MX = 111320, MY = 110540;

	/* ------------------------------------------------------------ metres around an origin */
	function toXY(ll, o) { return [(ll[1] - o[1]) * Math.cos(o[0] * Math.PI / 180) * MX, (ll[0] - o[0]) * MY]; }
	function toLL(p, o) { return [o[0] + p[1] / MY, o[1] + p[0] / (Math.cos(o[0] * Math.PI / 180) * MX)]; }
	function dist(a, b) { var p = toXY(b, a); return Math.sqrt(p[0] * p[0] + p[1] * p[1]); }
	function middle(pairs) { var a = 0, b = 0; pairs.forEach(function (p) { a += p.lat; b += p.lng; }); return [a / pairs.length, b / pairs.length]; }
	function px(p, w, h) { return [p.x / 1000 * w, p.y / 1000 * h]; }

	/* ------------------------------------------------------------ the flat fit (least squares similarity) */
	function flat(pairs, w, h) {
		if (!pairs || pairs.length < 2) { return null; }
		var o = middle(pairs), n = pairs.length;
		// picture y runs down, the ground's north runs up: work with y flipped so the rotation is a plain one
		var P = pairs.map(function (p) { var q = px(p, w, h); return [q[0], -q[1]]; });
		var G = pairs.map(function (p) { return toXY([p.lat, p.lng], o); });
		var pc = [0, 0], gc = [0, 0];
		P.forEach(function (p, i) { pc[0] += p[0] / n; pc[1] += p[1] / n; gc[0] += G[i][0] / n; gc[1] += G[i][1] / n; });
		var sa = 0, sb = 0, spp = 0;
		P.forEach(function (p, i) {
			var x = p[0] - pc[0], y = p[1] - pc[1], X = G[i][0] - gc[0], Y = G[i][1] - gc[1];
			sa += x * X + y * Y; sb += x * Y - y * X; spp += x * x + y * y;
		});
		if (!spp) { return null; }
		var ang = Math.atan2(sb, sa), s = Math.sqrt(sa * sa + sb * sb) / spp, c = Math.cos(ang), sn = Math.sin(ang);
		var tx = gc[0] - s * (c * pc[0] - sn * pc[1]), ty = gc[1] - s * (sn * pc[0] + c * pc[1]);
		return finish({
			kind: 'flat', mPerPx: s, rotationDeg: ang * 180 / Math.PI,
			fwd: function (x, y) { var Y = -y; return toLL([s * (c * x - sn * Y) + tx, s * (sn * x + c * Y) + ty], o); },
			inv: function (lat, lng) { var g = toXY([lat, lng], o), dx = g[0] - tx, dy = g[1] - ty; return [(c * dx + sn * dy) / s, (sn * dx - c * dy) / s]; }
		}, pairs, w, h);
	}

	/* ------------------------------------------------------------ the perspective fit (homography) */
	function solve(A, b) { // Gaussian elimination, partial pivoting
		var n = A.length, M = A.map(function (r, i) { return r.concat([b[i]]); }), i, j, k;
		for (i = 0; i < n; i++) {
			var piv = i;
			for (j = i + 1; j < n; j++) { if (Math.abs(M[j][i]) > Math.abs(M[piv][i])) { piv = j; } }
			if (Math.abs(M[piv][i]) < 1e-12) { return null; }
			var t = M[i]; M[i] = M[piv]; M[piv] = t;
			for (j = 0; j < n; j++) {
				if (j === i) { continue; }
				var f = M[j][i] / M[i][i]; if (!f) { continue; }
				for (k = i; k <= n; k++) { M[j][k] -= f * M[i][k]; }
			}
		}
		return M.map(function (r, q) { return r[n] / r[q]; });
	}
	function normaliser(pts) { // Hartley: centre at 0, mean distance √2
		var cx = 0, cy = 0, d = 0;
		pts.forEach(function (p) { cx += p[0]; cy += p[1]; }); cx /= pts.length; cy /= pts.length;
		pts.forEach(function (p) { d += Math.sqrt((p[0] - cx) * (p[0] - cx) + (p[1] - cy) * (p[1] - cy)); }); d /= pts.length;
		var s = d ? Math.SQRT2 / d : 1;
		return [[s, 0, -s * cx], [0, s, -s * cy], [0, 0, 1]];
	}
	function mul3(A, B) {
		var R = [[0, 0, 0], [0, 0, 0], [0, 0, 0]];
		for (var i = 0; i < 3; i++) { for (var j = 0; j < 3; j++) { for (var k = 0; k < 3; k++) { R[i][j] += A[i][k] * B[k][j]; } } }
		return R;
	}
	function inv3(m) {
		var a = m[0][0], b = m[0][1], c = m[0][2], d = m[1][0], e = m[1][1], f = m[1][2], g = m[2][0], hh = m[2][1], i = m[2][2];
		var A = e * i - f * hh, B = f * g - d * i, Cc = d * hh - e * g, det = a * A + b * B + c * Cc;
		if (Math.abs(det) < 1e-15) { return null; }
		return [[A / det, (c * hh - b * i) / det, (b * f - c * e) / det], [B / det, (a * i - c * g) / det, (c * d - a * f) / det], [Cc / det, (b * g - a * hh) / det, (a * e - b * d) / det]];
	}
	function apply3(H, x, y) {
		var z = H[2][0] * x + H[2][1] * y + H[2][2];
		return z ? [(H[0][0] * x + H[0][1] * y + H[0][2]) / z, (H[1][0] * x + H[1][1] * y + H[1][2]) / z, z] : null;
	}
	function perspective(pairs, w, h) {
		if (!pairs || pairs.length < 4) { return null; }
		var o = middle(pairs);
		var P = pairs.map(function (p) { return px(p, w, h); }), G = pairs.map(function (p) { return toXY([p.lat, p.lng], o); });
		var Np = normaliser(P), Ng = normaliser(G);
		var Pn = P.map(function (p) { return apply3(Np, p[0], p[1]); }), Gn = G.map(function (q) { return apply3(Ng, q[0], q[1]); });
		// least squares with h33 = 1 (normal equations of the 2n × 8 system)
		var AtA = [], Atb = [], r, a, b;
		for (r = 0; r < 8; r++) { AtA.push([0, 0, 0, 0, 0, 0, 0, 0]); Atb.push(0); }
		Pn.forEach(function (p, i) {
			var x = p[0], y = p[1], X = Gn[i][0], Y = Gn[i][1];
			[[x, y, 1, 0, 0, 0, -x * X, -y * X, X], [0, 0, 0, x, y, 1, -x * Y, -y * Y, Y]].forEach(function (row) {
				for (a = 0; a < 8; a++) { Atb[a] += row[a] * row[8]; for (b = 0; b < 8; b++) { AtA[a][b] += row[a] * row[b]; } }
			});
		});
		var v = solve(AtA, Atb); if (!v) { return null; }
		var Ngi = inv3(Ng); if (!Ngi) { return null; }
		var H = mul3(Ngi, mul3([[v[0], v[1], v[2]], [v[3], v[4], v[5]], [v[6], v[7], 1]], Np)), Hi = inv3(H);
		if (!Hi) { return null; }
		// the picture must not fold over itself: all four corners on the same side of the horizon line
		var zs = [[0, 0], [w, 0], [w, h], [0, h]].map(function (q) { var t = apply3(H, q[0], q[1]); return t ? t[2] : 0; });
		if (!(zs.every(function (z) { return z > 0; }) || zs.every(function (z) { return z < 0; }))) { return null; }
		var fwd = function (x, y) { var t = apply3(H, x, y); return t ? toLL([t[0], t[1]], o) : null; };
		var c0 = fwd(w / 2, h / 2), c1 = fwd(w / 2 + 10, h / 2), c2 = fwd(w / 2, h / 2 - 10);
		var rot = 0;
		if (c0 && c2) { var g0 = toXY(c2, c0); rot = Math.atan2(g0[0], g0[1]) * 180 / Math.PI; }
		return finish({
			kind: 'perspective', mPerPx: c0 && c1 ? dist(c0, c1) / 10 : 0, rotationDeg: rot,
			fwd: fwd,
			inv: function (lat, lng) { var g = toXY([lat, lng], o), t = apply3(Hi, g[0], g[1]); return t ? [t[0], t[1]] : [NaN, NaN]; }
		}, pairs, w, h);
	}

	/* the error of a fit at each intersection (metres) */
	function errorsOf(f, pairs, w, h) {
		return pairs.map(function (p) { var q = px(p, w, h), g = f.fwd(q[0], q[1]); return g && isFinite(g[0]) ? dist(g, [p.lat, p.lng]) : Infinity; });
	}
	function finish(f, pairs, w, h) {
		f.errors = errorsOf(f, pairs, w, h); f.n = pairs.length;
		f.rms = Math.sqrt(f.errors.reduce(function (s, e) { return s + e * e; }, 0) / f.errors.length);
		return f;
	}
	function rmsOf(list) { return list.length ? Math.sqrt(list.reduce(function (s, e) { return s + e * e; }, 0) / list.length) : Infinity; }
	function median(list) { var s = list.slice().sort(function (a, b) { return a - b; }), m = s.length >> 1; return s.length % 2 ? s[m] : (s[m - 1] + s[m]) / 2; }
	function choose(n, k) { var out = [], cur = []; (function go(from) { if (cur.length === k) { out.push(cur.slice()); return; } for (var i = from; i < n; i++) { cur.push(i); go(i + 1); cur.pop(); } })(0); return out; }

	/* Leave-one-out: the median error of predicting each kept intersection from the others. null = too few to test (a
	   flat fit on 2), Infinity = fails. */
	function looMedian(keep, maker, w, h) {
		var need = maker === perspective ? 5 : 3;
		if (keep.length < need) { return maker === flat && keep.length >= 2 ? null : Infinity; }
		var errs = keep.map(function (p, i) {
			var f = maker(keep.slice(0, i).concat(keep.slice(i + 1)), w, h), q = px(p, w, h), g = f && f.fwd(q[0], q[1]);
			return g && isFinite(g[0]) ? dist(g, [p.lat, p.lng]) : Infinity;
		});
		return median(errs);
	}
	/* perspective earns its place on a tie only when the flat fit is visibly off and it does far better (errors scaled up
	   for the parameters each spends: 8 against 4) */
	function perspectiveWins(pf, ff) {
		var ps = pf.inRms * Math.sqrt(2 * pf.inliers / Math.max(1, 2 * pf.inliers - 8));
		var fs = ff.inRms * Math.sqrt(2 * ff.inliers / Math.max(1, 2 * ff.inliers - 4));
		return fs > 15 && ps < fs * 0.6;
	}

	/* The best fit for a picture. pairs: [{x, y (0–1000), lat, lng}]; w, h: picture size (px).
	   opts: {perspective: true for a photo of paper, inlierM: 45}. Returns a fit with errors, inliers, inRms, loo,
	   mPerPx, rotationDeg, fwd(x, y) → [lat, lng] and inv(lat, lng) → [x, y]; or null. */
	function fitPhoto(pairs, w, h, opts) {
		opts = opts || {};
		var lim = opts.inlierM || 45, seen = {}, best = { flat: null, perspective: null };
		if (!pairs || pairs.length < 2) { return null; }
		function kept(errs) { return pairs.filter(function (p, i) { return errs[i] <= lim; }); }
		function score(f) { return f.loo == null ? f.inRms : f.loo; }
		function consider(f, maker) {
			if (!f || !isFinite(f.rms) || !(f.mPerPx > 0)) { return; }
			var inl = f.errors.filter(function (e) { return e <= lim; });
			f.inliers = inl.length; f.inRms = rmsOf(inl); // a misread intersection does not count against the fit
			if (f.kind === 'perspective' && f.inliers < 5) { return; }
			f.loo = looMedian(kept(f.errors), maker, w, h);
			if (f.loo === Infinity || (f.loo != null && f.loo > lim * 1.5)) { return; }
			/* perspective refits are ranked by the capped squared error (each intersection counts
			   its error², at most 45²), not by the count kept. Ranked by count, a homography that bent to keep a misread
			   intersection (8 kept at ±20 m) beat the right one (7 at ±6 m) on tilted photos; the cap still prefers 8 tight
			   to 7. Flat refits keep the count (ranked by cost, they dropped corners to look tight and did worse), and the
			   flat-against-perspective comparison below keeps counting intersections. */
			f.cost = f.errors.reduce(function (s, e) { var c = Math.min(e, lim); return s + c * c; }, 0);
			var b = best[f.kind];
			var better = f.kind === 'perspective'
				? (!b || f.cost < b.cost - 1e-9 || (Math.abs(f.cost - b.cost) <= 1e-9 && score(f) < score(b)))
				: (!b || f.inliers > b.inliers || (f.inliers === b.inliers && score(f) < score(b)));
			if (better) { best[f.kind] = f; }
		}
		function grow(seedErrors, maker) {
			var keep = kept(seedErrors), f = null;
			for (var round = 0; round < 4; round++) { // fit again until the kept set settles
				var key = (maker === perspective ? 'p' : 'f') + pairs.map(function (p) { return keep.indexOf(p) >= 0 ? 1 : 0; }).join('');
				if (seen[key]) { return; } seen[key] = 1;
				f = maker(keep, w, h); if (!f) { return; }
				var k2 = kept(errorsOf(f, pairs, w, h));
				if (k2.length <= keep.length) { break; } // explains nothing new (a shrinking set is not chased)
				keep = k2;
			}
			consider(finish({ kind: f.kind, fwd: f.fwd, inv: f.inv, mPerPx: f.mPerPx, rotationDeg: f.rotationDeg }, pairs, w, h), maker);
		}
		if (opts.perspective && pairs.length >= 5) {
			choose(pairs.length, 4).slice(0, 220).forEach(function (ix) {
				var f = perspective(ix.map(function (i) { return pairs[i]; }), w, h);
				if (f) { grow(errorsOf(f, pairs, w, h), perspective); }
			});
		}
		choose(pairs.length, 2).forEach(function (ix) {
			var f = flat(ix.map(function (i) { return pairs[i]; }), w, h);
			if (f) { grow(errorsOf(f, pairs, w, h), flat); }
		});
		var all = flat(pairs, w, h);
		if (all) { grow(all.errors, flat); }
		// the two kinds, compared once, best against best
		var F = best.flat, P = best.perspective, pick = F;
		if (P && (!F || P.inliers > F.inliers || (P.inliers === F.inliers && perspectiveWins(P, F)))) { pick = P; }
		if (pick === P && F && P.inliers === F.inliers + 1) {
			var keepP = kept(P.errors);
			var fair = pairs.every(function (p, i) {
				if (!(P.errors[i] <= lim) || F.errors[i] <= lim) { return true; } // only the ones perspective alone explains
				var f1 = perspective(keepP.filter(function (q) { return q !== p; }), w, h), q2 = px(p, w, h), g = f1 && f1.fwd(q2[0], q2[1]);
				return !!g && dist(g, [p.lat, p.lng]) <= lim;
			});
			/* on a mildly tilted photo the flat fit misses one CORNER intersection, and a corner is
			   exactly the one a perspective fit made without it predicts worst (it extrapolates), so the check above threw
			   away a right answer about one sheet in four (tests: "a mildly tilted photo"). With 7+ intersections a
			   homography is well pinned down and can't bend to one misread point, so there it also wins when it is clearly
			   better on the intersections both fits explain. With 5–6 (where bending is the real risk) the strict check
			   alone decides. */
			if (!fair && P.inliers >= 7) {
				var shared = pairs.filter(function (p, i) { return P.errors[i] <= lim && F.errors[i] <= lim; });
				var pe = shared.map(function (p) { return P.errors[pairs.indexOf(p)]; }), fe = shared.map(function (p) { return F.errors[pairs.indexOf(p)]; });
				fair = perspectiveWins({ inRms: rmsOf(pe), inliers: shared.length }, { inRms: rmsOf(fe), inliers: shared.length });
			}
			if (!fair) { pick = F; }
		}
		if (!pick && all) { all.inliers = all.errors.filter(function (e) { return e <= lim; }).length; all.inRms = all.rms; all.loo = null; pick = all; }
		if (pick) { pick.used = pick.inliers; }
		return pick;
	}
	/* an accepted fit that may still be off somewhere on the sheet, so the sheet is marked
	   "check the houses" and left out of Save all. A tilted photo with only 5–6 readable intersections (or one of them
	   misread) is often tens of metres off between them even when every kept one fits; in the probes this rule caught
	   every such case and flagged 6 % of good sheets with 8 intersections. */
	function looseFit(f) {
		if (!f) { return true; }
		return f.inRms > 15 || (f.loo != null && f.loo > 22.5) || f.inliers < 5 || (f.kind === 'perspective' && f.inliers <= 6);
	}
	/* Is the fit good enough to place houses with? {ok, why} */
	function acceptFit(f, n) {
		if (!f) { return { ok: false, why: 'the intersections did not line up' }; }
		var max = f.kind === 'perspective' ? 40 : 60;
		if (f.inliers < Math.min(3, n) || f.inRms > max) { return { ok: false, why: 'the intersections did not line up (error ' + Math.round(f.inRms) + ' m)' }; }
		if (!(f.mPerPx > 0.02 && f.mPerPx < 40)) { return { ok: false, why: 'the picture\'s scale looks wrong' }; }
		return { ok: true, why: '' };
	}

	/* ------------------------------------------------------------ the highlighter
	   Colour ranges in HSV: [hue from, hue to], least saturation, least brightness. Neon yellow-green highlighter reads at
	   hue 70–80° in a phone photo, so yellow runs to 98°. Red wraps past 360°. */
	var COLOURS = { orange: [8, 48, 0.42, 0.5], red: [340, 372, 0.45, 0.4], yellow: [45, 98, 0.35, 0.5], pink: [295, 350, 0.3, 0.55], green: [70, 165, 0.45, 0.35], blue: [195, 250, 0.5, 0.35] };
	function markPixels(rgba, w, h, colour) {
		var c = COLOURS[colour] || COLOURS.orange, out = new Uint8Array(w * h);
		for (var i = 0, n = w * h; i < n; i++) {
			var r = rgba[i * 4] / 255, g = rgba[i * 4 + 1] / 255, b = rgba[i * 4 + 2] / 255;
			var mx = Math.max(r, g, b), mn = Math.min(r, g, b), d = mx - mn;
			if (mx < c[3] || !mx || d / mx < c[2]) { continue; }
			var hue = 0;
			if (d > 0) {
				if (mx === r) { hue = 60 * (((g - b) / d) % 6); } else if (mx === g) { hue = 60 * ((b - r) / d + 2); } else { hue = 60 * ((r - g) / d + 4); }
				if (hue < 0) { hue += 360; }
			}
			if ((hue >= c[0] && hue <= c[1]) || (hue + 360 >= c[0] && hue + 360 <= c[1])) { out[i] = 1; }
		}
		return out;
	}
	/* summed-area table of a 0/1 grid, (w+1) × (h+1) */
	function integral(A, w, h) {
		var W1 = w + 1, I = new Int32Array(W1 * (h + 1));
		for (var y = 0; y < h; y++) { var row = 0; for (var x = 0; x < w; x++) { row += A[y * w + x]; I[(y + 1) * W1 + x + 1] = I[y * W1 + x + 1] + row; } }
		return I;
	}
	function boxSum(I, w, h, x, y, r) {
		var W1 = w + 1, x0 = Math.max(0, x - r), y0 = Math.max(0, y - r), x1 = Math.min(w, x + r + 1), y1 = Math.min(h, y + r + 1);
		return I[y1 * W1 + x1] - I[y0 * W1 + x1] - I[y1 * W1 + x0] + I[y0 * W1 + x0];
	}
	/* The stroke width measured on the picture: the median, over marked pixels, of the shorter of its horizontal and
	   vertical run. */
	function strokeWidth(m, w, h) {
		var run = new Uint16Array(w * h), widths = [], x, y, s, t;
		for (y = 0; y < h; y++) { for (x = 0; x < w; x++) { if (!m[y * w + x]) { continue; } s = x; while (x < w && m[y * w + x]) { x++; } for (t = s; t < x; t++) { run[y * w + t] = x - s; } } }
		for (x = 0; x < w; x++) { for (y = 0; y < h; y++) { if (!m[y * w + x]) { continue; } s = y; while (y < h && m[y * w + x]) { y++; } for (t = s; t < y; t++) { widths.push(Math.min(run[t * w + x], y - s)); } } }
		if (!widths.length) { return 0; }
		widths.sort(function (a, b) { return a - b; });
		return widths[widths.length >> 1];
	}
	/* A sticky note or a coloured mug is wider than any stroke: a marked pixel goes when it lies in a fully marked square
	   three strokes wide. Two summed-area passes, so the cost does not grow with the patch. A size in metres would be wrong:
	   on a zoomed-out sheet one highlighter stroke is 50–70 m wide. Returns how many pixels went. */
	function stripPatches(m, w, h) {
		var sw = strokeWidth(m, w, h); if (!sw) { return 0; }
		var r = Math.max(3, Math.round(1.5 * sw)), full = (2 * r + 1) * (2 * r + 1), I = integral(m, w, h), core = new Uint8Array(w * h), any = false, x, y, gone = 0;
		for (y = r; y < h - r; y++) { for (x = r; x < w - r; x++) { if (m[y * w + x] && boxSum(I, w, h, x, y, r) === full) { core[y * w + x] = 1; any = true; } } }
		if (!any) { return 0; }
		var Ic = integral(core, w, h);
		for (y = 0; y < h; y++) { for (x = 0; x < w; x++) { if (m[y * w + x] && boxSum(Ic, w, h, x, y, r) > 0) { m[y * w + x] = 0; gone++; } } }
		return gone;
	}
	/* The marked area's box from the 1st to the 99th percentile, so a few stray pixels don't stretch it. */
	function markedBox(m, w, h) {
		var xs = [], ys = [];
		for (var i = 0; i < w * h; i++) { if (m[i]) { var x = i % w; xs.push(x); ys.push((i - x) / w); } }
		if (!xs.length) { return null; }
		xs.sort(function (a, b) { return a - b; }); ys.sort(function (a, b) { return a - b; });
		var lo = Math.floor((xs.length - 1) * 0.01), hi = Math.ceil((xs.length - 1) * 0.99);
		return [xs[lo], ys[lo], xs[hi] + 1, ys[hi] + 1];
	}
	/* The mask. rgba: the picture's pixels at w × h (already scaled down to at most 640 wide); scale: those pixels per
	   picture pixel. opts: {radPx (picture px; else 1.2 % of the width), lineOnly, clip [x0, y0, x1, y1] (picture px),
	   patches}. hit(x, y) takes picture pixels. */
	function buildMask(rgba, w, h, scale, colour, opts) {
		opts = opts || {};
		var m = markPixels(rgba, w, h, colour), x, y, i;
		if (opts.patches) { stripPatches(m, w, h); }
		if (opts.clip) { // only the sheet counts
			var cx0 = Math.floor(opts.clip[0] * scale), cy0 = Math.floor(opts.clip[1] * scale), cx1 = Math.ceil(opts.clip[2] * scale), cy1 = Math.ceil(opts.clip[3] * scale);
			for (i = 0; i < w * h; i++) { if (m[i]) { x = i % w; y = (i - x) / w; if (x < cx0 || x > cx1 || y < cy0 || y > cy1) { m[i] = 0; } } }
		}
		var box = markedBox(m, w, h);
		var rad = opts.radPx ? Math.max(2, Math.min(40, Math.round(opts.radPx * scale))) : Math.max(2, Math.round(w * 0.012));
		// widen every marked pixel to a square of side 2·rad+1 (a summed-area test, not a loop per pixel)
		var sw = strokeWidth(m, w, h), I = integral(m, w, h), near = new Uint8Array(w * h), lineCells = 0;
		for (y = 0; y < h; y++) { for (x = 0; x < w; x++) { if (boxSum(I, w, h, x, y, rad) > 0) { near[y * w + x] = 1; lineCells++; } } }
		// flood the outside from the border; what is neither line nor reachable is enclosed
		var seen = new Uint8Array(w * h), stack = [], outside = 0;
		for (x = 0; x < w; x++) { stack.push(x, (h - 1) * w + x); }
		for (y = 0; y < h; y++) { stack.push(y * w, y * w + w - 1); }
		while (stack.length) {
			i = stack.pop();
			if (seen[i] || near[i]) { continue; }
			seen[i] = 1; outside++;
			x = i % w;
			if (x > 0) { stack.push(i - 1); } if (x < w - 1) { stack.push(i + 1); }
			if (i >= w) { stack.push(i - w); } if (i < w * (h - 1)) { stack.push(i + w); }
		}
		// a highlighted paper map means the streets, not the blocks they ring; and when the whole border is the marking
		// colour (the flood never started), nothing can be called enclosed
		var lineOnly = !!opts.lineOnly || !outside;
		var interior = lineOnly ? 0 : w * h - lineCells - outside;
		return {
			w: w, h: h, scale: scale, lineCells: lineCells, interiorCells: interior, lineOnly: lineOnly, radPx: rad / scale, strokePx: sw / scale, // picture px
			markedBox: box ? [box[0] / scale, box[1] / scale, box[2] / scale, box[3] / scale] : null,
			hit: function (px0, py0) {
				if (!isFinite(px0) || !isFinite(py0)) { return false; }
				var qx = Math.round(px0 * scale), qy = Math.round(py0 * scale);
				if (qx < 0 || qy < 0 || qx >= w || qy >= h) { return false; }
				var k = qy * w + qx;
				return !!near[k] || (!lineOnly && !seen[k]);
			}
		};
	}
	/* The sheet, for the clip: the spread of EVERY intersection the reader found (not only those the map placed), plus
	   a third of that spread (at least 8 % of the picture) on each side. Picture px. */
	function sheetBox(anchors, w, h) {
		var xs = anchors.map(function (a) { return a.x / 1000 * w; }), ys = anchors.map(function (a) { return a.y / 1000 * h; });
		if (!xs.length) { return null; }
		var x0 = Math.min.apply(null, xs), x1 = Math.max.apply(null, xs), y0 = Math.min.apply(null, ys), y1 = Math.max.apply(null, ys);
		var mx = Math.max((x1 - x0) / 3, w * 0.08), my = Math.max((y1 - y0) / 3, h * 0.08);
		return [x0 - mx, y0 - my, x1 + mx, y1 + my];
	}

	/* ------------------------------------------------------------ which houses
	   How much of each road segment the marking covers (sampled every 5 m). segments: [{id, path: [[lat, lng]…]}];
	   inv: the fit's ground → picture; hit: the mask's test. */
	/* run (optional, filled in): {segId: metres} of the longest unbroken marked stretch along each segment */
	function segmentCover(segments, inv, hit, run) {
		var out = {};
		(segments || []).forEach(function (s) {
			var p = s.path || [], tot = 0, on = 0, cur = 0, best = 0;
			for (var i = 1; i < p.length; i++) {
				var d = dist(p[i - 1], p[i]), steps = Math.max(1, Math.ceil(d / 5));
				for (var k = 0; k < steps; k++) {
					var f = (k + 0.5) / steps, q = inv(p[i - 1][0] + (p[i][0] - p[i - 1][0]) * f, p[i - 1][1] + (p[i][1] - p[i - 1][1]) * f);
					tot++;
					if (hit(q[0], q[1])) { on++; cur += d / steps; if (cur > best) { best = cur; } } else { cur = 0; }
				}
			}
			out[s.id] = tot ? on / tot : 0;
			if (run) { run[s.id] = best; }
		});
		return out;
	}
	/* Road segments with an end no other segment meets (within 3 m): dead ends — a court, a cul-de-sac. {segId: true} */
	function deadEnds(segments) {
		var ends = [], out = {};
		(segments || []).forEach(function (s) { var p = s.path || []; if (p.length > 1) { ends.push({ id: s.id, p: p[0] }, { id: s.id, p: p[p.length - 1] }); } });
		ends.forEach(function (e) {
			var met = ends.some(function (f) { return f.id !== e.id && Math.abs(f.p[0] - e.p[0]) < 0.00004 && Math.abs(f.p[1] - e.p[1]) < 0.00005 && dist(f.p, e.p) < 3; });
			if (!met) { out[e.id] = true; }
		});
		return out;
	}
	/* Mark each address .selected. A highlighter stroke runs a little past a corner, so the first houses of the cross
	   street are inside the marking too. On paper a house counts when it is inside the marking AND its street is one the
	   reader named, or the marking covers half or more of its road segment (a court the reader missed). A screenshot keeps
	   the plain rule: inside the marking.
	   Ours: the other app's rule let a NAMED street's next block in — 2nd Ave walked from B St south, the first
	   houses of 2nd Ave north of B St are inside the B St stroke and 2nd Ave is named. So a named street's house also needs
	   its own block marked past the corner: half of it, or an unbroken stretch longer than the corner spill (spillM: the
	   widening plus half a stroke). Without run/spillM, or a house with no segment, the rule is as before.
	   The half-covered rule takes the same care: its marked stretch must be longer than the spill too, unless the
	   segment is a dead end (dead: {segId: true}, from deadEnds) marked to its end.
	   o: {inv, hit, paper, cover ({segId: share}), run ({segId: metres}), spillM, dead, named: {exact: {key: 1}, core:
	   {core: 1}}, coreOf(key)} */
	function selectHouses(addresses, o) {
		var n = 0, exact = (o.named && o.named.exact) || {}, core = (o.named && o.named.core) || {};
		var anyNamed = Object.keys(exact).length + Object.keys(core).length > 0, cover = o.cover || {};
		(addresses || []).forEach(function (a) {
			if (a.lat == null) { a.selected = false; return; }
			var p = o.inv(a.lat, a.lng), inMark = o.hit(p[0], p[1]);
			var known = a.seg != null && cover[a.seg] != null, along = known ? cover[a.seg] >= 0.5 : true;
			// …and the half must be more than the corner spill: a short block BETWEEN two highlighted streets is half
			// covered by the two spills alone. A dead end marked to its end is a court the reader missed.
			if (along && known && o.run && o.spillM > 0 && o.run[a.seg] != null && !(o.run[a.seg] > o.spillM)) { along = !!(o.dead && o.dead[a.seg] && cover[a.seg] >= 0.9); }
			var key = a.street_key || '';
			var named = !!exact[key] || !!core[o.coreOf ? o.coreOf(key || a.street) : key];
			if (named && known && !along && o.run && o.spillM > 0 && o.run[a.seg] != null) { named = o.run[a.seg] > o.spillM; } // not just a corner spill
			a.selected = inMark && (!o.paper || !anyNamed || named || along);
			if (a.selected) { n++; }
		});
		return n;
	}

	/* ------------------------------------------------------------ the ZIP (the first real sheet came out under a ZIP
	   remembered from the route before, in another city, so none of its intersections were found)
	   A ZIP the reader is sure of replaces any remembered one. A guess it is unsure of is not used on a stack of sheets
	   (the map is searched instead); the one-photo form takes it only when it has nothing else. ZIP+4 is cut to five.
	   Returns {zip, note}. */
	function zipFor(current, guess, confidence, isSheet) {
		var g = String(guess || '').replace(/\D/g, '').slice(0, 5), cur = String(current || '').replace(/\D/g, '').slice(0, 5);
		var sure = !!confidence && confidence !== 'low';
		if (g.length === 5 && sure && g !== cur) { return { zip: g, note: 'ZIP ' + g + ' read off the map' + (cur ? ' (not ' + cur + ')' : '') }; }
		if (g.length === 5 && !cur && !isSheet && guess) { return { zip: g, note: '' }; }
		return { zip: cur, note: '' };
	}

	/* ------------------------------------------------------------ notes that stay small
	   Repeated lines merge ("ZIP 00002 had no match for 5 streets — used 00001"); duplicates go. Returns the lines. */
	function mergeNotes(list) {
		var lines = [], runs = {};
		(list || []).forEach(function (n) {
			var s = String(n == null ? '' : n).trim(); if (!s) { return; }
			var m = /^(.+?): ZIP (\d+) had no match; used (\d+)\.?$/.exec(s);
			if (m) {
				var k = m[2] + '>' + m[3];
				if (!runs[k]) { runs[k] = { from: m[2], to: m[3], n: 0, at: lines.length }; lines.push(null); }
				runs[k].n++; return;
			}
			if (lines.indexOf(s) < 0) { lines.push(s); }
		});
		Object.keys(runs).forEach(function (k) { var r = runs[k]; lines[r.at] = 'ZIP ' + r.from + ' had no match' + (r.n > 1 ? ' for ' + r.n + ' streets' : '') + ' — used ' + r.to + '.'; });
		return lines.filter(Boolean);
	}

	/* ------------------------------------------------------------ what a sheet needs before it is saved
	   s: {status, houses (count), date, alignFailed (why, or ''), aligned (bool), paper (bool), loose ({rms, used} when
	   the fit is loose), confidence, zipNote, dateFromSheet}. level: 'block' (can't save yet), 'check' (look first; left out of Save all), 'info'. */
	function sheetFlags(s) {
		var out = [];
		if (s.status !== 'ready') { return out; }
		if (!s.houses) { out.push({ level: 'block', text: 'No houses picked — tap Fix.' }); }
		if (!s.date) { out.push({ level: 'block', text: 'When was it walked? Add the date.' }); }
		if (s.alignFailed) { out.push({ level: 'check', text: 'Placed by the street names only (the picture could not be lined up: ' + s.alignFailed + ') — check the houses.' }); }
		else if (!s.aligned && s.paper) { out.push({ level: 'check', text: 'Placed by the street names only — check the houses.' }); }
		else if (s.loose) { out.push({ level: 'check', text: 'Lined up loosely' + (s.loose.rms != null ? ' (±' + Math.round(s.loose.rms) + ' m on ' + s.loose.used + ' intersections)' : '') + ' — check the houses.' }); }
		if (s.confidence === 'low') { out.push({ level: 'check', text: 'The reader was unsure about this sheet.' }); }
		if (s.houses > 600) { out.push({ level: 'check', text: s.houses + ' houses is a lot for one sheet — check it.' }); }
		if (s.zipNote) { out.push({ level: 'info', text: s.zipNote + '.' }); }
		if (s.dateFromSheet) { out.push({ level: 'info', text: 'Date read off the sheet.' }); }
		return out;
	}

	root.ZDH_PAPER = {
		toXY: toXY, toLL: toLL, dist: dist,
		flat: flat, perspective: perspective, fitPhoto: fitPhoto, acceptFit: acceptFit, looseFit: looseFit, perspectiveWins: perspectiveWins, looMedian: looMedian,
		COLOURS: COLOURS, markPixels: markPixels, strokeWidth: strokeWidth, stripPatches: stripPatches, markedBox: markedBox, buildMask: buildMask, sheetBox: sheetBox,
		segmentCover: segmentCover, deadEnds: deadEnds, selectHouses: selectHouses, zipFor: zipFor, mergeNotes: mergeNotes, sheetFlags: sheetFlags
	};
})(typeof window !== 'undefined' ? window : this);
