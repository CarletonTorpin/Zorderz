/**
 * Zorderz Jobs - Inline Dashboard Widget JS
 *
 * Vanilla JS (no jQuery). Mirrors the platform widget contract used by
 * the platform apps:
 *   - Idempotent init fired three ways: immediate (already in DOM),
 *     the SPA shell's `zdz_widgets_rendered` event, and DOMContentLoaded.
 *   - AJAX via fetch() + URLSearchParams against admin-ajax.php.
 *   - Server is authoritative (INV-1): this UI only mirrors what the caller
 *     may do; every write is re-checked server-side and failures surface inline.
 *
 * Globals via wp_localize_script (zjobWidget):
 *   ajaxurl, nonce, version, canHandoff (bool), isLead (bool)
 *
 * @package Zorderz\Jobs
 * @since 1.1.0
 */
(function () {
	'use strict';

	var STATUS = {
		open:          { label: 'Open',               cls: 'is-open' },
		in_progress:   { label: 'In progress',        cls: 'is-progress' },
		pending_close: { label: 'Awaiting close-out', cls: 'is-pending-close' },
		done:          { label: 'Done',               cls: 'is-done' },
		cancelled:     { label: 'Cancelled',          cls: 'is-cancelled' }
	};
	var COMPONENT = (window.zjobWidget && window.zjobWidget.components) ? window.zjobWidget.components : { service: 'Service', other: 'Other' };
	var ERRORS = {
		not_permitted:  'You do not have permission to assign jobs.',
		bad_assignee:   'Pick a valid specialist.',
		kiosk_forbidden:'Not available on the shared device.',
		create_failed:  'Could not save the job.',
		reassign_failed:'That change was not permitted.',
		status_failed:  'That change was not permitted.',
		not_logged_in:  'Please sign in again.',
		bad_time:       'Pick a valid date and time.',
		bad_request:    'Missing info for scheduling.',
		schedule_failed:'Could not set the time.',
		unschedule_failed:'Could not clear the time.',
		scheduler_unavailable:'The Scheduler app is unavailable right now.',
		photos_required:  'Add at least one finish photo first.',
		bad_state:        'This job can no longer be completed.',
		bad_job:          'That job could not be found.',
		not_found:        'That job could not be found.',
		not_pending_close:'That job is not awaiting close-out.',
		two_party_required:'A second person is available - use Close out instead.',
		reason_required:  'Please write a reason to extend the deadline.',
		bad_eta:          'Pick a valid ETA signal.',
		duplicate:        'That photo was already uploaded - take a fresh one.',
		not_image:        'That file is not an image.',
		no_file:          'No photo received - try again.',
		upload_failed:    'The photo upload failed - try again.',
		save_failed:      'Could not save the photo - try again.',
		media_unavailable:'The photo library is unavailable right now.'
	};

	function cfg() { return window.zjobWidget || {}; }

	function esc(s) {
		return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
		});
	}

	function humanErr(code) { return ERRORS[code] || (code ? String(code) : 'Something went wrong.'); }

	/* tel: needs digits (keep a leading +). "(555) 123-4567" -> "5551234567". */
	function digits(s) { return String(s == null ? '' : s).replace(/[^\d+]/g, ''); }

	/* Deep-link an address to the native maps app for turn-by-turn directions.
	   Apple platforms (iPhone/iPad/Mac) -> Apple Maps daddr; everyone else ->
	   Google Maps dir/?api=1&destination. Empty address -> ''. */
	function mapsHref(addr) {
		var q = encodeURIComponent(String(addr == null ? '' : addr).trim());
		if (!q) { return ''; }
		var ua = navigator.userAgent || '';
		var isApple = /iPhone|iPad|iPod|Macintosh/i.test(ua);
		return isApple
			? 'https://maps.apple.com/?daddr=' + q
			: 'https://www.google.com/maps/dir/?api=1&destination=' + q;
	}

	/* ---- scheduling helpers ---------------------------------------------- */
	/* Parse a MySQL UTC datetime ("2026-07-16 16:00:00") into a Date. */
	function parseUtc(mysqlUtc) {
		if (!mysqlUtc) { return null; }
		var s = String(mysqlUtc).trim().replace(' ', 'T');
		if (!/[zZ]|[+\-]\d\d:?\d\d$/.test(s)) { s += 'Z'; }
		var d = new Date(s);
		return isNaN(d.getTime()) ? null : d;
	}
	/* Friendly wall-clock in the job's tz, e.g. "Thu Jul 16, 9:00 AM". */
	function fmtWhen(utc, tz) {
		var d = parseUtc(utc);
		if (!d) { return ''; }
		try {
			var opts = { weekday: 'short', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' };
			if (tz) { opts.timeZone = tz; }
			return new Intl.DateTimeFormat(undefined, opts).format(d);
		} catch (e) { return d.toLocaleString(); }
	}
	/* Short local clock, e.g. "9:05 AM" (for the ETA chip). */
	function fmtClock(utc) {
		var d = parseUtc(utc);
		if (!d) { return ''; }
		try { return new Intl.DateTimeFormat(undefined, { hour: 'numeric', minute: '2-digit' }).format(d); }
		catch (e) { return ''; }
	}
	/* UTC -> a datetime-local input value ("YYYY-MM-DDTHH:mm") in the job's tz. */
	function toLocalInput(utc, tz) {
		var d = parseUtc(utc);
		if (!d) { return ''; }
		try {
			var opts = { year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hour12: false };
			if (tz) { opts.timeZone = tz; }
			var p = {};
			new Intl.DateTimeFormat('en-CA', opts).formatToParts(d).forEach(function (x) { p[x.type] = x.value; });
			if (!p.year) { return ''; }
			var hh = (p.hour === '24') ? '00' : p.hour; // some engines emit 24
			return p.year + '-' + p.month + '-' + p.day + 'T' + hh + ':' + p.minute;
		} catch (e) { return ''; }
	}
	/* A sensible default when opening the picker on an unscheduled job: next hour,
	   in the BROWSER's local tz (the admin will adjust as needed). */
	function defaultLocalInput() {
		var d = new Date(Date.now() + 3600 * 1000);
		d.setMinutes(0, 0, 0);
		var p = function (n) { return String(n).length < 2 ? '0' + n : '' + n; };
		return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate()) + 'T' + p(d.getHours()) + ':' + p(d.getMinutes());
	}
	/* Due-soon / overdue flag for an active scheduled job. */
	function dueFlag(utc, status) {
		if (status === 'done' || status === 'cancelled') { return null; }
		var d = parseUtc(utc);
		if (!d) { return null; }
		var diff = d.getTime() - Date.now();
		if (diff < 0) { return { cls: 'over', label: 'Overdue' }; }
		if (diff < 24 * 3600 * 1000) { return { cls: 'soon', label: 'Due soon' }; }
		return null;
	}

	function drawIcons() {
		if (window.lucide && typeof window.lucide.createIcons === 'function') {
			try { window.lucide.createIcons(); } catch (e) { /* non-fatal */ }
		}
	}

	/* POST an admin-ajax action; resolves the parsed JSON envelope. */
	function post(action, data) {
		var body = new URLSearchParams();
		body.set('action', action);
		body.set('nonce', cfg().nonce || '');
		if (data) {
			Object.keys(data).forEach(function (k) {
				var v = data[k];
				if (v !== undefined && v !== null) { body.set(k, v); }
			});
		}
		return fetch(cfg().ajaxurl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'X-Requested-With': 'XMLHttpRequest' },
			body: body
		}).then(function (r) {
			return r.json().catch(function () { return { success: false, data: { message: 'bad_response' } }; });
		});
	}

	/* ---- init ------------------------------------------------------------ */
	function initWidget() {
		var root = document.getElementById('zjob-widget');
		if (!root || root.dataset.tsjInited) { return; }
		root.dataset.tsjInited = '1';

		// One delegated click handler for tabs, chips, and item actions.
		root.addEventListener('click', function (e) {
			var tab = e.target.closest('.zjob-w-tab');
			if (tab && root.contains(tab)) { switchTab(root, tab.getAttribute('data-tab')); return; }

			var chip = e.target.closest('.zjob-chip');
			if (chip && root.contains(chip)) { setFilter(root, chip); return; }

			var act = e.target.closest('[data-act]');
			if (act && root.contains(act)) { handleAct(root, act); return; }
		});

		var createBtn = root.querySelector('#zjob-create');
		if (createBtn) { createBtn.addEventListener('click', function () { doCreate(root); }); }

		if (root.getAttribute('data-can-handoff') === '1') { loadAssignees(root); }
		loadList(root, 'present');
		drawIcons();
	}

	function switchTab(root, name) {
		root.querySelectorAll('.zjob-w-tab').forEach(function (t) {
			t.classList.toggle('is-active', t.getAttribute('data-tab') === name);
		});
		root.querySelectorAll('.zjob-w-panel').forEach(function (p) {
			p.classList.toggle('is-active', p.getAttribute('data-panel') === name);
		});
	}

	function currentFilter(root) {
		var a = root.querySelector('.zjob-chip.is-active');
		return a ? (a.getAttribute('data-bucket') || 'present') : 'present';
	}

	function setFilter(root, chip) {
		root.querySelectorAll('.zjob-chip').forEach(function (c) { c.classList.remove('is-active'); });
		chip.classList.add('is-active');
		loadList(root, chip.getAttribute('data-bucket') || 'present');
	}

	/* ---- list ------------------------------------------------------------ */
	function loadList(root, bucket) {
		var listEl = root.querySelector('#zjob-list');
		if (!listEl) { return; }
		listEl.innerHTML = '<div class="zjob-empty zjob-loading">Loading&hellip;</div>';
		post('zjob_list', { bucket: bucket || 'present' }).then(function (res) {
			if (!res || !res.success) {
				listEl.innerHTML = '<div class="zjob-empty">Could not load jobs.</div>';
				return;
			}
			renderList(root, (res.data && res.data.jobs) || []);
			updatePendingBadge(root, res.data ? res.data.pending_close_count : 0);
		}).catch(function () {
			listEl.innerHTML = '<div class="zjob-empty">Could not load jobs.</div>';
		});
	}

	/* "Pending my close" count badge on the Present tab (server-authoritative). */
	function updatePendingBadge(root, count) {
		var chip = root.querySelector('.zjob-chip[data-bucket="present"]');
		if (!chip) { return; }
		count = parseInt(count, 10) || 0;
		var b = chip.querySelector('.zjob-chip-count');
		if (count > 0) {
			if (!b) { b = document.createElement('span'); b.className = 'zjob-chip-count'; chip.appendChild(b); }
			b.textContent = String(count);
		} else if (b) {
			b.parentNode.removeChild(b);
		}
	}

	/* Worker inbox: which day-bucket a job belongs to. */
	function jobDayGroup(r) {
		if (r.status === 'done' || r.status === 'cancelled') { return { o: 9, key: 'done', label: 'Completed' }; }
		if (r.status === 'pending_close') { return { o: 5, key: 'closeout', label: 'Awaiting close-out' }; }
		var scheduled = parseInt(r.scheduled_appt_id || 0, 10) > 0 && r.scheduled_start_utc;
		var d = scheduled ? parseUtc(r.scheduled_start_utc) : null;
		if (!d) { return { o: 8, key: 'unsched', label: 'Not scheduled' }; }
		var now = new Date();
		var t0 = new Date(now.getFullYear(), now.getMonth(), now.getDate()).getTime();
		var jd = new Date(d.getFullYear(), d.getMonth(), d.getDate()).getTime();
		var diff = Math.round((jd - t0) / 86400000);
		if (diff < 0) { return { o: 0, key: 'overdue', label: 'Overdue' }; }
		if (diff === 0) { return { o: 1, key: 'today', label: 'Today' }; }
		if (diff === 1) { return { o: 2, key: 'tomorrow', label: 'Tomorrow' }; }
		if (diff <= 7) { return { o: 3, key: 'week', label: 'This week' }; }
		return { o: 4, key: 'later', label: 'Later' };
	}
	function schedMs(r) {
		var d = (parseInt(r.scheduled_appt_id || 0, 10) > 0) ? parseUtc(r.scheduled_start_utc) : null;
		return d ? d.getTime() : 0;
	}

	function renderList(root, rows) {
		var listEl = root.querySelector('#zjob-list');
		if (!listEl) { return; }
		if (!rows.length) {
			listEl.innerHTML = '<div class="zjob-empty">Nothing here &mdash; you&rsquo;re all caught up.</div>';
			return;
		}
		var isLead = root.getAttribute('data-is-lead') === '1';
		// Group by scheduled day (Today near the top), sorted by scheduled time within each group.
		var groups = {};
		rows.forEach(function (r) {
			var g = jobDayGroup(r);
			if (!groups[g.key]) { groups[g.key] = { o: g.o, label: g.label, rows: [] }; }
			groups[g.key].rows.push(r);
		});
		var ordered = Object.keys(groups).map(function (k) { return groups[k]; }).sort(function (a, b) { return a.o - b.o; });
		ordered.forEach(function (g) {
			g.rows.sort(function (a, b) {
				var ta = schedMs(a), tb = schedMs(b);
				if (ta && tb) { return ta - tb; }
				if (ta) { return -1; }
				if (tb) { return 1; }
				return (parseInt(b.id, 10) || 0) - (parseInt(a.id, 10) || 0);
			});
		});
		listEl.innerHTML = ordered.map(function (g) {
			return '<div class="zjob-group-head">' + esc(g.label) +
				'<span class="zjob-group-count">' + g.rows.length + '</span></div>' +
				g.rows.map(function (r) { return itemHtml(r, isLead); }).join('');
		}).join('');
		drawIcons();
	}

	function statusBtn(id, status, label, icon, variant) {
		return '<button class="zjob-mini' + (variant ? ' zjob-mini-' + variant : '') + '"' +
			' data-act="status" data-id="' + id + '" data-status="' + status + '">' +
			'<i data-lucide="' + icon + '"></i><span>' + label + '</span></button>';
	}

	/* Phase 7a: the worker completes THEIR part through the photo-gated capture flow
	   (not a plain status flip). Shown only on their own active job. */
	function completeBtn(id) {
		return '<button class="zjob-mini zjob-mini-primary" data-act="complete" data-id="' + parseInt(id, 10) + '">' +
			'<i data-lucide="camera"></i><span>Mark my part complete</span></button>';
	}

	/* Phase 7b: the originator's close-out button (only where can_close). */
	function closeBtn(id) {
		return '<button class="zjob-mini zjob-mini-primary" data-act="close" data-id="' + parseInt(id, 10) + '">' +
			'<i data-lucide="check-check"></i><span>Close out</span></button>';
	}

	/* Worker inbox: ETA taps on the worker's own active job. "On my way" doubles as the
	   auto-start (open -> in_progress). "Running late" flags the dispatcher. */
	function etaBtn(id, type) {
		if (type === 'on_my_way') {
			return '<button class="zjob-mini zjob-mini-primary" data-act="eta" data-id="' + parseInt(id, 10) + '" data-eta="on_my_way">' +
				'<i data-lucide="navigation"></i><span>On my way</span></button>';
		}
		return '<button class="zjob-mini zjob-mini-warn" data-act="eta" data-id="' + parseInt(id, 10) + '" data-eta="running_late">' +
			'<i data-lucide="clock"></i><span>Running late</span></button>';
	}

	/* The ETA chip a dispatcher/viewer sees on the card (on-the-way / running-late + when). */
	function etaChipHtml(r) {
		if (r.eta_status !== 'on_my_way' && r.eta_status !== 'running_late') { return ''; }
		var when = fmtClock(r.eta_at);
		var late = (r.eta_status === 'running_late');
		return '<div class="zjob-eta-chip ' + (late ? 'is-late' : 'is-otw') + '">' +
			'<i data-lucide="' + (late ? 'clock' : 'navigation') + '"></i>' +
			(late ? 'Running late' : 'On the way') +
			(when ? ' <span class="zjob-eta-when">&middot; ' + esc(when) + '</span>' : '') +
			'</div>';
	}

	/* Auto-close countdown from close_deadline: {cls,label} or null. Neutral when far
	   out, warning within closeSoonDays, danger once due. */
	function autoCloseFlag(deadline) {
		var d = parseUtc(deadline);
		if (!d) { return null; }
		var days = Math.ceil((d.getTime() - Date.now()) / 86400000);
		var soon = parseInt(cfg().closeSoonDays, 10) || 7;
		if (days <= 0) { return { cls: 'over', label: 'Auto-closing' }; }
		return { cls: (days <= soon) ? 'soon' : 'ok', label: 'Auto-closes in ' + days + ' day' + (days === 1 ? '' : 's') };
	}

	/* The originator's "extend the deadline" button (pending_close, can_close). */
	function extendBtn(id) {
		return '<button class="zjob-mini" data-act="extend" data-id="' + parseInt(id, 10) + '">' +
			'<i data-lucide="calendar-plus"></i><span>Extend</span></button>';
	}

	/* Inline extend form: a REQUIRED written reason + a days field (default 60). */
	function extendForm(id) {
		var d = parseInt(cfg().closeMaxDays, 10) || 60;
		return '<div class="zjob-ext-form" data-id="' + parseInt(id, 10) + '" hidden>' +
				'<label class="zjob-ext-label">Push out the auto-close deadline. A written reason is required.</label>' +
				'<textarea class="zjob-ext-reason" rows="2" placeholder="Why does this need more time? (required)"></textarea>' +
				'<div class="zjob-ext-row">' +
					'<label class="zjob-ext-days-l">Days<input type="number" class="zjob-ext-days" min="1" max="3650" value="' + d + '" inputmode="numeric" /></label>' +
					'<button class="zjob-mini zjob-mini-primary" data-act="extend-save" data-id="' + parseInt(id, 10) + '"><i data-lucide="check"></i><span>Extend</span></button>' +
					'<button class="zjob-mini" data-act="extend-cancel" data-id="' + parseInt(id, 10) + '"><span>Cancel</span></button>' +
				'</div>' +
				'<span class="zjob-ext-msg" aria-live="polite"></span>' +
			'</div>';
	}

	/* Safety floor: a SOLO operator (no distinct second party) closes their own job
	   with a RECORDED single-party attestation. Recorded as single_party_attested,
	   never laundered into two_party. Shown only when the server says can_self_attest. */
	function selfAttestBtn(id) {
		return '<button class="zjob-mini zjob-mini-primary" data-act="attest" data-id="' + parseInt(id, 10) + '">' +
			'<i data-lucide="user-check"></i><span>Close (solo attestation)</span></button>';
	}

	/* Inline attestation form: a REQUIRED written statement (the server rejects an
	   empty reason). The copy makes clear this is a solo close, not a two-party sign-off. */
	function attestForm(id) {
		return '<div class="zjob-att-form" data-id="' + parseInt(id, 10) + '" hidden>' +
				'<label class="zjob-att-label">No second person is available to sign off. Record that you completed this job. A written statement is required; this is logged as a solo (single-party) attestation, not a two-party close.</label>' +
				'<textarea class="zjob-att-reason" rows="2" placeholder="I completed this job on site. (required)"></textarea>' +
				'<div class="zjob-att-row">' +
					'<button class="zjob-mini zjob-mini-primary" data-act="attest-save" data-id="' + parseInt(id, 10) + '"><i data-lucide="check"></i><span>Attest &amp; close</span></button>' +
					'<button class="zjob-mini" data-act="attest-cancel" data-id="' + parseInt(id, 10) + '"><span>Cancel</span></button>' +
				'</div>' +
				'<span class="zjob-att-msg" aria-live="polite"></span>' +
			'</div>';
	}

	/* Once the worker has submitted, show their finish photos + the location badge.
	   Photos open full-size via the login-free token URL (clickable from anywhere). */
	function finishHtml(r) {
		if (r.status !== 'pending_close' && r.status !== 'done') { return ''; }
		var photos = Array.isArray(r.finish_photos) ? r.finish_photos : [];
		var thumbs = photos.map(function (p) {
			var full = esc(p.url || '');
			var th   = esc(p.thumb_url || p.url || '');
			return '<a class="zjob-fp" href="' + full + '" target="_blank" rel="noopener">' +
				'<img src="' + th + '" alt="Finish photo" loading="lazy" /></a>';
		}).join('');
		var verified = (r.finish_verified === true || r.finish_verified === 1 || r.finish_verified === '1');
		var when = r.worker_done_at ? fmtWhen(r.worker_done_at, null) : '';
		var badge = '<span class="zjob-fp-badge ' + (verified ? 'is-ok' : 'is-warn') + '">' +
			'<i data-lucide="' + (verified ? 'map-pin' : 'map-pin-off') + '"></i>' +
			(verified ? 'Location verified' : 'Location unverified') + '</span>';
		var label = (r.status === 'done') ? 'Finish photos' : ('Submitted' + (when ? ' &middot; ' + esc(when) : ''));
		var head = '<div class="zjob-fp-head"><i data-lucide="camera"></i><span>' + label + '</span>' + badge + '</div>';
		var strip = thumbs ? '<div class="zjob-fp-strip">' + thumbs + '</div>' : '';
		var note = '';
		if (r.status === 'pending_close') {
			var af = autoCloseFlag(r.close_deadline);
			var flag = af ? ' <span class="zjob-sla-flag is-' + af.cls + '">' + esc(af.label) + '</span>' : '';
			var extc = parseInt(r.close_extended_count, 10) || 0;
			var ext = extc > 0 ? ' <span class="zjob-ext-badge">Extended ' + extc + '&times;</span>' : '';
			note = '<div class="zjob-fp-note">Awaiting originator close-out.' + flag + ext + '</div>';
		} else if (r.status === 'done' && r.closed_by_name) {
			note = '<div class="zjob-fp-note">Closed out by ' + esc(r.closed_by_name) + '.</div>';
		}
		return '<div class="zjob-finish">' + head + strip + note + '</div>';
	}

	/* The (initially hidden) date/time editor for a job. isReschedule => a Clear
	   button is offered. data-cur carries the current time as a datetime-local
	   value so the picker opens on it. */
	function schedForm(id, curLocal, isReschedule) {
		return '<div class="zjob-sched-form" data-id="' + parseInt(id, 10) + '" data-cur="' + esc(curLocal || '') + '" hidden>' +
				'<input type="datetime-local" class="zjob-sched-input" aria-label="Job date and time" />' +
				'<div class="zjob-sched-factions">' +
					'<button class="zjob-mini zjob-mini-primary" data-act="sched-save" data-id="' + parseInt(id, 10) + '">' +
						'<i data-lucide="check"></i><span>' + (isReschedule ? 'Save time' : 'Schedule') + '</span></button>' +
					'<button class="zjob-mini" data-act="sched-cancel" data-id="' + parseInt(id, 10) + '"><span>Cancel</span></button>' +
					(isReschedule ? '<button class="zjob-mini zjob-mini-ghost" data-act="sched-clear" data-id="' + parseInt(id, 10) + '"><i data-lucide="x"></i><span>Clear</span></button>' : '') +
				'</div>' +
			'</div>';
	}

	/* The schedule block: a "when" chip once scheduled, plus (for managers) the
	   set-time / reschedule control. Workers never receive unscheduled rows, so
	   the unscheduled branch only ever shows to admins/supervisors. */
	function schedHtml(r, canSchedule) {
		var scheduled = parseInt(r.scheduled_appt_id || 0, 10) > 0 && r.scheduled_start_utc;
		if (scheduled) {
			var when = fmtWhen(r.scheduled_start_utc, r.scheduled_tz);
			var flag = dueFlag(r.scheduled_start_utc, r.status);
			var cur  = toLocalInput(r.scheduled_start_utc, r.scheduled_tz);
			var head = '<div class="zjob-sched is-set">' +
					'<i data-lucide="calendar-clock"></i>' +
					'<span class="zjob-sched-when">' + esc(when) + '</span>' +
					(flag ? '<span class="zjob-sched-flag is-' + flag.cls + '">' + esc(flag.label) + '</span>' : '') +
					(canSchedule ? '<button class="zjob-sched-edit" data-act="sched-edit" data-id="' + parseInt(r.id, 10) + '"><i data-lucide="pencil"></i>Edit</button>' : '') +
				'</div>';
			return head + (canSchedule ? schedForm(r.id, cur, true) : '');
		}
		if (canSchedule) {
			return '<div class="zjob-sched">' +
					'<span class="zjob-sched-none"><i data-lucide="calendar-off"></i>Not scheduled</span>' +
					'<button class="zjob-sched-edit is-set-time" data-act="sched-edit" data-id="' + parseInt(r.id, 10) + '"><i data-lucide="calendar-plus"></i>Set time</button>' +
				'</div>' + schedForm(r.id, '', false);
		}
		return '';
	}

	function itemHtml(r, isLead) {
		var st   = STATUS[r.status] || { label: r.status, cls: '' };
		var comp = r.component_label || COMPONENT[r.component] || r.component;
		var mine = (r.is_mine === true || r.is_mine === 1 || r.is_mine === '1');
		var canSchedule = (r.can_schedule === true || r.can_schedule === 1 || r.can_schedule === '1');

		// Customer + business.
		var who = [];
		if (r.customer_name)     { who.push('<span class="zjob-c-name">' + esc(r.customer_name) + '</span>'); }
		if (r.customer_business) { who.push('<span class="zjob-c-biz">' + esc(r.customer_business) + '</span>'); }
		var whoLine = who.length
			? '<div class="zjob-card-who">' + who.join('<span class="zjob-dot">&middot;</span>') + '</div>'
			: '<div class="zjob-card-who"><span class="zjob-muted">No customer</span></div>';

		// Tappable address -> native maps (directions).
		var addrRow = '';
		if (r.customer_address) {
			addrRow = '<a class="zjob-tap" href="' + esc(mapsHref(r.customer_address)) + '" target="_blank" rel="noopener" data-tap="map">' +
				'<i data-lucide="map-pin"></i>' +
				'<span class="zjob-tap-txt">' + esc(r.customer_address) + '</span>' +
				'<i class="zjob-tap-go" data-lucide="corner-up-right"></i>' +
				'</a>';
		}

		// Tap-to-call.
		var phoneRow = '';
		if (r.customer_phone) {
			phoneRow = '<a class="zjob-tap" href="tel:' + esc(digits(r.customer_phone)) + '" data-tap="call">' +
				'<i data-lucide="phone"></i>' +
				'<span class="zjob-tap-txt">' + esc(r.customer_phone) + '</span>' +
				'<i class="zjob-tap-go" data-lucide="phone-outgoing"></i>' +
				'</a>';
		}

		// Site / access notes callout (what the worker needs to get on site).
		var accessRow = r.access_notes
			? '<div class="zjob-access"><i data-lucide="key-round"></i><span>' + esc(r.access_notes) + '</span></div>'
			: '';

		// Component meta.
		var meta = [];
		if (r.brand) { meta.push(esc(r.brand)); }
		if (r.qty)   { meta.push('&times;' + parseInt(r.qty, 10)); }
		if (r.source_ref) { meta.push(esc(r.source_ref)); }
		var metaRow = meta.length
			? '<div class="zjob-item-meta">' + meta.join('<span class="zjob-dot">&middot;</span>') + '</div>'
			: '';

		// People + CRM link.
		var nut = r.child_lead_id
			? '<span class="zjob-item-crm" title="CRM lead #' + parseInt(r.child_lead_id, 10) + '">' +
			  '<i data-lucide="link-2"></i>Lead #' + parseInt(r.child_lead_id, 10) + '</span>'
			: '';
		var people = '<div class="zjob-item-people">' +
				'<span title="Doing it"><i data-lucide="user"></i>' + (mine ? 'You' : esc(r.assignee_name)) + '</span>' +
				'<span title="Handed off by"><i data-lucide="user-plus"></i>' + esc(r.creator_name) + '</span>' +
				nut +
			'</div>';

		var notes = r.notes ? '<div class="zjob-item-notes">' + esc(r.notes) + '</div>' : '';

		// Status actions.
		var canComplete = (r.can_complete === true || r.can_complete === 1 || r.can_complete === '1');
		var active = (r.status === 'open' || r.status === 'in_progress');
		var actions = [];
		// Worker inbox: the worker's own OPEN job starts via "On my way" (no separate Start);
		// a manager viewing someone else's open job keeps the plain Start.
		if (r.status === 'open' && !mine) { actions.push(statusBtn(r.id, 'in_progress', 'Start', 'play')); }
		if (mine && active) {
			if (r.eta_status !== 'on_my_way') { actions.push(etaBtn(r.id, 'on_my_way')); }
			if (r.eta_status !== 'running_late') { actions.push(etaBtn(r.id, 'running_late')); }
		}
		// The worker finishes their own active job via the photo-gated flow; a manager or
		// originator viewing someone else's active job keeps the interim "Mark done" close
		// (the server still enforces who may set each status).
		if (active && canComplete && mine) {
			actions.push(completeBtn(r.id));
		} else if (active) {
			actions.push(statusBtn(r.id, 'done', 'Mark done', 'check'));
		}
		var canClose = (r.can_close === true || r.can_close === 1 || r.can_close === '1');
		var canSelfAttest = (r.can_self_attest === true || r.can_self_attest === 1 || r.can_self_attest === '1');
		if (r.status === 'pending_close' && canClose) { actions.push(closeBtn(r.id)); actions.push(extendBtn(r.id)); }
		// Solo operator: no distinct second party, so the two-party Close is not offered;
		// the worker records a single-party attestation instead (server-gated + logged).
		else if (r.status === 'pending_close' && canSelfAttest) { actions.push(selfAttestBtn(r.id)); }
		if (r.status === 'done' || r.status === 'cancelled' || r.status === 'pending_close') { actions.push(statusBtn(r.id, 'open', 'Reopen', 'rotate-ccw')); }
		if (active) { actions.push(statusBtn(r.id, 'cancelled', 'Cancel', 'x', 'ghost')); }
		var actionsRow = actions.length ? '<div class="zjob-item-actions">' + actions.join('') + '</div>' : '';
		var extForm = ( r.status === 'pending_close' && canClose ) ? extendForm(r.id) : '';
		var attForm = ( r.status === 'pending_close' && !canClose && canSelfAttest ) ? attestForm(r.id) : '';

		var mineChip = mine ? '<span class="zjob-mine">Yours</span>' : '';
		var finish = finishHtml(r);
		var etaChip = etaChipHtml(r);

		return '' +
			'<div class="zjob-item zjob-card' + (mine ? ' is-mine' : '') + '" data-id="' + parseInt(r.id, 10) + '" data-status="' + esc(r.status) + '">' +
				'<div class="zjob-card-head">' +
					'<span class="zjob-card-title">' + esc(comp) + '</span>' +
					mineChip +
					'<span class="zjob-badge ' + st.cls + '">' + esc(st.label) + '</span>' +
				'</div>' +
				whoLine +
				schedHtml(r, canSchedule) +
				etaChip +
				addrRow +
				phoneRow +
				accessRow +
				metaRow +
				people +
				notes +
				finish +
				actionsRow +
				extForm +
				attForm +
			'</div>';
	}

	function handleAct(root, el) {
		var act = el.getAttribute('data-act');
		var id  = el.getAttribute('data-id');
		if (act === 'status')       { return doStatus(root, el, id); }
		if (act === 'complete')     { return openComplete(root, id); }
		if (act === 'eta')          { return doEta(root, el, id); }
		if (act === 'close')        { return doClose(root, el, id); }
		if (act === 'extend')        { return extendToggle(root, id, true); }
		if (act === 'extend-cancel') { return extendToggle(root, id, false); }
		if (act === 'extend-save')   { return doExtend(root, el, id); }
		if (act === 'attest')        { return attestToggle(root, id, true); }
		if (act === 'attest-cancel') { return attestToggle(root, id, false); }
		if (act === 'attest-save')   { return doAttest(root, el, id); }
		if (act === 'sched-edit')   { return schedEdit(root, id, true); }
		if (act === 'sched-cancel') { return schedEdit(root, id, false); }
		if (act === 'sched-save')   { return schedSave(root, el, id); }
		if (act === 'sched-clear')  { return schedClear(root, el, id); }
	}

	/* Worker inbox: post an ETA signal (on_my_way / running_late) then refresh. */
	function doEta(root, el, id) {
		var eta = el.getAttribute('data-eta');
		el.disabled = true;
		post('zjob_set_eta', { id: id, eta: eta }).then(function (res) {
			if (res && res.success) { loadList(root, currentFilter(root)); }
			else { el.disabled = false; flashRow(root, id, (res && res.data && res.data.message) || 'status_failed'); }
		}).catch(function () { el.disabled = false; flashRow(root, id, 'status_failed'); });
	}

	/* Reveal/hide the extend-deadline form. */
	function extendToggle(root, id, show) {
		var form = root.querySelector('.zjob-ext-form[data-id="' + parseInt(id, 10) + '"]');
		if (!form) { return; }
		form.hidden = !show;
		if (show) { var t = form.querySelector('.zjob-ext-reason'); if (t) { try { t.focus(); } catch (e) { /* non-fatal */ } } }
	}

	/* Submit an extension. The server REQUIRES a non-empty reason; we pre-check too. */
	function doExtend(root, el, id) {
		var form = root.querySelector('.zjob-ext-form[data-id="' + parseInt(id, 10) + '"]');
		if (!form) { return; }
		var reason = (form.querySelector('.zjob-ext-reason') || {}).value || '';
		var days   = (form.querySelector('.zjob-ext-days') || {}).value || '';
		var msg    = form.querySelector('.zjob-ext-msg');
		function say(t, kind) { if (msg) { msg.textContent = t; msg.className = 'zjob-ext-msg' + (kind ? ' is-' + kind : ''); } }
		if (!String(reason).trim()) { say('A written reason is required.', 'err'); return; }
		el.disabled = true;
		say('Extending\u2026', '');
		post('zjob_extend_close', { id: id, reason: reason, days: days }).then(function (res) {
			if (res && res.success) {
				loadList(root, currentFilter(root));
			} else {
				el.disabled = false;
				say(humanErr(res && res.data && res.data.message), 'err');
			}
		}).catch(function () {
			el.disabled = false;
			say('Network error - try again.', 'err');
		});
	}

	/* Reveal/hide the solo single-party attestation form. */
	function attestToggle(root, id, show) {
		var form = root.querySelector('.zjob-att-form[data-id="' + parseInt(id, 10) + '"]');
		if (!form) { return; }
		form.hidden = !show;
		if (show) { var t = form.querySelector('.zjob-att-reason'); if (t) { try { t.focus(); } catch (e) { /* non-fatal */ } } }
	}

	/* Submit a solo single-party attestation close. The server REQUIRES a non-empty
	   statement and records assurance = single_party_attested (never two_party); we
	   pre-check the reason so an empty submit never round-trips. */
	function doAttest(root, el, id) {
		var form = root.querySelector('.zjob-att-form[data-id="' + parseInt(id, 10) + '"]');
		if (!form) { return; }
		var reason = (form.querySelector('.zjob-att-reason') || {}).value || '';
		var msg    = form.querySelector('.zjob-att-msg');
		function say(t, kind) { if (msg) { msg.textContent = t; msg.className = 'zjob-att-msg' + (kind ? ' is-' + kind : ''); } }
		if (!String(reason).trim()) { say('A written attestation is required.', 'err'); return; }
		el.disabled = true;
		say('Recording…', '');
		post('zjob_self_attest_close', { id: id, reason: reason }).then(function (res) {
			if (res && res.success) {
				loadList(root, currentFilter(root));
			} else {
				el.disabled = false;
				say(humanErr(res && res.data && res.data.message), 'err');
			}
		}).catch(function () {
			el.disabled = false;
			say('Network error - try again.', 'err');
		});
	}

	/* Phase 7b close-out. Two-click confirm (a CRM lead gets closed) then post. */
	function doClose(root, el, id) {
		if (el.getAttribute('data-confirm') !== '1') {
			el.setAttribute('data-confirm', '1');
			el.classList.add('is-confirm');
			var s = el.querySelector('span');
			if (s) { el.setAttribute('data-label', s.textContent); s.textContent = 'Confirm close?'; }
			setTimeout(function () {
				if (el && el.getAttribute('data-confirm') === '1') {
					el.setAttribute('data-confirm', '0'); el.classList.remove('is-confirm');
					var s2 = el.querySelector('span'); if (s2) { s2.textContent = el.getAttribute('data-label') || 'Close out'; }
				}
			}, 4000);
			return;
		}
		el.disabled = true;
		post('zjob_close_job', { id: id }).then(function (res) {
			if (res && res.success) {
				loadList(root, currentFilter(root));
			} else {
				el.disabled = false; el.classList.remove('is-confirm'); el.setAttribute('data-confirm', '0');
				var s3 = el.querySelector('span'); if (s3) { s3.textContent = el.getAttribute('data-label') || 'Close out'; }
				flashRow(root, id, (res && res.data && res.data.message) || 'status_failed');
			}
		}).catch(function () {
			el.disabled = false;
			flashRow(root, id, 'status_failed');
		});
	}

	function doStatus(root, el, id) {
		var status = el.getAttribute('data-status');
		el.disabled = true;
		post('zjob_set_status', { id: id, status: status }).then(function (res) {
			if (res && res.success) {
				loadList(root, currentFilter(root));
			} else {
				el.disabled = false;
				flashRow(root, id, (res && res.data && res.data.message) || 'status_failed');
			}
		}).catch(function () {
			el.disabled = false;
			flashRow(root, id, 'status_failed');
		});
	}

	/* Reveal/hide a job's date/time editor, prefilling the picker. */
	function schedEdit(root, id, show) {
		var form = root.querySelector('.zjob-sched-form[data-id="' + parseInt(id, 10) + '"]');
		if (!form) { return; }
		if (show) {
			var input = form.querySelector('.zjob-sched-input');
			if (input && !input.value) { input.value = form.getAttribute('data-cur') || defaultLocalInput(); }
			form.hidden = false;
			if (input) { try { input.focus(); } catch (e) { /* non-fatal */ } }
		} else {
			form.hidden = true;
		}
	}

	/* Save a job's time (admin only, server-enforced). */
	function schedSave(root, el, id) {
		var form  = root.querySelector('.zjob-sched-form[data-id="' + parseInt(id, 10) + '"]');
		var input = form ? form.querySelector('.zjob-sched-input') : null;
		var val   = input ? String(input.value).trim() : '';
		if (!val) { flashRow(root, id, 'bad_time'); return; }
		el.disabled = true;
		post('zjob_set_schedule', { id: id, start_local: val }).then(function (res) {
			if (res && res.success) {
				loadList(root, currentFilter(root));
			} else {
				el.disabled = false;
				flashRow(root, id, (res && res.data && res.data.message) || 'schedule_failed');
			}
		}).catch(function () {
			el.disabled = false;
			flashRow(root, id, 'schedule_failed');
		});
	}

	/* Clear a job's schedule (delete the linked appointment). */
	function schedClear(root, el, id) {
		el.disabled = true;
		post('zjob_clear_schedule', { id: id }).then(function (res) {
			if (res && res.success) {
				loadList(root, currentFilter(root));
			} else {
				el.disabled = false;
				flashRow(root, id, (res && res.data && res.data.message) || 'unschedule_failed');
			}
		}).catch(function () {
			el.disabled = false;
			flashRow(root, id, 'unschedule_failed');
		});
	}

	function flashRow(root, id, code) {
		var item = root.querySelector('.zjob-item[data-id="' + parseInt(id, 10) + '"]');
		if (!item) { return; }
		var host = item.querySelector('.zjob-item-actions') || item;
		var old = host.querySelector('.zjob-mini-msg');
		if (old) { old.parentNode.removeChild(old); }
		var span = document.createElement('span');
		span.className = 'zjob-mini-msg';
		span.textContent = humanErr(code);
		host.appendChild(span);
		setTimeout(function () { if (span.parentNode) { span.parentNode.removeChild(span); } }, 4000);
	}

	/* ---- assignees ------------------------------------------------------- */
	function loadAssignees(root) {
		var sel = root.querySelector('#zjob-assignee');
		if (!sel) { return; }
		post('zjob_assignees', {}).then(function (res) {
			if (!res || !res.success) { sel.innerHTML = '<option value="">(could not load)</option>'; return; }
			var list = (res.data && res.data.assignees) || [];
			sel.innerHTML = '<option value="">Select a specialist&hellip;</option>' +
				list.map(function (a) { return '<option value="' + parseInt(a.id, 10) + '">' + esc(a.name) + '</option>'; }).join('');
		}).catch(function () { sel.innerHTML = '<option value="">(could not load)</option>'; });
	}

	/* ---- create ---------------------------------------------------------- */
	function val(root, id) { var el = root.querySelector('#' + id); return el ? String(el.value).trim() : ''; }

	function setStatus(el, msg, kind) {
		if (!el) { return; }
		el.textContent = msg;
		el.className = 'zjob-inline-status is-visible' + (kind ? ' zjob-is-' + kind : '');
	}

	function doCreate(root) {
		var statusEl = root.querySelector('#zjob-create-status');
		var assignee = val(root, 'zjob-assignee');
		if (!assignee) { setStatus(statusEl, 'Pick a specialist to assign to.', 'warn'); return; }

		var payload = {
			assigned_user_id:  assignee,
			component:         val(root, 'zjob-component'),
			customer_name:     val(root, 'zjob-customer'),
			customer_business: val(root, 'zjob-business'),
			customer_address:  val(root, 'zjob-address'),
			customer_phone:    val(root, 'zjob-phone'),
			access_notes:      val(root, 'zjob-access'),
			brand:             val(root, 'zjob-brand'),
			qty:               val(root, 'zjob-qty'),
			source_ref:        val(root, 'zjob-source'),
			parent_lead_id:    val(root, 'zjob-parent'),
			notes:             val(root, 'zjob-notes')
		};

		var btn = root.querySelector('#zjob-create');
		if (btn) { btn.disabled = true; }
		setStatus(statusEl, 'Saving...', 'busy');

		post('zjob_create', payload).then(function (res) {
			if (btn) { btn.disabled = false; }
			if (res && res.success) {
				var d = res.data || {};
				setStatus(statusEl, d.message || 'Job created.', 'ok');
				resetForm(root);
				switchTab(root, 'list');
				resetFilterToPresent(root);
				loadList(root, 'present');
			} else {
				setStatus(statusEl, humanErr(res && res.data && res.data.message), 'err');
			}
		}).catch(function () {
			if (btn) { btn.disabled = false; }
			setStatus(statusEl, 'Network error - try again.', 'err');
		});
	}

	function resetFilterToPresent(root) {
		var p = root.querySelector('.zjob-chip[data-bucket="present"]');
		if (!p) { return; }
		root.querySelectorAll('.zjob-chip').forEach(function (c) { c.classList.remove('is-active'); });
		p.classList.add('is-active');
	}

	function resetForm(root) {
		['zjob-customer', 'zjob-business', 'zjob-address', 'zjob-phone', 'zjob-access', 'zjob-brand', 'zjob-source', 'zjob-parent', 'zjob-notes'].forEach(function (id) {
			var el = root.querySelector('#' + id); if (el) { el.value = ''; }
		});
		var q = root.querySelector('#zjob-qty'); if (q) { q.value = '1'; }
		var a = root.querySelector('#zjob-assignee'); if (a) { a.value = ''; }
		var c = root.querySelector('#zjob-component'); if (c) { c.selectedIndex = 0; }
	}

	/* ---- completion capture modal (Phase 7a) ----------------------------- */
	/* One modal at a time. State: the job, the uploaded photos (each with its own
	   fix), the best fix seen (for the job-level verified flag), and a busy latch. */
	var cState = null;

	function openComplete(root, id) {
		var jobId = parseInt(id, 10);
		if (!jobId) { return; }
		closeComplete();
		cState = { root: root, jobId: jobId, photos: [], bestGps: null, busy: false, ov: null };

		var minP = parseInt(cfg().minPhotos, 10) || 1;
		var ov = document.createElement('div');
		ov.className = 'tsjc-overlay';
		ov.innerHTML =
			'<div class="tsjc-dialog" role="dialog" aria-modal="true" aria-label="Mark my part complete">' +
				'<div class="tsjc-head">' +
					'<span class="tsjc-title">Finish photos</span>' +
					'<button class="tsjc-x" type="button" aria-label="Close">&times;</button>' +
				'</div>' +
				'<div class="tsjc-sub">Add at least ' + minP + ' photo' + (minP === 1 ? '' : 's') +
					' taken on site. Your location is recorded with each photo to confirm you were there.</div>' +
				'<div class="tsjc-body">' +
					'<div class="tsjc-grid" id="tsjc-grid"></div>' +
					'<button class="tsjc-add" type="button" id="tsjc-add"><i data-lucide="camera"></i><span>Add photo</span></button>' +
					'<input type="file" accept="image/*" capture="environment" class="tsjc-file" id="tsjc-file" hidden />' +
				'</div>' +
				'<div class="tsjc-foot">' +
					'<span class="tsjc-status" id="tsjc-status" aria-live="polite"></span>' +
					'<button class="tsjc-cancel" type="button">Cancel</button>' +
					'<button class="tsjc-go" type="button" id="tsjc-go" disabled><i data-lucide="check"></i><span>Complete job</span></button>' +
				'</div>' +
			'</div>';
		document.body.appendChild(ov);
		cState.ov = ov;

		ov.addEventListener('click', function (e) { if (e.target === ov) { closeComplete(); } });
		ov.querySelector('.tsjc-x').addEventListener('click', closeComplete);
		ov.querySelector('.tsjc-cancel').addEventListener('click', closeComplete);
		var fileInput = ov.querySelector('#tsjc-file');
		ov.querySelector('#tsjc-add').addEventListener('click', function () {
			if (cState && cState.busy) { return; }
			fileInput.click();
		});
		fileInput.addEventListener('change', function () {
			var f = fileInput.files && fileInput.files[0];
			fileInput.value = '';
			if (f) { addPhoto(f); }
		});
		ov.querySelector('#tsjc-go').addEventListener('click', submitComplete);
		drawIcons();
	}

	function closeComplete() {
		if (cState && cState.ov && cState.ov.parentNode) { cState.ov.parentNode.removeChild(cState.ov); }
		cState = null;
	}

	function cStatus(msg, kind) {
		if (!cState || !cState.ov) { return; }
		var el = cState.ov.querySelector('#tsjc-status');
		if (!el) { return; }
		el.textContent = msg || '';
		el.className = 'tsjc-status' + (kind ? ' is-' + kind : '');
	}

	function refreshGo() {
		if (!cState || !cState.ov) { return; }
		var minP = parseInt(cfg().minPhotos, 10) || 1;
		var go = cState.ov.querySelector('#tsjc-go');
		if (go) { go.disabled = cState.busy || cState.photos.length < minP; }
		var add = cState.ov.querySelector('#tsjc-add');
		if (add) { add.disabled = cState.busy; }
	}

	/* Ask the browser for a location fix. Resolves { lat, lng, accuracy } or null
	   (denied/unavailable/timeout) - a null fix still uploads, just marked unverified. */
	function getFix() {
		return new Promise(function (resolve) {
			if (!navigator.geolocation) { resolve(null); return; }
			var done = false;
			var t = setTimeout(function () { if (!done) { done = true; resolve(null); } }, 12000);
			function fin(v) { if (done) { return; } done = true; clearTimeout(t); resolve(v); }
			try {
				navigator.geolocation.getCurrentPosition(
					function (pos) { fin({ lat: pos.coords.latitude, lng: pos.coords.longitude, accuracy: pos.coords.accuracy }); },
					function () { fin(null); },
					{ enableHighAccuracy: true, timeout: 10000, maximumAge: 0 }
				);
			} catch (e) { fin(null); }
		});
	}

	function addBusyTile() {
		var grid = cState.ov.querySelector('#tsjc-grid');
		var tile = document.createElement('div');
		tile.className = 'tsjc-tile is-busy';
		tile.innerHTML = '<span class="tsjc-tile-spin"></span>';
		grid.appendChild(tile);
		return tile;
	}

	function addPhoto(file) {
		if (!cState) { return; }
		if (!/^image\//.test(file.type || '')) { cStatus(humanErr('not_image'), 'err'); return; }
		cState.busy = true;
		refreshGo();
		cStatus('Getting your location\u2026', 'busy');
		var tile = addBusyTile();

		getFix().then(function (fix) {
			cStatus('Uploading photo\u2026', 'busy');
			var fd = new FormData();
			fd.append('action', 'zjob_upload_photo');
			fd.append('nonce', cfg().nonce || '');
			fd.append('job_id', String(cState.jobId));
			fd.append('photo', file, file.name || ('job-' + cState.jobId + '.jpg'));
			if (fix) {
				fd.append('gps_lat', String(fix.lat));
				fd.append('gps_lng', String(fix.lng));
				fd.append('gps_accuracy', String(Math.round(fix.accuracy)));
			}
			try { fd.append('captured_at', new Date().toISOString()); } catch (e) { /* non-fatal */ }
			return fetch(cfg().ajaxurl, {
				method: 'POST', credentials: 'same-origin',
				headers: { 'X-Requested-With': 'XMLHttpRequest' },
				body: fd
			}).then(function (r) {
				return r.json().catch(function () { return { success: false, data: { message: 'upload_failed' } }; });
			}).then(function (res) {
				if (!cState) { return; }
				if (res && res.success && res.data) {
					var d = res.data;
					cState.photos.push({ media_id: d.media_id, thumb: d.thumb_url, url: d.url, verified: !!d.verified, gps: fix || null });
					if (fix && (cState.bestGps === null || fix.accuracy < cState.bestGps.accuracy)) { cState.bestGps = fix; }
					tile.className = 'tsjc-tile is-done ' + (d.verified ? 'is-verified' : 'is-unverified');
					tile.innerHTML = '<img src="' + esc(d.thumb_url || d.url || '') + '" alt="Finish photo" />' +
						'<button class="tsjc-tile-x" type="button" aria-label="Remove photo">&times;</button>' +
						'<span class="tsjc-tile-badge">' + (d.verified ? 'Verified' : 'Unverified') + '</span>';
					tile.querySelector('.tsjc-tile-x').addEventListener('click', function () { removePhoto(d.media_id, tile); });
					var n = cState.photos.length;
					cStatus(n + ' photo' + (n === 1 ? '' : 's') + ' added.' + (fix ? '' : ' Location unavailable - marked unverified.'), fix ? 'ok' : 'warn');
				} else {
					var code = (res && res.data && res.data.message) || 'upload_failed';
					if (tile.parentNode) { tile.parentNode.removeChild(tile); }
					cStatus(humanErr(code), 'err');
				}
			});
		}).catch(function () {
			if (tile.parentNode) { tile.parentNode.removeChild(tile); }
			cStatus(humanErr('upload_failed'), 'err');
		}).then(function () {
			if (cState) { cState.busy = false; refreshGo(); }
		});
	}

	function removePhoto(mid, tile) {
		if (!cState || cState.busy) { return; }
		mid = parseInt(mid, 10);
		cState.photos = cState.photos.filter(function (p) { return parseInt(p.media_id, 10) !== mid; });
		cState.bestGps = cState.photos.reduce(function (best, p) {
			if (!p.gps) { return best; }
			return (best === null || p.gps.accuracy < best.accuracy) ? p.gps : best;
		}, null);
		if (tile && tile.parentNode) { tile.parentNode.removeChild(tile); }
		refreshGo();
		cStatus('', '');
	}

	function submitComplete() {
		if (!cState || cState.busy) { return; }
		var minP = parseInt(cfg().minPhotos, 10) || 1;
		if (cState.photos.length < minP) { cStatus('Add at least ' + minP + ' photo' + (minP === 1 ? '' : 's') + '.', 'warn'); return; }
		cState.busy = true;
		refreshGo();
		cStatus('Completing\u2026', 'busy');

		var mids = cState.photos.map(function (p) { return parseInt(p.media_id, 10); });
		var g = cState.bestGps;
		var payload = { id: cState.jobId, media_ids: JSON.stringify(mids) };
		if (g) {
			payload.gps_lat = g.lat;
			payload.gps_lng = g.lng;
			payload.gps_accuracy = Math.round(g.accuracy);
		}
		post('zjob_worker_complete', payload).then(function (res) {
			if (!cState) { return; }
			if (res && res.success) {
				var rootEl = cState.root;
				closeComplete();
				if (rootEl) { loadList(rootEl, currentFilter(rootEl)); }
			} else {
				cState.busy = false;
				refreshGo();
				cStatus(humanErr(res && res.data && res.data.message), 'err');
			}
		}).catch(function () {
			if (cState) { cState.busy = false; refreshGo(); cStatus('Network error - try again.', 'err'); }
		});
	}

	/* ---- bootstrap (3 triggers, idempotent) ------------------------------ */
	if (document.getElementById('zjob-widget')) { initWidget(); }
	document.addEventListener('zdz_widgets_rendered', initWidget);
	document.addEventListener('DOMContentLoaded', initWidget);
})();
