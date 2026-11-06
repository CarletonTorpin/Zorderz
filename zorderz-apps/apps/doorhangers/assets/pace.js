/* Doorhangers — walking or driving, the walk view's rule as a pure file for another app (the Route Planner's survey
   goes voice-only above walking speed by this same rule: one rule, never a second).

   This is app.js's paceLimits() and paceUpdate() without the walk view's extras (no "I'm driving" override, no log, no
   track backdating): the larger of the phone's own speed (median of the last 10 s) and the distance over a 12-25 s
   window decides, both ways; a jump over jumpM in under 90 s is driving; a loose fix says nothing. The limits are
   Doorhangers' settings (Zorderz -> Doorhangers -> Walking vs. driving; zdh_pace_limits()).

   app.js keeps its own copy (it is the walk view's, with the override woven in). The two must agree on the same
   drives: a change to one is a change to the other.

   ZDH_PACE.limits(raw)          -> {driveMph, driveS, walkMph, walkS, accM, jumpM} with the settings' defaults
   ZDH_PACE.gpsSpeed(position)   -> m/s or null (iOS reports -1, desktops null)
   ZDH_PACE.detector(limits)     -> {update(lat, lng, tMs, accM, speedMps|null) -> 'walk'|'drive', state(), mph(), reset()} */
(function (root) {
	'use strict';
	function distM(a, b) {
		var x = (b[1] - a[1]) * Math.cos((a[0] + b[0]) / 2 * Math.PI / 180) * 111320;
		var y = (b[0] - a[0]) * 110540;
		return Math.sqrt(x * x + y * y);
	}
	function median(a) { var b = a.slice().sort(function (x, y) { return x - y; }), m = b.length >> 1; return b.length % 2 ? b[m] : (b[m - 1] + b[m]) / 2; }
	function limits(p) {
		p = (p && typeof p === 'object') ? p : {};
		function n(v, d) { v = parseFloat(v); return isFinite(v) && v > 0 ? v : d; }
		var L0 = { driveMph: n(p.driveMph, 6), driveS: n(p.driveS, 15), walkMph: n(p.walkMph, 4.5), walkS: n(p.walkS, 15), accM: n(p.accM, 15), jumpM: n(p.jumpM, 300) };
		if (L0.walkMph >= L0.driveMph) { L0.walkMph = Math.max(1, L0.driveMph - 0.5); } // never a band where both are true
		return L0;
	}
	function gpsSpeed(pos) { var s = pos && pos.coords ? pos.coords.speed : null; return (s != null && isFinite(s) && s >= 0) ? +s : null; }
	function detector(lim) {
		var PL = limits(lim), P;
		function reset() { P = { state: 'walk', win: [], spd: [], last: null, fastSince: null, slowSince: null, mph: 0 }; }
		reset();
		function update(lat, lng, t, acc, speed) {
			if (acc > PL.accM) { return P.state; }
			if (P.last && t - P.last[2] >= 45000) { P.win = []; P.spd = []; P.fastSince = null; P.slowSince = null; }
			if (P.last && t - P.last[2] < 1000) { return P.state; }
			if (P.last && t - P.last[2] < 90000 && distM([P.last[0], P.last[1]], [lat, lng]) > PL.jumpM) { P.state = 'drive'; P.slowSince = null; }
			P.last = [lat, lng, t];
			P.win.push([t, lat, lng]);
			P.win = P.win.filter(function (w) { return t - w[0] <= 25000; });
			if (speed != null) { P.spd.push([t, speed]); }
			P.spd = P.spd.filter(function (s) { return t - s[0] <= 10000; });
			var oldest = P.win[0], span = (t - oldest[0]) / 1000;
			var track = span >= 12 ? distM([oldest[1], oldest[2]], [lat, lng]) / span * 2.23694 : null;
			var gps = P.spd.length >= 2 ? median(P.spd.map(function (s) { return s[1]; })) * 2.23694 : null;
			if (track == null && gps == null) { return P.state; }
			var fast = Math.max(gps == null ? 0 : gps, track == null ? 0 : track);
			P.mph = fast;
			if (P.state === 'walk') {
				if (fast > PL.driveMph) { if (!P.fastSince) { P.fastSince = t; } if (t - P.fastSince >= PL.driveS * 1000) { P.state = 'drive'; P.slowSince = null; } }
				else { P.fastSince = null; }
			} else if (track == null) {
				// leaving "driving" waits for the 12 s window (just after a GPS gap the phone's speed alone decides nothing)
			} else if (fast < PL.walkMph) {
				if (!P.slowSince) { P.slowSince = t; }
				if (t - P.slowSince >= PL.walkS * 1000) { P.state = 'walk'; P.fastSince = null; P.slowSince = null; }
			} else { P.slowSince = null; }
			return P.state;
		}
		return { update: update, state: function () { return P.state; }, mph: function () { return P.mph; }, reset: reset, limits: PL };
	}
	root.ZDH_PACE = { limits: limits, gpsSpeed: gpsSpeed, detector: detector, distM: distM };
})(typeof window !== 'undefined' ? window : this);
