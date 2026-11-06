/**
 * Zorderz Jobs - "My next": the steps that are this person's to do now, and what they finished lately.
 *
 * The server works every step out (ZJOB_My_Next) and sends only this person's; this file draws them. Start opens the
 * app the step happens in (a Project in Projects, a job in Prep, a route in Doorhangers). Nothing here decides who
 * owns a step.
 *
 * window.ZJOBMyNext = { load( listEl, post ), render( listEl, data ) }
 *
 * @package Zorderz\Jobs
 */
(function () {
	'use strict';

	function esc(s) {
		return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
		});
	}

	/* A stored UTC time ("2026-01-15 18:05:00") as the viewer's own day and clock. */
	function when(utc) {
		var d = new Date(String(utc || '').replace(' ', 'T') + 'Z');
		if (isNaN(d.getTime())) { return ''; }
		try {
			return d.toLocaleString(undefined, { weekday: 'short', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
		} catch (e) {
			return d.toString();
		}
	}

	var ICON = { install: 'hammer', cut: 'scissors', order: 'package', schedule: 'calendar-plus', walk: 'footprints', invoice: 'file-text', payment: 'wallet' };

	function item(it, past) {
		var p = it.project || {};
		var where = [p.customer, p.city].filter(Boolean).join(' · ');
		var meta = past ? when(it.at) : (it.when ? when(it.when) : '');
		var start = !past && it.start && it.start.app
			? '<button type="button" class="zjob-mini" data-next-start="' + esc(it.key) + '"><span>' + esc(it.start.label || 'Open') + '</span></button>' : '';
		return '<div class="zjob-next' + (it.held ? ' is-held' : '') + (past ? ' is-past' : '') + '">' +
			'<div class="zjob-next-top"><i data-lucide="' + esc(ICON[it.step] || 'circle') + '"></i><strong>' + esc(it.label) + '</strong>' +
			(meta ? '<span class="zjob-next-when">' + esc(meta) + '</span>' : '') + '</div>' +
			(where ? '<div class="zjob-next-where">' + esc(where) + (p.code ? ' <span class="zjob-next-code">' + esc(p.code) + '</span>' : '') + '</div>' : '') +
			(!past && it.why ? '<div class="zjob-next-why">' + esc(it.why) + (it.cut_by ? ' ' + esc('Prepared by ' + it.cut_by + '.') : '') + '</div>' : '') +
			(start ? '<div class="zjob-next-act">' + start + '</div>' : '') + '</div>';
	}

	function render(listEl, data) {
		var present = (data && data.present) || [];
		var past = (data && data.past) || [];
		var html = '';
		if (!present.length) {
			html += '<div class="zjob-empty">Nothing is waiting on you right now.</div>';
		} else {
			html += present.map(function (it) { return item(it, false); }).join('');
		}
		if (past.length) {
			html += '<h4 class="zjob-next-h">Done lately</h4>' + past.map(function (it) { return item(it, true); }).join('');
		}
		listEl.innerHTML = html;
		listEl._next = present;
		if (window.lucide && typeof window.lucide.createIcons === 'function') {
			try { window.lucide.createIcons(); } catch (e) { /* non-fatal */ }
		}
	}

	function start(listEl, key) {
		var it = (listEl._next || []).filter(function (x) { return x.key === key; })[0];
		if (!it || !it.start || typeof window.openApp !== 'function') { return; }
		var opts = it.start.options || {};
		if (it.start.app === 'projects' && opts.project_id) {
			window.zjobOpenProject = { id: String(opts.project_id), at: it.step === 'order' ? 'orders' : '' };
		}
		window.openApp(it.start.app, opts);
	}

	function load(listEl, post) {
		listEl.innerHTML = '<div class="zjob-empty zjob-loading">Loading&hellip;</div>';
		if (!listEl._nextWired) {
			listEl._nextWired = true;
			listEl.addEventListener('click', function (e) {
				var b = e.target.closest('[data-next-start]');
				if (b) { e.stopPropagation(); start(listEl, b.getAttribute('data-next-start')); }
			});
		}
		return post('zjob_my_next', {}).then(function (res) {
			if (!res || !res.success) {
				listEl.innerHTML = '<div class="zjob-empty">' + esc((res && res.data && res.data.message) || 'Your next steps could not be loaded.') + '</div>';
				return;
			}
			render(listEl, res.data);
		}).catch(function () {
			listEl.innerHTML = '<div class="zjob-empty">Your next steps could not be loaded.</div>';
		});
	}

	window.ZJOBMyNext = { load: load, render: render };
})();
