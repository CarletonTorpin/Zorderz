/**
 * TS Scheduler — calendar widget.
 *
 * Vanilla JS (no build step, matches the platform). Talks to zorderz/v1/scheduler REST.
 * Three views share one month grid surface:
 *   • Calendar     — appointments (personal + shared), click to add/edit.
 *   • Availability — paint your own open/busy days; dictation flow.
 *   • Team         — everyone's free/busy as rows.
 *
 * All times are handled as UTC ISO on the wire; the UI renders in the
 * configured tz (zschData.tz) and sends wall-clock "local" strings the server
 * converts back to UTC.
 */
(function () {
	'use strict';

	var D = window.zschData || {};
	if (!D.restUrl) { return; }
	var root = document.getElementById('zsch-widget');

	// Idempotency guard: the inline boot fallback (v1.0.5) may inject a second
	// copy of this file if the first didn't initialize. If the widget is already
	// mounted, do nothing — prevents double event listeners / double render.
	if (root && root.getAttribute('data-zsch-mounted') === '1') { return; }

	// If the widget HTML hasn't been injected into the dashboard zone yet (this
	// script can evaluate before the theme's renderWidgets() runs), wait for it
	// rather than bailing out permanently. We re-check on the theme's
	// "zdz_widgets_rendered" event and on a short poll, then boot once present.
	if (!root) {
		var booted = false;
		var tryBoot = function () {
			if (booted) { return; }
			root = document.getElementById('zsch-widget');
			if (root) { booted = true; start(); }
		};
		document.addEventListener('zdz_widgets_rendered', tryBoot);
		document.addEventListener('DOMContentLoaded', tryBoot);
		var polls = 0;
		var iv = setInterval(function () {
			tryBoot();
			if (booted || ++polls > 40) { clearInterval(iv); } // ~10s max
		}, 250);
		return;
	}
	start();

	// Everything below is wrapped so it can be invoked either immediately (the
	// common case: widget HTML already in the DOM) or deferred (race above).
	function start() {

	// Mark mounted immediately so a second (fallback-injected) copy of this file
	// no-ops at the idempotency guard above instead of double-initializing.
	if (root) {
		if (root.getAttribute('data-zsch-mounted') === '1') { return; }
		root.setAttribute('data-zsch-mounted', '1');
	}

	// ── tiny helpers ───────────────────────────────────────────────
	function $(sel, ctx) { return (ctx || root).querySelector(sel); }
	function $all(sel, ctx) { return Array.prototype.slice.call((ctx || root).querySelectorAll(sel)); }
	function el(tag, cls, txt) { var e = document.createElement(tag); if (cls) e.className = cls; if (txt != null) e.textContent = txt; return e; }
	function pad(n) { return (n < 10 ? '0' : '') + n; }

	// G2 (v1.5.1): REST nonces die in 12-24h while the PWA's auth cookie
	// lives on — the "calendar quietly stopped saving" class. Two defenses:
	// a 6h keepalive (below) and a one-shot 403 retry with a fresh nonce.
	function freshNonce() {
		var fd = new FormData();
		fd.append('action', 'ts_fresh_nonce');
		return fetch(D.restUrl.replace(/wp-json.*$/, 'wp-admin/admin-ajax.php'), {
			method: 'POST', body: fd, credentials: 'same-origin'
		})
		.then(function (r) { return r.json().catch(function () { return null; }); })
		.then(function (j) {
			if (j && j.success && j.data && j.data.rest) { D.nonce = j.data.rest; return true; }
			return false;
		})
		.catch(function () { return false; });
	}
	var lastNonceRefresh = Date.now();
	setInterval(function () { lastNonceRefresh = Date.now(); freshNonce(); }, 6 * 60 * 60 * 1000);
	document.addEventListener('visibilitychange', function () {
		if (!document.hidden && (Date.now() - lastNonceRefresh) > 6 * 60 * 60 * 1000) {
			lastNonceRefresh = Date.now(); freshNonce();
		}
	});

	function api(path, opts, _retried) {
		opts = opts || {};
		var headers = { 'Content-Type': 'application/json', 'X-WP-Nonce': D.nonce };
		return fetch(D.restUrl + path, {
			method: opts.method || 'GET',
			credentials: 'same-origin',
			headers: headers,
			body: opts.body ? JSON.stringify(opts.body) : undefined
		})
		.then(function (r) {
			// G2: expired-nonce 403 → ONE fresh-nonce retry, then give up soft.
			if (r.status === 403 && !_retried) {
				return r.json().catch(function () { return {}; }).then(function (body) {
					if (body && body.code === 'rest_cookie_invalid_nonce') {
						return freshNonce().then(function (ok) {
							return ok ? api(path, opts, true) : {};
						});
					}
					return body || {};
				});
			}
			return r.json().catch(function () { return {}; });
		})
		// Never reject: a network/REST error must not break the render chain.
		// Callers always get an object; they treat missing keys as "no data".
		.catch(function () { return {}; });
	}

	function toast(msg, isErr, ms) {
		var t = $('#zsch-toast');
		if (!t) return;
		/* v1.5.3: portal the toast to <body>. position:fixed is defeated when
		 * any widget ancestor gains a transform/contain (theme card animations),
		 * which clipped save confirmations inside the dashboard widget. */
		if (t.parentNode !== document.body) document.body.appendChild(t);
		t.textContent = msg;
		t.classList.toggle('is-err', !!isErr);
		t.hidden = false;
		clearTimeout(t._t);
		t._t = setTimeout(function () { t.hidden = true; }, ms || 3200);
	}

	// ── date utilities (work in the configured tz via Intl) ────────
	// We keep a "cursor" Date at local noon to avoid DST edge slips.
	var state = {
		view: 'month',          // month | availability | team
		scope: 'all',           // all | personal | shared
		cursor: startOfMonth(new Date()),
		paintKind: 'open',
		events: [],
		availability: [],
		team: [],
		roster: [],
		// Coerce to int: REST returns owner_user_id as a number, but PHP localizes
		// userId as a string. A === / !== mismatch (e.g. 2 !== "2") was filtering out
		// every saved availability block from the grid and breaking the paint toggle.
		me: parseInt(D.userId, 10) || 0,
		editingId: null
	};

	function startOfMonth(d) { return new Date(d.getFullYear(), d.getMonth(), 1, 12, 0, 0, 0); }
	function endOfMonth(d) { return new Date(d.getFullYear(), d.getMonth() + 1, 0, 12, 0, 0, 0); }
	function addMonths(d, n) { return new Date(d.getFullYear(), d.getMonth() + n, 1, 12, 0, 0, 0); }
	function ymd(d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); }
	function sameDay(a, b) { return a.getFullYear() === b.getFullYear() && a.getMonth() === b.getMonth() && a.getDate() === b.getDate(); }

	// Parse a UTC ISO (….Z) into a local Date object.
	function parseUTC(iso) {
		if (!iso) return null;
		// Browsers parse trailing Z as UTC; the Date is then in local tz.
		var d = new Date(iso);
		return isNaN(d.getTime()) ? null : d;
	}
	// Format a Date as a datetime-local input value (local wall clock).
	function toInputLocal(d) {
		return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) + 'T' + pad(d.getHours()) + ':' + pad(d.getMinutes());
	}
	// A datetime-local string is already "local wall clock" — send as-is.
	function fromInputLocal(v) { return v ? v.replace('T', ' ') + ':00' : ''; }
	// v1.2.0: parse a 'YYYY-MM-DD' key back into a local noon Date (noon avoids
	// any DST edge that midnight could hit). Used by availability drag commit.
	function parseYmd(s) {
		var p = String(s).split('-');
		return new Date(parseInt(p[0], 10), parseInt(p[1], 10) - 1, parseInt(p[2], 10), 12, 0, 0, 0);
	}

	var MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
	var DOW = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
	var DOW_FULL = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

	// ── data loading ───────────────────────────────────────────────
	function windowParams() {
		// Pad the month with the leading/trailing days shown in the grid.
		var first = startOfMonth(state.cursor);
		var gridStart = new Date(first); gridStart.setDate(first.getDate() - first.getDay());
		var last = endOfMonth(state.cursor);
		var gridEnd = new Date(last); gridEnd.setDate(last.getDate() + (6 - last.getDay()));
		return 'start=' + ymd(gridStart) + '&end=' + ymd(gridEnd);
	}

	function loading(on) { $('#zsch-loading').hidden = !on; }

	function loadEvents() {
		loading(true);
		return api('/events?' + windowParams() + '&scope=' + state.scope).then(function (res) {
			state.events = (res && res.events) || [];
			lastDataLoad = Date.now(); // v1.5.2: freshness stamp
			loading(false);
		});
	}

	function loadAvailabilityMine() {
		return api('/availability?' + windowParams() + '&user_ids=' + state.me).then(function (res) {
			state.availability = (res && res.availability) || [];
		});
	}

	function loadTeam() {
		loading(true);
		return api('/availability/team?' + windowParams()).then(function (res) {
			state.team = (res && res.members) || [];
			loading(false);
		});
	}

	function loadRoster() {
		return api('/team').then(function (res) {
			state.roster = (res && res.members) || [];
			if (res && typeof res.is_admin !== 'undefined') { D.isAdmin = res.is_admin; }
			renderLegend();
			return res;
		});
	}

	function colorForOwner(id) {
		for (var i = 0; i < state.roster.length; i++) {
			if (parseInt(state.roster[i].user_id, 10) === parseInt(id, 10)) return state.roster[i].color;
		}
		return 'hsl(' + ((id * 47) % 360) + ' 70% 50%)';
	}

	// ── rendering: header ──────────────────────────────────────────
	function renderPeriod() {
		$('#zsch-period').textContent = MONTHS[state.cursor.getMonth()] + ' ' + state.cursor.getFullYear();
	}

	function renderLegend() {
		var leg = $('#zsch-legend');
		leg.innerHTML = '';
		if (state.scope === 'personal' || state.view === 'availability') return;
		// Show a compact owner legend for shared/all.
		var seen = {};
		state.roster.slice(0, 8).forEach(function (m) {
			if (seen[m.user_id]) return; seen[m.user_id] = 1;
			var chip = el('span', 'zsch-w-legchip');
			var dot = el('span', 'zsch-w-legdot'); dot.style.background = m.color;
			chip.appendChild(dot); chip.appendChild(document.createTextNode(m.name.split(' ')[0]));
			leg.appendChild(chip);
		});
	}

	// ── rendering: month grid (Calendar view) ──────────────────────
	function renderGridHead(headId) {
		var head = $(headId);
		head.innerHTML = '';
		DOW.forEach(function (d) { head.appendChild(el('div', 'zsch-w-doh', d)); });
	}

	function buildGridDays() {
		var first = startOfMonth(state.cursor);
		var gridStart = new Date(first); gridStart.setDate(first.getDate() - first.getDay());
		var days = [];
		for (var i = 0; i < 42; i++) {
			var d = new Date(gridStart); d.setDate(gridStart.getDate() + i);
			days.push(d);
		}
		return days;
	}

	function eventsOnDay(d) {
		return state.events.filter(function (ev) {
			var s = parseUTC(ev.start_utc), e = parseUTC(ev.end_utc);
			if (!s || !e) return false;
			// Overlaps the day.
			var dayStart = new Date(d.getFullYear(), d.getMonth(), d.getDate(), 0, 0, 0);
			var dayEnd = new Date(d.getFullYear(), d.getMonth(), d.getDate(), 23, 59, 59);
			return s <= dayEnd && e >= dayStart;
		});
	}

	function renderMonth() {
		// D2 (v1.5.0): the cascade replaces the grid presentation when the flag
		// is on. Every existing repaint path (refreshActiveView, scope chips,
		// editor save/delete) funnels through renderMonth, so hooking here means
		// the cascade repaints everywhere the grid used to.
		if (V2) { renderCascade(); return; }
		renderGridHead('#zsch-grid-head');
		var grid = $('#zsch-grid');
		grid.innerHTML = '';
		var today = new Date();
		buildGridDays().forEach(function (d) {
			var cell = el('div', 'zsch-w-cell');
			if (d.getMonth() !== state.cursor.getMonth()) cell.classList.add('is-out');
			if (sameDay(d, today)) cell.classList.add('is-today');
			cell.dataset.date = ymd(d);

			var head = el('div', 'zsch-w-cellhd');
			head.appendChild(el('span', 'zsch-w-cellnum', String(d.getDate())));
			cell.appendChild(head);

			var dayEvents = sortEvents(eventsOnDay(d));
			var CAP = 3; // show up to 3 chips, the rest go behind "+N more"
			var list = el('div', 'zsch-w-cellevts');
			dayEvents.slice(0, CAP).forEach(function (ev) {
				list.appendChild(buildEventChip(ev));
			});
			var more = dayEvents.length - CAP;
			if (more > 0) {
				var moreBtn = el('button', 'zsch-w-more', '+' + more + ' more');
				moreBtn.type = 'button';
				moreBtn.setAttribute('aria-label', more + ' more events on ' + MONTHS[d.getMonth()] + ' ' + d.getDate());
				(function (dd) {
					moreBtn.addEventListener('click', function (e) { e.stopPropagation(); openDayDetail(dd); });
				})(d);
				list.appendChild(moreBtn);
			}
			cell.appendChild(list);

			// Clicking empty space in the cell: if there are events, show the day
			// detail (least astonishment — a populated day opens its list); if the
			// day is empty and the user can write, start a new appointment.
			(function (dd, hasEvents) {
				cell.addEventListener('click', function () {
					if (hasEvents) { openDayDetail(dd); }
					else if (D.canWrite) { openEditorNew(dd); }
				});
			})(d, dayEvents.length > 0);
			grid.appendChild(cell);
		});
	}

	// Sort a day's events: all-day first, then by start time, then by owner name.
	function sortEvents(events) {
		return events.slice().sort(function (a, b) {
			if (!!a.is_all_day !== !!b.is_all_day) { return a.is_all_day ? -1 : 1; }
			var sa = parseUTC(a.start_utc), sb = parseUTC(b.start_utc);
			if (sa && sb && sa.getTime() !== sb.getTime()) { return sa - sb; }
			return (a.owner_name || '').localeCompare(b.owner_name || '');
		});
	}

	// Build one compact event chip for the month grid. Shared events lead with the
	// owner's first name (so "who" is readable at a glance, never color-alone).
	function buildEventChip(ev) {
		var pill = el('button', 'zsch-w-evt');
		pill.type = 'button';
		pill.classList.add(ev.scope === 'shared' ? 'is-shared' : 'is-personal');
		if (ev.busy_status === 'free') pill.classList.add('is-free');
		var dot = el('span', 'zsch-w-evtdot');
		dot.style.background = ev.scope === 'shared' ? colorForOwner(ev.owner_user_id) : 'var(--zsch-personal, #0ea5e9)';
		pill.appendChild(dot);
		var t = parseUTC(ev.start_utc);
		var time = ev.is_all_day ? '' : (pad(t.getHours()) + ':' + pad(t.getMinutes()) + ' ');
		var who = (ev.scope === 'shared' && ev.owner_name) ? (firstName(ev.owner_name) + ': ') : '';
		pill.appendChild(document.createTextNode(time + who + (ev.title || '(untitled)')));
		pill.title = (ev.owner_name ? ev.owner_name + ' · ' : '') + (ev.is_all_day ? 'All day' : fmtTimeRange(ev)) + ' · ' + (ev.title || '');
		pill.addEventListener('click', function (e) { e.stopPropagation(); openEditor(ev); });
		return pill;
	}

	function firstName(name) { return String(name || '').trim().split(/\s+/)[0] || name; }

	function fmtTimeRange(ev) {
		var s = parseUTC(ev.start_utc), e = parseUTC(ev.end_utc);
		if (!s) { return ''; }
		var a = pad(s.getHours()) + ':' + pad(s.getMinutes());
		if (!e) { return a; }
		return a + '–' + pad(e.getHours()) + ':' + pad(e.getMinutes());
	}

	// ── day-detail popover: all events for a day, per person ───────────
	function openDayDetail(d) {
		var title = $('#zsch-day-title');
		var body = $('#zsch-day-body');
		if (!title || !body) { return; }
		title.textContent = DOW_FULL[d.getDay()] + ', ' + MONTHS[d.getMonth()] + ' ' + d.getDate();
		body.innerHTML = '';

		var events = sortEvents(eventsOnDay(d));
		if (!events.length) {
			body.appendChild(el('div', 'zsch-w-empty', 'Nothing scheduled.'));
		} else {
			events.forEach(function (ev) {
				var row = el('button', 'zsch-w-dayrow');
				row.type = 'button';
				var dot = el('span', 'zsch-w-daydot');
				dot.style.background = ev.scope === 'shared' ? colorForOwner(ev.owner_user_id) : 'var(--zsch-personal, #0ea5e9)';
				row.appendChild(dot);
				var main = el('div', 'zsch-w-daymain');
				var top = el('div', 'zsch-w-daytitle', ev.title || '(untitled)');
				main.appendChild(top);
				var metaText = (ev.is_all_day ? 'All day' : fmtTimeRange(ev));
				if (ev.scope === 'shared' && ev.owner_name) { metaText += ' · ' + ev.owner_name; }
				if (ev.location) { metaText += ' · ' + ev.location; }
				main.appendChild(el('div', 'zsch-w-daymeta', metaText));
				row.appendChild(main);
				// Tag: shared vs mine (text, not color-only).
				var tag = el('span', 'zsch-w-daytag ' + (ev.scope === 'shared' ? 'is-shared' : 'is-personal'), ev.scope === 'shared' ? 'Team' : 'Mine');
				row.appendChild(tag);
				row.addEventListener('click', function () { hideModal('#zsch-day-modal'); openEditor(ev); });
				body.appendChild(row);
			});
		}

		// "Add on this day" action (write users only).
		var addBtn = $('#zsch-day-add');
		if (addBtn) {
			addBtn.style.display = D.canWrite ? '' : 'none';
			addBtn.onclick = function () { hideModal('#zsch-day-modal'); openEditorNew(d); };
		}
		showModal('#zsch-day-modal');
	}

	// ── rendering: availability painting ───────────────────────────
	// ── Availability as an 8am–8pm hour grid ───────────────────────────────
	// v1.2.0: each day is modelled as DAY_SLOTS one-hour slots from DAY_START_H
	// to DAY_END_H. A slot is 'open', 'busy', or null (unset). This replaces the
	// old whole-day open/busy/"Mixed" model — there is no Mixed any more; every
	// hour is explicitly Available, Busy, or Not set.
	var DAY_START_H = 8;   // 8am
	var DAY_END_H   = 20;  // 8pm
	var DAY_SLOTS   = DAY_END_H - DAY_START_H; // 12 one-hour slots

	function blocksOnDay(d, ownerId) {
		return state.availability.filter(function (b) {
			if (ownerId && parseInt(b.owner_user_id, 10) !== parseInt(ownerId, 10)) return false;
			var s = parseUTC(b.start_utc), e = parseUTC(b.end_utc);
			if (!s || !e) return false;
			var dayStart = new Date(d.getFullYear(), d.getMonth(), d.getDate(), 0, 0, 0);
			var dayEnd = new Date(d.getFullYear(), d.getMonth(), d.getDate(), 23, 59, 59);
			return s <= dayEnd && e >= dayStart;
		});
	}

	// Build the per-hour state array for a day from its stored blocks. Each
	// block is clamped to the 8am–8pm window and rasterised onto the slots.
	// Later blocks win on overlap (last-writer), which matches how we re-save.
	function dayHourState(d, ownerId) {
		var slots = new Array(DAY_SLOTS).fill(null);
		var dayMid = new Date(d.getFullYear(), d.getMonth(), d.getDate(), 0, 0, 0).getTime();
		blocksOnDay(d, ownerId).forEach(function (b) {
			var s = parseUTC(b.start_utc), e = parseUTC(b.end_utc);
			if (!s || !e) return;
			// Hour offset from local midnight; clamp to the [START,END] window.
			var startH = (s.getTime() - dayMid) / 3600000;
			var endH   = (e.getTime() - dayMid) / 3600000;
			// All-day blocks (00:00–23:59:59) cover the whole window.
			var loSlot = Math.max(0, Math.floor(startH - DAY_START_H + 0.001));
			var hiSlot = Math.min(DAY_SLOTS, Math.ceil(endH - DAY_START_H - 0.001));
			for (var i = loSlot; i < hiSlot; i++) {
				if (i >= 0 && i < DAY_SLOTS) slots[i] = (b.kind === 'busy') ? 'busy' : 'open';
			}
		});
		return slots;
	}

	// Paint the colored segments of a day's bar from its slot array.
	function renderDayBar(track, slots) {
		// Clear existing segments (keep tick marks, which carry class is-tick).
		Array.prototype.slice.call(track.querySelectorAll('.zsch-w-seg')).forEach(function (n) { n.remove(); });
		var i = 0;
		while (i < DAY_SLOTS) {
			var v = slots[i], j = i;
			while (j < DAY_SLOTS && slots[j] === v) j++;
			if (v) {
				var seg = el('div', 'zsch-w-seg ' + (v === 'busy' ? 'is-busy' : 'is-open'));
				seg.style.top = (i / DAY_SLOTS * 100) + '%';
				seg.style.height = ((j - i) / DAY_SLOTS * 100) + '%';
				track.appendChild(seg);
			}
			i = j;
		}
	}

	function renderAvailability() {
		renderGridHead('#zsch-avail-head');
		var grid = $('#zsch-avail-grid');
		grid.innerHTML = '';
		var today = new Date();
		buildGridDays().forEach(function (d) {
			var cell = el('div', 'zsch-w-cell zsch-w-cell--avail');
			cell.dataset.date = ymd(d); // v1.2.0: needed for drag hit-testing
			if (d.getMonth() !== state.cursor.getMonth()) cell.classList.add('is-out');
			if (sameDay(d, today)) cell.classList.add('is-today');

			var head = el('div', 'zsch-w-cellhd');
			head.appendChild(el('span', 'zsch-w-cellnum', String(d.getDate())));
			cell.appendChild(head);

			// The hour track (8am at top → 8pm at bottom) with 3-hour ticks.
			var track = el('div', 'zsch-w-track');
			for (var t = 1; t < 4; t++) {
				var tick = el('div', 'zsch-w-tick');
				tick.style.top = (t * 3 / DAY_SLOTS * 100) + '%';
				track.appendChild(tick);
			}
			cell.appendChild(track);

			renderDayBar(track, dayHourState(d, state.me));
			grid.appendChild(cell);
		});
		// (Re)bind the grid-level drag once the cells exist.
		bindAvailDrag();
	}

	// ── Drag-to-paint across the hour grid ─────────────────────────────────
	// v1.2.0: pointer drag on the availability grid paints hour-slots. Dragging
	// within a day paints that hour span; dragging across days paints the
	// dragged hour range on each day touched. On pointerup, every dirtied day is
	// re-saved (its blocks replaced with clean contiguous runs — no "Mixed").
	var availDrag = { on: false, startCell: null, startSlot: null, dirty: {}, bound: false };

	function slotFromPointer(cell, clientY) {
		var track = cell.querySelector('.zsch-w-track');
		if (!track) return 0;
		var r = track.getBoundingClientRect();
		var f = Math.max(0, Math.min(0.9999, (clientY - r.top) / r.height));
		return Math.floor(f * DAY_SLOTS);
	}

	function paintModeVal() {
		// state.paintKind is 'open' | 'busy' | 'clear'
		return state.paintKind === 'clear' ? null : (state.paintKind === 'busy' ? 'busy' : 'open');
	}

	// Apply a slot range to a cell's LIVE slot model + repaint (no server yet).
	function applyPaint(cell, a, b) {
		if (!cell || !cell.dataset.date) return;
		var key = cell.dataset.date;
		if (!availDrag.dirty[key]) {
			// Snapshot the current slots for this day so we can re-save it whole.
			var d = parseYmd(key);
			var cur = dayHourState(d, state.me);
			// v1.4.0 (D3): keep the PRE-gesture model too — it powers the
			// overwrite-confirm chip and the 5s Undo toast (SC 2.5.2 abort/undo).
			availDrag.dirty[key] = { d: d, slots: cur, orig: cur.slice() };
		}
		var model = availDrag.dirty[key].slots;
		var lo = Math.min(a, b), hi = Math.max(a, b);
		var val = paintModeVal();
		for (var i = lo; i <= hi; i++) if (i >= 0 && i < DAY_SLOTS) model[i] = val;
		var track = cell.querySelector('.zsch-w-track');
		if (track) renderDayBar(track, model);
	}

	// Find the availability cell under a viewport point. Uses elementsFromPoint
	// (plural) so it still resolves the cell even when an ancestor with
	// overflow:clip (the theme's .view / .dash-widget-body) is the topmost hit —
	// we scan the stack for the first cell rather than trusting the single top
	// element. Used only for CROSS-DAY drag tracking; the single-day path relies
	// on pointer capture, not hit-testing.
	function cellUnderPoint(x, y) {
		var list = document.elementsFromPoint(x, y) || [];
		for (var i = 0; i < list.length; i++) {
			var c = list[i].closest ? list[i].closest('.zsch-w-cell--avail') : null;
			if (c) return c;
		}
		// Fallback: brute-force test each cell's rect (robust under any clipping).
		var cells = document.querySelectorAll('#zsch-avail-grid .zsch-w-cell--avail');
		for (var k = 0; k < cells.length; k++) {
			var r = cells[k].getBoundingClientRect();
			if (x >= r.left && x <= r.right && y >= r.top && y <= r.bottom) return cells[k];
		}
		return null;
	}

	// ═════════════════════════════════════════════════════════════════
	// v1.4.0 (D3) — availability interaction support module
	// ═════════════════════════════════════════════════════════════════

	// Deliberate multi-day painting is RETIRED for now (owner directive Jul 2);
	// kept behind this constant so a future release can re-enable it as an
	// explicit mode. Do not flip without an on-screen mode toggle.
	var MULTIDAY_PAINT = false;

	function prettyDate(key) {
		var d = parseYmd(key);
		return d.toLocaleDateString(undefined, { weekday: 'short', month: 'short', day: 'numeric' });
	}

	// ── press-progress ring (visual stand-in for haptics; iOS Safari has no
	//    Vibration API). A small ring that fills over LP_MS at the touch point. ──
	var pressRingEl = null;
	function showPressRing(cell, x, y) {
		hidePressRing();
		var r = document.createElement('div');
		r.className = 'zsch-press-ring';
		r.style.left = x + 'px';
		r.style.top  = y + 'px';
		document.body.appendChild(r);
		pressRingEl = r;
	}
	function hidePressRing() {
		if (pressRingEl && pressRingEl.parentNode) pressRingEl.parentNode.removeChild(pressRingEl);
		pressRingEl = null;
	}

	// ── one-time long-press hint (discoverability; shown max twice) ──
	function showLongPressHintOnce() {
		var n = 0;
		try { n = parseInt(localStorage.getItem('zsch_lp_hint') || '0', 10) || 0; } catch (e) {}
		if (n >= 2) return;
		try { localStorage.setItem('zsch_lp_hint', String(n + 1)); } catch (e) {}
		actionToast('Tip: press & hold any day to edit its hours precisely.', [], 6000);
	}

	// ── action toast: message + optional buttons, auto-dismiss ──
	var actionToastEl = null;
	function actionToast(msg, actions, ttlMs) {
		dismissActionToast();
		var t = document.createElement('div');
		t.className = 'zsch-action-toast';
		t.setAttribute('role', 'status');
		var span = document.createElement('span');
		span.textContent = msg;
		t.appendChild(span);
		(actions || []).forEach(function (a) {
			var b = document.createElement('button');
			b.type = 'button';
			b.className = 'zsch-at-btn';
			b.textContent = a.label;
			b.addEventListener('click', function () { dismissActionToast(); a.fn(); });
			t.appendChild(b);
		});
		document.body.appendChild(t);
		actionToastEl = t;
		setTimeout(function () { if (actionToastEl === t) dismissActionToast(); }, ttlMs || 5000);
	}
	function dismissActionToast() {
		if (actionToastEl && actionToastEl.parentNode) actionToastEl.parentNode.removeChild(actionToastEl);
		actionToastEl = null;
	}

	// ── save ONE day's slot model (delete existing blocks → POST contiguous
	//    runs). Extracted from commitDirtyDays so the Day Editor's Save and the
	//    Undo path reuse the exact proven v1.2.2 wire format. ──
	function saveDayModel(d, slots) {
		var existing = blocksOnDay(d, state.me);
		var del = existing.map(function (b) { return api('/availability/' + b.id, { method: 'DELETE' }); });
		return Promise.all(del).then(function () {
			var creates = [];
			var i = 0;
			while (i < DAY_SLOTS) {
				var v = slots[i], j = i;
				while (j < DAY_SLOTS && slots[j] === v) j++;
				if (v) {
					var startLocal = ymd(d) + ' ' + pad(DAY_START_H + i) + ':00';
					var endLocal   = ymd(d) + ' ' + pad(DAY_START_H + j) + ':00';
					creates.push(api('/availability', {
						method: 'POST',
						body: {
							kind: (v === 'busy') ? 'busy' : 'open',
							start_local: startLocal,
							end_local: endLocal,
							is_all_day: false,
							time_zone: D.tz
						}
					}));
				}
				i = j;
			}
			return Promise.all(creates);
		});
	}

	// ── overwrite guard: if this gesture REPLACES previously-set hours, ask
	//    with an inline chip before saving; otherwise commit immediately.
	//    (SC 2.5.2 — completion on explicit up-event, plus abort/undo.) ──
	function maybeConfirmThenCommit(cell) {
		var key = cell && cell.dataset ? cell.dataset.date : '';
		var entry = key ? availDrag.dirty[key] : null;
		if (!entry) { commitDirtyDays(); return; }
		var origHad = false, changed = false;
		for (var i = 0; i < DAY_SLOTS; i++) {
			if (entry.orig[i]) origHad = true;
			if (entry.slots[i] !== entry.orig[i]) changed = true;
		}
		if (!changed) { availDrag.dirty = {}; return; } // no-op gesture
		if (!origHad) { commitDirtyDays(); return; }    // blank day — nothing to overwrite

		// Replacing existing hours → 1-tap confirm chip on the cell.
		var old = cell.querySelector('.zsch-confirm-chip');
		if (old) old.parentNode.removeChild(old);
		var chip = document.createElement('div');
		chip.className = 'zsch-confirm-chip';
		var q = document.createElement('span');
		q.textContent = 'Overwrite?';
		var ok = document.createElement('button');
		ok.type = 'button'; ok.className = 'zsch-chip-ok'; ok.textContent = '✓';
		ok.setAttribute('aria-label', 'Confirm overwrite');
		var no = document.createElement('button');
		no.type = 'button'; no.className = 'zsch-chip-no'; no.textContent = '✕';
		no.setAttribute('aria-label', 'Cancel');
		chip.appendChild(q); chip.appendChild(ok); chip.appendChild(no);
		cell.appendChild(chip);
		var cleanup = function () { if (chip.parentNode) chip.parentNode.removeChild(chip); };
		var restore = function () {
			var track = cell.querySelector('.zsch-w-track');
			if (track) renderDayBar(track, entry.orig);
			delete availDrag.dirty[key];
			cleanup();
		};
		ok.addEventListener('click', function () { cleanup(); commitDirtyDays(); });
		no.addEventListener('click', restore);
		setTimeout(function () { if (chip.parentNode) restore(); }, 6000); // timeout = safe default: keep what was there
	}

	// ── DAY EDITOR ("press on 2 and hold it… a big box where I can now drag
	//    more granularly"). Full-width hour track, Open/Busy/Clear kinds,
	//    explicit Save/Cancel — nothing writes until Save. ──
	function openDayEditor(key) {
		var d     = parseYmd(key);
		var model = dayHourState(d, state.me);
		var orig  = model.slice();
		var kind  = (state.paintKind === 'clear') ? 'open' : state.paintKind; // editor default: paint something

		var back = document.createElement('div');
		back.className = 'zsch-de-backdrop';
		var panel = document.createElement('div');
		panel.className = 'zsch-de';
		panel.setAttribute('role', 'dialog');
		panel.setAttribute('aria-label', 'Edit hours for ' + prettyDate(key));

		var head = document.createElement('div');
		head.className = 'zsch-de-head';
		var title = document.createElement('strong');
		title.textContent = prettyDate(key);
		var close = document.createElement('button');
		close.type = 'button'; close.className = 'zsch-de-close'; close.textContent = '✕';
		close.setAttribute('aria-label', 'Cancel');
		head.appendChild(title); head.appendChild(close);

		var kinds = document.createElement('div');
		kinds.className = 'zsch-de-kinds';
		[['open', 'Open'], ['busy', 'Busy'], ['clear', 'Clear']].forEach(function (k) {
			var b = document.createElement('button');
			b.type = 'button';
			b.className = 'zsch-de-kind' + (k[0] === kind ? ' is-on' : '');
			b.dataset.kind = k[0];
			b.textContent = k[1];
			b.addEventListener('click', function () {
				kind = k[0];
				kinds.querySelectorAll('.zsch-de-kind').forEach(function (x) { x.classList.remove('is-on'); });
				b.classList.add('is-on');
			});
			kinds.appendChild(b);
		});

		var track = document.createElement('div');
		track.className = 'zsch-de-track';
		var rows = [];
		for (var i = 0; i < DAY_SLOTS; i++) {
			var row = document.createElement('div');
			row.className = 'zsch-de-slot';
			row.dataset.slot = String(i);
			var lab = document.createElement('span');
			lab.className = 'zsch-de-lab';
			var h = DAY_START_H + i;
			lab.textContent = (h % 12 === 0 ? 12 : h % 12) + (h < 12 ? 'am' : 'pm');
			row.appendChild(lab);
			track.appendChild(row);
			rows.push(row);
		}
		function paintRows() {
			rows.forEach(function (row, i) {
				row.classList.toggle('is-open', model[i] === 'open');
				row.classList.toggle('is-busy', model[i] === 'busy');
			});
		}
		paintRows();

		var deDrag = { on: false, anchor: 0, snap: null };
		function slotFromY(y) {
			var r = track.getBoundingClientRect();
			var f = Math.min(0.999, Math.max(0, (y - r.top) / r.height));
			return Math.floor(f * DAY_SLOTS);
		}
		function applyRange(a, b) {
			var lo = Math.min(a, b), hi = Math.max(a, b);
			for (var i = 0; i < DAY_SLOTS; i++) model[i] = (i >= lo && i <= hi)
				? (kind === 'clear' ? null : kind)
				: deDrag.snap[i];
			paintRows();
		}
		track.addEventListener('pointerdown', function (e) {
			deDrag.on = true;
			deDrag.anchor = slotFromY(e.clientY);
			deDrag.snap = model.slice();
			applyRange(deDrag.anchor, deDrag.anchor);
			try { track.setPointerCapture(e.pointerId); } catch (x) {}
			e.preventDefault();
		});
		track.addEventListener('pointermove', function (e) {
			if (!deDrag.on) return;
			e.preventDefault();
			applyRange(deDrag.anchor, slotFromY(e.clientY));
		});
		var deEnd = function () { deDrag.on = false; deDrag.snap = null; };
		track.addEventListener('pointerup', deEnd);
		track.addEventListener('pointercancel', deEnd);

		var note = document.createElement('div');
		note.className = 'zsch-de-note';
		note.textContent = 'Drag across hours to paint. Appointments are not shown here.';

		var foot = document.createElement('div');
		foot.className = 'zsch-de-foot';
		var cancel = document.createElement('button');
		cancel.type = 'button'; cancel.className = 'zsch-de-cancel'; cancel.textContent = 'Cancel';
		var save = document.createElement('button');
		save.type = 'button'; save.className = 'zsch-de-save'; save.textContent = 'Save';
		foot.appendChild(cancel); foot.appendChild(save);

		panel.appendChild(head); panel.appendChild(kinds); panel.appendChild(track);
		panel.appendChild(note); panel.appendChild(foot);
		back.appendChild(panel);
		document.body.appendChild(back);

		var closeAll = function () { if (back.parentNode) back.parentNode.removeChild(back); };
		close.addEventListener('click', closeAll);
		cancel.addEventListener('click', closeAll);
		back.addEventListener('click', function (e) { if (e.target === back) closeAll(); });

		save.addEventListener('click', function () {
			save.disabled = true;
			save.textContent = 'Saving…';
			saveDayModel(d, model)
				.then(function () { return loadAvailabilityMine(); })
				.then(renderAvailability)
				.then(function () {
					closeAll();
					actionToast(prettyDate(key) + ' saved', [
						{ label: 'Undo', fn: function () {
							saveDayModel(d, orig)
								.then(function () { return loadAvailabilityMine(); })
								.then(renderAvailability)
								.then(function () { toast('Restored'); });
						} }
					], 5000);
				})
				.catch(function () {
					save.disabled = false;
					save.textContent = 'Save';
					toast('Could not save hours', true);
				});
		});
	}

	// v1.2.2: bind the drag DIRECTLY to each cell with pointer capture, instead
	// of one grid-level listener that depended on document.elementFromPoint
	// (which fails inside the theme's nested overflow:clip scrollers — the cell
	// painted but pointerup/commit never fired, so edits weren't saved). With
	// setPointerCapture, once a drag begins on a cell, every move/up routes to
	// that cell regardless of overlays.
	//
	// v1.4.0 (D3): ONE DAY PER GESTURE + LONG-PRESS DAY EDITOR + OVERWRITE GUARD.
	// Owner directive (Jul 2 mobile review): "if I just drag my finger across,
	// it does tons of selections that might be overwriting other ones… For now,
	// it's one press per day, and you have to … pick up your thumb to get the
	// next day." The v1.2.2 cross-day rectangle is RETIRED behind MULTIDAY_PAINT
	// (default OFF) — a gesture affects ONLY the day it started on; vertical
	// drag still sets the hour range within that day. NEW: press-and-hold
	// (500ms, 10px slop) opens the full-width DAY EDITOR ("press on 2 and hold
	// it, it should bring up 2 as a big box where I can drag more granularly");
	// a ✎ Edit-hours chip after any paint is the always-visible route to the
	// same editor (long-press is never the only path — discoverability). iOS
	// playbook: implicit capture on the pressed cell only (never transferred to
	// a container — WebKit retargeting is unreliable); -webkit-touch-callout +
	// user-select suppressed in CSS; a visual press-progress ring stands in for
	// haptics (no Vibration API in iOS Safari); pointercancel = Safari claimed
	// the gesture for scrolling → abort with NOTHING painted or saved.
	var LP_MS      = 500; // long-press trigger (iOS system default ≈0.5s)
	var LP_SLOP_PX = 10;  // movement that cancels the long-press (iOS allowableMovement)

	function bindAvailDrag() {
		if (!D.canWrite) return;
		var cells = document.querySelectorAll('#zsch-avail-grid .zsch-w-cell--avail');
		Array.prototype.forEach.call(cells, function (cell) {
			if (cell._zschBound) return;
			cell._zschBound = true;

			// D3: VoiceOver — name the day and both interactions.
			if (cell.dataset.date && !cell.getAttribute('aria-label')) {
				cell.setAttribute('role', 'button');
				cell.setAttribute('aria-label', prettyDate(cell.dataset.date) +
					' availability. Tap or drag to paint hours. Press and hold to edit hours precisely.');
			}

			var lp = { timer: null, fired: false, x0: 0, y0: 0, painted: false };

			// Android long-press context menu; iOS never fires contextmenu (the
			// CSS -webkit-touch-callout:none handles the iOS callout instead).
			cell.addEventListener('contextmenu', function (e) { e.preventDefault(); });

			cell.addEventListener('pointerdown', function (e) {
				if (cell.classList.contains('is-out') || !cell.dataset.date) return;
				availDrag.on        = true;
				availDrag.startCell = cell;
				availDrag.startSlot = slotFromPointer(cell, e.clientY);
				lp.fired = false; lp.painted = false; lp.x0 = e.clientX; lp.y0 = e.clientY;
				try { cell.setPointerCapture(e.pointerId); } catch (x) {}
				// Paint is DEFERRED until the press proves it's a paint (moves or
				// lifts) rather than a long-press — opening the editor must never
				// half-paint the day underneath it.
				showPressRing(cell, e.clientX, e.clientY);
				lp.timer = setTimeout(function () {
					lp.fired            = true;
					availDrag.on        = false;
					availDrag.startCell = null;
					availDrag.dirty     = {};
					hidePressRing();
					openDayEditor(cell.dataset.date);
				}, LP_MS);
				e.preventDefault();
			});

			cell.addEventListener('pointermove', function (e) {
				if (!availDrag.on || lp.fired) return;
				e.preventDefault();
				var moved = Math.abs(e.clientX - lp.x0) > LP_SLOP_PX
					|| Math.abs(e.clientY - lp.y0) > LP_SLOP_PX;
				if (!moved && !lp.painted) return; // still a candidate long-press
				if (lp.timer) { clearTimeout(lp.timer); lp.timer = null; hidePressRing(); }
				if (!lp.painted) {
					lp.painted = true;
					applyPaint(cell, availDrag.startSlot, availDrag.startSlot);
				}
				if (MULTIDAY_PAINT) {
					// Deliberate multi-day mode (flagged OFF; a future release may
					// re-enable behind an explicit user toggle per the owner note
					// "later, when we do click-to-drag, maybe we'll do other things").
					var over = cellUnderPoint(e.clientX, e.clientY) || availDrag.startCell;
					if (over !== availDrag.startCell && over.dataset.date) {
						var curSlot = slotFromPointer(over, e.clientY);
						applyPaint(availDrag.startCell, availDrag.startSlot, DAY_SLOTS - 1);
						paintBetweenDays(availDrag.startCell, over);
						applyPaint(over, 0, curSlot);
						return;
					}
				}
				// ONE DAY PER GESTURE: movement outside the origin cell is ignored;
				// vertical drag adjusts the hour range WITHIN the pressed day only.
				applyPaint(availDrag.startCell, availDrag.startSlot, slotFromPointer(availDrag.startCell, e.clientY));
			});

			var end = function (e) {
				if (lp.timer) { clearTimeout(lp.timer); lp.timer = null; }
				hidePressRing();
				if (lp.fired) return;      // the Day Editor owns this gesture
				if (!availDrag.on) return;
				availDrag.on = false;
				var origin = availDrag.startCell || cell;
				availDrag.startCell = null;
				try { cell.releasePointerCapture(e.pointerId); } catch (x) {}
				if (e.type === 'pointercancel') {
					// Safari claimed the gesture (scroll) — abort with no changes.
					availDrag.dirty = {};
					return;
				}
				if (!lp.painted) {
					// Clean tap (no movement, released before the long-press fired):
					// paint the tapped hour slot — the original v1.2.2 tap behavior.
					applyPaint(origin, availDrag.startSlot, availDrag.startSlot);
				}
				maybeConfirmThenCommit(origin);
			};
			cell.addEventListener('pointerup', end);
			cell.addEventListener('pointercancel', end);
		});
		showLongPressHintOnce();
	}

	// Paint the full 8am–8pm window on every in-month day strictly between two
	// cells (used when a drag spans more than two days). Order-independent.
	function paintBetweenDays(cellA, cellB) {
		var a = parseYmd(cellA.dataset.date).getTime();
		var b = parseYmd(cellB.dataset.date).getTime();
		var lo = Math.min(a, b), hi = Math.max(a, b);
		var cells = document.querySelectorAll('#zsch-avail-grid .zsch-w-cell--avail');
		Array.prototype.forEach.call(cells, function (c) {
			if (!c.dataset.date || c.classList.contains('is-out')) return;
			var t = parseYmd(c.dataset.date).getTime();
			if (t > lo && t < hi) applyPaint(c, 0, DAY_SLOTS - 1);
		});
	}

	// Re-save every day touched by the drag: delete its existing blocks, then
	// create one block per contiguous run of the same kind (open/busy). Cleared
	// slots become no block at all. This is what guarantees a clean, Mixed-free
	// representation that round-trips with the server.
	function commitDirtyDays() {
		var keys = Object.keys(availDrag.dirty);
		if (!keys.length) return;
		var work = availDrag.dirty;
		availDrag.dirty = {};

		// v1.4.0 (D3): the per-day delete+create wire logic lives in
		// saveDayModel() (shared with the Day Editor's Save and with Undo).
		var ops = keys.map(function (key) {
			var entry = work[key];
			return saveDayModel(entry.d, entry.slots);
		});

		Promise.all(ops)
			.then(function () { return loadAvailabilityMine(); })
			.then(renderAvailability)
			.then(function () {
				// v1.4.0 (D3): saved-toast gains UNDO (restores the pre-gesture
				// hours — SC 2.5.2 abort/undo, and the owner's overwrite worry)
				// and ✎ EDIT HOURS (the always-visible route to the Day Editor,
				// so long-press is never the only path).
				var label = keys.length === 1 ? prettyDate(keys[0]) + ' saved' : 'Availability saved';
				actionToast(label, [
					{ label: '✎ Edit hours', fn: function () { openDayEditor(keys[0]); } },
					{ label: 'Undo', fn: function () {
						var undos = keys.map(function (key) {
							return saveDayModel(work[key].d, work[key].orig);
						});
						Promise.all(undos)
							.then(function () { return loadAvailabilityMine(); })
							.then(renderAvailability)
							.then(function () { toast('Restored'); })
							.catch(function () { toast('Could not restore', true); });
					} }
				], 5000);
			})
			.catch(function () { toast('Could not save availability', true); loadAvailabilityMine().then(renderAvailability); });
	}

	// ── rendering: team grid ───────────────────────────────────────
	function renderTeam() {
		var wrap = $('#zsch-team');
		wrap.innerHTML = '';

		// Glyph legend (color + symbol, so it's readable without color).
		var leg = el('div', 'zsch-w-glyphlegend');
		[['✓', 'is-open', 'Open'], ['✕', 'is-busy', 'Busy'], ['◐', 'is-mixed', 'Both']].forEach(function (g) {
			var item = el('span', 'zsch-w-glyphitem');
			var box = el('span', 'zsch-w-teamcell ' + g[1]);
			box.appendChild(el('span', 'zsch-w-teamglyph', g[0]));
			item.appendChild(box);
			item.appendChild(document.createTextNode(' ' + g[2]));
			leg.appendChild(item);
		});
		wrap.appendChild(leg);

		// Header row: day-of-month columns for the current month.
		var first = startOfMonth(state.cursor), last = endOfMonth(state.cursor);
		var nDays = last.getDate();

		var headRow = el('div', 'zsch-w-teamrow zsch-w-teamrow--head');
		headRow.appendChild(el('div', 'zsch-w-teamname', ''));
		var track = el('div', 'zsch-w-teamtrack');
		for (var i = 1; i <= nDays; i++) {
			var c = el('div', 'zsch-w-teamcol', String(i));
			track.appendChild(c);
		}
		headRow.appendChild(track);
		wrap.appendChild(headRow);

		if (!state.team.length) {
			wrap.appendChild(el('div', 'zsch-w-empty', 'No teammates have the Schedule app yet. Once they do, their open and busy days show here.'));
			return;
		}

		state.team.forEach(function (member) {
			var row = el('div', 'zsch-w-teamrow');
			var nameCell = el('div', 'zsch-w-teamname');
			var dot = el('span', 'zsch-w-legdot'); dot.style.background = colorForOwner(member.user_id);
			nameCell.appendChild(dot);
			nameCell.appendChild(document.createTextNode(member.name));
			row.appendChild(nameCell);

			var tr = el('div', 'zsch-w-teamtrack');
			for (var day = 1; day <= nDays; day++) {
				var dd = new Date(first.getFullYear(), first.getMonth(), day, 12, 0, 0);
				var blocks = (member.blocks || []).filter(function (b) {
					var s = parseUTC(b.start_utc), e = parseUTC(b.end_utc);
					if (!s || !e) return false;
					var ds = new Date(dd.getFullYear(), dd.getMonth(), dd.getDate(), 0, 0, 0);
					var de = new Date(dd.getFullYear(), dd.getMonth(), dd.getDate(), 23, 59, 59);
					return s <= de && e >= ds;
				});
				var cell = el('div', 'zsch-w-teamcell');
				var hasOpen = blocks.some(function (b) { return b.kind === 'open'; });
				var hasBusy = blocks.some(function (b) { return b.kind === 'busy'; });
				if (hasOpen) cell.classList.add('is-open');
				if (hasBusy) cell.classList.add('is-busy');
				// Never rely on color ALONE (accessibility): put a glyph in the cell
				// so open/busy is distinguishable without seeing color.
				//   ✓ = open, ✕ = busy, ◐ = both.
				if (hasOpen || hasBusy) {
					var glyph = (hasOpen && hasBusy) ? '◐' : (hasOpen ? '✓' : '✕');
					cell.appendChild(el('span', 'zsch-w-teamglyph', glyph));
					cell.setAttribute('aria-label', member.name + ' ' + (hasBusy && !hasOpen ? 'busy' : (hasOpen && !hasBusy ? 'open' : 'partly available')) + ' ' + MONTHS[dd.getMonth()] + ' ' + day);
					cell.title = member.name + ' — ' + (hasBusy && !hasOpen ? 'Busy' : (hasOpen && !hasBusy ? 'Open' : 'Open & busy')) + ' · ' + MONTHS[dd.getMonth()] + ' ' + day;
				}
				tr.appendChild(cell);
			}
			row.appendChild(tr);
			wrap.appendChild(row);
		});
	}

	// ── event editor modal ─────────────────────────────────────────
	function openEditorNew(day, hour) {
		if (!D.canWrite) return;
		state.editingId = null;
		$('#zsch-modal-title').textContent = 'New appointment';
		$('#zsch-delete').hidden = true;
		$('#zsch-f-title').value = '';
		// v1.5.0: the day-tier's empty hour rows pass the tapped hour.
		var h = (typeof hour === 'number' && hour >= 0 && hour <= 23) ? hour : 9;
		var start = new Date(day.getFullYear(), day.getMonth(), day.getDate(), h, 0, 0);
		var end = new Date(start); end.setHours(h + 1);
		$('#zsch-f-start').value = toInputLocal(start);
		$('#zsch-f-end').value = toInputLocal(end);
		$('#zsch-f-allday').checked = false;
		$('#zsch-f-location').value = '';
		$('#zsch-f-scope').value = (state.scope === 'shared') ? 'shared' : 'personal';
		$('#zsch-f-busy').value = 'busy';
		$('#zsch-f-body').value = '';
		$('#zsch-f-attendees').value = '';
		syncNote();
		showModal('#zsch-modal');
	}

	function openEditor(ev) {
		// Read-only viewers can open to view but not edit.
		state.editingId = ev.id;
		$('#zsch-modal-title').textContent = D.canWrite ? 'Edit appointment' : 'Appointment';
		$('#zsch-delete').hidden = !D.canWrite;
		$('#zsch-f-title').value = ev.title || '';
		var s = parseUTC(ev.start_utc), e = parseUTC(ev.end_utc);
		$('#zsch-f-start').value = s ? toInputLocal(s) : '';
		$('#zsch-f-end').value = e ? toInputLocal(e) : '';
		$('#zsch-f-allday').checked = !!ev.is_all_day;
		$('#zsch-f-location').value = ev.location || '';
		$('#zsch-f-scope').value = ev.scope || 'personal';
		$('#zsch-f-busy').value = ev.busy_status || 'busy';
		$('#zsch-f-body').value = ev.body || '';
		$('#zsch-f-attendees').value = (ev.attendees || []).join(', ');
		setEditorDisabled(!D.canWrite);
		syncNote();
		showModal('#zsch-modal');
	}

	function setEditorDisabled(disabled) {
		['#zsch-f-title', '#zsch-f-start', '#zsch-f-end', '#zsch-f-allday', '#zsch-f-location',
			'#zsch-f-scope', '#zsch-f-busy', '#zsch-f-body', '#zsch-f-attendees'].forEach(function (id) {
			$(id).disabled = disabled;
		});
		$('#zsch-save').hidden = disabled;
	}

	function syncNote() {
		var note = $('#zsch-sync-note');
		if (!D.syncOn) { note.textContent = 'Saved to Zorderz only (Outlook sync is off).'; return; }
		var scope = $('#zsch-f-scope').value;
		note.textContent = scope === 'shared'
			? 'Will appear on the team calendar and sync to your Outlook.'
			: 'Will sync to your Outlook / Exchange calendar.';
	}

	function collectEditor() {
		var allDay = $('#zsch-f-allday').checked;
		var startV = $('#zsch-f-start').value;
		var endV = $('#zsch-f-end').value;
		var attendees = $('#zsch-f-attendees').value.split(',').map(function (x) { return x.trim(); }).filter(Boolean);
		return {
			title: $('#zsch-f-title').value.trim(),
			body: $('#zsch-f-body').value.trim(),
			location: $('#zsch-f-location').value.trim(),
			start_local: fromInputLocal(startV),
			end_local: fromInputLocal(endV),
			is_all_day: allDay,
			calendar_scope: $('#zsch-f-scope').value,
			busy_status: $('#zsch-f-busy').value,
			attendees: attendees,
			time_zone: D.tz
		};
	}

	function saveEditor() {
		var data = collectEditor();
		if (!data.title) { toast('Give it a title', true); return; }
		if (!data.start_local || !data.end_local) { toast('Set a start and end', true); return; }

		var p = state.editingId
			? api('/events/' + state.editingId, { method: 'PATCH', body: data })
			: api('/events', { method: 'POST', body: data });

		$('#zsch-save').disabled = true;
		p.then(function (res) {
			$('#zsch-save').disabled = false;
			if (res && res.success) {
				hideModal('#zsch-modal');
				var synced = res.graph && res.graph.success && !res.graph.skipped;
				/* v1.5.3: confirmation carries the WHAT and WHEN, not a bare
				 * "Saved" — and lingers longer (5s) so it can't be missed. */
				var when = '';
				try {
					var sd = new Date(data.start_local), ed = new Date(data.end_local);
					if (!isNaN(sd) && !isNaN(ed)) {
						var dayStr = sd.toLocaleDateString(undefined, { weekday: 'short', month: 'short', day: 'numeric' });
						var tOpt = { hour: 'numeric', minute: '2-digit' };
						when = ' \u2014 ' + dayStr + ', ' + sd.toLocaleTimeString(undefined, tOpt)
							+ '\u2013' + ed.toLocaleTimeString(undefined, tOpt);
					}
				} catch (e) {}
				var verb = state.editingId ? 'Updated' : 'Saved';
				toast(verb + when + (synced ? ' \u00b7 synced to Outlook' : ''), false, 5000);
				refreshActiveView();
			} else {
				toast((res && res.error) || 'Could not save', true);
			}
		});
	}

	function deleteEditor() {
		if (!state.editingId) return;
		if (!window.confirm('Delete this appointment?')) return;
		api('/events/' + state.editingId, { method: 'DELETE' }).then(function (res) {
			if (res && res.success) {
				hideModal('#zsch-modal');
				toast('Deleted');
				refreshActiveView();
			} else {
				toast((res && res.error) || 'Could not delete', true);
			}
		});
	}

	// ── dictation ──────────────────────────────────────────────────
	var dictationSegments = [];

	function openDictate() {
		$('#zsch-dictate-input').value = '';
		$('#zsch-dictate-preview').innerHTML = '';
		dictationSegments = [];

		// Device-aware tip for the DEVICE's own dictation (no in-app recorder).
		var tip = $('#zsch-dictate-tip');
		if (tip) { tip.textContent = nativeDictationTip(); }

		showModal('#zsch-dictate-modal');

		// Auto-focus the field so the device keyboard opens. On iPad/iPhone the
		// keyboard's microphone key is right there; on a Mac the user presses
		// their own Dictation shortcut. We do NOT run any in-app speech recorder.
		// A short delay lets the modal paint before focusing (iOS needs this, and
		// it must be inside the user-gesture that opened the modal to pop the
		// keyboard reliably).
		var input = $('#zsch-dictate-input');
		if (input) {
			setTimeout(function () { try { input.focus(); } catch (e) {} }, 60);
		}
	}

	// A one-line hint telling the user how to start their device's dictation.
	function nativeDictationTip() {
		var ua = navigator.userAgent || '';
		var isiOS = /iPad|iPhone|iPod/.test(ua) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
		var isMac = /Macintosh|Mac OS X/.test(ua) && !isiOS;
		if (isiOS) {
			return 'Tip: tap the 🎤 on your keyboard, then speak. Tap Apply when done.';
		}
		if (isMac) {
			return 'Tip: press your Dictation key (Fn Fn, or 🎤) and speak, then Apply.';
		}
		return 'Tip: use your keyboard\'s voice-typing button and speak, then Apply.';
	}

	function parseDictation(text) {
		// Client-side parse of common phrases; falls back to server parser on apply.
		text = (text || '').toLowerCase().trim();
		if (!text) return [];
		var kind = /\b(busy|book|blocked?|unavailable|out)\b/.test(text) ? 'busy' : 'open';
		var segs = [];

		// "<date> to/through/- <date>"
		var range = text.match(/(.+?)\s*(?:to|through|thru|until|-|–|—)\s*(.+)/);
		if (range) {
			var a = softDate(range[1]), b = softDate(range[2]);
			if (a && b) { segs.push({ kind: kind, start_local: a, end_local: b }); return segs; }
		}
		var single = softDate(text);
		if (single) { segs.push({ kind: kind, start_local: single, end_local: single }); }
		return segs;
	}

	function softDate(frag) {
		frag = frag.replace(/\b(open|busy|book(ed)?|blocked?|mark me|i am|i'?m|available|free|unavailable|out|on|the)\b/g, '').trim();
		if (!frag) return '';

		var now = new Date();
		var thisYear = now.getFullYear();

		// weekday words → next occurrence (always this/next week, correct year)
		var wd = ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'];
		for (var i = 0; i < wd.length; i++) {
			if (frag.indexOf(wd[i]) !== -1) {
				var diff = (i - now.getDay() + 7) % 7; if (diff === 0) diff = 7;
				if (frag.indexOf('next') !== -1) diff += 7;
				var nd = new Date(now.getFullYear(), now.getMonth(), now.getDate() + diff);
				return ymd(nd);
			}
		}
		// "today" / "tomorrow"
		if (/\btoday\b/.test(frag)) { return ymd(now); }
		if (/\btomorrow\b/.test(frag)) { var t = new Date(now.getFullYear(), now.getMonth(), now.getDate() + 1); return ymd(t); }

		// Month-name dates ("june 16", "jun 16"): parse WITH the current year
		// appended FIRST so JS doesn't default to a bogus year (the old code tried
		// the bare string first and "june 16" parsed as the year 2001 — which is
		// why dictated availability saved into the wrong year and never showed).
		// If the resulting date is already in the past this year, roll to next year.
		var candidates = [
			frag + ' ' + thisYear,
			frag,
			frag + ' ' + (thisYear + 1)
		];
		for (var j = 0; j < candidates.length; j++) {
			var d = new Date(candidates[j]);
			if (isNaN(d.getTime())) { continue; }
			var y = d.getFullYear();
			// Reject implausible years (the 2001 bug). Accept this year ± 1 only.
			if (y < thisYear - 1 || y > thisYear + 1) {
				// Force the current year onto the parsed month/day.
				d = new Date(thisYear, d.getMonth(), d.getDate());
				y = thisYear;
			}
			// If a bare month/day landed clearly in the past (> 1 month ago),
			// assume they mean the upcoming one next year.
			var ageDays = (now - d) / 86400000;
			if (ageDays > 32) {
				d = new Date(thisYear + 1, d.getMonth(), d.getDate());
			}
			return ymd(d);
		}
		return '';
	}

	function renderDictatePreview() {
		var box = $('#zsch-dictate-preview');
		box.innerHTML = '';
		if (!dictationSegments.length) { box.appendChild(el('span', 'zsch-w-hint', 'No dates detected yet.')); return; }
		dictationSegments.forEach(function (s) {
			var chip = el('div', 'zsch-w-segchip is-' + s.kind);
			chip.textContent = (s.kind === 'busy' ? 'Busy ' : 'Open ') + s.start_local + (s.end_local !== s.start_local ? ' → ' + s.end_local : '');
			box.appendChild(chip);
		});
	}

	function applyDictation() {
		var text = $('#zsch-dictate-input').value;
		if (!dictationSegments.length) { dictationSegments = parseDictation(text); }
		api('/availability/dictate', {
			method: 'POST',
			body: { segments: dictationSegments, text: text, time_zone: D.tz }
		}).then(function (res) {
			if (res && res.success && res.created > 0) {
				hideModal('#zsch-dictate-modal');
				toast('Set ' + res.created + ' availability block' + (res.created > 1 ? 's' : ''));
				if (state.view === 'availability') { loadAvailabilityMine().then(renderAvailability); }
				else if (state.view === 'team') { loadTeam().then(renderTeam); }
			} else {
				toast((res && res.error) || 'Could not understand those dates', true);
			}
		});
	}

	// NOTE: We intentionally do NOT use the Web Speech API / any in-app speech
	// recorder. Dictation is delegated to the DEVICE's native input (the keyboard
	// microphone on iPad/iPhone, the Dictation shortcut on macOS). The text the
	// device produces lands in #zsch-dictate-input via its normal 'input' event,
	// which we already parse. This is more reliable, more private, and follows the
	// principle of least astonishment (it's the dictation users already know).

	// ── modal plumbing ─────────────────────────────────────────────
	function showModal(sel) { $(sel).hidden = false; document.addEventListener('keydown', escClose); }
	function hideModal(sel) { $(sel).hidden = true; document.removeEventListener('keydown', escClose); }
	function escClose(e) { if (e.key === 'Escape') { $all('.zsch-w-modal').forEach(function (m) { m.hidden = true; }); } }

	// ── view switching ─────────────────────────────────────────────
	function setView(v) {
		state.view = v;
		$all('.zsch-w-tab').forEach(function (t) { t.classList.toggle('is-active', t.dataset.view === v); });
		$all('.zsch-w-pane').forEach(function (p) { p.classList.remove('is-active'); p.hidden = true; });
		var filters = $('#zsch-filters');
		if (filters) { filters.style.display = (v === 'month') ? '' : 'none'; }
		var pane = v === 'month' ? '#zsch-pane-month' : (v === 'availability' ? '#zsch-pane-availability' : '#zsch-pane-team');
		var paneEl = $(pane);
		if (paneEl) { paneEl.classList.add('is-active'); paneEl.hidden = false; }
		refreshActiveView();
	}

	function refreshActiveView() {
		renderPeriod();
		// Draw the structure SYNCHRONOUSLY first so the grid always appears even
		// before (or without) data — then overlay data when it arrives. This is
		// why the calendar can never render blank again: the cells exist
		// immediately; events just populate into them.
		if (state.view === 'month') {
			renderMonth();                              // empty grid now
			loadEvents().then(renderMonth);             // repaint with events
		} else if (state.view === 'availability') {
			renderAvailability();
			loadAvailabilityMine().then(renderAvailability);
		} else {
			renderTeam();
			loadTeam().then(renderTeam);
		}
	}

	// ── wire up ────────────────────────────────────────────────────
	function bind() {
		$('#zsch-prev').addEventListener('click', function () {
			if (V2 && state.view === 'month') { cascMonthStep(-1); return; }
			state.cursor = addMonths(state.cursor, -1); refreshActiveView();
		});
		$('#zsch-next').addEventListener('click', function () {
			if (V2 && state.view === 'month') { cascMonthStep(1); return; }
			state.cursor = addMonths(state.cursor, 1); refreshActiveView();
		});
		$('#zsch-today').addEventListener('click', function () {
			if (V2 && state.view === 'month') { cascToday(); return; }
			state.cursor = startOfMonth(new Date()); refreshActiveView();
		});
		bindCascade();

		$all('.zsch-w-tab').forEach(function (t) { t.addEventListener('click', function () { setView(t.dataset.view); }); });

		$all('.zsch-w-chip').forEach(function (c) {
			c.addEventListener('click', function () {
				$all('.zsch-w-chip').forEach(function (x) { x.classList.remove('is-active'); });
				c.classList.add('is-active');
				state.scope = c.dataset.scope;
				renderLegend();
				loadEvents().then(renderMonth);
			});
		});

		var addBtn = $('#zsch-add');
		if (addBtn) addBtn.addEventListener('click', function () { openEditorNew(new Date()); });

		var syncBtn = $('#zsch-sync');
		if (syncBtn) syncBtn.addEventListener('click', function () {
			syncBtn.classList.add('is-spinning');
			api('/sync/now', { method: 'POST' }).then(function (res) {
				syncBtn.classList.remove('is-spinning');
				if (res && res.success) { toast('Pulled ' + (res.pulled || 0) + ' from Outlook'); refreshActiveView(); }
				else { toast((res && res.error) || 'Sync failed', true); }
			});
		});

		// Day-detail modal
		var dayClose = $('#zsch-day-close'), dayDone = $('#zsch-day-done');
		if (dayClose) { dayClose.addEventListener('click', function () { hideModal('#zsch-day-modal'); }); }
		if (dayDone) { dayDone.addEventListener('click', function () { hideModal('#zsch-day-modal'); }); }

		// Editor modal
		$('#zsch-modal-close').addEventListener('click', function () { hideModal('#zsch-modal'); });
		$('#zsch-cancel').addEventListener('click', function () { hideModal('#zsch-modal'); });
		$('#zsch-save').addEventListener('click', saveEditor);
		$('#zsch-delete').addEventListener('click', deleteEditor);
		$('#zsch-f-scope').addEventListener('change', syncNote);
		$('#zsch-f-allday').addEventListener('change', function () {
			var on = $('#zsch-f-allday').checked;
			$('#zsch-f-start').type = on ? 'date' : 'datetime-local';
			$('#zsch-f-end').type = on ? 'date' : 'datetime-local';
		});

		// Availability paint mode pills (v1.2.0: open / busy / clear)
		$('#zsch-paint-open').addEventListener('click', function () { setPaint('open'); });
		$('#zsch-paint-busy').addEventListener('click', function () { setPaint('busy'); });
		var clearBtn = $('#zsch-paint-clear');
		if (clearBtn) clearBtn.addEventListener('click', function () { setPaint('clear'); });
		$('#zsch-dictate').addEventListener('click', openDictate);

		// Dictation modal (native device dictation — no in-app recorder)
		$('#zsch-dictate-close').addEventListener('click', function () { hideModal('#zsch-dictate-modal'); });
		$('#zsch-dictate-cancel').addEventListener('click', function () { hideModal('#zsch-dictate-modal'); });
		$('#zsch-dictate-apply').addEventListener('click', applyDictation);
		var dictateInput = $('#zsch-dictate-input');
		if (dictateInput) {
			dictateInput.addEventListener('input', function () {
				dictationSegments = parseDictation(this.value);
				renderDictatePreview();
			});
			// Enter applies (so after speaking, the keyboard "done"/return submits).
			dictateInput.addEventListener('keydown', function (e) {
				if (e.key === 'Enter') { e.preventDefault(); applyDictation(); }
			});
		}
	}

	function setPaint(kind) {
		// v1.2.0: three paint modes — open (Available) | busy | clear (unset).
		state.paintKind = kind;
		var map = { open: '#zsch-paint-open', busy: '#zsch-paint-busy', clear: '#zsch-paint-clear' };
		Object.keys(map).forEach(function (k) {
			var btn = $(map[k]);
			if (btn) {
				var active = (k === kind);
				btn.classList.toggle('is-active', active);
				btn.setAttribute('aria-pressed', active ? 'true' : 'false');
			}
		});
		var label = $('#zsch-avail-mode');
		if (label) {
			label.textContent = 'Painting: ' + (kind === 'open' ? 'Available' : (kind === 'busy' ? 'Busy' : 'Clearing'));
		}
	}


	// ═════════════════════════════════════════════════════════════════
	// D2 CASCADE CALENDAR (v1.5.0) — owner design, plan v2 §D2.
	//   Line 1: month strip — 6 week-span buttons of day ticks. Tap a week
	//           → it loads into line 2. ‹ › step months.
	//   Line 2: the selected week as a 7-day row. Tap a day → line 3.
	//   Line 3: day agenda expands IN-FLOW beneath (no modal, no internal
	//           scroll — the widget grows downward per the overflow contract).
	// One padded month window from /events (windowParams unchanged),
	// re-projected three ways client-side. No new endpoints.
	// Tap-driven; swipe is enhancement-only (iOS edge guard, scroll-wins).
	// ═════════════════════════════════════════════════════════════════
	var V2 = !!(D.viewsV2 && D.viewsV2 !== '0' && D.viewsV2 !== 'no');
	var casc = { weekStart: null, openDay: null };

	function startOfWeek(d) {
		var x = new Date(d.getFullYear(), d.getMonth(), d.getDate(), 12, 0, 0, 0);
		x.setDate(x.getDate() - x.getDay());
		return x;
	}
	function addDays(d, n) { var x = new Date(d); x.setDate(x.getDate() + n); return x; }
	function fmtHour(h) { var t = h % 12 || 12; return t + (h < 12 ? ' AM' : ' PM'); }

	// Which month "owns" a week = the month of its Wednesday (weekStart+3).
	// Boundary weeks (Jun 28–Jul 4) land with the month holding 4+ of their days.
	function owningMonth(weekStart) { return startOfMonth(addDays(weekStart, 3)); }

	function cascPersist() {
		try {
			localStorage.setItem('zsch_casc_v1', JSON.stringify({
				w: ymd(casc.weekStart), d: casc.openDay ? ymd(casc.openDay) : null
			}));
		} catch (e) { /* private mode — view state just won't persist */ }
	}
	function cascRestore() {
		var now = new Date();
		casc.weekStart = startOfWeek(now);
		casc.openDay = null;
		try {
			var raw = localStorage.getItem('zsch_casc_v1');
			if (raw) {
				var st = JSON.parse(raw);
				var w = st && st.w ? parseYmd(st.w) : null;
				// Only restore recent context — a months-old stored week is
				// worse than defaulting to today (62d ≈ two month windows).
				if (w && !isNaN(w.getTime()) && Math.abs(w - now) / 86400000 <= 62) {
					casc.weekStart = startOfWeek(w);
					if (st.d) {
						var dd = parseYmd(st.d);
						if (dd && !isNaN(dd.getTime()) && startOfWeek(dd).getTime() === casc.weekStart.getTime()) {
							casc.openDay = dd;
						}
					}
				}
			}
		} catch (e) { /* corrupt storage → today */ }
		state.cursor = owningMonth(casc.weekStart);
	}

	function cascWeeks() {
		var days = buildGridDays(), weeks = [];
		for (var i = 0; i < days.length; i += 7) { weeks.push(days.slice(i, i + 7)); }
		return weeks;
	}

	function renderCascade() {
		var wrap = $('#zsch-casc');
		if (!wrap) { return; }
		wrap.hidden = false;
		var gh = $('#zsch-grid-head'), gg = $('#zsch-grid');
		if (gh) { gh.hidden = true; }
		if (gg) { gg.hidden = true; }
		if (!casc.weekStart) { cascRestore(); }
		var weeks = cascWeeks();
		// Invariant (acceptance): the strip highlight ALWAYS matches line 2.
		// If the selected week isn't in the cursor month's 6-week grid (month
		// was stepped externally), snap to the week containing the 1st.
		var inGrid = false;
		for (var i = 0; i < weeks.length; i++) {
			if (weeks[i][0].getTime() === casc.weekStart.getTime()) { inGrid = true; break; }
		}
		if (!inGrid) {
			casc.weekStart = startOfWeek(startOfMonth(state.cursor));
			if (casc.openDay && startOfWeek(casc.openDay).getTime() !== casc.weekStart.getTime()) {
				casc.openDay = null;
			}
		}
		renderStrip(weeks);
		renderWeekRow();
		renderDayTier();
		cascPersist();
	}

	// ── Line 1: month strip ────────────────────────────────────────
	function renderStrip(weeks) {
		var strip = $('#zsch-casc-strip');
		if (!strip) { return; }
		strip.innerHTML = '';
		var today = new Date();
		weeks.forEach(function (w) {
			var btn = el('button', 'zsch-cs-wk');
			btn.type = 'button';
			var isSel = (w[0].getTime() === casc.weekStart.getTime());
			if (isSel) { btn.classList.add('is-sel'); }
			var evCount = 0;
			w.forEach(function (d) {
				var tick = el('span', 'zsch-cs-tick');
				if (d.getMonth() !== state.cursor.getMonth()) { tick.classList.add('is-out'); }
				var n = eventsOnDay(d).length;
				evCount += n;
				if (n > 0) { tick.classList.add('has-ev'); }
				if (n > 2) { tick.classList.add('has-many'); }
				if (sameDay(d, today)) { tick.classList.add('is-today'); }
				if (d.getDay() === 0 || d.getDate() === 1) {
					tick.classList.add('is-lbl');
					tick.appendChild(el('span', 'zsch-cs-num', String(d.getDate())));
				}
				btn.appendChild(tick);
			});
			btn.setAttribute('aria-label', 'Week of ' + MONTHS[w[0].getMonth()].slice(0, 3) + ' ' + w[0].getDate() +
				(evCount ? ', ' + evCount + ' event' + (evCount > 1 ? 's' : '') : ', no events'));
			btn.setAttribute('aria-pressed', isSel ? 'true' : 'false');
			btn.addEventListener('click', function () {
				if (casc.weekStart.getTime() !== w[0].getTime()) {
					casc.weekStart = w[0];
					casc.openDay = null; // changing weeks closes the day tier
					renderCascade();
				}
			});
			strip.appendChild(btn);
		});
	}

	// ── Line 2: week row ───────────────────────────────────────────
	function renderWeekRow() {
		var row = $('#zsch-casc-week');
		if (!row) { return; }
		row.innerHTML = '';
		var today = new Date();
		var wEnd = addDays(casc.weekStart, 6);
		var lbl = $('#zsch-casc-wklabel');
		if (lbl) {
			lbl.textContent = MONTHS[casc.weekStart.getMonth()].slice(0, 3) + ' ' + casc.weekStart.getDate() +
				' – ' + (wEnd.getMonth() !== casc.weekStart.getMonth() ? MONTHS[wEnd.getMonth()].slice(0, 3) + ' ' : '') + wEnd.getDate();
		}
		for (var i = 0; i < 7; i++) {
			(function (d) {
				var cell = el('button', 'zsch-cw-day');
				cell.type = 'button';
				cell.appendChild(el('span', 'zsch-cw-dow', DOW[d.getDay()].charAt(0)));
				cell.appendChild(el('span', 'zsch-cw-num', String(d.getDate())));
				var evs = sortEvents(eventsOnDay(d));
				var dots = el('span', 'zsch-cw-dots');
				evs.slice(0, 3).forEach(function (ev) {
					var dot = el('span', 'zsch-cw-dot');
					dot.style.background = (ev.scope === 'shared') ? colorForOwner(ev.owner_user_id) : 'var(--zsch-personal, #0ea5e9)';
					dots.appendChild(dot);
				});
				if (evs.length > 3) { dots.appendChild(el('span', 'zsch-cw-more', '+')); }
				cell.appendChild(dots);
				if (sameDay(d, today)) { cell.classList.add('is-today'); }
				if (d.getMonth() !== state.cursor.getMonth()) { cell.classList.add('is-out'); }
				var open = !!(casc.openDay && sameDay(d, casc.openDay));
				if (open) { cell.classList.add('is-open'); }
				cell.setAttribute('aria-expanded', open ? 'true' : 'false');
				cell.setAttribute('aria-label', DOW_FULL[d.getDay()] + ' ' + MONTHS[d.getMonth()] + ' ' + d.getDate() +
					(evs.length ? ', ' + evs.length + ' event' + (evs.length > 1 ? 's' : '') : ', no events'));
				cell.addEventListener('click', function () {
					// Re-tap the open day → collapse (plan: collapse rules).
					casc.openDay = open ? null : d;
					renderCascade();
				});
				row.appendChild(cell);
			})(addDays(casc.weekStart, i));
		}
	}

	// ── Line 3: day drill-down (in-flow, no modal) ─────────────────
	function renderDayTier() {
		var tier = $('#zsch-casc-day');
		if (!tier) { return; }
		if (!casc.openDay) { tier.hidden = true; tier.innerHTML = ''; return; }
		tier.hidden = false;
		tier.innerHTML = '';
		var d = casc.openDay;
		tier.setAttribute('aria-label', 'Day agenda: ' + DOW_FULL[d.getDay()] + ' ' + MONTHS[d.getMonth()] + ' ' + d.getDate());

		var hdr = el('div', 'zsch-cd-hdr');
		var prev = el('button', 'zsch-cd-nav'); prev.type = 'button';
		prev.setAttribute('aria-label', 'Previous day');
		prev.appendChild(chevSvg(true));
		var next = el('button', 'zsch-cd-nav'); next.type = 'button';
		next.setAttribute('aria-label', 'Next day');
		next.appendChild(chevSvg(false));
		var ttl = el('span', 'zsch-cd-title', DOW_FULL[d.getDay()] + ', ' + MONTHS[d.getMonth()].slice(0, 3) + ' ' + d.getDate());
		var close = el('button', 'zsch-cd-close', '✕'); close.type = 'button';
		close.setAttribute('aria-label', 'Close day view');
		prev.addEventListener('click', function () { cascDayStep(-1); });
		next.addEventListener('click', function () { cascDayStep(1); });
		close.addEventListener('click', function () { casc.openDay = null; renderCascade(); });
		hdr.appendChild(prev); hdr.appendChild(ttl); hdr.appendChild(next); hdr.appendChild(close);
		tier.appendChild(hdr);

		var evs = sortEvents(eventsOnDay(d));
		var dayStart = new Date(d.getFullYear(), d.getMonth(), d.getDate(), 0, 0, 0);
		var pinned = [], timed = [];
		evs.forEach(function (ev) {
			var st = parseUTC(ev.start_utc);
			if (ev.is_all_day || (st && st < dayStart)) { pinned.push(ev); } else { timed.push(ev); }
		});
		if (pinned.length) {
			var prow = el('div', 'zsch-cd-row zsch-cd-row--pinned');
			prow.appendChild(el('span', 'zsch-cd-hlabel', 'All day'));
			var plane = el('div', 'zsch-cd-lane');
			pinned.forEach(function (ev) { plane.appendChild(cdChip(ev, true)); });
			prow.appendChild(plane);
			tier.appendChild(prow);
		}
		// 7am–8pm baseline; auto-extend so an outlier event is never hidden.
		var h0 = 7, h1 = 20;
		timed.forEach(function (ev) {
			var st = parseUTC(ev.start_utc);
			if (st) {
				if (st.getHours() < h0) { h0 = st.getHours(); }
				if (st.getHours() > h1) { h1 = st.getHours(); }
			}
		});
		var canAdd = D.canWrite && !D.isReadOnly;
		for (var h = h0; h <= h1; h++) {
			(function (h) {
				var rowl = el('div', 'zsch-cd-row');
				rowl.appendChild(el('span', 'zsch-cd-hlabel', fmtHour(h)));
				var inHour = timed.filter(function (ev) {
					var st = parseUTC(ev.start_utc);
					return st && st.getHours() === h;
				});
				if (inHour.length) {
					var lane = el('div', 'zsch-cd-lane');
					inHour.forEach(function (ev) { lane.appendChild(cdChip(ev, false)); });
					rowl.appendChild(lane);
				} else if (canAdd) {
					// Empty hour = a real button (visible affordance + a11y).
					var add = el('button', 'zsch-cd-lane zsch-cd-lane--add', '+');
					add.type = 'button';
					add.setAttribute('aria-label', 'Add appointment at ' + fmtHour(h) + ' on ' + MONTHS[d.getMonth()].slice(0, 3) + ' ' + d.getDate());
					add.addEventListener('click', function () { openEditorNew(d, h); });
					rowl.appendChild(add);
					rowl.classList.add('is-empty');
				} else {
					rowl.appendChild(el('div', 'zsch-cd-lane zsch-cd-lane--blank'));
					rowl.classList.add('is-empty');
				}
				tier.appendChild(rowl);
			})(h);
		}
	}

	function cdChip(ev, pinnedFlag) {
		var b = el('button', 'zsch-cd-ev');
		b.type = 'button';
		var dot = el('span', 'zsch-cd-evdot');
		dot.style.background = (ev.scope === 'shared') ? colorForOwner(ev.owner_user_id) : 'var(--zsch-personal, #0ea5e9)';
		b.appendChild(dot);
		var t = pinnedFlag ? (ev.is_all_day ? 'All day' : 'Continues') : fmtTimeRange(ev);
		b.appendChild(el('span', 'zsch-cd-evtime', t));
		var who = (ev.scope === 'shared' && ev.owner_name) ? firstName(ev.owner_name) + ': ' : '';
		b.appendChild(el('span', 'zsch-cd-evttl', who + (ev.title || '(untitled)')));
		b.addEventListener('click', function () { openEditor(ev); });
		return b;
	}

	function chevSvg(left) {
		var svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
		svg.setAttribute('width', '16'); svg.setAttribute('height', '16');
		svg.setAttribute('viewBox', '0 0 24 24'); svg.setAttribute('fill', 'none');
		svg.setAttribute('stroke', 'currentColor'); svg.setAttribute('stroke-width', '2.4');
		svg.setAttribute('stroke-linecap', 'round'); svg.setAttribute('stroke-linejoin', 'round');
		svg.setAttribute('aria-hidden', 'true');
		var pl = document.createElementNS('http://www.w3.org/2000/svg', 'polyline');
		pl.setAttribute('points', left ? '15 18 9 12 15 6' : '9 18 15 12 9 6');
		svg.appendChild(pl);
		return svg;
	}

	// ── navigation ─────────────────────────────────────────────────
	// Structure paints synchronously from date math; events repaint when the
	// new window resolves (same sync-then-async pattern as refreshActiveView).
	function cascReload() { renderMonth(); loadEvents().then(renderMonth); }

	function cascMonthStep(n) {
		state.cursor = addMonths(state.cursor, n);
		casc.weekStart = startOfWeek(startOfMonth(state.cursor));
		casc.openDay = null;
		renderPeriod();
		cascReload();
	}
	function cascWeekStep(n) {
		var ns = addDays(casc.weekStart, 7 * n);
		casc.weekStart = ns;
		casc.openDay = null;
		var owner = owningMonth(ns);
		if (owner.getTime() !== startOfMonth(state.cursor).getTime()) {
			state.cursor = owner;
			renderPeriod();
			cascReload();
		} else {
			renderCascade();
		}
	}
	function cascDayStep(n) {
		if (!casc.openDay) { return; }
		var nd = addDays(casc.openDay, n);
		casc.openDay = nd;
		var ws = startOfWeek(nd);
		if (ws.getTime() !== casc.weekStart.getTime()) {
			casc.weekStart = ws;
			var owner = owningMonth(ws);
			if (owner.getTime() !== startOfMonth(state.cursor).getTime()) {
				state.cursor = owner;
				renderPeriod();
				cascReload();
				return;
			}
		}
		renderCascade();
	}
	function cascToday() {
		var now = new Date();
		var wasOpen = !!casc.openDay;
		state.cursor = startOfMonth(now);
		casc.weekStart = startOfWeek(now);
		casc.openDay = wasOpen ? new Date(now.getFullYear(), now.getMonth(), now.getDate(), 12, 0, 0, 0) : null;
		renderPeriod();
		cascReload();
	}

	// ── swipe (enhancement-only; chevrons are the guaranteed path) ─
	function cascSwipe(elm, fn) {
		if (!elm) { return; }
		var sx = null, sy = null, on = false, swallow = false;
		elm.addEventListener('pointerdown', function (e) {
			if (e.pointerType === 'mouse') { return; }
			var vw = window.innerWidth || 9999;
			// iOS edge-back-swipe guard: never claim gestures born at the
			// screen edges — Safari owns those.
			if (e.clientX < 24 || e.clientX > vw - 24) { return; }
			sx = e.clientX; sy = e.clientY; on = true; swallow = false;
		});
		elm.addEventListener('pointermove', function (e) {
			if (!on || sx === null) { return; }
			var dx = e.clientX - sx, dy = e.clientY - sy;
			if (Math.abs(dy) > 30 && Math.abs(dy) > Math.abs(dx)) { on = false; return; } // scroll wins
			if (Math.abs(dx) >= 48) {
				on = false; swallow = true;
				fn(dx < 0 ? 1 : -1);
			}
		});
		elm.addEventListener('pointercancel', function () { on = false; });
		elm.addEventListener('pointerup', function () { on = false; });
		// A 48px drag that ends over a child button would still click it —
		// swallow exactly one click after a fired swipe.
		elm.addEventListener('click', function (e) {
			if (swallow) { swallow = false; e.stopPropagation(); e.preventDefault(); }
		}, true);
	}

	function bindCascade() {
		if (!V2) { return; }
		var mp = $('#zsch-casc-mprev'), mn = $('#zsch-casc-mnext');
		var wp = $('#zsch-casc-wprev'), wn = $('#zsch-casc-wnext');
		if (mp) { mp.addEventListener('click', function () { cascMonthStep(-1); }); }
		if (mn) { mn.addEventListener('click', function () { cascMonthStep(1); }); }
		if (wp) { wp.addEventListener('click', function () { cascWeekStep(-1); }); }
		if (wn) { wn.addEventListener('click', function () { cascWeekStep(1); }); }
		cascSwipe($('#zsch-casc-strip'), cascMonthStep);
		cascSwipe($('#zsch-casc-week'), cascWeekStep);
	}

	// ── v1.5.2: CROSS-APP AUTO-REFRESH ─────────────────────────────
	// A booking made in CHAT (the [ZSCH_BOOK] bridge) lands server-side with
	// no client signal, so the widget kept re-projecting its in-memory window
	// until a manual Today-tap/chevron (live finding, Jul 3 round-1 test).
	// Fix: reload the active view's data when it's STALE (>60s) and the user
	// plausibly just came back to it — (a) the widget re-enters the viewport,
	// (b) the PWA returns to the foreground, (c) a gentle 5-minute tick while
	// on screen. Never while the user is MID-SOMETHING: any open modal, the
	// day-hours editor, or uncommitted availability paint blocks the refresh
	// (their in-progress work must never repaint out from under them).
	var lastDataLoad = 0;

	function uiBusy() {
		if ($('#zsch-modal') && !$('#zsch-modal').hidden) { return true; }
		if ($('#zsch-day-modal') && !$('#zsch-day-modal').hidden) { return true; }
		if ($('#zsch-dictate-modal') && !$('#zsch-dictate-modal').hidden) { return true; }
		if (document.querySelector('.zsch-de-backdrop')) { return true; }        // day-hours editor open
		if (availDrag && availDrag.dirty && Object.keys(availDrag.dirty).length) { return true; } // uncommitted paint
		return false;
	}

	function autoRefresh() {
		// v1.6.3: staleness debounce 60s → 180s. The calendar auto-refresh is
		// driven by THREE overlapping triggers (5-min tick + IntersectionObserver
		// on-enter + every visibilitychange-visible); on a dashboard where the
		// calendar is usually in view, tab-focus/scroll churn was firing a
		// zorderz/v1/scheduler/events fetch as often as once a minute. Collapsing that to once
		// per 3 minutes cuts the events-endpoint volume ~50-65% — part of the
		// platform-wide request-rate reduction (WP Engine GES edge rate limiting).
		if ((Date.now() - lastDataLoad) <= 180000 || uiBusy()) { return; }
		lastDataLoad = Date.now(); // debounce concurrent triggers (IO + foreground can fire together)
		// v1.6.3: on failure, push the next allowed refresh further out (error
		// backoff) so a struggling/ratelimited origin gets breathing room instead
		// of being retried at every trigger. Success paths re-stamp lastDataLoad.
		var onErr = function () { lastDataLoad = Date.now() + 180000; };
		if (state.view === 'month') {
			loadEvents().then(renderMonth).catch(onErr);
		} else if (state.view === 'availability') {
			loadAvailabilityMine().then(renderAvailability).catch(onErr);
		} else {
			loadTeam().then(renderTeam).catch(onErr);
		}
	}

	if ('IntersectionObserver' in window && root) {
		try {
			new IntersectionObserver(function (entries) {
				for (var i = 0; i < entries.length; i++) {
					if (entries[i].isIntersecting && entries[i].intersectionRatio >= 0.25) { autoRefresh(); break; }
				}
			}, { threshold: [0.25] }).observe(root);
		} catch (e) { /* very old engine — the other two triggers still cover */ }
	}
	document.addEventListener('visibilitychange', function () {
		if (!document.hidden) { autoRefresh(); }
	});
	setInterval(function () {
		if (!root) { return; }
		var r = root.getBoundingClientRect();
		if (r.bottom > 0 && r.top < (window.innerHeight || 9999)) { autoRefresh(); }
	}, 5 * 60 * 1000);

	// ── boot ───────────────────────────────────────────────────────
	// Render the calendar IMMEDIATELY — do not gate the first paint on any
	// network call. The roster (team colors/legend) loads in the background and
	// triggers a cheap re-render when it arrives; if it fails, the calendar
	// still works (events fall back to a default color).
	bind();
	if (V2) { cascRestore(); }         // restore selected week/day BEFORE the first load window is computed
	setView('month');                  // paints the grid right away
	loadRoster().then(function () {
		renderLegend();
		// Repaint the active view so owner colors apply once the roster is known.
		refreshActiveView();
	});

	// ── self-healing render guard ──────────────────────────────────
	// Defence in depth: if for ANY reason the month grid is still empty a beat
	// after boot (a cached/!=expected asset, a race where this script ran before
	// the widget HTML was injected into the dashboard zone, an exception swallowed
	// upstream), force a re-render. This guarantees the calendar never sits blank.
	function ensureGridPainted(attempt) {
		attempt = attempt || 0;
		var grid = V2 ? document.getElementById('zsch-casc-strip') : document.getElementById('zsch-grid');
		if (grid && grid.children.length === 0 && state.view === 'month') {
			renderMonth();
		}
		// Retry a couple of times to cover slow DOM insertion, then stop.
		if (attempt < 3) {
			setTimeout(function () { ensureGridPainted(attempt + 1); }, 300);
		}
	}
	setTimeout(function () { ensureGridPainted(0); }, 250);

	// The theme fires this after it injects inline-widget HTML into the dashboard
	// zone. If our script happened to evaluate before that insertion, re-run the
	// active view now that our DOM is definitely present.
	document.addEventListener('zdz_widgets_rendered', function () {
		if (document.getElementById('zsch-grid')) {
			refreshActiveView();
		}
	});

	} // end start()

})();
