/**
 * Zorderz Prep - field measurements of a long run ("sections"), and how a long run prints.
 *
 * A run is an area the estimate sold (the length of a wall, say): the plan prints it as "needs a field measurement".
 * The person at the site measures each panel here, in order along the run; the server keeps the panels
 * (ZPREP_Sections) and the next plan cuts them on one continuous pull with cumulative tape marks. The verdict on the
 * total against the quoted run uses the same thresholds as the server (ok / a few inches over / over).
 *
 * window.ZPREPSections = { tolerance( measured, quoted, grace ), runButton( d ), pageExtra( page ), open( ctx ) }
 *   ctx = { post( action, data ) -> Promise, job: { lead_id, source_job }, colors: [], run: deliverable|null, done() }
 *
 * @package Zorderz\Prep
 */
(function () {
	'use strict';

	function esc(s) {
		return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
		});
	}
	function fmt(n) {
		n = Number(n) || 0;
		return Math.abs(n - Math.round(n)) < 0.001 ? String(Math.round(n)) : String(Math.round(n * 100) / 100);
	}
	function feet(inches) {
		var ft = Math.floor(inches / 12);
		return ft + "' " + fmt(inches - ft * 12) + '"';
	}

	/* The same three verdicts as ZPREP_Sections::tolerance(). */
	function tolerance(measured, quoted, grace) {
		grace = Math.max(0, Number(grace) || 0);
		if (!(quoted > 0)) { return { level: 'none', over_in: 0, remaining_in: 0 }; }
		var over = Math.round((measured - quoted) * 100) / 100;
		var level = over <= 0 ? 'ok' : (over <= grace ? 'warn' : 'over');
		return { level: level, over_in: Math.max(0, over), remaining_in: Math.round(Math.max(0, quoted - measured) * 100) / 100 };
	}

	function runButton(d) {
		if (!d || !d.is_run) { return ''; }
		var dims = Object.keys(d.dimensions || {})[0] || '';
		return '<button type="button" class="zprep-w-btn zprep-w-btn-secondary zprep-sec-open" data-run-dims="' + esc(dims) + '" data-run-key="' + esc(d.run_key || '') + '">Measure the panels</button>';
	}

	/* A long run's page: where to rip, and the tape marks from the same end; or "needs a field measurement". */
	function pageExtra(page) {
		if (!page || !page.compressed) { return ''; }
		if (page.needs_sections) {
			return '<div class="zprep-sec-note">Needs a field measurement: about ' + esc(feet(page.run_length_in || 0)) + ' set aside, ' +
				esc(fmt(page.run_height_in || 0)) + '" high. Measure the panels, then plan again.</div>';
		}
		var cuts = (page.crosscuts || []).map(function (c, i) {
			return '<li>Mark ' + (i + 1) + ': ' + esc(feet(c.at_in)) + ' (' + esc(fmt(c.at_in)) + '") → panel ' + esc(fmt(c.piece)) + '"' + (c.label ? ' ' + esc(c.label) : '') + '</li>';
		}).join('');
		return '<div class="zprep-sec-note">One continuous pull of ' + esc(feet(page.sheet_length || 0)) + '. Rip to ' + esc(fmt(page.trim_to_in || 0)) +
			'" wide, then cut across at each mark, measured from the same end:</div><ol class="zprep-sec-cuts">' + cuts + '</ol>';
	}

	var S = { ctx: null, list: [], grace: 6, edit: null };

	function box() {
		var b = document.getElementById('zprep-sec-panel');
		if (!b) {
			b = document.createElement('div');
			b.id = 'zprep-sec-panel';
			b.className = 'zprep-sec-panel';
			b.setAttribute('role', 'dialog');
			b.setAttribute('aria-label', 'Field measurements');
			document.body.appendChild(b);
			b.addEventListener('click', onClick);
			b.addEventListener('input', onInput);
		}
		return b;
	}

	function blank(run) {
		var dims = String((run && run.dims) || '').match(/([\d.]+)"\s*[x×]\s*([\d.]+)"/);
		return { id: 0, name: '', color: (S.ctx.colors || [])[0] || '', run_length_in: dims ? dims[1] : '', run_height_in: dims ? dims[2] : '', panels: [{ h: dims ? dims[2] : '', w: '', qty: 1, label: '' }], notes: '' };
	}

	function readForm() {
		var b = box(), e = S.edit;
		var val = function (sel) { var x = b.querySelector(sel); return x ? x.value : ''; };
		e.name = val('[data-f="name"]');
		e.color = val('[data-f="color"]');
		e.run_length_in = val('[data-f="run_length_in"]');
		e.run_height_in = val('[data-f="run_height_in"]');
		e.notes = val('[data-f="notes"]');
		b.querySelectorAll('[data-p]').forEach(function (x) {
			var i = parseInt(x.getAttribute('data-i'), 10);
			if (e.panels[i]) { e.panels[i][x.getAttribute('data-p')] = x.value; }
		});
	}

	function totals() {
		var e = S.edit, m = 0;
		e.panels.forEach(function (p) { var w = parseFloat(p.w), q = parseInt(p.qty, 10) || 1; if (w > 0) { m += w * q; } });
		var t = tolerance(m, parseFloat(e.run_length_in) || 0, S.grace);
		var words = { ok: 'Covered' + (t.remaining_in > 0 ? ', ' + feet(t.remaining_in) + ' to spare' : '') + '.',
			warn: feet(t.over_in) + ' over the quoted run: within the usual crosscut loss. Go ahead, but it may come up short.',
			over: feet(t.over_in) + ' over the quoted run: more than was taken out. Check the stock before cutting.',
			none: 'No quoted run to measure against.' };
		return '<div class="zprep-sec-total is-' + t.level + '">Measured ' + esc(feet(m)) + '. ' + esc(words[t.level]) + '</div>';
	}

	function render() {
		var b = box(), e = S.edit;
		var html = '<div class="zprep-sec-head"><strong>Field measurements</strong><button type="button" class="zprep-w-btn zprep-w-btn-secondary" data-a="close">Close</button></div>';
		html += S.list.map(function (s) {
			return '<div class="zprep-sec-item"><span>' + esc(s.name || 'Section') + ' · ' + esc(s.panel_count) + ' panel(s) · ' + esc(feet(s.measured_in || 0)) + '</span>' +
				'<button type="button" class="zprep-w-btn zprep-w-btn-secondary" data-a="edit" data-id="' + s.id + '">Edit</button>' +
				'<button type="button" class="zprep-w-btn zprep-w-btn-secondary" data-a="del" data-id="' + s.id + '">Delete</button></div>';
		}).join('');
		if (e) {
			html += '<div class="zprep-sec-form"><input type="text" data-f="name" maxlength="128" placeholder="Name (e.g. north side)" value="' + esc(e.name) + '">' +
				'<label>Colour <select data-f="color">' + (S.ctx.colors || []).map(function (c) { return '<option value="' + esc(c) + '"' + (c === e.color ? ' selected' : '') + '>' + esc(c) + '</option>'; }).join('') + '</select></label>' +
				'<label>Quoted run (in) <input type="number" step="0.25" data-f="run_length_in" value="' + esc(e.run_length_in) + '"></label>' +
				'<label>Height (in) <input type="number" step="0.25" data-f="run_height_in" value="' + esc(e.run_height_in) + '"></label>' +
				'<div class="zprep-sec-panels"><div class="zprep-sec-ph"><span>Height</span><span>Length</span><span>Qty</span><span>Label</span></div>' +
				e.panels.map(function (p, i) {
					return '<div class="zprep-sec-row"><input type="number" step="0.25" data-p="h" data-i="' + i + '" value="' + esc(p.h) + '" aria-label="Height">' +
						'<input type="number" step="0.25" data-p="w" data-i="' + i + '" value="' + esc(p.w) + '" aria-label="Length">' +
						'<input type="number" min="1" data-p="qty" data-i="' + i + '" value="' + esc(p.qty || 1) + '" aria-label="Quantity">' +
						'<input type="text" maxlength="64" data-p="label" data-i="' + i + '" value="' + esc(p.label || '') + '" aria-label="Label">' +
						'<button type="button" data-a="rm" data-i="' + i + '" aria-label="Remove">&times;</button></div>';
				}).join('') + '<button type="button" class="zprep-w-btn zprep-w-btn-secondary" data-a="add">Add a panel</button></div>' +
				'<div class="zprep-sec-live">' + totals() + '</div>' +
				'<textarea data-f="notes" rows="2" maxlength="2000" placeholder="Notes">' + esc(e.notes) + '</textarea>' +
				'<div class="zprep-sec-actions"><button type="button" class="zprep-w-btn zprep-w-btn-primary" data-a="save">Save and plan again</button></div></div>';
		} else {
			html += '<button type="button" class="zprep-w-btn zprep-w-btn-secondary" data-a="new">Measure another run</button>';
		}
		html += '<div class="zprep-sec-msg" aria-live="polite"></div>';
		b.innerHTML = html;
		b.hidden = false;
	}

	function say(m) { var x = box().querySelector('.zprep-sec-msg'); if (x) { x.textContent = m; } }

	function onInput(ev) {
		if (!S.edit || !ev.target.closest('[data-p],[data-f]')) { return; }
		readForm();
		var live = box().querySelector('.zprep-sec-live');
		if (live) { live.innerHTML = totals(); }
	}

	function onClick(ev) {
		var a = ev.target.closest('[data-a]');
		if (!a) { return; }
		var act = a.getAttribute('data-a');
		if (act === 'close') { box().hidden = true; return; }
		if (S.edit) { readForm(); }
		if (act === 'new') { S.edit = blank(null); render(); return; }
		if (act === 'add') { S.edit.panels.push({ h: (S.edit.panels[S.edit.panels.length - 1] || {}).h || '', w: '', qty: 1, label: '' }); render(); return; }
		if (act === 'rm') { S.edit.panels.splice(parseInt(a.getAttribute('data-i'), 10), 1); render(); return; }
		if (act === 'edit') {
			var s = S.list.filter(function (x) { return String(x.id) === a.getAttribute('data-id'); })[0];
			if (s) { S.edit = JSON.parse(JSON.stringify({ id: s.id, name: s.name, color: s.color, run_length_in: s.run_length_in || '', run_height_in: s.run_height_in || '', panels: s.panels.length ? s.panels : [{ h: '', w: '', qty: 1 }], notes: s.notes || '' })); render(); }
			return;
		}
		var job = S.ctx.job || {};
		if (act === 'del') {
			a.disabled = true;
			S.ctx.post('zprep_section_delete', { lead_id: job.lead_id || 0, source_job: job.source_job || '', id: a.getAttribute('data-id') }).then(function () { load(); });
			return;
		}
		if (act === 'save') {
			var e = S.edit;
			a.disabled = true;
			S.ctx.post('zprep_section_save', {
				lead_id: job.lead_id || 0, source_job: job.source_job || '', id: e.id || 0, name: e.name, color: e.color,
				run_length_in: e.run_length_in, run_height_in: e.run_height_in, notes: e.notes,
				panels: JSON.stringify(e.panels.filter(function (p) { return parseFloat(p.w) > 0 && parseFloat(p.h) > 0; }))
			}).then(function (res) {
				a.disabled = false;
				if (!res || !res.success) { say((res && res.data && res.data.message) || 'The section could not be saved.'); return; }
				S.edit = null;
				box().hidden = true;
				if (typeof S.ctx.done === 'function') { S.ctx.done(); }
			});
		}
	}

	function load() {
		var job = S.ctx.job || {};
		return S.ctx.post('zprep_section_list', { lead_id: job.lead_id || 0, source_job: job.source_job || '' }).then(function (res) {
			S.list = (res && res.success && res.data && res.data.sections) || [];
			S.grace = (res && res.data && res.data.grace_in != null) ? res.data.grace_in : 6;
			render();
		});
	}

	function open(ctx) {
		S.ctx = ctx;
		S.edit = ctx.run ? blank(ctx.run) : null;
		render();
		load();
	}

	window.ZPREPSections = { tolerance: tolerance, runButton: runButton, pageExtra: pageExtra, open: open };
})();
