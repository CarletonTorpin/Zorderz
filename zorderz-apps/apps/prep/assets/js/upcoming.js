/**
 * Zorderz Prep - Upcoming production: the sold work coming up, each job's prep sheets and the day it must be ready by,
 * and a printable prep sheet (the estimate number, the town, the install day, what was sold, the sheet's steps).
 *
 * The server works it all out (ZPREP_Upcoming); this draws it when the section is opened. Printing a prep sheet
 * records nothing.
 *
 * @package Zorderz\Prep
 */
(function () {
	'use strict';

	function cfg() { return window.zprepWidgetData || {}; }
	function esc(s) {
		return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
		});
	}
	function day(ymd) {
		var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(ymd || ''));
		if (!m) { return ''; }
		var d = new Date(+m[1], +m[2] - 1, +m[3]);
		try { return d.toLocaleDateString(undefined, { weekday: 'short', month: 'short', day: 'numeric' }); } catch (e) { return ymd; }
	}
	function post(action, data) {
		var body = new URLSearchParams();
		body.append('action', action);
		body.append('nonce', cfg().nonce || '');
		Object.keys(data || {}).forEach(function (k) { body.append(k, data[k]); });
		return fetch(cfg().ajaxurl || (window.ajaxurl || '/wp-admin/admin-ajax.php'), { method: 'POST', credentials: 'same-origin', body: body })
			.then(function (r) { return r.json(); });
	}

	var S = { data: null };

	function sheetLabel(k) {
		var s = ((S.data && S.data.sheets) || []).filter(function (x) { return x.key === k; })[0];
		return s ? s.label : k;
	}

	function render(box) {
		var d = S.data;
		var days = (d.days || []).filter(function (r) {
			return Object.keys(r.sheets).some(function (k) { return r.sheets[k].over; });
		}).map(function (r) {
			return '<div class="zprep-up-over">' + esc(day(r.day)) + ': ' + Object.keys(r.sheets).filter(function (k) { return r.sheets[k].over; }).map(function (k) {
				return esc(sheetLabel(k)) + ' ' + r.sheets[k].count + ' of ' + r.sheets[k].capacity;
			}).join(', ') + '</div>';
		}).join('');
		var items = (d.items || []).map(function (it, i) {
			var chips = (it.sheets || []).map(function (k) {
				return '<button type="button" class="zprep-up-sheet" data-up="' + i + '" data-sheet="' + esc(k) + '">' + esc(sheetLabel(k)) + '</button>';
			}).join('');
			return '<div class="zprep-up-item' + (it.late ? ' is-late' : '') + '"><div class="zprep-up-top"><strong>' + esc(it.number ? '#' + it.number : 'Project') + '</strong>' +
				'<span>' + esc([it.city, it.installer].filter(Boolean).join(' · ')) + '</span></div>' +
				'<div class="zprep-up-when">' + (it.install_day ? 'Install ' + esc(day(it.install_day)) : 'Not booked yet') +
				(it.prep_by ? ' · ready by ' + esc(day(it.prep_by)) + (it.late ? ' (late)' : '') : '') + '</div>' +
				(chips ? '<div class="zprep-up-chips">' + chips + '</div>' : '<div class="zprep-up-when">No prep sheet for this kind of work.</div>') + '</div>';
		}).join('');
		box.innerHTML = days + (items || '<div class="zprep-w-hint">Nothing sold is coming up.</div>');
	}

	function printSheet(it, key) {
		var s = ((S.data && S.data.sheets) || []).filter(function (x) { return x.key === key; })[0] || { label: key, steps: [] };
		var w = window.open('', '_blank');
		if (!w) { return; }
		var html = '<!DOCTYPE html><html><head><meta charset="utf-8"><title>' + esc(s.label) + '</title><style>' +
			'body{font:14px/1.4 -apple-system,Segoe UI,Roboto,sans-serif;margin:24px;color:#111}h1{font-size:20px;margin:0 0 4px}' +
			'.m{color:#444;margin-bottom:12px}table{border-collapse:collapse;width:100%}td,th{border:1px solid #999;padding:6px;text-align:left;vertical-align:top}' +
			'ol{padding-left:20px}.sig{margin-top:24px;display:flex;gap:32px}</style></head><body>' +
			'<h1>' + esc((cfg().letterhead ? cfg().letterhead + ' — ' : '') + s.label) + '</h1>' +
			'<div class="m">' + esc(it.number ? 'Estimate #' + it.number : '') + (it.city ? ' · ' + esc(it.city) : '') +
			(it.install_day ? ' · Install ' + esc(day(it.install_day)) : '') + (it.prep_by ? ' · Ready by ' + esc(day(it.prep_by)) : '') + '</div>' +
			'<h2>What was sold</h2><table><thead><tr><th>Qty</th><th>Item</th><th>Details</th></tr></thead><tbody>' +
			(it.lines || []).map(function (l) { return '<tr><td>' + esc(l.qty) + '</td><td>' + esc(l.name) + '</td><td>' + esc(l.description) + '</td></tr>'; }).join('') +
			'</tbody></table>' + ((s.steps || []).length ? '<h2>Steps</h2><ol>' + s.steps.map(function (x) { return '<li>' + esc(x) + '</li>'; }).join('') + '</ol>' : '') +
			'<div class="sig"><div>Prepared by: ________________</div><div>Date: __________</div></div></body></html>';
		w.document.open();
		w.document.write(html);
		w.document.close();
		w.focus();
		try { w.print(); } catch (e) { /* the person prints from the window */ }
	}

	function load(box) {
		box.innerHTML = '<div class="zprep-w-hint">Loading…</div>';
		post('zprep_upcoming', {}).then(function (res) {
			if (!res || !res.success) {
				box.innerHTML = '<div class="zprep-w-error" style="display:block">' + esc((res && res.data && res.data.message) || 'Upcoming production could not be loaded.') + '</div>';
				return;
			}
			S.data = res.data;
			render(box);
		}).catch(function () { box.innerHTML = '<div class="zprep-w-error" style="display:block">Upcoming production could not be loaded.</div>'; });
	}

	function init() {
		var det = document.getElementById('zprep-w-upcoming');
		if (!det || det.dataset.zupInited) { return; }
		det.dataset.zupInited = '1';
		var box = document.getElementById('zprep-w-upcoming-body');
		det.addEventListener('toggle', function () { if (det.open && !S.data) { load(box); } });
		box.addEventListener('click', function (e) {
			var b = e.target.closest('[data-up]');
			if (!b || !S.data) { return; }
			printSheet(S.data.items[parseInt(b.getAttribute('data-up'), 10)], b.getAttribute('data-sheet'));
		});
	}

	document.addEventListener('zdz_widgets_rendered', init);
	document.addEventListener('DOMContentLoaded', init);
	init();
})();
