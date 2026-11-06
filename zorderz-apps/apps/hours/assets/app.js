/* Hours — the app in its card. Vanilla JS on the platform's kit of parts (ZDZ.ui: rest, sheet, busy, toast, loadingHtml,
   personChip). Starts when its root exists: at once, on the theme's `zdz_widgets_rendered`, or at DOMContentLoaded.
   Every number shown comes from the server (<namespace>/hours/); this file only draws it and sends what the person
   pressed. Who may see or change what, and whose hours a reading is, are decided on the server: a reading's stretch is
   added only for the person the server attributed it to, or the person someone chose for a review item here. Nothing
   here ever picks a finish time: the person types it. */
(function () {
	'use strict';

	var C = window.ZHR_CONFIG || {};
	var DAY_NAMES = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
	var MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
	var PAY = C.payPeriod ? [['this_pay_period', 'This pay period'], ['last_pay_period', 'Last pay period']] : [];
	var PERIODS = [['today', 'Today'], ['this_week', 'This week'], ['last_week', 'Last week']].concat(PAY, [['this_month', 'This month'], ['last_month', 'Last month'], ['custom', 'Pick dates']]);
	// what a person outside the admin tier chooses between: their week, the one before, the pay period, and the month
	var WORKER_PERIODS = [['this_week', 'This week'], ['last_week', 'Last week']].concat(PAY, [['this_month', 'This month'], ['last_month', 'Last month']]);
	var SOURCE = { timer: 'Clock', text: 'Typed or said', photo: 'From a photo', manual: 'Exact times', doorhangers: 'Door hanging', admin: 'Added by an admin' };

	function kit() { return (window.ZDZ && window.ZDZ.ui) ? window.ZDZ.ui : null; }
	function api(path, opts) {
		var k = kit();
		if (!k) { return Promise.reject(new Error('Hours needs a newer Zorderz theme. Reload the app.')); }
		path = (C.restPath || 'zorderz/v1/hours/') + path;
		// a site on plain links serves the API at ?rest_route=/…, so the route's own query joins with & (one ? only)
		if (window.zdzData && /[?&]rest_route=/.test(String(window.zdzData.apiUrl || ''))) { path = path.replace('?', '&'); }
		return k.rest(path, opts || {});
	}
	function say(msg, kind) { var k = kit(); if (k) { k.toast(msg, kind ? { kind: kind } : {}); } }
	function sayErr(err, retry) { var k = kit(); if (k) { k.toastError(err, retry); } }
	function busy(btn, work) { var k = kit(); return k ? k.busy(btn, work) : Promise.resolve(work()); }
	function loadingHtml(label) { var k = kit(); return k && k.loadingHtml ? k.loadingHtml(label) : '<p>' + esc(label) + '</p>'; }

	/* ------------------------------------------------------------------ words and numbers */

	function esc(s) {
		return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; });
	}
	function fmt(min) {
		min = Math.max(0, Math.round(min || 0));
		var h = Math.floor(min / 60), m = min % 60;
		if (!h) { return m + ' min'; }
		return h + ' h' + (m ? ' ' + m + ' min' : '');
	}
	function t12(hm) {
		if (!hm) { return ''; }
		var p = String(hm).split(':'), h = +p[0], m = p[1];
		var ap = h >= 12 ? 'PM' : 'AM';
		h = h % 12; if (h === 0) { h = 12; }
		return h + ':' + m + ' ' + ap;
	}
	function parseDate(iso) { var p = String(iso).split('-'); return new Date(+p[0], +p[1] - 1, +p[2], 12); }
	function isoOf(d) { return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'); }
	function dayLabel(iso) {
		var d = parseDate(iso);
		return DAY_NAMES[d.getDay()] + ' ' + MONTHS[d.getMonth()] + ' ' + d.getDate();
	}
	function addDays(iso, n) { var d = parseDate(iso); d.setDate(d.getDate() + n); return isoOf(d); }
	function mapsLink(p) {
		if (!p) { return ''; }
		// the platform's one map-link builder (the business's map provider); a plain search link when it is not loaded
		var at = p.lat + ',' + p.lng;
		var url = typeof window.zdzMapsUrl === 'function' ? window.zdzMapsUrl(at) : 'https://www.google.com/maps/search/?api=1&query=' + encodeURIComponent(at);
		return '<a class="zhr-place" href="' + esc(url) + '" target="_blank" rel="noopener">map' + (p.acc ? ' (±' + p.acc + ' m)' : '') + '</a>';
	}
	function personIsAdmin(id) {
		var list = C.people || [];
		for (var i = 0; i < list.length; i++) { if (+list[i].id === +id) { return !!list[i].admin; } }
		return false;
	}
	/** The server's code for a refusal (WP_Error's code), from the kit's RequestError. */
	function errCode(e) { return e && e.body && typeof e.body === 'object' ? String(e.body.code || '') : ''; }
	/** 'Yesterday', 'Today', or 'Sat Mar 7' for a work day. */
	function dayWord(iso) {
		var t = (S.data && S.data.today) || C.today;
		return iso === t ? 'Today' : (iso === addDays(t, -1) ? 'Yesterday' : dayLabel(iso));
	}
	/** "Sat Mar 7's shift" / "Yesterday's shift". */
	function shiftOf(iso) { return dayWord(iso) + '\'s shift'; }
	function personName(id) {
		var list = C.people || [];
		for (var i = 0; i < list.length; i++) { if (+list[i].id === +id) { return list[i].name; } }
		return '';
	}
	function chip(id) {
		var k = kit();
		if (!k || !k.personChip || !id) { return ''; }
		try { var c = k.personChip(+id, { size: 'sm' }); return c && c.outerHTML ? c.outerHTML + ' ' : ''; } catch (e) { return ''; }
	}
	function speechLang() { return document.documentElement.lang || navigator.language || 'en-US'; }

	/* ------------------------------------------------------------------ state */

	var root = null;
	var S = { who: 'me', period: 'this_week', from: '', to: '', data: null, team: null, open: {}, dayCache: {}, loadedAt: 0, myRun: null, loading: false, preview: false, seq: 0 };
	var isAdmin = !!C.isAdmin;

	function viewingSelf() { return S.who === 'me' || +S.who === +C.userId; }
	/**
	 * The person's own screen: everyone outside the admin tier, an admin's "See it as they see it", and a crew lead's
	 * reading of their crew. Its numbers come from the server already without the standard hours, evidence, flags, places
	 * or the day's timeline.
	 */
	function workerView() { return !isAdmin || S.preview; }
	/** Someone else's hours drawn as they see them (an admin's preview, a crew lead's reading): nothing on it acts. */
	function asThem() { return !viewingSelf() && (S.preview || !isAdmin); }
	/** Words for the person the screen is about, as they would read it ("you" when it is theirs, or as they see it). */
	function theirOwn() { return viewingSelf() || asThem(); }
	function userParam() { return (viewingSelf() ? '' : '&user=' + encodeURIComponent(S.who)) + (S.preview ? '&view=worker' : ''); }
	function firstName(n) { return String(n || '').split(' ')[0]; }
	function periodQuery() {
		var q = 'period=' + encodeURIComponent(S.period);
		if (S.period === 'custom') { q += '&from=' + encodeURIComponent(S.from) + '&to=' + encodeURIComponent(S.to); }
		return q;
	}
	/** Minutes of a running entry now: what the server said plus the time since it said it (no clock-zone guessing). */
	function liveMin(e) {
		if (!e || !e.running) { return e ? e.minutes : 0; }
		return e.minutes + Math.floor((Date.now() - S.loadedAt) / 60000);
	}

	/* ------------------------------------------------------------------ the frame */

	function frame() {
		var who = '';
		var others = (C.people || []).filter(function (p) { return +p.id !== +C.userId; });
		if (isAdmin || others.length) {
			who = '<label class="zhr-who"><span>Showing</span> <select id="zhr-who">'
				+ '<option value="me">My hours</option>' + (isAdmin ? '<option value="all">Everyone</option>' : '')
				+ others.map(function (p) { return '<option value="' + p.id + '">' + esc(p.name) + '</option>'; }).join('')
				+ '</select></label>';
		}
		if (isAdmin) {
			who += '<button type="button" class="zdz-btn zdz-btn--quiet zdz-btn--sm" id="zhr-settings" aria-label="Standard hours, rules and settings">Settings</button>'
				+ '<button type="button" class="zdz-btn zdz-btn--quiet zdz-btn--sm" id="zhr-preview" aria-pressed="false"></button>';
		}
		root.innerHTML = ''
			+ (who ? '<div class="zhr-head"><div class="zhr-head-tools">' + who + '</div></div>' : '')
			+ '<p class="zhr-preview-note" id="zhr-preview-note" hidden></p>'
			+ '<section class="zhr-clock" id="zhr-clock" aria-live="polite"></section>'
			+ '<section class="zhr-compose" id="zhr-compose" hidden></section>'
			+ '<div class="zhr-periods-wrap" id="zhr-periods-wrap"><nav class="zhr-periods" id="zhr-periods" aria-label="Period"></nav></div>'
			+ '<div class="zhr-custom" id="zhr-custom" hidden><label>From <input type="date" id="zhr-from"></label><label>To <input type="date" id="zhr-to"></label><button type="button" class="zdz-btn zdz-btn--secondary zdz-btn--sm" id="zhr-go">Show</button></div>'
			+ '<section class="zhr-body" id="zhr-body">' + loadingHtml('Loading your hours…') + '</section>'
			+ '<footer class="zhr-foot" id="zhr-foot"></footer>';
		drawPeriods();
		var w = root.querySelector('#zhr-who');
		if (w) {
			w.addEventListener('change', function () { S.who = w.value; S.open = {}; S.dayCache = {}; load(); });
		}
		var g = root.querySelector('#zhr-settings');
		if (g) { g.addEventListener('click', openSettings); }
		var pv = root.querySelector('#zhr-preview');
		if (pv) {
			pv.addEventListener('click', function () {
				S.preview = !S.preview; S.open = {}; S.dayCache = {};
				if (S.preview && WORKER_PERIODS.map(function (p) { return p[0]; }).concat(['custom']).indexOf(S.period) === -1) { S.period = 'this_week'; }
				load();
			});
		}
		var pw = root.querySelector('#zhr-periods');
		pw.addEventListener('scroll', periodsEdge, { passive: true });
		window.addEventListener('resize', periodsEdge);
		root.querySelector('#zhr-go').addEventListener('click', function () {
			var f = root.querySelector('#zhr-from').value, t = root.querySelector('#zhr-to').value;
			if (!f || !t) { say('Pick both dates.'); return; }
			S.period = 'custom'; S.from = f; S.to = t; load();
		});
	}

	function drawPeriods() {
		var nav = root.querySelector('#zhr-periods');
		nav.innerHTML = '<button type="button" class="zdz-btn zdz-btn--quiet zdz-btn--sm zhr-step" data-step="-1" aria-label="Earlier">‹</button>'
			+ (workerView() ? WORKER_PERIODS : PERIODS).map(function (p) {
				// a dot on a chip whose period holds a shift with no finish (or a finish set later)
				var m = S.who !== 'all' && S.data && S.data.marks ? S.data.marks[p[0]] : null, dot = '';
				if (m && m.open) { dot = '<span class="zhr-dot is-open" aria-hidden="true"></span><span class="zhr-vh">, ' + m.open + ' with no finish time</span>'; }
				else if (m && m.set) { dot = '<span class="zhr-dot is-set" aria-hidden="true"></span><span class="zhr-vh">, ' + m.set + ' finish time' + (m.set === 1 ? '' : 's') + ' set later</span>'; }
				return '<button type="button" class="zdz-btn zdz-btn--sm zhr-period ' + (S.period === p[0] ? 'zdz-btn--primary' : 'zdz-btn--secondary') + '" data-p="' + p[0] + '" aria-pressed="' + (S.period === p[0]) + '">' + p[1] + dot + '</button>';
			}).join('')
			+ '<button type="button" class="zdz-btn zdz-btn--quiet zdz-btn--sm zhr-step" data-step="1" aria-label="Later">›</button>';
		Array.prototype.forEach.call(nav.querySelectorAll('.zhr-period'), function (b) {
			b.addEventListener('click', function () {
				var p = b.getAttribute('data-p');
				var box = root.querySelector('#zhr-custom');
				if (p === 'custom') {
					box.hidden = false;
					root.querySelector('#zhr-from').value = S.data ? S.data.from : C.today;
					root.querySelector('#zhr-to').value = S.data ? S.data.to : C.today;
					return;
				}
				box.hidden = true;
				S.period = p; load();
			});
		});
		Array.prototype.forEach.call(nav.querySelectorAll('.zhr-step'), function (b) {
			b.addEventListener('click', function () { step(+b.getAttribute('data-step')); });
		});
		var on = nav.querySelector('.zdz-btn--primary');
		if (on && nav.scrollWidth > nav.clientWidth) { nav.scrollLeft = Math.max(0, on.offsetLeft - 48); }
		periodsEdge();
	}

	/** The chips row scrolls sideways on a phone: a fade on the side that has more says so. */
	function periodsEdge() {
		var nav = root && root.querySelector('#zhr-periods'), wrap = root && root.querySelector('#zhr-periods-wrap');
		if (!nav || !wrap) { return; }
		var max = nav.scrollWidth - nav.clientWidth;
		wrap.classList.toggle('is-more-right', max > 2 && nav.scrollLeft < max - 2);
		wrap.classList.toggle('is-more-left', max > 2 && nav.scrollLeft > 2);
	}

	/** Earlier / later by the period's own length (a whole month moves a month). */
	function step(dir) {
		var cur = S.data || S.team;
		if (!cur) { return; }
		var from = cur.from, to = cur.to, f, t;
		var a = parseDate(from), b = parseDate(to);
		var wholeMonth = a.getDate() === 1 && addDays(to, 1).slice(8) === '01' && from.slice(0, 7) === to.slice(0, 7);
		if (wholeMonth) {
			var m = new Date(a.getFullYear(), a.getMonth() + dir, 1, 12);
			f = isoOf(m); t = isoOf(new Date(m.getFullYear(), m.getMonth() + 1, 0, 12));
		} else {
			var len = Math.round((b - a) / 86400000) + 1;
			f = addDays(from, dir * len); t = addDays(to, dir * len);
		}
		S.period = 'custom'; S.from = f; S.to = t;
		root.querySelector('#zhr-custom').hidden = true;
		load();
	}

	/* ------------------------------------------------------------------ loading */

	/** The admin's "See it as they see it" switch and its note; a crew lead's reading note. */
	function chrome() {
		var pv = root.querySelector('#zhr-preview'), note = root.querySelector('#zhr-preview-note');
		// another admin's own screen is the full view, so there is nothing else to see it as
		var noPreview = S.who === 'all' || (!viewingSelf() && personIsAdmin(S.who));
		if (noPreview) { S.preview = false; }
		if (pv) {
			pv.hidden = noPreview;
			var them = viewingSelf() ? 'a person' : (firstName(personName(S.who)) || 'they');
			pv.textContent = S.preview ? 'Back to the full view' : 'See it as ' + them + ' sees it';
			pv.setAttribute('aria-pressed', String(S.preview));
		}
		if (note) {
			var crew = !isAdmin && !viewingSelf();
			note.hidden = !S.preview && !crew;
			note.textContent = crew ? 'These are ' + (personName(S.who) || 'their') + '\'s hours, as they see them. Only they and the office can change them.'
				: (!S.preview ? '' : (viewingSelf()
					? 'This is Hours as a person sees it: the clock, adding hours, and the week. Nothing on it grades them.'
					: 'This is Hours as ' + (personName(S.who) || 'they') + ' sees it. Nothing here acts while you look.'));
		}
		root.classList.toggle('is-worker', workerView());
	}

	function load() {
		chrome();
		drawPeriods();
		var seq = ++S.seq; // only the newest request is drawn: a slow answer never lands over a later choice
		var body = root.querySelector('#zhr-body');
		if (S.who === 'all') {
			body.innerHTML = loadingHtml('Loading everyone…');
			root.querySelector('#zhr-clock').innerHTML = '';
			root.querySelector('#zhr-compose').hidden = true;
			return api('team?' + periodQuery()).then(function (r) {
				if (seq !== S.seq) { return; }
				S.team = r; S.data = null; S.loadedAt = Date.now();
				drawTeam();
			}).catch(function (e) { if (seq === S.seq) { body.innerHTML = '<p class="zhr-err">' + esc(e.message) + '</p>'; } });
		}
		body.innerHTML = loadingHtml('Loading hours…');
		return api('summary?' + periodQuery() + userParam()).then(function (r) {
			if (seq !== S.seq) { return; }
			S.data = r; S.team = null; S.loadedAt = Date.now();
			if (viewingSelf()) { S.myRun = r.running; }
			draw();
			noteToday(r);
		}).catch(function (e) {
			if (seq !== S.seq) { return; }
			body.innerHTML = '<p class="zhr-err">' + esc(e.message) + '</p><p><button type="button" class="zdz-btn zdz-btn--secondary" id="zhr-retry">Try again</button></p>';
			var r = body.querySelector('#zhr-retry'); if (r) { r.addEventListener('click', load); }
		});
	}

	function reloadSoon() { S.dayCache = {}; return load(); }

	/* ------------------------------------------------------------------ the clock card */

	function drawClock() {
		var box = root.querySelector('#zhr-clock');
		var d = S.data;
		if (!d) { box.innerHTML = ''; return; }
		var run = d.running;
		var self = viewingSelf();
		var own = theirOwn(); // drawn as the person sees it (their own screen, or someone else's as they see it)
		var off = asThem() ? ' disabled' : '';
		var who = self ? '' : '<p class="zhr-viewing">' + chip(d.person.id) + 'Hours of <strong>' + esc(d.person.name) + '</strong></p>';
		var html = who;
		if (run && run.ask_finish) {
			// an earlier day's shift (or one past 16 hours) is answered before anything else; Clock in waits
			var q = run.date === d.today ? 'Your clock has been running more than 16 hours.' : shiftOf(run.date) + ' is still open.';
			html += '<div class="zhr-clock-row is-ask" role="group" aria-labelledby="zhr-ask-q"><div class="zhr-clock-text"><strong id="zhr-ask-q">' + (own ? esc(q) + ' When did you finish?' : esc(q)) + '</strong>'
				+ '<span class="zhr-sub">' + (own ? 'You clocked in at ' : 'Clocked in at ') + t12(run.start) + (run.date !== d.today ? ' on ' + dayLabel(run.date) : '') + '. Hours never fills in a finish time: ' + (own ? 'you set it, or the office does.' : 'the person sets it, or the office does.') + '</span>'
				+ (run.running ? '' : '<span class="zhr-sub">Until then it counts no hours.</span>')
				+ '</div></div>'
				+ (own ? '<div class="zhr-clock-btns zhr-ask-btns">'
					+ '<button type="button" class="zdz-btn zdz-btn--secondary" id="zhr-ask-office"' + off + '>I forgot: ask the office</button>'
					+ (run.running ? '<button type="button" class="zdz-btn zdz-btn--secondary" id="zhr-out"' + off + '>Clock out now</button>' : '')
					+ '<button type="button" class="zdz-btn zdz-btn--primary" id="zhr-set-fin"' + off + '>Set the time I finished</button></div>'
					: (isAdmin && !workerView() ? '<div class="zhr-clock-btns"><button type="button" class="zdz-btn zdz-btn--secondary" id="zhr-set-fin">Set the finish</button></div>' : ''));
		} else if (run) {
			var walk = run.source === 'doorhangers';
			html += '<div class="zhr-clock-row is-on"><div class="zhr-clock-text"><strong>' + (walk ? 'Door hanging' : 'On the clock') + '</strong>'
				+ ' since ' + t12(run.start) + (run.date !== d.today ? ' (' + dayLabel(run.date) + ')' : '')
				+ ' · <span class="zhr-live" data-live="1">' + fmt(liveMin(run)) + '</span>'
				+ (walk && own ? '<span class="zhr-sub">Started by your door-hanger walk; it stops when you finish the walk. Driving between blocks counts as work time.</span>' : '')
				+ (own ? mealReminder(d, walk) : '')
				+ (run.running_long ? '<span class="zhr-warn">The clock has run over 14 hours. If you finished earlier, set the time you finished.</span>' : '')
				+ '</div>'
				+ '</div>'
				+ (own ? '<div class="zhr-clock-btns">' + (walk ? '' : '<button type="button" class="zdz-btn zdz-btn--secondary" id="zhr-meal"' + off + '>Meal break</button>') + '<button type="button" class="zdz-btn zdz-btn--primary" id="zhr-out"' + off + '>Clock out</button></div>'
					// from the reminder time on, the way to say "I finished earlier"
					+ (run.remind_due || run.running_long ? '<p class="zhr-clock-alt"><button type="button" class="zhr-linkish" id="zhr-set-fin"' + off + '>Finished earlier? Set the time you finished</button></p>' : '') : '')
				+ '</div>';
		} else if (own && d.on_meal) {
			var minLen = mealMin();
			html += '<div class="zhr-clock-row is-meal"><div class="zhr-clock-text"><strong>On meal break</strong> since ' + t12(String(d.on_meal.since).slice(11, 16))
				+ ' · <span data-live-meal="1">' + fmt(d.on_meal.minutes + Math.floor((Date.now() - S.loadedAt) / 60000)) + '</span>'
				+ '<span class="zhr-sub">You are off the clock and off duty.' + (minLen ? ' A meal break here is at least ' + minLen + ' minutes' + (d.on_meal.minutes < minLen ? ', so back at ' + t12(addMin(String(d.on_meal.since).slice(11, 16), minLen)) + ' or later' : '') + '.' : '') + '</span>'
				+ '</div><button type="button" class="zdz-btn zdz-btn--primary" id="zhr-in"' + off + '>Clock back in</button></div>';
		} else if (own) {
			html += '<div class="zhr-clock-row"><div class="zhr-clock-text"><strong>Not on the clock</strong>'
				+ (C.askLocation ? '<span class="zhr-sub">Clocking in and out asks your phone where you are. You can say no.</span>' : '')
				+ '</div><button type="button" class="zdz-btn zdz-btn--primary" id="zhr-in"' + off + '>Clock in</button></div>';
		} else {
			html += '<p class="zhr-sub">Not on the clock now.</p>';
		}
		// shifts the person left with the office, said plainly under the card
		if (own && d.waiting && d.waiting.length) {
			html += '<ul class="zhr-waiting">' + d.waiting.map(function (w) {
				return '<li>' + esc(shiftOf(w.date)) + ' (from ' + t12(w.start) + ') is with the office: they will set when you finished. Until then it counts no hours.</li>';
			}).join('') + '</ul>';
		}
		if (!workerView()) { // the admin's full view keeps its button; everyone else gets the composer below the card
			html += '<div class="zhr-add-row"><button type="button" class="zdz-btn zdz-btn--secondary zdz-btn--block" id="zhr-add">' + (self ? 'Add hours: say it, type it, or snap a photo' : 'Add hours for ' + esc(firstName(d.person.name))) + '</button></div>';
		}
		box.innerHTML = html;
		var bi = box.querySelector('#zhr-in'), bo = box.querySelector('#zhr-out'), bm = box.querySelector('#zhr-meal');
		var bf = box.querySelector('#zhr-set-fin'), bk = box.querySelector('#zhr-ask-office');
		if (!asThem()) {
			if (bi) { bi.addEventListener('click', function () { clock('in', bi); }); }
			if (bm) { bm.addEventListener('click', function () { clock('meal', bm); }); }
			if (bo) { bo.addEventListener('click', function () { clock('out', bo); }); }
			if (bf) { bf.addEventListener('click', function () { openFinish(run, own ? '' : firstName(d.person.name)); }); }
			if (bk) { bk.addEventListener('click', function () { askOffice(run); }); }
		}
		var ba = box.querySelector('#zhr-add');
		if (ba) { ba.addEventListener('click', openAdd); }
	}

	/** Where the phone is, if the business asks and the person allows it (never longer than 7 s; null when refused). */
	function place(prompt) {
		return new Promise(function (resolve) {
			if (!navigator.geolocation || (!prompt && !C.askLocation)) { resolve(null); return; }
			var done = false;
			var timer = setTimeout(function () { if (!done) { done = true; resolve(null); } }, 7000);
			try {
				navigator.geolocation.getCurrentPosition(function (p) {
					if (done) { return; } done = true; clearTimeout(timer);
					resolve({ lat: p.coords.latitude, lng: p.coords.longitude, acc: Math.round(p.coords.accuracy || 0) });
				}, function () { if (!done) { done = true; clearTimeout(timer); resolve(null); } }, { enableHighAccuracy: true, timeout: 6500, maximumAge: 60000 });
			} catch (e) { done = true; clearTimeout(timer); resolve(null); }
		});
	}

	function clock(which, btn) {
		busy(btn, function () {
			return (C.askLocation ? place(true) : Promise.resolve(null)).then(function (p) {
				var body = p ? { lat: p.lat, lng: p.lng, acc: p.acc } : {};
				return api(which === 'in' ? 'clock-in' : (which === 'meal' ? 'meal-start' : 'clock-out'), { method: 'POST', body: body });
			});
		}).then(function (r) {
			if (which === 'in') { say(r.started ? 'Clocked in.' : 'You were already on the clock.', 'success'); }
			else if (which === 'meal') { say(r.on_meal ? 'Off the clock for your meal break. Clock back in when you are back.' : 'That was under a minute on the clock, so nothing was logged.', 'success'); }
			else {
				var q = mealQuestionToday(r.summary);
				var k = kit();
				if (q && k) {
					k.toast('Clocked out. Today has a meal-break question.', { action: 'Answer', onAction: function () { openMealAnswer(q.day, q.meal); } });
				} else {
					say(r.stopped ? 'Clocked out.' : 'The clock was not running.', 'success');
				}
			}
			reloadSoon();
		}).catch(function (e) {
			// Clock in waits for an open shift; Clock out refuses to make up a finish. Both are answered on the card.
			if (errCode(e) === 'open_shift' || errCode(e) === 'needs_time') { say(e.message); reloadSoon(); return; }
			sayErr(e);
		});
	}

	/* ------------------------------------------------------------------ the forgotten clock-out */

	/**
	 * "When did you finish?" for an open shift $e ({id, date, start}). The person types the time; nothing is filled in
	 * for them. Kept as a correction with their initials (the office's, when it is someone else's). $who: the person's
	 * first name when the office sets it. Opened inside the tap that asks (the kit's rule for Back).
	 */
	function openFinish(e, who) {
		var k = kit(); if (!k || !e) { return; }
		var mine = !who;
		var wrap = document.createElement('div');
		wrap.className = 'zhr-finish';
		wrap.innerHTML = '<p>' + (who ? esc(who) + ' clocked in at ' : 'You clocked in at ') + '<strong>' + t12(e.start) + '</strong>' + (e.date !== ((S.data && S.data.today) || C.today) ? ', ' + esc(dayLabel(e.date)) : ' today') + '.</p>'
			+ '<label class="zhr-lab" for="zhr-fin-t">' + (mine ? 'I finished at' : 'They finished at') + '</label>'
			+ '<input type="time" id="zhr-fin-t" class="zhr-fin-t" required>'
			+ '<p class="zhr-sub">' + (mine ? 'Hours never fills this in for you. The time you set is kept on the entry with your initials and when you set it.' : 'Kept on the entry as the office\'s correction, with your initials and when you set it. They see it.') + ' A time before the clock-in means the next morning (a night shift), up to 16 hours.</p>';
		k.sheet({
			title: mine ? 'When did you finish?' : 'Set the finish',
			body: wrap,
			actions: [{ label: 'Cancel' }, { label: 'Save', kind: 'primary', onClick: function (sh) {
				var t = wrap.querySelector('#zhr-fin-t').value;
				if (!t) { sh.status('Type the time you finished.', 'error'); return Promise.resolve(false); }
				return api('finish', { method: 'POST', body: { id: e.id, time: t } }).then(function () {
					say('Saved: finished at ' + t12(t) + '.', 'success'); reloadSoon(); return true;
				}).catch(function (er) { sh.status(er.message, 'error'); return false; });
			} }]
		});
	}

	/** "I forgot: ask the office", said back before it is sent. */
	function askOffice(e) {
		var k = kit(); if (!k || !e) { return; }
		var day = dayWord(e.date);
		k.sheet({
			title: 'Ask the office',
			body: '<p>The office sets when you finished on ' + esc(day === 'Today' || day === 'Yesterday' ? day.toLowerCase() : day) + ', from what they know of the day. You will see their initials on it.</p>'
				+ '<p class="zhr-sub">Until then that shift counts no hours. You can clock in now.</p>',
			actions: [{ label: 'Cancel' }, { label: 'Ask the office', kind: 'primary', onClick: function (sh) {
				return api('ask-office', { method: 'POST', body: { id: e.id } }).then(function () {
					say('Sent to the office. You can clock in now.', 'success'); reloadSoon(); return true;
				}).catch(function (er) { sh.status(er.message, 'error'); return false; });
			} }]
		});
	}

	/**
	 * The shifts with no finish yet, for the Add hours sheet ($all) and the composer (each with its own button). The
	 * composer leaves out what the card above it already says: the shift it asks about, and those with the office.
	 */
	function openShiftsHtml(all) {
		var run = S.data && S.data.running && S.data.running.ask_finish ? +S.data.running.id : 0;
		var list = (S.data && theirOwn() ? (S.data.open_shifts || []) : []).filter(function (o) { return all || (o.state !== 'parked' && +o.id !== run); });
		if (!list.length) { return ''; }
		return '<div class="zhr-openlist" role="note"><strong>No finish time yet</strong><ul>' + list.map(function (o) {
			return '<li><span>' + esc(dayLabel(o.date)) + ', from ' + t12(o.start) + (o.state === 'parked' ? ': with the office' : '') + '</span>'
				+ (o.state === 'parked' || asThem() ? '' : ' <button type="button" class="zdz-btn zdz-btn--secondary zdz-btn--sm" data-finish="' + o.id + '">Set the time</button>') + '</li>';
		}).join('') + '</ul></div>';
	}
	function wireOpenShifts(scope, closeFirst) {
		Array.prototype.forEach.call(scope.querySelectorAll('[data-finish]'), function (b) {
			b.addEventListener('click', function () {
				var id = +b.getAttribute('data-finish'), o = null;
				(S.data.open_shifts || []).forEach(function (x) { if (+x.id === id) { o = x; } });
				if (!o) { o = findEntry(id); }
				if (closeFirst) { closeFirst(); }
				openFinish(o, viewingSelf() ? '' : firstName(S.data.person.name));
			});
		});
	}

	/* ------------------------------------------------------------------ one person's period */

	var FLAG_WORDS = {
		running_long: 'running over 14 hours',
		long: 'over 14 hours',
		off_day: 'on a day off',
		overlap: 'overlaps another entry',
		no_finish: 'no finish time yet'
	};
	/** The history's reasons in words. */
	var WHY = { edit: 'changed', set_end: 'finish set later', office_set_end: 'finish set by the office', ask_office: 'asked the office to set the finish', meal_recorded: 'split for a meal break', deleted: 'deleted' };

	function addMin(hm, n) { var p = String(hm).split(':'); var m = (+p[0] * 60 + (+p[1]) + n) % 1440; return String(Math.floor(m / 60)).padStart(2, '0') + ':' + String(m % 60).padStart(2, '0'); }
	function ordinalHour(min) {
		if (min % 60) { return fmt(min) + ' worked'; }
		var h = min / 60, s = (h % 100 >= 11 && h % 100 <= 13) ? 'th' : (['th', 'st', 'nd', 'rd'][h % 10] || 'th');
		return 'your ' + h + s + ' hour';
	}

	/** Today's row, if the period shown has it. */
	function todayRow(d) {
		var r = null;
		(d && d.days ? d.days : []).forEach(function (x) { if (x.date === d.today) { r = x; } });
		return r;
	}
	/** The shortest meal the business's rules ask for, from today's row (0: no meal rules). */
	function mealMin() {
		var x = todayRow(S.data), p = x && x.meals && x.meals.progress;
		if (!p || !p.meals || !p.meals.length) { return 0; }
		return p.meals.reduce(function (a, m) { return a ? Math.min(a, m.length) : m.length; }, 0);
	}

	/**
	 * While on the clock, when the business has meal rules: when the next meal has to start, from the server's own count
	 * of today's breaks (a meal already taken is done).
	 */
	function mealReminder(d, walk) {
		var x = todayRow(d);
		if (!x || !x.meals || !x.meals.progress || !x.meals.progress.meals) { return ''; }
		var extra = Math.floor((Date.now() - S.loadedAt) / 60000);
		var how = walk ? ' For it, finish the walk, then Clock in when you are back.' : ' Press Meal break when you start it.';
		var at = function (left) { var t = new Date(Date.now() + left * 60000); return t12(String(t.getHours()).padStart(2, '0') + ':' + String(t.getMinutes()).padStart(2, '0')); };
		var m = null;
		x.meals.progress.meals.forEach(function (p) { if (!m && !p.done) { m = p; } });
		if (!m) { return ''; }
		var left = m.left - extra;
		if (left > 60) { return ''; }
		var what = (m.no === 1 ? 'a ' : 'your next ') + m.length + '-minute meal break';
		var w = m.waiver && m.waive_up_to ? ' Your signed waiver covers it only if you finish the day by ' + at(left + (m.waive_up_to - m.after)) + '.' : '';
		if (left >= 0) {
			return '<span class="zhr-warn">Take ' + what + ' by ' + at(left) + ' (the end of ' + ordinalHour(m.after) + ').' + how + w + '</span>';
		}
		return '<span class="zhr-warn">Time for ' + what + ': you are past ' + ordinalHour(m.after) + '.' + how + w + ' Hours will ask you a quick question about it once the day is over.</span>';
	}

	/** The first meal question still open today, for the toast after clocking out. */
	function mealQuestionToday(sum) {
		var x = todayRow(sum);
		if (!x || !x.meals) { return null; }
		for (var i = 0; i < x.meals.list.length; i++) {
			var m = x.meals.list[i];
			if (m.violation && !m.answer && m.can_answer) { return { day: x, meal: m }; }
		}
		return null;
	}

	function answerWords(a) {
		if (a === 'voluntary') { return 'your own choice (a meal break was offered)'; }
		if (a === 'not_provided') { return 'not provided' + (C.premiumWords ? ': ' + C.premiumWords + ' of extra pay is owed' : ''); }
		return a;
	}

	function mealsHtml(x) {
		if (!x.meals || !x.meals.list.length) { return ''; }
		return '<div class="zhr-meals"><strong>Meal breaks</strong> <button type="button" class="zhr-linkish" data-meal-help="1">how this works</button><ul>' + x.meals.list.map(function (m) {
			var line = '<li class="' + (m.violation ? 'is-issue' : '') + '">' + esc(m.words);
			if (m.violation) {
				var open = x.running || (S.data && x.date === S.data.today && S.data.on_meal);
				var mine = theirOwn();
				line += m.answer ? '<span class="zhr-sub">Answer: ' + esc(answerWords(m.answer)) + '</span>'
					: (open ? '<span class="zhr-sub">Asked once the day is over.</span>'
					: (mine
						? '<span class="zhr-warn">A quick question about this break, to keep your record right' + (m.can_answer ? '' : ' (it is older than the hours you can change, so an admin answers it)') + '.</span>'
						: '<span class="zhr-warn">Needs an answer' + (m.can_answer ? '' : ' from them') + '.</span>'));
				if (m.can_answer) { line += '<button type="button" class="zdz-btn zdz-btn--secondary zdz-btn--sm" data-meal-q="' + x.date + '|' + m.no + '"' + (asThem() ? ' disabled' : '') + '>' + (m.answer ? 'Change the answer' : 'Answer') + '</button>'; }
			}
			return line + '</li>';
		}).join('') + '</ul>' + (x.meals.premium && C.premiumWords ? '<p class="zhr-sub">Meal premium: ' + esc(C.premiumWords) + ' of extra pay for this day' + (x.meals.needs_answer ? ' unless the answer says it was the person\'s own choice' : '') + '.</p>' : '') + '</div>';
	}

	function mealHelp() {
		var k = kit(); if (!k) { return; }
		k.sheet({
			title: 'Meal breaks',
			body: '<p>A meal break is time off the clock: press <strong>Meal break</strong> when you stop to eat and <strong>Clock back in</strong> when you start again. Nothing is ever taken off your hours automatically.</p>'
				+ (C.mealWords && C.mealWords.length ? '<p>The rules here: ' + C.mealWords.map(esc).join(' ') + '</p>' : '')
				+ '<p>If a day\'s record shows a meal break missed, late, or too short, Hours asks you what happened: you took it but forgot to clock out (give the times), you chose to skip or shorten a break you were given, or you couldn\'t take it. The office sees the answer.</p>',
			actions: [{ label: 'OK', kind: 'primary' }]
		});
	}

	function findDay(date) {
		var r = null;
		(S.data ? S.data.days : []).forEach(function (d) { if (d.date === date) { r = d; } });
		return r;
	}

	/** One meal question: three answers. Opened inside the tap that asks (the kit's rule for Back). */
	function openMealAnswer(day, m) {
		var k = kit(); if (!k || !day || !m) { return; }
		var self = viewingSelf();
		var len = mealMin() || 0;
		var wrap = document.createElement('div');
		wrap.className = 'zhr-add';
		wrap.innerHTML = '<p><strong>' + esc(day.date === (S.data && S.data.today) ? 'Today' : dayLabel(day.date)) + '</strong>: ' + esc(m.words) + '.</p>'
			+ '<p class="zhr-lab">What happened?</p>'
			+ '<label class="zhr-check"><input type="radio" name="zhr-ma" value="forgot"> ' + (self ? 'I took a full meal break but didn\'t clock out' : 'They took a full meal break but didn\'t clock out') + '</label>'
			+ '<div class="zhr-grid" data-forgot hidden><label>Break started <input type="time" id="zhr-ma-a"></label><label>Break ended <input type="time" id="zhr-ma-b"></label></div>'
			+ (m.can_say_voluntary ? '<label class="zhr-check"><input type="radio" name="zhr-ma" value="voluntary"> A meal break was offered and I chose to skip, delay, or shorten it</label>' : '')
			+ '<label class="zhr-check"><input type="radio" name="zhr-ma" value="not_provided"> ' + (self ? 'I couldn\'t take a full break (work did not allow it)' : 'They could not take a full break') + '</label>'
			+ '<p class="zhr-sub">There is no wrong answer.' + (C.premiumWords ? ' If work didn\'t allow the break, ' + esc(C.premiumWords) + ' of extra pay is owed for the day.' : '') + ' Your answer is kept with the day, with any change to it. Choosing to skip a break is never required.' + (len ? ' A recorded break is ' + len + ' minutes to 2 hours.' : '') + '</p>';
		Array.prototype.forEach.call(wrap.querySelectorAll('input[name=zhr-ma]'), function (r) {
			r.addEventListener('change', function () { wrap.querySelector('[data-forgot]').hidden = r.value !== 'forgot' || !r.checked; });
		});
		k.sheet({
			title: 'Meal break ' + m.no,
			body: wrap,
			actions: [{ label: 'Cancel' }, { label: 'Save', kind: 'primary', onClick: function (sh) {
				var pick = wrap.querySelector('input[name=zhr-ma]:checked');
				if (!pick) { sh.status('Choose one.', 'error'); return Promise.resolve(false); }
				var body = { date: day.date, no: m.no, answer: pick.value };
				if (pick.value === 'forgot') {
					body.meal_start = wrap.querySelector('#zhr-ma-a').value; body.meal_end = wrap.querySelector('#zhr-ma-b').value;
					if (!body.meal_start || !body.meal_end) { sh.status('Give the break\'s start and end.', 'error'); return Promise.resolve(false); }
				}
				if (!self) { body.user = S.who; }
				return api('meal-answer', { method: 'POST', body: body }).then(function () { say('Saved.', 'success'); reloadSoon(); return true; });
			} }]
		});
	}

	function evidenceChip(e) {
		var ev = e.evidence || { level: 'none', pct: 0 };
		var words = { strong: 'Backed by app activity', some: 'Partly backed by app activity', little: 'Little app activity', none: 'No app activity in this time' }[ev.level];
		return '<button type="button" class="zhr-ev is-' + ev.level + '" data-ev-help="1">' + words + (ev.level !== 'none' ? ' (' + ev.pct + '%)' : '') + '</button>';
	}

	/** What a door-hanger walk's stretch was: minutes on foot, and minutes driven between blocks (work time, not door hanging). */
	function walkWords(e) {
		var w = e.walk;
		if (!w || !w.drive) { return 'Door hanging'; }
		if (!w.foot) { return 'Driving between blocks'; }
		return 'Door hanging, with ' + fmt(w.drive) + ' driving between blocks';
	}

	function entryHtml(e) {
		var full = !workerView();
		var flags = full ? (e.flags || []).filter(function (f) { return FLAG_WORDS[f]; }).map(function (f) { return '<span class="zhr-flag">' + FLAG_WORDS[f] + '</span>'; }).join('') : '';
		var src = e.source === 'admin' && e.added_by ? 'Added by ' + esc(e.added_by) : (e.source === 'doorhangers' ? walkWords(e) : (SOURCE[e.source] || e.source));
		var places = [];
		if (e.start_place) { places.push('in: ' + mapsLink(e.start_place)); }
		if (e.end_place) { places.push('out: ' + mapsLink(e.end_place)); }
		var hist = '';
		if (e.history && e.history.length) {
			hist = '<details class="zhr-hist"><summary>Changed ' + e.history.length + ' time' + (e.history.length > 1 ? 's' : '') + '</summary><ul>'
				+ e.history.map(function (h) {
					var b = h.before || {};
					var was = [];
					if (b.start_at) { was.push('start ' + t12(String(b.start_at).slice(11, 16))); }
					if (b.end_at !== undefined) { was.push('end ' + (b.end_at ? t12(String(b.end_at).slice(11, 16)) : 'running')); }
					if (b.minutes !== undefined && !b.start_at && b.end_at === undefined) { was.push(fmt(b.minutes)); }
					if (b.note !== undefined) { was.push('note "' + esc(b.note) + '"'); }
					return '<li>' + esc(h.at) + ' · ' + esc(h.by) + ' · ' + esc(WHY[h.why] || h.why) + (was.length ? ' · was ' + was.join(', ') : '') + '</li>';
				}).join('') + '</ul></details>';
		} else if (e.changes) {
			hist = '<span class="zhr-sub">Changed ' + e.changes + ' time' + (e.changes > 1 ? 's' : '') + '</span>';
		}
		// a shift with no finish shows that, counts nothing, and offers the way to set it
		var open = !!e.unfinished;
		var endWords = e.running ? 'now' : (open ? (e.unfinished === 'parked' ? 'with the office' : 'no finish yet') : t12(e.end) + (e.next_day ? ' (next day)' : ''));
		var set = e.finish_set ? '<span class="zhr-set">Finish set by ' + esc(e.finish_set.by) + (e.finish_set.office ? ' (the office)' : '') + (e.finish_set.at ? ', ' + esc(dayLabel(String(e.finish_set.at).slice(0, 10))) + ' ' + t12(String(e.finish_set.at).slice(11, 16)) : '') + '</span>' : '';
		var hints = full && e.hints ? hintsHtml(e.hints) : '';
		var canFinish = open && e.can_edit && (full || e.unfinished !== 'parked');
		var tools = !asThem() && (e.can_edit || canFinish);
		return '<li class="zhr-entry' + (e.running ? ' is-running' : '') + (open ? ' is-open' : '') + '" data-id="' + e.id + '">'
			+ '<div class="zhr-entry-main"><span class="zhr-span">' + t12(e.start) + ' – ' + endWords + '</span>'
			+ '<span class="zhr-mins">' + (open ? '—' : fmt(liveMin(e))) + '</span></div>'
			+ (set || hints ? '<div class="zhr-entry-meta">' + set + hints + '</div>' : '')
			+ '<div class="zhr-entry-meta"><span class="zhr-src">' + src + '</span>' + (full ? evidenceChip(e) : '') + flags
			+ (places.length ? '<span class="zhr-places">' + places.join(' · ') + '</span>' : '') + '</div>'
			+ (e.note ? '<div class="zhr-note">' + esc(e.note) + '</div>' : '')
			+ hist
			+ (tools ? '<div class="zhr-entry-tools">'
				+ (canFinish ? '<button type="button" class="zdz-btn zdz-btn--secondary zdz-btn--sm" data-fin="' + e.id + '">' + (full && !viewingSelf() ? 'Set the finish' : 'Set the time I finished') + '</button>' : '')
				+ (e.can_edit ? '<button type="button" class="zdz-btn zdz-btn--quiet zdz-btn--sm" data-edit="' + e.id + '">' + (e.running ? 'Fix the start' : 'Change') + '</button>' : '')
				+ '</div>' : '')
			+ '</li>';
	}

	/** The office's hints for a finish (what the app saw that day). Hints only: the office decides. */
	function hintsHtml(h) {
		var bits = [];
		var t = function (v) { return v ? t12(String(v).slice(0, 5)) + (/\+1$/.test(v) ? ' (next day)' : '') : ''; };
		if (h.last_app) { bits.push('last app use ' + t(h.last_app)); }
		if (h.last_photo) { bits.push('last photo ' + t(h.last_photo)); }
		if (h.last_job) { bits.push('last Jobs step ' + t(h.last_job)); }
		if (h.last_appointment) { bits.push('last appointment ended ' + t(h.last_appointment)); }
		return '<span class="zhr-hints">' + (bits.length ? 'Hints only: ' + bits.join(' · ') : 'Nothing the app saw that day after the clock-in.') + '</span>';
	}

	function draw() {
		drawPeriods(); // the chips carry the marks from this answer
		drawClock();
		drawCompose();
		var d = S.data, body = root.querySelector('#zhr-body');
		var t = d.totals;
		var max = 0;
		d.days.forEach(function (x) { max = Math.max(max, x.worked + (x.running ? 30 : 0), x.standard || 0); });
		max = Math.max(max, 60);
		var html;
		var range = '<p class="zhr-range">' + dayLabel(d.from) + (d.from !== d.to ? ' – ' + dayLabel(d.to) : '') + (d.edit_from && !asThem() ? ' · You can change your hours from ' + dayLabel(d.edit_from) + ' on; for older ones, ask an admin.' : '') + '</p>';
		if (workerView()) {
			// the person's total: what they worked, and nothing to measure it against
			html = '<div class="zhr-total"><span class="zhr-total-n" data-live-total="1">' + fmt(t.worked + runningExtra()) + '</span>'
				+ '<span class="zhr-total-l">worked ' + esc((d.label || '').toLowerCase()) + (t.days_worked ? ' · ' + t.days_worked + ' day' + (t.days_worked === 1 ? '' : 's') : '') + '</span>'
				+ (t.meal_open ? '<span class="zhr-total-q">' + t.meal_open + ' quick meal-break question' + (t.meal_open === 1 ? '' : 's') + ' below</span>' : '')
				+ '</div>' + range;
			drawDays(d, max, html, body);
			return;
		}
		var tiles = '<div class="zhr-tile"><span class="zhr-tile-n" data-live-total="1">' + fmt(t.worked + runningExtra()) + '</span><span class="zhr-tile-l">worked, ' + esc((d.label || '').toLowerCase()) + '</span></div>';
		if (d.has_standard) {
			var diff = t.over ? '<span class="zhr-over">' + fmt(t.over) + ' over</span>' : (t.under ? '<span class="zhr-under">' + fmt(t.under) + ' under</span>' : 'right on');
			tiles += '<div class="zhr-tile"><span class="zhr-tile-n">' + fmt(t.standard_to_date) + '</span><span class="zhr-tile-l">standard so far' + (t.standard !== t.standard_to_date ? ' (of ' + fmt(t.standard) + ')' : '') + '</span></div>'
				+ '<div class="zhr-tile"><span class="zhr-tile-n">' + diff + '</span><span class="zhr-tile-l">against standard</span></div>';
		}
		if (t.overtime !== undefined) {
			tiles += '<div class="zhr-tile"><span class="zhr-tile-n">' + fmt(t.overtime) + '</span><span class="zhr-tile-l">overtime, by the business\'s lines</span></div>';
		}
		tiles += '<div class="zhr-tile"><span class="zhr-tile-n">' + t.days_worked + '</span><span class="zhr-tile-l">day' + (t.days_worked === 1 ? '' : 's') + ' worked' + (t.meal_open ? ' · ' + t.meal_open + ' meal question' + (t.meal_open === 1 ? '' : 's') + ' to answer' : '') + (t.meal_premiums && !viewingSelf() ? ' · ' + t.meal_premiums + ' meal premium' + (t.meal_premiums === 1 ? '' : 's') : '') + '</span></div>';
		html = '<div class="zhr-sum">' + tiles + '</div>' + range;
		drawDays(d, max, html, body);
	}

	/** The day list under the totals, then (admin, someone else, with meal rules) their waivers, the entries' buttons and the footer. */
	function drawDays(d, max, html, body) {
		var days = d.days.slice();
		if (days.length > 14) { // a month: newest first, and days with nothing on them folded away
			days = days.filter(function (x) { return x.raw > 0 || x.scheduled.length || x.date === d.today; }).reverse();
		}
		html += '<ul class="zhr-days">' + days.map(function (x) { return dayHtml(x, max); }).join('') + '</ul>';
		if (d.days.length > 14 && !days.length) { html += '<p class="zhr-sub">No hours in this period.</p>'; }
		body.innerHTML = html;
		Array.prototype.forEach.call(body.querySelectorAll('.zhr-day-head'), function (h) {
			h.addEventListener('click', function () { toggleDay(h.getAttribute('data-date')); });
		});
		if (isAdmin && !workerView() && !viewingSelf() && d.waivers && d.meal_rules) {
			body.insertAdjacentHTML('beforeend', '<details class="zhr-waivers"><summary><strong>Signed meal waivers</strong> (' + (d.waivers.first || d.waivers.second ? 'on file' : 'none') + ')</summary>'
				+ '<p class="zhr-sub">Only with a written waiver this person signed, for a rule that allows one. A waiver covers days from the date it was signed. Clearing a date revokes it from today; the days before stay covered.</p>'
				+ '<div class="zhr-grid"><label>First meal: signed on <input type="date" id="zhr-w1" max="' + esc(d.today) + '" value="' + esc(d.waivers.first_on) + '"></label>'
				+ '<label>Second meal: signed on <input type="date" id="zhr-w2" max="' + esc(d.today) + '" value="' + esc(d.waivers.second_on) + '"></label></div>'
				+ waiverHistory(d.waivers)
				+ '<button type="button" class="zdz-btn zdz-btn--secondary zdz-btn--sm" id="zhr-w-save">Save waivers</button></details>');
			var ws = body.querySelector('#zhr-w-save');
			ws.addEventListener('click', function () {
				busy(ws, function () {
					return api('waivers', { method: 'POST', body: { user: S.who, first: body.querySelector('#zhr-w1').value, second: body.querySelector('#zhr-w2').value } });
				}).then(function () { say('Saved.', 'success'); reloadSoon(); }).catch(function (e) { sayErr(e); });
			});
		}
		wireEntries(body);
		drawFoot();
	}

	function waiverHistory(w) {
		var rows = [];
		[['first', 'First meal'], ['second', 'Second meal']].forEach(function (k) {
			(w[k[0] + '_periods'] || []).forEach(function (p) {
				rows.push('<li>' + k[1] + ': from ' + esc(p[0]) + (p[1] ? ' until ' + esc(p[1]) + ' (revoked)' : ' (in force)') + '</li>');
			});
		});
		return rows.length ? '<ul class="zhr-sub">' + rows.join('') + '</ul>' : '';
	}

	function runningExtra() {
		var r = S.data && S.data.running;
		if (!r || r.date < S.data.from || r.date > S.data.to) { return 0; }
		return Math.floor((Date.now() - S.loadedAt) / 60000);
	}

	function dayHtml(x, max) {
		var w = Math.min(100, Math.round(100 * (x.worked + (x.running ? Math.floor((Date.now() - S.loadedAt) / 60000) : 0)) / max));
		var std = x.standard ? Math.min(100, Math.round(100 * x.standard / max)) : 0;
		var tags = '';
		if (x.off_day) { tags += '<span class="zhr-tag">day off</span>'; }
		if (x.meals && x.meals.needs_answer) { tags += '<span class="zhr-tag is-look">meal question</span>'; }
		else if (x.meals && x.meals.premium) { tags += '<span class="zhr-tag">meal premium</span>'; }
		if (x.running) { tags += '<span class="zhr-tag is-on">on the clock</span>'; }
		if (x.unfinished) { tags += '<span class="zhr-tag is-look">no finish time</span>'; }
		if (x.overtime) { tags += '<span class="zhr-tag">' + fmt(x.overtime) + ' overtime</span>'; }
		// "worth a look" is the office's: a person's own screen does not ask them to scrutinise their day
		var flagged = !workerView() && x.entries.some(function (e) { return (e.flags || []).some(function (f) { return f !== 'off_day' && FLAG_WORDS[f]; }) || (e.evidence && (e.evidence.level === 'none' || e.evidence.level === 'little')); });
		if (flagged) { tags += '<span class="zhr-tag is-look">worth a look</span>'; }
		var open = !!S.open[x.date];
		return '<li class="zhr-day' + (x.date === S.data.today ? ' is-today' : '') + (x.future ? ' is-future' : '') + '">'
			+ '<button type="button" class="zhr-day-head" data-date="' + x.date + '" aria-expanded="' + open + '">'
			+ '<span class="zhr-day-name">' + (x.date === S.data.today ? 'Today' : dayLabel(x.date)) + '</span>'
			+ '<span class="zhr-bar" aria-hidden="true"><span class="zhr-bar-fill" style="width:' + w + '%"></span>' + (std ? '<span class="zhr-bar-std" style="left:' + std + '%"></span>' : '') + '</span>'
			+ '<span class="zhr-day-n">' + (x.raw || x.running ? fmt(x.worked + (x.running ? Math.floor((Date.now() - S.loadedAt) / 60000) : 0)) : (x.off_day ? '—' : '0')) + '</span>'
			+ '<span class="zhr-tags">' + tags + '</span>'
			+ '</button>'
			+ '<div class="zhr-day-body" id="zhr-day-' + x.date + '"' + (open ? '' : ' hidden') + '>' + (open ? dayBody(x) : '') + '</div>'
			+ '</li>';
	}

	function dayBody(x) {
		var full = S.dayCache[x.date];
		var html = '';
		html += x.entries.length ? '<ul class="zhr-entries">' + x.entries.map(entryHtml).join('') + '</ul>' : '<p class="zhr-sub">No hours logged.' + (x.off_day ? ' A day off.' : '') + '</p>';
		if (!workerView() && S.data.has_standard) {
			html += '<p class="zhr-sub">Standard: ' + (x.off_day ? 'day off' : t12(x.std_start) + ' – ' + t12(x.std_end) + ' (' + fmt(x.standard) + ' paid' + (C.mealRules ? ', with a meal break' : '') + ')') + '.</p>';
		}
		html += mealsHtml(x);
		if (x.scheduled && x.scheduled.length) {
			html += '<div class="zhr-sched"><strong>On the schedule</strong><ul>' + x.scheduled.map(function (s) {
				return '<li>' + (s.all_day ? 'All day' : t12(s.start) + ' – ' + t12(s.end)) + ' · ' + esc(s.title) + '</li>';
			}).join('') + '</ul></div>';
		}
		if (workerView()) {
			return html; // "what the app saw" is the office's; the server does not send it to anyone else
		}
		if (full === undefined) {
			html += '<div class="zhr-tl" data-tl="' + x.date + '">' + loadingHtml('What the app saw…') + '</div>';
		} else {
			html += timelineHtml(full);
		}
		return html;
	}

	function timelineHtml(tl) {
		if (!tl || !tl.length) { return '<div class="zhr-tl"><strong>What the app saw</strong><p class="zhr-sub">Nothing recorded in the app on this day.</p></div>'; }
		return '<details class="zhr-tl" open><summary><strong>What the app saw</strong> (' + tl.length + ')</summary><ol>' + tl.map(function (r) {
			return '<li><span class="zhr-tl-t">' + t12(r.time) + (r.until ? ' – ' + t12(r.until) : '') + '</span> ' + esc(r.what) + (r.app && r.what.indexOf(r.app) === -1 ? ' ' + esc(r.app) : '') + (r.place ? ' · ' + mapsLink(r.place) : '') + '</li>';
		}).join('') + '</ol></details>';
	}

	function toggleDay(date) {
		S.open[date] = !S.open[date];
		var x = null;
		S.data.days.forEach(function (d) { if (d.date === date) { x = d; } });
		var box = root.querySelector('#zhr-day-' + date);
		var head = root.querySelector('.zhr-day-head[data-date="' + date + '"]');
		if (!box || !x) { return; }
		head.setAttribute('aria-expanded', String(!!S.open[date]));
		if (!S.open[date]) { box.hidden = true; return; }
		box.hidden = false;
		box.innerHTML = dayBody(x);
		wireEntries(box);
		if (S.dayCache[date] === undefined && !workerView()) {
			var whose = userParam();
			api('day?date=' + encodeURIComponent(date) + whose).then(function (r) {
				if (whose !== userParam()) { return; } // the person or the view changed meanwhile
				S.dayCache[date] = r.timeline || [];
				var tl = box.querySelector('[data-tl="' + date + '"]');
				if (tl) { tl.outerHTML = timelineHtml(S.dayCache[date]); }
			}).catch(function (e) {
				var tl = box.querySelector('[data-tl="' + date + '"]');
				if (tl) { tl.innerHTML = '<p class="zhr-err">' + esc(e.message) + '</p>'; }
			});
		}
	}

	function findEntry(id) {
		var found = null;
		if (S.data && S.data.running && +S.data.running.id === +id) { found = S.data.running; }
		(S.data ? S.data.days : []).forEach(function (d) { d.entries.forEach(function (e) { if (+e.id === +id) { found = e; } }); });
		return found;
	}

	function wireEntries(scope) {
		Array.prototype.forEach.call(scope.querySelectorAll('[data-edit]'), function (b) {
			b.addEventListener('click', function () { openEdit(findEntry(b.getAttribute('data-edit'))); });
		});
		Array.prototype.forEach.call(scope.querySelectorAll('[data-fin]'), function (b) {
			b.addEventListener('click', function () { openFinish(findEntry(b.getAttribute('data-fin')), viewingSelf() ? '' : firstName(S.data.person.name)); });
		});
		Array.prototype.forEach.call(scope.querySelectorAll('[data-ev-help]'), function (b) {
			b.addEventListener('click', evidenceHelp);
		});
		Array.prototype.forEach.call(scope.querySelectorAll('[data-meal-help]'), function (b) { b.addEventListener('click', mealHelp); });
		Array.prototype.forEach.call(scope.querySelectorAll('[data-meal-q]'), function (b) {
			b.addEventListener('click', function () {
				var p = b.getAttribute('data-meal-q').split('|'), day = findDay(p[0]), m = null;
				(day && day.meals ? day.meals.list : []).forEach(function (x) { if (x.no === +p[1]) { m = x; } });
				openMealAnswer(day, m);
			});
		});
	}

	function evidenceHelp() {
		var k = kit(); if (!k) { return; }
		k.sheet({
			title: 'Backed by app activity',
			body: '<p>Hours compares each stretch of logged time with what the app itself saw then: apps opened and used, photos taken in the app (with where they were taken), Jobs steps, door-hanger walks traced by GPS, and where the phone was while the clock ran.</p>'
				+ '<p>The percentage is how much of the stretch had app activity nearby. It is information for the office, not a judgment: work away from the phone, driving, and workshop work leave little trace in the app. Where the phone said it was while the clock ran is the phone\'s own word, so it counts for little on its own.</p>',
			actions: [{ label: 'OK', kind: 'primary' }]
		});
	}

	function drawFoot() {
		var f = root.querySelector('#zhr-foot');
		var cur = S.data || S.team;
		if (!cur || !C.exportUrl || asThem()) { f.innerHTML = ''; return; } // someone else's file is not theirs to take
		var who = S.who === 'all' ? 'all' : (viewingSelf() ? 'me' : S.who);
		var base = C.exportUrl + '&user=' + encodeURIComponent(who) + '&period=custom&from=' + cur.from + '&to=' + cur.to;
		f.innerHTML = '<span>' + (workerView() ? 'Download your hours:' : 'Download for the accountant:') + '</span> <a class="zdz-btn zdz-btn--quiet zdz-btn--sm" href="' + esc(base + '&kind=days') + '">Daily totals (CSV)</a> <a class="zdz-btn zdz-btn--quiet zdz-btn--sm" href="' + esc(base + '&kind=entries') + '">Every entry (CSV)</a>';
	}

	/* ------------------------------------------------------------------ everyone (admin tier) */

	function drawTeam() {
		var t = S.team, body = root.querySelector('#zhr-body');
		var html = '';
		// shifts waiting for a finish come first, whatever the period: the ones people asked about, then the rest
		if (t.review && t.review.length) {
			html += '<section class="zhr-review" aria-labelledby="zhr-review-h"><h3 id="zhr-review-h">Waiting for a finish time (' + t.review.length + ')</h3><ul>' + t.review.map(function (r) {
				return '<li><div class="zhr-review-who">' + chip(r.person.id) + '<strong>' + esc(r.person.name) + '</strong> · ' + esc(dayLabel(r.date)) + ', clocked in ' + t12(r.start) + (r.source === 'doorhangers' ? ' (door-hanger walk)' : '') + '</div>'
					+ '<div class="zhr-sub">' + (r.asked ? 'Asked the office' + (r.asked_at ? ' ' + esc(dayLabel(String(r.asked_at).slice(0, 10))) + ' ' + t12(String(r.asked_at).slice(11, 16)) : '') : 'Not answered yet') + '</div>'
					+ '<div>' + hintsHtml(r.hints || {}) + '</div>'
					+ '<button type="button" class="zdz-btn zdz-btn--secondary zdz-btn--sm" data-review="' + r.id + '">Set the finish</button></li>';
			}).join('') + '</ul></section>';
		}
		html += '<p class="zhr-range">' + esc(t.label) + ': ' + dayLabel(t.from) + (t.from !== t.to ? ' – ' + dayLabel(t.to) : '') + '</p>';
		if (!t.people.length) { html += '<p class="zhr-sub">No one holds Hours yet. Give it to people in User Management, or turn on "Everyone on staff has Hours" in Settings.</p>'; }
		html += '<ul class="zhr-team">' + t.people.map(function (p) {
			var x = p.totals;
			var diff = t.has_standard ? (x.over ? '<span class="zhr-over">+' + fmt(x.over) + '</span>' : (x.under ? '<span class="zhr-under">−' + fmt(x.under) + '</span>' : '')) + ' <span class="zhr-sub">of ' + fmt(x.standard_to_date) + '</span>' : '';
			if (x.overtime) { diff += ' <span class="zhr-sub">' + fmt(x.overtime) + ' overtime</span>'; }
			return '<li><button type="button" class="zhr-person" data-person="' + p.id + '">'
				+ '<span class="zhr-person-name">' + chip(p.id) + esc(p.name) + (p.running ? (p.running.open ? ' <span class="zhr-tag is-look">no finish time</span>' : ' <span class="zhr-tag is-on">' + (p.running.source === 'doorhangers' ? 'door hanging' : 'on the clock') + '</span>') : '') + (p.flags ? ' <span class="zhr-tag is-look">' + p.flags + ' to look at</span>' : '') + '</span>'
				+ '<span class="zhr-person-n">' + fmt(x.worked) + '</span>'
				+ '<span class="zhr-person-d">' + diff + '</span>'
				+ '</button></li>';
		}).join('') + '</ul>';
		body.innerHTML = html;
		Array.prototype.forEach.call(body.querySelectorAll('[data-review]'), function (b) {
			b.addEventListener('click', function () {
				var r = null;
				t.review.forEach(function (x) { if (+x.id === +b.getAttribute('data-review')) { r = x; } });
				if (r) { openFinish({ id: r.id, date: r.date, start: r.start }, +r.person.id === +C.userId ? '' : firstName(r.person.name)); }
			});
		});
		Array.prototype.forEach.call(body.querySelectorAll('[data-person]'), function (b) {
			b.addEventListener('click', function () {
				S.who = b.getAttribute('data-person');
				var sel = root.querySelector('#zhr-who'); if (sel) { sel.value = S.who; }
				S.open = {}; S.dayCache = {};
				load();
			});
		});
		drawFoot();
	}

	/* ------------------------------------------------------------------ what a reading proposed */

	/**
	 * The read proposals as a ticked list (the composer and the sheet draw them the same way). A stretch the server could
	 * not attribute to exactly one person is a review item: it starts unticked and is added only once someone chooses
	 * whose it is (a person outside the admin tier may only say "these are mine"). $mine: the list is for the viewer's
	 * own hours.
	 */
	function proposalsHtml(list, unclear, lead, mine) {
		return '<p class="zhr-lab">' + esc(lead) + '</p><ul class="zhr-prop-list">' + list.map(function (p, i) {
			var review = !!p.review && p.review !== 'other_person';
			var other = p.review === 'other_person';
			var addable = p.ok && (!p.review || (other && isAdmin && p.person));
			var pick = '';
			if (review && p.choices && p.choices.length) {
				if (isAdmin) {
					pick = '<label class="zhr-pick">Whose hours? <select data-pick="' + i + '"><option value="">Choose…</option>' + p.choices.map(function (c) { return '<option value="' + c.id + '">' + esc(c.name) + '</option>'; }).join('') + '</select></label>';
				} else {
					pick = '<label class="zhr-check zhr-pick"><input type="checkbox" data-mine="' + i + '"> These are my hours</label>';
				}
			}
			var whose = p.person && (other || !mine) ? ' · <em>for ' + esc(p.person.name) + '</em>' : '';
			var when = p.needs_start ? 'a length only' : t12(p.start) + ' – ' + t12(p.end) + (p.next_day ? ' (next day)' : '');
			return '<li class="' + (p.ok && !p.review ? '' : 'is-bad') + (p.review ? ' is-review' : '') + '"><label><input type="checkbox" data-i="' + i + '"' + (addable ? ' checked' : ' disabled') + '> '
				+ '<span><strong>' + dayLabel(p.date) + '</strong> ' + when + (p.minutes ? ' · ' + fmt(p.minutes) : '')
				+ (p.kind === 'finish' ? ' · <em>the finish of the shift clocked in for</em>' : '')
				+ whose + (p.note ? ' · ' + esc(p.note) : '') + '</span></label>'
				+ (p.assumed ? '<span class="zhr-sub">Only the length was given, so it starts at the standard start. Change it after adding if that is wrong.</span>' : '')
				+ (p.review_words ? '<span class="zhr-warn">' + esc(p.review_words) + '</span>' : '')
				+ pick
				+ (p.problems && p.problems.length ? '<span class="zhr-warn">' + esc(p.problems.join(' ')) + '</span>' : '')
				+ '</li>';
		}).join('') + '</ul>'
			+ (unclear.length ? '<p class="zhr-warn">Not sure about: ' + esc(unclear.join(' ')) + '</p>' : '');
	}

	/** A review item's tick follows its choice: chosen, it can be ticked; unchosen, it cannot. */
	function wireProposals(box, list, onCount) {
		Array.prototype.forEach.call(box.querySelectorAll('[data-pick]'), function (s) {
			s.addEventListener('change', function () {
				var i = +s.getAttribute('data-pick'), c = box.querySelector('input[data-i="' + i + '"]');
				if (c) { c.disabled = !s.value || !list[i].ok; c.checked = !!s.value && list[i].ok; }
				onCount();
			});
		});
		Array.prototype.forEach.call(box.querySelectorAll('[data-mine]'), function (m) {
			m.addEventListener('change', function () {
				var i = +m.getAttribute('data-mine'), c = box.querySelector('input[data-i="' + i + '"]');
				if (c) { c.disabled = !m.checked || !list[i].ok; c.checked = m.checked && list[i].ok; }
				onCount();
			});
		});
		Array.prototype.forEach.call(box.querySelectorAll('input[data-i]'), function (c) { c.addEventListener('change', onCount); });
	}

	/**
	 * The ticked proposals, grouped by the person they are added for: [{ user, entries }]. A stretch exactly someone's
	 * goes to them; a review item to the person chosen for it. The server checks every one again.
	 */
	function keptByPerson(box, list, target) {
		var groups = {};
		Array.prototype.forEach.call(box.querySelectorAll('input[data-i]:checked'), function (c) {
			var i = +c.getAttribute('data-i'), p = list[i], who = target, chosen = 0;
			if (p.review && p.review !== 'other_person') {
				var s = box.querySelector('[data-pick="' + i + '"]'), m = box.querySelector('[data-mine="' + i + '"]');
				chosen = s ? +s.value : (m && m.checked ? +C.userId : 0);
				if (!chosen) { return; }
				who = chosen;
			} else if (p.person) {
				who = +p.person.id;
			}
			var row = p.kind === 'finish' ? { ref: p.ref, end: p.end, date: p.date } : { ref: p.ref, date: p.date, start: p.start, end: p.end, note: p.note };
			if (chosen) { row.person = chosen; }
			(groups[who] = groups[who] || []).push(row);
		});
		return Object.keys(groups).map(function (k) { return { user: +k, entries: groups[k] }; });
	}

	/** Add each person's kept stretches with one request each. Resolves to {added, skipped}. */
	function addKept(groups, token) {
		var added = 0, skipped = [];
		return groups.reduce(function (p, g) {
			return p.then(function () {
				var body = { entries: g.entries, token: token };
				if (+g.user !== +C.userId) { body.user = g.user; }
				return api('entries/batch', { method: 'POST', body: body }).then(function (r) {
					added += r.added || 0; skipped = skipped.concat(r.skipped || []);
				});
			});
		}, Promise.resolve()).then(function () { return { added: added, skipped: skipped }; });
	}

	function sayAdded(r) {
		if (r.skipped && r.skipped.length) {
			say('Added ' + r.added + '. Not added: ' + r.skipped.map(function (s) { return (s.date ? dayLabel(s.date) + ' ' : '') + (s.start ? t12(s.start) + ' – ' : '') + t12(s.end) + ' (' + s.why + ')'; }).join('; '));
		} else {
			say('Added ' + r.added + (r.added === 1 ? ' entry.' : ' entries.'), 'success');
		}
	}

	/* ------------------------------------------------------------------ the composer */

	var ICON = {
		mic: '<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false"><path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M12 3a3 3 0 0 0-3 3v6a3 3 0 0 0 6 0V6a3 3 0 0 0-3-3zM5 11a7 7 0 0 0 14 0M12 18v3"/></svg>',
		camera: '<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false"><path fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round" d="M4 8h3l2-3h6l2 3h3v11H4z"/><circle cx="12" cy="13" r="3.5" fill="none" stroke="currentColor" stroke-width="2"/></svg>',
		photo: '<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false"><rect x="3" y="5" width="18" height="14" rx="2" fill="none" stroke="currentColor" stroke-width="2"/><path fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round" d="M3 16l5-5 4 4 3-3 6 6"/></svg>'
	};
	var CP = { proposals: null, unclear: [], token: '', image: null, rec: null, clear: false };

	/**
	 * Add hours: one box for words (typed, or said with the mic), a camera, and a photo button (on a phone it offers the
	 * library, the camera and files). "Read it" shows what was read, ticked; "Add" adds the ticked ones.
	 */
	function drawCompose() {
		var box = root.querySelector('#zhr-compose');
		if (!workerView() || !S.data) { box.hidden = true; box.innerHTML = ''; box.removeAttribute('data-mode'); return; }
		// a refresh (every 5 minutes on the clock, or on coming back to the app) keeps what is being typed or read
		var mode = (asThem() ? 'them:' + S.who : 'own') + '|' + (C.reader ? 1 : 0) + '|' + (S.data.open_shifts || []).map(function (o) { return o.id + o.state; }).join(',');
		if (!box.hidden && box.getAttribute('data-mode') === mode && !CP.clear) { return; }
		box.setAttribute('data-mode', mode);
		box.hidden = false;
		var off = asThem() ? ' disabled' : '';
		var SR = window.SpeechRecognition || window.webkitSpeechRecognition;
		if (!C.reader) { // words and photos cannot be read on this site yet: exact times only
			box.innerHTML = openShiftsHtml() + '<h3 class="zhr-compose-title">Add hours</h3>'
				+ '<button type="button" class="zdz-btn zdz-btn--secondary zdz-btn--block" id="zhr-c-exact-only"' + off + '>Add a start and an end time</button>';
			var only = box.querySelector('#zhr-c-exact-only');
			if (!asThem()) { only.addEventListener('click', function () { openAdd('exact'); }); wireOpenShifts(box); }
			return;
		}
		box.innerHTML = openShiftsHtml() + '<label class="zhr-compose-title" for="zhr-c-words">Add hours: say it, type it, or snap a photo</label>'
			+ '<textarea id="zhr-c-words" rows="3" placeholder="7:30 to 4 yesterday, lunch 12 to 12:30. Or a photo of your time sheet."' + off + '></textarea>'
			+ '<div class="zhr-compose-tools">'
			+ (SR ? '<button type="button" class="zdz-btn zdz-btn--secondary zdz-btn--sm zhr-tool" id="zhr-c-mic"' + off + '>' + ICON.mic + '<span>Speak</span></button>' : '')
			+ '<label class="zdz-btn zdz-btn--secondary zdz-btn--sm zhr-tool' + (off ? ' is-off' : '') + '">' + ICON.camera + '<span>Camera</span><input type="file" id="zhr-c-cam" accept="image/*" capture="environment" class="zhr-file"' + off + '></label>'
			+ '<label class="zdz-btn zdz-btn--secondary zdz-btn--sm zhr-tool' + (off ? ' is-off' : '') + '">' + ICON.photo + '<span>Photo</span><input type="file" id="zhr-c-file" accept="image/*,.heic,.heif" class="zhr-file"' + off + '></label>'
			+ '</div>'
			+ (SR ? '' : '<p class="zhr-sub">Tip: the microphone on your keyboard dictates.</p>')
			+ '<div class="zhr-compose-photo" id="zhr-c-photo" hidden><img alt="Your photo" id="zhr-c-img"><button type="button" class="zhr-linkish" id="zhr-c-unphoto">Remove the photo</button></div>'
			+ '<div class="zhr-props" id="zhr-c-props" aria-live="polite"></div>'
			+ '<button type="button" class="zdz-btn zdz-btn--primary zdz-btn--block" id="zhr-c-go"' + off + '>Read it</button>'
			+ '<p class="zhr-compose-alt"><button type="button" class="zhr-linkish" id="zhr-c-exact"' + off + '>Or type an exact start and end</button></p>';
		CP = { proposals: null, unclear: [], token: '', image: null, rec: null };
		if (asThem()) { return; }
		wireOpenShifts(box);
		var q = function (s) { return box.querySelector(s); };
		var words = q('#zhr-c-words'), go = q('#zhr-c-go'), props = q('#zhr-c-props');
		function reset() { CP.proposals = null; CP.unclear = []; CP.token = ''; props.innerHTML = ''; go.textContent = 'Read it'; }
		words.addEventListener('input', function () { if (CP.proposals) { reset(); } });
		q('#zhr-c-exact').addEventListener('click', function () { openAdd('exact'); });
		var mic = q('#zhr-c-mic');
		if (mic && SR) {
			mic.addEventListener('click', function () {
				if (CP.rec) { try { CP.rec.stop(); } catch (e) {} return; }
				var rec = new SR();
				rec.lang = speechLang(); rec.interimResults = false; rec.continuous = true;
				rec.onresult = function (ev) {
					for (var i = ev.resultIndex; i < ev.results.length; i++) {
						if (ev.results[i].isFinal) { words.value = (words.value ? words.value.replace(/\s*$/, ' ') : '') + ev.results[i][0].transcript.trim(); }
					}
					if (CP.proposals) { reset(); }
				};
				rec.onend = function () { CP.rec = null; mic.querySelector('span').textContent = 'Speak'; mic.classList.remove('is-on'); };
				rec.onerror = function () { CP.rec = null; mic.querySelector('span').textContent = 'Speak'; mic.classList.remove('is-on'); say('The microphone did not start. Use the keyboard\'s microphone instead.'); };
				try { rec.start(); CP.rec = rec; mic.querySelector('span').textContent = 'Stop'; mic.classList.add('is-on'); } catch (e) { CP.rec = null; }
			});
		}
		function takePhoto(input) {
			input.addEventListener('change', function () {
				var f = input.files && input.files[0];
				input.value = '';
				if (!f) { return; }
				reset();
				shrink(f).then(function (url) {
					CP.image = url;
					q('#zhr-c-img').src = url; q('#zhr-c-photo').hidden = false;
				}).catch(function (e) { say(e.message); });
			});
		}
		takePhoto(q('#zhr-c-cam'));
		takePhoto(q('#zhr-c-file'));
		q('#zhr-c-unphoto').addEventListener('click', function () { CP.image = null; q('#zhr-c-photo').hidden = true; q('#zhr-c-img').removeAttribute('src'); reset(); });
		go.addEventListener('click', function () {
			if (CP.proposals) { addFromCompose(); return; }
			var text = words.value.trim();
			if (!text && !CP.image) { say('Say or type your hours, or add a photo of them.'); words.focus(); return; }
			var req = { text: text };
			if (CP.image) { req.image = CP.image; }
			busy(go, function () {
				return api('parse', { method: 'POST', body: req, timeout: 60000 });
			}).then(function (r) {
				CP.proposals = r.proposals || []; CP.unclear = r.unclear || []; CP.token = r.token || '';
				drawComposeProps();
			}).catch(function (e) { sayErr(e); });
		});
		function drawComposeProps() {
			if (!CP.proposals.length) {
				props.innerHTML = '<p class="zhr-warn">No hours could be read from that.' + (CP.unclear.length ? ' ' + esc(CP.unclear.join(' ')) : '') + ' Try saying when each stretch started and ended, or type an exact start and end.</p>';
				CP.proposals = null; go.textContent = 'Read it';
				return;
			}
			props.innerHTML = proposalsHtml(CP.proposals, CP.unclear, 'This is what was read. Untick anything that is wrong.', true);
			wireProposals(props, CP.proposals, count);
			count();
			if (!CP.proposals.some(function (p) { return p.ok; })) { CP.proposals = null; go.textContent = 'Read it again'; }
		}
		function count() {
			var n = props.querySelectorAll('input[data-i]:checked').length;
			go.textContent = n ? 'Add ' + n + ' to my hours' : 'Nothing ticked';
		}
		function addFromCompose() {
			var groups = keptByPerson(props, CP.proposals, +C.userId);
			if (!groups.length) { say('Tick at least one, or change the words and read them again.'); return; }
			busy(go, function () { return addKept(groups, CP.token); }).then(function (r) {
				sayAdded(r);
				CP.clear = true; // added: the next draw starts an empty composer
				reloadSoon();
			}).catch(function (e) { sayErr(e); });
		}
	}

	/* ------------------------------------------------------------------ adding hours */

	/** The sheet: say or type, a photo, or exact times. $first: the tab to open on ('exact' from the composer). */
	function openAdd(first) {
		var k = kit(); if (!k) { return; }
		var self = viewingSelf();
		var target = self ? +C.userId : +S.who;
		var name = S.data ? S.data.person.name : '';
		var st = { tab: typeof first === 'string' ? first : (C.reader ? 'say' : 'exact'), proposals: null, unclear: [], image: null, token: '' };
		var SR = window.SpeechRecognition || window.webkitSpeechRecognition;
		var wrap = document.createElement('div');
		wrap.className = 'zhr-add';
		wrap.innerHTML = openShiftsHtml(true)
			+ '<div class="zhr-tabs" role="tablist">'
			+ (C.reader ? '<button type="button" role="tab" class="zdz-btn zdz-btn--sm" data-tab="say">Say or type</button><button type="button" role="tab" class="zdz-btn zdz-btn--sm" data-tab="photo">Photo</button>' : '')
			+ '<button type="button" role="tab" class="zdz-btn zdz-btn--sm" data-tab="exact">Exact times</button></div>'
			+ '<div data-pane="say" hidden>'
			+ '<label class="zhr-lab" for="zhr-words">The hours, in your own words</label>'
			+ '<textarea id="zhr-words" rows="4" placeholder="7:30 to 4 yesterday, lunch 12 to 12:30. Today 8 to noon."></textarea>'
			+ (SR ? '<button type="button" class="zdz-btn zdz-btn--secondary zdz-btn--sm" id="zhr-mic">Speak</button> ' : '')
			+ '<span class="zhr-sub">' + (SR ? 'Or use the microphone on your keyboard.' : 'Tip: the microphone on your keyboard dictates.') + '</span>'
			+ '</div>'
			+ '<div data-pane="photo" hidden>'
			+ '<label class="zhr-lab" for="zhr-photo">A photo of a time sheet, a punch card, or a note</label>'
			+ '<input type="file" id="zhr-photo" accept="image/*">'
			+ '<img class="zhr-photo-prev" id="zhr-photo-prev" alt="" hidden>'
			+ '<label class="zhr-lab" for="zhr-photo-words">Anything to add (optional)</label><input type="text" id="zhr-photo-words" placeholder="This is last week\'s sheet">'
			+ '<p class="zhr-sub">Hours adds a row only for the person whose name is on it. A row with no name, or a name that is not exactly one person\'s, waits for someone to choose whose it is.</p>'
			+ '</div>'
			+ '<div data-pane="exact" hidden>'
			+ '<div class="zhr-grid"><label>Day <input type="date" id="zhr-x-date" value="' + esc(C.today) + '" max="' + esc(C.today) + '"></label>'
			+ '<label>Start <input type="time" id="zhr-x-start" value=""></label><label>End <input type="time" id="zhr-x-end" value=""></label></div>'
			+ '<label class="zhr-lab" for="zhr-x-note">What it was (optional)</label><input type="text" id="zhr-x-note" maxlength="500" placeholder="What the work was">'
			+ '<p class="zhr-sub">An end before the start runs past midnight.</p>'
			+ '</div>'
			+ '<div class="zhr-props" id="zhr-props" aria-live="polite"></div>';

		var sheet = k.sheet({
			title: self ? 'Add hours' : 'Add hours for ' + name,
			body: wrap,
			actions: [
				{ label: 'Cancel' },
				{ label: 'Read it', kind: 'primary', onClick: function (sh) { return primary(sh); } }
			]
		});
		var mainBtn = sheet.el.querySelector('.zdz-sheet-actions .zdz-btn--primary');
		wireOpenShifts(wrap, function () { if (sheet && sheet.close) { sheet.close(); } });
		function setMain(label) { if (mainBtn) { mainBtn.textContent = label; } }
		function q(sel) { return wrap.querySelector(sel); }

		function showTab(tab) {
			st.tab = tab;
			Array.prototype.forEach.call(wrap.querySelectorAll('[data-tab]'), function (b) {
				var on = b.getAttribute('data-tab') === tab;
				b.className = 'zdz-btn zdz-btn--sm ' + (on ? 'zdz-btn--primary' : 'zdz-btn--secondary');
				b.setAttribute('aria-selected', String(on));
			});
			Array.prototype.forEach.call(wrap.querySelectorAll('[data-pane]'), function (p) { p.hidden = p.getAttribute('data-pane') !== tab; });
			resetProps();
		}
		function resetProps() {
			st.proposals = null; st.unclear = [];
			q('#zhr-props').innerHTML = '';
			setMain(st.tab === 'exact' ? 'Add' : 'Read it');
		}
		Array.prototype.forEach.call(wrap.querySelectorAll('[data-tab]'), function (b) {
			b.addEventListener('click', function () { showTab(b.getAttribute('data-tab')); });
		});
		if (q('#zhr-words')) { q('#zhr-words').addEventListener('input', function () { if (st.proposals) { resetProps(); } }); }

		// dictation in the browser where it has it; the keyboard's microphone works everywhere
		var rec = null;
		var mic = q('#zhr-mic');
		if (mic && SR) {
			mic.addEventListener('click', function () {
				if (rec) { try { rec.stop(); } catch (e) {} return; }
				rec = new SR();
				rec.lang = speechLang(); rec.interimResults = false; rec.continuous = true;
				var box = q('#zhr-words');
				rec.onresult = function (ev) {
					for (var i = ev.resultIndex; i < ev.results.length; i++) {
						if (ev.results[i].isFinal) { box.value = (box.value ? box.value.replace(/\s*$/, ' ') : '') + ev.results[i][0].transcript.trim(); }
					}
					if (st.proposals) { resetProps(); }
				};
				rec.onend = function () { rec = null; mic.textContent = 'Speak'; };
				rec.onerror = function () { rec = null; mic.textContent = 'Speak'; say('The microphone did not start. Use the keyboard\'s microphone instead.'); };
				try { rec.start(); mic.textContent = 'Stop'; } catch (e) { rec = null; }
			});
			sheet.closed.then(function () { if (rec) { try { rec.stop(); } catch (e) {} } });
		}

		var file = q('#zhr-photo');
		if (file) {
			file.addEventListener('change', function () {
				resetProps();
				st.image = null;
				var f = file.files && file.files[0];
				if (!f) { return; }
				shrink(f).then(function (url) {
					st.image = url;
					var prev = q('#zhr-photo-prev'); prev.src = url; prev.hidden = false;
				}).catch(function (e) { say(e.message); });
			});
		}

		function primary(sh) {
			if (st.tab === 'exact') {
				var body = { date: q('#zhr-x-date').value, start: q('#zhr-x-start').value, end: q('#zhr-x-end').value, note: q('#zhr-x-note').value };
				if (!self) { body.user = S.who; }
				if (!body.date || !body.start || !body.end) { sh.status('Fill in the day, the start, and the end.', 'error'); return Promise.resolve(false); }
				return api('entries', { method: 'POST', body: body }).then(function () { say('Added ' + t12(body.start) + ' – ' + t12(body.end) + '.', 'success'); reloadSoon(); return true; });
			}
			if (st.proposals) { return addFromSheet(sh); }
			var req = { text: '' };
			if (st.tab === 'say') {
				req.text = q('#zhr-words').value.trim();
				if (!req.text) { sh.status('Say or type the hours first.', 'error'); return Promise.resolve(false); }
			} else {
				if (!st.image) { sh.status('Choose or take a photo first.', 'error'); return Promise.resolve(false); }
				req.image = st.image; req.text = q('#zhr-photo-words').value.trim();
			}
			if (!self) { req.user = S.who; }
			sh.status('Reading…', '');
			return api('parse', { method: 'POST', body: req, timeout: 60000 }).then(function (r) {
				sh.status('', '');
				st.proposals = r.proposals || []; st.unclear = r.unclear || []; st.token = r.token || '';
				drawProps();
				return false; // keep the sheet open to look at what was read
			});
		}

		function drawProps() {
			var box = q('#zhr-props');
			if (!st.proposals.length) {
				box.innerHTML = '<p class="zhr-warn">No hours could be read from that.' + (st.unclear.length ? ' ' + esc(st.unclear.join(' ')) : '') + ' Try saying the start and end of each stretch, or use Exact times.</p>';
				setMain('Read it');
				st.proposals = null;
				return;
			}
			box.innerHTML = proposalsHtml(st.proposals, st.unclear, 'This is what was read. Untick anything that is wrong.', self);
			wireProposals(box, st.proposals, countKept);
			countKept();
			if (!st.proposals.some(function (p) { return p.ok; })) { setMain('Read it again'); st.proposals = null; }
		}
		function countKept() {
			var n = wrap.querySelectorAll('#zhr-props input[data-i]:checked').length;
			setMain(n ? 'Add ' + n : 'Nothing ticked');
		}
		function addFromSheet(sh) {
			var groups = keptByPerson(q('#zhr-props'), st.proposals, target);
			if (!groups.length) { sh.status('Tick at least one, or Cancel.', 'error'); return Promise.resolve(false); }
			return addKept(groups, st.token).then(function (r) { sayAdded(r); reloadSoon(); return true; });
		}

		showTab(st.tab);
	}

	/** A photo made small enough to send: at most 1800 px on its long side, JPEG. HEIC the browser cannot draw goes as it is. */
	function shrink(file) {
		return new Promise(function (resolve, reject) {
			if (!/^image\//.test(file.type || '') && !/\.(heic|heif)$/i.test(file.name || '')) { reject(new Error('That is not a photo.')); return; }
			var fr = new FileReader();
			fr.onerror = function () { reject(new Error('That photo could not be opened.')); };
			fr.onload = function () {
				var url = String(fr.result || '');
				var img = new Image();
				img.onload = function () {
					var w = img.naturalWidth, h = img.naturalHeight, s = Math.min(1, 1800 / Math.max(w, h));
					var c = document.createElement('canvas'); c.width = Math.round(w * s); c.height = Math.round(h * s);
					c.getContext('2d').drawImage(img, 0, 0, c.width, c.height);
					resolve(c.toDataURL('image/jpeg', 0.85));
				};
				img.onerror = function () {
					if (url.length < 6000000 && /^data:image\/(heic|heif)/.test(url)) { resolve(url); return; }
					reject(new Error('That photo could not be read here. Take it again with the camera.'));
				};
				img.src = url;
			};
			fr.readAsDataURL(file);
		});
	}

	/* ------------------------------------------------------------------ changing an entry */

	function openEdit(e) {
		var k = kit(); if (!k || !e) { return; }
		var wrap = document.createElement('div');
		wrap.className = 'zhr-edit';
		wrap.innerHTML = '<div class="zhr-grid"><label>Day <input type="date" id="zhr-e-date" value="' + esc(e.date) + '"></label>'
			+ '<label>Start <input type="time" id="zhr-e-start" value="' + esc(e.start) + '"></label>'
			+ '<label>End <input type="time" id="zhr-e-end" value="' + esc(e.end || '') + '"></label></div>'
			+ (e.running ? '<p class="zhr-sub">The clock is running. Leave the end empty to keep it running, or give it an end to stop it then.</p>' : '<p class="zhr-sub">An end before the start runs past midnight.</p>')
			+ '<label class="zhr-lab" for="zhr-e-note">What it was</label><input type="text" id="zhr-e-note" maxlength="500" value="' + esc(e.note) + '">'
			+ '<p class="zhr-sub">Every change is kept with who made it and what it was before.</p>';
		var armed = false;
		k.sheet({
			title: e.running ? 'The running clock' : 'Change ' + dayLabel(e.date) + ', ' + t12(e.start) + ' – ' + t12(e.end),
			body: wrap,
			actions: [
				{ label: 'Delete', kind: 'danger', onClick: function (sh) {
					if (!armed) { armed = true; sh.status('Press Delete again to remove this entry.', 'error'); return Promise.resolve(false); }
					return api('entries/' + e.id + '?_method=DELETE', { method: 'POST' }).then(function () { say('Deleted.', 'success'); reloadSoon(); return true; });
				} },
				{ label: 'Cancel' },
				{ label: 'Save', kind: 'primary', onClick: function () {
					var body = { date: wrap.querySelector('#zhr-e-date').value, start: wrap.querySelector('#zhr-e-start').value, end: wrap.querySelector('#zhr-e-end').value, note: wrap.querySelector('#zhr-e-note').value };
					return api('entries/' + e.id, { method: 'POST', body: body }).then(function () { say('Saved.', 'success'); reloadSoon(); return true; });
				} }
			]
		});
	}

	/* ------------------------------------------------------------------ settings (admin tier) */

	/** Hours as a decimal from minutes (480 -> '8'), '' for none. */
	function hrs(min) { return min == null || min === '' ? '' : String(Math.round(min / 6) / 10); }
	/** Minutes from hours typed as a decimal ('8.5' -> 510), null for empty. */
	function mins(v) { v = String(v || '').trim(); if (v === '') { return null; } var n = parseFloat(v); return isNaN(n) ? null : Math.round(n * 60); }

	function openSettings() {
		var k = kit(); if (!k) { return; }
		var names = ['', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
		var wrap = document.createElement('div');
		wrap.className = 'zhr-settings';
		wrap.innerHTML = loadingHtml('Loading the settings…');
		var cur = null;
		k.sheet({
			title: 'Standard hours, rules and settings',
			body: wrap,
			actions: [
				{ label: 'Cancel' },
				{ label: 'Save', kind: 'primary', onClick: function (sh) {
					if (!cur) { return Promise.resolve(false); }
					var q = function (s) { return wrap.querySelector(s); };
					var days = {};
					for (var d = 1; d <= 7; d++) {
						days[d] = { on: q('#zhr-s-on-' + d).checked, start: q('#zhr-s-a-' + d).value, end: q('#zhr-s-b-' + d).value };
						if (days[d].on && (!days[d].start || !days[d].end || days[d].end <= days[d].start)) { sh.status(names[d] + ': the end has to be after the start.', 'error'); return Promise.resolve(false); }
					}
					var periods = [];
					[1, 2].forEach(function (n) {
						var after = mins(q('#zhr-s-m' + n + '-after').value), len = parseInt(q('#zhr-s-m' + n + '-len').value, 10);
						if (after && len > 0) { periods.push({ after: after, length: len, waive_up_to: mins(q('#zhr-s-m' + n + '-waive').value), waive_if_previous_not_waived: n === 2 && q('#zhr-s-m2-prev').checked }); }
					});
					var body = { settings: {
						days: days,
						week_start: +q('#zhr-s-ws').value,
						edit_weeks: +q('#zhr-s-weeks').value,
						ask_location: q('#zhr-s-ask').checked,
						note_location: q('#zhr-s-note').checked,
						everyone: q('#zhr-s-all').checked,
						crew_leads: q('#zhr-s-crew').checked,
						remind_at: q('#zhr-s-remind').value, // '' = no reminder
						meals: { periods: periods, premium_minutes: parseInt(q('#zhr-s-prem').value, 10) || 0 },
						overtime: { daily_after: mins(q('#zhr-s-otd').value), weekly_after: mins(q('#zhr-s-otw').value) },
						pay_period: { kind: q('#zhr-s-pp').value, anchor: q('#zhr-s-ppa').value }
					} };
					return api('settings', { method: 'POST', body: body }).then(function (r) {
						C.weekStart = r.settings.week_start; C.askLocation = r.settings.ask_location ? '1' : ''; C.noteLocation = r.settings.note_location ? '1' : '';
						say('Saved. Everyone\'s hours follow these. Reload Hours to see new periods and rules on every screen.', 'success'); reloadSoon(); return true;
					});
				} }
			]
		});
		api('settings').then(function (r) {
			cur = r.settings;
			var rows = '';
			for (var d = 1; d <= 7; d++) {
				var x = cur.days[d] || cur.days[String(d)];
				rows += '<tr><th scope="row"><label><input type="checkbox" id="zhr-s-on-' + d + '"' + (x.on ? ' checked' : '') + '> ' + names[d] + '</label></th>'
					+ '<td><input type="time" id="zhr-s-a-' + d + '" value="' + esc(x.start) + '" aria-label="' + names[d] + ' start"></td>'
					+ '<td><input type="time" id="zhr-s-b-' + d + '" value="' + esc(x.end) + '" aria-label="' + names[d] + ' end"></td></tr>';
			}
			var mp = cur.meals.periods || [];
			var meal = function (n) {
				var p = mp[n - 1] || {};
				return '<fieldset class="zhr-fs"><legend>Meal ' + n + '</legend><div class="zhr-grid">'
					+ '<label>On a day over (hours) <input type="number" min="0" step="0.25" id="zhr-s-m' + n + '-after" value="' + esc(hrs(p.after)) + '"></label>'
					+ '<label>A break of (minutes) <input type="number" min="0" step="1" id="zhr-s-m' + n + '-len" value="' + esc(p.length || '') + '"></label>'
					+ '<label>A signed waiver covers days up to (hours, empty: never) <input type="number" min="0" step="0.25" id="zhr-s-m' + n + '-waive" value="' + esc(hrs(p.waive_up_to)) + '"></label></div>'
					+ (n === 2 ? '<label class="zhr-check"><input type="checkbox" id="zhr-s-m2-prev"' + (p.waive_if_previous_not_waived ? ' checked' : '') + '> Waivable only when meal 1 was not waived</label>' : '')
					+ '</fieldset>';
			};
			var pp = cur.pay_period || { kind: '', anchor: '' };
			wrap.innerHTML = '<h3 class="zhr-s-h">Standard hours</h3><p class="zhr-sub">For everyone. A day without a tick is a day off. With none set, Hours does not compare hours with a standard.</p>'
				+ '<table class="zhr-stable"><thead><tr><th>Day</th><th>Start</th><th>End</th></tr></thead><tbody>' + rows + '</tbody></table>'
				+ '<label class="zhr-lab">Weeks start on <select id="zhr-s-ws">' + [1, 2, 3, 4, 5, 6, 7].map(function (n) { return '<option value="' + n + '"' + (cur.week_start === n ? ' selected' : '') + '>' + names[n] + '</option>'; }).join('') + '</select></label>'
				+ '<h3 class="zhr-s-h">Pay period</h3>'
				+ '<div class="zhr-grid"><label>Pay period <select id="zhr-s-pp">' + [['', 'None'], ['weekly', 'Weekly'], ['biweekly', 'Every two weeks'], ['semimonthly', 'Twice a month (1–15, 16–end)'], ['monthly', 'Monthly']].map(function (o) { return '<option value="' + o[0] + '"' + (pp.kind === o[0] ? ' selected' : '') + '>' + o[1] + '</option>'; }).join('') + '</select></label>'
				+ '<label>First day of any one two-week period <input type="date" id="zhr-s-ppa" value="' + esc(pp.anchor || '') + '"></label></div>'
				+ '<h3 class="zhr-s-h">Meal breaks</h3>'
				+ '<p class="zhr-sub">The business\'s rules, if it has any (they depend on where it works). Leave them empty for none. Meal breaks are never deducted: people clock out for them. A missed, late, or short one asks the person what happened.</p>'
				+ meal(1) + meal(2)
				+ '<label class="zhr-lab">Extra pay owed for a day with a missed meal the person did not choose (minutes, 0 for none) <input type="number" min="0" step="1" id="zhr-s-prem" value="' + esc(cur.meals.premium_minutes || 0) + '"></label>'
				+ (r.meal_words && r.meal_words.length ? '<p class="zhr-sub">Now: ' + r.meal_words.map(esc).join(' ') + '</p>' : '')
				+ '<h3 class="zhr-s-h">Overtime</h3>'
				+ '<p class="zhr-sub">Hours past a line are reported as overtime for the office and the accountant. Empty: no line.</p>'
				+ '<div class="zhr-grid"><label>Past this many hours in a day <input type="number" min="0" step="0.25" id="zhr-s-otd" value="' + esc(hrs(cur.overtime.daily_after)) + '"></label>'
				+ '<label>Past this many hours in a week <input type="number" min="0" step="0.25" id="zhr-s-otw" value="' + esc(hrs(cur.overtime.weekly_after)) + '"></label></div>'
				+ '<h3 class="zhr-s-h">Who and what</h3>'
				+ '<label class="zhr-lab">People may change their own hours for <select id="zhr-s-weeks">' + [1, 2, 3, 4].map(function (n) { return '<option value="' + n + '"' + (cur.edit_weeks === n ? ' selected' : '') + '>' + (n === 1 ? 'this week only' : n === 2 ? 'this week and last' : 'the last ' + n + ' weeks') + '</option>'; }).join('') + '</select></label>'
				+ '<label class="zhr-check"><input type="checkbox" id="zhr-s-all"' + (cur.everyone ? ' checked' : '') + '> Everyone on staff has Hours (take it from one person in User Management)</label>'
				+ '<label class="zhr-check"><input type="checkbox" id="zhr-s-crew"' + (cur.crew_leads ? ' checked' : '') + '> Crew leads see their crew\'s hours (as the person sees them; only the person and the office change them)</label>'
				+ '<label class="zhr-check"><input type="checkbox" id="zhr-s-ask"' + (cur.ask_location ? ' checked' : '') + '> Clock in and out ask the phone where it is</label>'
				+ '<label class="zhr-check"><input type="checkbox" id="zhr-s-note"' + (cur.note_location ? ' checked' : '') + '> While someone is on the clock, note where the phone is when they use the app (only on phones that already allowed it)</label>'
				+ '<label class="zhr-lab" for="zhr-s-remind">Remind anyone still clocked in at</label><input type="time" id="zhr-s-remind" value="' + esc(cur.remind_at || '') + '">'
				+ '<p class="zhr-sub">One notice a shift, on the bell and the phone: "Clock out now, or set the time you finished." Leave it empty for no reminder. Hours never clocks anyone out by itself.</p>'
				+ '<p class="zhr-sub">Reading words and photos: ' + esc(r.reader || '') + '. Stored: ' + r.stats.entries + ' entries, ' + r.stats.activity + ' activity rows.</p>';
		}).catch(function (e) { wrap.innerHTML = '<p class="zhr-err">' + esc(e.message) + '</p>'; });
	}

	/* ------------------------------------------------------------------ while on the clock */

	var lastPing = 0;
	/** The phone was here, using this app: only while this person's clock runs, only if location is already allowed. */
	function ping(appId) {
		if (!C.noteLocation || !S.myRun || !navigator.geolocation || Date.now() - lastPing < 4 * 60000) { return; }
		if (!navigator.permissions || !navigator.permissions.query) { return; } // never ask just for this
		navigator.permissions.query({ name: 'geolocation' }).then(function (p) {
			if (p.state !== 'granted') { return; }
			lastPing = Date.now();
			place(true).then(function (pl) {
				if (!pl) { return; }
				api('ping', { method: 'POST', body: { app: appId || '', lat: pl.lat, lng: pl.lng, acc: pl.acc } }).catch(function () {});
			});
		}).catch(function () {});
	}

	/**
	 * The home screen's "Hours Today" tile, when the theme draws it: this person's own hours today, and a tap opens
	 * Hours. Does nothing when the tile is not on the page.
	 */
	var todayMin = null;
	function fillTodayTile() {
		if (todayMin === null) { return; }
		Array.prototype.forEach.call(document.querySelectorAll('.stat-pill .stat-label'), function (l) {
			if (String(l.textContent).trim() !== 'Hours Today') { return; }
			var pill = l.closest('.stat-pill'), val = pill && pill.querySelector('.stat-val');
			if (val) { val.textContent = fmt(todayMin + (S.myRun ? Math.floor((Date.now() - S.loadedAt) / 60000) : 0)); }
			if (pill && !pill.getAttribute('data-zhr')) {
				pill.setAttribute('data-zhr', '1');
				pill.addEventListener('click', function (ev) {
					if (window.Bridge && typeof window.Bridge.loadApp === 'function') { ev.preventDefault(); ev.stopImmediatePropagation(); window.Bridge.loadApp('hours'); }
				}, true);
			}
		});
	}
	function noteToday(d) {
		if (!d || !viewingSelf()) { return; }
		d.days.forEach(function (x) { if (x.date === d.today) { todayMin = x.worked; } });
		fillTodayTile();
	}

	function tick() {
		fillTodayTile();
		if (!root || !S.data) { return; }
		Array.prototype.forEach.call(root.querySelectorAll('[data-live]'), function (n) { n.textContent = fmt(liveMin(S.data.running)); });
		Array.prototype.forEach.call(root.querySelectorAll('[data-live-meal]'), function (n) { if (S.data.on_meal) { n.textContent = fmt(S.data.on_meal.minutes + Math.floor((Date.now() - S.loadedAt) / 60000)); } });
		var tot = root.querySelector('[data-live-total]');
		if (tot) { tot.textContent = fmt(S.data.totals.worked + runningExtra()); }
	}

	/* ------------------------------------------------------------------ start */

	/**
	 * The reminder's link (?zhr=finish) opens Hours on its card, where "Set the time you finished" is. The parameter comes
	 * off the address so a reload does not do it again. No sheet opens by itself (Back stays the kit's).
	 */
	(function () {
		if (!/[?&]zhr=finish\b/.test(window.location.search || '')) { return; }
		try {
			var u = new URL(window.location.href);
			u.searchParams.delete('zhr');
			window.history.replaceState(window.history.state, '', u.pathname + u.search + u.hash);
		} catch (e) {}
		var n = 0, iv = setInterval(function () {
			if (window.Bridge && typeof window.Bridge.loadApp === 'function') { clearInterval(iv); try { window.Bridge.loadApp('hours'); } catch (e) {} return; }
			if (++n > 40) { clearInterval(iv); var c = document.getElementById('zhr-clock'); if (c && c.scrollIntoView) { c.scrollIntoView({ block: 'start' }); } }
		}, 250);
	})();

	function start() {
		root = document.getElementById('zhr-app');
		if (!root || root.getAttribute('data-zhr-init')) { return; }
		root.setAttribute('data-zhr-init', '1');
		if (!kit()) {
			root.innerHTML = '<p class="zhr-err">Hours needs a newer Zorderz theme. Reload the app.</p>';
			return;
		}
		frame();
		load();
		setInterval(tick, 30000);
		setInterval(function () { if (document.visibilityState === 'visible' && viewingSelf() && S.data && S.data.running) { load(); } }, 5 * 60000);
		setInterval(function () { if (document.visibilityState === 'visible') { ping(''); } }, 10 * 60000);
	}

	if (document.getElementById('zhr-app')) { start(); }
	document.addEventListener('zdz_widgets_rendered', function () { start(); fillTodayTile(); });
	document.addEventListener('DOMContentLoaded', start);
})();
